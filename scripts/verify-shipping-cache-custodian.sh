#!/bin/sh
# Measure the cache custodian in its own processes: normal, exit and fatal.
#
# For each of the three ways a run can end, the sequence is
#
#   seed    in process 1: plant a row that stands in for the SHOP's own cached
#           city list, and record the fingerprint of everything that is not the
#           run's
#   run     in process 2: take a key namespace of its own, DECLARE the exact
#           rows it will create, register the shutdown guard, create them, and
#           also create a row it did NOT declare -- another process's business --
#           then either release cleanly or leave through an exit or a fatal
#   check   in process 3: the shop's row is byte-identical, the undeclared row is
#           still there, and none of the run's own rows remain
#
# The undeclared row is the case ownership-by-subtraction got wrong: it was not
# in the snapshot, so the previous custodian deleted it as a suspected leftover.
# It belongs to whoever created it.
#
# No wildcard delete is used anywhere, here or in the PHP it drives. No carrier
# is contacted and no credential is read.
#
# Run with:  ./scripts/verify-shipping-cache-custodian.sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

failures=0
note() { printf '%s\n' "$1"; }
fail() { printf '%s\n' "$1" >&2; failures=$((failures + 1)); }

custodian() {
  docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-shipping-cache-custodian.php "$@" 2>&1 || true
}

field() {
  printf '%s\n' "$1" | tr '|' '\n' | sed -n "s/^$2://p" | head -n 1
}

measure_ending() {
  ending=$1
  namespace="testrun-$(date +%s)-$ending"

  seed_line=$(custodian seed | grep -E '^CUSTODIAN_SEED=' | tail -n 1)
  seeded=$(field "$seed_line" 'foreign_fingerprint')

  if [ -z "$seeded" ]; then
    fail "SHIPPING_CACHE_CUSTODIAN_${ending}=FAIL|reason:seed_produced_no_fingerprint"
    return 0
  fi

  run_out=$(custodian "$ending" "$namespace")
  declared=$(field "$(printf '%s\n' "$run_out" | grep -E '^CUSTODIAN_RUN=starting' | tail -n 1)" 'declared')
  dirtied=$(printf '%s\n' "$run_out" | grep -cE '^CUSTODIAN_RUN=dirtied' || true)

  released='n/a'
  if [ "$ending" = 'normal' ]; then
    released=$(field "$(printf '%s\n' "$run_out" | grep -E '^CUSTODIAN_RUN=released' | tail -n 1)" 'ok')
  fi

  check_line=$(custodian check "$namespace" "$seeded" | grep -E '^CUSTODIAN_CHECK=' | tail -n 1)
  verdict=$(printf '%s\n' "$check_line" | sed -n 's/^CUSTODIAN_CHECK=\([A-Z]*\).*/\1/p')
  shop_fp=$(field "$check_line" 'shop_rows_fingerprint_match')
  shop_val=$(field "$check_line" 'shop_value_intact')
  undeclared=$(field "$check_line" 'undeclared_row_preserved')
  leftover=$(field "$check_line" 'run_rows_left')

  # Whatever this measurement planted, this measurement removes.
  cleanup_line=$(custodian cleanup | grep -E '^CUSTODIAN_CLEANUP=' | tail -n 1)
  rows_left=$(field "$cleanup_line" 'rows_left')

  if [ "$verdict" = 'PASS' ] \
    && [ "$dirtied" = '1' ] \
    && [ "$declared" = '6' ] \
    && [ "$shop_fp" = 'yes' ] \
    && [ "$shop_val" = 'yes' ] \
    && [ "$undeclared" = 'yes' ] \
    && [ "$leftover" = '0' ] \
    && [ "$rows_left" = '0' ] \
    && { [ "$ending" != 'normal' ] || [ "$released" = 'yes' ]; }; then
    note "SHIPPING_CACHE_CUSTODIAN_${ending}=PASS|measured:separate_process|ending:${ending}|isolation:own_namespace|declared_exact_names:${declared}|run_rows_created:yes|released_cleanly:${released}|shop_rows_fingerprint_match:${shop_fp}|shop_value_intact:${shop_val}|undeclared_midrun_row_preserved:${undeclared}|run_rows_left:${leftover}|sentinels_removed:yes|wildcard_delete:none"
  else
    fail "SHIPPING_CACHE_CUSTODIAN_${ending}=FAIL|ending:${ending}|verdict:${verdict:-none}|dirtied:${dirtied}|declared:${declared:-none}|released:${released}|shop_rows_fingerprint_match:${shop_fp:-none}|shop_value_intact:${shop_val:-none}|undeclared_midrun_row_preserved:${undeclared:-none}|run_rows_left:${leftover:-unknown}|rows_left_after_cleanup:${rows_left:-unknown}"
  fi
}

measure_ending 'normal'
measure_ending 'exit'
measure_ending 'fatal'

if [ "$failures" -ne 0 ]; then
  printf 'SHIPPING_CACHE_CUSTODIAN_SUITE=FAIL|failures:%s\n' "$failures" >&2
  exit 1
fi

printf 'SHIPPING_CACHE_CUSTODIAN_SUITE=PASS\n'
