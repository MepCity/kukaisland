<?php
/**
 * Billing-panel field contract, proved on run-owned fixtures.
 *
 * The rule under test: a customer's own details live in WooCommerce's Billing
 * panel and survive our plugin untouched. Nothing of ours rewrites, strips or
 * relocates them.
 *
 * This used to be asserted against two hard-coded local order IDs (#297 and
 * #125), which made the check unrunnable on a clean database and turned two
 * accidental sandbox rows into a universal contract. The behaviour is now proved
 * on fixtures this run creates, owns and removes, so the result is identical on
 * a clean CI database and on the local one.
 *
 * Fixture discipline mirrors scripts/verify-invoice-integration.php: every
 * fixture carries an isolation run ID so it stays discoverable from the database
 * after a fatal error, cleanup is a four-state machine with ownership checks,
 * and the twelve-table keyset is compared before and after.
 *
 * This script is also the portable companion to the read-only snapshot in
 * scripts/verify-order-experience.php: it proves all three branches of the
 * protected-order verdict, including the clean-database branch that cannot be
 * reproduced against the developer database.
 *
 * Run with:
 * docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-order-billing-panel.php
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

define( 'KUKA_INVOICE_KEYSET_LIBRARY_ONLY', true );
require_once __DIR__ . '/verify-invoice-keyset.php';
require_once __DIR__ . '/lib-protected-orders.php';

// No mail during fixture work.
add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );

const KUKA_BILLING_RUN_META = '_kuka_isolation_run_id';

$failures = array();
$report   = static function ( string $name, bool $passed, string $detail = '' ) use ( &$failures ): void {
	WP_CLI::line( sprintf( '%s=%s%s', $name, $passed ? 'PASS' : 'FAIL', '' !== $detail ? '|' . $detail : '' ) );
	if ( ! $passed ) {
		$failures[] = $name;
	}
};

$pre_keysets  = kuka_invoice_capture_keysets();
$billing_run  = wp_generate_uuid4();
$GLOBALS['kuka_billing_cleanup_state'] = 'idle';

/**
 * Discover this run's fixtures straight from the database.
 *
 * @param string $run_id Isolation run ID.
 * @return array<int, int>
 */
function kuka_billing_discover( string $run_id ): array {
	global $wpdb;

	$ids   = array();
	$table = $wpdb->prefix . 'wc_orders_meta';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$ids = array_merge(
			$ids,
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT order_id FROM ' . $table . ' WHERE meta_key = %s AND meta_value = %s',
					KUKA_BILLING_RUN_META,
					$run_id
				)
			)
		);
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = array_merge(
		$ids,
		(array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				KUKA_BILLING_RUN_META,
				$run_id
			)
		)
	);

	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	sort( $ids );

	return $ids;
}

/**
 * Delete one fixture after strict run-ID ownership validation.
 */
function kuka_billing_delete( int $order_id, string $expected_run_id ): bool {
	if ( $order_id <= 0 ) {
		return true;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return true;
	}
	$run_id = (string) $order->get_meta( KUKA_BILLING_RUN_META, true );
	if ( '' === $run_id || $run_id !== $expected_run_id ) {
		WP_CLI::warning( sprintf( 'Ownership refusal: order #%d is not owned by this run. Refusing cleanup.', $order_id ) );
		return false;
	}
	foreach ( wc_get_order_notes( array( 'order_id' => $order_id ) ) as $note ) {
		wp_delete_comment( $note->id, true );
	}
	$order->delete( true );

	return true;
}

/**
 * Four-state cleanup coordinator with a re-entry guard.
 *
 * @return array{state: string, refused: array<int, int>, leftover: array<int, int>, considered: int}
 */
function kuka_billing_cleanup( string $run_id ): array {
	$current = (string) ( $GLOBALS['kuka_billing_cleanup_state'] ?? 'idle' );
	if ( 'idle' !== $current ) {
		return array(
			'state'      => $current,
			'refused'    => array(),
			'leftover'   => array(),
			'considered' => 0,
		);
	}
	$GLOBALS['kuka_billing_cleanup_state'] = 'running';

	$ids     = kuka_billing_discover( $run_id );
	$refused = array();
	foreach ( $ids as $order_id ) {
		if ( ! kuka_billing_delete( $order_id, $run_id ) ) {
			$refused[] = $order_id;
		}
	}
	$leftover = kuka_billing_discover( $run_id );

	$GLOBALS['kuka_billing_cleanup_state'] = ( empty( $refused ) && empty( $leftover ) ) ? 'succeeded' : 'failed';

	return array(
		'state'      => $GLOBALS['kuka_billing_cleanup_state'],
		'refused'    => $refused,
		'leftover'   => $leftover,
		'considered' => count( $ids ),
	);
}

// Fatal-error safety net.
register_shutdown_function(
	static function () use ( $billing_run ): void {
		if ( 'idle' === (string) ( $GLOBALS['kuka_billing_cleanup_state'] ?? 'idle' ) ) {
			$result = kuka_billing_cleanup( $billing_run );
			if ( 'succeeded' !== $result['state'] ) {
				WP_CLI::warning( sprintf( 'Billing fixture cleanup ended in state "%s".', $result['state'] ) );
				exit( 1 );
			}
		}
	}
);

