<?php
/**
 * Müşteri e-postalarının GERÇEK gönderim yolundan render edilmesi.
 *
 * `get_content_html()` doğrudan çağrılmaz. Bir e-postanın müşteride nasıl
 * görüneceği yalnız kendi tetikleyicisinden geçtiğinde belli olur: dil
 * anahtarı, kenara yazılan sipariş, konu ve başlık filtreleri ve şablon
 * kancaları o yolda çalışır. Bu yüzden ileti gerçekten gönderilir ve taşıyıcı
 * `pre_wp_mail` üzerinde kesilir: hiçbir mesaj dışarı çıkmaz, gövdesi ölçülür.
 *
 * Kullanım (konteyner içinde):
 *   php /project-scripts/render-email-preview.php <mod>
 *
 * Modlar: tr-fulfillment | en-fulfillment | tr-processing | en-processing
 *         (aynı adların `-https` eki, ürün görselinin halka açık HTTPS
 *          adresten geldiği üretim durumunu ölçer)
 *         measure  -> yalnız ölçüm satırları, HTML yok
 *
 * Fixture sipariş sahiplik damgasıyla oluşturulur; kapanışta notlarıyla
 * birlikte silinir, ölümcül hatada da silinir.
 */

require '/var/www/html/wp-load.php';
require_once __DIR__ . '/lib-iyzico-test-ownership.php';

$mode = (string) ( $argv[1] ?? 'measure' );

const KUKA_PREVIEW_PUBLIC_HOST = 'https://cdn.kukaisland.com';

/** @var array<string, mixed> $kuka_capture */
$kuka_capture = array(
	'subject' => '',
	'message' => '',
	'mails'   => 0,
);

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $args ) use ( &$kuka_capture ) {
		$to = (array) ( $args['to'] ?? array() );

		foreach ( $to as $address ) {
			if ( ! str_contains( strtolower( (string) $address ), 'kuka-preview@' ) ) {
				continue;
			}

			++$kuka_capture['mails'];
			$kuka_capture['subject'] = (string) ( $args['subject'] ?? '' );
			$kuka_capture['message'] = (string) ( $args['message'] ?? '' );

			return true;
		}

		return $short_circuit;
	},
	-2000,
	2
);

/* ------------------------------------------------------------------ fixture */

$kuka_run_id     = wp_generate_uuid4();
$kuka_order_ids  = array();
$kuka_fulfilled  = array();
$kuka_refusals   = array();
$kuka_state      = 'idle';
$kuka_store      = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';
$kuka_entity     = '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment';

$kuka_cleanup = static function () use ( &$kuka_order_ids, &$kuka_fulfilled, &$kuka_state, &$kuka_refusals, $kuka_run_id, $kuka_store ): void {
	$enter      = kuka_iyzico_cleanup_enter( $kuka_state );
	$kuka_state = (string) $enter['state'];

	if ( ! $enter['proceed'] ) {
		if ( '' !== (string) $enter['refusal'] ) {
			$kuka_refusals[] = (string) $enter['refusal'];
		}
		return;
	}

	foreach ( $kuka_fulfilled as $record ) {
		try {
			wc_get_container()->get( $kuka_store )->delete( $record );
		} catch ( Throwable $error ) {
			unset( $error );
			$kuka_refusals[] = 'fulfillment:delete_refused';
		}
	}

	foreach ( $kuka_order_ids as $order_id ) {
		$reason = '';

		if ( ! kuka_iyzico_fixture_is_owned( (int) $order_id, $kuka_run_id, $kuka_order_ids, $reason ) ) {
			$kuka_refusals[] = 'order:' . $reason;
			continue;
		}

		kuka_iyzico_delete_owned_order( (int) $order_id );
	}

	$kuka_state = kuka_iyzico_cleanup_finish( $kuka_refusals );
};

register_shutdown_function(
	static function () use ( $kuka_cleanup, &$kuka_state, &$kuka_refusals, $mode ): void {
		$kuka_cleanup();

		if ( 'succeeded' !== $kuka_state ) {
			fwrite( STDERR, 'PREVIEW_CLEANUP=' . $kuka_state . '|' . ( $kuka_refusals ? implode( ' | ', $kuka_refusals ) : 'unknown' ) . PHP_EOL );
			exit( 1 );
		}

		if ( 'measure' === $mode ) {
			echo 'EMAIL_PREVIEW_CLEANUP=succeeded' . PHP_EOL;
		}
	}
);

