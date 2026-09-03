<?php
/**
 * The JWT session, held in memory and nowhere else.
 *
 * The token is NOT written to an option, a transient, a cookie, a file or a log.
 * It lives in a private property for the duration of one PHP request and dies
 * with it. That costs one extra /token round trip in a request that had none
 * cached, and it buys a guarantee worth far more: a bearer credential for a
 * shipping account never appears in a database dump, never survives into a
 * staging copy of the site, and cannot be read by another plugin through
 * get_option().
 *
 * Expiry is treated as ambiguous, deliberately, and the ambiguity is resolved
 * by NOT relying on the string for the cache lifetime at all.
 * Identity_API-1.0.json documents jwtExpireDate only by example --
 * "10.03.2020 16:05:00" -- with no timezone anywhere in the document, and its
 * own example implies a lifetime of about an hour. Reading a Turkish local time
 * as UTC (or the reverse) moves the instant by three hours, which is a large
 * fraction of that hour.
 *
 * So the cache window is a FIXED five minutes, comfortably inside any plausible
 * lifetime, and the documented string is used for one unambiguous purpose only:
 * as a veto. If even the most generous reading of it is already in the past, the
 * token is not cached at all. It is never used to justify caching for LONGER
 * than the fixed window.
 *
 * An earlier version took the pessimistic reading and derived the window from
 * it. On a one-hour token that arithmetic went negative for one of the two
 * timezones, the window collapsed to zero, and every single request fetched a
 * new token -- more credential traffic, not less risk. The fixed window is both
 * safer and cheaper, and an expiry this code guesses wrong is corrected by the
 * client's single re-authentication on a 401 read.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_DHL_Token_Store {

	/** The fixed cache window. Never exceeded, whatever the answer said. */
	private const MAX_CACHE_SECONDS = 300;

	/** Used when jwtExpireDate cannot be parsed at all. */
	private const FALLBACK_CACHE_SECONDS = 300;

	/** Clock-skew margin subtracted from every computed lifetime. */
	private const SKEW_SECONDS = 60;

	private string $token = '';

	private int $expires_at = 0;

	/**
	 * Count of /token requests made in this process.
	 *
	 * Read by the verification suite to prove that a cached token is actually
	 * reused, and that a failed authentication is not retried in a loop.
	 */
	private int $issue_count = 0;

	public function get_issue_count(): int {
		return $this->issue_count;
	}

	/** Drop the cached token. Used after a 401, and by tests. */
	public function forget(): void {
		$this->token      = '';
		$this->expires_at = 0;
	}

	public function has_valid_token(): bool {
		return '' !== $this->token && $this->expires_at > time();
	}

	/**
	 * The bearer token, obtaining one if the cache cannot serve it.
	 *
	 * @param Kuka_Island_Shipping_DHL_Config                $config    Configuration.
	 * @param Kuka_Island_Shipping_HTTP_Transport_Interface  $transport Transport.
	 * @return array{ok: bool, token: string, outcome: string, code: string, http: int}
	 */
	public function acquire( Kuka_Island_Shipping_DHL_Config $config, Kuka_Island_Shipping_HTTP_Transport_Interface $transport ): array {
		if ( $this->has_valid_token() ) {
			return array(
				'ok'      => true,
				'token'   => $this->token,
				'outcome' => Kuka_Island_Shipping_Result::OUTCOME_SUCCESS,
				'code'    => '',
				'http'    => 0,
			);
		}

		$url = Kuka_Island_Shipping_DHL_Config::SANDBOX_IDENTITY_URL;

		if ( ! $config->is_allowed_url( $url ) ) {
			return $this->failure( Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'endpoint_not_allowed' );
		}

		$body = wp_json_encode(
			array(
				'customerNumber' => $config->get_customer_number(),
				'password'       => $config->get_password(),
				'identityType'   => Kuka_Island_Shipping_DHL_Config::IDENTITY_TYPE,
			)
		);

		if ( ! is_string( $body ) ) {
			return $this->failure( Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'bad_request' );
		}

		++$this->issue_count;

		$response = $transport->request(
			'POST',
			$url,
			array(
				'Content-Type'        => 'application/json',
				'Accept'              => 'application/json',
				'X-IBM-Client-Id'     => $config->get_client_id(),
				'X-IBM-Client-Secret' => $config->get_client_secret(),
			),
			$body,
			$config->get_timeout()
		);

		$decoded = json_decode( (string) $response['body'], true );
		$jwt     = is_array( $decoded ) ? trim( (string) ( $decoded['jwt'] ?? '' ) ) : '';

		/*
		 * Obtaining a token changes nothing at the carrier, so it is classified
		 * as a READ. A timeout here is safe to repeat; it can never have left a
		 * shipment behind.
		 */
		$verdict = Kuka_Island_Shipping_Fault_Classifier::classify(
			(int) $response['status'],
			(string) $response['error'],
			'' !== $jwt,
			false
		);

		if ( Kuka_Island_Shipping_Result::OUTCOME_SUCCESS !== $verdict['outcome'] ) {
			$this->forget();

			return $this->failure( $verdict['outcome'], $verdict['code'], (int) $response['status'] );
		}

		$this->token      = $jwt;
		$this->expires_at = time() + $this->cache_seconds( is_array( $decoded ) ? (string) ( $decoded['jwtExpireDate'] ?? '' ) : '' );

		return array(
			'ok'      => true,
			'token'   => $this->token,
			'outcome' => Kuka_Island_Shipping_Result::OUTCOME_SUCCESS,
			'code'    => '',
			'http'    => (int) $response['status'],
		);
	}

	/**
	 * @return array{ok: bool, token: string, outcome: string, code: string, http: int}
	 */
	private function failure( string $outcome, string $code, int $http = 0 ): array {
		return array(
			'ok'      => false,
			'token'   => '',
			'outcome' => $outcome,
			'code'    => $code,
			'http'    => $http,
		);
	}

	/**
	 * How long this token may be reused, in seconds.
	 *
	 * The fixed window, unless the documented expiry vetoes caching entirely.
	 *
	 * @param string $expire_date Raw jwtExpireDate, format 'd.m.Y H:i:s'.
	 */
	public function cache_seconds( string $expire_date ): int {
		$remaining = self::remaining_seconds( $expire_date, time() );

		if ( null === $remaining ) {
			// Unreadable expiry. The fixed window still applies: it is short
			// enough not to depend on the value that could not be read.
			return self::FALLBACK_CACHE_SECONDS;
		}

		if ( $remaining <= self::SKEW_SECONDS ) {
			// Even read generously, this token is finished. Not cached at all.
			return 0;
		}

		return min( self::MAX_CACHE_SECONDS, $remaining - self::SKEW_SECONDS );
	}

	/**
	 * Seconds left on the documented expiry, read GENEROUSLY.
	 *
	 * The string is interpreted in UTC and in the carrier's own timezone and the
	 * LARGER remaining lifetime is returned, because this value is only ever
	 * used as a veto: "not even the most generous reading leaves any time" is a
	 * statement that holds whatever the timezone turns out to be. It is never
	 * used to extend the cache window past the fixed five minutes, so reading it
	 * generously cannot keep a dead token alive for longer than that.
	 *
	 * Returns null when the value is not the documented format at all.
	 *
	 * Public so the choice can be measured directly rather than inferred from
	 * cache behaviour.
	 *
	 * @param string $expire_date Raw jwtExpireDate.
	 * @param int    $now         Current unix time.
	 */
	public static function remaining_seconds( string $expire_date, int $now ): ?int {
		$expire_date = trim( $expire_date );

		if ( 1 !== preg_match( '/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}$/', $expire_date ) ) {
			return null;
		}

		$candidates = array();

		foreach ( array( 'UTC', 'Europe/Istanbul' ) as $zone ) {
			try {
				$moment = DateTimeImmutable::createFromFormat( 'd.m.Y H:i:s', $expire_date, new DateTimeZone( $zone ) );
			} catch ( Exception $e ) {
				continue;
			}

			if ( $moment instanceof DateTimeImmutable ) {
				$candidates[] = $moment->getTimestamp() - $now;
			}
		}

		if ( array() === $candidates ) {
			return null;
		}

		return (int) max( $candidates );
	}

	/**
	 * Keep the token out of var_dump(), print_r() and every debug backtrace
	 * that walks object properties.
	 *
	 * @return array<string, mixed>
	 */
	public function __debugInfo(): array {
		return array(
			'token'       => '[redacted]',
			'expires_at'  => $this->expires_at,
			'issue_count' => $this->issue_count,
		);
	}
}
