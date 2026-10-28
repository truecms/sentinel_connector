<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Moves the deprecated State API key into a Key module key.
 *
 * The new key uses Key's State provider, so the value stays in the database
 * until an administrator moves it to a file, an environment variable or a
 * vault. The key's label and description say so.
 */
class ApiKeyMigrator {

  /**
   * The id of the key created by the migration.
   */
  public const KEY_ID = 'sentinel_connector_api_key';

  /**
   * Prefix of the State variable the migrated key reads.
   */
  public const KEY_STATE_PREFIX = 'sentinel_connector.key_value.';

  /**
   * Constructs the migrator.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\sentinel_connector\ApiKeyResolver $apiKeyResolver
   *   Resolves the active credential source.
   */
  public function __construct(
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ApiKeyResolver $apiKeyResolver,
  ) {}

  /**
   * Moves a State key into a new Key module key and selects it.
   *
   * Does nothing without the Key module, without a State key, or when a key
   * is already selected, or when settings.php or the environment supplies it.
   *
   * @return string|null
   *   The id of the new key, or NULL when nothing was migrated.
   */
  public function migrate(): ?string {
    if (!$this->moduleHandler->moduleExists('key')) {
      return NULL;
    }
    $value = $this->state->get(ApiKeyResolver::STATE_KEY);
    if (!is_string($value) || $value === '') {
      return NULL;
    }
    $config = $this->configFactory->getEditable('sentinel_connector.settings');
    if (trim((string) $config->get('api_key_id')) !== '') {
      return NULL;
    }

    // Selecting a migrated State key would outrank an environment key.
    // Preserve external secret management and leave unused State untouched.
    if (in_array($this->apiKeyResolver->getSource(), ['settings.php', 'environment'], TRUE)) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('key');
    $id = self::KEY_ID;
    for ($suffix = 2; $storage->load($id); $suffix++) {
      $id = self::KEY_ID . '_' . $suffix;
    }
    $stateKey = self::KEY_STATE_PREFIX . $id;
    $this->state->set($stateKey, $value);
    $storage->create([
      'id' => $id,
      'label' => 'Sentinel API key (State provider, not recommended)',
      'description' => 'Moved from the State value saved by an earlier Sentinel Connector settings form. It is still stored in the database: change the provider to a file, an environment variable or a vault, then delete the State variable ' . $stateKey . '.',
      'key_type' => 'authentication',
      'key_provider' => 'state',
      'key_provider_settings' => ['state_key' => $stateKey],
      'key_input' => 'none',
    ])->save();
    $config->set('api_key_id', $id)->save();
    $this->state->delete(ApiKeyResolver::STATE_KEY);

    return $id;
  }

}
