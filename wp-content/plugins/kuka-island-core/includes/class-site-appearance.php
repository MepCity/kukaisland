<?php
/**
 * Site Appearance data contract and administration screen.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Site_Appearance {
	public const OPTION_NAME = 'kuka_island_site_content';
	/** Bumped whenever a stored field is retired, renamed or force-reset. */
	private const SCHEMA_VERSION = 14;
	private const CAPABILITY = 'manage_woocommerce';
	/** Operator-declared state of one legal identifier. Never inferred by code. */
	public const LEGAL_STATUS_PENDING = 'pending';
	public const LEGAL_STATUS_PRESENT = 'present';
	public const LEGAL_STATUS_NOT_APPLICABLE = 'not_applicable';
	/** @var array<int, string> */
	private static array $sanitize_notices = array();
	/** @var array<string, mixed>|null Request-local merged content cache. */
	private static ?array $content_cache = null;

	/**
	 * Register the settings page and the explicit, nonce-protected save action.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_kuka_island_save_site_appearance', array( $this, 'save' ) );
		add_action( 'admin_post_kuka_island_save_iyzico_documents', array( $this, 'save_iyzico_documents' ) );
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
			$settings['requires']         = $threshold > 0 ? 'either' : '';
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
				'email_banner_id' => 0,
				'email' => 'info@kukaisland.com', 'phone' => '+90 530 948 19 96', 'whatsapp_phone' => '0530 948 19 96',
				'social_links' => 'Instagram|https://www.instagram.com/kukaisland',
			),
			'announcement' => array(
				'enabled' => true,
				'items' => array( '4.000 TL üzeri siparişlerde ücretsiz kargo' ),
				'link_labels' => array( '' ), 'link_urls' => array( '' ),
			),
			'languages' => array(
				'items' => "Türkçe|/\nEnglish|/en/",
			),
			'hero' => array(
				'enabled' => true, 'desktop_image_id' => 0, 'mobile_image_id' => 0, 'eyebrow' => 'KUKA ISLAND / YENİ SEZON',
				'title' => 'Kaçışınız için tasarlandı. Est. 2026', 'copy' => 'Gün boyu hareket eden, sade ve güçlü parçalar.',
				'button_label' => 'Yeni gelenleri keşfet', 'button_url' => '/magaza/', 'alignment' => 'left', 'text_tone' => 'dark',
			),
			'home' => array(
				'category_index_enabled' => false, 'category_index_label' => 'Formunu bul', 'category_index_title' => 'Ürün kategorileri',
				'new_arrivals_enabled' => true, 'new_arrivals_title' => 'Yeni Gelenler', 'new_arrivals_copy' => 'Yeni sezon seçkisi.',
				'new_arrivals_source' => 'latest', 'source_category' => '', 'source_collection' => '', 'manual_product_ids' => '', 'presentation' => 'grid',
				'card_swatches_enabled' => true, 'card_stock_enabled' => true,
				'editorial_enabled' => true, 'editorial_title' => 'Sonsuz yazlar için tasarlandı', 'editorial_copy' => 'Şehirden kıyıya uzanan günlük üniforma.',
				'editorial_image_id' => 0, 'editorial_video_id' => 0, 'editorial_url' => '/hakkimizda/', 'editorial_link_label' => 'Hikâyeyi oku',
				'manifesto_enabled' => true,
				'manifesto_line_1' => 'Güneş. Ten. Özgürlük.', 'manifesto_line_1_en' => 'Sun. Skin. Freedom.',
				'manifesto_line_2' => 'Bir yer değil. Bir his.', 'manifesto_line_2_en' => 'Not a place. A feeling.',
				'services_enabled' => true,
				'service_1_title' => 'Güvenli ödeme', 'service_1_copy' => 'iyzico altyapısı · 3D Secure', 'service_1_url' => '/mesafeli-satis-sozlesmesi/',
				'service_2_title' => 'Kolay iade', 'service_2_copy' => '14 gün içinde cayma hakkı', 'service_2_url' => '/iade-degisim/',
				'service_3_title' => 'Destek', 'service_3_copy' => 'Hafta içi 09.00–18.00 · WhatsApp', 'service_3_url' => '',
			),
			'story' => array(
				'scenes' => array(
					array( 'text' => "Bir yer değil. Bir his.", 'text_en' => "Not a place. A feeling.", 'desktop_image_id' => 0, 'desktop_image_id_en' => 0, 'mobile_image_id' => 0, 'mobile_image_id_en' => 0, 'text_tone' => 'dark', 'text_tone_en' => 'dark' ) + self::story_art_direction( 0 ),
					array( 'text' => "Hayatta bazen sıfırdan başlamak gerekir.\n\nBenim için KUKA ISLAND tam olarak böyle başladı.", 'text_en' => "Sometimes, life asks you to begin again.\n\nThat is exactly how KUKA ISLAND began for me.", 'desktop_image_id' => 0, 'desktop_image_id_en' => 0, 'mobile_image_id' => 0, 'mobile_image_id_en' => 0, 'text_tone' => 'dark', 'text_tone_en' => 'dark' ) + self::story_art_direction( 1 ),
					array( 'text' => 'Yeni bir sayfa açarken, sadece bir marka kurmak istemedim. Bana iyi hissettiren her şeyi tek bir çatı altında toplamak istedim.', 'text_en' => 'As I turned a new page, I did not want to create just another brand. I wanted to bring everything that makes me feel good together under one roof.', 'desktop_image_id' => 0, 'desktop_image_id_en' => 0, 'mobile_image_id' => 0, 'mobile_image_id_en' => 0, 'text_tone' => 'dark', 'text_tone_en' => 'dark' ) + self::story_art_direction( 2 ),
					array( 'text' => "Denizi…\nYazı…\nÖzgürlüğü…\nVe kadınların kendini en güzel hissettiği anları…", 'text_en' => "The sea…\nSummer…\nFreedom…\nAnd those moments when women feel most beautiful in their own skin…", 'desktop_image_id' => 0, 'desktop_image_id_en' => 0, 'mobile_image_id' => 0, 'mobile_image_id_en' => 0, 'text_tone' => 'dark', 'text_tone_en' => 'dark', 'reveal_lines' => true ) + self::story_art_direction( 3 ),
					array( 'text' => "İşte KUKA ISLAND böyle doğdu.\n\nHer koleksiyon, sadece bir sezon için değil; yıllar sonra bile giydiğinde sana aynı hissi yaşatsın diye hazırlanıyor.", 'text_en' => "That is how KUKA ISLAND came to life.\n\nEvery collection is made for more than a single season—to bring back that same feeling, even when you wear it years from now.", 'desktop_image_id' => 0, 'desktop_image_id_en' => 0, 'mobile_image_id' => 0, 'mobile_image_id_en' => 0, 'text_tone' => 'dark', 'text_tone_en' => 'dark' ) + self::story_art_direction( 4 ),
					array( 'text' => "Bu yolculuk daha yeni başlıyor.\n\nİyi ki buradasın.\n\nVe bu hikâyenin ilk sayfalarında bize eşlik ediyorsun.\n\nLove,\nKÜBRA", 'text_en' => "This journey is only just beginning.\n\nI am so glad you are here.\n\nAnd that you are with us for the first pages of this story.\n\nLove,\nKÜBRA", 'desktop_image_id' => 0, 'desktop_image_id_en' => 0, 'mobile_image_id' => 0, 'mobile_image_id_en' => 0, 'text_tone' => 'dark', 'text_tone_en' => 'dark' ) + self::story_art_direction( 5 ),
				),
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
				'telephone' => '0530 948 19 96', 'mersis_number' => '', 'kep_address' => '',
				'professional_chamber' => '', 'professional_rules_url' => '', 'etbis_number' => '',
				// Her yasal kimlik alanı kendi operatör beyanını taşır. Varsayılan
				// "bekliyor"dur; "uygulanamaz" hukuki bir karardır ve koddan
				// türetilmez (§20.1).
				'mersis_status' => self::LEGAL_STATUS_PENDING,
				'kep_status' => self::LEGAL_STATUS_PENDING,
				'professional_chamber_status' => self::LEGAL_STATUS_PENDING,
				'professional_rules_status' => self::LEGAL_STATUS_PENDING,
				'etbis_status' => self::LEGAL_STATUS_PENDING,
				'iyzico_tax_certificate' => false, 'iyzico_signature_circular' => false,
				'iyzico_identity_copy' => false, 'iyzico_iban_document' => false, 'iyzico_findeks_report' => false,
			),
			'checkout' => array(
				'require_phone' => true, 'require_company' => false,
				'require_address_2' => false,
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

	/** @return array<string, string> */
	private static function story_art_direction( int $index ): array {
		$directions = array(
			array( 'transition_type' => 'zoom-out', 'text_position' => 'left-bottom', 'gradient_intensity' => 'medium' ),
			array( 'transition_type' => 'crossfade-left', 'text_position' => 'left-center', 'gradient_intensity' => 'strong' ),
			array( 'transition_type' => 'fade-center', 'text_position' => 'center', 'gradient_intensity' => 'medium' ),
			array( 'transition_type' => 'line-sequence', 'text_position' => 'left-center', 'gradient_intensity' => 'strong' ),
			array( 'transition_type' => 'grow-right', 'text_position' => 'right-center', 'gradient_intensity' => 'strong' ),
			array( 'transition_type' => 'gather', 'text_position' => 'center', 'gradient_intensity' => 'medium' ),
		);
		return $directions[ $index ] ?? array( 'transition_type' => 'fade-center', 'text_position' => 'left-bottom', 'gradient_intensity' => 'medium' );
	}

	/**
	 * Return persisted content merged recursively over safe defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		if ( null !== self::$content_cache ) {
			return self::filtered( self::$content_cache );
		}
		$saved = get_option( self::OPTION_NAME, array() );
		$legacy_main = "Yeni Gelenler|/magaza/?orderby=date\nTüm Ürünler|/magaza/\nHakkımızda|/hakkimizda/";
		if ( is_array( $saved ) && $legacy_main === ( $saved['navigation']['main'] ?? '' ) ) {
			$saved['navigation']['main'] = self::defaults()['navigation']['main'];
		}
		$saved   = self::migrate( is_array( $saved ) ? $saved : array() );
		$content = self::merge( self::defaults(), $saved );
		// Repeater rows are an ordered collection: deleting a scene must not merge
		// the numeric default rows back into the customer's saved sequence.
		if ( isset( $saved['story']['scenes'] ) && is_array( $saved['story']['scenes'] ) ) {
			$content['story']['scenes'] = $saved['story']['scenes'];
		}
		self::$content_cache = class_exists( 'Kuka_Island_Core_Language' ) ? Kuka_Island_Core_Language::with_translation_defaults( $content ) : $content;
		return self::filtered( self::$content_cache );
	}

	/**
	 * Panel içeriğinin okunan hâli.
	 *
	 * Açık bir uzatma noktası: içerik süreç içinde önbelleklendiği için bir
	 * ölçüm ya da eklenti tek bir alanı, operatörün option satırına YAZMADAN
	 * değiştirebilir. Üretimde bu filtreye bağlı hiçbir şey yoktur.
	 *
	 * @param array<string, mixed> $content Okunan içerik.
	 * @return array<string, mixed>
	 */
	private static function filtered( array $content ): array {
		/**
		 * Site Görünümü panelinin okunan içeriği.
		 *
		 * @param array<string, mixed> $content Panel içeriği.
		 */
		$filtered = apply_filters( 'kuka_island_site_content', $content );

		return is_array( $filtered ) ? $filtered : $content;
	}

	/** Clear the request-local cache after this class changes the option. */
	private static function clear_content_cache(): void {
		self::$content_cache = null;
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
		if ( isset( $saved['story']['scenes'] ) && is_array( $saved['story']['scenes'] ) ) {
			foreach ( $saved['story']['scenes'] as $index => &$scene ) {
				if ( ! is_array( $scene ) ) { continue; }
				$scene += self::story_art_direction( (int) $index );
				if ( in_array( (int) $index, array( 0, 1, 2, 3 ), true ) ) {
					$scene['text_tone']    = 'dark';
					$scene['text_tone_en'] = 'dark';
				}
			}
			unset( $scene );
		}
		if ( 'Bulunmamaktadır' === ( $saved['legal']['mersis_number'] ?? '' ) ) {
			$saved['legal']['mersis_number'] = '';
		}
		if ( str_contains( (string) ( $saved['legal']['etbis_number'] ?? '' ), '[' ) ) {
			$saved['legal']['etbis_number'] = '';
		}
		// Yasal kimlik alanları tek zorunlu kriter olmaktan çıkıp alan başına
		// üç durumlu beyana geçti. Operatörün girdiği değer korunur: doğrulanmış
		// bir değer "mevcut" sayılır, geri kalan her şey "bekliyor" olur.
		// Hiçbir alan otomatik "uygulanamaz" yapılmaz; bu hukuki bir karardır.
		foreach ( self::legal_status_map() as $status_key => $value_key ) {
			if ( isset( $saved['legal'][ $status_key ] ) ) {
				continue;
			}
			$saved['legal'][ $status_key ] = self::legal_value_verified( $value_key, (string) ( $saved['legal'][ $value_key ] ?? '' ) )
				? self::LEGAL_STATUS_PRESENT
				: self::LEGAL_STATUS_PENDING;
		}
		unset(
				$saved['languages']['items_en'],
				$saved['languages']['pending_urls'],
				$saved['languages']['pending_note'],
			$saved['brand']['social_links_labels_en'],
			$saved['legal']['address'],
			$saved['commercial']['return_period_days'],
			$saved['commercial']['exchange_copy'],
			$saved['hero']['overlay_strength'],
			$saved['footer']['payment_label'],
			$saved['footer']['payment_label_en'],
			$saved['footer']['payment_logos_enabled'],
			$saved['footer']['brand_copy'],
			$saved['checkout']['require_city'],
			$saved['home']['manifesto_title'],
			$saved['home']['manifesto_copy'],
			$saved['panels']
		);
		// Dil adları teknik yönlendirme sözleşmesidir; iki vitrinde de her dil
		// kendi adıyla görünür. Eski çeviri turunda kaydedilmiş etiketleri taşıma.
		$saved['languages']['items'] = self::defaults()['languages']['items'];
		// Faz 6B kapanış geri bildirimi: ana sayfanın iki başlığı marka diliyle
		// birlikte yenilendi; eski kayıtlar yeni metni gölgelememeli.
		$saved['hero']['title']               = self::defaults()['hero']['title'];
		$saved['hero']['title_en']            = 'Designed for your escape. Est. 2026';
		$saved['home']['editorial_title']     = self::defaults()['home']['editorial_title'];
		$saved['home']['editorial_title_en']  = 'Designed for endless summers';
		// Kesim indeksi müşteri isteğiyle geri çekildi; eski kurulumlarda açık
		// kalmasın diye bir kez kapatılır, sonra panelden açılabilir.
		$saved['home']['category_index_enabled'] = false;
		$saved['schema_version']                 = self::SCHEMA_VERSION;
		update_option( self::OPTION_NAME, $saved, true );
		self::clear_content_cache();

		return $saved;
	}

	/** @return array<string, array<string, array<string, mixed>>> */
	private static function fields(): array {
		$groups = array(
			'brand'        => array(
				'label'  => __( '1. Marka', 'kuka-island-core' ),
				'note'   => __( 'Header, footer, iletişim bağlantıları, tarayıcı ikonu ve sosyal paylaşım kartını yönetir.', 'kuka-island-core' ),
				'fields' => array(
					'logo_id'               => array( __( 'Logo', 'kuka-island-core' ), 'media_image' ),
					'mobile_logo_id'        => array( __( 'Mobil logo', 'kuka-island-core' ), 'media_image' ),
					'emblem_id'             => array( __( 'Amblem (logoyla gösterilmez; boşsa palmiye SVG kullanılır)', 'kuka-island-core' ), 'media_image' ),
					'favicon_id'            => array( __( 'Favicon', 'kuka-island-core' ), 'media_image' ),
					'social_share_image_id' => array( __( 'Sosyal paylaşım görseli', 'kuka-island-core' ), 'media_image' ),
					'email_banner_id'       => array( __( 'E-posta kapak/banner görseli (isteğe bağlı; boşsa e-postada banner çıkmaz)', 'kuka-island-core' ), 'media_image' ),
					'email'                 => array( __( 'E-posta', 'kuka-island-core' ), 'email' ),
					'phone'                 => array( __( 'Telefon', 'kuka-island-core' ), 'text' ),
					'whatsapp_phone'        => array( __( 'WhatsApp numarası (wa.me bağlantısı otomatik üretilir)', 'kuka-island-core' ), 'text' ),
					'social_links'          => array( __( 'Sosyal bağlantılar (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
				),
			),
			'announcement' => array(
				'label'  => __( '2. Duyuru Bandı', 'kuka-island-core' ),
				'note'   => __( 'Sitenin en üstündeki duyuru satırını ve varsa bağlantısını yönetir.', 'kuka-island-core' ),
				'fields' => array(
					'enabled' => array( __( 'Bandı göster', 'kuka-island-core' ), 'checkbox' ),
					'items'   => array( __( 'Duyuru (tek satır, ortalanır)', 'kuka-island-core' ), 'lines' ),
					'link_labels' => array( __( 'Duyuru bağlantı etiketleri (satır sırasıyla)', 'kuka-island-core' ), 'lines' ),
					'link_urls' => array( __( 'Duyuru bağlantı URL’leri (satır sırasıyla)', 'kuka-island-core' ), 'url_lines' ),
				),
			),
			'languages' => array(
				'label'  => __( '3. Dil Seçici', 'kuka-island-core' ),
				'note'   => __( 'Header’daki dil seçeneklerini yönetir. Dil adları çevrilmez; her dil kendi dilinde yazılır.', 'kuka-island-core' ),
				'fields' => array(
					'items'        => array( __( 'Diller (Etiket|URL öneki) — tek satır seçici gizlenir', 'kuka-island-core' ), 'link_lines' ),
				),
			),
			'hero'         => array(
				'label'  => __( '4. Ana Hero', 'kuka-island-core' ),
				'note'   => __( 'Ana sayfanın ilk ekranındaki görseli, başlığı, açıklamayı ve düğmeyi yönetir.', 'kuka-island-core' ),
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
					'text_tone'        => array( __( 'Metin tonu', 'kuka-island-core' ), 'tone' ),
				),
			),
			'home'         => array(
				'label'  => __( '5. Ana Sayfa Bölümleri', 'kuka-island-core' ),
				'note'   => __( 'Hero altındaki ürün, editoryal, manifesto ve hizmet bölümlerini yönetir.', 'kuka-island-core' ),
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
			'story'        => array(
				'label'  => __( '6. Marka Hikâyesi', 'kuka-island-core' ),
				'note'   => __( 'Hakkımızda sayfasındaki sahneleri yönetir. Sahneleri ekleyip çıkarabilir ve sıralayabilirsiniz; paragraf ve satır sonlarını koruyun.', 'kuka-island-core' ),
				'fields' => array(
					'scenes' => array( __( 'Hikâye sahneleri', 'kuka-island-core' ), 'story_scenes' ),
				),
			),
			'navigation'   => array(
				'label'  => __( '7. Navigasyon', 'kuka-island-core' ),
				'note'   => __( 'Header, kategori indeksi ve yardım menüsündeki bağlantıları yönetir.', 'kuka-island-core' ),
				'fields' => array(
					'main' => array( __( 'Sabit üst menü bağlantıları (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
					'categories' => array( __( 'Kategori görünürlüğü', 'kuka-island-core' ), 'category_navigation' ),
					'help' => array( __( 'Yardım menüsü (Etiket|URL)', 'kuka-island-core' ), 'link_lines' ),
				),
			),
			'footer'       => array(
				'label'  => __( '8. Footer', 'kuka-island-core' ),
				'note'   => __( 'Sayfa altındaki bülten alanını, yardım ve yasal bağlantıları yönetir.', 'kuka-island-core' ),
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
				'label'  => __( '9. Ticari Bilgiler', 'kuka-island-core' ),
				'note'   => __( 'Sepet, ödeme, kargo ve iade yüzeylerinde ortak kullanılan ticari değerleri yönetir.', 'kuka-island-core' ),
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
				'label'  => __( '10. Şirket ve Yasal Bilgiler', 'kuka-island-core' ),
				'note'   => __( 'Footer, iletişim ve yasal sayfalardaki tek kaynak şirket bilgilerini yönetir. MERSİS, KEP, meslek odası, davranış kuralları ve ETBİS satırlarının her biri kendi durumunu taşır: “Bekliyor” lansman eksikliği sayılır, “Mevcut” yalnız doğrulanmış değerle yayımlanır, “Uygulanamaz” ne yayımlanır ne eksik sayılır. “Uygulanamaz” hukuki bir beyandır; mali müşavir veya hukuk danışmanı teyidi olmadan seçmeyin.', 'kuka-island-core' ),
				'fields' => array(
					'company_title' => array( __( 'Satıcı / unvan', 'kuka-island-core' ), 'text' ),
					'brand_name'    => array( __( 'İşletme adı', 'kuka-island-core' ), 'text' ),
					'tax_number'    => array( __( 'VKN', 'kuka-island-core' ), 'text' ),
					'tax_office'    => array( __( 'Vergi dairesi', 'kuka-island-core' ), 'text' ),
					'address_full'  => array( __( 'Açık adres (yasal sayfalarda zorunlu; sözleşmelerdekiyle aynı kalmalı)', 'kuka-island-core' ), 'textarea' ),
					'address_short' => array( __( 'Kısa adres (pazarlama yüzeyleri)', 'kuka-island-core' ), 'text' ),
					'telephone'     => array( __( 'Yasal iletişim telefonu', 'kuka-island-core' ), 'text' ),
					'mersis_number' => array( __( 'MERSİS numarası', 'kuka-island-core' ), 'text' ),
					'mersis_status' => array( __( 'MERSİS durumu', 'kuka-island-core' ), 'legal_status' ),
					'kep_address'   => array( __( 'KEP adresi', 'kuka-island-core' ), 'email' ),
					'kep_status'    => array( __( 'KEP durumu', 'kuka-island-core' ), 'legal_status' ),
					'professional_chamber' => array( __( 'Meslek odası', 'kuka-island-core' ), 'text' ),
					'professional_chamber_status' => array( __( 'Meslek odası durumu', 'kuka-island-core' ), 'legal_status' ),
					'professional_rules_url' => array( __( 'Davranış kuralları bağlantısı', 'kuka-island-core' ), 'url' ),
					'professional_rules_status' => array( __( 'Davranış kuralları durumu', 'kuka-island-core' ), 'legal_status' ),
					'etbis_number'  => array( __( 'ETBİS numarası', 'kuka-island-core' ), 'text' ),
					'etbis_status'  => array( __( 'ETBİS durumu', 'kuka-island-core' ), 'legal_status' ),
					'iyzico_tax_certificate' => array( __( 'iyzico belgesi: Vergi levhası hazır', 'kuka-island-core' ), 'checkbox' ),
					'iyzico_signature_circular' => array( __( 'iyzico belgesi: İmza sirküleri hazır', 'kuka-island-core' ), 'checkbox' ),
					'iyzico_identity_copy' => array( __( 'iyzico belgesi: Kimlik fotokopisi hazır', 'kuka-island-core' ), 'checkbox' ),
					'iyzico_iban_document' => array( __( 'iyzico belgesi: Banka IBAN doğrulama belgesi hazır', 'kuka-island-core' ), 'checkbox' ),
					'iyzico_findeks_report' => array( __( 'iyzico belgesi: Findeks Risk Raporu hazır', 'kuka-island-core' ), 'checkbox' ),
				),
			),
			'checkout'     => array(
				'label'  => __( '11. Ödeme Formu Alanları', 'kuka-island-core' ),
				'note'   => __( 'Ödeme formundaki isteğe bağlı alanların zorunluluğunu yönetir. Ad, soyad, e-posta, adres, il ve posta kodu kilitlidir.', 'kuka-island-core' ),
				'fields' => array(
					'require_phone'     => array( __( 'Telefon zorunlu', 'kuka-island-core' ), 'checkbox' ),
					'require_company'   => array( __( 'Şirket adı zorunlu', 'kuka-island-core' ), 'checkbox' ),
					'require_address_2' => array( __( 'Adres satırı 2 zorunlu', 'kuka-island-core' ), 'checkbox' ),
				),
			),
			'content'      => array(
				'label'  => __( '12. Beden Rehberi Verileri', 'kuka-island-core' ),
				'note'   => __( 'Beden Rehberi sayfasındaki ölçü tablolarını yönetir.', 'kuka-island-core' ),
				'fields' => array(
					'size_top_rows'      => array( __( 'Bikini üstü satırları (Beden|Göğüs|Göğüs altı|Kupa)', 'kuka-island-core' ), 'size_rows' ),
					'size_bottom_rows'   => array( __( 'Bikini altı satırları (Beden|Bel|Kalça)', 'kuka-island-core' ), 'size_rows' ),
					'size_swimsuit_rows' => array( __( 'Mayo satırları (Beden|Göğüs|Bel|Kalça)', 'kuka-island-core' ), 'size_rows' ),
				),
			),
			'membership'   => array(
				'label'  => __( '13. Üyelik', 'kuka-island-core' ),
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
					$rebuilt[ $config['key'] ] = array( sprintf( '%s (EN)', wp_strip_all_tags( (string) $field[0] ) ), $type );
				}
			}
			$groups[ $group_key ]['fields'] = $rebuilt;
		}
		return $groups;
	}

	/** Read-only field contract used by acceptance measurements and documentation. */
	public static function field_inventory(): array {
		return self::fields();
	}

	public function add_menu(): void {
		$menu_icon = get_stylesheet_directory_uri() . '/assets/img/palmiye.svg';
		add_menu_page(
			__( 'Kuka Island', 'kuka-island-core' ),
			__( 'Kuka Island', 'kuka-island-core' ),
			self::CAPABILITY,
			'kuka-island',
			array( $this, 'render_start_page' ),
			$menu_icon,
			58
		);
		add_submenu_page( 'kuka-island', __( 'Başlangıç', 'kuka-island-core' ), __( 'Başlangıç', 'kuka-island-core' ), self::CAPABILITY, 'kuka-island', array( $this, 'render_start_page' ) );
		add_submenu_page( 'kuka-island', __( 'Site Görünümü', 'kuka-island-core' ), __( 'Site Görünümü', 'kuka-island-core' ), self::CAPABILITY, 'kuka-island-appearance', array( $this, 'render_page' ) );
	}

	/** Load the native media selector plus the small operator helpers. */
	public function enqueue_admin_assets( string $hook ): void {
		wp_enqueue_style( 'kuka-island-admin-menu', plugins_url( 'assets/admin-menu.css', KUKA_ISLAND_CORE_FILE ), array(), '0.2.1' );
		$kuka_screen = str_contains( $hook, 'kuka-island' ) || in_array( $hook, array( 'post.php', 'post-new.php', 'edit-tags.php', 'term.php' ), true );
		if ( ! $kuka_screen ) { return; }
		wp_enqueue_style( 'kuka-island-admin', plugins_url( 'assets/admin.css', KUKA_ISLAND_CORE_FILE ), array(), '0.2.0' );
		if ( in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			wp_enqueue_script( 'kuka-island-admin', plugins_url( 'assets/admin.js', KUKA_ISLAND_CORE_FILE ), array(), '0.2.0', true );
		}
		if ( 'kuka-island_page_kuka-island-appearance' !== $hook ) { return; }
		wp_enqueue_media();
		wp_enqueue_script(
			'kuka-island-site-appearance',
			plugins_url( 'assets/site-appearance.js', KUKA_ISLAND_CORE_FILE ),
			array( 'jquery' ),
			'0.2.0',
			true
		);
	}

	/** Daily operator map with direct links to the relevant native screens. */
	public function render_start_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'kuka-island-core' ) );
		}
		$content = self::get();
		$products = wp_count_posts( 'product' );
		$routes = array(
			array( __( 'Ürünler', 'kuka-island-core' ), __( 'Ürün, fiyat, stok ve fotoğrafları yönetin.', 'kuka-island-core' ), admin_url( 'edit.php?post_type=product' ) ),
			array( __( 'Siparişler', 'kuka-island-core' ), __( 'Yeni siparişleri ve durumlarını izleyin.', 'kuka-island-core' ), admin_url( 'admin.php?page=wc-orders' ) ),
			array( __( 'Site Görünümü', 'kuka-island-core' ), __( 'Hero, duyuru ve ortak site metinlerini yönetin.', 'kuka-island-core' ), admin_url( 'admin.php?page=kuka-island-appearance' ) ),
			array( __( 'Yönetim Haritası', 'kuka-island-core' ), __( 'Her işin hangi ekrandan yapıldığını bulun.', 'kuka-island-core' ), admin_url( 'admin.php?page=kuka-island-management-map' ) ),
		);
		$warnings = self::operator_warnings( $content );
		$iyzico_checks = self::iyzico_application_checks( $content );
		$iyzico_totals = self::iyzico_readiness_totals( $iyzico_checks );
		?>
		<div class="wrap kuka-admin-home"><h1><?php esc_html_e( 'Kuka Island / Başlangıç', 'kuka-island-core' ); ?></h1>
		<p><?php esc_html_e( 'Günlük işinize aşağıdaki kartlardan başlayın.', 'kuka-island-core' ); ?></p>
		<div class="kuka-status-grid">
			<div class="card"><h2><?php esc_html_e( 'Mağaza durumu', 'kuka-island-core' ); ?></h2><p><strong><?php echo 'yes' === get_option( 'woocommerce_coming_soon' ) ? esc_html__( 'Çok yakında', 'kuka-island-core' ) : esc_html__( 'Yayında', 'kuka-island-core' ); ?></strong></p></div>
			<div class="card"><h2><?php esc_html_e( 'Arama motorları', 'kuka-island-core' ); ?></h2><p><strong><?php echo get_option( 'blog_public' ) ? esc_html__( 'İndekslemeye açık', 'kuka-island-core' ) : esc_html__( 'Engelleniyor', 'kuka-island-core' ); ?></strong></p></div>
			<div class="card"><h2><?php esc_html_e( 'Ürün özeti', 'kuka-island-core' ); ?></h2><p><?php echo esc_html( sprintf( __( '%1$d yayında · %2$d taslak', 'kuka-island-core' ), (int) ( $products->publish ?? 0 ), (int) ( $products->draft ?? 0 ) ) ); ?></p></div>
			<div class="card"><h2><?php esc_html_e( 'Dikkat gerekenler', 'kuka-island-core' ); ?></h2><p><strong><?php echo esc_html( (string) count( $warnings ) ); ?></strong></p></div>
		</div>
		<div class="kuka-task-grid"><?php foreach ( $routes as $route ) : ?><div class="card"><h2><?php echo esc_html( $route[0] ); ?></h2><p><?php echo esc_html( $route[1] ); ?></p><a class="button button-primary" href="<?php echo esc_url( $route[2] ); ?>"><?php esc_html_e( 'Ekrana git', 'kuka-island-core' ); ?></a></div><?php endforeach; ?></div>
		<section class="kuka-iyzico-readiness" aria-labelledby="kuka-iyzico-readiness-title">
			<h2 id="kuka-iyzico-readiness-title"><?php esc_html_e( 'iyzico başvurusuna hazır mıyız?', 'kuka-island-core' ); ?></h2>
			<p><strong><?php echo esc_html( sprintf( __( '%1$d / %2$d otomatik kriter tamam', 'kuka-island-core' ), $iyzico_totals['complete'], $iyzico_totals['total'] ) ); ?></strong>
			<?php if ( $iyzico_totals['not_applicable'] > 0 ) : ?>
				<span class="description"><?php echo esc_html( sprintf( /* translators: %d: number of criteria the operator declared not applicable. */ __( '%d kriter “uygulanamaz” olarak işaretlendi ve eksik sayılmıyor.', 'kuka-island-core' ), $iyzico_totals['not_applicable'] ) ); ?></span>
			<?php endif; ?>
			</p>
			<ul class="kuka-readiness-list">
			<?php
			foreach ( $iyzico_checks as $check ) :
				$row_class = $check['complete'] ? 'is-complete' : ( empty( $check['applicable'] ) ? 'is-not-applicable' : 'is-missing' );
				$row_mark  = $check['complete'] ? '&#10003;' : ( empty( $check['applicable'] ) ? '&#8211;' : '&#8212;' );
				$row_note  = '';
				if ( 'unverified' === ( $check['state'] ?? '' ) ) {
					$row_note = __( 'Mevcut seçili ancak değer boş veya geçersiz.', 'kuka-island-core' );
				} elseif ( empty( $check['applicable'] ) ) {
					$row_note = __( 'Uygulanamaz — yayımlanmaz, eksik sayılmaz.', 'kuka-island-core' );
				} elseif ( ! empty( $check['legal'] ) && self::LEGAL_STATUS_PENDING === ( $check['state'] ?? '' ) ) {
					// Durum etiketi yalnız operatörün beyan ettiği yasal satırlara
					// aittir; teknik kriterlerde tik/tire zaten yeterli.
					$row_note = __( 'Bekliyor', 'kuka-island-core' );
				}
				?>
				<li class="<?php echo esc_attr( $row_class ); ?>">
					<span aria-hidden="true"><?php echo $row_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span><?php echo esc_html( $check['label'] ); ?><?php echo '' === $row_note ? '' : ' <em>' . esc_html( $row_note ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<a href="<?php echo esc_url( $check['url'] ); ?>"><?php esc_html_e( 'İlgili ekrana git', 'kuka-island-core' ); ?></a>
				</li>
			<?php endforeach; ?>
			</ul>
			<form class="kuka-iyzico-documents" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<h3><?php esc_html_e( 'Başvuru belgeleri (manuel kontrol)', 'kuka-island-core' ); ?></h3>
				<input type="hidden" name="action" value="kuka_island_save_iyzico_documents">
				<?php wp_nonce_field( 'kuka_island_save_iyzico_documents' ); ?>
				<div class="kuka-iyzico-document-grid">
				<?php foreach ( self::iyzico_document_fields() as $key => $label ) : ?>
					<label><input type="checkbox" name="iyzico_documents[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $content['legal'][ $key ] ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
				</div>
				<p class="description"><strong><?php esc_html_e( 'Uyarı:', 'kuka-island-core' ); ?></strong> <?php esc_html_e( 'Şirket unvanı, vergi levhası ve banka hesabı ismi aynı olmalıdır.', 'kuka-island-core' ); ?></p>
				<?php submit_button( __( 'Belge durumunu kaydet', 'kuka-island-core' ), 'secondary', 'submit', false ); ?>
			</form>
		</section>
		<h2><?php esc_html_e( 'Tutarlılık uyarıları', 'kuka-island-core' ); ?></h2>
		<?php if ( $warnings ) : ?><div class="notice notice-warning inline"><ul class="ul-disc"><?php foreach ( $warnings as $warning ) : ?><li><?php echo esc_html( $warning[0] ); ?> <a href="<?php echo esc_url( $warning[1] ); ?>"><?php esc_html_e( 'Düzelt', 'kuka-island-core' ); ?></a></li><?php endforeach; ?></ul></div><?php else : ?><div class="notice notice-success inline"><p><?php esc_html_e( 'Etkin bir tutarsızlık uyarısı yok.', 'kuka-island-core' ); ?></p></div><?php endif; ?>
		<?php do_action( 'kuka_island_start_page_email_tools' ); ?>
		</div>
		<?php
	}

	/** At least nine actionable consistency checks; only active findings are shown. */
	private static function operator_warnings( array $content ): array {
		$appearance = admin_url( 'admin.php?page=kuka-island-appearance' );
		$checks = array(
			array( 'yes' !== get_option( 'woocommerce_coming_soon' ), __( 'Mağaza “Çok yakında” modunda değil.', 'kuka-island-core' ), admin_url( 'admin.php?page=wc-settings&tab=site-visibility' ) ),
			array( (bool) get_option( 'blog_public' ), __( 'Arama motoru indeksleme engeli kapalı.', 'kuka-island-core' ), admin_url( 'options-reading.php' ) ),
			array( empty( $content['brand']['favicon_id'] ), __( 'Favicon seçilmemiş.', 'kuka-island-core' ), add_query_arg( 'tab', 'brand', $appearance ) ),
			array( empty( $content['brand']['social_share_image_id'] ), __( 'Sosyal paylaşım görseli seçilmemiş.', 'kuka-island-core' ), add_query_arg( 'tab', 'brand', $appearance ) ),
			array( empty( $content['hero']['desktop_image_id'] ), __( 'Ana hero masaüstü görseli seçilmemiş.', 'kuka-island-core' ), add_query_arg( 'tab', 'hero', $appearance ) ),
			array( empty( $content['hero']['mobile_image_id'] ), __( 'Ana hero mobil görseli seçilmemiş.', 'kuka-island-core' ), add_query_arg( 'tab', 'hero', $appearance ) ),
			array( str_contains( implode( ' ', $content['legal'] ?? array() ), '[' ), __( 'Şirket veya yasal bilgilerde yer tutucu kalmış.', 'kuka-island-core' ), add_query_arg( 'tab', 'legal', $appearance ) ),
			array( empty( $content['brand']['email'] ) || ! is_email( $content['brand']['email'] ), __( 'Geçerli iletişim e-postası eksik.', 'kuka-island-core' ), add_query_arg( 'tab', 'brand', $appearance ) ),
			array( empty( $content['brand']['whatsapp_phone'] ), __( 'WhatsApp numarası eksik.', 'kuka-island-core' ), add_query_arg( 'tab', 'brand', $appearance ) ),
			array( (int) ( $content['commercial']['cayma_hakki_gun'] ?? 0 ) < 14, __( 'Cayma hakkı süresi 14 günden kısa.', 'kuka-island-core' ), add_query_arg( 'tab', 'commercial', $appearance ) ),
			array( ! empty( $content['footer']['newsletter_enabled'] ) && empty( $content['footer']['newsletter_consent'] ), __( 'Bülten açık ancak onay metni boş.', 'kuka-island-core' ), add_query_arg( 'tab', 'footer', $appearance ) ),
			array( ! empty( $content['membership']['enabled'] ), __( 'Bakım sözleşmesindeki misafir alışveriş kararına rağmen üyelik açık.', 'kuka-island-core' ), add_query_arg( 'tab', 'membership', $appearance ) ),
		);
		// Yasal kimlikler alan başına uyarılır: "bekliyor" lansman eksikliğidir,
		// "mevcut" seçilip boş bırakılan alan veri hatasıdır, "uygulanamaz" ise
		// hiç uyarı üretmez.
		$labels    = self::legal_field_labels();
		$states    = self::legal_field_states( $content );
		$pending   = array_keys( array_filter( $states, static fn( string $state ): bool => self::LEGAL_STATUS_PENDING === $state ) );
		$unverified = array_keys( array_filter( $states, static fn( string $state ): bool => 'unverified' === $state ) );
		$checks[] = array(
			(bool) $pending,
			sprintf(
				/* translators: %s: comma separated list of legal identifier names. */
				__( 'Lansman için durumu “bekliyor” olan yasal alanlar var: %s', 'kuka-island-core' ),
				implode( ', ', array_map( static fn( string $key ): string => $labels[ $key ], $pending ) )
			),
			add_query_arg( 'tab', 'legal', $appearance ),
		);
		$checks[] = array(
			(bool) $unverified,
			sprintf(
				/* translators: %s: comma separated list of legal identifier names. */
				__( '“Mevcut” işaretli ancak değeri boş veya geçersiz olan yasal alanlar var: %s', 'kuka-island-core' ),
				implode( ', ', array_map( static fn( string $key ): string => $labels[ $key ], $unverified ) )
			),
			add_query_arg( 'tab', 'legal', $appearance ),
		);
		$warnings = array_values( array_map( static fn( array $check ): array => array( $check[1], $check[2] ), array_filter( $checks, static fn( array $check ): bool => (bool) $check[0] ) ) );
		return apply_filters( 'kuka_island_operator_warnings', $warnings );
	}

	/**
	 * Status key => value key for every legal identifier that may or may not
	 * apply to this seller. The pairing is the single source both the panel,
	 * the storefront and the readiness meter read.
	 *
	 * @return array<string, string>
	 */
	public static function legal_status_map(): array {
		return array(
			'mersis_status'               => 'mersis_number',
			'kep_status'                  => 'kep_address',
			'professional_chamber_status' => 'professional_chamber',
			'professional_rules_status'   => 'professional_rules_url',
			'etbis_status'                => 'etbis_number',
		);
	}

	/** @return array<string, string> */
	public static function legal_status_labels(): array {
		return array(
			self::LEGAL_STATUS_PENDING        => __( 'Bekliyor', 'kuka-island-core' ),
			self::LEGAL_STATUS_PRESENT        => __( 'Mevcut', 'kuka-island-core' ),
			self::LEGAL_STATUS_NOT_APPLICABLE => __( 'Uygulanamaz', 'kuka-island-core' ),
		);
	}

	/** Criterion labels used by the readiness meter. @return array<string, string> */
	public static function legal_field_labels(): array {
		return array(
			'mersis_number'          => __( 'MERSİS numarası', 'kuka-island-core' ),
			'kep_address'            => __( 'KEP adresi', 'kuka-island-core' ),
			'professional_chamber'   => __( 'Meslek odası', 'kuka-island-core' ),
			'professional_rules_url' => __( 'Mesleki davranış kuralları', 'kuka-island-core' ),
			'etbis_number'           => __( 'ETBİS numarası', 'kuka-island-core' ),
		);
	}

	/**
	 * A value counts as verified when the operator actually filled it in with
	 * data of the right shape. Placeholders left in brackets are treated as
	 * unfilled so a template string can never reach a legal page.
	 */
	public static function legal_value_verified( string $value_key, string $value ): bool {
		$value = trim( $value );
		if ( '' === $value || str_contains( $value, '[' ) ) {
			return false;
		}
		if ( 'kep_address' === $value_key ) {
			return (bool) is_email( $value );
		}
		if ( 'professional_rules_url' === $value_key ) {
			return '' !== self::sanitize_url( $value );
		}
		return true;
	}

	/**
	 * Resolve one legal row into the four states the rest of the code branches
	 * on: `not_applicable`, `present` (declared and verified), `unverified`
	 * (declared present but the value is missing or malformed) and `pending`.
	 */
	public static function legal_field_state( array $content, string $value_key ): string {
		$status_key = (string) array_search( $value_key, self::legal_status_map(), true );
		$status     = (string) ( $content['legal'][ $status_key ] ?? self::LEGAL_STATUS_PENDING );
		if ( self::LEGAL_STATUS_NOT_APPLICABLE === $status ) {
			return self::LEGAL_STATUS_NOT_APPLICABLE;
		}
		if ( self::LEGAL_STATUS_PRESENT !== $status ) {
			return self::LEGAL_STATUS_PENDING;
		}
		return self::legal_value_verified( $value_key, (string) ( $content['legal'][ $value_key ] ?? '' ) )
			? self::LEGAL_STATUS_PRESENT
			: 'unverified';
	}

	/** Only a declared-present, verified value may be published on the site. */
	public static function legal_field_publishable( array $content, string $value_key ): bool {
		return self::LEGAL_STATUS_PRESENT === self::legal_field_state( $content, $value_key );
	}

	/** @return array<string, string> value key => resolved state */
	public static function legal_field_states( ?array $content = null ): array {
		$content ??= self::get();
		$states    = array();
		foreach ( self::legal_status_map() as $value_key ) {
			$states[ $value_key ] = self::legal_field_state( $content, $value_key );
		}
		return $states;
	}

	/** @return array<string, string> */
	private static function iyzico_document_fields(): array {
		return array(
			'iyzico_tax_certificate' => __( 'Vergi levhası hazır', 'kuka-island-core' ),
			'iyzico_signature_circular' => __( 'İmza sirküleri hazır', 'kuka-island-core' ),
			'iyzico_identity_copy' => __( 'Kimlik fotokopisi hazır', 'kuka-island-core' ),
			'iyzico_iban_document' => __( 'Banka IBAN doğrulama belgesi hazır', 'kuka-island-core' ),
			'iyzico_findeks_report' => __( 'Findeks Risk Raporu hazır', 'kuka-island-core' ),
		);
	}

	/** @return array<int, array{label:string,complete:bool,url:string}> */
	public static function iyzico_application_checks( ?array $content = null ): array {
		$content ??= self::get();
		$appearance = admin_url( 'admin.php?page=kuka-island-appearance' );
		$page_check = static function ( string $path, string $label ): array {
			$page = get_page_by_path( $path, OBJECT, 'page' );
			return array(
				'label' => $label,
				'complete' => $page instanceof WP_Post && 'publish' === $page->post_status,
				'url' => $page instanceof WP_Post ? admin_url( 'post.php?post=' . $page->ID . '&action=edit' ) : admin_url( 'edit.php?post_type=page' ),
			);
		};

		$shipping = get_page_by_path( 'kargo-teslimat', OBJECT, 'page' );
		$returns = get_page_by_path( 'iade-degisim', OBJECT, 'page' );
		$real_product = (bool) get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array( 'key' => '_kuka_pilot_expected_variations', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_price', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ),
				),
			)
		);
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$iyzico_active = (bool) array_filter( $active_plugins, static fn( string $plugin ): bool => str_contains( $plugin, 'iyzico' ) );
		$plugin_cards = glob( WP_PLUGIN_DIR . '/*iyzico*/assets/images/cards_v2.png' ) ?: array();
		// Beş yasal kimlik artık tek zorunlu kriter değil; her biri kendi
		// satırında operatör beyanıyla ölçülür. "Uygulanamaz" satır ne tamam ne
		// eksik sayılır, bu yüzden paydadan da düşer.
		$legal_rows = array();
		foreach ( self::legal_field_labels() as $value_key => $label ) {
			$state        = self::legal_field_state( $content, $value_key );
			$legal_rows[] = array(
				'label'      => $label,
				'legal'      => true,
				'state'      => $state,
				'complete'   => self::LEGAL_STATUS_PRESENT === $state,
				'applicable' => self::LEGAL_STATUS_NOT_APPLICABLE !== $state,
				'url'        => add_query_arg( 'tab', 'legal', $appearance ),
			);
		}

		$checks = array_merge(
			array(
				$page_check( 'hakkimizda', __( 'Hakkımızda sayfası yayında', 'kuka-island-core' ) ),
				$page_check( 'gizlilik-politikasi', __( 'Gizlilik Politikası yayında', 'kuka-island-core' ) ),
				$page_check( 'mesafeli-satis-sozlesmesi', __( 'Mesafeli Satış Sözleşmesi yayında', 'kuka-island-core' ) ),
				array( 'label' => __( 'Kargo ve İade sayfaları yayında', 'kuka-island-core' ), 'complete' => $shipping instanceof WP_Post && 'publish' === $shipping->post_status && $returns instanceof WP_Post && 'publish' === $returns->post_status, 'url' => $shipping instanceof WP_Post ? admin_url( 'post.php?post=' . $shipping->ID . '&action=edit' ) : admin_url( 'edit.php?post_type=page' ) ),
				$page_check( 'iletisim', __( 'İletişim sayfası yayında', 'kuka-island-core' ) ),
				array( 'label' => __( 'Site HTTPS kullanıyor', 'kuka-island-core' ), 'complete' => 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ), 'url' => admin_url( 'site-health.php' ) ),
				array( 'label' => __( 'iyzico eklentisi etkin', 'kuka-island-core' ), 'complete' => $iyzico_active, 'url' => admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ),
				array( 'label' => __( 'Ödeme sayfasındaki iyzico kart şeridi hazır', 'kuka-island-core' ), 'complete' => ! empty( $plugin_cards ), 'url' => admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ),
			),
			$legal_rows,
			array(
				array( 'label' => __( 'Pilot olmayan, fiyatlı en az bir ürün yayında', 'kuka-island-core' ), 'complete' => $real_product, 'url' => admin_url( 'edit.php?post_type=product' ) ),
				array( 'label' => __( 'Mağaza “Çok yakında” modundan çıkarıldı', 'kuka-island-core' ), 'complete' => 'yes' !== get_option( 'woocommerce_coming_soon' ), 'url' => admin_url( 'admin.php?page=wc-settings&tab=site-visibility' ) ),
			)
		);

		return array_map(
			static function ( array $check ): array {
				$check['applicable'] ??= true;
				$check['legal']      ??= false;
				$check['state']      ??= $check['complete'] ? 'complete' : self::LEGAL_STATUS_PENDING;
				return $check;
			},
			$checks
		);
	}

	/**
	 * Applicable rows only. A row the operator declared "uygulanamaz" is not a
	 * launch gap, so it leaves both the numerator and the denominator.
	 *
	 * @param array<int, array{complete:bool,applicable:bool}> $checks
	 * @return array{complete:int,total:int,missing:int,not_applicable:int}
	 */
	public static function iyzico_readiness_totals( array $checks ): array {
		$applicable = array_values( array_filter( $checks, static fn( array $check ): bool => ! empty( $check['applicable'] ) ) );
		$complete   = count( array_filter( $applicable, static fn( array $check ): bool => ! empty( $check['complete'] ) ) );
		return array(
			'complete'       => $complete,
			'total'          => count( $applicable ),
			'missing'        => count( $applicable ) - $complete,
			'not_applicable' => count( $checks ) - count( $applicable ),
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'kuka-island-core' ) );
		}

		$content = self::get();
		$groups = self::fields();
		$requested_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $groups[ $requested_tab ] ) ? $requested_tab : (string) array_key_first( $groups );
		?>
		<div class="wrap kuka-island-settings">
			<h1><?php esc_html_e( 'Kuka Island / Site Görünümü', 'kuka-island-core' ); ?></h1>
			<p><?php esc_html_e( 'Yalnızca içerik ve ticari metinler burada yönetilir. Renk, tipografi ve ölçüler temanın tasarım sözleşmesindedir.', 'kuka-island-core' ); ?></p>
			<div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Türkçe + İngilizce:', 'kuka-island-core' ); ?></strong> <?php esc_html_e( 'Çevrilebilir alanlar aynı satırda iki sütundur. İngilizce alanı boş bırakılırsa vitrinde Türkçe metin gösterilir; URL, sayı, medya ve şirket verisi tek kaynaktır.', 'kuka-island-core' ); ?></p></div>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Site görünümü kaydedildi.', 'kuka-island-core' ); ?></p></div>
			<?php endif; ?>
			<?php $notices = get_transient( 'kuka_island_site_notices_' . get_current_user_id() ); delete_transient( 'kuka_island_site_notices_' . get_current_user_id() ); ?>
			<?php if ( is_array( $notices ) && $notices ) : ?><div class="notice notice-warning"><p><?php esc_html_e( 'Bazı bağlantı satırları kaydedilmedi:', 'kuka-island-core' ); ?></p><ul><?php foreach ( $notices as $notice ) : ?><li><?php echo esc_html( $notice ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
			<label class="screen-reader-text" for="kuka-field-search"><?php esc_html_e( 'Alanlarda ara', 'kuka-island-core' ); ?></label>
			<input class="regular-text" id="kuka-field-search" type="search" placeholder="<?php esc_attr_e( 'Alanlarda ara…', 'kuka-island-core' ); ?>" data-kuka-field-search>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Site Görünümü grupları', 'kuka-island-core' ); ?>">
			<?php foreach ( $groups as $group_key => $group ) : ?><a class="nav-tab<?php echo $active_tab === $group_key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'kuka-island-appearance', 'tab' => $group_key ), admin_url( 'admin.php' ) ) ); ?>" data-kuka-tab="<?php echo esc_attr( $group_key ); ?>"><?php echo esc_html( $group['label'] ); ?></a><?php endforeach; ?>
			</nav>
			<p class="description" data-kuka-search-status aria-live="polite"></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-kuka-settings-form>
				<input type="hidden" name="action" value="kuka_island_save_site_appearance">
				<input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>" data-kuka-active-tab>
				<?php wp_nonce_field( 'kuka_island_save_site_appearance' ); ?>
				<?php foreach ( $groups as $group_key => $group ) : ?>
					<fieldset id="<?php echo esc_attr( $group_key ); ?>" class="kuka-settings-panel<?php echo $active_tab === $group_key ? ' is-active' : ''; ?>" data-kuka-panel="<?php echo esc_attr( $group_key ); ?>">
						<legend><?php echo esc_html( $group['label'] ); ?></legend>
						<p class="description"><strong><?php esc_html_e( 'Türkçe', 'kuka-island-core' ); ?></strong> <?php esc_html_e( 'kaynak', 'kuka-island-core' ); ?> · <strong>(EN)</strong> <?php esc_html_e( 'çeviri', 'kuka-island-core' ); ?> · <?php esc_html_e( 'boş İngilizce alanı Türkçe kaynağı kullanır.', 'kuka-island-core' ); ?></p>
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
				<div class="kuka-sticky-save"><?php submit_button( __( 'Site görünümünü kaydet', 'kuka-island-core' ), 'primary', 'submit', false ); ?><span class="description"><?php esc_html_e( 'Kayıttan sonra aynı sekmede kalırsınız.', 'kuka-island-core' ); ?></span></div>
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
			<td<?php echo $translation ? ' class="kuka-language-pair"' : ''; ?>>
			<?php if ( $translation ) : ?><div><p><strong>Türkçe</strong></p><?php endif; ?>
			<?php if ( 'story_scenes' === $type ) : ?>
				<?php $this->render_story_scenes( is_array( $value ) ? $value : array() ); ?>
			<?php elseif ( 'checkbox' === $type ) : ?>
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
			<?php elseif ( 'legal_status' === $type ) : ?>
				<select id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" name="<?php echo esc_attr( $name ); ?>">
				<?php foreach ( self::legal_status_labels() as $status_value => $status_label ) : ?>
					<option value="<?php echo esc_attr( $status_value ); ?>" <?php selected( $status_value, $value ); ?>><?php echo esc_html( $status_label ); ?></option>
				<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( '“Bekliyor” varsayılandır ve lansman eksikliği olarak sayılır. “Mevcut” yalnız yukarıdaki değer dolu ve doğrulanmışsa sitede yayımlanır. “Uygulanamaz” satırı sitede göstermez ve eksik saymaz; bu hukuki bir beyandır, mali müşavir veya hukuk danışmanı teyidi olmadan seçmeyin.', 'kuka-island-core' ); ?></p>
			<?php elseif ( 'tone' === $type ) : ?>
				<select id="<?php echo esc_attr( $group_key . '-' . $field_key ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<option value="light" <?php selected( 'light', $value ); ?>><?php esc_html_e( 'Açık metin', 'kuka-island-core' ); ?></option>
					<option value="dark" <?php selected( 'dark', $value ); ?>><?php esc_html_e( 'Koyu metin', 'kuka-island-core' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Metnin yerleştiği fotoğraf bölgesi koyuysa “Açık metin”, açıksa “Koyu metin” seçin; görsel veya başlık değiştiğinde iki dilde de önizleyip kontrastı yeniden kontrol edin.', 'kuka-island-core' ); ?></p>
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
			<?php if ( 'hero' === $group_key && 'title' === $field_key ) : ?><p class="description"><?php esc_html_e( 'Uzun başlıklar fotoğrafın koyu bölgesine taşabilir; yükledikten sonra kontrol edin.', 'kuka-island-core' ); ?></p><?php endif; ?>
			<?php if ( in_array( $type, array( 'text', 'textarea' ), true ) ) : ?><p class="description"><span data-kuka-character-count>0</span> <?php esc_html_e( 'karakter', 'kuka-island-core' ); ?></p><?php endif; ?>
			<?php if ( in_array( $type, array( 'media_image', 'media_video' ), true ) ) : ?>
				<?php $media_alt = $value ? trim( (string) get_post_meta( absint( $value ), '_wp_attachment_image_alt', true ) ) : ''; ?>
				<p class="description<?php echo $value && 'media_image' === $type && '' === $media_alt ? ' kuka-alt-warning' : ''; ?>" data-kuka-alt-warning><?php echo $value && 'media_image' === $type && '' === $media_alt ? esc_html__( 'Uyarı: Bu görselin alternatif metni boş.', 'kuka-island-core' ) : esc_html__( 'Görsel seçtiğinizde Medya Kütüphanesi’ndeki alternatif metni kontrol edin.', 'kuka-island-core' ); ?></p>
			<?php endif; ?>
			<?php if ( $translation ) : ?></div><div><p><strong>(EN)</strong></p><?php $translated_field = self::fields()[ $group_key ]['fields'][ $translation['key'] ]; $this->render_control( $group_key, $translation['key'], $translated_field, $content[ $group_key ][ $translation['key'] ] ?? '' ); ?></div><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/** @param array<int, array<string, mixed>> $scenes */
	private function render_story_scenes( array $scenes ): void {
		?>
		<div data-kuka-story-scenes>
			<div data-kuka-story-list>
				<?php foreach ( array_values( $scenes ) as $index => $scene ) { $this->render_story_scene( (string) $index, $scene ); } ?>
			</div>
			<p><button class="button" type="button" data-kuka-story-add><?php esc_html_e( 'Sahne ekle', 'kuka-island-core' ); ?></button></p>
			<template data-kuka-story-template><?php $this->render_story_scene( '__INDEX__', array() ); ?></template>
		</div>
		<?php
	}

	/** @param array<string, mixed> $scene */
	private function render_story_scene( string $index, array $scene ): void {
		$prefix = 'site_content[story][scenes][' . $index . ']';
		$number = is_numeric( $index ) ? str_pad( (string) ( (int) $index + 1 ), 2, '0', STR_PAD_LEFT ) : '';
		/* translators: %s is the two-digit scene number shown in the story repeater. */
		$scene_label = sprintf( __( 'Sahne %s', 'kuka-island-core' ), $number );
		$transition_options = array(
			'zoom-out' => __( 'Fotoğraf uzaklaşır / metin alttan', 'kuka-island-core' ),
			'crossfade-left' => __( 'Çapraz erime / metin soldan', 'kuka-island-core' ),
			'fade-center' => __( 'Çapraz erime / merkezde belirme', 'kuka-island-core' ),
			'line-sequence' => __( 'Sabit fotoğraf / satır sırası', 'kuka-island-core' ),
			'grow-right' => __( 'Fotoğraf büyür / metin sağdan', 'kuka-island-core' ),
			'gather' => __( 'Çapraz erime / merkezde toplanma', 'kuka-island-core' ),
		);
		$position_options = array(
			'left-bottom' => __( 'Sol alt', 'kuka-island-core' ),
			'left-center' => __( 'Sol orta', 'kuka-island-core' ),
			'center' => __( 'Merkez', 'kuka-island-core' ),
			'right-center' => __( 'Sağ orta', 'kuka-island-core' ),
		);
		$gradient_options = array(
			'none' => __( 'Yok', 'kuka-island-core' ),
			'soft' => __( 'Yumuşak', 'kuka-island-core' ),
			'medium' => __( 'Orta', 'kuka-island-core' ),
			'strong' => __( 'Güçlü', 'kuka-island-core' ),
		);
		?>
		<fieldset data-kuka-story-scene style="border:1px solid #c3c4c7;margin:0 0 1rem;padding:1rem">
			<legend data-kuka-story-number style="font-weight:600;padding:0 0.5rem"><?php echo esc_html( $scene_label ); ?></legend>
			<p><button class="button" type="button" data-kuka-story-up><?php esc_html_e( 'Yukarı', 'kuka-island-core' ); ?></button> <button class="button" type="button" data-kuka-story-down><?php esc_html_e( 'Aşağı', 'kuka-island-core' ); ?></button> <button class="button-link-delete" type="button" data-kuka-story-remove><?php esc_html_e( 'Sahneyi kaldır', 'kuka-island-core' ); ?></button></p>
			<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem">
				<div><p><strong>Türkçe</strong></p><label><?php esc_html_e( 'Metin', 'kuka-island-core' ); ?><textarea class="large-text" rows="7" name="<?php echo esc_attr( $prefix . '[text]' ); ?>"><?php echo esc_textarea( (string) ( $scene['text'] ?? '' ) ); ?></textarea></label><?php $this->render_story_media( $prefix, 'desktop_image_id', __( 'Masaüstü görsel', 'kuka-island-core' ), absint( $scene['desktop_image_id'] ?? 0 ) ); ?><?php $this->render_story_media( $prefix, 'mobile_image_id', __( 'Mobil görsel', 'kuka-island-core' ), absint( $scene['mobile_image_id'] ?? 0 ) ); ?><label><?php esc_html_e( 'Metin tonu', 'kuka-island-core' ); ?><select name="<?php echo esc_attr( $prefix . '[text_tone]' ); ?>"><option value="light" <?php selected( 'light', $scene['text_tone'] ?? 'light' ); ?>><?php esc_html_e( 'Açık metin', 'kuka-island-core' ); ?></option><option value="dark" <?php selected( 'dark', $scene['text_tone'] ?? 'light' ); ?>><?php esc_html_e( 'Koyu metin', 'kuka-island-core' ); ?></option></select></label></div>
				<div><p><strong>(EN)</strong></p><label><?php esc_html_e( 'Metin (EN)', 'kuka-island-core' ); ?><textarea class="large-text" rows="7" name="<?php echo esc_attr( $prefix . '[text_en]' ); ?>"><?php echo esc_textarea( (string) ( $scene['text_en'] ?? '' ) ); ?></textarea></label><?php $this->render_story_media( $prefix, 'desktop_image_id_en', __( 'Masaüstü görsel (EN)', 'kuka-island-core' ), absint( $scene['desktop_image_id_en'] ?? 0 ) ); ?><?php $this->render_story_media( $prefix, 'mobile_image_id_en', __( 'Mobil görsel (EN)', 'kuka-island-core' ), absint( $scene['mobile_image_id_en'] ?? 0 ) ); ?><label><?php esc_html_e( 'Metin tonu (EN)', 'kuka-island-core' ); ?><select name="<?php echo esc_attr( $prefix . '[text_tone_en]' ); ?>"><option value="light" <?php selected( 'light', $scene['text_tone_en'] ?? 'light' ); ?>><?php esc_html_e( 'Açık metin', 'kuka-island-core' ); ?></option><option value="dark" <?php selected( 'dark', $scene['text_tone_en'] ?? 'light' ); ?>><?php esc_html_e( 'Koyu metin', 'kuka-island-core' ); ?></option></select></label></div>
			</div>
			<p><label><?php esc_html_e( 'Geçiş tipi', 'kuka-island-core' ); ?> <select name="<?php echo esc_attr( $prefix . '[transition_type]' ); ?>"><?php foreach ( $transition_options as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $scene['transition_type'] ?? 'fade-center' ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
			<p><label><?php esc_html_e( 'Metin konumu', 'kuka-island-core' ); ?> <select name="<?php echo esc_attr( $prefix . '[text_position]' ); ?>"><?php foreach ( $position_options as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $scene['text_position'] ?? 'left-bottom' ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
			<p><label><?php esc_html_e( 'Okuma gradyanı', 'kuka-island-core' ); ?> <select name="<?php echo esc_attr( $prefix . '[gradient_intensity]' ); ?>"><?php foreach ( $gradient_options as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $scene['gradient_intensity'] ?? 'medium' ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
			<p><input type="hidden" name="<?php echo esc_attr( $prefix . '[reveal_lines]' ); ?>" value="0"><label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[reveal_lines]' ); ?>" value="1" <?php checked( ! empty( $scene['reveal_lines'] ) ); ?>> <?php esc_html_e( 'Satırları sırayla aç (kısa, bilinçli satır dizileri için)', 'kuka-island-core' ); ?></label></p>
		</fieldset>
		<?php
	}

	private function render_story_media( string $prefix, string $key, string $label, int $value ): void {
		?>
		<div data-kuka-media-field data-media-type="image"><p><strong><?php echo esc_html( $label ); ?></strong></p><input class="small-text" type="number" min="0" name="<?php echo esc_attr( $prefix . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" readonly> <button class="button" type="button" data-kuka-media-select><?php esc_html_e( 'Medyadan seç', 'kuka-island-core' ); ?></button> <button class="button-link-delete" type="button" data-kuka-media-clear><?php esc_html_e( 'Temizle', 'kuka-island-core' ); ?></button> <span data-kuka-media-preview><?php echo $value ? wp_get_attachment_image( $value, array( 80, 80 ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></div>
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
		update_option( self::OPTION_NAME, self::sanitize( $raw ), true );
		self::clear_content_cache();
		self::sync_free_shipping_threshold();
		if ( self::$sanitize_notices ) {
			set_transient( 'kuka_island_site_notices_' . get_current_user_id(), self::$sanitize_notices, MINUTE_IN_SECONDS );
		}

		$active_tab = sanitize_key( wp_unslash( $_POST['active_tab'] ?? '' ) );
		if ( ! isset( self::fields()[ $active_tab ] ) ) { $active_tab = 'brand'; }
		wp_safe_redirect( add_query_arg( array( 'updated' => '1', 'tab' => $active_tab ), admin_url( 'admin.php?page=kuka-island-appearance' ) ) );
		exit;
	}

	/** Save only the manual iyzico document checklist shown on the start page. */
	public function save_iyzico_documents(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'kuka-island-core' ), 403 );
		}
		check_admin_referer( 'kuka_island_save_iyzico_documents' );
		$saved = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) { $saved = array(); }
		if ( ! isset( $saved['legal'] ) || ! is_array( $saved['legal'] ) ) { $saved['legal'] = array(); }
		$submitted = isset( $_POST['iyzico_documents'] ) && is_array( $_POST['iyzico_documents'] ) ? wp_unslash( $_POST['iyzico_documents'] ) : array();
		foreach ( self::iyzico_document_fields() as $key => $label ) {
			$saved['legal'][ $key ] = '1' === (string) ( $submitted[ $key ] ?? '0' );
			unset( $label );
		}
		$saved['schema_version'] = self::SCHEMA_VERSION;
		update_option( self::OPTION_NAME, $saved, true );
		self::clear_content_cache();
		wp_safe_redirect( add_query_arg( 'documents-updated', '1', admin_url( 'admin.php?page=kuka-island#kuka-iyzico-readiness-title' ) ) );
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
					case 'story_scenes':
						$scenes = array();
						foreach ( array_slice( is_array( $value ) ? array_values( $value ) : array(), 0, 20 ) as $scene ) {
							if ( ! is_array( $scene ) ) { continue; }
							$text    = sanitize_textarea_field( (string) ( $scene['text'] ?? '' ) );
							$text_en = sanitize_textarea_field( (string) ( $scene['text_en'] ?? '' ) );
							if ( '' === trim( $text ) && '' === trim( $text_en ) ) { continue; }
							$scenes[] = array(
								'text' => $text,
								'text_en' => $text_en,
								'desktop_image_id' => absint( $scene['desktop_image_id'] ?? 0 ),
								'desktop_image_id_en' => absint( $scene['desktop_image_id_en'] ?? 0 ),
								'mobile_image_id' => absint( $scene['mobile_image_id'] ?? 0 ),
								'mobile_image_id_en' => absint( $scene['mobile_image_id_en'] ?? 0 ),
								'text_tone' => in_array( $scene['text_tone'] ?? '', array( 'light', 'dark' ), true ) ? $scene['text_tone'] : 'light',
								'text_tone_en' => in_array( $scene['text_tone_en'] ?? '', array( 'light', 'dark' ), true ) ? $scene['text_tone_en'] : 'light',
								'transition_type' => in_array( $scene['transition_type'] ?? '', array( 'zoom-out', 'crossfade-left', 'fade-center', 'line-sequence', 'grow-right', 'gather' ), true ) ? $scene['transition_type'] : 'fade-center',
								'text_position' => in_array( $scene['text_position'] ?? '', array( 'left-bottom', 'left-center', 'center', 'right-center' ), true ) ? $scene['text_position'] : 'left-bottom',
								'gradient_intensity' => in_array( $scene['gradient_intensity'] ?? '', array( 'none', 'soft', 'medium', 'strong' ), true ) ? $scene['gradient_intensity'] : 'medium',
								'reveal_lines' => '1' === (string) ( $scene['reveal_lines'] ?? '0' ),
							);
						}
						$value = $scenes;
						break;
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
							$value = array_slice( array_filter( array_map( 'sanitize_text_field', preg_split( '/\R/', (string) $value ) ?: array() ) ), 0, 3, true );
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
					case 'legal_status':
						// Tanınmayan gönderim sessizce "uygulanamaz"a düşmemeli;
						// güvenli varsayılan her zaman "bekliyor"dur.
						$value = in_array( $value, array( self::LEGAL_STATUS_PENDING, self::LEGAL_STATUS_PRESENT, self::LEGAL_STATUS_NOT_APPLICABLE ), true )
							? (string) $value
							: self::LEGAL_STATUS_PENDING;
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
		if ( str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' ) && ! str_contains( $value, '\\' ) ) {
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
