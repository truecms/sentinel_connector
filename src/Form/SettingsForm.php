<?php

namespace Drupal\sentinel_connector\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ToConfig;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\sentinel_connector\ApiBaseUrlResolver;
use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\sentinel_connector\ConnectionChecker;
use Drupal\sentinel_connector\ConnectionCheckResult;
use Drupal\sentinel_connector\PayloadBuilder;
use Drupal\sentinel_connector\PushLimitFormatter;
use Drupal\sentinel_connector\SendingSwitch;
use Drupal\sentinel_connector\SiteUuid;
use Drupal\sentinel_connector\SiteUuidResolver;
use Drupal\sentinel_connector\StatusReportCollector;
use Drupal\sentinel_connector\SyncResult;
use Drupal\sentinel_connector\SyncService;
use Drupal\sentinel_connector\UpgradeStatusCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the Sentinel Connector module.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The configuration this form edits.
   */
  public const CONFIG_NAME = 'sentinel_connector.settings';

  /**
   * Constructs the settings form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager (required by ConfigFormBase on Drupal 11).
   * @param \Drupal\sentinel_connector\ApiKeyResolver $apiKeyResolver
   *   The API key resolver.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store, for sync status and the deprecated saved API key.
   * @param \Drupal\sentinel_connector\SyncService $syncService
   *   The sync service, for the stored push-limit outcome.
   * @param \Drupal\sentinel_connector\PushLimitFormatter $pushLimitFormatter
   *   Builds the text for a refused push.
   * @param \Drupal\sentinel_connector\UpgradeStatusCollector $upgradeStatus
   *   Reads the Upgrade Status result for the Integrations section.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, to tell which optional modules are enabled.
   * @param \Drupal\sentinel_connector\ApiBaseUrlResolver $urlResolver
   *   Resolves the API base URL, shown read-only.
   * @param \Drupal\sentinel_connector\SiteUuidResolver $uuidResolver
   *   Resolves the Site UUID, which settings.php can override.
   * @param \Drupal\sentinel_connector\SendingSwitch $sendingSwitch
   *   The off switch.
   * @param \Drupal\sentinel_connector\ConnectionChecker $connectionChecker
   *   Runs "Test connection".
   * @param \Drupal\sentinel_connector\StatusReportCollector $statusReport
   *   Tells whether the status report is reported, and whether settings.php
   *   sets that.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected ApiKeyResolver $apiKeyResolver,
    protected StateInterface $state,
    protected SyncService $syncService,
    protected PushLimitFormatter $pushLimitFormatter,
    protected UpgradeStatusCollector $upgradeStatus,
    protected ModuleHandlerInterface $moduleHandler,
    protected ApiBaseUrlResolver $urlResolver,
    protected SiteUuidResolver $uuidResolver,
    protected SendingSwitch $sendingSwitch,
    protected ConnectionChecker $connectionChecker,
    protected StatusReportCollector $statusReport,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('Drupal\sentinel_connector\ApiKeyResolver'),
      $container->get('state'),
      $container->get('Drupal\sentinel_connector\SyncService'),
      $container->get('Drupal\sentinel_connector\PushLimitFormatter'),
      $container->get('Drupal\sentinel_connector\UpgradeStatusCollector'),
      $container->get('module_handler'),
      $container->get('Drupal\sentinel_connector\ApiBaseUrlResolver'),
      $container->get('Drupal\sentinel_connector\SiteUuidResolver'),
      $container->get('Drupal\sentinel_connector\SendingSwitch'),
      $container->get('Drupal\sentinel_connector\ConnectionChecker'),
      $container->get('Drupal\sentinel_connector\StatusReportCollector'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The editable configuration names.
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sentinel_connector_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The populated form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $help = [':help' => ConnectionChecker::ENVIRONMENTS_HELP_URL];

    // Shown first: nothing below is sent while either holds.
    if ($this->sendingSwitch->isDisabled()) {
      $form['sending_disabled'] = [
        '#type' => 'item',
        '#markup' => $this->sendingSwitch->getSource() === 'settings.php'
          ? $this->t('Sending is turned off for this environment by <code>@settings</code> in settings.php. Cron, Drush and Sync now send nothing here.', ['@settings' => "\$settings['" . SendingSwitch::SETTINGS_KEY . "']"])
          : $this->t('Sending is turned off for this environment by the <code>@env</code> environment variable. Cron, Drush and Sync now send nothing here.', ['@env' => SendingSwitch::ENV_VAR]),
        '#wrapper_attributes' => ['class' => ['messages', 'messages--status'], 'role' => 'status'],
      ];
    }
    elseif ($this->urlResolver->getUrl() === NULL) {
      $form['api_base_url_error'] = [
        '#type' => 'item',
        '#markup' => $this->connectionChecker->invalidUrlMessage(),
        '#wrapper_attributes' => ['class' => ['messages', 'messages--error'], 'role' => 'alert'],
      ];
    }

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic sync on cron'),
      '#description' => $this->t('Cron sends at most one push an hour. How often Sentinel accepts a push depends on the plan.'),
      '#config_target' => self::CONFIG_NAME . ':enabled',
    ];

    // Read-only: only settings.php or the server environment can send the
    // API key and inventory to another host.
    $urlSource = match ($this->urlResolver->getSource()) {
      ApiBaseUrlResolver::SOURCE_SETTINGS => $this->t('from settings.php'),
      ApiBaseUrlResolver::SOURCE_ENVIRONMENT => $this->t('from the @env environment variable', ['@env' => ApiBaseUrlResolver::ENV_VAR]),
      default => $this->t('Default'),
    };
    $url = $this->urlResolver->getUrl();
    $form['api_base_url'] = [
      '#type' => 'item',
      '#title' => $this->t('Sentinel API'),
      '#markup' => $url !== NULL
        ? $this->t('<code>@url</code> · @source', ['@url' => $url, '@source' => $urlSource])
        : $this->t('<strong>Not valid</strong> · @source', ['@source' => $urlSource]),
      '#description' => $this->t('Change it only for a non-production Sentinel, in settings.php or the @env environment variable. <a href=":help">How</a>', $help + ['@env' => ApiBaseUrlResolver::ENV_VAR]),
    ];

    if ($this->uuidResolver->isOverridden()) {
      $form['site_uuid'] = [
        '#type' => 'item',
        '#title' => $this->t('Site UUID'),
        '#markup' => $this->t('<code>@uuid</code> · @source', [
          '@uuid' => $this->uuidResolver->getUuid() ?? $this->uuidResolver->getRawValue(),
          '@source' => $this->uuidResolver->getSource() === SiteUuidResolver::SOURCE_SETTINGS
            ? $this->t('from settings.php')
            : $this->t('from the @env environment variable', ['@env' => SiteUuidResolver::ENV_VAR]),
        ]),
        '#description' => $this->uuidResolver->getUuid() === NULL
          ? $this->t('This value is not a valid UUID. Nothing is sent until it is fixed.')
          : $this->t('Set for this environment. The value saved on this form is not used.'),
      ];
    }
    else {
      $form['site_uuid'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Site UUID'),
        '#description' => $this->t("Copy it from the site's Connection tab in Sentinel. Can also be set in settings.php or @env for each environment.", ['@env' => SiteUuidResolver::ENV_VAR]),
        '#config_target' => new ConfigTarget(self::CONFIG_NAME, 'site_uuid', toConfig: fn (mixed $value): string => (string) $value),
        '#required' => TRUE,
      ];
    }
    $form['report_scope'] = [
      '#type' => 'radios',
      '#title' => $this->t('Report scope'),
      '#options' => [
        'all' => $this->t('All: Drupal core, contrib and custom modules'),
        'core_contrib' => $this->t('Drupal core and contrib (custom modules are not sent)'),
      ],
      '#config_target' => new ConfigTarget(self::CONFIG_NAME, 'report_scope', PayloadBuilder::normaliseScope(...), PayloadBuilder::normaliseScope(...)),
    ];
    $form['report_config_drift'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Report configuration drift'),
      '#description' => $this->t('Sends the names of configuration objects that differ from the sync directory, and the type of change. Configuration values are never sent.'),
      '#config_target' => $this->switchTarget('report_config_drift'),
    ];
    $form['report_platform'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Report platform versions'),
      '#description' => $this->t('Sends the web server, database and operating system versions, and their system package versions, so Sentinel can warn you about known vulnerabilities in your server software. The PHP version is always sent, because Drupal core support depends on it.'),
      '#config_target' => $this->switchTarget('report_platform'),
    ];
    $statusOverridden = $this->statusReport->isReportingOverridden();
    $form['report_status_report'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Report the Drupal status report (errors and warnings)'),
      '#description' => $statusOverridden
        ? $this->t('Set in settings.php by $settings[@key], which takes precedence over this checkbox.', ['@key' => "'" . StatusReportCollector::SETTINGS_KEY . "'"])
        : $this->t('Sends the title, value and description of each error and warning on Reports, Status report, as plain text. OK and information items are never sent. Site paths are replaced with [site root]. Preview what is sent: <code>@command</code>', ['@command' => 'drush sentinel_connector:status-report']),
      '#config_target' => $this->switchTarget('report_status_report', $statusOverridden),
      '#disabled' => $statusOverridden,
    ];
    if ($statusOverridden) {
      // Shows the value settings.php sets; the stored value is kept.
      $form['report_status_report']['#default_value'] = $this->statusReport->isReportingEnabled();
    }
    $form['integrations'] = $this->integrations();
    // API key status: the source is shown, the key never is.
    $source = $this->apiKeyResolver->getSource();
    $keyModule = $this->apiKeyResolver->keyModuleEnabled();
    $status = match ($source) {
      'settings.php' => $this->t('Configured in settings.php. This key is used ahead of the selected key, the environment variable and any key saved in State.'),
      'key' => $this->t('Configured with the key "@label" from the Key module.', ['@label' => (string) $this->apiKeyResolver->getKeyLabel()]),
      'environment' => $this->t('Configured in the @env environment variable. This key is used ahead of any key saved in State.', ['@env' => ApiKeyResolver::ENV_VAR]),
      'state' => $this->t('Configured. The key is saved in State, which is deprecated: the value is stored in plain text in the database and its backups. Select a key from the Key module instead.'),
      default => $this->t('Not configured. Select a key below, or set $settings[@settings] in settings.php or the @env environment variable.', [
        '@settings' => "'" . ApiKeyResolver::SETTINGS_KEY . "'",
        '@env' => ApiKeyResolver::ENV_VAR,
      ]),
    };
    // A selected key that cannot be used is skipped; say why.
    $problem = $source === 'settings.php' ? NULL : $this->apiKeyResolver->getKeyProblem();
    $form['api_key_status'] = [
      '#type' => 'item',
      '#title' => $this->t('API key status'),
      '#markup' => $problem === NULL ? $status : $this->t('@status The selected key is not used: @problem', [
        '@status' => $status,
        '@problem' => match ($problem) {
          'missing' => $this->t('it does not exist.'),
          'config_provider' => $this->t('it keeps its value in configuration, which is exported. Use a provider that reads a file, an environment variable or a vault.'),
          'empty' => $this->t('it has no value.'),
          default => $this->t('the Key module is not enabled.'),
        },
      ]),
    ];

    if ($keyModule) {
      $links = [
        ':add' => Url::fromRoute('entity.key.add_form')->toString(),
        ':list' => Url::fromRoute('entity.key.collection')->toString(),
      ];
      $form['api_key_id'] = [
        '#type' => 'key_select',
        '#title' => $this->t('API key'),
        '#empty_option' => $this->t('- None -'),
        '#config_target' => new ConfigTarget(self::CONFIG_NAME, 'api_key_id', fn (mixed $value): string => trim((string) $value), fn (mixed $value): string => (string) $value),
        '#description' => $source === 'settings.php'
          ? $this->t('Sync requests use the key from settings.php, not the selected key. <a href=":add">Add a key</a> or <a href=":list">manage keys</a>.', $links)
          : $this->t('The key from the site dashboard in Sentinel, stored with the Key module. Use a provider that reads a file, an environment variable or a vault; keys that keep the value in configuration are refused. <a href=":add">Add a key</a> or <a href=":list">manage keys</a>.', $links),
      ];
    }
    else {
      // Never given a default value, so a saved key is not sent to the
      // browser. A key saved here is not used while settings.php or the
      // environment supplies one, so the field must not claim to replace the
      // active key.
      $external = $source === 'settings.php' || $source === 'environment';
      $form['api_key_state'] = [
        '#type' => 'password',
        '#title' => match (TRUE) {
          $external => $this->t('API key saved on this form (not in use)'),
          $source === 'state' => $this->t('Replace API key'),
          default => $this->t('API key'),
        },
        '#description' => $external
          ? $this->t('Sync requests use the key from @source, not a key saved here. To change or rotate the active key, change it in @source. A key entered here is saved in State and used only if that key is removed. Leave blank to keep the saved value.', [
            '@source' => $source === 'settings.php' ? 'settings.php' : ApiKeyResolver::ENV_VAR,
          ])
          : $this->t('The key from the site dashboard in Sentinel. Leave blank to keep the current key. Deprecated: the key is saved in State, in plain text in the database. Enable the Key module to store it in a file, an environment variable or a vault; an existing key is moved into a key when the module is enabled.'),
        '#attributes' => ['autocomplete' => 'off'],
      ];
    }

    // Queue acceptance and failed attempts are not successful synchronisations.
    $lastSuccess = (int) $this->state->get('sentinel_connector.last_sync_time', 0);
    $lastAttempt = (int) $this->state->get('sentinel_connector.last_attempt_time', 0);
    $form['last_sync_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Sync status'),
      '#markup' => $this->t('Last successful sync: @success. Last attempt: @attempt. Result: @result. @message', [
        '@success' => $lastSuccess ? date('c', $lastSuccess) : $this->t('Never'),
        '@attempt' => $lastAttempt ? date('c', $lastAttempt) : $this->t('Never'),
        '@result' => $this->state->get('sentinel_connector.last_result', 'unknown'),
        '@message' => $this->state->get('sentinel_connector.last_message', ''),
      ]),
    ];

    // A stored push limit: no push is sent before this time.
    $pushLimit = $this->syncService->deferredResult();
    if ($pushLimit !== NULL && $pushLimit->nextAllowedAt !== NULL) {
      $form['push_limit_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Push limit'),
        '#markup' => $this->t('@message Next push accepted after @time. Cron and "Sync now" send nothing before then.', [
          '@message' => $pushLimit->message,
          '@time' => $this->pushLimitFormatter->formatTime($pushLimit->nextAllowedAt),
        ]),
      ];
    }

    // The last push was refused over billing: say why in plain words.
    if ($this->state->get('sentinel_connector.last_result') === SyncResult::SUBSCRIPTION_INACTIVE) {
      $reason = $this->state->get(SyncService::STATE_SUBSCRIPTION_REASON);
      $reason = is_string($reason) && $reason !== '' ? $reason : NULL;
      $message = $this->state->get('sentinel_connector.last_message', '');
      $hold = $this->syncService->getSubscriptionHoldUntil();
      $arguments = [
        '@reason' => $this->pushLimitFormatter->subscriptionReasonLabel($reason),
        '@message' => $this->pushLimitFormatter->subscriptionError($reason, is_string($message) ? $message : ''),
      ];
      $form['subscription_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Subscription'),
        '#markup' => $hold === NULL
          ? $this->t('Subscription @reason. @message', $arguments)
          : $this->t('Subscription @reason. @message Cron will try again after @time. "Sync now" is not held back.', $arguments + [
            '@time' => $this->pushLimitFormatter->formatTime($hold),
          ]),
      ];
    }

    $form['environments_help'] = [
      '#type' => 'item',
      '#markup' => $this->t('<a href=":help">Using several environments (production, non-production, local, CI)</a>', $help),
      '#weight' => 100,
    ];

    $form = parent::buildForm($form, $form_state);

    // Both are hidden while sending is turned off: nothing would be sent.
    $mayTrigger = !$this->sendingSwitch->isDisabled() && $this->currentUser()->hasPermission('trigger sentinel sync');
    // Tests the saved settings, not the values on the form.
    $form['actions']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test connection'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [],
      '#weight' => 5,
      '#access' => $mayTrigger,
    ];
    // Offered only once a save has stored everything a push needs, and placed
    // to the right of "Save configuration".
    $form['actions']['sync_now'] = [
      '#type' => 'link',
      '#title' => $this->t('Sync now'),
      '#url' => Url::fromRoute('sentinel_connector.sync_now'),
      '#attributes' => ['class' => ['button', 'button--primary']],
      '#weight' => 10,
      '#access' => $mayTrigger && $this->syncService->isConfigured(),
    ];

    return $form;
  }

  /**
   * The config target of an on/off setting that is on when not yet saved.
   *
   * @param string $key
   *   The setting in sentinel_connector.settings.
   * @param bool $overridden
   *   TRUE when settings.php sets it: the stored value is then kept.
   *
   * @return \Drupal\Core\Form\ConfigTarget
   *   The config target.
   */
  protected function switchTarget(string $key, bool $overridden = FALSE): ConfigTarget {
    return new ConfigTarget(
      self::CONFIG_NAME,
      $key,
      fn (mixed $value): bool => (bool) ($value ?? TRUE),
      fn (mixed $value): bool|ToConfig => $overridden ? ToConfig::NoOp : (bool) $value,
    );
  }

  /**
   * The Integrations section: one row per optional module.
   *
   * @return array<string, mixed>
   *   The section's form elements.
   */
  protected function integrations(): array {
    $detected = $this->upgradeStatus->isDetected();
    $section = [
      '#type' => 'details',
      '#title' => $this->t('Integrations'),
      '#description' => $this->t('Optional modules the connector reports from when they are enabled on this site.'),
      '#open' => $detected,
    ];

    $section['upgrade_status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Upgrade Status'),
    ];
    if ($detected) {
      $block = $this->upgradeStatus->isReportingEnabled() ? $this->upgradeStatus->collect(FALSE) : NULL;
      $lastScan = is_string($block['newest_scan_at'] ?? NULL) ? $block['newest_scan_at'] : NULL;
      $section['upgrade_status']['status'] = [
        '#type' => 'item',
        '#title' => $this->t('Status'),
        '#markup' => $this->t('Detected. Version: @version. Last scan: @scan.', [
          '@version' => $this->upgradeStatus->moduleVersion() ?? $this->t('unknown'),
          '@scan' => $lastScan ?? $this->t('none'),
        ]),
      ];
    }
    else {
      $section['upgrade_status']['status'] = [
        '#type' => 'item',
        '#title' => $this->t('Status'),
        '#markup' => $this->t('Not detected. When Upgrade Status is enabled on the site the connector reports from, Sentinel receives its result and shows it. To add it: <code>@require</code> then <code>@enable</code>.', [
          '@require' => 'composer require --dev drupal/upgrade_status',
          '@enable' => 'drush pm:install upgrade_status',
        ]),
      ];
    }
    $overridden = $this->upgradeStatus->isReportingOverridden();
    $section['upgrade_status']['report_upgrade_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Report Upgrade Status readiness to Sentinel'),
      '#description' => $overridden
        ? $this->t('Set in settings.php by $settings[@key], which takes precedence over this checkbox.', ['@key' => "'" . UpgradeStatusCollector::SETTINGS_KEY . "'"])
        : $this->t('Sends how many projects are at each Upgrade Status next step and when they were scanned. Project names, paths and messages are never sent.'),
      '#config_target' => $this->switchTarget('report_upgrade_status', $overridden),
      '#disabled' => $overridden,
    ];
    if ($overridden) {
      // Shows the value settings.php sets; the stored value is kept.
      $section['upgrade_status']['report_upgrade_status']['#default_value'] = $this->upgradeStatus->isReportingEnabled();
    }
    $section['upgrade_status']['scan'] = [
      '#type' => 'item',
      '#title' => $this->t('Scan command'),
      '#markup' => $this->t('<code>@command</code> scans custom projects with Upgrade Status, then syncs. A scan uses CPU for several minutes: run it in a quiet window. It never runs on cron.', [
        '@command' => 'drush sentinel_connector:upgrade-status',
      ]),
    ];

    // Its status fields come with the configuration pull request reporting.
    $configPr = $this->moduleHandler->moduleExists('sentinel_connector_config_pr');
    $section['config_pr'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Config PR'),
    ];
    $section['config_pr']['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Status'),
      '#markup' => $configPr
        ? $this->t('Detected. The Sentinel Connector Config PR submodule is enabled.')
        : $this->t('Not detected. To open configuration pull requests from Sentinel: <code>@require</code> then <code>@enable</code>.', [
          '@require' => 'composer require drupal/config_pr',
          '@enable' => 'drush pm:install sentinel_connector_config_pr',
        ]),
    ];
    if ($configPr) {
      $section['config_pr']['settings'] = [
        '#type' => 'link',
        '#title' => $this->t('Config PR settings'),
        '#url' => Url::fromRoute('sentinel_connector_config_pr.settings'),
      ];
    }
    return $section;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($this->uuidResolver->isOverridden()) {
      // The read-only value is not part of the submission.
      $this->forgetRejectedValues($form_state);
      return;
    }
    $entered = $form_state->getValue('site_uuid');
    $uuid = is_string($entered) ? SiteUuid::normalize($entered) : NULL;
    if ($uuid !== NULL) {
      // Saved in the one form Sentinel accepts in the request path.
      $form_state->setValue('site_uuid', $uuid);
    }
    elseif (is_string($entered) && trim($entered) !== '') {
      // An empty value is reported by the required field itself.
      $form_state->setErrorByName('site_uuid', $this->t('The Site UUID is not a valid UUID. Copy it from the site in Sentinel: 32 hexadecimal digits in five groups, for example @example.', ['@example' => SiteUuid::EXAMPLE]));
    }
    $this->forgetRejectedValues($form_state);
  }

  /**
   * Drops the values copied into the configuration by a refused submission.
   *
   * The parent class copies every #config_target value into the editable
   * configuration before this form can refuse one. A refused submission must
   * not leave them for later reads in the same request.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function forgetRejectedValues(FormStateInterface $form_state): void {
    if ($form_state->hasAnyErrors()) {
      $this->configFactory()->reset(self::CONFIG_NAME);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Compared with the saved key before the parent class saves the new one.
    $keyId = NULL;
    $changed = FALSE;
    if (isset($form['api_key_id'])) {
      $keyId = trim((string) $form_state->getValue('api_key_id'));
      $changed = $keyId !== $this->apiKeyResolver->getKeyId();
    }
    else {
      $key = (string) $form_state->getValue('api_key_state');
      if ($key !== '') {
        $this->state->set(ApiKeyResolver::STATE_KEY, $key);
        $changed = TRUE;
      }
    }

    // Saves every element with a #config_target.
    parent::submitForm($form, $form_state);

    // The other settings are saved; the URL is fixed outside this form.
    if ($this->urlResolver->getUrl() === NULL && !$this->sendingSwitch->isDisabled()) {
      $this->messenger()->addWarning($this->connectionChecker->invalidUrlMessage());
    }
    // The selected key replaces the deprecated State key.
    if ($keyId !== NULL && $keyId !== '' && $this->apiKeyResolver->getKeyProblem() === NULL && $this->state->get(ApiKeyResolver::STATE_KEY) !== NULL) {
      $this->state->delete(ApiKeyResolver::STATE_KEY);
      $this->messenger()->addStatus($this->t('The API key saved in State was deleted. The selected key replaces it.'));
    }
    if ($changed) {
      // Nothing was accepted with the new key yet: cron pushes on its next
      // due run, so a wrong key shows up within the hour.
      $this->state->deleteMultiple([SyncService::STATE_LAST_FINGERPRINT, SyncService::STATE_LAST_UNCHANGED_TIME]);
    }
    // The status report allows a newly configured site a day to push.
    $this->syncService->markConfigured();
  }

  /**
   * Submit handler of "Test connection": one plain answer.
   *
   * @param array<array-key, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    // The button is not rendered without the permission; this guards a
    // crafted submission.
    if (!$this->currentUser()->hasPermission('trigger sentinel sync')) {
      $this->messenger()->addError($this->t('You do not have permission to test the connection.'));
      return;
    }
    $result = $this->connectionChecker->check();
    $message = $this->connectionChecker->message($result);
    if ($result->isOk()) {
      $this->messenger()->addStatus($message);
    }
    elseif ($result->outcome === ConnectionCheckResult::DISABLED || $result->outcome === ConnectionCheckResult::UNKNOWN_SITE || $result->outcome === ConnectionCheckResult::LIMITED) {
      $this->messenger()->addWarning($message);
    }
    else {
      $this->messenger()->addError($message);
    }
  }

}
