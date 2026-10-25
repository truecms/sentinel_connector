<?php

namespace Drupal\sentinel_connector_config_pr;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\sentinel_connector_config_pr\Host\HostException;
use Drupal\sentinel_connector_config_pr\Host\HostFactory;
use Drupal\sentinel_connector_config_pr\Host\HostInterface;
use Psr\Log\LoggerInterface;

/**
 * Exports changed configuration to a branch and opens one pull request.
 *
 * The run writes the objects that differ between the export storage and the
 * sync storage to the export branch, opens a pull request from it, or updates
 * the one already open. It never merges. Files that already match the branch
 * are skipped, so a run without a new change makes no commit.
 */
class ConfigPrRunner {

  public const TRIGGER_CRON = 'cron';
  public const TRIGGER_DRUSH = 'drush';

  /**
   * The shortest interval between runs, in seconds.
   */
  public const MIN_INTERVAL = 3600;

  /**
   * The lock that keeps cron and Drush runs apart.
   */
  protected const LOCK = 'sentinel_connector_config_pr';

  /**
   * Seconds the lock lasts; renewed before each file is written.
   */
  protected const LOCK_TIMEOUT = 600.0;

  /**
   * Constructs the runner.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\sentinel_connector_config_pr\ChangeSetBuilder $changeSetBuilder
   *   Compares the export storage with the sync storage.
   * @param \Drupal\sentinel_connector_config_pr\TokenResolver $tokenResolver
   *   Supplies the access token.
   * @param \Drupal\sentinel_connector_config_pr\Host\HostFactory $hostFactory
   *   Builds the Git host.
   * @param \Drupal\sentinel_connector_config_pr\PullRequestTracker $tracker
   *   Remembers the open pull request.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The sentinel_connector log channel. Never receives the token,
   *   configuration values or messages from the Git host.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ChangeSetBuilder $changeSetBuilder,
    protected TokenResolver $tokenResolver,
    protected HostFactory $hostFactory,
    protected PullRequestTracker $tracker,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Runs from cron when cron runs are on and the interval has passed.
   *
   * Cron also needs the Sentinel Connector's own cron reporting to be on,
   * which the README turns on for production only.
   */
  public function cron(): RunResult {
    if (!$this->settings()->get('cron_enabled')) {
      return new RunResult('disabled', 'Cron runs are off.');
    }
    if (!$this->configFactory->get('sentinel_connector.settings')->get('enabled')) {
      return new RunResult('disabled', 'Sentinel Connector cron reporting is off on this environment.');
    }
    return $this->run(FALSE, FALSE, self::TRIGGER_CRON);
  }

