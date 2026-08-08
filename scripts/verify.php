<?php
/**
 * Machine-readable-ish acceptance snapshot.
 */

defined( 'WP_CLI' ) || exit( 1 );

$attribute_rows = array();
foreach ( wc_get_attribute_taxonomies() as $attribute ) {
	if ( in_array( $attribute->attribute_name, array( 'renk', 'beden', 'kesim' ), true ) ) {
		$taxonomy        = wc_attribute_taxonomy_name( $attribute->attribute_name );
		$attribute_rows[] = array(
			'name'  => $attribute->attribute_label,
			'slug'  => $taxonomy,
			'terms' => implode( ',', wp_list_pluck( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ), 'name' ) ),
		);
	}
}

WP_CLI::line( 'WP_LOCALE=' . get_locale() );
WP_CLI::line( 'WP_VERSION=' . get_bloginfo( 'version' ) );
WP_CLI::line( 'WOOCOMMERCE_VERSION=' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'missing' ) );
WP_CLI::line( 'BLOCKSY_VERSION=' . wp_get_theme( 'blocksy' )->get( 'Version' ) );
WP_CLI::line( 'IYZICO_VERSION=' . ( get_plugin_data( WP_PLUGIN_DIR . '/iyzico-woocommerce/woocommerce-gateway-iyzico.php' )['Version'] ?? 'missing' ) );
WP_CLI::line( 'TIMEZONE=' . wp_timezone_string() );
WP_CLI::line( 'CURRENCY=' . get_woocommerce_currency() );
WP_CLI::line( 'PRICE_FORMAT=' . wp_strip_all_tags( html_entity_decode( wc_price( 2890 ), ENT_QUOTES, 'UTF-8' ) ) );
WP_CLI::line( 'PRICE_SETTINGS=' . get_option( 'woocommerce_currency_pos' ) . '|' . get_option( 'woocommerce_price_thousand_sep' ) . '|' . get_option( 'woocommerce_price_decimal_sep' ) . '|' . get_option( 'woocommerce_price_num_decimals' ) );
WP_CLI::line( 'ACTIVE_THEME=' . wp_get_theme()->get_stylesheet() );
WP_CLI::line( 'HPOS=' . get_option( 'woocommerce_custom_orders_table_enabled' ) );
WP_CLI::line( 'GUEST_CHECKOUT=' . get_option( 'woocommerce_enable_guest_checkout' ) );
WP_CLI::line( 'STORE_VISIBILITY=' . ( 'yes' === get_option( 'woocommerce_coming_soon' ) ? 'coming-soon' : 'live' ) );
WP_CLI::line( 'MYACCOUNT_REGISTRATION=' . get_option( 'woocommerce_enable_myaccount_registration' ) );
WP_CLI::line( 'IMAGE_CROP=' . get_option( 'woocommerce_thumbnail_cropping_custom_width' ) . ':' . get_option( 'woocommerce_thumbnail_cropping_custom_height' ) );
WP_CLI::line( 'BIG_IMAGE_THRESHOLD=' . apply_filters( 'big_image_size_threshold', 2560, array(), '', 0 ) );
$thumb_size = wc_get_image_size( 'thumbnail' );
$single_size = wc_get_image_size( 'single' );
$gallery_size = wc_get_image_size( 'gallery_thumbnail' );
WP_CLI::line( sprintf( 'IMAGE_SIZES=card:%dx%d|single:%dx%d|gallery:%dx%d', $thumb_size['width'], $thumb_size['height'], $single_size['width'], $single_size['height'], $gallery_size['width'], $gallery_size['height'] ) );
WP_CLI::line( 'IYZICO_ACTIVE=' . ( is_plugin_active( 'iyzico-woocommerce/woocommerce-gateway-iyzico.php' ) ? 'yes' : 'no' ) );
WP_CLI::line( 'LOGINIZER_ACTIVE=' . ( is_plugin_active( 'loginizer/loginizer.php' ) ? 'yes' : 'no' ) );
WP_CLI::line( 'WC_GALLERY_SUPPORT=slider:' . ( current_theme_supports( 'wc-product-gallery-slider' ) ? 'yes' : 'no' ) . '|zoom:' . ( current_theme_supports( 'wc-product-gallery-zoom' ) ? 'yes' : 'no' ) . '|lightbox:' . ( current_theme_supports( 'wc-product-gallery-lightbox' ) ? 'yes' : 'no' ) );
foreach ( $attribute_rows as $row ) {
	WP_CLI::line( 'ATTRIBUTE=' . $row['name'] . '|' . $row['slug'] . '|' . $row['terms'] );
}