/**
 * Create a run-owned fixture with the given billing values.
 *
 * @param string                $run_id Isolation run ID.
 * @param array<string, string> $billing Billing values to write.
 */
function kuka_billing_fixture( string $run_id, array $billing ): WC_Order {
	$order = wc_create_order();
	$order->update_meta_data( KUKA_BILLING_RUN_META, $run_id );
	$order->set_billing_first_name( $billing['first_name'] );
	$order->set_billing_last_name( $billing['last_name'] );
	$order->set_billing_email( $billing['email'] );
	$order->set_billing_phone( $billing['phone'] );
	$order->set_billing_address_1( $billing['address_1'] );
	$order->set_billing_city( $billing['city'] );
	$order->save();

	return $order;
}

/* ========================================================================== */
/* Fixtures: one complete customer, one without a phone number                 */
/* ========================================================================== */

$cases = array(
	'full'     => array(
		'first_name' => 'Deniz',
		'last_name'  => 'Yılmaz Öztürk',
		'email'      => 'deniz.fixture@example.com',
		'phone'      => '+90 555 000 00 00',
		'address_1'  => 'Caferağa Mah. Moda Cad. No:1 D:2',
		'city'       => 'İstanbul',
	),
	'no_phone' => array(
		'first_name' => 'Ayşe',
		'last_name'  => 'Şahin',
		'email'      => 'ayse.fixture@example.com',
		'phone'      => '',
		'address_1'  => 'Bağdat Cad. No:3',
		'city'       => 'İstanbul',
	),
);

$field_report = array();
$roundtrip    = array();
$created      = 0;

foreach ( $cases as $case => $billing ) {
	$order = kuka_billing_fixture( $billing_run, $billing );
	++$created;

	// Re-read from the database, not from the object we just wrote, so any
	// filter or store that mangles a billing field is caught.
	$fresh = wc_get_order( $order->get_id() );

	$field_report[] = sprintf(
		'%s:first:%s,last:%s,email:%s,phone:%s',
		$case,
		'' !== $fresh->get_billing_first_name() ? 'set' : 'EMPTY',
		'' !== $fresh->get_billing_last_name() ? 'set' : 'EMPTY',
		'' !== $fresh->get_billing_email() ? 'set' : 'EMPTY',
		'' !== $fresh->get_billing_phone() ? 'set' : 'empty'
	);

	$actual = array(
		'first_name' => $fresh->get_billing_first_name(),
		'last_name'  => $fresh->get_billing_last_name(),
		'email'      => $fresh->get_billing_email(),
		'phone'      => $fresh->get_billing_phone(),
		'address_1'  => $fresh->get_billing_address_1(),
		'city'       => $fresh->get_billing_city(),
	);
	foreach ( $actual as $field => $value ) {
		$roundtrip[] = array(
			'case'     => $case,
			'field'    => $field,
			'expected' => $billing[ $field ],
			'actual'   => $value,
			'exact'    => $billing[ $field ] === $value,
		);
	}
}

// The public contract line keeps its name and its per-case shape, but the cases
// are now fixtures rather than two accidental local order IDs.
WP_CLI::line( 'ORDER_BILLING_FIELDS=' . implode( '|', $field_report ) );

$expected_shape = 'full:first:set,last:set,email:set,phone:set|no_phone:first:set,last:set,email:set,phone:empty';
$report(
	'ORDER_BILLING_FIELD_PRESENCE',
	$expected_shape === implode( '|', $field_report ),
	sprintf( 'cases:%d|observed:%s', count( $cases ), implode( '|', $field_report ) )
);

$mismatches = array_values(
	array_filter(
		$roundtrip,
		static fn( array $row ): bool => ! $row['exact']
	)
);
$report(
	'ORDER_BILLING_ROUNDTRIP',
	empty( $mismatches ),
	sprintf(
		'fields:%d|mismatches:%s',
		count( $roundtrip ),
		empty( $mismatches )
			? 'none'
			: implode( ',', array_map( static fn( array $row ): string => $row['case'] . '.' . $row['field'], $mismatches ) )
	)
);

/* ========================================================================== */
/* Cleanup and isolation                                                       */
/* ========================================================================== */

$discovered = count( kuka_billing_discover( $billing_run ) );
$cleanup    = kuka_billing_cleanup( $billing_run );
$reentry    = kuka_billing_cleanup( $billing_run );

$report(
	'ORDER_BILLING_FIXTURE_CLEANUP',
	'succeeded' === $cleanup['state']
	&& empty( $cleanup['refused'] )
	&& empty( $cleanup['leftover'] )
	&& $created === $discovered
	&& 'succeeded' === $reentry['state'],
	sprintf(
		'state:%s|created:%d|db_discoverable:%d|refused:%d|leftover:%d|reentry_blocked:%s',
		$cleanup['state'],
		$created,
		$discovered,
		count( $cleanup['refused'] ),
		count( $cleanup['leftover'] ),
		'succeeded' === $reentry['state'] && 0 === $reentry['considered'] ? 'yes' : 'no'
	)
);

