<?php
/**
 * Isolated EDM sandbox end-to-end invoice experiment (driver).
 *
 * All decision logic lives in scripts/lib-edm-sandbox.php so it can be proved
 * with fixtures and a mocked transport by
 * scripts/verify-edm-sandbox-harness.php. This file only sequences the steps
 * and prints safe verdicts.
 *
 * Questions measured, on the EDM TEST endpoint only:
 *   Q1 Does EDM assign the document number when INVOICE/@ID is omitted and
 *      LoadInvoice carries GENERATEINVOICEIDONLOAD = true?
 *   Q2 Does the assigned number reach the UBL cbc:ID of the stored document?
 *   Q3 Do UUID, payable amount and VAT survive the round trip unchanged?
 *
 * Refusals, all unconditional:
 *   - live environment
 *   - APPLICATION_NAME other than ozelyazilim.kukaisland
 *   - any of the seven sender/recipient verifications failing
 *   - no exclusive claim lock (another run in progress)
 *   - state in_flight, uncertain, confirmed or failed_definitive
 *   - KUKA_EDM_ALLOW_SANDBOX_WRITE not the literal string "true"
 *   - --confirm=<operation> absent or not matching the planned operation
 *
 * Nothing here creates a WooCommerce order or writes any database row. The
 * production Kuka_Island_Core_Invoice_Manager and its
 * invoice_numbering_unconfirmed guard are neither used nor relaxed, and no
 * write-capable method is added to the plugin: the LoadInvoice request is
 * assembled here and issued through the transport the client already owns.
 *
 * Run only through ./scripts/edm-sandbox-run.sh
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

require_once __DIR__ . '/lib-edm-test-credentials.php';
require_once __DIR__ . '/lib-edm-sandbox.php';

$all_steps = array(
	'SANDBOX_PRECHECK',
	'SANDBOX_SENDER_IDENTITY',
	'SANDBOX_PLAN',
	'SANDBOX_CLAIM',
	'SANDBOX_DUPLICATE_GUARD',
	'SANDBOX_CREATE',
	'SANDBOX_NUMBER_ASSIGNED',
	'SANDBOX_STATUS_READBACK',
	'SANDBOX_XML_READBACK',
	'SANDBOX_CBC_ID_READBACK',
);

$block = static function ( string $step, string $reason ): void {
	WP_CLI::line( sprintf( '%s=BLOCKED|reason:%s', $step, $reason ) );
};

$block_from = static function ( array $steps, string $reason ) use ( $block ): void {
	foreach ( $steps as $step ) {
		$block( $step, $reason );
	}
	WP_CLI::line( 'SANDBOX_WRITE_OPERATIONS=NONE|count:0' );
};

$cli_args = (array) ( $args ?? array() );

/* ========================================================================== */
/* Gate: credentials                                                           */
/* ========================================================================== */

$loaded = kuka_edm_test_credentials( true );
if ( ! $loaded['available'] ) {
	$block_from( $all_steps, $loaded['reason'] );
	exit( 0 );
}

$config = new Kuka_Island_Core_Invoice_Config( $loaded['overrides'] );

/* ========================================================================== */
/* Gate: test endpoint and application name                                    */
/* ========================================================================== */

if ( $config->is_live() ) {
	$block_from( $all_steps, 'live_endpoint_refused' );
	WP_CLI::error( 'Sandbox experiment refuses to run against a live endpoint.' );
}
if ( 'ozelyazilim.kukaisland' !== $config->get_application_name() ) {
	$block_from( $all_steps, 'unexpected_application_name' );
	WP_CLI::error( 'APPLICATION_NAME contract violated.' );
}

WP_CLI::line(
	sprintf(
		'SANDBOX_PRECHECK=PASS|environment:%s|application_name_ok:yes|credentials:%s',
		$config->get_environment(),
		kuka_edm_test_presence_summary( $loaded['presence'] )
	)
);

$sandbox_receiver = (string) ( $loaded['sandbox']['receiver_vkn'] ?? '' );
$sandbox_profile  = (string) ( $loaded['sandbox']['profile_id'] ?? '' );

