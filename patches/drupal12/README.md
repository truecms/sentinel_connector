# Drupal 12 optional integration prerequisites

The connector itself retains Drupal 10.3, 11 and 12 support. Config Split and
Key are optional integrations. Their unpatched stable versions below cannot
run on Drupal 12; testing only Composer constraint relaxation is insufficient.
These files use the normal Composer Patches 2 workflow and are **not applied
automatically when this module is installed as a dependency**.

## Pinned sources

- **Key 1.22.0:** the complete official upstream [MR !79](https://git.drupalcode.org/project/key/-/merge_requests/79)
  for [#3632542](https://www.drupal.org/project/key/issues/3632542), head
  `d307ab5f81e65f3a2452417e9bc8dad40a852bea`, base
  `e0a32405aca3c1c570d6880f5f03524abea25d90`. The maintainer's issue comments
  describe the attribute conversion and Drupal 10 compatibility. This fixes
  annotation-only plugin discovery removed by Drupal 12, without moving to
  the incompatible Key 2.x API.
- **Config Split 2.0.2:** only the `getSubscribedEvents(): array` change in
  `SplitImportExportSubscriber` from official upstream [MR !62](https://git.drupalcode.org/project/config_split/-/merge_requests/62),
  head `d217f1cf2cb446684bfa4a99818ad353d1566dff`, tracked by
  [#3596578](https://www.drupal.org/project/config_split/issues/3596578).
  The full MR targets newer development code and does not apply to 2.0.2;
  this one-line excerpt applies cleanly to that stable release. It adds the
  return type required by Symfony 8 and changes no comparison behaviour.

Both are upstream changes awaiting merge/release, not claims that the stable
packages already support Drupal 12. `patches.json` records exact SHA-256 hashes
and depth. A changed file or missing patch must fail the build. No third-party
package is forked or published by this project.

## CI and site builds

CI pins the two dependency versions, applies these patches only in its Drupal
12 scaffold, then runs the same required Unit, contract and Kernel suites.
Config Ignore, Config Split and Key remain on the narrowly scoped Composer
Lenient allow-list until their package constraints admit Drupal 12.

## Site installation and updates are a separate gate

These patches establish CI runtime coverage only. They are not a complete
Drupal 12 site installation or upgrade recipe. Kernel tests enable modules
without the normal installer compatibility check. The Config Split excerpt
and unpatched Config Ignore still have module `core_version_requirement`
metadata excluding Drupal 12. Composer Lenient only relaxes Composer metadata;
it does not change Drupal's module install/update requirements. Therefore
Config Split 2.0.2 and Config Ignore 3.4 cannot pass those Drupal 12 checks with
this CI recipe alone. Key's complete upstream patch includes its module
metadata change.

A Drupal 12 site using these optional modules needs compatible upstream
releases, or a separately reviewed complete upstream compatibility change
covering runtime code and install/update metadata through its normal workflow.
Do not bypass Drupal's installer requirements or treat a successful Kernel run
as proof that site updates work. The connector's own Drupal 12 support remains
covered by the matrix; no optional integration is required to use it.

If a site explicitly adopts an upstream patch through an established workflow:

1. Scope Composer Lenient, where still necessary, only to the incompatible
   Composer constraints of the packages actually used by that site. The CI
   allow-list is `drupal/config_ignore`, `drupal/config_split`, `drupal/key`.
   An upstream patch does not itself change repository Composer metadata.
2. Use Composer Patches 2 in the site's root project, available during the
   production build including `--no-dev` builds. Merge relevant definitions
   into the existing patch configuration; preserve unrelated patches, hashes
   and depth, adjusting local URLs as needed. Do not replace a site's patch
   configuration or adopt these runtime excerpts automatically.
3. Use `extra.composer-patches.patches-file` (the version 2 namespace), then
   `composer patches-relock` and `composer patches-repatch`. Commit both locks
   through the normal site workflow. Verify the actual installed code, module
   metadata, install/update paths and site tests before deployment.

`php scripts/check-compatibility-patches.php /path/to/test-project` is a CI
scaffold verifier only: it expects both pinned integrations under
`web/modules/contrib/`. It does not support arbitrary site layouts or partial
patch adoption, and is not shipped as a site administration command.

Do not patch customer sites as an inference from this CI setup. Remove patches
and lenient exceptions only after updating to upstream versions containing the
fixes and rerunning the complete matrix. A new version making a patch
unnecessary should fail application until that deliberate update.
