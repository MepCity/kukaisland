<?php
/**
 * Fixture and mock verification for the EDM sandbox harness.
 *
 * Proves every refusal path of scripts/lib-edm-sandbox.php and the credential
 * parser WITHOUT any network call, without any EDM operation and without
 * creating any document. No WooCommerce order or database row is touched.
 *
 * Run with:
 * docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-edm-sandbox-harness.php
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

require_once __DIR__ . '/lib-edm-test-credentials.php';
require_once __DIR__ . '/lib-edm-sandbox.php';

$failures = array();
$report   = static function ( string $name, bool $passed, string $detail = '' ) use ( &$failures ): void {
	WP_CLI::line( sprintf( '%s=%s%s', $name, $passed ? 'PASS' : 'FAIL', '' !== $detail ? '|' . $detail : '' ) );
	if ( ! $passed ) {
		$failures[] = $name;
	}
};

/* ========================================================================== */
/* Credential parser: values are stored verbatim                               */
/* ========================================================================== */

$raw = "# comment line\n"
	. "KUKA_EDM_USERNAME=plain_user\n"
	. "KUKA_EDM_PASSWORD=p=a=s s\"w'ord \n"
	. "KUKA_EDM_SECRET_KEY=\"quoted-stays-quoted\"\n"
	. "KUKA_EDM_SENDER_ALIAS=urn:mail:box@example.com\r\n"
	. "IGNORED_KEY=whatever\n"
	. "\n"
	. "   # indented comment\n"
	. "KUKA_EDM_SANDBOX_RECEIVER_VKN=11223344556\n"
	. "KUKA_EDM_SANDBOX_PROFILE_ID=SOME_PROFILE\n";

$parsed_file = kuka_edm_parse_credential_file( $raw );

$report(
	'SANDBOX_CRED_PARSER_VERBATIM',
	'plain_user' === ( $parsed_file['KUKA_EDM_USERNAME'] ?? null )
	// Everything after the first '=' survives, including further '=' and quotes,
	// and the trailing space is NOT trimmed.
	&& 'p=a=s s"w\'ord ' === ( $parsed_file['KUKA_EDM_PASSWORD'] ?? null )
	// Quotes are part of the value: nothing is unquoted.
	&& '"quoted-stays-quoted"' === ( $parsed_file['KUKA_EDM_SECRET_KEY'] ?? null )
	// Only a trailing CR is removed for CRLF files.
	&& 'urn:mail:box@example.com' === ( $parsed_file['KUKA_EDM_SENDER_ALIAS'] ?? null )
	&& ! array_key_exists( 'IGNORED_KEY', $parsed_file )
	&& '11223344556' === ( $parsed_file['KUKA_EDM_SANDBOX_RECEIVER_VKN'] ?? null )
	&& 'SOME_PROFILE' === ( $parsed_file['KUKA_EDM_SANDBOX_PROFILE_ID'] ?? null ),
	sprintf(
		'keys_recognised:%d|equals_in_value_preserved:%s|trailing_space_preserved:%s|quotes_preserved:%s|crlf_handled:%s|unknown_key_ignored:%s',
		count( $parsed_file ),
		str_contains( (string) ( $parsed_file['KUKA_EDM_PASSWORD'] ?? '' ), '=a=' ) ? 'yes' : 'no',
		str_ends_with( (string) ( $parsed_file['KUKA_EDM_PASSWORD'] ?? '' ), ' ' ) ? 'yes' : 'no',
		str_starts_with( (string) ( $parsed_file['KUKA_EDM_SECRET_KEY'] ?? '' ), '"' ) ? 'yes' : 'no',
		'urn:mail:box@example.com' === ( $parsed_file['KUKA_EDM_SENDER_ALIAS'] ?? '' ) ? 'yes' : 'no',
		array_key_exists( 'IGNORED_KEY', $parsed_file ) ? 'no' : 'yes'
	)
);

/* ========================================================================== */
/* Sender verification: fail-closed on every single boolean                     */
/* ========================================================================== */

