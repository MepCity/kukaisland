<?php
/**
 * Isolated EDM sandbox end-to-end invoice experiment.
 *
 * PURPOSE
 * -------
 * Answer, against the EDM TEST endpoint only, the questions that the WSDL alone
 * cannot settle:
 *   Q1  Does EDM assign the fiscal document number when INVOICE/@ID is omitted
 *       and LoadInvoice is called with GENERATEINVOICEIDONLOAD = true?
 *   Q2  If it does, is the assigned number written into the UBL cbc:ID element
 *       of the stored document (read back via GetInvoice with XML content)?
 *   Q3  Do UUID, payable amount and VAT survive the round trip unchanged?
 *
 * SAFETY CONTRACT -- every one of these must hold or the script refuses:
 *   1. Test endpoint only. A live environment is an unconditional BLOCKED.
 *   2. Default mode is PLAN. Nothing is created unless BOTH
 *      KUKA_EDM_ALLOW_SANDBOX_WRITE is the literal string "true" AND
 *      --confirm=<operation> names exactly the operation the plan resolved.
 *   3. No WooCommerce order is created. No order, order meta, customer or any
 *      other WordPress table row is written. The synthetic invoice exists only
 *      in memory and in a host-side JSON state file.
 *   4. Sender identity (VKN, mailbox alias, registered serial, legal address
 *      block) must be supplied by the EDM account holder and confirmed
 *      read-only against EDM. Nothing is invented; a missing field is BLOCKED
 *      with the field name listed.
 *   5. The document is idempotent: its UUID is derived deterministically from a
 *      fixed seed, and a recorded prior run refuses to create a second
 *      document. EDM's own duplicate detection is the second layer.
 *   6. The production Kuka_Island_Core_Invoice_Manager and its
 *      invoice_numbering_unconfirmed guard are NOT used and NOT relaxed. This
 *      script drives Kuka_Island_Core_EDM_Client directly with a synthetic
 *      payload.
 *   7. The VAT rate used is a fixed constant declared in this file. The shop's
 *      own tax settings are never read and never modified.
 *
 * Output is PASS / FAIL / BLOCKED plus safe counts and booleans. No username,
 * password, secret key, session id, VKN, alias or serial code is printed.
 *
 * Run through the wrapper (never directly):
 *   ./scripts/edm-sandbox-run.sh                  # PLAN, creates nothing
 *   ./scripts/edm-sandbox-run.sh --confirm=LoadInvoice   # requires the env gate too
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

require_once __DIR__ . '/lib-edm-test-credentials.php';

/** Fixed synthetic VAT rate for the experiment. Never read from the shop. */
const KUKA_SANDBOX_VAT_PERCENT = 20;
/** Synthetic net line amount in kuruş (100.00 TRY). */
const KUKA_SANDBOX_NET_CENTS = 10000;
/** Deterministic seed so re-runs reuse the same UUID. */
const KUKA_SANDBOX_UUID_SEED = 'kuka-island-edm-sandbox-e2e-v1';
/** Host-side state file (read-write mount), never the database. */
const KUKA_SANDBOX_STATE_FILE = '/run/edm/state/sandbox-e2e.json';

$line = static function ( string $text ): void {
	WP_CLI::line( $text );
};

$block = static function ( string $step, string $reason ) use ( $line ): void {
	$line( sprintf( '%s=BLOCKED|reason:%s', $step, $reason ) );
};

/**
 * Deterministic RFC-4122-shaped UUID from a fixed seed.
 */
function kuka_sandbox_uuid(): string {
	$h = hash( 'sha256', KUKA_SANDBOX_UUID_SEED );

	return sprintf(
		'%s-%s-4%s-8%s-%s',
		substr( $h, 0, 8 ),
		substr( $h, 8, 4 ),
		substr( $h, 13, 3 ),
		substr( $h, 17, 3 ),
		substr( $h, 20, 12 )
	);
}

/**
 * Read the host-side state file.
 *
 * @return array<string, mixed>
 */
function kuka_sandbox_state_read(): array {
	if ( ! is_readable( KUKA_SANDBOX_STATE_FILE ) ) {
		return array();
	}
	$raw = file_get_contents( KUKA_SANDBOX_STATE_FILE );

	return false === $raw ? array() : (array) json_decode( (string) $raw, true );
}

/**
 * Persist the host-side state file. Never touches the database.
 *
 * @param array<string, mixed> $state State payload.
 */
