<?php

namespace Drupal\sentinel_connector;

use Composer\InstalledVersions;

/**
 * Resolves the Drupal.org project of an extension from Composer metadata.
 *
 * Used when an info.yml has no 'project' key, which is the case for modules
 * installed from a git clone, a VCS Composer repository or a dev checkout.
 */
class ComposerProjectResolver {

  /**
   * Package short names keyed by real install path with a trailing slash.
   *
   * @var array<string, string>
   */
  protected array $paths = [];

  /**
   * Constructs the resolver from Composer's installed package data.
   *
   * @param string $appRoot
   *   The Drupal app root that extension paths are relative to.
   * @param array<string, array<string, mixed>> $versions
   *   Installed packages keyed by name, as in Composer's 'versions' data. Only
   *   'type' and 'install_path' are read.
   * @param string|null $rootPackage
   *   Name of the root package. Its install path is the whole project, so it
   *   can never identify a single extension.
   */
  public function __construct(
    protected string $appRoot,
    array $versions,
    ?string $rootPackage = NULL,
  ) {
    foreach ($versions as $name => $package) {
      $name = strtolower((string) $name);
      $type = $package['type'] ?? NULL;
      $path = $package['install_path'] ?? NULL;
      if (!str_starts_with($name, 'drupal/') || $name === strtolower((string) $rootPackage)) {
        continue;
      }
      // Path-repository packages of site-specific code use 'drupal-custom-*'
      // types and are not Drupal.org projects.
      if (!is_string($type) || !str_starts_with($type, 'drupal-') || $type === 'drupal-core' || str_starts_with($type, 'drupal-custom-')) {
        continue;
      }
      $real = is_string($path) && $path !== '' ? realpath($path) : FALSE;
      if ($real === FALSE) {
        continue;
      }
      $this->paths[rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR] = substr($name, strlen('drupal/'));
    }
  }

  /**
   * Builds a resolver from the packages Composer installed for this site.
   */
  public static function fromInstalledVersions(string $appRoot): self {
    $versions = [];
    $root = NULL;
    if (class_exists(InstalledVersions::class)) {
      foreach (InstalledVersions::getAllRawData() as $data) {
        $versions += $data['versions'];
        $root ??= $data['root']['name'];
      }
    }
    return new self($appRoot, $versions, $root);
  }

  /**
   * Finds the drupal/* package that contains an extension.
   *
   * @param string $extensionPath
   *   Extension directory, relative to the app root.
   *
   * @return string|null
   *   The package short name (the part after 'drupal/'), or NULL when no
   *   drupal/* package contains the extension. Sub-modules resolve to the
   *   package of their parent project.
   */
  public function resolve(string $extensionPath): ?string {
    $real = realpath($this->appRoot . DIRECTORY_SEPARATOR . $extensionPath);
    if ($real === FALSE) {
      return NULL;
    }
    $real = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $match = NULL;
    $length = 0;
    // The deepest containing package wins, so a module shipped inside a
    // profile package is not attributed to a package nested elsewhere.
    foreach ($this->paths as $path => $name) {
      if (str_starts_with($real, $path) && strlen($path) > $length) {
        $match = $name;
        $length = strlen($path);
      }
    }
    return $match;
  }

}
