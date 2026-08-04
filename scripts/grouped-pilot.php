<?php
/**
 * Creates a disposable grouped-product candidate for rendered markup inspection.
 */

defined( 'WP_CLI' ) || exit( 1 );

$old_id = wc_get_product_id_by_sku( 'KI-PILOT-GROUPED' );
if ( $old_id ) {
	wp_delete_post( $old_id, true );
}

$children = array_filter(
	array(
		wc_get_product_id_by_sku( 'KI-TOP-002' ),
		wc_get_product_id_by_sku( 'KI-BTM-004' ),
	)
);

$grouped = new WC_Product_Grouped();
$grouped->set_name( 'Geçici Kombin Grouped Pilot' );
$grouped->set_slug( 'gecici-kombin-grouped-pilot' );
$grouped->set_sku( 'KI-PILOT-GROUPED' );
$grouped->set_status( 'publish' );
$grouped->set_catalog_visibility( 'hidden' );
$grouped->set_children( $children );
$id = $grouped->save();

WP_CLI::line( $id . '|' . get_permalink( $id ) );

