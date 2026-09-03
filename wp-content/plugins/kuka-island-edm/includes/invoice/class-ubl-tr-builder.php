<?php
/**
 * UBL-TR 2.1 Invoice XML Builder.
 *
 * Generates standards-compliant Turkish e-Invoice (e-Fatura) and e-Archive
 * (e-Arşiv) UBL 2.1 XML documents.
 *
 * Fail-closed policy: this builder never substitutes a placeholder for missing
 * fiscal data. Every mandatory field must be supplied by
 * Kuka_Island_Core_Invoice_Order_Mapper, which validates it. A missing value
 * raises Kuka_Island_Core_Invoice_Permanent_Exception with `ubl_missing_field`
 * instead of emitting an invented VKN, city, company name or tax rate.
 *
 * Discount handling matches the mapper's line-level allowance approach: each
 * discounted InvoiceLine carries its own cac:AllowanceCharge and its
 * LineExtensionAmount is already net, so no document-level AllowanceTotalAmount
 * is emitted and the discount is never deducted twice.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_UBL_TR_Builder {
	private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
	private const NS_CAC     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
	private const NS_CBC     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
	private const NS_EXT     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

	private array $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Build complete UTF-8 UBL-TR 2.1 XML document.
	 *
	 * @throws Kuka_Island_Core_Invoice_Permanent_Exception When a mandatory field is missing.
	 */
	public function build_xml(): string {
		$dom                     = new DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput       = true;
		$dom->preserveWhiteSpace = false;

		$invoice = $dom->createElementNS( self::NS_INVOICE, 'Invoice' );
		$dom->appendChild( $invoice );

		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC );
		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC );
		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:ccts', 'urn:un:unece:uncefact:documentation:2' );
		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#' );
		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::NS_EXT );
		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:ubltr', 'urn:oasis:names:specification:ubl:schema:xsd:TurkishCustomizationExtensionComponents' );
		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:qdt', 'urn:oasis:names:specification:ubl:schema:xsd:QualifiedDatatypes-2' );
		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:udt', 'urn:un:unece:uncefact:data:specification:UnqualifiedDataTypesSchemaModule:2' );
		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance' );

		// UBLExtensions placeholder (signature container).
		$ubl_extensions = $dom->createElementNS( self::NS_EXT, 'ext:UBLExtensions' );
		$ubl_extension  = $dom->createElementNS( self::NS_EXT, 'ext:UBLExtension' );
		$ubl_extension->appendChild( $dom->createElementNS( self::NS_EXT, 'ext:ExtensionContent' ) );
		$ubl_extensions->appendChild( $ubl_extension );
		$invoice->appendChild( $ubl_extensions );

		$currency       = $this->required( $this->data['currency'] ?? null, 'currency' );
		$invoice_number = $this->required( $this->data['invoice_number'] ?? null, 'invoice_number' );
		$uuid           = $this->required( $this->data['uuid'] ?? null, 'uuid' );
		$issue_date     = $this->required( $this->data['issue_date'] ?? null, 'issue_date' );
		$issue_time     = $this->required( $this->data['issue_time'] ?? null, 'issue_time' );
		$profile_id     = $this->required( $this->data['profile_id'] ?? null, 'profile_id' );
		$type_code      = $this->required( $this->data['invoice_type_code'] ?? null, 'invoice_type_code' );

		$this->append_cbc( $dom, $invoice, 'cbc:UBLVersionID', '2.1' );
		$this->append_cbc( $dom, $invoice, 'cbc:CustomizationID', 'TR1.2' );
		$this->append_cbc( $dom, $invoice, 'cbc:ProfileID', $profile_id );
		$this->append_cbc( $dom, $invoice, 'cbc:ID', $invoice_number );
		$this->append_cbc( $dom, $invoice, 'cbc:CopyIndicator', 'false' );
		$this->append_cbc( $dom, $invoice, 'cbc:UUID', $uuid );
		$this->append_cbc( $dom, $invoice, 'cbc:IssueDate', $issue_date );
		$this->append_cbc( $dom, $invoice, 'cbc:IssueTime', $issue_time );
		$this->append_cbc( $dom, $invoice, 'cbc:InvoiceTypeCode', $type_code );

		foreach ( (array) ( $this->data['notes'] ?? array() ) as $note ) {
			$note_text = trim( (string) $note );
			if ( '' !== $note_text ) {
				$this->append_cbc( $dom, $invoice, 'cbc:Note', $note_text );
			}
		}

		$this->append_cbc( $dom, $invoice, 'cbc:DocumentCurrencyCode', $currency );
		$this->append_cbc( $dom, $invoice, 'cbc:LineCountNumeric', (string) count( (array) ( $this->data['lines'] ?? array() ) ) );

		if ( ! empty( $this->data['order_number'] ) ) {
			$order_ref = $dom->createElement( 'cac:OrderReference' );
			$this->append_cbc( $dom, $order_ref, 'cbc:ID', (string) $this->data['order_number'] );
			if ( ! empty( $this->data['order_date'] ) ) {
				$this->append_cbc( $dom, $order_ref, 'cbc:IssueDate', (string) $this->data['order_date'] );
			}
			$invoice->appendChild( $order_ref );
		}

		// e-Arşiv sending format reference. The identifier is derived from the
		// invoice UUID so rebuilding the same invoice produces identical XML
		// (a fresh random UUID would break retry idempotency).
		if ( 'EARSIVFATURA' === $profile_id ) {
			$doc_ref = $dom->createElement( 'cac:AdditionalDocumentReference' );
			$this->append_cbc( $dom, $doc_ref, 'cbc:ID', $uuid );
			$this->append_cbc( $dom, $doc_ref, 'cbc:IssueDate', $issue_date );
			$this->append_cbc( $dom, $doc_ref, 'cbc:DocumentTypeCode', 'GÖNDERİM_TÜRÜ' );
			$this->append_cbc( $dom, $doc_ref, 'cbc:DocumentType', 'ELEKTRONIK' );
			$invoice->appendChild( $doc_ref );
		}

		$supplier     = (array) ( $this->data['supplier'] ?? array() );
		$supplier_vkn = $this->required( $supplier['vkn'] ?? null, 'supplier.vkn' );

		// Signature container.
		$signature = $dom->createElement( 'cac:Signature' );
		$this->append_cbc( $dom, $signature, 'cbc:ID', $supplier_vkn, array( 'schemeID' => 'VKN_TCKN' ) );
		$sig_party    = $dom->createElement( 'cac:SignatoryParty' );
		$sig_party_id = $dom->createElement( 'cac:PartyIdentification' );
		$this->append_cbc( $dom, $sig_party_id, 'cbc:ID', $supplier_vkn, array( 'schemeID' => 'VKN' ) );
		$sig_party->appendChild( $sig_party_id );
		$signature->appendChild( $sig_party );
		$digital_sig = $dom->createElement( 'cac:DigitalSignatureAttachment' );
		$ext_doc_ref = $dom->createElement( 'cac:ExternalReference' );
		$this->append_cbc( $dom, $ext_doc_ref, 'cbc:URI', '#Signature' );
		$digital_sig->appendChild( $ext_doc_ref );
		$signature->appendChild( $digital_sig );
		$invoice->appendChild( $signature );

		$this->append_supplier_party( $dom, $invoice, $supplier );
		$this->append_customer_party( $dom, $invoice, (array) ( $this->data['customer'] ?? array() ) );
		$this->append_payment_means( $dom, $invoice, (array) ( $this->data['payment'] ?? array() ) );
		$this->append_document_charges( $dom, $invoice, (array) ( $this->data['totals'] ?? array() ), $currency );
		$this->append_tax_total( $dom, $invoice, (array) ( $this->data['tax_summary'] ?? array() ), $currency );
		$this->append_legal_monetary_total( $dom, $invoice, (array) ( $this->data['totals'] ?? array() ), $currency );

		$line_index = 1;
		foreach ( (array) ( $this->data['lines'] ?? array() ) as $line_data ) {
			$this->append_invoice_line( $dom, $invoice, (array) $line_data, $line_index, $currency );
			++$line_index;
		}

		return (string) $dom->saveXML();
	}

	/**
	 * Require a non-empty mandatory fiscal field.
	 *
	 * @param mixed  $value Candidate value.
	 * @param string $field Field path used in the error message.
	 * @throws Kuka_Island_Core_Invoice_Permanent_Exception When the value is absent.
	 */
	private function required( $value, string $field ): string {
		$str = trim( (string) ( $value ?? '' ) );
		if ( '' === $str ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				sprintf( 'Mandatory UBL field "%s" is missing; refusing to emit a placeholder.', $field ),
				'ubl_missing_field',
				__( 'Zorunlu fatura alanı eksik olduğu için UBL belgesi üretilmedi.', 'kuka-island-edm' )
			);
		}

		return $str;
	}

	private function append_cbc( DOMDocument $dom, DOMElement $parent, string $name, string $value, array $attributes = array() ): DOMElement {
		$elem = $dom->createElement( $name, htmlspecialchars( $value, ENT_XML1, 'UTF-8' ) );
		foreach ( $attributes as $key => $attr_value ) {
			$elem->setAttribute( $key, (string) $attr_value );
		}
		$parent->appendChild( $elem );

		return $elem;
	}

	private function append_supplier_party( DOMDocument $dom, DOMElement $invoice, array $supplier ): void {
		$sup_party = $dom->createElement( 'cac:AccountingSupplierParty' );
		$party     = $dom->createElement( 'cac:Party' );

		$party_id = $dom->createElement( 'cac:PartyIdentification' );
		$this->append_cbc( $dom, $party_id, 'cbc:ID', $this->required( $supplier['vkn'] ?? null, 'supplier.vkn' ), array( 'schemeID' => 'VKN' ) );
		$party->appendChild( $party_id );

		$party_name = $dom->createElement( 'cac:PartyName' );
		$this->append_cbc( $dom, $party_name, 'cbc:Name', $this->required( $supplier['name'] ?? null, 'supplier.name' ) );
		$party->appendChild( $party_name );

		$address = $dom->createElement( 'cac:PostalAddress' );
		$this->append_cbc( $dom, $address, 'cbc:StreetName', $this->required( $supplier['address'] ?? null, 'supplier.address' ) );
		$this->append_cbc( $dom, $address, 'cbc:CitySubdivisionName', $this->required( $supplier['district'] ?? null, 'supplier.district' ) );
		$this->append_cbc( $dom, $address, 'cbc:CityName', $this->required( $supplier['city'] ?? null, 'supplier.city' ) );
		// Optional. EDM's own sample invoices carry no supplier cbc:PostalZone,
		// so a missing postcode omits the element entirely rather than emitting
		// an empty node -- an empty PostalZone would be a schema violation, not
		// a neutral placeholder.
		$supplier_postcode = trim( (string) ( $supplier['postcode'] ?? '' ) );
		if ( '' !== $supplier_postcode ) {
			$this->append_cbc( $dom, $address, 'cbc:PostalZone', $supplier_postcode );
		}
		$country = $dom->createElement( 'cac:Country' );
		$this->append_cbc( $dom, $country, 'cbc:Name', $this->required( $supplier['country'] ?? null, 'supplier.country' ) );
		$address->appendChild( $country );
		$party->appendChild( $address );

		$party_tax  = $dom->createElement( 'cac:PartyTaxScheme' );
		$tax_scheme = $dom->createElement( 'cac:TaxScheme' );
		$this->append_cbc( $dom, $tax_scheme, 'cbc:Name', $this->required( $supplier['tax_office'] ?? null, 'supplier.tax_office' ) );
		$party_tax->appendChild( $tax_scheme );
		$party->appendChild( $party_tax );

		if ( ! empty( $supplier['email'] ) || ! empty( $supplier['phone'] ) ) {
			$contact = $dom->createElement( 'cac:Contact' );
			if ( ! empty( $supplier['phone'] ) ) {
				$this->append_cbc( $dom, $contact, 'cbc:Telephone', (string) $supplier['phone'] );
			}
			if ( ! empty( $supplier['email'] ) ) {
				$this->append_cbc( $dom, $contact, 'cbc:ElectronicMail', (string) $supplier['email'] );
			}
			$party->appendChild( $contact );
		}

		$sup_party->appendChild( $party );
		$invoice->appendChild( $sup_party );
	}

	private function append_customer_party( DOMDocument $dom, DOMElement $invoice, array $customer ): void {
		$cust_party = $dom->createElement( 'cac:AccountingCustomerParty' );
		$party      = $dom->createElement( 'cac:Party' );

		$tax_number = $this->required( $customer['tax_number'] ?? null, 'customer.tax_number' );
		$id_scheme  = 11 === strlen( $tax_number ) ? 'TCKN' : 'VKN';

		$party_id = $dom->createElement( 'cac:PartyIdentification' );
		$this->append_cbc( $dom, $party_id, 'cbc:ID', $tax_number, array( 'schemeID' => $id_scheme ) );
		$party->appendChild( $party_id );

		/*
		 * cac:Person is BUILT here and APPENDED last, after cac:Contact.
		 *
		 * UBL 2.1's PartyType sequence -- and EDM's own WSDL, which declares
		 * PartyIdentification, PartyName, PostalAddress, PhysicalLocation,
		 * PartyTaxScheme, PartyLegalEntity, Contact, Person -- puts Person
		 * AFTER Contact. Appending it here, next to PartyIdentification, put it
		 * in an invalid position and is what EDM refused a SendInvoice for on
		 * 3 September 2026.
		 *
		 * The node is created once and moved, never copied: a second Person
		 * would be a different defect with the same symptom.
		 */
		$person = null;

		if ( ! empty( $customer['company'] ) ) {
			$party_name = $dom->createElement( 'cac:PartyName' );
			$this->append_cbc( $dom, $party_name, 'cbc:Name', (string) $customer['company'] );
			$party->appendChild( $party_name );
		} else {
			/*
			 * An individual e-Arşiv recipient is identified by the generic
			 * consumer TCKN plus a REAL name. Both parts are mandatory: an empty
			 * cbc:FirstName or cbc:FamilyName would leave the document without
			 * an identifiable buyer, and a generic consumer substitute would be a
			 * fabricated party name.
			 */
			$person = $dom->createElement( 'cac:Person' );
			$this->append_cbc( $dom, $person, 'cbc:FirstName', $this->required( $customer['first_name'] ?? null, 'customer.first_name' ) );
			$this->append_cbc( $dom, $person, 'cbc:FamilyName', $this->required( $customer['last_name'] ?? null, 'customer.last_name' ) );
		}

		$address = $dom->createElement( 'cac:PostalAddress' );
		$this->append_cbc( $dom, $address, 'cbc:StreetName', $this->required( $customer['address'] ?? null, 'customer.address' ) );
		$this->append_cbc( $dom, $address, 'cbc:CitySubdivisionName', (string) ( $customer['district'] ?? '' ) );
		$this->append_cbc( $dom, $address, 'cbc:CityName', $this->required( $customer['city'] ?? null, 'customer.city' ) );
		$this->append_cbc( $dom, $address, 'cbc:PostalZone', (string) ( $customer['postcode'] ?? '' ) );
		$country = $dom->createElement( 'cac:Country' );
		$this->append_cbc( $dom, $country, 'cbc:Name', $this->required( $customer['country'] ?? null, 'customer.country' ) );
		$address->appendChild( $country );
		$party->appendChild( $address );

		$party_tax  = $dom->createElement( 'cac:PartyTaxScheme' );
		$tax_scheme = $dom->createElement( 'cac:TaxScheme' );
		$this->append_cbc( $dom, $tax_scheme, 'cbc:Name', (string) ( $customer['tax_office'] ?? '' ) );
		$party_tax->appendChild( $tax_scheme );
		$party->appendChild( $party_tax );

		/*
		 * EDM delivers the e-Arşiv document to this address itself, so
		 * cbc:ElectronicMail is mandatory rather than decorative. The same
		 * address goes into SendInvoiceRequest/INVOICE/HEADER/TO; there is no
		 * separate EmailInvoice call.
		 */
		$contact = $dom->createElement( 'cac:Contact' );
		if ( ! empty( $customer['phone'] ) ) {
			$this->append_cbc( $dom, $contact, 'cbc:Telephone', (string) $customer['phone'] );
		}
		$this->append_cbc( $dom, $contact, 'cbc:ElectronicMail', $this->required( $customer['email'] ?? null, 'customer.email' ) );
		$party->appendChild( $contact );

		// Last in PartyType's sequence, and only for an individual buyer.
		if ( null !== $person ) {
			$party->appendChild( $person );
		}

		$cust_party->appendChild( $party );
		$invoice->appendChild( $cust_party );
	}

	private function append_payment_means( DOMDocument $dom, DOMElement $invoice, array $payment ): void {
		$pay_means = $dom->createElement( 'cac:PaymentMeans' );
		$this->append_cbc( $dom, $pay_means, 'cbc:PaymentMeansCode', $this->required( $payment['code'] ?? null, 'payment.code' ) );
		if ( ! empty( $payment['due_date'] ) ) {
			$this->append_cbc( $dom, $pay_means, 'cbc:PaymentDueDate', (string) $payment['due_date'] );
		}
		if ( ! empty( $payment['channel'] ) ) {
			$this->append_cbc( $dom, $pay_means, 'cbc:PaymentChannelCode', (string) $payment['channel'] );
		}
		$invoice->appendChild( $pay_means );

		if ( ! empty( $payment['terms'] ) ) {
			$pay_terms = $dom->createElement( 'cac:PaymentTerms' );
			$this->append_cbc( $dom, $pay_terms, 'cbc:Note', (string) $payment['terms'] );
			$invoice->appendChild( $pay_terms );
		}
	}

	/**
	 * Document-level charges. Only the shipping charge lives here; the coupon
	 * discount is attributed to the invoice lines.
	 */
	private function append_document_charges( DOMDocument $dom, DOMElement $invoice, array $totals, string $currency ): void {
		if ( self::amount_to_cents( $totals['charge_total_amount'] ?? '0.00' ) <= 0 ) {
			return;
		}

		$charge = $dom->createElement( 'cac:AllowanceCharge' );
		$this->append_cbc( $dom, $charge, 'cbc:ChargeIndicator', 'true' );
		$this->append_cbc( $dom, $charge, 'cbc:AllowanceChargeReason', 'Kargo Bedeli' );
		$this->append_cbc( $dom, $charge, 'cbc:Amount', $this->format_amount( $totals['charge_total_amount'] ), array( 'currencyID' => $currency ) );
		$invoice->appendChild( $charge );
	}

	private function append_tax_total( DOMDocument $dom, DOMElement $invoice, array $tax_summary, string $currency ): void {
		$tax_total = $dom->createElement( 'cac:TaxTotal' );
		$this->append_cbc( $dom, $tax_total, 'cbc:TaxAmount', $this->format_amount( $tax_summary['total_tax'] ?? '0.00' ), array( 'currencyID' => $currency ) );

		foreach ( (array) ( $tax_summary['rates'] ?? array() ) as $rate_data ) {
			$rate_data = (array) $rate_data;
			if ( ! array_key_exists( 'percent', $rate_data ) ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'Tax subtotal is missing its verified percentage.',
					'ubl_missing_field',
					__( 'Vergi oranı bilgisi eksik olduğu için UBL belgesi üretilmedi.', 'kuka-island-edm' )
				);
			}

			$subtotal = $dom->createElement( 'cac:TaxSubtotal' );
			$this->append_cbc( $dom, $subtotal, 'cbc:TaxableAmount', $this->format_amount( $rate_data['taxable_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );
			$this->append_cbc( $dom, $subtotal, 'cbc:TaxAmount', $this->format_amount( $rate_data['tax_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );
			$this->append_cbc( $dom, $subtotal, 'cbc:Percent', (string) (int) $rate_data['percent'] );

			$category   = $dom->createElement( 'cac:TaxCategory' );
			$tax_scheme = $dom->createElement( 'cac:TaxScheme' );
			$this->append_cbc( $dom, $tax_scheme, 'cbc:Name', 'KDV' );
			$this->append_cbc( $dom, $tax_scheme, 'cbc:TaxTypeCode', '0015' );
			$category->appendChild( $tax_scheme );
			$subtotal->appendChild( $category );
			$tax_total->appendChild( $subtotal );
		}

		$invoice->appendChild( $tax_total );
	}

	private function append_legal_monetary_total( DOMDocument $dom, DOMElement $invoice, array $totals, string $currency ): void {
		$monetary = $dom->createElement( 'cac:LegalMonetaryTotal' );

		$this->append_cbc( $dom, $monetary, 'cbc:LineExtensionAmount', $this->format_amount( $totals['line_extension_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );
		$this->append_cbc( $dom, $monetary, 'cbc:TaxExclusiveAmount', $this->format_amount( $totals['tax_exclusive_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );
		$this->append_cbc( $dom, $monetary, 'cbc:TaxInclusiveAmount', $this->format_amount( $totals['tax_inclusive_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );

		if ( self::amount_to_cents( $totals['charge_total_amount'] ?? '0.00' ) > 0 ) {
			$this->append_cbc( $dom, $monetary, 'cbc:ChargeTotalAmount', $this->format_amount( $totals['charge_total_amount'] ), array( 'currencyID' => $currency ) );
		}

		// UBL 2.1 LegalMonetaryTotal sequence order: ... ChargeTotalAmount,
		// PrepaidAmount, PayableRoundingAmount, PayableAmount. The rounding term
		// carries the per-line vs per-bucket tax rounding difference so
		// PayableAmount equals the amount actually charged.
		$rounding_cents = self::amount_to_cents( $totals['payable_rounding_amount'] ?? '0.00' );
		if ( 0 !== $rounding_cents ) {
			$this->append_cbc( $dom, $monetary, 'cbc:PayableRoundingAmount', self::cents_to_amount( $rounding_cents ), array( 'currencyID' => $currency ) );
		}

		$this->append_cbc( $dom, $monetary, 'cbc:PayableAmount', $this->format_amount( $totals['payable_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );

		$invoice->appendChild( $monetary );
	}

	private function append_invoice_line( DOMDocument $dom, DOMElement $invoice, array $line, int $index, string $currency ): void {
		if ( ! array_key_exists( 'tax_percent', $line ) ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				sprintf( 'Invoice line %d has no verified tax percentage.', $index ),
				'ubl_missing_field',
				__( 'Fatura satırının KDV oranı doğrulanmadığı için UBL belgesi üretilmedi.', 'kuka-island-edm' )
			);
		}

		$inv_line = $dom->createElement( 'cac:InvoiceLine' );

		$this->append_cbc( $dom, $inv_line, 'cbc:ID', (string) $index );
		$this->append_cbc( $dom, $inv_line, 'cbc:InvoicedQuantity', (string) max( 1, (int) ( $line['quantity'] ?? 1 ) ), array( 'unitCode' => 'C62' ) );
		$this->append_cbc( $dom, $inv_line, 'cbc:LineExtensionAmount', $this->format_amount( $line['line_extension_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );

		// Line-level coupon/discount attribution.
		if ( self::amount_to_cents( $line['allowance_amount'] ?? '0.00' ) > 0 ) {
			$allowance = $dom->createElement( 'cac:AllowanceCharge' );
			$this->append_cbc( $dom, $allowance, 'cbc:ChargeIndicator', 'false' );
			$this->append_cbc( $dom, $allowance, 'cbc:AllowanceChargeReason', 'İskonto / Kupon İndirimi' );
			$this->append_cbc( $dom, $allowance, 'cbc:Amount', $this->format_amount( $line['allowance_amount'] ), array( 'currencyID' => $currency ) );
			$this->append_cbc( $dom, $allowance, 'cbc:BaseAmount', $this->format_amount( $line['gross_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );
			$inv_line->appendChild( $allowance );
		}

		$tax_total = $dom->createElement( 'cac:TaxTotal' );
		$this->append_cbc( $dom, $tax_total, 'cbc:TaxAmount', $this->format_amount( $line['tax_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );
		$subtotal = $dom->createElement( 'cac:TaxSubtotal' );
		$this->append_cbc( $dom, $subtotal, 'cbc:TaxableAmount', $this->format_amount( $line['taxable_amount'] ?? $line['line_extension_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );
		$this->append_cbc( $dom, $subtotal, 'cbc:TaxAmount', $this->format_amount( $line['tax_amount'] ?? '0.00' ), array( 'currencyID' => $currency ) );
		$this->append_cbc( $dom, $subtotal, 'cbc:Percent', (string) (int) $line['tax_percent'] );
		$category = $dom->createElement( 'cac:TaxCategory' );
		$scheme   = $dom->createElement( 'cac:TaxScheme' );
		$this->append_cbc( $dom, $scheme, 'cbc:Name', 'KDV' );
		$this->append_cbc( $dom, $scheme, 'cbc:TaxTypeCode', '0015' );
		$category->appendChild( $scheme );
		$subtotal->appendChild( $category );
		$tax_total->appendChild( $subtotal );
		$inv_line->appendChild( $tax_total );

		$item = $dom->createElement( 'cac:Item' );
		$this->append_cbc( $dom, $item, 'cbc:Name', $this->required( $line['name'] ?? null, sprintf( 'lines[%d].name', $index ) ) );
		if ( ! empty( $line['sku'] ) ) {
			$sellers_id = $dom->createElement( 'cac:SellersItemIdentification' );
			$this->append_cbc( $dom, $sellers_id, 'cbc:ID', (string) $line['sku'] );
			$item->appendChild( $sellers_id );
		}
		$inv_line->appendChild( $item );

		$price = $dom->createElement( 'cac:Price' );
		$this->append_cbc( $dom, $price, 'cbc:PriceAmount', $this->format_amount( $line['unit_price'] ?? '0.00' ), array( 'currencyID' => $currency ) );
		$inv_line->appendChild( $price );

		$invoice->appendChild( $inv_line );
	}

	public static function amount_to_cents( string|int|float|null $amount ): int {
		return Kuka_Island_Core_Invoice_Order_Mapper::amount_to_cents( $amount );
	}

	public static function cents_to_amount( int $cents ): string {
		return Kuka_Island_Core_Invoice_Order_Mapper::cents_to_amount( $cents );
	}

	private function format_amount( $amount ): string {
		if ( is_int( $amount ) ) {
			return self::cents_to_amount( $amount );
		}

		return self::cents_to_amount( self::amount_to_cents( $amount ) );
	}
}
