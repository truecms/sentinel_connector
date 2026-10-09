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
 * Tests how the sync service stores and honours a push limit.
 *
 * @group sentinel_connector
 */
class SyncServicePushLimitTest extends TestCase {

  /**
   * The fixed "now" of the test: 2026-10-09T13:00:00Z.
   */
  private const NOW = 1791550800;

  /**
   * The in-memory state values.
   *
   * @var array<string, mixed>
   */
  private array $stateValues = [];

  /**
   * The current time reported by the time service.
   */
  private int $now = self::NOW;

  /**
   * The results the mocked client returns, in order.
   *
   * @var \Drupal\sentinel_connector\SyncResult[]
   */
  private array $responses = [];

  /**
   * How many times Sentinel was called.
   */
  private int $calls = 0;

  /**
   * The notices written to the logger.
   *
   * @var array<int, array{string, array<string, mixed>}>
   */
  private array $notices = [];

  /**
   * The warnings written to the logger.
   *
   * @var array<int, array{string, array<string, mixed>}>
   */
  private array $warnings = [];

  /**
   * A rejection stores the next allowed time and is logged as a notice.
   */
  public function testRejectionIsStoredAndLogged(): void {
    $next = self::NOW + 51234;
    $this->responses = [SyncResult::pushLimited('Once every 24 hours on the Free plan.', $next, 51234, 'free', 1)];

    $result = $this->service()->sync();

    $this->assertTrue($result->isPushLimited());
    $this->assertFalse($result->deferred);
    $this->assertSame($next, $result->nextAllowedAt);
    $this->assertSame($next, $this->stateValues[SyncService::STATE_NEXT_ALLOWED_AT]);
    $this->assertSame('Once every 24 hours on the Free plan.', $this->stateValues[SyncService::STATE_PUSH_LIMIT_MESSAGE]);
    $this->assertSame('push_limited', $this->stateValues['sentinel_connector.last_result']);
    $this->assertSame(self::NOW, $this->stateValues['sentinel_connector.last_attempt_time']);
    $this->assertArrayNotHasKey('sentinel_connector.last_sync_time', $this->stateValues);
    $this->assertCount(1, $this->notices);
    $this->assertSame([
      '@plan' => 'free',
      '@limit' => 1,
      '@next' => gmdate('Y-m-d\TH:i:s\Z', $next),
    ], $this->notices[0][1]);
  }

  /**
   * Nothing is sent before the next allowed time; pushes resume after it.
   */
  public function testNoPushIsSentUntilNextAllowedTime(): void {
    $next = self::NOW + 3600;
    $this->responses = [
      SyncResult::pushLimited('Once an hour on paid plans.', $next, 3600, 'paid', 1),
      SyncResult::success(200, 'ok'),
    ];
    $service = $this->service();
    $service->sync();
    $this->assertSame(1, $this->calls);

    // One second before the limit ends: cron skips and a manual push is held.
    $this->now = $next - 1;
    $this->assertFalse($service->cronIsDue($this->now));
    $held = $service->sync();
    $this->assertSame(1, $this->calls);
    $this->assertTrue($held->isPushLimited());
    $this->assertTrue($held->deferred);
    $this->assertNull($held->httpCode);
    $this->assertSame('Once an hour on paid plans.', $held->message);
    $this->assertSame($next, $held->nextAllowedAt);
    // A held push is not an attempt and is not logged again.
    $this->assertSame(self::NOW, $this->stateValues['sentinel_connector.last_attempt_time']);
    $this->assertCount(1, $this->notices);

    // Once the time has passed cron is due and a manual push goes through.
    $this->now = $next;
    $this->assertTrue($service->cronIsDue($this->now));
    $accepted = $service->sync();
    $this->assertSame(2, $this->calls);
    $this->assertTrue($accepted->isOk());
    $this->assertArrayNotHasKey(SyncService::STATE_NEXT_ALLOWED_AT, $this->stateValues);
    $this->assertArrayNotHasKey(SyncService::STATE_PUSH_LIMIT_MESSAGE, $this->stateValues);
    $this->assertNull($service->deferredResult());
    $this->assertSame($this->now, $this->stateValues[SyncService::STATE_LAST_ACCEPTED_TIME]);
    // The hourly floor applies again after an accepted push.
    $this->assertFalse($service->cronIsDue($this->now + SyncService::MIN_PUSH_INTERVAL - 1));
    $this->assertTrue($service->cronIsDue($this->now + SyncService::MIN_PUSH_INTERVAL));
  }

