<?php
/**
 * Isolated EDM sandbox DRAFT-UPLOAD experiment (driver).
 *
 * What this actually does: it calls LoadInvoice, which uploads the document to
 * EDM as a DRAFT so that it can be sent later. It is NOT SendInvoice, it issues
 * nothing and it delivers nothing to a recipient. SendInvoice is a separate
 * operation, it is out of scope for this round, and the report says so
 * explicitly on every path.
 * https://docs.edmbilisim.com.tr/api/api-documentation/einvoice/referenced/EFaturaEDMConnectorService.LoadInvoiceRequest.html
 * https://docs.edmbilisim.com.tr/api/api-documentation/einvoice/referenced/EFaturaEDMConnectorService.SendInvoiceRequest.html
 *
 * All decision logic lives in scripts/lib-edm-sandbox.php so it can be proved
 * with fixtures and a mocked transport by
 * scripts/verify-edm-sandbox-harness.php. This file only sequences the steps
 * and prints safe verdicts.
 *
 * Questions measured, on the EDM TEST endpoint only:
 *   Q1 Does EDM assign the document number when INVOICE/@ID is omitted and
 *      LoadInvoice carries GENERATEINVOICEIDONLOAD = true?
 *   Q2 Does the assigned number reach the UBL cbc:ID of the stored draft?
 *   Q3 Do UUID, payable amount and VAT survive the round trip unchanged?
 *
 * Refusals, all unconditional:
 *   - live environment
 *   - APPLICATION_NAME other than ozelyazilim.kukaisland
 *   - any blocking sender/recipient verification failing
 *   - no exclusive claim lock (another run in progress)
 *   - state in_flight, uncertain, confirmed or failed_definitive
 *   - KUKA_EDM_ALLOW_SANDBOX_WRITE not the literal string "true"
 *   - confirm=<operation> absent or not matching the planned operation
 *
 * Nothing here creates a WooCommerce order or writes any database row. The
 * production Kuka_Island_Core_Invoice_Manager and its
 * invoice_numbering_unconfirmed guard are neither used nor relaxed, and no
 * write-capable method is added to the plugin: the LoadInvoice request is
 * assembled here and issued through the transport the client already owns.
 *
 * Run only through ./scripts/edm-sandbox-run.sh:
 *   KUKA_EDM_ALLOW_SANDBOX_WRITE=true ./scripts/edm-sandbox-run.sh confirm=LoadInvoice
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
	'SANDBOX_DRAFT_UPLOAD',
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

/*
 * SendInvoice is never called by this tool. The fact is reported exactly once,
 * on every exit path, so a successful LoadInvoice can never be mistaken for a
 * sent or issued invoice.
 */
$sendinvoice_reported = false;
register_shutdown_function(
	static function () use ( &$sendinvoice_reported ): void {
		if ( $sendinvoice_reported ) {
			return;
		}
		$sendinvoice_reported = true;
		WP_CLI::line( 'SANDBOX_SENDINVOICE=NOT_EXECUTED|reason:out_of_scope_this_round|documents_sent:0|recipient_delivery:none' );
	}
);

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

/*
 * is_live() only reads the environment label, and KUKA_EDM_WSDL overrides the
 * URL independently of it. The real endpoint is therefore proved here, against
 * the actual get_wsdl() value, BEFORE Login and before anything else runs. The
 * URL itself is never printed: a custom WSDL can carry user information.
 */
$endpoint = kuka_sandbox_verify_test_endpoint( $config->get_wsdl() );

if ( ! $endpoint['ok'] ) {
	WP_CLI::line( sprintf( 'SANDBOX_ENDPOINT=BLOCKED|reason:%s|login_attempted:no', $endpoint['reason'] ) );
	$block_from( $all_steps, 'wsdl_endpoint_not_verified' );
	WP_CLI::error( 'The configured WSDL is not the EDM test service. Nothing was contacted.' );
}

WP_CLI::line(
	sprintf(
		'SANDBOX_ENDPOINT=PASS|reason:%s|scheme:https|host:matched|path:matched|query:allowed|userinfo:absent|port:absent|fragment:absent',
		$endpoint['reason']
	)
);

WP_CLI::line(
	sprintf(
		'SANDBOX_PRECHECK=PASS|environment:%s|application_name_ok:yes|credentials:%s',
		$config->get_environment(),
		kuka_edm_test_presence_summary( $loaded['presence'] )
	)
);

/*
 * PROFILEID and the recipient identity are the ones EDM's published e-Arşiv
 * SOAP example uses, unless the operator deliberately overrides them. They are
 * fixture identities for this isolated experiment, not values EDM assigned to
 * this account. The resolver needs the verified endpoint as evidence -- the
 * environment label alone never unlocks them -- and a malformed or unsafe
 * override blocks instead of falling back.
 * https://docs.edmbilisim.com.tr/api/api-documentation/einvoice/efatura-soap-envelopes.html
 */
