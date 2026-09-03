<?php
/**
 * The passive delivery contract, measured in a real WordPress runtime.
 *
 * The EDM plugin ships inactive. This script proves what that actually means,
 * and it deliberately does NOT load the invoice module -- loading it would
 * destroy the very thing being measured. Everything here is observed from the
 * live runtime: declared classes, the real $wp_filter registry, Action
 * Scheduler's own store, and an order taken through a genuine WooCommerce
 * status lifecycle.
 *
 * A source scan would prove nothing useful. Source can contain a hook
 * registration that never runs, and can omit one that a filter adds. The
 * question "is anything hooked right now" is only answerable at runtime.
 *
 * No EDM operation is possible from here: the classes that could contact EDM
 * are asserted absent, so there is nothing to call.
 *
 * @package Kuka_Island_EDM
 */

defined( 'WP_CLI' ) || exit( 1 );

/*
 * The lifecycle below moves a real order through processing and completed,
 * which would otherwise send customer e-mail and spawn sendmail subprocesses.
 * Suppressed for the same reason the other suites suppress it: a verification
 * run must not send mail to anyone.
 */
add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_refunded_order', '__return_false' );

$failures = array();
$report   = static function ( string $name, bool $passed, string $detail = '' ) use ( &$failures ): void {
	WP_CLI::line( sprintf( '%s=%s%s', $name, $passed ? 'PASS' : 'FAIL', '' !== $detail ? '|' . $detail : '' ) );
	if ( ! $passed ) {
		$failures[] = $name;
	}
};

const KUKA_PASSIVE_RUN_META = '_kuka_passive_run_id';

$run_id = 'passive-' . bin2hex( random_bytes( 6 ) );

/* ========================================================================== */
/* 1. Plugin state                                                             */
/* ========================================================================== */

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$edm_file    = 'kuka-island-edm/kuka-island-edm.php';
$edm_path    = trailingslashit( WP_PLUGIN_DIR ) . $edm_file;
$edm_present = is_readable( $edm_path );
$edm_active  = is_plugin_active( $edm_file );
$core_active = is_plugin_active( 'kuka-island-core/kuka-island-core.php' );
$wc_active   = class_exists( 'WooCommerce' );

// The header must actually declare what the delivery contract promises.
$header        = $edm_present ? (string) file_get_contents( $edm_path ) : '';
$header_ok     = str_contains( $header, 'Plugin Name: Kuka Island EDM' )
	&& str_contains( $header, 'Text Domain: kuka-island-edm' )
	&& str_contains( $header, 'Requires Plugins: woocommerce, kuka-island-core' );

$report(
	'EDM_PASSIVE_PLUGIN_STATE',
	$edm_present && ! $edm_active && $core_active && $wc_active && $header_ok,
	sprintf(
		'measured:wordpress_runtime|plugin_file_present:%s|plugin_active:%s|core_active:%s|woocommerce_active:%s|header_declares_dependencies:%s',
		$edm_present ? 'yes' : 'NO',
		$edm_active ? 'YES' : 'no',
		$core_active ? 'yes' : 'NO',
		$wc_active ? 'yes' : 'NO',
		$header_ok ? 'yes' : 'NO'
	)
);

/* ========================================================================== */
/* 2. No class of the module is loaded                                         */
/* ========================================================================== */

$module_classes = array(
	'Kuka_Island_Core_Invoice',
	'Kuka_Island_Core_Invoice_Manager',
	'Kuka_Island_Core_Invoice_Queue',
	'Kuka_Island_Core_Invoice_Status_Poller',
	'Kuka_Island_Core_Invoice_Admin',
	'Kuka_Island_Core_Invoice_Order_Store',
	'Kuka_Island_Core_Invoice_Recovery',
	'Kuka_Island_Core_EDM_Client',
	'Kuka_Island_Core_EDM_SOAP_Transport',
	'Kuka_Island_Core_UBL_TR_Builder',
	'Kuka_Island_Core_Internet_Sales_Details',
	'Kuka_Island_EDM_Plugin',
);

