<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\sentinel_connector\ConnectionCheckResult;
use Drupal\sentinel_connector\SentinelClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests how the connection check reads Sentinel's answer.
 *
 * @group sentinel_connector
 */
#[Group('sentinel_connector')]
class ConnectionCheckTest extends TestCase {

  /**
   * The requests sent.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $transactions = [];

  /**
   * The request goes to the check route with the site key and no site data.
   */
  public function testRequest(): void {
    $result = $this->check(new Response(200, [], '{"ok": true, "environment": "uat"}'));
    $this->assertTrue($result->isOk());
    $this->assertSame('uat', $result->environment);

    $request = $this->transactions[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://sentinel.example.com/api/v1/sites/11111111-1111-4111-8111-111111111111/connection-check', (string) $request->getUri());
    $this->assertSame('sk_test', $request->getHeaderLine('X-API-Key'));
    $this->assertSame('{}', (string) $request->getBody());
  }

  /**
   * Each answer and the outcome it gives.
   *
   * @param int $code
   *   The HTTP status.
   * @param string|array<string, mixed> $body
   *   The body, as text or as data sent as JSON.
   * @param string $outcome
   *   The expected outcome.
   * @param string|null $environment
   *   The expected environment.
   */
  #[DataProvider('answers')]
  public function testAnswers(int $code, string|array $body, string $outcome, ?string $environment = NULL): void {
    $result = $this->check(new Response($code, [], is_array($body) ? (string) json_encode($body) : $body));
    $this->assertSame($outcome, $result->outcome);
    $this->assertSame($environment, $result->environment);
  }

  /**
   * Answers of Sentinel and of other servers.
   *
   * @return array<string, array{0: int, 1: string|array<string, mixed>, 2: string, 3?: string}>
   *   The status, the body, the outcome and the environment.
   */
  public static function answers(): array {
    $unknown = ConnectionCheckResult::UNKNOWN_SITE;
    $refused = ConnectionCheckResult::KEY_REFUSED;
    $limited = ConnectionCheckResult::LIMITED;
    $other = ConnectionCheckResult::NOT_SENTINEL;
    return [
      'connected' => [200, ['ok' => TRUE, 'environment' => 'uat'], ConnectionCheckResult::CONNECTED, 'uat'],
      'unknown environment' => [200, ['ok' => TRUE, 'environment' => 'staging<b>'], ConnectionCheckResult::CONNECTED],
      'html page' => [200, '<html>Welcome</html>', $other],
      'json without ok' => [200, ['status' => 'fine'], $other],
      'ok not true' => [200, ['ok' => 'yes'], $other],
      'plain 404' => [404, 'Not Found', $other],
      'fastapi 404 without code' => [404, ['detail' => 'Not Found'], $other],
      'unknown uuid' => [404, ['error_code' => 'site_unavailable', 'environment' => 'uat'], $unknown, 'uat'],
      'uuid mismatch nested' => [400, ['detail' => ['error_code' => 'site_uuid_mismatch']], $unknown],
      'key invalid' => [401, ['error_code' => 'api_key_invalid'], $refused],
      'another site key' => [403, ['error_code' => 'site_mismatch'], $refused],
      'subscription' => [402, ['error_code' => 'subscription_inactive', 'reason' => 'past_due'], $limited],
      'push limit' => [429, ['error_code' => 'push_limit_reached', 'retry_after_seconds' => 60], $limited],
      'subscription on wrong status' => [400, ['error_code' => 'subscription_inactive'], $other],
      'rate limited' => [429, ['error_code' => 'site_rate_limited'], ConnectionCheckResult::FAILED],
      'unknown code' => [500, ['error_code' => 'brand_new'], $other],
    ];
  }

  /**
   * A TLS, DNS or connection failure means no Sentinel API answered.
   */
  public function testTransportFailure(): void {
    $result = $this->check(new ConnectException('cURL error 60', new Request('POST', 'https://sentinel.example.com')));
    $this->assertSame(ConnectionCheckResult::NOT_SENTINEL, $result->outcome);
  }

  /**
   * The refusal carries the server's words for the limited outcomes.
   */
  public function testLimitedKeepsRefusal(): void {
    $result = $this->check(new Response(402, [], '{"error_code": "subscription_inactive", "reason": "past_due", "message": "Pay the invoice."}'));
    $this->assertNotNull($result->refusal);
    $this->assertTrue($result->refusal->isSubscriptionInactive());
    $this->assertSame('past_due', $result->refusal->reason);
  }

  /**
   * Runs one check against the given answer.
   */
  protected function check(Response|\Throwable $answer): ConnectionCheckResult {
    $this->transactions = [];
    $stack = HandlerStack::create(new MockHandler([$answer]));
    $stack->push(Middleware::history($this->transactions));
    return (new SentinelClient(new Client(['handler' => $stack])))
      ->check('https://sentinel.example.com', '11111111-1111-4111-8111-111111111111', 'sk_test');
  }

}
