<?php

namespace Drupal\sentinel_connector;

use Drupal\Component\Datetime\TimeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;

/**
 * Sends a sync payload to the Sentinel backend.
 *
 * Nothing is logged here. Every outcome is returned as a SyncResult, and
 * SyncService writes the one log entry of a push.
 */
class SentinelClient {

  /**
   * The error code Sentinel sends when a plan's push limit is reached.
   */
  public const PUSH_LIMIT_ERROR_CODE = 'push_limit_reached';

  /**
   * The error code Sentinel sends when the subscription is not active.
   */
  public const SUBSCRIPTION_ERROR_CODE = 'subscription_inactive';

  /**
   * The outcome of every error code of the sync endpoint's error contract.
   *
   * A response with one of these codes is classified by the code alone: the
   * HTTP status and the wording of the message are not consulted, except for
   * the codes in STATUS_BOUND_ERROR_CODES. A response without a code, or with
   * a code that is not listed, is classified by its status and the shape of
   * its body, as before the contract.
   *
   * @var array<string, string>
   */
  public const ERROR_CODE_OUTCOMES = [
    'site_url_mismatch' => 'site_mismatch',
    'site_uuid_mismatch' => 'site_mismatch',
    'module_validation_failed' => 'validation_failed',
    'module_not_on_drupal_org' => 'validation_failed',
    'body_unreadable' => 'invalid_payload',
    'authentication_required' => 'auth_error',
    'api_key_invalid' => 'auth_error',
    'api_key_inactive' => 'auth_error',
    'permission_denied' => 'auth_error',
    // Not a mistake: Sentinel's code "site_mismatch" means the API key belongs
    // to another site, which is an authentication failure. It only shares its
    // name with this module's "site_mismatch" outcome, which is for a site URL
    // or UUID that differs (codes site_url_mismatch and site_uuid_mismatch).
    'site_mismatch' => 'auth_error',
    'signature_missing' => 'signature_refused',
    'signature_invalid' => 'signature_refused',
    'timestamp_skew' => 'signature_refused',
    'replay_detected' => 'signature_refused',
    self::SUBSCRIPTION_ERROR_CODE => SyncResult::SUBSCRIPTION_INACTIVE,
    'site_unavailable' => 'not_found',
    'catalog_conflict' => 'conflict',
    'catalog_entry_unavailable' => 'conflict',
    'payload_invalid' => 'invalid_payload',
    'json_invalid' => 'invalid_payload',
    self::PUSH_LIMIT_ERROR_CODE => SyncResult::PUSH_LIMITED,
    'site_rate_limited' => 'rate_limited',
    'auth_rate_limited' => 'rate_limited',
    'rate_limiter_unavailable' => 'unavailable',
    'replay_protection_unavailable' => 'unavailable',
    'queue_unavailable' => 'unavailable',
    'inventory_busy' => 'unavailable',
    'auth_rate_limiter_unavailable' => 'unavailable',
    'internal_error' => 'server_error',
  ];

  /**
   * The only HTTP status on which some error codes decide the outcome.
   *
   * These two outcomes make this site hold back later pushes: a push limit
   * stores the time of the next push, an inactive subscription holds cron for
   * a day. They are therefore honoured only on the status Sentinel sends them
   * with. On any other status the response is classified without the code.
   *
   * @var array<string, int>
   */
  public const STATUS_BOUND_ERROR_CODES = [
    self::PUSH_LIMIT_ERROR_CODE => 429,
    self::SUBSCRIPTION_ERROR_CODE => 402,
  ];

  /**
   * The most characters of a message that is shown, logged and stored.
   */
  protected const MAX_MESSAGE_LENGTH = 500;

  /**
   * Message used when a push-limit response carries no usable message.
   */
  public const PUSH_LIMIT_FALLBACK_MESSAGE = 'The push limit for this site has been reached.';

  /**
   * How many rejected module names a validation message lists.
   */
  protected const MAX_LISTED_MODULES = 5;