$complete_company = array(
	'sender_vkn'        => '1234567890',
	'sender_alias'      => 'urn:mail:box@example.com',
	'sender_title'      => 'Test A.S.',
	'sender_tax_office' => 'Kadikoy',
	'sender_address'    => 'Moda Cad. 1',
	'sender_district'   => 'Kadikoy',
	'sender_city'       => 'Istanbul',
	'sender_postcode'   => '34710',
);

$good_facts = array(
	'series_code'             => 'KUK',
	'registered_serial_codes' => array( 'AAA', 'kuk', 'ZZZ' ),
	'check_user_ok'           => true,
	'edm_alias'               => 'urn:mail:box@example.com',
	'configured_alias'        => 'urn:mail:box@example.com',
	'company_fields'          => $complete_company,
	'sandbox_receiver_vkn'    => '11223344556',
	'profile_id'              => 'SOME_PROFILE',
);

$baseline = kuka_sandbox_verify_sender( $good_facts );
$report(
	'SANDBOX_VERIFY_ALL_PASS_ALLOWS_PLAN',
	true === $baseline['ok'] && array() === $baseline['failed'] && 7 === count( $baseline['checks'] ),
	sprintf( 'checks:%d|failed:%s', count( $baseline['checks'] ), empty( $baseline['failed'] ) ? 'none' : implode( ',', $baseline['failed'] ) )
);

$negatives = array(
	'missing_series'        => array( 'series_code' => '', 'expect' => 'series_configured' ),
	'malformed_series'      => array( 'series_code' => 'KUKA', 'expect' => 'series_configured' ),
	'unregistered_series'   => array( 'registered_serial_codes' => array( 'AAA', 'BBB' ), 'expect' => 'series_registered_at_edm' ),
	'no_registered_serials' => array( 'registered_serial_codes' => array(), 'expect' => 'series_registered_at_edm' ),
	'check_user_failed'     => array( 'check_user_ok' => false, 'expect' => 'check_user_ok' ),
	'alias_mismatch'        => array( 'edm_alias' => 'urn:mail:OTHER@example.com', 'expect' => 'alias_exact_match' ),
	'alias_case_mismatch'   => array( 'edm_alias' => 'URN:MAIL:BOX@EXAMPLE.COM', 'expect' => 'alias_exact_match' ),
	'alias_whitespace'      => array( 'edm_alias' => ' urn:mail:box@example.com', 'expect' => 'alias_exact_match' ),
	'empty_configured_alias' => array( 'configured_alias' => '', 'expect' => 'alias_exact_match' ),
	'missing_receiver'      => array( 'sandbox_receiver_vkn' => '', 'expect' => 'sandbox_receiver_supplied' ),
	'malformed_receiver'    => array( 'sandbox_receiver_vkn' => '123', 'expect' => 'sandbox_receiver_supplied' ),
	'missing_profile'       => array( 'profile_id' => '', 'expect' => 'profile_id_supplied' ),
	'whitespace_profile'    => array( 'profile_id' => '   ', 'expect' => 'profile_id_supplied' ),
);

$negative_results = array();
$negatives_ok     = true;
foreach ( $negatives as $case => $mutation ) {
	$expected = $mutation['expect'];
	unset( $mutation['expect'] );
	$result = kuka_sandbox_verify_sender( array_merge( $good_facts, $mutation ) );
	$hit    = ( false === $result['ok'] ) && in_array( $expected, $result['failed'], true );
	$negative_results[ $case ] = $hit ? 'blocked' : 'LEAKED';
	if ( ! $hit ) {
		$negatives_ok = false;
	}
}

// Each of the eight company/address fields, one at a time.
foreach ( array_keys( $complete_company ) as $field ) {
	$broken            = $complete_company;
	$broken[ $field ]  = '';
	$result            = kuka_sandbox_verify_sender( array_merge( $good_facts, array( 'company_fields' => $broken ) ) );
	$hit               = ( false === $result['ok'] ) && in_array( 'company_fields_complete', $result['failed'], true );
	$negative_results[ 'company_' . $field ] = $hit ? 'blocked' : 'LEAKED';
	if ( ! $hit ) {
		$negatives_ok = false;
	}
}

