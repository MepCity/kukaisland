<?php
/**
 * HTTP transport abstraction.
 *
 * Exists so every failure mode a carrier API can produce -- timeout, 401, 403,
 * 409, 429, 5xx, a body that is not JSON at all -- can be produced on demand in
 * a test, without a network, without credentials and without a real shipment.
 *
 * The return shape is deliberately dumb: status code, headers, raw body, and a
 * transport-level error string. Interpreting any of it is the client's job, and
 * classifying it is the fault classifier's job. A transport that decided what a
 * 409 meant would put that decision somewhere no test can reach.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

interface Kuka_Island_Shipping_HTTP_Transport_Interface {

	/**
	 * Perform one HTTP request.
	 *
	 * Implementations MUST NOT throw. A connection failure, a DNS failure and a
	 * timeout are all reported through the 'error' key with status 0, because a
	 * caller that has to distinguish "no answer" from "an answer that was a
	 * failure" cannot do so through an exception type.
	 *
	 * @param string                $method  HTTP method, upper case.
	 * @param string                $url     Absolute request URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string                $body    Raw request body ('' for GET).
	 * @param int                   $timeout Timeout in seconds.
	 * @return array{status: int, headers: array<string, string>, body: string, error: string}
	 */
	public function request( string $method, string $url, array $headers, string $body, int $timeout ): array;
}
