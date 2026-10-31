<?php

namespace Drupal\sentinel_connector\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\sentinel_connector\ApiKeyMigrator;
use Drupal\sentinel_connector\PushHealth;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cron and runtime status hooks for Sentinel Connector.
 */
class SentinelHooks {

  /**
   * Defers service lookup until failures can be caught.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public function __construct(protected ContainerInterface $container) {}

  /**
   * Checks the inventory without allowing a failure to stop other cron hooks.
   */
  #[Hook('cron')]
  public function cron(): void {
    try {
      /** @var \Drupal\sentinel_connector\SyncService $sync */
      $sync = $this->container->get('Drupal\sentinel_connector\SyncService');
      $now = $this->container->get('datetime.time')->getRequestTime();
      $sync->markConfigured();
      if ($sync->cronIsDue($now)) {
        $sync->syncIfChanged();
      }
    }
    catch (\Throwable $error) {
      // State/config/service failures may prevent recording. Keep cron running.
      try {
        $state = $this->container->get('state');
        $state->set('sentinel_connector.last_attempt_time', $this->container->get('datetime.time')->getRequestTime());
        $state->set('sentinel_connector.last_result', 'internal_error');
        $state->set('sentinel_connector.last_message', 'Cron sync failed. Check Drupal logs.');
      }
      catch (\Throwable) {
        // Recording is best effort when Drupal storage itself is unavailable.
      }
      try {
        $this->container->get('logger.channel.sentinel_connector')->error('Sentinel cron failed with @class.', ['@class' => get_class($error)]);
      }
      catch (\Throwable) {
        // Logging must not abort the remaining cron hooks.
      }
    }
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   Runtime requirement entries.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $health = $this->container->get(PushHealth::class);
    $requirements = ['sentinel_connector' => $health->requirement()];
    $connection = $health->connectionRequirement();
    if ($connection !== NULL) {
      $requirements['sentinel_connector_connection'] = $connection;
    }
    return $requirements;
  }

  /**
   * Moves a State API key into a key once the Key module is enabled.
   *
   * @param string[] $modules
   *   The modules just installed.
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules): void {
    if (in_array('key', $modules, TRUE)) {
      $this->container->get(ApiKeyMigrator::class)->migrate();
    }
  }

}
