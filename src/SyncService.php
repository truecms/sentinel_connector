<?php

namespace Drupal\sentinel_connector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Single entry point for a Sentinel sync, shared by cron, drush, and the UI.
 */
class SyncService {

  /**
   * Default minimum seconds between cron pushes: once a day.
   */
  public const DEFAULT_CRON_INTERVAL = 86400;

  /**
   * Longest time a single push-limit response may hold back pushes.
   *
   * Sentinel's longest window is a day. The cap stops one bad response from
   * silencing the connector for good.
   */
  public const MAX_PUSH_DEFERRAL = 604800;

  /**
   * State key: Unix timestamp after which Sentinel accepts the next push.
   */
  public const STATE_NEXT_ALLOWED_AT = 'sentinel_connector.next_allowed_at';

  /**
   * State key: the message Sentinel sent with the last push-limit response.
   */
  public const STATE_PUSH_LIMIT_MESSAGE = 'sentinel_connector.push_limit_message';

  /**
   * Constructs the sync service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store for last-sync bookkeeping.
   * @param \Drupal\sentinel_connector\PayloadBuilder $payloadBuilder
   *   Builds the sync payload from the extension list.
   * @param \Drupal\sentinel_connector\ApiKeyResolver $apiKeyResolver
   *   Resolves the API key.
   * @param \Drupal\sentinel_connector\SentinelClient $client
   *   The HTTP client for the Sentinel backend.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   Diagnostic logger; never receives secret-bearing exception messages.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected PayloadBuilder $payloadBuilder,
    protected ApiKeyResolver $apiKeyResolver,
    protected SentinelClient $client,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Run a sync now. Returns a SyncResult describing the outcome.
   *
   * While a stored push limit is in force, Sentinel is not contacted and the
   * stored outcome is returned instead.
   */
  public function sync(): SyncResult {
    try {
      $deferred = $this->deferredResult();
    }
    catch (\Throwable $e) {
      $this->logFailure($e);
      $deferred = NULL;
    }
    if ($deferred !== NULL) {
      return $deferred;
    }
    try {
      $result = $this->performSync();
      if ($result->isPushLimited()) {
        $result = $this->resolvePushLimit($result);
        $this->logPushLimit($result);
      }
    }
    catch (\Throwable $e) {
      $this->logFailure($e);
      $result = SyncResult::failure('internal_error', NULL, 'Sentinel sync could not complete. Check the connector configuration and Drupal logs.');
    }
    try {
      $this->recordResult($result);
    }
    catch (\Throwable $e) {
      $this->logFailure($e);
      return SyncResult::failure('state_error', $result->httpCode, 'The sync outcome could not be saved. Check Drupal state storage and logs.');
    }
    return $result;
  }

  /**
   * Builds and sends the inventory inside the guarded sync boundary.
   */
  protected function performSync(): SyncResult {
    $config = $this->configFactory->get('sentinel_connector.settings');
    $baseUrl = (string) $config->get('api_base_url');
    $uuid = (string) $config->get('site_uuid');
    $apiKey = $this->apiKeyResolver->getKey();

    if ($baseUrl === '' || $uuid === '' || $apiKey === NULL) {
      $result = SyncResult::failure('unconfigured', NULL, 'Sentinel connector is not fully configured.');
      return $result;
    }

    $payload = $this->payloadBuilder->build((string) ($config->get('report_scope') ?: 'all'));
    $payload['site'] = [
      'url' => (string) $config->get('site_url'),
      'name' => (string) $config->get('site_name'),
      'uuid' => $uuid,
    ];

    $result = $this->client->sync($baseUrl, $uuid, $apiKey, $payload);
    return $result;
  }

  /**
   * The time after which Sentinel accepts the next push, when one is stored.
   *
   * @return int|null
   *   A Unix timestamp, or NULL when no push limit is stored.
   */
  public function getNextAllowedAt(): ?int {
    $next = (int) $this->state->get(self::STATE_NEXT_ALLOWED_AT, 0);
    return $next > 0 ? $next : NULL;
  }

