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
/* The real WSDL endpoint, not the environment label                            */
/* ========================================================================== */

$official_test_wsdl = 'https://' . KUKA_SANDBOX_TEST_WSDL_HOST . KUKA_SANDBOX_TEST_WSDL_PATH;

$endpoint_cases = array(
	// Accepted: exactly the EDM test service, with or without ?singleWsdl.
	'official_test_no_query'     => array( $official_test_wsdl, true, 'edm_test_service_verified' ),
	'official_test_single_wsdl'  => array( $official_test_wsdl . '?singleWsdl', true, 'edm_test_service_verified' ),
	// Refused: the live service.
	'live_wsdl'                  => array( Kuka_Island_Core_Invoice_Config::DEFAULT_LIVE_WSDL, false, 'wsdl_host_not_edm_test' ),
	'live_host_bare'             => array( 'https://portal2.edmbilisim.com.tr' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_host_not_edm_test' ),
	// Refused: look-alike hosts.
	'suffix_lookalike'           => array( 'https://test.edmbilisim.com.tr.evil.example' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_host_not_edm_test' ),
	'prefix_lookalike'           => array( 'https://eviltest.edmbilisim.com.tr' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_host_not_edm_test' ),
	'subdomain_lookalike'        => array( 'https://a.test.edmbilisim.com.tr' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_host_not_edm_test' ),
	'host_only_in_path'          => array( 'https://evil.example/test.edmbilisim.com.tr' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_host_not_edm_test' ),
	'trailing_dot_host'          => array( 'https://test.edmbilisim.com.tr.' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_host_not_edm_test' ),
	// Refused: transport and locality.
	'plain_http'                 => array( 'http://' . KUKA_SANDBOX_TEST_WSDL_HOST . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_scheme_not_https' ),
	'localhost'                  => array( 'https://localhost' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_host_not_edm_test' ),
	'ip_literal'                 => array( 'https://93.184.216.34' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_host_not_edm_test' ),
	// Refused: wrong service on the right host.
	'other_service_path'         => array( 'https://' . KUKA_SANDBOX_TEST_WSDL_HOST . '/EFaturaEDM/EFaturaEDM.svc', false, 'wsdl_path_not_test_service' ),
	'host_omitted'               => array( 'https://' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_malformed' ),
	'root_path'                  => array( 'https://' . KUKA_SANDBOX_TEST_WSDL_HOST . '/', false, 'wsdl_path_not_test_service' ),
	// Refused: URL features the canonical endpoint does not have.
	'userinfo'                   => array( 'https://user:pass@' . KUKA_SANDBOX_TEST_WSDL_HOST . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_contains_userinfo' ),
	'userinfo_lookalike'         => array( 'https://' . KUKA_SANDBOX_TEST_WSDL_HOST . '@evil.example' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_contains_userinfo' ),
	'custom_port'                => array( 'https://' . KUKA_SANDBOX_TEST_WSDL_HOST . ':8443' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_explicit_port_refused' ),
	'default_port_spelled_out'   => array( 'https://' . KUKA_SANDBOX_TEST_WSDL_HOST . ':443' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_explicit_port_refused' ),
	'fragment'                   => array( $official_test_wsdl . '#x', false, 'wsdl_contains_fragment' ),
	'unexpected_query'           => array( $official_test_wsdl . '?wsdl=0', false, 'wsdl_query_not_allowed' ),
	'backslash'                  => array( 'https://' . KUKA_SANDBOX_TEST_WSDL_HOST . '\\@evil.example' . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_contains_backslash' ),
	'whitespace'                 => array( 'https://' . KUKA_SANDBOX_TEST_WSDL_HOST . ' /x', false, 'wsdl_contains_whitespace_or_control' ),
	// A padded canonical URL is refused, never trimmed back into the allow-list.
	'leading_space'              => array( ' ' . $official_test_wsdl, false, 'wsdl_contains_whitespace_or_control' ),
	'trailing_space'             => array( $official_test_wsdl . ' ', false, 'wsdl_contains_whitespace_or_control' ),
	'leading_tab'                => array( "\t" . $official_test_wsdl, false, 'wsdl_contains_whitespace_or_control' ),
	'trailing_tab'               => array( $official_test_wsdl . "\t", false, 'wsdl_contains_whitespace_or_control' ),
	'leading_newline'            => array( "\n" . $official_test_wsdl, false, 'wsdl_contains_whitespace_or_control' ),
	'trailing_newline'           => array( $official_test_wsdl . "\n", false, 'wsdl_contains_whitespace_or_control' ),
	'trailing_crlf'              => array( $official_test_wsdl . "\r\n", false, 'wsdl_contains_whitespace_or_control' ),
	'leading_cr'                 => array( "\r" . $official_test_wsdl, false, 'wsdl_contains_whitespace_or_control' ),
	'leading_nul'                => array( "\0" . $official_test_wsdl, false, 'wsdl_contains_whitespace_or_control' ),
	'trailing_nul'               => array( $official_test_wsdl . "\0", false, 'wsdl_contains_whitespace_or_control' ),
	'trailing_del'               => array( $official_test_wsdl . "\x7F", false, 'wsdl_contains_whitespace_or_control' ),
	'vertical_tab'               => array( "\x0B" . $official_test_wsdl, false, 'wsdl_contains_whitespace_or_control' ),
	'form_feed'                  => array( $official_test_wsdl . "\x0C", false, 'wsdl_contains_whitespace_or_control' ),
	'whitespace_only'            => array( '   ', false, 'wsdl_contains_whitespace_or_control' ),
	'padded_single_wsdl'         => array( ' ' . $official_test_wsdl . '?singleWsdl ', false, 'wsdl_contains_whitespace_or_control' ),
	'empty'                      => array( '', false, 'wsdl_empty' ),
	'malformed'                  => array( 'https://', false, 'wsdl_malformed' ),
	'not_a_url'                  => array( 'EFaturaEDM.svc', false, 'wsdl_scheme_not_https' ),
	'scheme_relative'            => array( '//' . KUKA_SANDBOX_TEST_WSDL_HOST . KUKA_SANDBOX_TEST_WSDL_PATH, false, 'wsdl_scheme_not_https' ),
);

$endpoint_ok      = true;
$endpoint_details = array();
foreach ( $endpoint_cases as $case => $spec ) {
	$verdict = kuka_sandbox_verify_test_endpoint( $spec[0] );
	$hit     = $verdict['ok'] === $spec[1] && $verdict['reason'] === $spec[2];
	$endpoint_details[ $case ] = $hit ? ( $verdict['ok'] ? 'accepted' : 'refused' ) : ( 'WRONG(' . ( $verdict['ok'] ? 'accepted' : 'refused' ) . '/' . $verdict['reason'] . ')' );
	if ( ! $hit ) {
		$endpoint_ok = false;
	}
}

// The config default for the test environment must itself pass, and the config
// default for live must not.
$config_default_test = kuka_sandbox_verify_test_endpoint( Kuka_Island_Core_Invoice_Config::DEFAULT_TEST_WSDL );
$config_default_live = kuka_sandbox_verify_test_endpoint( Kuka_Island_Core_Invoice_Config::DEFAULT_LIVE_WSDL );

$report(
	'SANDBOX_ENDPOINT_ALLOWLIST',
	$endpoint_ok
	&& true === $config_default_test['ok']
	&& false === $config_default_live['ok'],
	sprintf(
		'cases:%d|accepted:%d|refused:%d|wrong:%s|config_default_test:%s|config_default_live:%s',
		count( $endpoint_details ),
		count( array_filter( $endpoint_details, static fn( string $v ): bool => 'accepted' === $v ) ),
		count( array_filter( $endpoint_details, static fn( string $v ): bool => 'refused' === $v ) ),
		implode( ',', array_keys( array_filter( $endpoint_details, static fn( string $v ): bool => str_starts_with( $v, 'WRONG' ) ) ) ) ?: 'none',
		$config_default_test['ok'] ? 'accepted' : 'REFUSED',
		$config_default_live['ok'] ? 'ACCEPTED' : 'refused'
	)
);

/*
 * Padding is a refusal, not something to clean up. Asserted by calling the
 * verifier directly with every pad character around the otherwise canonical
 * URL: an implementation that trims first would accept all of these.
 */
$pad_bytes = array(
	'space'         => ' ',
	'tab'           => "\t",
	'newline'       => "\n",
	'carriage'      => "\r",
	'crlf'          => "\r\n",
	'nul'           => "\0",
	'vertical_tab'  => "\x0B",
	'form_feed'     => "\x0C",
	'del'           => "\x7F",
);
$padding_ok      = true;
$padding_details = array();
foreach ( $pad_bytes as $pad_name => $pad ) {
	foreach ( array( 'leading' => $pad . $official_test_wsdl, 'trailing' => $official_test_wsdl . $pad ) as $side => $candidate ) {
		$verdict = kuka_sandbox_verify_test_endpoint( $candidate );
		$hit     = false === $verdict['ok'] && 'wsdl_contains_whitespace_or_control' === $verdict['reason'];
		if ( ! $hit ) {
			$padding_ok = false;
			$padding_details[] = $side . '_' . $pad_name . '=' . ( $verdict['ok'] ? 'ACCEPTED' : $verdict['reason'] );
		}
	}
}
// The unpadded value must still be accepted, so the rule rejects padding rather
// than the endpoint itself.
$unpadded_still_ok = kuka_sandbox_verify_test_endpoint( $official_test_wsdl );

$report(
	'SANDBOX_ENDPOINT_REJECTS_PADDING',
	$padding_ok && true === $unpadded_still_ok['ok'],
	sprintf(
		'pad_bytes:%d|variants:%d|leaked:%s|unpadded_canonical:%s',
		count( $pad_bytes ),
		count( $pad_bytes ) * 2,
		empty( $padding_details ) ? 'none' : implode( ',', $padding_details ),
		$unpadded_still_ok['ok'] ? 'accepted' : 'REFUSED'
	)
);

// The verifier must not trim, and the source must not reintroduce it.
$lib_body = (string) file_get_contents( __DIR__ . '/lib-edm-sandbox.php' );
$verifier_body = '';
$verifier_start = strpos( $lib_body, 'function kuka_sandbox_verify_test_endpoint(' );
if ( false !== $verifier_start ) {
	$verifier_end  = strpos( $lib_body, "\n}\n", $verifier_start );
	$verifier_body = false !== $verifier_end ? substr( $lib_body, $verifier_start, $verifier_end - $verifier_start ) : '';
}
$report(
	'SANDBOX_ENDPOINT_DOES_NOT_NORMALISE',
	'' !== $verifier_body
	&& ! str_contains( $verifier_body, 'trim( $wsdl )' )
	&& ! str_contains( $verifier_body, 'ltrim(' )
	&& ! str_contains( $verifier_body, 'rtrim(' )
	&& str_contains( $verifier_body, '$raw = $wsdl;' ),
	sprintf(
		'verifier_located:%s|trim_calls:%s|raw_bytes_validated:%s',
		'' !== $verifier_body ? 'yes' : 'no',
		( str_contains( $verifier_body, 'trim(' ) ) ? 'PRESENT' : 'none',
		str_contains( $verifier_body, '$raw = $wsdl;' ) ? 'yes' : 'no'
	)
);

// The driver must prove the endpoint before it logs in, not after.
$driver_body     = (string) file_get_contents( __DIR__ . '/edm-sandbox-invoice.php' );
$verify_position = strpos( $driver_body, 'kuka_sandbox_verify_test_endpoint(' );
$login_position  = strpos( $driver_body, '$client->login()' );
$report(
	'SANDBOX_ENDPOINT_CHECKED_BEFORE_LOGIN',
	false !== $verify_position
	&& false !== $login_position
	&& $verify_position < $login_position
	&& str_contains( $driver_body, "SANDBOX_ENDPOINT=BLOCKED|reason:%s|login_attempted:no" )
	&& str_contains( $driver_body, '$config->get_wsdl()' ),
	sprintf(
		'verifier_present:%s|reads_real_get_wsdl:%s|before_login:%s|blocked_line_states_no_login:%s',
		false !== $verify_position ? 'yes' : 'no',
		str_contains( $driver_body, '$config->get_wsdl()' ) ? 'yes' : 'no',
		( false !== $verify_position && false !== $login_position && $verify_position < $login_position ) ? 'yes' : 'no',
		str_contains( $driver_body, 'login_attempted:no' ) ? 'yes' : 'no'
	)
);

/* ========================================================================== */
/* Sandbox values need BOTH the test label and the proved endpoint              */
/* ========================================================================== */

$good_endpoint = kuka_sandbox_verify_test_endpoint( $official_test_wsdl . '?singleWsdl' );
$bad_endpoint  = kuka_sandbox_verify_test_endpoint( Kuka_Island_Core_Invoice_Config::DEFAULT_LIVE_WSDL );

$defaults_test = kuka_sandbox_resolve_defaults( 'test', $good_endpoint, '', '', '1234567890' );
$defaults_live = kuka_sandbox_resolve_defaults( 'live', $bad_endpoint, '', '', '1234567890' );
// The two dangerous mixed cases: a test label over a live URL, and a live label
// over the test URL. Neither may resolve anything.
$defaults_test_label_live_url = kuka_sandbox_resolve_defaults( 'test', $bad_endpoint, '', '', '1234567890' );
$defaults_live_label_test_url = kuka_sandbox_resolve_defaults( 'live', $good_endpoint, '', '', '1234567890' );
// An override cannot buy its way past either gate.
$defaults_live_override = kuka_sandbox_resolve_defaults( 'live', $bad_endpoint, 'EARSIVFATURA', '11223344556', '1234567890' );

$refusals = array(
	'live_both'            => $defaults_live,
	'test_label_live_url'  => $defaults_test_label_live_url,
	'live_label_test_url'  => $defaults_live_label_test_url,
	'live_with_override'   => $defaults_live_override,
);
$refusals_clean = true;
foreach ( $refusals as $refused ) {
	if ( false !== $refused['ok'] || '' !== $refused['profile_id'] || '' !== $refused['receiver_vkn'] ) {
		$refusals_clean = false;
	}
}

$report(
	'SANDBOX_DEFAULTS_TEST_ENDPOINT_ONLY',
	true === $defaults_test['ok']
	&& KUKA_SANDBOX_DOCUMENTED_PROFILE_ID === $defaults_test['profile_id']
	&& KUKA_SANDBOX_DOCUMENTED_RECEIVER_VKN === $defaults_test['receiver_vkn']
	&& 'documented_example_fixture' === $defaults_test['profile_source']
	&& 'documented_example_fixture' === $defaults_test['receiver_source']
	&& $refusals_clean
	&& in_array( 'wsdl_endpoint_not_verified', $defaults_test_label_live_url['failed'], true )
	&& in_array( 'environment_not_test', $defaults_live_label_test_url['failed'], true ),
	sprintf(
		'test_label_and_verified_url:resolved|live_both:%s|test_label_live_url:%s|live_label_test_url:%s|live_with_override:%s|values_leaked:%s|reason:%s',
		$defaults_live['ok'] ? 'LEAKED' : 'refused',
		$defaults_test_label_live_url['ok'] ? 'LEAKED' : 'refused',
		$defaults_live_label_test_url['ok'] ? 'LEAKED' : 'refused',
		$defaults_live_override['ok'] ? 'LEAKED' : 'refused',
		$refusals_clean ? 'none' : 'YES',
		$defaults_test_label_live_url['reason']
	)
);

/*
 * Overrides: accepted only after a format and safety check. A bad override
 * fails closed; it never silently falls back to the documented default.
 */
$override_cases = array(
	'profile_override_ok'        => array( 'EARSIVFATURA_X', '', true, '' ),
	'profile_lowercase'          => array( 'earsivfatura', '', false, 'profile_override_invalid_format' ),
	'profile_too_short'          => array( 'EAR', '', false, 'profile_override_invalid_format' ),
	'profile_with_space'         => array( 'EARSIV FATURA', '', false, 'profile_override_invalid_format' ),
	'profile_with_punctuation'   => array( 'EARSIV;DROP', '', false, 'profile_override_invalid_format' ),
	'receiver_override_ok'       => array( '', '11223344556', true, '' ),
	'receiver_too_short'         => array( '', '123', false, 'receiver_override_invalid_format' ),
	'receiver_non_numeric'       => array( '', '1122334455a', false, 'receiver_override_invalid_format' ),
	'receiver_equals_sender'     => array( '', '1234567890', false, 'receiver_override_equals_sender' ),
	'receiver_all_zeroes'        => array( '', '00000000000', false, 'receiver_override_not_an_identity' ),
);
$override_ok      = true;
$override_details = array();
foreach ( $override_cases as $case => $spec ) {
	$resolved = kuka_sandbox_resolve_defaults( 'test', $good_endpoint, $spec[0], $spec[1], '1234567890' );
	$hit      = $resolved['ok'] === $spec[2]
		&& ( '' === $spec[3] || in_array( $spec[3], $resolved['failed'], true ) )
		// A rejected override never leaks a usable value.
		&& ( $spec[2] || ( '' === $resolved['profile_id'] && '' === $resolved['receiver_vkn'] ) );
	$override_details[ $case ] = $hit ? ( $resolved['ok'] ? 'accepted' : 'refused' ) : 'WRONG';
	if ( ! $hit ) {
		$override_ok = false;
	}
}
$report(
	'SANDBOX_OVERRIDE_VALIDATION',
	$override_ok,
	sprintf(
		'cases:%d|wrong:%s',
		count( $override_details ),
		implode( ',', array_keys( array_filter( $override_details, static fn( string $v ): bool => 'WRONG' === $v ) ) ) ?: 'none'
	)
);

/*
 * The artificial "EDM must confirm the PROFILEID in writing" gate is gone, in
 * the library, in the credential loader and in the credential helper.
 */
$confirmation_sources = array(
	__DIR__ . '/lib-edm-sandbox.php',
	__DIR__ . '/lib-edm-test-credentials.php',
	__DIR__ . '/edm-sandbox-invoice.php',
	__DIR__ . '/edm-test-credentials.sh',
);
$confirmation_hits = array();
foreach ( $confirmation_sources as $source ) {
	$body = is_readable( $source ) ? (string) file_get_contents( $source ) : '';
	if ( str_contains( $body, 'PROFILE_ID_CONFIRMED' ) || str_contains( $body, 'kuka_sandbox_profile_confirmation' ) ) {
		$confirmation_hits[] = basename( $source );
	}
}
$report(
	'SANDBOX_PROFILE_CONFIRMATION_GATE_REMOVED',
	! function_exists( 'kuka_sandbox_profile_confirmation' )
	&& ! array_key_exists( 'KUKA_EDM_SANDBOX_PROFILE_ID_CONFIRMED', kuka_edm_sandbox_credential_map() )
	&& array() === $confirmation_hits
	&& 2 === count( kuka_edm_sandbox_credential_map() ),
	sprintf(
		'function_exists:%s|credential_key:%s|sources_scanned:%d|hits:%s|sandbox_keys:%d',
		function_exists( 'kuka_sandbox_profile_confirmation' ) ? 'yes' : 'no',
		array_key_exists( 'KUKA_EDM_SANDBOX_PROFILE_ID_CONFIRMED', kuka_edm_sandbox_credential_map() ) ? 'present' : 'absent',
		count( $confirmation_sources ),
		empty( $confirmation_hits ) ? 'none' : implode( ',', $confirmation_hits ),
		count( kuka_edm_sandbox_credential_map() )
	)
);

/*
 * Sandbox isolation.
 *
 * PROFILEID = EARSIVFATURA is the real UBL-TR profile for an e-Arşiv document,
 * so production legitimately uses that string; what must never happen is the
 * plugin reaching into this sandbox library for a default. The sandbox
 * constants are therefore required to live in exactly one place, and no plugin
 * file may reference the library, its constants or its credential keys.
 *
 * The recipient identity is the sharper case: 11111111111 is the generic GİB
 * consumer TCKN that EDM technical support confirmed for individual e-Arşiv
 * recipients, so it now belongs in the mapper as a named constant -- and
 * nowhere else. What is asserted here is that it is declared once, in the
 * mapper, and that the sandbox library is not where any of it comes from.
 */
$module_dir   = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/invoice/';
$module_files = (array) glob( $module_dir . '*.php' );
$leak_hits    = array();

/**
 * Strip comments so the scan measures CODE, not prose.
 *
 * A docblock may legitimately point at the sandbox library to explain why a
 * shared contract exists; what must never happen is executable code depending
 * on it.
 *
 * @param string $php Source.
 */
$executable_only = static function ( string $php ): string {
	$out = '';
	foreach ( token_get_all( $php ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= $token[1];
			continue;
		}
		$out .= $token;
	}

	return $out;
};

foreach ( $module_files as $module_file ) {
	$body = $executable_only( (string) file_get_contents( $module_file ) );
	foreach ( array( 'lib-edm-sandbox', 'kuka_sandbox_', 'KUKA_SANDBOX_', 'KUKA_EDM_SANDBOX_' ) as $needle ) {
		if ( str_contains( $body, $needle ) ) {
			$leak_hits[] = basename( $module_file ) . ':' . $needle;
		}
	}
}

$mapper_src = (string) file_get_contents( $module_dir . 'class-invoice-order-mapper.php' );

/*
 * The generic consumer TCKN is declared exactly once, as
 * Kuka_Island_Core_Invoice_Order_Mapper::GENERIC_INDIVIDUAL_TCKN, and every
 * other module file refers to that constant rather than repeating the literal.
 * A second copy is how a fiscal identifier drifts.
 */
$generic_tckn_declared = str_contains( $mapper_src, "public const GENERIC_INDIVIDUAL_TCKN = '11111111111';" );
$generic_tckn_copies   = array();
foreach ( $module_files as $module_file ) {
	$body  = $executable_only( (string) file_get_contents( $module_file ) );
	$hits  = preg_match_all( "/['\"]11111111111['\"]/", $body );
	$limit = basename( $module_file ) === 'class-invoice-order-mapper.php' ? 1 : 0;
	if ( $hits > $limit ) {
		$generic_tckn_copies[] = basename( $module_file ) . '(' . $hits . ')';
	}
}

$report(
	'SANDBOX_DEFAULTS_NOT_IN_PRODUCTION',
	count( $module_files ) >= 15
	&& array() === $leak_hits
	&& $generic_tckn_declared
	&& array() === $generic_tckn_copies,
	sprintf(
		'module_files:%d|sandbox_references:%s|generic_tckn_declared_once:%s|extra_literal_copies:%s',
		count( $module_files ),
		empty( $leak_hits ) ? 'none' : implode( ',', $leak_hits ),
		$generic_tckn_declared ? 'yes' : 'no',
		empty( $generic_tckn_copies ) ? 'none' : implode( ',', $generic_tckn_copies )
	)
);

/* ========================================================================== */
/* Optional serial: absence is not a blocker, a bad override still is           */
/* ========================================================================== */

$series_cases = array(
	'not_configured'      => array( '', array( 'AAA' ), true, true, false, 'not_configured_edm_assigns_the_number' ),
	'not_configured_dark' => array( '', array(), false, true, false, 'not_configured_edm_assigns_the_number' ),
	'registered'          => array( 'KUK', array( 'AAA', 'kuk' ), true, true, true, 'series_override_registered_at_edm' ),
	'unregistered'        => array( 'KUK', array( 'AAA', 'BBB' ), true, false, false, 'series_override_not_registered_at_edm' ),
	'query_failed'        => array( 'KUK', array(), false, false, false, 'series_override_registration_unverified' ),
	'bad_format_long'     => array( 'KUKA', array( 'KUKA' ), true, false, false, 'series_override_invalid_format' ),
	'bad_format_lower'    => array( 'kuk', array( 'kuk' ), true, false, false, 'series_override_invalid_format' ),
	'bad_format_symbol'   => array( 'K-K', array(), false, false, false, 'series_override_invalid_format' ),
);
$series_ok      = true;
$series_details = array();
foreach ( $series_cases as $case => $spec ) {
	$resolved = kuka_sandbox_resolve_series( $spec[0], $spec[1], $spec[2] );
	$hit      = $resolved['ok'] === $spec[3]
		&& $resolved['send'] === $spec[4]
		&& $resolved['reason'] === $spec[5]
		// A serial that is not sent must not survive as a code either.
		&& ( $resolved['send'] || '' === $resolved['code'] );
	$series_details[ $case ] = $hit ? ( $resolved['send'] ? 'sent' : ( $resolved['ok'] ? 'omitted' : 'blocked' ) ) : ( 'WRONG(' . $resolved['reason'] . ')' );
	if ( ! $hit ) {
		$series_ok = false;
	}
}
$report(
	'SANDBOX_SERIES_OPTIONAL',
	$series_ok,
	implode( '|', array_map( static fn( string $k, string $v ): string => $k . ':' . $v, array_keys( $series_details ), $series_details ) )
);

/* ========================================================================== */
/* LoadInvoice request shape                                                    */
/* ========================================================================== */

$request_ctx = array(
	'uuid'             => kuka_sandbox_uuid(),
	'issue_date'       => '2026-01-02',
	'action_date'      => '2026-01-02T03:04:05',
	'session_id'       => 'SESSION',
	'application_name' => 'ozelyazilim.kukaisland',
	'sender_vkn'       => '1234567890',
	'sender_alias'     => 'urn:mail:box@example.com',
	'receiver_vkn'     => KUKA_SANDBOX_DOCUMENTED_RECEIVER_VKN,
	'profile_id'       => KUKA_SANDBOX_DOCUMENTED_PROFILE_ID,
	'payable'          => '120.00',
	'content'          => '<Invoice/>',
);

$req_no_series   = kuka_sandbox_build_load_request( array_merge( $request_ctx, array( 'series_code' => '' ) ) );
$req_with_series = kuka_sandbox_build_load_request( array_merge( $request_ctx, array( 'series_code' => 'KUK' ) ) );

$header_no_series   = $req_no_series['INVOICE'][0]['HEADER'];
$header_with_series = $req_with_series['INVOICE'][0]['HEADER'];

$report(
	'SANDBOX_LOAD_REQUEST_SHAPE',
	true === $req_no_series['GENERATEINVOICEIDONLOAD']
	&& true === $req_with_series['GENERATEINVOICEIDONLOAD']
	&& ! array_key_exists( 'INVOICESERIAL_REQUESTED', $header_no_series )
	&& 'KUK' === ( $header_with_series['INVOICESERIAL_REQUESTED'] ?? null )
	// INVOICE/@ID is never present, with or without a serial.
	&& ! array_key_exists( 'ID', $req_no_series['INVOICE'][0] )
	&& ! array_key_exists( 'ID', $req_with_series['INVOICE'][0] )
	&& KUKA_SANDBOX_DOCUMENTED_PROFILE_ID === $header_no_series['PROFILEID']
	&& true === $header_no_series['EARCHIVE'],
	sprintf(
		'no_series:generate_on_load=%s,invoiceserial=%s,invoice_id=%s|with_series:generate_on_load=%s,invoiceserial=%s,invoice_id=%s',
		$req_no_series['GENERATEINVOICEIDONLOAD'] ? 'true' : 'false',
		array_key_exists( 'INVOICESERIAL_REQUESTED', $header_no_series ) ? 'present' : 'absent',
		array_key_exists( 'ID', $req_no_series['INVOICE'][0] ) ? 'present' : 'absent',
		$req_with_series['GENERATEINVOICEIDONLOAD'] ? 'true' : 'false',
		array_key_exists( 'INVOICESERIAL_REQUESTED', $header_with_series ) ? 'present' : 'absent',
		array_key_exists( 'ID', $req_with_series['INVOICE'][0] ) ? 'present' : 'absent'
	)
);

/* ========================================================================== */
/* REQUEST_HEADER: one shared generator, eight fields, exact values             */
/* ========================================================================== */

/*
 * The sandbox once sent four header fields while the production client sent
 * eight. Both now go through Kuka_Island_Core_EDM_Request_Header, and this is
 * asserted against the real LoadInvoice request the driver would hand the
 * transport -- not against a copy of the expectation.
 */
$load_header = (array) ( $req_no_series['REQUEST_HEADER'] ?? array() );

$header_expected = array(
	'REASON'           => 'LoadInvoice',
	'APPLICATION_NAME' => 'ozelyazilim.kukaisland',
	'HOSTNAME'         => 'kukaisland',
	'CHANNEL_NAME'     => 'WEB',
	'COMPRESSED'       => 'N',
	'SESSION_ID'       => 'SESSION',
	'ACTION_DATE'      => '2026-01-02T03:04:05',
	'CLIENT_TXN_ID'    => $request_ctx['uuid'],
);
$header_wrong = array();
foreach ( $header_expected as $field => $value ) {
	if ( ( $load_header[ $field ] ?? null ) !== $value ) {
		$header_wrong[] = $field;
	}
}

// Exactly the eight contract fields, each exactly once, in envelope order.
$header_keys = array_keys( $load_header );

$report(
	'SANDBOX_LOAD_REQUEST_HEADER_CONTRACT',
	Kuka_Island_Core_EDM_Request_Header::FIELDS === $header_keys
	&& 8 === count( $header_keys )
	&& count( $header_keys ) === count( array_unique( $header_keys ) )
	&& array() === $header_wrong,
	sprintf(
		'fields:%d|order_matches_contract:%s|duplicates:%s|wrong_values:%s|reason:%s|hostname:%s|channel:%s|compressed:%s|client_txn_id_is_uuid:%s',
		count( $header_keys ),
		Kuka_Island_Core_EDM_Request_Header::FIELDS === $header_keys ? 'yes' : 'no',
		count( $header_keys ) === count( array_unique( $header_keys ) ) ? 'none' : 'YES',
		empty( $header_wrong ) ? 'none' : implode( ',', $header_wrong ),
		(string) ( $load_header['REASON'] ?? 'absent' ),
		(string) ( $load_header['HOSTNAME'] ?? 'absent' ),
		(string) ( $load_header['CHANNEL_NAME'] ?? 'absent' ),
		(string) ( $load_header['COMPRESSED'] ?? 'absent' ),
		( $load_header['CLIENT_TXN_ID'] ?? null ) === $request_ctx['uuid'] ? 'yes' : 'no'
	)
);

// The sandbox must not hold its own copy of the header contract.
$sandbox_lib_body = (string) file_get_contents( __DIR__ . '/lib-edm-sandbox.php' );
$own_header_literals = array();
foreach ( array( "'CHANNEL_NAME'", "'HOSTNAME'", "'COMPRESSED'", "'REASON'" ) as $literal ) {
	if ( str_contains( $sandbox_lib_body, $literal . ' =>' ) ) {
		$own_header_literals[] = $literal;
	}
}
$report(
	'SANDBOX_HEADER_GENERATOR_IS_SHARED',
	str_contains( $sandbox_lib_body, 'Kuka_Island_Core_EDM_Request_Header::build(' )
	&& array() === $own_header_literals
	&& method_exists( 'Kuka_Island_Core_EDM_Request_Header', 'build' )
	&& ( new ReflectionMethod( 'Kuka_Island_Core_EDM_Request_Header', 'build' ) )->isStatic(),
	sprintf(
		'sandbox_uses_shared_builder:%s|sandbox_own_header_literals:%s|builder_is_pure_static:%s',
		str_contains( $sandbox_lib_body, 'Kuka_Island_Core_EDM_Request_Header::build(' ) ? 'yes' : 'no',
		empty( $own_header_literals ) ? 'none' : implode( ',', $own_header_literals ),
		( new ReflectionMethod( 'Kuka_Island_Core_EDM_Request_Header', 'build' ) )->isStatic() ? 'yes' : 'no'
	)
);

/* ========================================================================== */
/* UBL cbc:ID carries EDM's portal-serial placeholder                          */
/* ========================================================================== */

/*
 * The old code stripped cbc:ID out of the document. UBL-TR requires it, so what
 * was sent was structurally invalid. With GENERATEINVOICEIDONLOAD = true the
 * real number is assigned by EDM; the document still has to carry the portal
 * placeholder, and the SOAP-side INVOICE/@ID stays omitted. Two different
 * fields.
 */
$ubl_supplier_fixture = array(
	'vkn'        => '3230512384',
	'name'       => 'EDM TEST',
	'tax_office' => 'SELÇUK',
	'address'    => 'BOMONTİ BUSİNESS CENTER',
	'district'   => 'Esenler',
	'city'       => 'İstanbul',
	'postcode'   => '',
	'country'    => 'Türkiye',
	'email'      => '',
	'phone'      => '',
);

$ubl_built = kuka_sandbox_build_ubl(
	$ubl_supplier_fixture,
	KUKA_SANDBOX_DOCUMENTED_RECEIVER_VKN,
	KUKA_SANDBOX_DOCUMENTED_PROFILE_ID,
	kuka_sandbox_uuid()
);

$ubl_dom = new DOMDocument();
$ubl_dom->loadXML( $ubl_built['xml'] );
$ubl_xp     = new DOMXPath( $ubl_dom );
$ubl_ids    = $ubl_xp->query( '/*[local-name()="Invoice"]/*[local-name()="ID"]' );
$ubl_id_num = ( false !== $ubl_ids ) ? $ubl_ids->length : 0;
$ubl_id_val = $ubl_id_num > 0 ? trim( (string) $ubl_ids->item( 0 )->nodeValue ) : '';

$report(
	'SANDBOX_UBL_CBC_ID_PLACEHOLDER',
	1 === $ubl_id_num
	&& KUKA_SANDBOX_UBL_ID_PLACEHOLDER === $ubl_id_val
	&& 'ABC2009123456789' === $ubl_id_val
	&& 1 === $ubl_built['cbc_id_count']
	&& KUKA_SANDBOX_UBL_ID_PLACEHOLDER === $ubl_built['cbc_id']
	// The old placeholder string must be gone entirely.
	&& ! str_contains( $ubl_built['xml'], 'SANDBOXPLACEHOLDER' )
	&& ! str_contains( $sandbox_lib_body, 'removeChild' ),
	sprintf(
		'cbc_id_count:%d|cbc_id:%s|matches_literal:%s|dom_removal_code:%s|old_placeholder:%s',
		$ubl_id_num,
		$ubl_id_val,
		'ABC2009123456789' === $ubl_id_val ? 'yes' : 'no',
		str_contains( $sandbox_lib_body, 'removeChild' ) ? 'PRESENT' : 'removed',
		str_contains( $ubl_built['xml'], 'SANDBOXPLACEHOLDER' ) ? 'PRESENT' : 'gone'
	)
);

// The UBL placeholder and the omitted SOAP INVOICE/@ID are independent.
$ubl_request = kuka_sandbox_build_load_request(
	array_merge( $request_ctx, array( 'series_code' => '', 'content' => $ubl_built['xml'] ) )
);
$ubl_content = (string) ( $ubl_request['INVOICE'][0]['CONTENT'] ?? '' );

$report(
	'SANDBOX_REQUEST_KEEPS_UBL_ID_AND_OMITS_SOAP_ID',
	! array_key_exists( 'ID', $ubl_request['INVOICE'][0] )
	&& str_contains( $ubl_content, '>' . KUKA_SANDBOX_UBL_ID_PLACEHOLDER . '<' )
	&& true === $ubl_request['GENERATEINVOICEIDONLOAD'],
	sprintf(
		'soap_invoice_id_attribute:%s|ubl_cbc_id_in_content:%s|generate_invoice_id_on_load:%s',
		array_key_exists( 'ID', $ubl_request['INVOICE'][0] ) ? 'PRESENT' : 'absent',
		str_contains( $ubl_content, '>' . KUKA_SANDBOX_UBL_ID_PLACEHOLDER . '<' ) ? 'present' : 'ABSENT',
		$ubl_request['GENERATEINVOICEIDONLOAD'] ? 'true' : 'false'
	)
);

/* ========================================================================== */
/* Sender verification: profile-aware, fail-closed                             */
/* ========================================================================== */

/*
 * CheckUser is a GİB e-Invoice REGISTRY lookup. EDM's own connector library
 * names it "Vergi Kimlik No ile e-fatura mükellefi arama"
 * (EFaturaEDMConnectorLibrary.cs :: CheckUser_byIdentifier), so an e-Archive
 * sender has no reason to appear in it and its emptiness must not block. For
 * e-Archive the authority is the independent portal fixture; for e-Invoice the
 * registry stays mandatory and is not relaxed.
 */
$portal_fixture = kuka_sandbox_expected_sender_fixture();

// The fixture must be a constant. If it were ever derived from a caller, the
// comparison would be a value against itself and would prove nothing.
$fixture_call_a = kuka_sandbox_expected_sender_fixture();
$fixture_call_b = kuka_sandbox_expected_sender_fixture();

$fixture_source     = (string) file_get_contents( __DIR__ . '/lib-edm-sandbox.php' );
$fixture_fn_start   = strpos( $fixture_source, 'function kuka_sandbox_expected_sender_fixture(' );
$fixture_fn_body    = false !== $fixture_fn_start
	? substr( $fixture_source, $fixture_fn_start, ( strpos( $fixture_source, "\n}\n", $fixture_fn_start ) ?: $fixture_fn_start ) - $fixture_fn_start )
	: '';
// Anything that could pull a value in from outside the literal.
$fixture_taints = array();
foreach ( array( '$config', '$facts', '$loaded', 'get_sender_', 'getenv', 'get_option', 'KUKA_EDM_SENDER', 'func_get_arg', '$_' ) as $taint ) {
	if ( '' !== $fixture_fn_body && str_contains( $fixture_fn_body, $taint ) ) {
		$fixture_taints[] = $taint;
	}
}

$report(
	'SANDBOX_SENDER_FIXTURE_IS_INDEPENDENT',
	$fixture_call_a === $fixture_call_b
	&& $fixture_call_a === $portal_fixture
	&& 7 === count( $portal_fixture )
	&& '' !== $fixture_fn_body
	// Takes no argument at all, so nothing can be injected.
	&& 0 === ( new ReflectionFunction( 'kuka_sandbox_expected_sender_fixture' ) )->getNumberOfParameters()
	&& array() === $fixture_taints
	&& ! array_key_exists( 'sender_postcode', $portal_fixture ),
	sprintf(
		'fields:%d|deterministic:%s|parameters:%d|derives_from_config:%s|postcode_listed:%s',
		count( $portal_fixture ),
		$fixture_call_a === $fixture_call_b ? 'yes' : 'no',
		( new ReflectionFunction( 'kuka_sandbox_expected_sender_fixture' ) )->getNumberOfParameters(),
		empty( $fixture_taints ) ? 'no' : implode( ',', $fixture_taints ),
		array_key_exists( 'sender_postcode', $portal_fixture ) ? 'yes' : 'no'
	)
);

// The fixture is released only for a proved TEST endpoint.
$fixture_gate_cases = array(
	'test_label_verified_url' => array( $good_endpoint, 'test', true ),
	'live_label_verified_url' => array( $good_endpoint, 'live', false ),
	'test_label_live_url'     => array( $bad_endpoint, 'test', false ),
	'live_label_live_url'     => array( $bad_endpoint, 'live', false ),
);
$fixture_gate_ok      = true;
$fixture_gate_details = array();
foreach ( $fixture_gate_cases as $case => $spec ) {
	$released = kuka_sandbox_sender_fixture_for( $spec[0], $spec[1] );
	$hit      = $spec[2] ? ( $released === $portal_fixture ) : ( array() === $released );
	$fixture_gate_details[ $case ] = $hit ? ( $spec[2] ? 'released' : 'withheld' ) : 'WRONG';
	if ( ! $hit ) {
		$fixture_gate_ok = false;
	}
}

$report(
	'SANDBOX_SENDER_FIXTURE_TEST_ONLY',
	$fixture_gate_ok,
	implode( '|', array_map( static fn( string $k, string $v ): string => $k . ':' . $v, array_keys( $fixture_gate_details ), $fixture_gate_details ) )
);

/*
 * Behaviour matrix. The company block starts as an exact copy of the portal
 * fixture, plus the postcode the portal does not publish.
 */
$complete_company = array_merge( $portal_fixture, array( 'sender_postcode' => '' ) );

$earchive_defaults = kuka_sandbox_resolve_defaults( 'test', $good_endpoint, '', '', '9999999999' );
$einvoice_defaults = kuka_sandbox_resolve_defaults( 'test', $good_endpoint, 'TICARIFATURA', '', '9999999999' );

$earchive_facts = array(
	'defaults'         => $earchive_defaults,
	'series'           => kuka_sandbox_resolve_series( '', array(), true ),
	// Measured reality on the EDM test account: the call succeeds, the registry
	// returns nothing.
	'check_user_ok'    => false,
	'edm_alias'        => '',
	'configured_alias' => $portal_fixture['sender_alias'],
	'company_fields'   => $complete_company,
	'sender_fixture'   => $portal_fixture,
);

$einvoice_facts = array_merge(
	$earchive_facts,
	array(
		'defaults'      => $einvoice_defaults,
		'check_user_ok' => true,
		'edm_alias'     => $portal_fixture['sender_alias'],
	)
);

/**
 * Mutate one company field by a single character.
 *
 * @param array<string, string> $company Company block.
 * @param string                $field   Field to disturb.
 * @return array<string, string>
 */
$one_char_off = static function ( array $company, string $field ): array {
	$company[ $field ] = $company[ $field ] . 'X';

	return $company;
};

$matrix = array(
	// 1. e-Archive, correct fixture, empty CheckUser -> PASS.
	'earchive_fixture_match_checkuser_empty' => array( $earchive_facts, true, '' ),
	// 2-5. One character off in each portal field -> BLOCKED.
	'earchive_vkn_one_char_off'              => array( array_merge( $earchive_facts, array( 'company_fields' => $one_char_off( $complete_company, 'sender_vkn' ) ) ), false, 'sender_matches_portal_fixture' ),
	'earchive_alias_one_char_off'            => array( array_merge( $earchive_facts, array( 'company_fields' => $one_char_off( $complete_company, 'sender_alias' ) ) ), false, 'sender_matches_portal_fixture' ),
	'earchive_title_differs'                 => array( array_merge( $earchive_facts, array( 'company_fields' => array_merge( $complete_company, array( 'sender_title' => 'BASKA UNVAN A.S.' ) ) ) ), false, 'sender_matches_portal_fixture' ),
	'earchive_tax_office_differs'            => array( array_merge( $earchive_facts, array( 'company_fields' => $one_char_off( $complete_company, 'sender_tax_office' ) ) ), false, 'sender_matches_portal_fixture' ),
	'earchive_address_differs'               => array( array_merge( $earchive_facts, array( 'company_fields' => $one_char_off( $complete_company, 'sender_address' ) ) ), false, 'sender_matches_portal_fixture' ),
	'earchive_district_differs'              => array( array_merge( $earchive_facts, array( 'company_fields' => $one_char_off( $complete_company, 'sender_district' ) ) ), false, 'sender_matches_portal_fixture' ),
	'earchive_city_differs'                  => array( array_merge( $earchive_facts, array( 'company_fields' => $one_char_off( $complete_company, 'sender_city' ) ) ), false, 'sender_matches_portal_fixture' ),
	// 6. Missing required company field -> BLOCKED.
	'earchive_missing_required_field'        => array( array_merge( $earchive_facts, array( 'company_fields' => array_merge( $complete_company, array( 'sender_city' => '' ) ) ) ), false, 'company_fields_complete' ),
	// Fixture withheld (unproved endpoint) -> BLOCKED, never silently skipped.
	'earchive_fixture_unavailable'           => array( array_merge( $earchive_facts, array( 'sender_fixture' => array() ) ), false, 'sender_matches_portal_fixture' ),
	// 7-9. e-Invoice keeps the registry requirement.
	'einvoice_checkuser_empty'               => array( array_merge( $einvoice_facts, array( 'check_user_ok' => false, 'edm_alias' => '' ) ), false, 'check_user_ok' ),
	'einvoice_alias_differs'                 => array( array_merge( $einvoice_facts, array( 'edm_alias' => $portal_fixture['sender_alias'] . 'X' ) ), false, 'alias_exact_match' ),
	'einvoice_alias_exact'                   => array( $einvoice_facts, true, '' ),
	// 10. A test label over a live endpoint resolves nothing -> BLOCKED.
	'live_endpoint_under_test_label'         => array(
		array_merge(
			$earchive_facts,
			array(
				'defaults'       => kuka_sandbox_resolve_defaults( 'test', $bad_endpoint, '', '', '9999999999' ),
				'sender_fixture' => kuka_sandbox_sender_fixture_for( $bad_endpoint, 'test' ),
			)
		),
		false,
		'profile_id_resolved',
	),
	// 12. Empty postcode must not block.
	'earchive_postcode_empty'                => array( $earchive_facts, true, '' ),
	// 13. Empty series must not block.
	'earchive_series_empty'                  => array( array_merge( $earchive_facts, array( 'series' => kuka_sandbox_resolve_series( '', array(), false ) ) ), true, '' ),
);

$matrix_ok      = true;
$matrix_details = array();
foreach ( $matrix as $case => $spec ) {
	$verdict = kuka_sandbox_verify_sender( $spec[0] );
	$hit     = $verdict['ok'] === $spec[1]
		&& ( '' === $spec[2] || in_array( $spec[2], $verdict['failed'], true ) );

	// A blocked case must always produce actionable guidance, and guidance must
	// never contain a configured value -- only field names.
	$guidance = implode( ' ', kuka_sandbox_sender_guidance( $verdict ) );
	if ( ! $verdict['ok'] && '' === trim( $guidance ) ) {
		$hit = false;
	}
	foreach ( array( $portal_fixture['sender_alias'], $portal_fixture['sender_title'], $portal_fixture['sender_vkn'] ) as $value ) {
		if ( str_contains( $guidance, $value ) ) {
			$hit = false;
		}
	}

	$matrix_details[ $case ] = $hit ? ( $verdict['ok'] ? 'PASS' : 'blocked' ) : ( 'WRONG(' . ( $verdict['ok'] ? 'pass' : implode( '+', $verdict['failed'] ) ) . ')' );
	if ( ! $hit ) {
		$matrix_ok = false;
	}
}

$report(
	'SANDBOX_SENDER_PROFILE_MATRIX',
	$matrix_ok,
	sprintf(
		'cases:%d|wrong:%s',
		count( $matrix ),
		implode( ',', array_keys( array_filter( $matrix_details, static fn( string $v ): bool => str_starts_with( $v, 'WRONG' ) ) ) ) ?: 'none'
	)
);

// The information labels the report must carry, verbatim and machine-testable.
$earchive_pass = kuka_sandbox_verify_sender( $earchive_facts );
$einvoice_pass = kuka_sandbox_verify_sender( $einvoice_facts );

$report(
	'SANDBOX_SENDER_IDENTITY_SOURCE_LABELS',
	'portal_verified_test_fixture' === $earchive_pass['info']['sender_identity_source']
	&& 'einvoice_registry_lookup' === $earchive_pass['info']['check_user_role']
	&& 'not_applicable_for_earchive_sender' === $earchive_pass['info']['check_user_requirement']
	&& 'user_entry_absent' === $earchive_pass['info']['check_user_result']
	&& 'earchive' === $earchive_pass['profile']
	// The e-Invoice side keeps the registry as its authority.
	&& 'edm_checkuser_registry_alias' === $einvoice_pass['info']['sender_identity_source']
	&& 'required_for_einvoice_sender' === $einvoice_pass['info']['check_user_requirement']
	&& 'einvoice' === $einvoice_pass['profile']
	// CheckUser is never a blocking check on the e-Archive path.
	&& ! array_key_exists( 'check_user_ok', $earchive_pass['checks'] )
	&& ! array_key_exists( 'alias_exact_match', $earchive_pass['checks'] )
	&& array_key_exists( 'check_user_ok', $einvoice_pass['checks'] ),
	sprintf(
		'earchive:sender_identity_source=%s,check_user_role=%s,check_user_requirement=%s,check_user_result=%s,check_user_blocking=%s|einvoice:sender_identity_source=%s,check_user_requirement=%s,check_user_blocking=%s',
		$earchive_pass['info']['sender_identity_source'],
		$earchive_pass['info']['check_user_role'],
		$earchive_pass['info']['check_user_requirement'],
		$earchive_pass['info']['check_user_result'],
		array_key_exists( 'check_user_ok', $earchive_pass['checks'] ) ? 'yes' : 'no',
		$einvoice_pass['info']['sender_identity_source'],
		$einvoice_pass['info']['check_user_requirement'],
		array_key_exists( 'check_user_ok', $einvoice_pass['checks'] ) ? 'yes' : 'no'
	)
);

// Guidance must reflect the check that actually failed, never the old blanket
// "supply every sender field" text.
$guidance_complete_but_mismatched = kuka_sandbox_sender_guidance(
	kuka_sandbox_verify_sender( array_merge( $earchive_facts, array( 'company_fields' => $one_char_off( $complete_company, 'sender_city' ) ) ) )
);
$guidance_missing = kuka_sandbox_sender_guidance(
	kuka_sandbox_verify_sender( array_merge( $earchive_facts, array( 'company_fields' => array_merge( $complete_company, array( 'sender_city' => '' ) ) ) ) )
);
$guidance_registry = kuka_sandbox_sender_guidance(
	kuka_sandbox_verify_sender( array_merge( $einvoice_facts, array( 'check_user_ok' => false, 'edm_alias' => '' ) ) )
);

$mismatch_text = implode( ' ', $guidance_complete_but_mismatched );
$missing_text  = implode( ' ', $guidance_missing );
$registry_text = implode( ' ', $guidance_registry );

$report(
	'SANDBOX_SENDER_GUIDANCE_IS_ACCURATE',
	// A field-value mismatch names the field and does NOT ask for missing ones.
	str_contains( $mismatch_text, 'sender_city' )
	&& ! str_contains( $mismatch_text, 'Missing sender field' )
	// A genuinely absent field asks for exactly that field.
	&& str_contains( $missing_text, 'Missing sender field' )
	&& str_contains( $missing_text, 'sender_city' )
	// An empty registry answer is described as a GİB registration matter.
	&& str_contains( $registry_text, 'GİB e-Invoice registry' )
	// The e-Archive path never presents an empty registry answer as an error.
	&& str_contains( $mismatch_text, 'not a failure' )
	&& array() === kuka_sandbox_sender_guidance( $earchive_pass ),
	sprintf(
		'mismatch_names_field:%s|mismatch_avoids_missing_text:%s|missing_asks_for_field:%s|registry_explained:%s|passing_run_silent:%s',
		str_contains( $mismatch_text, 'sender_city' ) ? 'yes' : 'no',
		str_contains( $mismatch_text, 'Missing sender field' ) ? 'NO' : 'yes',
		str_contains( $missing_text, 'sender_city' ) ? 'yes' : 'no',
		str_contains( $registry_text, 'GİB e-Invoice registry' ) ? 'yes' : 'no',
		array() === kuka_sandbox_sender_guidance( $earchive_pass ) ? 'yes' : 'no'
	)
);

$good_facts = $earchive_facts;

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
	// A confirmed record must carry the assigned number, otherwise status()
	// classifies it as corrupt (proved separately by the confirmed_no_number
	// fixture below).
	$c->settle( $terminal, Kuka_Sandbox_Claim::S_CONFIRMED === $terminal ? array( 'assigned_number' => 'KUK2026000000001' ) : array() );
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

/*
 * Production numbering guard untouched by the sandbox work.
 *
 * The contract it enforces has moved on -- EDM confirmed that the submitted UBL
 * asks for automatic numbering with a fixed sentinel and returns the number it
 * assigned -- but the two guarantees that matter here are the same ones:
 * nothing local is ever accepted as a fiscal number, and the serial prefix comes
 * only from the reviewed configuration.
 */
$numbering_src = (string) file_get_contents( $module_dir . 'class-invoice-numbering.php' );
$store_src     = (string) file_get_contents( $module_dir . 'class-invoice-order-store.php' );
$numbering_guards = array(
	'sentinel_declared'      => str_contains( $numbering_src, "AUTO_NUMBER_SENTINEL = 'ABC2009123456789'" ),
	'series_fail_closed'     => str_contains( $numbering_src, "ERROR_SERIES_UNCONFIGURED = 'invoice_series_unconfigured'" ),
	'provenance_required'    => str_contains( $numbering_src, 'NUMBER_SOURCE_EDM !== $source' ),
	'sentinel_never_stored'  => str_contains( $store_src, 'Kuka_Island_Core_Invoice_Numbering::is_auto_number_sentinel( $number )' ),
);
$numbering_gaps = array_keys( array_filter( $numbering_guards, static fn( bool $ok ): bool => ! $ok ) );

$report(
	'SANDBOX_NUMBERING_GUARD_UNTOUCHED',
	array() === $numbering_gaps,
	sprintf(
		'guards:%d|missing:%s',
		count( $numbering_guards ),
		empty( $numbering_gaps ) ? 'none' : implode( ',', $numbering_gaps )
	)
);

/* ========================================================================== */
/* Corrupt state records are fail-closed                                        */
/* ========================================================================== */

$state_root = rtrim( sys_get_temp_dir(), '/' ) . '/kuka-sandbox-state-' . bin2hex( random_bytes( 6 ) );
mkdir( $state_root, 0700, true );

/**
 * Mocked transport that counts every call. No network, no EDM.
 */
final class Kuka_Sandbox_Mock_Transport implements Kuka_Island_Core_SOAP_Transport_Interface {
	public int $calls = 0;
	/** @var array<int, string> */
	public array $operations = array();
	/** @var callable */
	private $responder;

	public function __construct( callable $responder ) {
		$this->responder = $responder;
	}

	public function call( string $action, array $parameters ) {
		++$this->calls;
		$this->operations[] = $action;

		return ( $this->responder )( $action, $parameters );
	}

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}
}

$make_state_file = static function ( string $root, string $name, ?string $contents ): string {
	$file = $root . '/' . $name . '.json';
	if ( null !== $contents ) {
		file_put_contents( $file, $contents );
		chmod( $file, 0600 );
	}
	return $file;
};

$state_cases = array(
	'missing_file'       => array( 'contents' => null, 'expect_state' => Kuka_Sandbox_Claim::S_IDLE, 'expect_reason' => 'no_state_file' ),
	'empty_file'         => array( 'contents' => '', 'expect_state' => Kuka_Sandbox_Claim::S_CORRUPT, 'expect_reason' => 'state_file_empty' ),
	'whitespace_file'    => array( 'contents' => "   \n\t ", 'expect_state' => Kuka_Sandbox_Claim::S_CORRUPT, 'expect_reason' => 'state_file_empty' ),
	'malformed_json'     => array( 'contents' => '{"state": "idle"', 'expect_state' => Kuka_Sandbox_Claim::S_CORRUPT, 'expect_reason' => 'state_file_invalid_json' ),
	'json_not_object'    => array( 'contents' => '"just a string"', 'expect_state' => Kuka_Sandbox_Claim::S_CORRUPT, 'expect_reason' => 'state_file_invalid_json' ),
	'missing_state_key'  => array( 'contents' => '{"uuid":"x","operation":"LoadInvoice"}', 'expect_state' => Kuka_Sandbox_Claim::S_CORRUPT, 'expect_reason' => 'state_field_missing' ),
	'unknown_state'      => array( 'contents' => '{"state":"halfway","uuid":"x","operation":"LoadInvoice"}', 'expect_state' => Kuka_Sandbox_Claim::S_CORRUPT, 'expect_reason' => 'unknown_state_value' ),
	'partial_in_flight'  => array( 'contents' => '{"state":"in_flight"}', 'expect_state' => Kuka_Sandbox_Claim::S_CORRUPT, 'expect_reason' => 'missing_uuid_for_state_in_flight' ),
	'in_flight_no_op'    => array( 'contents' => '{"state":"in_flight","uuid":"abc"}', 'expect_state' => Kuka_Sandbox_Claim::S_CORRUPT, 'expect_reason' => 'missing_operation_for_state_in_flight' ),
	'confirmed_no_number' => array( 'contents' => '{"state":"confirmed","uuid":"abc","operation":"LoadInvoice"}', 'expect_state' => Kuka_Sandbox_Claim::S_CORRUPT, 'expect_reason' => 'confirmed_without_assigned_number' ),
	'valid_idle'         => array( 'contents' => '{"state":"idle"}', 'expect_state' => Kuka_Sandbox_Claim::S_IDLE, 'expect_reason' => 'ok' ),
);

$state_ok      = true;
$state_details = array();
foreach ( $state_cases as $case => $spec ) {
	$file    = $make_state_file( $state_root, $case, $spec['contents'] );
	$probe   = new Kuka_Sandbox_Claim( $file );
	$status  = $probe->status();
	$hit     = $status['state'] === $spec['expect_state'] && $status['reason'] === $spec['expect_reason'];
	$state_details[ $case ] = $hit ? $status['state'] : ( 'MISMATCH(' . $status['state'] . '/' . $status['reason'] . ')' );
	if ( ! $hit ) {
		$state_ok = false;
	}
}

$report(
	'SANDBOX_STATE_CORRUPTION_FAIL_CLOSED',
	$state_ok,
	sprintf(
		'cases:%d|%s',
		count( $state_cases ),
		implode( ' ', array_map( static fn( string $k, string $v ): string => $k . '=' . $v, array_keys( $state_details ), $state_details ) )
	)
);

// A corrupt record must stop the write path before any transport call.
$corrupt_calls = array();
$corrupt_ok    = true;
foreach ( array( 'empty_file', 'malformed_json', 'unknown_state', 'partial_in_flight' ) as $case ) {
	$claim_c   = new Kuka_Sandbox_Claim( $state_root . '/' . $case . '.json' );
	$claim_c->acquire();
	$transport = new Kuka_Sandbox_Mock_Transport(
		static function (): array {
			return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}
	);
	$result    = kuka_sandbox_execute_write( $claim_c, $transport, array(), $uuid, 'LoadInvoice' );
	$claim_c->release();
	$hit                     = 0 === $transport->calls && false === $result['claimed'] && false === $result['call_attempted'];
	$corrupt_calls[ $case ] = $hit ? 'calls=0' : 'LEAKED(calls=' . $transport->calls . ')';
	if ( ! $hit ) {
		$corrupt_ok = false;
	}
}
$report(
	'SANDBOX_CORRUPT_STATE_BLOCKS_WRITE',
	$corrupt_ok,
	implode( '|', array_map( static fn( string $k, string $v ): string => $k . ':' . $v, array_keys( $corrupt_calls ), $corrupt_calls ) )
);

/* ========================================================================== */
/* Call classification: only a complete rejection is definitive                 */
/* ========================================================================== */

$classification_cases = array(
	'nonzero_return_code'   => array(
		'response' => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 5 ), 'INVOICE' => array( array( 'UUID' => $uuid, 'ID' => 'X' ) ) ),
		'expect'   => KUKA_SANDBOX_CALL_DEFINITIVE,
	),
	'success_missing_invoice' => array(
		'response' => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) ),
		'expect'   => KUKA_SANDBOX_CALL_UNCERTAIN,
	),
	'success_empty_id'      => array(
		'response' => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ), 'INVOICE' => array( array( 'UUID' => $uuid, 'ID' => '' ) ) ),
		'expect'   => KUKA_SANDBOX_CALL_UNCERTAIN,
	),
	'success_uuid_missing'  => array(
		'response' => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ), 'INVOICE' => array( array( 'ID' => 'X' ) ) ),
		'expect'   => KUKA_SANDBOX_CALL_UNCERTAIN,
	),
	'success_uuid_mismatch' => array(
		'response' => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ), 'INVOICE' => array( array( 'UUID' => 'ffffffff-0000-4000-8000-000000000000', 'ID' => 'X' ) ) ),
		'expect'   => KUKA_SANDBOX_CALL_UNCERTAIN,
	),
	'off_schema_success'    => array(
		'response' => array( 'RESULT' => 'OK' ),
		'expect'   => KUKA_SANDBOX_CALL_UNCERTAIN,
	),
	'unparseable'           => array(
		'response' => 'not xml, not a struct',
		'expect'   => KUKA_SANDBOX_CALL_UNCERTAIN,
	),
	'clean_success'         => array(
		'response' => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ), 'INVOICE' => array( array( 'UUID' => $uuid, 'ID' => 'KUK2026000000999' ) ) ),
		'expect'   => KUKA_SANDBOX_CALL_SUCCESS,
	),
);

$class_ok      = true;
$class_details = array();
foreach ( $classification_cases as $case => $spec ) {
	$parsed = kuka_sandbox_parse_load_invoice_response( $spec['response'], $uuid );
	$actual = kuka_sandbox_classify_call( $parsed, false );
	$hit    = $actual === $spec['expect'];
	$class_details[ $case ] = $hit ? $actual : ( 'MISMATCH(' . $actual . ')' );
	if ( ! $hit ) {
		$class_ok = false;
	}
}
// A thrown transport call is always uncertain, never definitive.
$thrown = kuka_sandbox_classify_call( null, true );
if ( KUKA_SANDBOX_CALL_UNCERTAIN !== $thrown ) {
	$class_ok                        = false;
	$class_details['transport_threw'] = 'MISMATCH(' . $thrown . ')';
} else {
	$class_details['transport_threw'] = $thrown;
}

$report(
	'SANDBOX_CALL_CLASSIFICATION',
	$class_ok,
	sprintf(
		'cases:%d|%s',
		count( $class_details ),
		implode( ' ', array_map( static fn( string $k, string $v ): string => $k . '=' . $v, array_keys( $class_details ), $class_details ) )
	)
);

/* ========================================================================== */
/* Driver-level write path with a mocked transport                              */
/* ========================================================================== */

$drive = static function ( string $name, callable $responder ) use ( $state_root, $uuid ): array {
	$file      = $state_root . '/drive-' . $name . '.json';
	$claim     = new Kuka_Sandbox_Claim( $file );
	$claim->acquire();
	$transport = new Kuka_Sandbox_Mock_Transport( $responder );
	$result    = kuka_sandbox_execute_write( $claim, $transport, array( 'x' => 1 ), $uuid, 'LoadInvoice' );
	$state     = $claim->state();
	$claim->release();

	return array(
		'result'    => $result,
		'calls'     => $transport->calls,
		'end_state' => $state,
		'file'      => $file,
	);
};

$ok_response = static fn(): array => array(
	'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
	'INVOICE'        => array( array( 'UUID' => $uuid, 'ID' => 'KUK2026000000777' ) ),
);

$driver_cases = array(
	'success'             => array(
		'responder' => $ok_response,
		'expect'    => array( 'verdict' => 'PASS', 'label' => 'draft_uploaded', 'state' => Kuka_Sandbox_Claim::S_CONFIRMED, 'exit' => 0, 'calls' => 1 ),
	),
	'nonzero_code'        => array(
		'responder' => static fn(): array => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 9 ) ),
		'expect'    => array( 'verdict' => 'FAIL', 'label' => 'failed_definitive', 'state' => Kuka_Sandbox_Claim::S_FAILED_DEFINITIVE, 'exit' => 1, 'calls' => 1 ),
	),
	'empty_id'            => array(
		'responder' => static fn(): array => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ), 'INVOICE' => array( array( 'UUID' => $uuid, 'ID' => '' ) ) ),
		'expect'    => array( 'verdict' => 'FAIL', 'label' => 'uncertain_manual_reconciliation_required', 'state' => Kuka_Sandbox_Claim::S_UNCERTAIN, 'exit' => 1, 'calls' => 1 ),
	),
	'uuid_mismatch'       => array(
		'responder' => static fn(): array => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ), 'INVOICE' => array( array( 'UUID' => 'ffffffff-0000-4000-8000-000000000000', 'ID' => 'X' ) ) ),
		'expect'    => array( 'verdict' => 'FAIL', 'label' => 'uncertain_manual_reconciliation_required', 'state' => Kuka_Sandbox_Claim::S_UNCERTAIN, 'exit' => 1, 'calls' => 1 ),
	),
	'missing_invoice'     => array(
		'responder' => static fn(): array => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) ),
		'expect'    => array( 'verdict' => 'FAIL', 'label' => 'uncertain_manual_reconciliation_required', 'state' => Kuka_Sandbox_Claim::S_UNCERTAIN, 'exit' => 1, 'calls' => 1 ),
	),
	'transport_exception' => array(
		'responder' => static function (): array {
			throw new SoapFault( 'HTTP', 'Connection timed out' );
		},
		'expect'    => array( 'verdict' => 'FAIL', 'label' => 'uncertain_manual_reconciliation_required', 'state' => Kuka_Sandbox_Claim::S_UNCERTAIN, 'exit' => 1, 'calls' => 1 ),
	),
);

