<?php
/**
 * Isolated EDM sandbox SendInvoice experiment.
 *
 * SendInvoice ISSUES a document. Unlike LoadInvoice -- which stores a draft and
 * delivers nothing -- this transmits an e-Arşiv invoice in EDM's TEST service
 * and EDM then delivers it to the address in INVOICE/HEADER/TO. Everything
 * here is built around that being irreversible.
 *
 * What this tool does NOT do:
 *
 * - It never touches the confirmed LoadInvoice draft. That document already
 *   exists at EDM, and transmitting it would be precisely the blind resend
 *   every guard in this codebase exists to prevent. This experiment mints its
 *   own document from its own deterministic seed and its own state record.
 * - It never calls SendInvoice more than once. The production client's
 *   session-expiry retry is disabled for transmissions, so an uncertain or
 *   faulted answer surfaces instead of being re-attempted.
 * - It never calls EmailInvoice. EDM delivers an e-Arşiv document itself, from
 *   HEADER/TO.
 * - It never runs against a live endpoint, and it writes no database row.
 *
 * Default behaviour is PLAN: nothing is transmitted. Two literal gates, kept
 * completely separate from the LoadInvoice ones, are required:
 *
 *   KUKA_EDM_ALLOW_SANDBOX_SEND=true ./scripts/edm-sandbox-send-run.sh confirm=SendInvoice
 *
 * Opening the LoadInvoice gate at the same time is refused: an operator asking
 * for a draft upload and a transmission in one run has an ambiguous intent, and
 * neither is carried out.
 *
 * The transmission itself goes through the PRODUCTION client,
 * Kuka_Island_Core_EDM_Client::send_invoice(), so what is proved here is the
 * code that will run in production rather than a hand-rolled copy of it.
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

require_once __DIR__ . '/lib-edm-test-credentials.php';
require_once __DIR__ . '/lib-edm-sandbox.php';

$all_steps = array(
	'SANDBOX_SEND_ENDPOINT',
	'SANDBOX_SEND_RECIPIENT',
	'SANDBOX_SEND_NUMBERING',
	'SANDBOX_SEND_PLAN',
	'SANDBOX_SEND_DUPLICATE_GUARD',
	'SANDBOX_SEND_TRANSMIT',
	'SANDBOX_SEND_NUMBER_ASSIGNED',
	'SANDBOX_SEND_STATUS_READBACK',
	'SANDBOX_SEND_XML_READBACK',
	'SANDBOX_SEND_CBC_ID_READBACK',
);

$block = static function ( string $step, string $reason ): void {
	WP_CLI::line( sprintf( '%s=BLOCKED|reason:%s', $step, $reason ) );
};

$block_from = static function ( array $steps, string $reason ) use ( $block ): void {
	foreach ( $steps as $step ) {
		$block( $step, $reason );
	}
};

$cli_args = (array) ( $args ?? array() );

/*
 * Whether a document was transmitted is reported exactly once, on every exit
 * path, so a PLAN run can never be mistaken for a transmission and vice versa.
 */
$sendinvoice_reported = false;
$sendinvoice_line     = 'SANDBOX_SENDINVOICE=NOT_EXECUTED|reason:plan_only|documents_sent:0|recipient_delivery:none';
/*
 * What was written is reported by the same coordinator, for the same reason: a
 * blocked step printing "NONE|count:0" of its own would contradict a
 * transmission that had already happened earlier in the run.
 */
$write_ops_line = 'SANDBOX_SEND_WRITE_OPERATIONS=NONE|count:0';
register_shutdown_function(
	static function () use ( &$sendinvoice_reported, &$sendinvoice_line, &$write_ops_line ): void {
		if ( $sendinvoice_reported ) {
			return;
		}
		$sendinvoice_reported = true;
		WP_CLI::line( $write_ops_line );
		WP_CLI::line( $sendinvoice_line );
	}
);

/* ========================================================================== */
/* Mode: resolve is read-only and answers "what does EDM actually hold?"        */
/* ========================================================================== */

/*
 * An uncertain transmission must never be settled by transmitting again, so
 * the only way forward is to ASK. This mode calls GetInvoiceStatus and
 * GetInvoice for the recorded UUID and nothing else; it needs no send gate
 * because it issues nothing, and it REFUSES to run while a send gate or a
 * send confirmation is present, because "resolve and also transmit" is not an
 * intent this tool will guess at.
 */
$resolve_requested = in_array( 'resolve', $cli_args, true );

/*
 * status=confirm asks EDM what it now holds for a document it ALREADY accepted.
 * Strictly read-only: Login, GetInvoiceStatus, optionally GetInvoice, Logout,
 * enforced by an allow-listed transport rather than by convention. It never
 * claims, never settles, never reconciles, and writes no state or history --
 * the runner mounts the state directory read-only for this mode, so a write is
 * impossible rather than merely unintended.
 */
$status_requested = in_array( 'status=confirm', $cli_args, true );

