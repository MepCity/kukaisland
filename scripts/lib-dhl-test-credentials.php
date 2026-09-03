<?php
/**
 * Read the local DHL sandbox credential file and turn it into constants.
 *
 * The file lives OUTSIDE the git work tree, mode 600, at
 * ~/.config/kuka-island/dhl-sandbox.env, and is bind-mounted read-only into the
 * container by scripts/dhl-test-run.sh. It is never copied into the repository,
 * never exported as container environment variables (those stay readable
 * through `docker inspect` for as long as the container object exists) and
 * never echoed.
 *
 * TWO CREDENTIAL PAIRS, KEPT APART. The API gateway pair identifies the
 * integration; the Identity pair identifies the shipping account. A file that
 * holds only the gateway pair is a file that cannot obtain a token, and this
 * loader reports that as a named gap rather than as a mysterious 401 later.
 *
 * Values are read VERBATIM: everything after the first '=' on a line, with only
 * a trailing CR removed for CRLF files. Nothing is trimmed and nothing is
 * unquoted, because a quote or a trailing space inside a secret is part of the
 * secret.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || defined( 'ABSPATH' ) || exit( 1 );

/**
 * Credential-file key => plugin constant.
 *
 * The SANDBOX-prefixed spellings are the canonical ones, because they say out
 * loud which environment the values belong to. The unprefixed spellings are
 * accepted as aliases so a file written either way works.
 *
 * @return array<string, string>
 */
function kuka_dhl_credential_key_map(): array {
	return array(
		'KUKA_DHL_SANDBOX_CLIENT_ID'       => 'KUKA_DHL_CLIENT_ID',
		'KUKA_DHL_SANDBOX_CLIENT_SECRET'   => 'KUKA_DHL_CLIENT_SECRET',
		'KUKA_DHL_SANDBOX_CUSTOMER_NUMBER' => 'KUKA_DHL_CUSTOMER_NUMBER',
		'KUKA_DHL_SANDBOX_PASSWORD'        => 'KUKA_DHL_PASSWORD',
		'KUKA_DHL_CLIENT_ID'               => 'KUKA_DHL_CLIENT_ID',
		'KUKA_DHL_CLIENT_SECRET'           => 'KUKA_DHL_CLIENT_SECRET',
		'KUKA_DHL_CUSTOMER_NUMBER'         => 'KUKA_DHL_CUSTOMER_NUMBER',
		'KUKA_DHL_PASSWORD'                => 'KUKA_DHL_PASSWORD',
	);
}

/**
 * Parse credential-file contents into recognised keys.
 *
 * Comment lines and unrecognised keys are ignored. Later lines win over
 * earlier ones, so the unprefixed alias can override the prefixed spelling in a
 * hand-edited file without the order being surprising.
 *
 * @param string $raw File contents.
 * @return array<string, string> Constant name => verbatim value.
 */
function kuka_dhl_parse_credential_file( string $raw ): array {
	$map    = kuka_dhl_credential_key_map();
	$parsed = array();

	foreach ( explode( "\n", $raw ) as $line ) {
		// Only a trailing CR is removed. Nothing else is trimmed.
		$line = rtrim( $line, "\r" );

		if ( '' === trim( $line ) || str_starts_with( trim( $line ), '#' ) ) {
			continue;
		}

		$split = strpos( $line, '=' );

		if ( false === $split ) {
			continue;
		}

		$key   = trim( substr( $line, 0, $split ) );
		$value = substr( $line, $split + 1 );

		if ( ! isset( $map[ $key ] ) || '' === $value ) {
			continue;
		}

		$parsed[ $map[ $key ] ] = $value;
	}

	return $parsed;
}

/**
 * The mounted path the runner uses.
 */
function kuka_dhl_credential_mount_path(): string {
	return '/run/dhl/dhl-sandbox.env';
}

/**
 * Load credentials from the mount and define them as constants.
 *
 * Returns a report that names which values are PRESENT, never what they are.
 *
 * @return array{ok: bool, reason: string, present: array<int, string>, missing: array<int, string>}
 */
function kuka_dhl_load_credentials(): array {
	$required = array( 'KUKA_DHL_CLIENT_ID', 'KUKA_DHL_CLIENT_SECRET', 'KUKA_DHL_CUSTOMER_NUMBER', 'KUKA_DHL_PASSWORD' );
	$path     = kuka_dhl_credential_mount_path();

	if ( ! is_readable( $path ) ) {
		return array(
			'ok'      => false,
			'reason'  => 'credentials_mount_absent',
			'present' => array(),
			'missing' => $required,
		);
	}

	$parsed = kuka_dhl_parse_credential_file( (string) file_get_contents( $path ) );

	$present = array();
	$missing = array();

	foreach ( $required as $constant ) {
		if ( isset( $parsed[ $constant ] ) && '' !== $parsed[ $constant ] ) {
			$present[] = $constant;

			if ( ! defined( $constant ) ) {
				define( $constant, $parsed[ $constant ] );
			}
		} else {
			$missing[] = $constant;
		}
	}

	/*
	 * The sandbox tools never run against live, whatever the environment says.
	 * Pinned here as well as in the configuration class so a tool cannot be
	 * pointed elsewhere by an inherited environment variable.
	 */
	if ( ! defined( 'KUKA_DHL_ENVIRONMENT' ) ) {
		define( 'KUKA_DHL_ENVIRONMENT', 'test' );
	}

	return array(
		'ok'      => array() === $missing,
		'reason'  => array() === $missing ? 'credentials_loaded' : 'credentials_incomplete',
		'present' => $present,
		'missing' => $missing,
	);
}
