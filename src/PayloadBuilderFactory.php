<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Creates a PayloadBuilder with runtime values, platform, drift and readiness.
 */
class PayloadBuilderFactory {

  public function __construct(
    protected ModuleExtensionList $moduleList,
    protected RequestStack $requestStack,
    protected string $appRoot,
    protected ?PlatformCollector $platform = NULL,
    protected ?ConfigDriftCollector $configDrift = NULL,
    protected ?ConfigFactoryInterface $configFactory = NULL,
    protected ?UpgradeStatusCollector $upgradeStatus = NULL,
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
    // On unless the site owner turned it off; a site updated from before
    // the setting existed keeps reporting.
    $platformReporting = $this->configFactory?->get('sentinel_connector.settings')->get('report_platform') ?? TRUE;
    try {
      // Off: the stack is not even read.
      $platform = $platformReporting ? ($this->platform?->collect() ?? []) : [];
    }
    catch (\Throwable) {
      // Stack versions are optional: never fail a sync over them.
      $platform = [];
    }
    // Drift is compared when a payload is built, not when a service that
    // could send one is created: that happens on every cron run and Drush
    // command.
    $collector = $this->configDrift;
    $configDrift = $collector === NULL ? NULL : static function () use ($collector): ?array {
      try {
        return $collector->collect();
      }
      catch (\Throwable) {
        // The collector reports its own failures; this guards the push anyway.
        return NULL;
      }
    };
    $readiness = $this->upgradeStatus;
    $upgradeStatus = $readiness === NULL ? NULL : static function () use ($readiness): ?array {
      try {
        return $readiness->collect();
      }
      catch (\Throwable) {
        return NULL;
      }
    };
    return new PayloadBuilder($this->moduleList, \Drupal::VERSION, PHP_VERSION, $ip, ComposerProjectResolver::fromInstalledVersions($this->appRoot), $platform, $configDrift, (bool) $platformReporting, $upgradeStatus);
  }

}
