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
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

if ( ! defined( 'KUKA_EDM_TEST_CREDENTIALS_PATH' ) ) {
	define( 'KUKA_EDM_TEST_CREDENTIALS_PATH', '/run/edm/edm-test.env' );
}

/**
 * Keys accepted from the credential file, mapped to config override keys.
 *
 * Anything else in the file is ignored.
 *
 * @return array<string, string>
 */
function kuka_edm_test_credential_map(): array {
	return array(
		'KUKA_EDM_USERNAME'        => 'username',
		'KUKA_EDM_PASSWORD'       => 'password',
		'KUKA_EDM_SECRET_KEY'     => 'secret_key',
		'KUKA_EDM_SENDER_VKN'     => 'sender_vkn',
		'KUKA_EDM_SENDER_ALIAS'   => 'sender_alias',
		'KUKA_EDM_SERIES_EARCHIVE' => 'series_earchive',
		'KUKA_EDM_SERIES_EINVOICE' => 'series_einvoice',
		// Sender legal fields. Not secrets, but company data: still reported
		// presence-only. Required by the UBL builder for the sandbox E2E test.
		'KUKA_EDM_SENDER_TITLE'     => 'sender_title',
		'KUKA_EDM_SENDER_TAX_OFFICE' => 'sender_tax_office',
		'KUKA_EDM_SENDER_ADDRESS'   => 'sender_address',
		'KUKA_EDM_SENDER_DISTRICT'  => 'sender_district',
		'KUKA_EDM_SENDER_CITY'      => 'sender_city',
		'KUKA_EDM_SENDER_POSTCODE'  => 'sender_postcode',
	);
}

/**
 * Load locally supplied EDM test credentials.
 *
 * @param bool $force_test_environment Pin the config to the EDM test endpoint.
 * @return array{available: bool, reason: string, overrides: array<string, string>, presence: array<string, bool>, path: string}
 */
function kuka_edm_test_credentials( bool $force_test_environment = true ): array {
	$path = KUKA_EDM_TEST_CREDENTIALS_PATH;

	$result = array(
		'available' => false,
		'reason'    => '',
		'overrides' => array(),
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

	$map    = kuka_edm_test_credential_map();
	$values = array();

	foreach ( preg_split( '/\R/', $raw ) ?: array() as $line ) {
		$line = trim( $line );
		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			continue;
		}
		$pos = strpos( $line, '=' );
		if ( false === $pos ) {
			continue;
		}
		$key = trim( substr( $line, 0, $pos ) );
		if ( ! isset( $map[ $key ] ) ) {
			continue;
		}
		$value = trim( substr( $line, $pos + 1 ) );
		if ( strlen( $value ) >= 2 ) {
			$first = $value[0];
			$last  = $value[ strlen( $value ) - 1 ];
			if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {
				$value = substr( $value, 1, -1 );
			}
		}
		if ( '' !== $value ) {
			$values[ $map[ $key ] ] = $value;
		}
	}

	// Presence map only: never the values themselves.
	foreach ( $map as $override_key ) {
		$result['presence'][ $override_key ] = isset( $values[ $override_key ] );
	}

	if ( ! isset( $values['username'] ) || ! isset( $values['password'] ) ) {
		$result['reason'] = 'username_or_password_missing_in_file';
		return $result;
	}

	if ( $force_test_environment ) {
		$values['environment'] = Kuka_Island_Core_Invoice_Config::ENV_TEST;
	}

	$result['available'] = true;
	$result['reason']    = 'ok';
	$result['overrides'] = $values;

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
