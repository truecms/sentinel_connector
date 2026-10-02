<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\sentinel_connector\SentinelClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests response typing and safe transport diagnostics.
 *
 * @group sentinel_connector
 */
class SentinelClientTest extends TestCase {

  /**
   * Structured authentication errors remain typed failures.
   */
  public function testStructuredAuthenticationErrors(): void {
    foreach ([401, 403] as $code) {
      $client = $this->client(new Response($code, [], json_encode(['detail' => ['reason' => 'invalid key']])));
      $result = $client->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      $this->assertSame('auth_error', $result->status);
      $this->assertSame($code, $result->httpCode);
      $this->assertStringContainsString('invalid key', $result->message);
    }
  }

  /**
   * Non-JSON proxy failures retain actionable HTTP classifications.
   */
  public function testNonJsonErrorsPreserveHttpClassification(): void {
    foreach ([401, 403, 500, 502, 503] as $code) {
      $result = $this->client(new Response($code, [], '<html>SECRET proxy failure</html>'))
        ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
      $this->assertSame($code < 500 ? 'auth_error' : 'server_error', $result->status);
      $this->assertSame($code, $result->httpCode);
      $this->assertStringNotContainsString('SECRET', $result->message);
      $this->assertFalse($result->isOk());
    }
  }

  /**
   * A malformed JSON success must not masquerade as a successful sync.
   */
  public function testMalformedJsonIsRejected(): void {
    $result = $this->client(new Response(200, [], '<html>proxy error</html>'))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('invalid_response', $result->status);
    $this->assertFalse($result->isOk());
  }

  /**
   * A queued response without an actual task ID is not usable acceptance.
   */
  public function testInvalidTaskIdIsRejected(): void {
    $result = $this->client(new Response(202, [], '{"task_id":[]}'))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('invalid_response', $result->status);
  }

  /**
   * The actual server retry interval is shown to administrators.
   */
  public function testRetryAfterIsShown(): void {
    $result = $this->client(new Response(429, ['Retry-After' => '3600']))
      ->sync('https://sentinel.example.com', 'uuid', 'SECRET', []);
    $this->assertSame('rate_limited', $result->status);
    $this->assertStringContainsString('Retry after 3600 seconds', $result->message);
  }

  /**
   * Diagnostics distinguish transport failures without logging secrets or URLs.
   */
  public function testTransportLogsOnlySafeStructuredDiagnostics(): void {
    $exception = new ConnectException('https://user:SECRET@sentinel.example.com/path?token=SECRET', new Request('POST', 'https://sentinel.example.com'), NULL, ['errno' => 7]);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error')->with(
      $this->callback(fn ($message) => !str_contains($message, 'SECRET')),
      $this->callback(fn ($context) => $context['@host'] === 'sentinel.example.com' && $context['@errno'] === 7 && !str_contains(json_encode($context), 'SECRET')),
    );
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([$exception]))]);
    $result = (new SentinelClient($http, $logger))->sync('https://user:SECRET@sentinel.example.com?token=SECRET', 'uuid', 'SECRET', []);
    $this->assertSame('transport_error', $result->status);
    $this->assertStringNotContainsString('SECRET', $result->message);
  }

  /**
   * Builds a client whose actual Guzzle stack returns the given response.
   */
  private function client(Response $response): SentinelClient {
    return new SentinelClient(new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]), new NullLogger());
  }

}
