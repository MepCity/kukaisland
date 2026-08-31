#!/bin/sh
# Run a project verification script inside the wp-cli container with the local
# EDM test credential file bind-mounted read-only.
#
# The credential file is mounted rather than exported as container environment
# variables: `-e` values stay readable through `docker inspect` for as long as
# the container object exists, whereas a read-only bind mount does not surface
# there.
#
# Usage: ./scripts/edm-test-run.sh <script-name.php> [extra wp args...]
#        ./scripts/edm-test-run.sh test-edm-sandbox.php
set -eu

script_name="${1:-}"
if [ -z "$script_name" ]; then
  echo "Usage: $0 <script-name.php> [extra args]" >&2
  exit 64
fi
shift

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

if [ ! -f "scripts/$script_name" ]; then
  echo "EDM_TEST_RUN=BLOCKED|reason:script_not_found:$script_name" >&2
  exit 1
fi

cred_dir="${XDG_CONFIG_HOME:-$HOME/.config}/kuka-island"
cred_file="$cred_dir/edm-test.env"

case "$cred_file" in
  "$project_dir"/*)
    echo "EDM_TEST_RUN=BLOCKED|reason:credential_path_inside_repository" >&2
    exit 1
    ;;
esac

if [ ! -f "$cred_file" ]; then
  echo "EDM_TEST_RUN=BLOCKED|reason:credentials_file_absent"
  echo "Create it first (nothing is echoed):  ./scripts/edm-test-credentials.sh"
  exit 0
fi

mode=$(stat -f '%Lp' "$cred_file" 2>/dev/null || stat -c '%a' "$cred_file" 2>/dev/null || echo "unknown")
if [ "$mode" != "600" ]; then
  echo "EDM_TEST_RUN=BLOCKED|reason:credentials_file_mode_not_600|mode:$mode" >&2
  echo "Fix with:  chmod 600 \"$cred_file\"" >&2
  exit 1
fi

echo "EDM_TEST_RUN=STARTING|script:$script_name|credentials:mounted_read_only|mode:$mode"

exec docker compose run --rm -T \
  -v "$cred_file":/run/edm/edm-test.env:ro \
  wp-cli wp eval-file "/project-scripts/$script_name" "$@"
