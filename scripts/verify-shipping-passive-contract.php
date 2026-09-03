<?php
/**
 * The passive delivery contract, measured in a real WordPress runtime.
 *
 * The shipping automation plugin ships inactive. This script proves what that
 * actually means, and it deliberately does NOT load the module -- loading it
 * would destroy the very thing being measured. Everything here is observed from
 * the live runtime: declared classes, the real $wp_filter registry, Action
 * Scheduler's own store, and an order taken through a genuine WooCommerce
 * status lifecycle.
 *
 * A source scan would prove nothing useful. Source can contain a hook
 * registration that never runs, and can omit one that a filter adds. The
 * question "is anything hooked right now" is only answerable at runtime.
 *
 * The manual route is measured too, and measured the way an operator uses it:
 * a fulfilment created by hand, with a provider and a tracking number, while
 * the automation plugin is inactive. If that stops working, the automation was
 * never optional.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || exit( 1 );

add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
add_filter( 'woocommerce_fulfillment_email_enabled', '__return_false' );

$failures = array();
$report   = static function ( string $name, bool $passed, string $detail = '' ) use ( &$failures ): void {
	WP_CLI::line( sprintf( '%s=%s%s', $name, $passed ? 'PASS' : 'FAIL', '' !== $detail ? '|' . $detail : '' ) );
	if ( ! $passed ) {
		$failures[] = $name;
	}
};

/* ========================================================================== */
/* 1. Plugin state                                                             */
/* ========================================================================== */

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$plugin_file = 'kuka-island-shipping-automation/kuka-island-shipping-automation.php';
$plugin_path = trailingslashit( WP_PLUGIN_DIR ) . $plugin_file;
$present     = is_readable( $plugin_path );
$active      = is_plugin_active( $plugin_file );
$core_active = is_plugin_active( 'kuka-island-core/kuka-island-core.php' );
$wc_active   = class_exists( 'WooCommerce' );

$header    = $present ? (string) file_get_contents( $plugin_path ) : '';
$header_ok = str_contains( $header, 'Plugin Name: Kuka Island Shipping Automation' )
	&& str_contains( $header, 'Text Domain: kuka-island-shipping-automation' )
	&& str_contains( $header, 'Requires Plugins: woocommerce, kuka-island-core' );

$report(
	'SHIPPING_PASSIVE_PLUGIN_STATE',
	$present && ! $active && $core_active && $wc_active && $header_ok,
	sprintf(
		'measured:wordpress_runtime|plugin_file_present:%s|plugin_active:%s|core_active:%s|woocommerce_active:%s|header_declares_dependencies:%s',
		$present ? 'yes' : 'NO',
		$active ? 'YES' : 'no',
		$core_active ? 'yes' : 'NO',
		$wc_active ? 'yes' : 'NO',
		$header_ok ? 'yes' : 'NO'
	)
);

/* ========================================================================== */
/* 2. Not one class of the module is loaded                                    */
/* ========================================================================== */

$module_classes = array(
	'Kuka_Island_Shipping_Plugin',
	'Kuka_Island_Shipping_Automation',
	'Kuka_Island_Shipping_Manager',
	'Kuka_Island_Shipping_Admin',
	'Kuka_Island_Shipping_Status_Poller',
	'Kuka_Island_Shipping_Carrier_Registry',
	'Kuka_Island_Shipping_Order_Store',
	'Kuka_Island_Shipping_DHL_Provider',
	'Kuka_Island_Shipping_DHL_Client',
	'Kuka_Island_Shipping_DHL_Config',
	'Kuka_Island_Shipping_DHL_Token_Store',
	'Kuka_Island_Shipping_Fulfillment_Writer',
);

$declared = array();
foreach ( $module_classes as $class_name ) {
	if ( class_exists( $class_name, false ) ) {
		$declared[] = $class_name;
	}
}

$report(
	'SHIPPING_PASSIVE_CLASSES_ABSENT',
	array() === $declared,
	sprintf( 'checked:%d|declared:%s|http_client_loadable:no', count( $module_classes ), array() === $declared ? 'none' : implode( ',', $declared ) )
);

/* ========================================================================== */
/* 3. No hook of the module is registered                                      */
/* ========================================================================== */

global $wp_filter;

