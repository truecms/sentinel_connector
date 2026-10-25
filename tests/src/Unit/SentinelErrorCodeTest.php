<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\sentinel_connector\PayloadBuilder;
use Drupal\sentinel_connector\SentinelClient;
use Drupal\sentinel_connector\SyncResult;
use Drupal\sentinel_connector\SyncService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Tests that Sentinel's error codes decide the outcome of a push.
 *
 * Fixtures use mocks as stubs and assert the returned payload or result.
 *
 * @group sentinel_connector
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
#[Group('sentinel_connector')]
class SentinelErrorCodeTest extends TestCase {

  /**
   * The fixed "now" of the test: 2026-10-09T13:00:00Z.
   */
  private const NOW = 1791550800;

  /**
   * What the connector makes of each code: outcome, log level, added text.
   *
   * The added text follows Sentinel's message in the text that is shown and
   * logged. The codes, their statuses and their bodies are Sentinel's own, in
   * tests/fixtures/sync_error_codes.json.
   */
  private const EXPECTED = [
    'site_url_mismatch' => ['site_mismatch', LogLevel::WARNING, ''],
    'site_uuid_mismatch' => ['site_mismatch', LogLevel::WARNING, ''],
    'module_validation_failed' => ['validation_failed', LogLevel::WARNING, ' Rejected modules (1): Capital_Name.'],
    'module_not_on_drupal_org' => ['validation_failed', LogLevel::WARNING, ' Rejected modules (1): not_on_drupal_org.'],
    'body_unreadable' => ['invalid_payload', LogLevel::ERROR, ''],
    'authentication_required' => ['auth_error', LogLevel::ERROR, ''],
    'api_key_invalid' => ['auth_error', LogLevel::ERROR, ''],
    'api_key_inactive' => ['auth_error', LogLevel::ERROR, ''],
    'signature_missing' => ['signature_refused', LogLevel::ERROR, ''],
    'signature_invalid' => ['signature_refused', LogLevel::ERROR, ''],
    'timestamp_skew' => ['signature_refused', LogLevel::ERROR, ''],
    'replay_detected' => ['signature_refused', LogLevel::ERROR, ''],
    'subscription_inactive' => ['subscription_inactive', LogLevel::WARNING, ''],
    'permission_denied' => ['auth_error', LogLevel::ERROR, ''],
    'site_mismatch' => ['auth_error', LogLevel::ERROR, ''],
    'site_unavailable' => ['not_found', LogLevel::WARNING, ''],
    'catalog_conflict' => ['conflict', LogLevel::WARNING, ''],
    'catalog_entry_unavailable' => ['conflict', LogLevel::WARNING, ''],
    'payload_invalid' => [
      'invalid_payload',
      LogLevel::ERROR,
      " First field at fault: body.modules.0.machine_name: String should match pattern '^[a-zA-Z0-9_]+$'.",
    ],
    'json_invalid' => ['invalid_payload', LogLevel::ERROR, ' First field at fault: body.10: JSON decode error.'],
    'push_limit_reached' => ['push_limited', LogLevel::NOTICE, ''],
    'site_rate_limited' => ['rate_limited', LogLevel::NOTICE, ' Retry after 60 seconds.'],
    'auth_rate_limited' => ['rate_limited', LogLevel::NOTICE, ' Retry after 60 seconds.'],
    'internal_error' => ['server_error', LogLevel::ERROR, ''],
    'rate_limiter_unavailable' => ['unavailable', LogLevel::ERROR, ' Retry after 30 seconds.'],
    'replay_protection_unavailable' => ['unavailable', LogLevel::ERROR, ' Retry after 30 seconds.'],
    'queue_unavailable' => ['unavailable', LogLevel::ERROR, ' Retry after 30 seconds.'],
    'inventory_busy' => ['unavailable', LogLevel::ERROR, ' Retry after 30 seconds.'],
    'auth_rate_limiter_unavailable' => ['unavailable', LogLevel::ERROR, ' Retry after 30 seconds.'],
  ];

  /**
   * The state keys that hold back later pushes.
   */
  private const HOLD_STATE = [
    SyncService::STATE_NEXT_ALLOWED_AT,
    SyncService::STATE_PUSH_LIMIT_MESSAGE,
    SyncService::STATE_SUBSCRIPTION_HOLD_UNTIL,
    SyncService::STATE_SUBSCRIPTION_REASON,
  ];

  /**
   * The in-memory state values.
   *
   * @var array<string, mixed>
   */
  private array $stateValues = [];

  /**
   * Every log entry written, as level, message and context.
   *
   * @var array<int, array{string, string, array<string, mixed>}>
   */
  private array $logs = [];

  /**
   * Every code Sentinel sends decides the outcome, the level and the text.
   *
   * The first response is Sentinel's own. The second carries the same code
   * with a status and a wording that, read without the code, mean another
   * outcome.
   *
   * @dataProvider serverCodes
   */
  #[DataProvider('serverCodes')]
  public function testErrorCodeDecidesOutcome(string $errorCode): void {
    $row = self::fixture()[$errorCode];
    [$outcome, $level, $added] = self::EXPECTED[$errorCode];
    $headers = $row['retry_after'] !== NULL ? ['Retry-After' => (string) $row['retry_after']] : [];

    $result = $this->push(new Response($row['status'], $headers, (string) json_encode($row['body'])));

    $this->assertSame($outcome, $result->status);
    $this->assertSame($row['status'], $result->httpCode);
    $this->assertFalse($result->isOk());
    // The text is Sentinel's message, and only what the message cannot hold.
    $this->assertSame($row['body']['message'] . $added, $result->message);
    $this->assertLessThanOrEqual(500, mb_strlen($result->message));
    $this->assertSame(['error code' => $errorCode], $result->diagnostics);

    $this->assertCount(1, $this->logs);
    [$logged, $message, $context] = $this->logs[0];
    $this->assertSame($level, $logged);
    $this->assertSame('Sentinel push (@trigger): @outcome, HTTP @code. @message [@details]', $message);
    $this->assertSame($outcome, $context['@outcome']);
    $this->assertSame($row['status'], $context['@code']);
    $this->assertSame($result->message, $context['@message']);
    $this->assertMatchesRegularExpression('/(\A|; )error code ' . $errorCode . '\z/', $context['@details']);
    $this->assertStringNotContainsString('SECRET', (string) json_encode($this->logs));

    if (isset(SentinelClient::STATUS_BOUND_ERROR_CODES[$errorCode])) {
      // These two count on their own status only.
      return;
    }
    // Without the code: not_found for the 404, site_mismatch for the 400.
    [$status, $detail] = $outcome === 'site_mismatch' ? [404, 'Site not found'] : [400, 'Site URL mismatch'];
    $misleading = $this->push(new Response($status, [], (string) json_encode(['detail' => $detail] + $row['body'])));
    $this->assertSame($outcome, $misleading->status);
    $this->assertSame($status, $misleading->httpCode);
    $this->assertSame($level, $this->logs[0][0]);
    $this->assertStringNotContainsString($detail, $misleading->message);
  }

