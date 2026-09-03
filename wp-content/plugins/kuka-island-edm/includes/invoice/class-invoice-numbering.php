<?php
/**
 * Fiscal document number resolution.
 *
 * Turkish e-Fatura / e-Arşiv document numbers are legal fiscal identifiers.
 * They may only originate from the integrator's registered serial counter --
 * never from a WooCommerce order ID, a local option counter, a timestamp, or
 * any other locally invented sequence.
 *
 * EDM technical support has now confirmed in writing how automatic numbering
 * is requested, which closes the question the previous fail-closed block was
 * waiting on:
 *
 * - The submitted UBL `cbc:ID` carries the fixed sentinel `ABC2009123456789`.
 *   It is a request, not a number: it tells EDM "assign this document's number
 *   from the registered serial". It is the same literal for every document.
 * - `SendInvoiceRequest/INVOICE/@ID` is left out entirely. The sentinel does
 *   NOT go there.
 * - The number EDM assigns comes back in the SendInvoice response as
 *   `INVOICE/@ID`, and that value -- and only that value -- is the fiscal
 *   document number.
 *
 * Two consequences are enforced here and in
 * Kuka_Island_Core_Invoice_Order_Store:
 *
 * - The sentinel is never written to the order as a document number. It would
 *   look exactly like a real fiscal identifier and it is not one.
 * - A document with no EDM-assigned number is not complete, whatever else the
 *   response says.
 *
 * Verified EDM WSDL evidence
 * (https://test.edmbilisim.com.tr/EFaturaEDM21ea/EFaturaEDM.svc?singleWsdl):
 *
 * - `CreateSerial` (CreateSerialRequest: SERIAL xs:string, SERIALTYPE enum
 *   {EARSIV, EFATURA, INTERNETSATIS}) registers a serial prefix. This is a
 *   one-off provisioning act performed by the accountant / EDM portal, not by
 *   this plugin.
 * - `GetInvoiceSerial` reports the registered serials and their
 *   `LASTSERIALUSED`. It reports state; it does not reserve a number.
 * - `INVOICE/HEADER/INVOICESERIAL_REQUESTED` (xs:token, optional) binds the
 *   document to a registered serial, and `INVOICE/@ID` is optional -- which is
 *   consistent with EDM assigning the number itself.
 *
 * The three-character serial prefix itself is never guessed or hard-coded. It
 * is chosen in the EDM portal and reaches this code only through the reviewed
 * environment configuration; until it is configured, the send path is
 * fail-closed BLOCKED with `invoice_series_unconfigured`.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Numbering {
	/**
	 * The literal EDM documents for "assign this document's number yourself".
	 *
	 * Fixed, identical for every document, and meaningful only inside the
	 * submitted UBL `cbc:ID`.
	 */
	public const AUTO_NUMBER_SENTINEL = 'ABC2009123456789';

	/**
	 * Safe error code for a send attempted without a configured serial prefix.
	 */
	public const ERROR_SERIES_UNCONFIGURED = 'invoice_series_unconfigured';

	/**
	 * Safe error code for a response that carried no usable document number.
	 */
	public const ERROR_NUMBER_NOT_ASSIGNED = 'invoice_number_not_assigned';

	/**
	 * EDM serial send types (CreateSerialRequest/SERIALTYPE enumeration).
	 */
	public const SERIAL_TYPE_EARCHIVE = 'EARSIV';
	public const SERIAL_TYPE_EINVOICE = 'EFATURA';

	/**
	 * Is this value the automatic-numbering request rather than a number?
	 *
	 * Compared after trimming and upper-casing, so a padded or lower-cased copy
	 * cannot slip past as a fiscal identifier.
	 *
	 * @param mixed $value Candidate value.
	 */
	public static function is_auto_number_sentinel( $value ): bool {
		return self::AUTO_NUMBER_SENTINEL === strtoupper( trim( (string) $value ) );
	}

	/**
	 * The serial prefix configured for a document type.
	 *
	 * Never guessed and never defaulted: it is registered in the EDM portal and
	 * reaches this code only through the reviewed environment configuration.
	 *
	 * @param Kuka_Island_Core_Invoice_Config $config        Invoice configuration.
	 * @param string                          $document_type Kuka_Island_Core_Invoice_Status::TYPE_* value.
	 * @return string Three-character serial prefix.
	 * @throws Kuka_Island_Core_Invoice_Permanent_Exception When no valid prefix is configured.
	 */
	public static function resolve_series( Kuka_Island_Core_Invoice_Config $config, string $document_type ): string {
		$series = Kuka_Island_Core_Invoice_Status::TYPE_EINVOICE === $document_type
			? $config->get_series_einvoice()
			: $config->get_series_earchive();

		$series = strtoupper( trim( (string) $series ) );

		if ( 1 !== preg_match( '/^[A-Z0-9]{3}$/', $series ) ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				sprintf( 'No three-character serial prefix is configured for %s.', $document_type ),
				self::ERROR_SERIES_UNCONFIGURED,
				__( 'Fatura serisi yapılandırılmadığı için gönderim durduruldu. Seri, EDM portalından seçilip ortam yapılandırmasına girilmelidir.', 'kuka-island-edm' )
			);
		}

		return $series;
	}

	/**
	 * The value the submitted UBL `cbc:ID` must carry.
	 *
	 * Always the sentinel: EDM assigns the number. The configured serial prefix
	 * is validated here so a document is never submitted against a serial the
	 * shop has not registered, and travels separately in
	 * INVOICE/HEADER/INVOICESERIAL_REQUESTED.
	 *
	 * @param Kuka_Island_Core_Invoice_Config $config        Invoice configuration.
	 * @param string                          $document_type Kuka_Island_Core_Invoice_Status::TYPE_* value.
	 * @return string The automatic-numbering sentinel.
	 * @throws Kuka_Island_Core_Invoice_Permanent_Exception When no valid serial prefix is configured.
	 */
	public static function resolve_requested_number( Kuka_Island_Core_Invoice_Config $config, string $document_type ): string {
		self::resolve_series( $config, $document_type );

		return self::AUTO_NUMBER_SENTINEL;
	}

	/**
	 * The EDM-assigned number on an order, or '' when there is none.
	 *
	 * Provenance is mandatory. Rows written by the removed local generator carry
	 * a number but no provenance marker, and several of them share the same
	 * value across different orders, so a bare number is never a fiscal
	 * identifier. The sentinel is not one either.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public static function assigned_number( WC_Order $order ): string {
		$assigned = trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, true ) );
		$source   = trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE, true ) );

		if ( '' === $assigned || Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM !== $source ) {
			return '';
		}

		return self::is_auto_number_sentinel( $assigned ) ? '' : $assigned;
	}

	/**
	 * Map a document type to the EDM serial type enumeration value.
	 *
	 * @param string $document_type Kuka_Island_Core_Invoice_Status::TYPE_* value.
	 */
	public static function serial_type_for_document( string $document_type ): string {
		return Kuka_Island_Core_Invoice_Status::TYPE_EINVOICE === $document_type
			? self::SERIAL_TYPE_EINVOICE
			: self::SERIAL_TYPE_EARCHIVE;
	}
}
