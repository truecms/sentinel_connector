<?php

namespace Drupal\sentinel_connector\Commands;

use Drupal\sentinel_connector\PushLimitFormatter;
use Drupal\sentinel_connector\SyncService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Sentinel Connector. CLI execution has full access.
 */
class SentinelCommands extends DrushCommands {

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
   *
   * @command sentinel_connector:sync
   * @aliases sc-sync
   * @usage drush sentinel_connector:sync
   *   Collect installed extensions and push them to Sentinel.
   */
  public function sync(): void {
    $result = $this->syncService->sync();
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
