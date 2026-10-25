<?php

namespace Drupal\sentinel_connector_config_pr;

/**
 * Decides which files must be written or deleted on the export branch.
 *
 * A file whose content already matches the branch is skipped, so a run with
 * no new change makes no commit.
 */
final class FilePlan {

  /**
   * Plans the writes and deletes.
   *
   * @param array<string, string|null> $desired
   *   The wanted content keyed by repository path; NULL means no file.
   * @param callable(string): ?string $remoteBlobSha
   *   Returns the Git blob SHA of a path on the branch, or NULL when absent.
   *
   * @return array<string, array{op: string, content: string|null, exists: bool}>
   *   Operations keyed by path: 'write' (with 'exists' telling create from
   *   update) or 'delete'.
   */
  public static function plan(array $desired, callable $remoteBlobSha): array {
    $operations = [];
    foreach ($desired as $path => $content) {
      $remote = $remoteBlobSha($path);
      if ($content === NULL) {
        if ($remote !== NULL) {
          $operations[$path] = ['op' => 'delete', 'content' => NULL, 'exists' => TRUE];
        }
      }
      elseif ($remote !== static::blobSha($content)) {
        $operations[$path] = ['op' => 'write', 'content' => $content, 'exists' => $remote !== NULL];
      }
    }
    return $operations;
  }

  /**
   * The Git blob SHA of some content, as GitHub and GitLab report it.
   */
  public static function blobSha(string $content): string {
    return sha1('blob ' . strlen($content) . "\0" . $content);
  }

}
