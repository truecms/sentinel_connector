<?php

namespace Drupal\sentinel_connector;

/**
 * Immutable outcome of a sync attempt.
 */
final class SyncResult {

  /**
   * Constructs a SyncResult.
   *
   * @param string $status
   *   Machine status, e.g. 'success', 'accepted', 'rejected'.
   * @param int|null $httpCode
   *   The HTTP status code, or NULL for transport/config failures.
   * @param string $message
   *   Human-readable outcome message.
   * @param string|null $taskId
   *   The background task ID, when the sync was accepted asynchronously.
   */
  public function __construct(
    public readonly string $status,
    public readonly ?int $httpCode,
    public readonly string $message,
    public readonly ?string $taskId = NULL,
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
   */
  public static function failure(string $status, ?int $code, string $message): self {
    return new self($status, $code, $message);
  }

  /**
   * Whether the sync succeeded or was accepted for processing.
   */
  public function isOk(): bool {
    return in_array($this->status, ['success', 'accepted'], TRUE);
  }

}