$products = wc_get_products( array( 'limit' => -1, 'type' => 'variable', 'return' => 'objects' ) );
WP_CLI::line( 'VARIABLE_PRODUCTS=' . count( $products ) );
foreach ( $products as $product ) {
	$valid_stock = true;
	$galleries   = true;
	$fields      = true;
	foreach ( array( '_kuka_material', '_kuka_care', '_kuka_fit', '_kuka_model_info', '_kuka_size_guide', '_kuka_seo_title', '_kuka_meta_description' ) as $meta_key ) {
		$fields = $fields && '' !== (string) $product->get_meta( $meta_key, true );
	}
	foreach ( $product->get_children() as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		$valid_stock = $valid_stock && $variation->get_sku() && $variation->managing_stock() && null !== $variation->get_low_stock_amount();
		$blocksy_gallery = $variation->get_meta( 'blocksy_post_meta_options', true );
		$galleries      = $galleries && 'custom' === ( $blocksy_gallery['gallery_source'] ?? '' ) && ! empty( $blocksy_gallery['images'] );
	}
	WP_CLI::line(
		sprintf(
			'PRODUCT=%s|variations:%d|stock_fields:%s|color_galleries:%s|required_fields:%s',
			$product->get_sku(),
			count( $product->get_children() ),
			$valid_stock ? 'yes' : 'no',
			$galleries ? 'yes' : 'no',
			$fields ? 'yes' : 'no'
		)
	);
}

$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0 ) );
WP_CLI::line( 'MAIN_CATEGORIES=' . implode( ',', wp_list_pluck( $categories, 'name' ) ) );
$turkey_zone = null;
foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
	if ( 'Türkiye' === $zone_data['zone_name'] ) {
		$turkey_zone = WC_Shipping_Zones::get_zone( (int) $zone_data['zone_id'] );
		break;
	}
}
WP_CLI::line( 'TURKEY_SHIPPING_METHODS=' . ( $turkey_zone ? implode( ',', wp_list_pluck( $turkey_zone->get_shipping_methods( true ), 'id' ) ) : 'missing' ) );

$swatches = get_terms( array( 'taxonomy' => 'pa_renk', 'hide_empty' => false ) );
foreach ( $swatches as $term ) {
	WP_CLI::line( 'SWATCH=' . $term->name . '|' . get_term_meta( $term->term_id, 'kuka_swatch_hex', true ) );
}

