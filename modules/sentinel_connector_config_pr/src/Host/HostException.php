<?php

namespace Drupal\sentinel_connector_config_pr\Host;

/**
 * A Git host step failed.
 *
 * The message names the step only. It never holds the token, file content or
 * a message from the Git host.
 */
class HostException extends \RuntimeException {}
