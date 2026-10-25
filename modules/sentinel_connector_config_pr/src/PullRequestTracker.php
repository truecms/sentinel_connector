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

}