/** İlk kargolanabilir ürün ya da varyasyonu. */
function kuka_preview_product_id(): int {
	foreach ( (array) wc_get_products( array( 'status' => 'publish', 'limit' => 25, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) ) as $candidate_id ) {
		$product = wc_get_product( (int) $candidate_id );

		if ( ! $product instanceof WC_Product || ! $product->needs_shipping() ) {
			continue;
		}

		if ( ! $product->is_type( 'variable' ) ) {
			return (int) $candidate_id;
		}

		foreach ( (array) $product->get_children() as $child_id ) {
			$child = wc_get_product( (int) $child_id );

			if ( $child instanceof WC_Product && $child->needs_shipping() ) {
				return (int) $child_id;
			}
		}
	}

	return 0;
}

/** Gerçek bir görsel ek dosyasının kimliği; banner ölçümü bunu kullanır. */
function kuka_preview_attachment_id(): int {
	$product = wc_get_product( kuka_preview_product_id() );

	if ( $product instanceof WC_Product ) {
		$image_id = (int) $product->get_image_id();

		if ( 1 > $image_id && $product->get_parent_id() > 0 ) {
			$parent = wc_get_product( $product->get_parent_id() );

			if ( $parent instanceof WC_Product ) {
				$image_id = (int) $parent->get_image_id();
			}
		}

		if ( $image_id > 0 ) {
			return $image_id;
		}
	}

	$found = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/jpeg',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	return $found ? (int) $found[0] : 0;
}

/**
 * Fixture sipariş.
 *
 * @param string $run_id    Sahiplik damgası.
 * @param array  $order_ids Oluşturulan kimlikler (referans).
 * @param bool   $english   İngilizce sipariş mi?
 * @param bool   $guest     Misafir sipariş mi?
 */
function kuka_preview_order( string $run_id, array &$order_ids, bool $english, bool $guest = true ): WC_Order {
	$order = wc_create_order();
	$order->set_billing_first_name( $english ? 'Emma' : 'Ayşe' );
	$order->set_billing_last_name( $english ? 'Ross' : 'Yılmaz' );
	$order->set_billing_email( 'kuka-preview@example.test' );
	$order->set_billing_city( $english ? 'London' : 'İstanbul' );
	$order->set_billing_country( $english ? 'GB' : 'TR' );

	if ( $english ) {
		$order->update_meta_data( '_kuka_order_locale', 'en_US' );
	}

	if ( ! $guest ) {
		$order->set_customer_id( 1 );
	}

	$order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
	$order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );

	$product_id = kuka_preview_product_id();

	if ( $product_id > 0 ) {
		$order->add_product( wc_get_product( $product_id ), 2 );
	}

	$order->calculate_totals();
	$order->save();

	$order_ids[] = (int) $order->get_id();

	return $order;
}

/**
 * Fixture gönderim kaydı.
 *
 * @param WC_Order $order       Sipariş.
 * @param array    $records     Oluşturulan kayıtlar (referans).
 * @param string   $tracking    Takip numarası.
 * @param string   $tracking_url Takip adresi.
 */
function kuka_preview_fulfillment( WC_Order $order, array &$records, string $tracking = 'KI1TR0099887766', string $tracking_url = 'https://www.dhl.com/tr-tr/home/tracking.html?tracking-id=KI1TR0099887766' ) {
	$entity = '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment';
	$store  = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';

	if ( ! class_exists( $entity ) || ! class_exists( $store ) ) {
		return null;
	}

	$record = new $entity();
	$record->set_entity_type( WC_Order::class );
	$record->set_entity_id( (string) $order->get_id() );
	$record->set_status( 'fulfilled' );
	$record->update_meta_data( '_shipment_provider', 'dhl' );

	if ( '' !== $tracking ) {
		$record->update_meta_data( '_tracking_number', $tracking );
	}

	if ( '' !== $tracking_url ) {
		$record->update_meta_data( '_tracking_url', $tracking_url );
	}

	$items = array();

	foreach ( $order->get_items() as $item_id => $item ) {
		unset( $item );
		$items[] = array(
			'item_id' => (int) $item_id,
			'qty'     => 2,
		);
	}

	$record->set_items( $items );

	try {
		wc_get_container()->get( $store )->create( $record );
	} catch ( Throwable $error ) {
		unset( $error );
		return null;
	}

	$records[] = $record;

	return $record;
}

/**
 * Ürün görsellerinin halka açık HTTPS adresten geldiği üretim durumu.
 *
 * Ölçülen şey kapının kendisidir: aynı ürün, aynı kod yolu, farklı olan tek
 * şey adresin şeması ve sunucusu — üretimle yerel ortam arasındaki gerçek fark.
 */
