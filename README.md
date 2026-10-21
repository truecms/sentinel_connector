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

The payload is `schema_version: 2`. Its `platform` block reports the stack
Sentinel matches against known vulnerabilities:

| Part | Fields | Source |
|------|--------|--------|
| `php` | `version`, `sapi` | `PHP_VERSION`, `PHP_SAPI` |
| `web_server` | `software`, `version`, `raw` | `SERVER_SOFTWARE` of a web request |
| `database` | `driver`, `product`, `version` | The Drupal database connection; `product` is `mariadb` when the version says MariaDB |
| `os` | `family`, `distribution`, `release` | `PHP_OS_FAMILY`; `ID` and `VERSION_ID` from `/etc/os-release` |

Cron and Drush have no web server, so the module keeps the `SERVER_SOFTWARE`
of the last web request in state (`sentinel_connector.web_server_software`)
and sends that. With the version hidden (`server_tokens off`) only the
software is sent. A value the module cannot read is left out and never fails
the sync; Sentinel shows it as unknown.

## Configuration

Admin UI: **Configuration → Web services → Sentinel Connector**
(`/admin/config/services/sentinel-connector`).

| Setting | Description |
| --- | --- |
| API base URL | Sentinel base URL, e.g. `https://sentinel.example.com` |
| Site UUID | The registered site's UUID (must match Sentinel). Saved and sent in lower case with hyphens; see below |
| Site URL | Must match the URL registered in Sentinel |
| Report scope | `all` (default), `contrib_custom`, or `contrib` |

### API key (secret — never stored in exported config)

In Sentinel, open the registered site's dashboard and choose **Create API key**
(or **Rotate API key** to replace existing credentials). Copy the displayed key
once into Drupal; Sentinel stores its hash and never returns it on later reads.
The Site UUID and Site URL on the dashboard must match this module's settings.
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

1. `$settings['sentinel_connector.api_key']` in `settings.php` (recommended for production)
2. Environment variable `SENTINEL_CONNECTOR_API_KEY` (host-set, never in code)
3. Drupal state (set via the settings form), for simple setups

The API key is the only credential. There is no site token: paste the key from
the Sentinel dashboard into **API key** on the settings form, or set it in one
of the first two places. The form never shows a saved key; it shows where the
active key comes from, and a blank field keeps the current key. Drupal state is
not encrypted.

## Triggering a sync

- **Cron** — automatic when enabled on the settings form. Pushes only when the inventory changed, at most once an hour; see [Push frequency](#push-frequency).
- **Drush** — `drush sentinel_connector:sync` (alias `sc-sync`).
- **Deployment** — `drush sentinel_connector:deploy` (alias `sc-deploy`) from the CI/CD pipeline; see [Notify Sentinel on deployment](#notify-sentinel-on-deployment).
- **Admin button** — "Sync now", next to "Save configuration" on the settings form (requires the *Trigger Sentinel sync* permission). It appears once the saved settings hold the API base URL, a valid Site UUID and an API key.

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
| API URL, site UUID or API key missing, or site UUID not a UUID | Warning, nothing sent | 0 |
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
  the IP address, plus the API base URL. Any difference, down to one
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
| `success` | 200 | | info | The inventory was stored. The message is Sentinel's own when it sends one. |
| `accepted` | 202 | | info | The inventory was queued; the entry has the task ID. |
| `push_deferred` | none | | debug | A stored plan limit held the push back; no request was sent. |
| `push_limited` | 429 | `push_limit_reached` | notice | The plan's push limit was reached. |
| `rate_limited` | 429 | `site_rate_limited`, `auth_rate_limited` | notice | Sentinel's general rate limit, or its limit on authentication attempts, was reached. The message is Sentinel's own, with the `Retry-After` wait. |
| `subscription_inactive` | 402 | `subscription_inactive` | warning | The subscription is overdue, unpaid or paused. |
| `site_mismatch` | 400 | `site_url_mismatch`, `site_uuid_mismatch` | warning | The site URL or UUID differs from the site registered in Sentinel. |
| `validation_failed` | 400 | `module_validation_failed`, `module_not_on_drupal_org` | warning | Sentinel rejected module names or versions, or a module that is not on drupal.org. The message has the count and the first five names when Sentinel lists them. |
| `not_found` | 404 | `site_unavailable` | warning | Sentinel does not know the site, the site or its organisation is inactive, or the API base URL is wrong. |
| `conflict` | 409 | `catalog_conflict`, `catalog_entry_unavailable` | warning | Sentinel could not store a reported module. |
| `unconfigured` | none | | warning; debug on cron | The API URL, site UUID or API key is missing, or the site UUID is not a UUID; no request was sent. |
| `signature_refused` | 401 | `signature_missing`, `signature_invalid`, `timestamp_skew`, `replay_detected` | error | The request signature was refused; the entry has Sentinel's code. |
| `auth_error` | 401, 403 | `authentication_required`, `api_key_invalid`, `api_key_inactive`, `permission_denied`, `site_mismatch` | error | The API key is missing, wrong, expired or inactive, lacks permission, or belongs to another site. |
| `invalid_payload` | 400, 422 | `payload_invalid`, `json_invalid`, `body_unreadable` | error | Sentinel could not read or decode the payload. The message has the first field at fault when Sentinel names one. |
| `unavailable` | 503 | `rate_limiter_unavailable`, `replay_protection_unavailable`, `queue_unavailable`, `inventory_busy`, `auth_rate_limiter_unavailable` | error | Sentinel is temporarily unavailable. With one of these codes the message has the `Retry-After` wait. |
| `server_error` | other 5xx | `internal_error` | error | Sentinel failed. |
| `transport_error` | none | | error | Sentinel could not be reached. |
| `invalid_response` | any | | error | The response was not the JSON the connector expects. |
| `rejected` | other | | error | Any other refusal. |
| `internal_error`, `state_error` | none | | error | The push failed on this site; the entry has the error class. Not to be confused with Sentinel's `internal_error` code, which is a `server_error`. |

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
| API base URL, site UUID or API key missing, or site UUID not a UUID | Error, with a link to the settings form |
| Last push refused over an inactive subscription | Error, with the reason |
| No push accepted and no unchanged check in the last 24 hours | Error, with the last attempt, its result and its time |
| Configured less than 24 hours ago, no push accepted yet | Warning |
| Next push held by the plan limit, last accepted push under 24 hours old | Information, with the next allowed time |
| Last accepted push under 24 hours old | OK, with its time |
| Last accepted push older, cron found no change in the last 24 hours | OK, with the time of the push and of the last check |

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
alone, so the `all` and `contrib_custom` scopes list the same modules. Custom
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
