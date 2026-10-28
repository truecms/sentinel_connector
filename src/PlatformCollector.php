<?php

namespace Drupal\sentinel_connector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Reads the PHP, web server, database and OS versions for the payload.
 *
 * On Debian and Ubuntu it also reads the package database for the stack's
 * own source packages, so Sentinel can tell whether the vendor's security
 * fixes are installed (truecms/sentinel#1409).
 *
 * Every value is best effort: one that cannot be read is left out, and no
 * collector throws. Sentinel shows a missing part as unknown.
 */
class PlatformCollector {

  /**
   * State key: SERVER_SOFTWARE of the last web request that reported one.
   *
   * Cron and Drush syncs have no web server, so they send this value. Holds
   * 'raw' (the value) and 'time' (when it was stored).
   */
  public const STATE_WEB_SERVER = 'sentinel_connector.web_server_software';

  /**
   * Seconds a stored web server value is kept before another may replace it.
   *
   * Web heads that report different values would otherwise write state on
   * every request.
   */
  public const WEB_SERVER_MIN_AGE = 3600;

  /**
   * Files read for the OS distribution, first readable one wins.
   */
  public const OS_RELEASE_FILES = ['/etc/os-release', '/usr/lib/os-release'];

  /**
   * Package databases read for the stack's packages, first readable one wins.
   */
  public const DPKG_STATUS_FILES = ['/var/lib/dpkg/status'];

  /**
   * Bytes of the package database read at most.
   */
  public const DPKG_STATUS_LIMIT = 32 * 1024 * 1024;

  /**
   * Source packages sent, at most; Sentinel ignores the rest.
   */
  public const PACKAGES_LIMIT = 20;

  /**
   * The only source packages sent: PHP, Apache, Nginx, MySQL, MariaDB and
   * PostgreSQL as Debian and Ubuntu name them. Nothing else leaves the site.
   */
  public const PACKAGE_PATTERN = '/^(php\d+\.\d+|apache2|nginx|mysql-\d+\.\d+|mariadb(-\d+\.\d+)?|postgresql-\d+(\.\d+)?)$/';

  /**
   * Length Sentinel keeps of a platform value.
   */
  public const TEXT_LIMIT = 50;

  /**
   * Length Sentinel keeps of the raw SERVER_SOFTWARE.
   */
  public const RAW_LIMIT = 255;

  /**
   * Database products named by the driver, when the version names none.
   */
  protected const DATABASE_PRODUCTS = [
    'mysql' => 'mysql',
    'pgsql' => 'postgresql',
    'sqlite' => 'sqlite',
  ];

  /**
   * Constructs a PlatformCollector.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   Holds the last web server software seen.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The current request, which has no web server on cron or Drush.
   * @param \Drupal\Core\Database\Connection $database
   *   The default database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param string[] $osReleaseFiles
   *   The os-release files to read, first readable one wins.
   * @param string[] $dpkgStatusFiles
   *   The package databases to read, first readable one wins.
   */
  public function __construct(
    protected StateInterface $state,
    protected RequestStack $requestStack,
    protected Connection $database,
    protected TimeInterface $time,
    protected array $osReleaseFiles = self::OS_RELEASE_FILES,
    protected array $dpkgStatusFiles = self::DPKG_STATUS_FILES,
  ) {}

  /**
   * Returns the payload's platform block, without the parts it cannot read.
   *
   * @return array<string, array<mixed>>
   *   Parts keyed php, web_server, database, os and packages.
   */
  public function collect(): array {
    $platform = [
      'php' => $this->php(),
      'web_server' => $this->webServer(),
      'database' => $this->database(),
      'os' => $this->os(),
      'packages' => $this->packages(),
    ];
    return array_filter($platform);
  }

  /**
   * The PHP version and server API running this code.
   *
   * @return array<string, string>
   *   The version and sapi keys.
   */
  public function php(): array {
    return $this->clean(['version' => PHP_VERSION, 'sapi' => PHP_SAPI]);
  }

  /**
   * The web server, from this request or the last web request seen.
   *
   * A hidden version (nginx with server_tokens off) gives the software only.
   *
   * @return array<string, string>
   *   The software, version and raw keys, each only when known.
   */
  public function webServer(): array {
    $raw = $this->rememberWebServer($this->requestStack->getCurrentRequest());
    $raw ??= $this->storedWebServer()['raw'];
    if ($raw === NULL) {
      return [];
    }
    // "nginx/1.24.0", "Apache/2.4.57 (Debian)", "nginx", "Microsoft-IIS/10.0".
    preg_match('#^([A-Za-z][^/\s]*)(?:/(\S+))?#', $raw, $match);
    return $this->clean([
      'software' => $match[1] ?? NULL,
      'version' => $match[2] ?? NULL,
      'raw' => $raw,
    ]);
  }

  /**
   * Stores the web server software of a request for later syncs.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   A request, which has no SERVER_SOFTWARE on the command line.
   *
   * @return string|null
   *   The request's SERVER_SOFTWARE, or NULL when it has none.
   */
  public function rememberWebServer(?Request $request): ?string {
    if ($request === NULL) {
      return NULL;
    }
    $raw = $this->text($request->server->get('SERVER_SOFTWARE'), self::RAW_LIMIT);
    if ($raw === NULL) {
      return NULL;
    }
    try {
      $stored = $this->storedWebServer();
      $now = $this->time->getCurrentTime();
      if ($stored['raw'] !== $raw && $stored['time'] <= $now - self::WEB_SERVER_MIN_AGE) {
        $this->state->set(self::STATE_WEB_SERVER, ['raw' => $raw, 'time' => $now]);
      }
    }
    catch (\Throwable) {
      // The value is still sent with this sync; only the cache is missing.
    }
    return $raw;
  }

  /**
   * The stored web server value and when it was stored.
   *
   * @return array{raw: string|null, time: int}
   *   The value, NULL when none is stored, and its time, 0 when unknown.
   */
  protected function storedWebServer(): array {
    try {
      $stored = $this->state->get(self::STATE_WEB_SERVER);
    }
    catch (\Throwable) {
      $stored = NULL;
    }
    return [
      'raw' => $this->text(is_array($stored) ? ($stored['raw'] ?? NULL) : NULL, self::RAW_LIMIT),
      'time' => is_array($stored) && is_int($stored['time'] ?? NULL) ? $stored['time'] : 0,
    ];
  }

  /**
   * The database driver, product and server version.
   *
   * MariaDB is recognised from its version string ("10.11.6-MariaDB").
   *
   * @return array<string, string>
   *   The driver, product and version keys, each only when known.
   */
  public function database(): array {
    try {
      $driver = $this->text($this->database->driver());
    }
    catch (\Throwable) {
      $driver = NULL;
    }
    try {
      $version = $this->text($this->database->version());
    }
    catch (\Throwable) {
      $version = NULL;
    }
    $product = $driver === NULL ? NULL : (self::DATABASE_PRODUCTS[$driver] ?? NULL);
    if ($version !== NULL && stripos($version, 'mariadb') !== FALSE) {
      $product = 'mariadb';
    }
    return $this->clean([
      'driver' => $driver,
      'product' => $product,
      'version' => $version,
    ]);
  }

  /**
   * The operating system family, and its distribution from os-release.
   *
   * @return array<string, string>
   *   The family, distribution, release and codename keys, each only when
   *   known.
   */
  public function os(): array {
    $release = $this->osRelease();
    return $this->clean([
      'family' => PHP_OS_FAMILY === 'Unknown' ? NULL : PHP_OS_FAMILY,
      'distribution' => $release['ID'] ?? NULL,
      'release' => $release['VERSION_ID'] ?? NULL,
      'codename' => $release['VERSION_CODENAME'] ?? NULL,
    ]);
  }

  /**
   * The installed builds of the stack's source packages, from dpkg.
   *
   * Only source packages that PACKAGE_PATTERN names are read, and only those
   * installed. The version is the source package's, which is what Ubuntu and
   * Debian publish fixes against. A source whose installed binaries disagree
   * (an upgrade half done) is left out rather than guessed.
   *
   * @return list<array{source: string, version: string}>
   *   One entry per source package, empty without a readable database.
   */
  public function packages(): array {
    $versions = [];
    foreach ($this->dpkgStanzas() as $fields) {
      if (!str_ends_with($fields['Status'] ?? '', ' installed')) {
        continue;
      }
      // "Source: php8.3 (8.3.6-0ubuntu0.24.04.3)" when the source version
      // differs from the binary's; just "Source: php8.3", or no Source at
      // all, when it is the same.
      $source = $fields['Package'] ?? '';
      $version = $fields['Version'] ?? '';
      if (isset($fields['Source']) && preg_match('/^(\S+)(?:\s+\((\S+)\))?$/', $fields['Source'], $match)) {
        $source = $match[1];
        $version = $match[2] ?? $version;
      }
      if (!preg_match(self::PACKAGE_PATTERN, $source) || $version === '') {
        continue;
      }
      $versions[$source][$version] = TRUE;
    }
    $packages = [];
    foreach ($versions as $source => $seen) {
      if (count($seen) !== 1) {
        continue;
      }
      $package = $this->clean(['source' => $source, 'version' => array_key_first($seen)]);
      if (count($package) === 2) {
        $packages[] = $package;
      }
      if (count($packages) === self::PACKAGES_LIMIT) {
        break;
      }
    }
    return $packages;
  }

  /**
   * Reads the first readable package database, one stanza at a time.
   *
   * Only the Package, Status, Source and Version fields are kept.
   *
   * @return \Generator<array<string, string>>
   *   Each stanza's fields keyed by name.
   */
  protected function dpkgStanzas(): \Generator {
    foreach ($this->dpkgStatusFiles as $file) {
      // open_basedir or a missing file makes these warn; neither is an error.
      $handle = @is_readable($file) ? @fopen($file, 'rb') : FALSE;
      if ($handle === FALSE) {
        continue;
      }
      try {
        $fields = [];
        $read = 0;
        while (($line = fgets($handle)) !== FALSE && $read < self::DPKG_STATUS_LIMIT) {
          $read += strlen($line);
          $line = rtrim($line, "\r\n");
          if ($line === '') {
            if ($fields) {
              yield $fields;
            }
            $fields = [];
          }
          elseif (preg_match('/^(Package|Status|Source|Version):\s*(.*)$/', $line, $match)) {
            $fields[$match[1]] = trim($match[2]);
          }
        }
        if ($fields) {
          yield $fields;
        }
      }
      finally {
        fclose($handle);
      }
      return;
    }
  }

  /**
   * Reads the first readable os-release file.
   *
   * @return array<string, string>
   *   Its values keyed by variable name, empty when none is readable.
   */
  protected function osRelease(): array {
    foreach ($this->osReleaseFiles as $file) {
      // open_basedir or a missing file makes these warn; neither is an error.
      $contents = @is_readable($file) ? @file_get_contents($file, FALSE, NULL, 0, 8192) : FALSE;
      if (!is_string($contents)) {
        continue;
      }
      $values = [];
      foreach (preg_split('/\R/', $contents) as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $match)) {
          $values[$match[1]] = trim($match[2], " \t\"'");
        }
      }
      return $values;
    }
    return [];
  }

  /**
   * Drops the values that are not text, cutting the rest to Sentinel's size.
   *
   * @param array<string, mixed> $values
   *   The part's values.
   *
   * @return array<string, string>
   *   The non-empty values.
   */
  protected function clean(array $values): array {
    $clean = [];
    foreach ($values as $key => $value) {
      $value = $this->text($value, $key === 'raw' ? self::RAW_LIMIT : self::TEXT_LIMIT);
      if ($value !== NULL) {
        $clean[$key] = $value;
      }
    }
    return $clean;
  }

  /**
   * A value as single-line text of at most the given length, or NULL.
   */
  protected function text(mixed $value, int $limit = self::TEXT_LIMIT): ?string {
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
      return NULL;
    }
    // Invalid UTF-8 cannot be sent as JSON.
    if (!mb_check_encoding((string) $value, 'UTF-8')) {
      return NULL;
    }
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value) ?? '');
    $value = trim(mb_substr($value, 0, $limit));
    return $value === '' ? NULL : $value;
  }

}
