<?php

namespace Drupal\sentinel_connector_config_pr\Host;

use Drupal\config_pr\RepoControllerInterface;

/**
 * Uses a config_pr repository controller as the Git host transport.
 *
 * The controller's file methods read at the ref set with setSha(), which
 * config_pr points at the default branch. Before touching the export branch
 * this class points it at that branch instead, so file SHAs match the branch
 * being written. The subclasses add the few calls config_pr does not offer
 * (open pull request by branch, file blob SHA, one branch by name, branch
 * deletion) through the controller's own authenticated client. config_pr's
 * branch list holds only the first page of branches, so it is not used.
 */
abstract class ConfigPrHost implements HostInterface {

  /**
   * Constructs the host.
   *
   * @param \Drupal\config_pr\RepoControllerInterface $controller
   *   A config_pr repository controller used by this run only.
   * @param string $owner
   *   The repository owner or GitLab namespace.
   * @param string $name
   *   The repository name.
   * @param array{name: string, email: string} $committer
   *   The committer of the export commits.
   */
  public function __construct(
    protected RepoControllerInterface $controller,
    protected string $owner,
    protected string $name,
    protected array $committer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function connect(string $token): void {
    $this->controller->setRepoOwner($this->owner);
    $this->controller->setRepoName($this->name);
    $this->controller->setCommitter($this->committer);
    $this->controller->setAuthToken($token);
    if (!$this->controller->authenticate()) {
      throw new HostException('The repository was not found or the token cannot read it.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultBranch(): string {
    return $this->controller->getDefaultBranch();
  }

  /**
   * {@inheritdoc}
   */
  public function createBranch(string $branch, string $base): void {
    $head = $this->branchHead($base);
    if ($head === NULL) {
      throw new HostException('The base branch does not exist.');
    }
    $this->controller->setSha($head);
    if (!$this->controller->createBranch($branch)) {
      throw new HostException('The export branch could not be created.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function writeFile(string $path, string $content, string $message, string $branch, bool $exists): void {
    $this->controller->setSha($branch);
    $result = $exists
      ? $this->controller->updateFile($path, $content, $message, $branch)
      : $this->controller->createFile($path, $content, $message, $branch);
    if ($result === FALSE) {
      throw new HostException('A file could not be written to the export branch.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFile(string $path, string $message, string $branch): void {
    $this->controller->setSha($branch);
    if (!$this->controller->deleteFile($path, $message, $branch)) {
      throw new HostException('A file could not be deleted on the export branch.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPullRequest(string $base, string $branch, string $title, string $body): array {
    $result = $this->controller->createPr($base, $branch, $title, $body);
    if (!is_array($result) || empty($result['url'])) {
      throw new HostException('The pull request could not be opened.');
    }
    return ['url' => (string) $result['url'], 'number' => (int) ($result['number'] ?? 0)];
  }

  /**
   * Whether a client exception means "not found".
   */
  protected static function isNotFound(\Throwable $error): bool {
    return $error->getCode() === 404;
  }

}
