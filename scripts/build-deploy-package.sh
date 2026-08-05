#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
output_dir="$project_dir/dist-deploy"
timestamp=$(date -u '+%Y%m%dT%H%M%SZ')
revision=$(git -C "$project_dir" rev-parse --short HEAD 2>/dev/null || printf 'uncommitted')
archive="$output_dir/kuka-island-${revision}-${timestamp}.tar.gz"

mkdir -p "$output_dir"
umask 077

tar -czf "$archive" \
	-C "$project_dir" \
	wp-content/themes/kuka-island-child \
	wp-content/plugins/kuka-island-core \
	docs/DEPLOY_RUNBOOK.md

if command -v shasum >/dev/null 2>&1; then
	shasum -a 256 "$archive" > "$archive.sha256"
elif command -v sha256sum >/dev/null 2>&1; then
	sha256sum "$archive" > "$archive.sha256"
else
	printf 'Uyarı: SHA-256 aracı bulunamadı; checksum üretilmedi.\n' >&2
fi

printf 'DEPLOY_PACKAGE=%s\n' "$archive"
