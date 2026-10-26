<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\sentinel_connector\ComposerProjectResolver;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\sentinel_connector\ComposerProjectResolver
 * @covers \Drupal\sentinel_connector\ComposerProjectResolver::__construct
 * @covers \Drupal\sentinel_connector\ComposerProjectResolver::resolve
 * @covers \Drupal\sentinel_connector\ComposerProjectResolver::version
 * @covers \Drupal\sentinel_connector\ComposerProjectResolver::fromInstalledVersions
 * @group sentinel_connector
 */
#[\PHPUnit\Framework\Attributes\CoversMethod(ComposerProjectResolver::class, '__construct')]
#[\PHPUnit\Framework\Attributes\CoversMethod(ComposerProjectResolver::class, 'resolve')]
#[\PHPUnit\Framework\Attributes\CoversMethod(ComposerProjectResolver::class, 'version')]
#[\PHPUnit\Framework\Attributes\CoversMethod(ComposerProjectResolver::class, 'fromInstalledVersions')]
#[Group('sentinel_connector')]
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
        'pretty_version' => '3.5.0',
      ],
      'drupal/linked' => [
        'type' => 'drupal-module',
        'install_path' => $web . '/modules/contrib/linked',
        'pretty_version' => '2.3.0',
      ],
      'drupal/hyphen-name' => [
        'type' => 'drupal-module',
        'install_path' => $web . '/modules/contrib/hyphen',
        'pretty_version' => ['not', 'a', 'string'],
      ],
      'drupal/lightning' => [
        'type' => 'drupal-profile',
        'install_path' => $web . '/profiles/contrib/lightning',
        'pretty_version' => ' 5.0.0-rc1 ',
      ],
      'drupal/site_feature' => [
        'type' => 'drupal-custom-module',
        'install_path' => $web . '/modules/custom/site_feature',
      ],
      'acme/my_custom' => ['type' => 'drupal-module', 'install_path' => $web . '/modules/custom/my_custom'],
      'drupal/missing' => ['type' => 'drupal-module', 'install_path' => $web . '/modules/contrib/missing'],
    ], 'drupal/site');
  }

  /**
   * Tests resolves containing drupal package.
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
   * Tests ignores non project packages.
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
   * Tests the installed version of the containing package.
   */
  public function testVersionOfContainingPackage(): void {
    $resolver = $this->resolver();
    $this->assertSame('3.5.0', $resolver->version('modules/contrib/admin_toolbar'));
    // Only the package root gets a version: a nested module may be another
    // project, such as a module shipped inside a profile.
    $this->assertNull($resolver->version('modules/contrib/admin_toolbar/admin_toolbar_links_access_filter'));
    $this->assertNull($resolver->version('profiles/contrib/lightning/modules/lightning_core'));
    // A symlinked path-repository install has no trusted version.
    $this->assertSame('linked', $resolver->resolve('modules/contrib/linked'));
    $this->assertNull($resolver->version('modules/contrib/linked'));
    // A non-string version, and paths outside any package.
    $this->assertNull($resolver->version('modules/contrib/hyphen'));
    $this->assertNull($resolver->version('modules/custom/my_custom'));
    $this->assertNull($resolver->version('modules/contrib/does_not_exist'));
  }

  /**
   * Tests builds from installed versions.
   */
  public function testBuildsFromInstalledVersions(): void {
    $resolver = ComposerProjectResolver::fromInstalledVersions($this->root . '/web');
    $this->assertNull($resolver->resolve('modules/custom/my_custom'));
  }

}