$driver_ok      = true;
$driver_details = array();
foreach ( $driver_cases as $case => $spec ) {
	$run    = $drive( $case, $spec['responder'] );
	$expect = $spec['expect'];
	$hit    = $run['result']['create_verdict'] === $expect['verdict']
		&& $run['result']['result_label'] === $expect['label']
		&& $run['end_state'] === $expect['state']
		&& $run['result']['exit_code'] === $expect['exit']
		&& $run['calls'] === $expect['calls'];
	// The label is shown alongside the record state so a successful LoadInvoice
	// reads as an uploaded draft rather than a sent invoice.
	$driver_details[ $case ] = $hit
		? $expect['verdict'] . '/' . $expect['label'] . '/record=' . $expect['state']
		: sprintf( 'MISMATCH(%s/%s/%s/exit=%d/calls=%d)', $run['result']['create_verdict'], $run['result']['result_label'], $run['end_state'], $run['result']['exit_code'], $run['calls'] );
	if ( ! $hit ) {
		$driver_ok = false;
	}
}

$report(
	'SANDBOX_DRIVER_WRITE_PATH',
	$driver_ok,
	sprintf(
		'cases:%d|%s',
		count( $driver_cases ),
		implode( ' ', array_map( static fn( string $k, string $v ): string => $k . '=' . $v, array_keys( $driver_details ), $driver_details ) )
	)
);

