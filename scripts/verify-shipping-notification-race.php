<?php
/**
 * İki gerçek süreç aynı siparişin ilk `fulfilled` geçişine aynı anda girerse.
 *
 * Mevcut tekrar-poll ölçümleri SIRALIDIR: biri bitmeden öteki başlamaz, ve o
 * sırada durum makinesi zaten `sent` yazmış olur. Bu yüzden o ölçümler yarışı
 * kanıtlamaz. Üretimde iki yol aynı siparişe aynı anda girebilir: Action
 * Scheduler'ın zamanlanmış durum sorgusu ve operatörün "durumu sorgula"
 * basışı. İkisi de isteğin başında siparişi belleğe alır; ikisi de
 * `fulfilled` olmayan bir kayıt ve boş bir bildirim durumu görür.
 *
 * Bu betik o anı iki ayrı PHP SÜRECİYLE, dolayısıyla iki ayrı MySQL
 * oturumuyla üretir. `GET_LOCK` bağlantı başına çalıştığı için tek süreç
 * içinden kurgulanan bir yarış kilidi kanıtlamaz; iki süreç kanıtlar.
 *
 * Kullanım (konteyner içinde):
 *   php /project-scripts/verify-shipping-notification-race.php measure
 *   php /project-scripts/verify-shipping-notification-race.php worker <order_id> <reference> <start_micro> <slot>
 *
 * Taşıyıcıya, EDM'ye ve SMTP'ye hiçbir çağrı yapılmaz: durum kodu doğrudan
 * `Fulfillment_Writer::sync_status()` içine verilir ve posta taşıyıcısı
 * `pre_wp_mail` üzerinde kesilir.
 */

require '/var/www/html/wp-load.php';
require_once __DIR__ . '/lib-iyzico-test-ownership.php';

if ( ! class_exists( 'Kuka_Island_Shipping_Fulfillment_Writer' ) ) {
	require_once __DIR__ . '/lib-shipping-module-loader.php';
	kuka_shipping_load_module();
}

const KUKA_RACE_RECIPIENT = 'kuka-race@example.test';
const KUKA_RACE_REFERENCE = 'KI1RACE0000001';

$mode = (string) ( $argv[1] ?? 'measure' );

/* ------------------------------------------------------------------ ortak */

/** İlk kargolanabilir ürün ya da varyasyonu. */
function kuka_race_product_id(): int {
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

/** Bir siparişi süreç içi önbelleklerden düşür. */
function kuka_race_forget_order( int $order_id ): void {
	if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
		try {
			$cache = wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class );

			if ( is_object( $cache ) && method_exists( $cache, 'remove' ) ) {
				$cache->remove( $order_id );
			}
		} catch ( Throwable $unavailable ) {
			unset( $unavailable );
		}
	}

	wp_cache_delete( $order_id, 'orders' );
	wp_cache_delete( $order_id, 'order-items' );
	wp_cache_delete( $order_id, 'posts' );
	wp_cache_delete( $order_id, 'post_meta' );
}

/** Bu modülün KENDİ kaydı: referans metası sahipliği belirler. */
function kuka_race_own_fulfillment( WC_Order $order ) {
	$entity = '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment';
	$store  = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';

	if ( ! class_exists( $entity ) || ! class_exists( $store ) ) {
		return null;
	}

	$record = new $entity();
	$record->set_entity_type( WC_Order::class );
	$record->set_entity_id( (string) $order->get_id() );
	$record->set_status( 'unfulfilled' );
	$record->update_meta_data( Kuka_Island_Shipping_Fulfillment_Writer::META_REFERENCE, KUKA_RACE_REFERENCE );
	$record->update_meta_data( '_shipment_provider', 'dhl' );
	$record->update_meta_data( '_tracking_number', 'RACE-TRACK-1' );

	$items = array();

	foreach ( $order->get_items() as $item_id => $item ) {
		unset( $item );
		$items[] = array(
			'item_id' => (int) $item_id,
			'qty'     => 1,
		);
	}

	$record->set_items( $items );

	try {
		wc_get_container()->get( $store )->create( $record );
	} catch ( Throwable $error ) {
		unset( $error );

		return null;
	}

	return $record;
}

/* ------------------------------------------------------------------ worker */

