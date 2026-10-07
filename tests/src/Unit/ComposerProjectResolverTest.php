<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\sentinel_connector\ComposerProjectResolver;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\sentinel_connector\ComposerProjectResolver
 * @group sentinel_connector
 */
class ComposerProjectResolverTest extends TestCase {

  /**
   * Temporary project root holding a fake 'web' app root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/sentinel_connector_' . bin2hex(random_bytes(6));
    foreach ([
      'web/core/modules/node',
      'web/modules/contrib/admin_toolbar/admin_toolbar_links_access_filter',
      'web/modules/contrib/hyphen',
      'web/modules/custom/my_custom',
      'web/modules/custom/site_feature',
      'web/profiles/contrib/lightning/modules/lightning_core',
      'vendor/drupal/linked',
    ] as $dir) {
      mkdir($this->root . '/' . $dir, 0777, TRUE);
    }
    symlink($this->root . '/vendor/drupal/linked', $this->root . '/web/modules/contrib/linked');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->root);
    parent::tearDown();
  }

  /**
   * Recursively removes a directory without following symlinks.
   */
  private function remove(string $path): void {
    if (is_link($path) || is_file($path)) {
      unlink($path);
      return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
      $this->remove($path . '/' . $entry);
    }
    rmdir($path);
  }

  /**
   * Builds a resolver over the fake project.
   */
  private function resolver(): ComposerProjectResolver {
    $web = $this->root . '/web';
    return new ComposerProjectResolver($web, [
      'drupal/recommended-project' => ['type' => 'project', 'install_path' => $this->root],
      'drupal/site' => ['type' => 'drupal-module', 'install_path' => $this->root],
      'drupal/core' => ['type' => 'drupal-core', 'install_path' => $web . '/core'],
      'drupal/core-recommended' => ['type' => 'metapackage', 'install_path' => NULL],
      'Drupal/Admin_Toolbar' => [
        'type' => 'drupal-module',
        'install_path' => $web . '/modules/contrib/../contrib/admin_toolbar',
      ],
      'drupal/hyphen-name' => ['type' => 'drupal-module', 'install_path' => $web . '/modules/contrib/hyphen'],
      'drupal/linked' => ['type' => 'drupal-module', 'install_path' => $this->root . '/vendor/drupal/linked'],
      'drupal/lightning' => ['type' => 'drupal-profile', 'install_path' => $web . '/profiles/contrib/lightning'],
      'drupal/site_feature' => [
        'type' => 'drupal-custom-module',
        'install_path' => $web . '/modules/custom/site_feature',
      ],
      'acme/my_custom' => ['type' => 'drupal-module', 'install_path' => $web . '/modules/custom/my_custom'],
      'drupal/missing' => ['type' => 'drupal-module', 'install_path' => $web . '/modules/contrib/missing'],
    ], 'drupal/site');
  }

  /**
   * @covers ::__construct
   * @covers ::resolve
   */
  public function testResolvesContainingDrupalPackage(): void {
    $resolver = $this->resolver();
    $this->assertSame('admin_toolbar', $resolver->resolve('modules/contrib/admin_toolbar'));
    // Sub-modules resolve to the parent project.
    $this->assertSame('admin_toolbar', $resolver->resolve('modules/contrib/admin_toolbar/admin_toolbar_links_access_filter'));
    $this->assertSame('lightning', $resolver->resolve('profiles/contrib/lightning/modules/lightning_core'));
    // Symlinked install paths are compared by real path.
    $this->assertSame('linked', $resolver->resolve('modules/contrib/linked'));
    // The raw short name is returned; the caller validates it.
    $this->assertSame('hyphen-name', $resolver->resolve('modules/contrib/hyphen'));
  }

  /**
   * @covers ::__construct
   * @covers ::resolve
   */
  public function testIgnoresNonProjectPackages(): void {
    $resolver = $this->resolver();
    // Root and project-type packages span the whole site and never match.
    $this->assertNull($resolver->resolve('modules/custom/my_custom'));
    // Custom package types and non-drupal vendors are not Drupal.org projects.
    $this->assertNull($resolver->resolve('modules/custom/site_feature'));
    $this->assertNull($resolver->resolve('core/modules/node'));
    $this->assertNull($resolver->resolve('modules/contrib/does_not_exist'));
    // A sibling whose name shares a prefix is not inside the package.
    mkdir($this->root . '/web/modules/contrib/admin_toolbar_extra');
    $this->assertNull($resolver->resolve('modules/contrib/admin_toolbar_extra'));
  }

  /**
   * @covers ::fromInstalledVersions
   */
  public function testBuildsFromInstalledVersions(): void {
    $resolver = ComposerProjectResolver::fromInstalledVersions($this->root . '/web');
    $this->assertNull($resolver->resolve('modules/custom/my_custom'));
  }

}