// Successful call whose confirmed state cannot be persisted: never PASS, never
// confirmed, record stays in_flight, second write refused, non-zero exit.
$persist_dir = $state_root . '/persistfail';
mkdir( $persist_dir, 0700, true );
$persist_file  = $persist_dir . '/state.json';
$persist_claim = new Kuka_Sandbox_Claim( $persist_file, $state_root . '/persistfail.lock' );
$persist_claim->acquire();
$persist_transport = new Kuka_Sandbox_Mock_Transport(
	static function () use ( $persist_dir, $uuid ): array {
		// The directory becomes unwritable between claim and settle.
		chmod( $persist_dir, 0500 );
		return array(
			'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			'INVOICE'        => array( array( 'UUID' => $uuid, 'ID' => 'KUK2026000000888' ) ),
		);
	}
);
$persist_result = kuka_sandbox_execute_write( $persist_claim, $persist_transport, array(), $uuid, 'LoadInvoice' );
chmod( $persist_dir, 0700 );
$persist_state  = $persist_claim->state();
$persist_second = $persist_claim->claim( $uuid, 'LoadInvoice' );
$persist_claim->release();

$report(
	'SANDBOX_SETTLE_PERSIST_FAILURE_NOT_CONFIRMED',
	1 === $persist_transport->calls
	&& KUKA_SANDBOX_CALL_SUCCESS === $persist_result['classification']
	&& 'PASS' !== $persist_result['create_verdict']
	&& 'confirmed' !== $persist_result['result_label']
	&& 'draft_uploaded' !== $persist_result['result_label']
	&& 'state_persist_failed_manual_reconciliation_required' === $persist_result['status_token']
	&& false === $persist_result['state_recorded']
	&& 1 === $persist_result['exit_code']
	&& 'KUK2026000000888' === $persist_result['assigned_number']
	&& Kuka_Sandbox_Claim::S_IN_FLIGHT === $persist_state
	&& false === $persist_second['ok'],
	sprintf(
		'calls:%d|classification:%s|verdict:%s|label:%s|state_recorded:%s|exit:%d|number_available:%s|record_state:%s|second_write:%s',
		$persist_transport->calls,
		$persist_result['classification'],
		$persist_result['create_verdict'],
		$persist_result['result_label'],
		$persist_result['state_recorded'] ? 'yes' : 'no',
		$persist_result['exit_code'],
		'' !== $persist_result['assigned_number'] ? 'yes' : 'no',
		$persist_state,
		$persist_second['ok'] ? 'ALLOWED' : 'refused'
	)
);