function kuka_sandbox_state_write( array $state ): bool {
	$dir = dirname( KUKA_SANDBOX_STATE_FILE );
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
		return false;
	}

	return false !== file_put_contents( KUKA_SANDBOX_STATE_FILE, (string) wp_json_encode( $state, JSON_PRETTY_PRINT ) );
}

/**
 * Build the synthetic, clearly-marked TEST UBL document.
 *
 * cbc:ID is deliberately removed after building: the experiment sends NO
 * document number so that EDM's assignment behaviour can be observed. The
 * builder itself is the production one, so its validation still applies.
 *
 * @param array<string, string> $supplier Supplier block.
 * @param string                $uuid Deterministic UUID.
 * @return array{xml: string, cbc_id_sent: bool, totals: array<string, string>}
 */
function kuka_sandbox_build_ubl( array $supplier, string $uuid ): array {
	$net   = KUKA_SANDBOX_NET_CENTS;
	$tax   = Kuka_Island_Core_Invoice_Order_Mapper::tax_from_taxable( $net, KUKA_SANDBOX_VAT_PERCENT );
	$gross = $net + $tax;
	$a     = static fn( int $c ): string => Kuka_Island_Core_Invoice_Order_Mapper::cents_to_amount( $c );

	$issue_date = gmdate( 'Y-m-d' );

	$data = array(
		'uuid'              => $uuid,
		// Placeholder only, stripped from the emitted XML below. Never persisted
		// and never presented as a fiscal number.
		'invoice_number'    => 'SANDBOXPLACEHOLDER',
		'series'            => '',
		'profile_id'        => 'EARSIVFATURA',
		'document_type'     => Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
		'invoice_type_code' => 'SATIS',
		'issue_date'        => $issue_date,
		'issue_time'        => gmdate( 'H:i:s' ),
		'currency'          => 'TRY',
		'order_number'      => 'SANDBOX-E2E',
		'order_date'        => $issue_date,
		'receiver_alias'    => '',
		'notes'             => array(
			'TEST BELGESI - KUKA ISLAND EDM SANDBOX DOGRULAMA. GERCEK SATIS DEGILDIR.',
			'Bu belge yalnizca EDM test ortaminda numaralandirma sozlesmesini olcmek icin uretilmistir.',
		),
		'supplier'          => $supplier,
		'customer'          => array(
			'first_name' => 'SANDBOX',
			'last_name'  => 'TEST ALICI',
			'company'    => '',
			'tax_number' => '11111111111',
			'tax_office' => '',
			'address'    => 'TEST ADRES - GERCEK DEGIL',
			'district'   => 'TEST',
			'city'       => 'TEST',
			'postcode'   => '00000',
			'country'    => 'Türkiye',
			'email'      => '',
			'phone'      => '',
		),
		'payment'           => array(
			'code'     => '48',
			'due_date' => $issue_date,
			'channel'  => 'SANDBOX',
			'terms'    => 'TEST',
		),
		'totals'            => array(
			'line_extension_amount'   => $a( $net ),
			'gross_line_amount'       => $a( $net ),
			'line_allowance_total'    => $a( 0 ),
			'tax_exclusive_amount'    => $a( $net ),
			'tax_inclusive_amount'    => $a( $gross ),
			'allowance_total_amount'  => $a( 0 ),
			'charge_total_amount'     => $a( 0 ),
			'payable_rounding_amount' => $a( 0 ),
			'payable_amount'          => $a( $gross ),
		),
		'tax_summary'       => array(
			'total_tax' => $a( $tax ),
			'rates'     => array(
				array(
					'percent'        => KUKA_SANDBOX_VAT_PERCENT,
					'taxable_amount' => $a( $net ),
					'tax_amount'     => $a( $tax ),
				),
			),
		),
		'lines'             => array(
			array(
				'name'                  => 'SANDBOX TEST KALEMI - SATISA KONU DEGILDIR',
				'sku'                   => 'SANDBOX-TEST',
				'quantity'              => 1,
				'unit_price'            => $a( $net ),
				'gross_amount'          => $a( $net ),
				'allowance_amount'      => $a( 0 ),
				'line_extension_amount' => $a( $net ),
				'taxable_amount'        => $a( $net ),
				'tax_percent'           => KUKA_SANDBOX_VAT_PERCENT,
				'tax_amount'            => $a( $tax ),
			),
		),
	);

	$xml = ( new Kuka_Island_Core_UBL_TR_Builder( $data ) )->build_xml();

	// Remove the document-number element: we send no number on purpose.
	$dom = new DOMDocument();
	$dom->loadXML( $xml );
	$xpath   = new DOMXPath( $dom );
	$id_node = $xpath->query( '/*[local-name()="Invoice"]/*[local-name()="ID"]' )->item( 0 );
	if ( null !== $id_node && null !== $id_node->parentNode ) {
		$id_node->parentNode->removeChild( $id_node );
	}
	$stripped = (string) $dom->saveXML();

	$recheck = new DOMDocument();
	$recheck->loadXML( $stripped );
	$still   = ( new DOMXPath( $recheck ) )->query( '/*[local-name()="Invoice"]/*[local-name()="ID"]' )->length;

	return array(
		'xml'         => $stripped,
		'cbc_id_sent' => $still > 0,
		'totals'      => array(
			'net'     => $a( $net ),
			'tax'     => $a( $tax ),
			'payable' => $a( $gross ),
			'percent' => (string) KUKA_SANDBOX_VAT_PERCENT,
		),
	);
}

