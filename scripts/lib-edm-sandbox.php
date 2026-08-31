<?php
/**
 * Testable core of the isolated EDM sandbox invoice experiment.
 *
 * Everything here is a pure function or a file-backed state machine with an
 * injectable path, so scripts/verify-edm-sandbox-harness.php can prove the
 * refusal paths with fixtures and a mocked transport, without any network call
 * and without creating any document.
 *
 * Nothing in this file talks to EDM. The driver
 * (scripts/edm-sandbox-invoice.php) supplies the facts.
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

/** Fixed synthetic VAT rate for the experiment. Never read from the shop. */
const KUKA_SANDBOX_VAT_PERCENT = 20;
/** Synthetic net line amount in kuruş (100.00 TRY). */
const KUKA_SANDBOX_NET_CENTS = 10000;
/** Deterministic seed so a repeat run reuses the same UUID. */
const KUKA_SANDBOX_UUID_SEED = 'kuka-island-edm-sandbox-e2e-v1';
/** Host-side state directory (read-write mount). Never the database. */
const KUKA_SANDBOX_STATE_DIR = '/run/edm/state';

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
 * Fail-closed sender / recipient verification.
 *
 * Every check must be true before the driver is allowed to reach even the PLAN
 * stage. Nothing is inferred and nothing is defaulted.
 *
 * @param array<string, mixed> $facts Observed facts.
 * @return array{ok: bool, checks: array<string, bool>, failed: array<int, string>}
 */
function kuka_sandbox_verify_sender( array $facts ): array {
	$series          = (string) ( $facts['series_code'] ?? '' );
	$registered      = array_map( 'strval', (array) ( $facts['registered_serial_codes'] ?? array() ) );
	$edm_alias       = (string) ( $facts['edm_alias'] ?? '' );
	$config_alias    = (string) ( $facts['configured_alias'] ?? '' );
	$company         = (array) ( $facts['company_fields'] ?? array() );
	$receiver_vkn    = (string) ( $facts['sandbox_receiver_vkn'] ?? '' );
	$profile_id      = (string) ( $facts['profile_id'] ?? '' );
	$check_user_ok   = true === ( $facts['check_user_ok'] ?? false );

	$series_registered = false;
	if ( '' !== $series ) {
		foreach ( $registered as $code ) {
			if ( 0 === strcasecmp( trim( $code ), trim( $series ) ) ) {
				$series_registered = true;
				break;
			}
		}
	}

	$required_company = array( 'sender_vkn', 'sender_alias', 'sender_title', 'sender_tax_office', 'sender_address', 'sender_district', 'sender_city', 'sender_postcode' );
	$company_complete = true;
	foreach ( $required_company as $field ) {
		if ( '' === trim( (string) ( $company[ $field ] ?? '' ) ) ) {
			$company_complete = false;
			break;
		}
	}

	$checks = array(
		'series_configured'         => 1 === preg_match( '/^[A-Z0-9]{3}$/', $series ),
		'series_registered_at_edm'  => $series_registered,
		'check_user_ok'             => $check_user_ok,
		// Byte-for-byte match. A near-miss alias is a refusal, not a warning.
		'alias_exact_match'         => '' !== $config_alias && $edm_alias === $config_alias,
		'company_fields_complete'   => $company_complete,
		'sandbox_receiver_supplied' => 1 === preg_match( '/^\d{10,11}$/', $receiver_vkn ),
		'profile_id_supplied'       => '' !== trim( $profile_id ),
	);

	$failed = array_keys(
		array_filter(
			$checks,
			static fn( bool $passed ): bool => ! $passed
		)
	);

	return array(
		'ok'     => array() === $failed,
		'checks' => $checks,
		'failed' => $failed,
	);
}

/**
 * Strict LoadInvoiceResponse validation.
 *
 * WSDL contract: LoadInvoiceResponse = REQUEST_RETURN (tns:REQUEST_RETURNType,
 * whose RETURN_CODE is xs:int) + INVOICE* (tns:INVOICE, with UUID and ID
 * attributes).
 *
 * The assigned number is read ONLY from the top-level INVOICE entry's ID. No
 * recursive search is performed, so an unrelated nested ID can never be mistaken
 * for a fiscal document number.
 *
 * @param mixed  $response      Raw SOAP response.
 * @param string $expected_uuid Deterministic UUID that was sent.
 * @return array{ok: bool, outcome: string, return_code: int|null, assigned_number: string, returned_uuid: string, detail: string}
 */
