#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
# Overridable so the verification suite can build a throwaway package into a
# temporary directory instead of littering dist-deploy on every run.
output_dir="${KUKA_DEPLOY_OUTPUT_DIR:-$project_dir/dist-deploy}"
timestamp=$(date -u '+%Y%m%dT%H%M%SZ')
revision=$(git -C "$project_dir" rev-parse --short HEAD 2>/dev/null || printf 'uncommitted')
archive="$output_dir/kuka-island-${revision}-${timestamp}.tar.gz"

mkdir -p "$output_dir"
umask 077

# kuka-island-edm and kuka-island-shipping-automation both travel in the package
# and are both delivered INACTIVE. Shipping them is what lets an operator
# activate one deliberately later, with its activation checklist in hand;
# leaving one out would mean a rushed manual upload on the day fiscal documents
# -- or carrier labels -- are wanted.
#
# Each plugin's three documents ship WITH it, and that is not tidiness. Both
# AGENTS.md files instruct whoever works on the module to read the maintenance
# memory and the activation guide FIRST; a package containing the instruction
# but not the documents it points at is a package that cannot be acted on. The
# technical contracts travel too, because the activation guides reference them
# at almost every step, and the fulfilment-drawer protection note travels
# because both modules are forbidden from touching what it describes.
tar -czf "$archive" \
	-C "$project_dir" \
	wp-content/themes/kuka-island-child \
	wp-content/plugins/kuka-island-core \
	wp-content/plugins/kuka-island-edm \
	wp-content/plugins/kuka-island-shipping-automation \
	docs/DEPLOY_RUNBOOK.md \
	docs/KARGO_SCROLL_KORUMA_NOTU.md \
	docs/EDM_AKTIVASYON_REHBERI.md \
	docs/EDM_BAKIM_HAFIZASI.md \
	docs/EDM_ENTEGRASYONU.md \
	docs/DHL_AKTIVASYON_REHBERI.md \
	docs/DHL_BAKIM_HAFIZASI.md \
	docs/DHL_ENTEGRASYONU.md

if command -v shasum >/dev/null 2>&1; then
	shasum -a 256 "$archive" > "$archive.sha256"
elif command -v sha256sum >/dev/null 2>&1; then
	sha256sum "$archive" > "$archive.sha256"
else
	printf 'Uyarı: SHA-256 aracı bulunamadı; checksum üretilmedi.\n' >&2
fi

printf 'DEPLOY_PACKAGE=%s\n' "$archive"
