<?php
/**
 * Measure the cache custodian's SHUTDOWN path, in its own process.
 *
 * The normal path is measured inside verify-shipping-automation.php. This
 * script exists for the path that cannot be measured there: a run that never
 * reaches its cleanup because it exited or crashed. That has to happen in a
 * separate process, because the measurement is "the process is gone and the
 * shop's rows are intact".
 *
 * Usage: wp eval-file <this> <phase> [death] [expected-fingerprint]
 *
 * Positional arguments, because wp eval-file rejects flags it does not know.
 *
 * Phases, driven by verify-shipping-cache-custodian.sh:
 *
 *   seed    Write a sentinel cache row -- value and timeout -- and print its
 *           fingerprint. This stands in for the shop's own cached city list.
 *   crash   Snapshot with the custodian, register the shutdown guard, overwrite
 *           the cache the way a real scenario does, then die. death 'exit' uses
 *           WP_CLI::error(); death 'fatal' calls a function that does not exist.
 *   check   Compare the cache against the fingerprint the seed printed.
 *   cleanup Remove the sentinel this script planted.
 *
 * No carrier is contacted and no credential is read at any point.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || exit( 1 );

require_once __DIR__ . '/lib-shipping-cache-custodian.php';

const KUKA_CUSTODIAN_SENTINEL_KEY  = 'kuka_dhl_cbs_cities_v1';
const KUKA_CUSTODIAN_SENTINEL_CITY = 'KUKA-CUSTODIAN-SENTINEL-CITY';

$kuka_custodian_args  = array_values( (array) ( $args ?? array() ) );
$kuka_custodian_phase = (string) ( $kuka_custodian_args[0] ?? 'check' );
$kuka_custodian_death = (string) ( $kuka_custodian_args[1] ?? 'exit' );
$kuka_custodian_want  = (string) ( $kuka_custodian_args[2] ?? '' );

/** The sentinel value a shop's own cached city list stands in for. */
function kuka_custodian_sentinel_value(): array {
	return array(
		array(
			'code' => '99',
			'name' => KUKA_CUSTODIAN_SENTINEL_CITY,
		),
	);
}

if ( 'seed' === $kuka_custodian_phase ) {
	set_transient( KUKA_CUSTODIAN_SENTINEL_KEY, kuka_custodian_sentinel_value(), DAY_IN_SECONDS );

	$rows = Kuka_Shipping_Cache_Custodian::rows();

	WP_CLI::line(
		sprintf(
			'CUSTODIAN_SEED=ok|rows:%d|fingerprint:%s',
			count( $rows ),
			Kuka_Shipping_Cache_Custodian::fingerprint( $rows )
		)
	);

	return;
}

if ( 'crash' === $kuka_custodian_phase ) {
	$custodian = ( new Kuka_Shipping_Cache_Custodian() )->guard();

	WP_CLI::line(
		sprintf(
			'CUSTODIAN_CRASH=starting|death:%s|snapshot_rows:%d|snapshot_fingerprint:%s',
			$kuka_custodian_death,
			count( $custodian->names_before() ),
			$custodian->snapshot_fingerprint()
		)
	);

	/*
	 * Exactly what a scenario does: empty the cache, then refill it with mock
	 * data. If the custodian were not registered, these rows would be what the
	 * shop is left with.
	 */
	delete_transient( KUKA_CUSTODIAN_SENTINEL_KEY );
	set_transient( KUKA_CUSTODIAN_SENTINEL_KEY, array( array( 'code' => '34', 'name' => 'MOCK-ONLY' ) ), DAY_IN_SECONDS );
	set_transient( 'kuka_dhl_cbs_districts_v1_34', array( array( 'code' => '1', 'name' => 'MOCK-ONLY' ) ), DAY_IN_SECONDS );

	WP_CLI::line( 'CUSTODIAN_CRASH=dirtied|run_owned_rows_added:yes' );

	if ( 'fatal' === $kuka_custodian_death ) {
		// An uncatchable error, on purpose: shutdown functions still run.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		kuka_custodian_this_function_does_not_exist();
	}

	WP_CLI::error( 'CUSTODIAN_CRASH=exited_on_purpose' );
}

if ( 'check' === $kuka_custodian_phase ) {
	$rows        = Kuka_Shipping_Cache_Custodian::rows();
	$fingerprint = Kuka_Shipping_Cache_Custodian::fingerprint( $rows );
	$cached      = get_transient( KUKA_CUSTODIAN_SENTINEL_KEY );
	$sentinel_ok = kuka_custodian_sentinel_value() === $cached;
	$matches     = '' === $kuka_custodian_want || $kuka_custodian_want === $fingerprint;

	$mock_left = 0;
	foreach ( $rows as $name => $row ) {
		if ( str_contains( $row['option_value'], 'MOCK-ONLY' ) ) {
			++$mock_left;
		}
	}

	WP_CLI::line(
		sprintf(
			'CUSTODIAN_CHECK=%s|rows:%d|fingerprint:%s|expected:%s|fingerprint_match:%s|sentinel_value_intact:%s|run_owned_rows_left:%d',
			( $matches && $sentinel_ok && 0 === $mock_left ) ? 'PASS' : 'FAIL',
			count( $rows ),
			$fingerprint,
			'' !== $kuka_custodian_want ? $kuka_custodian_want : 'none',
			$matches ? 'yes' : 'NO',
			$sentinel_ok ? 'yes' : 'NO',
			$mock_left
		)
	);

	return;
}

if ( 'cleanup' === $kuka_custodian_phase ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", Kuka_Shipping_Cache_Custodian::LIKE ) );
	wp_cache_delete( 'alloptions', 'options' );

	WP_CLI::line( sprintf( 'CUSTODIAN_CLEANUP=ok|rows_left:%d', count( Kuka_Shipping_Cache_Custodian::rows() ) ) );

	return;
}

WP_CLI::error( 'CUSTODIAN=FAIL|reason:unknown_phase:' . $kuka_custodian_phase );
