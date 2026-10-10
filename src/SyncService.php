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
   * Fewest seconds between two pushes sent by cron: one push an hour.
   *
   * Fixed on purpose. How often a push is accepted is decided by Sentinel, by
   * plan. A manual push is not bound by this floor.
   */
  public const MIN_PUSH_INTERVAL = 3600;

  /**
   * State key: Unix timestamp of the last push Sentinel accepted.
   *
   * Covers both an inline success (200) and a queued push (202).
   */
  public const STATE_LAST_ACCEPTED_TIME = 'sentinel_connector.last_accepted_time';

  /**
   * State key: Unix timestamp at which the configuration became complete.
   */
  public const STATE_CONFIGURED_TIME = 'sentinel_connector.configured_time';

  /**
   * Longest time a single push-limit response may hold back pushes.
   *
   * Sentinel's longest window is a day. The cap stops one bad response from
   * silencing the connector for good.
   */
  public const MAX_PUSH_DEFERRAL = 604800;

  /**
   * Seconds cron waits after a refusal over an inactive subscription.
   */
  public const SUBSCRIPTION_HOLD = 86400;

  /**
   * State key: Unix timestamp before which cron sends no push.
   *
   * Set when Sentinel refuses a push over an inactive subscription. It never
   * holds a manual push.
   */
  public const STATE_SUBSCRIPTION_HOLD_UNTIL = 'sentinel_connector.subscription_hold_until';

  /**
   * State key: the reason Sentinel gave for the inactive subscription.
   */
  public const STATE_SUBSCRIPTION_REASON = 'sentinel_connector.subscription_reason';

  /**
   * State key: Unix timestamp after which Sentinel accepts the next push.
   */
  public const STATE_NEXT_ALLOWED_AT = 'sentinel_connector.next_allowed_at';

  /**
   * State key: the message Sentinel sent with the last push-limit response.
   */
  public const STATE_PUSH_LIMIT_MESSAGE = 'sentinel_connector.push_limit_message';

  /**
   * State key: fingerprint of the inventory in the last push Sentinel accepted.
   */
  public const STATE_LAST_FINGERPRINT = 'sentinel_connector.last_fingerprint';

  /**
   * Fingerprint of the inventory built for the push in progress.
   */
  protected ?string $pendingFingerprint = NULL;

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
    $this->pendingFingerprint = NULL;
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
      elseif ($result->isSubscriptionInactive()) {
        $this->logSubscriptionInactive($result);
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
    $this->markConfigured();

    $payload = $this->buildPayload();
    $this->pendingFingerprint = $this->fingerprint($payload);

    $result = $this->client->sync($baseUrl, $uuid, $apiKey, $payload);
    return $result;
  }

  /**
   * Builds the full payload: the inventory plus the site block from config.
   *
   * @return array<string, mixed>
   *   The payload sent to Sentinel.
   */
  protected function buildPayload(): array {
    $config = $this->configFactory->get('sentinel_connector.settings');
    $payload = $this->payloadBuilder->build((string) ($config->get('report_scope') ?: 'all'));
    $payload['site'] = [
      'url' => (string) $config->get('site_url'),
      'name' => (string) $config->get('site_name'),
      'uuid' => (string) $config->get('site_uuid'),
    ];
    return $payload;
  }

  /**
   * A stable hash of everything in a payload that a deployment can change.
   *
   * The IP address is left out: it differs between a web request and the
   * command line, and between containers, without anything being deployed.
   * The API base URL is added: a push accepted by one Sentinel says nothing
   * about another.
   *
   * @param array<string, mixed> $payload
   *   The payload sent to Sentinel.
   */
  protected function fingerprint(array $payload): string {
    unset($payload['drupal_info']['ip_address']);
    $modules = [];
    foreach ($payload['modules'] ?? [] as $module) {
      ksort($module);
      $modules[] = $module;
    }
    // The order of the extension list is not part of the inventory.
    usort($modules, fn (array $a, array $b): int => strcmp((string) ($a['machine_name'] ?? ''), (string) ($b['machine_name'] ?? '')));
    $payload['modules'] = $modules;
    $payload['api_base_url'] = rtrim((string) $this->configFactory->get('sentinel_connector.settings')->get('api_base_url'), '/');
    ksort($payload);
    return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
  }

  /**
   * Whether the inventory differs from the last push Sentinel accepted.
   *
   * TRUE when nothing was accepted yet, and when the inventory cannot be
   * read: in doubt a push is sent.
   */
  public function hasInventoryChanged(): bool {
    try {
      $last = $this->state->get(self::STATE_LAST_FINGERPRINT);
      if (!is_string($last) || $last === '') {
        return TRUE;
      }
      return !hash_equals($last, $this->fingerprint($this->buildPayload()));
    }
    catch (\Throwable $e) {
      $this->logFailure($e);
      return TRUE;
    }
  }

  /**
   * Whether everything a push needs is set: API URL, site UUID and API key.
   */
  public function isConfigured(): bool {
    $config = $this->configFactory->get('sentinel_connector.settings');
    return (string) $config->get('api_base_url') !== ''
      && (string) $config->get('site_uuid') !== ''
      && $this->apiKeyResolver->getKey() !== NULL;
  }

  /**
   * Records when the configuration first became complete.
   *
   * The status report uses it to give a new site a day before it reports a
   * missing push as an error.
   */
  public function markConfigured(): void {
    if ($this->isConfigured() && !$this->state->get(self::STATE_CONFIGURED_TIME)) {
      $this->state->set(self::STATE_CONFIGURED_TIME, $this->time->getCurrentTime());
    }
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
   * The time before which cron sends no push, after a billing refusal.
   *
   * @return int|null
   *   A Unix timestamp, or NULL when cron is not held.
   */
  public function getSubscriptionHoldUntil(): ?int {
    $until = (int) $this->state->get(self::STATE_SUBSCRIPTION_HOLD_UNTIL, 0);
    return $until > 0 ? $until : NULL;
  }

  /**
   * Logs a refusal over an inactive subscription, with the reason only.
   */
  protected function logSubscriptionInactive(SyncResult $result): void {
    try {
      $this->logger->warning('Sentinel refused the push: subscription inactive, reason @reason.', [
        '@reason' => $result->reason ?? 'unknown',
      ]);
    }
    catch (\Throwable) {
      // Broken log storage must not hide the outcome from the caller.
    }
  }

  /**
   * Whether cron is due to run a sync.
   *
   * Three things hold cron back, and all must have passed: the hold after a
   * refusal over an inactive subscription (one try a day), the time Sentinel
   * gave with a push-limit response, and the fixed floor of one push an hour
   * counted from the last attempt, whatever its outcome.
   */
  public function cronIsDue(int $now): bool {
    $config = $this->configFactory->get('sentinel_connector.settings');
    if (!$config->get('enabled')) {
      return FALSE;
    }
    $hold = $this->getSubscriptionHoldUntil();
    if ($hold !== NULL && $now < $hold) {
      return FALSE;
    }
    $next = $this->getNextAllowedAt();
    if ($next !== NULL && $now < $next) {
      return FALSE;
    }
    $last = (int) $this->state->get('sentinel_connector.last_attempt_time',
      $this->state->get('sentinel_connector.last_sync_time', 0));
    return ($now - $last) >= self::MIN_PUSH_INTERVAL;
  }

  /**
   * Persist the outcome to state for the settings-form status line and cron.
   */
  protected function recordResult(SyncResult $result): void {
    $this->state->set('sentinel_connector.last_attempt_time', $this->time->getRequestTime());
    if ($result->status === 'success') {
      $this->state->set('sentinel_connector.last_sync_time', $this->time->getRequestTime());
    }
    if ($result->isOk()) {
      $this->state->set(self::STATE_LAST_ACCEPTED_TIME, $this->time->getRequestTime());
      if ($this->pendingFingerprint !== NULL) {
        $this->state->set(self::STATE_LAST_FINGERPRINT, $this->pendingFingerprint);
      }
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
    if ($result->isSubscriptionInactive()) {
      // Cron tries again in a day. A manual push is never held by this.
      $this->state->set(self::STATE_SUBSCRIPTION_HOLD_UNTIL, $this->time->getCurrentTime() + self::SUBSCRIPTION_HOLD);
      $this->state->set(self::STATE_SUBSCRIPTION_REASON, $result->reason ?? '');
    }
    else {
      $this->state->delete(self::STATE_SUBSCRIPTION_REASON);
      if ($result->isOk()) {
        // Only an accepted push shows that billing is in order again.
        $this->state->delete(self::STATE_SUBSCRIPTION_HOLD_UNTIL);
      }
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
