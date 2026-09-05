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
	if ( ! in_array( $domain, array( 'blocksy', 'woocommerce' ), true ) ) { return $translation; }
	if ( function_exists( 'kuka_island_is_english' ) && kuka_island_is_english() ) { return $translation; }
	static $maps = null;
	$maps ??= array(
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

/**
 * Serve the child theme's "çok yakında" screen instead of WooCommerce's pattern.
 *
 * WooCommerce resolves the screen with get_query_template( 'coming-soon' ) and
 * registers a block template under the same slug, which wins over the theme
 * file. Forcing the path here keeps the launch screen in the child theme where
 * it stays token-driven and version controlled.
 *
 * @param string $template Previously resolved template path.
 * @return string
 */
function kuka_island_coming_soon_template( $template ) {
	$override = get_stylesheet_directory() . '/coming-soon.php';
	return file_exists( $override ) ? $override : $template;
}
add_filter( 'coming-soon_template', 'kuka_island_coming_soon_template', 20 );

/** Render one of the five source-matched inline SVG icons. */
function kuka_island_icon( string $name ): string {
	$paths = array(
		'search' => '<circle cx="11" cy="11" r="6.5" stroke="currentColor"/><path d="m16 16 4.5 4.5" stroke="currentColor"/>',
		'bag' => '<path d="M5 8.5h14l-1 12H6l-1-12Z" stroke="currentColor"/><path d="M9 9V6.5a3 3 0 0 1 6 0V9" stroke="currentColor"/>',
		'menu' => '<path d="M3 7h18M3 17h18" stroke="currentColor"/>',
		'close' => '<path d="m4 4 16 16M20 4 4 20" stroke="currentColor"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) { return ''; }
	return '<svg class="kuka-icon kuka-icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

/**
 * Palm emblem markup shared by the header lockup and the footer brand lock.
 *
 * `<img src="...svg">` cannot inherit the page colour (isolated render
 * context), so the theme asset is inlined; the panel emblem may be a raster
 * upload, which is the expected limit. Both are static/theme-owned, so no
 * sanitisation is required.
 */
function kuka_island_default_emblem_svg(): string {
	static $svg = null;
	if ( null !== $svg ) { return $svg; }
	$path    = get_stylesheet_directory() . '/assets/img/palmiye.svg';
	$version = is_file( $path ) ? (string) filemtime( $path ) : '';
	$cached  = get_option( 'kuka_island_default_emblem_svg', array() );
	if ( is_array( $cached ) && $version === (string) ( $cached['version'] ?? '' ) ) {
		return $svg = (string) ( $cached['svg'] ?? '' );
	}
	$svg = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	update_option( 'kuka_island_default_emblem_svg', array( 'version' => $version, 'svg' => $svg ), true );
	return $svg;
}

function kuka_island_emblem_markup(): string {
	static $markup = null;
	if ( null !== $markup ) { return $markup; }
	$emblem_id = absint( kuka_island_content()['brand']['emblem_id'] ?? 0 );
	if ( $emblem_id ) {
		$markup = (string) wp_get_attachment_image( $emblem_id, 'full', false, array( 'class' => 'kuka-logo__emblem', 'alt' => '', 'aria-hidden' => 'true' ) );
		return $markup;
	}
	$markup = (string) preg_replace( '/<svg /', '<svg class="kuka-logo__emblem" ', kuka_island_default_emblem_svg(), 1 );
	return $markup;
}

/** @return array<string, mixed> */
function kuka_island_content(): array {
	static $cache = array();
	if ( class_exists( 'Kuka_Island_Core_Site_Appearance' ) ) {
		$locale = function_exists( 'kuka_island_locale' ) ? kuka_island_locale() : 'tr';
		if ( isset( $cache[ $locale ] ) ) {
			return $cache[ $locale ];
		}
		$content = Kuka_Island_Core_Site_Appearance::get();
		$cache[ $locale ] = class_exists( 'Kuka_Island_Core_Language' ) ? Kuka_Island_Core_Language::localized_content( $content ) : $content;
		return $cache[ $locale ];
	}
	return array();
}

/** Output panel-controlled brand metadata without exposing layout controls. */
function kuka_island_brand_metadata(): void {
	$brand = kuka_island_content()['brand'] ?? array();
	// og:image ve diğer paylaşım kartı etiketleri inc/seo.php içinde tek yerden
	// basılır; ürün sayfasında ürün görseli, diğer sayfalarda paneldeki sosyal
	// paylaşım görseli kullanılır.
	if ( ! empty( $brand['favicon_id'] ) ) {
		$url = wp_get_attachment_image_url( absint( $brand['favicon_id'] ), 'full' );
		if ( $url ) { echo '<link rel="icon" href="' . esc_url( $url ) . '">'; }
	} else {
		$icon = kuka_island_default_emblem_svg();
		if ( '' !== $icon ) {
			$light_icon = 'data:image/svg+xml,' . rawurlencode( str_replace( 'currentColor', '#3c2a12', $icon ) );
			$dark_icon  = 'data:image/svg+xml,' . rawurlencode( str_replace( 'currentColor', '#fffdf8', $icon ) );
			echo '<link rel="icon" media="(prefers-color-scheme: light)" href="' . esc_attr( $light_icon ) . '">';
			echo '<link rel="icon" media="(prefers-color-scheme: dark)" href="' . esc_attr( $dark_icon ) . '">';
		}
	}
}
add_action( 'wp_head', 'kuka_island_brand_metadata', 3 );

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

/** @return array<int, array{label:string,url:string,header:bool,home:bool}> */
function kuka_island_category_navigation(): array {
	$content = kuka_island_content();
	$lines   = (string) ( $content['navigation']['categories'] ?? '' );
	if ( class_exists( 'Kuka_Island_Core_Site_Appearance' ) ) {
		return Kuka_Island_Core_Site_Appearance::parse_category_navigation( $lines );
	}
	return array();
}

/** Cache the optional home category index's aggregate cut labels. */
function kuka_island_category_cut_names( int $term_id ): array {
	$version = (int) get_option( 'kuka_category_index_cache_version', 1 );
	$key     = 'kuka_category_cuts_' . $term_id . '_' . kuka_island_locale() . '_' . $version;
	$cached  = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$product_ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term_id ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	$cut_terms   = $product_ids ? wp_get_object_terms( $product_ids, 'pa_kesim', array( 'fields' => 'all' ) ) : array();
	$cut_names   = is_wp_error( $cut_terms ) ? array() : array_map( 'kuka_island_term_name', $cut_terms );
	$cut_names   = array_values( array_unique( $cut_names ) );
	set_transient( $key, $cut_names, 12 * HOUR_IN_SECONDS );
	return $cut_names;
}

/** Invalidate aggregate labels after product or taxonomy edits without scanning old keys. */
function kuka_island_invalidate_category_index_cache(): void {
	update_option( 'kuka_category_index_cache_version', time(), true );
}
add_action( 'save_post_product', 'kuka_island_invalidate_category_index_cache' );
add_action( 'edited_product_cat', 'kuka_island_invalidate_category_index_cache' );
add_action( 'edited_pa_kesim', 'kuka_island_invalidate_category_index_cache' );

/** Keep fixed brand links and category visibility in one panel-owned menu. */
function kuka_island_header_menu(): array {
	$fixed      = kuka_island_menu_lines( (string) ( kuka_island_content()['navigation']['main'] ?? '' ) );
	$categories = array_values(
		array_map(
			static fn( array $item ): array => array( 'label' => $item['label'], 'url' => $item['url'] ),
			array_filter( kuka_island_category_navigation(), static fn( array $item ): bool => $item['header'] )
		)
	);
	$first = $fixed ? array_shift( $fixed ) : null;
	return array_merge( $first ? array( $first ) : array(), $categories, $fixed );
}

function kuka_island_content_url( string $url ): string {
	if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
		$base = untrailingslashit( (string) get_option( 'home' ) );
		$url  = $base . '/' . ltrim( $url, '/' );
	}
	return class_exists( 'Kuka_Island_Core_Language' ) ? Kuka_Island_Core_Language::url_for_language( $url, kuka_island_locale() ) : $url;
}

/**
 * Resolve the panel WhatsApp phone field to a wa.me link, or '' when unset.
 * The link is generated so the operator only enters a phone number.
 */
function kuka_island_whatsapp_url(): string {
	$phone = (string) ( kuka_island_content()['brand']['whatsapp_phone'] ?? '' );
	return class_exists( 'Kuka_Island_Core_Content' ) ? Kuka_Island_Core_Content::whatsapp_url( $phone ) : '';
}

/**
 * Parse the panel language list. The selector only renders when more than one
 * language is defined; a single line keeps it hidden.
 *
 * @return array<int, array{label:string,url:string}>
 */
function kuka_island_languages(): array {
	if ( ! class_exists( 'Kuka_Island_Core_Site_Appearance' ) || ! class_exists( 'Kuka_Island_Core_Language' ) ) {
		return array();
	}
	$rows = kuka_island_menu_lines( (string) ( Kuka_Island_Core_Site_Appearance::get()['languages']['items'] ?? '' ) );
	return array_values( array_map( static function ( array $row ): array {
		$path = '/' . ltrim( (string) wp_parse_url( $row['url'], PHP_URL_PATH ), '/' );
		$code = preg_match( '#^/en(?:/|$)#', $path ) ? 'en' : 'tr';
		return array( 'label' => $row['label'], 'url' => Kuka_Island_Core_Language::current_url( $code ), 'code' => $code );
	}, $rows ) );
}

require_once get_stylesheet_directory() . '/inc/assets.php';
require_once get_stylesheet_directory() . '/inc/catalog-filters.php';
require_once get_stylesheet_directory() . '/inc/checkout.php';
require_once get_stylesheet_directory() . '/inc/seo.php';
require_once get_stylesheet_directory() . '/inc/storefront-panels.php';
require_once get_stylesheet_directory() . '/inc/woocommerce.php';
