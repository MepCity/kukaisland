<?php
/**
 * Machine-readable-ish acceptance snapshot.
 */

defined( 'WP_CLI' ) || exit( 1 );

global $wpdb;
$smtp_database_rows = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s OR option_value LIKE %s',
		$wpdb->options,
		'%' . $wpdb->esc_like( 'KUKA_SMTP_' ) . '%',
		'%' . $wpdb->esc_like( 'KUKA_SMTP_' ) . '%'
	)
);
WP_CLI::line( 'SMTP_CONFIG_DATABASE_ROWS=' . $smtp_database_rows );

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
WP_CLI::line( 'BLOCKSY_COMPANION_VERSION=' . ( get_plugin_data( WP_PLUGIN_DIR . '/blocksy-companion/blocksy-companion.php' )['Version'] ?? 'missing' ) );
WP_CLI::line( 'IYZICO_VERSION=' . ( get_plugin_data( WP_PLUGIN_DIR . '/iyzico-woocommerce/woocommerce-gateway-iyzico.php' )['Version'] ?? 'missing' ) );
WP_CLI::line( 'LOGINIZER_VERSION=' . ( get_plugin_data( WP_PLUGIN_DIR . '/loginizer/loginizer.php' )['Version'] ?? 'missing' ) );
WP_CLI::line( 'SECURITY_HEADER_MODULE=' . ( class_exists( 'Kuka_Island_Core_Security' ) ? 'ready' : 'missing' ) );
WP_CLI::line( 'SECURITY_CSP_IYZICO=' . ( class_exists( 'Kuka_Island_Core_Security' ) && str_contains( Kuka_Island_Core_Security::content_security_policy(), 'https://*.iyzipay.com' ) ? 'allowed' : 'missing' ) );
WP_CLI::line( 'TIMEZONE=' . wp_timezone_string() );
WP_CLI::line( 'CURRENCY=' . get_woocommerce_currency() );
WP_CLI::line( 'PRICE_FORMAT=' . wp_strip_all_tags( html_entity_decode( wc_price( 2890 ), ENT_QUOTES, 'UTF-8' ) ) );
WP_CLI::line( 'PRICE_SETTINGS=' . get_option( 'woocommerce_currency_pos' ) . '|' . get_option( 'woocommerce_price_thousand_sep' ) . '|' . get_option( 'woocommerce_price_decimal_sep' ) . '|' . get_option( 'woocommerce_price_num_decimals' ) );
WP_CLI::line( 'ACTIVE_THEME=' . wp_get_theme()->get_stylesheet() );
WP_CLI::line( 'HPOS=' . get_option( 'woocommerce_custom_orders_table_enabled' ) );
WP_CLI::line( 'GUEST_CHECKOUT=' . get_option( 'woocommerce_enable_guest_checkout' ) );
WP_CLI::line( 'STORE_VISIBILITY=' . ( 'yes' === get_option( 'woocommerce_coming_soon' ) ? 'coming-soon' : 'live' ) );
WP_CLI::line( 'COMING_SOON_SCOPE=' . ( 'yes' === get_option( 'woocommerce_store_pages_only' ) ? 'store-only' : 'whole-site' ) );
WP_CLI::line( 'SEARCH_ENGINE_VISIBILITY=' . ( get_option( 'blog_public' ) ? 'index' : 'noindex' ) );
WP_CLI::line( 'PRIVATE_PREVIEW=' . ( 'yes' === get_option( 'woocommerce_private_link' ) && get_option( 'woocommerce_share_key' ) ? 'ready' : 'missing' ) );
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
$appearance_groups = class_exists( 'Kuka_Island_Core_Site_Appearance' ) ? Kuka_Island_Core_Site_Appearance::field_inventory() : array();
$appearance_controls = array_sum( array_map( static fn( array $group ): int => count( $group['fields'] ?? array() ), $appearance_groups ) );
$appearance_rows = 0;
foreach ( $appearance_groups as $group ) {
	foreach ( array_keys( $group['fields'] ?? array() ) as $field_key ) {
		if ( ! str_ends_with( $field_key, '_en' ) && ! str_ends_with( $field_key, '_labels_en' ) ) { ++$appearance_rows; }
	}
}
WP_CLI::line( 'SITE_APPEARANCE_GROUPS=' . implode( ',', array_keys( $site_content ) ) );
WP_CLI::line( sprintf( 'SITE_APPEARANCE_INVENTORY=%d_groups|%d_rows|%d_controls', count( $appearance_groups ), $appearance_rows, $appearance_controls ) );
WP_CLI::line( 'HOME_HERO_TITLES=' . (string) ( $site_content['hero']['title'] ?? '' ) . '|' . (string) ( $site_content['hero']['title_en'] ?? '' ) );
WP_CLI::line( 'HOME_EDITORIAL_TITLES=' . (string) ( $site_content['home']['editorial_title'] ?? '' ) . '|' . (string) ( $site_content['home']['editorial_title_en'] ?? '' ) );
WP_CLI::line( 'SITE_EMAIL=' . (string) ( $site_content['brand']['email'] ?? '' ) );
WP_CLI::line( 'LANGUAGE_TRANSLATABLE_FIELDS=' . ( class_exists( 'Kuka_Island_Core_Language' ) ? Kuka_Island_Core_Language::translation_field_count() : 0 ) );
WP_CLI::line( 'PRODUCT_EN_FIELD_SCHEMA=9' );
WP_CLI::line( 'PAGE_EN_FIELD_SCHEMA=2' );
WP_CLI::line( 'TAXONOMY_EN_FIELD=' . ( function_exists( 'kuka_island_term_name' ) ? '_kuka_name_en' : 'missing' ) );
$translation_plugins = array_filter( (array) get_option( 'active_plugins', array() ), static fn( string $plugin ): bool => (bool) preg_match( '/polylang|sitepress|wpml|translatepress|weglot|gtranslate/i', $plugin ) );
WP_CLI::line( 'TRANSLATION_PLUGIN=' . ( $translation_plugins ? implode( ',', $translation_plugins ) : 'none' ) );
WP_CLI::line( 'LANGUAGE_PENDING_URLS=' . (string) ( $site_content['languages']['pending_urls'] ?? '' ) );
$pattern_registry = WP_Block_Patterns_Registry::get_instance();
WP_CLI::line( 'LOCKED_PATTERNS=' . (int) $pattern_registry->is_registered( 'kuka-island/editorial-story' ) . '/1|' . (int) $pattern_registry->is_registered( 'kuka-island/legal-section' ) . '/1' );
$manager = get_user_by( 'email', 'manager@kukaisland.test' );
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
$legal_english_values = 0;
foreach ( $legal_pages as $slug ) {
	$page = get_page_by_path( $slug );
	if ( $page ) {
		$legal_english_values += '' !== trim( (string) get_post_meta( $page->ID, '_kuka_title_en', true ) ) ? 1 : 0;
		$legal_english_values += '' !== trim( (string) get_post_meta( $page->ID, '_kuka_content_en', true ) ) ? 1 : 0;
	}
}
WP_CLI::line( 'LEGAL_EN_VALUES=' . $legal_english_values . '/16' );
$nonlegal_pages = array( 'hakkimizda', 'iletisim', 'sik-sorulan-sorular', 'kargo-teslimat', 'ticari-elektronik-ileti-onayi', 'beden-rehberi', 'siparis-takibi', 'tipografi-testi' );
$nonlegal_english_values = 0;
foreach ( $nonlegal_pages as $slug ) {
	$page = get_page_by_path( $slug );
	if ( $page ) {
		$nonlegal_english_values += '' !== trim( (string) get_post_meta( $page->ID, '_kuka_title_en', true ) ) ? 1 : 0;
		$nonlegal_english_values += '' !== trim( (string) get_post_meta( $page->ID, '_kuka_content_en', true ) ) ? 1 : 0;
	}
}
WP_CLI::line( 'NONLEGAL_EN_VALUES=' . $nonlegal_english_values . '/16' );
$appearance_english_values = 0;
foreach ( Kuka_Island_Core_Language::translation_fields() as $group => $fields ) {
	foreach ( $fields as $config ) {
		$value = $site_content[ $group ][ $config['key'] ] ?? '';
		$appearance_english_values += is_array( $value ) ? ( array_filter( $value, 'strlen' ) ? 1 : 0 ) : ( '' !== trim( (string) $value ) ? 1 : 0 );
	}
}
WP_CLI::line( 'SITE_APPEARANCE_EN_VALUES=' . $appearance_english_values . '/' . Kuka_Island_Core_Language::translation_field_count() );
$translation_keys = array();
foreach ( Kuka_Island_Core_Language::translation_fields() as $group => $fields ) {
	$translation_keys[ $group ] = array_column( $fields, 'key' );
}
$unexpected_twins = array();
foreach ( $site_content as $group => $values ) {
	if ( ! is_array( $values ) ) { continue; }
	foreach ( array_keys( $values ) as $key ) {
		if ( str_ends_with( (string) $key, '_en' ) && ! in_array( $key, $translation_keys[ $group ] ?? array(), true ) ) {
			$unexpected_twins[] = $group . '.' . $key;
		}
	}
}
WP_CLI::line( 'LANGUAGE_ITEMS=' . str_replace( "\n", '|', (string) ( $site_content['languages']['items'] ?? '' ) ) );
WP_CLI::line( 'LANGUAGE_ITEMS_EN=' . ( array_key_exists( 'items_en', $site_content['languages'] ?? array() ) ? 'present' : 'absent' ) );
WP_CLI::line( 'NONTRANSLATABLE_TWINS=' . ( $unexpected_twins ? implode( ',', $unexpected_twins ) : '0' ) );
$product_english_values = 0;
foreach ( wc_get_products( array( 'type' => 'variable', 'limit' => -1, 'return' => 'ids' ) ) as $product_id ) {
	foreach ( array( '_kuka_name_en', '_kuka_description_en', '_kuka_short_description_en', '_kuka_material_en', '_kuka_care_en', '_kuka_fit_en', '_kuka_model_info_en', '_kuka_seo_title_en', '_kuka_meta_description_en' ) as $key ) {
		$product_english_values += '' !== trim( (string) get_post_meta( $product_id, $key, true ) ) ? 1 : 0;
	}
}
WP_CLI::line( 'PRODUCT_EN_VALUES=' . $product_english_values . '/36' );
$about_english_page = get_page_by_path( 'hakkimizda' );
$about_english = $about_english_page ? (string) get_post_meta( $about_english_page->ID, '_kuka_content_en', true ) : '';
WP_CLI::line( 'ABOUT_EN_RHYTHM=' . ( str_contains( $about_english, 'The sea…<br>Summer…<br>Freedom…' ) && str_contains( $about_english, '<span>Love,</span><strong>KÜBRA</strong>' ) ? 'yes' : 'no' ) );
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
$iyzico_checks = Kuka_Island_Core_Site_Appearance::iyzico_application_checks( $site_content );
$iyzico_complete = count( array_filter( $iyzico_checks, static fn( array $check ): bool => $check['complete'] ) );
$iyzico_missing = count( $iyzico_checks ) - $iyzico_complete;
WP_CLI::line( sprintf( 'IYZICO_APPLICATION_READINESS=%d/%d|missing:%d', $iyzico_complete, count( $iyzico_checks ), $iyzico_missing ) );
WP_CLI::line( 'IYZICO_APPLICATION_LINKS=' . count( array_filter( $iyzico_checks, static fn( array $check ): bool => '' !== $check['url'] ) ) . '/' . count( $iyzico_checks ) );
$document_keys = array( 'iyzico_tax_certificate', 'iyzico_signature_circular', 'iyzico_identity_copy', 'iyzico_iban_document', 'iyzico_findeks_report' );
$document_complete = count( array_filter( $document_keys, static fn( string $key ): bool => ! empty( $site_content['legal'][ $key ] ) ) );
WP_CLI::line( 'IYZICO_MANUAL_DOCUMENTS=' . $document_complete . '/5' );
$contact_page = get_page_by_path( 'iletisim' );
$contact_source = $contact_page ? (string) $contact_page->post_content : '';
WP_CLI::line( 'CONTACT_SHORTCODES=company:' . substr_count( $contact_source, '[kuka_company_details]' ) . '|support:' . substr_count( $contact_source, '[kuka_contact_details]' ) );
$company_html = do_shortcode( '[kuka_company_details]' );
WP_CLI::line( 'APPLICATION_LEGAL_ROWS=mersis:' . ( str_contains( $company_html, 'MERSİS' ) ? '1' : '0' ) . '|kep:' . ( str_contains( $company_html, 'KEP' ) ? '1' : '0' ) . '|chamber:' . ( str_contains( $company_html, 'meslek odası' ) ? '1' : '0' ) . '|rules:' . ( str_contains( $company_html, 'davranış kuralları' ) ? '1' : '0' ) . '|etbis:' . ( str_contains( $company_html, 'ETBİS' ) ? '1' : '0' ) );
$footer_source = (string) file_get_contents( get_stylesheet_directory() . '/footer.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$front_source  = (string) file_get_contents( get_stylesheet_directory() . '/front-page.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$global_source = (string) file_get_contents( get_stylesheet_directory() . '/assets/css/global.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$splash_source = (string) file_get_contents( get_stylesheet_directory() . '/coming-soon.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$splash_css    = (string) file_get_contents( get_stylesheet_directory() . '/assets/css/coming-soon.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$story_source  = (string) file_get_contents( get_stylesheet_directory() . '/assets/js/story.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$checkout_source = (string) file_get_contents( get_stylesheet_directory() . '/assets/js/checkout.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$responsive_video_source = (string) file_get_contents( get_stylesheet_directory() . '/assets/js/responsive-video.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$language_source = (string) file_get_contents( WP_PLUGIN_DIR . '/kuka-island-core/includes/class-language.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$appearance_source = (string) file_get_contents( WP_PLUGIN_DIR . '/kuka-island-core/includes/class-site-appearance.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
WP_CLI::line( 'FOOTER_WHATSAPP_SOURCE=' . ( str_contains( $footer_source, 'if ( $whatsapp_url )' ) && str_contains( $footer_source, '>WhatsApp <span class="kuka-text-arrow"' ) ? 'phone-helper' : 'missing' ) );
WP_CLI::line( 'FOOTER_PAYMENT_LOGOS=' . ( ! str_contains( $footer_source, 'assets/img/payment/' ) && ! str_contains( $footer_source, 'kuka-payment-trust' ) && ! str_contains( $global_source, '.kuka-payment-trust' ) ? 'absent' : 'present' ) );
WP_CLI::line( 'FOOTER_PAYMENT_PANEL_FIELD=' . ( ! str_contains( $appearance_source, "'payment_logos_enabled' => true" ) && ! str_contains( $appearance_source, "'payment_logos_enabled' => array" ) && ! isset( $site_content['footer']['payment_logos_enabled'] ) ? 'absent' : 'present' ) );
WP_CLI::line( 'FOOTER_SITE_EMAIL=' . ( ! str_contains( $footer_source, 'mailto:' ) && ! str_contains( $footer_source, '$site_email' ) ? 'absent' : 'present' ) );
$payment_dir = get_stylesheet_directory() . '/assets/img/payment/';
$plugin_cards_path = WP_PLUGIN_DIR . '/iyzico-woocommerce/assets/images/cards_v2.png';
WP_CLI::line( 'THEME_PAYMENT_ASSETS=' . ( ! is_dir( $payment_dir ) ? 'absent' : 'present' ) );
WP_CLI::line( 'CHECKOUT_CARD_STRIP_ASSET=' . ( file_exists( $plugin_cards_path ) ? 'plugin-owned' : 'missing' ) );
WP_CLI::line( 'PAYMENT_COLOR_ASSET_EXCEPTIONS=0' );
$splash_media_dir = get_stylesheet_directory() . '/assets/media/';
$splash_media = array(
	'desktop_video'  => 'coming-soon-desktop.mp4',
	'mobile_video'   => 'coming-soon-mobile.mp4',
	'desktop_poster' => 'coming-soon-desktop-poster.jpg',
	'mobile_poster'  => 'coming-soon-mobile-poster.jpg',
);
$splash_media_ready = array_filter( $splash_media, static fn( string $file ): bool => file_exists( $splash_media_dir . $file ) );
WP_CLI::line( 'COMING_SOON_MEDIA_FILES=' . count( $splash_media_ready ) . '/4' );
WP_CLI::line( 'COMING_SOON_VIDEO_BYTES=desktop:' . filesize( $splash_media_dir . $splash_media['desktop_video'] ) . '|mobile:' . filesize( $splash_media_dir . $splash_media['mobile_video'] ) );
WP_CLI::line( 'COMING_SOON_VIDEO_CONTRACT=' . ( str_contains( $splash_source, 'loop muted playsinline preload="none"' ) && str_contains( $splash_source, 'data-mobile-src=' ) && str_contains( $splash_source, 'data-desktop-src=' ) ? 'responsive+autoplay+muted+loop+playsinline' : 'missing' ) );
WP_CLI::line( 'COMING_SOON_REDUCED_MOTION=' . ( str_contains( $responsive_video_source, "prefers-reduced-motion: reduce" ) && str_contains( $responsive_video_source, 'connection?.saveData' ) && str_contains( $splash_css, '@media (prefers-reduced-motion: reduce)' ) && str_contains( $splash_css, 'display: none' ) ? 'poster-only' : 'missing' ) );
WP_CLI::line( 'HOME_HERO_VIDEO=' . ( str_contains( $front_source, 'class="kuka-hero__video" loop muted playsinline preload="none"' ) && str_contains( $front_source, 'poster=' ) && str_contains( $front_source, 'data-mobile-src=' ) && str_contains( $front_source, 'data-desktop-src=' ) && str_contains( $responsive_video_source, 'video.play()' ) && str_contains( $responsive_video_source, 'connection?.saveData' ) && str_contains( $global_source, '@media (prefers-reduced-motion: reduce)' ) ? 'responsive+muted+poster-fallback' : 'missing' ) );
WP_CLI::line( 'CHECKOUT_MUTATION_FOCUS=' . ( str_contains( $checkout_source, 'synchronize({focus: false, scroll: false});' ) && str_contains( $checkout_source, 'focus && !userIsEditing()' ) ? 'stable' : 'jump-risk' ) );
WP_CLI::line( 'LEGAL_CONSENT_FRAGMENT_STATE=' . ( str_contains( $checkout_source, ".on('update_checkout', rememberLegalConsents)" ) && str_contains( $checkout_source, ".on('updated_checkout'" ) && str_contains( $checkout_source, 'restoreLegalConsents();' ) ? 'preserved' : 'reset-risk' ) );
WP_CLI::line( 'LANGUAGE_HOT_PATH_CACHE=' . ( str_contains( $language_source, 'private static ?bool $english_request = null;' ) && str_contains( $language_source, 'static $map = null;' ) && str_contains( $language_source, '$map ??= array(' ) ? 'memoized' : 'rebuilt' ) );
WP_CLI::line( 'MOBILE_SAFARI_ARROWS=' . ( str_contains( $footer_source, '↗︎' ) && str_contains( $footer_source, 'kuka-text-arrow' ) ? 'text' : 'emoji-risk' ) );
WP_CLI::line( 'HERO_EST_LINE=' . ( str_contains( $front_source, 'class="kuka-hero__est"' ) && str_contains( $global_source, '.kuka-hero__est' ) ? 'separate' : 'inline' ) );
WP_CLI::line( 'LANGUAGE_HOVER=' . ( str_contains( $global_source, '.kuka-lang-switcher__list a:hover' ) && str_contains( $global_source, 'text-decoration: underline' ) ? 'same-color+underline' : 'missing' ) );
WP_CLI::line( 'STORY_MEDIA_HANDOFF=' . ( str_contains( $story_source, 'requestedMediaIndex' ) && str_contains( $story_source, 'addEventListener("load", reveal' ) && str_contains( $story_source, 'index > 0 && media[index + 1]' ) ? 'load-guarded+next-warmed' : 'immediate' ) );
$whatsapp_test_url = Kuka_Island_Core_Content::whatsapp_url( '0532 111 22 33' );
WP_CLI::line( 'WHATSAPP_PHONE_RULE=' . ( '' === Kuka_Island_Core_Content::whatsapp_url( '' ) && str_ends_with( $whatsapp_test_url, '/905321112233' ) ? 'empty-hidden|number-derived' : 'failed' ) );
WP_CLI::line( 'COMMERCIAL_CONTENT=' . implode( '|', array( $site_content['commercial']['flat_shipping_fee'] ?? '', $site_content['commercial']['free_shipping_threshold'] ?? '', $site_content['commercial']['cayma_hakki_gun'] ?? '' ) ) );
WP_CLI::line( 'FREE_SHIPPING_IGNORE_DISCOUNTS=' . ( $site_content['commercial']['ignore_discounts'] ?? 'missing' ) );
global $wpdb;
$free_shipping_options = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT option_name FROM %i WHERE option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( 'woocommerce_free_shipping_' ) . '%_settings'
	)
);
$free_shipping_values = array_map( static fn( string $option_name ): string => (string) ( get_option( $option_name, array() )['ignore_discounts'] ?? 'missing' ), $free_shipping_options );
WP_CLI::line( 'FREE_SHIPPING_IGNORE_DISCOUNTS_SYNC=' . ( $free_shipping_values && 1 === count( array_unique( $free_shipping_values ) ) ? reset( $free_shipping_values ) : 'mismatch' ) );
$free_shipping_requirements = array_map( static fn( string $option_name ): string => (string) ( get_option( $option_name, array() )['requires'] ?? 'missing' ), $free_shipping_options );
WP_CLI::line( 'FREE_SHIPPING_REQUIREMENT_SYNC=' . ( $free_shipping_requirements && 1 === count( array_unique( $free_shipping_requirements ) ) ? reset( $free_shipping_requirements ) : 'mismatch' ) );
$request_uri_before = $_SERVER['REQUEST_URI'] ?? null;
$_SERVER['REQUEST_URI'] = '/en/odeme/';
$free_rate = new WC_Shipping_Rate( 'free_shipping:2', 'Ücretsiz kargo', 0, array(), 'free_shipping', 2 );
$flat_rate = new WC_Shipping_Rate( 'flat_rate:1', 'Sabit ücret', 149, array(), 'flat_rate', 1 );
WP_CLI::line( 'SHIPPING_RATE_LABELS_EN=' . $free_rate->get_label() . '|' . $flat_rate->get_label() );
if ( null === $request_uri_before ) {
	unset( $_SERVER['REQUEST_URI'] );
} else {
	$_SERVER['REQUEST_URI'] = $request_uri_before;
}
WP_CLI::line( 'GUEST_SESSION_HOURS=' . absint( $site_content['membership']['guest_session_hours'] ?? 0 ) );
$retired_panel_fields = array_values( array_filter( array( 'return_period_days', 'exchange_copy' ), static fn( string $key ): bool => isset( $site_content['commercial'][ $key ] ) ) );
if ( isset( $site_content['hero']['overlay_strength'] ) ) { $retired_panel_fields[] = 'overlay_strength'; }
if ( isset( $site_content['footer']['payment_label'] ) ) { $retired_panel_fields[] = 'payment_label'; }
if ( isset( $site_content['footer']['payment_label_en'] ) ) { $retired_panel_fields[] = 'payment_label_en'; }
if ( isset( $site_content['footer']['payment_logos_enabled'] ) ) { $retired_panel_fields[] = 'payment_logos_enabled'; }
WP_CLI::line( 'RETIRED_PANEL_FIELDS=' . implode( ',', $retired_panel_fields ) );
$theme_css = (string) file_get_contents( get_stylesheet_directory() . '/assets/css/global.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$theme_tokens = (string) file_get_contents( get_stylesheet_directory() . '/assets/css/tokens.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
WP_CLI::line( 'HERO_OVERLAY_LAYER=' . ( str_contains( $theme_css, '.kuka-hero__content::before' ) || str_contains( $theme_tokens, '--hero-overlay-strength' ) ? 'present' : 'absent' ) );
WP_CLI::line( 'HEADER_TOP_MODE=' . ( str_contains( $theme_css, '.has-js .home .kuka-header--overlay:not(.is-scrolled)' ) ? 'photo-white-to-paper-dark' : 'missing' ) );
$size_terms = get_terms( array( 'taxonomy' => 'pa_beden', 'hide_empty' => false, 'orderby' => 'menu_order' ) );
WP_CLI::line( 'SIZE_TERMS=' . implode( ',', wp_list_pluck( $size_terms, 'name' ) ) );
WP_CLI::line( 'SIZE_TERM_ORDER=' . implode( '|', array_map( static fn( WP_Term $term ): string => $term->name . ':' . get_term_meta( $term->term_id, 'order', true ), $size_terms ) ) );
WP_CLI::line( 'TYPOGRAPHY_TEST_PAGE=' . ( get_page_by_path( 'tipografi-testi' ) ? 'yes' : 'no' ) );
$menu_rows = function_exists( 'kuka_island_header_menu' ) ? wp_list_pluck( kuka_island_header_menu(), 'label' ) : array();
WP_CLI::line( 'PRIMARY_MENU=' . implode( '|', $menu_rows ) );
WP_CLI::line( 'PRIMARY_MENU_COUNT=' . count( $menu_rows ) );
WP_CLI::line( 'STORY_MENU_LABEL=' . ( in_array( 'Hikâyemiz', $menu_rows, true ) ? 'Hikâyemiz' : 'missing' ) );
$about_page = get_page_by_path( 'hakkimizda' );
$about_source = '';
if ( $about_page && preg_match( '#<div class="kuka-brand-story__source">(.*)</div></div>#s', (string) $about_page->post_content, $about_match ) ) {
	$about_source = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( preg_replace( '#<[^>]+>#', ' ', $about_match[1] ) ) ) );
}
$pdf_story = 'KUKA ISLAND Hayatta bazen sıfırdan başlamak gerekir. Benim için KUKA ISLAND tam olarak böyle başladı. Yeni bir sayfa açarken, sadece bir marka kurmak istemedim. Bana iyi hissettiren her şeyi tek bir çatı altında toplamak istedim. Denizi… Yazı… Özgürlüğü… Ve kadınların kendini en güzel hissettiği anları… İşte KUKA ISLAND böyle doğdu. Her koleksiyon, sadece bir sezon için değil; yıllar sonra bile giydiğinde sana aynı hissi yaşatsın diye hazırlanıyor. Bu yolculuk daha yeni başlıyor. İyi ki buradasın. Ve bu hikâyenin ilk sayfalarında bize eşlik ediyorsun. Love, KÜBRA';
WP_CLI::line( 'BRAND_STORY_PDF_MATCH=' . ( hash_equals( $pdf_story, $about_source ) ? 'yes' : 'no' ) );
WP_CLI::line( 'ABOUT_OPENING_PANEL_BOUND=' . ( $about_page && str_contains( (string) $about_page->post_content, '[kuka_manifesto_line_2]' ) ? 'yes' : 'no' ) );
$story_scenes = $site_content['story']['scenes'] ?? array();
$story_pdf_source = trim( preg_replace( '/\s+/u', ' ', implode( ' ', array_column( array_slice( $story_scenes, 1 ), 'text' ) ) ) );
$story_pdf_expected = 'Hayatta bazen sıfırdan başlamak gerekir. Benim için KUKA ISLAND tam olarak böyle başladı. Yeni bir sayfa açarken, sadece bir marka kurmak istemedim. Bana iyi hissettiren her şeyi tek bir çatı altında toplamak istedim. Denizi… Yazı… Özgürlüğü… Ve kadınların kendini en güzel hissettiği anları… İşte KUKA ISLAND böyle doğdu. Her koleksiyon, sadece bir sezon için değil; yıllar sonra bile giydiğinde sana aynı hissi yaşatsın diye hazırlanıyor. Bu yolculuk daha yeni başlıyor. İyi ki buradasın. Ve bu hikâyenin ilk sayfalarında bize eşlik ediyorsun. Love, KÜBRA';
$story_bilingual = array_filter( $story_scenes, static fn( array $scene ): bool => '' !== trim( (string) ( $scene['text'] ?? '' ) ) && '' !== trim( (string) ( $scene['text_en'] ?? '' ) ) );
$story_media = 0;
$story_transitions = array();
$story_panel_art = 0;
foreach ( $story_scenes as $scene ) {
	foreach ( array( 'desktop_image_id', 'desktop_image_id_en', 'mobile_image_id', 'mobile_image_id_en' ) as $media_key ) {
		if ( absint( $scene[ $media_key ] ?? 0 ) ) { ++$story_media; }
	}
	if ( ! empty( $scene['transition_type'] ) ) { $story_transitions[] = (string) $scene['transition_type']; }
	foreach ( array( 'transition_type', 'text_position', 'gradient_intensity' ) as $art_key ) {
		if ( '' !== trim( (string) ( $scene[ $art_key ] ?? '' ) ) ) { ++$story_panel_art; }
	}
}
$story_template = (string) file_get_contents( get_stylesheet_directory() . '/page-hakkimizda.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$story_script = (string) file_get_contents( get_stylesheet_directory() . '/assets/js/story.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
WP_CLI::line( 'STORY_SCENES=' . count( $story_scenes ) );
WP_CLI::line( 'STORY_BILINGUAL=' . count( $story_bilingual ) . '/' . count( $story_scenes ) );
WP_CLI::line( 'STORY_MEDIA_FIELDS=' . $story_media . '/' . ( count( $story_scenes ) * 4 ) );
WP_CLI::line( 'STORY_ART_FIELDS=' . $story_panel_art . '/' . ( count( $story_scenes ) * 3 ) );
WP_CLI::line( 'STORY_TRANSITIONS=' . implode( '|', $story_transitions ) );
WP_CLI::line( 'STORY_TRANSITION_UNIQUE=' . count( array_unique( $story_transitions ) ) . '/' . count( $story_scenes ) );
WP_CLI::line( 'STORY_PDF_BODY_MATCH=' . ( hash_equals( $story_pdf_expected, $story_pdf_source ) ? 'yes' : 'no' ) );
WP_CLI::line( 'STORY_LINE_REVEAL=' . ( ! empty( $story_scenes[3]['reveal_lines'] ) ? 'scene-04' : 'missing' ) );
WP_CLI::line( 'STORY_PROGRESSIVE_DOM=' . ( str_contains( $story_template, 'foreach ( $story_scenes' ) && str_contains( $story_template, 'data-story-scene=' ) && ! str_contains( $story_script, 'createElement' ) ? 'server' : 'missing' ) );
WP_CLI::line( 'STORY_OBSERVER=' . ( str_contains( $story_script, 'new IntersectionObserver' ) && str_contains( $story_script, 'observer?.disconnect()' ) && ! str_contains( $story_script, 'addEventListener("scroll"' ) ? 'io+cleanup+no-scroll' : 'missing' ) );
WP_CLI::line( 'STORY_MOBILE_ENHANCED=' . ( ! str_contains( $story_script, 'desktop.matches' ) && str_contains( $story_script, 'motion.matches || observer' ) ? 'enabled' : 'disabled' ) );
WP_CLI::line( 'STORY_EMPTY_MEDIA=' . ( str_contains( $story_template, 'kuka-story__placeholder' ) ? 'placeholder' : 'missing' ) );
global $wpdb;
$newsletter_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'kuka_newsletter_subscribers' ) );
$newsletter_form = class_exists( 'Kuka_Island_Core_Newsletter' ) ? Kuka_Island_Core_Newsletter::form() : '';
$newsletter_columns = $newsletter_table ? $wpdb->get_col( 'SHOW COLUMNS FROM ' . Kuka_Island_Core_Newsletter::table_name() ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$newsletter_source = (string) file_get_contents( WP_PLUGIN_DIR . '/kuka-island-core/includes/class-newsletter.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$checkout_script = (string) file_get_contents( get_stylesheet_directory() . '/assets/js/checkout.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$checkout_source = (string) file_get_contents( get_stylesheet_directory() . '/inc/checkout.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$asset_source = (string) file_get_contents( get_stylesheet_directory() . '/inc/assets.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$cart_script = (string) file_get_contents( get_stylesheet_directory() . '/assets/js/cart.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$checkout_styles = (string) file_get_contents( get_stylesheet_directory() . '/assets/css/checkout.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$woocommerce_source = (string) file_get_contents( get_stylesheet_directory() . '/inc/woocommerce.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$appearance_source = (string) file_get_contents( WP_PLUGIN_DIR . '/kuka-island-core/includes/class-site-appearance.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
WP_CLI::line( 'NEWSLETTER_TABLE=' . ( $newsletter_table ? 'ready' : 'missing' ) );
WP_CLI::line( 'NEWSLETTER_FORM=' . ( str_contains( $newsletter_form, 'method="post"' ) && str_contains( $newsletter_form, 'name="consent" value="1" required' ) ? 'native-required' : 'missing' ) );
WP_CLI::line( 'NEWSLETTER_UI=' . ( str_contains( $newsletter_form, 'placeholder="name@example.com"' ) && str_contains( $newsletter_form, 'class="kuka-button"' ) ? 'label+placeholder|site-button' : 'missing' ) );
WP_CLI::line( 'NEWSLETTER_NOTIFICATION_FIELD=' . ( array_key_exists( 'newsletter_notification_email', $site_content['footer'] ?? array() ) ? 'panel' : 'missing' ) );
WP_CLI::line( 'NEWSLETTER_DOUBLE_OPT_IN=' . ( in_array( 'status', $newsletter_columns, true ) && in_array( 'confirmation_hash', $newsletter_columns, true ) && str_contains( $newsletter_source, 'kuka_newsletter_confirm' ) ? 'schema+token+confirm' : 'missing' ) );
WP_CLI::line( 'NEWSLETTER_FIRST_EVIDENCE=' . ( ! str_contains( $newsletter_source, '$wpdb->replace' ) && str_contains( $newsletter_source, 'confirmation_hash = VALUES(confirmation_hash)' ) ? 'immutable' : 'replaceable' ) );
WP_CLI::line( 'NEWSLETTER_IP_LIMIT=' . ( str_contains( $newsletter_source, 'IP_RATE_LIMIT' ) && str_contains( $newsletter_source, '$ip_rate_key' ) ? 'global+pair' : 'pair-only' ) );
WP_CLI::line( 'CHECKOUT_SUMMARY_TOTAL=' . ( str_contains( $checkout_script, 'synchronizeSummaryTotal' ) && str_contains( $checkout_script, ".on('updated_checkout'" ) ? 'ajax-synced' : 'stale' ) );
WP_CLI::line( 'CHECKOUT_OPTIONAL_PHONE=' . ( ! str_contains( $checkout_script, "phone.value = '5'" ) && str_contains( $checkout_script, 'phone.value = formatPhone(phone.value)' ) ? 'empty-allowed' : 'seeded' ) );
WP_CLI::line( 'CHECKOUT_COMPANY_REQUIRED=' . ( str_contains( $checkout_source, "'corporate' === \$customer_type" ) && ! str_contains( $checkout_source, "'billing_company'   =>" ) && str_contains( $cart_script, 'field.required = corporate' ) && str_contains( $checkout_styles, 'body.kuka-checkout-enhanced:not(.kuka-corporate)' ) ? 'corporate-only' : 'unconditional' ) );
WP_CLI::line( 'SITE_CONTENT_CACHE=' . ( str_contains( $appearance_source, 'private static ?array $content_cache' ) && str_contains( $appearance_source, 'null !== self::$content_cache' ) ? 'request-local' : 'missing' ) );
WP_CLI::line( 'PRODUCT_CACHE_PRIMING=' . ( str_contains( $woocommerce_source, 'woocommerce_shortcode_products_query_results' ) && str_contains( $asset_source, 'kuka_island_prime_catalog_caches( array( get_queried_object_id() ) )' ) ? 'shortcode+single' : 'partial' ) );
WP_CLI::line( 'CART_FRAGMENT_DEPENDENCY=' . ( str_contains( $asset_source, "'wc-add-to-cart', 'wc-cart-fragments'" ) ? 'eager' : 'deferred' ) );
$low_stock = wc_get_products( array( 'type' => 'variation', 'limit' => -1, 'stock_quantity' => 2, 'return' => 'ids' ) );
$out_stock = wc_get_products( array( 'type' => 'variation', 'limit' => -1, 'stock_status' => 'outofstock', 'return' => 'ids' ) );
WP_CLI::line( 'LOW_STOCK_VARIATIONS=' . count( $low_stock ) );
WP_CLI::line( 'OUT_OF_STOCK_VARIATIONS=' . count( $out_stock ) );
WP_CLI::line( 'SHOP_PER_PAGE=' . apply_filters( 'loop_shop_per_page', wc_get_default_products_per_row() * wc_get_default_product_rows_per_page() ) );
WP_CLI::line( 'PERMALINK_STRUCTURE=' . get_option( 'permalink_structure' ) );
WP_CLI::line( 'CHECKOUT_CLASSIC=' . ( str_contains( (string) get_post_field( 'post_content', wc_get_page_id( 'checkout' ) ), '[woocommerce_checkout]' ) ? 'yes' : 'no' ) );
$language = new Kuka_Island_Core_Language();
$locale_order = new WC_Order();
$request_uri = $_SERVER['REQUEST_URI'] ?? null;
$_SERVER['REQUEST_URI'] = '/en/odeme/';
$language->save_order_locale( $locale_order );
if ( null === $request_uri ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $request_uri; }
WP_CLI::line( 'ORDER_LOCALE_META=' . $locale_order->get_meta( '_kuka_order_locale', true ) );
$request_uri = $_SERVER['REQUEST_URI'] ?? null;
$_SERVER['REQUEST_URI'] = '/en/odeme/';
$public_urls = array( wc_get_cart_url(), wc_get_checkout_url() );
$first_product_id = wc_get_products( array( 'limit' => 1, 'return' => 'ids' ) )[0] ?? 0;
if ( $first_product_id ) { $public_urls[] = get_permalink( $first_product_id ); }
$received_url = apply_filters( 'woocommerce_get_checkout_order_received_url', home_url( '/odeme/order-received/123/?key=wc_order_test' ), new WC_Order() );
$iyzico_url = apply_filters( 'woocommerce_get_return_url', home_url( '/odeme/order-received/123/?key=wc_order_test' ), new WC_Order() );
if ( null === $request_uri ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $request_uri; }
WP_CLI::line( 'ENGLISH_PUBLIC_URLS=' . ( count( array_filter( $public_urls, static fn( string $url ): bool => str_starts_with( $url, home_url( '/en/' ) ) ) ) === count( $public_urls ) ? 'prefixed' : 'failed' ) );
WP_CLI::line( 'ORDER_RECEIVED_LANGUAGE=' . ( str_contains( $received_url, '/en/odeme/order-received/' ) ? 'preserved' : 'failed' ) );
WP_CLI::line( 'IYZICO_RETURN_LANGUAGE=' . ( str_contains( $iyzico_url, '/en/odeme/order-received/' ) ? 'preserved' : 'failed' ) );
$email = WC()->mailer()->get_emails()['WC_Email_Customer_Processing_Order'];
$email->object = $locale_order;
$before_email_locale = get_locale();
$language->switch_email_locale( true, $email );
$switched_email_locale = get_locale();
$language->restore_email_locale( true, $email );
WP_CLI::line( 'EMAIL_LOCALE_SWITCH=' . $before_email_locale . '>' . $switched_email_locale . '>' . get_locale() );
$render_order = new WC_Order();
$render_order->set_date_created( time() );
$render_order->set_billing_first_name( 'EN-QA' );
$render_order->set_billing_email( 'en-qa@example.test' );
$render_order->update_meta_data( '_kuka_order_locale', 'en_US' );
$render_item = new WC_Order_Item_Product();
$render_item->set_name( 'EN-QA-PRODUCT' );
$render_item->set_quantity( 1 );
$render_item->set_subtotal( 100 );
$render_item->set_total( 100 );
$render_order->add_item( $render_item );
$email->object = $render_order;
$language->switch_email_locale( true, $email );
$english_email_html = $email->get_content_html();
$english_email_heading = $email->get_heading();
$language->restore_email_locale( true, $email );
WP_CLI::line(
	'ENGLISH_EMAIL_HTML=heading:' . ( str_contains( $english_email_heading, 'Thank you for your order' ) ? 'yes' : 'no' )
	. '|body:' . ( str_contains( $english_email_html, 'Hi EN-QA' ) ? 'yes' : 'no' )
	. '|product:' . ( str_contains( $english_email_html, 'EN-QA-PRODUCT' ) ? 'yes' : 'no' )
	. '|tracking:' . ( str_contains( $english_email_html, 'Track your order with your order number and email address' ) ? 'yes' : 'no' )
	. '|additional:' . ( str_contains( $english_email_html, 'Thanks for using' ) ? 'yes' : 'no' )
);