function kuka_preview_public_media( bool $enable ): void {
	static $rewrite = null;

	if ( null === $rewrite ) {
		/*
		 * Yükleme dizininin taban adresi kullanılır, `home_url()` DEĞİL:
		 * İngilizce siparişte dil anahtarı `home_url()` değerini
		 * değiştirebiliyor ve o zaman arama dizgisi ek dosya adresiyle
		 * eşleşmiyor.
		 */
		$base = (string) ( wp_get_upload_dir()['baseurl'] ?? '' );

		$rewrite = static function ( $url ) use ( $base ) {
			if ( ! is_string( $url ) || '' === $base ) {
				return $url;
			}

			return str_replace( $base, KUKA_PREVIEW_PUBLIC_HOST . '/wp-content/uploads', $url );
		};
	}

	if ( $enable ) {
		add_filter( 'wp_get_attachment_url', $rewrite, 9999 );
		return;
	}

	remove_filter( 'wp_get_attachment_url', $rewrite, 9999 );
}

/**
 * Bir gönderim bildirimini render et.
 *
 * @param array $capture Yakalanan ileti (referans).
 */
function kuka_preview_render_fulfillment( array &$capture, WC_Order $order, $record ): void {
	$capture['mails']   = 0;
	$capture['subject'] = '';
	$capture['message'] = '';

	WC()->mailer();
	do_action( 'woocommerce_fulfillment_created_notification', (int) $order->get_id(), $record, wc_get_order( (int) $order->get_id() ) );
}

/**
 * Bir e-postanın tipini geçici olarak değiştirir; eski değeri döndürür.
 *
 * @param string $class WC_Email sınıfı.
 * @param string $type  Yeni tip.
 */
function kuka_preview_email_type( string $class, string $type ): string {
	foreach ( WC()->mailer()->get_emails() as $email ) {
		if ( $class === get_class( $email ) ) {
			$previous           = (string) $email->email_type;
			$email->email_type = $type;

			return $previous;
		}
	}

	return $type;
}

/**
 * Bir sipariş durumu e-postasını render et.
 *
 * @param array  $capture Yakalanan ileti (referans).
 * @param string $class   WC_Email sınıfı.
 */
function kuka_preview_render_order_email( array &$capture, WC_Order $order, string $class ): void {
	$capture['mails']   = 0;
	$capture['subject'] = '';
	$capture['message'] = '';

	$mailer = WC()->mailer();

	foreach ( $mailer->get_emails() as $email ) {
		if ( $class === get_class( $email ) ) {
			$email->trigger( (int) $order->get_id(), wc_get_order( (int) $order->get_id() ) );
			return;
		}
	}
}

/* -------------------------------------------------------------------- render */

$render_modes = array(
	'tr-fulfillment'       => array( false, 'fulfillment', false ),
	'en-fulfillment'       => array( true, 'fulfillment', false ),
	'tr-processing'        => array( false, 'processing', false ),
	'en-processing'        => array( true, 'processing', false ),
	'tr-fulfillment-https' => array( false, 'fulfillment', true ),
	'en-fulfillment-https' => array( true, 'fulfillment', true ),
	'tr-processing-https'  => array( false, 'processing', true ),
	'en-processing-https'  => array( true, 'processing', true ),
);

if ( isset( $render_modes[ $mode ] ) ) {
	list( $english, $kind, $public_media ) = $render_modes[ $mode ];

	kuka_preview_public_media( $public_media );

	$order = kuka_preview_order( $kuka_run_id, $kuka_order_ids, $english );

	if ( 'fulfillment' === $kind ) {
		$record = kuka_preview_fulfillment( $order, $kuka_fulfilled );

		if ( null === $record ) {
			fwrite( STDERR, 'PREVIEW=FAIL|reason:fulfillment_api_unavailable' . PHP_EOL );
			exit( 1 );
		}

		kuka_preview_render_fulfillment( $kuka_capture, $order, $record );
	} else {
		$order->set_status( 'processing' );
		$order->save();
		kuka_preview_render_order_email( $kuka_capture, $order, 'WC_Email_Customer_Processing_Order' );
	}

	if ( 1 !== (int) $kuka_capture['mails'] ) {
		fwrite( STDERR, 'PREVIEW=FAIL|reason:no_message_captured|mails:' . (int) $kuka_capture['mails'] . PHP_EOL );
		exit( 1 );
	}

	fwrite( STDERR, 'PREVIEW_SUBJECT=' . $kuka_capture['subject'] . PHP_EOL );
	echo $kuka_capture['message'];
	exit;
}

