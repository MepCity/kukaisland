<?php
/**
 * Fiscal document number resolution.
 *
 * Turkish e-Fatura / e-Arşiv document numbers are legal fiscal identifiers.
 * They may only originate from the integrator's registered serial counter --
 * never from a WooCommerce order ID, a local option counter, a timestamp, or
 * any other locally invented sequence.
 *
 * Verified EDM WSDL evidence
 * (https://test.edmbilisim.com.tr/EFaturaEDM21ea/EFaturaEDM.svc?singleWsdl):
 *
 * - `CreateSerial` (CreateSerialRequest: SERIAL xs:string, SERIALTYPE enum
 *   {EARSIV, EFATURA, INTERNETSATIS}) registers a serial prefix. This is a
 *   one-off provisioning act performed by the accountant / EDM portal, not by
 *   this plugin.
 * - `GetInvoiceSerial` (GetInvoiceSerialRequest: INVOICESERIALCODE xs:token,
 *   INVOICESENDTYPE xs:token, YEAR xs:int) reports the registered serials and
 *   their `LASTSERIALUSED` (INVOICESERIALLIST/LASTSERIALUSED xs:int nillable).
 *   It reports state; it does not reserve or hand out the next number.
 * - `INVOICE/HEADER/INVOICESERIAL_REQUESTED` (xs:token, optional) is the field
 *   that asks EDM to assign the document number from a registered serial at
 *   SendInvoice time. `INVOICE/@ID` is an optional attribute, consistent with
 *   EDM assigning the number itself.
 *
 * What the WSDL does NOT establish: whether EDM stamps the assigned number
 * back into the UBL `cbc:ID` element of the submitted CONTENT. UBL-TR 2.1
 * TR1.2 requires `cbc:ID` to carry the document number, and that value cannot
 * be produced locally without inventing a fiscal number.
 *
 * Therefore the send path is fail-closed BLOCKED with
 * `invoice_numbering_unconfirmed` until the assignment semantics are confirmed
 * against a real EDM account. Nothing here fabricates a number.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Numbering {
	/**
	 * Safe error code for the fail-closed numbering block.
	 */
	public const ERROR_UNCONFIRMED = 'invoice_numbering_unconfirmed';

	/**
	 * EDM serial send types (CreateSerialRequest/SERIALTYPE enumeration).
	 */
	public const SERIAL_TYPE_EARCHIVE = 'EARSIV';
	public const SERIAL_TYPE_EINVOICE = 'EFATURA';

	/**
	 * Resolve the fiscal document number for an order.
	 *
	 * The only accepted source is a number already assigned by EDM and
	 * persisted on the order by a previous EDM response.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string EDM-assigned document number.
	 * @throws Kuka_Island_Core_Invoice_Permanent_Exception When no EDM-assigned number exists.
	 */
	public static function resolve_assigned_number( WC_Order $order ): string {
		$assigned = trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, true ) );
		$source   = trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE, true ) );

		// Provenance is mandatory. Rows written by the removed local generator
		// carry a number but no provenance marker, and several of them share the
		// same value across different orders, so a bare number is never trusted
		// as a fiscal identifier.
		if ( '' !== $assigned && Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM === $source ) {
			return $assigned;
		}

		throw new Kuka_Island_Core_Invoice_Permanent_Exception(
			sprintf(
				'No EDM-assigned invoice number is available (number:%s, provenance:%s) and local fiscal numbering is prohibited.',
				'' === $assigned ? 'absent' : 'present',
				'' === $source ? 'absent' : $source
			),
			self::ERROR_UNCONFIRMED,
			__( 'Fatura numarası yalnızca EDM tarafından atanabilir. EDM numara atama sözleşmesi doğrulanmadığı için gönderim güvenli biçimde durduruldu.', 'kuka-island-core' )
		);
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
