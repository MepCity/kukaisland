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
		register_nav_menus(
			array(
				'primary' => __( 'Ana mağaza menüsü', 'kuka-island' ),
				'footer_categories' => __( 'Footer kategoriler', 'kuka-island' ),
				'footer_legal' => __( 'Footer yasal', 'kuka-island' ),
			)
		);
	}
);

/**
 * Let the child theme's WooCommerce gallery override render unchanged.
 * Blocksy otherwise replaces the template before WooCommerce can load it.
 */
add_filter( 'blocksy:woocommerce:product-view:use-default', '__return_true' );

/**
 * Translate the parent theme's first breadcrumb without altering the vendor.
 *
 * @param array<int, array<string, string>> $items Breadcrumb items.
 * @return array<int, array<string, string>>
 */
function kuka_island_breadcrumb_items( $items ) {
	if ( isset( $items[0]['name'] ) ) {
		$items[0]['name'] = __( 'Ana sayfa', 'kuka-island' );
	}

	return $items;
}
add_filter( 'blocksy:breadcrumbs:items-array', 'kuka_island_breadcrumb_items' );

/**
 * Fill the small Turkish gaps left by Blocksy's unavailable language pack
 * and a newer WooCommerce privacy string.
 */
function kuka_island_translation_gaps( string $translation, string $text, string $domain ): string {
	$maps = array(
		'blocksy' => array(
			'Product' => 'Ürün',
			'Price' => 'Fiyat',
			'Quantity' => 'Adet',
			'Subtotal' => 'Ara toplam',
			'Remove item' => 'Ürünü çıkar',
			'Remove product' => 'Ürünü çıkar',
			'Remove %s from cart' => '%s ürününü sepetten çıkar',
			'Coupon:' => 'Kupon:',
			'Coupon code' => 'Kupon kodu',
			'Apply coupon' => 'Kuponu uygula',
			'Update cart' => 'Sepeti güncelle',
			'Available on backorder' => 'Ön siparişle temin edilebilir',
		),
		'woocommerce' => array(
			'Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our %s.' => 'Kişisel verileriniz siparişinizi işlemek, site deneyiminizi desteklemek ve %s sayfamızda açıklanan diğer amaçlar için kullanılacaktır.',
		),
	);

	return $maps[ $domain ][ $text ] ?? $translation;
}
add_filter( 'gettext', 'kuka_island_translation_gaps', 10, 3 );

add_action(
	'after_setup_theme',
	static function (): void {
		remove_theme_support( 'wc-product-gallery-slider' );
		remove_theme_support( 'wc-product-gallery-zoom' );
		remove_theme_support( 'wc-product-gallery-lightbox' );
	},
	100
);

/** Render one of the five source-matched inline SVG icons. */
function kuka_island_icon( string $name ): string {
	$paths = array(
		'search' => '<circle cx="11" cy="11" r="6.5" stroke="currentColor"/><path d="m16 16 4.5 4.5" stroke="currentColor"/>',
		'account' => '<circle cx="12" cy="8" r="3.5" stroke="currentColor"/><path d="M5.5 20c.6-4 2.8-6 6.5-6s5.9 2 6.5 6" stroke="currentColor"/>',
		'bag' => '<path d="M5 8.5h14l-1 12H6l-1-12Z" stroke="currentColor"/><path d="M9 9V6.5a3 3 0 0 1 6 0V9" stroke="currentColor"/>',
		'menu' => '<path d="M3 7h18M3 17h18" stroke="currentColor"/>',
		'close' => '<path d="m4 4 16 16M20 4 4 20" stroke="currentColor"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) { return ''; }
	return '<svg class="kuka-icon kuka-icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

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
		if ( 'cart' === $name ) {
			$dependencies = array_merge( $dependencies, array( 'jquery', 'wc-add-to-cart', 'wc-cart-fragments' ) );
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
					$gallery_ids = array_values( array_unique( array_map( 'intval', array_filter( array_merge( array( $variation->get_image_id() ), (array) $variation->get_meta( '_kuka_variation_gallery_ids' ) ) ) ) ) );
					$availability[] = array(
						'color'       => $attributes['pa_renk'] ?? '',
						'size'        => $attributes['pa_beden'] ?? '',
						'available'   => $variation->is_in_stock() && $variation->is_purchasable(),
						'stock'       => $variation->get_stock_quantity(),
						'id'          => $variation->get_id(),
						'gallery'     => array_map(
							static fn( int $id ): array => array(
								'src'  => wp_get_attachment_image_url( $id, 'large' ),
								'full' => wp_get_attachment_image_url( $id, 'full' ),
								'alt'  => get_post_meta( $id, '_wp_attachment_image_alt', true ),
							),
							$gallery_ids
						),
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
			'colors'       => array_map(
				static fn( WP_Term $term ): array => array( 'slug' => $term->slug, 'name' => $term->name, 'hex' => get_term_meta( $term->term_id, 'kuka_swatch_hex', true ) ),
				get_terms( array( 'taxonomy' => 'pa_renk', 'hide_empty' => false ) )
			),
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

add_filter(
	'woocommerce_breadcrumb_defaults',
	static function ( array $defaults ): array {
		$defaults['home'] = __( 'Ana sayfa', 'kuka-island' );
		return $defaults;
	}
);

require_once get_stylesheet_directory() . '/inc/catalog-filters.php';
require_once get_stylesheet_directory() . '/inc/storefront-panels.php';

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
