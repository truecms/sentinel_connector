<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\sentinel_connector\ComposerProjectResolver;
use Drupal\sentinel_connector\PayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests the inventory payload.
 *
 * Fixtures use mocks as stubs and assert the returned payload or result.
 *
 * @coversDefaultClass \Drupal\sentinel_connector\PayloadBuilder
 * @covers \Drupal\sentinel_connector\PayloadBuilder::build
 * @covers \Drupal\sentinel_connector\PayloadBuilder::parents
 * @group sentinel_connector
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
#[\PHPUnit\Framework\Attributes\CoversMethod(PayloadBuilder::class, 'build')]
#[\PHPUnit\Framework\Attributes\CoversMethod(PayloadBuilder::class, 'parents')]
#[Group('sentinel_connector')]
class PayloadBuilderTest extends TestCase {

  /**
   * Builds a fake Extension with a given info array, status and path.
   *
   * @param string $name
   *   Module machine name.
   * @param array<string, mixed> $info
   *   Module metadata.
   * @param int $status
   *   Installed status.
   * @param string $path
   *   Module directory.
   */
  private function ext(string $name, array $info, int $status, string $path): Extension {
    $ext = $this->getMockBuilder(TestExtension::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getName', 'getPath'])
      ->getMock();
    $ext->method('getName')->willReturn($name);
    $ext->method('getPath')->willReturn($path);
    $ext->info = $info;
    $ext->status = $status;
    return $ext;
  }

  /**
   * Builds a payload builder with an isolated extension catalog.
   *
   * @param array<string, Extension> $extensions
   *   Discovered modules.
   * @param array<string, string> $packages
   *   Composer package short names keyed by extension path prefix.
   * @param array<string, string> $versions
   *   Composer pretty versions keyed by extension path prefix.
   */
  private function builder(array $extensions, array $packages = [], array $versions = []): PayloadBuilder {
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('reset')->willReturnSelf();
    $list->method('getList')->willReturn($extensions);
    $resolver = $this->createMock(ComposerProjectResolver::class);
    $resolver->method('resolve')->willReturnCallback(function (string $path) use ($packages): ?string {
      foreach ($packages as $prefix => $package) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
          return $package;
        }
      }
      return NULL;
    });
    $resolver->method('version')->willReturnCallback(function (string $path) use ($versions): ?string {
      foreach ($versions as $prefix => $version) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
          return $version;
        }
      }
      return NULL;
    });
    return new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4', $resolver);
  }

  /**
   * Tests classifies and maps modules.
   */
  public function testClassifiesAndMapsModules(): void {
    $extensions = [
      'node' => $this->ext('node', ['name' => 'Node', 'description' => 'Core node.'], 1, 'core/modules/node'),
      'token' => $this->ext('token', [
        'name' => 'Token',
        'project' => 'token',
        'version' => '8.x-1.15',
        'description' => 'Tokens.',
      ], 1, 'modules/contrib/token'),
      'my_custom' => $this->ext('my_custom', ['name' => 'My Custom'], 0, 'modules/custom/my_custom'),
    ];
    $payload = $this->builder($extensions)->build('all');

    $this->assertSame('10.3.0', $payload['drupal_info']['core_version']);
    $this->assertSame('8.3.0', $payload['drupal_info']['php_version']);
    $this->assertSame('203.0.113.4', $payload['drupal_info']['ip_address']);
    $this->assertTrue($payload['full_sync']);
    $this->assertSame('all', $payload['inventory_scope']);
    $this->assertSame(PayloadBuilder::CONNECTOR_VERSION, $payload['connector_version']);
    $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $payload['connector_version']);

    $byName = [];
    foreach ($payload['modules'] as $m) {
      $byName[$m['machine_name']] = $m;
    }

    // Core is reported through drupal_info only.
    $this->assertArrayNotHasKey('node', $byName);
    $this->assertSame('contrib', $byName['token']['module_type']);
    $this->assertSame('custom', $byName['my_custom']['module_type']);

    $this->assertSame('8.x-1.15', $byName['token']['version']);
    $this->assertTrue($byName['token']['version_known']);
    $this->assertSame('token', $byName['token']['reported_project']);
    $this->assertTrue($byName['token']['enabled']);
    $this->assertSame('Token', $byName['token']['display_name']);

    // Version-less custom module gets the placeholder and stays in the payload.
    $this->assertSame('0.0.0', $byName['my_custom']['version']);
    $this->assertFalse($byName['my_custom']['version_known']);
    $this->assertNull($byName['my_custom']['reported_project']);
    $this->assertFalse($byName['my_custom']['enabled']);
    // Missing description becomes null.
    $this->assertNull($byName['my_custom']['description']);
  }

  /**
   * Tests scope filtering.
   */
  public function testScopeFiltering(): void {
    $extensions = [
      'node' => $this->ext('node', ['name' => 'Node'], 1, 'core/modules/node'),
      'token' => $this->ext('token', ['name' => 'Token', 'project' => 'token', 'version' => '8.x-1.15'], 1, 'modules/contrib/token'),
      'my_custom' => $this->ext('my_custom', ['name' => 'My Custom'], 1, 'modules/custom/my_custom'),
    ];

    $names = fn(array $p) => array_column($p['modules'], 'machine_name');

    $this->assertEqualsCanonicalizing(['token', 'my_custom'], $names($this->builder($extensions)->build('all')));
    $this->assertSame('all', $this->builder($extensions)->build('all')['inventory_scope']);
    $this->assertEqualsCanonicalizing(['token'], $names($this->builder($extensions)->build('core_contrib')));
    $this->assertSame('core_contrib', $this->builder($extensions)->build('core_contrib')['inventory_scope']);
    // Unknown values, the legacy ones too, count as 'all'.
    foreach (['contrib_custom', 'contrib', 'nonsense', ''] as $scope) {
      $payload = $this->builder($extensions)->build($scope);
      $this->assertSame('all', $payload['inventory_scope'], $scope);
      $this->assertEqualsCanonicalizing(['token', 'my_custom'], $names($payload), $scope);
    }
  }

  /**
   * Saved scopes from before payload v4 map to their replacements.
   */
  public function testLegacyScopesAreMapped(): void {
    $this->assertSame('all', PayloadBuilder::normaliseScope('contrib_custom'));
    $this->assertSame('core_contrib', PayloadBuilder::normaliseScope('contrib'));
    $this->assertSame('all', PayloadBuilder::normaliseScope('all'));
    $this->assertSame('core_contrib', PayloadBuilder::normaliseScope('core_contrib'));
    $this->assertSame('all', PayloadBuilder::normaliseScope(NULL));
    $this->assertSame('all', PayloadBuilder::normaliseScope('CONTRIB'));
    $this->assertSame('all', PayloadBuilder::normaliseScope(['contrib']));
  }

  /**
   * Sub-modules are folded into the module whose directory contains them.
   */
  public function testSubmodulesAreExcluded(): void {
    $profile = $this->getMockBuilder(TestExtension::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getName', 'getPath', 'getType'])
      ->getMock();
    $profile->method('getName')->willReturn('distro');
    $profile->method('getPath')->willReturn('profiles/contrib/distro');
    $profile->method('getType')->willReturn('profile');
    $profile->info = ['project' => 'distro', 'version' => '1.0.0'];
    $extensions = [
      'webform' => $this->ext('webform', ['project' => 'webform', 'version' => '6.2.8'], 1, 'modules/contrib/webform'),
      'webform_ui' => $this->ext('webform_ui', ['project' => 'webform', 'version' => '6.2.8'], 1, 'modules/contrib/webform/modules/webform_ui'),
      // A sibling whose name shares a prefix is not a sub-module.
      'webform_views' => $this->ext('webform_views', ['project' => 'webform_views', 'version' => '5.0.0'], 0, 'modules/contrib/webform_views'),
      // Disabled parent with an enabled sub-module two levels down.
      'commerce' => $this->ext('commerce', ['project' => 'commerce', 'version' => '2.40.0'], 0, 'modules/contrib/commerce'),
      'commerce_order' => $this->ext('commerce_order', ['project' => 'commerce'], 0, 'modules/contrib/commerce/modules/order'),
      'commerce_order_test' => $this->ext('commerce_order_test', ['project' => 'commerce'], 1, 'modules/contrib/commerce/modules/order/tests/commerce_order_test'),
      // Disabled parent whose sub-modules are all disabled.
      'my_custom' => $this->ext('my_custom', [], 0, 'modules/custom/my_custom'),
      'my_custom_sub' => $this->ext('my_custom_sub', [], 0, 'modules/custom/my_custom/modules/my_custom_sub'),
      // A sub-module whose parent module is absent stays as its own row.
      'orphan_ui' => $this->ext('orphan_ui', ['project' => 'orphan', 'version' => '1.0.0'], 1, 'modules/contrib/orphan/modules/orphan_ui'),
      // Modules shipped by a profile are not its sub-modules.
      'distro' => $profile,
      'distro_feature' => $this->ext('distro_feature', ['project' => 'distro', 'version' => '1.0.0'], 1, 'profiles/contrib/distro/modules/distro_feature'),
    ];
    $byName = array_column($this->builder($extensions)->build('all')['modules'], NULL, 'machine_name');

    $this->assertEqualsCanonicalizing(
      ['webform', 'webform_views', 'commerce', 'my_custom', 'orphan_ui', 'distro', 'distro_feature'],
      array_keys($byName),
    );
    $this->assertTrue($byName['webform']['enabled']);
    $this->assertFalse($byName['webform_views']['enabled']);
    $this->assertTrue($byName['commerce']['enabled']);
    $this->assertSame('2.40.0', $byName['commerce']['version']);
    $this->assertFalse($byName['my_custom']['enabled']);
    $this->assertSame('orphan', $byName['orphan_ui']['reported_project']);
  }

  /**
   * Project identity follows packaging metadata, never extension names.
   */
  public function testUnknownMetadata(): void {
    $extensions = [
      'token' => $this->ext('token', ['version' => ''], 1, 'modules/custom/token'),
      'invalid_project' => $this->ext('invalid_project', [
        'project' => 'https://internal.example.com/project',
        'version' => '1.0.0',
      ], 1, 'modules/contrib/invalid_project'),
    ];
    $payload = $this->builder($extensions)->build('all');
    $byName = array_column($payload['modules'], NULL, 'machine_name');
    $this->assertSame('custom', $byName['token']['module_type']);
    $this->assertNull($byName['token']['reported_project']);
    $this->assertFalse($byName['token']['version_known']);
    $this->assertSame('0.0.0', $byName['token']['version']);
    $this->assertNull($byName['invalid_project']['reported_project']);
  }

  /**
   * Composer metadata identifies contrib modules that lack a 'project' key.
   */
  public function testComposerMetadataResolvesMissingProject(): void {
    $extensions = [
      // Core.
      'node' => $this->ext('node', ['name' => 'Node'], 1, 'core/modules/node'),
      // Packaged contrib: info.yml wins and Composer is not consulted.
      'token' => $this->ext('token', ['project' => 'token', 'version' => '8.x-1.15'], 1, 'modules/contrib/token'),
      // Git checkout of a contrib module; its sub-module is not listed.
      'admin_toolbar' => $this->ext('admin_toolbar', ['name' => 'Admin Toolbar'], 1, 'modules/contrib/admin_toolbar'),
      'admin_toolbar_links_access_filter' => $this->ext(
        'admin_toolbar_links_access_filter',
        [],
        1,
        'modules/contrib/admin_toolbar/admin_toolbar_links_access_filter',
      ),
      // A drupal/* package whose name is not a valid project name.
      'odd' => $this->ext('odd', [], 1, 'modules/contrib/odd'),
      // True custom module, named like a real Drupal.org project.
      'pathauto' => $this->ext('pathauto', [], 1, 'modules/custom/pathauto'),
    ];
    $packages = [
      'modules/contrib/token' => 'wrong',
      'modules/contrib/admin_toolbar' => 'Admin_Toolbar',
      'modules/contrib/odd' => 'odd-package',
    ];
    $byName = array_column($this->builder($extensions, $packages)->build('all')['modules'], NULL, 'machine_name');

    $identity = fn(string $name): array => [$byName[$name]['module_type'], $byName[$name]['reported_project']];
    $this->assertArrayNotHasKey('node', $byName);
    $this->assertArrayNotHasKey('admin_toolbar_links_access_filter', $byName);
    $this->assertSame(['contrib', 'token'], $identity('token'));
    $this->assertSame(['contrib', 'admin_toolbar'], $identity('admin_toolbar'));
    $this->assertSame(['contrib', NULL], $identity('odd'));
    $this->assertSame(['custom', NULL], $identity('pathauto'));

    // The 'core_contrib' scope keeps Composer-identified modules.
    $names = array_column($this->builder($extensions, $packages)->build('core_contrib')['modules'], 'machine_name');
    $this->assertEqualsCanonicalizing(['token', 'admin_toolbar', 'odd'], $names);
  }

  /**
   * Composer supplies the version when info.yml has none.
   */
  public function testComposerVersionFillsMissingVersion(): void {
    $extensions = [
      // Packaged release: info.yml wins.
      'token' => $this->ext('token', ['project' => 'token', 'version' => '8.x-1.15'], 1, 'modules/contrib/token'),
      // Git checkout identified by Composer.
      'metatag' => $this->ext('metatag', ['name' => 'Metatag'], 1, 'modules/contrib/metatag'),
      // info.yml names the project but has no version.
      'redis' => $this->ext('redis', ['project' => 'redis'], 1, 'modules/contrib/redis'),
      // Branch install: reported as a development version.
      'glightbox' => $this->ext('glightbox', [], 1, 'modules/contrib/glightbox'),
      // The containing package is another project: its version is not used.
      'mask' => $this->ext('mask', ['project' => 'mask'], 1, 'modules/contrib/bundle/mask'),
      // Composer has no version, or one too long to send.
      'audit' => $this->ext('audit', [], 1, 'modules/contrib/audit'),
      'long' => $this->ext('long', [], 1, 'modules/contrib/long'),
      // Custom code is never given a Composer version.
      'my_custom' => $this->ext('my_custom', [], 1, 'modules/custom/my_custom'),
      // A blank info.yml version falls through to Composer.
      'blank' => $this->ext('blank', ['project' => 'blank', 'version' => ' '], 1, 'modules/contrib/blank'),
    ];
    $packages = [
      'modules/contrib/token' => 'token',
      'modules/contrib/metatag' => 'metatag',
      'modules/contrib/redis' => 'redis',
      'modules/contrib/glightbox' => 'glightbox',
      'modules/contrib/bundle' => 'bundle',
      'modules/contrib/audit' => 'audit',
      'modules/contrib/long' => 'long',
      'modules/contrib/blank' => 'blank',
    ];
    $versions = [
      'modules/contrib/token' => '1.16.0',
      'modules/contrib/metatag' => '2.1.1',
      'modules/contrib/redis' => '1.9.0',
      'modules/contrib/glightbox' => '1.x-dev',
      'modules/contrib/bundle' => '3.0.0',
      'modules/contrib/long' => str_repeat('9', 51),
      'modules/custom/my_custom' => '1.0.0',
      'modules/contrib/blank' => 'dev-main',
    ];
    $byName = array_column($this->builder($extensions, $packages, $versions)->build('all')['modules'], NULL, 'machine_name');
    $version = fn(string $name): array => [$byName[$name]['version'], $byName[$name]['version_known']];
    $this->assertSame(['8.x-1.15', TRUE], $version('token'));
    $this->assertSame(['2.1.1', TRUE], $version('metatag'));
    $this->assertSame(['1.9.0', TRUE], $version('redis'));
    $this->assertSame(['1.x-dev', TRUE], $version('glightbox'));
    $this->assertSame(['0.0.0', FALSE], $version('mask'));
    $this->assertSame(['0.0.0', FALSE], $version('audit'));
    $this->assertSame(['0.0.0', FALSE], $version('long'));
    $this->assertSame(['0.0.0', FALSE], $version('my_custom'));
    $this->assertSame(['dev-main', TRUE], $version('blank'));
  }

  /**
   * Whitespace around a version is trimmed before it is sent.
   */
  public function testVersionIsTrimmed(): void {
    $extensions = [
      'token' => $this->ext('token', ['project' => 'token', 'version' => " 8.x-1.15\n"], 1, 'modules/contrib/token'),
      'redis' => $this->ext('redis', ['project' => 'redis'], 1, 'modules/contrib/redis'),
      'blank' => $this->ext('blank', ['project' => 'blank'], 1, 'modules/contrib/blank'),
    ];
    $packages = ['modules/contrib/redis' => 'redis', 'modules/contrib/blank' => 'blank'];
    $versions = ['modules/contrib/redis' => "\t1.9.0 ", 'modules/contrib/blank' => '  '];
    $byName = array_column($this->builder($extensions, $packages, $versions)->build('all')['modules'], NULL, 'machine_name');
    $this->assertSame(['8.x-1.15', TRUE], [$byName['token']['version'], $byName['token']['version_known']]);
    $this->assertSame(['1.9.0', TRUE], [$byName['redis']['version'], $byName['redis']['version_known']]);
    // A blank Composer version keeps the placeholder.
    $this->assertSame(['0.0.0', FALSE], [$byName['blank']['version'], $byName['blank']['version_known']]);
  }

  /**
   * Without Composer metadata the builder falls back to info.yml only.
   */
  public function testWorksWithoutResolver(): void {
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('reset')->willReturnSelf();
    $list->method('getList')->willReturn([
      'admin_toolbar' => $this->ext('admin_toolbar', [], 1, 'modules/contrib/admin_toolbar'),
    ]);
    $payload = (new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4'))->build('all');
    $this->assertSame('custom', $payload['modules'][0]['module_type']);
    $this->assertNull($payload['modules'][0]['reported_project']);
  }

  /**
   * Schema version 2 carries the platform block, left out when empty.
   */
  public function testPlatformBlock(): void {
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('reset')->willReturnSelf();
    $list->method('getList')->willReturn([]);

    $payload = (new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4'))->build('all');
    $this->assertSame(4, $payload['schema_version']);
    $this->assertArrayNotHasKey('platform', $payload);
    $this->assertSame('enabled', $payload['platform_reporting']);

    $platform = ['php' => ['version' => '8.3.0', 'sapi' => 'fpm-fcgi']];
    $payload = (new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4', NULL, $platform))->build('all');
    $this->assertSame($platform, $payload['platform']);
    $this->assertSame('enabled', $payload['platform_reporting']);
  }

  /**
   * Platform reporting turned off: no platform block, PHP still sent.
   */
  public function testPlatformReportingOff(): void {
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('reset')->willReturnSelf();
    $list->method('getList')->willReturn([]);

    $platform = ['php' => ['version' => '8.3.0', 'sapi' => 'fpm-fcgi'], 'os' => ['id' => 'debian']];
    $payload = (new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4', NULL, $platform, NULL, FALSE))->build('all');
    $this->assertArrayNotHasKey('platform', $payload);
    $this->assertSame('disabled', $payload['platform_reporting']);
    $this->assertSame('8.3.0', $payload['drupal_info']['php_version']);
  }

  /**
   * Schema version 3 carries the config_drift block when one is given.
   *
   * @covers ::build
   */
  public function testConfigDriftBlock(): void {
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('reset')->willReturnSelf();
    $list->method('getList')->willReturn([]);

    $payload = (new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4'))->build('all');
    $this->assertArrayNotHasKey('config_drift', $payload);

    $drift = [
      'status' => 'unavailable',
      'reason' => 'sync_directory_missing',
      'generated_at' => '2026-11-02T03:04:05Z',
      'total' => 0,
      'config_pr_enabled' => TRUE,
      'truncated' => FALSE,
      'objects' => [],
    ];
    $calls = new \ArrayObject();
    $builder = new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4', NULL, [], function () use ($calls, $drift): array {
      $calls->append(TRUE);
      return $drift;
    });
    // Comparing configuration is left until a payload is built.
    $this->assertCount(0, $calls);
    $payload = $builder->build('all');
    $this->assertSame($drift, $payload['config_drift']);
    $this->assertSame(4, $payload['schema_version']);
    // The sync and its change check see the same drift, compared once.
    $this->assertSame($drift, $builder->build('core_contrib')['config_drift']);
    $this->assertCount(1, $calls);

    $payload = (new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4', NULL, [], fn () => NULL))->build('all');
    $this->assertArrayNotHasKey('config_drift', $payload);
  }

  /**
   * Schema version 4 carries the upgrade_status block, read once.
   *
   * @covers ::build
   */
  public function testUpgradeStatusBlock(): void {
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('reset')->willReturnSelf();
    $list->method('getList')->willReturn([]);

    $block = ['status' => 'ready', 'generated_at' => '2026-10-25T01:00:00Z'];
    $calls = new \ArrayObject();
    $builder = new PayloadBuilder($list, '11.2.0', '8.4.0', '203.0.113.4', NULL, [], NULL, TRUE, function () use ($calls, $block): array {
      $calls->append(TRUE);
      return $block;
    });
    $this->assertCount(0, $calls);
    $this->assertSame($block, $builder->build('all')['upgrade_status']);
    $this->assertSame($block, $builder->build('contrib')['upgrade_status']);
    $this->assertCount(1, $calls);

    $payload = (new PayloadBuilder($list, '11.2.0', '8.4.0', '203.0.113.4', NULL, [], NULL, TRUE, fn () => NULL))->build('all');
    $this->assertArrayNotHasKey('upgrade_status', $payload);
  }

}
