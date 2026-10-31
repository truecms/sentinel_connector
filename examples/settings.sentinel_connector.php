<?php

/**
 * @file
 * Sentinel Connector settings for each environment.
 *
 * Copy this file next to settings.php and include it from there. Keep API keys
 * out of this file: read them from the environment or a secrets store.
 *
 * Set SENTINEL_CONNECTOR_ENVIRONMENT on each environment, or map your host's
 * own variable below (LAGOON_ENVIRONMENT_TYPE, PLATFORM_ENVIRONMENT_TYPE,
 * PANTHEON_ENVIRONMENT, AH_SITE_ENVIRONMENT...).
 *
 * Every value here can also come from the server environment instead:
 * SENTINEL_CONNECTOR_SITE_UUID, SENTINEL_CONNECTOR_API_KEY,
 * SENTINEL_CONNECTOR_API_BASE_URL and SENTINEL_CONNECTOR_DISABLED=1.
 * settings.php wins over the environment, the environment over the form.
 *
 * Help: https://sentinelconnector.io/app/support/knowledge-base/use-the-sentinel-connector-on-several-environments
 */

$sentinel_environment = getenv('SENTINEL_CONNECTOR_ENVIRONMENT') ?: 'local';

// Never send from CI or local machines.
if (getenv('CI') || in_array($sentinel_environment, ['local', 'ci'], TRUE)) {
  $settings['sentinel_connector.disabled'] = TRUE;
  return;
}

if ($sentinel_environment === 'production') {
  // The production site in Sentinel. The default API base URL is used.
  $settings['sentinel_connector.site_uuid'] = '00000000-0000-0000-0000-000000000000';
  // The key comes from SENTINEL_CONNECTOR_API_KEY in the environment.
}
elseif ($sentinel_environment === 'develop') {
  // A separate Sentinel site for non-production, with its own UUID and key.
  $settings['sentinel_connector.site_uuid'] = '11111111-1111-1111-1111-111111111111';
  $settings['sentinel_connector.api_key'] = getenv('SENTINEL_CONNECTOR_API_KEY_DEVELOP') ?: NULL;
  // Without its own key this site would fall back to the production key.
  if ($settings['sentinel_connector.api_key'] === NULL) {
    $settings['sentinel_connector.disabled'] = TRUE;
  }
  // To send to a non-production Sentinel instead, set its base URL here, for
  // example https://uat-ingest.sentinelconnector.io. Use the base URL only,
  // never the sync endpoint. Left empty, the production default is used.
  $sentinel_uat_url = '';
  if ($sentinel_uat_url !== '') {
    $settings['sentinel_connector.api_base_url'] = $sentinel_uat_url;
  }
  // To keep the web server, database and OS versions to yourself on this
  // environment, uncomment the next line. The PHP version is still sent.
  // phpcs:ignore Drupal.Commenting.InlineComment.InvalidEndChar
  // $config['sentinel_connector.settings']['report_platform'] = FALSE;
}
else {
  // Any other environment does not send.
  $settings['sentinel_connector.disabled'] = TRUE;
}
