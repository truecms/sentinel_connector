<?php

namespace Drupal\sentinel_connector_config_pr\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sentinel_connector_config_pr\Host\HostFactory;
use Drupal\sentinel_connector_config_pr\PullRequestTracker;
use Drupal\sentinel_connector_config_pr\TokenResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for the configuration pull request. Holds no secrets.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The intervals offered for cron runs, in seconds.
   */
  protected const INTERVALS = [3600, 21600, 43200, 86400, 604800];

  /**
   * Constructs the settings form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager (required by ConfigFormBase on Drupal 11).
   * @param \Drupal\sentinel_connector_config_pr\TokenResolver $tokenResolver
   *   Tells whether a token is present, never its value.
   * @param \Drupal\sentinel_connector_config_pr\Host\HostFactory $hostFactory
   *   Tells whether the Git host module is enabled.
   * @param \Drupal\sentinel_connector_config_pr\PullRequestTracker $tracker
   *   The open pull request.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected TokenResolver $tokenResolver,
    protected HostFactory $hostFactory,
    protected PullRequestTracker $tracker,
    protected ModuleHandlerInterface $moduleHandler,
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
      $container->get(TokenResolver::class),
      $container->get(HostFactory::class),
      $container->get(PullRequestTracker::class),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The editable configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['sentinel_connector_config_pr.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sentinel_connector_config_pr_settings';
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
    $config = $this->config('sentinel_connector_config_pr.settings');

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Writes the configuration that differs from the sync directory to an export branch and opens one pull request (GitHub) or merge request (GitLab), or updates the open one. Your team reviews and merges it; this module never merges. Run it with <code>drush sentinel_connector:config-pr</code>, or on cron below.') . '</p>',
    ];

    $form['repository'] = [
      '#type' => 'details',
      '#title' => $this->t('Repository'),
      '#open' => TRUE,
    ];
    $form['repository']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Git host'),
      '#options' => ['github' => $this->t('GitHub'), 'gitlab' => $this->t('GitLab')],
      '#default_value' => $config->get('provider') ?: 'github',
      '#description' => $this->t('Enable the matching config_pr submodule too: config_pr_github or config_pr_gitlab.'),
      '#required' => TRUE,
    ];
    $form['repository']['api_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Host URL for GitHub Enterprise or self-managed GitLab'),
      '#default_value' => $config->get('api_base_url'),
      '#description' => $this->t('For example https://github.example.com or https://gitlab.example.com. Leave empty for github.com or gitlab.com.'),
    ];
    $form['repository']['repo_owner'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository owner'),
      '#default_value' => $config->get('repo_owner'),
      '#description' => $this->t('The GitHub user or organisation, or the GitLab group path (subgroups separated by /).'),
      '#required' => TRUE,
    ];
    $form['repository']['repo_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository name'),
      '#default_value' => $config->get('repo_name'),
      '#required' => TRUE,
    ];
    $form['repository']['base_branch'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base branch'),
      '#default_value' => $config->get('base_branch'),
      '#description' => $this->t("The branch the pull request merges into. Leave empty for the repository's default branch."),
    ];
    $form['repository']['branch'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Export branch'),
      '#default_value' => $config->get('branch') ?: 'sentinel/config-export',
      '#description' => $this->t('This module owns the branch: it updates the branch while its pull request is open and starts it again from the base branch after the pull request is merged or closed. Do not push other work to it.'),
      '#required' => TRUE,
    ];
    $form['repository']['sync_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sync directory in the repository'),
      '#default_value' => $config->get('sync_path') ?: 'config/sync',
      '#description' => $this->t('The path of the configuration sync directory from the repository root, for example config/sync.'),
      '#required' => TRUE,
    ];

    $form['pull_request'] = [
      '#type' => 'details',
      '#title' => $this->t('Pull request'),
      '#open' => TRUE,
    ];
    $form['pull_request']['pr_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $config->get('pr_title'),
      '#description' => $this->t('{site} is replaced by the site name and {date} by the date of the run.'),
      '#required' => TRUE,
    ];
    $form['pull_request']['committer_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Committer name'),
      '#default_value' => $config->get('committer_name'),
      '#description' => $this->t('Leave empty for "Sentinel Connector".'),
    ];
    $form['pull_request']['committer_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Committer email'),
      '#default_value' => $config->get('committer_email'),
      '#description' => $this->t("Leave empty for the site's email address."),
    ];
    $pull = $this->tracker->get();
    $form['pull_request']['open'] = [
      '#type' => 'item',
      '#title' => $this->t('Open pull request'),
      '#markup' => $pull ? $this->t('<a href=":url">@url</a>, last updated @time.', [
        ':url' => $pull['url'],
        '@url' => $pull['url'],
        '@time' => date('c', $pull['updated_at']),
      ]) : $this->t('None known.'),
    ];

    $form['token'] = [
      '#type' => 'details',
      '#title' => $this->t('Access token'),
      '#open' => TRUE,
    ];
    // Shows only whether a token is present, never the token.
    $form['token']['token_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Token status'),
      '#markup' => match ($this->tokenResolver->getSource()) {
        'key' => $this->t('Present, from the Key module.'),
        'settings.php' => $this->t('Present, from settings.php.'),
        default => match ($this->tokenResolver->getKeyProblem()) {
          'missing' => $this->t('Not usable: the selected key does not exist.'),
          'config_provider' => $this->t('Not usable: the selected key keeps its value in configuration. Use a key provider that reads a file or an environment variable.'),
          'empty' => $this->t('Not usable: the selected key has no value.'),
          'no_module' => $this->t('Not usable: the Key module is not enabled.'),
          default => $this->t("Not configured. Select a key below, or set \$settings['@key']['token'] in settings.php.", ['@key' => TokenResolver::SETTINGS_KEY]),
        },
      },
    ];
    if ($this->moduleHandler->moduleExists('key')) {
      $form['token']['token_key_id'] = [
        '#type' => 'key_select',
        '#title' => $this->t('Key'),
        '#empty_option' => $this->t('- None: use settings.php -'),
        '#default_value' => $config->get('token_key_id'),
        '#description' => $this->t('A key whose provider reads a file or an environment variable. Keys that keep the value in configuration are refused.'),
      ];
    }
    $form['token']['permissions'] = [
      '#markup' => '<p>' . $this->t('GitHub: a fine-grained token for this repository only, with Contents and Pull requests set to read and write. GitLab: a project access token with the api scope and the Developer role.') . '</p>',
    ];

    $form['schedule'] = [
      '#type' => 'details',
      '#title' => $this->t('Schedule'),
      '#open' => TRUE,
    ];
    $form['schedule']['cron_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Run on cron'),
      '#default_value' => $config->get('cron_enabled'),
      '#description' => $this->t('Cron runs only while Sentinel Connector cron reporting is enabled too, which the README keeps to production.'),
    ];
    $options = [];
    foreach (self::INTERVALS as $seconds) {
      $options[$seconds] = $seconds < 86400
        ? $this->formatPlural(intdiv($seconds, 3600), '1 hour', '@count hours')
        : $this->formatPlural(intdiv($seconds, 86400), '1 day', '@count days');
    }
    $form['schedule']['interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Run at most every'),
      '#options' => $options,
      '#default_value' => (int) $config->get('interval') ?: 86400,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $url = trim((string) $form_state->getValue('api_base_url'));
    if ($url !== '' && !str_starts_with(strtolower($url), 'https://')) {
      $form_state->setErrorByName('api_base_url', $this->t('The host URL must use https.'));
    }
    if (!preg_match('/^[A-Za-z0-9_.-]+(\/[A-Za-z0-9_.-]+)*$/', trim((string) $form_state->getValue('repo_owner')))) {
      $form_state->setErrorByName('repo_owner', $this->t('Use letters, digits, ".", "-", "_" and "/" only.'));
    }
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', trim((string) $form_state->getValue('repo_name')))) {
      $form_state->setErrorByName('repo_name', $this->t('Use letters, digits, ".", "-" and "_" only.'));
    }
    $branch = trim((string) $form_state->getValue('branch'));
    if ($branch === trim((string) $form_state->getValue('base_branch'))) {
      $form_state->setErrorByName('branch', $this->t('The export branch must differ from the base branch.'));
    }
    $syncPath = trim(trim((string) $form_state->getValue('sync_path')), '/');
    if ($syncPath === '' || in_array('..', explode('/', $syncPath), TRUE)) {
      $form_state->setErrorByName('sync_path', $this->t('Enter a path inside the repository, such as config/sync.'));
    }
    if (!in_array((int) $form_state->getValue('interval'), self::INTERVALS, TRUE)) {
      $form_state->setErrorByName('interval', $this->t('Choose an interval from the list.'));
    }
    $provider = (string) $form_state->getValue('provider');
    if (isset(HostFactory::MODULES[$provider]) && !$this->moduleHandler->moduleExists(HostFactory::MODULES[$provider])) {
      $this->messenger()->addWarning($this->t('Enable the @module module before the first run.', ['@module' => HostFactory::MODULES[$provider]]));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('sentinel_connector_config_pr.settings')
      ->set('provider', (string) $form_state->getValue('provider'))
      ->set('api_base_url', rtrim(trim((string) $form_state->getValue('api_base_url')), '/'))
      ->set('repo_owner', trim((string) $form_state->getValue('repo_owner')))
      ->set('repo_name', trim((string) $form_state->getValue('repo_name')))
      ->set('base_branch', trim((string) $form_state->getValue('base_branch')))
      ->set('branch', trim((string) $form_state->getValue('branch')))
      ->set('sync_path', trim(trim((string) $form_state->getValue('sync_path')), '/'))
      ->set('pr_title', trim((string) $form_state->getValue('pr_title')))
      ->set('committer_name', trim((string) $form_state->getValue('committer_name')))
      ->set('committer_email', trim((string) $form_state->getValue('committer_email')))
      ->set('token_key_id', (string) ($form_state->getValue('token_key_id') ?? $this->config('sentinel_connector_config_pr.settings')->get('token_key_id')))
      ->set('cron_enabled', (bool) $form_state->getValue('cron_enabled'))
      ->set('interval', (int) $form_state->getValue('interval'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
