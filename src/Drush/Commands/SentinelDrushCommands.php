<?php

namespace Drupal\sentinel_connector\Drush\Commands;

use Drupal\sentinel_connector\PushLimitFormatter;
use Drupal\sentinel_connector\SyncResult;
use Drupal\sentinel_connector\SyncService;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Sentinel Connector. CLI execution has full access.
 */
class SentinelDrushCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs the commands.
   *
   * @param \Drupal\sentinel_connector\SyncService $syncService
   *   The sync service.
   * @param \Drupal\sentinel_connector\PushLimitFormatter $pushLimitFormatter
   *   Builds the messages for a refused push.
   */
  public function __construct(
    protected SyncService $syncService,
    protected PushLimitFormatter $pushLimitFormatter,
  ) {
    parent::__construct();
  }

  /**
   * Run a Sentinel sync now.
   */
  #[CLI\Command(name: 'sentinel_connector:sync', aliases: ['sc-sync'])]
  #[CLI\Usage(name: 'drush sentinel_connector:sync', description: 'Collect installed extensions and push them to Sentinel.')]
  public function sync(): void {
    $this->report($this->syncService->sync(SyncService::TRIGGER_DRUSH));
  }

  /**
   * Notify Sentinel after a deployment, when the inventory changed.
   *
   * Made for CI/CD pipelines: run it as the last step of a production
   * deployment. No push is sent when the modules, their versions, Drupal core
   * and PHP are the same as in the last push Sentinel accepted.
   *
   * @param array $options
   *   The command options.
   *
   * @phpstan-param array{force: bool} $options
   */
  #[CLI\Command(name: 'sentinel_connector:deploy', aliases: ['sc-deploy'])]
  #[CLI\Option(name: 'force', description: 'Push even when nothing changed since the last accepted push.')]
  #[CLI\Usage(name: 'drush sentinel_connector:deploy', description: 'Push the inventory to Sentinel if the deployment changed it.')]
  #[CLI\Usage(name: 'drush sentinel_connector:deploy --force', description: 'Push the inventory to Sentinel whether or not it changed.')]
  public function deploy(array $options = ['force' => FALSE]): void {
    if (!$this->syncService->isConfigured()) {
      // The same pipeline often runs on environments that do not report.
      $this->logger()->warning(dt('Sentinel connector is not fully configured; no push was sent.'));
      return;
    }
    if (!$options['force'] && !$this->syncService->hasInventoryChanged()) {
      $this->logger()->success(dt('Sentinel deploy: no change since the last accepted push; no push was sent.'));
      return;
    }
    $this->report($this->syncService->sync(SyncService::TRIGGER_DRUSH));
  }

  /**
   * Reports the outcome of a push; throws when the command must fail.
   */
  protected function report(SyncResult $result): void {
    if ($result->isOk()) {
      $this->logger()->success(dt('Sentinel sync: @msg', ['@msg' => $result->message]));
    }
    elseif ($result->isPushLimited()) {
      // Expected outcome of a plan limit: warn, but do not fail the command.
      $this->logger()->warning((string) $this->pushLimitFormatter->warning($result));
    }
    elseif ($result->isSubscriptionInactive()) {
      // No push succeeds until billing is fixed, so the command fails.
      throw new \RuntimeException((string) $this->pushLimitFormatter->subscriptionError($result->reason, $result->message));
    }
    else {
      throw new \RuntimeException(dt('Sentinel sync failed (@status): @msg', [
        '@status' => $result->status,
        '@msg' => $result->message,
      ]));
    }
  }

}
