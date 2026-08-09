<?php
/**
 * Site Appearance data contract and administration screen.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Site_Appearance {
	public const OPTION_NAME = 'kuka_island_site_content';
	/** Bumped whenever a stored field is retired, renamed or force-reset. */
	private const SCHEMA_VERSION = 3;
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
		$commercial = self::get()['commercial'];
		$threshold  = (float) ( $commercial['free_shipping_threshold'] ?? 0 );
		$flat_rate  = (float) ( $commercial['flat_shipping_fee'] ?? 0 );

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
			$settings['ignore_discounts'] = 'yes' === ( $commercial['ignore_discounts'] ?? 'no' ) ? 'yes' : 'no';
			update_option( $option_key, $settings, false );
		}

		$flat_rate_keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'woocommerce_flat_rate_' ) . '%_settings'
			)
		);
		foreach ( $flat_rate_keys as $option_key ) {
			$settings         = get_option( $option_key, array() );
			$settings         = is_array( $settings ) ? $settings : array();
			$settings['cost'] = (string) $flat_rate;
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
				'logo_id' => 0, 'mobile_logo_id' => 0, 'emblem_id' => 0, 'favicon_id' => 0, 'social_share_image_id' => 0,
				'email' => 'Gultekinkubraa@gmail.com', 'phone' => '+90 530 948 19 96', 'whatsapp_phone' => '0530 948 19 96',
				'social_links' => 'Instagram|https://www.instagram.com/kukaisland',
			),
			'announcement' => array(
				'enabled' => true,
				'items' => array( '4.000 TL üzeri siparişlerde ücretsiz kargo' ),
				'link_labels' => array( '' ), 'link_urls' => array( '' ),
			),
			'languages' => array(
				'items' => "Türkçe|/\nEnglish|/en/",
				'pending_urls' => '',
				'pending_note' => '',
			),
			'hero' => array(
				'enabled' => true, 'desktop_image_id' => 0, 'mobile_image_id' => 0, 'eyebrow' => 'KUKA ISLAND / YENİ SEZON',
				'title' => 'Adanın ritmini yanında taşı.', 'copy' => 'Gün boyu hareket eden, sade ve güçlü parçalar.',
				'button_label' => 'Yeni gelenleri keşfet', 'button_url' => '/magaza/', 'alignment' => 'left', 'text_tone' => 'dark', 'overlay_strength' => 78,
			),
			'home' => array(
				'category_index_enabled' => false, 'category_index_label' => 'Formunu bul', 'category_index_title' => 'Ürün kategorileri',
				'new_arrivals_enabled' => true, 'new_arrivals_title' => 'Yeni Gelenler', 'new_arrivals_copy' => 'Yeni sezon seçkisi.',
				'new_arrivals_source' => 'latest', 'source_category' => '', 'source_collection' => '', 'manual_product_ids' => '', 'presentation' => 'grid',
				'card_swatches_enabled' => true, 'card_stock_enabled' => true,
				'editorial_enabled' => true, 'editorial_title' => 'Ada Günlüğü', 'editorial_copy' => 'Şehirden kıyıya uzanan günlük üniforma.',
				'editorial_image_id' => 0, 'editorial_video_id' => 0, 'editorial_url' => '/hakkimizda/', 'editorial_link_label' => 'Hikâyeyi oku',
				'manifesto_enabled' => true,
				'manifesto_line_1' => 'Güneş. Ten. Özgürlük.', 'manifesto_line_1_en' => 'Sun. Skin. Freedom.',
				'manifesto_line_2' => 'Bir yer değil. Bir his.', 'manifesto_line_2_en' => 'Not a place. A feeling.',
				'services_enabled' => true,
				'service_1_title' => 'Güvenli ödeme', 'service_1_copy' => 'iyzico altyapısı · 3D Secure', 'service_1_url' => '/mesafeli-satis-sozlesmesi/',
				'service_2_title' => 'Kolay iade', 'service_2_copy' => '14 gün içinde cayma hakkı', 'service_2_url' => '/iade-degisim/',
				'service_3_title' => 'Destek', 'service_3_copy' => 'Hafta içi 09.00–18.00 · WhatsApp', 'service_3_url' => '',
			),
			'navigation' => array(
				'main' => "Yeni Gelenler|/magaza/?orderby=date\nHikâyemiz|/hakkimizda/",
				'categories' => "Bikini|/kategori/bikini-ustleri/|1|1\nMayo|/kategori/mayolar/|1|1\nPlaj Giyim|/kategori/plaj-giyim/|1|1\nKoleksiyonlar|/magaza/|1|0",
				'help' => "Beden Rehberi|/beden-rehberi/\nKargo ve Teslimat|/kargo-teslimat/\nİade|/iade-degisim/\nSık Sorulan Sorular|/sik-sorulan-sorular/\nİletişim|/iletisim/\nSipariş Takibi|/siparis-takibi/",
			),
			'footer' => array(
				'newsletter_enabled' => true,
				'newsletter_eyebrow' => 'Ada mektupları', 'newsletter_title' => 'Ada mektuplarına katıl',
				'newsletter_copy' => 'Yeni koleksiyonlar ve stüdyo notları için e-posta listemize katıl.',
				'newsletter_consent' => 'Gizlilik politikasını okudum ve iletişim izni veriyorum.',
				'newsletter_notification_email' => '',
				'help_links' => "Beden Rehberi|/beden-rehberi/\nKargo ve Teslimat|/kargo-teslimat/\nİade|/iade-degisim/\nSık Sorulan Sorular|/sik-sorulan-sorular/\nİletişim|/iletisim/\nSipariş Takibi|/siparis-takibi/",
				// Üyelik sözleşmesi (/kullanim-kosullari/) üyelik sunulmadığı için
				// listede yoktur; hukuk danışmanı kararı gelince eklenecek.
				'legal_links' => "Mesafeli Satış Sözleşmesi|/mesafeli-satis-sozlesmesi/\nÖn Bilgilendirme Formu|/on-bilgilendirme-formu/\nCayma Hakkı ve İade|/iade-degisim/\nKVKK Aydınlatma Metni|/kvkk-aydinlatma-metni/\nGizlilik Politikası|/gizlilik-politikasi/\nÇerez Politikası|/cerez-politikasi/\nAçık Rıza Metni|/acik-riza-metni/",
			),
			'commercial' => array(
				'free_shipping_threshold' => 4000, 'ignore_discounts' => 'no', 'shipping_copy' => '4.000 TL üzeri siparişlerde ücretsiz kargo.',
				'free_shipping_remaining_copy' => 'Ücretsiz kargo için %s daha ekleyin.', 'free_shipping_ready_copy' => 'Ücretsiz kargo hakkınız hazır.',
				'flat_shipping_fee' => 149, 'flat_rate_copy' => 'Standart gönderim bedeli ödeme adımında hesaplanır.',
				'shipping_carrier' => '[KARGO FİRMASI]', 'delivery_time' => '[TESLİMAT SÜRESİ]', 'cayma_hakki_gun' => 14,
				'return_shipping_responsibility' => '[İADE KARGO ÜCRETİNİN KİME AİT OLDUĞU]',
				'hygiene_copy' => 'Hijyen koruma bandı çıkarılmış, kullanılmış, yıkanmış, parfüm, krem veya deodorant kokusu bulunan, lekelenmiş ya da yeniden satılabilir niteliğini kaybetmiş bikini ve mayo ürünlerinde iade ve değişim kabul edilmez.',
				'hygiene_defect_copy' => 'Ayıplı ürünlerde tüketicinin kanuni hakları saklıdır.',
				'hygiene_try_on_copy' => 'Ürünü hijyen bandını sökmeden, iç çamaşırınızın üzerinden deneyebilirsiniz.',
				'secure_payment_copy' => 'Ödeme bilgileriniz güvenli bağlantı üzerinden işlenir.', 'support_hours' => 'Hafta içi 09.00–18.00',
			),
			'legal' => array(
				'company_title' => 'Kübra Gültekin', 'brand_name' => 'KUKA ISLAND',
				'tax_number' => '4220658128', 'tax_office' => 'Beşiktaş',
				'address_full' => 'Akat Mah. Ata Sk. Eti Sitesi A3 Blok No: 2 C İç Kapı No: 3, Beşiktaş / İstanbul',
				'address_short' => 'Akat Mh. Etiler',
				'telephone' => '0530 948 19 96', 'mersis_number' => 'Bulunmamaktadır', 'etbis_number' => '[ETBİS NO]',
			),
			'checkout' => array(
				'require_phone' => true, 'require_company' => false,
				'require_address_2' => false, 'require_city' => false,
			),
			'content' => array(
				'size_top_rows' => "S|84–88|72–76|A–B\nM|88–92|76–80|B–C\nL|92–98|80–84|C–D",
				'size_bottom_rows' => "S|66–70|92–96\nM|70–74|96–100\nL|74–80|100–106",
				'size_swimsuit_rows' => "S|84–88|66–70|92–96\nM|88–92|70–74|96–100\nL|92–98|74–80|100–106",
			),
			'membership' => array(
				'enabled' => false, 'guest_session_hours' => 48,
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
		$content = self::merge( self::defaults(), self::migrate( is_array( $saved ) ? $saved : array() ) );
		return class_exists( 'Kuka_Island_Core_Language' ) ? Kuka_Island_Core_Language::with_translation_defaults( $content ) : $content;
	}

	/**
	 * Carry a stored option forward to the current field contract.
	 *
	 * Retired fields would otherwise survive in the option row and keep feeding
	 * the storefront even though the panel no longer shows them. Values that
	 * simply moved keep the operator's own text; values the customer decided to
	 * drop are removed outright.
	 *
	 * @param array<string, mixed> $saved Stored option value.
	 * @return array<string, mixed>
	 */
	private static function migrate( array $saved ): array {
		if ( ! $saved || self::SCHEMA_VERSION === ( $saved['schema_version'] ?? 0 ) ) {
			return $saved;
		}

		// Adres tek alandı; yasal sayfalarda zorunlu açık adres ile pazarlama
		// yüzeylerindeki kısa adres artık ayrı tutuluyor.
		if ( isset( $saved['legal']['address'] ) && ! isset( $saved['legal']['address_full'] ) ) {
			$saved['legal']['address_full'] = $saved['legal']['address'];
		}
		// Tek "iade/değişim süresi" alanı yalnız cayma hakkına indi (§20, Bölüm E).
		if ( isset( $saved['commercial']['return_period_days'] ) && ! isset( $saved['commercial']['cayma_hakki_gun'] ) ) {
			$saved['commercial']['cayma_hakki_gun'] = $saved['commercial']['return_period_days'];
		}
		if ( isset( $saved['navigation']['main'] ) ) {
			$old_story_label             = sprintf( '%s / %s', 'Marka', 'Hikâyemiz' );
			$saved['navigation']['main'] = str_replace( $old_story_label, 'Hikâyemiz', (string) $saved['navigation']['main'] );
		}
		unset(
			$saved['legal']['address'],
			$saved['commercial']['return_period_days'],
			$saved['commercial']['exchange_copy'],
			$saved['footer']['brand_copy'],
			$saved['home']['manifesto_title'],
			$saved['home']['manifesto_copy'],
			$saved['panels']
		);
		// Kesim indeksi müşteri isteğiyle geri çekildi; eski kurulumlarda açık
		// kalmasın diye bir kez kapatılır, sonra panelden açılabilir.
		$saved['home']['category_index_enabled'] = false;
		$saved['schema_version']                 = self::SCHEMA_VERSION;
		update_option( self::OPTION_NAME, $saved, false );

		return $saved;
	}

	/** @return array<string, array<string, array<string, mixed>>> */
	private static function fields(): array {
		$groups = array(
			'brand'        => array(
				'label'  => __( '1. Marka', 'kuka-island-core' ),
				'fields' => array(
					'logo_id'               => array( __( 'Logo', 'kuka-island-core' ), 'media_image' ),
					'mobile_logo_id'        => array( __( 'Mobil logo', 'kuka-island-core' ), 'media_image' ),
					'emblem_id'             => array( __( 'Amblem (logoyla gösterilmez; boşsa palmiye SVG kullanılır)', 'kuka-island-core' ), 'media_image' ),
					'favicon_id'            => array( __( 'Favicon', 'kuka-island-core' ), 'media_image' ),
					'social_share_image_id' => array( __( 'Sosyal paylaşım görseli', 'kuka-island-core' ), 'media_image' ),
					'email'                 => array( __( 'E-posta', 'kuka-island-core' ), 'email' ),
					'phone'                 => array( __( 'Telefon', 'kuka-island-core' ), 'text' ),
					'whatsapp_phone'        => array( __( 'WhatsApp numarası (wa.me bağlantısı otomatik üretilir)', 'kuka-island-core' ), 'text' ),
					'social_links'          => array( __( 'Sosyal bağlantılar (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
				),
			),
			'announcement' => array(
				'label'  => __( '2. Duyuru Bandı', 'kuka-island-core' ),
				'fields' => array(
					'enabled' => array( __( 'Bandı göster', 'kuka-island-core' ), 'checkbox' ),
					'items'   => array( __( 'Duyuru (tek satır, ortalanır)', 'kuka-island-core' ), 'lines' ),
					'link_labels' => array( __( 'Duyuru bağlantı etiketleri (satır sırasıyla)', 'kuka-island-core' ), 'lines' ),
					'link_urls' => array( __( 'Duyuru bağlantı URL’leri (satır sırasıyla)', 'kuka-island-core' ), 'url_lines' ),
				),
			),
			'languages' => array(
				'label'  => __( 'Dil Seçici', 'kuka-island-core' ),
				'fields' => array(
					'items'        => array( __( 'Diller (Etiket|URL öneki) — tek satır seçici gizlenir', 'kuka-island-core' ), 'link_lines' ),
					'pending_urls' => array( __( 'Henüz yayında olmayan dil URL’leri (virgülle) — bağlantı yerine bilgi gösterilir', 'kuka-island-core' ), 'text' ),
					'pending_note' => array( __( 'Yayında olmayan dil için gösterilecek not', 'kuka-island-core' ), 'text' ),
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
					'overlay_strength' => array( __( 'Metin perdesi yoğunluğu (%)', 'kuka-island-core' ), 'percentage' ),
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
					'manifesto_line_1'    => array( __( 'Manifesto 1. satır (Türkçe)', 'kuka-island-core' ), 'text' ),
					'manifesto_line_1_en' => array( __( 'Manifesto 1. satır (İngilizce)', 'kuka-island-core' ), 'text' ),
					'manifesto_line_2'    => array( __( 'Manifesto 2. satır (Türkçe)', 'kuka-island-core' ), 'text' ),
					'manifesto_line_2_en' => array( __( 'Manifesto 2. satır (İngilizce)', 'kuka-island-core' ), 'text' ),
					'services_enabled'   => array( __( 'Servis şeridini göster', 'kuka-island-core' ), 'checkbox' ),
					'service_1_title'    => array( __( 'Servis 1 başlık', 'kuka-island-core' ), 'text' ),
					'service_1_copy'     => array( __( 'Servis 1 açıklama', 'kuka-island-core' ), 'text' ),
					'service_1_url'      => array( __( 'Servis 1 bağlantı', 'kuka-island-core' ), 'url' ),
					'service_2_title'    => array( __( 'Servis 2 başlık', 'kuka-island-core' ), 'text' ),
					'service_2_copy'     => array( __( 'Servis 2 açıklama', 'kuka-island-core' ), 'text' ),
					'service_2_url'      => array( __( 'Servis 2 bağlantı', 'kuka-island-core' ), 'url' ),
					'service_3_title'    => array( __( 'Servis 3 başlık', 'kuka-island-core' ), 'text' ),
					'service_3_copy'     => array( __( 'Servis 3 açıklama', 'kuka-island-core' ), 'text' ),
					'service_3_url'      => array( __( 'Servis 3 bağlantı (boşsa WhatsApp/iletişim)', 'kuka-island-core' ), 'url' ),
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
					'newsletter_enabled' => array( __( 'Bülteni göster', 'kuka-island-core' ), 'checkbox' ),
					'newsletter_eyebrow' => array( __( 'Bülten üst başlığı', 'kuka-island-core' ), 'text' ),
					'newsletter_title'   => array( __( 'Bülten başlığı', 'kuka-island-core' ), 'text' ),
					'newsletter_copy'    => array( __( 'Bülten metni', 'kuka-island-core' ), 'textarea' ),
					'newsletter_consent' => array( __( 'Bülten onay metni', 'kuka-island-core' ), 'textarea' ),
					'newsletter_notification_email' => array( __( 'Yeni kayıt bildirim e-postası (boş bırakılabilir)', 'kuka-island-core' ), 'email' ),
					'help_links'         => array( __( 'Yardım bağlantıları (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
					'legal_links'        => array( __( 'Yasal bağlantılar (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
				),
			),
			'commercial'   => array(
				'label'  => __( '7. Ticari Bilgiler', 'kuka-island-core' ),
				'fields' => array(
					'free_shipping_threshold' => array( __( 'Ücretsiz kargo eşiği (TL)', 'kuka-island-core' ), 'number' ),
					'ignore_discounts'        => array( __( 'Ücretsiz kargo eşiği kupon indiriminden önce mi hesaplansın?', 'kuka-island-core' ), 'shipping_discount_basis' ),
					'flat_shipping_fee'       => array( __( 'Standart kargo ücreti (TL)', 'kuka-island-core' ), 'number' ),
					'shipping_carrier'        => array( __( 'Kargo firması', 'kuka-island-core' ), 'text' ),
					'delivery_time'           => array( __( 'Tahmini teslimat süresi', 'kuka-island-core' ), 'text' ),
					'cayma_hakki_gun'         => array( __( 'Cayma hakkı süresi (gün) — 6502 sayılı Kanun on dört gün öngörür, düşürülmemelidir', 'kuka-island-core' ), 'number' ),
					'return_shipping_responsibility' => array( __( 'İade kargo ücreti sorumluluğu', 'kuka-island-core' ), 'text' ),
					'shipping_copy'             => array( __( 'Kargo metni', 'kuka-island-core' ), 'textarea' ),
					'free_shipping_remaining_copy' => array( __( 'Eşiğe kalan kargo metni (%s fiyat)', 'kuka-island-core' ), 'textarea' ),
					'free_shipping_ready_copy' => array( __( 'Eşik tamamlandı metni', 'kuka-island-core' ), 'textarea' ),
					'flat_rate_copy'            => array( __( 'Sabit kargo metni', 'kuka-island-core' ), 'textarea' ),
					'hygiene_copy'              => array( __( 'Hijyen ibaresi', 'kuka-island-core' ), 'textarea' ),
					'hygiene_defect_copy'       => array( __( 'Ayıplı ürün cümlesi', 'kuka-island-core' ), 'textarea' ),
					'hygiene_try_on_copy'       => array( __( 'Bandı sökmeden deneme bilgisi', 'kuka-island-core' ), 'textarea' ),
					'secure_payment_copy'       => array( __( 'Güvenli ödeme metni', 'kuka-island-core' ), 'textarea' ),
					'support_hours'             => array( __( 'Destek saatleri', 'kuka-island-core' ), 'text' ),
				),
			),
			'legal'        => array(
				'label'  => __( '8. Şirket ve Yasal Bilgiler', 'kuka-island-core' ),
				'fields' => array(
					'company_title' => array( __( 'Satıcı / unvan', 'kuka-island-core' ), 'text' ),
					'brand_name'    => array( __( 'İşletme adı', 'kuka-island-core' ), 'text' ),
					'tax_number'    => array( __( 'VKN', 'kuka-island-core' ), 'text' ),
					'tax_office'    => array( __( 'Vergi dairesi', 'kuka-island-core' ), 'text' ),
					'address_full'  => array( __( 'Açık adres (yasal sayfalarda zorunlu; sözleşmelerdekiyle aynı kalmalı)', 'kuka-island-core' ), 'textarea' ),
					'address_short' => array( __( 'Kısa adres (pazarlama yüzeyleri)', 'kuka-island-core' ), 'text' ),
					'telephone'     => array( __( 'Yasal iletişim telefonu', 'kuka-island-core' ), 'text' ),
					'mersis_number' => array( __( 'MERSİS numarası', 'kuka-island-core' ), 'text' ),
					'etbis_number'  => array( __( 'ETBİS numarası', 'kuka-island-core' ), 'text' ),
				),
			),
			'checkout'     => array(
				'label'  => __( '9. Ödeme Formu Alanları', 'kuka-island-core' ),
				'note'   => __( 'Ad, soyad, e-posta, adres, il ve posta kodu mesafeli satış mevzuatı gereği zorunludur; bu yüzden panelde açılıp kapatılamaz.', 'kuka-island-core' ),
				'fields' => array(
					'require_phone'     => array( __( 'Telefon zorunlu', 'kuka-island-core' ), 'checkbox' ),
					'require_company'   => array( __( 'Şirket adı zorunlu', 'kuka-island-core' ), 'checkbox' ),
					'require_address_2' => array( __( 'Adres satırı 2 zorunlu', 'kuka-island-core' ), 'checkbox' ),
					'require_city'      => array( __( 'İlçe zorunlu', 'kuka-island-core' ), 'checkbox' ),
				),
			),
			'content'      => array(
				'label'  => __( '10. Beden Rehberi Verileri', 'kuka-island-core' ),
				'fields' => array(
					'size_top_rows'      => array( __( 'Bikini üstü satırları (Beden|Göğüs|Göğüs altı|Kupa)', 'kuka-island-core' ), 'size_rows' ),
					'size_bottom_rows'   => array( __( 'Bikini altı satırları (Beden|Bel|Kalça)', 'kuka-island-core' ), 'size_rows' ),
					'size_swimsuit_rows' => array( __( 'Mayo satırları (Beden|Göğüs|Bel|Kalça)', 'kuka-island-core' ), 'size_rows' ),
				),
			),
			'membership'   => array(
				'label'  => __( '11. Üyelik', 'kuka-island-core' ),
				'note'   => __( 'Üyelik kapalıyken site hiçbir yerde hesap sormaz: misafir ödeme açık, kayıt ve giriş kapalıdır. Anahtar ileride açılırsa WooCommerce kayıt/giriş ayarları tek yerden yeniden etkinleşir; storefront hesap arayüzü ayrı bir yayın çalışması olarak eklenmelidir. Sipariş takibi sipariş numarası ve e-posta ile çalışmayı sürdürür.', 'kuka-island-core' ),
				'fields' => array(
					'enabled'             => array( __( 'Üyelik sistemini aç', 'kuka-island-core' ), 'checkbox' ),
					'guest_session_hours' => array( __( 'Misafir sepeti ömrü (saat)', 'kuka-island-core' ), 'number' ),
				),
			),
		);
		if ( ! class_exists( 'Kuka_Island_Core_Language' ) ) { return $groups; }
		foreach ( Kuka_Island_Core_Language::translation_fields() as $group_key => $translations ) {
			if ( ! isset( $groups[ $group_key ] ) ) { continue; }
			$rebuilt = array();
			$target_keys = array_column( $translations, 'key' );
			foreach ( $groups[ $group_key ]['fields'] as $field_key => $field ) {
				if ( in_array( $field_key, $target_keys, true ) ) { continue; }
				$rebuilt[ $field_key ] = $field;
				$config = $translations[ $field_key ] ?? null;
				if ( $config ) {
					$type = 'labels' === $config['mode'] ? 'lines' : $field[1];
					$rebuilt[ $config['key'] ] = array( sprintf( 'English — %s', wp_strip_all_tags( (string) $field[0] ) ), $type );
				}
			}
			$groups[ $group_key ]['fields'] = $rebuilt;
		}
		return $groups;
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
			<div class="notice notice-info inline"><p><strong>Türkçe + English:</strong> <?php esc_html_e( 'Çevrilebilir alanlar aynı satırda iki sütundur. English alanı boş bırakılırsa vitrinde Türkçe metin gösterilir; URL, sayı, medya ve şirket verisi tek kaynaktır.', 'kuka-island-core' ); ?></p></div>
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
						<p class="description"><strong>Türkçe</strong> kaynak · <strong>English</strong> çeviri · boş English alanı Türkçe fallback kullanır.</p>
						<?php if ( ! empty( $group['note'] ) ) : ?><p class="description"><?php echo esc_html( $group['note'] ); ?></p><?php endif; ?>
						<table class="form-table" role="presentation"><tbody>
						<?php foreach ( $group['fields'] as $field_key => $field ) : ?>
							<?php if ( str_ends_with( $field_key, '_en' ) || str_ends_with( $field_key, '_labels_en' ) ) { continue; } ?>
							<?php $translation = class_exists( 'Kuka_Island_Core_Language' ) ? Kuka_Island_Core_Language::translation_config( $group_key, $field_key ) : null; ?>
							<?php $this->render_field( $group_key, $field_key, $field, $content[ $group_key ][ $field_key ] ?? '', $translation, $content ); ?>
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
	private function render_field( string $group_key, string $field_key, array $field, mixed $value, ?array $translation = null, array $content = array() ): void {
		$name = sprintf( 'site_content[%s][%s]', $group_key, $field_key );
		$type = $field[1];
		if ( in_array( $type, array( 'lines', 'url_lines' ), true ) && is_array( $value ) ) {
			$value = implode( "\n", $value );
		}
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
			<td<?php echo $translation ? ' style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem"' : ''; ?>>
			<?php if ( $translation ) : ?><div><p><strong>Türkçe</strong></p><?php endif; ?>
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
			<?php elseif ( 'shipping_discount_basis' === $type ) : ?>
				<select id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<option value="no" <?php selected( 'no', $value ); ?>><?php esc_html_e( 'İndirimden sonraki tutar (varsayılan)', 'kuka-island-core' ); ?></option>
					<option value="yes" <?php selected( 'yes', $value ); ?>><?php esc_html_e( 'İndirimden önceki tutar', 'kuka-island-core' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Varsayılanda kupon indirimi eşiğe uygulanır. “İndirimden önce” seçilirse ücretsiz kargo uygunluğu kupon uygulanmadan önceki ara toplamla değerlendirilir.', 'kuka-island-core' ); ?></p>
			<?php elseif ( 'category_navigation' === $type ) : ?>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Kategori', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'URL', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'Üst menü', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'Ana sayfa indeksi', 'kuka-island-core' ); ?></th></tr></thead><tbody>
				<?php foreach ( self::parse_category_navigation( (string) $value ) as $index => $item ) : ?>
					<tr><td><input type="text" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $item['label'] ); ?>"></td><td><input type="text" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][url]" value="<?php echo esc_attr( $item['url'] ); ?>"></td><td><input type="hidden" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][header]" value="0"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][header]" value="1" <?php checked( $item['header'] ); ?>></td><td><input type="hidden" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][home]" value="0"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $index ); ?>][home]" value="1" <?php checked( $item['home'] ); ?>></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php elseif ( in_array( $type, array( 'textarea', 'lines', 'url_lines', 'link_lines', 'size_rows' ), true ) ) : ?>
				<textarea class="large-text" rows="<?php echo 'size_rows' === $type ? '7' : '4'; ?>" id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" name="<?php echo esc_attr( $name ); ?>"><?php echo esc_textarea( (string) $value ); ?></textarea>
			<?php else : ?>
				<input class="regular-text" id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" type="<?php echo esc_attr( in_array( $type, array( 'email', 'number', 'percentage' ), true ) ? ( 'email' === $type ? 'email' : 'number' ) : 'text' ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" <?php echo 'number' === $type ? 'min="0"' : ''; ?> <?php echo 'percentage' === $type ? 'min="0" max="100" step="1"' : ''; ?>>
			<?php endif; ?>
			<?php if ( $translation ) : ?></div><div><p><strong>English</strong></p><?php $translated_field = self::fields()[ $group_key ]['fields'][ $translation['key'] ]; $this->render_control( $group_key, $translation['key'], $translated_field, $content[ $group_key ][ $translation['key'] ] ?? '' ); ?></div><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/** Render a control without a table row, used by the English half of a pair. */
	private function render_control( string $group_key, string $field_key, array $field, mixed $value ): void {
		$name = sprintf( 'site_content[%s][%s]', $group_key, $field_key );
		$type = $field[1];
		if ( in_array( $type, array( 'textarea', 'lines', 'url_lines', 'link_lines', 'size_rows' ), true ) ) {
			?><textarea class="large-text" rows="4" id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" name="<?php echo esc_attr( $name ); ?>"><?php echo esc_textarea( is_array( $value ) ? implode( "\n", $value ) : (string) $value ); ?></textarea><?php
		} else {
			?><input class="regular-text" id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>"><?php
		}
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
					case 'percentage':
						$value = min( 100, max( 0, absint( $value ) ) );
						break;
					case 'shipping_discount_basis':
						$value = 'yes' === (string) $value ? 'yes' : 'no';
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
					case 'size_rows':
						$rows = array();
						foreach ( preg_split( '/\R/', (string) $value ) ?: array() as $row ) {
							$cells = array_values( array_filter( array_map( 'sanitize_text_field', explode( '|', $row ) ), static fn( string $cell ): bool => '' !== $cell ) );
							if ( count( $cells ) >= 3 && count( $cells ) <= 5 ) { $rows[] = implode( '|', $cells ); }
						}
						$value = implode( "\n", array_slice( $rows, 0, 10 ) );
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
		// Kaydedilen değer güncel alan sözleşmesini taşır; aksi hâlde her
		// okumada geçiş yeniden çalışıp operatörün seçimini ezerdi.
		$clean['schema_version'] = self::SCHEMA_VERSION;
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