$report(
	'SANDBOX_VERIFY_NEGATIVE_MATRIX',
	$negatives_ok,
	sprintf(
		'cases:%d|leaked:%s',
		count( $negative_results ),
		implode( ',', array_keys( array_filter( $negative_results, static fn( string $v ): bool => 'blocked' !== $v ) ) ) ?: 'none'
	)
);

/* ========================================================================== */
/* LoadInvoiceResponse parser fixtures                                          */
/* ========================================================================== */

$uuid = kuka_sandbox_uuid();

$fixtures = array(
	'success'                => array(
		'response' => array(
			'REQUEST_RETURN' => array( 'INTL_TXN_ID' => 7, 'RETURN_CODE' => 0 ),
			'INVOICE'        => array( array( 'TRXID' => 1, 'UUID' => $uuid, 'ID' => 'KUK2026000000123' ) ),
		),
		'expect'   => array( 'ok' => true, 'outcome' => 'success', 'number' => 'KUK2026000000123' ),
	),
	'success_single_object'  => array(
		'response' => (object) array(
			'REQUEST_RETURN' => (object) array( 'RETURN_CODE' => 0 ),
			'INVOICE'        => (object) array( 'UUID' => strtoupper( $uuid ), 'ID' => 'KUK2026000000124' ),
		),
		'expect'   => array( 'ok' => true, 'outcome' => 'success', 'number' => 'KUK2026000000124' ),
	),
	'business_error'         => array(
		'response' => array(
			'REQUEST_RETURN' => array( 'RETURN_CODE' => 1, 'WARNINGS' => array( 'sema hatasi' ) ),
			'INVOICE'        => array( array( 'UUID' => $uuid, 'ID' => 'KUK2026000000125' ) ),
		),
		'expect'   => array( 'ok' => false, 'outcome' => 'business_error', 'number' => '' ),
	),
	'empty_id'               => array(
		'response' => array(
			'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			'INVOICE'        => array( array( 'UUID' => $uuid, 'ID' => '' ) ),
		),
		'expect'   => array( 'ok' => false, 'outcome' => 'empty_id', 'number' => '' ),
	),
	'whitespace_id'          => array(
		'response' => array(
			'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			'INVOICE'        => array( array( 'UUID' => $uuid, 'ID' => '   ' ) ),
		),
		'expect'   => array( 'ok' => false, 'outcome' => 'empty_id', 'number' => '' ),
	),
	'uuid_mismatch'          => array(
		'response' => array(
			'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			'INVOICE'        => array( array( 'UUID' => 'ffffffff-0000-4000-8000-000000000000', 'ID' => 'KUK2026000000126' ) ),
		),
		'expect'   => array( 'ok' => false, 'outcome' => 'uuid_mismatch', 'number' => '' ),
	),
	'nested_unrelated_id'    => array(
		'response' => array(
			'REQUEST_RETURN' => array( 'RETURN_CODE' => 0, 'CLIENT_TXN_ID' => 'NOT-A-NUMBER' ),
			'INVOICE'        => array(
				array(
					'UUID'   => $uuid,
					// No top-level ID. A nested ID must never be promoted.
					'HEADER' => array( 'ID' => 'NESTED-SHOULD-NOT-BE-USED', 'ENVELOPE_IDENTIFIER' => 'x' ),
					'LINES'  => array( array( 'ID' => 'ALSO-NOT-A-DOCUMENT-NUMBER' ) ),
				),
			),
		),
		'expect'   => array( 'ok' => false, 'outcome' => 'empty_id', 'number' => '' ),
	),
	'malformed_string'       => array(
		'response' => 'not a structure',
		'expect'   => array( 'ok' => false, 'outcome' => 'malformed', 'number' => '' ),
	),
	'malformed_null'         => array(
		'response' => null,
		'expect'   => array( 'ok' => false, 'outcome' => 'malformed', 'number' => '' ),
	),
	'missing_request_return' => array(
		'response' => array( 'INVOICE' => array( array( 'UUID' => $uuid, 'ID' => 'KUK1' ) ) ),
		'expect'   => array( 'ok' => false, 'outcome' => 'malformed', 'number' => '' ),
	),
	'non_numeric_code'       => array(
		'response' => array(
			'REQUEST_RETURN' => array( 'RETURN_CODE' => 'OK' ),
			'INVOICE'        => array( array( 'UUID' => $uuid, 'ID' => 'KUK1' ) ),
		),
		'expect'   => array( 'ok' => false, 'outcome' => 'malformed', 'number' => '' ),
	),
	'missing_invoice'        => array(
		'response' => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) ),
		'expect'   => array( 'ok' => false, 'outcome' => 'malformed', 'number' => '' ),
	),
);