  /**
   * The codes of Sentinel's registry.
   *
   * @return array<string, array{string}>
   *   The error codes.
   */
  public static function serverCodes(): array {
    $codes = array_keys(self::fixture());
    return array_combine($codes, array_map(fn (string $code): array => [$code], $codes));
  }

  /**
   * The map holds the codes of Sentinel's registry, and no others.
   */
  public function testMapCoversTheServerRegistry(): void {
    $fixture = self::fixture();
    $server = array_keys($fixture);
    $map = array_keys(SentinelClient::ERROR_CODE_OUTCOMES);
    $expected = array_keys(self::EXPECTED);
    sort($server);
    sort($map);
    sort($expected);
    $this->assertSame($server, $map);
    $this->assertSame($server, $expected);

    foreach ($fixture as $errorCode => $row) {
      $this->assertMatchesRegularExpression('/\A[a-z0-9_]{1,64}\z/', (string) $errorCode);
      $this->assertSame($errorCode, $row['body']['error_code']);
      $this->assertSame(self::EXPECTED[$errorCode][0], SentinelClient::ERROR_CODE_OUTCOMES[$errorCode]);
      // Sentinel always says how long to wait on a 429 and a 503, only there.
      $this->assertSame(in_array($row['status'], [429, 503], TRUE), $row['retry_after'] !== NULL, (string) $errorCode);
    }
    // The codes that hold back pushes are bound to the status Sentinel uses.
    foreach (SentinelClient::STATUS_BOUND_ERROR_CODES as $errorCode => $status) {
      $this->assertSame($fixture[$errorCode]['status'], $status);
    }
  }

  /**
   * A known code on a status of another class still decides the outcome.
   */
  public function testKnownCodeWithMismatchedStatus(): void {
    $this->assertSame('unavailable', $this->respond(500, '{"error_code": "queue_unavailable"}')->status);
    $this->assertSame('server_error', $this->respond(503, '{"error_code": "internal_error"}')->status);
    $this->assertSame('rate_limited', $this->respond(503, '{"error_code": "site_rate_limited"}')->status);
    $this->assertSame('not_found', $this->respond(409, '{"error_code": "site_unavailable"}')->status);
  }

  /**
   * The codes that hold back pushes do nothing on another status.
   *
   * A push limit stores the time of the next push, and an inactive
   * subscription holds cron for a day. Neither happens unless the code comes
   * with its own status.
   *
   * @dataProvider holdingCodesOnOtherStatuses
   */
  #[DataProvider('holdingCodesOnOtherStatuses')]
  public function testHoldingCodeOnOtherStatusWritesNoHold(string $errorCode, int $status, string $outcome): void {
    $body = self::fixture()[$errorCode]['body'];

    $result = $this->push(new Response($status, ['Retry-After' => '600'], (string) json_encode($body)));

    $this->assertSame($outcome, $result->status);
    $this->assertSame($status, $result->httpCode);
    $this->assertFalse($result->isPushLimited());
    $this->assertFalse($result->isSubscriptionInactive());
    $this->assertNull($result->nextAllowedAt);
    $this->assertNull($result->reason);
    $this->assertSame(['ignored error code' => $errorCode], $result->diagnostics);
    foreach (self::HOLD_STATE as $key) {
      $this->assertArrayNotHasKey($key, $this->stateValues);
    }
    $this->assertSame($outcome, $this->stateValues['sentinel_connector.last_result']);
    $this->assertSame(LogLevel::ERROR, $this->logs[0][0]);
    $this->assertSame('ignored error code ' . $errorCode, $this->logs[0][2]['@details']);
  }

  /**
   * The two holding codes on statuses Sentinel never sends them with.
   *
   * @return array<string, array{string, int, string}>
   *   The error code, the HTTP status and the outcome of that status.
   */
  public static function holdingCodesOnOtherStatuses(): array {
    $cases = [];
    foreach (array_keys(SentinelClient::STATUS_BOUND_ERROR_CODES) as $errorCode) {
      foreach ([400 => 'rejected', 500 => 'server_error', 503 => 'unavailable'] as $status => $outcome) {
        $cases[$errorCode . ' on ' . $status] = [$errorCode, $status, $outcome];
      }
    }
    // On the status of the other code. The billing code on a 429 is in
    // testHoldingCodesOnTheirOwnStatus(): its outcome is logged as a notice.
    $cases['push_limit_reached on 402'] = ['push_limit_reached', 402, 'rejected'];
    return $cases;
  }

