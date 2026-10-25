<?php

namespace Drupal\sentinel_connector_config_pr_test;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the sync version of system.date in exports, like Config Ignore.
 */
class IgnoreSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Config\StorageInterface $syncStorage
   *   The sync storage.
   */
  public function __construct(
    protected StorageInterface $syncStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::STORAGE_TRANSFORM_EXPORT => 'onExport'];
  }

  /**
   * Replaces the exported system.date with the sync version.
   */
  public function onExport(StorageTransformEvent $event): void {
    $storage = $event->getStorage();
    $sync = $this->syncStorage->read('system.date');
    if (is_array($sync)) {
      $storage->write('system.date', $sync);
    }
    else {
      $storage->delete('system.date');
    }
  }

}