$post_keysets = kuka_invoice_capture_keysets();
$table_diff   = array();
foreach ( $pre_keysets['tables'] as $table_key => $pre_rows ) {
	$post_rows = $post_keysets['tables'][ $table_key ] ?? array();
	if ( $pre_rows !== $post_rows ) {
		$table_diff[] = sprintf( '%s(%d->%d)', $table_key, count( $pre_rows ), count( $post_rows ) );
	}
}

$report(
	'ORDER_BILLING_DB_ISOLATION',
	$pre_keysets['hash'] === $post_keysets['hash'] && empty( $table_diff ),
	sprintf(
		'tables:%d|pre_hash:%s|post_hash:%s|diff:%s',
		count( $pre_keysets['tables'] ),
		substr( $pre_keysets['hash'], 0, 12 ),
		substr( $post_keysets['hash'], 0, 12 ),
		empty( $table_diff ) ? 'none' : implode( ',', $table_diff )
	)
);

/* ========================================================================== */
/* Protected-order verdict: all three branches, proved with fixtures            */
/* ========================================================================== */

$snapshot = array(
	11 => 'processing/100',
	12 => 'cancelled/200',
	13 => 'completed/300',
);

$verdict_cases = array(
	'all_present_unchanged' => array(
		'observed' => array(
			11 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'processing/100' ),
			12 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'cancelled/200' ),
			13 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'completed/300' ),
		),
		'expect'   => 'verified',
	),
	'clean_database'        => array(
		'observed' => array(),
		'expect'   => 'not_applicable',
	),
	'all_absent_explicit'   => array(
		'observed' => array(
			11 => array( 'exists' => false ),
			12 => array( 'exists' => false ),
			13 => array( 'exists' => false ),
		),
		'expect'   => 'not_applicable',
	),
	'one_signature_changed' => array(
		'observed' => array(
			11 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'processing/100' ),
			12 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'cancelled/999' ),
			13 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'completed/300' ),
		),
		'expect'   => 'DRIFT',
	),
	'partial_presence'      => array(
		'observed' => array(
			11 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'processing/100' ),
			12 => array( 'exists' => false ),
			13 => array( 'exists' => false ),
		),
		'expect'   => 'DRIFT',
	),
	'one_deleted'           => array(
		'observed' => array(
			11 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'processing/100' ),
			12 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'cancelled/200' ),
			13 => array( 'exists' => false ),
		),
		'expect'   => 'DRIFT',
	),
	'ids_held_by_fixtures'  => array(
		// A clean database where another verification script's fixtures happen to
		// occupy these IDs: still not_applicable, never drift.
		'observed' => array(
			11 => array( 'exists' => true, 'is_fixture' => true, 'signature' => 'pending/0' ),
			12 => array( 'exists' => true, 'is_fixture' => true, 'signature' => 'pending/0' ),
			13 => array( 'exists' => true, 'is_fixture' => true, 'signature' => 'pending/0' ),
		),
		'expect'   => 'not_applicable',
	),
	'fixture_plus_real'     => array(
		'observed' => array(
			11 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'processing/100' ),
			12 => array( 'exists' => true, 'is_fixture' => true, 'signature' => 'pending/0' ),
			13 => array( 'exists' => true, 'is_fixture' => false, 'signature' => 'completed/300' ),
		),
		'expect'   => 'DRIFT',
	),
);

$verdict_ok      = true;
$verdict_details = array();
foreach ( $verdict_cases as $case => $spec ) {
	$result = kuka_protected_orders_verdict( $snapshot, $spec['observed'] );
	$hit    = $result['state'] === $spec['expect'];
	$verdict_details[ $case ] = $hit ? $result['state'] : ( 'MISMATCH(' . $result['state'] . ')' );
	if ( ! $hit ) {
		$verdict_ok = false;
	}
}

// The clean-database line is exactly what a fresh CI run must emit.
$ci_line       = kuka_protected_orders_verdict( $snapshot, array() )['line'];
$ci_line_shape = 'PROTECTED_ORDERS=not_applicable|present:0/3|matching:0|drifted:0|absent:3|reason:clean_database_without_local_sandbox_orders';

$report(
	'PROTECTED_ORDERS_VERDICT_MATRIX',
	$verdict_ok && $ci_line === $ci_line_shape,
	sprintf(
		'cases:%d|%s|clean_line_shape:%s',
		count( $verdict_cases ),
		implode( ' ', array_map( static fn( string $k, string $v ): string => $k . '=' . $v, array_keys( $verdict_details ), $verdict_details ) ),
		$ci_line === $ci_line_shape ? 'ok' : 'MISMATCH'
	)
);

if ( ! empty( $failures ) ) {
	WP_CLI::error( sprintf( 'Billing panel contract failed (%d: %s).', count( $failures ), implode( ', ', $failures ) ) );
}

WP_CLI::success( 'Billing panel contract verified on run-owned fixtures.' );
