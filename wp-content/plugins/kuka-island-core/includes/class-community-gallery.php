<?php
/** Operator-curated community photographs; no public submission endpoint. */
defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Community_Gallery {
	public const OPTION = 'kuka_island_community_gallery';
	public const PAGE = 'kuka-island-community';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_kuka_import_community', array( $this, 'import_initial' ) );
		add_action( 'admin_post_kuka_save_community', array( $this, 'save' ) );
	}

	public static function defaults(): array {
		return array(
			'enabled' => true,
			'eyebrow' => 'Sizden Gelenler', 'eyebrow_en' => 'From you',
			'title' => '#kukagirls🌴', 'title_en' => '#kukagirls🌴',
			'copy' => 'Kukalarınızı #kukagirls🌴 etiketi ile paylaşın @kukaisland galerisinde yer alın ✨',
			'copy_en' => 'Share your Kuka moments with #kukagirls🌴 and join the @kukaisland gallery ✨',
			'items' => array(),
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$data = array_replace( self::defaults(), is_array( $saved ) ? $saved : array() );
		$data['items'] = is_array( $data['items'] ) ? array_values( $data['items'] ) : array();
		return $data;
	}

	/** Only valid images explicitly published and selected for home reach the storefront. */
	public static function published_items(): array {
		return array_values( array_filter( self::get()['items'], static function ( $item ): bool {
			return is_array( $item ) && 'published' === ( $item['status'] ?? '' ) && ! empty( $item['home'] ) && wp_attachment_is_image( absint( $item['image_id'] ?? 0 ) );
		} ) );
	}

	/** Validate the entire editor before writing: malformed/truncated forms never erase records. */
	public static function sanitize( array $input ) {
		$result = self::defaults();
		$result['enabled'] = ! empty( $input['enabled'] );
		foreach ( array( 'eyebrow', 'title', 'copy' ) as $key ) {
			foreach ( array( '', '_en' ) as $suffix ) {
				$value = $input[ $key . $suffix ] ?? '';
				if ( ! is_scalar( $value ) ) { return new WP_Error( 'invalid_text', 'Metin alanını kontrol edin.' ); }
				$result[ $key . $suffix ] = sanitize_textarea_field( (string) $value );
			}
		}
		$items = $input['items'] ?? array();
		if ( ! is_array( $items ) || count( $items ) > 40 ) { return new WP_Error( 'limit', 'Bir galeride en fazla 40 fotoğraf saklayabilirsiniz.' ); }
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_filter( $item, 'is_array' ) ) { return new WP_Error( 'invalid_row', 'Fotoğraf alanlarını kontrol edin.' ); }
			$id = absint( $item['image_id'] ?? 0 );
			if ( ! wp_attachment_is_image( $id ) ) { return new WP_Error( 'invalid_image', 'Her kayıt için geçerli bir fotoğraf seçin.' ); }
			$url = trim( (string) ( $item['product_url'] ?? '' ) );
			$clean_url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' !== $url && ( ! filter_var( $clean_url, FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
				return new WP_Error( 'invalid_url', 'Ürün bağlantısını https:// ile başlayan tam adres olarak girin.' );
			}
			$row = array(
				'image_id' => $id,
				'product_url' => $clean_url,
				'status' => in_array( $item['status'] ?? '', array( 'draft', 'published', 'archived' ), true ) ? $item['status'] : 'draft',
				'home' => true,
			);
			$result['items'][] = $row;
		}
		return $result;
	}

	/** Idempotent explicit import of the four photographs supplied by the owner. */
	public static function seed_initial() {
		if ( false !== get_option( self::OPTION, false ) ) { return new WP_Error( 'exists', 'Galeri zaten kaydedilmiş; mevcut içerik korunuyor.' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$data = self::defaults();
		$photos = array(
			'01-white-flower.jpg' => array( 'Havuz kenarında beyaz çiçek detaylı bikini ve örgü takım', 'White floral bikini with a knitted cover-up by the pool' ),
			'02-geometric.jpg' => array( 'Deniz kenarında geometrik desenli bikini ve pareo', 'Geometric print bikini and matching sarong by the sea' ),
			'03-polka-dot.jpg' => array( 'Havuz kenarında kahverengi puantiyeli bikini', 'Brown polka-dot bikini by the pool' ),
			'04-brown.jpg' => array( 'Krem koltukta kahverengi bikini takımı', 'Brown bikini set on a cream sofa' ),
		);
		foreach ( $photos as $file => $descriptions ) {
			$existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => '_kuka_community_seed', 'meta_value' => $file ) );
			$id = $existing ? (int) $existing[0] : 0;
			if ( ! $id ) {
				$temp = wp_tempnam( $file );
				if ( ! $temp || ! copy( KUKA_ISLAND_CORE_PATH . 'assets/community/' . $file, $temp ) ) { return new WP_Error( 'copy', 'Başlangıç fotoğrafı hazırlanamadı.' ); }
				$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $temp ), 0, $descriptions[0] );
				if ( is_wp_error( $id ) ) { if ( file_exists( $temp ) ) { wp_delete_file( $temp ); } return $id; }
				update_post_meta( $id, '_kuka_community_seed', $file );
				update_post_meta( $id, '_wp_attachment_image_alt', $descriptions[0] );
			}
			$data['items'][] = array( 'image_id' => $id, 'status' => 'published', 'home' => true, 'product_url' => '' );
		}
		// add_option never replaces another operator's newly saved gallery.
		return add_option( self::OPTION, $data, '', false );
	}

	public function import_initial(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'upload_files' ) ) { wp_die( 'Bu işlem için yetkiniz yok.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'kuka_import_community' );
		$result = self::seed_initial();
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
		exit;
	}

	public function menu(): void {
		add_submenu_page( 'kuka-island', 'Sizden Gelenler', 'Sizden Gelenler', 'manage_woocommerce', self::PAGE, array( $this, 'render' ) );
	}

	public function assets( string $hook ): void {
		if ( 'kuka-island_page_' . self::PAGE !== $hook ) { return; }
		wp_enqueue_media();
		$base = plugin_dir_url( KUKA_ISLAND_CORE_FILE );
		wp_enqueue_script( 'kuka-community-admin', $base . 'assets/community-admin.js', array( 'jquery', 'jquery-ui-sortable' ), (string) filemtime( KUKA_ISLAND_CORE_PATH . 'assets/community-admin.js' ), true );
		wp_enqueue_style( 'kuka-community-admin', $base . 'assets/community-admin.css', array(), (string) filemtime( KUKA_ISLAND_CORE_PATH . 'assets/community-admin.css' ) );
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Bu işlem için yetkiniz yok.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'kuka_save_community' );
		if ( 'complete' !== ( $_POST['kuka_complete'] ?? '' ) || ! is_array( $_POST['gallery'] ?? null ) ) { wp_die( 'Form eksik ulaştı. Kayıtlar değiştirilmedi; geri dönüp yeniden deneyin.' ); }
		$result = self::sanitize( wp_unslash( $_POST['gallery'] ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) ); }
		// Reject a stale editor instead of silently overwriting another operator's changes.
		$revision = is_string( $_POST['revision'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['revision'] ) ) : '';
		if ( ! hash_equals( md5( wp_json_encode( self::get() ) ), $revision ) ) { wp_die( 'Galeri başka bir pencerede değiştirildi. Yenileyip tekrar deneyin.', '', array( 'back_link' => true ) ); }
		update_option( self::OPTION, $result, false );
		if ( self::get() !== $result ) { wp_die( 'Galeri kaydı doğrulanamadı. Yenileyip tekrar deneyin.', '', array( 'back_link' => true ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
		exit;
	}

	private function field( string $name, string $label, string $value, string $type = 'text' ): void {
		?><label><?php echo esc_html( $label ); ?><input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"></label><?php
	}

	public function row( $index, array $item ): void {
		$prefix = 'gallery[items][' . $index . ']';
		$id = absint( $item['image_id'] ?? 0 );
		?>
		<article class="kuka-community-row">
			<div class="kuka-community-row__tools"><strong data-row-title>Fotoğraf</strong><button type="button" class="button" data-move="up" aria-label="Fotoğrafı yukarı taşı">↑</button><button type="button" class="button" data-move="down" aria-label="Fotoğrafı aşağı taşı">↓</button><span class="description">Sıralamak için buradan sürükleyin</span><button type="button" class="button-link-delete" data-remove>Galeriden çıkar</button></div>
			<div class="kuka-community-row__body">
				<div><div class="kuka-community-preview"><?php if ( $id ) { echo wp_get_attachment_image( $id, 'medium' ); } ?></div><input type="hidden" data-image-id name="<?php echo esc_attr( $prefix . '[image_id]' ); ?>" value="<?php echo esc_attr( $id ); ?>"><button type="button" class="button" data-replace>Fotoğrafı değiştir</button></div>
				<div class="kuka-community-fields kuka-community-fields--photo">
				<?php $this->field( $prefix . '[product_url]', 'Ürün bağlantısı (isteğe bağlı)', (string) ( $item['product_url'] ?? '' ), 'url' ); ?>
				<p class="description">Fotoğrafa tıklandığında açılacak ürünün tam bağlantısını yapıştırın. Boşsa fotoğraf tıklanmaz.</p>
				<label>Durum<select name="<?php echo esc_attr( $prefix . '[status]' ); ?>"><?php foreach ( array( 'draft' => 'Taslak', 'published' => 'Yayında', 'archived' => 'Arşiv' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $item['status'] ?? 'draft', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				</div>
			</div>
		</article>
		<?php
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$data = self::get();
		?><div class="wrap" id="kuka-community-admin"><h1>Sizden Gelenler</h1><p>Sosyal medyadan seçtiğiniz fotoğrafları yükleyin, sıraya koyun ve yayımlayın. Arşivlenen fotoğraflar sitede görünmez.</p>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Galeri kaydedildi.</p></div><?php endif; ?>
		<?php if ( false === get_option( self::OPTION, false ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="kuka_import_community"><?php wp_nonce_field( 'kuka_import_community' ); ?><p><button class="button" type="submit">Onaylanan dört başlangıç fotoğrafını yükle</button></p></form><?php endif; ?>
		<form data-community-editor method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="kuka_save_community"><?php wp_nonce_field( 'kuka_save_community' ); ?><input type="hidden" name="revision" value="<?php echo esc_attr( md5( wp_json_encode( $data ) ) ); ?>">
		<label><input type="checkbox" name="gallery[enabled]" value="1" <?php checked( $data['enabled'] ); ?>> Ana sayfada Sizden Gelenler bölümünü göster</label>
		<details><summary>Bölüm başlığı ve açıklaması</summary><div class="kuka-community-fields"><?php foreach ( array( 'eyebrow' => 'Üst etiket', 'title' => 'Başlık', 'copy' => 'Açıklama' ) as $key => $label ) { foreach ( array( '' => 'TR', '_en' => 'EN' ) as $suffix => $lang ) { $this->field( 'gallery[' . $key . $suffix . ']', $label . ' (' . $lang . ')', (string) $data[ $key . $suffix ] ); } } ?></div></details>
		<p><button type="button" class="button" id="kuka-community-add">Fotoğraf ekle / toplu seç</button> <span id="kuka-community-count" aria-live="polite"></span></p>
		<div id="kuka-community-rows"><?php foreach ( $data['items'] as $index => $item ) { $this->row( $index, $item ); } ?></div>
		<input type="hidden" name="kuka_complete" value="complete"><div class="kuka-community-save"><?php submit_button( 'Galeriyi kaydet', 'primary', 'submit', false ); ?><span role="status" data-gallery-status>Değişiklikler kaydedildikten sonra siteye yansır.</span></div></form>
		<template id="kuka-community-template"><?php $this->row( '__INDEX__', array( 'home' => true ) ); ?></template></div><?php
	}
}
