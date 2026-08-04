<?php
/** Required classic-checkout legal acknowledgements. */
defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Legal_Displays {
	public function register(): void {
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render' ), 8 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ), 20 );
	}

	public function render(): void {
		$distance = get_page_by_path( 'mesafeli-satis-sozlesmesi' );
		$preinfo  = get_page_by_path( 'on-bilgilendirme-formu' );
		?>
		<div class="kuka-legal-consents">
			<?php /* translators: %s is the URL of the pre-information form. */ ?>
			<label><input type="checkbox" name="kuka_preinfo_accepted" value="1" required> <span><?php printf( wp_kses( __( '<a href="%s" target="_blank" rel="noopener">Ön Bilgilendirme Formu</a>’nu okudum ve kabul ediyorum.', 'kuka-island-core' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ), esc_url( $preinfo ? get_permalink( $preinfo ) : home_url( '/on-bilgilendirme-formu/' ) ) ); ?></span></label>
			<?php /* translators: %s is the URL of the distance sales agreement. */ ?>
			<label><input type="checkbox" name="kuka_distance_sales_accepted" value="1" required> <span><?php printf( wp_kses( __( '<a href="%s" target="_blank" rel="noopener">Mesafeli Satış Sözleşmesi</a>’ni okudum ve kabul ediyorum.', 'kuka-island-core' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ), esc_url( $distance ? get_permalink( $distance ) : home_url( '/mesafeli-satis-sozlesmesi/' ) ) ); ?></span></label>
		</div>
		<?php
	}

	public function validate(): void {
		if ( empty( $_POST['kuka_preinfo_accepted'] ) ) { wc_add_notice( __( 'Ön Bilgilendirme Formu onayı zorunludur.', 'kuka-island-core' ), 'error' ); }
		if ( empty( $_POST['kuka_distance_sales_accepted'] ) ) { wc_add_notice( __( 'Mesafeli Satış Sözleşmesi onayı zorunludur.', 'kuka-island-core' ), 'error' ); }
	}

	public function save( WC_Order $order ): void {
		$order->update_meta_data( '_kuka_preinfo_accepted', empty( $_POST['kuka_preinfo_accepted'] ) ? 'no' : 'yes' );
		$order->update_meta_data( '_kuka_distance_sales_accepted', empty( $_POST['kuka_distance_sales_accepted'] ) ? 'no' : 'yes' );
		$order->update_meta_data( '_kuka_legal_acceptance_time', current_time( 'mysql' ) );
	}
}
