<?php
/**
 * Product-to-product pairing metadata; pricing/cart behavior is intentionally absent.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Combination_Relations {
	public function register(): void {
		add_action( 'woocommerce_product_options_related', array( $this, 'render_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_field' ) );
	}

	public function render_field(): void {
		woocommerce_wp_text_input(
			array(
				'id'                => '_kuka_paired_product_id',
				'label'             => esc_html__( 'Eşleşen parça ürün ID', 'kuka-island-core' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
				'description'       => esc_html__( 'Faz 2 yalnız ilişkiyi saklar; kombin fiyatı ve stok davranışı uygulanmaz.', 'kuka-island-core' ),
			)
		);
	}

	public function save_field( WC_Product $product ): void {
		if ( ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}

		$value = isset( $_POST['_kuka_paired_product_id'] ) ? absint( wp_unslash( $_POST['_kuka_paired_product_id'] ) ) : 0;
		$product->update_meta_data( '_kuka_paired_product_id', $value );
	}
}

