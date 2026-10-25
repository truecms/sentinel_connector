<?php

namespace Drupal\sentinel_connector_config_pr\Host;

use Gitlab\Client;

/**
 * GitLab.com and self-managed GitLab through config_pr_gitlab.
 */
class GitlabHost extends ConfigPrHost {

  /**
   * {@inheritdoc}
   */
  public function branchHead(string $branch): ?string {
    try {
      $result = $this->client()->repositories()->branch($this->controller->getRepoId(), $branch);
    }
    catch (\Throwable $error) {
      if (static::isNotFound($error)) {
        return NULL;
      }
      throw $error;
    }
    return isset($result['commit']['id']) ? (string) $result['commit']['id'] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteBranch(string $branch): void {
    $this->client()->repositories()->deleteBranch($this->controller->getRepoId(), $branch);
  }

  /**
   * {@inheritdoc}
   */
  public function blobSha(string $path, string $branch): ?string {
    try {
      $file = $this->client()->repositoryFiles()->getFile($this->controller->getRepoId(), $path, $branch);
    }
    catch (\Throwable $error) {
      if (static::isNotFound($error)) {
        return NULL;
      }
      throw $error;
    }
    return is_array($file) && isset($file['blob_id']) ? (string) $file['blob_id'] : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * The config_pr GitLab controller sends content and encoding with a delete,
   * which the GitLab client rejects, so the client is called directly.
   */
  public function deleteFile(string $path, string $message, string $branch): void {
    $this->client()->repositoryFiles()->deleteFile($this->controller->getRepoId(), [
      'file_path' => $path,
      'branch' => $branch,
      'commit_message' => $message,
      'author_email' => $this->committer['email'],
      'author_name' => $this->committer['name'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function findOpenPullRequest(string $branch): ?array {
    $requests = $this->client()->mergeRequests()->all($this->controller->getRepoId(), [
      'state' => 'opened',
      'source_branch' => $branch,
    ]);
    foreach (is_array($requests) ? $requests : [] as $request) {
      // A merge request from a fork can use the same branch name.
      $sameProject = (string) ($request['source_project_id'] ?? '') === $this->controller->getRepoId();
      if ($sameProject && ($request['source_branch'] ?? NULL) === $branch && !empty($request['web_url'])) {
        return [
          'url' => (string) $request['web_url'],
          'number' => (int) $request['iid'],
          'base' => (string) ($request['target_branch'] ?? ''),
        ];
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function closedPullRequestHead(int $number): ?string {
    try {
      $request = $this->client()->mergeRequests()->show($this->controller->getRepoId(), $number);
    }
    catch (\Throwable $error) {
      if (static::isNotFound($error)) {
        return NULL;
      }
      throw $error;
    }
    return in_array($request['state'] ?? '', ['merged', 'closed'], TRUE) && isset($request['sha']) ? (string) $request['sha'] : NULL;
  }

  /**
   * The controller's authenticated client.
   */
  protected function client(): Client {
    $client = $this->controller->getClient();
    assert($client instanceof Client);
    return $client;
  }

}
