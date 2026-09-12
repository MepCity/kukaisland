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
 *   1. createOrder, through the ordinary manager, with every ordinary gate in
 *      force.
 *   2. prove the carrier order exists with the ordinary read-only
 *      reconciliation path.
 *   3. createbarcode through the separate resume door used by production.
 *   4. one read-only status query.
 *   5. cancel, confirmed by a second read.
 *
 * If step 1 ends uncertain, the remaining steps are skipped and the order is
 * left in reconciliation. Nothing is retried. If step 5 cannot confirm the cancellation,
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

/**
 * Record only the JSON field SHAPE of the two reconciliation reads.
 *
 * Values are deliberately unavailable to the report: a carrier response may
 * contain recipient data. Field names and container types are enough to detect
 * drift between the documented envelope and the sandbox response.
 */
final class Kuka_Island_DHL_Sandbox_Shape_Transport implements Kuka_Island_Shipping_HTTP_Transport_Interface {
	private Kuka_Island_Shipping_HTTP_Transport_Interface $inner;
	/** @var array<int, string> */
	private array $observations = array();

	public function __construct() {
		$this->inner = new Kuka_Island_Shipping_DHL_HTTP_Transport();
	}

	public function request( string $method, string $url, array $headers, string $body, int $timeout ): array {
		$response = $this->inner->request( $method, $url, $headers, $body, $timeout );
		$operation = '';

		if ( str_contains( $url, '/getshipmentstatus/' ) ) {
			$operation = 'get_shipment_status';
		} elseif ( str_contains( $url, '/getshipment/' ) ) {
			$operation = 'get_shipment';
		} elseif ( str_contains( $url, '/getorder/' ) ) {
			$operation = 'get_order';
		}

		if ( '' !== $operation ) {
			$decoded = json_decode( (string) $response['body'], true );
			$this->observations[] = sprintf(
				'%s:http_%d:%s',
				$operation,
				(int) $response['status'],
				self::shape( JSON_ERROR_NONE === json_last_error() ? $decoded : null )
			);
		}

		return $response;
	}

	public function safe_line(): string {
		return array() === $this->observations ? 'none' : implode( ',', $this->observations );
	}

	/** @param mixed $decoded Decoded JSON. */
	private static function shape( $decoded ): string {
		if ( ! is_array( $decoded ) ) {
			return null === $decoded ? 'null_or_non_json' : gettype( $decoded );
		}

		if ( array_is_list( $decoded ) ) {
			$first = $decoded[0] ?? null;
			return 'list(' . count( $decoded ) . '):' . self::shape( $first );
		}

		$keys = array_map(
			static fn ( $key ): string => preg_replace( '/[^A-Za-z0-9_]/', '', (string) $key ) ?: 'field',
			array_keys( $decoded )
		);
		sort( $keys, SORT_STRING );

		return 'object(' . implode( '+', array_slice( $keys, 0, 30 ) ) . ')';
	}
}

/*
 * The carrier is built by hand rather than taken from the filter, because this
 * script runs with the plugin inactive: the filter would have nothing on it.
 */
$shape_transport = new Kuka_Island_DHL_Sandbox_Shape_Transport();
$client          = new Kuka_Island_Shipping_DHL_Client( $config, $shape_transport );
$resolver        = new Kuka_Island_Shipping_DHL_Address_Resolver( $client );
$provider        = new Kuka_Island_Shipping_DHL_Provider( $config, $client, $resolver );
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

/* A settled test record may be inspected again, but it may never be recreated. */
if ( Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === Kuka_Island_Shipping_Order_Store::get_state( $order ) ) {
	$data       = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
	$diagnostic = $provider->read_shipment_status( (string) $data['reference'] );

	WP_CLI::line( 'DHL_SANDBOX_SETTLED_STATUS_READ=' . $diagnostic->to_safe_line() );
	WP_CLI::line( 'DHL_SANDBOX_SETTLED_STATUS_SHAPE=' . $shape_transport->safe_line() );
	WP_CLI::line( 'DHL_SANDBOX_SHIPMENT=INSPECTED|writes:0|state:cancelled|left_at_carrier:0' );
	WP_CLI::halt( 0 );
}

/* -------------------------------------------------------------------------- */
/* 1. Create the carrier order                                                  */
/* -------------------------------------------------------------------------- */

$initial_state = Kuka_Island_Shipping_Order_Store::get_state( $order );

if ( Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $initial_state ) {
	$created = array(
		'ok'     => true,
		'code'   => '',
		'detail' => 'not_repeated:already_order_created',
	);
} else {
	$created = $manager->create_shipment( $order );
}

