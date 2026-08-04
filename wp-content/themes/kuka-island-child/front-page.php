<?php
/** Storefront. */
defined( 'ABSPATH' ) || exit;
get_header();
$content = kuka_island_content();
$hero = $content['hero'] ?? array();
$home = $content['home'] ?? array();
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
$products_shortcode = '[products limit="4" columns="4" orderby="date"';
if ( 'featured' === ( $home['new_arrivals_source'] ?? 'latest' ) ) { $products_shortcode .= ' visibility="featured"'; }
if ( 'sale' === ( $home['new_arrivals_source'] ?? 'latest' ) ) { $products_shortcode .= ' on_sale="true"'; }
if ( 'manual' === ( $home['new_arrivals_source'] ?? 'latest' ) && ! empty( $home['manual_product_ids'] ) ) { $products_shortcode .= ' ids="' . esc_attr( $home['manual_product_ids'] ) . '"'; }
if ( ! empty( $home['source_category'] ) ) { $products_shortcode .= ' category="' . esc_attr( $home['source_category'] ) . '"'; }
if ( ! empty( $home['source_collection'] ) ) { $products_shortcode .= ' tag="' . esc_attr( $home['source_collection'] ) . '"'; }
$products_shortcode .= ']';
?>
<?php if ( ! empty( $hero['enabled'] ) ) : ?>
<section class="kuka-hero kuka-hero--<?php echo esc_attr( $hero['text_tone'] ?? 'light' ); ?> kuka-hero--<?php echo esc_attr( $hero['alignment'] ?? 'left' ); ?>" style="--hero-desktop:url('<?php echo esc_url( $desktop ); ?>');--hero-mobile:url('<?php echo esc_url( $mobile ); ?>')">
	<div class="kuka-hero__content"><p class="kuka-eyebrow"><?php echo esc_html( $hero['eyebrow'] ?? '' ); ?></p><h1><?php echo esc_html( $hero['title'] ?? '' ); ?></h1><p><?php echo esc_html( $hero['copy'] ?? '' ); ?></p><a class="kuka-button" href="<?php echo esc_url( kuka_island_content_url( $hero['button_url'] ?? '/magaza/' ) ); ?>"><?php echo esc_html( $hero['button_label'] ?? '' ); ?></a></div>
</section>
<?php endif; ?>
<?php if ( ! empty( $home['category_index_enabled'] ) && ! is_wp_error( $category_terms ) && $category_terms ) : ?>
<section class="kuka-category-intro kuka-section" aria-labelledby="kuka-category-title">
	<h2 id="kuka-category-title" class="kuka-eyebrow"><?php echo esc_html( $home['category_index_label'] ?? __( 'Formunu bul', 'kuka-island' ) ); ?></h2>
	<div class="kuka-category-index" aria-label="<?php echo esc_attr( $home['category_index_title'] ?? __( 'Ürün kategorileri', 'kuka-island' ) ); ?>">
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
<?php if ( ! empty( $home['new_arrivals_enabled'] ) ) : ?><section class="kuka-home-products kuka-home-products--<?php echo esc_attr( $home['presentation'] ?? 'grid' ); ?> kuka-section"><div class="kuka-section-heading"><div><p class="kuka-eyebrow"><?php esc_html_e( 'Koleksiyon', 'kuka-island' ); ?></p><h2><?php echo esc_html( $home['new_arrivals_title'] ?? '' ); ?></h2><p><?php echo esc_html( $home['new_arrivals_copy'] ?? '' ); ?></p></div><a class="kuka-text-link" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Tümünü gör', 'kuka-island' ); ?><span aria-hidden="true">↗</span></a></div><?php echo do_shortcode( $products_shortcode ); ?></section><?php endif; ?>
<?php if ( ! empty( $home['editorial_enabled'] ) ) : ?><section class="kuka-editorial kuka-section"><div class="kuka-editorial__image"><?php if ( ! empty( $home['editorial_video_id'] ) ) : ?><?php echo wp_video_shortcode( array( 'src' => wp_get_attachment_url( absint( $home['editorial_video_id'] ) ) ) ); ?><?php else : ?><?php echo wp_get_attachment_image( absint( $home['editorial_image_id'] ?? 0 ), 'full' ); ?><?php endif; ?></div><div><p class="kuka-eyebrow"><?php esc_html_e( 'Editoryal', 'kuka-island' ); ?></p><h2><?php echo esc_html( $home['editorial_title'] ?? '' ); ?></h2><p><?php echo esc_html( $home['editorial_copy'] ?? '' ); ?></p><a class="kuka-button" href="<?php echo esc_url( kuka_island_content_url( $home['editorial_url'] ?? '/hakkimizda/' ) ); ?>"><?php echo esc_html( $home['editorial_link_label'] ?? __( 'Hikâyeyi oku', 'kuka-island' ) ); ?></a></div></section><?php endif; ?>
<?php if ( ! empty( $home['manifesto_enabled'] ) ) : ?><section class="kuka-manifesto kuka-section"><p class="kuka-eyebrow"><?php esc_html_e( 'Manifesto', 'kuka-island' ); ?></p><h2><?php echo esc_html( $home['manifesto_title'] ?? '' ); ?></h2><p><?php echo esc_html( $home['manifesto_copy'] ?? '' ); ?></p></section><?php endif; ?>
<?php if ( ! empty( $home['services_enabled'] ) ) : ?><section class="kuka-services"><?php foreach ( array( 'service_1', 'service_2', 'service_3' ) as $key ) : ?><div><span aria-hidden="true">↗</span><p><?php echo esc_html( $home[ $key ] ?? '' ); ?></p></div><?php endforeach; ?></section><?php endif; ?>
<?php get_footer(); ?>
