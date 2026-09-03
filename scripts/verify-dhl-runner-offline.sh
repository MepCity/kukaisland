#!/bin/sh
# Prove that the DHL runner's offline allow-list mode starts NOTHING.
#
# What is measured, and why each part is necessary:
#
#   NO PROCESS. The mode runs with a PATH whose first entry holds shims named
#   docker, docker-compose, php, wp and curl. Each shim appends its own name to
#   a marker file and exits 0. If the wrapper launched any of them the marker
#   file would exist, so an empty marker file is the measurement -- not a
#   reading of the wrapper's source.
#
#   NO CREDENTIAL READ. Two fixtures, and the second is the real proof.
#     Fixture A places a credential file with mode 600 and a sentinel value, so
#     the enforced path WOULD have proceeded. The whole output is then scanned
#     for the sentinel.
#     Fixture B makes the credential directory unsearchable (mode 000), so any
#     attempt to test, stat or read the file inside it fails. The mode must
#     still answer identically. A wrapper that touched the path would change
#     its answer or emit an error.
#
#   NO NETWORK. There is no allow-listed egress in this mode at all: with curl
#   shimmed and no process started, the only way out would be a shell builtin
#   redirect, and /dev/tcp does not exist in POSIX sh. Reported as a
#   consequence of the process count, not as an independent claim.
#
#   THE REAL CREDENTIAL FILE IS NEVER INVOLVED. XDG_CONFIG_HOME points at a
#   throwaway directory for every case below, so the operator's own file at
#   ~/.config/kuka-island/dhl-sandbox.env is not read, not stat-ed and not
#   mounted by anything here.
#
# Run with:  ./scripts/verify-dhl-runner-offline.sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

failures=0
note() { printf '%s\n' "$1"; }
fail() { printf '%s\n' "$1" >&2; failures=$((failures + 1)); }

work=$(mktemp -d 2>/dev/null || mktemp -d -t kuka-dhl-offline)
cleanup() {
  chmod 700 "$work/blindfold/kuka-island" 2>/dev/null || true
  rm -rf "$work"
}
trap cleanup EXIT INT TERM

# --- Shims: anything the wrapper might launch --------------------------------
shim_dir="$work/bin"
marker="$work/launched.txt"
mkdir -p "$shim_dir"
: > "$marker"

for tool in docker docker-compose php php8.3 wp curl wget nc; do
  cat > "$shim_dir/$tool" <<SHIM
#!/bin/sh
printf '%s\n' "$tool" >> "$marker"
exit 0
SHIM
  chmod 755 "$shim_dir/$tool"
done

# --- Fixture A: credentials present, mode 600, sentinel value ---------------
# A deliberately fake value. Nothing here reads or copies the operator's file.
sentinel='OFFLINE-FIXTURE-SENTINEL-NOT-A-CREDENTIAL'
mkdir -p "$work/present/kuka-island"
printf 'KUKA_DHL_CLIENT_ID=%s\nKUKA_DHL_CLIENT_SECRET=%s\nKUKA_DHL_CUSTOMER_NUMBER=%s\nKUKA_DHL_PASSWORD=%s\n' \
  "$sentinel" "$sentinel" "$sentinel" "$sentinel" > "$work/present/kuka-island/dhl-sandbox.env"
chmod 600 "$work/present/kuka-island/dhl-sandbox.env"

# --- Fixture B: the credential directory cannot even be searched ------------
mkdir -p "$work/blindfold/kuka-island"
printf 'unreachable\n' > "$work/blindfold/kuka-island/dhl-sandbox.env"
chmod 600 "$work/blindfold/kuka-island/dhl-sandbox.env"
chmod 000 "$work/blindfold/kuka-island"

run_offline() {
  # $1 = XDG_CONFIG_HOME fixture, $2 = candidate script name
  PATH="$shim_dir:$PATH" XDG_CONFIG_HOME="$1" ./scripts/dhl-test-run.sh --check-script="$2" 2>&1 || true
}

# --- 0. THE CONTROL: the shims would fire if anything launched them --------
#
# "No marker file" only means something if a marker file is reachable. So the
# shims are invoked deliberately, through the same PATH the measurements use,
# and the marker must appear. Without this, a PATH that was never consulted
# would produce the same silent pass as a wrapper that launches nothing.
: > "$marker"
PATH="$shim_dir:$PATH" docker --version >/dev/null 2>&1 || true
PATH="$shim_dir:$PATH" php --version >/dev/null 2>&1 || true
PATH="$shim_dir:$PATH" curl --version >/dev/null 2>&1 || true
control_hits=$(wc -l < "$marker" | tr -d ' ')

if [ "$control_hits" = '3' ]; then
  note "DHL_RUNNER_SHIM_CONTROL=PASS|shims_invoked_on_purpose:3|marker_lines:$control_hits|detection_works:yes"
