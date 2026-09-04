<?php
/** URL-led Turkish/English locale and SEO infrastructure. */

defined( 'ABSPATH' ) || exit;

/** Return the one active storefront language code. */
function kuka_island_locale(): string {
	return Kuka_Island_Core_Language::is_english_context() ? 'en' : 'tr';
}

function kuka_island_is_english(): bool {
	return 'en' === kuka_island_locale();
}

final class Kuka_Island_Core_Language {
	private static bool $email_locale_switched = false;

	/** Gönderim bildirimi süresince siparişi tutar; bkz. register(). */
	private static ?WC_Order $fulfillment_order = null;
	private static ?bool $english_request = null;
	private static ?string $english_request_key = null;
	/** @return array<string, array<string, array{key:string,mode:string}>> */
	public static function translation_fields(): array {
		return array(
			'announcement' => array(
				'items' => array( 'key' => 'items_en', 'mode' => 'copy' ),
				'link_labels' => array( 'key' => 'link_labels_en', 'mode' => 'copy' ),
			),
			'hero' => self::simple_fields( array( 'eyebrow', 'title', 'copy', 'button_label' ) ),
			'home' => self::simple_fields( array(
				'category_index_label', 'category_index_title', 'new_arrivals_title', 'new_arrivals_copy',
				'editorial_title', 'editorial_copy', 'editorial_link_label', 'manifesto_line_1', 'manifesto_line_2',
				'service_1_title', 'service_1_copy', 'service_2_title', 'service_2_copy', 'service_3_title', 'service_3_copy',
			) ),
			'navigation' => array(
				'main' => array( 'key' => 'main_labels_en', 'mode' => 'labels' ),
				'categories' => array( 'key' => 'categories_labels_en', 'mode' => 'labels' ),
				'help' => array( 'key' => 'help_labels_en', 'mode' => 'labels' ),
			),
			'footer' => array_merge(
				self::simple_fields( array( 'newsletter_eyebrow', 'newsletter_title', 'newsletter_copy', 'newsletter_consent' ) ),
				array(
					'help_links' => array( 'key' => 'help_links_labels_en', 'mode' => 'labels' ),
					'legal_links' => array( 'key' => 'legal_links_labels_en', 'mode' => 'labels' ),
				)
			),
			'commercial' => self::simple_fields( array(
				'delivery_time', 'return_shipping_responsibility', 'shipping_copy', 'free_shipping_remaining_copy',
				'free_shipping_ready_copy', 'flat_rate_copy', 'hygiene_copy', 'hygiene_defect_copy',
				'hygiene_try_on_copy', 'secure_payment_copy', 'support_hours',
			) ),
		);
	}

	/** @param array<int, string> $keys @return array<string, array{key:string,mode:string}> */
	private static function simple_fields( array $keys ): array {
		$fields = array();
		foreach ( $keys as $key ) { $fields[ $key ] = array( 'key' => $key . '_en', 'mode' => 'copy' ); }
		return $fields;
	}

	public static function translation_config( string $group, string $key ): ?array {
		return self::translation_fields()[ $group ][ $key ] ?? null;
	}