// An uncertain record refuses a second write and makes no call.
$uncertain_file  = $state_root . '/drive-empty_id.json';
$uncertain_claim = new Kuka_Sandbox_Claim( $uncertain_file );
$uncertain_claim->acquire();
$uncertain_transport = new Kuka_Sandbox_Mock_Transport( static fn(): array => array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) ) );
$uncertain_second    = kuka_sandbox_execute_write( $uncertain_claim, $uncertain_transport, array(), $uuid, 'LoadInvoice' );
$uncertain_claim->release();

$report(
	'SANDBOX_UNCERTAIN_SECOND_RUN_NO_WRITE',
	0 === $uncertain_transport->calls
	&& false === $uncertain_second['claimed']
	&& false === $uncertain_second['call_attempted']
	&& str_contains( $uncertain_second['claim_reason'], 'uncertain' ),
	sprintf( 'calls:%d|claimed:%s|reason:%s', $uncertain_transport->calls, $uncertain_second['claimed'] ? 'yes' : 'no', $uncertain_second['claim_reason'] )
);

/* ========================================================================== */
/* LoadInvoice is a DRAFT upload; SendInvoice is a separate, uncalled operation  */
/* ========================================================================== */

$driver_src = (string) file_get_contents( __DIR__ . '/edm-sandbox-invoice.php' );
$lib_src    = (string) file_get_contents( __DIR__ . '/lib-edm-sandbox.php' );

