<?php
/**
 * Locked editorial patterns and the daily Shop Manager workspace.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Admin_Experience {
	public function register(): void {
		add_action( 'init', array( $this, 'register_patterns' ) );
		add_action( 'admin_menu', array( $this, 'register_management_map' ), 25 );
		add_action( 'admin_menu', array( $this, 'simplify_shop_manager_menu' ), 999 );
	}

	/** Put the operator's task-to-screen directory under the shared Kuka Island menu. */
	public function register_management_map(): void {
		add_submenu_page(
			'kuka-island',
			__( 'Yönetim Haritası', 'kuka-island-core' ),
			__( 'Yönetim Haritası', 'kuka-island-core' ),
			'manage_woocommerce',
			'kuka-island-management-map',
			array( $this, 'render_management_map' )
		);
	}

	/** Direct, task-led links for an operator who should not need to learn menu topology. */
	public function render_management_map(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'kuka-island-core' ) );
		}
		$appearance = admin_url( 'admin.php?page=kuka-island-appearance' );
		$rows = array(
			array( __( 'Yeni ürün eklemek', 'kuka-island-core' ), __( 'Ürünler → Yeni ekle', 'kuka-island-core' ), admin_url( 'post-new.php?post_type=product' ) ),
			array( __( 'Ürün fiyatı veya stoğu değiştirmek', 'kuka-island-core' ), __( 'Ürünler → ilgili ürün', 'kuka-island-core' ), admin_url( 'edit.php?post_type=product' ) ),
			array( __( 'Ürünün İngilizcesini yazmak', 'kuka-island-core' ), __( 'Aynı ürün ekranında Türkçe alanların yanı', 'kuka-island-core' ), admin_url( 'edit.php?post_type=product' ) ),
			array( __( 'Kategori adını veya sırasını değiştirmek', 'kuka-island-core' ), __( 'Ürünler → Kategoriler', 'kuka-island-core' ), admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ),
			array( __( 'Renk kutucuğunun rengini değiştirmek', 'kuka-island-core' ), __( 'Ürünler → Nitelikler → Renk', 'kuka-island-core' ), admin_url( 'edit.php?post_type=product&page=product_attributes' ) ),
			array( __( 'Ana sayfa hero görselini veya metnini değiştirmek', 'kuka-island-core' ), __( 'Site Görünümü → Ana Hero', 'kuka-island-core' ), add_query_arg( 'tab', 'hero', $appearance ) ),
			array( __( 'Marka hikâyesi sahnelerini değiştirmek', 'kuka-island-core' ), __( 'Site Görünümü → Marka Hikâyesi', 'kuka-island-core' ), add_query_arg( 'tab', 'story', $appearance ) ),
			array( __( 'Ücretsiz kargo eşiğini değiştirmek', 'kuka-island-core' ), __( 'Site Görünümü → Ticari Bilgiler', 'kuka-island-core' ), add_query_arg( 'tab', 'commercial', $appearance ) ),
			array( __( 'Kargo ücretini değiştirmek', 'kuka-island-core' ), __( 'WooCommerce → Ayarlar → Gönderim', 'kuka-island-core' ), admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ),
			array( __( 'Yasal metinleri değiştirmek', 'kuka-island-core' ), __( 'Sayfalar', 'kuka-island-core' ), admin_url( 'edit.php?post_type=page' ) ),
			array( __( 'Şirket bilgilerini veya VKN’yi değiştirmek', 'kuka-island-core' ), __( 'Site Görünümü → Şirket ve Yasal', 'kuka-island-core' ), add_query_arg( 'tab', 'legal', $appearance ) ),
			array( __( 'WhatsApp numarasını değiştirmek', 'kuka-island-core' ), __( 'Site Görünümü → Marka', 'kuka-island-core' ), add_query_arg( 'tab', 'brand', $appearance ) ),
			array( __( 'Bülten kayıtlarını indirmek', 'kuka-island-core' ), __( 'Kuka Island → Bülten Kayıtları', 'kuka-island-core' ), admin_url( 'admin.php?page=kuka-newsletter' ) ),
			array( __( 'Siteyi yayına açmak', 'kuka-island-core' ), __( 'WooCommerce → Ayarlar → Site görünürlüğü', 'kuka-island-core' ), admin_url( 'admin.php?page=wc-settings&tab=site-visibility' ) ),
		);
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Yönetim Haritası', 'kuka-island-core' ); ?></h1>
		<p><?php esc_html_e( 'Yapmak istediğiniz işi seçin; bağlantı sizi doğrudan doğru ekrana götürür.', 'kuka-island-core' ); ?></p>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Ne yapmak istiyorum?', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'Nereden?', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'Bağlantı', 'kuka-island-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( $row[0] ); ?></td><td><?php echo esc_html( $row[1] ); ?></td><td><a class="button" href="<?php echo esc_url( $row[2] ); ?>"><?php esc_html_e( 'Ekrana git', 'kuka-island-core' ); ?></a></td></tr><?php endforeach; ?>
		</tbody></table></div>
		<?php
	}

	/** Register content-editable patterns whose structure cannot be moved or removed. */
	public function register_patterns(): void {
		register_block_pattern_category( 'kuka-island', array( 'label' => __( 'Kuka Island', 'kuka-island-core' ) ) );
		register_block_pattern(
			'kuka-island/editorial-story',
			array(
				'title'       => __( 'Kilitli editoryal hikâye', 'kuka-island-core' ),
				'description' => __( 'Başlık ve metin değiştirilebilir; iki sütunlu düzen kilitlidir.', 'kuka-island-core' ),
				'categories'  => array( 'kuka-island' ),
				'content'     => '<!-- wp:group {"templateLock":"all","className":"kuka-content-pattern"} --><div class="wp-block-group kuka-content-pattern"><!-- wp:columns {"templateLock":"all"} --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading --><h2 class="wp-block-heading">' . esc_html__( 'Hikâye başlığı', 'kuka-island-core' ) . '</h2><!-- /wp:heading --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>' . esc_html__( 'Editoryal metninizi buraya yazın.', 'kuka-island-core' ) . '</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->',
			)
		);
		register_block_pattern(
			'kuka-island/legal-section',
			array(
				'title'       => __( 'Kilitli yasal bölüm', 'kuka-island-core' ),
				'description' => __( 'Başlık ve paragraflar değiştirilebilir; bölüm yapısı kilitlidir.', 'kuka-island-core' ),
				'categories'  => array( 'kuka-island' ),
				'content'     => '<!-- wp:group {"templateLock":"all","className":"kuka-content-pattern"} --><div class="wp-block-group kuka-content-pattern"><!-- wp:heading --><h2 class="wp-block-heading">' . esc_html__( 'Bölüm başlığı', 'kuka-island-core' ) . '</h2><!-- /wp:heading --><!-- wp:paragraph --><p>' . esc_html__( 'Onaylı metni buraya yazın.', 'kuka-island-core' ) . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			)
		);
	}

	/** Keep the daily operator focused on media, content, products, orders and Site Appearance. */
	public function simplify_shop_manager_menu(): void {
		$user = wp_get_current_user();
		if ( ! in_array( 'shop_manager', (array) $user->roles, true ) ) { return; }
		foreach ( array( 'edit.php', 'edit-comments.php', 'themes.php', 'plugins.php', 'users.php', 'tools.php', 'options-general.php' ) as $slug ) {
			remove_menu_page( $slug );
		}
	}
}
