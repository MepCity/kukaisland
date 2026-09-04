<?php
/**
 * WooCommerce'in manuel "müşteriye bildir" yolu, bu modülün eklentisi
 * DEVRE DIŞIYKEN ölçülür.
 *
 * Otomatik bildirim eklendikten sonra sorulması gereken soru şudur: operatör
 * eklentiyi kapattığında WooCommerce'in kendi çekmecesindeki işaret hâlâ
 * müşteriye e-posta gönderiyor mu? Yaşam döngüsü testinin `core_works` alanı
 * bunu ölçmez, yalnız iki Core sınıfının hâlâ tanımlı olduğunu söyler.
 *
 * Bu betik taze bir WordPress sürecinde çalışır ve önce eklentinin gerçekten
 * yüklü olmadığını kanıtlar; sonra elle kurulmuş bir fulfillment kaydı için
 * WooCommerce'in bildirim eylemini tetikler. Taşıyıcı `pre_wp_mail` üzerinde
 * -2000 önceliğiyle kesilir: hiçbir mesaj dışarı çıkmaz, yalnız sayılır.
 * Fixture sipariş sahiplik damgasıyla oluşturulur ve kapanışta notlarıyla
 * birlikte silinir; ölümcül bir hatada da silinir.
 */

require '/var/www/html/wp-load.php';
require_once __DIR__ . '/lib-iyzico-test-ownership.php';

$plugin_file    = 'kuka-island-shipping-automation/kuka-island-shipping-automation.php';
$plugin_active  = in_array( $plugin_file, (array) get_option( 'active_plugins', array() ), true );
$module_loaded  = class_exists( 'Kuka_Island_Shipping_Plugin', false )
	|| class_exists( 'Kuka_Island_Shipping_Fulfillment_Writer', false )
	|| class_exists( 'Kuka_Island_Shipping_Notification', false );

$recipient = 'passive-manual-route@example.test';
$mails     = 0;
$subjects  = array();

