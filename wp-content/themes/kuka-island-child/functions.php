<?php
/**
 * Kuka Island child theme bootstrap.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

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
		wp_enqueue_script(
			$handle,
			get_stylesheet_directory_uri() . '/' . $script,
			$dependencies,
			kuka_island_child_asset_version( $script ),
			true
		);
		$previous = $handle;
	}
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