/* ========================================================================== */
/* Read-only fact gathering for the fail-closed sender verification             */
/* ========================================================================== */

$client   = new Kuka_Island_Core_EDM_Client( $config );
$login_ok = false;
try {
	$login_ok = '' !== $client->login();
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	$block( 'SANDBOX_SENDER_IDENTITY', 'login_failed:' . $e->get_safe_error_code() );
}

if ( ! $login_ok ) {
	$block_from( array_slice( $all_steps, 2 ), 'login_failed' );
	WP_CLI::error( 'Login to the EDM test endpoint failed.' );
}

$registered_codes = array();
$serial_query_ok  = false;
try {
	$serials          = $client->get_invoice_serial( '', (int) gmdate( 'Y' ), '' );
	$registered_codes = array_map(
		static fn( array $row ): string => (string) ( $row['code'] ?? '' ),
		(array) ( $serials['serials'] ?? array() )
	);
	$serial_query_ok  = true;
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	$serial_query_ok = false;
}

$check_user_ok = false;
$edm_alias     = '';
if ( '' !== $config->get_sender_vkn() ) {
	try {
		$user          = $client->check_user( $config->get_sender_vkn() );
		$check_user_ok = true;
		$edm_alias     = (string) ( $user['alias'] ?? '' );
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		$check_user_ok = false;
	}
}

$verification = kuka_sandbox_verify_sender(
	array(
		'series_code'             => $config->get_series_earchive(),
		'registered_serial_codes' => $registered_codes,
		'check_user_ok'           => $check_user_ok,
		'edm_alias'               => $edm_alias,
		'configured_alias'        => $config->get_sender_alias(),
		'company_fields'          => array(
			'sender_vkn'        => $config->get_sender_vkn(),
			'sender_alias'      => $config->get_sender_alias(),
			'sender_title'      => $config->get_sender_title(),
			'sender_tax_office' => $config->get_sender_tax_office(),
			'sender_address'    => $config->get_sender_address(),
			'sender_district'   => $config->get_sender_district(),
			'sender_city'       => $config->get_sender_city(),
			'sender_postcode'   => $config->get_sender_postcode(),
		),
		'sandbox_receiver_vkn'    => $sandbox_receiver,
		'profile_id'              => $sandbox_profile,
	)
);

$check_summary = implode(
	'|',
	array_map(
		static fn( string $k, bool $v ): string => $k . ':' . ( $v ? 'yes' : 'no' ),
		array_keys( $verification['checks'] ),
		$verification['checks']
	)
);

if ( ! $verification['ok'] ) {
	WP_CLI::line(
		sprintf(
			'SANDBOX_SENDER_IDENTITY=BLOCKED|failed:%s|%s|serial_query_ok:%s|registered_serials:%d',
			implode( ',', $verification['failed'] ),
			$check_summary,
			$serial_query_ok ? 'yes' : 'no',
			count( $registered_codes )
		)
	);
	$block_from( array_slice( $all_steps, 2 ), 'sender_verification_failed' );
	$client->logout();
	WP_CLI::log( 'Nothing was invented and nothing was created. Supply the listed fields, then re-run.' );
	exit( 0 );
}

WP_CLI::line(
	sprintf(
		'SANDBOX_SENDER_IDENTITY=PASS|%s|registered_serials:%d',
		$check_summary,
		count( $registered_codes )
	)
);

/* ========================================================================== */
/* PLAN                                                                        */
/* ========================================================================== */

$uuid = kuka_sandbox_uuid();

try {
	$built = kuka_sandbox_build_ubl(
		array(
			'vkn'        => $config->get_sender_vkn(),
			'name'       => $config->get_sender_title(),
			'tax_office' => $config->get_sender_tax_office(),
			'address'    => $config->get_sender_address(),
			'district'   => $config->get_sender_district(),
			'city'       => $config->get_sender_city(),
			'postcode'   => $config->get_sender_postcode(),
			'country'    => 'Türkiye',
			'email'      => '',
			'phone'      => '',
		),
		$sandbox_receiver,
		$sandbox_profile,
		$uuid
	);
} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
	$block( 'SANDBOX_PLAN', 'ubl_build_failed:' . $e->get_safe_error_code() );
	$block_from( array_slice( $all_steps, 3 ), 'no_document_built' );
	$client->logout();
	exit( 0 );
}

