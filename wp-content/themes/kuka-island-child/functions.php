<?php
/**
 * Kuka Island child theme bootstrap.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'after_setup_theme',
	static function (): void {
		load_child_theme_textdomain( 'kuka-island', get_stylesheet_directory() . '/languages' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'woocommerce' );
		add_theme_support( 'wc-product-gallery-zoom' );
		add_theme_support( 'wc-product-gallery-lightbox' );
		add_theme_support( 'wc-product-gallery-slider' );
	}
);

/** @return array<string, mixed> */
function kuka_island_content(): array {
	if ( class_exists( 'Kuka_Island_Core_Site_Appearance' ) ) {
		return Kuka_Island_Core_Site_Appearance::get();
	}
	return array();
}

/** @return array<int, array{label:string,url:string}> */
function kuka_island_menu_lines( string $lines ): array {
	$items = array();
	foreach ( preg_split( '/\R/', $lines ) ?: array() as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( 2 === count( $parts ) && $parts[0] && $parts[1] ) {
			$items[] = array( 'label' => $parts[0], 'url' => $parts[1] );
		}
	}
	return $items;
}

function kuka_island_content_url( string $url ): string {
	return str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ? home_url( $url ) : $url;
}

/**
 * Return a cache-busting asset version without leaking filesystem paths.
 */
function kuka_island_child_asset_version( string $relative_path ): string {
	$absolute_path = get_stylesheet_directory() . '/' . ltrim( $relative_path, '/' );

	return is_file( $absolute_path ) ? (string) filemtime( $absolute_path ) : '0.1.0';
}

/**
 * Load presentation assets in dependency order.
 */
function kuka_island_child_enqueue_assets(): void {
	$styles = array( 'tokens', 'global', 'catalog', 'product', 'cart', 'checkout', 'content' );
	$previous = 'ct-main-styles';

	foreach ( $styles as $style ) {
		$handle   = 'kuka-island-' . $style;
		$relative = 'assets/css/' . $style . '.css';
		wp_enqueue_style(
			$handle,
			get_stylesheet_directory_uri() . '/' . $relative,
			array( $previous ),
			kuka_island_child_asset_version( $relative )
		);
		$previous = $handle;
	}

	$scripts  = array( 'storefront', 'catalog', 'product', 'cart' );
	$previous = '';
	foreach ( $scripts as $name ) {
		$handle       = 'kuka-island-' . $name;
		$script       = 'assets/js/' . $name . '.js';
		$dependencies = $previous ? array( $previous ) : array();
		if ( 'product' === $name ) {
			$dependencies = array_merge( $dependencies, array( 'jquery', 'wc-add-to-cart-variation' ) );
		}
		wp_enqueue_script(
			$handle,
			get_stylesheet_directory_uri() . '/' . $script,
			$dependencies,
			kuka_island_child_asset_version( $script ),
			true
		);
		$previous = $handle;
	}
	$availability = array();
	if ( is_product() ) {
		$product = wc_get_product( get_queried_object_id() );
		if ( $product instanceof WC_Product_Variable ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation instanceof WC_Product_Variation ) {
					$attributes = $variation->get_attributes();
					$availability[] = array(
						'color'       => $attributes['pa_renk'] ?? '',
						'size'        => $attributes['pa_beden'] ?? '',
						'available'   => $variation->is_in_stock() && $variation->is_purchasable(),
						'stock'       => $variation->get_stock_quantity(),
					);
				}
			}
		}
	}
	wp_localize_script(
		'kuka-island-product',
		'kukaIslandProduct',
		array(
			/* translators: %d is the remaining stock quantity. */
			'lowStock' => __( 'Son %d adet', 'kuka-island' ),
			'soldOut'  => __( 'Tükendi', 'kuka-island' ),
			'availability' => $availability,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'kuka_island_child_enqueue_assets', 20 );

/**
 * Product detail gallery thumbnails follow the measured 3:4 source ratio.
 */
function kuka_island_child_gallery_thumbnail_size(): array {
	return array(
		'width'  => 180,
		'height' => 240,
		'crop'   => 1,
	);
}
add_filter( 'woocommerce_get_image_size_gallery_thumbnail', 'kuka_island_child_gallery_thumbnail_size' );

add_filter( 'loop_shop_per_page', static fn(): int => 12, 20 );
add_filter( 'loop_shop_columns', static fn(): int => 4, 20 );

add_action(
	'wp_head',
	static function (): void {
		if ( is_shop() || is_product_taxonomy() ) {
			echo '<link rel="canonical" href="' . esc_url( get_pagenum_link( max( 1, get_query_var( 'paged' ) ) ) ) . '">' . "\n";
		}
	},
	1
);

add_action(
	'woocommerce_before_shop_loop_item_title',
	static function (): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$terms = wc_get_product_terms( $product->get_id(), 'pa_kesim', array( 'fields' => 'names' ) );
		if ( $terms ) {
			echo '<p class="kuka-card__cut">' . esc_html( implode( ', ', $terms ) ) . '</p>';
		}
	},
	15
);

add_action(
	'woocommerce_single_product_summary',
	static function (): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		foreach ( array( '_kuka_material' => __( 'Malzeme', 'kuka-island' ), '_kuka_care' => __( 'Bakım', 'kuka-island' ), '_kuka_fit' => __( 'Kalıp', 'kuka-island' ), '_kuka_model_info' => __( 'Model', 'kuka-island' ) ) as $key => $label ) {
			$value = $product->get_meta( $key );
			if ( $value ) {
				echo '<details class="kuka-product-detail"><summary>' . esc_html( $label ) . '</summary><p>' . esc_html( $value ) . '</p></details>';
			}
		}
		$guide = $product->get_meta( '_kuka_size_guide' );
		if ( $guide ) {
			echo '<a class="kuka-size-link" href="' . esc_url( home_url( '/' . trim( $guide, '/' ) . '/' ) ) . '">' . esc_html__( 'Beden rehberini aç', 'kuka-island' ) . '</a>';
		}
	},
	35
);

add_action(
	'woocommerce_after_single_product_summary',
	static function (): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$paired = wc_get_product( absint( $product->get_meta( '_kuka_paired_product_id' ) ) );
		if ( $paired ) {
			echo '<section class="kuka-pair"><div><p class="kuka-eyebrow">' . esc_html__( 'Birlikte iyi gider', 'kuka-island' ) . '</p><h2>' . esc_html( $paired->get_name() ) . '</h2><p>' . wp_kses_post( $paired->get_price_html() ) . '</p><a class="kuka-button" href="' . esc_url( $paired->get_permalink() ) . '">' . esc_html__( 'Parçayı incele', 'kuka-island' ) . '</a></div>' . $paired->get_image( 'woocommerce_single' ) . '</section>';
		}
	},
	5
);

add_filter( 'woocommerce_structured_data_product', static function ( array $markup ): array {
	unset( $markup['review'], $markup['aggregateRating'] );
	return $markup;
} );

add_filter(
	'woocommerce_product_tabs',
	static function ( array $tabs ): array {
		unset( $tabs['reviews'] );
		return $tabs;
	},
	30
);
add_filter( 'comments_open', static fn( bool $open, int $post_id ): bool => 'product' === get_post_type( $post_id ) ? false : $open, 20, 2 );
