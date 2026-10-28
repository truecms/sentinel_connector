<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Form\FormState;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Drupal\sentinel_connector\ApiKeyMigrator;
use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\sentinel_connector\Form\SettingsForm;
use Drupal\sentinel_connector\SyncService;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Key module source and the move of a State key into a key.
 *
 * @group sentinel_connector
 */
#[RunTestsInSeparateProcesses]
#[Group('sentinel_connector')]
class ApiKeyKeyModuleTest extends KernelTestBase {

  /**
   * A key saved in State by an earlier version of the form.
   */
  protected const STATE_VALUE = 'sk_state_0123456789';

  /**
   * A key read from the environment through the Key module.
   */
  protected const KEY_VALUE = 'sk_key_0123456789';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'sentinel_connector'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['sentinel_connector']);
    $this->config('sentinel_connector.settings')
      ->set('api_base_url', 'https://sentinel.example.com')
      ->set('site_uuid', '11111111-1111-4111-8111-111111111111')
      ->set('site_url', 'https://example.com')
      ->set('site_name', 'Example')
      ->save();
    $this->container->get('router.builder')->rebuild();
    putenv('SENTINEL_KEY_TEST=' . self::KEY_VALUE);
    Key::create([
      'id' => 'sentinel_env',
      'label' => 'Sentinel (environment)',
      'key_type' => 'authentication',
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'SENTINEL_KEY_TEST',
        'base64_encoded' => FALSE,
        'strip_line_breaks' => TRUE,
      ],
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('SENTINEL_KEY_TEST');
    putenv(ApiKeyResolver::ENV_VAR);
    parent::tearDown();
  }

  /**
   * Sources resolve as settings.php, key, environment, then State.
   */
  public function testResolutionOrder(): void {
    $state = \Drupal::state();
    $state->set(ApiKeyResolver::STATE_KEY, self::STATE_VALUE);
    $this->assertSame([self::STATE_VALUE, 'state'], $this->resolved());

    putenv(ApiKeyResolver::ENV_VAR . '=sk_env_abcdef');
    $this->assertSame(['sk_env_abcdef', 'environment'], $this->resolved());

    $this->config('sentinel_connector.settings')->set('api_key_id', 'sentinel_env')->save();
    $this->assertSame([self::KEY_VALUE, 'key'], $this->resolved());

    new Settings([ApiKeyResolver::SETTINGS_KEY => 'sk_settings_abcdef'] + Settings::getAll());
    $this->assertSame(['sk_settings_abcdef', 'settings.php'], $this->resolved());
  }

  /**
   * The form offers a key selector, never the key, and no State field.
   */
  public function testFormSelectsKey(): void {
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::STATE_VALUE);
    $form = $this->buildSettingsForm();
    $this->assertSame('key_select', $form['api_key_id']['#type']);
    $this->assertArrayNotHasKey('api_key_state', $form);
    $this->assertStringContainsString('deprecated', (string) $form['api_key_status']['#markup']);
    $this->assertStringContainsString('/admin/config/system/keys/add', (string) $form['api_key_id']['#description']);

    \Drupal::state()->set(SyncService::STATE_LAST_FINGERPRINT, 'old');
    $this->submitSettingsForm(['api_key_id' => 'sentinel_env']);
    $this->assertSame('sentinel_env', $this->config('sentinel_connector.settings')->get('api_key_id'));
    // The selected key replaces the deprecated State key.
    $this->assertNull(\Drupal::state()->get(ApiKeyResolver::STATE_KEY));
    $this->assertNull(\Drupal::state()->get(SyncService::STATE_LAST_FINGERPRINT));

    $form = $this->buildSettingsForm();
    $this->assertStringContainsString('Sentinel (environment)', (string) $form['api_key_status']['#markup']);
    $built = \Drupal::formBuilder()->getForm(SettingsForm::class);
    $html = (string) $this->container->get('renderer')->renderRoot($built);
    $this->assertStringNotContainsString(self::KEY_VALUE, $html);
  }

  /**
   * A key kept in configuration is refused and the status says so.
   */
  public function testConfigProviderIsRefused(): void {
    Key::create([
      'id' => 'sentinel_config',
      'label' => 'Sentinel (configuration)',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'sk_config_abcdef'],
    ])->save();
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::STATE_VALUE);

    $this->submitSettingsForm(['api_key_id' => 'sentinel_config']);
    // An unusable key does not replace the State key.
    $this->assertSame([self::STATE_VALUE, 'state'], $this->resolved());
    $status = (string) $this->buildSettingsForm()['api_key_status']['#markup'];
    $this->assertStringContainsString('The selected key is not used', $status);
    $this->assertStringContainsString('configuration', $status);
  }

  /**
   * The update moves a State key into a new key and selects it.
   */
  public function testUpdateMovesStateKey(): void {
    $state = \Drupal::state();
    $state->set(ApiKeyResolver::STATE_KEY, self::STATE_VALUE);
    // An existing key with the default id is kept.
    Key::create([
      'id' => ApiKeyMigrator::KEY_ID,
      'label' => 'Unrelated',
      'key_type' => 'authentication',
      'key_provider' => 'env',
      'key_provider_settings' => ['env_variable' => 'SENTINEL_KEY_TEST'],
    ])->save();

    $this->container->get('module_handler')->loadInclude('sentinel_connector', 'install');
    $message = sentinel_connector_update_10004();

    $id = ApiKeyMigrator::KEY_ID . '_2';
    $this->assertStringContainsString($id, $message);
    $this->assertSame($id, $this->config('sentinel_connector.settings')->get('api_key_id'));
    $this->assertNull($state->get(ApiKeyResolver::STATE_KEY));
    $key = Key::load($id);
    $this->assertSame('state', $key->get('key_provider'));
    $this->assertStringContainsString('not recommended', (string) $key->label());
    $this->assertSame([self::STATE_VALUE, 'key'], $this->resolved());
    $this->assertSame('Unrelated', Key::load(ApiKeyMigrator::KEY_ID)->label());

    // A second run changes nothing.
    $this->assertStringContainsString('Nothing to move', sentinel_connector_update_10004());
    $this->assertSame($id, $this->config('sentinel_connector.settings')->get('api_key_id'));
  }

  /**
   * A State key is left alone when a key is already selected.
   */
  public function testMigrationKeepsSelectedKey(): void {
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::STATE_VALUE);
    $this->config('sentinel_connector.settings')->set('api_key_id', 'sentinel_env')->save();

    $this->assertNull($this->container->get(ApiKeyMigrator::class)->migrate());
    $this->assertSame(self::STATE_VALUE, \Drupal::state()->get(ApiKeyResolver::STATE_KEY));
    $this->assertSame([self::KEY_VALUE, 'key'], $this->resolved());
  }

  /**
   * Migrating an unused State key must not replace an external credential.
   */
  public function testMigrationKeepsExternalKey(): void {
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::STATE_VALUE);
    $migrator = $this->container->get(ApiKeyMigrator::class);

    putenv(ApiKeyResolver::ENV_VAR . '=sk_env_abcdef');
    $this->assertNull($migrator->migrate());
    $this->assertSame(['sk_env_abcdef', 'environment'], $this->resolved());

    putenv(ApiKeyResolver::ENV_VAR);
    new Settings([ApiKeyResolver::SETTINGS_KEY => 'sk_settings_abcdef'] + Settings::getAll());
    $this->assertNull($migrator->migrate());
    $this->assertSame(['sk_settings_abcdef', 'settings.php'], $this->resolved());
    $this->assertSame(self::STATE_VALUE, \Drupal::state()->get(ApiKeyResolver::STATE_KEY));
    $this->assertEmpty($this->config('sentinel_connector.settings')->get('api_key_id'));
    $this->assertNull(Key::load(ApiKeyMigrator::KEY_ID));
  }

  /**
   * The resolved key and its source.
   *
   * @return array{0: string|null, 1: string|null}
   *   The key and its source.
   */
  protected function resolved(): array {
    $resolver = $this->container->get(ApiKeyResolver::class);
    return [$resolver->getKey(), $resolver->getSource()];
  }

  /**
   * Builds the settings form without rendering it.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  protected function buildSettingsForm(): array {
    return SettingsForm::create($this->container)->buildForm([], new FormState());
  }

  /**
   * Submits the settings form with the stored values and the given changes.
   *
   * @param array<string, mixed> $values
   *   Values that differ from the stored configuration.
   */
  protected function submitSettingsForm(array $values): void {
    $config = $this->config('sentinel_connector.settings');
    $form_state = (new FormState())->setValues($values + [
      'enabled' => $config->get('enabled'),
      'api_base_url' => $config->get('api_base_url'),
      'site_uuid' => $config->get('site_uuid'),
      'site_url' => $config->get('site_url'),
      'site_name' => $config->get('site_name'),
      'report_scope' => $config->get('report_scope'),
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
  }

}
