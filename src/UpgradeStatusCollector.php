<?php

namespace Drupal\sentinel_connector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports the Drupal upgrade readiness that Upgrade Status stored.
 *
 * Reads the next step Upgrade Status gives each project and the dates of its
 * stored scans. Only counts are reported, never a project name, path or
 * message. Upgrade Status has no public API for this, so every read is
 * guarded: a missing service, a changed method or unexpected data is
 * reported as an unavailable block. No method throws.
 */
class UpgradeStatusCollector {

  public const STATUS_READY = 'ready';
  public const STATUS_NOT_READY = 'not_ready';
  public const STATUS_INCOMPLETE = 'incomplete';
  public const STATUS_NOT_SCANNED = 'not_scanned';
  public const STATUS_UNAVAILABLE = 'unavailable';

  public const REASON_SERVICE_MISSING = 'service_missing';
  public const REASON_UNSUPPORTED_API = 'unsupported_api';
  public const REASON_UNEXPECTED_DATA = 'unexpected_data';
  public const REASON_ERROR = 'error';

  /**
   * The project collector service of Upgrade Status.
   */
  public const SERVICE = 'upgrade_status.project_collector';

  /**
   * The key value collection holding one scan result per project.
   */
  public const SCAN_RESULTS = 'upgrade_status_scan_results';

  /**
   * The file name Upgrade Status stores in a result when PHPStan failed.
   */
  public const PHPSTAN_FAILED = 'PHPStan failed';

  /**
   * The settings.php key that overrides the reporting switch.
   */
  public const SETTINGS_KEY = 'sentinel_connector.report_upgrade_status';

  /**
   * Next steps of Upgrade Status, in the order they are counted.
   */
  public const NEXT_STEPS = ['relax', 'update', 'collaborate', 'rector', 'manual', 'remove', 'scan'];

  /**
   * Next steps that make a site not ready. "remove" is a warning only.
   */
  protected const BLOCKING = ['update', 'collaborate', 'rector', 'manual'];

  /**
   * Next steps of a custom project that need code changes.
   */
  protected const CUSTOM_BLOCKING = ['rector', 'manual'];

