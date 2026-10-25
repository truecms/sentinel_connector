<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers HTTP requests from a fixture file and records them.
 *
 * Each fixture entry has 'method', 'path' (decoded), an optional 'query'
 * that the request must contain, an optional 'after' count of earlier
 * matches of the same route, 'status' and 'body'.
 */
class ReplayHttpClient implements ClientInterface {

  /**
   * The client used by the next discovery, set by the test.
   *
   * @var \Drupal\Tests\sentinel_connector_config_pr\Support\ReplayHttpClient|null
   */
  public static ?self $current = NULL;

  /**
   * The requests received, in order.
   *
   * @var \Psr\Http\Message\RequestInterface[]
   */
  public array $requests = [];

  /**
   * Constructs the client.
   *
   * @param array<int, array<string, mixed>> $fixtures
   *   The fixture entries.
   * @param string $prefix
   *   The API path prefix of the host, such as /api/v3, left out of the
   *   fixture paths.
   */
  public function __construct(
    protected array $fixtures,
    protected string $prefix = '',
  ) {}

  /**
   * Loads a fixture file.
   */
  public static function fromFile(string $path, string $prefix = ''): self {
    return new self(static::read($path), $prefix);
  }

  /**
   * Reads the entries of a fixture file.
   *
   * @return array<int, array<string, mixed>>
   *   The fixture entries.
   */
  public static function read(string $path): array {
    return json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * {@inheritdoc}
   */
  public function sendRequest(RequestInterface $request): ResponseInterface {
    $this->requests[] = $request;
    $path = rawurldecode($request->getUri()->getPath());
    if ($this->prefix !== '' && str_starts_with($path, $this->prefix)) {
      $path = substr($path, strlen($this->prefix));
    }
    parse_str($request->getUri()->getQuery(), $query);
    foreach ($this->fixtures as $fixture) {
      if ($fixture['method'] !== $request->getMethod() || $fixture['path'] !== $path) {
        continue;
      }
      if (array_diff_assoc($fixture['query'] ?? [], $query) !== []) {
        continue;
      }
      $body = $fixture['body'] ?? NULL;
      return new Response($fixture['status'] ?? 200, ['Content-Type' => 'application/json'], $body === NULL ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    return new Response(599, ['Content-Type' => 'application/json'], json_encode(['message' => 'No fixture for ' . $request->getMethod() . ' ' . $path]));
  }

  /**
   * The requests as "METHOD host path" lines.
   *
   * @return string[]
   *   The lines.
   */
  public function lines(): array {
    return array_map(static fn (RequestInterface $request): string => $request->getMethod() . ' ' . $request->getUri()->getHost() . rawurldecode($request->getUri()->getPath()), $this->requests);
  }

}
