<?php
/**
 * Invoice provider contract.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

interface Kuka_Island_Core_Invoice_Provider_Interface {
	/**
	 * Provider identifier (e.g. 'edm').
	 */
	public function get_id(): string;

	/**
	 * Human-readable provider name.
	 */
	public function get_name(): string;

	/**
	 * Authenticate / establish session with the integrator.
	 *
	 * @return string Session ID or token.
	 * @throws Kuka_Island_Core_Invoice_Exception On failure.
	 */
	public function login(): string;

	/**
	 * Terminate active session.
	 *
	 * @return bool True on success.
	 */
	public function logout(): bool;

	/**
	 * Check if a tax identifier (VKN/TCKN) is an e-Invoice user in GİB.
	 *
	 * @param string $identifier 10-digit VKN or 11-digit TCKN.
	 * @return array{is_einvoice_user: bool, title: string, alias: string, register_time: string, raw_data: array<string, mixed>}
	 * @throws Kuka_Island_Core_Invoice_Exception On failure.
	 */
	public function check_user( string $identifier ): array;

	/**
	 * Send an invoice to the integrator.
	 *
	 * @param array<string, mixed> $invoice_payload Structured payload with header and base64 UBL XML.
	 * @return Kuka_Island_Core_Invoice_Result Result DTO.
	 * @throws Kuka_Island_Core_Invoice_Exception On failure.
	 */
	public function send_invoice( array $invoice_payload ): Kuka_Island_Core_Invoice_Result;

	/**
	 * Query invoice status from integrator.
	 *
	 * @param string $uuid Invoice UUID.
	 * @param string $invoice_number Invoice number if known.
	 * @return Kuka_Island_Core_Invoice_Result Status result.
	 * @throws Kuka_Island_Core_Invoice_Exception On failure.
	 */
	public function get_invoice_status( string $uuid, string $invoice_number = '' ): Kuka_Island_Core_Invoice_Result;

	/**
	 * Retrieve invoice document (PDF or XML).
	 *
	 * @param string $uuid Invoice UUID.
	 * @param string $format Document format: 'PDF' or 'XML'.
	 * @return string Binary/decoded document content.
	 * @throws Kuka_Island_Core_Invoice_Exception On failure.
	 */
	public function get_invoice_document( string $uuid, string $format = 'PDF' ): string;

	/**
	 * Request integrator to email the invoice to the customer.
	 *
	 * @param string $uuid Invoice UUID.
	 * @param string $email Customer email address.
	 * @return bool True if queued/sent by integrator.
	 * @throws Kuka_Island_Core_Invoice_Exception On failure.
	 */
	public function email_invoice( string $uuid, string $email ): bool;

	/**
	 * Query remaining invoice counter/credits.
	 *
	 * @return array{counter_left: int}
	 * @throws Kuka_Island_Core_Invoice_Exception On failure.
	 */
	public function check_counter(): array;

	/**
	 * Query the fiscal serials registered at the integrator (read-only).
	 *
	 * @param string $serial_code Optional serial code filter.
	 * @param int    $year Fiscal year (0 = current year).
	 * @param string $send_type Optional send-type filter.
	 * @return array{serials: array<int, array{code: string, year: int, last_serial_used: int}>}
	 * @throws Kuka_Island_Core_Invoice_Exception On failure.
	 */
	public function get_invoice_serial( string $serial_code = '', int $year = 0, string $send_type = '' ): array;
}
