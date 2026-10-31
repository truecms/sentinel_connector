<?php

namespace Drupal\sentinel_connector;

/**
 * Moves saved settings to the minimal form of payload version 4.
 *
 * Pure, so the update hook's mapping can be tested without Drupal.
 */
final class SettingsUpgrade {

  /**
   * Settings the form no longer has.
   */
  public const REMOVED_KEYS = ['api_base_url', 'site_url', 'site_name'];

  /**
   * Brings saved settings into the new shape.
   *
   * The API base URL, site URL and site name are removed, a legacy report
   * scope becomes its replacement, and platform reporting is turned on when
   * it was never set, which keeps what the site sent before.
   *
   * @param array<string, mixed> $data
   *   The saved sentinel_connector.settings values.
   *
   * @return array{0: array<string, mixed>, 1: string|null}
   *   The new values, and the saved API base URL when it was not the
   *   production default: that site must now set it in settings.php or the
   *   environment.
   */
  public static function apply(array $data): array {
    $saved = is_string($data['api_base_url'] ?? NULL) ? trim($data['api_base_url']) : '';
    $customUrl = NULL;
    if ($saved !== '' && ApiBaseUrlResolver::validate($saved)[0] !== ApiBaseUrlResolver::DEFAULT_URL) {
      $customUrl = $saved;
    }
    foreach (self::REMOVED_KEYS as $key) {
      unset($data[$key]);
    }
    $data['report_scope'] = PayloadBuilder::normaliseScope($data['report_scope'] ?? 'all');
    if (!isset($data['report_platform'])) {
      $data['report_platform'] = TRUE;
    }
    return [$data, $customUrl];
  }

}
