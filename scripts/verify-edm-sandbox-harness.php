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
 * The recipient default is the sharper case: 11111111111 exists in the mapper
 * only behind the reviewed allow_generic_individual_vkn policy, which defaults
 * to false. That gate is asserted here so the sandbox work cannot be mistaken
 * for having relaxed it.
 */
$module_dir   = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/invoice/';
$module_files = (array) glob( $module_dir . '*.php' );
$leak_hits    = array();
foreach ( $module_files as $module_file ) {
	$body = (string) file_get_contents( $module_file );
	foreach ( array( 'lib-edm-sandbox', 'kuka_sandbox_', 'KUKA_SANDBOX_', 'KUKA_EDM_SANDBOX_' ) as $needle ) {
		if ( str_contains( $body, $needle ) ) {
			$leak_hits[] = basename( $module_file ) . ':' . $needle;
		}
	}
}

$mapper_src = (string) file_get_contents( $module_dir . 'class-invoice-order-mapper.php' );
$config_src = (string) file_get_contents( $module_dir . 'class-invoice-config.php' );
// The generic recipient identity is still reachable only through the policy
// gate, and the policy still defaults to false.
$generic_vkn_gated = str_contains( $mapper_src, '! $this->config->allow_generic_individual_vkn()' )
	&& str_contains( $config_src, "defined( 'KUKA_EDM_ALLOW_GENERIC_INDIVIDUAL_VKN' ) && true === KUKA_EDM_ALLOW_GENERIC_INDIVIDUAL_VKN" );