$parser_ok      = true;
$parser_details = array();
foreach ( $fixtures as $case => $fixture ) {
	$out      = kuka_sandbox_parse_load_invoice_response( $fixture['response'], $uuid );
	$expected = $fixture['expect'];
	$hit      = ( $out['ok'] === $expected['ok'] )
		&& ( $out['outcome'] === $expected['outcome'] )
		&& ( $out['assigned_number'] === $expected['number'] );
	$parser_details[ $case ] = $hit ? $out['outcome'] : ( 'MISMATCH(' . $out['outcome'] . '/' . $out['assigned_number'] . ')' );
	if ( ! $hit ) {
		$parser_ok = false;
	}
}

$report(
	'SANDBOX_LOAD_RESPONSE_PARSER',
	$parser_ok,
	sprintf(
		'fixtures:%d|%s',
		count( $fixtures ),
		implode( ' ', array_map( static fn( string $k, string $v ): string => $k . '=' . $v, array_keys( $parser_details ), $parser_details ) )
	)
);

/* ========================================================================== */
/* Readback verdict cannot be PASS with a failing mandatory check               */
/* ========================================================================== */

$all_true  = array(
	'xml_retrieved' => true,
	'xml_parsed'    => true,
	'uuid_match'    => true,
	'payable_match' => true,
	'tax_match'     => true,
);
$readback_ok      = true === kuka_sandbox_evaluate_readback( $all_true )['ok'];
$readback_details = array();
foreach ( array_keys( $all_true ) as $key ) {
	$broken         = $all_true;
	$broken[ $key ] = false;
	$verdict        = kuka_sandbox_evaluate_readback( $broken );
	$hit            = ( false === $verdict['ok'] ) && in_array( $key, $verdict['failed'], true );
	$readback_details[ $key ] = $hit ? 'fails_correctly' : 'LEAKED';
	if ( ! $hit ) {
		$readback_ok = false;
	}
}
// A completely empty check set must also fail, never default to PASS.
if ( false !== kuka_sandbox_evaluate_readback( array() )['ok'] ) {
	$readback_ok              = false;
	$readback_details['empty'] = 'LEAKED';
}

$report(
	'SANDBOX_READBACK_VERDICT_FAIL_CLOSED',
	$readback_ok,
	implode( '|', array_map( static fn( string $k, string $v ): string => $k . ':' . $v, array_keys( $readback_details ), $readback_details ) )
);

/* ========================================================================== */
/* Claim state machine: lock, transitions, atomic persistence                   */
/* ========================================================================== */

$tmp_root = rtrim( sys_get_temp_dir(), '/' ) . '/kuka-sandbox-harness-' . bin2hex( random_bytes( 6 ) );
mkdir( $tmp_root, 0700, true );
$state_file = $tmp_root . '/state.json';

// Two independent claims on the same file: only one may hold the lock.
$claim_a = new Kuka_Sandbox_Claim( $state_file );
$claim_b = new Kuka_Sandbox_Claim( $state_file );
$a_lock  = $claim_a->acquire();
$b_lock  = $claim_b->acquire();
$report(
	'SANDBOX_CLAIM_SINGLE_HOLDER',
	true === $a_lock && false === $b_lock,
	sprintf( 'first_acquire:%s|second_acquire:%s', $a_lock ? 'yes' : 'no', $b_lock ? 'yes' : 'no' )
);

