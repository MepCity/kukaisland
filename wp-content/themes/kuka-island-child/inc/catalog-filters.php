<?php
/**
 * Catalog filter presentation connected to WooCommerce's main product query.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

/** @return array<int, string> */
function kuka_island_filter_values( string $key ): array {
	$raw = isset( $_GET[ $key ] ) ? (array) wp_unslash( $_GET[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return array_values( array_filter( array_map( 'sanitize_title', $raw ) ) );
}

add_filter(
	'woocommerce_product_query_tax_query',
	static function ( array $tax_query ): array {
		foreach ( array( 'ki_cut' => 'pa_kesim', 'ki_color' => 'pa_renk', 'ki_size' => 'pa_beden' ) as $query_key => $taxonomy ) {
			$values = kuka_island_filter_values( $query_key );
			if ( $values ) {
				$tax_query[] = array( 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $values, 'operator' => 'IN' );
			}
		}
		return $tax_query;
	}
);

add_filter(
	'woocommerce_product_query_meta_query',
	static function ( array $meta_query ): array {
		if ( isset( $_GET['ki_stock'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['ki_stock'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$meta_query[] = array( 'key' => '_stock_status', 'value' => 'instock' );
		}
		return $meta_query;
	}
);

/** Render one taxonomy chip group. */
function kuka_island_filter_group( string $title, string $query_key, string $taxonomy, string $class ): void {
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'orderby' => 'menu_order' ) );
	if ( is_wp_error( $terms ) || ! $terms ) { return; }
	$chosen = kuka_island_filter_values( $query_key );
	?>
	<details class="kuka-filter-group" <?php echo $chosen ? 'open' : ''; ?>>
		<summary><span><?php echo esc_html( $title ); ?></span><span aria-hidden="true">⌄</span></summary>
		<fieldset><legend class="screen-reader-text"><?php echo esc_html( $title ); ?></legend><ul class="kuka-filter-options <?php echo esc_attr( $class ); ?>">
		<?php foreach ( $terms as $term ) : ?>
			<?php $swatch = 'pa_renk' === $taxonomy ? sanitize_hex_color( get_term_meta( $term->term_id, 'kuka_swatch_hex', true ) ) : ''; ?>
			<li><label><input class="kuka-sr-only" type="checkbox" name="<?php echo esc_attr( $query_key ); ?>[]" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( in_array( $term->slug, $chosen, true ) ); ?>><span class="kuka-filter-label" <?php echo $swatch ? 'style="--swatch-color:' . esc_attr( $swatch ) . '"' : ''; ?>><?php echo esc_html( $term->name ); ?></span><span class="kuka-filter-count"><?php echo esc_html( $term->count ); ?></span></label></li>
		<?php endforeach; ?>
		</ul></fieldset>
	</details>
	<?php
}

/** Render active filters as individually removable links. */
function kuka_island_active_filters(): void {
	$chips = array();
	foreach ( array( 'ki_cut' => 'pa_kesim', 'ki_color' => 'pa_renk', 'ki_size' => 'pa_beden' ) as $query_key => $taxonomy ) {
		foreach ( kuka_island_filter_values( $query_key ) as $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term ) {
				$current = kuka_island_filter_values( $query_key );
				$remaining = array_values( array_diff( $current, array( $slug ) ) );
				$url = $remaining ? add_query_arg( $query_key, $remaining ) : remove_query_arg( $query_key );
				$chips[] = array( 'label' => $term->name, 'url' => $url );
			}
		}
	}
	if ( isset( $_GET['ki_stock'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$chips[] = array( 'label' => __( 'Stokta', 'kuka-island' ), 'url' => remove_query_arg( 'ki_stock' ) );
	}
	if ( ! $chips ) { return; }
	?>
	<div class="kuka-active-filters" aria-label="<?php esc_attr_e( 'Aktif filtreler', 'kuka-island' ); ?>">
	<?php foreach ( $chips as $chip ) : ?><span><?php echo esc_html( $chip['label'] ); ?><a href="<?php echo esc_url( $chip['url'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( '%s filtresini kaldır', 'kuka-island' ), $chip['label'] ) ); ?>">×</a></span><?php endforeach; ?>
	</div>
	<?php
}

/** Catalog toolbar and drawer. */
function kuka_island_catalog_controls(): void {
	if ( ! is_shop() && ! is_product_taxonomy() ) { return; }
	$base_url = is_shop() ? wc_get_page_permalink( 'shop' ) : get_term_link( get_queried_object() );
	?>
	<div class="kuka-catalog-toolbar">
		<p><?php echo esc_html( sprintf( _n( '%d ürün', '%d ürün', wc_get_loop_prop( 'total' ), 'kuka-island' ), wc_get_loop_prop( 'total' ) ) ); ?></p>
		<button type="button" class="kuka-filter-trigger" data-panel-trigger="kuka-catalog-filters" aria-controls="kuka-catalog-filters" aria-expanded="false"><?php esc_html_e( 'Filtrele', 'kuka-island' ); ?> <span aria-hidden="true">+</span></button>
	</div>
	<?php kuka_island_active_filters(); ?>
	<div class="kuka-panel-overlay kuka-panel-overlay--light" data-panel-overlay hidden></div>
	<aside id="kuka-catalog-filters" class="kuka-catalog-filters" role="dialog" aria-modal="true" aria-labelledby="kuka-filter-title" aria-hidden="true" inert>
		<div class="kuka-panel-head"><span id="kuka-filter-title"><?php esc_html_e( 'Filtrele', 'kuka-island' ); ?></span><button class="kuka-icon-button" type="button" data-panel-close aria-label="<?php esc_attr_e( 'Filtreleri kapat', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'close' ); // phpcs:ignore ?></button></div>
		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="kuka-filter-form">
			<div class="kuka-filter-scroll">
				<label class="kuka-stock-toggle"><input type="checkbox" name="ki_stock" value="1" <?php checked( isset( $_GET['ki_stock'] ) ); // phpcs:ignore ?>><span><?php esc_html_e( 'Stokta olanlar', 'kuka-island' ); ?></span></label>
				<?php kuka_island_filter_group( __( 'Kesim', 'kuka-island' ), 'ki_cut', 'pa_kesim', 'kuka-filter-options--cuts' ); ?>
				<?php kuka_island_filter_group( __( 'Renk', 'kuka-island' ), 'ki_color', 'pa_renk', 'kuka-filter-options--colors' ); ?>
				<?php kuka_island_filter_group( __( 'Beden', 'kuka-island' ), 'ki_size', 'pa_beden', 'kuka-filter-options--sizes' ); ?>
			</div>
			<div class="kuka-filter-foot"><a href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Temizle', 'kuka-island' ); ?></a><button type="submit"><?php esc_html_e( 'Sonuçları gör', 'kuka-island' ); ?></button></div>
		</form>
	</aside>
	<?php
}
add_action( 'woocommerce_before_shop_loop', 'kuka_island_catalog_controls', 5 );
// WooCommerce prints its own result count on the same hook; the toolbar above
// already shows the count, so suppress the duplicate to keep a single toolbar row.
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 10 );

/** Render the archive title inside the WooCommerce content rhythm. */
function kuka_island_catalog_heading(): void {
	?>
	<header class="kuka-catalog-heading">
		<h1><?php echo esc_html( woocommerce_page_title( false ) ); ?></h1>
	</header>
	<?php
}
add_action( 'woocommerce_before_shop_loop', 'kuka_island_catalog_heading', 1 );

add_action(
	'woocommerce_no_products_found',
	static function (): void {
		echo '<div class="kuka-empty-catalog"><h2>' . esc_html__( 'Bu seçimde ürün bulunamadı.', 'kuka-island' ) . '</h2><p>' . esc_html__( 'Filtrelerden birini kaldırarak yeniden deneyin.', 'kuka-island' ) . '</p><a class="kuka-button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Filtreleri temizle', 'kuka-island' ) . '</a></div>';
	},
	5
);
