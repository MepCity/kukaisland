<?php
/**
 * Turn one HTTP answer into an outcome and an allow-listed error code.
 *
 * Two things make this class worth having on its own.
 *
 * FIRST: the verdict depends on whether the request could have CHANGED anything
 * at the carrier. The same timeout means "try again" after a status query and
 * "you do not know whether a parcel now exists" after createOrder. Classifying
 * without that flag is how an integration books the same parcel twice, so
 * is_write is a required argument rather than an option with a default.
 *
 * SECOND: no byte the carrier wrote is allowed out of here. The classifier
 * returns codes from a fixed list it owns. A remote 'detail' string can contain
 * a customer name, an address, an internal identifier or an echo of the request
 * -- and every consumer downstream (order note, admin panel, verification
 * output, order meta) is a surface where such text must never appear.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Fault_Classifier {

	/**
	 * Every code this classifier can ever emit.
	 *
	 * Anything not on this list is a bug, not a new failure mode: a code that
	 * reaches an order note has to be one a human has already read once.
	 *
	 * @var array<int, string>
	 */
	public const CODES = array(
		'network_error',
		'timeout',
		'malformed_response',
		'bad_request',
		'unauthorized',
		'forbidden',
		'not_found',
		'conflict',
		'rate_limited',
		'server_error',
		'unexpected_redirect',
		'unexpected_status',
	);

	/**
	 * Classify a transport answer.
	 *
	 * @param int    $http_status      Status code, 0 when no answer arrived.
	 * @param string $transport_error  Non-empty when the transport itself failed.
	 * @param bool   $body_parsed      Whether the body parsed into the expected shape.
	 * @param bool   $is_write         Whether the request could have changed carrier state.
	 * @return array{outcome: string, code: string}
	 */
	public static function classify( int $http_status, string $transport_error, bool $body_parsed, bool $is_write ): array {
		/*
		 * No answer at all. The request may still have been received and acted
		 * on -- a timeout is silence, not a refusal -- so a write becomes
		 * uncertain and only a read is safe to repeat.
		 */
		if ( 0 === $http_status || '' !== $transport_error ) {
			$code = self::looks_like_timeout( $transport_error ) ? 'timeout' : 'network_error';

			return self::verdict( $is_write ? Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN : Kuka_Island_Shipping_Result::OUTCOME_TRANSIENT, $code );
		}

		if ( $http_status >= 300 && $http_status < 400 ) {
			// The official endpoints answer directly. A redirect is a sign the
			// request went somewhere unintended, which is never something to
			// follow with credentials attached.
			return self::verdict( $is_write ? Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN : Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'unexpected_redirect' );
		}

		if ( 200 <= $http_status && $http_status < 300 ) {
			if ( $body_parsed ) {
				return self::verdict( Kuka_Island_Shipping_Result::OUTCOME_SUCCESS, '' );
			}

			/*
			 * A 2xx whose body cannot be read is the most dangerous answer of
			 * all for a write: the carrier said yes in a way this code cannot
			 * confirm. Repeating it is forbidden; only a read can settle it.
			 */
			return self::verdict( $is_write ? Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN : Kuka_Island_Shipping_Result::OUTCOME_TRANSIENT, 'malformed_response' );
		}

		return match ( true ) {
			400 === $http_status => self::verdict( Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'bad_request' ),
			401 === $http_status => self::verdict( Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'unauthorized' ),
			403 === $http_status => self::verdict( Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'forbidden' ),
			404 === $http_status => self::verdict( Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'not_found' ),
			/*
			 * 409 is the carrier saying the thing already exists, or that the
			 * state does not allow this. Either way a repeat is meaningless and
			 * a duplicate is possible, so a write goes to reconciliation.
			 */
			409 === $http_status => self::verdict( $is_write ? Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN : Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'conflict' ),
			/*
			 * 429 and 5xx: whether the gateway rejected the request before the
			 * carrier saw it is not something this code can know from the
			 * status alone. Reads repeat; writes reconcile.
			 */
			429 === $http_status => self::verdict( $is_write ? Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN : Kuka_Island_Shipping_Result::OUTCOME_TRANSIENT, 'rate_limited' ),
			$http_status >= 500  => self::verdict( $is_write ? Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN : Kuka_Island_Shipping_Result::OUTCOME_TRANSIENT, 'server_error' ),
			default              => self::verdict( $is_write ? Kuka_Island_Shipping_Result::OUTCOME_UNCERTAIN : Kuka_Island_Shipping_Result::OUTCOME_PERMANENT, 'unexpected_status' ),
		};
	}

	/**
	 * Is this transport error a timeout rather than a hard connection failure?
	 *
	 * Matched on the transport's own English wording, which this project owns
	 * (WP_Http / cURL), never on carrier text.
	 */
	private static function looks_like_timeout( string $transport_error ): bool {
		$needle = strtolower( $transport_error );

		return str_contains( $needle, 'timed out' )
			|| str_contains( $needle, 'timeout' )
			|| str_contains( $needle, 'operation aborted' );
	}

	/**
	 * @return array{outcome: string, code: string}
	 */
	private static function verdict( string $outcome, string $code ): array {
		return array(
			'outcome' => $outcome,
			'code'    => '' === $code || in_array( $code, self::CODES, true ) ? $code : 'unexpected_status',
		);
	}
}
