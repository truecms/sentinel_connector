<?php

namespace Drupal\sentinel_connector_config_pr;

/**
 * Immutable outcome of one export run.
 */
final class RunResult {

  /**
   * Statuses that mean the run did what it should.
   */
  protected const OK = ['created', 'updated', 'unchanged', 'dry_run', 'not_due', 'disabled'];

  /**
   * Constructs a RunResult.
   *
   * @param string $status
   *   One of 'created', 'updated', 'unchanged', 'dry_run', 'not_due',
   *   'disabled', 'unconfigured', 'no_token' or 'failed'.
   * @param string $message
   *   A message for people. Never holds the token or configuration values.
   * @param string|null $url
   *   The pull request URL, when one is open.
   * @param int $files
   *   The number of files written or deleted, or listed by a dry run.
   * @param array<string, string> $changes
   *   For a dry run, the operation ('create', 'update', 'delete') of each
   *   changed configuration object, keyed by repository path.
   */
  public function __construct(
    public readonly string $status,
    public readonly string $message,
    public readonly ?string $url = NULL,
    public readonly int $files = 0,
    public readonly array $changes = [],
  ) {}

  /**
   * Whether the run succeeded or had nothing to do.
   */
  public function isOk(): bool {
    return in_array($this->status, self::OK, TRUE);
  }

}
