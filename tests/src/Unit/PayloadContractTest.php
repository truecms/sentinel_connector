<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\sentinel_connector\PayloadBuilder;
use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the built payload to Sentinel's DrupalSiteSync schema.
 *
 * @group sentinel_connector
 */
class PayloadContractTest extends TestCase {

  /**
   * Validates the full payload against the documented API contract.
   */
  public function testBuiltPayloadMatchesSchema(): void {
    $ext = $this->getMockBuilder(TestExtension::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getName', 'getPath'])
      ->getMock();
    $ext->method('getName')->willReturn('token');
    $ext->method('getPath')->willReturn('modules/contrib/token');
    $ext->info = ['name' => 'Token', 'project' => 'token', 'version' => '8.x-1.15', 'description' => 'Tokens.'];
    $ext->status = 1;

    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('reset')->willReturnSelf();
    $list->method('getList')->willReturn(['token' => $ext]);

    $platform = [
      'php' => ['version' => '8.3.12', 'sapi' => 'fpm-fcgi'],
      'web_server' => ['software' => 'nginx', 'version' => '1.24.0', 'raw' => 'nginx/1.24.0'],
      'database' => ['driver' => 'mysql', 'product' => 'mariadb', 'version' => '10.11.6-MariaDB'],
      'os' => ['family' => 'Linux', 'distribution' => 'debian', 'release' => '12'],
    ];
    $payload = (new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4', NULL, $platform, fn () => self::drift()))->build('all');
    // Add the 'site' block the caller normally supplies.
    $payload['site'] = [
      'url' => 'https://example.com',
      'name' => 'Example',
      'uuid' => '11111111-1111-4111-8111-111111111111',
    ];

    $this->assertSame([], $this->errors($payload), 'Payload must match DrupalSiteSync schema.');

    // The config pull request submodule may add its open pull request.
    $payload['config_drift']['export_pull_request'] = [
      'url' => 'https://github.com/example/site/pull/7',
      'updated_at' => '2026-11-02T03:00:00Z',
    ];
    $this->assertSame([], $this->errors($payload), 'The export pull request is part of the schema.');
  }

  /**
   * Every drift status the collector sends matches the schema.
   *
   * @param array<string, mixed> $drift
   *   The config_drift block.
   *
   * @dataProvider validDrift
   */
  public function testDriftBlocksMatchSchema(array $drift): void {
    $this->assertSame([], $this->errors($this->payload($drift)));
  }

  /**
   * Drift blocks the collector can send.
   *
   * @return array<string, array{array<string, mixed>}>
   *   Drift blocks keyed by case.
   */
  public static function validDrift(): array {
    $unavailable = ['status' => 'unavailable', 'reason' => 'sync_directory_missing', 'total' => 0, 'objects' => []] + self::drift();
    return [
      'complete' => [self::drift()],
      'incomplete' => [['status' => 'incomplete', 'reason' => 'time_budget'] + self::drift()],
      'unavailable' => [$unavailable],
      'disabled' => [['reason' => 'disabled', 'config_pr_enabled' => TRUE] + $unavailable],
      'rename' => [
        ['objects' => [['name' => 'node.type.page', 'collection' => '', 'change' => 'rename']]] + self::drift(),
      ],
    ];
  }

  /**
   * The schema refuses a value, or anything else, in the drift block.
   *
   * @param array<string, mixed> $drift
   *   The config_drift block.
   *
   * @dataProvider invalidDrift
   */
  public function testDriftSchemaRefusesExtraData(array $drift): void {
    $this->assertNotSame([], $this->errors($this->payload($drift)));
  }

  /**
   * Drift blocks the schema refuses.
   *
   * @return array<string, array{array<string, mixed>}>
   *   Drift blocks keyed by case.
   */
  public static function invalidDrift(): array {
    $object = ['name' => 'system.site', 'collection' => '', 'change' => 'update'];
    return [
      'value on an object' => [['objects' => [$object + ['value' => 'secret']]] + self::drift()],
      'value on the block' => [self::drift() + ['values' => ['system.site' => 'secret']]],
      'unknown change' => [['objects' => [['change' => 'modify'] + $object]] + self::drift()],
      'unknown status' => [['status' => 'partial'] + self::drift()],
      'more than 200 objects' => [['objects' => array_fill(0, 201, $object)] + self::drift()],
      'pull request value' => [
        self::drift() + [
          'export_pull_request' => [
            'url' => 'https://github.com/example/site/pull/7',
            'updated_at' => '2026-11-02T03:00:00Z',
            'diff' => 'secret',
          ],
        ],
      ],
      'complete with a reason' => [['reason' => 'error'] + self::drift()],
      'incomplete without a reason' => [['status' => 'incomplete'] + self::drift()],
      'unavailable without a reason' => [['status' => 'unavailable', 'total' => 0, 'objects' => []] + self::drift()],
      'unavailable with objects' => [['status' => 'unavailable', 'reason' => 'error'] + self::drift()],
      'missing config_pr flag' => [array_diff_key(self::drift(), ['config_pr_enabled' => TRUE])],
    ];
  }

  /**
   * A complete drift block.
   *
   * @return array<string, mixed>
   *   The config_drift block.
   */
  private static function drift(): array {
    return [
      'status' => 'complete',
      'reason' => NULL,
      'generated_at' => '2026-11-02T03:04:05Z',
      'total' => 2,
      'config_pr_enabled' => FALSE,
      'truncated' => FALSE,
      'objects' => [
        ['name' => 'system.site', 'collection' => '', 'change' => 'update'],
        ['name' => 'views.view.content', 'collection' => 'language.fr', 'change' => 'create'],
      ],
    ];
  }

  /**
   * A minimal valid payload with the given drift block.
   *
   * @param array<string, mixed> $drift
   *   The config_drift block.
   *
   * @return array<string, mixed>
   *   The payload.
   */
  private function payload(array $drift): array {
    return [
      'site' => ['url' => 'https://example.com', 'name' => 'Example', 'uuid' => '11111111-1111-4111-8111-111111111111'],
      'drupal_info' => ['core_version' => '11.1.0', 'php_version' => '8.4.0', 'ip_address' => '10.0.0.1'],
      'modules' => [],
      'full_sync' => TRUE,
      'schema_version' => 3,
      'config_drift' => $drift,
    ];
  }

  /**
   * Validates a payload against the fixture schema.
   *
   * @param array<string, mixed> $payload
   *   The payload.
   *
   * @return string[]
   *   The validation errors.
   */
  private function errors(array $payload): array {
    $data = json_decode(json_encode($payload));
    $validator = new Validator();
    $validator->validate($data, (object) ['$ref' => 'file://' . dirname(__DIR__, 3) . '/tests/fixtures/drupal_sync.schema.json']);
    return array_map(fn($e) => $e['property'] . ': ' . $e['message'], $validator->getErrors());
  }

}
