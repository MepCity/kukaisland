#!/bin/sh
# Behavioural proof that the reconciliation reset is offline, measured through
# the REAL user surface.
#
# The previous attempt at this lived inside the wp-cli container and rebuilt the
# reset out of its own closure, so it proved nothing about the shipped driver.
# Everything here runs the actual ./scripts/edm-sandbox-run.sh wrapper, which in
# turn runs the actual scripts/edm-sandbox-invoice.php, against a throwaway
# XDG_CONFIG_HOME.
#
# Test A  KUKA_EDM_ALLOW_SANDBOX_WRITE=true together with reset= must be refused
#         on the host, before docker is started at all.
# Test B  A real reset must move uncertain -> idle without the container ever
#         receiving a credential mount.
#
# Docker invocations are captured by putting a recording shim first on PATH, so
# "docker was not started" and "no credential was mounted" are read off the real
# argument vector rather than asserted.
#
# No EDM call, no LoadInvoice, no SendInvoice. The developer's own sandbox claim
# file is never opened: only the temporary XDG tree is used.
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

real_docker=$(command -v docker || true)
if [ -z "$real_docker" ]; then
  echo "SANDBOX_RESET_HOST_WRITE_GATE=SKIPPED|reason:docker_not_on_path"
  echo "SANDBOX_RESET_REAL_WRAPPER_DRIVER=SKIPPED|reason:docker_not_on_path"
  exit 0
fi

# The real claim, which this test must leave completely alone.
real_claim="${XDG_CONFIG_HOME:-$HOME/.config}/kuka-island/edm-sandbox-state/sandbox-e2e.json"
real_claim_before="absent"
if [ -f "$real_claim" ]; then
  real_claim_before=$(shasum -a 256 "$real_claim" | cut -d' ' -f1)
fi

tmp_root=$(mktemp -d "${TMPDIR:-/tmp}/kuka-reset-offline.XXXXXX")
# Ownership marker: cleanup refuses to remove a directory it did not create.
marker="$tmp_root/.kuka-reset-offline-fixture"
: > "$marker"

cleanup() {
  if [ -n "${tmp_root:-}" ] && [ -f "$tmp_root/.kuka-reset-offline-fixture" ]; then
    rm -rf "$tmp_root"
  fi
}
trap cleanup EXIT INT TERM

xdg="$tmp_root/xdg"
state_dir="$xdg/kuka-island/edm-sandbox-state"
cred_file="$xdg/kuka-island/edm-test.env"
claim_file="$state_dir/sandbox-e2e.json"
mkdir -p "$state_dir"

# Linux bind mounts keep numeric ownership. The host-side state directory and
# mode-600 claim therefore have to be used by the invoking host UID, just as the
# real wrapper does. Docker Desktop masks this distinction on macOS; a native
# Linux runner does not. Keep the whole temporary tree private to that owner.
chmod 0700 "$tmp_root" "$xdg" "$xdg/kuka-island" "$state_dir"
container_user="$(id -u):$(id -g)"

read_fixture_claim() {
  "$real_docker" compose run --rm -T \
    --user "$container_user" \
    -v "$state_dir":/run/edm/state:ro \
    wp-cli php -r '
$path = "/run/edm/state/sandbox-e2e.json";
if ( ! is_readable( $path ) ) {
    exit( 1 );
}
echo file_get_contents( $path );
'
}

# --------------------------------------------------------------------------
# Seed a genuine 'uncertain' record through the state machine.
# The JSON is never hand-written: an invented record would not prove the
# transition the driver actually performs.
# --------------------------------------------------------------------------
set +e
seed_output=$(
  "$real_docker" compose run --rm -T \
    --user "$container_user" \
    -v "$state_dir":/run/edm/state \
    wp-cli wp --skip-plugins=iyzico-woocommerce eval '
require_once "/project-scripts/lib-edm-sandbox.php";
$claim = new Kuka_Sandbox_Claim( "/run/edm/state/sandbox-e2e.json" );
$claim->acquire();
$claim->claim( "uuid-reset-offline-fixture", "LoadInvoice" );
$claim->settle( Kuka_Sandbox_Claim::S_UNCERTAIN, array( "outcome" => "transport_exception" ) );
$claim->release();
' 2>&1
)
seed_exit=$?
set -e