// WSDL: GENERATEINVOICEIDONLOAD (xs:boolean, required) exists ONLY on
// LoadInvoiceRequest; SendInvoiceRequest has no equivalent. The single write
// operation for this experiment is therefore LoadInvoice.
$planned_operation = 'LoadInvoice';

// Supplying a PROFILEID is not the same as EDM having confirmed it. PLAN reports
// the confirmation state without echoing any value; the write gate below is what
// the confirmation actually controls.
$profile_gate = kuka_sandbox_profile_confirmation(
	$sandbox_profile,
	(string) ( $loaded['sandbox']['profile_id_confirmed'] ?? '' )
);

WP_CLI::line(
	sprintf(
		'SANDBOX_PLAN=PASS|operation:%s|generate_invoice_id_on_load:true|invoice_id_attribute:omitted|ubl_cbc_id_sent:%s|vat_percent:%s|net:%s|tax:%s|payable:%s|uuid_deterministic:yes|profile_confirmed:%s',
		$planned_operation,
		$built['cbc_id_sent'] ? 'present' : 'absent',
		$built['totals']['percent'],
		$built['totals']['net'],
		$built['totals']['tax'],
		$built['totals']['payable'],
		$profile_gate['ok'] ? 'yes' : 'no'
	)
);

/* ========================================================================== */
/* Claim: exclusive lock plus corrupt-aware state machine                      */
/* ========================================================================== */

$claim = new Kuka_Sandbox_Claim( KUKA_SANDBOX_STATE_DIR . '/sandbox-e2e.json' );

if ( ! $claim->acquire() ) {
	$block( 'SANDBOX_CLAIM', 'exclusive_lock_unavailable_another_run_in_progress' );
	$block_from( array_slice( $all_steps, 4 ), 'no_claim' );
	$client->logout();
	exit( 1 );
}

$status    = $claim->status();
$state_now = $status['state'];

/**
 * Read-only reconciliation. Never a write, never a PASS for a failed query.
 */
$reconcile_only = static function ( string $reason, string $recorded_number ) use ( $client, $uuid, $block ): void {
	$block( 'SANDBOX_CREATE', $reason );
	$block( 'SANDBOX_NUMBER_ASSIGNED', 'no_new_document' );

	$status_ok = false;
	$mapped    = '';
	try {
		$result    = $client->get_invoice_status( $uuid, $recorded_number );
		$status_ok = true;
		$mapped    = $result->get_status();
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		$mapped = 'query_failed:' . $e->get_safe_error_code();
	}
	WP_CLI::line( sprintf( 'SANDBOX_STATUS_READBACK=%s|mode:reconcile_only|status:%s', $status_ok ? 'PASS' : 'FAIL', $mapped ) );
	$block( 'SANDBOX_XML_READBACK', 'reconcile_only_no_new_document' );
	$block( 'SANDBOX_CBC_ID_READBACK', 'reconcile_only_no_new_document' );
	WP_CLI::line( 'SANDBOX_WRITE_OPERATIONS=NONE|count:0' );
};

if ( Kuka_Sandbox_Claim::S_CORRUPT === $state_now ) {
	// A damaged record may be hiding an in-flight or confirmed document. It is
	// never treated as idle and it is never repaired by deleting the file.
	WP_CLI::line( sprintf( 'SANDBOX_CLAIM=BLOCKED|state:corrupt|reason:%s|write_refused:yes', $status['reason'] ) );
	WP_CLI::line( 'SANDBOX_DUPLICATE_GUARD=PASS|state:corrupt|second_write_refused:yes' );
	$reconcile_only( 'claim_record_corrupt', '' );
	WP_CLI::log( 'Do not delete or reset the state file. Reconcile the deterministic UUID against EDM first, then decide.' );
	$claim->release();
	$client->logout();
	exit( 1 );
}