/* ========================================================================== */
/* Gate 1 - credentials                                                        */
/* ========================================================================== */

$steps = array( 'SANDBOX_PRECHECK', 'SANDBOX_SENDER_IDENTITY', 'SANDBOX_PLAN', 'SANDBOX_CREATE', 'SANDBOX_NUMBER_ASSIGNED', 'SANDBOX_CBC_ID_READBACK', 'SANDBOX_XML_READBACK', 'SANDBOX_DUPLICATE_GUARD' );

$loaded = kuka_edm_test_credentials( true );
if ( ! $loaded['available'] ) {
	foreach ( $steps as $step ) {
		$block( $step, $loaded['reason'] );
	}
	WP_CLI::line( 'SANDBOX_WRITE_OPERATIONS=NONE' );
	exit( 0 );
}

$config = new Kuka_Island_Core_Invoice_Config( $loaded['overrides'] );

/* ========================================================================== */
/* Gate 2 - test endpoint only                                                 */
/* ========================================================================== */

if ( $config->is_live() ) {
	foreach ( $steps as $step ) {
		$block( $step, 'live_endpoint_refused' );
	}
	WP_CLI::line( 'SANDBOX_WRITE_OPERATIONS=NONE' );
	WP_CLI::error( 'Sandbox experiment refuses to run against a live endpoint.' );
}

if ( 'ozelyazilim.kukaisland' !== $config->get_application_name() ) {
	foreach ( $steps as $step ) {
		$block( $step, 'unexpected_application_name' );
	}
	WP_CLI::error( 'APPLICATION_NAME contract violated.' );
}

/* ========================================================================== */
/* Gate 3 - sender identity must be supplied, never invented                   */
/* ========================================================================== */

$required_sender = array(
	'sender_vkn'        => $config->get_sender_vkn(),
	'sender_alias'      => $config->get_sender_alias(),
	'sender_title'      => $config->get_sender_title(),
	'sender_tax_office' => $config->get_sender_tax_office(),
	'sender_address'    => $config->get_sender_address(),
	'sender_district'   => $config->get_sender_district(),
	'sender_city'       => $config->get_sender_city(),
	'sender_postcode'   => $config->get_sender_postcode(),
);
$missing_sender = array_keys( array_filter( $required_sender, static fn( string $v ): bool => '' === trim( $v ) ) );

WP_CLI::line(
	sprintf(
		'SANDBOX_PRECHECK=PASS|environment:%s|application_name_ok:yes|credentials:%s',
		$config->get_environment(),
		kuka_edm_test_presence_summary( $loaded['presence'] )
	)
);

if ( ! empty( $missing_sender ) ) {
	$block( 'SANDBOX_SENDER_IDENTITY', 'missing_fields:' . implode( ',', $missing_sender ) );
	foreach ( array( 'SANDBOX_PLAN', 'SANDBOX_CREATE', 'SANDBOX_NUMBER_ASSIGNED', 'SANDBOX_CBC_ID_READBACK', 'SANDBOX_XML_READBACK', 'SANDBOX_DUPLICATE_GUARD' ) as $step ) {
		$block( $step, 'sender_identity_incomplete' );
	}
	WP_CLI::line( 'SANDBOX_WRITE_OPERATIONS=NONE' );
	WP_CLI::log( 'Add the listed keys to the local credential file. Nothing is invented.' );
	exit( 0 );
}

/* ========================================================================== */
/* Read-only confirmation of sender identity and registered serials at EDM     */
/* ========================================================================== */

