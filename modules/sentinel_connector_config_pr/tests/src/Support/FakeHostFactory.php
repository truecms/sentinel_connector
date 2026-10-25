<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Support;

use Drupal\sentinel_connector_config_pr\Host\HostFactory;
use Drupal\sentinel_connector_config_pr\Host\HostInterface;

/**
 * Hands out one fake host.
 */
class FakeHostFactory extends HostFactory {

  /**
   * Constructs the factory.
   *
   * @param \Drupal\Tests\sentinel_connector_config_pr\Support\FakeHost $host
   *   The fake host.
   */
  public function __construct(
    public FakeHost $host,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function create(array $committer): HostInterface {
    return $this->host;
  }

}
