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

if ( in_array( $mode, array( 'worker', 'crash-worker', 'sabotage-worker', 'unreadable-worker' ), true ) ) {
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

	/*
	 * ÇÖKME PENCERESİ. `woocommerce_fulfillment_after_update`, veri deposunun
	 * satırı YAZDIKTAN sonra ve hiçbir işlem (transaction) içinde olmadan
	 * tetikleniyor. Buradan ölen bir süreç tam olarak bildirilen aralıkta ölür:
	 * kayıt diskte `fulfilled`, bildirim niyeti hiç yazılmamış. Üretim kodunda
	 * hiçbir şey değişmez; ölüm dışarıdan, gerçek bir kancadan gelir.
	 */
	if ( 'crash-worker' === $mode ) {
		add_action(
			'woocommerce_fulfillment_after_update',
			static function (): void {
				if ( function_exists( 'posix_kill' ) ) {
					posix_kill( getmypid(), 9 );
				}

				exit( 3 );
			},
			PHP_INT_MIN
		);
	}

	/*
	 * KONTROLLÜ SABOTAJ: claim'in meta yazması sessizce düşürülür.
	 *
	 * WordPress'in kendi `query` filtresi kullanılır — üretim kodunda hiçbir
	 * şey değişmez. Yazma çalıştırılmadan `SELECT 1`'e çevrilir, dolayısıyla
	 * `save_meta_data()` başarılı sanır ama satır diske hiç düşmez. Claim'in
	 * kilit içinde yaptığı geri okuma bunu yakalamak zorundadır.
	 */
	if ( 'sabotage-worker' === $mode ) {
		add_filter(
			'query',
			static function ( $query ) {
				$query = (string) $query;
				$head  = strtoupper( substr( ltrim( $query ), 0, 6 ) );

				if ( ! str_contains( $query, '_kuka_shipping_notify' ) ) {
					return $query;
				}

				if ( 'INSERT' === $head || 'UPDATE' === $head || 'REPLAC' === $head ) {
					return 'SELECT 1';
				}

				return $query;
			},
			PHP_INT_MAX
		);
	}

	/*
	 * KONTROLLÜ SABOTAJ: sipariş taze okunamaz.
	 *
	 * `WC_Order_Factory` sınıf adını `woocommerce_order_class` filtresinden
	 * geçirir ve sınıf yoksa `false` döner, yani `wc_get_order()` başarısız
	 * olur. Filtre işçi kendi siparişini YÜKLEDİKTEN sonra takılır, böylece
	 * başarısız olan tek şey claim'in kilit içindeki taze okumasıdır.
	 */
	if ( 'unreadable-worker' === $mode ) {
		add_filter(
			'woocommerce_order_class',
			static function (): string {
				return 'Kuka_Race_Order_Class_That_Does_Not_Exist';
			},
			PHP_INT_MAX
		);
	}

	// Bariyer: iki süreç aynı mikro saniyede devam eder.
	while ( microtime( true ) < $start ) {
		usleep( 200 );
	}

	$clock_before = microtime( true );

	$synced = Kuka_Island_Shipping_Fulfillment_Writer::sync_status(
		$order,
		$reference,
		Kuka_Island_Shipping_Status::CODE_IN_TRANSFER
	);

	$clock_after = microtime( true );

	printf(
		'slot:%s|started_unfulfilled:%s|state_before:%s|action:%s|reason:%s|notification:%s|date:%s|mails:%d|events:%d|http:%d|clock_before:%.6F|clock_after:%.6F' . PHP_EOL,
		$slot,
		$was_unfulfil ? 'yes' : 'no',
		'' === $state_before ? 'absent' : $state_before,
		(string) $synced['action'],
		'' === (string) $synced['reason'] ? 'none' : (string) $synced['reason'],
		(string) $synced['notification'],
		(string) $synced['date_fulfilled'],
		$mails,
		$events,
		$http,
		$clock_before,
		$clock_after
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
 * @param int    $order_id Sipariş.
 * @param array  $barriers Yuva adı => devam edeceği mikro zaman.
 * @param string $mode     `worker` ya da `crash-worker`.
 * @return array{lines: array<string, string>, errors: array<string, string>, codes: array<string, int>}
 */
function kuka_race_spawn( int $order_id, array $barriers, string $mode = 'worker' ): array {
	$handles = array();
	$pipes   = array();

	foreach ( $barriers as $slot => $barrier ) {
		$command = sprintf(
			'php %s %s %d %s %.6F %s',
			escapeshellarg( __FILE__ ),
			escapeshellarg( $mode ),
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
			return array( 'lines' => array(), 'errors' => array( (string) $slot => 'cannot_spawn_process' ), 'codes' => array() );
		}

		$handles[ $slot ] = $handle;
		$pipes[ $slot ]   = $slot_pipes;
	}

	$lines  = array();
	$errors = array();
	$codes  = array();

	foreach ( $handles as $slot => $handle ) {
		$lines[ $slot ]  = trim( (string) stream_get_contents( $pipes[ $slot ][1] ) );
		$errors[ $slot ] = trim( (string) stream_get_contents( $pipes[ $slot ][2] ) );
		fclose( $pipes[ $slot ][1] );
		fclose( $pipes[ $slot ][2] );
		$codes[ $slot ] = (int) proc_close( $handle );
	}

	return array( 'lines' => $lines, 'errors' => $errors, 'codes' => $codes );
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
 * Bir siparişin bildirim durumu ve sipariş statüsü, taze okumayla.
 *
 * @param int $order_id Sipariş.
 * @return array{state: string, code: string, attempts: int, order_status: string}
 */
function kuka_race_status( int $order_id ): array {
	kuka_race_forget_order( $order_id );
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		return array( 'state' => 'unreadable', 'code' => '', 'attempts' => -1, 'order_status' => 'unreadable' );
	}

	$status = Kuka_Island_Shipping_Notification::status( $order );

	return array(
		'state'        => (string) $status['state'],
		'code'         => (string) $status['code'],
		'attempts'     => (int) $status['attempts'],
		'order_status' => (string) $order->get_status(),
	);
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

$concurrent_date_writes = 0;

foreach ( $workers as $fields ) {
	$concurrent_date_writes += 'set' === (string) ( $fields['date'] ?? '' ) ? 1 : 0;
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

/* --- 3. senaryo: `pending` borcu sonradan ödenmeli --------------------- */

/*
 * `recipient_missing` ya da `mailer_unavailable` ilk çağrıda `pending` yazar:
 * "gönderilmedi, sonra denenmeli". Sonraki poll'da kayıt zaten `fulfilled`
 * olduğu için `first_transition` FALSE gelir. `pending` özel işlenmezse o borç
 * kalıcı olarak ödenmez ve müşteri hiç bilgilendirilmez.
 *
 * Eksiklik gerçek üretim yolundan üretilir: siparişin faturalama e-postası yok.
 */
$pending_order = wc_create_order();
$pending_order->set_billing_first_name( 'Borç' );
$pending_order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
$pending_order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );
$pending_order->update_meta_data( '_kuka_shipping_reference', KUKA_RACE_REFERENCE );

if ( $product_id > 0 ) {
	$pending_order->add_product( wc_get_product( $product_id ), 1 );
}

$pending_order->save();
$order_ids[] = (int) $pending_order->get_id();

$pending_record = kuka_race_own_fulfillment( $pending_order );

if ( null !== $pending_record ) {
	$records[] = $pending_record;
}

$pending_first   = kuka_race_workers( kuka_race_spawn( (int) $pending_order->get_id(), array( 'first' => microtime( true ) + 0.2 ) )['lines'] );
$pending_state_1 = kuka_race_status( (int) $pending_order->get_id() );

// Eksiklik giderilir: müşterinin adresi artık var.
kuka_race_forget_order( (int) $pending_order->get_id() );
$pending_fixed = wc_get_order( (int) $pending_order->get_id() );
$pending_fixed->set_billing_email( KUKA_RACE_RECIPIENT );
$pending_fixed->save();

$pending_second  = kuka_race_workers( kuka_race_spawn( (int) $pending_order->get_id(), array( 'second' => microtime( true ) + 0.2 ) )['lines'] );
$pending_state_2 = kuka_race_status( (int) $pending_order->get_id() );

$pending_third   = kuka_race_workers( kuka_race_spawn( (int) $pending_order->get_id(), array( 'third' => microtime( true ) + 0.2 ) )['lines'] );
$pending_state_3 = kuka_race_status( (int) $pending_order->get_id() );

$pending_mails = (int) ( $pending_first['first']['mails'] ?? -1 )
	+ (int) ( $pending_second['second']['mails'] ?? -1 )
	+ (int) ( $pending_third['third']['mails'] ?? -1 );

printf(
	'SHIPPING_NOTIFICATION_PENDING_RETRIES_WHEN_DUE=%s|first:%s|state_after_first:%s|attempts_after_first:%d|mails_first:%d|second_first_transition:%s|second:%s|mails_second:%d|state:%s|attempts:%d|third:%s|mails_total:%d|order_status:%s' . PHP_EOL,
	'recipient_missing' === (string) ( $pending_first['first']['notification'] ?? '' )
		&& Kuka_Island_Shipping_Notification::STATE_PENDING === (string) $pending_state_1['state']
		&& 0 === (int) $pending_state_1['attempts']
		&& 0 === (int) ( $pending_first['first']['mails'] ?? -1 )
		&& 'no' === (string) ( $pending_second['second']['started_unfulfilled'] ?? '' )
		&& 'sent' === (string) ( $pending_second['second']['notification'] ?? '' )
		&& 1 === (int) ( $pending_second['second']['mails'] ?? -1 )
		&& Kuka_Island_Shipping_Notification::STATE_SENT === (string) $pending_state_2['state']
		&& 1 === (int) $pending_state_2['attempts']
		&& 'already_sent' === (string) ( $pending_third['third']['notification'] ?? '' )
		&& 1 === $pending_mails
		&& 1 === (int) $pending_state_3['attempts']
		&& 'pending' === (string) $pending_state_3['order_status']
			? 'PASS' : 'FAIL',
	(string) ( $pending_first['first']['notification'] ?? 'missing' ),
	'' === (string) $pending_state_1['state'] ? 'absent' : (string) $pending_state_1['state'],
	(int) $pending_state_1['attempts'],
	(int) ( $pending_first['first']['mails'] ?? -1 ),
	'yes' === (string) ( $pending_second['second']['started_unfulfilled'] ?? '' ) ? 'yes' : 'no',
	(string) ( $pending_second['second']['notification'] ?? 'missing' ),
	(int) ( $pending_second['second']['mails'] ?? -1 ),
	'' === (string) $pending_state_2['state'] ? 'absent' : (string) $pending_state_2['state'],
	(int) $pending_state_2['attempts'],
	(string) ( $pending_third['third']['notification'] ?? 'missing' ),
	$pending_mails,
	(string) $pending_state_3['order_status']
);

/* --- 4. senaryo: niyetten önce çöken süreç ----------------------------- */

$crash_order = wc_create_order();
$crash_order->set_billing_email( KUKA_RACE_RECIPIENT );
$crash_order->set_billing_first_name( 'Çökme' );
$crash_order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
$crash_order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );
$crash_order->update_meta_data( '_kuka_shipping_reference', KUKA_RACE_REFERENCE );

if ( $product_id > 0 ) {
	$crash_order->add_product( wc_get_product( $product_id ), 1 );
}

$crash_order->save();
$order_ids[] = (int) $crash_order->get_id();

$crash_record = kuka_race_own_fulfillment( $crash_order );

if ( null !== $crash_record ) {
	$records[] = $crash_record;
}

$crashed      = kuka_race_spawn( (int) $crash_order->get_id(), array( 'crash' => microtime( true ) + 0.2 ), 'crash-worker' );
$crash_lines  = kuka_race_workers( $crashed['lines'] );
$crash_state  = kuka_race_status( (int) $crash_order->get_id() );
$crash_exit   = (int) ( $crashed['codes']['crash'] ?? 0 );

$crash_fulfilled = 'no';
$crash_reread    = Kuka_Island_Shipping_Fulfillment_Writer::find_own(
	wc_get_order( (int) $crash_order->get_id() ),
	KUKA_RACE_REFERENCE
);

if ( is_object( $crash_reread ) && method_exists( $crash_reread, 'get_is_fulfilled' ) ) {
	$crash_fulfilled = $crash_reread->get_is_fulfilled() ? 'yes' : 'no';
}

$crash_recovery = kuka_race_workers( kuka_race_spawn( (int) $crash_order->get_id(), array( 'recovery' => microtime( true ) + 0.2 ) )['lines'] );
$crash_after    = kuka_race_status( (int) $crash_order->get_id() );
$crash_repeat   = kuka_race_workers( kuka_race_spawn( (int) $crash_order->get_id(), array( 'repeat' => microtime( true ) + 0.2 ) )['lines'] );
$crash_final    = kuka_race_status( (int) $crash_order->get_id() );

$crash_mails = (int) ( $crash_lines['crash']['mails'] ?? 0 )
	+ (int) ( $crash_recovery['recovery']['mails'] ?? -1 )
	+ (int) ( $crash_repeat['repeat']['mails'] ?? -1 );

printf(
	'SHIPPING_NOTIFICATION_CRASH_BEFORE_SEND_INTENT_RECOVERS=%s|crash_exit:%s|crash_mails:%d|record_fulfilled_after_crash:%s|state_after_crash:%s|recovery:%s|mails_total:%d|state:%s|attempts:%d|second_automatic_send:%d|order_status:%s' . PHP_EOL,
	0 !== $crash_exit
		&& 0 === (int) ( $crash_lines['crash']['mails'] ?? 0 )
		&& 'yes' === $crash_fulfilled
		&& 'sent' === (string) ( $crash_recovery['recovery']['notification'] ?? '' )
		&& 1 === $crash_mails
		&& Kuka_Island_Shipping_Notification::STATE_SENT === (string) $crash_after['state']
		&& 1 === (int) $crash_after['attempts']
		&& 0 === (int) ( $crash_repeat['repeat']['mails'] ?? -1 )
		&& 1 === (int) $crash_final['attempts']
		&& 'pending' === (string) $crash_final['order_status']
			? 'PASS' : 'FAIL',
	0 !== $crash_exit ? 'nonzero' : 'ZERO',
	(int) ( $crash_lines['crash']['mails'] ?? 0 ),
	$crash_fulfilled,
	'' === (string) $crash_state['state'] ? 'absent' : (string) $crash_state['state'],
	(string) ( $crash_recovery['recovery']['notification'] ?? 'missing' ),
	$crash_mails,
	'' === (string) $crash_after['state'] ? 'absent' : (string) $crash_after['state'],
	(int) $crash_after['attempts'],
	(int) ( $crash_repeat['repeat']['mails'] ?? -1 ),
	(string) $crash_final['order_status']
);

/* --- Fulfillment tarihi anlaşılan ana bağlı mı ------------------------- */

/*
 * Tarih ezmesi ölçüldü: gerçek eşzamanlılıkta iki süreç de `_date_fulfilled`
 * boş görüp kendi damgasını yazıyor (`concurrent_date_writes:2`). 120 ms arayla
 * koşan ikinci süreç ise tarihi zaten dolu gördüğü için hiç yazmıyor — yani
 * ezme yalnız ikisi de kaydı kaydetmeden ÖNCE okuduğunda oluyor ve o pencerede
 * iki damga birbirinden mikro saniyeler kadar uzak. Saklanan değer saniye
 * çözünürlüğünde olduğu için fark ancak iki damga bir saniye tikinin iki yanına
 * düşerse ortaya çıkar; bir milisaniyelik yarışı kovalayan bir ölçüm ise
 * kararsız olur ve hiçbir şey kanıtlamaz.
 *
 * Bu yüzden sorulan soru MEKANİZMA üzerinedir ve deterministiktir: saklanan
 * gönderi tarihi süreçlerin saatinden mi geliyor, yoksa borçla birlikte yazılan
 * TEK anlaşılmış andan mı? Kurulum, borcunu yazdıktan sonra çökmüş bir sürecin
 * geride bıraktığının aynısıdır — durum `due`, referans yazılı, teslim anı
 * 26 saat öncesi. O an bugünden BAŞKA bir yerel tarihe düşer; saklanan değer
 * onu takip ederse tarih süreçlerin saatine bağlı değildir ve iki süreçte
 * ezilmesi EDM'nin okuduğu tarihi değiştiremez. Bir gün sonra çalışan bir
 * yeniden deneme de mali tarihi kaydırmaz.
 */
$seed_instant = ( new DateTimeImmutable( '-26 hours', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:sP' );
$seed_utc     = gmdate( 'Y-m-d H:i:s', (int) strtotime( $seed_instant ) );
$seed_local   = ( new DateTimeImmutable( $seed_instant ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
$today_local  = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );

$seed_order = wc_create_order();
$seed_order->set_billing_email( KUKA_RACE_RECIPIENT );
$seed_order->set_billing_first_name( 'Anlaşma' );
$seed_order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
$seed_order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );
$seed_order->update_meta_data( '_kuka_shipping_reference', KUKA_RACE_REFERENCE );

if ( $product_id > 0 ) {
	$seed_order->add_product( wc_get_product( $product_id ), 1 );
}

// Borcunu yazıp çökmüş bir sürecin bıraktığı hâl, birebir.
$seed_order->update_meta_data( Kuka_Island_Shipping_Notification::META_STATE, Kuka_Island_Shipping_Notification::STATE_DUE );
$seed_order->update_meta_data( Kuka_Island_Shipping_Notification::META_CODE, '' );
$seed_order->update_meta_data( Kuka_Island_Shipping_Notification::META_DUE_REFERENCE, KUKA_RACE_REFERENCE );
$seed_order->update_meta_data( Kuka_Island_Shipping_Notification::META_HANDOVER_AT, $seed_instant );
$seed_order->save();
$order_ids[] = (int) $seed_order->get_id();

$seed_record = kuka_race_own_fulfillment( $seed_order );

if ( null !== $seed_record ) {
	$records[] = $seed_record;
}

$seed_run    = kuka_race_workers( kuka_race_spawn( (int) $seed_order->get_id(), array( 'seed' => microtime( true ) + 0.2 ) )['lines'] );
$seed_repeat = kuka_race_workers( kuka_race_spawn( (int) $seed_order->get_id(), array( 'repeat' => microtime( true ) + 0.2 ) )['lines'] );

kuka_race_forget_order( (int) $seed_order->get_id() );
$seed_final  = wc_get_order( (int) $seed_order->get_id() );
$seed_stored = '';
$seed_reread = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $seed_final, KUKA_RACE_REFERENCE );

if ( is_object( $seed_reread ) && method_exists( $seed_reread, 'get_date_fulfilled' ) ) {
	$seed_stored = (string) ( $seed_reread->get_date_fulfilled() ?? '' );
}

$seed_stored_utc = '' === $seed_stored ? '' : gmdate( 'Y-m-d H:i:s', (int) strtotime( $seed_stored . ' UTC' ) );
$seed_matches    = '' !== $seed_stored_utc && $seed_stored_utc === $seed_utc;

printf(
	'SHIPPING_FULFILLMENT_DATE_FOLLOWS_AGREED_INSTANT=%s|concurrent_date_writes:%d|offset_process_date_writes:1|seeded_local_date_differs_from_today:%s|stored_matches_agreed:%s|retry_moves_date:%s|mails:%d|edm_shipment_date_can_differ:%s' . PHP_EOL,
	2 === $concurrent_date_writes
		&& $seed_local !== $today_local
		&& $seed_matches
		&& 1 === (int) ( $seed_run['seed']['mails'] ?? -1 )
		&& 0 === (int) ( $seed_repeat['repeat']['mails'] ?? -1 )
			? 'PASS' : 'FAIL',
	$concurrent_date_writes,
	$seed_local !== $today_local ? 'yes' : 'NO',
	$seed_matches ? 'yes' : 'NO',
	$seed_matches ? 'no' : 'yes',
	(int) ( $seed_run['seed']['mails'] ?? -1 ) + (int) ( $seed_repeat['repeat']['mails'] ?? -1 ),
	$seed_matches ? 'no' : 'yes'
);

/* --- 6..8. senaryo: claim sınırları fail-closed mı --------------------- */

/**
 * Bir siparişin bildirim metası ve kaydının hâli, tek okumada.
 *
 * @param int $order_id Sipariş.
 * @return array<string, string>
 */
function kuka_race_claim_facts( int $order_id ): array {
	kuka_race_forget_order( $order_id );
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		return array( 'meta_writes' => 'unreadable', 'fulfilled' => 'unreadable', 'date' => 'unreadable' );
	}

	$keys = array(
		Kuka_Island_Shipping_Notification::META_STATE,
		Kuka_Island_Shipping_Notification::META_CODE,
		Kuka_Island_Shipping_Notification::META_ATTEMPTS,
		Kuka_Island_Shipping_Notification::META_AT,
		Kuka_Island_Shipping_Notification::META_DUE_REFERENCE,
		Kuka_Island_Shipping_Notification::META_HANDOVER_AT,
	);

	$writes = 0;

	foreach ( $keys as $key ) {
		$writes += '' === (string) $order->get_meta( $key, true ) ? 0 : 1;
	}

	$record    = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $order, KUKA_RACE_REFERENCE );
	$fulfilled = 'missing';
	$date      = 'missing';

	if ( is_object( $record ) && method_exists( $record, 'get_is_fulfilled' ) ) {
		$fulfilled = $record->get_is_fulfilled() ? 'yes' : 'no';
		$date      = '' === (string) ( $record->get_date_fulfilled() ?? '' ) ? 'absent' : 'present';
	}

	return array(
		'meta_writes' => (string) $writes,
		'fulfilled'   => $fulfilled,
		'date'        => $date,
	);
}

/**
 * Fixture: sipariş + bu modülün kendi `unfulfilled` kaydı.
 *
 * @param string $run_id    Sahiplik damgası.
 * @param array  $order_ids Kimlikler (referans).
 * @param array  $records   Kayıtlar (referans).
 * @param string $name      Faturalama adı.
 * @param int    $product   Ürün kimliği.
 */
function kuka_race_claim_fixture( string $run_id, array &$order_ids, array &$records, string $name, int $product ): WC_Order {
	$order = wc_create_order();
	$order->set_billing_email( KUKA_RACE_RECIPIENT );
	$order->set_billing_first_name( $name );
	$order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
	$order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );
	$order->update_meta_data( '_kuka_shipping_reference', KUKA_RACE_REFERENCE );

	if ( $product > 0 ) {
		$order->add_product( wc_get_product( $product ), 1 );
	}

	$order->save();
	$order_ids[] = (int) $order->get_id();

	$record = kuka_race_own_fulfillment( $order );

	if ( null !== $record ) {
		$records[] = $record;
	}

	return $order;
}

