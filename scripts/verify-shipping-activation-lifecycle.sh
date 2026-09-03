#!/bin/sh
# The real activation lifecycle, driven through WP-CLI.
#
# The offline suite requires the module's files directly. That proves the code
# works from its own path, and proves nothing at all about the plugin: not the
# bootstrap, not the dependency check, not activation, not deactivation. Those
# only happen when WordPress itself loads the plugin. So this script activates
# and deactivates it for real and measures each state in a FRESH WordPress
# process -- a hook registered in the same process that just activated the
# plugin would prove nothing about the next request.
#
# Ownership: the starting state is snapshotted and restored, including the
# difference between an option that is ABSENT and one that holds a value.
# Restoration runs on failure too.
#
# No carrier call is possible from here. Activation itself must make none, which
# is one of the things measured; nothing in this script creates an order,
# requests a token or touches the credential file.
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

SLUG='kuka-island-shipping-automation'
GATE_OPTION='kuka_island_shipping_runtime_disabled'
POLL_HOOK='kuka_island_shipping_query_status'

wpx() {
  docker compose run --rm -T wp-cli wp "$@" 2>/dev/null
}

failures=0
note() {
  echo "$1"
}
fail() {
  failures=$((failures + 1))
  echo "$1" >&2
}

# --------------------------------------------------------------------------
# Snapshot
# --------------------------------------------------------------------------

start_status=$(wpx plugin list --field=status --name="$SLUG" | tr -d '\r\n' || true)
[ -n "$start_status" ] || start_status='not-installed'

start_active_plugins=$(wpx option get active_plugins --format=json | tr -d '\r\n' || true)

if wpx option get "$GATE_OPTION" >/dev/null 2>&1; then
  start_gate_present='yes'
  start_gate_value=$(wpx option get "$GATE_OPTION" | tr -d '\r\n' || true)
else
  start_gate_present='no'
  start_gate_value=''
fi

# Baselines that deactivation must NOT disturb.
start_shipping_meta=$(wpx eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE \"_kuka_shipping%\"" );' | tr -d '\r\n' || echo 'x')
start_actions=$(wpx eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = \"kuka_island_shipping_query_status\"" );' | tr -d '\r\n' || echo 'x')

restore() {
  case "$start_status" in
    active) wpx plugin activate "$SLUG" >/dev/null 2>&1 || true ;;
    *)      wpx plugin deactivate "$SLUG" >/dev/null 2>&1 || true ;;
  esac

  if [ "$start_gate_present" = 'yes' ]; then
    wpx option update "$GATE_OPTION" "$start_gate_value" >/dev/null 2>&1 || true
  else
    wpx option delete "$GATE_OPTION" >/dev/null 2>&1 || true
  fi
}
trap restore EXIT HUP INT TERM

# --------------------------------------------------------------------------
# 0. Starting conditions
# --------------------------------------------------------------------------

core_status=$(wpx plugin list --field=status --name=kuka-island-core | tr -d '\r\n' || true)
wc_status=$(wpx plugin list --field=status --name=woocommerce | tr -d '\r\n' || true)

if [ "$core_status" = 'active' ] && [ "$wc_status" = 'active' ] && [ "$start_status" = 'inactive' ] && [ "$start_gate_present" = 'no' ]; then
  note "SHIPPING_LIFECYCLE_START=PASS|measured:wp_cli|plugin:$start_status|core:$core_status|woocommerce:$wc_status|gate_option:absent|shipping_meta_rows:$start_shipping_meta|poll_actions:$start_actions"
else
  fail "SHIPPING_LIFECYCLE_START=FAIL|plugin:$start_status|core:$core_status|woocommerce:$wc_status|gate_option:$start_gate_present|shipping_meta_rows:$start_shipping_meta"
fi

# --------------------------------------------------------------------------
# 1. Activation, measured in a fresh process
# --------------------------------------------------------------------------