	/** First-pass English copy supplied for every non-legal Site Appearance field. */
	public static function translation_defaults(): array {
		return array(
			'announcement' => array(
				'items_en' => array( 'Free shipping on orders over ₺4,000' ),
				'link_labels_en' => array( 'Learn more' ),
			),
			'hero' => array(
				'eyebrow_en' => 'KUKA ISLAND / NEW SEASON',
				'title_en' => 'Designed for your escape. Est. 2026',
				'copy_en' => 'Clean, confident pieces made to move with you all day.',
				'button_label_en' => 'Discover new arrivals',
			),
			'home' => array(
				'category_index_label_en' => 'Find your shape',
				'category_index_title_en' => 'Product categories',
				'new_arrivals_title_en' => 'New Arrivals',
				'new_arrivals_copy_en' => 'A first look at the new-season edit.',
				'editorial_title_en' => 'Designed for endless summers',
				'editorial_copy_en' => 'An everyday uniform, from the city to the shore.',
				'editorial_link_label_en' => 'Read the story',
				'manifesto_line_1_en' => 'Sun. Skin. Freedom.',
				'manifesto_line_2_en' => 'Not a place. A feeling.',
				'service_1_title_en' => 'Secure payment',
				'service_1_copy_en' => 'iyzico infrastructure · 3D Secure',
				'service_2_title_en' => 'Easy returns',
				'service_2_copy_en' => '14-day right of withdrawal',
				'service_3_title_en' => 'Support',
				'service_3_copy_en' => 'Weekdays 09:00–18:00 · WhatsApp',
			),
			'navigation' => array(
				'main_labels_en' => "New Arrivals\nOur Story",
				'categories_labels_en' => "Bikinis\nSwimsuits\nBeachwear\nCollections",
				'help_labels_en' => "Size Guide\nShipping & Delivery\nReturns\nFrequently Asked Questions\nContact\nOrder Tracking",
			),
			'footer' => array(
				'newsletter_eyebrow_en' => 'Island letters',
				'newsletter_title_en' => 'Join our island letters',
				'newsletter_copy_en' => 'Join our email list for new collections and notes from the studio.',
				'newsletter_consent_en' => 'I have read the Privacy Policy and consent to receiving communications.',
				'help_links_labels_en' => "Size Guide\nShipping & Delivery\nReturns\nFrequently Asked Questions\nContact\nOrder Tracking",
				'legal_links_labels_en' => "Distance Sales Agreement\nPre-information Form\nRight of Withdrawal and Returns\nKVKK Privacy Notice\nPrivacy Policy\nCookie Policy\nExplicit Consent Text",
			),
			'commercial' => array(
				'delivery_time_en' => '[DELIVERY TIME]',
				'return_shipping_responsibility_en' => '[PARTY RESPONSIBLE FOR RETURN SHIPPING COSTS]',
				'shipping_copy_en' => 'Free shipping on orders over ₺4,000.',
				'free_shipping_remaining_copy_en' => 'Add %s more to qualify for free shipping.',
				'free_shipping_ready_copy_en' => 'Your order qualifies for free shipping.',
				'flat_rate_copy_en' => 'Standard shipping is calculated at checkout.',
				'hygiene_copy_en' => 'Returns or exchanges are not accepted for bikinis and swimsuits if the hygiene seal has been removed, or if the item has been used, washed, stained, carries traces of perfume, cream or deodorant, or is no longer fit for resale.',
				'hygiene_defect_copy_en' => 'Your statutory rights are reserved for faulty products.',
				'hygiene_try_on_copy_en' => 'You may try the item on over your underwear without removing the hygiene seal.',
				'secure_payment_copy_en' => 'Your payment details are processed over a secure connection.',
				'support_hours_en' => 'Weekdays 09:00–18:00',
			),
		);
	}

	/** Add the reviewed first-pass English defaults to the field contract. */
	public static function with_translation_defaults( array $content ): array {
		// Teknik, sayısal, medya, şirket ve marka alanları tek kaynaktır. Faz 5B
		// öncesinden kalan yanlış ikizler vitrinde ya da panelde yeniden belirmesin.
		unset( $content['languages']['items_en'], $content['brand']['social_links_labels_en'] );
		$defaults = self::translation_defaults();
		foreach ( self::translation_fields() as $group => $fields ) {
			foreach ( $fields as $config ) {
				if ( ! array_key_exists( $config['key'], $content[ $group ] ?? array() ) ) {
					$content[ $group ][ $config['key'] ] = $defaults[ $group ][ $config['key'] ] ?? '';
				}
			}
		}
		return $content;
	}

	/** Resolve English values field-by-field, falling back to Turkish. */
	public static function localized_content( array $content ): array {
		$content = self::with_translation_defaults( $content );
		if ( ! self::is_english_request() ) { return $content; }
		foreach ( self::translation_fields() as $group => $fields ) {
			foreach ( $fields as $source_key => $config ) {
				$translated = $content[ $group ][ $config['key'] ] ?? '';
				if ( 'labels' === $config['mode'] ) {
					$content[ $group ][ $source_key ] = self::translated_labels( (string) ( $content[ $group ][ $source_key ] ?? '' ), (string) $translated );
				} elseif ( is_array( $content[ $group ][ $source_key ] ?? null ) ) {
					if ( is_array( $translated ) && array_filter( $translated, 'strlen' ) ) { $content[ $group ][ $source_key ] = $translated; }
				} elseif ( '' !== trim( (string) $translated ) ) {
					$content[ $group ][ $source_key ] = $translated;
				}
			}
		}
		return $content;
	}

	private static function translated_labels( string $source, string $translations ): string {
		$labels = preg_split( '/\R/', $translations ) ?: array();
		$rows   = preg_split( '/\R/', $source ) ?: array();
		foreach ( $rows as $index => &$row ) {
			$label = trim( (string) ( $labels[ $index ] ?? '' ) );
			if ( '' === $label ) { continue; }
			$parts = explode( '|', $row );
			$parts[0] = $label;
			$row = implode( '|', $parts );
		}
		return implode( "\n", $rows );
	}

	public static function translation_field_count(): int {
		return array_sum( array_map( 'count', self::translation_fields() ) );
	}

