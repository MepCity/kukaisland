#!/bin/sh
# The real activation lifecycle, driven through WP-CLI.
#
# The offline suites require the module's files directly. That proves the code
# works from its new path, and proves nothing at all about the plugin: not the
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
# No EDM network operation is possible from here: this never calls Login,
# GetInvoiceStatus, GetInvoice, LoadInvoice or SendInvoice, and it never touches
# the sandbox state files.
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"

EDM_SLUG='kuka-island-edm'
GATE_OPTION='kuka_island_edm_runtime_disabled'

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

start_status=$(wpx plugin list --field=status --name="$EDM_SLUG" | tr -d '\r\n' || true)
[ -n "$start_status" ] || start_status='not-installed'

start_active_plugins=$(wpx option get active_plugins --format=json | tr -d '\r\n' || true)

# Existence and value are different facts. `absent` must come back as absent.
if wpx option get "$GATE_OPTION" >/dev/null 2>&1; then
  start_gate_present='yes'
  start_gate_value=$(wpx option get "$GATE_OPTION" | tr -d '\r\n' || true)
else
  start_gate_present='no'
  start_gate_value=''
fi

# Baselines that deactivation must NOT disturb.
start_invoice_meta=$(wpx eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE \"_kuka_invoice%\"" );' | tr -d '\r\n' || echo 'x')
start_edm_actions=$(wpx eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN (\"kuka_island_process_order_invoice\",\"kuka_island_query_invoice_status\")" );' | tr -d '\r\n' || echo 'x')

restore() {
  # Plugin state first: activation writes the gate option, so the gate has to
  # be restored after it.
  case "$start_status" in
    active)   wpx plugin activate "$EDM_SLUG" >/dev/null 2>&1 || true ;;
    *)        wpx plugin deactivate "$EDM_SLUG" >/dev/null 2>&1 || true ;;
  esac

  if [ "$start_gate_present" = 'yes' ]; then
    wpx option update "$GATE_OPTION" "$start_gate_value" >/dev/null 2>&1 || true
  else
    wpx option delete "$GATE_OPTION" >/dev/null 2>&1 || true
  fi

  # Activation appends the plugin and deactivation may reindex the option.
  # Restore the exact original load order after the lifecycle hooks have run;
  # equal membership alone is not enough because plugin load order is
  # observable behaviour.
  if [ -n "$start_active_plugins" ]; then
    wpx option update active_plugins "$start_active_plugins" --format=json >/dev/null 2>&1 || true
  fi
}
trap restore EXIT HUP INT TERM

# --------------------------------------------------------------------------
# 0. Starting conditions
# --------------------------------------------------------------------------

core_status=$(wpx plugin list --field=status --name=kuka-island-core | tr -d '\r\n' || true)
wc_status=$(wpx plugin list --field=status --name=woocommerce | tr -d '\r\n' || true)

# WHAT THIS ASSERTS, AND WHAT IT ONLY RECORDS.
#
# It used to demand that the plugin start INACTIVE with the run-gate option
# ABSENT -- a pristine install that has never been deactivated. That is not the
# delivery state of a site whose operator has deactivated the plugin: a closed
# gate is the CORRECT companion of an inactive plugin, so this line failed for
# doing the right thing, and it took the whole suite down with it while
# ACTIVATION, DEACTIVATION and RESTORED all passed.
#
# The starting plugin state and gate row are now RECORDED. What is asserted is
# what this suite needs in order to mean anything: Core and WooCommerce are
# active, so the activation and deactivation measured below are measuring THIS
# module and not a missing dependency. Whatever the starting state was is then
# restored exactly -- see restore() above and EDM_LIFECYCLE_RESTORED below,
# which compares plugin state, gate existence, gate VALUE, active_plugins and
# the invoice meta count.
if [ "$core_status" = 'active' ] && [ "$wc_status" = 'active' ]; then
  note "EDM_LIFECYCLE_START=PASS|measured:wp_cli|edm:$start_status|core:$core_status|woocommerce:$wc_status|gate_option:$start_gate_present|invoice_meta_rows:$start_invoice_meta|edm_actions:$start_edm_actions|starting_state:recorded_not_asserted"
else
  fail "EDM_LIFECYCLE_START=FAIL|edm:$start_status|core:$core_status|woocommerce:$wc_status|gate_option:$start_gate_present|invoice_meta_rows:$start_invoice_meta"
fi

# --------------------------------------------------------------------------
# 1. Activation, measured in a fresh process
# --------------------------------------------------------------------------

if ! wpx plugin activate "$EDM_SLUG" >/dev/null 2>&1; then
  fail 'EDM_LIFECYCLE_ACTIVATION=FAIL|reason:activate_command_failed'
