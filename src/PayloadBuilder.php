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
  public const CONNECTOR_VERSION = '0.4.0';

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
   * Core modules are never listed: core is reported by its version alone.
   * Sub-modules are folded into the module whose directory contains them.
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
    $extensions = array_filter(
      $this->moduleList->reset()->getList(),
      fn(Extension $extension): bool => !$this->isCore($extension),
    );
    $parents = $this->parents($extensions);
    // A project counts as enabled when it or any of its sub-modules is.
    $enabled = [];
    foreach ($extensions as $name => $extension) {
      if ((int) ($extension->status ?? 0) === 1) {
        $enabled[$parents[$name] ?? $name] = TRUE;
      }
    }
    $modules = [];
    foreach ($extensions as $name => $extension) {
      if (isset($parents[$name])) {
        continue;
      }
      $info = $extension->info;
      $project = $this->normaliseProject($info['project'] ?? NULL);
      $packaged = !empty($info['project']);
      if ($project === NULL && $this->composerProjects) {
        // No usable packaging metadata: ask Composer which drupal/* package
        // ships this extension. The machine name is never used as a guess.
        $package = $this->composerProjects->resolve($extension->getPath());
        $packaged = $packaged || $package !== NULL;
        $project = $this->normaliseProject($package);
      }
      $type = $packaged ? 'contrib' : 'custom';
      if ($scope === 'contrib' && $type !== 'contrib') {
        continue;
      }
      $version = $info['version'] ?? NULL;
      $versionKnown = is_string($version) && trim($version) !== '';
      $modules[] = [
        'machine_name' => $name,
        'display_name' => $info['name'] ?? $name,
        'module_type' => $type,
        'enabled' => isset($enabled[$name]),
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
   * Maps each sub-module to the outermost module that contains it.
   *
   * @param array<string, \Drupal\Core\Extension\Extension> $extensions
   *   Discovered modules keyed by machine name.
   *
   * @return array<string, string>
   *   Containing module names keyed by sub-module name.
   */
  protected function parents(array $extensions): array {
    $paths = [];
    foreach ($extensions as $name => $extension) {
      // A profile is a distribution, not the parent of the modules it ships.
      if ($extension->getType() !== 'profile') {
        $paths[$name] = rtrim($extension->getPath(), '/') . '/';
      }
    }
    $parents = [];
    foreach ($extensions as $name => $extension) {
      $own = rtrim($extension->getPath(), '/') . '/';
      $length = strlen($own);
      foreach ($paths as $candidate => $path) {
        if (strlen($path) < $length && str_starts_with($own, $path)) {
          $parents[$name] = $candidate;
          $length = strlen($path);
        }
      }
    }
    return $parents;
  }

}
