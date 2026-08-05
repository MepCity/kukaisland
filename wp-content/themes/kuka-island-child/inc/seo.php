<?php
/** Catalog canonical URL policy. */

defined( 'ABSPATH' ) || exit;

/** Print one query-free catalog canonical while retaining pagination. */
function kuka_island_catalog_canonical(): void {
	if ( ! is_shop() && ! is_product_taxonomy() ) { return; }
	$page = max( 1, absint( get_query_var( 'paged' ) ) );
	$url  = get_pagenum_link( $page );
	$url  = strtok( $url, '?' ) ?: $url;
	echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
}
add_action( 'wp_head', 'kuka_island_catalog_canonical', 1 );

add_filter(
	'document_title_parts',
	static function ( array $parts ): array {
		if ( ! is_product() ) { return $parts; }
		$product = wc_get_product( get_queried_object_id() );
		$title   = $product instanceof WC_Product ? $product->get_meta( '_kuka_seo_title' ) : '';
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
		$description = (string) $product->get_meta( '_kuka_meta_description' );
		if ( ! $description ) { return; }
		echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( (string) ( $product->get_meta( '_kuka_seo_title' ) ?: $product->get_name() ) ) . '">' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	},
	2
);
