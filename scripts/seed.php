<?php
/**
 * Idempotent WooCommerce pilot data and settings seed.
 * Executed only through the wp-cli container.
 */

defined( 'WP_CLI' ) || exit( 1 );

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce etkin değil.' );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

function kuka_seed_attribute( string $name, string $slug ): int {
	foreach ( wc_get_attribute_taxonomies() as $attribute ) {
		if ( $slug === $attribute->attribute_name ) {
			return (int) $attribute->attribute_id;
		}
	}

	$id = wc_create_attribute(
		array(
			'name'         => $name,
			'slug'         => $slug,
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		)
	);

	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}

	delete_transient( 'wc_attribute_taxonomies' );
	return (int) $id;
}

function kuka_seed_term( string $taxonomy, string $name, string $slug, array $meta = array() ): int {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term ) {
		$result = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$term_id = (int) $result['term_id'];
	} else {
		$term_id = (int) $term->term_id;
	}

	foreach ( $meta as $key => $value ) {
		update_term_meta( $term_id, $key, $value );
	}

	return $term_id;
}

function kuka_seed_media( string $basename, string $alt ): int {
	$existing = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'meta_key'       => '_kuka_seed_source',
			'meta_value'     => $basename,
			'fields'         => 'ids',
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}

	$source = '/seed-media/' . $basename;
	if ( ! is_readable( $source ) ) {
		WP_CLI::error( 'Seed görseli okunamadı: ' . $basename );
	}

	$temp = wp_tempnam( $basename );
	if ( ! $temp || ! copy( $source, $temp ) ) {
		WP_CLI::error( 'Geçici görsel oluşturulamadı: ' . $basename );
	}

	$id = media_handle_sideload(
		array( 'name' => $basename, 'tmp_name' => $temp ),
		0,
		$alt
	);
	if ( is_wp_error( $id ) ) {
		@unlink( $temp );
		WP_CLI::error( $id->get_error_message() );
	}

	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	update_post_meta( $id, '_kuka_seed_source', $basename );
	return (int) $id;
}

function kuka_seed_taxonomy_attribute( int $attribute_id, string $taxonomy, array $term_ids, int $position ): WC_Product_Attribute {
	$attribute = new WC_Product_Attribute();
	$attribute->set_id( $attribute_id );
	$attribute->set_name( $taxonomy );
	$attribute->set_options( $term_ids );
	$attribute->set_position( $position );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	return $attribute;
}

