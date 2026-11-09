<?php

namespace Drupal\sentinel_connector\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\sentinel_connector\ApiKeyMigrator;
use Drupal\sentinel_connector\PushHealth;
use Drupal\sentinel_connector\StatusReportCollector;
use Drupal\sentinel_connector\SyncService;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Cron and runtime status hooks for Sentinel Connector.
 */
class SentinelHooks {

  /**
   * Constructs the hooks with autowired service closures.
   *
   * Each closure returns its service when called. A service that cannot be
   * built then fails inside the hook's own error handling, so it never stops
   * the other modules' cron hooks or a cache rebuild.
   *
   * @param \Closure $syncService
   *   Returns the sync service.
   * @param \Closure $time
   *   Returns the time service.
   * @param \Closure $state
   *   Returns the state store.
   * @param \Closure $logger
   *   Returns the Sentinel Connector logger channel.
   * @param \Closure $pushHealth
   *   Returns the push health service.
   * @param \Closure $statusReport
   *   Returns the status report collector.
   * @param \Closure $apiKeyMigrator
   *   Returns the API key migrator.
   */
  public function __construct(
    #[AutowireServiceClosure(SyncService::class)]
    protected \Closure $syncService,
    #[AutowireServiceClosure('datetime.time')]
    protected \Closure $time,
    #[AutowireServiceClosure('state')]
    protected \Closure $state,
    #[AutowireServiceClosure('logger.channel.sentinel_connector')]
    protected \Closure $logger,
    #[AutowireServiceClosure(PushHealth::class)]
    protected \Closure $pushHealth,
    #[AutowireServiceClosure(StatusReportCollector::class)]
    protected \Closure $statusReport,
    #[AutowireServiceClosure(ApiKeyMigrator::class)]
    protected \Closure $apiKeyMigrator,
  ) {}

  /**
   * Checks the inventory without allowing a failure to stop other cron hooks.
   */
  #[Hook('cron')]
  public function cron(): void {
    try {
      /** @var \Drupal\sentinel_connector\SyncService $sync */
      $sync = ($this->syncService)();
      $now = ($this->time)()->getRequestTime();
      $sync->markConfigured();
      if ($sync->cronIsDue($now)) {
        $sync->syncIfChanged();
      }
    }
    catch (\Throwable $error) {
      // State/config/service failures may prevent recording. Keep cron running.
      try {
        $state = ($this->state)();
        $state->set('sentinel_connector.last_attempt_time', ($this->time)()->getRequestTime());
        $state->set('sentinel_connector.last_result', 'internal_error');
        $state->set('sentinel_connector.last_message', 'Cron sync failed. Check Drupal logs.');
      }
      catch (\Throwable) {
        // Recording is best effort when Drupal storage itself is unavailable.
      }
      try {
        ($this->logger)()->error('Sentinel cron failed with @class.', ['@class' => get_class($error)]);
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
    /** @var \Drupal\sentinel_connector\PushHealth $health */
    $health = ($this->pushHealth)();
    $requirements = ['sentinel_connector' => $health->requirement()];
    $connection = $health->connectionRequirement();
    if ($connection !== NULL) {
      $requirements['sentinel_connector_connection'] = $connection;
    }
    return $requirements;
  }

  /**
   * Forgets the status report snapshot when caches are rebuilt.
   *
   * A deployment or a module change rebuilds caches, and can fix or cause
   * status report errors: the next sync builds a fresh list, and the next
   * web response a fresh snapshot.
   */
  #[Hook('rebuild')]
  public function rebuild(): void {
    try {
      ($this->statusReport)()->forgetSnapshot();
    }
    catch (\Throwable) {
      // A cache rebuild must never fail over a value only the sync needs.
    }
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
      ($this->apiKeyMigrator)()->migrate();
    }
  }

}
