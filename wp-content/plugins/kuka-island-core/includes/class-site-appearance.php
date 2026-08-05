<?php
/**
 * Site Appearance data contract and administration screen.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Site_Appearance {
	public const OPTION_NAME = 'kuka_island_site_content';
	private const CAPABILITY = 'manage_woocommerce';
	/** @var array<int, string> */
	private static array $sanitize_notices = array();

	/**
	 * Register the settings page and the explicit, nonce-protected save action.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_kuka_island_save_site_appearance', array( $this, 'save' ) );
	}

	/**
	 * Keep the free-shipping threshold in sync with WooCommerce so the panel
	 * announcement, the cart drawer progress message and the checkout shipping
	 * rule all read from a single source. The panel is that source (§15.2 lists
	 * the commercial messages under Site Appearance); on save it writes the
	 * matched free_shipping method min_amount.
	 */
	public static function sync_free_shipping_threshold(): void {
		$threshold = (float) ( self::get()['commercial']['free_shipping_threshold'] ?? 0 );

		// Write every free_shipping instance option directly from the options table.
		// Going through WC_Shipping_Zones would read cached, sometimes stale, runtime
		// method objects; the persisted option is the single source the checkout reads.
		global $wpdb;
		$keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'woocommerce_free_shipping_' ) . '%_settings'
			)
		);

		foreach ( $keys as $option_key ) {
			$settings                     = get_option( $option_key, array() );
			$settings                     = is_array( $settings ) ? $settings : array();
			$settings['title']            = $settings['title'] ?? __( 'Ücretsiz kargo', 'kuka-island-core' );
			$settings['requires']         = $threshold > 0 ? 'min_amount' : '';
			$settings['min_amount']        = (string) $threshold;
			$settings['ignore_discounts'] = $settings['ignore_discounts'] ?? 'no';
			update_option( $option_key, $settings, false );
		}
	}

	/**
	 * Defaults are intentionally content-only. Design tokens remain in the theme.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'brand' => array(
				'logo_id' => 0, 'mobile_logo_id' => 0, 'favicon_id' => 0, 'social_share_image_id' => 0,
				'email' => 'hello@kukaisland.com', 'phone' => '+90 850 000 00 00', 'whatsapp_url' => 'https://wa.me/908500000000',
				'social_links' => "Instagram|https://www.instagram.com/\nPinterest|https://www.pinterest.com/",
			),
			'announcement' => array(
				'enabled' => true,
				'items' => array( '1.500 TL üzeri siparişlerde ücretsiz kargo', 'Kolay değişim desteği', 'Güvenli ödeme' ),
				'link_labels' => array( '', '', '' ), 'link_urls' => array( '', '', '' ),
			),
			'hero' => array(
				'enabled' => true, 'desktop_image_id' => 0, 'mobile_image_id' => 0, 'eyebrow' => 'KUKA ISLAND / YENİ SEZON',
				'title' => 'Adanın ritmini yanında taşı.', 'copy' => 'Gün boyu hareket eden, sade ve güçlü parçalar.',
				'button_label' => 'Yeni gelenleri keşfet', 'button_url' => '/magaza/', 'alignment' => 'left', 'text_tone' => 'light',
			),
			'home' => array(
				'category_index_enabled' => true, 'category_index_label' => 'Formunu bul', 'category_index_title' => 'Ürün kategorileri',
				'new_arrivals_enabled' => true, 'new_arrivals_title' => 'Yeni Gelenler', 'new_arrivals_copy' => 'Yeni sezon seçkisi.',
				'new_arrivals_source' => 'latest', 'source_category' => '', 'source_collection' => '', 'manual_product_ids' => '', 'presentation' => 'grid',
				'card_swatches_enabled' => true, 'card_stock_enabled' => true,
				'editorial_enabled' => true, 'editorial_title' => 'Ada Günlüğü', 'editorial_copy' => 'Şehirden kıyıya uzanan günlük üniforma.',
				'editorial_image_id' => 0, 'editorial_video_id' => 0, 'editorial_url' => '/hakkimizda/', 'editorial_link_label' => 'Hikâyeyi oku',
				'manifesto_enabled' => true, 'manifesto_title' => 'Az, iyi ve uzun ömürlü.', 'manifesto_copy' => 'Her parçayı tekrar tekrar giymek için tasarlıyoruz.',
				'services_enabled' => true, 'service_1' => 'Güvenli ödeme', 'service_2' => 'Kolay değişim', 'service_3' => 'Destek hattı',
			),
			'navigation' => array(
				'main' => "Yeni Gelenler|/magaza/?orderby=date\nMarka / Hikâyemiz|/hakkimizda/",
				'categories' => "Bikini|/kategori/bikini-ustleri/|1|1\nMayo|/kategori/mayolar/|1|1\nPlaj Giyim|/kategori/plaj-giyim/|1|1\nKoleksiyonlar|/magaza/|1|0",
				'help' => "Beden Rehberi|/beden-rehberi/\nSık Sorulan Sorular|/sik-sorulan-sorular/\nİletişim|/iletisim/",
			),
			'footer' => array(
				'brand_copy' => 'Günlük hayatın her ritmine uyum sağlayan zamansız parçalar.', 'newsletter_enabled' => true,
				'newsletter_eyebrow' => 'Ada mektupları', 'newsletter_title' => 'Ada mektuplarına katıl',
				'newsletter_copy' => 'Yeni koleksiyonlar ve stüdyo notları için e-posta listemize katıl.',
				'newsletter_consent' => 'Gizlilik politikasını okudum ve iletişim izni veriyorum.',
				'company_name' => 'Kuka Island Tekstil Ltd. Şti.', 'company_address' => '[Şirket adresi hukuk/onay sonrası eklenecek]',
				'help_links' => "Beden Rehberi|/beden-rehberi/\nSık Sorulan Sorular|/sik-sorulan-sorular/\nİletişim|/iletisim/",
				'legal_links' => "Gizlilik Politikası|/gizlilik-politikasi/\nÇerez Politikası|/cerez-politikasi/\nKVKK Aydınlatma Metni|/kvkk-aydinlatma-metni/\nMesafeli Satış Sözleşmesi|/mesafeli-satis-sozlesmesi/",
			),
			'commercial' => array(
				'free_shipping_threshold' => 1500, 'shipping_copy' => '1.500 TL üzeri siparişlerde ücretsiz kargo.',
				'free_shipping_remaining_copy' => 'Ücretsiz kargo için %s daha ekleyin.', 'free_shipping_ready_copy' => 'Ücretsiz kargo hakkınız hazır.',
				'flat_rate_copy' => 'Standart gönderim bedeli ödeme adımında hesaplanır.',
				'exchange_copy' => 'Değişim talebinizi teslimattan sonra 14 gün içinde iletebilirsiniz.',
				'secure_payment_copy' => 'Ödeme bilgileriniz güvenli bağlantı üzerinden işlenir.', 'support_hours' => 'Hafta içi 09.00–18.00',
			),
			'panels' => array(
				'account_greeting' => 'Tekrar hoş geldiniz.', 'account_copy' => 'E-posta adresiniz ve şifrenizle giriş yapın.',
			),
		);
	}

	/**
	 * Return persisted content merged recursively over safe defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_NAME, array() );
		$legacy_main = "Yeni Gelenler|/magaza/?orderby=date\nTüm Ürünler|/magaza/\nHakkımızda|/hakkimizda/";
		if ( is_array( $saved ) && $legacy_main === ( $saved['navigation']['main'] ?? '' ) ) {
			$saved['navigation']['main'] = self::defaults()['navigation']['main'];
		}
		return self::merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** @return array<string, array<string, array<string, mixed>>> */
	private static function fields(): array {
		return array(
			'brand'        => array(
				'label'  => __( '1. Marka', 'kuka-island-core' ),
				'fields' => array(
					'logo_id'               => array( __( 'Logo', 'kuka-island-core' ), 'media_image' ),
					'mobile_logo_id'        => array( __( 'Mobil logo', 'kuka-island-core' ), 'media_image' ),
					'favicon_id'            => array( __( 'Favicon', 'kuka-island-core' ), 'media_image' ),
					'social_share_image_id' => array( __( 'Sosyal paylaşım görseli', 'kuka-island-core' ), 'media_image' ),
					'email'                 => array( __( 'E-posta', 'kuka-island-core' ), 'email' ),
					'phone'                 => array( __( 'Telefon', 'kuka-island-core' ), 'text' ),
					'whatsapp_url'          => array( __( 'WhatsApp URL', 'kuka-island-core' ), 'url' ),
					'social_links'          => array( __( 'Sosyal bağlantılar (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
				),
			),
			'announcement' => array(
				'label'  => __( '2. Duyuru Bandı', 'kuka-island-core' ),
				'fields' => array(
					'enabled' => array( __( 'Bandı göster', 'kuka-island-core' ), 'checkbox' ),
					'items'   => array( __( 'Duyurular (satır başına bir, en fazla 3)', 'kuka-island-core' ), 'lines' ),
					'link_labels' => array( __( 'Duyuru bağlantı etiketleri (satır sırasıyla)', 'kuka-island-core' ), 'lines' ),
					'link_urls' => array( __( 'Duyuru bağlantı URL’leri (satır sırasıyla)', 'kuka-island-core' ), 'url_lines' ),
				),
			),
			'hero'         => array(
				'label'  => __( '3. Ana Hero', 'kuka-island-core' ),
				'fields' => array(
					'enabled'          => array( __( 'Hero göster', 'kuka-island-core' ), 'checkbox' ),
					'desktop_image_id' => array( __( 'Masaüstü görsel', 'kuka-island-core' ), 'media_image' ),
					'mobile_image_id'  => array( __( 'Mobil görsel', 'kuka-island-core' ), 'media_image' ),
					'eyebrow'          => array( __( 'Üst başlık', 'kuka-island-core' ), 'text' ),
					'title'            => array( __( 'Başlık', 'kuka-island-core' ), 'text' ),
					'copy'             => array( __( 'Metin', 'kuka-island-core' ), 'textarea' ),
					'button_label'     => array( __( 'Buton etiketi', 'kuka-island-core' ), 'text' ),
					'button_url'       => array( __( 'Buton URL', 'kuka-island-core' ), 'url' ),
					'alignment'        => array( __( 'Hizalama (left/center/right)', 'kuka-island-core' ), 'alignment' ),
					'text_tone'        => array( __( 'Metin tonu (light/dark)', 'kuka-island-core' ), 'tone' ),
				),
			),
			'home'         => array(
				'label'  => __( '4. Ana Sayfa Bölümleri', 'kuka-island-core' ),
				'fields' => array(
					'category_index_enabled' => array( __( 'Kesim indeksini göster', 'kuka-island-core' ), 'checkbox' ),
					'category_index_label'   => array( __( 'Kesim indeksi etiketi', 'kuka-island-core' ), 'text' ),
					'category_index_title'   => array( __( 'Kesim indeksi erişilebilir başlığı', 'kuka-island-core' ), 'text' ),
					'new_arrivals_enabled'   => array( __( 'Yeni gelenleri göster', 'kuka-island-core' ), 'checkbox' ),
					'new_arrivals_title' => array( __( 'Yeni gelenler başlığı', 'kuka-island-core' ), 'text' ),
					'new_arrivals_copy' => array( __( 'Yeni gelenler metni', 'kuka-island-core' ), 'textarea' ),
					'new_arrivals_source' => array( __( 'Ürün kaynağı (latest/featured/sale/manual)', 'kuka-island-core' ), 'product_source' ),
					'source_category' => array( __( 'Kaynak kategori slug', 'kuka-island-core' ), 'slug' ),
					'source_collection' => array( __( 'Kaynak koleksiyon slug', 'kuka-island-core' ), 'slug' ),
					'manual_product_ids' => array( __( 'Manuel ürün ID’leri (virgülle)', 'kuka-island-core' ), 'ids' ),
					'presentation' => array( __( 'Sunum (grid/carousel)', 'kuka-island-core' ), 'presentation' ),
					'card_swatches_enabled' => array( __( 'Kart renk swatch’larını göster', 'kuka-island-core' ), 'checkbox' ),
					'card_stock_enabled' => array( __( 'Kart beden/stok satırını göster', 'kuka-island-core' ), 'checkbox' ),
					'editorial_enabled' => array( __( 'Editoryal bölümü göster', 'kuka-island-core' ), 'checkbox' ),
					'editorial_title'    => array( __( 'Editoryal başlık', 'kuka-island-core' ), 'text' ),
					'editorial_copy'     => array( __( 'Editoryal metin', 'kuka-island-core' ), 'textarea' ),
					'editorial_image_id' => array( __( 'Editoryal görsel', 'kuka-island-core' ), 'media_image' ),
					'editorial_video_id' => array( __( 'Editoryal video', 'kuka-island-core' ), 'media_video' ),
					'editorial_url'      => array( __( 'Editoryal URL', 'kuka-island-core' ), 'url' ),
					'editorial_link_label' => array( __( 'Editoryal bağlantı etiketi', 'kuka-island-core' ), 'text' ),
					'manifesto_enabled' => array( __( 'Manifestoyu göster', 'kuka-island-core' ), 'checkbox' ),
					'manifesto_title'    => array( __( 'Manifesto başlığı', 'kuka-island-core' ), 'text' ),
					'manifesto_copy'     => array( __( 'Manifesto metni', 'kuka-island-core' ), 'textarea' ),
					'services_enabled'   => array( __( 'Hizmet satırını göster', 'kuka-island-core' ), 'checkbox' ),
					'service_1'          => array( __( 'Hizmet 1', 'kuka-island-core' ), 'text' ),
					'service_2'          => array( __( 'Hizmet 2', 'kuka-island-core' ), 'text' ),
					'service_3'          => array( __( 'Hizmet 3', 'kuka-island-core' ), 'text' ),
				),
			),
			'navigation'   => array(
				'label'  => __( '5. Navigasyon', 'kuka-island-core' ),
				'fields' => array(
					'main' => array( __( 'Sabit üst menü bağlantıları (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
					'categories' => array( __( 'Kategori görünürlüğü', 'kuka-island-core' ), 'category_navigation' ),
					'help' => array( __( 'Yardım menüsü (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
				),
			),
			'footer'       => array(
				'label'  => __( '6. Footer', 'kuka-island-core' ),
				'fields' => array(
					'brand_copy'         => array( __( 'Marka metni', 'kuka-island-core' ), 'textarea' ),
					'newsletter_enabled' => array( __( 'Bülteni göster', 'kuka-island-core' ), 'checkbox' ),
					'newsletter_eyebrow' => array( __( 'Bülten üst başlığı', 'kuka-island-core' ), 'text' ),
					'newsletter_title'   => array( __( 'Bülten başlığı', 'kuka-island-core' ), 'text' ),
					'newsletter_copy'    => array( __( 'Bülten metni', 'kuka-island-core' ), 'textarea' ),
					'newsletter_consent' => array( __( 'Bülten onay metni', 'kuka-island-core' ), 'textarea' ),
					'company_name'       => array( __( 'Şirket unvanı', 'kuka-island-core' ), 'text' ),
					'company_address'    => array( __( 'Şirket adresi', 'kuka-island-core' ), 'textarea' ),
					'help_links'         => array( __( 'Yardım bağlantıları (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
					'legal_links'        => array( __( 'Yasal bağlantılar (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
				),
			),
			'commercial'   => array(
				'label'  => __( '7. Ticari Bilgiler', 'kuka-island-core' ),
				'fields' => array(
					'free_shipping_threshold' => array( __( 'Ücretsiz kargo eşiği (TL)', 'kuka-island-core' ), 'number' ),
					'shipping_copy'             => array( __( 'Kargo metni', 'kuka-island-core' ), 'textarea' ),
					'free_shipping_remaining_copy' => array( __( 'Eşiğe kalan kargo metni (%s fiyat)', 'kuka-island-core' ), 'textarea' ),
					'free_shipping_ready_copy' => array( __( 'Eşik tamamlandı metni', 'kuka-island-core' ), 'textarea' ),
					'flat_rate_copy'            => array( __( 'Sabit kargo metni', 'kuka-island-core' ), 'textarea' ),
					'exchange_copy'             => array( __( 'Değişim metni', 'kuka-island-core' ), 'textarea' ),
					'secure_payment_copy'       => array( __( 'Güvenli ödeme metni', 'kuka-island-core' ), 'textarea' ),
					'support_hours'             => array( __( 'Destek saatleri', 'kuka-island-core' ), 'text' ),
				),
			),
			'panels'       => array(
				'label'  => __( '8. Panel Metinleri', 'kuka-island-core' ),
				'fields' => array(
					'account_greeting' => array( __( 'Hesap karşılama başlığı', 'kuka-island-core' ), 'text' ),
					'account_copy'     => array( __( 'Hesap kısa açıklaması', 'kuka-island-core' ), 'textarea' ),
				),
			),
		);
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Kuka Island', 'kuka-island-core' ),
			__( 'Kuka Island', 'kuka-island-core' ),
			self::CAPABILITY,
			'kuka-island',
			array( $this, 'render_start_page' ),
			'dashicons-store',
			58
		);
		add_submenu_page( 'kuka-island', __( 'Başlangıç', 'kuka-island-core' ), __( 'Başlangıç', 'kuka-island-core' ), self::CAPABILITY, 'kuka-island', array( $this, 'render_start_page' ) );
		add_submenu_page( 'kuka-island', __( 'Site Görünümü', 'kuka-island-core' ), __( 'Site Görünümü', 'kuka-island-core' ), self::CAPABILITY, 'kuka-island-appearance', array( $this, 'render_page' ) );
	}

	/** Load WordPress' media selector only on the Site Appearance screen. */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'kuka-island_page_kuka-island-appearance' !== $hook ) { return; }
		wp_enqueue_media();
		wp_enqueue_script(
			'kuka-island-site-appearance',
			plugins_url( 'assets/site-appearance.js', KUKA_ISLAND_CORE_FILE ),
			array( 'jquery' ),
			'0.1.0',
			true
		);
	}

	/** Daily operator map with direct links to the relevant native screens. */
	public function render_start_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'kuka-island-core' ) );
		}
		$routes = array(
			__( 'Ürün / stok / fotoğraf', 'kuka-island-core' ) => admin_url( 'edit.php?post_type=product' ),
			__( 'Renk swatch rengi', 'kuka-island-core' ) => admin_url( 'edit.php?post_type=product&page=product_attributes' ),
			__( 'Hero, duyuru, bölüm başlıkları', 'kuka-island-core' ) => admin_url( 'admin.php?page=kuka-island-appearance' ),
			__( 'Kargo ücreti / eşik', 'kuka-island-core' ) => admin_url( 'admin.php?page=kuka-island-appearance#commercial' ),
			__( 'Siparişler', 'kuka-island-core' ) => admin_url( 'admin.php?page=wc-orders' ),
			__( 'Sayfa metinleri', 'kuka-island-core' ) => admin_url( 'edit.php?post_type=page' ),
		);
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Kuka Island / Başlangıç', 'kuka-island-core' ); ?></h1><p><?php esc_html_e( 'Değiştirmek istediğiniz içeriğin yönetim ekranına buradan gidin.', 'kuka-island-core' ); ?></p><table class="widefat striped" style="max-width:900px"><tbody>
		<?php foreach ( $routes as $label => $url ) : ?><tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Aç', 'kuka-island-core' ); ?></a></td></tr><?php endforeach; ?>
		</tbody></table></div>
		<?php
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'kuka-island-core' ) );
		}

		$content = self::get();
		?>
		<div class="wrap kuka-island-settings">
			<h1><?php esc_html_e( 'Kuka Island / Site Görünümü', 'kuka-island-core' ); ?></h1>
			<p><?php esc_html_e( 'Yalnızca içerik ve ticari metinler burada yönetilir. Renk, tipografi ve ölçüler temanın tasarım sözleşmesindedir.', 'kuka-island-core' ); ?></p>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Site görünümü kaydedildi.', 'kuka-island-core' ); ?></p></div>
			<?php endif; ?>
			<?php $notices = get_transient( 'kuka_island_site_notices_' . get_current_user_id() ); delete_transient( 'kuka_island_site_notices_' . get_current_user_id() ); ?>
			<?php if ( is_array( $notices ) && $notices ) : ?><div class="notice notice-warning"><p><?php esc_html_e( 'Bazı bağlantı satırları kaydedilmedi:', 'kuka-island-core' ); ?></p><ul><?php foreach ( $notices as $notice ) : ?><li><?php echo esc_html( $notice ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="kuka_island_save_site_appearance">
				<?php wp_nonce_field( 'kuka_island_save_site_appearance' ); ?>
				<?php foreach ( self::fields() as $group_key => $group ) : ?>
					<fieldset id="<?php echo esc_attr( $group_key ); ?>" style="max-width:920px;background:#fff;border:1px solid #c3c4c7;margin:20px 0;padding:20px">
						<legend style="font-size:16px;font-weight:600;padding:0 8px"><?php echo esc_html( $group['label'] ); ?></legend>
						<table class="form-table" role="presentation"><tbody>
						<?php foreach ( $group['fields'] as $field_key => $field ) : ?>
							<?php $this->render_field( $group_key, $field_key, $field, $content[ $group_key ][ $field_key ] ?? '' ); ?>
						<?php endforeach; ?>
						</tbody></table>
					</fieldset>
				<?php endforeach; ?>
				<?php submit_button( __( 'Site görünümünü kaydet', 'kuka-island-core' ) ); ?>
			</form>
		</div>
		<?php
	}

	/** @param array<int, string> $field */
	private function render_field( string $group_key, string $field_key, array $field, mixed $value ): void {
		$name = sprintf( 'site_content[%s][%s]', $group_key, $field_key );
		$type = $field[1];
		if ( in_array( $type, array( 'lines', 'url_lines' ), true ) && is_array( $value ) ) {
			$value = implode( "\n", $value );
		}
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
			<td>
			<?php if ( 'checkbox' === $type ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<input id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $value ); ?>>
			<?php elseif ( in_array( $type, array( 'media_image', 'media_video' ), true ) ) : ?>
				<div data-kuka-media-field data-media-type="<?php echo esc_attr( 'media_video' === $type ? 'video' : 'image' ); ?>">
					<input class="small-text" id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" type="number" min="0" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" readonly>
					<button class="button" type="button" data-kuka-media-select><?php esc_html_e( 'Medyadan seç', 'kuka-island-core' ); ?></button>
					<button class="button-link-delete" type="button" data-kuka-media-clear><?php esc_html_e( 'Temizle', 'kuka-island-core' ); ?></button>
					<span data-kuka-media-preview><?php echo $value ? wp_get_attachment_image( absint( $value ), array( 80, 80 ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</div>
			<?php elseif ( 'category_navigation' === $type ) : ?>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Kategori', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'URL', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'Üst menü', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'Ana sayfa indeksi', 'kuka-island-core' ); ?></th></tr></thead><tbody>
				<?php foreach ( self::parse_category_navigation( (string) $value ) as $index => $item ) : ?>
					<tr><td><input type="text" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $item['label'] ); ?>"></td><td><input type="text" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][url]" value="<?php echo esc_attr( $item['url'] ); ?>"></td><td><input type="hidden" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][header]" value="0"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][header]" value="1" <?php checked( $item['header'] ); ?>></td><td><input type="hidden" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][home]" value="0"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][home]" value="1" <?php checked( $item['home'] ); ?>></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php elseif ( in_array( $type, array( 'textarea', 'lines', 'url_lines', 'link_lines' ), true ) ) : ?>
				<textarea class="large-text" rows="4" id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" name="<?php echo esc_attr( $name ); ?>"><?php echo esc_textarea( (string) $value ); ?></textarea>
			<?php else : ?>
				<input class="regular-text" id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" type="<?php echo esc_attr( in_array( $type, array( 'email', 'number' ), true ) ? $type : 'text' ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" <?php echo 'number' === $type ? 'min="0"' : ''; ?>>
			<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'kuka-island-core' ), 403 );
		}

		check_admin_referer( 'kuka_island_save_site_appearance' );
		$raw = isset( $_POST['site_content'] ) && is_array( $_POST['site_content'] )
			? wp_unslash( $_POST['site_content'] )
			: array();
		update_option( self::OPTION_NAME, self::sanitize( $raw ), false );
		self::sync_free_shipping_threshold();
		if ( self::$sanitize_notices ) {
			set_transient( 'kuka_island_site_notices_' . get_current_user_id(), self::$sanitize_notices, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=kuka-island-appearance' ) ) );
		exit;
	}

	/** @param array<string, mixed> $raw @return array<string, mixed> */
	private static function sanitize( array $raw ): array {
		$clean = array();
		self::$sanitize_notices = array();
		foreach ( self::fields() as $group_key => $group ) {
			foreach ( $group['fields'] as $field_key => $field ) {
				$value = $raw[ $group_key ][ $field_key ] ?? '';
				switch ( $field[1] ) {
					case 'checkbox':
						$value = '1' === (string) $value;
						break;
					case 'number':
						$value = max( 0, absint( $value ) );
						break;
					case 'media_image':
					case 'media_video':
						$value = absint( $value );
						break;
					case 'email':
						$value = sanitize_email( $value );
						break;
					case 'url':
						$value = self::sanitize_url( (string) $value );
						break;
					case 'textarea':
						$value = sanitize_textarea_field( $value );
						break;
					case 'lines':
						$value = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\R/', (string) $value ) ?: array() ) ) ), 0, 3 );
						break;
					case 'url_lines':
						$value = array_slice( array_map( array( self::class, 'sanitize_url' ), preg_split( '/\R/', (string) $value ) ?: array() ), 0, 3 );
						break;
					case 'link_lines':
						$lines = array();
						foreach ( preg_split( '/\R/', (string) $value ) ?: array() as $line_number => $line ) {
							if ( '' === trim( $line ) ) { continue; }
							$parts = array_map( 'trim', explode( '|', $line, 2 ) );
							$url = 2 === count( $parts ) ? self::sanitize_url( $parts[1] ) : '';
							if ( 2 === count( $parts ) && $parts[0] && $url ) {
								$lines[] = sanitize_text_field( $parts[0] ) . '|' . $url;
							} else {
								self::$sanitize_notices[] = sprintf( __( '%1$s / %2$s: %3$d. satır (%4$s)', 'kuka-island-core' ), $group['label'], $field[0], $line_number + 1, sanitize_text_field( $line ) );
							}
						}
						$value = implode( "\n", $lines );
						break;
					case 'category_navigation':
						$lines = array();
						foreach ( is_array( $value ) ? $value : array() as $row_number => $row ) {
							$label = sanitize_text_field( $row['label'] ?? '' );
							$url = self::sanitize_url( (string) ( $row['url'] ?? '' ) );
							if ( ! $label || ! $url ) {
								self::$sanitize_notices[] = sprintf( __( 'Kategori görünürlüğü: %d. satır kaydedilmedi.', 'kuka-island-core' ), $row_number + 1 );
								continue;
							}
							$lines[] = implode( '|', array( $label, $url, '1' === (string) ( $row['header'] ?? '0' ) ? '1' : '0', '1' === (string) ( $row['home'] ?? '0' ) ? '1' : '0' ) );
						}
						$value = implode( "\n", $lines );
						break;
					case 'ids':
						$value = implode( ',', array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', (string) $value ) ?: array() ) ) );
						break;
					case 'slug':
						$value = sanitize_title( $value );
						break;
					case 'product_source':
						$value = in_array( $value, array( 'latest', 'featured', 'sale', 'manual' ), true ) ? $value : 'latest';
						break;
					case 'presentation':
						$value = in_array( $value, array( 'grid', 'carousel' ), true ) ? $value : 'grid';
						break;
					case 'alignment':
						$value = in_array( $value, array( 'left', 'center', 'right' ), true ) ? $value : 'left';
						break;
					case 'tone':
						$value = in_array( $value, array( 'light', 'dark' ), true ) ? $value : 'light';
						break;
					default:
						$value = sanitize_text_field( $value );
				}
				$clean[ $group_key ][ $field_key ] = $value;
			}
		}
		return $clean;
	}

	private static function sanitize_url( string $value ): string {
		$value = trim( $value );
		if ( str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' ) ) {
			return '/' . ltrim( sanitize_text_field( $value ), '/' );
		}
		return preg_match( '#^https?://#i', $value ) ? esc_url_raw( $value, array( 'http', 'https' ) ) : '';
	}

	/** @return array<int, array{label:string,url:string,header:bool,home:bool}> */
	public static function parse_category_navigation( string $value ): array {
		$items = array();
		foreach ( preg_split( '/\R/', $value ) ?: array() as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 4 ) );
			if ( 4 !== count( $parts ) || ! $parts[0] || ! $parts[1] ) { continue; }
			$items[] = array( 'label' => $parts[0], 'url' => $parts[1], 'header' => '1' === $parts[2], 'home' => '1' === $parts[3] );
		}
		return $items;
	}

	/** @param array<string, mixed> $defaults @param array<string, mixed> $saved @return array<string, mixed> */
	private static function merge( array $defaults, array $saved ): array {
		foreach ( $defaults as $key => $value ) {
			if ( is_array( $value ) ) {
				$saved[ $key ] = self::merge( $value, isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array() );
			} elseif ( ! array_key_exists( $key, $saved ) || null === $saved[ $key ] ) {
				$saved[ $key ] = $value;
			}
		}
		return $saved;
	}
}
