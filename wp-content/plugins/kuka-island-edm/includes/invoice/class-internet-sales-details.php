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
 * @package Kuka_Island_EDM
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
	/** The fulfilled shipment carries no WooCommerce provider key. */
	public const ERROR_CARRIER_PROVIDER_MISSING = 'internet_sales_carrier_provider_missing';
	/** The provider key has no reviewed fiscal identity configured. */
	public const ERROR_CARRIER_UNMAPPED = 'internet_sales_carrier_unmapped';
	/** The configured carrier VKN is not 10 or 11 digits. */
	public const ERROR_CARRIER_VKN_INVALID = 'internet_sales_carrier_vkn_invalid';
	/** The order shipped with more than one carrier. */
	public const ERROR_CARRIER_MULTIPLE_PROVIDERS = 'internet_sales_carrier_multiple_providers';
	/** A fulfilled shipment carries a date this code cannot read. */
	public const ERROR_SHIPMENT_DATE_INVALID = 'internet_sales_shipment_date_invalid';
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

		/*
		 * Key order is the WSDL's own sequence for the inline
		 * INTERNETSALESDETAILS complexType:
		 *   webAdresi, odemeSekli, odemeAracisiAdi, odemeTarihi, gonderiBilgileri
		 * (gonderiBilgileri: gonderimTarihi, then gonderiTasiyan).
		 * PHP's WSDL-driven SoapClient orders by the schema, but emitting the
		 * array in sequence order keeps the producer readable against the
		 * contract it serialises into.
		 */
		$details = array(
			'webAdresi'  => $web_address,
			'odemeSekli' => $payment['odemeSekli'],
		);

		// Present exactly when the resolved literal calls for it.
		if ( '' !== $payment['odemeAracisiAdi'] ) {
			$details['odemeAracisiAdi'] = $payment['odemeAracisiAdi'];
		}

		// xs:date. The WSDL has no odemeTarihiSpecified companion element, so
		// none is emitted.
		$details['odemeTarihi'] = $payment_date;

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
	 * Parse a WooCommerce fulfillment timestamp, strictly, as UTC.
	 *
	 * Measured against WooCommerce 11.0.1 rather than assumed.
	 * Fulfillment::set_date_fulfilled() runs its input through
	 * normalize_date_to_utc(), which reads a zone-less string in wp_timezone()
	 * and STORES the UTC equivalent; get_date_fulfilled() is documented as
	 * returning "a UTC 'Y-m-d H:i:s' string", and the data store's own comment
	 * says "DB values are already stored in UTC". Round-tripped here on this
	 * install (PHP UTC, WordPress Europe/Istanbul):
	 *
	 *   set_date_fulfilled( '2026-09-02 23:30:00' )  ->  '2026-09-02 20:30:00'
	 *   set_date_fulfilled( '2026-09-02 00:30:00' )  ->  '2026-09-01 21:30:00'
	 *
	 * So the raw value is UTC, and the day that belongs on the document is that
	 * moment expressed in the SHOP's timezone.
	 *
	 * The old strtotime() reached the same answer here only because WordPress
	 * happens to leave PHP's default timezone at UTC: the input zone was
	 * inherited rather than stated. Any code calling date_default_timezone_set()
	 * would have silently moved every handover date on a fiscal document, and
	 * strtotime() would additionally accept loose input and answer with a
	 * plausible wrong date. Both are stated explicitly now.
	 *
	 * Strict on purpose. A value that is not exactly the expected format, that
	 * carries leading or trailing data, or that names a day that does not exist
	 * is refused rather than coerced.
	 *
	 * The input is deliberately NOT normalised -- no trim, no ltrim, no rtrim.
	 * Trimming first made the method contradict its own contract:
	 * ' 2026-09-02 20:30:00' and '2026-09-02 20:30:00 ' were accepted, because
	 * the round-trip below then compared against the already-cleaned string. A
	 * stored fiscal timestamp carrying stray whitespace is a corrupt row, and
	 * quietly repairing one is how a value nobody wrote gets onto a document.
	 * The empty string is the one value that is simply absent.
	 *
	 * @param string $raw Raw date_fulfilled value, in UTC.
	 * @return DateTimeImmutable|null Null when the value cannot be trusted.
	 */
	public static function parse_fulfillment_datetime( string $raw ): ?DateTimeImmutable {
		if ( '' === $raw ) {
			return null;
		}

		/*
		 * The input zone is stated, never inherited from PHP's default. The
		 * leading '!' resets every field the format does not name, so no part of
		 * the current time can leak into the result.
		 */
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $raw, new DateTimeZone( 'UTC' ) );
		if ( ! $parsed instanceof DateTimeImmutable ) {
			return null;
		}

		/*
		 * PHP 8.2 changed getLastErrors() to return false rather than an array
		 * of zero counts when nothing went wrong, so the array shape cannot be
		 * assumed.
		 */
		$errors = DateTimeImmutable::getLastErrors();
		if ( is_array( $errors )
			&& ( ( (int) ( $errors['warning_count'] ?? 0 ) ) > 0 || ( (int) ( $errors['error_count'] ?? 0 ) ) > 0 ) ) {
			return null;
		}

		/*
		 * Round-trip against the UNMODIFIED input: catches leading and trailing
		 * data of any kind -- spaces, tabs, newlines, CRLF -- and a rolled-over
		 * impossible date such as 2026-02-30 even where the parser raises
		 * nothing. Compared before the zone shift, while the value is still the
		 * stored one.
		 */
		if ( $parsed->format( 'Y-m-d H:i:s' ) !== $raw ) {
			return null;
		}

		// The same instant, expressed where the shop lives.
		return $parsed->setTimezone( wp_timezone() );
	}

	/**
	 * The shop-local calendar day of a fulfillment timestamp.
	 *
	 * gonderimTarihi is xs:date, and the day is the day it was in the shop --
	 * so a handover at 23:30 Istanbul is reported on that day even though the
	 * value WooCommerce stored for it reads 20:30 the same date in UTC. Uses the
	 * same parser as the comparison that picks the latest shipment, so the two
	 * can never disagree.
	 *
	 * @param string $raw Raw date_fulfilled value, in UTC.
	 * @return string 'Y-m-d', or '' when the value cannot be trusted.
	 */
	public static function fulfillment_calendar_day( string $raw ): string {
		$parsed = self::parse_fulfillment_datetime( $raw );

		return $parsed instanceof DateTimeImmutable ? $parsed->format( 'Y-m-d' ) : '';
	}

	/**
	 * May a whole-order invoice be issued for this shipment state?
	 *
	 * A physical order is invoiced when it has ALL left, not when its payment
	 * cleared: the internet-sales block states when the goods were handed over,
	 * and a partial shipment has no such moment. An order with nothing to ship
	 * has nothing to wait for.
	 *
	 * @param string $shipment_state One of the SHIPMENT_* constants.
	 */
	public static function is_invoiceable_shipment( string $shipment_state ): bool {
		return in_array( $shipment_state, array( self::SHIPMENT_COMPLETE, self::SHIPMENT_NONE ), true );
	}

	/**
	 * Read the shipment facts for an order from WooCommerce's real API.
	 *
	 * Verified against WooCommerce 11.0.1:
	 *   Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils
	 *     ::get_order_fulfillment_status() -> fulfilled | partially_fulfilled |
	 *       unfulfilled | no_fulfillments (read from the order's
	 *       _fulfillment_status meta)
	 *   ...\Fulfillments\DataStore\FulfillmentsDataStore::read_fulfillments()
	 *   ...\Fulfillments\Fulfillment::get_is_fulfilled(): bool
	 *   ...\Fulfillments\Fulfillment::get_date_fulfilled(): ?string
	 *   ...\Fulfillments\Fulfillment::get_shipment_provider(): ?string
	 *
	 * provider_keys are the raw WooCommerce provider keys of the FULFILLED
	 * shipments -- 'dhl', 'aras-kargo' and so on. That key, not
	 * resolve_provider_name()'s display label, is what a fiscal identity is
	 * looked up by: the label is shop-editable text and carries no identity.
	 *
	 * shipment_date is the LATEST fulfilled date, which is the moment the order
	 * as a whole left.
	 *
	 * @param WC_Order $order Order.
	 * @return array{shipment_state: string, shipment_date: string, provider_keys: array<int, string>, provider_label: string, fulfillment_count: int, fulfilled_count: int, api_available: bool}
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
				'shipment_date_invalid' => false,
				'provider_keys'     => array(),
				'provider_label'    => '',
				'fulfillment_count' => 0,
				'fulfilled_count'   => 0,
				'api_available'     => true,
			);
		}

		if ( ! class_exists( $utils ) || ! class_exists( $data_store ) || ! function_exists( 'wc_get_container' ) ) {
			// Without the API there is no shipment fact to report. Treated as
			// pending, never as fulfilled.
			return array(
				'shipment_state'    => self::SHIPMENT_PENDING,
				'shipment_date'     => '',
				'shipment_date_invalid' => false,
				'provider_keys'     => array(),
				'provider_label'    => '',
				'fulfillment_count' => 0,
				'fulfilled_count'   => 0,
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

		$shipment_date   = '';
		$shipment_moment = null;
		$provider_label  = '';
		$provider_keys   = array();
		$fulfilled_count = 0;
		$invalid_dates   = 0;

		foreach ( $fulfillments as $fulfillment ) {
			if ( ! is_object( $fulfillment ) || ! method_exists( $fulfillment, 'get_is_fulfilled' ) ) {
				continue;
			}
			if ( ! $fulfillment->get_is_fulfilled() ) {
				continue;
			}

			++$fulfilled_count;

			$provider_key = method_exists( $fulfillment, 'get_shipment_provider' )
				? strtolower( trim( (string) $fulfillment->get_shipment_provider() ) )
				: '';
			if ( '' !== $provider_key && ! in_array( $provider_key, $provider_keys, true ) ) {
				$provider_keys[] = $provider_key;
			}

			$date = (string) ( $fulfillment->get_date_fulfilled() ?? '' );
			if ( '' === $date ) {
				continue;
			}

			$moment = self::parse_fulfillment_datetime( $date );
			if ( ! $moment instanceof DateTimeImmutable ) {
				// A fulfilled shipment whose handover time cannot be read. Not
				// skipped quietly: the caller fails the whole block closed.
				++$invalid_dates;
				continue;
			}

			// Compared as moments, not as strings, so two shipments either side
			// of midnight order correctly.
			if ( null === $shipment_moment || $moment > $shipment_moment ) {
				$shipment_moment = $moment;
				$shipment_date   = $date;
				$provider_label  = (string) call_user_func( array( $utils, 'resolve_provider_name' ), $fulfillment );
			}
		}

		sort( $provider_keys );

		return array(
			'shipment_state'    => in_array( $state, array( self::SHIPMENT_COMPLETE, self::SHIPMENT_PARTIAL, self::SHIPMENT_PENDING ), true ) ? $state : self::SHIPMENT_PENDING,
			'shipment_date'     => $shipment_date,
			// True when a fulfilled shipment carried a date this code refuses to
			// interpret. The caller must not invoice past it.
			'shipment_date_invalid' => $invalid_dates > 0,
			'provider_keys'     => $provider_keys,
			// Display only. Never a fiscal identity.
			'provider_label'    => $provider_label,
			'fulfillment_count' => count( $fulfillments ),
			'fulfilled_count'   => $fulfilled_count,
			'api_available'     => true,
		);
	}

	/**
	 * WooCommerce's own display name for a shipment provider key.
	 *
	 * DISPLAY ONLY. It is read from the provider registry so the order screen can
	 * say "DHL" rather than "dhl", and it is never used to look up a fiscal
	 * identity -- resolve_carrier() is keyed by
	 * Fulfillment::get_shipment_provider() and by nothing else.
	 *
	 * @param string $provider_key Provider key.
	 */
	public static function provider_display_label( string $provider_key ): string {
		$key = strtolower( trim( $provider_key ) );
		if ( '' === $key ) {
			return '';
		}

		$utils = '\Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils';
		if ( ! class_exists( $utils ) || ! method_exists( $utils, 'get_shipping_providers' ) ) {
			return strtoupper( $key );
		}

		$providers = (array) call_user_func( array( $utils, 'get_shipping_providers' ) );
		$provider  = $providers[ $key ] ?? null;

		if ( is_object( $provider ) && method_exists( $provider, 'get_name' ) ) {
			$name = trim( (string) $provider->get_name() );
			if ( '' !== $name ) {
				return $name;
			}
		}

		return strtoupper( $key );
	}

	/**
	 * Resolve the carrier's fiscal identity from the provider keys that shipped.
	 *
	 * The VKN and legal title are never inferred from the provider key or its
	 * label: 'dhl' is WooCommerce's identifier for a courier, not a taxpayer.
	 * They come only from the reviewed environment configuration, and a key with
	 * no entry is refused by name -- including the free-text 'other' provider,
	 * which is a box somebody typed into.
	 *
	 * More than one distinct carrier is refused rather than resolved. A single
	 * whole-order invoice has one gonderiTasiyan, and picking the last or the
	 * largest shipment would state something about the delivery that is not
	 * true. Several fulfillments with the SAME provider are fine.
	 *
	 * @param Kuka_Island_Core_Invoice_Config $config        Invoice configuration.
	 * @param array<int, string>              $provider_keys Distinct provider keys that shipped.
	 * @return array{ok: bool, error: string, provider_key: string, vkn: string, title: string}
	 */
	public static function resolve_carrier( Kuka_Island_Core_Invoice_Config $config, array $provider_keys ): array {
		$refused = static function ( string $error, string $provider_key = '' ): array {
			return array(
				'ok'           => false,
				'error'        => $error,
				'provider_key' => $provider_key,
				'vkn'          => '',
				'title'        => '',
			);
		};

		$provider_keys = array_values( array_unique( array_filter( array_map( static fn( $key ): string => strtolower( trim( (string) $key ) ), $provider_keys ) ) ) );

		if ( array() === $provider_keys ) {
			return $refused( self::ERROR_CARRIER_PROVIDER_MISSING );
		}

		if ( count( $provider_keys ) > 1 ) {
			return $refused( self::ERROR_CARRIER_MULTIPLE_PROVIDERS, implode( '+', $provider_keys ) );
		}

		$provider_key = $provider_keys[0];
		$carrier      = $config->get_carrier( $provider_key );

		if ( array() === $carrier ) {
			return $refused( self::ERROR_CARRIER_UNMAPPED, $provider_key );
		}

		$vkn   = trim( (string) ( $carrier['vkn'] ?? '' ) );
		$title = trim( (string) ( $carrier['title'] ?? '' ) );

		if ( '' === $vkn ) {
			return $refused( self::ERROR_CARRIER_VKN_MISSING, $provider_key );
		}
		/*
		 * Exactly ten digits. The serialisation always writes
		 * gonderiTasiyan/tuzelKisi/vkn -- a legal person's tax number -- and an
		 * eleven-digit value is a TCKN, which belongs to a natural person and
		 * to the gercekKisi branch instead. Accepting eleven here would put a
		 * citizen's identity number in a company's VKN field.
		 *
		 * Natural-person carriers are deliberately out of scope: supporting
		 * them means an explicit identity type and the gercekKisi { tckn,
		 * adiSoyadi } branch, not a wider pattern here.
		 */
		if ( 1 !== preg_match( '/^\d{10}$/', $vkn ) ) {
			return $refused( self::ERROR_CARRIER_VKN_INVALID, $provider_key );
		}
		if ( '' === $title ) {
			return $refused( self::ERROR_CARRIER_TITLE_MISSING, $provider_key );
		}

		return array(
			'ok'           => true,
			'error'        => '',
			'provider_key' => $provider_key,
			'vkn'          => $vkn,
			'title'        => $title,
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
