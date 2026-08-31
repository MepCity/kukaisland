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
 * kind and the NAME of the marker that matched. Every one of those four values
 * comes from a closed allow-list, so no byte of remote text can reach an output
 * surface even if a caller hands in a hand-built array.
 *
 * No digest of the message is produced. A hash of text that may contain a
 * reflected password is a verification oracle: an attacker who can read it can
 * confirm password guesses offline. The gain -- telling "same fault as last
 * run" apart from a new one -- does not justify that.
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

	/** Marker value used when no group matched. */
	public const MARKER_NONE = 'none';

	/**
	 * Every category a diagnostic may carry.
	 *
	 * @return array<int, string>
	 */
	public static function categories(): array {
		return array(
			self::CAT_CREDENTIALS,
			self::CAT_SESSION,
			self::CAT_NOT_FOUND,
			self::CAT_CONTRACT,
			self::CAT_SERVER,
			self::CAT_TIMEOUT,
			self::CAT_TLS,
			self::CAT_TRANSPORT,
			self::CAT_UNCLASSIFIED,
		);
	}

	/**
	 * Every folded fault kind a diagnostic may carry.
	 *
	 * @return array<int, string>
	 */
	public static function fault_kinds(): array {
		return array( 'http', 'client', 'server', 'wsdl', 'protocol', 'none', 'other' );
	}

	/**
	 * Every marker name a diagnostic may carry.
	 *
	 * Derived from the marker table itself, so a new group cannot be added
	 * without also becoming allow-listed -- and nothing else can.
	 *
	 * @return array<int, string>
	 */
	public static function marker_names(): array {
		$names = array( self::MARKER_NONE );
		foreach ( self::marker_groups() as $group ) {
			$names[] = $group['marker'];
		}

		return $names;
	}

	/**
	 * Force any array into the four-field diagnostic contract.
	 *
	 * This is the single choke point. Anything missing, of the wrong type or
	 * outside its allow-list collapses to a fixed safe default, so a value that
	 * originated in remote text or user input can never survive into an output
	 * surface. Extra keys are dropped rather than carried.
	 *
	 * The safe default for `retryable` is false: an unknown verdict must not be
	 * able to argue for retrying an operation whose outcome nobody established.
	 *
	 * @param array<string, mixed> $diagnostic Untrusted candidate.
	 * @return array{category: string, fault_kind: string, marker: string, retryable: bool}
	 */
	public static function normalize( array $diagnostic ): array {
		$category = $diagnostic['category'] ?? null;
		$kind     = $diagnostic['fault_kind'] ?? null;
		$marker   = $diagnostic['marker'] ?? null;

		return array(
			'category'   => is_string( $category ) && in_array( $category, self::categories(), true )
				? $category
				: self::CAT_UNCLASSIFIED,
			'fault_kind' => is_string( $kind ) && in_array( $kind, self::fault_kinds(), true )
				? $kind
				: 'other',
			'marker'     => is_string( $marker ) && in_array( $marker, self::marker_names(), true )
				? $marker
				: self::MARKER_NONE,
			// Strictly boolean true. A truthy string, 1 or 'yes' is not a
			// verdict, so it fails closed.
			'retryable'  => true === ( $diagnostic['retryable'] ?? null ),
		);
	}

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
	 * @param string $fault_message Raw SoapFault::getMessage(). Never returned,
	 *                              never stored and never hashed.
	 * @return array{category: string, fault_kind: string, marker: string, retryable: bool}
	 */
	public static function classify( string $fault_code, string $fault_message ): array {
		$kind = self::fault_kind( $fault_code );

		// Lowercased only for matching. This copy never leaves the function.
		$haystack = strtolower( $fault_message );

		foreach ( self::marker_groups() as $group ) {
			foreach ( $group['needles'] as $needle ) {
				if ( '' !== $needle && str_contains( $haystack, $needle ) ) {
					return self::result( $group['category'], $group['retryable'], $kind, $group['marker'] );
				}
			}
		}

		// No marker matched. Fall back on the transport-level evidence only,
		// and never invent an authentication verdict from silence.
		switch ( $kind ) {
			case 'http':
				return self::result( self::CAT_TRANSPORT, true, $kind, self::MARKER_NONE );
			case 'server':
				return self::result( self::CAT_SERVER, true, $kind, self::MARKER_NONE );
			case 'client':
			case 'protocol':
				return self::result( self::CAT_CONTRACT, false, $kind, self::MARKER_NONE );
			case 'wsdl':
				return self::result( self::CAT_TRANSPORT, true, $kind, self::MARKER_NONE );
			default:
				return self::result( self::CAT_UNCLASSIFIED, true, $kind, self::MARKER_NONE );
		}
	}

	/**
	 * Build the verdict. Routed through normalize() so even an internal typo
	 * cannot put an unlisted value into a diagnostic.
	 *
	 * @param string $category  Category constant.
	 * @param bool   $retryable Whether a retry could plausibly help.
	 * @param string $kind      Folded fault kind.
	 * @param string $marker    Name of the matched marker group, or 'none'.
	 * @return array{category: string, fault_kind: string, marker: string, retryable: bool}
	 */
	private static function result( string $category, bool $retryable, string $kind, string $marker ): array {
		return self::normalize(
			array(
				'category'   => $category,
				'fault_kind' => $kind,
				'marker'     => $marker,
				'retryable'  => $retryable,
			)
		);
	}

	/**
	 * One-line, printable form of a verdict.
	 *
	 * Normalises again before printing. The exception already normalises on the
	 * way in, so this is deliberate double validation: this method is public and
	 * may be handed an array that never passed through set_diagnostic().
	 *
	 * The result is assembled ONLY from allow-listed tokens, so the output shape
	 * is fixed and no caller-supplied byte can appear in it:
	 *
	 *   category:<allow-listed>|fault_kind:<allow-listed>|marker:<allow-listed>|retryable:yes|no
	 *
	 * @param array<string, mixed> $verdict Candidate verdict.
	 */
	public static function to_safe_line( array $verdict ): string {
		$safe = self::normalize( $verdict );

		return sprintf(
			'category:%s|fault_kind:%s|marker:%s|retryable:%s',
			$safe['category'],
			$safe['fault_kind'],
			$safe['marker'],
			$safe['retryable'] ? 'yes' : 'no'
		);
	}
}