$defaults = kuka_sandbox_resolve_defaults(
	$config->get_environment(),
	$endpoint,
	(string) ( $loaded['sandbox']['profile_id'] ?? '' ),
	(string) ( $loaded['sandbox']['receiver_vkn'] ?? '' ),
	$config->get_sender_vkn()
);

$sandbox_receiver = (string) $defaults['receiver_vkn'];
$sandbox_profile  = (string) $defaults['profile_id'];

WP_CLI::line(
	sprintf(
		'SANDBOX_DEFAULTS=%s|profile_source:%s|receiver_source:%s|reason:%s%s',
		$defaults['ok'] ? 'PASS' : 'BLOCKED',
		$defaults['profile_source'],
		$defaults['receiver_source'],
		$defaults['reason'],
		empty( $defaults['failed'] ) ? '' : '|failed:' . implode( ',', $defaults['failed'] )
	)
);

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

/*
 * check_user_ok means "the GİB e-Invoice registry returned a usable entry for
 * this identifier", NOT "the call did not throw". CheckUser succeeding with an
 * empty USER list is the normal answer for a taxpayer that is not an e-Invoice
 * user, and reporting that as a present entry would be false.
 */
$check_user_ok = false;
$edm_alias     = '';
if ( '' !== $config->get_sender_vkn() ) {
	try {
		$user          = $client->check_user( $config->get_sender_vkn() );
		$edm_alias     = (string) ( $user['alias'] ?? '' );
		$check_user_ok = '' !== $edm_alias;
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		$check_user_ok = false;
	}
}

/*
 * The serial is optional: LoadInvoice always carries
 * GENERATEINVOICEIDONLOAD = true, so with no serial configured EDM assigns the
 * number from its own system serial. GetInvoiceSerial above stays pure
 * read-only discovery and is not a write gate.
 */
$series = kuka_sandbox_resolve_series( $config->get_series_earchive(), $registered_codes, $serial_query_ok );

/*
 * The portal fixture is the e-Archive sender authority. It is released only for
 * the endpoint already proved above -- never from the config or the credential
 * file, which would compare a value with itself.
 */
$sender_fixture = kuka_sandbox_sender_fixture_for( $endpoint, $config->get_environment() );

