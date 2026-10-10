<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\sentinel_connector\PayloadBuilder;
use Drupal\sentinel_connector\SentinelClient;
use Drupal\sentinel_connector\SyncResult;
use Drupal\sentinel_connector\SyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests how the sync service detects a changed inventory for a deployment.
 *
 * @group sentinel_connector
 */
class SyncServiceFingerprintTest extends TestCase {

  /**
   * The in-memory state values.
   *
   * @var array<string, mixed>
   */
  private array $stateValues = [];

  /**
   * The payload the mocked builder returns; NULL makes the builder throw.
   *
   * @var array<string, mixed>|null
   */
  private ?array $payload = NULL;

  /**
   * The site name in config.
   */
  private string $siteName = 'Example';

  /**
   * The API base URL in config.
   */
  private string $apiBaseUrl = 'https://sentinel.example.com';

  /**
   * The results the mocked client returns, in order.
   *
   * @var \Drupal\sentinel_connector\SyncResult[]
   */
  private array $responses = [];

  /**
   * The current time reported by the time service.
   */
  private int $now = 1791550800;

  /**
   * How many errors were logged.
   */
  private int $errors = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->payload = $this->inventory();
  }

  /**
   * Before any accepted push the inventory counts as changed.
   */
  public function testChangedWhenNothingWasAccepted(): void {
    $this->assertTrue($this->service()->hasInventoryChanged());
  }

  /**
   * An accepted push stores the fingerprint; the same inventory is unchanged.
   *
   * @dataProvider acceptedResults
   */
  public function testUnchangedAfterAcceptedPush(SyncResult $accepted): void {
    $this->responses = [$accepted];
    $service = $this->service();
    $service->sync();

    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->stateValues[SyncService::STATE_LAST_FINGERPRINT]);
    $this->assertFalse($service->hasInventoryChanged());
  }

  /**
   * Both a 200 and a queued 202 count as accepted.
   *
   * @return array<string, array{\Drupal\sentinel_connector\SyncResult}>
   *   The accepted results.
   */
  public static function acceptedResults(): array {
    return [
      'success' => [SyncResult::success(200, 'ok')],
      'queued' => [SyncResult::accepted('task-1')],
    ];
  }

  /**
   * Anything a deployment can change is detected.
   *
   * @dataProvider changes
   */
  public function testDeploymentChangesAreDetected(callable $change): void {
    $this->responses = [SyncResult::success(200, 'ok')];
    $service = $this->service();
    $service->sync();

    $change($this);

    $this->assertTrue($service->hasInventoryChanged());
  }

  /**
   * The changes a deployment can make.
   *
   * @return array<string, array{callable}>
   *   Callbacks that alter the test's inventory.
   */
  public static function changes(): array {
    return [
      'module version' => [fn (self $t) => $t->payload['modules'][0]['version'] = '3.6.1'],
      'module enabled' => [fn (self $t) => $t->payload['modules'][1]['enabled'] = TRUE],
      'module added' => [fn (self $t) => $t->payload['modules'][] = ['machine_name' => 'webform']],
      'module removed' => [fn (self $t) => array_pop($t->payload['modules'])],
      'core version' => [fn (self $t) => $t->payload['drupal_info']['core_version'] = '11.1.1'],
      'php version' => [fn (self $t) => $t->payload['drupal_info']['php_version'] = '8.4.1'],
      'report scope' => [fn (self $t) => $t->payload['inventory_scope'] = 'contrib'],
      'connector version' => [fn (self $t) => $t->payload['connector_version'] = '9.9.9'],
      'site name' => [fn (self $t) => $t->siteName = 'Renamed'],
      'api base url' => [fn (self $t) => $t->apiBaseUrl = 'https://sentinel.example.org'],
    ];
  }

  /**
   * The IP address, list order and a trailing URL slash are not a change.
   */
  public function testIpAddressAndOrderAreIgnored(): void {
    $this->responses = [SyncResult::success(200, 'ok')];
    $service = $this->service();
    $service->sync();

    $this->payload['drupal_info']['ip_address'] = '10.0.0.99';
    $this->apiBaseUrl .= '/';
    $this->payload['modules'] = array_reverse($this->payload['modules']);
    $this->payload['modules'][0] = array_reverse($this->payload['modules'][0], TRUE);

    $this->assertFalse($service->hasInventoryChanged());
  }

  /**
   * A fingerprint is trusted for a day after the push it belongs to.
   */
  public function testOldFingerprintCountsAsChanged(): void {
    $this->responses = [SyncResult::success(200, 'ok')];
    $service = $this->service();
    $service->sync();

    // It expires a margin early, so a daily cron run a little early pushes.
    $this->now += SyncService::FINGERPRINT_MAX_AGE - SyncService::FINGERPRINT_EXPIRY_MARGIN - 1;
    $this->assertFalse($service->hasInventoryChanged());
    $this->now++;
    $this->assertTrue($service->hasInventoryChanged());
  }

  /**
   * A push that was not accepted leaves the stored fingerprint alone.
   *
   * @dataProvider refusedResults
   */
  public function testRefusedPushKeepsFingerprint(SyncResult $refused): void {
    $this->responses = [SyncResult::success(200, 'ok'), $refused];
    $service = $this->service();
    $service->sync();
    $stored = $this->stateValues[SyncService::STATE_LAST_FINGERPRINT];

    $this->payload['modules'][0]['version'] = '3.6.1';
    $service->sync();

    $this->assertSame($stored, $this->stateValues[SyncService::STATE_LAST_FINGERPRINT]);
    $this->assertTrue($service->hasInventoryChanged());
  }

  /**
   * The outcomes that are not an accepted push.
   *
   * @return array<string, array{\Drupal\sentinel_connector\SyncResult}>
   *   The refused results.
   */
  public static function refusedResults(): array {
    return [
      'push limit' => [SyncResult::pushLimited('Once an hour.', NULL, 3600, 'paid', 1)],
      'subscription' => [SyncResult::subscriptionInactive('', 'past_due')],
      'transport' => [SyncResult::failure('transport_error', NULL, 'down')],
      'rejected' => [SyncResult::failure('rejected', 400, 'bad')],
    ];
  }

  /**
   * A push held by a stored limit does not record a fingerprint.
   */
  public function testDeferredPushRecordsNoFingerprint(): void {
    $this->responses = [SyncResult::pushLimited('Once an hour.', NULL, 3600, 'paid', 1)];
    $service = $this->service();
    $service->sync();
    $held = $service->sync();

    $this->assertTrue($held->deferred);
    $this->assertArrayNotHasKey(SyncService::STATE_LAST_FINGERPRINT, $this->stateValues);
    $this->assertTrue($service->hasInventoryChanged());
  }

  /**
   * An inventory that cannot be read counts as changed, so a push is tried.
   */
  public function testUnreadableInventoryCountsAsChanged(): void {
    $this->stateValues[SyncService::STATE_LAST_FINGERPRINT] = str_repeat('a', 64);
    $this->stateValues[SyncService::STATE_LAST_ACCEPTED_TIME] = $this->now;
    $this->payload = NULL;

    $this->assertTrue($this->service()->hasInventoryChanged());
    $this->assertSame(1, $this->errors);
  }

  /**
   * A small inventory in the shape the payload builder returns.
   *
   * @return array<string, mixed>
   *   The payload without the site block.
   */
  private function inventory(): array {
    return [
      'drupal_info' => ['core_version' => '11.1.0', 'php_version' => '8.4.0', 'ip_address' => '10.0.0.1'],
      'modules' => [
        ['machine_name' => 'admin_toolbar', 'version' => '3.6.0', 'enabled' => TRUE],
        ['machine_name' => 'token', 'version' => '1.15.0', 'enabled' => FALSE],
      ],
      'full_sync' => TRUE,
      'inventory_scope' => 'all',
      'connector_version' => '0.3.0',
    ];
  }

  /**
   * Builds the service on in-memory state and a mocked Sentinel client.
   */
  private function service(): SyncService {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn (string $key) => [
      'api_base_url' => $this->apiBaseUrl,
      'site_uuid' => '11111111-1111-4111-8111-111111111111',
      'site_url' => 'https://example.com',
      'site_name' => $this->siteName,
      'report_scope' => 'all',
      'enabled' => TRUE,
    ][$key] ?? NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn (string $key, mixed $default = NULL) => $this->stateValues[$key] ?? $default);
    $state->method('set')->willReturnCallback(function (string $key, mixed $value): void {
      $this->stateValues[$key] = $value;
    });
    $state->method('delete')->willReturnCallback(function (string $key): void {
      unset($this->stateValues[$key]);
    });
    $state->method('deleteMultiple')->willReturnCallback(function (array $keys): void {
      foreach ($keys as $key) {
        unset($this->stateValues[$key]);
      }
    });

    $builder = $this->createMock(PayloadBuilder::class);
    $builder->method('build')->willReturnCallback(fn (): array => $this->payload ?? throw new \RuntimeException('unreadable'));
    $resolver = $this->createMock(ApiKeyResolver::class);
    $resolver->method('getKey')->willReturn('sk_test');

    $client = $this->createMock(SentinelClient::class);
    $client->method('sync')->willReturnCallback(function (): SyncResult {
      $response = array_shift($this->responses);
      $this->assertNotNull($response, 'Sentinel was called more often than expected.');
      return $response;
    });

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn (): int => $this->now);
    $time->method('getCurrentTime')->willReturnCallback(fn (): int => $this->now);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('error')->willReturnCallback(function (): void {
      $this->errors++;
    });

    return new SyncService($configFactory, $state, $builder, $resolver, $client, $time, $logger);
  }

}