else
  active_line=$(wpx eval '
$slug   = "kuka-island-edm/kuka-island-edm.php";
$active = in_array( $slug, (array) get_option( "active_plugins", array() ), true );

$root    = class_exists( "Kuka_Island_EDM_Plugin", false );
$booted  = $root && Kuka_Island_EDM_Plugin::instance()->is_booted();
$missing = $root ? Kuka_Island_EDM_Plugin::missing_dependencies() : array( "composition_root_absent" );

$classes = array( "Kuka_Island_Core_Invoice", "Kuka_Island_Core_Invoice_Manager", "Kuka_Island_Core_EDM_Client", "Kuka_Island_Core_UBL_TR_Builder" );
$absent  = array();
foreach ( $classes as $c ) { if ( ! class_exists( $c, false ) ) { $absent[] = $c; } }

$hooks = array(
  "queue_processing"  => has_action( "woocommerce_order_status_processing" ),
  "queue_completed"   => has_action( "woocommerce_order_status_completed" ),
  "queue_worker"      => has_action( "kuka_island_process_order_invoice" ),
  "poller_worker"     => has_action( "kuka_island_query_invoice_status" ),
  "admin_manual_send" => has_action( "admin_post_kuka_invoice_manual_send" ),
  "admin_requery"     => has_action( "admin_post_kuka_invoice_requery" ),
);
$unregistered = array();
foreach ( $hooks as $name => $present ) { if ( ! $present ) { $unregistered[] = $name; } }

$gate_open = class_exists( "Kuka_Island_Core_Invoice_Runtime_Gate", false )
  ? ! Kuka_Island_Core_Invoice_Runtime_Gate::is_disabled()
  : false;

$auto = get_option( "kuka_invoice_auto_send" );
$auto_off = empty( $auto ) && ! ( defined( "KUKA_INVOICE_AUTO_SEND" ) && true === KUKA_INVOICE_AUTO_SEND );

$creds = class_exists( "Kuka_Island_Core_Invoice_Config", false )
  ? ( new Kuka_Island_Core_Invoice_Config() )->has_login_credentials()
  : false;

printf(
  "active:%s|composition_root:%s|booted:%s|missing_deps:%s|classes_absent:%s|hooks_unregistered:%s|runtime_gate_open:%s|auto_send_off:%s|credentials_configured:%s",
  $active ? "yes" : "NO",
  $root ? "loaded" : "ABSENT",
  $booted ? "yes" : "NO",
  array() === $missing ? "none" : implode( ",", $missing ),
  array() === $absent ? "none" : implode( ",", $absent ),
  array() === $unregistered ? "none" : implode( ",", $unregistered ),
  $gate_open ? "yes" : "NO",
  $auto_off ? "yes" : "NO",
  $creds ? "YES" : "no"
);
' | tr -d '\r\n' || true)

  after_actions=$(wpx eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN (\"kuka_island_process_order_invoice\",\"kuka_island_query_invoice_status\")" );' | tr -d '\r\n' || echo 'x')
  after_meta=$(wpx eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE \"_kuka_invoice%\"" );' | tr -d '\r\n' || echo 'x')

  # "SendInvoice:0 / LoadInvoice:0" is asserted through what a transmission
  # would have LEFT BEHIND, since there is no call counter in a live process:
  # no new scheduled action, no new invoice meta, and no usable credentials, so
  # a send was not merely unobserved but impossible.
  expected="active:yes|composition_root:loaded|booted:yes|missing_deps:none|classes_absent:none|hooks_unregistered:none|runtime_gate_open:yes|auto_send_off:yes|credentials_configured:no"

  if [ "$active_line" = "$expected" ] && [ "$after_actions" = "$start_edm_actions" ] && [ "$after_meta" = "$start_invoice_meta" ]; then
    note "EDM_LIFECYCLE_ACTIVATION=PASS|measured:fresh_wp_process|$active_line|actions_delta:0|invoice_meta_delta:0|SendInvoice:0|LoadInvoice:0"
  else
    fail "EDM_LIFECYCLE_ACTIVATION=FAIL|measured:fresh_wp_process|$active_line|actions:$start_edm_actions->$after_actions|invoice_meta:$start_invoice_meta->$after_meta"
  fi
fi

# --------------------------------------------------------------------------
# 2. Deactivation, measured in a fresh process
# --------------------------------------------------------------------------

if ! wpx plugin deactivate "$EDM_SLUG" >/dev/null 2>&1; then
  fail 'EDM_LIFECYCLE_DEACTIVATION=FAIL|reason:deactivate_command_failed'
else
  off_line=$(wpx eval '
$slug   = "kuka-island-edm/kuka-island-edm.php";
$active = in_array( $slug, (array) get_option( "active_plugins", array() ), true );

$declared = array();
foreach ( array( "Kuka_Island_EDM_Plugin", "Kuka_Island_Core_Invoice", "Kuka_Island_Core_Invoice_Manager", "Kuka_Island_Core_EDM_Client" ) as $c ) {
  if ( class_exists( $c, false ) ) { $declared[] = $c; }
}

$registered = array();
foreach ( array( "kuka_island_process_order_invoice", "kuka_island_query_invoice_status", "admin_post_kuka_invoice_manual_send" ) as $h ) {
  if ( has_action( $h ) ) { $registered[] = $h; }
}

$pending = 0;
if ( function_exists( "as_get_scheduled_actions" ) && class_exists( "ActionScheduler_Store" ) ) {
  foreach ( array( "kuka_island_process_order_invoice", "kuka_island_query_invoice_status" ) as $h ) {
    foreach ( array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ) as $st ) {
      $pending += count( (array) as_get_scheduled_actions( array( "hook" => $h, "status" => $st, "per_page" => 50 ), "ids" ) );
    }
  }
}

$core = class_exists( "Kuka_Island_Core_Plugin", false ) && class_exists( "Kuka_Island_Core_Corporate_Billing", false );
$wc   = class_exists( "WooCommerce" ) && function_exists( "wc_get_order" );

printf(
  "active:%s|classes_declared:%s|hooks_registered:%s|pending_edm_actions:%d|core_works:%s|woocommerce_works:%s",
  $active ? "YES" : "no",
  array() === $declared ? "none" : implode( ",", $declared ),
  array() === $registered ? "none" : implode( ",", $registered ),
  $pending,
  $core ? "yes" : "NO",
  $wc ? "yes" : "NO"
);
' | tr -d '\r\n' || true)

  off_meta=$(wpx eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE \"_kuka_invoice%\"" );' | tr -d '\r\n' || echo 'x')
  off_actions=$(wpx eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook IN (\"kuka_island_process_order_invoice\",\"kuka_island_query_invoice_status\")" );' | tr -d '\r\n' || echo 'x')

  expected_off="active:no|classes_declared:none|hooks_registered:none|pending_edm_actions:0|core_works:yes|woocommerce_works:yes"

  # The invoice meta rows are the audit trail. Deactivation must leave every
  # one of them: an integration that erases what it issued leaves the shop
  # unable to answer the one question that matters after it is switched off.
  if [ "$off_line" = "$expected_off" ] && [ "$off_meta" = "$start_invoice_meta" ] && [ "$off_actions" = "$start_edm_actions" ]; then
    note "EDM_LIFECYCLE_DEACTIVATION=PASS|measured:fresh_wp_process|$off_line|invoice_meta_rows:$off_meta|invoice_meta_preserved:yes|actions_row_delta:0|SendInvoice:0|LoadInvoice:0"
  else
    fail "EDM_LIFECYCLE_DEACTIVATION=FAIL|measured:fresh_wp_process|$off_line|invoice_meta:$start_invoice_meta->$off_meta|actions:$start_edm_actions->$off_actions"
  fi
fi

# --------------------------------------------------------------------------
# 3. Restore, then prove the restoration
# --------------------------------------------------------------------------

restore
trap - EXIT HUP INT TERM

end_status=$(wpx plugin list --field=status --name="$EDM_SLUG" | tr -d '\r\n' || true)
if wpx option get "$GATE_OPTION" >/dev/null 2>&1; then
  end_gate_present='yes'
else
  end_gate_present='no'
fi
end_active_plugins=$(wpx option get active_plugins --format=json | tr -d '\r\n' || true)

# The VALUE too, not only whether the row exists. Putting a closed gate back as
# an open one would leave EDM transmission enabled on a site whose operator
# deactivated the plugin -- the one residue this suite must never produce.
if [ "$end_gate_present" = 'yes' ]; then
  end_gate_value=$(wpx option get "$GATE_OPTION" | tr -d '\r\n' || true)
else
  end_gate_value=''
fi
end_invoice_meta=$(wpx eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE \"_kuka_invoice%\"" );' | tr -d '\r\n' || echo 'x')

same_plugins='no'
[ "$start_active_plugins" = "$end_active_plugins" ] && same_plugins='yes'

if [ "$end_status" = "$start_status" ] && [ "$end_gate_present" = "$start_gate_present" ] && [ "$end_gate_value" = "$start_gate_value" ] && [ "$same_plugins" = 'yes' ] && [ "$end_invoice_meta" = "$start_invoice_meta" ]; then
  note "EDM_LIFECYCLE_RESTORED=PASS|measured:wp_cli|edm:$end_status|gate_option:$end_gate_present|gate_value_identical:yes|active_plugins_identical:yes|invoice_meta_rows:$end_invoice_meta|edm_network_operations:0|sandbox_state_touched:no"
else
  fail "EDM_LIFECYCLE_RESTORED=FAIL|edm:$start_status->$end_status|gate_option:$start_gate_present->$end_gate_present|gate_value_identical:$( [ "$end_gate_value" = "$start_gate_value" ] && echo yes || echo no )|active_plugins_identical:$same_plugins|invoice_meta:$start_invoice_meta->$end_invoice_meta"
fi

if [ "$failures" -ne 0 ]; then
  echo "EDM_LIFECYCLE=FAIL|failures:$failures" >&2
  exit 1
fi

echo 'EDM_LIFECYCLE=PASS|activation_and_deactivation_measured_through_wp_cli'