function kuka_seed_product( array $spec, array $attribute_ids, array $terms, array $media ): int {
	$existing_id = wc_get_product_id_by_sku( $spec['sku'] );
	$product     = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Variable();
	if ( ! $product instanceof WC_Product_Variable ) {
		WP_CLI::error( 'SKU başka ürün türüyle çakışıyor: ' . $spec['sku'] );
	}

	$product->set_name( $spec['name'] );
	$product->set_slug( $spec['slug'] );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_sku( $spec['sku'] );
	$product->set_regular_price( (string) $spec['price'] );
	$product->set_description( $spec['description'] );
	$product->set_short_description( $spec['short'] );
	$product->set_category_ids( array( $terms['categories'][ $spec['category'] ] ) );
	$product->set_image_id( $media[ $spec['featured'] ] );
	$product->set_gallery_image_ids( array_map( static fn( string $file ): int => $media[ $file ], $spec['gallery'] ) );

	$color_ids = array_map( static fn( string $slug ): int => $terms['colors'][ $slug ], array_keys( $spec['colors'] ) );
	$size_ids  = array_map( static fn( string $slug ): int => $terms['sizes'][ $slug ], $spec['sizes'] );
	$cut_ids   = array( $terms['cuts'][ $spec['cut'] ] );

	$color_attribute = kuka_seed_taxonomy_attribute( $attribute_ids['renk'], 'pa_renk', $color_ids, 0 );
	$size_attribute  = kuka_seed_taxonomy_attribute( $attribute_ids['beden'], 'pa_beden', $size_ids, 1 );
	$cut_attribute   = kuka_seed_taxonomy_attribute( $attribute_ids['kesim'], 'pa_kesim', $cut_ids, 2 );
	$cut_attribute->set_variation( false );
	$product->set_attributes( array( $color_attribute, $size_attribute, $cut_attribute ) );

	foreach ( $spec['meta'] as $key => $value ) {
		$product->update_meta_data( $key, $value );
	}
	$product->update_meta_data( '_kuka_pilot_expected_variations', count( $spec['colors'] ) * count( $spec['sizes'] ) );
	$product_id = $product->save();

	$expected_skus = array();
	$index         = 0;
	foreach ( $spec['colors'] as $color_slug => $color ) {
		foreach ( $spec['sizes'] as $size_slug ) {
			$sku             = $spec['sku'] . '-' . strtoupper( $color_slug ) . '-' . strtoupper( $size_slug );
			$expected_skus[] = $sku;
			$variation_id    = wc_get_product_id_by_sku( $sku );
			$variation       = $variation_id ? new WC_Product_Variation( $variation_id ) : new WC_Product_Variation();
			$stock           = ( $index % 11 === 0 ) ? 0 : ( ( $index % 7 === 1 ) ? 2 : 3 + ( $index % 6 ) );
			$variation->set_parent_id( $product_id );
			$variation->set_status( 'publish' );
			$variation->set_sku( $sku );
			$variation->set_regular_price( (string) $spec['price'] );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $stock );
			$variation->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
			$variation->set_low_stock_amount( 2 );
			$variation->set_backorders( 'no' );
			$variation->set_attributes( array( 'pa_renk' => $color_slug, 'pa_beden' => $size_slug ) );
			$variation->set_image_id( $media[ $color['image'] ] );
			$gallery_ids = array_map( static fn( string $file ): int => $media[ $file ], $color['gallery'] );
			$variation->update_meta_data( '_kuka_variation_gallery_ids', $gallery_ids );
			$variation->update_meta_data(
				'blocksy_post_meta_options',
				array(
					'gallery_source' => 'custom',
					'images'         => array_map(
						static fn( int $attachment_id ): array => array(
							'attachment_id' => $attachment_id,
							'url'           => wp_get_attachment_url( $attachment_id ),
						),
						$gallery_ids
					),
				)
			);
			$variation->save();
			++$index;
		}
	}

	$children = $product->get_children();
	foreach ( $children as $child_id ) {
		$child = wc_get_product( $child_id );
		if ( $child && ! in_array( $child->get_sku(), $expected_skus, true ) ) {
			$child->delete( true );
		}
	}

	WC_Product_Variable::sync( $product_id );
	wc_delete_product_transients( $product_id );
	return $product_id;
}

