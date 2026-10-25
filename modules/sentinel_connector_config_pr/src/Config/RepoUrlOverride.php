<?php

namespace Drupal\sentinel_connector_config_pr\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Gives config_pr's self-hosted controllers this module's API base URL.
 *
 * The GitHub Enterprise and self-managed GitLab controllers read the host URL
 * from config_pr.settings. An override supplies it at runtime, so this module
 * never writes another module's configuration (which would itself be drift).
 */
class RepoUrlOverride implements ConfigFactoryOverrideInterface {

  /**
   * Constructs the override.
   *
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The active configuration storage. Read directly, because reading through
   *   the config factory from inside an override would recurse.
   */
  public function __construct(
    protected StorageInterface $storage,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @param string[] $names
   *   The configuration names being loaded.
   *
   * @return array<string, array<string, mixed>>
   *   The overrides keyed by configuration name.
   */
  public function loadOverrides($names): array {
    if (!in_array('config_pr.settings', $names, TRUE)) {
      return [];
    }
    $settings = $this->storage->read('sentinel_connector_config_pr.settings');
    $url = is_array($settings) ? trim((string) ($settings['api_base_url'] ?? '')) : '';
    if ($url === '') {
      return [];
    }
    return ['config_pr.settings' => ['repo' => ['repo_url' => $url]]];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'sentinel_connector_config_pr';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    $metadata = new CacheableMetadata();
    if ($name === 'config_pr.settings') {
      $metadata->addCacheTags(['config:sentinel_connector_config_pr.settings']);
    }
    return $metadata;
  }

}
