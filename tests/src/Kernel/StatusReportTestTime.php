<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use Drupal\Component\Datetime\TimeInterface;

/**
 * A clock the status report tests set and move on.
 */
class StatusReportTestTime implements TimeInterface {

  public function __construct(public float $now) {}

  /**
   * Moves the clock on.
   */
  public function advance(float $seconds): void {
    $this->now += $seconds;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestTime(): int {
    return (int) $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime(): float {
    return $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime(): int {
    return (int) $this->now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime(): float {
    return $this->now;
  }

}