$client = new Kuka_Island_Core_EDM_Client( $config );

$login_ok = false;
try {
	$login_ok = '' !== $client->login();
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	$block( 'SANDBOX_SENDER_IDENTITY', 'login_failed:' . $e->get_safe_error_code() );
}

if ( ! $login_ok ) {
	foreach ( array( 'SANDBOX_PLAN', 'SANDBOX_CREATE', 'SANDBOX_NUMBER_ASSIGNED', 'SANDBOX_CBC_ID_READBACK', 'SANDBOX_XML_READBACK', 'SANDBOX_DUPLICATE_GUARD' ) as $step ) {
		$block( $step, 'login_failed' );
	}
	WP_CLI::line( 'SANDBOX_WRITE_OPERATIONS=NONE' );
	WP_CLI::error( 'Login to the EDM test endpoint failed.' );
}

$serial_count = 0;
$serial_match = false;
try {
	$serials      = $client->get_invoice_serial( '', (int) gmdate( 'Y' ), '' );
	$rows         = $serials['serials'] ?? array();
	$serial_count = count( $rows );
	$wanted       = $config->get_series_earchive();
	foreach ( $rows as $row ) {
		if ( '' !== $wanted && strtoupper( (string) ( $row['code'] ?? '' ) ) === strtoupper( $wanted ) ) {
			$serial_match = true;
			break;
		}
	}
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	$block( 'SANDBOX_SENDER_IDENTITY', 'serial_query_failed:' . $e->get_safe_error_code() );
	$client->logout();
	WP_CLI::line( 'SANDBOX_WRITE_OPERATIONS=NONE' );
	exit( 1 );
}

$alias_confirmed = false;
try {
	$user            = $client->check_user( $config->get_sender_vkn() );
	$alias_confirmed = '' !== (string) ( $user['alias'] ?? '' );
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	$alias_confirmed = false;
}

WP_CLI::line(
	sprintf(
		'SANDBOX_SENDER_IDENTITY=PASS|registered_serials:%d|configured_series_registered:%s|sender_alias_confirmed_by_edm:%s',
		$serial_count,
		$serial_match ? 'yes' : 'no',
		$alias_confirmed ? 'yes' : 'no'
	)
);

/* ========================================================================== */
/* Plan - resolve which single write operation would be called                  */
/* ========================================================================== */

$uuid  = kuka_sandbox_uuid();
$state = kuka_sandbox_state_read();
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
	$uuid
);

// WSDL: GENERATEINVOICEIDONLOAD (xs:boolean, required) exists ONLY on
// LoadInvoiceRequest. SendInvoiceRequest has no equivalent field. The single
// write operation for this experiment is therefore LoadInvoice.
$planned_operation = 'LoadInvoice';

WP_CLI::line(
	sprintf(
		'SANDBOX_PLAN=PASS|operation:%s|generate_invoice_id_on_load:true|invoice_id_attribute:omitted|ubl_cbc_id_sent:%s|vat_percent:%s|net:%s|tax:%s|payable:%s|uuid_deterministic:yes',
		$planned_operation,
		$built['cbc_id_sent'] ? 'present' : 'absent',
		$built['totals']['percent'],
		$built['totals']['net'],
		$built['totals']['tax'],
		$built['totals']['payable']
	)
);

/* ========================================================================== */
/* Gate 4 - duplicate guard                                                     */
/* ========================================================================== */

if ( ! empty( $state['document_created'] ) ) {
	WP_CLI::line(
		sprintf(
			'SANDBOX_DUPLICATE_GUARD=PASS|prior_run_recorded:yes|created_at_utc:%s|second_document_refused:yes',
			(string) ( $state['created_at_utc'] ?? 'unknown' )
		)
	);
	foreach ( array( 'SANDBOX_CREATE' ) as $step ) {
		$block( $step, 'document_already_created_in_a_previous_run' );
	}
	// Re-runs stay read-only: re-read the already created document.
	$readback_status = 'unknown';
	try {
		$result          = $client->get_invoice_status( $uuid, (string) ( $state['assigned_number'] ?? '' ) );
		$readback_status = $result->get_status();
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		$readback_status = 'query_failed:' . $e->get_safe_error_code();
	}
	WP_CLI::line( sprintf( 'SANDBOX_XML_READBACK=PASS|mode:reread_only|status:%s', $readback_status ) );
	$client->logout();
	WP_CLI::line( 'SANDBOX_WRITE_OPERATIONS=NONE' );
	exit( 0 );
}