if [ "$seed_exit" != "0" ]; then
  echo "SANDBOX_RESET_HOST_WRITE_GATE=FAIL|reason:fixture_claim_seed_failed"
  echo "SANDBOX_RESET_REAL_WRAPPER_DRIVER=FAIL|reason:fixture_claim_seed_failed"
  exit 1
fi

if [ ! -f "$claim_file" ]; then
  echo "SANDBOX_RESET_HOST_WRITE_GATE=FAIL|reason:fixture_claim_not_created"
  echo "SANDBOX_RESET_REAL_WRAPPER_DRIVER=FAIL|reason:fixture_claim_not_created"
  exit 1
fi

seeded_json=$(read_fixture_claim)
seeded_state=$(printf '%s' "$seeded_json" | python3 -c 'import json,sys;print(json.load(sys.stdin)["state"])')
seeded_uuid=$(printf '%s' "$seeded_json" | python3 -c 'import json,sys;print(json.load(sys.stdin)["uuid"])')
seeded_hash=$(printf '%s' "$seeded_json" | shasum -a 256 | cut -d' ' -f1)
printf '%s' "$seeded_json" | python3 -c 'import json,sys;json.dump(json.load(sys.stdin)["history"],open(sys.argv[1],"w"))' "$tmp_root/history-before.json"

# --------------------------------------------------------------------------
# Test A -- host write gate. A shim that ONLY records: if the wrapper starts
# docker here, the refusal came too late.
# --------------------------------------------------------------------------
mkdir -p "$tmp_root/bin-blocking"
cat > "$tmp_root/bin-blocking/docker" <<EOF
#!/bin/sh
printf '%s\n' "\$*" >> "$tmp_root/docker-a.log"
exit 0
EOF
chmod +x "$tmp_root/bin-blocking/docker"
: > "$tmp_root/docker-a.log"

set +e
gate_output=$(
  PATH="$tmp_root/bin-blocking:$PATH" \
  XDG_CONFIG_HOME="$xdg" \
  KUKA_EDM_ALLOW_SANDBOX_WRITE=true \
  ./scripts/edm-sandbox-run.sh reset=document_absent_at_edm audit=edm_portal_absent 2>&1
)
gate_exit=$?
set -e

gate_docker_calls=$(wc -l < "$tmp_root/docker-a.log" | tr -d ' ')
gate_json=$(read_fixture_claim)
gate_hash=$(printf '%s' "$gate_json" | shasum -a 256 | cut -d' ' -f1)
gate_line="EDM_SANDBOX_RUN=BLOCKED|reason:write_gate_open_during_reset|credentials_mounted:no|docker_started:no|state_unchanged:yes"

gate_ok=yes
[ "$gate_exit" = "1" ] || gate_ok=no
printf '%s\n' "$gate_output" | grep -Fqx "$gate_line" || gate_ok=no
[ "$gate_docker_calls" = "0" ] || gate_ok=no
[ "$gate_hash" = "$seeded_hash" ] || gate_ok=no
[ -f "$cred_file" ] && gate_ok=no

printf 'SANDBOX_RESET_HOST_WRITE_GATE=%s|exit:%s|reason:%s|docker_started:%s|credentials_mounted:%s|state_unchanged:%s\n' \
  "$( [ "$gate_ok" = "yes" ] && echo PASS || echo FAIL )" \
  "$gate_exit" \
  "$( printf '%s\n' "$gate_output" | grep -Fqx "$gate_line" && echo write_gate_open_during_reset || echo UNEXPECTED )" \
  "$( [ "$gate_docker_calls" = "0" ] && echo no || echo YES )" \
  "$( [ -f "$cred_file" ] && echo YES || echo no )" \
  "$( [ "$gate_hash" = "$seeded_hash" ] && echo yes || echo NO )"