// The only operation name the write path may ever pass to the transport.
$transport_ops = array();
if ( preg_match_all( '/\$transport->call\(\s*\x27([A-Za-z]+)\x27/', $lib_src . $driver_src, $m ) ) {
	$transport_ops = array_values( array_unique( $m[1] ) );
}

$forbidden_ops = array();
foreach ( array( 'SendInvoice', 'CancelInvoice', 'EmailInvoice', 'CreateSerial' ) as $forbidden ) {
	// Mentioning SendInvoice in prose is fine; calling it is not.
	if ( preg_match( '/->\s*call\(\s*\x27' . $forbidden . '\x27/', $lib_src . $driver_src )
		|| preg_match( '/->\s*' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $forbidden ) ) . '\(/', $driver_src ) ) {
		$forbidden_ops[] = $forbidden;
	}
}

// A successful upload is labelled a draft, never "sent" or "issued".
$success_report = kuka_sandbox_resolve_report( KUKA_SANDBOX_CALL_SUCCESS, array( 'written' => true ) );

$report(
	'SANDBOX_LOAD_VS_SEND_SEMANTICS',
	array( 'LoadInvoice' ) === $transport_ops
	&& array() === $forbidden_ops
	&& 'draft_uploaded' === $success_report['result_label']
	&& 'PASS' === $success_report['create_verdict']
	&& str_contains( $driver_src, 'SANDBOX_SENDINVOICE=NOT_EXECUTED' )
	&& str_contains( $driver_src, 'SANDBOX_DRAFT_UPLOAD' )
	&& ! str_contains( $driver_src, 'SANDBOX_CREATE' ),
	sprintf(
		'transport_operations:%s|forbidden_calls:%s|success_label:%s|sendinvoice_line:%s|draft_step:%s',
		implode( ',', $transport_ops ) ?: 'none',
		empty( $forbidden_ops ) ? 'none' : implode( ',', $forbidden_ops ),
		$success_report['result_label'],
		str_contains( $driver_src, 'SANDBOX_SENDINVOICE=NOT_EXECUTED' ) ? 'present' : 'absent',
		str_contains( $driver_src, 'SANDBOX_DRAFT_UPLOAD' ) ? 'present' : 'absent'
	)
);

