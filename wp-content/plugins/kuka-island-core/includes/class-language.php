<?php
/** URL-led Turkish/English locale and SEO infrastructure. */

defined( 'ABSPATH' ) || exit;

/** Return the one active storefront language code. */
function kuka_island_locale(): string {
	return Kuka_Island_Core_Language::is_english_request() ? 'en' : 'tr';
}

function kuka_island_is_english(): bool {
	return 'en' === kuka_island_locale();
}

final class Kuka_Island_Core_Language {
	private static bool $email_locale_switched = false;
	/** @return array<string, array<string, array{key:string,mode:string}>> */
	public static function translation_fields(): array {
		return array(
			'brand' => array( 'social_links' => array( 'key' => 'social_links_labels_en', 'mode' => 'labels' ) ),
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

	/** Add empty English storage keys without inventing translated content. */
	public static function with_translation_defaults( array $content ): array {
		foreach ( self::translation_fields() as $group => $fields ) {
			foreach ( $fields as $config ) {
				if ( ! array_key_exists( $config['key'], $content[ $group ] ?? array() ) ) {
					$content[ $group ][ $config['key'] ] = '';
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
		add_action( 'wp_head', array( $this, 'language_metadata' ), 0 );
		add_filter( 'wp_sitemaps_enabled', '__return_true' );
		add_action( 'init', array( $this, 'register_sitemap_provider' ), 20 );
		add_filter( 'gettext', array( $this, 'english_interface' ), 20, 3 );
		add_filter( 'ngettext', array( $this, 'english_plural_interface' ), 20, 5 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_locale' ), 20 );
		add_filter( 'woocommerce_allow_switching_email_locale', array( $this, 'switch_email_locale' ), 20, 2 );
		add_filter( 'woocommerce_allow_restoring_email_locale', array( $this, 'restore_email_locale' ), 20, 2 );
	}

	public function english_interface( string $translation, string $text, string $domain ): string {
		if ( ! self::is_english_request() || ! in_array( $domain, array( 'kuka-island', 'kuka-island-core' ), true ) ) { return $translation; }
		$map = array(
			'Ada mektupları' => 'Island letters', 'Yardım' => 'Help', 'Yasal' => 'Legal', 'Sosyal' => 'Social',
			'WhatsApp destek' => 'WhatsApp support', 'Formunu bul' => 'Find your shape', 'Ürün kategorileri' => 'Product categories',
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
		if ( ! self::is_english_request() || 'kuka-island' !== $domain ) { return $translation; }
		$map = array( '%d ürün' => '%d products', '%d renk' => '%d colors' );
		return isset( $map[ $single ] ) ? sprintf( $map[ $single ], $number ) : $translation;
	}

	public function save_order_locale( WC_Order $order ): void {
		$order->update_meta_data( '_kuka_order_locale', self::is_english_request() ? 'en_US' : 'tr_TR' );
	}

	public function switch_email_locale( bool $allow, WC_Email $email ): bool {
		$order = $email->object instanceof WC_Order ? $email->object : null;
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

	public static function is_english_request(): bool {
		if ( 'en' === (string) get_query_var( 'kuka_lang', '' ) ) { return true; }
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		return (bool) preg_match( '#^/en(?:/|$)#', $path );
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

	/** Prefix ordinary storefront home URLs on an English request. */
	public function filter_home_url( string $url, string $path, ?string $scheme, ?int $blog_id ): string {
		unset( $scheme, $blog_id );
		if ( ! self::is_english_request() || str_starts_with( ltrim( $path, '/' ), 'en/' ) ) { return $url; }
		if ( preg_match( '#^(wp-admin|wp-login\.php|wp-json)(?:/|$)#', ltrim( $path, '/' ) ) ) { return $url; }
		return self::url_for_language( $url, 'en' );
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
		$path    = '/' . ltrim( $request, '/' );
		$path    = preg_replace( '#^/en(?=/|\?|$)#', '', $path ) ?: '/';
		return $home . ( 'en' === $language ? '/en' : '' ) . ( str_starts_with( $path, '/' ) ? $path : '/' . $path );
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
		if ( 1 !== (int) $page_num ) { return array(); }
		$urls = array( array( 'loc' => Kuka_Island_Core_Language::url_for_language( (string) get_option( 'home' ) . '/', 'en' ) ) );
		foreach ( get_posts( array( 'post_type' => array( 'page', 'product' ), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $post_id ) {
			$urls[] = array( 'loc' => Kuka_Island_Core_Language::url_for_language( get_permalink( $post_id ), 'en' ) );
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = array( 'loc' => Kuka_Island_Core_Language::url_for_language( wc_get_page_permalink( 'shop' ), 'en' ) );
		}
		return $urls;
	}

	public function get_max_num_pages( $object_subtype = '' ): int {
		unset( $object_subtype );
		return 1;
	}
}