if ( ! $status_requested ) {
	foreach ( $cli_args as $cli_arg ) {
		if ( 0 === strpos( (string) $cli_arg, 'status=' ) ) {
			WP_CLI::line( 'SANDBOX_SEND_STATUS=BLOCKED|reason:invalid_status_confirmation|expected:status=confirm|soap_calls:0' );
			$sendinvoice_line = 'SANDBOX_SENDINVOICE=NOT_EXECUTED|reason:status_confirmation_invalid|documents_sent:0|recipient_delivery:none';
			exit( 1 );
		}
	}
}

if ( $resolve_requested || $status_requested ) {
	$resolve_conflicts = array();
	if ( 'true' === (string) getenv( 'KUKA_EDM_ALLOW_SANDBOX_SEND' ) ) {
		$resolve_conflicts[] = 'send_gate_open_during_read_only_mode';
	}
	if ( 'true' === (string) getenv( 'KUKA_EDM_ALLOW_SANDBOX_WRITE' ) ) {
		$resolve_conflicts[] = 'loadinvoice_gate_open_during_read_only_mode';
	}
	foreach ( $cli_args as $cli_arg ) {
		if ( 0 === strpos( (string) $cli_arg, 'confirm=' ) ) {
			$resolve_conflicts[] = 'confirmation_present_during_read_only_mode';
			break;
		}
	}

	$mode_label = $status_requested ? 'STATUS' : 'RESOLVE';

	if ( $resolve_requested && $status_requested ) {
		$resolve_conflicts[] = 'resolve_and_status_both_requested';
	}

	if ( array() !== $resolve_conflicts ) {
		WP_CLI::line(
			sprintf(
				'SANDBOX_SEND_%s=BLOCKED|reason:%s|soap_calls:0',
				$mode_label,
				implode( ',', $resolve_conflicts )
			)
		);
		$block_from( $all_steps, strtolower( $mode_label ) . '_mode_refused' );
		$sendinvoice_line = sprintf(
			'SANDBOX_SENDINVOICE=NOT_EXECUTED|reason:%s_mode_refused|documents_sent:0|recipient_delivery:none',
			strtolower( $mode_label )
		);
		exit( 1 );
	}

	WP_CLI::line(
		sprintf(
			'SANDBOX_SEND_MODE=%s|writes:none|operations:Login,GetInvoiceStatus,GetInvoice,Logout|sendinvoice:never|loadinvoice:never',
			$mode_label
		)
	);
	$sendinvoice_line = sprintf(
		'SANDBOX_SENDINVOICE=NOT_EXECUTED|reason:%s_mode_read_only|documents_sent:0|recipient_delivery:none',
		strtolower( $mode_label )
	);
}

/* ========================================================================== */
/* Gates: the two literal opt-ins, decided before anything is contacted        */
/* ========================================================================== */

$gates = kuka_sandbox_send_gates(
	(string) getenv( 'KUKA_EDM_ALLOW_SANDBOX_SEND' ),
	(string) getenv( 'KUKA_EDM_ALLOW_SANDBOX_WRITE' ),
	$cli_args
);

if ( ! $resolve_requested && ! $status_requested && 'refused' === $gates['mode'] ) {
	WP_CLI::line( sprintf( 'SANDBOX_SEND_GATES=BLOCKED|reason:%s|soap_calls:0', implode( ',', $gates['refusals'] ) ) );
	$block_from( $all_steps, 'send_gates_refused' );
	$sendinvoice_line = 'SANDBOX_SENDINVOICE=NOT_EXECUTED|reason:gates_refused|documents_sent:0|recipient_delivery:none';
	exit( 1 );
}