WP_CLI::line( sprintf( 'SANDBOX_CLAIM=PASS|lock:acquired|state:%s|record:%s', $state_now, $status['reason'] ) );

if ( Kuka_Sandbox_Claim::S_IDLE !== $state_now ) {
	WP_CLI::line( sprintf( 'SANDBOX_DUPLICATE_GUARD=PASS|state:%s|second_write_refused:yes', $state_now ) );
	$reconcile_only( 'state_' . $state_now, (string) ( $status['record']['assigned_number'] ?? '' ) );
	$claim->release();
	$client->logout();
	exit( 0 );
}

WP_CLI::line( 'SANDBOX_DUPLICATE_GUARD=PASS|state:idle|uuid_deterministic:yes|edm_duplicate_detection:second_layer' );

/* ========================================================================== */
/* Gates: PROFILEID confirmation, explicit env opt-in, operation confirmation   */
/* ========================================================================== */

if ( ! $profile_gate['ok'] ) {
	WP_CLI::line( sprintf( 'SANDBOX_PROFILE_GATE=BLOCKED|profile_confirmed:no|reason:%s', $profile_gate['reason'] ) );
	$block( 'SANDBOX_CREATE', 'profileid_not_confirmed_by_edm' );
	$block_from( array_slice( $all_steps, 6 ), 'no_document_created' );
	WP_CLI::log( 'EDM has not confirmed a PROFILEID in writing. No value is guessed and no document is created.' );
	$claim->release();
	$client->logout();
	exit( 0 );
}
WP_CLI::line( 'SANDBOX_PROFILE_GATE=PASS|profile_confirmed:yes|byte_for_byte_match:yes' );

$allow_write = 'true' === (string) getenv( 'KUKA_EDM_ALLOW_SANDBOX_WRITE' );

$confirmed_operation = '';
foreach ( $cli_args as $arg ) {
	if ( is_string( $arg ) && str_starts_with( $arg, '--confirm=' ) ) {
		$confirmed_operation = substr( $arg, strlen( '--confirm=' ) );
	}
}

if ( ! $allow_write || $planned_operation !== $confirmed_operation ) {
	WP_CLI::line( '' );
	WP_CLI::line( '>>> A WRITE OPERATION IS REQUIRED TO CONTINUE <<<' );
	WP_CLI::line( sprintf( '>>> Operation that would be called: %s (EDM test endpoint)', $planned_operation ) );
	WP_CLI::line( '>>> Effect: a persistent test document is created in the EDM test account.' );
	WP_CLI::line( '>>> Nothing has been created. Both gates are required:' );
	WP_CLI::line( '>>>   1) KUKA_EDM_ALLOW_SANDBOX_WRITE=true  (literal)' );
	WP_CLI::line( sprintf( '>>>   2) --confirm=%s', $planned_operation ) );
	WP_CLI::line( '' );
	$block( 'SANDBOX_CREATE', $allow_write ? 'operation_not_confirmed' : 'sandbox_write_not_enabled' );
	$block_from( array_slice( $all_steps, 6 ), 'no_document_created' );
	$claim->release();
	$client->logout();
	exit( 0 );
}

/* ========================================================================== */
/* The single write call, through the shared, harness-tested write path         */
/* ========================================================================== */

$issue_date = gmdate( 'Y-m-d' );
$header     = array(
	'SENDER'                          => $config->get_sender_vkn(),
	'RECEIVER'                        => $sandbox_receiver,
	'FROM'                            => $config->get_sender_alias(),
	'PROFILEID'                       => $sandbox_profile,
	'INVOICE_TYPE'                    => 'SATIS',
	'ISSUE_DATE'                      => $issue_date,
	'PAYABLE_AMOUNT'                  => $built['totals']['payable'],
	'INTERNETSALES'                   => false,
	'EARCHIVE'                        => true,
	'EARCHIVE_REPORT_SENDDATE'        => $issue_date,
	'CANCEL_EARCHIVE_REPORT_SENDDATE' => $issue_date,
	'ISACTIVE'                        => true,
	'MARKED'                          => false,
);

