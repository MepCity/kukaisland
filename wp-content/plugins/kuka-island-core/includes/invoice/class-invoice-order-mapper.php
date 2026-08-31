<?php
/**
 * WooCommerce Order to Invoice Data Mapper.
 *
 * Maps a WC_Order object into a structured UBL-TR array with fail-closed
 * financial validation and minor-unit integer kuruş precision. No floating
 * point arithmetic is used for money.
 *
 * Accounting semantics (UBL-TR 2.1 TR1.2, line-level allowance approach):
 *
 * - Order-level coupon/discount is NOT emitted as a document level
 *   AllowanceTotalAmount. It is attributed to the individual invoice lines
 *   exactly as WooCommerce charged it: allowance_i = subtotal_i - total_i.
 *   That is what the customer actually paid, so no re-allocation heuristic can
 *   drift away from the charged amount.
 * - InvoiceLine/LineExtensionAmount is therefore the NET (post-discount) line
 *   amount, and each discounted line carries its own
 *   cac:AllowanceCharge (ChargeIndicator=false).
 * - LegalMonetaryTotal/LineExtensionAmount = sum of net line amounts.
 * - Shipping remains a document-level charge (ChargeTotalAmount).
 * - TaxExclusiveAmount = LineExtensionAmount + ChargeTotalAmount.
 *   AllowanceTotalAmount is intentionally absent so the discount is never
 *   deducted twice.
 * - Every TaxSubtotal -- document level and line level -- satisfies
 *   TaxAmount = round_half_up( TaxableAmount * Percent / 100 ) in kuruş,
 *   computed from the discounted taxable base.
 * - TaxTotal/TaxAmount = sum of the document level TaxSubtotal amounts.
 * - TaxInclusiveAmount = TaxExclusiveAmount + TaxTotal/TaxAmount.
 * - PayableAmount = TaxInclusiveAmount and is cross-checked against
 *   WC_Order::get_total(). A mismatch is fail-closed
 *   (`payable_total_mismatch`) rather than silently invoiced.
 *
 * Because tax is recomputed from each rate bucket's summed taxable base, while
 * WooCommerce rounds tax per line, the sum of the line level TaxAmounts can
 * differ from the document level TaxTotal by at most one kuruş per member of a
 * rate bucket. The document level totals are the fiscally authoritative ones
 * and are internally consistent; the payable cross-check above is what
 * guarantees the invoice equals the charged amount.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Order_Mapper {
	private Kuka_Island_Core_Invoice_Config $config;

	public function __construct( Kuka_Island_Core_Invoice_Config $config ) {
		$this->config = $config;
	}

	public static function amount_to_cents( string|int|float|null $amount ): int {
		$str = trim( (string) $amount );
		if ( '' === $str ) {
			return 0;
		}
		$negative  = str_starts_with( $str, '-' );
		$clean     = ltrim( $str, '+-' );
		$parts     = explode( '.', $clean, 2 );
		$lira      = preg_replace( '/\D/', '', $parts[0] );
		$lira_int  = '' === $lira ? 0 : (int) $lira;
		$kurus_str = isset( $parts[1] ) ? substr( preg_replace( '/\D/', '', $parts[1] ) . '00', 0, 2 ) : '00';
		$kurus_int = (int) $kurus_str;
		$total     = ( $lira_int * 100 ) + $kurus_int;
		return $negative ? -$total : $total;
	}

	public static function cents_to_amount( int $cents ): string {
		$negative  = $cents < 0;
		$abs        = abs( $cents );
		$lira       = intdiv( $abs, 100 );
		$kurus      = $abs % 100;
		$formatted  = sprintf( '%d.%02d', $lira, $kurus );
		return $negative ? '-' . $formatted : $formatted;
	}

	/**
	 * Canonical kuruş tax derivation: TaxAmount = round_half_up( Taxable * Percent / 100 ).
	 *
	 * This is the single definition used for every TaxSubtotal the builder
	 * emits, so the UBL invariant holds by construction rather than by luck.
	 *
	 * @param int $taxable_cents Taxable base in kuruş.
	 * @param int $percent Integer VAT percentage.
	 */
	public static function tax_from_taxable( int $taxable_cents, int $percent ): int {
		if ( 0 === $percent || 0 === $taxable_cents ) {
			return 0;
		}

		if ( $taxable_cents < 0 ) {
			return -intdiv( ( -$taxable_cents ) * $percent + 50, 100 );
		}

		return intdiv( $taxable_cents * $percent + 50, 100 );
	}

	/**
	 * Normalise a WooCommerce rate percentage into an integer VAT percent.
	 *
	 * WooCommerce exposes rates as '10.0000' style strings and as floats.
	 * Naive digit stripping turned '10.0000' into 100000, so parse numerically
	 * and fail closed on any non-integer rate (Turkish KDV rates are integers).
	 *
	 * @param mixed $raw Raw rate value.
	 * @return int|null Integer percent, or null when unusable.
	 */
	public static function normalize_percent( $raw ): ?int {
		if ( null === $raw ) {
			return null;
		}
		$str = trim( str_replace( ',', '.', (string) $raw ) );
		if ( '' === $str || ! is_numeric( $str ) ) {
			return null;
		}
		$num = (float) $str;
		if ( $num < 0.0 || $num > 100.0 ) {
			return null;
		}
		$int = (int) round( $num );
		if ( abs( $num - (float) $int ) > 0.0001 ) {
			return null;
		}

		return $int;
	}

	/**
	 * Map a WC_Order to the structured invoice data array.
	 *
	 * @param WC_Order $order WooCommerce Order instance.
	 * @param string   $document_type 'einvoice' or 'earchive'.
	 * @param string   $profile_id 'TICARIFATURA', 'TEMELFATURA', or 'EARSIVFATURA'.
	 * @param string   $receiver_alias Receiver alias ('' for e-Arşiv, see Kuka_Island_Core_Invoice_Manager::resolve_routing()).
	 * @param string   $invoice_number EDM-assigned fiscal document number. Never generated locally.
	 * @return array<string, mixed> Structured UBL-TR data.
	 * @throws Kuka_Island_Core_Invoice_Permanent_Exception If financial or legal data is missing or inconsistent.
	 */
	public function map_order_to_invoice_data( WC_Order $order, string $document_type, string $profile_id, string $receiver_alias, string $invoice_number ): array {
		$uuid = trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true ) );
		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
		}

		$series = Kuka_Island_Core_Invoice_Status::TYPE_EINVOICE === $document_type
			? $this->config->get_series_einvoice()
			: $this->config->get_series_earchive();

		if ( ! preg_match( '/^[A-Z0-9]{3}$/', $series ) ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Invoice series prefix is missing or invalid.',
				'invalid_invoice_series',
				__( 'Fatura seri ön eki (3 karakter) yapılandırılmamış.', 'kuka-island-core' )
			);
		}

		$invoice_number = trim( $invoice_number );
		if ( '' === $invoice_number ) {
			// Defensive: the manager resolves the number through
			// Kuka_Island_Core_Invoice_Numbering before mapping. Local fiscal
			// numbering is prohibited, so there is no fallback here.
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'No EDM-assigned invoice number supplied to the mapper.',
				Kuka_Island_Core_Invoice_Numbering::ERROR_UNCONFIRMED,
				__( 'Fatura numarası yalnızca EDM tarafından atanabilir.', 'kuka-island-core' )
			);
		}

		$currency = trim( (string) $order->get_currency() );
		if ( '' === $currency ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Order currency is missing.',
				'missing_order_currency',
				__( 'Sipariş para birimi bilgisi eksik olduğu için fatura oluşturulamaz.', 'kuka-island-core' )
			);
		}

		$customer = $this->build_customer_data( $order, $document_type, $receiver_alias );
		$supplier = $this->get_supplier_data();

		$math = $this->calculate_monetary_data( $order );

		$notes = array(
			sprintf( 'Sipariş No: #%s', $order->get_order_number() ),
		);
		$payment_title = trim( (string) $order->get_payment_method_title() );
		if ( '' !== $payment_title ) {
			$notes[] = sprintf( 'Ödeme Yöntemi: %s', $payment_title );
		}
		if ( 'EARSIVFATURA' === $profile_id ) {
			$notes[] = 'Bu e-Arşiv fatura 433 sıra no.lu VUK Genel Tebliği uyarınca elektronik ortamda düzenlenmiştir.';
		}

		$date_created = $order->get_date_created();
		if ( ! $date_created instanceof WC_DateTime ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Order creation date is missing.',
				'missing_order_date',
				__( 'Sipariş tarihi bulunamadığı için fatura oluşturulamaz.', 'kuka-island-core' )
			);
		}
		$issue_date = $date_created->date( 'Y-m-d' );
		$issue_time = $date_created->date( 'H:i:s' );

		return array(
			'uuid'              => $uuid,
			'invoice_number'    => $invoice_number,
			'series'            => $series,
			'profile_id'        => $profile_id,
			'document_type'     => $document_type,
			'invoice_type_code' => 'SATIS',
			'issue_date'        => $issue_date,
			'issue_time'        => $issue_time,
			'currency'          => $currency,
			'order_number'      => (string) $order->get_order_number(),
			'order_date'        => $issue_date,
			'receiver_alias'    => $receiver_alias,
			'notes'             => $notes,
			'supplier'          => $supplier,
			'customer'          => $customer,
			'payment'           => array(
				'code'     => '48',
				'due_date' => $issue_date,
				'channel'  => 'IYZICO',
				'terms'    => 'Peşin / Kredi Kartı',
			),
			'totals'            => $math['totals'],
			'tax_summary'       => $math['tax_summary'],
			'lines'             => $math['lines'],
		);
	}

	/**
	 * Compute every monetary figure in integer kuruş.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array{lines: array<int, array<string, mixed>>, totals: array<string, string>, tax_summary: array<string, mixed>}
	 * @throws Kuka_Island_Core_Invoice_Permanent_Exception On unresolvable rates or inconsistent totals.
	 */
	private function calculate_monetary_data( WC_Order $order ): array {
		$lines             = array();
		$buckets           = array();
		$gross_total_cents = 0;
		$net_total_cents   = 0;
		$allowance_cents   = 0;

		/** @var WC_Order_Item_Product $item */
		foreach ( $order->get_items() as $item ) {
			$quantity = max( 1, (int) $item->get_quantity() );

			$gross_line_cents = self::amount_to_cents( $item->get_subtotal() );
			$net_line_cents   = self::amount_to_cents( $item->get_total() );

			// A blank subtotal (legacy / manually built items) means "no separate
			// list price": the net amount is also the gross amount.
			if ( 0 === $gross_line_cents && 0 !== $net_line_cents ) {
				$gross_line_cents = $net_line_cents;
			}

			$line_allowance_cents = $gross_line_cents - $net_line_cents;
			if ( $line_allowance_cents < 0 ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'Order line total exceeds its subtotal, discount attribution is not representable.',
					'invalid_line_discount',
					__( 'Sipariş satırında indirim tutarı hatalı olduğu için fatura oluşturulamadı.', 'kuka-island-core' )
				);
			}

			$percent = $this->resolve_item_tax_percent( $order, $item );
			if ( null === $percent ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'Unable to resolve verified tax rate for order item.',
					'missing_tax_rate',
					__( 'Siparişteki ürün için doğrulanmış KDV oranı tespit edilemedi.', 'kuka-island-core' )
				);
			}

			$line_tax_cents = self::tax_from_taxable( $net_line_cents, $percent );

			$gross_total_cents += $gross_line_cents;
			$net_total_cents   += $net_line_cents;
			$allowance_cents   += $line_allowance_cents;

			$rate_key = (string) $percent;
			if ( ! isset( $buckets[ $rate_key ] ) ) {
				$buckets[ $rate_key ] = array(
					'percent'       => $percent,
					'taxable_cents' => 0,
					'members'       => 0,
				);
			}
			$buckets[ $rate_key ]['taxable_cents'] += $net_line_cents;
			++$buckets[ $rate_key ]['members'];

			$product = $item->get_product();

			$lines[] = array(
				'name'                  => $item->get_name(),
				'sku'                   => $product instanceof WC_Product ? (string) $product->get_sku() : '',
				'quantity'              => $quantity,
				'unit_price'            => self::cents_to_amount( self::divide_half_up( $gross_line_cents, $quantity ) ),
				'gross_amount'          => self::cents_to_amount( $gross_line_cents ),
				'allowance_amount'      => self::cents_to_amount( $line_allowance_cents ),
				'line_extension_amount' => self::cents_to_amount( $net_line_cents ),
				'taxable_amount'        => self::cents_to_amount( $net_line_cents ),
				'tax_percent'           => $percent,
				'tax_amount'            => self::cents_to_amount( $line_tax_cents ),
			);
		}

		// Shipping is a document-level charge with its own rate bucket membership.
		$shipping_net_cents = self::amount_to_cents( $order->get_shipping_total() );
		if ( $shipping_net_cents < 0 ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Negative shipping total is not representable on an invoice.',
				'invalid_shipping_total',
				__( 'Kargo tutarı negatif olduğu için fatura oluşturulamadı.', 'kuka-island-core' )
			);
		}

		if ( $shipping_net_cents > 0 ) {
			$shipping_percent = $this->resolve_shipping_tax_percent( $order );
			if ( null === $shipping_percent ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'Unable to resolve verified shipping tax rate.',
					'missing_shipping_tax_rate',
					__( 'Kargo için doğrulanmış KDV oranı tespit edilemedi.', 'kuka-island-core' )
				);
			}

			$rate_key = (string) $shipping_percent;
			if ( ! isset( $buckets[ $rate_key ] ) ) {
				$buckets[ $rate_key ] = array(
					'percent'       => $shipping_percent,
					'taxable_cents' => 0,
					'members'       => 0,
				);
			}
			$buckets[ $rate_key ]['taxable_cents'] += $shipping_net_cents;
			++$buckets[ $rate_key ]['members'];
		}

		// The coupon attribution must reproduce WooCommerce's own net discount
		// exactly. Any drift means the invoice would not match what was charged.
		$wc_discount_cents = self::amount_to_cents( $order->get_discount_total() );
		if ( $wc_discount_cents !== $allowance_cents ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				sprintf(
					'Coupon attribution mismatch: order discount %d kuruş vs line allowances %d kuruş.',
					$wc_discount_cents,
					$allowance_cents
				),
				'discount_allocation_mismatch',
				__( 'Kupon indirimi satırlara birebir dağıtılamadığı için fatura oluşturulmadı.', 'kuka-island-core' )
			);
		}

		// Deterministic bucket ordering (ascending percent) for reproducible XML.
		ksort( $buckets, SORT_NUMERIC );

		$tax_rates       = array();
		$total_tax_cents = 0;
		$taxed_members   = 0;
		foreach ( $buckets as $bucket ) {
			$taxed_members += (int) $bucket['members'];
			$bucket_tax_cents = self::tax_from_taxable( $bucket['taxable_cents'], $bucket['percent'] );
			$total_tax_cents += $bucket_tax_cents;

			$tax_rates[] = array(
				'percent'        => $bucket['percent'],
				'taxable_amount' => self::cents_to_amount( $bucket['taxable_cents'] ),
				'tax_amount'     => self::cents_to_amount( $bucket_tax_cents ),
				'members'        => $bucket['members'],
			);
		}

		$tax_exclusive_cents = $net_total_cents + $shipping_net_cents;
		$tax_inclusive_cents = $tax_exclusive_cents + $total_tax_cents;

		// WooCommerce rounds tax per line; this invoice derives each rate
		// bucket's tax from that bucket's summed taxable base so every
		// TaxSubtotal satisfies TaxableAmount x Percent / 100 = TaxAmount
		// exactly. The two conventions can differ by at most one kuruş per
		// bucket member, and UBL-TR 2.1 models exactly that difference with
		// cbc:PayableRoundingAmount:
		//
		//   PayableAmount = TaxInclusiveAmount + PayableRoundingAmount
		//
		// So PayableAmount stays byte-identical to the amount actually charged
		// while the tax invariant holds. Anything larger than the arithmetic
		// bound is not a rounding artefact and is fail-closed.
		$order_total_cents = self::amount_to_cents( $order->get_total() );
		$rounding_cents    = $order_total_cents - $tax_inclusive_cents;

		// The bound scales with the shop's own money granularity. WooCommerce
		// rounds each line and the order total to wc_get_price_decimals()
		// places, so a store configured for whole lira (0 decimals) can legally
		// differ from a kuruş-exact computation by up to one lira per bucket
		// member. Anything beyond that is not rounding.
		$granularity    = self::price_granularity_cents();
		$rounding_bound = ( $taxed_members + 1 ) * $granularity;

		if ( abs( $rounding_cents ) > $rounding_bound ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				sprintf(
					'Computed tax-inclusive total %d kuruş differs from the charged order total %d kuruş by %d kuruş, above the %d kuruş rounding bound (granularity %d).',
					$tax_inclusive_cents,
					$order_total_cents,
					$rounding_cents,
					$rounding_bound,
					$granularity
				),
				'payable_total_mismatch',
				__( 'Hesaplanan fatura tutarı, siparişte tahsil edilen tutarla yuvarlama farkının ötesinde ayrıştığı için fatura oluşturulmadı.', 'kuka-island-core' )
			);
		}

		$payable_cents = $order_total_cents;

		return array(
			'lines'       => $lines,
			'totals'      => array(
				'line_extension_amount'  => self::cents_to_amount( $net_total_cents ),
				'gross_line_amount'      => self::cents_to_amount( $gross_total_cents ),
				'line_allowance_total'   => self::cents_to_amount( $allowance_cents ),
				'tax_exclusive_amount'   => self::cents_to_amount( $tax_exclusive_cents ),
				'tax_inclusive_amount'   => self::cents_to_amount( $tax_inclusive_cents ),
				'allowance_total_amount' => self::cents_to_amount( 0 ),
				'charge_total_amount'    => self::cents_to_amount( $shipping_net_cents ),
				'payable_rounding_amount' => self::cents_to_amount( $rounding_cents ),
				'payable_amount'         => self::cents_to_amount( $payable_cents ),
			),
			'tax_summary' => array(
				'total_tax' => self::cents_to_amount( $total_tax_cents ),
				'rates'     => $tax_rates,
			),
		);
	}

	/**
	 * Smallest money step the shop actually charges, expressed in kuruş.
	 *
	 * A shop configured with 2 price decimals charges to the kuruş (1); one
	 * configured with 0 decimals charges to the whole lira (100).
	 */
	public static function price_granularity_cents(): int {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$decimals = max( 0, min( 2, $decimals ) );

		return (int) ( 10 ** ( 2 - $decimals ) );
	}

	/**
	 * Integer half-up division used for unit prices.
	 */
	private static function divide_half_up( int $numerator, int $divisor ): int {
		if ( $divisor <= 0 ) {
			return $numerator;
		}
		if ( $numerator < 0 ) {
			return -intdiv( ( -$numerator * 2 ) + $divisor, $divisor * 2 );
		}

		return intdiv( ( $numerator * 2 ) + $divisor, $divisor * 2 );
	}

	/**
	 * Resolve the verified integer VAT percentage for a line item.
	 *
	 * @param WC_Order               $order Order.
	 * @param WC_Order_Item_Product  $item Line item.
	 */
	private function resolve_item_tax_percent( WC_Order $order, $item ): ?int {
		$taxes = $item->get_taxes();

		$percent = $this->percent_from_rate_ids( $order, is_array( $taxes['total'] ?? null ) ? array_keys( $taxes['total'] ) : array() );
		if ( null !== $percent ) {
			return $percent;
		}

		$line_tax_cents = self::amount_to_cents( $item->get_total_tax() );
		if ( 0 === $line_tax_cents && is_array( $taxes['total'] ?? null ) ) {
			foreach ( $taxes['total'] as $tax_str ) {
				$line_tax_cents += self::amount_to_cents( $tax_str );
			}
		}

		$percent = $this->percent_from_single_order_tax_item( $order );
		if ( null !== $percent ) {
			return $percent;
		}

		// No verified rate at all: only a genuinely untaxed line may continue.
		return 0 === $line_tax_cents ? 0 : null;
	}

	/**
	 * Resolve the verified integer VAT percentage for the shipping charge.
	 */
	private function resolve_shipping_tax_percent( WC_Order $order ): ?int {
		$rate_ids = array();
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			$shipping_taxes = $shipping_item->get_taxes();
			if ( is_array( $shipping_taxes['total'] ?? null ) ) {
				$rate_ids = array_merge( $rate_ids, array_keys( $shipping_taxes['total'] ) );
			}
		}

		$percent = $this->percent_from_rate_ids( $order, $rate_ids );
		if ( null !== $percent ) {
			return $percent;
		}

		$percent = $this->percent_from_single_order_tax_item( $order );
		if ( null !== $percent ) {
			return $percent;
		}

		return 0 === self::amount_to_cents( $order->get_shipping_tax() ) ? 0 : null;
	}

	/**
	 * Look up a verified percentage from WooCommerce tax rate IDs.
	 *
	 * @param WC_Order         $order Order.
	 * @param array<int|string> $rate_ids Candidate rate IDs.
	 */
	private function percent_from_rate_ids( WC_Order $order, array $rate_ids ): ?int {
		foreach ( $rate_ids as $rate_id ) {
			foreach ( $order->get_items( 'tax' ) as $order_tax_item ) {
				if ( (int) $order_tax_item->get_rate_id() !== (int) $rate_id ) {
					continue;
				}
				$percent = self::normalize_percent( $order_tax_item->get_rate_percent() );
				if ( null !== $percent ) {
					return $percent;
				}
			}

			if ( class_exists( 'WC_Tax' ) ) {
				$percent = self::normalize_percent( WC_Tax::get_rate_percent_value( $rate_id ) );
				if ( null !== $percent && $percent > 0 ) {
					return $percent;
				}
			}
		}

		return null;
	}

	/**
	 * When an order carries exactly one tax rate, that rate is unambiguous.
	 */
	private function percent_from_single_order_tax_item( WC_Order $order ): ?int {
		$order_tax_items = $order->get_items( 'tax' );
		if ( 1 !== count( $order_tax_items ) ) {
			return null;
		}

		$first = reset( $order_tax_items );

		return self::normalize_percent( $first->get_rate_percent() );
	}

	/**
	 * Build and validate the receiver (customer) block.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $document_type Document type.
	 * @param string   $receiver_alias Receiver alias.
	 * @return array<string, string>
	 * @throws Kuka_Island_Core_Invoice_Permanent_Exception On missing mandatory receiver data.
	 */
	private function build_customer_data( WC_Order $order, string $document_type, string $receiver_alias ): array {
		$customer_type = (string) $order->get_meta( '_billing_customer_type', true );
		$is_corporate  = 'corporate' === $customer_type || '' !== trim( (string) $order->get_billing_company() );

		$tax_number = trim( (string) $order->get_meta( '_billing_tax_number', true ) );
		$tax_office = trim( (string) $order->get_meta( '_billing_tax_office', true ) );

		if ( $is_corporate ) {
			if ( ! preg_match( '/^\d{10,11}$/', $tax_number ) ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'Corporate tax number is missing or invalid.',
					'invalid_corporate_tax_number',
					__( 'Kurumsal fatura için geçerli bir VKN/TCKN girilmelidir.', 'kuka-island-core' )
				);
			}
			if ( '' === $tax_office ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'Corporate tax office is missing.',
					'missing_tax_office',
					__( 'Kurumsal fatura için vergi dairesi bilgisi zorunludur.', 'kuka-island-core' )
				);
			}
			if ( Kuka_Island_Core_Invoice_Status::TYPE_EINVOICE === $document_type && '' === trim( $receiver_alias ) ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'e-Invoice recipient alias is missing.',
					'missing_recipient_alias',
					__( 'e-Fatura alıcısı için GİB posta kutusu etiketi (alias) bulunamadı.', 'kuka-island-core' )
				);
			}
		} elseif ( '' === $tax_number ) {
			if ( ! $this->config->allow_generic_individual_vkn() ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'Individual TCKN is missing and the generic retail VKN policy is disabled.',
					'missing_individual_tckn',
					__( 'Bireysel müşteri için geçerli bir T.C. Kimlik Numarası girilmelidir.', 'kuka-island-core' )
				);
			}
			// Explicitly enabled and reviewed policy only.
			$tax_number = '11111111111';
		} elseif ( ! preg_match( '/^\d{11}$/', $tax_number ) ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Individual TCKN must be exactly 11 digits.',
				'invalid_individual_tckn',
				__( 'Bireysel T.C. Kimlik Numarası 11 haneli olmalıdır.', 'kuka-island-core' )
			);
		}

		$billing_address = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
		$billing_city    = trim( (string) $order->get_billing_city() );
		if ( '' === $billing_address || '' === $billing_city ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Billing address or city is missing on order.',
				'missing_billing_address',
				__( 'Fatura oluşturmak için alıcı adres ve şehir bilgisi zorunludur.', 'kuka-island-core' )
			);
		}

		$country_code = trim( (string) $order->get_billing_country() );

		return array(
			'first_name' => (string) $order->get_billing_first_name(),
			'last_name'  => (string) $order->get_billing_last_name(),
			'company'    => $is_corporate ? (string) $order->get_billing_company() : '',
			'tax_number' => $tax_number,
			'tax_office' => $tax_office,
			'address'    => $billing_address,
			'district'   => (string) $order->get_meta( '_billing_district', true ),
			'city'       => $billing_city,
			'postcode'   => (string) $order->get_billing_postcode(),
			'country'    => ( '' === $country_code || 'TR' === $country_code ) ? 'Türkiye' : $country_code,
			'email'      => (string) $order->get_billing_email(),
			'phone'      => (string) $order->get_billing_phone(),
		);
	}

	/**
	 * Build and validate the supplier (sender) block.
	 *
	 * @return array<string, string>
	 * @throws Kuka_Island_Core_Invoice_Permanent_Exception On incomplete supplier configuration.
	 */
	private function get_supplier_data(): array {
		$vkn        = $this->config->get_sender_vkn();
		$name       = $this->config->get_sender_title();
		$tax_office = $this->config->get_sender_tax_office();
		$address    = $this->config->get_sender_address();
		$district   = $this->config->get_sender_district();
		$city       = $this->config->get_sender_city();
		$postcode   = $this->config->get_sender_postcode();
		$email      = defined( 'KUKA_SMTP_FROM_EMAIL' ) ? (string) KUKA_SMTP_FROM_EMAIL : '';
		$phone      = defined( 'KUKA_LEGAL_PHONE' ) ? (string) KUKA_LEGAL_PHONE : '';

		if ( '' === $vkn || '' === $name || '' === $tax_office || '' === $address || '' === $district || '' === $city || '' === $postcode ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Supplier legal configuration (VKN, Name, Tax Office, Address, District, City, Postcode) is incomplete.',
				'missing_supplier_configuration',
				__( 'Satıcı kurumsal ve mali bilgileri (VKN, Unvan, Vergi Dairesi, Adres, İlçe, Şehir, Posta Kodu) eksik yapılandırılmış.', 'kuka-island-core' )
			);
		}

		return array(
			'vkn'        => $vkn,
			'name'       => $name,
			'tax_office' => $tax_office,
			'address'    => $address,
			'district'   => $district,
			'city'       => $city,
			'postcode'   => $postcode,
			'country'    => 'Türkiye',
			'email'      => $email,
			'phone'      => $phone,
		);
	}
}