  /**
   * On their own status the holding codes write their hold, within the cap.
   */
  public function testHoldingCodesOnTheirOwnStatus(): void {
    $far = ['retry_after_seconds' => 99999999, 'next_allowed_at' => '2099-01-01T00:00:00Z'];
    $limited = $this->push(new Response(429, [], (string) json_encode($far + self::fixture()['push_limit_reached']['body'])));
    $this->assertTrue($limited->isPushLimited());
    // One response holds pushes back for seven days at most.
    $this->assertSame(self::NOW + SyncService::MAX_PUSH_DEFERRAL, $limited->nextAllowedAt);
    $this->assertSame(self::NOW + SyncService::MAX_PUSH_DEFERRAL, $this->stateValues[SyncService::STATE_NEXT_ALLOWED_AT]);
    $this->assertSame(
      'plan free; limit 1; next push allowed at ' . gmdate('Y-m-d\TH:i:s\Z', self::NOW + SyncService::MAX_PUSH_DEFERRAL) . '; error code push_limit_reached',
      $this->logs[0][2]['@details'],
    );

    $billing = $this->push(new Response(402, [], (string) json_encode(self::fixture()['subscription_inactive']['body'])));
    $this->assertTrue($billing->isSubscriptionInactive());
    $this->assertSame(self::NOW + SyncService::SUBSCRIPTION_HOLD, $this->stateValues[SyncService::STATE_SUBSCRIPTION_HOLD_UNTIL]);
    $this->assertSame('past_due', $this->stateValues[SyncService::STATE_SUBSCRIPTION_REASON]);
    $this->assertSame('reason past_due; error code subscription_inactive', $this->logs[0][2]['@details']);

    // A 429 with the billing code is a rate limit, and holds nothing.
    $this->push(new Response(429, ['Retry-After' => '600'], '{"error_code": "subscription_inactive", "reason": "paused"}'));
    $this->assertSame('rate_limited', $this->stateValues['sentinel_connector.last_result']);
    foreach (self::HOLD_STATE as $key) {
      $this->assertArrayNotHasKey($key, $this->stateValues);
    }
  }

  /**
   * Sentinel's "site_mismatch" code is not this module's outcome of that name.
   *
   * The code means that the API key belongs to another site. The outcome is
   * for a site URL or UUID that differs.
   */
  public function testSiteMismatchCodeIsAnAuthenticationError(): void {
    $row = self::fixture()['site_mismatch'];
    $key = $this->push(new Response(403, [], (string) json_encode($row['body'])));
    $this->assertSame(403, $row['status']);
    $this->assertSame('auth_error', $key->status);
    $this->assertSame(403, $key->httpCode);
    $this->assertSame('This API key belongs to a different site. Use the key issued for this site.', $key->message);
    $this->assertSame(LogLevel::ERROR, $this->logs[0][0]);
    $this->assertSame('auth_error', $this->logs[0][2]['@outcome']);
    $this->assertSame('error code site_mismatch', $this->logs[0][2]['@details']);
    // Not even on the status and wording of a site URL mismatch.
    $this->assertSame('auth_error', $this->respond(400, '{"error_code": "site_mismatch", "detail": "Site URL mismatch"}')->status);
  }

  /**
   * A site URL or UUID that differs keeps the "site_mismatch" outcome.
   */
  public function testSiteUrlAndUuidCodesKeepSiteMismatchOutcome(): void {
    foreach (['site_url_mismatch', 'site_uuid_mismatch'] as $errorCode) {
      $body = ['error_code' => $errorCode, 'message' => 'It differs.'];
      $site = $this->push(new Response(400, [], (string) json_encode($body)));
      $this->assertSame('site_mismatch', $site->status);
      $this->assertSame(LogLevel::WARNING, $this->logs[0][0]);
      $this->assertSame('site_mismatch', $this->logs[0][2]['@outcome']);
      $this->assertSame('It differs.', $site->message);
    }
  }

  /**
   * A body Sentinel could not decode or parse is an invalid payload.
   *
   * "json_invalid" has a list detail on the sync route and a string detail on
   * the older route. A "loc" segment may be an offset or "*".
   *
   * @dataProvider unreadableBodies
   */
  #[DataProvider('unreadableBodies')]
  public function testUnreadableBodyIsInvalidPayload(int $status, string $body, string $message): void {
    $result = $this->push(new Response($status, [], $body));

    $this->assertSame('invalid_payload', $result->status);
    $this->assertSame($status, $result->httpCode);
    $this->assertSame($message, $result->message);
    $this->assertSame(LogLevel::ERROR, $this->logs[0][0]);
  }

  /**
   * Bodies of a refusal over an undecodable, unparsable or invalid payload.
   *
   * @return array<string, array{int, string, string}>
   *   HTTP status, raw body and the expected message.
   */
  public static function unreadableBodies(): array {
    $list = '[{"loc": ["body", 17], "msg": "JSON decode error", "type": "json_invalid"}]';
    $star = '[{"loc": ["body", "modules", 3, "*", "version"], "msg": "Field required", "type": "missing"}]';
    return [
      'body unreadable' => [
        400,
        '{"error_code": "body_unreadable", "message": "Sentinel could not read the request body.", "detail": "Unreadable"}',
        'Sentinel could not read the request body.',
      ],
      'body unreadable, no message' => [
        400, '{"error_code": "body_unreadable"}', 'Sentinel could not read the inventory.',
      ],
      'json invalid, list detail' => [
        422,
        '{"error_code": "json_invalid", "message": "The request body is not valid JSON.", "detail": ' . $list . '}',
        'The request body is not valid JSON. First field at fault: body.17: JSON decode error.',
      ],
      'json invalid, list detail, no message' => [
        422,
        '{"error_code": "json_invalid", "detail": ' . $list . '}',
        'Sentinel could not read the inventory: body.17: JSON decode error',
      ],
      'json invalid, string detail' => [
        422,
        '{"error_code": "json_invalid", "message": "The request body is not valid JSON.", "detail": "Invalid JSON inventory payload"}',
        'The request body is not valid JSON.',
      ],
      'json invalid, string detail, no message' => [
        422,
        '{"error_code": "json_invalid", "detail": "Invalid JSON inventory payload"}',
        'Invalid JSON inventory payload',
      ],
      'payload invalid, wildcard and integer segments' => [
        422,
        '{"error_code": "payload_invalid", "message": "The inventory is not valid.", "detail": ' . $star . '}',
        'The inventory is not valid. First field at fault: body.modules.3.*.version: Field required.',
      ],
      'older server, wildcard segment' => [
        422,
        '{"detail": ' . $star . '}',
        'Sentinel could not read the inventory: body.modules.3.*.version: Field required',
      ],
    ];
  }

