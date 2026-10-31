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

Install a published release from the Drupal project root:

```bash
composer require drupal/sentinel_connector
drush en sentinel_connector
```

A Sentinel account, a registered site and its API key are required to send
reports. The module does not include the Sentinel service. Review **What it
sends** before you enable reporting.

Maintained by [TrueCMS Pty. Ltd.](https://www.drupal.org/truecms-pty-ltd).

The module is licensed under GPL-2.0-or-later; see [LICENSE.txt](LICENSE.txt).

## Upgrading to 1.5.0

Release `1.5.0` keeps payload v4 and adds the optional `upgrade_status` block
([Drupal upgrade readiness](#drupal-upgrade-readiness-upgrade-status-integration)).
Upgrade only after the receiving Sentinel accepts that block: a Sentinel
without it refuses the push. Then:

```bash
composer require drupal/sentinel_connector:^1.5 --with-all-dependencies
drush updb
```

Update `10006` enables `report_upgrade_status`. Export configuration
afterwards (`drush cex`), or add `report_upgrade_status: true` to the site's
`sentinel_connector.settings.yml`, so a later import keeps it. Nothing is sent
until Upgrade Status is enabled on the site; no scan runs on cron. Sites
upgrading from a release before 1.4.0 also follow the 1.4.0 steps below.

## Upgrading to 1.4.0

Release `1.4.0` slims the settings form, adds settings for several
environments, and sends payload v4. Upgrade only after the receiving Sentinel
accepts payload v4 and has the connection check route. Run `drush updb` after
updating, then export configuration (`drush cex`).

Update `10005`:

- Removes `api_base_url`, `site_url` and `site_name` from
  `sentinel_connector.settings`. The site is identified by its Site UUID and
  its API key; the URL and name are no longer sent.
- Maps the report scope: `contrib_custom` becomes `all`, `contrib` becomes
  `core_contrib`. Custom modules that were left out stay left out.
- Turns on **Report platform versions** (`report_platform: true`), which keeps
  what the site sent before.

The API base URL now comes from `settings.php` or the environment, with
`https://ingest.sentinelconnector.io` as the default. **UAT and local sites
must act**: when the saved base URL was not the production default, the
site is never moved to production. It sends nothing, and the update logs a
warning, until you set `$settings['sentinel_connector.api_base_url']` or
`SENTINEL_CONNECTOR_API_BASE_URL` on that environment (to the production URL
if that is what you want). See
[Several environments](#several-environments). Afterwards run
`drush sentinel_connector:check` on each environment.

Release `1.4.0` also adds the Key module as the recommended API key source.

If the key was saved on the settings form (Drupal State) and the Key module is
enabled, update `10004` moves it into a new key `sentinel_connector_api_key`
(`sentinel_connector_api_key_2` and so on if that id is taken), selects it, and
deletes the State value. Without the Key module the key stays in State until
the module is enabled, which runs the same move. A site that already selected
a key, or uses `settings.php` or the environment variable, is not changed.

The moved key uses Key's State provider, so the value is still in the
database. Edit the key, change its provider to a file, an environment variable
or a vault, then delete the State variable named in its description:

```bash
drush state:delete sentinel_connector.key_value.sentinel_connector_api_key
```

Export configuration afterwards (`drush cex`) to keep `api_key_id` and the
key entity. The key entity holds no value for file, environment or vault
providers, so it is safe to export.

## Upgrading to 1.3.3

Release `1.3.3` adds two optional platform fields and keeps payload v3: the
operating system's `codename`, and a `packages` list of the installed builds
of PHP, Apache, Nginx, MySQL, MariaDB and PostgreSQL from the Debian and
Ubuntu package database. Sentinel uses them to check Ubuntu's and Debian's
own security fixes; older Sentinel releases ignore them. Update the module
with Composer; no database update or configuration export is needed.

## Upgrading to 1.3.2

Release `1.3.2` is a patch release with no payload, schema or configuration
change. It reports numeric Composer branches, such as `dev-3.x`, as Drupal
branch versions, such as `3.x-dev`. Update the module with Composer; no
database update or configuration export is needed.

## Upgrading to 1.3.1

Release `1.3.1` is a patch release with no payload, schema or configuration
change. It trims module versions, names each module Sentinel rejected and
warns about versions Sentinel could not read. Update the module with Composer;
no database update or configuration export is needed.

## Upgrading to 1.3.0

Release `1.3.0` introduces payload v3, configuration drift and the optional
`sentinel_connector_config_pr` submodule. Release `1.2.0` already provides
Drupal 12 compatibility and uses payload v2. Upgrade only after the receiving
Sentinel environment accepts v3; preserve the site's UUID, key and
production-only overrides. See [RELEASE.md](RELEASE.md) for release and canary
gates. Availability on Drupal.org must be confirmed before running:

```bash
composer require drupal/sentinel_connector:^1.3 --with-all-dependencies
drush updb
```

Update `10003` enables `report_config_drift`. Add `report_config_drift: true`
to the existing `sentinel_connector.settings.yml` in the site's configuration
repository and commit only that intended change. If using `drush cex`, export
on a development copy and discard unrelated exported changes before deployment.
Keep the deployed sync directory as the code baseline during drift verification;
exporting all active production configuration there would hide existing drift.
This preserves the setting on a later configuration import. Keep the optional submodule disabled
unless that site's setup has been separately agreed. Run
`drush sentinel_connector:deploy` last in the site's normal deployment, then
verify a fresh accepted receiver-side payload contains `schema_version: 3` and
`config_drift`, including its generation time and status.


For a manual verification after deployment, run `drush cr` and
`drush sentinel_connector:sync` (or use "Sync now").

Release `0.3.0` has database updates: they remove the `cron_interval` and
`site_token` settings. Sites that keep configuration in code must export
configuration afterwards (`drush cex`), or the next import puts the unused keys
back.

"Sync now" on the settings form works in place of the Drush command. Sites on
`0.1.0` must upgrade to `0.2.0` or later for Sentinel to check contrib modules
against Drupal.org; see [CHANGELOG.md](CHANGELOG.md).

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

## What it sends

A `POST` to `{api_base_url}/api/v1/sites/{site_uuid}/modules/sync` with an
`X-API-Key` header, containing the site UUID, Drupal/PHP/IP environment, and the
list of installed modules (machine name, display name, type, enabled, version,
description). Every sync is a full snapshot (`full_sync: true`).

The payload is `schema_version: 4`. The `site` block holds only the `uuid`:
the site URL and name are not sent. `platform_reporting` is always sent,
`"enabled"` or `"disabled"`, so Sentinel can tell a site that turned platform
reporting off from one whose versions could not be read.

With **Report platform versions** on (the default), the `platform` block
reports the stack Sentinel matches against known vulnerabilities. Turned off,
the stack is not read and no `platform` block is sent;
`drupal_info.php_version` is still sent, because Drupal core support depends
on it.

The optional `upgrade_status` block is described in
[Drupal upgrade readiness](#drupal-upgrade-readiness-upgrade-status-integration).

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
# Scan custom projects with Upgrade Status, then sync. Fails if the scan fails.
drush sentinel_connector:upgrade-status

# The same in two steps.
drush upgrade_status:analyze --all --ignore-contrib
drush sentinel_connector:sync
```

With the [off switch](#several-environments) set, the command exits 0 and scans
nothing.

Turn reporting off with **Report Upgrade Status readiness to Sentinel** in the
Integrations section of the settings form (`report_upgrade_status: false`), or
in `settings.php`, which takes precedence:

```php
$settings['sentinel_connector.report_upgrade_status'] = FALSE;
```

The block is then left out, as it is when Upgrade Status is not enabled.

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
  Sentinel's message and the time the next push is accepted, in the site's
  default time zone. The Drush command still exits with 0.
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
release as `connector_version`, per-extension `reported_project`, and
`version_known`.
Sub-modules are not listed: a module whose directory is inside another
module's directory (for example, `webform_ui` inside `webform`) is folded into
that module, which reports `enabled: true` when it or any of its sub-modules is
enabled. Modules shipped inside an install profile are listed normally. Core
modules are not listed either; core is reported by `drupal_info.core_version`
alone, in both scopes. `core_contrib` leaves out custom modules. Custom
names are never guessed as Drupal.org projects.

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