if ( 'worker' === $mode ) {
	$order_id  = (int) ( $argv[2] ?? 0 );
	$reference = (string) ( $argv[3] ?? '' );
	$start     = (float) ( $argv[4] ?? 0 );
	$slot      = (string) ( $argv[5] ?? '?' );

	$mails  = 0;
	$events = 0;
	$http   = 0;

	/*
	 * Dışa açılan hiçbir çağrı olmamalı: bu ölçüm durum kodunu doğrudan
	 * `sync_status()` içine verir, taşıyıcı istemcisi hiç kurulmaz ve posta
	 * taşıyıcısı kesilir. Kanca fail-closed: bir şey yine de dışarı çıkmaya
	 * çalışırsa hem engellenir hem sayılır.
	 */
	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) use ( &$http ) {
			unset( $preempt, $args, $url );
			++$http;

			return new WP_Error( 'kuka_race_outbound_blocked', 'Blocked by the concurrency measurement.' );
		},
		PHP_INT_MIN,
		3
	);

	/*
	 * -2000: Core'un `Email_Delivery::send_safely()` sarmalayıcısı
	 * `pre_wp_mail`e -1000'de bağlanır ve içeride `wp_mail()`'i yeniden
	 * çağırır; tek mantıksal ileti bu kancaya iki kez düşer. Kayıt daha erken
	 * karar verirse aynı ileti ikinci kez sayılmaz. Dışarı hiçbir mesaj
	 * çıkmaz: filtre karar verilmiş bir değer döndürür.
	 */
	add_filter(
		'pre_wp_mail',
		static function ( $short_circuit, $args ) use ( &$mails ) {
			$args = is_array( $args ) ? $args : array();

			foreach ( (array) ( $args['to'] ?? array() ) as $address ) {
				if ( strtolower( (string) $address ) !== KUKA_RACE_RECIPIENT ) {
					continue;
				}

				++$mails;

				/*
				 * `wp_mail()` bir kısa devre değeri gördüğünde HEMEN döner ve
				 * `wp_mail_succeeded` eylemini kendisi ateşlemez. Kabul eden
				 * bir taşıyıcıyı taklit eden kayıt o sinyali kendisi
				 * vermelidir; vermezse durum makinesi sonucu bilinmez sayar ve
				 * `reconciliation_required` yazar. Bu, gerçek SMTP'nin kabul
				 * ettiği durumun ölçümdeki karşılığıdır.
				 */
				do_action( 'wp_mail_succeeded', $args );

				return true;
			}

			return $short_circuit;
		},
		-2000,
		2
	);

	add_action(
		'woocommerce_fulfillment_created_notification',
		static function () use ( &$events ): void {
			++$events;
		},
		1
	);

	/*
	 * GERÇEK İSTEK SIRASI. Bir istek siparişi başında belleğe alır; kararı
	 * sonra verir. Bu yüzden sipariş nesnesi BARİYERDEN ÖNCE yüklenir ve
	 * bariyerden sonra o nesneyle karar verilir — yarışın kendisi budur.
	 */
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		printf( 'slot:%s|error:order_unreadable' . PHP_EOL, $slot );
		exit( 1 );
	}

	$before        = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $order, $reference );
	$was_unfulfil  = $before instanceof stdClass || ( is_object( $before ) && method_exists( $before, 'get_is_fulfilled' ) )
		? ! $before->get_is_fulfilled()
		: false;
	$state_before  = Kuka_Island_Shipping_Notification::state( $order );

	// Bariyer: iki süreç aynı mikro saniyede devam eder.
	while ( microtime( true ) < $start ) {
		usleep( 200 );
	}

	$synced = Kuka_Island_Shipping_Fulfillment_Writer::sync_status(
		$order,
		$reference,
		Kuka_Island_Shipping_Status::CODE_IN_TRANSFER
	);

	printf(
		'slot:%s|started_unfulfilled:%s|state_before:%s|action:%s|notification:%s|mails:%d|events:%d|http:%d' . PHP_EOL,
		$slot,
		$was_unfulfil ? 'yes' : 'no',
		'' === $state_before ? 'absent' : $state_before,
		(string) $synced['action'],
		(string) $synced['notification'],
		$mails,
		$events,
		$http
	);
	exit;
}

/* ----------------------------------------------------------------- measure */