  /**
   * A code on a response that is not an error does not make it one.
   */
  public function testErrorCodeIsIgnoredBelowErrorStatuses(): void {
    $result = $this->respond(200, '{"error_code": "site_unavailable", "message": "Stored."}');
    $this->assertSame('success', $result->status);
    $this->assertSame([], $result->diagnostics);
    $this->assertTrue($this->respond(202, '{"error_code": "site_unavailable", "task_id": "task-1"}')->isOk());

    // A redirect that was not followed is read by its status alone.
    foreach (['site_unavailable', 'push_limit_reached', 'brand_new_code'] as $errorCode) {
      $body = ['error_code' => $errorCode, 'message' => 'Moved.'];
      $redirect = $this->push(new Response(302, [], (string) json_encode($body)));
      $this->assertSame('rejected', $redirect->status);
      $this->assertSame(302, $redirect->httpCode);
      $this->assertSame('Sentinel rejected the sync.', $redirect->message);
      $this->assertSame([], $redirect->diagnostics);
      $this->assertSame('no details', $this->logs[0][2]['@details']);
      foreach (self::HOLD_STATE as $key) {
        $this->assertArrayNotHasKey($key, $this->stateValues);
      }
    }
  }

  /**
   * An unknown code keeps the outcome of the status and is logged as a token.
   */
  public function testUnknownCodeFallsBack(): void {
    $result = $this->push(new Response(409, [], '{"error_code": "brand_new_code", "message": "New words.", "detail": "Old words"}'));

    $this->assertSame('conflict', $result->status);
    $this->assertSame('Old words', $result->message);
    $this->assertSame(['unknown error code' => 'brand_new_code'], $result->diagnostics);
    $this->assertSame(LogLevel::WARNING, $this->logs[0][0]);
    $this->assertSame('unknown error code brand_new_code', $this->logs[0][2]['@details']);

    // The signature code of an older server is kept beside it.
    $signature = $this->respond(401, '{"error_code": "signature_v2_needed", "detail": {"code": "signature_expired", "message": "Expired."}}');
    $this->assertSame('signature_refused', $signature->status);
    $this->assertSame(['code' => 'signature_expired', 'unknown error code' => 'signature_v2_needed'], $signature->diagnostics);

    // Whatever the status means without a code is what an unknown code gets.
    $this->assertSame('rate_limited', $this->respond(429, '{"error_code": "abuse_limit"}')->status);
    $this->assertSame('rejected', $this->respond(402, '{"error_code": "site_limit_reached"}')->status);
    $this->assertSame('site_mismatch', $this->respond(400, '{"error_code": "new_code", "detail": "Site URL mismatch"}')->status);
  }

  /**
   * A code that is not a safe token is not matched and not logged.
   *
   * @dataProvider unsafeCodes
   */
  #[DataProvider('unsafeCodes')]
  public function testUnsafeCodeIsIgnored(mixed $errorCode): void {
    $bodies = [
      ['error_code' => $errorCode, 'message' => 'New words.', 'detail' => 'Old words'],
      ['detail' => ['error_code' => $errorCode, 'message' => 'Old words']],
      // An unusable code at the top level hides a usable one under "detail".
      ['error_code' => $errorCode, 'detail' => ['error_code' => 'site_unavailable', 'message' => 'Old words']],
    ];
    foreach ($bodies as $body) {
      $result = $this->push(new Response(409, [], (string) json_encode($body)));

      $this->assertSame('conflict', $result->status);
      $this->assertSame('Old words', $result->message);
      $this->assertSame([], $result->diagnostics);
      $this->assertSame('no details', $this->logs[0][2]['@details']);
    }
  }

  /**
   * Values of "error_code" that are not a safe token.
   *
   * @return array<string, array{mixed}>
   *   The value of the "error_code" member.
   */
  public static function unsafeCodes(): array {
    return [
      'trailing newline on a known code' => ["site_unavailable\n"],
      'leading newline' => ["\nsite_unavailable"],
      'embedded newline' => ["site_unavailable\ninjected"],
      'upper case' => ['Site_Unavailable'],
      'space' => ['site unavailable'],
      'markup' => ['<script>alert(1)</script>'],
      'log separator' => ['a; outcome success'],
      'too long' => [str_repeat('a', 65)],
      'empty' => [''],
      'list' => [['site_unavailable']],
      'number' => [404],
      'boolean' => [TRUE],
    ];
  }

  /**
   * A code under FastAPI's "detail" is read like one at the top level.
   */
  public function testErrorCodeNestedUnderDetail(): void {
    $result = $this->respond(418, (string) json_encode([
      'detail' => [
        'error_code' => 'module_validation_failed',
        'message' => 'Two modules were rejected.',
        'invalid_modules' => [['module_name' => 'bad_1'], ['module_name' => 'bad_2']],
      ],
    ]));
    $this->assertSame('validation_failed', $result->status);
    $this->assertSame('Two modules were rejected. Rejected modules (2): bad_1, bad_2.', $result->message);
    $this->assertSame(['error code' => 'module_validation_failed'], $result->diagnostics);

    $missing = $this->respond(400, '{"detail": {"error_code": "site_unavailable", "message": "This site is not active."}}');
    $this->assertSame('not_found', $missing->status);
    $this->assertSame('This site is not active.', $missing->message);

    $bare = $this->respond(400, '{"detail": {"error_code": "catalog_conflict"}}');
    $this->assertSame('conflict', $bare->status);
    $this->assertSame('Sentinel could not store the inventory because of a conflict.', $bare->message);

    // The code at the top level wins over one under "detail".
    $both = $this->respond(400, '{"error_code": "site_unavailable", "detail": {"error_code": "catalog_conflict"}}');
    $this->assertSame('not_found', $both->status);

    // A null at the top level is no code: the one under "detail" counts.
    $null = $this->respond(400, '{"error_code": null, "detail": {"error_code": "catalog_conflict", "message": "Conflict."}}');
    $this->assertSame('conflict', $null->status);
    $this->assertSame('Conflict.', $null->message);
    $this->assertSame(['error code' => 'catalog_conflict'], $null->diagnostics);
  }

