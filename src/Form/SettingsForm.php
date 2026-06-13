<?php

namespace Drupal\sentinel_connector\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\sentinel_connector\ApiKeyResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the Sentinel Connector module.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    $config_factory,
    protected ApiKeyResolver $apiKeyResolver,
  ) {
    parent::__construct($config_factory);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('Drupal\sentinel_connector\ApiKeyResolver'),
    );
  }

  protected function getEditableConfigNames(): array {
    return ['sentinel_connector.settings'];
  }

  public function getFormId(): string {
    return 'sentinel_connector_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('sentinel_connector.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic sync on cron'),
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
    ];
    $form['site_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site token'),
      '#default_value' => $config->get('site_token'),
    ];
    $form['report_scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Report scope'),
      '#options' => [
        'all' => $this->t('All (contrib + custom + core)'),
        'contrib_custom' => $this->t('Contrib + custom'),
        'contrib' => $this->t('Contrib only'),
      ],
      '#default_value' => $config->get('report_scope') ?: 'all',
    ];
    $form['cron_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum seconds between cron syncs'),
      '#min' => 900,
      '#default_value' => $config->get('cron_interval') ?: 21600,
      '#description' => $this->t('Sentinel rate-limits to 4 requests/hour per site.'),
    ];

    // API key status (never echo the key).
    $source = $this->apiKeyResolver->getSource();
    $form['api_key_status'] = [
      '#type' => 'item',
      '#title' => $this->t('API key'),
      '#markup' => $source
        ? $this->t('Configured (source: @s).', ['@s' => $source])
        : $this->t('Not configured. Set @env, $settings[@settings], or the state value below.', [
          '@env' => ApiKeyResolver::ENV_VAR,
          '@settings' => "'" . ApiKeyResolver::SETTINGS_KEY . "'",
        ]),
    ];
    $form['api_key_state'] = [
      '#type' => 'password',
      '#title' => $this->t('Set API key in state (fallback)'),
      '#description' => $this->t('Leave blank to keep the current value. Prefer settings.php or the environment variable for production.'),
    ];

    $form['sync_now'] = [
      '#type' => 'link',
      '#title' => $this->t('Sync now'),
      '#url' => Url::fromRoute('sentinel_connector.sync_now'),
      '#attributes' => ['class' => ['button', 'button--primary']],
      '#access' => \Drupal::currentUser()->hasPermission('trigger sentinel sync'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('sentinel_connector.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('api_base_url', rtrim((string) $form_state->getValue('api_base_url'), '/'))
      ->set('site_uuid', (string) $form_state->getValue('site_uuid'))
      ->set('site_url', (string) $form_state->getValue('site_url'))
      ->set('site_name', (string) $form_state->getValue('site_name'))
      ->set('site_token', (string) $form_state->getValue('site_token'))
      ->set('report_scope', (string) $form_state->getValue('report_scope'))
      ->set('cron_interval', (int) $form_state->getValue('cron_interval'))
      ->save();

    $key = (string) $form_state->getValue('api_key_state');
    if ($key !== '') {
      \Drupal::state()->set('sentinel_connector.api_key', $key);
    }

    parent::submitForm($form, $form_state);
  }

}
