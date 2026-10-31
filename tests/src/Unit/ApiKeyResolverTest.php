<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Site\Settings;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\sentinel_connector\ApiKeyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests key precedence without disclosing configured key values.
 *
 * Fixtures use mocks as stubs and assert the returned payload or result.
 *
 * @group sentinel_connector
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
#[Group('sentinel_connector')]
class ApiKeyResolverTest extends TestCase {

  /**
   * Clears the process environment after each test.
   */
  protected function tearDown(): void {
    putenv('SENTINEL_CONNECTOR_API_KEY');
    parent::tearDown();
  }

  /**
   * Builds a resolver with isolated settings, state and keys.
   *
   * @param array<string, mixed> $settings
   *   Isolated settings values.
   * @param array<string, mixed> $state
   *   Isolated state values.
   * @param string $keyId
   *   The selected key id.
   * @param array<string, array{string, string}>|null $keys
   *   Keys by id, each its provider and value, or NULL without the Key module.
   */
  private function resolver(array $settings, array $state, string $keyId = '', ?array $keys = NULL): ApiKeyResolver {
    $settingsObj = new Settings($settings);
    $stateMock = $this->createMock(StateInterface::class);
    $stateMock->method('get')->willReturnCallback(fn($k, $d = NULL) => $state[$k] ?? $d);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($k) => $k === 'api_key_id' ? $keyId : NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $repository = NULL;
    if ($keys !== NULL) {
      $repository = $this->createMock(KeyRepositoryInterface::class);
      $repository->method('getKey')->willReturnCallback(function ($id) use ($keys) {
        if (!isset($keys[$id])) {
          return NULL;
        }
        $key = $this->createMock(KeyInterface::class);
        $key->method('get')->willReturnCallback(fn($p) => $p === 'key_provider' ? $keys[$id][0] : NULL);
        $key->method('getKeyValue')->willReturn($keys[$id][1]);
        return $key;
      });
    }
    return new ApiKeyResolver($settingsObj, $stateMock, $configFactory, $repository);
  }

  /**
   * A settings.php key takes precedence over all fallback keys.
   */
  public function testSettingsPhpWins(): void {
    putenv('SENTINEL_CONNECTOR_API_KEY=env_key');
    $r = $this->resolver(['sentinel_connector.api_key' => 'settings_key'], ['sentinel_connector.api_key' => 'state_key']);
    $this->assertSame('settings_key', $r->getKey());
    $this->assertSame('settings.php', $r->getSource());
  }

  /**
   * The environment key takes precedence over state.
   */
  public function testEnvVarSecond(): void {
    putenv('SENTINEL_CONNECTOR_API_KEY=env_key');
    $r = $this->resolver([], ['sentinel_connector.api_key' => 'state_key']);
    $this->assertSame('env_key', $r->getKey());
    $this->assertSame('environment', $r->getSource());
  }

  /**
   * State supplies the key when higher-priority sources are absent.
   */
  public function testStateLast(): void {
    $r = $this->resolver([], ['sentinel_connector.api_key' => 'state_key']);
    $this->assertSame('state_key', $r->getKey());
    $this->assertSame('state', $r->getSource());
  }

  /**
   * An unconfigured resolver returns no key or source.
   */
  public function testNoneConfigured(): void {
    $r = $this->resolver([], []);
    $this->assertNull($r->getKey());
    $this->assertNull($r->getSource());
  }

  /**
   * A selected key comes after settings.php and ahead of the environment.
   */
  public function testKeySecond(): void {
    putenv('SENTINEL_CONNECTOR_API_KEY=env_key');
    $keys = ['sentinel' => ['file', " key_value\n"]];

    $r = $this->resolver([], ['sentinel_connector.api_key' => 'state_key'], 'sentinel', $keys);
    $this->assertSame('key_value', $r->getKey());
    $this->assertSame('key', $r->getSource());
    $this->assertNull($r->getKeyProblem());

    $r = $this->resolver(['sentinel_connector.api_key' => 'settings_key'], [], 'sentinel', $keys);
    $this->assertSame('settings_key', $r->getKey());
    $this->assertSame('settings.php', $r->getSource());
  }

  /**
   * A selected key that cannot be used falls through to the next source.
   *
   * @param string $keyId
   *   The selected key id.
   * @param array<string, array{string, string}>|null $keys
   *   Keys by id, or NULL without the Key module.
   * @param string $problem
   *   The expected problem.
   *
   * @dataProvider unusableKeys
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('unusableKeys')]
  public function testUnusableKeyFallsThrough(string $keyId, ?array $keys, string $problem): void {
    putenv('SENTINEL_CONNECTOR_API_KEY=env_key');
    $r = $this->resolver([], [], $keyId, $keys);
    $this->assertSame($problem, $r->getKeyProblem());
    $this->assertSame('env_key', $r->getKey());
    $this->assertSame('environment', $r->getSource());
  }

  /**
   * Selected keys that cannot be used.
   *
   * @return array<string, array{string, array<string, array{string, string}>|null, string}>
   *   The key id, the keys and the expected problem.
   */
  public static function unusableKeys(): array {
    return [
      'missing' => ['gone', ['other' => ['file', 'value']], 'missing'],
      'config provider' => ['cfg', ['cfg' => ['config', 'value']], 'config_provider'],
      'empty' => ['blank', ['blank' => ['env', " \n"]], 'empty'],
      'no Key module' => ['sentinel', NULL, 'no_module'],
    ];
  }

}
