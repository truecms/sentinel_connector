<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\SyncService;
use GuzzleHttp\Client;
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
      ->set('site_token', 'tok')
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
    $stack->push(\GuzzleHttp\Middleware::history($transactions));
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

}