	public function register(): void {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'rewrite_rules_array', array( $this, 'translated_rewrite_rules' ), 20 );
		add_filter( 'locale', array( $this, 'request_locale' ), 1 );
		add_filter( 'determine_locale', array( $this, 'request_locale' ), 1 );
		add_action( 'wp', array( $this, 'switch_runtime_locale' ), 1 );
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 20, 4 );
		foreach ( array( 'post_link', 'page_link', 'post_type_link', 'term_link', 'paginate_links', 'woocommerce_get_cart_url', 'woocommerce_get_checkout_url', 'woocommerce_get_myaccount_page_permalink', 'woocommerce_get_checkout_order_received_url', 'woocommerce_get_return_url' ) as $url_filter ) {
			add_filter( $url_filter, array( $this, 'filter_public_url' ), 20 );
		}
		add_filter( 'wp_redirect', array( $this, 'filter_public_redirect' ), 20, 2 );
		add_action( 'wp', array( $this, 'remember_storefront_language' ), 2 );
		add_action( 'wp_head', array( $this, 'language_metadata' ), 0 );
		add_filter( 'wp_sitemaps_enabled', '__return_true' );
		add_action( 'init', array( $this, 'register_sitemap_provider' ), 20 );
		add_filter( 'gettext', array( $this, 'english_interface' ), 20, 3 );
		add_filter( 'ngettext', array( $this, 'english_plural_interface' ), 20, 5 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_locale' ), 20 );
		add_filter( 'woocommerce_allow_switching_email_locale', array( $this, 'switch_email_locale' ), 20, 2 );
		add_filter( 'woocommerce_allow_restoring_email_locale', array( $this, 'restore_email_locale' ), 20, 2 );
		// Gönderim bildirimleri de sipariş diline tabidir: İngilizce bir sipariş
		// kargoya verildiğinde müşteri İngilizce metin görür.
		/*
		 * Gönderim e-postalarında sipariş, dil anahtarına ULAŞMIYOR.
		 *
		 * WC_Email_Customer_Fulfillment_Created::trigger() önce setup_locale()
		 * çağırır, `$this->object` siparişi ondan SONRA atanır. Standart sipariş
		 * e-postalarında sıra terstir. Bu yüzden switch_email_locale() filtresi
		 * bu iki e-postada `$email->object` alanını boş görüyor ve İngilizce bir
		 * sipariş için dil hiç değişmiyordu: müşteri Türkçe metin alıyordu.
		 *
		 * Bildirim eylemi WooCommerce'in kendi trigger'ından (öncelik 10) önce
		 * dinlenip sipariş kenara yazılır, sonra temizlenir. Hem operatörün
		 * "müşteriye bildir" işareti hem de kargo eklentisinin otomatik
		 * bildirimi aynı eylemi kullandığı için ikisi de düzelir.
		 */
		foreach ( array( 'woocommerce_fulfillment_created_notification', 'woocommerce_fulfillment_updated_notification' ) as $fulfillment_hook ) {
			add_action( $fulfillment_hook, array( $this, 'remember_fulfillment_order' ), 9, 3 );
			add_action( $fulfillment_hook, array( $this, 'forget_fulfillment_order' ), 999 );
		}

		foreach ( array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_refunded_order', 'customer_invoice', 'customer_note', 'customer_failed_order' ) as $email_id ) {
			add_filter( 'woocommerce_email_heading_' . $email_id, array( $this, 'english_email_heading' ), 20, 3 );
			add_filter( 'woocommerce_email_subject_' . $email_id, array( $this, 'english_email_subject' ), 20, 3 );
			add_filter( 'woocommerce_email_additional_content_' . $email_id, array( $this, 'english_email_additional_content' ), 20, 3 );
		}

		/*
		 * Gönderim e-postalarının konu ve başlığı BU listede yer almaz, bilerek.
		 *
		 * `english_email_subject()` İngilizce metni `get_default_subject()`
		 * üzerinden alır, o da `__()` çağrısıdır: `switch_to_locale( 'en_US' )`
		 * sonrasında `woocommerce` alanının tr_TR girdileri bellekte kalınca
		 * geriye TÜRKÇE makine çevirisi döner ve Fulfillments sınıfının yazdığı
		 * doğru İngilizce metnin üstüne yazar. Ölçülen hâli tam olarak buydu.
		 *
		 * Bu iki e-postanın iki dili de tek yerde, sipariş diline bakarak
		 * Kuka_Island_Core_Fulfillments::turkish_subject()/turkish_heading()
		 * içinde belirlenir. Dil anahtarı (yukarıdaki
		 * remember_fulfillment_order + switch_email_locale) yine geçerlidir:
		 * gövdedeki WooCommerce metinleri onunla İngilizceye geçer.
		 */
		foreach ( array( 'customer_fulfillment_created', 'customer_fulfillment_updated' ) as $email_id ) {
			add_filter( 'woocommerce_email_additional_content_' . $email_id, array( $this, 'english_email_additional_content' ), 20, 3 );
		}
	}

	public function english_interface( string $translation, string $text, string $domain ): string {
		if ( ! in_array( $domain, array( 'kuka-island', 'kuka-island-core' ), true ) || ! self::is_english_context() ) { return $translation; }
		static $map = null;
		$map ??= array(
			'E-posta adresi' => 'Email address', 'Katıl' => 'Join', 'Şirket' => 'Company',
			'E-posta' => 'Email', 'E-posta:' => 'Email:', 'Telefon' => 'Phone', 'Telefon:' => 'Phone:',
			'Destek saatleri:' => 'Support hours:', 'Adres' => 'Address', 'Satıcı' => 'Seller', 'Marka' => 'Brand',
			'Vergi Dairesi' => 'Tax Office', 'Vergi Kimlik No' => 'Tax Identification Number', 'MERSİS No' => 'MERSIS Number',
			'KEP adresi' => 'Registered Email (KEP)', 'Meslek odası' => 'Professional Chamber',
			'Kayıtlı olunan meslek odası' => 'Professional Chamber',
			'Mesleki davranış kuralları' => 'Professional Code of Conduct',
			'Uygulanan mesleki davranış kuralları' => 'Professional Code of Conduct', 'ETBİS numarası' => 'ETBIS Number',
			'MERSİS numarası' => 'MERSIS Number',
			// Yasal alan durumu: operatör beyanı, hukuki varsayım değil.
			'Bekliyor' => 'Pending', 'Mevcut' => 'Present', 'Uygulanamaz' => 'Not applicable',
			'Bikini üstü' => 'Bikini top', 'Bikini altı' => 'Bikini bottom', 'Mayo' => 'Swimsuit',
			'Göğüs (cm)' => 'Bust (cm)', 'Göğüs altı (cm)' => 'Underbust (cm)', 'Bel (cm)' => 'Waist (cm)',
			'Kalça (cm)' => 'Hips (cm)', 'Kupa' => 'Cup',
			'Doğrulama bağlantısını e-posta adresinize gönderdik.' => 'We sent a verification link to your email address.',
			'E-posta adresiniz doğrulandı. Bülten kaydınız tamamlandı.' => 'Your email address has been verified. Your subscription is complete.',
			'Doğrulama bağlantısı geçersiz veya süresi dolmuş. Lütfen yeniden kaydolun.' => 'The verification link is invalid or expired. Please subscribe again.',
			'Devam etmek için onay kutusunu işaretleyin.' => 'Please select the consent checkbox to continue.',
			'Geçerli bir e-posta adresi girin.' => 'Enter a valid email address.',
			'Lütfen yeniden göndermeden önce kısa bir süre bekleyin.' => 'Please wait a moment before submitting again.',
			'Kayıt şu anda tamamlanamadı. Lütfen tekrar deneyin.' => 'Your registration could not be completed. Please try again.',
			'Ada mektupları' => 'Island letters', 'Yardım' => 'Help', 'Yasal' => 'Legal', 'Sosyal' => 'Social',
			'WhatsApp destek' => 'WhatsApp support', 'Formunu bul' => 'Find your shape', 'Ürün kategorileri' => 'Product categories',
			'Çok yakında' => 'Coming soon', 'Oturum aç' => 'Log in', 'by %s' => 'by %s',
			'Koleksiyon' => 'Collection', 'Tümünü gör' => 'View all', 'Editoryal' => 'Editorial', 'Hikâyeyi oku' => 'Read the story',
			'Servis güvenceleri' => 'Service assurances', 'Ana sayfa' => 'Home', 'İçeriğe geç' => 'Skip to content',
			'Duyurular' => 'Announcements', 'Sepete dön' => 'Back to cart', 'Menüyü aç' => 'Open menu', 'Ana menü' => 'Main menu',
			'Ürün ara' => 'Search products', 'Sepeti aç' => 'Open cart', 'Menü' => 'Menu', 'Menüyü kapat' => 'Close menu',
			'Mobil menü' => 'Mobile menu', 'Ara' => 'Search', 'Aramayı kapat' => 'Close search', 'Ne arıyorsunuz?' => 'What are you looking for?',
			'Ürün, renk veya kesim' => 'Product, color or cut', 'Hızlı bağlantılar' => 'Quick links', 'Sık aranan' => 'Popular searches',
			'Sepeti kapat' => 'Close cart', 'Tükendi' => 'Sold out', 'Sınırlı' => 'Limited', 'Yeni' => 'New',
			'Renk seçimi' => 'Color selection', 'Beden seçimi' => 'Size selection', 'Stokta' => 'In stock', 'Aktif filtreler' => 'Active filters',
			'Filtrele' => 'Filter', 'Filtreleri kapat' => 'Close filters', 'Stokta olanlar' => 'In stock only', 'Kesim' => 'Cut',
			'Renk' => 'Color', 'Beden' => 'Size', 'Temizle' => 'Clear', 'Sonuçları gör' => 'View results',
			'Bu seçimde ürün bulunamadı.' => 'No products were found for this selection.', 'Filtrelerden birini kaldırarak yeniden deneyin.' => 'Remove a filter and try again.',
			'Filtreleri temizle' => 'Clear filters', 'Teslimat adresi' => 'Shipping address', 'Fatura bilgileri' => 'Billing details',
			'Adres' => 'Address', 'Adres devamı' => 'Address line 2', 'İl' => 'Province',
			'Cadde, sokak, bina ve kapı numarası' => 'Street, building and door number', 'Site, blok, daire vb.' => 'Complex, block, apartment, etc.',
			'Kişisel bilgiler' => 'Personal information', 'Sepet' => 'Cart', 'Bilgiler ve ödeme' => 'Details and payment', 'Onay' => 'Confirmation',
			'Ödeme adımları' => 'Checkout steps', 'Yardım gerekiyor mu?' => 'Need help?', 'Güvenli ödeme' => 'Secure payment',
			'Kargo ve iade' => 'Shipping and returns', 'Sepetiniz boş' => 'Your cart is empty', 'Ada seçkisini keşfedin.' => 'Explore the island selection.',
			'Alışverişe başla' => 'Start shopping', 'Ara toplam' => 'Subtotal', 'Ödemeye geç' => 'Proceed to checkout', 'Sepete git' => 'View cart',
			'Ön Bilgilendirme Formu' => 'Pre-information Form', 'Mesafeli Satış Sözleşmesi' => 'Distance Sales Agreement',
			'Malzeme' => 'Material', 'Bakım' => 'Care', 'Kalıp' => 'Fit', 'Model' => 'Model', 'Beden rehberini aç' => 'Open size guide',
			'Kargo, teslimat ve iade' => 'Shipping, delivery and returns', 'Birlikte iyi gider' => 'Pairs well with', 'Parçayı incele' => 'View item',
			'Ödeme şu anda kullanılamıyor.' => 'Payment is currently unavailable.', 'Ödeme' => 'Checkout', 'Sipariş özeti' => 'Order summary',
			'Fatura adresim teslimat adresimden farklı' => 'My billing address is different from my shipping address', 'Fatura adresi' => 'Billing address',
			'Sipariş notu' => 'Order note', 'Sepeti düzenle' => 'Edit cart', 'Adet' => 'Quantity', 'Kupon kodunuz varsa girin' => 'Enter your coupon code',
			'Kupon kodu' => 'Coupon code', 'Uygula' => 'Apply', 'Tahmini teslim' => 'Estimated delivery', 'Toplam' => 'Total', 'KDV dahil' => 'VAT included',
			'Renk seçenekleri' => 'Color options', 'Önceki fotoğraf' => 'Previous photo', 'Sonraki fotoğraf' => 'Next photo',
			'Eski fiyat:' => 'Previous price:', 'Yeni fiyat:' => 'New price:', 'Beden stokları' => 'Size availability',
			'Ürün görseli' => 'Product image', 'Önceki ürün fotoğrafı' => 'Previous product photo', 'Sonraki ürün fotoğrafı' => 'Next product photo',
			'Galeriyi kapat' => 'Close gallery', 'Yakınlaştır' => 'Zoom', 'Şirket unvanı' => 'Company name', 'Fatura türü' => 'Invoice type',
			'Bireysel' => 'Personal', 'Kurumsal' => 'Business', 'Vergi dairesi' => 'Tax office', 'VKN (10 hane)' => 'Tax number (10 digits)',
			'Kurumsal fatura için şirket unvanı zorunludur.' => 'Company name is required for a business invoice.',
			'Kurumsal fatura için vergi dairesi zorunludur.' => 'Tax office is required for a business invoice.',
			'VKN 10 rakamdan oluşmalıdır.' => 'The tax number must contain 10 digits.',
			'Bu alan zorunludur.' => 'This field is required.',
			'Telefon numarası 5XX XXX XX XX biçiminde olmalıdır.' => 'The phone number must use the 5XX XXX XX XX format.',
			'Ön Bilgilendirme Formu onayı zorunludur.' => 'Acceptance of the Pre-information Form is required.',
			'Mesafeli Satış Sözleşmesi onayı zorunludur.' => 'Acceptance of the Distance Sales Agreement is required.',
			'Siparişinizi sipariş numarası ve e-posta adresinizle takip edin' => 'Track your order with your order number and email address',
			'Siparişinizi sipariş numaranız ve e-posta adresinizle takip edin' => 'Track your order with your order number and email address',
			'<a href="%s" target="_blank" rel="noopener">Ön Bilgilendirme Formu</a>’nu okudum ve kabul ediyorum.' => '<a href="%s" target="_blank" rel="noopener">I have read and accept the Pre-information Form</a>.',
			'<a href="%s" target="_blank" rel="noopener">Mesafeli Satış Sözleşmesi</a>’ni okudum ve kabul ediyorum.' => '<a href="%s" target="_blank" rel="noopener">I have read and accept the Distance Sales Agreement</a>.',
			'%1$s; tam ekran aç (%2$d/%3$d)' => '%1$s; open fullscreen (%2$d/%3$d)', '%s rengini seç' => 'Select %s',
			'%s beden tükendi' => 'Size %s is sold out', '%s beden stokta' => 'Size %s is in stock', '%s filtresini kaldır' => 'Remove %s filter',
			'Sepet / %d' => 'Cart / %d', '%s adedi' => '%s quantity', '%d gün içinde cayma hakkı' => '%d-day right of withdrawal',
			'Cayma bildiriminizi teslimattan sonra %d gün içinde iletebilirsiniz.' => 'You may submit your withdrawal notice within %d days after delivery.',
			'%s ürün galerisi' => '%s product gallery', '%s galerisi' => '%s gallery', '%s ürününü incele' => 'View %s',
		);
		return $map[ $text ] ?? $translation;
	}

	public function english_plural_interface( string $translation, string $single, string $plural, int $number, string $domain ): string {
		unset( $plural );
		if ( 'kuka-island' !== $domain || ! self::is_english_context() ) { return $translation; }
		static $map = array(
			'%d ürün' => array( '%d product', '%d products' ),
			'%d renk' => array( '%d color', '%d colors' ),
		);
		return isset( $map[ $single ] ) ? sprintf( $map[ $single ][ 1 === $number ? 0 : 1 ], $number ) : $translation;
	}

	public function save_order_locale( WC_Order $order ): void {
		$order->update_meta_data( '_kuka_order_locale', self::is_english_request() ? 'en_US' : 'tr_TR' );
	}

	/**
	 * Gönderim bildiriminin siparişi, dil anahtarı için kenara yazılır.
	 *
	 * @param int   $order_id    Sipariş kimliği.
	 * @param mixed $fulfillment WooCommerce gönderim kaydı.
	 * @param mixed $order       Sipariş nesnesi ya da false.
	 */
	public function remember_fulfillment_order( $order_id, $fulfillment = null, $order = null ): void {
		unset( $fulfillment );

		$resolved = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );

		self::$fulfillment_order = $resolved instanceof WC_Order ? $resolved : null;
	}

	/** Eylem bittiğinde kenara yazılan sipariş bırakılır. */
	public function forget_fulfillment_order(): void {
		self::$fulfillment_order = null;
	}

	/**
	 * O an işlenen gönderim bildiriminin siparişi, yoksa null.
	 *
	 * Şablonlar ve e-posta tasarımı katmanı bu siparişi `$email->object`
	 * yerine tercih eder; bkz. K-46.
	 */
	public static function current_fulfillment_order(): ?WC_Order {
		return self::$fulfillment_order;
	}

	public function switch_email_locale( bool $allow, WC_Email $email ): bool {
		/*
		 * Kenara yazılan sipariş ÖNCE gelir, `$email->object` sonra.
		 *
		 * WC_Email nesnesi yeniden kullanılır ve gönderim e-postalarında
		 * `$this->object` setup_locale()'dan SONRA atanır. Aynı istekte ikinci
		 * bir bildirim gönderildiğinde `$email->object` hâlâ ÖNCEKİ siparişi
		 * tutar; boş değil, bayat. Ölçülen sonucu şuydu: İngilizce bir sipariş,
		 * kendisinden önce gönderilen Türkçe siparişin diliyle yazıldı.
		 *
		 * Kenara yazılan değer yalnız bildirim eylemi süresince doludur
		 * (öncelik 9'da yazılır, 999'da bırakılır), bu yüzden diğer bütün
		 * e-postalarda davranış değişmez.
		 */
		$order = self::$fulfillment_order instanceof WC_Order ? self::$fulfillment_order : $email->object;
		$order = $order instanceof WC_Order ? $order : null;
		if ( $order && 'en_US' === $order->get_meta( '_kuka_order_locale', true ) ) {
			switch_to_locale( 'en_US' );
			if ( function_exists( 'WC' ) ) { WC()->load_plugin_textdomain(); }
			self::$email_locale_switched = true;
			return false;
		}
		return $allow;
	}

	public function restore_email_locale( bool $allow, WC_Email $email ): bool {
		unset( $email );
		if ( self::$email_locale_switched ) {
			restore_previous_locale();
			if ( function_exists( 'WC' ) ) { WC()->load_plugin_textdomain(); }
			self::$email_locale_switched = false;
			return false;
		}
		return $allow;
	}

	public function english_email_heading( string $heading, $object, WC_Email $email ): string {
		if ( ! $object instanceof WC_Order || 'en_US' !== $object->get_meta( '_kuka_order_locale', true ) ) { return $heading; }
		return $email->format_string( $email->get_default_heading() );
	}

	public function english_email_subject( string $subject, $object, WC_Email $email ): string {
		if ( ! $object instanceof WC_Order || 'en_US' !== $object->get_meta( '_kuka_order_locale', true ) ) { return $subject; }
		return $email->format_string( $email->get_default_subject() );
	}

	public function english_email_additional_content( string $content, $object, WC_Email $email ): string {
		if ( ! $object instanceof WC_Order || 'en_US' !== $object->get_meta( '_kuka_order_locale', true ) ) { return $content; }
		return $email->format_string( 'Thanks for using {site_url}!' );
	}

	public static function is_english_request(): bool {
		global $wp_query;
		$query_language = $wp_query instanceof WP_Query ? (string) $wp_query->get( 'kuka_lang', '' ) : '';
		$request_key = implode( "\0", array(
			(string) ( $_SERVER['REQUEST_URI'] ?? '/' ),
			$query_language,
			(string) ( $_REQUEST['kuka_lang'] ?? '' ),
			(string) ( $_SERVER['HTTP_REFERER'] ?? '' ),
			isset( $_GET['wc-ajax'] ) ? 'wc-ajax' : '',
		) );
		if ( self::$english_request_key === $request_key && null !== self::$english_request ) { return self::$english_request; }
		self::$english_request_key = $request_key;
		if ( 'en' === $query_language ) { return self::$english_request = true; }
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		if ( (bool) preg_match( '#^/en(?:/|$)#', $path ) ) { return self::$english_request = true; }
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || isset( $_GET['wc-ajax'] ) ) {
			$requested = sanitize_key( (string) wp_unslash( $_REQUEST['kuka_lang'] ?? '' ) );
			if ( in_array( $requested, array( 'tr', 'en' ), true ) ) { return self::$english_request = 'en' === $requested; }
			$referer_path = (string) wp_parse_url( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ), PHP_URL_PATH );
			if ( '' !== $referer_path ) { return self::$english_request = (bool) preg_match( '#^/en(?:/|$)#', $referer_path ); }
			if ( function_exists( 'WC' ) && WC()->session ) { return self::$english_request = 'en' === WC()->session->get( 'kuka_storefront_language', 'tr' ); }
		}
		return self::$english_request = false;
	}

	public static function is_english_context(): bool {
		return self::$email_locale_switched || self::is_english_request();
	}

	public function query_vars( array $vars ): array {
		$vars[] = 'kuka_lang';
		return $vars;
	}

	/** Prefix every existing public rewrite while retaining the original query. */
	public function translated_rewrite_rules( array $rules ): array {
		$translated = array( 'en/?$' => 'index.php?kuka_lang=en' );
		foreach ( $rules as $pattern => $query ) {
			$pattern = ltrim( (string) $pattern, '^' );
			$query   = str_starts_with( (string) $query, 'index.php?' ) ? substr( (string) $query, 10 ) : (string) $query;
			$translated[ 'en/' . $pattern ] = 'index.php?kuka_lang=en&' . $query;
		}
		return $translated + $rules;
	}

	public function request_locale( string $locale ): string {
		return self::is_english_request() ? 'en_US' : $locale;
	}

	public function switch_runtime_locale(): void {
		if ( self::is_english_request() && 'en_US' !== get_locale() ) {
			switch_to_locale( 'en_US' );
		}
	}

	/** Persist the last normal storefront language in WooCommerce's guest session. */
	public function remember_storefront_language(): void {
		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ! function_exists( 'WC' ) || ! WC()->session ) { return; }
		WC()->session->set( 'kuka_storefront_language', self::is_english_request() ? 'en' : 'tr' );
	}

	/** Prefix ordinary storefront home URLs on an English request. */
	public function filter_home_url( string $url, string $path, ?string $scheme, ?int $blog_id ): string {
		unset( $scheme, $blog_id );
		if ( ! self::is_english_context() || str_starts_with( ltrim( $path, '/' ), 'en/' ) ) { return $url; }
		if ( self::is_technical_url( $url ) ) { return $url; }
		return self::url_for_language( $url, 'en' );
	}

	/** Prefix every internal public permalink generated outside home_url(). */
	public function filter_public_url( mixed $url ): mixed {
		if ( ! is_string( $url ) || ! self::is_english_context() || self::is_technical_url( $url ) ) { return $url; }
		return self::url_for_language( $url, 'en' );
	}

	/** Keep public English redirects in-language without touching technical endpoints. */
	public function filter_public_redirect( string $location, int $status ): string {
		unset( $status );
		return (string) $this->filter_public_url( $location );
	}

	private static function is_technical_url( string $url ): bool {
		$home = untrailingslashit( (string) get_option( 'home' ) );
		if ( ! str_starts_with( $url, $home ) ) { return true; }
		$path = ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( preg_match( '#^(?:en/)?(?:wp-admin|wp-login\.php|wp-json)(?:/|$)#', $path ) ) { return true; }
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return isset( $query['wc-ajax'] ) || str_contains( $path, 'admin-ajax.php' );
	}

	public static function url_for_language( string $url, string $language ): string {
		$home = untrailingslashit( (string) get_option( 'home' ) );
		if ( ! str_starts_with( $url, $home ) ) { return $url; }
		$rest = substr( $url, strlen( $home ) );
		$rest = preg_replace( '#^/en(?=/|$)#', '', $rest ) ?: $rest;
		return $home . ( 'en' === $language ? '/en' : '' ) . ( str_starts_with( $rest, '/' ) ? $rest : '/' . $rest );
	}

	public static function current_url( string $language ): string {
		$request = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
		$home    = untrailingslashit( (string) get_option( 'home' ) );
		$path    = '/' . ltrim( (string) wp_parse_url( $request, PHP_URL_PATH ), '/' );
		$path    = preg_replace( '#^/en(?=/|$)#', '', $path ) ?: '/';
		$url     = $home . ( 'en' === $language ? '/en' : '' ) . ( str_starts_with( $path, '/' ) ? $path : '/' . $path );
		parse_str( (string) wp_parse_url( $request, PHP_URL_QUERY ), $query );
		$product_page = absint( $query['product-page'] ?? 0 );
		return $product_page > 1 ? add_query_arg( 'product-page', $product_page, $url ) : $url;
	}

	public function language_metadata(): void {
		if ( is_admin() || is_feed() ) { return; }
		$tr = self::current_url( 'tr' );
		$en = self::current_url( 'en' );
		$current = self::is_english_request() ? $en : $tr;
		echo '<link rel="canonical" href="' . esc_url( $current ) . '">' . "\n";
		echo '<link rel="alternate" hreflang="tr" href="' . esc_url( $tr ) . '">' . "\n";
		echo '<link rel="alternate" hreflang="en" href="' . esc_url( $en ) . '">' . "\n";
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $tr ) . '">' . "\n";
		echo '<meta property="og:locale" content="' . esc_attr( self::is_english_request() ? 'en_US' : 'tr_TR' ) . '">' . "\n";
	}

	public function register_sitemap_provider(): void {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) { return; }
		$server = wp_sitemaps_get_server();
		if ( ! $server->registry->get_provider( 'english' ) ) {
			$server->registry->add_provider( 'english', new Kuka_Island_English_Sitemap_Provider() );
		}
	}
}