if ( 'measure' !== $mode ) {
	fwrite( STDERR, 'PREVIEW=FAIL|reason:unknown_mode:' . $mode . PHP_EOL );
	exit( 1 );
}

/* ------------------------------------------------------------------- ölçüm */

/**
 * HTML'deki "Hesabım" bağlantılarının sayısı.
 *
 * Hesap sayfasının kalıcı bağlantısı bu sitede `/hesabim/`; sabit bir
 * `my-account` dizgisi aramak Türkçe kurulumda hiçbir şey bulmaz ve ölçüm
 * sessizce yanlış geçer.
 *
 * @param string $html Render edilen HTML.
 */
function kuka_preview_account_links( string $html ): int {
	$path  = (string) ( wp_parse_url( (string) wc_get_page_permalink( 'myaccount' ), PHP_URL_PATH ) ?? '' );
	$count = 0;

	if ( '' !== $path && '/' !== $path ) {
		$count += (int) preg_match_all( '#href="[^"]*' . preg_quote( $path, '#' ) . '#i', $html );
	}

	foreach ( array( 'Siparişlerimi görüntüle', 'View my orders' ) as $label ) {
		$count += (int) preg_match_all( '/' . preg_quote( $label, '/' ) . '/u', $html );
	}

	return $count;
}

/**
 * Bir render sonucunun ölçülebilir özeti.
 *
 * @param string $html Render edilen HTML.
 * @return array<string, int|string>
 */
function kuka_preview_facts( string $html ): array {
	$forbidden = 0;

	foreach ( array( 'Woo!', 'öğe', 'yerine getiril', 'fulfillment' ) as $needle ) {
		$forbidden += (int) preg_match_all( '/' . preg_quote( $needle, '/' ) . '/iu', $html );
	}

	return array(
		'bytes'       => strlen( $html ),
		'images'      => (int) preg_match_all( '/<img\s/i', $html ),
		'gates'       => (int) preg_match_all( '/kuka-image-gate:/', $html ),
		'gate_code'   => 1 === preg_match( '/kuka-image-gate:([a-z_]+)/', $html, $found ) ? $found[1] : 'none',
		'forbidden'   => $forbidden,
		'my_account'  => kuka_preview_account_links( $html ),
		'tokenized'   => (int) preg_match_all( '/kuka_track=/', $html ),
		'buttons'     => (int) preg_match_all( '/class="kuka-button"/', $html ),
		'empty_href'  => (int) preg_match_all( '/href=(""|\'\')/', $html ),
		'wordmark'    => (int) preg_match_all( '/class="kuka-wordmark"/', $html ),
		'eyebrow'     => (int) preg_match_all( '/class="kuka-eyebrow"/', $html ),
		'banner'      => (int) preg_match_all( '/class="kuka-banner"/', $html ),
		'items'       => (int) preg_match_all( '/class="kuka-item-name"/', $html ),
		'fixed_600'   => (int) preg_match_all( '/width="600"/', $html ),
		'max_width'   => (int) preg_match_all( '/max-width:\s*780px/', $html ),
		'media_query' => (int) preg_match_all( '/@media only screen and \(max-width: 640px\)/', $html ),
	);
}

$facts = array();

/* 1-3. Dört ölçümlü render: TR/EN x processing/kargo. */
foreach ( array(
	'tr_processing'  => array( false, 'processing' ),
	'en_processing'  => array( true, 'processing' ),
	'tr_fulfillment' => array( false, 'fulfillment' ),
	'en_fulfillment' => array( true, 'fulfillment' ),
) as $label => $variant ) {
	list( $english, $kind ) = $variant;

	$order = kuka_preview_order( $kuka_run_id, $kuka_order_ids, $english );

	if ( 'fulfillment' === $kind ) {
		$record = kuka_preview_fulfillment( $order, $kuka_fulfilled );
		kuka_preview_render_fulfillment( $kuka_capture, $order, $record );
	} else {
		$order->set_status( 'processing' );
		$order->save();
		kuka_preview_render_order_email( $kuka_capture, $order, 'WC_Email_Customer_Processing_Order' );
	}

	$facts[ $label ]                 = kuka_preview_facts( (string) $kuka_capture['message'] );
	$facts[ $label ]['subject']      = (string) $kuka_capture['subject'];
	$facts[ $label ]['mails']        = (int) $kuka_capture['mails'];
	$facts[ $label ]['html']         = (string) $kuka_capture['message'];
}

