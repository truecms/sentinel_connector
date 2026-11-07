<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\StringTranslation\Translator\StaticTranslation;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\StatusReportCollector;
use Drupal\sentinel_connector\SyncService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests the status_report block, its web snapshot and its setting.
 *
 * @group sentinel_connector
 */
#[RunTestsInSeparateProcesses]
#[Group('sentinel_connector')]
class StatusReportCollectorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'sentinel_connector', 'sentinel_status_report_test'];

  /**
   * The start time of the test clock.
   */
  protected const START = 1792890000;

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
    $container->register('logger.channel.sentinel_connector', BufferLogger::class)->setPublic(TRUE);
    // A real lock, so a second backend can hold it as another request would.
    $container->register('lock', DatabaseLockBackend::class)
      ->addArgument(new Reference('database'))
      ->setPublic(TRUE);
    $container->register('datetime.time', StatusReportTestTime::class)
      ->addArgument((float) self::START)
      ->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'sentinel_connector']);
    // The user module's own check reads the anonymous user.
    $this->installEntitySchema('user');
    $this->setSetting('sentinel_connector.api_base_url', 'https://sentinel.example.com');
    $this->setSetting('file_temp_path', '/srv/example-temp');
    $this->config('sentinel_connector.settings')
      ->set('site_uuid', '11111111-1111-4111-8111-111111111111')
      ->save();
    $this->container->get('state')->set('sentinel_connector.api_key', 'sk_test');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    // Set before the container is built, so the private stream wrapper is
    // registered and core's own private files check runs as on a site.
    $this->setSetting('file_private_path', '/srv/example-private');
  }

  /**
   * Only errors and warnings are sent, each with the module that made it.
   */
  public function testErrorsAndWarningsOnlyWithProvider(): void {
    $this->setItems([
      'test_error' => ['title' => 'Test error', 'value' => 'Broken', 'severity' => 2],
      'test_warning' => ['title' => 'Test warning', 'value' => 'Shaky', 'severity' => 1],
      'test_ok' => ['title' => 'Test OK', 'value' => 'Fine', 'severity' => 0],
      'test_info' => ['title' => 'Test info', 'value' => 'Note', 'severity' => -1],
      'test_none' => ['title' => 'No severity', 'value' => 'Note'],
    ]);
    $block = $this->collector()->build(StatusReportCollector::CONTEXT_WEB);
    $this->assertSame('reported', $block['status']);
    $this->assertSame('web', $block['context']);
    $this->assertSame([], $block['left_out']);
    $items = $this->byKey($block);
    $this->assertSame('error', $items['test_error']['severity']);
    $this->assertSame('warning', $items['test_warning']['severity']);
    $this->assertSame('sentinel_status_report_test', $items['test_error']['provider']);
    foreach (['test_ok', 'test_info', 'test_none'] as $key) {
      $this->assertArrayNotHasKey($key, $items);
    }
    // The kernel test site has no trusted host patterns: a system error.
    $this->assertSame('system', $items['trusted_host_patterns']['provider'] ?? NULL);
    $this->assertSame('error', $items['trusted_host_patterns']['severity']);
    foreach ($block['items'] as $item) {
      $this->assertContains($item['severity'], ['error', 'warning']);
      // The connector's own push status is never sent back to Sentinel.
      $this->assertNotSame('sentinel_connector', $item['provider']);
    }
    $this->assertArrayNotHasKey('sentinel_connector', $items);
    $this->assertSchemaBlock($block);
  }

  /**
   * Drupal 11.2 enum severities count like integers.
   */
  public function testEnumSeverities(): void {
    $this->container->get('state')->set('sentinel_status_report_test.enum', TRUE);
    $this->setItems([
      'enum_error' => ['title' => 'Enum error', 'severity' => 2],
      'enum_warning' => ['title' => 'Enum warning', 'severity' => 1],
      'enum_ok' => ['title' => 'Enum OK', 'severity' => 0],
      'enum_info' => ['title' => 'Enum info', 'severity' => -1],
    ]);
    $items = $this->byKey($this->collector()->build(StatusReportCollector::CONTEXT_WEB));
    $this->assertSame('error', $items['enum_error']['severity']);
    $this->assertSame('warning', $items['enum_warning']['severity']);
    $this->assertArrayNotHasKey('enum_ok', $items);
    $this->assertArrayNotHasKey('enum_info', $items);
  }

  /**
   * Texts arrive as plain text, without links, tags or site paths.
   */
  public function testTextsAreCleaned(): void {
    $root = $this->container->getParameter('app.root');
    $this->setItems([
      'cleaned' => [
        'title' => new TranslatableMarkup("Cleaned\n   <em>title</em>"),
        'value' => ['#markup' => 'Version <strong>1.2</strong> &amp; more'],
        'description' => [
          'intro' => ['#markup' => '<p>Fix the file <code>' . $root . '/sites/default/settings.php</code>.</p>'],
          'link' => [
            '#type' => 'link',
            '#title' => 'Read the handbook',
            '#url' => Url::fromUri('https://www.drupal.org/docs'),
          ],
          'paths' => ['#markup' => '<p>Private: /srv/example-private/backup. Temporary: /srv/example-temp/x.</p>'],
        ],
        'severity' => 1,
      ],
      'kept' => [
        'title' => 'Kept',
        'description' => 'Needs PHP &lt;8.3 or later. Not a site path: /srv/example-privateer/x.',
        'severity' => 1,
      ],
      'escaped' => [
        'title' => 'Escaped',
        'description' => '&lt;script&gt;alert(1)&lt;/script&gt; <a href="https://evil.example">click</a>',
        'severity' => 2,
      ],
    ]);
    $items = $this->byKey($this->collector()->build(StatusReportCollector::CONTEXT_WEB));
    $this->assertSame('Cleaned title', $items['cleaned']['title']);
    $this->assertSame('Version 1.2 & more', $items['cleaned']['value']);
    $description = $items['cleaned']['description'];
    $this->assertSame('Fix the file [site root]/sites/default/settings.php. Read the handbook Private: [private files]/backup. Temporary: [temporary files]/x.', $description);
    $this->assertStringNotContainsString('https://www.drupal.org', $description);
    $this->assertStringNotContainsString($root, $description);
    $this->assertSame('alert(1) click', $items['escaped']['description']);
    $this->assertSame('Needs PHP <8.3 or later. Not a site path: /srv/example-privateer/x.', $items['kept']['description']);
    $this->assertSchemaBlock($this->collector()->build(StatusReportCollector::CONTEXT_WEB));
  }

  /**
   * Over-long fields are cut, keys are cleaned, errors are kept first.
   */
  public function testLimits(): void {
    $words = trim(str_repeat('word ', 400));
    $items = [
      'A Long Key With Spaces/And?Marks' . str_repeat('x', 200) => [
        'title' => $words,
        'value' => $words,
        'description' => $words,
        'severity' => 1,
      ],
    ];
    for ($i = 0; $i < 110; $i++) {
      $items[sprintf('bulk_warning_%03d', $i)] = ['title' => "Warning $i", 'severity' => 1];
    }
    for ($i = 0; $i < 10; $i++) {
      $items[sprintf('zz_error_%02d', $i)] = ['title' => "Error $i", 'severity' => 2];
    }
    $this->setItems($items);
    $block = $this->collector()->build(StatusReportCollector::CONTEXT_WEB);
    $this->assertCount(StatusReportCollector::MAX_ITEMS, $block['items']);
    // Every error is kept, ahead of the warnings.
    $errors = array_keys(array_filter($block['items'], fn (array $item): bool => $item['severity'] === 'error'));
    $this->assertSame(range(0, count($errors) - 1), $errors);
    foreach (range(0, 9) as $i) {
      $this->assertArrayHasKey(sprintf('zz_error_%02d', $i), $this->byKey($block));
    }
    $long = NULL;
    foreach ($block['items'] as $item) {
      $this->assertMatchesRegularExpression('/^[a-z0-9_.:-]{1,128}$/', $item['key']);
      if (str_starts_with($item['key'], 'a_long_key')) {
        $long = $item;
      }
    }
    $this->assertNotNull($long);
    $this->assertSame(128, strlen($long['key']));
    $this->assertStringStartsWith('a_long_key_with_spaces_and_marks', $long['key']);
    foreach (['title' => 200, 'value' => 300, 'description' => 1000] as $field => $limit) {
      $this->assertLessThanOrEqual($limit, mb_strlen($long[$field]));
      $this->assertGreaterThan($limit - 10, mb_strlen($long[$field]));
      // Cut on a word, with an ellipsis.
      $this->assertMatchesRegularExpression('/ word…$/u', $long[$field]);
    }
    $this->assertSchemaBlock($block);
  }

  /**
   * A text that cannot be rendered is sent empty; the list is kept.
   */
  public function testUnrenderableTextKeepsTheList(): void {
    $this->setItems([
      'broken_link' => [
        'title' => 'Broken link',
        'value' => 'Bad',
        'description' => [
          '#type' => 'link',
          '#title' => 'Missing',
          '#url' => Url::fromRoute('sentinel_status_report_test.missing_route'),
        ],
        'severity' => 2,
      ],
      'fine' => ['title' => 'Fine', 'severity' => 1],
    ]);
    $block = $this->collector()->build(StatusReportCollector::CONTEXT_WEB);
    $this->assertSame('reported', $block['status']);
    $items = $this->byKey($block);
    $this->assertSame('', $items['broken_link']['description']);
    $this->assertSame('Bad', $items['broken_link']['value']);
    $this->assertArrayHasKey('fine', $items);
    $this->assertCount(1, $this->logger()->cleanLogs());
  }

  /**
   * The list is built in the default language, whatever the request's is.
   */
  public function testBuiltInTheDefaultLanguage(): void {
    $translator = new class() extends StaticTranslation {

      /**
       * {@inheritdoc}
       *
       * @return array<string, array<string, string>>
       *   Translations keyed by context, then by source string.
       */
      protected function getLanguage($langcode) {
        return $langcode === 'fr' ? ['' => ['Translated title' => 'Titre traduit']] : [];
      }

    };
    /** @var \Drupal\Core\StringTranslation\TranslationManager $translation */
    $translation = $this->container->get('string_translation');
    $translation->addTranslator($translator);
    // The visitor's request is in French.
    $this->container->set('language_manager', new class($this->container->get('language.default')) extends LanguageManager {

      /**
       * {@inheritdoc}
       */
      public function getCurrentLanguage($type = LanguageInterface::TYPE_INTERFACE) {
        return new Language(['id' => 'fr']);
      }

    });
    $translation->setDefaultLangcode('fr');
    $this->assertSame('Titre traduit', (string) new TranslatableMarkup('Translated title'));
    $this->setItems(['translated' => ['title' => new TranslatableMarkup('Translated title'), 'severity' => 1]]);
    $items = $this->byKey($this->collector()->build(StatusReportCollector::CONTEXT_WEB));
    $this->assertSame('Translated title', $items['translated']['title']);
    // The request's language is back.
    $this->assertSame('Titre traduit', (string) new TranslatableMarkup('Translated title'));
  }

  /**
   * A cache rebuild forgets the snapshot, so a deployment is seen at once.
   */
  public function testRebuildForgetsSnapshot(): void {
    $state = $this->container->get('state');
    $state->set(StatusReportCollector::STATE_SNAPSHOT, ['checked_at' => self::START, 'items' => []]);
    $state->set(StatusReportCollector::STATE_ATTEMPT, self::START);
    $this->container->get('module_handler')->loadAll();
    $this->container->get('module_handler')->invoke('sentinel_connector', 'rebuild');
    $state->resetCache();
    $this->assertNull($state->get(StatusReportCollector::STATE_SNAPSHOT));
    $this->assertNull($state->get(StatusReportCollector::STATE_ATTEMPT));
  }

  /**
   * The alter hooks run; what they add carries the altering module.
   */
  public function testAlterHooks(): void {
    $this->setItems(['altered' => ['title' => 'Altered', 'severity' => 0]]);
    $this->container->get('state')->set('sentinel_status_report_test.alter', [
      'altered' => ['title' => 'Altered', 'severity' => 2],
      'added_by_alter' => ['title' => 'Added', 'severity' => 1],
    ]);
    $items = $this->byKey($this->collector()->build(StatusReportCollector::CONTEXT_WEB));
    $this->assertSame('error', $items['altered']['severity']);
    $this->assertSame('sentinel_status_report_test', $items['altered']['provider']);
    $this->assertSame('sentinel_status_report_test', $items['added_by_alter']['provider']);
  }

  /**
   * A throwing hook is skipped and logged once; the sync still succeeds.
   */
  public function testThrowingHookIsSkipped(): void {
    $this->container->get('state')->set('sentinel_status_report_test.throw', TRUE);
    $collector = $this->collector();
    $block = $collector->build(StatusReportCollector::CONTEXT_WEB);
    $this->assertSame('reported', $block['status']);
    // Other modules still report.
    $this->assertArrayHasKey('trusted_host_patterns', $this->byKey($block));
    $collector->build(StatusReportCollector::CONTEXT_WEB);
    $logs = $this->logger()->cleanLogs();
    $this->assertCount(1, $logs, print_r(array_map(fn ($l) => $l[2]['@module'] ?? '', $logs), TRUE));
    $this->assertSame('sentinel_status_report_test', $logs[0][2]['@module']);

    $payload = $this->sync();
    $this->assertSame('reported', $payload['status_report']['status']);
  }

  /**
   * A build over the time budget sends unavailable and keeps the snapshot.
   */
  public function testTimeoutSendsUnavailable(): void {
    $state = $this->container->get('state');
    $snapshot = ['checked_at' => self::START - 2 * 86400, 'items' => []];
    $state->set(StatusReportCollector::STATE_SNAPSHOT, $snapshot);
    $state->set('sentinel_status_report_test.advance', 25);
    $block = $this->collector()->collect();
    $this->assertSame('unavailable', $block['status']);
    $this->assertSame('timeout', $block['reason']);
    $this->assertArrayNotHasKey('items', $block);
    $this->assertSame($snapshot, $state->get(StatusReportCollector::STATE_SNAPSHOT));
    $this->assertSchemaBlock($block);

    // A web snapshot over the budget is not stored either.
    $this->assertTrue($this->collector()->refreshSnapshot());
    $this->assertSame($snapshot, $state->get(StatusReportCollector::STATE_SNAPSHOT));

    $payload = $this->sync();
    $this->assertSame('unavailable', $payload['status_report']['status']);
    $this->assertSame('timeout', $payload['status_report']['reason']);
  }

  /**
   * After a response, a web snapshot is built at most once in 12 hours.
   */
  public function testWebSnapshotAfterResponse(): void {
    $this->setItems(['snapshot_item' => ['title' => 'Snapshot', 'severity' => 1]]);
    $state = $this->container->get('state');

    // A page cache hit builds nothing.
    $this->terminate('HIT');
    $this->assertSame(0, $this->calls());
    $this->assertNull($state->get(StatusReportCollector::STATE_SNAPSHOT));

    $this->terminate();
    $this->assertSame(1, $this->calls());
    /** @var array{checked_at: int, items: list<array<string, string>>} $snapshot */
    $snapshot = $state->get(StatusReportCollector::STATE_SNAPSHOT);
    $this->assertSame(self::START, $snapshot['checked_at']);
    $this->assertSame('snapshot_item', $this->byKey(['items' => $snapshot['items']])['snapshot_item']['key']);

    // The next request in 12 hours does not build it again.
    $this->clock()->advance(43199);
    $this->terminate();
    $this->assertSame(1, $this->calls());
    $this->clock()->advance(1);
    $this->terminate();
    $this->assertSame(2, $this->calls());

    // A request that does not get the lock leaves the build to the holder.
    $this->clock()->advance(43200);
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire(StatusReportCollector::LOCK));
    $this->terminate();
    $this->assertSame(2, $this->calls());
    $other->release(StatusReportCollector::LOCK);
    $this->terminate();
    $this->assertSame(3, $this->calls());

    // The sync sends the snapshot without building the list.
    $payload = $this->sync();
    $this->assertSame(3, $this->calls());
    $this->assertSame('web', $payload['status_report']['context']);
    $this->assertSame([], $payload['status_report']['left_out']);
    $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', (int) $this->clock()->now), $payload['status_report']['checked_at']);
  }

  /**
   * Without a web snapshot from the last day, the sync builds from the CLI.
   */
  public function testCommandLineFallback(): void {
    $this->setItems([
      'php_opcache' => ['title' => 'PHP OPcode caching', 'value' => 'Not enabled', 'severity' => 1],
      'cli_warning' => ['title' => 'Real warning', 'severity' => 1],
    ]);
    $state = $this->container->get('state');
    $payload = $this->sync();
    $block = $payload['status_report'];
    $this->assertSame('cli', $block['context']);
    $this->assertSame(StatusReportCollector::CLI_LEFT_OUT, $block['left_out']);
    $items = $this->byKey($block);
    $this->assertArrayHasKey('cli_warning', $items);
    $this->assertArrayNotHasKey('php_opcache', $items);
    // The command line build is not stored as a web snapshot.
    $this->assertNull($state->get(StatusReportCollector::STATE_SNAPSHOT));
    $this->assertSchemaBlock($block);

    // A snapshot from under a day ago is sent instead.
    $state->set(StatusReportCollector::STATE_SNAPSHOT, ['checked_at' => self::START - 86399, 'items' => []]);
    $block = $this->collector()->collect();
    $this->assertSame('web', $block['context']);
    $this->assertSame([], $block['items']);
    $state->set(StatusReportCollector::STATE_SNAPSHOT, ['checked_at' => self::START - 86400, 'items' => []]);
    $this->assertSame('cli', $this->collector()->collect()['context']);
  }

  /**
   * With the setting off, the payload says so and has no block.
   */
  public function testSettingOff(): void {
    $this->config('sentinel_connector.settings')->set('report_status_report', FALSE)->save();
    $payload = $this->sync();
    $this->assertSame('disabled', $payload['status_reporting']);
    $this->assertArrayNotHasKey('status_report', $payload);
    $this->assertSame(0, $this->calls());
    $this->assertNull($this->collector()->collect());

    // No snapshot is built either.
    $this->terminate();
    $this->assertSame(0, $this->calls());

    // settings.php wins over the configuration.
    $this->setSetting(StatusReportCollector::SETTINGS_KEY, TRUE);
    $this->assertTrue($this->collector()->isReportingEnabled());
    $this->assertTrue($this->collector()->isReportingOverridden());
    $this->config('sentinel_connector.settings')->set('report_status_report', TRUE)->save();
    $this->setSetting(StatusReportCollector::SETTINGS_KEY, FALSE);
    $this->assertFalse($this->collector()->isReportingEnabled());

    // On by default.
    $this->setSetting(StatusReportCollector::SETTINGS_KEY, NULL);
    $this->config('sentinel_connector.settings')->clear('report_status_report')->save();
    $this->assertTrue($this->collector()->isReportingEnabled());
    $this->assertSame('enabled', $this->sync()['status_reporting']);
  }

  /**
   * The check time alone does not push again; a changed item does.
   */
  public function testCheckedAtDoesNotChangeTheHash(): void {
    $state = $this->container->get('state');
    $items = [[
      'key' => 'cron',
      'severity' => 'warning',
      'title' => 'Cron maintenance tasks',
      'value' => 'Last run 3 days ago',
      'description' => '',
      'provider' => 'system',
    ],
    ];
    $state->set(StatusReportCollector::STATE_SNAPSHOT, ['checked_at' => self::START, 'items' => $items]);
    $this->sync();
    $this->assertFalse($this->syncService()->hasInventoryChanged());

    // A newer snapshot with the same items.
    $this->clock()->advance(3600);
    $state->set(StatusReportCollector::STATE_SNAPSHOT, ['checked_at' => self::START + 3600, 'items' => $items]);
    $this->assertFalse($this->syncService()->hasInventoryChanged());

    // A changed value.
    $items[0]['value'] = 'Last run 4 days ago';
    $state->set(StatusReportCollector::STATE_SNAPSHOT, ['checked_at' => self::START + 3600, 'items' => $items]);
    $this->assertTrue($this->syncService()->hasInventoryChanged());
  }

  /**
   * The update hook turns reporting on for sites updated from 1.5.x.
   */
  public function testUpdateHookTurnsReportingOn(): void {
    $this->config('sentinel_connector.settings')->clear('report_status_report')->save();
    \Drupal::moduleHandler()->loadInclude('sentinel_connector', 'install');
    sentinel_connector_update_10007();
    $this->assertTrue($this->config('sentinel_connector.settings')->get('report_status_report'));
    // A site that turned it off keeps it off.
    $this->config('sentinel_connector.settings')->set('report_status_report', FALSE)->save();
    sentinel_connector_update_10007();
    $this->assertFalse($this->config('sentinel_connector.settings')->get('report_status_report'));
  }

  /**
   * Stores the requirements the test module returns.
   *
   * @param array<string, array<string, mixed>> $items
   *   The requirements.
   */
  protected function setItems(array $items): void {
    $this->container->get('state')->set('sentinel_status_report_test.items', $items);
  }

  /**
   * How often the test module's requirements hook ran.
   */
  protected function calls(): int {
    $state = $this->container->get('state');
    $state->resetCache();
    return (int) $state->get('sentinel_status_report_test.calls', 0);
  }

  /**
   * The collector, recreated so that it reads the current settings.
   */
  protected function collector(): StatusReportCollector {
    $this->container->set(StatusReportCollector::class, NULL);
    return $this->container->get(StatusReportCollector::class);
  }

  /**
   * The sync service, recreated with a fresh payload builder.
   */
  protected function syncService(): SyncService {
    $ids = [
      SyncService::class,
      'Drupal\sentinel_connector\PayloadBuilder',
      'sentinel_connector.payload_builder_factory',
      StatusReportCollector::class,
    ];
    foreach ($ids as $id) {
      $this->container->set($id, NULL);
    }
    return $this->container->get(SyncService::class);
  }

  /**
   * The test clock.
   */
  protected function clock(): StatusReportTestTime {
    /** @var \Drupal\Tests\sentinel_connector\Kernel\StatusReportTestTime $clock */
    $clock = $this->container->get('datetime.time');
    return $clock;
  }

  /**
   * The connector's logger.
   */
  protected function logger(): BufferLogger {
    /** @var \Drupal\Tests\sentinel_connector\Kernel\BufferLogger $logger */
    $logger = $this->container->get('logger.channel.sentinel_connector');
    return $logger;
  }

  /**
   * Ends a request as the kernel does after the response is sent.
   */
  protected function terminate(?string $pageCache = NULL): void {
    $response = new Response('ok');
    if ($pageCache !== NULL) {
      $response->headers->set('X-Drupal-Cache', $pageCache);
    }
    $this->container->set(StatusReportCollector::class, NULL);
    $this->container->set('Drupal\sentinel_connector\EventSubscriber\StatusReportSubscriber', NULL);
    $subscriber = $this->container->get('Drupal\sentinel_connector\EventSubscriber\StatusReportSubscriber');
    $this->assertArrayHasKey(KernelEvents::TERMINATE, $subscriber::getSubscribedEvents());
    $subscriber->onTerminate(new TerminateEvent($this->container->get('http_kernel'), Request::create('/'), $response));
  }

  /**
   * Runs a sync against a mocked Sentinel and returns the payload sent.
   *
   * @return array<string, mixed>
   *   The payload.
   */
  protected function sync(): array {
    $this->transactions = [];
    $stack = HandlerStack::create(new MockHandler([new GuzzleResponse(200, [], '{"message": "ok"}')]));
    $stack->push(Middleware::history($this->transactions));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $this->container->set('Drupal\sentinel_connector\SentinelClient', NULL);
    $result = $this->syncService()->sync(SyncService::TRIGGER_DRUSH);
    $this->assertTrue($result->isOk(), $result->message);
    $this->assertCount(1, $this->transactions);
    $payload = json_decode((string) $this->transactions[0]['request']->getBody(), TRUE);
    $this->assertSame(5, $payload['schema_version']);
    if (class_exists(Validator::class)) {
      $validator = new Validator();
      $data = json_decode(json_encode($payload));
      $validator->validate($data, (object) ['$ref' => 'file://' . dirname(__DIR__, 3) . '/tests/fixtures/drupal_sync.schema.json']);
      $this->assertSame([], array_map(fn ($e) => $e['property'] . ': ' . $e['message'], $validator->getErrors()));
    }
    return $payload;
  }

  /**
   * Validates a block against the shared schema.
   *
   * @param array<string, mixed> $block
   *   The status_report block.
   */
  protected function assertSchemaBlock(array $block): void {
    if (!class_exists(Validator::class)) {
      return;
    }
    $payload = [
      'site' => ['uuid' => '11111111-1111-4111-8111-111111111111'],
      'drupal_info' => [
        'core_version' => '11.2.5',
        'php_version' => '8.4.12',
        'ip_address' => '203.0.113.4',
      ],
      'modules' => [],
      'full_sync' => TRUE,
      'schema_version' => 5,
      'status_reporting' => 'enabled',
      'status_report' => $block,
    ];
    $validator = new Validator();
    $data = json_decode(json_encode($payload));
    $validator->validate($data, (object) ['$ref' => 'file://' . dirname(__DIR__, 3) . '/tests/fixtures/drupal_sync.schema.json']);
    $this->assertSame([], array_map(fn ($e) => $e['property'] . ': ' . $e['message'], $validator->getErrors()));
  }

  /**
   * The items of a block keyed by key.
   *
   * @param array<string, mixed> $block
   *   The block.
   *
   * @return array<string, array<string, string>>
   *   The items.
   */
  protected function byKey(array $block): array {
    $items = [];
    foreach ($block['items'] ?? [] as $item) {
      $items[$item['key']] = $item;
    }
    return $items;
  }

}
