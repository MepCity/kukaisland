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
/**
 * EDM's documented portal-serial placeholder for UBL cbc:ID.
 *
 * With GENERATEINVOICEIDONLOAD = true the document number is assigned by EDM,
 * but the UBL still has to carry a syntactically valid cbc:ID. This is the
 * placeholder EDM's own portal uses; it is NOT a fiscal invoice number and is
 * never persisted or presented as one.
 */
const KUKA_SANDBOX_UBL_ID_PLACEHOLDER = 'ABC2009123456789';

/** Deterministic seed so a repeat run reuses the same UUID. */
const KUKA_SANDBOX_UUID_SEED = 'kuka-island-edm-sandbox-e2e-v1';
/** Host-side state directory (read-write mount). Never the database. */
const KUKA_SANDBOX_STATE_DIR = '/run/edm/state';

/** EDM environment token the sandbox gates accept. */
const KUKA_SANDBOX_TEST_ENVIRONMENT = 'test';

/**
 * Values taken from EDM's published e-Arşiv SOAP example.
 *
 * EDM's own e-Fatura SOAP envelope reference prints a complete e-Arşiv envelope
 * whose header carries PROFILEID = EARSIVFATURA and whose recipient is the
 * example individual identifier 11111111111:
 * https://docs.edmbilisim.com.tr/api/api-documentation/einvoice/efatura-soap-envelopes.html
 *
 * They are used ONLY as the fixture identity of this isolated sandbox
 * experiment. Nothing here claims EDM assigned either value to our test
 * account: they are the identifiers EDM's public example uses, nothing more,
 * and the recipient in particular is an example consumer identity rather than a
 * provisioned test counterparty.
 *
 * They stay sandbox-only. kuka_sandbox_resolve_defaults() releases them only
 * after the real WSDL endpoint has been proved to be EDM's test service, and no
 * plugin file includes this library, so they cannot reach a WooCommerce order
 * mapping or the production config.
 */
const KUKA_SANDBOX_DOCUMENTED_PROFILE_ID   = 'EARSIVFATURA';
const KUKA_SANDBOX_DOCUMENTED_RECEIVER_VKN = '11111111111';

/** The one WSDL host the sandbox may ever talk to. */
const KUKA_SANDBOX_TEST_WSDL_HOST = 'test.edmbilisim.com.tr';
/** The one WSDL service path the sandbox may ever talk to. */
const KUKA_SANDBOX_TEST_WSDL_PATH = '/EFaturaEDM21ea/EFaturaEDM.svc';

/** Outcome classes for a write attempt. */
const KUKA_SANDBOX_CALL_SUCCESS    = 'success';
const KUKA_SANDBOX_CALL_DEFINITIVE = 'definitive_rejection';
const KUKA_SANDBOX_CALL_UNCERTAIN  = 'uncertain';

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
 * Prove that the configured WSDL really is EDM's test service.
 *
 * The environment token is a label the operator sets; KUKA_EDM_WSDL overrides
 * the URL independently of it, so a config can read "test" while pointing at
 * production. This function looks at the real Kuka_Island_Core_Invoice_Config
 * ::get_wsdl() value and accepts exactly one endpoint:
 *
 *     https://test.edmbilisim.com.tr/EFaturaEDM21ea/EFaturaEDM.svc[?singleWsdl]
 *
 * Everything else is refused, including a live URL, a look-alike host
 * (test.edmbilisim.com.tr.evil.example), a host that only contains the real one
 * in its path, plain HTTP, an IP literal, a different service path, embedded
 * user information, any explicit port, any fragment and any malformed string.
 * An explicit port is refused even when it is 443: the canonical endpoint has
 * none, and accepting one only widens the parsing surface.
 *
 * The value is checked as the exact byte string the config holds. It is never
 * trimmed or normalised first: a leading or trailing space, tab, newline, CR or
 * control byte is a refusal, not something to clean up. Silently repairing the
 * input would mean the string that passes validation is not the string a SOAP
 * client would be handed.
 *
 * The URL is never echoed. A custom WSDL can carry user information, so only
 * the reason token leaves this function.
 *
 * @param string $wsdl Value of Kuka_Island_Core_Invoice_Config::get_wsdl().
 * @return array{ok: bool, reason: string, scheme_ok: bool, host_ok: bool, path_ok: bool, query_ok: bool}
 */
