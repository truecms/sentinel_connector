<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\sentinel_connector_config_pr\FilePlan;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that only files differing from the branch are written.
 *
 * @group sentinel_connector
 */
#[Group('sentinel_connector')]
class FilePlanTest extends UnitTestCase {

  /**
   * The blob SHA matches Git's: `printf 'hello\n' | git hash-object --stdin`.
   */
  public function testBlobShaMatchesGit(): void {
    $this->assertSame('ce013625030ba8dba906f756967f9e9ca394464a', FilePlan::blobSha("hello\n"));
  }

  /**
   * A second plan against the branch it produced has nothing to do.
   */
  public function testPlanIsIdempotent(): void {
    $branch = ['a.yml' => "old\n", 'gone.yml' => "x\n"];
    $remote = static function (string $path) use (&$branch): ?string {
      return isset($branch[$path]) ? FilePlan::blobSha($branch[$path]) : NULL;
    };
    $desired = ['a.yml' => "new\n", 'b.yml' => "b\n", 'gone.yml' => NULL, 'never.yml' => NULL];

    $plan = FilePlan::plan($desired, $remote);
    $this->assertSame(['a.yml', 'b.yml', 'gone.yml'], array_keys($plan));
    $this->assertSame(['op' => 'write', 'content' => "new\n", 'exists' => TRUE], $plan['a.yml']);
    $this->assertSame(['op' => 'write', 'content' => "b\n", 'exists' => FALSE], $plan['b.yml']);
    $this->assertSame('delete', $plan['gone.yml']['op']);

    // Apply the plan, then plan again.
    foreach ($plan as $path => $operation) {
      if ($operation['op'] === 'delete') {
        unset($branch[$path]);
      }
      else {
        $branch[$path] = $operation['content'];
      }
    }
    $this->assertSame([], FilePlan::plan($desired, $remote));
  }

}
