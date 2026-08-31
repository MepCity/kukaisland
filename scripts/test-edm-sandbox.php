<?php
/**
 * Real EDM Sandbox read-only probe.
 *
 * Executes ONLY read-only operations against the real EDM test endpoint when
 * runtime credentials are supplied: Login, CheckCounter, GetInvoiceSerial,
 * CheckUser, Logout. Each result is reported separately.
 *
 * This script never calls SendInvoice, CreateSerial, CancelInvoice,
 * EmailInvoice or any other operation that mutates state at EDM or at GİB.
 * Without credentials every step is reported as BLOCKED -- never PASS.
 *
 * Usernames, passwords, secret keys and session identifiers are never printed.
 *
 * Run with:
 * docker compose run --rm -T wp-cli wp eval-file /project-scripts/test-edm-sandbox.php
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

$config = new Kuka_Island_Core_Invoice_Config();

$blocked = static function ( string $reason ): void {
	foreach ( array( 'REAL_EDM_WSDL', 'REAL_EDM_LOGIN', 'REAL_EDM_CHECK_COUNTER', 'REAL_EDM_GET_INVOICE_SERIAL', 'REAL_EDM_CHECK_USER', 'REAL_EDM_LOGOUT' ) as $step ) {
		WP_CLI::line( sprintf( '%s=BLOCKED|reason:%s', $step, $reason ) );
	}
	WP_CLI::line( 'REAL_EDM_SEND_INVOICE=SKIPPED|reason:read_only_probe_never_sends' );
};

if ( ! $config->can_run_read_only_sandbox() ) {
	$blocked( 'no_runtime_credentials' );
	WP_CLI::log( 'EDM sandbox credentials have not been provided. No network call was attempted.' );
	exit( 0 );
}

if ( ! class_exists( 'SoapClient' ) ) {
	$blocked( 'php_ext_soap_missing' );
	WP_CLI::error( 'PHP ext-soap is not available.' );
}

WP_CLI::line( 'REAL_EDM_WSDL=PASS|endpoint:test' );
WP_CLI::log( 'Connecting to the real EDM test endpoint (read-only)...' );

$client = new Kuka_Island_Core_EDM_Client( $config );

// 1. Login.
$session_ok = false;
try {
	$session_ok = '' !== $client->login();
	WP_CLI::line( $session_ok ? 'REAL_EDM_LOGIN=PASS|session_obtained:yes' : 'REAL_EDM_LOGIN=FAIL|session_obtained:no' );
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	WP_CLI::line( sprintf( 'REAL_EDM_LOGIN=FAIL|safe_code:%s', $e->get_safe_error_code() ) );
}

if ( ! $session_ok ) {
	foreach ( array( 'REAL_EDM_CHECK_COUNTER', 'REAL_EDM_GET_INVOICE_SERIAL', 'REAL_EDM_CHECK_USER', 'REAL_EDM_LOGOUT' ) as $step ) {
		WP_CLI::line( sprintf( '%s=BLOCKED|reason:login_failed', $step ) );
	}
	WP_CLI::line( 'REAL_EDM_SEND_INVOICE=SKIPPED|reason:read_only_probe_never_sends' );
	WP_CLI::error( 'EDM sandbox login failed.' );
}

// 2. CheckCounter, verified through the COUNTER_LEFT field.
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

// 3. GetInvoiceSerial: read-only evidence for the fiscal numbering contract.
try {
	$series  = $config->get_series_earchive();
	$serials = $client->get_invoice_serial( $series, (int) gmdate( 'Y' ), Kuka_Island_Core_Invoice_Numbering::SERIAL_TYPE_EARCHIVE );
	$found   = $serials['serials'] ?? array();
	WP_CLI::line(
		sprintf(
			'REAL_EDM_GET_INVOICE_SERIAL=PASS|registered_serials:%d|codes:%s',
			count( $found ),
			empty( $found ) ? 'none' : implode( ',', array_map( static fn( array $s ): string => $s['code'] . '/' . $s['last_serial_used'], $found ) )
		)
	);
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	WP_CLI::line( sprintf( 'REAL_EDM_GET_INVOICE_SERIAL=FAIL|safe_code:%s', $e->get_safe_error_code() ) );
}

// 4. CheckUser against the configured sender VKN.
$sender_vkn = $config->get_sender_vkn();
if ( '' === $sender_vkn ) {
	WP_CLI::line( 'REAL_EDM_CHECK_USER=BLOCKED|reason:no_sender_vkn_configured' );
} else {
	try {
		$user = $client->check_user( $sender_vkn );
		WP_CLI::line( sprintf( 'REAL_EDM_CHECK_USER=PASS|is_einvoice_user:%s', ! empty( $user['is_einvoice_user'] ) ? 'yes' : 'no' ) );
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		WP_CLI::line( sprintf( 'REAL_EDM_CHECK_USER=FAIL|safe_code:%s', $e->get_safe_error_code() ) );
	}
}

// 5. Logout.
try {
	$logout_ok = $client->logout() && null === $client->get_session_id();
	WP_CLI::line( $logout_ok ? 'REAL_EDM_LOGOUT=PASS|session_closed:yes' : 'REAL_EDM_LOGOUT=FAIL|session_closed:no' );
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	WP_CLI::line( sprintf( 'REAL_EDM_LOGOUT=FAIL|safe_code:%s', $e->get_safe_error_code() ) );
}

WP_CLI::line( 'REAL_EDM_SEND_INVOICE=SKIPPED|reason:read_only_probe_never_sends' );
WP_CLI::success( 'Real EDM sandbox read-only probe completed.' );
