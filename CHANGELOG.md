# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org/).

## 1.5.0 - Unreleased

Keeps payload `schema_version: 4` and adds an optional block to it. Connector
1.4.0 sends version 4 without the block. A Sentinel that does not yet know the
block refuses a push that carries it, so deploy truecms/sentinel#1349 first.

### Added

- An optional `upgrade_status` block with the Drupal upgrade readiness
  Upgrade Status stored: a status (`ready`, `not_ready`, `incomplete`,
  `not_scanned`, `unavailable`), the count of projects at each next step, the
  custom projects that need code changes and the newest and oldest scan dates.
  Counts only: no project names, paths or messages. Sent only while Upgrade
  Status is enabled; a failed read sends `unavailable` with a reason and never
  fails the push.
- The block is part of the push fingerprint, except its `generated_at`, so a
  new scan result is pushed by the next cron run.
- `drush sentinel_connector:upgrade-status` scans custom projects with
  Upgrade Status, then syncs. Opt-in; it never runs on cron. With the off
  switch set it exits 0 and scans nothing. A project whose scan failed fails
  the command, and nothing is pushed.
- An Integrations section on the settings form with Upgrade Status and
  Config PR rows, and **Report Upgrade Status readiness to Sentinel**
  (`report_upgrade_status`), on by default and overridable with
  `$settings['sentinel_connector.report_upgrade_status']`. The section starts
  closed until Upgrade Status is enabled. Update `10006` turns reporting on for
  existing sites; export configuration afterwards (`drush cex`).

### Requires

