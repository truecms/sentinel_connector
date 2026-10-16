<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\sentinel_connector\PayloadBuilder;
use Drupal\sentinel_connector\SentinelClient;
use Drupal\sentinel_connector\SiteUuid;
use Drupal\sentinel_connector\SyncResult;
use Drupal\sentinel_connector\SyncService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Tests that the Site UUID is sent in the one form Sentinel accepts.
 *
 * @group sentinel_connector
 */
class SiteUuidTest extends TestCase {

  /**
   * The canonical form of the UUID used in the tests.
   */
  private const UUID = '123e4567-e89b-42d3-a456-426614174000';

  /**
   * The requests sent to Sentinel.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $transactions = [];

  /**
   * Every log entry written, as level and context.
   *
   * @var array<int, array{string, array<string, mixed>}>
   */
  private array $logs = [];

  /**
   * The values written to state.
   *
   * @var array<string, mixed>
   */
  private array $stateValues = [];

  /**
   * A UUID is brought into lower case with hyphens, or refused.
   *
   * @dataProvider notations
   */
  public function testNormalize(string $value, ?string $expected): void {
    $this->assertSame($expected, SiteUuid::normalize($value));
  }

  /**
   * Notations of a UUID, and values that are none.
   *
   * @return array<string, array{string, string|null}>
   *   The value and its canonical form, or NULL when it is not a UUID.
   */
  public static function notations(): array {
    return [
      'canonical' => [self::UUID, self::UUID],
      'padded' => ["\t " . self::UUID . " \n", self::UUID],
      'upper case' => [strtoupper(self::UUID), self::UUID],
      'braced' => ['{' . self::UUID . '}', self::UUID],
      'braced with spaces' => [' { ' . strtoupper(self::UUID) . ' } ', self::UUID],
      'unhyphenated' => [str_replace('-', '', self::UUID), self::UUID],
      'unhyphenated, upper case, braced' => ['{' . strtoupper(str_replace('-', '', self::UUID)) . '}', self::UUID],
      'empty' => ['', NULL],
      'spaces' => ['   ', NULL],
      'word' => ['my-site', NULL],
      'too short' => [substr(self::UUID, 0, -1), NULL],
      'too long' => [self::UUID . '0', NULL],
      'not hexadecimal' => [substr(self::UUID, 0, -1) . 'g', NULL],
      'hyphens in other places' => ['123e-4567e89b-42d3-a456-426614174000', NULL],
      'newline inside' => ["123e4567-e89b-42d3-a456\n-426614174000", NULL],
      'two pairs of braces' => ['{{' . self::UUID . '}}', NULL],
      'unbalanced brace' => ['{' . self::UUID, NULL],
      'path segments' => ['../' . self::UUID, NULL],
      'full-width digits' => ["\u{FF11}23e4567-e89b-42d3-a456-426614174000", NULL],
    ];
  }

  /**
   * A stored UUID in another notation is sent in the canonical one.
   *
   * @dataProvider storedNotations
   */
  public function testStoredUuidIsSentInCanonicalForm(string $stored): void {
    $result = $this->push($stored, [new Response(200, [], '{"message": "ok"}')]);

    $this->assertSame('success', $result->status);
    $this->assertCount(1, $this->transactions);
    $request = $this->transactions[0]['request'];
    $this->assertSame('https://sentinel.example.com/api/v1/sites/' . self::UUID . '/modules/sync', (string) $request->getUri());
    // The payload names the same UUID as the path.
    $this->assertSame(self::UUID, json_decode((string) $request->getBody(), TRUE)['site']['uuid']);
  }

  /**
   * Stored values that are the test's UUID in another notation.
   *
   * @return array<string, array{string}>
   *   The stored value.
   */
  public static function storedNotations(): array {
    return [
      'canonical' => [self::UUID],
      'upper case' => [strtoupper(self::UUID)],
      'padded' => ['  ' . self::UUID . "\n"],
      'braced' => ['{' . self::UUID . '}'],
      'unhyphenated' => [str_replace('-', '', self::UUID)],
    ];
  }

  /**
   * A stored value that is no UUID is not sent, and the message says why.
   *
   * @dataProvider storedValuesThatAreNoUuid
   */
  public function testStoredValueThatIsNoUuidIsNotSent(string $stored, string $trigger, string $level): void {
    $result = $this->push($stored, [], $trigger);

    $this->assertSame('unconfigured', $result->status);
    $this->assertNull($result->httpCode);
    $this->assertStringContainsString('Site UUID', $result->message);
    $this->assertStringNotContainsString($stored, $result->message);
    $this->assertSame([], $this->transactions);
    $this->assertSame('unconfigured', $this->stateValues['sentinel_connector.last_result']);
    $this->assertCount(1, $this->logs);
    $this->assertSame($level, $this->logs[0][0]);
    $this->assertSame($result->message, $this->logs[0][1]['@message']);
  }

  /**
   * Stored values that are no UUID, with the trigger and the log level.
   *
   * @return array<string, array{string, string, string}>
   *   The stored value, the trigger of the push and the expected log level.
   */
  public static function storedValuesThatAreNoUuid(): array {
    return [
      'word, form' => ['my-site', SyncService::TRIGGER_FORM, LogLevel::WARNING],
      'truncated, drush' => [substr(self::UUID, 0, -2), SyncService::TRIGGER_DRUSH, LogLevel::WARNING],
      'path, cron' => ['../../admin', SyncService::TRIGGER_CRON, LogLevel::DEBUG],
    ];
  }

  /**
   * A missing UUID keeps its own message.
   */
  public function testMissingUuidKeepsItsMessage(): void {
    $result = $this->push('', []);

    $this->assertSame('unconfigured', $result->status);
    $this->assertSame('Sentinel connector is not fully configured.', $result->message);
    $this->assertSame([], $this->transactions);
  }

  /**
   * Runs one push with the given stored Site UUID.
   *
   * @param string $stored
   *   The value of the site_uuid setting.
   * @param \GuzzleHttp\Psr7\Response[] $responses
   *   The responses Sentinel gives, in order.
   * @param string $trigger
   *   What started the push.
   */
  private function push(string $stored, array $responses, string $trigger = SyncService::TRIGGER_FORM): SyncResult {
    $settings = [
      'api_base_url' => 'https://sentinel.example.com',
      'site_uuid' => $stored,
      'site_url' => 'https://example.com',
      'site_name' => 'Example',
      'report_scope' => 'all',
      'enabled' => TRUE,
    ];
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $settings[$key] ?? NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn (string $key, mixed $default = NULL) => $default);
    $state->method('set')->willReturnCallback(function (string $key, mixed $value): void {
      $this->stateValues[$key] = $value;
    });

    $builder = $this->createMock(PayloadBuilder::class);
    $builder->method('build')->willReturn(['modules' => []]);
    $resolver = $this->createMock(ApiKeyResolver::class);
    $resolver->method('getKey')->willReturn('sk_test');
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1791550800);
    $time->method('getCurrentTime')->willReturn(1791550800);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(function (string $level, string|\Stringable $message, array $context = []): void {
      $this->logs[] = [$level, $context];
    });

    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->transactions));
    $client = new SentinelClient(new Client(['handler' => $stack]), $time);

    return (new SyncService($configFactory, $state, $builder, $resolver, $client, $time, $logger))->sync($trigger);
  }

}
