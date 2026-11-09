<?php

namespace Drupal\sentinel_connector\EventSubscriber;

use Drupal\sentinel_connector\StatusReportCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Builds the status report snapshot after a web response is sent.
 *
 * At most once every 12 hours, by one request at a time, never on a page
 * cache hit or during update.php. The visitor never waits for it: the
 * response has already been sent.
 */
class StatusReportSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected StatusReportCollector $collector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the other terminate listeners, such as the page cache.
    return [KernelEvents::TERMINATE => ['onTerminate', -100]];
  }

  /**
   * Builds the snapshot when one is due.
   */
  public function onTerminate(TerminateEvent $event): void {
    if ($event->getResponse()->headers->get('X-Drupal-Cache') === 'HIT') {
      return;
    }
    // update.php runs with modules and their schema half updated.
    if (defined('MAINTENANCE_MODE')) {
      return;
    }
    try {
      $this->collector->refreshSnapshot();
    }
    catch (\Throwable) {
      // Never break a page for a value only the next sync needs.
    }
  }

}
