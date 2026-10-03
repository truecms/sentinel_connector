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

    $payload = (new PayloadBuilder($list, '10.3.0', '8.3.0', '203.0.113.4'))->build('all');
    // Add the 'site' block the caller normally supplies.
    $payload['site'] = [
      'url' => 'https://example.com',
      'name' => 'Example',
      'uuid' => '11111111-1111-4111-8111-111111111111',
    ];

    $data = json_decode(json_encode($payload));
    $validator = new Validator();
    $validator->validate($data, (object) ['$ref' => 'file://' . dirname(__DIR__, 3) . '/tests/fixtures/drupal_sync.schema.json']);

    $errors = array_map(fn($e) => $e['property'] . ': ' . $e['message'], $validator->getErrors());
    $this->assertSame([], $errors, 'Payload must match DrupalSiteSync schema.');
  }

}
