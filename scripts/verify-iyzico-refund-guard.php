<?php
/**
 * Read-only acceptance for the automatic iyzico refund guard.
 *
 * Every case is a pure preflight verdict or a hook-shape assertion: no refund
 * is created, no gateway call is made and no money moves. The behavioural proof
 * that a blocked refund leaves no record lives in the same contract — the guard
 * throws before `$refund->save()`, which `wc_create_refund()` turns into a
 * WP_Error.
 */

defined( 'WP_CLI' ) || exit( 1 );

$guard = 'Kuka_Island_Core_Iyzico_Refund_Guard';
if ( ! class_exists( $guard ) ) {
	WP_CLI::line( 'REFUND_GUARD=missing' );
	return;
}

global $wpdb;
$before = array(
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	'orders'   => (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}wc_orders" ),
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	'refunds'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order_refund'" ),
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	'provider' => (array) $wpdb->get_col( "SELECT iyzico_order_id FROM {$wpdb->prefix}iyzico_order" ),
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	'notes'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'order_note'" ),
);

/* An unsaved in-memory order is enough for a pure verdict. */
$order = new WC_Order();
$order->set_id( 4242 );

$row = static function ( array $overrides = array() ): array {
	return array_merge(
		array(
			'order_id'        => 4242,
			'payment_id'      => '900123',
			'conversation_id' => 'conv-4242',
			'status'          => 'success',
			'payment_status'  => 'SUCCESS',
		),
		$overrides
	);
};

$cases = array(
	'valid_latest_row'         => array( $row(), array( '900123' ), true, 'allowed' ),
	'payment_id_null'          => array( $row( array( 'payment_id' => null ) ), array( '900123' ), false, 'payment_id_missing' ),
	'payment_id_empty'         => array( $row( array( 'payment_id' => '  ' ) ), array( '900123' ), false, 'payment_id_missing' ),
	'conversation_id_empty'    => array( $row( array( 'conversation_id' => '' ) ), array( '900123' ), false, 'conversation_id_missing' ),
	'status_failure'           => array( $row( array( 'status' => 'failure' ) ), array( '900123' ), false, 'provider_status_not_success' ),
	'payment_status_failure'   => array( $row( array( 'payment_status' => 'FAILURE' ) ), array( '900123' ), false, 'payment_status_not_success' ),
	'verified_id_differs'      => array( $row(), array( '900999' ), false, 'payment_id_unverified' ),
	'no_verified_id'           => array( $row(), array(), false, 'payment_id_unverified' ),
	'order_id_mismatch'        => array( $row( array( 'order_id' => 9999 ) ), array( '900123' ), false, 'order_id_mismatch' ),
	'latest_row_missing'       => array( null, array( '900123' ), false, 'no_provider_row' ),
);

$results = array();
foreach ( $cases as $name => $case ) {
	[ $provider_row, $verified, $expect_allowed, $expect_reason ] = $case;
	$verdict   = $guard::preflight( $order, $provider_row, $verified );
	$results[] = $name . ':' . ( $verdict['allowed'] === $expect_allowed && $verdict['reason'] === $expect_reason ? 'PASS' : 'FAIL(' . $verdict['reason'] . ')' );
}
WP_CLI::line( 'REFUND_PREFLIGHT=' . implode( '|', $results ) );

/* A missing order is refused before anything else is read. */
WP_CLI::line( 'REFUND_ORDER_MISSING=' . ( false === $guard::preflight( null, $row(), array( '900123' ) )['allowed'] ? 'blocked' : 'ALLOWED' ) );

/*
 * The stale-newest-row scenario that produced refund #762: an older healthy row
 * exists, but the row the gateway would actually take carries a NULL payment
 * id. The guard must look at the same newest row and refuse.
 */
$older_healthy = $row();
$newest_broken = $row( array( 'payment_id' => null ) );
WP_CLI::line( 'REFUND_STALE_LATEST_ROW=' . (
	true === $guard::preflight( $order, $older_healthy, array( '900123' ) )['allowed']
	&& false === $guard::preflight( $order, $newest_broken, array( '900123' ) )['allowed']
		? 'older-ok-but-latest-blocked'
		: 'UNSAFE'
) );

/* Hook shape: guarded before save, manual refunds and other gateways skipped. */
$source = (string) file_get_contents( WP_PLUGIN_DIR . '/kuka-island-core/includes/class-iyzico-refund-guard.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$shape  = str_contains( $source, "add_action( 'woocommerce_create_refund'" )
	&& str_contains( $source, "if ( empty( \$args['refund_payment'] ) ) {" )
	&& str_contains( $source, "self::GATEWAY_ID !== \$order->get_payment_method()" )
	&& str_contains( $source, 'throw new Exception' );
WP_CLI::line( 'REFUND_GUARD_SHAPE=' . ( $shape ? 'before-save|manual-skipped|other-gateways-skipped' : 'UNSAFE' ) );

/* The newest row is selected with the gateway's own ordering. */
WP_CLI::line( 'REFUND_ROW_SELECTION=' . ( str_contains( $source, 'ORDER BY iyzico_order_id DESC LIMIT 1' ) ? 'matches-vendor' : 'DIVERGES' ) );

/* Nothing sensitive may be rendered or thrown. */
$leaks = 0;
foreach ( array( 'payment_id', 'conversation_id', 'token', 'secret_key', 'api_key' ) as $needle ) {
	$leaks += preg_match( '/(echo|esc_html\(|Exception\()[^;]*\$?' . $needle . '/', $source );
}
WP_CLI::line( 'REFUND_GUARD_LEAKS=' . $leaks );

/* Read-only guarantee: nothing above changed a single row. */
$after = array(
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	'orders'   => (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}wc_orders" ),
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	'refunds'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order_refund'" ),
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	'provider' => (array) $wpdb->get_col( "SELECT iyzico_order_id FROM {$wpdb->prefix}iyzico_order" ),
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	'notes'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'order_note'" ),
);
sort( $before['orders'] );
sort( $after['orders'] );
sort( $before['provider'] );
sort( $after['provider'] );
WP_CLI::line( sprintf(
	'REFUND_TEST_SIDE_EFFECTS=orders:%s|refunds:%d→%d|provider:%s|notes:%d→%d',
	$before['orders'] === $after['orders'] ? 'same' : 'CHANGED',
	$before['refunds'],
	$after['refunds'],
	$before['provider'] === $after['provider'] ? 'same' : 'CHANGED',
	$before['notes'],
	$after['notes']
) );