/*
 * With no serial configured the write path still runs, and the request it hands
 * the transport carries GENERATEINVOICEIDONLOAD = true, no
 * INVOICESERIAL_REQUESTED and no INVOICE/@ID. Proved against the mocked
 * transport, so the exact payload EDM would receive is inspected.
 */
$captured        = null;
$noseries_series = kuka_sandbox_resolve_series( '', array(), true );
$noseries_req    = kuka_sandbox_build_load_request(
	array_merge(
		$request_ctx,
		array( 'series_code' => $noseries_series['send'] ? $noseries_series['code'] : '' )
	)
);
$noseries_transport = new Kuka_Sandbox_Mock_Transport(
	static function ( string $op, array $payload ) use ( &$captured ): array {
		$captured = array( 'operation' => $op, 'payload' => $payload );

		return array(
			'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			'INVOICE'        => array( array( 'UUID' => $payload['INVOICE'][0]['UUID'], 'ID' => 'EDM2026000000001' ) ),
		);
	}
);
$noseries_claim = new Kuka_Sandbox_Claim( $state_root . '/noseries.json' );
$noseries_claim->acquire();
$noseries_write = kuka_sandbox_execute_write( $noseries_claim, $noseries_transport, $noseries_req, $request_ctx['uuid'], 'LoadInvoice' );
$noseries_claim->release();

$captured_invoice = (array) ( $captured['payload']['INVOICE'][0] ?? array() );
$captured_header  = (array) ( $captured_invoice['HEADER'] ?? array() );

$report(
	'SANDBOX_NO_SERIES_LOAD_REQUEST',
	1 === $noseries_transport->calls
	&& 'LoadInvoice' === ( $captured['operation'] ?? '' )
	&& true === ( $captured['payload']['GENERATEINVOICEIDONLOAD'] ?? null )
	&& ! array_key_exists( 'INVOICESERIAL_REQUESTED', $captured_header )
	&& ! array_key_exists( 'ID', $captured_invoice )
	&& 'PASS' === $noseries_write['create_verdict']
	&& 'draft_uploaded' === $noseries_write['result_label']
	&& 'EDM2026000000001' === $noseries_write['assigned_number'],
	sprintf(
		'calls:%d|operation:%s|generate_invoice_id_on_load:%s|invoiceserial_requested:%s|invoice_id_attribute:%s|verdict:%s|label:%s|edm_assigned_number:%s',
		$noseries_transport->calls,
		(string) ( $captured['operation'] ?? 'none' ),
		true === ( $captured['payload']['GENERATEINVOICEIDONLOAD'] ?? null ) ? 'true' : 'false',
		array_key_exists( 'INVOICESERIAL_REQUESTED', $captured_header ) ? 'present' : 'absent',
		array_key_exists( 'ID', $captured_invoice ) ? 'present' : 'absent',
		$noseries_write['create_verdict'],
		$noseries_write['result_label'],
		'' !== (string) $noseries_write['assigned_number'] ? 'read_back' : 'missing'
	)
);

/*
 * An uncertain write must carry a safe fault classification: the retry is
 * forbidden, so the classification is the operator's only way forward. Only
 * allow-listed tokens may appear -- never the SOAP fault text.
 */
$fault_transport = new Kuka_Sandbox_Mock_Transport(
	static function (): array {
		throw new SoapFault( 's:Client', 'Kullanıcı adı veya şifre hatalı (secret_password_123)' );
	}
);
$fault_claim = new Kuka_Sandbox_Claim( $state_root . '/faultclass.json' );
$fault_claim->acquire();
$fault_write = kuka_sandbox_execute_write( $fault_claim, $fault_transport, array(), $uuid, 'LoadInvoice' );
$fault_claim->release();

$fault_line = array() !== (array) ( $fault_write['fault'] ?? array() )
	? Kuka_Island_Core_EDM_Fault_Classifier::to_safe_line( (array) $fault_write['fault'] )
	: '';

$report(
	'SANDBOX_UNCERTAIN_WRITE_CARRIES_SAFE_FAULT',
	KUKA_SANDBOX_CALL_UNCERTAIN === $fault_write['classification']
	&& array() !== (array) $fault_write['fault']
	&& 1 === preg_match( '/^category:[a-z_]+\|fault_kind:[a-z]+\|marker:[a-z_]+\|retryable:(yes|no)$/', $fault_line )
	// The remote text, including the credential it quoted, never survives.
	&& ! str_contains( $fault_line, 'secret_password_123' )
	&& ! str_contains( (string) wp_json_encode( $fault_write ), 'secret_password_123' )
	&& 1 === $fault_transport->calls,
	sprintf(
		'calls:%d|classification:%s|fault_line_shape:%s|remote_text_leaked:%s',
		$fault_transport->calls,
		$fault_write['classification'],
		1 === preg_match( '/^category:[a-z_]+\|fault_kind:[a-z]+\|marker:[a-z_]+\|retryable:(yes|no)$/', $fault_line ) ? 'ok' : 'BAD',
		str_contains( (string) wp_json_encode( $fault_write ), 'secret_password_123' ) ? 'YES' : 'none'
	)
);

/* ========================================================================== */
/* Reconciliation reset: static contract only                                   */
/* ========================================================================== */

/*
 * SCOPE. These checks are STATIC. They read the driver's source and exercise
 * the claim state machine directly. They do NOT run scripts/edm-sandbox-run.sh
 * and they do NOT run scripts/edm-sandbox-invoice.php, so they cannot and must
 * not be reported as proof that the shipped driver makes no SOAP call.
 *
 * An earlier version of this block built its own copy of the reset out of a
 * closure and passed a "counting transport" it then discarded. That measured
 * nothing: the transport was never reachable from the code under test, so a
 * count of zero was guaranteed regardless of what the real driver did.
 *
 * The behavioural proof lives on the host, in scripts/verify-reset-offline.sh,
 * which drives the real wrapper and the real driver and reads the mounts off
 * docker's actual argument vector:
 *   SANDBOX_RESET_HOST_WRITE_GATE
 *   SANDBOX_RESET_REAL_WRAPPER_DRIVER
 */
$driver_body = (string) file_get_contents( __DIR__ . '/edm-sandbox-invoice.php' );

$pos_reset       = strpos( $driver_body, '$reset_requested = false;' );
$pos_reset_exit  = strpos( $driver_body, "exit( \$reset_result['ok'] ? 0 : 1 );" );
$pos_credentials = strpos( $driver_body, 'kuka_edm_test_credentials(' );
$pos_config      = strpos( $driver_body, 'new Kuka_Island_Core_Invoice_Config(' );
$pos_client      = strpos( $driver_body, 'new Kuka_Island_Core_EDM_Client(' );
$pos_login       = strpos( $driver_body, '$client->login()' );
$pos_serial      = strpos( $driver_body, '$client->get_invoice_serial(' );
$pos_check_user  = strpos( $driver_body, '$client->check_user(' );

$after_reset_exit = array(
	'credentials'      => $pos_credentials,
	'config'           => $pos_config,
	'client'           => $pos_client,
	'login'            => $pos_login,
	'get_invoice_serial' => $pos_serial,
	'check_user'       => $pos_check_user,
);
$too_early = array();
foreach ( $after_reset_exit as $label => $pos ) {
	if ( false !== $pos && ( false === $pos_reset_exit || $pos < $pos_reset_exit ) ) {
		$too_early[] = $label;
	}
}

$report(
	'SANDBOX_RESET_PRECEDES_EVERY_EDM_PATH',
	false !== $pos_reset
	&& false !== $pos_reset_exit
	&& $pos_reset < $pos_reset_exit
	&& array() === $too_early,
	sprintf(
		'measured:source_position|reset_parsed:%s|reset_exits:%s|reachable_before_reset_exit:%s',
		false !== $pos_reset ? 'yes' : 'no',
		false !== $pos_reset_exit ? 'yes' : 'no',
		empty( $too_early ) ? 'none' : implode( ',', $too_early )
	)
);

/*
 * The claim state machine itself, exercised directly. This is a genuine unit
 * test OF Kuka_Sandbox_Claim -- not of the driver -- and is labelled that way.
 */
$reset_root = $state_root . '/reset-state-machine';
mkdir( $reset_root, 0700, true );
$reset_file = $reset_root . '/claim.json';
$reset_lock = $reset_root . '/claim.lock';

$seed_claim = new Kuka_Sandbox_Claim( $reset_file, $reset_lock );
$seed_claim->acquire();
$seed_claim->claim( 'uuid-reset-state-machine-fixture', 'LoadInvoice' );
$seed_claim->settle( Kuka_Sandbox_Claim::S_UNCERTAIN, array( 'outcome' => 'transport_exception' ) );
$seed_claim->release();

$before_record  = json_decode( (string) file_get_contents( $reset_file ), true );
$before_state   = (string) ( $before_record['state'] ?? '' );
$before_uuid    = (string) ( $before_record['uuid'] ?? '' );
$before_history = (array) ( $before_record['history'] ?? array() );

$apply_reset = static function ( string $file, string $lock, string $evidence, string $audit ): array {
	$claim = new Kuka_Sandbox_Claim( $file, $lock );
	if ( ! $claim->acquire() ) {
		return array( 'ok' => false, 'reason' => 'lock_unavailable', 'state' => 'unknown' );
	}
	$result = $claim->reset_after_reconcile( $evidence, $audit );
	$claim->release();

	return $result;
};

