<?php
/**
 * Required product content metadata.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Product_Fields {
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
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_fields' ) );
		add_action( 'add_meta_boxes_product', array( $this, 'add_english_box' ) );
		add_action( 'save_post_product', array( $this, 'save_english_box' ), 20, 2 );
		add_filter( 'woocommerce_product_get_name', array( $this, 'english_name' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_name', array( $this, 'english_variation_name' ), 10, 2 );
		add_filter( 'woocommerce_product_get_description', array( $this, 'english_description' ), 10, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( $this, 'english_short_description' ), 10, 2 );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'english_structured_data' ), 20, 2 );
	}

	public function add_english_box(): void {
		add_meta_box( 'kuka-product-english', __( 'English product content', 'kuka-island-core' ), array( $this, 'render_english_box' ), 'product', 'normal', 'high' );
	}

	public function render_english_box( WP_Post $post ): void {
		wp_nonce_field( 'kuka_product_english', 'kuka_product_english_nonce' );
		$fields = array(
			'_kuka_name_en' => array( 'Product name (EN)', 'text' ),
			'_kuka_description_en' => array( 'Long description (EN)', 'textarea' ),
			'_kuka_short_description_en' => array( 'Short description (EN)', 'textarea' ),
			'_kuka_seo_title_en' => array( 'SEO title (EN)', 'text' ),
			'_kuka_meta_description_en' => array( 'Meta description (EN)', 'textarea' ),
		);
		echo '<p>' . esc_html__( 'Leave an English field empty to show its Turkish source. Price, stock, SKU, variations and images remain shared.', 'kuka-island-core' ) . '</p>';
		foreach ( $fields as $key => $field ) {
			$value = (string) get_post_meta( $post->ID, $key, true );
			echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $field[0] ) . '</strong></label><br>';
			if ( 'textarea' === $field[1] ) {
				echo '<textarea class="widefat" rows="4" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
			} else {
				echo '<input class="widefat" type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
			}
			echo '</p>';
		}
	}

	public function save_english_box( int $post_id, WP_Post $post ): void {
		if ( 'product' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! isset( $_POST['kuka_product_english_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kuka_product_english_nonce'] ) ), 'kuka_product_english' ) ) { return; }
		foreach ( array( '_kuka_name_en', '_kuka_seo_title_en' ) as $key ) {
			update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
		foreach ( array( '_kuka_description_en', '_kuka_short_description_en', '_kuka_meta_description_en' ) as $key ) {
			update_post_meta( $post_id, $key, wp_kses_post( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
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
		$original_parent = get_the_title( $product->get_parent_id() );
		return str_replace( $original_parent, $parent_name, $name );
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
