<?php

namespace Drupal\sentinel_connector;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
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
   * @param array $payload
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
    catch (RequestException $e) {
      $this->logger->error('Sentinel sync transport error: @msg', ['@msg' => $e->getMessage()]);
      return SyncResult::failure('transport_error', NULL, $e->getMessage());
    }

    $code = $response->getStatusCode();
    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);

    if ($code === 200) {
      return SyncResult::success(200, $decoded['message'] ?? 'Sync completed.');
    }
    if ($code === 202) {
      return SyncResult::accepted((string) ($decoded['task_id'] ?? ''));
    }
    if ($code === 429) {
      return SyncResult::failure('rate_limited', 429, 'Rate limit exceeded (4/hour).');
    }
    if (in_array($code, [401, 403], TRUE)) {
      return SyncResult::failure('auth_error', $code, $decoded['detail'] ?? 'Authentication failed.');
    }

    $detail = is_array($decoded['detail'] ?? NULL)
      ? json_encode($decoded['detail'])
      : ($decoded['detail'] ?? $body);
    $this->logger->error('Sentinel sync rejected (@code): @detail', ['@code' => $code, '@detail' => $detail]);
    return SyncResult::failure('rejected', $code, (string) $detail);
  }

}