$verification = kuka_sandbox_verify_sender(
	array(
		'defaults'                => $defaults,
		'series'                  => $series,
		'sender_fixture'          => $sender_fixture,
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

// Labels only, never values. Sender fiscal fields are named so a BLOCKED run
// says exactly which field still has to come from the EDM portal or API.
$info_summary = implode(
	'|',
	array_map(
		static fn( string $k, string $v ): string => $k . ':' . $v,
		array_keys( $verification['info'] ),
		$verification['info']
	)
);

if ( ! $verification['ok'] ) {
	WP_CLI::line(
		sprintf(
			'SANDBOX_SENDER_IDENTITY=BLOCKED|profile:%s|failed:%s|missing_sender_fields:%s|mismatched_fixture_fields:%s|%s|%s|serial_query_ok:%s|registered_serials:%d',
			$verification['profile'],
			implode( ',', $verification['failed'] ),
			empty( $verification['missing_company_fields'] ) ? 'none' : implode( ',', $verification['missing_company_fields'] ),
			empty( $verification['mismatched_fixture_fields'] ) ? 'none' : implode( ',', $verification['mismatched_fixture_fields'] ),
			$check_summary,
			$info_summary,
			$serial_query_ok ? 'yes' : 'no',
			count( $registered_codes )
		)
	);
	$block_from( array_slice( $all_steps, 2 ), 'sender_verification_failed' );
	$client->logout();
	// Guidance is produced from the checks that actually failed, so it never
	// tells the operator to supply fields that are already present.
	WP_CLI::log( 'Nothing was invented and no draft was uploaded.' );
	foreach ( kuka_sandbox_sender_guidance( $verification ) as $guidance_line ) {
		WP_CLI::log( '  - ' . $guidance_line );
	}
	exit( 0 );
}

WP_CLI::line(
	sprintf(
		'SANDBOX_SENDER_IDENTITY=PASS|profile:%s|%s|%s|registered_serials:%d',
		$verification['profile'],
		$check_summary,
		$info_summary,
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

WP_CLI::line(
	sprintf(
		'SANDBOX_PLAN=PASS|operation:%s|effect:draft_upload_only|generate_invoice_id_on_load:true|invoice_id_attribute:omitted|invoiceserial_requested:%s|ubl_cbc_id:%s|ubl_cbc_id_count:%d|vat_percent:%s|net:%s|tax:%s|payable:%s|uuid_deterministic:yes',
		$planned_operation,
		$series['send'] ? 'sent' : 'omitted',
		$built['cbc_id'],
		$built['cbc_id_count'],
		$built['totals']['percent'],
		$built['totals']['net'],
		$built['totals']['tax'],
		$built['totals']['payable']
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
	$block( 'SANDBOX_DRAFT_UPLOAD', $reason );
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

/* ========================================================================== */
/* Operator-driven reconciliation reset: uncertain -> idle. No EDM call.       */
/* ========================================================================== */

/*
 * Only reachable with an explicit positional argument naming the evidence, and
 * only from 'uncertain'. It makes no external call at all: the operator has
 * already established, out of band, that the document is absent at EDM. The
 * on-disk history is appended to, never rewritten or deleted.
 *
 *   ./scripts/edm-sandbox-run.sh reset=document_absent_at_edm audit=<label>
 */
$reset_evidence = '';
$reset_audit    = '';
foreach ( $cli_args as $arg ) {
	if ( ! is_string( $arg ) ) {
		continue;
	}
	if ( str_starts_with( $arg, 'reset=' ) ) {
		$reset_evidence = substr( $arg, strlen( 'reset=' ) );
	}
	if ( str_starts_with( $arg, 'audit=' ) ) {
		$reset_audit = substr( $arg, strlen( 'audit=' ) );
	}
}

if ( '' !== $reset_evidence ) {
	$reset = $claim->reset_after_reconcile( $reset_evidence, $reset_audit );

	WP_CLI::line(
		sprintf(
			'SANDBOX_CLAIM_RESET=%s|from:%s|to:%s|reason:%s|audit:%s|written:%s',
			$reset['ok'] ? 'PASS' : 'BLOCKED',
			$state_now,
			$reset['state'],
			$reset['reason'],
			'' === trim( $reset_audit ) ? 'none' : trim( $reset_audit ),
			$reset['written'] ? 'yes' : 'no'
		)
	);

	$block_from( array_slice( $all_steps, 4 ), 'reconciliation_reset_only' );
	$claim->release();
	$client->logout();
	exit( $reset['ok'] ? 0 : 1 );
}

if ( Kuka_Sandbox_Claim::S_IDLE !== $state_now ) {
	WP_CLI::line( sprintf( 'SANDBOX_DUPLICATE_GUARD=PASS|state:%s|second_write_refused:yes', $state_now ) );
	$reconcile_only( 'state_' . $state_now, (string) ( $status['record']['assigned_number'] ?? '' ) );
	$claim->release();
	$client->logout();
	exit( 0 );
}

WP_CLI::line( 'SANDBOX_DUPLICATE_GUARD=PASS|state:idle|uuid_deterministic:yes|edm_duplicate_detection:second_layer' );

/* ========================================================================== */
/* Gates: explicit env opt-in and operation confirmation                        */
/* ========================================================================== */

/*
 * Unresolved contract, and the last gate before any write.
 *
 * For EARSIVFATURA addressed to the final-consumer identifier 11111111111, no
 * official EDM source establishes what LoadInvoiceRequest.RECEIVER.alias and
 * INVOICE.HEADER.TO must carry. Nothing is guessed here: not 'defaultpk', not
 * an e-mail address, and not an empty string presented as if it were correct.
 *
 * Flip this to true ONLY against a written EDM answer, and record that answer
 * in docs/EDM_ENTEGRASYONU.md at the same time.
 */
$receiver_alias_established = false;

if ( ! $receiver_alias_established ) {
	WP_CLI::line( 'SANDBOX_RECEIVER_ALIAS=BLOCKED|reason:official_earchive_alias_not_established' );
	$block( 'SANDBOX_DRAFT_UPLOAD', 'receiver_alias_contract_unresolved' );
	$block_from( array_slice( $all_steps, 6 ), 'no_document_created' );
	WP_CLI::log( 'No LoadInvoice was attempted: the e-Archive recipient alias contract is not established by any official EDM source, and it will not be guessed.' );
	$claim->release();
	$client->logout();
	exit( 0 );
}

WP_CLI::line( 'SANDBOX_RECEIVER_ALIAS=PASS|reason:official_earchive_alias_established' );

$allow_write = 'true' === (string) getenv( 'KUKA_EDM_ALLOW_SANDBOX_WRITE' );

/*
 * `wp eval-file` forwards BARE positional arguments only: a leading `--` makes
 * WP-CLI parse the token as one of its own parameters and abort with
 * "unknown --confirm parameter" before this file ever runs. The documented gate
 * is therefore `confirm=LoadInvoice`; the `--confirm=` spelling stays accepted
 * for anyone invoking this script outside WP-CLI. Either way the operation has
 * to be named in full, so the gate is unchanged in strength.
 */
$confirmed_operation = '';
foreach ( $cli_args as $arg ) {
	if ( ! is_string( $arg ) ) {
		continue;
	}
	foreach ( array( 'confirm=', '--confirm=' ) as $prefix ) {
		if ( str_starts_with( $arg, $prefix ) ) {
			$confirmed_operation = substr( $arg, strlen( $prefix ) );
			break;
		}
	}
}

if ( ! $allow_write || $planned_operation !== $confirmed_operation ) {
	WP_CLI::line( '' );
	WP_CLI::line( '>>> A WRITE OPERATION IS REQUIRED TO CONTINUE <<<' );
	WP_CLI::line( sprintf( '>>> Operation that would be called: %s (EDM test endpoint)', $planned_operation ) );
	WP_CLI::line( '>>> Effect: a persistent DRAFT is uploaded to the EDM test account.' );
	WP_CLI::line( '>>> Nothing is sent to a recipient: SendInvoice is not called.' );
	WP_CLI::line( '>>> Nothing has been created. Both gates are required:' );
	WP_CLI::line( '>>>   1) KUKA_EDM_ALLOW_SANDBOX_WRITE=true  (literal)' );
	WP_CLI::line( sprintf( '>>>   2) confirm=%s   (bare, no leading dashes: wp eval-file forwards positional args only)', $planned_operation ) );
	WP_CLI::line( '' );
	$block( 'SANDBOX_DRAFT_UPLOAD', $allow_write ? 'operation_not_confirmed' : 'sandbox_write_not_enabled' );
	$block_from( array_slice( $all_steps, 6 ), 'no_document_created' );
	$claim->release();
	$client->logout();
	exit( 0 );
}

/* ========================================================================== */
/* The single write call, through the shared, harness-tested write path         */
/* ========================================================================== */

$issue_date = gmdate( 'Y-m-d' );

// Assembled by the shared builder so verify-edm-sandbox-harness.php can prove
// the request shape -- serial present or absent -- without any network call.
$load_request = kuka_sandbox_build_load_request(
	array(
		'uuid'             => $uuid,
		'issue_date'       => $issue_date,
		'action_date'      => gmdate( 'Y-m-d\TH:i:s' ),
		'session_id'       => (string) $client->get_session_id(),
		'application_name' => $config->get_application_name(),
		'sender_vkn'       => $config->get_sender_vkn(),
		'sender_alias'     => $config->get_sender_alias(),
		'receiver_vkn'     => $sandbox_receiver,
		'profile_id'       => $sandbox_profile,
		'series_code'      => $series['send'] ? $series['code'] : '',
		'payable'          => $built['totals']['payable'],
		'content'          => $built['xml'],
	)
);

WP_CLI::line( sprintf( 'SANDBOX_DRAFT_UPLOAD=RUNNING|operation:%s|effect:draft_upload_only|state:in_flight|state_recorded:yes', $planned_operation ) );

$write = kuka_sandbox_execute_write( $claim, $client->get_transport(), $load_request, $uuid, $planned_operation );

if ( ! $write['claimed'] ) {
	$block( 'SANDBOX_DRAFT_UPLOAD', 'claim_failed:' . $write['claim_reason'] );
	$block_from( array_slice( $all_steps, 6 ), 'no_document_created' );
	$claim->release();
	$client->logout();
	exit( 1 );
}

WP_CLI::line(
	sprintf(
		'SANDBOX_DRAFT_UPLOAD=%s|operation:%s|effect:draft_upload_only|classification:%s|state:%s|state_recorded:%s|status:%s',
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
	if ( array() !== (array) ( $write['fault'] ?? array() ) ) {
		// Classification tokens only. The SOAP fault text is never printed.
		WP_CLI::line( sprintf( 'SANDBOX_DRAFT_UPLOAD_FAULT=%s', Kuka_Island_Core_EDM_Fault_Classifier::to_safe_line( (array) $write['fault'] ) ) );
	}
	if ( KUKA_SANDBOX_CALL_UNCERTAIN === $write['classification'] ) {
		WP_CLI::log( 'Uncertain: the call left this process and a document may exist at EDM. No automatic retry. Reconcile the UUID read-only before any further decision.' );
	}
	$claim->release();
	$client->logout();
	exit( $write['exit_code'] );
}

// LoadInvoice stored the DRAFT. Nothing was sent. Measurements still run even
// when the confirmed state could not be persisted, but the run is not reported
// as PASS or confirmed in that case.
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
			'LoadInvoice stored the draft at EDM but the confirmed state could not be persisted (%s). The on-disk record still says in_flight, a second write stays refused, and manual reconciliation is required.',
			$write['status_token']
		)
	);
}

WP_CLI::success( 'EDM sandbox draft-upload experiment finished. LoadInvoice only; SendInvoice was not called.' );
