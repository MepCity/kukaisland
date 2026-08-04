<?php
/**
 * Faz 4 Site Görünümü panelinin navigation placeholder'ı.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Site_Appearance {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Kuka Island', 'kuka-island-core' ),
			__( 'Kuka Island', 'kuka-island-core' ),
			'manage_options',
			'kuka-island',
			array( $this, 'render_page' ),
			'dashicons-store',
			58
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'kuka-island-core' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Kuka Island / Site Görünümü', 'kuka-island-core' ); ?></h1>
			<p><?php echo esc_html__( 'Alan sözleşmesi Faz 2 aktarma haritasında tanımlıdır; düzenleme arayüzü Faz 4 kapsamındadır.', 'kuka-island-core' ); ?></p>
		</div>
		<?php
	}
}

