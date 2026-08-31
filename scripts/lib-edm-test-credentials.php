<?php
/**
 * Read-only loader for locally supplied EDM test credentials.
 *
 * Security model
 * --------------
 * - Credentials live in a file OUTSIDE the git work tree
 *   (default: ~/.config/kuka-island/edm-test.env, mode 600). Because the file is
 *   not inside the repository, `git add` cannot reach it at all.
 * - The file is bind-mounted read-only into the wp-cli container at
 *   KUKA_EDM_TEST_CREDENTIALS_PATH. Mounting is used instead of container
 *   environment variables on purpose: values passed with `-e` ARE readable from
 *   `docker inspect` for as long as the container object exists.
 * - Values are handed to Kuka_Island_Core_Invoice_Config as constructor
 *   overrides. Production code paths are untouched: the plugin itself still
 *   reads credentials only from wp-config constants or the process environment.
 * - This loader never prints, logs or returns a credential value in any
 *   human-readable summary. Only booleans are exposed through 'presence'.
 *
 * Parsing contract
 * ----------------
 * One `KEY=value` pair per line. The value is EVERYTHING after the first `=`,
 * verbatim: it is not trimmed and quotes are not stripped, so a password
 * containing spaces, quotes or further `=` characters survives byte-for-byte.
 * Only a trailing CR (CRLF files) is removed. Blank lines and lines whose first
 * non-blank character is `#` are ignored.
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

if ( ! defined( 'KUKA_EDM_TEST_CREDENTIALS_PATH' ) ) {
	define( 'KUKA_EDM_TEST_CREDENTIALS_PATH', '/run/edm/edm-test.env' );
}

/**
 * Keys accepted from the credential file that map onto
 * Kuka_Island_Core_Invoice_Config constructor overrides.
 *
 * @return array<string, string>
 */
function kuka_edm_test_credential_map(): array {
	return array(
		'KUKA_EDM_USERNAME'          => 'username',
		'KUKA_EDM_PASSWORD'          => 'password',
		'KUKA_EDM_SECRET_KEY'        => 'secret_key',
		'KUKA_EDM_SENDER_VKN'        => 'sender_vkn',
		'KUKA_EDM_SENDER_ALIAS'      => 'sender_alias',
		'KUKA_EDM_SENDER_TITLE'      => 'sender_title',
		'KUKA_EDM_SENDER_TAX_OFFICE' => 'sender_tax_office',
		'KUKA_EDM_SENDER_ADDRESS'    => 'sender_address',
		'KUKA_EDM_SENDER_DISTRICT'   => 'sender_district',
		'KUKA_EDM_SENDER_CITY'       => 'sender_city',
		'KUKA_EDM_SENDER_POSTCODE'   => 'sender_postcode',
		'KUKA_EDM_SERIES_EARCHIVE'   => 'series_earchive',
		'KUKA_EDM_SERIES_EINVOICE'   => 'series_einvoice',
	);
}

/**
 * Sandbox-only keys. These are NOT config overrides: they exist so the isolated
 * sandbox experiment can refuse to invent a receiver identity or a document
 * profile that EDM has not confirmed in writing.
 *
 * @return array<string, string>
 */
function kuka_edm_sandbox_credential_map(): array {
	return array(
		'KUKA_EDM_SANDBOX_RECEIVER_VKN'      => 'receiver_vkn',
		'KUKA_EDM_SANDBOX_PROFILE_ID'        => 'profile_id',
		// The PROFILEID EDM confirmed in writing. Recorded separately from the
		// value the harness would send so the two can be matched byte-for-byte.
		// Until EDM answers there is nothing to record and the write gate stays
		// shut.
		'KUKA_EDM_SANDBOX_PROFILE_ID_CONFIRMED' => 'profile_id_confirmed',
	);
}

/**
 * Parse raw credential-file content into a key => verbatim value map.
 *
 * Exposed separately so the harness tests can exercise the parser without a
 * mounted file.
 *
 * @param string $raw File content.
 * @return array<string, string> Recognised keys only.
 */
function kuka_edm_parse_credential_file( string $raw ): array {
	$allowed = array_merge( kuka_edm_test_credential_map(), kuka_edm_sandbox_credential_map() );
	$values  = array();

	foreach ( preg_split( '/\n/', $raw ) ?: array() as $line ) {
		// Strip only a trailing CR so CRLF files parse; never touch the value.
		if ( str_ends_with( $line, "\r" ) ) {
			$line = substr( $line, 0, -1 );
		}
		if ( '' === trim( $line ) || str_starts_with( ltrim( $line ), '#' ) ) {
			continue;
		}
		$pos = strpos( $line, '=' );
		if ( false === $pos ) {
			continue;
		}
		$key = trim( substr( $line, 0, $pos ) );
		if ( ! isset( $allowed[ $key ] ) ) {
			continue;
		}
		// Everything after the FIRST '=' is the value, verbatim.
		$value = substr( $line, $pos + 1 );
		if ( '' !== $value ) {
			$values[ $key ] = $value;
		}
	}

	return $values;
}

/**
 * Load locally supplied EDM test credentials.
 *
 * @param bool $force_test_environment Pin the config to the EDM test endpoint.
 * @return array{available: bool, reason: string, overrides: array<string, string>, sandbox: array<string, string>, presence: array<string, bool>, path: string}
 */
function kuka_edm_test_credentials( bool $force_test_environment = true ): array {
	$path = KUKA_EDM_TEST_CREDENTIALS_PATH;

	$config_map  = kuka_edm_test_credential_map();
	$sandbox_map = kuka_edm_sandbox_credential_map();

	$result = array(
		'available' => false,
		'reason'    => '',
		'overrides' => array(),
		'sandbox'   => array(),
		'presence'  => array(),
		'path'      => $path,
	);

	if ( ! is_readable( $path ) ) {
		$result['reason'] = 'credentials_file_not_mounted';
		return $result;
	}

	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		$result['reason'] = 'credentials_file_unreadable';
		return $result;
	}

	$values = kuka_edm_parse_credential_file( (string) $raw );

	// Presence map only: never the values themselves.
	foreach ( array_keys( array_merge( $config_map, $sandbox_map ) ) as $file_key ) {
		$result['presence'][ $file_key ] = isset( $values[ $file_key ] );
	}

	foreach ( $config_map as $file_key => $override_key ) {
		if ( isset( $values[ $file_key ] ) ) {
			$result['overrides'][ $override_key ] = $values[ $file_key ];
		}
	}
	foreach ( $sandbox_map as $file_key => $sandbox_key ) {
		if ( isset( $values[ $file_key ] ) ) {
			$result['sandbox'][ $sandbox_key ] = $values[ $file_key ];
		}
	}

	if ( ! isset( $result['overrides']['username'] ) || ! isset( $result['overrides']['password'] ) ) {
		$result['reason'] = 'username_or_password_missing_in_file';
		return $result;
	}

	if ( $force_test_environment ) {
		$result['overrides']['environment'] = Kuka_Island_Core_Invoice_Config::ENV_TEST;
	}

	$result['available'] = true;
	$result['reason']    = 'ok';

	return $result;
}

/**
 * Human-safe one-line summary of which credential fields were supplied.
 *
 * Emits booleans only. No value, length or fragment of any credential.
 *
 * @param array<string, bool> $presence Presence map from kuka_edm_test_credentials().
 */
function kuka_edm_test_presence_summary( array $presence ): string {
	$parts = array();
	foreach ( $presence as $field => $is_present ) {
		$parts[] = $field . ':' . ( $is_present ? 'supplied' : 'absent' );
	}

	return implode( '|', $parts );
}
