# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- `drush sentinel_connector:deploy` (alias `sc-deploy`) for CI/CD pipelines.
  Run it as the last step of a production deployment. It pushes only when
  the inventory differs from the last push Sentinel accepted; `--force`
  pushes regardless. A plan limit or an unconfigured site does not fail the
  command.
- Every accepted push stores a hash of the inventory in state
  (`sentinel_connector.last_fingerprint`). The IP address is not part of it;
  the API base URL is.

## 0.3.0 - 2026-10-09

### Added

- Sentinel's plan push limit (HTTP 429 with `error_code: push_limit_reached`)
  is recognised as an expected outcome. "Sync now" and the Drush command show
  a warning with Sentinel's message and the next allowed time in the site's
  time zone; cron stays silent. Every rejection is logged at notice level.
- The next allowed time is stored in state. Nothing is sent before it, and the
  settings form shows it.
- A push refused because the subscription is overdue, unpaid or paused (HTTP
  402 with `error_code: subscription_inactive`) is its own result. A manual
  push shows an error and the Drush command fails; cron logs a warning and
  tries at most once in 24 hours until a push is accepted.
- The status report has a Sentinel Connector entry: an error for incomplete
  configuration, a billing refusal or no accepted push in 24 hours; a warning
  for a new site that has not pushed; information while the plan limit holds
  the next push.

### Changed

- Cron sends at most one push an hour, fixed in code. A manual push is not
  bound by this floor.
- The API key field on the settings form is labelled **API key** (or **Replace
  API key** once one is set) and says which credential it is. The status line
  names the active source and says when `settings.php` or the environment
  variable takes precedence over the key saved on the form; the field then
  says a key saved there is not in use. A saved key is never sent back to the
  browser.

### Removed

- The `cron_interval` setting. Update `10001` deletes the stored value.
- The **Site token** field and the `site_token` setting. Sentinel ignores the
  token; the API key is the only credential. Update `10002` deletes the stored
  value and leaves the API key unchanged.

### Upgrade notes

- Run `drush updb`. Sites that keep configuration in code must export it
  afterwards, or the next import puts the unused `cron_interval` and `site_token` keys
  back.
- Sites that pushed less often than hourly now push hourly from cron. Sentinel
  refuses what the plan does not allow, and the module then waits.

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