$order = wc_get_order( $order_id );
$data  = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_CREATE_ORDER=%s|state:%s|code:%s|shipment_id_present:%s|repeated:%s|%s',
		$created['ok'] ? 'PASS' : 'FAIL',
		$data['state'],
		'' !== $created['code'] ? $created['code'] : 'none',
		'' !== $data['shipment_id'] ? 'yes' : 'no',
		Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $initial_state ? 'no' : 'not_applicable',
		$created['detail']
	)
);

if ( ! $created['ok'] || Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED !== $data['state'] ) {
	WP_CLI::line( 'DHL_SANDBOX_RECONCILE_ORDER=SKIPPED|reason:create_order_did_not_succeed' );
	WP_CLI::line( 'DHL_SANDBOX_CREATE_BARCODE=SKIPPED|reason:create_order_did_not_succeed' );
	WP_CLI::line( 'DHL_SANDBOX_QUERY=SKIPPED|reason:create_did_not_succeed' );
	WP_CLI::line( 'DHL_SANDBOX_CANCEL=SKIPPED|reason:create_did_not_succeed' );
	WP_CLI::line( 'Belirsiz kayıt varsa yeniden gönderim YAPILMAZ; sipariş ekranından mutabakat sorgusu çalıştırın.' );
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=FAIL|stage:create_order' );
}

/* -------------------------------------------------------------------------- */
/* 2. Prove the carrier order exists, using reads only                          */
/* -------------------------------------------------------------------------- */

$reconciled = $manager->reconcile_order( $order );
$order      = wc_get_order( $order_id );
$data       = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_RECONCILE_ORDER=%s|verdict:%s|state:%s',
		'order_present' === $reconciled['verdict'] ? 'PASS' : 'FAIL',
		$reconciled['verdict'],
		$data['state']
	)
);
WP_CLI::line( 'DHL_SANDBOX_RECONCILE_SHAPES=' . $shape_transport->safe_line() );

if ( 'order_present' !== $reconciled['verdict'] || Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED !== $data['state'] ) {
	WP_CLI::line( 'DHL_SANDBOX_CREATE_BARCODE=SKIPPED|reason:carrier_order_not_proven' );
	WP_CLI::line( 'DHL_SANDBOX_QUERY=SKIPPED|reason:carrier_order_not_proven' );
	WP_CLI::line( 'DHL_SANDBOX_CANCEL=SKIPPED|reason:carrier_order_not_proven' );
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=FAIL|stage:reconcile_order' );
}

/* -------------------------------------------------------------------------- */
/* 3. Create the barcode through production's separate resume door             */
/* -------------------------------------------------------------------------- */

$barcoded = $manager->resume_barcode( $order );
$order    = wc_get_order( $order_id );
$data     = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );

WP_CLI::line(
	sprintf(
		'DHL_SANDBOX_CREATE_BARCODE=%s|state:%s|code:%s|shipment_id_present:%s|pieces:%d|%s',
		$barcoded['ok'] ? 'PASS' : 'FAIL',
		$data['state'],
		'' !== $barcoded['code'] ? $barcoded['code'] : 'none',
		'' !== $data['shipment_id'] ? 'yes' : 'no',
		(int) ( $barcoded['pieces'] ?? 0 ),
		$barcoded['detail']
	)
);

if ( ! $barcoded['ok'] || Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED !== $data['state'] ) {
	WP_CLI::line( 'DHL_SANDBOX_QUERY=SKIPPED|reason:create_barcode_did_not_succeed' );
	WP_CLI::line( 'DHL_SANDBOX_CANCEL=SKIPPED|reason:create_barcode_did_not_succeed' );
	WP_CLI::line( 'Barkod isteği yeniden GÖNDERİLMEZ; kaydın güvenli durumuna göre mutabakat veya manuel inceleme gerekir.' );
	WP_CLI::error( 'DHL_SANDBOX_SHIPMENT=FAIL|stage:create_barcode' );
}

/* -------------------------------------------------------------------------- */
/* 4. Query                                                                    */
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
WP_CLI::line( 'DHL_SANDBOX_QUERY_SHAPES=' . $shape_transport->safe_line() );

/* -------------------------------------------------------------------------- */
/* 5. Cancel, confirmed by a read                                              */
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

WP_CLI::line( 'DHL_SANDBOX_SHIPMENT=PASS|orders_created:1|barcodes_created:1|queried:1|cancelled:1|left_at_carrier:0' );