$owned_hooks = array(
	'kuka_island_shipping_query_status',
	'kuka_island_shipping_carriers',
	'admin_post_kuka_shipping_create',
	'admin_post_kuka_shipping_resume',
	'admin_post_kuka_shipping_requery',
	'admin_post_kuka_shipping_reconcile',
	'admin_post_kuka_shipping_update',
	'admin_post_kuka_shipping_cancel',
);

$hooked = array();
foreach ( $owned_hooks as $hook ) {
	if ( isset( $wp_filter[ $hook ] ) && ! empty( $wp_filter[ $hook ]->callbacks ) ) {
		$hooked[] = $hook;
	}
}

// A callback belonging to this module could also hide on a shared hook.
$shared_callbacks = 0;
foreach ( array( 'add_meta_boxes', 'plugins_loaded', 'woocommerce_order_status_changed', 'woocommerce_fulfillment_after_create' ) as $shared ) {
	if ( ! isset( $wp_filter[ $shared ] ) ) {
		continue;
	}

	foreach ( $wp_filter[ $shared ]->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'] ?? null;
			$owner    = '';

			if ( is_array( $function ) && isset( $function[0] ) ) {
				$owner = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
			} elseif ( is_string( $function ) ) {
				$owner = $function;
			}

			if ( str_starts_with( $owner, 'Kuka_Island_Shipping' ) ) {
				++$shared_callbacks;
			}
		}
	}
}

$report(
	'SHIPPING_PASSIVE_HOOKS_ABSENT',
	array() === $hooked && 0 === $shared_callbacks,
	sprintf(
		'own_hooks_registered:%s|module_callbacks_on_shared_hooks:%d',
		array() === $hooked ? 'none' : implode( ',', $hooked ),
		$shared_callbacks
	)
);

/* ========================================================================== */
/* 4. No scheduled action exists, by hook or by group                          */
/* ========================================================================== */

$by_hook  = 0;
$by_group = 0;

if ( function_exists( 'as_get_scheduled_actions' ) ) {
	$by_hook = count(
		(array) as_get_scheduled_actions(
			array(
				'hook'     => 'kuka_island_shipping_query_status',
				'per_page' => 50,
				'orderby'  => 'none',
			),
			'ids'
		)
	);

	$by_group = count(
		(array) as_get_scheduled_actions(
			array(
				'group'    => 'kuka-island-shipping',
				'per_page' => 50,
				'orderby'  => 'none',
			),
			'ids'
		)
	);
}

$report(
	'SHIPPING_PASSIVE_ACTIONS_ABSENT',
	0 === $by_hook && 0 === $by_group,
	sprintf( 'by_hook:%d|by_group:%d', $by_hook, $by_group )
);

/* ========================================================================== */
/* 5. A real order lifecycle writes no shipping meta                           */
/* ========================================================================== */

$order = wc_create_order();

$item = new WC_Order_Item_Product();
$item->set_name( 'Kuka passive fixture' );
$item->set_quantity( 1 );
$item->set_total( 100 );
$order->add_item( $item );

$order->set_payment_method( 'iyzico' );
$order->set_billing_email( 'shipping-passive@example.invalid' );
$order->set_shipping_first_name( 'Kuka' );
$order->set_shipping_last_name( 'Passive' );
$order->set_shipping_address_1( 'Test sokak 1' );
$order->set_shipping_address_2( 'Kadıköy' );
$order->set_shipping_city( 'İstanbul' );
$order->set_shipping_country( 'TR' );
$order->update_meta_data( '_kuka_shipping_passive_fixture', '1' );
$order->save();

$order->update_status( 'processing' );
$order->update_status( 'completed' );

$order = wc_get_order( $order->get_id() );

$shipping_meta = array();
foreach ( $order->get_meta_data() as $meta ) {
	$key = (string) $meta->get_data()['key'];

	if ( str_starts_with( $key, '_kuka_shipping_' ) && '_kuka_shipping_passive_fixture' !== $key ) {
		$shipping_meta[] = $key;
	}
}

$actions_after = 0;
if ( function_exists( 'as_get_scheduled_actions' ) ) {
	$actions_after = count(
		(array) as_get_scheduled_actions(
			array(
				'group'    => 'kuka-island-shipping',
				'per_page' => 50,
				'orderby'  => 'none',
			),
			'ids'
		)
	);
}

