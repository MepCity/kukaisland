#!/bin/sh
# Measure the cache custodian's SHUTDOWN path, which needs its own processes.
#
# The normal path -- snapshot, dirty, restore at the bottom of the file -- is
# measured inside verify-shipping-automation.php. This suite measures the path
# that file cannot: a run that never reaches its cleanup.
#
# For each death mode the sequence is
#
#   seed   in process 1: plant a sentinel cache row and record its fingerprint
#   crash  in process 2: snapshot, register the shutdown guard, overwrite the
#          cache the way a real scenario does, then die
#   check  in process 3: the sentinel must be back, byte for byte, and none of
#          the crashed run's own rows may remain
#
# Two death modes, because they leave PHP by different doors: an explicit exit
# (WP_CLI::error) and an uncaught fatal (a call to a function that does not
# exist). Shutdown functions run for both; that is the property being measured
# rather than assumed.
#
# No carrier is contacted and no credential is read.
#
# Run with:  ./scripts/verify-shipping-cache-custodian.sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

failures=0
note() { printf '%s\n' "$1"; }
fail() { printf '%s\n' "$1" >&2; failures=$((failures + 1)); }

custodian() {
  # $@ = positional args for the PHP script
  docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-shipping-cache-custodian.php "$@" 2>&1 || true
}

field() {
  # $1 = whole line, $2 = field name
  printf '%s\n' "$1" | tr '|' '\n' | sed -n "s/^$2://p" | head -n 1
}

measure_death_mode() {
  death=$1

  seed_line=$(custodian seed | grep -E '^CUSTODIAN_SEED=' | tail -n 1)
  seeded_fingerprint=$(field "$seed_line" 'fingerprint')

  if [ -z "$seeded_fingerprint" ]; then
    fail "SHIPPING_CACHE_CUSTODIAN_${death}=FAIL|reason:seed_produced_no_fingerprint"
    return 0
  fi

  crash_out=$(custodian crash "$death")
  dirtied=$(printf '%s\n' "$crash_out" | grep -cE '^CUSTODIAN_CRASH=dirtied' || true)
  snapshot_rows=$(field "$(printf '%s\n' "$crash_out" | grep -E '^CUSTODIAN_CRASH=starting' | tail -n 1)" 'snapshot_rows')

  check_line=$(custodian check "$death" "$seeded_fingerprint" | grep -E '^CUSTODIAN_CHECK=' | tail -n 1)
  verdict=$(printf '%s\n' "$check_line" | sed -n 's/^CUSTODIAN_CHECK=\([A-Z]*\).*/\1/p')
  match=$(field "$check_line" 'fingerprint_match')
  intact=$(field "$check_line" 'sentinel_value_intact')
  leftover=$(field "$check_line" 'run_owned_rows_left')

  if [ "$verdict" = 'PASS' ] && [ "$dirtied" = '1' ] && [ "$match" = 'yes' ] && [ "$intact" = 'yes' ] && [ "$leftover" = '0' ]; then
    note "SHIPPING_CACHE_CUSTODIAN_${death}=PASS|measured:separate_process|death:${death}|snapshot_rows:${snapshot_rows:-0}|cache_dirtied_before_death:yes|restored_by:shutdown_guard|fingerprint_match:${match}|sentinel_value_intact:${intact}|run_owned_rows_left:${leftover}"
  else
    fail "SHIPPING_CACHE_CUSTODIAN_${death}=FAIL|death:${death}|verdict:${verdict:-none}|cache_dirtied_before_death:${dirtied}|fingerprint_match:${match:-none}|sentinel_value_intact:${intact:-none}|run_owned_rows_left:${leftover:-unknown}"
  fi
}

measure_death_mode 'exit'
measure_death_mode 'fatal'

# The sentinel is this suite's own fixture, so this suite removes it.
cleanup_line=$(custodian cleanup | grep -E '^CUSTODIAN_CLEANUP=' | tail -n 1)
rows_left=$(field "$cleanup_line" 'rows_left')

if [ "$rows_left" = '0' ]; then
  note "SHIPPING_CACHE_CUSTODIAN_FIXTURE_REMOVED=PASS|rows_left:${rows_left}"
else
  fail "SHIPPING_CACHE_CUSTODIAN_FIXTURE_REMOVED=FAIL|rows_left:${rows_left:-unknown}"
fi

if [ "$failures" -ne 0 ]; then
  printf 'SHIPPING_CACHE_CUSTODIAN_SUITE=FAIL|failures:%s\n' "$failures" >&2
  exit 1
fi

printf 'SHIPPING_CACHE_CUSTODIAN_SUITE=PASS\n'
