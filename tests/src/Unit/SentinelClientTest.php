<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\sentinel_connector\SentinelClient;
use Drupal\sentinel_connector\SyncResult;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests response typing and safe transport diagnostics.
 *
 * Fixtures use mocks as stubs and assert the returned payload or result.
 *
 * @group sentinel_connector
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
#[Group('sentinel_connector')]
class SentinelClientTest extends TestCase {

  /**
   * Structured authentication errors remain typed failures.
   */
  public function testStructuredAuthenticationErrors(): void {
    foreach ([401, 403] as $code) {
      $detail = ['code' => 'signature_invalid', 'message' => 'The request signature is not valid.'];
      $client = $this->client(new Response($code, [], json_encode(['detail' => $detail])));
      $result = $client->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      $this->assertSame($code === 401 ? 'signature_refused' : 'auth_error', $result->status);
      $this->assertSame($code, $result->httpCode);
      $this->assertSame('The request signature is not valid.', $result->message);
      $this->assertSame(['code' => 'signature_invalid'], $result->diagnostics);
    }
  }

  /**
   * Non-JSON proxy failures retain actionable HTTP classifications.
   */
  public function testNonJsonErrorsPreserveHttpClassification(): void {
    foreach ([401, 403, 500, 502, 503] as $code) {
      $result = $this->client(new Response($code, [], '<html>SECRET proxy failure</html>'))
        ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      $this->assertSame(match (TRUE) {
        $code < 500 => 'auth_error',
        $code === 503 => 'unavailable',
        default => 'server_error',
      }, $result->status);
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
  #[DataProvider('malformedPushLimits')]
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
  #[DataProvider('otherRateLimitBodies')]
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
  #[DataProvider('subscriptionReasons')]
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
  #[DataProvider('incompleteSubscriptionBodies')]
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
  #[DataProvider('otherPaymentRequiredBodies')]
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
   * Transport diagnostics are kept on the result without secrets or URLs.
   */
  public function testTransportDiagnosticsAreSafe(): void {
    $exception = new ContextConnectException('https://user:SECRET@sentinel.example.com/path?token=SECRET', new Request('POST', 'https://sentinel.example.com'));
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([$exception]))]);
    $result = (new SentinelClient($http))->sync('https://user:SECRET@sentinel.example.com?token=SECRET', 'uuid', 'SECRET', []);
    $this->assertSame('transport_error', $result->status);
    $this->assertNull($result->httpCode);
    $this->assertStringNotContainsString('SECRET', $result->message);
    $this->assertSame([
      'class' => ContextConnectException::class,
      'host' => 'sentinel.example.com',
      'curl errno' => 7,
    ], $result->diagnostics);
  }

  /**
   * Transfer stats retain numeric cURL errors without leaking handler details.
   *
   * @dataProvider handlerErrors
   */
  #[DataProvider('handlerErrors')]
  public function testTransportStatsAreSafe(mixed $handlerError, int $expected): void {
    $http = new Client([
      'handler' => HandlerStack::create(new MockHandler([
        static function ($request, array $options) use ($handlerError): ConnectException {
          $options['on_stats'](new TransferStats($request, NULL, 0.0, $handlerError));
          return new ConnectException('SECRET connection detail', $request);
        },
      ])),
    ]);
    $result = (new SentinelClient($http))->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('transport_error', $result->status);
    $this->assertSame([
      'class' => ConnectException::class,
      'host' => 'sentinel.example.com',
      'curl errno' => $expected,
    ], $result->diagnostics);
    $this->assertStringNotContainsString('SECRET', $result->message);
  }

  /**
   * Transfer handlers may provide numeric errors or opaque diagnostic data.
   *
   * @return array<string, array{mixed, int}>
   *   Handler error data and the safe diagnostic code.
   */
  public static function handlerErrors(): array {
    return [
      'curl error' => [7, 7],
      'no error data' => [NULL, 0],
      'text is private' => ['SECRET', 0],
      'context is private' => [['url' => 'https://SECRET'], 0],
    ];
  }

  /**
   * Every error status Sentinel sends has its own outcome and message.
   *
   * @dataProvider refusals
   */
  #[DataProvider('refusals')]
  public function testRefusalsAreMapped(int $code, string $body, string $status, string $message): void {
    $result = $this->client(new Response($code, [], $body))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame($status, $result->status);
    $this->assertSame($code, $result->httpCode);
    $this->assertSame($message, $result->message);
    $this->assertFalse($result->isOk());
    // A structured detail is never encoded into the message.
    $this->assertStringNotContainsString('{', $result->message);
    $this->assertStringNotContainsString('SECRET', $result->message);
  }

  /**
   * Error responses with string, object, list and non-JSON bodies.
   *
   * @return array<string, array{int, string, string, string}>
   *   HTTP status, raw body, expected status and expected message.
   */
  public static function refusals(): array {
    $check = '. Check the Site UUID against the site registered in Sentinel.';
    $modules = fn (int $count): array => array_map(fn (int $i): array => [
      'module_name' => 'bad_' . $i,
      'version' => '1.0',
      'reason' => 'Invalid module name',
      'result' => 'invalid_name',
    ], range(1, $count));
    $validation = fn (int $count): string => (string) json_encode([
      'detail' => [
        'error' => 'Module validation failed',
        'message' => 'All module names and versions must be valid',
        'invalid_modules' => $modules($count),
      ],
    ]);
    return [
      'site URL mismatch' => [400, '{"detail": "Site URL mismatch"}', 'site_mismatch', 'Site URL mismatch' . $check],
      'site UUID mismatch' => [400, '{"detail": "Site UUID mismatch"}', 'site_mismatch', 'Site UUID mismatch' . $check],
      'two invalid modules' => [
        400, $validation(2), 'validation_failed',
        'All module names and versions must be valid. Rejected modules (2): bad_1 (invalid_name, version "1.0"), bad_2 (invalid_name, version "1.0").',
      ],
      'many invalid modules' => [
        400, $validation(8), 'validation_failed',
        'All module names and versions must be valid. Rejected modules (8): ' . implode(', ', array_map(fn (int $i): string => 'bad_' . $i . ' (invalid_name, version "1.0")', range(1, 5))) . ' and 3 more.',
      ],
      'validation without modules' => [
        400, '{"detail": {"error": "Module validation failed"}}', 'validation_failed', 'Module validation failed',
      ],
      'other 400' => [
        400, '{"detail": "Site-module association already exists"}', 'rejected', 'Site-module association already exists',
      ],
      'html 400' => [
        400, '<html>SECRET</html>', 'invalid_response', 'Sentinel returned an invalid JSON response. Check the API base URL and retry.',
      ],
      'site not found' => [
        404, '{"detail": "Site not found"}', 'not_found', 'Site not found. Check the API base URL and site UUID.',
      ],
      'html 404' => [
        404, '<html>SECRET</html>', 'not_found', 'Sentinel did not find this site. Check the API base URL and site UUID.',
      ],
      'conflict' => [
        409, '{"detail": "Reported catalog entry is unavailable"}', 'conflict', 'Reported catalog entry is unavailable',
      ],
      'html 409' => [
        409, '<html>SECRET</html>', 'conflict', 'Sentinel could not store the inventory because of a conflict.',
      ],
      'invalid payload list' => [
        422,
        '{"detail": [{"loc": ["body", "modules", 0, "version"], "type": "missing", "msg": "Field required"}, {"loc": ["body"], "type": "x", "msg": "Other"}]}',
        'invalid_payload',
        'Sentinel could not read the inventory: body.modules.0.version: Field required (and 1 more)',
      ],
      'invalid payload string' => [
        422, '{"detail": "Invalid JSON inventory payload"}', 'invalid_payload', 'Invalid JSON inventory payload',
      ],
      'invalid payload object' => [
        422, '{"detail": {"unexpected": "SECRET"}}', 'invalid_payload', 'Sentinel could not read the inventory.',
      ],
      'unavailable' => [
        503, '{"detail": "Inventory queue is unavailable"}', 'unavailable', 'Inventory queue is unavailable',
      ],
      'html 503' => [503, '<html>SECRET</html>', 'unavailable', 'Sentinel is temporarily unavailable. Retry later.'],
      'server error object' => [
        500, '{"detail": {"trace": "SECRET"}}', 'server_error', 'Sentinel server returned HTTP 500. Retry later.',
      ],
      'signature refused' => [
        401, '{"detail": {"code": "signature_expired", "message": "The request signature has expired."}}', 'signature_refused', 'The request signature has expired.',
      ],
      'missing permission' => [
        403, '{"detail": "Missing permission: site:sync"}', 'auth_error', 'Missing permission: site:sync',
      ],
      'unknown status' => [418, '{"detail": ["a", "b"]}', 'rejected', 'Sentinel rejected the sync: a (and 1 more)'],
    ];
  }

  /**
   * An abuse rate limit shows the server's own words and the wait.
   */
  public function testAbuseRateLimitUsesServerDetail(): void {
    $result = $this->client(new Response(429, ['Retry-After' => '60'], '{"detail": "Rate limit exceeded: 100 per 1 hour"}'))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('rate_limited', $result->status);
    $this->assertSame('Rate limit exceeded: 100 per 1 hour. Retry after 60 seconds.', $result->message);

    $bare = $this->client(new Response(429))->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('Sentinel rate limit reached.', $bare->message);
  }

  /**
   * Server text is reduced to one bounded line, and a task ID is validated.
   */
  public function testServerTextIsCleaned(): void {
    $result = $this->client(new Response(409, [], (string) json_encode(['detail' => "line one\r\nline two" . str_repeat('x', 600)])))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertStringStartsWith('line one line two', $result->message);
    $this->assertSame(500, mb_strlen($result->message));

    $bidi = $this->client(new Response(409, [], (string) json_encode(['detail' => "a\u{202E}b\u{2028}c\u{0085}d"])))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('a b c d', $bidi->message);

    $queued = $this->client(new Response(202, [], (string) json_encode(['task_id' => "abc\ninjected"])))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('invalid_response', $queued->status);
  }

  /**
   * A validation refusal keeps every rejected entry, cleaned.
   *
   * The message names the first few and how many more; the result holds all
   * of them for Drush.
   */
  public function testEveryRejectedEntryIsKept(): void {
    $modules = array_map(fn (int $i): array => [
      'module_name' => 'mod_' . $i,
      'version' => "1.0\u{202E}" . str_repeat('v', 80),
      'result' => 'invalid_version',
      'reason' => 'Unreadable version',
    ], range(1, 7));
    $modules[] = ['module_name' => 'Bad Name', 'version' => '2.0.0', 'result' => "invalid_name\n"];
    $body = [
      'error_code' => 'module_validation_failed',
      'message' => 'Refused.',
      'detail' => ['invalid_modules' => $modules],
    ];
    $result = $this->client(new Response(400, [], (string) json_encode($body)))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);

    $this->assertSame('validation_failed', $result->status);
    $this->assertCount(8, $result->modules);
    $this->assertSame([
      'module_name' => 'mod_1',
      'version' => '1.0 ' . str_repeat('v', 46),
      'result' => 'invalid_version',
    ], $result->modules[0]);
    // A result that is not a plain token is dropped, not shown.
    $this->assertSame(['module_name' => 'Bad Name', 'version' => '2.0.0', 'result' => NULL], $result->modules[7]);
    // The list stops within the message limit and says how many more there
    // are.
    $this->assertStringStartsWith('Refused. Rejected modules (8): mod_1 (invalid_version, version "1.0 vvv', $result->message);
    $this->assertMatchesRegularExpression('/ and [1-7] more\.\z/', $result->message);
    $this->assertLessThanOrEqual(500, mb_strlen($result->message));

    // A response from an older server, without a code, is listed the same.
    $older = (string) json_encode(['detail' => ['error' => 'Module validation failed', 'invalid_modules' => $modules]]);
    $older = $this->client(new Response(400, [], $older))->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertCount(8, $older->modules);

    // A long message from an older server is shortened, so the list keeps
    // its end.
    $legacy = ['error' => 'Module validation failed', 'message' => str_repeat('w', 300), 'invalid_modules' => $modules];
    $legacy = $this->client(new Response(400, [], (string) json_encode(['detail' => $legacy])))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertLessThanOrEqual(500, mb_strlen($legacy->message));
    $this->assertStringStartsWith('www', $legacy->message);
    $this->assertMatchesRegularExpression('/ and [1-7] more\.\z/', $legacy->message);
  }

  /**
   * Unreadable versions of an accepted push are kept for a warning.
   */
  public function testUnresolvedVersionsAreKept(): void {
    $unresolved = [
      ['module_name' => 'foo', 'version' => 'dev-main', 'result' => 'unresolved_version'],
      ['module_name' => "bar\n", 'version' => "1.0 <b>\u{202E}", 'result' => 'unresolved_version'],
    ];
    $ok = (string) json_encode(['message' => 'Synced.', 'unresolved_versions' => $unresolved]);
    $ok = $this->client(new Response(200, [], $ok))->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertTrue($ok->isOk());
    $this->assertSame([
      ['module_name' => 'foo', 'version' => 'dev-main', 'result' => NULL],
      ['module_name' => 'bar', 'version' => "1.0 \u{2039}b\u{203A}", 'result' => NULL],
    ], $ok->modules);
    $this->assertSame("foo (version \"dev-main\"), bar (version \"1.0 \u{2039}b\u{203A}\")", SyncResult::listModules($ok->modules));

    $queued = (string) json_encode(['task_id' => 'task-1', 'unresolved_versions' => $unresolved]);
    $queued = $this->client(new Response(202, [], $queued))->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('accepted', $queued->status);
    $this->assertCount(2, $queued->modules);

    // An empty list, a missing one (an older Sentinel) or a malformed one
    // adds nothing.
    foreach ([['unresolved_versions' => []], [], ['unresolved_versions' => 'x']] as $extra) {
      $plain = $this->client(new Response(200, [], (string) json_encode(['message' => 'Synced.'] + $extra)))
        ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      $this->assertTrue($plain->isOk());
      $this->assertSame([], $plain->modules);
    }
  }

  /**
   * Builds a client whose actual Guzzle stack returns the given response.
   *
   * Its clock stands at 2026-10-09T13:00:00Z, before the dates of the tests.
   */
  private function client(Response $response): SentinelClient {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(gmmktime(13, 0, 0, 10, 9, 2026));
    return new SentinelClient(new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]), $time);
  }

}