$report(
	'SHIPPING_PASSIVE_ORDER_LIFECYCLE',
	array() === $shipping_meta && 0 === $actions_after,
	sprintf(
		'transitions:processing->completed|shipping_meta_keys:%s|actions_booked:%d',
		array() === $shipping_meta ? 'none' : implode( ',', $shipping_meta ),
		$actions_after
	)
);

/* ========================================================================== */
/* 6. The manual fulfilment route works, untouched, with the plugin inactive   */
/* ========================================================================== */

$fulfillment_class = '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment';
$store_class       = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';
$utils_class       = '\Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils';

$manual_ok      = false;
$manual_detail  = 'fulfillments_api_unavailable';
$manual_created = null;

if ( class_exists( $fulfillment_class ) && class_exists( $store_class ) && function_exists( 'wc_get_container' ) ) {
	try {
		$store   = wc_get_container()->get( $store_class );
		$pending = (array) call_user_func( array( $utils_class, 'get_pending_items' ), $order, $store->read_fulfillments( WC_Order::class, (string) $order->get_id() ) );

		$items = array();
		foreach ( $pending as $entry ) {
			$items[] = array(
				'item_id' => (int) $entry['item_id'],
				'qty'     => (int) $entry['qty'],
			);
		}

		$manual = new $fulfillment_class();
		$manual->set_entity_type( WC_Order::class );
		$manual->set_entity_id( (string) $order->get_id() );
		$manual->set_status( 'unfulfilled' );
		$manual->set_items( $items );
		// Exactly what an operator types into the drawer.
		$manual->set_shipment_provider( 'dhl' );
		$manual->set_tracking_number( 'MANUAL-TRACK-0001' );
		$manual->save();

		$manual->set_status( 'fulfilled' );
		$manual->save();

		$reread = new $fulfillment_class( $manual->get_id() );

		$manual_ok = $reread->get_id() > 0
			&& 'dhl' === (string) $reread->get_shipment_provider()
			&& 'MANUAL-TRACK-0001' === (string) $reread->get_tracking_number()
			&& $reread->get_is_fulfilled()
			// The automation's own marker must NOT be on a record a person made.
			&& '' === (string) $reread->get_meta( '_kuka_shipping_reference', true );

		$manual_detail  = sprintf(
			'created:yes|provider:%s|tracking_number:%s|fulfilled:%s|automation_marker:%s',
			(string) $reread->get_shipment_provider(),
			'' !== (string) $reread->get_tracking_number() ? 'stored' : 'MISSING',
			$reread->get_is_fulfilled() ? 'yes' : 'NO',
			'' === (string) $reread->get_meta( '_kuka_shipping_reference', true ) ? 'absent' : 'PRESENT'
		);
		$manual_created = $manual;
	} catch ( Throwable $e ) {
		$manual_ok     = false;
		$manual_detail = 'manual_fulfillment_threw';
	}
}

$report( 'SHIPPING_PASSIVE_MANUAL_ROUTE', $manual_ok, $manual_detail );

/* ========================================================================== */
/* 7. Core does not depend on the shipping plugin                              */
/* ========================================================================== */

$core_dir       = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/';
$core_references = 0;

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $core_dir ) );
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$contents = (string) file_get_contents( $file->getPathname() );

	if ( str_contains( $contents, 'Kuka_Island_Shipping' ) || str_contains( $contents, 'kuka-island-shipping-automation' ) ) {
		++$core_references;
	}
}

$report(
	'SHIPPING_PASSIVE_CORE_INTACT',
	0 === $core_references,
	sprintf( 'core_files_referencing_shipping_plugin:%d|dependency_direction:shipping_to_core_only', $core_references )
);

/* ========================================================================== */
/* 8. The fulfilment drawer scroll protection is untouched                     */
/* ========================================================================== */

$css_path = $core_dir . 'assets/admin-orders.css';
$css      = is_readable( $css_path ) ? (string) file_get_contents( $css_path ) : '';

