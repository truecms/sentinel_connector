<?php

namespace Drupal\sentinel_connector_config_pr\Host;

use Github\Client;

/**
 * GitHub and GitHub Enterprise through config_pr_github.
 */
class GithubHost extends ConfigPrHost {

  /**
   * {@inheritdoc}
   */
  public function branchHead(string $branch): ?string {
    try {
      $result = $this->client()->repo()->branches($this->owner, $this->name, $branch);
    }
    catch (\Throwable $error) {
      if (static::isNotFound($error)) {
        return NULL;
      }
      throw $error;
    }
    return isset($result['commit']['sha']) ? (string) $result['commit']['sha'] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteBranch(string $branch): void {
    $this->client()->gitData()->references()->remove($this->owner, $this->name, 'heads/' . $branch);
  }

  /**
   * {@inheritdoc}
   */
  public function blobSha(string $path, string $branch): ?string {
    try {
      $file = $this->client()->repo()->contents()->show($this->owner, $this->name, $path, $branch);
    }
    catch (\Throwable $error) {
      if (static::isNotFound($error)) {
        return NULL;
      }
      throw $error;
    }
    return is_array($file) && isset($file['sha']) && ($file['type'] ?? 'file') === 'file' ? (string) $file['sha'] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function findOpenPullRequest(string $branch, string $base): ?array {
    $pulls = $this->client()->pullRequest()->all($this->owner, $this->name, [
      'state' => 'open',
      'head' => $this->owner . ':' . $branch,
      'base' => $base,
    ]);
    foreach ($pulls as $pull) {
      if (($pull['head']['ref'] ?? NULL) === $branch && !empty($pull['html_url'])) {
        return ['url' => (string) $pull['html_url'], 'number' => (int) $pull['number']];
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
