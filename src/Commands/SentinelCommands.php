<?php

namespace Drupal\sentinel_connector\Commands;

use Drupal\sentinel_connector\SyncService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Sentinel Connector. CLI execution has full access.
 */
class SentinelCommands extends DrushCommands {

  public function __construct(protected SyncService $syncService) {
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
    else {
      throw new \RuntimeException(dt('Sentinel sync failed (@status): @msg', [
        '@status' => $result->status,
        '@msg' => $result->message,
      ]));
    }
  }

}