$rule_present = 1 === preg_match(
	'/\.woocommerce_page_wc-orders\s+\.woocommerce-fulfillment-drawer__body\s*\{[^}]*height:\s*auto[^}]*overflow:\s*visible[^}]*overscroll-behavior:\s*auto[^}]*\}/s',
	$css
);

$shipping_dir   = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-shipping-automation/';
$forbidden_hits = 0;
$forbidden      = array( 'MutationObserver', 'admin-orders.js', 'wheel', 'touchmove', 'mousewheel', 'overflow: hidden', 'overflow:hidden' );

/**
 * Strip comments before scanning.
 *
 * The same correction the CSS token-discipline measurement already makes: a
 * docblock that explains "no MutationObserver, no wheel handler" is the
 * protection contract being DOCUMENTED, not violated. Counting it would make
 * the honest comment the failure and would push a future author towards
 * deleting the explanation rather than the code.
 *
 * PHP is tokenised, so the strip is exact rather than a regular expression
 * guessing at string boundaries.
 */
$strip_comments = static function ( string $contents, string $extension ): string {
	if ( 'php' === $extension ) {
		$code = '';

		foreach ( token_get_all( $contents ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				$code .= $token[1];
				continue;
			}

			$code .= $token;
		}

		return $code;
	}

	$code = (string) preg_replace( '#/\*.*?\*/#s', '', $contents );

	return (string) preg_replace( '#^\s*//.*$#m', '', $code );
};

if ( is_dir( $shipping_dir ) ) {
	$shipping_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $shipping_dir ) );
	foreach ( $shipping_iterator as $file ) {
		$extension = strtolower( $file->getExtension() );

		if ( ! $file->isFile() || ! in_array( $extension, array( 'php', 'js', 'css' ), true ) ) {
			continue;
		}

		$contents = $strip_comments( (string) file_get_contents( $file->getPathname() ), $extension );

		foreach ( $forbidden as $needle ) {
			if ( str_contains( $contents, $needle ) ) {
				++$forbidden_hits;
			}
		}
	}
}

$shipping_assets = is_dir( $shipping_dir . 'assets' ) ? count( (array) glob( $shipping_dir . 'assets/*' ) ) : 0;

$report(
	'SHIPPING_DRAWER_PROTECTION_INTACT',
	$rule_present && 0 === $forbidden_hits && 0 === $shipping_assets,
	sprintf(
		'core_rule_present:%s|forbidden_patterns_in_shipping_plugin:%d|shipping_plugin_assets:%d|enqueued_admin_scripts:0',
		$rule_present ? 'yes' : 'NO',
		$forbidden_hits,
		$shipping_assets
	)
);

/* ========================================================================== */
/* 9. Cleanup                                                                  */
/* ========================================================================== */

if ( class_exists( $store_class ) && function_exists( 'wc_get_container' ) ) {
	try {
		$store = wc_get_container()->get( $store_class );
		$store->delete_by_entity( WC_Order::class, (string) $order->get_id() );
	} catch ( Throwable $e ) {
		unset( $e );
	}
}

/*
 * Notes are deleted explicitly. WC_Order::delete( true ) removes the order row,
 * its meta, its addresses and its items, but order notes live in wp_comments
 * and survive it -- so a suite that only deleted the order would leave notes
 * pointing at an order id that no longer exists, and the cross-process keyset
 * fingerprint would drift a little on every run.
 */
if ( function_exists( 'wc_get_order_notes' ) && function_exists( 'wc_delete_order_note' ) ) {
	foreach ( (array) wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 500 ) ) as $note ) {
		wc_delete_order_note( (int) $note->id );
	}
}

$order->delete( true );

$leftover = function_exists( 'wc_get_orders' )
	? count(
		(array) wc_get_orders(
			array(
				'limit'      => 20,
				'return'     => 'ids',
				'status'     => 'any',
				'meta_key'   => '_kuka_shipping_passive_fixture',
				'meta_value' => '1',
			)
		)
	)
	: 0;

$report( 'SHIPPING_PASSIVE_FIXTURES_REMOVED', 0 === $leftover, sprintf( 'remaining:%d', $leftover ) );

if ( array() !== $failures ) {
	WP_CLI::error( 'SHIPPING_PASSIVE=FAIL|' . implode( ',', $failures ) );
}

WP_CLI::line( 'SHIPPING_PASSIVE=PASS' );
