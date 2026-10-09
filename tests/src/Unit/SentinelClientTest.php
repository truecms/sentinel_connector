<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\sentinel_connector\SentinelClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests response typing and safe transport diagnostics.
 *
 * @group sentinel_connector
 */
class SentinelClientTest extends TestCase {

  /**
   * Structured authentication errors remain typed failures.
   */
  public function testStructuredAuthenticationErrors(): void {
    foreach ([401, 403] as $code) {
      $client = $this->client(new Response($code, [], json_encode(['detail' => ['reason' => 'invalid key']])));
      $result = $client->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      $this->assertSame('auth_error', $result->status);
      $this->assertSame($code, $result->httpCode);
      $this->assertStringContainsString('invalid key', $result->message);
    }
  }

  /**
   * Non-JSON proxy failures retain actionable HTTP classifications.
   */
  public function testNonJsonErrorsPreserveHttpClassification(): void {
    foreach ([401, 403, 500, 502, 503] as $code) {
      $result = $this->client(new Response($code, [], '<html>SECRET proxy failure</html>'))
        ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      $this->assertSame($code < 500 ? 'auth_error' : 'server_error', $result->status);
      $this->assertSame($code, $result->httpCode);
      $this->assertStringNotContainsString('SECRET', $result->message);
      $this->assertFalse($result->isOk());
    }
  }

  /**
   * A malformed JSON success must not masquerade as a successful sync.
   */
  public function testMalformedJsonIsRejected(): void {
    $result = $this->client(new Response(200, [], '<html>proxy error</html>'))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('invalid_response', $result->status);
    $this->assertFalse($result->isOk());
  }

  /**
   * A queued response without an actual task ID is not usable acceptance.
   */
  public function testInvalidTaskIdIsRejected(): void {
    $result = $this->client(new Response(202, [], '{"task_id":[]}'))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('invalid_response', $result->status);
  }

  /**
   * The actual server retry interval is shown to administrators.
   */
  public function testRetryAfterIsShown(): void {
    $result = $this->client(new Response(429, ['Retry-After' => '3600']))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('rate_limited', $result->status);
    $this->assertStringContainsString('Retry after 3600 seconds', $result->message);
  }

  /**
   * A push-limit rejection is typed as such, with the server's details.
   */
  public function testPushLimitRejectionIsRecognised(): void {
    $result = $this->client(new Response(429, ['Retry-After' => '51234'], json_encode(self::pushLimitBody())))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('push_limited', $result->status);
    $this->assertTrue($result->isPushLimited());
    $this->assertFalse($result->isOk());
    $this->assertFalse($result->deferred);
    $this->assertSame(429, $result->httpCode);
    $this->assertSame(self::pushLimitBody()['message'], $result->message);
    $this->assertSame('free', $result->plan);
    $this->assertSame(1, $result->limit);
    $this->assertSame(51234, $result->retryAfterSeconds);
    $this->assertSame(gmmktime(3, 15, 0, 10, 10, 2026), $result->nextAllowedAt);
  }

  /**
   * The same body nested under FastAPI's "detail" key is also recognised.
   */
  public function testPushLimitUnderDetailIsRecognised(): void {
    $body = ['plan' => 'paid', 'limit' => 1, 'window_seconds' => 3600] + self::pushLimitBody();
    $result = $this->client(new Response(429, [], json_encode(['detail' => $body])))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertTrue($result->isPushLimited());
    $this->assertSame('paid', $result->plan);
  }

  /**
   * Malformed fields fall back to Retry-After and a generic message.
   *
   * @param array<string, string> $headers
   *   The response headers.
   * @param int|null $seconds
   *   The expected relative wait.
   * @param int|null $nextAllowedAt
   *   The expected absolute time.
   *
   * @dataProvider malformedPushLimits
   */
  public function testMalformedPushLimitFallsBackToRetryAfter(array $headers, ?int $seconds, ?int $nextAllowedAt): void {
    $body = [
      'error_code' => 'push_limit_reached',
      'message' => ['not', 'a', 'string'],
      'plan' => '<script>alert(1)</script>',
      'limit' => 'many',
      'retry_after_seconds' => -5,
      'next_allowed_at' => 'tomorrow',
    ];
    $result = $this->client(new Response(429, $headers, json_encode($body)))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertTrue($result->isPushLimited());
    $this->assertSame(SentinelClient::PUSH_LIMIT_FALLBACK_MESSAGE, $result->message);
    $this->assertNull($result->plan);
    $this->assertNull($result->limit);
    $this->assertSame($seconds, $result->retryAfterSeconds);
    $this->assertSame($nextAllowedAt, $result->nextAllowedAt);
  }