if ( 'measure' !== $mode ) {
	fwrite( STDERR, 'RACE=FAIL|reason:unknown_mode:' . $mode . PHP_EOL );
	exit( 1 );
}

$run_id     = wp_generate_uuid4();
$order_ids  = array();
$records    = array();
$refusals   = array();
$state      = 'idle';
$store      = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';

$cleanup = static function () use ( &$order_ids, &$records, &$state, &$refusals, $run_id, $store ): void {
	$enter = kuka_iyzico_cleanup_enter( $state );
	$state = (string) $enter['state'];

	if ( ! $enter['proceed'] ) {
		if ( '' !== (string) $enter['refusal'] ) {
			$refusals[] = (string) $enter['refusal'];
		}

		return;
	}

	foreach ( $records as $record ) {
		try {
			wc_get_container()->get( $store )->delete( $record );
		} catch ( Throwable $error ) {
			unset( $error );
			$refusals[] = 'fulfillment:delete_refused';
		}
	}

	foreach ( $order_ids as $order_id ) {
		$reason = '';

		if ( ! kuka_iyzico_fixture_is_owned( (int) $order_id, $run_id, $order_ids, $reason ) ) {
			$refusals[] = 'order:' . $reason;
			continue;
		}

		kuka_iyzico_delete_owned_order( (int) $order_id );
	}

	$state = kuka_iyzico_cleanup_finish( $refusals );
};

register_shutdown_function(
	static function () use ( $cleanup, &$state, &$refusals ): void {
		$cleanup();

		if ( 'succeeded' !== $state ) {
			fwrite( STDERR, 'RACE_CLEANUP=' . $state . '|' . ( $refusals ? implode( ' | ', $refusals ) : 'unknown' ) . PHP_EOL );
			exit( 1 );
		}
	}
);

$order = wc_create_order();
$order->set_billing_email( KUKA_RACE_RECIPIENT );
$order->set_billing_first_name( 'Yarış' );
$order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
$order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );
$order->update_meta_data( '_kuka_shipping_reference', KUKA_RACE_REFERENCE );

$product_id = kuka_race_product_id();

if ( $product_id > 0 ) {
	$order->add_product( wc_get_product( $product_id ), 1 );
}

$order->save();
$order_ids[] = (int) $order->get_id();

$record = kuka_race_own_fulfillment( $order );

if ( null === $record ) {
	echo 'SHIPPING_NOTIFICATION_CONCURRENT_FIRST_DISPATCH=FAIL|reason:fulfillments_api_unavailable' . PHP_EOL;
	exit( 1 );
}

$records[] = $record;

/**
 * İki gerçek süreç başlat ve çıktılarını topla.
 *
 * `wp eval-file` içinden kurgulanan bir yarış hiçbir şey kanıtlamaz:
 * `GET_LOCK` bağlantı başınadır ve tek süreçte tek bağlantı var.
 *
 * @param int   $order_id  Sipariş.
 * @param array $barriers  Yuva adı => devam edeceği mikro zaman.
 * @return array{lines: array<string, string>, errors: array<string, string>}
 */
function kuka_race_spawn( int $order_id, array $barriers ): array {
	$handles = array();
	$pipes   = array();

	foreach ( $barriers as $slot => $barrier ) {
		$command = sprintf(
			'php %s worker %d %s %.6F %s',
			escapeshellarg( __FILE__ ),
			$order_id,
			escapeshellarg( KUKA_RACE_REFERENCE ),
			(float) $barrier,
			escapeshellarg( (string) $slot )
		);

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$handle = proc_open( $command, $descriptors, $slot_pipes );

		if ( ! is_resource( $handle ) ) {
			return array( 'lines' => array(), 'errors' => array( (string) $slot => 'cannot_spawn_process' ) );
		}

		$handles[ $slot ] = $handle;
		$pipes[ $slot ]   = $slot_pipes;
	}

	$lines  = array();
	$errors = array();

	foreach ( $handles as $slot => $handle ) {
		$lines[ $slot ]  = trim( (string) stream_get_contents( $pipes[ $slot ][1] ) );
		$errors[ $slot ] = trim( (string) stream_get_contents( $pipes[ $slot ][2] ) );
		fclose( $pipes[ $slot ][1] );
		fclose( $pipes[ $slot ][2] );
		proc_close( $handle );
	}

	return array( 'lines' => $lines, 'errors' => $errors );
}

