<?php

namespace Drupal\sentinel_connector;

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
   * Message used when a push-limit response carries no usable message.
   */
  public const PUSH_LIMIT_FALLBACK_MESSAGE = 'The push limit for this site has been reached.';

  /**
   * How many rejected module names a validation message lists.
   */
  protected const MAX_LISTED_MODULES = 5;

  public function __construct(
    protected ClientInterface $httpClient,
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
      $host = is_string($host) && preg_match('/^[a-zA-Z0-9.\-:]+$/', $host) ? $host : 'unknown';
      $context = method_exists($e, 'getHandlerContext') ? $e->getHandlerContext() : [];
      return SyncResult::failure('transport_error', NULL, $message, [
        'class' => get_class($e),
        'host' => $host,
        'curl errno' => (int) ($context['errno'] ?? 0),
      ]);
    }

    $code = $response->getStatusCode();
    $body = (string) $response->getBody();
    // Reverse proxies may return HTML for any error. The HTTP status is
    // classified first; a JSON body only adds the server's own words.
    $decoded = json_decode($body, TRUE);
    $detail = is_array($decoded) ? ($decoded['detail'] ?? NULL) : NULL;
    if ($code === 429) {
      $retry = $response->getHeaderLine('Retry-After');
      $pushLimit = $this->pushLimit($body, $retry);
      if ($pushLimit !== NULL) {
        return $pushLimit;
      }
      $message = rtrim($this->detailMessage($detail, 'Sentinel rate limit reached.'), '.') . '.';
      if (ctype_digit($retry)) {
        $message .= ' Retry after ' . $retry . ' seconds.';
      }
      elseif ($retry !== '' && strtotime($retry) !== FALSE) {
        $message .= ' Retry after ' . gmdate('c', strtotime($retry)) . '.';
      }
      return SyncResult::failure('rate_limited', 429, $message);
    }
    // A refusal over billing is its own outcome. Other 402 bodies fall through.
    if ($code === 402) {
      $data = $this->errorBody($body, self::SUBSCRIPTION_ERROR_CODE);
      if ($data !== NULL) {
        $reason = $data['reason'] ?? NULL;
        $reason = is_string($reason) && preg_match('/^[a-z0-9_]{1,32}$/', $reason) ? $reason : NULL;
        return SyncResult::subscriptionInactive($this->cleanMessage($data['message'] ?? NULL) ?? '', $reason);
      }
    }
    $refusal = $this->refusal($code, $detail);
    if ($refusal !== NULL) {
      return $refusal;
    }
    if (!is_array($decoded)) {
      return SyncResult::failure('invalid_response', $code, 'Sentinel returned an invalid JSON response. Check the API base URL and retry.');
    }

    if ($code === 200) {
      return SyncResult::success(200, $this->detailMessage($decoded['message'] ?? NULL, 'Sync completed.'));
    }
    if ($code === 202) {
      $taskId = $decoded['task_id'] ?? NULL;
      if (!is_string($taskId) || !preg_match('/^[A-Za-z0-9_.:\-]{1,128}$/', $taskId)) {
        return SyncResult::failure('invalid_response', 202, 'Sentinel accepted the sync without a task ID.');
      }
      return SyncResult::accepted($taskId);
    }

    return SyncResult::failure('rejected', $code, $this->detailMessage($detail, 'Sentinel rejected the sync.'));
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
      $diagnostics = is_string($errorCode) && preg_match('/^[a-z0-9_]{1,64}$/', $errorCode) ? ['code' => $errorCode] : [];
      return SyncResult::failure('auth_error', $code, $this->detailMessage($detail, 'Authentication failed. Check the API key and retry.'), $diagnostics);
    }
    if ($code === 400) {
      if (is_array($detail) && (isset($detail['invalid_modules']) || isset($detail['error']))) {
        return SyncResult::failure('validation_failed', 400, $this->validationMessage($detail));
      }
      if (is_string($detail) && preg_match('/mismatch|does not match/i', $detail)) {
        return SyncResult::failure('site_mismatch', 400, rtrim($this->detailMessage($detail, 'The site does not match.'), '.') . '. Check the site URL and site UUID against the site registered in Sentinel.');
      }
      return NULL;
    }
    return match (TRUE) {
      $code === 404 => SyncResult::failure('not_found', 404, rtrim($this->detailMessage($detail, 'Sentinel did not find this site.'), '.') . '. Check the API base URL and site UUID.'),
      $code === 409 => SyncResult::failure('conflict', 409, $this->detailMessage($detail, 'Sentinel could not store the inventory because of a conflict.')),
      $code === 422 => SyncResult::failure('invalid_payload', 422, $this->detailMessage($detail, 'Sentinel could not read the inventory.')),
      $code === 503 => SyncResult::failure('unavailable', 503, $this->detailMessage($detail, 'Sentinel is temporarily unavailable. Retry later.')),
      $code >= 500 => SyncResult::failure('server_error', $code, $this->detailMessage($detail, 'Sentinel server returned HTTP ' . $code . '. Retry later.')),
      default => NULL,
    };
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
    $invalid = is_array($detail['invalid_modules'] ?? NULL) ? $detail['invalid_modules'] : [];
    if ($invalid === []) {
      return $message;
    }
    $names = [];
    foreach (array_slice($invalid, 0, self::MAX_LISTED_MODULES) as $module) {
      $name = $this->cleanMessage(is_array($module) ? ($module['module_name'] ?? NULL) : $module);
      $names[] = $name !== NULL ? mb_substr($name, 0, 64) : 'unnamed';
    }
    $count = count($invalid);
    $message = rtrim($message, '.') . '. Rejected modules (' . $count . '): ' . implode(', ', $names);
    $more = $count - count($names);
    return (string) $this->cleanMessage($message . ($more > 0 ? ' and ' . $more . ' more.' : '.'));
  }

  /**
   * Maps a plan push-limit response to a typed result.
   *
   * Only a JSON body carrying the push-limit error code counts. Every other
   * field is optional: a missing or malformed value falls back to the
   * Retry-After header and a generic message.
   *
   * @param string $body
   *   The raw response body.
   * @param string $retryAfter
   *   The Retry-After header value, or an empty string.
   *
   * @return \Drupal\sentinel_connector\SyncResult|null
   *   The push-limit result, or NULL when this is another kind of 429.
   */
  protected function pushLimit(string $body, string $retryAfter): ?SyncResult {
    $data = $this->errorBody($body, self::PUSH_LIMIT_ERROR_CODE);
    if ($data === NULL) {
      return NULL;
    }
    $message = $this->cleanMessage($data['message'] ?? NULL) ?? self::PUSH_LIMIT_FALLBACK_MESSAGE;

    $nextAllowedAt = $this->timestamp($data['next_allowed_at'] ?? NULL);
    $seconds = $data['retry_after_seconds'] ?? NULL;
    $seconds = is_int($seconds) && $seconds > 0 ? $seconds : NULL;
    $retryAfter = trim($retryAfter);
    if ($seconds === NULL && ctype_digit($retryAfter) && strlen($retryAfter) <= 9 && (int) $retryAfter > 0) {
      $seconds = (int) $retryAfter;
    }
    if ($nextAllowedAt === NULL && $retryAfter !== '' && !ctype_digit($retryAfter)) {
      // Retry-After may also be an HTTP date.
      $date = strtotime($retryAfter);
      $nextAllowedAt = $date !== FALSE && $date > 0 ? $date : NULL;
    }

    $plan = $data['plan'] ?? NULL;
    $plan = is_string($plan) && preg_match('/^[a-zA-Z0-9_\-]{1,32}$/', $plan) ? $plan : NULL;
    $limit = $data['limit'] ?? NULL;
    $limit = is_int($limit) && $limit > 0 ? $limit : NULL;

    return SyncResult::pushLimited($message, $nextAllowedAt, $seconds, $plan, $limit);
  }

  /**
   * Returns the structured error body that carries the given error code.
   *
   * @param string $body
   *   The raw response body.
   * @param string $errorCode
   *   The error code the body must carry.
   *
   * @return array<array-key, mixed>|null
   *   The decoded error object, or NULL when the body is something else.
   */
  protected function errorBody(string $body, string $errorCode): ?array {
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    // FastAPI nests the body of a raised HTTP error under "detail".
    $data = isset($decoded['error_code']) || !is_array($decoded['detail'] ?? NULL) ? $decoded : $decoded['detail'];
    return ($data['error_code'] ?? NULL) === $errorCode ? $data : NULL;
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
    $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message));
    return $clean !== '' ? mb_substr($clean, 0, 500) : NULL;
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
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+\-]\d{2}:?\d{2})?$/', $value)) {
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
    // A list of validation errors: the first one, and how many follow.
    $first = $detail[0];
    $text = $this->cleanMessage(is_array($first) ? ($first['msg'] ?? NULL) : $first);
    if ($text === NULL) {
      return $fallback;
    }
    $loc = is_array($first) && is_array($first['loc'] ?? NULL) ? implode('.', array_filter($first['loc'], 'is_scalar')) : '';
    $message = rtrim($fallback, '.') . ': ' . ($loc !== '' ? $loc . ': ' : '') . $text;
    $more = count($detail) - 1;
    return (string) $this->cleanMessage($message . ($more > 0 ? ' (and ' . $more . ' more)' : ''));
  }

}