$report(
	'SANDBOX_DEFAULTS_NOT_IN_PRODUCTION',
	count( $module_files ) >= 15
	&& array() === $leak_hits
	&& $generic_vkn_gated,
	sprintf(
		'module_files:%d|sandbox_references:%s|generic_receiver_still_policy_gated:%s',
		count( $module_files ),
		empty( $leak_hits ) ? 'none' : implode( ',', $leak_hits ),
		$generic_vkn_gated ? 'yes' : 'no'
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
/* Sender verification: fail-closed on everything that cannot be looked up      */
/* ========================================================================== */

$complete_company = array(
	'sender_vkn'        => '1234567890',
	'sender_alias'      => 'urn:mail:box@example.com',
	'sender_title'      => 'Test A.S.',
	'sender_tax_office' => 'Kadikoy',
	'sender_address'    => 'Moda Cad. 1',
	'sender_district'   => 'Kadikoy',
	'sender_city'       => 'Istanbul',
	'sender_postcode'   => '34710',
);

$good_facts = array(
	'defaults'         => kuka_sandbox_resolve_defaults( 'test', $good_endpoint, '', '', '1234567890' ),
	'series'           => kuka_sandbox_resolve_series( 'KUK', array( 'AAA', 'kuk', 'ZZZ' ), true ),
	'check_user_ok'    => true,
	'edm_alias'        => 'urn:mail:box@example.com',
	'configured_alias' => 'urn:mail:box@example.com',
	'company_fields'   => $complete_company,
);

$baseline = kuka_sandbox_verify_sender( $good_facts );
$report(
	'SANDBOX_VERIFY_ALL_PASS_ALLOWS_PLAN',
	true === $baseline['ok'] && array() === $baseline['failed'] && 6 === count( $baseline['checks'] ),
	sprintf( 'checks:%d|failed:%s', count( $baseline['checks'] ), empty( $baseline['failed'] ) ? 'none' : implode( ',', $baseline['failed'] ) )
);

// An absent serial must NOT block: EDM assigns the number instead.
$no_series_facts  = array_merge( $good_facts, array( 'series' => kuka_sandbox_resolve_series( '', array(), true ) ) );
$no_series_result = kuka_sandbox_verify_sender( $no_series_facts );
$report(
	'SANDBOX_MISSING_SERIES_DOES_NOT_BLOCK',
	true === $no_series_result['ok']
	&& array() === $no_series_result['failed']
	&& 'no' === $no_series_result['info']['series_sent']
	&& 'not_configured_edm_assigns_the_number' === $no_series_result['info']['series_mode'],
	sprintf(
		'ok:%s|series_sent:%s|series_mode:%s',
		$no_series_result['ok'] ? 'yes' : 'no',
		$no_series_result['info']['series_sent'],
		$no_series_result['info']['series_mode']
	)
);

$negatives = array(
	'check_user_failed'      => array( 'check_user_ok' => false, 'expect' => 'check_user_ok' ),
	'alias_mismatch'         => array( 'edm_alias' => 'urn:mail:OTHER@example.com', 'expect' => 'alias_exact_match' ),
	'alias_case_mismatch'    => array( 'edm_alias' => 'URN:MAIL:BOX@EXAMPLE.COM', 'expect' => 'alias_exact_match' ),
	'alias_whitespace'       => array( 'edm_alias' => ' urn:mail:box@example.com', 'expect' => 'alias_exact_match' ),
	'empty_configured_alias' => array( 'configured_alias' => '', 'expect' => 'alias_exact_match' ),
	'defaults_refused_live'  => array( 'defaults' => kuka_sandbox_resolve_defaults( 'live', $bad_endpoint, '', '', '1234567890' ), 'expect' => 'profile_id_resolved' ),
	'defaults_missing'       => array( 'defaults' => array(), 'expect' => 'receiver_identity_resolved' ),
	'defaults_unproved_wsdl' => array( 'defaults' => kuka_sandbox_resolve_defaults( 'test', $bad_endpoint, '', '', '1234567890' ), 'expect' => 'profile_id_resolved' ),
	'bad_receiver_override'  => array( 'defaults' => kuka_sandbox_resolve_defaults( 'test', $good_endpoint, '', '123', '1234567890' ), 'expect' => 'receiver_identity_resolved' ),
	'bad_profile_override'   => array( 'defaults' => kuka_sandbox_resolve_defaults( 'test', $good_endpoint, 'bad profile', '', '1234567890' ), 'expect' => 'profile_id_resolved' ),
	'malformed_series'       => array( 'series' => kuka_sandbox_resolve_series( 'KUKA', array(), true ), 'expect' => 'series_selection_valid' ),
	'unregistered_series'    => array( 'series' => kuka_sandbox_resolve_series( 'KUK', array( 'AAA' ), true ), 'expect' => 'series_selection_valid' ),
	'unverifiable_series'    => array( 'series' => kuka_sandbox_resolve_series( 'KUK', array(), false ), 'expect' => 'series_selection_valid' ),
	'series_missing'         => array( 'series' => array(), 'expect' => 'series_selection_valid' ),
);

$negative_results = array();
$negatives_ok     = true;
foreach ( $negatives as $case => $mutation ) {
	$expected = $mutation['expect'];
	unset( $mutation['expect'] );
	$result = kuka_sandbox_verify_sender( array_merge( $good_facts, $mutation ) );
	$hit    = ( false === $result['ok'] ) && in_array( $expected, $result['failed'], true );
	$negative_results[ $case ] = $hit ? 'blocked' : 'LEAKED';
	if ( ! $hit ) {
		$negatives_ok = false;
	}
}

// Each of the eight sender fiscal fields, one at a time. Every one of them is a
// value that must come from EDM's portal or API and is named in the output.
foreach ( array_keys( $complete_company ) as $field ) {
	$broken           = $complete_company;
	$broken[ $field ] = '';
	$result           = kuka_sandbox_verify_sender( array_merge( $good_facts, array( 'company_fields' => $broken ) ) );
	$hit              = ( false === $result['ok'] )
		&& in_array( 'company_fields_complete', $result['failed'], true )
		&& in_array( $field, $result['missing_company_fields'], true );
	$negative_results[ 'company_' . $field ] = $hit ? 'blocked' : 'LEAKED';
	if ( ! $hit ) {
		$negatives_ok = false;
	}
}

$report(
	'SANDBOX_VERIFY_NEGATIVE_MATRIX',
	$negatives_ok,
	sprintf(
		'cases:%d|leaked:%s',
		count( $negative_results ),
		implode( ',', array_keys( array_filter( $negative_results, static fn( string $v ): bool => 'blocked' !== $v ) ) ) ?: 'none'
	)
);

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

// Production numbering guard untouched.
$numbering_src = (string) file_get_contents( $module_dir . 'class-invoice-numbering.php' );
$report(
	'SANDBOX_NUMBERING_GUARD_UNTOUCHED',
	str_contains( $numbering_src, "ERROR_UNCONFIRMED = 'invoice_numbering_unconfirmed'" )
	&& str_contains( $numbering_src, 'NUMBER_SOURCE_EDM === $source' ),
	'invoice_numbering_unconfirmed:present|provenance_required:present'
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

if ( ! empty( $failures ) ) {
	WP_CLI::error( sprintf( 'EDM sandbox harness verification failed (%d: %s).', count( $failures ), implode( ', ', $failures ) ) );
}

WP_CLI::success( 'EDM sandbox harness verification passed. No network call, no document, no database write.' );
