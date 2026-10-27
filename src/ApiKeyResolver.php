<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Resolves the Sentinel API key.
 *
 * Sources in order: settings.php, a Key module key, the environment variable,
 * and the deprecated key saved in State by earlier versions of the form.
 */
class ApiKeyResolver {

  public const SETTINGS_KEY = 'sentinel_connector.api_key';
  public const ENV_VAR = 'SENTINEL_CONNECTOR_API_KEY';

  /**
   * The deprecated State key; the Key module replaces it.
   */
  public const STATE_KEY = 'sentinel_connector.api_key';

  /**
   * Key providers that keep the value in exported configuration.
   */
  public const REFUSED_KEY_PROVIDERS = ['config'];

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store, for the deprecated saved key.
   * @param \Drupal\Core\Config\ConfigFactoryInterface|null $configFactory
   *   The config factory, for the selected key id only.
   * @param \Drupal\key\KeyRepositoryInterface|null $keyRepository
   *   The Key module's repository, when that module is enabled.
   */
  public function __construct(
    protected Settings $settings,
    protected StateInterface $state,
    protected ?ConfigFactoryInterface $configFactory = NULL,
    protected ?KeyRepositoryInterface $keyRepository = NULL,
  ) {}

  /**
   * The resolved key, or NULL when none is configured.
   */
  public function getKey(): ?string {
    return $this->resolve()[0];
  }

  /**
   * Which source supplied the key.
   *
   * @return string|null
   *   One of 'settings.php', 'key', 'environment', 'state', or NULL when unset.
   */
  public function getSource(): ?string {
    return $this->resolve()[1];
  }

  /**
   * Whether the Key module is enabled.
   */
  public function keyModuleEnabled(): bool {
    return $this->keyRepository !== NULL;
  }

  /**
   * The id of the selected Key module key, or '' when none is selected.
   */
  public function getKeyId(): string {
    return trim((string) $this->configFactory?->get('sentinel_connector.settings')->get('api_key_id'));
  }

  /**
   * The label of the selected key, or NULL when it does not exist.
   */
  public function getKeyLabel(): ?string {
    $keyId = $this->getKeyId();
    $key = $keyId !== '' ? $this->keyRepository?->getKey($keyId) : NULL;
    return $key ? (string) $key->label() : NULL;
  }

  /**
   * Explains why the selected key cannot be used, if it cannot.
   *
   * @return string|null
   *   'missing' when the key does not exist, 'config_provider' when it keeps
   *   its value in configuration, 'empty' when it has no value, 'no_module'
   *   when the Key module is not enabled, or NULL when the key is usable or
   *   none is selected.
   */
  public function getKeyProblem(): ?string {
    $keyId = $this->getKeyId();
    if ($keyId === '') {
      return NULL;
    }
    if (!$this->keyRepository) {
      return 'no_module';
    }
    $key = $this->keyRepository->getKey($keyId);
    if (!$key) {
      return 'missing';
    }
    if (in_array($key->get('key_provider'), self::REFUSED_KEY_PROVIDERS, TRUE)) {
      return 'config_provider';
    }
    return trim((string) $key->getKeyValue()) !== '' ? NULL : 'empty';
  }

  /**
   * Resolves the key and its source.
   *
   * @return array{0: string|null, 1: string|null}
   *   The key and its source, both NULL when no source supplies a key.
   */
  protected function resolve(): array {
    $fromSettings = $this->settings->get(self::SETTINGS_KEY);
    if (is_string($fromSettings) && $fromSettings !== '') {
      return [$fromSettings, 'settings.php'];
    }
    // A selected key that cannot be used falls through to the next source;
    // the settings form says why.
    if ($this->keyRepository && $this->getKeyId() !== '' && $this->getKeyProblem() === NULL) {
      return [trim((string) $this->keyRepository->getKey($this->getKeyId())?->getKeyValue()), 'key'];
    }
    $fromEnv = getenv(self::ENV_VAR);
    if (is_string($fromEnv) && $fromEnv !== '') {
      return [$fromEnv, 'environment'];
    }
    $fromState = $this->state->get(self::STATE_KEY);
    if (is_string($fromState) && $fromState !== '') {
      return [$fromState, 'state'];
    }
    return [NULL, NULL];
  }

}