  /**
   * The next allowed time is worked out on this site's clock and capped.
   *
   * @param int|null $absoluteOffset
   *   The server's absolute time as an offset from now, or NULL for none.
   * @param int|null $seconds
   *   The server's relative wait.
   * @param int|null $expectedOffset
   *   The expected stored time as an offset from now, or NULL for none.
   *
   * @dataProvider nextAllowedTimes
   */
  public function testNextAllowedTimeResolution(?int $absoluteOffset, ?int $seconds, ?int $expectedOffset): void {
    $absolute = $absoluteOffset === NULL ? NULL : self::NOW + $absoluteOffset;
    $this->responses = [SyncResult::pushLimited('Limit.', $absolute, $seconds)];

    $result = $this->service()->sync();

    $expected = $expectedOffset === NULL ? NULL : self::NOW + $expectedOffset;
    $this->assertSame($expected, $result->nextAllowedAt);
    $this->assertSame($expected, $this->stateValues[SyncService::STATE_NEXT_ALLOWED_AT] ?? NULL);
    $this->assertSame($expected === NULL ? 'unknown' : gmdate('Y-m-d\TH:i:s\Z', $expected), $this->notices[0][1]['@next']);
    $this->assertSame('unknown', $this->notices[0][1]['@plan']);
    $this->assertSame('unknown', $this->notices[0][1]['@limit']);
  }

  /**
   * Server times and the time the service is expected to store.
   *
   * @return array<string, array{int|null, int|null, int|null}>
   *   Absolute offset, relative wait and expected offset.
   */
  public static function nextAllowedTimes(): array {
    return [
      'absolute time wins' => [7200, 3600, 7200],
      'relative wait only' => [NULL, 3600, 3600],
      'absolute time in the past uses the wait' => [-60, 3600, 3600],
      'absolute time in the past, no wait' => [-60, NULL, NULL],
      'no time at all' => [NULL, NULL, NULL],
      'absolute time beyond the cap' => [86400 * 365, NULL, SyncService::MAX_PUSH_DEFERRAL],
      'wait beyond the cap' => [NULL, 86400 * 365, SyncService::MAX_PUSH_DEFERRAL],
    ];
  }

  /**
   * Without a usable time the hourly floor still throttles cron.
   */
  public function testRejectionWithoutTimeFallsBackToFloor(): void {
    $this->responses = [SyncResult::pushLimited('Limit.'), SyncResult::pushLimited('Limit.')];
    $service = $this->service();
    $service->sync();

    $this->assertNull($service->deferredResult());
    $this->assertFalse($service->cronIsDue(self::NOW + SyncService::MIN_PUSH_INTERVAL - 1));
    $this->assertTrue($service->cronIsDue(self::NOW + SyncService::MIN_PUSH_INTERVAL));
    // A manual push is not held when no time is stored.
    $service->sync();
    $this->assertSame(2, $this->calls);
  }

  /**
   * A failure after the limit has passed clears the stored limit.
   */
  public function testOtherOutcomeClearsStoredLimit(): void {
    $this->stateValues[SyncService::STATE_NEXT_ALLOWED_AT] = self::NOW - 10;
    $this->stateValues[SyncService::STATE_PUSH_LIMIT_MESSAGE] = 'Old limit.';
    $this->responses = [SyncResult::failure('server_error', 503, 'Retry later.')];
    $service = $this->service();

    $this->assertSame('server_error', $service->sync()->status);
    $this->assertArrayNotHasKey(SyncService::STATE_NEXT_ALLOWED_AT, $this->stateValues);
    $this->assertSame([], $this->notices);
    $this->assertSame([], $this->warnings);
    $this->assertFalse($service->cronIsDue(self::NOW + 60));
  }

