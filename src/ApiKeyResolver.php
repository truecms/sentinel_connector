<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Resolves the Sentinel API key from settings.php, env var, or state.
 */
class ApiKeyResolver {

  public const SETTINGS_KEY = 'sentinel_connector.api_key';
  public const ENV_VAR = 'SENTINEL_CONNECTOR_API_KEY';
  public const STATE_KEY = 'sentinel_connector.api_key';

  public function __construct(
    protected Settings $settings,
    protected StateInterface $state,
  ) {}

  /**
   * The resolved key, or NULL when none is configured.
   */
  public function getKey(): ?string {
    $fromSettings = $this->settings->get(self::SETTINGS_KEY);
    if (is_string($fromSettings) && $fromSettings !== '') {
      return $fromSettings;
    }
    $fromEnv = getenv(self::ENV_VAR);
    if (is_string($fromEnv) && $fromEnv !== '') {
      return $fromEnv;
    }
    $fromState = $this->state->get(self::STATE_KEY);
    if (is_string($fromState) && $fromState !== '') {
      return $fromState;
    }
    return NULL;
  }

  /**
   * Which source supplied the key: 'settings.php', 'environment', 'state', or NULL.
   */
  public function getSource(): ?string {
    $fromSettings = $this->settings->get(self::SETTINGS_KEY);
    if (is_string($fromSettings) && $fromSettings !== '') {
      return 'settings.php';
    }
    $fromEnv = getenv(self::ENV_VAR);
    if (is_string($fromEnv) && $fromEnv !== '') {
      return 'environment';
    }
    $fromState = $this->state->get(self::STATE_KEY);
    if (is_string($fromState) && $fromState !== '') {
      return 'state';
    }
    return NULL;
  }

}
