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

	/** Record a WRITE, running the injected hooks around it. */
	private function record_write( string $operation ): void {
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

		foreach ( array( 'read_order', 'read_shipment', 'read_shipment_status', 'track_shipment' ) as $operation ) {
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

		return Kuka_Island_Shipping_Result::success(
			'resolve_location',
			array(
				'city_code'     => 7,
				'district_code' => 77,
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

		return Kuka_Island_Shipping_Result::success( 'update_order', array( 'acknowledged' => true ) );
	}

	public function cancel_order( string $reference ): Kuka_Island_Shipping_Result {
		$this->record_write( 'cancel_order' );

		return Kuka_Island_Shipping_Result::success( 'cancel_order', array( 'acknowledged' => true ) );
	}

	/**
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function update_shipment( array $shipment ): Kuka_Island_Shipping_Result {
		$this->record_write( 'update_shipment' );

		return Kuka_Island_Shipping_Result::success( 'update_shipment', array( 'acknowledged' => true ) );
	}

	public function cancel_shipment( string $reference, string $shipment_id ): Kuka_Island_Shipping_Result {
		$this->record_write( 'cancel_shipment' );

		return Kuka_Island_Shipping_Result::success( 'cancel_shipment', array( 'acknowledged' => true ) );
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

	public function read_shipment_status( string $reference ): Kuka_Island_Shipping_Result {
		$this->record( 'read_shipment_status' );

		return Kuka_Island_Shipping_Result::success(
			'get_shipment_status',
			array(
				'status_code'  => 2,
				'tracking_url' => 'https://fake-kargo.example/FAKE-SHIP-1',
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

$cbs_custodian = ( new Kuka_Shipping_Cache_Custodian() )->guard();

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

$cbs_resolver->purge_cache( array( '34', '06' ) );

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
$ambiguous_resolver->purge_cache( array( '34' ) );
$ambiguous = $ambiguous_resolver->resolve( 'Istanbul', 'Sisli' );
$ambiguous_resolver->purge_cache( array( '34' ) );

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
$failing_resolver->purge_cache( array( '34' ) );
$failing_resolver->cities();
$cached_after_failure = get_transient( 'kuka_dhl_cbs_cities_v1' );

$report(
	'SHIPPING_REFERENCE_DATA_CACHE',
	false === $cached_after_failure,
	sprintf( 'failure_cached:%s|ttl_bounded:1_day', false === $cached_after_failure ? 'no' : 'YES' )
);

$cbs_resolver->purge_cache( array( '34', '06' ) );

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
	$provider->get_resolver()->purge_cache( array( '34' ) );

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
$happy_provider->get_resolver()->purge_cache( array( '34' ) );

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
$uncertain_provider->get_resolver()->purge_cache( array( '34' ) );

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
$stuck_provider->get_resolver()->purge_cache( array( '34' ) );

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
		&& str_contains( (string) $cancel_ship_result['detail'], 'confirmed_by:read_shipment' ),
	sprintf(
		'branch:shipment|cancelshipment_calls:%d|cancelorder_calls:%d|getshipment_calls:%d|getorder_calls:%d|state:%s|confirmed_by:%s',
		$cancel_ship['transport']->count_for( '/cancelshipment' ),
		$cancel_ship['transport']->count_for( '/cancelorder/' ),
		$cancel_ship['transport']->count_for( '/getshipment/' ),
		$cancel_ship['transport']->count_for( '/getorder/' ),
		$cancel_ship_after['state'],
		str_contains( (string) $cancel_ship_result['detail'], 'confirmed_by:read_shipment' ) ? 'read_shipment' : 'OTHER'
	)
);

kuka_ship_destroy_order( $cancel_ship_order );

// --- Order branch, cancellation proved: cancelorder, then getorder ---------

$cancel_order_scenario = kuka_ship_scenario(
	static function ( string $method, string $url ): array {
		$common = kuka_ship_common_reads( $url );

		if ( null !== $common ) {
			return $common;
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return kuka_ship_create_order_ok();
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			// PERMANENT, not uncertain: the order is registered, the shipment
			// never existed, and nothing has to be reconciled. This is the
			// order_created dead end exactly as an operator meets it.
			return array( 'status' => 400, 'body' => '{"title":"Bad Request"}' );
		}

		if ( str_contains( $url, '/cancelorder/' ) ) {
			return array( 'status' => 200, 'body' => '{}' );
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$cancel_order_scenario['manager']->create_shipment( $cancel_order_scenario['order'] );
$cancel_order_order = wc_get_order( $cancel_order_scenario['order']->get_id() );
$cancel_order_pre   = Kuka_Island_Shipping_Order_Store::get_shipment_data( $cancel_order_order );

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
		&& 1 === $cancel_order_scenario['transport']->count_for( '/getorder/' )
		&& 0 === $cancel_order_scenario['transport']->count_for( '/getshipment/' )
		&& str_contains( (string) $cancel_order_result['detail'], 'confirmed_by:read_order' ),
	sprintf(
		'branch:order|state_before:%s|shipment_id_before:%s|cancelorder_calls:%d|cancelshipment_calls:%d|getorder_calls:%d|getshipment_calls:%d|state:%s|confirmed_by:%s',
		$cancel_order_pre['state'],
		'' === $cancel_order_pre['shipment_id'] ? 'none' : 'PRESENT',
		$cancel_order_scenario['transport']->count_for( '/cancelorder/' ),
		$cancel_order_scenario['transport']->count_for( '/cancelshipment' ),
		$cancel_order_scenario['transport']->count_for( '/getorder/' ),
		$cancel_order_scenario['transport']->count_for( '/getshipment/' ),
		$cancel_order_after['state'],
		str_contains( (string) $cancel_order_result['detail'], 'confirmed_by:read_order' ) ? 'read_order' : 'OTHER'
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

$false_cancel['manager']->create_shipment( $false_cancel['order'] );
$false_cancel_order = wc_get_order( $false_cancel['order']->get_id() );
$false_cancel_pre   = Kuka_Island_Shipping_Order_Store::get_shipment_data( $false_cancel_order );

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
		&& 'cancel_unconfirmed' === $false_cancel_result['code']
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED !== $false_cancel_after['state']
		&& Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $false_cancel_after['state']
		&& 1 === $false_cancel['transport']->count_for( '/cancelorder/' )
		&& 1 === $false_cancel['transport']->count_for( '/getorder/' )
		&& 0 === $false_cancel['transport']->count_for( '/getshipment/' )
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
		&& Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED === $uncertain_cancel_state
		&& ! $uncertain_cancel_second['ok']
		// reconcile_required is not in cancel()'s allow-list, so the second
		// press is refused by the state gate itself and writes nothing.
		&& 'not_cancellable' === $uncertain_cancel_second['code']
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

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$resume['manager']->create_shipment( $resume['order'] );
$resume_order = wc_get_order( $resume['order']->get_id() );
$resume_pre   = Kuka_Island_Shipping_Order_Store::get_shipment_data( $resume_order );

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
	8 === count( $resume_refused )
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

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
);

$admin_resume['manager']->create_shipment( $admin_resume['order'] );
$admin_resume_order    = wc_get_order( $admin_resume['order']->get_id() );
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

$fail_poller = new Kuka_Island_Shipping_Status_Poller( $fail_chain['manager'] );
$fail_poller->register();

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

$ok_poller = new Kuka_Island_Shipping_Status_Poller( $ok_chain['manager'] );
$ok_poller->register();

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
$chain_group_state    = kuka_ship_purge_orphan_group( $chain_group_existed );
$chain_automation_off = ! Kuka_Island_Shipping_Status_Poller::automation_enabled();

$report(
	'SHIPPING_POLL_CHAIN_LEAVES_NOTHING',
	$chain_automation_off
		&& 10 === $fail_actions_removed
		&& 3 === $ok_actions_removed
		&& in_array( $chain_group_state, array( 'removed', 'preexisting' ), true ),
	sprintf(
		'automation_restored:%s|failure_chain_actions_removed:%d|success_chain_actions_removed:%d|group_row:%s',
		$chain_automation_off ? 'off' : 'ON',
		$fail_actions_removed,
		$ok_actions_removed,
		$chain_group_state
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

$registered = 0;
foreach ( $our_hooks as $hook ) {
	if ( isset( $wp_filter[ $hook ] ) && ! empty( $wp_filter[ $hook ]->callbacks ) ) {
		++$registered;
	}
}

$report(
	'SHIPPING_LOAD_REGISTERS_NOTHING',
	0 === $registered,
	sprintf( 'hooks_checked:%d|registered:%d|register_called:no', count( $our_hooks ), $registered )
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
	'Taşıyıcı iptali kabul etti fakat doğrulama sorgusu yapılamadı. Durum değiştirilmedi.',
	'kayıtlı değil (siparişte taşıyıcı yazılı değil)',
	'%s (bu kurulumda kayıtlı değil)',
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

$amend_ok = $amend['manager']->update_shipment( wc_get_order( $amend_id ) );

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
		&& $amend_ok['ok']
		&& 1 === $amend['adapter']->count_for( 'update_shipment' )
		&& 0 === $amend['adapter']->count_for( 'update_order' )
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $amend_state_now
		&& ! $amend_late['ok']
		&& 'nothing_to_update' === (string) $amend_late['code']
		&& 1 === $amend_updates,
	sprintf(
		'concurrent_call:%s|writes_while_lock_held:%d|first:%s|update_shipment:%d|update_order:%d|state_after_cancel:%s|late_update_from_stale_handle:%s|total_updates:%d',
		(string) $amend_contended['code'],
		$amend_writes_while_held,
		$amend_ok['ok'] ? 'updated' : 'REFUSED:' . (string) $amend_ok['code'],
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
		'states_checked:8|wrong:%s|carrier_writes:%d',
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
		&& $cod_update['ok']
		&& 1 === $cod_booked['adapter']->count_for( 'update_shipment' )
		&& $cod_cancel['ok']
		&& 1 === $cod_booked['adapter']->count_for( 'cancel_shipment' )
		&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $cod_after,
	sprintf(
		'payment_method:cod|cod_gate_closed:%s|create:%s|resume:%s|create_writes:%d|update:%s|update_writes:%d|cancel:%s|cancel_writes:%d|state:%s',
		$cod_gate_on ? 'yes' : 'NO',
		(string) $cod_create['code'],
		(string) $cod_resume['code'],
		$cod_creates,
		$cod_update['ok'] ? 'allowed' : 'REFUSED:' . (string) $cod_update['code'],
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

	$dhl->get_resolver()->purge_cache( array( '34' ) );

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
		&& $affinity_update['ok']
		&& 1 === $affinity_writes_update
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
$uncertain_aff_dhl->get_resolver()->purge_cache( array( '34' ) );

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
	// read_shipment / read_order
	&& 'blocked' === (string) $gate_recon_verdict['verdict']
	&& 0 === $gate_recon['adapter']->read_calls()
	&& Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED === $gate_recon_state
	// the cancellation confirmation
	&& ! $gate_confirm_result['ok']
	&& 'cancel_unconfirmed' === (string) $gate_confirm_result['code']
	&& 1 === $gate_confirm['adapter']->count_for( 'cancel_shipment' )
	&& 0 === $gate_confirm['adapter']->count_for( 'read_shipment' )
	&& $gate_confirm_closed
	&& Kuka_Island_Shipping_Order_Store::STATE_CANCELLED !== $gate_confirm_state,
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
/* 41. Cleanup and verdict                                                     */
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
 * THE CONTROL, before the real restore. A restore that is never seen to restore
 * anything is not a measurement, and today's snapshot may legitimately be
 * empty, so a sentinel is planted, overwritten by a real scenario and then
 * required to come back byte for byte.
 *
 * This custodian is deliberately NOT guarded: registering a second shutdown
 * function would restore the sentinel again after the outer one had removed it,
 * and the sentinel is a fixture, not the shop's data.
 */
$control_key     = '_transient_kuka_dhl_cbs_cities_v1';
$control_timeout = '_transient_timeout_kuka_dhl_cbs_cities_v1';
$control_cities  = array( array( 'code' => '99', 'name' => 'KUKA-CONTROL-CITY' ) );

set_transient( 'kuka_dhl_cbs_cities_v1', $control_cities, DAY_IN_SECONDS );

$control_custodian = new Kuka_Shipping_Cache_Custodian();
$control_before    = array();

foreach ( Kuka_Shipping_Cache_Custodian::rows() as $control_name => $control_row ) {
	if ( in_array( (string) $control_name, array( $control_key, $control_timeout ), true ) ) {
		$control_before[ (string) $control_name ] = $control_row;
	}
}

// A real scenario: it purges the cache and refills it from the mock, which is
// exactly what would have destroyed the shop's own rows.
$control_scenario = kuka_ship_scenario( kuka_ship_happy_responder() );
$control_scenario['manager']->create_shipment( $control_scenario['order'] );
$control_overwritten = $control_custodian->snapshot_fingerprint() !== Kuka_Shipping_Cache_Custodian::fingerprint( Kuka_Shipping_Cache_Custodian::rows() );
kuka_ship_destroy_order( wc_get_order( $control_scenario['order']->get_id() ) );

$control_restore = $control_custodian->restore( 'normal' );

// Called twice on purpose: the shutdown path calls the same method, so a second
// call has to be a no-op rather than a second restore.
$control_second  = $control_custodian->restore( 'shutdown' );
$control_rows    = Kuka_Shipping_Cache_Custodian::rows();
$control_recovered = $control_custodian->snapshot_fingerprint() === Kuka_Shipping_Cache_Custodian::fingerprint( $control_rows );
$control_value_ok  = $control_cities === get_transient( 'kuka_dhl_cbs_cities_v1' );

$control_bytes_ok = array() !== $control_before;
foreach ( $control_rows as $control_name => $control_row ) {
	if ( ! isset( $control_before[ (string) $control_name ] ) ) {
		continue;
	}

	if ( $control_row['option_value'] !== $control_before[ (string) $control_name ]['option_value']
		|| $control_row['autoload'] !== $control_before[ (string) $control_name ]['autoload'] ) {
		$control_bytes_ok = false;
	}
}

$report(
	'SHIPPING_CBS_CACHE_PRESERVED',
	$control_overwritten
		&& $control_restore['ok']
		&& 0 === (int) $control_restore['refused']
		&& $control_recovered
		&& $control_value_ok
		&& $control_bytes_ok
		&& 2 === count( $control_before )
		// Idempotent: the second call reported the first call's outcome.
		&& $control_second === $control_restore
		&& 'normal' === (string) $control_second['invoked_by'],
	sprintf(
		'control:planted_then_overwritten_then_restored|coordinator:shared|overwritten_by_run:%s|value_and_timeout_rows:%d|restored_rows:%d|inserted_rows:%d|run_owned_removed:%d|refused:%d|fingerprint_recovered:%s|value_identical:%s|bytes_identical:%s|second_call_is_noop:%s',
		$control_overwritten ? 'yes' : 'NO',
		count( $control_before ),
		(int) $control_restore['restored'],
		(int) $control_restore['inserted'],
		(int) $control_restore['run_owned_removed'],
		(int) $control_restore['refused'],
		$control_recovered ? 'yes' : 'NO',
		$control_value_ok ? 'yes' : 'NO',
		$control_bytes_ok ? 'yes' : 'NO',
		$control_second === $control_restore ? 'yes' : 'NO'
	)
);

/*
 * And now the real thing: the cache as it was BEFORE this suite touched it. The
 * sentinel planted above is not in that snapshot, so this restore removes it --
 * whatever the run created is the run's residue and goes.
 */
$cbs_restore     = $cbs_custodian->restore( 'normal' );
$cbs_after       = Kuka_Shipping_Cache_Custodian::rows();
$cbs_after_print = Kuka_Shipping_Cache_Custodian::fingerprint( $cbs_after );

$cbs_names_before = $cbs_custodian->names_before();
$cbs_names_after  = array_keys( $cbs_after );
sort( $cbs_names_before );
sort( $cbs_names_after );

$report(
	'SHIPPING_FIXTURES_REMOVED',
	0 === $still_there
		&& $notes_before === $notes_after
		&& $cbs_restore['ok']
		&& 0 === (int) $cbs_restore['refused']
		&& $cbs_custodian->snapshot_fingerprint() === $cbs_after_print
		&& $cbs_names_before === $cbs_names_after,
	sprintf(
		'remaining_fixture_orders:%d|order_note_delta:%d|pre_existing_cache_rows:%d|cache_keyset_identical:%s|cache_fingerprint_identical:%s|run_owned_cache_residue:%d|cache_restore_refused:%d',
		$still_there,
		$notes_after - $notes_before,
		count( $cbs_names_before ),
		$cbs_names_before === $cbs_names_after ? 'yes' : 'NO',
		$cbs_custodian->snapshot_fingerprint() === $cbs_after_print ? 'yes' : 'NO',
		(int) $cbs_restore['run_owned_removed'],
		(int) $cbs_restore['refused']
	)
);

if ( array() !== $failures ) {
	WP_CLI::error( 'SHIPPING_VERIFY=FAIL|' . implode( ',', $failures ) );
}

WP_CLI::line( 'SHIPPING_VERIFY=PASS' );
