<?php
/**
 * The carrier contract.
 *
 * This interface is the reason a second courier can be added without touching
 * Core, the manager, the order store, the poller, the admin panel or the
 * WooCommerce fulfilment writer. Everything above this line speaks in the
 * vocabulary declared here; everything below it -- endpoints, field names,
 * numeric enumerations, authentication, JSON shapes -- belongs to one adapter
 * and is invisible to the rest of the plugin.
 *
 * Two conventions make that hold in practice.
 *
 * The SHIPMENT REQUEST passed to the write methods is a plain array using
 * semantic tokens, never a carrier's numeric codes. 'package', 'sender',
 * 'to_address' are the words; mapping them to 3, 1 and 1 is the adapter's job.
 * A manager that wrote 3 would have learned one carrier's enumeration, and the
 * next carrier would need the manager changed.
 *
 * Every method returns Kuka_Island_Shipping_Result and NONE of them throws for
 * a carrier-side condition. A timeout is not an exception here; it is an
 * uncertain outcome that the caller must handle deliberately, and an exception
 * would let a caller ignore it with a catch-all.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

interface Kuka_Island_Shipping_Carrier_Interface {

	/**
	 * WHICH of the values a carrier returns is the WooCommerce tracking number
	 * is a property of that carrier, not of this plugin, so the vocabulary for
	 * saying it belongs here rather than inside one adapter's configuration
	 * class. The fulfilment writer speaks only these three words; it never
	 * learns which courier produced them.
	 *
	 * UNSET is the default and it means "not measured yet". A tracking number
	 * that does not track is worse than an absent one: it reaches the customer's
	 * e-mail and the support conversation.
	 */
	public const TRACKING_SOURCE_UNSET = '';

	/** The carrier's own shipment id is the number that tracks. */
	public const TRACKING_SOURCE_SHIPMENT_ID = 'shipment_id';

	/** A piece barcode is the number that tracks. */
	public const TRACKING_SOURCE_BARCODE = 'barcode';

	/**
	 * Stable machine key.
	 *
	 * MUST equal the WooCommerce Fulfillments shipment provider key this
	 * carrier writes back to, so 'dhl' here and 'dhl' in the fulfilment record
	 * are the same string by construction rather than by coincidence.
	 */
	public function get_key(): string;

	/** Operator-facing name, for the admin panel. */
	public function get_label(): string;

	/**
	 * Can this carrier be contacted at all, and if not, exactly what is missing?
	 *
	 * 'gaps' contains CONFIGURATION FIELD NAMES, never values. 'live_blocked' is
	 * true when the operator asked for a live environment whose real endpoints
	 * have not been verified against the vendor's own documents -- in which case
	 * no call of any kind is made.
	 *
	 * @return array{ready: bool, gaps: array<int, string>, environment: string, live_blocked: bool}
	 */
	public function get_readiness(): array;

	/**
	 * Which value of this carrier's answer WooCommerce should track.
	 *
	 * One of the TRACKING_SOURCE_* constants above. An adapter that has not had
	 * the question answered against a real shipment returns
	 * TRACKING_SOURCE_UNSET, and the fulfilment writer then writes no tracking
	 * number at all.
	 */
	public function get_tracking_number_source(): string;

	/**
	 * Read-only connection test. Contacts authentication only.
	 *
	 * Exists so an operator can prove credentials work without creating
	 * anything. An implementation that booked, reserved or changed anything here
	 * would break the one guarantee this method makes.
	 */
	public function ping(): Kuka_Island_Shipping_Result;

	/**
	 * Resolve a free-text city and district into whatever codes the carrier
	 * needs, using the carrier's own reference data.
	 *
	 * Returns a result whose data carries at least 'city_code' and
	 * 'district_code' on success. A district that cannot be matched is a
	 * permanent failure, not a guess: a parcel addressed to the wrong district
	 * code is a parcel delivered to the wrong place.
	 *
	 * @param string $city     Free-text city from the order address.
	 * @param string $district Free-text district from the order address.
	 */
	public function resolve_location( string $city, string $district ): Kuka_Island_Shipping_Result;

	/**
	 * Register the order with the carrier.
	 *
	 * @param array<string, mixed> $shipment Shipment request; see class docblock.
	 */
	public function create_order( array $shipment ): Kuka_Island_Shipping_Result;

	/**
	 * Turn a registered order into a shipment with barcodes.
	 *
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function create_barcode( array $shipment ): Kuka_Island_Shipping_Result;

	/**
	 * Amend a registered order that has not become a shipment yet.
	 *
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function update_order( array $shipment ): Kuka_Island_Shipping_Result;

	/**
	 * Cancel a registered order.
	 *
	 * @param string $reference Carrier reference.
	 */
	public function cancel_order( string $reference ): Kuka_Island_Shipping_Result;

	/**
	 * Amend an existing shipment.
	 *
	 * @param array<string, mixed> $shipment Shipment request, including shipment_id.
	 */
	public function update_shipment( array $shipment ): Kuka_Island_Shipping_Result;

	/**
	 * Cancel an existing shipment.
	 *
	 * @param string $reference   Carrier reference.
	 * @param string $shipment_id Carrier shipment id.
	 */
	public function cancel_shipment( string $reference, string $shipment_id ): Kuka_Island_Shipping_Result;

	/**
	 * Read back the registered order. The reconciliation call for createOrder.
	 *
	 * @param string $reference Carrier reference.
	 */
	public function read_order( string $reference ): Kuka_Island_Shipping_Result;

	/**
	 * Read back the shipment. The reconciliation call for createbarcode.
	 *
	 * @param string $reference Carrier reference.
	 */
	public function read_shipment( string $reference ): Kuka_Island_Shipping_Result;

	/**
	 * Read the shipment's current status.
	 *
	 * On success the data MUST carry 'status_code' exactly as the carrier sent
	 * it -- unnormalised -- so the dictionary, not the adapter, decides what an
	 * unrecognised value means.
	 *
	 * @param string $reference Carrier reference.
	 */
	public function read_shipment_status( string $reference ): Kuka_Island_Shipping_Result;

	/**
	 * Read the shipment's movement history.
	 *
	 * @param string $reference Carrier reference.
	 */
	public function track_shipment( string $reference ): Kuka_Island_Shipping_Result;
}
