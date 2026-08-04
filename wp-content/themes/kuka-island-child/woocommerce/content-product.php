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

$image_ids = array_values( array_unique( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) ) );
$cuts = wc_get_product_terms( $product->get_id(), 'pa_kesim', array( 'fields' => 'names' ) );
$colors = wc_get_product_terms( $product->get_id(), 'pa_renk', array( 'fields' => 'all' ) );
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
		<?php if ( count( $image_ids ) > 1 ) : ?>
			<div class="kuka-product-card__nav"><button type="button" data-card-previous aria-label="<?php esc_attr_e( 'Önceki fotoğraf', 'kuka-island' ); ?>">←</button><span aria-live="polite">1 / <?php echo esc_html( count( $image_ids ) ); ?></span><button type="button" data-card-next aria-label="<?php esc_attr_e( 'Sonraki fotoğraf', 'kuka-island' ); ?>">→</button></div>
		<?php endif; ?>
	</div>
	<a class="kuka-product-card__info" href="<?php echo esc_url( $product->get_permalink() ); ?>">
		<?php if ( $cuts ) : ?><p class="kuka-product-card__cut"><?php echo esc_html( implode( ', ', $cuts ) ); ?></p><?php endif; ?>
		<div class="kuka-product-card__title-row"><h2><?php echo esc_html( $product->get_name() ); ?></h2><div class="kuka-product-card__price">
		<?php if ( $product->is_on_sale() ) : ?><span class="screen-reader-text"><?php esc_html_e( 'Eski fiyat:', 'kuka-island' ); ?></span><del><?php echo wp_kses_post( wc_price( $product->get_regular_price() ) ); ?></del><span class="screen-reader-text"><?php esc_html_e( 'Yeni fiyat:', 'kuka-island' ); ?></span><ins><?php echo wp_kses_post( wc_price( $product->get_sale_price() ) ); ?></ins><?php else : ?><?php echo wp_kses_post( $product->get_price_html() ); ?><?php endif; ?>
		</div></div>
		<p class="kuka-product-card__color"><?php if ( $colors ) : ?><?php echo esc_html( $colors[0]->name ); ?> · <?php echo esc_html( sprintf( _n( '%d renk', '%d renk', count( $colors ), 'kuka-island' ), count( $colors ) ) ); ?><?php endif; ?></p>
	</a>
</li>
