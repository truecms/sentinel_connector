<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Creates a PayloadBuilder with runtime core/php/ip values injected.
 */
class PayloadBuilderFactory {

  public function __construct(
    protected ModuleExtensionList $moduleList,
    protected RequestStack $requestStack,
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
    return new PayloadBuilder($this->moduleList, \Drupal::VERSION, PHP_VERSION, $ip);
  }

}