$declared = array();
foreach ( $module_classes as $class_name ) {
	if ( class_exists( $class_name, false ) ) {
		$declared[] = $class_name;
	}
}

$report(
	'EDM_PASSIVE_CLASSES_ABSENT',
	array() === $declared,
	sprintf(
		'measured:declared_classes|checked:%d|declared:%s|soap_client_loadable:%s',
		count( $module_classes ),
		array() === $declared ? 'none' : implode( ',', $declared ),
		class_exists( 'Kuka_Island_Core_EDM_SOAP_Transport', false ) ? 'YES' : 'no'
	)
);

/* ========================================================================== */
/* 3. No hook of the module is registered                                     */
/* ========================================================================== */

/**
 * Callbacks on a hook that belong to the EDM module.
 *
 * Read out of the real $wp_filter registry. Object callbacks are matched by
 * their class, string callbacks by prefix, so a module callback cannot hide
 * behind an unexpected shape.
 *
 * @return array<int, string>
 */
$module_callbacks = static function ( string $hook ): array {
	global $wp_filter;

	$found = array();
	if ( ! isset( $wp_filter[ $hook ] ) ) {
		return $found;
	}

	$registry = $wp_filter[ $hook ];
	$all      = $registry instanceof WP_Hook ? $registry->callbacks : (array) $registry;

	foreach ( (array) $all as $priority => $callbacks ) {
		foreach ( (array) $callbacks as $entry ) {
			$callback = $entry['function'] ?? null;
			$label    = '';

			if ( is_array( $callback ) && isset( $callback[0] ) ) {
				$label = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			} elseif ( is_string( $callback ) ) {
				$label = $callback;
			} elseif ( $callback instanceof Closure ) {
				$label = 'Closure';
			}

			if ( '' === $label ) {
				continue;
			}
			if ( str_starts_with( $label, 'Kuka_Island_Core_Invoice' )
				|| str_starts_with( $label, 'Kuka_Island_Core_EDM' )
				|| str_starts_with( $label, 'Kuka_Island_EDM' ) ) {
				$found[] = sprintf( '%s@%s', $hook, $label );
			}
		}
	}

	return $found;
};

/*
 * Two kinds of hook. The WooCommerce ones must exist (other modules use them)
 * but carry no EDM callback; the two Action Scheduler hooks are the module's
 * own and must have no callbacks at all.
 */
$shared_hooks = array(
	'woocommerce_order_status_processing',
	'woocommerce_order_status_completed',
	'woocommerce_order_refunded',
	'woocommerce_fulfillment_after_fulfill',
	'add_meta_boxes',
	'admin_post_kuka_invoice_requery',
	'admin_post_kuka_invoice_manual_send',
	'admin_post_kuka_invoice_recreate',
);

$owned_hooks = array(
	'kuka_island_process_order_invoice',
	'kuka_island_query_invoice_status',
);

$hook_hits = array();
foreach ( $shared_hooks as $hook ) {
	$hook_hits = array_merge( $hook_hits, $module_callbacks( $hook ) );
}

$owned_registered = array();
foreach ( $owned_hooks as $hook ) {
	if ( has_action( $hook ) ) {
		$owned_registered[] = $hook;
	}
}

$report(
	'EDM_PASSIVE_HOOKS_ABSENT',
	array() === $hook_hits && array() === $owned_registered,
	sprintf(
		'measured:wp_filter_registry|shared_hooks_checked:%d|edm_callbacks:%s|own_action_hooks_registered:%s|admin_post_handlers:%s',
		count( $shared_hooks ),
		array() === $hook_hits ? 'none' : implode( ',', $hook_hits ),
		array() === $owned_registered ? 'none' : implode( ',', $owned_registered ),
		( has_action( 'admin_post_kuka_invoice_manual_send' ) || has_action( 'admin_post_kuka_invoice_recreate' ) ) ? 'PRESENT' : 'none'
	)
);

