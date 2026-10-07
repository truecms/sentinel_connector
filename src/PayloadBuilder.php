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

  /**
   * This connector's release version. Bump it when tagging a release.
   */
  public const CONNECTOR_VERSION = '0.2.0';

  public function __construct(
    protected ModuleExtensionList $moduleList,
    protected string $coreVersion,
    protected string $phpVersion,
    protected string $ipAddress,
    protected ?ComposerProjectResolver $composerProjects = NULL,
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
    $scope = in_array($scope, ['all', 'contrib_custom', 'contrib'], TRUE) ? $scope : 'all';
    $modules = [];
    foreach ($this->moduleList->reset()->getList() as $name => $extension) {
      $info = $extension->info;
      $core = $this->isCore($extension);
      $project = $core ? 'drupal' : $this->normaliseProject($info['project'] ?? NULL);
      $packaged = !empty($info['project']);
      if (!$core && $project === NULL && $this->composerProjects) {
        // No usable packaging metadata: ask Composer which drupal/* package
        // ships this extension. The machine name is never used as a guess.
        $package = $this->composerProjects->resolve($extension->getPath());
        $packaged = $packaged || $package !== NULL;
        $project = $this->normaliseProject($package);
      }
      $type = $core ? 'core' : ($packaged ? 'contrib' : 'custom');
      if (!$this->inScope($type, $scope)) {
        continue;
      }
      $version = $info['version'] ?? ($core ? $this->coreVersion : NULL);
      $versionKnown = is_string($version) && trim($version) !== '';
      $modules[] = [
        'machine_name' => $name,
        'display_name' => $info['name'] ?? $name,
        'module_type' => $type,
        'enabled' => (int) ($extension->status ?? 0) === 1,
        'version' => $versionKnown ? $version : self::VERSION_PLACEHOLDER,
        'version_known' => $versionKnown,
        'reported_project' => $project,
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
      'inventory_scope' => $scope,
      'connector_version' => self::CONNECTOR_VERSION,
    ];
  }

  /**
   * Whether an extension ships with Drupal core.
   */
  protected function isCore(Extension $extension): bool {
    return str_starts_with($extension->getPath(), 'core/') || ($extension->origin ?? '') === 'core';
  }

  /**
   * Returns a valid lower-case Drupal.org project name, or NULL.
   */
  protected function normaliseProject(mixed $project): ?string {
    $project = is_string($project) ? strtolower(trim($project)) : NULL;
    if ($project === NULL || !preg_match('/^[a-z0-9_]{1,128}$/D', $project)) {
      return NULL;
    }
    return $project;
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
