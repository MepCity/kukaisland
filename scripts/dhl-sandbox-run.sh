#!/bin/sh
# Run the ONE allow-listed WRITE tool against the DHL sandbox.
#
# This wrapper is separate from scripts/dhl-test-run.sh on purpose. That one
# mounts the credentials for a read-only script and can be run whenever a
# connection needs checking. This one can create a shipment at a courier, and
# creating a shipment costs money and produces a parcel somebody has to cancel.
#
# So it refuses unless BOTH of these are supplied on the command line:
#   1. the exact confirmation phrase, typed in full;
#   2. the WooCommerce order id the shipment is for.
#
# Neither is defaulted, neither is remembered between runs, and a run without
# them makes no network call of any kind.
#
# Usage:
#   ./scripts/dhl-sandbox-run.sh --order=123 --confirm=TEK-SANDBOX-GONDERISI-ONAYLIYORUM
set -eu

ALLOWED_SCRIPT='dhl-sandbox-shipment.php'
CONFIRM_PHRASE='TEK-SANDBOX-GONDERISI-ONAYLIYORUM'

order_id=''
confirm=''

for arg in "$@"; do
  case "$arg" in
    --order=*)   order_id="${arg#--order=}" ;;
    --confirm=*) confirm="${arg#--confirm=}" ;;
    *)
      echo "DHL_SANDBOX_RUN=BLOCKED|reason:unexpected_argument" >&2
      exit 64
      ;;
  esac
done

if [ "$confirm" != "$CONFIRM_PHRASE" ]; then
  echo "DHL_SANDBOX_RUN=BLOCKED|reason:confirmation_phrase_missing_or_wrong|external_calls:0" >&2
  echo "Bu araç taşıyıcıda GERÇEK bir sandbox gönderisi oluşturur." >&2
  echo "Onaylamak için:  ./scripts/dhl-sandbox-run.sh --order=<id> --confirm=$CONFIRM_PHRASE" >&2
  exit 1
fi

case "$order_id" in
  ''|*[!0-9]*)
    echo "DHL_SANDBOX_RUN=BLOCKED|reason:order_id_missing_or_not_numeric|external_calls:0" >&2
    exit 1
    ;;
esac

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

if [ ! -f "scripts/$ALLOWED_SCRIPT" ]; then
  echo "DHL_SANDBOX_RUN=BLOCKED|reason:allow_listed_script_missing_on_disk" >&2
  exit 1
fi

cred_dir="${XDG_CONFIG_HOME:-$HOME/.config}/kuka-island"
cred_file="$cred_dir/dhl-sandbox.env"

case "$cred_file" in
  "$project_dir"/*)
    echo "DHL_SANDBOX_RUN=BLOCKED|reason:credential_path_inside_repository" >&2
    exit 1
    ;;
esac

if [ ! -f "$cred_file" ]; then
  echo "DHL_SANDBOX_RUN=BLOCKED|reason:credentials_file_absent|external_calls:0"
  exit 0
fi

mode=$(stat -f '%Lp' "$cred_file" 2>/dev/null || stat -c '%a' "$cred_file" 2>/dev/null || echo "unknown")
if [ "$mode" != "600" ]; then
  echo "DHL_SANDBOX_RUN=BLOCKED|reason:credentials_file_mode_not_600|mode:$mode" >&2
  exit 1
fi

echo "DHL_SANDBOX_RUN=STARTING|script:$ALLOWED_SCRIPT|order:$order_id|confirmed:yes|credentials:mounted_read_only"

exec docker compose run --rm -T \
  -v "$cred_file":/run/dhl/dhl-sandbox.env:ro \
  wp-cli wp eval-file "/project-scripts/$ALLOWED_SCRIPT" "$order_id" "$CONFIRM_PHRASE"
