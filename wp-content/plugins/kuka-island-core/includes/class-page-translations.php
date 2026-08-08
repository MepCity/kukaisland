<?php
/** English title/content meta for the existing WordPress page record. */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Page_Translations {
	private const LEGAL_SLUGS = array(
		'mesafeli-satis-sozlesmesi', 'on-bilgilendirme-formu', 'kullanim-kosullari', 'iade-degisim',
		'kvkk-aydinlatma-metni', 'gizlilik-politikasi', 'cerez-politikasi', 'acik-riza-metni',
	);

	public function register(): void {
		add_action( 'add_meta_boxes_page', array( $this, 'add_box' ) );
		add_action( 'save_post_page', array( $this, 'save' ), 20, 2 );
		add_filter( 'the_title', array( $this, 'title' ), 20, 2 );
		add_filter( 'the_content', array( $this, 'content' ), 8 );
	}

	public function add_box(): void {
		add_meta_box( 'kuka-page-english', __( 'English page content', 'kuka-island-core' ), array( $this, 'render_box' ), 'page', 'normal', 'high' );
	}

	public function render_box( WP_Post $post ): void {
		wp_nonce_field( 'kuka_page_english', 'kuka_page_english_nonce' );
		$title = (string) get_post_meta( $post->ID, '_kuka_title_en', true );
		$content = (string) get_post_meta( $post->ID, '_kuka_content_en', true );
		echo '<p>' . esc_html__( 'Leave empty to show the Turkish title and content. Shortcodes are processed in both languages.', 'kuka-island-core' ) . '</p>';
		if ( in_array( $post->post_name, self::LEGAL_SLUGS, true ) ) {
			echo '<p><strong>' . esc_html__( 'Legal translation must be supplied by the customer’s legal adviser; it is intentionally empty in the seed.', 'kuka-island-core' ) . '</strong></p>';
		}
		echo '<p><label for="kuka_title_en"><strong>' . esc_html__( 'Page title (EN)', 'kuka-island-core' ) . '</strong></label><br><input class="widefat" id="kuka_title_en" name="kuka_title_en" type="text" value="' . esc_attr( $title ) . '"></p>';
		echo '<p><label for="kuka_content_en"><strong>' . esc_html__( 'Page content (EN)', 'kuka-island-core' ) . '</strong></label><br><textarea class="widefat" rows="14" id="kuka_content_en" name="kuka_content_en">' . esc_textarea( $content ) . '</textarea></p>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( 'page' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! isset( $_POST['kuka_page_english_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kuka_page_english_nonce'] ) ), 'kuka_page_english' ) ) { return; }
		update_post_meta( $post_id, '_kuka_title_en', sanitize_text_field( wp_unslash( $_POST['kuka_title_en'] ?? '' ) ) );
		update_post_meta( $post_id, '_kuka_content_en', wp_kses_post( wp_unslash( $_POST['kuka_content_en'] ?? '' ) ) );
	}

	public function title( string $title, int $post_id ): string {
		if ( is_admin() || ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() || 'page' !== get_post_type( $post_id ) ) { return $title; }
		$english = trim( (string) get_post_meta( $post_id, '_kuka_title_en', true ) );
		if ( '' !== $english ) { return $english; }
		$technical_titles = array( 'magaza' => 'Shop', 'sepet' => 'Cart', 'odeme' => 'Checkout', 'hesabim' => 'My account' );
		return $technical_titles[ (string) get_post_field( 'post_name', $post_id ) ] ?? $title;
	}

	public function content( string $content ): string {
		if ( ! is_page() || ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() ) { return $content; }
		$post_id = get_queried_object_id();
		$english = trim( (string) get_post_meta( $post_id, '_kuka_content_en', true ) );
		if ( '' !== $english ) { return $english; }
		$slug = (string) get_post_field( 'post_name', $post_id );
		if ( in_array( $slug, array( 'magaza', 'sepet', 'odeme', 'hesabim' ), true ) ) { return $content; }
		$message = in_array( $slug, self::LEGAL_SLUGS, true )
			? __( 'The legally binding version of this document is Turkish. The Turkish text is shown below.', 'kuka-island-core' )
			: __( 'An English translation is not available yet. The Turkish content is shown below.', 'kuka-island-core' );
		return '<aside class="kuka-translation-notice" role="note">' . esc_html( $message ) . '</aside>' . $content;
	}
}
