<?php

namespace Drupal\sentinel_connector_config_pr\Host;

/**
 * The Git host operations the runner needs.
 *
 * Every method throws \Drupal\sentinel_connector_config_pr\Host\HostException
 * or a client exception when the host refuses or cannot be reached.
 */
interface HostInterface {

  /**
   * Authenticates with the token and loads the repository details.
   */
  public function connect(string $token): void;

  /**
   * The repository's default branch.
   */
  public function defaultBranch(): string;

  /**
   * The head commit of a branch, or NULL when the branch does not exist.
   */
  public function branchHead(string $branch): ?string;

  /**
   * Creates a branch from the head of another branch.
   */
  public function createBranch(string $branch, string $base): void;

  /**
   * Deletes a branch.
   */
  public function deleteBranch(string $branch): void;

  /**
   * The Git blob SHA of a file on a branch, or NULL when there is no file.
   */
  public function blobSha(string $path, string $branch): ?string;

  /**
   * Creates or updates one file on a branch, in one commit.
   */
  public function writeFile(string $path, string $content, string $message, string $branch, bool $exists): void;

  /**
   * Deletes one file on a branch, in one commit.
   */
  public function deleteFile(string $path, string $message, string $branch): void;

  /**
   * The open pull request from a branch, whatever its base branch.
   *
   * @return array{url: string, number: int, base: string}|null
   *   The pull request, or NULL when none is open.
   */
  public function findOpenPullRequest(string $branch): ?array;

  /**
   * Opens a pull request.
   *
   * @return array{url: string, number: int}
   *   The new pull request.
   */
  public function createPullRequest(string $base, string $branch, string $title, string $body): array;

}
