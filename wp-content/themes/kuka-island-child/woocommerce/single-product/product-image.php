<?php
/**
 * Editorial product gallery with deferred full-resolution lightbox sources.
 *
 * @package WooCommerce\Templates
 * @version 10.5.0
 */
defined( 'ABSPATH' ) || exit;
global $product;
if ( ! $product instanceof WC_Product ) { return; }

$image_ids = array_values( array_unique( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) ) );
if ( ! $image_ids ) { $image_ids[] = 0; }
?>
<section class="images kuka-product-gallery" data-product-gallery aria-label="<?php echo esc_attr( sprintf( __( '%s ürün galerisi', 'kuka-island' ), $product->get_name() ) ); ?>">
	<div class="kuka-product-gallery__track" data-gallery-track>
		<?php foreach ( $image_ids as $index => $image_id ) : ?>
			<?php
			$full        = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : wc_placeholder_img_src( 'full' );
			$display     = $image_id ? wp_get_attachment_image_src( $image_id, 'large' ) : false;
			$display_url = $display ? $display[0] : wc_placeholder_img_src( 'woocommerce_single' );
			$width       = $display ? $display[1] : 600;
			$height      = $display ? $display[2] : 800;
			$alt         = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : __( 'Ürün görseli', 'kuka-island' );
			?>
			<button class="kuka-product-gallery__item" type="button" data-gallery-item data-gallery-index="<?php echo esc_attr( $index ); ?>" data-full="<?php echo esc_url( $full ); ?>" aria-label="<?php echo esc_attr( sprintf( __( '%1$s; tam ekran aç (%2$d/%3$d)', 'kuka-island' ), get_post_meta( $image_id, '_wp_attachment_image_alt', true ), $index + 1, count( $image_ids ) ) ); ?>">
				<img src="<?php echo esc_url( $display_url ); ?>" width="<?php echo esc_attr( $width ); ?>" height="<?php echo esc_attr( $height ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="<?php echo 0 === $index ? 'eager' : 'lazy'; ?>" decoding="async">
			</button>
		<?php endforeach; ?>
	</div>
	<div class="kuka-product-gallery__mobile-controls"><button type="button" data-gallery-previous aria-label="<?php esc_attr_e( 'Önceki ürün fotoğrafı', 'kuka-island' ); ?>">←</button><span data-gallery-counter aria-live="polite">1 / <?php echo esc_html( count( $image_ids ) ); ?></span><button type="button" data-gallery-next aria-label="<?php esc_attr_e( 'Sonraki ürün fotoğrafı', 'kuka-island' ); ?>">→</button></div>
	<p class="kuka-sr-only" data-gallery-status aria-live="polite"></p>
</section>
<section id="kuka-product-lightbox" class="kuka-product-lightbox" role="dialog" aria-modal="true" aria-labelledby="kuka-lightbox-title" aria-hidden="true" inert>
	<header><span id="kuka-lightbox-title"><?php echo esc_html( sprintf( __( '%s galerisi', 'kuka-island' ), $product->get_name() ) ); ?></span><span data-lightbox-counter>1 / <?php echo esc_html( count( $image_ids ) ); ?></span><button type="button" data-lightbox-close aria-label="<?php esc_attr_e( 'Galeriyi kapat', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'close' ); // phpcs:ignore ?></button></header>
	<div class="kuka-product-lightbox__viewport" data-lightbox-viewport><div data-lightbox-image-host></div></div>
	<div class="kuka-product-lightbox__controls"><button type="button" data-lightbox-previous aria-label="<?php esc_attr_e( 'Önceki fotoğraf', 'kuka-island' ); ?>">←</button><button type="button" data-lightbox-zoom aria-pressed="false"><?php esc_html_e( 'Yakınlaştır', 'kuka-island' ); ?></button><button type="button" data-lightbox-next aria-label="<?php esc_attr_e( 'Sonraki fotoğraf', 'kuka-island' ); ?>">→</button></div>
	<p class="kuka-sr-only" data-lightbox-status aria-live="polite"></p>
</section>
