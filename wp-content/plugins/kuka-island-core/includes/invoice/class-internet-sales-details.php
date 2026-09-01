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
 * Four refusals in particular:
 *
 * - odemeSekli is a fiscal enumeration, not a label. A gateway id ("iyzico",
 *   "pwi") and a checkout title ("Banka/Kredi Kartı ile Öde") are both
 *   user-facing strings, and writing either into odemeSekli would put a
 *   made-up value in a fiscal field. Gateways are resolved through an explicit
 *   table of literals this integration has actually confirmed, and a gateway
 *   with no confirmed literal is refused by name rather than guessed at.
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
 * odemeTarihi is emitted as a plain xs:date value. The current EDM test WSDL
 * (https://test.edmbilisim.com.tr/EFaturaEDM21ea/EFaturaEDM.svc?singleWsdl)
 * declares no odemeTarihiSpecified element, so no such key is produced: adding
 * one would send a field the contract does not have.
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

	/**
	 * The one fiscal payment literal this integration has confirmed.
	 *
	 * ODEMEARACISI states that a payment intermediary collected the money; the
	 * intermediary's name then belongs in odemeAracisiAdi, which is why the two
	 * are validated together and never separately.
	 *
	 * Other fiscal literals are deliberately absent. Adding one requires the
	 * exact spelling from EDM's own INTERNETSATIS documentation -- a plausible
	 * guess written into a fiscal field is worse than a refusal, because the
	 * refusal is visible and the guess is not.
	 */
	public const PAYMENT_INTERMEDIARY = 'ODEMEARACISI';

	/**
	 * The intermediary's name for both WooCommerce iyzico gateways.
	 *
	 * This is the company that collected the payment, not a checkout label, so
	 * it is a constant rather than anything read from gateway settings.
	 */
	public const AGENT_IYZICO = 'iyzico';

	// Safe, distinct refusal codes. Each names exactly one missing fact.
	public const ERROR_PAYMENT_DATE_MISSING = 'internet_sales_payment_date_missing';
	public const ERROR_PAYMENT_METHOD_MISSING = 'internet_sales_payment_method_missing';
	/** The gateway is known to WooCommerce but has no confirmed fiscal literal. */
	public const ERROR_PAYMENT_METHOD_UNMAPPED = 'internet_sales_payment_method_unmapped';
	/** The proposed odemeSekli value is not one of the confirmed literals. */
	public const ERROR_PAYMENT_METHOD_NOT_FISCAL = 'internet_sales_payment_method_not_fiscal';
	/** ODEMEARACISI without the intermediary's name. */
	public const ERROR_PAYMENT_AGENT_MISSING = 'internet_sales_payment_agent_missing';
	public const ERROR_WEB_ADDRESS_MISSING = 'internet_sales_web_address_missing';
	public const ERROR_SHIPMENT_PARTIAL = 'internet_sales_shipment_partial';
	public const ERROR_SHIPMENT_PENDING = 'internet_sales_shipment_pending';
	public const ERROR_SHIPMENT_DATE_MISSING = 'internet_sales_shipment_date_missing';
	public const ERROR_CARRIER_VKN_MISSING = 'internet_sales_carrier_vkn_missing';
	public const ERROR_CARRIER_TITLE_MISSING = 'internet_sales_carrier_title_missing';

	/**
	 * WooCommerce gateway id -> confirmed fiscal payment pair.
	 *
	 * Keyed by WC_Order::get_payment_method(), which is the gateway's id, never
	 * its title. Both entries come from the iyzico-woocommerce plugin:
	 * 'iyzico' is its Checkout Form gateway and 'pwi' is Pay With iyzico
	 * (see includes/Checkout/CheckoutForm.php and includes/Pwi/Pwi.php). In
	 * both cases iyzico collects the money, which is precisely what
	 * ODEMEARACISI states.
	 *
	 * A gateway that is not on this list -- bacs, cheque, cod, or anything
	 * added later -- has no confirmed literal and is refused. That is the whole
	 * point of the table: the missing rows are missing evidence, not oversights
	 * to be filled in with a plausible-looking value.
	 *
	 * @return array<string, array{0: string, 1: string}> gateway id => [odemeSekli, odemeAracisiAdi]
	 */
	public static function payment_gateway_table(): array {
		return array(
			'iyzico' => array( self::PAYMENT_INTERMEDIARY, self::AGENT_IYZICO ),
			'pwi'    => array( self::PAYMENT_INTERMEDIARY, self::AGENT_IYZICO ),
		);
	}

	/**
	 * Every value odemeSekli is allowed to carry.
	 *
	 * @return array<int, string>
	 */
	public static function fiscal_payment_literals(): array {
		return array( self::PAYMENT_INTERMEDIARY );
	}

	/**
	 * Validate a proposed odemeSekli / odemeAracisiAdi pair.
	 *
	 * Separate from the table lookup so the invariant holds for any producer,
	 * including a future table row that forgets the intermediary's name.
	 *
	 * @param string $literal Proposed odemeSekli.
	 * @param string $agent   Proposed odemeAracisiAdi.
	 * @return array{ok: bool, error: string}
	 */
	public static function validate_payment( string $literal, string $agent ): array {
		$literal = trim( $literal );
		$agent   = trim( $agent );

		if ( '' === $literal ) {
			return array(
				'ok'    => false,
				'error' => self::ERROR_PAYMENT_METHOD_MISSING,
			);
		}

		if ( ! in_array( $literal, self::fiscal_payment_literals(), true ) ) {
			// A gateway id ('iyzico') and a checkout title ('Kredi kartı ile
			// öde') both land here. Neither is a fiscal value, so neither is
			// written to the document.
			return array(
				'ok'    => false,
				'error' => self::ERROR_PAYMENT_METHOD_NOT_FISCAL,
			);
		}

		if ( self::PAYMENT_INTERMEDIARY === $literal && '' === $agent ) {
			// ODEMEARACISI says somebody else collected the money. Leaving the
			// name blank states half a fact.
			return array(
				'ok'    => false,
				'error' => self::ERROR_PAYMENT_AGENT_MISSING,
			);
		}

		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * Resolve the fiscal payment fields for a WooCommerce gateway id.
	 *
	 * @param string $gateway_id WC_Order::get_payment_method() value.
	 * @return array{ok: bool, error: string, odemeSekli: string, odemeAracisiAdi: string}
	 */
	public static function payment_for_gateway( string $gateway_id ): array {
		$refused = static function ( string $error ): array {
			return array(
				'ok'              => false,
				'error'           => $error,
				'odemeSekli'      => '',
				'odemeAracisiAdi' => '',
			);
		};

		$key = strtolower( trim( $gateway_id ) );

		if ( '' === $key ) {
			return $refused( self::ERROR_PAYMENT_METHOD_MISSING );
		}

		$table = self::payment_gateway_table();
		if ( ! array_key_exists( $key, $table ) ) {
			return $refused( self::ERROR_PAYMENT_METHOD_UNMAPPED );
		}

		list( $literal, $agent ) = $table[ $key ];

		$verdict = self::validate_payment( $literal, $agent );
		if ( ! $verdict['ok'] ) {
			return $refused( $verdict['error'] );
		}

		return array(
			'ok'              => true,
			'error'           => '',
			'odemeSekli'      => $literal,
			'odemeAracisiAdi' => $agent,
		);
	}

	/**
	 * Build the block, or report exactly what is missing.
	 *
	 * Pure: every fact arrives as an argument, so the whole matrix is provable
	 * from fixtures without an order, a database or a network.
	 *
	 * @param array<string, mixed> $facts Observed facts:
	 *     web_address      string  Shop URL.
	 *     payment_gateway  string  WooCommerce gateway ID, from
	 *                              WC_Order::get_payment_method(). Resolved
	 *                              through payment_gateway_table(); the
	 *                              gateway's own id and its checkout title are
	 *                              never written to odemeSekli.
	 *     payment_date     string  From WC_Order::get_date_paid(). Never derived.
	 *     shipment_state   string  One of the SHIPMENT_* constants.
	 *     shipment_date    string  Date the goods were handed over.
	 *     carrier_vkn      string  Carrier tax number. Never guessed.
	 *     carrier_title    string  Carrier legal title. Never guessed.
	 * @return array{ok: bool, errors: array<int, string>, details: array<string, mixed>}
	 */
	public static function build( array $facts ): array {
		$web_address     = trim( (string) ( $facts['web_address'] ?? '' ) );
		$payment_gateway = trim( (string) ( $facts['payment_gateway'] ?? '' ) );
		$payment_date    = trim( (string) ( $facts['payment_date'] ?? '' ) );
		$shipment_state  = (string) ( $facts['shipment_state'] ?? self::SHIPMENT_PENDING );
		$shipment_date   = trim( (string) ( $facts['shipment_date'] ?? '' ) );
		$carrier_vkn     = trim( (string) ( $facts['carrier_vkn'] ?? '' ) );
		$carrier_title   = trim( (string) ( $facts['carrier_title'] ?? '' ) );

		$errors = array();

		if ( '' === $web_address ) {
			$errors[] = self::ERROR_WEB_ADDRESS_MISSING;
		}

		$payment = self::payment_for_gateway( $payment_gateway );
		if ( ! $payment['ok'] ) {
			$errors[] = $payment['error'];
		}

		if ( '' === $payment_date ) {
			// An unpaid order has no payment date. There is nothing to fall
			// back to that would not be a fabrication.
			$errors[] = self::ERROR_PAYMENT_DATE_MISSING;
		}

		$details = array(
			'webAdresi'   => $web_address,
			'odemeSekli'  => $payment['odemeSekli'],
			// xs:date. The WSDL has no odemeTarihiSpecified companion element,
			// so none is emitted.
			'odemeTarihi' => $payment_date,
		);

		// Present exactly when the resolved literal calls for it.
		if ( '' !== $payment['odemeAracisiAdi'] ) {
			$details['odemeAracisiAdi'] = $payment['odemeAracisiAdi'];
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
	 * Read the gateway ID WooCommerce recorded for the order.
	 *
	 * get_payment_method(), never get_payment_method_title(): the title is a
	 * shop-editable label ("Banka/Kredi Kartı ile Öde") and has no fiscal
	 * meaning. The id is what payment_gateway_table() is keyed by.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function read_payment_gateway( WC_Order $order ): string {
		return trim( (string) $order->get_payment_method() );
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