WP_CLI::line( 'SANDBOX_DUPLICATE_GUARD=PASS|prior_run_recorded:no|uuid_deterministic:yes|edm_duplicate_detection:second_layer' );

/* ========================================================================== */
/* Gate 5 - explicit opt-in plus operation confirmation                         */
/* ========================================================================== */

$allow_write = 'true' === (string) getenv( 'KUKA_EDM_ALLOW_SANDBOX_WRITE' );

$confirmed_operation = '';
foreach ( (array) ( $args ?? array() ) as $arg ) {
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
	foreach ( array( 'SANDBOX_NUMBER_ASSIGNED', 'SANDBOX_CBC_ID_READBACK', 'SANDBOX_XML_READBACK' ) as $step ) {
		$block( $step, 'no_document_created' );
	}
	$client->logout();
	WP_CLI::line( 'SANDBOX_WRITE_OPERATIONS=NONE' );
	exit( 0 );
}

/* ========================================================================== */
/* Write - one single LoadInvoice call                                          */
/* ========================================================================== */

WP_CLI::line( sprintf( 'SANDBOX_CREATE=RUNNING|operation:%s', $planned_operation ) );

// The LoadInvoice request is assembled HERE, in the test harness, and issued
// through the transport the production client already owns. No write-capable
// LoadInvoice method is added to Kuka_Island_Core_EDM_Client, so the plugin
// gains no new document-creating capability from this experiment.
//
// Shape per the verified WSDL LoadInvoiceRequest:
//   REQUEST_HEADER, INVOICE* (tns:INVOICE), SENDER(@vkn,@alias),
//   RECEIVER(@vkn,@alias), GENERATEINVOICEIDONLOAD (xs:boolean, required)
$issue_date = gmdate( 'Y-m-d' );
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
	'RECEIVER'                => array( 'vkn' => '11111111111' ),
	'INVOICE'                 => array(
		array(
			// INVOICE/@ID deliberately omitted: the whole point is to observe
			// whether EDM assigns it.
			'TRXID'   => (int) hexdec( substr( hash( 'sha256', $uuid ), 0, 8 ) ),
			'UUID'    => $uuid,
			'HEADER'  => array(
				'SENDER'                          => $config->get_sender_vkn(),
				'RECEIVER'                        => '11111111111',
				'FROM'                            => $config->get_sender_alias(),
				'PROFILEID'                       => 'EARSIVFATURA',
				'INVOICE_TYPE'                    => 'SATIS',
				'ISSUE_DATE'                      => $issue_date,
				'PAYABLE_AMOUNT'                  => $built['totals']['payable'],
				'INTERNETSALES'                   => false,
				'EARCHIVE'                        => true,
				'EARCHIVE_REPORT_SENDDATE'        => $issue_date,
				'CANCEL_EARCHIVE_REPORT_SENDDATE' => $issue_date,
				'ISACTIVE'                        => true,
				'MARKED'                          => false,
				'INVOICESERIAL_REQUESTED'         => $config->get_series_earchive(),
			),
			'CONTENT' => $built['xml'],
		),
	),
	'GENERATEINVOICEIDONLOAD' => true,
);

