<?php

namespace Drupal\sentinel_connector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;

/**
 * Reports which configuration differs between production and code.
 *
 * Compares the export storage (active configuration after export
 * transformations, so Config Ignore and Config Split are respected) with the
 * sync storage, as `drush config:status` does. Only object names, their
 * collection and the type of change are reported, never a value. No method
 * throws: a failure is reported as an unavailable drift block.
 */
class ConfigDriftCollector {

  /**
   * Most objects listed in the payload. The total is always exact.
   */
  public const OBJECT_LIMIT = 200;

  /**
   * Seconds the comparison may take before it reports what it found.
   */
  public const TIME_BUDGET = 10.0;

  public const STATUS_COMPLETE = 'complete';
  public const STATUS_INCOMPLETE = 'incomplete';
  public const STATUS_UNAVAILABLE = 'unavailable';

  public const REASON_SYNC_DIRECTORY_MISSING = 'sync_directory_missing';
  public const REASON_SYNC_UNREADABLE = 'sync_unreadable';
  public const REASON_TIME_BUDGET = 'time_budget';
  public const REASON_ERROR = 'error';
  public const REASON_DISABLED = 'disabled';

  /**
   * Comparer operations, each reported as the change type of the same name.
   *
   * The export storage is the source: "create" is only in production and
   * "delete" only in code.
   */
  protected const OPERATIONS = ['create', 'update', 'delete', 'rename'];

  /**
   * Constructs a ConfigDriftCollector.
   *
   * @param \Drupal\Core\Config\StorageManagerInterface $exportStorageManager
   *   Builds the export storage afresh on each call.
   * @param \Closure(): \Drupal\Core\Config\StorageInterface $syncStorage
   *   Returns the sync storage. A closure, because core throws while building
   *   it when no sync directory is set.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the reporting switch.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, to tell whether config_pr is enabled.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings, which name the sync directory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, also the clock of the time budget.
   * @param float $timeBudget
   *   Seconds the comparison may take.
   */
  public function __construct(
    protected StorageManagerInterface $exportStorageManager,
    protected \Closure $syncStorage,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected Settings $settings,
    protected TimeInterface $time,
    protected float $timeBudget = self::TIME_BUDGET,
  ) {}

  /**
   * Returns the payload's config_drift block.
   *
   * @return array<string, mixed>
   *   The status, reason, generated_at, total, config_pr_enabled, truncated
   *   and objects keys.
   */
  public function collect(): array {
    $configPr = $this->configPrEnabled();
    try {
      if ($this->configFactory->get('sentinel_connector.settings')->get('report_config_drift') === FALSE) {
        return $this->unavailable(self::REASON_DISABLED, $configPr);
      }
      $directory = $this->settings->get('config_sync_directory');
      if (!is_string($directory) || $directory === '' || !@is_dir($directory)) {
        return $this->unavailable(self::REASON_SYNC_DIRECTORY_MISSING, $configPr);
      }
      if (!@is_readable($directory) || !$this->collectionsReadable($directory)) {
        return $this->unavailable(self::REASON_SYNC_UNREADABLE, $configPr);
      }
      return $this->compare($configPr);
    }
    catch (\Throwable) {
      return $this->unavailable(self::REASON_ERROR, $configPr);
    }
  }

  /**
   * Compares the storages within the time budget.
   *
   * @return array<string, mixed>
   *   A complete or incomplete drift block.
   */
  protected function compare(bool $configPr): array {
    $start = $this->time->getCurrentMicroTime();
    $expired = fn (): bool => $this->time->getCurrentMicroTime() - $start >= $this->timeBudget;
    $sync = ($this->syncStorage)();
    // The sync storage is readable only when it lists without failing.
    try {
      $sync->listAll();
    }
    catch (\Throwable) {
      return $this->unavailable(self::REASON_SYNC_UNREADABLE, $configPr);
    }
    $comparer = new ConfigDriftStorageComparer($this->exportStorageManager->getStorage(), $sync);
    $done = $comparer->createChangelistWithin($expired);
    $collections = $done ?? $comparer->getAllCollectionNames();

    $objects = [];
    foreach ($collections as $collection) {
      foreach ($this->changes($comparer, $collection) as $name => $change) {
        $objects[] = ['name' => $name, 'collection' => $collection, 'change' => $change];
      }
    }
    usort($objects, fn (array $a, array $b): int => [$a['collection'], $a['name']] <=> [$b['collection'], $b['name']]);
    $total = count($objects);
    $complete = $done === NULL;
    return [
      'status' => $complete ? self::STATUS_COMPLETE : self::STATUS_INCOMPLETE,
      'reason' => $complete ? NULL : self::REASON_TIME_BUDGET,
      'generated_at' => $this->generatedAt(),
      'total' => $total,
      'config_pr_enabled' => $configPr,
      'truncated' => $total > self::OBJECT_LIMIT,
      'objects' => array_slice($objects, 0, self::OBJECT_LIMIT),
    ];
  }

  /**
   * The change type of each object in one collection.
   *
   * An object recreated under the same name (another UUID) is in both the
   * create and delete lists; it is reported once, as an update. A rename is
   * reported under its name in production.
   *
   * @return array<string, string>
   *   Change types keyed by object name.
   */
  protected function changes(ConfigDriftStorageComparer $comparer, string $collection): array {
    $changes = [];
    foreach (self::OPERATIONS as $op) {
      foreach ($comparer->getChangelist($op, $collection) as $name) {
        if ($op === 'rename') {
          $name = $comparer->extractRenameNames($name)['new_name'];
        }
        $changes[$name] = isset($changes[$name]) ? 'update' : $op;
      }
    }
    return $changes;
  }

  /**
   * Whether the sync directory and its collection directories can be listed.
   *
   * Core's file storage lists an unreadable directory as empty, which would
   * report every object as created in production.
   */
  protected function collectionsReadable(string $directory): bool {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST,
      \RecursiveIteratorIterator::CATCH_GET_CHILD,
    );
    foreach ($iterator as $file) {
      if ($file->isDir() && !$file->isReadable()) {
        return FALSE;
      }
    }
    return @scandir($directory) !== FALSE;
  }

  /**
   * An unavailable drift block.
   *
   * @return array<string, mixed>
   *   The block with no objects.
   */
  protected function unavailable(string $reason, bool $configPr): array {
    return [
      'status' => self::STATUS_UNAVAILABLE,
      'reason' => $reason,
      'generated_at' => $this->generatedAt(),
      'total' => 0,
      'config_pr_enabled' => $configPr,
      'truncated' => FALSE,
      'objects' => [],
    ];
  }

  /**
   * Whether the config_pr module is enabled, FALSE when unknown.
   */
  protected function configPrEnabled(): bool {
    try {
      return $this->moduleHandler->moduleExists('config_pr');
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * The current time as an ISO 8601 UTC timestamp.
   */
  protected function generatedAt(): string {
    try {
      $now = $this->time->getCurrentTime();
    }
    catch (\Throwable) {
      $now = time();
    }
    return gmdate('Y-m-d\TH:i:s\Z', $now);
  }

}
