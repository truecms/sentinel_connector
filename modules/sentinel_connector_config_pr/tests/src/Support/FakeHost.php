<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Support;

use Drupal\sentinel_connector_config_pr\FilePlan;
use Drupal\sentinel_connector_config_pr\Host\HostException;
use Drupal\sentinel_connector_config_pr\Host\HostInterface;

/**
 * An in-memory Git host.
 */
class FakeHost implements HostInterface {

  /**
   * Files by branch, then by path.
   *
   * @var array<string, array<string, string>>
   */
  public array $branches = ['main' => []];

  /**
   * Pull requests by number.
   *
   * @var array<int, array{branch: string, base: string, url: string, open: bool, title: string, head?: string}>
   */
  public array $pulls = [];

  /**
   * The methods called, in order.
   *
   * @var string[]
   */
  public array $calls = [];

  /**
   * The number of commits made.
   */
  public int $commits = 0;

  /**
   * A method that throws when called.
   */
  public ?string $failOn = NULL;

  /**
   * Writes that succeed before writeFile() throws; negative never throws.
   */
  public int $failAfterWrites = -1;

  /**
   * The token received.
   */
  public ?string $token = NULL;

  /**
   * {@inheritdoc}
   */
  public function connect(string $token): void {
    $this->call(__FUNCTION__);
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultBranch(): string {
    return 'main';
  }

  /**
   * {@inheritdoc}
   */
  public function branchHead(string $branch): ?string {
    $this->call(__FUNCTION__);
    return isset($this->branches[$branch]) ? sha1(serialize($this->branches[$branch])) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function createBranch(string $branch, string $base): void {
    $this->call(__FUNCTION__);
    $this->branches[$branch] = $this->branches[$base];
  }

  /**
   * {@inheritdoc}
   */
  public function deleteBranch(string $branch): void {
    $this->call(__FUNCTION__);
    unset($this->branches[$branch]);
  }

  /**
   * {@inheritdoc}
   */
  public function blobSha(string $path, string $branch): ?string {
    $this->call(__FUNCTION__);
    return isset($this->branches[$branch][$path]) ? FilePlan::blobSha($this->branches[$branch][$path]) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function writeFile(string $path, string $content, string $message, string $branch, bool $exists): void {
    $this->call(__FUNCTION__);
    if ($this->failAfterWrites-- === 0) {
      throw new HostException('Fake failure.');
    }
    $this->branches[$branch][$path] = $content;
    $this->commits++;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFile(string $path, string $message, string $branch): void {
    $this->call(__FUNCTION__);
    unset($this->branches[$branch][$path]);
    $this->commits++;
  }

  /**
   * {@inheritdoc}
   */
  public function findOpenPullRequest(string $branch): ?array {
    $this->call(__FUNCTION__);
    foreach ($this->pulls as $number => $pull) {
      if ($pull['open'] && $pull['branch'] === $branch) {
        return ['url' => $pull['url'], 'number' => $number, 'base' => $pull['base']];
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function closedPullRequestHead(int $number): ?string {
    $this->call(__FUNCTION__);
    $pull = $this->pulls[$number] ?? NULL;
    return $pull && !$pull['open'] ? ($pull['head'] ?? NULL) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function createPullRequest(string $base, string $branch, string $title, string $body): array {
    $this->call(__FUNCTION__);
    $number = count($this->pulls) + 1;
    $url = 'https://git.example.com/acme/site/pull/' . $number;
    $this->pulls[$number] = ['branch' => $branch, 'base' => $base, 'url' => $url, 'open' => TRUE, 'title' => $title];
    return ['url' => $url, 'number' => $number];
  }

  /**
   * Records a call and throws when told to.
   */
  protected function call(string $method): void {
    $this->calls[] = $method;
    if ($this->failOn === $method) {
      throw new HostException('Fake failure.');
    }
  }

}
