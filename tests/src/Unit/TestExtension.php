<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\Core\Extension\Extension;

/**
 * Models the installed status Drupal adds to a discovered module extension.
 */
class TestExtension extends Extension {

  /**
   * Whether the module is installed and enabled.
   */
  public int $status = 1;

}
