<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;

/**
 * Core's storage comparer, stopped between collections at a deadline.
 *
 * The comparison is core's own; only the loop over collections is repeated
 * here so that a large site can stop part way and report what it found.
 *
 * @internal
 */
class ConfigDriftStorageComparer extends StorageComparer {

  /**
   * Builds the change list until done or until the deadline has passed.
   *
   * @param callable(): bool $expired
   *   Returns TRUE once the time budget is spent. Checked before each
   *   collection and between its steps.
   *
   * @return string[]|null
   *   The collections compared in full, or NULL when every collection was.
   */
  public function createChangelistWithin(callable $expired): ?array {
    $done = [];
    foreach ($this->getAllCollectionNames() as $collection) {
      $steps = [
        fn () => $this->getAndSortConfigData($collection),
        fn () => $this->addChangelistCreate($collection),
        fn () => $this->addChangelistUpdate($collection),
        fn () => $this->addChangelistDelete($collection),
      ];
      // Only collections that support configuration entities can have renames.
      if ($collection === StorageInterface::DEFAULT_COLLECTION) {
        $steps[] = fn () => $this->addChangelistRename($collection);
      }
      $this->changelist[$collection] = $this->getEmptyChangelist();
      foreach ($steps as $step) {
        if ($expired()) {
          // A collection cut short is left out: its list could be wrong.
          unset($this->changelist[$collection]);
          return $done;
        }
        $step();
      }
      $done[] = $collection;
    }
    return NULL;
  }

}
