<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Site\Settings;
use Drupal\sentinel_connector\ApiKeyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests key precedence without disclosing configured key values.
 *
 * @group sentinel_connector
 */
class ApiKeyResolverTest extends TestCase {

  /**
   * Clears the process environment after each test.
   */
  protected function tearDown(): void {
    putenv('SENTINEL_CONNECTOR_API_KEY');
    parent::tearDown();
  }

  /**
   * Builds a resolver with isolated settings and state.
   *
   * @param array<string, mixed> $settings
   *   Isolated settings values.
   * @param array<string, mixed> $state
   *   Isolated state values.
   */
  private function resolver(array $settings, array $state): ApiKeyResolver {
    $settingsObj = new Settings($settings);
    $stateMock = $this->createMock(StateInterface::class);
    $stateMock->method('get')->willReturnCallback(fn($k, $d = NULL) => $state[$k] ?? $d);
    return new ApiKeyResolver($settingsObj, $stateMock);
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

}
