<?php
/** Storefront. */
defined( 'ABSPATH' ) || exit;
get_header();
$content = kuka_island_content();
$hero = $content['hero'] ?? array();
$home = $content['home'] ?? array();
$whatsapp_url = kuka_island_whatsapp_url();
$desktop = ! empty( $hero['desktop_image_id'] ) ? wp_get_attachment_image_url( $hero['desktop_image_id'], 'full' ) : '';
$mobile = ! empty( $hero['mobile_image_id'] ) ? wp_get_attachment_image_url( $hero['mobile_image_id'], 'full' ) : $desktop;
$category_items = array_values( array_filter( kuka_island_category_navigation(), static fn( array $item ): bool => $item['home'] ) );
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
<?php if ( ! empty( $home['category_index_enabled'] ) && $category_items ) : ?>
<section class="kuka-category-intro kuka-section" aria-labelledby="kuka-category-title">
	<h2 id="kuka-category-title" class="kuka-eyebrow"><?php echo esc_html( $home['category_index_label'] ?? __( 'Formunu bul', 'kuka-island' ) ); ?></h2>
	<div class="kuka-category-index" aria-label="<?php echo esc_attr( $home['category_index_title'] ?? __( 'Ürün kategorileri', 'kuka-island' ) ); ?>">
		<?php foreach ( $category_items as $index => $category_item ) :
			$category_slug = basename( untrailingslashit( wp_parse_url( $category_item['url'], PHP_URL_PATH ) ?: '' ) );
			$category = get_term_by( 'slug', $category_slug, 'product_cat' );
			$product_ids = $category instanceof WP_Term ? get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $category->term_id ) ) ) ) : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$cut_names = $product_ids ? wp_get_object_terms( $product_ids, 'pa_kesim', array( 'fields' => 'names' ) ) : array();
			$cut_names = is_wp_error( $cut_names ) ? array() : array_values( array_unique( $cut_names ) );
			?>
			<a class="kuka-category-index__item" href="<?php echo esc_url( kuka_island_content_url( $category_item['url'] ) ); ?>">
				<span class="kuka-category-index__number"><?php echo esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
				<span class="kuka-category-index__name"><?php echo esc_html( $category_item['label'] ); ?></span>
				<span class="kuka-category-index__meta"><?php echo esc_html( $cut_names ? implode( ' · ', $cut_names ) : '—' ); ?></span>
				<span class="kuka-category-index__arrow" aria-hidden="true">↗</span>
			</a>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>
<?php if ( ! empty( $home['new_arrivals_enabled'] ) ) : ?><section class="kuka-home-products kuka-home-products--<?php echo esc_attr( $home['presentation'] ?? 'grid' ); ?> kuka-section"><div class="kuka-section-heading"><div><p class="kuka-eyebrow"><?php esc_html_e( 'Koleksiyon', 'kuka-island' ); ?></p><h2><?php echo esc_html( $home['new_arrivals_title'] ?? '' ); ?></h2><p><?php echo esc_html( $home['new_arrivals_copy'] ?? '' ); ?></p></div><a class="kuka-text-link" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Tümünü gör', 'kuka-island' ); ?><span aria-hidden="true">↗</span></a></div><?php echo do_shortcode( $products_shortcode ); ?></section><?php endif; ?>
<?php if ( ! empty( $home['editorial_enabled'] ) ) : ?><section class="kuka-editorial kuka-section"><div class="kuka-editorial__image"><?php if ( ! empty( $home['editorial_video_id'] ) ) : ?><?php echo wp_video_shortcode( array( 'src' => wp_get_attachment_url( absint( $home['editorial_video_id'] ) ) ) ); ?><?php else : ?><?php echo wp_get_attachment_image( absint( $home['editorial_image_id'] ?? 0 ), 'full' ); ?><?php endif; ?></div><div><p class="kuka-eyebrow"><?php esc_html_e( 'Editoryal', 'kuka-island' ); ?></p><h2><?php echo esc_html( $home['editorial_title'] ?? '' ); ?></h2><p><?php echo esc_html( $home['editorial_copy'] ?? '' ); ?></p><a class="kuka-button" href="<?php echo esc_url( kuka_island_content_url( $home['editorial_url'] ?? '/hakkimizda/' ) ); ?>"><?php echo esc_html( $home['editorial_link_label'] ?? __( 'Hikâyeyi oku', 'kuka-island' ) ); ?></a></div></section><?php endif; ?>
<?php if ( ! empty( $home['manifesto_enabled'] ) ) : ?><section class="kuka-manifesto kuka-section"><p class="kuka-eyebrow"><?php esc_html_e( 'Manifesto', 'kuka-island' ); ?></p><h2><?php echo esc_html( $home['manifesto_title'] ?? '' ); ?></h2><p><?php echo esc_html( $home['manifesto_copy'] ?? '' ); ?></p></section><?php endif; ?>
<?php if ( ! empty( $home['services_enabled'] ) ) : ?>
<section class="kuka-services" aria-label="<?php esc_attr_e( 'Servis güvenceleri', 'kuka-island' ); ?>">
<?php foreach ( array( 'service_1', 'service_2', 'service_3' ) as $service_index => $service_key ) :
	$service_title = (string) ( $home[ $service_key . '_title' ] ?? '' );
	$service_copy  = (string) ( $home[ $service_key . '_copy' ] ?? '' );
	$service_url   = (string) ( $home[ $service_key . '_url' ] ?? '' );
	if ( '' === trim( $service_title ) ) { continue; }
	if ( '' === $service_url ) {
		$service_url = 'service_3' === $service_key && $whatsapp_url ? $whatsapp_url : kuka_island_content_url( '/iletisim/' );
	} else {
		$service_url = kuka_island_content_url( $service_url );
	}
?>
<a class="kuka-services__cell" href="<?php echo esc_url( $service_url ); ?>">
	<span class="kuka-services__number"><?php echo esc_html( str_pad( (string) ( $service_index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
	<span class="kuka-services__title"><?php echo esc_html( $service_title ); ?></span>
	<span class="kuka-services__copy"><?php echo esc_html( $service_copy ); ?></span>
	<span class="kuka-services__arrow" aria-hidden="true">↗</span>
</a>
<?php endforeach; ?>
</section>
<?php endif; ?>
<?php get_footer(); ?>
