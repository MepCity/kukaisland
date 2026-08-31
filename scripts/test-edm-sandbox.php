<?php
/**
 * Real EDM test-endpoint read-only probe.
 *
 * Runs ONLY read-only operations, in this order:
 *   1. Login            (needs username + password only)
 *   2. CheckCounter     (needs username + password only)
 *   3. GetInvoiceSerial (unfiltered first; filtered pass only if a series is supplied)
 *   4. CheckUser        (needs a sender VKN; BLOCKED without one -- this step alone)
 *   5. Logout
 *
 * Never calls SendInvoice, LoadInvoice, LoadInvoiceModel, CreateSerial,
 * EmailInvoice, CancelInvoice or any other document-creating or mutating
 * operation.
 *
 * Output discipline: PASS / FAIL / BLOCKED plus safe counts and booleans only.
 * No username, password, secret key, session id, VKN, alias, serial code or
 * last-serial value is ever printed.
 *
 * Credentials come from a mode-600 file outside the git work tree, bind-mounted
 * read-only into the container. Run through the wrapper:
 *   ./scripts/edm-test-run.sh test-edm-sandbox.php
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

require_once __DIR__ . '/lib-edm-test-credentials.php';

$steps = array( 'REAL_EDM_WSDL', 'REAL_EDM_LOGIN', 'REAL_EDM_CHECK_COUNTER', 'REAL_EDM_GET_INVOICE_SERIAL', 'REAL_EDM_CHECK_USER', 'REAL_EDM_LOGOUT' );

$block_all = static function ( string $reason ) use ( $steps ): void {
	foreach ( $steps as $step ) {
		WP_CLI::line( sprintf( '%s=BLOCKED|reason:%s', $step, $reason ) );
	}
	WP_CLI::line( 'REAL_EDM_WRITE_OPERATIONS=NONE|send_invoice:0|load_invoice:0|create_serial:0|email_invoice:0' );
};

$loaded = kuka_edm_test_credentials( true );

if ( ! $loaded['available'] ) {
	$block_all( $loaded['reason'] );
	WP_CLI::line( 'EDM_TEST_CREDENTIALS=' . ( array() === $loaded['presence'] ? 'file_absent' : kuka_edm_test_presence_summary( $loaded['presence'] ) ) );
	WP_CLI::log( 'No network call attempted. Create credentials with ./scripts/edm-test-credentials.sh' );
	exit( 0 );
}

WP_CLI::line( 'EDM_TEST_CREDENTIALS=' . kuka_edm_test_presence_summary( $loaded['presence'] ) );

$config = new Kuka_Island_Core_Invoice_Config( $loaded['overrides'] );

// Endpoint guard: this probe only ever talks to the EDM test service.
if ( $config->is_live() ) {
	$block_all( 'live_environment_refused_by_read_only_probe' );
	WP_CLI::error( 'Read-only probe refuses to run against the live endpoint.' );
}

if ( ! $config->has_login_credentials() ) {
	$block_all( 'username_or_password_missing' );
	exit( 0 );
}

if ( ! class_exists( 'SoapClient' ) ) {
	$block_all( 'php_ext_soap_missing' );
	WP_CLI::error( 'PHP ext-soap is not available.' );
}

WP_CLI::line( sprintf( 'REAL_EDM_WSDL=PASS|environment:%s|application_name_ok:%s', $config->get_environment(), 'ozelyazilim.kukaisland' === $config->get_application_name() ? 'yes' : 'no' ) );

$client = new Kuka_Island_Core_EDM_Client( $config );

/* -------------------------------------------------------------------------- */
/* 1. Login                                                                    */
/* -------------------------------------------------------------------------- */

$login_ok = false;
try {
	$login_ok = '' !== $client->login();
	WP_CLI::line( $login_ok ? 'REAL_EDM_LOGIN=PASS|session_obtained:yes' : 'REAL_EDM_LOGIN=FAIL|session_obtained:no' );
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	WP_CLI::line( sprintf( 'REAL_EDM_LOGIN=FAIL|safe_code:%s', $e->get_safe_error_code() ) );
}

if ( ! $login_ok ) {
	foreach ( array( 'REAL_EDM_CHECK_COUNTER', 'REAL_EDM_GET_INVOICE_SERIAL', 'REAL_EDM_CHECK_USER', 'REAL_EDM_LOGOUT' ) as $step ) {
		WP_CLI::line( sprintf( '%s=BLOCKED|reason:login_failed', $step ) );
	}
	WP_CLI::line( 'REAL_EDM_WRITE_OPERATIONS=NONE|send_invoice:0|load_invoice:0|create_serial:0|email_invoice:0' );
	WP_CLI::error( 'EDM test-endpoint login failed.' );
}