  /**
   * The message is preferred over the detail, cleaned and capped.
   */
  public function testMessageIsPreferredCleanedAndCapped(): void {
    $long = $this->respond(409, (string) json_encode([
      'error_code' => 'catalog_conflict',
      'message' => "Line one\r\nline\u{202E}two " . str_repeat('x', 600),
      'detail' => 'Legacy wording.',
    ]));
    $this->assertStringStartsWith('Line one line two', $long->message);
    $this->assertSame(500, mb_strlen($long->message));

    // No usable message: the detail, and then a fixed sentence.
    $this->assertSame('Legacy wording.', $this->respond(409, '{"error_code": "catalog_conflict", "message": ["x"], "detail": "Legacy wording."}')->message);
    $this->assertSame('Legacy wording.', $this->respond(409, '{"error_code": "catalog_conflict", "message": " ", "detail": "Legacy wording."}')->message);
    $this->assertSame('Authentication failed. Check the API key and retry.', $this->respond(401, '{"error_code": "api_key_invalid", "detail": {"secret": "SECRET"}}')->message);
    $this->assertSame('Sentinel server returned HTTP 500. Retry later.', $this->respond(500, '{"error_code": "internal_error"}')->message);
  }

  /**
   * This module's own advice is not added to a message of Sentinel's.
   *
   * Sentinel's message already says what to do. The short "detail" of an
   * older server does not, so the advice still follows that.
   */
  public function testAdviceIsNotAddedToServerMessage(): void {
    $advice = 'Check the site URL and site UUID against the site registered in Sentinel.';
    $this->assertSame(
      'The site URL differs. Check the site URL in Sentinel.',
      $this->respond(400, '{"error_code": "site_url_mismatch", "message": "The site URL differs. Check the site URL in Sentinel.", "detail": "Site URL mismatch"}')->message,
    );
    $this->assertSame('Site URL mismatch. ' . $advice, $this->respond(400, '{"error_code": "site_url_mismatch", "detail": "Site URL mismatch"}')->message);
    $this->assertSame('Site URL mismatch. ' . $advice, $this->respond(400, '{"detail": "Site URL mismatch"}')->message);

    $this->assertSame('No active site.', $this->respond(404, '{"error_code": "site_unavailable", "message": "No active site.", "detail": "Site not found"}')->message);
    $this->assertSame(
      'Site not found. Check the API base URL and site UUID.',
      $this->respond(404, '{"error_code": "site_unavailable", "detail": "Site not found"}')->message,
    );

    // A wait is only added to the outcomes that show one.
    $error = $this->client(new Response(500, ['Retry-After' => '30'], '{"error_code": "internal_error", "message": "Try again later."}'))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('Try again later.', $error->message);
  }

  /**
   * What follows the message is a sentence of its own, within the cap.
   */
  public function testAdditionsFollowTheMessageWithinTheCap(): void {
    // Rejected modules are listed after the message, not the detail's words.
    $validation = $this->respond(400, (string) json_encode([
      'error_code' => 'module_validation_failed',
      'message' => 'One module was rejected',
      'detail' => [
        'error' => 'Module validation failed',
        'message' => 'Legacy wording',
        'invalid_modules' => [['module_name' => 'bad_1']],
      ],
    ]));
    $this->assertSame('One module was rejected. Rejected modules (1): bad_1.', $validation->message);

    // The first field at fault is a sentence of its own.
    $payload = $this->respond(422, (string) json_encode([
      'error_code' => 'payload_invalid',
      'message' => 'The inventory is not valid. Update the module if this continues.',
      'detail' => [
        ['loc' => ['body', 'modules', 0, 'version'], 'msg' => 'Field required', 'type' => 'missing'],
        ['loc' => ['body'], 'msg' => 'Other', 'type' => 'x'],
      ],
    ]));
    $this->assertSame(
      'The inventory is not valid. Update the module if this continues. First field at fault: body.modules.0.version: Field required (and 1 more).',
      $payload->message,
    );

    // A long message is shortened, so that what follows it is kept.
    $long = str_repeat('x', 600);
    $body = ['error_code' => 'queue_unavailable', 'message' => $long];
    $busy = $this->client(new Response(503, ['Retry-After' => '30'], (string) json_encode($body)))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame(500, mb_strlen($busy->message));
    $this->assertStringEndsWith("x\u{2026} Retry after 30 seconds.", $busy->message);

    $modules = array_map(fn (int $i): array => ['module_name' => str_repeat('m', 80) . $i], range(1, 9));
    $many = $this->respond(400, (string) json_encode([
      'error_code' => 'module_validation_failed',
      'message' => $long,
      'detail' => ['invalid_modules' => $modules],
    ]));
    $this->assertSame(500, mb_strlen($many->message));
    $this->assertStringContainsString("x\u{2026} Rejected modules (9): " . str_repeat('m', 64) . ', ', $many->message);
    $this->assertStringEndsWith(' and 4 more.', $many->message);

    $field = $this->respond(422, (string) json_encode([
      'error_code' => 'payload_invalid',
      'message' => $long,
      'detail' => [['loc' => ['body', str_repeat('l', 300)], 'msg' => str_repeat('w', 500), 'type' => 'x']],
    ]));
    $this->assertSame(500, mb_strlen($field->message));
    $this->assertStringContainsString("x\u{2026} First field at fault: body.lll", $field->message);
  }

  /**
   * Server text in a validation error is cleaned on a coded response too.
   *
   * The location and the words of the error reach the log, the stored state
   * and the page. Neither may carry a line break or a bidi override.
   */
  public function testValidationErrorTextIsCleaned(): void {
    $error = [
      'loc' => ['body', "a\nFAKE LOG LINE\u{202E}b\u{0007}"],
      'msg' => "x\r\ny\u{202E}z\u{2028}w",
      'type' => "t\n",
    ];
    $body = ['error_code' => 'payload_invalid', 'message' => 'Bad.', 'detail' => [$error, $error]];
    $expected = 'Bad. First field at fault: body.a FAKE LOG LINE b: x y z w (and 1 more).';

    $result = $this->push(new Response(422, [], (string) json_encode($body)));

    $this->assertSame($expected, $result->message);
    $this->assertSame($expected, $this->stateValues['sentinel_connector.last_message']);
    $this->assertSame($expected, $this->logs[0][2]['@message']);
    $this->assertDoesNotMatchRegularExpression('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', (string) json_encode($this->logs, JSON_UNESCAPED_UNICODE));

    // The same body from an older server reads the same way.
    $older = $this->respond(422, (string) json_encode(['detail' => [$error, $error]]));
    $this->assertSame('Sentinel could not read the inventory: body.a FAKE LOG LINE b: x y z w (and 1 more)', $older->message);
  }