$first_reset = $apply_reset( $reset_file, $reset_lock, 'document_absent_at_edm', 'edm_portal_absent' );

$after_record  = json_decode( (string) file_get_contents( $reset_file ), true );
$after_state   = (string) ( $after_record['state'] ?? '' );
$after_uuid    = (string) ( $after_record['uuid'] ?? '' );
$after_history = (array) ( $after_record['history'] ?? array() );

$history_preserved = count( $after_history ) === count( $before_history ) + 1
	&& array_slice( $after_history, 0, count( $before_history ) ) === $before_history;
$appended = (array) end( $after_history );

// A second reset is refused, and a wrong evidence token leaves the file
// byte-identical.
$second_reset = $apply_reset( $reset_file, $reset_lock, 'document_absent_at_edm', '' );

$wrong_root = $state_root . '/reset-wrong-evidence';
mkdir( $wrong_root, 0700, true );
$wrong_file  = $wrong_root . '/claim.json';
$wrong_lock  = $wrong_root . '/claim.lock';
$wrong_claim = new Kuka_Sandbox_Claim( $wrong_file, $wrong_lock );
$wrong_claim->acquire();
$wrong_claim->claim( 'uuid-wrong-evidence', 'LoadInvoice' );
$wrong_claim->settle( Kuka_Sandbox_Claim::S_UNCERTAIN, array( 'outcome' => 'transport_exception' ) );
$wrong_claim->release();

$wrong_before = (string) file_get_contents( $wrong_file );
$wrong_reset  = $apply_reset( $wrong_file, $wrong_lock, 'edm_portal_absent', '' );
$wrong_after  = (string) file_get_contents( $wrong_file );

$report(
	'SANDBOX_RESET_STATE_MACHINE',
	Kuka_Sandbox_Claim::S_UNCERTAIN === $before_state
	&& true === $first_reset['ok']
	&& Kuka_Sandbox_Claim::S_IDLE === $after_state
	&& $before_uuid === $after_uuid
	&& $history_preserved
	&& 'document_absent_at_edm' === ( $appended['evidence'] ?? '' )
	&& 'edm_portal_absent' === ( $appended['audit'] ?? '' )
	&& false === $second_reset['ok']
	&& false === $wrong_reset['ok']
	&& $wrong_before === $wrong_after,
	sprintf(
		'measured:claim_class|from:%s|to:%s|uuid_unchanged:%s|history:%s|second_reset:%s|wrong_evidence_state_unchanged:%s',
		$before_state,
		$after_state,
		$before_uuid === $after_uuid ? 'yes' : 'no',
		$history_preserved ? 'append_only' : 'REWRITTEN',
		false === $second_reset['ok'] ? 'refused' : 'ACCEPTED',
		$wrong_before === $wrong_after ? 'yes' : 'no'
	)
);

/*
 * Wrapper source guard. Also static: the behavioural equivalent is
 * SANDBOX_RESET_REAL_WRAPPER_DRIVER, which reads the mounts off the argument
 * vector docker actually received.
 */
$wrapper_body  = (string) file_get_contents( __DIR__ . '/edm-sandbox-run.sh' );
$reset_branch  = '';
$wrapper_start = strpos( $wrapper_body, 'if [ "$reset_mode" = "yes" ]; then' );
if ( false !== $wrapper_start ) {
	$wrapper_end  = strpos( $wrapper_body, "\nfi\n", $wrapper_start );
	$reset_branch = false !== $wrapper_end ? substr( $wrapper_body, $wrapper_start, $wrapper_end - $wrapper_start ) : '';
}

// The host-side refusal must be positioned before docker, the state directory
// and the credential handling.
$pos_host_gate  = strpos( $wrapper_body, 'if [ "$reset_mode" = "yes" ] && [ "$allow" = "true" ]; then' );
$pos_mkdir      = strpos( $wrapper_body, 'mkdir -p "$state_dir"' );
$pos_first_dock = strpos( $wrapper_body, 'docker compose run' );
$host_gate_first = false !== $pos_host_gate
	&& ( false === $pos_mkdir || $pos_host_gate < $pos_mkdir )
	&& ( false === $pos_first_dock || $pos_host_gate < $pos_first_dock );

$report(
	'SANDBOX_RESET_WRAPPER_MOUNTS_NO_CREDENTIALS',
	'' !== $reset_branch
	&& ! str_contains( $reset_branch, 'edm-test.env' )
	&& ! str_contains( $reset_branch, 'KUKA_EDM_ALLOW_SANDBOX_WRITE' )
	&& str_contains( $reset_branch, '/run/edm/state' )
	&& $host_gate_first
	// The normal paths keep their credential protections.
	&& str_contains( $wrapper_body, 'credentials_file_mode_not_600' )
	&& str_contains( $wrapper_body, '"$cred_file":/run/edm/edm-test.env:ro' ),
	sprintf(
		'measured:wrapper_source|reset_branch_found:%s|credential_mount:%s|write_env_forwarded:%s|state_mount:%s|host_gate_before_docker:%s|normal_path_protections:%s',
		'' !== $reset_branch ? 'yes' : 'no',
		str_contains( $reset_branch, 'edm-test.env' ) ? 'PRESENT' : 'absent',
		str_contains( $reset_branch, 'KUKA_EDM_ALLOW_SANDBOX_WRITE' ) ? 'PRESENT' : 'absent',
		str_contains( $reset_branch, '/run/edm/state' ) ? 'present' : 'ABSENT',
		$host_gate_first ? 'yes' : 'no',
		( str_contains( $wrapper_body, 'credentials_file_mode_not_600' ) && str_contains( $wrapper_body, '"$cred_file":/run/edm/edm-test.env:ro' ) ) ? 'intact' : 'WEAKENED'
	)
);

// Clean up the state fixtures.
foreach ( (array) glob( $state_root . '/*' ) as $leftover ) {
	if ( is_file( $leftover ) ) {
		wp_delete_file( $leftover );
	} elseif ( is_dir( $leftover ) ) {
		foreach ( (array) glob( $leftover . '/*' ) as $inner ) {
			wp_delete_file( $inner );
		}
		rmdir( $leftover );
	}
}
rmdir( $state_root );
$report( 'SANDBOX_STATE_FIXTURES_CLEANED', ! is_dir( $state_root ), sprintf( 'temp_root_removed:%s', is_dir( $state_root ) ? 'no' : 'yes' ) );

/* ========================================================================== */
/* The sandbox and production address the recipient identically                 */
/* ========================================================================== */

/*
 * EDM technical support answered the e-Arşiv addressing contract: the buyer's
 * e-mail goes in the UBL's cbc:ElectronicMail and in INVOICE/HEADER/TO, EDM
 * delivers to it, and RECEIVER/@alias -- a GİB mailbox the recipient does not
 * have -- is omitted entirely.
 *
 * Both write paths now take that shape from one helper,
 * Kuka_Island_Core_EDM_Client::recipient_addressing(). This measures that they
 * really do agree, by comparing the request the PRODUCTION client serialises
 * against the one the sandbox builds -- rather than trusting that they call the
 * same function.
 */
final class Kuka_Sandbox_Addressing_Recorder implements Kuka_Island_Core_SOAP_Transport_Interface {
	/** @var array<string, array<string, mixed>> */
	public array $requests = array();

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}

	public function call( string $operation, array $parameters ) {
		$this->requests[ $operation ] = $parameters;

		if ( 'Login' === $operation ) {
			return array( 'SESSION_ID' => 'session-addressing', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}

		return array(
			'INVOICE'        => array( 'UUID' => 'uuid-addressing', 'ID' => 'EDM2026000009999' ),
			'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
		);
	}
}

$addressing_config = new Kuka_Island_Core_Invoice_Config(
	array(
		'username'      => 'addressing_user',
		'password'      => 'addressing_pass',
		'sender_vkn'    => '1234567890',
		'sender_alias'  => 'urn:mail:box@example.com',
	)
);

/**
 * The RECEIVER block and HEADER/TO a production SendInvoice would serialise.
 *
 * @param Kuka_Island_Core_Invoice_Config $config  Configuration.
 * @param string                          $profile PROFILEID to send under.
 * @param string                          $alias   Recipient alias, if any.
 * @param string                          $email   Buyer e-mail.
 * @return array{receiver: array<string, mixed>, to: string|null}
 */
$production_addressing = static function ( Kuka_Island_Core_Invoice_Config $config, string $profile, string $alias, string $email ): array {
	$recorder = new Kuka_Sandbox_Addressing_Recorder();
	$client   = new Kuka_Island_Core_EDM_Client( $config, $recorder );

	$client->send_invoice(
		array(
			'trx_id'         => 1,
			'uuid'           => 'uuid-addressing',
			'invoice_serial' => '',
			'profile_id'     => $profile,
			'issue_date'     => '2026-01-02',
			'payable_amount' => '120.00',
			'receiver_vkn'   => KUKA_SANDBOX_DOCUMENTED_RECEIVER_VKN,
			'receiver_alias' => $alias,
			'customer_email' => $email,
			'ubl_xml'        => '<Invoice/>',
		)
	);

	$sent   = (array) ( $recorder->requests['SendInvoice'] ?? array() );
	$header = (array) ( $sent['INVOICE'][0]['HEADER'] ?? array() );

	return array(
		'receiver' => (array) ( $sent['RECEIVER'] ?? array() ),
		'to'       => array_key_exists( 'TO', $header ) ? (string) $header['TO'] : null,
	);
};

$prod_earchive = $production_addressing( $addressing_config, KUKA_SANDBOX_DOCUMENTED_PROFILE_ID, '', KUKA_SANDBOX_RECEIVER_EMAIL );

// Reuses the harness's own supplier fixture, so no new sender identity appears.
$addressing_ubl_xml = (string) kuka_sandbox_build_ubl(
	$ubl_supplier_fixture,
	KUKA_SANDBOX_DOCUMENTED_RECEIVER_VKN,
	KUKA_SANDBOX_DOCUMENTED_PROFILE_ID,
	'uuid-addressing'
)['xml'];

$sandbox_request  = kuka_sandbox_build_load_request( array_merge( $request_ctx, array( 'series_code' => '' ) ) );
$sandbox_receiver = (array) ( $sandbox_request['RECEIVER'] ?? array() );
$sandbox_header   = (array) ( $sandbox_request['INVOICE'][0]['HEADER'] ?? array() );
$sandbox_to       = array_key_exists( 'TO', $sandbox_header ) ? (string) $sandbox_header['TO'] : null;

// The e-Fatura branch, to show the helper did not simply drop the alias.
$prod_einvoice = $production_addressing( $addressing_config, 'TICARIFATURA', 'urn:mail:defaultgb@acme.com', KUKA_SANDBOX_RECEIVER_EMAIL );

$report(
	'SANDBOX_MATCHES_PRODUCTION_RECIPIENT_ADDRESSING',
	// e-Arşiv: no alias attribute at all, on either path.
	array( 'vkn' ) === array_keys( $prod_earchive['receiver'] )
	&& array( 'vkn' ) === array_keys( $sandbox_receiver )
	&& ! array_key_exists( 'alias', $prod_earchive['receiver'] )
	&& ! array_key_exists( 'alias', $sandbox_receiver )
	// The buyer's e-mail in HEADER/TO, the same value on both.
	&& KUKA_SANDBOX_RECEIVER_EMAIL === $prod_earchive['to']
	&& KUKA_SANDBOX_RECEIVER_EMAIL === $sandbox_to
	&& $prod_earchive['to'] === $sandbox_to
	&& $prod_earchive['receiver'] === $sandbox_receiver
	// And the UBL the sandbox loads carries that same address, from the same
	// constant that filled HEADER/TO.
	&& str_contains( (string) $addressing_ubl_xml, '<cbc:ElectronicMail>' . KUKA_SANDBOX_RECEIVER_EMAIL . '</cbc:ElectronicMail>' )
	// e-Fatura still addresses a GİB mailbox in both fields.
	&& 'urn:mail:defaultgb@acme.com' === ( $prod_einvoice['receiver']['alias'] ?? '' )
	&& 'urn:mail:defaultgb@acme.com' === $prod_einvoice['to'],
	sprintf(
		'measured:production_client_vs_sandbox_request|earchive_alias:%s|sandbox_alias:%s|earchive_to:%s|sandbox_to:%s|identical:%s|ubl_electronicmail:%s|einvoice_alias:%s|einvoice_to:%s',
		array_key_exists( 'alias', $prod_earchive['receiver'] ) ? 'PRESENT' : 'omitted',
		array_key_exists( 'alias', $sandbox_receiver ) ? 'PRESENT' : 'omitted',
		null === $prod_earchive['to'] ? 'absent' : 'customer_email',
		null === $sandbox_to ? 'absent' : 'customer_email',
		( $prod_earchive['to'] === $sandbox_to && $prod_earchive['receiver'] === $sandbox_receiver ) ? 'yes' : 'no',
		'same_constant',
		'' === ( $prod_einvoice['receiver']['alias'] ?? '' ) ? 'absent' : 'set',
		null === $prod_einvoice['to'] ? 'absent' : 'set'
	)
);

/* ========================================================================== */
/* The SendInvoice experiment's gates, and its isolation from LoadInvoice        */
/* ========================================================================== */

/*
 * SendInvoice ISSUES a document, so its gates are measured as a pure decision
 * table rather than by running the tool: every refusal has to hold before any
 * credential is loaded or any endpoint is contacted.
 *
 * The two experiments' gates are deliberately separate. Opening the LoadInvoice
 * one during a send is refused outright -- one asks for a draft, the other for a
 * transmission, and together the intent is ambiguous.
 */
