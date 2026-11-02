<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use Psr\Log\AbstractLogger;

/**
 * Keeps logged messages for a test to read.
 */
class BufferLogger extends AbstractLogger {

  /**
   * Level, message and context of each entry.
   *
   * @var array<int, array{mixed, string|\Stringable, array<mixed>}>
   */
  protected array $logs = [];

  /**
   * {@inheritdoc}
   *
   * @param mixed $level
   *   The log level.
   * @param string|\Stringable $message
   *   The message.
   * @param array<mixed> $context
   *   The placeholders.
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->logs[] = [$level, $message, $context];
  }

  /**
   * Returns and forgets the logged entries.
   *
   * @return array<int, array{mixed, string|\Stringable, array<mixed>}>
   *   Level, message and context of each entry.
   */
  public function cleanLogs(): array {
    $logs = $this->logs;
    $this->logs = [];
    return $logs;
  }

}
