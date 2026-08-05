<?php
/** WooCommerce presentation hooks and catalog cache priming. */

defined( 'ABSPATH' ) || exit;

function kuka_island_child_gallery_thumbnail_size(): array {
	return array( 'width' => 180, 'height' => 240, 'crop' => 1 );
}
add_filter( 'woocommerce_get_image_size_gallery_thumbnail', 'kuka_island_child_gallery_thumbnail_size' );
add_filter( 'loop_shop_per_page', static fn(): int => 12, 20 );
add_filter( 'loop_shop_columns', static fn(): int => 4, 20 );

/** Prime product, variation, meta and term caches for the full catalog loop. */
function kuka_island_prime_catalog_caches( $product_ids = false ): void {
	if ( ! function_exists( 'wc_get_product' ) ) { return; }
	if ( false === $product_ids ) {
		global $wp_query;
		$product_ids = is_array( $wp_query->posts ?? null ) ? wp_list_pluck( $wp_query->posts, 'ID' ) : array();
	}
	$product_ids = array_filter( array_map( 'absint', (array) $product_ids ) );
	if ( ! $product_ids ) { return; }
	_prime_post_caches( $product_ids, true, true );
	$variation_ids = array();
	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product_Variable ) {
			$variation_ids = array_merge( $variation_ids, array_map( 'intval', $product->get_children() ) );
		}
	}
	if ( ! $variation_ids ) { return; }
	_prime_post_caches( $variation_ids, true, true );
	$color_slugs = array();
	foreach ( $variation_ids as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( $variation instanceof WC_Product_Variation && $variation->get_attribute( 'pa_renk' ) ) {
			$color_slugs[] = $variation->get_attribute( 'pa_renk' );
		}
	}
	if ( $color_slugs && taxonomy_exists( 'pa_renk' ) ) {
		get_terms( array( 'taxonomy' => 'pa_renk', 'hide_empty' => false, 'slug' => array_values( array_unique( $color_slugs ) ), 'fields' => 'all' ) );
	}
}
add_action( 'woocommerce_before_shop_loop', static function (): void { kuka_island_prime_catalog_caches(); }, 1 );

add_filter(
	'woocommerce_breadcrumb_defaults',
	static function ( array $defaults ): array {
		$defaults['home'] = __( 'Ana sayfa', 'kuka-island' );
		return $defaults;
	}
);

add_action(
	'woocommerce_before_shop_loop_item_title',
	static function (): void {
		global $product;
		if ( ! $product instanceof WC_Product ) { return; }
		$terms = wc_get_product_terms( $product->get_id(), 'pa_kesim', array( 'fields' => 'names' ) );
		if ( $terms ) { echo '<p class="kuka-card__cut">' . esc_html( implode( ', ', $terms ) ) . '</p>'; }
	},
	15
);

add_action(
	'woocommerce_single_product_summary',
	static function (): void {
		global $product;
		if ( ! $product instanceof WC_Product ) { return; }
		foreach ( array( '_kuka_material' => __( 'Malzeme', 'kuka-island' ), '_kuka_care' => __( 'Bakım', 'kuka-island' ), '_kuka_fit' => __( 'Kalıp', 'kuka-island' ), '_kuka_model_info' => __( 'Model', 'kuka-island' ) ) as $key => $label ) {
			$value = $product->get_meta( $key );
			if ( $value ) { echo '<details class="kuka-product-detail"><summary>' . esc_html( $label ) . '</summary><p>' . esc_html( $value ) . '</p></details>'; }
		}
		$guide = $product->get_meta( '_kuka_size_guide' );
		if ( $guide ) { echo '<a class="kuka-size-link" href="' . esc_url( home_url( '/' . trim( $guide, '/' ) . '/' ) ) . '">' . esc_html__( 'Beden rehberini aç', 'kuka-island' ) . '</a>'; }
	},
	35
);

add_action(
	'woocommerce_after_single_product_summary',
	static function (): void {
		global $product;
		if ( ! $product instanceof WC_Product ) { return; }
		$paired = wc_get_product( absint( $product->get_meta( '_kuka_paired_product_id' ) ) );
		if ( $paired ) {
			echo '<section class="kuka-pair"><div><p class="kuka-eyebrow">' . esc_html__( 'Birlikte iyi gider', 'kuka-island' ) . '</p><h2>' . esc_html( $paired->get_name() ) . '</h2><p>' . wp_kses_post( $paired->get_price_html() ) . '</p><a class="kuka-button" href="' . esc_url( $paired->get_permalink() ) . '">' . esc_html__( 'Parçayı incele', 'kuka-island' ) . '</a></div>' . wp_kses_post( $paired->get_image( 'woocommerce_single' ) ) . '</section>';
		}
	},
	5
);

add_filter( 'woocommerce_structured_data_product', static function ( array $markup ): array { unset( $markup['review'], $markup['aggregateRating'] ); return $markup; } );
add_filter( 'woocommerce_product_tabs', static function ( array $tabs ): array { unset( $tabs['reviews'] ); return $tabs; }, 30 );
add_filter( 'comments_open', static fn( bool $open, int $post_id ): bool => 'product' === get_post_type( $post_id ) ? false : $open, 20, 2 );
