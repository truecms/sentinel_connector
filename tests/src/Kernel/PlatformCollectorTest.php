<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\EventSubscriber\WebServerSubscriber;
use Drupal\sentinel_connector\PlatformCollector;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as HttpResponse;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Kernel tests for the platform block of the sync payload.
 *
 * @group sentinel_connector
 */
#[RunTestsInSeparateProcesses]
#[Group('sentinel_connector')]
class PlatformCollectorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['sentinel_connector'];

  /**
   * The os-release fixtures.
   */
  protected string $fixtures;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fixtures = dirname(__DIR__, 2) . '/fixtures/os-release';
  }

  /**
   * Builds a collector on the container's services.
   *
   * @param string[] $osReleaseFiles
   *   The os-release files to read.
   * @param \Drupal\Core\Database\Connection|null $database
   *   A database connection, the default one when NULL.
   */
  protected function collector(array $osReleaseFiles = [], ?Connection $database = NULL): PlatformCollector {
    return new PlatformCollector(
      $this->container->get('state'),
      $this->container->get('request_stack'),
      $database ?? $this->container->get('database'),
      $this->container->get('datetime.time'),
      $osReleaseFiles,
    );
  }

  /**
   * Makes the current request one served by the given web server.
   */
  protected function pushRequest(?string $software): Request {
    $server = $software === NULL ? [] : ['SERVER_SOFTWARE' => $software];
    $request = Request::create('/', 'GET', [], [], [], $server);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    return $request;
  }

  /**
   * PHP is always known.
   */
  public function testPhp(): void {
    $this->assertSame(['version' => PHP_VERSION, 'sapi' => PHP_SAPI], $this->collector()->php());
  }

  /**
   * A web request's server software is parsed and kept for later syncs.
   */
  public function testWebServerFromRequest(): void {
    $this->pushRequest('Apache/2.4.57 (Debian)');
    $this->assertSame([
      'software' => 'Apache',
      'version' => '2.4.57',
      'raw' => 'Apache/2.4.57 (Debian)',
    ], $this->collector()->webServer());
    $this->assertSame('Apache/2.4.57 (Debian)', $this->storedWebServer());
  }

  /**
   * Values are cut to Sentinel's limits; invalid UTF-8 is left out.
   */
  public function testWebServerLimits(): void {
    $this->pushRequest('nginx/' . str_repeat('1', 60) . ' ' . str_repeat('x', 300));
    $webServer = $this->collector()->webServer();
    $this->assertSame('nginx', $webServer['software']);
    $this->assertSame(PlatformCollector::TEXT_LIMIT, strlen($webServer['version']));
    $this->assertSame(PlatformCollector::RAW_LIMIT, strlen($webServer['raw']));

    $this->container->get('state')->delete(PlatformCollector::STATE_WEB_SERVER);
    $this->pushRequest("nginx/1.24.0\xff");
    $this->assertSame([], $this->collector()->webServer());
  }

  /**
   * A stored value is replaced at most once an hour.
   */
  public function testWebServerReplacedAtMostHourly(): void {
    $state = $this->container->get('state');
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $state->set(PlatformCollector::STATE_WEB_SERVER, ['raw' => 'nginx/1.24.0', 'time' => $now - 60]);
    $this->collector()->rememberWebServer(Request::create('/', 'GET', [], [], [], ['SERVER_SOFTWARE' => 'nginx/1.26.0']));
    $this->assertSame('nginx/1.24.0', $this->storedWebServer());

    $state->set(PlatformCollector::STATE_WEB_SERVER, [
      'raw' => 'nginx/1.24.0',
      'time' => $now - PlatformCollector::WEB_SERVER_MIN_AGE,
    ]);
    $this->collector()->rememberWebServer(Request::create('/', 'GET', [], [], [], ['SERVER_SOFTWARE' => 'nginx/1.26.0']));
    $this->assertSame('nginx/1.26.0', $this->storedWebServer());
  }

  /**
   * The stored web server value.
   */
  protected function storedWebServer(): ?string {
    return $this->container->get('state')->get(PlatformCollector::STATE_WEB_SERVER)['raw'] ?? NULL;
  }

  /**
   * A hidden version gives the software only.
   */
  public function testWebServerHiddenVersion(): void {
    $this->pushRequest('nginx');
    $this->assertSame(['software' => 'nginx', 'raw' => 'nginx'], $this->collector()->webServer());
  }

  /**
   * Cron and Drush send the value of the last web request.
   */
  public function testWebServerFromState(): void {
    $this->container->get('state')->set(PlatformCollector::STATE_WEB_SERVER, ['raw' => 'nginx/1.24.0', 'time' => 1]);
    $this->pushRequest(NULL);
    $this->assertSame([
      'software' => 'nginx',
      'version' => '1.24.0',
      'raw' => 'nginx/1.24.0',
    ], $this->collector()->webServer());
  }

  /**
   * Without a web request ever seen the web server is left out.
   */
  public function testWebServerMissing(): void {
    $this->pushRequest(NULL);
    $this->assertSame([], $this->collector()->webServer());
    $this->pushRequest('  ');
    $this->assertSame([], $this->collector()->webServer());
    $this->assertNull($this->storedWebServer());
  }

  /**
   * Every web request records its server software after the response.
   */
  public function testSubscriberRemembersWebServer(): void {
    $request = Request::create('/', 'GET', [], [], [], ['SERVER_SOFTWARE' => 'nginx/1.26.2']);
    $event = new TerminateEvent($this->container->get('http_kernel'), $request, new Response());
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::TERMINATE);
    $this->assertSame('nginx/1.26.2', $this->storedWebServer());

    // A request without one, such as Drush, keeps the stored value.
    $subscriber = $this->container->get(WebServerSubscriber::class);
    $subscriber->onTerminate(new TerminateEvent($this->container->get('http_kernel'), Request::create('/'), new Response()));
    $this->assertSame('nginx/1.26.2', $this->storedWebServer());

    // A page cache hit does not touch state.
    $this->container->get('state')->delete(PlatformCollector::STATE_WEB_SERVER);
    $request = Request::create('/', 'GET', [], [], [], ['SERVER_SOFTWARE' => 'nginx/1.26.2']);
    $subscriber->onTerminate(new TerminateEvent($this->container->get('http_kernel'), $request, new Response('', 200, ['X-Drupal-Cache' => 'HIT'])));
    $this->assertNull($this->storedWebServer());
  }

  /**
   * The test database reports its driver and version.
   */
  public function testDatabasePresent(): void {
    $database = $this->collector()->database();
    $connection = $this->container->get('database');
    $this->assertSame($connection->driver(), $database['driver']);
    $this->assertSame(mb_substr(trim((string) $connection->version()), 0, PlatformCollector::TEXT_LIMIT), $database['version']);
  }

  /**
   * MariaDB is recognised from the version string.
   */
  public function testDatabaseMariaDb(): void {
    $connection = $this->createStub(Connection::class);
    $connection->method('driver')->willReturn('mysql');
    $connection->method('version')->willReturn('10.11.6-MariaDB-1:10.11.6+maria~deb12');
    $this->assertSame([
      'driver' => 'mysql',
      'product' => 'mariadb',
      'version' => '10.11.6-MariaDB-1:10.11.6+maria~deb12',
    ], $this->collector([], $connection)->database());

    $connection = $this->createStub(Connection::class);
    $connection->method('driver')->willReturn('pgsql');
    $connection->method('version')->willReturn('16.4');
    $this->assertSame([
      'driver' => 'pgsql',
      'product' => 'postgresql',
      'version' => '16.4',
    ], $this->collector([], $connection)->database());
  }

  /**
   * A database that cannot answer is left out instead of failing.
   */
  public function testDatabaseMissing(): void {
    $connection = $this->createStub(Connection::class);
    $connection->method('driver')->willThrowException(new \RuntimeException('gone'));
    $connection->method('version')->willThrowException(new \RuntimeException('gone'));
    $this->assertSame([], $this->collector([], $connection)->database());

    $connection = $this->createStub(Connection::class);
    $connection->method('driver')->willReturn('mysql');
    $connection->method('version')->willThrowException(new \RuntimeException('gone'));
    $this->assertSame(['driver' => 'mysql', 'product' => 'mysql'], $this->collector([], $connection)->database());
  }

  /**
   * The distribution and release come from the first readable os-release.
   */
  public function testOsPresent(): void {
    $os = $this->collector([$this->fixtures . '/missing', $this->fixtures . '/debian'])->os();
    $this->assertSame(['family' => PHP_OS_FAMILY, 'distribution' => 'debian', 'release' => '12'], $os);

    // A rolling distribution has no release.
    $os = $this->collector([$this->fixtures . '/arch'])->os();
    $this->assertSame(['family' => PHP_OS_FAMILY, 'distribution' => 'arch'], $os);
  }

  /**
   * Without a readable os-release only the family is sent.
   */
  public function testOsMissing(): void {
    $this->assertSame(['family' => PHP_OS_FAMILY], $this->collector([$this->fixtures . '/missing'])->os());
  }

  /**
   * Parts that cannot be read are left out of the block.
   */
  public function testCollectLeavesOutUnknownParts(): void {
    $connection = $this->createStub(Connection::class);
    $connection->method('driver')->willThrowException(new \RuntimeException('gone'));
    $connection->method('version')->willThrowException(new \RuntimeException('gone'));
    $this->pushRequest(NULL);
    $platform = $this->collector([], $connection)->collect();
    $this->assertSame(['php', 'os'], array_keys($platform));
  }

  /**
   * A sync from Drush after a web request sends the cached web server.
   */
  public function testSyncSendsPlatform(): void {
    $this->config('sentinel_connector.settings')
      ->set('api_base_url', 'https://sentinel.example.com')
      ->set('site_uuid', '11111111-1111-4111-8111-111111111111')
      ->set('site_url', 'https://example.com')
      ->set('site_name', 'Example')
      ->set('report_scope', 'all')
      ->set('enabled', TRUE)
      ->save();
    $this->container->get('state')->set('sentinel_connector.api_key', 'sk_test');
    $this->container->get(PlatformCollector::class)->rememberWebServer(Request::create('/', 'GET', [], [], [], ['SERVER_SOFTWARE' => 'nginx/1.24.0']));
    $this->pushRequest(NULL);

    $transactions = [];
    $stack = HandlerStack::create(new MockHandler([new HttpResponse(200, [], '{"message":"ok"}')]));
    $stack->push(Middleware::history($transactions));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $result = $this->container->get('Drupal\sentinel_connector\SyncService')->sync();
    $this->assertTrue($result->isOk());
    $body = json_decode((string) $transactions[0]['request']->getBody(), TRUE);
    $this->assertSame(3, $body['schema_version']);
    $this->assertSame(['version' => PHP_VERSION, 'sapi' => PHP_SAPI], $body['platform']['php']);
    $this->assertSame('nginx/1.24.0', $body['platform']['web_server']['raw']);
    $this->assertSame($this->container->get('database')->driver(), $body['platform']['database']['driver']);
  }

}