  /**
   * Module names and the message itself are cleaned on a coded response.
   */
  public function testModuleNamesAreCleaned(): void {
    $modules = $this->push(new Response(400, [], (string) json_encode([
      'error_code' => 'module_validation_failed',
      'message' => "Two\nlines\u{202E}.",
      'detail' => ['invalid_modules' => [['module_name' => "bad\n\u{202E}one"], ['module_name' => "\u{202E}"]]],
    ])));
    $this->assertSame('Two lines . Rejected modules (2): bad one, unnamed.', $modules->message);
    $this->assertSame($modules->message, $this->stateValues['sentinel_connector.last_message']);
    $this->assertSame($modules->message, $this->logs[0][2]['@message']);
  }

  /**
   * What follows the message is joined to it with the right punctuation.
   *
   * @dataProvider punctuation
   */
  #[DataProvider('punctuation')]
  public function testPunctuationOfAdditions(string $message, string $msg, string $expected): void {
    $body = ['error_code' => 'payload_invalid', 'message' => $message, 'detail' => [['loc' => ['body'], 'msg' => $msg]]];
    $this->assertSame($expected, $this->respond(422, (string) json_encode($body))->message);
  }

  /**
   * Messages and validation errors with and without final punctuation.
   *
   * @return array<string, array{string, string, string}>
   *   The message, the words of the validation error and the expected text.
   */
  public static function punctuation(): array {
    $field = ' First field at fault: body: Field required.';
    return [
      'full stop' => ['Not valid.', 'Field required', 'Not valid.' . $field],
      'no full stop' => ['Not valid', 'Field required', 'Not valid.' . $field],
      'question mark' => ['Is the module up to date?', 'Field required', 'Is the module up to date?' . $field],
      'exclamation mark' => ['Not valid!', 'Field required', 'Not valid!' . $field],
      'ellipsis' => ["Not valid\u{2026}", 'Field required', "Not valid\u{2026}" . $field],
      'error ends in a full stop' => ['Not valid.', 'Field required.', 'Not valid.' . $field],
      'error ends in full stops' => ['Not valid.', 'Field required. . .', 'Not valid.' . $field],
      'error is only a full stop' => ['Not valid.', '.', 'Not valid.'],
      'message is only punctuation' => [
        '...', 'Field required', 'Sentinel could not read the inventory: body: Field required',
      ],
      'message is only symbols' => [
        '?! - ', 'Field required', 'Sentinel could not read the inventory: body: Field required',
      ],
      'message is empty after cleaning' => [
        "\u{202E}\n ", 'Field required', 'Sentinel could not read the inventory: body: Field required',
      ],
    ];
  }

  /**
   * A message too long for the cap is cut at a word and ends in an ellipsis.
   */
  public function testLongMessageIsShortenedAtWordBoundary(): void {
    $wait = ' Retry after 30 seconds.';
    $send = fn (string $message): string => $this->client(new Response(503, ['Retry-After' => '30'], (string) json_encode([
      'error_code' => 'queue_unavailable',
      'message' => $message,
    ])))->sync('https://sentinel.example.com', 'uuid', 'SECRET', [])->message;

    // Words: the cut is at a space, and no word is broken.
    $words = $send(trim(str_repeat('word ', 120)));
    $this->assertLessThanOrEqual(500, mb_strlen($words));
    $this->assertGreaterThan(490, mb_strlen($words));
    $this->assertStringEndsWith("word\u{2026}" . $wait, $words);
    $this->assertStringNotContainsString("wor\u{2026}", $words);

    // No space to cut at, and characters of more than one byte.
    $accents = $send(str_repeat("\u{00E9}", 600));
    $this->assertSame(500, mb_strlen($accents));
    $this->assertTrue(mb_check_encoding($accents, 'UTF-8'));
    $this->assertSame(str_repeat("\u{00E9}", 475) . "\u{2026}" . $wait, $accents);

    // A message that just fits is not touched.
    $fits = str_repeat('x', 475) . '.';
    $this->assertSame($fits . $wait, $send($fits));
    $this->assertSame(500, mb_strlen($send($fits)));
    $this->assertSame(str_repeat('x', 474) . '.' . $wait, $send(str_repeat('x', 474)));
    $this->assertSame(str_repeat('x', 475) . "\u{2026}" . $wait, $send(str_repeat('x', 476)));
  }

  /**
   * Rejected modules beside a nested error code are listed.
   *
   * A nested error object can have a "detail" of its own. The modules are
   * then read from the object that carries the code.
   */
  public function testRejectedModulesBesideNestedCode(): void {
    $modules = [['module_name' => 'bad_1'], ['module_name' => 'bad_2']];
    $nested = $this->respond(400, (string) json_encode([
      'detail' => [
        'error_code' => 'module_validation_failed',
        'message' => 'Two modules were rejected.',
        'detail' => 'Module validation failed',
        'invalid_modules' => $modules,
      ],
    ]));
    $this->assertSame('validation_failed', $nested->status);
    $this->assertSame('Two modules were rejected. Rejected modules (2): bad_1, bad_2.', $nested->message);

    // Without a message, the words of the nested detail come first.
    $bare = $this->respond(400, (string) json_encode([
      'detail' => [
        'error_code' => 'module_validation_failed',
        'detail' => 'Module validation failed',
        'invalid_modules' => $modules,
      ],
    ]));
    $this->assertSame('Module validation failed. Rejected modules (2): bad_1, bad_2.', $bare->message);

    // The same beside a code at the top level.
    $flat = $this->respond(400, (string) json_encode([
      'error_code' => 'module_validation_failed',
      'message' => 'Two modules were rejected.',
      'detail' => 'Module validation failed',
      'invalid_modules' => $modules,
    ]));
    $this->assertSame('Two modules were rejected. Rejected modules (2): bad_1, bad_2.', $flat->message);

    // The modules in the detail win when both list some.
    $both = $this->respond(400, (string) json_encode([
      'error_code' => 'module_validation_failed',
      'message' => 'Rejected.',
      'detail' => ['invalid_modules' => [['module_name' => 'from_detail']]],
      'invalid_modules' => $modules,
    ]));
    $this->assertSame('Rejected. Rejected modules (1): from_detail.', $both->message);
  }

