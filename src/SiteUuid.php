<?php

namespace Drupal\sentinel_connector;

/**
 * Brings a Site UUID into the one form Sentinel accepts.
 *
 * Sentinel compares the UUID in the request path as text, and what stands in
 * front of it only lets the lower-case hyphenated form through. A UUID in any
 * other notation is refused there as if the API key were wrong.
 */
final class SiteUuid {

  /**
   * A UUID an administrator can compare with what they entered.
   */
  public const EXAMPLE = '123e4567-e89b-42d3-a456-426614174000';

  /**
   * Returns the canonical form of a UUID: lower case, with hyphens.
   *
   * Spaces around the value and one pair of braces are dropped, upper case
   * is lowered, and 32 hexadecimal digits without hyphens get them.
   *
   * @param string $value
   *   The UUID as entered or stored.
   *
   * @return string|null
   *   The canonical UUID, or NULL when the value is not a UUID.
   */
  public static function normalize(string $value): ?string {
    $value = trim($value);
    if (preg_match('/\A\{(.*)\}\z/s', $value, $braced)) {
      $value = trim($braced[1]);
    }
    $value = strtolower($value);
    if (preg_match('/\A[0-9a-f]{32}\z/', $value)) {
      $value = implode('-', [
        substr($value, 0, 8),
        substr($value, 8, 4),
        substr($value, 12, 4),
        substr($value, 16, 4),
        substr($value, 20),
      ]);
    }
    return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) ? $value : NULL;
  }

}
