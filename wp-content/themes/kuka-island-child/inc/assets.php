<?php
/** Conditional storefront assets and product localization. */

defined( 'ABSPATH' ) || exit;

/** Return a cache-busting asset version without exposing filesystem paths. */
function kuka_island_child_asset_version( string $relative_path ): string {
	$absolute_path = get_stylesheet_directory() . '/' . ltrim( $relative_path, '/' );
	return is_file( $absolute_path ) ? (string) filemtime( $absolute_path ) : '0.1.0';
}

/** Enqueue one child stylesheet. */
function kuka_island_enqueue_style( string $name, array $dependencies ): void {
	$relative = 'assets/css/' . $name . '.css';
	wp_enqueue_style( 'kuka-island-' . $name, get_stylesheet_directory_uri() . '/' . $relative, $dependencies, kuka_island_child_asset_version( $relative ) );
}

/** Enqueue one child script. */
function kuka_island_enqueue_script( string $name, array $dependencies = array() ): void {
	$relative = 'assets/js/' . $name . '.js';
	wp_enqueue_script( 'kuka-island-' . $name, get_stylesheet_directory_uri() . '/' . $relative, $dependencies, kuka_island_child_asset_version( $relative ), true );
}

/**
 * Invalidate cached cart fragments when the panel markup changes.
 *
 * WooCommerce keeps the rendered cart panel in sessionStorage under
 * `fragment_name` and only refreshes it when the cart hash changes. A theme
 * update therefore kept showing the previous markup to returning visitors;
 * binding the key to the panel and script mtimes retires the stale copy.
 *
 * @param mixed  $params Localized script data.
 * @param string $handle Script handle.
 * @return mixed
 */
function kuka_island_cart_fragment_name( $params, string $handle ) {
	if ( 'wc-cart-fragments' !== $handle || ! is_array( $params ) ) {
		return $params;
	}
	$language                = function_exists( 'kuka_island_locale' ) ? kuka_island_locale() : 'tr';
	$signature               = kuka_island_child_asset_version( 'inc/storefront-panels.php' ) . '-' . kuka_island_child_asset_version( 'assets/js/cart.js' ) . '-' . $language;
	$params['fragment_name'] = 'wc_fragments_kuka_' . substr( md5( $signature ), 0, 12 );
	if ( 'en' === $language ) {
		$params['wc_ajax_url'] = (string) ( $params['wc_ajax_url'] ?? '' ) . '&kuka_lang=en';
	}
	return $params;
}
add_filter( 'woocommerce_get_script_data', 'kuka_island_cart_fragment_name', 10, 2 );

/**
 * "Çok yakında" ekranının varlıkları.
 *
 * WooCommerce kendi blok desenini ve stilini bu ekrana yükler; şablonu
 * devraldığımız için o stil kullanılmaz, çıkarılır. Splash header/footer
 * kullanmadığından mağaza stillerine de ihtiyaç duymaz.
 */
function kuka_island_coming_soon_assets(): void {
	foreach ( array( 'catalog', 'product', 'cart', 'checkout', 'content' ) as $unused ) {
		wp_dequeue_style( 'kuka-island-' . $unused );
	}
	wp_dequeue_style( 'woocommerce-coming-soon' );
	kuka_island_enqueue_style( 'coming-soon', array( 'kuka-island-global' ) );
}

/** Load route-specific presentation assets and product runtime data. */
function kuka_island_child_enqueue_assets(): void {
	kuka_island_enqueue_style( 'tokens', array( 'ct-main-styles' ) );
	kuka_island_enqueue_style( 'global', array( 'kuka-island-tokens' ) );
	if ( is_front_page() || is_shop() || is_product_taxonomy() ) {
		kuka_island_enqueue_style( 'catalog', array( 'kuka-island-global' ) );
	}
	if ( is_product() ) {
		kuka_island_enqueue_style( 'product', array( 'kuka-island-global' ) );
	}
	kuka_island_enqueue_style( 'cart', array( 'kuka-island-global' ) );
	if ( is_checkout() ) {
		kuka_island_enqueue_style( 'checkout', array( 'kuka-island-cart' ) );
	}
	if ( is_page() ) {
		kuka_island_enqueue_style( 'content', array( 'kuka-island-global' ) );
	}

	kuka_island_enqueue_script( 'storefront' );
	if ( is_front_page() || is_shop() || is_product_taxonomy() ) {
		kuka_island_enqueue_script( 'catalog', array( 'kuka-island-storefront' ) );
	}
	kuka_island_enqueue_script( 'cart', array( 'kuka-island-storefront', 'jquery', 'wc-add-to-cart', 'wc-cart-fragments' ) );
	if ( ! is_product() ) { return; }
	kuka_island_enqueue_script( 'product', array( 'kuka-island-storefront', 'jquery', 'wc-add-to-cart-variation' ) );

	$availability = array();
	$colors       = array();
	$product      = wc_get_product( get_queried_object_id() );
	if ( $product instanceof WC_Product_Variable ) {
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) { continue; }
			$attributes  = $variation->get_attributes();
			$gallery_ids = array_values( array_unique( array_map( 'intval', array_filter( array_merge( array( $variation->get_image_id() ), (array) $variation->get_meta( '_kuka_variation_gallery_ids' ) ) ) ) ) );
			$availability[] = array(
				'color'     => $attributes['pa_renk'] ?? '',
				'size'      => $attributes['pa_beden'] ?? '',
				'available' => $variation->is_in_stock() && $variation->is_purchasable(),
				'stock'     => $variation->get_stock_quantity(),
				'id'        => $variation->get_id(),
				'gallery'   => array_map(
					static fn( int $id ): array => array( 'src' => wp_get_attachment_image_url( $id, 'large' ), 'full' => wp_get_attachment_image_url( $id, 'full' ), 'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
					$gallery_ids
				),
			);
		}
	}
	$color_terms = taxonomy_exists( 'pa_renk' ) ? get_terms( array( 'taxonomy' => 'pa_renk', 'hide_empty' => false ) ) : array();
	if ( is_array( $color_terms ) ) {
		foreach ( $color_terms as $color_term ) {
			if ( ! $color_term instanceof WP_Term ) { continue; }
			$colors[] = array( 'slug' => $color_term->slug, 'name' => $color_term->name, 'hex' => sanitize_hex_color( get_term_meta( $color_term->term_id, 'kuka_swatch_hex', true ) ) ?: '' );
		}
	}
	wp_localize_script(
		'kuka-island-product',
		'kukaIslandProduct',
		array(
			'soldOut'           => __( 'Tükendi', 'kuka-island' ),
			'galleryFullscreen' => __( '%1$s; tam ekran aç (%2$d/%3$d)', 'kuka-island' ),
			'colorSelection'    => __( 'Renk seçimi', 'kuka-island' ),
			'selectColor'       => __( '%s rengini seç', 'kuka-island' ),
			'sizeSelection'     => __( 'Beden seçimi', 'kuka-island' ),
			'sizeSoldOut'       => __( '%s beden tükendi', 'kuka-island' ),
			'sizeInStock'       => __( '%s beden stokta', 'kuka-island' ),
			'availability'      => $availability,
			'colors'            => $colors,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'kuka_island_child_enqueue_assets', 20 );