  /**
   * The stored push-limit outcome, while Sentinel still refuses pushes.
   *
   * @return \Drupal\sentinel_connector\SyncResult|null
   *   The stored outcome, or NULL when a push may be sent.
   */
  public function deferredResult(): ?SyncResult {
    $next = $this->getNextAllowedAt();
    if ($next === NULL || $this->time->getCurrentTime() >= $next) {
      return NULL;
    }
    $message = $this->state->get(self::STATE_PUSH_LIMIT_MESSAGE);
    return SyncResult::pushDeferred(
      is_string($message) && $message !== '' ? $message : SentinelClient::PUSH_LIMIT_FALLBACK_MESSAGE,
      $next,
    );
  }

  /**
   * Works out when the next push is allowed, on this site's clock.
   *
   * The server's absolute time is used when it lies in the future. Otherwise
   * the relative wait is added to the current time, which also covers a clock
   * that differs from Sentinel's. The result never exceeds the deferral cap.
   */
  protected function resolvePushLimit(SyncResult $result): SyncResult {
    $now = $this->time->getCurrentTime();
    $next = NULL;
    if ($result->nextAllowedAt !== NULL && $result->nextAllowedAt > $now) {
      $next = $result->nextAllowedAt;
    }
    elseif ($result->retryAfterSeconds !== NULL && $result->retryAfterSeconds > 0) {
      $next = $now + $result->retryAfterSeconds;
    }
    if ($next !== NULL) {
      $next = min($next, $now + self::MAX_PUSH_DEFERRAL);
    }
    return $result->withNextAllowedAt($next);
  }

  /**
   * Logs a push-limit rejection. It is expected, so it is a notice.
   */
  protected function logPushLimit(SyncResult $result): void {
    try {
      $this->logger->notice('Sentinel push limit reached: plan @plan, limit @limit, next push allowed at @next.', [
        '@plan' => $result->plan ?? 'unknown',
        '@limit' => $result->limit ?? 'unknown',
        '@next' => $result->nextAllowedAt !== NULL ? gmdate('Y-m-d\TH:i:s\Z', $result->nextAllowedAt) : 'unknown',
      ]);
    }
    catch (\Throwable) {
      // Broken log storage must not turn an expected outcome into a failure.
    }
  }

  /**
   * Whether cron is due to run a sync.
   *
   * A stored push limit decides on its own: nothing is sent before the time
   * Sentinel gave, and a push is due as soon as that time has passed. Without
   * one, the configured interval applies.
   */
  public function cronIsDue(int $now): bool {
    $config = $this->configFactory->get('sentinel_connector.settings');
    if (!$config->get('enabled')) {
      return FALSE;
    }
    $next = $this->getNextAllowedAt();
    if ($next !== NULL) {
      return $now >= $next;
    }
    $interval = (int) ($config->get('cron_interval') ?: self::DEFAULT_CRON_INTERVAL);
    $last = (int) $this->state->get('sentinel_connector.last_attempt_time',
      $this->state->get('sentinel_connector.last_sync_time', 0));
    return ($now - $last) >= $interval;
  }

  /**
   * Persist the outcome to state for the settings-form status line and cron.
   */
  protected function recordResult(SyncResult $result): void {
    $this->state->set('sentinel_connector.last_attempt_time', $this->time->getRequestTime());
    if ($result->status === 'success') {
      $this->state->set('sentinel_connector.last_sync_time', $this->time->getRequestTime());
    }
    $this->state->set('sentinel_connector.last_result', $result->status);
    $this->state->set('sentinel_connector.last_message', $result->message);
    $this->state->set('sentinel_connector.last_task_id', $result->taskId);
    if ($result->isPushLimited() && $result->nextAllowedAt !== NULL) {
      $this->state->set(self::STATE_NEXT_ALLOWED_AT, $result->nextAllowedAt);
      $this->state->set(self::STATE_PUSH_LIMIT_MESSAGE, $result->message);
    }
    else {
      // Any other outcome means the stored limit no longer applies.
      $this->state->deleteMultiple([self::STATE_NEXT_ALLOWED_AT, self::STATE_PUSH_LIMIT_MESSAGE]);
    }
  }

  /**
   * Best-effort diagnostics without exception messages or request material.
   */
  protected function logFailure(\Throwable $error): void {
    try {
      $this->logger->error('Sentinel connector failed with @class.', ['@class' => get_class($error)]);
    }
    catch (\Throwable) {
      // Broken log storage must not make cron fail a second time.
    }
  }

}