- A Sentinel release with the `upgrade_status` block
  (truecms/sentinel#1349), deployed before upgrading clients.

## 1.4.0 - 2026-10-30

Payload v4. Needs a Sentinel release that accepts payload v4 and serves the
connection check route (truecms/sentinel#1613, deployed 30 October 2026).

### Added

- Settings for several environments (#45). The API base URL, the API key, the
  Site UUID and a new off switch can come from `settings.php`
  (`sentinel_connector.api_base_url`, `.api_key`, `.site_uuid`, `.disabled`)
  or the environment (`SENTINEL_CONNECTOR_API_BASE_URL`, `_API_KEY`,
  `_SITE_UUID`, `_DISABLED=1`). `settings.php` wins over the environment, the
  environment over the form. The form shows overridden values read-only with
  their source.
- The API base URL defaults to `https://ingest.sentinelconnector.io` and is
  validated: `https://` only (`http://` for `localhost` and `*.localhost`), no
  path, query, fragment or credentials, and its own message for a pasted sync
  endpoint. An invalid value is refused, never replaced by the default.
- The off switch stops cron, `drush sentinel_connector:sync` and `:deploy`
  (which exit 0) and "Sync now".
- "Test connection" on the settings form and `drush sentinel_connector:check`
  (alias `sc-check`) call Sentinel's connection check route and give one plain
  answer: connected (with the Sentinel environment), not a Sentinel API, Site
  UUID not known there, API key refused, or a subscription or push limit. The
  status report warns while the last check failed.
- **Report platform versions** (`report_platform`), on by default. Off, the
  stack is not read and no `platform` block is sent; the PHP version is still
  sent. The payload always says `platform_reporting: "enabled"` or
  `"disabled"`.
- `examples/settings.sentinel_connector.php`, a commented include file for
  production, non-production, local and CI.
- Update `10005` removes `api_base_url`, `site_url` and `site_name`, maps the
  report scope (`contrib_custom` to `all`, `contrib` to `core_contrib`) and
  turns on `report_platform`. A saved base URL that was not the production
  default is logged as a warning, and nothing is sent until `settings.php` or
  the environment sets the URL: the site is never moved to production.
- Sync and connection check requests no longer follow redirects, so the API
  key never reaches another host.

- The API key can be stored with the [Key](https://www.drupal.org/project/key)
  module (optional): when Key is enabled, the settings form has a key selector
  with links to add and manage keys, and stores only the key id
  (`api_key_id`). Keys whose provider keeps the value in configuration are
  refused. Sources are resolved in this order: `settings.php`, the selected
  key, the `SENTINEL_CONNECTOR_API_KEY` environment variable, then the key
  saved in State.
- Update `10004`, and enabling the Key module later, move a key saved in State
  into a new key `sentinel_connector_api_key` and select it. That key uses
  Key's State provider, so the value is still in the database; its label says
  this is not recommended. Change its provider to a file, an environment
  variable or a vault. Export configuration afterwards (`drush cex`).

### Changed

- Payload `schema_version` 4. The `site` block holds only the `uuid`: the
  site URL and name are no longer sent.
- Report scope has two values: `all` (core, contrib and custom) and
  `core_contrib` (custom modules left out).
- The settings form asks only for the Site UUID, the API key, the report
  scope and the two reporting checkboxes.

### Removed

- The API base URL, Site URL and Site name fields and settings.

### Deprecated

- Saving the API key in State. Without the Key module the form still offers
  the field, marked as deprecated. Selecting a usable key deletes the State
  value.

## 1.3.3 - 2026-10-29

### Added

- The `os` part sends `codename` (`VERSION_CODENAME` from os-release, such
  as `noble` or `bookworm`).
- A new `packages` part lists the installed builds of the stack's own
  source packages, read from the Debian and Ubuntu package database
  (`/var/lib/dpkg/status`): PHP (`php8.3`), Apache (`apache2`), Nginx,
  MySQL (`mysql-8.0`), MariaDB and PostgreSQL (`postgresql-16`), as
  `{"source", "version"}`, at most 20. Nothing else from the database is
  sent. Sentinel uses them to tell whether Ubuntu's or Debian's security
  fixes are installed (truecms/sentinel#1409). A source whose installed
  packages disagree on the version is left out, and a server without a
  readable package database sends no `packages` part. Sentinel releases
  before #1409 ignore both fields.

## 1.3.2 - 2026-10-28

### Fixed

- A module installed from a numeric Composer branch, such as `dev-3.x` or
  `dev-3.2.x`, is reported in Drupal's syntax, `3.x-dev` or `3.2.x-dev`.
  Sentinel can read these versions. Releases, pre-releases such as
  `1.0.0-rc1`, and other branch names such as `dev-main` are sent unchanged.

## 1.3.1 - 2026-10-28

### Changed

- A module version is sent without surrounding whitespace.
- A refusal over invalid modules (`validation_failed`) names each rejected
  entry with its result, such as `invalid_name`, and the version sent, up to
  five entries and "and N more" in the message and log.
  `drush sentinel_connector:sync` lists every entry.

### Added

- Sentinel stores a version it cannot read as an unknown version and lists the
  module in `unresolved_versions` (truecms/sentinel#1521). The connector now
  shows these modules as a warning after "Sync now", in the Drush output and
  in the log entry, which is written at warning level. A Sentinel without the
  list shows nothing new.

## 1.3.0 - 2026-10-27

### Added

- The sync payload is `schema_version: 3` and has a `config_drift` block: the
  names of configuration objects that differ between production and the
  configuration sync directory, their collection and the type of change
  (`create`, `update`, `delete`, `rename`). Configuration values are never
  sent. The export storage is compared, so Config Ignore and Config Split are
  respected, as in `drush config:status`.
- The list holds at most 200 objects with an exact `total` and a `truncated`
  flag. The comparison has a 10-second budget (`status: incomplete`); a
  missing or unreadable sync directory, or any failure, is reported as
  `status: unavailable` with a reason and never fails the push.
- `config_pr_enabled` says whether the Config Pull Request module is enabled.
- A "Report configuration drift" checkbox on the settings form, on by default.
  Update `10003` turns it on for existing sites; export configuration
  afterwards (`drush cex`).
- Drift is part of the push fingerprint: a change in drift is pushed by the
  next cron run or `drush sentinel_connector:deploy`.
- Optional submodule `sentinel_connector_config_pr`: opens one configuration
  pull request (GitHub) or merge request (GitLab) through `drupal/config_pr`,
  from `drush sentinel_connector:config-pr` or cron (off by default). It never
  merges. Its open pull request is added to the drift block as
  `export_pull_request`.

### Fixed

- A contrib module installed from git or a source checkout has no `version`
  in its info.yml and was reported as `0.0.0`, so Sentinel could not assess
  it. The connector now reports the version Composer recorded for the
  package, when the module is the root of a Composer package of the same
  project. It is Composer's record, not a reading of the files: a checkout
  moved by hand after `composer install` still reports the installed version.
  Symlinked path-repository packages and modules nested in another package,
  such as inside a profile, still report no version.

### Requires

- A Sentinel server that accepts payload `schema_version: 3`, deployed before
  upgrading clients. See [RELEASE.md](RELEASE.md) for the staged rollout.
- Release 1.2.0 already shipped Drupal 12 compatibility with payload v2.
  This follow-on release adds configuration drift and the optional configuration
  pull request submodule; it must not replace the published 1.2.0 tag.

## 1.2.0 - 2026-10-25

### Changed

- Support Drupal `^10.3 || ^11 || ^12`. Drupal 12 is tested against the
  current beta release and requires PHP 8.5.
- Use object-oriented cron and runtime requirement hooks on newer Drupal
  versions, with backward-compatible entry points for Drupal 10.3.
- Report status severity using the enum required by Drupal 12, while
  retaining integer severity values on Drupal 10.
- Discover the sync and deployment commands with current Drush versions,
  while retaining Drush 12 support.
- Preserve safe connection diagnostics with Guzzle 7 and Guzzle 8.
- Run the unit, contract and Kernel tests on all three Drupal major versions,
  with test metadata compatible with their PHPUnit versions.

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
