<?php
/**
 * Kuka Island kargo bilgisi bloğu.
 *
 * NEDEN KOPYALANDI: WooCommerce'in kendi bloğu müşteriye şunları basar ve
 * hiçbirinin filtrelenebilir bir kancası yoktur:
 *
 *   - "Fulfillment summary" başlığı ve "yerine getirildi" dilinden metinler,
 *   - ham taşıyıcı anahtarı (`dhl`) — müşteriye gösterilecek ad değil,
 *   - takip adresi boşken bile `<a href="">` — çalışmayan bir bağlantı,
 *   - misafir siparişinde bile "Hesabım > Siparişler" bağlantısı,
 *   - 48 pikselik ürün görseli.
 *
 * Görsel adresi tek bir kapıdan geçer. Kapı adresin BİÇİMİNE bakar — şema
 * https mi, sunucu açıkça yerel/özel mi, uzantı SVG mi — DNS ya da HTTP
 * denemesi YAPMAZ. Yerel ortamda ürün görselinin adresi
 * `http://localhost:8080/...` olur ve Gmail bu adrese erişemez; o durumda kırık
 * bir resim çerçevesi basmak yerine temiz tipografik satır kullanılır ve HTML'e
 * yalnız bir ölçüm kodu düşülür.
 *
 * Kaynak: WooCommerce templates/emails/email-fulfillment-details.php sürüm 10.7.0.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

if ( ! $order instanceof WC_Order ) {
	return;
}

$design_ready = class_exists( 'Kuka_Island_Core_Email_Design' );
$english      = $design_ready ? Kuka_Island_Core_Email_Design::is_english( $email ) : false;
$thumb        = $design_ready ? (int) Kuka_Island_Core_Email_Design::ITEM_IMAGE : 104;

$kuka_text = static function ( string $tr, string $en ) use ( $english ): string {
	return $english ? $en : $tr;
};

if ( null === $fulfillment->get_date_deleted() ) {
	$tracking_number = trim( (string) $fulfillment->get_meta( '_tracking_number', true ) );
	$tracking_url    = trim( (string) $fulfillment->get_meta( '_tracking_url', true ) );
	$provider_key    = trim( (string) $fulfillment->get_meta( '_shipment_provider', true ) );
	$carrier         = $design_ready ? Kuka_Island_Core_Email_Design::carrier_label( $provider_key ) : $provider_key;

	/*
	 * Tahmini teslim tarihi yalnız VARSA gösterilir. Kargo firmasından gelmeyen
	 * bir tarihi kendimiz uydurmayız; alan boşsa satır hiç çıkmaz.
	 */
	$estimate = '';

	foreach ( array( '_estimated_delivery_date', '_kuka_shipping_estimated_delivery' ) as $estimate_key ) {
		$candidate = trim( (string) $fulfillment->get_meta( $estimate_key, true ) );

		if ( '' !== $candidate ) {
			$estimate = $candidate;
			break;
		}
	}

	if ( '' !== $estimate ) {
		$estimate_time = strtotime( $estimate );
		$estimate      = false === $estimate_time ? $estimate : date_i18n( wc_date_format(), $estimate_time );
	}

	// Çalışmayan düğme basılmaz: adres yoksa ya da http(s) değilse düğme yok.
	$tracking_href = '';

	if ( '' !== $tracking_url ) {
		$scheme = strtolower( (string) ( wp_parse_url( $tracking_url, PHP_URL_SCHEME ) ?? '' ) );

		if ( 'https' === $scheme || 'http' === $scheme ) {
			$tracking_href = $tracking_url;
		}
	}

	if ( '' !== $carrier || '' !== $tracking_number || '' !== $estimate ) {
		echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation" class="kuka-card"><tr><td class="kuka-card-cell">';

		$rows = array();

		if ( '' !== $carrier ) {
			$rows[] = array( $kuka_text( 'Kargo firması', 'Carrier' ), esc_html( $carrier ), false );
		}

		if ( '' !== $tracking_number ) {
			$rows[] = array( $kuka_text( 'Takip numarası', 'Tracking number' ), '<span class="kuka-code">' . esc_html( $tracking_number ) . '</span>', false );
		}

		if ( '' !== $estimate ) {
			$rows[] = array( $kuka_text( 'Tahmini teslim', 'Estimated delivery' ), esc_html( $estimate ), false );
		}

		$last = count( $rows ) - 1;

		foreach ( $rows as $index => $row ) {
			printf(
				'<p class="kuka-card-label">%1$s</p><p class="kuka-card-value%2$s">%3$s</p>',
				esc_html( (string) $row[0] ),
				$index === $last ? ' kuka-card-value-last' : '',
				wp_kses_post( (string) $row[1] )
			);
		}

		echo '</td></tr></table>';
	} else {
		printf(
			'<p class="kuka-note">%s</p>',
			esc_html( $kuka_text( 'Takip bilgisi eklendiğinde size tekrar yazacağız.', 'We will write again as soon as tracking information is available.' ) )
		);
	}

	if ( '' !== $tracking_href ) {
		printf(
			'<table border="0" cellpadding="0" cellspacing="0" role="presentation" class="kuka-button"><tr><td><a href="%1$s" target="_blank">%2$s</a></td></tr></table>',
			esc_url( $tracking_href ),
			esc_html( $kuka_text( 'Kargonu takip et', 'Track your parcel' ) )
		);
	}
}

