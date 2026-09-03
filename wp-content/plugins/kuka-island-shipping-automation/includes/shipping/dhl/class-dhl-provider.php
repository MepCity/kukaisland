<?php
/**
 * The DHL eCommerce Türkiye adapter.
 *
 * Implements the carrier contract on top of the client, the mapper and the
 * address resolver. It is the last place in the plugin where the vendor's
 * vocabulary appears; everything it returns is carrier-agnostic.
 *
 * get_key() returns 'dhl', which is the same string WooCommerce Fulfillments
 * uses for its own DHL shipping provider
 * (Automattic\WooCommerce\Admin\Features\Fulfillments\Providers\
 * DHLShippingProvider::get_key()). That is deliberate and it is what makes the
 * fulfilment record this integration writes indistinguishable from one a person
 * created by hand -- which is the property that keeps the manual route working.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_DHL_Provider implements Kuka_Island_Shipping_Carrier_Interface {

	public const KEY = 'dhl';

	private Kuka_Island_Shipping_DHL_Config $config;
	private Kuka_Island_Shipping_DHL_Client $client;
	private Kuka_Island_Shipping_DHL_Address_Resolver $resolver;

	public function __construct(
		?Kuka_Island_Shipping_DHL_Config $config = null,
		?Kuka_Island_Shipping_DHL_Client $client = null,
		?Kuka_Island_Shipping_DHL_Address_Resolver $resolver = null
	) {
		$this->config   = $config ?? new Kuka_Island_Shipping_DHL_Config();
		$this->client   = $client ?? new Kuka_Island_Shipping_DHL_Client( $this->config );
		$this->resolver = $resolver ?? new Kuka_Island_Shipping_DHL_Address_Resolver( $this->client );
	}

	public function get_key(): string {
		return self::KEY;
	}

	public function get_label(): string {
		return __( 'DHL eCommerce Türkiye', 'kuka-island-shipping-automation' );
	}

	public function get_config(): Kuka_Island_Shipping_DHL_Config {
		return $this->config;
	}

	public function get_client(): Kuka_Island_Shipping_DHL_Client {
		return $this->client;
	}

	public function get_resolver(): Kuka_Island_Shipping_DHL_Address_Resolver {
		return $this->resolver;
	}

	/**
	 * @return array{ready: bool, gaps: array<int, string>, environment: string, live_blocked: bool}
	 */
	public function get_readiness(): array {
		return array(
			'ready'        => $this->config->is_ready(),
			'gaps'         => $this->config->get_readiness_gaps(),
			'environment'  => $this->config->get_environment(),
			'live_blocked' => $this->config->is_live_blocked(),
		);
	}

	/**
	 * Which DHL value WooCommerce should track.
	 *
	 * Read from this adapter's own configuration, which is where the answer
	 * belongs: it is a fact about DHL's response shape and it has not been
	 * measured against a real shipment yet, so the shipped default is UNSET.
	 * See the open measurement O-03 in docs/DHL_BAKIM_HAFIZASI.md.
	 */
	public function get_tracking_number_source(): string {
		return $this->config->get_tracking_number_source();
	}

	public function ping(): Kuka_Island_Shipping_Result {
		return $this->client->authenticate();
	}

	public function resolve_location( string $city, string $district ): Kuka_Island_Shipping_Result {
		return $this->resolver->resolve( $city, $district );
	}

	/**
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function create_order( array $shipment ): Kuka_Island_Shipping_Result {
		$gaps = Kuka_Island_Shipping_DHL_Order_Mapper::validate( $shipment );

		if ( array() !== $gaps ) {
			return Kuka_Island_Shipping_Result::local_refusal( 'create_order', 'payload_incomplete' );
		}

		if ( ! empty( $shipment['cod']['enabled'] ) ) {
			// Refused here as well as upstream. Two independent refusals, because
			// this one survives a future caller that builds a shipment request
			// without going through the manager.
			return Kuka_Island_Shipping_Result::local_refusal( 'create_order', 'cod_not_supported' );
		}

		return $this->client->create_order( Kuka_Island_Shipping_DHL_Order_Mapper::create_order_payload( $shipment ) );
	}

	/**
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function create_barcode( array $shipment ): Kuka_Island_Shipping_Result {
		$gaps = Kuka_Island_Shipping_DHL_Order_Mapper::validate( $shipment );

		if ( array() !== $gaps ) {
			return Kuka_Island_Shipping_Result::local_refusal( 'create_barcode', 'payload_incomplete' );
		}

		if ( ! empty( $shipment['cod']['enabled'] ) ) {
			return Kuka_Island_Shipping_Result::local_refusal( 'create_barcode', 'cod_not_supported' );
		}

		return $this->client->create_barcode( Kuka_Island_Shipping_DHL_Order_Mapper::create_barcode_payload( $shipment ) );
	}

	/**
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function update_order( array $shipment ): Kuka_Island_Shipping_Result {
		if ( ! Kuka_Island_Shipping_Reference::is_valid( (string) ( $shipment['reference'] ?? '' ) ) ) {
			return Kuka_Island_Shipping_Result::local_refusal( 'update_order', 'payload_incomplete' );
		}

		return $this->client->update_order( Kuka_Island_Shipping_DHL_Order_Mapper::update_order_payload( $shipment ) );
	}

	public function cancel_order( string $reference ): Kuka_Island_Shipping_Result {
		return $this->client->cancel_order( $reference );
	}

	/**
	 * @param array<string, mixed> $shipment Shipment request.
	 */
	public function update_shipment( array $shipment ): Kuka_Island_Shipping_Result {
		if ( ! Kuka_Island_Shipping_Reference::is_valid( (string) ( $shipment['reference'] ?? '' ) )
			|| '' === trim( (string) ( $shipment['shipment_id'] ?? '' ) ) ) {
			return Kuka_Island_Shipping_Result::local_refusal( 'update_shipment', 'payload_incomplete' );
		}

		return $this->client->update_shipment( Kuka_Island_Shipping_DHL_Order_Mapper::update_shipment_payload( $shipment ) );
	}

	public function cancel_shipment( string $reference, string $shipment_id ): Kuka_Island_Shipping_Result {
		return $this->client->cancel_shipment( $reference, $shipment_id );
	}

	public function read_order( string $reference ): Kuka_Island_Shipping_Result {
		return $this->client->get_order( $reference );
	}

	public function read_shipment( string $reference ): Kuka_Island_Shipping_Result {
		return $this->client->get_shipment( $reference );
	}

	/**
	 * DHL cannot prove an amendment took effect, so it says so.
	 *
	 * The vendor's Standard Query documents describe getorder and getshipment as
	 * returning identifiers, a transformation flag, a status code, a delivery
	 * flag and a piece count. NONE of the amendable fields -- recipient name,
	 * address, city and district codes, phone, content, description, desi, kg --
	 * appear in either response. There is therefore nothing to compare an
	 * amendment against, and inventing a comparison would turn "the shipment
	 * still exists" into "the amendment was applied".
	 *
	 * This is a REFUSAL, not a failure: an uncertain amendment stays in
	 * update_reconciliation_required and waits for a person. It becomes a real
	 * read-back only if a documented, verified field-returning query endpoint is
	 * added to DHL_Client and measured against the sandbox -- the same bar every
	 * other open measurement in docs/DHL_BAKIM_HAFIZASI.md has to clear.
	 */
	public function read_amendable_fields( string $reference ): Kuka_Island_Shipping_Result {
		return Kuka_Island_Shipping_Result::local_refusal( 'read_amendable_fields', 'readback_unsupported' );
	}

	public function read_shipment_status( string $reference ): Kuka_Island_Shipping_Result {
		return $this->client->get_shipment_status( $reference );
	}

	public function track_shipment( string $reference ): Kuka_Island_Shipping_Result {
		return $this->client->track_shipment( $reference );
	}
}
