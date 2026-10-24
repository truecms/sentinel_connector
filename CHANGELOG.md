# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org/).

## 1.1.0 - 2026-10-24

### Added

- The sync payload is `schema_version: 2` and has a `platform` block with the
  PHP version and server API, the web server, the database driver, product
  and version, and the operating system family, distribution and release.
  Sentinel matches these against known vulnerabilities. A value the module
  cannot read is left out; it never fails a push.
- The web server comes from `SERVER_SOFTWARE`. Cron and Drush have none, so
  web requests store it in state (`sentinel_connector.web_server_software`)
  when it changed, at most once an hour, and later pushes send the stored
  value. Page cache hits do not touch state.
- A new web server or database version counts as an inventory change for cron
  and `drush sentinel_connector:deploy`. The `php` and `os` parts do not,
  because they differ between the web and the command line; the daily resend
  reports them.

## 1.0.0 - 2026-10-16

### Added

- Drupal.org GitLab CI for Drupal 10 and 11. Both PHPUnit jobs are required.
- Production-only `settings.php` examples that block cron and manual API
  reports on development and staging, including after a database copy.
- A Kernel regression test for the environment overrides.
- GPL license text and release archive exclusions for development files.

### Changed

- Use the `drupal/sentinel_connector` Composer package name for Drupal.org.
- Support the PHPStan Drupal extension versions needed by both core versions.
- Declare separate processes for Kernel tests on newer Drupal test runners.

## 0.5.0 - 2026-10-14

### Added

- `drush sentinel_connector:deploy` (alias `sc-deploy`) for CI/CD pipelines.
  Run it as the last step of a production deployment. It pushes only when
  the inventory differs from the last push Sentinel accepted; `--force`
  pushes regardless. A plan limit or an unconfigured site does not fail the
  command.
- Every accepted push stores a hash of the inventory in state
  (`sentinel_connector.last_fingerprint`). The IP address is not part of it;
  the API base URL is. The hash is trusted for 24 hours; after that a push is
  sent whether or not anything changed.

- Every push writes one entry to the Drupal log on the `sentinel_connector`
  channel, with the trigger (cron, form or Drush), the outcome, the HTTP
  status, Sentinel's message and the task ID of a queued push. The README
  lists the outcomes and their log levels.
- Sentinel's `error_code` decides the outcome of a refused push. Each code of
  the sync endpoint's error contract maps to one of the existing outcomes
  (the README lists them), on any error status and whatever the wording of
  the message. The log entry has the code: `error code <code>`, or
  `unknown error code <code>` for a code this release does not know.
- `push_limit_reached` and `subscription_inactive` still count only on a 429
  and a 402, because they hold back later pushes. On another status the
  response is classified by its status and the log entry has
  `ignored error code <code>`.
- A response without an `error_code`, or with an unknown one, is classified by
  its HTTP status and body as before, so older Sentinel servers and reverse
  proxies that answer with HTML are still supported.

- The settings form validates the Site UUID. It trims the value, removes
  surrounding braces, lowers upper case and adds the hyphens to 32 hexadecimal
  digits; anything that is not a UUID is rejected with an error. Sentinel
  accepts only the lower-case hyphenated form and refuses any other as if the
  API key were wrong.
- A Site UUID already stored in another notation is sent in the canonical
  form, in the request path and in the payload, without a re-save. A stored
  value that is not a UUID is not sent: the push ends as `unconfigured` with a
  message naming the Site UUID, and the status report shows the site as not
  configured.

### Changed

- "Sync now" sits next to "Save configuration" and is hidden until the saved
  settings hold the API base URL, a valid Site UUID and an API key.
- Sentinel's error responses have their own outcomes instead of `rejected`:
  `site_mismatch` and `validation_failed` (400), `signature_refused` (401),
  `not_found` (404),
  `conflict` (409), `invalid_payload` (422) and `unavailable` (503). A
  structured error is shown as a sentence, never as JSON. A rate limit shows
  Sentinel's own message instead of a fixed "100/hour".
- With a known `error_code`, the text shown and logged is Sentinel's `message`
  rather than `detail`, without the connector's own advice. The rejected
  modules, the first field at fault and the wait follow it as sentences of
  their own, and the whole text is at most 500 characters.
- The `Retry-After` wait is shown by outcome: for `rate_limited`, and with a
  known `error_code` also for `unavailable`.
- `Retry-After` counts only as a positive number of seconds or as an HTTP date
  in the future. A relative phrase, zero, a negative number or a past date is
  ignored, also for the stored time of a push limit.
- Values Sentinel sends are no longer accepted with a trailing newline. This
  also applies to responses without an `error_code`: a 202 whose `task_id`
  ends in a newline is now `invalid_response`; a 401 whose `detail.code` ends
  in a newline is now `auth_error` without the code in the log detail; a
  `reason` or `plan` that ends in a newline is dropped.
- The log detail of a refused signature reads `error code timestamp_skew`
  when Sentinel sends an `error_code`; before it was `code timestamp_skew`.
- `SentinelClient` takes the time service as an optional second argument. Its
  protected `errorBody()` and `pushLimit()` have new signatures: `errorBody()`
  takes the decoded body and returns the object with a valid `error_code`,
  `pushLimit()` takes that object and the parsed `Retry-After`. Subclasses
  that override them need updating.
- A 200 shows Sentinel's `message` only when it is a non-empty string;
  otherwise "Sync completed."
- `SentinelClient` no longer takes a logger; `SyncService::sync()` takes the
  trigger of the push.
- Cron pushes only when the inventory changed since the last push Sentinel
  accepted, and once every 24 hours otherwise. It still checks at most once an
  hour. Before, it pushed every hour and used up the plan's push limit.
  "Sync now" and `drush sentinel_connector:sync` always push.
- The status report stays OK while cron finds no change: a check in the last
  24 hours keeps an older accepted push current, and the entry shows both
  times.

## 0.4.0 - 2026-10-10

### Changed

- Sub-modules are no longer sent. A module whose directory is inside another
  module's directory is folded into that module, which reports as enabled when
  it or any of its sub-modules is enabled. Modules shipped inside an install
  profile are still listed.
- Core modules are no longer sent; core is reported by
  `drupal_info.core_version` alone. The `all` and `contrib_custom` report
  scopes now list the same modules.

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