/* 6. Ürün fotoğrafı halka açık HTTPS ortamında. */
kuka_preview_public_media( true );
$public_order  = kuka_preview_order( $kuka_run_id, $kuka_order_ids, false );
$public_record = kuka_preview_fulfillment( $public_order, $kuka_fulfilled );
kuka_preview_render_fulfillment( $kuka_capture, $public_order, $public_record );
$public_html  = (string) $kuka_capture['message'];
$public_facts = kuka_preview_facts( $public_html );
$public_alt   = 1 === preg_match( '/<img[^>]*alt="([^"]+)"[^>]*class="[^"]*"/', $public_html, $alt_found )
	? $alt_found[1]
	: ( 1 === preg_match( '/<img[^>]*alt="([^"]+)"/', $public_html, $alt_found ) ? $alt_found[1] : '' );
kuka_preview_public_media( false );

/* 8. Logo: panelde seçiliyse görünür, seçili değilse wordmark. */
$logo_attachment = kuka_preview_attachment_id();
$logo_probe      = static function ( $content ) use ( $logo_attachment ) {
	if ( is_array( $content ) && isset( $content['brand'] ) && is_array( $content['brand'] ) ) {
		$content['brand']['logo_id'] = $logo_attachment;
	}

	return $content;
};
add_filter( 'kuka_island_site_content', $logo_probe, 20 );
kuka_preview_public_media( true );
$logo_order  = kuka_preview_order( $kuka_run_id, $kuka_order_ids, false );
$logo_record = kuka_preview_fulfillment( $logo_order, $kuka_fulfilled );
kuka_preview_render_fulfillment( $kuka_capture, $logo_order, $logo_record );
$logo_html = (string) $kuka_capture['message'];
kuka_preview_public_media( false );
remove_filter( 'kuka_island_site_content', $logo_probe, 20 );

/* Aynı logo yerel adresle sunulduğunda: kırık resim yok, wordmark var. */
add_filter( 'kuka_island_site_content', $logo_probe, 20 );
$local_logo_order  = kuka_preview_order( $kuka_run_id, $kuka_order_ids, false );
$local_logo_record = kuka_preview_fulfillment( $local_logo_order, $kuka_fulfilled );
kuka_preview_render_fulfillment( $kuka_capture, $local_logo_order, $local_logo_record );
$local_logo_html = (string) $kuka_capture['message'];
remove_filter( 'kuka_island_site_content', $logo_probe, 20 );

/* 11. Takip adresi yokken düğme basılmaz. */
$bare_order  = kuka_preview_order( $kuka_run_id, $kuka_order_ids, false );
$bare_record = kuka_preview_fulfillment( $bare_order, $kuka_fulfilled, 'KI1TR0000000001', '' );
kuka_preview_render_fulfillment( $kuka_capture, $bare_order, $bare_record );
$bare_facts = kuka_preview_facts( (string) $kuka_capture['message'] );

/*
 * 10. Misafir Hesabım bağlantısını görmez. Üyelik AÇIK ve sipariş bir hesaba
 * bağlıysa bağlantı görünür; üyelik kapalıysa hesaba bağlı siparişte de
 * görünmez, çünkü o zaman müşteriyi olmayan bir sayfaya göndermiş olurduk.
 */
$membership_state = class_exists( 'Kuka_Island_Core_Membership' ) && Kuka_Island_Core_Membership::enabled() ? 'on' : 'off';

$member_order  = kuka_preview_order( $kuka_run_id, $kuka_order_ids, false, false );
$member_record = kuka_preview_fulfillment( $member_order, $kuka_fulfilled );
kuka_preview_render_fulfillment( $kuka_capture, $member_order, $member_record );
$member_facts = kuka_preview_facts( (string) $kuka_capture['message'] );

$membership_probe = static function ( $content ) {
	if ( is_array( $content ) && isset( $content['membership'] ) && is_array( $content['membership'] ) ) {
		$content['membership']['enabled'] = true;
	}

	return $content;
};
add_filter( 'kuka_island_site_content', $membership_probe, 20 );
$signed_order  = kuka_preview_order( $kuka_run_id, $kuka_order_ids, false, false );
$signed_record = kuka_preview_fulfillment( $signed_order, $kuka_fulfilled );
kuka_preview_render_fulfillment( $kuka_capture, $signed_order, $signed_record );
$signed_facts = kuka_preview_facts( (string) $kuka_capture['message'] );
remove_filter( 'kuka_island_site_content', $membership_probe, 20 );

