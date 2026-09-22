<?php
/** Photo-only community gallery with optional direct product links. */
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'Kuka_Island_Core_Community_Gallery' ) ) { return; }
$gallery = Kuka_Island_Core_Community_Gallery::get();
$items = Kuka_Island_Core_Community_Gallery::published_items();
if ( empty( $gallery['enabled'] ) || ! $items ) { return; }
$english = function_exists( 'kuka_island_locale' ) && 'en' === kuka_island_locale();
$copy = static function ( array $data, string $key ) use ( $english ): string {
	return (string) ( $english && ! empty( $data[ $key . '_en' ] ) ? $data[ $key . '_en' ] : ( $data[ $key ] ?? '' ) );
};
?>
<section class="kuka-community kuka-section" aria-labelledby="kuka-community-title" data-community>
	<div class="kuka-community__heading"><div><p class="kuka-eyebrow"><?php echo esc_html( $copy( $gallery, 'eyebrow' ) ); ?></p><h2 id="kuka-community-title"><?php echo esc_html( $copy( $gallery, 'title' ) ); ?></h2></div><p class="kuka-community__copy"><?php echo esc_html( $copy( $gallery, 'copy' ) ); ?></p></div>
	<div class="kuka-community__rail" id="kuka-community-rail">
	<?php foreach ( $items as $index => $item ) :
		$image_id = absint( $item['image_id'] );
		$alt = (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true );
		if ( '' === $alt ) { $alt = $english ? 'Kuka Island community photo' : 'Kuka Island Sizden Gelenler fotoğrafı'; }
		$url = esc_url( (string) ( $item['product_url'] ?? '' ), array( 'http', 'https' ) );
		?>
		<div class="kuka-community__card">
			<?php if ( $url ) : ?><a class="kuka-community__photo" href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>" aria-label="<?php echo esc_attr( ( $english ? 'Discover the product: ' : 'Ürünü incele: ' ) . $alt ); ?>"><?php else : ?><div class="kuka-community__photo"><?php endif; ?>
				<?php echo wp_get_attachment_image( $image_id, 'large', false, array( 'alt' => $alt, 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '(max-width: 760px) 82vw, (max-width: 1024px) 44vw, 23vw' ) ); ?>
			<?php if ( $url ) : ?></a><?php else : ?></div><?php endif; ?>
		</div>
	<?php endforeach; ?>
	</div>
	<div class="kuka-community__controls" data-rail-controls hidden><button type="button" data-rail-prev aria-controls="kuka-community-rail" aria-label="<?php echo esc_attr( $english ? 'Previous photo' : 'Önceki fotoğraf' ); ?>">←</button><button type="button" data-rail-next aria-controls="kuka-community-rail" aria-label="<?php echo esc_attr( $english ? 'Next photo' : 'Sonraki fotoğraf' ); ?>">→</button></div>
</section>