function kuka_sandbox_verify_test_endpoint( string $wsdl ): array {
	$deny = static function ( string $reason, bool $scheme = false, bool $host = false, bool $path = false, bool $query = false ): array {
		return array(
			'ok'        => false,
			'reason'    => $reason,
			'scheme_ok' => $scheme,
			'host_ok'   => $host,
			'path_ok'   => $path,
			'query_ok'  => $query,
		);
	};

	// Deliberately NOT trimmed: the raw bytes are what gets validated.
	$raw = $wsdl;

	if ( '' === $raw ) {
		return $deny( 'wsdl_empty' );
	}
	if ( strlen( $raw ) > 512 ) {
		return $deny( 'wsdl_too_long' );
	}
	// Whitespace or control characters ANYWHERE -- leading and trailing
	// included -- mean the string was never a plain URL.
	if ( 1 === preg_match( '/[\x00-\x20\x7F]/', $raw ) ) {
		return $deny( 'wsdl_contains_whitespace_or_control' );
	}
	// Some parsers treat a backslash as a separator; refuse it outright.
	if ( str_contains( $raw, '\\' ) ) {
		return $deny( 'wsdl_contains_backslash' );
	}
	if ( str_contains( $raw, '#' ) ) {
		return $deny( 'wsdl_contains_fragment' );
	}
	// Checked on the raw string as well, so a parser quirk cannot hide it.
	if ( str_contains( $raw, '@' ) ) {
		return $deny( 'wsdl_contains_userinfo' );
	}

	$parts = wp_parse_url( $raw );
	if ( ! is_array( $parts ) || array() === $parts ) {
		return $deny( 'wsdl_malformed' );
	}
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return $deny( 'wsdl_contains_userinfo' );
	}
	if ( isset( $parts['fragment'] ) ) {
		return $deny( 'wsdl_contains_fragment' );
	}
	if ( isset( $parts['port'] ) ) {
		return $deny( 'wsdl_explicit_port_refused' );
	}

	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	if ( 'https' !== $scheme ) {
		return $deny( 'wsdl_scheme_not_https' );
	}

	// DNS is case-insensitive, so the comparison is; it is still exact, so a
	// trailing dot, a prefix, a suffix or a subdomain never matches.
	$host = strtolower( (string) ( $parts['host'] ?? '' ) );
	if ( KUKA_SANDBOX_TEST_WSDL_HOST !== $host ) {
		return $deny( 'wsdl_host_not_edm_test', true );
	}

	$path = (string) ( $parts['path'] ?? '' );
	if ( KUKA_SANDBOX_TEST_WSDL_PATH !== $path ) {
		return $deny( 'wsdl_path_not_test_service', true, true );
	}

	$query = (string) ( $parts['query'] ?? '' );
	if ( '' !== $query && 'singleWsdl' !== $query ) {
		return $deny( 'wsdl_query_not_allowed', true, true, true );
	}

	return array(
		'ok'        => true,
		'reason'    => 'edm_test_service_verified',
		'scheme_ok' => true,
		'host_ok'   => true,
		'path_ok'   => true,
		'query_ok'  => true,
	);
}

/**
 * Resolve the sandbox PROFILEID and recipient identity.
 *
 * Two independent facts are required and the environment token alone is never
 * enough: the config must say "test" AND kuka_sandbox_verify_test_endpoint()
 * must already have proved that the real get_wsdl() value is EDM's test
 * service. The endpoint verdict is passed in as evidence rather than recomputed
 * here, so this function cannot claim "test endpoint" on the strength of a
 * label. If either fact is missing nothing is resolved, override or not.
 *
 * An override is accepted only after a format and safety check; a bad override
 * fails closed instead of silently falling back to the example value.
 *
 * @param string               $environment       Resolved config environment.
 * @param array<string, mixed> $endpoint          Verdict of kuka_sandbox_verify_test_endpoint().
 * @param string               $profile_override  KUKA_EDM_SANDBOX_PROFILE_ID, may be empty.
 * @param string               $receiver_override KUKA_EDM_SANDBOX_RECEIVER_VKN, may be empty.
 * @param string               $sender_vkn        Configured sender identity.
 * @return array{ok: bool, profile_id: string, receiver_vkn: string, profile_source: string, receiver_source: string, failed: array<int, string>, reason: string}
 */
function kuka_sandbox_resolve_defaults( string $environment, array $endpoint, string $profile_override, string $receiver_override, string $sender_vkn = '' ): array {
	$blocked = static function ( array $failed, string $reason ): array {
		return array(
			'ok'              => false,
			'profile_id'      => '',
			'receiver_vkn'    => '',
			'profile_source'  => 'none',
			'receiver_source' => 'none',
			'failed'          => $failed,
			'reason'          => $reason,
		);
	};

	$environment_ok = KUKA_SANDBOX_TEST_ENVIRONMENT === strtolower( trim( $environment ) );
	$endpoint_ok    = true === ( $endpoint['ok'] ?? false );

	if ( ! $environment_ok || ! $endpoint_ok ) {
		$why = array();
		if ( ! $environment_ok ) {
			$why[] = 'environment_not_test';
		}
		if ( ! $endpoint_ok ) {
			$why[] = 'wsdl_endpoint_not_verified';
		}

		return $blocked( $why, 'sandbox_values_refused_without_verified_test_endpoint' );
	}

	$failed = array();

	$profile        = trim( $profile_override );
	$profile_source = 'documented_example_fixture';
	if ( '' !== $profile ) {
		$profile_source = 'operator_override';
		if ( 1 !== preg_match( '/^[A-Z][A-Z0-9_]{3,31}$/', $profile ) ) {
			$failed[] = 'profile_override_invalid_format';
		}
	} else {
		$profile = KUKA_SANDBOX_DOCUMENTED_PROFILE_ID;
	}

	$receiver        = trim( $receiver_override );
	$receiver_source = 'documented_example_fixture';
	if ( '' !== $receiver ) {
		$receiver_source = 'operator_override';
		if ( 1 !== preg_match( '/^\d{10,11}$/', $receiver ) ) {
			$failed[] = 'receiver_override_invalid_format';
		} elseif ( '' !== trim( $sender_vkn ) && trim( $sender_vkn ) === $receiver ) {
			// Addressing the sender's own identity is never a valid experiment.
			$failed[] = 'receiver_override_equals_sender';
		} elseif ( 1 === preg_match( '/^0+$/', $receiver ) ) {
			$failed[] = 'receiver_override_not_an_identity';
		}
	} else {
		$receiver = KUKA_SANDBOX_DOCUMENTED_RECEIVER_VKN;
	}

	if ( array() !== $failed ) {
		return $blocked( $failed, 'override_rejected' );
	}

	return array(
		'ok'              => true,
		'profile_id'      => $profile,
		'receiver_vkn'    => $receiver,
		'profile_source'  => $profile_source,
		'receiver_source' => $receiver_source,
		'failed'          => array(),
		'reason'          => 'resolved',
	);
}

