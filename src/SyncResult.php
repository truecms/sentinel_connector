<?php

namespace Drupal\sentinel_connector;

/**
 * Immutable outcome of a sync attempt.
 */
final class SyncResult {

  /**
   * Machine status of a push that Sentinel refused because of the push limit.
   */
  public const PUSH_LIMITED = 'push_limited';

  /**
   * Machine status of a push refused because the subscription is not active.
   */
  public const SUBSCRIPTION_INACTIVE = 'subscription_inactive';

  /**
   * Constructs a SyncResult.
   *
   * @param string $status
   *   Machine status, e.g. 'success', 'accepted', 'not_found'.
   * @param int|null $httpCode
   *   The HTTP status code, or NULL for transport/config failures.
   * @param string $message
   *   Human-readable outcome message.
   * @param string|null $taskId
   *   The background task ID, when the sync was accepted asynchronously.
   * @param int|null $nextAllowedAt
   *   Unix timestamp after which Sentinel accepts the next push, when known.
   * @param int|null $retryAfterSeconds
   *   Seconds to wait before the next push, as reported by Sentinel.
   * @param string|null $plan
   *   The plan the push limit belongs to, e.g. 'free' or 'paid'.
   * @param int|null $limit
   *   The number of pushes Sentinel accepts in one window.
   * @param bool $deferred
   *   TRUE when a stored push limit stopped the push before Sentinel was
   *   contacted.
   * @param string|null $reason
   *   Why the subscription is not active, e.g. 'past_due', when known.
   * @param array<string, int|string> $diagnostics
   *   Facts for the log entry only, e.g. a curl error number. Never holds the
   *   API key, the request URL, headers, the payload or an exception message.
   */
  public function __construct(
    public readonly string $status,
    public readonly ?int $httpCode,
    public readonly string $message,
    public readonly ?string $taskId = NULL,
    public readonly ?int $nextAllowedAt = NULL,
    public readonly ?int $retryAfterSeconds = NULL,
    public readonly ?string $plan = NULL,
    public readonly ?int $limit = NULL,
    public readonly bool $deferred = FALSE,
    public readonly ?string $reason = NULL,
    public readonly array $diagnostics = [],
  ) {}

  /**
   * Creates a successful result.
   */
  public static function success(int $code, string $message): self {
    return new self('success', $code, $message);
  }

  /**
   * Creates a result for an asynchronously accepted (202) sync.
   */
  public static function accepted(string $taskId): self {
    return new self('accepted', 202, 'Sync queued for background processing.', $taskId);
  }

  /**
   * Creates a failure result with the given status, code, and message.
   *
   * @param string $status
   *   Machine status, e.g. 'not_found'.
   * @param int|null $code
   *   The HTTP status code, or NULL when Sentinel sent no response.
   * @param string $message
   *   Human-readable outcome message.
   * @param array<string, int|string> $diagnostics
   *   Facts for the log entry only.
   */
  public static function failure(string $status, ?int $code, string $message, array $diagnostics = []): self {
    return new self($status, $code, $message, diagnostics: $diagnostics);
  }

  /**
   * Creates a result for a push Sentinel refused because of the push limit.
   *
   * @param string $message
   *   The server's message, or a generic one when the server sent none.
   * @param int|null $nextAllowedAt
   *   Unix timestamp after which the next push is accepted, when known.
   * @param int|null $retryAfterSeconds
   *   Seconds to wait before the next push, when known.
   * @param string|null $plan
   *   The plan the limit belongs to, when known.
   * @param int|null $limit
   *   The number of pushes accepted in one window, when known.
   */
  public static function pushLimited(string $message, ?int $nextAllowedAt = NULL, ?int $retryAfterSeconds = NULL, ?string $plan = NULL, ?int $limit = NULL): self {
    return new self(self::PUSH_LIMITED, 429, $message, NULL, $nextAllowedAt, $retryAfterSeconds, $plan, $limit);
  }

  /**
   * Creates a result for a push held back by a stored push limit.
   *
   * @param string $message
   *   The message stored when Sentinel refused the earlier push.
   * @param int $nextAllowedAt
   *   Unix timestamp after which the next push is accepted.
   */
  public static function pushDeferred(string $message, int $nextAllowedAt): self {
    return new self(self::PUSH_LIMITED, NULL, $message, NULL, $nextAllowedAt, NULL, NULL, NULL, TRUE);
  }

  /**
   * Creates a result for a push refused over an inactive subscription.
   *
   * @param string $message
   *   The server's message, or an empty string when the server sent none.
   * @param string|null $reason
   *   The server's reason, e.g. 'past_due', 'unpaid' or 'paused', when known.
   */
  public static function subscriptionInactive(string $message, ?string $reason = NULL): self {
    return new self(self::SUBSCRIPTION_INACTIVE, 402, $message, NULL, NULL, NULL, NULL, NULL, FALSE, $reason);
  }

  /**
   * Returns a copy with the next allowed time replaced.
   *
   * @param int|null $nextAllowedAt
   *   Unix timestamp after which the next push is accepted, or NULL.
   */
  public function withNextAllowedAt(?int $nextAllowedAt): self {
    return new self($this->status, $this->httpCode, $this->message, $this->taskId, $nextAllowedAt, $this->retryAfterSeconds, $this->plan, $this->limit, $this->deferred, $this->reason, $this->diagnostics);
  }

  /**
   * Whether the sync succeeded or was accepted for processing.
   */
  public function isOk(): bool {
    return in_array($this->status, ['success', 'accepted'], TRUE);
  }

  /**
   * Whether Sentinel refused the push because the subscription is not active.
   */
  public function isSubscriptionInactive(): bool {
    return $this->status === self::SUBSCRIPTION_INACTIVE;
  }

  /**
   * Whether the push was refused, or held back, because of the push limit.
   */
  public function isPushLimited(): bool {
    return $this->status === self::PUSH_LIMITED;
  }

}
