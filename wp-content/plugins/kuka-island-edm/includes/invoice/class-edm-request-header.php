<?php
/**
 * The single REQUEST_HEADER generator for every EDM SOAP call.
 *
 * Production (Kuka_Island_Core_EDM_Client) and the isolated sandbox experiment
 * (scripts/lib-edm-sandbox.php) both build LoadInvoice-shaped requests. They
 * drifted apart once already: the sandbox sent four header fields while the
 * client sent eight, which is the deviation this class exists to make
 * impossible. There is one pure builder and both paths call it, so a change to
 * the contract can only be made in one place.
 *
 * WSDL (tns:REQUEST_HEADERType) declares, in this order and all optional:
 * SESSION_ID, CLIENT_TXN_ID, INTL_TXN_ID, INTL_PARENT_TXN_ID, ACTION_DATE,
 * CHANGE_INFO, REASON, APPLICATION_NAME, HOSTNAME, CHANNEL_NAME,
 * SIMULATION_FLAG, COMPRESSED, ATTRIBUTES.
 *
 * EDM's reference envelope populates exactly the eight in self::FIELDS, in that
 * order, and that is what this builder emits.
 *
 * Nothing here reads configuration, the environment, the request or any
 * credential: every value is either a fixed contract constant or an explicit
 * argument, so the builder cannot log or leak a secret.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_EDM_Request_Header {

	/** REQUEST_HEADER/CHANNEL_NAME. Fixed integration channel label. */
	public const CHANNEL_NAME = 'WEB';

	/**
	 * REQUEST_HEADER/HOSTNAME. A fixed application label, never a real machine
	 * name and never the request's Host header: the first leaks internal
	 * infrastructure and the second is attacker-controlled.
	 */
	public const HOSTNAME = 'kukaisland';

	/** REQUEST_HEADER/COMPRESSED. The payload is not gzip-compressed. */
	public const COMPRESSED = 'N';

	/** SESSION_ID value for Login, which has no session yet. */
	public const NO_SESSION = '0';

	/**
	 * The eight fields EDM's reference envelope carries, in envelope order.
	 *
	 * Exposed so a test can assert the emitted header against the contract
	 * rather than against a copy of it.
	 */
	public const FIELDS = array(
		'SESSION_ID',
		'CLIENT_TXN_ID',
		'ACTION_DATE',
		'REASON',
		'APPLICATION_NAME',
		'HOSTNAME',
		'CHANNEL_NAME',
		'COMPRESSED',
	);

	/**
	 * Build a complete REQUEST_HEADER.
	 *
	 * @param string $session_id       Session identifier, or self::NO_SESSION for Login.
	 * @param string $reason           Operation name recorded as REASON.
	 * @param string $application_name Contracted application name.
	 * @param string $client_txn_id    Caller-chosen transaction id. Empty means a
	 *                                 fresh UUID; LoadInvoice and SendInvoice pass
	 *                                 the document UUID so the header stays
	 *                                 idempotency-bound.
	 * @param string $action_date      UTC ISO timestamp. Empty means now.
	 * @return array<string, string>
	 */
	public static function build(
		string $session_id,
		string $reason,
		string $application_name,
		string $client_txn_id = '',
		string $action_date = ''
	): array {
		return array(
			'SESSION_ID'       => $session_id,
			'CLIENT_TXN_ID'    => '' !== $client_txn_id ? $client_txn_id : wp_generate_uuid4(),
			'ACTION_DATE'      => '' !== $action_date ? $action_date : gmdate( 'Y-m-d\TH:i:s' ),
			'REASON'           => $reason,
			'APPLICATION_NAME' => $application_name,
			'HOSTNAME'         => self::HOSTNAME,
			'CHANNEL_NAME'     => self::CHANNEL_NAME,
			'COMPRESSED'       => self::COMPRESSED,
		);
	}
}
