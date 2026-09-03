<?php
/**
 * READ-ONLY DHL sandbox connection test.
 *
 * What this script can do: obtain a token (Identity API) and read the carrier's
 * city and district reference lists (CBS Info API).
 *
 * What this script CANNOT do, structurally rather than by convention: create an
 * order, create a barcode, update anything or cancel anything. It never
 * constructs the manager, and the only client methods it calls are the two
 * read-only ones named above. There is no code path from here to a write.
 *
 * Nothing about the credentials is printed. The report says which values were
 * PRESENT and what the carrier answered; it never says what a value is, not
 * even masked, and never prints the token, a request body or a response body.
 *
 * Run with:  ./scripts/dhl-test-run.sh test-dhl-sandbox.php
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || exit( 1 );

require_once __DIR__ . '/lib-dhl-test-credentials.php';
require_once __DIR__ . '/lib-shipping-module-loader.php';

$credentials = kuka_dhl_load_credentials();

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_CREDENTIALS=%s|reason:%s|present:%d/4|missing:%s',
		$credentials['ok'] ? 'READY' : 'INCOMPLETE',
		$credentials['reason'],
		count( $credentials['present'] ),
		array() === $credentials['missing'] ? 'none' : implode( ',', $credentials['missing'] )
	)
);

if ( ! $credentials['ok'] ) {
	WP_CLI::line( 'DHL_SANDBOX_CONNECTION=BLOCKED|reason:credentials_incomplete|external_calls:0' );
	WP_CLI::line( 'Eksik anahtarları eklemek için (hiçbir şey ekrana yazılmaz):  ./scripts/dhl-test-credentials.sh' );
	WP_CLI::halt( 0 );
}

$module = kuka_shipping_load_module();

if ( ! $module['ok'] ) {
	WP_CLI::error( 'DHL_SANDBOX_CONNECTION=FAIL|reason:' . $module['reason'] );
}

$config = new Kuka_Island_Shipping_DHL_Config();

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_CONFIG=%s|environment:%s|live_blocked:%s|ready:%s|automation:%s|cod:%s|tracking_number_source:%s',
		$config->is_ready() ? 'READY' : 'NOT_READY',
		$config->get_environment(),
		$config->is_live_blocked() ? 'yes' : 'no',
		$config->is_ready() ? 'yes' : 'no',
		$config->is_automation_enabled() ? 'ON' : 'off',
		$config->is_cod_enabled() ? 'ON' : 'off',
		'' !== $config->get_tracking_number_source() ? $config->get_tracking_number_source() : 'unmeasured'
	)
);

if ( $config->is_live_blocked() || ! $config->is_ready() ) {
	WP_CLI::line( 'DHL_SANDBOX_CONNECTION=BLOCKED|reason:config_not_ready|external_calls:0' );
	WP_CLI::halt( 0 );
}

$client = new Kuka_Island_Shipping_DHL_Client( $config );

/* -------------------------------------------------------------------------- */
/* 1. Identity: can a session be established at all?                          */
/* -------------------------------------------------------------------------- */

$authenticated = $client->authenticate();

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_IDENTITY=%s|%s|token_stored_in_database:no|token_printed:no',
		$authenticated->is_success() ? 'PASS' : 'FAIL',
		$authenticated->to_safe_line()
	)
);

if ( ! $authenticated->is_success() ) {
	WP_CLI::line( 'DHL_SANDBOX_CBS=SKIPPED|reason:no_session' );
	WP_CLI::error( 'DHL_SANDBOX_CONNECTION=FAIL|stage:identity' );
}

/* -------------------------------------------------------------------------- */
/* 2. CBS Info: read the reference data the address mapper depends on         */
/* -------------------------------------------------------------------------- */

$resolver = new Kuka_Island_Shipping_DHL_Address_Resolver( $client );

// Start from a cold cache so this measures the carrier, not a stored answer.
$resolver->purge_cache();

$cities = $resolver->cities();

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_CBS_CITIES=%s|%s|count:%d',
		$cities['ok'] ? 'PASS' : 'FAIL',
		$cities['result']->to_safe_line(),
		count( $cities['places'] )
	)
);

$district_line = 'DHL_SANDBOX_CBS_DISTRICTS=SKIPPED|reason:no_cities';
$sample_city   = '';

if ( $cities['ok'] && array() !== $cities['places'] ) {
	// The first row, whatever it is. No city name is hard-coded here: the point
	// is that the endpoint answers, not that a particular province exists.
	$sample_city = (string) $cities['places'][0]['code'];
	$districts   = $resolver->districts( $sample_city );

	$district_line = sprintf(
		'DHL_SANDBOX_CBS_DISTRICTS=%s|%s|count:%d',
		$districts['ok'] ? 'PASS' : 'FAIL',
		$districts['result']->to_safe_line(),
		count( $districts['places'] )
	);
}

WP_CLI::line( $district_line );

// Leave no cached reference data behind: this run was a connection test, not a
// warm-up, and the next run must measure the carrier again.
$purged = $resolver->purge_cache( '' !== $sample_city ? array( $sample_city ) : array() );

WP_CLI::line( sprintf( 'DHL_SANDBOX_CACHE_CLEARED=PASS|entries_removed:%d', $purged ) );

$ok = $authenticated->is_success() && $cities['ok'];

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_CONNECTION=%s|read_only:yes|orders_created:0|barcodes_created:0|shipments_touched:0',
		$ok ? 'PASS' : 'FAIL'
	)
);

if ( ! $ok ) {
	WP_CLI::error( 'DHL_SANDBOX_CONNECTION=FAIL' );
}
