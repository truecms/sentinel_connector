<?php

namespace Drupal\sentinel_connector_config_pr\Commands;

use Drupal\sentinel_connector_config_pr\ConfigPrRunner;
use Drush\Commands\DrushCommands;

/**
 * Drush command that opens or updates the configuration pull request.
 */
class ConfigPrCommands extends DrushCommands {

  /**
   * Constructs the commands.
   *
   * @param \Drupal\sentinel_connector_config_pr\ConfigPrRunner $runner
   *   The export runner.
   */
  public function __construct(
    protected ConfigPrRunner $runner,
  ) {
    parent::__construct();
  }

  /**
   * Export changed configuration and open or update its pull request.
   *
   * Compares the export storage with the sync directory, writes the changed
   * objects to the export branch and opens one pull request, or updates the
   * open one. Never merges.
   *
   * @param array $options
   *   The command options.
   *
   * @command sentinel_connector:config-pr
   * @aliases sc-config-pr
   * @option dry-run List the changed configuration objects; contact no Git host and write nothing.
   * @option force Run even if the interval has not passed since the last run.
   * @usage drush sentinel_connector:config-pr --dry-run
   *   List the configuration objects a run would export.
   * @usage drush sentinel_connector:config-pr --force
   *   Export now, whenever the last run was.
   *
   * @phpstan-param array{dry-run: bool, force: bool} $options
   */
  public function configPr(array $options = ['dry-run' => FALSE, 'force' => FALSE]): void {
    $result = $this->runner->run((bool) $options['dry-run'], (bool) $options['force'], ConfigPrRunner::TRIGGER_DRUSH);
    foreach ($result->changes as $path => $op) {
      $this->output()->writeln(sprintf('%s %s', str_pad($op, 6), $path));
    }
    if (!$result->isOk()) {
      throw new \RuntimeException(dt('Configuration pull request: @message', ['@message' => $result->message]));
    }
    $message = $result->url
      ? dt('Configuration pull request: @message @url', ['@message' => $result->message, '@url' => $result->url])
      : dt('Configuration pull request: @message', ['@message' => $result->message]);
    if ($result->status === 'not_due') {
      $this->logger()->notice($message . ' ' . dt('Use --force to run now.'));
      return;
    }
    $this->logger()->success($message);
  }

}
