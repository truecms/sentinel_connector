<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Creates a PayloadBuilder with runtime core/php/ip, platform and drift.
 */
class PayloadBuilderFactory {

  public function __construct(
    protected ModuleExtensionList $moduleList,
    protected RequestStack $requestStack,
    protected string $appRoot,
    protected ?PlatformCollector $platform = NULL,
    protected ?ConfigDriftCollector $configDrift = NULL,
  ) {}

  /**
   * Builds a PayloadBuilder with runtime core version, PHP version, and IP.
   */
  public function create(): PayloadBuilder {
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request ? (string) ($request->server->get('SERVER_ADDR') ?: '') : '';
    if ($ip === '') {
      // gethostbyname() returns the hostname unchanged on resolution failure,
      // so compare against the input rather than relying on a falsy return.
      $hostname = php_uname('n');
      $resolved = gethostbyname($hostname);
      $ip = ($resolved !== '' && $resolved !== $hostname) ? $resolved : '127.0.0.1';
    }
    try {
      $platform = $this->platform?->collect() ?? [];
    }
    catch (\Throwable) {
      // Stack versions are optional: never fail a sync over them.
      $platform = [];
    }
    try {
      $configDrift = $this->configDrift?->collect();
    }
    catch (\Throwable) {
      // The collector reports its own failures; this guards the push anyway.
      $configDrift = NULL;
    }
    return new PayloadBuilder($this->moduleList, \Drupal::VERSION, PHP_VERSION, $ip, ComposerProjectResolver::fromInstalledVersions($this->appRoot), $platform, $configDrift);
  }

}
