<?php
/**
 * Behavioural test for the automatic iyzico refund guard.
 *
 * Creates run-owned fixture orders, drives the real `wc_create_refund()` path
 * and proves that a refund the gateway could not honour never reaches the
 * database. No real refund is requested: every automatic case is built so the
 * guard blocks it before the gateway is called, and the manual case never
 * involves the gateway at all.
 *
 * Run with:
 * docker compose run --rm -T wp-cli wp eval-file /project-scripts/test-iyzico-refund-guard.php
 */

defined( 'WP_CLI' ) || exit( 1 );

require_once __DIR__ . '/lib-iyzico-test-ownership.php';

global $wpdb;
$guard          = 'Kuka_Island_Core_Iyzico_Refund_Guard';
$provider_table = $wpdb->prefix . 'iyzico_order';
$run_id         = wp_generate_uuid4();
$created_orders = array();
$created_rows   = array();
$failures       = 0;

$fixture_id = (int) wc_get_product_id_by_sku( 'KUKA-SANDBOX-IYZ-FIXTURE' );
if ( $fixture_id <= 0 ) {
	WP_CLI::line( 'REFUND_BEHAVIOUR=FAIL (fixture product missing; run `make seed`)' );
	WP_CLI::halt( 1 );
}

$snapshot = static function () use ( $wpdb, $provider_table ): array {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$orders = (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}wc_orders" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = (array) $wpdb->get_col( "SELECT iyzico_order_id FROM {$provider_table}" );
	sort( $orders );
	sort( $rows );
	return array(
		'orders'   => $orders,
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		'refunds'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order_refund'" ),
		'provider' => $rows,
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		'notes'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'order_note'" ),
	);
};

$baseline = $snapshot();

$check = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	WP_CLI::line( sprintf( '%s %s%s', $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? ' | ' . $detail : '' ) );
	if ( ! $ok ) {
		++$failures;
	}
};

$make_order = static function ( string $case, string $method, ?string $payment_id, string $status, ?string $payment_status ) use ( $wpdb, $provider_table, $fixture_id, $run_id, &$created_orders, &$created_rows ): array {
	$order = wc_create_order();
	$order->add_product( wc_get_product( $fixture_id ), 1 );
	$order->set_billing_first_name( 'Sandbox' );
	$order->set_billing_email( 'sandbox-refund+' . substr( $run_id, 0, 8 ) . '@example.com' );
	$order->set_payment_method( $method );
	$order->calculate_totals();
	$order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );
	$order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
	$order->update_meta_data( '_kuka_sandbox_case', $case );
	$order->set_status( 'processing' );
	$order->save();
	$created_orders[] = (int) $order->get_id();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->insert(
		$provider_table,
		array(
			'payment_id'      => $payment_id,
			'order_id'        => $order->get_id(),
			'conversation_id' => 'refund-' . $order->get_id(),
			'token'           => wp_generate_uuid4(),
			'total_amount'    => $order->get_total(),
			'status'          => $status,
			'payment_status'  => $payment_status,
		),
		array( '%s', '%d', '%s', '%s', '%f', '%s', '%s' )
	);
	$created_rows[] = array( 'row_id' => (int) $wpdb->insert_id, 'order_id' => (int) $order->get_id() );

	return array( 'order_id' => (int) $order->get_id(), 'total' => (float) $order->get_total() );
};

$attempt_refund = static function ( int $order_id, float $amount, bool $refund_payment ) {
	return wc_create_refund(
		array(
			'order_id'       => $order_id,
			'amount'         => $amount,
			'reason'         => 'sandbox refund guard test',
			'refund_payment' => $refund_payment,
			'restock_items'  => false,
		)
	);
};

$blocked_message = $guard::BLOCKED_MESSAGE;

/* 1. Automatic refund on the exact #762 shape: newest row without a payment id. */
$case = $make_order( 'null-payment-id', 'iyzico', null, 'success', 'SUCCESS' );
$before = $snapshot();
$result = $attempt_refund( $case['order_id'], 10.0, true );
$after  = $snapshot();
$check(
	'1. payment_id NULL → otomatik iade engellendi, iade kaydı 0',
	is_wp_error( $result ) && $blocked_message === $result->get_error_message() && $after['refunds'] === $before['refunds'],
	'refunds:' . $before['refunds'] . '→' . $after['refunds']
);

/* 2. Manual refund on the same order is untouched. */
$before = $snapshot();
$manual = $attempt_refund( $case['order_id'], 10.0, false );
$after  = $snapshot();
$manual_ok = $manual instanceof WC_Order_Refund;
if ( $manual_ok ) {
	$created_orders[] = (int) $manual->get_id();
}
$check(
	'2. manuel iade (refund_payment=false) etkilenmiyor',
	$manual_ok && $after['refunds'] === $before['refunds'] + 1,
	'refunds:' . $before['refunds'] . '→' . $after['refunds']
);

