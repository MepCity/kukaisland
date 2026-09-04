<?php
/**
 * WooCommerce'in yerleşik sipariş karşılama ve kargo takip özelliği.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Fulfillments {
	private const SETUP_OPTION  = 'kuka_island_fulfillments_setup_version';
	private const SETUP_VERSION = 1;

	/**
	 * Özellik seçeneği WooCommerce'in init:10 kontrolünden önce yazılmalıdır.
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'install' ), 5 );

		/*
		 * Gönderim e-postalarının Türkçesi.
		 *
		 * WooCommerce'in kendi tr_TR çevirisi bu iki e-postada makine
		 * çevirisidir ve müşteriye gider. 4 Eylül 2026'da ölçülen hâli:
		 *
		 *   konu    "Kuka Island Siparişteki  bir öğe yerine getirildi!"
		 *   başlık  "Öğeniz yolda!"
		 *
		 * "Yerine getirildi" bir kargo bildiriminin Türkçesi değildir, ve çift
		 * boşluk çevirinin yer tutucusundan geliyor. Metin burada, Core'da
		 * düzeltilir: e-posta hem operatörün "müşteriye bildir" işaretinden hem
		 * de kargo eklentisinin otomatik bildiriminden aynı sınıfla gönderilir,
		 * dolayısıyla tek doğru yer ikisinin de ortak olduğu katmandır.
		 *
		 * Öncelik 10: İngilizce sipariş filtresi (Language sınıfı, öncelik 20)
		 * bunun ÜSTÜNE yazar, böylece İngilizce siparişlerde WooCommerce'in
		 * kendi doğal İngilizce metni korunur.
		 */
		foreach ( array( 'customer_fulfillment_created', 'customer_fulfillment_updated' ) as $email_id ) {
			add_filter( 'woocommerce_email_subject_' . $email_id, array( self::class, 'turkish_subject' ), 10, 3 );
			add_filter( 'woocommerce_email_heading_' . $email_id, array( self::class, 'turkish_heading' ), 10, 3 );
		}

		// Gövde metni şablonun içinde `esc_html__()` ile basılır; filtrelenebilir
		// bir kancası yoktur. Şablonu kopyalamak yerine yalnız bu birkaç dizgi
		// çeviri katmanında düzeltilir; WooCommerce şablonu değişse de geçerli
		// kalır ve vendor dosyası kopyalanmaz.
		add_filter( 'gettext', array( self::class, 'natural_turkish' ), 20, 3 );
	}

	/**
	 * Gönderim e-postası gövdesindeki makine çevirisi cümleleri.
	 *
	 * Ölçülen hâl (4 Eylül 2026, tr_TR): "Woo! Satın aldığınız bazı öğeler
	 * yerine getiriliyor." — bir kargo bildiriminde "öğe yerine getirilmez".
	 *
	 * Sıcak yol ucuz: önce tek bir dizi araması yapılır, eşleşme yoksa hiçbir
	 * şey hesaplanmaz. Dil `en_US`'e çevrilmişse (İngilizce sipariş, bkz.
	 * Language::switch_email_locale) çeviri katmanı zaten İngilizce özgün
	 * metni verir ve olduğu gibi geçer.
	 *
	 * @param string $translation Çeviri katmanının verdiği metin.
	 * @param string $text        Özgün İngilizce metin.
	 * @param string $domain      Metin alanı.
	 */
	public static function natural_turkish( string $translation, string $text, string $domain ): string {
		if ( 'woocommerce' !== $domain ) {
			return $translation;
		}

		static $map = null;

		$map ??= array(
			'Woo! Some items you purchased are being fulfilled. You can use the below information to track your shipment:'
				=> 'Siparişinizdeki ürünler kargoya verildi. Aşağıdaki bilgilerle gönderinizi takip edebilirsiniz:',
			'Some details of your shipment have recently been updated. This may include tracking information, item contents, or delivery status.'
				=> 'Kargonuzla ilgili bazı bilgiler güncellendi. Takip numarası, gönderi içeriği veya teslim durumu değişmiş olabilir.',
			'Here’s the latest info we have:'
				=> 'Elimizdeki en güncel bilgiler şöyle:',
			'Note from the store:'
				=> 'Mağaza notu:',
			'Please note that couriers may need some time to provide the latest shipping information.'
				=> 'Kargo firmasının takip bilgilerini güncellemesi biraz zaman alabilir.',
		);

		if ( ! isset( $map[ $text ] ) ) {
			return $translation;
		}

		if ( str_starts_with( get_locale(), 'tr' ) ) {
			return $map[ $text ];
		}

		/*
		 * Türkçe değilse özgün İNGİLİZCE metin döndürülür, `$translation`
		 * değil: `switch_to_locale( 'en_US' )` çağrıldıktan sonra `woocommerce`
		 * alanının tr_TR girdileri bellekte kalabiliyor ve çeviri katmanı yine
		 * Türkçe verebiliyor. İngilizce bir siparişin müşterisine bayat Türkçe
		 * göndermemenin tek kesin yolu kaynağa dönmektir.
		 */
		return $text;
	}

	/**
	 * Bu ileti İngilizce bir siparişe mi gidiyor?
	 *
	 * İki kaynak, bu sırayla: e-postanın taşıdığı sipariş nesnesi, yoksa o an
	 * geçerli dil. Dil kaynağı gerekli, çünkü gövde metni çeviri katmanından
	 * geçer ve orada sipariş bağlamı yoktur.
	 *
	 * Çeviri tablosuna GÜVENİLMEZ. `switch_to_locale( 'en_US' )` sonrasında
	 * `woocommerce` alanının tr_TR çevirileri bellekte kalabildiği için
	 * `__()` yine Türkçe döndürebilir; bu yüzden İngilizce metin burada
	 * doğrudan yazılıdır, bir çeviri çağrısından beklenmez.
	 *
	 * @param mixed $object WooCommerce e-posta nesnesi (sipariş beklenir).
	 */
	private static function is_english_order( $object ): bool {
		if ( $object instanceof WC_Order ) {
			return 'en_US' === (string) $object->get_meta( '_kuka_order_locale', true );
		}

		return ! str_starts_with( get_locale(), 'tr' );
	}

	/**
	 * @param mixed $object WooCommerce e-posta nesnesi (sipariş beklenir).
	 */
	public static function turkish_subject( string $subject, $object, WC_Email $email ): string {
		unset( $subject );

		if ( self::is_english_order( $object ) ) {
			return $email->format_string(
				'customer_fulfillment_updated' === $email->id
					? 'Your {site_title} order {order_number} shipping details were updated'
					: 'Your {site_title} order {order_number} has shipped!'
			);
		}

		if ( 'customer_fulfillment_updated' === $email->id ) {
			return $email->format_string( __( '{site_title} siparişinizin kargo bilgisi güncellendi', 'kuka-island-core' ) );
		}

		return $email->format_string( __( '{site_title} siparişiniz kargoya verildi!', 'kuka-island-core' ) );
	}

	/**
	 * @param mixed $object WooCommerce e-posta nesnesi (sipariş beklenir).
	 */
	public static function turkish_heading( string $heading, $object, WC_Email $email ): string {
		unset( $heading );

		if ( self::is_english_order( $object ) ) {
			return 'customer_fulfillment_updated' === $email->id
				? 'Your shipping details were updated'
				: 'Your order is on its way';
		}

		if ( 'customer_fulfillment_updated' === $email->id ) {
			return __( 'Kargo bilginiz güncellendi', 'kuka-island-core' );
		}

		return __( 'Siparişiniz yola çıktı', 'kuka-island-core' );
	}

	/**
	 * WooCommerce tablo şemasına doğrudan müdahale etmez. Özellik açılınca
	 * çekirdeğin FulfillmentsController sınıfı tabloları dbDelta ile kurar.
	 */
	public static function install(): void {
		if ( 'yes' !== get_option( 'woocommerce_feature_fulfillments_enabled', 'no' ) ) {
			update_option( 'woocommerce_feature_fulfillments_enabled', 'yes', true );
		}

		if ( self::SETUP_VERSION === (int) get_option( self::SETUP_OPTION, 0 ) ) {
			return;
		}

		// Yeni gönderim, gönderim güncellemesi ve iptali bildirimleri varsayılan
		// olarak açık gelir. Varsa satıcının konu/başlık ayarlarını koruruz.
		$email_ids = array(
			'customer_fulfillment_created',
			'customer_fulfillment_updated',
			'customer_fulfillment_deleted',
		);

		foreach ( $email_ids as $email_id ) {
			$option_key = 'woocommerce_' . $email_id . '_settings';
			$settings   = get_option( $option_key, array() );
			$settings   = is_array( $settings ) ? $settings : array();

			if ( ! array_key_exists( 'enabled', $settings ) ) {
				$settings['enabled'] = 'yes';
				update_option( $option_key, $settings, true );
			}
		}

		update_option( self::SETUP_OPTION, self::SETUP_VERSION, true );
	}
}
