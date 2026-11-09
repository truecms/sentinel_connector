<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\ApiKeyMigrator;
use Drupal\sentinel_connector\ApiKeyResolver;
use Drupal\sentinel_connector\SyncService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that uninstalling the module leaves nothing in State.
 *
 * @group sentinel_connector
 */
#[RunTestsInSeparateProcesses]
#[Group('sentinel_connector')]
class UninstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'sentinel_connector'];

  /**
   * Uninstalling deletes the module's State values and keeps the others.
   */
  public function testUninstallDeletesState(): void {
    $this->installConfig(['sentinel_connector']);
    $state = $this->container->get('state');
    $state->set(ApiKeyResolver::STATE_KEY, 'sk_plain_text_key');
    $state->set(ApiKeyMigrator::KEY_STATE_PREFIX . ApiKeyMigrator::KEY_ID, 'sk_migrated_key');
    $state->set(SyncService::STATE_LAST_FINGERPRINT, 'abc');
    $state->set('sentinel_connector.last_message', 'Accepted.');
    // Another module's value that only shares the start of the name.
    $state->set('sentinel_connector_other.value', 'kept');
    $state->set('system.cron_last', 1792800000);

    $this->container->get('module_installer')->uninstall(['sentinel_connector']);

    $names = array_keys($this->container->get('keyvalue')->get('state')->getAll());
    $this->assertSame([], array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'sentinel_connector.'))));
    $state = $this->container->get('state');
    $state->resetCache();
    $this->assertNull($state->get(ApiKeyResolver::STATE_KEY));
    $this->assertSame('kept', $state->get('sentinel_connector_other.value'));
    $this->assertSame(1792800000, $state->get('system.cron_last'));
    $this->assertNull($this->config('sentinel_connector.settings')->get('report_scope'));
  }

}