// Locale, commerce defaults and HPOS.
update_option( 'timezone_string', 'Europe/Istanbul' );
update_option( 'WPLANG', 'tr_TR' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'woocommerce_currency', 'TRY' );
update_option( 'woocommerce_currency_pos', 'left' );
update_option( 'woocommerce_price_num_decimals', '0' );
update_option( 'woocommerce_price_thousand_sep', '.' );
update_option( 'woocommerce_price_decimal_sep', ',' );
update_option( 'woocommerce_default_country', 'TR' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
update_option( 'woocommerce_checkout_privacy_policy_text', 'Kişisel verileriniz siparişinizi işlemek, site deneyiminizi desteklemek ve [privacy_policy] sayfamızda açıklanan diğer amaçlar için kullanılacaktır.' );
update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
update_option( 'woocommerce_thumbnail_image_width', '600' );
update_option( 'woocommerce_single_image_width', '1080' );
update_option( 'woocommerce_thumbnail_cropping', 'custom' );
update_option( 'woocommerce_thumbnail_cropping_custom_width', '4' );
update_option( 'woocommerce_thumbnail_cropping_custom_height', '5' );
update_option( 'woocommerce_enable_myaccount_registration', 'yes' );

$attribute_ids = array(
	'renk'  => kuka_seed_attribute( 'Renk', 'renk' ),
	'beden' => kuka_seed_attribute( 'Beden', 'beden' ),
	'kesim' => kuka_seed_attribute( 'Kesim', 'kesim' ),
);

if ( class_exists( 'WC_Post_Types' ) ) {
	delete_transient( 'wc_attribute_taxonomies' );
	WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
	WC_Post_Types::register_taxonomies();
}

$colors = array(
	'siyah'      => array( 'Siyah', '#111315' ),
	'kobalt'     => array( 'Kobalt', '#1746c8' ),
	'kum'        => array( 'Kum', '#c8b89a' ),
	'zeytin'     => array( 'Zeytin', '#5a5d3a' ),
	'terracotta' => array( 'Terracotta', '#b65a3a' ),
	'beyaz'      => array( 'Beyaz', '#eef0ec' ),
);
$terms  = array( 'colors' => array(), 'sizes' => array(), 'cuts' => array(), 'categories' => array() );
foreach ( $colors as $slug => $color ) {
	$terms['colors'][ $slug ] = kuka_seed_term( 'pa_renk', $color[0], $slug, array( 'kuka_swatch_hex' => $color[1] ) );
}
foreach ( array( '34', '36', '38', '40', '42', 'XS', 'S', 'M', 'L', 'XL' ) as $size ) {
	$terms['sizes'][ sanitize_title( $size ) ] = kuka_seed_term( 'pa_beden', $size, sanitize_title( $size ) );
}
foreach ( array( 'asimetrik' => 'Asimetrik', 'bralet' => 'Bralet', 'tek-parca' => 'Tek Parça', 'klasik' => 'Klasik' ) as $slug => $name ) {
	$terms['cuts'][ $slug ] = kuka_seed_term( 'pa_kesim', $name, $slug );
}
foreach ( array( 'bikini-ustleri' => 'Bikini Üstleri', 'bikini-altlari' => 'Bikini Altları', 'mayolar' => 'Mayolar', 'plaj-giyim' => 'Plaj Giyim' ) as $slug => $name ) {
	$terms['categories'][ $slug ] = kuka_seed_term( 'product_cat', $name, $slug );
}
$terms['categories']['takimlar'] = kuka_seed_term( 'product_cat', 'Takımlar', 'takimlar' );
$uncategorized = get_term_by( 'slug', 'uncategorized', 'product_cat' );
if ( $uncategorized ) {
	update_option( 'default_product_cat', $terms['categories']['bikini-ustleri'] );
	wp_delete_term( $uncategorized->term_id, 'product_cat' );
}

$media_specs = array(
	'noir-asymmetric-top.jpg' => 'Siyah asimetrik bikini üstü ön görünüm',
	'noir-asymmetric-top-detail.jpg' => 'Siyah asimetrik bikini üstü doku detayı',
	'cobalt-asymmetric-top.jpg' => 'Kobalt asimetrik bikini üstü ön görünüm',
	'cobalt-asymmetric-top-detail.jpg' => 'Kobalt asimetrik bikini üstü doku detayı',
	'azur-bralet-top.jpg' => 'Kobalt bralet bikini üstü ön görünüm',
	'azur-bralet-top-detail.jpg' => 'Kobalt bralet bikini üstü detay görünüm',
	'noir-one-piece.jpg' => 'Siyah tek omuz mayo ön görünüm',
	'noir-one-piece-detail.jpg' => 'Siyah tek omuz mayo detay görünüm',
	'azur-bikini-bottom.jpg' => 'Kobalt bikini altı ön görünüm',
	'azur-bikini-bottom-detail.jpg' => 'Kobalt bikini altı detay görünüm',
	'cobalt-set.jpg' => 'Kobalt kombin görünümü',
	'hero-aegean-black.jpg' => 'Ege kıyısında siyah mayo yatay görünüm',
	'hero-aegean-black-mobile.jpg' => 'Ege kıyısında siyah mayo dikey görünüm',
);
$media = array();
foreach ( $media_specs as $file => $alt ) {
	$media[ $file ] = kuka_seed_media( $file, $alt );
}

$common_meta = array(
	'_kuka_material'   => '%78 geri dönüştürülmüş poliamid, %22 elastan',
	'_kuka_care'       => 'Elde, soğuk suda yıkayın. Gölgede düz kurutun.',
	'_kuka_fit'        => 'Standart kalıp; iki beden arasındaysanız büyük bedeni seçin.',
	'_kuka_model_info' => 'Model 178 cm boyunda ve 36 beden giymektedir. Pilot veri.',
	'_kuka_size_guide' => 'beden-rehberi',
);
$all_colors = array(
	'siyah' => array( 'image' => 'noir-asymmetric-top.jpg', 'gallery' => array( 'noir-asymmetric-top.jpg', 'noir-asymmetric-top-detail.jpg', 'cobalt-set.jpg' ) ),
	'kobalt' => array( 'image' => 'cobalt-asymmetric-top.jpg', 'gallery' => array( 'cobalt-asymmetric-top.jpg', 'cobalt-asymmetric-top-detail.jpg', 'cobalt-set.jpg' ) ),
	'kum' => array( 'image' => 'azur-bralet-top.jpg', 'gallery' => array( 'azur-bralet-top.jpg', 'azur-bralet-top-detail.jpg', 'hero-aegean-black-mobile.jpg' ) ),
	'zeytin' => array( 'image' => 'noir-one-piece.jpg', 'gallery' => array( 'noir-one-piece.jpg', 'noir-one-piece-detail.jpg', 'hero-aegean-black.jpg' ) ),
	'terracotta' => array( 'image' => 'azur-bikini-bottom.jpg', 'gallery' => array( 'azur-bikini-bottom.jpg', 'azur-bikini-bottom-detail.jpg', 'cobalt-set.jpg' ) ),
);
$product_specs = array(
	array( 'sku' => 'KI-TOP-001', 'name' => 'Asimetrik Bikini Üstü', 'slug' => 'asimetrik-bikini-ustu', 'price' => 2890, 'category' => 'bikini-ustleri', 'cut' => 'asimetrik', 'featured' => 'noir-asymmetric-top.jpg', 'gallery' => array( 'noir-asymmetric-top-detail.jpg', 'cobalt-set.jpg' ), 'colors' => $all_colors, 'sizes' => array( '34', '36', '38', '40', '42', 'xs', 's' ), 'short' => 'Tek omuz çizgisi ve ayarlanabilir metal halka detayı.', 'description' => 'Bedene kontrollü biçimde oturan, çıkarılabilir pedli asimetrik bikini üstü.', 'meta' => $common_meta ),
	array( 'sku' => 'KI-TOP-002', 'name' => 'Azur Bralet Bikini Üstü', 'slug' => 'azur-bralet-bikini-ustu', 'price' => 2690, 'category' => 'bikini-ustleri', 'cut' => 'bralet', 'featured' => 'azur-bralet-top.jpg', 'gallery' => array( 'azur-bralet-top-detail.jpg', 'cobalt-set.jpg' ), 'colors' => array( 'kobalt' => $all_colors['kobalt'] ), 'sizes' => array( '34', '36', '38', '40', '42' ), 'short' => 'Geniş alt bantlı, destekli bralet form.', 'description' => 'Hareket sırasında dengeli destek sağlayan bralet bikini üstü.', 'meta' => $common_meta ),
	array( 'sku' => 'KI-ONE-001', 'name' => 'Noir Tek Omuz Mayo', 'slug' => 'noir-tek-omuz-mayo', 'price' => 4290, 'category' => 'mayolar', 'cut' => 'tek-parca', 'featured' => 'noir-one-piece.jpg', 'gallery' => array( 'noir-one-piece-detail.jpg', 'hero-aegean-black-mobile.jpg' ), 'colors' => array( 'siyah' => $all_colors['siyah'] ), 'sizes' => array( '34', '36', '38', '40', '42' ), 'short' => 'Heykelsi tek omuz çizgisi ve kontrollü bel formu.', 'description' => 'Suda ve kıyıda kullanılmak üzere tasarlanan tek omuz mayo.', 'meta' => $common_meta ),
	array( 'sku' => 'KI-BTM-004', 'name' => 'Azur Klasik Bikini Altı', 'slug' => 'azur-klasik-bikini-alti', 'price' => 2290, 'category' => 'bikini-altlari', 'cut' => 'klasik', 'featured' => 'azur-bikini-bottom.jpg', 'gallery' => array( 'azur-bikini-bottom-detail.jpg', 'cobalt-set.jpg' ), 'colors' => array( 'kobalt' => $all_colors['kobalt'] ), 'sizes' => array( '34', '36', '38', '40', '42' ), 'short' => 'Orta kapama ve ayarlanabilir yan detay.', 'description' => 'Gün boyu rahatlık için orta kapamalı klasik bikini altı.', 'meta' => $common_meta ),
);

$product_ids = array();
foreach ( $product_specs as $spec ) {
	$product_ids[ $spec['sku'] ] = kuka_seed_product( $spec, $attribute_ids, $terms, $media );
}

// Pair metadata only; component stock and special set pricing are deliberately not implemented.
update_post_meta( $product_ids['KI-TOP-002'], '_kuka_paired_product_id', $product_ids['KI-BTM-004'] );
update_post_meta( $product_ids['KI-BTM-004'], '_kuka_paired_product_id', $product_ids['KI-TOP-002'] );

// Classic checkout is the pilot decision; supported hooks remain available and iyzico renders here.
$checkout_id = (int) wc_get_page_id( 'checkout' );
if ( $checkout_id > 0 ) {
	wp_update_post( array( 'ID' => $checkout_id, 'post_title' => 'Ödeme', 'post_name' => 'odeme', 'post_content' => '[woocommerce_checkout]' ) );
}
$commerce_pages = array( 'shop' => array( 'magaza', 'Mağaza', '' ), 'cart' => array( 'sepet', 'Sepet', '[woocommerce_cart]' ), 'myaccount' => array( 'hesabim', 'Hesabım', '[woocommerce_my_account]' ) );
foreach ( $commerce_pages as $page_key => $page_data ) {
	$page_id = (int) wc_get_page_id( $page_key );
	if ( $page_id > 0 ) {
		$page_update = array( 'ID' => $page_id, 'post_name' => $page_data[0], 'post_title' => $page_data[1] );
		if ( $page_data[2] ) { $page_update['post_content'] = $page_data[2]; }
		wp_update_post( $page_update );
	}
}
update_option( 'woocommerce_permalinks', array( 'product_base' => '/urun', 'category_base' => '/kategori', 'tag_base' => '/urun-etiketi', 'attribute_base' => '' ) );

/** Build one reset-safe WordPress navigation menu. */
function kuka_seed_menu( string $name, array $items ): int {
	$menu = wp_get_nav_menu_object( $name );
	$menu_id = $menu ? (int) $menu->term_id : wp_create_nav_menu( $name );
	if ( is_wp_error( $menu_id ) ) { WP_CLI::error( $menu_id->get_error_message() ); }
	if ( wp_get_nav_menu_items( $menu_id ) ) { return (int) $menu_id; }
	$created = array();
	foreach ( $items as $key => $item ) {
		$created[ $key ] = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => $item['title'],
				'menu-item-url'       => home_url( $item['url'] ),
				'menu-item-status'    => 'publish',
				'menu-item-type'      => 'custom',
				'menu-item-parent-id' => isset( $item['parent'] ) ? ( $created[ $item['parent'] ] ?? 0 ) : 0,
			)
		);
	}
	return (int) $menu_id;
}