/* A. Claim kilidi başka bir MySQL oturumu tarafından tutuluyor. */

$lock_order = kuka_race_claim_fixture( $run_id, $order_ids, $records, 'Kilit', $product_id );
$lock_name  = 'kuka_ship_notify_claim_' . (int) $lock_order->get_id();

/*
 * Kilidi BU süreç tutar. Ayrı bir işletim sistemi süreci olduğu için ayrı bir
 * MySQL oturumudur ve `GET_LOCK` oturum başınadır: işçi onu alamayacak.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$lock_held = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );

$contended = kuka_race_workers( kuka_race_spawn( (int) $lock_order->get_id(), array( 'contended' => microtime( true ) + 0.2 ) )['lines'] );
$contended_facts = kuka_race_claim_facts( (int) $lock_order->get_id() );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );

$lock_retry       = kuka_race_workers( kuka_race_spawn( (int) $lock_order->get_id(), array( 'retry' => microtime( true ) + 0.2 ) )['lines'] );
$lock_retry_facts = kuka_race_claim_facts( (int) $lock_order->get_id() );

kuka_race_forget_order( (int) $lock_order->get_id() );
$lock_final    = wc_get_order( (int) $lock_order->get_id() );
$lock_agreed   = (string) $lock_final->get_meta( Kuka_Island_Shipping_Notification::META_HANDOVER_AT, true );
$lock_record   = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $lock_final, KUKA_RACE_REFERENCE );
$lock_stored   = is_object( $lock_record ) && method_exists( $lock_record, 'get_date_fulfilled' )
	? (string) ( $lock_record->get_date_fulfilled() ?? '' )
	: '';
$lock_date_ok  = '' !== $lock_agreed
	&& '' !== $lock_stored
	&& gmdate( 'Y-m-d H:i:s', (int) strtotime( $lock_agreed ) ) === gmdate( 'Y-m-d H:i:s', (int) strtotime( $lock_stored . ' UTC' ) );

printf(
	'SHIPPING_NOTIFICATION_CLAIM_LOCK_IS_FAIL_CLOSED=%s|lock_held_by_other_session:%s|outcome:%s|notification_meta_writes:%s|fulfilled:%s|date:%s|mails:%d|retry_outcome:%s|retry_mails:%d|retry_fulfilled:%s|date_from_first_successful_claim:%s' . PHP_EOL,
	'1' === $lock_held
		&& 'claim_lock_contended' === (string) ( $contended['contended']['reason'] ?? '' )
		&& '0' === (string) $contended_facts['meta_writes']
		&& 'no' === (string) $contended_facts['fulfilled']
		&& 'absent' === (string) $contended_facts['date']
		&& 0 === (int) ( $contended['contended']['mails'] ?? -1 )
		&& 'sent' === (string) ( $lock_retry['retry']['notification'] ?? '' )
		&& 1 === (int) ( $lock_retry['retry']['mails'] ?? -1 )
		&& 'yes' === (string) $lock_retry_facts['fulfilled']
		&& $lock_date_ok
			? 'PASS' : 'FAIL',
	'1' === $lock_held ? 'yes' : 'NO',
	(string) ( $contended['contended']['reason'] ?? 'missing' ),
	(string) $contended_facts['meta_writes'],
	(string) $contended_facts['fulfilled'],
	(string) $contended_facts['date'],
	(int) ( $contended['contended']['mails'] ?? -1 ),
	(string) ( $lock_retry['retry']['notification'] ?? 'missing' ),
	(int) ( $lock_retry['retry']['mails'] ?? -1 ),
	(string) $lock_retry_facts['fulfilled'],
	$lock_date_ok ? 'yes' : 'NO'
);

/* B. Claim'in meta yazması sabote edilmiş: geri okuma doğrulaması FAIL. */

