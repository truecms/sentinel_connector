# Sentinel Connector

A Drupal 10/11 module that reports a site's installed extensions and environment
to the [Sentinel](https://github.com/truecms/sentinel) monitoring platform.
Sentinel computes update and security status; this module only collects an
authoritative snapshot and submits it.

## Project and distribution

The public module name is **Sentinel Connector**. `SentinelConnector.io` is the
primary registered domain; `SentinelConnector.com` is the companion domain.

The Drupal.org project page and public releases are pending. The
[GitHub repository](https://github.com/truecms/sentinel_connector) is private.
Collaborators with repository access can install a source checkout in their
site's custom module directory, typically
`web/modules/custom/sentinel_connector`, then enable **Sentinel Connector** on
Drupal's Extend page. The machine name remains `sentinel_connector`.

## Upgrading

After updating the module code:

```bash
drush updb
drush cr
drush sentinel_connector:sync
```

Release `0.3.0` has database updates: they remove the `cron_interval` and
`site_token` settings. Sites that keep configuration in code must export
configuration afterwards (`drush cex`), or the next import puts the unused keys
back.

"Sync now" on the settings form works in place of the Drush command. Sites on
`0.1.0` must upgrade to `0.2.0` or later for Sentinel to check contrib modules
against Drupal.org; see [CHANGELOG.md](CHANGELOG.md).

## Requirements

- Drupal `^10.3 || ^11`
- PHP `>= 8.3`

## What it sends

A `POST` to `{api_base_url}/api/v1/sites/{site_uuid}/modules/sync` with an
`X-API-Key` header, containing the site info, Drupal/PHP/IP environment, and the
list of installed modules (machine name, display name, type, enabled, version,
description). Every sync is a full snapshot (`full_sync: true`).

## Configuration

Admin UI: **Configuration → Web services → Sentinel Connector**
(`/admin/config/services/sentinel-connector`).

| Setting | Description |
| --- | --- |
| API base URL | Sentinel base URL, e.g. `https://sentinel.example.com` |
| Site UUID | The registered site's UUID (must match Sentinel) |
| Site URL | Must match the URL registered in Sentinel |
| Report scope | `all` (default), `contrib_custom`, or `contrib` |

### API key (secret — never stored in exported config)

In Sentinel, open the registered site's dashboard and choose **Create API key**
(or **Rotate API key** to replace existing credentials). Copy the displayed key
once into Drupal; Sentinel stores its hash and never returns it on later reads.
The Site UUID and Site URL on the dashboard must match this module's settings.
Revoke a key in Sentinel to disable it immediately.

Resolved in this order:

1. `$settings['sentinel_connector.api_key']` in `settings.php` (recommended for production)
2. Environment variable `SENTINEL_CONNECTOR_API_KEY` (host-set, never in code)
3. Drupal state (set via the settings form), for simple setups

The API key is the only credential. There is no site token: paste the key from
the Sentinel dashboard into **API key** on the settings form, or set it in one
of the first two places. The form never shows a saved key; it shows where the
active key comes from, and a blank field keeps the current key. Drupal state is
not encrypted.

## Triggering a sync

- **Cron** — automatic when enabled on the settings form. At most one push an hour, whatever the cron frequency; see [Push frequency](#push-frequency).
- **Drush** — `drush sentinel_connector:sync` (alias `sc-sync`).
- **Deployment** — `drush sentinel_connector:deploy` (alias `sc-deploy`) from the CI/CD pipeline; see [Notify Sentinel on deployment](#notify-sentinel-on-deployment).
- **Admin button** — "Sync now" on the settings form (requires the *Trigger Sentinel sync* permission).

## Notify Sentinel on deployment

**Recommended:** run this as the last step of every production deployment,
after `drush updb`, `drush cim` and `drush cr`:

```bash
drush sentinel_connector:deploy
```

Sentinel then learns about a module, core or PHP update when it is released,
not on the next cron run. The command works in any pipeline that can run
Drush on the deployed site: GitHub Actions, GitLab CI, Lagoon post-rollout
tasks, Quant Cloud, Acquia or Pantheon hooks, or a shell script.

The command pushes only when something changed. It compares the inventory
with the last push Sentinel accepted and sends nothing when the two match, so
a content-only or theme-only release does not use up a push from the plan's
limit. A change is any of: a module added, removed, updated, installed or
uninstalled; the Drupal core or PHP version; the report scope; the site URL,
name or UUID; the connector's own version. The comparison uses a hash kept in
state (`sentinel_connector.last_fingerprint`), which every accepted push
updates, whether it came from cron, "Sync now" or Drush.

| Outcome | Message | Exit code |
| --- | --- | --- |
| Nothing changed | "no change since the last accepted push" | 0 |
| Changed, push accepted | Success | 0 |
| Changed, held or refused by the [plan limit](#push-limits) | Warning with the next allowed time; cron sends the push after it | 0 |
| API URL, site UUID or API key missing | Warning, nothing sent | 0 |
| Inactive subscription, or any other failure | Error | non-zero |

Options and notes:

- `--force` pushes even when nothing changed.
- A site that is not configured exits with 0, so the same pipeline can run on
  environments that do not report to Sentinel.
- To keep a Sentinel outage from failing a deployment, ignore the exit code:
  `drush sentinel_connector:deploy || true`. Cron sends the push later.
- The command does not need cron pushes to be enabled, and does not replace
  them: cron also reports changes made outside a deployment.

Examples:

```yaml
# GitHub Actions / GitLab CI: last deployment step.
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

There is no interval setting. The module pushes on cron and sends at most one
push an hour, counted from the last attempt whatever its outcome. This floor is
fixed in code. How often a push is accepted is decided by Sentinel, by plan.

"Sync now" and the Drush commands are not bound by the hourly floor: Sentinel
enforces the real limit and answers with a clear message. They are still held
by a stored plan limit (below).

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
  channel with the plan, the limit and the next allowed time.
- The next allowed time is kept in state (`sentinel_connector.next_allowed_at`).
  Until it passes nothing is sent: cron skips, and a manual push shows the
  stored message without contacting Sentinel. Cron pushes on its first run
  after that time, once the hourly floor has also passed. The settings form
  shows the time under **Push limit**.
- A single response can hold pushes back for at most 7 days. To clear a stored
  limit by hand: `drush state:delete sentinel_connector.next_allowed_at`.

When the response has the error code but no usable message or time, the
connector uses a generic message and the `Retry-After` header. A 429 without
the error code is reported as `rate_limited`, as before.

A site on the Free plan therefore sends one refused push a day: the push an
hour after the accepted one tells the module when the next is allowed, and
nothing is sent until then.

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

A 402 without that error code is reported as `rejected`, as before.

## Status report

**Reports → Status report** (`/admin/reports/status`) has a *Sentinel
Connector* entry:

| State | Severity |
| --- | --- |
| API base URL, site UUID or API key missing | Error, with a link to the settings form |
| Last push refused over an inactive subscription | Error, with the reason |
| No push accepted in the last 24 hours | Error, with the last attempt, its result and its time |
| Configured less than 24 hours ago, no push accepted yet | Warning |
| Next push held by the plan limit, last accepted push under 24 hours old | Information, with the next allowed time |
| Last accepted push under 24 hours old | OK, with its time |

"Accepted" means Sentinel answered 200 or 202. A plan limit that ended less
than 6 hours ago also shows as information, so that a Free site does not
report an error between the end of its daily limit and the next cron run.

## Permissions

- *Administer Sentinel Connector* — access the settings form.
- *Trigger Sentinel sync* — use the "Sync now" button.

## Testing

```bash
composer install
# Unit, contract, and Kernel tests run in CI against current Drupal 10.x/PHP 8.3 and Drupal 11/PHP 8.4.
```

Connection failures return an actionable transport error and record the last attempt when Drupal state storage is available. Other payload/configuration errors return an internal error; cron catches remaining failures and attempts to record and log them without exposing request secrets. Storage failures may prevent persistence and return a state error.
Cron waits an hour after each attempt, including failures.
The settings page displays the last attempt separately from the last successful
sync. A 202 queued result is accepted for processing and does not imply success.

Payloads also report the configured `inventory_scope`, the connector's own
release as `connector_version`, per-extension `reported_project`, and
`version_known`.
Submodules retain their package project (for example, `webform_ui` reports
`webform`); custom names are never guessed as Drupal.org projects. Core
extensions report `drupal` and the installed core version.

The project comes from the `project` key that Drupal.org packaging adds to
`info.yml`. Modules installed from a git clone, a VCS Composer repository or a
dev checkout have no such key, so the connector then looks for the installed
`drupal/*` Composer package whose install path contains the module:

| Module source | `module_type` | `reported_project` |
| --- | --- | --- |
| Drupal core | `core` | `drupal` |
| `info.yml` has a valid `project` key | `contrib` | that project |
| No `project` key, inside a `drupal/*` package | `contrib` | package short name, e.g. `admin_toolbar` |
| Inside a `drupal/*` package with an unusable name | `contrib` | `null` (Sentinel shows "missing project") |
| Anything else | `custom` | `null` |

The root package, `drupal/core*` packages and `drupal-custom-*` package types
are ignored, so site-specific code in a path repository stays `custom`. A missing version
keeps the legacy `0.0.0` placeholder with `version_known: false`.
Sentinel uses this site-scoped identity to assess the persisted inventory
asynchronously. Inventory acceptance and upstream assessment are distinct;
narrow scopes, custom modules and missing metadata cannot establish whole-site
assessment coverage. The connector does not yet sign or poll requests.

The real local API smoke fixture lives at `tests/fixtures/connector_e2e.php`.
Provision a synthetic site and issue its key through the normal Sentinel UI/API,
then provide `SENTINEL_E2E_ALLOW=1`, `SENTINEL_E2E_API_URL` (base before
`/api/v1`), `SENTINEL_E2E_SITE_UUID`, `SENTINEL_E2E_SITE_URL`,
`SENTINEL_E2E_SITE_NAME` and `SENTINEL_E2E_API_KEY` in a private environment.
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
