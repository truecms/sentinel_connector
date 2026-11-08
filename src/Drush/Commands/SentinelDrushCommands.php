<?php

namespace Drupal\sentinel_connector\Drush\Commands;

use Drupal\sentinel_connector\ApiBaseUrlResolver;
use Drupal\sentinel_connector\ConnectionChecker;
use Drupal\sentinel_connector\ConnectionCheckResult;
use Drupal\sentinel_connector\PushLimitFormatter;
use Drupal\sentinel_connector\ScanReportResult;
use Drupal\sentinel_connector\SentinelClient;
use Drupal\sentinel_connector\SiteUuidResolver;
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
   * The environment variable that holds the site's scan key in CI.
   */
  public const SCAN_KEY_ENV = 'SENTINEL_SCAN_KEY';

  /**
   * A scan key: the marker, then 64 hex characters.
   */
  protected const SCAN_KEY_PATTERN = '/^sk_scan_[0-9a-f]{64}$/D';

  /**
   * Constructs the commands.
   *
   * @param \Drupal\sentinel_connector\SyncService $syncService
   *   The sync service.
   * @param \Drupal\sentinel_connector\PushLimitFormatter $pushLimitFormatter
   *   Builds the messages for a refused push.
   * @param \Drupal\sentinel_connector\UpgradeStatusCollector $upgradeStatus
   *   Reads the Upgrade Status result after a scan.
   * @param \Drupal\sentinel_connector\ConnectionChecker $connectionChecker
   *   Runs the connection check.
   * @param \Drupal\sentinel_connector\ApiBaseUrlResolver $urlResolver
   *   The Sentinel API base URL, for a scan report.
   * @param \Drupal\sentinel_connector\SiteUuidResolver $uuidResolver
   *   The Site UUID, for a scan report.
   * @param \Drupal\sentinel_connector\SentinelClient $client
   *   Sends a scan report.
   */
  public function __construct(
    protected SyncService $syncService,
    protected PushLimitFormatter $pushLimitFormatter,
    protected UpgradeStatusCollector $upgradeStatus,
    protected ConnectionChecker $connectionChecker,
    protected ApiBaseUrlResolver $urlResolver,
    protected SiteUuidResolver $uuidResolver,
    protected SentinelClient $client,
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
    if ($this->syncService->hasInvalidUrl()) {
      $message = trim(strip_tags(html_entity_decode((string) $this->connectionChecker->invalidUrlMessage(), ENT_QUOTES | ENT_HTML5)));
      if ($this->syncService->hasSiteCredentials()) {
        // A site set up to report: a typo must not pass for "not configured".
        throw new \RuntimeException($message);
      }
      // An environment that does not report, such as a database copy.
      $this->logger()->warning($message);
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
   * Scan custom projects with Upgrade Status, then sync.
   *
   * Opt-in: a scan runs PHPStan over custom code, which takes minutes and
   * uses CPU. It never runs on cron or during a push. Contributed projects
   * are not scanned: their next steps come from release data.
   *
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'sentinel_connector:upgrade-status', aliases: ['sc-upgrade-status'])]
  #[CLI\Option(name: 'scan-report', description: 'In CI: send the result Upgrade Status stored to the scan route with the key in SENTINEL_SCAN_KEY. Runs no scan and ignores the off switch.')]
  #[CLI\Option(name: 'scan-started', description: 'With --scan-report: the Unix time the CI scan started. A custom project with no good scan from then on fails the report.')]
  #[CLI\Usage(name: 'drush sentinel_connector:upgrade-status', description: 'Scan custom projects with Upgrade Status, then push the readiness to Sentinel.')]
  #[CLI\Usage(name: 'drush sentinel_connector:upgrade-status --scan-report --scan-started=1793000000', description: 'In CI, after "drush upgrade_status:analyze --all": send the stored result to Sentinel.')]
  public function upgradeStatus(array $options = ['scan-report' => FALSE, 'scan-started' => self::REQ]): void {
    if (!empty($options['scan-report'])) {
      $this->scanReport($options['scan-started'] ?? NULL);
      return;
    }
    // First: shared deploy scripts run this where nothing reports.
    if ($this->syncService->isDisabled()) {
      $this->logger()->notice($this->syncService->disabledMessage());
      return;
    }
    if (!$this->upgradeStatus->isDetected()) {
      throw new \RuntimeException(dt('Upgrade Status is not enabled. Add it with "composer require --dev drupal/upgrade_status" and "drush pm:install upgrade_status", then run this command again.'));
    }
    // A scan takes minutes: do not run one whose result cannot be sent.
    if (!$this->syncService->isConfigured()) {
      throw new \RuntimeException(dt('Sentinel connector is not fully configured; no scan was run.'));
    }
    if ($this->syncService->deferredResult() !== NULL) {
      $this->report($this->syncService->sync(SyncService::TRIGGER_DRUSH));
      return;
    }
    $this->logger()->notice(dt('Scanning custom projects with Upgrade Status. This can take several minutes.'));
    $started = $this->upgradeStatus->now();
    $exit = $this->scan();
    // Upgrade Status exits with 3 when the scan found problems: that is a
    // result, not a failure.
    if ($exit !== self::EXIT_SUCCESS && $exit !== self::EXIT_FAILURE_WITH_CLARITY) {
      throw new \RuntimeException(dt('The Upgrade Status scan failed (exit code @code). No push was sent.', ['@code' => $exit]));
    }
    // Exit code 3 also covers a project whose scan failed. That leaves no
    // result, an older one or a new one holding the failure, none of which
    // must be reported as current.
    $unscanned = $this->upgradeStatus->customProjectsNotScannedSince($started);
    if ($unscanned === NULL) {
      throw new \RuntimeException(dt('The Upgrade Status result could not be checked. No push was sent.'));
    }
    if ($unscanned > 0) {
      throw new \RuntimeException(dt('The Upgrade Status scan did not finish for @count custom project(s). No push was sent.', ['@count' => $unscanned]));
    }
    $block = $this->upgradeStatus->collect(FALSE);
    if ($block === NULL) {
      $this->logger()->warning(dt('Upgrade Status reporting is switched off in the Sentinel connector settings; the push carries no readiness.'));
    }
    else {
      $this->logReadiness($block);
    }
    $this->report($this->syncService->sync(SyncService::TRIGGER_DRUSH));
  }

  /**
   * Sends the stored Upgrade Status result as a CI scan report.
   *
   * Runs no scan: the CI job has run one. Ignores the off switch, which CI
   * builds normally set, and needs no connector API key: the scan key in
   * SENTINEL_SCAN_KEY is the only credential, and it is never printed. Only
   * counts, dates and versions are sent, as in a push.
   *
   * @param mixed $started
   *   The Unix time the CI scan started, from --scan-started. Without it,
   *   only failed and missing scans are caught, not a result older than the
   *   scan.
   */
  protected function scanReport(mixed $started): void {
    $since = 0;
    if ($started !== NULL && $started !== '' && $started !== TRUE) {
      if (!is_scalar($started) || !ctype_digit((string) $started)) {
        throw new \RuntimeException(dt('--scan-started must be a Unix time in seconds. Nothing was sent.'));
      }
      $since = (int) $started;
    }
    $key = getenv(self::SCAN_KEY_ENV);
    if (!is_string($key) || $key === '') {
      throw new \RuntimeException(dt('Set @env to the site\'s scan key, from the site\'s Setup tab in Sentinel. Nothing was sent.', ['@env' => self::SCAN_KEY_ENV]));
    }
    if (!preg_match(self::SCAN_KEY_PATTERN, trim($key))) {
      throw new \RuntimeException(dt('@env does not hold a scan key. Scan keys start with sk_scan_. Nothing was sent.', ['@env' => self::SCAN_KEY_ENV]));
    }
    $key = trim($key);
    $url = $this->urlResolver->getUrl();
    if ($url === NULL) {
      throw new \RuntimeException(dt('The Sentinel API base URL is not valid. Nothing was sent.'));
    }
    $uuid = $this->uuidResolver->getUuid();
    if ($uuid === NULL) {
      throw new \RuntimeException(dt('Set @env to the site\'s Site UUID. Nothing was sent.', ['@env' => SiteUuidResolver::ENV_VAR]));
    }
    $block = $this->upgradeStatus->collectForScan();
    if ($block === NULL) {
      throw new \RuntimeException(dt('Upgrade Status is not enabled in this build. Nothing was sent.'));
    }
    if ($block['status'] === UpgradeStatusCollector::STATUS_UNAVAILABLE) {
      // Tell Sentinel the scan did not finish, then fail: this is a fault.
      throw new \RuntimeException(dt('The Upgrade Status result could not be read (@reason). @sent', [
        '@reason' => $block['reason'],
        '@sent' => $this->reportScanFailed($url, $uuid, $key, $block['generated_at']),
      ]));
    }
    // As for the scan this command runs: a custom project whose scan failed,
    // or that kept a result older than this scan, must not be reported as
    // current. Sentinel is told the scan did not finish instead.
    $unscanned = $this->upgradeStatus->customProjectsNotScannedSince($since);
    if ($unscanned === NULL || $unscanned > 0) {
      throw new \RuntimeException(dt('@what @sent', [
        '@what' => $unscanned === NULL
          ? dt('The Upgrade Status result could not be checked.')
          : dt('The Upgrade Status scan did not finish for @count custom project(s).', ['@count' => $unscanned]),
        '@sent' => $this->reportScanFailed($url, $uuid, $key, $block['generated_at']),
      ]));
    }
    $this->logReadiness($block);
    $result = $this->client->sendScan($url, $uuid, $key, ['upgrade_status' => $block]);
    if ($result->isAccepted()) {
      $this->logger()->success(dt('Sentinel scan report: @msg', ['@msg' => $result->message]));
      return;
    }
    if ($result->outcome === ScanReportResult::CLOSED) {
      // Not switched on yet for this site: a pipeline must not fail on it.
      $this->logger()->notice($result->message);
      return;
    }
    throw new \RuntimeException(dt('Sentinel scan report failed: @msg', ['@msg' => $result->message]));
  }

  /**
   * Tells Sentinel the CI scan did not finish, and says whether it heard.
   */
  protected function reportScanFailed(string $url, string $uuid, string $key, string $generatedAt): string {
    $problem = ['reason' => 'scan_failed', 'generated_at' => $generatedAt];
    $sent = $this->client->sendScan($url, $uuid, $key, ['problem' => $problem]);
    return $sent->isAccepted() ? (string) dt('Sentinel was told the scan did not finish.') : $sent->message;
  }

  /**
   * Logs the readiness a block reports, in counts.
   *
   * @param array<string, mixed> $block
   *   The upgrade_status block.
   */
  protected function logReadiness(array $block): void {
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

  /**
   * Runs the Upgrade Status scan of custom projects and returns its exit code.
   */
  protected function scan(): int {
    $process = Drush::drush(Drush::aliasManager()->getSelf(), 'upgrade_status:analyze', [], [
      'all' => TRUE,
      'ignore-contrib' => TRUE,
      // Not "ignore-uninstalled": that also skips an active custom install
      // profile, which reports as not installed but must be scanned.
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