# --------------------------------------------------------------------------
# Test B -- the real wrapper driving the real driver. The shim records the
# argument vector and then execs the real docker, so the mounts the container
# actually received are what gets inspected.
# --------------------------------------------------------------------------
mkdir -p "$tmp_root/bin-recording"
cat > "$tmp_root/bin-recording/docker" <<EOF
#!/bin/sh
printf '%s\n' "\$*" >> "$tmp_root/docker-b.log"
exec "$real_docker" "\$@"
EOF
chmod +x "$tmp_root/bin-recording/docker"
: > "$tmp_root/docker-b.log"

set +e
reset_output=$(
  PATH="$tmp_root/bin-recording:$PATH" \
  XDG_CONFIG_HOME="$xdg" \
  ./scripts/edm-sandbox-run.sh reset=document_absent_at_edm audit=edm_portal_absent 2>&1
)
reset_exit=$?
set -e

after_json=$(read_fixture_claim)
after_state=$(printf '%s' "$after_json" | python3 -c 'import json,sys;print(json.load(sys.stdin)["state"])')
after_uuid=$(printf '%s' "$after_json" | python3 -c 'import json,sys;print(json.load(sys.stdin)["uuid"])')
after_snapshot="$tmp_root/claim-after.json"
printf '%s' "$after_json" > "$after_snapshot"

# History must be append-only: every earlier entry survives unchanged and
# exactly one entry carrying the evidence and the audit label was added.
history_verdict=$(python3 - "$tmp_root/history-before.json" "$after_snapshot" <<'PY'
import json, sys
before = json.load(open(sys.argv[1]))
after = json.load(open(sys.argv[2]))["history"]
appended = after[-1] if after else {}
ok = (
    len(after) == len(before) + 1
    and after[: len(before)] == before
    and appended.get("to") == "idle"
    and appended.get("evidence") == "document_absent_at_edm"
    and appended.get("audit") == "edm_portal_absent"
)
print("append_only" if ok else "REWRITTEN")
PY
)

real_claim_after="absent"
if [ -f "$real_claim" ]; then
  real_claim_after=$(shasum -a 256 "$real_claim" | cut -d' ' -f1)
fi

# Read off the real argument vector the wrapper handed docker.
cred_mounted=no
grep -q 'edm-test.env' "$tmp_root/docker-b.log" && cred_mounted=YES
write_env_forwarded=no
grep -q 'KUKA_EDM_ALLOW_SANDBOX_WRITE' "$tmp_root/docker-b.log" && write_env_forwarded=YES

reset_ok=yes
[ "$reset_exit" = "0" ] || reset_ok=no
printf '%s\n' "$reset_output" | grep -q 'SANDBOX_CLAIM_RESET=PASS|from:uncertain|to:idle' || reset_ok=no
[ "$seeded_state" = "uncertain" ] || reset_ok=no
[ "$after_state" = "idle" ] || reset_ok=no
[ "$after_uuid" = "$seeded_uuid" ] || reset_ok=no
[ "$history_verdict" = "append_only" ] || reset_ok=no
[ "$cred_mounted" = "no" ] || reset_ok=no
[ "$write_env_forwarded" = "no" ] || reset_ok=no
[ -f "$cred_file" ] && reset_ok=no
[ "$real_claim_before" = "$real_claim_after" ] || reset_ok=no

printf 'SANDBOX_RESET_REAL_WRAPPER_DRIVER=%s|credentials_file:%s|credentials_mounted:%s|from:%s|to:%s|uuid_unchanged:%s|history:%s|real_claim_unchanged:%s\n' \
  "$( [ "$reset_ok" = "yes" ] && echo PASS || echo FAIL )" \
  "$( [ -f "$cred_file" ] && echo PRESENT || echo absent )" \
  "$cred_mounted" \
  "$seeded_state" \
  "$after_state" \
  "$( [ "$after_uuid" = "$seeded_uuid" ] && echo yes || echo NO )" \
  "$history_verdict" \
  "$( [ "$real_claim_before" = "$real_claim_after" ] && echo yes || echo NO )"
