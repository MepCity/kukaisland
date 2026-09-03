<?php
/**
 * The carrier reference identifier: minted once, uppercase, and kept.
 *
 * The vendor states the rule twice in its own specification, in both languages:
 *
 *   Standard_Command_API-1.0.json, definitions.Order.properties.referenceId
 *     "ReferenceId must be unique foreach order. ReferenceId must be uppercase."
 *   ...properties.barcode
 *     "Barcode must be same with ReferenceId. Barcode must be uppercase."
 *
 * So the barcode is not a second identifier to invent: it IS the reference, and
 * this class is the only place either value is produced.
 *
 * Persistence is the point. Every recovery path in this integration -- the
 * reconciliation after an uncertain create, the status poller, cancel, update --
 * addresses the carrier by reference. A reference regenerated on the second
 * attempt would make the first attempt unreachable, and an unreachable first
 * attempt is exactly how a shop ends up with a parcel it cannot find and books
 * a second one.
 *
 * A NEW reference is minted only by mint_replacement(), which the manager calls
 * only after a cancellation has been confirmed by a read. Every reference an
 * order has ever carried stays in the history; nothing is overwritten in place.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Reference {

	/**
	 * Prefix identifying this shop in the carrier's reference space.
	 *
	 * Fixed rather than configurable: it takes part in an identifier that must
	 * stay stable for the lifetime of an order, and a setting somebody can edit
	 * is a setting that will differ between the day the shipment was booked and
	 * the day it is queried.
	 */
	public const PREFIX = 'KI';

	/**
	 * The only shape this integration ever sends or accepts.
	 *
	 * Uppercase letters, digits and the hyphen. Deliberately narrower than the
	 * vendor's "must be uppercase": an identifier that travels in a URL path
	 * (cancelorder/{refrenceId}, getorder/{referenceId}) must not be able to
	 * carry a slash, a space or a percent sign.
	 */
	public const PATTERN = '/^[A-Z0-9][A-Z0-9-]{4,39}$/';

	/**
	 * Is this a reference this integration is willing to send?
	 */
	public static function is_valid( string $reference ): bool {
		return 1 === preg_match( self::PATTERN, $reference );
	}

	/**
	 * Build a fresh reference for an order.
	 *
	 * The order id makes it readable and ties it to the shop; the random suffix
	 * is what lets a replacement be minted after a confirmed cancellation
	 * without colliding with the reference the carrier already knows.
	 *
	 * Four random bytes, not three. Three was enough for the space that matters
	 * -- references for different orders can never collide, because the order id
	 * is part of the string -- but a suffix drawn from sixteen million values
	 * repeats often enough to be measurable, and a replacement that repeats an
	 * earlier reference addresses the shipment that reference already belongs
	 * to. mint_replacement() rejects a repeat outright; the wider suffix is what
	 * makes that rejection almost never necessary.
	 *
	 * @param int $order_id WooCommerce order id.
	 */
	public static function build( int $order_id ): string {
		$reference = sprintf(
			'%s%d-%s',
			self::PREFIX,
			max( 1, $order_id ),
			strtoupper( bin2hex( random_bytes( 4 ) ) )
		);

		// Belt and braces: the format above cannot fail the pattern, and if a
		// future edit makes it possible the failure surfaces here rather than at
		// the carrier.
		return self::is_valid( $reference ) ? $reference : self::PREFIX . '0-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
	}

	/**
	 * Build a reference that is not already in the given list.
	 *
	 * The list is an order's own reference history. A repeat there is not a
	 * cosmetic problem: the carrier knows that identifier, and re-using it would
	 * point every later query, update and cancellation at the wrong shipment.
	 *
	 * Bounded rather than looping for ever: after the attempts are spent the
	 * caller receives the last candidate and the collision check in the store
	 * decides what to do, rather than this method hanging.
	 *
	 * @param int              $order_id WooCommerce order id.
	 * @param array<int,string> $used    References this order has already used.
	 */
	public static function build_unused( int $order_id, array $used ): string {
		$candidate = self::build( $order_id );

		for ( $attempt = 0; $attempt < 8 && in_array( $candidate, $used, true ); $attempt++ ) {
			$candidate = self::build( $order_id );
		}

		return $candidate;
	}

	/**
	 * The piece barcode for one parcel of a shipment.
	 *
	 * OrderPieceList.barcode has no documented format beyond being required, so
	 * it is derived from the reference rather than invented: a piece barcode
	 * that cannot be traced back to its order is useless on a warehouse floor.
	 * Uppercase for the same reason the reference is.
	 *
	 * @param string $reference Order reference.
	 * @param int    $piece     1-based piece number.
	 */
	public static function piece_barcode( string $reference, int $piece ): string {
		return strtoupper( $reference ) . 'P' . max( 1, $piece );
	}
}
