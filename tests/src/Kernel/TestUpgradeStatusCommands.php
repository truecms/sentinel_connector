<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use Drupal\sentinel_connector\Drush\Commands\SentinelDrushCommands;

/**
 * The connector's Drush commands with a scan that returns a set exit code.
 */
class TestUpgradeStatusCommands extends SentinelDrushCommands {

  /**
   * Whether the scan ran.
   */
  public bool $scanned = FALSE;

  /**
   * The exit code of the scan.
   */
  public int $exit = 0;

  /**
   * The messages the command logged.
   */
  public BufferLogger $buffer;

  /**
   * {@inheritdoc}
   */
  protected function scan(): int {
    $this->scanned = TRUE;
    return $this->exit;
  }

}