final class Kuka_Island_English_Sitemap_Provider extends WP_Sitemaps_Provider {
	public function __construct() {
		$this->name = 'english';
		$this->object_type = 'language';
	}

	public function get_url_list( $page_num, $object_subtype = '' ): array {
		unset( $object_subtype );
		$page_num = max( 1, (int) $page_num );
		$limit    = wp_sitemaps_get_max_urls( $this->object_type );
		$offset   = ( $page_num - 1 ) * $limit;
		$remaining = $limit;
		$urls      = array();

		if ( 0 === $offset && $remaining ) {
			$urls[] = array( 'loc' => Kuka_Island_Core_Language::url_for_language( (string) get_option( 'home' ) . '/', 'en' ) );
			--$remaining;
		} else {
			$offset = max( 0, $offset - 1 );
		}

		$post_count = self::published_post_count();
		if ( $remaining && $offset < $post_count ) {
			$number = min( $remaining, $post_count - $offset );
			$excluded = function_exists( 'wc_get_page_id' ) ? array_filter( array( wc_get_page_id( 'myaccount' ) ) ) : array();
			$post_ids = get_posts( array( 'post_type' => array( 'page', 'product' ), 'post_status' => 'publish', 'posts_per_page' => $number, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'post__not_in' => $excluded ) );
			foreach ( $post_ids as $post_id ) {
				$urls[] = array( 'loc' => Kuka_Island_Core_Language::url_for_language( get_permalink( $post_id ), 'en' ) );
			}
			$remaining -= count( $post_ids );
			$offset = 0;
		} else {
			$offset = max( 0, $offset - $post_count );
		}

		foreach ( self::sitemap_taxonomies() as $taxonomy ) {
			$count = self::taxonomy_term_count( $taxonomy );
			if ( ! $remaining ) { break; }
			if ( $offset >= $count ) { $offset -= $count; continue; }
			$number = min( $remaining, $count - $offset );
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => $number, 'offset' => $offset, 'orderby' => 'term_id', 'order' => 'ASC' ) );
			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				$term_url = get_term_link( $term );
				if ( ! is_wp_error( $term_url ) ) { $urls[] = array( 'loc' => Kuka_Island_Core_Language::url_for_language( $term_url, 'en' ) ); }
			}
			$remaining -= is_wp_error( $terms ) ? 0 : count( $terms );
			$offset = 0;
		}
		return $urls;
	}

	public function get_max_num_pages( $object_subtype = '' ): int {
		unset( $object_subtype );
		$total = 1 + self::published_post_count();
		foreach ( self::sitemap_taxonomies() as $taxonomy ) { $total += self::taxonomy_term_count( $taxonomy ); }
		return max( 1, (int) ceil( $total / wp_sitemaps_get_max_urls( $this->object_type ) ) );
	}

	/** @return array<int, string> */
	private static function sitemap_taxonomies(): array {
		return array_values( array_filter( array( 'product_cat', 'pa_renk', 'pa_kesim', 'pa_beden' ), 'taxonomy_exists' ) );
	}

	private static function published_post_count(): int {
		$total = 0;
		foreach ( array( 'page', 'product' ) as $post_type ) { $total += (int) ( wp_count_posts( $post_type )->publish ?? 0 ); }
		$account_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'myaccount' ) : 0;
		return max( 0, $total - ( $account_id > 0 && 'publish' === get_post_status( $account_id ) ? 1 : 0 ) );
	}

	private static function taxonomy_term_count( string $taxonomy ): int {
		$count = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		return is_wp_error( $count ) ? 0 : (int) $count;
	}
}
