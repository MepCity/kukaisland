<?php
/**
 * SOAP transport abstraction interface.
 *
 * Allows mocking and unit testing without network or real credentials.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

interface Kuka_Island_Core_SOAP_Transport_Interface {
	/**
	 * Execute a SOAP operation.
	 *
	 * @param string               $action SOAP operation name (e.g. 'Login', 'CheckUser', 'SendInvoice').
	 * @param array<string, mixed> $parameters Request payload.
	 * @return mixed SOAP response object or array.
	 * @throws SoapFault|Exception On transport or SOAP failure.
	 */
	public function call( string $action, array $parameters );

	/**
	 * Get last response XML if available.
	 */
	public function get_last_response(): string;

	/**
	 * Get last request XML if available.
	 */
	public function get_last_request(): string;
}
