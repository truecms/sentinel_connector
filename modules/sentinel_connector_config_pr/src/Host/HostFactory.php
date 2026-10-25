<?php

namespace Drupal\sentinel_connector_config_pr\Host;

use Drupal\config_pr\RepoControllerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;

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
   * The available config_pr controllers keyed by service id.
   *
   * @var array<string, \Drupal\config_pr\RepoControllerInterface>
   */
  protected array $controllers = [];

  /**
   * Constructs the factory.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger, for the GitLab controllers built here.
   * @param \Drupal\config_pr\RepoControllerInterface|null $github
   *   The GitHub controller, when config_pr_github is enabled.
   * @param \Drupal\config_pr\RepoControllerInterface|null $githubEnterprise
   *   The GitHub Enterprise controller, when config_pr_github is enabled.
   * @param \Drupal\config_pr\RepoControllerInterface|null $gitlab
   *   The GitLab controller, when config_pr_gitlab is enabled.
   * @param \Drupal\config_pr\RepoControllerInterface|null $gitlabSelfManaged
   *   The self-managed GitLab controller, when config_pr_gitlab is enabled.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected MessengerInterface $messenger,
    ?RepoControllerInterface $github = NULL,
    ?RepoControllerInterface $githubEnterprise = NULL,
    ?RepoControllerInterface $gitlab = NULL,
    ?RepoControllerInterface $gitlabSelfManaged = NULL,
  ) {
    $controllers = [
      self::CONTROLLERS['github']['cloud'] => $github,
      self::CONTROLLERS['github']['self_hosted'] => $githubEnterprise,
      self::CONTROLLERS['gitlab']['cloud'] => $gitlab,
      self::CONTROLLERS['gitlab']['self_hosted'] => $gitlabSelfManaged,
    ];
    $this->controllers = array_filter($controllers);
  }

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
    return $id !== NULL && isset($this->controllers[$id]);
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
    if ($id === NULL || !isset($this->controllers[$id])) {
      throw new HostException(sprintf('Enable the %s module for this Git host.', self::MODULES[$provider] ?? 'config_pr_github or config_pr_gitlab'));
    }
    $url = rtrim(trim((string) $settings->get('api_base_url')), '/');
    if ($url !== '' && rtrim((string) $this->configFactory->get('config_pr.settings')->get('repo.repo_url'), '/') !== $url) {
      // The self-hosted controllers read the URL from config_pr.settings. If
      // it is not this module's URL, the token could go to another host.
      throw new HostException('The host URL could not be passed to config_pr.');
    }
    $owner = trim((string) $settings->get('repo_owner'));
    $name = trim((string) $settings->get('repo_name'));
    if ($provider === 'gitlab') {
      // config_pr finds GitLab projects by a global search that reads one
      // page of results, so a subclass looks the project up directly.
      $controller = $url === ''
        ? new GitlabProjectController($this->messenger, $this->configFactory)
        : new GitlabSelfManagedProjectController($this->messenger, $this->configFactory);
      $controller->setProjectRef(trim((string) $settings->get('gitlab_project_id')));
      return new GitlabHost($controller, $owner, $name, $committer);
    }
    // The controller service is shared. A copy keeps the token and the
    // branch state of this run out of it.
    return new GithubHost(clone $this->controllers[$id], $owner, $name, $committer);
  }

}
