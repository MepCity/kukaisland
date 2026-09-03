<?php
/**
 * The carrier's shipment status dictionary, transcribed from the official spec.
 *
 * Source, quoted from the vendor's own OpenAPI documents and from nowhere else:
 *
 *   Standard_Query_API-1.0.json
 *     definitions.ShipmentOUT.properties.shipmentStatusCode.description
 *     definitions.TrackShipmentResponse.properties.eventStatus.description
 *
 *   "1:Gönderi_Hazırlandı, 2:Transfer_Aşamasında, 3: Teslimat_Birimine_Ulaştı,
 *    4: Alıcı_Adresine_Yönlendirildi, 5: Teslim_Edildi, 6:Teslim_Edilemedi,
 *    7:Geri_Geliyor, 8: Destek_Gerekiyor"
 *
 * Two rules govern everything here.
 *
 * A value outside 1..8 is NOT guessed at, not rounded to the nearest known code
 * and not treated as "probably still in transit". It becomes
 * LIFECYCLE_MANUAL_REVIEW, polling stops, and a person is told. A shipment
 * whose state this code cannot name is a shipment this code must not act on.
 *
 * Only code 5 marks a WooCommerce fulfilment delivered. Codes 6, 7 and 8 are
 * real answers, but they are answers that need a human: an undeliverable
 * parcel, a parcel on its way back and an explicit "support required" are not
 * states an automation should resolve on its own.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Status {

	public const CODE_PREPARED       = 1;
	public const CODE_IN_TRANSFER    = 2;
	public const CODE_AT_BRANCH      = 3;
	public const CODE_OUT_FOR_DELIVERY = 4;
	public const CODE_DELIVERED      = 5;
	public const CODE_NOT_DELIVERED  = 6;
	public const CODE_RETURNING      = 7;
	public const CODE_SUPPORT_NEEDED = 8;

	/** Still moving; a further query is worth booking. */
	public const LIFECYCLE_IN_PROGRESS = 'in_progress';

	/** Delivered. Terminal, and the only code that closes a fulfilment. */
	public const LIFECYCLE_DELIVERED = 'delivered';

	/** A named state that a person has to decide about. Polling stops. */
	public const LIFECYCLE_MANUAL_REVIEW = 'manual_review';

	/** No status has been read yet. */
	public const LIFECYCLE_UNKNOWN = 'unknown';

	/**
	 * Codes this integration recognises, with their lifecycle verdict.
	 *
	 * @return array<int, string>
	 */
	public static function lifecycle_map(): array {
		return array(
			self::CODE_PREPARED         => self::LIFECYCLE_IN_PROGRESS,
			self::CODE_IN_TRANSFER      => self::LIFECYCLE_IN_PROGRESS,
			self::CODE_AT_BRANCH        => self::LIFECYCLE_IN_PROGRESS,
			self::CODE_OUT_FOR_DELIVERY => self::LIFECYCLE_IN_PROGRESS,
			self::CODE_DELIVERED        => self::LIFECYCLE_DELIVERED,
			self::CODE_NOT_DELIVERED    => self::LIFECYCLE_MANUAL_REVIEW,
			self::CODE_RETURNING        => self::LIFECYCLE_MANUAL_REVIEW,
			self::CODE_SUPPORT_NEEDED   => self::LIFECYCLE_MANUAL_REVIEW,
		);
	}

	/**
	 * The lifecycle verdict for a raw status value.
	 *
	 * Accepts the raw value from the carrier because the two documented sources
	 * disagree on type: shipmentStatusCode is an integer and eventStatus is a
	 * string carrying the same numbers. Anything that is not one of the eight
	 * documented codes -- a 9, a 0, an empty string, a word, a float, null --
	 * is LIFECYCLE_MANUAL_REVIEW.
	 *
	 * @param mixed $raw Value as it arrived.
	 */
	public static function lifecycle_for( $raw ): string {
		$code = self::normalize_code( $raw );

		if ( 0 === $code ) {
			return self::LIFECYCLE_MANUAL_REVIEW;
		}

		return self::lifecycle_map()[ $code ] ?? self::LIFECYCLE_MANUAL_REVIEW;
	}

	/**
	 * The documented code as an integer, or 0 when the value is not one.
	 *
	 * Strict: '5 ' and '05' are accepted after trimming and integer casting
	 * because both are the same number written differently, but 'delivered',
	 * '5a' and 5.5 are not, and neither is any value outside 1..8.
	 *
	 * @param mixed $raw Value as it arrived.
	 */
	public static function normalize_code( $raw ): int {
		if ( is_int( $raw ) ) {
			$code = $raw;
		} elseif ( is_string( $raw ) && preg_match( '/^\s*\d{1,3}\s*$/', $raw ) ) {
			$code = (int) trim( $raw );
		} else {
			return 0;
		}

		return array_key_exists( $code, self::lifecycle_map() ) ? $code : 0;
	}

	/**
	 * Is a further status query worth booking for this lifecycle?
	 */
	public static function should_keep_polling( string $lifecycle ): bool {
		return self::LIFECYCLE_IN_PROGRESS === $lifecycle;
	}

	/**
	 * Is this lifecycle final, in the sense that nothing further will change it
	 * without a person?
	 */
	public static function is_terminal( string $lifecycle ): bool {
		return in_array( $lifecycle, array( self::LIFECYCLE_DELIVERED, self::LIFECYCLE_MANUAL_REVIEW ), true );
	}

	/**
	 * Operator-facing label for a recognised code.
	 *
	 * The wording is this project's, taken from the vendor's own Turkish
	 * dictionary above. An unrecognised code deliberately does not get a
	 * label -- it gets the "unknown" sentence, so nobody reads a made-up state
	 * off the order screen.
	 *
	 * @param mixed $raw Value as it arrived.
	 */
	public static function label_for( $raw ): string {
		$labels = array(
			self::CODE_PREPARED         => __( 'Gönderi hazırlandı', 'kuka-island-shipping-automation' ),
			self::CODE_IN_TRANSFER      => __( 'Transfer aşamasında', 'kuka-island-shipping-automation' ),
			self::CODE_AT_BRANCH        => __( 'Teslimat birimine ulaştı', 'kuka-island-shipping-automation' ),
			self::CODE_OUT_FOR_DELIVERY => __( 'Alıcı adresine yönlendirildi', 'kuka-island-shipping-automation' ),
			self::CODE_DELIVERED        => __( 'Teslim edildi', 'kuka-island-shipping-automation' ),
			self::CODE_NOT_DELIVERED    => __( 'Teslim edilemedi', 'kuka-island-shipping-automation' ),
			self::CODE_RETURNING        => __( 'Geri geliyor', 'kuka-island-shipping-automation' ),
			self::CODE_SUPPORT_NEEDED   => __( 'Destek gerekiyor', 'kuka-island-shipping-automation' ),
		);

		$code = self::normalize_code( $raw );

		return $labels[ $code ] ?? __( 'Bilinmeyen durum kodu — manuel inceleme gerekiyor', 'kuka-island-shipping-automation' );
	}
}
