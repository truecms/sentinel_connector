<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Site\Settings;

/**
 * The off switch: stops every push from this environment.
 *
 * Set in settings.php, or in the environment variable. settings.php wins,
 * so $settings['sentinel_connector.disabled'] = FALSE turns sending back on
 * where the environment turns it off.
 */
class SendingSwitch {

  public const SETTINGS_KEY = 'sentinel_connector.disabled';
  public const ENV_VAR = 'SENTINEL_CONNECTOR_DISABLED';

  /**
   * Environment values that turn sending off.
   */
  public const ENV_ON_VALUES = ['1', 'true', 'yes', 'on'];

  /**
   * Constructs the switch.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   */
  public function __construct(protected Settings $settings) {}

  /**
   * Whether sending is turned off for this environment.
   */
  public function isDisabled(): bool {
    return $this->getSource() !== NULL;
  }

  /**
   * What turned sending off.
   *
   * @return string|null
   *   'settings.php' or 'environment', or NULL when sending is on.
   */
  public function getSource(): ?string {
    $fromSettings = $this->settings->get(self::SETTINGS_KEY);
    if ($fromSettings !== NULL) {
      return $fromSettings ? 'settings.php' : NULL;
    }
    $fromEnv = getenv(self::ENV_VAR);
    if (is_string($fromEnv) && in_array(strtolower(trim($fromEnv)), self::ENV_ON_VALUES, TRUE)) {
      return 'environment';
    }
    return NULL;
  }

  /**
   * The line shown wherever a push is not sent because of the switch.
   */
  public function message(): string {
    return $this->getSource() === 'settings.php'
      ? "Sending is turned off for this environment by \$settings['" . self::SETTINGS_KEY . "'] in settings.php. Cron, Drush and Sync now send nothing here."
      : 'Sending is turned off for this environment by the ' . self::ENV_VAR . ' environment variable. Cron, Drush and Sync now send nothing here.';
  }

}
