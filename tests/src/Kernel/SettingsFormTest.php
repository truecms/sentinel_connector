<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\sentinel_connector\Form\SettingsForm;

/**
 * Kernel tests for the credentials on the settings form.
 *
 * @group sentinel_connector
 */
class SettingsFormTest extends KernelTestBase {

  /**
   * A saved key that must never reach the browser.
   */
  protected const SAVED_KEY = 'sk_saved_0123456789';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'sentinel_connector'];

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
  }

  /**
   * The API key is the only credential the form asks for.
   */
  public function testFormHasNoSiteToken(): void {
    $form = $this->buildSettingsForm();

    $this->assertArrayNotHasKey('site_token', $form);
    $this->assertSame('password', $form['api_key_state']['#type']);
    $this->assertSame('API key', (string) $form['api_key_state']['#title']);
    $this->assertStringContainsString('Not configured', (string) $form['api_key_status']['#markup']);
    $this->assertArrayNotHasKey('site_token', $this->config('sentinel_connector.settings')->getRawData());
  }

  /**
   * A saved key is in no part of the built or rendered form.
   */
  public function testSavedKeyIsNotDisclosed(): void {
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::SAVED_KEY);

    $form = $this->buildSettingsForm();
    $this->assertSame('Replace API key', (string) $form['api_key_state']['#title']);
    $this->assertStringContainsString('Configured', (string) $form['api_key_status']['#markup']);
    $this->assertStringNotContainsString(self::SAVED_KEY, serialize($this->plain($form)));

    $built = \Drupal::formBuilder()->getForm(SettingsForm::class);
    $html = (string) $this->container->get('renderer')->renderRoot($built);
    $this->assertStringContainsString('type="password"', $html);
    $this->assertStringNotContainsString(self::SAVED_KEY, $html);
  }

  /**
   * The status names the source that wins, without the key.
   *
   * @dataProvider precedenceSources
   */
  public function testStatusShowsTheActiveSource(string $source, string $expected): void {
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::SAVED_KEY);
    if ($source === 'settings') {
      new Settings([ApiKeyResolver::SETTINGS_KEY => 'sk_settings_abcdef'] + Settings::getAll());
    }
    else {
      putenv(ApiKeyResolver::ENV_VAR . '=sk_env_abcdef');
    }

    try {
      $form = $this->buildSettingsForm();
    }
    finally {
      putenv(ApiKeyResolver::ENV_VAR);
    }

    $status = (string) $form['api_key_status']['#markup'];
    $this->assertStringContainsString($expected, $status);
    $this->assertStringContainsString('used ahead of', $status);
    // A key saved here would not be the active one, and the field says so.
    $this->assertStringContainsString('not in use', (string) $form['api_key_state']['#title']);
    $description = (string) $form['api_key_state']['#description'];
    $this->assertStringContainsString($expected, $description);
    $this->assertStringNotContainsString('authenticates every sync request', $description);
    $text = serialize($this->plain($form));
    foreach ([self::SAVED_KEY, 'sk_settings_abcdef', 'sk_env_abcdef'] as $secret) {
      $this->assertStringNotContainsString($secret, $text);
    }
  }

  /**
   * Sources that take precedence over the key saved on the form.
   *
   * @return array<string, array{string, string}>
   *   The source and the text the status must contain.
   */
  public static function precedenceSources(): array {
    return [
      'settings.php' => ['settings', 'settings.php'],
      'environment' => ['environment', ApiKeyResolver::ENV_VAR],
    ];
  }

  /**
   * A blank key field keeps the saved key; a new value replaces it.
   */
  public function testBlankSubmissionKeepsTheKey(): void {
    $state = \Drupal::state();
    $state->set(ApiKeyResolver::STATE_KEY, self::SAVED_KEY);

    $this->submitSettingsForm(['site_name' => 'Renamed', 'api_key_state' => '']);
    $this->assertSame('Renamed', $this->config('sentinel_connector.settings')->get('site_name'));
    $this->assertSame(self::SAVED_KEY, $state->get(ApiKeyResolver::STATE_KEY));

    $this->submitSettingsForm(['api_key_state' => 'sk_new_9876543210']);
    $this->assertSame('sk_new_9876543210', $state->get(ApiKeyResolver::STATE_KEY));
    // The key goes to state only, and the next build still hides it.
    $this->assertStringNotContainsString('sk_new_9876543210', serialize($this->config('sentinel_connector.settings')->getRawData()));
    $form = $this->buildSettingsForm();
    $this->assertStringContainsString('Configured', (string) $form['api_key_status']['#markup']);
    $this->assertStringNotContainsString('sk_new_9876543210', serialize($this->plain($form)));
  }

  /**
   * The Site UUID is saved in the one form Sentinel accepts.
   *
   * @dataProvider acceptedSiteUuids
   */
  public function testSiteUuidIsNormalised(string $entered): void {
    $this->submitSettingsForm(['site_uuid' => $entered]);

    $this->assertSame('123e4567-e89b-42d3-a456-426614174000', $this->config('sentinel_connector.settings')->get('site_uuid'));
  }

  /**
   * Notations of one UUID that the form accepts.
   *
   * @return array<string, array{string}>
   *   The value entered.
   */
  public static function acceptedSiteUuids(): array {
    return [
      'canonical' => ['123e4567-e89b-42d3-a456-426614174000'],
      'padded' => ["  123e4567-e89b-42d3-a456-426614174000\t"],
      'upper case' => ['123E4567-E89B-42D3-A456-426614174000'],
      'braced' => ['{123e4567-e89b-42d3-a456-426614174000}'],
      'unhyphenated' => ['123e4567e89b42d3a456426614174000'],
      'braced, upper case, unhyphenated and padded' => [' { 123E4567E89B42D3A456426614174000 } '],
    ];
  }

  /**
   * A value that is not a UUID is refused, and the stored value is kept.
   *
   * @dataProvider rejectedSiteUuids
   */
  public function testInvalidSiteUuidIsRejected(string $entered): void {
    $config = $this->config('sentinel_connector.settings');
    $form_state = (new FormState())->setValues([
      'enabled' => $config->get('enabled'),
      'api_base_url' => $config->get('api_base_url'),
      'site_uuid' => $entered,
      'site_url' => $config->get('site_url'),
      'site_name' => 'Must not be saved',
      'report_scope' => $config->get('report_scope'),
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);

    $errors = $form_state->getErrors();
    $this->assertSame(['site_uuid'], array_keys($errors));
    $this->assertStringContainsString('The Site UUID is not a valid UUID.', (string) $errors['site_uuid']);
    $this->assertStringContainsString('123e4567-e89b-42d3-a456-426614174000', (string) $errors['site_uuid']);
    $stored = $this->config('sentinel_connector.settings');
    $this->assertSame('11111111-1111-4111-8111-111111111111', $stored->get('site_uuid'));
    $this->assertSame('Example', $stored->get('site_name'));
  }

  /**
   * Values that are not a UUID in any accepted notation.
   *
   * @return array<string, array{string}>
   *   The value entered.
   */
  public static function rejectedSiteUuids(): array {
    return [
      'word' => ['my-site'],
      'too short' => ['123e4567-e89b-42d3-a456-42661417400'],
      'too long' => ['123e4567-e89b-42d3-a456-4266141740000'],
      'not hexadecimal' => ['123e4567-e89b-42d3-a456-42661417400g'],
      'hyphens in other places' => ['123e-4567e89b-42d3-a456-426614174000'],
      'two UUIDs' => ['123e4567-e89b-42d3-a456-426614174000 123e4567-e89b-42d3-a456-426614174000'],
      'URL' => ['https://sentinel.example.com/sites/123e4567-e89b-42d3-a456-426614174000'],
      'unbalanced brace' => ['{123e4567-e89b-42d3-a456-426614174000'],
    ];
  }

  /**
   * The update removes a stored site token and leaves the API key alone.
   */
  public function testUpdateRemovesSiteToken(): void {
    // Written to storage directly: the schema no longer knows the key.
    $storage = $this->container->get('config.storage');
    $storage->write('sentinel_connector.settings', ['site_token' => 'stale-token'] + $storage->read('sentinel_connector.settings'));
    $this->container->get('config.factory')->reset('sentinel_connector.settings');
    $before = $this->config('sentinel_connector.settings')->getRawData();
    $this->assertSame('stale-token', $before['site_token']);
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::SAVED_KEY);

    $this->container->get('module_handler')->loadInclude('sentinel_connector', 'install');
    $message = sentinel_connector_update_10002();

    $this->assertStringContainsString('site token', $message);
    unset($before['site_token']);
    $this->assertSame($before, $this->config('sentinel_connector.settings')->getRawData());
    $this->assertArrayNotHasKey('site_token', $storage->read('sentinel_connector.settings'));
    $this->assertSame(self::SAVED_KEY, \Drupal::state()->get(ApiKeyResolver::STATE_KEY));

    // A second run changes nothing.
    sentinel_connector_update_10002();
    $this->assertSame($before, $this->config('sentinel_connector.settings')->getRawData());
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

  /**
   * Turns translatable markup into strings so the form can be searched.
   *
   * @param array<array-key, mixed> $form
   *   A form structure.
   *
   * @return array<array-key, mixed>
   *   The same structure with every object cast to a string or dropped.
   */
  protected function plain(array $form): array {
    $plain = [];
    foreach ($form as $key => $value) {
      if (is_array($value)) {
        $plain[$key] = $this->plain($value);
      }
      elseif ($value instanceof \Stringable) {
        $plain[$key] = (string) $value;
      }
      elseif (!is_object($value)) {
        $plain[$key] = $value;
      }
    }
    return $plain;
  }

}
