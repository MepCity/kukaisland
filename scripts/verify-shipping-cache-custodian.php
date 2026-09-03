<?php
/**
 * Measure the cache custodian in its own processes: normal, exit and fatal.
 *
 * Three properties, and none of them can be measured inside
 * verify-shipping-automation.php: that file's own cleanup is the thing under
 * test, and a run that dies takes the measurement with it.
 *
 *   THE SHOP'S ROWS ARE NEVER TOUCHED. The run works in a key namespace of its
 *   own, so the production rows are not read, not written and not deleted. They
 *   are read here only to prove they are byte-identical afterwards.
 *
 *   AN UNDECLARED ROW SURVIVES. A row that appears DURING the run and was never
 *   declared by it belongs to somebody else -- a concurrent real request, for
 *   instance. The previous custodian deleted exactly this row, having decided
 *   ownership by subtraction: "not in my snapshot, therefore mine".
 *
 *   THE RUN'S OWN ROWS GO, however the process ends. The release is registered
 *   as a shutdown function at construction and the normal path calls the same
 *   idempotent method.
 *
 * Usage: wp eval-file <this> <phase> [namespace] [expected-foreign-fingerprint]
 *
 * Positional arguments, because wp eval-file rejects flags it does not know.
 *
 *   seed     Plant a shop-owned row and print the foreign fingerprint.
 *   normal   Run, dirty the run's own namespace, release cleanly, report.
 *   exit     Same, but leave through WP_CLI::error() without releasing.
 *   fatal    Same, but leave through a call to a function that does not exist.
 *   check    Compare everything against the seed's fingerprint.
 *   cleanup  Remove this script's own sentinels, by exact name.
 *
 * There is no wildcard delete in this file. No carrier is contacted and no
 * credential is read.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || exit( 1 );

require_once __DIR__ . '/lib-shipping-cache-custodian.php';

/** The row that stands in for the shop's own cached city list. */
const KUKA_CUSTODIAN_SHOP_TRANSIENT = 'kuka_dhl_cbs_cities_v1';
const KUKA_CUSTODIAN_SHOP_CITY      = 'KUKA-CUSTODIAN-SHOP-CITY';

/** The row another process creates while the run is going. */
const KUKA_CUSTODIAN_FOREIGN_TRANSIENT = 'kuka_dhl_cbs_districts_v1_81';
const KUKA_CUSTODIAN_FOREIGN_DISTRICT  = 'KUKA-CUSTODIAN-CONCURRENT-DISTRICT';

$kuka_custodian_args  = array_values( (array) ( $args ?? array() ) );
$kuka_custodian_phase = (string) ( $kuka_custodian_args[0] ?? 'check' );
$kuka_custodian_ns    = (string) ( $kuka_custodian_args[1] ?? '' );
$kuka_custodian_want  = (string) ( $kuka_custodian_args[2] ?? '' );

/** The value the shop's row holds. */
function kuka_custodian_shop_value(): array {
	return array(
		array(
			'code' => '99',
			'name' => KUKA_CUSTODIAN_SHOP_CITY,
		),
	);
}

/** Exact option names, both rows, for one transient. */
function kuka_custodian_option_names( string $transient ): array {
	return array( '_transient_' . $transient, '_transient_timeout_' . $transient );
}

/** Every cache row that is not in the given namespace. */
function kuka_custodian_foreign_rows( string $namespace ): array {
	$foreign = array();

	foreach ( Kuka_Shipping_Cache_Custodian::rows() as $name => $row ) {
		if ( '' !== $namespace && str_contains( (string) $name, $namespace ) ) {
			continue;
		}

		$foreign[ (string) $name ] = $row;
	}

	return $foreign;
}

if ( 'seed' === $kuka_custodian_phase ) {
	set_transient( KUKA_CUSTODIAN_SHOP_TRANSIENT, kuka_custodian_shop_value(), DAY_IN_SECONDS );

	$rows = kuka_custodian_foreign_rows( '' );

	WP_CLI::line(
		sprintf(
			'CUSTODIAN_SEED=ok|foreign_rows:%d|foreign_fingerprint:%s',
			count( $rows ),
			Kuka_Shipping_Cache_Custodian::fingerprint( $rows )
		)
	);

	return;
}

