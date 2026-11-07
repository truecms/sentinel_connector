<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use Drupal\sentinel_connector\StatusReportCollector;

/**
 * The status report collector as it runs in a web request.
 */
class WebStatusReportCollector extends StatusReportCollector {

  /**
   * {@inheritdoc}
   */
  protected function isCli(): bool {
    return FALSE;
  }

}