// Only bind a serial when one is actually configured. Verification already
// guarantees this; the guard is defensive.
$series_code = $config->get_series_earchive();
if ( '' !== $series_code ) {
	$header['INVOICESERIAL_REQUESTED'] = $series_code;
}

$load_request = array(
	'REQUEST_HEADER'          => array(
		'SESSION_ID'       => (string) $client->get_session_id(),
		'ACTION_DATE'      => gmdate( 'Y-m-d\TH:i:s' ),
		'CLIENT_TXN_ID'    => $uuid,
		'APPLICATION_NAME' => $config->get_application_name(),
	),
	'SENDER'                  => array(
		'vkn'   => $config->get_sender_vkn(),
		'alias' => $config->get_sender_alias(),
	),
	'RECEIVER'                => array( 'vkn' => $sandbox_receiver ),
	'INVOICE'                 => array(
		array(
			// INVOICE/@ID deliberately omitted: the experiment observes whether
			// EDM assigns it.
			'TRXID'   => (int) hexdec( substr( hash( 'sha256', $uuid ), 0, 8 ) ),
			'UUID'    => $uuid,
			'HEADER'  => $header,
			'CONTENT' => $built['xml'],
		),
	),
	'GENERATEINVOICEIDONLOAD' => true,
);

WP_CLI::line( sprintf( 'SANDBOX_CREATE=RUNNING|operation:%s|state:in_flight|state_recorded:yes', $planned_operation ) );

$write = kuka_sandbox_execute_write( $claim, $client->get_transport(), $load_request, $uuid, $planned_operation );

if ( ! $write['claimed'] ) {
	$block( 'SANDBOX_CREATE', 'claim_failed:' . $write['claim_reason'] );
	$block_from( array_slice( $all_steps, 6 ), 'no_document_created' );
	$claim->release();
	$client->logout();
	exit( 1 );
}

WP_CLI::line(
	sprintf(
		'SANDBOX_CREATE=%s|operation:%s|classification:%s|state:%s|state_recorded:%s|status:%s',
		$write['create_verdict'],
		$planned_operation,
		$write['classification'],
		(string) ( $write['settle']['state'] ?? 'unknown' ),
		$write['state_recorded'] ? 'yes' : 'no',
		$write['status_token']
	)
);

$assigned_number = (string) $write['assigned_number'];

if ( KUKA_SANDBOX_CALL_SUCCESS !== $write['classification'] ) {
	$block( 'SANDBOX_NUMBER_ASSIGNED', 'document_not_confirmed' );
	$block( 'SANDBOX_STATUS_READBACK', 'document_not_confirmed' );
	$block( 'SANDBOX_XML_READBACK', 'document_not_confirmed' );
	$block( 'SANDBOX_CBC_ID_READBACK', 'document_not_confirmed' );
	WP_CLI::line( sprintf( 'SANDBOX_WRITE_OPERATIONS=%s|count:1|result:%s', $planned_operation, $write['result_label'] ) );
	if ( KUKA_SANDBOX_CALL_UNCERTAIN === $write['classification'] ) {
		WP_CLI::log( 'Uncertain: the call left this process and a document may exist at EDM. No automatic retry. Reconcile the UUID read-only before any further decision.' );
	}
	$claim->release();
	$client->logout();
	exit( $write['exit_code'] );
}

// The call succeeded. Measurements still run even when the confirmed state could
// not be persisted, but the run is not reported as PASS or confirmed.
WP_CLI::line(
	sprintf(
		'SANDBOX_NUMBER_ASSIGNED=PASS|edm_returned_number:yes|number_length:%d|return_code:%d|uuid_echoed_by_edm:yes',
		strlen( $assigned_number ),
		(int) ( $write['parsed']['return_code'] ?? 0 )
	)
);

/* ========================================================================== */
/* Readback                                                                     */
/* ========================================================================== */

$status_ok     = false;
$mapped_status = '';
try {
	$status_result = $client->get_invoice_status( $uuid, $assigned_number );
	$status_ok     = true;
	$mapped_status = $status_result->get_status();
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	$mapped_status = 'query_failed:' . $e->get_safe_error_code();
}
WP_CLI::line( sprintf( 'SANDBOX_STATUS_READBACK=%s|status:%s', $status_ok ? 'PASS' : 'FAIL', $mapped_status ) );