  /**
   * Disabled cron stays off even when a stored limit has passed.
   */
  public function testDisabledCronIsNeverDue(): void {
    $this->stateValues[SyncService::STATE_NEXT_ALLOWED_AT] = self::NOW - 10;
    $this->assertFalse($this->service(FALSE)->cronIsDue(self::NOW));
  }

  /**
   * Cron every five minutes sends at most one push an hour.
   *
   * @param string $outcome
   *   What Sentinel answers to every push.
   * @param int $expectedPushes
   *   Requests expected over three hours of five-minute cron runs.
   *
   * @dataProvider frequentCronOutcomes
   */
  public function testFrequentCronSendsOnePushAnHour(string $outcome, int $expectedPushes): void {
    $service = $this->service();
    $sent = [];
    for ($offset = 0; $offset < 3 * 3600; $offset += 300) {
      $this->now = self::NOW + $offset;
      $this->responses = [
        match ($outcome) {
          'accepted' => SyncResult::success(200, 'ok'),
          'queued' => SyncResult::accepted('task-1'),
          'failed' => SyncResult::failure('server_error', 503, 'Retry later.'),
          // A limit that ends within the hour must not bring a second push.
          default => SyncResult::pushLimited('Limit.', $this->now + 30, 30, 'paid', 1),
        },
      ];
      if ($service->cronIsDue($this->now)) {
        $service->sync();
        $sent[] = $offset;
      }
    }
    $this->assertSame($expectedPushes, $this->calls);
    $this->assertSame([0, 3600, 7200], $sent);
  }

  /**
   * Outcomes that all leave the hourly floor in force.
   *
   * @return array<string, array{string, int}>
   *   The outcome and the expected number of requests.
   */
  public static function frequentCronOutcomes(): array {
    return [
      'accepted' => ['accepted', 3],
      'queued' => ['queued', 3],
      'failed' => ['failed', 3],
      'refused by a short plan limit' => ['limited', 3],
    ];
  }

  /**
   * A manual push is not bound by the hourly floor.
   */
  public function testManualPushBypassesFloor(): void {
    $this->responses = [SyncResult::success(200, 'ok'), SyncResult::success(200, 'ok')];
    $service = $this->service();
    $service->sync();
    $this->now = self::NOW + 60;

    $this->assertFalse($service->cronIsDue($this->now));
    $this->assertTrue($service->sync()->isOk());
    $this->assertSame(2, $this->calls);
  }

  /**
   * The time the configuration became complete is recorded once.
   */
  public function testConfiguredTimeIsRecordedOnce(): void {
    $this->responses = [SyncResult::failure('server_error', 503, 'Retry later.')];
    $service = $this->service();
    $this->assertTrue($service->isConfigured());
    $service->markConfigured();
    $this->now = self::NOW + 500;
    $service->markConfigured();
    $service->sync();
    $this->assertSame(self::NOW, $this->stateValues[SyncService::STATE_CONFIGURED_TIME]);
    $this->assertArrayNotHasKey(SyncService::STATE_LAST_ACCEPTED_TIME, $this->stateValues);
  }

  /**
   * A billing refusal holds cron for a day and is logged as a warning.
   */
  public function testSubscriptionRefusalHoldsCron(): void {
    $this->responses = [SyncResult::subscriptionInactive('Payment is overdue.', 'past_due')];
    $service = $this->service();

    $result = $service->sync();

    $this->assertTrue($result->isSubscriptionInactive());
    $this->assertSame(self::NOW + SyncService::SUBSCRIPTION_HOLD, $this->stateValues[SyncService::STATE_SUBSCRIPTION_HOLD_UNTIL]);
    $this->assertSame('past_due', $this->stateValues[SyncService::STATE_SUBSCRIPTION_REASON]);
    $this->assertSame('subscription_inactive', $this->stateValues['sentinel_connector.last_result']);
    $this->assertSame('Payment is overdue.', $this->stateValues['sentinel_connector.last_message']);
    $this->assertArrayNotHasKey(SyncService::STATE_NEXT_ALLOWED_AT, $this->stateValues);
    $this->assertSame([['Sentinel refused the push: subscription inactive, reason @reason.', ['@reason' => 'past_due']]], $this->warnings);
    $this->assertSame([], $this->notices);
    // The hourly floor has passed, but the hold has not.
    $this->assertFalse($service->cronIsDue(self::NOW + 3600));
    $this->assertFalse($service->cronIsDue(self::NOW + SyncService::SUBSCRIPTION_HOLD - 1));
    $this->assertTrue($service->cronIsDue(self::NOW + SyncService::SUBSCRIPTION_HOLD));
  }

