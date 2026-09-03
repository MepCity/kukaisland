#!/bin/sh
# Run the isolated EDM sandbox SendInvoice experiment.
#
# SendInvoice ISSUES a document and EDM delivers it to the address in
# INVOICE/HEADER/TO. This is not the LoadInvoice draft tool, and it never
# touches the document that tool created: it mints its own from its own seed,
# with its own state record.
#
# Default behaviour is PLAN: nothing is transmitted. Two literal gates are
# required, deliberately NOT the LoadInvoice ones:
#   1. KUKA_EDM_ALLOW_SANDBOX_SEND=true   (literal)
#   2. confirm=SendInvoice                (must name the operation)
#
# The confirmation is passed BARE, without leading dashes: `wp eval-file`
# forwards positional arguments only and rejects `--confirm=...` as one of its
# own unknown parameters before the script runs.
#
# Usage:
#   ./scripts/edm-sandbox-send-run.sh                                        # PLAN
#   KUKA_EDM_ALLOW_SANDBOX_SEND=true ./scripts/edm-sandbox-send-run.sh confirm=SendInvoice
#   ./scripts/edm-sandbox-send-run.sh resolve                                # read-only
#   ./scripts/edm-sandbox-send-run.sh status=confirm                          # read-only
#
# `resolve` asks EDM what it holds for an unsettled transmission, using
# GetInvoiceStatus and GetInvoice only. It needs no gate because it issues
# nothing, and it refuses to run while either gate is open.
#
# `status=confirm` asks EDM for the CURRENT status of a document it already
# accepted. Same read-only operations, and the state directory is mounted
# READ-ONLY for it: writing state or history is impossible, not just unintended.
#
# Mounts:
#   credential file  -> /run/edm/edm-test.env      read-only
#   state directory  -> /run/edm/state             read-write (JSON only, no DB)
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

cred_dir="${XDG_CONFIG_HOME:-$HOME/.config}/kuka-island"
cred_file="$cred_dir/edm-test.env"
state_dir="$cred_dir/edm-sandbox-state"

send_allow="${KUKA_EDM_ALLOW_SANDBOX_SEND:-}"
write_allow="${KUKA_EDM_ALLOW_SANDBOX_WRITE:-}"

# The two experiments' gates are separate, and opening both at once is
# ambiguous: one asks for a draft upload, the other for a transmission. Refused
# here, on the host, before any mount and before Docker starts.
if [ "$write_allow" = "true" ]; then
  echo "EDM_SANDBOX_SEND_RUN=BLOCKED|reason:loadinvoice_write_gate_open_during_send|credentials_mounted:no|docker_started:no|documents_sent:0"
  exit 1
fi

case "$cred_file" in
  "$project_dir"/*)
    echo "EDM_SANDBOX_SEND_RUN=BLOCKED|reason:credential_path_inside_repository" >&2
    exit 1
    ;;
esac

umask 077
mkdir -p "$state_dir"
chmod 700 "$state_dir"

if [ ! -f "$cred_file" ]; then
  echo "EDM_SANDBOX_SEND_RUN=BLOCKED|reason:credentials_file_absent"
  echo "Create it first (nothing is echoed):  ./scripts/edm-test-credentials.sh"
  exit 0
fi

mode=$(stat -f '%Lp' "$cred_file" 2>/dev/null || stat -c '%a' "$cred_file" 2>/dev/null || echo "unknown")
if [ "$mode" != "600" ]; then
  echo "EDM_SANDBOX_SEND_RUN=BLOCKED|reason:credentials_file_mode_not_600|mode:$mode" >&2
  exit 1
fi

resolve_requested=no
status_requested=no
for arg in "$@"; do
  [ "$arg" = "resolve" ] && resolve_requested=yes
  [ "$arg" = "status=confirm" ] && status_requested=yes
done

# The state mount is read-write only when a mode might legitimately settle a
# record. The status check never does, so it gets a read-only mount.
state_mount="$state_dir:/run/edm/state"
if [ "$status_requested" = "yes" ]; then
  state_mount="$state_dir:/run/edm/state:ro"
fi

if [ "$status_requested" = "yes" ]; then
  echo "EDM_SANDBOX_SEND_RUN=STATUS|writes:none|state_mount:read_only|operations:Login,GetInvoiceStatus,GetInvoice,Logout|nothing_will_be_transmitted"
elif [ "$resolve_requested" = "yes" ]; then
  echo "EDM_SANDBOX_SEND_RUN=RESOLVE|writes:none|operations:GetInvoiceStatus,GetInvoice|nothing_will_be_transmitted"
elif [ "$send_allow" = "true" ]; then
  echo "EDM_SANDBOX_SEND_RUN=SEND_GATE_OPEN|env_gate:true|operation_confirmation_still_required|effect:document_will_be_issued_and_delivered_by_edm"
else
  echo "EDM_SANDBOX_SEND_RUN=PLAN_ONLY|env_gate:absent_or_not_literal_true|nothing_will_be_transmitted"
fi

# KUKA_EDM_ALLOW_SANDBOX_WRITE is deliberately NOT forwarded: the LoadInvoice
# write path must be unreachable from inside this container even if the code
# changed.
exec docker compose run --rm -T \
  -e KUKA_EDM_ALLOW_SANDBOX_SEND \
  -v "$cred_file":/run/edm/edm-test.env:ro \
  -v "$state_mount" \
  wp-cli wp eval-file /project-scripts/edm-sandbox-send.php "$@"