if ! wpx plugin activate "$SLUG" >/dev/null 2>&1; then
  fail 'SHIPPING_LIFECYCLE_ACTIVATION=FAIL|reason:activate_command_failed'
else
  active_line=$(wpx eval '
$slug   = "kuka-island-shipping-automation/kuka-island-shipping-automation.php";
$active = in_array( $slug, (array) get_option( "active_plugins", array() ), true );

$root    = class_exists( "Kuka_Island_Shipping_Plugin", false );
$booted  = $root && Kuka_Island_Shipping_Plugin::instance()->is_booted();
$missing = $root ? Kuka_Island_Shipping_Plugin::missing_dependencies() : array( "composition_root_absent" );

$classes = array( "Kuka_Island_Shipping_Automation", "Kuka_Island_Shipping_Manager", "Kuka_Island_Shipping_DHL_Client", "Kuka_Island_Shipping_DHL_Provider" );
$absent  = array();
foreach ( $classes as $c ) { if ( ! class_exists( $c, false ) ) { $absent[] = $c; } }

$hooks = array(
  "poller_worker"     => has_action( "kuka_island_shipping_query_status" ),
  "admin_metabox"     => has_action( "add_meta_boxes" ),
  "admin_create"      => has_action( "admin_post_kuka_shipping_create" ),
  "admin_requery"     => has_action( "admin_post_kuka_shipping_requery" ),
  "admin_reconcile"   => has_action( "admin_post_kuka_shipping_reconcile" ),
  "carrier_registry"  => has_filter( "kuka_island_shipping_carriers" ),
);
$unregistered = array();
foreach ( $hooks as $name => $present ) { if ( ! $present ) { $unregistered[] = $name; } }

// Activation must not have opened an order-status route to a carrier.
$forbidden = array();
foreach ( array( "woocommerce_order_status_processing", "woocommerce_order_status_completed", "woocommerce_payment_complete" ) as $hook ) {
  if ( ! isset( $GLOBALS["wp_filter"][ $hook ] ) ) { continue; }
  foreach ( $GLOBALS["wp_filter"][ $hook ]->callbacks as $callbacks ) {
    foreach ( $callbacks as $callback ) {
      $fn    = $callback["function"] ?? null;
      $owner = is_array( $fn ) && isset( $fn[0] ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) : ( is_string( $fn ) ? $fn : "" );
      if ( str_starts_with( $owner, "Kuka_Island_Shipping" ) ) { $forbidden[] = $hook; }
    }
  }
}

$gate_open  = ! Kuka_Island_Shipping_Runtime_Gate::is_disabled();
$automation = Kuka_Island_Shipping_Status_Poller::automation_enabled();

global $wpdb;
$actions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = \"kuka_island_shipping_query_status\"" );

printf(
  "%s|active:%s|composition_root:%s|booted:%s|missing_deps:%s|classes_absent:%s|hooks_unregistered:%s|order_status_routes:%s|runtime_gate_open:%s|automation:%s|poll_actions:%d",
  ( $active && $root && $booted && array() === $missing && array() === $absent && array() === $unregistered && array() === $forbidden && $gate_open && ! $automation && 0 === $actions ) ? "PASS" : "FAIL",
  $active ? "yes" : "no",
  $root ? "loaded" : "ABSENT",
  $booted ? "yes" : "no",
  array() === $missing ? "none" : implode( "+", $missing ),
  array() === $absent ? "none" : implode( "+", $absent ),
  array() === $unregistered ? "none" : implode( "+", $unregistered ),
  array() === $forbidden ? "none" : implode( "+", array_unique( $forbidden ) ),
  $gate_open ? "yes" : "no",
  $automation ? "ON" : "off",
  $actions
);
' | tr -d '\r')

  case "$active_line" in
    PASS*) note "SHIPPING_LIFECYCLE_ACTIVATION=$active_line" ;;
    *)     fail "SHIPPING_LIFECYCLE_ACTIVATION=$active_line" ;;
  esac
fi

# --------------------------------------------------------------------------
# 2. Deactivation, measured in a fresh process
# --------------------------------------------------------------------------

