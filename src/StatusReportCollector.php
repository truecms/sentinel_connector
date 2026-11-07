<?php

namespace Drupal\sentinel_connector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Reports the errors and warnings of the site's Drupal status report.
 *
 * Builds the list the way SystemManager::listRequirements() does, but calls
 * the runtime requirement hooks one module at a time, so each item carries
 * the module that produced it. Only errors and warnings are kept, as plain
 * text with the site's paths replaced. A build never fails a sync: a hook
 * that throws is skipped, and a failed or slow build is reported as an
 * unavailable block.
 */
class StatusReportCollector {

  public const STATUS_REPORTED = 'reported';
  public const STATUS_UNAVAILABLE = 'unavailable';

  public const REASON_TIMEOUT = 'timeout';
  public const REASON_FAILED = 'collector_failed';

  public const CONTEXT_WEB = 'web';
  public const CONTEXT_CLI = 'cli';

  /**
   * The settings.php key that overrides the reporting switch.
   */
  public const SETTINGS_KEY = 'sentinel_connector.report_status_report';

  /**
   * State key: the last web snapshot.
   */
  public const STATE_SNAPSHOT = 'sentinel_connector.status_report_snapshot';

  /**
   * State key: when a web request last tried to build a snapshot.
   */
  public const STATE_ATTEMPT = 'sentinel_connector.status_report_attempt';

  /**
   * The lock held while a web request builds a snapshot.
   */
  public const LOCK = 'sentinel_connector.status_report';

  /**
   * Seconds between two web snapshots.
   */
  public const SNAPSHOT_INTERVAL = 43200;

  /**
   * Seconds a web snapshot is sent for before the sync builds its own list.
   */
  public const SNAPSHOT_MAX_AGE = 86400;

  /**
   * Seconds a build may take before it is reported as a timeout.
   */
  public const TIME_BUDGET = 20;

  /**
   * Checks that are wrong when the list is built from the command line.
   *
   * OPcache, APCu, output buffering and upload progress are configured for
   * the web server, not the CLI; the memory limit and the SameSite cookie
   * attribute come from a different php.ini or need a request.
   */
  public const CLI_LEFT_OUT = [
    'file_progress',
    'output_buffering',
    'php_apcu_available',
    'php_apcu_enabled',
    'php_memory_limit',
    'php_opcache',
    'php_session_samesite',
    'upload_progress',
  ];

  /**
   * The module whose own checks are never sent.
   */
  public const OWN_MODULE = 'sentinel_connector';

  /**
   * Severities sent, by Drupal's integer severity.
   */
  protected const SEVERITIES = [2 => 'error', 1 => 'warning'];

  /**
   * Field limits, in characters.
   */
  public const MAX_KEY = 128;
  public const MAX_TITLE = 200;
  public const MAX_VALUE = 300;
  public const MAX_DESCRIPTION = 1000;
  public const MAX_PROVIDER = 64;
  public const MAX_ITEMS = 100;

  /**
   * Modules whose hook threw in this request, so each is logged once.
   *
   * @var array<string, true>
   */
  protected array $failedModules = [];

