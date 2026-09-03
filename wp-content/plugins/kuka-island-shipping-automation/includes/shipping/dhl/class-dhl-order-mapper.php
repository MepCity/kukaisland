<?php
/**
 * Translate the carrier-agnostic shipment request into the vendor's payloads.
 *
 * This is the only file in the plugin that knows the vendor's numeric
 * enumerations, and that is the whole point: the manager says 'package' and
 * 'sender', and a second courier's adapter maps the same two words to whatever
 * ITS document uses. Every enumeration below is transcribed from the vendor's
 * own property descriptions:
 *
 *   shipmentServiceType  1:STANDART_TESLİMAT, 7:GUNİCİ_TESLİMAT, 8:AKŞAM_TESLİMAT
 *   packagingType        1:DOSYA, 2:Mİ, 3:PAKET, 4:KOLİ
 *   paymentType          1:GONDERICI_ODER, 2:ALICI_ODER, 3:PLATFORM_ODER
 *                        -- and, verbatim: "Method CreateOrder does not apply to
 *                           payment type 3", so 3 is not mappable here at all.
 *   deliveryType         1:ADRESE_TESLIM, 2:ALICISI_HABERLİ
 *
 * A token with no mapping is refused rather than defaulted. Defaulting an
 * unknown packaging type to "package" would ship a wardrobe as an envelope and
 * nobody would find out until the invoice arrived.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_DHL_Order_Mapper {

	/** @return array<string, int> */
	public static function service_types(): array {
		return array(
			'standard' => 1,
			'same_day' => 7,
			'evening'  => 8,
		);
	}

	/** @return array<string, int> */
	public static function packaging_types(): array {
		return array(
			'file'    => 1,
			'mi'      => 2,
			'package' => 3,
			'box'     => 4,
		);
	}

	/**
	 * Payment types valid for createOrder.
	 *
	 * 3 (PLATFORM_ODER) is absent because the vendor states createOrder does not
	 * accept it. Leaving it out of the map is stronger than checking for it: it
	 * cannot be requested at all.
	 *
	 * @return array<string, int>
	 */
	public static function payment_types(): array {
		return array(
			'sender'    => 1,
			'recipient' => 2,
		);
	}

	/** @return array<string, int> */
	public static function delivery_types(): array {
		return array(
			'to_address'       => 1,
			'notify_recipient' => 2,
		);
	}

	/**
	 * Fields a shipment request must carry before any payload is built.
	 *
	 * @param array<string, mixed> $shipment Shipment request.
	 * @return array<int, string> Missing or invalid field names.
	 */
	public static function validate( array $shipment ): array {
		$gaps = array();

		$reference = (string) ( $shipment['reference'] ?? '' );
		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
			$gaps[] = 'reference';
		}

		if ( ! isset( self::service_types()[ (string) ( $shipment['service'] ?? '' ) ] ) ) {
			$gaps[] = 'service';
		}
		if ( ! isset( self::packaging_types()[ (string) ( $shipment['packaging'] ?? '' ) ] ) ) {
			$gaps[] = 'packaging';
		}
		if ( ! isset( self::payment_types()[ (string) ( $shipment['payment'] ?? '' ) ] ) ) {
			$gaps[] = 'payment';
		}
		if ( ! isset( self::delivery_types()[ (string) ( $shipment['delivery'] ?? '' ) ] ) ) {
			$gaps[] = 'delivery';
		}

		if ( '' === trim( (string) ( $shipment['content'] ?? '' ) ) ) {
			$gaps[] = 'content';
		}
		if ( '' === trim( (string) ( $shipment['description'] ?? '' ) ) ) {
			$gaps[] = 'description';
		}

		$pieces = (array) ( $shipment['pieces'] ?? array() );
		if ( array() === $pieces ) {
			$gaps[] = 'pieces';
		} else {
			foreach ( $pieces as $piece ) {
				if ( ! is_array( $piece )
					|| '' === trim( (string) ( $piece['barcode'] ?? '' ) )
					|| (int) ( $piece['desi'] ?? 0 ) < 1
					|| (int) ( $piece['kg'] ?? 0 ) < 1 ) {
					$gaps[] = 'pieces';
					break;
				}
			}
		}

		$recipient = (array) ( $shipment['recipient'] ?? array() );
		foreach ( array( 'full_name', 'address' ) as $field ) {
			if ( '' === trim( (string) ( $recipient[ $field ] ?? '' ) ) ) {
				$gaps[] = 'recipient.' . $field;
			}
		}

		if ( (int) ( $recipient['city_code'] ?? 0 ) < 1 ) {
			$gaps[] = 'recipient.city_code';
		}
		if ( (int) ( $recipient['district_code'] ?? 0 ) < 1 ) {
			$gaps[] = 'recipient.district_code';
		}

		return array_values( array_unique( $gaps ) );
	}

	/**
	 * CreateOrderRequest.
	 *
	 * barcode is set to referenceId because the vendor requires exactly that:
	 * "Barcode must be same with ReferenceId."
	 *
	 * isCOD and codAmount are written as 0 unconditionally. Cash on delivery is
	 * refused upstream, and hard-writing the safe value here means a future
	 * caller that forgot the upstream check still cannot send a COD parcel.
	 *
	 * @param array<string, mixed> $shipment Shipment request.
	 * @return array<string, mixed>
	 */
	public static function create_order_payload( array $shipment ): array {
		$reference = strtoupper( (string) $shipment['reference'] );
		$recipient = (array) ( $shipment['recipient'] ?? array() );

		return array(
			'order'         => array(
				'referenceId'         => $reference,
				'barcode'             => $reference,
				'billOfLandingId'     => (string) ( $shipment['bill_of_landing_id'] ?? '' ),
				'isCOD'               => 0,
				'codAmount'           => 0,
				'shipmentServiceType' => self::service_types()[ (string) $shipment['service'] ],
				'packagingType'       => self::packaging_types()[ (string) $shipment['packaging'] ],
				'content'             => self::text( (string) $shipment['content'], 200 ),
				'smsPreference1'      => self::flag( $shipment['sms1'] ?? 0 ),
				'smsPreference2'      => self::flag( $shipment['sms2'] ?? 0 ),
				'smsPreference3'      => self::flag( $shipment['sms3'] ?? 0 ),
				'paymentType'         => self::payment_types()[ (string) $shipment['payment'] ],
				'deliveryType'        => self::delivery_types()[ (string) $shipment['delivery'] ],
				'description'         => self::text( (string) $shipment['description'], 200 ),
				'marketPlaceShortCode' => '',
				'marketPlaceSaleCode' => '',
			),
			'orderPieceList' => self::piece_list( $shipment ),
			'recipient'     => array(
				/*
				 * customerId is deliberately absent. The vendor's Customer
				 * object documents it as "Identity Number of Customer / Müşteri
				 * Numarası" and its example carries a number that is not
				 * explained anywhere in the five documents. Sending an invented
				 * one could attach this parcel to somebody else's account, and
				 * the field is not in any required list. refCustomerId IS
				 * documented -- "Identity Number of Customer in their's own
				 * system" -- so the WooCommerce order id goes there instead.
				 */
				'refCustomerId'        => (string) ( $recipient['ref_customer_id'] ?? '' ),
				'cityCode'             => (int) $recipient['city_code'],
				'districtCode'         => (int) $recipient['district_code'],
				'address'              => self::text( (string) $recipient['address'], 400 ),
				'bussinessPhoneNumber' => self::phone( (string) ( $recipient['business_phone'] ?? '' ) ),
				'email'                => (string) ( $recipient['email'] ?? '' ),
				'taxOffice'            => (string) ( $recipient['tax_office'] ?? '' ),
				'taxNumber'            => (string) ( $recipient['tax_number'] ?? '' ),
				'fullName'             => self::text( (string) $recipient['full_name'], 120 ),
				'homePhoneNumber'      => self::phone( (string) ( $recipient['home_phone'] ?? '' ) ),
				'mobilePhoneNumber'    => self::phone( (string) ( $recipient['mobile_phone'] ?? '' ) ),
			),
		);
	}

	/**
	 * CreateBarcodeRequest.
	 *
	 * printReferenceBarcodeOnError is 0: the vendor describes 1 as "create the
	 * Reference Label instead when the Barcode Label fails". A substitute label
	 * would make a failed barcode look like a successful one on the warehouse
	 * floor, and this integration would record a shipment it does not have.
	 *
	 * @param array<string, mixed> $shipment Shipment request.
	 * @return array<string, mixed>
	 */
	public static function create_barcode_payload( array $shipment ): array {
		return array(
			'referenceId'                  => strtoupper( (string) $shipment['reference'] ),
			'billOfLandingId'              => (string) ( $shipment['bill_of_landing_id'] ?? '' ),
			'isCOD'                        => 0,
			'codAmount'                    => 0,
			'packagingType'                => self::packaging_types()[ (string) $shipment['packaging'] ],
			'printReferenceBarcodeOnError' => 0,
			'message'                      => '',
			'additionalContent1'           => '',
			'additionalContent2'           => '',
			'additionalContent3'           => '',
			'additionalContent4'           => '',
			'orderPieceList'               => self::piece_list( $shipment ),
		);
	}

	/**
	 * UpdateOrderRequest. Only the fields the vendor lists as updatable.
	 *
	 * @param array<string, mixed> $shipment Shipment request.
	 * @return array<string, mixed>
	 */
	public static function update_order_payload( array $shipment ): array {
		return array(
			'referenceId'     => strtoupper( (string) $shipment['reference'] ),
			'billOfLandingId' => (string) ( $shipment['bill_of_landing_id'] ?? '' ),
			'isCOD'           => 0,
			'codAmount'       => 0,
			'orderPieceList'  => self::piece_list( $shipment ),
		);
	}

	/**
	 * UpdateShipmentRequest.
	 *
	 * @param array<string, mixed> $shipment Shipment request; shipment_id required.
	 * @return array<string, mixed>
	 */
	public static function update_shipment_payload( array $shipment ): array {
		return array(
			'referenceId'     => strtoupper( (string) $shipment['reference'] ),
			'shipmentId'      => (string) ( $shipment['shipment_id'] ?? '' ),
			'billOfLandingId' => (string) ( $shipment['bill_of_landing_id'] ?? '' ),
			'isCOD'           => 0,
			'codAmount'       => 0,
			'orderPieceList'  => self::piece_list( $shipment ),
		);
	}

	/**
	 * OrderPieceList, with desi and kg as the integers the vendor declares.
	 *
	 * Both are format int32 in the document, and both are floored to at least 1:
	 * a zero-weight parcel is not a thing a courier can price, and rounding a
	 * 0.4 kg parcel down to 0 would produce one.
	 *
	 * @param array<string, mixed> $shipment Shipment request.
	 * @return array<int, array<string, mixed>>
	 */
	private static function piece_list( array $shipment ): array {
		$pieces = array();

		foreach ( (array) ( $shipment['pieces'] ?? array() ) as $piece ) {
			if ( ! is_array( $piece ) ) {
				continue;
			}

			$pieces[] = array(
				'barcode' => strtoupper( (string) ( $piece['barcode'] ?? '' ) ),
				'desi'    => max( 1, (int) ( $piece['desi'] ?? 1 ) ),
				'kg'      => max( 1, (int) ( $piece['kg'] ?? 1 ) ),
				'content' => self::text( (string) ( $piece['content'] ?? '' ), 200 ),
			);
		}

		return $pieces;
	}

	/**
	 * A 0/1 flag from anything.
	 *
	 * @param mixed $value Raw preference.
	 */
	private static function flag( $value ): int {
		return $value ? 1 : 0;
	}

	/**
	 * Collapse whitespace and bound the length.
	 *
	 * The vendor documents no maximum lengths, so these bounds are this
	 * project's own and are generous: they exist to stop an unbounded product
	 * title or address from being sent, not to enforce a carrier rule.
	 */
	private static function text( string $value, int $limit ): string {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit, 'UTF-8' );
		}

		return substr( $value, 0, $limit );
	}

	/**
	 * Keep only the digits and a leading plus of a phone number.
	 *
	 * No format is documented, so nothing is reformatted into one: the digits
	 * the customer gave are sent, minus the spaces, brackets and dashes a form
	 * field collects.
	 */
	private static function phone( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$plus   = str_starts_with( $value, '+' ) ? '+' : '';
		$digits = preg_replace( '/\D+/', '', $value );

		return $plus . ( is_string( $digits ) ? $digits : '' );
	}
}
