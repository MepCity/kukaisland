<?php
/**
 * Machine-readable-ish acceptance snapshot.
 */

defined( 'WP_CLI' ) || exit( 1 );

$attribute_rows = array();
foreach ( wc_get_attribute_taxonomies() as $attribute ) {
	if ( in_array( $attribute->attribute_name, array( 'renk', 'beden', 'kesim' ), true ) ) {
		$taxonomy        = wc_attribute_taxonomy_name( $attribute->attribute_name );
		$attribute_rows[] = array(
			'name'  => $attribute->attribute_label,
			'slug'  => $taxonomy,
			'terms' => implode( ',', wp_list_pluck( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ), 'name' ) ),
		);
	}
}

WP_CLI::line( 'WP_LOCALE=' . get_locale() );
WP_CLI::line( 'TIMEZONE=' . wp_timezone_string() );
WP_CLI::line( 'CURRENCY=' . get_woocommerce_currency() );
WP_CLI::line( 'ACTIVE_THEME=' . wp_get_theme()->get_stylesheet() );
WP_CLI::line( 'HPOS=' . get_option( 'woocommerce_custom_orders_table_enabled' ) );
WP_CLI::line( 'GUEST_CHECKOUT=' . get_option( 'woocommerce_enable_guest_checkout' ) );
WP_CLI::line( 'IMAGE_CROP=' . get_option( 'woocommerce_thumbnail_cropping_custom_width' ) . ':' . get_option( 'woocommerce_thumbnail_cropping_custom_height' ) );
WP_CLI::line( 'BIG_IMAGE_THRESHOLD=' . apply_filters( 'big_image_size_threshold', 2560, array(), '', 0 ) );
$thumb_size = wc_get_image_size( 'thumbnail' );
$single_size = wc_get_image_size( 'single' );
$gallery_size = wc_get_image_size( 'gallery_thumbnail' );
WP_CLI::line( sprintf( 'IMAGE_SIZES=card:%dx%d|single:%dx%d|gallery:%dx%d', $thumb_size['width'], $thumb_size['height'], $single_size['width'], $single_size['height'], $gallery_size['width'], $gallery_size['height'] ) );
WP_CLI::line( 'IYZICO_ACTIVE=' . ( is_plugin_active( 'iyzico-woocommerce/woocommerce-gateway-iyzico.php' ) ? 'yes' : 'no' ) );
foreach ( $attribute_rows as $row ) {
	WP_CLI::line( 'ATTRIBUTE=' . $row['name'] . '|' . $row['slug'] . '|' . $row['terms'] );
}

$products = wc_get_products( array( 'limit' => -1, 'type' => 'variable', 'return' => 'objects' ) );
WP_CLI::line( 'VARIABLE_PRODUCTS=' . count( $products ) );
foreach ( $products as $product ) {
	$valid_stock = true;
	$galleries   = true;
	$fields      = true;
	foreach ( array( '_kuka_material', '_kuka_care', '_kuka_fit', '_kuka_model_info', '_kuka_size_guide' ) as $meta_key ) {
		$fields = $fields && '' !== (string) $product->get_meta( $meta_key, true );
	}
	foreach ( $product->get_children() as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		$valid_stock = $valid_stock && $variation->get_sku() && $variation->managing_stock() && null !== $variation->get_low_stock_amount();
		$blocksy_gallery = $variation->get_meta( 'blocksy_post_meta_options', true );
		$galleries      = $galleries && 'custom' === ( $blocksy_gallery['gallery_source'] ?? '' ) && ! empty( $blocksy_gallery['images'] );
	}
	WP_CLI::line(
		sprintf(
			'PRODUCT=%s|variations:%d|stock_fields:%s|color_galleries:%s|required_fields:%s',
			$product->get_sku(),
			count( $product->get_children() ),
			$valid_stock ? 'yes' : 'no',
			$galleries ? 'yes' : 'no',
			$fields ? 'yes' : 'no'
		)
	);
}

$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0 ) );
WP_CLI::line( 'MAIN_CATEGORIES=' . implode( ',', wp_list_pluck( $categories, 'name' ) ) );
$turkey_zone = null;
foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
	if ( 'Türkiye' === $zone_data['zone_name'] ) {
		$turkey_zone = WC_Shipping_Zones::get_zone( (int) $zone_data['zone_id'] );
		break;
	}
}
WP_CLI::line( 'TURKEY_SHIPPING_METHODS=' . ( $turkey_zone ? implode( ',', wp_list_pluck( $turkey_zone->get_shipping_methods( true ), 'id' ) ) : 'missing' ) );

$swatches = get_terms( array( 'taxonomy' => 'pa_renk', 'hide_empty' => false ) );
foreach ( $swatches as $term ) {
	WP_CLI::line( 'SWATCH=' . $term->name . '|' . get_term_meta( $term->term_id, 'kuka_swatch_hex', true ) );
}
