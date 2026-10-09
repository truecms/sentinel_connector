# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- Sentinel's plan push limit (HTTP 429 with `error_code: push_limit_reached`)
  is recognised as an expected outcome. "Sync now" and the Drush command show
  a warning with Sentinel's message and the next allowed time in the site's
  time zone; cron stays silent. Every rejection is logged at notice level.
- The next allowed time is stored in state. Nothing is sent before it, and the
  settings form shows it.

### Changed

- New installs push once a day (`cron_interval: 86400`, was 21600). Existing
  installs keep their saved interval; no update hook changes it.

## 0.2.0 - 2026-10-07

### Added

- Payloads send `inventory_scope`, and `reported_project` and `version_known`
  for every module. Release `0.1.0` sent none of these, so Sentinel reported
  every contrib module as "missing project".
- When `info.yml` has no `project` key, the project is resolved from the
  installed `drupal/*` Composer package that contains the module. Sub-modules
  resolve to their parent project.
- Payloads send `connector_version`, so Sentinel can tell when a site runs an
  outdated connector. Sentinel ignores the field until it adds support.

### Changed

- A module inside a `drupal/*` Composer package is always reported as
  `contrib`, even when its project cannot be resolved. It was reported as
  `custom` before, which would hide it from Sentinel's coverage check.
- Sentinel dashboard API key guidance, last-attempt reporting and branding
  updates made since `0.1.0`.

### Upgrade notes

- Update the module, run `drush cr`, then `drush sentinel_connector:sync` (or
  use "Sync now").
- No configuration or schema change is needed.

## 0.1.0 - 2026-06-13

- Initial release.