$send_gate_cases = array(
	// name => [ send env, write env, args, expected mode, expected refusal ]
	'plan_by_default'        => array( '', '', array(), 'plan', '' ),
	'both_gates'             => array( 'true', '', array( 'confirm=SendInvoice' ), 'send', '' ),
	'env_only'               => array( 'true', '', array(), 'refused', 'operation_not_confirmed' ),
	'confirm_only'           => array( '', '', array( 'confirm=SendInvoice' ), 'refused', 'send_gate_not_enabled' ),
	'wrong_operation'        => array( 'true', '', array( 'confirm=LoadInvoice' ), 'refused', 'wrong_operation_confirmed' ),
	'dashed_confirm'         => array( 'true', '', array( '--confirm=SendInvoice' ), 'send', '' ),
	'env_not_literal_true'   => array( 'TRUE', '', array( 'confirm=SendInvoice' ), 'refused', 'send_gate_not_enabled' ),
	'env_truthy_one'         => array( '1', '', array( 'confirm=SendInvoice' ), 'refused', 'send_gate_not_enabled' ),
	'loadinvoice_gate_open'  => array( 'true', 'true', array( 'confirm=SendInvoice' ), 'refused', 'loadinvoice_write_gate_open_during_send' ),
	'write_gate_alone'       => array( '', 'true', array( 'confirm=SendInvoice' ), 'refused', 'loadinvoice_write_gate_open_during_send' ),
);

$send_gate_ok      = true;
$send_gate_details = array();
foreach ( $send_gate_cases as $case => $spec ) {
	$verdict = kuka_sandbox_send_gates( (string) $spec[0], (string) $spec[1], (array) $spec[2] );

	$hit = $verdict['mode'] === $spec[3]
		&& ( 'send' === $spec[3] ) === $verdict['allowed']
		&& ( '' === $spec[4] || in_array( $spec[4], $verdict['refusals'], true ) );

	$send_gate_details[] = $case . '=' . $verdict['mode'] . ( empty( $verdict['refusals'] ) ? '' : '/' . implode( '+', $verdict['refusals'] ) );
	if ( ! $hit ) {
		$send_gate_ok = false;
	}
}

$isd_decision = kuka_sandbox_send_internetsales_decision();

$report(
	'SANDBOX_SEND_GATES_AND_ISOLATION',
	$send_gate_ok
	// The experiment mints its OWN document: a different seed, a different
	// state file. The confirmed LoadInvoice draft is never transmitted.
	&& kuka_sandbox_send_uuid() !== kuka_sandbox_uuid()
	&& KUKA_SANDBOX_SEND_UUID_SEED !== KUKA_SANDBOX_UUID_SEED
	&& 'sandbox-send-e2e.json' === KUKA_SANDBOX_SEND_STATE_FILE
	// INTERNETSALESDETAILS is decided from the WSDL, and this isolated fiscal
	// test is not a distance sale, so it declares INTERNETSALES=false and sends
	// no details block. Nothing here needs a courier API.
	&& false === $isd_decision['required']
	&& false === $isd_decision['internetsales']
	&& 'wsdl_minOccurs_0_and_not_a_distance_sale' === $isd_decision['reason'],
	sprintf(
		'measured:pure_gate_table|cases:%d|%s|own_uuid_seed:yes|own_state_file:%s|uuid_distinct:%s|internetsalesdetails:%s|reason:%s',
		count( $send_gate_cases ),
		implode( ' ', $send_gate_details ),
		KUKA_SANDBOX_SEND_STATE_FILE,
		kuka_sandbox_send_uuid() !== kuka_sandbox_uuid() ? 'yes' : 'NO',
		$isd_decision['required'] ? 'included' : 'omitted',
		$isd_decision['reason']
	)
);

/* ========================================================================== */
/* An unsettled transmission is resolved by evidence, never by assumption      */
/* ========================================================================== */

/*
 * kuka_sandbox_resolve_verdict() decides whether a document exists in the
 * world. The one mistake that produces a duplicate fiscal document is calling
 * absence when it was not proved, so every shape that is NOT proof of absence
 * must come back `unknown`.
 *
 * The control is what makes a refusal readable: EDM answers a never-transmitted
 * UUID the same way it answers a refusal, so the target is only absent when it
 * matches that control exactly.
 */
$refused          = 'edm_request_refused';
$control_refuses  = array(
	'status_ok'     => false,
	'status_error'  => $refused,
	'literal'       => '',
	'number_length' => 0,
);
$control_answers  = array(
	'status_ok'     => true,
	'status_error'  => '',
	'literal'       => '',
	'number_length' => 0,
);
$read_shape       = static function ( array $over = array() ): array {
	return array_merge(
		array(
			'status_ok'      => false,
			'status_error'   => '',
			'status_literal' => '',
			'status_number'  => '',
			'xml_error'      => '',
			'checks'         => array(
				'xml_retrieved' => false,
				'uuid_match'    => false,
			),
		),
		$over
	);
};

$verdict_cases = array(
	// Presence: EDM said something about THIS document.
	'xml_echoed_uuid'          => array(
		$read_shape( array( 'checks' => array( 'xml_retrieved' => true, 'uuid_match' => true ) ) ),
		array(),
		'present',
	),
	'status_gave_a_number'     => array(
		$read_shape( array( 'status_ok' => true, 'status_number' => '1234567890123456' ) ),
		array(),
		'present',
	),
	'status_gave_a_literal'    => array(
		$read_shape( array( 'status_ok' => true, 'status_literal' => 'LOAD - SUCCEED' ) ),
		array(),
		'present',
	),
	// Content with no UUID match is not absence -- something is stored there.
	'content_without_uuid'     => array(
		$read_shape( array( 'status_ok' => true, 'checks' => array( 'xml_retrieved' => true, 'uuid_match' => false ) ) ),
		array(),
		'unknown',
	),
	// Absence, answered directly: the query worked and EDM knew nothing.
	'answered_with_nothing'    => array(
		$read_shape( array( 'status_ok' => true, 'xml_error' => 'empty_content_returned' ) ),
		array(),
		'absent',
	),
	// Absence, calibrated: refused exactly as the never-transmitted UUID is.
	'refusal_matches_control'  => array(
		$read_shape( array( 'status_error' => $refused, 'xml_error' => 'empty_content_returned' ) ),
		$control_refuses,
		'absent',
	),
	// The control answered cleanly, so an unknown UUID is NOT refused here and
	// the target's refusal means something else.
	'control_answered_cleanly' => array(
		$read_shape( array( 'status_error' => $refused, 'xml_error' => 'empty_content_returned' ) ),
		$control_answers,
		'unknown',
	),
	// A different refusal is a different fact.
	'refusal_differs'          => array(
		$read_shape( array( 'status_error' => 'edm_network_error', 'xml_error' => 'empty_content_returned' ) ),
		$control_refuses,
		'unknown',
	),
	// No control at all: a refusal cannot be read, so nothing is concluded.
	'refusal_without_control'  => array(
		$read_shape( array( 'status_error' => $refused, 'xml_error' => 'empty_content_returned' ) ),
		array(),
		'unknown',
	),
	// A control that itself carried a document is not a control.
	'control_saw_a_document'   => array(
		$read_shape( array( 'status_error' => $refused, 'xml_error' => 'empty_content_returned' ) ),
		array_merge( $control_refuses, array( 'number_length' => 16 ) ),
		'unknown',
	),
	// Both calls failed at the transport: no answer, no verdict.
	'both_calls_failed'        => array(
		$read_shape( array( 'status_error' => 'edm_network_error', 'xml_error' => 'edm_network_error' ) ),
		array(),
		'unknown',
	),
);

$verdict_ok      = true;
$verdict_details = array();
foreach ( $verdict_cases as $case_name => $case ) {
	$got = kuka_sandbox_resolve_verdict( $case[0], $case[1] );
	if ( $case[2] !== $got['verdict'] ) {
		$verdict_ok = false;
	}
	$verdict_details[] = sprintf( '%s=%s', $case_name, $got['verdict'] );
}

// Absence is never the default: an empty read establishes nothing.
$empty_verdict = kuka_sandbox_resolve_verdict( array(), array() );
$verdict_ok    = $verdict_ok && 'unknown' === $empty_verdict['verdict'];

$report(
	'SANDBOX_RESOLVE_VERDICT',
	$verdict_ok,
	sprintf(
		'measured:pure_verdict_table|cases:%d|%s|empty_read:%s|absence_requires_an_answer:yes|refusal_requires_matching_control:yes',
		count( $verdict_cases ),
		implode( ' ', $verdict_details ),
		$empty_verdict['verdict']
	)
);

/* ========================================================================== */
/* The status check cannot transmit, structurally                              */
/* ========================================================================== */

/*
 * status=confirm asks EDM about a document it already accepted. The danger is
 * not that it queries: it is that a later edit reaches for send_invoice() on
 * the same client. Kuka_Sandbox_Readonly_Transport makes that impossible
 * rather than discouraged, so the refusal is measured here with a mock inner
 * transport -- no network, no document.
 */
$readonly_inner = new class() implements Kuka_Island_Core_SOAP_Transport_Interface {
	/** @var array<int, string> */
	public array $reached = array();

	public function call( string $action, array $parameters ) {
		$this->reached[] = $action;

		return array( 'ok' => true );
	}

	public function get_last_response(): string {
		return '';
	}

	public function get_last_request(): string {
		return '';
	}
};

$readonly = new Kuka_Sandbox_Readonly_Transport( $readonly_inner );

$readonly_cases = array(
	// Allowed: the four operations the status check needs.
	'Login'            => 'allowed',
	'GetInvoiceStatus' => 'allowed',
	'GetInvoice'       => 'allowed',
	'Logout'           => 'allowed',
	// Refused: everything that writes at EDM.
	'SendInvoice'      => 'refused',
	'LoadInvoice'      => 'refused',
	'EmailInvoice'     => 'refused',
	'CancelInvoice'    => 'refused',
	'CreateSerial'     => 'refused',
	// Refused: an operation nobody has heard of is not given the benefit of
	// the doubt.
	'MadeUpOperation'  => 'refused',
);

$readonly_ok      = true;
$readonly_details = array();
foreach ( $readonly_cases as $action => $expected ) {
	$got = 'allowed';
	try {
		$readonly->call( $action, array() );
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		$got = 'refused:' . $e->get_safe_error_code();
	}
	if ( 'allowed' === $expected ? 'allowed' !== $got : ! str_starts_with( $got, 'refused' ) ) {
		$readonly_ok = false;
	}
	$readonly_details[] = $action . '=' . ( str_starts_with( $got, 'refused' ) ? 'refused' : 'allowed' );
}

/*
 * A refused operation must not have reached the inner transport at all. If it
 * had, the refusal would be cosmetic: the request would already be on its way.
 */
$reached_only_allowed = array( 'Login', 'GetInvoiceStatus', 'GetInvoice', 'Logout' ) === $readonly_inner->reached;
$ledger_matches       = array( 'Login', 'GetInvoiceStatus', 'GetInvoice', 'Logout' ) === $readonly->ledger();
$no_writes_counted    = 0 === $readonly->count_of( 'SendInvoice' ) && 0 === $readonly->count_of( 'LoadInvoice' );

/*
 * Terminal classification. `unknown` is the one that matters: an unrecognised
 * literal must never be called settled, because a settled document stops being
 * watched.
 */
$terminal_cases = array(
	Kuka_Island_Core_EDM_Document_Status::CLASS_SUCCEEDED         => true,
	Kuka_Island_Core_EDM_Document_Status::CLASS_NEGATIVE_TERMINAL => true,
	Kuka_Island_Core_EDM_Document_Status::CLASS_ERROR             => true,
	Kuka_Island_Core_EDM_Document_Status::CLASS_PENDING           => false,
	Kuka_Island_Core_EDM_Document_Status::CLASS_UNKNOWN           => false,
);

$terminal_ok      = true;
$terminal_details = array();
foreach ( $terminal_cases as $class => $expected ) {
	$got = kuka_sandbox_status_is_terminal( (string) $class );
	if ( $expected !== $got ) {
		$terminal_ok = false;
	}
	$terminal_details[] = $class . '=' . ( $got ? 'terminal' : 'pending' );
}

// The literal this document is actually sitting on, classified by the
// production table rather than by eye.
$processing = Kuka_Island_Core_EDM_Document_Status::classify( 'PACKAGE - PROCESSING' );
$processing_pending = Kuka_Island_Core_EDM_Document_Status::CLASS_PENDING === $processing['class']
	&& ! kuka_sandbox_status_is_terminal( (string) $processing['class'] );

$report(
	'SANDBOX_STATUS_MODE_IS_READ_ONLY',
	$readonly_ok
	&& $reached_only_allowed
	&& $ledger_matches
	&& $no_writes_counted
	&& $terminal_ok
	&& $processing_pending,
	sprintf(
		'measured:allow_listed_transport|cases:%d|%s|reached_inner:%s|write_attempts_reached_inner:%s|SendInvoice:%d|LoadInvoice:%d|terminal_table:%s|package_processing:%s',
		count( $readonly_cases ),
		implode( ' ', $readonly_details ),
		array() === $readonly_inner->reached ? 'none' : implode( ',', $readonly_inner->reached ),
		$reached_only_allowed ? 'none' : 'SOME',
		$readonly->count_of( 'SendInvoice' ),
		$readonly->count_of( 'LoadInvoice' ),
		implode( ' ', $terminal_details ),
		$processing_pending ? 'pending' : 'MISCLASSIFIED'
	)
);

if ( ! empty( $failures ) ) {
	WP_CLI::error( sprintf( 'EDM sandbox harness verification failed (%d: %s).', count( $failures ), implode( ', ', $failures ) ) );
}

WP_CLI::success( 'EDM sandbox harness verification passed. No network call, no document, no database write.' );
