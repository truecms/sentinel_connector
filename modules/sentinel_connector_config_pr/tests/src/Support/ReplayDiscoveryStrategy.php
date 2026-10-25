<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Support;

use Http\Discovery\Strategy\DiscoveryStrategy;
use Psr\Http\Client\ClientInterface;

/**
 * Makes php-http discovery hand the replay client to the Git API clients.
 */
class ReplayDiscoveryStrategy implements DiscoveryStrategy {

  /**
   * {@inheritdoc}
   *
   * @return array<int, array{class: \Closure}>
   *   The candidate.
   */
  public static function getCandidates($type): array {
    if ($type !== ClientInterface::class) {
      return [];
    }
    return [['class' => static fn (): ClientInterface => ReplayHttpClient::$current ?? throw new \LogicException('No replay client.')]];
  }

}
