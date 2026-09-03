#!/bin/sh
# Run ONE allow-listed READ-ONLY verification script inside the wp-cli container
# with the local DHL sandbox credential file bind-mounted read-only.
#
# The allow-list is deliberately a single entry. The credential file must never
# be mountable for an arbitrary PHP file: a script name is attacker- or
# typo-controlled input, and any other script could exfiltrate or misuse the
# values. The write tool has its own separate wrapper
# (scripts/dhl-sandbox-run.sh) and is NOT reachable from here.
#
# The credential file is mounted rather than exported as container environment
# variables: values passed with `-e` remain readable through `docker inspect`
# for as long as the container object exists.
#
# TWO MODES.
#
#   ./scripts/dhl-test-run.sh test-dhl-sandbox.php
#       The operator command. Mounts the credentials and runs the script, which
#       contacts the carrier's Identity and CBS endpoints read-only.
#
#   ./scripts/dhl-test-run.sh --check-script=<name>
#       OFFLINE. Answers one question -- would this name be allow-listed? --
#       and answers it from the string alone. It does not read, stat or mount
#       the credential file, does not start Docker, does not run PHP and makes
#       no network call of any kind. It exists because the verification suite
#       needs the allow-list DECISION, and the suite must never be able to
#       trigger a real carrier call while asking for it.
#
# Why the offline mode is not optional: verify.sh used to obtain the decision by
# running the real command and reading only its first line through `head -n 1`.
# That works only because the closed pipe kills `docker compose run` before the
# container's PHP starts -- a SIGPIPE race, not a guarantee. With credentials in
# place, a slower pipe reader or a faster container would have made `make verify`
# authenticate against the carrier.
set -eu

# --- Allow-list ------------------------------------------------------------
# Exactly one read-only script may receive the credential mount.
ALLOWED_SCRIPT='test-dhl-sandbox.php'

# The whole allow-list decision, as a pure function of the given name.
#
# Echoes 'allow_listed', or the single reason the name is refused. Reads no
# file, starts no process and contacts nothing: both modes below share it, so
# the offline answer cannot drift from the enforced one.
allowlist_reason() {
  candidate=${1:-}

  if [ -z "$candidate" ]; then
    echo 'no_script_given'
    return 0
  fi

  case "$candidate" in
    /*) echo 'absolute_path_refused'; return 0 ;;
  esac
  case "$candidate" in
    *..*) echo 'path_traversal_refused'; return 0 ;;
  esac
  case "$candidate" in
    */*) echo 'slash_in_script_name_refused'; return 0 ;;
  esac
  case "$candidate" in
    *[!A-Za-z0-9._-]*) echo 'unexpected_character_in_script_name'; return 0 ;;
  esac

  if [ "$candidate" != "$ALLOWED_SCRIPT" ]; then
    echo 'script_not_allow_listed'
    return 0
  fi

  echo 'allow_listed'
}

# --- Offline mode ----------------------------------------------------------
# Handled first, before the credential path is even constructed.
case "${1:-}" in
  --check-script|--check-script=*)
    case "${1:-}" in
      --check-script) check_candidate='' ;;
      *)              check_candidate="${1#--check-script=}" ;;
    esac

    check_reason=$(allowlist_reason "$check_candidate")

    if [ "$check_reason" = 'allow_listed' ]; then
      check_listed='yes'
    else
      check_listed='no'
    fi

    printf 'DHL_TEST_RUN=CHECK|mode:offline_allowlist_check|script:%s|allow_listed:%s|reason:%s|credentials_read:no|docker_started:no|php_started:no|network_calls:0\n' \
      "${check_candidate:-none}" "$check_listed" "$check_reason"

    # Always 0: the verdict is the line, not the exit status, so a caller under
    # `set -e` can read the decision without the shell aborting on a refusal.
    exit 0
    ;;
esac

# --- Enforced mode ---------------------------------------------------------
script_name="${1:-}"
reason=$(allowlist_reason "$script_name")

if [ "$reason" = 'no_script_given' ]; then
  echo "DHL_TEST_RUN=BLOCKED|reason:no_script_given|allowed:$ALLOWED_SCRIPT" >&2
  exit 64
fi

if [ "$reason" != 'allow_listed' ]; then
  echo "DHL_TEST_RUN=BLOCKED|reason:$reason" >&2

  if [ "$reason" = 'script_not_allow_listed' ]; then
    echo "DHL_TEST_RUN=BLOCKED|reason:script_not_allow_listed|allowed:$ALLOWED_SCRIPT" >&2
    echo "The sandbox write tool runs only from ./scripts/dhl-sandbox-run.sh" >&2
  fi

  exit 1
fi

shift

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

if [ ! -f "scripts/$script_name" ]; then
  echo "DHL_TEST_RUN=BLOCKED|reason:allow_listed_script_missing_on_disk" >&2
  exit 1
fi

cred_dir="${XDG_CONFIG_HOME:-$HOME/.config}/kuka-island"
cred_file="$cred_dir/dhl-sandbox.env"

case "$cred_file" in
  "$project_dir"/*)
    echo "DHL_TEST_RUN=BLOCKED|reason:credential_path_inside_repository" >&2
    exit 1
    ;;
esac

if [ ! -f "$cred_file" ]; then
  echo "DHL_TEST_RUN=BLOCKED|reason:credentials_file_absent"
  echo "Create it first (nothing is echoed):  ./scripts/dhl-test-credentials.sh"
  exit 0
fi

mode=$(stat -f '%Lp' "$cred_file" 2>/dev/null || stat -c '%a' "$cred_file" 2>/dev/null || echo "unknown")
if [ "$mode" != "600" ]; then
  echo "DHL_TEST_RUN=BLOCKED|reason:credentials_file_mode_not_600|mode:$mode" >&2
  echo "Fix with:  chmod 600 \"$cred_file\"" >&2
  exit 1
fi

echo "DHL_TEST_RUN=STARTING|script:$script_name|allow_listed:yes|credentials:mounted_read_only|mode:$mode|writes:none"

exec docker compose run --rm -T \
  -v "$cred_file":/run/dhl/dhl-sandbox.env:ro \
  wp-cli wp eval-file "/project-scripts/$script_name" "$@"