/**
 * Bir işçinin satırını alanlara ayır.
 *
 * @param string $line İşçi çıktısı.
 * @return array<string, string>
 */
function kuka_race_fields( string $line ): array {
	$fields = array();

	foreach ( explode( '|', $line ) as $pair ) {
		$parts = explode( ':', $pair, 2 );

		if ( 2 === count( $parts ) ) {
			$fields[ $parts[0] ] = $parts[1];
		}
	}

	return $fields;
}

/**
 * İşçi çıktılarını alan dizilerine çevir.
 *
 * @param array<string, string> $lines Yuva => çıktı.
 * @return array<string, array<string, string>>
 */
function kuka_race_workers( array $lines ): array {
	$workers = array();

	foreach ( $lines as $slot => $line ) {
		// İşçi WordPress uyarısı basmış olabilir; yalnız kendi satırı alınır.
		$own = '';

		foreach ( explode( "\n", (string) $line ) as $candidate ) {
			if ( str_starts_with( trim( $candidate ), 'slot:' ) ) {
				$own = trim( $candidate );
			}
		}

		$workers[ $slot ] = kuka_race_fields( $own );
	}

	return $workers;
}

/* --- 1. senaryo: iki süreç aynı mikro saniyede ------------------------- */

$barrier   = microtime( true ) + 3.0;
$spawned   = kuka_race_spawn( (int) $order->get_id(), array( 'a' => $barrier, 'b' => $barrier ) );
$lines     = $spawned['lines'];
$errors    = $spawned['errors'];
$workers   = kuka_race_workers( $lines );

$started_unfulfilled = 0;
$mail_attempts       = 0;
$notification_events = 0;
$transport_owners    = 0;
$outcomes            = array();

foreach ( $workers as $fields ) {
	$started_unfulfilled += 'yes' === ( $fields['started_unfulfilled'] ?? '' ) && 'absent' === ( $fields['state_before'] ?? '' ) ? 1 : 0;
	$mail_attempts       += (int) ( $fields['mails'] ?? 0 );
	$notification_events += (int) ( $fields['events'] ?? 0 );
	$outcomes[]           = (string) ( $fields['notification'] ?? 'missing' );
	$transport_owners    += 'sent' === (string) ( $fields['notification'] ?? '' ) ? 1 : 0;
}

/*
 * TAZE OKUMA. Bu süreç siparişi kendisi oluşturdu, dolayısıyla nesne HPOS
 * sipariş önbelleğinde duruyor ve `wc_get_order()` çocuk süreçlerin yazdığı
 * metayı görmez. Önbellek düşürülmeden yapılan bir ölçüm "durum boş" der ve
 * yeşil bir yarışı kırmızı gösterir.
 */
kuka_race_forget_order( (int) $order->get_id() );
$final = wc_get_order( (int) $order->get_id() );
$status = Kuka_Island_Shipping_Notification::status( $final );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$lock_free = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', 'kuka_ship_notify_' . (int) $order->get_id() ) );

$pass = 2 === $started_unfulfilled
	&& 1 === $transport_owners
	&& 1 === $mail_attempts
	&& 1 === $notification_events
	&& Kuka_Island_Shipping_Notification::STATE_SENT === (string) $status['state']
	&& 1 === (int) $status['attempts']
	&& 'pending' === (string) $final->get_status();

printf(
	'SHIPPING_NOTIFICATION_CONCURRENT_FIRST_DISPATCH=%s|processes:2|both_started_from_unfulfilled:%s|lock_winners:%d|mail_attempts:%d|notification_events:%d|final_state:%s|attempts:%d|order_status:%s' . PHP_EOL,
	$pass ? 'PASS' : 'FAIL',
	2 === $started_unfulfilled ? 'yes' : 'no',
	$transport_owners,
	$mail_attempts,
	$notification_events,
	'' === (string) $status['state'] ? 'absent' : (string) $status['state'],
	(int) $status['attempts'],
	(string) $final->get_status()
);

printf(
	'SHIPPING_NOTIFICATION_RACE_OUTCOMES=%s' . PHP_EOL,
	implode( ',', $outcomes )
);

