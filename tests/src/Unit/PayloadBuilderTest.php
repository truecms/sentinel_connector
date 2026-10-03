<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\sentinel_connector\PayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\sentinel_connector\PayloadBuilder
 * @group sentinel_connector
 */
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
   */
  private function builder(array $extensions): PayloadBuilder {
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('reset')->willReturnSelf();
    $list->method('getList')->willReturn($extensions);
    return new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4');
  }

  /**
   * @covers ::build
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

    $byName = [];
    foreach ($payload['modules'] as $m) {
      $byName[$m['machine_name']] = $m;
    }

    $this->assertSame('core', $byName['node']['module_type']);
    $this->assertSame('contrib', $byName['token']['module_type']);
    $this->assertSame('custom', $byName['my_custom']['module_type']);

    $this->assertSame('8.x-1.15', $byName['token']['version']);
    $this->assertTrue($byName['token']['version_known']);
    $this->assertSame('token', $byName['token']['reported_project']);
    $this->assertSame('drupal', $byName['node']['reported_project']);
    $this->assertSame('10.3.0', $byName['node']['version']);
    $this->assertTrue($byName['node']['version_known']);
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
   * @covers ::build
   */
  public function testScopeFiltering(): void {
    $extensions = [
      'node' => $this->ext('node', ['name' => 'Node'], 1, 'core/modules/node'),
      'token' => $this->ext('token', ['name' => 'Token', 'project' => 'token', 'version' => '8.x-1.15'], 1, 'modules/contrib/token'),
      'my_custom' => $this->ext('my_custom', ['name' => 'My Custom'], 1, 'modules/custom/my_custom'),
    ];

    $names = fn(array $p) => array_column($p['modules'], 'machine_name');

    $this->assertEqualsCanonicalizing(['node', 'token', 'my_custom'], $names($this->builder($extensions)->build('all')));
    $this->assertEqualsCanonicalizing(['token', 'my_custom'], $names($this->builder($extensions)->build('contrib_custom')));
    $this->assertEqualsCanonicalizing(['token'], $names($this->builder($extensions)->build('contrib')));
    $this->assertSame('contrib', $this->builder($extensions)->build('contrib')['inventory_scope']);
    $this->assertSame('contrib_custom', $this->builder($extensions)->build('contrib_custom')['inventory_scope']);
  }

  /**
   * Project identity follows packaging metadata, never extension names.
   *
   * @covers ::build
   */
  public function testSubmodulesAndUnknownMetadata(): void {
    $extensions = [
      'webform_ui' => $this->ext('webform_ui', ['project' => 'webform', 'version' => '6.2.8'], 0, 'modules/contrib/webform/modules/webform_ui'),
      'token' => $this->ext('token', ['version' => ''], 1, 'modules/custom/token'),
      'invalid_project' => $this->ext('invalid_project', [
        'project' => 'https://internal.example.com/project',
        'version' => '1.0.0',
      ], 1, 'modules/contrib/invalid_project'),
    ];
    $payload = $this->builder($extensions)->build('all');
    $byName = array_column($payload['modules'], NULL, 'machine_name');
    $this->assertSame('webform', $byName['webform_ui']['reported_project']);
    $this->assertSame('6.2.8', $byName['webform_ui']['version']);
    $this->assertFalse($byName['webform_ui']['enabled']);
    $this->assertSame('custom', $byName['token']['module_type']);
    $this->assertNull($byName['token']['reported_project']);
    $this->assertFalse($byName['token']['version_known']);
    $this->assertSame('0.0.0', $byName['token']['version']);
    $this->assertNull($byName['invalid_project']['reported_project']);
  }

}