  /**
   * Retry-After variants for a push-limit body with unusable fields.
   *
   * @return array<string, array{array<string, string>, int|null, int|null}>
   *   Headers, expected relative wait and expected absolute time.
   */
  public static function malformedPushLimits(): array {
    return [
      'seconds' => [['Retry-After' => '3600'], 3600, NULL],
      'http date' => [['Retry-After' => 'Sat, 10 Oct 2026 03:15:00 GMT'], NULL, gmmktime(3, 15, 0, 10, 10, 2026)],
      'no header' => [[], NULL, NULL],
      'unusable header' => [['Retry-After' => 'soon'], NULL, NULL],
    ];
  }

  /**
   * Server text is reduced to one bounded line before it is shown or stored.
   */
  public function testPushLimitMessageIsBounded(): void {
    $body = ['message' => "Line one\r\nline two " . str_repeat('x', 600)] + self::pushLimitBody();
    $result = $this->client(new Response(429, [], json_encode($body)))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertStringStartsWith('Line one line two', $result->message);
    $this->assertSame(500, mb_strlen($result->message));
  }

  /**
   * A 429 without the push-limit error code keeps the rate-limit handling.
   *
   * @dataProvider otherRateLimitBodies
   */
  public function testOtherRateLimitsKeepExistingHandling(string $body): void {
    $result = $this->client(new Response(429, ['Retry-After' => '60'], $body))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('rate_limited', $result->status);
    $this->assertFalse($result->isPushLimited());
    $this->assertStringContainsString('Retry after 60 seconds', $result->message);
    $this->assertNull($result->nextAllowedAt);
  }

  /**
   * Bodies of a 429 that is not a plan push limit.
   *
   * @return array<string, array{string}>
   *   Raw response bodies.
   */
  public static function otherRateLimitBodies(): array {
    return [
      'empty' => [''],
      'html' => ['<html>Too many requests</html>'],
      'truncated json' => ['{"error_code": "push_limit_rea'],
      'json scalar' => ['"push_limit_reached"'],
      'no error code' => ['{"detail": "Rate limit exceeded"}'],
      'other error code' => ['{"error_code": "abuse_limit", "message": "Slow down."}'],
      'error code of the wrong type' => ['{"error_code": ["push_limit_reached"]}'],
    ];
  }

  /**
   * An accepted push carries no push-limit details.
   */
  public function testAcceptedPushIsNotPushLimited(): void {
    foreach ([new Response(200, [], '{"message":"ok"}'), new Response(202, [], '{"task_id":"task-1"}')] as $response) {
      $result = $this->client($response)->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      $this->assertTrue($result->isOk());
      $this->assertFalse($result->isPushLimited());
      $this->assertNull($result->nextAllowedAt);
    }
  }

  /**
   * A billing refusal is its own result, with the reason and the message.
   *
   * @dataProvider subscriptionReasons
   */
  public function testSubscriptionInactiveIsRecognised(string $reason): void {
    $body = [
      'error_code' => 'subscription_inactive',
      'reason' => $reason,
      'message' => 'Payment for this organisation is overdue.',
      'detail' => 'Payment for this organisation is overdue.',
    ];
    foreach ([$body, ['detail' => $body]] as $payload) {
      $result = $this->client(new Response(402, [], json_encode($payload)))
        ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      $this->assertSame('subscription_inactive', $result->status);
      $this->assertTrue($result->isSubscriptionInactive());
      $this->assertFalse($result->isPushLimited());
      $this->assertFalse($result->isOk());
      $this->assertSame(402, $result->httpCode);
      $this->assertSame($reason, $result->reason);
      $this->assertSame('Payment for this organisation is overdue.', $result->message);
      $this->assertNull($result->nextAllowedAt);
    }
  }

  /**
   * The reasons Sentinel sends for an inactive subscription.
   *
   * @return array<string, array{string}>
   *   The reason codes.
   */
  public static function subscriptionReasons(): array {
    return ['past_due' => ['past_due'], 'unpaid' => ['unpaid'], 'paused' => ['paused']];
  }

