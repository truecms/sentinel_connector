<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\Form\SettingsForm;
use Drupal\sentinel_connector\PushLimitFormatter;
use Drupal\sentinel_connector\SentinelClient;
use Drupal\sentinel_connector\SyncService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Kernel tests for Sentinel push-limit responses.
 *
 * @group sentinel_connector
 */
class PushLimitTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'sentinel_connector'];

  /**
   * The requests sent to the mocked Sentinel API.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $transactions = [];

  /**
   * Records written to the Drupal log during the test.
   *
   * @var array<int, array{level: mixed, message: string, context: array<string, mixed>}>
   */
  public array $logRecords = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['sentinel_connector']);
    $this->config('system.date')->set('timezone.default', 'Australia/Sydney')->save();
    $this->config('sentinel_connector.settings')
      ->set('api_base_url', 'https://sentinel.example.com')
      ->set('site_uuid', '11111111-1111-4111-8111-111111111111')
      ->set('site_url', 'https://example.com')
      ->set('site_name', 'Example')
      ->set('enabled', TRUE)
      ->save();
    \Drupal::state()->set('sentinel_connector.api_key', 'sk_test');

    $test = $this;
    $logger = new class($test) implements LoggerInterface {
      use RfcLoggerTrait;

      public function __construct(private PushLimitTest $test) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->test->logRecords[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);
  }

  /**
   * There is no interval setting, and frequent cron pushes once an hour.
   */
  public function testFrequentCronPushesOnce(): void {
    $this->assertNull($this->config('sentinel_connector.settings')->get('cron_interval'));
    // The client is mocked first: building the form creates the sync service.
    $this->mockResponses([new Response(200, [], json_encode(['message' => 'ok']))]);
    $form = SettingsForm::create($this->container)->buildForm([], new FormState());
    $this->assertArrayNotHasKey('cron_interval', $form);
    $this->container->get('module_handler')->loadAll();
    sentinel_connector_cron();
    // Later runs inside the hour would fail on the empty mock queue.
    sentinel_connector_cron();
    sentinel_connector_cron();

    $this->assertCount(1, $this->transactions);
    $state = \Drupal::state();
    $this->assertSame('success', $state->get('sentinel_connector.last_result'));
    $this->assertNotEmpty($state->get(SyncService::STATE_LAST_ACCEPTED_TIME));
    $this->assertNotEmpty($state->get(SyncService::STATE_CONFIGURED_TIME));
    $sync = \Drupal::service(SyncService::class);
    $attempt = $state->get('sentinel_connector.last_attempt_time');
    $this->assertFalse($sync->cronIsDue($attempt + 3599));
    $this->assertTrue($sync->cronIsDue($attempt + 3600));
  }

  /**
   * The update removes the stored interval and seeds the new state.
   */
  public function testUpdateRemovesIntervalSetting(): void {
    // Written to storage directly: the schema no longer knows the key.
    $storage = $this->container->get('config.storage');
    $storage->write('sentinel_connector.settings', ['cron_interval' => 21600] + $storage->read('sentinel_connector.settings'));
    $this->container->get('config.factory')->reset('sentinel_connector.settings');
    $this->assertSame(21600, $this->config('sentinel_connector.settings')->get('cron_interval'));
    $state = \Drupal::state();
    $state->set('sentinel_connector.last_sync_time', 1234567890);
    $before = $this->config('sentinel_connector.settings')->getRawData();

    $this->container->get('module_handler')->loadInclude('sentinel_connector', 'install');
    $message = sentinel_connector_update_10001();

    $this->assertStringContainsString('push interval', $message);
    unset($before['cron_interval']);
    $this->assertSame($before, $this->config('sentinel_connector.settings')->getRawData());
    $this->assertArrayNotHasKey('cron_interval', $storage->read('sentinel_connector.settings'));
    $this->assertSame(1234567890, $state->get(SyncService::STATE_LAST_ACCEPTED_TIME));
    $this->assertNotEmpty($state->get(SyncService::STATE_CONFIGURED_TIME));

    // A second run changes nothing and keeps a newer accepted time.
    $state->set(SyncService::STATE_LAST_ACCEPTED_TIME, 1234567999);
    sentinel_connector_update_10001();
    $this->assertSame(1234567999, $state->get(SyncService::STATE_LAST_ACCEPTED_TIME));
  }

  /**
   * A rejected cron push is logged, shows no message and is not repeated.
   */
  public function testRejectedCronPushIsLoggedAndNotRepeated(): void {
    $next = $this->futureMinute(7200);
    $this->mockResponses([$this->pushLimitResponse($next)]);
    $this->container->get('module_handler')->loadAll();

    sentinel_connector_cron();
    // The second run would fail on the empty mock queue if it sent a request.
    sentinel_connector_cron();

    $this->assertCount(1, $this->transactions);
    $state = \Drupal::state();
    $this->assertSame('push_limited', $state->get('sentinel_connector.last_result'));
    $this->assertSame($next, $state->get(SyncService::STATE_NEXT_ALLOWED_AT));
    $this->assertNull($state->get('sentinel_connector.last_sync_time'));
    $this->assertSame([], \Drupal::messenger()->all());

    $this->assertCount(1, $this->logRecords);
    $record = $this->logRecords[0];
    $this->assertSame(RfcLogLevel::NOTICE, $record['level']);
    $this->assertSame('sentinel_connector', $record['context']['channel']);
    $this->assertSame('cron', $record['context']['@trigger']);
    $this->assertSame('push_limited', $record['context']['@outcome']);
    $this->assertSame(429, $record['context']['@code']);
    $this->assertSame('plan free; limit 1; next push allowed at ' . gmdate('Y-m-d\TH:i:s\Z', $next), $record['context']['@details']);

    $sync = \Drupal::service(SyncService::class);
    $this->assertFalse($sync->cronIsDue($next - 1));
    $this->assertTrue($sync->cronIsDue($next));
  }

  /**
   * A rejected manual push warns with the server message and the site time.
   */
  public function testManualPushWarning(): void {
    $next = $this->futureMinute(7200);
    $this->mockResponses([$this->pushLimitResponse($next, 'Once every 24 hours on the <b>Free</b> plan.')]);
    $sync = \Drupal::service(SyncService::class);
    $formatter = \Drupal::service(PushLimitFormatter::class);
    $siteTime = (new \DateTimeImmutable('@' . $next))->setTimezone(new \DateTimeZone('Australia/Sydney'))->format('j M Y H:i T');

    $result = $sync->sync();
    $this->assertTrue($result->isPushLimited());
    $this->assertFalse($result->isOk());
    $this->assertSame($siteTime, $formatter->formatTime($next));
    $warning = (string) $formatter->warning($result);
    $this->assertStringContainsString('Sentinel did not accept this push.', $warning);
    $this->assertStringContainsString('The next push will be accepted after ' . $siteTime . '.', $warning);
    // Server text is escaped, never rendered as markup.
    $this->assertStringContainsString('&lt;b&gt;Free&lt;/b&gt;', $warning);
    $this->assertStringNotContainsString('<b>', $warning);

    // A second manual push shows the stored message without a request.
    $held = $sync->sync();
    $this->assertCount(1, $this->transactions);
    $this->assertTrue($held->deferred);
    $warning = (string) $formatter->warning($held);
    $this->assertStringContainsString('No push was sent', $warning);
    $this->assertStringContainsString('&lt;b&gt;Free&lt;/b&gt;', $warning);
    $this->assertStringContainsString($siteTime, $warning);
    // The rejection is a notice. The held push is a debug entry.
    $this->assertCount(2, $this->logRecords);
    $this->assertSame(RfcLogLevel::NOTICE, $this->logRecords[0]['level']);
    $this->assertSame('form', $this->logRecords[0]['context']['@trigger']);
    $this->assertSame(RfcLogLevel::DEBUG, $this->logRecords[1]['level']);
    $this->assertSame('push_deferred', $this->logRecords[1]['context']['@outcome']);
  }

  /**
   * A push-limit body with unusable fields still gives a clear message.
   */
  public function testMalformedBodyFallsBackToRetryAfter(): void {
    $this->mockResponses([
      new Response(429, ['Retry-After' => '120'], json_encode([
        'error_code' => 'push_limit_reached',
        'message' => NULL,
        'next_allowed_at' => 'not a date',
      ])),
    ]);
    $before = time();

    $result = \Drupal::service(SyncService::class)->sync();

    $this->assertTrue($result->isPushLimited());
    $this->assertSame(SentinelClient::PUSH_LIMIT_FALLBACK_MESSAGE, $result->message);
    $this->assertGreaterThanOrEqual($before + 120, $result->nextAllowedAt);
    $this->assertLessThanOrEqual(time() + 120, $result->nextAllowedAt);
    $this->assertSame($result->nextAllowedAt, \Drupal::state()->get(SyncService::STATE_NEXT_ALLOWED_AT));
    $warning = (string) \Drupal::service(PushLimitFormatter::class)->warning($result);
    $this->assertStringContainsString(SentinelClient::PUSH_LIMIT_FALLBACK_MESSAGE, $warning);
    $this->assertStringContainsString('The next push will be accepted after', $warning);
    $this->assertStringStartsWith('plan unknown; limit unknown; next push allowed at 2', $this->logRecords[0]['context']['@details']);
  }

  /**
   * A 429 with no body is still a rate-limit failure, not a push limit.
   */
  public function testRateLimitWithoutBodyKeepsExistingHandling(): void {
    $this->mockResponses([new Response(429, ['Retry-After' => '120'], '')]);

    $result = \Drupal::service(SyncService::class)->sync();

    $this->assertSame('rate_limited', $result->status);
    $this->assertStringContainsString('Retry after 120 seconds', $result->message);
    $this->assertNull(\Drupal::state()->get(SyncService::STATE_NEXT_ALLOWED_AT));
    $this->assertCount(1, $this->logRecords);
    $this->assertSame(RfcLogLevel::NOTICE, $this->logRecords[0]['level']);
    $this->assertSame('rate_limited', $this->logRecords[0]['context']['@outcome']);
  }

  /**
   * An accepted push after the limit has passed clears the stored limit.
   */
  public function testAcceptedPushClearsStoredLimit(): void {
    $state = \Drupal::state();
    $state->set(SyncService::STATE_NEXT_ALLOWED_AT, time() - 60);
    $state->set(SyncService::STATE_PUSH_LIMIT_MESSAGE, 'Old limit.');
    $this->mockResponses([new Response(200, [], json_encode(['message' => 'ok']))]);

    $result = \Drupal::service(SyncService::class)->sync();

    $this->assertSame('success', $result->status);
    $this->assertCount(1, $this->transactions);
    $this->assertNull($state->get(SyncService::STATE_NEXT_ALLOWED_AT));
    $this->assertNull($state->get(SyncService::STATE_PUSH_LIMIT_MESSAGE));
    $this->assertSame('success', $state->get('sentinel_connector.last_result'));
    $this->assertCount(1, $this->logRecords);
    $this->assertSame(RfcLogLevel::INFO, $this->logRecords[0]['level']);
    $this->assertSame(200, $this->logRecords[0]['context']['@code']);
  }

  /**
   * The settings form shows the last result and the next allowed time.
   */
  public function testSettingsFormShowsPushLimit(): void {
    // The client is mocked first: building the form creates the sync service.
    $next = $this->futureMinute(7200);
    $this->mockResponses([$this->pushLimitResponse($next, 'Once every 24 hours on the <b>Free</b> plan.')]);
    $form = SettingsForm::create($this->container)->buildForm([], new FormState());
    $this->assertArrayNotHasKey('push_limit_status', $form);

    \Drupal::service(SyncService::class)->sync();

    $form = SettingsForm::create($this->container)->buildForm([], new FormState());
    $status = (string) $form['last_sync_status']['#markup'];
    $this->assertStringContainsString('Result: push_limited.', $status);
    $limit = (string) $form['push_limit_status']['#markup'];
    $siteTime = \Drupal::service(PushLimitFormatter::class)->formatTime($next);
    $this->assertStringContainsString('Next push accepted after ' . $siteTime . '.', $limit);
    $this->assertStringContainsString('&lt;b&gt;Free&lt;/b&gt;', $limit);
    $this->assertStringNotContainsString('<b>', $limit);
  }

  /**
   * Cron pushes only when the inventory changed since the accepted push.
   */
  public function testCronPushesOnlyOnChange(): void {
    $ok = fn (): Response => new Response(200, [], json_encode(['message' => 'ok']));
    $this->mockResponses([$ok(), $ok(), $ok(), $ok()]);
    $this->container->get('module_handler')->loadAll();
    $state = \Drupal::state();
    $sync = \Drupal::service(SyncService::class);

    sentinel_connector_cron();
    $this->assertCount(1, $this->transactions);

    // An hour later nothing changed: the check is recorded, nothing is sent.
    $state->set('sentinel_connector.last_attempt_time', time() - 7200);
    sentinel_connector_cron();
    $this->assertCount(1, $this->transactions);
    $this->assertNotNull($sync->getLastUnchangedTime());
    // The check counts for the hourly floor, on cron's own clock: a run
    // exactly an hour later is due.
    $request = \Drupal::time()->getRequestTime();
    $this->assertSame($request, $sync->getLastUnchangedTime());
    $this->assertFalse($sync->cronIsDue($request + 3599));
    $this->assertTrue($sync->cronIsDue($request + 3600));

    // A changed inventory is pushed on the next due run.
    $state->set(SyncService::STATE_LAST_UNCHANGED_TIME, time() - 7200);
    $this->config('sentinel_connector.settings')->set('site_name', 'Renamed')->save();
    sentinel_connector_cron();
    $this->assertCount(2, $this->transactions);

    // Unchanged for a day: the inventory is sent again.
    $state->set('sentinel_connector.last_attempt_time', time() - 7200);
    $state->set(SyncService::STATE_LAST_ACCEPTED_TIME, time() - SyncService::FINGERPRINT_MAX_AGE);
    sentinel_connector_cron();
    $this->assertCount(3, $this->transactions);

    // A manual push is sent whether or not anything changed.
    $this->assertTrue($sync->sync()->isOk());
    $this->assertCount(4, $this->transactions);
  }

  /**
   * A billing refusal on cron is logged as a warning and holds cron only.
   */
  public function testSubscriptionRefusalOnCron(): void {
    $refusal = json_encode([
      'error_code' => 'subscription_inactive',
      'reason' => 'past_due',
      'message' => 'Payment for <b>Example Org</b> is overdue.',
      'detail' => 'Payment for <b>Example Org</b> is overdue.',
    ]);
    $this->mockResponses([
      new Response(402, [], $refusal),
      new Response(402, [], $refusal),
      new Response(200, [], json_encode(['message' => 'ok'])),
    ]);
    $this->container->get('module_handler')->loadAll();
    $state = \Drupal::state();
    $before = time();

    sentinel_connector_cron();

    $this->assertCount(1, $this->transactions);
    $this->assertSame('subscription_inactive', $state->get('sentinel_connector.last_result'));
    $this->assertSame('past_due', $state->get(SyncService::STATE_SUBSCRIPTION_REASON));
    $hold = $state->get(SyncService::STATE_SUBSCRIPTION_HOLD_UNTIL);
    $this->assertGreaterThanOrEqual($before + 86400, $hold);
    $this->assertSame([], \Drupal::messenger()->all());
    $this->assertCount(1, $this->logRecords);
    $this->assertSame(RfcLogLevel::WARNING, $this->logRecords[0]['level']);
    $this->assertSame('sentinel_connector', $this->logRecords[0]['context']['channel']);
    $this->assertSame('cron', $this->logRecords[0]['context']['@trigger']);
    $this->assertSame('reason past_due', $this->logRecords[0]['context']['@details']);

    // Cron is held even though the hourly floor has passed.
    $state->set('sentinel_connector.last_attempt_time', $before - 7200);
    $sync = \Drupal::service(SyncService::class);
    $this->assertFalse($sync->cronIsDue(time()));
    sentinel_connector_cron();
    $this->assertCount(1, $this->transactions);
    $this->assertTrue($sync->cronIsDue($hold));

    // The settings form names the reason and escapes the server text.
    $form = SettingsForm::create($this->container)->buildForm([], new FormState());
    $this->assertStringContainsString('Result: subscription_inactive.', (string) $form['last_sync_status']['#markup']);
    $status = (string) $form['subscription_status']['#markup'];
    $this->assertStringContainsString('Subscription payment overdue.', $status);
    $this->assertStringContainsString('Payment for &lt;b&gt;Example Org&lt;/b&gt; is overdue.', $status);
    $this->assertStringNotContainsString('<b>', $status);
    $formatter = \Drupal::service(PushLimitFormatter::class);
    $this->assertStringContainsString('Cron will try again after ' . $formatter->formatTime($hold) . '.', $status);

    // A manual push is not held: it reaches Sentinel and is refused again.
    $result = $sync->sync();
    $this->assertCount(2, $this->transactions);
    $this->assertTrue($result->isSubscriptionInactive());
    $error = (string) $formatter->subscriptionError($result->reason, $result->message);
    $this->assertSame('Sentinel did not accept this push. Payment for &lt;b&gt;Example Org&lt;/b&gt; is overdue.', $error);

    // An accepted push clears the hold and the status line.
    $this->assertTrue($sync->sync()->isOk());
    $this->assertCount(3, $this->transactions);
    $this->assertNull($state->get(SyncService::STATE_SUBSCRIPTION_HOLD_UNTIL));
    $this->assertNull($state->get(SyncService::STATE_SUBSCRIPTION_REASON));
    $form = SettingsForm::create($this->container)->buildForm([], new FormState());
    $this->assertArrayNotHasKey('subscription_status', $form);
  }

  /**
   * Without a server message each reason has its own text.
   *
   * @dataProvider subscriptionFallbacks
   */
  public function testSubscriptionFallbackMessages(?string $reason, string $text, string $label): void {
    $body = ['error_code' => 'subscription_inactive'] + ($reason === NULL ? [] : ['reason' => $reason]);
    $this->mockResponses([new Response(402, [], json_encode($body))]);

    $result = \Drupal::service(SyncService::class)->sync();

    $this->assertTrue($result->isSubscriptionInactive());
    $formatter = \Drupal::service(PushLimitFormatter::class);
    $this->assertStringContainsString($text, (string) $formatter->subscriptionError($result->reason, $result->message));
    $form = SettingsForm::create($this->container)->buildForm([], new FormState());
    $status = (string) $form['subscription_status']['#markup'];
    $this->assertStringContainsString('Subscription ' . $label . '.', $status);
    $this->assertStringContainsString($text, $status);
  }

  /**
   * Reasons, the fallback text and the plain-words label for each.
   *
   * @return array<string, array{string|null, string, string}>
   *   Reason, expected text and expected label.
   */
  public static function subscriptionFallbacks(): array {
    return [
      'past_due' => ['past_due', 'a payment for the subscription is overdue', 'payment overdue'],
      'unpaid' => ['unpaid', 'the subscription is unpaid', 'unpaid'],
      'paused' => ['paused', 'the subscription is paused', 'paused'],
      'unknown reason' => ['cancelled', 'the subscription is not active', 'not active'],
      'no reason' => [NULL, 'the subscription is not active', 'not active'],
    ];
  }

  /**
   * A 402 without the billing error code is still a generic rejection.
   */
  public function testOtherPaymentRequiredKeepsExistingHandling(): void {
    $this->mockResponses([new Response(402, [], json_encode(['detail' => 'Payment required']))]);

    $result = \Drupal::service(SyncService::class)->sync();

    $this->assertSame('rejected', $result->status);
    $this->assertNull(\Drupal::state()->get(SyncService::STATE_SUBSCRIPTION_HOLD_UNTIL));
    $form = SettingsForm::create($this->container)->buildForm([], new FormState());
    $this->assertArrayNotHasKey('subscription_status', $form);
  }

  /**
   * Replaces the HTTP client with one that returns the given responses.
   *
   * @param \GuzzleHttp\Psr7\Response[] $responses
   *   The responses, in order.
   */
  private function mockResponses(array $responses): void {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->transactions));
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * Builds the push-limit response from the Sentinel API contract.
   *
   * @param int $next
   *   Unix timestamp after which the next push is accepted.
   * @param string $message
   *   The server's message.
   */
  private function pushLimitResponse(int $next, string $message = 'This site can send data to Sentinel once every 24 hours on the Free plan.'): Response {
    return new Response(429, ['Retry-After' => (string) ($next - time())], json_encode([
      'error_code' => 'push_limit_reached',
      'message' => $message,
      'plan' => 'free',
      'limit' => 1,
      'window_seconds' => 86400,
      'retry_after_seconds' => $next - time(),
      'next_allowed_at' => gmdate('Y-m-d\TH:i:s\Z', $next),
    ]));
  }

  /**
   * A whole-minute timestamp at least the given number of seconds ahead.
   */
  private function futureMinute(int $seconds): int {
    return (int) (ceil((time() + $seconds) / 60) * 60);
  }

}
