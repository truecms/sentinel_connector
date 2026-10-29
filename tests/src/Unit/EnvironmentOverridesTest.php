<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\sentinel_connector\SendingSwitch;
use Drupal\sentinel_connector\SettingsUpgrade;
use Drupal\sentinel_connector\SiteUuidResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Site UUID override, the off switch, the example file and update.
 *
 * @group sentinel_connector
 */
#[Group('sentinel_connector')]
class EnvironmentOverridesTest extends TestCase {

  /**
   * The environment variables these tests change.
   */
  protected const VARIABLES = [
    SiteUuidResolver::ENV_VAR,
    SendingSwitch::ENV_VAR,
    'SENTINEL_CONNECTOR_ENVIRONMENT',
    'SENTINEL_CONNECTOR_API_KEY_DEVELOP',
    'CI',
  ];

  /**
   * The values of VARIABLES before the test.
   *
   * @var array<string, string|false>
   */
  protected array $saved = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (self::VARIABLES as $name) {
      $this->saved[$name] = getenv($name);
      putenv($name);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->saved as $name => $value) {
      putenv($value === FALSE ? $name : $name . '=' . $value);
    }
    parent::tearDown();
  }

  /**
   * Settings.php wins over the environment, the environment over the form.
   */
  public function testSiteUuidSources(): void {
    $form = '22222222-2222-4222-8222-222222222222';
    $resolver = new SiteUuidResolver(new Settings([]), $this->configFactory($form));
    $this->assertSame($form, $resolver->getUuid());
    $this->assertSame(SiteUuidResolver::SOURCE_CONFIG, $resolver->getSource());
    $this->assertFalse($resolver->isOverridden());

    putenv(SiteUuidResolver::ENV_VAR . '=33333333333343338333333333333333');
    $resolver = new SiteUuidResolver(new Settings([]), $this->configFactory($form));
    // Normalised like the form value.
    $this->assertSame('33333333-3333-4333-8333-333333333333', $resolver->getUuid());
    $this->assertSame(SiteUuidResolver::SOURCE_ENVIRONMENT, $resolver->getSource());
    $this->assertTrue($resolver->isOverridden());

    $resolver = new SiteUuidResolver(new Settings([SiteUuidResolver::SETTINGS_KEY => '{44444444-4444-4444-8444-444444444444}']), $this->configFactory($form));
    $this->assertSame('44444444-4444-4444-8444-444444444444', $resolver->getUuid());
    $this->assertSame(SiteUuidResolver::SOURCE_SETTINGS, $resolver->getSource());

    // An override that is not a UUID is refused, not replaced by the form.
    $resolver = new SiteUuidResolver(new Settings([SiteUuidResolver::SETTINGS_KEY => 'my-site']), $this->configFactory($form));
    $this->assertNull($resolver->getUuid());
    $this->assertSame('my-site', $resolver->getRawValue());
  }

  /**
   * The off switch, from settings.php or the environment.
   */
  #[DataProvider('switchValues')]
  public function testSendingSwitch(mixed $setting, ?string $env, ?string $source): void {
    if ($env !== NULL) {
      putenv(SendingSwitch::ENV_VAR . '=' . $env);
    }
    $switch = new SendingSwitch(new Settings($setting === NULL ? [] : [SendingSwitch::SETTINGS_KEY => $setting]));
    $this->assertSame($source, $switch->getSource());
    $this->assertSame($source !== NULL, $switch->isDisabled());
    if ($source === 'environment') {
      $this->assertStringContainsString(SendingSwitch::ENV_VAR, $switch->message());
    }
    if ($source === 'settings.php') {
      $this->assertStringContainsString("\$settings['sentinel_connector.disabled']", $switch->message());
    }
  }

  /**
   * Values of the off switch.
   *
   * @return array<string, array{mixed, string|null, string|null}>
   *   The settings.php value, the environment value and the expected source.
   */
  public static function switchValues(): array {
    return [
      'not set' => [NULL, NULL, NULL],
      'settings TRUE' => [TRUE, NULL, 'settings.php'],
      'settings FALSE' => [FALSE, NULL, NULL],
      'env 1' => [NULL, '1', 'environment'],
      'env true' => [NULL, 'TRUE', 'environment'],
      'env 0' => [NULL, '0', NULL],
      'env empty' => [NULL, '', NULL],
      'settings FALSE wins over env' => [FALSE, '1', NULL],
      'settings TRUE with env 0' => [TRUE, '0', 'settings.php'],
    ];
  }

  /**
   * The example include file sets what each environment needs.
   *
   * @param array<string, string> $env
   *   The environment variables to set.
   * @param array<string, mixed> $expected
   *   The resulting $settings.
   * @param array<string, mixed> $expectedConfig
   *   The resulting $config.
   */
  #[DataProvider('exampleEnvironments')]
  public function testExampleFile(array $env, array $expected, array $expectedConfig = []): void {
    foreach ($env as $name => $value) {
      putenv($name . '=' . $value);
    }
    $settings = [];
    $config = [];
    (static function () use (&$settings, &$config): void {
      include __DIR__ . '/../../../examples/settings.sentinel_connector.php';
    })();
    $this->assertSame($expected, $settings);
    $this->assertSame($expectedConfig, $config);
  }

  /**
   * Environments the example file knows.
   *
   * @return array<string, array{0: array<string, string>, 1: array<string, mixed>, 2?: array<string, mixed>}>
   *   The environment variables, the resulting $settings and $config.
   */
  public static function exampleEnvironments(): array {
    $off = ['sentinel_connector.disabled' => TRUE];
    return [
      'unset counts as local' => [[], $off],
      'local' => [['SENTINEL_CONNECTOR_ENVIRONMENT' => 'local'], $off],
      'ci' => [['SENTINEL_CONNECTOR_ENVIRONMENT' => 'ci'], $off],
      'production on CI' => [['SENTINEL_CONNECTOR_ENVIRONMENT' => 'production', 'CI' => 'true'], $off],
      'production' => [
        ['SENTINEL_CONNECTOR_ENVIRONMENT' => 'production'],
        ['sentinel_connector.site_uuid' => '00000000-0000-0000-0000-000000000000'],
      ],
      'develop' => [
        ['SENTINEL_CONNECTOR_ENVIRONMENT' => 'develop', 'SENTINEL_CONNECTOR_API_KEY_DEVELOP' => 'sk_develop'],
        [
          'sentinel_connector.site_uuid' => '11111111-1111-1111-1111-111111111111',
          'sentinel_connector.api_key' => 'sk_develop',
        ],
        ['sentinel_connector.settings' => ['report_platform' => TRUE]],
      ],
      'other' => [['SENTINEL_CONNECTOR_ENVIRONMENT' => 'feature-123'], $off],
    ];
  }

  /**
   * The update hook's mapping of saved settings.
   */
  public function testSettingsUpgrade(): void {
    [$data, $custom] = SettingsUpgrade::apply([
      'api_base_url' => 'https://ingest.sentinelconnector.io/',
      'site_uuid' => '22222222-2222-4222-8222-222222222222',
      'site_url' => 'https://example.com',
      'site_name' => 'Example',
      'report_scope' => 'contrib',
      'enabled' => TRUE,
      'report_config_drift' => FALSE,
      'api_key_id' => 'sentinel',
    ]);
    $this->assertNull($custom);
    $this->assertSame([
      'site_uuid' => '22222222-2222-4222-8222-222222222222',
      'report_scope' => 'core_contrib',
      'enabled' => TRUE,
      'report_config_drift' => FALSE,
      'api_key_id' => 'sentinel',
      'report_platform' => TRUE,
    ], $data);

    [$data, $custom] = SettingsUpgrade::apply([
      'api_base_url' => 'https://uat-ingest.sentinelconnector.io',
      'report_scope' => 'contrib_custom',
      'report_platform' => FALSE,
    ]);
    $this->assertSame('https://uat-ingest.sentinelconnector.io', $custom);
    $this->assertSame(['report_scope' => 'all', 'report_platform' => FALSE], $data);

    // Nothing saved: nothing to warn about.
    [$data, $custom] = SettingsUpgrade::apply(['api_base_url' => '']);
    $this->assertNull($custom);
    $this->assertSame(['report_scope' => 'all', 'report_platform' => TRUE], $data);
  }

  /**
   * A config factory whose settings hold the given Site UUID.
   */
  protected function configFactory(string $uuid): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $key === 'site_uuid' ? $uuid : NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

}
