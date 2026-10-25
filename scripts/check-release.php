<?php

/**
 * @file
 * Checks release identity without a Drupal or Composer installation.
 *
 * Run before publication: php scripts/check-release.php 1.3.0
 * With no argument, permits an Unreleased changelog heading for CI.
 */

$root = dirname(__DIR__);
$source = file_get_contents($root . '/src/PayloadBuilder.php');
$changelog = file_get_contents($root . '/CHANGELOG.md');
$schema = json_decode(file_get_contents($root . '/tests/fixtures/drupal_sync.schema.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$errors = [];
preg_match("/CONNECTOR_VERSION = '([0-9]+\\.[0-9]+\\.[0-9]+)'/", $source, $versionMatch);
preg_match('/SCHEMA_VERSION = ([0-9]+)/', $source, $schemaMatch);
preg_match('/^## ([^\r\n]+)\r?$/m', $changelog, $firstHeading);
preg_match('/^([0-9]+\\.[0-9]+\\.[0-9]+) - (Unreleased|[0-9]{4}-[0-9]{2}-[0-9]{2})$/D', $firstHeading[1] ?? '', $heading);
$version = $versionMatch[1] ?? '';
$payloadVersion = (int) ($schemaMatch[1] ?? 0);
if (!$version || ($heading[1] ?? '') !== $version) {
  $errors[] = 'The first changelog release must match CONNECTOR_VERSION.';
}
// Published 1.2.0 is immutable and carries schema v2. Drift starts at 1.3.0.
if ($payloadVersion >= 3 && version_compare($version ?: '0.0.0', '1.3.0', '<')) {
  $errors[] = 'Payload v3 requires connector 1.3.0 or later; preserve published 1.2.0.';
}
if (max($schema['properties']['schema_version']['enum'] ?? [0]) !== $payloadVersion) {
  $errors[] = 'The payload contract fixture must match SCHEMA_VERSION.';
}
if (!str_contains(file_get_contents($root . '/README.md'), '## Upgrading to ' . $version . "\n")) {
  $errors[] = 'README upgrade instructions must identify the current release.';
}
foreach (['sentinel_connector.info.yml', 'modules/sentinel_connector_config_pr/sentinel_connector_config_pr.info.yml'] as $infoFile) {
  if (!is_readable($root . '/' . $infoFile)) {
    $errors[] = $infoFile . ' must be present and readable.';
    continue;
  }
  $info = file_get_contents($root . '/' . $infoFile);
  if (preg_match('/^version: *[\x27\x22]?([^\s\x27\x22]+)/m', $info, $packagedVersion) && $packagedVersion[1] !== $version) {
    $errors[] = $infoFile . ' has a different packaged version.';
  }
}
if (isset($argv[1])) {
  if ($argv[1] !== $version) {
    $errors[] = 'The proposed release tag must match CONNECTOR_VERSION.';
  }
  if (($heading[2] ?? '') === 'Unreleased') {
    $errors[] = 'Date the changelog before tagging a release.';
  }
}
if ($errors) {
  fwrite(STDERR, implode("\n", $errors) . "\n");
  exit(1);
}
fwrite(STDOUT, 'Release consistency passed: ' . $version . ', schema ' . $payloadVersion . ".\n");
