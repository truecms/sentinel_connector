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
   */
  public function findOpenPullRequest(string $branch, string $base): ?array {
    $requests = $this->client()->mergeRequests()->all($this->controller->getRepoId(), [
      'state' => 'opened',
      'source_branch' => $branch,
      'target_branch' => $base,
    ]);
    foreach (is_array($requests) ? $requests : [] as $request) {
      if (($request['source_branch'] ?? NULL) === $branch && !empty($request['web_url'])) {
        return ['url' => (string) $request['web_url'], 'number' => (int) $request['iid']];
      }
    }
    return NULL;
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
