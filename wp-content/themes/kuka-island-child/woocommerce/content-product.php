<?php
/**
 * Product card.
 *
 * @package WooCommerce\Templates
 * @version 9.4.0
 */
defined( 'ABSPATH' ) || exit;
global $product;
if ( ! $product || ! $product->is_visible() ) { return; }
?>
<li <?php wc_product_class( 'kuka-card', $product ); ?>>
	<a class="kuka-card__link" href="<?php echo esc_url( $product->get_permalink() ); ?>">
		<div class="kuka-card__media">
			<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
			<?php $cuts = wc_get_product_terms( $product->get_id(), 'pa_kesim', array( 'fields' => 'names' ) ); ?>
			<?php if ( $cuts ) : ?><p class="kuka-card__cut"><?php echo esc_html( implode( ', ', $cuts ) ); ?></p><?php endif; ?>
		</div>
		<div class="kuka-card__body"><h2 class="woocommerce-loop-product__title"><?php echo esc_html( $product->get_name() ); ?></h2><span class="price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span></div>
	</a>
</li>