  /**
   * Without the time service the system clock decides whether a date passed.
   */
  public function testRetryAfterDateWithoutTimeService(): void {
    $format = 'D, d M Y H:i:s \G\M\T';
    $send = fn (string $header): string => (new SentinelClient(new Client([
      'handler' => HandlerStack::create(new MockHandler([
        new Response(503, ['Retry-After' => $header], '{"error_code": "queue_unavailable", "message": "Busy."}'),
      ])),
    ])))->sync('https://sentinel.example.com', 'uuid', 'SECRET', [])->message;

    $future = time() + 3600;
    $this->assertSame('Busy. Retry after ' . gmdate('c', $future) . '.', $send(gmdate($format, $future)));
    $this->assertSame('Busy.', $send(gmdate($format, time() - 3600)));
    $this->assertSame('Busy. Retry after 30 seconds.', $send('30'));
  }

  /**
   * An HTTP date is read as GMT whatever the site's default time zone is.
   *
   * @dataProvider timeZones
   */
  #[DataProvider('timeZones')]
  public function testRetryAfterDateIgnoresDefaultTimeZone(string $zone): void {
    $default = date_default_timezone_get();
    date_default_timezone_set($zone);
    try {
      $send = fn (string $header): SyncResult => $this->client(new Response(429, ['Retry-After' => $header], '{"error_code": "push_limit_reached"}'))
        ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      // Three hours after the fixed clock, in GMT.
      $this->assertSame(self::NOW + 10800, $send('Fri, 09 Oct 2026 16:00:00 GMT')->nextAllowedAt);
      // One hour before it: past in GMT, though not in every local time.
      $this->assertNull($send('Fri, 09 Oct 2026 12:00:00 GMT')->nextAllowedAt);
      $wait = $this->client(new Response(503, ['Retry-After' => 'Fri, 09 Oct 2026 16:00:00 GMT'], '{"error_code": "queue_unavailable", "message": "Busy."}'))
        ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      $this->assertSame('Busy. Retry after 2026-10-09T16:00:00+00:00.', $wait->message);
    }
    finally {
      date_default_timezone_set($default);
    }
  }

  /**
   * Default time zones west and east of GMT.
   *
   * @return array<string, array{string}>
   *   Time zone names.
   */
  public static function timeZones(): array {
    return [
      'UTC' => ['UTC'],
      'Pacific/Auckland' => ['Pacific/Auckland'],
      'America/Los_Angeles' => ['America/Los_Angeles'],
      'Asia/Kolkata' => ['Asia/Kolkata'],
    ];
  }

  /**
   * A refused signature is recognised by the top-level code.
   */
  public function testSignatureCodeAtTopLevel(): void {
    $result = $this->respond(401, (string) json_encode([
      'error_code' => 'timestamp_skew',
      'message' => 'The clock of this site is off.',
      'detail' => ['code' => 'timestamp_skew', 'message' => 'Legacy wording.'],
    ]));
    $this->assertSame('signature_refused', $result->status);
    $this->assertSame('The clock of this site is off.', $result->message);
    $this->assertSame(['error code' => 'timestamp_skew'], $result->diagnostics);
  }

  /**
   * Only a positive number of seconds or a future HTTP date is a wait.
   *
   * @param string $header
   *   The Retry-After header value.
   * @param string $wait
   *   The expected sentence, or an empty string when no wait is shown.
   * @param int|null $seconds
   *   The expected relative wait of a push limit.
   * @param int|null $time
   *   The expected absolute time of a push limit.
   *
   * @dataProvider retryAfterHeaders
   */
  #[DataProvider('retryAfterHeaders')]
  public function testRetryAfterIsStrict(string $header, string $wait, ?int $seconds, ?int $time): void {
    $headers = $header !== '' ? ['Retry-After' => $header] : [];
    $send = fn (int $status, string $body): SyncResult => $this->client(new Response($status, $headers, $body))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $suffix = $wait !== '' ? ' ' . $wait : '';

    // With a code, on the outcomes that show a wait.
    $this->assertSame('Busy.' . $suffix, $send(503, '{"error_code": "queue_unavailable", "message": "Busy."}')->message);
    $this->assertSame('Busy.' . $suffix, $send(429, '{"error_code": "site_rate_limited", "message": "Busy."}')->message);
    // Without a code, on a 429 only, as before.
    $this->assertSame('Sentinel rate limit reached.' . $suffix, $send(429, '')->message);
    $this->assertSame('Slow down.' . $suffix, $send(429, '{"detail": "Slow down"}')->message);
    $this->assertSame('Down', $send(503, '{"detail": "Down"}')->message);

    // A push limit that has the header and no body fields.
    $limited = $this->push(new Response(429, $headers, '{"error_code": "push_limit_reached"}'));
    $this->assertTrue($limited->isPushLimited());
    $this->assertSame(SentinelClient::PUSH_LIMIT_FALLBACK_MESSAGE, $limited->message);
    $this->assertSame($seconds, $limited->retryAfterSeconds);
    $stored = $seconds !== NULL ? self::NOW + $seconds : $time;
    $this->assertSame($stored, $limited->nextAllowedAt);
    $this->assertSame($stored, $this->stateValues[SyncService::STATE_NEXT_ALLOWED_AT] ?? NULL);
  }