if ! wpx plugin deactivate "$SLUG" >/dev/null 2>&1; then
  fail 'SHIPPING_LIFECYCLE_DEACTIVATION=FAIL|reason:deactivate_command_failed'
else
  inactive_line=$(wpx eval '
$classes = array( "Kuka_Island_Shipping_Plugin", "Kuka_Island_Shipping_Automation", "Kuka_Island_Shipping_Manager", "Kuka_Island_Shipping_DHL_Client" );
$declared = array();
foreach ( $classes as $c ) { if ( class_exists( $c, false ) ) { $declared[] = $c; } }

$hooks = array( "kuka_island_shipping_query_status", "admin_post_kuka_shipping_create", "admin_post_kuka_shipping_requery" );
$registered = array();
foreach ( $hooks as $h ) { if ( has_action( $h ) ) { $registered[] = $h; } }

global $wpdb;
$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = \"kuka_island_shipping_query_status\" AND status = \"pending\"" );
$meta    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE \"_kuka_shipping%\"" );

// The gate must be CLOSED after deactivation: a worker already inside a call
// is stopped by the option, not by the missing hook.
$gate_closed = "1" === (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", "kuka_island_shipping_runtime_disabled" ) );

// Core must still work.
$core_ok = class_exists( "Kuka_Island_Core_Plugin", false ) && class_exists( "Kuka_Island_Core_Fulfillments", false );

printf(
  "%s|classes_declared:%s|hooks_registered:%s|pending_poll_actions:%d|shipping_meta_preserved:%d|runtime_gate_closed:%s|core_works:%s",
  ( array() === $declared && array() === $registered && 0 === $pending && $gate_closed && $core_ok ) ? "PASS" : "FAIL",
  array() === $declared ? "none" : implode( "+", $declared ),
  array() === $registered ? "none" : implode( "+", $registered ),
  $pending,
  $meta,
  $gate_closed ? "yes" : "NO",
  $core_ok ? "yes" : "NO"
);
' | tr -d '\r')

  case "$inactive_line" in
    PASS*) note "SHIPPING_LIFECYCLE_DEACTIVATION=$inactive_line" ;;
    *)     fail "SHIPPING_LIFECYCLE_DEACTIVATION=$inactive_line" ;;
  esac
fi

# --------------------------------------------------------------------------
# 3. Restoration
# --------------------------------------------------------------------------

restore
trap - EXIT HUP INT TERM

end_status=$(wpx plugin list --field=status --name="$SLUG" | tr -d '\r\n' || true)
end_active_plugins=$(wpx option get active_plugins --format=json | tr -d '\r\n' || true)

if wpx option get "$GATE_OPTION" >/dev/null 2>&1; then
  end_gate_present='yes'
else
  end_gate_present='no'
fi

end_shipping_meta=$(wpx eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE \"_kuka_shipping%\"" );' | tr -d '\r\n' || echo 'x')

if [ "$end_status" = "$start_status" ] \
  && [ "$end_gate_present" = "$start_gate_present" ] \
  && [ "$end_active_plugins" = "$start_active_plugins" ] \
  && [ "$end_shipping_meta" = "$start_shipping_meta" ]; then
  note "SHIPPING_LIFECYCLE_RESTORED=PASS|plugin:$end_status|gate_option:$end_gate_present|active_plugins_identical:yes|shipping_meta_rows:$end_shipping_meta"
else
  fail "SHIPPING_LIFECYCLE_RESTORED=FAIL|plugin:$end_status|gate_option:$end_gate_present|active_plugins_identical:$( [ "$end_active_plugins" = "$start_active_plugins" ] && echo yes || echo no )|shipping_meta_rows:$end_shipping_meta"
fi

if [ "$failures" -ne 0 ]; then
  echo "SHIPPING_LIFECYCLE=FAIL ($failures)" >&2
  exit 1
fi

echo 'SHIPPING_LIFECYCLE=PASS'
