<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Resolves the Sentinel API base URL.
 *
 * Sources in order: settings.php, the environment variable, then the
 * production default. An override that is not a valid base URL is refused,
 * never replaced by the default: a typo must not send data elsewhere.
 *
 * A site that saved a non-production URL on the form before 1.4.0 is not
 * moved to production either: until settings.php or the environment sets a
 * URL, nothing is sent.
 */
class ApiBaseUrlResolver {

  public const SETTINGS_KEY = 'sentinel_connector.api_base_url';
  public const ENV_VAR = 'SENTINEL_CONNECTOR_API_BASE_URL';

  /**
   * The production Sentinel ingest host.
   */
  public const DEFAULT_URL = 'https://ingest.sentinelconnector.io';

  /**
   * Source of a URL set in settings.php.
   */
  public const SOURCE_SETTINGS = 'settings.php';

  /**
   * Source of a URL set in the environment variable.
   */
  public const SOURCE_ENVIRONMENT = 'environment';

  /**
   * Source of the built-in default.
   */
  public const SOURCE_DEFAULT = 'default';

  /**
   * State key: the host of a non-production URL saved before 1.4.0.
   *
   * Set by update 10005. Only the host is kept, never a path or credentials.
   */
  public const STATE_LEGACY_HOST = 'sentinel_connector.legacy_api_base_url_host';

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   * @param \Drupal\Core\Config\ConfigFactoryInterface|null $configFactory
   *   The config factory, for a URL saved before update 10005 ran.
   * @param \Drupal\Core\State\StateInterface|null $state
   *   The state store, for the host update 10005 removed.
   */
  public function __construct(
    protected Settings $settings,
    protected ?ConfigFactoryInterface $configFactory = NULL,
    protected ?StateInterface $state = NULL,
  ) {}

  /**
   * The base URL in use, without a trailing slash, or NULL when invalid.
   */
  public function getUrl(): ?string {
    return $this->getLegacyHost() !== NULL ? NULL : self::validate($this->getRawValue())[0];
  }

  /**
   * Why the configured URL cannot be used, or NULL when it can.
   */
  public function getError(): ?string {
    $legacy = $this->getLegacyHost();
    if ($legacy !== NULL) {
      return 'A Sentinel API base URL for ' . $legacy . ' was saved on the settings form before version 1.4.0, which no longer has that field. Set it in settings.php or the ' . self::ENV_VAR . ' environment variable, or set ' . self::DEFAULT_URL . ' there to use production.';
    }
    return self::validate($this->getRawValue())[1];
  }

  /**
   * The host of a non-production URL saved on the form, while not replaced.
   *
   * @return string|null
   *   The host, or NULL when settings.php or the environment sets a URL, or
   *   nothing other than the default was saved.
   */
  public function getLegacyHost(): ?string {
    if ($this->getSource() !== self::SOURCE_DEFAULT) {
      return NULL;
    }
    // Before update 10005: the saved value itself.
    $saved = $this->configFactory?->get('sentinel_connector.settings')->get('api_base_url');
    if (is_string($saved) && trim($saved) !== '' && self::validate($saved)[0] !== self::DEFAULT_URL) {
      $host = parse_url(trim($saved), PHP_URL_HOST);
      return is_string($host) && $host !== '' ? strtolower($host) : 'an unknown host';
    }
    // After it: the host it recorded.
    $host = $this->state?->get(self::STATE_LEGACY_HOST);
    return is_string($host) && $host !== '' ? $host : NULL;
  }

  /**
   * Which source supplied the URL.
   *
   * @return string
   *   One of the SOURCE_* constants.
   */
  public function getSource(): string {
    return $this->resolve()[1];
  }

  /**
   * The value as configured, before validation.
   */
  public function getRawValue(): string {
    return $this->resolve()[0];
  }

  /**
   * The configured value, safe to show to an administrator.
   *
   * A query or fragment, which may hold a token, is cut off.
   *
   * @return string|null
   *   The value, or NULL when it carries a user name or password.
   */
  public function getDisplayValue(): ?string {
    $raw = trim($this->getRawValue());
    $authority = preg_replace('#\A[a-zA-Z][a-zA-Z0-9+.\-]*://#', '', $raw);
    $authority = strtok((string) $authority, '/?#');
    if (is_string($authority) && str_contains($authority, '@')) {
      return NULL;
    }
    $cut = strcspn($raw, '?#');
    return $cut < strlen($raw) ? substr($raw, 0, $cut) . $raw[$cut] . '…' : $raw;
  }

  /**
   * Checks a base URL and brings it into one form.
   *
   * Only https is accepted, and plain http only for localhost and
   * *.localhost. A host is required; a path other than "/", a query, a
   * fragment, a user name and a password are refused. A trailing "/" is
   * dropped and the scheme and host are lowered.
   *
   * @param string $value
   *   The URL as configured.
   *
   * @return array{0: string|null, 1: string|null}
   *   The normalised URL and NULL, or NULL and the reason it is refused.
   */
  public static function validate(string $value): array {
    $value = trim($value);
    if ($value === '') {
      return [NULL, 'The address is empty.'];
    }
    $parts = parse_url($value);
    if ($parts === FALSE || !isset($parts['scheme'])) {
      return [NULL, 'The address is not a URL. Use the base URL, for example ' . self::DEFAULT_URL . '.'];
    }
    // A pasted sync or check endpoint gets a message of its own.
    if (preg_match('#/api(/|\z)#i', $parts['path'] ?? '')) {
      return [NULL, 'Use the base URL, not the sync endpoint, for example ' . self::DEFAULT_URL . '.'];
    }
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host'] ?? '');
    if ($host === '' || !preg_match('/\A(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*)\z/', $host)) {
      return [NULL, 'The address has no valid host name.'];
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      return [NULL, 'The address must not contain a user name or password.'];
    }
    $local = $host === 'localhost' || str_ends_with($host, '.localhost');
    if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
      return [NULL, 'The address must start with https://. Plain http:// is allowed only for localhost.'];
    }
    if (isset($parts['query']) || isset($parts['fragment']) || str_contains($value, '?') || str_contains($value, '#')) {
      return [NULL, 'The address must not have a query string or a fragment.'];
    }
    if (($parts['path'] ?? '') !== '' && $parts['path'] !== '/') {
      return [NULL, 'The address must not have a path. Use the base URL, for example ' . self::DEFAULT_URL . '.'];
    }
    return [$scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : ''), NULL];
  }

  /**
   * Resolves the raw value and its source.
   *
   * @return array{0: string, 1: string}
   *   The value as configured and its source.
   */
  protected function resolve(): array {
    $fromSettings = $this->settings->get(self::SETTINGS_KEY);
    if ($fromSettings !== NULL && $fromSettings !== '') {
      // A value that is not text is refused by validation, not skipped.
      return [is_string($fromSettings) ? $fromSettings : '', self::SOURCE_SETTINGS];
    }
    $fromEnv = getenv(self::ENV_VAR);
    if (is_string($fromEnv) && $fromEnv !== '') {
      return [$fromEnv, self::SOURCE_ENVIRONMENT];
    }
    return [self::DEFAULT_URL, self::SOURCE_DEFAULT];
  }

}
