<?php
/**
 * Required product content metadata.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Product_Fields {
	private bool $saving_editor = false;
	private const FIELDS = array(
		'_kuka_material'   => 'Kumaş',
		'_kuka_care'       => 'Bakım',
		'_kuka_fit'        => 'Kalıp',
		'_kuka_model_info' => 'Model bilgisi',
		'_kuka_size_guide' => 'Beden rehberi ilişkisi',
		'_kuka_seo_title'   => 'SEO başlığı',
		'_kuka_meta_description' => 'Meta açıklaması',
	);

	public function register(): void {
		add_action( 'init', array( $this, 'prepare_product_editor' ), 99 );
		add_action( 'edit_form_after_title', array( $this, 'render_english_name' ) );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 20, 2 );
		add_action( 'add_meta_boxes_product', array( $this, 'add_english_box' ) );
		add_action( 'save_post_product', array( $this, 'save_english_box' ), 20, 2 );
		add_filter( 'woocommerce_product_get_name', array( $this, 'english_name' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_name', array( $this, 'english_variation_name' ), 10, 2 );
		add_filter( 'woocommerce_order_item_name', array( $this, 'english_order_item_name' ), 20, 2 );
		add_filter( 'woocommerce_product_get_description', array( $this, 'english_description' ), 10, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( $this, 'english_short_description' ), 10, 2 );
		add_filter( 'the_title', array( $this, 'english_post_title' ), 25, 2 );
		add_filter( 'the_content', array( $this, 'english_post_description' ), 9 );
		add_filter( 'woocommerce_short_description', array( $this, 'english_post_short_description' ), 20 );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'english_structured_data' ), 20, 2 );
	}

	public function title_placeholder( string $placeholder, WP_Post $post ): string {
		return 'product' === $post->post_type ? __( 'Ürün adı (Türkçe)', 'kuka-island-core' ) : $placeholder;
	}

	/** The paired editors below are the only description source on the product screen. */
	public function prepare_product_editor(): void {
		remove_post_type_support( 'product', 'editor' );
		remove_post_type_support( 'product', 'excerpt' );
	}

	public function render_english_name( WP_Post $post ): void {
		if ( 'product' !== $post->post_type ) { return; }
		$value = (string) get_post_meta( $post->ID, '_kuka_name_en', true );
		echo '<div class="postbox"><div class="inside"><label for="kuka_name_en"><strong>' . esc_html__( 'Ürün adı (EN)', 'kuka-island-core' ) . '</strong></label><p class="description">' . esc_html__( 'Türkçe ürün adının İngilizce karşılığı; boşsa Türkçe ad gösterilir.', 'kuka-island-core' ) . '</p><input class="widefat" id="kuka_name_en" name="_kuka_name_en" type="text" value="' . esc_attr( $value ) . '"></div></div>';
	}

	public function add_english_box(): void {
		remove_meta_box( 'postexcerpt', 'product', 'normal' );
		add_meta_box( 'kuka-product-bilingual', __( 'Türkçe ve İngilizce ürün içeriği', 'kuka-island-core' ), array( $this, 'render_english_box' ), 'product', 'normal', 'high' );
		add_meta_box( 'kuka-product-checklist', __( 'Yayın kontrol listesi', 'kuka-island-core' ), array( $this, 'render_checklist' ), 'product', 'side', 'high' );
	}

	public function render_english_box( WP_Post $post ): void {
		wp_nonce_field( 'kuka_product_english', 'kuka_product_english_nonce' );
		echo '<p>' . esc_html__( 'Her satırda Türkçe kaynak solda, İngilizce karşılığı sağdadır. İngilizce alanı boşsa vitrinde Türkçe kaynak gösterilir. Fiyat, stok, SKU, varyasyonlar ve görseller iki dilde ortaktır.', 'kuka-island-core' ) . '</p>';
		echo '<h3>' . esc_html__( 'Uzun açıklama', 'kuka-island-core' ) . '</h3><div class="kuka-paired-fields"><div><p><strong>' . esc_html__( 'Türkçe', 'kuka-island-core' ) . '</strong></p>';
		wp_editor( $post->post_content, 'kuka_description_tr', array( 'textarea_name' => 'kuka_description_tr', 'textarea_rows' => 12, 'media_buttons' => true ) );
		echo '</div><div><p><strong>(EN)</strong></p>';
		wp_editor( (string) get_post_meta( $post->ID, '_kuka_description_en', true ), 'kuka_description_en', array( 'textarea_name' => '_kuka_description_en', 'textarea_rows' => 12, 'media_buttons' => true ) );
		echo '</div></div>';
		$this->render_pair( __( 'Kısa açıklama', 'kuka-island-core' ), 'kuka_short_description_tr', $post->post_excerpt, '_kuka_short_description_en', (string) get_post_meta( $post->ID, '_kuka_short_description_en', true ), 'textarea' );
		$labels = array(
			'_kuka_material' => __( 'Kumaş', 'kuka-island-core' ), '_kuka_care' => __( 'Bakım', 'kuka-island-core' ),
			'_kuka_fit' => __( 'Kalıp', 'kuka-island-core' ), '_kuka_model_info' => __( 'Model bilgisi', 'kuka-island-core' ),
			'_kuka_size_guide' => __( 'Beden rehberi ilişkisi', 'kuka-island-core' ), '_kuka_seo_title' => __( 'SEO başlığı', 'kuka-island-core' ),
			'_kuka_meta_description' => __( 'Meta açıklaması', 'kuka-island-core' ),
		);
		foreach ( $labels as $key => $label ) {
			$en_key = '_kuka_size_guide' === $key ? '' : $key . '_en';
			$this->render_pair( $label, $key, (string) get_post_meta( $post->ID, $key, true ), $en_key, $en_key ? (string) get_post_meta( $post->ID, $en_key, true ) : '', str_contains( $key, 'description' ) || in_array( $key, array( '_kuka_material', '_kuka_care' ), true ) ? 'textarea' : 'text' );
		}
	}

	private function render_pair( string $label, string $tr_key, string $tr_value, string $en_key, string $en_value, string $type ): void {
		echo '<h3>' . esc_html( $label ) . '</h3><div class="kuka-paired-fields"><div><label for="' . esc_attr( $tr_key ) . '"><strong>' . esc_html__( 'Türkçe', 'kuka-island-core' ) . '</strong></label>';
		$this->render_input( $tr_key, $tr_value, $type );
		echo '</div>';
		if ( $en_key ) {
			echo '<div><label for="' . esc_attr( $en_key ) . '"><strong>(EN)</strong></label>';
			$this->render_input( $en_key, $en_value, $type );
			echo '</div>';
		} else {
			echo '<div><p class="description">' . esc_html__( 'Bu teknik ilişki iki dilde ortaktır.', 'kuka-island-core' ) . '</p></div>';
		}
		echo '</div>';
	}

	private function render_input( string $key, string $value, string $type ): void {
		if ( 'textarea' === $type ) {
			echo '<textarea class="widefat" rows="4" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input class="widefat" type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
	}

	public function render_checklist( WP_Post $post ): void {
		$product = wc_get_product( $post->ID );
		$checks = array(
			__( 'Ürün adı', 'kuka-island-core' ) => '' !== trim( $post->post_title ),
			__( 'Uzun açıklama', 'kuka-island-core' ) => '' !== trim( wp_strip_all_tags( $post->post_content ) ),
			__( 'Kısa açıklama', 'kuka-island-core' ) => '' !== trim( wp_strip_all_tags( $post->post_excerpt ) ),
			__( 'Ürün görseli', 'kuka-island-core' ) => has_post_thumbnail( $post ),
			__( 'Kategori', 'kuka-island-core' ) => has_term( '', 'product_cat', $post ),
			__( 'Fiyat', 'kuka-island-core' ) => $product && '' !== $product->get_price(),
			__( 'SKU', 'kuka-island-core' ) => $product && '' !== $product->get_sku(),
			__( 'Renk niteliği', 'kuka-island-core' ) => $product && $product->get_attribute( 'pa_renk' ),
			__( 'Beden niteliği', 'kuka-island-core' ) => $product && $product->get_attribute( 'pa_beden' ),
		);
		foreach ( self::FIELDS as $key => $label ) { $checks[ $label ] = '' !== trim( (string) get_post_meta( $post->ID, $key, true ) ); }
		$missing = count( array_filter( $checks, static fn( bool $complete ): bool => ! $complete ) );
		echo '<div class="kuka-product-checklist"><p><strong>' . esc_html( sprintf( __( '%d eksik', 'kuka-island-core' ), $missing ) ) . '</strong></p><ul>';
		foreach ( $checks as $label => $complete ) { echo '<li class="' . ( $complete ? 'is-complete' : 'is-missing' ) . '">' . ( $complete ? '✓ ' : '○ ' ) . esc_html( $label ) . '</li>'; }
		echo '</ul><p class="description">' . esc_html__( 'Kontrol listesi yayınlamayı engellemez; eksikleri görünür kılar.', 'kuka-island-core' ) . '</p>';
		if ( 'publish' === $post->post_status ) { echo '<p><a href="' . esc_url( get_permalink( $post ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Sitede gör', 'kuka-island-core' ) . '</a></p>'; }
		echo '</div>';
	}

	public function save_english_box( int $post_id, WP_Post $post ): void {
		if ( $this->saving_editor ) { return; }
		if ( 'product' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! isset( $_POST['kuka_product_english_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kuka_product_english_nonce'] ) ), 'kuka_product_english' ) ) { return; }
		$this->saving_editor = true;
		wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_kses_post( wp_unslash( $_POST['kuka_description_tr'] ?? $post->post_content ) ), 'post_excerpt' => wp_kses_post( wp_unslash( $_POST['kuka_short_description_tr'] ?? $post->post_excerpt ) ) ) );
		$this->saving_editor = false;
		foreach ( array( '_kuka_name_en', '_kuka_seo_title_en', '_kuka_fit_en', '_kuka_model_info_en' ) as $key ) {
			update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
		foreach ( array( '_kuka_description_en', '_kuka_short_description_en', '_kuka_material_en', '_kuka_care_en', '_kuka_fit_en', '_kuka_model_info_en', '_kuka_meta_description_en' ) as $key ) {
			update_post_meta( $post_id, $key, wp_kses_post( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
		foreach ( array_keys( self::FIELDS ) as $key ) { update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) ); }
	}

	private static function translated_meta( WC_Product $product, string $key, string $fallback ): string {
		if ( ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() ) { return $fallback; }
		$id = $product instanceof WC_Product_Variation ? $product->get_parent_id() : $product->get_id();
		$value = trim( (string) get_post_meta( $id, $key, true ) );
		return '' !== $value ? $value : $fallback;
	}

	public function english_name( string $name, WC_Product $product ): string {
		return self::translated_meta( $product, '_kuka_name_en', $name );
	}

	public function english_variation_name( string $name, WC_Product $product ): string {
		$parent_name = self::translated_meta( $product, '_kuka_name_en', '' );
		if ( '' === $parent_name ) { return $name; }
		// `get_the_title()` is already localized by our title filter in an
		// English request. Read the stored parent title directly so the Turkish
		// prefix in WooCommerce's generated variation name can be replaced.
		$original_parent = (string) get_post_field( 'post_title', $product->get_parent_id() );
		return str_replace( $original_parent, $parent_name, $name );
	}

	/** Localize the immutable line-item snapshot on receipts and emails. */
	public function english_order_item_name( string $name, WC_Order_Item $item ): string {
		if ( ! $item instanceof WC_Order_Item_Product || ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() ) { return $name; }
		$product_id = $item->get_product_id();
		$english    = trim( (string) get_post_meta( $product_id, '_kuka_name_en', true ) );
		return '' === $english ? $name : str_replace( $item->get_name(), $english, $name );
	}

	public function english_description( string $description, WC_Product $product ): string {
		return self::translated_meta( $product, '_kuka_description_en', $description );
	}

	public function english_short_description( string $description, WC_Product $product ): string {
		return self::translated_meta( $product, '_kuka_short_description_en', $description );
	}

	public function english_structured_data( array $markup, WC_Product $product ): array {
		if ( function_exists( 'kuka_island_is_english' ) && kuka_island_is_english() ) {
			$markup['name'] = $product->get_name();
			$markup['description'] = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
		}
		return $markup;
	}

	public function english_post_title( string $title, int $post_id ): string {
		if ( is_admin() || ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() || 'product' !== get_post_type( $post_id ) ) { return $title; }
		$english = trim( (string) get_post_meta( $post_id, '_kuka_name_en', true ) );
		return '' !== $english ? $english : $title;
	}

	public function english_post_description( string $description ): string {
		if ( ! is_singular( 'product' ) || ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() ) { return $description; }
		$english = trim( (string) get_post_meta( get_queried_object_id(), '_kuka_description_en', true ) );
		return '' !== $english ? $english : $description;
	}

	public function english_post_short_description( string $description ): string {
		if ( ! is_singular( 'product' ) || ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() ) { return $description; }
		$english = trim( (string) get_post_meta( get_queried_object_id(), '_kuka_short_description_en', true ) );
		return '' !== $english ? $english : $description;
	}

	public function render_fields(): void {
		foreach ( self::FIELDS as $key => $label ) {
			woocommerce_wp_text_input(
				array(
					'id'          => $key,
					'label'       => esc_html( $label ),
					'desc_tip'    => true,
					'description' => esc_html__( 'Kuka Island ürün veri modeli alanı.', 'kuka-island-core' ),
				)
			);
		}
	}

	public function save_fields( WC_Product $product ): void {
		if ( ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}

		foreach ( self::FIELDS as $key => $label ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			$product->update_meta_data( $key, $value );
		}
	}
}