/* Banner: alan boşken hiç render edilmez, doluyken tek yatay şerit. */
$banner_attachment = kuka_preview_attachment_id();
$banner_probe      = static function ( $content ) use ( $banner_attachment ) {
	if ( is_array( $content ) && isset( $content['brand'] ) && is_array( $content['brand'] ) ) {
		$content['brand']['email_banner_id'] = $banner_attachment;
	}

	return $content;
};
add_filter( 'kuka_island_site_content', $banner_probe, 20 );
kuka_preview_public_media( true );
$banner_order  = kuka_preview_order( $kuka_run_id, $kuka_order_ids, false );
$banner_record = kuka_preview_fulfillment( $banner_order, $kuka_fulfilled );
kuka_preview_render_fulfillment( $kuka_capture, $banner_order, $banner_record );
$banner_facts = kuka_preview_facts( (string) $kuka_capture['message'] );
kuka_preview_public_media( false );
remove_filter( 'kuka_island_site_content', $banner_probe, 20 );

/*
 * 8. Yönetici e-postasının işlevi bozulmaz.
 *
 * Ortak iskelet yönetici iletilerinde de kullanılır; ölçülen şey iletinin
 * hâlâ üretildiği, sipariş numarasını taşıdığı ve müşteriye ait etiketi
 * TAŞIMADIĞIdır — "SİPARİŞ GÜNCELLEMESİ" bir operatör iletisinde anlamsızdır.
 */
$admin_order = kuka_preview_order( $kuka_run_id, $kuka_order_ids, false );
$admin_order->set_status( 'processing' );
$admin_order->save();
$admin_probe = static function (): string {
	return 'kuka-preview@example.test';
};

/*
 * `WC_Email_New_Order::trigger()` bir kez gönderilmiş siparişte erken döner
 * (`_new_order_email_sent`). Fixture'ın durumu `processing`'e alınırken ileti
 * çoktan gönderilmiş sayılıyor; ölçüm için tekrar gönderime izin verilir.
 */
$admin_resend = static function (): bool {
	return true;
};
add_filter( 'woocommerce_email_recipient_new_order', $admin_probe, 20 );
add_filter( 'woocommerce_new_order_email_allows_resend', $admin_resend, 20 );
kuka_preview_render_order_email( $kuka_capture, $admin_order, 'WC_Email_New_Order' );
remove_filter( 'woocommerce_email_recipient_new_order', $admin_probe, 20 );
remove_filter( 'woocommerce_new_order_email_allows_resend', $admin_resend, 20 );
$admin_html  = (string) $kuka_capture['message'];
$admin_facts = kuka_preview_facts( $admin_html );

/*
 * Düz metin sürümü bozulmaz.
 *
 * E-posta tipi nesne kurulurken ayarlardan okunur ve nesneler süreç başına bir
 * kez kurulur; seçeneği sonradan filtrelemek etkisizdir. Bu yüzden tip nesne
 * üzerinde geçici olarak değiştirilir ve eski değeri geri yazılır.
 * Veritabanına hiçbir şey YAZILMAZ.
 */
$plain_previous = kuka_preview_email_type( 'WC_Email_Customer_Fulfillment_Created', 'plain' );
$plain_order    = kuka_preview_order( $kuka_run_id, $kuka_order_ids, false );
$plain_record   = kuka_preview_fulfillment( $plain_order, $kuka_fulfilled );
kuka_preview_render_fulfillment( $kuka_capture, $plain_order, $plain_record );
$plain_body = (string) $kuka_capture['message'];
kuka_preview_email_type( 'WC_Email_Customer_Fulfillment_Created', $plain_previous );

/* Kapının kendisi: her red gerekçesi ayrı ayrı sorulur. */
$gate_cases = array(
	'public'    => 'https://cdn.kukaisland.com/wp-content/uploads/a.jpg',
	'localhost' => 'http://localhost:8080/wp-content/uploads/a.jpg',
	'https_lan' => 'https://192.168.1.20/wp-content/uploads/a.jpg',
	'loopback'  => 'https://127.0.0.1/a.jpg',
	'test_tld'  => 'https://kukaisland.test/a.jpg',
	'vector'    => 'https://cdn.kukaisland.com/logo.svg',
	'empty'     => '',
);
$gate_results = array();

foreach ( $gate_cases as $case => $url ) {
	$gate_results[] = $case . ':' . ( class_exists( 'Kuka_Island_Core_Email_Design' )
		? Kuka_Island_Core_Email_Design::image_gate( $url )
		: 'no_class' );
}

