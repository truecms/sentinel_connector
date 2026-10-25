<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use GuzzleHttp\Exception\ConnectException;

/**
 * Supplies Guzzle 7 handler context when testing with either Guzzle version.
 */
class ContextConnectException extends ConnectException {

  /**
   * Returns the numeric connection error supplied by the legacy handler.
   *
   * @return array<string, int>
   *   The handler context.
   */
  public function getHandlerContext(): array {
    return ['errno' => 7];
  }

}
