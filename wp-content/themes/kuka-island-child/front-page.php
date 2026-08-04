<?php
/** Storefront. */
defined( 'ABSPATH' ) || exit;
get_header();
$content = kuka_island_content();
$hero = $content['hero'] ?? array();
$desktop = ! empty( $hero['desktop_image_id'] ) ? wp_get_attachment_image_url( $hero['desktop_image_id'], 'full' ) : '';
$mobile = ! empty( $hero['mobile_image_id'] ) ? wp_get_attachment_image_url( $hero['mobile_image_id'], 'full' ) : $desktop;
$category_terms = get_terms( array(
	'taxonomy'   => 'product_cat',
	'hide_empty' => false,
	'parent'     => 0,
	'number'     => 4,
	'orderby'    => 'name',
	'exclude'    => array_filter( array( (int) get_option( 'default_product_cat' ) ) ),
) );
?>
<?php if ( ! empty( $hero['enabled'] ) ) : ?>
<section class="kuka-hero kuka-hero--<?php echo esc_attr( $hero['text_tone'] ?? 'light' ); ?> kuka-hero--<?php echo esc_attr( $hero['alignment'] ?? 'left' ); ?>" style="--hero-desktop:url('<?php echo esc_url( $desktop ); ?>');--hero-mobile:url('<?php echo esc_url( $mobile ); ?>')">
	<div class="kuka-hero__content"><p class="kuka-eyebrow"><?php echo esc_html( $hero['eyebrow'] ?? '' ); ?></p><h1><?php echo esc_html( $hero['title'] ?? '' ); ?></h1><p><?php echo esc_html( $hero['copy'] ?? '' ); ?></p><a class="kuka-button" href="<?php echo esc_url( kuka_island_content_url( $hero['button_url'] ?? '/magaza/' ) ); ?>"><?php echo esc_html( $hero['button_label'] ?? '' ); ?></a></div>
</section>
<?php endif; ?>
<?php if ( ! is_wp_error( $category_terms ) && $category_terms ) : ?>
<section class="kuka-category-intro kuka-section" aria-labelledby="kuka-category-title">
	<h2 id="kuka-category-title" class="kuka-eyebrow"><?php esc_html_e( 'Formunu bul', 'kuka-island' ); ?></h2>
	<div class="kuka-category-index" aria-label="<?php esc_attr_e( 'Ürün kategorileri', 'kuka-island' ); ?>">
		<?php foreach ( $category_terms as $index => $category ) :
			$product_ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $category->term_id ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$cut_names = $product_ids ? wp_get_object_terms( $product_ids, 'pa_kesim', array( 'fields' => 'names' ) ) : array();
			$cut_names = is_wp_error( $cut_names ) ? array() : array_values( array_unique( $cut_names ) );
			?>
			<a class="kuka-category-index__item" href="<?php echo esc_url( get_term_link( $category ) ); ?>">
				<span class="kuka-category-index__number"><?php echo esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
				<span class="kuka-category-index__name"><?php echo esc_html( $category->name ); ?></span>
				<span class="kuka-category-index__meta"><?php echo esc_html( $cut_names ? implode( ' · ', $cut_names ) : __( 'Seçki', 'kuka-island' ) ); ?></span>
				<span class="kuka-category-index__arrow" aria-hidden="true">↗</span>
			</a>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>
<section class="kuka-home-products kuka-section"><div class="kuka-section-heading"><p class="kuka-eyebrow"><?php esc_html_e( 'Koleksiyon', 'kuka-island' ); ?></p><h2><?php echo esc_html( $content['home']['new_arrivals_title'] ?? '' ); ?></h2><a class="kuka-text-link" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Tümünü gör', 'kuka-island' ); ?><span aria-hidden="true">→</span></a></div><?php echo do_shortcode( '[products limit="4" columns="4" orderby="date"]' ); ?></section>
<section class="kuka-editorial kuka-section"><div class="kuka-editorial__image"><?php echo wp_get_attachment_image( absint( $content['home']['editorial_image_id'] ?? 0 ), 'full' ); ?></div><div><p class="kuka-eyebrow"><?php esc_html_e( 'Editoryal', 'kuka-island' ); ?></p><h2><?php echo esc_html( $content['home']['editorial_title'] ?? '' ); ?></h2><p><?php echo esc_html( $content['home']['editorial_copy'] ?? '' ); ?></p><a class="kuka-button" href="<?php echo esc_url( kuka_island_content_url( $content['home']['editorial_url'] ?? '/hakkimizda/' ) ); ?>"><?php esc_html_e( 'Hikâyeyi oku', 'kuka-island' ); ?></a></div></section>
<section class="kuka-manifesto kuka-section"><p class="kuka-eyebrow"><?php esc_html_e( 'Manifesto', 'kuka-island' ); ?></p><h2><?php echo esc_html( $content['home']['manifesto_title'] ?? '' ); ?></h2><p><?php echo esc_html( $content['home']['manifesto_copy'] ?? '' ); ?></p></section>
<section class="kuka-services"><?php foreach ( array( 'service_1', 'service_2', 'service_3' ) as $key ) : ?><div><span aria-hidden="true">↗</span><p><?php echo esc_html( $content['home'][ $key ] ?? '' ); ?></p></div><?php endforeach; ?></section>
<?php get_footer(); ?>
