#!/usr/bin/env bash
# Exercise the release gate with inconsistent release candidates.
set -euo pipefail
root=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/scripts" "$fixture/src" "$fixture/tests/fixtures" \
  "$fixture/modules/sentinel_connector_config_pr"
for path in scripts/check-release.php src/PayloadBuilder.php CHANGELOG.md README.md \
  tests/fixtures/drupal_sync.schema.json sentinel_connector.info.yml \
  modules/sentinel_connector_config_pr/sentinel_connector_config_pr.info.yml; do
  cp "$root/$path" "$fixture/$path"
done
check() { php "$fixture/scripts/check-release.php" "$@"; }
refuse() {
  expected=$1
  shift
  if check "$@" > "$fixture/check.log" 2>&1; then
    echo 'Release gate accepted an inconsistent candidate.' >&2
    exit 1
  fi
  if ! grep -qF "$expected" "$fixture/check.log"; then
    cat "$fixture/check.log" >&2
    echo "Release gate did not report: $expected" >&2
    exit 1
  fi
}
replace() {
  php -r '$p = $argv[1]; file_put_contents($p, str_replace($argv[2], $argv[3], file_get_contents($p)));' "$@"
}
check
version=$(php -r 'preg_match("/CONNECTOR_VERSION = '\''([^'\'']+)'\''/", file_get_contents($argv[1]), $m); echo $m[1];' "$fixture/src/PayloadBuilder.php")
# Even a matching tag must be refused until the release entry is dated.
php -r '$p = $argv[1]; file_put_contents($p, preg_replace("/^## " . preg_quote($argv[2], "/") . " - .+$/m", "## " . $argv[2] . " - Unreleased", file_get_contents($p), 1));' "$fixture/CHANGELOG.md" "$version"
refuse 'Date the changelog before tagging a release.' "$version"
cp "$root/CHANGELOG.md" "$fixture/CHANGELOG.md"
replace "$fixture/CHANGELOG.md" "## $version - Unreleased" "## $version - 2026-10-25"
check "$version"
refuse 'The proposed release tag must match CONNECTOR_VERSION.' 9.9.9
replace "$fixture/CHANGELOG.md" "## $version - " '## 9.9.9 - '
refuse 'The first changelog release must match CONNECTOR_VERSION.'
cp "$root/CHANGELOG.md" "$fixture/CHANGELOG.md"
replace "$fixture/src/PayloadBuilder.php" "CONNECTOR_VERSION = '$version'" "CONNECTOR_VERSION = '1.2.0'"
replace "$fixture/CHANGELOG.md" "## $version - " '## 1.2.0 - '
replace "$fixture/README.md" "## Upgrading to $version" '## Upgrading to 1.2.0'
refuse 'Payload v3 requires connector 1.3.0 or later; preserve published 1.2.0.'
cp "$root/src/PayloadBuilder.php" "$fixture/src/PayloadBuilder.php"
cp "$root/CHANGELOG.md" "$fixture/CHANGELOG.md"
cp "$root/README.md" "$fixture/README.md"
replace "$fixture/src/PayloadBuilder.php" 'SCHEMA_VERSION = 3' 'SCHEMA_VERSION = 4'
refuse 'The payload contract fixture must match SCHEMA_VERSION.'
cp "$root/src/PayloadBuilder.php" "$fixture/src/PayloadBuilder.php"
printf "\nversion: '1.2.0'\n" >> "$fixture/modules/sentinel_connector_config_pr/sentinel_connector_config_pr.info.yml"
refuse 'has a different packaged version.'
rm "$fixture/modules/sentinel_connector_config_pr/sentinel_connector_config_pr.info.yml"
refuse 'must be present and readable.'
echo 'Release gate regression checks passed.'
