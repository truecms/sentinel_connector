<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use GuzzleHttp\Middleware;
use Drupal\Core\Site\Settings;
use Drupal\Core\Config\ConfigEvents;
use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\SyncService;
use Drupal\sentinel_connector\PayloadBuilder;
use Drupal\Core\State\StateInterface;
use Psr\Log\NullLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Kernel tests for SyncService using a mocked Guzzle HTTP client.
 *
 * The http_client service is swapped in the container BEFORE the first
 * SyncService resolution so Drupal's lazy-instantiation picks up the mock.
 *
 * @group sentinel_connector
 */
class SyncServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['sentinel_connector'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('sentinel_connector.settings')
      ->set('api_base_url', 'https://sentinel.example.com')
      ->set('site_uuid', '11111111-1111-4111-8111-111111111111')
      ->set('site_url', 'https://example.com')
      ->set('site_name', 'Example')
      ->set('report_scope', 'all')
      ->set('enabled', TRUE)
      ->save();
    \Drupal::state()->set('sentinel_connector.api_key', 'sk_test');
  }

  /**
   * Tests a 200 response records success state and sends correct request.
   */
  public function testSuccessfulSyncRecordsState(): void {
    $transactions = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], json_encode(['message' => 'ok', 'modules_processed' => 1])),
    ]));
    $stack->push(Middleware::history($transactions));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $sync = \Drupal::service(SyncService::class);
    $result = $sync->sync();

    $this->assertTrue($result->isOk());
    $this->assertSame('success', $result->status);

    $request = $transactions[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame(
      'https://sentinel.example.com/api/v1/sites/11111111-1111-4111-8111-111111111111/modules/sync',
      (string) $request->getUri()
    );
    $this->assertSame('sk_test', $request->getHeaderLine('X-API-Key'));
    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertTrue($body['full_sync']);
    $this->assertSame('11111111-1111-4111-8111-111111111111', $body['site']['uuid']);
    $this->assertArrayNotHasKey('token', $body['site']);
    $this->assertNotEmpty($body['modules']);
    $this->assertSame('success', \Drupal::state()->get('sentinel_connector.last_result'));
  }

  /**
   * Tests a 400 validation rejection records the rejected state.
   */
  public function testValidationRejectionRecordsState(): void {
    $stack = HandlerStack::create(new MockHandler([
      new Response(400, [], json_encode(['detail' => ['error' => 'Module validation failed']])),
    ]));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $sync = \Drupal::service(SyncService::class);

    $result = $sync->sync();

    $this->assertFalse($result->isOk());
    $this->assertSame('rejected', $result->status);
    $this->assertSame(400, $result->httpCode);
    $this->assertSame('rejected', \Drupal::state()->get('sentinel_connector.last_result'));
  }

  /**
   * Tests a 429 rate-limit response is surfaced correctly.
   */
  public function testRateLimited(): void {
    $stack = HandlerStack::create(new MockHandler([new Response(429, [], '')]));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $sync = \Drupal::service(SyncService::class);

    $result = $sync->sync();

    $this->assertSame('rate_limited', $result->status);
    $this->assertSame(429, $result->httpCode);
  }

  /**
   * A real Guzzle connection exception is recorded without aborting cron.
   */
  public function testConnectionFailurePreservesSuccessAndThrottlesCron(): void {
    $state = \Drupal::state();
    $state->set('sentinel_connector.last_sync_time', 123);
    $state->set('sentinel_connector.last_result', 'success');
    $stack = HandlerStack::create(new MockHandler([
      new ConnectException('cURL error 7', new Request('POST', 'https://sentinel.example.com')),
    ]));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $sync = \Drupal::service(SyncService::class);
    $result = $sync->sync();
    $this->assertSame('transport_error', $result->status);
    $this->assertSame('transport_error', $state->get('sentinel_connector.last_result'));
    $this->assertSame(123, $state->get('sentinel_connector.last_sync_time'));
    $this->assertNotEmpty($state->get('sentinel_connector.last_attempt_time'));
    $this->assertFalse($sync->cronIsDue(\Drupal::time()->getRequestTime()));
  }

  /**
   * Queued work is recorded as accepted, never as a successful sync.
   */
  public function testQueuedSyncDoesNotRecordSuccess(): void {
    $stack = HandlerStack::create(new MockHandler([
      new Response(202, [], json_encode(['task_id' => 'task-123'])),
    ]));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $result = \Drupal::service(SyncService::class)->sync();
    $this->assertSame('accepted', $result->status);
    $this->assertSame('task-123', \Drupal::state()->get('sentinel_connector.last_task_id'));
    $this->assertNull(\Drupal::state()->get('sentinel_connector.last_sync_time'));
    $this->assertNotEmpty(\Drupal::state()->get('sentinel_connector.last_attempt_time'));
  }

  /**
   * Real local refusal is recorded and Drupal cron completes successfully.
   */
  public function testRealConnectionRefusalDoesNotAbortCron(): void {
    $this->config('sentinel_connector.settings')->set('api_base_url', 'http://127.0.0.1:9')->save();
    $this->container->set('http_client', new Client(['connect_timeout' => 1]));
    \Drupal::state()->set('sentinel_connector.last_sync_time', 123);
    $this->assertTrue(\Drupal::service('cron')->run());
    $this->assertSame('transport_error', \Drupal::state()->get('sentinel_connector.last_result'));
    $this->assertSame(123, \Drupal::state()->get('sentinel_connector.last_sync_time'));
    $this->assertNotEmpty(\Drupal::state()->get('sentinel_connector.last_attempt_time'));
    $this->assertFalse(\Drupal::service(SyncService::class)->cronIsDue(\Drupal::time()->getRequestTime()));
  }

  /**
   * Payload TypeErrors are contained, persisted and throttle later cron runs.
   */
  public function testPayloadTypeErrorIsRecorded(): void {
    $builder = $this->createMock(PayloadBuilder::class);
    $builder->method('build')->willThrowException(new \TypeError('secret-bearing diagnostic'));
    $this->container->set(PayloadBuilder::class, $builder);
    $result = \Drupal::service(SyncService::class)->sync();
    $this->assertSame('internal_error', $result->status);
    $this->assertStringNotContainsString('secret-bearing', $result->message);
    $this->assertSame('internal_error', \Drupal::state()->get('sentinel_connector.last_result'));
    $this->assertNotEmpty(\Drupal::state()->get('sentinel_connector.last_attempt_time'));
  }

  /**
   * Invalid UTF-8 cannot escape the shared sync boundary during JSON encoding.
   */
  public function testInvalidPayloadEncodingIsRecorded(): void {
    $builder = $this->createMock(PayloadBuilder::class);
    $builder->method('build')->willReturn(['modules' => [['display_name' => "\xB1\x31"]]]);
    $this->container->set(PayloadBuilder::class, $builder);
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{}')]))]));
    $result = \Drupal::service(SyncService::class)->sync();
    $this->assertSame('internal_error', $result->status);
    $this->assertSame('internal_error', \Drupal::state()->get('sentinel_connector.last_result'));
  }

  /**
   * Imported malformed URI configuration is a recorded failure.
   */
  public function testMalformedUriIsRecorded(): void {
    $this->config('sentinel_connector.settings')->set('api_base_url', 'http://[broken')->save();
    $this->container->set('http_client', new Client());
    $result = \Drupal::service(SyncService::class)->sync();
    $this->assertSame('internal_error', $result->status);
    $this->assertSame('internal_error', \Drupal::state()->get('sentinel_connector.last_result'));
  }

  /**
   * Persistence failures return a truthful state error rather than throwing.
   */
  public function testStateFailureIsContained(): void {
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{}')]))]));
    $state = $this->createMock(StateInterface::class);
    $state->method('set')->willThrowException(new \RuntimeException('state unavailable'));
    $sync = new SyncService(
      $this->container->get('config.factory'), $state,
      $this->container->get(PayloadBuilder::class),
      $this->container->get('Drupal\sentinel_connector\ApiKeyResolver'),
      $this->container->get('Drupal\sentinel_connector\SentinelClient'),
      $this->container->get('datetime.time'), new NullLogger(),
    );
    $this->assertSame('state_error', $sync->sync()->status);
  }

  /**
   * A due-check Throwable is recorded without aborting the cron hook.
   */
  public function testCronHookContainsStorageErrors(): void {
    $sync = $this->createMock(SyncService::class);
    $sync->method('cronIsDue')->willThrowException(new \TypeError('storage error'));
    $this->container->set(SyncService::class, $sync);
    $this->container->get('module_handler')->loadAll();
    sentinel_connector_cron();
    $this->assertSame('internal_error', \Drupal::state()->get('sentinel_connector.last_result'));
  }

  /**
   * Higher-priority credentials stop the fixture before writes or sync.
   *
   * @dataProvider fixtureKeySources
   */
  public function testFixtureRejectsKeyOverrides(string $source): void {
    $settings = Settings::getAll();
    $original = $this->config('sentinel_connector.settings')->getRawData();
    $state = $this->createMock(StateInterface::class);
    $state->expects($this->never())->method('set');
    $state->expects($this->never())->method('delete');
    $sync = $this->createMock(SyncService::class);
    $sync->expects($this->never())->method('sync');
    $this->container->set(SyncService::class, $sync);
    $this->container->set('state', $state);
    $saves = 0;
    $this->container->get('event_dispatcher')->addListener(ConfigEvents::SAVE, static function () use (&$saves): void {
      $saves++;
    });
    try {
      new Settings($source === 'settings.php' ? $settings + [ApiKeyResolver::SETTINGS_KEY => 'existing-settings-key'] : $settings);
      $this->container->set(ApiKeyResolver::class, new ApiKeyResolver(Settings::getInstance(), $state));
      $this->runFixture([
        ApiKeyResolver::ENV_VAR => $source === 'environment' ? 'existing-env-key' : '',
      ]);
      $this->fail('The fixture must reject higher-priority credentials.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('override', $e->getMessage());
    }
    finally {
      new Settings($settings);
    }
    $this->assertSame(0, $saves);
    $this->assertSame($original, $this->config('sentinel_connector.settings')->getRawData());
  }

  /**
   * Credential sources that take priority over the fixture state key.
   *
   * @return array<int, array{string}>
   *   The settings and environment sources.
   */
  public static function fixtureKeySources(): array {
    return [['settings.php'], ['environment']];
  }

  /**
   * The fixture sends its own key and restores configuration and sync state.
   *
   * @dataProvider fixtureResponses
   */
  public function testFixtureRestoresState(int $httpCode): void {
    $original = $this->config('sentinel_connector.settings')->getRawData();
    $state = \Drupal::state();
    $state->set('sentinel_connector.last_sync_time', 123);
    $transactions = [];
    $stack = HandlerStack::create(new MockHandler([new Response($httpCode, [], '{}')]));
    $stack->push(Middleware::history($transactions));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    ob_start();
    try {
      $this->runFixture([ApiKeyResolver::ENV_VAR => '']);
      $this->assertSame(200, $httpCode);
    }
    catch (\RuntimeException $e) {
      $this->assertSame(400, $httpCode);
      $this->assertStringContainsString('rejected', $e->getMessage());
    }
    finally {
      ob_end_clean();
    }
    $this->assertCount(1, $transactions);
    $this->assertSame('fixture-key', $transactions[0]['request']->getHeaderLine('X-API-Key'));
    $this->assertSame('fixture.example.com', $transactions[0]['request']->getUri()->getHost());
    $this->assertSame($original, $this->config('sentinel_connector.settings')->getRawData());
    $this->assertSame('sk_test', $state->get('sentinel_connector.api_key'));
    $this->assertSame(123, $state->get('sentinel_connector.last_sync_time'));
    foreach (['last_attempt_time', 'last_result', 'last_message', 'last_task_id'] as $key) {
      $this->assertNull($state->get('sentinel_connector.' . $key));
    }
  }

  /**
   * Successful and failed responses both require restoration.
   *
   * @return array<int, array{int}>
   *   HTTP response codes.
   */
  public static function fixtureResponses(): array {
    return [[200], [400]];
  }

  /**
   * Runs the real fixture with synthetic environment values, restoring them.
   *
   * @param array<string, string> $overrides
   *   Environment values to override for this fixture run.
   */
  private function runFixture(array $overrides): void {
    $environment = $overrides + [
      'SENTINEL_E2E_ALLOW' => '1',
      'SENTINEL_E2E_API_URL' => 'https://fixture.example.com',
      'SENTINEL_E2E_SITE_UUID' => '22222222-2222-4222-8222-222222222222',
      'SENTINEL_E2E_SITE_URL' => 'https://fixture-site.example.com',
      'SENTINEL_E2E_SITE_NAME' => 'Fixture site',
      'SENTINEL_E2E_API_KEY' => 'fixture-key',
    ];
    $original = [];
    foreach ($environment as $name => $value) {
      $original[$name] = getenv($name);
      putenv($name . '=' . $value);
    }
    try {
      // Isolate the script's local variables from this helper's cleanup data.
      (static function (): void {
        require __DIR__ . '/../../fixtures/connector_e2e.php';
      })();
    }
    finally {
      foreach ($original as $name => $value) {
        putenv($value === FALSE ? $name : $name . '=' . $value);
      }
    }
  }

}
