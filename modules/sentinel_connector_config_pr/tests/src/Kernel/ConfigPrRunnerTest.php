<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector_config_pr\ConfigPrRunner;
use Drupal\sentinel_connector_config_pr\Host\HostFactory;
use Drupal\sentinel_connector_config_pr\PullRequestTracker;
use Drupal\Tests\sentinel_connector_config_pr\Support\FakeHost;
use Drupal\Tests\sentinel_connector_config_pr\Support\FakeHostFactory;
use Drupal\Tests\sentinel_connector_config_pr\Support\TestLogger;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests export runs against an in-memory Git host.
 *
 * @group sentinel_connector
 */
#[RunTestsInSeparateProcesses]
class ConfigPrRunnerTest extends KernelTestBase {

  /**
   * The token, which must never reach a log entry.
   */
  protected const TOKEN = 'ghp_kernel_test_token_0123456789';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'config',
    'sentinel_connector',
    'config_pr',
    'sentinel_connector_config_pr',
    'sentinel_connector_config_pr_test',
  ];

  /**
   * The fake Git host.
   */
  protected FakeHost $host;

  /**
   * The log channel.
   */
  protected TestLogger $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'sentinel_connector', 'sentinel_connector_config_pr']);
    $this->config('system.site')->set('name', 'Example')->set('mail', 'site@example.com')->save();
    $this->config('sentinel_connector_config_pr.settings')
      ->set('repo_owner', 'acme')
      ->set('repo_name', 'site')
      ->save();
    // The code holds the configuration as it is now.
    $this->copyConfig($this->container->get('config.storage.export'), $this->container->get('config.storage.sync'));

    $this->host = new FakeHost();
    $this->logger = new TestLogger();
    $this->container->set(HostFactory::class, new FakeHostFactory($this->host));
    $this->container->set('logger.channel.sentinel_connector', $this->logger);
    $this->setSetting('sentinel_connector_config_pr', ['token' => self::TOKEN]);
  }

  /**
   * The runner.
   */
  protected function runner(): ConfigPrRunner {
    return $this->container->get(ConfigPrRunner::class);
  }

  /**
   * One pull request with exactly the changed files; no empty commits.
   */
  public function testRunOpensAndUpdatesOnePullRequest(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();

    $result = $this->runner()->run(FALSE, TRUE);
    $this->assertSame('created', $result->status, $result->message);
    $this->assertSame(['config/sync/system.site.yml'], array_keys($this->host->branches['sentinel/config-export']));
    $this->assertStringContainsString('slogan: Drifted', $this->host->branches['sentinel/config-export']['config/sync/system.site.yml']);
    $this->assertCount(1, $this->host->pulls);
    $this->assertSame('Configuration export from Example', $this->host->pulls[1]['title']);
    $this->assertSame(1, $this->host->commits);
    $this->assertSame(self::TOKEN, $this->host->token);
    $this->assertSame($result->url, $this->container->get(PullRequestTracker::class)->openPullRequest()['url']);

    // A second run without a new change makes no commit.
    $before = count($this->host->calls);
    $result = $this->runner()->run(FALSE, TRUE);
    $this->assertSame('unchanged', $result->status, $result->message);
    $this->assertSame(1, $this->host->commits);
    $this->assertSame([], array_intersect(['createBranch', 'deleteBranch', 'createPullRequest'], array_slice($this->host->calls, $before)));

    // A new change updates the same pull request.
    $this->config('system.site')->set('slogan', 'Drifted again')->save();
    $this->config('system.performance')->set('cache.page.max_age', 300)->save();
    $result = $this->runner()->run(FALSE, TRUE);
    $this->assertSame('updated', $result->status, $result->message);
    $this->assertSame(2, $result->files);
    $this->assertCount(1, $this->host->pulls);
    $this->assertSame(3, $this->host->commits);
    $this->assertEqualsCanonicalizing([
      'config/sync/system.performance.yml',
      'config/sync/system.site.yml',
    ], array_keys($this->host->branches['sentinel/config-export']));

    // A change put back to match the code is reverted on the branch.
    $this->config('system.performance')->set('cache.page.max_age', 0)->save();
    $result = $this->runner()->run(FALSE, TRUE);
    $this->assertSame('updated', $result->status, $result->message);
    $this->assertSame(1, $result->files);
    $this->assertStringContainsString('max_age: 0', $this->host->branches['sentinel/config-export']['config/sync/system.performance.yml']);

    $this->assertStringNotContainsString(self::TOKEN, $this->logger->text());
    $this->assertStringNotContainsString('Drifted', $this->logger->text());
    $this->assertStringContainsString($result->url ?? 'missing', $this->logger->text());
  }

  /**
   * Deleted objects delete their files; a closed pull request starts again.
   */
  public function testDeleteAndRestartAfterClosedPullRequest(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();
    $this->runner()->run(FALSE, TRUE);
    $this->host->pulls[1]['open'] = FALSE;

    // The object exists in the code and the base branch, but not on the site.
    $this->container->get('config.storage.sync')->write('system.removed', ['a' => 1]);
    $this->host->branches['main']['config/sync/system.removed.yml'] = "a: 1\n";

    $result = $this->runner()->run(FALSE, TRUE);
    $this->assertSame('created', $result->status, $result->message);
    $this->assertCount(2, $this->host->pulls);
    $this->assertContains('deleteBranch', $this->host->calls);
    $this->assertArrayNotHasKey('config/sync/system.removed.yml', $this->host->branches['sentinel/config-export']);
    $this->assertArrayHasKey('config/sync/system.site.yml', $this->host->branches['sentinel/config-export']);
  }

  /**
   * Branches and pull requests this module did not make are left alone.
   */
  public function testForeignBranchAndOtherBaseAreRefused(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();
    $this->host->branches['sentinel/config-export'] = ['team-work.txt' => "x\n"];
    $this->assertSame('failed', $this->runner()->run(FALSE, TRUE)->status);
    $this->assertArrayNotHasKey('config/sync/system.site.yml', $this->host->branches['sentinel/config-export']);
    $this->assertNotContains('deleteBranch', $this->host->calls);

    // The module's own pull request, after the base branch setting changed.
    unset($this->host->branches['sentinel/config-export']);
    $this->assertSame('created', $this->runner()->run(FALSE, TRUE)->status);
    $this->host->branches['develop'] = $this->host->branches['main'];
    $this->config('sentinel_connector_config_pr.settings')->set('base_branch', 'develop')->save();
    $this->assertSame('failed', $this->runner()->run(FALSE, TRUE)->status);
    $this->assertStringContainsString('targets another base branch', $this->logger->text());
    $this->assertTrue($this->host->pulls[1]['open']);
    $this->assertArrayHasKey('sentinel/config-export', $this->host->branches);
  }

  /**
   * A branch of the same name in another repository is not this module's.
   */
  public function testOwnershipIsPerRepository(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();
    $this->assertSame('created', $this->runner()->run(FALSE, TRUE)->status);
    $this->host->pulls[1]['open'] = FALSE;

    // Another repository with a branch of the same name.
    $this->config('sentinel_connector_config_pr.settings')->set('repo_name', 'other')->save();
    $this->host->branches['sentinel/config-export'] = ['team-work.txt' => "x\n"];
    $this->assertSame('failed', $this->runner()->run(FALSE, TRUE)->status);
    $this->assertArrayNotHasKey('config/sync/system.site.yml', $this->host->branches['sentinel/config-export']);
    $this->assertNotContains('deleteBranch', $this->host->calls);
  }

  /**
   * A branch changed by someone else after its pull request closed is kept.
   */
  public function testChangedBranchIsNotDeleted(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();
    $this->assertSame('created', $this->runner()->run(FALSE, TRUE)->status);
    $this->host->pulls[1]['open'] = FALSE;
    $this->host->branches['sentinel/config-export']['team-work.txt'] = "x\n";

    $this->assertSame('failed', $this->runner()->run(FALSE, TRUE)->status);
    $this->assertContains('team-work.txt', array_keys($this->host->branches['sentinel/config-export'] ?? []));
    $this->assertNotContains('deleteBranch', $this->host->calls);
  }

  /**
   * An open pull request this module did not open is not taken over.
   */
  public function testForeignPullRequestIsRefused(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();
    $this->host->pulls[1] = [
      'branch' => 'sentinel/config-export',
      'base' => 'main',
      'url' => 'https://git.example.com/fork/pull/1',
      'open' => TRUE,
      'title' => 'Fork',
    ];

    $this->assertSame('failed', $this->runner()->run(FALSE, TRUE)->status);
    $this->assertArrayNotHasKey('sentinel/config-export', $this->host->branches);
    $this->assertNull($this->container->get(PullRequestTracker::class)->openPullRequest());
  }

  /**
   * A missing token fails before any request and leaves no branch.
   */
  public function testMissingTokenFailsWithoutBranch(): void {
    $this->setSetting('sentinel_connector_config_pr', []);
    // Neither State nor configuration is a token source.
    $this->container->get('state')->set('sentinel_connector_config_pr.token', 'from_state');
    $this->container->get('state')->set('sentinel_connector_config_pr', ['token' => 'from_state']);
    $this->config('system.site')->set('slogan', 'Drifted')->save();

    $result = $this->runner()->run(FALSE, TRUE);
    $this->assertSame('no_token', $result->status);
    $this->assertFalse($result->isOk());
    $this->assertSame([], $this->host->calls);
    $this->assertArrayNotHasKey('sentinel/config-export', $this->host->branches);
    $this->assertSame('error', $this->logger->records[0]['level']);
    $this->assertStringContainsString('No access token', $this->logger->records[0]['message']);
  }

  /**
   * A failed write removes the branch the run created.
   */
  public function testFailureLeavesNoPartialBranch(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();
    $this->host->failOn = 'writeFile';

    $result = $this->runner()->run(FALSE, TRUE);
    $this->assertSame('failed', $result->status);
    $this->assertArrayNotHasKey('sentinel/config-export', $this->host->branches);
    $this->assertSame([], $this->host->pulls);
    $this->assertNull($this->container->get(PullRequestTracker::class)->openPullRequest());
    $this->assertStringContainsString('step write', $this->logger->text());
    $this->assertStringNotContainsString(self::TOKEN, $this->logger->text());
  }

  /**
   * A partly failed update still puts back what it wrote, on the next run.
   */
  public function testPartialUpdateIsPutBackLater(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();
    $this->runner()->run(FALSE, TRUE);

    // The first write (system.performance) lands, the second fails.
    $this->config('system.performance')->set('cache.page.max_age', 300)->save();
    $this->config('system.site')->set('slogan', 'Drifted again')->save();
    $this->host->failAfterWrites = 1;
    $this->assertSame('failed', $this->runner()->run(FALSE, TRUE)->status);
    $this->assertStringContainsString('max_age: 300', $this->host->branches['sentinel/config-export']['config/sync/system.performance.yml']);
    // The open pull request's branch is not deleted after a failure.
    $this->assertCount(1, $this->host->pulls);

    // The object returns to the code value before the next run.
    $this->config('system.performance')->set('cache.page.max_age', 0)->save();
    $this->host->failAfterWrites = -1;
    $this->assertSame('updated', $this->runner()->run(FALSE, TRUE)->status);
    $this->assertStringContainsString('max_age: 0', $this->host->branches['sentinel/config-export']['config/sync/system.performance.yml']);
    $this->assertStringContainsString('Drifted again', $this->host->branches['sentinel/config-export']['config/sync/system.site.yml']);
  }

  /**
   * Objects left out by export transformations are not exported.
   */
  public function testExportTransformationsAreRespected(): void {
    // The test module keeps the sync version of system.date in exports.
    $this->config('system.date')->set('country.default', 'AU')->save();
    $this->config('system.site')->set('slogan', 'Drifted')->save();

    $result = $this->runner()->run(TRUE);
    $this->assertSame('dry_run', $result->status);
    $this->assertSame(['config/sync/system.site.yml' => 'update'], $result->changes);
  }

  /**
   * A dry run lists the changes and contacts no Git host.
   */
  public function testDryRunWritesNothing(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();
    $this->setSetting('sentinel_connector_config_pr', []);

    $result = $this->runner()->run(TRUE);
    $this->assertSame('dry_run', $result->status);
    $this->assertSame(1, $result->files);
    $this->assertSame([], $this->host->calls);
    $this->assertSame(0, $this->container->get(PullRequestTracker::class)->lastRun());
  }

  /**
   * Without --force a run waits for the interval.
   */
  public function testIntervalIsHonoured(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();
    $this->assertSame('created', $this->runner()->run()->status);
    $this->config('system.site')->set('slogan', 'Drifted again')->save();
    $this->assertSame('not_due', $this->runner()->run()->status);
    $this->assertSame('updated', $this->runner()->run(FALSE, TRUE)->status);
  }

  /**
   * Cron does nothing while disabled, the default, or off on the environment.
   */
  public function testCronIsOptIn(): void {
    $this->config('system.site')->set('slogan', 'Drifted')->save();
    $this->container->get('module_handler')->loadInclude('sentinel_connector_config_pr', 'module');

    sentinel_connector_config_pr_cron();
    $this->assertSame([], $this->host->calls);
    $this->assertSame('disabled', $this->runner()->cron()->status);

    // On, but Sentinel Connector cron reporting is off (non-production).
    $this->config('sentinel_connector_config_pr.settings')->set('cron_enabled', TRUE)->save();
    sentinel_connector_config_pr_cron();
    $this->assertSame([], $this->host->calls);

    $this->config('sentinel_connector.settings')->set('enabled', TRUE)->save();
    sentinel_connector_config_pr_cron();
    $this->assertCount(1, $this->host->pulls);
    $this->assertGreaterThan(0, $this->container->get(PullRequestTracker::class)->lastRun());
  }

  /**
   * An empty sync directory is refused rather than exporting everything.
   */
  public function testEmptySyncDirectoryIsRefused(): void {
    $this->container->get('config.storage.sync')->deleteAll();
    $result = $this->runner()->run(FALSE, TRUE);
    $this->assertSame('failed', $result->status);
    $this->assertSame([], $this->host->calls);
  }

}
