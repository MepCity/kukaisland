<?php
/** Classic checkout corporate billing fields. */
defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Corporate_Billing {
	public function register(): void {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'fields' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ), 10, 2 );
	}

	public function fields( array $fields ): array {
		$fields['billing']['billing_company'] = array( 'type' => 'text', 'label' => __( 'Şirket unvanı', 'kuka-island-core' ), 'required' => false, 'priority' => 30, 'class' => array( 'form-row-wide', 'kuka-corporate-field' ) );
		$fields['billing']['billing_customer_type'] = array(
			'type' => 'select', 'label' => __( 'Fatura türü', 'kuka-island-core' ), 'required' => true, 'priority' => 25,
			'options' => array( 'personal' => __( 'Bireysel', 'kuka-island-core' ), 'corporate' => __( 'Kurumsal', 'kuka-island-core' ) ), 'class' => array( 'form-row-wide' ),
		);
		$fields['billing']['billing_tax_office'] = array( 'type' => 'text', 'label' => __( 'Vergi dairesi', 'kuka-island-core' ), 'required' => false, 'priority' => 31, 'class' => array( 'form-row-first', 'kuka-corporate-field' ) );
		$fields['billing']['billing_tax_number'] = array( 'type' => 'text', 'label' => __( 'VKN (10 hane)', 'kuka-island-core' ), 'required' => false, 'priority' => 32, 'class' => array( 'form-row-last', 'kuka-corporate-field' ), 'maxlength' => 10, 'inputmode' => 'numeric' );
		return $fields;
	}

	public function validate( array $data, WP_Error $errors ): void {
		if ( 'corporate' !== ( $data['billing_customer_type'] ?? '' ) ) { return; }
		if ( empty( $data['billing_company'] ) ) { $errors->add( 'billing_company', __( 'Kurumsal fatura için şirket unvanı zorunludur.', 'kuka-island-core' ) ); }
		if ( empty( $data['billing_tax_office'] ) ) { $errors->add( 'billing_tax_office', __( 'Kurumsal fatura için vergi dairesi zorunludur.', 'kuka-island-core' ) ); }
		if ( ! preg_match( '/^\d{10}$/', (string) ( $data['billing_tax_number'] ?? '' ) ) ) { $errors->add( 'billing_tax_number', __( 'VKN 10 rakamdan oluşmalıdır.', 'kuka-island-core' ) ); }
	}

	public function save( WC_Order $order, array $data ): void {
		foreach ( array( 'billing_customer_type', 'billing_tax_office', 'billing_tax_number' ) as $key ) {
			$order->update_meta_data( '_' . $key, sanitize_text_field( $data[ $key ] ?? '' ) );
		}
	}
}
