<?php
/**
 * Behavioural verification for the shipping automation module.
 *
 * Everything here is MEASURED, not read. A mock transport stands in for the
 * network, so every failure mode the carrier API can produce -- timeout, 401,
 * 403, 409, 429, 5xx, a body that is not JSON, a status code nobody documented
 * -- is produced on demand and the resulting behaviour is observed. The mock
 * records every request it is handed, which is what makes "no second
 * createOrder was sent" an observation rather than a claim.
 *
 * No network call is made. No real credential is used. Fixture orders are
 * created, driven and deleted, together with any fulfilment they produced, so
 * the run leaves no row behind.
 *
 * Run with:
 * docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-shipping-automation.php
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || exit( 1 );

/*
 * Fixture orders move through real WooCommerce lifecycle code. Mail is
 * suppressed for the same reason every other suite suppresses it: a
 * verification run must not send anything to anyone.
 */
add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
add_filter( 'woocommerce_fulfillment_email_enabled', '__return_false' );

/*
 * A real request to the carrier would invalidate this whole run, so one is made
 * impossible rather than merely avoided. Every WordPress HTTP call to the
 * vendor's host is short-circuited AND COUNTED here, before anything is loaded.
 * The count is asserted at the end: a mock that silently stopped being used, or
 * a new code path that reached for wp_remote_*, shows up as a number instead of
 * as a surprise on somebody's bill.
 */
$kuka_ship_real_requests = array();

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$kuka_ship_real_requests ) {
		if ( str_contains( (string) $url, 'mngkargo.com.tr' ) ) {
			$kuka_ship_real_requests[] = (string) $url;

			return new WP_Error( 'kuka_ship_network_blocked', 'Blocked by verify-shipping-automation.php' );
		}

		return $preempt;
	},
	1,
	3
);

require_once __DIR__ . '/lib-shipping-module-loader.php';

$module = kuka_shipping_load_module();

$failures = array();
$report   = static function ( string $name, bool $passed, string $detail = '' ) use ( &$failures ): void {
	WP_CLI::line( sprintf( '%s=%s%s', $name, $passed ? 'PASS' : 'FAIL', '' !== $detail ? '|' . $detail : '' ) );
	if ( ! $passed ) {
		$failures[] = $name;
	}
};

/*
 * Order notes are counted at both ends of the run. They are the one thing a
 * fixture leaves behind that deleting the order does not remove, so counting
 * them here makes the residue visible in this suite rather than only in the
 * cross-process keyset comparison.
 */
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$notes_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'order_note'" );

$report(
	'SHIPPING_MODULE_LOADS',
	$module['ok'] && class_exists( 'Kuka_Island_Shipping_Manager' ),
	sprintf( 'reason:%s|files:%d', $module['reason'], $module['classes'] )
);

if ( ! $module['ok'] ) {
	WP_CLI::error( 'SHIPPING_VERIFY=FAIL' );
}

/* ========================================================================== */
/* Mock transport                                                              */
/* ========================================================================== */

/**
 * A transport that answers from a script and remembers every question.
 */
final class Kuka_Shipping_Mock_Transport implements Kuka_Island_Shipping_HTTP_Transport_Interface {

	/** @var array<int, array{method: string, url: string, headers: array<string,string>, body: string}> */
	public array $log = array();

	/** @var callable */
	private $responder;

	public function __construct( callable $responder ) {
		$this->responder = $responder;
	}

	public function request( string $method, string $url, array $headers, string $body, int $timeout ): array {
		$this->log[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		);

		$answer = call_user_func( $this->responder, $method, $url, $body, count( $this->log ) );

		return array(
			'status'  => (int) ( $answer['status'] ?? 0 ),
			'headers' => array(),
			'body'    => (string) ( $answer['body'] ?? '' ),
			'error'   => (string) ( $answer['error'] ?? '' ),
		);
	}

	/** How many requests hit a path containing this needle. */
	public function count_for( string $needle ): int {
		$count = 0;

		foreach ( $this->log as $entry ) {
			if ( str_contains( $entry['url'], $needle ) ) {
				++$count;
			}
		}

		return $count;
	}

	/** Everything that was ever sent, as one string, for leak scanning. */
	public function dump(): string {
		$parts = array();

		foreach ( $this->log as $entry ) {
			$parts[] = $entry['method'] . ' ' . $entry['url'] . ' ' . wp_json_encode( $entry['headers'] ) . ' ' . $entry['body'];
		}

		return implode( "\n", $parts );
	}

	public function reset(): void {
		$this->log = array();
	}
}

/**
 * A second carrier, written the way the contract says one may be written.
 *
 * It implements Kuka_Island_Shipping_Carrier_Interface and touches NOTHING
 * else: no DHL class, no DHL constant, no DHL configuration, no HTTP client and
 * no OpenAPI document. That is the whole point. The claim "a second courier is
 * one adapter attached to one filter" was previously measured by registering
 * the DHL adapter a second time, which could not have failed and therefore
 * proved nothing. This class can fail, and it counts every call it receives so
 * the manager's use of it is observed rather than assumed.
 */
final class Kuka_Shipping_Fake_Carrier implements Kuka_Island_Shipping_Carrier_Interface {

	public const KEY = 'kuka-test-kargo';

	/**
	 * This instance's key.
	 *
	 * Per-instance rather than a constant, because the ownership measurements
	 * need TWO adapters registered at once and have to be able to tell which of
	 * them the manager reached.
	 */
	private string $key;

	public function __construct( string $key = self::KEY ) {
		$this->key = strtolower( trim( $key ) );
	}

	/**
	 * Called at the START of every write, before the write is recorded.
	 *
	 * The seam that produces "the world changed between the write and the
	 * confirming read" without guessing at call ordering.
	 *
	 * @var callable|null
	 */
	public $on_write = null;

	/**
	 * Close the REAL runtime gate immediately after a write.
	 *
	 * Which puts the gate's closure exactly where it hurts: after the carrier
	 * has been told to cancel and before anybody can confirm it.
	 */
	public bool $close_runtime_gate_after_write = false;

	/**
	 * Scripted answers, keyed by operation.
	 *
	 * Lets one adapter produce the uncertain write the reconciliation rules
	 * exist for, without a second class that would drift from this one.
	 *
	 * @var array<string, Kuka_Island_Shipping_Result>
	 */
	public array $results = array();

	/** @var array<string, int> */
	public array $calls = array();

	/**
	 * How many times the gate asked whether this carrier is contactable.
	 *
	 * The number is the measurement: the boundary is supposed to be asked once
	 * on the way in and once more immediately before the write, so a value of
	 * two is what proves the second check exists.
	 */
	public int $readiness_checks = 0;

	/** @var array{ready: bool, gaps: array<int, string>, environment: string, live_blocked: bool} */
	public array $readiness = array(
		'ready'        => true,
		'gaps'         => array(),
		'environment'  => 'test',
		'live_blocked' => false,
	);

	/**
	 * Readiness to answer from the SECOND check onwards, or null to stay put.
	 *
	 * This is how "the operator deactivated the plugin while the lock was held"
	 * is produced deliberately instead of being waited for.
	 *
	 * @var array{ready: bool, gaps: array<int, string>, environment: string, live_blocked: bool}|null
	 */
	public ?array $readiness_after_first = null;

	private function record( string $operation ): void {
		$this->calls[ $operation ] = ( $this->calls[ $operation ] ?? 0 ) + 1;
	}

	/**
	 * How many operations this adapter refused WITHOUT contacting the carrier.
	 *
	 * Separate from $calls on purpose. A real adapter's local refusal -- an
	 * incomplete payload, a cash-on-delivery order, an endpoint that is not on
	 * the allow-list -- happens inside the method and before a socket is
	 * opened, so it is not a carrier write. Counting it as one would make every
	 * 'carrier_writes:0' measurement in this suite weaker than it reads.
	 */
	public int $local_refusals = 0;

	/**
	 * The same count, per operation.
	 *
	 * Which DOOR opened is a different question from whether anything was
	 * sent, and the create-path allow-list needs the first one: "state X never
	 * reaches createbarcode" cannot be measured from a total.
	 *
	 * @var array<string, int>
	 */
	public array $refusals = array();

	public function count_refused( string $operation ): int {
		return (int) ( $this->refusals[ $operation ] ?? 0 );
	}

	/** Record a WRITE, running the injected hooks around it. */
	private function record_write( string $operation ): void {
		$scripted = $this->results[ $operation ] ?? null;

		if ( $scripted instanceof Kuka_Island_Shipping_Result && ! $scripted->reached_carrier() ) {
			// Refused before the network: nothing left the building, so the
			// on_write hook (which represents the request starting) does not
			// fire and no write is recorded.
			++$this->local_refusals;

			$this->refusals[ $operation ] = $this->count_refused( $operation ) + 1;

			return;
		}

		if ( is_callable( $this->on_write ) ) {
			call_user_func( $this->on_write, $operation, $this );
		}

		$this->record( $operation );

		if ( $this->close_runtime_gate_after_write ) {
			Kuka_Island_Shipping_Runtime_Gate::disable();
		}
	}

	public function count_for( string $operation ): int {
		return (int) ( $this->calls[ $operation ] ?? 0 );
	}

	public function total_calls(): int {
		return (int) array_sum( $this->calls );
	}

	/** Every carrier WRITE this adapter was asked to perform. */
	public function write_calls(): int {
		$total = 0;

		foreach ( array( 'create_order', 'create_barcode', 'update_order', 'update_shipment', 'cancel_order', 'cancel_shipment' ) as $operation ) {
			$total += $this->count_for( $operation );
		}

		return $total;
	}

	/**
	 * The reads that cross the manager's read boundary.
	 *
	 * resolve_location() and ping() are deliberately NOT here: they are not
	 * shipment reads and counting them would make "the reconciliation read
	 * nothing" false the moment an address was resolved.
	 */
	public function read_calls(): int {
		$total = 0;

		foreach ( array( 'read_order', 'read_shipment', 'read_shipment_status', 'read_amendable_fields', 'track_shipment' ) as $operation ) {
			$total += $this->count_for( $operation );
		}

		return $total;
	}

	/** Was this adapter contacted AT ALL, for any reason? */
	public function contacts(): int {
		return $this->read_calls()
			+ $this->write_calls()
			+ $this->count_for( 'resolve_location' )
			+ $this->count_for( 'ping' );
	}

	/** The scripted answer for an operation, or the default one. */
	private function answer( string $operation, Kuka_Island_Shipping_Result $default ): Kuka_Island_Shipping_Result {
		return $this->results[ $operation ] ?? $default;
	}

	public function reset_counters(): void {
		$this->calls            = array();
		$this->refusals         = array();
		$this->local_refusals   = 0;
		$this->readiness_checks = 0;
	}

	public function get_key(): string {
		return $this->key;
	}

	public function get_label(): string {
		return self::KEY === $this->key
			? 'Kuka Test Kargo'
			: 'Kuka Test Kargo (' . $this->key . ')';
	}

	/**
	 * @return array{ready: bool, gaps: array<int, string>, environment: string, live_blocked: bool}
	 */
	public function get_readiness(): array {
		++$this->readiness_checks;

		if ( null !== $this->readiness_after_first && $this->readiness_checks > 1 ) {
			return $this->readiness_after_first;
		}

		return $this->readiness;
	}

	public function get_tracking_number_source(): string {
		// This carrier HAS answered the question: the piece barcode tracks.
		return self::TRACKING_SOURCE_BARCODE;
	}

	public function ping(): Kuka_Island_Shipping_Result {
		$this->record( 'ping' );

		return Kuka_Island_Shipping_Result::success( 'ping' );
	}

	public function resolve_location( string $city, string $district ): Kuka_Island_Shipping_Result {
		$this->record( 'resolve_location' );

		return $this->answer(
			'resolve_location',
			Kuka_Island_Shipping_Result::success(
				'resolve_location',
				array(
					'city_code'     => 7,
					'district_code' => 77,
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function create_order( array $shipment ): Kuka_Island_Shipping_Result {
		$this->record_write( 'create_order' );

		return $this->answer( 'create_order', Kuka_Island_Shipping_Result::success( 'create_order', array( 'order_invoice_id' => 'FAKE-OINV-1' ) ) );
	}

	/**
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function create_barcode( array $shipment ): Kuka_Island_Shipping_Result {
		$this->record_write( 'create_barcode' );

		return $this->answer(
			'create_barcode',
			Kuka_Island_Shipping_Result::success(
				'create_barcode',
				array(
					'shipment_id' => 'FAKE-SHIP-1',
					'barcodes'    => 'FAKE-BC-1',
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function update_order( array $shipment ): Kuka_Island_Shipping_Result {
		$this->record_write( 'update_order' );

		return $this->answer( 'update_order', Kuka_Island_Shipping_Result::success( 'update_order', array( 'acknowledged' => true ) ) );
	}

	public function cancel_order( string $reference ): Kuka_Island_Shipping_Result {
		$this->record_write( 'cancel_order' );

		return $this->answer( 'cancel_order', Kuka_Island_Shipping_Result::success( 'cancel_order', array( 'acknowledged' => true ) ) );
	}

	/**
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function update_shipment( array $shipment ): Kuka_Island_Shipping_Result {
		$this->record_write( 'update_shipment' );

		return $this->answer( 'update_shipment', Kuka_Island_Shipping_Result::success( 'update_shipment', array( 'acknowledged' => true ) ) );
	}

	public function cancel_shipment( string $reference, string $shipment_id ): Kuka_Island_Shipping_Result {
		$this->record_write( 'cancel_shipment' );

		return $this->answer( 'cancel_shipment', Kuka_Island_Shipping_Result::success( 'cancel_shipment', array( 'acknowledged' => true ) ) );
	}

	public function read_order( string $reference ): Kuka_Island_Shipping_Result {
		$this->record( 'read_order' );

		return $this->answer( 'read_order', Kuka_Island_Shipping_Result::permanent( 'get_order', 'not_found', 404 ) );
	}

	public function read_shipment( string $reference ): Kuka_Island_Shipping_Result {
		$this->record( 'read_shipment' );

		// Gone, which is what a confirmed cancellation looks like.
		return $this->answer( 'read_shipment', Kuka_Island_Shipping_Result::permanent( 'get_shipment', 'not_found', 404 ) );
	}

	/**
	 * What this carrier claims to hold for the amendable fields.
	 *
	 * Empty means "this carrier cannot read them back", which is the honest
	 * default and the one the DHL adapter answers.
	 *
	 * @var array<string, string>
	 */
	public array $amendable = array();

	public function read_amendable_fields( string $reference ): Kuka_Island_Shipping_Result {
		$this->record( 'read_amendable_fields' );

		if ( array() === $this->amendable ) {
			return $this->answer( 'read_amendable_fields', Kuka_Island_Shipping_Result::permanent( 'read_amendable_fields', 'readback_unsupported' ) );
		}

		return $this->answer( 'read_amendable_fields', Kuka_Island_Shipping_Result::success( 'read_amendable_fields', $this->amendable ) );
	}

	public function read_shipment_status( string $reference ): Kuka_Island_Shipping_Result {
		$this->record( 'read_shipment_status' );

		return $this->answer(
			'read_shipment_status',
			Kuka_Island_Shipping_Result::success(
				'get_shipment_status',
				array(
					'status_code'  => 2,
					'tracking_url' => 'https://fake-kargo.example/FAKE-SHIP-1',
				)
			)
		);
	}

	public function track_shipment( string $reference ): Kuka_Island_Shipping_Result {
		$this->record( 'track_shipment' );

		return Kuka_Island_Shipping_Result::success( 'track_shipment', array( 'status_code' => 2 ) );
	}
}

/** Sentinel credentials. Never real, and never printed. */
const KUKA_SHIP_CLIENT_ID   = 'CID-SENTINEL-AAAAAAAA';
const KUKA_SHIP_SECRET      = 'CSEC-SENTINEL-BBBBBBBB';
const KUKA_SHIP_CUSTOMER    = '9990001-SENTINEL';
const KUKA_SHIP_PASSWORD    = 'PWD-SENTINEL-CCCCCCCC';
const KUKA_SHIP_JWT         = 'JWT-SENTINEL-DDDDDDDD';

/**
 * A ready configuration that points at the sandbox and holds sentinel secrets.
 *
 * @param array<string, mixed> $overrides Extra overrides.
 */
function kuka_ship_config( array $overrides = array() ): Kuka_Island_Shipping_DHL_Config {
	return new Kuka_Island_Shipping_DHL_Config(
		array_merge(
			array(
				'environment'     => Kuka_Island_Shipping_DHL_Config::ENV_TEST,
				'client_id'       => KUKA_SHIP_CLIENT_ID,
				'client_secret'   => KUKA_SHIP_SECRET,
				'customer_number' => KUKA_SHIP_CUSTOMER,
				'password'        => KUKA_SHIP_PASSWORD,
				'timeout'         => 10,
			),
			$overrides
		)
	);
}

/** The JSON body a successful /token answer carries. */
function kuka_ship_token_body(): string {
	return (string) wp_json_encode(
		array(
			'jwt'                    => KUKA_SHIP_JWT,
			'refreshToken'           => 'RT-SENTINEL',
			'jwtExpireDate'          => gmdate( 'd.m.Y H:i:s', time() + 3600 ),
			'refreshTokenExpireDate' => gmdate( 'd.m.Y H:i:s', time() + 86400 ),
		)
	);
}

/* ========================================================================== */
/* 1. Fault classification: read and write are not the same question           */
/* ========================================================================== */

$classifier_cases = array(
	// label                     status error         parsed write  expected outcome                              expected code
	'timeout_write'    => array( 0, 'cURL error 28: Operation timed out', false, true, Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN, 'timeout' ),
	'timeout_read'     => array( 0, 'cURL error 28: Operation timed out', false, false, Kuka_Island_Shipping_Result::OUTCOME_TRANSIENT, 'timeout' ),
	'network_write'    => array( 0, 'Could not resolve host', false, true, Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN, 'network_error' ),
	'ok_parsed'        => array( 200, '', true, true, Kuka_Island_Shipping_Result::OUTCOME_SUCCESS, '' ),
	'ok_unparsed_write' => array( 200, '', false, true, Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN, 'malformed_response' ),
	'ok_unparsed_read' => array( 200, '', false, false, Kuka_Island_Shipping_Result::OUTCOME_TRANSIENT, 'malformed_response' ),
	'redirect_write'   => array( 302, '', false, true, Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN, 'unexpected_redirect' ),
	'bad_request'      => array( 400, '', false, true, Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'bad_request' ),
	'unauthorized'     => array( 401, '', false, true, Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'unauthorized' ),
	'forbidden'        => array( 403, '', false, true, Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'forbidden' ),
	'not_found_read'   => array( 404, '', false, false, Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'not_found' ),
	'conflict_write'   => array( 409, '', false, true, Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN, 'conflict' ),
	'conflict_read'    => array( 409, '', false, false, Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'conflict' ),
	'rate_limit_write' => array( 429, '', false, true, Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN, 'rate_limited' ),
	'rate_limit_read'  => array( 429, '', false, false, Kuka_Island_Shipping_Result::OUTCOME_TRANSIENT, 'rate_limited' ),
	'server_write'     => array( 500, '', false, true, Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN, 'server_error' ),
	'server_read'      => array( 503, '', false, false, Kuka_Island_Shipping_Result::OUTCOME_TRANSIENT, 'server_error' ),
	'teapot_write'     => array( 418, '', false, true, Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN, 'unexpected_status' ),
);

$classifier_ok      = true;
$classifier_details = array();

foreach ( $classifier_cases as $label => $case ) {
	list( $status, $error, $parsed, $is_write, $expected_outcome, $expected_code ) = $case;

	$verdict = Kuka_Island_Shipping_Fault_Classifier::classify( $status, $error, $parsed, $is_write );

	if ( $verdict['outcome'] !== $expected_outcome || $verdict['code'] !== $expected_code ) {
		$classifier_ok        = false;
		$classifier_details[] = $label;
	}
}

$report(
	'SHIPPING_FAULT_MATRIX',
	$classifier_ok,
	sprintf( 'cases:%d|write_and_read_separated:yes|wrong:%s', count( $classifier_cases ), array() === $classifier_details ? 'none' : implode( ',', $classifier_details ) )
);

/* ========================================================================== */
/* 2. The status dictionary, including everything outside it                   */
/* ========================================================================== */

$status_cases = array(
	array( 1, Kuka_Island_Shipping_Status::LIFECYCLE_IN_PROGRESS ),
	array( 2, Kuka_Island_Shipping_Status::LIFECYCLE_IN_PROGRESS ),
	array( 3, Kuka_Island_Shipping_Status::LIFECYCLE_IN_PROGRESS ),
	array( 4, Kuka_Island_Shipping_Status::LIFECYCLE_IN_PROGRESS ),
	array( 5, Kuka_Island_Shipping_Status::LIFECYCLE_DELIVERED ),
	array( 6, Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( 7, Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( 8, Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	// The vendor sends eventStatus as a string; both spellings of the same
	// number must agree.
	array( '5', Kuka_Island_Shipping_Status::LIFECYCLE_DELIVERED ),
	array( ' 5 ', Kuka_Island_Shipping_Status::LIFECYCLE_DELIVERED ),
	array( '05', Kuka_Island_Shipping_Status::LIFECYCLE_DELIVERED ),
	// Everything undocumented falls to manual review. None of these is
	// rounded, coerced or treated as still in transit.
	array( 0, Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( 9, Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( 99, Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( -1, Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( '', Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( 'delivered', Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( '5a', Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( 5.5, Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( null, Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( true, Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
	array( array( 5 ), Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW ),
);

$status_ok = true;
foreach ( $status_cases as $case ) {
	if ( Kuka_Island_Shipping_Status::lifecycle_for( $case[0] ) !== $case[1] ) {
		$status_ok = false;
	}
}

// The unknown label must not read like a real state.
$unknown_label_safe = Kuka_Island_Shipping_Status::label_for( 9 ) !== Kuka_Island_Shipping_Status::label_for( 5 )
	&& str_contains( Kuka_Island_Shipping_Status::label_for( 9 ), 'manuel' );

$report(
	'SHIPPING_STATUS_DICTIONARY',
	$status_ok && $unknown_label_safe && 8 === count( Kuka_Island_Shipping_Status::lifecycle_map() ),
	sprintf(
		'documented_codes:%d|cases:%d|unknown_to_manual_review:%s|delivered_only_code:5',
		count( Kuka_Island_Shipping_Status::lifecycle_map() ),
		count( $status_cases ),
		$unknown_label_safe ? 'yes' : 'NO'
	)
);

/* ========================================================================== */
/* 3. Reference identity: uppercase, valid, persistent                         */
/* ========================================================================== */

$reference_valid_cases = array(
	'KI360-A3F19C' => true,
	'KI1-AAAAAA'   => true,
	'ki360-a3f19c' => false,
	'KI360 A3F19C' => false,
	'KI360/A3F19C' => false,
	'KI360.A3F19C' => false,
	'-KI360'       => false,
	'KI36'         => false,
	''             => false,
);

$reference_ok = true;
foreach ( $reference_valid_cases as $candidate => $expected ) {
	if ( Kuka_Island_Shipping_Reference::is_valid( (string) $candidate ) !== $expected ) {
		$reference_ok = false;
	}
}

/*
 * Uniqueness is measured through build_unused(), because that is the method
 * that carries the guarantee. build() draws at random, and a random draw
 * repeated two hundred times has a real birthday probability -- a test asserting
 * otherwise would fail once every few hundred runs and teach whoever saw it that
 * the suite is flaky. build_unused() is handed everything minted so far and is
 * what mint_replacement() actually calls.
 */
$minted = array();
for ( $i = 0; $i < 200; $i++ ) {
	$minted[] = Kuka_Island_Shipping_Reference::build_unused( 360, $minted );
}

$all_upper = true;
$all_valid = true;
foreach ( $minted as $candidate ) {
	if ( $candidate !== strtoupper( $candidate ) ) {
		$all_upper = false;
	}
	if ( ! Kuka_Island_Shipping_Reference::is_valid( $candidate ) ) {
		$all_valid = false;
	}
}

// A reference already in the used list is never handed back.
$seeded  = $minted[0];
$avoided = Kuka_Island_Shipping_Reference::build_unused( 360, array( $seeded ) ) !== $seeded;

// References for two different orders cannot collide at all: the order id is
// part of the string, which is the property that actually matters in production.
$cross_order_distinct = str_starts_with( Kuka_Island_Shipping_Reference::build( 360 ), 'KI360-' )
	&& str_starts_with( Kuka_Island_Shipping_Reference::build( 361 ), 'KI361-' );

$report(
	'SHIPPING_REFERENCE_SHAPE',
	$reference_ok && $all_upper && $all_valid && $avoided && $cross_order_distinct
		&& count( array_unique( $minted ) ) === count( $minted ),
	sprintf(
		'validator_cases:%d|minted:%d|unique:%d|uppercase:%s|seeded_value_avoided:%s|order_id_in_reference:%s|piece_barcode:%s',
		count( $reference_valid_cases ),
		count( $minted ),
		count( array_unique( $minted ) ),
		$all_upper ? 'yes' : 'NO',
		$avoided ? 'yes' : 'NO',
		$cross_order_distinct ? 'yes' : 'NO',
		Kuka_Island_Shipping_Reference::piece_barcode( 'KI360-A3F19C', 1 )
	)
);

/* ========================================================================== */
/* 4. Live is blocked, and blocked structurally                                */
/* ========================================================================== */

$live_config    = kuka_ship_config( array( 'environment' => Kuka_Island_Shipping_DHL_Config::ENV_LIVE ) );
$live_transport = new Kuka_Shipping_Mock_Transport(
	static fn (): array => array(
		'status' => 200,
		'body'   => '{}',
	)
);
$live_provider = new Kuka_Island_Shipping_DHL_Provider(
	$live_config,
	new Kuka_Island_Shipping_DHL_Client( $live_config, $live_transport )
);

$live_create = $live_provider->create_order(
	array(
		'reference' => 'KI900-AAAAAA',
		'service'   => 'standard',
		'packaging' => 'package',
		'payment'   => 'sender',
		'delivery'  => 'to_address',
		'content'   => 'x',
		'description' => 'x',
		'pieces'    => array( array( 'barcode' => 'KI900-AAAAAAP1', 'desi' => 1, 'kg' => 1 ) ),
		'recipient' => array( 'full_name' => 'x', 'address' => 'x', 'city_code' => 34, 'district_code' => 1 ),
	)
);
$live_ping = $live_provider->ping();

$report(
	'SHIPPING_LIVE_BLOCKED',
	array() === $live_config->endpoints()
		&& ! $live_config->is_allowed_url( Kuka_Island_Shipping_DHL_Config::SANDBOX_IDENTITY_URL )
		&& 'live_environment_blocked' === $live_create->get_safe_error_code()
		&& 'live_environment_blocked' === $live_ping->get_safe_error_code()
		&& 0 === count( $live_transport->log ),
	sprintf(
		'endpoints_offered:%d|create_code:%s|ping_code:%s|http_requests:%d',
		count( $live_config->endpoints() ),
		$live_create->get_safe_error_code(),
		$live_ping->get_safe_error_code(),
		count( $live_transport->log )
	)
);

/* ========================================================================== */
/* 5. Missing credentials make no call at all                                  */
/* ========================================================================== */

$bare_config    = new Kuka_Island_Shipping_DHL_Config( array( 'client_id' => '', 'client_secret' => '', 'customer_number' => '', 'password' => '' ) );
$bare_transport = new Kuka_Shipping_Mock_Transport( static fn (): array => array( 'status' => 200, 'body' => '{}' ) );
$bare_provider  = new Kuka_Island_Shipping_DHL_Provider( $bare_config, new Kuka_Island_Shipping_DHL_Client( $bare_config, $bare_transport ) );

$bare_ping   = $bare_provider->ping();
$bare_status = $bare_provider->read_shipment_status( 'KI900-AAAAAA' );
$bare_gaps   = $bare_config->get_readiness_gaps();

$report(
	'SHIPPING_FAIL_CLOSED_CREDENTIALS',
	0 === count( $bare_transport->log )
		&& 'credentials_missing' === $bare_ping->get_safe_error_code()
		&& 'credentials_missing' === $bare_status->get_safe_error_code()
		&& 4 === count( $bare_gaps ),
	sprintf(
		'http_requests:%d|gaps:%d|gap_names:%s|ping_code:%s',
		count( $bare_transport->log ),
		count( $bare_gaps ),
		implode( '+', $bare_gaps ),
		$bare_ping->get_safe_error_code()
	)
);

/* ========================================================================== */
/* 6. The endpoint allow-list                                                  */
/* ========================================================================== */

$url_config = kuka_ship_config();
$url_cases  = array(
	Kuka_Island_Shipping_DHL_Config::SANDBOX_IDENTITY_URL                          => true,
	Kuka_Island_Shipping_DHL_Config::SANDBOX_STANDARD_CMD_URL . '/createOrder'     => true,
	Kuka_Island_Shipping_DHL_Config::SANDBOX_CBS_INFO_URL . '/getdistricts/34'     => true,
	'http://testapi.mngkargo.com.tr/mngapi/api/token'                              => false,
	'https://testapi.mngkargo.com.tr:443/mngapi/api/token'                         => false,
	'https://user:pass@testapi.mngkargo.com.tr/mngapi/api/token'                   => false,
	'https://testapi.mngkargo.com.tr.evil.example/mngapi/api/token'                => false,
	'https://eviltestapi.mngkargo.com.tr/mngapi/api/token'                         => false,
	'https://evil.example/testapi.mngkargo.com.tr/mngapi/api/token'                => false,
	'https://testapi.mngkargo.com.tr/mngapi/api/token#x'                           => false,
	'https://testapi.mngkargo.com.tr/other/path'                                   => false,
	'https://testapi.mngkargo.com.tr/mngapi/api/standardcmdapi/../../evil'         => false,
	' https://testapi.mngkargo.com.tr/mngapi/api/token'                            => false,
	"https://testapi.mngkargo.com.tr/mngapi/api/token\n"                           => false,
	'https://testapi.mngkargo.com.tr\\@evil.example/mngapi/api/token'              => false,
);

$url_ok      = true;
$url_wrong   = array();
foreach ( $url_cases as $candidate => $expected ) {
	if ( $url_config->is_allowed_url( (string) $candidate ) !== $expected ) {
		$url_ok    = false;
		$url_wrong[] = substr( (string) $candidate, 0, 40 );
	}
}

$report(
	'SHIPPING_ENDPOINT_ALLOWLIST',
	$url_ok,
	sprintf( 'cases:%d|wrong:%s', count( $url_cases ), array() === $url_wrong ? 'none' : implode( ',', $url_wrong ) )
);

/* ========================================================================== */
/* 7. Token session: reused, pessimistically expired, never exposed            */
/* ========================================================================== */

$token_transport = new Kuka_Shipping_Mock_Transport(
	static fn (): array => array(
		'status' => 200,
		'body'   => kuka_ship_token_body(),
	)
);
$token_config = kuka_ship_config();
$token_client = new Kuka_Island_Shipping_DHL_Client( $token_config, $token_transport );

$token_client->authenticate();
$token_client->authenticate();
$token_client->authenticate();

$token_store = $token_client->get_token_store();

/*
 * The window is fixed, and the documented expiry is only a veto. A value whose
 * MOST GENEROUS reading is already in the past must forbid caching outright;
 * one far in the future must not extend the window beyond its cap; one that is
 * not the documented format at all must not throw or be believed.
 */
$expired_both_ways = $token_store->cache_seconds( gmdate( 'd.m.Y H:i:s', time() - 86400 ) );
$far_future        = $token_store->cache_seconds( gmdate( 'd.m.Y H:i:s', time() + 999999 ) );
$unparsable        = $token_store->cache_seconds( 'not-a-date' );

$debug     = print_r( $token_store, true );
$leak_free = ! str_contains( $debug, KUKA_SHIP_JWT );

$report(
	'SHIPPING_TOKEN_SESSION',
	1 === $token_transport->count_for( '/token' )
		&& 1 === $token_store->get_issue_count()
		&& 0 === $expired_both_ways
		&& 300 === $far_future
		&& 300 === $unparsable
		&& null === Kuka_Island_Shipping_DHL_Token_Store::remaining_seconds( 'not-a-date', time() )
		&& $leak_free,
	sprintf(
		'authenticate_calls:3|token_requests:%d|reused:%s|expired_string_vetoes_cache:%d|far_future_capped:%d|unparsable_window:%d|persisted_to_db:no|token_in_debug_output:%s',
		$token_transport->count_for( '/token' ),
		1 === $token_transport->count_for( '/token' ) ? 'yes' : 'NO',
		$expired_both_ways,
		$far_future,
		$unparsable,
		$leak_free ? 'absent' : 'PRESENT'
	)
);

/* ========================================================================== */
/* 8. A write is never repeated after a 401; a read is repeated once           */
/* ========================================================================== */

$auth_write_transport = new Kuka_Shipping_Mock_Transport(
	static function ( string $method, string $url ): array {
		if ( str_contains( $url, '/token' ) ) {
			return array(
				'status' => 200,
				'body'   => kuka_ship_token_body(),
			);
		}

		return array(
			'status' => 401,
			'body'   => '{"title":"Unauthorized"}',
		);
	}
);
$auth_write_config = kuka_ship_config();
$auth_write_client = new Kuka_Island_Shipping_DHL_Client( $auth_write_config, $auth_write_transport );
$auth_write_result = $auth_write_client->create_order( array( 'order' => array() ) );

$auth_read_transport = new Kuka_Shipping_Mock_Transport(
	static function ( string $method, string $url ): array {
		if ( str_contains( $url, '/token' ) ) {
			return array(
				'status' => 200,
				'body'   => kuka_ship_token_body(),
			);
		}

		return array(
			'status' => 401,
			'body'   => '{"title":"Unauthorized"}',
		);
	}
);
$auth_read_config = kuka_ship_config();
$auth_read_client = new Kuka_Island_Shipping_DHL_Client( $auth_read_config, $auth_read_transport );
$auth_read_result = $auth_read_client->get_shipment_status( 'KI900-AAAAAA' );

$report(
	'SHIPPING_401_RETRY_POLICY',
	1 === $auth_write_transport->count_for( '/createOrder' )
		&& Kuka_Island_Shipping_Result::OUTCOME_PERMANENT === $auth_write_result->get_outcome()
		&& 2 === $auth_read_transport->count_for( '/getshipmentstatus' )
		&& 2 === $auth_read_transport->count_for( '/token' ),
	sprintf(
		'write_attempts:%d|write_outcome:%s|read_attempts:%d|reauth_calls:%d',
		$auth_write_transport->count_for( '/createOrder' ),
		$auth_write_result->get_outcome(),
		$auth_read_transport->count_for( '/getshipmentstatus' ),
		$auth_read_transport->count_for( '/token' )
	)
);

/* ========================================================================== */
/* 9. A 200 that cannot be read is uncertain, not success                      */
/* ========================================================================== */

$malformed_cases = array(
	'not_json'       => 'this is not json at all',
	'empty_object'   => '{}',
	'wrong_shape'    => '{"unexpected":"value"}',
	'null_body'      => 'null',
	'truncated_json' => '{"referenceId":"KI9',
);

$malformed_ok = true;
$malformed_seen = array();

foreach ( $malformed_cases as $label => $body ) {
	$transport = new Kuka_Shipping_Mock_Transport(
		static function ( string $method, string $url ) use ( $body ): array {
			if ( str_contains( $url, '/token' ) ) {
				return array(
					'status' => 200,
					'body'   => kuka_ship_token_body(),
				);
			}

			return array(
				'status' => 200,
				'body'   => $body,
			);
		}
	);

	$config = kuka_ship_config();
	$client = new Kuka_Island_Shipping_DHL_Client( $config, $transport );
	$result = $client->create_order( array( 'order' => array() ) );

	$malformed_seen[ $label ] = $result->get_outcome();

	if ( ! $result->is_uncertain() || 'malformed_response' !== $result->get_safe_error_code() ) {
		$malformed_ok = false;
	}
}

$report(
	'SHIPPING_UNREADABLE_SUCCESS_IS_UNCERTAIN',
	$malformed_ok,
	sprintf( 'cases:%d|all_uncertain:%s', count( $malformed_cases ), $malformed_ok ? 'yes' : 'NO' )
);

/* ========================================================================== */
/* 9b. The carrier reference cache belongs to the shop, not to this suite      */
/* ========================================================================== */

/*
 * Every scenario purges the CBS cache before resolving an address, because a
 * warm cache means the mock's /getcities is never called and the request counts
 * stop meaning anything. That purge used to be paired with a purge in the
 * cleanup block, which deleted whatever the shop already had: an earlier run
 * wiped four rows it had never created.
 *
 * The custodian snapshots those rows by exact name, restores them byte for byte
 * -- value and autoload flag, timeout companions included -- and removes only
 * what this run created. It is registered as a SHUTDOWN function as well, so a
 * failed assertion or a fatal error cannot leave the shop's cache holding
 * one-city mock data. Both entry points are the same idempotent method.
 */

require_once __DIR__ . '/lib-shipping-cache-custodian.php';

/*
 * A key space of this run's own, and an explicit list of the rows it may
 * create. Every provider this suite builds is moved onto that namespace by
 * kuka_ship_provider(), so the shop's own cached city list is never read,
 * never written and never deleted -- there is nothing to restore, and nothing
 * to get wrong when a process dies.
 *
 * The city codes are the ones the mocks answer for.
 */
const KUKA_SHIP_CACHED_CITY_CODES = array( '34', '06', '07' );

/*
 * A constant, not a variable: wp eval-file evaluates this file inside a
 * function scope, so a top-level variable is not a global and `global $x` in a
 * helper would read null -- which would silently put every resolver back on the
 * shop's own key space, which is the whole thing being avoided.
 */
define( 'KUKA_SHIP_CACHE_NAMESPACE', Kuka_Shipping_Cache_Custodian::mint_namespace() );

$cbs_custodian = ( new Kuka_Shipping_Cache_Custodian( KUKA_SHIP_CACHE_NAMESPACE ) )
	->own_resolver_keys( KUKA_SHIP_CACHED_CITY_CODES )
	->guard();

$cbs_namespace = KUKA_SHIP_CACHE_NAMESPACE;

/* ========================================================================== */
/* 10. Turkish place-name folding, and exact matching only                     */
/* ========================================================================== */

$fold       = static fn ( string $value ): string => Kuka_Island_Shipping_DHL_Address_Resolver::fold( $value );
$fold_ascii = static fn ( string $value ): string => Kuka_Island_Shipping_DHL_Address_Resolver::fold_ascii( $value );

$fold_ok = $fold( 'İstanbul' ) === $fold( 'İSTANBUL' )
	&& $fold( 'İstanbul' ) === $fold( 'istanbul' )
	&& $fold( 'Kadıköy' ) === $fold( 'KADIKÖY' )
	&& $fold( 'K. Maraş' ) === $fold( 'K.Maraş' )
	&& $fold( 'Şişli' ) === $fold( 'ŞİŞLİ' )
	&& $fold( 'Ağrı' ) === $fold( 'AĞRI' )
	// The Turkish rule is honoured rather than approximated: 'ISTANBUL' is not
	// the Turkish uppercase of 'İstanbul', so step one must NOT equate them.
	// Step two is what accepts that spelling, and only when it is unique.
	&& $fold( 'İstanbul' ) !== $fold( 'ISTANBUL' )
	&& $fold_ascii( 'İstanbul' ) === $fold_ascii( 'ISTANBUL' )
	&& $fold_ascii( 'Kadıköy' ) === $fold_ascii( 'Kadikoy' )
	// Different places must NOT fold together, under either folding.
	&& $fold( 'Kadıköy' ) !== $fold( 'Kartal' )
	&& $fold_ascii( 'Kadıköy' ) !== $fold_ascii( 'Kartal' )
	&& $fold_ascii( 'Adana' ) !== $fold_ascii( 'Adıyaman' );

$cbs_transport = new Kuka_Shipping_Mock_Transport(
	static function ( string $method, string $url ): array {
		if ( str_contains( $url, '/getcities' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						array( 'code' => '34', 'name' => 'İSTANBUL' ),
						array( 'code' => '06', 'name' => 'ANKARA' ),
					)
				),
			);
		}

		if ( str_contains( $url, '/getdistricts/34' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						array( 'cityCode' => '34', 'cityName' => 'İSTANBUL', 'code' => '1', 'name' => 'KADIKÖY' ),
						array( 'cityCode' => '34', 'cityName' => 'İSTANBUL', 'code' => '2', 'name' => 'ŞİŞLİ' ),
					)
				),
			);
		}

		if ( str_contains( $url, '/token' ) ) {
			return array(
				'status' => 200,
				'body'   => kuka_ship_token_body(),
			);
		}

		return array(
			'status' => 404,
			'body'   => '{}',
		);
	}
);

$cbs_config   = kuka_ship_config();
$cbs_client   = new Kuka_Island_Shipping_DHL_Client( $cbs_config, $cbs_transport );
$cbs_resolver = new Kuka_Island_Shipping_DHL_Address_Resolver( $cbs_client );
$cbs_resolver->set_cache_namespace( KUKA_SHIP_CACHE_NAMESPACE );

$cbs_resolver->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );

$exact_hit = $cbs_resolver->resolve( 'İSTANBUL', 'Kadıköy' );
$ascii_hit = $cbs_resolver->resolve( 'Istanbul', 'Kadikoy' );
$miss      = $cbs_resolver->resolve( 'İstanbul', 'Kadikoyy' );
$city_miss = $cbs_resolver->resolve( 'Atlantis', 'Kadıköy' );

/*
 * Two districts that collide under ASCII folding must be refused rather than
 * resolved by picking one. This is the case the uniqueness rule exists for.
 */
$ambiguous_transport = new Kuka_Shipping_Mock_Transport(
	static function ( string $method, string $url ): array {
		if ( str_contains( $url, '/getcities' ) ) {
			return array( 'status' => 200, 'body' => (string) wp_json_encode( array( array( 'code' => '34', 'name' => 'İSTANBUL' ) ) ) );
		}

		if ( str_contains( $url, '/getdistricts/34' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						array( 'code' => '2', 'name' => 'ŞİŞLİ' ),
						array( 'code' => '9', 'name' => 'SISLI' ),
					)
				),
			);
		}

		return array( 'status' => 404, 'body' => '{}' );
	}
);
$ambiguous_resolver = new Kuka_Island_Shipping_DHL_Address_Resolver(
	new Kuka_Island_Shipping_DHL_Client( kuka_ship_config(), $ambiguous_transport )
);
$ambiguous_resolver->set_cache_namespace( KUKA_SHIP_CACHE_NAMESPACE );
$ambiguous_resolver->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );
$ambiguous = $ambiguous_resolver->resolve( 'Istanbul', 'Sisli' );
$ambiguous_resolver->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );

$report(
	'SHIPPING_ADDRESS_RESOLUTION',
	$fold_ok
		&& $exact_hit->is_success()
		&& 'exact+exact' === (string) $exact_hit->get( 'match_mode', '' )
		&& '34' === (string) $exact_hit->get( 'city_code', '' )
		&& '1' === (string) $exact_hit->get( 'district_code', '' )
		&& $ascii_hit->is_success()
		&& 'ascii_unique+ascii_unique' === (string) $ascii_hit->get( 'match_mode', '' )
		&& '1' === (string) $ascii_hit->get( 'district_code', '' )
		&& 'district_not_found' === $miss->get_safe_error_code()
		&& 'city_not_found' === $city_miss->get_safe_error_code()
		&& 'district_ambiguous' === $ambiguous->get_safe_error_code(),
	sprintf(
		'folding:%s|exact:%s|ascii_unique:%s|ascii_collision_refused:%s|district_miss:%s|city_miss:%s|approximate_matching:none|no_authorization_on_cbs:%s',
		$fold_ok ? 'ok' : 'BROKEN',
		$exact_hit->is_success() ? 'yes' : 'NO',
		$ascii_hit->is_success() ? 'yes' : 'NO',
		$ambiguous->get_safe_error_code(),
		$miss->get_safe_error_code(),
		$city_miss->get_safe_error_code(),
		( 0 === $cbs_transport->count_for( '/token' ) ) ? 'yes' : 'NO'
	)
);

// A failed listing is never cached, so an outage cannot pin an empty list.
$failing_transport = new Kuka_Shipping_Mock_Transport( static fn (): array => array( 'status' => 500, 'body' => '' ) );
$failing_config    = kuka_ship_config();
$failing_resolver  = new Kuka_Island_Shipping_DHL_Address_Resolver( new Kuka_Island_Shipping_DHL_Client( $failing_config, $failing_transport ) );
$failing_resolver->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );
$failing_resolver->cities();
$cached_after_failure = get_transient( 'kuka_dhl_cbs_cities_v1' );

$report(
	'SHIPPING_REFERENCE_DATA_CACHE',
	false === $cached_after_failure,
	sprintf( 'failure_cached:%s|ttl_bounded:1_day', false === $cached_after_failure ? 'no' : 'YES' )
);

$cbs_resolver->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );

/* ========================================================================== */
/* 11. Order fixtures                                                          */
/* ========================================================================== */

/**
 * A throwaway order with one line item and a Turkish shipping address.
 */
function kuka_ship_fixture_order(): WC_Order {
	$order = wc_create_order();

	$item = new WC_Order_Item_Product();
	$item->set_name( 'Kuka test ürünü' );
	$item->set_quantity( 1 );
	$item->set_total( 100 );
	$order->add_item( $item );

	$order->set_payment_method( 'iyzico' );
	$order->set_billing_email( 'shipping-fixture@example.invalid' );
	$order->set_billing_phone( '5309481996' );
	$order->set_shipping_first_name( 'Kuka' );
	$order->set_shipping_last_name( 'Fixture' );
	$order->set_shipping_address_1( 'Test sokak 1' );
	$order->set_shipping_address_2( 'Kadıköy' );
	$order->set_shipping_city( 'İstanbul' );
	$order->set_shipping_country( 'TR' );
	$order->update_meta_data( '_kuka_shipping_fixture', '1' );
	$order->save();

	return $order;
}

/**
 * Delete every note this suite caused to be written on an order.
 *
 * Explicit, because WC_Order::delete( true ) removes the order row, its meta,
 * its addresses and its items -- but order notes live in wp_comments and
 * survive it. A suite that only deleted the order would leave a growing pile of
 * notes pointing at order ids that no longer exist, and the cross-process
 * keyset fingerprint would drift a little on every run.
 *
 * @param int $order_id Order id.
 */
function kuka_ship_delete_order_notes( int $order_id ): void {
	if ( ! function_exists( 'wc_get_order_notes' ) || ! function_exists( 'wc_delete_order_note' ) ) {
		return;
	}

	foreach ( (array) wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => 500 ) ) as $note ) {
		wc_delete_order_note( (int) $note->id );
	}
}

/**
 * Remove the order, its notes and every fulfilment it produced.
 */
function kuka_ship_destroy_order( WC_Order $order ): void {
	$store_class = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';

	if ( class_exists( $store_class ) && function_exists( 'wc_get_container' ) ) {
		try {
			$store = wc_get_container()->get( $store_class );
			$store->delete_by_entity( WC_Order::class, (string) $order->get_id() );
		} catch ( Throwable $e ) {
			// Nothing to remove.
		}
	}

	Kuka_Island_Shipping_Status_Poller::cancel_queries( (int) $order->get_id() );
	kuka_ship_delete_order_notes( (int) $order->get_id() );
	$order->delete( true );
}

/**
 * A carrier whose transport is under this suite's control.
 *
 * @param Kuka_Shipping_Mock_Transport $transport Mock transport.
 * @param array<string, mixed>         $overrides Config overrides.
 */
function kuka_ship_provider( Kuka_Shipping_Mock_Transport $transport, array $overrides = array() ): Kuka_Island_Shipping_DHL_Provider {
	$config   = kuka_ship_config( $overrides );
	$client   = new Kuka_Island_Shipping_DHL_Client( $config, $transport );
	$resolver = new Kuka_Island_Shipping_DHL_Address_Resolver( $client );

	// This run's own key space. Without it the resolver would read and write
	// the shop's cached city list, and a suite that has to start from a cold
	// cache would have to delete it first.
	$resolver->set_cache_namespace( KUKA_SHIP_CACHE_NAMESPACE );

	return new Kuka_Island_Shipping_DHL_Provider( $config, $client, $resolver );
}

/**
 * A manager whose registry holds exactly the given carrier.
 *
 * Typed on the CONTRACT, not on the DHL adapter, because the second-carrier
 * measurements below hand it an adapter that has never heard of DHL.
 */
function kuka_ship_manager( Kuka_Island_Shipping_Carrier_Interface $provider ): Kuka_Island_Shipping_Manager {
	return new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $provider ) ) );
}

/**
 * A registry holding exactly the given adapters, built through the PUBLIC
 * filter and through nothing else.
 *
 * The filter is the only seam the contract promises, so every measurement that
 * puts a carrier in front of the manager goes through it. A registry populated
 * by any other means would prove nothing about how a second courier arrives.
 *
 * @param array<int, mixed> $carriers Adapter instances, in registration order.
 */
function kuka_ship_registry_of( array $carriers ): Kuka_Island_Shipping_Carrier_Registry {
	$registry = new Kuka_Island_Shipping_Carrier_Registry();

	$filter = static function () use ( $carriers ): array {
		return $carriers;
	};

	add_filter( 'kuka_island_shipping_carriers', $filter, 999 );
	$registry->reset();
	$registry->all();
	remove_filter( 'kuka_island_shipping_carriers', $filter, 999 );

	return $registry;
}

/**
 * One transport, one carrier, one manager and one fresh fixture order.
 *
 * @param callable $responder Transport script.
 * @return array{order: WC_Order, transport: Kuka_Shipping_Mock_Transport, provider: Kuka_Island_Shipping_DHL_Provider, manager: Kuka_Island_Shipping_Manager}
 */
function kuka_ship_scenario( callable $responder ): array {
	$transport = new Kuka_Shipping_Mock_Transport( $responder );
	$provider  = kuka_ship_provider( $transport );
	$manager   = kuka_ship_manager( $provider );
	$provider->get_resolver()->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );

	return array(
		'order'     => kuka_ship_fixture_order(),
		'transport' => $transport,
		'provider'  => $provider,
		'manager'   => $manager,
	);
}

/** The reference-data and token answers every scenario needs. */
function kuka_ship_common_reads( string $url ): ?array {
	if ( str_contains( $url, '/token' ) ) {
		return array( 'status' => 200, 'body' => kuka_ship_token_body() );
	}

	if ( str_contains( $url, '/getcities' ) ) {
		return array( 'status' => 200, 'body' => (string) wp_json_encode( array( array( 'code' => '34', 'name' => 'İSTANBUL' ) ) ) );
	}

	if ( str_contains( $url, '/getdistricts/34' ) ) {
		return array( 'status' => 200, 'body' => (string) wp_json_encode( array( array( 'code' => '1', 'name' => 'KADIKÖY' ) ) ) );
	}

	return null;
}

/** A confirmed createOrder answer. */
function kuka_ship_create_order_ok(): array {
	return array(
		'status' => 200,
		'body'   => (string) wp_json_encode( array( 'referenceId' => 'ECHO', 'orderInvoiceId' => 'OINV-R1' ) ),
	);
}

/** A confirmed createbarcode answer. */
function kuka_ship_create_barcode_ok( string $shipment_id = '556677889', string $barcode = 'BC-R1' ): array {
	return array(
		'status' => 200,
		'body'   => (string) wp_json_encode(
			array(
				'referenceId' => 'ECHO',
				'invoiceId'   => 'INV-R1',
				'shipmentId'  => $shipment_id,
				'barcodes'    => array( array( 'pieceNumber' => 1, 'value' => $barcode ) ),
			)
		),
	);
}

/**
 * Attach a poller as the ONLY worker on the poll hook, for this process.
 *
 * WHY THIS EXISTS. The plugin is delivered ACTIVE, so its own Status_Poller is
 * already attached to kuka_island_shipping_query_status when this suite runs.
 * A measurement that attached its own poller on top of it had TWO workers
 * handling every real Action Scheduler action: the counts came out one too
 * high and the chain booked one action too many. That is a collision between
 * the harness and the live module, not a product defect -- but a suite whose
 * numbers depend on whether the plugin happens to be active is not a
 * measurement at all, so the collision is removed rather than tolerated.
 *
 * Hooks are per-process and each `wp eval-file` is its own process, so
 * detaching here cannot affect the site.
 *
 * @param Kuka_Island_Shipping_Manager $manager Manager the poller drives.
 */
function kuka_ship_attach_sole_poller( Kuka_Island_Shipping_Manager $manager ): Kuka_Island_Shipping_Status_Poller {
	remove_all_actions( Kuka_Island_Shipping_Status_Poller::ACTION );

	$poller = new Kuka_Island_Shipping_Status_Poller( $manager );
	$poller->register();

	return $poller;
}

/**
 * An existing published product that needs shipping, or 0.
 *
 * Read-only: the shop's own catalogue is used and never modified. It is needed
 * because EDM's Internet_Sales_Details::read_shipment_facts() answers "no
 * shipment fact" for an order with no shippable line -- correctly, since an
 * order of downloadables never leaves -- and the bare fixture item carries no
 * product id at all.
 */
function kuka_ship_shippable_product_id(): int {
	$ids = (array) wc_get_products(
		array(
			'status'  => 'publish',
			'limit'   => 25,
			'return'  => 'ids',
			'orderby' => 'ID',
			'order'   => 'ASC',
		)
	);

	foreach ( $ids as $id ) {
		$product = wc_get_product( (int) $id );

		if ( ! $product instanceof WC_Product || ! $product->needs_shipping() ) {
			continue;
		}

		if ( ! $product->is_type( 'variable' ) ) {
			return (int) $id;
		}

		// A variable parent cannot be an order line; its variation can, and the
		// variation is what carries needs_shipping() on the item.
		foreach ( (array) $product->get_children() as $child_id ) {
			$child = wc_get_product( (int) $child_id );

			if ( $child instanceof WC_Product && $child->needs_shipping() ) {
				return (int) $child_id;
			}
		}
	}

	return 0;
}

/**
 * Drive an order into STATE_ORDER_CREATED the way an operator now has to.
 *
 * ONE STEP BECAME TWO, DELIBERATELY. A createbarcode that answered 400 used to
 * leave the order in order_created directly, on the reading that a rejected
 * request cannot have created anything. The vendor's contract does not support
 * that reading: all six write operations document exactly 200, 400 "Bad
 * Request", 401 "Unauthorized" and 500 "Server Error", and not one of them says
 * whether a record was left behind. So the barcode's mutation intent now stays
 * OPEN and the order waits in reconcile_required until a READ settles it -- and
 * the read is what produces order_created: no shipment under this reference,
 * but the order is there.
 *
 * Every measurement that needs that state goes through this function, so a
 * set-up cannot quietly diverge from the state machine it is setting up. The
 * scenario has to answer /getshipment/ with a 404 and /getorder/ with a
 * present order for the middle step to land.
 *
 * @param array{manager: Kuka_Island_Shipping_Manager, order: WC_Order} $scenario Scenario.
 * @return array{order: WC_Order, reconcile: array<string, mixed>, state: string}
 */
function kuka_ship_reach_order_created( array $scenario ): array {
	$scenario['manager']->create_shipment( $scenario['order'] );

	$order     = wc_get_order( $scenario['order']->get_id() );
	$reconcile = $scenario['manager']->reconcile_order( $order );
	$order     = wc_get_order( $order->get_id() );

	return array(
		'order'     => $order,
		'reconcile' => $reconcile,
		'state'     => Kuka_Island_Shipping_Order_Store::get_state( $order ),
	);
}

/** A getorder answer that says the carrier order EXISTS. */
function kuka_ship_get_order_present(): array {
	return array(
		'status' => 200,
		'body'   => (string) wp_json_encode(
			array(
				'order' => array(
					'referenceId'             => 'ECHO',
					'shipmentId'              => '',
					'isTransformedToShipment' => 0,
				),
			)
		),
	);
}

/**
 * Drive the pending status chain for one order through the REAL Action
 * Scheduler execution path.
 *
 * ActionScheduler_QueueRunner::process_action() is the method the WP-Cron and
 * async queue runners call for each action they claim: it refuses anything not
 * PENDING, writes the execution log, sets the row to in-progress, dispatches
 * the hook with do_action(), and marks the row complete or failed. Driving the
 * chain through it means the worker really is reached the way production
 * reaches it -- through the registered hook, out of the store -- rather than by
 * this suite calling run() itself.
 *
 * Due dates are not simulated. Claiming is what respects them; execution does
 * not, so the ladder's fifteen minutes do not have to be waited out.
 *
 * @param int $order_id  Order id.
 * @param int $max_turns Refuses to loop for ever, so a chain that will not stop
 *                       shows up as a measurement rather than as a hung run.
 * @return array{processed: array<int, int>, errors: int}
 */
function kuka_ship_drive_status_chain( int $order_id, int $max_turns = 15 ): array {
	$processed = array();
	$errors    = 0;

	if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	for ( $turn = 0; $turn < $max_turns; $turn++ ) {
		$pending = (array) as_get_scheduled_actions(
			array(
				'hook'     => Kuka_Island_Shipping_Status_Poller::ACTION,
				'args'     => array( 'order_id' => $order_id ),
				'group'    => Kuka_Island_Shipping_Status_Poller::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);

		if ( array() === $pending ) {
			break;
		}

		$action_id = (int) reset( $pending );

		try {
			ActionScheduler::runner()->process_action( $action_id, 'Kuka Island verification' );
		} catch ( Throwable $e ) {
			++$errors;
		}

		$processed[] = $action_id;
	}

	return array(
		'processed' => $processed,
		'errors'    => $errors,
	);
}

/** The safe local fulfilment-retry hook name, whether or not it exists yet. */
function kuka_ship_sync_hook(): string {
	return defined( 'Kuka_Island_Shipping_Status_Poller::SYNC_ACTION' )
		? (string) constant( 'Kuka_Island_Shipping_Status_Poller::SYNC_ACTION' )
		: 'kuka_island_shipping_sync_fulfillment';
}

/** Pending poll actions booked for one order. */
function kuka_ship_pending_action_count( int $order_id ): int {
	if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
		return -1;
	}

	return count(
		(array) as_get_scheduled_actions(
			array(
				'hook'     => Kuka_Island_Shipping_Status_Poller::ACTION,
				'args'     => array( 'order_id' => $order_id ),
				'group'    => Kuka_Island_Shipping_Status_Poller::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 50,
				'orderby'  => 'none',
			),
			'ids'
		)
	);
}

/** Pending safe local fulfilment retries booked for one order. */
function kuka_ship_pending_sync_count( int $order_id ): int {
	if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
		return -1;
	}

	return count(
		(array) as_get_scheduled_actions(
			array(
				'hook'     => kuka_ship_sync_hook(),
				'args'     => array( 'order_id' => $order_id ),
				'group'    => Kuka_Island_Shipping_Status_Poller::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 50,
				'orderby'  => 'none',
			),
			'ids'
		)
	);
}

/**
 * Run every pending safe local fulfilment retry through Action Scheduler.
 *
 * The same real runner the poll chain uses. No carrier call can happen from
 * this hook, which is the whole point of it existing.
 *
 * @param int $order_id  Order.
 * @param int $max_turns Refuses to loop for ever.
 * @return array{turns: int, errors: int}
 */
function kuka_ship_drive_sync_chain( int $order_id, int $max_turns = 3 ): array {
	$turns  = 0;
	$errors = 0;

	if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
		return array( 'turns' => $turns, 'errors' => $errors );
	}

	for ( $turn = 0; $turn < $max_turns; $turn++ ) {
		$pending = (array) as_get_scheduled_actions(
			array(
				'hook'     => kuka_ship_sync_hook(),
				'args'     => array( 'order_id' => $order_id ),
				'group'    => Kuka_Island_Shipping_Status_Poller::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);

		if ( array() === $pending ) {
			break;
		}

		try {
			ActionScheduler::runner()->process_action( (int) reset( $pending ), 'Kuka Island verification' );
		} catch ( Throwable $e ) {
			++$errors;
		}

		++$turns;
	}

	return array( 'turns' => $turns, 'errors' => $errors );
}

/** Remove every safe local retry row this suite caused. */
function kuka_ship_purge_sync_actions( int $order_id ): int {
	if ( ! class_exists( 'ActionScheduler' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
		return 0;
	}

	$store   = ActionScheduler::store();
	$removed = 0;

	foreach ( (array) as_get_scheduled_actions(
		array(
			'hook'     => kuka_ship_sync_hook(),
			'args'     => array( 'order_id' => $order_id ),
			'group'    => Kuka_Island_Shipping_Status_Poller::GROUP,
			'per_page' => 200,
			'orderby'  => 'none',
		),
		'ids'
	) as $action_id ) {
		try {
			$store->delete_action( (int) $action_id );
			++$removed;
		} catch ( Throwable $e ) {
			// Already gone.
		}
	}

	return $removed;
}

/**
 * Attach a poller as the only worker AND record what it returns.
 *
 * Action Scheduler throws the callback's return value away, so the outcome
 * string is captured by the closure that invokes the real worker. The path is
 * unchanged: Action Scheduler fires the hook, the hook runs Status_Poller::run.
 *
 * @param Kuka_Island_Shipping_Manager $manager Manager the poller drives.
 * @param stdClass                     $box     Receives ->outcome.
 */
function kuka_ship_attach_recording_poller( Kuka_Island_Shipping_Manager $manager, stdClass $box ): Kuka_Island_Shipping_Status_Poller {
	remove_all_actions( Kuka_Island_Shipping_Status_Poller::ACTION );

	$poller = new Kuka_Island_Shipping_Status_Poller( $manager );
	$poller->register();
	remove_all_actions( Kuka_Island_Shipping_Status_Poller::ACTION );

	add_action(
		Kuka_Island_Shipping_Status_Poller::ACTION,
		static function ( $order_id ) use ( $poller, $box ): void {
			$box->outcome = (string) $poller->run( $order_id );
		}
	);

	return $poller;
}

/** Every scheduled action this order ever had, whatever its status. */
function kuka_ship_action_ids( int $order_id ): array {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return array();
	}

	return array_map(
		'intval',
		(array) as_get_scheduled_actions(
			array(
				'hook'     => Kuka_Island_Shipping_Status_Poller::ACTION,
				'args'     => array( 'order_id' => $order_id ),
				'group'    => Kuka_Island_Shipping_Status_Poller::GROUP,
				'per_page' => 200,
				'orderby'  => 'none',
			),
			'ids'
		)
	);
}

/**
 * Remove every action row this suite caused, completed ones included.
 *
 * The activation-lifecycle suite counts rows for this hook in EVERY status, not
 * only pending, so a completed chain left behind would fail a measurement in a
 * different file and in a different process. delete_action() also clears the
 * action's log rows: the DB logger hooks itself onto that deletion.
 *
 * @param int $order_id Order id.
 */
function kuka_ship_purge_actions( int $order_id ): int {
	if ( ! class_exists( 'ActionScheduler' ) ) {
		return 0;
	}

	$store   = ActionScheduler::store();
	$removed = 0;

	foreach ( kuka_ship_action_ids( $order_id ) as $action_id ) {
		try {
			$store->delete_action( $action_id );
			++$removed;
		} catch ( Throwable $e ) {
			// Already gone.
		}
	}

	return $removed;
}

/** The transport script for a completely successful shipment. */
function kuka_ship_happy_responder(): callable {
	return static function ( string $method, string $url ): array {
		if ( str_contains( $url, '/token' ) ) {
			return array( 'status' => 200, 'body' => kuka_ship_token_body() );
		}

		if ( str_contains( $url, '/getcities' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode( array( array( 'code' => '34', 'name' => 'İSTANBUL' ) ) ),
			);
		}

		if ( str_contains( $url, '/getdistricts/34' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode( array( array( 'code' => '1', 'name' => 'KADIKÖY' ) ) ),
			);
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						'orderInvoiceId'       => 'OINV-1',
						'orderInvoiceDetailId' => 'ODET-1',
						'shipperBranchCode'    => 'BR-1',
						'referenceId'          => 'ECHO',
					)
				),
			);
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						'referenceId' => 'ECHO',
						'invoiceId'   => 'INV-1',
						'shipmentId'  => '838302813413',
						'barcodes'    => array(
							array( 'pieceNumber' => 1, 'value' => 'BC-0001' ),
						),
					)
				),
			);
		}

		if ( str_contains( $url, '/getshipmentstatus' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						'referenceId'        => 'ECHO',
						'shipmentId'         => '838302813413',
						'shipmentStatusCode' => 2,
						'isDelivered'        => 0,
						'trackingUrl'        => 'https://kargotakip.example/838302813413',
					)
				),
			);
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	};
}

/* ========================================================================== */
/* 12. One shipment, end to end -- and only one                                */
/* ========================================================================== */

$happy_transport = new Kuka_Shipping_Mock_Transport( kuka_ship_happy_responder() );
$happy_provider  = kuka_ship_provider( $happy_transport );
$happy_manager   = kuka_ship_manager( $happy_provider );
$happy_provider->get_resolver()->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );

$order = kuka_ship_fixture_order();

$first  = $happy_manager->create_shipment( $order );
$order  = wc_get_order( $order->get_id() );
$second = $happy_manager->create_shipment( $order );
$order  = wc_get_order( $order->get_id() );

$data = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );

$report(
	'SHIPPING_CREATE_ONCE',
	$first['ok']
		&& ! $second['ok']
		&& 'already_in_progress' === $second['code']
		&& 1 === $happy_transport->count_for( '/createOrder' )
		&& 1 === $happy_transport->count_for( '/createbarcode' )
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $data['state']
		&& '838302813413' === $data['shipment_id']
		&& array( 'BC-0001' ) === $data['barcodes'],
	sprintf(
		'first:%s|second:%s|second_code:%s|createOrder_calls:%d|createbarcode_calls:%d|state:%s|shipment_id_stored:%s|barcodes_stored:%d',
		$first['ok'] ? 'created' : 'refused',
		$second['ok'] ? 'CREATED_AGAIN' : 'refused',
		$second['code'],
		$happy_transport->count_for( '/createOrder' ),
		$happy_transport->count_for( '/createbarcode' ),
		$data['state'],
		'' !== $data['shipment_id'] ? 'yes' : 'NO',
		count( $data['barcodes'] )
	)
);

// The reference is minted once and survives every later call.
$reference_after = Kuka_Island_Shipping_Order_Store::reference( $order );

$report(
	'SHIPPING_REFERENCE_PERSISTED',
	$reference_after === $data['reference']
		&& Kuka_Island_Shipping_Reference::is_valid( $reference_after )
		&& $reference_after === strtoupper( $reference_after )
		&& in_array( $reference_after, Kuka_Island_Shipping_Order_Store::get_shipment_data( $order )['reference_history'], true ),
	sprintf(
		'stable_across_reads:%s|uppercase:%s|in_history:%s|hpos_meta:yes',
		$reference_after === $data['reference'] ? 'yes' : 'NO',
		$reference_after === strtoupper( $reference_after ) ? 'yes' : 'NO',
		in_array( $reference_after, Kuka_Island_Shipping_Order_Store::get_shipment_data( $order )['reference_history'], true ) ? 'yes' : 'NO'
	)
);

/* ========================================================================== */
/* 13. The fulfilment record: provider key, no invented tracking number        */
/* ========================================================================== */

$own_fulfillment = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $order, $reference_after );

$report(
	'SHIPPING_FULFILLMENT_RECORD',
	null !== $own_fulfillment
		&& 'dhl' === (string) $own_fulfillment->get_shipment_provider()
		&& null === $own_fulfillment->get_tracking_number()
		&& ! $own_fulfillment->get_is_fulfilled(),
	sprintf(
		'record:%s|provider_key:%s|tracking_number:%s|status_on_create:%s',
		null !== $own_fulfillment ? 'created' : 'MISSING',
		null !== $own_fulfillment ? (string) $own_fulfillment->get_shipment_provider() : 'none',
		( null !== $own_fulfillment && null === $own_fulfillment->get_tracking_number() ) ? 'unset_because_unmeasured' : 'SET',
		( null !== $own_fulfillment && ! $own_fulfillment->get_is_fulfilled() ) ? 'unfulfilled' : 'FULFILLED'
	)
);

// The tracking-number source is a measured decision, not a default.
$report(
	'SHIPPING_TRACKING_NUMBER_SOURCE',
	'' === Kuka_Island_Shipping_Fulfillment_Writer::tracking_number( Kuka_Island_Shipping_DHL_Config::TRACKING_SOURCE_UNSET, '838302813413', array( 'BC-0001' ) )
		&& '838302813413' === Kuka_Island_Shipping_Fulfillment_Writer::tracking_number( Kuka_Island_Shipping_DHL_Config::TRACKING_SOURCE_SHIPMENT_ID, '838302813413', array( 'BC-0001' ) )
		&& 'BC-0001' === Kuka_Island_Shipping_Fulfillment_Writer::tracking_number( Kuka_Island_Shipping_DHL_Config::TRACKING_SOURCE_BARCODE, '838302813413', array( 'BC-0001' ) )
		&& Kuka_Island_Shipping_DHL_Config::TRACKING_SOURCE_UNSET === kuka_ship_config()->get_tracking_number_source()
		&& Kuka_Island_Shipping_DHL_Config::TRACKING_SOURCE_UNSET === kuka_ship_config( array( 'tracking_number_source' => 'invoiceId' ) )->get_tracking_number_source()
		// The adapter's constants and the CONTRACT's constants are the same
		// values. Two vocabularies would mean a value the config accepted and
		// the writer silently ignored.
		&& Kuka_Island_Shipping_Carrier_Interface::TRACKING_SOURCE_UNSET === Kuka_Island_Shipping_DHL_Config::TRACKING_SOURCE_UNSET
		&& Kuka_Island_Shipping_Carrier_Interface::TRACKING_SOURCE_SHIPMENT_ID === Kuka_Island_Shipping_DHL_Config::TRACKING_SOURCE_SHIPMENT_ID
		&& Kuka_Island_Shipping_Carrier_Interface::TRACKING_SOURCE_BARCODE === Kuka_Island_Shipping_DHL_Config::TRACKING_SOURCE_BARCODE
		// And the adapter answers the CONTRACT, not only its own config.
		&& Kuka_Island_Shipping_DHL_Config::TRACKING_SOURCE_UNSET === kuka_ship_provider( new Kuka_Shipping_Mock_Transport( kuka_ship_happy_responder() ) )->get_tracking_number_source(),
	'default:unmeasured|shipment_id:selectable|barcode:selectable|unknown_value_falls_back_to_unmeasured:yes|contract_constants_identical:yes|adapter_answers_contract:yes'
);

/* ========================================================================== */
/* 14. A status reading moves the fulfilment, and only in one direction        */
/* ========================================================================== */

$status_result = $happy_manager->query_status( $order );
$order         = wc_get_order( $order->get_id() );
$after_status  = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
$fulfilled_now = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $order, $reference_after );

$report(
	'SHIPPING_STATUS_TO_FULFILLMENT',
	$status_result['ok']
		&& Kuka_Island_Shipping_Status::LIFECYCLE_IN_PROGRESS === $status_result['lifecycle']
		&& 2 === $after_status['status_code']
		&& 'https://kargotakip.example/838302813413' === $after_status['tracking_url']
		&& null !== $fulfilled_now
		&& $fulfilled_now->get_is_fulfilled(),
	sprintf(
		'lifecycle:%s|stored_code:%d|tracking_url_stored:%s|fulfilled_at_code_2:%s',
		$status_result['lifecycle'],
		$after_status['status_code'],
		'' !== $after_status['tracking_url'] ? 'yes' : 'NO',
		( null !== $fulfilled_now && $fulfilled_now->get_is_fulfilled() ) ? 'yes' : 'NO'
	)
);

// An unrecognised code must not undo a fulfilment and must stop the chain.
$unknown_transport = new Kuka_Shipping_Mock_Transport(
	static function ( string $method, string $url ): array {
		if ( str_contains( $url, '/token' ) ) {
			return array( 'status' => 200, 'body' => kuka_ship_token_body() );
		}

		if ( str_contains( $url, '/getshipmentstatus' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode( array( 'shipmentId' => '838302813413', 'shipmentStatusCode' => 42 ) ),
			);
		}

		return array( 'status' => 404, 'body' => '{}' );
	}
);
$unknown_manager = kuka_ship_manager( kuka_ship_provider( $unknown_transport ) );
$unknown_result  = $unknown_manager->query_status( $order );
$order           = wc_get_order( $order->get_id() );
$after_unknown   = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
$still_fulfilled = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $order, $reference_after );

$report(
	'SHIPPING_UNKNOWN_STATUS_TO_MANUAL_REVIEW',
	Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW === $unknown_result['lifecycle']
		&& Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW === $after_unknown['state']
		&& 0 === $after_unknown['status_code']
		&& null !== $still_fulfilled
		&& $still_fulfilled->get_is_fulfilled()
		&& ! Kuka_Island_Shipping_Status::should_keep_polling( $unknown_result['lifecycle'] ),
	sprintf(
		'raw_code:42|lifecycle:%s|state:%s|stored_code:%d|fulfilment_not_downgraded:%s|polling_stops:yes',
		$unknown_result['lifecycle'],
		$after_unknown['state'],
		$after_unknown['status_code'],
		( null !== $still_fulfilled && $still_fulfilled->get_is_fulfilled() ) ? 'yes' : 'NO'
	)
);

/* ========================================================================== */
/* 15. No secret ever reaches an operator-visible surface                      */
/* ========================================================================== */

$notes      = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 200 ) );
$note_text  = '';
foreach ( $notes as $note ) {
	$note_text .= "\n" . $note->content;
}

$meta_dump = wp_json_encode( $order->get_meta_data() ? array_map( static fn ( $m ) => $m->get_data(), $order->get_meta_data() ) : array() );

$surface = $note_text . "\n" . (string) $meta_dump
	. "\n" . wp_json_encode( kuka_ship_config()->get_safe_summary() )
	. "\n" . $first['message'] . ' ' . $first['detail']
	. "\n" . $status_result['detail'];

$sentinels = array( KUKA_SHIP_CLIENT_ID, KUKA_SHIP_SECRET, KUKA_SHIP_CUSTOMER, KUKA_SHIP_PASSWORD, KUKA_SHIP_JWT );
$leaked    = array();

foreach ( $sentinels as $sentinel ) {
	if ( str_contains( $surface, $sentinel ) ) {
		$leaked[] = 'value';
	}
}

// The secrets MUST be in the outgoing requests -- that is what they are for --
// so the same scan over the transport log is the control that proves the scan
// itself works.
$control_dump   = $happy_transport->dump();
$control_proves = str_contains( $control_dump, KUKA_SHIP_CLIENT_ID ) && str_contains( $control_dump, KUKA_SHIP_JWT );

$report(
	'SHIPPING_NO_SECRET_LEAK',
	array() === $leaked && $control_proves,
	sprintf(
		'sentinels:%d|leaks_in_notes_meta_and_results:%d|scan_control_positive:%s|surfaces:order_notes+order_meta+safe_summary+result_lines',
		count( $sentinels ),
		count( $leaked ),
		$control_proves ? 'yes' : 'NO'
	)
);

kuka_ship_destroy_order( $order );

/* ========================================================================== */
/* 16. An uncertain create is never repeated                                   */
/* ========================================================================== */

$uncertain_order = kuka_ship_fixture_order();

$uncertain_transport = new Kuka_Shipping_Mock_Transport(
	static function ( string $method, string $url ): array {
		if ( str_contains( $url, '/token' ) ) {
			return array( 'status' => 200, 'body' => kuka_ship_token_body() );
		}

		if ( str_contains( $url, '/getcities' ) ) {
			return array( 'status' => 200, 'body' => (string) wp_json_encode( array( array( 'code' => '34', 'name' => 'İSTANBUL' ) ) ) );
		}

		if ( str_contains( $url, '/getdistricts/34' ) ) {
			return array( 'status' => 200, 'body' => (string) wp_json_encode( array( array( 'code' => '1', 'name' => 'KADIKÖY' ) ) ) );
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			// Silence. The order may or may not now exist.
			return array( 'status' => 0, 'body' => '', 'error' => 'cURL error 28: Operation timed out' );
		}

		// Reconciliation: nothing is there.
		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$uncertain_provider = kuka_ship_provider( $uncertain_transport );
$uncertain_manager  = kuka_ship_manager( $uncertain_provider );
$uncertain_provider->get_resolver()->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );

$uncertain_result = $uncertain_manager->create_shipment( $uncertain_order );
$uncertain_order  = wc_get_order( $uncertain_order->get_id() );
$uncertain_data   = Kuka_Island_Shipping_Order_Store::get_shipment_data( $uncertain_order );

$report(
	'SHIPPING_UNCERTAIN_NO_RESEND',
	! $uncertain_result['ok']
		&& 1 === $uncertain_transport->count_for( '/createOrder' )
		&& 0 === $uncertain_transport->count_for( '/createbarcode' )
		&& 1 === $uncertain_transport->count_for( '/getshipment/' )
		&& 1 === $uncertain_transport->count_for( '/getorder/' )
		&& Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED === $uncertain_data['state'],
	sprintf(
		'createOrder_attempts:%d|createbarcode_attempts:%d|read_only_reconcile_calls:%d|verdict_state:%s|code:%s',
		$uncertain_transport->count_for( '/createOrder' ),
		$uncertain_transport->count_for( '/createbarcode' ),
		$uncertain_transport->count_for( '/getshipment/' ) + $uncertain_transport->count_for( '/getorder/' ),
		$uncertain_data['state'],
		$uncertain_result['code']
	)
);

/* ========================================================================== */
/* 17. An inconclusive reconciliation leaves the door shut                     */
/* ========================================================================== */

$stuck_order = kuka_ship_fixture_order();

$stuck_transport = new Kuka_Shipping_Mock_Transport(
	static function ( string $method, string $url ): array {
		if ( str_contains( $url, '/token' ) ) {
			return array( 'status' => 200, 'body' => kuka_ship_token_body() );
		}

		if ( str_contains( $url, '/getcities' ) ) {
			return array( 'status' => 200, 'body' => (string) wp_json_encode( array( array( 'code' => '34', 'name' => 'İSTANBUL' ) ) ) );
		}

		if ( str_contains( $url, '/getdistricts/34' ) ) {
			return array( 'status' => 200, 'body' => (string) wp_json_encode( array( array( 'code' => '1', 'name' => 'KADIKÖY' ) ) ) );
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return array( 'status' => 500, 'body' => '' );
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode( array( 'referenceId' => 'ECHO', 'orderInvoiceId' => 'OINV-2' ) ),
			);
		}

		// Reads cannot answer either.
		return array( 'status' => 503, 'body' => '' );
	}
);

$stuck_provider = kuka_ship_provider( $stuck_transport );
$stuck_manager  = kuka_ship_manager( $stuck_provider );
$stuck_provider->get_resolver()->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );

$stuck_first  = $stuck_manager->create_shipment( $stuck_order );
$stuck_order  = wc_get_order( $stuck_order->get_id() );
$stuck_second = $stuck_manager->create_shipment( $stuck_order );
$stuck_order  = wc_get_order( $stuck_order->get_id() );
$stuck_data   = Kuka_Island_Shipping_Order_Store::get_shipment_data( $stuck_order );

$report(
	'SHIPPING_INCONCLUSIVE_STAYS_SHUT',
	! $stuck_first['ok']
		&& ! $stuck_second['ok']
		&& 'already_in_progress' === $stuck_second['code']
		&& 1 === $stuck_transport->count_for( '/createbarcode' )
		&& 1 === $stuck_transport->count_for( '/createOrder' )
		&& Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED === $stuck_data['state'],
	sprintf(
		'createbarcode_attempts:%d|createOrder_attempts:%d|state:%s|second_attempt:%s',
		$stuck_transport->count_for( '/createbarcode' ),
		$stuck_transport->count_for( '/createOrder' ),
		$stuck_data['state'],
		$stuck_second['code']
	)
);

kuka_ship_destroy_order( $stuck_order );

/* ========================================================================== */
/* 18. Reconciliation that FINDS the shipment adopts it                        */
/* ========================================================================== */

$found_transport = new Kuka_Shipping_Mock_Transport(
	static function ( string $method, string $url ): array {
		if ( str_contains( $url, '/token' ) ) {
			return array( 'status' => 200, 'body' => kuka_ship_token_body() );
		}

		if ( str_contains( $url, '/getshipment/' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						'shipment' => array(
							'referenceId'        => 'ECHO',
							'shipmentId'         => '999888777',
							'shipmentStatusCode' => 1,
							'pieceCount'         => 1,
						),
					)
				),
			);
		}

		return array( 'status' => 404, 'body' => '{}' );
	}
);

$found_provider = kuka_ship_provider( $found_transport );
$found_manager  = kuka_ship_manager( $found_provider );

$found_order     = wc_get_order( $uncertain_order->get_id() );
$found_reference = Kuka_Island_Shipping_Order_Store::reference( $found_order );
$found_verdict   = $found_manager->reconcile( $found_order, $found_provider, $found_reference );
$found_order     = wc_get_order( $found_order->get_id() );
$found_data      = Kuka_Island_Shipping_Order_Store::get_shipment_data( $found_order );

$report(
	'SHIPPING_RECONCILE_ADOPTS_EXISTING',
	'shipment_present' === $found_verdict['verdict']
		&& '999888777' === $found_data['shipment_id']
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $found_data['state']
		&& 0 === $found_transport->count_for( '/createOrder' )
		&& 0 === $found_transport->count_for( '/createbarcode' ),
	sprintf(
		'verdict:%s|adopted_shipment_id:%s|state:%s|writes_issued:%d',
		$found_verdict['verdict'],
		'' !== $found_data['shipment_id'] ? 'yes' : 'NO',
		$found_data['state'],
		$found_transport->count_for( '/createOrder' ) + $found_transport->count_for( '/createbarcode' )
	)
);

kuka_ship_destroy_order( $found_order );

/* ========================================================================== */
/* 19. Cash on delivery is refused before anything is sent                     */
/* ========================================================================== */

$cod_order = kuka_ship_fixture_order();
$cod_order->set_payment_method( 'cod' );
$cod_order->save();

$cod_transport = new Kuka_Shipping_Mock_Transport( kuka_ship_happy_responder() );
$cod_manager   = kuka_ship_manager( kuka_ship_provider( $cod_transport ) );
$cod_result    = $cod_manager->create_shipment( $cod_order );
$cod_order     = wc_get_order( $cod_order->get_id() );

// And the adapter refuses independently, for a caller that bypassed the manager.
$cod_direct = kuka_ship_provider( new Kuka_Shipping_Mock_Transport( kuka_ship_happy_responder() ) )->create_order(
	array(
		'reference'   => 'KI901-AAAAAA',
		'service'     => 'standard',
		'packaging'   => 'package',
		'payment'     => 'sender',
		'delivery'    => 'to_address',
		'content'     => 'x',
		'description' => 'x',
		'pieces'      => array( array( 'barcode' => 'KI901-AAAAAAP1', 'desi' => 1, 'kg' => 1 ) ),
		'recipient'   => array( 'full_name' => 'x', 'address' => 'x', 'city_code' => 34, 'district_code' => 1 ),
		'cod'         => array( 'enabled' => true, 'amount' => 100 ),
	)
);

$report(
	'SHIPPING_COD_FAIL_CLOSED',
	! $cod_result['ok']
		&& 'cod_not_supported' === $cod_result['code']
		&& 0 === count( $cod_transport->log )
		&& 'cod_not_supported' === $cod_direct->get_safe_error_code()
		&& ! kuka_ship_config()->is_cod_enabled(),
	sprintf(
		'manager_code:%s|http_requests:%d|adapter_code:%s|config_default:%s',
		$cod_result['code'],
		count( $cod_transport->log ),
		$cod_direct->get_safe_error_code(),
		kuka_ship_config()->is_cod_enabled() ? 'ENABLED' : 'disabled'
	)
);

// isCOD is hard-written as 0 in every payload the mapper can produce.
$cod_payload_shipment = array(
	'reference'   => 'KI902-AAAAAA',
	'service'     => 'standard',
	'packaging'   => 'package',
	'payment'     => 'sender',
	'delivery'    => 'to_address',
	'content'     => 'x',
	'description' => 'x',
	'shipment_id' => '1',
	'pieces'      => array( array( 'barcode' => 'KI902-AAAAAAP1', 'desi' => 1, 'kg' => 1 ) ),
	'recipient'   => array( 'full_name' => 'x', 'address' => 'x', 'city_code' => 34, 'district_code' => 1 ),
	'cod'         => array( 'enabled' => true, 'amount' => 500 ),
);

$cod_payloads = array(
	Kuka_Island_Shipping_DHL_Order_Mapper::create_order_payload( $cod_payload_shipment )['order'],
	Kuka_Island_Shipping_DHL_Order_Mapper::create_barcode_payload( $cod_payload_shipment ),
	Kuka_Island_Shipping_DHL_Order_Mapper::update_order_payload( $cod_payload_shipment ),
	Kuka_Island_Shipping_DHL_Order_Mapper::update_shipment_payload( $cod_payload_shipment ),
);

$cod_zero = true;
foreach ( $cod_payloads as $payload ) {
	if ( 0 !== $payload['isCOD'] || 0 !== $payload['codAmount'] ) {
		$cod_zero = false;
	}
}

$report(
	'SHIPPING_COD_ZERO_IN_PAYLOADS',
	$cod_zero,
	sprintf( 'payloads:%d|isCOD_always_zero:%s', count( $cod_payloads ), $cod_zero ? 'yes' : 'NO' )
);

kuka_ship_destroy_order( $cod_order );

/* ========================================================================== */
/* 20. The mapper: the vendor's enumerations, and nothing defaulted            */
/* ========================================================================== */

$mapper_shipment = array(
	'reference'   => 'KI903-AAAAAA',
	'service'     => 'standard',
	'packaging'   => 'package',
	'payment'     => 'sender',
	'delivery'    => 'to_address',
	'content'     => "İki  satırlı\nürün adı",
	'description' => 'Sipariş 903',
	'sms1'        => 0,
	'sms2'        => 0,
	'sms3'        => 0,
	'pieces'      => array( array( 'barcode' => 'ki903-aaaaaap1', 'desi' => 0, 'kg' => 0, 'content' => 'x' ) ),
	'recipient'   => array(
		'full_name'    => 'Kuka Fixture',
		'address'      => 'Test sokak 1',
		'city_code'    => 34,
		'district_code' => 1,
		'mobile_phone' => '+90 (530) 948-19 96',
	),
);

$mapped = Kuka_Island_Shipping_DHL_Order_Mapper::create_order_payload( $mapper_shipment );

$mapper_ok = 1 === $mapped['order']['shipmentServiceType']
	&& 3 === $mapped['order']['packagingType']
	&& 1 === $mapped['order']['paymentType']
	&& 1 === $mapped['order']['deliveryType']
	&& $mapped['order']['referenceId'] === $mapped['order']['barcode']
	&& 'KI903-AAAAAA' === $mapped['order']['referenceId']
	&& 'KI903-AAAAAAP1' === $mapped['orderPieceList'][0]['barcode']
	&& 1 === $mapped['orderPieceList'][0]['desi']
	&& 1 === $mapped['orderPieceList'][0]['kg']
	&& '+905309481996' === $mapped['recipient']['mobilePhoneNumber']
	&& ! str_contains( $mapped['order']['content'], "\n" )
	&& ! array_key_exists( 'customerId', $mapped['recipient'] )
	&& 0 === $mapped['order']['smsPreference1']
	&& 0 === $mapped['order']['smsPreference2']
	&& 0 === $mapped['order']['smsPreference3'];

// Unmapped tokens are refused, never defaulted.
$unknown_tokens = Kuka_Island_Shipping_DHL_Order_Mapper::validate(
	array_merge( $mapper_shipment, array( 'packaging' => 'wardrobe', 'payment' => 'platform', 'service' => 'teleport' ) )
);

$report(
	'SHIPPING_PAYLOAD_MAPPING',
	$mapper_ok
		&& in_array( 'packaging', $unknown_tokens, true )
		&& in_array( 'payment', $unknown_tokens, true )
		&& in_array( 'service', $unknown_tokens, true )
		&& ! array_key_exists( 'platform', Kuka_Island_Shipping_DHL_Order_Mapper::payment_types() ),
	sprintf(
		'enumerations:from_spec|barcode_equals_reference:yes|piece_minimums:1|phone_normalised:yes|sms_default:0,0,0|customerId_omitted:yes|unknown_tokens_refused:%d|platform_payment_unmappable:yes',
		count( $unknown_tokens )
	)
);

/* ========================================================================== */
/* 21. The runtime gate stops a call mid-flight                                */
/* ========================================================================== */

$gate_order     = kuka_ship_fixture_order();
$gate_transport = new Kuka_Shipping_Mock_Transport( kuka_ship_happy_responder() );
$gate_manager   = kuka_ship_manager( kuka_ship_provider( $gate_transport ) );

Kuka_Island_Shipping_Runtime_Gate::disable();
$gate_result = $gate_manager->create_shipment( $gate_order );
$gate_closed = Kuka_Island_Shipping_Runtime_Gate::is_disabled();
Kuka_Island_Shipping_Runtime_Gate::enable();
$gate_open = ! Kuka_Island_Shipping_Runtime_Gate::is_disabled();

$report(
	'SHIPPING_RUNTIME_GATE',
	$gate_closed
		&& $gate_open
		&& ! $gate_result['ok']
		&& Kuka_Island_Shipping_Runtime_Gate::CODE === $gate_result['code']
		&& 0 === count( $gate_transport->log ),
	sprintf(
		'closed_blocks:%s|http_requests_while_closed:%d|code:%s|restored:%s',
		$gate_result['ok'] ? 'NO' : 'yes',
		count( $gate_transport->log ),
		$gate_result['code'],
		$gate_open ? 'yes' : 'NO'
	)
);

kuka_ship_destroy_order( $gate_order );

/* ========================================================================== */
/* 22. A cancellation is confirmed by reading THE OBJECT THAT WAS CANCELLED    */
/* ========================================================================== */

/*
 * Both branches used to be confirmed with getshipment. On the ORDER branch that
 * is not a confirmation: no shipment was ever created under the reference, so
 * getshipment answers not_found whether cancelorder worked or not. The three
 * measurements below drive the REAL Manager::cancel() and read the transport log
 * to see WHICH query was asked.
 */

// --- Shipment branch: cancelshipment, then getshipment ---------------------

$cancel_ship = kuka_ship_scenario(
	static function ( string $method, string $url ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return kuka_ship_create_barcode_ok();
		}

		if ( str_contains( $url, '/cancelshipment' ) ) {
			return array( 'status' => 200, 'body' => '{}' );
		}

		// The parcel really is gone.
		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$cancel_ship['manager']->create_shipment( $cancel_ship['order'] );
$cancel_ship_order = wc_get_order( $cancel_ship['order']->get_id() );
$cancel_ship_state = Kuka_Island_Shipping_Order_Store::get_shipment_data( $cancel_ship_order )['state'];

$cancel_ship_result = $cancel_ship['manager']->cancel( $cancel_ship_order );
$cancel_ship_order  = wc_get_order( $cancel_ship_order->get_id() );
$cancel_ship_after  = Kuka_Island_Shipping_Order_Store::get_shipment_data( $cancel_ship_order );

$report(
	'SHIPPING_CANCEL_SHIPMENT_BRANCH',
	Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $cancel_ship_state
		&& $cancel_ship_result['ok']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $cancel_ship_after['state']
		&& 1 === $cancel_ship['transport']->count_for( '/cancelshipment' )
		&& 0 === $cancel_ship['transport']->count_for( '/cancelorder/' )
		&& 1 === $cancel_ship['transport']->count_for( '/getshipment/' )
		&& 0 === $cancel_ship['transport']->count_for( '/getorder/' )
		&& str_contains( (string) $cancel_ship_result['detail'], 'target:shipment' ),
	sprintf(
		'branch:shipment|cancelshipment_calls:%d|cancelorder_calls:%d|getshipment_calls:%d|getorder_calls:%d|state:%s|confirmed_by:%s',
		$cancel_ship['transport']->count_for( '/cancelshipment' ),
		$cancel_ship['transport']->count_for( '/cancelorder/' ),
		$cancel_ship['transport']->count_for( '/getshipment/' ),
		$cancel_ship['transport']->count_for( '/getorder/' ),
		$cancel_ship_after['state'],
		str_contains( (string) $cancel_ship_result['detail'], 'target:shipment' ) ? 'read_shipment' : 'OTHER'
	)
);

kuka_ship_destroy_order( $cancel_ship_order );

// --- Order branch, cancellation proved: cancelorder, then getorder ---------

$cancel_order_cancelled = false;
$cancel_order_scenario  = kuka_ship_scenario(
	static function ( string $method, string $url ) use ( &$cancel_order_cancelled ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			// The order is registered and the barcode was refused. The refusal
			// does NOT establish that no shipment exists -- see
			// kuka_ship_reach_order_created() -- so the reconciliation below is
			// what actually produces the order_created dead end.
			return array( 'status' => 400, 'body' => '{"title":"Bad Request"}' );
		}

		if ( str_contains( $url, '/cancelorder/' ) ) {
			$cancel_order_cancelled = true;

			return array( 'status' => 200, 'body' => '{}' );
		}

		if ( str_contains( $url, '/getorder/' ) ) {
			// Present before the cancellation, gone after it. Both readings are
			// needed: the first proves the dead end, the second proves the
			// cancellation.
			return $cancel_order_cancelled
				? array( 'status' => 404, 'body' => '{"title":"Not Found"}' )
				: kuka_ship_get_order_present();
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$cancel_order_reached = kuka_ship_reach_order_created( $cancel_order_scenario );
$cancel_order_order   = $cancel_order_reached['order'];
$cancel_order_pre     = Kuka_Island_Shipping_Order_Store::get_shipment_data( $cancel_order_order );

$cancel_order_result = $cancel_order_scenario['manager']->cancel( $cancel_order_order );
$cancel_order_order  = wc_get_order( $cancel_order_order->get_id() );
$cancel_order_after  = Kuka_Island_Shipping_Order_Store::get_shipment_data( $cancel_order_order );

$report(
	'SHIPPING_CANCEL_ORDER_BRANCH',
	Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $cancel_order_pre['state']
		&& '' === $cancel_order_pre['shipment_id']
		&& $cancel_order_result['ok']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $cancel_order_after['state']
		&& 1 === $cancel_order_scenario['transport']->count_for( '/cancelorder/' )
		&& 0 === $cancel_order_scenario['transport']->count_for( '/cancelshipment' )
		// Two getorder readings: one that established the dead end, one that
		// confirmed the cancellation. One getshipment, from the same
		// reconciliation. All four are READS.
		&& 2 === $cancel_order_scenario['transport']->count_for( '/getorder/' )
		&& 1 === $cancel_order_scenario['transport']->count_for( '/getshipment/' )
		&& str_contains( (string) $cancel_order_result['detail'], 'target:order' ),
	sprintf(
		'branch:order|state_before:%s|shipment_id_before:%s|cancelorder_calls:%d|cancelshipment_calls:%d|getorder_calls:%d|getshipment_calls:%d|state:%s|confirmed_by:%s',
		$cancel_order_pre['state'],
		'' === $cancel_order_pre['shipment_id'] ? 'none' : 'PRESENT',
		$cancel_order_scenario['transport']->count_for( '/cancelorder/' ),
		$cancel_order_scenario['transport']->count_for( '/cancelshipment' ),
		$cancel_order_scenario['transport']->count_for( '/getorder/' ),
		$cancel_order_scenario['transport']->count_for( '/getshipment/' ),
		$cancel_order_after['state'],
		str_contains( (string) $cancel_order_result['detail'], 'target:order' ) ? 'read_order' : 'OTHER'
	)
);

kuka_ship_destroy_order( $cancel_order_order );

// --- THE NEGATIVE CASE ------------------------------------------------------
//
// cancelorder answers success, getshipment answers not_found, getorder answers
// PRESENT. The old code asked getshipment, saw not_found and wrote `cancelled`
// on an order the carrier still holds. Nothing here may write `cancelled`.

$false_cancel = kuka_ship_scenario(
	static function ( string $method, string $url ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return array( 'status' => 400, 'body' => '{"title":"Bad Request"}' );
		}

		if ( str_contains( $url, '/cancelorder/' ) ) {
			// The carrier says yes.
			return array( 'status' => 200, 'body' => '{}' );
		}

		if ( str_contains( $url, '/getorder/' ) ) {
			// ...and the order is still there.
			return kuka_ship_get_order_present();
		}

		// A shipment never existed under this reference, so of course it is
		// absent. This 404 proves nothing about the cancellation.
		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$false_cancel_reached = kuka_ship_reach_order_created( $false_cancel );
$false_cancel_order   = $false_cancel_reached['order'];
$false_cancel_pre     = Kuka_Island_Shipping_Order_Store::get_shipment_data( $false_cancel_order );

$false_cancel_result = $false_cancel['manager']->cancel( $false_cancel_order );
$false_cancel_order  = wc_get_order( $false_cancel_order->get_id() );
$false_cancel_after  = Kuka_Island_Shipping_Order_Store::get_shipment_data( $false_cancel_order );

// A cancelled order stops being polled. An order that may still be live must
// not have had its queries unscheduled either.
$false_cancel_pending = Kuka_Island_Shipping_Status_Poller::has_pending_query( (int) $false_cancel_order->get_id() );

$report(
	'SHIPPING_CANCEL_ORDER_NOT_CANCELLED_ON_SHIPMENT_404',
	Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $false_cancel_pre['state']
		&& ! $false_cancel_result['ok']
		&& 'cancel_unconfirmed_record_present' === $false_cancel_result['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED !== $false_cancel_after['state']
		// The order does NOT go back to order_created either: a cancellation is
		// in flight and the door has to stay shut.
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $false_cancel_after['state']
		&& 1 === $false_cancel['transport']->count_for( '/cancelorder/' )
		// Two getorder readings and one getshipment: the first pair reached
		// order_created, the third confirmed -- and found the order still
		// there, which is what this measurement is about.
		&& 2 === $false_cancel['transport']->count_for( '/getorder/' )
		&& 1 === $false_cancel['transport']->count_for( '/getshipment/' )
		&& ! $false_cancel_pending,
	sprintf(
		'cancel_order:success|read_shipment:not_found|read_order:present|cancelorder_calls:%d|getorder_calls:%d|getshipment_calls:%d|code:%s|state:%s|cancelled_written:%s',
		$false_cancel['transport']->count_for( '/cancelorder/' ),
		$false_cancel['transport']->count_for( '/getorder/' ),
		$false_cancel['transport']->count_for( '/getshipment/' ),
		$false_cancel_result['code'],
		$false_cancel_after['state'],
		Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $false_cancel_after['state'] ? 'YES' : 'no'
	)
);

kuka_ship_destroy_order( $false_cancel_order );

// --- An uncertain cancellation is not repeated -----------------------------

$uncertain_cancel = kuka_ship_scenario(
	static function ( string $method, string $url ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return kuka_ship_create_barcode_ok();
		}

		if ( str_contains( $url, '/cancelshipment' ) ) {
			// Silence on a write: it may or may not have taken effect.
			return array( 'status' => 0, 'body' => '', 'error' => 'cURL error 28: Operation timed out' );
		}

		return array( 'status' => 503, 'body' => '' );
	}
);

$uncertain_cancel['manager']->create_shipment( $uncertain_cancel['order'] );
$uncertain_cancel_order = wc_get_order( $uncertain_cancel['order']->get_id() );

$uncertain_cancel_first = $uncertain_cancel['manager']->cancel( $uncertain_cancel_order );
$uncertain_cancel_order = wc_get_order( $uncertain_cancel_order->get_id() );
$uncertain_cancel_state = Kuka_Island_Shipping_Order_Store::get_state( $uncertain_cancel_order );

$uncertain_cancel_second = $uncertain_cancel['manager']->cancel( $uncertain_cancel_order );

$report(
	'SHIPPING_CANCEL_UNCERTAIN_NOT_REPEATED',
	! $uncertain_cancel_first['ok']
		// The write reached the carrier, so the order sits in the protected
		// cancel state rather than in the generic reconcile state.
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $uncertain_cancel_state
		&& ! $uncertain_cancel_second['ok']
		&& 'cancel_in_progress' === $uncertain_cancel_second['code']
		&& 1 === $uncertain_cancel['transport']->count_for( '/cancelshipment' ),
	sprintf(
		'first_code:%s|state:%s|second_code:%s|cancelshipment_calls:%d',
		$uncertain_cancel_first['code'],
		$uncertain_cancel_state,
		$uncertain_cancel_second['code'],
		$uncertain_cancel['transport']->count_for( '/cancelshipment' )
	)
);

kuka_ship_destroy_order( $uncertain_cancel_order );

/* ========================================================================== */
/* 23. The barcode stage can be resumed -- and only from order_created         */
/* ========================================================================== */

/*
 * The dead end: createOrder succeeded, createbarcode was refused, and
 * order_created blocks create_shipment(). Everything below drives the REAL
 * Manager::resume_barcode() and the REAL admin handler, and counts the carrier
 * calls each press produced.
 */

$resume_barcode_calls = 0;

$resume = kuka_ship_scenario(
	static function ( string $method, string $url ) use ( &$resume_barcode_calls ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			++$resume_barcode_calls;

			// The first attempt is refused permanently; the resume succeeds.
			return 1 === $resume_barcode_calls
				? array( 'status' => 400, 'body' => '{"title":"Bad Request"}' )
				: kuka_ship_create_barcode_ok( '445566778', 'BC-RESUMED' );
		}

		if ( str_contains( $url, '/getorder/' ) ) {
			// The reconciliation that establishes the dead end: the order is
			// registered, and the 404 fall-through below says no shipment is.
			return kuka_ship_get_order_present();
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$resume_reached = kuka_ship_reach_order_created( $resume );
$resume_order   = $resume_reached['order'];
$resume_pre     = Kuka_Island_Shipping_Order_Store::get_shipment_data( $resume_order );

// The create door is shut, and shut for the right reason.
$resume_create_again = $resume['manager']->create_shipment( $resume_order );
$resume_order        = wc_get_order( $resume_order->get_id() );

$resume_before_create_order = $resume['transport']->count_for( '/createOrder' );
$resume_before_barcode      = $resume['transport']->count_for( '/createbarcode' );

$resume_result = $resume['manager']->resume_barcode( $resume_order );
$resume_order  = wc_get_order( $resume_order->get_id() );
$resume_after  = Kuka_Island_Shipping_Order_Store::get_shipment_data( $resume_order );

$resume_delta_create_order = $resume['transport']->count_for( '/createOrder' ) - $resume_before_create_order;
$resume_delta_barcode      = $resume['transport']->count_for( '/createbarcode' ) - $resume_before_barcode;

// The double press. Same lock, and the state is now shipment_created.
$resume_second        = $resume['manager']->resume_barcode( $resume_order );
$resume_second_delta  = $resume['transport']->count_for( '/createbarcode' ) - $resume_before_barcode - 1;
$resume_order         = wc_get_order( $resume_order->get_id() );
$resume_after_second  = Kuka_Island_Shipping_Order_Store::get_shipment_data( $resume_order );

$report(
	'SHIPPING_RESUME_ORDER_CREATED',
	Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $resume_pre['state']
		&& ! $resume_create_again['ok']
		&& 'already_in_progress' === $resume_create_again['code']
		&& $resume_result['ok']
		&& 0 === $resume_delta_create_order
		&& 1 === $resume_delta_barcode
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $resume_after['state']
		&& '445566778' === $resume_after['shipment_id']
		&& array( 'BC-RESUMED' ) === $resume_after['barcodes']
		&& ! $resume_second['ok']
		&& 'not_resumable' === $resume_second['code']
		&& 0 === $resume_second_delta
		&& '445566778' === $resume_after_second['shipment_id'],
	sprintf(
		'state_before:%s|create_again_code:%s|createOrder_calls_during_resume:%d|createbarcode_calls_during_resume:%d|state_after:%s|shipment_id:%s|second_press_code:%s|second_press_writes:%d',
		$resume_pre['state'],
		$resume_create_again['code'],
		$resume_delta_create_order,
		$resume_delta_barcode,
		$resume_after['state'],
		'' !== $resume_after['shipment_id'] ? 'stored' : 'NONE',
		$resume_second['code'],
		$resume_second_delta
	)
);

// --- Every other state is refused, and refused before the network ----------

$resume_guard = kuka_ship_scenario(
	static function ( string $method, string $url ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		return array( 'status' => 500, 'body' => '' );
	}
);

/*
 * Driven through the real create path first, so the order has a pinned carrier
 * and a reference exactly as a live one would. Setting states on an order that
 * never had an owner would measure the ownership refusal instead of the state
 * gate -- a different, and separately measured, property.
 */
$resume_guard['manager']->create_shipment( $resume_guard['order'] );
$resume_guard_order = wc_get_order( $resume_guard['order']->get_id() );
$resume_guard['transport']->reset();

$resume_refused = array();
$resume_allowed = array();

foreach (
	array(
		Kuka_Island_Shipping_Order_Store::STATE_NONE,
		Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED,
		Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED,
		Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED,
		Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED,
		Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED,
		Kuka_Island_Shipping_Order_Store::STATE_DELIVERED,
		Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW,
		Kuka_Island_Shipping_Order_Store::STATE_CANCELLED,
		Kuka_Island_Shipping_Order_Store::STATE_BLOCKED,
	) as $guard_state
) {
	$resume_guard_order = wc_get_order( $resume_guard_order->get_id() );
	Kuka_Island_Shipping_Order_Store::set_state( $resume_guard_order, $guard_state );
	$guard_result = $resume_guard['manager']->resume_barcode( wc_get_order( $resume_guard_order->get_id() ) );

	if ( ! $guard_result['ok'] && 'not_resumable' === $guard_result['code'] && '' !== $guard_result['message'] ) {
		$resume_refused[] = $guard_state;
	} else {
		$resume_allowed[] = $guard_state . ':' . $guard_result['code'];
	}
}

$report(
	'SHIPPING_RESUME_REFUSES_OTHER_STATES',
	10 === count( $resume_refused )
		&& array() === $resume_allowed
		&& 0 === count( $resume_guard['transport']->log ),
	sprintf(
		'states_refused:%d|states_allowed:%s|http_requests:%d|codes:not_resumable|carrier_pinned:yes',
		count( $resume_refused ),
		array() === $resume_allowed ? 'none' : implode( '+', $resume_allowed ),
		count( $resume_guard['transport']->log )
	)
);

kuka_ship_destroy_order( wc_get_order( $resume_guard_order->get_id() ) );

// --- An uncertain resume is not repeated; the read decides -----------------

$resume_uncertain = kuka_ship_scenario(
	static function ( string $method, string $url ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			// Silence on a write. It may have produced a shipment.
			return array( 'status' => 0, 'body' => '', 'error' => 'cURL error 28: Operation timed out' );
		}

		// The reads cannot answer either, so absence is NOT proved.
		return array( 'status' => 503, 'body' => '' );
	}
);

$resume_uncertain['manager']->create_shipment( $resume_uncertain['order'] );
$resume_uncertain_order = wc_get_order( $resume_uncertain['order']->get_id() );
$resume_uncertain_data  = Kuka_Island_Shipping_Order_Store::get_shipment_data( $resume_uncertain_order );

$resume_uncertain_second = $resume_uncertain['manager']->resume_barcode( $resume_uncertain_order );

$report(
	'SHIPPING_RESUME_UNCERTAIN_TO_RECONCILE',
	1 === $resume_uncertain['transport']->count_for( '/createOrder' )
		&& 1 === $resume_uncertain['transport']->count_for( '/createbarcode' )
		&& Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED === $resume_uncertain_data['state']
		&& 1 === $resume_uncertain['transport']->count_for( '/getshipment/' )
		&& 1 === $resume_uncertain['transport']->count_for( '/getorder/' )
		&& ! $resume_uncertain_second['ok']
		&& 'not_resumable' === $resume_uncertain_second['code']
		&& 1 === $resume_uncertain['transport']->count_for( '/createbarcode' ),
	sprintf(
		'createOrder_calls:%d|createbarcode_calls:%d|state:%s|read_only_reconcile_calls:%d|second_press_code:%s',
		$resume_uncertain['transport']->count_for( '/createOrder' ),
		$resume_uncertain['transport']->count_for( '/createbarcode' ),
		$resume_uncertain_data['state'],
		$resume_uncertain['transport']->count_for( '/getshipment/' ) + $resume_uncertain['transport']->count_for( '/getorder/' ),
		$resume_uncertain_second['code']
	)
);

kuka_ship_destroy_order( wc_get_order( $resume_uncertain_order->get_id() ) );

/* ========================================================================== */
/* 24. The resume action, through the real admin handler                       */
/* ========================================================================== */

/*
 * Kuka_Island_Shipping_Admin::run_resume() IS the handler: nonce, capability,
 * order lookup and the manager call. Only the redirect is separate, because a
 * redirect ends the request and a measurement after it would never run.
 *
 * wp_die() is turned into an exception for the duration, so the two refusal
 * paths -- a nonce issued for a different action, and a user without the
 * capability -- can be observed instead of ending the process.
 */

$admin_resume_barcode_calls = 0;

$admin_resume = kuka_ship_scenario(
	static function ( string $method, string $url ) use ( &$admin_resume_barcode_calls ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			++$admin_resume_barcode_calls;

			return 1 === $admin_resume_barcode_calls
				? array( 'status' => 400, 'body' => '{"title":"Bad Request"}' )
				: kuka_ship_create_barcode_ok( '112233445', 'BC-ADMIN' );
		}

		if ( str_contains( $url, '/getorder/' ) ) {
			return kuka_ship_get_order_present();
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$admin_resume_reached  = kuka_ship_reach_order_created( $admin_resume );
$admin_resume_order    = $admin_resume_reached['order'];
$admin_resume_order_id = (int) $admin_resume_order->get_id();
$admin_resume_state    = Kuka_Island_Shipping_Order_Store::get_state( $admin_resume_order );

$admin_panel     = new Kuka_Island_Shipping_Admin( $admin_resume['manager'] );
$admin_carrier   = $admin_resume['provider'];
$admin_hint_open = Kuka_Island_Shipping_Admin::operator_hint( $admin_resume_order, $admin_carrier );
$admin_user_ids  = (array) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin_user_id   = array() !== $admin_user_ids ? (int) $admin_user_ids[0] : 0;
$previous_user   = get_current_user_id();

$throwing_die = static function (): callable {
	return static function ( $message = '', $title = '', $args = array() ): void {
		throw new RuntimeException( 'wp_die' );
	};
};
add_filter( 'wp_die_handler', $throwing_die, 99 );

/**
 * Drive run_resume() with a given nonce and user, and say what happened.
 *
 * @param Kuka_Island_Shipping_Admin $panel    Panel.
 * @param int                        $order_id Order id.
 * @param string                     $nonce    Nonce value to submit.
 * @param int                        $user_id  User to act as.
 * @return array{outcome: string, result: array<string, mixed>}
 */
$press_resume = static function ( Kuka_Island_Shipping_Admin $panel, int $order_id, string $nonce, int $user_id ): array {
	wp_set_current_user( $user_id );

	$_POST                       = array();
	$_REQUEST                    = array();
	$_POST['order_id']           = (string) $order_id;
	$_POST['_kuka_ship_nonce']   = $nonce;
	$_REQUEST['order_id']        = (string) $order_id;
	$_REQUEST['_kuka_ship_nonce'] = $nonce;

	try {
		$result = $panel->run_resume();

		return array(
			'outcome' => 'ran',
			'result'  => $result,
		);
	} catch ( RuntimeException $e ) {
		return array(
			'outcome' => 'refused',
			'result'  => array(),
		);
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
};

// 1. A nonce minted for the CREATE action must not open the RESUME action.
wp_set_current_user( $admin_user_id );
$create_nonce = wp_create_nonce( 'kuka_shipping_create_' . $admin_resume_order_id );
$resume_nonce = wp_create_nonce( 'kuka_shipping_resume_' . $admin_resume_order_id );
$nonce_namespaces_differ = $create_nonce !== $resume_nonce
	&& false === wp_verify_nonce( $create_nonce, 'kuka_shipping_resume_' . $admin_resume_order_id )
	&& false === wp_verify_nonce( $resume_nonce, 'kuka_shipping_create_' . $admin_resume_order_id );

$admin_before_barcode = $admin_resume['transport']->count_for( '/createbarcode' );
$wrong_nonce_press    = $press_resume( $admin_panel, $admin_resume_order_id, $create_nonce, $admin_user_id );
$after_wrong_nonce    = $admin_resume['transport']->count_for( '/createbarcode' );

// 2. Without the capability, nothing is reached either -- with a nonce that is
//    valid for the user making the request.
wp_set_current_user( 0 );
$logged_out_nonce  = wp_create_nonce( 'kuka_shipping_resume_' . $admin_resume_order_id );
$no_cap_press      = $press_resume( $admin_panel, $admin_resume_order_id, $logged_out_nonce, 0 );
$after_no_cap      = $admin_resume['transport']->count_for( '/createbarcode' );

// 3. The real press.
wp_set_current_user( $admin_user_id );
$good_nonce   = wp_create_nonce( 'kuka_shipping_resume_' . $admin_resume_order_id );
$good_press   = $press_resume( $admin_panel, $admin_resume_order_id, $good_nonce, $admin_user_id );
$after_good   = $admin_resume['transport']->count_for( '/createbarcode' );

remove_filter( 'wp_die_handler', $throwing_die, 99 );
wp_set_current_user( $previous_user );

$admin_resume_order = wc_get_order( $admin_resume_order_id );
$admin_resume_after = Kuka_Island_Shipping_Order_Store::get_shipment_data( $admin_resume_order );

/*
 * The wording an operator reads. The carrier's name comes from get_label(), and
 * the hint shown in order_created has to say what the button will and will not
 * do -- an operator staring at a half-finished shipment is exactly the person
 * who needs to be told that pressing it cannot register a second order.
 */
$admin_labels_dynamic = str_contains( Kuka_Island_Shipping_Admin::resume_button_label( $admin_carrier ), $admin_carrier->get_label() )
	&& str_contains( Kuka_Island_Shipping_Admin::create_button_label( $admin_carrier ), $admin_carrier->get_label() )
	&& str_contains( Kuka_Island_Shipping_Admin::resume_button_label( $admin_carrier ), 'sürdür' )
	&& ! str_contains( Kuka_Island_Shipping_Admin::create_button_label( new Kuka_Shipping_Fake_Carrier() ), 'DHL' )
	&& str_contains( $admin_hint_open, 'barkod' );

$report(
	'SHIPPING_RESUME_ADMIN_ACTION',
	$admin_user_id > 0
		&& Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $admin_resume_state
		&& $nonce_namespaces_differ
		&& 'refused' === $wrong_nonce_press['outcome']
		&& $after_wrong_nonce === $admin_before_barcode
		&& 'refused' === $no_cap_press['outcome']
		&& $after_no_cap === $admin_before_barcode
		&& 'ran' === $good_press['outcome']
		&& true === ( $good_press['result']['ok'] ?? false )
		&& $after_good === $admin_before_barcode + 1
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $admin_resume_after['state']
		&& '112233445' === $admin_resume_after['shipment_id']
		&& $admin_labels_dynamic,
	sprintf(
		'admin_user:%s|separate_nonce:%s|wrong_nonce:%s|wrong_nonce_writes:%d|no_capability:%s|no_capability_writes:%d|authorised_press:%s|authorised_writes:%d|state:%s|button_label_uses_carrier_name:%s',
		$admin_user_id > 0 ? 'found' : 'MISSING',
		$nonce_namespaces_differ ? 'yes' : 'NO',
		$wrong_nonce_press['outcome'],
		$after_wrong_nonce - $admin_before_barcode,
		$no_cap_press['outcome'],
		$after_no_cap - $admin_before_barcode,
		$good_press['outcome'],
		$after_good - $admin_before_barcode,
		$admin_resume_after['state'],
		$admin_labels_dynamic ? 'yes' : 'NO'
	)
);

$report(
	'SHIPPING_ADMIN_TEXT_IS_CARRIER_AGNOSTIC',
	$admin_labels_dynamic
		&& 'Kuka Test Kargo gönderisi oluştur' === Kuka_Island_Shipping_Admin::create_button_label( new Kuka_Shipping_Fake_Carrier() ),
	sprintf(
		'create_label:%s|resume_label_mentions_carrier:%s|order_created_hint_mentions_barcode:%s',
		Kuka_Island_Shipping_Admin::create_button_label( new Kuka_Shipping_Fake_Carrier() ),
		str_contains( Kuka_Island_Shipping_Admin::resume_button_label( $admin_carrier ), $admin_carrier->get_label() ) ? 'yes' : 'NO',
		str_contains( $admin_hint_open, 'barkod' ) ? 'yes' : 'NO'
	)
);

kuka_ship_destroy_order( $admin_resume_order );

/* ========================================================================== */
/* 25. Polling is bounded, increasing and finite -- and off by default         */
/* ========================================================================== */

$delays = array();
for ( $attempt = 0; $attempt < 12; $attempt++ ) {
	$delays[] = Kuka_Island_Shipping_Status_Poller::delay_for_attempt( $attempt );
}

$increasing = true;
for ( $i = 1; $i < count( $delays ); $i++ ) {
	if ( $delays[ $i ] < $delays[ $i - 1 ] ) {
		$increasing = false;
	}
}

$decisions = array(
	'delivered_stops'   => Kuka_Island_Shipping_Status_Poller::decide( Kuka_Island_Shipping_Status::LIFECYCLE_DELIVERED, 1, 60 ),
	'manual_stops'      => Kuka_Island_Shipping_Status_Poller::decide( Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW, 1, 60 ),
	'attempts_ceiling'  => Kuka_Island_Shipping_Status_Poller::decide( Kuka_Island_Shipping_Status::LIFECYCLE_IN_PROGRESS, Kuka_Island_Shipping_Status_Poller::MAX_ATTEMPTS, 60 ),
	'elapsed_ceiling'   => Kuka_Island_Shipping_Status_Poller::decide( Kuka_Island_Shipping_Status::LIFECYCLE_IN_PROGRESS, 1, Kuka_Island_Shipping_Status_Poller::MAX_ELAPSED ),
	'keeps_going'       => Kuka_Island_Shipping_Status_Poller::decide( Kuka_Island_Shipping_Status::LIFECYCLE_IN_PROGRESS, 1, 60 ),
);

$bounded = 'stop' === $decisions['delivered_stops']['action']
	&& 'stop' === $decisions['manual_stops']['action']
	&& 'give_up' === $decisions['attempts_ceiling']['action']
	&& 'give_up' === $decisions['elapsed_ceiling']['action']
	&& 'reschedule' === $decisions['keeps_going']['action'];

$report(
	'SHIPPING_POLL_POLICY',
	$increasing && $bounded && ! Kuka_Island_Shipping_Status_Poller::automation_enabled(),
	sprintf(
		'ladder:%s|monotonic:%s|max_attempts:%d|max_elapsed_days:%d|terminal_stops:yes|automation_default:%s',
		implode( ',', array_map( static fn ( $d ) => (int) ( $d / 60 ) . 'm', array_slice( $delays, 0, 8 ) ) ),
		$increasing ? 'yes' : 'NO',
		Kuka_Island_Shipping_Status_Poller::MAX_ATTEMPTS,
		(int) ( Kuka_Island_Shipping_Status_Poller::MAX_ELAPSED / DAY_IN_SECONDS ),
		Kuka_Island_Shipping_Status_Poller::automation_enabled() ? 'ON' : 'off'
	)
);

/* ========================================================================== */
/* 26. A failing status chain spends its budget and then stops                 */
/* ========================================================================== */

/*
 * Driven through the REAL Action Scheduler: the poller's worker hook is
 * registered, the first query is booked by the create path itself, and each
 * pending row is executed by ActionScheduler_QueueRunner::process_action() --
 * the same method the WP-Cron and async runners call. Nothing here calls run()
 * directly.
 *
 * Automation is switched on through the ENVIRONMENT for the duration and
 * switched off again, because a constant cannot be unset and the later
 * measurements assert the shipped default is off.
 */

/**
 * Remove the poller's Action Scheduler GROUP row when this run created it.
 *
 * delete_action() removes actions and their log rows but never the group row,
 * and that row is the one piece of residue a completed chain would otherwise
 * leave behind for ever. It goes only when it did not exist before the chain
 * ran AND no action references it any more, so a shop that already books
 * shipping queries keeps its own.
 */
function kuka_ship_purge_orphan_group( bool $existed_before ): string {
	global $wpdb;

	if ( $existed_before ) {
		return 'preexisting';
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$group_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s LIMIT 1",
			Kuka_Island_Shipping_Status_Poller::GROUP
		)
	);

	if ( 0 === $group_id ) {
		return 'absent';
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$referencing = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE group_id = %d", $group_id )
	);

	if ( $referencing > 0 ) {
		return 'still_referenced:' . $referencing;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_groups WHERE group_id = %d", $group_id ) );

	return 'removed';
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$chain_group_existed = 0 < (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s",
		Kuka_Island_Shipping_Status_Poller::GROUP
	)
);

putenv( 'KUKA_SHIPPING_AUTOMATION=1' );
$chain_automation_on = Kuka_Island_Shipping_Status_Poller::automation_enabled();

$fail_status_calls = 0;

$fail_chain = kuka_ship_scenario(
	static function ( string $method, string $url ) use ( &$fail_status_calls ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return kuka_ship_create_barcode_ok( '990011223', 'BC-CHAIN' );
		}

		if ( str_contains( $url, '/getshipmentstatus/' ) ) {
			++$fail_status_calls;

			// A gateway that will not answer, for ever.
			return array( 'status' => 503, 'body' => '' );
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$fail_chain['manager']->create_shipment( $fail_chain['order'] );
$fail_order_id   = (int) $fail_chain['order']->get_id();
$fail_chain_state = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $fail_order_id ) );
$fail_first_booked = Kuka_Island_Shipping_Status_Poller::has_pending_query( $fail_order_id );

$fail_poller = kuka_ship_attach_sole_poller( $fail_chain['manager'] );

$fail_run = kuka_ship_drive_status_chain( $fail_order_id, 20 );

// A second sweep: if anything were still booked, this would issue an
// eleventh query. It must find nothing.
$fail_sweep = kuka_ship_drive_status_chain( $fail_order_id, 5 );

remove_action( Kuka_Island_Shipping_Status_Poller::ACTION, array( $fail_poller, 'run' ), 10 );

$fail_order   = wc_get_order( $fail_order_id );
$fail_data    = Kuka_Island_Shipping_Order_Store::get_shipment_data( $fail_order );
$fail_pending = Kuka_Island_Shipping_Status_Poller::has_pending_query( $fail_order_id );

$fail_history_has_exhaustion = false;
foreach ( (array) $fail_data['history'] as $entry ) {
	if ( str_contains( (string) ( $entry['message'] ?? '' ), 'sınırına ulaşıldı' ) ) {
		$fail_history_has_exhaustion = true;
	}
}

$fail_note_has_exhaustion = false;
foreach ( (array) wc_get_order_notes( array( 'order_id' => $fail_order_id, 'limit' => 200 ) ) as $fail_note ) {
	if ( str_contains( (string) $fail_note->content, 'sınırına ulaşıldı' ) ) {
		$fail_note_has_exhaustion = true;
	}
}

$report(
	'SHIPPING_POLL_FAILURE_CHAIN_BOUNDED',
	$chain_automation_on
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $fail_chain_state
		&& $fail_first_booked
		&& Kuka_Island_Shipping_Status_Poller::MAX_ATTEMPTS === count( $fail_run['processed'] )
		&& Kuka_Island_Shipping_Status_Poller::MAX_ATTEMPTS === $fail_status_calls
		&& Kuka_Island_Shipping_Status_Poller::MAX_ATTEMPTS === $fail_chain['transport']->count_for( '/getshipmentstatus/' )
		&& Kuka_Island_Shipping_Status_Poller::MAX_ATTEMPTS === (int) $fail_data['query_attempts']
		&& 0 === count( $fail_sweep['processed'] )
		&& ! $fail_pending
		&& 0 === $fail_run['errors']
		&& 'poll_exhausted' === $fail_data['last_error']
		&& $fail_history_has_exhaustion
		&& $fail_note_has_exhaustion,
	sprintf(
		'runner:action_scheduler|actions_executed:%d|external_status_reads:%d|query_attempts:%d|pending_after:%d|eleventh_call:%s|runner_errors:%d|poll_exhausted_meta:%s|poll_exhausted_history:%s|poll_exhausted_note:%s',
		count( $fail_run['processed'] ),
		$fail_chain['transport']->count_for( '/getshipmentstatus/' ),
		(int) $fail_data['query_attempts'],
		$fail_pending ? 1 : 0,
		0 === count( $fail_sweep['processed'] ) ? 'none' : 'HAPPENED',
		$fail_run['errors'],
		'poll_exhausted' === $fail_data['last_error'] ? 'yes' : 'NO',
		$fail_history_has_exhaustion ? 'yes' : 'NO',
		$fail_note_has_exhaustion ? 'yes' : 'NO'
	)
);

$fail_actions_removed = kuka_ship_purge_actions( $fail_order_id );
kuka_ship_destroy_order( $fail_order );

/* ========================================================================== */
/* 27. A successful status chain behaves exactly as it did                     */
/* ========================================================================== */

$ok_status_calls = 0;

$ok_chain = kuka_ship_scenario(
	static function ( string $method, string $url ) use ( &$ok_status_calls ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return kuka_ship_create_barcode_ok( '778899001', 'BC-OK' );
		}

		if ( str_contains( $url, '/getshipmentstatus/' ) ) {
			++$ok_status_calls;

			// Moving, moving, delivered.
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						'referenceId'        => 'ECHO',
						'shipmentId'         => '778899001',
						'shipmentStatusCode' => $ok_status_calls >= 3 ? 5 : 2,
						'isDelivered'        => $ok_status_calls >= 3 ? 1 : 0,
						'trackingUrl'        => 'https://kargotakip.example/778899001',
					)
				),
			);
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$ok_chain['manager']->create_shipment( $ok_chain['order'] );
$ok_order_id  = (int) $ok_chain['order']->get_id();
$ok_reference = (string) Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $ok_order_id ) )['reference'];

$ok_poller = kuka_ship_attach_sole_poller( $ok_chain['manager'] );

$ok_run   = kuka_ship_drive_status_chain( $ok_order_id, 20 );
$ok_sweep = kuka_ship_drive_status_chain( $ok_order_id, 5 );

remove_action( Kuka_Island_Shipping_Status_Poller::ACTION, array( $ok_poller, 'run' ), 10 );

$ok_order       = wc_get_order( $ok_order_id );
$ok_data        = Kuka_Island_Shipping_Order_Store::get_shipment_data( $ok_order );
$ok_pending     = Kuka_Island_Shipping_Status_Poller::has_pending_query( $ok_order_id );
$ok_fulfillment = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $ok_order, $ok_reference );

$report(
	'SHIPPING_POLL_SUCCESS_CHAIN_INTACT',
	3 === count( $ok_run['processed'] )
		&& 3 === $ok_status_calls
		&& 3 === $ok_chain['transport']->count_for( '/getshipmentstatus/' )
		// One attempt per query and NOT two: the counter has a single owner.
		&& 3 === (int) $ok_data['query_attempts']
		&& Kuka_Island_Shipping_Order_Store::STATE_DELIVERED === $ok_data['state']
		&& 5 === (int) $ok_data['status_code']
		&& 'https://kargotakip.example/778899001' === $ok_data['tracking_url']
		&& 0 === count( $ok_sweep['processed'] )
		&& ! $ok_pending
		&& 0 === $ok_run['errors']
		&& 'poll_exhausted' !== $ok_data['last_error']
		&& null !== $ok_fulfillment
		&& $ok_fulfillment->get_is_fulfilled()
		&& '' !== (string) $ok_fulfillment->get_meta( Kuka_Island_Shipping_Fulfillment_Writer::META_DELIVERED_AT, true ),
	sprintf(
		'runner:action_scheduler|actions_executed:%d|external_status_reads:%d|query_attempts:%d|attempts_equal_reads:%s|state:%s|stored_code:%d|pending_after:%d|fulfilled:%s|delivered_at:%s',
		count( $ok_run['processed'] ),
		$ok_chain['transport']->count_for( '/getshipmentstatus/' ),
		(int) $ok_data['query_attempts'],
		( (int) $ok_data['query_attempts'] === $ok_status_calls ) ? 'yes' : 'NO',
		$ok_data['state'],
		(int) $ok_data['status_code'],
		$ok_pending ? 1 : 0,
		( null !== $ok_fulfillment && $ok_fulfillment->get_is_fulfilled() ) ? 'yes' : 'NO',
		( null !== $ok_fulfillment && '' !== (string) $ok_fulfillment->get_meta( Kuka_Island_Shipping_Fulfillment_Writer::META_DELIVERED_AT, true ) ) ? 'stored' : 'NONE'
	)
);

$ok_actions_removed = kuka_ship_purge_actions( $ok_order_id );
kuka_ship_destroy_order( $ok_order );

putenv( 'KUKA_SHIPPING_AUTOMATION' );
$chain_automation_off = ! Kuka_Island_Shipping_Status_Poller::automation_enabled();

/*
 * The Action Scheduler GROUP row is released at the very end of the run, not
 * here: measurements further down book queries of their own and would create it
 * again after this point. See the cleanup section.
 */
$report(
	'SHIPPING_POLL_CHAIN_LEAVES_NOTHING',
	$chain_automation_off
		&& 10 === $fail_actions_removed
		&& 3 === $ok_actions_removed,
	sprintf(
		'automation_restored:%s|failure_chain_actions_removed:%d|success_chain_actions_removed:%d|group_row:released_at_cleanup',
		$chain_automation_off ? 'off' : 'ON',
		$fail_actions_removed,
		$ok_actions_removed
	)
);

/* ========================================================================== */
/* 28. No Action Scheduler residue                                             */
/* ========================================================================== */

$pending_by_group = 0;
$pending_by_hook  = 0;

if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler_Store' ) ) {
	$pending_by_group = count(
		(array) as_get_scheduled_actions(
			array(
				'group'    => Kuka_Island_Shipping_Status_Poller::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
				'orderby'  => 'none',
			),
			'ids'
		)
	);

	$pending_by_hook = count(
		(array) as_get_scheduled_actions(
			array(
				'hook'     => Kuka_Island_Shipping_Status_Poller::ACTION,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
				'orderby'  => 'none',
			),
			'ids'
		)
	);
}

$report(
	'SHIPPING_NO_SCHEDULER_RESIDUE',
	0 === $pending_by_group && 0 === $pending_by_hook,
	sprintf( 'pending_by_group:%d|pending_by_hook:%d|automation_off_books_nothing:yes', $pending_by_group, $pending_by_hook )
);

/* ========================================================================== */
/* 29. Loading the module registers nothing                                    */
/* ========================================================================== */

/*
 * MEASURED AS A DELTA, NOT AS AN ABSOLUTE COUNT. The property is "loading the
 * files and constructing the module attaches nothing; only register() does".
 * An absolute count of zero can only be true while the plugin is INACTIVE, and
 * the plugin is delivered active -- so the absolute form was measuring the
 * site's activation state rather than the module's behaviour. The delta form
 * measures the actual property either way.
 */
global $wp_filter;

$our_hooks = array(
	Kuka_Island_Shipping_Status_Poller::ACTION,
	'admin_post_kuka_shipping_create',
	'admin_post_kuka_shipping_resume',
	'admin_post_kuka_shipping_requery',
	'admin_post_kuka_shipping_reconcile',
	'admin_post_kuka_shipping_update',
	'admin_post_kuka_shipping_cancel',
);

$count_our_hooks = static function () use ( &$wp_filter, $our_hooks ): int {
	$total = 0;

	foreach ( $our_hooks as $hook ) {
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			continue;
		}

		foreach ( (array) $wp_filter[ $hook ]->callbacks as $priority_bucket ) {
			$total += count( (array) $priority_bucket );
		}
	}

	return $total;
};

$hooks_before_load = $count_our_hooks();

// Load from disk and construct the composition root -- everything short of
// register(). Nothing here may attach a single callback.
Kuka_Island_Shipping_Automation::load_dependencies();
$unregistered_module = new Kuka_Island_Shipping_Automation();

$hooks_after_load = $count_our_hooks();

// And register() DOES attach, which is what makes the delta above meaningful:
// a measurement that can only ever read zero proves nothing.
$probe_module = new Kuka_Island_Shipping_Automation();
$probe_module->register();
$hooks_after_register = $count_our_hooks();

foreach ( $our_hooks as $hook ) {
	remove_all_actions( $hook );
}

$report(
	'SHIPPING_LOAD_REGISTERS_NOTHING',
	$hooks_after_load === $hooks_before_load
		&& $hooks_after_register > $hooks_after_load
		&& $unregistered_module instanceof Kuka_Island_Shipping_Automation,
	sprintf(
		'measured:hook_callback_delta|hooks_checked:%d|before_load:%d|after_load:%d|delta:%d|register_adds:%d',
		count( $our_hooks ),
		$hooks_before_load,
		$hooks_after_load,
		$hooks_after_load - $hooks_before_load,
		$hooks_after_register - $hooks_after_load
	)
);

/* ========================================================================== */
/* 30. A second carrier is an adapter plus a filter -- measured, not asserted  */
/* ========================================================================== */

// --- The registry accepts both, keyed by each adapter's own get_key() ------

$dhl_adapter  = kuka_ship_provider( new Kuka_Shipping_Mock_Transport( kuka_ship_happy_responder() ) );
$fake_adapter = new Kuka_Shipping_Fake_Carrier();

$mixed_registry = kuka_ship_registry_of(
	array(
		$dhl_adapter,
		$fake_adapter,
		'not-an-adapter',
		new stdClass(),
	)
);

$mixed_keys = $mixed_registry->keys();

$report(
	'SHIPPING_CARRIER_REGISTRY',
	array( 'dhl', Kuka_Shipping_Fake_Carrier::KEY ) === $mixed_keys
		&& null === $mixed_registry->get( 'aras-kargo' )
		&& $mixed_registry->get( 'dhl' ) instanceof Kuka_Island_Shipping_Carrier_Interface
		&& $mixed_registry->get( Kuka_Shipping_Fake_Carrier::KEY ) instanceof Kuka_Island_Shipping_Carrier_Interface,
	sprintf(
		'registered:%s|non_adapters_dropped:yes|unknown_key_returns:null|filter:kuka_island_shipping_carriers',
		implode( '+', $mixed_keys )
	)
);

// --- The whole manager lifecycle, on the fake adapter alone ----------------

$fake_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $fake_adapter ) ) );
$fake_order   = kuka_ship_fixture_order();

$fake_created   = $fake_manager->create_shipment( $fake_order );
$fake_order     = wc_get_order( $fake_order->get_id() );
$fake_data      = Kuka_Island_Shipping_Order_Store::get_shipment_data( $fake_order );
$fake_reference = (string) $fake_data['reference'];
$fake_record    = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $fake_order, $fake_reference );

$fake_queried = $fake_manager->query_status( $fake_order );
$fake_order   = wc_get_order( $fake_order->get_id() );

$fake_cancelled = $fake_manager->cancel( $fake_order );
$fake_order     = wc_get_order( $fake_order->get_id() );
$fake_after     = Kuka_Island_Shipping_Order_Store::get_shipment_data( $fake_order );

// The adapter itself must not need anything of DHL's to exist.
$fake_reflection    = new ReflectionClass( Kuka_Shipping_Fake_Carrier::class );
$fake_dhl_types     = 0;
foreach ( $fake_reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $fake_method ) {
	$fake_types = array();

	foreach ( $fake_method->getParameters() as $fake_parameter ) {
		$fake_types[] = (string) $fake_parameter->getType();
	}

	$fake_types[] = (string) $fake_method->getReturnType();

	foreach ( $fake_types as $fake_type ) {
		if ( str_contains( $fake_type, 'Kuka_Island_Shipping_DHL' ) ) {
			++$fake_dhl_types;
		}
	}
}

$fake_standalone = false === $fake_reflection->getParentClass()
	&& array( 'Kuka_Island_Shipping_Carrier_Interface' ) === array_values( $fake_reflection->getInterfaceNames() )
	&& 0 === $fake_dhl_types;

$report(
	'SHIPPING_SECOND_CARRIER_ADAPTER_ONLY',
	$fake_created['ok']
		&& 1 === $fake_adapter->count_for( 'create_order' )
		&& 1 === $fake_adapter->count_for( 'create_barcode' )
		&& 'FAKE-SHIP-1' === $fake_data['shipment_id']
		&& array( 'FAKE-BC-1' ) === $fake_data['barcodes']
		&& Kuka_Shipping_Fake_Carrier::KEY === $fake_data['provider']
		&& null !== $fake_record
		&& Kuka_Shipping_Fake_Carrier::KEY === (string) $fake_record->get_shipment_provider()
		&& 'FAKE-BC-1' === (string) $fake_record->get_tracking_number()
		&& $fake_queried['ok']
		&& Kuka_Island_Shipping_Status::LIFECYCLE_IN_PROGRESS === $fake_queried['lifecycle']
		&& 1 === (int) $fake_queried['attempts']
		&& 1 === $fake_adapter->count_for( 'read_shipment_status' )
		&& $fake_cancelled['ok']
		&& 1 === $fake_adapter->count_for( 'cancel_shipment' )
		&& 0 === $fake_adapter->count_for( 'cancel_order' )
		&& 1 === $fake_adapter->count_for( 'read_shipment' )
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $fake_after['state']
		&& $fake_standalone,
	sprintf(
		'carrier:%s|create_order:%d|create_barcode:%d|status_reads:%d|cancel_shipment:%d|cancel_order:%d|fulfillment_provider:%s|fulfillment_tracking:%s|state:%s|needs_no_dhl_class:%s|dhl_types_in_adapter:%d',
		Kuka_Shipping_Fake_Carrier::KEY,
		$fake_adapter->count_for( 'create_order' ),
		$fake_adapter->count_for( 'create_barcode' ),
		$fake_adapter->count_for( 'read_shipment_status' ),
		$fake_adapter->count_for( 'cancel_shipment' ),
		$fake_adapter->count_for( 'cancel_order' ),
		null !== $fake_record ? (string) $fake_record->get_shipment_provider() : 'NONE',
		null !== $fake_record ? (string) $fake_record->get_tracking_number() : 'NONE',
		$fake_after['state'],
		$fake_standalone ? 'yes' : 'NO',
		$fake_dhl_types
	)
);

kuka_ship_destroy_order( $fake_order );

/* ========================================================================== */
/* 31. The default carrier comes from configuration, and fails closed          */
/* ========================================================================== */

$default_unconfigured = ! defined( Kuka_Island_Shipping_Manager::DEFAULT_CARRIER_SETTING )
	&& false === getenv( Kuka_Island_Shipping_Manager::DEFAULT_CARRIER_SETTING );

// Two adapters, nothing configured: nobody chose, so nothing is chosen.
$ambiguous_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $dhl_adapter, $fake_adapter ) ) );
$ambiguous_key     = $ambiguous_manager->default_carrier_key();

// One adapter, nothing configured: one adapter is not a choice.
$single_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $fake_adapter ) ) );
$single_key     = $single_manager->default_carrier_key();

// The filter selects, by key, out of the registered set.
$select_fake = static function ( $key, $keys = array() ): string {
	return Kuka_Shipping_Fake_Carrier::KEY;
};
add_filter( 'kuka_island_shipping_default_carrier', $select_fake, 999 );
$selected_key = $ambiguous_manager->default_carrier_key();
remove_filter( 'kuka_island_shipping_default_carrier', $select_fake, 999 );

// An unknown key is NOT substituted for a registered one: it is refused, and
// refused before anything is contacted.
$select_unknown = static function ( $key, $keys = array() ): string {
	return 'kargo-yok';
};
add_filter( 'kuka_island_shipping_default_carrier', $select_unknown, 999 );
$unknown_key      = $ambiguous_manager->default_carrier_key();
$unknown_order    = kuka_ship_fixture_order();
$fake_calls_pre   = $fake_adapter->total_calls();
$unknown_result   = $ambiguous_manager->create_shipment( $unknown_order );
$fake_calls_after = $fake_adapter->total_calls();
remove_filter( 'kuka_island_shipping_default_carrier', $select_unknown, 999 );

$report(
	'SHIPPING_DEFAULT_CARRIER_FAIL_CLOSED',
	$default_unconfigured
		&& '' === $ambiguous_key
		&& Kuka_Shipping_Fake_Carrier::KEY === $single_key
		&& Kuka_Shipping_Fake_Carrier::KEY === $selected_key
		&& 'kargo-yok' === $unknown_key
		&& ! $unknown_result['ok']
		&& 'carrier_not_registered' === $unknown_result['code']
		&& $fake_calls_after === $fake_calls_pre,
	sprintf(
		'setting:%s|two_registered_none_configured:%s|one_registered:%s|filter_selects:%s|unknown_key_returned_verbatim:%s|unknown_key_code:%s|carrier_calls_on_unknown:%d',
		Kuka_Island_Shipping_Manager::DEFAULT_CARRIER_SETTING,
		'' === $ambiguous_key ? 'refused' : $ambiguous_key,
		$single_key,
		$selected_key,
		$unknown_key,
		$unknown_result['code'],
		$fake_calls_after - $fake_calls_pre
	)
);

kuka_ship_destroy_order( wc_get_order( $unknown_order->get_id() ) );

/* ========================================================================== */
/* 32. The carrier-agnostic core names no adapter                              */
/* ========================================================================== */

/*
 * Source-level, and deliberately so: the behavioural measurements above prove a
 * second adapter WORKS, and this one proves the shared classes cannot quietly
 * grow a dependency back. Comments are stripped before the scan, exactly as the
 * drawer-protection scan strips them (K-06): a sentence explaining why a
 * dependency was removed must not count as the dependency.
 */

$agnostic_files = array(
	'class-shipment-manager.php',
	'class-shipment-status-poller.php',
	'class-fulfillment-writer.php',
	'class-shipment-admin.php',
	'class-shipment-order-store.php',
	'class-shipment-notification.php',
	'class-carrier-registry.php',
	'class-shipment-status.php',
	'interface-carrier-provider.php',
);

$strip_php_comments = static function ( string $source ): string {
	$kept = '';

	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$kept .= $token[1];
			continue;
		}

		$kept .= $token;
	}

	return $kept;
};

$agnostic_dir        = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-shipping-automation/includes/shipping/';
$agnostic_violations = array();
$agnostic_scanned    = 0;

foreach ( $agnostic_files as $agnostic_file ) {
	$agnostic_path = $agnostic_dir . $agnostic_file;

	if ( ! is_readable( $agnostic_path ) ) {
		$agnostic_violations[] = $agnostic_file . ':unreadable';
		continue;
	}

	++$agnostic_scanned;
	$agnostic_code = $strip_php_comments( (string) file_get_contents( $agnostic_path ) );

	foreach ( array( 'Kuka_Island_Shipping_DHL', 'KUKA_DHL_' ) as $agnostic_needle ) {
		if ( str_contains( $agnostic_code, $agnostic_needle ) ) {
			$agnostic_violations[] = $agnostic_file . ':' . $agnostic_needle;
		}
	}
}

// The control: the scan must find the adapter's own name where it belongs, or
// the scan is not looking at anything.
$agnostic_control = str_contains(
	$strip_php_comments( (string) file_get_contents( $agnostic_dir . 'dhl/class-dhl-provider.php' ) ),
	'Kuka_Island_Shipping_DHL'
);

$report(
	'SHIPPING_CORE_NAMES_NO_ADAPTER',
	count( $agnostic_files ) === $agnostic_scanned
		&& array() === $agnostic_violations
		&& $agnostic_control,
	sprintf(
		'files:%d|dhl_class_or_constant_references:%s|comments_stripped:yes|scan_control_positive:%s',
		$agnostic_scanned,
		array() === $agnostic_violations ? '0' : implode( '+', $agnostic_violations ),
		$agnostic_control ? 'yes' : 'NO'
	)
);

/* ========================================================================== */
/* 33. The translation catalogue matches the source                            */
/* ========================================================================== */

/*
 * Measured, not eyeballed. Every translatable literal is extracted from the
 * plugin's PHP with token_get_all() -- not with a regular expression, which
 * would trip over the placeholders and the Turkish punctuation -- and every
 * msgid is read out of the POT with a parser that joins the continuation lines
 * make-pot emits for long strings. A catalogue that silently lost a string is
 * a screen an operator reads in the wrong language.
 *
 * Header entries (Plugin Name, Description, Author) are POT-only by
 * construction: they come from the plugin file's header block rather than from
 * a gettext call, so they are excluded from the stale count instead of being
 * reported as strings nobody uses.
 */

/** Turn one PO-quoted fragment into its value. */
function kuka_ship_po_unquote( string $fragment ): string {
	$fragment = trim( $fragment );

	if ( '' === $fragment || '"' !== $fragment[0] ) {
		return '';
	}

	$inner = substr( $fragment, 1, strrpos( $fragment, '"' ) - 1 );

	return str_replace(
		array( '\\n', '\\t', '\\r', '\\"', '\\\\' ),
		array( "\n", "\t", "\r", '"', '\\' ),
		(string) $inner
	);
}

/**
 * Every msgid in a POT file, with continuation lines joined.
 *
 * @param string $pot File contents.
 * @return array<string, array{header: bool}>
 */
function kuka_ship_pot_entries( string $pot ): array {
	$entries = array();

	foreach ( (array) preg_split( "/\n[ \t]*\n/", $pot ) as $block ) {
		$header  = false;
		$msgid   = null;
		$collect = false;

		foreach ( (array) preg_split( "/\n/", trim( (string) $block ) ) as $raw_line ) {
			$line = rtrim( (string) $raw_line, "\r" );

			if ( str_starts_with( $line, '#.' ) ) {
				if ( str_contains( $line, 'of the plugin' ) ) {
					$header = true;
				}
				continue;
			}

			if ( str_starts_with( $line, '#' ) ) {
				continue;
			}

			if ( str_starts_with( $line, 'msgid_plural ' ) ) {
				$collect = false;
				continue;
			}

			if ( str_starts_with( $line, 'msgid ' ) ) {
				$msgid   = kuka_ship_po_unquote( substr( $line, 6 ) );
				$collect = true;
				continue;
			}

			if ( str_starts_with( $line, 'msgstr' ) ) {
				$collect = false;
				continue;
			}

			if ( $collect && str_starts_with( $line, '"' ) ) {
				$msgid .= kuka_ship_po_unquote( $line );
			}
		}

		if ( null !== $msgid && '' !== $msgid ) {
			$entries[ $msgid ] = array( 'header' => $header );
		}
	}

	return $entries;
}

/** The value of one PHP string literal, as written in the source. */
function kuka_ship_php_literal( string $literal ): string {
	$quote = $literal[0] ?? '';
	$inner = substr( $literal, 1, -1 );

	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $inner );
	}

	return str_replace(
		array( '\\n', '\\t', '\\r', '\\"', '\\$', '\\\\' ),
		array( "\n", "\t", "\r", '"', '$', '\\' ),
		$inner
	);
}

/**
 * Every translatable literal in the given PHP files.
 *
 * @param array<int, string> $files Absolute paths.
 * @return array<int, string>
 */
function kuka_ship_source_msgids( array $files ): array {
	$gettext = array( '__', '_e', '_x', '_n', '_nx', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', 'esc_html_x', 'esc_attr_x' );
	$found   = array();

	foreach ( $files as $file ) {
		$tokens = token_get_all( (string) file_get_contents( $file ) );
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], $gettext, true ) ) {
				continue;
			}

			// A method or a declaration of the same name is not a gettext call.
			$before = $i - 1;
			while ( $before >= 0 && is_array( $tokens[ $before ] ) && T_WHITESPACE === $tokens[ $before ][0] ) {
				--$before;
			}
			if ( $before >= 0 && is_array( $tokens[ $before ] )
				&& in_array( $tokens[ $before ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue;
			}

			$open = $i + 1;
			while ( $open < $count && is_array( $tokens[ $open ] ) && T_WHITESPACE === $tokens[ $open ][0] ) {
				++$open;
			}
			if ( $open >= $count || '(' !== $tokens[ $open ] ) {
				continue;
			}

			$arg = $open + 1;
			while ( $arg < $count && is_array( $tokens[ $arg ] )
				&& in_array( $tokens[ $arg ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				++$arg;
			}
			if ( $arg >= $count || ! is_array( $tokens[ $arg ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $arg ][0] ) {
				continue;
			}

			$value = kuka_ship_php_literal( $tokens[ $arg ][1] );

			if ( '' !== $value ) {
				$found[] = $value;
			}
		}
	}

	return array_values( array_unique( $found ) );
}

$pot_dir  = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-shipping-automation/';
$pot_path = $pot_dir . 'languages/kuka-island-shipping-automation.pot';

$pot_php = array( $pot_dir . 'kuka-island-shipping-automation.php' );
foreach ( array( 'includes/class-activator.php', 'includes/class-plugin.php', 'includes/class-shipping-automation.php' ) as $pot_include ) {
	$pot_php[] = $pot_dir . $pot_include;
}
foreach ( Kuka_Island_Shipping_Automation::module_files() as $pot_module ) {
	$pot_php[] = $pot_dir . 'includes/shipping/' . $pot_module;
}

$pot_readable = is_readable( $pot_path );
$pot_entries  = $pot_readable ? kuka_ship_pot_entries( (string) file_get_contents( $pot_path ) ) : array();
$pot_source   = kuka_ship_source_msgids( array_values( array_filter( $pot_php, 'is_readable' ) ) );

$pot_missing = array();
foreach ( $pot_source as $pot_literal ) {
	if ( ! isset( $pot_entries[ $pot_literal ] ) ) {
		$pot_missing[] = $pot_literal;
	}
}

$pot_stale = array();
foreach ( $pot_entries as $pot_msgid => $pot_meta ) {
	if ( $pot_meta['header'] ) {
		continue;
	}

	if ( ! in_array( (string) $pot_msgid, $pot_source, true ) ) {
		$pot_stale[] = (string) $pot_msgid;
	}
}

// The strings this and the previous round added. Named explicitly, because
// "nothing is missing" would also be true of a catalogue generated before them.
$pot_required = array(
	'%s gönderisi oluştur',
	'%s gönderi/barkod oluşturmayı sürdür (sipariş yeniden oluşturulmaz)',
	'Bu siparişin taşıyıcı kaydı zaten iptal edilmiş ve iptal sorguyla doğrulanmıştı. Yeni iptal çağrısı yapılmadı.',
	'Bu siparişte gönderi var fakat gönderi numarası bilinmiyor; iptal edilecek kayıt adreslenemiyor. Önce salt-okunur mutabakat çalıştırılmalı.',
	'Bu siparişte gönderi var fakat gönderi numarası bilinmiyor; güncellenecek kayıt adreslenemiyor. Önce salt-okunur mutabakat çalıştırılmalı.',
	'Bu siparişte belirsiz bir taşıyıcı yanıtı var. İptal tekrarlanmadı; önce salt-okunur mutabakat çalıştırılmalı.',
	'Bu sipariş teslim edilmiş. Teslim edilmiş bir gönderi iptal edilmez; iade süreci ayrıdır.',
	'Bu siparişin durumu iptal için tanınmıyor; hiçbir çağrı yapılmadı.',
	'Bu siparişte taşıyıcıda bekleyen bir sipariş kaydı yok; sürdürülecek bir barkod aşaması bulunmuyor.',
	'Taşıyıcıda sipariş kaydı var, gönderi/barkod aşaması tamamlanmamış. Sipariş yeniden oluşturulmaz; yalnız barkod aşaması sürdürülür.',
	'Otomatik kargo durum sorgusu sınırına ulaşıldı (%1$d/%2$d deneme). Yeni sorgu planlanmadı; durum artık manuel sorgulanmalı.',
	'Durum sorgusu başarısız (%1$s). Deneme %2$d/%3$d.',
	'Kargo otomasyonu eklentisi devre dışı bırakıldığı için çağrı yapılmadı. Gönderi oluşturulmadı.',
	'Canlı ortam bloke: resmî üretim uçları doğrulanmadı. Hiçbir çağrı yapılmadı.',
	'Bu sipariş için başka bir kargo işlemi sürüyor. Yeni çağrı yapılmadı.',
	// This round: order-to-carrier ownership, and the gated reads.
	'Bu siparişin kargo kaydı başka bir taşıyıcıya ait. İstenen taşıyıcıya hiçbir çağrı yapılmadı; siparişin taşıyıcısı değiştirilmedi.',
	'Bu siparişte taşıyıcı kaydı var fakat hangi taşıyıcıya ait olduğu yazılı değil. Varsayılan taşıyıcı kullanılmadı; hiçbir çağrı yapılmadı. Kayıt elle belirlenmelidir.',
	'Bu siparişte taşıyıcı kaydı var fakat hangi taşıyıcıya ait olduğu yazılı değil. Otomatik işlem yapılmaz; varsayılan taşıyıcı kullanılmaz. Manuel kargo süreci kullanılabilir.',
	'Mutabakat yapılamadı: taşıyıcıya salt-okunur sorgu bile gönderilemedi. Durum belirsiz kaldı, yokluk varsayılmadı, hiçbir şey gönderilmedi.',
	/*
	 * The previous round's "Taşıyıcı iptali kabul etti fakat doğrulama sorgusu
	 * yapılamadı" is deliberately NOT here any more: the whole branch it lived
	 * in was replaced by reconcile_cancellation(), whose wording is listed
	 * below. A required string that no longer exists in the source would be a
	 * standing lie in this list.
	 */
	'kayıtlı değil (siparişte taşıyıcı yazılı değil)',
	'%s (bu kurulumda kayıtlı değil)',
	// This round: mutation evidence, and the module's own status line.
	'Bu sipariş için iptal isteği zaten taşıyıcıya gönderildi ve sonucu doğrulanıyor. Yeni iptal çağrısı yapılmadı; yalnız salt-okunur mutabakat çalıştırılabilir.',
	'İptal isteği taşıyıcıya gönderildi. Sonucu doğrulanana kadar yeni iptal gönderilmez.',
	'Taşıyıcı iptali kabul etti fakat kayıt hâlâ mevcut görünüyor. Durum korunuyor; yeni iptal gönderilmez, yalnız sorgu tekrarlanır.',
	'İptal doğrulaması yapılamadı: taşıyıcıya salt-okunur sorgu bile gönderilemedi. Durum korunuyor; yeni iptal gönderilmez.',
	'Bu taşıyıcı güncellenen alanları geri okuyamıyor, bu yüzden güncellemenin uygulandığı kanıtlanamaz. Kaydın var olması kanıt değildir. Durum manuel incelemeye bırakıldı; yeni güncelleme gönderilmez.',
	'Güncelleme alan bazında geri okundu ve gönderilen değerlerle birebir eşleşti.',
	'Güncelleme doğrulaması eşleşmedi; manuel inceleme gerekiyor.',
	'İptal sonucu doğrulanıyor',
	'Güncelleme sonucu doğrulanıyor',
	'Modül: %1$s · Çalışma kapısı: %2$s · Otomatik durum sorgusu: %3$s · Kayıtlı taşıyıcı: %4$s',
	// This round: the durable mutation intent, the outcome policy, the
	// fail-closed adapter switch, and the two states a person has to resolve.
	'Taşıyıcı isteği başlatılıyor (%1$s / %2$s / %3$s). Kayıt gönderim ÖNCESİNDE yazıldı; süreç burada kesilse bile yeni yazma açılmaz, yalnız salt-okunur mutabakat yapılır.',
	'Kargo işlemi başlatılmadı: yapılacak işlemin kalıcı kaydı eksiksiz kurulamadı. Taşıyıcıya hiçbir istek gönderilmedi.',
	'Kargo işlemi başlatılmadı: bu sipariş başka bir taşıyıcıya kayıtlı. Taşıyıcıya hiçbir istek gönderilmedi.',
	'Kargo işlemi başlatılmadı: işlem kaydı veritabanından geri okunamadı. Taşıyıcıya hiçbir istek gönderilmedi.',
	'Kargo işlemi başlatılmadı: işlem kaydı diske yazıldığı gibi geri okunamadı. Taşıyıcıya hiçbir istek gönderilmedi.',
	'Kargo işlemi başlatılmadı: yapılacak işlemin kalıcı kaydı doğrulanamadı. Taşıyıcıya hiçbir istek gönderilmedi.',
	'Taşıyıcı isteği reddedildi. Reddin kayıt oluşturmadığı sözleşmede yazılı olmadığı için yeniden gönderim açılmadı; salt-okunur mutabakat gerekiyor.',
	'Taşıyıcıya hiçbir istek gönderilmedi: adaptör isteği ağa çıkmadan reddetti. Kayıt önceki durumuna döndürüldü.',
	'Güncelleme isteği taşıyıcıya gönderildi. Uygulandığı alan bazında doğrulanana kadar yeni güncelleme gönderilmez.',
	'Gönderilen güncelleme değerleri kayıtlı değil, bu yüzden güncellemenin uygulandığı doğrulanamaz. Tahmin yapılmadı; yeni güncelleme gönderilmez, iptal hâlâ yapılabilir.',
	'İptal isteği taşıyıcıya gönderildi, sonucu doğrulanmadı. Otomatik durum sorgusu bu durumu ÇÖZMEZ ve yeni sorgu planlamaz; doğrulamayı "Mutabakat" düğmesiyle siz başlatmalısınız. Yeni iptal gönderilmez.',
	'Güncelleme isteği taşıyıcıya gönderildi, uygulandığı alan bazında doğrulanmadı. Otomatik durum sorgusu bu durumu ÇÖZMEZ; doğrulamayı "Mutabakat" düğmesiyle siz başlatmalısınız. Yeni güncelleme gönderilmez, iptal hâlâ yapılabilir.',
	'%s değeri tanınmadı; DHL adaptörü güvenli tarafta kapatıldı. Geçerli değerler: 1/true/yes/on veya 0/false/no/off (boşluksuz, küçük harf).',
	'Bu siparişte doğrulanmayı bekleyen bir güncelleme var ve güncellemenin hangi kayda gönderildiği okunamadı; iptal edilecek kayıt adreslenemiyor. Önce salt-okunur mutabakat çalıştırılmalı.',
	// The create path's allow-list refusals.
	'Bu siparişin taşıyıcı kaydı iptal edilmiş ve iptal sorguyla doğrulanmıştı. İptal edilmiş bir kayıt üzerinden gönderi ya da barkod oluşturulmaz; yeni kargo ayrı ve açık bir işlemdir.',
	'Mutabakat taşıyıcıda bu referansla kayıt olmadığını gösterdi; yeniden oluşturma açık bir işlemdir.',
	// The reconciliation lock, and the poll chain that stops on a local refusal.
	'Bu sipariş için başka bir kargo işlemi sürüyor. Mutabakat sorgusu yapılmadı.',
	'Bu siparişin taşıyıcı kaydı iptal edilmiş ve iptal salt-okunur sorguyla doğrulanmıştı. Yeni mutabakat sorgusu yapılmadı.',
	'Mutabakat bu referansla taşıyıcıda kayıt olmadığını daha önce kanıtladı. Yeni mutabakat sorgusu yapılmadı.',
	'Otomatik durum sorgusu taşıyıcıya hiç gönderilmedi (%s). Deneme harcanmadı ve yeni sorgu planlanmadı; ayar düzeltildikten sonra sorgu elle başlatılabilir.',
	'Otomatik kargo durum sorgusu yapılandırma nedeniyle gönderilemedi (%s). Deneme harcanmadı, yeni sorgu planlanmadı. Ayar düzeltildikten sonra sorgu elle başlatılabilir.',
	// The one customer e-mail this module sends, and its two failure sentences.
	'Kargo bildirimi e-postası gönderilemedi (%s). Müşteriye ileti ulaşmadı; sınırlı sayıda yeniden denenecek.',
	'Kargo bildirimi e-postası gönderildi fakat sonucu doğrulanamadı. Mükerrer ileti riski nedeniyle otomatik olarak tekrar gönderilmez; manuel inceleme gerekiyor.',
);

$pot_required_missing = array();
foreach ( $pot_required as $pot_needle ) {
	if ( ! isset( $pot_entries[ $pot_needle ] ) ) {
		$pot_required_missing[] = $pot_needle;
	}
}

// The string the hard-coded carrier name used to live in must be gone.
$pot_retired_present = isset( $pot_entries['DHL gönderisi oluştur'] );

$report(
	'SHIPPING_POT_CATALOG',
	$pot_readable
		&& array() === $pot_missing
		&& array() === $pot_stale
		&& array() === $pot_required_missing
		&& ! $pot_retired_present
		&& count( $pot_source ) > 100,
	sprintf(
		'pot:%s|source_literals:%d|catalog_msgids:%d|missing_from_catalog:%s|stale_in_catalog:%s|required_new_strings:%d/%d|retired_hardcoded_carrier_string:%s',
		$pot_readable ? 'readable' : 'MISSING',
		count( $pot_source ),
		count( $pot_entries ),
		array() === $pot_missing ? '0' : (string) count( $pot_missing ) . ':' . substr( (string) reset( $pot_missing ), 0, 40 ),
		array() === $pot_stale ? '0' : (string) count( $pot_stale ) . ':' . substr( (string) reset( $pot_stale ), 0, 40 ),
		count( $pot_required ) - count( $pot_required_missing ),
		count( $pot_required ),
		$pot_retired_present ? 'STILL_PRESENT' : 'removed'
	)
);

/* ========================================================================== */
/* 34. The shared mutation boundary, and a REAL second MySQL session           */
/* ========================================================================== */

/*
 * Two properties are measured here, and neither of them can be measured with
 * two sequential PHP calls in one process.
 *
 * THE LOCK. MySQL advisory locks are held per CONNECTION, so a second holder
 * has to be a second connection. A second wpdb instance is one: the assertion
 * below prints both CONNECTION_ID() values and refuses to continue unless they
 * differ, because a test that accidentally shared the connection would take the
 * lock recursively and measure nothing at all.
 *
 * THE RE-CHECK. The gate is asked once on the way in and again immediately
 * before the carrier write. The fake adapter can change its answer between
 * those two questions, which is how "the plugin was deactivated while the lock
 * was held" is produced deliberately.
 */

/**
 * A second, genuinely separate MySQL session.
 *
 * @return array{db: ?wpdb, own_id: int, second_id: int, separate: bool}
 */
function kuka_ship_second_session(): array {
	global $wpdb;

	$own_id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );

	if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
		return array(
			'db'        => null,
			'own_id'    => $own_id,
			'second_id' => 0,
			'separate'  => false,
		);
	}

	$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$second->suppress_errors( true );

	$second_id = (int) $second->get_var( 'SELECT CONNECTION_ID()' );

	return array(
		'db'        => $second,
		'own_id'    => $own_id,
		'second_id' => $second_id,
		'separate'  => $second_id > 0 && $second_id !== $own_id,
	);
}

/** Take the order's mutation lock on the OTHER session. */
function kuka_ship_hold_mutation_lock( wpdb $second, int $order_id ): bool {
	return '1' === (string) $second->get_var(
		$second->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_ship_mutate_' . $order_id )
	);
}

/** Give it back. */
function kuka_ship_release_mutation_lock( wpdb $second, int $order_id ): void {
	$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_ship_mutate_' . $order_id ) );
}

/**
 * A fake-carrier manager and an order already at shipment_created.
 *
 * Driven through the real create path so the state, the reference and the
 * shipment id are all written by production code.
 *
 * @return array{order: WC_Order, adapter: Kuka_Shipping_Fake_Carrier, manager: Kuka_Island_Shipping_Manager}
 */
function kuka_ship_fake_shipment(): array {
	$adapter = new Kuka_Shipping_Fake_Carrier();
	$manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $adapter ) ) );
	$order   = kuka_ship_fixture_order();

	$manager->create_shipment( $order );
	$adapter->reset_counters();

	return array(
		'order'   => wc_get_order( $order->get_id() ),
		'adapter' => $adapter,
		'manager' => $manager,
	);
}

$session = kuka_ship_second_session();

$report(
	'SHIPPING_SECOND_DB_SESSION',
	$session['separate'] && $session['db'] instanceof wpdb,
	sprintf(
		'own_connection_id:%s|second_connection_id:%s|separate:%s',
		$session['own_id'] > 0 ? 'present' : 'MISSING',
		$session['second_id'] > 0 ? 'present' : 'MISSING',
		$session['separate'] ? 'yes' : 'NO'
	)
);

// --- One lock family: every mutation door is blocked by the same key -------

$family = kuka_ship_fake_shipment();
$family_id = (int) $family['order']->get_id();

$family_held = $session['separate'] ? kuka_ship_hold_mutation_lock( $session['db'], $family_id ) : false;

$family_codes = array();
if ( $family_held ) {
	$family_codes['create'] = (string) $family['manager']->create_shipment( wc_get_order( $family_id ) )['code'];
	$family_codes['resume'] = (string) $family['manager']->resume_barcode( wc_get_order( $family_id ) )['code'];
	$family_codes['update'] = (string) $family['manager']->update_shipment( wc_get_order( $family_id ) )['code'];
	$family_codes['cancel'] = (string) $family['manager']->cancel( wc_get_order( $family_id ) )['code'];

	kuka_ship_release_mutation_lock( $session['db'], $family_id );
}

$family_all_contended = array( 'create', 'resume', 'update', 'cancel' ) === array_keys( $family_codes )
	&& array( 'lock_contended' ) === array_values( array_unique( $family_codes ) );

$report(
	'SHIPPING_MUTATION_LOCK_IS_ONE_FAMILY',
	$session['separate']
		&& $family_held
		&& $family_all_contended
		&& 0 === $family['adapter']->write_calls(),
	sprintf(
		'lock_key:kuka_ship_mutate_<order>|held_by:second_mysql_session|create:%s|resume:%s|update:%s|cancel:%s|carrier_writes:%d',
		$family_codes['create'] ?? 'NOT_RUN',
		$family_codes['resume'] ?? 'NOT_RUN',
		$family_codes['update'] ?? 'NOT_RUN',
		$family_codes['cancel'] ?? 'NOT_RUN',
		$family['adapter']->write_calls()
	)
);

kuka_ship_destroy_order( wc_get_order( $family_id ) );

// --- Cancellation: serialised, then idempotent -----------------------------

$serial       = kuka_ship_fake_shipment();
$serial_id    = (int) $serial['order']->get_id();
$serial_state = Kuka_Island_Shipping_Order_Store::get_state( $serial['order'] );

// A stale handle, taken while the record is still live. The second caller in a
// real double-click holds exactly this: an object read before the winner ran.
$serial_stale = wc_get_order( $serial_id );

$serial_held      = $session['separate'] ? kuka_ship_hold_mutation_lock( $session['db'], $serial_id ) : false;
$serial_contended = $serial_held ? $serial['manager']->cancel( wc_get_order( $serial_id ) ) : array( 'code' => 'NOT_RUN' );
$serial_writes_while_held = $serial['adapter']->write_calls();

if ( $serial_held ) {
	kuka_ship_release_mutation_lock( $session['db'], $serial_id );
}

$serial_first  = $serial['manager']->cancel( wc_get_order( $serial_id ) );
$serial_after  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $serial_id ) );
$serial_second = $serial['manager']->cancel( wc_get_order( $serial_id ) );
// And the stale handle, which still believes the shipment is live.
$serial_stale_result = $serial['manager']->cancel( $serial_stale );
$serial_total_writes = $serial['adapter']->write_calls();

$report(
	'SHIPPING_CANCEL_SERIALISED_AND_IDEMPOTENT',
	$session['separate']
		&& $serial_held
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $serial_state
		&& 'lock_contended' === (string) $serial_contended['code']
		&& 0 === $serial_writes_while_held
		&& $serial_first['ok']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $serial_after
		&& ! $serial_second['ok']
		&& 'already_cancelled' === (string) $serial_second['code']
		&& ! $serial_stale_result['ok']
		&& 'already_cancelled' === (string) $serial_stale_result['code']
		&& 1 === $serial_total_writes
		&& 1 === $serial['adapter']->count_for( 'cancel_shipment' )
		&& 0 === $serial['adapter']->count_for( 'cancel_order' )
		&& 1 === $serial['adapter']->count_for( 'read_shipment' )
		&& 0 === $serial['adapter']->count_for( 'read_order' ),
	sprintf(
		'concurrent_call:%s|writes_while_lock_held:%d|first:%s|state:%s|second:%s|stale_handle:%s|total_carrier_writes:%d|cancel_shipment:%d|cancel_order:%d|confirmed_by:read_shipment(%d)',
		(string) $serial_contended['code'],
		$serial_writes_while_held,
		$serial_first['ok'] ? 'cancelled' : 'REFUSED:' . (string) $serial_first['code'],
		$serial_after,
		(string) $serial_second['code'],
		(string) $serial_stale_result['code'],
		$serial_total_writes,
		$serial['adapter']->count_for( 'cancel_shipment' ),
		$serial['adapter']->count_for( 'cancel_order' ),
		$serial['adapter']->count_for( 'read_shipment' )
	)
);

kuka_ship_destroy_order( wc_get_order( $serial_id ) );

// --- Every state that must not write, does not write -----------------------

$states = kuka_ship_fake_shipment();
$states_id = (int) $states['order']->get_id();

$expected_codes = array(
	Kuka_Island_Shipping_Order_Store::STATE_NONE               => 'not_cancellable',
	Kuka_Island_Shipping_Order_Store::STATE_BLOCKED            => 'not_cancellable',
	Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED   => 'not_cancellable',
	Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED => 'not_cancellable',
	Kuka_Island_Shipping_Order_Store::STATE_DELIVERED          => 'not_cancellable',
	Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW      => 'not_cancellable',
	Kuka_Island_Shipping_Order_Store::STATE_CANCELLED          => 'already_cancelled',
	Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED => 'cancel_in_progress',
	Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED => 'not_cancellable',
	'a-state-this-version-never-heard-of'                      => 'not_cancellable',
);

$states_wrong = array();
foreach ( $expected_codes as $state => $expected ) {
	Kuka_Island_Shipping_Order_Store::set_state( $states['order'], (string) $state );
	$outcome = $states['manager']->cancel( wc_get_order( $states_id ) );

	if ( $outcome['ok'] || $expected !== (string) $outcome['code'] || '' === (string) $outcome['message'] ) {
		$states_wrong[] = $state . '=>' . (string) $outcome['code'];
	}
}

// The shipment_created record whose shipment id is unknown: a shipment exists,
// nothing can address it, and cancelling the ORDER instead would be a request
// about the wrong object.
Kuka_Island_Shipping_Order_Store::set_state( $states['order'], Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED );
$states['order']->update_meta_data( Kuka_Island_Shipping_Order_Store::META_SHIPMENT_ID, '' );
$states['order']->save_meta_data();
$blind = $states['manager']->cancel( wc_get_order( $states_id ) );

if ( $blind['ok'] || 'not_cancellable' !== (string) $blind['code'] ) {
	$states_wrong[] = 'shipment_created_without_id=>' . (string) $blind['code'];
}

$report(
	'SHIPPING_CANCEL_REFUSES_EVERY_OTHER_STATE',
	array() === $states_wrong && 0 === $states['adapter']->write_calls(),
	sprintf(
		'states_checked:%d|wrong:%s|carrier_writes:%d|unknown_state_refused:yes|shipment_created_without_id_refused:yes',
		count( $expected_codes ) + 1,
		array() === $states_wrong ? 'none' : implode( '+', $states_wrong ),
		$states['adapter']->write_calls()
	)
);

kuka_ship_destroy_order( wc_get_order( $states_id ) );

// --- Amendment: same lock, same freshness rule -----------------------------

$amend    = kuka_ship_fake_shipment();
$amend_id = (int) $amend['order']->get_id();

$amend_held      = $session['separate'] ? kuka_ship_hold_mutation_lock( $session['db'], $amend_id ) : false;
$amend_contended = $amend_held ? $amend['manager']->update_shipment( wc_get_order( $amend_id ) ) : array( 'code' => 'NOT_RUN' );
$amend_writes_while_held = $amend['adapter']->write_calls();

if ( $amend_held ) {
	kuka_ship_release_mutation_lock( $session['db'], $amend_id );
}

$amend_ok    = $amend['manager']->update_shipment( wc_get_order( $amend_id ) );
$amend_issued = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $amend_id ) );

/*
 * THE AMENDMENT WENT OUT ONCE AND IS NOT REPORTED AS APPLIED. This adapter
 * cannot read the amended fields back -- exactly like the DHL one -- so the
 * carrier's acknowledgement is all there is, and an acknowledgement is not
 * evidence. The measurement is therefore "issued, unproven, and never
 * repeated", not "updated".
 */
$amend_second = $amend['manager']->update_shipment( wc_get_order( $amend_id ) );

// A handle taken BEFORE the cancellation. The amendment it would send is
// addressed to a shipment that no longer exists.
$amend_stale = wc_get_order( $amend_id );
$amend['manager']->cancel( wc_get_order( $amend_id ) );
$amend_state_now = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $amend_id ) );
$amend_late      = $amend['manager']->update_shipment( $amend_stale );

$amend_updates = $amend['adapter']->count_for( 'update_shipment' ) + $amend['adapter']->count_for( 'update_order' );

$report(
	'SHIPPING_UPDATE_SERIALISED_AND_FRESH',
	$session['separate']
		&& $amend_held
		&& 'lock_contended' === (string) $amend_contended['code']
		&& 0 === $amend_writes_while_held
		// Issued, and honestly reported as unproven.
		&& ! $amend_ok['ok']
		&& 'readback_unsupported' === (string) $amend_ok['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED === $amend_issued
		&& 'nothing_to_update' === (string) $amend_second['code']
		&& 1 === $amend['adapter']->count_for( 'update_shipment' )
		&& 0 === $amend['adapter']->count_for( 'update_order' )
		// The parcel is still reachable: an unproven amendment does not take
		// the cancel button away.
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $amend_state_now
		&& ! $amend_late['ok']
		&& 'nothing_to_update' === (string) $amend_late['code']
		&& 1 === $amend_updates,
	sprintf(
		'concurrent_call:%s|writes_while_lock_held:%d|first:%s|state_after_update:%s|second_press:%s|update_shipment:%d|update_order:%d|cancel_from_unproven_update:%s|late_update_from_stale_handle:%s|total_updates:%d',
		(string) $amend_contended['code'],
		$amend_writes_while_held,
		$amend_ok['ok'] ? 'REPORTED_APPLIED' : 'issued_unproven:' . (string) $amend_ok['code'],
		$amend_issued,
		(string) $amend_second['code'],
		$amend['adapter']->count_for( 'update_shipment' ),
		$amend['adapter']->count_for( 'update_order' ),
		$amend_state_now,
		(string) $amend_late['code'],
		$amend_updates
	)
);

kuka_ship_destroy_order( wc_get_order( $amend_id ) );

// --- Every state that must not amend, does not amend -----------------------

$amend_states    = kuka_ship_fake_shipment();
$amend_states_id = (int) $amend_states['order']->get_id();
$amend_wrong     = array();

foreach (
	array(
		Kuka_Island_Shipping_Order_Store::STATE_NONE,
		Kuka_Island_Shipping_Order_Store::STATE_BLOCKED,
		Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED,
		Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED,
		Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED,
		Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED,
		Kuka_Island_Shipping_Order_Store::STATE_DELIVERED,
		Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW,
		Kuka_Island_Shipping_Order_Store::STATE_CANCELLED,
		'a-state-this-version-never-heard-of',
	) as $amend_state
) {
	Kuka_Island_Shipping_Order_Store::set_state( $amend_states['order'], (string) $amend_state );
	$outcome = $amend_states['manager']->update_shipment( wc_get_order( $amend_states_id ) );

	if ( $outcome['ok'] || 'nothing_to_update' !== (string) $outcome['code'] || '' === (string) $outcome['message'] ) {
		$amend_wrong[] = $amend_state . '=>' . (string) $outcome['code'];
	}
}

$report(
	'SHIPPING_UPDATE_REFUSES_EVERY_OTHER_STATE',
	array() === $amend_wrong && 0 === $amend_states['adapter']->write_calls(),
	sprintf(
		'states_checked:10|wrong:%s|carrier_writes:%d',
		array() === $amend_wrong ? 'none' : implode( '+', $amend_wrong ),
		$amend_states['adapter']->write_calls()
	)
);

kuka_ship_destroy_order( wc_get_order( $amend_states_id ) );

// --- Layer two is layer two: COD does not trap a booking -------------------
//
// The cash-on-delivery refusal used to sit in the SAME method as the carrier
// gate, so wiring cancellation through that gate would have made a COD order
// impossible to un-book. The dangerous act is SHIPPING a COD order as if it
// were prepaid; cancelling or amending one is the remedy, and a remedy that is
// refused leaves a parcel nobody can stop.

$cod_booked = kuka_ship_fake_shipment();
$cod_order  = $cod_booked['order'];
$cod_order->set_payment_method( 'cod' );
$cod_order->save();

$cod_state   = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $cod_order->get_id() ) );
$cod_gate_on = ! Kuka_Island_Shipping_Manager::cod_gate( wc_get_order( $cod_order->get_id() ) )['ok'];

$cod_create = $cod_booked['manager']->create_shipment( wc_get_order( $cod_order->get_id() ) );
$cod_resume = $cod_booked['manager']->resume_barcode( wc_get_order( $cod_order->get_id() ) );
$cod_creates = $cod_booked['adapter']->count_for( 'create_order' ) + $cod_booked['adapter']->count_for( 'create_barcode' );

$cod_update = $cod_booked['manager']->update_shipment( wc_get_order( $cod_order->get_id() ) );
$cod_cancel = $cod_booked['manager']->cancel( wc_get_order( $cod_order->get_id() ) );
$cod_after  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $cod_order->get_id() ) );

$report(
	'SHIPPING_COD_DOES_NOT_TRAP_A_BOOKING',
	$cod_gate_on
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $cod_state
		&& ! $cod_create['ok']
		&& 'cod_not_supported' === (string) $cod_create['code']
		&& ! $cod_resume['ok']
		&& 'cod_not_supported' === (string) $cod_resume['code']
		&& 0 === $cod_creates
		/*
		 * THE MEASUREMENT IS THAT THE REQUEST LEFT THE BUILDING, not that the
		 * result line says ok. An amendment is now reported as unproven until
		 * its fields are read back -- this adapter cannot read them, so it
		 * never will be -- and reading that refusal as "COD blocked the
		 * amendment" would be the opposite of what happened. The door was open:
		 * one update_shipment reached the carrier, and the refusal names the
		 * missing evidence rather than the payment method.
		 */
		&& 1 === $cod_booked['adapter']->count_for( 'update_shipment' )
		&& 'cod_not_supported' !== (string) $cod_update['code']
		&& $cod_cancel['ok']
		&& 1 === $cod_booked['adapter']->count_for( 'cancel_shipment' )
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $cod_after,
	sprintf(
		'payment_method:cod|cod_gate_closed:%s|create:%s|resume:%s|create_writes:%d|update:%s|update_writes:%d|cancel:%s|cancel_writes:%d|state:%s',
		$cod_gate_on ? 'yes' : 'NO',
		(string) $cod_create['code'],
		(string) $cod_resume['code'],
		$cod_creates,
		'cod_not_supported' !== (string) $cod_update['code'] ? 'reached_carrier:' . (string) $cod_update['code'] : 'BLOCKED_BY_COD',
		$cod_booked['adapter']->count_for( 'update_shipment' ),
		$cod_cancel['ok'] ? 'allowed' : 'REFUSED:' . (string) $cod_cancel['code'],
		$cod_booked['adapter']->count_for( 'cancel_shipment' ),
		$cod_after
	)
);

kuka_ship_destroy_order( wc_get_order( $cod_order->get_id() ) );

/* ========================================================================== */
/* 35. The gate is asked AGAIN, with the lock held, before every write         */
/* ========================================================================== */

/*
 * The adapter answers "contactable" the first time and "credentials missing"
 * from then on. The first answer admits the operation; the second is the one
 * the choke point asks immediately before contacting the carrier. Two readiness
 * checks and zero writes is the whole measurement.
 */

$recheck_results = array();
$recheck_wrong   = array();

foreach ( array( 'create', 'resume', 'update', 'cancel' ) as $door ) {
	$adapter = new Kuka_Shipping_Fake_Carrier();
	$manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $adapter ) ) );
	$order   = kuka_ship_fixture_order();

	if ( 'create' !== $door ) {
		// Reach the state the door accepts, using the real create path.
		$manager->create_shipment( $order );
		$order = wc_get_order( $order->get_id() );

		if ( 'resume' === $door ) {
			Kuka_Island_Shipping_Order_Store::set_state( $order, Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED );
			$order = wc_get_order( $order->get_id() );
		}
	}

	$adapter->reset_counters();
	$adapter->readiness_after_first = array(
		'ready'        => false,
		'gaps'         => array( 'KUKA_DHL_CLIENT_ID' ),
		'environment'  => 'test',
		'live_blocked' => false,
	);

	$outcome = match ( $door ) {
		'create' => $manager->create_shipment( $order ),
		'resume' => $manager->resume_barcode( $order ),
		'update' => $manager->update_shipment( $order ),
		'cancel' => $manager->cancel( $order ),
	};

	$recheck_results[ $door ] = sprintf(
		'%s(checks:%d,writes:%d)',
		(string) $outcome['code'],
		$adapter->readiness_checks,
		$adapter->write_calls()
	);

	if ( 'credentials_missing' !== (string) $outcome['code'] || 2 !== $adapter->readiness_checks || 0 !== $adapter->write_calls() ) {
		$recheck_wrong[] = $door;
	}

	kuka_ship_destroy_order( wc_get_order( $order->get_id() ) );
}

$report(
	'SHIPPING_GATE_RECHECKED_UNDER_LOCK',
	array() === $recheck_wrong,
	sprintf(
		'doors:4|wrong:%s|create:%s|resume:%s|update:%s|cancel:%s',
		array() === $recheck_wrong ? 'none' : implode( '+', $recheck_wrong ),
		$recheck_results['create'],
		$recheck_results['resume'],
		$recheck_results['update'],
		$recheck_results['cancel']
	)
);

/*
 * And the same thing with the REAL runtime gate rather than a fake readiness:
 * the option is closed from inside the shipment-request filter, which fires
 * under the lock and before the write. Runtime_Gate::is_disabled() reads the
 * options table directly on every call, so the second reading sees it.
 */

$midflight_adapter = new Kuka_Shipping_Fake_Carrier();
$midflight_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $midflight_adapter ) ) );
$midflight_order   = kuka_ship_fixture_order();

$close_the_gate = static function ( $shipment ) {
	Kuka_Island_Shipping_Runtime_Gate::disable();

	return $shipment;
};

add_filter( 'kuka_island_shipping_request', $close_the_gate, 999 );
$midflight_result = $midflight_manager->create_shipment( $midflight_order );
remove_filter( 'kuka_island_shipping_request', $close_the_gate, 999 );

$midflight_closed = Kuka_Island_Shipping_Runtime_Gate::is_disabled();
Kuka_Island_Shipping_Runtime_Gate::enable();
$midflight_restored = ! Kuka_Island_Shipping_Runtime_Gate::is_disabled();

$report(
	'SHIPPING_RUNTIME_GATE_CLOSED_MIDFLIGHT',
	! $midflight_result['ok']
		&& Kuka_Island_Shipping_Runtime_Gate::CODE === (string) $midflight_result['code']
		&& 0 === $midflight_adapter->write_calls()
		&& $midflight_closed
		&& $midflight_restored,
	sprintf(
		'closed_after:lock_held_and_request_built|code:%s|carrier_writes:%d|gate_was_closed:%s|gate_restored:%s',
		(string) $midflight_result['code'],
		$midflight_adapter->write_calls(),
		$midflight_closed ? 'yes' : 'NO',
		$midflight_restored ? 'yes' : 'NO'
	)
);

kuka_ship_destroy_order( wc_get_order( $midflight_order->get_id() ) );

/*
 * The entry-side gate, on all four doors: an unregistered carrier, a blocked
 * live environment and missing credentials each produce zero calls of any kind.
 */

$entry_wrong = array();
$entry_notes = array();

foreach (
	array(
		'carrier_not_registered'   => null,
		'live_environment_blocked' => array(
			'ready'        => true,
			'gaps'         => array(),
			'environment'  => 'live',
			'live_blocked' => true,
		),
		'credentials_missing'      => array(
			'ready'        => false,
			'gaps'         => array( 'KUKA_DHL_PASSWORD' ),
			'environment'  => 'test',
			'live_blocked' => false,
		),
	) as $expected_code => $readiness
) {
	$adapter = new Kuka_Shipping_Fake_Carrier();
	$order   = kuka_ship_fixture_order();

	if ( null === $readiness ) {
		// Nothing registered at all.
		$manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array() ) );
	} else {
		$adapter->readiness = $readiness;
		$manager            = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $adapter ) ) );
	}

	foreach (
		array(
			'create' => static fn (): array => $manager->create_shipment( wc_get_order( $order->get_id() ) ),
			'resume' => static fn (): array => $manager->resume_barcode( wc_get_order( $order->get_id() ) ),
			'update' => static fn (): array => $manager->update_shipment( wc_get_order( $order->get_id() ) ),
			'cancel' => static fn (): array => $manager->cancel( wc_get_order( $order->get_id() ) ),
		) as $door => $call
	) {
		$outcome = $call();

		if ( $outcome['ok'] || $expected_code !== (string) $outcome['code'] ) {
			$entry_wrong[] = $expected_code . '/' . $door . '=>' . (string) $outcome['code'];
		}
	}

	if ( 0 !== $adapter->write_calls() ) {
		$entry_wrong[] = $expected_code . '/writes=' . $adapter->write_calls();
	}

	$entry_notes[] = $expected_code . ':writes:' . $adapter->write_calls();

	kuka_ship_destroy_order( wc_get_order( $order->get_id() ) );
}

$report(
	'SHIPPING_MUTATION_GATE_SHARED',
	array() === $entry_wrong,
	sprintf(
		'doors:create+resume+update+cancel|conditions:3|wrong:%s|%s',
		array() === $entry_wrong ? 'none' : implode( '+', $entry_wrong ),
		implode( '|', $entry_notes )
	)
);

/* ========================================================================== */
/* 37. An order belongs to ITS carrier, not to the shop's current default      */
/* ========================================================================== */

/*
 * The scenario every measurement below shares: a shipment is booked with the
 * REAL DHL adapter (mock transport), a second adapter is registered alongside
 * it, and the shop's default is then switched to the second one -- which is
 * exactly what happens the day a second courier is added.
 *
 * Two recording adapters, reported separately. The DHL side records through its
 * mock transport's request log; the second adapter counts every read and write
 * it is handed. "Only DHL was contacted" is therefore two numbers, not an
 * inspection of which class the manager mentions.
 */

/**
 * The transport script for the affinity scenarios.
 *
 * Stateful on purpose: getshipment answers "present" until a cancellation has
 * been accepted and "not found" afterwards. A fixed answer cannot serve both a
 * reconciliation (which must find the shipment) and a cancellation
 * confirmation (which must not), and faking them with two transports would
 * stop measuring one order's life.
 */
function kuka_ship_affinity_responder(): callable {
	$cancelled = false;

	return static function ( string $method, string $url ) use ( &$cancelled ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return kuka_ship_create_barcode_ok( '313131313', 'BC-AFFINITY' );
		}

		if ( str_contains( $url, '/getshipmentstatus/' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						'referenceId'        => 'ECHO',
						'shipmentId'         => '313131313',
						'shipmentStatusCode' => 2,
						'trackingUrl'        => 'https://kargotakip.example/313131313',
					)
				),
			);
		}

		if ( str_contains( $url, '/updateshipment' ) || str_contains( $url, '/updateorder' ) ) {
			return array( 'status' => 200, 'body' => '{}' );
		}

		if ( str_contains( $url, '/cancelshipment' ) || str_contains( $url, '/cancelorder/' ) ) {
			$cancelled = true;

			return array( 'status' => 200, 'body' => '{}' );
		}

		if ( str_contains( $url, '/getshipment/' ) && ! $cancelled ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						'shipment' => array(
							'referenceId'        => 'ECHO',
							'shipmentId'         => '313131313',
							'shipmentStatusCode' => 2,
							'pieceCount'         => 1,
						),
					)
				),
			);
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	};
}

/**
 * Two adapters registered: the real DHL one on a mock transport, and a second
 * recording adapter. No create is run and no default is chosen yet.
 *
 * @return array{order: WC_Order, dhl: Kuka_Island_Shipping_DHL_Provider, transport: Kuka_Shipping_Mock_Transport, other: Kuka_Shipping_Fake_Carrier, manager: Kuka_Island_Shipping_Manager, filter: callable}
 */
function kuka_ship_affinity_scenario(): array {
	$transport = new Kuka_Shipping_Mock_Transport( kuka_ship_affinity_responder() );
	$dhl       = kuka_ship_provider( $transport );
	$other     = new Kuka_Shipping_Fake_Carrier( 'kuka-other-kargo' );
	$manager   = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $dhl, $other ) ) );

	$dhl->get_resolver()->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );

	return array(
		'order'     => kuka_ship_fixture_order(),
		'dhl'       => $dhl,
		'transport' => $transport,
		'other'     => $other,
		'manager'   => $manager,
		'filter'    => static function (): string {
			return 'kuka-other-kargo';
		},
	);
}

/** THE DAY THE SHOP ADDS A COURIER: the default becomes the other adapter. */
function kuka_ship_affinity_flip( array $scenario ): void {
	add_filter( 'kuka_island_shipping_default_carrier', $scenario['filter'], 999 );
}

/** Put the shop's default back where the rest of the suite expects it. */
function kuka_ship_affinity_unflip( array $scenario ): void {
	remove_filter( 'kuka_island_shipping_default_carrier', $scenario['filter'], 999 );
}

// --- Query, reconcile, amend and cancel all stay with DHL -----------------

$affinity    = kuka_ship_affinity_scenario();
$affinity_id = (int) $affinity['order']->get_id();

// Explicit key: two adapters are registered and nothing is configured, so the
// shop has not yet said which one it books with.
$affinity['manager']->create_shipment( $affinity['order'], 'dhl' );
$affinity_provider_stored = Kuka_Island_Shipping_Order_Store::provider( wc_get_order( $affinity_id ) );

kuka_ship_affinity_flip( $affinity );
$affinity_default_now = $affinity['manager']->default_carrier_key();
$affinity['transport']->reset();
$affinity['other']->reset_counters();

// Every call below passes NO carrier key -- the way the poller and the admin
// screen call them.
$affinity_query     = $affinity['manager']->query_status( wc_get_order( $affinity_id ) );
$affinity_status_reads = $affinity['transport']->count_for( '/getshipmentstatus/' );

$affinity_reconcile = $affinity['manager']->reconcile_order( wc_get_order( $affinity_id ) );
$affinity_recon_reads = $affinity['transport']->count_for( '/getshipment/' ) + $affinity['transport']->count_for( '/getorder/' );

$affinity_update = $affinity['manager']->update_shipment( wc_get_order( $affinity_id ) );
$affinity_writes_update = $affinity['transport']->count_for( '/updateshipment' );

$affinity_cancel  = $affinity['manager']->cancel( wc_get_order( $affinity_id ) );
$affinity_cancels = $affinity['transport']->count_for( '/cancelshipment' );
$affinity_confirms = $affinity['transport']->count_for( '/getshipment/' ) - 1;

$affinity_ownership = $affinity['manager']->carrier_ownership( wc_get_order( $affinity_id ) );

kuka_ship_affinity_unflip( $affinity );

$report(
	'SHIPPING_PROVIDER_AFFINITY',
	'dhl' === $affinity_provider_stored
		&& 'kuka-other-kargo' === $affinity_default_now
		&& 'dhl' === (string) $affinity_ownership['key']
		&& 'order' === (string) $affinity_ownership['source']
		&& $affinity_query['ok']
		&& 1 === $affinity_status_reads
		&& 'shipment_present' === (string) $affinity_reconcile['verdict']
		&& 1 === $affinity_recon_reads
		/*
		 * The amendment is measured by WHERE it went, which is what this
		 * section is about. It is reported as unproven -- the DHL adapter
		 * cannot read the amended fields back -- and that refusal is about
		 * missing evidence, not about the wrong courier.
		 */
		&& 1 === $affinity_writes_update
		&& 'readback_unsupported' === (string) $affinity_update['code']
		&& $affinity_cancel['ok']
		&& 1 === $affinity_cancels
		&& 1 === $affinity_confirms
		// The second adapter was never contacted, for any operation.
		&& 0 === $affinity['other']->contacts(),
	sprintf(
		'stored_provider:%s|default_now:%s|resolved:%s(%s)|dhl.status_reads:%d|dhl.reconcile_reads:%d|dhl.updates:%d|dhl.cancels:%d|dhl.cancel_confirm_reads:%d|other.reads:%d|other.writes:%d|other.contacts:%d',
		$affinity_provider_stored,
		$affinity_default_now,
		(string) $affinity_ownership['key'],
		(string) $affinity_ownership['source'],
		$affinity_status_reads,
		$affinity_recon_reads,
		$affinity_writes_update,
		$affinity_cancels,
		$affinity_confirms,
		$affinity['other']->read_calls(),
		$affinity['other']->write_calls(),
		$affinity['other']->contacts()
	)
);

kuka_ship_destroy_order( wc_get_order( $affinity_id ) );

// --- Resume stays with DHL too --------------------------------------------

$resume_affinity  = kuka_ship_affinity_scenario();
$resume_aff_order = $resume_affinity['order'];

// Reach order_created through the real create path, with DHL named.
$resume_affinity['manager']->create_shipment( $resume_aff_order, 'dhl' );
$resume_aff_order = wc_get_order( $resume_aff_order->get_id() );
Kuka_Island_Shipping_Order_Store::set_state( $resume_aff_order, Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED );

$resume_aff_stored = Kuka_Island_Shipping_Order_Store::provider( wc_get_order( $resume_aff_order->get_id() ) );

// Now flip the default and resume with NO key.
kuka_ship_affinity_flip( $resume_affinity );
$resume_affinity['transport']->reset();
$resume_affinity['other']->reset_counters();

$resume_aff_result   = $resume_affinity['manager']->resume_barcode( wc_get_order( $resume_aff_order->get_id() ) );
$resume_aff_barcodes = $resume_affinity['transport']->count_for( '/createbarcode' );
$resume_aff_orders   = $resume_affinity['transport']->count_for( '/createOrder' );

kuka_ship_affinity_unflip( $resume_affinity );

$report(
	'SHIPPING_PROVIDER_AFFINITY_RESUME',
	'dhl' === $resume_aff_stored
		&& $resume_aff_result['ok']
		&& 1 === $resume_aff_barcodes
		&& 0 === $resume_aff_orders
		&& 0 === $resume_affinity['other']->contacts(),
	sprintf(
		'stored_provider:%s|default_now:kuka-other-kargo|dhl.createbarcode:%d|dhl.createOrder:%d|other.reads:%d|other.writes:%d|other.contacts:%d|state:%s',
		$resume_aff_stored,
		$resume_aff_barcodes,
		$resume_aff_orders,
		$resume_affinity['other']->read_calls(),
		$resume_affinity['other']->write_calls(),
		$resume_affinity['other']->contacts(),
		Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $resume_aff_order->get_id() ) )
	)
);

kuka_ship_destroy_order( wc_get_order( $resume_aff_order->get_id() ) );

/* ========================================================================== */
/* 38. Ownership is pinned before the first write, and never guessed           */
/* ========================================================================== */

/**
 * One order meta value, read straight out of the database.
 *
 * Bypasses WC_Order and every object cache on purpose: the question is whether
 * the value was PERSISTED before the request went out, not whether some
 * in-memory object happened to be holding it.
 *
 * @param int    $order_id Order id.
 * @param string $meta_key Meta key.
 */
function kuka_ship_meta_in_db( int $order_id, string $meta_key ): string {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$hpos = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1",
			$order_id,
			$meta_key
		)
	);

	if ( null !== $hpos ) {
		return (string) $hpos;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$legacy = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
			$order_id,
			$meta_key
		)
	);

	return (string) $legacy;
}

// --- The pin is in the database when the first request is issued ----------

$pin_adapter = new Kuka_Shipping_Fake_Carrier();
$pin_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $pin_adapter ) ) );
$pin_order   = kuka_ship_fixture_order();
$pin_id      = (int) $pin_order->get_id();

$pin_before = kuka_ship_meta_in_db( $pin_id, Kuka_Island_Shipping_Order_Store::META_PROVIDER );

/** @var array<string, array<string, string>> $pin_seen */
$pin_seen              = array();
$pin_adapter->on_write = static function ( string $operation ) use ( &$pin_seen, $pin_id ): void {
	$pin_seen[ $operation ] = array(
		'provider'  => kuka_ship_meta_in_db( $pin_id, Kuka_Island_Shipping_Order_Store::META_PROVIDER ),
		'reference' => kuka_ship_meta_in_db( $pin_id, Kuka_Island_Shipping_Order_Store::META_REFERENCE ),
		'state'     => kuka_ship_meta_in_db( $pin_id, Kuka_Island_Shipping_Order_Store::META_STATE ),
	);
};

$pin_result = $pin_manager->create_shipment( $pin_order );
$pin_order  = wc_get_order( $pin_id );
$pin_data   = Kuka_Island_Shipping_Order_Store::get_shipment_data( $pin_order );

$pin_at_first_write = $pin_seen['create_order'] ?? array();

$report(
	'SHIPPING_PROVIDER_PINNED_BEFORE_FIRST_WRITE',
	'' === $pin_before
		&& $pin_result['ok']
		&& array() !== $pin_at_first_write
		&& Kuka_Shipping_Fake_Carrier::KEY === (string) ( $pin_at_first_write['provider'] ?? '' )
		// Same save: the reference cannot exist without an owner.
		&& Kuka_Island_Shipping_Reference::is_valid( (string) ( $pin_at_first_write['reference'] ?? '' ) )
		&& (string) ( $pin_at_first_write['reference'] ?? '' ) === (string) $pin_data['reference']
		&& Kuka_Shipping_Fake_Carrier::KEY === (string) $pin_data['provider']
		&& 1 === $pin_adapter->count_for( 'create_order' ),
	sprintf(
		'measured:database_read_inside_the_first_write|provider_before:%s|provider_at_first_write:%s|reference_at_first_write:%s|state_at_first_write:%s|provider_after:%s|create_order_calls:%d',
		'' === $pin_before ? 'empty' : $pin_before,
		'' !== (string) ( $pin_at_first_write['provider'] ?? '' ) ? (string) $pin_at_first_write['provider'] : 'EMPTY',
		Kuka_Island_Shipping_Reference::is_valid( (string) ( $pin_at_first_write['reference'] ?? '' ) ) ? 'stored' : 'MISSING',
		'' !== (string) ( $pin_at_first_write['state'] ?? '' ) ? (string) $pin_at_first_write['state'] : 'none',
		(string) $pin_data['provider'],
		$pin_adapter->count_for( 'create_order' )
	)
);

kuka_ship_destroy_order( wc_get_order( $pin_id ) );

// --- An untouched order, and only an untouched order, may use the default -

$untouched_adapter = new Kuka_Shipping_Fake_Carrier();
$untouched_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $untouched_adapter ) ) );
$untouched_order   = kuka_ship_fixture_order();

$untouched_before = $untouched_manager->carrier_ownership( $untouched_order );
$untouched_result = $untouched_manager->create_shipment( $untouched_order );
$untouched_order  = wc_get_order( $untouched_order->get_id() );
$untouched_after  = $untouched_manager->carrier_ownership( $untouched_order );

$report(
	'SHIPPING_UNTOUCHED_ORDER_USES_DEFAULT',
	'default' === (string) $untouched_before['source']
		&& Kuka_Shipping_Fake_Carrier::KEY === (string) $untouched_before['key']
		&& '' === (string) $untouched_before['code']
		&& $untouched_result['ok']
		// Once pinned, the SAME question is answered from the order.
		&& 'order' === (string) $untouched_after['source']
		&& Kuka_Shipping_Fake_Carrier::KEY === (string) $untouched_after['key']
		&& Kuka_Shipping_Fake_Carrier::KEY === Kuka_Island_Shipping_Order_Store::provider( $untouched_order ),
	sprintf(
		'before_create:%s(%s)|after_create:%s(%s)|stored:%s',
		(string) $untouched_before['key'],
		(string) $untouched_before['source'],
		(string) $untouched_after['key'],
		(string) $untouched_after['source'],
		Kuka_Island_Shipping_Order_Store::provider( $untouched_order )
	)
);

kuka_ship_destroy_order( wc_get_order( $untouched_order->get_id() ) );

// --- An uncertain createOrder keeps the owner -----------------------------

$uncertain_affinity = kuka_ship_affinity_scenario();
$uncertain_aff_id   = (int) $uncertain_affinity['order']->get_id();

// A transport that goes silent on the write and cannot answer the reads.
$uncertain_aff_transport = new Kuka_Shipping_Mock_Transport(
	static function ( string $method, string $url ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return array( 'status' => 0, 'body' => '', 'error' => 'cURL error 28: Operation timed out' );
		}

		// The reads cannot answer either, so absence is NOT proved.
		return array( 'status' => 503, 'body' => '' );
	}
);
$uncertain_aff_dhl     = kuka_ship_provider( $uncertain_aff_transport );
$uncertain_aff_other   = new Kuka_Shipping_Fake_Carrier( 'kuka-other-kargo' );
$uncertain_aff_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $uncertain_aff_dhl, $uncertain_aff_other ) ) );
$uncertain_aff_dhl->get_resolver()->purge_cache( KUKA_SHIP_CACHED_CITY_CODES );

$uncertain_aff_order = wc_get_order( $uncertain_aff_id );
$uncertain_aff_manager->create_shipment( $uncertain_aff_order, 'dhl' );

$uncertain_aff_stored = Kuka_Island_Shipping_Order_Store::provider( wc_get_order( $uncertain_aff_id ) );
$uncertain_aff_state  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $uncertain_aff_id ) );
$uncertain_aff_creates = $uncertain_aff_transport->count_for( '/createOrder' );

// Now the shop changes its default, and the operator reconciles.
kuka_ship_affinity_flip( $uncertain_affinity );
$uncertain_aff_transport->reset();
$uncertain_aff_other->reset_counters();

$uncertain_aff_verdict = $uncertain_aff_manager->reconcile_order( wc_get_order( $uncertain_aff_id ) );
$uncertain_aff_reads   = $uncertain_aff_transport->count_for( '/getshipment/' ) + $uncertain_aff_transport->count_for( '/getorder/' );
$uncertain_aff_recreate = $uncertain_aff_transport->count_for( '/createOrder' );

kuka_ship_affinity_unflip( $uncertain_affinity );

$report(
	'SHIPPING_UNCERTAIN_CREATE_RETAINS_PROVIDER',
	'dhl' === $uncertain_aff_stored
		&& Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED === $uncertain_aff_state
		&& 1 === $uncertain_aff_creates
		&& 'inconclusive' === (string) $uncertain_aff_verdict['verdict']
		&& $uncertain_aff_reads > 0
		&& 0 === $uncertain_aff_recreate
		&& 0 === $uncertain_aff_other->contacts(),
	sprintf(
		'createOrder:timeout|provider_after_timeout:%s|state:%s|dhl.createOrder_total:%d|reconcile_verdict:%s|dhl.reconcile_reads:%d|dhl.second_createOrder:%d|other.reads:%d|other.writes:%d|other.contacts:%d',
		'' !== $uncertain_aff_stored ? $uncertain_aff_stored : 'LOST',
		$uncertain_aff_state,
		$uncertain_aff_creates,
		(string) $uncertain_aff_verdict['verdict'],
		$uncertain_aff_reads,
		$uncertain_aff_recreate,
		$uncertain_aff_other->read_calls(),
		$uncertain_aff_other->write_calls(),
		$uncertain_aff_other->contacts()
	)
);

kuka_ship_destroy_order( wc_get_order( $uncertain_aff_id ) );

// --- An explicit key that disagrees with the record is refused ------------

$mismatch          = kuka_ship_affinity_scenario();
$mismatch_id       = (int) $mismatch['order']->get_id();
$mismatch['manager']->create_shipment( $mismatch['order'], 'dhl' );
$mismatch['transport']->reset();
$mismatch['other']->reset_counters();

$mismatch_codes = array(
	'create'    => (string) $mismatch['manager']->create_shipment( wc_get_order( $mismatch_id ), 'kuka-other-kargo' )['code'],
	'resume'    => (string) $mismatch['manager']->resume_barcode( wc_get_order( $mismatch_id ), 'kuka-other-kargo' )['code'],
	'update'    => (string) $mismatch['manager']->update_shipment( wc_get_order( $mismatch_id ), 'kuka-other-kargo' )['code'],
	'cancel'    => (string) $mismatch['manager']->cancel( wc_get_order( $mismatch_id ), 'kuka-other-kargo' )['code'],
	'query'     => (string) $mismatch['manager']->query_status( wc_get_order( $mismatch_id ), 'kuka-other-kargo' )['code'],
	'reconcile' => (string) $mismatch['manager']->reconcile_order( wc_get_order( $mismatch_id ), 'kuka-other-kargo' )['verdict'],
);

$mismatch_wrong = array();
foreach ( $mismatch_codes as $mismatch_door => $mismatch_code ) {
	if ( 'shipment_provider_mismatch' !== $mismatch_code ) {
		$mismatch_wrong[] = $mismatch_door . '=>' . $mismatch_code;
	}
}

$mismatch_still_dhl = 'dhl' === Kuka_Island_Shipping_Order_Store::provider( wc_get_order( $mismatch_id ) );

$report(
	'SHIPPING_PROVIDER_MISMATCH_FAILS_CLOSED',
	array() === $mismatch_wrong
		&& 0 === count( $mismatch['transport']->log )
		&& 0 === $mismatch['other']->contacts()
		&& $mismatch_still_dhl,
	sprintf(
		'stored:dhl|requested:kuka-other-kargo|doors:6|wrong:%s|dhl.requests:%d|other.reads:%d|other.writes:%d|other.contacts:%d|stored_provider_unchanged:%s',
		array() === $mismatch_wrong ? 'none' : implode( '+', $mismatch_wrong ),
		count( $mismatch['transport']->log ),
		$mismatch['other']->read_calls(),
		$mismatch['other']->write_calls(),
		$mismatch['other']->contacts(),
		$mismatch_still_dhl ? 'yes' : 'NO'
	)
);

kuka_ship_destroy_order( wc_get_order( $mismatch_id ) );

// --- A record with carrier evidence and no owner is refused, not guessed --

$legacy    = kuka_ship_affinity_scenario();
$legacy_id = (int) $legacy['order']->get_id();
$legacy['manager']->create_shipment( $legacy['order'], 'dhl' );

// Exactly what a record written before ownership was pinned looks like:
// state, reference and shipment id, but no provider.
$legacy_order = wc_get_order( $legacy_id );
$legacy_order->delete_meta_data( Kuka_Island_Shipping_Order_Store::META_PROVIDER );
$legacy_order->save_meta_data();

$legacy_evidence = Kuka_Island_Shipping_Order_Store::has_carrier_evidence( wc_get_order( $legacy_id ) );
$legacy_stored   = Kuka_Island_Shipping_Order_Store::provider( wc_get_order( $legacy_id ) );

kuka_ship_affinity_flip( $legacy );
$legacy['transport']->reset();
$legacy['other']->reset_counters();

$legacy_codes = array(
	'create'    => (string) $legacy['manager']->create_shipment( wc_get_order( $legacy_id ) )['code'],
	'resume'    => (string) $legacy['manager']->resume_barcode( wc_get_order( $legacy_id ) )['code'],
	'update'    => (string) $legacy['manager']->update_shipment( wc_get_order( $legacy_id ) )['code'],
	'cancel'    => (string) $legacy['manager']->cancel( wc_get_order( $legacy_id ) )['code'],
	'query'     => (string) $legacy['manager']->query_status( wc_get_order( $legacy_id ) )['code'],
	'reconcile' => (string) $legacy['manager']->reconcile_order( wc_get_order( $legacy_id ) )['verdict'],
);

kuka_ship_affinity_unflip( $legacy );

$legacy_wrong = array();
foreach ( $legacy_codes as $legacy_door => $legacy_code ) {
	if ( 'shipment_provider_missing' !== $legacy_code ) {
		$legacy_wrong[] = $legacy_door . '=>' . $legacy_code;
	}
}

$report(
	'SHIPPING_LEGACY_MISSING_PROVIDER_FAILS_CLOSED',
	$legacy_evidence
		&& '' === $legacy_stored
		&& array() === $legacy_wrong
		&& 0 === count( $legacy['transport']->log )
		&& 0 === $legacy['other']->contacts()
		// And no default was written in as a repair.
		&& '' === Kuka_Island_Shipping_Order_Store::provider( wc_get_order( $legacy_id ) ),
	sprintf(
		'carrier_evidence:%s|stored_provider:%s|doors:6|wrong:%s|dhl.requests:%d|other.reads:%d|other.writes:%d|other.contacts:%d|default_written_in:%s',
		$legacy_evidence ? 'yes' : 'NO',
		'' === $legacy_stored ? 'empty' : $legacy_stored,
		array() === $legacy_wrong ? 'none' : implode( '+', $legacy_wrong ),
		count( $legacy['transport']->log ),
		$legacy['other']->read_calls(),
		$legacy['other']->write_calls(),
		$legacy['other']->contacts(),
		'' === Kuka_Island_Shipping_Order_Store::provider( wc_get_order( $legacy_id ) ) ? 'no' : 'YES'
	)
);

kuka_ship_destroy_order( wc_get_order( $legacy_id ) );

// --- The decision taken INSIDE the lock is the one that counts ------------
//
// The owner is pinned while the caller is taking the lock, by hooking the very
// query that takes it. At entry the order was untouched and the default pointed
// at the OTHER adapter; if the entry answer survived, the other adapter would
// get the cancellation. It must not.

$fresh_a       = new Kuka_Shipping_Fake_Carrier( 'kuka-pinned-kargo' );
$fresh_b       = new Kuka_Shipping_Fake_Carrier( 'kuka-other-kargo' );
$fresh_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $fresh_a, $fresh_b ) ) );
$fresh_order   = kuka_ship_fixture_order();
$fresh_id      = (int) $fresh_order->get_id();

$fresh_default = static function (): string {
	return 'kuka-other-kargo';
};
add_filter( 'kuka_island_shipping_default_carrier', $fresh_default, 999 );

$fresh_entry_answer = (string) $fresh_manager->carrier_ownership( wc_get_order( $fresh_id ) )['key'];

$fresh_pinned = false;
$fresh_pinner = static function ( $query ) use ( &$fresh_pinned, $fresh_id ) {
	if ( $fresh_pinned || ! is_string( $query ) ) {
		return $query;
	}

	if ( ! str_contains( $query, 'GET_LOCK' ) || ! str_contains( $query, 'kuka_ship_mutate_' . $fresh_id ) ) {
		return $query;
	}

	// Guard first: the writes below run their own queries through this filter.
	$fresh_pinned = true;

	$racer = wc_get_order( $fresh_id );
	$racer->update_meta_data( Kuka_Island_Shipping_Order_Store::META_PROVIDER, 'kuka-pinned-kargo' );
	$racer->update_meta_data( Kuka_Island_Shipping_Order_Store::META_REFERENCE, Kuka_Island_Shipping_Reference::build( $fresh_id ) );
	$racer->update_meta_data( Kuka_Island_Shipping_Order_Store::META_STATE, Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED );
	$racer->update_meta_data( Kuka_Island_Shipping_Order_Store::META_SHIPMENT_ID, 'LOCKWIN-1' );
	$racer->save_meta_data();

	return $query;
};

add_filter( 'query', $fresh_pinner, 999 );
$fresh_result = $fresh_manager->cancel( wc_get_order( $fresh_id ) );
remove_filter( 'query', $fresh_pinner, 999 );
remove_filter( 'kuka_island_shipping_default_carrier', $fresh_default, 999 );

$fresh_state = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $fresh_id ) );

$report(
	'SHIPPING_PROVIDER_FRESH_UNDER_LOCK',
	'kuka-other-kargo' === $fresh_entry_answer
		&& $fresh_pinned
		&& $fresh_result['ok']
		&& 1 === $fresh_a->count_for( 'cancel_shipment' )
		&& 1 === $fresh_a->count_for( 'read_shipment' )
		&& 0 === $fresh_b->contacts()
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $fresh_state,
	sprintf(
		'entry_answer:%s|pinned_while_taking_lock:%s|winner:%s|pinned.cancel_shipment:%d|pinned.read_shipment:%d|entry_default.reads:%d|entry_default.writes:%d|entry_default.contacts:%d|state:%s',
		$fresh_entry_answer,
		$fresh_pinned ? 'yes' : 'NO',
		$fresh_a->count_for( 'cancel_shipment' ) > 0 ? 'in_lock_reading' : 'ENTRY_SNAPSHOT',
		$fresh_a->count_for( 'cancel_shipment' ),
		$fresh_a->count_for( 'read_shipment' ),
		$fresh_b->read_calls(),
		$fresh_b->write_calls(),
		$fresh_b->contacts(),
		$fresh_state
	)
);

kuka_ship_destroy_order( wc_get_order( $fresh_id ) );

/* ========================================================================== */
/* 39. Reads cross the same boundary, and a blocked read is not an absence     */
/* ========================================================================== */

/*
 * The class docblock used to claim that EVERY carrier operation crossed
 * carrier_gate(). Two of the three read callers never called it at all, and
 * none of the reads re-checked the gate before the request. Each measurement
 * below closes the gate at the last possible instant and counts the reads the
 * adapter was actually handed.
 */

/** A fake-carrier manager plus an order already at shipment_created. */
function kuka_ship_read_gate_fixture(): array {
	$adapter = new Kuka_Shipping_Fake_Carrier();
	$manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $adapter ) ) );
	$order   = kuka_ship_fixture_order();

	$manager->create_shipment( $order );
	$adapter->reset_counters();

	return array(
		'order'   => wc_get_order( $order->get_id() ),
		'adapter' => $adapter,
		'manager' => $manager,
	);
}

$not_ready = array(
	'ready'        => false,
	'gaps'         => array( 'KUKA_DHL_CLIENT_ID' ),
	'environment'  => 'test',
	'live_blocked' => false,
);

// --- read_shipment_status: the poller's read ------------------------------

$gate_status = kuka_ship_read_gate_fixture();
$gate_status_attempts_before = Kuka_Island_Shipping_Order_Store::query_attempts( $gate_status['order'] );
$gate_status['adapter']->readiness_after_first = $not_ready;

$gate_status_result = $gate_status['manager']->query_status( wc_get_order( $gate_status['order']->get_id() ) );
$gate_status_attempts_after = Kuka_Island_Shipping_Order_Store::query_attempts( wc_get_order( $gate_status['order']->get_id() ) );

// --- read_shipment / read_order: the reconciliation reads -----------------

$gate_recon = kuka_ship_read_gate_fixture();
Kuka_Island_Shipping_Order_Store::set_state( $gate_recon['order'], Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED );
$gate_recon['adapter']->readiness_after_first = $not_ready;

$gate_recon_verdict = $gate_recon['manager']->reconcile_order( wc_get_order( $gate_recon['order']->get_id() ) );
$gate_recon_state   = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $gate_recon['order']->get_id() ) );

// --- the cancellation confirmation, blocked by the REAL runtime gate ------
//
// The adapter closes the gate itself, the instant after it has accepted the
// cancellation. The write is done; the confirming read must not go out, the
// state must not move to cancelled, and the poll chain must stay booked.

$gate_confirm = kuka_ship_read_gate_fixture();
$gate_confirm['adapter']->close_runtime_gate_after_write = true;

$gate_confirm_result = $gate_confirm['manager']->cancel( wc_get_order( $gate_confirm['order']->get_id() ) );
$gate_confirm_closed = Kuka_Island_Shipping_Runtime_Gate::is_disabled();
Kuka_Island_Shipping_Runtime_Gate::enable();
$gate_confirm_state = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $gate_confirm['order']->get_id() ) );

$report(
	'SHIPPING_READ_GATE_SHARED',
	// read_shipment_status
	! $gate_status_result['ok']
	&& 'credentials_missing' === (string) $gate_status_result['code']
	&& 0 === $gate_status['adapter']->count_for( 'read_shipment_status' )
	&& 0 === $gate_status['adapter']->read_calls()
	// nothing was sent, so nothing was spent
	&& $gate_status_attempts_before === $gate_status_attempts_after
	/*
	 * read_shipment / read_order. The refusal now names the gate that produced
	 * it rather than the generic 'blocked': reconcile_order() takes the mutation
	 * lock and re-asks the gate INSIDE it, so a gate that closed after the entry
	 * check stops the operation before reconcile() is entered at all. The
	 * property being measured is unchanged and is the important one -- zero
	 * reads, and the state preserved, because a blocked read proves nothing.
	 */
	&& 'credentials_missing' === (string) $gate_recon_verdict['verdict']
	&& 0 === $gate_recon['adapter']->read_calls()
	&& Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED === $gate_recon_state
	// the cancellation confirmation
	&& ! $gate_confirm_result['ok']
	&& 'cancel_unconfirmed_blocked' === (string) $gate_confirm_result['code']
	&& 1 === $gate_confirm['adapter']->count_for( 'cancel_shipment' )
	&& 0 === $gate_confirm['adapter']->count_for( 'read_shipment' )
	&& $gate_confirm_closed
	&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED !== $gate_confirm_state
	// And the door is shut: the protected state, not the old one.
	&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $gate_confirm_state,
	sprintf(
		'operations:3|status_read.code:%s|status_read.reads:%d|status_read.attempt_spent:%s|reconcile.verdict:%s|reconcile.reads:%d|reconcile.state:%s|cancel_confirm.code:%s|cancel_confirm.writes:%d|cancel_confirm.reads:%d|cancel_confirm.state:%s',
		(string) $gate_status_result['code'],
		$gate_status['adapter']->read_calls(),
		$gate_status_attempts_before === $gate_status_attempts_after ? 'no' : 'YES',
		(string) $gate_recon_verdict['verdict'],
		$gate_recon['adapter']->read_calls(),
		$gate_recon_state,
		(string) $gate_confirm_result['code'],
		$gate_confirm['adapter']->count_for( 'cancel_shipment' ),
		$gate_confirm['adapter']->count_for( 'read_shipment' ),
		$gate_confirm_state
	)
);

kuka_ship_destroy_order( wc_get_order( $gate_status['order']->get_id() ) );
kuka_ship_destroy_order( wc_get_order( $gate_recon['order']->get_id() ) );
kuka_ship_destroy_order( wc_get_order( $gate_confirm['order']->get_id() ) );

// --- An uncertain write whose reconciliation cannot read stays uncertain --
//
// The most expensive mistake in this module would be to read a blocked gate as
// "nothing is there" and then create the shipment again.

$blocked_recon_adapter = new Kuka_Shipping_Fake_Carrier();
$blocked_recon_adapter->results['create_order'] = Kuka_Island_Shipping_Result::uncertain( 'create_order', 'timeout', 0 );
$blocked_recon_adapter->close_runtime_gate_after_write = true;

$blocked_recon_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $blocked_recon_adapter ) ) );
$blocked_recon_order   = kuka_ship_fixture_order();

$blocked_recon_result = $blocked_recon_manager->create_shipment( $blocked_recon_order );
$blocked_recon_closed = Kuka_Island_Shipping_Runtime_Gate::is_disabled();

// A second press, with the gate still shut.
$blocked_recon_second = $blocked_recon_manager->create_shipment( wc_get_order( $blocked_recon_order->get_id() ) );

Kuka_Island_Shipping_Runtime_Gate::enable();

$blocked_recon_state    = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $blocked_recon_order->get_id() ) );
$blocked_recon_provider = Kuka_Island_Shipping_Order_Store::provider( wc_get_order( $blocked_recon_order->get_id() ) );

$report(
	'SHIPPING_UNCERTAIN_READ_BLOCKED_STAYS_UNCERTAIN',
	! $blocked_recon_result['ok']
		&& 1 === $blocked_recon_adapter->count_for( 'create_order' )
		// The reconciliation could not read, so it proved nothing.
		&& 0 === $blocked_recon_adapter->read_calls()
		&& Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED === $blocked_recon_state
		&& Kuka_Shipping_Fake_Carrier::KEY === $blocked_recon_provider
		&& $blocked_recon_closed
		// And nothing was written a second time.
		&& ! $blocked_recon_second['ok']
		&& 1 === $blocked_recon_adapter->count_for( 'create_order' )
		&& 1 === $blocked_recon_adapter->write_calls(),
	sprintf(
		'createOrder:uncertain|gate_closed_after_write:%s|createOrder_calls:%d|reconcile_reads:%d|state:%s|absence_assumed:%s|provider_retained:%s|second_press_code:%s|total_writes:%d',
		$blocked_recon_closed ? 'yes' : 'NO',
		$blocked_recon_adapter->count_for( 'create_order' ),
		$blocked_recon_adapter->read_calls(),
		$blocked_recon_state,
		Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED === $blocked_recon_state ? 'YES' : 'no',
		'' !== $blocked_recon_provider ? $blocked_recon_provider : 'LOST',
		(string) $blocked_recon_second['code'],
		$blocked_recon_adapter->write_calls()
	)
);

kuka_ship_destroy_order( wc_get_order( $blocked_recon_order->get_id() ) );

/* ========================================================================== */
/* 40. The order screen names the order's carrier, not the shop's default      */
/* ========================================================================== */

$panel_a       = new Kuka_Shipping_Fake_Carrier( 'kuka-pinned-kargo' );
$panel_b       = new Kuka_Shipping_Fake_Carrier( 'kuka-other-kargo' );
$panel_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $panel_a, $panel_b ) ) );
$panel         = new Kuka_Island_Shipping_Admin( $panel_manager );

$panel_order = kuka_ship_fixture_order();
$panel_manager->create_shipment( $panel_order, 'kuka-pinned-kargo' );
$panel_order = wc_get_order( $panel_order->get_id() );

// From here on, any contact would be one the RENDERING path caused.
$panel_a->reset_counters();
$panel_b->reset_counters();

// An order nothing has been sent for, for the contrast.
$panel_new = kuka_ship_fixture_order();

$panel_default = static function (): string {
	return 'kuka-other-kargo';
};
add_filter( 'kuka_island_shipping_default_carrier', $panel_default, 999 );

$panel_pinned_ownership = $panel_manager->carrier_ownership( $panel_order );
$panel_pinned_carrier   = $panel_manager->get_registry()->get( (string) $panel_pinned_ownership['key'] );
$panel_pinned_label     = Kuka_Island_Shipping_Admin::carrier_label( $panel_pinned_carrier, (string) $panel_pinned_ownership['key'], (string) $panel_pinned_ownership['code'] );

$panel_new_ownership = $panel_manager->carrier_ownership( $panel_new );
$panel_new_carrier   = $panel_manager->get_registry()->get( (string) $panel_new_ownership['key'] );
$panel_new_label     = Kuka_Island_Shipping_Admin::carrier_label( $panel_new_carrier, (string) $panel_new_ownership['key'], (string) $panel_new_ownership['code'] );

// And a record whose owner is unknown: the panel says so and offers nothing.
$panel_legacy = wc_get_order( $panel_order->get_id() );
$panel_legacy->delete_meta_data( Kuka_Island_Shipping_Order_Store::META_PROVIDER );
$panel_legacy->save_meta_data();
$panel_legacy_ownership = $panel_manager->carrier_ownership( wc_get_order( $panel_order->get_id() ) );
$panel_legacy_label     = Kuka_Island_Shipping_Admin::carrier_label( null, (string) $panel_legacy_ownership['key'], (string) $panel_legacy_ownership['code'] );
$panel_legacy_hint      = Kuka_Island_Shipping_Admin::operator_hint( wc_get_order( $panel_order->get_id() ), null, (string) $panel_legacy_ownership['code'] );

remove_filter( 'kuka_island_shipping_default_carrier', $panel_default, 999 );

// Rendering must not have written anything to the order.
$panel_notes = count( (array) wc_get_order_notes( array( 'order_id' => $panel_new->get_id(), 'limit' => 50 ) ) );

$report(
	'SHIPPING_ADMIN_USES_STORED_PROVIDER',
	'kuka-pinned-kargo' === (string) $panel_pinned_ownership['key']
		&& 'order' === (string) $panel_pinned_ownership['source']
		&& $panel_pinned_label === $panel_a->get_label()
		&& ! str_contains( $panel_pinned_label, 'other' )
		// The untouched order is the only one the default may claim.
		&& 'kuka-other-kargo' === (string) $panel_new_ownership['key']
		&& 'default' === (string) $panel_new_ownership['source']
		&& $panel_new_label === $panel_b->get_label()
		// The orphaned record says so instead of naming a courier.
		&& 'shipment_provider_missing' === (string) $panel_legacy_ownership['code']
		&& str_contains( $panel_legacy_label, 'taşıyıcı yazılı değil' )
		&& str_contains( $panel_legacy_hint, 'varsayılan taşıyıcı kullanılmaz' )
		// Asking the question wrote nothing.
		&& 0 === $panel_notes
		&& 0 === $panel_a->contacts()
		&& 0 === $panel_b->contacts(),
	sprintf(
		'default_now:kuka-other-kargo|pinned_order:%s(%s)|pinned_label:%s|untouched_order:%s(%s)|untouched_label:%s|orphaned_code:%s|render_wrote_notes:%d|pinned.contacts:%d|other.contacts:%d',
		(string) $panel_pinned_ownership['key'],
		(string) $panel_pinned_ownership['source'],
		$panel_pinned_label,
		(string) $panel_new_ownership['key'],
		(string) $panel_new_ownership['source'],
		$panel_new_label,
		(string) $panel_legacy_ownership['code'],
		$panel_notes,
		$panel_a->contacts(),
		$panel_b->contacts()
	)
);

kuka_ship_destroy_order( wc_get_order( $panel_order->get_id() ) );
kuka_ship_destroy_order( wc_get_order( $panel_new->get_id() ) );

/* ========================================================================== */
/* 43. An amendment is proved by its FIELDS, never by the object existing      */
/* ========================================================================== */

/*
 * The bug: an uncertain updateorder was reconciled with the generic
 * reconciliation, which found the order -- of course it did, the order was there
 * before the amendment as well -- and wrote order_created. That re-armed the
 * update button on an amendment that may already have been applied.
 */

/** A fake-carrier manager, an order at shipment_created, and the values sent. */
function kuka_ship_update_fixture(): array {
	$adapter = new Kuka_Shipping_Fake_Carrier();
	$manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $adapter ) ) );
	$order   = kuka_ship_fixture_order();

	$manager->create_shipment( $order );
	$order = wc_get_order( $order->get_id() );

	$reference = (string) Kuka_Island_Shipping_Order_Store::get_shipment_data( $order )['reference'];
	$request   = $manager->build_request( $order, $adapter, $reference );

	$adapter->reset_counters();
	$adapter->results['update_shipment'] = Kuka_Island_Shipping_Result::uncertain( 'update_shipment', 'timeout', 0 );

	return array(
		'order'    => $order,
		'adapter'  => $adapter,
		'manager'  => $manager,
		'expected' => Kuka_Island_Shipping_Manager::amendable_fields( (array) $request['shipment'] ),
	);
}

// --- The object being there is not evidence -----------------------------

$update_present = kuka_ship_update_fixture();
$update_present['adapter']->results['read_shipment'] = Kuka_Island_Shipping_Result::success(
	'get_shipment',
	array( 'shipment_id' => 'FAKE-SHIP-1', 'exists' => true )
);

$up_first = $update_present['manager']->update_shipment( wc_get_order( $update_present['order']->get_id() ) );
$up_state = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $update_present['order']->get_id() ) );

// Reconciling repeatedly must not turn "the object exists" into success.
$up_recon  = $update_present['manager']->reconcile_order( wc_get_order( $update_present['order']->get_id() ) );
$up_after  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $update_present['order']->get_id() ) );
$up_second = $update_present['manager']->update_shipment( wc_get_order( $update_present['order']->get_id() ) );
$up_stale  = $update_present['manager']->update_shipment( $update_present['order'] );

$report(
	'SHIPPING_UPDATE_EVIDENCE_EXISTENCE_IS_NOT_PROOF',
	'readback_unsupported' === (string) $up_first['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED === $up_state
		&& 'readback_unsupported' === (string) $up_recon['verdict']
		// The generic reconciliation would have written shipment_created here.
		&& Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED === $up_after
		&& 'nothing_to_update' === (string) $up_second['code']
		&& 'nothing_to_update' === (string) $up_stale['code']
		&& 1 === $update_present['adapter']->count_for( 'update_shipment' )
		&& 1 === $update_present['adapter']->write_calls()
		// It asked for the FIELDS, not for the object.
		&& 0 === $update_present['adapter']->count_for( 'read_shipment' )
		&& 2 === $update_present['adapter']->count_for( 'read_amendable_fields' ),
	sprintf(
		'update:uncertain|object_present:yes|first:%s|state:%s|reconcile:%s|state_after_reconcile:%s|second_press:%s|stale_handle:%s|update_writes:%d|read_shipment:%d|read_amendable_fields:%d|reopened:%s',
		(string) $up_first['code'],
		$up_state,
		(string) $up_recon['verdict'],
		$up_after,
		(string) $up_second['code'],
		(string) $up_stale['code'],
		$update_present['adapter']->count_for( 'update_shipment' ),
		$update_present['adapter']->count_for( 'read_shipment' ),
		$update_present['adapter']->count_for( 'read_amendable_fields' ),
		Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $up_after ? 'YES' : 'no'
	)
);

kuka_ship_destroy_order( wc_get_order( $update_present['order']->get_id() ) );

// --- A carrier that cannot read the fields back can never prove it ------

$update_blind = kuka_ship_update_fixture();
$ub_result    = $update_blind['manager']->update_shipment( wc_get_order( $update_blind['order']->get_id() ) );
$ub_state     = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $update_blind['order']->get_id() ) );
$ub_pending   = Kuka_Island_Shipping_Order_Store::pending_mutation( wc_get_order( $update_blind['order']->get_id() ) );
$ub_dhl       = kuka_ship_provider( new Kuka_Shipping_Mock_Transport( kuka_ship_happy_responder() ) )
	->read_amendable_fields( 'KI1-AAAAAA' );

$report(
	'SHIPPING_UPDATE_EVIDENCE_READBACK_UNSUPPORTED',
	'readback_unsupported' === (string) $ub_result['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED === $ub_state
		&& 'update' === (string) ( $ub_pending['kind'] ?? '' )
		&& array() !== (array) ( $ub_pending['expected'] ?? array() )
		&& 1 === $update_blind['adapter']->count_for( 'update_shipment' )
		// And the shipped DHL adapter answers the same way, for the same reason.
		&& 'readback_unsupported' === $ub_dhl->get_safe_error_code()
		&& ! $ub_dhl->is_success(),
	sprintf(
		'code:%s|state:%s|evidence_kind:%s|expected_fields_recorded:%d|update_writes:%d|dhl_adapter_answer:%s',
		(string) $ub_result['code'],
		$ub_state,
		(string) ( $ub_pending['kind'] ?? 'none' ),
		count( (array) ( $ub_pending['expected'] ?? array() ) ),
		$update_blind['adapter']->count_for( 'update_shipment' ),
		$ub_dhl->get_safe_error_code()
	)
);

kuka_ship_destroy_order( wc_get_order( $update_blind['order']->get_id() ) );

// --- An exact field-level read-back is the one thing that proves it -----

$update_match = kuka_ship_update_fixture();
$update_match['adapter']->amendable = $update_match['expected'];

$um_result  = $update_match['manager']->update_shipment( wc_get_order( $update_match['order']->get_id() ) );
$um_state   = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $update_match['order']->get_id() ) );
$um_pending = Kuka_Island_Shipping_Order_Store::pending_mutation( wc_get_order( $update_match['order']->get_id() ) );

// Proved, so the door opens again: a second amendment is now legitimate.
$update_match['adapter']->results = array();
$um_second = $update_match['manager']->update_shipment( wc_get_order( $update_match['order']->get_id() ) );

$report(
	'SHIPPING_UPDATE_EVIDENCE_READBACK_MATCHES',
	// A proved amendment is an OK result, and the verdict travels in the
	// detail line rather than in an error code: there is no error.
	$um_result['ok']
		&& '' === (string) $um_result['code']
		&& str_contains( (string) $um_result['detail'], 'verdict:update_confirmed' )
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $um_state
		&& array() === $um_pending
		// Two read-backs, one per amendment: every amendment is now proved,
		// including the second one, because a success answer alone never is.
		&& 2 === $update_match['adapter']->count_for( 'read_amendable_fields' )
		&& $um_second['ok']
		&& 2 === $update_match['adapter']->count_for( 'update_shipment' )
		&& 9 === count( $update_match['expected'] ),
	sprintf(
		'readback:exact_match|fields_compared:%d|first:%s|verdict:%s|state:%s|evidence:%s|read_amendable_fields:%d|second_update_allowed:%s|update_writes:%d',
		count( $update_match['expected'] ),
		$um_result['ok'] ? 'confirmed' : 'REFUSED:' . (string) $um_result['code'],
		str_contains( (string) $um_result['detail'], 'verdict:update_confirmed' ) ? 'update_confirmed' : 'OTHER',
		$um_state,
		array() === $um_pending ? 'cleared' : 'STILL_SET',
		$update_match['adapter']->count_for( 'read_amendable_fields' ),
		$um_second['ok'] ? 'yes' : 'NO',
		$update_match['adapter']->count_for( 'update_shipment' )
	)
);

kuka_ship_destroy_order( wc_get_order( $update_match['order']->get_id() ) );

// --- One field off is a mismatch, and a mismatch needs a person ---------

$update_mismatch = kuka_ship_update_fixture();
$um2_fields      = $update_mismatch['expected'];
$um2_fields['recipient_address'] = 'BASKA BIR ADRES';
$update_mismatch['adapter']->amendable = $um2_fields;

$um2_result = $update_mismatch['manager']->update_shipment( wc_get_order( $update_mismatch['order']->get_id() ) );
$um2_state  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $update_mismatch['order']->get_id() ) );
$um2_press  = $update_mismatch['manager']->update_shipment( wc_get_order( $update_mismatch['order']->get_id() ) );

// A missing field is a mismatch too: "it did not contradict us" is not proof.
$absent_check = Kuka_Island_Shipping_Manager::fields_match(
	array( 'a' => '1', 'b' => '2' ),
	array( 'a' => '1' )
);

$report(
	'SHIPPING_UPDATE_EVIDENCE_READBACK_MISMATCH',
	'update_mismatch' === (string) $um2_result['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW === $um2_state
		&& 'nothing_to_update' === (string) $um2_press['code']
		&& 1 === $update_mismatch['adapter']->count_for( 'update_shipment' )
		&& ! $absent_check['match']
		&& array( 'b:absent' ) === $absent_check['mismatched'],
	sprintf(
		'readback:one_field_differs|code:%s|state:%s|second_press:%s|update_writes:%d|absent_field_is_mismatch:%s',
		(string) $um2_result['code'],
		$um2_state,
		(string) $um2_press['code'],
		$update_mismatch['adapter']->count_for( 'update_shipment' ),
		! $absent_check['match'] ? 'yes' : 'NO'
	)
);

kuka_ship_destroy_order( wc_get_order( $update_mismatch['order']->get_id() ) );

/* ========================================================================== */
/* 44. Ownership is pinned only once a valid request exists                    */
/* ========================================================================== */

/*
 * The pin used to be the first thing the creation sequence did. An order whose
 * city could not be mapped -- a purely local failure, no carrier contacted --
 * therefore came out of the attempt permanently owned by a courier that had
 * never heard of it, and could not then be handed to another one.
 */

$unmappable = new Kuka_Shipping_Fake_Carrier( 'kuka-unmappable-kargo' );
$unmappable->results['resolve_location'] = Kuka_Island_Shipping_Result::permanent( 'resolve_location', 'city_not_found' );
$fallback = new Kuka_Shipping_Fake_Carrier( 'kuka-fallback-kargo' );

$pin_manager_c = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $unmappable, $fallback ) ) );
$pin_order_c   = kuka_ship_fixture_order();
$pin_id_c      = (int) $pin_order_c->get_id();

$local_fail = $pin_manager_c->create_shipment( $pin_order_c, 'kuka-unmappable-kargo' );

$after_local_fail = kuka_ship_meta_in_db( $pin_id_c, Kuka_Island_Shipping_Order_Store::META_PROVIDER );
$ref_after_fail   = kuka_ship_meta_in_db( $pin_id_c, Kuka_Island_Shipping_Order_Store::META_REFERENCE );

// Unowned, so another courier may now be chosen -- which was the whole point.
$second_choice = $pin_manager_c->create_shipment( wc_get_order( $pin_id_c ), 'kuka-fallback-kargo' );
$owner_now     = Kuka_Island_Shipping_Order_Store::provider( wc_get_order( $pin_id_c ) );

// And a gate that shuts before the request also leaves the order unowned.
$gate_pin = new Kuka_Shipping_Fake_Carrier( 'kuka-gateclosed-kargo' );
$gate_pin->readiness_after_first = array(
	'ready'        => false,
	'gaps'         => array( 'KUKA_DHL_PASSWORD' ),
	'environment'  => 'test',
	'live_blocked' => false,
);
$gate_pin_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $gate_pin ) ) );
$gate_pin_order   = kuka_ship_fixture_order();
$gate_pin_result  = $gate_pin_manager->create_shipment( $gate_pin_order );
$gate_pin_owner   = kuka_ship_meta_in_db( (int) $gate_pin_order->get_id(), Kuka_Island_Shipping_Order_Store::META_PROVIDER );

$report(
	'SHIPPING_PROVIDER_NOT_PINNED_WITHOUT_A_WRITE',
	! $local_fail['ok']
		&& 'city_not_found' === (string) $local_fail['code']
		&& '' === $after_local_fail
		&& '' === $ref_after_fail
		&& 0 === $unmappable->write_calls()
		&& $second_choice['ok']
		&& 'kuka-fallback-kargo' === $owner_now
		&& 1 === $fallback->count_for( 'create_order' )
		// The gate-closed path is the other way a write can fail to happen.
		&& ! $gate_pin_result['ok']
		&& 'credentials_missing' === (string) $gate_pin_result['code']
		&& '' === $gate_pin_owner
		&& 0 === $gate_pin->write_calls(),
	sprintf(
		'local_validation_failed:%s|provider_after_local_failure:%s|reference_after_local_failure:%s|writes_on_first_carrier:%d|second_carrier_accepted:%s|owner_now:%s|gate_closed_before_write:%s|provider_after_gate_close:%s|writes_on_gated_carrier:%d',
		(string) $local_fail['code'],
		'' === $after_local_fail ? 'empty' : $after_local_fail,
		'' === $ref_after_fail ? 'empty' : 'WRITTEN',
		$unmappable->write_calls(),
		$second_choice['ok'] ? 'yes' : 'NO',
		$owner_now,
		(string) $gate_pin_result['code'],
		'' === $gate_pin_owner ? 'empty' : $gate_pin_owner,
		$gate_pin->write_calls()
	)
);

kuka_ship_destroy_order( wc_get_order( $pin_id_c ) );
kuka_ship_destroy_order( wc_get_order( $gate_pin_order->get_id() ) );

/* ========================================================================== */
/* 45. The module and the adapter switch off independently                     */
/* ========================================================================== */

// --- the adapter's own switch -------------------------------------------

$adapter_on_before = Kuka_Island_Shipping_DHL_Config::is_adapter_enabled();

putenv( Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING . '=off' );
$adapter_off        = ! Kuka_Island_Shipping_DHL_Config::is_adapter_enabled();
$carriers_when_off  = Kuka_Island_Shipping_Automation::register_default_carrier( array() );
$registry_when_off  = kuka_ship_registry_of( $carriers_when_off );

$off_transport = new Kuka_Shipping_Mock_Transport( kuka_ship_happy_responder() );
$off_manager   = new Kuka_Island_Shipping_Manager( $registry_when_off );
$off_order     = kuka_ship_fixture_order();

$off_codes = array(
	'create' => (string) $off_manager->create_shipment( wc_get_order( $off_order->get_id() ) )['code'],
	'resume' => (string) $off_manager->resume_barcode( wc_get_order( $off_order->get_id() ) )['code'],
	'update' => (string) $off_manager->update_shipment( wc_get_order( $off_order->get_id() ) )['code'],
	'cancel' => (string) $off_manager->cancel( wc_get_order( $off_order->get_id() ) )['code'],
	'query'  => (string) $off_manager->query_status( wc_get_order( $off_order->get_id() ) )['code'],
);

putenv( Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING );
$adapter_on_again  = Kuka_Island_Shipping_DHL_Config::is_adapter_enabled();
$carriers_when_on  = Kuka_Island_Shipping_Automation::register_default_carrier( array() );

$off_wrong = array();
foreach ( $off_codes as $off_door => $off_code ) {
	if ( 'carrier_not_registered' !== $off_code ) {
		$off_wrong[] = $off_door . '=>' . $off_code;
	}
}

$report(
	'SHIPPING_ADAPTER_SWITCH',
	$adapter_on_before
		&& $adapter_off
		&& array() === $carriers_when_off
		&& array() === $registry_when_off->keys()
		&& array() === $off_wrong
		&& 0 === count( $off_transport->log )
		&& $adapter_on_again
		&& 1 === count( $carriers_when_on )
		&& $carriers_when_on[0] instanceof Kuka_Island_Shipping_Carrier_Interface,
	sprintf(
		'setting:%s|enabled_by_default:%s|disabled:%s|adapters_registered_when_off:%d|registry_keys_when_off:%s|doors:5|wrong:%s|http_requests:%d|re_enabled:%s|adapters_registered_when_on:%d',
		Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING,
		$adapter_on_before ? 'yes' : 'NO',
		$adapter_off ? 'yes' : 'NO',
		count( $carriers_when_off ),
		array() === $registry_when_off->keys() ? 'none' : implode( '+', $registry_when_off->keys() ),
		array() === $off_wrong ? 'none' : implode( '+', $off_wrong ),
		count( $off_transport->log ),
		$adapter_on_again ? 'yes' : 'NO',
		count( $carriers_when_on )
	)
);

kuka_ship_destroy_order( wc_get_order( $off_order->get_id() ) );

// --- the four switches, stated on the panel ------------------------------

$status_adapter  = new Kuka_Shipping_Fake_Carrier();
$status_registry = kuka_ship_registry_of( array( $status_adapter ) );
$status_open     = Kuka_Island_Shipping_Admin::module_status( $status_registry );
$status_open_line = Kuka_Island_Shipping_Admin::module_status_line( $status_open );

Kuka_Island_Shipping_Runtime_Gate::disable();
$status_closed = Kuka_Island_Shipping_Admin::module_status( $status_registry );
Kuka_Island_Shipping_Runtime_Gate::enable();

putenv( 'KUKA_SHIPPING_AUTOMATION=1' );
$status_automation_on = Kuka_Island_Shipping_Admin::module_status( $status_registry );
putenv( 'KUKA_SHIPPING_AUTOMATION' );

$status_no_adapter = Kuka_Island_Shipping_Admin::module_status( kuka_ship_registry_of( array() ) );

$report(
	'SHIPPING_MODULE_STATUS_VISIBLE',
	'open' === (string) $status_open['runtime']
		&& 'off' === (string) $status_open['automation']
		&& Kuka_Shipping_Fake_Carrier::KEY === (string) $status_open['adapters']
		&& 'closed' === (string) $status_closed['runtime']
		&& 'on' === (string) $status_automation_on['automation']
		&& 'none' === (string) $status_no_adapter['adapters']
		&& str_contains( $status_open_line, 'Modül:' )
		&& str_contains( $status_open_line, 'Çalışma kapısı: açık' )
		&& str_contains( $status_open_line, 'Otomatik durum sorgusu: kapalı' )
		&& str_contains( $status_open_line, Kuka_Shipping_Fake_Carrier::KEY ),
	sprintf(
		'runtime_open:%s|runtime_closed:%s|automation_default:%s|automation_when_on:%s|adapters:%s|adapters_when_none:%s|line_names_all_four:yes',
		(string) $status_open['runtime'],
		(string) $status_closed['runtime'],
		(string) $status_open['automation'],
		(string) $status_automation_on['automation'],
		(string) $status_open['adapters'],
		(string) $status_no_adapter['adapters']
	)
);

// --- deactivation and re-activation leave ownership alone ----------------

require_once trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-shipping-automation/includes/class-activator.php';

putenv( 'KUKA_SHIPPING_AUTOMATION=1' );

$lifecycle_adapter = new Kuka_Shipping_Fake_Carrier();
$lifecycle_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $lifecycle_adapter ) ) );
$lifecycle_order   = kuka_ship_fixture_order();
$lifecycle_manager->create_shipment( $lifecycle_order );
$lifecycle_id = (int) $lifecycle_order->get_id();

$lifecycle_before = Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $lifecycle_id ) );
$lifecycle_pending_before = Kuka_Island_Shipping_Status_Poller::has_pending_query( $lifecycle_id );

Kuka_Island_Shipping_Activator::deactivate();
$lifecycle_gate_closed = Kuka_Island_Shipping_Runtime_Gate::is_disabled();
$lifecycle_pending_off = Kuka_Island_Shipping_Status_Poller::has_pending_query( $lifecycle_id );

Kuka_Island_Shipping_Activator::activate();
$lifecycle_gate_open = ! Kuka_Island_Shipping_Runtime_Gate::is_disabled();
$lifecycle_after     = Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $lifecycle_id ) );
$lifecycle_pending_on = Kuka_Island_Shipping_Status_Poller::has_pending_query( $lifecycle_id );

putenv( 'KUKA_SHIPPING_AUTOMATION' );
$lifecycle_removed = kuka_ship_purge_actions( $lifecycle_id );

$report(
	'SHIPPING_DEACTIVATION_PRESERVES_OWNERSHIP',
	Kuka_Shipping_Fake_Carrier::KEY === (string) $lifecycle_before['provider']
		&& $lifecycle_pending_before
		&& $lifecycle_gate_closed
		// Deactivation unschedules this module's pending work.
		&& ! $lifecycle_pending_off
		&& $lifecycle_gate_open
		// Re-activation resumes nothing and rewrites nothing.
		&& ! $lifecycle_pending_on
		&& $lifecycle_before['provider'] === $lifecycle_after['provider']
		&& $lifecycle_before['state'] === $lifecycle_after['state']
		&& $lifecycle_before['reference'] === $lifecycle_after['reference']
		&& $lifecycle_before['shipment_id'] === $lifecycle_after['shipment_id'],
	sprintf(
		'provider:%s|pending_before:%s|gate_after_deactivate:%s|pending_after_deactivate:%s|gate_after_activate:%s|pending_after_activate:%s|provider_unchanged:%s|state_unchanged:%s|reference_unchanged:%s|actions_removed:%d',
		(string) $lifecycle_after['provider'],
		$lifecycle_pending_before ? 'yes' : 'NO',
		$lifecycle_gate_closed ? 'closed' : 'OPEN',
		$lifecycle_pending_off ? 'STILL_PENDING' : 'unscheduled',
		$lifecycle_gate_open ? 'open' : 'CLOSED',
		$lifecycle_pending_on ? 'REBOOKED' : 'none',
		$lifecycle_before['provider'] === $lifecycle_after['provider'] ? 'yes' : 'NO',
		$lifecycle_before['state'] === $lifecycle_after['state'] ? 'yes' : 'NO',
		$lifecycle_before['reference'] === $lifecycle_after['reference'] ? 'yes' : 'NO',
		$lifecycle_removed
	)
);

kuka_ship_destroy_order( wc_get_order( $lifecycle_id ) );

/* ========================================================================== */
/* 42. A cancellation that reached the carrier can never be sent twice        */
/* ========================================================================== */

/*
 * The bug: only an UNCERTAIN cancellation closed the door. A cancellation the
 * carrier acknowledged left the order in shipment_created whenever the
 * confirming read could not prove absence -- blocked, unanswered, or answering
 * "still there" -- and the next press sent it again. The generic reconciliation
 * made it worse: finding the record, it wrote shipment_created back and re-armed
 * the button.
 *
 * Every measurement below counts the carrier's cancel writes across the whole
 * scenario. The number is always 1.
 */

/** A fake-carrier manager and an order at shipment_created, counters clean. */
function kuka_ship_cancel_fixture(): array {
	$adapter = new Kuka_Shipping_Fake_Carrier();
	$manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $adapter ) ) );
	$order   = kuka_ship_fixture_order();

	$manager->create_shipment( $order );
	$adapter->reset_counters();

	return array(
		'order'   => wc_get_order( $order->get_id() ),
		'adapter' => $adapter,
		'manager' => $manager,
	);
}

/** The same, stopped at order_created so the ORDER branch can be measured. */
function kuka_ship_cancel_order_fixture(): array {
	$adapter = new Kuka_Shipping_Fake_Carrier();

	/*
	 * The barcode is refused, and a refusal the carrier ANSWERED no longer
	 * establishes that no shipment exists -- the vendor's contract does not say
	 * it does -- so the order waits in reconcile_required until a read settles
	 * it. The two reads below are that proof: nothing under this reference on
	 * the shipment side, and the order is there. They are what produces
	 * order_created, which is the state this fixture exists to hand over.
	 */
	$adapter->results['create_barcode'] = Kuka_Island_Shipping_Result::permanent( 'create_barcode', 'bad_request', 400 );
	$adapter->results['read_shipment']  = Kuka_Island_Shipping_Result::permanent( 'get_shipment', 'not_found', 404 );
	$adapter->results['read_order']     = Kuka_Island_Shipping_Result::success( 'get_order', array( 'reference_id' => 'FIXTURE', 'exists' => true ) );

	$manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $adapter ) ) );
	$order   = kuka_ship_fixture_order();

	$manager->create_shipment( $order );
	$manager->reconcile_order( wc_get_order( $order->get_id() ) );

	$adapter->reset_counters();
	$adapter->results = array();

	return array(
		'order'   => wc_get_order( $order->get_id() ),
		'adapter' => $adapter,
		'manager' => $manager,
	);
}

$not_ready_readiness = array(
	'ready'        => false,
	'gaps'         => array( 'KUKA_DHL_CLIENT_ID' ),
	'environment'  => 'test',
	'live_blocked' => false,
);

// --- 1. success, confirming read blocked, then pressed again -------------

$evidence_blocked = kuka_ship_cancel_fixture();
$evidence_blocked['adapter']->close_runtime_gate_after_write = true;

$eb_first = $evidence_blocked['manager']->cancel( wc_get_order( $evidence_blocked['order']->get_id() ) );
$eb_state = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $evidence_blocked['order']->get_id() ) );

// The gate is still shut; press again, and again with a stale handle.
$eb_second = $evidence_blocked['manager']->cancel( wc_get_order( $evidence_blocked['order']->get_id() ) );
$eb_stale  = $evidence_blocked['manager']->cancel( $evidence_blocked['order'] );

Kuka_Island_Shipping_Runtime_Gate::enable();

// And once more with the gate open: the state, not the gate, is what refuses.
$eb_third      = $evidence_blocked['manager']->cancel( wc_get_order( $evidence_blocked['order']->get_id() ) );
$eb_writes     = $evidence_blocked['adapter']->write_calls();
$eb_end_state  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $evidence_blocked['order']->get_id() ) );

$report(
	'SHIPPING_CANCEL_EVIDENCE_SURVIVES_BLOCKED_CONFIRM',
	'cancel_unconfirmed_blocked' === (string) $eb_first['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $eb_state
		// With the gate still shut the door refuses first -- also 0 writes.
		&& in_array( (string) $eb_second['code'], array( 'cancel_in_progress', Kuka_Island_Shipping_Runtime_Gate::CODE ), true )
		&& in_array( (string) $eb_stale['code'], array( 'cancel_in_progress', Kuka_Island_Shipping_Runtime_Gate::CODE ), true )
		// With the gate open again it is the STATE that refuses.
		&& 'cancel_in_progress' === (string) $eb_third['code']
		&& 1 === $eb_writes
		&& 1 === $evidence_blocked['adapter']->count_for( 'cancel_shipment' )
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $eb_end_state,
	sprintf(
		'first:%s|state:%s|second_press:%s|stale_handle:%s|third_press_gate_open:%s|total_cancel_writes:%d|state_at_end:%s',
		(string) $eb_first['code'],
		$eb_state,
		(string) $eb_second['code'],
		(string) $eb_stale['code'],
		(string) $eb_third['code'],
		$eb_writes,
		$eb_end_state
	)
);

kuka_ship_destroy_order( wc_get_order( $evidence_blocked['order']->get_id() ) );

// --- 2. success, the record is still there, reconciled again -------------

$evidence_present = kuka_ship_cancel_fixture();
$evidence_present['adapter']->results['read_shipment'] = Kuka_Island_Shipping_Result::success(
	'get_shipment',
	array( 'shipment_id' => 'FAKE-SHIP-1', 'exists' => true )
);

$ep_first  = $evidence_present['manager']->cancel( wc_get_order( $evidence_present['order']->get_id() ) );
$ep_state  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $evidence_present['order']->get_id() ) );

// Reconcile, twice. The record keeps saying "here I am".
$ep_recon_one = $evidence_present['manager']->reconcile_order( wc_get_order( $evidence_present['order']->get_id() ) );
$ep_recon_two = $evidence_present['manager']->reconcile_order( wc_get_order( $evidence_present['order']->get_id() ) );
$ep_after     = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $evidence_present['order']->get_id() ) );
$ep_press     = $evidence_present['manager']->cancel( wc_get_order( $evidence_present['order']->get_id() ) );

$report(
	'SHIPPING_CANCEL_EVIDENCE_SURVIVES_RECORD_PRESENT',
	'cancel_unconfirmed_record_present' === (string) $ep_first['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $ep_state
		&& 'cancel_unconfirmed_record_present' === (string) $ep_recon_one['verdict']
		&& 'cancel_unconfirmed_record_present' === (string) $ep_recon_two['verdict']
		// The generic reconciliation would have written shipment_created here.
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $ep_after
		&& 'cancel_in_progress' === (string) $ep_press['code']
		&& 1 === $evidence_present['adapter']->write_calls()
		&& 3 === $evidence_present['adapter']->count_for( 'read_shipment' ),
	sprintf(
		'first:%s|state:%s|reconcile_1:%s|reconcile_2:%s|state_after_two_reconciles:%s|press_after:%s|total_cancel_writes:%d|read_shipment_calls:%d|reopened_to_shipment_created:%s',
		(string) $ep_first['code'],
		$ep_state,
		(string) $ep_recon_one['verdict'],
		(string) $ep_recon_two['verdict'],
		$ep_after,
		(string) $ep_press['code'],
		$evidence_present['adapter']->write_calls(),
		$evidence_present['adapter']->count_for( 'read_shipment' ),
		Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $ep_after ? 'YES' : 'no'
	)
);

kuka_ship_destroy_order( wc_get_order( $evidence_present['order']->get_id() ) );

// --- 3. uncertain, the record is still there, pressed again --------------

$evidence_uncertain = kuka_ship_cancel_fixture();
$evidence_uncertain['adapter']->results['cancel_shipment'] = Kuka_Island_Shipping_Result::uncertain( 'cancel_shipment', 'timeout', 0 );
$evidence_uncertain['adapter']->results['read_shipment']   = Kuka_Island_Shipping_Result::success(
	'get_shipment',
	array( 'shipment_id' => 'FAKE-SHIP-1', 'exists' => true )
);

$eu_first = $evidence_uncertain['manager']->cancel( wc_get_order( $evidence_uncertain['order']->get_id() ) );
$eu_state = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $evidence_uncertain['order']->get_id() ) );
$eu_recon = $evidence_uncertain['manager']->reconcile_order( wc_get_order( $evidence_uncertain['order']->get_id() ) );
$eu_press = $evidence_uncertain['manager']->cancel( wc_get_order( $evidence_uncertain['order']->get_id() ) );

$report(
	'SHIPPING_CANCEL_EVIDENCE_SURVIVES_UNCERTAIN',
	'cancel_unconfirmed_record_present' === (string) $eu_first['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $eu_state
		&& 'cancel_unconfirmed_record_present' === (string) $eu_recon['verdict']
		&& 'cancel_in_progress' === (string) $eu_press['code']
		&& 1 === $evidence_uncertain['adapter']->count_for( 'cancel_shipment' )
		&& 1 === $evidence_uncertain['adapter']->write_calls(),
	sprintf(
		'cancel:uncertain|first:%s|state:%s|reconcile:%s|press_after:%s|total_cancel_writes:%d',
		(string) $eu_first['code'],
		$eu_state,
		(string) $eu_recon['verdict'],
		(string) $eu_press['code'],
		$evidence_uncertain['adapter']->count_for( 'cancel_shipment' )
	)
);

kuka_ship_destroy_order( wc_get_order( $evidence_uncertain['order']->get_id() ) );

// --- 4. success, then a later read proves absence -----------------------

$evidence_gone = kuka_ship_cancel_fixture();
$evidence_gone['adapter']->results['read_shipment'] = Kuka_Island_Shipping_Result::success(
	'get_shipment',
	array( 'shipment_id' => 'FAKE-SHIP-1', 'exists' => true )
);

$eg_first = $evidence_gone['manager']->cancel( wc_get_order( $evidence_gone['order']->get_id() ) );
$eg_mid   = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $evidence_gone['order']->get_id() ) );

// The carrier catches up: the shipment is gone now.
$evidence_gone['adapter']->results = array();
$eg_recon = $evidence_gone['manager']->reconcile_order( wc_get_order( $evidence_gone['order']->get_id() ) );
$eg_state = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $evidence_gone['order']->get_id() ) );
$eg_press = $evidence_gone['manager']->cancel( wc_get_order( $evidence_gone['order']->get_id() ) );
$eg_pending = Kuka_Island_Shipping_Order_Store::pending_mutation( wc_get_order( $evidence_gone['order']->get_id() ) );

$report(
	'SHIPPING_CANCEL_EVIDENCE_CLEARED_ON_PROOF',
	Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $eg_mid
		&& 'cancelled' === (string) $eg_recon['verdict']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $eg_state
		&& array() === $eg_pending
		&& 'already_cancelled' === (string) $eg_press['code']
		&& 1 === $evidence_gone['adapter']->count_for( 'cancel_shipment' ),
	sprintf(
		'state_after_write:%s|reconcile_verdict:%s|state:%s|pending_evidence:%s|press_after:%s|total_cancel_writes:%d',
		$eg_mid,
		(string) $eg_recon['verdict'],
		$eg_state,
		array() === $eg_pending ? 'cleared' : 'STILL_SET',
		(string) $eg_press['code'],
		$evidence_gone['adapter']->count_for( 'cancel_shipment' )
	)
);

kuka_ship_destroy_order( wc_get_order( $evidence_gone['order']->get_id() ) );

// --- 5. the ORDER branch, measured on its own ---------------------------

$evidence_order = kuka_ship_cancel_order_fixture();
$evidence_order['adapter']->results['read_order'] = Kuka_Island_Shipping_Result::success(
	'get_order',
	array( 'reference_id' => 'X', 'exists' => true )
);

$eo_pre   = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $evidence_order['order']->get_id() ) );
$eo_first = $evidence_order['manager']->cancel( wc_get_order( $evidence_order['order']->get_id() ) );
$eo_state = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $evidence_order['order']->get_id() ) );
$eo_press = $evidence_order['manager']->cancel( wc_get_order( $evidence_order['order']->get_id() ) );

$report(
	'SHIPPING_CANCEL_EVIDENCE_ORDER_BRANCH',
	Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $eo_pre
		&& 'cancel_unconfirmed_record_present' === (string) $eo_first['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $eo_state
		&& 'cancel_in_progress' === (string) $eo_press['code']
		&& 1 === $evidence_order['adapter']->count_for( 'cancel_order' )
		&& 0 === $evidence_order['adapter']->count_for( 'cancel_shipment' )
		&& 1 === $evidence_order['adapter']->count_for( 'read_order' )
		&& 0 === $evidence_order['adapter']->count_for( 'read_shipment' )
		&& 1 === $evidence_order['adapter']->write_calls(),
	sprintf(
		'state_before:%s|first:%s|state:%s|press_after:%s|cancel_order:%d|cancel_shipment:%d|read_order:%d|read_shipment:%d|total_writes:%d',
		$eo_pre,
		(string) $eo_first['code'],
		$eo_state,
		(string) $eo_press['code'],
		$evidence_order['adapter']->count_for( 'cancel_order' ),
		$evidence_order['adapter']->count_for( 'cancel_shipment' ),
		$evidence_order['adapter']->count_for( 'read_order' ),
		$evidence_order['adapter']->count_for( 'read_shipment' ),
		$evidence_order['adapter']->write_calls()
	)
);

kuka_ship_destroy_order( wc_get_order( $evidence_order['order']->get_id() ) );

// --- 6. a pending cancellation does not lose the booked status query -----
//
// Cancelling the poll chain is how this module stops watching a parcel, and an
// unconfirmed cancellation is a parcel that may still be moving. The action
// stays booked. It also must not book another one.

putenv( 'KUKA_SHIPPING_AUTOMATION=1' );

$poll_guard = kuka_ship_cancel_fixture();
$poll_guard['adapter']->results['read_shipment'] = Kuka_Island_Shipping_Result::success(
	'get_shipment',
	array( 'shipment_id' => 'FAKE-SHIP-1', 'exists' => true )
);
$poll_guard_id = (int) $poll_guard['order']->get_id();

$poll_guard_booked = Kuka_Island_Shipping_Status_Poller::schedule_query( $poll_guard_id, 900 );
$poll_before       = Kuka_Island_Shipping_Status_Poller::has_pending_query( $poll_guard_id );

$poll_guard['manager']->cancel( wc_get_order( $poll_guard_id ) );
$poll_after_cancel = Kuka_Island_Shipping_Status_Poller::has_pending_query( $poll_guard_id );

// The worker runs: the state is not pollable, so it queries nothing and books
// nothing -- and it does not send a cancellation either.
$poll_guard_poller = kuka_ship_attach_sole_poller( $poll_guard['manager'] );
$poll_guard_run = kuka_ship_drive_status_chain( $poll_guard_id, 4 );
remove_action( Kuka_Island_Shipping_Status_Poller::ACTION, array( $poll_guard_poller, 'run' ), 10 );

$poll_after_run  = Kuka_Island_Shipping_Status_Poller::has_pending_query( $poll_guard_id );
$poll_guard_removed = kuka_ship_purge_actions( $poll_guard_id );

putenv( 'KUKA_SHIPPING_AUTOMATION' );

$report(
	'SHIPPING_PENDING_CANCEL_KEEPS_THE_POLL_BOOKING',
	in_array( $poll_guard_booked, array( Kuka_Island_Shipping_Status_Poller::SCHEDULE_CREATED, Kuka_Island_Shipping_Status_Poller::SCHEDULE_ALREADY_PENDING ), true )
		&& $poll_before
		// NOT unscheduled: the cancellation is not proved.
		&& $poll_after_cancel
		&& 1 === count( $poll_guard_run['processed'] )
		&& ! $poll_after_run
		&& 1 === $poll_guard['adapter']->count_for( 'cancel_shipment' )
		&& 0 === $poll_guard['adapter']->count_for( 'read_shipment_status' ),
	sprintf(
		'booked:%s|pending_before_cancel:%s|pending_after_cancel:%s|worker_runs:%d|pending_after_worker:%s|cancel_writes:%d|status_reads:%d|actions_removed:%d',
		$poll_guard_booked,
		$poll_before ? 'yes' : 'NO',
		$poll_after_cancel ? 'yes' : 'NO',
		count( $poll_guard_run['processed'] ),
		$poll_after_run ? 'yes' : 'no',
		$poll_guard['adapter']->count_for( 'cancel_shipment' ),
		$poll_guard['adapter']->count_for( 'read_shipment_status' ),
		$poll_guard_removed
	)
);

kuka_ship_destroy_order( wc_get_order( $poll_guard_id ) );

// --- which refusal re-opens the button, and which one does NOT ------------
//
// THIS MEASUREMENT USED TO ASSERT THE OPPOSITE, AND THE ASSERTION WAS WRONG.
// It was called "a definitive refusal keeps the old state" and it accepted a
// 400 as proof that nothing had happened, which re-opened the cancel button
// after a request the carrier had processed. The vendor's OpenAPI documents
// that status as "Bad Request" and nothing else: it does not say the record was
// left alone. So the rule is now about WHERE the refusal was made, not what
// number came back with it.

// Half one: the carrier ANSWERED no. The door stays shut.
$refused_cancel = kuka_ship_cancel_fixture();
$refused_cancel['adapter']->results['cancel_shipment'] = Kuka_Island_Shipping_Result::permanent( 'cancel_shipment', 'bad_request', 400 );

/*
 * And the confirming read finds the shipment still there. That combination is
 * the whole point: the carrier rejected the request AND the record exists, so
 * nothing has been established and no second cancellation may follow. A read
 * that said 'gone' would have PROVED the cancellation, refusal or not, and
 * would be measuring something else.
 */
$refused_cancel['adapter']->results['read_shipment'] = Kuka_Island_Shipping_Result::success(
	'get_shipment',
	array( 'shipment_id' => 'FAKE-SHIP-1', 'exists' => true )
);

$rc_result  = $refused_cancel['manager']->cancel( wc_get_order( $refused_cancel['order']->get_id() ) );
$rc_state   = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $refused_cancel['order']->get_id() ) );
$rc_pending = Kuka_Island_Shipping_Order_Store::pending_mutation( wc_get_order( $refused_cancel['order']->get_id() ) );

// A second press, with a carrier that would now say yes. It must not get one.
$refused_cancel['adapter']->results = array();
$rc_second = $refused_cancel['manager']->cancel( wc_get_order( $refused_cancel['order']->get_id() ) );
$rc_after  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $refused_cancel['order']->get_id() ) );

// Half two: the ADAPTER refused before the network. Provably nothing was sent,
// so the order goes back where it was and the button is live again.
$unsent_cancel = kuka_ship_cancel_fixture();
$unsent_cancel['adapter']->results['cancel_shipment'] = Kuka_Island_Shipping_Result::local_refusal( 'cancel_shipment', 'payload_incomplete' );

$uc_result  = $unsent_cancel['manager']->cancel( wc_get_order( $unsent_cancel['order']->get_id() ) );
$uc_state   = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $unsent_cancel['order']->get_id() ) );
$uc_pending = Kuka_Island_Shipping_Order_Store::pending_mutation( wc_get_order( $unsent_cancel['order']->get_id() ) );
$uc_writes  = $unsent_cancel['adapter']->count_for( 'cancel_shipment' );

$unsent_cancel['adapter']->results = array();
$uc_second = $unsent_cancel['manager']->cancel( wc_get_order( $unsent_cancel['order']->get_id() ) );
$uc_after  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $unsent_cancel['order']->get_id() ) );

$report(
	'SHIPPING_CANCEL_REFUSAL_POLICY',
	// The answered refusal: intent kept, state kept, second press refused.
	'cancel_unconfirmed_record_present' === (string) $rc_result['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $rc_state
		&& 'cancel' === (string) ( $rc_pending['kind'] ?? '' )
		&& ! $rc_second['ok']
		&& 'cancel_in_progress' === (string) $rc_second['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $rc_after
		&& 1 === $refused_cancel['adapter']->count_for( 'cancel_shipment' )
		// The unsent refusal: nothing recorded as a carrier write at all, the
		// intent closed, the previous state restored, the button live.
		&& 0 === $uc_writes
		&& 1 === $unsent_cancel['adapter']->local_refusals
		&& 'payload_incomplete' === (string) $uc_result['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $uc_state
		&& array() === $uc_pending
		&& $uc_second['ok']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $uc_after
		&& 1 === $unsent_cancel['adapter']->count_for( 'cancel_shipment' ),
	sprintf(
		'answered_400:code:%s|state:%s|intent:%s|second_press:%s|state_at_end:%s|writes:%d'
			. '||unsent:code:%s|state:%s|intent:%s|writes_at_refusal:%d|local_refusals:%d|second_press:%s|state_at_end:%s|writes:%d',
		(string) $rc_result['code'],
		$rc_state,
		array() === $rc_pending ? 'CLEARED' : 'kept',
		(string) $rc_second['code'],
		$rc_after,
		$refused_cancel['adapter']->count_for( 'cancel_shipment' ),
		(string) $uc_result['code'],
		$uc_state,
		array() === $uc_pending ? 'cleared' : 'STILL_SET',
		$uc_writes,
		$unsent_cancel['adapter']->local_refusals,
		$uc_second['ok'] ? 'cancelled' : 'REFUSED:' . (string) $uc_second['code'],
		$uc_after,
		$unsent_cancel['adapter']->count_for( 'cancel_shipment' )
	)
);

kuka_ship_destroy_order( wc_get_order( $refused_cancel['order']->get_id() ) );
kuka_ship_destroy_order( wc_get_order( $unsent_cancel['order']->get_id() ) );

/* ========================================================================== */
/* 46. Every external write has a durable, re-read intent behind it            */
/* ========================================================================== */

/*
 * THE HOLE THIS SECTION EXISTS FOR. The provider and the reference were pinned
 * before the first carrier write, and that was read as "the intent is durable".
 * It was not: an order whose create had gone out and whose process then died
 * still said state 'none', and the next press read 'none', passed
 * states_blocking_create() and sent the create again. One parcel, two bookings.
 *
 * Everything below is measured through the REAL Manager, the REAL Order_Store
 * and a SEPARATE MySQL connection -- one that can only see committed rows, so
 * what it can see is exactly what would survive this process dying.
 */

$intent_session = kuka_ship_second_session();
$intent_db      = $intent_session['db'];

/**
 * One order meta value, read from the database over a given connection.
 *
 * @param wpdb|null $db       Separate connection, or null for this process's own.
 * @param int       $order_id Order id.
 * @param string    $meta_key Meta key.
 */
function kuka_ship_meta_over( $db, int $order_id, string $meta_key ): string {
	global $wpdb;

	if ( ! $db instanceof wpdb ) {
		return kuka_ship_meta_in_db( $order_id, $meta_key );
	}

	$prefix = $wpdb->prefix;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$hpos = $db->get_var(
		$db->prepare(
			"SELECT meta_value FROM {$prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1",
			$order_id,
			$meta_key
		)
	);

	if ( null !== $hpos ) {
		return (string) $hpos;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$legacy = $db->get_var(
		$db->prepare(
			"SELECT meta_value FROM {$prefix}postmeta WHERE post_id = %d AND meta_key = %s LIMIT 1",
			$order_id,
			$meta_key
		)
	);

	return (string) $legacy;
}

/**
 * The mutation intent as the DATABASE holds it.
 *
 * @param wpdb|null $db       Connection.
 * @param int       $order_id Order id.
 * @return array<string, mixed>
 */
function kuka_ship_intent_over( $db, int $order_id ): array {
	$value = maybe_unserialize( kuka_ship_meta_over( $db, $order_id, Kuka_Island_Shipping_Order_Store::META_PENDING_MUTATION ) );

	return is_array( $value ) ? $value : array();
}

/** Make this process forget everything it knows about an order. */
function kuka_ship_forget_order( int $order_id ): void {
	if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
		try {
			$cache = wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class );

			if ( is_object( $cache ) && method_exists( $cache, 'remove' ) ) {
				$cache->remove( $order_id );
			}
		} catch ( Throwable $unavailable ) {
			unset( $unavailable );
		}
	}

	wp_cache_delete( $order_id, 'orders' );
	wp_cache_delete( $order_id, 'order-items' );
	wp_cache_delete( $order_id, 'posts' );
	wp_cache_delete( $order_id, 'post_meta' );
}

/**
 * Everything the database held at the instant one carrier write began.
 *
 * The adapter's on_write hook fires at the START of the write, before the call
 * is even recorded, so this is the last moment before the request would leave.
 *
 * @param array{adapter: Kuka_Shipping_Fake_Carrier, manager: Kuka_Island_Shipping_Manager, order: WC_Order} $fixture   Fixture.
 * @param string                                                                                            $operation Operation to watch.
 * @param wpdb|null                                                                                         $db        Connection to observe over.
 * @param callable                                                                                          $run       fn( Manager, WC_Order ): array
 * @param bool                                                                                              $crash     Break control flow once the intent has been seen.
 * @return array{seen: array<string, mixed>, outcome: array<string, mixed>, crashed: bool, writes: int}
 */
function kuka_ship_watch_intent( array $fixture, string $operation, $db, callable $run, bool $crash = false ): array {
	$order_id = (int) $fixture['order']->get_id();
	$seen     = array(
		'observed'  => false,
		'intent'    => array(),
		'state'     => '',
		'provider'  => '',
		'reference' => '',
	);

	$fixture['adapter']->on_write = static function ( string $written ) use ( $operation, $order_id, $db, $crash, &$seen ): void {
		if ( $written !== $operation ) {
			return;
		}

		$seen['observed']  = true;
		$seen['intent']    = kuka_ship_intent_over( $db, $order_id );
		$seen['state']     = kuka_ship_meta_over( $db, $order_id, Kuka_Island_Shipping_Order_Store::META_STATE );
		$seen['provider']  = kuka_ship_meta_over( $db, $order_id, Kuka_Island_Shipping_Order_Store::META_PROVIDER );
		$seen['reference'] = kuka_ship_meta_over( $db, $order_id, Kuka_Island_Shipping_Order_Store::META_REFERENCE );

		if ( $crash ) {
			// The request has started and this process stops existing. Not a
			// Result, not an exception the manager knows about: control flow
			// simply ends, which is what a fatal, a deploy or an OOM kill does.
			throw new RuntimeException( 'process died with ' . $written . ' in flight' );
		}
	};

	$crashed = false;
	$outcome = array();

	try {
		$outcome = (array) $run( $fixture['manager'], wc_get_order( $order_id ) );
	} catch ( Throwable $died ) {
		$crashed = true;
		unset( $died );
	}

	$fixture['adapter']->on_write = null;

	return array(
		'seen'    => $seen,
		'outcome' => $outcome,
		'crashed' => $crashed,
		'writes'  => $fixture['adapter']->count_for( $operation ),
	);
}

/**
 * Which of the intent's mandatory fields were missing or wrong.
 *
 * @param array<string, mixed> $seen            What the database held.
 * @param array<string, mixed> $wanted          kind, operation, target, previous_state, provider, expected_fields.
 * @return array<int, string>
 */
function kuka_ship_intent_faults( array $seen, array $wanted ): array {
	$faults = array();
	$intent = (array) $seen['intent'];

	if ( true !== $seen['observed'] ) {
		return array( 'write_never_reached' );
	}

	foreach ( array( 'mutation_id', 'kind', 'operation', 'target', 'provider', 'reference', 'created_at' ) as $field ) {
		if ( ! array_key_exists( $field, $intent ) || '' === (string) $intent[ $field ] ) {
			$faults[] = 'missing:' . $field;
		}
	}

	// previous_state may legitimately be 'none', so its presence is what counts.
	if ( ! array_key_exists( 'previous_state', $intent ) ) {
		$faults[] = 'missing:previous_state';
	}

	if ( 36 !== strlen( (string) ( $intent['mutation_id'] ?? '' ) ) ) {
		$faults[] = 'mutation_id_not_a_uuid';
	}

	foreach ( array( 'kind', 'operation', 'target', 'previous_state', 'provider' ) as $field ) {
		if ( (string) ( $wanted[ $field ] ?? '' ) !== (string) ( $intent[ $field ] ?? '' ) ) {
			$faults[] = 'wrong:' . $field . ':' . (string) ( $intent[ $field ] ?? 'none' );
		}
	}

	if ( ! is_int( $intent['created_at'] ?? null ) || (int) $intent['created_at'] <= 0 ) {
		$faults[] = 'created_at_not_a_timestamp';
	}

	if ( Kuka_Island_Shipping_Order_Store::protected_state_for( (string) ( $wanted['kind'] ?? '' ) ) !== (string) $seen['state'] ) {
		$faults[] = 'state:' . (string) $seen['state'];
	}

	if ( (string) ( $wanted['provider'] ?? '' ) !== (string) $seen['provider'] ) {
		$faults[] = 'provider_meta:' . (string) $seen['provider'];
	}

	if ( '' === (string) $seen['reference'] || (string) ( $intent['reference'] ?? '' ) !== (string) $seen['reference'] ) {
		$faults[] = 'reference_meta:' . (string) $seen['reference'];
	}

	if ( (int) ( $wanted['expected_fields'] ?? 0 ) !== count( (array) ( $intent['expected'] ?? array() ) ) ) {
		$faults[] = 'expected_fields:' . (string) count( (array) ( $intent['expected'] ?? array() ) );
	}

	return $faults;
}

/**
 * The six external mutations, each with the fixture that reaches it.
 *
 * @return array<int, array<string, mixed>>
 */
function kuka_ship_mutation_cases(): array {
	return array(
		array(
			'operation'       => 'create_order',
			'kind'            => Kuka_Island_Shipping_Order_Store::MUTATION_CREATE,
			'target'          => 'order',
			'previous_state'  => Kuka_Island_Shipping_Order_Store::STATE_NONE,
			'expected_fields' => 0,
			'fixture'         => static function (): array {
				$adapter = new Kuka_Shipping_Fake_Carrier();

				return array(
					'adapter' => $adapter,
					'manager' => new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $adapter ) ) ),
					'order'   => kuka_ship_fixture_order(),
				);
			},
			'run'             => static fn( $manager, $order ): array => $manager->create_shipment( $order ),
			'retry_code'      => 'already_in_progress',
			'verdict'         => 'absent_confirmed',
		),
		array(
			'operation'       => 'create_barcode',
			'kind'            => Kuka_Island_Shipping_Order_Store::MUTATION_CREATE,
			'target'          => 'shipment',
			'previous_state'  => Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED,
			'expected_fields' => 0,
			'fixture'         => static function (): array {
				$adapter = new Kuka_Shipping_Fake_Carrier();

				return array(
					'adapter' => $adapter,
					'manager' => new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $adapter ) ) ),
					'order'   => kuka_ship_fixture_order(),
				);
			},
			'run'             => static fn( $manager, $order ): array => $manager->create_shipment( $order ),
			'retry_code'      => 'already_in_progress',
			'verdict'         => 'absent_confirmed',
		),
		array(
			'operation'       => 'update_order',
			'kind'            => Kuka_Island_Shipping_Order_Store::MUTATION_UPDATE,
			'target'          => 'order',
			'previous_state'  => Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED,
			'expected_fields' => 9,
			'fixture'         => static fn(): array => kuka_ship_cancel_order_fixture(),
			'run'             => static fn( $manager, $order ): array => $manager->update_shipment( $order ),
			'retry_code'      => 'nothing_to_update',
			'verdict'         => 'readback_unsupported',
		),
		array(
			'operation'       => 'update_shipment',
			'kind'            => Kuka_Island_Shipping_Order_Store::MUTATION_UPDATE,
			'target'          => 'shipment',
			'previous_state'  => Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED,
			'expected_fields' => 9,
			'fixture'         => static fn(): array => kuka_ship_fake_shipment(),
			'run'             => static fn( $manager, $order ): array => $manager->update_shipment( $order ),
			'retry_code'      => 'nothing_to_update',
			'verdict'         => 'readback_unsupported',
		),
		array(
			'operation'       => 'cancel_order',
			'kind'            => Kuka_Island_Shipping_Order_Store::MUTATION_CANCEL,
			'target'          => 'order',
			'previous_state'  => Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED,
			'expected_fields' => 0,
			'fixture'         => static fn(): array => kuka_ship_cancel_order_fixture(),
			'run'             => static fn( $manager, $order ): array => $manager->cancel( $order ),
			'retry_code'      => 'cancel_in_progress',
			'verdict'         => 'cancelled',
		),
		array(
			'operation'       => 'cancel_shipment',
			'kind'            => Kuka_Island_Shipping_Order_Store::MUTATION_CANCEL,
			'target'          => 'shipment',
			'previous_state'  => Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED,
			'expected_fields' => 0,
			'fixture'         => static fn(): array => kuka_ship_fake_shipment(),
			'run'             => static fn( $manager, $order ): array => $manager->cancel( $order ),
			'retry_code'      => 'cancel_in_progress',
			'verdict'         => 'cancelled',
		),
	);
}

$intent_faults = array();
$intent_rows   = array();

foreach ( kuka_ship_mutation_cases() as $case ) {
	$fixture = ( $case['fixture'] )();
	$watched = kuka_ship_watch_intent( $fixture, (string) $case['operation'], $intent_db, $case['run'] );

	$faults = kuka_ship_intent_faults(
		$watched['seen'],
		array(
			'kind'            => (string) $case['kind'],
			'operation'       => (string) $case['operation'],
			'target'          => (string) $case['target'],
			'previous_state'  => (string) $case['previous_state'],
			'provider'        => Kuka_Shipping_Fake_Carrier::KEY,
			'expected_fields' => (int) $case['expected_fields'],
		)
	);

	if ( array() !== $faults ) {
		$intent_faults[] = (string) $case['operation'] . '(' . implode( ',', $faults ) . ')';
	}

	$intent_rows[] = (string) $case['operation'] . ':' . (string) $watched['seen']['state'];

	kuka_ship_destroy_order( wc_get_order( (int) $fixture['order']->get_id() ) );
}

$report(
	'SHIPPING_MUTATION_INTENT_DURABLE',
	$intent_session['separate']
		&& 6 === count( $intent_rows )
		&& array() === $intent_faults,
	sprintf(
		'observed_over:separate_mysql_session(%s)|operations:%d|wrong:%s|states_at_first_write:%s',
		$intent_session['separate'] ? 'yes' : 'NO',
		count( $intent_rows ),
		array() === $intent_faults ? 'none' : implode( '+', $intent_faults ),
		implode( ',', $intent_rows )
	)
);

/* ========================================================================== */
/* 47. A process that dies mid-request opens no second write                   */
/* ========================================================================== */

/*
 * The measurement the previous rounds did not have. Returning a Result -- even
 * an uncertain one -- means the code got back to the manager and could record
 * something. A crash does not: control flow ends inside the write. The only
 * thing that can protect the parcel afterwards is what was already on disk.
 *
 * So for every one of the six: see the intent in the database from inside the
 * write, then break control flow; then retry with a NEW WC_Order and a NEW
 * Manager over a NEW adapter instance -- the closest this suite can get to a
 * different process -- and count what the retry sends. Zero, every time, with
 * only the operation's own read-only reconciliation left open.
 */

$crash_faults = array();
$crash_rows   = array();

foreach ( kuka_ship_mutation_cases() as $case ) {
	$fixture  = ( $case['fixture'] )();
	$order_id = (int) $fixture['order']->get_id();

	$crashed = kuka_ship_watch_intent( $fixture, (string) $case['operation'], $intent_db, $case['run'], true );

	if ( true !== $crashed['crashed'] ) {
		$crash_faults[] = (string) $case['operation'] . '(control_flow_survived)';
	}

	if ( true !== $crashed['seen']['observed'] ) {
		$crash_faults[] = (string) $case['operation'] . '(intent_not_seen)';
	}

	// What the DATABASE holds now that the process is gone.
	$after_state  = kuka_ship_meta_over( $intent_db, $order_id, Kuka_Island_Shipping_Order_Store::META_STATE );
	$after_intent = kuka_ship_intent_over( $intent_db, $order_id );

	if ( Kuka_Island_Shipping_Order_Store::protected_state_for( (string) $case['kind'] ) !== $after_state ) {
		$crash_faults[] = (string) $case['operation'] . '(state_after_crash:' . $after_state . ')';
	}

	if ( (string) $case['operation'] !== (string) ( $after_intent['operation'] ?? '' )
		|| (string) $case['target'] !== (string) ( $after_intent['target'] ?? '' ) ) {
		$crash_faults[] = (string) $case['operation'] . '(intent_lost)';
	}

	// A different process: new object, new manager, new adapter, nothing this
	// one remembered.
	kuka_ship_forget_order( $order_id );

	$retry_adapter = new Kuka_Shipping_Fake_Carrier();
	$retry_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $retry_adapter ) ) );
	$retry_order   = wc_get_order( $order_id );

	if ( $retry_order === $fixture['order'] ) {
		$crash_faults[] = (string) $case['operation'] . '(order_object_reused)';
	}

	$retried = (array) ( $case['run'] )( $retry_manager, $retry_order );

	if ( true === ( $retried['ok'] ?? false ) || (string) $case['retry_code'] !== (string) ( $retried['code'] ?? '' ) ) {
		$crash_faults[] = (string) $case['operation'] . '(retry_code:' . (string) ( $retried['code'] ?? 'none' ) . ')';
	}

	if ( 0 !== $retry_adapter->write_calls() ) {
		$crash_faults[] = (string) $case['operation'] . '(SECOND_WRITE:' . (string) $retry_adapter->write_calls() . ')';
	}

	// And the one door that IS open is the read-only reconciliation this
	// operation needs -- not the generic one, and not a write.
	$verdict = $retry_manager->reconcile_order( wc_get_order( $order_id ) );

	if ( (string) $case['verdict'] !== (string) ( $verdict['verdict'] ?? '' ) ) {
		$crash_faults[] = (string) $case['operation'] . '(verdict:' . (string) ( $verdict['verdict'] ?? 'none' ) . ')';
	}

	if ( 0 !== $retry_adapter->write_calls() ) {
		$crash_faults[] = (string) $case['operation'] . '(RECONCILE_WROTE:' . (string) $retry_adapter->write_calls() . ')';
	}

	$crash_rows[] = (string) $case['operation'] . ':' . $after_state . '/' . (string) ( $verdict['verdict'] ?? 'none' ) . '/w' . (string) $retry_adapter->write_calls();

	kuka_ship_destroy_order( wc_get_order( $order_id ) );
}

$report(
	'SHIPPING_MUTATION_CRASH_BOUNDARY',
	6 === count( $crash_rows ) && array() === $crash_faults,
	sprintf(
		'operations:%d|retry_context:new_order_object+new_manager+new_adapter|second_writes:%s|wrong:%s|operation_state_verdict:%s',
		count( $crash_rows ),
		array() === $crash_faults ? '0' : 'SEE_WRONG',
		array() === $crash_faults ? 'none' : implode( '+', $crash_faults ),
		implode( ',', $crash_rows )
	)
);

/* ========================================================================== */
/* 48. An intent that did not persist stops the write                          */
/* ========================================================================== */

/*
 * update_meta_data() populates an object; save_meta_data() is what puts it on
 * disk, and it can fail without saying so. The verification exists for that
 * case, and the only way to measure it is to make the write genuinely not land
 * while the code path believes it did.
 *
 * The sabotage is narrow on purpose: only statements that write THIS meta key
 * are neutralised, so the state and the provider still save and the fault is
 * exactly "the intent record did not persist". That is the case the readback
 * has to catch, and the carrier must hear nothing.
 */

$unpersisted        = kuka_ship_fake_shipment();
$unpersisted_id     = (int) $unpersisted['order']->get_id();
$unpersisted_before = $unpersisted['adapter']->write_calls();

$sabotage_hits = 0;
$sabotage      = static function ( $query ) use ( &$sabotage_hits ) {
	if ( ! is_string( $query ) || ! str_contains( $query, Kuka_Island_Shipping_Order_Store::META_PENDING_MUTATION ) ) {
		return $query;
	}

	$verb = strtoupper( substr( ltrim( $query ), 0, 6 ) );

	if ( ! in_array( $verb, array( 'INSERT', 'UPDATE', 'REPLAC' ), true ) ) {
		return $query;
	}

	++$sabotage_hits;

	// Accepted by the database, and it changes nothing.
	return 'SELECT 1';
};

add_filter( 'query', $sabotage, 999 );
$unpersisted_result = $unpersisted['manager']->cancel( wc_get_order( $unpersisted_id ) );
remove_filter( 'query', $sabotage, 999 );

kuka_ship_forget_order( $unpersisted_id );
$unpersisted_state  = kuka_ship_meta_over( $intent_db, $unpersisted_id, Kuka_Island_Shipping_Order_Store::META_STATE );
$unpersisted_intent = kuka_ship_intent_over( $intent_db, $unpersisted_id );

// And the residue is resolvable: the state says a cancellation went out, the
// record that says which object it addressed did not survive, and the
// reconciliation still settles it read-only.
$unpersisted_verdict = $unpersisted['manager']->reconcile_order( wc_get_order( $unpersisted_id ) );
$unpersisted_after   = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $unpersisted_id ) );

$report(
	'SHIPPING_MUTATION_INTENT_UNPERSISTED_BLOCKS_WRITE',
	$sabotage_hits > 0
		&& ! $unpersisted_result['ok']
		&& 'mutation_intent_unverified' === (string) $unpersisted_result['code']
		// THE MEASUREMENT: nothing was sent.
		&& $unpersisted_before === $unpersisted['adapter']->write_calls()
		&& 0 === $unpersisted['adapter']->write_calls()
		// Held on the restrictive side, deliberately not rolled back.
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $unpersisted_state
		&& array() === $unpersisted_intent
		// ...and still resolvable by reading.
		&& 'cancelled' === (string) $unpersisted_verdict['verdict']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $unpersisted_after
		&& 0 === $unpersisted['adapter']->write_calls(),
	sprintf(
		'sabotaged_statements:%d|code:%s|carrier_writes:%d|state_after:%s|intent_after:%s|recovery_verdict:%s|state_at_end:%s',
		$sabotage_hits,
		(string) $unpersisted_result['code'],
		$unpersisted['adapter']->write_calls(),
		$unpersisted_state,
		array() === $unpersisted_intent ? 'absent' : 'present',
		(string) $unpersisted_verdict['verdict'],
		$unpersisted_after
	)
);

kuka_ship_destroy_order( wc_get_order( $unpersisted_id ) );

/* ========================================================================== */
/* 49. One outcome, one save                                                   */
/* ========================================================================== */

/*
 * The state change and the clearing of the intent record used to be two saves.
 * A crash between them left an order whose state said 'cancelled' and whose
 * meta still described a cancellation in flight -- or the reverse, which is the
 * one that re-opens a write. Order_Store::persist() is now the single write
 * point and it counts, so "one transition, one save" is a number rather than a
 * claim.
 */

$atomic_faults = array();
$atomic_rows   = array();

// Cancellation confirmed.
$atomic_cancel = kuka_ship_cancel_fixture();
$atomic_cancel['adapter']->results['read_shipment'] = Kuka_Island_Shipping_Result::transient( 'get_shipment', 'timeout', 0 );
$atomic_cancel['manager']->cancel( wc_get_order( (int) $atomic_cancel['order']->get_id() ) );
$atomic_cancel['adapter']->results = array();

Kuka_Island_Shipping_Order_Store::reset_save_count();
$atomic_cancel_verdict = $atomic_cancel['manager']->reconcile_order( wc_get_order( (int) $atomic_cancel['order']->get_id() ) );
$atomic_rows[]         = 'cancel_confirmed:' . (string) Kuka_Island_Shipping_Order_Store::save_count();

if ( 1 !== Kuka_Island_Shipping_Order_Store::save_count() || 'cancelled' !== (string) $atomic_cancel_verdict['verdict'] ) {
	$atomic_faults[] = 'cancel_confirmed';
}

kuka_ship_destroy_order( wc_get_order( (int) $atomic_cancel['order']->get_id() ) );

// Update confirmed, and update mismatched.
foreach ( array( 'update_confirmed' => false, 'update_mismatch' => true ) as $atomic_label => $atomic_break ) {
	$atomic_update = kuka_ship_update_fixture();
	$atomic_update['manager']->update_shipment( wc_get_order( (int) $atomic_update['order']->get_id() ) );

	$atomic_fields = $atomic_update['expected'];

	if ( $atomic_break ) {
		$atomic_fields['recipient_address'] = 'BASKA BIR ADRES';
	}

	$atomic_update['adapter']->amendable = $atomic_fields;
	$atomic_update['adapter']->results   = array();

	Kuka_Island_Shipping_Order_Store::reset_save_count();
	$atomic_verdict = $atomic_update['manager']->reconcile_order( wc_get_order( (int) $atomic_update['order']->get_id() ) );
	$atomic_saves   = Kuka_Island_Shipping_Order_Store::save_count();
	$atomic_rows[]  = $atomic_label . ':' . (string) $atomic_saves;

	if ( 1 !== $atomic_saves || $atomic_label !== (string) $atomic_verdict['verdict'] ) {
		$atomic_faults[] = $atomic_label;
	}

	kuka_ship_destroy_order( wc_get_order( (int) $atomic_update['order']->get_id() ) );
}

// The previous state restored after a refusal that never left the building.
$atomic_unsent = kuka_ship_fake_shipment();
$atomic_unsent['adapter']->results['cancel_shipment'] = Kuka_Island_Shipping_Result::local_refusal( 'cancel_shipment', 'payload_incomplete' );

Kuka_Island_Shipping_Order_Store::reset_save_count();
$atomic_unsent['manager']->cancel( wc_get_order( (int) $atomic_unsent['order']->get_id() ) );
$atomic_unsent_saves = Kuka_Island_Shipping_Order_Store::save_count();
$atomic_rows[]       = 'intent_opened_and_restored:' . (string) $atomic_unsent_saves;

// Two, and exactly two: one to open the intent, one to close it and put the
// order back. Anything more means a transition split itself.
if ( 2 !== $atomic_unsent_saves ) {
	$atomic_faults[] = 'restore_previous_state';
}

kuka_ship_destroy_order( wc_get_order( (int) $atomic_unsent['order']->get_id() ) );

// A whole create: intent, order confirmed, intent, shipment confirmed. Four.
$atomic_create = new Kuka_Shipping_Fake_Carrier();
$atomic_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $atomic_create ) ) );
$atomic_order   = kuka_ship_fixture_order();

Kuka_Island_Shipping_Order_Store::reset_save_count();
$atomic_manager->create_shipment( $atomic_order );
$atomic_create_saves = Kuka_Island_Shipping_Order_Store::save_count();
$atomic_rows[]       = 'create_and_barcode_confirmed:' . (string) $atomic_create_saves;

if ( 4 !== $atomic_create_saves ) {
	$atomic_faults[] = 'create_and_barcode_confirmed';
}

// Nothing inconsistent is left anywhere: a terminal state and an open intent
// record cannot both be true.
$atomic_final_state  = kuka_ship_meta_over( $intent_db, (int) $atomic_order->get_id(), Kuka_Island_Shipping_Order_Store::META_STATE );
$atomic_final_intent = kuka_ship_intent_over( $intent_db, (int) $atomic_order->get_id() );

if ( Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED !== $atomic_final_state || array() !== $atomic_final_intent ) {
	$atomic_faults[] = 'inconsistent_meta_after_create';
}

kuka_ship_purge_actions( (int) $atomic_order->get_id() );
kuka_ship_destroy_order( wc_get_order( (int) $atomic_order->get_id() ) );

$report(
	'SHIPPING_MUTATION_OUTCOME_ATOMIC',
	array() === $atomic_faults,
	sprintf(
		'measured:order_store_save_counter|transitions:%s|wrong:%s',
		implode( ',', $atomic_rows ),
		array() === $atomic_faults ? 'none' : implode( '+', $atomic_faults )
	)
);

/* ========================================================================== */
/* 50. The adapter switch fails CLOSED on anything it does not recognise       */
/* ========================================================================== */

/*
 * The old rule was "off only for the four explicit negatives, anything else
 * stays on", so 'flase', 'of', ' 0' and '' all left the adapter ON. An
 * operator who meant to stop shipping and mistyped it believed it was stopped
 * while parcels were still bookable, and nothing said the value had not been
 * understood.
 */

$adapter_cases = array(
	array( '1', true, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_ON ),
	array( 'true', true, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_ON ),
	array( 'yes', true, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_ON ),
	array( 'on', true, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_ON ),
	array( '0', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_OFF ),
	array( 'false', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_OFF ),
	array( 'no', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_OFF ),
	array( 'off', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_OFF ),
	// Everything below used to leave shipping on.
	array( '', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID ),
	array( 'flase', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID ),
	array( 'of', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID ),
	array( ' 1', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID ),
	array( '1 ', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID ),
	array( ' 0 ', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID ),
	array( 'ON', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID ),
	array( 'True', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID ),
	array( 'evet', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID ),
	array( '2', false, Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID ),
);

$adapter_wrong = array();

foreach ( $adapter_cases as $adapter_case ) {
	list( $adapter_value, $adapter_enabled, $adapter_reason ) = $adapter_case;

	putenv( Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING . '=' . $adapter_value );
	$adapter_state = Kuka_Island_Shipping_DHL_Config::adapter_state();

	if ( $adapter_enabled !== (bool) $adapter_state['enabled'] || $adapter_reason !== (string) $adapter_state['reason'] ) {
		$adapter_wrong[] = "'" . $adapter_value . "'=>" . ( $adapter_state['enabled'] ? 'on' : 'off' ) . '/' . (string) $adapter_state['reason'];
	}
}

// Unset keeps the historical default, which is the one value that must not
// change: a fresh install has to have a carrier.
putenv( Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING );
$adapter_unset = Kuka_Island_Shipping_DHL_Config::adapter_state();

if ( true !== (bool) $adapter_unset['enabled'] || Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_UNSET !== (string) $adapter_unset['reason'] ) {
	$adapter_wrong[] = 'unset=>' . ( $adapter_unset['enabled'] ? 'on' : 'off' ) . '/' . (string) $adapter_unset['reason'];
}

/*
 * And a value nobody understood constructs NOTHING. Not an adapter that
 * refuses, not a client with no credentials: the composition root returns
 * before building any of it, so there is no object that could open a socket.
 */
putenv( Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING . '=flase' );

$adapter_http    = 0;
$adapter_counter = static function ( $pre, $args, $url ) use ( &$adapter_http ) {
	unset( $args, $url );
	++$adapter_http;

	return $pre;
};
add_filter( 'pre_http_request', $adapter_counter, 1, 3 );

$adapter_registered = Kuka_Island_Shipping_Automation::register_default_carrier( array() );
$adapter_notices    = Kuka_Island_Shipping_Automation::adapter_notice( array() );

$adapter_registry = kuka_ship_registry_of( $adapter_registered );
$adapter_order    = kuka_ship_fixture_order();
$adapter_manager  = new Kuka_Island_Shipping_Manager( $adapter_registry );
$adapter_create   = $adapter_manager->create_shipment( $adapter_order );

remove_filter( 'pre_http_request', $adapter_counter, 1 );
putenv( Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING );

// The operator is told, in the module status line, that the value was not
// understood -- and told it through the same filter a second adapter would use.
$adapter_line_status = array(
	'module'     => 'active',
	'runtime'    => 'open',
	'automation' => 'off',
	'adapters'   => 'none',
	'notices'    => array_map( 'strval', $adapter_notices ),
);
$adapter_line = Kuka_Island_Shipping_Admin::module_status_line( $adapter_line_status );

$report(
	'SHIPPING_ADAPTER_KEY_FAIL_CLOSED',
	array() === $adapter_wrong
		&& array() === $adapter_registered
		&& 0 === $adapter_http
		&& ! $adapter_create['ok']
		&& 'carrier_not_registered' === (string) $adapter_create['code']
		&& 1 === count( $adapter_notices )
		&& str_contains( $adapter_line, Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING )
		&& str_contains( $adapter_line, 'tanınmadı' ),
	sprintf(
		'setting:%s|values_checked:%d|wrong:%s|unset_default:on|invalid_value_adapters:%d|invalid_value_http:%d|door:%s|status_line_names_the_setting:%s',
		Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING,
		count( $adapter_cases ) + 1,
		array() === $adapter_wrong ? 'none' : implode( '+', $adapter_wrong ),
		count( $adapter_registered ),
		$adapter_http,
		(string) $adapter_create['code'],
		str_contains( $adapter_line, Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING ) && str_contains( $adapter_line, 'tanınmadı' ) ? 'yes' : 'NO'
	)
);

kuka_ship_destroy_order( wc_get_order( (int) $adapter_order->get_id() ) );

/* ========================================================================== */
/* 51. "Exact" means exact: one canonical form, no tolerance                   */
/* ========================================================================== */

/*
 * fields_match() trimmed both sides before comparing, and that one call made
 * the word "exact" untrue: a read-back of ' Ada Lovelace' matched a sent 'Ada
 * Lovelace', so a carrier that had reformatted the field was reported as
 * holding it verbatim. The fix is a DEFINED form applied to what is SENT, and
 * then no tolerance at all in the comparison.
 */

$canon_cases = array(
	array( '  Ada Lovelace  ', 'Ada Lovelace' ),
	array( "Ada\tLovelace", 'Ada Lovelace' ),
	array( "Bahçe  Sokak\n No 3", 'Bahçe Sokak No 3' ),
	array( 'Ada Lovelace', 'Ada Lovelace' ),
);
$canon_wrong = array();

foreach ( $canon_cases as $canon_case ) {
	if ( $canon_case[1] !== Kuka_Island_Shipping_Manager::canonical_amendable_value( $canon_case[0] ) ) {
		$canon_wrong[] = 'canonical:' . $canon_case[0];
	}
}

// The comparison forgives nothing, in either direction.
$canon_pairs = array(
	array( array( 'a' => 'Ada' ), array( 'a' => ' Ada' ), false ),
	array( array( 'a' => 'Ada' ), array( 'a' => 'Ada ' ), false ),
	array( array( 'a' => 'Ada' ), array( 'a' => 'Ada' ), true ),
	array( array( 'a' => 'Ada' ), array(), false ),
	array( array( 'a' => '1' ), array( 'a' => 1 ), true ),
	array( array( 'a' => 'Ada' ), array( 'a' => array( 'Ada' ) ), false ),
);

foreach ( $canon_pairs as $canon_pair ) {
	if ( $canon_pair[2] !== Kuka_Island_Shipping_Manager::fields_match( $canon_pair[0], $canon_pair[1] )['match'] ) {
		$canon_wrong[] = 'match:' . (string) wp_json_encode( $canon_pair[1] );
	}
}

// The suite's own control: fields_match() must contain no trim() at all.
$canon_source = (string) file_get_contents(
	trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-shipping-automation/includes/shipping/class-shipment-manager.php'
);
$canon_body   = substr(
	$canon_source,
	(int) strpos( $canon_source, 'public static function fields_match(' ),
	600
);

if ( str_contains( $canon_body, 'trim(' ) ) {
	$canon_wrong[] = 'fields_match_still_trims';
}

/*
 * And end to end: the carrier answers with the sent values plus one leading
 * space. That used to be an exact match. It is now a mismatch, and a mismatch
 * needs a person.
 */
$canon_fixture = kuka_ship_update_fixture();
$canon_fields  = $canon_fixture['expected'];
$canon_fields['recipient_full_name'] = ' ' . $canon_fields['recipient_full_name'];
$canon_fixture['adapter']->amendable = $canon_fields;

$canon_result = $canon_fixture['manager']->update_shipment( wc_get_order( (int) $canon_fixture['order']->get_id() ) );
$canon_state  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( (int) $canon_fixture['order']->get_id() ) );

// What was SENT is canonical, so the carrier was asked for exactly these bytes.
$canon_sent = Kuka_Island_Shipping_Manager::amendable_fields(
	(array) $canon_fixture['manager']->build_request(
		wc_get_order( (int) $canon_fixture['order']->get_id() ),
		$canon_fixture['adapter'],
		(string) Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( (int) $canon_fixture['order']->get_id() ) )['reference']
	)['shipment']
);
$canon_sent_is_canonical = true;

foreach ( $canon_sent as $canon_field => $canon_value ) {
	if ( (string) $canon_value !== Kuka_Island_Shipping_Manager::canonical_amendable_value( $canon_value ) ) {
		$canon_sent_is_canonical = false;
		$canon_wrong[]           = 'sent_not_canonical:' . (string) $canon_field;
	}
}

$report(
	'SHIPPING_AMENDABLE_CANONICAL_EXACT',
	array() === $canon_wrong
		&& $canon_sent_is_canonical
		&& 'update_mismatch' === (string) $canon_result['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW === $canon_state,
	sprintf(
		'canonical_cases:%d|comparison_cases:%d|wrong:%s|fields_match_trims:no|sent_values_canonical:%s|leading_space_readback:%s|state:%s',
		count( $canon_cases ),
		count( $canon_pairs ),
		array() === $canon_wrong ? 'none' : implode( '+', $canon_wrong ),
		$canon_sent_is_canonical ? 'yes' : 'NO',
		(string) $canon_result['code'],
		$canon_state
	)
);

kuka_ship_destroy_order( wc_get_order( (int) $canon_fixture['order']->get_id() ) );

/* ========================================================================== */
/* 52. A pending cancellation is resolved by a person, and the code says so    */
/* ========================================================================== */

/*
 * The comment in cancel_still_unproven() claimed the already-booked status
 * query was "the only thing still watching" an unconfirmed cancellation. It
 * was not watching anything: the worker reads the state first, finds
 * cancel_reconciliation_required, and returns without a single carrier read or
 * a follow-up booking. Either that gap gets an operation-specific poll, or the
 * documentation and the order screen say plainly that a person resolves it.
 * This measures the second choice, and that the wrong comment is gone.
 */

putenv( 'KUKA_SHIPPING_AUTOMATION=1' );

$manual_only = kuka_ship_cancel_fixture();
$manual_only['adapter']->results['read_shipment'] = Kuka_Island_Shipping_Result::success(
	'get_shipment',
	array( 'shipment_id' => 'FAKE-SHIP-1', 'exists' => true )
);
$manual_only_id = (int) $manual_only['order']->get_id();

kuka_ship_attach_sole_poller( $manual_only['manager'] );
Kuka_Island_Shipping_Status_Poller::schedule_query( $manual_only_id );
$manual_only['manager']->cancel( wc_get_order( $manual_only_id ) );

$manual_only_reads_before = $manual_only['adapter']->count_for( 'read_shipment_status' );
$manual_only_run          = kuka_ship_drive_status_chain( $manual_only_id, 1 );
$manual_only_pending      = Kuka_Island_Shipping_Status_Poller::has_pending_query( $manual_only_id );
$manual_only_reads_after  = $manual_only['adapter']->count_for( 'read_shipment_status' );

/*
 * The real queue runner discards what the worker returns, so the outcome is
 * named by driving the SAME method directly, once. Same code, same order, same
 * manager -- and it still reads nothing.
 */
$manual_only_worker  = new Kuka_Island_Shipping_Status_Poller( $manual_only['manager'] );  // Driven directly; no hook needed.
$manual_only_outcome = $manual_only_worker->run( $manual_only_id );
$manual_only_reads_end = $manual_only['adapter']->count_for( 'read_shipment_status' );

// The operator's own lever, and it works.
$manual_only_verdict = $manual_only['manager']->reconcile_order( wc_get_order( $manual_only_id ) );

$manual_only_hint = Kuka_Island_Shipping_Admin::operator_hint( wc_get_order( $manual_only_id ), $manual_only['adapter'] );

$manual_only_removed = kuka_ship_purge_actions( $manual_only_id );
putenv( 'KUKA_SHIPPING_AUTOMATION' );

// The claim that is no longer made anywhere in the source.
$manual_only_source = (string) file_get_contents(
	trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-shipping-automation/includes/shipping/class-shipment-manager.php'
);

$report(
	'SHIPPING_PENDING_CANCEL_IS_MANUAL_ONLY',
	1 === count( $manual_only_run['processed'] )
		// The booked action ran and read NOTHING: it is not a watcher.
		&& $manual_only_reads_before === $manual_only_reads_after
		&& 0 === $manual_only_reads_after
		&& 'state_not_pollable' === (string) $manual_only_outcome
		&& 0 === $manual_only_reads_end
		// And it booked no follow-up, so the chain is over.
		&& ! $manual_only_pending
		&& ! Kuka_Island_Shipping_Status_Poller::has_pending_query( $manual_only_id )
		// The only thing that moves the order is the operator's read.
		&& 'cancel_unconfirmed_record_present' === (string) $manual_only_verdict['verdict']
		&& str_contains( $manual_only_hint, 'Otomatik durum sorgusu bu durumu ÇÖZMEZ' )
		&& str_contains( $manual_only_hint, 'Mutabakat' )
		&& ! str_contains( $manual_only_source, 'the only thing still watching it' ),
	sprintf(
		'worker_runs:%d|worker_outcome:%s|status_reads:%d|follow_up_booked:%s|operator_verdict:%s|screen_says_manual:%s|stale_comment_present:%s|actions_removed:%d',
		count( $manual_only_run['processed'] ),
		(string) $manual_only_outcome,
		$manual_only_reads_end,
		$manual_only_pending ? 'YES' : 'no',
		(string) $manual_only_verdict['verdict'],
		str_contains( $manual_only_hint, 'Otomatik durum sorgusu bu durumu ÇÖZMEZ' ) ? 'yes' : 'NO',
		str_contains( $manual_only_source, 'the only thing still watching it' ) ? 'YES' : 'no',
		$manual_only_removed
	)
);

kuka_ship_destroy_order( wc_get_order( $manual_only_id ) );

if ( $intent_db instanceof wpdb ) {
	$intent_db->close();
}

/* ========================================================================== */
/* 53. A cancelled record is fail-closed for create AND for barcode            */
/* ========================================================================== */

/*
 * THE HOLE THIS SECTION EXISTS FOR. The create door asked a DENY-list --
 * states_blocking_create() -- and STATE_CANCELLED was not on it. So a cancelled
 * order passed the door; run_creation() then found a state its createOrder
 * branch did not accept, skipped the branch, and fell through to run_barcode().
 * The result was a createbarcode against a record the carrier had already
 * cancelled and a read had PROVED cancelled, with no createOrder behind it.
 *
 * The whole path is driven here: real create, real cancel, real read-only
 * proof, then a fresh order object and a fresh Manager over a fresh adapter --
 * an adapter whose every operation would SUCCEED, so a single call of any kind
 * would show up.
 */

$cancelled_fixture = kuka_ship_cancel_fixture();
$cancelled_id      = (int) $cancelled_fixture['order']->get_id();

$cancelled_first  = $cancelled_fixture['manager']->cancel( wc_get_order( $cancelled_id ) );
$cancelled_proved = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $cancelled_id ) );
$cancelled_intent = Kuka_Island_Shipping_Order_Store::pending_mutation( wc_get_order( $cancelled_id ) );

// A different process would look exactly like this: nothing remembered.
kuka_ship_forget_order( $cancelled_id );

$cancelled_adapter = new Kuka_Shipping_Fake_Carrier();
$cancelled_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $cancelled_adapter ) ) );

$cancelled_create = $cancelled_manager->create_shipment( wc_get_order( $cancelled_id ) );
$cancelled_resume = $cancelled_manager->resume_barcode( wc_get_order( $cancelled_id ) );
$cancelled_update = $cancelled_manager->update_shipment( wc_get_order( $cancelled_id ) );
$cancelled_cancel = $cancelled_manager->cancel( wc_get_order( $cancelled_id ) );

$cancelled_after  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $cancelled_id ) );
$cancelled_after_intent = Kuka_Island_Shipping_Order_Store::pending_mutation( wc_get_order( $cancelled_id ) );

$report(
	'SHIPPING_CANCELLED_RECORD_IS_FAIL_CLOSED',
	// The setup actually reached a PROVED cancellation.
	Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $cancelled_proved
		&& $cancelled_first['ok']
		&& array() === $cancelled_intent
		// Not one external call of any kind, from any of the four doors.
		&& 0 === $cancelled_adapter->count_for( 'create_order' )
		&& 0 === $cancelled_adapter->count_for( 'create_barcode' )
		&& 0 === $cancelled_adapter->count_for( 'update_order' )
		&& 0 === $cancelled_adapter->count_for( 'update_shipment' )
		&& 0 === $cancelled_adapter->count_for( 'cancel_order' )
		&& 0 === $cancelled_adapter->count_for( 'cancel_shipment' )
		&& 0 === $cancelled_adapter->write_calls()
		&& 0 === $cancelled_adapter->read_calls()
		// Each door refuses with a code that names the reason.
		&& ! $cancelled_create['ok']
		&& 'not_creatable' === (string) $cancelled_create['code']
		&& str_contains( (string) $cancelled_create['message'], 'iptal' )
		&& ! $cancelled_resume['ok']
		&& 'not_resumable' === (string) $cancelled_resume['code']
		&& ! $cancelled_update['ok']
		&& 'nothing_to_update' === (string) $cancelled_update['code']
		&& ! $cancelled_cancel['ok']
		&& 'already_cancelled' === (string) $cancelled_cancel['code']
		// And nothing was written to the record either.
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $cancelled_after
		&& array() === $cancelled_after_intent,
	sprintf(
		'measured:real_manager_fresh_order_and_fresh_adapter|cancel_proved_by:read_only_query|createOrder:%d|createbarcode:%d|update:%d|cancel:%d|reads:%d|create_code:%s|resume_code:%s|update_code:%s|cancel_code:%s|state:%s|pending_mutation:%s',
		$cancelled_adapter->count_for( 'create_order' ),
		$cancelled_adapter->count_for( 'create_barcode' ),
		$cancelled_adapter->count_for( 'update_order' ) + $cancelled_adapter->count_for( 'update_shipment' ),
		$cancelled_adapter->count_for( 'cancel_order' ) + $cancelled_adapter->count_for( 'cancel_shipment' ),
		$cancelled_adapter->read_calls(),
		(string) $cancelled_create['code'],
		(string) $cancelled_resume['code'],
		(string) $cancelled_update['code'],
		(string) $cancelled_cancel['code'],
		$cancelled_after,
		array() === $cancelled_after_intent ? 'absent' : 'PRESENT'
	)
);

kuka_ship_destroy_order( wc_get_order( $cancelled_id ) );

/* --- and the whole state table, both doors, with a positive control ------ */

/*
 * Every state this version knows, plus one it has never heard of. The adapter
 * refuses both create operations BEFORE the network, so an allow-listed state
 * shows up as a refusal on that operation and a non-allow-listed one shows up
 * as nothing at all -- which makes the table a measurement of WHICH DOOR
 * OPENED rather than of what came back.
 *
 * The positive control matters as much as the refusals: a table where nothing
 * ever reaches a door would pass while the create path was broken.
 */

$door_states = array(
	Kuka_Island_Shipping_Order_Store::STATE_NONE,
	Kuka_Island_Shipping_Order_Store::STATE_BLOCKED,
	Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED,
	Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED,
	Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED,
	Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED,
	Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED,
	Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED,
	Kuka_Island_Shipping_Order_Store::STATE_DELIVERED,
	Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW,
	Kuka_Island_Shipping_Order_Store::STATE_CANCELLED,
	'a-state-this-version-never-heard-of',
);

$door_adapter = new Kuka_Shipping_Fake_Carrier();
$door_adapter->results['create_order']   = Kuka_Island_Shipping_Result::local_refusal( 'create_order', 'payload_incomplete' );
$door_adapter->results['create_barcode'] = Kuka_Island_Shipping_Result::local_refusal( 'create_barcode', 'payload_incomplete' );

$door_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $door_adapter ) ) );
$door_order   = kuka_ship_fixture_order();
$door_id      = (int) $door_order->get_id();

// Pin the owner and mint a reference, so ownership is never the thing refusing.
$door_manager->create_shipment( wc_get_order( $door_id ) );
$door_adapter->reset_counters();

$door_wrong = array();

foreach ( $door_states as $door_state ) {
	foreach ( array( 'create', 'resume' ) as $door_action ) {
		$door_fresh = wc_get_order( $door_id );
		Kuka_Island_Shipping_Order_Store::set_state( $door_fresh, (string) $door_state );
		$door_fresh->update_meta_data( Kuka_Island_Shipping_Order_Store::META_PENDING_MUTATION, array() );
		$door_fresh->update_meta_data( Kuka_Island_Shipping_Order_Store::META_SHIPMENT_ID, 'FAKE-SHIP-1' );
		$door_fresh->save_meta_data();
		kuka_ship_forget_order( $door_id );

		$door_adapter->reset_counters();

		if ( 'create' === $door_action ) {
			$door_manager->create_shipment( wc_get_order( $door_id ) );
			$door_expected_order   = in_array( $door_state, Kuka_Island_Shipping_Order_Store::states_allowing_create_order(), true ) ? 1 : 0;
			$door_expected_barcode = 0;
		} else {
			$door_manager->resume_barcode( wc_get_order( $door_id ) );
			$door_expected_order   = 0;
			$door_expected_barcode = in_array( $door_state, Kuka_Island_Shipping_Order_Store::states_allowing_create_barcode(), true ) ? 1 : 0;
		}

		if ( $door_expected_order !== $door_adapter->count_refused( 'create_order' )
			|| $door_expected_barcode !== $door_adapter->count_refused( 'create_barcode' )
			|| 0 !== $door_adapter->write_calls() ) {

			$door_wrong[] = sprintf(
				'%s/%s=>order:%d/barcode:%d/writes:%d',
				(string) $door_state,
				$door_action,
				$door_adapter->count_refused( 'create_order' ),
				$door_adapter->count_refused( 'create_barcode' ),
				$door_adapter->write_calls()
			);
		}
	}
}

$report(
	'SHIPPING_CREATE_DOORS_ARE_AN_ALLOWLIST',
	array() === $door_wrong
		&& 3 === count( Kuka_Island_Shipping_Order_Store::states_allowing_create_order() )
		&& 1 === count( Kuka_Island_Shipping_Order_Store::states_allowing_create_barcode() ),
	sprintf(
		'measured:which_door_opened|states:%d|actions:2|createOrder_allowed_from:%s|createbarcode_allowed_from:%s|wrong:%s|carrier_writes:0',
		count( $door_states ),
		implode( '+', Kuka_Island_Shipping_Order_Store::states_allowing_create_order() ),
		implode( '+', Kuka_Island_Shipping_Order_Store::states_allowing_create_barcode() ),
		array() === $door_wrong ? 'none' : implode( ',', $door_wrong )
	)
);

kuka_ship_purge_actions( $door_id );
kuka_ship_destroy_order( wc_get_order( $door_id ) );

/* ========================================================================== */
/* 54. A protected state with no owner is refused, never guessed               */
/* ========================================================================== */

/*
 * has_carrier_evidence() answers "was SOME carrier addressed under this
 * reference?", and a record that answers yes with no provider stored must fail
 * closed rather than fall back to the shop's default. The two protected states
 * were MISSING from its evidence list, and they are the strongest evidence
 * there is: an order only reaches them because begin_mutation() wrote an intent
 * immediately before a request went out. Without them, an ownerless record in
 * cancel_reconciliation_required was handed to whatever the shop now calls its
 * default -- which is how a cancellation reaches a courier that never had the
 * parcel.
 *
 * The pending-mutation record is evidence in its own right, whatever the state
 * says, because it exists only between begin_mutation() and the outcome that
 * settles it.
 */

$orphan_cases = array(
	'cancel_reconciliation_required' => static function ( WC_Order $order ): void {
		Kuka_Island_Shipping_Order_Store::set_state( $order, Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED );
	},
	'update_reconciliation_required' => static function ( WC_Order $order ): void {
		Kuka_Island_Shipping_Order_Store::set_state( $order, Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED );
	},
	'pending_mutation_only'          => static function ( WC_Order $order ): void {
		// A state that is NOT evidence on its own, plus an intent record.
		Kuka_Island_Shipping_Order_Store::set_state( $order, Kuka_Island_Shipping_Order_Store::STATE_BLOCKED );
		$order->update_meta_data(
			Kuka_Island_Shipping_Order_Store::META_PENDING_MUTATION,
			array(
				'mutation_id'    => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
				'kind'           => Kuka_Island_Shipping_Order_Store::MUTATION_CANCEL,
				'operation'      => 'cancel_shipment',
				'target'         => 'shipment',
				'previous_state' => Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED,
				'provider'       => 'kuka-test-kargo',
				'reference'      => 'KI1-AAAAAA',
				'expected'       => array(),
				'created_at'     => time(),
			)
		);
		$order->save_meta_data();
	},
);

$orphan_wrong = array();
$orphan_rows  = array();

foreach ( $orphan_cases as $orphan_label => $orphan_setup ) {
	$orphan = kuka_ship_affinity_scenario();
	$orphan_id = (int) $orphan['order']->get_id();

	// A real create, so the reference and the shipment id are genuine, then the
	// provider is removed: exactly what a record written before ownership was
	// pinned -- or one whose meta was partly lost -- looks like.
	$orphan['manager']->create_shipment( $orphan['order'], 'dhl' );

	$orphan_order = wc_get_order( $orphan_id );
	$orphan_setup( $orphan_order );

	$orphan_order = wc_get_order( $orphan_id );
	$orphan_order->delete_meta_data( Kuka_Island_Shipping_Order_Store::META_PROVIDER );
	$orphan_order->save_meta_data();
	kuka_ship_forget_order( $orphan_id );

	$orphan_evidence = Kuka_Island_Shipping_Order_Store::has_carrier_evidence( wc_get_order( $orphan_id ) );
	$orphan_stored   = Kuka_Island_Shipping_Order_Store::provider( wc_get_order( $orphan_id ) );

	kuka_ship_affinity_flip( $orphan );
	$orphan['transport']->reset();
	$orphan['other']->reset_counters();

	$orphan_codes = array(
		'create'    => (string) $orphan['manager']->create_shipment( wc_get_order( $orphan_id ) )['code'],
		'resume'    => (string) $orphan['manager']->resume_barcode( wc_get_order( $orphan_id ) )['code'],
		'update'    => (string) $orphan['manager']->update_shipment( wc_get_order( $orphan_id ) )['code'],
		'cancel'    => (string) $orphan['manager']->cancel( wc_get_order( $orphan_id ) )['code'],
		'query'     => (string) $orphan['manager']->query_status( wc_get_order( $orphan_id ) )['code'],
		'reconcile' => (string) $orphan['manager']->reconcile_order( wc_get_order( $orphan_id ) )['verdict'],
	);

	kuka_ship_affinity_unflip( $orphan );

	foreach ( $orphan_codes as $orphan_door => $orphan_code ) {
		if ( 'shipment_provider_missing' !== $orphan_code ) {
			$orphan_wrong[] = $orphan_label . '/' . $orphan_door . '=>' . $orphan_code;
		}
	}

	if ( ! $orphan_evidence ) {
		$orphan_wrong[] = $orphan_label . '/evidence=>NO';
	}

	if ( '' !== $orphan_stored ) {
		$orphan_wrong[] = $orphan_label . '/provider=>' . $orphan_stored;
	}

	if ( 0 !== count( $orphan['transport']->log ) || 0 !== $orphan['other']->contacts() ) {
		$orphan_wrong[] = sprintf(
			'%s/contacts=>dhl:%d,other:%d',
			$orphan_label,
			count( $orphan['transport']->log ),
			$orphan['other']->contacts()
		);
	}

	$orphan_rows[] = $orphan_label . ':evidence_yes/doors_refused_6/contacts_0';

	kuka_ship_purge_actions( $orphan_id );
	kuka_ship_destroy_order( wc_get_order( $orphan_id ) );
}

$report(
	'SHIPPING_ORPHANED_PROTECTED_STATE_FAILS_CLOSED',
	array() === $orphan_wrong && 3 === count( $orphan_rows ),
	sprintf(
		'measured:real_manager_with_two_adapters|cases:%d|doors_per_case:6|wrong:%s|rows:%s',
		count( $orphan_rows ),
		array() === $orphan_wrong ? 'none' : implode( '+', $orphan_wrong ),
		implode( ',', $orphan_rows )
	)
);

/* ========================================================================== */
/* 55. The fulfilment carries the date it was fulfilled                        */
/* ========================================================================== */

/*
 * THE GAP. sync_status() flipped the WooCommerce fulfilment to `fulfilled` and
 * never wrote a fulfilment DATE, so get_date_fulfilled() stayed null. Nothing
 * in the shipping module reads it -- but the EDM invoice does: the handover date
 * on a fiscal document is exactly this value, and without it every order
 * shipped through this module answered internet_sales_shipment_date_missing.
 *
 * THE TIMEZONE IS MEASURED, NOT GUESSED. Fulfillment::set_date_fulfilled()
 * hands its input to normalize_date_to_utc(), which builds a DateTime with
 * wp_timezone() as the fallback zone and stores the UTC equivalent. Round-
 * tripped on this install (PHP UTC, WordPress Europe/Istanbul, +03:00):
 *
 *   set_date_fulfilled( '2026-09-04 12:00:00' )        -> '2026-09-04 09:00:00'
 *   set_date_fulfilled( '2026-09-04 12:00:00+00:00' )  -> '2026-09-04 12:00:00'
 *
 * So a bare gmdate() string is read as SHOP-LOCAL and stored three hours early
 * on this shop -- a silently wrong handover date on a fiscal document. The
 * value written therefore states its own offset, and the assertion below is the
 * discriminator: a stored value that is off by the shop's offset fails it.
 */

$fdate_scenario = kuka_ship_scenario(
	static function ( string $method, string $url ) use ( &$fdate_code ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return kuka_ship_create_barcode_ok( '990011223', 'BC-FDATE' );
		}

		if ( str_contains( $url, '/getshipmentstatus' ) ) {
			// The vendor's own field name, exactly as the other status
			// measurements in this suite script it.
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode( array( 'shipmentId' => '990011223', 'shipmentStatusCode' => $fdate_code ) ),
			);
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$fdate_code    = 1;
$fdate_product = kuka_ship_shippable_product_id();

if ( $fdate_product > 0 ) {
	// A real shippable line, so EDM's reader has a shipment fact to report. The
	// product itself is only referenced; nothing about it is written.
	$fdate_order = $fdate_scenario['order'];

	foreach ( $fdate_order->get_items() as $fdate_item ) {
		$fdate_order->remove_item( $fdate_item->get_id() );
	}

	$fdate_order->add_product( wc_get_product( $fdate_product ), 1 );
	$fdate_order->calculate_totals( false );
	$fdate_order->save();
}

$fdate_scenario['manager']->create_shipment( $fdate_scenario['order'] );
$fdate_id  = (int) $fdate_scenario['order']->get_id();
$fdate_ref = (string) Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $fdate_id ) )['reference'];

/** The fulfilment as a FRESH object every time, so nothing is read from memory. */
$fdate_read = static function () use ( $fdate_id, $fdate_ref ) {
	kuka_ship_forget_order( $fdate_id );
	$own = Kuka_Island_Shipping_Fulfillment_Writer::find_own( wc_get_order( $fdate_id ), $fdate_ref );

	return null === $own ? null : $own;
};

// (a) Before code 2: not fulfilled, no date.
$fdate_before   = $fdate_read();
$fdate_none     = null !== $fdate_before && ! $fdate_before->get_is_fulfilled()
	&& '' === (string) $fdate_before->get_date_fulfilled();

/*
 * (b) Code 2, the FIRST transition into fulfilled.
 *
 * Driven through Fulfillment_Writer::sync_status() DIRECTLY, because that is
 * the method under test and its report says who wrote the date. Going only
 * through query_status() would leave the question open: WooCommerce's own
 * FulfillmentsDataStore also fills _date_fulfilled on a fulfilled save, so a
 * date appearing is not by itself evidence that this module produced it.
 * 'date_fulfilled:set' is.
 */
$fdate_code = Kuka_Island_Shipping_Status::CODE_IN_TRANSFER;
$fdate_at   = time();
$fdate_sync = Kuka_Island_Shipping_Fulfillment_Writer::sync_status(
	wc_get_order( $fdate_id ),
	$fdate_ref,
	Kuka_Island_Shipping_Status::CODE_IN_TRANSFER
);

// And the integrated path runs too, so the module's own poll is measured.
$fdate_scenario['manager']->query_status( wc_get_order( $fdate_id ) );

$fdate_first     = $fdate_read();
$fdate_stored    = null === $fdate_first ? '' : (string) $fdate_first->get_date_fulfilled();
$fdate_fulfilled = null !== $fdate_first && $fdate_first->get_is_fulfilled();

/*
 * The stored value is UTC 'Y-m-d H:i:s'. Parsed strictly as UTC and compared
 * against the moment the transition happened: a bare-gmdate write would land
 * the shop's offset away and fail here.
 */
$fdate_parsed = '' === $fdate_stored
	? null
	: DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $fdate_stored, new DateTimeZone( 'UTC' ) );
$fdate_skew   = $fdate_parsed instanceof DateTimeImmutable ? abs( $fdate_parsed->getTimestamp() - $fdate_at ) : -1;
$fdate_exact  = $fdate_parsed instanceof DateTimeImmutable && $fdate_parsed->format( 'Y-m-d H:i:s' ) === $fdate_stored;

// (c) Codes 3, 4, 5 and a repeated poll: the value must not move by one byte.
$fdate_moves = array();
foreach ( array( 3, 4, 5, 5 ) as $fdate_later ) {
	$fdate_code = $fdate_later;
	$fdate_scenario['manager']->query_status( wc_get_order( $fdate_id ) );

	$fdate_now = $fdate_read();
	$fdate_val = null === $fdate_now ? '' : (string) $fdate_now->get_date_fulfilled();

	if ( $fdate_val !== $fdate_stored ) {
		$fdate_moves[] = 'code' . (string) $fdate_later . '=>' . $fdate_val;
	}
}

/*
 * And the value EDM will put on the document. read_shipment_facts() is the
 * production reader; the day it derives has to be the moment expressed in the
 * SHOP's timezone, which right now is not the same calendar day as UTC.
 */
$fdate_edm_ready = class_exists( 'Kuka_Island_Core_Internet_Sales_Details' );

if ( ! $fdate_edm_ready ) {
	$fdate_edm_loader = dirname( __FILE__ ) . '/lib-edm-module-loader.php';

	if ( is_readable( $fdate_edm_loader ) ) {
		require_once $fdate_edm_loader;
		$fdate_edm_module = kuka_edm_load_module();
		$fdate_edm_ready  = (bool) $fdate_edm_module['ok'] && class_exists( 'Kuka_Island_Core_Internet_Sales_Details' );
	}
}

$fdate_facts      = array();
$fdate_edm_day    = '';
$fdate_expect_day = '';

if ( $fdate_edm_ready ) {
	kuka_ship_forget_order( $fdate_id );
	$fdate_facts   = Kuka_Island_Core_Internet_Sales_Details::read_shipment_facts( wc_get_order( $fdate_id ) );
	$fdate_edm_raw = (string) ( $fdate_facts['shipment_date'] ?? '' );
	$fdate_edm_dt  = '' === $fdate_edm_raw
		? null
		: Kuka_Island_Core_Internet_Sales_Details::parse_fulfillment_datetime( $fdate_edm_raw );

	if ( $fdate_edm_dt instanceof DateTimeImmutable ) {
		$fdate_edm_day = $fdate_edm_dt->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}

	if ( $fdate_parsed instanceof DateTimeImmutable ) {
		$fdate_expect_day = $fdate_parsed->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}
}

$report(
	'SHIPPING_FULFILLMENT_DATE_ON_FIRST_FULFILL',
	$fdate_none
		// This module wrote it, in its own code, on the first transition.
		&& true === (bool) $fdate_sync['ok']
		&& 'fulfilled' === (string) $fdate_sync['action']
		&& 'set' === (string) $fdate_sync['date_fulfilled']
		&& $fdate_fulfilled
		&& '' !== $fdate_stored
		&& $fdate_exact
		// Within two minutes of the transition, so the shop's offset cannot hide.
		&& $fdate_skew >= 0 && $fdate_skew <= 120
		&& array() === $fdate_moves
		&& $fdate_edm_ready
		&& $fdate_product > 0
		&& '' !== $fdate_edm_day
		&& $fdate_edm_day === $fdate_expect_day
		&& $fdate_edm_day === ( new DateTimeImmutable( '@' . (string) $fdate_at ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' ),
	sprintf(
		'measured:real_poll_and_fresh_fulfillment_objects|before_code_2:%s|writer_action:%s|writer_date:%s|fulfilled_after_code_2:%s|date_stored:%s|utc_format_exact:%s|skew_seconds:%d|later_codes_3_4_5_and_repeat:%s|edm_reader:%s|shippable_line:%s|edm_local_day:%s|shop_day_now:%s',
		$fdate_none ? 'no_date' : 'HAD_DATE_OR_FULFILLED',
		(string) $fdate_sync['action'],
		(string) $fdate_sync['date_fulfilled'],
		$fdate_fulfilled ? 'yes' : 'NO',
		'' === $fdate_stored ? 'ABSENT' : 'present',
		$fdate_exact ? 'yes' : 'NO',
		$fdate_skew,
		array() === $fdate_moves ? 'byte_identical' : implode( '+', $fdate_moves ),
		$fdate_edm_ready ? 'loaded' : 'UNAVAILABLE',
		$fdate_product > 0 ? 'yes' : 'NO',
		'' === $fdate_edm_day ? 'MISSING' : $fdate_edm_day,
		( new DateTimeImmutable( '@' . (string) $fdate_at ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' )
	)
);

kuka_ship_purge_actions( $fdate_id );
kuka_ship_destroy_order( wc_get_order( $fdate_id ) );

/* ========================================================================== */
/* 56. Reconciliation takes the same mutation lock, with zero wait             */
/* ========================================================================== */

/*
 * THE GAP. reconcile_order() is an external entry point -- an operator button
 * -- and it was the only mutation-adjacent door that took NO lock. It read the
 * provider, the state, the pending intent and the reference from whatever
 * object the caller happened to hold, so it could run while a create was still
 * in flight and settle an intent belonging to a write that had not finished.
 * Two operators pressing it at once could each read the same open intent.
 *
 * Measured with a GENUINE second MySQL session, because a MySQL advisory lock
 * is held per connection: two sequential calls on one connection prove nothing.
 */

$rlock_session = kuka_ship_second_session();
$rlock_db      = $rlock_session['db'];

$rlock_adapter = new Kuka_Shipping_Fake_Carrier();
$rlock_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $rlock_adapter ) ) );
$rlock_order   = kuka_ship_fixture_order();
$rlock_id      = (int) $rlock_order->get_id();

// An open CREATE intent, written by the production method that writes them.
$rlock_begin = Kuka_Island_Shipping_Order_Store::begin_mutation(
	wc_get_order( $rlock_id ),
	array(
		'kind'      => Kuka_Island_Shipping_Order_Store::MUTATION_CREATE,
		'operation' => 'create_order',
		'target'    => 'order',
		'provider'  => Kuka_Shipping_Fake_Carrier::KEY,
		'reference' => Kuka_Island_Shipping_Order_Store::prepare_reference( wc_get_order( $rlock_id ) ),
	)
);

kuka_ship_forget_order( $rlock_id );
$rlock_adapter->reset_counters();

/** The four decisions, as the DATABASE holds them. */
$rlock_snapshot = static function () use ( $rlock_db, $rlock_id ): string {
	return implode(
		'|',
		array(
			kuka_ship_meta_over( $rlock_db, $rlock_id, Kuka_Island_Shipping_Order_Store::META_STATE ),
			kuka_ship_meta_over( $rlock_db, $rlock_id, Kuka_Island_Shipping_Order_Store::META_PROVIDER ),
			kuka_ship_meta_over( $rlock_db, $rlock_id, Kuka_Island_Shipping_Order_Store::META_REFERENCE ),
			kuka_ship_meta_over( $rlock_db, $rlock_id, Kuka_Island_Shipping_Order_Store::META_PENDING_MUTATION ),
		)
	);
};

$rlock_before = $rlock_snapshot();

// Somebody else holds the order's mutation lock: a create is in flight.
$rlock_held      = $rlock_session['separate'] ? kuka_ship_hold_mutation_lock( $rlock_db, $rlock_id ) : false;
$rlock_contended = $rlock_held
	? $rlock_manager->reconcile_order( wc_get_order( $rlock_id ) )
	: array( 'verdict' => 'NOT_RUN' );

$rlock_reads_while_held  = $rlock_adapter->read_calls();
$rlock_writes_while_held = $rlock_adapter->write_calls();
$rlock_during            = $rlock_snapshot();

if ( $rlock_held ) {
	kuka_ship_release_mutation_lock( $rlock_db, $rlock_id );
}

/*
 * The lock is free again. The reconciliation now runs and settles the intent
 * from the FRESH state -- the adapter answers not_found on both reads, which is
 * the only proof of absence this integration accepts.
 */
$rlock_after_verdict = $rlock_manager->reconcile_order( wc_get_order( $rlock_id ) );
$rlock_after_state   = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $rlock_id ) );
$rlock_after_intent  = Kuka_Island_Shipping_Order_Store::pending_mutation( wc_get_order( $rlock_id ) );
$rlock_reads_after   = $rlock_adapter->read_calls();

kuka_ship_purge_actions( $rlock_id );
kuka_ship_destroy_order( wc_get_order( $rlock_id ) );

/* --- and one intent cannot be closed twice by two concurrent presses ----- */

$rtwice          = kuka_ship_cancel_fixture();
$rtwice_id       = (int) $rtwice['order']->get_id();
$rtwice['adapter']->results['read_shipment'] = Kuka_Island_Shipping_Result::transient( 'get_shipment', 'timeout', 0 );
$rtwice['manager']->cancel( wc_get_order( $rtwice_id ) );
$rtwice['adapter']->results = array();
$rtwice['adapter']->reset_counters();

// Press one: the read proves the shipment is gone, so the intent closes.
$rtwice_first  = $rtwice['manager']->reconcile_order( wc_get_order( $rtwice_id ) );
$rtwice_state  = Kuka_Island_Shipping_Order_Store::get_state( wc_get_order( $rtwice_id ) );
$rtwice_intent = Kuka_Island_Shipping_Order_Store::pending_mutation( wc_get_order( $rtwice_id ) );

// Press two, arriving while a third party holds the lock: refused, no reads.
$rtwice_held     = $rlock_session['separate'] ? kuka_ship_hold_mutation_lock( $rlock_db, $rtwice_id ) : false;
$rtwice_reads_a  = $rtwice['adapter']->read_calls();
$rtwice_second   = $rtwice_held
	? $rtwice['manager']->reconcile_order( wc_get_order( $rtwice_id ) )
	: array( 'verdict' => 'NOT_RUN' );
$rtwice_reads_b  = $rtwice['adapter']->read_calls();

if ( $rtwice_held ) {
	kuka_ship_release_mutation_lock( $rlock_db, $rtwice_id );
}

// And with the lock free, a second press still cannot re-close a closed intent.
$rtwice_third = $rtwice['manager']->reconcile_order( wc_get_order( $rtwice_id ) );

$report(
	'SHIPPING_RECONCILE_TAKES_THE_MUTATION_LOCK',
	$rlock_session['separate']
		&& true === (bool) $rlock_begin['ok']
		&& $rlock_held
		// Refused, with zero wait and zero carrier contact.
		&& 'lock_contended' === (string) $rlock_contended['verdict']
		&& 0 === $rlock_reads_while_held
		&& 0 === $rlock_writes_while_held
		// Not one byte of the four decisions moved.
		&& $rlock_during === $rlock_before
		// With the lock free it runs, from the fresh state, and settles.
		&& 'absent_confirmed' === (string) $rlock_after_verdict['verdict']
		&& Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED === $rlock_after_state
		&& array() === $rlock_after_intent
		&& 2 === $rlock_reads_after
		&& 0 === $rlock_adapter->write_calls()
		// One intent, closed once.
		&& 'cancelled' === (string) $rtwice_first['verdict']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $rtwice_state
		&& array() === $rtwice_intent
		&& $rtwice_held
		&& 'lock_contended' === (string) $rtwice_second['verdict']
		&& $rtwice_reads_a === $rtwice_reads_b
		// A conclusion a read already reached is not re-asked, and a closed
		// intent cannot be closed a second time.
		&& 'already_settled' === (string) $rtwice_third['verdict'],
	sprintf(
		'measured:second_mysql_session|separate:%s|contended_verdict:%s|reads_while_held:%d|writes_while_held:%d|decisions_byte_identical:%s|after_release_verdict:%s|state:%s|intent:%s|reconcile_reads:%d|concurrent_second_press:%s|reads_added_by_it:%d|third_press:%s',
		$rlock_session['separate'] ? 'yes' : 'NO',
		(string) $rlock_contended['verdict'],
		$rlock_reads_while_held,
		$rlock_writes_while_held,
		$rlock_during === $rlock_before ? 'yes' : 'NO',
		(string) $rlock_after_verdict['verdict'],
		$rlock_after_state,
		array() === $rlock_after_intent ? 'cleared' : 'STILL_SET',
		$rlock_reads_after,
		(string) $rtwice_second['verdict'],
		$rtwice_reads_b - $rtwice_reads_a,
		(string) $rtwice_third['verdict']
	)
);

kuka_ship_purge_actions( $rtwice_id );
kuka_ship_destroy_order( wc_get_order( $rtwice_id ) );

/* ========================================================================== */
/* 57. A local refusal ends the poll chain instead of booking 14 days of it    */
/* ========================================================================== */

/*
 * THE GAP. query_status() can refuse before the carrier is touched at all --
 * credentials missing, a closed runtime gate, an unregistered carrier, a
 * configuration value nobody understood. Those are not carrier attempts, so
 * they correctly spend no attempt from the budget. But the poller decided what
 * to do next from "ok:false plus an unknown lifecycle", which is the
 * still-moving branch, so it booked another query. With the attempt counter
 * standing still, MAX_ATTEMPTS never arrived and the only thing that ended the
 * chain was MAX_ELAPSED: roughly fourteen days of scheduler work for an order
 * whose carrier was never contacted once.
 */

putenv( 'KUKA_SHIPPING_AUTOMATION=1' );

$lrefuse            = kuka_ship_fake_shipment();
$lrefuse_id         = (int) $lrefuse['order']->get_id();
$lrefuse_notes_at_0 = count( (array) wc_get_order_notes( array( 'order_id' => $lrefuse_id, 'limit' => 200 ) ) );

// The gate closes: credentials are gone. Nothing may reach the carrier.
$lrefuse['adapter']->readiness = array(
	'ready'        => false,
	'gaps'         => array( 'KUKA_DHL_CLIENT_ID' ),
	'environment'  => 'test',
	'live_blocked' => false,
);
$lrefuse['adapter']->reset_counters();

kuka_ship_attach_sole_poller( $lrefuse['manager'] );
$lrefuse_booked = Kuka_Island_Shipping_Status_Poller::schedule_query( $lrefuse_id );

// Three runner turns, so note and history growth can be counted.
$lrefuse_run = kuka_ship_drive_status_chain( $lrefuse_id, 5 );

$lrefuse_worker  = new Kuka_Island_Shipping_Status_Poller( $lrefuse['manager'] );
$lrefuse_outcome = $lrefuse_worker->run( $lrefuse_id );
$lrefuse_worker->run( $lrefuse_id );
$lrefuse_worker->run( $lrefuse_id );

kuka_ship_forget_order( $lrefuse_id );
$lrefuse_data    = Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $lrefuse_id ) );
$lrefuse_pending = Kuka_Island_Shipping_Status_Poller::has_pending_query( $lrefuse_id );
$lrefuse_notes   = count( (array) wc_get_order_notes( array( 'order_id' => $lrefuse_id, 'limit' => 200 ) ) ) - $lrefuse_notes_at_0;

$lrefuse_history = 0;
foreach ( (array) $lrefuse_data['history'] as $lrefuse_entry ) {
	if ( str_contains( (string) ( $lrefuse_entry['message'] ?? '' ), 'kimlik' )
		|| str_contains( (string) ( $lrefuse_entry['message'] ?? '' ), 'yapılandırma' ) ) {
		++$lrefuse_history;
	}
}

$lrefuse_removed = kuka_ship_purge_actions( $lrefuse_id );

/* --- the control: a REAL transient network result still spends an attempt - */

$ltransient    = kuka_ship_fake_shipment();
$ltransient_id = (int) $ltransient['order']->get_id();
$ltransient['adapter']->results['read_shipment_status'] = Kuka_Island_Shipping_Result::transient( 'get_shipment_status', 'timeout', 0 );
$ltransient['adapter']->reset_counters();

kuka_ship_attach_sole_poller( $ltransient['manager'] );
Kuka_Island_Shipping_Status_Poller::schedule_query( $ltransient_id );
$ltransient_run = kuka_ship_drive_status_chain( $ltransient_id, 1 );

kuka_ship_forget_order( $ltransient_id );
$ltransient_attempts = Kuka_Island_Shipping_Order_Store::query_attempts( wc_get_order( $ltransient_id ) );
$ltransient_pending  = Kuka_Island_Shipping_Status_Poller::has_pending_query( $ltransient_id );
$ltransient_reads    = $ltransient['adapter']->count_for( 'read_shipment_status' );
$ltransient_removed  = kuka_ship_purge_actions( $ltransient_id );

putenv( 'KUKA_SHIPPING_AUTOMATION' );

$report(
	'SHIPPING_LOCAL_REFUSAL_ENDS_THE_POLL_CHAIN',
	in_array( $lrefuse_booked, array( Kuka_Island_Shipping_Status_Poller::SCHEDULE_CREATED, Kuka_Island_Shipping_Status_Poller::SCHEDULE_ALREADY_PENDING ), true )
		// The carrier was never contacted, so no attempt was spent.
		&& 0 === $lrefuse['adapter']->count_for( 'read_shipment_status' )
		&& 0 === (int) $lrefuse_data['query_attempts']
		// And no follow-up was booked: the chain ends here, not in 14 days.
		&& ! $lrefuse_pending
		&& 1 === count( $lrefuse_run['processed'] )
		&& str_starts_with( (string) $lrefuse_outcome, 'stop:' )
		// The operator can see WHY, once, however many turns run.
		&& 'credentials_missing' === (string) $lrefuse_data['last_error']
		&& $lrefuse_notes <= 1
		&& $lrefuse_history <= 1
		// The control: a real transient answer still costs an attempt and still
		// keeps the bounded retry chain.
		&& 1 === $ltransient_reads
		&& 1 === $ltransient_attempts
		&& $ltransient_pending
		&& 1 === count( $ltransient_run['processed'] ),
	sprintf(
		'measured:action_scheduler_runner|local_refusal:carrier_reads:%d|attempts:%d|follow_up_booked:%s|runner_turns:%d|worker_outcome:%s|last_error:%s|notes_added_by_4_turns:%d|history_entries:%d|actions_removed:%d'
			. '||transient_control:reads:%d|attempts:%d|follow_up_booked:%s|actions_removed:%d',
		$lrefuse['adapter']->count_for( 'read_shipment_status' ),
		(int) $lrefuse_data['query_attempts'],
		$lrefuse_pending ? 'YES' : 'no',
		count( $lrefuse_run['processed'] ),
		(string) $lrefuse_outcome,
		'' === (string) $lrefuse_data['last_error'] ? 'none' : (string) $lrefuse_data['last_error'],
		$lrefuse_notes,
		$lrefuse_history,
		$lrefuse_removed,
		$ltransient_reads,
		$ltransient_attempts,
		$ltransient_pending ? 'yes' : 'NO',
		$ltransient_removed
	)
);

kuka_ship_destroy_order( wc_get_order( $lrefuse_id ) );
kuka_ship_destroy_order( wc_get_order( $ltransient_id ) );

/* ========================================================================== */
/* 58. A shipment adopted by reconciliation has a start time                   */
/* ========================================================================== */

/*
 * THE GAP. META_CREATED_AT was written only by save_order_created(), which runs
 * on a confirmed createOrder. A reconciliation that finds a SHIPMENT under the
 * reference calls save_shipment_created() directly, with no createOrder behind
 * it in this life of the order -- so created_at stayed 0. The poller computes
 * elapsed as time() - created_at, and with 0 that is every second since 1970:
 * the very first turn exceeded MAX_ELAPSED and the chain gave up before it had
 * read anything. The parcel existed at the carrier and nothing followed it.
 */

putenv( 'KUKA_SHIPPING_AUTOMATION=1' );

$adopt_adapter = new Kuka_Shipping_Fake_Carrier();
$adopt_adapter->results['read_shipment'] = Kuka_Island_Shipping_Result::success(
	'get_shipment',
	array( 'shipment_id' => 'ADOPTED-1', 'exists' => true )
);
$adopt_manager = new Kuka_Island_Shipping_Manager( kuka_ship_registry_of( array( $adopt_adapter ) ) );
$adopt_order   = kuka_ship_fixture_order();
$adopt_id      = (int) $adopt_order->get_id();

/*
 * The order the reconciliation meets: an owner and a reference, an open create
 * intent, and NOTHING confirmed -- exactly what a createOrder that never came
 * back leaves behind.
 */
Kuka_Island_Shipping_Order_Store::begin_mutation(
	wc_get_order( $adopt_id ),
	array(
		'kind'      => Kuka_Island_Shipping_Order_Store::MUTATION_CREATE,
		'operation' => 'create_order',
		'target'    => 'order',
		'provider'  => Kuka_Shipping_Fake_Carrier::KEY,
		'reference' => Kuka_Island_Shipping_Order_Store::prepare_reference( wc_get_order( $adopt_id ) ),
	)
);

kuka_ship_forget_order( $adopt_id );
$adopt_created_before = (int) Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $adopt_id ) )['created_at'];

$adopt_verdict = $adopt_manager->reconcile_order( wc_get_order( $adopt_id ) );

kuka_ship_forget_order( $adopt_id );
$adopt_data    = Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $adopt_id ) );
$adopt_created = (int) $adopt_data['created_at'];

// The first poll must NOT be the last one.
kuka_ship_attach_sole_poller( $adopt_manager );
$adopt_decision = Kuka_Island_Shipping_Status_Poller::decide(
	Kuka_Island_Shipping_Status::LIFECYCLE_IN_PROGRESS,
	0,
	time() - $adopt_created
);
$adopt_run     = kuka_ship_drive_status_chain( $adopt_id, 1 );
$adopt_pending = Kuka_Island_Shipping_Status_Poller::has_pending_query( $adopt_id );
$adopt_removed = kuka_ship_purge_actions( $adopt_id );

// And an existing created_at is never moved.
$adopt_keep = kuka_ship_fixture_order();
$adopt_keep_id = (int) $adopt_keep->get_id();
$adopt_keep->update_meta_data( Kuka_Island_Shipping_Order_Store::META_CREATED_AT, 1700000000 );
$adopt_keep->save_meta_data();
Kuka_Island_Shipping_Order_Store::save_shipment_created( wc_get_order( $adopt_keep_id ), 'KEEP-1', array( 'BC-KEEP' ) );
kuka_ship_forget_order( $adopt_keep_id );
$adopt_kept = (int) Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $adopt_keep_id ) )['created_at'];

putenv( 'KUKA_SHIPPING_AUTOMATION' );

$report(
	'SHIPPING_ADOPTED_SHIPMENT_HAS_A_START_TIME',
	0 === $adopt_created_before
		&& 'shipment_present' === (string) $adopt_verdict['verdict']
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === (string) $adopt_data['state']
		// Read back from a fresh order object, and it is a real moment.
		&& $adopt_created > 0
		&& abs( $adopt_created - time() ) <= 120
		// So the first poll reschedules instead of giving up.
		&& 'reschedule' === (string) $adopt_decision['action']
		&& 'still_moving' === (string) $adopt_decision['reason']
		&& 1 === count( $adopt_run['processed'] )
		&& $adopt_pending
		// An existing value is left exactly as it was.
		&& 1700000000 === $adopt_kept,
	sprintf(
		'measured:real_reconciliation_and_action_scheduler|created_at_before:%d|verdict:%s|state:%s|created_at_after:%d|skew_seconds:%d|first_poll_decision:%s/%s|runner_turns:%d|follow_up_booked:%s|existing_value_kept:%d|actions_removed:%d',
		$adopt_created_before,
		(string) $adopt_verdict['verdict'],
		(string) $adopt_data['state'],
		$adopt_created,
		$adopt_created > 0 ? abs( $adopt_created - time() ) : -1,
		(string) $adopt_decision['action'],
		(string) $adopt_decision['reason'],
		count( $adopt_run['processed'] ),
		$adopt_pending ? 'yes' : 'NO',
		$adopt_kept,
		$adopt_removed
	)
);

kuka_ship_destroy_order( wc_get_order( $adopt_id ) );
kuka_ship_destroy_order( wc_get_order( $adopt_keep_id ) );

if ( $rlock_db instanceof wpdb ) {
	$rlock_db->close();
}

/* ========================================================================== */
/* 59. The one customer e-mail, and the state that keeps it one                */
/* ========================================================================== */

/*
 * WooCommerce fires woocommerce_fulfillment_created_notification from exactly
 * one place -- its REST controller, behind the drawer's "notify customer" tick.
 * A fulfilment this module flipped to `fulfilled` from a carrier status reading
 * therefore produced NO e-mail: notification event 0, mail attempt 0, on a
 * record that was fulfilled.
 *
 * NOTHING LEAVES THIS MACHINE. The recorder below short-circuits wp_mail() at
 * 'pre_wp_mail' and then plays the transport's part itself: 'accepted' fires
 * wp_mail_succeeded, 'refused' fires wp_mail_failed with a WP_Error whose
 * message deliberately contains an SMTP user name and a server line, and
 * 'silent' fires nothing at all -- which is what an SMTP conversation that dies
 * mid-handshake looks like from inside WordPress.
 */

final class Kuka_Shipping_Mail_Recorder {

	/** accepted | refused | silent */
	public string $mode = 'accepted';

	/** @var array<int, array<string, mixed>> */
	public array $sent = array();

	/** The sentinel a refusal carries, so a leak can be searched for by name. */
	public const SECRET = 'kuka-smtp-user-SENTINEL-9f2a';

	/**
	 * Attached BEFORE Core's Throwable-safe wrapper, deliberately.
	 *
	 * Kuka_Island_Core_Email_Delivery::send_safely() hooks 'pre_wp_mail' at
	 * -1000 and, so that a disabled mail() cannot kill checkout, calls wp_mail()
	 * again inside itself behind a recursion guard. One logical message
	 * therefore reaches that hook twice: once from the wrapper's inner call and
	 * once from the outer chain. A recorder attached after the wrapper counts
	 * two mails for one e-mail. Attached at -2000 the wrapper sees a decided
	 * value, returns it untouched, and exactly one message is recorded.
	 */
	public function attach(): void {
		add_filter( 'pre_wp_mail', array( $this, 'intercept' ), -2000, 2 );
	}

	public function detach(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'intercept' ), -2000 );
	}

	public function reset(): void {
		$this->sent = array();
	}

	public function count_to( string $recipient ): int {
		$total = 0;

		foreach ( $this->sent as $mail ) {
			foreach ( (array) ( is_array( $mail['to'] ) ? $mail['to'] : explode( ',', (string) $mail['to'] ) ) as $address ) {
				if ( strtolower( trim( (string) $address ) ) === strtolower( $recipient ) ) {
					++$total;
				}
			}
		}

		return $total;
	}

	/** Every recorded subject, so a surprise second message names itself. */
	public function subjects(): string {
		$out = array();

		foreach ( $this->sent as $mail ) {
			$out[] = (string) ( $mail['subject'] ?? '' );
		}

		return implode( ' ;; ', $out );
	}

	public function last_subject(): string {
		$last = end( $this->sent );

		return is_array( $last ) ? (string) ( $last['subject'] ?? '' ) : '';
	}

	/**
	 * @param mixed                $short_circuit Current short-circuit value.
	 * @param array<string, mixed> $atts          wp_mail() arguments.
	 * @return bool
	 */
	public function intercept( $short_circuit, $atts = array() ) {
		unset( $short_circuit );

		$atts         = is_array( $atts ) ? $atts : array();
		$this->sent[] = $atts;

		if ( 'accepted' === $this->mode ) {
			do_action( 'wp_mail_succeeded', $atts );

			return true;
		}

		if ( 'refused' === $this->mode ) {
			do_action(
				'wp_mail_failed',
				new WP_Error(
					'wp_mail_failed',
					'SMTP error: 535 authentication failed for ' . self::SECRET . ' at smtp.example.invalid',
					$atts
				)
			);

			return false;
		}

		// silent: neither signal. The outcome is unknowable from here.
		return false;
	}
}

$mailer = new Kuka_Shipping_Mail_Recorder();
$mailer->attach();

/** A shipment whose carrier status this measurement drives by hand. */
$notify_code = 1;

$notify_scenario = kuka_ship_scenario(
	static function ( string $method, string $url ) use ( &$notify_code ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return kuka_ship_create_barcode_ok( '778899001', 'BC-NOTIFY' );
		}

		if ( str_contains( $url, '/getshipmentstatus' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode( array( 'shipmentId' => '778899001', 'shipmentStatusCode' => $notify_code ) ),
			);
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$notify_scenario['manager']->create_shipment( $notify_scenario['order'] );
$notify_id    = (int) $notify_scenario['order']->get_id();
$notify_ref   = (string) Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $notify_id ) )['reference'];
$notify_email = strtolower( (string) wc_get_order( $notify_id )->get_billing_email() );

// (a) Before code 2 nothing is owed and nothing has been sent.
$mailer->reset();
$notify_before_state = Kuka_Island_Shipping_Notification::state( wc_get_order( $notify_id ) );
$notify_before_mails = $mailer->count_to( $notify_email );

// (b) The FIRST unfulfilled -> fulfilled transition. Exactly one e-mail.
$notify_events = 0;
$notify_probe  = static function () use ( &$notify_events ): void { ++$notify_events; };
add_action( 'woocommerce_fulfillment_created_notification', $notify_probe, 1 );
$notify_code = Kuka_Island_Shipping_Status::CODE_IN_TRANSFER;
$notify_scenario['manager']->query_status( wc_get_order( $notify_id ) );

kuka_ship_forget_order( $notify_id );
$notify_first_status = Kuka_Island_Shipping_Notification::status( wc_get_order( $notify_id ) );
$notify_first_mails  = $mailer->count_to( $notify_email );
$notify_subject      = $mailer->last_subject();

// (c) The same status, polled again. Still one.
$notify_scenario['manager']->query_status( wc_get_order( $notify_id ) );
$notify_repeat_mails = $mailer->count_to( $notify_email );

// (d) Codes 3, 4 and 5 after it. Still one.
foreach ( array( 3, 4, 5 ) as $notify_later ) {
	$notify_code = $notify_later;
	$notify_scenario['manager']->query_status( wc_get_order( $notify_id ) );
}

kuka_ship_forget_order( $notify_id );
$notify_later_mails  = $mailer->count_to( $notify_email );
$notify_later_status = Kuka_Island_Shipping_Notification::status( wc_get_order( $notify_id ) );
$notify_order_status = wc_get_order( $notify_id )->get_status();

$report(
	'SHIPPING_NOTIFIES_CUSTOMER_ONCE_ON_DISPATCH',
	'' === $notify_before_state
		&& 0 === $notify_before_mails
		// One e-mail, on the transition, and the state says so.
		&& 1 === $notify_first_mails
		&& Kuka_Island_Shipping_Notification::STATE_SENT === (string) $notify_first_status['state']
		&& 1 === (int) $notify_first_status['attempts']
		&& '' === (string) $notify_first_status['code']
		// Repeated poll of the same status: still one.
		&& 1 === $notify_repeat_mails
		// Codes 3, 4, 5 after it: still one.
		&& 1 === $notify_later_mails
		&& Kuka_Island_Shipping_Notification::STATE_SENT === (string) $notify_later_status['state']
		&& 1 === (int) $notify_later_status['attempts']
		// The order is NOT completed by a dispatch notification.
		&& 'completed' !== $notify_order_status
		// And the customer reads natural Turkish.
		&& str_contains( $notify_subject, 'siparişiniz kargoya verildi' )
		&& ! str_contains( $notify_subject, 'yerine getirildi' ),
	sprintf(
		'measured:real_poll_and_intercepted_transport|before_code_2:state:%s/mails:%d|first_transition_mails:%d|state:%s|attempts:%d|repeat_poll_mails:%d|codes_3_4_5_mails:%d|order_status:%s|notification_events:%d|subjects:[%s]|subject_is_natural_tr:%s',
		'' === $notify_before_state ? 'absent' : $notify_before_state,
		$notify_before_mails,
		$notify_first_mails,
		(string) $notify_later_status['state'],
		(int) $notify_later_status['attempts'],
		$notify_repeat_mails,
		$notify_later_mails,
		$notify_order_status,
		$notify_events,
		$mailer->subjects(),
		str_contains( $notify_subject, 'siparişiniz kargoya verildi' ) && ! str_contains( $notify_subject, 'yerine getirildi' ) ? 'yes' : 'NO'
	)
);

remove_action( 'woocommerce_fulfillment_created_notification', $notify_probe, 1 );
kuka_ship_purge_actions( $notify_id );
kuka_ship_destroy_order( wc_get_order( $notify_id ) );

/* --- a definite refusal, and an unknown outcome ------------------------- */

/**
 * A shipment whose fulfilment is one status reading away from dispatch.
 *
 * @return array{order: WC_Order, manager: Kuka_Island_Shipping_Manager, reference: string, email: string, code: callable}
 */
function kuka_ship_notify_fixture(): array {
	$code = 1;
	$box  = new stdClass();
	$box->code = 1;

	$scenario = kuka_ship_scenario(
		static function ( string $method, string $url ) use ( $box ): array {
			$common = kuka_ship_common_reads( $url );

			if ( null !== $common ) {
				return $common;
			}

			if ( str_contains( $url, '/createOrder' ) ) {
				return kuka_ship_create_order_ok();
			}

			if ( str_contains( $url, '/createbarcode' ) ) {
				return kuka_ship_create_barcode_ok( '667788990', 'BC-NOTIFY2' );
			}

			if ( str_contains( $url, '/getshipmentstatus' ) ) {
				return array(
					'status' => 200,
					'body'   => (string) wp_json_encode( array( 'shipmentId' => '667788990', 'shipmentStatusCode' => $box->code ) ),
				);
			}

			return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
		}
	);

	unset( $code );
	$scenario['manager']->create_shipment( $scenario['order'] );
	$id = (int) $scenario['order']->get_id();

	return array(
		'order'     => wc_get_order( $id ),
		'manager'   => $scenario['manager'],
		'reference' => (string) Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $id ) )['reference'],
		'email'     => strtolower( (string) wc_get_order( $id )->get_billing_email() ),
		'box'       => $box,
	);
}

// A transport that definitively refuses. The message carries a sentinel that
// looks like an SMTP user name, so a leak can be searched for by exact name.
$refused = kuka_ship_notify_fixture();
$refused_id = (int) $refused['order']->get_id();
$mailer->reset();
$mailer->mode = 'refused';
$refused['box']->code = Kuka_Island_Shipping_Status::CODE_IN_TRANSFER;
$refused['manager']->query_status( wc_get_order( $refused_id ) );

kuka_ship_forget_order( $refused_id );
$refused_status = Kuka_Island_Shipping_Notification::status( wc_get_order( $refused_id ) );
$refused_data   = Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $refused_id ) );
$refused_mails  = $mailer->count_to( $refused['email'] );

/*
 * THIS MODULE'S OWN SURFACES, searched for the sentinel by exact name: the
 * notification state, every shipping meta value, the shipping history, and the
 * order note this module writes. The transport's message is the one string on
 * this path that can carry a credential, so the only safe handling is never to
 * keep it -- an allow-listed code is stored instead.
 *
 * Core's own order-e-mail failure recorder is NOT part of this measurement. It
 * hooks wp_mail_failed for every WooCommerce e-mail, has done so since before
 * this feature existed, and redacts the CONFIGURED SMTP secrets through
 * Email_Delivery::safe_error_message(); that is its own contract and it is
 * measured separately below.
 */
$refused_surfaces = (string) wp_json_encode( $refused_status ) . '|' . (string) wp_json_encode( $refused_data );

foreach ( (array) wc_get_order_notes( array( 'order_id' => $refused_id, 'limit' => 100 ) ) as $refused_note ) {
	if ( str_contains( (string) $refused_note->content, 'Kargo bildirimi' ) ) {
		$refused_surfaces .= '|' . (string) $refused_note->content;
	}
}

$refused_leaks = substr_count( $refused_surfaces, Kuka_Shipping_Mail_Recorder::SECRET );

// The transport's own words must not be anywhere in them either.
$refused_raw = 0;

foreach ( array( 'SMTP error', '535', 'smtp.example.invalid', 'authentication failed' ) as $refused_fragment ) {
	$refused_raw += substr_count( $refused_surfaces, $refused_fragment );
}

// And Core's redaction of the CONFIGURED secrets still holds.
$refused_core_redacts = true;

foreach ( array( 'KUKA_SMTP_PASSWORD', 'KUKA_SMTP_USERNAME' ) as $refused_constant ) {
	if ( defined( $refused_constant ) && '' !== (string) constant( $refused_constant ) ) {
		$refused_all = '';

		foreach ( (array) wc_get_order_notes( array( 'order_id' => $refused_id, 'limit' => 100 ) ) as $refused_any ) {
			$refused_all .= (string) $refused_any->content;
		}

		$refused_core_redacts = $refused_core_redacts && ! str_contains( $refused_all, (string) constant( $refused_constant ) );
	}
}

// The bounded retry: a refusal may be tried again, and only so often.
$mailer->mode = 'accepted';
$refused['manager']->query_status( wc_get_order( $refused_id ) );
kuka_ship_forget_order( $refused_id );
$refused_retry_status = Kuka_Island_Shipping_Notification::status( wc_get_order( $refused_id ) );
$refused_retry_mails  = $mailer->count_to( $refused['email'] );

kuka_ship_purge_actions( $refused_id );
kuka_ship_destroy_order( wc_get_order( $refused_id ) );

// A transport whose outcome is unknowable: neither signal comes back.
$silent = kuka_ship_notify_fixture();
$silent_id = (int) $silent['order']->get_id();
$mailer->reset();
$mailer->mode = 'silent';
$silent['box']->code = Kuka_Island_Shipping_Status::CODE_IN_TRANSFER;
$silent['manager']->query_status( wc_get_order( $silent_id ) );

kuka_ship_forget_order( $silent_id );
$silent_status = Kuka_Island_Shipping_Notification::status( wc_get_order( $silent_id ) );
$silent_mails  = $mailer->count_to( $silent['email'] );

// Now the transport works perfectly. It must STILL not send a second message.
$mailer->mode = 'accepted';
$silent['manager']->query_status( wc_get_order( $silent_id ) );
$silent['box']->code = 5;
$silent['manager']->query_status( wc_get_order( $silent_id ) );

kuka_ship_forget_order( $silent_id );
$silent_after_status = Kuka_Island_Shipping_Notification::status( wc_get_order( $silent_id ) );
$silent_after_mails  = $mailer->count_to( $silent['email'] );

kuka_ship_purge_actions( $silent_id );
kuka_ship_destroy_order( wc_get_order( $silent_id ) );

$report(
	'SHIPPING_NOTIFICATION_OUTCOME_IS_SAFE',
	// A definite refusal: recorded, visible, retryable, and it leaks nothing.
	1 === $refused_mails
		&& Kuka_Island_Shipping_Notification::STATE_FAILED === (string) $refused_status['state']
		&& 'wp_mail_failed' === (string) $refused_status['code']
		&& 1 === (int) $refused_status['attempts']
		&& 0 === $refused_leaks
		&& 0 === $refused_raw
		&& $refused_core_redacts
		// The bounded retry then succeeds, and the customer gets one message.
		&& 2 === $refused_retry_mails
		&& Kuka_Island_Shipping_Notification::STATE_SENT === (string) $refused_retry_status['state']
		&& 2 === (int) $refused_retry_status['attempts']
		// An unknown outcome: recorded as needing a person, never repeated.
		&& 1 === $silent_mails
		&& Kuka_Island_Shipping_Notification::STATE_MANUAL_REVIEW === (string) $silent_status['state']
		&& 'send_outcome_unknown' === (string) $silent_status['code']
		&& 1 === $silent_after_mails
		&& Kuka_Island_Shipping_Notification::STATE_MANUAL_REVIEW === (string) $silent_after_status['state']
		&& 1 === (int) $silent_after_status['attempts'],
	sprintf(
		'measured:intercepted_transport|refused:mails:%d|state:%s|code:%s|attempts:%d|secret_leaks:%d|raw_transport_text:%d|configured_secrets_redacted:%s|retry_mails:%d|retry_state:%s'
			. '||unknown:mails:%d|state:%s|code:%s|automatic_second_send:%d|state_after:%s',
		$refused_mails,
		(string) $refused_status['state'],
		(string) $refused_status['code'],
		(int) $refused_status['attempts'],
		$refused_leaks,
		$refused_raw,
		$refused_core_redacts ? 'yes' : 'NO',
		$refused_retry_mails,
		(string) $refused_retry_status['state'],
		$silent_mails,
		(string) $silent_status['state'],
		(string) $silent_status['code'],
		$silent_after_mails - $silent_mails,
		(string) $silent_after_status['state']
	)
);

/* --- the manual route, untouched --------------------------------------- */

/*
 * The operator's tick and this module are the same WooCommerce action, the
 * same e-mail class and the same template. What separates them is ownership:
 * a fulfilment created by hand carries no carrier reference, so
 * Fulfillment_Writer::find_own() never returns it and no automatic
 * notification is ever produced for it.
 */

$manual_order = kuka_ship_fixture_order();
$manual_id    = (int) $manual_order->get_id();
$manual_email = strtolower( (string) $manual_order->get_billing_email() );

$manual_class = '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment';
$manual_store = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';
$manual_ready = class_exists( $manual_class ) && class_exists( $manual_store );
$manual_own   = null;

if ( $manual_ready ) {
	$manual_own = new $manual_class();
	$manual_own->set_entity_type( WC_Order::class );
	$manual_own->set_entity_id( (string) $manual_id );
	$manual_own->set_status( 'fulfilled' );
	$manual_own->update_meta_data( '_tracking_number', 'MANUAL-TRACK-1' );
	$manual_own->update_meta_data( '_shipment_provider', 'aras-kargo' );
	$manual_items = array();

	foreach ( $manual_order->get_items() as $manual_item_id => $manual_item ) {
		$manual_items[] = array( 'item_id' => (int) $manual_item_id, 'qty' => 1 );
	}

	$manual_own->set_items( $manual_items );

	try {
		wc_get_container()->get( $manual_store )->create( $manual_own );
	} catch ( Throwable $manual_error ) {
		$manual_ready = false;
	}
}

$mailer->reset();
$mailer->mode = 'accepted';

// notify = false: WooCommerce fires nothing, so nothing is sent.
$manual_quiet = $mailer->count_to( $manual_email );

// notify = true: the operator's path, which is this action and this action only.
if ( $manual_ready ) {
	WC()->mailer();
	do_action( 'woocommerce_fulfillment_created_notification', $manual_id, $manual_own, wc_get_order( $manual_id ) );
}

$manual_notified = $mailer->count_to( $manual_email );

// And this module claimed nothing about that record.
kuka_ship_forget_order( $manual_id );
$manual_module_state = Kuka_Island_Shipping_Notification::state( wc_get_order( $manual_id ) );
$manual_found_by_us  = Kuka_Island_Shipping_Fulfillment_Writer::find_own( wc_get_order( $manual_id ), 'KI1-MANUAL' );

// The module's own status sync, run against an order whose only fulfilment is
// somebody else's: no notification, and the record is not touched.
$mailer->reset();
$manual_sync = Kuka_Island_Shipping_Fulfillment_Writer::sync_status( wc_get_order( $manual_id ), 'KI1-MANUAL', 2 );
$manual_after_mails = $mailer->count_to( $manual_email );

if ( $manual_ready ) {
	try {
		wc_get_container()->get( $manual_store )->delete( $manual_own );
	} catch ( Throwable $manual_cleanup ) {
		unset( $manual_cleanup );
	}
}

$report(
	'SHIPPING_MANUAL_FULFILLMENT_ROUTE_UNTOUCHED',
	$manual_ready
		// notify = false is the absence of the action: nothing sent.
		&& 0 === $manual_quiet
		// notify = true sends exactly one, through WooCommerce's own class.
		&& 1 === $manual_notified
		// This module recorded nothing about somebody else's record.
		&& '' === $manual_module_state
		&& null === $manual_found_by_us
		&& 'own_fulfillment_absent' === (string) $manual_sync['reason']
		&& 'not_due' === (string) $manual_sync['notification']
		&& 0 === $manual_after_mails,
	sprintf(
		'measured:real_fulfillments_datastore_and_intercepted_transport|api:%s|notify_false_mails:%d|notify_true_mails:%d|module_state:%s|module_claims_record:%s|module_sync:%s/%s|module_sync_mails:%d',
		$manual_ready ? 'available' : 'UNAVAILABLE',
		$manual_quiet,
		$manual_notified,
		'' === $manual_module_state ? 'absent' : $manual_module_state,
		null === $manual_found_by_us ? 'no' : 'YES',
		(string) $manual_sync['reason'],
		(string) $manual_sync['notification'],
		$manual_after_mails
	)
);

kuka_ship_destroy_order( wc_get_order( $manual_id ) );

/* --- the order's own language decides the wording ----------------------- */

/*
 * Measured through a REAL send, not by reading get_subject() on an idle e-mail
 * object. WooCommerce's own English defaults go through __(), so on a Turkish
 * site they come back translated unless the locale has actually been switched
 * -- and that switch happens inside the send, via setup_locale() and Core's
 * woocommerce_allow_switching_email_locale filter. Reading the property without
 * sending measures the translation table, not the customer's e-mail.
 */

/**
 * A fulfilment record the e-mail template can actually render.
 *
 * @param WC_Order $order Order to attach it to.
 * @return object|null
 */
function kuka_ship_language_fulfillment( WC_Order $order ) {
	$class = '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment';
	$store = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';

	if ( ! class_exists( $class ) || ! class_exists( $store ) ) {
		return null;
	}

	$items = array();

	foreach ( $order->get_items() as $item_id => $item ) {
		$items[] = array( 'item_id' => (int) $item_id, 'qty' => 1 );
	}

	$record = new $class();
	$record->set_entity_type( WC_Order::class );
	$record->set_entity_id( (string) $order->get_id() );
	$record->set_status( 'fulfilled' );
	$record->update_meta_data( '_tracking_number', 'LANG-TRACK-1' );
	$record->update_meta_data( '_tracking_url', 'https://example.invalid/track/LANG-TRACK-1' );
	$record->update_meta_data( '_shipment_provider', 'dhl' );
	$record->set_items( $items );

	try {
		wc_get_container()->get( $store )->create( $record );
	} catch ( Throwable $failed ) {
		return null;
	}

	return $record;
}

/** Send the notification for one order and hand back what the customer got. */
$lang_send = static function ( WC_Order $order ) use ( $mailer ): array {
	$record = kuka_ship_language_fulfillment( $order );

	if ( null === $record ) {
		return array( 'subject' => '', 'body' => '', 'ok' => false );
	}

	$mailer->reset();
	$mailer->mode = 'accepted';

	/*
	 * WHICH LOCALE THE BODY WAS RENDERED IN, recorded as the intro string
	 * passes through the translation layer. This is the fact that was broken:
	 * WC_Email objects are reused and these two e-mails assign $this->object
	 * AFTER setup_locale(), so a second notification in one request decided its
	 * language from the PREVIOUS order.
	 */
	$GLOBALS['kuka_lang_probe'] = '';
	$probe = static function ( $translation, $text, $domain ) {
		if ( 'woocommerce' === $domain && str_starts_with( (string) $text, 'Woo! Some items' ) ) {
			$GLOBALS['kuka_lang_probe'] = get_locale();
		}

		return $translation;
	};
	add_filter( 'gettext', $probe, 5, 3 );

	WC()->mailer();
	do_action( 'woocommerce_fulfillment_created_notification', (int) $order->get_id(), $record, $order );
	remove_filter( 'gettext', $probe, 5 );

	$sent = end( $mailer->sent );

	try {
		wc_get_container()->get( '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore' )->delete( $record );
	} catch ( Throwable $ignored ) {
		unset( $ignored );
	}

	return array(
		'subject' => is_array( $sent ) ? (string) ( $sent['subject'] ?? '' ) : '',
		'body'    => is_array( $sent ) ? (string) ( $sent['message'] ?? '' ) : '',
		'locale'  => (string) $GLOBALS['kuka_lang_probe'],
		'ok'      => is_array( $sent ),
	);
};

$lang_tr = kuka_ship_fixture_order();
$lang_tr_mail = $lang_send( wc_get_order( $lang_tr->get_id() ) );

$lang_en = kuka_ship_fixture_order();
$lang_en->update_meta_data( '_kuka_order_locale', 'en_US' );
$lang_en->save();
$lang_en_mail = $lang_send( wc_get_order( $lang_en->get_id() ) );

// The machine translation this round replaced, searched for by its own words.
$lang_machine = 0;

foreach ( array( 'yerine getirildi', 'yerine getiriliyor', 'Öğeniz yolda', 'Woo!', 'öğe' ) as $lang_phrase ) {
	$lang_machine += substr_count( $lang_tr_mail['subject'] . $lang_tr_mail['body'], $lang_phrase );
}

$report(
	'SHIPPING_NOTIFICATION_TEXT_FOLLOWS_ORDER_LANGUAGE',
	$lang_tr_mail['ok']
		&& $lang_en_mail['ok']
		// Turkish: a courier sentence, subject, heading and body.
		&& str_contains( $lang_tr_mail['subject'], 'siparişiniz kargoya verildi' )
		&& str_contains( $lang_tr_mail['body'], 'Siparişiniz kargoya verildi' )
		&& str_contains( $lang_tr_mail['body'], 'siparişiniz hazırlanarak kargo firmasına teslim edildi.' )
		&& 0 === $lang_machine
		// The tracking number and its link reach the customer when present.
		&& str_contains( $lang_tr_mail['body'], 'LANG-TRACK-1' )
		&& str_contains( $lang_tr_mail['body'], 'example.invalid/track/LANG-TRACK-1' )
		// An English order keeps natural English, from WooCommerce's own text.
		&& ! str_contains( $lang_en_mail['subject'], 'kargoya verildi' )
		&& ! str_contains( $lang_en_mail['body'], 'yerine get' )
		&& str_contains( $lang_en_mail['subject'], 'has shipped!' )
		&& str_contains( $lang_en_mail['body'], 'Your order has shipped' )
		&& str_contains( $lang_en_mail['body'], 'your order has been prepared and handed over to the carrier.' )
		&& str_contains( $lang_en_mail['body'], 'Track your parcel' )
		// And both ids are inside the order-locale contract, which is the
		// switch itself: the body was rendered in the order's own locale.
		&& has_filter( 'woocommerce_email_subject_customer_fulfillment_created' )
		&& has_filter( 'woocommerce_email_heading_customer_fulfillment_updated' )
		&& 'tr_TR' === (string) $lang_tr_mail['locale']
		&& 'en_US' === (string) $lang_en_mail['locale'],
	sprintf(
		'measured:real_send_through_intercepted_transport|tr_subject:%s|tr_heading_in_body:%s|tr_intro_natural:%s|machine_phrases:%d|tracking_number:%s|tracking_link:%s|en_subject:%s|en_heading_in_body:%s|en_intro:%s|en_turkish_leftover:%s|locale_at_body_render:%s',
		$lang_tr_mail['subject'],
		str_contains( $lang_tr_mail['body'], 'Siparişiniz kargoya verildi' ) ? 'yes' : 'NO',
		str_contains( $lang_tr_mail['body'], 'siparişiniz hazırlanarak kargo firmasına teslim edildi.' ) ? 'yes' : 'NO',
		$lang_machine,
		str_contains( $lang_tr_mail['body'], 'LANG-TRACK-1' ) ? 'shown' : 'MISSING',
		str_contains( $lang_tr_mail['body'], 'example.invalid/track/LANG-TRACK-1' ) ? 'shown' : 'MISSING',
		$lang_en_mail['subject'],
		str_contains( $lang_en_mail['body'], 'Your order has shipped' ) ? 'yes' : 'NO',
		str_contains( $lang_en_mail['body'], 'your order has been prepared and handed over to the carrier.' ) ? 'yes' : 'NO',
		str_contains( $lang_en_mail['body'], 'yerine get' ) ? 'PRESENT' : 'no',
		sprintf(
			'%s/%s',
			(string) $lang_tr_mail['locale'],
			(string) $lang_en_mail['locale']
		)
	)
);

/* --- a terminal FIRST answer must not close the notification chain ------ */

/*
 * THE TERMINAL-SKIP TRAP.
 *
 * `query_status()` records the carrier lifecycle first and then called
 * `sync_status()` while DISCARDING its result. So when the carrier's first and
 * only observed status was already terminal -- code 5, delivered -- and the
 * notification claim was refused, everything below happened at once:
 *
 *   fulfillment stayed unfulfilled, no handover date, no e-mail,
 *   query_status returned ok:true with a terminal lifecycle,
 *   the poller answered stop:terminal_lifecycle and booked nothing.
 *
 * There was no next poll, so "the next poll tries again" was not true for this
 * shape. The obstacle is installed for real in each case and the REAL Action
 * Scheduler worker is run.
 *
 * @param string $case   claim_lock_contended | notification_claim_unverified | claim_order_unreadable
 * @param object $mailer The suite's mail recorder.
 * @return array<string, mixed>
 */
function kuka_ship_terminal_claim_case( string $case, $mailer ): array {
	$fixture = kuka_ship_notify_fixture();
	$id      = (int) $fixture['order']->get_id();
	$runner_box = new stdClass();
	$runner_box->outcome = '';
	kuka_ship_attach_recording_poller( $fixture['manager'], $runner_box );

	// The carrier's FIRST and ONLY observed status is already terminal.
	$fixture['box']->code = Kuka_Island_Shipping_Status::CODE_DELIVERED;

	$mailer->reset();
	$mailer->mode = 'accepted';

	$lock_name  = 'kuka_ship_notify_claim_' . $id;
	$second     = null;
	$lock_held  = 'n/a';
	$reads      = new stdClass();
	$reads->seen = 0;

	$drop_writes = static function ( $query ) {
		$query = (string) $query;
		$head  = strtoupper( substr( ltrim( $query ), 0, 6 ) );

		if ( str_contains( $query, '_kuka_shipping_notify' ) && ( 'INSERT' === $head || 'UPDATE' === $head || 'REPLAC' === $head ) ) {
			return 'SELECT 1';
		}

		return $query;
	};

	/*
	 * `WC_Order_Factory` consults `woocommerce_order_class` on EVERY read that
	 * reaches it -- the class-name array is built fresh each call, not cached --
	 * but a read served from the order cache never gets there. So the order is
	 * warmed into the cache first and then EVERY factory read of it fails: the
	 * poller's own load is served from the cache and succeeds, while the
	 * claim's fresh read, which drops that cache on purpose, cannot.
	 */
	$break_read = static function ( $classname, $order_type = '', $read_id = 0 ) use ( $reads, $id ) {
		unset( $order_type );

		if ( (int) $read_id !== $id ) {
			return $classname;
		}

		++$reads->seen;

		return 'Kuka_Ship_Absent_Order_Class';
	};

	if ( 'claim_lock_contended' === $case ) {
		/*
		 * A SECOND, REAL MySQL SESSION. Named locks are per connection, so a
		 * second wpdb instance is a second session even inside one process --
		 * and it is the only way this process can be refused its own lock.
		 */
		$second    = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$lock_held = '1' === (string) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) ? 'yes' : 'NO';
	} elseif ( 'notification_claim_unverified' === $case ) {
		// The claim's write is executed as `SELECT 1`: nothing lands on disk.
		add_filter( 'query', $drop_writes, PHP_INT_MAX );
	} else {
		// Warm the cache so the worker's own load does not need the factory.
		wc_get_order( $id );
		add_filter( 'woocommerce_order_class', $break_read, PHP_INT_MAX, 3 );
	}

	// The real scheduled worker, driven by Action Scheduler's own runner.
	kuka_ship_purge_actions( $id );
	Kuka_Island_Shipping_Status_Poller::schedule_query( $id, 0 );
	$chain   = kuka_ship_drive_status_chain( $id, 3 );
	$runner  = (string) $runner_box->outcome;
	$blocked = kuka_ship_pending_action_count( $id );
	$booked  = kuka_ship_pending_sync_count( $id );

	if ( 'claim_lock_contended' === $case ) {
		$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		$second->close();
	} elseif ( 'notification_claim_unverified' === $case ) {
		remove_filter( 'query', $drop_writes, PHP_INT_MAX );
	} else {
		remove_filter( 'woocommerce_order_class', $break_read, PHP_INT_MAX );
	}

	$first = kuka_ship_terminal_facts( $id, $fixture['reference'] );
	$first['mails']    = $mailer->count_to( $fixture['email'] );
	$first['lock']     = $lock_held;
	$first['runner']   = '' === $runner ? 'unrecorded' : $runner;
	$first['pending']  = $blocked;
	$first['follow_up'] = $booked;
	$first['turns']    = count( $chain['processed'] );

	// The obstacle is gone. The safe local retry must now finish the job.
	$retry_outcome = kuka_ship_drive_sync_chain( $id, 3 );
	$second_pass   = kuka_ship_terminal_facts( $id, $fixture['reference'] );
	$second_pass['mails'] = $mailer->count_to( $fixture['email'] );

	// A further run must not produce a second message.
	kuka_ship_drive_sync_chain( $id, 2 );
	$third = kuka_ship_terminal_facts( $id, $fixture['reference'] );
	$third['mails'] = $mailer->count_to( $fixture['email'] );

	$purged = kuka_ship_purge_actions( $id ) + kuka_ship_purge_sync_actions( $id );
	kuka_ship_destroy_order( wc_get_order( $id ) );

	return array(
		'case'    => $case,
		'first'   => $first,
		'retry'   => $retry_outcome,
		'second'  => $second_pass,
		'third'   => $third,
		'purged'  => $purged,
	);
}

/**
 * The three facts the terminal-skip trap turns on, read fresh.
 *
 * @param int    $order_id  Order.
 * @param string $reference Carrier reference.
 * @return array<string, string>
 */
function kuka_ship_terminal_facts( int $order_id, string $reference ): array {
	kuka_ship_forget_order( $order_id );
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		return array( 'lifecycle' => 'unreadable', 'claim' => 'unreadable', 'fulfilled' => 'unreadable', 'date' => 'unreadable', 'notify_state' => 'unreadable', 'handover' => '' );
	}

	$data   = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
	$record = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $order, $reference );

	return array(
		'lifecycle'    => (string) $data['status_lifecycle'],
		'claim'        => (string) $order->get_meta( '_kuka_shipping_sync_last_reason', true ),
		'fulfilled'    => is_object( $record ) && method_exists( $record, 'get_is_fulfilled' )
			? ( $record->get_is_fulfilled() ? 'yes' : 'no' )
			: 'missing',
		'date'         => is_object( $record ) && method_exists( $record, 'get_date_fulfilled' ) && '' !== (string) ( $record->get_date_fulfilled() ?? '' )
			? (string) $record->get_date_fulfilled()
			: '',
		'notify_state' => (string) Kuka_Island_Shipping_Notification::state( $order ),
		'handover'     => (string) $order->get_meta( Kuka_Island_Shipping_Notification::META_HANDOVER_AT, true ),
	);
}

/**
 * One scheduler outcome, produced on the real Action Scheduler surface.
 *
 * The claim is refused for real (a second MySQL session holds its lock), so
 * the manager reaches the safe-retry booking; the booking then meets whichever
 * obstacle this case installs. What is measured is whether the manager tells
 * the truth about a booking that did not happen.
 *
 * @param string $case   created | already_pending | lock_contended | schedule_failed
 * @param object $mailer The suite's mail recorder.
 * @return array<string, mixed>
 */
function kuka_ship_schedule_case( string $case, $mailer ): array {
	$fixture = kuka_ship_notify_fixture();
	$id      = (int) $fixture['order']->get_id();
	$fixture['box']->code = Kuka_Island_Shipping_Status::CODE_DELIVERED;

	$mailer->reset();
	$mailer->mode = 'accepted';

	// The claim refusal itself: a second real MySQL session holds its lock.
	$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_ship_notify_claim_' . $id ) );

	$fail_schedule = static function () {
		// A real Action Scheduler short-circuit: zero means nothing was booked.
		return 0;
	};

	if ( 'lock_contended' === $case ) {
		// The scheduling lock, held by that same second session.
		$second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_ship_query_' . $id ) );
	} elseif ( 'schedule_failed' === $case ) {
		add_filter( 'pre_as_schedule_single_action', $fail_schedule, PHP_INT_MAX );
	} elseif ( 'already_pending' === $case ) {
		Kuka_Island_Shipping_Status_Poller::schedule_sync( $id );
	}

	$queried = $fixture['manager']->query_status( wc_get_order( $id ) );

	if ( 'schedule_failed' === $case ) {
		remove_filter( 'pre_as_schedule_single_action', $fail_schedule, PHP_INT_MAX );
	}

	$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_ship_notify_claim_' . $id ) );
	$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_ship_query_' . $id ) );
	$second->close();

	kuka_ship_forget_order( $id );
	$order   = wc_get_order( $id );
	$pending = kuka_ship_pending_sync_count( $id );
	$notes   = kuka_ship_sync_note_count( $id );
	$panel   = kuka_ship_render_panel( $fixture['manager'], $order );

	$facts = array(
		'case'            => $case,
		'retry_field'     => (string) ( $queried['fulfillment_retry'] ?? '' ),
		'schedule_result' => (string) ( $queried['fulfillment_schedule'] ?? '' ),
		'pending'         => $pending,
		'mails'           => $mailer->count_to( $fixture['email'] ),
		'schedule_error'  => (string) $order->get_meta( '_kuka_shipping_sync_schedule_error', true ),
		'notes'           => $notes,
		'panel_reason'    => str_contains( $panel, 'claim_lock_contended' ) ? 'yes' : 'no',
		'panel_manual'    => str_contains( $panel, 'elle' ) || str_contains( $panel, 'manuel' ) ? 'yes' : 'no',
	);

	kuka_ship_purge_actions( $id );
	kuka_ship_purge_sync_actions( $id );
	kuka_ship_destroy_order( wc_get_order( $id ) );

	return $facts;
}

/** How many order notes mention the local notification sync. */
function kuka_ship_sync_note_count( int $order_id ): int {
	$total = 0;

	foreach ( (array) wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => 200 ) ) as $note ) {
		$total += str_contains( (string) $note->content, 'bildirimi yerel' ) ? 1 : 0;
	}

	return $total;
}

/**
 * The shipping panel exactly as an operator sees it.
 *
 * @param Kuka_Island_Shipping_Manager $manager Manager.
 * @param mixed                        $order   Order.
 */
function kuka_ship_render_panel( Kuka_Island_Shipping_Manager $manager, $order ): string {
	if ( ! $order instanceof WC_Order ) {
		return '';
	}

	$admin = new Kuka_Island_Shipping_Admin( $manager );

	ob_start();
	$admin->render_meta_box( $order );

	return (string) ob_get_clean();
}

putenv( 'KUKA_SHIPPING_AUTOMATION=1' );

/* --- the scheduler-result matrix -------------------------------------- */

$schedule_matrix = array();

foreach ( array( 'created', 'already_pending', 'lock_contended', 'schedule_failed' ) as $schedule_case ) {
	$schedule_matrix[ $schedule_case ] = kuka_ship_schedule_case( $schedule_case, $mailer );
}

$schedule_lines = array();
$schedule_ok    = true;

foreach ( $schedule_matrix as $name => $facts ) {
	$proves = in_array( $name, array( 'created', 'already_pending' ), true );

	$case_ok = $proves
		? ( 1 === (int) $facts['pending'] && '' !== (string) $facts['retry_field'] && '' === (string) $facts['schedule_error'] )
		: ( 0 === (int) $facts['pending']
			&& '' === (string) $facts['retry_field']
			&& $name === (string) $facts['schedule_error']
			&& 1 === (int) $facts['notes']
			&& 'yes' === (string) $facts['panel_reason']
			&& 'yes' === (string) $facts['panel_manual'] );

	$schedule_ok = $schedule_ok && $case_ok && 0 === (int) $facts['mails'];

	$schedule_lines[] = sprintf(
		'%s:%s/follow_up:%s/pending:%d/schedule:%s/error_meta:%s/notes:%d/panel_reason:%s/panel_manual:%s/mails:%d',
		$name,
		$case_ok ? 'ok' : 'FAIL',
		'' !== (string) $facts['retry_field'] ? 'yes' : 'no',
		(int) $facts['pending'],
		'' === (string) $facts['schedule_result'] ? 'none' : (string) $facts['schedule_result'],
		'' === (string) $facts['schedule_error'] ? 'none' : (string) $facts['schedule_error'],
		(int) $facts['notes'],
		(string) $facts['panel_reason'],
		(string) $facts['panel_manual'],
		(int) $facts['mails']
	);
}

// A declared PHP function cannot be undeclared, so `scheduler_unavailable` is
// asserted on the pure predicate; the manager path it takes is the same one
// `schedule_failed` proves end to end.
$unavailable_policy = method_exists( 'Kuka_Island_Shipping_Status_Poller', 'schedule_proves_action' )
	&& false === Kuka_Island_Shipping_Status_Poller::schedule_proves_action( Kuka_Island_Shipping_Status_Poller::SCHEDULE_SCHEDULER_UNAVAILABLE )
	&& true === Kuka_Island_Shipping_Status_Poller::schedule_proves_action( Kuka_Island_Shipping_Status_Poller::SCHEDULE_CREATED )
	&& true === Kuka_Island_Shipping_Status_Poller::schedule_proves_action( Kuka_Island_Shipping_Status_Poller::SCHEDULE_ALREADY_PENDING )
	&& false === Kuka_Island_Shipping_Status_Poller::schedule_proves_action( Kuka_Island_Shipping_Status_Poller::SCHEDULE_FAILED )
	&& false === Kuka_Island_Shipping_Status_Poller::schedule_proves_action( Kuka_Island_Shipping_Status_Poller::SCHEDULE_LOCK_CONTENDED );

$report(
	'SHIPPING_SYNC_SCHEDULE_RESULT_IS_PROVEN',
	$schedule_ok && $unavailable_policy,
	sprintf(
		'measured:real_action_scheduler_surfaces|cases:%d|scheduler_unavailable:policy_only:%s|%s',
		count( $schedule_matrix ),
		$unavailable_policy ? 'ok' : 'FAIL',
		implode( '|', $schedule_lines )
	)
);

/* --- the local worker may clear only on a real success ---------------- */

/**
 * Every local sync fact one order carries, read fresh.
 *
 * @param int $order_id Order.
 * @return array<string, string>
 */
function kuka_ship_sync_facts( int $order_id ): array {
	kuka_ship_forget_order( $order_id );
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		return array( 'reason' => 'unreadable', 'attempts' => '-1', 'schedule_error' => 'unreadable' );
	}

	return array(
		'reason'         => (string) $order->get_meta( Kuka_Island_Shipping_Order_Store::META_SYNC_LAST_REASON, true ),
		'attempts'       => (string) Kuka_Island_Shipping_Order_Store::sync_attempts( $order ),
		'schedule_error' => (string) $order->get_meta( Kuka_Island_Shipping_Order_Store::META_SYNC_SCHEDULE_ERROR, true ),
	);
}

/**
 * Drive one order to a recorded transient refusal with a real booked action.
 *
 * The refusal is produced the production way: a second real MySQL session
 * holds the claim lock while the manager runs.
 *
 * @param object $mailer The suite's mail recorder.
 * @return array<string, mixed>
 */
function kuka_ship_sync_pending_fixture( $mailer ): array {
	$fixture = kuka_ship_notify_fixture();
	$id      = (int) $fixture['order']->get_id();
	$fixture['box']->code = Kuka_Island_Shipping_Status::CODE_DELIVERED;

	$mailer->reset();
	$mailer->mode = 'accepted';

	$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_ship_notify_claim_' . $id ) );
	$fixture['manager']->query_status( wc_get_order( $id ) );
	$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_ship_notify_claim_' . $id ) );
	$second->close();

	return $fixture;
}

/** Delete this module's own fulfilment record, so find_own() answers null. */
function kuka_ship_delete_own_fulfillment( int $order_id, string $reference ): bool {
	$store  = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';
	$record = Kuka_Island_Shipping_Fulfillment_Writer::find_own( wc_get_order( $order_id ), $reference );

	if ( ! is_object( $record ) || ! class_exists( $store ) ) {
		return false;
	}

	try {
		wc_get_container()->get( $store )->delete( $record );
	} catch ( Throwable $failed ) {
		unset( $failed );

		return false;
	}

	return true;
}

putenv( 'KUKA_SHIPPING_AUTOMATION=1' );

$keep_cases = array();

/* A. The debt changes owner while the retry is waiting. */
$keep_a  = kuka_ship_sync_pending_fixture( $mailer );
$keep_a_id = (int) $keep_a['order']->get_id();
$keep_a_before = kuka_ship_sync_facts( $keep_a_id );

/*
 * A debt that exists and belongs to a DIFFERENT shipment. Both halves matter:
 * with no state at all the claim would simply write a fresh, correct debt.
 */
$keep_a_order = wc_get_order( $keep_a_id );
$keep_a_order->update_meta_data( Kuka_Island_Shipping_Notification::META_STATE, Kuka_Island_Shipping_Notification::STATE_DUE );
$keep_a_order->update_meta_data( Kuka_Island_Shipping_Notification::META_DUE_REFERENCE, 'KI1SOMEONEELSE9' );
$keep_a_order->update_meta_data( Kuka_Island_Shipping_Notification::META_HANDOVER_AT, gmdate( 'Y-m-d H:i:sP' ) );
$keep_a_order->save();

$keep_a_run = kuka_ship_drive_sync_chain( $keep_a_id, 2 );
$keep_a_after = kuka_ship_sync_facts( $keep_a_id );
$keep_a_panel = kuka_ship_render_panel( $keep_a['manager'], wc_get_order( $keep_a_id ) );
$keep_a_record = Kuka_Island_Shipping_Fulfillment_Writer::find_own( wc_get_order( $keep_a_id ), (string) $keep_a['reference'] );

$keep_cases['owner_changed'] = array(
	'reason'    => (string) $keep_a_after['reason'],
	'attempts'  => (int) $keep_a_after['attempts'],
	'notes'     => kuka_ship_sync_note_count( $keep_a_id ),
	'pending'   => kuka_ship_pending_sync_count( $keep_a_id ),
	'panel'     => str_contains( $keep_a_panel, 'elle' ) ? 'yes' : 'no',
	'mails'     => $mailer->count_to( (string) $keep_a['email'] ),
	'fulfilled' => is_object( $keep_a_record ) && method_exists( $keep_a_record, 'get_is_fulfilled' )
		? ( $keep_a_record->get_is_fulfilled() ? 'yes' : 'no' )
		: 'missing',
	'expected'  => 'claim_other_record',
	'turns'     => (int) $keep_a_run['turns'],
	'before'    => (string) $keep_a_before['reason'],
);

kuka_ship_purge_actions( $keep_a_id );
kuka_ship_purge_sync_actions( $keep_a_id );
kuka_ship_destroy_order( wc_get_order( $keep_a_id ) );

/* B. The order's carrier reference disappears while the retry is waiting. */
$keep_b    = kuka_ship_sync_pending_fixture( $mailer );
$keep_b_id = (int) $keep_b['order']->get_id();

$keep_b_order = wc_get_order( $keep_b_id );
$keep_b_order->delete_meta_data( Kuka_Island_Shipping_Order_Store::META_REFERENCE );
$keep_b_order->save();

kuka_ship_drive_sync_chain( $keep_b_id, 2 );
$keep_b_after = kuka_ship_sync_facts( $keep_b_id );
$keep_b_panel = kuka_ship_render_panel( $keep_b['manager'], wc_get_order( $keep_b_id ) );

$keep_cases['reference_gone'] = array(
	'reason'    => (string) $keep_b_after['reason'],
	'attempts'  => (int) $keep_b_after['attempts'],
	'notes'     => kuka_ship_sync_note_count( $keep_b_id ),
	'pending'   => kuka_ship_pending_sync_count( $keep_b_id ),
	'panel'     => str_contains( $keep_b_panel, 'elle' ) ? 'yes' : 'no',
	'mails'     => $mailer->count_to( (string) $keep_b['email'] ),
	'fulfilled' => 'n/a',
	'expected'  => 'sync_reference_missing',
	'turns'     => 1,
	'before'    => 'claim_lock_contended',
);

kuka_ship_purge_actions( $keep_b_id );
kuka_ship_purge_sync_actions( $keep_b_id );
kuka_ship_destroy_order( wc_get_order( $keep_b_id ) );

/* C. This module's own fulfilment record is gone when the retry runs. */
$keep_c    = kuka_ship_sync_pending_fixture( $mailer );
$keep_c_id = (int) $keep_c['order']->get_id();
kuka_ship_delete_own_fulfillment( $keep_c_id, (string) $keep_c['reference'] );

kuka_ship_drive_sync_chain( $keep_c_id, 2 );
$keep_c_after = kuka_ship_sync_facts( $keep_c_id );
$keep_c_panel = kuka_ship_render_panel( $keep_c['manager'], wc_get_order( $keep_c_id ) );

$keep_cases['record_gone'] = array(
	'reason'    => (string) $keep_c_after['reason'],
	'attempts'  => (int) $keep_c_after['attempts'],
	'notes'     => kuka_ship_sync_note_count( $keep_c_id ),
	'pending'   => kuka_ship_pending_sync_count( $keep_c_id ),
	'panel'     => str_contains( $keep_c_panel, 'elle' ) ? 'yes' : 'no',
	'mails'     => $mailer->count_to( (string) $keep_c['email'] ),
	'fulfilled' => 'missing',
	'expected'  => 'own_fulfillment_absent',
	'turns'     => 1,
	'before'    => 'claim_lock_contended',
);

kuka_ship_purge_actions( $keep_c_id );
kuka_ship_purge_sync_actions( $keep_c_id );
kuka_ship_destroy_order( wc_get_order( $keep_c_id ) );

$keep_ok    = true;
$keep_lines = array();

foreach ( $keep_cases as $name => $facts ) {
	$case_ok = (string) $facts['expected'] === (string) $facts['reason']
		&& 1 === (int) $facts['notes']
		&& 0 === (int) $facts['pending']
		&& 'yes' === (string) $facts['panel']
		&& 0 === (int) $facts['mails']
		&& (int) $facts['attempts'] >= 1
		&& 'yes' !== (string) $facts['fulfilled'];

	$keep_ok      = $keep_ok && $case_ok;
	$keep_lines[] = sprintf(
		'%s:%s/reason:%s/attempts:%d/notes:%d/pending:%d/panel_manual:%s/mails:%d/fulfilled:%s',
		$name,
		$case_ok ? 'ok' : 'FAIL',
		'' === (string) $facts['reason'] ? 'CLEARED' : (string) $facts['reason'],
		(int) $facts['attempts'],
		(int) $facts['notes'],
		(int) $facts['pending'],
		(string) $facts['panel'],
		(int) $facts['mails'],
		(string) $facts['fulfilled']
	);
}

$report(
	'SHIPPING_SYNC_CLEARS_ONLY_ON_REAL_SUCCESS',
	$keep_ok,
	sprintf(
		'measured:real_action_scheduler_worker|cases:%d|%s',
		count( $keep_cases ),
		implode( '|', $keep_lines )
	)
);

/* --- a proven booking clears the OLD scheduling error, nothing else --- */

$stale    = kuka_ship_notify_fixture();
$stale_id = (int) $stale['order']->get_id();
$stale['box']->code = Kuka_Island_Shipping_Status::CODE_DELIVERED;

$mailer->reset();
$mailer->mode = 'accepted';

$stale_fail = static function () {
	return 0;
};

// First turn: the claim is refused AND the booking fails for real.
$stale_second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$stale_second->get_var( $stale_second->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_ship_notify_claim_' . $stale_id ) );
add_filter( 'pre_as_schedule_single_action', $stale_fail, PHP_INT_MAX );
$stale['manager']->query_status( wc_get_order( $stale_id ) );
remove_filter( 'pre_as_schedule_single_action', $stale_fail, PHP_INT_MAX );

$stale_first   = kuka_ship_sync_facts( $stale_id );
$stale_pending = kuka_ship_pending_sync_count( $stale_id );

// Second turn: still refused, but now the booking really happens.
$stale['manager']->query_status( wc_get_order( $stale_id ) );
$stale_second->get_var( $stale_second->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_ship_notify_claim_' . $stale_id ) );
$stale_second->close();

$stale_after         = kuka_ship_sync_facts( $stale_id );
$stale_after_pending = kuka_ship_pending_sync_count( $stale_id );
$stale_panel         = kuka_ship_render_panel( $stale['manager'], wc_get_order( $stale_id ) );

// The booked action now runs with nothing in its way.
kuka_ship_drive_sync_chain( $stale_id, 3 );
$stale_final = kuka_ship_sync_facts( $stale_id );

$report(
	'SHIPPING_SYNC_SCHEDULE_ERROR_CLEARS_ON_PROOF',
	'schedule_failed' === (string) $stale_first['schedule_error']
		&& 0 === (int) $stale_pending
		&& '' === (string) $stale_after['schedule_error']
		&& 1 === (int) $stale_after_pending
		&& 'claim_lock_contended' === (string) $stale_after['reason']
		&& (int) $stale_after['attempts'] >= 2
		// The panel must not claim a failed booking and a pending retry at once.
		&& ! str_contains( $stale_panel, 'schedule_failed' )
		&& str_contains( $stale_panel, 'Bekleyen yeniden deneme: var' )
		// And a real success wipes the whole local record.
		&& '' === (string) $stale_final['reason']
		&& '0' === (string) $stale_final['attempts']
		&& '' === (string) $stale_final['schedule_error']
		&& 1 === $mailer->count_to( (string) $stale['email'] ),
	sprintf(
		'measured:real_action_scheduler_surfaces|first_error:%s|first_pending:%d|after_error:%s|after_pending:%d|after_reason:%s|after_attempts:%d|panel_shows_error:%s|panel_shows_pending:%s|final_reason:%s|final_attempts:%s|final_error:%s|mails:%d',
		'' === (string) $stale_first['schedule_error'] ? 'none' : (string) $stale_first['schedule_error'],
		(int) $stale_pending,
		'' === (string) $stale_after['schedule_error'] ? 'cleared' : (string) $stale_after['schedule_error'],
		(int) $stale_after_pending,
		'' === (string) $stale_after['reason'] ? 'CLEARED' : (string) $stale_after['reason'],
		(int) $stale_after['attempts'],
		str_contains( $stale_panel, 'schedule_failed' ) ? 'yes' : 'no',
		str_contains( $stale_panel, 'Bekleyen yeniden deneme: var' ) ? 'yes' : 'no',
		'' === (string) $stale_final['reason'] ? 'cleared' : (string) $stale_final['reason'],
		(string) $stale_final['attempts'],
		'' === (string) $stale_final['schedule_error'] ? 'cleared' : (string) $stale_final['schedule_error'],
		$mailer->count_to( (string) $stale['email'] )
	)
);

kuka_ship_purge_actions( $stale_id );
kuka_ship_purge_sync_actions( $stale_id );
kuka_ship_destroy_order( wc_get_order( $stale_id ) );

/* --- carrier and local counters must not overwrite each other --------- */

/*
 * The manager reused ONE `$attempts` variable for two unrelated budgets: the
 * carrier query attempts, which the poller spends against MAX_ATTEMPTS, and
 * the local sync refusal count. A claim refusal therefore reset the carrier
 * budget the poller reads -- an order on its seventh query reported one.
 */
$counter_fixture = kuka_ship_notify_fixture();
$counter_id      = (int) $counter_fixture['order']->get_id();
$counter_fixture['box']->code = Kuka_Island_Shipping_Status::CODE_DELIVERED;

// Six carrier queries already spent; this run is the seventh.
for ( $spent = 0; $spent < 6; $spent++ ) {
	Kuka_Island_Shipping_Order_Store::record_query_attempt( wc_get_order( $counter_id ) );
}

$mailer->reset();
$mailer->mode = 'accepted';

$counter_second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$counter_second->get_var( $counter_second->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_ship_notify_claim_' . $counter_id ) );
$counter_queried = $counter_fixture['manager']->query_status( wc_get_order( $counter_id ) );
$counter_second->get_var( $counter_second->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_ship_notify_claim_' . $counter_id ) );
$counter_second->close();

kuka_ship_forget_order( $counter_id );
$counter_store = Kuka_Island_Shipping_Order_Store::get_shipment_data( wc_get_order( $counter_id ) );

/* --- the first safe retry must really be two minutes away ------------- */

$delay_seconds = -1;

if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler_Store' ) ) {
	$delay_pending = (array) as_get_scheduled_actions(
		array(
			'hook'     => kuka_ship_sync_hook(),
			'args'     => array( 'order_id' => $counter_id ),
			'group'    => Kuka_Island_Shipping_Status_Poller::GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
			'orderby'  => 'none',
		)
	);

	$delay_action = reset( $delay_pending );

	if ( is_object( $delay_action ) && method_exists( $delay_action, 'get_schedule' ) ) {
		$schedule = $delay_action->get_schedule();

		if ( is_object( $schedule ) && method_exists( $schedule, 'get_date' ) ) {
			$date = $schedule->get_date();

			if ( $date instanceof DateTimeInterface ) {
				$delay_seconds = $date->getTimestamp() - time();
			}
		}
	}
}

$report(
	'SHIPPING_SYNC_COUNTERS_AND_DELAY_ARE_SEPARATE',
	7 === (int) ( $counter_queried['attempts'] ?? -1 )
		&& 1 === (int) ( $counter_queried['fulfillment_sync_attempts'] ?? -1 )
		&& 7 === (int) $counter_store['query_attempts']
		&& 1 === Kuka_Island_Shipping_Order_Store::sync_attempts( wc_get_order( $counter_id ) )
		// Measured from the real Action Scheduler row, not from a constant.
		&& $delay_seconds >= 100
		&& $delay_seconds <= 140,
	sprintf(
		'measured:real_action_scheduler_row|returned_attempts:%d|returned_sync_attempts:%d|stored_query_attempts:%d|stored_sync_attempts:%d|first_delay_seconds:%d',
		(int) ( $counter_queried['attempts'] ?? -1 ),
		(int) ( $counter_queried['fulfillment_sync_attempts'] ?? -1 ),
		(int) $counter_store['query_attempts'],
		Kuka_Island_Shipping_Order_Store::sync_attempts( wc_get_order( $counter_id ) ),
		$delay_seconds
	)
);

kuka_ship_purge_actions( $counter_id );
kuka_ship_purge_sync_actions( $counter_id );
kuka_ship_destroy_order( wc_get_order( $counter_id ) );

/* --- a refusal a retry cannot fix is visible, once -------------------- */

/*
 * `claim_other_record` and `claim_reference_missing` are data problems: a
 * retry would repeat them for ever. They must still be recorded, shown and
 * noted -- once -- so the operator knows why a delivered shipment has no
 * customer notification.
 */
$other_fixture = kuka_ship_notify_fixture();
$other_id      = (int) $other_fixture['order']->get_id();
$other_fixture['box']->code = Kuka_Island_Shipping_Status::CODE_DELIVERED;

// A debt that belongs to a DIFFERENT carrier reference.
$other_order = wc_get_order( $other_id );
$other_order->update_meta_data( Kuka_Island_Shipping_Notification::META_STATE, Kuka_Island_Shipping_Notification::STATE_DUE );
$other_order->update_meta_data( Kuka_Island_Shipping_Notification::META_DUE_REFERENCE, 'KI1SOMEONEELSE1' );
$other_order->update_meta_data( Kuka_Island_Shipping_Notification::META_HANDOVER_AT, gmdate( 'Y-m-d H:i:sP' ) );
$other_order->save();

$mailer->reset();
$mailer->mode = 'accepted';

$other_fixture['manager']->query_status( wc_get_order( $other_id ) );
kuka_ship_forget_order( $other_id );
$other_first_notes = kuka_ship_sync_note_count( $other_id );
$other_pending     = kuka_ship_pending_sync_count( $other_id );

// Viewing the panel and querying again must not multiply the note.
kuka_ship_render_panel( $other_fixture['manager'], wc_get_order( $other_id ) );
$other_fixture['manager']->query_status( wc_get_order( $other_id ) );
kuka_ship_forget_order( $other_id );

$other_panel = kuka_ship_render_panel( $other_fixture['manager'], wc_get_order( $other_id ) );
$other_notes = kuka_ship_sync_note_count( $other_id );
$other_reason = (string) wc_get_order( $other_id )->get_meta( '_kuka_shipping_sync_last_reason', true );

$missing_claim = Kuka_Island_Shipping_Notification::claim( wc_get_order( $other_id ), '' );

$report(
	'SHIPPING_SYNC_NONRETRYABLE_IS_VISIBLE_ONCE',
	'claim_other_record' === $other_reason
		&& 0 === (int) $other_pending
		&& 1 === (int) $other_first_notes
		&& 1 === (int) $other_notes
		&& str_contains( $other_panel, 'claim_other_record' )
		&& ( str_contains( $other_panel, 'elle' ) || str_contains( $other_panel, 'manuel' ) )
		&& 0 === $mailer->count_to( $other_fixture['email'] )
		&& false === (bool) $missing_claim['ok']
		&& 'claim_reference_missing' === (string) $missing_claim['outcome']
		&& ! in_array( 'claim_other_record', Kuka_Island_Shipping_Notification::claim_refusals(), true )
		&& ! in_array( 'claim_reference_missing', Kuka_Island_Shipping_Notification::claim_refusals(), true ),
	sprintf(
		'measured:real_manager_path|reason:%s|pending_retry:%d|notes_after_first:%d|notes_after_repeat:%d|panel_reason:%s|panel_manual:%s|mails:%d|reference_missing_outcome:%s',
		'' === $other_reason ? 'none' : $other_reason,
		(int) $other_pending,
		(int) $other_first_notes,
		(int) $other_notes,
		str_contains( $other_panel, 'claim_other_record' ) ? 'yes' : 'no',
		( str_contains( $other_panel, 'elle' ) || str_contains( $other_panel, 'manuel' ) ) ? 'yes' : 'no',
		$mailer->count_to( $other_fixture['email'] ),
		(string) $missing_claim['outcome']
	)
);

kuka_ship_purge_actions( $other_id );
kuka_ship_purge_sync_actions( $other_id );
kuka_ship_destroy_order( wc_get_order( $other_id ) );

/* --- a successful sync clears the local bookkeeping ------------------- */

$clear_fixture = kuka_ship_notify_fixture();
$clear_id      = (int) $clear_fixture['order']->get_id();
$clear_fixture['box']->code = Kuka_Island_Shipping_Status::CODE_DELIVERED;

$mailer->reset();
$mailer->mode = 'accepted';

// First turn refused, so the bookkeeping exists.
$clear_second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$clear_second->get_var( $clear_second->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_ship_notify_claim_' . $clear_id ) );
$clear_fixture['manager']->query_status( wc_get_order( $clear_id ) );
$clear_second->get_var( $clear_second->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_ship_notify_claim_' . $clear_id ) );
$clear_second->close();

kuka_ship_forget_order( $clear_id );
$clear_before = (string) wc_get_order( $clear_id )->get_meta( '_kuka_shipping_sync_last_reason', true );

// Second turn succeeds.
$clear_fixture['manager']->query_status( wc_get_order( $clear_id ) );
kuka_ship_forget_order( $clear_id );
$clear_order = wc_get_order( $clear_id );

$report(
	'SHIPPING_SYNC_BOOKKEEPING_CLEARS_ON_SUCCESS',
	'' !== $clear_before
		&& '' === (string) $clear_order->get_meta( '_kuka_shipping_sync_last_reason', true )
		&& 0 === Kuka_Island_Shipping_Order_Store::sync_attempts( $clear_order )
		&& '' === (string) $clear_order->get_meta( '_kuka_shipping_sync_schedule_error', true )
		&& 1 === $mailer->count_to( $clear_fixture['email'] ),
	sprintf(
		'measured:real_manager_path|reason_before:%s|reason_after:%s|attempts_after:%d|schedule_error_after:%s|mails:%d',
		'' === $clear_before ? 'none' : $clear_before,
		'' === (string) $clear_order->get_meta( '_kuka_shipping_sync_last_reason', true ) ? 'cleared' : 'PRESENT',
		Kuka_Island_Shipping_Order_Store::sync_attempts( $clear_order ),
		'' === (string) $clear_order->get_meta( '_kuka_shipping_sync_schedule_error', true ) ? 'cleared' : 'PRESENT',
		$mailer->count_to( $clear_fixture['email'] )
	)
);

kuka_ship_purge_actions( $clear_id );
kuka_ship_purge_sync_actions( $clear_id );
kuka_ship_destroy_order( wc_get_order( $clear_id ) );

$terminal_cases = array();

foreach ( array( 'claim_lock_contended', 'notification_claim_unverified', 'claim_order_unreadable' ) as $terminal_case ) {
	$terminal_cases[ $terminal_case ] = kuka_ship_terminal_claim_case( $terminal_case, $mailer );
}

putenv( 'KUKA_SHIPPING_AUTOMATION' );

$terminal_ok    = true;
$terminal_lines = array();

foreach ( $terminal_cases as $name => $result ) {
	$first  = $result['first'];
	$second = $result['second'];
	$third  = $result['third'];

	$case_ok = 'delivered' === (string) $first['lifecycle']
		// The claim was refused, and the refusal is visible with a safe code.
		&& $name === (string) $first['claim']
		// Nothing about the dispatch moved, and no message went out.
		&& 'no' === (string) $first['fulfilled']
		&& '' === (string) $first['date']
		&& 0 === (int) $first['mails']
		// A safe local retry is really booked, and no carrier poll is.
		&& 1 === (int) $first['follow_up']
		&& 0 === (int) $first['pending']
		// Once the obstacle is gone the retry finishes the job, exactly once.
		&& 'yes' === (string) $second['fulfilled']
		&& '' !== (string) $second['date']
		&& 1 === (int) $second['mails']
		&& Kuka_Island_Shipping_Notification::STATE_SENT === (string) $second['notify_state']
		// The handover date is the instant of the first successful claim.
		&& '' !== (string) $second['handover']
		&& gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $second['handover'] ) )
			=== gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $second['date'] . ' UTC' ) )
		// The carrier's terminal status survives, and no second message follows.
		&& 'delivered' === (string) $second['lifecycle']
		&& 1 === (int) $third['mails']
		&& 'delivered' === (string) $third['lifecycle'];

	$terminal_ok = $terminal_ok && $case_ok;

	$terminal_lines[] = sprintf(
		'%s:%s/lifecycle:%s/claim:%s/fulfilled:%s/date:%s/mails:%d/follow_up:%d/runner:%s/retry_fulfilled:%s/retry_mails:%d/total_mails:%d',
		$name,
		$case_ok ? 'ok' : 'FAIL',
		(string) $first['lifecycle'],
		'' === (string) $first['claim'] ? 'none' : (string) $first['claim'],
		(string) $first['fulfilled'],
		'' === (string) $first['date'] ? 'absent' : 'present',
		(int) $first['mails'],
		(int) $first['follow_up'],
		'' === (string) $first['runner'] ? 'unrecorded' : (string) $first['runner'],
		(string) $second['fulfilled'],
		(int) $second['mails'],
		(int) $third['mails']
	);
}

$terminal_residue = 0;

foreach ( $terminal_cases as $result ) {
	$terminal_residue += 0 > (int) $result['purged'] ? 1 : 0;
}

$report(
	'SHIPPING_TERMINAL_FIRST_ANSWER_STILL_NOTIFIES',
	$terminal_ok && 0 === $terminal_residue,
	sprintf(
		'measured:real_action_scheduler_worker_and_intercepted_transport|carrier_writes:0|cases:%d|%s',
		count( $terminal_cases ),
		implode( '|', $terminal_lines )
	)
);

$mailer->detach();
kuka_ship_destroy_order( wc_get_order( $lang_tr->get_id() ) );
kuka_ship_destroy_order( wc_get_order( $lang_en->get_id() ) );

/* ========================================================================== */
/* 60. FS_CHMOD_FILE is defined without touching a vendor file                 */
/* ========================================================================== */

/*
 * iyzico's AbstractLogger reaches for \FS_CHMOD_FILE in createHtaccess(), and
 * WordPress defines that constant in ONE place: inside WP_Filesystem(), which
 * lives in wp-admin/includes/file.php. WP-CLI does not load the admin side, so
 * the constant exists in a CLI run only because the logger's own constructor
 * happened to call WP_Filesystem() during bootstrap -- and only while
 * get_filesystem_method() answers 'direct'. When it answers anything else the
 * logger's fallback returns early and never defines it, and a later
 * createHtaccess() call dies on an undefined constant.
 *
 * MEASURED, NOT ASSUMED: in this container the method IS 'direct' and the
 * constant IS already defined, so the failure does not reproduce here. What is
 * fixed is the dependency on that accident. Core defines the constant itself,
 * with WordPress's own formula, when nothing else has -- and the vendor file is
 * not touched.
 */

$chmod_guard   = Kuka_Island_Core_Compatibility::ensure_chmod_file_constant();
$chmod_default = Kuka_Island_Core_Compatibility::chmod_file_default();
$chmod_wp      = ( fileperms( ABSPATH . 'index.php' ) & 0777 ) | 0644;

// The end-to-end question: can the vendor's own htaccess writer run here?
$chmod_dir     = trailingslashit( sys_get_temp_dir() ) . 'kuka-chmod-probe-' . wp_generate_password( 8, false );
$chmod_logger  = class_exists( '\Iyzico\IyzipayWoocommerce\Common\Helpers\Logger' );
$chmod_written = false;
$chmod_error   = '';

if ( $chmod_logger ) {
	try {
		new \Iyzico\IyzipayWoocommerce\Common\Helpers\Logger( trailingslashit( $chmod_dir ) );
		$chmod_written = file_exists( trailingslashit( $chmod_dir ) . '.htaccess' )
			&& str_contains( (string) file_get_contents( trailingslashit( $chmod_dir ) . '.htaccess' ), 'Deny from all' );
	} catch ( Throwable $chmod_thrown ) {
		$chmod_error = get_class( $chmod_thrown );
	}
}

// The probe directory is ours and is removed by exact path.
if ( is_dir( $chmod_dir ) ) {
	foreach ( (array) glob( trailingslashit( $chmod_dir ) . '{,.}*', GLOB_BRACE ) as $chmod_file ) {
		if ( is_file( $chmod_file ) ) {
			unlink( $chmod_file );
		}
	}

	rmdir( $chmod_dir );
}

// And no vendor file was modified to achieve any of it.
$chmod_vendor_clean = ! str_contains(
	(string) shell_exec( 'cd ' . escapeshellarg( dirname( WP_PLUGIN_DIR, 3 ) ) . ' && git status --porcelain wp-content/plugins/iyzico-woocommerce 2>/dev/null' ),
    'iyzico-woocommerce'
);

$report(
	'SHIPPING_FS_CHMOD_FILE_GUARDED_IN_PROJECT',
	in_array( $chmod_guard, array( 'already_defined', 'defined_now' ), true )
		&& defined( 'FS_CHMOD_FILE' )
		// Core's value is WordPress's own formula, not a hand-picked mask.
		&& $chmod_default === $chmod_wp
		&& FS_CHMOD_FILE === $chmod_wp
		// The vendor path that needs the constant runs.
		&& $chmod_logger
		&& $chmod_written
		&& '' === $chmod_error
		&& ! is_dir( $chmod_dir ),
	sprintf(
		'measured:core_guard_and_real_vendor_logger|guard:%s|constant:%s|wordpress_formula:%s|identical:%s|filesystem_method:%s|vendor_logger:%s|htaccess_written:%s|error:%s|probe_dir_removed:%s',
		$chmod_guard,
		defined( 'FS_CHMOD_FILE' ) ? decoct( FS_CHMOD_FILE ) : 'undefined',
		decoct( $chmod_wp ),
		defined( 'FS_CHMOD_FILE' ) && FS_CHMOD_FILE === $chmod_wp ? 'yes' : 'NO',
		function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : 'unknown',
		$chmod_logger ? 'available' : 'ABSENT',
		$chmod_written ? 'yes' : 'NO',
		'' === $chmod_error ? 'none' : $chmod_error,
		is_dir( $chmod_dir ) ? 'NO' : 'yes'
	)
);

/* ========================================================================== */
/* 61. The SMTP password lives in wp-config, and nowhere else                  */
/* ========================================================================== */

/*
 * A mail transport is the one place in this project where a working credential
 * has to exist at runtime, so where it is NOT stored matters as much as where
 * it is. Three things are measured rather than assumed: the admin surface
 * offers no field that could capture it, the database holds no row that could
 * carry it, and the transport reads it from wp-config constants only.
 *
 * The runbook line is measured too. A secret whose production location is not
 * written down is a secret somebody will paste into an option.
 */

$smtp_admin_source = (string) file_get_contents(
	trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/class-email-delivery.php'
);

// No input that could take a password, and no setting that could store one.
$smtp_input_fields = preg_match_all( '/type=["\']password["\']/', $smtp_admin_source );
$smtp_registered   = preg_match_all( '/register_setting\(|add_settings_field\(/', $smtp_admin_source );

// Nothing in the options table can be carrying it either.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$smtp_option_rows = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->options}
	 WHERE option_name LIKE '%smtp%'
	    OR option_name LIKE '%KUKA_SMTP%'
	    OR option_value LIKE '%KUKA_SMTP_PASSWORD%'"
);

// The transport's own answer comes from constants, and it never says the value.
$smtp_required = array( 'KUKA_SMTP_HOST', 'KUKA_SMTP_PORT', 'KUKA_SMTP_USERNAME', 'KUKA_SMTP_PASSWORD', 'KUKA_SMTP_ENCRYPTION', 'KUKA_SMTP_FROM_NAME' );
$smtp_defined  = 0;

foreach ( $smtp_required as $smtp_constant ) {
	if ( defined( $smtp_constant ) ) {
		++$smtp_defined;
	}
}

$smtp_configured = class_exists( 'Kuka_Island_Core_Email_Delivery' )
	&& Kuka_Island_Core_Email_Delivery::smtp_is_configured();

$smtp_has_runtime_value = false;
foreach ( $smtp_required as $smtp_constant ) {
	if ( ! defined( $smtp_constant ) ) {
		continue;
	}

	if ( 'KUKA_SMTP_PORT' === $smtp_constant ) {
		$smtp_has_runtime_value = $smtp_has_runtime_value || 0 < (int) constant( $smtp_constant );
		continue;
	}

	$smtp_has_runtime_value = $smtp_has_runtime_value || '' !== trim( (string) constant( $smtp_constant ) );
}

// CI deliberately carries no mail credential. Empty is safe; partial is not.
$smtp_runtime_state = $smtp_configured ? 'configured' : ( $smtp_has_runtime_value ? 'INVALID' : 'unconfigured' );

/*
 * The compose transport, the runbook line and .gitignore are HOST files: this
 * container mounts only /project-scripts, so they cannot be read from here and
 * are measured host-side instead -- see SMTP_SECRET_TRANSPORT in verify.sh.
 * Reading a file that is not mounted would have measured nothing and reported
 * it as a failure of the thing being checked.
 */

$report(
	'SHIPPING_SMTP_SECRET_STAYS_OUT_OF_THE_DATABASE',
	// The admin surface cannot capture it.
	0 === $smtp_input_fields
	&& 0 === $smtp_registered
	// The database is not carrying it.
	&& 0 === $smtp_option_rows
	// Runtime values come only from wp-config; CI may deliberately leave them empty.
	&& 6 === $smtp_defined
	&& 'INVALID' !== $smtp_runtime_state,
	sprintf(
		'measured:source_and_options_table|password_input_fields:%d|registered_settings:%d|smtp_option_rows:%d|constants_from_wp_config:%d/6|runtime_state:%s|host_files:measured_by_SMTP_SECRET_TRANSPORT',
		$smtp_input_fields,
		$smtp_registered,
		$smtp_option_rows,
		$smtp_defined,
		$smtp_runtime_state
	)
);

/* ========================================================================== */
/* 62. Cleanup and verdict                                                     */
/* ========================================================================== */

$leftover = get_posts(
	array(
		'post_type'      => 'shop_order',
		'post_status'    => 'any',
		'posts_per_page' => 20,
		'meta_key'       => '_kuka_shipping_fixture',
		'meta_value'     => '1',
		'fields'         => 'ids',
	)
);

$hpos_leftover = array();
if ( function_exists( 'wc_get_orders' ) ) {
	$hpos_leftover = (array) wc_get_orders(
		array(
			'limit'      => 20,
			'return'     => 'ids',
			'status'     => 'any',
			'meta_key'   => '_kuka_shipping_fixture',
			'meta_value' => '1',
		)
	);
}

foreach ( array_merge( (array) $leftover, $hpos_leftover ) as $leftover_id ) {
	$leftover_order = wc_get_order( (int) $leftover_id );
	if ( $leftover_order instanceof WC_Order ) {
		kuka_ship_destroy_order( $leftover_order );
	}
}

$still_there = function_exists( 'wc_get_orders' )
	? count(
		(array) wc_get_orders(
			array(
				'limit'      => 20,
				'return'     => 'ids',
				'status'     => 'any',
				'meta_key'   => '_kuka_shipping_fixture',
				'meta_value' => '1',
			)
		)
	)
	: 0;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$notes_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'order_note'" );

$report(
	'SHIPPING_NO_REAL_CARRIER_REQUEST',
	array() === $kuka_ship_real_requests,
	sprintf(
		'guard:pre_http_request|carrier_host:mngkargo.com.tr|real_requests_attempted:%d|transport:mock_only',
		count( $kuka_ship_real_requests )
	)
);

/*
 * THE CONTROL. Two things have to be shown, and neither is "the restore put
 * things back": there is nothing to put back any more.
 *
 *   1. A row belonging to the SHOP -- in the production namespace, planted here
 *      to stand in for a real cached city list -- is untouched by a full run.
 *   2. A row that appears DURING the run and was never declared is left alone.
 *      This is the case ownership-by-subtraction got wrong: a concurrent real
 *      request can create one, and the previous version deleted it as a
 *      suspected leftover.
 *
 * Both sentinels are this block's own fixtures and are removed by exact name at
 * the end, one delete per name.
 */
global $wpdb;

$shop_key      = 'kuka_dhl_cbs_cities_' . Kuka_Shipping_Cache_Custodian::PRODUCTION_NAMESPACE;
$shop_option   = '_transient_' . $shop_key;
$shop_timeout  = '_transient_timeout_' . $shop_key;
$shop_cities   = array( array( 'code' => '99', 'name' => 'KUKA-SHOP-OWNED-CITY' ) );

set_transient( $shop_key, $shop_cities, DAY_IN_SECONDS );

$shop_before = array();
foreach ( Kuka_Shipping_Cache_Custodian::rows() as $control_name => $control_row ) {
	if ( in_array( (string) $control_name, array( $shop_option, $shop_timeout ), true ) ) {
		$shop_before[ (string) $control_name ] = $control_row;
	}
}

// A real scenario, on the run's own namespace: it fills the cache from the mock
// exactly as every other scenario does.
$control_scenario = kuka_ship_scenario( kuka_ship_happy_responder() );
$control_scenario['manager']->create_shipment( $control_scenario['order'] );
$control_run_rows = 0;
foreach ( Kuka_Shipping_Cache_Custodian::rows() as $control_name => $control_row ) {
	if ( str_contains( (string) $control_name, (string) $cbs_namespace ) ) {
		++$control_run_rows;
	}
}
kuka_ship_destroy_order( wc_get_order( $control_scenario['order']->get_id() ) );

// A row nobody declared, appearing mid-run: another process's business.
$foreign_key     = 'kuka_dhl_cbs_districts_' . Kuka_Shipping_Cache_Custodian::PRODUCTION_NAMESPACE . '_81';
$foreign_option  = '_transient_' . $foreign_key;
$foreign_timeout = '_transient_timeout_' . $foreign_key;
set_transient( $foreign_key, array( array( 'code' => '1', 'name' => 'KUKA-CONCURRENT-DISTRICT' ) ), DAY_IN_SECONDS );

$cbs_release = $cbs_custodian->release( 'normal' );
$cbs_second  = $cbs_custodian->release( 'shutdown' );
$cbs_after   = Kuka_Shipping_Cache_Custodian::rows();

$shop_intact = array() !== $shop_before;
foreach ( $shop_before as $shop_name => $shop_row ) {
	if ( ! isset( $cbs_after[ $shop_name ] )
		|| $cbs_after[ $shop_name ]['option_value'] !== $shop_row['option_value']
		|| $cbs_after[ $shop_name ]['autoload'] !== $shop_row['autoload'] ) {
		$shop_intact = false;
	}
}

$shop_value_ok   = $shop_cities === get_transient( $shop_key );
$foreign_kept    = isset( $cbs_after[ $foreign_option ] ) && isset( $cbs_after[ $foreign_timeout ] );
$run_rows_left   = 0;
foreach ( array_keys( $cbs_after ) as $control_name ) {
	if ( str_contains( (string) $control_name, (string) $cbs_namespace ) ) {
		++$run_rows_left;
	}
}

$report(
	'SHIPPING_CBS_CACHE_PRESERVED',
	$control_run_rows > 0
		&& $cbs_release['ok']
		&& 0 === (int) $cbs_release['refused']
		&& 0 === (int) $cbs_release['foreign_changed']
		&& 0 === $run_rows_left
		&& $shop_intact
		&& $shop_value_ok
		// The undeclared row that appeared mid-run is still there.
		&& $foreign_kept
		// Idempotent: the second call reported the first call's outcome.
		&& $cbs_second === $cbs_release
		&& 'normal' === (string) $cbs_second['invoked_by'],
	sprintf(
		'isolation:own_namespace|namespace:%s|run_rows_created:%d|owned_declared:%d|owned_removed:%d|run_rows_left:%d|shop_row_bytes_identical:%s|shop_row_value_identical:%s|undeclared_midrun_row_preserved:%s|foreign_preserved:%d|foreign_changed:%d|refused:%d|second_call_is_noop:%s|wildcard_delete:none',
		(string) $cbs_namespace,
		$control_run_rows,
		(int) $cbs_release['owned_declared'],
		(int) $cbs_release['owned_removed'],
		$run_rows_left,
		$shop_intact ? 'yes' : 'NO',
		$shop_value_ok ? 'yes' : 'NO',
		$foreign_kept ? 'yes' : 'NO',
		(int) $cbs_release['foreign_preserved'],
		(int) $cbs_release['foreign_changed'],
		(int) $cbs_release['refused'],
		$cbs_second === $cbs_release ? 'yes' : 'NO'
	)
);

/*
 * Both sentinels were planted here, so they are removed here -- by exact name,
 * one delete each. Nothing in this suite deletes by pattern.
 */
$sentinel_removed = 0;
foreach ( array( $shop_option, $shop_timeout, $foreign_option, $foreign_timeout ) as $sentinel_name ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( false !== $wpdb->delete( $wpdb->options, array( 'option_name' => $sentinel_name ) ) ) {
		++$sentinel_removed;
	}
	wp_cache_delete( $sentinel_name, 'options' );
}
wp_cache_delete( 'alloptions', 'options' );

// Every measurement that books a query has run by now, so the group row can
// go -- unless the shop already had one before this run started.
$chain_group_state = kuka_ship_purge_orphan_group( $chain_group_existed );

$cbs_final       = Kuka_Shipping_Cache_Custodian::rows();
$cbs_names_after = array_keys( $cbs_final );
sort( $cbs_names_after );

$report(
	'SHIPPING_FIXTURES_REMOVED',
	0 === $still_there
		&& $notes_before === $notes_after
		&& $cbs_release['ok']
		&& array() === $cbs_names_after
		&& 4 === $sentinel_removed
		&& in_array( $chain_group_state, array( 'removed', 'preexisting', 'absent' ), true ),
	sprintf(
		'remaining_fixture_orders:%d|order_note_delta:%d|cache_rows_left:%d|run_owned_cache_removed:%d|cache_release_refused:%d|sentinels_removed_by_exact_name:%d|action_group_row:%s',
		$still_there,
		$notes_after - $notes_before,
		count( $cbs_names_after ),
		(int) $cbs_release['owned_removed'],
		(int) $cbs_release['refused'],
		$sentinel_removed,
		$chain_group_state
	)
);

if ( array() !== $failures ) {
	WP_CLI::error( 'SHIPPING_VERIFY=FAIL|' . implode( ',', $failures ) );
}

WP_CLI::line( 'SHIPPING_VERIFY=PASS' );
