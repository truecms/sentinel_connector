<?php

namespace Drupal\sentinel_connector\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\sentinel_connector\PushLimitFormatter;
use Drupal\sentinel_connector\SyncResult;
use Drupal\sentinel_connector\SyncService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the Sentinel Connector module.
 */
final class SettingsForm extends ConfigFormBase {

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
   *   The state store, used to persist the API key entered on the form.
   * @param \Drupal\sentinel_connector\SyncService $syncService
   *   The sync service, for the stored push-limit outcome.
   * @param \Drupal\sentinel_connector\PushLimitFormatter $pushLimitFormatter
   *   Builds the text for a refused push.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected ApiKeyResolver $apiKeyResolver,
    protected StateInterface $state,
    protected SyncService $syncService,
    protected PushLimitFormatter $pushLimitFormatter,
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
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The editable configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['sentinel_connector.settings'];
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
    $config = $this->config('sentinel_connector.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic sync on cron'),
      '#description' => $this->t('Cron sends at most one push an hour. How often Sentinel accepts a push depends on the plan.'),
      '#default_value' => $config->get('enabled'),
    ];
    $form['api_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Sentinel API base URL'),
      '#default_value' => $config->get('api_base_url'),
      '#required' => TRUE,
    ];
    $form['site_uuid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site UUID'),
      '#default_value' => $config->get('site_uuid'),
      '#required' => TRUE,
    ];
    $form['site_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Site URL (must match the registered URL)'),
      '#default_value' => $config->get('site_url'),
      '#required' => TRUE,
    ];
    $form['site_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site name'),
      '#default_value' => $config->get('site_name'),
      '#required' => TRUE,
    ];
    $form['report_scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Report scope'),
      '#options' => [
        'all' => $this->t('All (contrib + custom, core by version)'),
        'contrib_custom' => $this->t('Contrib + custom'),
        'contrib' => $this->t('Contrib only'),
      ],
      '#default_value' => $config->get('report_scope') ?: 'all',
    ];
    // API key status: the source is shown, the key never is.
    $source = $this->apiKeyResolver->getSource();
    $form['api_key_status'] = [
      '#type' => 'item',
      '#title' => $this->t('API key status'),
      '#markup' => match ($source) {
        'settings.php' => $this->t('Configured in settings.php. This key is used ahead of the environment variable and any key saved on this form.'),
        'environment' => $this->t('Configured in the @env environment variable. This key is used ahead of any key saved on this form.', ['@env' => ApiKeyResolver::ENV_VAR]),
        'state' => $this->t('Configured. The key was saved on this form.'),
        default => $this->t('Not configured. Enter the API key below, or set $settings[@settings] in settings.php or the @env environment variable.', [
          '@settings' => "'" . ApiKeyResolver::SETTINGS_KEY . "'",
          '@env' => ApiKeyResolver::ENV_VAR,
        ]),
      },
    ];
    // Never given a default value, so a saved key is not sent to the browser.
    // A key saved here is not used while settings.php or the environment
    // supplies one, so the field must not claim to replace the active key.
    $external = $source === 'settings.php' || $source === 'environment';
    $form['api_key_state'] = [
      '#type' => 'password',
      '#title' => match (TRUE) {
        $external => $this->t('API key saved on this form (not in use)'),
        $source === 'state' => $this->t('Replace API key'),
        default => $this->t('API key'),
      },
      '#description' => $external
        ? $this->t('Sync requests use the key from @source, not a key saved here. To change or rotate the active key, change it in @source. A key entered here is saved in the database and used only if that key is removed. Leave blank to keep the saved value.', [
          '@source' => $source === 'settings.php' ? 'settings.php' : ApiKeyResolver::ENV_VAR,
        ])
        : $this->t('The key from the site dashboard in Sentinel. It authenticates every sync request and is the only credential needed. Leave blank to keep the current key. The key is saved in the database, not in exported configuration; for production prefer settings.php or the environment variable.'),
      '#attributes' => ['autocomplete' => 'off'],
    ];

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

    $form['sync_now'] = [
      '#type' => 'link',
      '#title' => $this->t('Sync now'),
      '#url' => Url::fromRoute('sentinel_connector.sync_now'),
      '#attributes' => ['class' => ['button', 'button--primary']],
      '#access' => $this->currentUser()->hasPermission('trigger sentinel sync'),
    ];

    return parent::buildForm($form, $form_state);
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
    $this->config('sentinel_connector.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('api_base_url', rtrim((string) $form_state->getValue('api_base_url'), '/'))
      ->set('site_uuid', (string) $form_state->getValue('site_uuid'))
      ->set('site_url', (string) $form_state->getValue('site_url'))
      ->set('site_name', (string) $form_state->getValue('site_name'))
      ->set('report_scope', (string) $form_state->getValue('report_scope'))
      ->save();

    $key = (string) $form_state->getValue('api_key_state');
    if ($key !== '') {
      $this->state->set('sentinel_connector.api_key', $key);
    }
    // The status report allows a newly configured site a day to push.
    $this->syncService->markConfigured();

    parent::submitForm($form, $form_state);
  }

}
