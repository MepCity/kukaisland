<?php
/**
 * Müşteri işlem e-postalarının ortak tasarımı.
 *
 * Bir e-posta istemcisi bir tarayıcı değildir: flex, grid, harici stil sayfası,
 * CSS değişkeni ve `position` yoktur. Bu yüzden mağazanın storefront CSS'i
 * e-postaya BAĞLANMAZ; burada tablo tabanlı, satır içine gömülebilen ayrı bir
 * stil üretilir ve WooCommerce'in kendi `woocommerce_email_styles` kancasından
 * verilir. Emogrifier bu CSS'i elemanlara gömer, `@media` bloğu ise belgede
 * kalır ve mobil istemcide çalışır.
 *
 * Dış görsellerin tek bir kapısı vardır: `public_image_url()`. Yalnız halka
 * açık HTTPS adresler geçer. Yerel geliştirme ortamında ürün görselinin adresi
 * `http://localhost:8080/...` olur; Gmail bu adrese erişemez ve müşteriye kırık
 * bir resim çerçevesi gider. Şablonlar bu yüzden hiçbir zaman ham eklenti
 * çıktısını basmaz, önce bu kapıdan geçirir ve reddedilen görselin yerine temiz
 * tipografik satırı koyar.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Email_Design {
	/**
	 * Masaüstü içerik genişliği. Sözleşme 760-800 px aralığıdır; WooCommerce'in
	 * kendi varsayılanı 600 px'dir ve ürün satırı için dar kalır.
	 */
	public const CONTENT_WIDTH = 780;

	/** Mobilde içeriğin kenardan güvenli mesafesi. */
	public const MOBILE_GUTTER = 18;

	/** Ürün görselinin e-postadaki kenar uzunluğu. */
	public const ITEM_IMAGE = 104;

	/**
	 * O an gönderilen e-posta nesnesi.
	 *
	 * WooCommerce'in başlık şablonu yalnız `$email_heading` alır; e-posta
	 * nesnesini almaz (`WC_Emails::email_header( $email_heading )`). Başlığın
	 * üstündeki etiket hangi e-posta olduğunu bilmek zorunda olduğu için nesne
	 * `woocommerce_email_header` eyleminden kenara yazılır ve altbilgiden sonra
	 * bırakılır.
	 *
	 * @var mixed
	 */
	private static $current_email = null;

	private const GROUND    = '#f0e9dc';
	private const SURFACE   = '#ffffff';
	private const INK       = '#16120e';
	private const MUTED     = '#71634e';
	private const HAIRLINE  = '#e3dbcc';
	private const ACCENT    = '#3c2a12';

	public function register(): void {
		add_filter( 'woocommerce_email_styles', array( self::class, 'styles' ), 20, 2 );

		/*
		 * Logo WooCommerce'in kendi başlık görseli seçeneğinden okunur, ama
		 * değer veritabanına YAZILMAZ: panelde tek bir logo alanı vardır ve
		 * ikinci bir kopya tutmak iki kaynağın ayrışması demektir. Seçenek
		 * okunurken filtrelenir, böylece operatör Site Görünümü'nde logoyu
		 * değiştirdiğinde e-posta da değişir.
		 */
		add_filter( 'option_woocommerce_email_header_image', array( self::class, 'header_image' ) );
		add_filter( 'default_option_woocommerce_email_header_image', array( self::class, 'header_image' ) );

		add_action( 'woocommerce_email_header', array( self::class, 'remember_email' ), 1, 2 );

		/*
		 * WooCommerce'in kendi sipariş satırı görseli de aynı kapıdan geçer.
		 * Bu filtre olmadan `customer_processing_order` gibi e-postalar ürün
		 * fotoğrafını `http://localhost:8080/...` adresiyle basar: Gmail o
		 * adrese erişemez ve müşteriye kırık bir resim çerçevesi gider.
		 */
		add_filter( 'woocommerce_order_item_thumbnail', array( self::class, 'gate_item_thumbnail' ), 20, 2 );

		/*
		 * Sitenin `date_format` seçeneği `F j, Y` ve bu Türkçe bir e-postada
		 * "Eylül 4, 2026" üretiyor; Türkçede gün önce gelir. Operatörün site
		 * ayarına DOKUNULMAZ: biçim yalnız bir e-posta render edilirken, yani
		 * `woocommerce_email_header` ile `woocommerce_email_footer` arasında
		 * değişir. Sipariş ekranı ve mağaza etkilenmez.
		 */
		add_filter( 'woocommerce_date_format', array( self::class, 'email_date_format' ) );

		// Banner işlem bilgilerinin ALTINDA, altbilgiden önce çıkar.
		add_action( 'woocommerce_email_footer', array( self::class, 'render_banner' ), 5 );
		add_action( 'woocommerce_email_footer', array( self::class, 'forget_email' ), 999 );
	}

	/**
	 * @param string $heading Başlık metni.
	 * @param mixed  $email   WooCommerce e-posta nesnesi.
	 */
	public static function remember_email( $heading = '', $email = null ): void {
		unset( $heading );

		self::$current_email = is_object( $email ) ? $email : null;
	}

	public static function forget_email(): void {
		self::$current_email = null;
	}

	/**
	 * O an gönderilen e-posta nesnesi, yoksa null.
	 *
	 * @return mixed
	 */
	public static function current_email() {
		return self::$current_email;
	}

	/* ---------------------------------------------------------------- medya */

	/**
	 * Bir ek dosyasının e-postaya konulabilir adresi, yoksa boş dizgi.
	 *
	 * @param int $attachment_id Ek dosya kimliği.
	 */
	public static function public_image_url( int $attachment_id ): string {
		if ( $attachment_id < 1 ) {
			return '';
		}

		$url = (string) wp_get_attachment_image_url( $attachment_id, 'full' );

		return 'ok' === self::image_gate( $url ) ? $url : '';
	}

	/**
	 * Bir adresin e-postaya konulabilir olup olmamasının gerekçesi.
	 *
	 * NE ÖLÇÜLDÜĞÜ, TAM OLARAK: adresin BİÇİMİ. Şema `https` mi, sunucu adı
	 * açıkça yerel ya da özel bir aralık mı, uzantı SVG mi. DNS sorgusu
	 * yapılmaz, HTTP isteği atılmaz; bu kapı bir adresin gerçekten
	 * çözülebildiğini ya da dosyanın orada durduğunu KANITLAMAZ. Yanlış yazılmış
	 * bir alan adı ya da 404 veren bir yol buradan geçer.
	 *
	 * Kapının işi tek bir sınıf hatayı kesmek: e-postaya erişilemeyeceği
	 * biçimden belli olan bir adres koymak — `http://localhost:8080/...` gibi.
	 *
	 * Dönen değerler ölçülebilir olsun diye sabit: `ok`, `empty`, `not_https`,
	 * `private_host`, `vector`.
	 *
	 * @param string $url İncelenen adres.
	 */
	public static function image_gate( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return 'empty';
		}

		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path  = strtolower( (string) ( $parts['path'] ?? '' ) );

		if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return 'not_https';
		}

		if ( '' === $host || ! self::host_looks_public( $host ) ) {
			return 'private_host';
		}

		// SVG bir e-posta istemcisinde çizilmez ve çoğunda tamamen engellenir.
		if ( str_ends_with( $path, '.svg' ) || str_ends_with( $path, '.svgz' ) ) {
			return 'vector';
		}

		return 'ok';
	}

	/**
	 * Sunucu adı BİÇİM olarak dışarıya açık bir adres mi?
	 *
	 * Bir ad çözümlemesi değil, bir biçim kontrolü: nokta içermeyen adlar,
	 * bilinen yerel son ekler ve özel/döngü IP aralıkları reddedilir. Geçen bir
	 * adın gerçekten çözüldüğü İDDİA EDİLMEZ.
	 *
	 * @param string $host Küçük harfe indirilmiş sunucu adı.
	 */
	private static function host_looks_public( string $host ): bool {
		if ( 'localhost' === $host || ! str_contains( $host, '.' ) ) {
			return false;
		}

		foreach ( array( '.local', '.localhost', '.test', '.invalid', '.internal', '.example' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return false;
			}
		}

		$literal = filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP );

		if ( false !== $literal ) {
			// Özel ve döngü aralıkları dışarıdan erişilemez; geri kalanın
			// erişilebilir olduğu iddia edilmez, yalnız yerel olmadığı.
			return false !== filter_var(
				$literal,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		return true;
	}

	/** Panelden yönetilen logonun e-postaya konulabilir adresi. */
	public static function logo_url(): string {
		return self::public_image_url( (int) ( self::brand()['logo_id'] ?? 0 ) );
	}

	/** Panelden yönetilen e-posta banner görselinin adresi. */
	public static function banner_url(): string {
		return self::public_image_url( (int) ( self::brand()['email_banner_id'] ?? 0 ) );
	}

	/**
	 * E-posta render edilirken kullanılacak tarih biçimi.
	 *
	 * @param mixed $format Sitenin biçimi.
	 */
	public static function email_date_format( $format ): string {
		$format = (string) $format;

		if ( null === self::$current_email || ! str_starts_with( get_locale(), 'tr' ) ) {
			return $format;
		}

		return 'j F Y';
	}

	/**
	 * Sipariş satırı görselinin adresi e-postaya konulabilir değilse boşaltılır.
	 *
	 * @param string $image_html WooCommerce'in ürettiği `<img>` etiketi.
	 * @param mixed  $item       Sipariş satırı.
	 */
	public static function gate_item_thumbnail( $image_html, $item = null ): string {
		unset( $item );

		$image_html = (string) $image_html;

		if ( '' === trim( $image_html ) ) {
			return '';
		}

		if ( 1 !== preg_match( '/\ssrc=["\']([^"\']+)["\']/', $image_html, $found ) ) {
			return '';
		}

		$gate = self::image_gate( html_entity_decode( $found[1], ENT_QUOTES, 'UTF-8' ) );

		if ( 'ok' === $gate ) {
			return $image_html;
		}

		// Adresi taşımayan, ölçülebilir bir kod bırakılır.
		return '<!-- kuka-image-gate:' . esc_html( $gate ) . ' -->';
	}

	/**
	 * WooCommerce'in başlık görseli seçeneğinin okunan değeri.
	 *
	 * @param mixed $stored Veritabanındaki değer.
	 */
	public static function header_image( $stored ): string {
		unset( $stored );

		return self::logo_url();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function brand(): array {
		if ( ! class_exists( 'Kuka_Island_Core_Site_Appearance' ) ) {
			return array();
		}

		$content = Kuka_Island_Core_Site_Appearance::get();

		return is_array( $content['brand'] ?? null ) ? $content['brand'] : array();
	}

	/* ----------------------------------------------------------------- dil */

	/**
	 * Bu ileti İngilizce bir siparişe mi gidiyor?
	 *
	 * @param mixed $email WooCommerce e-posta nesnesi.
	 */
	public static function is_english( $email ): bool {
		$order = self::order_of( $email );

		if ( $order instanceof WC_Order ) {
			return 'en_US' === (string) $order->get_meta( '_kuka_order_locale', true );
		}

		return ! str_starts_with( get_locale(), 'tr' );
	}

	/**
	 * E-postanın siparişi.
	 *
	 * `$email->object` bu iş için tek başına güvenilmez: gönderim
	 * e-postalarında sipariş `setup_locale()` çağrısından SONRA atanır ve
	 * nesneler yeniden kullanılır (bkz. K-46). Dil katmanının kenara yazdığı
	 * sipariş varsa o kullanılır.
	 *
	 * @param mixed $email WooCommerce e-posta nesnesi.
	 */
	public static function order_of( $email ): ?WC_Order {
		if ( class_exists( 'Kuka_Island_Core_Language' ) ) {
			$remembered = Kuka_Island_Core_Language::current_fulfillment_order();

			if ( $remembered instanceof WC_Order ) {
				return $remembered;
			}
		}

		$object = is_object( $email ) && isset( $email->object ) ? $email->object : null;

		return $object instanceof WC_Order ? $object : null;
	}

	/* ------------------------------------------------------------- içerik */

	/**
	 * Başlığın üstündeki küçük etiket. Yönetici e-postalarında boştur.
	 *
	 * @param mixed $email WooCommerce e-posta nesnesi.
	 */
	public static function eyebrow( $email ): string {
		$id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';

		if ( '' === $id || ! str_starts_with( $id, 'customer_' ) ) {
			return '';
		}

		$english = self::is_english( $email );

		if ( str_starts_with( $id, 'customer_fulfillment' ) ) {
			return $english ? 'ORDER UPDATE' : 'SİPARİŞ GÜNCELLEMESİ';
		}

		$labels = array(
			'customer_processing_order' => array( 'SİPARİŞİNİZ ALINDI', 'ORDER RECEIVED' ),
			'customer_completed_order'  => array( 'SİPARİŞİNİZ TAMAMLANDI', 'ORDER COMPLETE' ),
			'customer_on_hold_order'    => array( 'SİPARİŞİNİZ BEKLEMEDE', 'ORDER ON HOLD' ),
			'customer_refunded_order'   => array( 'İADE BİLGİSİ', 'REFUND UPDATE' ),
			'customer_invoice'          => array( 'ÖDEME BİLGİSİ', 'PAYMENT DETAILS' ),
			'customer_note'             => array( 'MAĞAZA NOTU', 'A NOTE FROM US' ),
			'customer_failed_order'     => array( 'ÖDEME ALINAMADI', 'PAYMENT FAILED' ),
		);

		if ( ! isset( $labels[ $id ] ) ) {
			return '';
		}

		return $english ? $labels[ $id ][1] : $labels[ $id ][0];
	}

	/**
	 * Taşıyıcının müşteriye gösterilecek adı.
	 *
	 * `_shipment_provider` bir anahtardır (`dhl`, `aras-kargo`). Müşteriye ham
	 * anahtar gösterilmez. Sıra: WooCommerce'in kendi taşıyıcı kaydı, sonra
	 * WooCommerce'te bulunmayan Türkiye taşıyıcıları, sonra bir filtre — kargo
	 * eklentisi kendi adını böyle ekleyebilir, Core ona bağımlı olmadan.
	 *
	 * @param string $key Taşıyıcı anahtarı.
	 */
	public static function carrier_label( string $key ): string {
		$key = trim( $key );

		if ( '' === $key ) {
			return '';
		}

		$label    = '';
		$registry = '\Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils';

		if ( class_exists( $registry ) && method_exists( $registry, 'get_shipping_providers' ) ) {
			$providers = (array) call_user_func( array( $registry, 'get_shipping_providers' ) );
			$provider  = $providers[ $key ] ?? null;

			if ( is_object( $provider ) && method_exists( $provider, 'get_name' ) ) {
				$label = (string) $provider->get_name();
			}
		}

		if ( '' === $label ) {
			$turkish = array(
				'mng'      => 'MNG Kargo',
				'yurtici'  => 'Yurtiçi Kargo',
				'ptt'      => 'PTT Kargo',
				'surat'    => 'Sürat Kargo',
				'sendeo'   => 'Sendeo',
				'kolaygel' => 'Kolay Gelsin',
			);
			$label   = $turkish[ $key ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $key ) );
		}

		/**
		 * Taşıyıcının e-postada görünen adı.
		 *
		 * @param string $label Bulunan ad.
		 * @param string $key   Taşıyıcı anahtarı.
		 */
		return (string) apply_filters( 'kuka_island_email_carrier_label', $label, $key );
	}

	/**
	 * İşlem bilgilerinin altındaki tek yatay banner.
	 *
	 * Alan boşsa ya da görsel halka açık HTTPS değilse hiç render edilmez.
	 * Ürün önerisi TAŞIMAZ: pazarlama izni olmayan müşteriye öneri listesi
	 * basmak bir işlem e-postasının işi değildir. Yalnız marka atmosferi ve
	 * mağaza bağlantısı.
	 *
	 * @param mixed $email WooCommerce e-posta nesnesi.
	 */
	public static function render_banner( $email = null ): void {
		$id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';

		if ( '' === $id || ! str_starts_with( $id, 'customer_' ) ) {
			return;
		}

		$banner = self::banner_url();

		if ( '' === $banner ) {
			return;
		}

		$english = self::is_english( $email );
		$alt     = $english ? 'Kuka Island collection' : 'Kuka Island seçkisi';
		$cta     = $english ? 'Visit the store' : 'Mağazayı keşfet';

		printf(
			'<table border="0" cellpadding="0" cellspacing="0" width="100%%" role="presentation" class="kuka-banner"><tr><td align="center">'
				. '<a href="%1$s" target="_blank"><img src="%2$s" alt="%3$s" width="%4$d" class="kuka-banner-image" /></a>'
				. '<p class="kuka-banner-link"><a href="%1$s" target="_blank">%5$s</a></p>'
				. '</td></tr></table>',
			esc_url( home_url( '/' ) ),
			esc_url( $banner ),
			esc_attr( $alt ),
			(int) self::CONTENT_WIDTH,
			esc_html( $cta )
		);
	}

	/* ------------------------------------------------------------- stiller */

	/**
	 * WooCommerce e-posta CSS'inin sonuna eklenen Kuka Island katmanı.
	 *
	 * @param string $css   WooCommerce'in ürettiği CSS.
	 * @param mixed  $email WooCommerce e-posta nesnesi.
	 */
	public static function styles( string $css, $email = null ): string {
		unset( $email );

		$width  = (int) self::CONTENT_WIDTH;
		$gutter = (int) self::MOBILE_GUTTER;
		$thumb  = (int) self::ITEM_IMAGE;

		return $css . "
body {
	background-color: " . self::GROUND . " !important;
	color: " . self::INK . " !important;
}
#outer_wrapper {
	background-color: " . self::GROUND . " !important;
	width: 100% !important;
	padding: 0 !important;
}
.kuka-outer-cell {
	padding: 28px 14px 44px !important;
}
#wrapper {
	max-width: {$width}px !important;
	width: 100% !important;
	margin: 0 auto !important;
	padding: 0 !important;
}
#template_container, #template_header, #template_body, #template_footer {
	max-width: {$width}px !important;
	width: 100% !important;
	border: 0 !important;
	border-radius: 0 !important;
	box-shadow: none !important;
	background-color: transparent !important;
}
#template_container {
	background-color: " . self::SURFACE . " !important;
	border-radius: 14px !important;
}
#template_header_image {
	padding: 0 0 18px !important;
	text-align: center !important;
}
#template_header_image img {
	max-width: 190px !important;
	height: auto !important;
	border: 0 !important;
}
#template_header_image .kuka-wordmark a {
	color: inherit !important;
	text-decoration: none !important;
}
.kuka-wordmark {
	margin: 0 !important;
	color: " . self::ACCENT . " !important;
	font-size: 17px !important;
	font-weight: 600 !important;
	letter-spacing: 0.24em !important;
	text-transform: uppercase !important;
	text-decoration: none !important;
}
#header_wrapper {
	padding: 34px 38px 6px !important;
	background-color: " . self::SURFACE . " !important;
	text-align: left !important;
	border-radius: 14px 14px 0 0 !important;
}
#header_wrapper h1 {
	margin: 0 !important;
	color: " . self::INK . " !important;
	font-size: 27px !important;
	font-weight: 600 !important;
	line-height: 1.2 !important;
	letter-spacing: -0.01em !important;
	text-align: left !important;
	text-shadow: none !important;
}
.kuka-eyebrow {
	margin: 0 0 10px !important;
	color: " . self::ACCENT . " !important;
	font-size: 11px !important;
	font-weight: 600 !important;
	letter-spacing: 0.16em !important;
	text-transform: uppercase !important;
}
#body_content, #body_content_inner {
	background-color: " . self::SURFACE . " !important;
	color: " . self::INK . " !important;
}
#body_content_inner_cell {
	padding: 14px 38px 34px !important;
}
#body_content_inner, #body_content_inner p, #body_content_inner td {
	font-size: 15px !important;
	line-height: 1.62 !important;
	color: " . self::INK . " !important;
	text-align: left !important;
}
#body_content_inner a {
	color: " . self::ACCENT . " !important;
	text-decoration: underline !important;
}
#body_content_inner .email-introduction p, #body_content_inner .kuka-intro {
	margin: 0 0 22px !important;
	font-size: 16px !important;
}
#body_content_inner .kuka-card {
	margin: 0 0 26px !important;
	background-color: #faf7f1 !important;
	border: 1px solid " . self::HAIRLINE . " !important;
	border-radius: 12px !important;
}
#body_content_inner .kuka-card-cell {
	padding: 20px 22px !important;
}
#body_content_inner .kuka-card-label {
	margin: 0 0 4px !important;
	color: " . self::MUTED . " !important;
	font-size: 11px !important;
	letter-spacing: 0.12em !important;
	text-transform: uppercase !important;
}
#body_content_inner .kuka-card-value {
	margin: 0 0 16px !important;
	color: " . self::INK . " !important;
	font-size: 16px !important;
	font-weight: 600 !important;
}
#body_content_inner .kuka-card-value-last {
	margin-bottom: 0 !important;
}
#body_content_inner .kuka-code {
	font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace !important;
	letter-spacing: 0.04em !important;
}
#body_content_inner .kuka-button {
	margin: 0 0 28px !important;
}
#body_content_inner .kuka-button a {
	display: inline-block !important;
	padding: 15px 30px !important;
	background-color: " . self::INK . " !important;
	color: #ffffff !important;
	border-radius: 999px !important;
	font-size: 15px !important;
	font-weight: 600 !important;
	text-decoration: none !important;
}
#body_content_inner .kuka-section-title {
	margin: 0 0 14px !important;
	color: " . self::INK . " !important;
	font-size: 13px !important;
	font-weight: 600 !important;
	letter-spacing: 0.12em !important;
	text-transform: uppercase !important;
}
#body_content_inner .kuka-items {
	width: 100% !important;
	border: 0 !important;
	border-collapse: collapse !important;
	margin: 0 0 26px !important;
}
#body_content_inner .kuka-items td {
	border: 0 !important;
	border-bottom: 1px solid " . self::HAIRLINE . " !important;
	padding: 16px 0 !important;
	vertical-align: top !important;
}
#body_content_inner .kuka-item-thumb {
	width: {$thumb}px !important;
	padding-right: 18px !important;
}
#body_content_inner .kuka-item-thumb img {
	display: block !important;
	width: {$thumb}px !important;
	height: auto !important;
	border: 1px solid " . self::HAIRLINE . " !important;
	border-radius: 8px !important;
}
#body_content_inner .kuka-item-name {
	margin: 0 0 4px !important;
	color: " . self::INK . " !important;
	font-size: 15px !important;
	font-weight: 600 !important;
}
#body_content_inner .kuka-item-meta {
	margin: 0 !important;
	color: " . self::MUTED . " !important;
	font-size: 13px !important;
}
#body_content_inner .kuka-item-price {
	white-space: nowrap !important;
	text-align: right !important;
	color: " . self::INK . " !important;
	font-size: 15px !important;
	font-weight: 600 !important;
}
#body_content_inner .kuka-note {
	margin: 0 !important;
	color: " . self::MUTED . " !important;
	font-size: 13px !important;
}
#body_content_inner .kuka-banner {
	margin: 6px 0 0 !important;
}
#body_content_inner .kuka-banner-image {
	display: block !important;
	width: 100% !important;
	max-width: {$width}px !important;
	height: auto !important;
	border: 0 !important;
	border-radius: 12px !important;
}
#body_content_inner .kuka-banner-link {
	margin: 12px 0 0 !important;
	font-size: 13px !important;
	letter-spacing: 0.08em !important;
	text-transform: uppercase !important;
}
#template_footer td, #credit, #credit p {
	background-color: transparent !important;
	color: " . self::MUTED . " !important;
	font-size: 12px !important;
	line-height: 1.6 !important;
	text-align: center !important;
	border: 0 !important;
	box-shadow: none !important;
}
#credit a {
	color: " . self::MUTED . " !important;
}
@media only screen and (max-width: 640px) {
	.kuka-outer-cell {
		padding: 18px 0 28px !important;
	}
	#wrapper, #template_container, #template_header, #template_body, #template_footer {
		max-width: 100% !important;
		width: 100% !important;
	}
	#template_container {
		border-radius: 0 !important;
	}
	#header_wrapper {
		padding: 26px {$gutter}px 4px !important;
		border-radius: 0 !important;
	}
	#header_wrapper h1 {
		font-size: 23px !important;
	}
	#body_content_inner_cell {
		padding: 12px {$gutter}px 26px !important;
	}
	#body_content_inner .kuka-item-thumb {
		width: 84px !important;
		padding-right: 14px !important;
	}
	#body_content_inner .kuka-item-thumb img {
		width: 84px !important;
	}
	#body_content_inner .kuka-button a {
		display: block !important;
		text-align: center !important;
	}
}
";
	}
}
