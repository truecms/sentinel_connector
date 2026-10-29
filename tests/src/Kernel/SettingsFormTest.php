<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Form\FormState;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\ApiKeyMigrator;
use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\sentinel_connector\ConnectionChecker;
use Drupal\sentinel_connector\Controller\SyncController;
use Drupal\sentinel_connector\Form\SettingsForm;
use Drupal\sentinel_connector\SentinelClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for the credentials on the settings form.
 *
 * @group sentinel_connector
 */
#[RunTestsInSeparateProcesses]
#[Group('sentinel_connector')]
class SettingsFormTest extends KernelTestBase {

  use UserCreationTrait;

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
    $this->setSetting('sentinel_connector.api_base_url', 'https://sentinel.example.com');
    $this->installConfig(['sentinel_connector']);
    $this->config('sentinel_connector.settings')
      ->set('site_uuid', '11111111-1111-4111-8111-111111111111')
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
  #[DataProvider('precedenceSources')]
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

    $this->submitSettingsForm(['report_scope' => 'core_contrib', 'api_key_state' => '']);
    $this->assertSame('core_contrib', $this->config('sentinel_connector.settings')->get('report_scope'));
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
  #[DataProvider('acceptedSiteUuids')]
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
  #[DataProvider('rejectedSiteUuids')]
  public function testInvalidSiteUuidIsRejected(string $entered): void {
    $config = $this->config('sentinel_connector.settings');
    $form_state = (new FormState())->setValues([
      'enabled' => $config->get('enabled'),
      'site_uuid' => $entered,
      'report_scope' => 'core_contrib',
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);

    $errors = $form_state->getErrors();
    $this->assertSame(['site_uuid'], array_keys($errors));
    $this->assertStringContainsString('The Site UUID is not a valid UUID.', (string) $errors['site_uuid']);
    $this->assertStringContainsString('123e4567-e89b-42d3-a456-426614174000', (string) $errors['site_uuid']);
    $stored = $this->config('sentinel_connector.settings');
    $this->assertSame('11111111-1111-4111-8111-111111111111', $stored->get('site_uuid'));
    $this->assertSame('all', $stored->get('report_scope'));
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
   * Update 10005 removes the connection settings and maps the scope.
   */
  public function testUpdateMinimisesSettings(): void {
    // Written to storage directly: the schema no longer knows the keys.
    $storage = $this->container->get('config.storage');
    $old = $storage->read('sentinel_connector.settings');
    unset($old['report_platform']);
    $storage->write('sentinel_connector.settings', [
      'api_base_url' => 'https://uat-ingest.sentinelconnector.io',
      'site_url' => 'https://example.com',
      'site_name' => 'Example',
      'report_scope' => 'contrib',
    ] + $old);
    $this->container->get('config.factory')->reset('sentinel_connector.settings');

    $this->container->get('module_handler')->loadInclude('sentinel_connector', 'install');
    $message = sentinel_connector_update_10005();

    $saved = $storage->read('sentinel_connector.settings');
    foreach (['api_base_url', 'site_url', 'site_name'] as $removed) {
      $this->assertArrayNotHasKey($removed, $saved);
    }
    $this->assertSame('core_contrib', $saved['report_scope']);
    $this->assertTrue($saved['report_platform']);
    $this->assertSame('11111111-1111-4111-8111-111111111111', $saved['site_uuid']);
    // The site must move its non-production URL to settings.php.
    $this->assertStringContainsString('was not the production default', $message);
    $this->assertStringContainsString('the sentinel_connector.api_base_url setting in settings.php', $message);
    $this->assertStringNotContainsString('uat-ingest', $message);

    // A second run changes nothing and warns about nothing.
    $this->assertStringNotContainsString('production default', sentinel_connector_update_10005());
    $this->assertSame($saved, $storage->read('sentinel_connector.settings'));
  }

  /**
   * Enabling the Key module moves a State key into a key.
   */
  public function testEnablingKeyMovesStateKey(): void {
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::SAVED_KEY);
    $this->container->get('module_handler')->loadInclude('sentinel_connector', 'install');
    $this->assertStringContainsString('Enable the Key module', sentinel_connector_update_10004());
    $this->assertSame(self::SAVED_KEY, \Drupal::state()->get(ApiKeyResolver::STATE_KEY));

    $this->container->get('module_installer')->install(['key']);

    $this->assertSame(ApiKeyMigrator::KEY_ID, $this->config('sentinel_connector.settings')->get('api_key_id'));
    $this->assertNull(\Drupal::state()->get(ApiKeyResolver::STATE_KEY));
    $resolver = $this->container->get(ApiKeyResolver::class);
    $this->assertSame(self::SAVED_KEY, $resolver->getKey());
    $this->assertSame('key', $resolver->getSource());
  }

  /**
   * The sync button stays hidden until a save stores a full configuration.
   */
  public function testSyncNowAppearsAfterSuccessfulSave(): void {
    $this->setUpSyncUser(['trigger sentinel sync']);
    // A fresh install: nothing saved yet.
    $this->config('sentinel_connector.settings')
      ->set('site_uuid', '')
      ->save();
    $this->assertFalse($this->buildSettingsForm()['actions']['sync_now']['#access']);

    // A submission that fails validation saves nothing.
    $form_state = (new FormState())->setValues([
      'enabled' => FALSE,
      'site_uuid' => 'not-a-uuid',
      'report_scope' => 'all',
      'api_key_state' => 'sk_new_9876543210',
    ]);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);
    $this->assertNotSame([], $form_state->getErrors());
    $this->assertFalse($this->buildSettingsForm()['actions']['sync_now']['#access']);

    // Saved without an API key: a push could not be sent yet.
    $this->submitSettingsForm([
      'site_uuid' => '11111111-1111-4111-8111-111111111111',
    ]);
    $this->assertFalse($this->buildSettingsForm()['actions']['sync_now']['#access']);

    $this->submitSettingsForm(['api_key_state' => 'sk_new_9876543210']);
    $form = $this->buildSettingsForm();
    $this->assertArrayNotHasKey('sync_now', $form);
    $this->assertTrue($form['actions']['sync_now']['#access']);

    // Rendered in the actions, to the right of "Save configuration".
    $built = \Drupal::formBuilder()->getForm(SettingsForm::class);
    $html = (string) $this->container->get('renderer')->renderRoot($built);
    $save = strpos($html, 'value="Save configuration"');
    $sync = strpos($html, '>Sync now</a>');
    $this->assertNotFalse($save);
    $this->assertNotFalse($sync);
    $this->assertGreaterThan($save, $sync);
    $actions = substr($html, (int) strpos($html, 'data-drupal-selector="edit-actions"'));
    $this->assertStringContainsString('>Sync now</a>', $actions);
  }

