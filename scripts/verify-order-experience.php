<?php
/**
 * Read-only contract snapshot for the order-screen experience.
 *
 * Covers the shipping vocabulary and the rule that the order screen carries no
 * second customer summary or workflow guide of ours: WooCommerce's own Billing
 * panel, order status, line items and shipping drawer are the whole surface.
 * Nothing here writes.
 */

defined( 'WP_CLI' ) || exit( 1 );

/** Status/total of the long-lived sandbox orders this project must preserve. */
const KUKA_IYZ_PROTECTED_ORDERS_SNAPSHOT = array(
	125 => 'processing/4980',
	189 => 'processing/3039',
	190 => 'cancelled/3039',
	192 => 'cancelled/2750',
	193 => 'completed/3039',
);

$language = 'Kuka_Island_Core_Fulfillments_Language';
if ( ! class_exists( $language ) ) {
	WP_CLI::line( 'ORDER_EXPERIENCE=missing' );
	return;
}

wp_set_current_user( 1 );

/* 1. Terminology map size and split. */
WP_CLI::line( sprintf(
	'FULFILLMENT_MAP=total:%d|drawer:%d|php:%d',
	count( $language::all_strings() ),
	count( $language::drawer_strings() ),
	count( $language::php_strings() )
) );

/* 2. Scope first: a non-orders screen must not attach the filter at all. */
set_current_screen( 'dashboard' );
$off_screen = new $language();
$off_screen->boot();
WP_CLI::line( 'FULFILLMENT_SCOPE=dashboard_hooked:' . ( false === has_filter( 'gettext', array( $off_screen, 'translate' ) ) ? 'no' : 'YES' ) );

/* 3. Raw "yerine getirme" must be gone, and only this domain may be touched. */
set_current_screen( 'woocommerce_page_wc-orders' );
$module = new $language();
$module->boot();
WP_CLI::line( 'FULFILLMENT_HOOKED=orders_screen:' . ( false === has_filter( 'gettext', array( $module, 'translate' ) ) ? 'NO' : 'yes' ) );

