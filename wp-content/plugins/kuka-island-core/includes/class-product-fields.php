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
	);

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_fields' ) );
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

