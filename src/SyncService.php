<?php

namespace Drupal\sentinel_connector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Single entry point for a Sentinel sync, shared by cron, drush, and the UI.
 */
class SyncService {

  /**
   * Constructs the sync service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store for last-sync bookkeeping.
   * @param \Drupal\sentinel_connector\PayloadBuilder $payloadBuilder
   *   Builds the sync payload from the extension list.
   * @param \Drupal\sentinel_connector\ApiKeyResolver $apiKeyResolver
   *   Resolves the API key.
   * @param \Drupal\sentinel_connector\SentinelClient $client
   *   The HTTP client for the Sentinel backend.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module logger channel.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected PayloadBuilder $payloadBuilder,
    protected ApiKeyResolver $apiKeyResolver,
    protected SentinelClient $client,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
  ) {}

  /**
   * Run a sync now. Returns a SyncResult describing the outcome.
   */
  public function sync(): SyncResult {
    $config = $this->configFactory->get('sentinel_connector.settings');
    $baseUrl = (string) $config->get('api_base_url');
    $uuid = (string) $config->get('site_uuid');
    $apiKey = $this->apiKeyResolver->getKey();

    if ($baseUrl === '' || $uuid === '' || $apiKey === NULL) {
      $result = SyncResult::failure('unconfigured', NULL, 'Sentinel connector is not fully configured.');
      $this->recordResult($result);
      return $result;
    }

    $payload = $this->payloadBuilder->build((string) ($config->get('report_scope') ?: 'all'));
    $payload['site'] = [
      'url' => (string) $config->get('site_url'),
      'name' => (string) $config->get('site_name'),
      'token' => (string) $config->get('site_token'),
      'uuid' => $uuid,
    ];

    $result = $this->client->sync($baseUrl, $uuid, $apiKey, $payload);
    $this->recordResult($result);
    return $result;
  }

  /**
   * Whether cron is due to run a sync, per configured interval.
   */
  public function cronIsDue(int $now): bool {
    $config = $this->configFactory->get('sentinel_connector.settings');
    if (!$config->get('enabled')) {
      return FALSE;
    }
    $interval = (int) ($config->get('cron_interval') ?: 21600);
    $last = (int) $this->state->get('sentinel_connector.last_sync_time', 0);
    return ($now - $last) >= $interval;
  }

  /**
   * Persist the outcome to state for the settings-form status line and cron.
   */
  protected function recordResult(SyncResult $result): void {
    if ($result->isOk()) {
      $this->state->set('sentinel_connector.last_sync_time', $this->time->getRequestTime());
    }
    $this->state->set('sentinel_connector.last_result', $result->status);
    $this->state->set('sentinel_connector.last_message', $result->message);
    $this->state->set('sentinel_connector.last_task_id', $result->taskId);
  }

}