/*
 * -2000: Core'un `Email_Delivery::send_safely()` sarmalayıcısı `pre_wp_mail`e
 * -1000'de bağlanır ve içeride `wp_mail()`'i yeniden çağırır, yani tek mantıksal
 * mesaj bu kancaya iki kez düşer. Kayıt daha erken karar verirse sarmalayıcı
 * karara varılmış değeri görür ve aynı mesajı ikinci kez saymayız.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $args ) use ( $recipient, &$mails, &$subjects ) {
		$to = (array) ( $args['to'] ?? array() );

		foreach ( $to as $address ) {
			if ( strtolower( (string) $address ) !== $recipient ) {
				continue;
			}

			++$mails;
			$subjects[] = (string) ( $args['subject'] ?? '' );

			return true;
		}

		return $short_circuit;
	},
	-2000,
	2
);

$run_id           = wp_generate_uuid4();
$created_order_id = 0;
$created_note_ids = array();
$cleanup_refusals = array();
$cleanup_state    = 'idle';
$fulfillment      = null;
$fulfillment_gone = 'not_created';

$store_class = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';
$entity_class = '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment';

$cleanup = static function () use (
	&$created_order_id,
	&$created_note_ids,
	&$cleanup_state,
	&$cleanup_refusals,
	&$fulfillment,
	&$fulfillment_gone,
	$run_id,
	$store_class
): void {
	$enter = kuka_iyzico_cleanup_enter( $cleanup_state );
	$cleanup_state = (string) $enter['state'];

	if ( ! $enter['proceed'] ) {
		if ( '' !== (string) $enter['refusal'] ) {
			$cleanup_refusals[] = (string) $enter['refusal'];
		}
		return;
	}

	if ( null !== $fulfillment ) {
		try {
			wc_get_container()->get( $store_class )->delete( $fulfillment );
			$fulfillment_gone = 'deleted';
		} catch ( Throwable $error ) {
			unset( $error );
			$fulfillment_gone   = 'LEFT_BEHIND';
			$cleanup_refusals[] = 'fulfillment:delete_refused';
		}
	}

	if ( 0 !== $created_order_id ) {
		$reason = '';

		if ( ! kuka_iyzico_fixture_is_owned( $created_order_id, $run_id, array( $created_order_id ), $reason ) ) {
			$cleanup_refusals[] = 'order:' . $reason;
		} else {
			// Notlar rapor için sayılır; silme işini notları da kaldıran
			// sahiplik yardımcısı yapar.
			foreach ( wc_get_order_notes( array( 'order_id' => $created_order_id, 'limit' => 500 ) ) as $note ) {
				$created_note_ids[] = (int) $note->id;
			}

			kuka_iyzico_delete_owned_order( $created_order_id );
		}
	}

	$cleanup_state = kuka_iyzico_cleanup_finish( $cleanup_refusals );
};

register_shutdown_function(
	static function () use ( $cleanup, &$cleanup_state, &$cleanup_refusals, &$created_order_id, &$created_note_ids, &$fulfillment_gone ): void {
		$cleanup();
		echo 'MANUAL_ROUTE_FIXTURE_ORDER=' . $created_order_id . PHP_EOL;
		echo 'MANUAL_ROUTE_FIXTURE_NOTES=' . ( $created_note_ids ? implode( ',', $created_note_ids ) : 'none' ) . PHP_EOL;
		echo 'MANUAL_ROUTE_FULFILLMENT=' . $fulfillment_gone . PHP_EOL;
		echo 'MANUAL_ROUTE_CLEANUP_STATE=' . $cleanup_state . PHP_EOL;

		if ( 'succeeded' !== $cleanup_state ) {
			echo 'MANUAL_ROUTE_CLEANUP_REFUSED=' . ( $cleanup_refusals ? implode( ' | ', $cleanup_refusals ) : 'state:' . $cleanup_state ) . PHP_EOL;
			exit( 1 );
		}
	}
);

$product_id = 0;

foreach ( (array) wc_get_products( array( 'status' => 'publish', 'limit' => 25, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) ) as $candidate_id ) {
	$product = wc_get_product( (int) $candidate_id );

	if ( ! $product instanceof WC_Product || ! $product->needs_shipping() ) {
		continue;
	}

	if ( ! $product->is_type( 'variable' ) ) {
		$product_id = (int) $candidate_id;
		break;
	}

	foreach ( (array) $product->get_children() as $child_id ) {
		$child = wc_get_product( (int) $child_id );

		if ( $child instanceof WC_Product && $child->needs_shipping() ) {
			$product_id = (int) $child_id;
			break 2;
		}
	}
}

$order = wc_create_order();
$order->set_billing_email( $recipient );
$order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
$order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );

if ( 0 !== $product_id ) {
	$order->add_product( wc_get_product( $product_id ), 1 );
}

$order->save();
$created_order_id = (int) $order->get_id();

$api_ready = class_exists( $entity_class ) && class_exists( $store_class );

if ( $api_ready ) {
	$fulfillment = new $entity_class();
	$fulfillment->set_entity_type( WC_Order::class );
	$fulfillment->set_entity_id( (string) $created_order_id );
	$fulfillment->set_status( 'fulfilled' );
	$fulfillment->update_meta_data( '_tracking_number', 'PASSIVE-MANUAL-1' );
	$fulfillment->update_meta_data( '_shipment_provider', 'aras-kargo' );

	$items = array();

	foreach ( $order->get_items() as $item_id => $item ) {
		unset( $item );
		$items[] = array(
			'item_id' => (int) $item_id,
			'qty'     => 1,
		);
	}

	$fulfillment->set_items( $items );

	try {
		wc_get_container()->get( $store_class )->create( $fulfillment );
	} catch ( Throwable $error ) {
		unset( $error );
		$api_ready   = false;
		$fulfillment = null;
	}
}

// notify = false, yani eylemin hiç oluşmaması: hiçbir mesaj gitmez.
$quiet_mails = $mails;

// notify = true: operatörün yolu, bu eylem ve yalnız bu eylem.
if ( $api_ready ) {
	WC()->mailer();
	do_action( 'woocommerce_fulfillment_created_notification', $created_order_id, $fulfillment, wc_get_order( $created_order_id ) );
}

$notified_mails = $mails;

printf(
	'SHIPPING_MANUAL_ROUTE_WITH_PLUGIN_INACTIVE=%s|measured:fresh_wp_process_with_plugin_inactive|plugin_active:%s|module_loaded:%s|api:%s|notify_false_mails:%d|notify_true_mails:%d|subject:%s' . PHP_EOL,
	( ! $plugin_active && ! $module_loaded && $api_ready && 0 === $quiet_mails && 1 === $notified_mails && '' !== (string) ( $subjects[0] ?? '' ) ) ? 'PASS' : 'FAIL',
	$plugin_active ? 'YES' : 'no',
	$module_loaded ? 'YES' : 'no',
	$api_ready ? 'available' : 'UNAVAILABLE',
	$quiet_mails,
	$notified_mails,
	'' !== (string) ( $subjects[0] ?? '' ) ? 'present' : 'MISSING'
);
