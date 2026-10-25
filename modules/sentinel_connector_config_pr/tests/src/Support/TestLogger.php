<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Support;

use Psr\Log\AbstractLogger;

/**
 * Keeps log entries, with placeholders replaced.
 */
class TestLogger extends AbstractLogger {

  /**
   * The entries, each with 'level' and 'message'.
   *
   * @var array<int, array{level: string, message: string}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   *
   * @param mixed $level
   *   The level.
   * @param string|\Stringable $message
   *   The message.
   * @param array<string, mixed> $context
   *   The placeholders.
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $replacements = [];
    foreach ($context as $key => $value) {
      if (is_scalar($value) || $value instanceof \Stringable) {
        $replacements[$key] = (string) $value;
      }
    }
    $this->records[] = ['level' => (string) $level, 'message' => strtr((string) $message, $replacements)];
  }

  /**
   * All messages joined, for searching.
   */
  public function text(): string {
    return implode("\n", array_column($this->records, 'message'));
  }

}