  /**
   * Constructs a StatusReportCollector.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler that invokes the requirement hooks.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   Renders render array texts to strings.
   * @param \Drupal\Core\State\StateInterface $state
   *   Holds the web snapshot.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   Lets one web request at a time build the snapshot.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the reporting switch.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings, which may override the reporting switch.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   Gives the private files and temporary paths that are replaced.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   Receives one warning for each module whose hook throws.
   * @param string $appRoot
   *   The Drupal root, replaced in every text.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected RendererInterface $renderer,
    protected StateInterface $state,
    protected LockBackendInterface $lock,
    protected ConfigFactoryInterface $configFactory,
    protected Settings $settings,
    protected FileSystemInterface $fileSystem,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected string $appRoot,
  ) {}

  /**
   * Whether the status report is reported: settings.php, then configuration.
   */
  public function isReportingEnabled(): bool {
    try {
      $override = $this->settings->get(self::SETTINGS_KEY);
      if ($override !== NULL) {
        return (bool) $override;
      }
      return $this->configFactory->get('sentinel_connector.settings')->get('report_status_report') !== FALSE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Whether settings.php sets the reporting switch.
   */
  public function isReportingOverridden(): bool {
    try {
      return $this->settings->get(self::SETTINGS_KEY) !== NULL;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Returns the payload's status_report block.
   *
   * Sends the web snapshot while it is newer than a day. Otherwise builds
   * the list now: from the command line with the web-only checks left out.
   * A failed build is sent as unavailable and keeps the last snapshot.
   *
   * @return array<string, mixed>|null
   *   The block, or NULL when reporting is off.
   */
  public function collect(): ?array {
    if (!$this->isReportingEnabled()) {
      return NULL;
    }
    try {
      $snapshot = $this->snapshot();
      if ($snapshot !== NULL && $this->now() - $snapshot['checked_at'] < self::SNAPSHOT_MAX_AGE) {
        return $this->block($snapshot);
      }
      $context = PHP_SAPI === 'cli' ? self::CONTEXT_CLI : self::CONTEXT_WEB;
      $report = $this->build($context);
      if ($context === self::CONTEXT_WEB && $report['status'] === self::STATUS_REPORTED) {
        $this->store($report);
      }
      return $report;
    }
    catch (\Throwable) {
      return $this->unavailable(self::REASON_FAILED);
    }
  }

  /**
   * Builds a web snapshot when the last attempt is older than 12 hours.
   *
   * Called after the response is sent. One request at a time: the lock is
   * taken without waiting, and a request that does not get it does nothing.
   * A failed build is not stored, so the last good snapshot stays.
   *
   * @return bool
   *   TRUE when this call built a snapshot, whatever its outcome.
   */
  public function refreshSnapshot(): bool {
    if (!$this->isReportingEnabled() || !$this->isSnapshotDue()) {
      return FALSE;
    }
    if (!$this->lock->acquire(self::LOCK, self::TIME_BUDGET + 10)) {
      return FALSE;
    }
    try {
      // Another request may have built it between the check and the lock.
      $this->state->resetCache();
      if ($this->now() - (int) $this->state->get(self::STATE_ATTEMPT, 0) < self::SNAPSHOT_INTERVAL) {
        return FALSE;
      }
      $this->state->set(self::STATE_ATTEMPT, $this->now());
      $report = $this->build(self::CONTEXT_WEB);
      if ($report['status'] === self::STATUS_REPORTED) {
        $this->store($report);
      }
      return TRUE;
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Whether the last snapshot attempt is older than the interval.
   */
  public function isSnapshotDue(): bool {
    $last = (int) $this->state->get(self::STATE_ATTEMPT, 0);
    return $this->now() - $last >= self::SNAPSHOT_INTERVAL;
  }

  /**
   * The stored web snapshot, NULL when there is none or it is unreadable.
   *
   * @return array{checked_at: int, context: string, left_out: list<string>, items: list<array<string, string>>}|null
   *   The snapshot.
   */
  public function snapshot(): ?array {
    $snapshot = $this->state->get(self::STATE_SNAPSHOT);
    if (!is_array($snapshot) || !is_int($snapshot['checked_at'] ?? NULL) || !is_array($snapshot['items'] ?? NULL)) {
      return NULL;
    }
    return [
      'checked_at' => $snapshot['checked_at'],
      'context' => self::CONTEXT_WEB,
      'left_out' => [],
      'items' => array_values($snapshot['items']),
    ];
  }

  /**
   * Builds the block now, in the given context.
   *
   * @param string $context
   *   'web' or 'cli'. From the command line the web-only checks are left
   *   out and listed.
   *
   * @return array<string, mixed>
   *   A reported block, or an unavailable one with its reason.
   */
  public function build(string $context): array {
    $context = $context === self::CONTEXT_CLI ? self::CONTEXT_CLI : self::CONTEXT_WEB;
    $start = $this->microtime();
    try {
      $requirements = $this->requirements($start);
    }
    catch (StatusReportTimeoutException) {
      return $this->unavailable(self::REASON_TIMEOUT);
    }
    catch (\Throwable) {
      return $this->unavailable(self::REASON_FAILED);
    }
    $leftOut = $context === self::CONTEXT_CLI ? self::CLI_LEFT_OUT : [];
    try {
      $items = $this->clean($requirements, $leftOut, $start);
    }
    catch (StatusReportTimeoutException) {
      return $this->unavailable(self::REASON_TIMEOUT);
    }
    catch (\Throwable) {
      return $this->unavailable(self::REASON_FAILED);
    }
    return $this->block([
      'checked_at' => $this->now(),
      'context' => $context,
      'left_out' => $leftOut,
      'items' => $items,
    ]);
  }

  /**
   * Collects the runtime requirements, keyed by key, with their provider.
   *
   * @param float $start
   *   The time the build started, for the time budget.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements, each with a '#provider' key.
   */
  protected function requirements(float $start): array {
    foreach (array_keys($this->moduleHandler->getModuleList()) as $module) {
      $this->moduleHandler->loadInclude($module, 'install');
    }
    $requirements = [];
    $add = function (mixed $items, string $module) use (&$requirements, $start): void {
      if (is_array($items)) {
        foreach ($items as $key => $item) {
          if (is_array($item)) {
            $requirements[(string) $key] = ['#provider' => $module] + $item;
          }
        }
      }
      $this->checkBudget($start);
    };
    $this->moduleHandler->invokeAllWith('requirements', function (callable $hook, string $module) use ($add): void {
      $add($this->guard($module, fn () => $hook('runtime')), $module);
    });
    // Drupal 11.2 adds a runtime hook and an alter hook of its own.
    $runtime = version_compare(\Drupal::VERSION, '11.1.99', '>');
    if ($runtime) {
      $this->moduleHandler->invokeAllWith('runtime_requirements', function (callable $hook, string $module) use ($add): void {
        $add($this->guard($module, fn () => $hook()), $module);
      });
    }
    foreach ($runtime ? ['requirements_alter', 'runtime_requirements_alter'] : ['requirements_alter'] as $alter) {
      $this->moduleHandler->invokeAllWith($alter, function (callable $hook, string $module) use (&$requirements, $start): void {
        $altered = $requirements;
        $result = $this->guard($module, function () use ($hook, &$altered): bool {
          $hook($altered);
          return TRUE;
        });
        if ($result === TRUE && is_array($altered)) {
          foreach ($altered as $key => $item) {
            if (is_array($item) && !isset($item['#provider'])) {
              // Added by this alter hook.
              $item = ['#provider' => $requirements[$key]['#provider'] ?? $module] + $item;
            }
            $altered[$key] = $item;
          }
          $requirements = array_filter($altered, 'is_array');
        }
        $this->checkBudget($start);
      });
    }
    return $requirements;
  }

  /**
   * Runs one module's hook; a hook that throws is skipped and logged once.
   *
   * @param string $module
   *   The module whose hook runs.
   * @param callable $call
   *   Calls the hook.
   *
   * @return mixed
   *   What the hook returned, NULL when it threw.
   */
  protected function guard(string $module, callable $call): mixed {
    try {
      return $call();
    }
    catch (StatusReportTimeoutException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      if (!isset($this->failedModules[$module])) {
        $this->failedModules[$module] = TRUE;
        try {
          $this->logger->warning('The status report check of the @module module failed and is left out of the Sentinel status report: @message', [
            '@module' => $module,
            '@message' => $e->getMessage(),
          ]);
        }
        catch (\Throwable) {
        }
      }
      return NULL;
    }
  }

  /**
   * Keeps errors and warnings as cleaned items, errors first.
   *
   * @param array<string, array<string, mixed>> $requirements
   *   The requirements with their provider.
   * @param list<string> $leftOut
   *   Keys left out because they are wrong in this context.
   * @param float $start
   *   The time the build started, for the time budget.
   *
   * @return list<array<string, string>>
   *   At most MAX_ITEMS items.
   */
  protected function clean(array $requirements, array $leftOut, float $start): array {
    $replacements = $this->pathReplacements();
    $items = [];
    $seen = [];
    foreach ($requirements as $key => $requirement) {
      $severity = self::SEVERITIES[$this->severity($requirement['severity'] ?? NULL)] ?? NULL;
      if ($severity === NULL) {
        continue;
      }
      // The connector's own checks describe its pushes, which Sentinel
      // knows; sending them would change the payload after each push.
      if (($requirement['#provider'] ?? NULL) === self::OWN_MODULE) {
        continue;
      }
      $key = $this->key($key);
      if (in_array($key, $leftOut, TRUE)) {
        continue;
      }
      // Sentinel refuses duplicate keys; two raw keys can clean to one.
      $unique = $key;
      for ($i = 2; isset($seen[$unique]); $i++) {
        $suffix = '_' . $i;
        $unique = substr($key, 0, self::MAX_KEY - strlen($suffix)) . $suffix;
      }
      $seen[$unique] = TRUE;
      $title = $this->text($requirement['title'] ?? '', self::MAX_TITLE, $replacements);
      $items[] = [
        'key' => $unique,
        'severity' => $severity,
        'title' => $title === '' ? $unique : $title,
        'value' => $this->text($requirement['value'] ?? '', self::MAX_VALUE, $replacements),
        'description' => $this->text($requirement['description'] ?? '', self::MAX_DESCRIPTION, $replacements),
        'provider' => $this->provider($requirement['#provider'] ?? ''),
      ];
      $this->checkBudget($start);
    }
    // Errors first, then by key, so the order does not change the hash.
    usort($items, function (array $a, array $b): int {
      return [$a['severity'] === 'error' ? 0 : 1, $a['key']] <=> [$b['severity'] === 'error' ? 0 : 1, $b['key']];
    });
    return array_slice($items, 0, self::MAX_ITEMS);
  }

  /**
   * Drupal's integer severity of a requirement, OK when it has none.
   *
   * Accepts integers, numeric strings and the Drupal 11.2 enum.
   */
  protected function severity(mixed $severity): int {
    if ($severity instanceof \BackedEnum) {
      $severity = $severity->value;
    }
    if (is_int($severity) || (is_string($severity) && preg_match('/^-?[0-9]+$/D', $severity))) {
      return (int) $severity;
    }
    return 0;
  }

  /**
   * A key Sentinel accepts: lower case, safe characters, 128 at most.
   */
  protected function key(string|int $key): string {
    $key = preg_replace('/[^a-z0-9_.:-]/', '_', strtolower((string) $key)) ?? '';
    $key = substr($key, 0, self::MAX_KEY);
    return $key === '' ? 'item' : $key;
  }

  /**
   * A provider Sentinel accepts: a module machine name, 64 at most.
   */
  protected function provider(mixed $provider): string {
    $provider = preg_replace('/[^a-z0-9_]/', '_', strtolower(is_string($provider) ? $provider : '')) ?? '';
    $provider = substr($provider, 0, self::MAX_PROVIDER);
    return $provider === '' ? 'system' : $provider;
  }

  /**
   * One text as plain text, the site's paths replaced, cut to a limit.
   *
   * @param mixed $text
   *   A string, markup object or render array.
   * @param int $limit
   *   The maximum length in characters.
   * @param array<string, string> $replacements
   *   Replacement labels keyed by path.
   */
  protected function text(mixed $text, int $limit, array $replacements): string {
    if (is_array($text)) {
      $text = $this->render($text);
    }
    elseif ($text instanceof \Stringable) {
      $text = (string) $text;
    }
    elseif (!is_string($text) && !is_int($text) && !is_float($text)) {
      return '';
    }
    $text = (string) $text;
    // Inline tags go; any other tag becomes a space, so the words of two
    // paragraphs or a link and the next paragraph do not join.
    // A link next to a word keeps a space between them.
    $text = preg_replace(['#</a>(?=[^\s.,;:!?)\]<])#i', '#(?<=[^\s(\[>])<a\b#i'], ['</a> ', ' <a'], $text) ?? $text;
    $text = preg_replace('#</?(a|abbr|b|cite|code|em|i|kbd|mark|q|s|samp|small|span|strong|sub|sup|time|u|var)\b[^>]*>#i', '', $text) ?? $text;
    $text = preg_replace('#<[^>]*>#', ' ', $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Decoding can produce tags again from escaped markup.
    $text = strip_tags($text);
    if (!mb_check_encoding($text, 'UTF-8')) {
      $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    $text = strtr($text, $replacements);
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    return $this->cut($text, $limit);
  }

  /**
   * Renders a render array to a string, in a render context of its own.
   *
   * @param array<array-key, mixed> $elements
   *   The render array.
   */
  protected function render(array $elements): string {
    return (string) $this->renderer->renderInIsolation($elements);
  }

  /**
   * Cuts a text on a word, ending with an ellipsis, to the limit.
   */
  protected function cut(string $text, int $limit): string {
    if (mb_strlen($text) <= $limit) {
      return $text;
    }
    $cut = mb_substr($text, 0, $limit - 1);
    $space = mb_strrpos($cut, ' ');
    // A single long word is cut where it is.
    if ($space !== FALSE && $space > $limit / 2) {
      $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, " \t\n\r\0\x0B.,;:") . '…';
  }

  /**
   * The site paths replaced in every text, longest first.
   *
   * @return array<string, string>
   *   Replacement labels keyed by path.
   */
  protected function pathReplacements(): array {
    $paths = [];
    $add = function (mixed $path, string $label) use (&$paths): void {
      if (!is_string($path) || $path === '') {
        return;
      }
      // A relative path, such as ../private, is shown resolved.
      $real = @realpath($path);
      foreach ([$path, $real] as $candidate) {
        $candidate = is_string($candidate) ? rtrim($candidate, '/\\') : '';
        // Only absolute paths: a fragment would replace parts of words.
        if (strlen($candidate) >= 2 && preg_match('#^([a-zA-Z]:)?[/\\\\]#', $candidate)) {
          $paths[$candidate] ??= $label;
        }
      }
    };
    // More specific paths first: they may sit inside the site root.
    try {
      $add($this->settings->get('file_private_path'), '[private files]');
    }
    catch (\Throwable) {
    }
    try {
      $add($this->fileSystem->getTempDirectory(), '[temporary files]');
    }
    catch (\Throwable) {
    }
    $add($this->appRoot, '[site root]');
    if (defined('DRUPAL_ROOT')) {
      $add(DRUPAL_ROOT, '[site root]');
    }
    uksort($paths, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    return $paths;
  }

  /**
   * Throws when the build is over its time budget.
   */
  protected function checkBudget(float $start): void {
    if ($this->microtime() - $start > self::TIME_BUDGET) {
      throw new StatusReportTimeoutException();
    }
  }

  /**
   * Stores a reported block as the web snapshot.
   *
   * @param array<string, mixed> $report
   *   The block.
   */
  protected function store(array $report): void {
    $this->state->set(self::STATE_SNAPSHOT, [
      'checked_at' => $this->now(),
      'items' => $report['items'],
    ]);
  }

  /**
   * A reported block from a snapshot or a build.
   *
   * @param array{checked_at: int, context: string, left_out: list<string>, items: list<array<string, string>>} $report
   *   The snapshot or build.
   *
   * @return array<string, mixed>
   *   The block.
   */
  protected function block(array $report): array {
    return [
      'status' => self::STATUS_REPORTED,
      'checked_at' => $this->format($report['checked_at']),
      'context' => $report['context'],
      'left_out' => $report['left_out'],
      'items' => $report['items'],
    ];
  }

  /**
   * An unavailable block: no items, so Sentinel keeps the last list.
   *
   * @return array<string, mixed>
   *   The block.
   */
  protected function unavailable(string $reason): array {
    return [
      'status' => self::STATUS_UNAVAILABLE,
      'reason' => $reason,
      'checked_at' => $this->format($this->now()),
      'context' => PHP_SAPI === 'cli' ? self::CONTEXT_CLI : self::CONTEXT_WEB,
      'left_out' => [],
    ];
  }

  /**
   * The current Unix time.
   */
  public function now(): int {
    try {
      return $this->time->getCurrentTime();
    }
    catch (\Throwable) {
      return time();
    }
  }

  /**
   * The current time in seconds with microseconds, for the time budget.
   */
  protected function microtime(): float {
    try {
      return $this->time->getCurrentMicroTime();
    }
    catch (\Throwable) {
      return microtime(TRUE);
    }
  }

  /**
   * A Unix time as an ISO 8601 UTC timestamp.
   */
  protected function format(int $time): string {
    return gmdate('Y-m-d\TH:i:s\Z', $time);
  }

}
