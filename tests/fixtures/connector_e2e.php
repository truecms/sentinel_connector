<?php

/**
 * @file
 * Real local API smoke fixture. Run with drush php:script.
 *
 * Requires SENTINEL_E2E_ALLOW=1 and the normal once-issued site key in the
 * environment. The original Drupal configuration and sync state are restored.
 */

use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\sentinel_connector\SyncService;

if (getenv('SENTINEL_E2E_ALLOW') !== '1') {
  throw new RuntimeException('Explicitly enable this fixture only on an isolated local Drupal site.');
}
// Higher-priority keys would override the fixture key stored in state.
$source = \Drupal::service(ApiKeyResolver::class)->getSource();
if (in_array($source, ['settings.php', 'environment'], TRUE)) {
  throw new RuntimeException('Remove the settings.php or SENTINEL_CONNECTOR_API_KEY override on this isolated site before running the fixture.');
}
$fields = [
  'api_base_url' => 'SENTINEL_E2E_API_URL',
  'site_uuid' => 'SENTINEL_E2E_SITE_UUID',
  'site_url' => 'SENTINEL_E2E_SITE_URL',
  'site_name' => 'SENTINEL_E2E_SITE_NAME',
];
$config = \Drupal::configFactory()->getEditable('sentinel_connector.settings');
$original = $config->getRawData();
$state = \Drupal::state();
$stateKeys = [
  'api_key',
  'last_sync_time',
  'last_attempt_time',
  'last_result',
  'last_message',
  'last_task_id',
  'last_accepted_time',
  'configured_time',
  'next_allowed_at',
  'push_limit_message',
  'subscription_hold_until',
  'subscription_reason',
];
$originalState = [];
foreach ($stateKeys as $key) {
  $originalState[$key] = $state->get('sentinel_connector.' . $key);
}
try {
  foreach ($fields as $field => $env) {
    $value = getenv($env);
    if (!is_string($value) || $value === '') {
      throw new RuntimeException('Missing fixture environment variable: ' . $env);
    }
    $config->set($field, $value);
  }
  $key = getenv('SENTINEL_E2E_API_KEY');
  if (!is_string($key) || $key === '') {
    throw new RuntimeException('Set SENTINEL_E2E_API_KEY using a normally issued site key.');
  }
  $config->set('enabled', TRUE)->save();
  $state->set('sentinel_connector.api_key', $key);
  $result = \Drupal::service(SyncService::class)->sync();
  if ($result->status !== 'success') {
    throw new RuntimeException('Connector E2E failed with status: ' . $result->status);
  }
  if (!$state->get('sentinel_connector.last_attempt_time') || !$state->get('sentinel_connector.last_sync_time')) {
    throw new RuntimeException('Successful sync did not record attempt and success timestamps.');
  }
  print json_encode([
    'status' => $result->status,
    'http_code' => $result->httpCode,
    'core' => \Drupal::VERSION,
    'php' => PHP_VERSION,
  ]) . PHP_EOL;
}
finally {
  $config->setData($original)->save();
  foreach ($originalState as $key => $value) {
    if ($value === NULL) {
      $state->delete('sentinel_connector.' . $key);
    }
    else {
      $state->set('sentinel_connector.' . $key, $value);
    }
  }
}
