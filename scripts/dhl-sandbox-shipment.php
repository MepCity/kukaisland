<?php
/**
 * ONE sandbox shipment: create, query, cancel.
 *
 * This is the only tool in the repository that writes to the carrier, and it is
 * reachable only through scripts/dhl-sandbox-run.sh, which demands the exact
 * confirmation phrase and an order id on the command line. Both are re-checked
 * here, so calling this file directly with wp eval-file achieves nothing.
 *
 * The sequence is deliberately complete: creating without cancelling would
 * leave a live parcel at a courier, and a tool that produces one of those every
 * time somebody tests the connection is a tool that gets somebody an invoice.
 *
 *   1. createOrder + createbarcode, through the ordinary manager, with every
 *      ordinary gate in force.
 *   2. one read-only status query.
 *   3. cancel, confirmed by a second read.
 *
 * If step 1 ends uncertain, steps 2 and 3 are skipped and the order is left in
 * reconciliation. Nothing is retried. If step 3 cannot confirm the cancellation,
 * that is reported loudly, because it means a parcel may still exist.
 *
 * Run with:
 *   ./scripts/dhl-sandbox-run.sh --order=<id> --confirm=TEK-SANDBOX-GONDERISI-ONAYLIYORUM
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || exit( 1 );

const KUKA_DHL_SANDBOX_CONFIRM_PHRASE = 'TEK-SANDBOX-GONDERISI-ONAYLIYORUM';

$argv_values = isset( $args ) && is_array( $args ) ? $args : array();

$order_id = (int) ( $argv_values[0] ?? 0 );
$confirm  = (string) ( $argv_values[1] ?? '' );

if ( KUKA_DHL_SANDBOX_CONFIRM_PHRASE !== $confirm ) {
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=BLOCKED|reason:confirmation_phrase_missing|external_calls:0' );
}

if ( $order_id < 1 ) {
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=BLOCKED|reason:order_id_missing|external_calls:0' );
}

require_once __DIR__ . '/lib-dhl-test-credentials.php';
require_once __DIR__ . '/lib-shipping-module-loader.php';

$credentials = kuka_dhl_load_credentials();

if ( ! $credentials['ok'] ) {
	WP_CLI::error(
		sprintf(
			'DHL_SANDBOX_SHIPMENT=BLOCKED|reason:credentials_incomplete|missing:%s|external_calls:0',
			implode( ',', $credentials['missing'] )
		)
	);
}

$module = kuka_shipping_load_module();

if ( ! $module['ok'] ) {
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=BLOCKED|reason:' . $module['reason'] );
}

$config = new Kuka_Island_Shipping_DHL_Config();

if ( $config->is_live_blocked() ) {
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=BLOCKED|reason:live_environment|external_calls:0' );
}

if ( ! $config->is_ready() ) {
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=BLOCKED|reason:config_not_ready|external_calls:0' );
}

$order = wc_get_order( $order_id );

if ( ! $order instanceof WC_Order ) {
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=BLOCKED|reason:order_not_found|external_calls:0' );
}

/*
 * The carrier is built by hand rather than taken from the filter, because this
 * script runs with the plugin inactive: the filter would have nothing on it.
 */
$provider = new Kuka_Island_Shipping_DHL_Provider( $config );
$registry = new Kuka_Island_Shipping_Carrier_Registry();

$attach = static function ( $carriers ) use ( $provider ): array {
	return array( $provider );
};

add_filter( 'kuka_island_shipping_carriers', $attach, 999 );
$registry->reset();
$registry->all();
remove_filter( 'kuka_island_shipping_carriers', $attach, 999 );

$manager = new Kuka_Island_Shipping_Manager( $registry );

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_SHIPMENT_START=order:%d|environment:%s|state_before:%s',
		$order_id,
		$config->get_environment(),
		Kuka_Island_Shipping_Order_Store::get_state( $order )
	)
);

/* -------------------------------------------------------------------------- */
/* 1. Create                                                                   */
/* -------------------------------------------------------------------------- */

$created = $manager->create_shipment( $order );
$order   = wc_get_order( $order_id );
$data    = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_CREATE=%s|state:%s|code:%s|shipment_id_present:%s|barcodes:%d|%s',
		$created['ok'] ? 'PASS' : 'FAIL',
		$data['state'],
		'' !== $created['code'] ? $created['code'] : 'none',
		'' !== $data['shipment_id'] ? 'yes' : 'no',
		count( $data['barcodes'] ),
		$created['detail']
	)
);

if ( ! $created['ok'] ) {
	WP_CLI::line( 'DHL_SANDBOX_QUERY=SKIPPED|reason:create_did_not_succeed' );
	WP_CLI::line( 'DHL_SANDBOX_CANCEL=SKIPPED|reason:create_did_not_succeed' );
	WP_CLI::line( 'Belirsiz kayıt varsa yeniden gönderim YAPILMAZ; sipariş ekranından mutabakat sorgusu çalıştırın.' );
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=FAIL|stage:create' );
}

/* -------------------------------------------------------------------------- */
/* 2. Query                                                                    */
/* -------------------------------------------------------------------------- */

$queried = $manager->query_status( $order );
$order   = wc_get_order( $order_id );

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_QUERY=%s|lifecycle:%s|stored_code:%d|%s',
		$queried['ok'] ? 'PASS' : 'FAIL',
		$queried['lifecycle'],
		(int) Kuka_Island_Shipping_Order_Store::get_shipment_data( $order )['status_code'],
		$queried['detail']
	)
);

/* -------------------------------------------------------------------------- */
/* 3. Cancel, confirmed by a read                                              */
/* -------------------------------------------------------------------------- */

$cancelled = $manager->cancel( $order );
$order     = wc_get_order( $order_id );
$final     = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_CANCEL=%s|state:%s|code:%s|%s',
		$cancelled['ok'] ? 'PASS' : 'FAIL',
		$final['state'],
		'' !== $cancelled['code'] ? $cancelled['code'] : 'none',
		$cancelled['detail']
	)
);

if ( ! $cancelled['ok'] ) {
	WP_CLI::line( 'UYARI: iptal doğrulanamadı. Taşıyıcıda gönderi hâlâ var olabilir; MNG/DHL panelinden elle kontrol edin.' );
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=FAIL|stage:cancel' );
}

WP_CLI::line( 'DHL_SANDBOX_SHIPMENT=PASS|created:1|queried:1|cancelled:1|left_at_carrier:0' );
