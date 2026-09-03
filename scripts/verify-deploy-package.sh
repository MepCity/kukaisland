#!/bin/sh
# The deploy package must contain everything the delivery needs, measured by
# building a real archive and reading it back.
#
# Host-side, because the packaging script runs on the host. Built into a
# throwaway directory so a verification run never leaves an artefact in
# dist-deploy, and removed afterwards even on failure.
#
# This is a CONTENT measurement, not a source scan of the tar command: an entry
# can be listed in the script and still be absent from the archive (a missing
# file, a path typo, a build run from the wrong directory).
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

tmp_dir=$(mktemp -d "${TMPDIR:-/tmp}/kuka-deploy-verify-XXXXXX")
chmod 700 "$tmp_dir"

cleanup() {
  rm -rf "$tmp_dir"
}
trap cleanup EXIT HUP INT TERM

if ! KUKA_DEPLOY_OUTPUT_DIR="$tmp_dir" ./scripts/build-deploy-package.sh >"$tmp_dir/build.log" 2>&1; then
  echo "DEPLOY_PACKAGE_CONTENTS=FAIL|reason:build_failed"
  exit 1
fi

archive=$(find "$tmp_dir" -name '*.tar.gz' -type f | head -n 1)
if [ -z "$archive" ]; then
  echo "DEPLOY_PACKAGE_CONTENTS=FAIL|reason:archive_not_produced"
  exit 1
fi

listing="$tmp_dir/listing.txt"
tar -tzf "$archive" > "$listing"

# Exact paths the delivery contract promises. The plugin directory is checked
# through its own entry point rather than as a bare directory name, so an empty
# directory entry cannot satisfy it.
missing=''
for required in \
  'wp-content/plugins/kuka-island-edm/kuka-island-edm.php' \
  'wp-content/plugins/kuka-island-edm/AGENTS.md' \
  'wp-content/plugins/kuka-island-edm/includes/class-plugin.php' \
  'wp-content/plugins/kuka-island-edm/includes/class-activator.php' \
  'wp-content/plugins/kuka-island-edm/includes/class-invoice.php' \
  'wp-content/plugins/kuka-island-edm/includes/invoice/class-edm-client.php' \
  'wp-content/plugins/kuka-island-edm/includes/invoice/class-invoice-runtime-gate.php' \
  'wp-content/plugins/kuka-island-shipping-automation/kuka-island-shipping-automation.php' \
  'wp-content/plugins/kuka-island-shipping-automation/AGENTS.md' \
  'wp-content/plugins/kuka-island-shipping-automation/includes/class-plugin.php' \
  'wp-content/plugins/kuka-island-shipping-automation/includes/class-activator.php' \
  'wp-content/plugins/kuka-island-shipping-automation/includes/class-shipping-automation.php' \
  'wp-content/plugins/kuka-island-shipping-automation/includes/shipping/class-shipment-runtime-gate.php' \
  'wp-content/plugins/kuka-island-shipping-automation/includes/shipping/dhl/class-dhl-client.php' \
  'wp-content/plugins/kuka-island-core/kuka-island-core.php' \
  'wp-content/themes/kuka-island-child/style.css' \
  'docs/DEPLOY_RUNBOOK.md' \
  'docs/KARGO_SCROLL_KORUMA_NOTU.md' \
  'docs/EDM_AKTIVASYON_REHBERI.md' \
  'docs/EDM_BAKIM_HAFIZASI.md' \
  'docs/EDM_ENTEGRASYONU.md' \
  'docs/DHL_AKTIVASYON_REHBERI.md' \
  'docs/DHL_BAKIM_HAFIZASI.md' \
  'docs/DHL_ENTEGRASYONU.md'
do
  if ! grep -Fqx "$required" "$listing"; then
    missing="$missing $required"
  fi
done

edm_entries=$(grep -c '^wp-content/plugins/kuka-island-edm/' "$listing" || true)
shipping_entries=$(grep -c '^wp-content/plugins/kuka-island-shipping-automation/' "$listing" || true)
checksum='no'
[ -f "$archive.sha256" ] && checksum='yes'

# Credentials must never travel in a package.
leaked=$(grep -Ec '(^|/)(\.env|edm-test\.env|dhl-sandbox\.env)$' "$listing" || true)

if [ -n "$missing" ] || [ "$checksum" != 'yes' ] || [ "$leaked" != '0' ]; then
  printf 'DEPLOY_PACKAGE_CONTENTS=FAIL|edm_entries:%s|shipping_entries:%s|checksum:%s|credential_files:%s|missing:%s\n' \
    "$edm_entries" "$shipping_entries" "$checksum" "$leaked" "${missing:-none}"
  exit 1
fi

printf 'DEPLOY_PACKAGE_CONTENTS=PASS|measured:built_archive_listing|required_paths:24|missing:none|edm_entries:%s|shipping_entries:%s|checksum:%s|credential_files:0|built_in_temp_dir:yes\n' \
  "$edm_entries" "$shipping_entries" "$checksum"
