<?php
/**
 * Write what the carrier confirmed into WooCommerce's own Fulfillments entity.
 *
 * The record this class creates is an ORDINARY WooCommerce fulfilment. Same
 * table, same entity, same provider key -- whatever the carrier adapter's own
 * get_key() returns, which is the key a person would pick from the drawer's own
 * list -- and the same tracking fields. That is what keeps the manual route
 * alive: nothing here creates a parallel notion of "shipped" that the standard
 * screens cannot see or edit, and an operator can still open the drawer and
 * type a tracking number by hand at any point.
 *
 * Three rules keep this class from stepping on a human.
 *
 * IT ONLY EVER TOUCHES ITS OWN RECORD. Ours carries the carrier reference in
 * fulfilment meta. A fulfilment somebody created by hand does not, and is never
 * read, edited, fulfilled or deleted here.
 *
 * IT NEVER CLAIMS DISPATCH THE CARRIER HAS NOT CONFIRMED. Booking a shipment
 * produces a label, not a handover, so the record starts 'unfulfilled'. It
 * becomes 'fulfilled' only once the carrier's own status says the parcel is in
 * transfer or beyond (codes 2, 3, 4, 5). Code 1 -- "Gönderi hazırlandı" -- is
 * the carrier saying it has prepared a shipment, which is not the same as
 * having it.
 *
 * IT NEVER DOWNGRADES. A fulfilment that is fulfilled stays fulfilled, whatever
 * a later status reading says. Undoing a dispatch is a decision with customer
 * consequences and it belongs to a person.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Fulfillment_Writer {

	/** Fulfilment meta identifying a record this plugin owns. */
	public const META_REFERENCE = '_kuka_shipping_reference';

	/** Fulfilment meta recording the carrier's delivery confirmation. */
	public const META_DELIVERED_AT = '_kuka_shipping_delivered_at';

	private const FULFILLMENT_CLASS = '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment';
	private const DATA_STORE_CLASS  = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';
	private const UTILS_CLASS       = '\Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils';

	/**
	 * Is WooCommerce's Fulfillments API actually present in this runtime?
	 *
	 * Checked as loaded classes rather than as a feature flag: the option can
	 * say the feature is on while the classes failed to load, and a writer that
	 * trusted the option would fatal instead of reporting.
	 */
	public static function api_available(): bool {
		return class_exists( self::FULFILLMENT_CLASS )
			&& class_exists( self::DATA_STORE_CLASS )
			&& class_exists( self::UTILS_CLASS )
			&& function_exists( 'wc_get_container' );
	}

	/**
	 * The fulfilment this plugin owns for an order, or null.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $reference Carrier reference.
	 * @return object|null
	 */
	public static function find_own( WC_Order $order, string $reference ) {
		if ( ! self::api_available() || '' === $reference ) {
			return null;
		}

		try {
			$store        = wc_get_container()->get( self::DATA_STORE_CLASS );
			$fulfillments = (array) $store->read_fulfillments( WC_Order::class, (string) $order->get_id() );
		} catch ( Throwable $e ) {
			return null;
		}

		foreach ( $fulfillments as $fulfillment ) {
			if ( ! is_object( $fulfillment ) || ! method_exists( $fulfillment, 'get_meta' ) ) {
				continue;
			}

			if ( (string) $fulfillment->get_meta( self::META_REFERENCE, true ) === $reference ) {
				return $fulfillment;
			}
		}

		return null;
	}

	/**
	 * Create or update the fulfilment record for a confirmed shipment.
	 *
	 * @param WC_Order          $order        Order.
	 * @param string            $provider_key WooCommerce shipment provider key.
	 * @param string            $reference    Carrier reference.
	 * @param string            $shipment_id  Carrier shipment id.
	 * @param array<int,string> $barcodes     Piece barcodes.
	 * @param string            $tracking_url Carrier tracking URL, '' when unknown.
	 * @param string            $tracking_number_source One of the carrier contract's TRACKING_SOURCE_* values.
	 * @return array{ok: bool, action: string, fulfillment_id: int, reason: string, tracking_number_set: bool}
	 */
	public static function record_shipment(
		WC_Order $order,
		string $provider_key,
		string $reference,
		string $shipment_id,
		array $barcodes,
		string $tracking_url,
		string $tracking_number_source
	): array {
		if ( ! self::api_available() ) {
			return self::outcome( false, 'none', 0, 'fulfillments_api_unavailable', false );
		}

		$existing = self::find_own( $order, $reference );
		$creating = null === $existing;

		try {
			if ( $creating ) {
				$items = self::pending_items( $order );

				if ( array() === $items ) {
					/*
					 * Every item is already inside somebody else's fulfilment --
					 * almost always a manual one an operator created. Nothing is
					 * taken from it and nothing is created alongside it: the
					 * carrier data stays in order meta and the operator is told.
					 * Splitting or editing a human's fulfilment record without
					 * being asked is exactly the behaviour that would make this
					 * automation untrustworthy.
					 */
					return self::outcome( false, 'none', 0, 'no_unfulfilled_items', false );
				}

				$fulfillment_class = self::FULFILLMENT_CLASS;
				$fulfillment       = new $fulfillment_class();
				$fulfillment->set_entity_type( WC_Order::class );
				$fulfillment->set_entity_id( (string) $order->get_id() );
				$fulfillment->set_status( 'unfulfilled' );
				$fulfillment->set_items( $items );
				$fulfillment->update_meta_data( self::META_REFERENCE, $reference );
			} else {
				$fulfillment = $existing;
			}

			$fulfillment->set_shipment_provider( $provider_key );

			if ( '' !== $tracking_url ) {
				$fulfillment->set_tracking_url( $tracking_url );
			}

			/*
			 * WHICH carrier value is the WooCommerce tracking number has not
			 * been measured against the sandbox, so by default none is written.
			 * A tracking number that does not track is worse than an absent one:
			 * it goes into the customer's e-mail and into support conversations.
			 * The carrier adapter answers get_tracking_number_source() once its
			 * own measurement is in, and only then does a number appear. This
			 * class never learns which courier that was.
			 */
			$tracking_number = self::tracking_number( $tracking_number_source, $shipment_id, $barcodes );

			if ( '' !== $tracking_number ) {
				$fulfillment->set_tracking_number( $tracking_number );
			}

			$fulfillment->save();

			return self::outcome(
				true,
				$creating ? 'created' : 'updated',
				(int) $fulfillment->get_id(),
				'',
				'' !== $tracking_number
			);
		} catch ( Throwable $e ) {
			// The exception message can quote order data. It is not returned,
			// not logged and not written to a note; only the fact of failure is.
			return self::outcome( false, $creating ? 'create_failed' : 'update_failed', 0, 'fulfillment_write_failed', false );
		}
	}

	/**
	 * Reflect a carrier status reading into the fulfilment's own status.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $reference Carrier reference.
	 * @param mixed    $raw_code  Status value exactly as it arrived.
	 * @return array{ok: bool, action: string, reason: string}
	 */
	public static function sync_status( WC_Order $order, string $reference, $raw_code ): array {
		if ( ! self::api_available() ) {
			return array(
				'ok'     => false,
				'action' => 'none',
				'reason' => 'fulfillments_api_unavailable',
			);
		}

		$fulfillment = self::find_own( $order, $reference );

		if ( null === $fulfillment ) {
			return array(
				'ok'     => false,
				'action' => 'none',
				'reason' => 'own_fulfillment_absent',
			);
		}

		$code      = Kuka_Island_Shipping_Status::normalize_code( $raw_code );
		$lifecycle = Kuka_Island_Shipping_Status::lifecycle_for( $raw_code );

		// Codes 6, 7, 8 and anything unrecognised: a person decides. The record
		// is left exactly as it is.
		if ( Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW === $lifecycle ) {
			return array(
				'ok'     => true,
				'action' => 'left_for_manual_review',
				'reason' => '',
			);
		}

		$should_be_fulfilled = $code >= Kuka_Island_Shipping_Status::CODE_IN_TRANSFER;

		if ( ! $should_be_fulfilled ) {
			return array(
				'ok'     => true,
				'action' => 'no_change',
				'reason' => '',
			);
		}

		try {
			$changed = false;

			if ( ! $fulfillment->get_is_fulfilled() ) {
				$fulfillment->set_status( 'fulfilled' );
				$changed = true;
			}

			if ( Kuka_Island_Shipping_Status::CODE_DELIVERED === $code
				&& '' === (string) $fulfillment->get_meta( self::META_DELIVERED_AT, true ) ) {
				$fulfillment->update_meta_data( self::META_DELIVERED_AT, gmdate( 'Y-m-d H:i:s' ) );
				$changed = true;
			}

			if ( ! $changed ) {
				return array(
					'ok'     => true,
					'action' => 'no_change',
					'reason' => '',
				);
			}

			$fulfillment->save();

			return array(
				'ok'     => true,
				'action' => Kuka_Island_Shipping_Status::CODE_DELIVERED === $code ? 'delivered' : 'fulfilled',
				'reason' => '',
			);
		} catch ( Throwable $e ) {
			return array(
				'ok'     => false,
				'action' => 'update_failed',
				'reason' => 'fulfillment_write_failed',
			);
		}
	}

	/**
	 * Items not already inside some fulfilment, in the shape the data store
	 * validates: integer item_id, positive qty.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{item_id: int, qty: int}>
	 */
	private static function pending_items( WC_Order $order ): array {
		try {
			$store        = wc_get_container()->get( self::DATA_STORE_CLASS );
			$fulfillments = (array) $store->read_fulfillments( WC_Order::class, (string) $order->get_id() );
			$pending      = (array) call_user_func( array( self::UTILS_CLASS, 'get_pending_items' ), $order, $fulfillments );
		} catch ( Throwable $e ) {
			return array();
		}

		$items = array();

		foreach ( $pending as $entry ) {
			$item_id = (int) ( $entry['item_id'] ?? 0 );
			$qty     = (int) ( $entry['qty'] ?? 0 );

			if ( $item_id > 0 && $qty > 0 ) {
				$items[] = array(
					'item_id' => $item_id,
					'qty'     => $qty,
				);
			}
		}

		return $items;
	}

	/**
	 * The tracking number to write, or '' when the mapping is unmeasured.
	 *
	 * @param string            $source      One of Kuka_Island_Shipping_Carrier_Interface::TRACKING_SOURCE_*.
	 * @param string            $shipment_id Carrier shipment id.
	 * @param array<int,string> $barcodes    Piece barcodes.
	 */
	public static function tracking_number( string $source, string $shipment_id, array $barcodes ): string {
		if ( Kuka_Island_Shipping_Carrier_Interface::TRACKING_SOURCE_SHIPMENT_ID === $source ) {
			return trim( $shipment_id );
		}

		if ( Kuka_Island_Shipping_Carrier_Interface::TRACKING_SOURCE_BARCODE === $source ) {
			foreach ( $barcodes as $barcode ) {
				$barcode = trim( (string) $barcode );
				if ( '' !== $barcode ) {
					return $barcode;
				}
			}
		}

		return '';
	}

	/**
	 * @return array{ok: bool, action: string, fulfillment_id: int, reason: string, tracking_number_set: bool}
	 */
	private static function outcome( bool $ok, string $action, int $id, string $reason, bool $tracking_number_set ): array {
		return array(
			'ok'                  => $ok,
			'action'              => $action,
			'fulfillment_id'      => $id,
			'reason'              => $reason,
			'tracking_number_set' => $tracking_number_set,
		);
	}
}