/**
 * Decide whether the LoadInvoice request may bind a registered serial.
 *
 * The serial is OPTIONAL. LoadInvoice always carries
 * GENERATEINVOICEIDONLOAD = true, so with no serial configured EDM assigns the
 * document number from its own system serial and the experiment still runs.
 * A configured serial is bound only when its registration at EDM was actually
 * observed. If GetInvoiceSerial could not be read, the registration is
 * unverified, so the serial is NOT sent and the run blocks: sending a serial
 * whose registration nobody could confirm is exactly the guess this harness
 * refuses to make. A malformed or provably unregistered override blocks the
 * same way rather than being dropped silently.
 *
 * A serial that was never configured is a different case: nothing is asserted
 * about it, EDM picks its own system serial, and the run proceeds even when
 * GetInvoiceSerial is unreadable.
 *
 * @param string            $configured Configured e-Archive series code.
 * @param array<int, mixed> $registered Codes GetInvoiceSerial returned.
 * @param bool              $query_ok   Whether GetInvoiceSerial succeeded.
 * @return array{ok: bool, send: bool, code: string, reason: string, registered: bool}
 */
function kuka_sandbox_resolve_series( string $configured, array $registered, bool $query_ok ): array {
	$code = trim( $configured );

	if ( '' === $code ) {
		return array(
			'ok'         => true,
			'send'       => false,
			'code'       => '',
			'reason'     => 'not_configured_edm_assigns_the_number',
			'registered' => false,
		);
	}

	if ( 1 !== preg_match( '/^[A-Z0-9]{3}$/', $code ) ) {
		return array(
			'ok'         => false,
			'send'       => false,
			'code'       => '',
			'reason'     => 'series_override_invalid_format',
			'registered' => false,
		);
	}

	$is_registered = false;
	foreach ( $registered as $known ) {
		if ( 0 === strcasecmp( trim( (string) $known ), $code ) ) {
			$is_registered = true;
			break;
		}
	}

	if ( ! $query_ok ) {
		// Registration could not be observed, so it is not asserted.
		return array(
			'ok'         => false,
			'send'       => false,
			'code'       => '',
			'reason'     => 'series_override_registration_unverified',
			'registered' => false,
		);
	}

	if ( ! $is_registered ) {
		return array(
			'ok'         => false,
			'send'       => false,
			'code'       => '',
			'reason'     => 'series_override_not_registered_at_edm',
			'registered' => false,
		);
	}

	return array(
		'ok'         => true,
		'send'       => true,
		'code'       => $code,
		'reason'     => 'series_override_registered_at_edm',
		'registered' => true,
	);
}

/**
 * Sender fields the sandbox compares against, verified in the EDM test portal.
 *
 * Read from Tanımlar -> Firmalarım -> Görüntüle/Güncelle on the EDM TEST
 * account and hard-coded here on purpose. The whole point is that this is an
 * INDEPENDENT reference: deriving it from Kuka_Island_Core_Invoice_Config or
 * from the credential file would compare a value with itself and prove nothing.
 * Nothing may be passed in and nothing may be read from the environment.
 *
 * These are not secrets, but they are still test-account values: they are never
 * written to a WordPress option, to the database or to any production runtime
 * path, and kuka_sandbox_sender_fixture_for() refuses to release them outside a
 * proved EDM test endpoint.
 *
 * The portal has no postcode field, so none is listed. See
 * kuka_sandbox_verify_sender().
 *
 * @return array<string, string>
 */
function kuka_sandbox_expected_sender_fixture(): array {
	return array(
		'sender_vkn'        => '3230512384',
		'sender_alias'      => 'urn:mail:defaultgb@edmbilisim.com.tr',
		'sender_title'      => '2018 - EDM BİLİŞİM SİSTEMLERİ VE DANIŞMANLIK HİZMETLERİ ANONİM ŞİRKETİ',
		'sender_tax_office' => 'SELÇUK',
		'sender_address'    => 'BOMONTİ BUSİNESS CENTER',
		'sender_district'   => 'Esenler',
		'sender_city'       => 'İstanbul',
	);
}

/**
 * Release the portal fixture only for a proved EDM test endpoint.
 *
 * Both facts are required, exactly as in kuka_sandbox_resolve_defaults(): the
 * environment label alone never unlocks it, and neither does a verified
 * endpoint under a live label. A live configuration therefore has no path to
 * these values at all -- it receives the empty array and its sender
 * verification falls back on CheckUser, which is the only authority there.
 *
 * @param array<string, mixed> $endpoint    Verdict of kuka_sandbox_verify_test_endpoint().
 * @param string               $environment Resolved config environment.
 * @return array<string, string> Empty when the endpoint is not the proved test service.
 */
function kuka_sandbox_sender_fixture_for( array $endpoint, string $environment ): array {
	if ( true !== ( $endpoint['ok'] ?? false ) ) {
		return array();
	}
	if ( KUKA_SANDBOX_TEST_ENVIRONMENT !== strtolower( trim( $environment ) ) ) {
		return array();
	}

	return kuka_sandbox_expected_sender_fixture();
}

/**
 * Compare the configured sender block against the portal fixture.
 *
 * Byte-for-byte on every field the portal publishes. Only FIELD NAMES are
 * returned for a mismatch: the configured value is what is under suspicion, so
 * echoing it would defeat the check being reported.
 *
 * @param array<string, string> $configured Configured sender fields.
 * @param array<string, string> $fixture    Portal fixture, possibly empty.
 * @return array{ok: bool, available: bool, mismatched: array<int, string>}
 */
function kuka_sandbox_match_sender_fixture( array $configured, array $fixture ): array {
	if ( array() === $fixture ) {
		return array(
			'ok'         => false,
			'available'  => false,
			'mismatched' => array(),
		);
	}

	$mismatched = array();
	foreach ( $fixture as $field => $expected ) {
		if ( (string) ( $configured[ $field ] ?? '' ) !== (string) $expected ) {
			$mismatched[] = $field;
		}
	}

	return array(
		'ok'         => array() === $mismatched,
		'available'  => true,
		'mismatched' => $mismatched,
	);
}