/* --- 2. senaryo: kilit boşken giren BAYAT süreç ------------------------ */

/*
 * Yarışın öteki sıralaması: kazanan işini bitirip kilidi bıraktıktan SONRA
 * ikinci süreç kilidi çekişmesiz alır. O süreç sipariş nesnesini kendi
 * isteğinin başında yüklemiştir ve elindeki meta bayattır. Kilit tek başına bu
 * durumu kapatmaz; kapatan şey kilit alındıktan sonra veritabanından yapılan
 * TAZE okumadır. Bu senaryo o yarım düzeltmeyi ayrıca ölçer.
 */
$stale_order = wc_create_order();
$stale_order->set_billing_email( KUKA_RACE_RECIPIENT );
$stale_order->set_billing_first_name( 'Bayat' );
$stale_order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
$stale_order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );
$stale_order->update_meta_data( '_kuka_shipping_reference', KUKA_RACE_REFERENCE );

if ( $product_id > 0 ) {
	$stale_order->add_product( wc_get_product( $product_id ), 1 );
}

$stale_order->save();
$order_ids[] = (int) $stale_order->get_id();

$stale_record = kuka_race_own_fulfillment( $stale_order );

if ( null !== $stale_record ) {
	$records[] = $stale_record;
}

$stale_start   = microtime( true ) + 3.0;
$stale_spawned = kuka_race_spawn(
	(int) $stale_order->get_id(),
	// İkinci süreç siparişi hemen yükler, kararı ilk süreç bittikten sonra verir.
	array( 'first' => $stale_start, 'second' => $stale_start + 4.0 )
);
$stale_workers = kuka_race_workers( $stale_spawned['lines'] );

$stale_mails   = 0;
$stale_events  = 0;
$stale_started = 0;

foreach ( $stale_workers as $fields ) {
	$stale_mails   += (int) ( $fields['mails'] ?? 0 );
	$stale_events  += (int) ( $fields['events'] ?? 0 );
	$stale_started += 'yes' === ( $fields['started_unfulfilled'] ?? '' ) && 'absent' === ( $fields['state_before'] ?? '' ) ? 1 : 0;
}

$stale_first  = (string) ( $stale_workers['first']['notification'] ?? 'missing' );
$stale_second = (string) ( $stale_workers['second']['notification'] ?? 'missing' );

kuka_race_forget_order( (int) $stale_order->get_id() );
$stale_final  = wc_get_order( (int) $stale_order->get_id() );
$stale_status = Kuka_Island_Shipping_Notification::status( $stale_final );

printf(
	'SHIPPING_NOTIFICATION_STALE_SECOND_PROCESS=%s|both_started_from_unfulfilled:%s|first:%s|second:%s|mail_attempts:%d|notification_events:%d|final_state:%s|attempts:%d|order_status:%s' . PHP_EOL,
	2 === $stale_started
		&& 'sent' === $stale_first
		&& 'already_sent' === $stale_second
		&& 1 === $stale_mails
		&& 1 === $stale_events
		&& Kuka_Island_Shipping_Notification::STATE_SENT === (string) $stale_status['state']
		&& 1 === (int) $stale_status['attempts']
		&& 'pending' === (string) $stale_final->get_status()
			? 'PASS' : 'FAIL',
	2 === $stale_started ? 'yes' : 'no',
	$stale_first,
	$stale_second,
	$stale_mails,
	$stale_events,
	'' === (string) $stale_status['state'] ? 'absent' : (string) $stale_status['state'],
	(int) $stale_status['attempts'],
	(string) $stale_final->get_status()
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$stale_lock_free = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', 'kuka_ship_notify_' . (int) $stale_order->get_id() ) );

$outbound = 0;

foreach ( array_merge( array_values( $workers ), array_values( $stale_workers ) ) as $fields ) {
	$outbound += (int) ( $fields['http'] ?? 0 );
}

printf(
	'SHIPPING_NOTIFICATION_RACE_HYGIENE=lock_released:%s|outbound_http:%d|worker_errors:%d' . PHP_EOL,
	'1' === $lock_free && '1' === $stale_lock_free ? 'yes' : 'NO',
	$outbound,
	count( array_filter( array_merge( $errors, $stale_spawned['errors'] ), static fn( string $text ): bool => '' !== $text ) )
);
