<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Unit;

use Gitlab\Client as GitlabClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies real HTTP discovery without the fixture replay client.
 *
 * @group sentinel_connector_config_pr
 */
#[Group('sentinel_connector_config_pr')]
class HttpDiscoveryTest extends TestCase {

  /**
   * Core's native HTTP implementations work for the optional GitLab client.
   */
  public function testCoreHttpImplementationsAreDiscovered(): void {
    $this->assertInstanceOf(Client::class, Psr18ClientDiscovery::find());
    $this->assertInstanceOf(HttpFactory::class, Psr17FactoryDiscovery::findRequestFactory());
    $this->assertInstanceOf(HttpFactory::class, Psr17FactoryDiscovery::findStreamFactory());
    // Construct through normal discovery without making a request.
    $this->assertInstanceOf(GitlabClient::class, new GitlabClient());
  }

}