function kuka_sandbox_parse_load_invoice_response( $response, string $expected_uuid ): array {
	$fail = static function ( string $outcome, string $detail, ?int $code = null, string $number = '', string $uuid = '' ): array {
		return array(
			'ok'              => false,
			'outcome'         => $outcome,
			'return_code'     => $code,
			'assigned_number' => $number,
			'returned_uuid'   => $uuid,
			'detail'          => $detail,
		);
	};

	if ( is_object( $response ) ) {
		$response = json_decode( (string) wp_json_encode( $response ), true );
	}
	if ( ! is_array( $response ) ) {
		return $fail( 'malformed', 'response_not_a_structure' );
	}

	if ( ! isset( $response['REQUEST_RETURN'] ) || ! is_array( $response['REQUEST_RETURN'] ) ) {
		return $fail( 'malformed', 'missing_request_return' );
	}
	$request_return = $response['REQUEST_RETURN'];
	if ( ! array_key_exists( 'RETURN_CODE', $request_return ) || ! is_numeric( $request_return['RETURN_CODE'] ) ) {
		return $fail( 'malformed', 'missing_or_non_numeric_return_code' );
	}
	$return_code = (int) $request_return['RETURN_CODE'];

	if ( 0 !== $return_code ) {
		return $fail( 'business_error', 'return_code_not_success', $return_code );
	}

	if ( ! isset( $response['INVOICE'] ) ) {
		return $fail( 'malformed', 'missing_invoice_element', $return_code );
	}
	$invoice = $response['INVOICE'];
	if ( is_array( $invoice ) && array_key_exists( 0, $invoice ) ) {
		$invoice = $invoice[0];
	}
	if ( ! is_array( $invoice ) ) {
		return $fail( 'malformed', 'invoice_element_not_a_structure', $return_code );
	}

	$returned_uuid = isset( $invoice['UUID'] ) && is_scalar( $invoice['UUID'] ) ? (string) $invoice['UUID'] : '';
	if ( '' === $returned_uuid || 0 !== strcasecmp( $returned_uuid, $expected_uuid ) ) {
		return $fail( 'uuid_mismatch', 'returned_uuid_does_not_match_sent_uuid', $return_code, '', $returned_uuid );
	}

	$assigned = isset( $invoice['ID'] ) && is_scalar( $invoice['ID'] ) ? (string) $invoice['ID'] : '';
	if ( '' === trim( $assigned ) ) {
		return $fail( 'empty_id', 'edm_returned_no_document_number', $return_code, '', $returned_uuid );
	}

	return array(
		'ok'              => true,
		'outcome'         => 'success',
		'return_code'     => $return_code,
		'assigned_number' => $assigned,
		'returned_uuid'   => $returned_uuid,
		'detail'          => 'ok',
	);
}

/**
 * Readback verdict. PASS requires every mandatory XML check to hold.
 *
 * status_query_ok is reported under its own key by the driver and is not part of
 * the XML verdict.
 *
 * @param array<string, bool> $checks Observed readback checks.
 * @return array{ok: bool, mandatory: array<string, bool>, failed: array<int, string>}
 */
function kuka_sandbox_evaluate_readback( array $checks ): array {
	$mandatory_keys = array( 'xml_retrieved', 'xml_parsed', 'uuid_match', 'payable_match', 'tax_match' );

	$mandatory = array();
	foreach ( $mandatory_keys as $key ) {
		$mandatory[ $key ] = true === ( $checks[ $key ] ?? false );
	}

	$failed = array_keys(
		array_filter(
			$mandatory,
			static fn( bool $passed ): bool => ! $passed
		)
	);

	return array(
		'ok'        => array() === $failed,
		'mandatory' => $mandatory,
		'failed'    => $failed,
	);
}

/**
 * Build the synthetic, clearly-marked TEST UBL document.
 *
 * cbc:ID is removed after building: the experiment sends NO document number so
 * that EDM's assignment behaviour can be observed. The production builder is
 * used, so its own validation still applies.
 *
 * @param array<string, string> $supplier   Supplier block (already verified complete).
 * @param string                $receiver_vkn Explicitly supplied sandbox receiver identity.
 * @param string                $profile_id EDM-confirmed profile identifier.
 * @param string                $uuid       Deterministic UUID.
 * @return array{xml: string, cbc_id_sent: bool, totals: array<string, string>}
 * @throws Kuka_Island_Core_Invoice_Permanent_Exception When a mandatory field is missing.
 */
