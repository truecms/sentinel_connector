<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Builds the Sentinel sync payload from the module extension list.
 *
 * Pure: every input is injected, so this class needs no container.
 */
class PayloadBuilder {

  public const VERSION_PLACEHOLDER = '0.0.0';

  public function __construct(
    protected ModuleExtensionList $moduleList,
    protected string $coreVersion,
    protected string $phpVersion,
    protected string $ipAddress,
  ) {}

  /**
   * Build the modules array for the given scope.
   *
   * @param string $scope
   *   One of 'all', 'contrib_custom', 'contrib'.
   *
   * @return array<string, mixed>
   *   The full DrupalSiteSync-shaped payload, minus the 'site' block which the
   *   caller fills from config.
   */
  public function build(string $scope): array {
    $modules = [];
    foreach ($this->moduleList->reset()->getList() as $name => $extension) {
      $type = $this->classify($extension);
      if (!$this->inScope($type, $scope)) {
        continue;
      }
      $info = $extension->info;
      $modules[] = [
        'machine_name' => $name,
        'display_name' => $info['name'] ?? $name,
        'module_type' => $type,
        'enabled' => (int) ($extension->status ?? 0) === 1,
        'version' => $info['version'] ?? self::VERSION_PLACEHOLDER,
        'description' => $info['description'] ?? NULL,
      ];
    }

    return [
      'drupal_info' => [
        'core_version' => $this->coreVersion,
        'php_version' => $this->phpVersion,
        'ip_address' => $this->ipAddress,
      ],
      'modules' => $modules,
      'full_sync' => TRUE,
    ];
  }

  /**
   * Classify an extension as core, contrib, or custom.
   */
  protected function classify(Extension $extension): string {
    $path = $extension->getPath();
    if (str_starts_with($path, 'core/') || ($extension->origin ?? '') === 'core') {
      return 'core';
    }
    // Drupal's packaging script adds a 'project' key to contrib info.yml.
    if (!empty($extension->info['project'])) {
      return 'contrib';
    }
    return 'custom';
  }

  /**
   * Whether a module type is included in the configured scope.
   */
  protected function inScope(string $type, string $scope): bool {
    return match ($scope) {
      'contrib' => $type === 'contrib',
      'contrib_custom' => $type !== 'core',
      default => TRUE,
    };
  }

}