$primary_menu = kuka_seed_menu(
	'Kuka Island Ana Menü',
	array(
		'new' => array( 'title' => 'Yeni Gelenler', 'url' => '/magaza/?orderby=date' ),
		'bikini' => array( 'title' => 'Bikini', 'url' => '/magaza/' ),
		'bikini_tops' => array( 'title' => 'Bikini Üstleri', 'url' => '/kategori/bikini-ustleri/', 'parent' => 'bikini' ),
		'bikini_bottoms' => array( 'title' => 'Bikini Altları', 'url' => '/kategori/bikini-altlari/', 'parent' => 'bikini' ),
		'sets' => array( 'title' => 'Takımlar', 'url' => '/kategori/takimlar/', 'parent' => 'bikini' ),
		'one_piece' => array( 'title' => 'Mayo', 'url' => '/kategori/mayolar/' ),
		'beachwear' => array( 'title' => 'Plaj Giyim', 'url' => '/kategori/plaj-giyim/' ),
		'collections' => array( 'title' => 'Koleksiyonlar', 'url' => '/magaza/' ),
		'brand' => array( 'title' => 'Marka / Hikâyemiz', 'url' => '/hakkimizda/' ),
	)
);
$footer_menu = kuka_seed_menu(
	'Kuka Island Footer Kategoriler',
	array(
		'new' => array( 'title' => 'Yeni Gelenler', 'url' => '/magaza/?orderby=date' ),
		'tops' => array( 'title' => 'Bikini Üstleri', 'url' => '/kategori/bikini-ustleri/' ),
		'bottoms' => array( 'title' => 'Bikini Altları', 'url' => '/kategori/bikini-altlari/' ),
		'one_piece' => array( 'title' => 'Mayolar', 'url' => '/kategori/mayolar/' ),
		'beachwear' => array( 'title' => 'Plaj Giyim', 'url' => '/kategori/plaj-giyim/' ),
	)
);
$locations = get_theme_mod( 'nav_menu_locations', array() );
$locations['primary'] = $primary_menu;
$locations['footer_categories'] = $footer_menu;
set_theme_mod( 'nav_menu_locations', $locations );

