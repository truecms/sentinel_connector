<?php

namespace Drupal\sentinel_connector;

/**
 * Immutable outcome of a connection check.
 */
final class ConnectionCheckResult {

  /**
   * Sentinel accepted the API key and the Site UUID.
   */
  public const CONNECTED = 'connected';

  /**
   * No Sentinel API answered at the base URL.
   */
  public const NOT_SENTINEL = 'not_sentinel';

  /**
   * This Sentinel does not know the Site UUID.
   */
  public const UNKNOWN_SITE = 'unknown_site';

  /**
   * Sentinel refused the API key, or the key belongs to another site.
   */
  public const KEY_REFUSED = 'key_refused';

  /**
   * The subscription is not active, or the push limit is reached.
   */
  public const LIMITED = 'limited';

  /**
   * Any other refusal: rate limit, signature, server error.
   */
  public const FAILED = 'failed';

  /**
   * The API base URL in use is not valid; Sentinel was not contacted.
   */
  public const INVALID_URL = 'invalid_url';

  /**
   * The Site UUID or the API key is missing; Sentinel was not contacted.
   */
  public const UNCONFIGURED = 'unconfigured';

  /**
   * Sending is turned off for this environment; Sentinel was not contacted.
   */
  public const DISABLED = 'disabled';

  /**
   * The Sentinel environment names this module knows.
   */
  public const ENVIRONMENTS = ['production', 'uat', 'local'];

  /**
   * Constructs a result.
   *
   * @param string $outcome
   *   One of the outcome constants.
   * @param string|null $environment
   *   The Sentinel environment that answered, when it said which.
   * @param \Drupal\sentinel_connector\SyncResult|null $refusal
   *   The refusal as the sync contract reads it, when Sentinel refused.
   */
  public function __construct(
    public readonly string $outcome,
    public readonly ?string $environment = NULL,
    public readonly ?SyncResult $refusal = NULL,
  ) {}

  /**
   * Whether Sentinel accepted the key and the UUID.
   */
  public function isOk(): bool {
    return $this->outcome === self::CONNECTED;
  }

  /**
   * A known environment name from a response, or NULL.
   */
  public static function environment(mixed $value): ?string {
    return is_string($value) && in_array($value, self::ENVIRONMENTS, TRUE) ? $value : NULL;
  }

}