// A claim requires the lock.
$unlocked      = new Kuka_Sandbox_Claim( $tmp_root . '/other.json' );
$unlocked_call = $unlocked->claim( $uuid, 'LoadInvoice' );
$report(
	'SANDBOX_CLAIM_REQUIRES_LOCK',
	false === $unlocked_call['ok'] && 'lock_not_held' === $unlocked_call['reason'],
	sprintf( 'reason:%s', $unlocked_call['reason'] )
);

// idle -> in_flight, then a second claim in the same state is refused.
$first  = $claim_a->claim( $uuid, 'LoadInvoice' );
$second = $claim_a->claim( $uuid, 'LoadInvoice' );
$report(
	'SANDBOX_CLAIM_IDLE_TO_IN_FLIGHT',
	true === $first['ok'] && Kuka_Sandbox_Claim::S_IN_FLIGHT === $first['state'] && true === $first['written']
	&& false === $second['ok'] && str_contains( $second['reason'], 'in_flight' ),
	sprintf( 'first:%s/%s|second_refused:%s', $first['state'], $first['written'] ? 'written' : 'not_written', $second['reason'] )
);

$mode = substr( sprintf( '%o', fileperms( $state_file ) ), -3 );
$report( 'SANDBOX_CLAIM_STATE_FILE_MODE_600', '600' === $mode, sprintf( 'mode:%s', $mode ) );

// Transport uncertainty settles to uncertain, and a second write is refused.
$settled_uncertain = $claim_a->settle( Kuka_Sandbox_Claim::S_UNCERTAIN, array( 'outcome' => 'transport_uncertain' ) );
$after_uncertain   = $claim_a->claim( $uuid, 'LoadInvoice' );
$report(
	'SANDBOX_CLAIM_TIMEOUT_UNCERTAIN_NO_SECOND_WRITE',
	true === $settled_uncertain['ok'] && Kuka_Sandbox_Claim::S_UNCERTAIN === $settled_uncertain['state']
	&& false === $after_uncertain['ok'] && str_contains( $after_uncertain['reason'], 'uncertain' ),
	sprintf( 'settled:%s|second_write:%s', $settled_uncertain['state'], $after_uncertain['reason'] )
);

// uncertain -> idle only with explicit absence evidence.
$bad_reset  = $claim_a->reset_after_reconcile( 'i_think_it_is_fine' );
$good_reset = $claim_a->reset_after_reconcile( 'document_absent_at_edm' );
$report(
	'SANDBOX_CLAIM_RECONCILE_REQUIRES_EVIDENCE',
	false === $bad_reset['ok'] && 'reset_requires_document_absent_evidence' === $bad_reset['reason']
	&& true === $good_reset['ok'] && Kuka_Sandbox_Claim::S_IDLE === $good_reset['state'],
	sprintf( 'weak_evidence:%s|strong_evidence:%s', $bad_reset['reason'], $good_reset['state'] )
);

// confirmed and failed_definitive both refuse a further claim.
$terminal_ok = true;
$terminal_detail = array();
foreach ( array( Kuka_Sandbox_Claim::S_CONFIRMED, Kuka_Sandbox_Claim::S_FAILED_DEFINITIVE ) as $terminal ) {
	$file = $tmp_root . '/terminal-' . $terminal . '.json';
	$c    = new Kuka_Sandbox_Claim( $file );
	$c->acquire();
	$c->claim( $uuid, 'LoadInvoice' );
	$c->settle( $terminal );
	$again = $c->claim( $uuid, 'LoadInvoice' );
	$hit   = false === $again['ok'] && str_contains( $again['reason'], $terminal );
	$terminal_detail[ $terminal ] = $hit ? 'refused' : 'LEAKED';
	if ( ! $hit ) {
		$terminal_ok = false;
	}
	$c->release();
}
$report(
	'SANDBOX_CLAIM_TERMINAL_STATES_REFUSE',
	$terminal_ok,
	implode( '|', array_map( static fn( string $k, string $v ): string => $k . ':' . $v, array_keys( $terminal_detail ), $terminal_detail ) )
);