WP_CLI::line(
	sprintf(
		'SANDBOX_SEND_GATES=PASS|mode:%s|send_gate:%s|loadinvoice_gate:closed|confirmation:%s',
		$status_requested ? 'status' : ( $resolve_requested ? 'resolve' : $gates['mode'] ),
		$gates['allowed'] ? 'open' : 'closed',
		'' === $gates['confirmed'] ? 'absent' : $gates['confirmed']
	)
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
/* Gate: TEST endpoint only                                                    */
/* ========================================================================== */

if ( $config->is_live() ) {
	$block_from( $all_steps, 'live_endpoint_refused' );
	WP_CLI::error( 'The SendInvoice experiment refuses to run against a live endpoint.' );
}
if ( 'ozelyazilim.kukaisland' !== $config->get_application_name() ) {
	$block_from( $all_steps, 'unexpected_application_name' );
	WP_CLI::error( 'APPLICATION_NAME contract violated.' );
}

/*
 * The environment label alone never unlocks anything: KUKA_EDM_WSDL can point
 * the URL somewhere else independently of it, so the actual endpoint is proved
 * against the allow-list before Login. The URL itself is never printed -- a
 * custom WSDL can carry user information.
 */
$endpoint = kuka_sandbox_verify_test_endpoint( $config->get_wsdl() );
if ( ! $endpoint['ok'] ) {
	WP_CLI::line( sprintf( 'SANDBOX_SEND_ENDPOINT=BLOCKED|reason:%s|login_attempted:no', $endpoint['reason'] ) );
	$block_from( array_slice( $all_steps, 1 ), 'wsdl_endpoint_not_verified' );
	WP_CLI::error( 'The configured WSDL is not the EDM test service. Nothing was contacted.' );
}

WP_CLI::line(
	sprintf(
		'SANDBOX_SEND_ENDPOINT=PASS|environment:%s|reason:%s|scheme:https|host:matched|path:matched|userinfo:absent|port:absent|fragment:absent',
		$config->get_environment(),
		$endpoint['reason']
	)
);

/* ========================================================================== */
/* Status: read-only check on a document EDM already accepted                  */
/* ========================================================================== */

if ( $status_requested ) {
	$state_path = KUKA_SANDBOX_STATE_DIR . '/' . KUKA_SANDBOX_SEND_STATE_FILE;

	/*
	 * The state file is read, hashed, and never opened for writing. No claim is
	 * acquired: a claim needs a writable lock file, and this mode runs against
	 * a read-only mount precisely so that a write cannot happen. The hash is
	 * taken again at the end and reported, so the claim of "nothing changed" is
	 * a measurement rather than an assertion.
	 */
	$state_sha_before = is_readable( $state_path ) ? hash_file( 'sha256', $state_path ) : '';

	$st_claim  = new Kuka_Sandbox_Claim( $state_path );
	$st_status = $st_claim->status();
	$st_state  = (string) $st_status['state'];
	$st_record = (array) ( $st_status['record'] ?? array() );
	$st_uuid   = trim( (string) ( $st_record['uuid'] ?? '' ) );
	$st_number = trim( (string) ( $st_record['assigned_number'] ?? '' ) );

	// Only a document EDM accepted has a status worth asking about.
	if ( Kuka_Sandbox_Claim::S_CONFIRMED !== $st_state ) {
		WP_CLI::line(
			sprintf(
				'SANDBOX_SEND_STATUS=BLOCKED|reason:record_not_confirmed|state:%s|soap_calls:0',
				$st_state
			)
		);
		$block_from( $all_steps, 'status_requires_confirmed_record' );
		exit( 1 );
	}
	if ( '' === $st_uuid ) {
		WP_CLI::line( 'SANDBOX_SEND_STATUS=BLOCKED|reason:recorded_uuid_absent|soap_calls:0' );
		$block_from( $all_steps, 'status_uuid_absent' );
		exit( 1 );
	}

	/*
	 * The production client, wrapped so it CANNOT transmit. A write operation
	 * raises before reaching the network, and the ledger below is what the
	 * report quotes for SendInvoice=0 and LoadInvoice=0.
	 */
	$st_readonly = new Kuka_Sandbox_Readonly_Transport(
		new Kuka_Island_Core_EDM_SOAP_Transport(
			$config->get_wsdl(),
			$config->get_timeout(),
			false
		)
	);
	$st_client = new Kuka_Island_Core_EDM_Client( $config, $st_readonly );

	$st_tax     = Kuka_Island_Core_Invoice_Order_Mapper::tax_from_taxable( KUKA_SANDBOX_NET_CENTS, KUKA_SANDBOX_VAT_PERCENT );
	$st_payable = KUKA_SANDBOX_NET_CENTS + $st_tax;

	$st_read = kuka_sandbox_read_document( $st_client, $st_uuid, $st_number, $st_payable, $st_tax );

	// Released explicitly so the session does not linger; failure to log out is
	// not an error worth failing the check over, so it is reported either way.
	$st_logout = false;
	try {
		$st_logout = $st_client->logout();
	} catch ( Throwable $t ) {
		$st_logout = false;
	}

	$st_classified = Kuka_Island_Core_EDM_Document_Status::classify( (string) $st_read['status_literal'] );
	$st_terminal   = kuka_sandbox_status_is_terminal( (string) $st_classified['class'] );

	/*
	 * Presence is decided the same way the resolve verdict decides it: EDM has
	 * to have said something about THIS document, not merely answered the call.
	 */
	$st_present = '' !== trim( (string) $st_read['status_literal'] )
		|| '' !== trim( (string) $st_read['status_number'] )
		|| true === $st_read['status_uuid_match']
		|| true === $st_read['checks']['uuid_match'];

	$st_returned_number = trim( (string) $st_read['status_number'] );

	WP_CLI::line(
		sprintf(
			'SANDBOX_SEND_STATUS=%s|uuid_prefix:%s|edm_status:%s|status_class:%s|terminal:%s|mapped_status:%s|document_present_at_edm:%s|number_returned:%s|number_length:%d|number_matches_record:%s|status_response_uuid_match:%s|xml_uuid_match:%s|status_error:%s',
			$st_present ? 'PASS' : 'FAIL',
			substr( $st_uuid, 0, 8 ),
			'' === $st_read['status_literal'] ? 'none' : $st_read['status_literal'],
			(string) $st_classified['class'],
			$st_terminal ? 'terminal' : 'pending',
			'' === $st_read['mapped_status'] ? 'none' : $st_read['mapped_status'],
			$st_present ? 'yes' : 'NO',
			'' !== $st_returned_number ? 'yes' : 'no',
			strlen( $st_returned_number ),
			( '' !== $st_returned_number && $st_returned_number === $st_number ) ? 'yes' : 'no',
			true === $st_read['status_uuid_match'] ? 'yes' : 'no',
			true === $st_read['checks']['uuid_match'] ? 'yes' : 'no',
			'' === $st_read['status_error'] ? 'none' : $st_read['status_error']
		)
	);

	WP_CLI::line(
		sprintf(
			'SANDBOX_SEND_STATUS_XML=%s|xml_retrieved:%s|xml_parsed:%s|uuid_match:%s|cbc_id_present:%s|cbc_id_matches_assigned_number:%s|payable_match:%s|tax_match:%s|customer_email_match:%s|reason:%s',
			true === $st_read['checks']['xml_retrieved'] ? 'PASS' : 'PENDING',
			true === $st_read['checks']['xml_retrieved'] ? 'yes' : 'no',
			true === $st_read['checks']['xml_parsed'] ? 'yes' : 'no',
			true === $st_read['checks']['uuid_match'] ? 'yes' : 'no',
			true === $st_read['cbc_present'] ? 'yes' : 'no',
			true === $st_read['cbc_matches'] ? 'yes' : 'no',
			true === $st_read['checks']['payable_match'] ? 'yes' : 'no',
			true === $st_read['checks']['tax_match'] ? 'yes' : 'no',
			true === $st_read['email_match'] ? 'yes' : 'no',
			'' === $st_read['xml_error'] ? 'none' : $st_read['xml_error']
		)
	);

	$st_ledger = $st_readonly->ledger();

	WP_CLI::line(
		sprintf(
			'SANDBOX_SEND_STATUS_OPERATIONS=%s|observed:%s|SendInvoice:%d|LoadInvoice:%d|refused_write_attempts:%s|logout:%s',
			( 0 === $st_readonly->count_of( 'SendInvoice' ) && 0 === $st_readonly->count_of( 'LoadInvoice' ) ) ? 'PASS' : 'FAIL',
			array() === $st_ledger ? 'none' : implode( ',', $st_ledger ),
			$st_readonly->count_of( 'SendInvoice' ),
			$st_readonly->count_of( 'LoadInvoice' ),
			array() === $st_readonly->refused() ? 'none' : implode( ',', $st_readonly->refused() ),
			$st_logout ? 'ok' : 'not_confirmed'
		)
	);

	$state_sha_after = is_readable( $state_path ) ? hash_file( 'sha256', $state_path ) : '';

	WP_CLI::line(
		sprintf(
			'SANDBOX_SEND_STATUS_STATE_UNCHANGED=%s|state:%s|history_entries:%d|sha256_before:%s|sha256_after:%s|claim_transitions:0|state_writes:0',
			( '' !== $state_sha_before && $state_sha_before === $state_sha_after ) ? 'PASS' : 'FAIL',
			(string) $st_claim->status()['state'],
			count( (array) ( $st_record['history'] ?? array() ) ),
			substr( $state_sha_before, 0, 16 ),
			substr( $state_sha_after, 0, 16 )
		)
	);

	/*
	 * A pending document is reported as pending and left alone. No loop, no
	 * rescheduling, and no background process is started here -- if a next
	 * check is wanted, a person runs this command again.
	 */
	if ( ! $st_terminal ) {
		WP_CLI::line(
			sprintf(
				'SANDBOX_SEND_STATUS_NEXT_CHECK=pending|not_resent:yes|no_polling_loop:yes|no_background_process_started:yes|earliest_useful_recheck_utc:%s|command:status=confirm',
				gmdate( 'Y-m-d\TH:i:s\Z', time() + ( 30 * MINUTE_IN_SECONDS ) )
			)
		);
	}

	$block_from( $all_steps, 'status_mode_read_only' );
	exit( $st_present ? 0 : 1 );
}

/* ========================================================================== */
/* Resolve: read-only reconciliation of an unsettled transmission              */
/* ========================================================================== */

if ( $resolve_requested ) {
	$rs_claim = new Kuka_Sandbox_Claim( KUKA_SANDBOX_STATE_DIR . '/' . KUKA_SANDBOX_SEND_STATE_FILE );

	if ( ! $rs_claim->acquire() ) {
		WP_CLI::line( 'SANDBOX_SEND_RESOLVE=BLOCKED|reason:exclusive_lock_unavailable_another_run_in_progress' );
		$block_from( $all_steps, 'resolve_lock_unavailable' );
		exit( 1 );
	}

	$rs_status = $rs_claim->status();
	$rs_state  = (string) $rs_status['state'];
	$rs_record = (array) ( $rs_status['record'] ?? array() );
	$rs_uuid   = trim( (string) ( $rs_record['uuid'] ?? '' ) );

	// Only an unsettled record has anything to reconcile. A confirmed or idle
	// one is already answered, and asking again would be noise.
	if ( ! in_array( $rs_state, array( Kuka_Sandbox_Claim::S_UNCERTAIN, Kuka_Sandbox_Claim::S_IN_FLIGHT ), true ) ) {
		WP_CLI::line(
			sprintf(
				'SANDBOX_SEND_RESOLVE=BLOCKED|reason:nothing_to_resolve|state:%s|soap_calls:0',
				$rs_state
			)
		);
		$block_from( $all_steps, 'resolve_state_already_settled' );
		$rs_claim->release();
		exit( 0 );
	}

	if ( '' === $rs_uuid ) {
		WP_CLI::line( 'SANDBOX_SEND_RESOLVE=BLOCKED|reason:recorded_uuid_absent|soap_calls:0' );
		$block_from( $all_steps, 'resolve_uuid_absent' );
		$rs_claim->release();
		exit( 1 );
	}

	// The same production mapper the transmission used, so the expected totals
	// here cannot drift away from the ones that were actually sent.
	$rs_tax     = Kuka_Island_Core_Invoice_Order_Mapper::tax_from_taxable( KUKA_SANDBOX_NET_CENTS, KUKA_SANDBOX_VAT_PERCENT );
	$rs_payable = KUKA_SANDBOX_NET_CENTS + $rs_tax;

	$rs_client = new Kuka_Island_Core_EDM_Client( $config );
	$rs_read   = kuka_sandbox_read_document(
		$rs_client,
		$rs_uuid,
		trim( (string) ( $rs_record['assigned_number'] ?? '' ) ),
		$rs_payable,
		$rs_tax
	);

	/*
	 * The verdict is deliberately three-valued. "Present" and "absent" are both
	 * definite answers; anything else is still unknown, and an unknown answer
	 * leaves the record uncertain so no later run can transmit.
	 */
	// The control runs FIRST: it must not be influenced by anything the target
	// query left behind in the session.
	$rs_control = kuka_sandbox_probe_unknown_uuid( $rs_client );
	$rs_verdict = kuka_sandbox_resolve_verdict( $rs_read, $rs_control );

	WP_CLI::line(
		sprintf(
			'SANDBOX_SEND_RESOLVE=%s|verdict:%s|uuid_prefix:%s|status_answered:%s|status_literal:%s|status_number_length:%d|mapped_status:%s|status_error:%s|xml_error:%s|xml_retrieved:%s|uuid_match:%s',
			'unknown' === $rs_verdict['verdict'] ? 'FAIL' : 'PASS',
			$rs_verdict['verdict'],
			substr( $rs_uuid, 0, 8 ),
			true === $rs_read['status_ok'] ? 'yes' : 'no',
			'' === $rs_read['status_literal'] ? 'none' : $rs_read['status_literal'],
			strlen( (string) $rs_read['status_number'] ),
			'' === $rs_read['mapped_status'] ? 'none' : $rs_read['mapped_status'],
			'' === $rs_read['status_error'] ? 'none' : $rs_read['status_error'],
			'' === $rs_read['xml_error'] ? 'none' : $rs_read['xml_error'],
			true === $rs_read['checks']['xml_retrieved'] ? 'yes' : 'no',
			true === $rs_read['checks']['uuid_match'] ? 'yes' : 'no'
		)
	);
	WP_CLI::line(
		sprintf(
			'SANDBOX_SEND_RESOLVE_CONTROL=never_transmitted_uuid|uuid_prefix:%s|status_answered:%s|status_error:%s|status_literal:%s|status_number_length:%d',
			$rs_control['uuid_prefix'],
			true === $rs_control['status_ok'] ? 'yes' : 'no',
			'' === $rs_control['status_error'] ? 'none' : $rs_control['status_error'],
			'' === $rs_control['literal'] ? 'none' : $rs_control['literal'],
			$rs_control['number_length']
		)
	);
	WP_CLI::line( sprintf( 'SANDBOX_SEND_RESOLVE_REASON=%s', $rs_verdict['reason'] ) );

	/*
	 * Returning the record to idle is the one state change this mode can make,
	 * and it re-opens the send gate, so it needs its own literal AND absence
	 * proved in THIS run -- never a stale verdict from an earlier one.
	 */
	$rs_reset = 'not_requested';
	if ( in_array( 'reconcile=absent', $cli_args, true ) ) {
		if ( 'absent' !== $rs_verdict['verdict'] ) {
			$rs_reset = 'refused_absence_not_proved_in_this_run';
		} else {
			$done     = $rs_claim->reset_after_reconcile( 'document_absent_at_edm', 'sandbox_send_resolve' );
			$rs_reset = true === ( $done['written'] ?? false ) ? 'reset_to_idle' : (string) $done['reason'];
		}
	}

	WP_CLI::line(
		sprintf(
			'SANDBOX_SEND_RESOLVE_STATE=%s|before:%s|after:%s|reconcile:%s',
			'not_requested' === $rs_reset || 'reset_to_idle' === $rs_reset ? 'PASS' : 'FAIL',
			$rs_state,
			$rs_claim->status()['state'],
			$rs_reset
		)
	);

	$block_from( $all_steps, 'resolve_mode_read_only' );
	$rs_claim->release();
	exit( 'unknown' === $rs_verdict['verdict'] ? 1 : 0 );
}

/* ========================================================================== */
/* The document: recipient, numbering, totals                                  */
/* ========================================================================== */

$defaults = kuka_sandbox_resolve_defaults(
	$config->get_environment(),
	$endpoint,
	(string) ( $loaded['sandbox']['profile_id'] ?? '' ),
	(string) ( $loaded['sandbox']['receiver_vkn'] ?? '' ),
	$config->get_sender_vkn()
);

if ( ! $defaults['ok'] ) {
	WP_CLI::line( sprintf( 'SANDBOX_SEND_RECIPIENT=BLOCKED|reason:%s', $defaults['reason'] ) );
	$block_from( array_slice( $all_steps, 2 ), 'recipient_not_resolved' );
	exit( 0 );
}

$send_uuid     = kuka_sandbox_send_uuid();
$send_receiver = (string) $defaults['receiver_vkn'];
$send_profile  = (string) $defaults['profile_id'];

// The supplier is the configured sender, exactly as the LoadInvoice experiment
// and production build it. Nothing new is introduced here.
$supplier = array(
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
);

$built = kuka_sandbox_build_ubl(
	$supplier,
	$send_receiver,
	$send_profile,
	$send_uuid,
	array(
		'first_name' => KUKA_SANDBOX_SEND_FIRST_NAME,
		'last_name'  => KUKA_SANDBOX_SEND_LAST_NAME,
		'email'      => KUKA_SANDBOX_RECEIVER_EMAIL,
	)
);

$addressing = Kuka_Island_Core_EDM_Client::recipient_addressing( true, '', KUKA_SANDBOX_RECEIVER_EMAIL );

$ubl_has_mail = str_contains( $built['xml'], '<cbc:ElectronicMail>' . KUKA_SANDBOX_RECEIVER_EMAIL . '</cbc:ElectronicMail>' );
$ubl_has_name = str_contains( $built['xml'], '<cbc:FirstName>' . KUKA_SANDBOX_SEND_FIRST_NAME . '</cbc:FirstName>' )
	&& str_contains( $built['xml'], '<cbc:FamilyName>' . KUKA_SANDBOX_SEND_LAST_NAME . '</cbc:FamilyName>' );
$ubl_has_tckn = str_contains( $built['xml'], '>' . $send_receiver . '<' );

$recipient_ok = KUKA_SANDBOX_DOCUMENTED_RECEIVER_VKN === $send_receiver
	&& $ubl_has_tckn
	&& $ubl_has_name
	&& $ubl_has_mail
	&& KUKA_SANDBOX_RECEIVER_EMAIL === (string) $addressing['to']
	&& null === $addressing['receiver_alias'];

WP_CLI::line(
	sprintf(
		'SANDBOX_SEND_RECIPIENT=%s|tckn:%s|person_name:synthetic_present|ubl_electronicmail:%s|header_to:%s|same_address:%s|receiver_alias:%s',
		$recipient_ok ? 'PASS' : 'BLOCKED',
		$send_receiver,
		$ubl_has_mail ? 'present' : 'ABSENT',
		null === $addressing['to'] ? 'ABSENT' : 'customer_email',
		( $ubl_has_mail && KUKA_SANDBOX_RECEIVER_EMAIL === (string) $addressing['to'] ) ? 'yes' : 'no',
		null === $addressing['receiver_alias'] ? 'omitted' : 'PRESENT'
	)
);

if ( ! $recipient_ok ) {
	$block_from( array_slice( $all_steps, 2 ), 'recipient_contract_not_met' );
	exit( 1 );
}

/*
 * Numbering: the submitted UBL carries EDM's portal placeholder in cbc:ID and
 * the SOAP INVOICE/@ID is not sent at all, so the real number can only come
 * back from EDM. Two different fields; only the SOAP-side one is omitted.
 */
$ubl_dom = new DOMDocument();
$ubl_dom->loadXML( $built['xml'] );
$ubl_xp      = new DOMXPath( $ubl_dom );
$ubl_id_list = $ubl_xp->query( '/*[local-name()="Invoice"]/*[local-name()="ID"]' );
$ubl_id_val  = ( false !== $ubl_id_list && $ubl_id_list->length > 0 ) ? trim( (string) $ubl_id_list->item( 0 )->nodeValue ) : '';

$expected_tax     = Kuka_Island_Core_Invoice_Order_Mapper::tax_from_taxable( KUKA_SANDBOX_NET_CENTS, KUKA_SANDBOX_VAT_PERCENT );
$expected_payable = KUKA_SANDBOX_NET_CENTS + $expected_tax;

$numbering_ok = KUKA_SANDBOX_UBL_ID_PLACEHOLDER === $ubl_id_val
	&& 1 === ( ( false !== $ubl_id_list ) ? $ubl_id_list->length : 0 )
	&& Kuka_Island_Core_Invoice_Order_Mapper::amount_to_cents( (string) $built['totals']['payable'] ) === $expected_payable
	&& Kuka_Island_Core_Invoice_Order_Mapper::amount_to_cents( (string) $built['totals']['tax'] ) === $expected_tax;

WP_CLI::line(
	sprintf(
		'SANDBOX_SEND_NUMBERING=%s|ubl_cbc_id:%s|ubl_cbc_id_count:%d|soap_invoice_id:omitted|number_source:edm_response_only|net:%s|vat_percent:%d|tax:%s|payable:%s',
		$numbering_ok ? 'PASS' : 'BLOCKED',
		$ubl_id_val,
		( false !== $ubl_id_list ) ? $ubl_id_list->length : 0,
		Kuka_Island_Core_Invoice_Order_Mapper::cents_to_amount( KUKA_SANDBOX_NET_CENTS ),
		KUKA_SANDBOX_VAT_PERCENT,
		(string) $built['totals']['tax'],
		(string) $built['totals']['payable']
	)
);

if ( ! $numbering_ok ) {
	$block_from( array_slice( $all_steps, 3 ), 'numbering_or_totals_contract_not_met' );
	exit( 1 );
}

/*
 * INTERNETSALESDETAILS is decided from the WSDL, not from habit. It is
 * minOccurs="0" and it describes a distance sale; this experiment is a
 * fiscal-service test with no order, no payment intermediary and no shipment
 * behind it, so it declares INTERNETSALES=false and sends no details block.
 * Nothing here needs a courier API.
 */
$isd = kuka_sandbox_send_internetsales_decision();

$send_payload = array(
	'trx_id'            => (int) hexdec( substr( hash( 'sha256', $send_uuid ), 0, 8 ) ),
	'uuid'              => $send_uuid,
	'invoice_serial'    => '',
	'profile_id'        => $send_profile,
	'invoice_type_code' => 'SATIS',
	'issue_date'        => gmdate( 'Y-m-d' ),
	'payable_amount'    => (string) $built['totals']['payable'],
	'receiver_vkn'      => $send_receiver,
	'receiver_alias'    => '',
	'customer_email'    => KUKA_SANDBOX_RECEIVER_EMAIL,
	'is_internet_sales' => $isd['internetsales'],
	'ubl_xml'           => $built['xml'],
);

WP_CLI::line(
	sprintf(
		'SANDBOX_SEND_PLAN=PASS|operation:SendInvoice|effect:document_issued_and_delivered_by_edm|profile:%s|internetsales:%s|internetsalesdetails:%s|reason:%s|uuid_deterministic:yes|uuid_distinct_from_loadinvoice:%s|emailinvoice:never_called',
		$send_profile,
		$isd['internetsales'] ? 'true' : 'false',
		$isd['required'] ? 'included' : 'omitted',
		$isd['reason'],
		kuka_sandbox_send_uuid() !== kuka_sandbox_uuid() ? 'yes' : 'NO'
	)
);

/* ========================================================================== */
/* Claim: process lock and persistent state, before anything is transmitted    */
/* ========================================================================== */

$claim = new Kuka_Sandbox_Claim( KUKA_SANDBOX_STATE_DIR . '/' . KUKA_SANDBOX_SEND_STATE_FILE );

if ( ! $claim->acquire() ) {
	WP_CLI::line( 'SANDBOX_SEND_DUPLICATE_GUARD=BLOCKED|reason:exclusive_lock_unavailable_another_run_in_progress' );
	$block_from( array_slice( $all_steps, 5 ), 'lock_unavailable' );
	exit( 1 );
}

$state_now = $claim->status()['state'];

if ( Kuka_Sandbox_Claim::S_IDLE !== $state_now ) {
	WP_CLI::line(
		sprintf(
			'SANDBOX_SEND_DUPLICATE_GUARD=BLOCKED|reason:state_not_idle|state:%s|second_transmission_refused:yes',
			$state_now
		)
	);
	$block_from( array_slice( $all_steps, 5 ), 'document_already_attempted' );
	$claim->release();
	exit( 0 );
}

WP_CLI::line( 'SANDBOX_SEND_DUPLICATE_GUARD=PASS|state:idle|process_lock:held|uuid_deterministic:yes|edm_duplicate_detection:second_layer' );

/* ========================================================================== */
/* PLAN stops here                                                             */
/* ========================================================================== */

if ( ! $gates['allowed'] ) {
	WP_CLI::line( 'SANDBOX_SEND_TRANSMIT=BLOCKED|reason:send_gate_not_enabled' );
	$block_from( array_slice( $all_steps, 6 ), 'no_document_issued' );
	WP_CLI::line( '' );
	WP_CLI::line( '>>> A TRANSMISSION IS REQUIRED TO CONTINUE <<<' );
	WP_CLI::line( '>>> SendInvoice ISSUES a document and EDM delivers it. Both gates are needed:' );
	WP_CLI::line( '>>>   1) KUKA_EDM_ALLOW_SANDBOX_SEND=true' );
	WP_CLI::line( '>>>   2) confirm=SendInvoice   (bare, no leading dashes)' );
	$claim->release();
	exit( 0 );
}

/* ========================================================================== */
/* The single transmission                                                     */
/* ========================================================================== */

WP_CLI::line( 'SANDBOX_SEND_TRANSMIT=RUNNING|operation:SendInvoice|state:in_flight|state_recorded:yes' );

$client = new Kuka_Island_Core_EDM_Client( $config );

$transmit = kuka_sandbox_execute_send( $claim, $client, $send_payload, $send_uuid );

WP_CLI::line(
	sprintf(
		'SANDBOX_SEND_TRANSMIT=%s|operation:SendInvoice|classification:%s|state:%s|state_recorded:%s|calls:%d',
		$transmit['ok'] ? 'PASS' : 'FAIL',
		$transmit['classification'],
		$transmit['state'],
		$transmit['state_recorded'] ? 'yes' : 'no',
		$transmit['calls']
	)
);

/*
 * The refusal, as the client itself classified it. Printed whenever the run did
 * not confirm, so an unsettled transmission always says what happened rather
 * than only that something did.
 */
if ( ! $transmit['ok'] ) {
	WP_CLI::line(
		sprintf(
			'SANDBOX_SEND_ERROR_CODE=%s',
			'' === (string) $transmit['error_code'] ? 'none' : (string) $transmit['error_code']
		)
	);
}
if ( ! empty( $transmit['fault'] ) ) {
	WP_CLI::line( sprintf( 'SANDBOX_SEND_FAULT=%s', Kuka_Island_Core_EDM_Fault_Classifier::to_safe_line( (array) $transmit['fault'] ) ) );
}

$write_ops_line = sprintf(
	'SANDBOX_SEND_WRITE_OPERATIONS=SendInvoice|count:%d|result:%s',
	$transmit['calls'],
	$transmit['classification']
);

/*
 * `documents_sent` states what is KNOWN. A confirmed transmission is 1. An
 * uncertain one is `unknown`, never 0: EDM was contacted and whether it kept
 * the document is exactly the fact this run failed to establish. Reporting 0
 * there would assert the safe answer without having measured it, and would
 * invite the resend this whole tool exists to prevent.
 */
$sendinvoice_line = sprintf(
	'SANDBOX_SENDINVOICE=EXECUTED|reason:operator_confirmed|documents_sent:%s|transmission_attempts:%d|state:%s|recipient_delivery:%s|second_call_attempted:no',
	$transmit['ok'] ? '1' : 'unknown',
	$transmit['calls'],
	$transmit['state'],
	$transmit['ok'] ? 'edm_delivers_to_header_to' : 'unknown'
);

if ( ! $transmit['ok'] ) {
	$block_from( array_slice( $all_steps, 6 ), 'transmission_not_confirmed' );
	WP_CLI::log( 'No second SendInvoice was attempted. Ask EDM what it holds, read-only:' );
	WP_CLI::log( '  ./scripts/edm-sandbox-send-run.sh resolve' );
	$claim->release();
	exit( 1 );
}

$assigned_number = (string) $transmit['assigned_number'];

WP_CLI::line(
	sprintf(
		'SANDBOX_SEND_NUMBER_ASSIGNED=%s|edm_returned_number:%s|number_length:%d|uuid_echoed_by_edm:%s|source:edm_response_only',
		'' !== $assigned_number ? 'PASS' : 'FAIL',
		'' !== $assigned_number ? 'yes' : 'no',
		strlen( $assigned_number ),
		$transmit['uuid_echoed'] ? 'yes' : 'no'
	)
);

/* ========================================================================== */
/* Readback: reads only                                                        */
/* ========================================================================== */

$read = kuka_sandbox_read_document( $client, $send_uuid, $assigned_number, $expected_payable, $expected_tax );

kuka_sandbox_report_readback( $read, 'SANDBOX_SEND_' );

WP_CLI::line(
	sprintf(
		'SANDBOX_SEND_CUSTOMER_EMAIL_READBACK=%s|expected:reserved_invalid_domain|found_in_stored_xml:%s',
		true === ( $read['email_match'] ?? false ) ? 'PASS' : 'FAIL',
		true === ( $read['email_match'] ?? false ) ? 'yes' : 'no'
	)
);

$client->logout();
$claim->release();

exit( 0 );
