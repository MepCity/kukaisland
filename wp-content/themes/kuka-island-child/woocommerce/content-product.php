<?php
/**
 * Editorial catalog product card.
 *
 * @package WooCommerce\Templates
 * @version 9.4.0
 */
defined( 'ABSPATH' ) || exit;
global $product;
if ( ! $product instanceof WC_Product || ! $product->is_visible() ) { return; }

$cuts = wc_get_product_terms( $product->get_id(), 'pa_kesim', array( 'fields' => 'all' ) );
if ( is_wp_error( $cuts ) ) { $cuts = array(); }
$colors = wc_get_product_terms( $product->get_id(), 'pa_renk', array( 'fields' => 'all' ) );
if ( is_wp_error( $colors ) ) { $colors = array(); }
$sizes = wc_get_product_terms( $product->get_id(), 'pa_beden', array( 'fields' => 'all', 'orderby' => 'menu_order' ) );
if ( is_wp_error( $sizes ) ) { $sizes = array(); }
$color_by_slug = array();
if ( is_array( $colors ) ) {
	foreach ( $colors as $color_term ) {
		if ( $color_term instanceof WP_Term ) {
			$color_by_slug[ $color_term->slug ] = $color_term;
		}
	}
}
$color_data = array();
$variation_images = array();
if ( $product->is_type( 'variable' ) ) {
	foreach ( $product->get_children() as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof WC_Product_Variation ) { continue; }
		$attributes = $variation->get_attributes();
		$color_slug = (string) ( $attributes['pa_renk'] ?? '' );
		$size_slug  = (string) ( $attributes['pa_beden'] ?? '' );
		if ( ! $color_slug ) { continue; }
		$image_id = $variation->get_image_id();
		if ( $image_id ) { $variation_images[] = $image_id; }
		if ( ! isset( $color_data[ $color_slug ] ) ) {
			$term = $color_by_slug[ $color_slug ] ?? null;
			$color_data[ $color_slug ] = array(
				'name'     => $term ? kuka_island_term_name( $term ) : $color_slug,
				'hex'      => $term ? (string) get_term_meta( $term->term_id, 'kuka_swatch_hex', true ) : '#777777',
				'image_id' => $image_id,
				'sizes'    => array(),
			);
		}
		$color_data[ $color_slug ]['sizes'][ $size_slug ] = $variation->is_in_stock();
	}
}
$image_ids = array_values( array_unique( array_filter( array_merge( array( $product->get_image_id() ), $variation_images, $product->get_gallery_image_ids() ) ) ) );
$selected_color = $color_data ? array_key_first( $color_data ) : '';
$card_settings = kuka_island_content()['home'] ?? array();
$badge = '';
if ( ! $product->is_in_stock() ) {
	$badge = __( 'Tükendi', 'kuka-island' );
} elseif ( $product->is_on_sale() ) {
	$badge = __( 'Sınırlı', 'kuka-island' );
} elseif ( $product->get_date_created() && $product->get_date_created()->getTimestamp() > strtotime( '-30 days' ) ) {
	$badge = __( 'Yeni', 'kuka-island' );
}
?>
<li <?php wc_product_class( 'kuka-product-card', $product ); ?> data-product-card>
	<div class="kuka-product-card__visual">
		<a href="<?php echo esc_url( $product->get_permalink() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( '%s ürününü incele', 'kuka-island' ), $product->get_name() ) ); ?>">
			<?php foreach ( $image_ids as $index => $image_id ) : ?>
				<?php echo wp_get_attachment_image( $image_id, 'woocommerce_thumbnail', false, array( 'class' => 'kuka-product-card__image' . ( 0 === $index ? ' is-active' : '' ), 'data-card-image' => (string) $index, 'loading' => 0 === $index ? 'eager' : 'lazy' ) ); ?>
			<?php endforeach; ?>
		</a>
		<?php if ( $badge ) : ?><span class="kuka-product-card__badge"><?php echo esc_html( $badge ); ?></span><?php endif; ?>
		<?php if ( $color_data && ! empty( $card_settings['card_swatches_enabled'] ) ) : ?>
			<div class="kuka-product-card__swatches" aria-label="<?php esc_attr_e( 'Renk seçenekleri', 'kuka-island' ); ?>">
				<?php foreach ( $color_data as $slug => $color ) : ?>
					<button type="button" class="kuka-product-card__swatch<?php echo $slug === $selected_color ? ' is-selected' : ''; ?>" data-card-swatch data-color="<?php echo esc_attr( $slug ); ?>" data-color-name="<?php echo esc_attr( $color['name'] ); ?>" data-image-index="<?php echo esc_attr( (string) array_search( $color['image_id'], $image_ids, true ) ); ?>" data-sizes="<?php echo esc_attr( wp_json_encode( $color['sizes'] ) ); ?>" style="--swatch-color:<?php echo esc_attr( sanitize_hex_color( $color['hex'] ) ?: '#777777' ); ?>" aria-label="<?php echo esc_attr( $color['name'] ); ?>" aria-pressed="<?php echo $slug === $selected_color ? 'true' : 'false'; ?>"><span></span></button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php if ( count( $image_ids ) > 1 ) : ?>
			<div class="kuka-product-card__nav"><button type="button" data-card-previous aria-label="<?php esc_attr_e( 'Önceki fotoğraf', 'kuka-island' ); ?>">←</button><span aria-live="polite">1 / <?php echo esc_html( count( $image_ids ) ); ?></span><button type="button" data-card-next aria-label="<?php esc_attr_e( 'Sonraki fotoğraf', 'kuka-island' ); ?>">→</button></div>
		<?php endif; ?>
	</div>
	<a class="kuka-product-card__info" href="<?php echo esc_url( $product->get_permalink() ); ?>">
		<?php if ( $cuts ) : ?><p class="kuka-product-card__cut"><?php echo esc_html( implode( ', ', array_map( 'kuka_island_term_name', $cuts ) ) ); ?></p><?php endif; ?>
		<div class="kuka-product-card__title-row"><h2><?php echo esc_html( $product->get_name() ); ?></h2><div class="kuka-product-card__price">
		<?php echo wp_kses_post( $product->get_price_html() ); ?>
		</div></div>
		<p class="kuka-product-card__color"><span data-card-color-name><?php echo esc_html( $selected_color ? $color_data[ $selected_color ]['name'] : ( $colors[0]->name ?? '' ) ); ?></span><?php if ( $colors ) : ?> · <?php echo esc_html( sprintf( _n( '%d renk', '%d renk', count( $colors ), 'kuka-island' ), count( $colors ) ) ); ?><?php endif; ?></p>
		<?php if ( ! empty( $card_settings['card_stock_enabled'] ) ) : ?><div class="kuka-product-card__stock-row">
			<span class="kuka-product-card__sku"><?php echo esc_html( $product->get_sku() ); ?></span>
			<?php if ( $sizes ) : ?><span class="kuka-product-card__sizes" aria-label="<?php esc_attr_e( 'Beden stokları', 'kuka-island' ); ?>"><?php foreach ( $sizes as $size ) : $available = (bool) ( $color_data[ $selected_color ]['sizes'][ $size->slug ] ?? false ); ?><span data-card-size="<?php echo esc_attr( $size->slug ); ?>"<?php echo $available ? '' : ' class="is-sold-out"'; ?>><?php echo esc_html( kuka_island_term_name( $size ) ); ?></span><?php endforeach; ?></span><?php endif; ?>
		</div><?php endif; ?>
	</a>
</li>