/**
 * Fail-closed sender / recipient verification, split by document profile.
 *
 * CheckUser is a GİB e-Invoice REGISTRY lookup, not a source of the sender's
 * own mailbox. EDM's own connector library names the operation
 * "Vergi Kimlik No ile e-fatura mükellefi arama"
 * (EFaturaEDMConnectorLibrary.cs, CheckUser_byIdentifier). A taxpayer that
 * issues e-Archive documents has no reason to appear in that registry, so
 * requiring a USER entry -- and deriving the sender alias from it -- was simply
 * the wrong authority for an e-Archive sender. Measured on the EDM test
 * account: CheckUser succeeds and returns an empty USER list.
 *
 * The split:
 *
 *   EARSIVFATURA        the sender block is proved against the independent
 *                       portal fixture. An empty CheckUser result is expected
 *                       and is reported as information, never as a failure.
 *   e-Invoice profiles  unchanged and NOT relaxed: a USER entry is required and
 *                       the alias EDM returns must match the configured one
 *                       byte-for-byte.
 *
 * The postcode is the one company field that is NOT required. All sixteen
 * sample invoices in EDM's XML ÖRNEKLERİ package omit the supplier
 * cbc:PostalZone, and the test portal exposes no postcode field.
 *
 * The serial is NOT blocking. LoadInvoice runs with GENERATEINVOICEIDONLOAD =
 * true, so an absent serial only means EDM assigns the number itself; a
 * supplied-but-unusable serial still blocks, via series_selection_valid.
 *
 * @param array<string, mixed> $facts Observed facts.
 * @return array{ok: bool, profile: string, checks: array<string, bool>, info: array<string, string>, failed: array<int, string>, missing_company_fields: array<int, string>, mismatched_fixture_fields: array<int, string>}
 */
function kuka_sandbox_verify_sender( array $facts ): array {
	$edm_alias     = (string) ( $facts['edm_alias'] ?? '' );
	$config_alias  = (string) ( $facts['configured_alias'] ?? '' );
	$company       = (array) ( $facts['company_fields'] ?? array() );
	$check_user_ok = true === ( $facts['check_user_ok'] ?? false );
	$defaults      = (array) ( $facts['defaults'] ?? array() );
	$series        = (array) ( $facts['series'] ?? array() );
	$fixture       = (array) ( $facts['sender_fixture'] ?? array() );

	$required_company = array( 'sender_vkn', 'sender_alias', 'sender_title', 'sender_tax_office', 'sender_address', 'sender_district', 'sender_city' );
	$missing_company  = array();
	foreach ( $required_company as $field ) {
		if ( '' === trim( (string) ( $company[ $field ] ?? '' ) ) ) {
			$missing_company[] = $field;
		}
	}

	$defaults_ok = true === ( $defaults['ok'] ?? false );
	$profile     = $defaults_ok ? (string) ( $defaults['profile_id'] ?? '' ) : '';
	$is_earchive = KUKA_SANDBOX_DOCUMENTED_PROFILE_ID === $profile;

	$fixture_match = kuka_sandbox_match_sender_fixture( $company, $fixture );

	$checks = array(
		'company_fields_complete'    => array() === $missing_company,
		'profile_id_resolved'        => $defaults_ok && '' !== $profile,
		'receiver_identity_resolved' => $defaults_ok && 1 === preg_match( '/^\d{10,11}$/', (string) ( $defaults['receiver_vkn'] ?? '' ) ),
		'series_selection_valid'     => true === ( $series['ok'] ?? false ),
	);

	if ( $is_earchive ) {
		// The fixture is the authority here. It is released only for a proved
		// test endpoint, so an unavailable fixture is itself a refusal.
		$checks['sender_matches_portal_fixture'] = $fixture_match['available'] && $fixture_match['ok'];
	} else {
		// e-Invoice: the GİB registry is the authority and stays mandatory.
		$checks['check_user_ok']     = $check_user_ok;
		$checks['alias_exact_match'] = '' !== $config_alias && $edm_alias === $config_alias;
	}

	$failed = array_keys(
		array_filter(
			$checks,
			static fn( bool $passed ): bool => ! $passed
		)
	);

	// Reported, never blocking. Labels and field names only, never values.
	$info = array(
		'sender_identity_source' => $is_earchive ? 'portal_verified_test_fixture' : 'edm_checkuser_registry_alias',
		'check_user_role'        => 'einvoice_registry_lookup',
		'check_user_requirement' => $is_earchive ? 'not_applicable_for_earchive_sender' : 'required_for_einvoice_sender',
		'check_user_result'      => $check_user_ok ? 'user_entry_present' : 'user_entry_absent',
		'fixture_available'      => $fixture_match['available'] ? 'yes' : 'no',
		'profile_source'         => (string) ( $defaults['profile_source'] ?? 'none' ),
		'receiver_source'        => (string) ( $defaults['receiver_source'] ?? 'none' ),
		'series_mode'            => (string) ( $series['reason'] ?? 'unknown' ),
		'series_sent'            => true === ( $series['send'] ?? false ) ? 'yes' : 'no',
	);

	return array(
		'ok'                        => array() === $failed,
		'profile'                   => $is_earchive ? 'earchive' : 'einvoice',
		'checks'                    => $checks,
		'info'                      => $info,
		'failed'                    => $failed,
		'missing_company_fields'    => $missing_company,
		'mismatched_fixture_fields' => $fixture_match['mismatched'],
	);
}