/*
 * 12. Kimlik bilgisi sözleşmesi, iki ayrı soru olarak.
 *
 * SMTP PAROLASI hiçbir müşteri HTML'inde geçmez; bu kesin bir sıfırdır.
 * SMTP KULLANICI ADI bu kurulumda mağazanın YAYINLANMIŞ iletişim adresiyle
 * aynıdır (operatör aynı posta kutusunu hem kimlik doğrulama hem iletişim için
 * kullanıyor). O adresin `customer_processing_order` metninde geçmesi bir
 * sızıntı değil, mağazanın kendi iletişim cümlesidir. Sorulan doğru soru
 * şudur: bu modülün kendi şablonları o adresi basıyor mu? Cevap 0 olmalıdır.
 */
$password_hits = 0;
$username_hits = 0;
$username_note = 'undefined';

$module_htmls = array( $public_html, $logo_html, $local_logo_html );

foreach ( $facts as $label => $entry ) {
	if ( str_contains( $label, 'fulfillment' ) ) {
		$module_htmls[] = (string) $entry['html'];
	}
}

if ( defined( 'KUKA_SMTP_PASSWORD' ) && '' !== (string) constant( 'KUKA_SMTP_PASSWORD' ) ) {
	$password = (string) constant( 'KUKA_SMTP_PASSWORD' );

	foreach ( $facts as $entry ) {
		$password_hits += (int) str_contains( (string) $entry['html'], $password );
	}

	foreach ( $module_htmls as $html ) {
		$password_hits += (int) str_contains( $html, $password );
	}
}

if ( defined( 'KUKA_SMTP_USERNAME' ) && '' !== (string) constant( 'KUKA_SMTP_USERNAME' ) ) {
	$username      = (string) constant( 'KUKA_SMTP_USERNAME' );
	$brand_address = class_exists( 'Kuka_Island_Core_Site_Appearance' )
		? (string) ( Kuka_Island_Core_Site_Appearance::get()['brand']['email'] ?? '' )
		: '';
	$username_note = $username === $brand_address ? 'public_brand_address' : 'private_credential';

	foreach ( $module_htmls as $html ) {
		$username_hits += (int) str_contains( $html, $username );
	}
}

/* Şablon kopyalarının yukarı akış sürümü. */
$template_drift = array();

foreach ( array(
	'email-header.php'              => '10.7.0',
	'email-footer.php'              => '10.4.0',
	'email-fulfillment-details.php' => '10.7.0',
) as $template => $pinned ) {
	$vendor = WP_PLUGIN_DIR . '/woocommerce/templates/emails/' . $template;
	$source = is_readable( $vendor ) ? (string) file_get_contents( $vendor ) : '';
	$live   = 1 === preg_match( '/@version\s+([0-9.]+)/', $source, $version_found ) ? $version_found[1] : 'unreadable';

	$template_drift[] = $template . ':' . ( $live === $pinned ? 'pinned' : $live . '!=' . $pinned );
}

/* -------------------------------------------------------------- raporlama */

printf(
	'EMAIL_DESIGN_RENDERS=%s' . PHP_EOL,
	implode(
		'|',
		array_map(
			static function ( string $label ) use ( $facts ): string {
				return $label . ':' . ( 1 === (int) $facts[ $label ]['mails'] && (int) $facts[ $label ]['bytes'] > 4000 ? 'ok' : 'FAIL' );
			},
			array_keys( $facts )
		)
	)
);

printf(
	'EMAIL_DESIGN_LAYOUT=content_width:%d|max_width_declarations:%d|mobile_query:%s|fixed_600_attributes:%d|shared_header:%d/4|shared_footer:%d/4' . PHP_EOL,
	class_exists( 'Kuka_Island_Core_Email_Design' ) ? (int) Kuka_Island_Core_Email_Design::CONTENT_WIDTH : 0,
	(int) $facts['tr_fulfillment']['max_width'],
	(int) $facts['tr_fulfillment']['media_query'] > 0 ? 'present' : 'MISSING',
	array_sum( array_column( $facts, 'fixed_600' ) ),
	count( array_filter( $facts, static fn( array $entry ): bool => (int) $entry['wordmark'] > 0 ) ),
	count( array_filter( $facts, static fn( array $entry ): bool => str_contains( (string) $entry['html'], 'id="template_footer"' ) ) )
);