$sabotage_order = kuka_race_claim_fixture( $run_id, $order_ids, $records, 'Sabotaj', $product_id );
$sabotaged      = kuka_race_workers( kuka_race_spawn( (int) $sabotage_order->get_id(), array( 'sabotage' => microtime( true ) + 0.2 ), 'sabotage-worker' )['lines'] );
$sabotage_facts = kuka_race_claim_facts( (int) $sabotage_order->get_id() );

$sabotage_retry       = kuka_race_workers( kuka_race_spawn( (int) $sabotage_order->get_id(), array( 'retry' => microtime( true ) + 0.2 ) )['lines'] );
$sabotage_retry_facts = kuka_race_claim_facts( (int) $sabotage_order->get_id() );

printf(
	'SHIPPING_NOTIFICATION_CLAIM_READBACK_IS_VERIFIED=%s|outcome:%s|notification_meta_writes:%s|fulfilled:%s|date:%s|mails:%d|retry_outcome:%s|retry_mails:%d|retry_fulfilled:%s' . PHP_EOL,
	'notification_claim_unverified' === (string) ( $sabotaged['sabotage']['reason'] ?? '' )
		&& '0' === (string) $sabotage_facts['meta_writes']
		&& 'no' === (string) $sabotage_facts['fulfilled']
		&& 'absent' === (string) $sabotage_facts['date']
		&& 0 === (int) ( $sabotaged['sabotage']['mails'] ?? -1 )
		&& 'sent' === (string) ( $sabotage_retry['retry']['notification'] ?? '' )
		&& 1 === (int) ( $sabotage_retry['retry']['mails'] ?? -1 )
		&& 'yes' === (string) $sabotage_retry_facts['fulfilled']
			? 'PASS' : 'FAIL',
	(string) ( $sabotaged['sabotage']['reason'] ?? 'missing' ),
	(string) $sabotage_facts['meta_writes'],
	(string) $sabotage_facts['fulfilled'],
	(string) $sabotage_facts['date'],
	(int) ( $sabotaged['sabotage']['mails'] ?? -1 ),
	(string) ( $sabotage_retry['retry']['notification'] ?? 'missing' ),
	(int) ( $sabotage_retry['retry']['mails'] ?? -1 ),
	(string) $sabotage_retry_facts['fulfilled']
);

