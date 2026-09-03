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

# kuka-island-edm travels in the package but is delivered INACTIVE. Shipping it
# is what lets an operator activate it deliberately later, with the activation
# checklist in hand; leaving it out would mean a rushed manual upload on the day
# fiscal documents are wanted.
#
# The three EDM documents ship WITH it, and that is not tidiness. The plugin's
# own AGENTS.md instructs whoever works on it to read the maintenance memory and
# the activation guide FIRST; a package containing the instruction but not the
# documents it points at is a package that cannot be acted on. The technical
# contract travels too, because the activation guide references it at almost
# every step.
tar -czf "$archive" \
	-C "$project_dir" \
	wp-content/themes/kuka-island-child \
	wp-content/plugins/kuka-island-core \
	wp-content/plugins/kuka-island-edm \
	docs/DEPLOY_RUNBOOK.md \
	docs/EDM_AKTIVASYON_REHBERI.md \
	docs/EDM_BAKIM_HAFIZASI.md \
	docs/EDM_ENTEGRASYONU.md

if command -v shasum >/dev/null 2>&1; then
	shasum -a 256 "$archive" > "$archive.sha256"
elif command -v sha256sum >/dev/null 2>&1; then
	sha256sum "$archive" > "$archive.sha256"
else
	printf 'Uyarı: SHA-256 aracı bulunamadı; checksum üretilmedi.\n' >&2
fi

printf 'DEPLOY_PACKAGE=%s\n' "$archive"
