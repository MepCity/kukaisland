#!/bin/sh
# Run the isolated EDM sandbox invoice experiment.
#
# Default behaviour is PLAN: nothing is created. Creating the single test
# document requires BOTH gates:
#   1. KUKA_EDM_ALLOW_SANDBOX_WRITE=true   (literal)
#   2. confirm=LoadInvoice                 (must name the planned operation)
#
# The confirmation is passed BARE, without leading dashes: `wp eval-file`
# forwards positional arguments only and rejects `--confirm=...` as one of its
# own unknown parameters before the script runs.
#
# Usage:
#   ./scripts/edm-sandbox-run.sh                                            # PLAN
#   KUKA_EDM_ALLOW_SANDBOX_WRITE=true ./scripts/edm-sandbox-run.sh confirm=LoadInvoice
#
# Reconciliation reset (uncertain -> idle only). Fully offline: no credential
# file is required and none is mounted, so no EDM call is reachable:
#   ./scripts/edm-sandbox-run.sh reset=document_absent_at_edm audit=<label>
#
# Mounts:
#   credential file  -> /run/edm/edm-test.env      read-only
#   state directory  -> /run/edm/state             read-write (JSON only, no DB)
#
# The read-only probe must pass first:
#   ./scripts/edm-test-run.sh test-edm-sandbox.php
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

cred_dir="${XDG_CONFIG_HOME:-$HOME/.config}/kuka-island"
cred_file="$cred_dir/edm-test.env"
state_dir="$cred_dir/edm-sandbox-state"

# The reconciliation reset touches the state file and nothing else. It gets no
# credential mount at all, so the offline claim in the PHP driver is enforced
# here too rather than merely asserted: with no file at /run/edm/edm-test.env
# the credential loader has nothing to read even if the code path changed.
reset_mode=no
for arg in "$@"; do
  case "$arg" in
    reset=*) reset_mode=yes ;;
  esac
done

case "$cred_file" in
  "$project_dir"/*)
    echo "EDM_SANDBOX_RUN=BLOCKED|reason:credential_path_inside_repository" >&2
    exit 1
    ;;
esac

umask 077
mkdir -p "$state_dir"
chmod 700 "$state_dir"

if [ "$reset_mode" = "yes" ]; then
  # No credential file requirement, no credential mount, no write env var.
  echo "EDM_SANDBOX_RUN=RECONCILIATION_RESET|credentials_mounted:no|write_env_forwarded:no|state_only:yes"
  exec docker compose run --rm -T \
    -v "$state_dir":/run/edm/state \
    wp-cli wp eval-file /project-scripts/edm-sandbox-invoice.php "$@"
fi

if [ ! -f "$cred_file" ]; then
  echo "EDM_SANDBOX_RUN=BLOCKED|reason:credentials_file_absent"
  echo "Create it first (nothing is echoed):  ./scripts/edm-test-credentials.sh"
  exit 0
fi

mode=$(stat -f '%Lp' "$cred_file" 2>/dev/null || stat -c '%a' "$cred_file" 2>/dev/null || echo "unknown")
if [ "$mode" != "600" ]; then
  echo "EDM_SANDBOX_RUN=BLOCKED|reason:credentials_file_mode_not_600|mode:$mode" >&2
  exit 1
fi

allow="${KUKA_EDM_ALLOW_SANDBOX_WRITE:-}"
if [ "$allow" = "true" ]; then
  echo "EDM_SANDBOX_RUN=WRITE_GATE_OPEN|env_gate:true|operation_confirmation_still_required"
else
  echo "EDM_SANDBOX_RUN=PLAN_ONLY|env_gate:absent_or_not_literal_true|nothing_will_be_created"
fi

exec docker compose run --rm -T \
  -e KUKA_EDM_ALLOW_SANDBOX_WRITE \
  -v "$cred_file":/run/edm/edm-test.env:ro \
  -v "$state_dir":/run/edm/state \
  wp-cli wp eval-file /project-scripts/edm-sandbox-invoice.php "$@"