$site_content = class_exists( 'Kuka_Island_Core_Site_Appearance' ) ? Kuka_Island_Core_Site_Appearance::get() : array();
WP_CLI::line( 'SITE_APPEARANCE_GROUPS=' . implode( ',', array_keys( $site_content ) ) );
$pattern_registry = WP_Block_Patterns_Registry::get_instance();
WP_CLI::line( 'LOCKED_PATTERNS=' . (int) $pattern_registry->is_registered( 'kuka-island/editorial-story' ) . '/1|' . (int) $pattern_registry->is_registered( 'kuka-island/legal-section' ) . '/1' );
$manager = get_user_by( 'login', '[removed-manager-user]' );
WP_CLI::line( 'DAILY_MANAGER=' . ( $manager && in_array( 'shop_manager', (array) $manager->roles, true ) ? 'yes' : 'no' ) );
$required_pages = array( 'hakkimizda', 'iletisim', 'sik-sorulan-sorular', 'kargo-teslimat', 'iade-degisim', 'gizlilik-politikasi', 'cerez-politikasi', 'kvkk-aydinlatma-metni', 'kullanim-kosullari', 'on-bilgilendirme-formu', 'mesafeli-satis-sozlesmesi', 'acik-riza-metni', 'ticari-elektronik-ileti-onayi', 'beden-rehberi', 'siparis-takibi' );
$present_pages = array_filter( $required_pages, static fn( string $slug ): bool => (bool) get_page_by_path( $slug ) );
WP_CLI::line( sprintf( 'CONTENT_PAGES=%d/%d', count( $present_pages ), count( $required_pages ) ) );
// Müşterinin sekiz sözleşmesi. Metinler PDF'lerden birebir alındığı için artık
// taslak uyarısı taşımazlar; satıcı bloğu ise hepsinde tek kaynaktan basılır.
$legal_pages = array( 'mesafeli-satis-sozlesmesi', 'on-bilgilendirme-formu', 'kullanim-kosullari', 'iade-degisim', 'kvkk-aydinlatma-metni', 'gizlilik-politikasi', 'cerez-politikasi', 'acik-riza-metni' );
$draft_warnings = 0;
$central_company = 0;
foreach ( $legal_pages as $slug ) {
	$page = get_page_by_path( $slug );
	$content = $page ? (string) $page->post_content : '';
	$draft_warnings += str_contains( $content, '[kuka_legal_warning]' ) ? 1 : 0;
	$central_company += str_contains( $content, '[kuka_company_details]' ) ? 1 : 0;
}
WP_CLI::line( sprintf( 'LEGAL_DRAFT_WARNINGS=%d', $draft_warnings ) );
WP_CLI::line( sprintf( 'LEGAL_CENTRAL_COMPANY=%d/8', $central_company ) );
$hygiene_copy = (string) ( $site_content['commercial']['hygiene_copy'] ?? '' );
$return_page = get_page_by_path( 'iade-degisim' );
$return_html = $return_page ? do_shortcode( (string) $return_page->post_content ) : '';
$hygiene_fields = array_filter(
	array(
		$site_content['commercial']['hygiene_copy'] ?? '',
		$site_content['commercial']['hygiene_defect_copy'] ?? '',
		$site_content['commercial']['hygiene_try_on_copy'] ?? '',
	)
);
WP_CLI::line( 'HYGIENE_SINGLE_SOURCE=' . ( 3 === count( $hygiene_fields ) && '' !== $hygiene_copy && str_contains( wp_strip_all_tags( $return_html ), $hygiene_copy ) ? 'yes' : 'no' ) );
WP_CLI::line( 'LEGAL_PAGE_STATUS=' . implode( '|', array_map( static fn( string $slug ): string => $slug . ':' . ( get_page_by_path( $slug )->post_status ?? 'missing' ), $legal_pages ) ) );
WP_CLI::line( 'MEMBERSHIP_ENABLED=' . ( ! empty( $site_content['membership']['enabled'] ) ? 'yes' : 'no' ) );
WP_CLI::line( 'ACCOUNT_OPTIONS=guest:' . get_option( 'woocommerce_enable_guest_checkout' ) . '|checkout_signup:' . get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) . '|checkout_login:' . get_option( 'woocommerce_enable_checkout_login_reminder' ) . '|myaccount_registration:' . get_option( 'woocommerce_enable_myaccount_registration' ) . '|users_can_register:' . get_option( 'users_can_register' ) );
WP_CLI::line( 'SOCIAL_LOGIN_PLUGIN=' . ( is_dir( WP_PLUGIN_DIR . '/nextend-facebook-connect' ) ? 'present' : 'absent' ) );
$tracking_test_order = new WC_Order();
$tracking_test_order->set_billing_email( 'guest@example.test' );
ob_start();
( new Kuka_Island_Core_Membership() )->email_tracking_link( $tracking_test_order, false );
$tracking_email_html = (string) ob_get_clean();
WP_CLI::line( 'EMAIL_TRACKING_LINK=' . ( str_contains( $tracking_email_html, 'orderid=0' ) && str_contains( $tracking_email_html, 'order_email=guest@example.test' ) ? 'personalized' : 'missing' ) );
$tracking_page = get_page_by_path( 'siparis-takibi' );
WP_CLI::line( 'ORDER_TRACKING_PAGE=' . ( $tracking_page && str_contains( (string) $tracking_page->post_content, '[woocommerce_order_tracking]' ) ? 'ready' : 'missing' ) );
$terms_page = get_page_by_path( 'kullanim-kosullari' );
WP_CLI::line( 'MEMBERSHIP_TERMS_STATUS=' . ( $terms_page->post_status ?? 'missing' ) );
$account_page_id = (int) get_option( 'woocommerce_myaccount_page_id' );
WP_CLI::line( 'MYACCOUNT_PAGE=' . ( $account_page_id > 0 && get_post( $account_page_id ) ? 'kept' : 'missing' ) );
$size_html = do_shortcode( '[kuka_size_guide]' );
WP_CLI::line( 'SIZE_GUIDE_TABLES=' . substr_count( $size_html, '<table>' ) );
WP_CLI::line( 'INSTAGRAM_LINK=' . ( str_contains( (string) ( $site_content['brand']['social_links'] ?? '' ), 'https://www.instagram.com/kukaisland' ) ? 'yes' : 'no' ) );
WP_CLI::line( 'COMMERCIAL_CONTENT=' . implode( '|', array( $site_content['commercial']['flat_shipping_fee'] ?? '', $site_content['commercial']['free_shipping_threshold'] ?? '', $site_content['commercial']['cayma_hakki_gun'] ?? '' ) ) );
WP_CLI::line( 'GUEST_SESSION_HOURS=' . absint( $site_content['membership']['guest_session_hours'] ?? 0 ) );
WP_CLI::line( 'RETIRED_PANEL_FIELDS=' . implode( ',', array_values( array_filter( array( 'return_period_days', 'exchange_copy' ), static fn( string $key ): bool => isset( $site_content['commercial'][ $key ] ) ) ) ) );
WP_CLI::line( 'SIZE_TERMS=' . implode( ',', wp_list_pluck( get_terms( array( 'taxonomy' => 'pa_beden', 'hide_empty' => false, 'orderby' => 'term_id' ) ), 'name' ) ) );
WP_CLI::line( 'TYPOGRAPHY_TEST_PAGE=' . ( get_page_by_path( 'tipografi-testi' ) ? 'yes' : 'no' ) );
$menu_rows = function_exists( 'kuka_island_header_menu' ) ? wp_list_pluck( kuka_island_header_menu(), 'label' ) : array();
WP_CLI::line( 'PRIMARY_MENU=' . implode( '|', $menu_rows ) );
WP_CLI::line( 'PRIMARY_MENU_COUNT=' . count( $menu_rows ) );
$low_stock = wc_get_products( array( 'type' => 'variation', 'limit' => -1, 'stock_quantity' => 2, 'return' => 'ids' ) );
$out_stock = wc_get_products( array( 'type' => 'variation', 'limit' => -1, 'stock_status' => 'outofstock', 'return' => 'ids' ) );
WP_CLI::line( 'LOW_STOCK_VARIATIONS=' . count( $low_stock ) );
WP_CLI::line( 'OUT_OF_STOCK_VARIATIONS=' . count( $out_stock ) );
WP_CLI::line( 'SHOP_PER_PAGE=' . apply_filters( 'loop_shop_per_page', wc_get_default_products_per_row() * wc_get_default_product_rows_per_page() ) );
WP_CLI::line( 'PERMALINK_STRUCTURE=' . get_option( 'permalink_structure' ) );
WP_CLI::line( 'CHECKOUT_CLASSIC=' . ( str_contains( (string) get_post_field( 'post_content', wc_get_page_id( 'checkout' ) ), '[woocommerce_checkout]' ) ? 'yes' : 'no' ) );