$raw   = 0;
$typos = 0;
foreach ( array_keys( $language::php_strings() ) as $original ) {
	$out = __( $original, 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	if ( preg_match( '/yerine getir/iu', $out ) ) {
		++$raw;
	}
	if ( '' === trim( $out ) ) {
		++$typos;
	}
}
$other_domain = __( 'Fulfilled', 'kuka-island-core' ) !== 'Fulfilled' ? 1 : 0; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
$unrelated    = __( 'Orders', 'woocommerce' ) !== 'Siparişler' ? 1 : 0;
WP_CLI::line( 'FULFILLMENT_WORDING=raw_yerine_getirme:' . $raw . '|empty:' . $typos . '|other_domain_affected:' . $other_domain . '|unrelated_wc_affected:' . $unrelated );

/* 4. "Kargoya verildi" and "Teslim edildi" must stay distinct. */
$all      = $language::all_strings();
$delivered = 0;
foreach ( $all as $turkish ) {
	if ( str_contains( $turkish, 'Teslim edildi' ) || str_contains( $turkish, 'teslim edildi' ) ) {
		++$delivered;
	}
}
WP_CLI::line( 'FULFILLMENT_DELIVERY_TERM=teslim_edildi_in_map:' . $delivered );

/*
 * 5. The order screen must carry no extra summary or guide.
 *
 * WooCommerce's own Billing panel shows the customer, and the order status,
 * the line items and the shipping drawer carry the operation. A second block
 * duplicating any of that was removed; nothing may reintroduce it, not even as
 * a comment or hidden markup.
 */
$leftovers = 0;
$paths     = array(
	WP_PLUGIN_DIR . '/kuka-island-core/includes',
	WP_PLUGIN_DIR . '/kuka-island-core/assets',
);
$needles = array( 'kuka-order-overview', 'Kuka_Island_Core_Order_Overview', 'Müşteri özeti', 'Sipariş akışı', 'Sıradaki işlem' );
foreach ( $paths as $path ) {
	foreach ( (array) glob( $path . '/*' ) as $file ) {
		if ( ! is_file( $file ) ) {
			continue;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = (string) file_get_contents( $file );
		foreach ( $needles as $needle ) {
			$leftovers += substr_count( $contents, $needle );
		}
	}
}
WP_CLI::line( 'ORDER_OVERVIEW_REMOVED=' . ( 0 === $leftovers && ! class_exists( 'Kuka_Island_Core_Order_Overview' ) ? 'yes' : 'NO' ) . '|leftovers:' . $leftovers );

/* The customer's own details stay in WooCommerce's Billing panel. */
$billing = array();
foreach ( array( 297, 125 ) as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		continue;
	}
	$billing[] = sprintf(
		'%d:first:%s,last:%s,email:%s,phone:%s',
		$order_id,
		'' !== $order->get_billing_first_name() ? 'set' : 'EMPTY',
		'' !== $order->get_billing_last_name() ? 'set' : 'EMPTY',
		'' !== $order->get_billing_email() ? 'set' : 'EMPTY',
		'' !== $order->get_billing_phone() ? 'set' : 'empty'
	);
}
WP_CLI::line( 'ORDER_BILLING_FIELDS=' . implode( '|', $billing ) );

/*
 * 6. The Kargo İşlemleri drawer must have exactly one scrolling layer.
 *
 * A local `[class*="fulfillment-drawer"] { overflow: hidden }` rule overrode
 * the core `overflow-y: auto` on both the panel and its body, and the script
 * meant to compensate never ran because the React container does not exist yet
 * when it executes. Both stay gone. The only allowed drawer rule neutralizes
 * the non-scrolling inner body's wheel trap so WooCommerce's panel remains the
 * sole scroll container.
 */
$css_file = WP_PLUGIN_DIR . '/kuka-island-core/assets/admin-orders.css';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$css      = file_exists( $css_file ) ? (string) file_get_contents( $css_file ) : '';
$drawer_rules    = 0;
$safe_body_rules = 0;
foreach ( preg_split( '/\}/', $css ) ?: array() as $block ) {
	$parts    = explode( '{', $block, 2 );
	$selector = trim( (string) ( $parts[0] ?? '' ) );
	$body     = trim( (string) ( $parts[1] ?? '' ) );
	if ( '' === $selector ) {
		continue;
	}
	// Comments are stripped so the explanation above does not count as a rule.
	$selector = trim( (string) preg_replace( '#/\*.*?\*/#s', '', $selector ) );
	foreach ( array( 'fulfillment-drawer', 'wc_order_fulfillments_panel_container', 'data-kuka-scroll', 'data-kuka-drawer', 'kuka-fulfillment-drawer-open' ) as $needle ) {
		if ( '' !== $selector && str_contains( $selector, $needle ) ) {
			++$drawer_rules;
		}
	}
	if (
		'.woocommerce_page_wc-orders .woocommerce-fulfillment-drawer__body' === $selector
		&& preg_match( '/height\s*:\s*auto\s*;/', $body )
		&& preg_match( '/overflow\s*:\s*visible\s*;/', $body )
		&& preg_match( '/overscroll-behavior\s*:\s*auto\s*;/', $body )
	) {
		++$safe_body_rules;
	}
}
$script_gone = ! file_exists( WP_PLUGIN_DIR . '/kuka-island-core/assets/admin-orders.js' )
	&& ! wp_script_is( 'kuka-island-admin-orders', 'registered' );
WP_CLI::line(
	'DRAWER_SCROLL_CONTRACT=drawer_rules:' . $drawer_rules
	. '|safe_body_rules:' . $safe_body_rules
	. '|script:' . ( $script_gone ? 'removed' : 'PRESENT' )
);

/* 7. Long-lived sandbox orders are still present and untouched. */
$present = 0;
foreach ( KUKA_IYZ_PROTECTED_ORDERS_SNAPSHOT as $order_id => $signature ) {
	$order = wc_get_order( $order_id );
	$present += $order instanceof WC_Order && ( $order->get_status() . '/' . $order->get_total() ) === $signature ? 1 : 0;
}
WP_CLI::line( 'PROTECTED_ORDERS=' . $present . '/' . count( KUKA_IYZ_PROTECTED_ORDERS_SNAPSHOT ) );
