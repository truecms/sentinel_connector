<?php

namespace Drupal\sentinel_connector;

/**
 * Immutable outcome of a sync attempt.
 */
final class SyncResult {

  public function __construct(
    public readonly string $status,
    public readonly ?int $httpCode,
    public readonly string $message,
    public readonly ?string $taskId = NULL,
  ) {}

  public static function success(int $code, string $message): self {
    return new self('success', $code, $message);
  }

  public static function accepted(string $taskId): self {
    return new self('accepted', 202, 'Sync queued for background processing.', $taskId);
  }

  public static function failure(string $status, ?int $code, string $message): self {
    return new self($status, $code, $message);
  }

  public function isOk(): bool {
    return in_array($this->status, ['success', 'accepted'], TRUE);
  }

}
