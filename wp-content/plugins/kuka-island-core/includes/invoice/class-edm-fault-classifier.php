<?php
/**
 * Safe classification of SOAP faults raised by the EDM endpoint.
 *
 * A SoapFault message is untrusted remote text. It may quote the request back,
 * which can include the user name, and it is not translated or stable, so it
 * must never reach an output stream, a log line, an order note or the database.
 *
 * This classifier turns a fault into a small, fixed vocabulary that is safe to
 * print: a category, whether a retry could plausibly help, a normalised fault
 * kind and the NAME of the marker that matched. It also returns a short digest
 * of the message so two runs can be compared without anyone reading the text.
 *
 * Nothing here returns, stores or logs the message itself.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_EDM_Fault_Classifier {

	/** The endpoint rejected the supplied credentials. */
	public const CAT_CREDENTIALS = 'credentials_rejected';
	/** The session is missing, expired or not accepted. */
	public const CAT_SESSION = 'session_invalid';
	/** The service address answered, but not with this service. */
	public const CAT_NOT_FOUND = 'endpoint_not_found';
	/** The request shape itself was refused (schema, missing field, format). */
	public const CAT_CONTRACT = 'request_contract_rejected';
	/** The remote side failed while processing a well-formed request. */
	public const CAT_SERVER = 'remote_server_error';
	/** No answer within the timeout. */
	public const CAT_TIMEOUT = 'network_timeout';
	/** The TLS handshake or certificate validation failed. */
	public const CAT_TLS = 'tls_failure';
	/** Transport-level failure that is none of the above. */
	public const CAT_TRANSPORT = 'http_transport_failure';
	/** Nothing matched. Deliberately never guessed into another category. */
	public const CAT_UNCLASSIFIED = 'unclassified_fault';

	/**
	 * Marker groups, in evaluation order.
	 *
	 * Transport-level evidence is checked first: an HTTP 404 or a TLS failure
	 * can carry words that would otherwise look like an authentication problem.
	 * Only the group NAME ever leaves this class.
	 *
	 * @return array<int, array{marker: string, category: string, retryable: bool, needles: array<int, string>}>
	 */
	private static function marker_groups(): array {
		return array(
			array(
				'marker'    => 'http_not_found',
				'category'  => self::CAT_NOT_FOUND,
				'retryable' => false,
				'needles'   => array( '404', 'not found', 'bulunamadi', 'bulunamadı' ),
			),
			array(
				'marker'    => 'timeout',
				'category'  => self::CAT_TIMEOUT,
				'retryable' => true,
				'needles'   => array( 'timed out', 'timeout', 'zaman asimi', 'zaman aşımı', 'could not connect', 'connection refused' ),
			),
			array(
				'marker'    => 'tls',
				'category'  => self::CAT_TLS,
				'retryable' => true,
				'needles'   => array( 'ssl', 'tls', 'certificate', 'sertifika', 'handshake' ),
			),
			array(
				'marker'    => 'http_server_error',
				'category'  => self::CAT_SERVER,
				'retryable' => true,
				'needles'   => array( '500 internal', 'internal server error', '502 ', '503 ', '504 ' ),
			),
			array(
				'marker'    => 'authentication',
				'category'  => self::CAT_CREDENTIALS,
				'retryable' => false,
				'needles'   => array(
					'user name',
					'username',
					'user_name',
					'password',
					'secret',
					'unauthor',
					'kullanici',
					'kullanıcı',
					'sifre',
					'şifre',
					'parola',
					'yetkisiz',
					'yetki yok',
					'login failed',
					'invalid login',
					'giris yapilamadi',
					'giriş yapılamadı',
				),
			),
			array(
				'marker'    => 'session',
				'category'  => self::CAT_SESSION,
				'retryable' => false,
				'needles'   => array( 'session', 'oturum' ),
			),
			array(
				'marker'    => 'request_contract',
				'category'  => self::CAT_CONTRACT,
				'retryable' => false,
				'needles'   => array(
					'schema',
					'sema',
					'şema',
					'validation',
					'was not expected',
					'deserial',
					'formatter threw',
					'endelement',
					'expecting element',
					'is missing',
					'zorunlu',
					'eksik',
					'gecersiz',
					'geçersiz',
					'invalid request',
					'bad request',
					'400 ',
				),
			),
		);
	}

	/**
	 * Fold a raw SOAP fault code into a small, stable vocabulary.
	 *
	 * @param string $fault_code Raw faultcode, e.g. 'HTTP', 's:Client', 'WSDL'.
	 */
	public static function fault_kind( string $fault_code ): string {
		$code = strtolower( trim( $fault_code ) );
		// Strip any namespace prefix: 's:Client', 'soap:Client' and 'Client'
		// must all fold to the same token.
		$colon = strrpos( $code, ':' );
		if ( false !== $colon ) {
			$code = substr( $code, $colon + 1 );
		}

		switch ( $code ) {
			case 'http':
				return 'http';
			case 'client':
				return 'client';
			case 'server':
			case 'receiver':
				return 'server';
			case 'wsdl':
				return 'wsdl';
			case 'versionmismatch':
			case 'mustunderstand':
				return 'protocol';
			case '':
				return 'none';
			default:
				return 'other';
		}
	}

	/**
	 * Classify a fault without exposing its message.
	 *
	 * @param string $fault_code    Raw SoapFault::faultcode.
	 * @param string $fault_message Raw SoapFault::getMessage(). Never returned.
	 * @return array{category: string, retryable: bool, fault_kind: string, marker: string, digest: string}
	 */
	public static function classify( string $fault_code, string $fault_message ): array {
		$kind = self::fault_kind( $fault_code );

		// Lowercased only for matching. This copy never leaves the function.
		$haystack = strtolower( $fault_message );

		foreach ( self::marker_groups() as $group ) {
			foreach ( $group['needles'] as $needle ) {
				if ( '' !== $needle && str_contains( $haystack, $needle ) ) {
					return self::result( $group['category'], $group['retryable'], $kind, $group['marker'], $fault_message );
				}
			}
		}

		// No marker matched. Fall back on the transport-level evidence only,
		// and never invent an authentication verdict from silence.
		switch ( $kind ) {
			case 'http':
				return self::result( self::CAT_TRANSPORT, true, $kind, 'none', $fault_message );
			case 'server':
				return self::result( self::CAT_SERVER, true, $kind, 'none', $fault_message );
			case 'client':
			case 'protocol':
				return self::result( self::CAT_CONTRACT, false, $kind, 'none', $fault_message );
			case 'wsdl':
				return self::result( self::CAT_TRANSPORT, true, $kind, 'none', $fault_message );
			default:
				return self::result( self::CAT_UNCLASSIFIED, true, $kind, 'none', $fault_message );
		}
	}

	/**
	 * Build the verdict, including a non-reversible digest of the message.
	 *
	 * The digest exists so an operator can tell "same fault as last run" from
	 * "a different fault" without anyone reading remote text. It is truncated,
	 * so it identifies nothing on its own.
	 *
	 * @param string $category      Category constant.
	 * @param bool   $retryable     Whether a retry could plausibly help.
	 * @param string $kind          Folded fault kind.
	 * @param string $marker        Name of the matched marker group, or 'none'.
	 * @param string $fault_message Raw message. Hashed, never stored.
	 * @return array{category: string, retryable: bool, fault_kind: string, marker: string, digest: string}
	 */
	private static function result( string $category, bool $retryable, string $kind, string $marker, string $fault_message ): array {
		return array(
			'category'   => $category,
			'retryable'  => $retryable,
			'fault_kind' => $kind,
			'marker'     => $marker,
			'digest'     => substr( hash( 'sha256', $fault_message ), 0, 8 ),
		);
	}

	/**
	 * One-line, printable form of a verdict. Contains no remote text.
	 *
	 * @param array<string, mixed> $verdict Output of classify().
	 */
	public static function to_safe_line( array $verdict ): string {
		return sprintf(
			'category:%s|fault_kind:%s|marker:%s|retryable:%s|digest:%s',
			(string) ( $verdict['category'] ?? self::CAT_UNCLASSIFIED ),
			(string) ( $verdict['fault_kind'] ?? 'other' ),
			(string) ( $verdict['marker'] ?? 'none' ),
			true === ( $verdict['retryable'] ?? false ) ? 'yes' : 'no',
			(string) ( $verdict['digest'] ?? '' )
		);
	}
}
