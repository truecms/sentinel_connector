# Sentinel Connector

A Drupal 10/11 module that reports a site's installed extensions and environment
to the [Sentinel](https://github.com/truecms/sentinel) monitoring platform.
Sentinel computes update and security status; this module only collects an
authoritative snapshot and submits it.

## Project and distribution

The public module name is **Sentinel Connector**. `SentinelConnector.io` is the
primary registered domain; `SentinelConnector.com` is the companion domain.

The Drupal.org project page is pending. Until the project and releases are
published there, obtain the module source from the
[GitHub repository](https://github.com/truecms/sentinel_connector). Place the
source checkout in your site's custom module directory, typically
`web/modules/custom/sentinel_connector`, then enable **Sentinel Connector** on
Drupal's Extend page. The machine name remains `sentinel_connector`.

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
| Cron interval | Minimum seconds between cron syncs (default 6h) |

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

## Triggering a sync

- **Cron** — automatic, throttled to the configured interval (under Sentinel's 100/hour cap).
- **Drush** — `drush sentinel_connector:sync` (alias `sc-sync`).
- **Admin button** — "Sync now" on the settings form (requires the *Trigger Sentinel sync* permission).

## Permissions

- *Administer Sentinel Connector* — access the settings form.
- *Trigger Sentinel sync* — use the "Sync now" button.

## Testing

```bash
composer install
# Unit, contract, and Kernel tests run in CI against current Drupal 10.x/PHP 8.3 and Drupal 11/PHP 8.4.
```

Connection failures return an actionable transport error and record the last attempt when Drupal state storage is available. Other payload/configuration errors return an internal error; cron catches remaining failures and attempts to record and log them without exposing request secrets. Storage failures may prevent persistence and return a state error.
Cron waits for the configured interval after each attempt, including failures.
The settings page displays the last attempt separately from the last successful
sync. A 202 queued result is accepted for processing and does not imply success.

Payloads also report the configured `inventory_scope`, per-extension
`reported_project` from Drupal packaging metadata, and `version_known`.
Submodules retain their package project (for example, `webform_ui` reports
`webform`); custom names are never guessed as Drupal.org projects. Core
extensions report `drupal` and the installed core version. A missing version
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

The fixture requires a real successful response, checks attempt/success state,
prints only status and runtime versions, and restores the original connector
configuration and state. Run only against isolated local Drupal and Sentinel
fixtures; do not save keys or credentialed URLs in the repository or logs.
The Kernel suite also runs Drupal's actual cron service against an unreachable
local endpoint and covers malformed URI, invalid UTF-8, typed payload errors,
and unavailable state storage.
