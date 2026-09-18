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
$size_terms = get_terms(
	array(
		'taxonomy'   => 'pa_beden',
		'hide_empty' => false,
	)
);
if ( is_wp_error( $size_terms ) || 3 > count( $size_terms ) ) {
	WP_CLI::error( 'S, M and L size terms are required for the product card fixture.' );
}
$size_terms_by_slug = array();
foreach ( $size_terms as $size_term ) {
	if ( $size_term instanceof WP_Term && in_array( $size_term->slug, array( 's', 'm', 'l' ), true ) ) {
		$size_terms_by_slug[ $size_term->slug ] = $size_term;
	}
}
if ( 3 !== count( $size_terms_by_slug ) ) {
	WP_CLI::error( 'The product card fixture could not resolve S, M and L.' );
}
$size_attribute = new WC_Product_Attribute();
$size_attribute->set_id( wc_attribute_taxonomy_id_by_name( 'pa_beden' ) );
$size_attribute->set_name( 'pa_beden' );
$size_attribute->set_options( array_map( static fn( WP_Term $term ): int => $term->term_id, $size_terms_by_slug ) );
$size_attribute->set_visible( true );
$size_attribute->set_variation( true );
$parent->set_attributes( array( $size_attribute ) );
$parent_id = $parent->save();

$variation_ids = array();
foreach ( array( 's' => 3, 'm' => 3, 'l' => 4 ) as $size_slug => $quantity ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_status( 'publish' );
	$variation->set_attributes( array( 'pa_beden' => $size_slug ) );
	$variation->set_regular_price( '10' );
	if ( 's' === $size_slug ) {
		$variation->set_sale_price( '1' );
	}
	$variation->set_manage_stock( true );
	$variation->set_stock_quantity( $quantity );
	$variation_ids[] = $variation->save();
}

try {
	WC_Product_Variable::sync( $parent_id );
	wc_delete_product_transients( $parent_id );
	global $product;
	$product = wc_get_product( $parent_id );

	$request_uri_before = $_SERVER['REQUEST_URI'] ?? null;
	$card_text          = array();
	$card_html          = array();
	foreach ( array( 'tr' => '/', 'en' => '/en/' ) as $language => $request_uri ) {
		$_SERVER['REQUEST_URI'] = $request_uri;
		ob_start();
		wc_get_template_part( 'content', 'product' );
		$html                   = (string) ob_get_clean();
		$card_html[ $language ] = $html;
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
	WP_CLI::line(
		'PRODUCT_CARD_SIZE_ONLY_STOCK=' . (
			3 === substr_count( $card_html['tr'], 'data-card-size=' )
			&& ! str_contains( $card_html['tr'], 'class="is-sold-out"' )
				? 'S:available|M:available|L:available'
				: 'incorrect'
		)
	);
} finally {
	foreach ( $variation_ids as $variation_id ) {
		$stored_variation = wc_get_product( $variation_id );
		if ( $stored_variation instanceof WC_Product_Variation && $variation_id === $stored_variation->get_id() ) {
			wp_delete_post( $variation_id, true );
		}
	}
	if ( $parent_id && 'Kuka değişken fiyat denetimi' === get_the_title( $parent_id ) ) {
		wp_delete_post( $parent_id, true );
	}
}