/** This action is documented in WooCommerce templates/emails/email-fulfillment-details.php. */
do_action( 'woocommerce_email_before_fulfillment_table', $order, $fulfillment, $sent_to_admin, $plain_text, $email );

/* ------------------------------------------------------------------ ürünler */

$kuka_rows = '';

foreach ( (array) $fulfillment->get_items() as $fulfillment_item ) {
	$item = $order->get_item( (int) ( $fulfillment_item['item_id'] ?? 0 ) );

	if ( ! $item instanceof WC_Order_Item_Product ) {
		continue;
	}

	/** This filter is documented in WooCommerce templates/emails/email-fulfillment-items.php. */
	if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
		continue;
	}

	$quantity = max( 1, (int) ( $fulfillment_item['qty'] ?? 1 ) );
	$product  = $item->get_product();

	/*
	 * Ad WooCommerce'in kendi filtresinden geçirilir: İngilizce siparişte ürün
	 * adının İngilizcesi Core'un `english_order_item_name` filtresinden gelir
	 * ve bu satır onu atlarsa müşteri İngilizce bir e-postada Türkçe ürün adı
	 * görür.
	 */
	/** This filter is documented in WooCommerce templates/emails/email-order-items.php. */
	$name = wp_strip_all_tags( (string) apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) );

	// Varyasyon görseli yoksa ana ürünün görseline dönülür.
	$image_id = 0;

	if ( $product instanceof WC_Product ) {
		$image_id = (int) $product->get_image_id();

		if ( 1 > $image_id && $product->get_parent_id() > 0 ) {
			$parent = wc_get_product( $product->get_parent_id() );

			if ( $parent instanceof WC_Product ) {
				$image_id = (int) $parent->get_image_id();
			}
		}
	}

	$image_url  = '';
	$image_gate = 'empty';

	if ( $design_ready ) {
		$image_gate = $image_id > 0
			? Kuka_Island_Core_Email_Design::image_gate( (string) wp_get_attachment_image_url( $image_id, 'full' ) )
			: 'empty';
		$image_url  = Kuka_Island_Core_Email_Design::public_image_url( $image_id );
	}

	$variation = '';

	if ( $product instanceof WC_Product && $product->is_type( 'variation' ) ) {
		$variation = wc_get_formatted_variation( $product, true, false );
	}

	if ( '' === $variation ) {
		$meta      = $item->get_formatted_meta_data( '', true );
		$fragments = array();

		foreach ( $meta as $entry ) {
			$fragments[] = wp_strip_all_tags( (string) $entry->display_key ) . ': ' . wp_strip_all_tags( (string) $entry->display_value );
		}

		$variation = implode( ' · ', $fragments );
	}

	/*
	 * WooCommerce bir varyasyonun sipariş satırı adını "Ürün - Renk, Beden"
	 * biçiminde yazar. Varyasyon ikinci satırda ayrıca gösterildiği için aynı
	 * bilgi iki kez çıkmasın diye ekten arındırılır; ek yoksa ad olduğu gibi
	 * kalır.
	 */
	if ( '' !== trim( $variation ) ) {
		$suffix = ' - ' . trim( wp_strip_all_tags( $variation ) );

		if ( str_ends_with( $name, $suffix ) ) {
			$name = substr( $name, 0, -strlen( $suffix ) );
		}
	}

	$unit  = (float) $item->get_quantity() > 0 ? (float) $item->get_subtotal() / (float) $item->get_quantity() : (float) $item->get_subtotal();
	$price = wc_price( $unit * $quantity, array( 'currency' => $order->get_currency() ) );

	$kuka_rows .= '<tr>';

	if ( '' !== $image_url ) {
		$kuka_rows .= sprintf(
			'<td class="kuka-item-thumb"><img src="%1$s" alt="%2$s" width="%3$d" /></td>',
			esc_url( $image_url ),
			esc_attr( $name ),
			$thumb
		);
	} else {
		/*
		 * Kırık resim çerçevesi basmak yerine görsel hücresi hiç açılmaz.
		 * Yorumdaki kod ölçülebilir olsun diye sabit ve adres TAŞIMAZ.
		 */
		$kuka_rows .= '<!-- kuka-image-gate:' . esc_html( $image_gate ) . ' -->';
	}

	$kuka_rows .= '<td>';
	$kuka_rows .= '<p class="kuka-item-name">' . esc_html( $name ) . '</p>';

	$details = array();

	if ( '' !== trim( $variation ) ) {
		$details[] = trim( wp_strip_all_tags( $variation ) );
	}

	$details[] = sprintf( '%s: %d', $kuka_text( 'Adet', 'Quantity' ), $quantity );
	$kuka_rows .= '<p class="kuka-item-meta">' . esc_html( implode( ' · ', $details ) ) . '</p>';
	$kuka_rows .= '</td>';
	$kuka_rows .= '<td class="kuka-item-price">' . wp_kses_post( $price ) . '</td>';
	$kuka_rows .= '</tr>';
}