  /**
   * Constructs an UpgradeStatusCollector.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container. The project collector is fetched only when a
   *   block is built: it is not created on every cron run and Drush command.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, to tell whether Upgrade Status is enabled.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module extension list, for the version of Upgrade Status.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   The key value factory holding the stored scan results.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the reporting switch.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings, which may override the reporting switch.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   Receives one warning when the readiness cannot be read.
   */
  public function __construct(
    protected ContainerInterface $container,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleList,
    protected KeyValueFactoryInterface $keyValue,
    protected ConfigFactoryInterface $configFactory,
    protected Settings $settings,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether the Upgrade Status module is enabled, FALSE when unknown.
   */
  public function isDetected(): bool {
    try {
      return $this->moduleHandler->moduleExists('upgrade_status');
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Whether readiness is reported: settings.php first, then configuration.
   */
  public function isReportingEnabled(): bool {
    try {
      $override = $this->settings->get(self::SETTINGS_KEY);
      if ($override !== NULL) {
        return (bool) $override;
      }
      return $this->configFactory->get('sentinel_connector.settings')->get('report_upgrade_status') !== FALSE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Whether settings.php sets the reporting switch.
   */
  public function isReportingOverridden(): bool {
    try {
      return $this->settings->get(self::SETTINGS_KEY) !== NULL;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * The version of Upgrade Status, NULL when unknown.
   */
  public function moduleVersion(): ?string {
    try {
      $version = $this->moduleList->getExtensionInfo('upgrade_status')['version'] ?? NULL;
      // Sentinel refuses a push whose version is not a plain release string.
      return is_string($version) && preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,49}$/D', trim($version)) ? trim($version) : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Returns the payload's upgrade_status block.
   *
   * @param bool $log
   *   FALSE to leave out the warning for an unavailable block, for a second
   *   read in the same request.
   *
   * @return array<string, mixed>|null
   *   The block, or NULL when Upgrade Status is not enabled or reporting is
   *   switched off.
   */
  public function collect(bool $log = TRUE): ?array {
    if (!$this->isDetected() || !$this->isReportingEnabled()) {
      return NULL;
    }
    try {
      $block = $this->report();
    }
    catch (\Throwable) {
      $block = $this->unavailable(self::REASON_ERROR);
    }
    if ($log && $block['status'] === self::STATUS_UNAVAILABLE) {
      try {
        $this->logger->warning('Sentinel could not read the Upgrade Status result (@reason). The push reports it as unavailable.', ['@reason' => $block['reason']]);
      }
      catch (\Throwable) {
      }
    }
    return $block;
  }

  /**
   * Counts custom projects with no good scan from the given time or later.
   *
   * A failed PHPStan run still stores a result with a new date, so a result
   * that holds the failure counts as not scanned.
   *
   * @param int $since
   *   The Unix time the scan started.
   *
   * @return int|null
   *   The number of projects, NULL when reading them failed. 0 when Upgrade
   *   Status gives no project list: the push then reports the block as
   *   unavailable.
   */
  public function customProjectsNotScannedSince(int $since): ?int {
    try {
      $collector = $this->projectCollector();
      $projects = $collector !== NULL && method_exists($collector, 'collectProjects') ? $collector->collectProjects() : NULL;
      if (!is_array($projects)) {
        return 0;
      }
      $custom = [];
      foreach ($projects as $name => $project) {
        $info = is_object($project) && isset($project->info) && is_array($project->info) ? $project->info : [];
        // Uninstalled custom code is not scanned: it only needs removing.
        if (($info['upgrade_status_type'] ?? NULL) === 'custom' && ($info['upgrade_status_next'] ?? NULL) !== 'remove') {
          $custom[] = (string) $name;
        }
      }
      $results = $custom === [] ? [] : $this->keyValue->get(self::SCAN_RESULTS)->getMultiple($custom);
      $missing = 0;
      foreach ($custom as $name) {
        $result = is_array($results[$name] ?? NULL) ? $results[$name] : [];
        $date = $result['date'] ?? NULL;
        $scanned = is_int($date) || (is_string($date) && ctype_digit($date));
        $missing += $scanned && !$this->scanFailed($result) && (int) $date >= $since ? 0 : 1;
      }
      return $missing;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Builds the block from the project list and the stored scans.
   *
   * @return array<string, mixed>
   *   The block.
   */
  protected function report(): array {
    $collector = $this->projectCollector();
    if ($collector === NULL) {
      return $this->unavailable(self::REASON_SERVICE_MISSING);
    }
    if (!method_exists($collector, 'collectProjects')) {
      return $this->unavailable(self::REASON_UNSUPPORTED_API);
    }
    $projects = $collector->collectProjects();
    if (!is_array($projects)) {
      return $this->unavailable(self::REASON_UNEXPECTED_DATA);
    }

    $names = array_values(array_filter(array_keys($projects), 'is_string'));
    $results = $names === [] ? [] : $this->keyValue->get(self::SCAN_RESULTS)->getMultiple($names);

    $counts = array_fill_keys(self::NEXT_STEPS, 0);
    $customTotal = 0;
    $customScan = 0;
    $customNotReady = 0;
    $custom = [];
    foreach ($projects as $name => $project) {
      $info = is_object($project) && isset($project->info) && is_array($project->info) ? $project->info : NULL;
      $next = $info['upgrade_status_next'] ?? NULL;
      if (!is_string($name) || $name === '' || !is_string($next) || !isset($counts[$next])) {
        return $this->unavailable(self::REASON_UNEXPECTED_DATA);
      }
      // A failed scan says nothing about the code, so it still needs a scan.
      if (in_array($next, self::CUSTOM_BLOCKING, TRUE) && $this->scanFailed($results[$name] ?? NULL)) {
        $next = 'scan';
      }
      $counts[$next]++;
      // Uninstalled custom code is not scanned: it only needs removing.
      if (($info['upgrade_status_type'] ?? NULL) === 'custom' && $next !== 'remove') {
        $customTotal++;
        $custom[] = $name;
        $customScan += $next === 'scan' ? 1 : 0;
        $customNotReady += in_array($next, self::CUSTOM_BLOCKING, TRUE) ? 1 : 0;
      }
    }

    // Only results of projects on the site count: Upgrade Status keeps the
    // results of deleted projects.
    $dates = [];
    $customScanned = FALSE;
    foreach ($results as $name => $result) {
      if (!is_array($result) || $this->scanFailed($result)) {
        continue;
      }
      $customScanned = $customScanned || in_array($name, $custom, TRUE);
      $date = $result['date'] ?? NULL;
      if (is_int($date) || (is_string($date) && ctype_digit($date))) {
        if ((int) $date > 0) {
          $dates[] = (int) $date;
        }
      }
    }

    $blocking = 0;
    foreach (self::BLOCKING as $step) {
      $blocking += $counts[$step];
    }
    $status = match (TRUE) {
      $blocking > 0 => self::STATUS_NOT_READY,
      $customTotal > 0 && $customScan === $customTotal && !$customScanned => self::STATUS_NOT_SCANNED,
      $counts['scan'] > 0 => self::STATUS_INCOMPLETE,
      default => self::STATUS_READY,
    };
    $current = $this->currentMajor($collector);
    return [
      'module_version' => $this->moduleVersion(),
      'current_major' => $current,
      'target_major' => $current + 1,
      'status' => $status,
      'projects_total' => count($projects),
      'counts' => $counts,
      'custom_not_ready' => $customNotReady,
      'reason' => NULL,
      'newest_scan_at' => $dates === [] ? NULL : $this->format(max($dates)),
      'oldest_scan_at' => $dates === [] ? NULL : $this->format(min($dates)),
      'generated_at' => $this->format($this->now()),
    ];
  }

  /**
   * Whether a stored scan result holds a failed PHPStan run.
   */
  protected function scanFailed(mixed $result): bool {
    return is_array($result) && isset($result['data']['files'][self::PHPSTAN_FAILED]);
  }

  /**
   * The project collector of Upgrade Status, NULL when there is none.
   *
   * Typed loosely: its API is not public and can change between releases.
   */
  protected function projectCollector(): ?object {
    return $this->container->get(self::SERVICE, ContainerInterface::NULL_ON_INVALID_REFERENCE);
  }

  /**
   * The core major Upgrade Status compares with, as it computes it.
   */
  protected function currentMajor(?object $collector = NULL): int {
    if ($collector !== NULL && is_callable([$collector, 'getDrupalCoreMajorVersion'])) {
      try {
        $major = call_user_func([$collector, 'getDrupalCoreMajorVersion']);
        if (is_int($major) && $major > 0) {
          return $major;
        }
      }
      catch (\Throwable) {
      }
    }
    return (int) \Drupal::VERSION;
  }

  /**
   * An unavailable block: no counts, so Sentinel keeps the last good result.
   *
   * @return array<string, mixed>
   *   The block.
   */
  protected function unavailable(string $reason): array {
    $current = $this->currentMajor();
    return [
      'module_version' => $this->moduleVersion(),
      'current_major' => $current,
      'target_major' => $current + 1,
      'status' => self::STATUS_UNAVAILABLE,
      'projects_total' => 0,
      'counts' => array_fill_keys(self::NEXT_STEPS, 0),
      'custom_not_ready' => 0,
      'reason' => $reason,
      'newest_scan_at' => NULL,
      'oldest_scan_at' => NULL,
      'generated_at' => $this->format($this->now()),
    ];
  }

  /**
   * The current Unix time.
   */
  public function now(): int {
    try {
      return $this->time->getCurrentTime();
    }
    catch (\Throwable) {
      return time();
    }
  }

  /**
   * A Unix time as an ISO 8601 UTC timestamp.
   */
  protected function format(int $time): string {
    return gmdate('Y-m-d\TH:i:s\Z', $time);
  }

}
