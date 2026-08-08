<?php
/**
 * Move `pa_beden` to the S · M · L set on an existing store.
 *
 * Deleting the old terms first would leave the variations pointing at slugs
 * that no longer resolve, so the order is: create the new terms, remap every
 * variation and parent attribute, delete the variations that collapse onto an
 * already-taken (colour, size) pair, and only then remove the retired terms.
 *
 * The map follows the size guide: 34/36 → S, 38 → M, 40/42 → L, XS → S,
 * XL → L. Idempotent — a store already on S/M/L reports zero changes.
 */

defined( 'WP_CLI' ) || exit( 1 );

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce etkin değil.' );
}

const KUKA_SIZE_MAP = array(
	'34' => 's',
	'36' => 's',
	'xs' => 's',
	'38' => 'm',
	'40' => 'l',
	'42' => 'l',
	'xl' => 'l',
);
const KUKA_SIZE_KEEP = array( 's' => 'S', 'm' => 'M', 'l' => 'L' );

foreach ( KUKA_SIZE_KEEP as $slug => $name ) {
	if ( ! get_term_by( 'slug', $slug, 'pa_beden' ) ) {
		$created = wp_insert_term( $name, 'pa_beden', array( 'slug' => $slug ) );
		if ( is_wp_error( $created ) ) {
			WP_CLI::error( $created->get_error_message() );
		}
	}
}

$moved   = 0;
$dropped = 0;

foreach ( wc_get_products( array( 'type' => 'variable', 'limit' => -1 ) ) as $product ) {
	$groups = array();
	foreach ( $product->get_children() as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof WC_Product_Variation ) {
			continue;
		}
		$attributes = $variation->get_attributes();
		$size       = (string) ( $attributes['pa_beden'] ?? '' );
		$mapped     = KUKA_SIZE_MAP[ $size ] ?? $size;
		$key        = ( $attributes['pa_renk'] ?? '' ) . '|' . $mapped;
		$groups[ $key ][] = compact( 'variation', 'attributes', 'size', 'mapped' );
	}

	foreach ( $groups as $items ) {
		// Var olan S/M/L kaydı varsa onu korumak SKU çakışmasını ve gereksiz kimlik
		// değişimini önler. Yoksa grubun ilk kaydı yeni bedene taşınır.
		$survivor_item = $items[0];
		foreach ( $items as $item ) {
			if ( $item['size'] === $item['mapped'] ) {
				$survivor_item = $item;
				break;
			}
		}
		$survivor   = $survivor_item['variation'];
		$total_stock = 0;
		$has_stock   = false;
		foreach ( $items as $item ) {
			$variation = $item['variation'];
			if ( $variation->managing_stock() ) {
				$total_stock += (int) $variation->get_stock_quantity();
				$has_stock = true;
			}
			if ( $variation->get_id() !== $survivor->get_id() ) {
				$variation->delete( true );
				++$dropped;
			}
		}

		if ( $survivor_item['mapped'] !== $survivor_item['size'] ) {
			$attributes               = $survivor_item['attributes'];
			$attributes['pa_beden']  = $survivor_item['mapped'];
			$survivor->set_attributes( $attributes );
			$sku = (string) $survivor->get_sku();
			if ( '' !== $sku && str_ends_with( $sku, '-' . strtoupper( $survivor_item['size'] ) ) ) {
				$survivor->set_sku( substr( $sku, 0, -strlen( $survivor_item['size'] ) ) . strtoupper( $survivor_item['mapped'] ) );
			}
			++$moved;
		}
		if ( $has_stock ) {
			$survivor->set_manage_stock( true );
			$survivor->set_stock_quantity( $total_stock );
		}
		$survivor->save();
	}

	// Üst üründeki beden seçenekleri yeni terim kümesine indirilir.
	$attributes = $product->get_attributes();
	if ( isset( $attributes['pa_beden'] ) ) {
		$options = array();
		foreach ( (array) $attributes['pa_beden']->get_options() as $term_id ) {
			$term = get_term( (int) $term_id, 'pa_beden' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$mapped = KUKA_SIZE_MAP[ $term->slug ] ?? $term->slug;
			$target = get_term_by( 'slug', $mapped, 'pa_beden' );
			if ( $target instanceof WP_Term ) {
				$options[ $target->term_id ] = $target->term_id;
			}
		}
		$attributes['pa_beden']->set_options( array_values( $options ) );
		$product->set_attributes( $attributes );
		$product->save();
	}

	WC_Product_Variable::sync( $product->get_id() );
	wc_delete_product_transients( $product->get_id() );
}

$removed = 0;
foreach ( get_terms( array( 'taxonomy' => 'pa_beden', 'hide_empty' => false ) ) as $term ) {
	if ( isset( KUKA_SIZE_KEEP[ $term->slug ] ) ) {
		continue;
	}
	wp_delete_term( $term->term_id, 'pa_beden' );
	++$removed;
}

WP_CLI::success( sprintf( 'Beden geçişi: %d varyasyon taşındı, %d tekrar silindi, %d terim kaldırıldı.', $moved, $dropped, $removed ) );
