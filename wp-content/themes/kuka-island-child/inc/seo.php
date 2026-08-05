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
