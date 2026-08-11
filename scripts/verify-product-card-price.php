<?php
/**
 * Verify that an on-sale variable product card uses its variation prices.
 *
 * Usage: wp eval-file /project-scripts/verify-product-card-price.php
 *
 * @package KukaIslandOps
 */

$parent = new WC_Product_Variable();
$parent->set_name( 'Kuka değişken fiyat denetimi' );
$parent->set_status( 'publish' );
$parent->set_catalog_visibility( 'visible' );
$parent_id = $parent->save();

$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent_id );
$variation->set_status( 'publish' );
$variation->set_regular_price( '10' );
$variation->set_sale_price( '1' );
$variation_id = $variation->save();

try {
	WC_Product_Variable::sync( $parent_id );
	wc_delete_product_transients( $parent_id );
	global $product;
	$product = wc_get_product( $parent_id );

	$request_uri_before = $_SERVER['REQUEST_URI'] ?? null;
	$card_text          = array();
	foreach ( array( 'tr' => '/', 'en' => '/en/' ) as $language => $request_uri ) {
		$_SERVER['REQUEST_URI'] = $request_uri;
		ob_start();
		wc_get_template_part( 'content', 'product' );
		$html                   = (string) ob_get_clean();
		$card_text[ $language ] = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, get_bloginfo( 'charset' ) );
	}
	if ( null === $request_uri_before ) {
		unset( $_SERVER['REQUEST_URI'] );
	} else {
		$_SERVER['REQUEST_URI'] = $request_uri_before;
	}

	WP_CLI::line( 'PRODUCT_CARD_VARIABLE_ON_SALE=' . ( $product->is_on_sale() ? 'yes' : 'no' ) );
	WP_CLI::line( 'PRODUCT_CARD_MIN_PRICE=' . wc_format_decimal( $product->get_price(), 2 ) );
	WP_CLI::line( 'PRODUCT_CARD_ONE_LIRA_TR=' . ( str_contains( $card_text['tr'], '₺1' ) ? 'present' : 'missing' ) );
	WP_CLI::line( 'PRODUCT_CARD_ZERO_LIRA_TR=' . ( str_contains( $card_text['tr'], '₺0' ) ? 'present' : 'absent' ) );
	WP_CLI::line( 'PRODUCT_CARD_ONE_LIRA_EN=' . ( str_contains( $card_text['en'], '₺1' ) ? 'present' : 'missing' ) );
	WP_CLI::line( 'PRODUCT_CARD_ZERO_LIRA_EN=' . ( str_contains( $card_text['en'], '₺0' ) ? 'present' : 'absent' ) );
} finally {
	$stored_variation = wc_get_product( $variation_id );
	if ( $stored_variation instanceof WC_Product_Variation && $variation_id === $stored_variation->get_id() ) {
		wp_delete_post( $variation_id, true );
	}
	if ( $parent_id && 'Kuka değişken fiyat denetimi' === get_the_title( $parent_id ) ) {
		wp_delete_post( $parent_id, true );
	}
}