$checks = array(
	'xml_retrieved' => false,
	'xml_parsed'    => false,
	'uuid_match'    => false,
	'payable_match' => false,
	'tax_match'     => false,
);
$cbc_present = false;
$cbc_matches = false;

try {
	$xml_back                = $client->get_invoice_document( $uuid, 'XML' );
	$checks['xml_retrieved'] = '' !== trim( (string) $xml_back );

	if ( $checks['xml_retrieved'] ) {
		$dom                  = new DOMDocument();
		$checks['xml_parsed'] = (bool) $dom->loadXML( $xml_back );
		if ( $checks['xml_parsed'] ) {
			$xp    = new DOMXPath( $dom );
			$one   = static function ( DOMXPath $xp, string $q ): string {
				$n = $xp->query( $q );
				return ( false !== $n && $n->length > 0 ) ? trim( (string) $n->item( 0 )->nodeValue ) : '';
			};
			$cents = static fn( string $v ): int => Kuka_Island_Core_Invoice_Order_Mapper::amount_to_cents( $v );

			$back_id  = $one( $xp, '/*[local-name()="Invoice"]/*[local-name()="ID"]' );
			$back_uid = $one( $xp, '/*[local-name()="Invoice"]/*[local-name()="UUID"]' );
			$back_pay = $one( $xp, '//*[local-name()="LegalMonetaryTotal"]/*[local-name()="PayableAmount"]' );
			$back_tax = $one( $xp, '/*[local-name()="Invoice"]/*[local-name()="TaxTotal"]/*[local-name()="TaxAmount"]' );

			$checks['uuid_match']    = '' !== $back_uid && 0 === strcasecmp( $back_uid, $uuid );
			$checks['payable_match'] = '' !== $back_pay && $cents( $back_pay ) === $cents( $built['totals']['payable'] );
			$checks['tax_match']     = '' !== $back_tax && $cents( $back_tax ) === $cents( $built['totals']['tax'] );

			$cbc_present = '' !== $back_id;
			$cbc_matches = $cbc_present && $back_id === $assigned_number;
		}
	}
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	$checks['xml_retrieved'] = false;
}

$readback = kuka_sandbox_evaluate_readback( $checks );

WP_CLI::line(
	sprintf(
		'SANDBOX_XML_READBACK=%s|xml_retrieved:%s|xml_parsed:%s|uuid_match:%s|payable_match:%s|tax_match:%s|failed:%s',
		$readback['ok'] ? 'PASS' : 'FAIL',
		$checks['xml_retrieved'] ? 'yes' : 'no',
		$checks['xml_parsed'] ? 'yes' : 'no',
		$checks['uuid_match'] ? 'yes' : 'no',
		$checks['payable_match'] ? 'yes' : 'no',
		$checks['tax_match'] ? 'yes' : 'no',
		empty( $readback['failed'] ) ? 'none' : implode( ',', $readback['failed'] )
	)
);

WP_CLI::line(
	sprintf(
		'SANDBOX_CBC_ID_READBACK=%s|cbc_id_present_in_stored_xml:%s|matches_assigned_number:%s',
		$cbc_matches ? 'PASS' : 'FAIL',
		$cbc_present ? 'yes' : 'no',
		$cbc_matches ? 'yes' : 'no'
	)
);

WP_CLI::line( sprintf( 'SANDBOX_WRITE_OPERATIONS=%s|count:1|result:%s', $planned_operation, $write['result_label'] ) );

$claim->release();
$client->logout();

if ( 0 !== $write['exit_code'] ) {
	WP_CLI::error(
		sprintf(
			'LoadInvoice succeeded at EDM but the confirmed state could not be persisted (%s). The on-disk record still says in_flight, a second write stays refused, and manual reconciliation is required.',
			$write['status_token']
		)
	);
}

WP_CLI::success( 'EDM sandbox experiment finished.' );
