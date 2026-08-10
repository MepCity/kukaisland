<?php
/**
 * Verify that a free-shipping coupon unlocks the free method below the threshold.
 *
 * Usage: wp eval-file /project-scripts/verify-free-shipping-coupon.php
 *
 * @package KukaIslandOps
 */

if ( ! function_exists( 'wc_load_cart' ) ) {
	WP_CLI::error( 'WooCommerce yüklü değil.' );
}

wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->remove_coupons();

$content   = Kuka_Island_Core_Site_Appearance::get();
$threshold = (float) ( $content['commercial']['free_shipping_threshold'] ?? 0 );
$products  = wc_get_products(
	array(
		'status' => 'publish',
		'limit'  => -1,
		'type'   => array( 'simple', 'variation' ),
		'orderby' => 'price',
		'order'   => 'ASC',
	)
);
$product = null;
foreach ( $products as $candidate ) {
	if ( $candidate->is_purchasable() && $candidate->is_in_stock() && (float) $candidate->get_price() < $threshold ) {
		$product = $candidate;
		break;
	}
}
if ( ! $product instanceof WC_Product ) {
	WP_CLI::error( 'Ücretsiz kargo eşiğinin altında test ürünü bulunamadı.' );
}

$coupon = new WC_Coupon();
$coupon->set_code( 'kuka-free-shipping-audit-' . strtolower( wp_generate_password( 8, false, false ) ) );
$coupon->set_discount_type( 'fixed_cart' );
$coupon->set_amount( 0 );
$coupon->set_free_shipping( true );
$coupon_id = $coupon->save();

try {
	WC()->customer->set_billing_country( 'TR' );
	WC()->customer->set_shipping_country( 'TR' );
	WC()->customer->set_billing_state( 'TR34' );
	WC()->customer->set_shipping_state( 'TR34' );

	if ( $product instanceof WC_Product_Variation ) {
		WC()->cart->add_to_cart( $product->get_parent_id(), 1, $product->get_id(), $product->get_variation_attributes() );
	} else {
		WC()->cart->add_to_cart( $product->get_id(), 1 );
	}
	WC()->cart->calculate_totals();
	$subtotal = (float) WC()->cart->get_subtotal();

	if ( ! WC()->cart->apply_coupon( $coupon->get_code() ) ) {
		WP_CLI::error( 'Denetim kuponu uygulanamadı.' );
	}
	WC()->cart->calculate_totals();
	$packages = WC()->shipping()->calculate_shipping( WC()->cart->get_shipping_packages() );
	$rates    = $packages[0]['rates'] ?? array();
	$methods  = array_map( static fn( WC_Shipping_Rate $rate ): string => $rate->get_method_id(), $rates );
	$cost     = array_sum( array_map( static fn( WC_Shipping_Rate $rate ): float => (float) $rate->get_cost(), $rates ) );

	WP_CLI::line( 'FREE_SHIPPING_COUPON_SUBTOTAL=' . wc_format_decimal( $subtotal, 2 ) );
	WP_CLI::line( 'FREE_SHIPPING_COUPON_BELOW_THRESHOLD=' . ( $subtotal < $threshold ? 'yes' : 'no' ) );
	WP_CLI::line( 'FREE_SHIPPING_COUPON_METHODS=' . implode( ',', array_values( $methods ) ) );
	WP_CLI::line( 'FREE_SHIPPING_COUPON_COST=' . wc_format_decimal( $cost, 2 ) );
} finally {
	WC()->cart->remove_coupons();
	WC()->cart->empty_cart();
	$stored_coupon = wc_get_coupon_id_by_code( $coupon->get_code() );
	if ( $coupon_id && $stored_coupon === $coupon_id ) {
		wp_delete_post( $coupon_id, true );
	}
}
