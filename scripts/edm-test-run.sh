#!/bin/sh
# Run ONE allow-listed read-only verification script inside the wp-cli container
# with the local EDM test credential file bind-mounted read-only.
#
# The allow-list is deliberately a single entry. The credential file must never
# be mountable for an arbitrary PHP file: a script name is attacker- or
# typo-controlled input, and any other script could exfiltrate or misuse the
# values. The sandbox write tool has its own separate wrapper
# (scripts/edm-sandbox-run.sh) and is NOT reachable from here.
#
# The credential file is mounted rather than exported as container environment
# variables: values passed with `-e` remain readable through `docker inspect`
# for as long as the container object exists.
#
# Usage: ./scripts/edm-test-run.sh test-edm-sandbox.php
set -eu

# --- Allow-list ------------------------------------------------------------
# Exactly one read-only script may receive the credential mount.
ALLOWED_SCRIPT='test-edm-sandbox.php'

script_name="${1:-}"
if [ -z "$script_name" ]; then
  echo "EDM_TEST_RUN=BLOCKED|reason:no_script_given|allowed:$ALLOWED_SCRIPT" >&2
  exit 64
fi
shift

# Reject anything that is not a bare, allow-listed file name. Each condition is
# reported separately so a refusal is never ambiguous.
case "$script_name" in
  /*)
    echo "EDM_TEST_RUN=BLOCKED|reason:absolute_path_refused" >&2
    exit 1
    ;;
esac
case "$script_name" in
  *..*)
    echo "EDM_TEST_RUN=BLOCKED|reason:path_traversal_refused" >&2
    exit 1
    ;;
esac
case "$script_name" in
  */*)
    echo "EDM_TEST_RUN=BLOCKED|reason:slash_in_script_name_refused" >&2
    exit 1
    ;;
esac
case "$script_name" in
  *[!A-Za-z0-9._-]*)
    echo "EDM_TEST_RUN=BLOCKED|reason:unexpected_character_in_script_name" >&2
    exit 1
    ;;
esac
if [ "$script_name" != "$ALLOWED_SCRIPT" ]; then
  echo "EDM_TEST_RUN=BLOCKED|reason:script_not_allow_listed|allowed:$ALLOWED_SCRIPT" >&2
  echo "The sandbox write tool runs only from ./scripts/edm-sandbox-run.sh" >&2
  exit 1
fi

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

if [ ! -f "scripts/$script_name" ]; then
  echo "EDM_TEST_RUN=BLOCKED|reason:allow_listed_script_missing_on_disk" >&2
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

echo "EDM_TEST_RUN=STARTING|script:$script_name|allow_listed:yes|credentials:mounted_read_only|mode:$mode"

exec docker compose run --rm -T \
  -v "$cred_file":/run/edm/edm-test.env:ro \
  wp-cli wp eval-file "/project-scripts/$script_name" "$@"
