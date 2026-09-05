<?php
/**
 * Storefront SEO layer: meta description, Open Graph / Twitter cards, the
 * Organization JSON-LD and the hero image preload.
 *
 * Canonical, hreflang and og:locale are emitted centrally by Kuka Island Core
 * (Kuka_Island_Core_Language::language_metadata). Everything here reads the
 * same panel, product, page and term data; no description is ever invented,
 * an empty field simply prints no tag.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

remove_action( 'wp_head', 'rel_canonical' );

/** Resolve one localized product SEO meta with the Turkish source as fallback. */
function kuka_island_product_seo_meta( WC_Product $product, string $key ): string {
	$english = function_exists( 'kuka_island_is_english' ) && kuka_island_is_english();
	$value   = $english ? (string) $product->get_meta( $key . '_en' ) : '';
	if ( '' === trim( $value ) ) {
		$value = (string) $product->get_meta( $key );
	}
	return trim( $value );
}

/** One-line, tag-free, whitespace-normalized text for a meta attribute. */
function kuka_island_seo_text( string $value, int $limit = 320 ): string {
	$value = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $value ) ) ) );
	if ( '' === $value ) {
		return '';
	}
	return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
}

/**
 * The meta description of the current request, or '' when the operator left
 * the relevant field empty.
 *
 * Sources, in order: product meta (product editor), the panel's SEO group for
 * the front page and the shop page, the WooCommerce category description for
 * category archives, and the page editor's meta description for pages. The
 * panel content is already localized, so the English fallback to Turkish is
 * the same one every other panel field uses.
 */
function kuka_island_meta_description(): string {
	$english = function_exists( 'kuka_island_is_english' ) && kuka_island_is_english();
	if ( is_singular( 'product' ) ) {
		$product = wc_get_product( get_queried_object_id() );
		return $product instanceof WC_Product ? kuka_island_seo_text( kuka_island_product_seo_meta( $product, '_kuka_meta_description' ) ) : '';
	}
	$seo = kuka_island_content()['seo'] ?? array();
	if ( is_front_page() ) {
		return kuka_island_seo_text( (string) ( $seo['home_meta_description'] ?? '' ) );
	}
	if ( function_exists( 'is_shop' ) && is_shop() ) {
		return kuka_island_seo_text( (string) ( $seo['shop_meta_description'] ?? '' ) );
	}
	if ( is_product_taxonomy() ) {
		$term = get_queried_object();
		return $term instanceof WP_Term ? kuka_island_seo_text( (string) $term->description ) : '';
	}
	if ( is_page() ) {
		$post_id = get_queried_object_id();
		$value   = $english ? (string) get_post_meta( $post_id, '_kuka_meta_description_en', true ) : '';
		if ( '' === trim( $value ) ) {
			$value = (string) get_post_meta( $post_id, '_kuka_meta_description', true );
		}
		return kuka_island_seo_text( $value );
	}
	return '';
}

/** The image a shared link carries: the product image, else the panel's social share image. */
function kuka_island_social_image_url(): string {
	if ( is_singular( 'product' ) ) {
		$product = wc_get_product( get_queried_object_id() );
		if ( $product instanceof WC_Product && $product->get_image_id() ) {
			$url = wp_get_attachment_image_url( (int) $product->get_image_id(), 'full' );
			if ( $url ) {
				return (string) $url;
			}
		}
	}
	$image_id = absint( kuka_island_content()['brand']['social_share_image_id'] ?? 0 );
	$url      = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
	return $url ? (string) $url : '';
}

/** Absolute URL of the current request in the active storefront language. */
function kuka_island_seo_current_url(): string {
	$language = function_exists( 'kuka_island_locale' ) ? kuka_island_locale() : 'tr';
	if ( class_exists( 'Kuka_Island_Core_Language' ) ) {
		return Kuka_Island_Core_Language::current_url( $language );
	}
	global $wp;
	return home_url( '/' . ltrim( (string) ( $wp->request ?? '' ), '/' ) );
}

add_filter(
	'document_title_parts',
	static function ( array $parts ): array {
		if ( ! is_singular( 'product' ) ) {
			return $parts;
		}
		$product = wc_get_product( get_queried_object_id() );
		$title   = $product instanceof WC_Product ? kuka_island_product_seo_meta( $product, '_kuka_seo_title' ) : '';
		if ( '' !== $title ) {
			$parts['title'] = $title;
		}
		return $parts;
	}
);