  /**
   * Runs the export.
   *
   * @param bool $dryRun
   *   List the changed objects only; contact no Git host and write nothing.
   * @param bool $force
   *   Run even if the interval has not passed since the last run.
   * @param string $trigger
   *   One of the TRIGGER_* constants, for the log entry.
   */
  public function run(bool $dryRun = FALSE, bool $force = FALSE, string $trigger = self::TRIGGER_DRUSH): RunResult {
    $problem = $this->settingsProblem();
    if ($problem !== NULL) {
      return $this->finish(new RunResult('unconfigured', $problem), $trigger);
    }
    $now = $this->time->getCurrentTime();
    if (!$force && !$dryRun) {
      $last = $this->tracker->lastRun();
      $interval = max(self::MIN_INTERVAL, (int) $this->settings()->get('interval'));
      if ($last > 0 && $now - $last < $interval) {
        return new RunResult('not_due', sprintf('The last run was less than %d hours ago.', intdiv($interval, 3600)), $this->tracker->get()['url'] ?? NULL);
      }
    }
    if ($this->changeSetBuilder->syncStorageIsEmpty()) {
      return $this->finish(new RunResult('failed', 'The configuration sync directory is empty or missing, so nothing was exported.'), $trigger);
    }
    $changes = $this->changeSetBuilder->build($this->syncPath());
    if ($dryRun) {
      return new RunResult(
        'dry_run',
        sprintf('%d configuration objects differ from the sync directory.', count($changes)),
        $this->tracker->get()['url'] ?? NULL,
        count($changes),
        array_map(static fn (array $change): string => $change['op'], $changes),
      );
    }
    if (!$this->lock->acquire(self::LOCK, self::LOCK_TIMEOUT)) {
      return $this->finish(new RunResult('failed', 'Another run is in progress.'), $trigger);
    }
    try {
      return $this->finish($this->export($changes, $now), $trigger);
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Why the settings cannot run, or NULL when they can.
   */
  public function settingsProblem(): ?string {
    $settings = $this->settings();
    if (!isset(HostFactory::CONTROLLERS[(string) $settings->get('provider')])) {
      return 'Choose GitHub or GitLab on the settings form.';
    }
    $hasPath = trim((string) $settings->get('repo_owner')) !== '' && trim((string) $settings->get('repo_name')) !== '';
    $hasProjectId = $settings->get('provider') === 'gitlab' && trim((string) $settings->get('gitlab_project_id')) !== '';
    if (!$hasPath && !$hasProjectId) {
      return 'Set the repository owner and name on the settings form.';
    }
    $branch = trim((string) $settings->get('branch'));
    if ($branch === '' || $branch === trim((string) $settings->get('base_branch'))) {
      return 'Set an export branch that differs from the base branch.';
    }
    if ($this->syncPath() === '') {
      return 'Set the repository path of the sync directory.';
    }
    if ($this->committer()['email'] === '') {
      return 'Set a committer email, or the site email address.';
    }
    return NULL;
  }

  /**
   * Writes the changes and opens or updates the pull request.
   *
   * @param array<string, array{name: string, collection: string, op: string, content: string|null}> $changes
   *   The changed objects keyed by repository path.
   * @param int $now
   *   The current time.
   */
  protected function export(array $changes, int $now): RunResult {
    $tracked = $this->tracker->paths();
    if ($changes === [] && $tracked === [] && $this->tracker->get() === NULL) {
      $this->tracker->setLastRun($now);
      return new RunResult('unchanged', 'No configuration differs from the sync directory.');
    }
    $token = $this->tokenResolver->getToken();
    if ($token === NULL) {
      // Checked before any request, so a missing token leaves no branch. Not
      // recorded as a run: nothing was sent, and a fixed token runs at once.
      return new RunResult('no_token', 'No access token. Set a Key module key on the settings form, or $settings[\'sentinel_connector_config_pr\'][\'token\'] in settings.php.');
    }

    $step = 'connect';
    $createdBranch = FALSE;
    $host = NULL;
    $branch = trim((string) $this->settings()->get('branch'));
    try {
      $host = $this->hostFactory->create($this->committer());
      $host->connect($token);
      $base = trim((string) $this->settings()->get('base_branch')) ?: $host->defaultBranch();
      if ($base === $branch) {
        throw new HostException('The export branch is the base branch.');
      }

      $step = 'read';
      $pull = $host->findOpenPullRequest($branch);
      $head = $host->branchHead($branch);
      // Only the pull request this module opened, or a branch still at the
      // commit this module left it at in this repository, is ever written to
      // or deleted.
      $identity = $this->branchIdentity($branch);
      $known = $this->tracker->get();
      $owned = $pull !== NULL
        ? $pull['url'] === ($known['url'] ?? NULL)
        : $this->tracker->ownsBranch($identity, $head);
      if (!$owned && $pull === NULL && $head !== NULL && $known !== NULL && $this->tracker->ownsBranch($identity, $this->tracker->ownedHead())) {
        // "Update branch", a rebase or a reviewer's push moved the branch
        // before its pull request was merged or closed. The branch is still
        // this module's if it is where that pull request ended.
        $owned = $host->closedPullRequestHead($known['number']) === $head;
      }
      if ($pull !== NULL && !$owned) {
        throw new HostException('An open pull request from the export branch was not opened by this module. Choose another export branch.');
      }
      if ($head !== NULL && !$owned) {
        throw new HostException('The export branch was not created by this module, or changed after its pull request closed. Delete the branch or choose another export branch.');
      }
      if ($pull !== NULL && $pull['base'] !== $base) {
        throw new HostException('The open pull request from the export branch targets another base branch. Close it, or set that base branch.');
      }
      if ($pull === NULL) {
        // A branch left by a merged or closed pull request starts again from
        // the base branch, so the new pull request holds only current drift.
        $tracked = [];
        if ($head !== NULL) {
          $step = 'reset';
          $host->deleteBranch($branch);
          $this->tracker->clearOwnedBranch();
          $head = NULL;
        }
      }

      $desired = [];
      $paths = $tracked;
      foreach ($changes as $path => $change) {
        $desired[$path] = $change['content'];
        $paths[$path] = ['name' => $change['name'], 'collection' => $change['collection']];
      }
      // A file changed earlier on the open pull request whose object now
      // matches the sync directory goes back to the exported content.
      foreach ($tracked as $path => $item) {
        if (!array_key_exists($path, $desired)) {
          $desired[$path] = $this->changeSetBuilder->exportContent($item['name'], $item['collection']);
        }
      }

      $step = 'compare';
      $ref = $head !== NULL ? $branch : $base;
      $operations = FilePlan::plan($desired, function (string $path) use ($host, $ref): ?string {
        $this->renewLock();
        return $host->blobSha($path, $ref);
      });
      if ($operations === []) {
        $this->tracker->setLastRun($now);
        if ($pull === NULL) {
          $this->tracker->clear();
          return new RunResult('unchanged', 'The base branch already holds the exported configuration.');
        }
        $this->tracker->set($pull['url'], $pull['number'], $this->tracker->get()['updated_at'] ?? $now);
        $this->tracker->setPaths($paths);
        return new RunResult('unchanged', 'The open pull request already holds the exported configuration.', $pull['url']);
      }

      if ($head === NULL) {
        $step = 'branch';
        $host->createBranch($branch, $base);
        $createdBranch = TRUE;
        $this->tracker->setUnfinishedBranch($identity);
      }
      $step = 'write';
      if ($pull !== NULL) {
        // Recorded before writing, so a file written to the open pull request
        // is put back by a later run even if a write below fails.
        $this->tracker->setPaths($paths);
      }
      foreach ($operations as $path => $operation) {
        // config_pr makes one commit per file; keep the lock while writing.
        $this->renewLock();
        $name = $paths[$path]['name'];
        if ($operation['op'] === 'delete') {
          $host->deleteFile($path, sprintf('Delete %s (exported configuration)', $name), $branch);
        }
        else {
          $host->writeFile($path, (string) $operation['content'], sprintf('%s %s (exported configuration)', $operation['exists'] ? 'Update' : 'Add', $name), $branch, $operation['exists']);
        }
      }
      $step = 'record';
      $after = $host->branchHead($branch);
      if ($after !== NULL) {
        $this->tracker->setOwnedBranch($identity, $after);
      }
      $status = 'updated';
      if ($pull === NULL) {
        $step = 'pull_request';
        $this->renewLock();
        $pull = $host->createPullRequest($base, $branch, $this->title(), $this->body());
        $status = 'created';
      }
    }
    catch (\Throwable $error) {
      $this->tracker->setLastRun($now);
      if ($createdBranch && $host instanceof HostInterface) {
        // Leave no partial branch behind a pull request that was not opened.
        try {
          $host->deleteBranch($branch);
          $this->tracker->clearOwnedBranch();
        }
        catch (\Throwable) {
          // Still recorded as unfinished, so the next run deletes it before
          // starting again.
        }
      }
      $this->logger->error('Configuration pull request run failed at step @step (@class, code @code). @reason', [
        '@step' => $step,
        '@class' => get_class($error),
        '@code' => $error->getCode(),
        // Only this module's own messages are safe to log.
        '@reason' => $error instanceof HostException ? $error->getMessage() : '',
      ]);
      return new RunResult('failed', sprintf('The Git host step "%s" failed. Check the Drupal log.', $step));
    }

    $this->tracker->set($pull['url'], $pull['number'], $now);
    $this->tracker->setPaths($paths);
    $this->tracker->setLastRun($now);
    $message = $status === 'created' ? 'Opened a configuration pull request.' : 'Updated the open configuration pull request.';
    return new RunResult($status, $message, $pull['url'], count($operations));
  }

  /**
   * Extends the run's lock, and stops the run if another run holds it.
   */
  protected function renewLock(): void {
    if (!$this->lock->acquire(self::LOCK, self::LOCK_TIMEOUT)) {
      throw new HostException('The run lost its lock to another run.');
    }
  }

  /**
   * The export branch with the host and repository it belongs to.
   */
  public function branchIdentity(string $branch): string {
    $settings = $this->settings();
    return implode('|', [
      (string) $settings->get('provider'),
      rtrim(trim((string) $settings->get('api_base_url')), '/'),
      trim((string) $settings->get('repo_owner')),
      trim((string) $settings->get('repo_name')),
      $settings->get('provider') === 'gitlab' ? trim((string) $settings->get('gitlab_project_id')) : '',
      $branch,
    ]);
  }

  /**
   * Logs the outcome of a run and returns it.
   */
  protected function finish(RunResult $result, string $trigger): RunResult {
    $level = match ($result->status) {
      'failed', 'no_token' => 'error',
      'unconfigured' => 'warning',
      default => 'info',
    };
    // A failed Git host step has also logged its step and error class.
    $this->logger->log($level, 'Configuration pull request (@trigger): @status, @files files. @message @url', [
      '@trigger' => $trigger,
      '@status' => $result->status,
      '@files' => $result->files,
      '@message' => $result->message,
      '@url' => $result->url ?? '',
    ]);
    return $result;
  }

  /**
   * The pull request title.
   */
  protected function title(): string {
    $template = trim((string) $this->settings()->get('pr_title')) ?: 'Configuration export from {site}';
    return strtr($template, [
      '{site}' => $this->siteName(),
      '{date}' => gmdate('Y-m-d', $this->time->getCurrentTime()),
    ]);
  }

  /**
   * The pull request description.
   */
  protected function body(): string {
    return implode("\n\n", [
      sprintf('Sentinel Connector exported the configuration that changed on %s and differs from the configuration sync directory. Objects left out by Config Ignore or Config Split are not included.', $this->siteName()),
      'Review the changes and merge this pull request when they are right. Sentinel Connector never merges it. Later runs update this pull request while it is open.',
    ]);
  }

  /**
   * The committer of the export commits.
   *
   * @return array{name: string, email: string}
   *   The name and email.
   */
  protected function committer(): array {
    $settings = $this->settings();
    $site = $this->configFactory->get('system.site');
    return [
      'name' => trim((string) $settings->get('committer_name')) ?: 'Sentinel Connector',
      'email' => trim((string) $settings->get('committer_email')) ?: trim((string) $site->get('mail')),
    ];
  }

  /**
   * The site name.
   */
  protected function siteName(): string {
    return trim((string) $this->configFactory->get('system.site')->get('name')) ?: 'Drupal';
  }

  /**
   * The repository path of the sync directory, without slashes around it.
   */
  protected function syncPath(): string {
    return trim(trim((string) $this->settings()->get('sync_path')), '/');
  }

  /**
   * This module's settings.
   */
  protected function settings(): ImmutableConfig {
    return $this->configFactory->get('sentinel_connector_config_pr.settings');
  }

}
