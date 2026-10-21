<?php

namespace Drupal\sentinel_connector\EventSubscriber;

use Drupal\sentinel_connector\PlatformCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Remembers the web server software for syncs from cron and Drush.
 *
 * Runs after the response is sent, and writes state only when the value
 * changed.
 */
class WebServerSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected PlatformCollector $collector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

  /**
   * Stores the request's SERVER_SOFTWARE.
   */
  public function onTerminate(TerminateEvent $event): void {
    try {
      $this->collector->rememberWebServer($event->getRequest());
    }
    catch (\Throwable) {
      // Never break a page for a value only the next sync needs.
    }
  }

}
