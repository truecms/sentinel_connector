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
   * New installs push once a day.
   */
  public function testDefaultCronIntervalIsDaily(): void {
    $this->assertSame(86400, $this->config('sentinel_connector.settings')->get('cron_interval'));
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
    $this->assertSame('free', $record['context']['@plan']);
    $this->assertSame(1, $record['context']['@limit']);
    $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $next), $record['context']['@next']);

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
    // Only the real rejection is logged.
    $this->assertCount(1, $this->logRecords);
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
    $this->assertSame('unknown', $this->logRecords[0]['context']['@plan']);
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
    $this->assertSame([], $this->logRecords);
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
    $this->assertSame([], $this->logRecords);
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