/**
 * Turn a failed verification into an accurate operator instruction.
 *
 * The old text always said "every listed sender field must come from the EDM
 * portal" even when every field was present and the real problem was something
 * else entirely. Each sentence below is produced only by the check that
 * actually failed, and none of them carries a value -- only field names.
 *
 * @param array<string, mixed> $verification Output of kuka_sandbox_verify_sender().
 * @return array<int, string>
 */
function kuka_sandbox_sender_guidance( array $verification ): array {
	if ( true === ( $verification['ok'] ?? false ) ) {
		return array();
	}

	$failed   = (array) ( $verification['failed'] ?? array() );
	$lines    = array();
	$earchive = 'earchive' === (string) ( $verification['profile'] ?? '' );

	if ( in_array( 'company_fields_complete', $failed, true ) ) {
		$lines[] = sprintf(
			'Missing sender field(s), read them from the EDM portal (Tanımlar -> Firmalarım): %s',
			implode( ', ', (array) $verification['missing_company_fields'] )
		);
	}

	if ( in_array( 'sender_matches_portal_fixture', $failed, true ) ) {
		$mismatched = (array) ( $verification['mismatched_fixture_fields'] ?? array() );
		$lines[]    = array() === $mismatched
			? 'The portal sender fixture is unavailable: it is released only for the proved EDM test endpoint.'
			: sprintf(
				'Configured sender field(s) do not match the EDM test portal record (field names only): %s',
				implode( ', ', $mismatched )
			);
	}

	if ( in_array( 'check_user_ok', $failed, true ) ) {
		$lines[] = 'CheckUser returned no GİB e-Invoice registry entry for this sender. That is an e-Invoice registration matter at GİB/EDM, not a configuration error here.';
	}

	if ( in_array( 'alias_exact_match', $failed, true ) ) {
		$lines[] = 'The mailbox alias EDM returned does not match the configured one byte-for-byte.';
	}

	if ( in_array( 'series_selection_valid', $failed, true ) ) {
		$lines[] = 'The configured invoice series cannot be used; leave it empty to let EDM assign the number.';
	}

	if ( in_array( 'profile_id_resolved', $failed, true ) || in_array( 'receiver_identity_resolved', $failed, true ) ) {
		$lines[] = 'The sandbox profile or recipient identity could not be resolved for a proved EDM test endpoint.';
	}

	if ( $earchive ) {
		$lines[] = 'Note: an empty CheckUser result is expected for an e-Archive sender and is not a failure.';
	}

	return $lines;
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
 * Classification is deliberately asymmetric. Only a structurally complete
 * rejection -- REQUEST_RETURN present, RETURN_CODE numeric and non-zero -- is
 * treated as a definitive refusal. Every other non-success shape means the call
 * was made and a document may well exist at EDM, so it classifies as
 * 'uncertain' and must never trigger an automatic second write.
 *
 * @param mixed  $response      Raw SOAP response.
 * @param string $expected_uuid Deterministic UUID that was sent.
 * @return array{ok: bool, outcome: string, classification: string, return_code: int|null, assigned_number: string, returned_uuid: string, detail: string}
 */
function kuka_sandbox_parse_load_invoice_response( $response, string $expected_uuid ): array {
	$fail = static function ( string $outcome, string $classification, string $detail, ?int $code = null, string $number = '', string $uuid = '' ): array {
		return array(
			'ok'              => false,
			'outcome'         => $outcome,
			'classification'  => $classification,
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
		return $fail( 'malformed', KUKA_SANDBOX_CALL_UNCERTAIN, 'response_not_a_structure' );
	}

	if ( ! isset( $response['REQUEST_RETURN'] ) || ! is_array( $response['REQUEST_RETURN'] ) ) {
		return $fail( 'malformed', KUKA_SANDBOX_CALL_UNCERTAIN, 'missing_request_return' );
	}
	$request_return = $response['REQUEST_RETURN'];
	if ( ! array_key_exists( 'RETURN_CODE', $request_return ) || ! is_numeric( $request_return['RETURN_CODE'] ) ) {
		return $fail( 'malformed', KUKA_SANDBOX_CALL_UNCERTAIN, 'missing_or_non_numeric_return_code' );
	}
	$return_code = (int) $request_return['RETURN_CODE'];

	if ( 0 !== $return_code ) {
		return $fail( 'business_error', KUKA_SANDBOX_CALL_DEFINITIVE, 'return_code_not_success', $return_code );
	}

	if ( ! isset( $response['INVOICE'] ) ) {
		return $fail( 'malformed', KUKA_SANDBOX_CALL_UNCERTAIN, 'missing_invoice_element', $return_code );
	}
	$invoice = $response['INVOICE'];
	if ( is_array( $invoice ) && array_key_exists( 0, $invoice ) ) {
		$invoice = $invoice[0];
	}
	if ( ! is_array( $invoice ) ) {
		return $fail( 'malformed', KUKA_SANDBOX_CALL_UNCERTAIN, 'invoice_element_not_a_structure', $return_code );
	}

	$returned_uuid = isset( $invoice['UUID'] ) && is_scalar( $invoice['UUID'] ) ? (string) $invoice['UUID'] : '';
	if ( '' === $returned_uuid || 0 !== strcasecmp( $returned_uuid, $expected_uuid ) ) {
		return $fail( 'uuid_mismatch', KUKA_SANDBOX_CALL_UNCERTAIN, 'returned_uuid_does_not_match_sent_uuid', $return_code, '', $returned_uuid );
	}

	$assigned = isset( $invoice['ID'] ) && is_scalar( $invoice['ID'] ) ? (string) $invoice['ID'] : '';
	if ( '' === trim( $assigned ) ) {
		return $fail( 'empty_id', KUKA_SANDBOX_CALL_UNCERTAIN, 'edm_returned_no_document_number', $return_code, '', $returned_uuid );
	}

	return array(
		'ok'              => true,
		'outcome'         => 'success',
		'classification'  => KUKA_SANDBOX_CALL_SUCCESS,
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
 * The UBL carries EDM's documented portal-serial placeholder in cbc:ID
 * (KUKA_SANDBOX_UBL_ID_PLACEHOLDER). It is not a fiscal number: with
 * GENERATEINVOICEIDONLOAD = true EDM assigns the real one, and the SOAP-side
 * INVOICE/@ID is still omitted so that assignment can be observed. Stripping
 * cbc:ID out of the document, as this function used to do, produced UBL that
 * does not satisfy the schema. The production builder is used, so its own
 * validation still applies.
 *
 * @param array<string, string> $supplier   Supplier block (already verified complete).
 * @param string                $receiver_vkn Explicitly supplied sandbox receiver identity.
 * @param string                $profile_id EDM-confirmed profile identifier.
 * @param string                $uuid       Deterministic UUID.
 * @return array{xml: string, cbc_id: string, cbc_id_count: int, totals: array<string, string>}
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
		// EDM's documented portal-serial placeholder. Never persisted and never
		// presented as a fiscal number: EDM assigns the real one on load.
		'invoice_number'    => KUKA_SANDBOX_UBL_ID_PLACEHOLDER,
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

	// Read back what was actually emitted rather than trusting the input: the
	// placeholder has to be present exactly once for the document to be valid.
	$dom = new DOMDocument();
	$dom->loadXML( $xml );
	$id_nodes = ( new DOMXPath( $dom ) )->query( '/*[local-name()="Invoice"]/*[local-name()="ID"]' );
	$id_count = ( false !== $id_nodes ) ? $id_nodes->length : 0;
	$id_value = ( $id_count > 0 ) ? trim( (string) $id_nodes->item( 0 )->nodeValue ) : '';

	return array(
		'xml'          => $xml,
		'cbc_id'       => $id_value,
		'cbc_id_count' => $id_count,
		'totals'       => array(
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
	/** Not a lifecycle state: the persisted record cannot be trusted. */
	public const S_CORRUPT           = 'corrupt';

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
	 * Raw record, or an empty array when there is nothing usable to read.
	 *
	 * Callers must consult status() for the trustworthiness of that record;
	 * read() alone cannot distinguish "no file" from "damaged file".
	 *
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

	/**
	 * Trustworthiness of the persisted claim record.
	 *
	 * A missing file is genuinely idle: nothing was ever claimed. Anything else
	 * that cannot be read back as a complete, self-consistent record is CORRUPT
	 * and can never be claimed, because a damaged record may be hiding an
	 * in-flight or confirmed document at EDM. Recovery is a read-only
	 * reconciliation by UUID at EDM, never deleting or resetting this file.
	 *
	 * @return array{state: string, reason: string, record: array<string, mixed>}
	 */
	public function status(): array {
		$known = array( self::S_IDLE, self::S_IN_FLIGHT, self::S_CONFIRMED, self::S_FAILED_DEFINITIVE, self::S_UNCERTAIN );

		$verdict = static function ( string $state, string $reason, array $record = array() ): array {
			return array(
				'state'  => $state,
				'reason' => $reason,
				'record' => $record,
			);
		};

		if ( ! file_exists( $this->state_file ) ) {
			return $verdict( self::S_IDLE, 'no_state_file' );
		}
		if ( ! is_readable( $this->state_file ) ) {
			return $verdict( self::S_CORRUPT, 'state_file_unreadable' );
		}

		$raw = file_get_contents( $this->state_file );
		if ( false === $raw ) {
			return $verdict( self::S_CORRUPT, 'state_file_unreadable' );
		}
		if ( '' === trim( (string) $raw ) ) {
			return $verdict( self::S_CORRUPT, 'state_file_empty' );
		}

		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			return $verdict( self::S_CORRUPT, 'state_file_invalid_json' );
		}
		if ( ! isset( $decoded['state'] ) || ! is_string( $decoded['state'] ) ) {
			return $verdict( self::S_CORRUPT, 'state_field_missing', $decoded );
		}

		$state = $decoded['state'];
		if ( ! in_array( $state, $known, true ) ) {
			return $verdict( self::S_CORRUPT, 'unknown_state_value', $decoded );
		}

		// Any non-idle state must carry the identifiers that make reconciliation
		// possible. Without them the record cannot be acted on safely.
		if ( self::S_IDLE !== $state ) {
			if ( '' === trim( (string) ( $decoded['uuid'] ?? '' ) ) ) {
				return $verdict( self::S_CORRUPT, 'missing_uuid_for_state_' . $state, $decoded );
			}
			if ( '' === trim( (string) ( $decoded['operation'] ?? '' ) ) ) {
				return $verdict( self::S_CORRUPT, 'missing_operation_for_state_' . $state, $decoded );
			}
		}
		if ( self::S_CONFIRMED === $state && '' === trim( (string) ( $decoded['assigned_number'] ?? '' ) ) ) {
			return $verdict( self::S_CORRUPT, 'confirmed_without_assigned_number', $decoded );
		}

		return $verdict( $state, 'ok', $decoded );
	}

	public function state(): string {
		return $this->status()['state'];
	}

	public function state_reason(): string {
		return $this->status()['reason'];
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
	public function reset_after_reconcile( string $evidence, string $audit_note = '' ): array {
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
		$entry = array(
			'at_utc'   => gmdate( 'c' ),
			'to'       => self::S_IDLE,
			'evidence' => $evidence,
		);
		// The operator's own audit label, recorded alongside the required
		// evidence token. It is a note, never a substitute: the gate above
		// still demands the literal 'document_absent_at_edm'.
		if ( '' !== trim( $audit_note ) ) {
			$entry['audit'] = trim( $audit_note );
		}
		$history[] = $entry;

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

/**
 * Classify a write attempt.
 *
 * @param array<string, mixed>|null $parsed Parser output, or null when the call threw.
 * @param bool                      $threw  Whether the transport call raised.
 */
function kuka_sandbox_classify_call( ?array $parsed, bool $threw ): string {
	if ( $threw || null === $parsed ) {
		// The call left this process. A document may exist at EDM.
		return KUKA_SANDBOX_CALL_UNCERTAIN;
	}

	$classification = (string) ( $parsed['classification'] ?? KUKA_SANDBOX_CALL_UNCERTAIN );

	return in_array( $classification, array( KUKA_SANDBOX_CALL_SUCCESS, KUKA_SANDBOX_CALL_DEFINITIVE, KUKA_SANDBOX_CALL_UNCERTAIN ), true )
		? $classification
		: KUKA_SANDBOX_CALL_UNCERTAIN;
}

/**
 * Turn a classification plus the settle result into the reported verdict.
 *
 * A success here means LoadInvoice stored a DRAFT at EDM. It never means an
 * invoice was sent or issued: SendInvoice is a separate operation and this
 * harness does not call it.
 *
 * A successful external call whose confirmed state could not be persisted is
 * NEVER reported as PASS or as confirmed: the on-disk record still says
 * in_flight, so a second write stays refused and the operator must reconcile.
 *
 * @param string               $classification Call classification.
 * @param array<string, mixed> $settle Result of Kuka_Sandbox_Claim::settle().
 * @return array{create_verdict: string, result_label: string, status_token: string, exit_code: int, state_recorded: bool}
 */
function kuka_sandbox_resolve_report( string $classification, array $settle ): array {
	$written = true === ( $settle['written'] ?? false );

	if ( KUKA_SANDBOX_CALL_SUCCESS === $classification && $written ) {
		// LoadInvoice stored a DRAFT at EDM. Nothing was sent to a recipient.
		return array(
			'create_verdict' => 'PASS',
			'result_label'   => 'draft_uploaded',
			'status_token'   => 'draft_uploaded',
			'exit_code'      => 0,
			'state_recorded' => true,
		);
	}

	if ( KUKA_SANDBOX_CALL_SUCCESS === $classification && ! $written ) {
		return array(
			'create_verdict' => 'FAIL',
			'result_label'   => 'state_persist_failed_manual_reconciliation_required',
			'status_token'   => 'state_persist_failed_manual_reconciliation_required',
			'exit_code'      => 1,
			'state_recorded' => false,
		);
	}

	if ( KUKA_SANDBOX_CALL_DEFINITIVE === $classification ) {
		return array(
			'create_verdict' => 'FAIL',
			'result_label'   => $written ? 'failed_definitive' : 'state_persist_failed_manual_reconciliation_required',
			'status_token'   => $written ? 'failed_definitive' : 'state_persist_failed_manual_reconciliation_required',
			'exit_code'      => 1,
			'state_recorded' => $written,
		);
	}

	return array(
		'create_verdict' => 'FAIL',
		'result_label'   => $written ? 'uncertain_manual_reconciliation_required' : 'state_persist_failed_manual_reconciliation_required',
		'status_token'   => $written ? 'uncertain_manual_reconciliation_required' : 'state_persist_failed_manual_reconciliation_required',
		'exit_code'      => 1,
		'state_recorded' => $written,
	);
}

/**
 * Assemble the LoadInvoice request.
 *
 * LoadInvoice UPLOADS A DRAFT: EDM stores the document so that it can be sent
 * later. It is not SendInvoice, it delivers nothing to a recipient and it is
 * not an issued invoice.
 * https://docs.edmbilisim.com.tr/api/api-documentation/einvoice/referenced/EFaturaEDMConnectorService.LoadInvoiceRequest.html
 *
 * Two shape rules are the whole point of the experiment and are enforced here:
 *   - INVOICE/@ID is never set, so EDM has to assign the document number. The
 *     UBL's own cbc:ID carries EDM's portal-serial placeholder instead; the two
 *     are different fields and only the SOAP-side one is omitted.
 *   - GENERATEINVOICEIDONLOAD is always true (WSDL: xs:boolean, required, and
 *     present only on LoadInvoiceRequest -- SendInvoiceRequest has no
 *     equivalent).
 * INVOICESERIAL_REQUESTED appears only when a usable serial was resolved by
 * kuka_sandbox_resolve_series(); with no serial EDM uses its system serial.
 *
 * @param array<string, mixed> $ctx Prepared, already verified context.
 * @return array<string, mixed>
 */
function kuka_sandbox_build_load_request( array $ctx ): array {
	$uuid        = (string) ( $ctx['uuid'] ?? '' );
	$issue_date  = (string) ( $ctx['issue_date'] ?? '' );
	$series_code = (string) ( $ctx['series_code'] ?? '' );

	$header = array(
		'SENDER'                          => (string) ( $ctx['sender_vkn'] ?? '' ),
		'RECEIVER'                        => (string) ( $ctx['receiver_vkn'] ?? '' ),
		'FROM'                            => (string) ( $ctx['sender_alias'] ?? '' ),
		'PROFILEID'                       => (string) ( $ctx['profile_id'] ?? '' ),
		'INVOICE_TYPE'                    => 'SATIS',
		'ISSUE_DATE'                      => $issue_date,
		'PAYABLE_AMOUNT'                  => (string) ( $ctx['payable'] ?? '' ),
		'INTERNETSALES'                   => false,
		'EARCHIVE'                        => true,
		'EARCHIVE_REPORT_SENDDATE'        => $issue_date,
		'CANCEL_EARCHIVE_REPORT_SENDDATE' => $issue_date,
		'ISACTIVE'                        => true,
		'MARKED'                          => false,
	);

	if ( '' !== $series_code ) {
		$header['INVOICESERIAL_REQUESTED'] = $series_code;
	}

	return array(
		// The one shared generator, so this request cannot drift away from the
		// production client's header again. CLIENT_TXN_ID is the document UUID:
		// it is the idempotency key EDM sees.
		'REQUEST_HEADER'          => Kuka_Island_Core_EDM_Request_Header::build(
			(string) ( $ctx['session_id'] ?? '' ),
			'LoadInvoice',
			(string) ( $ctx['application_name'] ?? '' ),
			$uuid,
			(string) ( $ctx['action_date'] ?? '' )
		),
		'SENDER'                  => array(
			'vkn'   => (string) ( $ctx['sender_vkn'] ?? '' ),
			'alias' => (string) ( $ctx['sender_alias'] ?? '' ),
		),
		'RECEIVER'                => array( 'vkn' => (string) ( $ctx['receiver_vkn'] ?? '' ) ),
		'INVOICE'                 => array(
			array(
				// INVOICE/@ID deliberately absent: the experiment observes
				// whether EDM assigns it.
				'TRXID'   => (int) hexdec( substr( hash( 'sha256', $uuid ), 0, 8 ) ),
				'UUID'    => $uuid,
				'HEADER'  => $header,
				'CONTENT' => (string) ( $ctx['content'] ?? '' ),
			),
		),
		'GENERATEINVOICEIDONLOAD' => true,
	);
}

/**
 * The complete write sequence: claim, single call, classify, settle, resolve.
 *
 * This is the driver's write path. The driver calls it and so does the harness,
 * with a mocked transport and an injectable claim, so the reported verdict and
 * the number of external calls are both provable without touching EDM.
 *
 * Exactly one transport call is ever attempted, and only after the claim moved
 * the record from idle to in_flight on disk.
 *
 * @param Kuka_Sandbox_Claim                            $claim        Lock-guarded claim.
 * @param Kuka_Island_Core_SOAP_Transport_Interface     $transport    Transport to call.
 * @param array<string, mixed>                          $load_request LoadInvoice request payload.
 * @param string                                        $uuid         Deterministic UUID sent.
 * @param string                                        $operation    Operation name.
 * @return array<string, mixed>
 */
function kuka_sandbox_execute_write(
	Kuka_Sandbox_Claim $claim,
	Kuka_Island_Core_SOAP_Transport_Interface $transport,
	array $load_request,
	string $uuid,
	string $operation
): array {
	$base = array(
		'claimed'         => false,
		'claim_reason'    => '',
		'call_attempted'  => false,
		'classification'  => 'not_attempted',
		'parsed'          => null,
		'settle'          => array(),
		'create_verdict'  => 'BLOCKED',
		'result_label'    => 'no_write_attempted',
		'status_token'    => 'no_write_attempted',
		'exit_code'       => 1,
		'state_recorded'  => false,
		'assigned_number' => '',
		'fault'           => array(),
	);

	$claimed              = $claim->claim( $uuid, $operation );
	$base['claim_reason'] = (string) $claimed['reason'];
	if ( ! $claimed['ok'] ) {
		$base['status_token'] = 'claim_refused';
		$base['result_label'] = 'claim_refused';

		return $base;
	}
	$base['claimed'] = true;

	$parsed      = null;
	$threw       = false;
	$fault_class = array();
	try {
		$base['call_attempted'] = true;
		$response               = $transport->call( 'LoadInvoice', $load_request );
		$parsed                 = kuka_sandbox_parse_load_invoice_response( $response, $uuid );
	} catch ( Throwable $t ) {
		$threw = true;
		/*
		 * An uncertain outcome forbids a retry, so the operator's only way
		 * forward is knowing WHAT kind of refusal it was. The message itself is
		 * untrusted remote text and is never kept: only the allow-listed
		 * classification is.
		 */
		if ( class_exists( 'Kuka_Island_Core_EDM_Fault_Classifier' ) ) {
			$fault_class = Kuka_Island_Core_EDM_Fault_Classifier::classify(
				( $t instanceof SoapFault && isset( $t->faultcode ) ) ? (string) $t->faultcode : '',
				(string) $t->getMessage()
			);
		}
	}
	$base['fault'] = $fault_class;

	$classification         = kuka_sandbox_classify_call( $parsed, $threw );
	$base['classification'] = $classification;
	$base['parsed']         = $parsed;

	$settle_target = KUKA_SANDBOX_CALL_SUCCESS === $classification
		? Kuka_Sandbox_Claim::S_CONFIRMED
		: ( KUKA_SANDBOX_CALL_DEFINITIVE === $classification ? Kuka_Sandbox_Claim::S_FAILED_DEFINITIVE : Kuka_Sandbox_Claim::S_UNCERTAIN );

	$extra = array( 'outcome' => (string) ( $parsed['outcome'] ?? ( $threw ? 'transport_exception' : 'unknown' ) ) );
	if ( array() !== $fault_class ) {
		// Allow-listed tokens only; no remote text reaches the state file.
		$extra['fault'] = $fault_class;
	}
	if ( KUKA_SANDBOX_CALL_SUCCESS === $classification ) {
		$extra['assigned_number']  = (string) $parsed['assigned_number'];
		$extra['return_code']      = $parsed['return_code'];
		$base['assigned_number']   = (string) $parsed['assigned_number'];
	}

	$settle         = $claim->settle( $settle_target, $extra );
	$base['settle'] = $settle;

	return array_merge( $base, kuka_sandbox_resolve_report( $classification, $settle ) );
}
