<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ExportStorageManager;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\ConfigDriftCollector;
use Drupal\sentinel_connector\SyncService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the configuration drift report against a temporary sync directory.
 *
 * @group sentinel_connector
 */
#[RunTestsInSeparateProcesses]
class ConfigDriftCollectorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'sentinel_connector'];

  /**
   * The sync directory of this test.
   */
  protected string $syncDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'sentinel_connector']);
    $this->config('sentinel_connector.settings')
      ->set('api_base_url', 'https://sentinel.example.com')
      ->set('site_uuid', '11111111-1111-4111-8111-111111111111')
      ->set('site_url', 'https://example.com')
      ->set('site_name', 'Example')
      ->save();
    $this->container->get('state')->set('sentinel_connector.api_key', 'sk_test');
    $this->syncDirectory = (string) Settings::get('config_sync_directory');
    $this->container->get('file_system')->prepareDirectory($this->syncDirectory, 1);
    $this->exportToSync();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // A directory left unreadable would stop the test site from being removed.
    if (isset($this->syncDirectory) && is_dir($this->syncDirectory)) {
      @chmod($this->syncDirectory, 0775);
    }
    parent::tearDown();
  }

  /**
   * A site whose configuration matches the sync directory reports no drift.
   */
  public function testCleanSite(): void {
    $drift = $this->collect();
    $this->assertSame('complete', $drift['status']);
    $this->assertNull($drift['reason']);
    $this->assertSame(0, $drift['total']);
    $this->assertSame([], $drift['objects']);
    $this->assertFalse($drift['truncated']);
    $this->assertFalse($drift['config_pr_enabled']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $drift['generated_at']);
  }

  /**
   * Each change type is reported by name, sorted by collection then name.
   */
  public function testChangeTypes(): void {
    $active = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    // Changed in production.
    $this->config('system.site')->set('name', 'Changed in production')->save();
    // Only in production.
    $active->write('system.action.only_production', ['id' => 'only_production']);
    // Only in code.
    $sync->write('system.action.only_code', ['id' => 'only_code']);
    // Renamed in production: the same UUID under another name.
    $sync->write('system.action.old_name', ['uuid' => 'b0b7f1e2-0000-4000-8000-000000000001', 'id' => 'old_name']);
    $active->write('system.action.new_name', ['uuid' => 'b0b7f1e2-0000-4000-8000-000000000001', 'id' => 'new_name']);
    // Recreated in production: the same name under another UUID.
    $sync->write('system.action.recreated', ['uuid' => 'b0b7f1e2-0000-4000-8000-000000000002', 'id' => 'recreated']);
    $active->write('system.action.recreated', ['uuid' => 'b0b7f1e2-0000-4000-8000-000000000003', 'id' => 'recreated']);
    // A translation override only in production.
    $active->createCollection('language.fr')->write('system.site', ['name' => 'Site FR']);

    $drift = $this->collect();
    $this->assertSame('complete', $drift['status']);
    $this->assertNull($drift['reason']);
    $this->assertSame([
      ['name' => 'system.action.new_name', 'collection' => '', 'change' => 'rename'],
      ['name' => 'system.action.only_code', 'collection' => '', 'change' => 'delete'],
      ['name' => 'system.action.only_production', 'collection' => '', 'change' => 'create'],
      ['name' => 'system.action.recreated', 'collection' => '', 'change' => 'update'],
      ['name' => 'system.site', 'collection' => '', 'change' => 'update'],
      ['name' => 'system.site', 'collection' => 'language.fr', 'change' => 'create'],
    ], $drift['objects']);
    $this->assertSame(6, $drift['total']);
    $this->assertFalse($drift['truncated']);
  }

  /**
   * An object ignored by Config Ignore is not reported.
   */
  public function testConfigIgnoreIsRespected(): void {
    $this->enableOptionalModule('config_ignore');
    $this->config('config_ignore.settings')->set('mode', 'simple')->set('ignored_config_entities', ['system.site'])->save();
    $this->exportToSync();

    $this->config('system.site')->set('name', 'Changed in production')->save();
    $this->config('system.date')->set('first_day', 3)->save();

    $names = array_column($this->collect()['objects'], 'name');
    $this->assertNotContains('system.site', $names);
    $this->assertContains('system.date', $names);
  }

  /**
   * An object handled by an active Config Split is not reported.
   */
  public function testConfigSplitIsRespected(): void {
    $this->enableOptionalModule('config_split');
    $this->config('config_split.config_split.production')->setData([
      'uuid' => 'b0b7f1e2-0000-4000-8000-000000000010',
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => [],
      'id' => 'production',
      'label' => 'Production',
      'description' => '',
      'weight' => 0,
      'stackable' => FALSE,
      'no_patching' => FALSE,
      'storage' => 'folder',
      'folder' => $this->siteDirectory . '/split/production',
      'module' => [],
      'theme' => [],
      'complete_list' => ['system.site'],
      'partial_list' => [],
    ])->save();
    $this->exportToSync();
    $this->assertFalse($this->container->get('config.storage.sync')->exists('system.site'));

    $this->config('system.site')->set('name', 'Changed in production')->save();
    $this->config('system.date')->set('first_day', 3)->save();

    $names = array_column($this->collect()['objects'], 'name');
    $this->assertNotContains('system.site', $names);
    $this->assertContains('system.date', $names);
  }

  /**
   * A list above the cap is cut to 200 entries with the exact total.
   */
  public function testObjectCap(): void {
    $active = $this->container->get('config.storage');
    for ($i = 0; $i < 205; $i++) {
      $active->write(sprintf('system.action.drift_%03d', $i), ['id' => 'drift_' . $i]);
    }

    $drift = $this->collect();
    $this->assertSame('complete', $drift['status']);
    $this->assertSame(205, $drift['total']);
    $this->assertTrue($drift['truncated']);
    $this->assertCount(ConfigDriftCollector::OBJECT_LIMIT, $drift['objects']);
    $this->assertSame('system.action.drift_000', $drift['objects'][0]['name']);
    $this->assertSame('system.action.drift_199', $drift['objects'][199]['name']);
  }

  /**
   * Reaching the time budget reports the collections compared so far.
   */
  public function testTimeBudget(): void {
    $this->config('system.site')->set('name', 'Changed in production')->save();
    $this->container->get('config.storage')->createCollection('language.fr')->write('system.site', ['name' => 'Site FR']);

    // Each reading of the clock moves it one second on. The default
    // collection takes five checks after the start, so the budget runs out
    // before the language collection.
    $clock = 0.0;
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentMicroTime')->willReturnCallback(function () use (&$clock): float {
      return $clock++;
    });
    $time->method('getCurrentTime')->willReturn(1793588645);

    $drift = $this->collector(time: $time, budget: 5.5)->collect();
    $this->assertSame('incomplete', $drift['status']);
    $this->assertSame('time_budget', $drift['reason']);
    $this->assertSame([['name' => 'system.site', 'collection' => '', 'change' => 'update']], $drift['objects']);
    $this->assertSame(1, $drift['total']);
    $this->assertSame('2026-11-02T03:04:05Z', $drift['generated_at']);
  }

  /**
   * No sync directory gives an unavailable report, and the push still works.
   */
  public function testMissingSyncDirectory(): void {
    $this->container->get('file_system')->deleteRecursive($this->syncDirectory);

    $drift = $this->collect();
    $this->assertSame('unavailable', $drift['status']);
    $this->assertSame('sync_directory_missing', $drift['reason']);
    $this->assertSame(0, $drift['total']);
    $this->assertSame([], $drift['objects']);

    $body = $this->push();
    $this->assertSame('unavailable', $body['config_drift']['status']);
    $this->assertSame('sync_directory_missing', $body['config_drift']['reason']);
  }

  /**
   * A sync directory set to an empty value is missing too.
   */
  public function testUnsetSyncDirectory(): void {
    $this->setSetting('config_sync_directory', '');
    $drift = $this->collector()->collect();
    $this->assertSame('unavailable', $drift['status']);
    $this->assertSame('sync_directory_missing', $drift['reason']);
  }

  /**
   * An unreadable sync directory is not reported as an empty one.
   */
  public function testUnreadableSyncDirectory(): void {
    chmod($this->syncDirectory, 0000);
    clearstatcache();
    if (is_readable($this->syncDirectory)) {
      $this->markTestSkipped('The test runs as a user that reads any directory.');
    }

    $drift = $this->collect();
    $this->assertSame('unavailable', $drift['status']);
    $this->assertSame('sync_unreadable', $drift['reason']);
  }

  /**
   * An unreadable configuration file is not reported as missing from code.
   */
  public function testUnreadableSyncFile(): void {
    $file = $this->syncDirectory . '/system.site.yml';
    chmod($file, 0000);
    clearstatcache();
    try {
      if (is_readable($file)) {
        $this->markTestSkipped('The test runs as a user that reads any file.');
      }
      $drift = $this->collect();
      $this->assertSame('unavailable', $drift['status']);
      $this->assertSame('sync_unreadable', $drift['reason']);
    }
    finally {
      chmod($file, 0664);
    }
  }

  /**
   * A sync directory that cannot be entered is not reported as an empty one.
   */
  public function testSyncDirectoryWithoutExecute(): void {
    chmod($this->syncDirectory, 0644);
    clearstatcache();
    if (is_executable($this->syncDirectory)) {
      $this->markTestSkipped('The test runs as a user that enters any directory.');
    }

    $drift = $this->collect();
    $this->assertSame('unavailable', $drift['status']);
    $this->assertSame('sync_unreadable', $drift['reason']);
  }

  /**
   * A concurrent export is not waited for, and its lock is not taken over.
   */
  public function testConcurrentExportIsNotAwaited(): void {
    // Kernel tests replace the lock service with one that never locks.
    $own = new DatabaseLockBackend($this->container->get('database'));
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire(ExportStorageManager::LOCK_NAME));

    $start = microtime(TRUE);
    $drift = $this->collector(exportManager: $this->exportManager($own), lock: $own)->collect();
    $this->assertLessThan(5, microtime(TRUE) - $start);
    $this->assertSame('unavailable', $drift['status']);
    $this->assertSame('error', $drift['reason']);
    $this->assertFalse($other->lockMayBeAvailable(ExportStorageManager::LOCK_NAME));
    $other->release(ExportStorageManager::LOCK_NAME);

    // The lock is released after a comparison, for the next export.
    $this->assertSame('complete', $this->collector(exportManager: $this->exportManager($own), lock: $own)->collect()['status']);
    $this->assertTrue($other->acquire(ExportStorageManager::LOCK_NAME));
    $other->release(ExportStorageManager::LOCK_NAME);

    // An export this request made and may still read keeps its lock.
    $this->exportManager($own)->getStorage();
    $drift = $this->collector(exportManager: $this->exportManager($own), lock: $own)->collect();
    $this->assertSame('error', $drift['reason']);
    $this->assertFalse($other->acquire(ExportStorageManager::LOCK_NAME));
  }

  /**
   * A failing comparison is reported as an error, and never thrown.
   */
  public function testCollectorError(): void {
    $manager = $this->createMock('Drupal\Core\Config\StorageManagerInterface');
    $manager->method('getStorage')->willThrowException(new \RuntimeException('Lock not acquired.'));

    $drift = $this->collector(exportManager: $manager)->collect();
    $this->assertSame('unavailable', $drift['status']);
    $this->assertSame('error', $drift['reason']);
  }

  /**
   * Switched off on the settings form, nothing is compared.
   */
  public function testDisabled(): void {
    $this->config('system.site')->set('name', 'Changed in production')->save();
    $this->config('sentinel_connector.settings')->set('report_config_drift', FALSE)->save();

    $drift = $this->collect();
    $this->assertSame('unavailable', $drift['status']);
    $this->assertSame('disabled', $drift['reason']);
    $this->assertSame([], $drift['objects']);
  }

  /**
   * The config_pr flag follows the module and is sent with every status.
   */
  public function testConfigPrFlag(): void {
    $this->assertFalse($this->collect()['config_pr_enabled']);

    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->willReturnCallback(fn (string $module): bool => $module === 'config_pr');
    $this->assertTrue($this->collector(moduleHandler: $handler)->collect()['config_pr_enabled']);

    $this->config('sentinel_connector.settings')->set('report_config_drift', FALSE)->save();
    $drift = $this->collector(moduleHandler: $handler)->collect();
    $this->assertSame(['unavailable', TRUE], [$drift['status'], $drift['config_pr_enabled']]);
  }

  /**
   * No configuration value reaches the payload, which matches the schema.
   */
  public function testPayloadHoldsNoValues(): void {
    $this->config('system.site')->set('name', 'secret_value_marker')->set('slogan', 'secret_value_marker')->save();
    $planted = ['id' => 'planted', 'label' => 'secret_value_marker'];
    $this->container->get('config.storage')->write('system.action.planted', $planted);
    $codeOnly = ['id' => 'code_only', 'label' => 'secret_value_marker'];
    $this->container->get('config.storage.sync')->write('system.action.code_only', $codeOnly);

    $raw = $this->push(TRUE);
    $this->assertStringNotContainsString('secret_value_marker', $raw);
    $body = json_decode($raw, TRUE);
    $this->assertSame(3, $body['schema_version']);
    $this->assertSame('complete', $body['config_drift']['status']);
    $this->assertSame(
      ['system.action.code_only', 'system.action.planted', 'system.site'],
      array_column($body['config_drift']['objects'], 'name'),
    );

    if (!class_exists(Validator::class)) {
      $this->markTestSkipped('justinrainbow/json-schema is not installed.');
    }
    $validator = new Validator();
    $data = json_decode($raw);
    $validator->validate($data, (object) ['$ref' => 'file://' . dirname(__DIR__, 3) . '/tests/fixtures/drupal_sync.schema.json']);
    $this->assertSame([], array_map(fn ($e) => $e['property'] . ': ' . $e['message'], $validator->getErrors()));
  }

  /**
   * A change in drift counts as an inventory change for cron and deploy.
   */
  public function testDriftChangesTheFingerprint(): void {
    $this->push();
    $sync = $this->container->get(SyncService::class);
    $this->assertFalse($sync->hasInventoryChanged());

    // The payload builder holds the drift of the process that built it.
    $this->config('system.site')->set('name', 'Changed in production')->save();
    $this->container->set('Drupal\sentinel_connector\PayloadBuilder', NULL);
    $this->container->set(SyncService::class, NULL);
    $this->assertTrue($this->container->get(SyncService::class)->hasInventoryChanged());
  }

  /**
   * Runs the collector service.
   *
   * @return array<string, mixed>
   *   The config_drift block.
   */
  protected function collect(): array {
    return $this->container->get(ConfigDriftCollector::class)->collect();
  }

  /**
   * Builds a collector with some services replaced.
   */
  protected function collector(?TimeInterface $time = NULL, float $budget = ConfigDriftCollector::TIME_BUDGET, ?ModuleHandlerInterface $moduleHandler = NULL, mixed $exportManager = NULL, ?LockBackendInterface $lock = NULL): ConfigDriftCollector {
    return new ConfigDriftCollector(
      $exportManager ?? $this->exportManager(),
      $this->container->get('config.factory'),
      $moduleHandler ?? $this->container->get('module_handler'),
      Settings::getInstance(),
      $lock ?? $this->container->get('lock'),
      $time ?? $this->container->get('datetime.time'),
      $budget,
    );
  }

  /**
   * Writes the export storage to the sync directory, as `drush cex` does.
   */
  protected function exportToSync(): void {
    $this->copyConfig($this->exportManager()->getStorage(), $this->container->get('config.storage.sync'));
  }

  /**
   * Core's export storage manager, which the container keeps private.
   */
  protected function exportManager(?LockBackendInterface $lock = NULL): ExportStorageManager {
    return new ExportStorageManager(
      $this->container->get('config.storage'),
      $this->container->get('database'),
      $this->container->get('event_dispatcher'),
      $lock ?? $this->container->get('lock'),
    );
  }

  /**
   * Enables a contributed module, or skips the test when it is not present.
   */
  protected function enableOptionalModule(string $module): void {
    if (!$this->container->get('extension.list.module')->exists($module)) {
      $this->markTestSkipped("The $module module is not installed.");
    }
    $this->enableModules([$module]);
    $this->installConfig([$module]);
  }

  /**
   * Sends a push to a mocked Sentinel.
   *
   * @return ($raw is true ? string : array<string, mixed>)
   *   The request body, decoded unless raw.
   */
  protected function push(bool $raw = FALSE): string|array {
    $transactions = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"message":"ok"}')]));
    $stack->push(Middleware::history($transactions));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $result = $this->container->get(SyncService::class)->sync(SyncService::TRIGGER_DRUSH);
    $this->assertTrue($result->isOk());
    $body = (string) $transactions[0]['request']->getBody();
    return $raw ? $body : json_decode($body, TRUE);
  }

}