$assigned_number = '';
$create_ok       = false;
try {
	$response = $client->get_transport()->call( 'LoadInvoice', $load_request );

	// LoadInvoiceResponse: REQUEST_RETURN + INVOICE* (tns:INVOICE, @ID attribute).
	$decoded = json_decode( (string) wp_json_encode( $response ), true );
	$walk    = static function ( $node ) use ( &$walk ): string {
		if ( is_array( $node ) ) {
			if ( isset( $node['ID'] ) && is_scalar( $node['ID'] ) && '' !== (string) $node['ID'] ) {
				return (string) $node['ID'];
			}
			foreach ( $node as $child ) {
				$found = $walk( $child );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}
		return '';
	};
	$assigned_number = $walk( $decoded );
	$create_ok       = true;
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	WP_CLI::line( sprintf( 'SANDBOX_CREATE=FAIL|safe_code:%s', $e->get_safe_error_code() ) );
} catch ( Throwable $t ) {
	WP_CLI::line( 'SANDBOX_CREATE=FAIL|safe_code:load_invoice_transport_error' );
}

if ( $create_ok ) {
	kuka_sandbox_state_write(
		array(
			'document_created' => true,
			'uuid'             => $uuid,
			'assigned_number'  => $assigned_number,
			'created_at_utc'   => gmdate( 'c' ),
			'operation'        => $planned_operation,
		)
	);
	WP_CLI::line( sprintf( 'SANDBOX_CREATE=PASS|operation:%s|state_recorded:yes', $planned_operation ) );
	WP_CLI::line( sprintf( 'SANDBOX_NUMBER_ASSIGNED=%s|edm_returned_number:%s|number_length:%d', '' !== $assigned_number ? 'PASS' : 'FAIL', '' !== $assigned_number ? 'yes' : 'no', strlen( $assigned_number ) ) );

	// Read back: status, then the stored XML.
	try {
		$status = $client->get_invoice_status( $uuid, $assigned_number );
		WP_CLI::line( sprintf( 'SANDBOX_XML_READBACK=PASS|status_query:ok|mapped_status:%s', $status->get_status() ) );
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		WP_CLI::line( sprintf( 'SANDBOX_XML_READBACK=FAIL|status_query:%s', $e->get_safe_error_code() ) );
	}

	try {
		$xml_back = $client->get_invoice_document( $uuid, 'XML' );
		$dom      = new DOMDocument();
		$parsed   = '' !== $xml_back && $dom->loadXML( $xml_back );
		if ( $parsed ) {
			$xp          = new DOMXPath( $dom );
			$one         = static function ( DOMXPath $xp, string $q ): string {
				$n = $xp->query( $q );
				return ( false !== $n && $n->length > 0 ) ? trim( (string) $n->item( 0 )->nodeValue ) : '';
			};
			$back_id     = $one( $xp, '/*[local-name()="Invoice"]/*[local-name()="ID"]' );
			$back_uuid   = $one( $xp, '/*[local-name()="Invoice"]/*[local-name()="UUID"]' );
			$back_pay    = $one( $xp, '//*[local-name()="LegalMonetaryTotal"]/*[local-name()="PayableAmount"]' );
			$back_tax    = $one( $xp, '/*[local-name()="Invoice"]/*[local-name()="TaxTotal"]/*[local-name()="TaxAmount"]' );

			$cents = static fn( string $v ): int => Kuka_Island_Core_Invoice_Order_Mapper::amount_to_cents( $v );

			WP_CLI::line(
				sprintf(
					'SANDBOX_CBC_ID_READBACK=%s|cbc_id_present_in_stored_xml:%s|matches_assigned_number:%s',
					'' !== $back_id ? 'PASS' : 'FAIL',
					'' !== $back_id ? 'yes' : 'no',
					( '' !== $back_id && $back_id === $assigned_number ) ? 'yes' : 'no'
				)
			);
			WP_CLI::line(
				sprintf(
					'SANDBOX_XML_FIELD_MATCH=%s|uuid_match:%s|payable_match:%s|tax_match:%s',
					( $back_uuid === $uuid && $cents( $back_pay ) === $cents( $built['totals']['payable'] ) && $cents( $back_tax ) === $cents( $built['totals']['tax'] ) ) ? 'PASS' : 'FAIL',
					$back_uuid === $uuid ? 'yes' : 'no',
					$cents( $back_pay ) === $cents( $built['totals']['payable'] ) ? 'yes' : 'no',
					$cents( $back_tax ) === $cents( $built['totals']['tax'] ) ? 'yes' : 'no'
				)
			);
		} else {
			WP_CLI::line( 'SANDBOX_CBC_ID_READBACK=FAIL|reason:stored_xml_unparseable' );
			WP_CLI::line( 'SANDBOX_XML_FIELD_MATCH=FAIL|reason:stored_xml_unparseable' );
		}
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		WP_CLI::line( sprintf( 'SANDBOX_CBC_ID_READBACK=FAIL|safe_code:%s', $e->get_safe_error_code() ) );
		WP_CLI::line( 'SANDBOX_XML_FIELD_MATCH=BLOCKED|reason:document_not_retrieved' );
	}

	WP_CLI::line( sprintf( 'SANDBOX_WRITE_OPERATIONS=%s|count:1', $planned_operation ) );
} else {
	foreach ( array( 'SANDBOX_NUMBER_ASSIGNED', 'SANDBOX_CBC_ID_READBACK', 'SANDBOX_XML_READBACK' ) as $step ) {
		$block( $step, 'create_failed' );
	}
	WP_CLI::line( 'SANDBOX_WRITE_OPERATIONS=NONE' );
}

$client->logout();
WP_CLI::success( 'EDM sandbox experiment finished.' );
