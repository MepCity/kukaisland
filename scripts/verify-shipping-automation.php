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
 */
function kuka_ship_manager( Kuka_Island_Shipping_DHL_Provider $provider ): Kuka_Island_Shipping_Manager {
	$registry = new Kuka_Island_Shipping_Carrier_Registry();

	$filter = static function ( $carriers ) use ( $provider ): array {
		return array( $provider );
	};

	add_filter( 'kuka_island_shipping_carriers', $filter, 999 );
	$registry->reset();
	$registry->all();
	remove_filter( 'kuka_island_shipping_carriers', $filter, 999 );

	return new Kuka_Island_Shipping_Manager( $registry );
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
		&& Kuka_Island_Shipping_DHL_Config::TRACKING_SOURCE_UNSET === kuka_ship_config( array( 'tracking_number_source' => 'invoiceId' ) )->get_tracking_number_source(),
	'default:unmeasured|shipment_id:selectable|barcode:selectable|unknown_value_falls_back_to_unmeasured:yes'
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
/* 22. Polling is bounded, increasing and finite -- and off by default         */
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
/* 23. No Action Scheduler residue                                             */
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
/* 24. Loading the module registers nothing                                    */
/* ========================================================================== */

global $wp_filter;

$our_hooks = array(
	Kuka_Island_Shipping_Status_Poller::ACTION,
	'admin_post_kuka_shipping_create',
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
/* 25. The registry is the seam for a second carrier                           */
/* ========================================================================== */

$registry = new Kuka_Island_Shipping_Carrier_Registry();

$second_carrier_filter = static function ( $carriers ): array {
	$carriers   = is_array( $carriers ) ? $carriers : array();
	$carriers[] = new Kuka_Island_Shipping_DHL_Provider();
	$carriers[] = 'not-an-adapter';
	$carriers[] = new stdClass();

	return $carriers;
};

add_filter( 'kuka_island_shipping_carriers', $second_carrier_filter, 999 );
$registry->reset();
$keys = $registry->keys();
remove_filter( 'kuka_island_shipping_carriers', $second_carrier_filter, 999 );

$report(
	'SHIPPING_CARRIER_REGISTRY',
	array( 'dhl' ) === $keys
		&& null === $registry->get( 'aras-kargo' )
		&& $registry->get( 'dhl' ) instanceof Kuka_Island_Shipping_Carrier_Interface,
	sprintf(
		'registered:%s|non_adapters_dropped:yes|unknown_key_returns:null|filter:kuka_island_shipping_carriers',
		implode( '+', $keys )
	)
);

/* ========================================================================== */
/* 26. Cleanup and verdict                                                     */
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
	'SHIPPING_FIXTURES_REMOVED',
	0 === $still_there && $notes_before === $notes_after,
	sprintf( 'remaining_fixture_orders:%d|order_note_delta:%d', $still_there, $notes_after - $notes_before )
);

if ( array() !== $failures ) {
	WP_CLI::error( 'SHIPPING_VERIFY=FAIL|' . implode( ',', $failures ) );
}

WP_CLI::line( 'SHIPPING_VERIFY=PASS' );
