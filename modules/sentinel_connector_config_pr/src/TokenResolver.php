<?php

namespace Drupal\sentinel_connector_config_pr;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\key\KeyRepositoryInterface;

/**
 * Resolves the Git host access token from the Key module or settings.php.
 *
 * The token is never read from configuration or State: a Key module key that
 * keeps its value in configuration is refused, and no other source exists.
 */
class TokenResolver {

  /**
   * The settings.php key: $settings['sentinel_connector_config_pr']['token'].
   */
  public const SETTINGS_KEY = 'sentinel_connector_config_pr';

  /**
   * Key providers that keep the value in configuration.
   */
  protected const REFUSED_KEY_PROVIDERS = ['config'];

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the key id only.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   * @param \Drupal\key\KeyRepositoryInterface|null $keyRepository
   *   The Key module's repository, when that module is enabled.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Settings $settings,
    protected ?KeyRepositoryInterface $keyRepository = NULL,
  ) {}

  /**
   * The token, or NULL when none is available.
   */
  public function getToken(): ?string {
    return $this->resolve()[0];
  }

  /**
   * Where the token comes from.
   *
   * @return string|null
   *   'key' or 'settings.php', or NULL when no usable token exists.
   */
  public function getSource(): ?string {
    return $this->resolve()[1];
  }

  /**
   * Explains why the configured key cannot be used, if it cannot.
   *
   * @return string|null
   *   'missing' when the key does not exist, 'config_provider' when it keeps
   *   its value in configuration, 'empty' when it has no value, 'no_module'
   *   when the Key module is not enabled, or NULL when the key is usable or
   *   none is configured.
   */
  public function getKeyProblem(): ?string {
    $keyId = $this->keyId();
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
   * Resolves the token and its source.
   *
   * @return array{0: string|null, 1: string|null}
   *   The token and its source, both NULL when there is no usable token.
   */
  protected function resolve(): array {
    $keyId = $this->keyId();
    if ($keyId !== '') {
      // A configured key that cannot be used fails the run rather than
      // falling back to another source.
      if ($this->getKeyProblem() !== NULL || !$this->keyRepository) {
        return [NULL, NULL];
      }
      return [trim((string) $this->keyRepository->getKey($keyId)?->getKeyValue()), 'key'];
    }
    $settings = $this->settings->get(self::SETTINGS_KEY, []);
    $token = is_array($settings) ? ($settings['token'] ?? NULL) : NULL;
    if (is_string($token) && trim($token) !== '') {
      return [trim($token), 'settings.php'];
    }
    return [NULL, NULL];
  }

  /**
   * The configured key id.
   */
  protected function keyId(): string {
    return trim((string) $this->configFactory->get('sentinel_connector_config_pr.settings')->get('token_key_id'));
  }

}