  /**
   * Constructs the client.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Component\Datetime\TimeInterface|null $time
   *   The time service, used to tell whether a Retry-After date has passed.
   *   The system clock is used when it is not given.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected ?TimeInterface $time = NULL,
  ) {}

  /**
   * POST the payload to Sentinel and map the response to a SyncResult.
   *
   * @param string $baseUrl
   *   Sentinel base URL, e.g. https://sentinel.example.com.
   * @param string $siteUuid
   *   Site UUID; also the path segment.
   * @param string $apiKey
   *   Plaintext API key for the X-API-Key header.
   * @param array<string, mixed> $payload
   *   The full DrupalSiteSync-shaped payload.
   */
  public function sync(string $baseUrl, string $siteUuid, string $apiKey, array $payload): SyncResult {
    $url = rtrim($baseUrl, '/') . '/api/v1/sites/' . $siteUuid . '/modules/sync';
    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'X-API-Key' => $apiKey,
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'json' => $payload,
        'timeout' => 30,
        'http_errors' => FALSE,
      ]);
    }
    catch (TransferException $e) {
      $message = 'Could not connect to Sentinel. Check the API URL and network, then retry.';
      // Never keep the request URL, exception message, headers, or payload.
      $host = parse_url($baseUrl, PHP_URL_HOST);
      $host = is_string($host) && preg_match('/^[a-zA-Z0-9.\-:]+$/D', $host) ? $host : 'unknown';
      $context = method_exists($e, 'getHandlerContext') ? $e->getHandlerContext() : [];
      return SyncResult::failure('transport_error', NULL, $message, [
        'class' => get_class($e),
        'host' => $host,
        'curl errno' => (int) ($context['errno'] ?? 0),
      ]);
    }

    $code = $response->getStatusCode();
    // Reverse proxies may return HTML for any error, so the body may not be
    // JSON at all.
    $decoded = json_decode((string) $response->getBody(), TRUE);
    $retry = $this->retryAfter($response->getHeaderLine('Retry-After'));
    // An error that names a known error code is classified by that code.
    $error = $code >= 400 ? $this->errorBody($decoded) : NULL;
    $errorCode = $error !== NULL ? (string) $error['error_code'] : '';
    if ($error !== NULL && $this->decidesOutcome($errorCode, $code)) {
      return $this->codedRefusal($code, $error, !isset($decoded['error_code']), $retry);
    }
    $result = $this->classify($code, $decoded, $retry);
    if ($error === NULL) {
      return $result;
    }
    // A code that did not decide the outcome is kept for the log entry: one
    // this version does not know, or one sent with a status it is not
    // honoured on.
    return $result->withDiagnostics([
      (isset(self::ERROR_CODE_OUTCOMES[$errorCode]) ? 'ignored error code' : 'unknown error code') => $errorCode,
    ]);
  }

  /**
   * Whether an error code decides the outcome of a response.
   *
   * @param string $errorCode
   *   The error code, a safe token.
   * @param int $code
   *   The HTTP status code.
   */
  protected function decidesOutcome(string $errorCode, int $code): bool {
    if (!isset(self::ERROR_CODE_OUTCOMES[$errorCode])) {
      return FALSE;
    }
    return !isset(self::STATUS_BOUND_ERROR_CODES[$errorCode]) || self::STATUS_BOUND_ERROR_CODES[$errorCode] === $code;
  }

  /**
   * Builds the result of an error response whose error code decides it.
   *
   * @param int $code
   *   The HTTP status code. It is reported, but does not decide the outcome.
   * @param array<array-key, mixed> $data
   *   The error object that carries the error code.
   * @param bool $nested
   *   TRUE when the error object was found under "detail".
   * @param array{seconds: int|null, time: int|null} $retry
   *   The wait of the Retry-After header.
   */
  protected function codedRefusal(int $code, array $data, bool $nested, array $retry): SyncResult {
    $errorCode = (string) $data['error_code'];
    $outcome = self::ERROR_CODE_OUTCOMES[$errorCode];
    $diagnostics = ['error code' => $errorCode];
    if ($outcome === SyncResult::PUSH_LIMITED) {
      return $this->pushLimit($data, $retry)->withDiagnostics($diagnostics);
    }
    if ($outcome === SyncResult::SUBSCRIPTION_INACTIVE) {
      $reason = $data['reason'] ?? NULL;
      $reason = is_string($reason) && preg_match('/^[a-z0-9_]{1,32}$/D', $reason) ? $reason : NULL;
      return SyncResult::subscriptionInactive($this->cleanMessage($data['message'] ?? NULL) ?? '', $reason)->withDiagnostics($diagnostics);
    }
    // A nested error object holds its extra fields, such as the rejected
    // modules, beside the error code.
    $detail = $nested ? ($data['detail'] ?? $data) : ($data['detail'] ?? NULL);
    $listed = is_array($detail) && is_array($detail['invalid_modules'] ?? NULL);
    if ($outcome === 'validation_failed' && !$listed && is_array($data['invalid_modules'] ?? NULL)) {
      // The rejected modules are beside the error code, not in its detail.
      $words = is_array($detail) && !array_is_list($detail) ? $detail : ['message' => $detail];
      $detail = ['invalid_modules' => $data['invalid_modules']] + $words;
    }
    // Only these outcomes show how long to wait.
    $wait = in_array($outcome, ['rate_limited', 'unavailable'], TRUE) ? $this->retryAfterText($retry) : '';
    $message = $this->cleanMessage($data['message'] ?? NULL);
    if ($message !== NULL && !preg_match('/[\p{L}\p{N}]/u', $message)) {
      // Punctuation alone says nothing: it counts as no message.
      $message = NULL;
    }
    return SyncResult::failure($outcome, $code, $this->codedText($outcome, $code, $detail, $message, $wait), $diagnostics);
  }

  /**
   * Builds the text of an error whose error code decided the outcome.
   *
   * Sentinel's message says what to do, so none of this module's own advice
   * is added to it. What the message cannot hold follows as sentences of
   * their own: the rejected modules, the first field at fault and the wait.
   * The message is shortened to make room, so the whole text stays within
   * the length limit. Every part is cleaned, and so is the whole.
   *
   * @param string $status
   *   The outcome, e.g. 'not_found'.
   * @param int $code
   *   The HTTP status code.
   * @param mixed $detail
   *   The "detail" member of the error object, or NULL.
   * @param string|null $message
   *   The cleaned "message" of the error object, or NULL when it has none.
   * @param string $wait
   *   The sentence that says how long to wait, or an empty string.
   */
  protected function codedText(string $status, int $code, mixed $detail, ?string $message, string $wait): string {
    if ($message === NULL) {
      // No message: the words of an older server, or this module's own.
      return mb_substr($this->refusalText($status, $code, $detail, $wait), 0, self::MAX_MESSAGE_LENGTH);
    }
    $additions = [];
    if ($status === 'validation_failed' && is_array($detail)) {
      $additions[] = $this->rejectedModules($detail);
    }
    if (is_array($detail) && $detail !== [] && array_is_list($detail)) {
      $field = $this->firstValidationError($detail, TRUE);
      $additions[] = $field !== NULL ? 'First field at fault: ' . rtrim(mb_substr($field, 0, 150), '. ') . '.' : '';
    }
    $additions[] = $wait;
    $tail = implode(' ', array_filter($additions, fn (string $sentence): bool => $sentence !== ''));
    if ($tail === '') {
      return $message;
    }
    // A message that ends a sentence itself gets no full stop.
    if (!preg_match('/[.?!\x{2026}]\z/u', $message)) {
      $message .= '.';
    }
    $message = $this->shorten($message, max(1, self::MAX_MESSAGE_LENGTH - 1 - mb_strlen($tail)));
    return (string) $this->cleanMessage($message . ' ' . $tail);
  }

  /**
   * Shortens a text to a length, at a word boundary where there is one.
   *
   * @param string $text
   *   The cleaned text.
   * @param int $length
   *   The most characters to return, at least 1.
   *
   * @return string
   *   The text, ending in an ellipsis when it was shortened.
   */
  protected function shorten(string $text, int $length): string {
    if (mb_strlen($text) <= $length) {
      return $text;
    }
    $cut = mb_substr($text, 0, $length - 1);
    $space = mb_strrpos($cut, ' ');
    if ($space !== FALSE && $space > 0) {
      $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, ' .,;:') . "\u{2026}";
  }

  /**
   * Classifies a response by its HTTP status and the shape of its body.
   *
   * This is how a response is read whose error code does not decide the
   * outcome: one from a Sentinel released before the error contract, or from
   * a reverse proxy. The HTTP status is classified first; a JSON body only
   * adds the server's own words.
   *
   * @param int $code
   *   The HTTP status code.
   * @param mixed $decoded
   *   The decoded response body, or NULL when it is not JSON.
   * @param array{seconds: int|null, time: int|null} $retry
   *   The wait of the Retry-After header.
   */
  protected function classify(int $code, mixed $decoded, array $retry): SyncResult {
    $detail = is_array($decoded) ? ($decoded['detail'] ?? NULL) : NULL;
    if ($code === 429) {
      return $this->refusalResult('rate_limited', 429, $detail, $this->retryAfterText($retry));
    }
    $refusal = $this->refusal($code, $detail);
    if ($refusal !== NULL) {
      return $refusal;
    }
    if (!is_array($decoded)) {
      return SyncResult::failure('invalid_response', $code, 'Sentinel returned an invalid JSON response. Check the API base URL and retry.');
    }

    if ($code === 200) {
      return SyncResult::success(200, $this->cleanMessage($decoded['message'] ?? NULL) ?? 'Sync completed.');
    }
    if ($code === 202) {
      $taskId = $decoded['task_id'] ?? NULL;
      if (!is_string($taskId) || !preg_match('/^[A-Za-z0-9_.:\-]{1,128}$/D', $taskId)) {
        return SyncResult::failure('invalid_response', 202, 'Sentinel accepted the sync without a task ID.');
      }
      return SyncResult::accepted($taskId);
    }

    return $this->refusalResult('rejected', $code, $detail);
  }

  /**
   * Maps the error statuses Sentinel is known to send to their own outcomes.
   *
   * @param int $code
   *   The HTTP status code.
   * @param mixed $detail
   *   The "detail" member of the response body, or NULL.
   *
   * @return \Drupal\sentinel_connector\SyncResult|null
   *   The outcome, or NULL when the status has no outcome of its own.
   */
  protected function refusal(int $code, mixed $detail): ?SyncResult {
    if ($code === 401 || $code === 403) {
      // A refused signature carries a machine-readable code.
      $errorCode = is_array($detail) ? ($detail['code'] ?? NULL) : NULL;
      $diagnostics = is_string($errorCode) && preg_match('/^[a-z0-9_]{1,64}$/D', $errorCode) ? ['code' => $errorCode] : [];
      return $this->refusalResult($code === 401 && $diagnostics !== [] ? 'signature_refused' : 'auth_error', $code, $detail, '', $diagnostics);
    }
    if ($code === 400) {
      if (is_array($detail) && (isset($detail['invalid_modules']) || isset($detail['error']))) {
        return $this->refusalResult('validation_failed', 400, $detail);
      }
      if (is_string($detail) && preg_match('/mismatch|does not match/i', $detail)) {
        return $this->refusalResult('site_mismatch', 400, $detail);
      }
      return NULL;
    }
    $status = match (TRUE) {
      $code === 404 => 'not_found',
      $code === 409 => 'conflict',
      $code === 422 => 'invalid_payload',
      $code === 503 => 'unavailable',
      $code >= 500 => 'server_error',
      default => NULL,
    };
    return $status !== NULL ? $this->refusalResult($status, $code, $detail) : NULL;
  }

  /**
   * Builds the failure of an outcome from the "detail" of the response.
   *
   * @param string $status
   *   The outcome, e.g. 'not_found'.
   * @param int $code
   *   The HTTP status code.
   * @param mixed $detail
   *   The "detail" member of the response body, or NULL.
   * @param string $wait
   *   The sentence that says how long to wait, or an empty string.
   * @param array<string, int|string> $diagnostics
   *   Facts for the log entry only.
   */
  protected function refusalResult(string $status, int $code, mixed $detail, string $wait = '', array $diagnostics = []): SyncResult {
    return SyncResult::failure($status, $code, $this->refusalText($status, $code, $detail, $wait), $diagnostics);
  }

  /**
   * Builds the text of an outcome from the "detail" of the response.
   *
   * The detail of a Sentinel released before the error contract is a short
   * label, so this module adds its own advice to some outcomes.
   *
   * @param string $status
   *   The outcome, e.g. 'not_found'.
   * @param int $code
   *   The HTTP status code.
   * @param mixed $detail
   *   The "detail" member of the response body, or NULL.
   * @param string $wait
   *   The sentence that says how long to wait, or an empty string.
   */
  protected function refusalText(string $status, int $code, mixed $detail, string $wait = ''): string {
    $text = fn (string $fallback): string => $this->detailMessage($detail, $fallback);
    $advised = fn (string $fallback, string $advice): string => rtrim($text($fallback), '.') . '. ' . $advice;
    return match ($status) {
      'site_mismatch' => $advised('The site does not match.', 'Check the site URL and site UUID against the site registered in Sentinel.'),
      'validation_failed' => is_array($detail) && !array_is_list($detail)
        ? $this->validationMessage($detail)
        : $text('Sentinel rejected one or more modules.'),
      'signature_refused' => $text('Sentinel refused the request signature. Check the site clock and the API key.'),
      'auth_error' => $text('Authentication failed. Check the API key and retry.'),
      'not_found' => $advised('Sentinel did not find this site.', 'Check the API base URL and site UUID.'),
      'conflict' => $text('Sentinel could not store the inventory because of a conflict.'),
      'invalid_payload' => $text('Sentinel could not read the inventory.'),
      'rate_limited' => rtrim($text('Sentinel rate limit reached.'), '.') . '.' . ($wait !== '' ? ' ' . $wait : ''),
      'unavailable' => $wait !== ''
        ? rtrim($text('Sentinel is temporarily unavailable.'), '.') . '. ' . $wait
        : $text('Sentinel is temporarily unavailable. Retry later.'),
      'server_error' => $text('Sentinel server returned HTTP ' . $code . '. Retry later.'),
      default => $text('Sentinel rejected the sync.'),
    };
  }

  /**
   * Reads a Retry-After header.
   *
   * Only the two forms of RFC 7231 count: a positive number of whole seconds,
   * and an HTTP date (IMF-fixdate, GMT) that lies in the future. Relative
   * words, signed numbers, zero and dates in the past give no wait.
   *
   * @param string $header
   *   The Retry-After header value, or an empty string.
   *
   * @return array{seconds: int|null, time: int|null}
   *   The seconds to wait or the Unix timestamp to wait for. Both are NULL
   *   when the header is missing or unusable.
   */
  protected function retryAfter(string $header): array {
    $header = trim($header);
    $none = ['seconds' => NULL, 'time' => NULL];
    if (preg_match('/\A[0-9]{1,9}\z/', $header)) {
      return (int) $header > 0 ? ['seconds' => (int) $header, 'time' => NULL] : $none;
    }
    $format = 'D, d M Y H:i:s \G\M\T';
    $date = \DateTimeImmutable::createFromFormat('!' . $format, $header, new \DateTimeZone('UTC'));
    // The date must read back as it was sent: no overflow, no other format.
    if ($date === FALSE || $date->format($format) !== $header) {
      return $none;
    }
    $now = $this->time?->getCurrentTime() ?? time();
    return $date->getTimestamp() > $now ? ['seconds' => NULL, 'time' => $date->getTimestamp()] : $none;
  }

  /**
   * Turns the wait of a Retry-After header into a sentence.
   *
   * @param array{seconds: int|null, time: int|null} $retry
   *   The wait of the Retry-After header.
   *
   * @return string
   *   The sentence, or an empty string when there is no wait.
   */
  protected function retryAfterText(array $retry): string {
    if ($retry['seconds'] !== NULL) {
      return 'Retry after ' . $retry['seconds'] . ' seconds.';
    }
    return $retry['time'] !== NULL ? 'Retry after ' . gmdate('c', $retry['time']) . '.' : '';
  }

  /**
   * Describes a module validation refusal: how many modules, and which.
   *
   * @param array<array-key, mixed> $detail
   *   The "detail" object of the response.
   */
  protected function validationMessage(array $detail): string {
    $message = $this->cleanMessage($detail['message'] ?? NULL)
      ?? $this->cleanMessage($detail['error'] ?? NULL)
      ?? 'Sentinel rejected one or more modules.';
    $modules = $this->rejectedModules($detail);
    return $modules !== '' ? (string) $this->cleanMessage(rtrim($message, '.') . '. ' . $modules) : $message;
  }

  /**
   * Lists the modules Sentinel rejected: how many, and the first few names.
   *
   * @param array<array-key, mixed> $detail
   *   The "detail" object of the response.
   *
   * @return string
   *   A sentence, or an empty string when the detail lists no modules.
   */
  protected function rejectedModules(array $detail): string {
    $invalid = is_array($detail['invalid_modules'] ?? NULL) ? $detail['invalid_modules'] : [];
    if ($invalid === []) {
      return '';
    }
    $names = [];
    foreach (array_slice($invalid, 0, self::MAX_LISTED_MODULES) as $module) {
      $name = $this->cleanMessage(is_array($module) ? ($module['module_name'] ?? NULL) : $module);
      $names[] = $name !== NULL ? mb_substr($name, 0, 64) : 'unnamed';
    }
    $count = count($invalid);
    $more = $count - count($names);
    return 'Rejected modules (' . $count . '): ' . implode(', ', $names) . ($more > 0 ? ' and ' . $more . ' more.' : '.');
  }

  /**
   * Maps a plan push-limit response to a typed result.
   *
   * Every field besides the error code is optional: a missing or malformed
   * value falls back to the Retry-After header and a generic message.
   *
   * @param array<array-key, mixed> $data
   *   The error object that carries the push-limit error code.
   * @param array{seconds: int|null, time: int|null} $retry
   *   The wait of the Retry-After header.
   */
  protected function pushLimit(array $data, array $retry): SyncResult {
    $message = $this->cleanMessage($data['message'] ?? NULL) ?? self::PUSH_LIMIT_FALLBACK_MESSAGE;

    $seconds = $data['retry_after_seconds'] ?? NULL;
    $seconds = is_int($seconds) && $seconds > 0 ? $seconds : $retry['seconds'];
    $nextAllowedAt = $this->timestamp($data['next_allowed_at'] ?? NULL) ?? $retry['time'];

    $plan = $data['plan'] ?? NULL;
    $plan = is_string($plan) && preg_match('/^[a-zA-Z0-9_\-]{1,32}$/D', $plan) ? $plan : NULL;
    $limit = $data['limit'] ?? NULL;
    $limit = is_int($limit) && $limit > 0 ? $limit : NULL;

    return SyncResult::pushLimited($message, $nextAllowedAt, $seconds, $plan, $limit);
  }

  /**
   * Returns the error object of a response body that names an error code.
   *
   * @param mixed $decoded
   *   The decoded response body, or NULL when it is not JSON.
   *
   * @return array<array-key, mixed>|null
   *   The object whose "error_code" is a safe token, or NULL when the body
   *   names no usable error code.
   */
  protected function errorBody(mixed $decoded): ?array {
    if (!is_array($decoded)) {
      return NULL;
    }
    // FastAPI nests the body of a raised HTTP error under "detail".
    $data = isset($decoded['error_code']) || !is_array($decoded['detail'] ?? NULL) ? $decoded : $decoded['detail'];
    $errorCode = $data['error_code'] ?? NULL;
    // Anchored at the very end: a trailing newline does not pass.
    return is_string($errorCode) && preg_match('/\A[a-z0-9_]{1,64}\z/', $errorCode) ? $data : NULL;
  }

  /**
   * Reduces server text to one bounded line.
   *
   * The text is shown to administrators and stored, so control characters
   * are removed and the length is capped. It is still escaped on output.
   *
   * @param mixed $message
   *   The message from the response body.
   *
   * @return string|null
   *   The cleaned message, or NULL when there is no usable text.
   */
  protected function cleanMessage(mixed $message): ?string {
    if (!is_string($message)) {
      return NULL;
    }
    // Control and format characters, including line and bidi overrides.
    $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $message));
    return $clean !== '' ? mb_substr($clean, 0, self::MAX_MESSAGE_LENGTH) : NULL;
  }

  /**
   * Parses an ISO 8601 date-time into a Unix timestamp.
   *
   * @param mixed $value
   *   The value from the response body. A value without an offset is UTC.
   *
   * @return int|null
   *   The timestamp, or NULL when the value is not a usable date-time.
   */
  protected function timestamp(mixed $value): ?int {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+\-]\d{2}:?\d{2})?$/D', $value)) {
      return NULL;
    }
    try {
      $timestamp = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
    }
    catch (\Exception) {
      return NULL;
    }
    return $timestamp > 0 ? $timestamp : NULL;
  }

  /**
   * Turns the "detail" of an error response into one bounded line.
   *
   * FastAPI sends a string, an object with a "message", or a list of
   * validation errors. An object or list is never encoded into the message.
   *
   * @param mixed $detail
   *   The "detail" member of the response body.
   * @param string $fallback
   *   The message used when the detail holds no usable text.
   */
  protected function detailMessage(mixed $detail, string $fallback): string {
    if (is_string($detail)) {
      return $this->cleanMessage($detail) ?? $fallback;
    }
    if (!is_array($detail) || $detail === []) {
      return $fallback;
    }
    if (!array_is_list($detail)) {
      return $this->cleanMessage($detail['message'] ?? NULL) ?? $fallback;
    }
    $error = $this->firstValidationError($detail);
    return $error !== NULL ? (string) $this->cleanMessage(rtrim($fallback, '.') . ': ' . $error) : $fallback;
  }

  /**
   * Describes a list of validation errors: the first one, and how many follow.
   *
   * @param non-empty-list<mixed> $detail
   *   The "detail" list of the response.
   * @param bool $bare
   *   TRUE to leave out a full stop at the end of the error's own words, for
   *   a caller that ends the sentence itself.
   *
   * @return string|null
   *   The field at fault and what is wrong with it, e.g.
   *   "body.modules.0.version: Field required (and 1 more)", or NULL when the
   *   first error holds no usable text. Both parts are cleaned.
   */
  protected function firstValidationError(array $detail, bool $bare = FALSE): ?string {
    $first = $detail[0];
    $text = $this->cleanMessage(is_array($first) ? ($first['msg'] ?? NULL) : $first);
    if ($text !== NULL && $bare) {
      $text = $this->cleanMessage(rtrim($text, '. '));
    }
    if ($text === NULL) {
      return NULL;
    }
    // The location is server text like the words: it is cleaned as well.
    $loc = is_array($first) && is_array($first['loc'] ?? NULL) ? implode('.', array_filter($first['loc'], 'is_scalar')) : '';
    $loc = $this->cleanMessage($loc) ?? '';
    $more = count($detail) - 1;
    return ($loc !== '' ? $loc . ': ' : '') . $text . ($more > 0 ? ' (and ' . $more . ' more)' : '');
  }

}