else
  fail "DHL_RUNNER_SHIM_CONTROL=FAIL|expected:3|marker_lines:$control_hits|detection_works:no"
fi

: > "$marker"

expected_tail='credentials_read:no|docker_started:no|php_started:no|network_calls:0'

# --- 1. The allow-listed name, with credentials fully in place -------------
present_line=$(run_offline "$work/present" 'test-dhl-sandbox.php')

present_ok=no
case "$present_line" in
  "DHL_TEST_RUN=CHECK|mode:offline_allowlist_check|script:test-dhl-sandbox.php|allow_listed:yes|reason:allow_listed|$expected_tail") present_ok=yes ;;
esac

sentinel_leaked=no
case "$present_line" in
  *"$sentinel"*) sentinel_leaked=yes ;;
esac

# --- 2. The same question with the credential directory unsearchable ------
blindfold_line=$(run_offline "$work/blindfold" 'test-dhl-sandbox.php')

blindfold_identical=no
if [ "$blindfold_line" = "$present_line" ]; then
  blindfold_identical=yes
fi

# --- 3. Every refusal, still offline ---------------------------------------
refusals=0
refusal_expected=0
for candidate in 'dhl-sandbox-shipment.php' '../scripts/test-dhl-sandbox.php' '/etc/passwd' 'sub/test-dhl-sandbox.php' 'test-dhl-sandbox.php;id' 'verify.php' 'TEST-DHL-SANDBOX.PHP' ''; do
  refusal_expected=$((refusal_expected + 1))
  refusal_line=$(run_offline "$work/present" "$candidate")

  case "$refusal_line" in
    "DHL_TEST_RUN=CHECK|mode:offline_allowlist_check|"*"|allow_listed:no|reason:"*"|$expected_tail") refusals=$((refusals + 1)) ;;
  esac
done

# --- 4. Nothing was launched, in any of the cases above -------------------
launched=$(wc -l < "$marker" | tr -d ' ')
launched_names=$(sort -u "$marker" | tr '\n' '+' | sed 's/+$//')

if [ "$present_ok" = 'yes' ] \
  && [ "$sentinel_leaked" = 'no' ] \
  && [ "$blindfold_identical" = 'yes' ] \
  && [ "$refusals" = "$refusal_expected" ] \
  && [ "$launched" = '0' ]; then
  note "DHL_RUNNER_OFFLINE=PASS|mode:offline_allowlist_check|allowlisted_answered:$present_ok|refusals:$refusals/$refusal_expected|credentials_4of4_fixture:yes|credential_value_in_output:$sentinel_leaked|answer_identical_with_unreadable_credential_dir:$blindfold_identical|processes_launched:$launched|shimmed:docker+docker-compose+php+wp+curl+wget+nc|network_calls:0"
else
  fail "DHL_RUNNER_OFFLINE=FAIL|allowlisted_answered:$present_ok|refusals:$refusals/$refusal_expected|credential_value_in_output:$sentinel_leaked|answer_identical_with_unreadable_credential_dir:$blindfold_identical|processes_launched:$launched|launched_names:${launched_names:-none}"
fi

# --- 5. The enforced mode still refuses the same names, and still offline --
enforced_refusals=0
enforced_expected=0
: > "$marker"
for candidate in 'dhl-sandbox-shipment.php' '/etc/passwd' 'sub/test-dhl-sandbox.php' 'test-dhl-sandbox.php;id' 'verify.php'; do
  enforced_expected=$((enforced_expected + 1))
  enforced_line=$(PATH="$shim_dir:$PATH" XDG_CONFIG_HOME="$work/present" ./scripts/dhl-test-run.sh "$candidate" 2>&1 | head -n 1 || true)

  case "$enforced_line" in
    DHL_TEST_RUN=BLOCKED*) enforced_refusals=$((enforced_refusals + 1)) ;;
  esac
done
enforced_launched=$(wc -l < "$marker" | tr -d ' ')

if [ "$enforced_refusals" = "$enforced_expected" ] && [ "$enforced_launched" = '0' ]; then
  note "DHL_RUNNER_ENFORCED_REFUSALS=PASS|refused:$enforced_refusals/$enforced_expected|processes_launched:$enforced_launched|operator_command_unchanged:yes"
else
  fail "DHL_RUNNER_ENFORCED_REFUSALS=FAIL|refused:$enforced_refusals/$enforced_expected|processes_launched:$enforced_launched"
fi

if [ "$failures" -ne 0 ]; then
  printf 'DHL_RUNNER_OFFLINE_SUITE=FAIL|failures:%s\n' "$failures" >&2
  exit 1
fi

printf 'DHL_RUNNER_OFFLINE_SUITE=PASS\n'