/* 3. Another gateway is not blocked by this guard. */
$other  = $make_order( 'other-gateway', 'bacs', null, 'success', 'SUCCESS' );
$before = $snapshot();
$result = $attempt_refund( $other['order_id'], 10.0, true );
$after  = $snapshot();
$check(
	'3. başka ödeme yöntemi bu guard tarafından engellenmiyor',
	! ( is_wp_error( $result ) && $blocked_message === $result->get_error_message() ) && $after['refunds'] === $before['refunds'],
	is_wp_error( $result ) ? 'woo_error' : 'refund_created'
);

/* 4. Provider status failure. */
$case = $make_order( 'status-failure', 'iyzico', '900123', 'failure', 'SUCCESS' );
$before = $snapshot();
$result = $attempt_refund( $case['order_id'], 10.0, true );
$after  = $snapshot();
$check( '4. provider status failure → engellendi', is_wp_error( $result ) && $blocked_message === $result->get_error_message() && $after['refunds'] === $before['refunds'] );

/* 5. Payment status FAILURE. */
$case = $make_order( 'payment-status-failure', 'iyzico', '900123', 'success', 'FAILURE' );
$before = $snapshot();
$result = $attempt_refund( $case['order_id'], 10.0, true );
$after  = $snapshot();
$check( '5. payment_status FAILURE → engellendi', is_wp_error( $result ) && $blocked_message === $result->get_error_message() && $after['refunds'] === $before['refunds'] );

/* 6. A fully formed row whose payment id was never verified. */
$case = $make_order( 'unverified-payment-id', 'iyzico', '900123', 'success', 'SUCCESS' );
$before = $snapshot();
$result = $attempt_refund( $case['order_id'], 10.0, true );
$after  = $snapshot();
$check( '6. doğrulanmamış payment id → engellendi', is_wp_error( $result ) && $blocked_message === $result->get_error_message() && $after['refunds'] === $before['refunds'] );

/* 7. Older healthy row, newest row broken — the gateway would take the newest. */
$case = $make_order( 'stale-latest-row', 'iyzico', '900123', 'success', 'SUCCESS' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->insert(
	$provider_table,
	array(
		'payment_id'      => null,
		'order_id'        => $case['order_id'],
		'conversation_id' => 'refund-late-' . $case['order_id'],
		'token'           => wp_generate_uuid4(),
		'total_amount'    => $case['total'],
		'status'          => 'success',
		'payment_status'  => null,
	),
	array( '%s', '%d', '%s', '%s', '%f', '%s', '%s' )
);
$created_rows[] = array( 'row_id' => (int) $wpdb->insert_id, 'order_id' => (int) $case['order_id'] );
$before = $snapshot();
$result = $attempt_refund( $case['order_id'], 10.0, true );
$after  = $snapshot();
$check( '7. en yeni satır bozuk, eski satır geçerli → engellendi', is_wp_error( $result ) && $blocked_message === $result->get_error_message() && $after['refunds'] === $before['refunds'] );

/* 8. No sensitive value may appear in the error message. */
$leak = 0;
foreach ( array( '900123', 'refund-' ) as $needle ) {
	$leak += is_wp_error( $result ) && str_contains( $result->get_error_message(), $needle ) ? 1 : 0;
}
$check( '8. hata mesajında hassas veri yok', 0 === $leak );

/* Teardown: run-owned only. */
$refusals = array();
foreach ( $created_rows as $record ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $provider_table, array( 'iyzico_order_id' => (int) $record['row_id'] ), array( '%d' ) );
	if ( 1 !== (int) $wpdb->rows_affected ) {
		$refusals[] = 'provider#' . $record['row_id'] . ':affected_rows_' . (int) $wpdb->rows_affected;
	}
}
foreach ( array_reverse( array_unique( array_map( 'intval', $created_orders ) ) ) as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order instanceof WC_Order_Refund ) {
		// A refund this run created; its parent is verified below.
		$order->delete( true );
		continue;
	}
	$reason = '';
	if ( ! kuka_iyzico_fixture_is_owned( $order_id, $run_id, $created_orders, $reason ) ) {
		$refusals[] = 'order#' . $order_id . ':' . $reason;
		continue;
	}
	kuka_iyzico_delete_owned_order( $order_id );
}

wp_cache_flush();
$final = $snapshot();
$check( 'Temizlik hiçbir hedefi doğrulayamadan silmedi', empty( $refusals ), $refusals ? implode( ' | ', $refusals ) : 'reddedilen yok' );
WP_CLI::line( sprintf(
	'REFUND_BEHAVIOUR_STATE=orders:%s|refunds:%d→%d|provider:%s|notes:%d→%d',
	$baseline['orders'] === $final['orders'] ? 'same' : 'CHANGED',
	$baseline['refunds'],
	$final['refunds'],
	$baseline['provider'] === $final['provider'] ? 'same' : 'CHANGED',
	$baseline['notes'],
	$final['notes']
) );
$check( 'Koşu öncesi kalıcı durum birebir geri geldi', $baseline === $final );

WP_CLI::line( 0 === $failures ? 'REFUND_BEHAVIOUR=PASS' : 'REFUND_BEHAVIOUR=FAIL (' . $failures . ')' );
if ( 0 !== $failures ) {
	WP_CLI::halt( 1 );
}