/* ========================================================================== */
/* 4. No Action Scheduler job of the module exists                            */
/* ========================================================================== */

$pending_actions = array();
if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler_Store' ) ) {
	foreach ( $owned_hooks as $hook ) {
		foreach ( array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			$found = (array) as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => $status,
					'group'    => 'kuka-island-invoice',
					'per_page' => 50,
				),
				'ids'
			);
			if ( array() !== $found ) {
				$pending_actions[] = sprintf( '%s/%s=%d', $hook, $status, count( $found ) );
			}
		}
	}
}

// The whole group, in case a hook name ever changes.
$group_actions = array();
if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler_Store' ) ) {
	foreach ( array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
		$found = (array) as_get_scheduled_actions(
			array(
				'group'    => 'kuka-island-invoice',
				'status'   => $status,
				'per_page' => 50,
			),
			'ids'
		);
		if ( array() !== $found ) {
			$group_actions[] = sprintf( '%s=%d', $status, count( $found ) );
		}
	}
}

$report(
	'EDM_PASSIVE_ACTIONS_ABSENT',
	array() === $pending_actions && array() === $group_actions,
	sprintf(
		'measured:action_scheduler_store|by_hook:%s|by_group:%s|group:kuka-island-invoice',
		array() === $pending_actions ? 'none' : implode( ',', $pending_actions ),
		array() === $group_actions ? 'none' : implode( ',', $group_actions )
	)
);

/* ========================================================================== */
/* 5. A real order lifecycle writes no invoice meta and books no job          */
/* ========================================================================== */

/*
 * The strongest available measurement: take an order through the exact
 * transitions that would trigger invoicing if the module were active, and look
 * at what it carries afterwards. Meta is counted by prefix, so a key added in
 * future is caught without this list being maintained.
 */
$lifecycle_error = '';
$order_id        = 0;
$invoice_meta    = array();
$statuses_seen   = array();
$actions_after   = array();

try {
	$order = wc_create_order();
	$order->update_meta_data( KUKA_PASSIVE_RUN_META, $run_id );
	$order->set_billing_first_name( 'Pasif' );
	$order->set_billing_last_name( 'Sozlesme' );
	$order->set_billing_email( 'pasif.sozlesme@example.com' );
	$order->set_billing_address_1( 'Test Mahallesi 1' );
	$order->set_billing_city( 'İstanbul' );
	$order->set_billing_country( 'TR' );
	$order->set_payment_method( 'iyzico' );
	$order->set_total( '120.00' );
	$order->save();
	$order_id = (int) $order->get_id();

	// The two transitions the queue listens on, plus a refund event.
	$order->set_status( 'processing' );
	$order->save();
	$statuses_seen[] = (string) $order->get_status();

	$order->set_status( 'completed' );
	$order->save();
	$statuses_seen[] = (string) $order->get_status();

	// The fulfilment event the shipment gate listens on.
	do_action( 'woocommerce_order_refunded', $order_id, 0 );

	$fresh = wc_get_order( $order_id );
	foreach ( (array) $fresh->get_meta_data() as $meta ) {
		$key = (string) ( $meta->key ?? '' );
		if ( str_starts_with( $key, '_kuka_invoice' ) ) {
			$invoice_meta[] = $key;
		}
	}

	if ( function_exists( 'as_get_scheduled_actions' ) ) {
		foreach ( $owned_hooks as $hook ) {
			$found = (array) as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => array( 'order_id' => $order_id ),
					'per_page' => 10,
				),
				'ids'
			);
			if ( array() !== $found ) {
				$actions_after[] = sprintf( '%s=%d', $hook, count( $found ) );
			}
		}
	}
} catch ( Throwable $t ) {
	$lifecycle_error = get_class( $t ) . ': ' . $t->getMessage();
}

