<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\sentinel_connector\ApiBaseUrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests where the API base URL comes from and how it is checked.
 *
 * @group sentinel_connector
 */
#[Group('sentinel_connector')]
class ApiBaseUrlResolverTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(ApiBaseUrlResolver::ENV_VAR);
    parent::tearDown();
  }

  /**
   * Settings.php wins over the environment, the environment over the default.
   */
  public function testSourcesInOrder(): void {
    $default = new ApiBaseUrlResolver(new Settings([]));
    $this->assertSame(ApiBaseUrlResolver::DEFAULT_URL, $default->getUrl());
    $this->assertSame(ApiBaseUrlResolver::SOURCE_DEFAULT, $default->getSource());
    $this->assertNull($default->getError());

    putenv(ApiBaseUrlResolver::ENV_VAR . '=https://uat-ingest.sentinelconnector.io/');
    $env = new ApiBaseUrlResolver(new Settings([]));
    $this->assertSame('https://uat-ingest.sentinelconnector.io', $env->getUrl());
    $this->assertSame(ApiBaseUrlResolver::SOURCE_ENVIRONMENT, $env->getSource());

    $settings = new ApiBaseUrlResolver(new Settings([ApiBaseUrlResolver::SETTINGS_KEY => 'https://sentinel.example.com']));
    $this->assertSame('https://sentinel.example.com', $settings->getUrl());
    $this->assertSame(ApiBaseUrlResolver::SOURCE_SETTINGS, $settings->getSource());

    // An empty value counts as not set.
    $empty = new ApiBaseUrlResolver(new Settings([ApiBaseUrlResolver::SETTINGS_KEY => '']));
    $this->assertSame(ApiBaseUrlResolver::SOURCE_ENVIRONMENT, $empty->getSource());
  }

  /**
   * An invalid override is refused, never replaced by the default.
   */
  public function testInvalidOverrideIsNotReplaced(): void {
    putenv(ApiBaseUrlResolver::ENV_VAR . '=http://ingest.sentinelconnector.io');
    $resolver = new ApiBaseUrlResolver(new Settings([]));
    $this->assertNull($resolver->getUrl());
    $this->assertSame(ApiBaseUrlResolver::SOURCE_ENVIRONMENT, $resolver->getSource());
    $this->assertStringContainsString('https://', (string) $resolver->getError());

    // A value that is not text is refused too.
    $resolver = new ApiBaseUrlResolver(new Settings([ApiBaseUrlResolver::SETTINGS_KEY => TRUE]));
    $this->assertNull($resolver->getUrl());
    $this->assertSame(ApiBaseUrlResolver::SOURCE_SETTINGS, $resolver->getSource());
  }

  /**
   * A non-production URL saved before 1.4.0 is not replaced by production.
   */
  public function testLegacySavedUrlIsNotReplaced(): void {
    $state = $this->createStub(StateInterface::class);
    $state->method('get')->willReturn(NULL);
    $resolver = new ApiBaseUrlResolver(new Settings([]), $this->configFactory('https://uat-ingest.sentinelconnector.io/'), $state);
    $this->assertNull($resolver->getUrl());
    $this->assertSame('uat-ingest.sentinelconnector.io', $resolver->getLegacyHost());
    $this->assertStringContainsString('before version 1.4.0', (string) $resolver->getError());

    // The production default saved: nothing to keep.
    $resolver = new ApiBaseUrlResolver(new Settings([]), $this->configFactory('https://ingest.sentinelconnector.io'), $state);
    $this->assertSame(ApiBaseUrlResolver::DEFAULT_URL, $resolver->getUrl());

    // After update 10005: the host it recorded.
    $recorded = $this->createStub(StateInterface::class);
    $recorded->method('get')->willReturn('uat-ingest.sentinelconnector.io');
    $resolver = new ApiBaseUrlResolver(new Settings([]), $this->configFactory(NULL), $recorded);
    $this->assertNull($resolver->getUrl());

    // An override for this environment replaces it.
    $resolver = new ApiBaseUrlResolver(new Settings([ApiBaseUrlResolver::SETTINGS_KEY => ApiBaseUrlResolver::DEFAULT_URL]), $this->configFactory(NULL), $recorded);
    $this->assertSame(ApiBaseUrlResolver::DEFAULT_URL, $resolver->getUrl());
    $this->assertNull($resolver->getLegacyHost());
  }

  /**
   * A query or fragment, which may hold a token, is never shown back.
   */
  public function testDisplayValueCutsQuery(): void {
    $resolver = new ApiBaseUrlResolver(new Settings([ApiBaseUrlResolver::SETTINGS_KEY => 'https://sentinel.example.com/?token=secret']));
    $this->assertSame('https://sentinel.example.com/?…', $resolver->getDisplayValue());
    $resolver = new ApiBaseUrlResolver(new Settings([ApiBaseUrlResolver::SETTINGS_KEY => 'https://sentinel.example.com#secret']));
    $this->assertSame('https://sentinel.example.com#…', $resolver->getDisplayValue());
  }

  /**
   * A config factory whose settings hold the given saved base URL.
   */
  protected function configFactory(?string $saved): ConfigFactoryInterface {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $key === 'api_base_url' ? $saved : NULL);
    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

  /**
   * A URL with credentials is never shown back.
   */
  public function testDisplayValueHidesCredentials(): void {
    $resolver = new ApiBaseUrlResolver(new Settings([ApiBaseUrlResolver::SETTINGS_KEY => 'https://user:secret@sentinel.example.com']));
    $this->assertNull($resolver->getUrl());
    $this->assertNull($resolver->getDisplayValue());

    $resolver = new ApiBaseUrlResolver(new Settings([ApiBaseUrlResolver::SETTINGS_KEY => 'https://sentinel.example.com/api/v1/sites/x/modules/sync']));
    $this->assertSame('https://sentinel.example.com/api/v1/sites/x/modules/sync', $resolver->getDisplayValue());
  }

  /**
   * Valid base URLs and their normalised form.
   *
   * @dataProvider validUrls
   */
  #[DataProvider('validUrls')]
  public function testValidUrls(string $value, string $expected): void {
    $this->assertSame([$expected, NULL], ApiBaseUrlResolver::validate($value));
  }

  /**
   * Valid base URLs.
   *
   * @return array<string, array{string, string}>
   *   The value and its normalised form.
   */
  public static function validUrls(): array {
    return [
      'default' => ['https://ingest.sentinelconnector.io', 'https://ingest.sentinelconnector.io'],
      'trailing slash' => ['https://ingest.sentinelconnector.io/', 'https://ingest.sentinelconnector.io'],
      'spaces' => ["  https://uat-ingest.sentinelconnector.io \n", 'https://uat-ingest.sentinelconnector.io'],
      'upper case' => ['HTTPS://Sentinel.Example.COM', 'https://sentinel.example.com'],
      'port' => ['https://sentinel.example.com:8443', 'https://sentinel.example.com:8443'],
      'http localhost' => ['http://localhost:8000', 'http://localhost:8000'],
      'http dot localhost' => ['http://sentinel.localhost/', 'http://sentinel.localhost'],
      'https localhost' => ['https://localhost', 'https://localhost'],
    ];
  }

  /**
   * Invalid base URLs and the words of their message.
   *
   * @dataProvider invalidUrls
   */
  #[DataProvider('invalidUrls')]
  public function testInvalidUrls(string $value, string $message): void {
    [$url, $error] = ApiBaseUrlResolver::validate($value);
    $this->assertNull($url);
    $this->assertStringContainsString($message, (string) $error);
  }

  /**
   * Invalid base URLs.
   *
   * @return array<string, array{string, string}>
   *   The value and a part of its message.
   */
  public static function invalidUrls(): array {
    return [
      'empty' => ['', 'empty'],
      'no scheme' => ['ingest.sentinelconnector.io', 'not a URL'],
      'plain http' => ['http://ingest.sentinelconnector.io', 'must start with https://'],
      'http lookalike' => ['http://localhost.example.com', 'must start with https://'],
      'ftp' => ['ftp://ingest.sentinelconnector.io', 'must start with https://'],
      'sync endpoint' => [
        'https://ingest.sentinelconnector.io/api/v1/sites/3f6c2a9e-71b4-4d0a-9c55-0e8d2b7a41f3/modules/sync',
        'not the sync endpoint',
      ],
      'api prefix' => ['https://ingest.sentinelconnector.io/api', 'not the sync endpoint'],
      'path' => ['https://ingest.sentinelconnector.io/sentinel', 'must not have a path'],
      'query' => ['https://ingest.sentinelconnector.io?x=1', 'query string'],
      'empty query' => ['https://ingest.sentinelconnector.io/?', 'query string'],
      'fragment' => ['https://ingest.sentinelconnector.io#top', 'fragment'],
      'user' => ['https://user@ingest.sentinelconnector.io', 'user name or password'],
      'user and password' => ['https://user:pass@ingest.sentinelconnector.io', 'user name or password'],
      'no host' => ['https://', 'not a URL'],
      'bad host' => ['https://ingest_sentinel!.io', 'valid host'],
    ];
  }

}