  /**
   * A missing message or an unusable reason leaves them empty, not guessed.
   *
   * @param array<string, mixed> $fields
   *   Body fields besides the error code.
   * @param string|null $reason
   *   The expected reason.
   * @param string $message
   *   The expected message.
   *
   * @dataProvider incompleteSubscriptionBodies
   */
  public function testIncompleteSubscriptionBody(array $fields, ?string $reason, string $message): void {
    $result = $this->client(new Response(402, [], json_encode(['error_code' => 'subscription_inactive'] + $fields)))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertTrue($result->isSubscriptionInactive());
    $this->assertSame($reason, $result->reason);
    $this->assertSame($message, $result->message);
  }

  /**
   * Billing refusals with missing, malformed or oversized fields.
   *
   * @return array<string, array{array<string, mixed>, string|null, string}>
   *   Body fields, expected reason and expected message.
   */
  public static function incompleteSubscriptionBodies(): array {
    return [
      'no message' => [['reason' => 'paused'], 'paused', ''],
      'message of the wrong type' => [['reason' => 'unpaid', 'message' => ['x']], 'unpaid', ''],
      'unknown reason is kept' => [['reason' => 'cancelled', 'message' => 'Ended.'], 'cancelled', 'Ended.'],
      'no reason' => [['message' => 'Ended.'], NULL, 'Ended.'],
      'malformed reason' => [['reason' => '<b>past due</b>'], NULL, ''],
      'message is one bounded line' => [
        ['reason' => 'paused', 'message' => "A\nB" . str_repeat('x', 600)],
        'paused',
        'A B' . str_repeat('x', 497),
      ],
    ];
  }

  /**
   * A 402 that is not the billing refusal keeps the generic handling.
   *
   * @dataProvider otherPaymentRequiredBodies
   */
  public function testOtherPaymentRequiredKeepsExistingHandling(string $body, string $status): void {
    $result = $this->client(new Response(402, [], $body))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame($status, $result->status);
    $this->assertFalse($result->isSubscriptionInactive());
    $this->assertNull($result->reason);
  }

  /**
   * Bodies of a 402 that is not the billing refusal.
   *
   * @return array<string, array{string, string}>
   *   Raw body and the expected status.
   */
  public static function otherPaymentRequiredBodies(): array {
    return [
      'empty' => ['', 'invalid_response'],
      'html' => ['<html>Payment required</html>', 'invalid_response'],
      'no error code' => ['{"detail": "Payment required"}', 'rejected'],
      'other error code' => ['{"error_code": "site_limit_reached", "reason": "paused"}', 'rejected'],
    ];
  }

  /**
   * The error code only counts on a 402.
   */
  public function testSubscriptionCodeOnOtherStatusIsNotRecognised(): void {
    $result = $this->client(new Response(400, [], '{"error_code": "subscription_inactive", "reason": "paused"}'))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('rejected', $result->status);
  }

  /**
   * The push-limit body from the Sentinel API contract.
   *
   * @return array<string, mixed>
   *   The decoded response body.
   */
  private static function pushLimitBody(): array {
    return [
      'error_code' => 'push_limit_reached',
      'message' => 'This site can send data to Sentinel once every 24 hours on the Free plan. Next push accepted after 2026-10-10 03:15 UTC.',
      'plan' => 'free',
      'limit' => 1,
      'window_seconds' => 86400,
      'retry_after_seconds' => 51234,
      'next_allowed_at' => '2026-10-10T03:15:00Z',
    ];
  }

  /**
   * Diagnostics distinguish transport failures without logging secrets or URLs.
   */
  public function testTransportLogsOnlySafeStructuredDiagnostics(): void {
    $exception = new ConnectException('https://user:SECRET@sentinel.example.com/path?token=SECRET', new Request('POST', 'https://sentinel.example.com'), NULL, ['errno' => 7]);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error')->with(
      $this->callback(fn ($message) => !str_contains($message, 'SECRET')),
      $this->callback(fn ($context) => $context['@host'] === 'sentinel.example.com' && $context['@errno'] === 7 && !str_contains(json_encode($context), 'SECRET')),
    );
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([$exception]))]);
    $result = (new SentinelClient($http, $logger))->sync('https://user:SECRET@sentinel.example.com?token=SECRET', 'uuid', 'SECRET', []);
    $this->assertSame('transport_error', $result->status);
    $this->assertStringNotContainsString('SECRET', $result->message);
  }

  /**
   * Builds a client whose actual Guzzle stack returns the given response.
   */
  private function client(Response $response): SentinelClient {
    return new SentinelClient(new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]), new NullLogger());
  }

}
