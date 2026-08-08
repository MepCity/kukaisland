<?php
/** Catalog canonical URL policy. */

defined( 'ABSPATH' ) || exit;

/** Print one query-free catalog canonical while retaining pagination. */
function kuka_island_catalog_canonical(): void {
	// Canonical and hreflang are emitted centrally by Kuka Island Core.
}
add_action( 'wp_head', 'kuka_island_catalog_canonical', 1 );
remove_action( 'wp_head', 'rel_canonical' );

add_filter(
	'document_title_parts',
	static function ( array $parts ): array {
		if ( ! is_product() ) { return $parts; }
		$product = wc_get_product( get_queried_object_id() );
		$meta_key = function_exists( 'kuka_island_is_english' ) && kuka_island_is_english() ? '_kuka_seo_title_en' : '_kuka_seo_title';
		$title   = $product instanceof WC_Product ? $product->get_meta( $meta_key ) : '';
		if ( ! $title && $product instanceof WC_Product ) { $title = $product->get_meta( '_kuka_seo_title' ); }
		if ( $title ) { $parts['title'] = $title; }
		return $parts;
	}
);

add_action(
	'wp_head',
	static function (): void {
		if ( ! is_product() ) { return; }
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof WC_Product ) { return; }
		$description_key = function_exists( 'kuka_island_is_english' ) && kuka_island_is_english() ? '_kuka_meta_description_en' : '_kuka_meta_description';
		$title_key = function_exists( 'kuka_island_is_english' ) && kuka_island_is_english() ? '_kuka_seo_title_en' : '_kuka_seo_title';
		$description = (string) ( $product->get_meta( $description_key ) ?: $product->get_meta( '_kuka_meta_description' ) );
		if ( ! $description ) { return; }
		echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( (string) ( $product->get_meta( $title_key ) ?: $product->get_meta( '_kuka_seo_title' ) ?: $product->get_name() ) ) . '">' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	},
	2
);
