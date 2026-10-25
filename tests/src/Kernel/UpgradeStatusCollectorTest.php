<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\PayloadBuilder;
use Drupal\sentinel_connector\PushLimitFormatter;
use Drupal\sentinel_connector\SentinelClient;
use Drupal\sentinel_connector\SyncService;
use Drupal\sentinel_connector\UpgradeStatusCollector;
use Drupal\upgrade_status\ProjectCollector;
use Drush\Commands\DrushCommands;
use Drush\Log\DrushLoggerManager;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests the upgrade_status block against a stand-in for Upgrade Status.
 *
 * @group sentinel_connector
 */
#[RunTestsInSeparateProcesses]
#[Group('sentinel_connector')]
class UpgradeStatusCollectorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'sentinel_connector', 'upgrade_status'];

  /**
   * The clock of the services under test.
   */
  protected int $now = 1792890000;

  /**
   * Requests sent to the mocked Sentinel.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $transactions = [];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Collects the warnings of the connector's logger channel.
    $container->register('logger.channel.sentinel_connector', BufferLogger::class)->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'sentinel_connector']);
    // Uninstalling a module clears user data.
    $this->installSchema('user', ['users_data']);
    $this->config('sentinel_connector.settings')
      ->set('api_base_url', 'https://sentinel.example.com')
      ->set('site_uuid', '11111111-1111-4111-8111-111111111111')
      ->set('site_url', 'https://example.com')
      ->set('site_name', 'Example')
      ->save();
    $this->container->get('state')->set('sentinel_connector.api_key', 'sk_test');
    $this->setClock($this->now);
  }

  /**
   * Each status follows the rules in order.
   *
   * @param array<string, array{string, string}> $projects
   *   Type and next step per project name.
   * @param array<string, int> $scans
   *   Scan dates per project name.
   * @param string $status
   *   The expected status.
   * @param int $customNotReady
   *   The expected custom_not_ready count.
   *
   * @dataProvider statuses
   */
  #[DataProvider('statuses')]
  public function testStatus(array $projects, array $scans, string $status, int $customNotReady): void {
    $this->setProjects($projects, $scans);
    $block = $this->collect();
    $this->assertSame($status, $block['status']);
    $this->assertNull($block['reason']);
    $this->assertSame($customNotReady, $block['custom_not_ready']);
    $this->assertSame(count($projects), $block['projects_total']);
    $this->assertSame(array_sum($block['counts']), $block['projects_total']);
    $this->assertSame((int) \Drupal::VERSION, $block['current_major']);
    $this->assertSame((int) \Drupal::VERSION + 1, $block['target_major']);
    $this->assertSame('4.3.10', $block['module_version']);
    $this->assertSame([], $this->logger()->cleanLogs());
  }

  /**
   * Project lists and scans for each status.
   *
   * @return array<string, array{array<string, array{string, string}>, array<string, int>, string, int}>
   *   Projects, scans, status and custom_not_ready keyed by case.
   */
  public static function statuses(): array {
    $relax = ['token' => ['contrib', 'relax'], 'pathauto' => ['contrib', 'relax']];
    $date = 1792800000;
    return [
      'contrib update' => [$relax + ['webform' => ['contrib', 'update']], [], 'not_ready', 0],
      'contrib collaborate' => [$relax + ['old' => ['contrib', 'collaborate']], [], 'not_ready', 0],
      'custom rector' => [
        $relax + ['site_a' => ['custom', 'rector'], 'site_b' => ['custom', 'relax']],
        ['site_a' => $date, 'site_b' => $date],
        'not_ready',
        1,
      ],
      'custom manual beats scan' => [
        $relax + ['site_a' => ['custom', 'manual'], 'site_b' => ['custom', 'scan']],
        ['site_a' => $date],
        'not_ready',
        1,
      ],
      'not scanned' => [
        $relax + ['site_a' => ['custom', 'scan'], 'site_b' => ['custom', 'scan']],
        [],
        'not_scanned',
        0,
      ],
      'scanned but next step unknown' => [
        $relax + ['site_a' => ['custom', 'scan']],
        ['site_a' => $date],
        'incomplete',
        0,
      ],
      'incomplete' => [
        $relax + ['site_a' => ['custom', 'relax'], 'site_b' => ['custom', 'scan']],
        ['site_a' => $date],
        'incomplete',
        0,
      ],
      'ready' => [$relax + ['site_a' => ['custom', 'relax']], ['site_a' => $date], 'ready', 0],
      'remove only' => [['unused' => ['contrib', 'remove'], 'old_custom' => ['custom', 'remove']], [], 'ready', 0],
      'remove with relax' => [$relax + ['unused' => ['contrib', 'remove']], [], 'ready', 0],
      'no projects' => [[], [], 'ready', 0],
    ];
  }

  /**
   * A contrib-only site with every project relax is ready without a scan.
   */
  public function testContribOnlyReadyWithoutScan(): void {
    $this->setProjects(['token' => ['contrib', 'relax'], 'pathauto' => ['contrib', 'relax']]);
    $block = $this->collect();
    $this->assertSame('ready', $block['status']);
    $expected = array_fill_keys(UpgradeStatusCollector::NEXT_STEPS, 0);
    $expected['relax'] = 2;
    $this->assertSame($expected, $block['counts']);
    $this->assertNull($block['newest_scan_at']);
    $this->assertNull($block['oldest_scan_at']);
  }

  /**
   * Scan dates come only from projects on the site.
   */
  public function testScanDatesIgnoreDeletedProjects(): void {
    $this->setProjects(
      ['site_a' => ['custom', 'relax'], 'site_b' => ['custom', 'relax']],
      ['site_a' => 1792800000, 'site_b' => 1792700000, 'deleted' => 1792890000],
    );
    $this->container->get('keyvalue')->get(UpgradeStatusCollector::SCAN_RESULTS)->set('older_deleted', ['date' => 1000]);
    $block = $this->collect();
    $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1792800000), $block['newest_scan_at']);
    $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1792700000), $block['oldest_scan_at']);
    $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->now), $block['generated_at']);
  }

  /**
   * No block when Upgrade Status is not enabled or reporting is off.
   */
  public function testNoBlock(): void {
    $this->setProjects(['token' => ['contrib', 'relax']]);
    $this->assertArrayHasKey('upgrade_status', $this->push());

    $this->config('sentinel_connector.settings')->set('report_upgrade_status', FALSE)->save();
    $this->assertNull($this->collect());
    $this->assertArrayNotHasKey('upgrade_status', $this->push());

    // settings.php wins over configuration, both ways.
    $this->overrideReporting(TRUE);
    $this->assertSame('ready', $this->collect()['status']);
    $this->config('sentinel_connector.settings')->set('report_upgrade_status', TRUE)->save();
    $this->overrideReporting(FALSE);
    $this->assertNull($this->collect());
    $this->overrideReporting(NULL);

    $this->container->get('module_installer')->uninstall(['upgrade_status']);
    $this->assertNull($this->collect());
    $body = $this->push();
    $this->assertArrayNotHasKey('upgrade_status', $body);
    $this->assertSame(4, $body['schema_version']);
  }

  /**
   * A broken Upgrade Status sends unavailable, logs one warning and pushes.
   *
   * @param string $mode
   *   How the project collector breaks.
   * @param string $reason
   *   The expected reason.
   *
   * @dataProvider brokenCollectors
   */
  #[DataProvider('brokenCollectors')]
  public function testUnavailable(string $mode, string $reason): void {
    $this->setProjects(['token' => ['contrib', 'relax']], ['token' => 1792800000]);
    match ($mode) {
      // An older or newer Upgrade Status without the method.
      'older' => $this->container->set(UpgradeStatusCollector::SERVICE, new \stdClass()),
      'unknown next step' => $this->setProjects(['token' => ['contrib', 'later']]),
      default => $this->container->get('state')->set(ProjectCollector::STATE_MODE, $mode),
    };

    // Cron checks for a change, then pushes: the block is built once, so the
    // warning is logged once.
    $this->mockSentinel();
    $sync = $this->container->get(SyncService::class);
    $this->assertTrue($sync->hasInventoryChanged());
    $this->assertTrue($sync->sync(SyncService::TRIGGER_CRON)->isOk());
    $body = json_decode((string) $this->transactions[0]['request']->getBody(), TRUE);
    $block = $body['upgrade_status'];
    $this->assertSame('unavailable', $block['status']);
    $this->assertSame($reason, $block['reason']);
    $this->assertSame(0, $block['projects_total']);
    $this->assertSame(0, array_sum($block['counts']));
    $this->assertNull($block['newest_scan_at']);
    // The push itself logs its outcome on the same channel.
    $logs = array_values(array_filter($this->logger()->cleanLogs(), fn (array $log): bool => isset($log[2]['@reason'])));
    $this->assertCount(1, $logs);
    $this->assertSame('warning', $logs[0][0]);
    $this->assertSame($reason, $logs[0][2]['@reason']);
    $this->assertSchema($body);
  }

  /**
   * Ways the project collector breaks, with the reason each sends.
   *
   * @return array<string, array{string, string}>
   *   Mode and reason keyed by case.
   */
  public static function brokenCollectors(): array {
    return [
      'older' => ['older', 'unsupported_api'],
      'throws' => ['throw', 'error'],
      'not a list' => ['not_array', 'unexpected_data'],
      'unknown next step' => ['unknown next step', 'unexpected_data'],
    ];
  }

  /**
   * A missing service sends unavailable.
   */
  public function testServiceMissing(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(FALSE);
    $collector = new UpgradeStatusCollector(
      $container,
      $this->container->get('module_handler'),
      $this->container->get('extension.list.module'),
      $this->container->get('keyvalue'),
      $this->container->get('config.factory'),
      Settings::getInstance(),
      $this->container->get('datetime.time'),
      $this->logger(),
    );
    $block = $collector->collect();
    $this->assertSame('unavailable', $block['status']);
    $this->assertSame('service_missing', $block['reason']);
    $this->assertCount(1, $this->logger()->cleanLogs());
  }

  /**
   * The payload holds counts only, never project names or paths.
   */
  public function testPayloadHoldsNoNames(): void {
    $this->setProjects(
      ['secret_custom_marker' => ['custom', 'manual'], 'token' => ['contrib', 'relax']],
      ['secret_custom_marker' => 1792800000],
    );
    $raw = json_encode($this->push()['upgrade_status']);
    $this->assertStringNotContainsString('secret_custom_marker', $raw);
    $this->assertStringNotContainsString('token', $raw);
    $this->assertStringNotContainsString('modules/', $raw);
    $this->assertStringNotContainsString('custom', str_replace('custom_not_ready', '', $raw));
  }

  /**
   * The generation time alone does not change the fingerprint; a scan does.
   */
  public function testFingerprint(): void {
    $this->setProjects(['site_a' => ['custom', 'scan'], 'token' => ['contrib', 'relax']]);
    $first = $this->push();
    $this->assertFalse($this->sync()->hasInventoryChanged());

    // An hour later, the same result: only generated_at differs.
    $this->setClock($this->now + 3600);
    $this->assertNotSame($first['upgrade_status']['generated_at'], $this->collect()['generated_at']);
    $this->assertFalse($this->sync()->hasInventoryChanged());

    // A scan stores a result: the next cron pushes it.
    $this->setProjects(['site_a' => ['custom', 'rector'], 'token' => ['contrib', 'relax']], ['site_a' => $this->now + 3000]);
    $this->setClock($this->now + 3600);
    $this->assertTrue($this->sync()->hasInventoryChanged());
    $second = $this->push();
    $this->assertSame('not_ready', $second['upgrade_status']['status']);
    $this->assertFalse($this->sync()->hasInventoryChanged());

    // A rescan with the same outcome still changes the scan date.
    $this->setProjects(['site_a' => ['custom', 'rector'], 'token' => ['contrib', 'relax']], ['site_a' => $this->now + 3500]);
    $this->setClock($this->now + 3600);
    $this->assertTrue($this->sync()->hasInventoryChanged());
  }

  /**
   * The command scans, then syncs; a failed scan fails without a push.
   */
  public function testDrushCommand(): void {
    $this->setProjects(['site_a' => ['custom', 'relax'], 'token' => ['contrib', 'relax']], ['site_a' => 1792800000]);
    $this->mockSentinel();

    $command = $this->command(0);
    $command->upgradeStatus();
    $this->assertTrue($command->scanned);
    $this->assertCount(1, $this->transactions);
    $body = json_decode((string) $this->transactions[0]['request']->getBody(), TRUE);
    $this->assertSame('ready', $body['upgrade_status']['status']);
    $messages = implode("\n", array_column($command->buffer->cleanLogs(), 1));
    $this->assertStringContainsString('readiness: ready', $messages);

    // Problems found are a result, not a failure.
    $this->mockSentinel();
    $this->command(3)->upgradeStatus();
    $this->assertCount(1, $this->transactions);

    $this->mockSentinel();
    $failed = $this->command(1);
    try {
      $failed->upgradeStatus();
      $this->fail('A failed scan must fail the command.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('scan failed', $e->getMessage());
    }
    $this->assertTrue($failed->scanned);
    $this->assertCount(0, $this->transactions);

    // A failed push fails the command too.
    $this->mockSentinel(new Response(500, [], '{"message":"down"}'));
    $this->expectException(\RuntimeException::class);
    $this->command(0)->upgradeStatus();
  }

  /**
   * Without Upgrade Status the command fails before scanning.
   */
  public function testDrushCommandWithoutUpgradeStatus(): void {
    $this->container->get('module_installer')->uninstall(['upgrade_status']);
    $command = $this->command(0);
    try {
      $command->upgradeStatus();
      $this->fail('The command must fail without Upgrade Status.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('not enabled', $e->getMessage());
    }
    $this->assertFalse($command->scanned);
  }

  /**
   * The update hook turns reporting on for existing sites.
   */
  public function testUpdateHook(): void {
    $this->config('sentinel_connector.settings')->clear('report_upgrade_status')->save();
    $this->container->get('module_handler')->loadInclude('sentinel_connector', 'install');
    sentinel_connector_update_10004();
    $this->assertTrue($this->config('sentinel_connector.settings')->get('report_upgrade_status'));

    // A site that switched it off keeps it off.
    $this->config('sentinel_connector.settings')->set('report_upgrade_status', FALSE)->save();
    sentinel_connector_update_10004();
    $this->assertFalse($this->config('sentinel_connector.settings')->get('report_upgrade_status'));
  }

  /**
   * Stores the projects the stand-in returns and their scan results.
   *
   * @param array<string, array{string, string}> $projects
   *   Type and next step per project name.
   * @param array<string, int> $scans
   *   Scan dates per project name.
   */
  protected function setProjects(array $projects, array $scans = []): void {
    $this->container->get('state')->set(ProjectCollector::STATE_PROJECTS, $projects);
    $storage = $this->container->get('keyvalue')->get(UpgradeStatusCollector::SCAN_RESULTS);
    $storage->deleteAll();
    foreach ($scans as $name => $date) {
      $next = $projects[$name][1] ?? 'relax';
      $storage->set($name, ['date' => $date, 'data' => ['totals' => ['upgrade_status_next' => $next]]]);
    }
  }

  /**
   * Sets the clock and recreates the services that read it.
   */
  protected function setClock(int $now): void {
    $this->container->set('datetime.time', new class($now) implements TimeInterface {

      public function __construct(protected int $now) {}

      /**
       * {@inheritdoc}
       */
      public function getRequestTime(): int {
        return $this->now;
      }

      /**
       * {@inheritdoc}
       */
      public function getRequestMicroTime(): float {
        return (float) $this->now;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentTime(): int {
        return $this->now;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentMicroTime(): float {
        return (float) $this->now;
      }

    });
    $this->resetServices();
  }

  /**
   * Recreates the services that hold a block or the clock.
   */
  protected function resetServices(): void {
    $services = [
      UpgradeStatusCollector::class,
      'sentinel_connector.payload_builder_factory',
      PayloadBuilder::class,
      SentinelClient::class,
      SyncService::class,
    ];
    foreach ($services as $id) {
      $this->container->set($id, NULL);
    }
  }

  /**
   * Sets the reporting switch in settings.php, NULL to remove it.
   */
  protected function overrideReporting(?bool $value): void {
    $settings = Settings::getAll();
    unset($settings[UpgradeStatusCollector::SETTINGS_KEY]);
    if ($value !== NULL) {
      $settings[UpgradeStatusCollector::SETTINGS_KEY] = $value;
    }
    new Settings($settings);
    $this->container->set('settings', Settings::getInstance());
    $this->resetServices();
  }

  /**
   * Runs the collector service.
   *
   * @return array<string, mixed>|null
   *   The upgrade_status block.
   */
  protected function collect(): ?array {
    return $this->container->get(UpgradeStatusCollector::class)->collect(FALSE);
  }

  /**
   * A fresh sync service, as the next cron run builds it.
   */
  protected function sync(): SyncService {
    $this->resetServices();
    return $this->container->get(SyncService::class);
  }

  /**
   * The buffering logger of the connector's channel.
   */
  protected function logger(): BufferLogger {
    // Registered in place of the channel by register().
    /** @var \Drupal\Tests\sentinel_connector\Kernel\BufferLogger $logger */
    $logger = $this->container->get('logger.channel.sentinel_connector');
    return $logger;
  }

  /**
   * Answers the next push with the given response.
   */
  protected function mockSentinel(?Response $response = NULL): void {
    $this->transactions = [];
    $stack = HandlerStack::create(new MockHandler([$response ?? new Response(200, [], '{"message":"ok"}')]));
    $stack->push(Middleware::history($this->transactions));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $this->resetServices();
  }

  /**
   * Sends a push to a mocked Sentinel.
   *
   * @return array<string, mixed>
   *   The decoded request body.
   */
  protected function push(): array {
    $this->mockSentinel();
    $result = $this->container->get(SyncService::class)->sync(SyncService::TRIGGER_DRUSH);
    $this->assertTrue($result->isOk());
    return json_decode((string) $this->transactions[0]['request']->getBody(), TRUE);
  }

  /**
   * The Drush command with a scan that returns the given exit code.
   */
  protected function command(int $exit): TestUpgradeStatusCommands {
    if (!class_exists(DrushCommands::class)) {
      $this->markTestSkipped('Drush is not installed.');
    }
    if (!function_exists('dt')) {
      // Drush loads its helper functions when it starts.
      require_once dirname((string) (new \ReflectionClass(DrushCommands::class))->getFileName(), 3) . '/includes/output.inc';
    }
    $command = new TestUpgradeStatusCommands(
      $this->container->get(SyncService::class),
      $this->container->get(PushLimitFormatter::class),
      $this->container->get(UpgradeStatusCollector::class),
    );
    $command->exit = $exit;
    $command->buffer = new BufferLogger();
    $logger = new DrushLoggerManager();
    $logger->add('buffer', $command->buffer);
    $command->setLogger($logger);
    return $command;
  }

  /**
   * Validates a payload against the shared schema.
   *
   * @param array<string, mixed> $payload
   *   The payload.
   */
  protected function assertSchema(array $payload): void {
    if (!class_exists(Validator::class)) {
      return;
    }
    $validator = new Validator();
    $data = json_decode(json_encode($payload));
    $validator->validate($data, (object) ['$ref' => 'file://' . dirname(__DIR__, 3) . '/tests/fixtures/drupal_sync.schema.json']);
    $this->assertSame([], array_map(fn ($e) => $e['property'] . ': ' . $e['message'], $validator->getErrors()));
  }

}
