<?php

namespace Drupal\sentinel_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\sentinel_connector\SyncService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles the on-demand "Sync now" action.
 */
class SyncController extends ControllerBase {

  public function __construct(protected SyncService $syncService) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('Drupal\sentinel_connector\SyncService'));
  }

  /**
   * Run a sync and redirect back to the settings form with a status message.
   */
  public function syncNow(): RedirectResponse {
    $result = $this->syncService->sync();
    if ($result->isOk()) {
      $this->messenger()->addStatus($this->t('Sentinel sync: @msg', ['@msg' => $result->message]));
    }
    else {
      $this->messenger()->addError($this->t('Sentinel sync failed (@status): @msg', [
        '@status' => $result->status,
        '@msg' => $result->message,
      ]));
    }
    return new RedirectResponse(Url::fromRoute('sentinel_connector.settings')->toString());
  }

}
