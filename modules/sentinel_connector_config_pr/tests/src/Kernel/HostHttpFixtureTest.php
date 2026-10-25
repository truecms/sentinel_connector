<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Kernel;

use Psr\Http\Message\RequestInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector_config_pr\ConfigPrRunner;
use Drupal\sentinel_connector_config_pr\PullRequestTracker;
use Drupal\Tests\sentinel_connector_config_pr\Support\ReplayDiscoveryStrategy;
use Drupal\Tests\sentinel_connector_config_pr\Support\ReplayHttpClient;
use Drupal\Tests\sentinel_connector_config_pr\Support\TestLogger;
use Http\Discovery\ClassDiscovery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs the config_pr GitHub and GitLab controllers against HTTP fixtures.
 *
 * The fixtures follow the response shapes of the GitHub REST API and the
 * GitLab REST API v4. php-http discovery hands the replay client to both API
 * clients, so every request the controllers make is answered and recorded.
 *
 * @group sentinel_connector
 */
#[RunTestsInSeparateProcesses]
class HostHttpFixtureTest extends KernelTestBase {

  /**
   * The token, which must reach the Git host only.
   */
  protected const TOKEN = 'fixture_token_0123456789abcdef';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'config',
    'sentinel_connector',
    'config_pr',
    'config_pr_github',
    'config_pr_gitlab',
    'sentinel_connector_config_pr',
  ];

  /**
   * The log channel.
   */
  protected TestLogger $logger;

  /**
   * The hosts and API prefixes for each provider and hosting.
   *
   * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
   *   Provider, base URL setting, expected host, API path prefix.
   */
  public static function hosts(): array {
    return [
      'github.com' => ['github', '', 'api.github.com', ''],
      'GitHub Enterprise' => ['github', 'https://github.example.com', 'github.example.com', '/api/v3'],
      'gitlab.com' => ['gitlab', '', 'gitlab.com', '/api/v4'],
      'self-managed GitLab' => ['gitlab', 'https://gitlab.example.com', 'gitlab.example.com', '/api/v4'],
    ];
  }

  /**
   * Sets up the site for one provider.
   */
  protected function configure(string $provider, string $baseUrl): void {
    $this->installConfig(['system', 'sentinel_connector', 'sentinel_connector_config_pr']);
    $this->config('system.site')->set('name', 'Example')->set('mail', 'site@example.com')->save();
    $this->config('sentinel_connector_config_pr.settings')
      ->set('provider', $provider)
      ->set('api_base_url', $baseUrl)
      ->set('repo_owner', 'acme')
      ->set('repo_name', 'site')
      ->save();
    $this->copyConfig($this->container->get('config.storage.export'), $this->container->get('config.storage.sync'));
    $this->config('system.site')->set('slogan', 'Drifted')->save();

    $this->logger = new TestLogger();
    $this->container->set('logger.channel.sentinel_connector', $this->logger);
    $this->setSetting('sentinel_connector_config_pr', ['token' => self::TOKEN]);
    ClassDiscovery::prependStrategy(ReplayDiscoveryStrategy::class);
  }

  /**
   * A first run creates the branch, writes the file and opens a pull request.
   *
   * @dataProvider hosts
   */
  public function testCreate(string $provider, string $baseUrl, string $host, string $prefix): void {
    $this->configure($provider, $baseUrl);
    $client = ReplayHttpClient::$current = ReplayHttpClient::fromFile(__DIR__ . "/../../fixtures/{$provider}_create.json", $prefix);

    $result = $this->container->get(ConfigPrRunner::class)->run(FALSE, TRUE);

    $this->assertSame('created', $result->status, $result->message . "\n" . $this->logger->text() . "\n" . implode("\n", $client->lines()));
    $this->assertSame(1, $result->files);
    $this->assertSame($provider === 'github' ? 'https://github.example.com/acme/site/pull/7' : 'https://gitlab.example.com/acme/site/-/merge_requests/3', $result->url);
    $this->assertRequests($client, $host);

    $writes = array_values(array_filter($client->lines(), static fn (string $line): bool => !str_starts_with($line, 'GET') && !str_starts_with($line, 'HEAD')));
    $files = $provider === 'github' ? '/repos/acme/site' : '/projects/42';
    $this->assertSame([
      'POST ' . $host . $prefix . $files . ($provider === 'github' ? '/git/refs' : '/repository/branches'),
      'PUT ' . $host . $prefix . $files . ($provider === 'github' ? '/contents/' : '/repository/files/') . 'config/sync/system.site.yml',
      'POST ' . $host . $prefix . $files . ($provider === 'github' ? '/pulls' : '/merge_requests'),
    ], $writes);

    // The file holds the exported YAML.
    $put = $this->request($client, 'PUT');
    $body = json_decode((string) $put->getBody(), TRUE);
    $this->assertStringContainsString('slogan: Drifted', base64_decode($body['content']));
    $this->assertSame('sentinel/config-export', $body['branch']);
  }

  /**
   * A run with an open pull request writes the change and opens nothing new.
   *
   * @dataProvider hosts
   */
  public function testUpdate(string $provider, string $baseUrl, string $host, string $prefix): void {
    $this->configure($provider, $baseUrl);
    $this->container->get(PullRequestTracker::class)->setOwnedBranch('sentinel/config-export');
    $client = ReplayHttpClient::$current = ReplayHttpClient::fromFile(__DIR__ . "/../../fixtures/{$provider}_update.json", $prefix);

    $result = $this->container->get(ConfigPrRunner::class)->run(FALSE, TRUE);

    $this->assertSame('updated', $result->status, $result->message . "\n" . $this->logger->text() . "\n" . implode("\n", $client->lines()));
    $this->assertSame(1, $result->files);
    $this->assertRequests($client, $host);
    $methods = array_map(static fn ($request): string => $request->getMethod(), $client->requests);
    $this->assertNotContains('POST', $methods);
    $this->assertNotContains('DELETE', $methods);
    $this->assertCount(1, array_keys($methods, 'PUT', TRUE));
  }

  /**
   * A deleted object deletes its file on the export branch.
   *
   * @dataProvider hosts
   */
  public function testDelete(string $provider, string $baseUrl, string $host, string $prefix): void {
    $this->configure($provider, $baseUrl);
    $this->container->get(PullRequestTracker::class)->setOwnedBranch('sentinel/config-export');
    // The code still has an object the site no longer has.
    $this->container->get('config.storage.sync')->write('system.removed', ['a' => 1]);
    $fixtures = __DIR__ . '/../../fixtures/';
    $client = ReplayHttpClient::$current = new ReplayHttpClient(array_merge(
      ReplayHttpClient::read($fixtures . "{$provider}_update.json"),
      ReplayHttpClient::read($fixtures . "{$provider}_delete.json"),
    ), $prefix);

    $result = $this->container->get(ConfigPrRunner::class)->run(FALSE, TRUE);

    $this->assertSame('updated', $result->status, $result->message . "\n" . $this->logger->text() . "\n" . implode("\n", $client->lines()));
    $this->assertSame(2, $result->files);
    $this->assertRequests($client, $host);
    $delete = $this->request($client, 'DELETE');
    $this->assertStringEndsWith('config/sync/system.removed.yml', rawurldecode($delete->getUri()->getPath()));
  }

  /**
   * An export branch this module did not create is never touched.
   *
   * @dataProvider hosts
   */
  public function testForeignBranchIsRefused(string $provider, string $baseUrl, string $host, string $prefix): void {
    $this->configure($provider, $baseUrl);
    $client = ReplayHttpClient::$current = ReplayHttpClient::fromFile(__DIR__ . "/../../fixtures/{$provider}_update.json", $prefix);

    $result = $this->container->get(ConfigPrRunner::class)->run(FALSE, TRUE);

    $this->assertSame('failed', $result->status);
    $this->assertStringContainsString('was not created by this module', $this->logger->text());
    $methods = array_map(static fn ($request): string => $request->getMethod(), $client->requests);
    $this->assertSame([], array_diff($methods, ['GET', 'HEAD']));
  }

  /**
   * Every request went to the expected host with the token; no log has it.
   */
  protected function assertRequests(ReplayHttpClient $client, string $host): void {
    $this->assertNotEmpty($client->requests);
    foreach ($client->requests as $request) {
      $this->assertSame($host, $request->getUri()->getHost());
      $this->assertSame('https', $request->getUri()->getScheme());
      $headers = implode(' ', array_merge(...array_values($request->getHeaders())));
      $this->assertStringContainsString(self::TOKEN, $headers);
    }
    $this->assertStringNotContainsString(self::TOKEN, $this->logger->text());
  }

  /**
   * The first request with a method.
   */
  protected function request(ReplayHttpClient $client, string $method): RequestInterface {
    foreach ($client->requests as $request) {
      if ($request->getMethod() === $method) {
        return $request;
      }
    }
    $this->fail("No $method request.");
  }

}
