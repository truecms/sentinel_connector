<?php

namespace Drupal\sentinel_connector;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Psr\Log\LoggerInterface;

/**
 * Sends a sync payload to the Sentinel backend.
 */
class SentinelClient {

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