// Turkey zone with provisional values pending customer confirmation.
$zone_id = 0;
foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
	if ( 'Türkiye' === $zone_data['zone_name'] ) {
		$zone_id = (int) $zone_data['zone_id'];
		break;
	}
}
$zone = $zone_id ? WC_Shipping_Zones::get_zone( $zone_id ) : new WC_Shipping_Zone();
if ( ! $zone_id ) {
	$zone->set_zone_name( 'Türkiye' );
	$zone->set_zone_order( 1 );
	$zone->add_location( 'TR', 'country' );
	$zone->save();
}
$has_flat = false;
$has_free = false;
foreach ( $zone->get_shipping_methods( true ) as $method ) {
	if ( 'flat_rate' === $method->id ) {
		$method->instance_settings = array( 'title' => 'Sabit ücret', 'tax_status' => 'none', 'cost' => '149' );
		update_option( $method->get_instance_option_key(), $method->instance_settings );
		$has_flat = true;
	}
	if ( 'free_shipping' === $method->id ) {
		$method->instance_settings = array( 'title' => 'Ücretsiz kargo', 'requires' => 'min_amount', 'min_amount' => '3000', 'ignore_discounts' => 'no' );
		update_option( $method->get_instance_option_key(), $method->instance_settings );
		$has_free = true;
	}
}
if ( ! $has_flat ) {
	$instance_id = $zone->add_shipping_method( 'flat_rate' );
	update_option( 'woocommerce_flat_rate_' . $instance_id . '_settings', array( 'title' => 'Sabit ücret', 'tax_status' => 'none', 'cost' => '149' ) );
}
if ( ! $has_free ) {
	$instance_id = $zone->add_shipping_method( 'free_shipping' );
	update_option( 'woocommerce_free_shipping_' . $instance_id . '_settings', array( 'title' => 'Ücretsiz kargo', 'requires' => 'min_amount', 'min_amount' => '3000', 'ignore_discounts' => 'no' ) );
}

flush_rewrite_rules( false );
WP_CLI::success( sprintf( 'Pilot seed tamamlandı: %d ürün, %d varyasyon.', count( $product_ids ), array_sum( array_map( static fn( array $spec ): int => count( $spec['colors'] ) * count( $spec['sizes'] ), $product_specs ) ) ) );