printf(
	'EMAIL_DESIGN_COPY=tr_subject:%s|tr_eyebrow:%d|tr_intro_name:%s|en_subject:%s|en_eyebrow:%d|en_intro_name:%s|forbidden_words:%d' . PHP_EOL,
	(string) $facts['tr_fulfillment']['subject'],
	(int) $facts['tr_fulfillment']['eyebrow'],
	str_contains( (string) $facts['tr_fulfillment']['html'], 'Merhaba Ayşe, siparişiniz hazırlanarak kargo firmasına teslim edildi.' ) ? 'yes' : 'NO',
	(string) $facts['en_fulfillment']['subject'],
	(int) $facts['en_fulfillment']['eyebrow'],
	str_contains( (string) $facts['en_fulfillment']['html'], 'Hello Emma, your order has been prepared and handed over to the carrier.' ) ? 'yes' : 'NO',
	array_sum( array_column( $facts, 'forbidden' ) ) + (int) $public_facts['forbidden'] + (int) $bare_facts['forbidden'] + (int) $member_facts['forbidden'] + (int) $banner_facts['forbidden']
);

printf(
	'EMAIL_DESIGN_IMAGES=localhost_img:%d|localhost_gate:%s|public_https_img:%d|public_https_gate:%d|alt_from_product:%s|gate:%s' . PHP_EOL,
	array_sum( array_column( $facts, 'images' ) ),
	(string) $facts['tr_fulfillment']['gate_code'],
	(int) $public_facts['images'],
	(int) $public_facts['gates'],
	'' !== $public_alt && ! str_contains( $public_alt, 'http' ) ? 'yes' : 'NO',
	implode( ',', $gate_results )
);

printf(
	'EMAIL_DESIGN_LOGO=configured_logo_id:%d|no_logo_wordmark:%d|public_logo_img:%d|public_logo_wordmark:%d|local_logo_img:%d|local_logo_wordmark:%d' . PHP_EOL,
	class_exists( 'Kuka_Island_Core_Site_Appearance' ) ? (int) ( Kuka_Island_Core_Site_Appearance::get()['brand']['logo_id'] ?? 0 ) : -1,
	(int) $facts['tr_fulfillment']['wordmark'],
	(int) preg_match_all( '/<img\s/', $logo_html ),
	(int) preg_match_all( '/class="kuka-wordmark"/', $logo_html ),
	(int) preg_match_all( '/<img\s/', $local_logo_html ),
	(int) preg_match_all( '/class="kuka-wordmark"/', $local_logo_html )
);

printf(
	'EMAIL_DESIGN_ACCESS=membership:%s|guest_my_account:%d|guest_tokenized:%d|customer_my_account_membership_off:%d|customer_my_account_membership_on:%d|tracking_button:%d|no_url_button:%d|empty_href:%d' . PHP_EOL,
	$membership_state,
	(int) $facts['tr_fulfillment']['my_account'],
	(int) $facts['tr_fulfillment']['tokenized'],
	(int) $member_facts['my_account'],
	(int) $signed_facts['my_account'],
	(int) $facts['tr_fulfillment']['buttons'],
	(int) $bare_facts['buttons'],
	array_sum( array_column( $facts, 'empty_href' ) ) + (int) $bare_facts['empty_href']
);

printf(
	'EMAIL_DESIGN_BANNER=unconfigured:%d|configured:%d|items_still_shown:%d' . PHP_EOL,
	(int) $facts['tr_fulfillment']['banner'],
	(int) $banner_facts['banner'],
	(int) $banner_facts['items']
);

printf(
	'EMAIL_DESIGN_SECRETS=password_in_customer_html:%d|username_in_module_templates:%d|username_is:%s' . PHP_EOL,
	$password_hits,
	$username_hits,
	$username_note
);

printf(
	'EMAIL_DESIGN_ADMIN=rendered:%s|order_number:%s|customer_eyebrow:%d|shared_skeleton:%s' . PHP_EOL,
	(int) $admin_facts['bytes'] > 4000 ? 'yes' : 'NO',
	str_contains( $admin_html, (string) $admin_order->get_order_number() ) ? 'yes' : 'NO',
	(int) $admin_facts['eyebrow'],
	str_contains( $admin_html, 'id="template_container"' ) ? 'yes' : 'NO'
);

printf(
	'EMAIL_DESIGN_PLAIN_TEXT=html_tags:%d|tracking_number:%s|bytes:%s' . PHP_EOL,
	(int) preg_match_all( '/<(table|div|img|html)\b/i', $plain_body ),
	str_contains( $plain_body, 'KI1TR0099887766' ) ? 'present' : 'MISSING',
	strlen( $plain_body ) > 80 ? 'nonempty' : 'EMPTY'
);

printf(
	'EMAIL_DESIGN_TEMPLATE_DRIFT=%s' . PHP_EOL,
	implode( '|', $template_drift )
);
