<?php

namespace Drupal\sentinel_connector_config_pr;

use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageManagerInterface;
use Drupal\Component\Serialization\Yaml;

/**
 * Compares the export storage with the sync storage.
 *
 * The export storage is what `drush config:export` would write: active
 * configuration after the export transformations, so Config Ignore and Config
 * Split objects are already left out. The sync storage is the configuration
 * deployed with the code.
 */
class ChangeSetBuilder {

  /**
   * A copy of the export storage read by the last build.
   *
   * Core keeps the export in a shared table that another export can rebuild
   * while a run is still writing files, so a run works from its own copy.
   */
  protected ?StorageInterface $exportStorage = NULL;

  /**
   * Constructs the builder.
   *
   * @param \Drupal\Core\Config\StorageManagerInterface $exportStorageManager
   *   The export storage manager. Asked for a fresh export on every build,
   *   because the config.storage.export service keeps its first export.
   * @param \Drupal\Core\Config\StorageInterface $syncStorage
   *   The sync storage (config.storage.sync).
   */
  public function __construct(
    protected StorageManagerInterface $exportStorageManager,
    protected StorageInterface $syncStorage,
  ) {}

  /**
   * Whether the sync storage holds any configuration at all.
   *
   * An empty or missing sync directory would turn every object into a new
   * file, so the runner refuses to export in that case.
   */
  public function syncStorageIsEmpty(): bool {
    return $this->syncStorage->listAll() === [];
  }

  /**
   * The objects that differ between export and sync storage.
   *
   * @param string $syncPath
   *   The repository path of the sync directory, without a trailing slash.
   *
   * @return array<string, array{name: string, collection: string, op: string, content: string|null}>
   *   Changes keyed by repository path, sorted by path. 'op' is 'create',
   *   'update' or 'delete'; 'content' is the YAML to write, or NULL to delete.
   */
  public function build(string $syncPath): array {
    $this->exportStorage = $this->snapshot($this->exportStorageManager->getStorage());
    $changes = [];
    foreach ($this->collections() as $collection) {
      $exportData = static::readCollection($this->exportStorage, $collection);
      $syncData = static::readCollection($this->syncStorage, $collection);
      $exportNames = array_keys($exportData);
      $syncNames = array_keys($syncData);
      foreach (array_unique(array_merge($exportNames, $syncNames)) as $name) {
        $inExport = isset($exportData[$name]);
        $inSync = isset($syncData[$name]);
        if ($inExport && $inSync && $exportData[$name] === $syncData[$name]) {
          continue;
        }
        $path = static::path($syncPath, $collection, $name);
        $changes[$path] = [
          'name' => $name,
          'collection' => $collection,
          'op' => !$inSync ? 'create' : ($inExport ? 'update' : 'delete'),
          'content' => $inExport ? static::encode($exportData[$name]) : NULL,
        ];
      }
    }
    ksort($changes);
    return $changes;
  }

  /**
   * The YAML the export storage holds for one object, or NULL when absent.
   *
   * Reads the export of the last build, so a run sees one export only.
   */
  public function exportContent(string $name, string $collection): ?string {
    $this->exportStorage ??= $this->snapshot($this->exportStorageManager->getStorage());
    if ($collection !== StorageInterface::DEFAULT_COLLECTION && !in_array($collection, $this->exportStorage->getAllCollectionNames(), TRUE)) {
      return NULL;
    }
    $data = $this->exportStorage->createCollection($collection)->read($name);
    return is_array($data) ? static::encode($data) : NULL;
  }

  /**
   * The repository path of one configuration object.
   *
   * Collections use the same directories as core's file storage, so the
   * French override of system.site is language/fr/system.site.yml.
   */
  public static function path(string $syncPath, string $collection, string $name): string {
    $directory = trim($syncPath, '/');
    if ($collection !== StorageInterface::DEFAULT_COLLECTION) {
      $directory .= '/' . str_replace('.', '/', $collection);
    }
    return ltrim($directory . '/' . $name . '.yml', '/');
  }

  /**
   * Encodes configuration the way core's file storage writes it.
   *
   * @param array<mixed> $data
   *   The configuration data.
   */
  public static function encode(array $data): string {
    return Yaml::encode($data);
  }

  /**
   * Copies a storage, with all its collections, into memory.
   */
  protected static function snapshot(StorageInterface $source): StorageInterface {
    $copy = new MemoryStorage();
    foreach (array_merge([StorageInterface::DEFAULT_COLLECTION], $source->getAllCollectionNames()) as $collection) {
      $from = $source->createCollection($collection);
      $to = $copy->createCollection($collection);
      foreach ($from->readMultiple($from->listAll()) as $name => $data) {
        $to->write($name, $data);
      }
    }
    return $copy;
  }

  /**
   * Reads every object of one collection, or nothing if it does not exist.
   *
   * @return array<string, array<mixed>>
   *   The configuration data keyed by name.
   */
  protected static function readCollection(StorageInterface $storage, string $collection): array {
    if ($collection !== StorageInterface::DEFAULT_COLLECTION && !in_array($collection, $storage->getAllCollectionNames(), TRUE)) {
      return [];
    }
    $collectionStorage = $storage->createCollection($collection);
    return $collectionStorage->readMultiple($collectionStorage->listAll());
  }

  /**
   * Every collection in either storage, the default collection first.
   *
   * @return string[]
   *   The collection names.
   */
  protected function collections(): array {
    assert($this->exportStorage !== NULL);
    $collections = array_unique(array_merge(
      $this->exportStorage->getAllCollectionNames(),
      $this->syncStorage->getAllCollectionNames(),
    ));
    sort($collections);
    return array_merge([StorageInterface::DEFAULT_COLLECTION], array_values(array_diff($collections, [StorageInterface::DEFAULT_COLLECTION])));
  }

}
