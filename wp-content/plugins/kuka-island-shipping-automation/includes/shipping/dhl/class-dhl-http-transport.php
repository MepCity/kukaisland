<?php
/**
 * WordPress HTTP transport for the DHL adapter.
 *
 * A thin wrapper over wp_remote_request, and thin on purpose: everything this
 * class could usefully decide -- what a 409 means, whether a retry is safe, what
 * to write on the order -- is decided elsewhere by code a test can drive. What
 * lives here is only the part that genuinely needs the network.
 *
 * It never throws. wp_remote_request returns WP_Error for a DNS failure, a
 * refused connection and a timeout alike, and all three are reported through
 * the 'error' key with status 0, because the caller has to be able to tell
 * "no answer" from "an answer that was a failure" without catching anything.
 *
 * Redirects are refused (redirection => 0). Following a redirect would resend
 * the gateway keys and the bearer token to whatever host the redirect named.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_DHL_HTTP_Transport implements Kuka_Island_Shipping_HTTP_Transport_Interface {

	/**
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string                $body    Raw body.
	 * @param int                   $timeout Seconds.
	 * @return array{status: int, headers: array<string, string>, body: string, error: string}
	 */
	public function request( string $method, string $url, array $headers, string $body, int $timeout ): array {
		$args = array(
			'method'      => strtoupper( $method ),
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'httpversion' => '1.1',
			'user-agent'  => 'KukaIslandShippingAutomation/0.1',
		);

		if ( '' !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 0,
				'headers' => array(),
				'body'    => '',
				// WP_Error messages come from WordPress and cURL, not from the
				// carrier, so they are safe to hand to the classifier. They are
				// still never written to an order note: the classifier reduces
				// them to one of its own codes first.
				'error'   => (string) $response->get_error_message(),
			);
		}

		$raw_headers = wp_remote_retrieve_headers( $response );
		$flat        = array();

		if ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
			foreach ( (array) $raw_headers->getAll() as $name => $value ) {
				$flat[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		} elseif ( is_array( $raw_headers ) ) {
			foreach ( $raw_headers as $name => $value ) {
				$flat[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $flat,
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'error'   => '',
		);
	}
}