  /**
   * A complete configuration does not show "Sync now" without the permission.
   */
  public function testSyncNowNeedsThePermission(): void {
    $this->setUpSyncUser([]);
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::SAVED_KEY);

    $this->assertFalse($this->buildSettingsForm()['actions']['sync_now']['#access']);
  }

  /**
   * The form saves only the settings it still has.
   */
  public function testFormSavesOnlyRemainingKeys(): void {
    // A site updated without running update 10005 yet.
    $this->config('sentinel_connector.settings')->set('report_scope', 'contrib')->save();
    $form = $this->buildSettingsForm();
    foreach (['site_url', 'site_name', 'api_key'] as $removed) {
      $this->assertArrayNotHasKey($removed, $form);
    }
    $this->assertSame('item', $form['api_base_url']['#type']);
    // The legacy scope shows as its replacement.
    $this->assertSame('core_contrib', $form['report_scope']['#default_value']);
    $this->assertSame(['all', 'core_contrib'], array_keys($form['report_scope']['#options']));
    $this->assertTrue($form['report_platform']['#default_value']);
    $this->assertStringContainsString('The PHP version is always sent', (string) $form['report_platform']['#description']);

    // NULL unchecks a checkbox in a programmed submission.
    $this->submitSettingsForm([
      'report_scope' => 'core_contrib',
      'report_platform' => NULL,
      'api_base_url' => 'https://evil.example.com',
    ]);
    $saved = $this->config('sentinel_connector.settings')->getRawData();
    unset($saved['_core']);
    $this->assertSame([
      'site_uuid' => '11111111-1111-4111-8111-111111111111',
      'report_scope' => 'core_contrib',
      'enabled' => FALSE,
      'report_config_drift' => TRUE,
      'report_platform' => FALSE,
      'api_key_id' => '',
    ], $saved);
  }

  /**
   * The API base URL is shown read-only with its source.
   */
  public function testApiBaseUrlIsReadOnly(): void {
    $markup = (string) $this->buildSettingsForm()['api_base_url']['#markup'];
    $this->assertStringContainsString('https://sentinel.example.com', $markup);
    $this->assertStringContainsString('from settings.php', $markup);

    $this->setSetting('sentinel_connector.api_base_url', NULL);
    $markup = (string) $this->buildSettingsForm()['api_base_url']['#markup'];
    $this->assertStringContainsString('https://ingest.sentinelconnector.io', $markup);
    $this->assertStringContainsString('Default', $markup);

    // An invalid override: the form says why, and saving still works.
    $this->setSetting('sentinel_connector.api_base_url', 'https://ingest.sentinelconnector.io/api/v1/sites/3f6c/modules/sync');
    $form = $this->buildSettingsForm();
    $this->assertStringContainsString('Not valid', (string) $form['api_base_url']['#markup']);
    $this->assertStringContainsString('Use the base URL, not the sync endpoint', (string) $form['api_base_url_error']['#markup']);
    $this->submitSettingsForm(['report_scope' => 'core_contrib']);
    $this->assertSame('core_contrib', $this->config('sentinel_connector.settings')->get('report_scope'));
    $warnings = \Drupal::messenger()->messagesByType('warning');
    $this->assertStringContainsString('Nothing is sent until this is fixed', (string) end($warnings));
  }

  /**
   * A Site UUID from settings.php is shown read-only and never overwritten.
   */
  public function testSiteUuidOverrideIsReadOnly(): void {
    $this->setSetting('sentinel_connector.site_uuid', '22222222-2222-4222-8222-222222222222');
    $form = $this->buildSettingsForm();
    $this->assertSame('item', $form['site_uuid']['#type']);
    $this->assertStringContainsString('22222222-2222-4222-8222-222222222222', (string) $form['site_uuid']['#markup']);
    $this->assertStringContainsString('from settings.php', (string) $form['site_uuid']['#markup']);

    $this->submitSettingsForm(['site_uuid' => 'ignored']);
    $this->assertSame('11111111-1111-4111-8111-111111111111', $this->config('sentinel_connector.settings')->get('site_uuid'));
  }

  /**
   * The off switch hides both buttons and says why.
   */
  public function testOffSwitchHidesButtons(): void {
    $this->setUpSyncUser(['trigger sentinel sync']);
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::SAVED_KEY);
    $form = $this->buildSettingsForm();
    $this->assertTrue($form['actions']['sync_now']['#access']);
    $this->assertTrue($form['actions']['test_connection']['#access']);
    $this->assertArrayNotHasKey('sending_disabled', $form);

    $this->setSetting('sentinel_connector.disabled', TRUE);
    $form = $this->buildSettingsForm();
    $this->assertFalse($form['actions']['sync_now']['#access']);
    $this->assertFalse($form['actions']['test_connection']['#access']);
    $this->assertStringContainsString('Sending is turned off for this environment', (string) $form['sending_disabled']['#markup']);

    // "Sync now" reached directly sends nothing.
    $response = SyncController::create($this->container)->syncNow();
    $this->assertStringContainsString('/admin/config/services/sentinel-connector', $response->getTargetUrl());
    $this->assertNull(\Drupal::state()->get('sentinel_connector.last_attempt_time'));
    $status = \Drupal::messenger()->messagesByType('status');
    $this->assertStringContainsString('Sending is turned off', (string) end($status));
  }

  /**
   * The "Test connection" button needs the "Sync now" permission.
   */
  public function testTestConnectionNeedsThePermission(): void {
    $this->setUpSyncUser([]);
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::SAVED_KEY);
    $this->assertFalse($this->buildSettingsForm()['actions']['test_connection']['#access']);

    $transactions = $this->fakeSentinel([new Response(200, [], '{"ok": true, "environment": "production"}')]);
    $form = [];
    SettingsForm::create($this->container)->testConnection($form, new FormState());
    $this->assertCount(0, $transactions);
    $this->assertNotEmpty(\Drupal::messenger()->messagesByType('error'));
  }

  /**
   * The "Test connection" button gives one plain answer for each outcome.
   *
   * @dataProvider connectionAnswers
   */
  #[DataProvider('connectionAnswers')]
  public function testConnectionOutcomes(int $code, string $body, string $type, string $expected): void {
    $this->setUpSyncUser(['trigger sentinel sync']);
    \Drupal::state()->set(ApiKeyResolver::STATE_KEY, self::SAVED_KEY);
    $transactions = $this->fakeSentinel([new Response($code, [], $body)]);

    $form = [];
    SettingsForm::create($this->container)->testConnection($form, new FormState());

    $this->assertCount(1, $transactions);
    $this->assertStringEndsWith('/api/v1/sites/11111111-1111-4111-8111-111111111111/connection-check', (string) $transactions[0]['request']->getUri());
    $messages = \Drupal::messenger()->messagesByType($type);
    $this->assertNotEmpty($messages);
    $this->assertStringContainsString($expected, (string) end($messages));
    $this->assertStringNotContainsString(self::SAVED_KEY, (string) end($messages));
    // Recorded for the status report.
    $this->assertSame($type === 'status', \Drupal::state()->get(ConnectionChecker::STATE_LAST_CHECK)['outcome'] === 'connected');
  }

  /**
   * Answers of Sentinel and of other servers.
   *
   * @return array<string, array{int, string, string, string}>
   *   The status, the body, the message type and a part of the message.
   */
  public static function connectionAnswers(): array {
    return [
      'connected' => [
        200,
        '{"ok": true, "environment": "production"}',
        'status',
        'Connected to Sentinel production as this site. The API key and Site UUID are accepted.',
      ],
      'not sentinel' => [200, '<html>Hello</html>', 'error', 'No Sentinel API answered at https://sentinel.example.com'],
      'unknown uuid' => [
        404,
        '{"error_code": "site_unavailable", "environment": "uat"}',
        'warning',
        'Sentinel UAT does not know this Site UUID. This environment sends to https://sentinel.example.com (set in settings.php).',
      ],
      'key refused' => [
        401,
        '{"error_code": "api_key_invalid"}',
        'error',
        'refused the API key, or the key belongs to another site',
      ],
      'subscription' => [402, '{"error_code": "subscription_inactive", "reason": "past_due"}', 'warning', 'payment'],
      'push limit' => [429, '{"error_code": "push_limit_reached", "retry_after_seconds": 3600}', 'warning', 'push'],
    ];
  }

  /**
   * Replaces the HTTP client with one that gives the responses in order.
   *
   * @param \GuzzleHttp\Psr7\Response[] $responses
   *   The responses.
   *
   * @return \ArrayObject<int, array<string, mixed>>
   *   The requests sent, filled as they are sent.
   */
  protected function fakeSentinel(array $responses): \ArrayObject {
    $transactions = new \ArrayObject();
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($transactions));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    // The client service holds the HTTP client it was built with.
    $this->container->set(SentinelClient::class, NULL);
    $this->container->set(ConnectionChecker::class, NULL);
    return $transactions;
  }

  /**
   * Makes a user with the given permissions the current user.
   *
   * @param string[] $permissions
   *   The permissions to grant.
   */
  protected function setUpSyncUser(array $permissions): void {
    $this->installEntitySchema('user');
    // User 1 bypasses permission checks, so it is created and not used.
    $this->createUser();
    $this->setCurrentUser($this->createUser($permissions));
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
      'site_uuid' => $config->get('site_uuid'),
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
