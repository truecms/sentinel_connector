<?php

namespace Drupal\sentinel_connector;

/**
 * What Sentinel answered to a CI upgrade scan report.
 *
 * Messages are written here, never copied from the response, and never hold
 * the scan key, the request or the payload.
 */
final class ScanReportResult {

  /**
   * The result is shown on the site.
   */
  public const STORED = 'stored';

  /**
   * Accepted, but a newer scan of the site is shown.
   */
  public const OLDER = 'older';

  /**
   * The problem is recorded; any result already shown stays.
   */
  public const PROBLEM_STORED = 'problem_stored';

  /**
   * The scan route is closed: CI scans are not switched on for this site.
   */
  public const CLOSED = 'closed';

  /**
   * Sentinel refused the scan key.
   */
  public const KEY_REFUSED = 'key_refused';

  /**
   * The site has not reported its Drupal major version to Sentinel yet.
   */
  public const SITE_NOT_REPORTED = 'site_not_reported';

  /**
   * The scan is for another Drupal major version than the site runs.
   */
  public const MAJOR_MISMATCH = 'major_mismatch';

  /**
   * Too many scans for this site: try again later.
   */
  public const RATE_LIMITED = 'rate_limited';

  /**
   * Sentinel could not be reached, or answered something unexpected.
   */
  public const FAILED = 'failed';

  /**
   * Constructs a result.
   *
   * @param string $outcome
   *   One of the constants above.
   * @param int|null $httpCode
   *   The HTTP status, NULL when Sentinel was not reached.
   * @param string $message
   *   What happened, in plain words.
   */
  public function __construct(
    public readonly string $outcome,
    public readonly ?int $httpCode,
    public readonly string $message,
  ) {}

  /**
   * Whether Sentinel accepted the report.
   */
  public function isAccepted(): bool {
    return in_array($this->outcome, [self::STORED, self::OLDER, self::PROBLEM_STORED], TRUE);
  }

}
