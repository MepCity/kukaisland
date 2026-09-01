<?php
/**
 * INTERNETSALESDETAILS data contract.
 *
 * Turkish distance-selling invoices carry an internet-sales block describing
 * where the sale happened, how it was paid, when it was paid, and who carried
 * the goods. Every one of those is a fiscal statement, so this builder produces
 * the block only from facts that can be read, and refuses -- by name -- when
 * one is missing.
 *
 * Deliberately NOT wired to transmission in this round. It is a pure producer
 * plus a fail-closed validator, so the shape can be reviewed and proved before
 * a single document depends on it. The order status hooks are untouched.
 *
 * Three refusals in particular:
 *
 * - The payment date comes from WC_Order::get_date_paid(). Deriving it from the
 *   order creation date would put a plausible but wrong date on a fiscal
 *   document; an unpaid order simply has no payment date.
 * - The carrier's VKN and title are never inferred. A shipping provider slug
 *   such as "dhl" is a label chosen in the admin UI, not a taxpayer identity,
 *   and there is no VKN anywhere in WooCommerce's Fulfillments data.
 * - A partially fulfilled order is not a whole-order invoice. Its shipment is
 *   incomplete, so the shipment block would describe something that has not
 *   happened yet.
 *
 * Fulfillment facts come from WooCommerce's real API, verified against the
 * plugin source:
 *   Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils
 *     ::get_order_fulfillment_status() -> fulfilled|partially_fulfilled|unfulfilled
 *   ...\Fulfillments\DataStore\FulfillmentsDataStore::read_fulfillments()
 *   ...\Fulfillments\Fulfillment::get_is_fulfilled() / get_date_fulfilled()
 *   ...\Fulfillments\FulfillmentUtils::resolve_provider_name()
 * Nothing is read from rendered markup or CSS classes.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Internet_Sales_Details {

	/** The order has no shipment at all: digital or service-only. */
	public const SHIPMENT_NONE = 'no_shipment';
	/** Every item has shipped. */
	public const SHIPMENT_COMPLETE = 'fulfilled';
	/** Some items have shipped. Not invoiceable as a whole order. */
	public const SHIPMENT_PARTIAL = 'partially_fulfilled';
	/** Shippable items exist and none has shipped. */
	public const SHIPMENT_PENDING = 'unfulfilled';

	// Safe, distinct refusal codes. Each names exactly one missing fact.
	public const ERROR_PAYMENT_DATE_MISSING = 'internet_sales_payment_date_missing';
	public const ERROR_PAYMENT_METHOD_MISSING = 'internet_sales_payment_method_missing';
	public const ERROR_WEB_ADDRESS_MISSING = 'internet_sales_web_address_missing';
	public const ERROR_SHIPMENT_PARTIAL = 'internet_sales_shipment_partial';
	public const ERROR_SHIPMENT_PENDING = 'internet_sales_shipment_pending';
	public const ERROR_SHIPMENT_DATE_MISSING = 'internet_sales_shipment_date_missing';
	public const ERROR_CARRIER_VKN_MISSING = 'internet_sales_carrier_vkn_missing';
	public const ERROR_CARRIER_TITLE_MISSING = 'internet_sales_carrier_title_missing';

	/**
	 * Build the block, or report exactly what is missing.
	 *
	 * Pure: every fact arrives as an argument, so the whole matrix is provable
	 * from fixtures without an order, a database or a network.
	 *
	 * @param array<string, mixed> $facts Observed facts:
	 *     web_address     string  Shop URL.
	 *     payment_method  string  Payment method title.
	 *     payment_agent   string  Payment intermediary name, when one applies.
	 *     payment_date    string  From WC_Order::get_date_paid(). Never derived.
	 *     shipment_state  string  One of the SHIPMENT_* constants.
	 *     shipment_date   string  Date the goods were handed over.
	 *     carrier_vkn     string  Carrier tax number. Never guessed.
	 *     carrier_title   string  Carrier legal title. Never guessed.
	 * @return array{ok: bool, errors: array<int, string>, details: array<string, mixed>}
	 */
	public static function build( array $facts ): array {
		$web_address    = trim( (string) ( $facts['web_address'] ?? '' ) );
		$payment_method = trim( (string) ( $facts['payment_method'] ?? '' ) );
		$payment_agent  = trim( (string) ( $facts['payment_agent'] ?? '' ) );
		$payment_date   = trim( (string) ( $facts['payment_date'] ?? '' ) );
		$shipment_state = (string) ( $facts['shipment_state'] ?? self::SHIPMENT_PENDING );
		$shipment_date  = trim( (string) ( $facts['shipment_date'] ?? '' ) );
		$carrier_vkn    = trim( (string) ( $facts['carrier_vkn'] ?? '' ) );
		$carrier_title  = trim( (string) ( $facts['carrier_title'] ?? '' ) );

		$errors = array();

		if ( '' === $web_address ) {
			$errors[] = self::ERROR_WEB_ADDRESS_MISSING;
		}
		if ( '' === $payment_method ) {
			$errors[] = self::ERROR_PAYMENT_METHOD_MISSING;
		}
		if ( '' === $payment_date ) {
			// An unpaid order has no payment date. There is nothing to fall
			// back to that would not be a fabrication.
			$errors[] = self::ERROR_PAYMENT_DATE_MISSING;
		}

		$details = array(
			'webAdresi'    => $web_address,
			'odemeSekli'   => $payment_method,
			'odemeTarihi'  => $payment_date,
		);

		// Only present when an intermediary actually handled the payment.
		if ( '' !== $payment_agent ) {
			$details['odemeAracisiAdi'] = $payment_agent;
		}

		switch ( $shipment_state ) {
			case self::SHIPMENT_NONE:
				// Digital or service-only: there is no carrier and no handover,
				// and inventing one would misdescribe the sale.
				break;

			case self::SHIPMENT_COMPLETE:
				if ( '' === $shipment_date ) {
					$errors[] = self::ERROR_SHIPMENT_DATE_MISSING;
				}
				if ( '' === $carrier_vkn ) {
					$errors[] = self::ERROR_CARRIER_VKN_MISSING;
				}
				if ( '' === $carrier_title ) {
					$errors[] = self::ERROR_CARRIER_TITLE_MISSING;
				}
				if ( ! in_array( self::ERROR_SHIPMENT_DATE_MISSING, $errors, true )
					&& ! in_array( self::ERROR_CARRIER_VKN_MISSING, $errors, true )
					&& ! in_array( self::ERROR_CARRIER_TITLE_MISSING, $errors, true ) ) {
					$details['gonderiBilgileri'] = array(
						'gonderimTarihi'  => $shipment_date,
						'gonderiTasiyan'  => array(
							'tuzelKisi' => array(
								'vkn'   => $carrier_vkn,
								'unvan' => $carrier_title,
							),
						),
					);
				}
				break;

			case self::SHIPMENT_PARTIAL:
				$errors[] = self::ERROR_SHIPMENT_PARTIAL;
				break;

			default:
				$errors[] = self::ERROR_SHIPMENT_PENDING;
				break;
		}

		return array(
			'ok'      => array() === $errors,
			'errors'  => $errors,
			'details' => array() === $errors ? $details : array(),
		);
	}

	/**
	 * Read the shipment facts for an order from WooCommerce's real API.
	 *
	 * Returns labels and dates only. The carrier's VKN and title are NOT
	 * derived here, because WooCommerce's Fulfillments data has no such fields:
	 * the provider is a slug or a free-text name. They must be supplied from
	 * reviewed configuration or the block stays refused.
	 *
	 * @param WC_Order $order Order.
	 * @return array{shipment_state: string, shipment_date: string, provider_label: string, fulfillment_count: int, api_available: bool}
	 */
	public static function read_shipment_facts( WC_Order $order ): array {
		$utils      = '\Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils';
		$data_store = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';

		$has_shippable = false;
		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
			if ( $product instanceof WC_Product && $product->needs_shipping() ) {
				$has_shippable = true;
				break;
			}
		}

		if ( ! $has_shippable ) {
			return array(
				'shipment_state'    => self::SHIPMENT_NONE,
				'shipment_date'     => '',
				'provider_label'    => '',
				'fulfillment_count' => 0,
				'api_available'     => true,
			);
		}

		if ( ! class_exists( $utils ) || ! class_exists( $data_store ) || ! function_exists( 'wc_get_container' ) ) {
			// Without the API there is no shipment fact to report. Treated as
			// pending, never as fulfilled.
			return array(
				'shipment_state'    => self::SHIPMENT_PENDING,
				'shipment_date'     => '',
				'provider_label'    => '',
				'fulfillment_count' => 0,
				'api_available'     => false,
			);
		}

		$state = (string) call_user_func( array( $utils, 'get_order_fulfillment_status' ), $order );

		$fulfillments = array();
		try {
			$store        = wc_get_container()->get( $data_store );
			$fulfillments = (array) $store->read_fulfillments( WC_Order::class, (string) $order->get_id() );
		} catch ( Throwable $e ) {
			$fulfillments = array();
		}

		// The handover date is the latest completed fulfillment: the moment the
		// order as a whole left.
		$shipment_date = '';
		$provider      = '';
		foreach ( $fulfillments as $fulfillment ) {
			if ( ! is_object( $fulfillment ) || ! method_exists( $fulfillment, 'get_is_fulfilled' ) ) {
				continue;
			}
			if ( ! $fulfillment->get_is_fulfilled() ) {
				continue;
			}
			$date = (string) ( $fulfillment->get_date_fulfilled() ?? '' );
			if ( '' !== $date && ( '' === $shipment_date || strtotime( $date ) > strtotime( $shipment_date ) ) ) {
				$shipment_date = $date;
				$provider      = (string) call_user_func( array( $utils, 'resolve_provider_name' ), $fulfillment );
			}
		}

		return array(
			'shipment_state'    => in_array( $state, array( self::SHIPMENT_COMPLETE, self::SHIPMENT_PARTIAL, self::SHIPMENT_PENDING ), true ) ? $state : self::SHIPMENT_PENDING,
			'shipment_date'     => $shipment_date,
			'provider_label'    => $provider,
			'fulfillment_count' => count( $fulfillments ),
			'api_available'     => true,
		);
	}

	/**
	 * Read the payment date exactly as WooCommerce recorded it.
	 *
	 * Empty when the order was never paid. The creation date is NOT a
	 * substitute.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function read_payment_date( WC_Order $order ): string {
		$paid = $order->get_date_paid();

		return $paid instanceof WC_DateTime ? $paid->date( 'Y-m-d' ) : '';
	}
}
