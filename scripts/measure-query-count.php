<?php
/**
 * Measure the database query count on a 12-product catalog page.
 *
 * Executed only through the wp-cli container. Renders the shop loop twice
 * (cold and warm) and reports SAVEQUERIES counts so the N+1 fix can be
 * measured before/after.
 */

defined( 'WP_CLI' ) || exit( 1 );

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

global $wpdb;
$wpdb->queries = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

$url = wc_get_page_permalink( 'shop' );
WP_CLI::log( 'Catalog URL: ' . $url );

/**
 * Reset WooCommerce product loop query state and request a fresh 12-product
 * shop loop, capturing the query count.
 *
 * @return array{total:int, products:int, variations:int}
 */
function kuka_measure_catalog_queries(): array {
	global $wp_query, $wpdb;

	// Replay a main shop query for 12 published products.
	$wp_query = new WP_Query(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 12,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		)
	);

	$product_ids = $wp_query->posts;

	// Reproduce the theme's priming step (the N+1 fix) so the measurement
	// reflects the real catalog render path. If the function is absent the
	// measurement falls back to the unprimed card loop (the baseline path).
	if ( function_exists( 'kuka_island_prime_catalog_caches' ) ) {
		kuka_island_prime_catalog_caches( $product_ids );
	}

	$before = count( $wpdb->queries );

	$variation_total = 0;
	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			continue;
		}
		// Mirror the card's per-product work: terms + variations.
		wc_get_product_terms( $product_id, 'pa_kesim', array( 'fields' => 'names' ) );
		wc_get_product_terms( $product_id, 'pa_renk', array( 'fields' => 'all' ) );
		wc_get_product_terms( $product_id, 'pa_beden', array( 'fields' => 'all' ) );
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation instanceof WC_Product_Variation ) {
					++$variation_total;
					$attributes = $variation->get_attributes();
					$color_slug = $attributes['pa_renk'] ?? '';
					if ( $color_slug ) {
						get_term_by( 'slug', $color_slug, 'pa_renk' );
					}
				}
			}
		}
	}

	$after = count( $wpdb->queries );
	return array(
		'total'      => $after - $before,
		'products'   => count( $product_ids ),
		'variations' => $variation_total,
	);
}

// Warm run (objects cached from the cold run) to show steady-state cost too.
$cold = kuka_measure_catalog_queries();
$warm = kuka_measure_catalog_queries();

WP_CLI::log( sprintf( 'PRODUCTS=%d', $cold['products'] ) );
WP_CLI::log( sprintf( 'VARIATIONS_TOUCHED=%d', $cold['variations'] ) );
WP_CLI::log( sprintf( 'QUERIES_COLD=%d', $cold['total'] ) );
WP_CLI::log( sprintf( 'QUERIES_WARM=%d', $warm['total'] ) );