function kuka_sandbox_build_ubl( array $supplier, string $receiver_vkn, string $profile_id, string $uuid ): array {
	$net   = KUKA_SANDBOX_NET_CENTS;
	$tax   = Kuka_Island_Core_Invoice_Order_Mapper::tax_from_taxable( $net, KUKA_SANDBOX_VAT_PERCENT );
	$gross = $net + $tax;
	$a     = static fn( int $c ): string => Kuka_Island_Core_Invoice_Order_Mapper::cents_to_amount( $c );

	$issue_date = gmdate( 'Y-m-d' );

	$data = array(
		'uuid'              => $uuid,
		// Placeholder only. Stripped from the emitted XML below, never persisted
		// and never presented as a fiscal number.
		'invoice_number'    => 'SANDBOXPLACEHOLDER',
		'series'            => '',
		'profile_id'        => $profile_id,
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
			sprintf( 'Sentetik test KDV orani: %%%d. Magazanin vergi ayarlari okunmadi ve degistirilmedi.', KUKA_SANDBOX_VAT_PERCENT ),
		),
		'supplier'          => $supplier,
		'customer'          => array(
			'first_name' => 'SANDBOX',
			'last_name'  => 'TEST ALICI',
			'company'    => '',
			'tax_number' => $receiver_vkn,
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
	$still = ( new DOMXPath( $recheck ) )->query( '/*[local-name()="Invoice"]/*[local-name()="ID"]' )->length;

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

/**
 * Lock-guarded, atomically persisted write claim.
 *
 * States: idle -> in_flight -> confirmed | failed_definitive | uncertain
 *
 * - Only one process may hold the claim: an exclusive non-blocking flock is
 *   required before any transition.
 * - A write may only be claimed from 'idle'.
 * - 'in_flight' and 'uncertain' refuse a second write unconditionally. A
 *   timeout or dropped connection settles to 'uncertain', because the call may
 *   well have succeeded at EDM, and is never retried automatically.
 * - 'uncertain' can only return to 'idle' through reset_after_reconcile(),
 *   which the driver calls solely when EDM reconciliation proved the document
 *   is absent AND the operator asked for it.
 * - Every persist is temp-file + rename, mode 600. A failed persist is reported
 *   as such and must never be presented as recorded.
 */
final class Kuka_Sandbox_Claim {
	public const S_IDLE              = 'idle';
	public const S_IN_FLIGHT         = 'in_flight';
	public const S_CONFIRMED         = 'confirmed';
	public const S_FAILED_DEFINITIVE = 'failed_definitive';
	public const S_UNCERTAIN         = 'uncertain';

	private string $state_file;
	private string $lock_file;
	/** @var resource|null */
	private $lock_handle = null;

	public function __construct( string $state_file, ?string $lock_file = null ) {
		$this->state_file = $state_file;
		$this->lock_file  = $lock_file ?? $state_file . '.lock';
	}

	/**
	 * Acquire the exclusive non-blocking lock. False means another run holds it.
	 */
	public function acquire(): bool {
		if ( null !== $this->lock_handle ) {
			return true;
		}
		$dir = dirname( $this->lock_file );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $this->lock_file, 'c' );
		if ( false === $handle ) {
			return false;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
			return false;
		}
		@chmod( $this->lock_file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$this->lock_handle = $handle;

		return true;
	}

	public function release(): void {
		if ( null === $this->lock_handle ) {
			return;
		}
		flock( $this->lock_handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $this->lock_handle );
		$this->lock_handle = null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function read(): array {
		if ( ! is_readable( $this->state_file ) ) {
			return array();
		}
		$raw = file_get_contents( $this->state_file );
		if ( false === $raw ) {
			return array();
		}
		$decoded = json_decode( (string) $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	public function state(): string {
		$state = (string) ( $this->read()['state'] ?? self::S_IDLE );

		return in_array( $state, array( self::S_IDLE, self::S_IN_FLIGHT, self::S_CONFIRMED, self::S_FAILED_DEFINITIVE, self::S_UNCERTAIN ), true )
			? $state
			: self::S_IDLE;
	}

	/**
	 * Atomic, mode-600 persist. False on any failure.
	 *
	 * @param array<string, mixed> $state State payload.
	 */
	private function persist( array $state ): bool {
		$dir = dirname( $this->state_file );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}
		$tmp = $this->state_file . '.tmp.' . getmypid();
		if ( false === file_put_contents( $tmp, (string) wp_json_encode( $state, JSON_PRETTY_PRINT ) ) ) {
			return false;
		}
		if ( ! chmod( $tmp, 0600 ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		if ( ! rename( $tmp, $this->state_file ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		@chmod( $this->state_file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return true;
	}

	/**
	 * idle -> in_flight. Refused from every other state.
	 *
	 * @return array{ok: bool, reason: string, written: bool, state: string}
	 */
	public function claim( string $uuid, string $operation ): array {
		if ( null === $this->lock_handle ) {
			return array(
				'ok'      => false,
				'reason'  => 'lock_not_held',
				'written' => false,
				'state'   => $this->state(),
			);
		}

		$current = $this->state();
		if ( self::S_IDLE !== $current ) {
			return array(
				'ok'      => false,
				'reason'  => 'claim_refused_from_state_' . $current,
				'written' => false,
				'state'   => $current,
			);
		}

		$previous = $this->read();
		$history  = (array) ( $previous['history'] ?? array() );
		$history[] = array(
			'at_utc' => gmdate( 'c' ),
			'to'     => self::S_IN_FLIGHT,
		);

		$written = $this->persist(
			array(
				'state'          => self::S_IN_FLIGHT,
				'uuid'           => $uuid,
				'operation'      => $operation,
				'claimed_at_utc' => gmdate( 'c' ),
				'updated_at_utc' => gmdate( 'c' ),
				'history'        => array_slice( $history, -20 ),
			)
		);

		return array(
			'ok'      => $written,
			'reason'  => $written ? 'claimed' : 'state_persist_failed',
			'written' => $written,
			'state'   => $written ? self::S_IN_FLIGHT : $current,
		);
	}

	/**
	 * in_flight -> confirmed | failed_definitive | uncertain.
	 *
	 * @param string               $target Target state.
	 * @param array<string, mixed> $extra Extra fields to record.
	 * @return array{ok: bool, reason: string, written: bool, state: string}
	 */
	public function settle( string $target, array $extra = array() ): array {
		$allowed = array( self::S_CONFIRMED, self::S_FAILED_DEFINITIVE, self::S_UNCERTAIN );
		if ( ! in_array( $target, $allowed, true ) ) {
			return array(
				'ok'      => false,
				'reason'  => 'invalid_target_state',
				'written' => false,
				'state'   => $this->state(),
			);
		}
		if ( null === $this->lock_handle ) {
			return array(
				'ok'      => false,
				'reason'  => 'lock_not_held',
				'written' => false,
				'state'   => $this->state(),
			);
		}
		$current = $this->state();
		if ( self::S_IN_FLIGHT !== $current ) {
			return array(
				'ok'      => false,
				'reason'  => 'settle_refused_from_state_' . $current,
				'written' => false,
				'state'   => $current,
			);
		}

		$state     = $this->read();
		$history   = (array) ( $state['history'] ?? array() );
		$history[] = array(
			'at_utc' => gmdate( 'c' ),
			'to'     => $target,
		);

		$payload                   = array_merge( $state, $extra );
		$payload['state']          = $target;
		$payload['updated_at_utc'] = gmdate( 'c' );
		$payload['history']        = array_slice( $history, -20 );

		$written = $this->persist( $payload );

		return array(
			'ok'      => $written,
			'reason'  => $written ? 'settled' : 'state_persist_failed',
			'written' => $written,
			'state'   => $written ? $target : $current,
		);
	}

	/**
	 * uncertain -> idle, only with explicit reconciliation evidence.
	 *
	 * @return array{ok: bool, reason: string, written: bool, state: string}
	 */
	public function reset_after_reconcile( string $evidence ): array {
		if ( null === $this->lock_handle ) {
			return array(
				'ok'      => false,
				'reason'  => 'lock_not_held',
				'written' => false,
				'state'   => $this->state(),
			);
		}
		$current = $this->state();
		if ( self::S_UNCERTAIN !== $current ) {
			return array(
				'ok'      => false,
				'reason'  => 'reset_refused_from_state_' . $current,
				'written' => false,
				'state'   => $current,
			);
		}
		if ( 'document_absent_at_edm' !== $evidence ) {
			return array(
				'ok'      => false,
				'reason'  => 'reset_requires_document_absent_evidence',
				'written' => false,
				'state'   => $current,
			);
		}

		$state     = $this->read();
		$history   = (array) ( $state['history'] ?? array() );
		$history[] = array(
			'at_utc'   => gmdate( 'c' ),
			'to'       => self::S_IDLE,
			'evidence' => $evidence,
		);

		$written = $this->persist(
			array(
				'state'          => self::S_IDLE,
				'uuid'           => (string) ( $state['uuid'] ?? '' ),
				'updated_at_utc' => gmdate( 'c' ),
				'history'        => array_slice( $history, -20 ),
			)
		);

		return array(
			'ok'      => $written,
			'reason'  => $written ? 'reset_to_idle' : 'state_persist_failed',
			'written' => $written,
			'state'   => $written ? self::S_IDLE : $current,
		);
	}
}