  /**
   * A manual push is never held, and an accepted push ends the hold.
   */
  public function testManualPushIsNotHeldAndAcceptedPushClearsHold(): void {
    $this->responses = [
      SyncResult::subscriptionInactive('', 'unpaid'),
      SyncResult::subscriptionInactive('', NULL),
      SyncResult::failure('server_error', 503, 'Retry later.'),
      SyncResult::success(200, 'ok'),
    ];
    $service = $this->service();
    $service->sync();

    // A second push an hour later still reaches Sentinel and renews the hold.
    $this->now = self::NOW + 3600;
    $this->assertNull($service->deferredResult());
    $second = $service->sync();
    $this->assertSame(2, $this->calls);
    $this->assertTrue($second->isSubscriptionInactive());
    $this->assertFalse($second->deferred);
    $this->assertSame($this->now + SyncService::SUBSCRIPTION_HOLD, $this->stateValues[SyncService::STATE_SUBSCRIPTION_HOLD_UNTIL]);
    $this->assertSame('', $this->stateValues[SyncService::STATE_SUBSCRIPTION_REASON]);
    $this->assertSame('unknown', $this->warnings[1][1]['@reason']);

    // A failure that is not about billing keeps the hold.
    $hold = $service->getSubscriptionHoldUntil();
    $this->now += 60;
    $this->assertSame('server_error', $service->sync()->status);
    $this->assertSame($hold, $service->getSubscriptionHoldUntil());
    $this->assertArrayNotHasKey(SyncService::STATE_SUBSCRIPTION_REASON, $this->stateValues);
    $this->assertFalse($service->cronIsDue($this->now + 3600));

    // An accepted push clears it; the hourly floor applies again.
    $this->now += 60;
    $this->assertTrue($service->sync()->isOk());
    $this->assertSame(4, $this->calls);
    $this->assertArrayNotHasKey(SyncService::STATE_SUBSCRIPTION_HOLD_UNTIL, $this->stateValues);
    $this->assertNull($service->getSubscriptionHoldUntil());
    $this->assertFalse($service->cronIsDue($this->now + SyncService::MIN_PUSH_INTERVAL - 1));
    $this->assertTrue($service->cronIsDue($this->now + SyncService::MIN_PUSH_INTERVAL));
  }

  /**
   * Builds the service around in-memory state, a fixed clock and a fake client.
   */
  private function service(bool $enabled = TRUE): SyncService {
    $settings = [
      'api_base_url' => 'https://sentinel.example.com',
      'site_uuid' => '11111111-1111-4111-8111-111111111111',
      'site_url' => 'https://example.com',
      'site_name' => 'Example',
      'report_scope' => 'all',
      'enabled' => $enabled,
    ];
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
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
    $builder->method('build')->willReturn(['modules' => []]);
    $resolver = $this->createMock(ApiKeyResolver::class);
    $resolver->method('getKey')->willReturn('sk_test');

    $client = $this->createMock(SentinelClient::class);
    $client->method('sync')->willReturnCallback(function (): SyncResult {
      $this->calls++;
      $response = array_shift($this->responses);
      $this->assertNotNull($response, 'Sentinel was called more often than expected.');
      return $response;
    });

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn (): int => $this->now);
    $time->method('getCurrentTime')->willReturnCallback(fn (): int => $this->now);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('notice')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
      $this->notices[] = [(string) $message, $context];
    });
    $logger->expects($this->never())->method('error');
    $logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
      $this->warnings[] = [(string) $message, $context];
    });

    return new SyncService($configFactory, $state, $builder, $resolver, $client, $time, $logger);
  }

}