if ( '' !== $kuka_rows ) {
	printf(
		'<p class="kuka-section-title">%s</p>',
		esc_html( $kuka_text( 'Paketinizdeki ürünler', 'Inside your parcel' ) )
	);
	echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation" class="kuka-items"><tbody>'
		. wp_kses_post( $kuka_rows )
		. '</tbody></table>';
}

/* ------------------------------------------------------- sipariş ve erişim */

/*
 * Tarih biçimi Core'un `woocommerce_date_format` filtresinden gelir: Türkçe
 * bir e-postada gün önce yazılır, İngilizcede sitenin biçimi korunur. Böylece
 * bu satır ile WooCommerce'in kendi sipariş tablosu aynı biçimi kullanır.
 */
printf(
	'<p class="kuka-note">%1$s &middot; %2$s</p>',
	esc_html( sprintf( $kuka_text( 'Sipariş #%s', 'Order #%s' ), $order->get_order_number() ) ),
	esc_html( (string) wc_format_datetime( $order->get_date_created() ) )
);

if ( ! $sent_to_admin ) {
	$membership_on = class_exists( 'Kuka_Island_Core_Membership' ) && Kuka_Island_Core_Membership::enabled();

	/*
	 * Misafir siparişinde "Hesabım > Siparişler" bağlantısı GÖSTERİLMEZ:
	 * müşterinin hesabı yoktur, bağlantı onu giriş ekranına düşürür ve üyelik
	 * kapalıysa hiç var olmayan bir sayfaya yönlendirir. Onun yerine sipariş
	 * takip sayfasının süreli, imzalı bağlantısı kullanılır.
	 */
	if ( $membership_on && $order->get_customer_id() > 0 ) {
		printf(
			'<p class="kuka-note"><a href="%1$s" target="_blank">%2$s</a></p>',
			esc_url( wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) ),
			esc_html( $kuka_text( 'Siparişlerimi görüntüle', 'View my orders' ) )
		);
	} elseif ( class_exists( 'Kuka_Island_Core_Membership' ) ) {
		printf(
			'<p class="kuka-note"><a href="%1$s" target="_blank">%2$s</a></p>',
			esc_url( Kuka_Island_Core_Membership::tracking_link( $order ) ),
			esc_html( $kuka_text( 'Siparişimi takip et', 'Track my order' ) )
		);
	}
}

/** This action is documented in WooCommerce templates/emails/email-fulfillment-details.php. */
do_action( 'woocommerce_email_after_fulfillment_table', $order, $fulfillment, $sent_to_admin, $plain_text, $email );
