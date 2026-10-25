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

For a Drupal 12 site using either optional integration, prefer a supported
upstream release containing the fix. Otherwise explicitly review the relevant
patch in the site's existing supported patch workflow before enabling it:

1. Use Composer Patches 2 in the site's root project. It must be available
   during the production build, including builds using `--no-dev`.
2. Copy the needed patch and its expanded definition from this directory into
   the site's patch tree. Merge the definition into its existing patch file;
   preserve other patches and adjust only the local URL. Keep the checksum and
   depth. Do not replace an existing patch configuration automatically.
3. Use `extra.composer-patches.patches-file` (the Composer Patches 2 namespace),
   then `composer patches-relock` and `composer patches-repatch`. Commit the
   root Composer lock and patch lock through the site's normal workflow.
4. Verify the actual installed code, not just the patch lock. In this source
   checkout, `php scripts/check-compatibility-patches.php /path/to/test-project`
   verifies checksums, locks and installed signatures. Run the integration
   tests and normal site checks before deployment.

Do not patch customer sites as an inference from this CI setup. Remove the
patch and lenient exception only after updating to an upstream version that
contains the fix and rerunning the complete matrix. A new version that makes
the patch unnecessary should fail application until that deliberate update.