/** Meta description plus Open Graph and Twitter card tags for every storefront response. */
function kuka_island_seo_head(): void {
	if ( is_admin() || is_feed() || is_404() || is_search() ) {
		return;
	}
	$description = kuka_island_meta_description();
	$title       = wp_get_document_title();
	$url         = kuka_island_seo_current_url();
	$image       = kuka_island_social_image_url();

	if ( '' !== $description ) {
		echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
	}
	echo '<meta property="og:type" content="' . ( is_singular( 'product' ) ? 'product' : 'website' ) . '">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( '' !== $description ) {
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	}
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	if ( '' !== $image ) {
		echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
	}
	echo '<meta name="twitter:card" content="' . ( '' !== $image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( '' !== $description ) {
		echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
	}
	if ( '' !== $image ) {
		echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'kuka_island_seo_head', 2 );

/** E.164 form of a panel phone number, Türkiye default; '' when there are no digits. */
function kuka_island_seo_phone( string $phone ): string {
	$digits = (string) preg_replace( '/\D+/', '', $phone );
	if ( '' === $digits ) {
		return '';
	}
	if ( str_starts_with( $digits, '90' ) && strlen( $digits ) === 12 ) {
		return '+' . $digits;
	}
	if ( str_starts_with( $digits, '0' ) ) {
		$digits = substr( $digits, 1 );
	}
	return '+90' . $digits;
}

/**
 * Organization JSON-LD on the front page only, built from panel data.
 *
 * WooCommerce prints Product and BreadcrumbList structured data on its own
 * pages; the store itself had no entity. Only the front page carries it so the
 * same organization is not declared on every response.
 */
function kuka_island_organization_schema(): void {
	if ( ! is_front_page() ) {
		return;
	}
	$content = kuka_island_content();
	$brand   = $content['brand'] ?? array();
	$legal   = $content['legal'] ?? array();
	$name    = trim( (string) ( $legal['brand_name'] ?? '' ) );
	$schema  = array(
		'@context' => 'https://schema.org',
		'@type'    => 'OnlineStore',
		'name'     => '' !== $name ? $name : get_bloginfo( 'name' ),
		'url'      => home_url( '/' ),
	);
	$logo_id = absint( $brand['logo_id'] ?? 0 );
	$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
	if ( $logo ) {
		$schema['logo'] = $logo;
	}
	$same_as = array();
	foreach ( kuka_island_menu_lines( (string) ( $brand['social_links'] ?? '' ) ) as $link ) {
		if ( preg_match( '#^https://#i', $link['url'] ) ) {
			$same_as[] = $link['url'];
		}
	}
	if ( $same_as ) {
		$schema['sameAs'] = $same_as;
	}
	$email = sanitize_email( (string) ( $brand['email'] ?? '' ) );
	if ( is_email( $email ) ) {
		$schema['email'] = $email;
	}
	$phone = kuka_island_seo_phone( (string) ( $brand['phone'] ?? '' ) );
	if ( '' !== $phone ) {
		$schema['telephone']    = $phone;
		$schema['contactPoint'] = array(
			'@type'             => 'ContactPoint',
			'telephone'         => $phone,
			'contactType'       => 'customer service',
			'availableLanguage' => array( 'Turkish', 'English' ),
		);
	}
	$address = kuka_island_seo_text( (string) ( $legal['address_full'] ?? '' ) );
	if ( '' !== $address ) {
		$schema['address'] = array(
			'@type'          => 'PostalAddress',
			'streetAddress'  => $address,
			'addressCountry' => 'TR',
		);
	}
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'kuka_island_organization_schema', 4 );

/**
 * Preload the hero photograph on the front page.
 *
 * The hero image is a CSS background set through a style attribute, so the
 * browser only discovers it after the stylesheet is applied. Declaring it up
 * front lets the largest element start downloading with the HTML. The
 * breakpoint mirrors global.css: the mobile image applies up to 47.5em.
 *
 * @param array<int, array<string, string>> $resources Resources WordPress will preload.
 * @return array<int, array<string, string>>
 */
function kuka_island_preload_hero_images( array $resources ): array {
	if ( ! is_front_page() ) {
		return $resources;
	}
	$hero = kuka_island_content()['hero'] ?? array();
	if ( empty( $hero['enabled'] ) ) {
		return $resources;
	}
	$desktop = ! empty( $hero['desktop_image_id'] ) ? (string) wp_get_attachment_image_url( absint( $hero['desktop_image_id'] ), 'full' ) : '';
	$mobile  = ! empty( $hero['mobile_image_id'] ) ? (string) wp_get_attachment_image_url( absint( $hero['mobile_image_id'] ), 'full' ) : $desktop;
	if ( '' === $desktop && '' === $mobile ) {
		return $resources;
	}
	if ( $desktop === $mobile ) {
		$resources[] = array( 'href' => $desktop, 'as' => 'image', 'fetchpriority' => 'high' );
		return $resources;
	}
	if ( '' !== $mobile ) {
		$resources[] = array( 'href' => $mobile, 'as' => 'image', 'media' => '(max-width: 47.5em)', 'fetchpriority' => 'high' );
	}
	if ( '' !== $desktop ) {
		$resources[] = array( 'href' => $desktop, 'as' => 'image', 'media' => '(min-width: 47.5625em)', 'fetchpriority' => 'high' );
	}
	return $resources;
}
add_filter( 'wp_preload_resources', 'kuka_island_preload_hero_images' );