$lifecycle_ok = '' === $lifecycle_error
	&& $order_id > 0
	&& array( 'processing', 'completed' ) === $statuses_seen
	&& array() === $invoice_meta
	&& array() === $actions_after;

$report(
	'EDM_PASSIVE_ORDER_LIFECYCLE',
	$lifecycle_ok,
	sprintf(
		'measured:real_woocommerce_order|transitions:%s|invoice_meta_keys:%s|actions_booked:%s|woocommerce_still_works:%s|error:%s',
		array() === $statuses_seen ? 'none' : implode( '->', $statuses_seen ),
		array() === $invoice_meta ? 'none' : implode( ',', $invoice_meta ),
		array() === $actions_after ? 'none' : implode( ',', $actions_after ),
		( $order_id > 0 && array( 'processing', 'completed' ) === $statuses_seen ) ? 'yes' : 'NO',
		'' === $lifecycle_error ? 'none' : $lifecycle_error
	)
);

/* ========================================================================== */
/* 6. Core is intact without the EDM plugin                                    */
/* ========================================================================== */

$core_classes = array(
	'Kuka_Island_Core_Plugin',
	'Kuka_Island_Core_Corporate_Billing',
	'Kuka_Island_Core_Fulfillments',
	'Kuka_Island_Core_Iyzico_Idempotency',
	'Kuka_Island_Core_Shipping',
);

$core_missing = array();
foreach ( $core_classes as $class_name ) {
	if ( ! class_exists( $class_name, false ) ) {
		$core_missing[] = $class_name;
	}
}

// Core's own composition must no longer mention the invoice module at all.
$core_plugin_source = (string) file_get_contents( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/class-plugin.php' );
$core_mentions      = preg_match( '/[\'"]class-invoice\.php[\'"]|new\s+Kuka_Island_Core_Invoice\s*\(/', $core_plugin_source );

$report(
	'EDM_PASSIVE_CORE_INTACT',
	array() === $core_missing && 0 === $core_mentions,
	sprintf(
		'measured:declared_classes_and_core_source|core_classes_missing:%s|core_loads_invoice_module:%s|dependency_direction:edm_to_core_only',
		array() === $core_missing ? 'none' : implode( ',', $core_missing ),
		0 === $core_mentions ? 'no' : 'YES'
	)
);

/* ========================================================================== */
/* Cleanup: ownership-checked, and refusal fails the run                       */
/* ========================================================================== */

$cleanup_ok = true;
if ( $order_id > 0 ) {
	$order = wc_get_order( $order_id );
	if ( $order instanceof WC_Order ) {
		$owner = (string) $order->get_meta( KUKA_PASSIVE_RUN_META, true );
		if ( $owner !== $run_id ) {
			$cleanup_ok = false;
			WP_CLI::warning( sprintf( 'Ownership refusal: order #%d is not owned by this run.', $order_id ) );
		} else {
			foreach ( wc_get_order_notes( array( 'order_id' => $order_id ) ) as $note ) {
				wp_delete_comment( $note->id, true );
			}
			$order->delete( true );
		}
	}
}

$residue = wc_get_order( $order_id ) instanceof WC_Order;

$report(
	'EDM_PASSIVE_FIXTURE_RESIDUE',
	$cleanup_ok && ! $residue,
	sprintf(
		'measured:post_cleanup|order_removed:%s|ownership_checked:%s',
		$residue ? 'NO' : 'yes',
		$cleanup_ok ? 'yes' : 'REFUSED'
	)
);

if ( ! empty( $failures ) ) {
	WP_CLI::error( sprintf( 'EDM passive contract failed (%d: %s).', count( $failures ), implode( ', ', $failures ) ) );
}

WP_CLI::success( 'EDM passive contract verified. Plugin inactive, no hook, no job, no meta, no SOAP.' );
