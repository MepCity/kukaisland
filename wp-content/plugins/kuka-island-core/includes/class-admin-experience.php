<?php
/**
 * Locked editorial patterns and the daily Shop Manager workspace.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Admin_Experience {
	public function register(): void {
		add_action( 'init', array( $this, 'register_patterns' ) );
		add_action( 'admin_menu', array( $this, 'simplify_shop_manager_menu' ), 999 );
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
