<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;

/**
 * Resolves the Site UUID.
 *
 * Sources in order: settings.php, the environment variable, then the value
 * saved on the settings form. One codebase can so push each environment as
 * its own site in Sentinel.
 */
class SiteUuidResolver {

  public const SETTINGS_KEY = 'sentinel_connector.site_uuid';
  public const ENV_VAR = 'SENTINEL_CONNECTOR_SITE_UUID';

  /**
   * Source of a UUID set in settings.php.
   */
  public const SOURCE_SETTINGS = 'settings.php';

  /**
   * Source of a UUID set in the environment variable.
   */
  public const SOURCE_ENVIRONMENT = 'environment';

  /**
   * Source of a UUID saved on the settings form.
   */
  public const SOURCE_CONFIG = 'config';

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected Settings $settings,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The canonical Site UUID, or NULL when none is set or it is no UUID.
   */
  public function getUuid(): ?string {
    return SiteUuid::normalize($this->getRawValue());
  }

  /**
   * The value as configured, before normalisation.
   */
  public function getRawValue(): string {
    return $this->resolve()[0];
  }

  /**
   * Which source supplied the UUID.
   *
   * @return string
   *   One of the SOURCE_* constants.
   */
  public function getSource(): string {
    return $this->resolve()[1];
  }

  /**
   * Whether settings.php or the environment sets the UUID.
   */
  public function isOverridden(): bool {
    return $this->getSource() !== self::SOURCE_CONFIG;
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
      return [is_string($fromSettings) ? $fromSettings : '', self::SOURCE_SETTINGS];
    }
    $fromEnv = getenv(self::ENV_VAR);
    if (is_string($fromEnv) && $fromEnv !== '') {
      return [$fromEnv, self::SOURCE_ENVIRONMENT];
    }
    return [(string) $this->configFactory->get('sentinel_connector.settings')->get('site_uuid'), self::SOURCE_CONFIG];
  }

}
