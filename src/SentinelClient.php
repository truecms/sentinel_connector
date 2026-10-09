<?php

namespace Drupal\sentinel_connector;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Psr\Log\LoggerInterface;

/**
 * Sends a sync payload to the Sentinel backend.
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

  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
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
      // Never log the request URL, exception message, headers, or payload.
      $host = parse_url($baseUrl, PHP_URL_HOST);
      $host = is_string($host) && preg_match('/^[a-zA-Z0-9.\-:]+$/', $host) ? $host : 'unknown';
      $context = method_exists($e, 'getHandlerContext') ? $e->getHandlerContext() : [];
      $this->logger->error('Sentinel transport failure: @class; host @host; curl errno @errno.', [
        '@class' => get_class($e),
        '@host' => $host,
        '@errno' => (int) ($context['errno'] ?? 0),
      ]);
      return SyncResult::failure('transport_error', NULL, $message);
    }

    $code = $response->getStatusCode();
    $body = (string) $response->getBody();
    // A rate-limit response does not need a JSON body.
    if ($code === 429) {
      $pushLimit = $this->pushLimit($body, $response->getHeaderLine('Retry-After'));
      if ($pushLimit !== NULL) {
        return $pushLimit;
      }
      $retry = $response->getHeaderLine('Retry-After');
      $message = 'Rate limit exceeded (100/hour).';
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
    // Reverse proxies may return HTML for authentication and server errors.
    // Classify their HTTP status before enforcing the success JSON contract.
    if (in_array($code, [401, 403], TRUE) || $code >= 500) {
      $decoded = json_decode($body, TRUE);
      $detail = is_array($decoded) ? ($decoded['detail'] ?? NULL) : NULL;
      if (in_array($code, [401, 403], TRUE)) {
        return SyncResult::failure('auth_error', $code, $this->detail($detail, 'Authentication failed. Check the API key and retry.'));
      }
      return SyncResult::failure('server_error', $code, $this->detail($detail, 'Sentinel server returned HTTP ' . $code . '. Retry later.'));
    }
    try {
      $decoded = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($decoded)) {
        throw new \UnexpectedValueException('Expected a JSON object.');
      }
    }
    catch (\Throwable $e) {
      return SyncResult::failure('invalid_response', $code, 'Sentinel returned an invalid JSON response. Check the API base URL and retry.');
    }

    if ($code === 200) {
      return SyncResult::success(200, $this->detail($decoded['message'] ?? NULL, 'Sync completed.'));
    }
    if ($code === 202) {
      if (!is_string($decoded['task_id'] ?? NULL) || $decoded['task_id'] === '') {
        return SyncResult::failure('invalid_response', 202, 'Sentinel accepted the sync without a task ID.');
      }
      return SyncResult::accepted($decoded['task_id']);
    }

    $detail = $this->detail($decoded['detail'] ?? NULL, 'Sentinel rejected the sync.');
    $this->logger->error('Sentinel sync rejected with HTTP @code.', ['@code' => $code]);
    return SyncResult::failure('rejected', $code, $detail);
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
   * Coerces structured API errors without passing arrays to typed results.
   */
  protected function detail(mixed $detail, string $fallback): string {
    if (is_string($detail) && $detail !== '') {
      return $detail;
    }
    if (is_array($detail)) {
      return json_encode($detail, JSON_INVALID_UTF8_SUBSTITUTE) ?: $fallback;
    }
    return $fallback;
  }

}
