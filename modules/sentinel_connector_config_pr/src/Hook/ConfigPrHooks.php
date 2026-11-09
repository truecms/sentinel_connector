<?php

namespace Drupal\sentinel_connector_config_pr\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\sentinel_connector_config_pr\ConfigPrRunner;
use Drupal\sentinel_connector_config_pr\PullRequestTracker;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Cron and drift report hooks for the configuration pull request submodule.
 */
class ConfigPrHooks {

  /**
   * Constructs the hooks with autowired service closures.
   *
   * The runner is built only when cron calls it, inside the error handling,
   * so a broken Git host module never stops the other cron hooks.
   *
   * @param \Closure $runner
   *   Returns the configuration pull request runner.
   * @param \Closure $logger
   *   Returns the Sentinel Connector logger channel.
   * @param \Drupal\sentinel_connector_config_pr\PullRequestTracker $tracker
   *   The open pull request tracker.
   */
  public function __construct(
    #[AutowireServiceClosure(ConfigPrRunner::class)]
    protected \Closure $runner,
    #[AutowireServiceClosure('logger.channel.sentinel_connector')]
    protected \Closure $logger,
    protected PullRequestTracker $tracker,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Does nothing unless cron runs are on in this module's settings (off by
   * default) and the Sentinel Connector's cron reporting is on, which the
   * README keeps to production. Then runs once the interval has passed.
   */
  #[Hook('cron')]
  public function cron(): void {
    try {
      ($this->runner)()->cron();
    }
    catch (\Throwable $error) {
      try {
        ($this->logger)()->error('Configuration pull request cron failed with @class.', ['@class' => get_class($error)]);
      }
      catch (\Throwable) {
        // Logging must not abort the remaining cron hooks.
      }
    }
  }

  /**
   * Implements hook_sentinel_connector_config_drift_alter().
   *
   * Adds the open configuration pull request to the drift report. It is read
   * from State only, so building the report never calls the Git host.
   *
   * @param array<string, mixed> $drift
   *   The configuration drift block.
   */
  #[Hook('sentinel_connector_config_drift_alter')]
  public function configDriftAlter(array &$drift): void {
    $pull = $this->tracker->openPullRequest();
    if ($pull !== NULL && preg_match('#^https?://#', $pull['url'])) {
      $drift['export_pull_request'] = $pull;
    }
  }

}
