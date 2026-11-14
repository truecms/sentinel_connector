# Sentinel Connector

A Drupal 10/11/12 module that reports a site's installed extensions and environment
to the [Sentinel Connector](https://sentinelconnector.io/) monitoring platform.
Sentinel computes update and security status; this module only collects an
authoritative snapshot and submits it.

## Website, accounts and documentation

[SentinelConnector.io](https://sentinelconnector.io/) provides the monitoring
service. A [Free account](https://sentinelconnector.io/register?plan=free) and a
14-day trial are available. The Free plan monitors one Drupal website and
accepts one data update a day. See [plans and pricing](https://sentinelconnector.io/pricing)
for included features and applicable trial terms. The Drupal module remains
free under its GPL license.

- [User guide](https://sentinelconnector.io/help)
- [Add and connect a Drupal website](https://sentinelconnector.io/help#help-sites-add-connect-and-inspect-drupal)
- [Create and manage API keys](https://sentinelconnector.io/help#help-api-keys)
- [Troubleshooting](https://sentinelconnector.io/help#help-when-something-does-not-work)

## Project and distribution

The public module name is **Sentinel Connector** and the machine name is
`sentinel_connector`. Get releases from the
[Drupal.org project page](https://www.drupal.org/project/sentinel_connector).

To install it, see [Installation](#installation). A Sentinel account, a
registered site and its API key are required to send reports. The module does
not include the Sentinel service. Review [What it sends](#what-it-sends)
before you enable reporting.

The module is licensed under GPL-2.0-or-later; see [LICENSE.txt](LICENSE.txt).

## Requirements

- Drupal `^10.3 || ^11 || ^12`
- PHP `>= 8.3`, subject to the minimum PHP version required by Drupal core.
  Drupal 12 requires PHP 8.5. Drupal 12 compatibility is tested against the
  current prerelease; rerun the test matrix when updating core.

Drush 12.5 or later is required to use the optional command-line commands.

Drupal 12 sites using Config Split or the optional Key integration need compatible
upstream versions. CI exercises their runtime paths with explicit, checksum-pinned upstream
patches and a narrow Composer Lenient allow-list. This does not establish site
installation/update compatibility: Config Split and Config Ignore also need
upstream Drupal 12 module metadata. See
[optional integration prerequisites](patches/drupal12/README.md); installing this
module does not automatically patch those dependencies.

## Recommended modules

- [Key](https://www.drupal.org/project/key): stores the Sentinel API key in a
  file, an environment variable or a vault instead of the database.
- [Upgrade Status](https://www.drupal.org/project/upgrade_status): when it is
  enabled, the connector reports Drupal upgrade readiness; see
  [Drupal upgrade readiness](#drupal-upgrade-readiness-upgrade-status-integration).
- [Config PR](https://www.drupal.org/project/config_pr): needed by the
  optional `sentinel_connector_config_pr` submodule; see
  [Configuration pull request](#configuration-pull-request-optional-submodule).

## Installation

Install the module as you would normally install a contributed Drupal module.
See [Installing modules](https://www.drupal.org/docs/extending-drupal/installing-modules)
for more information.

1. From the Drupal project root, add the module with Composer:

   ```bash
   composer require drupal/sentinel_connector
   ```

2. Enable it on **Extend** (`/admin/modules`), or with Drush:

   ```bash
   drush en sentinel_connector
   ```

3. Give the *Administer Sentinel Connector* permission only to trusted roles;
   see [Permissions](#permissions).
4. In Sentinel, register the site and create its API key. Then enter the Site
   UUID and the API key at **Configuration → Web services → Sentinel
   Connector** (`/admin/config/services/sentinel-connector`), or set them in
   `settings.php`; see [Configuration](#configuration).
5. Choose **Test connection**, then **Sync now**, or run:

   ```bash
   drush sentinel_connector:check
   drush sentinel_connector:sync
   ```

6. Export configuration (`drush cex`). The API key is never exported.

After that, cron sends a report when something changed; see
[Push frequency](#push-frequency).

The optional submodule `sentinel_connector_config_pr` is enabled separately;
see [Configuration pull request](#configuration-pull-request-optional-submodule).

## Upgrading

Run `drush updb` after every update, then export configuration
(`drush cex`). [UPGRADE.md](UPGRADE.md) lists the steps of each release, and
[CHANGELOG.md](CHANGELOG.md) what changed.

## What it sends

A `POST` to `{api_base_url}/api/v1/sites/{site_uuid}/modules/sync` with an
`X-API-Key` header, containing the site UUID, Drupal/PHP/IP environment, and the
list of installed modules (machine name, display name, type, enabled, version,
description). Every sync is a full snapshot (`full_sync: true`).

The payload is `schema_version: 5`. The `site` block holds only the `uuid`:
the site URL and name are not sent. `platform_reporting` and
`status_reporting` are always sent, `"enabled"` or `"disabled"`, so Sentinel
can tell a site that turned reporting off from one that could not read it.

With **Report platform versions** on (the default), the `platform` block
reports the stack Sentinel matches against known vulnerabilities. Turned off,
the stack is not read and no `platform` block is sent;
`drupal_info.php_version` is still sent, because Drupal core support depends
on it.

The optional `upgrade_status` block is described in
[Drupal upgrade readiness](#drupal-upgrade-readiness-upgrade-status-integration),
the `status_report` block in
[Drupal status report](#drupal-status-report-errors-and-warnings).

| Part | Fields | Source |
|------|--------|--------|
| `php` | `version`, `sapi` | `PHP_VERSION`, `PHP_SAPI` |
| `web_server` | `software`, `version`, `raw` | `SERVER_SOFTWARE` of a web request |
| `database` | `driver`, `product`, `version` | The Drupal database connection; `product` is `mariadb` when the version says MariaDB |
| `os` | `family`, `distribution`, `release`, `codename` | `PHP_OS_FAMILY`; `ID`, `VERSION_ID` and `VERSION_CODENAME` from `/etc/os-release` |
| `packages` | list of `source`, `version` | Installed source packages from `/var/lib/dpkg/status`, only PHP (`php8.3`), `apache2`, `nginx`, MySQL (`mysql-8.0`), MariaDB (`mariadb`, `mariadb-10.11`) and PostgreSQL (`postgresql-16`); at most 20 |

Cron and Drush have no web server, so the module keeps the `SERVER_SOFTWARE`
of the last web request in state (`sentinel_connector.web_server_software`)
and sends that. A stored value is replaced at most once an hour, so web heads
that report different values do not write state on every request. With the
version hidden (`server_tokens off`) `version` is left out. The `php` and `os`
parts describe the process that sends the push: on cron or Drush that is the
command-line PHP. A value the module cannot read is left out and never fails
the sync; Sentinel shows it as unknown. `packages` is read only on Debian and
Ubuntu (a readable `/var/lib/dpkg/status`); the version is the source
package's, and a source whose installed packages disagree is left out.

### Configuration drift

The `config_drift` block says which configuration objects differ between the
production site and the configuration sync directory (the code). It is what
`drush config:status` reports: the export storage (active configuration after
export transformations, so Config Ignore, Config Split and
`config_exclude_modules` are respected) is compared with the sync directory,
in every collection, such as the `language.fr` translation overrides.

**Configuration values are never sent**: only the object name, its collection
(`""` for the default collection) and the type of change.

| Change | Meaning |
| --- | --- |
| `create` | Only in production |
| `update` | In both, changed in production (also an object recreated under the same name) |
| `delete` | Only in code |
| `rename` | Renamed in production; reported under the production name |

```json
"config_drift": {
  "status": "complete",
  "reason": null,
  "generated_at": "2026-11-02T03:04:05Z",
  "total": 3,
  "config_pr_enabled": false,
  "truncated": false,
  "objects": [
    {"name": "system.site", "collection": "", "change": "update"},
    {"name": "views.view.content", "collection": "language.fr", "change": "create"}
  ]
}
```

- `status` is `complete`, `incomplete` (the 10-second time budget ran out; the
  collections compared so far are listed) or `unavailable`, with `reason`
  `sync_directory_missing`, `sync_unreadable`, `time_budget`, `error` or
  `disabled`. `reason` is `null` when complete.
- `objects` holds at most 200 entries, sorted by collection then name.
  `total` is the full count and `truncated` says the list was cut.
- `config_pr_enabled` is `true` when the Config Pull Request (`config_pr`)
  module is enabled. It is sent with every status.
- `export_pull_request` (`{"url": …, "updated_at": "2026-11-02T03:04:05Z"}`)
  is added when the `sentinel_connector_config_pr` submodule has a
  configuration pull request open. It is read from State, with no call to the
  Git host. A new or updated pull request changes the push fingerprint, so the
  next cron run sends it.
- A failure in the comparison never fails the push.
- The drift list is part of the push fingerprint, so a change in drift is
  pushed by the next cron run or `drush sentinel_connector:deploy`. Run
  `drush sentinel_connector:deploy` as the last step of each production
  deployment so drift clears promptly after `drush cim`. A change past the
  first 200 objects counts only through `total`; the daily resend reports it.
  A comparison that is cut short or fails (for example while `drush cex`
  holds the export lock, which is never waited for) does not count as a
  change on its own.

Turn it off with **Report configuration drift** on the settings form
(`report_config_drift: false`); the block is then sent as `unavailable` with
`reason: disabled`.

### Drupal upgrade readiness (Upgrade Status integration)

When the contrib module [Upgrade Status](https://www.drupal.org/project/upgrade_status)
is enabled on the site the connector reports from, Sentinel receives its
result and shows whether the site is ready for the next major Drupal version.
The connector does not need Upgrade Status, and nothing is sent without it.
Upgrade Status is often installed on a development or staging environment
only; the connector reports from wherever it runs.

The `upgrade_status` block holds counts only: how many projects are at each
Upgrade Status next step and when they were scanned. **Project names, paths
and messages are never sent.**

```json
"upgrade_status": {
  "module_version": "4.3.10", "current_major": 11, "target_major": 12,
  "status": "not_ready", "projects_total": 84,
  "counts": {"relax": 76, "update": 3, "collaborate": 1, "rector": 2,
             "manual": 1, "remove": 1, "scan": 0},
  "custom_not_ready": 1, "reason": null,
  "newest_scan_at": "2026-10-20T03:12:00Z",
  "oldest_scan_at": "2026-10-12T22:40:00Z",
  "oldest_custom_scan_at": "2026-10-18T01:05:00Z",
  "generated_at": "2026-10-25T01:00:00Z"
}
```

`status` follows these rules, in order:

| Status | Rule |
| --- | --- |
| `not_ready` | At least one project is `update`, `collaborate`, `rector` or `manual` |
| `not_scanned` | There is installed custom code, every installed custom project is `scan` and none has a stored result |
| `incomplete` | Some projects are `scan` |
| `ready` | Every project is `relax` or `remove` |
| `unavailable` | Upgrade Status could not be read (`reason`: `service_missing`, `unsupported_api`, `unexpected_data` or `error`); one warning is logged and the push goes ahead |

- Contributed projects get their next step from release data without a scan,
  so a site without custom code can be `ready` without one.
- `remove` (code present, module not enabled) is a warning only: it never
  makes a site not ready.
- `custom_not_ready` counts custom projects at `rector` or `manual`.
- Scan dates come only from stored results of projects still on the site.
- The block is part of the push fingerprint, except `generated_at`: a new
  scan result is pushed by the next cron run, an unchanged one is not.
- The hosting environment checks of Upgrade Status (PHP, database, web
  server) are not reported.

The connector never scans on cron or during a push. A scan runs PHPStan over
custom code: it takes minutes and uses CPU, so run it in a quiet window:

```bash
# Scan custom projects with Upgrade Status, then sync. Fails, with no push, if
# the scan or the scan of any custom project fails.
drush sentinel_connector:upgrade-status

# The same in two steps.
drush upgrade_status:analyze --all --ignore-contrib
drush sentinel_connector:sync
```

With the [off switch](#several-environments) set, the command exits 0 and scans
nothing.

#### Full scan in CI (`--scan-report`)

A full scan can run in your own CI pipeline instead of on production, with
your site image and a sanitised copy of your database. Sentinel's
`truecms/sentinel-upgrade-scan` action runs the scan, then calls:

```bash
started=$(date +%s)
# Exit code 3 means the scan found problems: that is a result, not a failure.
drush upgrade_status:analyze --all || [ $? -eq 3 ]
SENTINEL_SCAN_KEY=sk_scan_... drush sentinel_connector:upgrade-status --scan-report --scan-started="$started"
```

- It runs no scan. It reads the result Upgrade Status stored and sends it to
  Sentinel's CI scan route.
- The only credential is the site's scan key, from the site's Setup tab in
  Sentinel, in `SENTINEL_SCAN_KEY`. Keep it in your CI secrets. It is never
  printed. The connector API key is not needed.
- The Site UUID and the API base URL come from the usual settings, for
  example `SENTINEL_CONNECTOR_SITE_UUID`.
- It ignores the off switch and the reporting switch, so it works in a build
  that shares `settings.php` with production.
- It sends counts only, like a push.
- A custom project with no good scan since `--scan-started` (a failed scan,
  or a result older than this scan) is not sent as a result: Sentinel is told
  the scan did not finish (`scan_failed`) and the command fails. Without
  `--scan-started` only failed and missing scans are caught.
- With `--scan-started`, the report and any failure carry that time as
  `generated_at`, so Sentinel orders CI jobs that finish out of order by when
  they started.
- Exit codes: 0 when Sentinel accepted the scan, or when Sentinel does not
  take CI scans for the site yet. Not 0 when the key is missing or refused,
  the result cannot be read (Sentinel is told the scan failed), or Sentinel
  refuses the scan.
- Needs connector 1.6.0 or later in the build.

Turn reporting off with **Report Upgrade Status readiness to Sentinel** in the
Integrations section of the settings form (`report_upgrade_status: false`), or
in `settings.php`, which takes precedence:

```php
$settings['sentinel_connector.report_upgrade_status'] = FALSE;
```

The block is then left out, as it is when Upgrade Status is not enabled.

### Drupal status report (errors and warnings)

The `status_report` block carries the errors and warnings of the site's own
status report (**Reports → Status report**, `/admin/reports/status`), so
Sentinel can show "database updates not run" or "trusted host patterns not
set" without anyone signing in to the site. OK and information items are
never sent.

```json
"status_reporting": "enabled",
"status_report": {
  "status": "reported",
  "checked_at": "2026-11-07T01:02:03Z",
  "context": "web",
  "left_out": [],
  "items": [
    {
      "key": "trusted_host_patterns",
      "severity": "error",
      "title": "Trusted Host Settings",
      "value": "Not enabled",
      "description": "The trusted_host_patterns setting is not configured in settings.php. ...",
      "provider": "system"
    }
  ]
}
```

- The list is built as Drupal builds the status report: the runtime
  requirement hooks (`hook_requirements('runtime')`, and on Drupal 11.2 and
  later `hook_runtime_requirements()`), then their alter hooks. Each module's
  hook is called on its own, so each item names the module that produced it
  (`provider`). A hook that throws is skipped and logged once.
- Each text is sent as plain text: render arrays are rendered, HTML and links
  are removed and entities decoded. The Drupal root, the private files path
  and the temporary path are replaced with `[site root]`, `[private files]`
  and `[temporary files]`.
- Limits: `key` 128 characters (`a-z`, `0-9`, `_ . : -`; other characters
  become `_`), `title` 200, `value` 300, `description` 1,000 (cut on a word
  with `…`), at most 100 items, errors first.
- The connector's own Sentinel Connector check is not sent: it describes the
  pushes, which Sentinel knows.

**Where it is built.** Some checks are only right on the web server (OPcache,
APCu, output buffering, upload progress, the PHP memory limit, the SameSite
cookie attribute), and some contrib checks are slow. So, after a web response
has been sent, at most once every 12 hours and by one request at a time, the
module builds the list and keeps it in state
(`sentinel_connector.status_report_snapshot`); page cache hits and
`update.php` never build it. The visitor never waits for it. A sync sends
that snapshot (`context: "web"`) while it is newer than 24 hours. Otherwise a
sync from cron or Drush builds the list itself from the command line
(`context: "cli"`) and leaves out the checks that are wrong there, listing
their keys in `left_out`. **Sync now** on the settings form never builds it
while you wait: it sends the last snapshot, or no block before the first
one. A cache rebuild (`drush cr`, `drush deploy`, enabling a module)
forgets the snapshot, so the next sync reports the deployed site. The list is
built in the site's default language, whatever the language of the request.

A build over 20 seconds, or one that fails, is sent as
`"status": "unavailable"` with `reason` `timeout` or `collector_failed` and no
items; the last good snapshot is kept. The push never fails because of it.

The block is part of the push fingerprint except `checked_at`: a changed list
is pushed at the next cron run, an unchanged one is not.

Preview what would be sent (a fresh command line build; nothing is sent):

```bash
drush sentinel_connector:status-report
drush sentinel_connector:status-report --format=json
```

Turn it off with **Report the Drupal status report (errors and warnings)** on
the settings form (`report_status_report: false`), or in `settings.php`, which
takes precedence:

```php
$settings['sentinel_connector.report_status_report'] = FALSE;
```

The payload then sends `status_reporting: "disabled"` and no block.

## Configuration

Admin UI: **Configuration → Web services → Sentinel Connector**
(`/admin/config/services/sentinel-connector`).

| Setting | Description |
| --- | --- |
| Site UUID | From the site's Connection tab in Sentinel. Saved and sent in lower case with hyphens; see below. Can come from `settings.php` or the environment |
| API key | See below |
| Report scope | `all` (default): Drupal core, contrib and custom modules. `core_contrib`: Drupal core and contrib; custom modules are not sent |
| Report configuration drift | On by default. Sends the names of configuration objects that differ from the sync directory, never their values; see [Configuration drift](#configuration-drift) |
| Report platform versions | On by default. Sends the web server, database and OS versions and their system package versions. The PHP version is always sent |
| Report the Drupal status report (errors and warnings) | On by default. Sends the errors and warnings of the status report as plain text; see [Drupal status report](#drupal-status-report-errors-and-warnings). Can be set in `settings.php` |
| Integrations | Closed until Upgrade Status is enabled. Upgrade Status: detected or not, version, last scan, **Report Upgrade Status readiness to Sentinel** (on by default) and the scan command. Config PR: detected or not, with a link to its settings |

The form shows the Sentinel API base URL in use and where it comes from
(Default, settings.php or the environment variable). It cannot be changed on
the form, so nobody with form access can send the API key and the inventory to
another host.

**Test connection** (next to "Save configuration", with the *Trigger Sentinel
sync* permission) and `drush sentinel_connector:check` ask Sentinel whether it
accepts this site's API key and Site UUID. Nothing about the site is sent. The
answer is one of:

| Answer | What to do |
| --- | --- |
| Connected to Sentinel production (or UAT, local) as this site | Nothing |
| The Sentinel API base URL is not valid | Fix the value named in the message. Nothing is sent until then |
| No Sentinel API answered at the address | Check the base URL, the network and the TLS certificate |
| Sentinel does not know this Site UUID | Most often the URL of one environment with the UUID of another. Check both belong to the same Sentinel |
| Sentinel refused the API key, or the key belongs to another site | Copy the key of this site from Sentinel again |
| Subscription or push limit | As for a push; see [Push limits](#push-limits) and [Inactive subscription](#inactive-subscription) |

`drush sentinel_connector:check` exits non-zero on any failure, so CI and
deployment scripts stop, and exits 0 with a notice when sending is turned off.

### API key (secret — never stored in exported config)

In Sentinel, open the registered site's dashboard and choose **Create API key**
(or **Rotate API key** to replace existing credentials). Copy the displayed key
once into Drupal; Sentinel stores its hash and never returns it on later reads.
The Site UUID on the dashboard must match this module's settings.
Revoke a key in Sentinel to disable it immediately.

Sentinel accepts the Site UUID in one notation only: lower case with hyphens,
such as `123e4567-e89b-42d3-a456-426614174000`. Any other notation is refused
with a misleading "Access denied" or "This API key belongs to a different
site". The settings form therefore trims the value, removes surrounding
braces, lowers upper case, adds the hyphens to 32 hexadecimal digits, and
rejects anything else. A value already stored in another notation, or set in
`settings.php`, is sent in the canonical form without a re-save. A stored
value that is not a UUID at all is never sent: the push ends as `unconfigured`
with a message that names the Site UUID.

Resolved in this order:

1. `$settings['sentinel_connector.api_key']` in `settings.php`
2. A key from the [Key](https://www.drupal.org/project/key) module, selected on
   the settings form (recommended)
3. Environment variable `SENTINEL_CONNECTOR_API_KEY` (host-set, never in code)
4. Drupal State, saved by the settings form when the Key module is not
   enabled (deprecated)

The API key is the only credential. There is no site token. The form never
shows the key; it shows where the active key comes from.

To use the Key module, enable it, add a key of type *Authentication* whose
provider reads a file, an environment variable or a vault (**Configuration >
System > Keys**), paste the key from the Sentinel dashboard into it, and select
it under **API key** on the settings form. Only the key id is stored in
`sentinel_connector.settings` (`api_key_id`), so it can be exported. A key
whose provider keeps the value in configuration is refused, because it would
be exported. A selected key that is missing, empty or refused is skipped, and
the next source is used; the settings form says why.

Without the Key module, the form saves the key in Drupal State, which is
stored in plain text in the database and its backups. A blank field keeps the
current key. This is deprecated: enabling the Key module moves the saved key
into a new key (see [Upgrading to 1.4.0](#upgrading-to-140)).

### Several environments

One codebase can push production and non-production as separate Sentinel
sites, or not push at all from local and CI. These settings can come from
`settings.php` or the server environment:

| Setting | settings.php | Environment variable |
| --- | --- | --- |
| API base URL | `$settings['sentinel_connector.api_base_url']` | `SENTINEL_CONNECTOR_API_BASE_URL` |
| API key | `$settings['sentinel_connector.api_key']` | `SENTINEL_CONNECTOR_API_KEY` |
| Site UUID | `$settings['sentinel_connector.site_uuid']` | `SENTINEL_CONNECTOR_SITE_UUID` |
| Off switch | `$settings['sentinel_connector.disabled'] = TRUE` | `SENTINEL_CONNECTOR_DISABLED=1` |

`settings.php` wins over the environment, and the environment wins over the
form. A value that comes from `settings.php` or the environment is shown
read-only on the form with its source; a key is never shown. A Site UUID
override is normalised like the form value.

**API base URL.** The default is `https://ingest.sentinelconnector.io`.
Change it only for a non-production Sentinel. The value must be a base URL:
`https://` only (plain `http://` only for `localhost` and `*.localhost`), a
host, no path, query, fragment, user name or password. A trailing `/` is
dropped. A sync endpoint pasted by mistake gets its own message: "Use the base
URL, not the sync endpoint." An invalid value is refused, never replaced by
the default: nothing is sent, and the form, the status report, "Sync now" and
Drush say why.

**Off switch.** With sending turned off, cron sends nothing,
`drush sentinel_connector:sync` and `:deploy` print "Sending is turned off for
this environment" and exit 0, and "Sync now" and "Test connection" are hidden.
The status report shows it as information.

**Platform versions** can be turned off per environment with the normal
configuration override:
`$config['sentinel_connector.settings']['report_platform'] = FALSE;`.

The module ships a commented example,
[`examples/settings.sentinel_connector.php`](examples/settings.sentinel_connector.php).
Copy it next to `settings.php`, set `SENTINEL_CONNECTOR_ENVIRONMENT` on each
environment (or map your host's own variable, such as
`LAGOON_ENVIRONMENT_TYPE`), and include it in one of two ways:

```php
// Always include (the file itself turns sending off on local and CI).
if (file_exists($app_root . '/' . $site_path . '/settings.sentinel_connector.php')) {
  include $app_root . '/' . $site_path . '/settings.sentinel_connector.php';
}
```

```php
// Include only outside local and CI.
if (!getenv('CI') && getenv('SENTINEL_CONNECTOR_ENVIRONMENT') !== 'local') {
  include $app_root . '/' . $site_path . '/settings.sentinel_connector.php';
}
```

With the second form, also turn sending off on local and CI, for example with
`SENTINEL_CONNECTOR_DISABLED=1`: a production database copy holds the
production Site UUID.

Keep API keys out of the file: read them from the environment or a secrets
store. Then run `drush sentinel_connector:check` on each environment.

More in the knowledge base:
[Use the Sentinel Connector on several environments](https://sentinelconnector.io/app/support/knowledge-base/use-the-sentinel-connector-on-several-environments).

## Triggering a sync

- **Cron** — automatic when enabled on the settings form. Pushes only when the inventory changed, at most once an hour; see [Push frequency](#push-frequency).
- **Drush** — `drush sentinel_connector:sync` (alias `sc-sync`).
- **Deployment** — `drush sentinel_connector:deploy` (alias `sc-deploy`) from the CI/CD pipeline; see [Notify Sentinel on deployment](#notify-sentinel-on-deployment).
- **Admin button** — "Sync now", next to "Save configuration" on the settings form (requires the *Trigger Sentinel sync* permission). It appears once a valid API base URL, a valid Site UUID and an API key are in place, and not while sending is turned off.
- **Connection check** — "Test connection" on the settings form, or `drush sentinel_connector:check` (alias `sc-check`). Sends no inventory; see [Configuration](#configuration).

## Notify Sentinel on deployment

**Recommended:** run this as the last step of every production deployment,
after `drush updb`, `drush cim` and `drush cr`:

```bash
drush sentinel_connector:deploy || true
```

`|| true` keeps a Sentinel outage or a billing problem from failing the
deployment. Leave it out if the pipeline should fail when Sentinel cannot be
told. Run the command on production only: an environment that has a copy of
the production database also has its site UUID and API key, and would
overwrite production's inventory in Sentinel.

Sentinel then learns about a module, core or PHP update when it is released,
not on the next cron run. The command works in any pipeline that can run
Drush on the deployed site: GitHub Actions, GitLab CI, Lagoon post-rollout
tasks, Quant Cloud, Acquia or Pantheon hooks, or a shell script.

The command pushes only when something changed. It compares the inventory
with the last push Sentinel accepted and sends nothing when the two match, so
a content-only or theme-only release does not use up a push from the plan's
limit. A change is anything that alters [what is sent](#what-it-sends): a
listed module added, removed, updated, installed or uninstalled; the Drupal
core or PHP version; the report scope; the site URL, name or UUID; the API
base URL; the connector's own version. Core modules are not listed, and a
sub-module only counts when it changes whether its parent module reports as
enabled. The comparison uses a hash kept in state
(`sentinel_connector.last_fingerprint`), which every accepted push updates,
whether it came from cron, "Sync now" or Drush.

The hash is trusted for 24 hours after the push it belongs to. After that the
command pushes whether or not anything changed, because Sentinel's copy can
differ without the site knowing: a restored database, another environment
that pushed with the same credentials, or a site registered again.

| Outcome | Message | Exit code |
| --- | --- | --- |
| Nothing changed, last accepted push under 24 hours old | "no change since the last accepted push" | 0 |
| Changed, push accepted | Success | 0 |
| Changed, held or refused by the [plan limit](#push-limits) | Warning with the next allowed time | 0 |
| Site UUID or API key missing, or site UUID not a UUID | Warning, nothing sent | 0 |
| Sending turned off for this environment | Notice, nothing sent | 0 |
| Inactive subscription, or any other failure | Error | non-zero |

Options and notes:

- `--force` pushes even when nothing changed. To make the next run push,
  `drush state:delete sentinel_connector.last_fingerprint` also works.
- A site that is not configured exits with 0, so the same pipeline can run on
  environments that do not report to Sentinel.
- A push that was held, refused or failed is sent by cron later, when cron
  pushes are enabled on the settings form. The command itself works without
  them, but then nothing retries until the next deployment.
- Cron pushes count against the same plan limit. On paid plans cron only
  uses it when something changed and once a day, so it is normally free when
  a deployment needs it. On Free the daily push uses the whole limit, so a
  deployment after it is held until the next day's push.
- Configuration drift is part of the inventory, so run the command after
  `drush cim`: the push then reports that production matches the code again.
- The PHP version is part of the inventory. If Drush and the web server run
  different PHP versions and cron runs through the web server, every
  deployment counts as a change and pushes.

Examples:

```yaml
# GitHub Actions / GitLab CI: last step of the production deployment job.
- run: vendor/bin/drush sentinel_connector:deploy || true
```

```yaml
# Lagoon: .lagoon.yml
tasks:
  post-rollout:
    - run:
        name: Notify Sentinel
        command: if [ "$LAGOON_ENVIRONMENT_TYPE" = "production" ]; then drush sentinel_connector:deploy || true; fi
        service: cli
```

## Push frequency

There is no interval setting. Cron pushes only when something changed, so that
the plan's push limit is kept for real changes:

- At most once an hour, whatever the cron frequency, cron compares the
  inventory with the last push Sentinel accepted. The hour is counted from the
  last attempt whatever its outcome, or from the last check that found no
  change. This floor is fixed in code.
- The comparison uses a SHA-256 hash of everything that is sent, apart from
  the IP address, plus the API base URL in use. Any difference, down to one
  character, is a change and is pushed. The hash is kept in state
  (`sentinel_connector.last_fingerprint`) and is updated by every accepted
  push; the payload itself is not stored.
- When nothing changed, nothing is sent. The check is recorded
  (`sentinel_connector.last_unchanged_time`) and the status report shows it.
- An unchanged inventory is sent again 24 hours after the last accepted push
  (from 15 minutes earlier, so that a once-a-day cron run that starts a little
  early does not skip it), because Sentinel's copy can differ without the site knowing: a restored
  database, another environment that pushed with the same credentials, or a
  site registered again.

How often a push is accepted is decided by Sentinel, by plan.

"Sync now" and `drush sentinel_connector:sync` always push, changed or not,
and are not bound by the hourly floor: Sentinel enforces the real limit and
answers with a clear message. They are still held by a stored plan limit
(below). To make cron push on its next due run:
`drush state:delete sentinel_connector.last_fingerprint`. Saving a new API key
on the settings form does the same, so a wrong key shows up within the hour.

A push that Sentinel queued (202) and then failed to process is not sent again
until the inventory changes or the day is over.

## Push limits

Sentinel limits how often a site may push, by plan: once in 24 hours on Free
and once an hour on paid plans. A push sent too soon is answered with HTTP 429
and `error_code: push_limit_reached`. The connector treats this as an expected
outcome, not as a connection or authentication failure:

- **Sync now** and `drush sentinel_connector:sync` show a warning with
  Sentinel's explanation and a single retry time in the site's default time
  zone. Retry times are rounded up to the next whole minute so they never
  suggest trying before the exact cutoff. The Drush command still exits with 0.
- **Cron** shows nothing.
- Every rejection is logged at notice level on the `sentinel_connector`
  channel with the plan, the limit and the next allowed time. A push held
  back afterwards is logged at debug level. See [Push log](#push-log).
- The next allowed time is kept in state (`sentinel_connector.next_allowed_at`).
  Until it passes nothing is sent: cron skips, and a manual push shows the
  stored message without contacting Sentinel. Cron pushes on its first run
  after that time, once the hourly floor has also passed. The settings form
  shows the time under **Push limit**.
- A single response can hold pushes back for at most 7 days. To clear a stored
  limit by hand: `drush state:delete sentinel_connector.next_allowed_at`.

When the response has the error code but no usable message or time, the
connector uses a generic message and the `Retry-After` header. A 429 without
the error code is reported as `rate_limited`, as before. The error code counts
on a 429 only: on any other status nothing is stored and no push is held back.

A site on the Free plan therefore sends about one push a day, with the latest
inventory. A change made after that day's push is refused once: the refusal
tells the module when the next push is allowed, nothing is sent until then,
and the change goes out with the next push.

## Inactive subscription

Sentinel refuses every push while the organisation's subscription is overdue,
unpaid or paused: HTTP 402 with `error_code: subscription_inactive` and a
`reason` of `past_due`, `unpaid` or `paused`.

- **Sync now** shows an error with Sentinel's message, or a text for the
  reason when there is none. `drush sentinel_connector:sync` fails with the
  same text and a non-zero exit code.
- **Cron** shows nothing, logs a warning with the reason on the
  `sentinel_connector` channel, and then tries at most once in 24 hours until
  a push is accepted.
- A manual push is never held back, so it can be retried straight after
  billing is fixed. Any accepted push ends the hold.
- The settings form shows the reason under **Subscription**.

A 402 without that error code is reported as `rejected`, as before. The error
code counts on a 402 only: on any other status cron is not held.

## Push log

Every push writes one entry to the Drupal log on the `sentinel_connector`
channel, whether it was started by cron, by **Sync now** or by Drush:

```
Sentinel push (cron): not_found, HTTP 404. Site not found. Check the API base URL and site UUID. [no details]
```

The entry holds the trigger (`cron`, `form` or `drush`), the outcome, the HTTP
status, Sentinel's message and, in brackets, what else is known: the task ID
of a queued push, the plan limit, the reason for a billing refusal, the
`error_code` Sentinel sent, or the error class and curl error number of a
connection failure. It never holds the
API key, the request URL, headers, the payload or an exception message.
Sentinel's text is reduced to one line of at most 500 characters. A cron run
that finds no change sends nothing and logs nothing.

An error response from Sentinel names an `error_code`. When the connector
knows the code, the code alone decides the outcome: the HTTP status and the
wording of the message are not consulted, and the entry ends with
`error code <code>`. A refused signature, for example, now reads
`error code timestamp_skew` where an older server gave `code timestamp_skew`.

Two codes are the exception, because their outcomes hold back later pushes:
`push_limit_reached` counts on a 429 only and `subscription_inactive` on a 402
only. On any other status the response is classified without the code, nothing
is stored, and the entry ends with `ignored error code <code>`.

With a known code the text shown and logged is Sentinel's `message`, without
the connector's own advice. What the message cannot hold follows as sentences
of their own: the rejected modules, the first field at fault of an invalid
payload, and the `Retry-After` wait of a `rate_limited` or `unavailable`
outcome. The whole text is at most 500 characters; a message too long for that
is cut at a word and ends with an ellipsis. When there is no `message`, or it
holds no letter or digit, the older `detail` is used, as for a response
without a code.

`Retry-After` counts as a positive number of whole seconds, or as an HTTP date
(`Sat, 10 Oct 2026 03:15:00 GMT`) in the future. Any other value, such as `0`,
a negative number, a relative phrase or a date in the past, means that no wait
is shown or stored.

A response without an `error_code` is classified by its HTTP status and the
shape of its body, as in earlier releases. Sentinel servers released before
error codes, and reverse proxies that answer with HTML, are therefore still
supported. A code this release does not know is classified the same way, and
the entry ends with `unknown error code <code>`. A code that is not 1 to 64
lower-case letters, digits or underscores is ignored.

| Outcome | HTTP | `error_code` | Level | Meaning |
|---------|------|--------------|-------|---------|
| `success` | 200 | | info; warning with unresolved versions | The inventory was stored. The message is Sentinel's own when it sends one. Modules whose version Sentinel could not read are listed as a warning (see below). |
| `accepted` | 202 | | info; warning with unresolved versions | The inventory was queued; the entry has the task ID. Unresolved versions are listed as for `success`. |
| `push_deferred` | none | | debug | A stored plan limit held the push back; no request was sent. |
| `push_limited` | 429 | `push_limit_reached` | notice | The plan's push limit was reached. |
| `rate_limited` | 429 | `site_rate_limited`, `auth_rate_limited` | notice | Sentinel's general rate limit, or its limit on authentication attempts, was reached. The message is Sentinel's own, with the `Retry-After` wait. |
| `subscription_inactive` | 402 | `subscription_inactive` | warning | The subscription is overdue, unpaid or paused. |
| `site_mismatch` | 400 | `site_url_mismatch`, `site_uuid_mismatch` | warning | The site UUID differs from the site registered in Sentinel. (`site_url_mismatch` comes only from connectors before 1.4.0, which sent the site URL.) |
| `validation_failed` | 400 | `module_validation_failed`, `module_not_on_drupal_org` | warning | Sentinel rejected module names or versions, or a module that is not on drupal.org. The message has the count and up to five entries when Sentinel lists them, each with its result and the version sent, then "and N more". `drush sentinel_connector:sync` lists every entry. |
| `not_found` | 404 | `site_unavailable` | warning | Sentinel does not know the site, the site or its organisation is inactive, or the API base URL is wrong. |
| `conflict` | 409 | `catalog_conflict`, `catalog_entry_unavailable` | warning | Sentinel could not store a reported module. |
| `unconfigured` | none | | warning; debug on cron | The site UUID or API key is missing, or the site UUID is not a UUID; no request was sent. |
| `invalid_url` | none | | error | The API base URL from `settings.php` or the environment is not a valid base URL; no request was sent. |
| `disabled` | none | | debug | Sending is turned off for this environment; no request was sent and nothing is recorded. |
| `signature_refused` | 401 | `signature_missing`, `signature_invalid`, `timestamp_skew`, `replay_detected` | error | The request signature was refused; the entry has Sentinel's code. |
| `auth_error` | 401, 403 | `authentication_required`, `api_key_invalid`, `api_key_inactive`, `permission_denied`, `site_mismatch` | error | The API key is missing, wrong, expired or inactive, lacks permission, or belongs to another site. |
| `invalid_payload` | 400, 422 | `payload_invalid`, `json_invalid`, `body_unreadable` | error | Sentinel could not read or decode the payload. The message has the first field at fault when Sentinel names one. |
| `unavailable` | 503 | `rate_limiter_unavailable`, `replay_protection_unavailable`, `queue_unavailable`, `inventory_busy`, `auth_rate_limiter_unavailable` | error | Sentinel is temporarily unavailable. With one of these codes the message has the `Retry-After` wait. |
| `server_error` | other 5xx | `internal_error` | error | Sentinel failed. |
| `transport_error` | none | | error | Sentinel could not be reached. |
| `invalid_response` | any | | error | The response was not the JSON the connector expects, or a redirect (3xx), which is never followed. |
| `rejected` | other | | error | Any other refusal. |
| `internal_error`, `state_error` | none | | error | The push failed on this site; the entry has the error class. Not to be confused with Sentinel's `internal_error` code, which is a `server_error`. |

Sentinel stores a module whose version it cannot read, such as a Composer
branch name like `dev-main`, as an unknown version instead of refusing the
push, and lists it in `unresolved_versions` of the `200` or `202` response.
The connector then shows a warning on the settings page after "Sync now",
lists every such module in the Drush output and writes the log entry at
warning level with `unresolved versions (N)` and the first entries. Module
names and versions from Sentinel are shown without control characters, with
angle brackets as `‹ ›`, and cut to 64 and 50 characters. A Sentinel that does not send the list shows no
warning.

A 403 without an `error_code` is also reported as `auth_error`. Such a
response can come from in front of Sentinel, for example a network-level
"Access denied" from a firewall or proxy, so the cause may be the network path
rather than the API key.

The HTTP column is the status Sentinel sends with the outcome. A known
`error_code` gives its outcome on any error status (400 and above), except
`push_limit_reached` and `subscription_inactive`, as described above.

Sentinel's `error_code: site_mismatch` and the connector's `site_mismatch`
outcome are different things that share a name. The code is sent with a 403
when the API key belongs to another site, and is an `auth_error`: check the
API key. The outcome is for a site URL or UUID that differs from the site
registered in Sentinel, which Sentinel reports as `site_url_mismatch` or
`site_uuid_mismatch`. This is intended.

The same outcome is shown on the settings form and by Drush, and stored in
state as `sentinel_connector.last_result`.

## Status report

**Reports → Status report** (`/admin/reports/status`) has a *Sentinel
Connector* entry:

| State | Severity |
| --- | --- |
| Sending turned off for this environment | Information |
| API base URL from `settings.php` or the environment not valid | Error, with the reason |
| Site UUID or API key missing, or site UUID not a UUID | Error, with a link to the settings form |
| Last push refused over an inactive subscription | Error, with the reason |
| No push accepted and no unchanged check in the last 24 hours | Error, with the last attempt, its result and its time |
| Configured less than 24 hours ago, no push accepted yet | Warning |
| Next push held by the plan limit, last accepted push under 24 hours old | Information, with the next allowed time |
| Last accepted push under 24 hours old | OK, with its time |
| Last accepted push older, cron found no change in the last 24 hours | OK, with the time of the push and of the last check |

"Accepted" means Sentinel answered 200 or 202. A plan limit that ended less
than 6 hours ago also shows as information, so that a Free site does not
report an error between the end of its daily limit and the next cron run.

A second entry, *Sentinel Connector connection test*, is a warning while the
last "Test connection" or `drush sentinel_connector:check` failed and no push
has been accepted since.

## Configuration pull request (optional submodule)

The `sentinel_connector_config_pr` submodule brings configuration that changed
on production back into code. It writes every configuration object that
differs from the sync directory to an export branch and opens one pull request
(GitHub) or merge request (GitLab), or updates the one already open. Your team
reviews and merges it; the module never merges. It uses
[Config Pull Request](https://www.drupal.org/project/config_pr) (`config_pr`
3.0) for the GitHub and GitLab APIs only: its own form and its user token
field are not used.

Install it on the site that should open the pull request:

```bash
composer require drupal/config_pr:^3.0
drush en sentinel_connector_config_pr config_pr_github
```

For GitLab, enable `config_pr_gitlab` instead and add its API client, which
`config_pr` does not install:

```bash
composer require m4tthumphrey/php-gitlab-api:^12.0
```

Drupal core supplies the PSR-18 HTTP client and PSR-17 factories. Do not add
Guzzle 7-only adapters or factories to Drupal 12, which uses Guzzle 8/PSR-7 3.

Set the repository on **Configuration > Web services > Sentinel configuration
pull request** (`/admin/config/services/sentinel-connector/config-pr`): Git
host, owner (for GitLab the group path, subgroups separated by `/`) and name
or, for GitLab, the numeric project id, the host URL for GitHub Enterprise or self-managed GitLab, the base branch
(empty for the default branch), the export branch (default
`sentinel/config-export`), the path of the sync directory in the repository
(default `config/sync`) and the pull request title. The settings hold no
secrets.

### Access token

The token comes only from `settings.php` or from a [Key](https://www.drupal.org/project/key)
module key selected on the settings form. It is never read from configuration
or State, never logged and never sent to Sentinel. A key that keeps its value
in configuration is refused; use a key provider that reads a file or an
environment variable. The settings form shows only whether a token is present.

```php
$settings['sentinel_connector_config_pr']['token'] = getenv('CONFIG_PR_TOKEN');
```

Give the token access to the one repository only:

- **GitHub**: a fine-grained personal access token for that repository, with
  **Contents** and **Pull requests** set to read and write.
- **GitLab**: a project access token with the `api` scope and the
  **Developer** role. Do not protect the export branch.

### Running it

```bash
# List the configuration objects a run would export; writes nothing.
drush sentinel_connector:config-pr --dry-run
# Export now, even if the interval has not passed.
drush sc-config-pr --force
```

A run compares the export storage with the sync directory, the same comparison
as `drush config:status`, so objects that Config Ignore or Config Split leave
out are not exported. It writes created and updated objects as YAML and deletes
the files of deleted objects. Files that already match the export branch are
skipped, so a run without a new change makes no commit. After the pull request
is merged or closed, the next run starts the export branch again from the base
branch. The module owns the export branch: do not push other work to it. A run
refuses a branch of that name that it did not create in this repository (or
that changed after its pull request closed), an open pull request from it that
it did not open or that targets another base branch, and an empty sync
directory. Keep the token on production only: a database copy also copies the
record of the open pull request.

Cron runs are off by default. When turned on, cron runs at most once per
interval (default 24 hours), and only while Sentinel Connector's own cron
reporting is enabled, so the guard in
[Send reports from production only](#send-reports-from-production-only) also
keeps non-production environments from opening pull requests.

Each run logs its outcome, the pull request URL and the number of files on the
`sentinel_connector` channel, never configuration values or the token.
Configuration values are never sent to Sentinel.

Known limit: `config_pr` makes one commit per file, so a large export makes
many commits.

## Permissions

- *Administer Sentinel Connector* — access the settings form.
- *Trigger Sentinel sync* — use the "Sync now" button.

## Testing

The Drupal.org GitLab pipeline is configured in `.gitlab-ci.yml` using the
[Drupal Association templates](https://project.pages.drupalcode.org/gitlab_templates/).
It runs the unit, payload contract and Kernel tests on Drupal 10, 11 and 12.
Drupal 10 and 11 use PHP 8.3; Drupal 12 uses its required PHP version.
All three Composer and test jobs must pass. The templates provide a database
for Kernel tests. No Sentinel account or API key is needed for these tests.
The GitHub workflow also tests Drupal 10/PHP 8.3, Drupal 11/PHP 8.4 and
Drupal 12 beta/PHP 8.5.

To run the tests in a development Drupal checkout that contains this module and
has the matching `drupal/core-dev` and `justinrainbow/json-schema` installed:

```bash
# Run from the Drupal web root; adjust the module path for a contrib install.
../vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/sentinel_connector/tests/src/Unit
SIMPLETEST_DB='sqlite://localhost/:memory:' ../vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/sentinel_connector/tests/src/Kernel
```

The Kernel command needs the SQLite PHP extension. Installing this module's
Composer dependencies alone does not install the Drupal test framework.
Drupal 12 release archives omit the core tests. Use a core source checkout
at the exact installed revision; the GitHub workflow restores these files
before running the tests.

Connection failures return an actionable transport error and record the last attempt when Drupal state storage is available. Other payload/configuration errors return an internal error; cron catches remaining failures and attempts to record and log them without exposing request secrets. Storage failures may prevent persistence and return a state error.
Cron waits an hour after each attempt, including failures, and after each check that found no change.
The settings page displays the last attempt separately from the last successful
sync. A 202 queued result is accepted for processing and does not imply success.

Payloads also report the configured `inventory_scope`, the connector's own
release as `connector_version`, per-extension `reported_project`,
`version_known` and `extension_type`.
Themes are listed with modules, by the same rules, with `extension_type:
"theme"`. A theme reports `enabled: true` when it is installed, and
`theme_roles` lists `default` and `admin` when it is the site's default or
administration theme (an empty list otherwise). A theme named like a listed
module shares that module's row.
Sub-modules are not listed: a module whose directory is inside another
module's directory (for example, `webform_ui` inside `webform`) is folded into
that module, which reports `enabled: true` when it or any of its sub-modules is
enabled. A sub-theme inside another theme's directory is folded the same way,
and its roles move to that theme's row. Modules shipped inside an install
profile are listed normally. Core modules and themes are not listed either;
core is reported by `drupal_info.core_version` alone, in both scopes.
`core_contrib` leaves out custom modules and themes. Custom names are never
guessed as Drupal.org projects.

The project comes from the `project` key that Drupal.org packaging adds to
`info.yml`. Modules installed from a git clone, a VCS Composer repository or a
dev checkout have no such key, so the connector then looks for the installed
`drupal/*` Composer package whose install path contains the module:

| Module source | `module_type` | `reported_project` |
| --- | --- | --- |
| Drupal core | not listed | not listed |
| `info.yml` has a valid `project` key | `contrib` | that project |
| No `project` key, inside a `drupal/*` package | `contrib` | package short name, e.g. `admin_toolbar` |
| Inside a `drupal/*` package with an unusable name | `contrib` | `null` (Sentinel shows "missing project") |
| Anything else | `custom` | `null` |

The root package, `drupal/core*` packages and `drupal-custom-*` package types
are ignored, so site-specific code in a path repository stays `custom`. A missing version
keeps the legacy `0.0.0` placeholder with `version_known: false`. Whitespace
around a version is trimmed before it is sent.
Sentinel uses this site-scoped identity to assess the persisted inventory
asynchronously. Inventory acceptance and upstream assessment are distinct;
narrow scopes, custom modules and missing metadata cannot establish whole-site
assessment coverage. The connector does not yet sign or poll requests.

The real local API smoke fixture lives at `tests/fixtures/connector_e2e.php`.
Provision a synthetic site and issue its key through the normal Sentinel UI/API,
then provide `SENTINEL_E2E_ALLOW=1`, `SENTINEL_E2E_API_URL` (base before
`/api/v1`), `SENTINEL_E2E_SITE_UUID` and `SENTINEL_E2E_API_KEY` in a private
environment. The fixture sets the API base URL through
`SENTINEL_CONNECTOR_API_BASE_URL` for its run.
Run from the Drupal project root:

```bash
vendor/bin/drush php:script web/modules/custom/sentinel_connector/tests/fixtures/connector_e2e.php
```

The fixture refuses to run if `settings.php` or `SENTINEL_CONNECTOR_API_KEY`
provides a key, because either would override the fixture key. Remove those
overrides only on the isolated local test site before running it.

The fixture requires a real successful response, checks attempt/success state,
prints only status and runtime versions, and restores the original connector
configuration and state. Run only against isolated local Drupal and Sentinel
fixtures; do not save keys or credentialed URLs in the repository or logs.
The Kernel suite also runs Drupal's actual cron service against an unreachable
local endpoint and covers malformed URI, invalid UTF-8, typed payload errors,
and unavailable state storage.

## Troubleshooting

- **Test connection** on the settings form, or `drush sentinel_connector:check`,
  says in one line why Sentinel does not accept this site; see
  [Configuration](#configuration).
- Reports, Status report shows the result of the last push. Each push is
  logged; see [Push log](#push-log).
- A refused push is explained in [Push limits](#push-limits) and
  [Inactive subscription](#inactive-subscription).
- The [Sentinel user guide](https://sentinelconnector.io/help#help-when-something-does-not-work)
  covers the dashboard side.

## Maintainers

- [TrueCMS Pty. Ltd.](https://www.drupal.org/truecms-pty-ltd)
- [ivrh](https://www.drupal.org/u/ivrh)
