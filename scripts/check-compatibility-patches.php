<?php

/**
 * @file
 * Verifies pinned patches and, optionally, an installed test scaffold.
 */

$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/patches/drupal12/patches.json'), TRUE, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['patches'] as $patches) {
  foreach ($patches as $patch) {
    if (hash_file('sha256', $root . '/' . $patch['url']) !== $patch['sha256']) {
      throw new RuntimeException('Compatibility patch checksum mismatch.');
    }
  }
}
if (isset($argv[1])) {
  $project = rtrim($argv[1], '/');
  $lock = json_decode(file_get_contents($project . '/patches.lock.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  foreach ($manifest['patches'] as $package => $patches) {
    foreach ($patches as $patch) {
      if (!in_array($patch['sha256'], array_column($lock['patches'][$package] ?? [], 'sha256'), TRUE)) {
        throw new RuntimeException('Compatibility patch missing from lock: ' . $package);
      }
    }
  }
  $contrib = $project . '/web/modules/contrib/';
  $subscriber = file_get_contents($contrib . 'config_split/src/EventSubscriber/SplitImportExportSubscriber.php');
  if (!str_contains($subscriber, 'function getSubscribedEvents(): array')) {
    throw new RuntimeException('Config Split compatibility patch is not applied.');
  }
  if (!is_file($contrib . 'key/src/Attribute/KeyProvider.php')) {
    throw new RuntimeException('Key attribute compatibility patch is not applied.');
  }
}
fwrite(STDOUT, "Compatibility patch checks passed.\n");