// settle() is only legal from in_flight, and only to an allowed target.
$stray = new Kuka_Sandbox_Claim( $tmp_root . '/stray.json' );
$stray->acquire();
$bad_settle_state  = $stray->settle( Kuka_Sandbox_Claim::S_CONFIRMED );
$stray->claim( $uuid, 'LoadInvoice' );
$bad_settle_target = $stray->settle( 'in_flight' );
$report(
	'SANDBOX_CLAIM_SETTLE_GUARDS',
	false === $bad_settle_state['ok'] && str_contains( $bad_settle_state['reason'], 'settle_refused_from_state_idle' )
	&& false === $bad_settle_target['ok'] && 'invalid_target_state' === $bad_settle_target['reason'],
	sprintf( 'from_idle:%s|bad_target:%s', $bad_settle_state['reason'], $bad_settle_target['reason'] )
);
$stray->release();

// A state file that cannot be written must be reported as not recorded.
$ro_dir = $tmp_root . '/readonly';
mkdir( $ro_dir, 0700, true );
$ro_claim = new Kuka_Sandbox_Claim( $ro_dir . '/state.json' );
$ro_lock  = $ro_claim->acquire();
chmod( $ro_dir, 0500 );
$ro_result = $ro_claim->claim( $uuid, 'LoadInvoice' );
chmod( $ro_dir, 0700 );
$report(
	'SANDBOX_CLAIM_STATE_WRITE_FAILURE_REPORTED',
	true === $ro_lock && false === $ro_result['ok'] && false === $ro_result['written'] && 'state_persist_failed' === $ro_result['reason'],
	sprintf( 'lock:%s|written:%s|reason:%s', $ro_lock ? 'yes' : 'no', $ro_result['written'] ? 'yes' : 'no', $ro_result['reason'] )
);
$ro_claim->release();

$claim_a->release();
$claim_b->release();

// Harness leaves no temporary files behind.
foreach ( (array) glob( $tmp_root . '/*' ) as $leftover ) {
	if ( is_file( $leftover ) ) {
		wp_delete_file( $leftover );
	} elseif ( is_dir( $leftover ) ) {
		foreach ( (array) glob( $leftover . '/*' ) as $inner ) {
			wp_delete_file( $inner );
		}
		rmdir( $leftover );
	}
}
rmdir( $tmp_root );
$report( 'SANDBOX_HARNESS_TEMP_CLEANED', ! is_dir( $tmp_root ), sprintf( 'temp_root_removed:%s', is_dir( $tmp_root ) ? 'no' : 'yes' ) );

/* ========================================================================== */
/* No document-creating capability leaked into the plugin                       */
/* ========================================================================== */

$module_dir   = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/invoice/';
$module_files = glob( $module_dir . '*.php' ) ?: array();
$write_hits   = array();
foreach ( $module_files as $file ) {
	$contents = (string) file_get_contents( $file );
	foreach ( array( "'LoadInvoice'", "'CreateSerial'", "'CancelInvoice'", 'function load_invoice', 'function create_serial' ) as $needle ) {
		if ( str_contains( $contents, $needle ) ) {
			$write_hits[] = basename( $file ) . ':' . $needle;
		}
	}
}
$report(
	'SANDBOX_PLUGIN_HAS_NO_WRITE_CAPABILITY',
	count( $module_files ) >= 15 && empty( $write_hits ),
	sprintf( 'module_files:%d|hits:%s', count( $module_files ), empty( $write_hits ) ? 'none' : implode( ',', $write_hits ) )
);

// Production numbering guard untouched.
$numbering_src = (string) file_get_contents( $module_dir . 'class-invoice-numbering.php' );
$report(
	'SANDBOX_NUMBERING_GUARD_UNTOUCHED',
	str_contains( $numbering_src, "ERROR_UNCONFIRMED = 'invoice_numbering_unconfirmed'" )
	&& str_contains( $numbering_src, 'NUMBER_SOURCE_EDM === $source' ),
	'invoice_numbering_unconfirmed:present|provenance_required:present'
);

if ( ! empty( $failures ) ) {
	WP_CLI::error( sprintf( 'EDM sandbox harness verification failed (%d: %s).', count( $failures ), implode( ', ', $failures ) ) );
}

WP_CLI::success( 'EDM sandbox harness verification passed. No network call, no document, no database write.' );
