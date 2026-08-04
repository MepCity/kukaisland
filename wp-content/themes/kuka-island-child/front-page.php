<?php
/** Storefront. */
defined( 'ABSPATH' ) || exit;
get_header();
$content = kuka_island_content();
$hero = $content['hero'] ?? array();
$desktop = ! empty( $hero['desktop_image_id'] ) ? wp_get_attachment_image_url( $hero['desktop_image_id'], 'full' ) : '';
$mobile = ! empty( $hero['mobile_image_id'] ) ? wp_get_attachment_image_url( $hero['mobile_image_id'], 'full' ) : $desktop;
?>
<?php if ( ! empty( $hero['enabled'] ) ) : ?>
<section class="kuka-hero kuka-hero--<?php echo esc_attr( $hero['text_tone'] ?? 'light' ); ?> kuka-hero--<?php echo esc_attr( $hero['alignment'] ?? 'left' ); ?>" style="--hero-desktop:url('<?php echo esc_url( $desktop ); ?>');--hero-mobile:url('<?php echo esc_url( $mobile ); ?>')">
	<div class="kuka-hero__content"><p class="kuka-eyebrow"><?php echo esc_html( $hero['eyebrow'] ?? '' ); ?></p><h1><?php echo esc_html( $hero['title'] ?? '' ); ?></h1><p><?php echo esc_html( $hero['copy'] ?? '' ); ?></p><a class="kuka-button" href="<?php echo esc_url( kuka_island_content_url( $hero['button_url'] ?? '/magaza/' ) ); ?>"><?php echo esc_html( $hero['button_label'] ?? '' ); ?></a></div>
</section>
<?php endif; ?>
<section class="kuka-home-products kuka-section"><div class="kuka-section-heading"><p class="kuka-eyebrow"><?php esc_html_e( 'Koleksiyon', 'kuka-island' ); ?></p><h2><?php echo esc_html( $content['home']['new_arrivals_title'] ?? '' ); ?></h2><a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Tümünü gör', 'kuka-island' ); ?></a></div><?php echo do_shortcode( '[products limit="4" columns="4" orderby="date"]' ); ?></section>
<section class="kuka-editorial kuka-section"><div class="kuka-editorial__image"><?php echo wp_get_attachment_image( absint( $content['home']['editorial_image_id'] ?? 0 ), 'full' ); ?></div><div><p class="kuka-eyebrow"><?php esc_html_e( 'Editoryal', 'kuka-island' ); ?></p><h2><?php echo esc_html( $content['home']['editorial_title'] ?? '' ); ?></h2><p><?php echo esc_html( $content['home']['editorial_copy'] ?? '' ); ?></p><a class="kuka-button" href="<?php echo esc_url( kuka_island_content_url( $content['home']['editorial_url'] ?? '/hakkimizda/' ) ); ?>"><?php esc_html_e( 'Hikâyeyi oku', 'kuka-island' ); ?></a></div></section>
<section class="kuka-manifesto kuka-section"><p class="kuka-eyebrow"><?php esc_html_e( 'Manifesto', 'kuka-island' ); ?></p><h2><?php echo esc_html( $content['home']['manifesto_title'] ?? '' ); ?></h2><p><?php echo esc_html( $content['home']['manifesto_copy'] ?? '' ); ?></p></section>
<section class="kuka-services"><?php foreach ( array( 'service_1', 'service_2', 'service_3' ) as $key ) : ?><div><span aria-hidden="true">↗</span><p><?php echo esc_html( $content['home'][ $key ] ?? '' ); ?></p></div><?php endforeach; ?></section>
<?php get_footer(); ?>
