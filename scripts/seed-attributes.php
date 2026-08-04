<?php
/**
 * Attribute schema pass. WooCommerce registers new pa_* taxonomies on the next request.
 */

defined( 'WP_CLI' ) || exit( 1 );

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce etkin değil.' );
}

$wanted = array( 'renk' => 'Renk', 'beden' => 'Beden', 'kesim' => 'Kesim' );
$known  = array();
foreach ( wc_get_attribute_taxonomies() as $attribute ) {
	$known[ $attribute->attribute_name ] = (int) $attribute->attribute_id;
}

foreach ( $wanted as $slug => $name ) {
	if ( isset( $known[ $slug ] ) ) {
		continue;
	}

	$result = wc_create_attribute(
		array(
			'name'         => $name,
			'slug'         => $slug,
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		)
	);
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $result->get_error_message() );
	}
}

delete_transient( 'wc_attribute_taxonomies' );
WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
WP_CLI::success( 'Global nitelik şeması hazır.' );