/* C. Sipariş kilit içinde taze okunamıyor. */

$unread_order = kuka_race_claim_fixture( $run_id, $order_ids, $records, 'Okunamaz', $product_id );
$unread_run   = kuka_race_workers( kuka_race_spawn( (int) $unread_order->get_id(), array( 'unreadable' => microtime( true ) + 0.2 ), 'unreadable-worker' )['lines'] );
$unread_facts = kuka_race_claim_facts( (int) $unread_order->get_id() );

printf(
	'SHIPPING_NOTIFICATION_CLAIM_UNREADABLE_ORDER_IS_FAIL_CLOSED=%s|outcome:%s|notification_meta_writes:%s|fulfilled:%s|date:%s|mails:%d' . PHP_EOL,
	'claim_order_unreadable' === (string) ( $unread_run['unreadable']['reason'] ?? '' )
		&& '0' === (string) $unread_facts['meta_writes']
		&& 'no' === (string) $unread_facts['fulfilled']
		&& 'absent' === (string) $unread_facts['date']
		&& 0 === (int) ( $unread_run['unreadable']['mails'] ?? -1 )
			? 'PASS' : 'FAIL',
	(string) ( $unread_run['unreadable']['reason'] ?? 'missing' ),
	(string) $unread_facts['meta_writes'],
	(string) $unread_facts['fulfilled'],
	(string) $unread_facts['date'],
	(int) ( $unread_run['unreadable']['mails'] ?? -1 )
);

