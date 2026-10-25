# Release 1.3.0: configuration drift

1.2.0 is already published with Drupal 12 compatibility and payload v2.
Preserve its tag and release node. 1.3.0 is the separate minor release for
payload v3, drift reporting and the optional `sentinel_connector_config_pr`
submodule. Reuse [Drupal.org #3632886](https://www.drupal.org/project/sentinel_connector/issues/3632886)
for this follow-on; the Drupal 12 fixes are already delivered.

## Integration and review

- Merge the existing Drupal 12 integration PR
  [#33](https://github.com/truecms/sentinel_connector/pull/33) into
  `release/1297-config-drift`, preserving the published 1.2.0 changelog entry.
  This correction is stacked on that PR while it remains open; retarget it
  to the integration branch after #33 merges. It does not replace that work.
- Resolve all final-head lint, unit, contract and Kernel checks on Drupal
  10, 11 and 12, including the optional submodule. Contrib dependency
  compatibility with Drupal 12 is a release gate; do not suppress failures.
  The Drupal 12 test scaffold uses the documented Lenient Composer Plugin
  only for Config Ignore/Split and Key, whose upstream constraints still stop at 11.
  This tests their actual code; it does not change production dependencies or
  claim upstream Drupal 12 support for those optional contrib modules. Core
  supplies the PSR HTTP client/factories on all three majors; the GitLab client
  needs no Guzzle 7-only adapter or factory.
- Run `php scripts/check-release.php`. It checks release identity against the
  changelog, payload schema and upgrade instructions, and prevents v3 being
  released as 1.2.0. Obtain review on the final immutable head and follow normal
  protected-branch checks before merging the integration PR into `main`.

## Receiver first

The authoritative server plan is
[Sentinel #1312](https://github.com/truecms/sentinel/issues/1312), with migration
ordering in [#1314](https://github.com/truecms/sentinel/pull/1314).
The receiver step is currently blocked: [#1315](https://github.com/truecms/sentinel/pull/1315)
is open and its migration has not been tested on a copy of UAT. #1312's
promotion also contains unrelated billing, phone and Patching work; its newer
`pre-develop` head includes #1272 despite the older exclusion in the issue.
Resolve the intended receiver scope and migration evidence before promotion.
No canary UUID is established and no live GitHub/GitLab provider validation of
the optional submodule has been performed; its host coverage uses HTTP fixtures.

Older comments calling the drift release 1.2.0 refer to **1.3.0** instead,
including the optional submodule instructions in connector PR #31.

- Verify a scoped, reviewed receiver release on UAT, then production, accepts
  v2 and v3 before publishing this client. Keep existing endpoints and site
  credentials. Do not promote unrelated unreleased work as part of this fix.
- Use the live release plan, backups and recovery checks. Its prerequisite
  `e1242a0b0001` precedes new code, and phone expand migrations through
  `c1125a0b0001` precede a slot switch when that release is promoted. Follow
  #1314's deferral of `e1276a0b0002`; it belongs in a later release after the
  rollback window, never before the old slot is retired. Include the drift
  expand migration `c1299a0b0001` only in the reviewed migration chain.
- `CONFIG_DRIFT_ENABLED` stays false until the approved canary is ready.
  Preserve unrelated feature flags, billing/SMS settings and other sites.
  A successful v3 request with this flag off proves acceptance only, not drift
  reconciliation. Verify both distinctly in an approved isolated environment.

## Publication

After receiver compatibility, review and all required checks pass:

1. Confirm `1.3.0` is still unused on GitHub, Drupal.org Git and
   packages.drupal.org. Never move or overwrite an existing tag.
2. Date the 1.3.0 changelog entry, review that resulting head, then run
   `php scripts/check-release.php 1.3.0` and all required final-head checks.
3. Use the established Drupal.org merge/release workflow to bring the reviewed
   `main` contents to development branch `1.x`. Preserve the existing Drupal.org
   history; cross-host merge commits may differ. Compare module contents.
4. Tag the reviewed release contents as `1.3.0` on both hosts, create the
   Drupal.org 1.3.0 release node, and link #3632886. The release includes the
   optional submodule and update 10003. Do not add hardcoded `version` fields to
   source info files; Drupal.org adds package metadata for both modules.
5. Verify downloaded tar.gz and zip contents: connector 1.3.0, schema 3,
   preserved Drupal 12 code, optional module, matching package versions and
   runtime sources. Development files and both modules' tests are excluded by
   `.gitattributes`. Confirm Composer resolves the published archive from
   packages.drupal.org, rather than only observing the tag.
6. Verify `1.x-dev` resolves to the new development head and its downloaded
   archive contains the latest runtime code. Do not change a site's Composer
   reference merely to update the module's development distribution.

## Canary and receiver evidence

#1312 calls for one UAT test site and a first production site, but does not
identify a site UUID or repository. Resolve the established canary from live
operational records before a site write; do not infer authority to upgrade all
customers from the older bulk-upgrade checklist.

For each approved canary only, use its supported deployment workflow:

1. Require `drupal/sentinel_connector:^1.3`, run `drush updb`, export and commit
   `report_config_drift: true`, deploy, then run
   `drush sentinel_connector:deploy` last. Retain production-only overrides,
   API URL, site UUID and API key.
2. Check a fresh receiver-stored payload for that site and request time:
   `connector_version: 1.3.0`, `schema_version: 3`, a fresh `generated_at`,
   `config_drift.status`, object names/collections/change types and no values.
   A version string, client log or HTTP 200 alone is insufficient.
3. On the isolated UAT fixture, change one configuration object without
   exporting: the next received push must show that object's change. Restore
   the original value or import its baseline, push, and verify objects clear
   and the drift episode closes. Never mutate customer configuration for a test.
4. Verify a missing/unreadable sync directory yields an accepted `unavailable`
   block with the correct reason. Verify stale evidence becomes `unknown` by
   the configured stale threshold rather than remaining clean. Restore fixture
   settings afterwards. Also verify old v2 clients continue to push successfully.
5. Leave the optional configuration PR submodule disabled unless individually
   approved. If enabled for the canary, follow its README setup and dry-run
   checks; no token belongs in configuration, payloads or logs.

Record exact heads, checks, package hashes, target identifiers and redacted
receiver evidence in the release record. Claim only checks actually performed.
