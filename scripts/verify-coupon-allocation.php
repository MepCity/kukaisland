<?php
/**
 * Coupon allocation audit.
 *
 * Builds a cart from three differently priced variations, applies a coupon and
 * compares WooCommerce's per-line discount (`_line_subtotal` minus
 * `_line_total`) against the coupon amount. Nothing is corrected here: the
 * script only measures, because invoice arithmetic belongs to WooCommerce
 * (PLAN §17.3).
 *
 * Usage: docker compose run --rm wp-cli wp eval-file /var/www/html/scripts/verify-coupon-allocation.php
 *
 * @package KukaIslandOps
 */

if ( ! function_exists( 'wc_load_cart' ) ) {
	WP_CLI::error( 'WooCommerce yüklü değil.' );
}

wc_load_cart();

/**
 * Pick the first purchasable, in-stock variation of each parent product.
 *
 * @return array<int, int>
 */
function kuka_audit_pick_variations(): array {
	$picked = array();
	$query  = new WP_Query(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	foreach ( $query->posts as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product_Variable ) { continue; }
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof WC_Product_Variation && $variation->is_in_stock() && $variation->is_purchasable() ) {
				$picked[ (string) $variation->get_price() ] = $variation_id;
				break;
			}
		}
	}
	return array_values( array_slice( $picked, 0, 3 ) );
}

/**
 * Create the audit coupon when it is missing so the script is reproducible on
 * a freshly reset database.
 */
function kuka_audit_ensure_coupon( string $code, string $type, string $amount ): void {
	if ( wc_get_coupon_id_by_code( $code ) ) { return; }
	$coupon = new WC_Coupon();
	$coupon->set_code( $code );
	$coupon->set_discount_type( $type );
	$coupon->set_amount( $amount );
	$coupon->save();
	WP_CLI::log( sprintf( 'Denetim kuponu oluşturuldu: %s', $code ) );
}

/**
 * Build the cart, apply the coupon, create the order and report the allocation.
 *
 * @param string           $coupon_code Coupon to apply.
 * @param array<int, int>  $variations  Variation ids.
 */
function kuka_audit_run( string $coupon_code, array $variations ): void {
	WC()->cart->empty_cart();
	WC()->cart->remove_coupons();

	foreach ( $variations as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		WC()->cart->add_to_cart( $variation->get_parent_id(), 1, $variation_id, $variation->get_variation_attributes() );
	}

	WC()->cart->calculate_totals();
	$subtotal_before = (float) WC()->cart->get_subtotal();

	if ( ! WC()->cart->apply_coupon( $coupon_code ) ) {
		WP_CLI::warning( sprintf( 'Kupon uygulanamadı: %s', $coupon_code ) );
		return;
	}
	WC()->cart->calculate_totals();

	$coupon          = new WC_Coupon( $coupon_code );
	$cart_discount   = (float) WC()->cart->get_discount_total();
	$expected        = 'percent' === $coupon->get_discount_type()
		? round( $subtotal_before * (float) $coupon->get_amount() / 100, wc_get_price_decimals() )
		: (float) $coupon->get_amount();

	$order_id = WC()->checkout()->create_order(
		array(
			'billing_first_name' => 'Kupon',
			'billing_last_name'  => 'Denetimi',
			'billing_email'      => 'kupon-denetimi@example.com',
			'billing_country'    => 'TR',
			'payment_method'     => '',
			'shipping_method'    => array(),
		)
	);
	if ( is_wp_error( $order_id ) ) {
		WP_CLI::warning( $order_id->get_error_message() );
		return;
	}
	$order = wc_get_order( $order_id );

	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '== Kupon: %s (%s / %s) ==', $coupon_code, $coupon->get_discount_type(), $coupon->get_amount() ) );
	WP_CLI::log( sprintf( 'Sipariş #%d', $order_id ) );
	WP_CLI::log( str_pad( 'Satır', 34 ) . str_pad( '_line_subtotal', 16 ) . str_pad( '_line_total', 16 ) . 'fark' );

	$allocated = 0.0;
	foreach ( $order->get_items() as $item ) {
		$line_subtotal = (float) $item->get_subtotal();
		$line_total    = (float) $item->get_total();
		$difference    = round( $line_subtotal - $line_total, wc_get_price_decimals() );
		$allocated    += $difference;
		WP_CLI::log(
			str_pad( mb_substr( $item->get_name(), 0, 32 ), 34 )
			. str_pad( number_format( $line_subtotal, 2, ',', '.' ), 16 )
			. str_pad( number_format( $line_total, 2, ',', '.' ), 16 )
			. number_format( $difference, 2, ',', '.' )
		);
	}

	$allocated = round( $allocated, wc_get_price_decimals() );
	$expected  = round( $expected, wc_get_price_decimals() );
	WP_CLI::log( sprintf( 'Ara toplam (kupon öncesi): %s', number_format( $subtotal_before, 2, ',', '.' ) ) );
	WP_CLI::log( sprintf( 'Satır indirimleri toplamı: %s', number_format( $allocated, 2, ',', '.' ) ) );
	WP_CLI::log( sprintf( 'Sepet indirim toplamı:     %s', number_format( $cart_discount, 2, ',', '.' ) ) );
	WP_CLI::log( sprintf( 'Beklenen kupon tutarı:     %s', number_format( $expected, 2, ',', '.' ) ) );
	WP_CLI::log( sprintf( 'Sipariş indirim toplamı:   %s', number_format( (float) $order->get_discount_total(), 2, ',', '.' ) ) );

	$matches = abs( $allocated - $expected ) < 0.005 && abs( $cart_discount - $expected ) < 0.005;
	WP_CLI::log( $matches ? 'SONUÇ: EŞİT (kuruşu kuruşuna)' : 'SONUÇ: FARK VAR — düzeltme yazılmadı, rapor edilir' );

	if ( wc_tax_enabled() ) {
		WP_CLI::log( sprintf( 'Vergi matrahı: satır bazında %s', wp_json_encode( array_map( static fn( $i ) => array( (float) $i->get_subtotal_tax(), (float) $i->get_total_tax() ), array_values( $order->get_items() ) ) ) ) );
	} else {
		WP_CLI::log( 'KDV: WooCommerce vergi hesabı kapalı (woocommerce_calc_taxes=no) — vergi matrahı satırı yok.' );
	}

	$order->delete( true );
	WC()->cart->remove_coupons();
	WC()->cart->empty_cart();
}

$variations = kuka_audit_pick_variations();
if ( count( $variations ) < 3 ) {
	WP_CLI::error( 'Üç farklı fiyatlı varyasyon bulunamadı.' );
}
WP_CLI::log( 'Seçilen varyasyonlar: ' . implode( ', ', array_map( static fn( $id ) => $id . ' (' . wc_get_product( $id )->get_price() . ')', $variations ) ) );

kuka_audit_ensure_coupon( 'kuka500', 'fixed_cart', '500' );
kuka_audit_ensure_coupon( 'kuka10', 'percent', '10' );

kuka_audit_run( 'kuka500', $variations );
kuka_audit_run( 'kuka10', $variations );
