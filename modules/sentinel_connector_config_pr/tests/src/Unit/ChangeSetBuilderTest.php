<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageManagerInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\sentinel_connector_config_pr\ChangeSetBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the change set between export and sync storage.
 *
 * @group sentinel_connector
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
#[Group('sentinel_connector')]
class ChangeSetBuilderTest extends UnitTestCase {

  /**
   * Created, updated and deleted objects become files; equal objects do not.
   */
  public function testChangesBecomeFiles(): void {
    $export = new MemoryStorage();
    $sync = new MemoryStorage();
    $export->write('system.site', ['name' => 'Production']);
    $sync->write('system.site', ['name' => 'Code']);
    $export->write('system.same', ['a' => 1]);
    $sync->write('system.same', ['a' => 1]);
    $export->write('views.view.new', ['id' => 'new']);
    $sync->write('views.view.old', ['id' => 'old']);

    $changes = $this->builder($export, $sync)->build('/config/sync/');

    $this->assertSame([
      'config/sync/system.site.yml',
      'config/sync/views.view.new.yml',
      'config/sync/views.view.old.yml',
    ], array_keys($changes));
    $this->assertSame('update', $changes['config/sync/system.site.yml']['op']);
    $this->assertSame(['name' => 'Production'], Yaml::decode((string) $changes['config/sync/system.site.yml']['content']));
    $this->assertSame('create', $changes['config/sync/views.view.new.yml']['op']);
    $this->assertSame('delete', $changes['config/sync/views.view.old.yml']['op']);
    $this->assertNull($changes['config/sync/views.view.old.yml']['content']);
  }

  /**
   * Collections are written to the same directories as core's file storage.
   */
  public function testCollectionsUseCoreDirectories(): void {
    $export = new MemoryStorage();
    $sync = new MemoryStorage();
    $export->write('system.site', ['name' => 'Same']);
    $sync->write('system.site', ['name' => 'Same']);
    $export->createCollection('language.fr')->write('system.site', ['name' => 'Production FR']);

    $changes = $this->builder($export, $sync)->build('config/sync');

    $this->assertSame(['config/sync/language/fr/system.site.yml'], array_keys($changes));
    $this->assertSame('language.fr', $changes['config/sync/language/fr/system.site.yml']['collection']);
    $this->assertSame('create', $changes['config/sync/language/fr/system.site.yml']['op']);
  }

  /**
   * No difference gives no change; an empty sync storage is reported.
   */
  public function testNoChangeAndEmptySync(): void {
    $export = new MemoryStorage();
    $sync = new MemoryStorage();
    $builder = $this->builder($export, $sync);
    $this->assertTrue($builder->syncStorageIsEmpty());

    $export->write('system.site', ['name' => 'Same']);
    $sync->write('system.site', ['name' => 'Same']);
    $this->assertFalse($builder->syncStorageIsEmpty());
    $this->assertSame([], $builder->build('config/sync'));
    $this->assertSame(Yaml::encode(['name' => 'Same']), $builder->exportContent('system.site', ''));
    $this->assertNull($builder->exportContent('missing.object', ''));
  }

  /**
   * A builder whose export storage manager returns the given storage.
   */
  protected function builder(StorageInterface $export, StorageInterface $sync): ChangeSetBuilder {
    $manager = $this->createMock(StorageManagerInterface::class);
    $manager->method('getStorage')->willReturn($export);
    return new ChangeSetBuilder($manager, $sync);
  }

}
