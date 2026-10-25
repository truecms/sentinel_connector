<?php

namespace Drupal\sentinel_connector_config_pr;

use Drupal\Core\State\StateInterface;

/**
 * Remembers the open export pull request and the paths it changes.
 *
 * State holds the pull request URL and number, its last update time and the
 * repository paths written to the export branch. It never holds the token
 * or configuration values.
 */
class PullRequestTracker {

  /**
   * State key of the open pull request.
   */
  public const STATE_PULL_REQUEST = 'sentinel_connector_config_pr.pull_request';

  /**
   * State key of the paths written to the export branch.
   */
  public const STATE_PATHS = 'sentinel_connector_config_pr.paths';

  /**
   * State key of the export branch this module created, with its repository.
   */
  public const STATE_BRANCH = 'sentinel_connector_config_pr.branch';

  /**
   * State key of the last run that reached the Git host or found no change.
   */
  public const STATE_LAST_RUN = 'sentinel_connector_config_pr.last_run';

  /**
   * Constructs the tracker.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   */
  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * The open export pull request, for the drift block of the Sentinel payload.
   *
   * @return array{url: string, updated_at: string}|null
   *   The pull request URL and the ISO 8601 time of its last update by this
   *   module, or NULL when none is known to be open.
   */
  public function openPullRequest(): ?array {
    $pull = $this->get();
    if ($pull === NULL) {
      return NULL;
    }
    return [
      'url' => $pull['url'],
      'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $pull['updated_at']),
    ];
  }

  /**
   * The stored pull request.
   *
   * @return array{url: string, number: int, updated_at: int}|null
   *   The pull request, or NULL.
   */
  public function get(): ?array {
    $pull = $this->state->get(self::STATE_PULL_REQUEST);
    if (!is_array($pull) || !is_string($pull['url'] ?? NULL) || $pull['url'] === '') {
      return NULL;
    }
    return [
      'url' => $pull['url'],
      'number' => (int) ($pull['number'] ?? 0),
      'updated_at' => (int) ($pull['updated_at'] ?? 0),
    ];
  }

  /**
   * Records the open pull request.
   */
  public function set(string $url, int $number, int $updatedAt): void {
    $this->state->set(self::STATE_PULL_REQUEST, [
      'url' => $url,
      'number' => $number,
      'updated_at' => $updatedAt,
    ]);
  }

  /**
   * Forgets the pull request and its paths, after it was merged or closed.
   */
  public function clear(): void {
    $this->state->deleteMultiple([self::STATE_PULL_REQUEST, self::STATE_PATHS]);
  }

  /**
   * The paths written to the export branch since its pull request opened.
   *
   * @return array<string, array{name: string, collection: string}>
   *   Configuration names and collections keyed by repository path.
   */
  public function paths(): array {
    $paths = $this->state->get(self::STATE_PATHS, []);
    return is_array($paths) ? $paths : [];
  }

  /**
   * Records the paths written to the export branch.
   *
   * @param array<string, array{name: string, collection: string}> $paths
   *   Configuration names and collections keyed by repository path.
   */
  public function setPaths(array $paths): void {
    ksort($paths);
    $this->state->set(self::STATE_PATHS, $paths);
  }

  /**
   * The time of the last run, or 0.
   */
  public function lastRun(): int {
    return (int) $this->state->get(self::STATE_LAST_RUN, 0);
  }

  /**
   * Records the time of a run.
   */
  public function setLastRun(int $time): void {
    $this->state->set(self::STATE_LAST_RUN, $time);
  }

  /**
   * Whether the export branch is the one this module last left.
   *
   * @param string $identity
   *   The branch with its host and repository.
   * @param string|null $head
   *   The branch's current head commit.
   */
  public function ownsBranch(string $identity, ?string $head): bool {
    $owned = $this->state->get(self::STATE_BRANCH);
    return is_array($owned)
      && ($owned['identity'] ?? NULL) === $identity
      && $head !== NULL
      && (!empty($owned['unfinished']) || ($owned['head'] ?? NULL) === $head);
  }

  /**
   * Records an export branch this module created but has not finished.
   *
   * Set as soon as the branch exists, so a run that stops before its pull
   * request is opened, and fails to delete the branch, leaves a branch that
   * the next run still deletes and starts again, whatever its head.
   */
  public function setUnfinishedBranch(string $identity): void {
    $this->state->set(self::STATE_BRANCH, ['identity' => $identity, 'head' => NULL, 'unfinished' => TRUE]);
  }

  /**
   * Records the export branch and the head commit this module left it at.
   *
   * @param string $identity
   *   The branch with its host and repository, so a branch of the same name in
   *   another repository is not taken for this module's.
   * @param string $head
   *   The head commit after this module's last write.
   */
  public function setOwnedBranch(string $identity, string $head): void {
    $this->state->set(self::STATE_BRANCH, ['identity' => $identity, 'head' => $head]);
  }

  /**
   * The head commit this module last left the export branch at, or NULL.
   */
  public function ownedHead(): ?string {
    $owned = $this->state->get(self::STATE_BRANCH);
    return is_array($owned) && is_string($owned['head'] ?? NULL) ? $owned['head'] : NULL;
  }

  /**
   * Forgets the export branch, after this module deleted it.
   */
  public function clearOwnedBranch(): void {
    $this->state->delete(self::STATE_BRANCH);
  }

}
