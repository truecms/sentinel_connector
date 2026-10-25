<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\ConfigDriftCollector;
use Drupal\sentinel_connector_config_pr\PullRequestTracker;

/**
 * Tests the open pull request in the drift report.
 *
 * @group sentinel_connector_config_pr
 */
class DriftReportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'config',
    'sentinel_connector',
    'config_pr',
    'sentinel_connector_config_pr',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'sentinel_connector', 'sentinel_connector_config_pr']);
  }

  /**
   * The open pull request is reported, and nothing when there is none.
   */
  public function testOpenPullRequestIsReported(): void {
    $collector = $this->container->get(ConfigDriftCollector::class);
    $this->assertArrayNotHasKey('export_pull_request', $collector->collect());

    $this->container->get(PullRequestTracker::class)->set('https://github.com/example/site/pull/7', 7, 1793588645);
    $this->assertSame([
      'url' => 'https://github.com/example/site/pull/7',
      'updated_at' => '2026-11-02T03:04:05Z',
    ], $collector->collect()['export_pull_request']);
  }

}
