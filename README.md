# Sentinel Connector

A Drupal 10/11 module that reports a site's installed extensions and environment
to the [Sentinel](https://github.com/truecms/sentinel) monitoring platform.
Sentinel computes update and security status; this module only collects an
authoritative snapshot and submits it.

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

Resolved in this order:

1. `$settings['sentinel_connector.api_key']` in `settings.php` (recommended for production)
2. Environment variable `SENTINEL_CONNECTOR_API_KEY` (host-set, never in code)
3. Drupal state (set via the settings form), for simple setups

## Triggering a sync

- **Cron** — automatic, throttled to the configured interval (under Sentinel's 4/hour cap).
- **Drush** — `drush sentinel_connector:sync` (alias `sc-sync`).
- **Admin button** — "Sync now" on the settings form (requires the *Trigger Sentinel sync* permission).

## Permissions

- *Administer Sentinel Connector* — access the settings form.
- *Trigger Sentinel sync* — use the "Sync now" button.

## Testing

```bash
composer install
# Unit + contract tests run in CI against Drupal 10.3/PHP 8.3 and Drupal 11/PHP 8.4.
```