  /**
   * Retry-After header values.
   *
   * @return array<string, array{string, string, int|null, int|null}>
   *   The header value, the expected sentence, and the expected relative
   *   wait and absolute time of a push limit.
   */
  public static function retryAfterHeaders(): array {
    $date = gmmktime(3, 15, 0, 10, 10, 2026);
    $none = ['', NULL, NULL];
    return [
      'seconds' => ['30', 'Retry after 30 seconds.', 30, NULL],
      'seconds with leading zeros' => ['0030', 'Retry after 30 seconds.', 30, NULL],
      'future date' => ['Sat, 10 Oct 2026 03:15:00 GMT', 'Retry after 2026-10-10T03:15:00+00:00.', NULL, $date],
      'no header' => ['', ...$none],
      'zero' => ['0', ...$none],
      'negative' => ['-5', ...$none],
      'signed' => ['+30', ...$none],
      'fraction' => ['30.5', ...$none],
      'oversized' => [str_repeat('9', 400), ...$none],
      'ten digits' => ['1000000000', ...$none],
      'relative years' => ['+500 years', ...$none],
      'tomorrow' => ['tomorrow', ...$none],
      'word' => ['soon', ...$none],
      'past date' => ['Thu, 08 Oct 2026 03:15:00 GMT', ...$none],
      'the present moment' => ['Fri, 09 Oct 2026 13:00:00 GMT', ...$none],
      'wrong weekday' => ['Mon, 10 Oct 2026 03:15:00 GMT', ...$none],
      'day that does not exist' => ['Sat, 31 Nov 2026 03:15:00 GMT', ...$none],
      'other time zone' => ['Sat, 10 Oct 2026 03:15:00 +0200', ...$none],
      'obsolete date format' => ['Saturday, 10-Oct-26 03:15:00 GMT', ...$none],
      'ISO date' => ['2026-10-10T03:15:00Z', ...$none],
      'date with trailing text' => ['Sat, 10 Oct 2026 03:15:00 GMT; DROP', ...$none],
    ];
  }

  /**
   * A response without a code is read as before.
   */
  public function testResponsesWithoutCodeAreUnchanged(): void {
    // An older server's 503 never showed the wait.
    $unavailable = $this->client(new Response(503, ['Retry-After' => '30'], '{"detail": "Inventory queue is unavailable"}'))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('unavailable', $unavailable->status);
    $this->assertSame('Inventory queue is unavailable', $unavailable->message);
    $this->assertSame([], $unavailable->diagnostics);

    // A "message" beside the detail is not the contract's message.
    $proxy = $this->respond(403, '{"message": "Forbidden by the gateway", "detail": "Missing permission: site:sync"}');
    $this->assertSame('auth_error', $proxy->status);
    $this->assertSame('Missing permission: site:sync', $proxy->message);
    $this->assertSame('Authentication failed. Check the API key and retry.', $this->respond(403, '{"message": "Forbidden"}')->message);

    foreach ([400 => 'invalid_response', 404 => 'not_found', 429 => 'rate_limited', 502 => 'server_error'] as $status => $outcome) {
      $html = $this->respond($status, '<html>error_code: site_url_mismatch</html>');
      $this->assertSame($outcome, $html->status);
      $this->assertSame([], $html->diagnostics);
    }
  }

  /**
   * A 200 shows Sentinel's message when it has a usable one.
   *
   * @dataProvider successBodies
   */
  #[DataProvider('successBodies')]
  public function testSuccessMessage(string $body, string $message): void {
    $result = $this->push(new Response(200, [], $body));

    $this->assertSame('success', $result->status);
    $this->assertSame($message, $result->message);
    $this->assertSame(LogLevel::INFO, $this->logs[0][0]);
    $this->assertSame($message, $this->logs[0][2]['@message']);
  }

  /**
   * Bodies of a 200 with, without and with an unusable message.
   *
   * @return array<string, array{string, string}>
   *   The raw body and the expected message.
   */
  public static function successBodies(): array {
    return [
      'message' => [
        '{"message": "Successfully synced 42 modules", "modules_processed": 42}',
        'Successfully synced 42 modules',
      ],
      'no message' => ['{"modules_processed": 42}', 'Sync completed.'],
      'empty object' => ['{}', 'Sync completed.'],
      'empty message' => ['{"message": "  "}', 'Sync completed.'],
      'message of the wrong type' => ['{"message": ["Inventory updated"]}', 'Sync completed.'],
      'message object' => ['{"message": {"message": "Inventory updated"}}', 'Sync completed.'],
      'message is one bounded line' => [
        (string) json_encode(['message' => "Inventory\nupdated" . str_repeat('x', 600)]),
        'Inventory updated' . str_repeat('x', 483),
      ],
    ];
  }

  /**
   * Sentinel's own error codes, statuses and bodies.
   *
   * @return array<string, array{status: int, retry_after: int|null, body: array<string, mixed>}>
   *   The rows of tests/fixtures/sync_error_codes.json, keyed by error code.
   */
  private static function fixture(): array {
    $json = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/sync_error_codes.json');
    return json_decode($json, TRUE, 16, JSON_THROW_ON_ERROR)['codes'];
  }

  /**
   * Sends one response through the client alone.
   */
  private function respond(int $status, string $body): SyncResult {
    return $this->client(new Response($status, [], $body))->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
  }

  /**
   * Builds a client with a fixed clock whose Guzzle stack returns the response.
   */
  private function client(Response $response): SentinelClient {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(self::NOW);
    return new SentinelClient(new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]), $time);
  }

  /**
   * Runs one push through the sync service and the real client.
   *
   * The log entries are collected in $this->logs, the state in
   * $this->stateValues. Both start empty.
   */
  private function push(Response $response): SyncResult {
    $this->stateValues = [];
    $this->logs = [];
    $settings = [
      'api_base_url' => 'https://sentinel.example.com',
      'site_uuid' => '11111111-1111-4111-8111-111111111111',
      'site_url' => 'https://example.com',
      'site_name' => 'Example',
      'report_scope' => 'all',
      'enabled' => TRUE,
    ];
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $builder = $this->createMock(PayloadBuilder::class);
    $builder->method('build')->willReturn(['modules' => []]);
    $resolver = $this->createMock(ApiKeyResolver::class);
    $resolver->method('getKey')->willReturn('SECRET');

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);
    $time->method('getCurrentTime')->willReturn(self::NOW);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(function (string $level, string|\Stringable $message, array $context = []): void {
      $this->logs[] = [$level, (string) $message, $context];
    });

    return (new SyncService($configFactory, $this->state(), $builder, $resolver, $this->client($response), $time, $logger))->sync(SyncService::TRIGGER_CRON);
  }

  /**
   * Builds a state store kept in $this->stateValues.
   */
  private function state(): StateInterface {
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
    return $state;
  }

}
