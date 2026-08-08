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
function kuka_island_emblem_markup(): string {
	static $markup = null;
	if ( null !== $markup ) { return $markup; }
	$emblem_id = absint( kuka_island_content()['brand']['emblem_id'] ?? 0 );
	if ( $emblem_id ) {
		$markup = (string) wp_get_attachment_image( $emblem_id, 'full', false, array( 'class' => 'kuka-logo__emblem', 'alt' => '', 'aria-hidden' => 'true' ) );
		return $markup;
	}
	$path   = get_stylesheet_directory() . '/assets/img/palmiye.svg';
	$markup = file_exists( $path )
		? (string) preg_replace( '/<svg /', '<svg class="kuka-logo__emblem" ', file_get_contents( $path ), 1 ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		: '';
	return $markup;
}

/** @return array<string, mixed> */
function kuka_island_content(): array {
	if ( class_exists( 'Kuka_Island_Core_Site_Appearance' ) ) {
		return Kuka_Island_Core_Site_Appearance::get();
	}
	return array();
}

/** Output panel-controlled brand metadata without exposing layout controls. */
function kuka_island_brand_metadata(): void {
	$brand = kuka_island_content()['brand'] ?? array();
	if ( ! empty( $brand['social_share_image_id'] ) ) {
		$url = wp_get_attachment_image_url( absint( $brand['social_share_image_id'] ), 'full' );
		if ( $url ) { echo '<meta property="og:image" content="' . esc_url( $url ) . '">'; }
	}
	if ( ! empty( $brand['favicon_id'] ) ) {
		$url = wp_get_attachment_image_url( absint( $brand['favicon_id'] ), 'full' );
		if ( $url ) { echo '<link rel="icon" href="' . esc_url( $url ) . '">'; }
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
	return str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ? home_url( $url ) : $url;
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
	return kuka_island_menu_lines( (string) ( kuka_island_content()['languages']['items'] ?? '' ) );
}

/**
 * Whether a language entry points at a translation that is not published yet.
 * Listed URLs render as a disabled row instead of a link, so the selector can
 * be visible before the second language exists without producing a 404.
 */
function kuka_island_language_is_pending( string $url ): bool {
	$pending = array_filter( array_map( 'trim', explode( ',', (string) ( kuka_island_content()['languages']['pending_urls'] ?? '' ) ) ) );
	$needle  = trailingslashit( trim( $url ) );
	foreach ( $pending as $candidate ) {
		if ( trailingslashit( $candidate ) === $needle ) { return true; }
	}
	return false;
}

function kuka_island_language_pending_note(): string {
	$note = trim( (string) ( kuka_island_content()['languages']['pending_note'] ?? '' ) );
	return '' === $note ? __( 'Yakında', 'kuka-island' ) : $note;
}

require_once get_stylesheet_directory() . '/inc/assets.php';
require_once get_stylesheet_directory() . '/inc/catalog-filters.php';
require_once get_stylesheet_directory() . '/inc/checkout.php';
require_once get_stylesheet_directory() . '/inc/seo.php';
require_once get_stylesheet_directory() . '/inc/storefront-panels.php';
require_once get_stylesheet_directory() . '/inc/woocommerce.php';
