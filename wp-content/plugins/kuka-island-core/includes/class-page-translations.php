<?php
/** English title/content meta for the existing WordPress page record. */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Page_Translations {
	private bool $saving_editor = false;
	private const LEGAL_SLUGS = array(
		'mesafeli-satis-sozlesmesi', 'on-bilgilendirme-formu', 'kullanim-kosullari', 'iade-degisim',
		'kvkk-aydinlatma-metni', 'gizlilik-politikasi', 'cerez-politikasi', 'acik-riza-metni',
	);

	public function register(): void {
		add_action( 'init', array( $this, 'prepare_page_editor' ), 99 );
		add_action( 'edit_form_after_title', array( $this, 'render_english_title' ) );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 20, 2 );
		add_action( 'add_meta_boxes_page', array( $this, 'add_box' ) );
		add_action( 'save_post_page', array( $this, 'save' ), 20, 2 );
		add_filter( 'the_title', array( $this, 'title' ), 20, 2 );
		add_filter( 'document_title_parts', array( $this, 'document_title' ), 20 );
		add_filter( 'the_content', array( $this, 'content' ), 8 );
	}

	public function title_placeholder( string $placeholder, WP_Post $post ): string {
		return 'page' === $post->post_type ? __( 'Sayfa başlığı (Türkçe)', 'kuka-island-core' ) : $placeholder;
	}

	public function prepare_page_editor(): void {
		remove_post_type_support( 'page', 'editor' );
	}

	public function render_english_title( WP_Post $post ): void {
		if ( 'page' !== $post->post_type ) { return; }
		$value = (string) get_post_meta( $post->ID, '_kuka_title_en', true );
		echo '<div class="postbox"><div class="inside"><label for="kuka_title_en"><strong>' . esc_html__( 'Sayfa başlığı (EN)', 'kuka-island-core' ) . '</strong></label><p class="description">' . esc_html__( 'Türkçe başlığın İngilizce karşılığı; boşsa Türkçe başlık gösterilir.', 'kuka-island-core' ) . '</p><input class="widefat" id="kuka_title_en" name="kuka_title_en" type="text" value="' . esc_attr( $value ) . '"></div></div>';
	}

	public function add_box(): void {
		add_meta_box( 'kuka-page-bilingual', __( 'Türkçe ve İngilizce sayfa içeriği', 'kuka-island-core' ), array( $this, 'render_box' ), 'page', 'normal', 'high' );
	}

	public function render_box( WP_Post $post ): void {
		wp_nonce_field( 'kuka_page_english', 'kuka_page_english_nonce' );
		$content = (string) get_post_meta( $post->ID, '_kuka_content_en', true );
		echo '<p>' . esc_html__( 'Türkçe kaynak solda, İngilizce karşılığı sağdadır. Kısa kodlar iki dilde de işlenir; İngilizce alanı boşsa Türkçe kaynak gösterilir.', 'kuka-island-core' ) . '</p>';
		if ( in_array( $post->post_name, self::LEGAL_SLUGS, true ) ) {
			echo '<p><strong>' . esc_html__( 'Yasal çeviri müşterinin hukuk danışmanı tarafından sağlanmalıdır; onay gelene kadar İngilizce alanı boş bırakılır.', 'kuka-island-core' ) . '</strong></p>';
		}
		echo '<div class="kuka-paired-fields"><div><p><strong>' . esc_html__( 'Türkçe', 'kuka-island-core' ) . '</strong></p>';
		wp_editor( $post->post_content, 'kuka_page_content_tr', array( 'textarea_name' => 'kuka_page_content_tr', 'textarea_rows' => 18, 'media_buttons' => true ) );
		echo '</div><div><p><strong>(EN)</strong></p>';
		wp_editor( $content, 'kuka_page_content_en', array( 'textarea_name' => 'kuka_content_en', 'textarea_rows' => 18, 'media_buttons' => true ) );
		echo '</div></div>';
		$meta_tr = (string) get_post_meta( $post->ID, '_kuka_meta_description', true );
		$meta_en = (string) get_post_meta( $post->ID, '_kuka_meta_description_en', true );
		echo '<div class="kuka-paired-fields kuka-page-meta-description"><div><label for="kuka_meta_description"><strong>' . esc_html__( 'Meta açıklaması (Türkçe)', 'kuka-island-core' ) . '</strong></label><p class="description">' . esc_html__( 'Arama sonuçlarında başlığın altında ve paylaşım kartlarında görünür; 150–160 karakter hedeflenir. Boşsa etiket basılmaz.', 'kuka-island-core' ) . '</p><textarea class="widefat" id="kuka_meta_description" name="kuka_meta_description" rows="3" maxlength="320">' . esc_textarea( $meta_tr ) . '</textarea></div><div><label for="kuka_meta_description_en"><strong>' . esc_html__( 'Meta açıklaması (EN)', 'kuka-island-core' ) . '</strong></label><p class="description">' . esc_html__( 'Boşsa İngilizce sayfada Türkçe açıklama kullanılır.', 'kuka-island-core' ) . '</p><textarea class="widefat" id="kuka_meta_description_en" name="kuka_meta_description_en" rows="3" maxlength="320">' . esc_textarea( $meta_en ) . '</textarea></div></div>';
		if ( 'publish' === $post->post_status ) { echo '<p><a href="' . esc_url( get_permalink( $post ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Sitede gör', 'kuka-island-core' ) . '</a></p>'; }
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( $this->saving_editor ) { return; }
		if ( 'page' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		if ( ! isset( $_POST['kuka_page_english_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kuka_page_english_nonce'] ) ), 'kuka_page_english' ) ) { return; }
		update_post_meta( $post_id, '_kuka_title_en', sanitize_text_field( wp_unslash( $_POST['kuka_title_en'] ?? '' ) ) );
		update_post_meta( $post_id, '_kuka_content_en', wp_kses_post( wp_unslash( $_POST['kuka_content_en'] ?? '' ) ) );
		// Meta açıklaması tek satırdır: satır sonu ve etiket taşımaz.
		update_post_meta( $post_id, '_kuka_meta_description', sanitize_text_field( wp_unslash( $_POST['kuka_meta_description'] ?? '' ) ) );
		update_post_meta( $post_id, '_kuka_meta_description_en', sanitize_text_field( wp_unslash( $_POST['kuka_meta_description_en'] ?? '' ) ) );
		$this->saving_editor = true;
		wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_kses_post( wp_unslash( $_POST['kuka_page_content_tr'] ?? $post->post_content ) ) ) );
		$this->saving_editor = false;
	}

	public function title( string $title, int $post_id = 0 ): string {
		if ( is_admin() || ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() || 'page' !== get_post_type( $post_id ) ) { return $title; }
		$english = trim( (string) get_post_meta( $post_id, '_kuka_title_en', true ) );
		if ( '' !== $english ) { return $english; }
		$technical_titles = array( 'magaza' => 'Shop', 'sepet' => 'Cart', 'odeme' => 'Checkout', 'hesabim' => 'My account' );
		return $technical_titles[ (string) get_post_field( 'post_name', $post_id ) ] ?? $title;
	}

	public function document_title( array $parts ): array {
		if ( ! is_page() || ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() ) { return $parts; }
		$english = trim( (string) get_post_meta( get_queried_object_id(), '_kuka_title_en', true ) );
		if ( '' !== $english ) { $parts['title'] = $english; }
		return $parts;
	}

	public function content( string $content ): string {
		if ( ! is_page() || ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() ) { return $content; }
		$post_id = get_queried_object_id();
		$english = trim( (string) get_post_meta( $post_id, '_kuka_content_en', true ) );
		if ( '' !== $english ) { return $english; }
		$slug = (string) get_post_field( 'post_name', $post_id );
		if ( in_array( $slug, array( 'magaza', 'sepet', 'odeme', 'hesabim' ), true ) ) { return $content; }
		if ( ! in_array( $slug, self::LEGAL_SLUGS, true ) ) { return $content; }
		$message = __( 'The legally binding version of this document is Turkish. The Turkish text is shown below.', 'kuka-island-core' );
		return '<aside class="kuka-translation-notice" role="note">' . esc_html( $message ) . '</aside>' . $content;
	}
}
