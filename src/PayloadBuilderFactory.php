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

  public function create(): PayloadBuilder {
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request ? (string) ($request->server->get('SERVER_ADDR') ?: '') : '';
    if ($ip === '') {
      $ip = gethostbyname(php_uname('n')) ?: '127.0.0.1';
    }
    return new PayloadBuilder($this->moduleList, \Drupal::VERSION, PHP_VERSION, $ip);
  }

}
