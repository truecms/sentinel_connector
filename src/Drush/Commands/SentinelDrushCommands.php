<?php

namespace Drupal\sentinel_connector\Drush\Commands;

use Drupal\sentinel_connector\PushLimitFormatter;
use Drupal\sentinel_connector\SyncResult;
use Drupal\sentinel_connector\SyncService;
use Drupal\sentinel_connector\UpgradeStatusCollector;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Symfony\Component\Process\Process;

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
   * @param \Drupal\sentinel_connector\UpgradeStatusCollector $upgradeStatus
   *   Reads the Upgrade Status result after a scan.
   */
  public function __construct(
    protected SyncService $syncService,
    protected PushLimitFormatter $pushLimitFormatter,
    protected UpgradeStatusCollector $upgradeStatus,
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
   * Scan custom projects with Upgrade Status, then sync.
   *
   * Opt-in: a scan runs PHPStan over custom code, which takes minutes and
   * uses CPU. It never runs on cron or during a push. Contributed projects
   * are not scanned: their next steps come from release data.
   */
  #[CLI\Command(name: 'sentinel_connector:upgrade-status', aliases: ['sc-upgrade-status'])]
  #[CLI\Usage(name: 'drush sentinel_connector:upgrade-status', description: 'Scan custom projects with Upgrade Status, then push the readiness to Sentinel.')]
  public function upgradeStatus(): void {
    if (!$this->upgradeStatus->isDetected()) {
      throw new \RuntimeException(dt('Upgrade Status is not enabled. Add it with "composer require --dev drupal/upgrade_status" and "drush pm:install upgrade_status", then run this command again.'));
    }
    // A scan takes minutes: do not run one whose result cannot be sent.
    if (!$this->syncService->isConfigured()) {
      throw new \RuntimeException(dt('Sentinel connector is not fully configured; no scan was run.'));
    }
    $this->logger()->notice(dt('Scanning custom projects with Upgrade Status. This can take several minutes.'));
    $exit = $this->scan();
    // Upgrade Status exits with 3 when the scan found problems: that is a
    // result, not a failure.
    if ($exit !== self::EXIT_SUCCESS && $exit !== self::EXIT_FAILURE_WITH_CLARITY) {
      throw new \RuntimeException(dt('The Upgrade Status scan failed (exit code @code). No push was sent.', ['@code' => $exit]));
    }
    $block = $this->upgradeStatus->collect(FALSE);
    if ($block === NULL) {
      $this->logger()->warning(dt('Upgrade Status reporting is switched off in the Sentinel connector settings; the push carries no readiness.'));
    }
    else {
      $counts = [];
      foreach ($block['counts'] as $step => $count) {
        $counts[] = $step . ' ' . $count;
      }
      $this->logger()->notice(dt('Drupal @target readiness: @status (@counts).', [
        '@target' => $block['target_major'],
        '@status' => $block['status'] . ($block['reason'] ? ', ' . $block['reason'] : ''),
        '@counts' => implode(', ', $counts),
      ]));
    }
    $this->report($this->syncService->sync(SyncService::TRIGGER_DRUSH));
  }

  /**
   * Runs the Upgrade Status scan of custom projects and returns its exit code.
   */
  protected function scan(): int {
    $process = Drush::drush(Drush::aliasManager()->getSelf(), 'upgrade_status:analyze', [], [
      'all' => TRUE,
      'ignore-contrib' => TRUE,
    ]);
    // A scan of a large codebase takes longer than the default time limit.
    $process->setTimeout(NULL);
    return $process->run(function (string $type, string $buffer): void {
      if ($type === Process::ERR) {
        $this->io()->getErrorStyle()->write($buffer);
      }
      else {
        $this->output()->write($buffer);
      }
    });
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
