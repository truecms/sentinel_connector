<?php

namespace Drupal\sentinel_connector\Drush\Commands;

use Drupal\sentinel_connector\ConnectionChecker;
use Drupal\sentinel_connector\ConnectionCheckResult;
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
   * @param \Drupal\sentinel_connector\ConnectionChecker $connectionChecker
   *   Runs the connection check.
   */
  public function __construct(
    protected SyncService $syncService,
    protected PushLimitFormatter $pushLimitFormatter,
    protected ConnectionChecker $connectionChecker,
  ) {
    parent::__construct();
  }

  /**
   * Run a Sentinel sync now.
   */
  #[CLI\Command(name: 'sentinel_connector:sync', aliases: ['sc-sync'])]
  #[CLI\Usage(name: 'drush sentinel_connector:sync', description: 'Collect installed extensions and push them to Sentinel.')]
  public function sync(): void {
    if ($this->syncService->isDisabled()) {
      // Local and CI run the same deploy scripts: this is not a failure.
      $this->logger()->notice($this->syncService->disabledMessage());
      return;
    }
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
    if ($this->syncService->isDisabled()) {
      $this->logger()->notice($this->syncService->disabledMessage());
      return;
    }
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
   * Check that Sentinel accepts this site's API key and Site UUID.
   *
   * Sends nothing about the site. Fails when the check fails, so CI and
   * deployment scripts stop; succeeds with a notice when sending is turned
   * off for this environment.
   */
  #[CLI\Command(name: 'sentinel_connector:check', aliases: ['sc-check'])]
  #[CLI\Usage(name: 'drush sentinel_connector:check', description: 'Test the connection to Sentinel with the settings a push would use.')]
  public function check(): void {
    $result = $this->connectionChecker->check();
    // The message is markup for the form; the terminal shows plain text.
    $message = trim(strip_tags(html_entity_decode((string) $this->connectionChecker->message($result), ENT_QUOTES | ENT_HTML5)));
    if ($result->outcome === ConnectionCheckResult::UNKNOWN_SITE) {
      $message .= ': ' . ConnectionChecker::ENVIRONMENTS_HELP_URL;
    }
    if ($result->isOk()) {
      $this->logger()->success($message);
      return;
    }
    if ($result->outcome === ConnectionCheckResult::DISABLED) {
      $this->logger()->notice($message);
      return;
    }
    throw new \RuntimeException($message);
  }

  /**
   * Reports the outcome of a push; throws when the command must fail.
   */
  protected function report(SyncResult $result): void {
    if ($result->isOk()) {
      $this->logger()->success(dt('Sentinel sync: @msg', ['@msg' => $result->message]));
      if ($result->modules !== []) {
        $this->logger()->warning(dt('Sentinel could not read the version of these modules and shows them as unknown version (@count):', [
          '@count' => count($result->modules),
        ]) . $this->moduleLines($result));
      }
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
      if ($result->modules !== []) {
        // The message names the first few; the command lists every one.
        $this->logger()->error(dt('Rejected modules (@count):', ['@count' => count($result->modules)]) . $this->moduleLines($result));
      }
      throw new \RuntimeException(dt('Sentinel sync failed (@status): @msg', [
        '@status' => $result->status,
        '@msg' => $result->message,
      ]));
    }
  }

  /**
   * Lists every module entry of a result, one per line.
   *
   * The entries are cleaned server text without angle brackets.
   */
  protected function moduleLines(SyncResult $result): string {
    $lines = '';
    foreach ($result->modules as $module) {
      $lines .= "\n  - " . SyncResult::describeModule($module);
    }
    return $lines;
  }

}
