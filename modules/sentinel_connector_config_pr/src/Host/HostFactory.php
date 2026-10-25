<?php

namespace Drupal\sentinel_connector_config_pr\Host;

use Drupal\config_pr\RepoControllerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the Git host for the configured provider.
 */
class HostFactory {

  /**
   * The config_pr controller services, by provider and hosting.
   */
  public const CONTROLLERS = [
    'github' => [
      'cloud' => 'config_pr_github.repo_controller.github',
      'self_hosted' => 'config_pr_github.repo_controller.github_enterprise',
    ],
    'gitlab' => [
      'cloud' => 'config_pr_gitlab.repo_controller.gitlab',
      'self_hosted' => 'config_pr_gitlab.repo_controller.gitlab_self_managed',
    ],
  ];

  /**
   * The module that provides each provider's controllers.
   */
  public const MODULES = [
    'github' => 'config_pr_github',
    'gitlab' => 'config_pr_gitlab',
  ];

  /**
   * Constructs the factory.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container, to look up the controller lazily.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected ContainerInterface $container,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The controller service id for the current settings.
   */
  public function controllerId(): ?string {
    $settings = $this->configFactory->get('sentinel_connector_config_pr.settings');
    $hosting = trim((string) $settings->get('api_base_url')) === '' ? 'cloud' : 'self_hosted';
    return self::CONTROLLERS[(string) $settings->get('provider')][$hosting] ?? NULL;
  }

  /**
   * Whether the controller for the current settings is available.
   */
  public function isAvailable(): bool {
    $id = $this->controllerId();
    return $id !== NULL && $this->container->has($id);
  }

  /**
   * Creates the host for one run.
   *
   * @param array{name: string, email: string} $committer
   *   The committer of the export commits.
   */
  public function create(array $committer): HostInterface {
    $settings = $this->configFactory->get('sentinel_connector_config_pr.settings');
    $provider = (string) $settings->get('provider');
    $id = $this->controllerId();
    if ($id === NULL || !$this->container->has($id)) {
      throw new HostException(sprintf('Enable the %s module for this Git host.', self::MODULES[$provider] ?? 'config_pr_github or config_pr_gitlab'));
    }
    $controller = $this->container->get($id);
    if (!$controller instanceof RepoControllerInterface) {
      throw new HostException('The config_pr controller is not usable.');
    }
    // The controller service is shared. A copy keeps the token and the
    // branch state of this run out of it.
    $controller = clone $controller;
    $owner = trim((string) $settings->get('repo_owner'));
    $name = trim((string) $settings->get('repo_name'));
    return $provider === 'gitlab'
      ? new GitlabHost($controller, $owner, $name, $committer)
      : new GithubHost($controller, $owner, $name, $committer);
  }

}