/* -------------------------------------------------------------------------- */
/* 2. CheckCounter                                                             */
/* -------------------------------------------------------------------------- */

try {
	$counter = $client->check_counter();
	$left    = $counter['counter_left'] ?? null;
	WP_CLI::line(
		is_int( $left )
			? sprintf( 'REAL_EDM_CHECK_COUNTER=PASS|counter_left:%d', $left )
			: 'REAL_EDM_CHECK_COUNTER=FAIL|reason:counter_left_missing'
	);
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	WP_CLI::line( sprintf( 'REAL_EDM_CHECK_COUNTER=FAIL|safe_code:%s', $e->get_safe_error_code() ) );
}

/* -------------------------------------------------------------------------- */
/* 3. GetInvoiceSerial -- unfiltered first, then filtered when a series exists  */
/* -------------------------------------------------------------------------- */

$unfiltered_count = null;
$unfiltered_code  = '';
try {
	$unfiltered       = $client->get_invoice_serial( '', (int) gmdate( 'Y' ), '' );
	$unfiltered_count = count( $unfiltered['serials'] ?? array() );
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	$unfiltered_code = $e->get_safe_error_code();
}

$series          = $config->get_series_earchive();
$filtered_count  = null;
$filtered_code   = '';
if ( '' !== $series ) {
	try {
		$filtered       = $client->get_invoice_serial( $series, (int) gmdate( 'Y' ), Kuka_Island_Core_Invoice_Numbering::SERIAL_TYPE_EARCHIVE );
		$filtered_count = count( $filtered['serials'] ?? array() );
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		$filtered_code = $e->get_safe_error_code();
	}
}

if ( null !== $unfiltered_count ) {
	// Counts only. Serial codes and LASTSERIALUSED values are fiscal data and
	// are deliberately not printed.
	WP_CLI::line(
		sprintf(
			'REAL_EDM_GET_INVOICE_SERIAL=PASS|unfiltered_registered_serials:%d|filtered_query:%s|filtered_registered_serials:%s',
			$unfiltered_count,
			'' === $series ? 'skipped_no_series_configured' : ( '' === $filtered_code ? 'performed' : 'failed' ),
			null === $filtered_count ? 'n/a' : (string) $filtered_count
		)
	);
} else {
	WP_CLI::line( sprintf( 'REAL_EDM_GET_INVOICE_SERIAL=FAIL|safe_code:%s', $unfiltered_code ?: 'unknown' ) );
}

/* -------------------------------------------------------------------------- */
/* 4. CheckUser -- the only step that needs a sender VKN                       */
/* -------------------------------------------------------------------------- */

if ( '' === $config->get_sender_vkn() ) {
	WP_CLI::line( 'REAL_EDM_CHECK_USER=BLOCKED|reason:no_sender_vkn_supplied' );
} else {
	try {
		$user = $client->check_user( $config->get_sender_vkn() );
		WP_CLI::line( sprintf( 'REAL_EDM_CHECK_USER=PASS|is_einvoice_user:%s|alias_present:%s', ! empty( $user['is_einvoice_user'] ) ? 'yes' : 'no', '' !== (string) ( $user['alias'] ?? '' ) ? 'yes' : 'no' ) );
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		WP_CLI::line( sprintf( 'REAL_EDM_CHECK_USER=FAIL|safe_code:%s', $e->get_safe_error_code() ) );
	}
}

/* -------------------------------------------------------------------------- */
/* 5. Logout                                                                   */
/* -------------------------------------------------------------------------- */

try {
	$logout_ok = $client->logout() && null === $client->get_session_id();
	WP_CLI::line( $logout_ok ? 'REAL_EDM_LOGOUT=PASS|session_closed:yes' : 'REAL_EDM_LOGOUT=FAIL|session_closed:no' );
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	WP_CLI::line( sprintf( 'REAL_EDM_LOGOUT=FAIL|safe_code:%s', $e->get_safe_error_code() ) );
}

WP_CLI::line( 'REAL_EDM_WRITE_OPERATIONS=NONE|send_invoice:0|load_invoice:0|create_serial:0|email_invoice:0' );
WP_CLI::success( 'EDM test-endpoint read-only probe completed.' );