if ( in_array( $kuka_custodian_phase, array( 'normal', 'exit', 'fatal' ), true ) ) {
	if ( '' === $kuka_custodian_ns ) {
		WP_CLI::error( 'CUSTODIAN=FAIL|reason:namespace_required' );
	}

	$custodian = ( new Kuka_Shipping_Cache_Custodian( $kuka_custodian_ns ) )
		->own_resolver_keys( array( '34', '06' ) )
		->guard();

	WP_CLI::line(
		sprintf(
			'CUSTODIAN_RUN=starting|phase:%s|namespace:%s|declared:%d',
			$kuka_custodian_phase,
			$custodian->namespace_key(),
			count( $custodian->owned_names() )
		)
	);

	// Exactly what a scenario does, in the run's own namespace.
	set_transient( 'kuka_dhl_cbs_cities_' . $kuka_custodian_ns, array( array( 'code' => '34', 'name' => 'MOCK-ONLY' ) ), DAY_IN_SECONDS );
	set_transient( 'kuka_dhl_cbs_districts_' . $kuka_custodian_ns . '_34', array( array( 'code' => '1', 'name' => 'MOCK-ONLY' ) ), DAY_IN_SECONDS );

	// And a row nobody declared, appearing mid-run: another process's business.
	set_transient(
		KUKA_CUSTODIAN_FOREIGN_TRANSIENT,
		array(
			array(
				'code' => '1',
				'name' => KUKA_CUSTODIAN_FOREIGN_DISTRICT,
			),
		),
		DAY_IN_SECONDS
	);

	WP_CLI::line( 'CUSTODIAN_RUN=dirtied|run_rows_added:yes|undeclared_row_added:yes' );

	if ( 'fatal' === $kuka_custodian_phase ) {
		// An uncatchable error, on purpose: shutdown functions still run.
		kuka_custodian_this_function_does_not_exist();
	}

	if ( 'exit' === $kuka_custodian_phase ) {
		WP_CLI::error( 'CUSTODIAN_RUN=exited_on_purpose' );
	}

	$outcome = $custodian->release( 'normal' );

	WP_CLI::line(
		sprintf(
			'CUSTODIAN_RUN=released|ok:%s|owned_declared:%d|owned_removed:%d|foreign_preserved:%d|foreign_changed:%d|refused:%d|invoked_by:%s',
			$outcome['ok'] ? 'yes' : 'no',
			(int) $outcome['owned_declared'],
			(int) $outcome['owned_removed'],
			(int) $outcome['foreign_preserved'],
			(int) $outcome['foreign_changed'],
			(int) $outcome['refused'],
			(string) $outcome['invoked_by']
		)
	);

	return;
}

if ( 'check' === $kuka_custodian_phase ) {
	$foreign     = kuka_custodian_foreign_rows( $kuka_custodian_ns );
	$fingerprint = Kuka_Shipping_Cache_Custodian::fingerprint( $foreign );

	$shop_value_ok = kuka_custodian_shop_value() === get_transient( KUKA_CUSTODIAN_SHOP_TRANSIENT );

	$undeclared = get_transient( KUKA_CUSTODIAN_FOREIGN_TRANSIENT );
	$undeclared_ok = is_array( $undeclared )
		&& KUKA_CUSTODIAN_FOREIGN_DISTRICT === (string) ( $undeclared[0]['name'] ?? '' );

	$run_rows_left = 0;
	foreach ( array_keys( Kuka_Shipping_Cache_Custodian::rows() ) as $name ) {
		if ( '' !== $kuka_custodian_ns && str_contains( (string) $name, $kuka_custodian_ns ) ) {
			++$run_rows_left;
		}
	}

	// The seed's fingerprint covered the shop row only; the undeclared row was
	// added afterwards, so it is compared on its own rather than folded in.
	$expected_ok = '' === $kuka_custodian_want
		|| $kuka_custodian_want === Kuka_Shipping_Cache_Custodian::fingerprint(
			array_diff_key( $foreign, array_flip( kuka_custodian_option_names( KUKA_CUSTODIAN_FOREIGN_TRANSIENT ) ) )
		);

	WP_CLI::line(
		sprintf(
			'CUSTODIAN_CHECK=%s|foreign_rows:%d|shop_rows_fingerprint_match:%s|shop_value_intact:%s|undeclared_row_preserved:%s|run_rows_left:%d|fingerprint:%s',
			( $expected_ok && $shop_value_ok && $undeclared_ok && 0 === $run_rows_left ) ? 'PASS' : 'FAIL',
			count( $foreign ),
			$expected_ok ? 'yes' : 'NO',
			$shop_value_ok ? 'yes' : 'NO',
			$undeclared_ok ? 'yes' : 'NO',
			$run_rows_left,
			$fingerprint
		)
	);

	return;
}

if ( 'cleanup' === $kuka_custodian_phase ) {
	global $wpdb;

	$removed = 0;
	$names   = array_merge(
		kuka_custodian_option_names( KUKA_CUSTODIAN_SHOP_TRANSIENT ),
		kuka_custodian_option_names( KUKA_CUSTODIAN_FOREIGN_TRANSIENT )
	);

	foreach ( $names as $name ) {
		// One delete, one exact name. No pattern, ever.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false !== $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ) ) {
			++$removed;
		}

		wp_cache_delete( $name, 'options' );
	}

	wp_cache_delete( 'alloptions', 'options' );

	WP_CLI::line(
		sprintf(
			'CUSTODIAN_CLEANUP=ok|sentinels_removed_by_exact_name:%d|rows_left:%d',
			$removed,
			count( Kuka_Shipping_Cache_Custodian::rows() )
		)
	);

	return;
}

WP_CLI::error( 'CUSTODIAN=FAIL|reason:unknown_phase:' . $kuka_custodian_phase );