/* --- 5. senaryo: borcu olmayan kaydı kendiliğinden sahiplenme --------- */

/*
 * Bu modülün referansını taşıyan ama HİÇ borç yazılmamış, önceden `fulfilled`
 * bir kayıt: operatör onu elle işaretlemiş olabilir. `due` ve `pending`
 * gönderime izin veren durumlar olduğuna göre, durumun BOŞ olması gönderime
 * izin vermemeli — yoksa modül kendi yazmadığı bir geçiş için müşteriye
 * e-posta atar.
 */
$adopt_order = wc_create_order();
$adopt_order->set_billing_email( KUKA_RACE_RECIPIENT );
$adopt_order->set_billing_first_name( 'Sahipsiz' );
$adopt_order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
$adopt_order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );
$adopt_order->update_meta_data( '_kuka_shipping_reference', KUKA_RACE_REFERENCE );

if ( $product_id > 0 ) {
	$adopt_order->add_product( wc_get_product( $product_id ), 1 );
}

$adopt_order->save();
$order_ids[] = (int) $adopt_order->get_id();

$adopt_record = kuka_race_own_fulfillment( $adopt_order );

if ( null !== $adopt_record ) {
	$records[] = $adopt_record;

	// Kayıt bu modülün bildirim yolundan GEÇMEDEN fulfilled yapılır.
	$adopt_record->set_status( 'fulfilled' );
	$adopt_record->set_date_fulfilled( ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:sP' ) );
	$adopt_record->save();
}

$adopt_before = kuka_race_status( (int) $adopt_order->get_id() );
$adopt_run    = kuka_race_workers( kuka_race_spawn( (int) $adopt_order->get_id(), array( 'adopt' => microtime( true ) + 0.2 ) )['lines'] );
$adopt_after  = kuka_race_status( (int) $adopt_order->get_id() );

printf(
	'SHIPPING_NOTIFICATION_NO_SELF_ADOPTION=%s|state_before:%s|record_fulfilled:%s|outcome:%s|mails:%d|state_after:%s|attempts:%d' . PHP_EOL,
	'' === (string) $adopt_before['state']
		&& 'no' === (string) ( $adopt_run['adopt']['started_unfulfilled'] ?? '' )
		&& 'not_due' === (string) ( $adopt_run['adopt']['notification'] ?? '' )
		&& 0 === (int) ( $adopt_run['adopt']['mails'] ?? -1 )
		&& '' === (string) $adopt_after['state']
		&& 0 === (int) $adopt_after['attempts']
			? 'PASS' : 'FAIL',
	'' === (string) $adopt_before['state'] ? 'absent' : (string) $adopt_before['state'],
	'no' === (string) ( $adopt_run['adopt']['started_unfulfilled'] ?? '' ) ? 'yes' : 'NO',
	(string) ( $adopt_run['adopt']['notification'] ?? 'missing' ),
	(int) ( $adopt_run['adopt']['mails'] ?? -1 ),
	'' === (string) $adopt_after['state'] ? 'absent' : (string) $adopt_after['state'],
	(int) $adopt_after['attempts']
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
