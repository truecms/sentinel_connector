# Upgrading Sentinel Connector

Update the module with Composer, then run the database updates and export
configuration:

```bash
composer require drupal/sentinel_connector:^1.7 --with-all-dependencies
drush updb
drush cex
```

Read the section of every release between the version you run and the new
one. A release that changes the payload needs a Sentinel that accepts it
first; its section says so.

## Upgrading to 1.7.0

No database update and no configuration change. The next sync after the
upgrade also reports the site's themes, so it is sent even when nothing else
changed. Payload v5 is unchanged: any Sentinel that accepts 1.6.x accepts
1.7.0.

## Upgrading to 1.6.2

No database update and no configuration change. Messages in the admin pages
can now be translated.

## Upgrading to 1.6.1

Release `1.6.1` adds an [Installation](README.md#installation) section, moves the hooks
to autowired classes and saves both settings forms through `#config_target`.
The payload, settings and database are unchanged, so there is no database
update. Sites on 1.5.x or earlier
follow the 1.6.0 steps below.

```bash
composer require drupal/sentinel_connector:^1.6 --with-all-dependencies
```

## Upgrading to 1.6.0

Release `1.6.0` sends payload v5 and adds
[`--scan-report`](README.md#full-scan-in-ci---scan-report) for a full upgrade scan in
your own CI. Payload v5 adds the `status_reporting` field and the
optional `status_report` block
([Drupal status report](README.md#drupal-status-report-errors-and-warnings)). Upgrade
only after the receiving Sentinel accepts payload v5: an older Sentinel
refuses the push. Then:

```bash
composer require drupal/sentinel_connector:^1.6 --with-all-dependencies
drush updb
```

Update `10007` turns on `report_status_report`, also on sites upgraded from
1.5.x. Export configuration afterwards (`drush cex`), or add
`report_status_report: true` to the site's `sentinel_connector.settings.yml`,
so a later import keeps it. To keep it off, set it to `false` before the
import, or in `settings.php`. Preview what would be sent with
`drush sentinel_connector:status-report`.

Sites upgrading from a release before 1.5.0 also follow the 1.5.0 steps below.

## Upgrading to 1.5.0

Release `1.5.0` keeps payload v4 and adds the optional `upgrade_status` block
([Drupal upgrade readiness](README.md#drupal-upgrade-readiness-upgrade-status-integration)).
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
[Several environments](README.md#several-environments). Afterwards run
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
