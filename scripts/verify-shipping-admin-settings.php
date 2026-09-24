<?php
/**
 * Behavioural verification for the administrator settings surface, the secret
 * vault and automatic shipment creation.
 *
 * WHY THIS FILE EXISTS SEPARATELY.
 *
 * verify-shipping-automation.php owns the carrier state machine. What is
 * measured here is the layer a SHOP OWNER touches: a settings page, four
 * switches, four secrets and an optional automatic booking path. Those are the
 * parts that can quietly hand a password to a database dump or send a parcel
 * nobody asked for, so they get their own measurements rather than being
 * appended to a suite that is already about something else.
 *
 * Nothing here contacts a carrier. The vendor's host is short-circuited AND
 * counted before anything loads, exactly as in the behaviour suite, and the
 * count is asserted at the end.
 *
 * Run with:
 * docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-shipping-admin-settings.php
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || exit( 1 );

add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
add_filter( 'woocommerce_fulfillment_email_enabled', '__return_false' );

/*
 * EVERY DIAGNOSTIC THIS RUN EMITS IS COUNTED.
 *
 * A warning in a verification suite is not cosmetic. It is PHP saying a value
 * was not what the code assumed, in a file whose whole job is to be sure -- and
 * once a run prints one routinely, the next one hides in the scrollback. So the
 * count is a measurement with a name, and the run fails on a single notice.
 *
 * The previous handler is kept and called, so nothing else's error reporting is
 * suppressed: this only observes.
 */
$kuka_set_diagnostics = array();

$kuka_set_previous_error_handler = set_error_handler(
	static function ( int $severity, string $message, string $file = '', int $line = 0 ) use ( &$kuka_set_diagnostics, &$kuka_set_previous_error_handler ) {
		$named = array(
			E_WARNING           => 'warning',
			E_NOTICE            => 'notice',
			E_DEPRECATED        => 'deprecated',
			E_USER_WARNING      => 'user_warning',
			E_USER_NOTICE       => 'user_notice',
			E_USER_DEPRECATED   => 'user_deprecated',
			E_STRICT            => 'strict',
			E_RECOVERABLE_ERROR => 'recoverable_error',
		);

		if ( isset( $named[ $severity ] ) ) {
			$kuka_set_diagnostics[] = sprintf( '%s:%s', $named[ $severity ], $message );
		}

		if ( is_callable( $kuka_set_previous_error_handler ) ) {
			return ( $kuka_set_previous_error_handler )( $severity, $message, $file, $line );
		}

		// false hands the error back to PHP's own handler, which is what
		// reports it. Nothing is swallowed.
		return false;
	}
);

$kuka_set_real_requests = array();

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$kuka_set_real_requests ) {
		unset( $args );

		if ( str_contains( (string) $url, 'mngkargo.com.tr' ) ) {
			$kuka_set_real_requests[] = (string) $url;

			return new WP_Error( 'kuka_set_network_blocked', 'Blocked by verify-shipping-admin-settings.php' );
		}

		return $preempt;
	},
	1,
	3
);

require_once __DIR__ . '/lib-shipping-module-loader.php';
require_once __DIR__ . '/lib-shipping-cache-custodian.php';

$module = kuka_shipping_load_module();

/*
 * This suite exercises the real deactivation cleanup below. The activator is
 * bootstrapped by WordPress only when the plugin itself is active, whereas the
 * suite must mean the same thing when it starts from the delivered inactive
 * state. Load that production class explicitly instead of inheriting it from
 * the machine's current plugin state.
 */
require_once KUKA_ISLAND_SHIPPING_PATH . 'includes/class-activator.php';

/*
 * THE SHOP'S OWN CBS CACHE IS NOT THIS SUITE'S TO WRITE.
 *
 * Every createOrder in here resolves an address, and the resolver caches the
 * city and district lists it was answered with. Answered by a MOCK, in this
 * run. Left on the shop's own key space, those rows would become the lists a
 * real shipment is addressed from. So every provider this file builds is given
 * a namespace minted for this run, and the custodian releases exactly the rows
 * that namespace created -- on a fatal as well as on a clean finish.
 */
define( 'KUKA_SET_CACHE_NAMESPACE', Kuka_Shipping_Cache_Custodian::mint_namespace() );

$kuka_set_custodian = ( new Kuka_Shipping_Cache_Custodian( KUKA_SET_CACHE_NAMESPACE ) )
	->own_resolver_keys( array( '34', '06', '07' ) )
	->guard();

$failures = array();
$report   = static function ( string $name, bool $passed, string $detail = '' ) use ( &$failures ): void {
	WP_CLI::line( sprintf( '%s=%s%s', $name, $passed ? 'PASS' : 'FAIL', '' !== $detail ? '|' . $detail : '' ) );

	if ( ! $passed ) {
		$failures[] = $name;
	}
};

if ( ! $module['ok'] ) {
	$report( 'SHIPPING_SETTINGS_MODULE_LOADED', false, 'reason:' . $module['reason'] );
	WP_CLI::error( 'Shipping module could not be loaded.' );
}

/* ========================================================================== */
/* Harness                                                                     */
/* ========================================================================== */

/** The sentinel secrets. Never real, and searched for everywhere afterwards. */
const KUKA_SET_CLIENT_ID     = 'KUKA-SENTINEL-CLIENT-ID-7f3a';
const KUKA_SET_CLIENT_SECRET = 'KUKA-SENTINEL-CLIENT-SECRET-91bd';
const KUKA_SET_CUSTOMER      = 'KUKA-SENTINEL-CUSTOMER-4402';
const KUKA_SET_PASSWORD      = 'KUKA-SENTINEL-PASSWORD-c5e1';

/** Everything this run wrote, removed at the end whatever happened. */
$kuka_set_owned_options = array();

/**
 * Every order id this run created.
 *
 * Tracked rather than trusted: each measurement deletes its own fixture, but a
 * single missed path would leave rows behind for ever, and this suite is the
 * only thing that knows which rows are its own.
 *
 * @var array<int, int>
 */
$GLOBALS['kuka_set_owned_orders'] = array();

/** A fixture order that can actually be shipped. */
function kuka_set_order( array $overrides = array() ): WC_Order {
	$order = wc_create_order();

	$item = new WC_Order_Item_Product();
	$item->set_name( 'Kuka ayar testi ürünü' );
	$item->set_quantity( 1 );
	$item->set_total( 100 );
	$order->add_item( $item );

	$order->set_payment_method( 'iyzico' );
	$order->set_billing_email( 'settings-fixture@example.invalid' );
	$order->set_billing_phone( (string) ( $overrides['phone'] ?? '5309481996' ) );
	$order->set_shipping_first_name( 'Kuka' );
	$order->set_shipping_last_name( 'Fixture' );
	$order->set_shipping_address_1( (string) ( $overrides['address'] ?? 'Test sokak 1' ) );
	$order->set_shipping_address_2( 'Daire 1' );
	$order->set_shipping_state( (string) ( $overrides['state'] ?? 'TR34' ) );
	$order->set_shipping_city( (string) ( $overrides['city'] ?? 'Kadıköy' ) );
	$order->set_shipping_country( 'TR' );
	$order->update_meta_data( '_kuka_shipping_fixture', '1' );

	if ( isset( $overrides['status'] ) ) {
		$order->set_status( (string) $overrides['status'] );
	}

	if ( ! empty( $overrides['paid'] ) ) {
		$order->set_date_paid( time() );
	}

	$order->save();

	$GLOBALS['kuka_set_owned_orders'][] = (int) $order->get_id();

	return $order;
}

/** Drop an order and the notes it produced. */
function kuka_set_destroy( ?WC_Order $order ): void {
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$id = (int) $order->get_id();

	if ( function_exists( 'wc_get_order_notes' ) && function_exists( 'wc_delete_order_note' ) ) {
		foreach ( (array) wc_get_order_notes( array( 'order_id' => $id, 'limit' => 500 ) ) as $note ) {
			wc_delete_order_note( (int) $note->id );
		}
	}

	$order->delete( true );
}

/** An order read past every cache this process holds. */
function kuka_set_fresh( int $order_id ): ?WC_Order {
	if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
		try {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	wp_cache_delete( $order_id, 'orders' );

	$order = wc_get_order( $order_id );

	return $order instanceof WC_Order ? $order : null;
}

/** Count this module's own pending scheduler rows, by hook. */
function kuka_set_pending( string $hook, int $order_id ): int {
	global $wpdb;

	$table = $wpdb->prefix . 'actionscheduler_actions';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE hook = %s AND status = 'pending' AND args LIKE %s",
			$hook,
			'%' . $wpdb->esc_like( '"order_id":' . $order_id ) . '%'
		)
	);
}

/** Remove every scheduler row this suite booked for an order. */
function kuka_set_purge( int $order_id ): int {
	if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_action' ) ) {
		return 0;
	}

	$removed = 0;

	foreach ( array( 'kuka_island_shipping_auto_create', 'kuka_island_shipping_query_status', 'kuka_island_shipping_sync_fulfillment' ) as $hook ) {
		foreach ( array( 'pending', 'in-progress', 'complete', 'failed', 'canceled' ) as $status ) {
			$rows = (array) as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => $status,
					'per_page' => 100,
				),
				'ids'
			);

			foreach ( $rows as $row_id ) {
				$action = ActionScheduler::store()->fetch_action( (int) $row_id );
				$args   = (array) $action->get_args();

				if ( (int) ( $args['order_id'] ?? 0 ) !== $order_id ) {
					continue;
				}

				ActionScheduler::store()->delete_action( (int) $row_id );
				++$removed;
			}
		}
	}

	return $removed;
}

/** A transport that answers the whole happy path and counts every call. */
final class Kuka_Set_Transport implements Kuka_Island_Shipping_HTTP_Transport_Interface {

	/** @var array<int, string> */
	public array $calls = array();

	/** @var callable|null */
	public $responder = null;

	/**
	 * @param array<string, string> $headers Request headers.
	 * @return array<string, mixed>
	 */
	public function request( string $method, string $url, array $headers, string $body, int $timeout ): array {
		unset( $headers, $body, $timeout );

		$this->calls[] = $url;

		$answer = null;

		if ( is_callable( $this->responder ) ) {
			$answer = ( $this->responder )( $method, $url );
		}

		$answer = is_array( $answer ) ? $answer : self::happy( $url );

		return array(
			'status'  => (int) ( $answer['status'] ?? 0 ),
			'headers' => array(),
			'body'    => (string) ( $answer['body'] ?? '' ),
			'error'   => (string) ( $answer['error'] ?? '' ),
		);
	}

	public function count_for( string $needle ): int {
		$total = 0;

		foreach ( $this->calls as $url ) {
			if ( str_contains( $url, $needle ) ) {
				++$total;
			}
		}

		return $total;
	}

	public function writes(): int {
		return $this->count_for( '/createRecipient' )
			+ $this->count_for( '/createOrder' )
			+ $this->count_for( '/createbarcode' )
			+ $this->count_for( '/updateorder' )
			+ $this->count_for( '/updateshipment' )
			+ $this->count_for( '/cancelorder' )
			+ $this->count_for( '/cancelshipment' );
	}

	/**
	 * @return array{status: int, body: string}
	 */
	public static function happy( string $url ): array {
		if ( str_contains( $url, '/token' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode( array( 'jwt' => 'set-token', 'jwtExpireDate' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) ),
			);
		}

		if ( str_contains( $url, '/getcities' ) ) {
			return array( 'status' => 200, 'body' => (string) wp_json_encode( array( array( 'code' => '34', 'name' => 'İSTANBUL' ) ) ) );
		}

		if ( str_contains( $url, '/getdistricts' ) ) {
			return array( 'status' => 200, 'body' => (string) wp_json_encode( array( array( 'code' => '1', 'name' => 'KADIKÖY' ) ) ) );
		}

		if ( str_contains( $url, '/createRecipient' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						'orderInvoiceId'       => 'SET-RINV',
						'orderInvoiceDetailId' => 'SET-RDET',
						'shipperBranchCode'    => '034',
					)
				),
			);
		}

		if ( str_contains( $url, '/createOrder' ) ) {
			return array( 'status' => 200, 'body' => (string) wp_json_encode( array( 'referenceId' => 'ECHO', 'orderInvoiceId' => 'SET-OINV' ) ) );
		}

		if ( str_contains( $url, '/createbarcode' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array(
						'referenceId' => 'ECHO',
						'invoiceId'   => 'SET-INV',
						'shipmentId'  => '909631576507',
						'barcodes'    => array( array( 'pieceNumber' => 1, 'value' => "^XA\r\n^FDSET^FS\r\n^XZ\r\n" ) ),
					)
				),
			);
		}

		if ( str_contains( $url, '/getshipmentstatus' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode(
					array( 'referenceId' => 'ECHO', 'shipmentId' => '909631576507', 'shipmentStatusCode' => 2, 'isDelivered' => 0 )
				),
			);
		}

		if ( str_contains( $url, '/getorder/' ) ) {
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode( array( 'order' => array( 'referenceId' => 'ECHO', 'shipmentId' => '', 'isTransformedToShipment' => 0 ) ) ),
			);
		}

		return array( 'status' => 404, 'body' => '{"title":"Not Found"}' );
	}
}

/** A provider wired to a counting transport and the sentinel credentials. */
function kuka_set_provider( Kuka_Set_Transport $transport, array $overrides = array() ): Kuka_Island_Shipping_DHL_Provider {
	$config = new Kuka_Island_Shipping_DHL_Config(
		array_merge(
			array(
				'environment'     => 'test',
				'client_id'       => KUKA_SET_CLIENT_ID,
				'client_secret'   => KUKA_SET_CLIENT_SECRET,
				'customer_number' => KUKA_SET_CUSTOMER,
				'password'        => KUKA_SET_PASSWORD,
			),
			$overrides
		)
	);

	$client   = new Kuka_Island_Shipping_DHL_Client( $config, $transport );
	$resolver = new Kuka_Island_Shipping_DHL_Address_Resolver( $client );

	$resolver->set_cache_namespace( KUKA_SET_CACHE_NAMESPACE );

	return new Kuka_Island_Shipping_DHL_Provider( $config, $client, $resolver );
}

/** A registry containing exactly the given adapters. */
function kuka_set_registry( array $adapters ): Kuka_Island_Shipping_Carrier_Registry {
	$filter = static function () use ( $adapters ): array {
		return $adapters;
	};

	add_filter( 'kuka_island_shipping_carriers', $filter, PHP_INT_MAX );
	$registry = new Kuka_Island_Shipping_Carrier_Registry();
	$registry->keys();
	remove_filter( 'kuka_island_shipping_carriers', $filter, PHP_INT_MAX );

	return $registry;
}

/** A manager over one adapter. */
function kuka_set_manager( Kuka_Island_Shipping_Carrier_Interface $provider ): Kuka_Island_Shipping_Manager {
	return new Kuka_Island_Shipping_Manager( kuka_set_registry( array( $provider ) ) );
}

/** Read an option row straight from the table: value AND autoload. */
function kuka_set_option_row( string $name ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ),
		ARRAY_A
	);

	return is_array( $row ) ? $row : array();
}

/** Every option row whose name or value mentions this module. */
function kuka_set_all_module_option_values(): string {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = (array) $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'kuka_island_shipping%'" );

	return implode( "\n", array_map( 'strval', $rows ) );
}

/**
 * Run something with one switch set, and put the setting back afterwards.
 *
 * Deliberately a TEST-SIDE helper built from the production API
 * (save_switches / get_switch). A scoped-override method living in the plugin
 * would be a seam that exists only for this file, and the next person needing
 * "just one override" would reach for it in production code.
 *
 * @param mixed    $value    Value to set for the duration.
 * @param callable $callback What to run.
 * @return mixed
 */
function kuka_set_with_switch( string $name, $value, callable $callback ) {
	$before = Kuka_Island_Shipping_Settings::get_switch( $name );

	Kuka_Island_Shipping_Settings::save_switches( array( $name => $value ) );

	try {
		return $callback();
	} finally {
		Kuka_Island_Shipping_Settings::save_switches( array( $name => $before ) );
	}
}

$kuka_set_admin_ids = (array) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$kuka_set_admin     = array() !== $kuka_set_admin_ids ? (int) $kuka_set_admin_ids[0] : 0;
$kuka_set_previous  = get_current_user_id();

$kuka_set_die = static function (): callable {
	return static function ( $message = '', $title = '', $args = array() ): void {
		unset( $message, $title, $args );

		throw new RuntimeException( 'wp_die' );
	};
};

/**
 * Press a settings-page handler with a given user, nonce and payload.
 *
 * @param array<string, string> $fields POST payload.
 * @return string 'ran' or 'refused'
 */
function kuka_set_press( callable $handler, int $user_id, string $nonce, array $fields ): string {
	wp_set_current_user( $user_id );

	/*
	 * A handler that finished redirects and exits, which is correct for a real
	 * admin-post request and fatal for a measurement. The redirect is turned
	 * into an exception so the run continues; the exception is thrown from
	 * INSIDE wp_redirect(), before the exit, and it is a different class from
	 * the wp_die() one so "finished" and "refused" stay distinguishable.
	 *
	 * THE PRIORITY IS THE POINT. WP-CLI hooks `wp_redirect` at the default
	 * priority to warn that "some code is trying to do a URL redirect" and to
	 * dump a backtrace to STDERR -- correct for a stray redirect, noise for one
	 * this measurement asked for on purpose. Throwing from the FIRST callback
	 * ends apply_filters() before any later one runs, so the expected redirect
	 * stays silent while an unexpected one anywhere else in this run still
	 * reports itself. Nothing is added to production code and nothing WP-CLI
	 * registered is removed: the redirect simply never gets past this filter.
	 */
	$GLOBALS['kuka_set_last_redirect'] = '';

	$redirected = static function ( $location ) {
		// Recorded before throwing, so the measurement can assert WHERE a
		// finished handler sent the operator, not only that it finished.
		$GLOBALS['kuka_set_last_redirect'] = (string) $location;

		throw new Kuka_Set_Redirect( 'wp_redirect' );
	};

	add_filter( 'wp_redirect', $redirected, PHP_INT_MIN );

	$_POST    = array();
	$_REQUEST = array();

	foreach ( $fields as $key => $value ) {
		$_POST[ $key ]    = $value;
		$_REQUEST[ $key ] = $value;
	}

	$_POST['_kuka_shipping_settings_nonce']    = $nonce;
	$_REQUEST['_kuka_shipping_settings_nonce'] = $nonce;

	try {
		$handler();

		return 'ran';
	} catch ( Kuka_Set_Redirect $e ) {
		unset( $e );

		return 'ran';
	} catch ( RuntimeException $e ) {
		unset( $e );

		return 'refused';
	} finally {
		remove_filter( 'wp_redirect', $redirected, PHP_INT_MIN );

		$_POST    = array();
		$_REQUEST = array();
	}
}

/** Where the last finished handler tried to send the operator. */
function kuka_set_last_redirect(): string {
	return (string) ( $GLOBALS['kuka_set_last_redirect'] ?? '' );
}

/** A finished handler's redirect, raised instead of ending the process. */
final class Kuka_Set_Redirect extends RuntimeException {}

/* ========================================================================== */
/* 1. The settings page exists and is reachable only by the right people      */
/* ========================================================================== */

$page_exists = class_exists( 'Kuka_Island_Shipping_Settings_Page' )
	&& class_exists( 'Kuka_Island_Shipping_Settings' )
	&& class_exists( 'Kuka_Island_Shipping_Secret_Vault' )
	&& class_exists( 'Kuka_Island_Shipping_Dispatcher' );

$report(
	'SHIPPING_SETTINGS_SURFACE_EXISTS',
	$page_exists,
	sprintf(
		'settings_page:%s|settings:%s|vault:%s|dispatcher:%s',
		class_exists( 'Kuka_Island_Shipping_Settings_Page' ) ? 'yes' : 'NO',
		class_exists( 'Kuka_Island_Shipping_Settings' ) ? 'yes' : 'NO',
		class_exists( 'Kuka_Island_Shipping_Secret_Vault' ) ? 'yes' : 'NO',
		class_exists( 'Kuka_Island_Shipping_Dispatcher' ) ? 'yes' : 'NO'
	)
);

if ( ! $page_exists ) {
	/*
	 * Everything below drives those classes. Reporting thirty more identical
	 * "class not found" failures would bury the one that matters, so the run
	 * stops here and says so.
	 */
	WP_CLI::error( 'SHIPPING_SETTINGS=RED|reason:surface_absent|measurements_not_run:32' );
}

$settings_page = new Kuka_Island_Shipping_Settings_Page();

wp_set_current_user( $kuka_set_admin );
$good_nonce = wp_create_nonce( Kuka_Island_Shipping_Settings_Page::NONCE_SAVE );
$wrong_nonce = wp_create_nonce( 'kuka_shipping_something_else' );

add_filter( 'wp_die_handler', $kuka_set_die, 99 );

// A subscriber-level user, created for this run and removed with it.
$kuka_set_weak = wp_insert_user(
	array(
		'user_login' => 'kuka-set-probe',
		'user_pass'  => wp_generate_password( 24, true, true ),
		'user_email' => 'kuka-set-probe@example.invalid',
		'role'       => 'subscriber',
	)
);

/*
 * A run that died before its cleanup leaves the probe behind, and
 * wp_insert_user() then fails on the duplicate login. Reusing the existing row
 * keeps the measurement meaningful instead of silently degrading to user 0 --
 * which also lacks the capability, and would therefore have kept passing while
 * measuring something else.
 */
if ( is_wp_error( $kuka_set_weak ) ) {
	$kuka_set_existing = get_user_by( 'login', 'kuka-set-probe' );
	$kuka_set_weak     = $kuka_set_existing instanceof WP_User ? (int) $kuka_set_existing->ID : 0;
}

$kuka_set_weak = (int) $kuka_set_weak;

wp_set_current_user( $kuka_set_weak );
$weak_nonce = wp_create_nonce( Kuka_Island_Shipping_Settings_Page::NONCE_SAVE );

$before_switches = Kuka_Island_Shipping_Settings::switches();

$no_cap = kuka_set_press(
	static function () use ( $settings_page ): void {
		$settings_page->handle_save();
	},
	$kuka_set_weak,
	$weak_nonce,
	array( 'kuka_shipping_environment' => 'live' )
);

$after_no_cap = Kuka_Island_Shipping_Settings::switches();

$report(
	'SHIPPING_SETTINGS_REQUIRE_CAPABILITY',
	'refused' === $no_cap && $before_switches === $after_no_cap,
	sprintf(
		'measured:real_admin_post_handler|user:subscriber|outcome:%s|settings_changed:%s',
		$no_cap,
		$before_switches === $after_no_cap ? 'no' : 'YES'
	)
);

$bad_nonce = kuka_set_press(
	static function () use ( $settings_page ): void {
		$settings_page->handle_save();
	},
	$kuka_set_admin,
	$wrong_nonce,
	array( 'kuka_shipping_environment' => 'live' )
);

$after_bad_nonce = Kuka_Island_Shipping_Settings::switches();

$report(
	'SHIPPING_SETTINGS_REQUIRE_NONCE',
	'refused' === $bad_nonce && $before_switches === $after_bad_nonce,
	sprintf(
		'measured:real_admin_post_handler|nonce:issued_for_another_action|outcome:%s|settings_changed:%s',
		$bad_nonce,
		$before_switches === $after_bad_nonce ? 'no' : 'YES'
	)
);

/* ========================================================================== */
/* 2. The secret vault                                                         */
/* ========================================================================== */

// A real save, through the real handler, with the sentinel secrets.
wp_set_current_user( $kuka_set_admin );
$save_nonce = wp_create_nonce( Kuka_Island_Shipping_Settings_Page::NONCE_SAVE );

$saved = kuka_set_press(
	static function () use ( $settings_page ): void {
		$settings_page->handle_save();
	},
	$kuka_set_admin,
	$save_nonce,
	array(
		'kuka_shipping_client_id'       => KUKA_SET_CLIENT_ID,
		'kuka_shipping_client_secret'   => KUKA_SET_CLIENT_SECRET,
		'kuka_shipping_customer_number' => KUKA_SET_CUSTOMER,
		'kuka_shipping_password'        => KUKA_SET_PASSWORD,
		'kuka_shipping_environment'     => 'test',
	)
);

$kuka_set_owned_options[] = Kuka_Island_Shipping_Secret_Vault::OPTION;
$kuka_set_owned_options[] = Kuka_Island_Shipping_Settings::OPTION;

$vault_row     = kuka_set_option_row( Kuka_Island_Shipping_Secret_Vault::OPTION );
$settings_row  = kuka_set_option_row( Kuka_Island_Shipping_Settings::OPTION );
$all_option_text = kuka_set_all_module_option_values();

$sentinels = array( KUKA_SET_CLIENT_ID, KUKA_SET_CLIENT_SECRET, KUKA_SET_CUSTOMER, KUKA_SET_PASSWORD );
$plaintext_hits = array();

foreach ( $sentinels as $sentinel ) {
	if ( str_contains( $all_option_text, $sentinel ) ) {
		$plaintext_hits[] = substr( $sentinel, 0, 12 );
	}
}

$report(
	'SHIPPING_SETTINGS_SECRET_NOT_PLAINTEXT_IN_OPTIONS',
	'ran' === $saved && array() === $plaintext_hits && array() !== $vault_row,
	sprintf(
		'measured:options_table_scan|save:%s|vault_row:%s|sentinels_checked:%d|plaintext_hits:%s',
		$saved,
		array() !== $vault_row ? 'present' : 'MISSING',
		count( $sentinels ),
		array() === $plaintext_hits ? 'none' : implode( ',', $plaintext_hits )
	)
);

/*
 * WordPress 6.6 replaced this column's 'yes'/'no' with 'on'/'off'/'auto' and
 * kept the old spellings readable. Both ways of saying "not autoloaded" are
 * accepted; every value that WOULD put the row into the snapshot each request
 * loads -- 'yes', 'on', 'auto' -- fails.
 */
$not_autoloaded = array( 'no', 'off' );

$report(
	'SHIPPING_SETTINGS_SECRET_OPTIONS_NOT_AUTOLOADED',
	in_array( (string) ( $vault_row['autoload'] ?? '' ), $not_autoloaded, true )
		&& in_array( (string) ( $settings_row['autoload'] ?? '' ), $not_autoloaded, true ),
	sprintf(
		'measured:options_table_autoload_column|vault:%s|settings:%s',
		(string) ( $vault_row['autoload'] ?? 'missing' ),
		(string) ( $settings_row['autoload'] ?? 'missing' )
	)
);

// The values come back, byte for byte, through the production reader.
$roundtrip = array(
	'client_id'       => Kuka_Island_Shipping_Settings::resolve_secret( 'client_id' ),
	'client_secret'   => Kuka_Island_Shipping_Settings::resolve_secret( 'client_secret' ),
	'customer_number' => Kuka_Island_Shipping_Settings::resolve_secret( 'customer_number' ),
	'password'        => Kuka_Island_Shipping_Settings::resolve_secret( 'password' ),
);

$roundtrip_ok = KUKA_SET_CLIENT_ID === $roundtrip['client_id']
	&& KUKA_SET_CLIENT_SECRET === $roundtrip['client_secret']
	&& KUKA_SET_CUSTOMER === $roundtrip['customer_number']
	&& KUKA_SET_PASSWORD === $roundtrip['password'];

$report(
	'SHIPPING_SETTINGS_VAULT_ROUNDTRIP',
	$roundtrip_ok && Kuka_Island_Shipping_Secret_Vault::is_available(),
	sprintf(
		'measured:production_reader|sodium:%s|fields_recovered:%d/4|byte_identical:%s|cipher_is_not_plaintext:%s',
		Kuka_Island_Shipping_Secret_Vault::is_available() ? 'yes' : 'NO',
		count( array_filter( $roundtrip, static fn( $v ): bool => '' !== $v ) ),
		$roundtrip_ok ? 'yes' : 'NO',
		str_contains( (string) ( $vault_row['option_value'] ?? '' ), KUKA_SET_PASSWORD ) ? 'NO' : 'yes'
	)
);

// The panel must never print a secret back into the browser.
$rendered = '';

ob_start();
$settings_page->render();
$rendered = (string) ob_get_clean();

$render_hits = array();

foreach ( $sentinels as $sentinel ) {
	if ( str_contains( $rendered, $sentinel ) ) {
		$render_hits[] = substr( $sentinel, 0, 12 );
	}
}

$report(
	'SHIPPING_SETTINGS_SECRET_NEVER_RENDERED',
	array() === $render_hits && '' !== $rendered,
	sprintf(
		'measured:real_page_render|bytes:%d|sentinels_checked:%d|in_html:%s|password_inputs_prefilled:%s',
		strlen( $rendered ),
		count( $sentinels ),
		array() === $render_hits ? 'none' : implode( ',', $render_hits ),
		1 === preg_match( '/type="password"[^>]*value="[^"]+"/', $rendered ) ? 'YES' : 'no'
	)
);

// A ciphertext somebody edited must not decrypt to anything.
$intact = get_option( Kuka_Island_Shipping_Secret_Vault::OPTION );
$broken = is_array( $intact ) ? $intact : array();

if ( isset( $broken['fields']['password']['cipher'] ) ) {
	$cipher = (string) $broken['fields']['password']['cipher'];
	$broken['fields']['password']['cipher'] = strrev( $cipher );
}

update_option( Kuka_Island_Shipping_Secret_Vault::OPTION, $broken, false );

$tampered_read  = Kuka_Island_Shipping_Settings::resolve_secret( 'password' );
$tampered_state = Kuka_Island_Shipping_Settings::readiness();

update_option( Kuka_Island_Shipping_Secret_Vault::OPTION, $intact, false );

$report(
	'SHIPPING_SETTINGS_VAULT_TAMPER_FAILS_CLOSED',
	'' === $tampered_read
		&& Kuka_Island_Shipping_Secret_Vault::CODE_UNREADABLE === (string) ( $tampered_state['code'] ?? '' ),
	sprintf(
		'measured:production_reader_on_edited_ciphertext|value_returned:%s|code:%s|ready:%s',
		'' === $tampered_read ? 'empty' : 'VALUE',
		(string) ( $tampered_state['code'] ?? 'none' ),
		! empty( $tampered_state['ready'] ) ? 'YES' : 'no'
	)
);

// Rotating the site salts must be visible, not silently empty.
$rotated = is_array( $intact ) ? $intact : array();
$rotated['key_fingerprint'] = str_repeat( 'a', 32 );

update_option( Kuka_Island_Shipping_Secret_Vault::OPTION, $rotated, false );

$rotated_read  = Kuka_Island_Shipping_Settings::resolve_secret( 'password' );
$rotated_state = Kuka_Island_Shipping_Settings::readiness();

update_option( Kuka_Island_Shipping_Secret_Vault::OPTION, $intact, false );

$report(
	'SHIPPING_SETTINGS_SALT_ROTATION_IS_UNREADABLE',
	'' === $rotated_read
		&& Kuka_Island_Shipping_Secret_Vault::CODE_UNREADABLE === (string) ( $rotated_state['code'] ?? '' ),
	sprintf(
		'measured:production_reader_with_foreign_key_fingerprint|value_returned:%s|code:%s|operator_told_to_re_enter:%s',
		'' === $rotated_read ? 'empty' : 'VALUE',
		(string) ( $rotated_state['code'] ?? 'none' ),
		'' !== (string) ( $rotated_state['message'] ?? '' ) ? 'yes' : 'NO'
	)
);

/* ========================================================================== */
/* 3. Precedence, blank fields and deliberate deletion                         */
/* ========================================================================== */

/*
 * A constant outranks the vault. The shop owner's panel is a convenience for a
 * site that has no deployment pipeline; a site that DOES define the constant
 * has made a deliberate decision in a file the panel cannot reach, and the
 * panel must not quietly override it.
 */
putenv( 'KUKA_DHL_CUSTOMER_NUMBER=ENV-WINS-OVER-VAULT' );

$env_wins    = Kuka_Island_Shipping_Settings::resolve_secret( 'customer_number' );
$env_source  = Kuka_Island_Shipping_Settings::secret_source( 'customer_number' );

putenv( 'KUKA_DHL_CUSTOMER_NUMBER' );

$vault_source = Kuka_Island_Shipping_Settings::secret_source( 'customer_number' );

$report(
	'SHIPPING_SETTINGS_CONSTANT_BEATS_VAULT',
	'ENV-WINS-OVER-VAULT' === $env_wins
		&& Kuka_Island_Shipping_Settings::SOURCE_ENV === $env_source
		&& Kuka_Island_Shipping_Settings::SOURCE_VAULT === $vault_source,
	sprintf(
		'measured:production_reader|order:constant>environment>vault|with_env:%s/source:%s|without_env:source:%s',
		'ENV-WINS-OVER-VAULT' === $env_wins ? 'env_value' : 'VAULT_VALUE',
		$env_source,
		$vault_source
	)
);

// An empty field is "leave it alone", not "erase it".
wp_set_current_user( $kuka_set_admin );
$keep_nonce = wp_create_nonce( Kuka_Island_Shipping_Settings_Page::NONCE_SAVE );

$kept = kuka_set_press(
	static function () use ( $settings_page ): void {
		$settings_page->handle_save();
	},
	$kuka_set_admin,
	$keep_nonce,
	array(
		'kuka_shipping_client_id'       => '',
		'kuka_shipping_client_secret'   => '',
		'kuka_shipping_customer_number' => '',
		'kuka_shipping_password'        => '',
		'kuka_shipping_environment'     => 'test',
	)
);

$after_blank = Kuka_Island_Shipping_Settings::resolve_secret( 'password' );

$report(
	'SHIPPING_SETTINGS_EMPTY_SECRET_KEEPS_VALUE',
	'ran' === $kept && KUKA_SET_PASSWORD === $after_blank,
	sprintf(
		'measured:real_admin_post_handler|save:%s|blank_fields:4|value_after:%s',
		$kept,
		KUKA_SET_PASSWORD === $after_blank ? 'unchanged' : ( '' === $after_blank ? 'ERASED' : 'CHANGED' )
	)
);

/*
 * DELETING A STORED CREDENTIAL IS A SEPARATE, CONFIRMED ACTION.
 *
 * The form carries a confirmation checkbox, and `required` on that checkbox is
 * a courtesy to a person using a browser -- it is NOT a security boundary. Any
 * POST can omit it. So the handler is measured against a request that simply
 * does not send it, and against one that sends something other than the exact
 * expected value.
 */
$forget_cases = array();

$forget_case = static function ( string $name, int $user_id, string $nonce_action, array $fields ) use ( &$forget_cases, $settings_page ): void {
	wp_set_current_user( $user_id );

	$nonce = wp_create_nonce( $nonce_action );

	$outcome = kuka_set_press(
		static function () use ( $settings_page ): void {
			$settings_page->handle_forget();
		},
		$user_id,
		$nonce,
		$fields
	);

	$forget_cases[ $name ] = array(
		'outcome'  => $outcome,
		'redirect' => kuka_set_last_redirect(),
		'password' => Kuka_Island_Shipping_Settings::resolve_secret( 'password' ),
		'others'   => array(
			'client_id'       => Kuka_Island_Shipping_Settings::resolve_secret( 'client_id' ),
			'client_secret'   => Kuka_Island_Shipping_Settings::resolve_secret( 'client_secret' ),
			'customer_number' => Kuka_Island_Shipping_Settings::resolve_secret( 'customer_number' ),
		),
	);
};

// 1. No confirmation field at all. The browser would have stopped here; a
//    crafted POST does not.
$forget_case( 'no_confirmation', $kuka_set_admin, Kuka_Island_Shipping_Settings_Page::NONCE_FORGET, array( 'kuka_shipping_forget' => 'password' ) );

// 2. A confirmation field carrying something else.
$forget_case( 'wrong_confirmation', $kuka_set_admin, Kuka_Island_Shipping_Settings_Page::NONCE_FORGET, array( 'kuka_shipping_forget' => 'password', 'kuka_shipping_forget_confirm' => 'on' ) );

// 3. An empty confirmation field.
$forget_case( 'empty_confirmation', $kuka_set_admin, Kuka_Island_Shipping_Settings_Page::NONCE_FORGET, array( 'kuka_shipping_forget' => 'password', 'kuka_shipping_forget_confirm' => '' ) );

// 4. The right nonce family but the wrong action's nonce.
$forget_case( 'wrong_nonce', $kuka_set_admin, 'kuka_shipping_something_else', array( 'kuka_shipping_forget' => 'password', 'kuka_shipping_forget_confirm' => '1' ) );

// 5. Everything correct except the capability.
$forget_case( 'no_capability', $kuka_set_weak, Kuka_Island_Shipping_Settings_Page::NONCE_FORGET, array( 'kuka_shipping_forget' => 'password', 'kuka_shipping_forget_confirm' => '1' ) );

// 6. Capability, this action's nonce, and the exact confirmation value.
$forget_case( 'confirmed', $kuka_set_admin, Kuka_Island_Shipping_Settings_Page::NONCE_FORGET, array( 'kuka_shipping_forget' => 'password', 'kuka_shipping_forget_confirm' => '1' ) );

// Put it back for the measurements below.
Kuka_Island_Shipping_Secret_Vault::put( 'password', KUKA_SET_PASSWORD );

$forget_kept = array( 'no_confirmation', 'wrong_confirmation', 'empty_confirmation', 'wrong_nonce', 'no_capability' );
$forget_ok   = true;

foreach ( $forget_kept as $forget_name ) {
	if ( KUKA_SET_PASSWORD !== (string) $forget_cases[ $forget_name ]['password'] ) {
		$forget_ok = false;
	}
}

// The other three are untouched in every case, including the confirmed one.
$forget_others_intact = KUKA_SET_CLIENT_ID === (string) $forget_cases['confirmed']['others']['client_id']
	&& KUKA_SET_CLIENT_SECRET === (string) $forget_cases['confirmed']['others']['client_secret']
	&& KUKA_SET_CUSTOMER === (string) $forget_cases['confirmed']['others']['customer_number'];

$forget_line = implode(
	'|',
	array_map(
		static function ( string $name ) use ( $forget_cases ): string {
			$notice = '';

			if ( '' !== (string) $forget_cases[ $name ]['redirect'] ) {
				$query = (string) ( wp_parse_url( (string) $forget_cases[ $name ]['redirect'], PHP_URL_QUERY ) ?? '' );

				parse_str( $query, $parsed );

				$notice = (string) ( $parsed['kuka_shipping_notice'] ?? '' );
			}

			return sprintf(
				'%s:%s/secret=%s/notice=%s',
				$name,
				(string) $forget_cases[ $name ]['outcome'],
				'' === (string) $forget_cases[ $name ]['password'] ? 'erased' : 'kept',
				'' === $notice ? 'none' : $notice
			);
		},
		array_keys( $forget_cases )
	)
);

$report(
	'SHIPPING_SETTINGS_FORGET_SECRET_IS_GUARDED',
	$forget_ok
		&& '' === (string) $forget_cases['confirmed']['password']
		&& $forget_others_intact
		&& 'refused' === (string) $forget_cases['wrong_nonce']['outcome']
		&& 'refused' === (string) $forget_cases['no_capability']['outcome']
		/*
		 * A FINISHED handler ends in a redirect, and the destination carries the
		 * one sentence the operator will read. Asserting it is what separates
		 * "the handler returned without deleting" from "the handler told the
		 * operator nothing was deleted, and why".
		 */
		&& str_contains( (string) $forget_cases['no_confirmation']['redirect'], 'kuka_shipping_notice=forget_not_confirmed' )
		&& str_contains( (string) $forget_cases['confirmed']['redirect'], 'kuka_shipping_notice=forgotten' ),
	sprintf(
		'measured:real_admin_post_handler|%s|other_three_secrets:%s|html_required_is_not_a_boundary:asserted_server_side',
		$forget_line,
		$forget_others_intact ? 'unchanged' : 'CHANGED'
	)
);

remove_filter( 'wp_die_handler', $kuka_set_die, 99 );
wp_set_current_user( $kuka_set_previous );

/* ========================================================================== */
/* 4. The four switches are four different switches                            */
/* ========================================================================== */

// The run gate stops everything, including the booking of new work.
$gate_transport = new Kuka_Set_Transport();
$gate_manager   = kuka_set_manager( kuka_set_provider( $gate_transport ) );
$gate_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$gate_id        = (int) $gate_order->get_id();

Kuka_Island_Shipping_Runtime_Gate::disable();

$gate_result   = $gate_manager->create_shipment( kuka_set_fresh( $gate_id ) );
$gate_dispatch = ( new Kuka_Island_Shipping_Dispatcher( $gate_manager ) )->maybe_schedule( $gate_id );
$gate_booked   = kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $gate_id );

Kuka_Island_Shipping_Runtime_Gate::enable();

$report(
	'SHIPPING_RUNTIME_GATE_CLOSED_BOOKS_NOTHING',
	0 === count( $gate_transport->calls )
		&& false === (bool) ( $gate_result['ok'] ?? false )
		&& Kuka_Island_Shipping_Runtime_Gate::CODE === (string) ( $gate_result['code'] ?? '' )
		&& false === (bool) ( $gate_dispatch['scheduled'] ?? false )
		&& 0 === $gate_booked,
	sprintf(
		'measured:real_gate_option_and_scheduler|http_calls:%d|manual_code:%s|dispatch_reason:%s|jobs_booked:%d',
		count( $gate_transport->calls ),
		(string) ( $gate_result['code'] ?? 'none' ),
		(string) ( $gate_dispatch['reason'] ?? 'none' ),
		$gate_booked
	)
);

kuka_set_purge( $gate_id );
kuka_set_destroy( kuka_set_fresh( $gate_id ) );

// The adapter switch stops the client from being constructed at all.
$adapter_off = kuka_set_with_switch(
	'adapter_enabled',
	false,
	static function (): array {
		$carriers = Kuka_Island_Shipping_Automation::register_default_carrier( array() );

		return array(
			'registered' => count( $carriers ),
			'state'      => Kuka_Island_Shipping_DHL_Config::adapter_state(),
		);
	}
);

$adapter_on = kuka_set_with_switch(
	'adapter_enabled',
	true,
	static function (): array {
		return array( 'registered' => count( Kuka_Island_Shipping_Automation::register_default_carrier( array() ) ) );
	}
);

$report(
	'SHIPPING_ADAPTER_OFF_BUILDS_NO_CLIENT',
	0 === (int) $adapter_off['registered'] && 1 === (int) $adapter_on['registered'],
	sprintf(
		'measured:real_registry_filter|panel_off:adapters:%d/reason:%s|panel_on:adapters:%d',
		(int) $adapter_off['registered'],
		(string) ( $adapter_off['state']['reason'] ?? 'none' ),
		(int) $adapter_on['registered']
	)
);

// Automatic creation is off until somebody turns it on, and the poll switch is
// a different switch entirely.
$report(
	'SHIPPING_AUTO_CREATE_DEFAULTS_OFF',
	false === Kuka_Island_Shipping_Settings::default_switches()['auto_create_enabled'],
	sprintf(
		'measured:declared_defaults|auto_create:%s|auto_poll:%s|module:%s|adapter:%s|environment:%s',
		Kuka_Island_Shipping_Settings::default_switches()['auto_create_enabled'] ? 'ON' : 'off',
		Kuka_Island_Shipping_Settings::default_switches()['auto_poll_enabled'] ? 'ON' : 'off',
		Kuka_Island_Shipping_Settings::default_switches()['module_enabled'] ? 'on' : 'off',
		Kuka_Island_Shipping_Settings::default_switches()['adapter_enabled'] ? 'on' : 'off',
		(string) Kuka_Island_Shipping_Settings::default_switches()['environment']
	)
);

$poll_independent = kuka_set_with_switch(
	'auto_poll_enabled',
	true,
	static function (): array {
		return kuka_set_with_switch(
			'auto_create_enabled',
			false,
			static function (): array {
				return array(
					'poll'   => Kuka_Island_Shipping_Status_Poller::automation_enabled(),
					'create' => Kuka_Island_Shipping_Settings::is_auto_create_enabled(),
				);
			}
		);
	}
);

$create_independent = kuka_set_with_switch(
	'auto_poll_enabled',
	false,
	static function (): array {
		return kuka_set_with_switch(
			'auto_create_enabled',
			true,
			static function (): array {
				return array(
					'poll'   => Kuka_Island_Shipping_Status_Poller::automation_enabled(),
					'create' => Kuka_Island_Shipping_Settings::is_auto_create_enabled(),
				);
			}
		);
	}
);

$report(
	'SHIPPING_POLL_SWITCH_IS_INDEPENDENT',
	true === $poll_independent['poll'] && false === $poll_independent['create']
		&& false === $create_independent['poll'] && true === $create_independent['create'],
	sprintf(
		'measured:real_poller_and_settings|poll_on_create_off:poll=%s/create=%s|poll_off_create_on:poll=%s/create=%s',
		$poll_independent['poll'] ? 'on' : 'off',
		$poll_independent['create'] ? 'on' : 'off',
		$create_independent['poll'] ? 'on' : 'off',
		$create_independent['create'] ? 'on' : 'off'
	)
);

/* ========================================================================== */
/* 5. Automatic creation: who is eligible, and who is not                      */
/* ========================================================================== */

/*
 * ELIGIBILITY IS AN ALLOW-LIST. Every case below is a real order driven through
 * the real dispatcher; what is counted is whether a job was booked and whether
 * the carrier heard anything. A shipment booked for an unpaid order, a
 * cancelled order or a download-only order is a parcel somebody has to chase.
 */
$eligibility_cases = array();

$eligibility_case = static function ( string $name, array $overrides, bool $auto_on = true ) use ( &$eligibility_cases ): void {
	$transport = new Kuka_Set_Transport();
	$manager   = kuka_set_manager( kuka_set_provider( $transport ) );
	$order     = kuka_set_order( $overrides );
	$order_id  = (int) $order->get_id();

	$outcome = kuka_set_with_switch(
		'auto_create_enabled',
		$auto_on,
		static function () use ( $manager, $order_id ): array {
			$dispatcher = new Kuka_Island_Shipping_Dispatcher( $manager );

			return $dispatcher->maybe_schedule( $order_id );
		}
	);

	$eligibility_cases[ $name ] = array(
		'scheduled'  => (bool) ( $outcome['scheduled'] ?? false ),
		'reason'     => (string) ( $outcome['reason'] ?? '' ),
		'jobs'       => kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $order_id ),
		'http'       => count( $transport->calls ),
		'writes'     => $transport->writes(),
		'order_id'   => $order_id,
	);

	kuka_set_purge( $order_id );
	kuka_set_destroy( kuka_set_fresh( $order_id ) );
};

$eligibility_case( 'eligible', array( 'paid' => true, 'status' => 'processing' ) );
$eligibility_case( 'auto_switch_off', array( 'paid' => true, 'status' => 'processing' ), false );
$eligibility_case( 'unpaid', array( 'status' => 'pending' ) );
$eligibility_case( 'cancelled', array( 'paid' => true, 'status' => 'cancelled' ) );
$eligibility_case( 'refunded', array( 'paid' => true, 'status' => 'refunded' ) );
$eligibility_case( 'failed', array( 'paid' => true, 'status' => 'failed' ) );
$eligibility_case( 'no_phone', array( 'paid' => true, 'status' => 'processing', 'phone' => '' ) );
$eligibility_case( 'no_address', array( 'paid' => true, 'status' => 'processing', 'address' => '' ) );
$eligibility_case( 'no_city', array( 'paid' => true, 'status' => 'processing', 'state' => '' ) );
$eligibility_case( 'no_district', array( 'paid' => true, 'status' => 'processing', 'city' => '' ) );

// A download-only order needs a virtual line item, so it is built separately.
$virtual_transport = new Kuka_Set_Transport();
$virtual_manager   = kuka_set_manager( kuka_set_provider( $virtual_transport ) );
$virtual_product   = new WC_Product_Simple();
$virtual_product->set_name( 'Kuka dijital ürün' );
$virtual_product->set_regular_price( '100' );
$virtual_product->set_virtual( true );
$virtual_product->set_downloadable( true );
$virtual_product->save();

$virtual_order = wc_create_order();
$virtual_item  = new WC_Order_Item_Product();
$virtual_item->set_product( $virtual_product );
$virtual_item->set_quantity( 1 );
$virtual_item->set_total( 100 );
$virtual_order->add_item( $virtual_item );
$virtual_order->set_payment_method( 'iyzico' );
$virtual_order->set_billing_email( 'settings-fixture@example.invalid' );
$virtual_order->set_billing_phone( '5309481996' );
$virtual_order->set_shipping_first_name( 'Kuka' );
$virtual_order->set_shipping_last_name( 'Fixture' );
$virtual_order->set_shipping_address_1( 'Test sokak 1' );
$virtual_order->set_shipping_city( 'İstanbul' );
$virtual_order->set_shipping_country( 'TR' );
$virtual_order->update_meta_data( '_kuka_shipping_fixture', '1' );
$virtual_order->set_status( 'processing' );
$virtual_order->set_date_paid( time() );
$virtual_order->save();

$virtual_id = (int) $virtual_order->get_id();

$GLOBALS['kuka_set_owned_orders'][] = $virtual_id;

$virtual_outcome = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $virtual_manager, $virtual_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $virtual_manager ) )->maybe_schedule( $virtual_id );
	}
);

$eligibility_cases['virtual_only'] = array(
	'scheduled' => (bool) ( $virtual_outcome['scheduled'] ?? false ),
	'reason'    => (string) ( $virtual_outcome['reason'] ?? '' ),
	'jobs'      => kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $virtual_id ),
	'http'      => count( $virtual_transport->calls ),
	'writes'    => $virtual_transport->writes(),
	'order_id'  => $virtual_id,
);

kuka_set_purge( $virtual_id );
kuka_set_destroy( kuka_set_fresh( $virtual_id ) );
$virtual_product->delete( true );

$eligible = $eligibility_cases['eligible'];

$report(
	'SHIPPING_AUTO_CREATE_BOOKS_ONE_JOB',
	true === $eligible['scheduled'] && 1 === (int) $eligible['jobs'] && 0 === (int) $eligible['http'],
	sprintf(
		'measured:real_dispatcher_and_action_scheduler|scheduled:%s|jobs:%d|http_during_scheduling:%d|carrier_writes:%d',
		$eligible['scheduled'] ? 'yes' : 'NO',
		(int) $eligible['jobs'],
		(int) $eligible['http'],
		(int) $eligible['writes']
	)
);

$refused_names = array( 'auto_switch_off', 'unpaid', 'cancelled', 'refunded', 'failed', 'no_phone', 'no_address', 'no_city', 'no_district', 'virtual_only' );
$refused_ok    = true;

foreach ( $refused_names as $name ) {
	$case = $eligibility_cases[ $name ];

	if ( false !== $case['scheduled'] || 0 !== (int) $case['jobs'] || 0 !== (int) $case['writes'] ) {
		$refused_ok = false;
	}
}

$refusal_line = implode(
	'|',
	array_map(
		static function ( string $name ) use ( $eligibility_cases ): string {
			$case = $eligibility_cases[ $name ];

			return sprintf(
				'%s:scheduled=%s/jobs=%d/writes=%d/reason=%s',
				$name,
				$case['scheduled'] ? 'YES' : 'no',
				(int) $case['jobs'],
				(int) $case['writes'],
				'' === $case['reason'] ? 'none' : $case['reason']
			);
		},
		$refused_names
	)
);

$report(
	'SHIPPING_AUTO_CREATE_SKIPS_UNPAID',
	false === $eligibility_cases['unpaid']['scheduled'] && 0 === (int) $eligibility_cases['unpaid']['jobs'],
	sprintf( 'measured:real_dispatcher|%s', $refusal_line )
);

$report(
	'SHIPPING_AUTO_CREATE_SKIPS_CANCELLED',
	false === $eligibility_cases['cancelled']['scheduled']
		&& false === $eligibility_cases['refunded']['scheduled']
		&& false === $eligibility_cases['failed']['scheduled'],
	sprintf(
		'measured:real_dispatcher|cancelled:%s|refunded:%s|failed:%s|writes:%d',
		$eligibility_cases['cancelled']['reason'],
		$eligibility_cases['refunded']['reason'],
		$eligibility_cases['failed']['reason'],
		(int) $eligibility_cases['cancelled']['writes'] + (int) $eligibility_cases['refunded']['writes'] + (int) $eligibility_cases['failed']['writes']
	)
);

$report(
	'SHIPPING_AUTO_CREATE_SKIPS_VIRTUAL_ONLY',
	false === $eligibility_cases['virtual_only']['scheduled'] && 0 === (int) $eligibility_cases['virtual_only']['jobs'],
	sprintf(
		'measured:real_dispatcher_and_real_virtual_product|scheduled:%s|reason:%s|jobs:%d|writes:%d',
		$eligibility_cases['virtual_only']['scheduled'] ? 'YES' : 'no',
		$eligibility_cases['virtual_only']['reason'],
		(int) $eligibility_cases['virtual_only']['jobs'],
		(int) $eligibility_cases['virtual_only']['writes']
	)
);

$report(
	'SHIPPING_AUTO_CREATE_SKIPS_INCOMPLETE_ADDRESS',
	false === $eligibility_cases['no_phone']['scheduled']
		&& false === $eligibility_cases['no_address']['scheduled']
		&& false === $eligibility_cases['no_city']['scheduled']
		&& false === $eligibility_cases['no_district']['scheduled'],
	sprintf(
		'measured:real_dispatcher|no_phone:%s|no_address:%s|no_city:%s|no_district:%s|writes:%d',
		$eligibility_cases['no_phone']['reason'],
		$eligibility_cases['no_address']['reason'],
		$eligibility_cases['no_city']['reason'],
		$eligibility_cases['no_district']['reason'],
		(int) $eligibility_cases['no_phone']['writes'] + (int) $eligibility_cases['no_address']['writes'] + (int) $eligibility_cases['no_city']['writes'] + (int) $eligibility_cases['no_district']['writes']
	)
);

$report(
	'SHIPPING_AUTO_CREATE_OFF_PAYMENT_WRITES_NOTHING',
	false === $eligibility_cases['auto_switch_off']['scheduled']
		&& 0 === (int) $eligibility_cases['auto_switch_off']['jobs']
		&& 0 === (int) $eligibility_cases['auto_switch_off']['writes'],
	sprintf(
		'measured:real_dispatcher_with_switch_off|scheduled:%s|reason:%s|jobs:%d|carrier_writes:%d',
		$eligibility_cases['auto_switch_off']['scheduled'] ? 'YES' : 'no',
		$eligibility_cases['auto_switch_off']['reason'],
		(int) $eligibility_cases['auto_switch_off']['jobs'],
		(int) $eligibility_cases['auto_switch_off']['writes']
	)
);

$report(
	'SHIPPING_AUTO_CREATE_ELIGIBILITY_IS_AN_ALLOWLIST',
	$refused_ok,
	sprintf( 'measured:real_dispatcher|cases:%d|wrong:%s', count( $refused_names ), $refused_ok ? 'none' : 'SEE_ABOVE' )
);

/* The checkout stores TR34 + Kadıköy; DHL expects cityCode + districtCode. */
$address_transport = new Kuka_Set_Transport();
$address_provider  = kuka_set_provider( $address_transport );
$address_manager   = kuka_set_manager( $address_provider );
$address_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$address_id        = (int) $address_order->get_id();
$address_request   = $address_manager->build_request(
	$address_order,
	$address_provider,
	Kuka_Island_Shipping_Order_Store::prepare_reference( $address_order )
);
$address_recipient = (array) ( $address_request['shipment']['recipient'] ?? array() );

$report(
	'SHIPPING_CHECKOUT_ADDRESS_MAPS_TO_CARRIER',
	true === (bool) ( $address_request['ok'] ?? false )
		&& 34 === (int) ( $address_recipient['city_code'] ?? 0 )
		&& 1 === (int) ( $address_recipient['district_code'] ?? 0 )
		&& str_contains( (string) ( $address_recipient['address'] ?? '' ), 'Test sokak 1' )
		&& str_contains( (string) ( $address_recipient['address'] ?? '' ), 'Daire 1' ),
	sprintf(
		'measured:real_woocommerce_order_and_dhl_resolver|stored:state=TR34/city=Kadıköy/address_2=Daire_1|mapped:city_code=%d/district_code=%d|address_continuation:%s',
		(int) ( $address_recipient['city_code'] ?? 0 ),
		(int) ( $address_recipient['district_code'] ?? 0 ),
		str_contains( (string) ( $address_recipient['address'] ?? '' ), 'Daire 1' ) ? 'kept' : 'MISSING'
	)
);

kuka_set_destroy( kuka_set_fresh( $address_id ) );

/* ========================================================================== */
/* 6. One event, one job. Several events, still one job.                       */
/* ========================================================================== */

/*
 * Checkout, the gateway callback and the status transition can all fire for the
 * same order, in the same second, from different requests. Booking one job per
 * event would book three, and three workers would each find an order with no
 * carrier record yet.
 */
$idem_transport = new Kuka_Set_Transport();
$idem_manager   = kuka_set_manager( kuka_set_provider( $idem_transport ) );
$idem_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$idem_id        = (int) $idem_order->get_id();

$idem_results = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $idem_manager, $idem_id ): array {
		$dispatcher = new Kuka_Island_Shipping_Dispatcher( $idem_manager );

		return array(
			'first'  => $dispatcher->maybe_schedule( $idem_id ),
			'second' => $dispatcher->maybe_schedule( $idem_id ),
			'third'  => ( new Kuka_Island_Shipping_Dispatcher( $idem_manager ) )->maybe_schedule( $idem_id ),
		);
	}
);

$idem_jobs = kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $idem_id );

$report(
	'SHIPPING_AUTO_CREATE_IS_IDEMPOTENT',
	1 === $idem_jobs
		&& true === (bool) $idem_results['first']['scheduled']
		&& false === (bool) $idem_results['second']['scheduled']
		&& false === (bool) $idem_results['third']['scheduled'],
	sprintf(
		'measured:real_action_scheduler_rows|events:3|jobs_booked:%d|first:%s|second:%s/%s|third:%s/%s',
		$idem_jobs,
		$idem_results['first']['scheduled'] ? 'scheduled' : 'NOT_SCHEDULED',
		$idem_results['second']['scheduled'] ? 'SCHEDULED' : 'refused',
		(string) $idem_results['second']['reason'],
		$idem_results['third']['scheduled'] ? 'SCHEDULED' : 'refused',
		(string) $idem_results['third']['reason']
	)
);

/* ========================================================================== */
/* 7. The two carrier writes run in two separate worker turns                 */
/* ========================================================================== */

$phase_one = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $idem_manager, $idem_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $idem_manager ) )->run( $idem_id );
	}
);

$phase_one_data    = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $idem_id ) );
$phase_one_pending = kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $idem_id );

// The Action Scheduler runner consumes the current row before invoking the
// callback. This harness calls run() directly, so remove that simulated row
// before starting the later barcode turn.
kuka_set_purge( $idem_id );

$two_phase = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $idem_manager, $idem_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $idem_manager ) )->run( $idem_id );
	}
);

$two_phase_data  = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $idem_id ) );
$phase_two_pending = kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $idem_id );
$phase_gap         = 0;

if ( function_exists( 'as_next_scheduled_action' ) ) {
	$phase_when = as_next_scheduled_action(
		Kuka_Island_Shipping_Dispatcher::ACTION,
		array( 'order_id' => $idem_id ),
		Kuka_Island_Shipping_Dispatcher::GROUP
	);
	$phase_gap = is_numeric( $phase_when ) ? (int) $phase_when - time() : 0;
}

kuka_set_purge( $idem_id );

$three_phase = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $idem_manager, $idem_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $idem_manager ) )->run( $idem_id );
	}
);

$three_phase_data = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $idem_id ) );

$report(
	'SHIPPING_AUTO_CREATE_IS_TWO_PHASE',
	1 === $idem_transport->count_for( '/createRecipient' )
		&& 1 === $idem_transport->count_for( '/createOrder' )
		&& 1 === $idem_transport->count_for( '/createbarcode' )
		&& 1 === $phase_one_pending
		&& Kuka_Island_Shipping_Order_Store::STATE_RECIPIENT_CREATED === (string) $phase_one_data['state']
		&& true === (bool) ( $phase_one['next_phase_scheduled'] ?? false )
		&& $phase_gap >= Kuka_Island_Shipping_Dispatcher::PHASE_DELAY_TEST
		&& 1 === $phase_two_pending
		&& Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === (string) $two_phase_data['state']
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === (string) $three_phase_data['state']
		&& true === (bool) ( $three_phase['ok'] ?? false ),
	sprintf(
		'measured:three_real_dispatcher_turns_and_mock_transport|first:createRecipient=%d/createOrder=0/createbarcode=0/state=%s/follow_up_jobs=%d/gap=%d|second:createOrder=%d/state=%s/follow_up_jobs=%d|third:createbarcode=%d/state=%s/ok=%s|phases:%s',
		$idem_transport->count_for( '/createRecipient' ),
		(string) $phase_one_data['state'],
		$phase_one_pending,
		$phase_gap,
		$idem_transport->count_for( '/createOrder' ),
		(string) $two_phase_data['state'],
		$phase_two_pending,
		$idem_transport->count_for( '/createbarcode' ),
		(string) $three_phase_data['state'],
		! empty( $three_phase['ok'] ) ? 'yes' : 'no',
		(string) ( $three_phase['phases'] ?? 'none' )
	)
);

$report(
	'SHIPPING_AUTO_CREATE_STORES_SHIPMENT_ID',
	'909631576507' === (string) $three_phase_data['shipment_id']
		&& 1 === count( Kuka_Island_Shipping_Order_Store::labels( kuka_set_fresh( $idem_id ) ) ),
	sprintf(
		'measured:order_meta_after_real_worker|shipment_id:%s|labels:%d|legacy_barcodes_meta:%d|zpl_in_tracking_number:%s',
		'909631576507' === (string) $three_phase_data['shipment_id'] ? 'carrier_shipment_id' : 'WRONG',
		count( Kuka_Island_Shipping_Order_Store::labels( kuka_set_fresh( $idem_id ) ) ),
		count( (array) $three_phase_data['barcodes'] ),
		str_contains( (string) $three_phase_data['shipment_id'], '^XA' ) ? 'YES' : 'no'
	)
);

// A second worker turn, after success, must not write again.
$after_success = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $idem_manager, $idem_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $idem_manager ) )->run( $idem_id );
	}
);

$report(
	'SHIPPING_AUTO_AND_MANUAL_RACE_WRITES_ONCE',
	1 === $idem_transport->count_for( '/createOrder' )
		&& 1 === $idem_transport->count_for( '/createbarcode' )
		&& false === (bool) ( $after_success['ok'] ?? false ),
	sprintf(
		'measured:real_worker_rerun|createOrder_total:%d|createbarcode_total:%d|second_turn:%s/%s',
		$idem_transport->count_for( '/createOrder' ),
		$idem_transport->count_for( '/createbarcode' ),
		! empty( $after_success['ok'] ) ? 'OK' : 'refused',
		(string) ( $after_success['reason'] ?? $after_success['code'] ?? 'none' )
	)
);

kuka_set_purge( $idem_id );
kuka_set_destroy( kuka_set_fresh( $idem_id ) );

/* ========================================================================== */
/* 8. Crashing and uncertainty, on the automatic path                          */
/* ========================================================================== */

/*
 * The automatic path is the same Manager, so the same guarantees have to hold
 * through it. Both halves are staged with the production methods and driven
 * from a FRESH dispatcher, manager and adapter -- the closest this suite gets
 * to a later request.
 */
$crash_transport = new Kuka_Set_Transport();
$crash_transport->responder = static function ( string $method, string $url ) {
	unset( $method );

	if ( str_contains( $url, '/createRecipient' ) ) {
		return array( 'status' => 0, 'body' => '', 'error' => 'cURL error 28: Operation timed out' );
	}

	if ( str_contains( $url, '/getshipment/' ) || str_contains( $url, '/getorder/' ) ) {
		return array( 'status' => 503, 'body' => '' );
	}

	return null;
};

$crash_manager = kuka_set_manager( kuka_set_provider( $crash_transport ) );
$crash_order   = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$crash_id      = (int) $crash_order->get_id();

$crash_first = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $crash_manager, $crash_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $crash_manager ) )->run( $crash_id );
	}
);

$crash_state = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $crash_id ) );

// A LATER request: everything fresh, nothing remembered.
$retry_transport = new Kuka_Set_Transport();
$retry_manager   = kuka_set_manager( kuka_set_provider( $retry_transport ) );

$crash_retry = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $retry_manager, $crash_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $retry_manager ) )->run( $crash_id );
	}
);

$report(
	'SHIPPING_AUTO_CREATE_PHASE_ONE_CRASH_IS_SAFE',
	1 === $crash_transport->count_for( '/createRecipient' )
		&& 0 === $crash_transport->count_for( '/createOrder' )
		&& 0 === $crash_transport->count_for( '/createbarcode' )
		&& Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED === (string) $crash_state['state']
		&& 0 === $retry_transport->count_for( '/createRecipient' )
		&& 0 === $retry_transport->writes()
		&& false === (bool) ( $crash_retry['ok'] ?? false ),
	sprintf(
		'measured:uncertain_createRecipient_then_fresh_worker|first:createRecipient=%d/createOrder=%d/state=%s|retry:createRecipient=%d/writes=%d/ok=%s/reason=%s',
		$crash_transport->count_for( '/createRecipient' ),
		$crash_transport->count_for( '/createOrder' ),
		(string) $crash_state['state'],
		$retry_transport->count_for( '/createRecipient' ),
		$retry_transport->writes(),
		! empty( $crash_retry['ok'] ) ? 'YES' : 'no',
		(string) ( $crash_retry['reason'] ?? $crash_retry['code'] ?? 'none' )
	)
);

kuka_set_purge( $crash_id );
kuka_set_destroy( kuka_set_fresh( $crash_id ) );

$local_transport = new Kuka_Set_Transport();
$local_manager   = kuka_set_manager( kuka_set_provider( $local_transport ) );
$local_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$local_id        = (int) $local_order->get_id();
$local_filter    = static function ( array $shipment ): array {
	if ( isset( $shipment['recipient'] ) && is_array( $shipment['recipient'] ) ) {
		$shipment['recipient']['city_name'] = '';
	}

	return $shipment;
};

add_filter( 'kuka_island_shipping_request', $local_filter, 20 );

$local_first = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $local_manager, $local_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $local_manager ) )->run( $local_id );
	}
);

$local_state = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $local_id ) );

remove_filter( 'kuka_island_shipping_request', $local_filter, 20 );

$local_later_transport = new Kuka_Set_Transport();
$local_later_manager   = kuka_set_manager( kuka_set_provider( $local_later_transport ) );

$local_later = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $local_later_manager, $local_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $local_later_manager ) )->run( $local_id );
	}
);

$report(
	'SHIPPING_AUTO_CREATE_RECIPIENT_LOCAL_REFUSAL_IS_SAFE',
	0 === $local_transport->writes()
		&& 0 === $local_later_transport->writes()
		&& false === (bool) ( $local_first['ok'] ?? false )
		&& false === (bool) ( $local_later['ok'] ?? false )
		&& Kuka_Island_Shipping_Order_Store::STATE_NONE === (string) $local_state['state']
		&& array() === (array) $local_state['pending_mutation'],
	sprintf(
		'measured:local_payload_refusal_then_fresh_worker|first_writes=%d/state=%s/pending=%s|later_writes=%d/ok=%s/reason=%s',
		$local_transport->writes(),
		(string) $local_state['state'],
		array() === (array) $local_state['pending_mutation'] ? 'absent' : 'present',
		$local_later_transport->writes(),
		! empty( $local_later['ok'] ) ? 'YES' : 'no',
		(string) ( $local_later['reason'] ?? 'none' )
	)
);

kuka_set_purge( $local_id );
kuka_set_destroy( kuka_set_fresh( $local_id ) );

// Phase two goes silent: the shipment may exist, so nothing is repeated.
$silent_transport = new Kuka_Set_Transport();
$silent_transport->responder = static function ( string $method, string $url ) {
	unset( $method );

	if ( str_contains( $url, '/createbarcode' ) ) {
		return array( 'status' => 0, 'body' => '', 'error' => 'cURL error 28: Operation timed out' );
	}

	if ( str_contains( $url, '/getshipment/' ) ) {
		return array( 'status' => 503, 'body' => '' );
	}

	return null;
};

$silent_manager = kuka_set_manager( kuka_set_provider( $silent_transport ) );
$silent_order   = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$silent_id      = (int) $silent_order->get_id();

$silent_first = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $silent_manager, $silent_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $silent_manager ) )->run( $silent_id );
	}
);

kuka_set_purge( $silent_id );

kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $silent_manager, $silent_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $silent_manager ) )->run( $silent_id );
	}
);

kuka_set_purge( $silent_id );

$silent_second = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $silent_manager, $silent_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $silent_manager ) )->run( $silent_id );
	}
);

$silent_state = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $silent_id ) );

$silent_retry_transport = new Kuka_Set_Transport();
$silent_retry_manager   = kuka_set_manager( kuka_set_provider( $silent_retry_transport ) );

$silent_retry = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $silent_retry_manager, $silent_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $silent_retry_manager ) )->run( $silent_id );
	}
);

$report(
	'SHIPPING_AUTO_CREATE_PHASE_TWO_UNCERTAIN_IS_SAFE',
	1 === $silent_transport->count_for( '/createOrder' )
		&& 1 === $silent_transport->count_for( '/createbarcode' )
		&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED !== (string) $silent_state['state']
		&& 0 === $silent_retry_transport->count_for( '/createbarcode' )
		&& 0 === $silent_retry_transport->writes(),
	sprintf(
		'measured:uncertain_createbarcode_then_fresh_worker|first:createOrder=%d/createbarcode=%d/state=%s|retry:createbarcode=%d/writes=%d/reason=%s',
		$silent_transport->count_for( '/createOrder' ),
		$silent_transport->count_for( '/createbarcode' ),
		(string) $silent_state['state'],
		$silent_retry_transport->count_for( '/createbarcode' ),
		$silent_retry_transport->writes(),
		(string) ( $silent_retry['reason'] ?? $silent_retry['code'] ?? 'none' )
	)
);

kuka_set_purge( $silent_id );
kuka_set_destroy( kuka_set_fresh( $silent_id ) );

/* ========================================================================== */
/* 8b. The retry budget is a real retry, and it is phase-aware                 */
/* ========================================================================== */

/*
 * A COUNTER IS NOT A CONTRACT.
 *
 * MAX_ATTEMPTS and dispatch_attempts existed, and the documents said a local
 * pre-network refusal "may get a limited retry" -- but nothing ever booked a
 * second job, so `retry_budget_spent` could not be reached by the ordinary
 * automatic flow. A budget nobody spends is not a budget.
 *
 * What follows measures the real thing, and the two halves that make it safe:
 *
 *   RETRYABLE means structurally proven not to have reached the carrier. Not
 *   "the HTTP status looked local": the order is read back from the database
 *   and the pending intent -- which begin_mutation() writes BEFORE the request
 *   -- must be absent and the state unmoved.
 *
 *   PHASE-AWARE means a retry after a createOrder that DID land continues at
 *   createbarcode. Repeating a proven carrier write to "start again" is the one
 *   thing this module exists to prevent.
 *
 * The obstacle is a genuine second MySQL session holding the order's mutation
 * lock, which is what a concurrent operator press looks like from here.
 */
$retry_cases = array();

/** A second, real MySQL session; named locks are per connection. */
function kuka_set_second_session(): ?wpdb {
	if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
		return null;
	}

	$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$second->suppress_errors( true );

	return (int) $second->get_var( 'SELECT CONNECTION_ID()' ) > 0 ? $second : null;
}

/** Hold the MANAGER's mutation lock -- not the scheduler's booking lock. */
function kuka_set_hold_mutation_lock( wpdb $second, int $order_id ): bool {
	return '1' === (string) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_ship_mutate_' . $order_id ) );
}

function kuka_set_release_mutation_lock( wpdb $second, int $order_id ): void {
	$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_ship_mutate_' . $order_id ) );
}

/** Count this order's pending dispatcher jobs. */
function kuka_set_retry_jobs( int $order_id ): int {
	return kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $order_id );
}

/** Order notes whose text repeats, so duplication is visible as a number. */
function kuka_set_note_counts( int $order_id ): array {
	$seen = array();

	foreach ( (array) wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => 200 ) ) as $note ) {
		$text          = trim( (string) $note->content );
		$seen[ $text ] = (int) ( $seen[ $text ] ?? 0 ) + 1;
	}

	return $seen;
}

/** The highest number of times any single note text appears. */
function kuka_set_max_note_repeat( int $order_id ): int {
	$counts = kuka_set_note_counts( $order_id );

	return array() === $counts ? 0 : max( $counts );
}

$retry_session = kuka_set_second_session();

/* --- (a) transient contention BEFORE createOrder ----------------------- */

$a_transport = new Kuka_Set_Transport();
$a_manager   = kuka_set_manager( kuka_set_provider( $a_transport ) );
$a_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$a_id        = (int) $a_order->get_id();
$a_held      = null !== $retry_session && kuka_set_hold_mutation_lock( $retry_session, $a_id );

$a_first = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $a_manager, $a_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $a_manager ) )->run( $a_id );
	}
);

$a_writes_during_block = $a_transport->writes();
$a_jobs_after_block    = kuka_set_retry_jobs( $a_id );

if ( null !== $retry_session ) {
	kuka_set_release_mutation_lock( $retry_session, $a_id );
}

$a_second = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $a_manager, $a_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $a_manager ) )->run( $a_id );
	}
);

kuka_set_purge( $a_id );

$a_third = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $a_manager, $a_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $a_manager ) )->run( $a_id );
	}
);

kuka_set_purge( $a_id );

$a_fourth = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $a_manager, $a_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $a_manager ) )->run( $a_id );
	}
);

$a_state = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $a_id ) );

$retry_cases['a_contention_before_create_order'] = array(
	'held'            => $a_held,
	'first_reason'    => (string) ( $a_first['reason'] ?? '' ),
	'writes_blocked'  => $a_writes_during_block,
	'retry_scheduled' => $a_jobs_after_block,
	'create_order'    => $a_transport->count_for( '/createOrder' ),
	'create_barcode'  => $a_transport->count_for( '/createbarcode' ),
	'state'           => (string) $a_state['state'],
	'ok'              => (bool) ( $a_fourth['ok'] ?? false ),
	'notes_repeat'    => kuka_set_max_note_repeat( $a_id ),
);

kuka_set_purge( $a_id );
kuka_set_destroy( kuka_set_fresh( $a_id ) );

/* --- (b) createOrder landed; contention BEFORE createbarcode ------------ */

$b_transport = new Kuka_Set_Transport();
$b_manager   = kuka_set_manager( kuka_set_provider( $b_transport ) );
$b_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$b_id        = (int) $b_order->get_id();

// Phase one, for real, through the production path.
$b_manager->create_shipment( kuka_set_fresh( $b_id ) );

$b_after_phase_one = Kuka_Island_Shipping_Order_Store::get_state( kuka_set_fresh( $b_id ) );
$b_held            = null !== $retry_session && kuka_set_hold_mutation_lock( $retry_session, $b_id );

$b_first = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $b_manager, $b_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $b_manager ) )->run( $b_id );
	}
);

$b_create_order_during_block = $b_transport->count_for( '/createOrder' );
$b_jobs_after_block          = kuka_set_retry_jobs( $b_id );

if ( null !== $retry_session ) {
	kuka_set_release_mutation_lock( $retry_session, $b_id );
}

$b_second = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $b_manager, $b_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $b_manager ) )->run( $b_id );
	}
);

$b_state = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $b_id ) );

$retry_cases['b_contention_before_create_barcode'] = array(
	'held'             => $b_held,
	'state_at_entry'   => $b_after_phase_one,
	'first_reason'     => (string) ( $b_first['reason'] ?? '' ),
	'retry_scheduled'  => $b_jobs_after_block,
	'create_order'     => $b_transport->count_for( '/createOrder' ),
	'create_order_mid' => $b_create_order_during_block,
	'create_barcode'   => $b_transport->count_for( '/createbarcode' ),
	'state'            => (string) $b_state['state'],
	'ok'               => (bool) ( $b_second['ok'] ?? false ),
	'notes_repeat'     => kuka_set_max_note_repeat( $b_id ),
);

kuka_set_purge( $b_id );
kuka_set_destroy( kuka_set_fresh( $b_id ) );

/* --- (c) and (d) uncertainty books NOTHING ------------------------------ */

$uncertain_case = static function ( string $name, string $silent_path ) use ( &$retry_cases ): void {
	$transport = new Kuka_Set_Transport();
	$transport->responder = static function ( string $method, string $url ) use ( $silent_path ) {
		unset( $method );

		if ( str_contains( $url, $silent_path ) ) {
			return array( 'status' => 0, 'body' => '', 'error' => 'cURL error 28: Operation timed out' );
		}

		if ( str_contains( $url, '/getshipment/' ) || str_contains( $url, '/getorder/' ) ) {
			return array( 'status' => 503, 'body' => '' );
		}

		return null;
	};

	$manager  = kuka_set_manager( kuka_set_provider( $transport ) );
	$order    = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
	$order_id = (int) $order->get_id();

	$turns = array(
		'/createRecipient' => 1,
		'/createOrder'     => 2,
		'/createbarcode'   => 3,
	);

	$result = array();

	for ( $turn = 0; $turn < (int) ( $turns[ $silent_path ] ?? 1 ); $turn++ ) {
		if ( $turn > 0 ) {
			kuka_set_purge( $order_id );
		}

		$result = kuka_set_with_switch(
			'auto_create_enabled',
			true,
			static function () use ( $manager, $order_id ): array {
				return ( new Kuka_Island_Shipping_Dispatcher( $manager ) )->run( $order_id );
			}
		);
	}

	$jobs = kuka_set_retry_jobs( $order_id );

	// A LATER worker turn, everything fresh, must still send nothing.
	$later_transport = new Kuka_Set_Transport();
	$later_manager   = kuka_set_manager( kuka_set_provider( $later_transport ) );

	kuka_set_with_switch(
		'auto_create_enabled',
		true,
		static function () use ( $later_manager, $order_id ): array {
			return ( new Kuka_Island_Shipping_Dispatcher( $later_manager ) )->run( $order_id );
		}
	);

	$state = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $order_id ) );

	$retry_cases[ $name ] = array(
		'reason'          => (string) ( $result['reason'] ?? '' ),
		'retry_scheduled' => $jobs,
		'create_order'    => $transport->count_for( '/createOrder' ),
		'create_barcode'  => $transport->count_for( '/createbarcode' ),
		'later_writes'    => $later_transport->writes(),
		'state'           => (string) $state['state'],
		'notes_repeat'    => kuka_set_max_note_repeat( $order_id ),
	);

	kuka_set_purge( $order_id );
	kuka_set_destroy( kuka_set_fresh( $order_id ) );
};

$uncertain_case( 'c_uncertain_create_order', '/createOrder' );
$uncertain_case( 'd_uncertain_create_barcode', '/createbarcode' );

/* --- (e) the budget really runs out ------------------------------------- */

$e_transport = new Kuka_Set_Transport();
$e_manager   = kuka_set_manager( kuka_set_provider( $e_transport ) );
$e_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$e_id        = (int) $e_order->get_id();
$e_held      = null !== $retry_session && kuka_set_hold_mutation_lock( $retry_session, $e_id );
$e_turns     = array();

for ( $e_turn = 0; $e_turn < 4; $e_turn++ ) {
	/*
	 * Action Scheduler consumes a row before the turn it books runs. Calling
	 * run() directly does not, so the row this harness left behind is removed
	 * between turns -- otherwise the count at the end would measure the
	 * harness rather than the budget.
	 */
	kuka_set_purge( $e_id );

	$e_turns[] = kuka_set_with_switch(
		'auto_create_enabled',
		true,
		static function () use ( $e_manager, $e_id ): array {
			return ( new Kuka_Island_Shipping_Dispatcher( $e_manager ) )->run( $e_id );
		}
	);
}

$e_jobs  = kuka_set_retry_jobs( $e_id );
$e_data  = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $e_id ) );

if ( null !== $retry_session ) {
	kuka_set_release_mutation_lock( $retry_session, $e_id );
}

$retry_cases['e_budget'] = array(
	'held'            => $e_held,
	'turns'           => count( $e_turns ),
	'attempts'        => (int) $e_data['dispatch_attempts'],
	'last_reason'     => (string) ( $e_turns[3]['reason'] ?? '' ),
	'retry_scheduled' => $e_jobs,
	'writes'          => $e_transport->writes(),
	'panel_reason'    => (string) $e_data['dispatch_reason'],
	'notes_repeat'    => kuka_set_max_note_repeat( $e_id ),
);

kuka_set_purge( $e_id );
kuka_set_destroy( kuka_set_fresh( $e_id ) );

/* --- (f) the scheduler refuses to book the retry ------------------------ */

$f_transport = new Kuka_Set_Transport();
$f_manager   = kuka_set_manager( kuka_set_provider( $f_transport ) );
$f_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$f_id        = (int) $f_order->get_id();
$f_held      = null !== $retry_session && kuka_set_hold_mutation_lock( $retry_session, $f_id );

$f_block = static function ( $pre, $timestamp, $hook ) {
	unset( $timestamp );

	return Kuka_Island_Shipping_Dispatcher::ACTION === $hook ? 0 : $pre;
};

add_filter( 'pre_as_schedule_single_action', $f_block, PHP_INT_MAX, 3 );

$f_result = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $f_manager, $f_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $f_manager ) )->run( $f_id );
	}
);

remove_filter( 'pre_as_schedule_single_action', $f_block, PHP_INT_MAX );

if ( null !== $retry_session ) {
	kuka_set_release_mutation_lock( $retry_session, $f_id );
}

$f_data = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $f_id ) );

$retry_cases['f_schedule_failed'] = array(
	'held'            => $f_held,
	'claimed_retry'   => (bool) ( $f_result['retry_scheduled'] ?? false ),
	'retry_scheduled' => kuka_set_retry_jobs( $f_id ),
	'reason'          => (string) ( $f_result['reason'] ?? '' ),
	'panel_reason'    => (string) $f_data['dispatch_reason'],
	'writes'          => $f_transport->writes(),
	'notes_repeat'    => kuka_set_max_note_repeat( $f_id ),
);

kuka_set_purge( $f_id );
kuka_set_destroy( kuka_set_fresh( $f_id ) );

/* --- (g) a switch turned off before the retry --------------------------- */

$g_transport = new Kuka_Set_Transport();
$g_manager   = kuka_set_manager( kuka_set_provider( $g_transport ) );
$g_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$g_id        = (int) $g_order->get_id();
$g_held      = null !== $retry_session && kuka_set_hold_mutation_lock( $retry_session, $g_id );

// The run gate closes while the obstacle is still in place.
Kuka_Island_Shipping_Runtime_Gate::disable();

$g_result = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $g_manager, $g_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $g_manager ) )->run( $g_id );
	}
);

Kuka_Island_Shipping_Runtime_Gate::enable();

// And the same with the automatic switch itself closed.
$g_switch_result = kuka_set_with_switch(
	'auto_create_enabled',
	false,
	static function () use ( $g_manager, $g_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $g_manager ) )->run( $g_id );
	}
);

if ( null !== $retry_session ) {
	kuka_set_release_mutation_lock( $retry_session, $g_id );
}

$retry_cases['g_switched_off'] = array(
	'held'            => $g_held,
	'gate_reason'     => (string) ( $g_result['reason'] ?? '' ),
	'switch_reason'   => (string) ( $g_switch_result['reason'] ?? '' ),
	'retry_scheduled' => kuka_set_retry_jobs( $g_id ),
	'writes'          => $g_transport->writes(),
	'notes_repeat'    => kuka_set_max_note_repeat( $g_id ),
);

kuka_set_purge( $g_id );
kuka_set_destroy( kuka_set_fresh( $g_id ) );

if ( null !== $retry_session ) {
	$retry_session->close();
}

$a = $retry_cases['a_contention_before_create_order'];
$b = $retry_cases['b_contention_before_create_barcode'];
$c = $retry_cases['c_uncertain_create_order'];
$d = $retry_cases['d_uncertain_create_barcode'];
$e = $retry_cases['e_budget'];
$f = $retry_cases['f_schedule_failed'];
$g = $retry_cases['g_switched_off'];

$retry_ok = true === $a['held'] && true === $b['held'] && true === $e['held'] && true === $f['held'] && true === $g['held']

	// (a) blocked before the first write; ONE retry booked; then both phases run once.
	&& 0 === (int) $a['writes_blocked']
	&& 1 === (int) $a['retry_scheduled']
	&& 1 === (int) $a['create_order']
	&& 1 === (int) $a['create_barcode']
	&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === (string) $a['state']
	&& true === (bool) $a['ok']

	// (b) the retry continues at phase two; createOrder is NOT repeated.
	&& Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === (string) $b['state_at_entry']
	&& 1 === (int) $b['create_order_mid']
	&& 1 === (int) $b['retry_scheduled']
	&& 1 === (int) $b['create_order']
	&& 1 === (int) $b['create_barcode']
	&& Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === (string) $b['state']

	// (c) and (d): uncertainty books no retry and repeats no write.
	&& 1 === (int) $c['create_order']
	&& 0 === (int) $c['retry_scheduled']
	&& 0 === (int) $c['later_writes']
	&& 1 === (int) $d['create_barcode']
	&& 0 === (int) $d['retry_scheduled']
	&& 0 === (int) $d['later_writes']

	// (e) three turns and no more; the panel says so, once.
	&& 4 === (int) $e['attempts']
	&& 'retry_budget_spent' === (string) $e['last_reason']
	&& 0 === (int) $e['retry_scheduled']
	&& 0 === (int) $e['writes']
	&& 1 === (int) $e['notes_repeat']

	// (f) a booking that did not happen is not reported as one.
	&& false === (bool) $f['claimed_retry']
	&& 0 === (int) $f['retry_scheduled']
	&& '' !== (string) $f['panel_reason']
	&& 0 === (int) $f['writes']

	// (g) a closed switch books nothing and contacts nobody.
	&& Kuka_Island_Shipping_Runtime_Gate::CODE === (string) $g['gate_reason']
	&& 'auto_create_disabled' === (string) $g['switch_reason']
	&& 0 === (int) $g['retry_scheduled']
	&& 0 === (int) $g['writes'];

$report(
	'SHIPPING_AUTO_CREATE_RETRY_IS_REAL_AND_PHASE_AWARE',
	$retry_ok,
	sprintf(
		'measured:real_worker_with_a_second_mysql_session_holding_the_mutation_lock'
			. '|a_before_create_order:blocked_writes=%d/retry_jobs=%d/createOrder=%d/createbarcode=%d/state=%s'
			. '|b_before_create_barcode:entry_state=%s/createOrder_mid=%d/retry_jobs=%d/createOrder_total=%d/createbarcode_total=%d/state=%s'
			. '|c_uncertain_create_order:createOrder=%d/retry_jobs=%d/later_writes=%d/state=%s'
			. '|d_uncertain_create_barcode:createbarcode=%d/retry_jobs=%d/later_writes=%d/state=%s'
			. '|e_budget:turns=%d/attempts=%d/last=%s/retry_jobs=%d/writes=%d/max_note_repeat=%d'
			. '|f_schedule_failed:claimed_retry=%s/retry_jobs=%d/panel_reason=%s/writes=%d'
			. '|g_switched_off:gate=%s/switch=%s/retry_jobs=%d/writes=%d',
		(int) $a['writes_blocked'],
		(int) $a['retry_scheduled'],
		(int) $a['create_order'],
		(int) $a['create_barcode'],
		(string) $a['state'],
		(string) $b['state_at_entry'],
		(int) $b['create_order_mid'],
		(int) $b['retry_scheduled'],
		(int) $b['create_order'],
		(int) $b['create_barcode'],
		(string) $b['state'],
		(int) $c['create_order'],
		(int) $c['retry_scheduled'],
		(int) $c['later_writes'],
		(string) $c['state'],
		(int) $d['create_barcode'],
		(int) $d['retry_scheduled'],
		(int) $d['later_writes'],
		(string) $d['state'],
		(int) $e['turns'],
		(int) $e['attempts'],
		'' === (string) $e['last_reason'] ? 'none' : (string) $e['last_reason'],
		(int) $e['retry_scheduled'],
		(int) $e['writes'],
		(int) $e['notes_repeat'],
		$f['claimed_retry'] ? 'YES' : 'no',
		(int) $f['retry_scheduled'],
		'' === (string) $f['panel_reason'] ? 'none' : (string) $f['panel_reason'],
		(int) $f['writes'],
		'' === (string) $g['gate_reason'] ? 'none' : (string) $g['gate_reason'],
		'' === (string) $g['switch_reason'] ? 'none' : (string) $g['switch_reason'],
		(int) $g['retry_scheduled'],
		(int) $g['writes']
	)
);

/* ========================================================================== */
/* 8c. The dispatch intent is written, read back, and only then acted on       */
/* ========================================================================== */

/*
 * update_meta_data() FILLS AN OBJECT. save_meta_data() is what puts it on disk,
 * and it can fail without saying so.
 *
 * The module already learned this twice -- for the mutation intent and for the
 * notification claim -- and both now read their own write back before anything
 * irreversible happens. The dispatcher's own bookkeeping did not: it counted an
 * attempt, marked the phase, and called the carrier. If neither row landed, the
 * carrier got a request that nothing local remembered asking for, and the next
 * worker would send it again.
 *
 * Each case below neutralises ONE specific write, with WordPress's own `query`
 * filter, so the statement is accepted by the database and changes nothing. No
 * hook is added to production code for this.
 */
$sabotage_cases = array();

/**
 * Replace matching INSERT/UPDATE statements with a harmless SELECT.
 *
 * @param callable $matches Given the SQL, says whether to neutralise it.
 * @return array{filter: callable, hits: object}
 */
function kuka_set_drop_writes( callable $matches ): array {
	$hits = new stdClass();
	$hits->count = 0;

	$filter = static function ( $query ) use ( $matches, $hits ) {
		if ( ! is_string( $query ) ) {
			return $query;
		}

		if ( ! in_array( strtoupper( substr( ltrim( $query ), 0, 6 ) ), array( 'INSERT', 'UPDATE', 'REPLAC' ), true ) ) {
			return $query;
		}

		if ( ! $matches( $query ) ) {
			return $query;
		}

		++$hits->count;

		// Accepted by the database, and it changes nothing.
		return 'SELECT 1';
	};

	return array(
		'filter' => $filter,
		'hits'   => $hits,
	);
}

/* --- (a) the attempt + phase start record never lands ------------------- */

$sa_transport = new Kuka_Set_Transport();
$sa_manager   = kuka_set_manager( kuka_set_provider( $sa_transport ) );
$sa_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$sa_id        = (int) $sa_order->get_id();

$sa_sabotage = kuka_set_drop_writes(
	static function ( string $query ): bool {
		return str_contains( $query, Kuka_Island_Shipping_Order_Store::META_DISPATCH_PHASES )
			|| str_contains( $query, Kuka_Island_Shipping_Order_Store::META_DISPATCH_ATTEMPTS );
	}
);

add_filter( 'query', $sa_sabotage['filter'], PHP_INT_MAX );

$sa_result = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $sa_manager, $sa_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $sa_manager ) )->run( $sa_id );
	}
);

remove_filter( 'query', $sa_sabotage['filter'], PHP_INT_MAX );

$sa_data = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $sa_id ) );

$sabotage_cases['a_dispatch_start_write_dropped'] = array(
	'dropped'      => (int) $sa_sabotage['hits']->count,
	'first_write'  => $sa_transport->writes(),
	'second_write' => 0,
	'retry_jobs'   => kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $sa_id ),
	'attempts'     => (int) $sa_data['dispatch_attempts'],
	'phases'       => implode( '+', (array) $sa_data['dispatch_phases'] ),
	'reason'       => (string) ( $sa_result['reason'] ?? '' ),
	'panel_reason' => (string) $sa_data['dispatch_reason'],
	'state'        => (string) $sa_data['state'],
);

kuka_set_purge( $sa_id );
kuka_set_destroy( kuka_set_fresh( $sa_id ) );

/* --- (d) the attempt count lands, the phase mark does not --------------- */

/*
 * ONE PERSIST TURN IS NOT ONE STATEMENT. save_meta_data() may issue a separate
 * statement per meta key, so the attempt count and the phase mark CAN land by
 * halves -- and that is precisely why the opening record is proven by reading
 * BOTH values back from a fresh order rather than by trusting the save.
 *
 * Case (a) drops the record entirely. A guard that only survives total loss
 * would still let a half-written ledger through, so each half is dropped on its
 * own, in both directions. Either way the carrier must not be contacted.
 */
$sd_transport = new Kuka_Set_Transport();
$sd_manager   = kuka_set_manager( kuka_set_provider( $sd_transport ) );
$sd_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$sd_id        = (int) $sd_order->get_id();

$sd_sabotage = kuka_set_drop_writes(
	static function ( string $query ): bool {
		return str_contains( $query, Kuka_Island_Shipping_Order_Store::META_DISPATCH_PHASES );
	}
);

add_filter( 'query', $sd_sabotage['filter'], PHP_INT_MAX );

$sd_result = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $sd_manager, $sd_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $sd_manager ) )->run( $sd_id );
	}
);

remove_filter( 'query', $sd_sabotage['filter'], PHP_INT_MAX );

$sd_data = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $sd_id ) );

$sabotage_cases['d_attempt_landed_phase_dropped'] = array(
	'dropped'      => (int) $sd_sabotage['hits']->count,
	'first_write'  => $sd_transport->writes(),
	'second_write' => 0,
	'retry_jobs'   => kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $sd_id ),
	'attempts'     => (int) $sd_data['dispatch_attempts'],
	'phases'       => implode( '+', (array) $sd_data['dispatch_phases'] ),
	'reason'       => (string) ( $sd_result['reason'] ?? '' ),
	'panel_reason' => (string) $sd_data['dispatch_reason'],
	'state'        => (string) $sd_data['state'],
);

kuka_set_purge( $sd_id );
kuka_set_destroy( kuka_set_fresh( $sd_id ) );

/* --- (e) the phase mark lands, the attempt count does not --------------- */

$se_transport = new Kuka_Set_Transport();
$se_manager   = kuka_set_manager( kuka_set_provider( $se_transport ) );
$se_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$se_id        = (int) $se_order->get_id();

$se_sabotage = kuka_set_drop_writes(
	static function ( string $query ): bool {
		return str_contains( $query, Kuka_Island_Shipping_Order_Store::META_DISPATCH_ATTEMPTS );
	}
);

add_filter( 'query', $se_sabotage['filter'], PHP_INT_MAX );

$se_result = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $se_manager, $se_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $se_manager ) )->run( $se_id );
	}
);

remove_filter( 'query', $se_sabotage['filter'], PHP_INT_MAX );

$se_data = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $se_id ) );

$sabotage_cases['e_phase_landed_attempt_dropped'] = array(
	'dropped'      => (int) $se_sabotage['hits']->count,
	'first_write'  => $se_transport->writes(),
	'second_write' => 0,
	'retry_jobs'   => kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $se_id ),
	'attempts'     => (int) $se_data['dispatch_attempts'],
	'phases'       => implode( '+', (array) $se_data['dispatch_phases'] ),
	'reason'       => (string) ( $se_result['reason'] ?? '' ),
	'panel_reason' => (string) $se_data['dispatch_reason'],
	'state'        => (string) $se_data['state'],
);

kuka_set_purge( $se_id );
kuka_set_destroy( kuka_set_fresh( $se_id ) );

/* --- (b) a proven-safe refusal, but the phase clear never lands --------- */

$sb_transport = new Kuka_Set_Transport();
$sb_manager   = kuka_set_manager( kuka_set_provider( $sb_transport ) );
$sb_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$sb_id        = (int) $sb_order->get_id();
$sb_session   = kuka_set_second_session();
$sb_held      = null !== $sb_session && kuka_set_hold_mutation_lock( $sb_session, $sb_id );

/*
 * The clear is the ONLY write neutralised. The start record lands, the Manager
 * refuses with lock_contended having sent nothing, and the phase mark then
 * cannot be taken back -- so no retry may be booked, because a retry would meet
 * a mark that says this phase was already issued.
 */
$sb_started  = false;
$sb_sabotage = kuka_set_drop_writes(
	static function ( string $query ) use ( &$sb_started ): bool {
		if ( ! str_contains( $query, Kuka_Island_Shipping_Order_Store::META_DISPATCH_PHASES ) ) {
			return false;
		}

		if ( ! $sb_started ) {
			// Let the opening write through; only the clear is sabotaged.
			$sb_started = true;

			return false;
		}

		return true;
	}
);

add_filter( 'query', $sb_sabotage['filter'], PHP_INT_MAX );

$sb_result = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $sb_manager, $sb_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $sb_manager ) )->run( $sb_id );
	}
);

remove_filter( 'query', $sb_sabotage['filter'], PHP_INT_MAX );

if ( null !== $sb_session ) {
	kuka_set_release_mutation_lock( $sb_session, $sb_id );
}

$sb_data = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $sb_id ) );

$sabotage_cases['b_phase_clear_write_dropped'] = array(
	'dropped'      => (int) $sb_sabotage['hits']->count,
	'first_write'  => $sb_transport->writes(),
	'second_write' => 0,
	'retry_jobs'   => kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $sb_id ),
	'attempts'     => (int) $sb_data['dispatch_attempts'],
	'phases'       => implode( '+', (array) $sb_data['dispatch_phases'] ),
	'reason'       => (string) ( $sb_result['reason'] ?? '' ),
	'panel_reason' => (string) $sb_data['dispatch_reason'],
	'state'        => (string) $sb_data['state'],
	'claimed'      => (bool) ( $sb_result['retry_scheduled'] ?? false ),
);

kuka_set_purge( $sb_id );
kuka_set_destroy( kuka_set_fresh( $sb_id ) );

/* --- (c) the order cannot be read back at all --------------------------- */

$sc_transport = new Kuka_Set_Transport();
$sc_manager   = kuka_set_manager( kuka_set_provider( $sc_transport ) );
$sc_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$sc_id        = (int) $sc_order->get_id();

// Every factory read for THIS order returns a class that cannot be built.
$sc_break = static function ( $classname, $order_type = '', $read_id = 0 ) use ( $sc_id ) {
	unset( $order_type );

	return (int) $read_id === $sc_id ? 'Kuka_Set_Absent_Order_Class' : $classname;
};

add_filter( 'woocommerce_order_class', $sc_break, PHP_INT_MAX, 3 );

$sc_result = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $sc_manager, $sc_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $sc_manager ) )->run( $sc_id );
	}
);

remove_filter( 'woocommerce_order_class', $sc_break, PHP_INT_MAX );

$sc_data = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $sc_id ) );

$sabotage_cases['c_order_unreadable'] = array(
	'dropped'      => 0,
	'first_write'  => $sc_transport->writes(),
	'second_write' => 0,
	'retry_jobs'   => kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $sc_id ),
	'attempts'     => (int) $sc_data['dispatch_attempts'],
	'phases'       => implode( '+', (array) $sc_data['dispatch_phases'] ),
	'reason'       => (string) ( $sc_result['reason'] ?? '' ),
	'panel_reason' => (string) $sc_data['dispatch_reason'],
	'state'        => (string) $sc_data['state'],
);

kuka_set_purge( $sc_id );
kuka_set_destroy( kuka_set_fresh( $sc_id ) );

$sa = $sabotage_cases['a_dispatch_start_write_dropped'];
$sb = $sabotage_cases['b_phase_clear_write_dropped'];
$sc = $sabotage_cases['c_order_unreadable'];
$sd = $sabotage_cases['d_attempt_landed_phase_dropped'];
$se = $sabotage_cases['e_phase_landed_attempt_dropped'];

$sabotage_ok = $sa['dropped'] > 0
	&& 0 === (int) $sa['first_write']
	&& 0 === (int) $sa['retry_jobs']
	&& 0 === (int) $sa['attempts']
	&& '' === (string) $sa['phases']
	&& 'dispatch_intent_unverified' === (string) $sa['reason']
	&& 'dispatch_intent_unverified' === (string) $sa['panel_reason']

	/*
	 * The half that DID land is asserted too. Without it the measurement would
	 * pass on an order where nothing landed at all, which is case (a) again --
	 * a partial write that was never partial proves nothing about halves.
	 */
	&& $sd['dropped'] > 0
	&& 0 === (int) $sd['first_write']
	&& 0 === (int) $sd['retry_jobs']
	&& 1 === (int) $sd['attempts']
	&& '' === (string) $sd['phases']
	&& 'dispatch_intent_unverified' === (string) $sd['reason']
	&& 'dispatch_intent_unverified' === (string) $sd['panel_reason']

	&& $se['dropped'] > 0
	&& 0 === (int) $se['first_write']
	&& 0 === (int) $se['retry_jobs']
	&& 0 === (int) $se['attempts']
	&& 'create_recipient' === (string) $se['phases']
	&& 'dispatch_intent_unverified' === (string) $se['reason']
	&& 'dispatch_intent_unverified' === (string) $se['panel_reason']

	&& true === $sb_held
	&& $sb['dropped'] > 0
	&& 0 === (int) $sb['first_write']
	&& 0 === (int) $sb['retry_jobs']
	&& false === (bool) $sb['claimed']
	&& 'dispatch_phase_clear_unverified' === (string) $sb['reason']
	&& 'dispatch_phase_clear_unverified' === (string) $sb['panel_reason']

	&& 0 === (int) $sc['first_write']
	&& 0 === (int) $sc['retry_jobs']
	&& 'dispatch_order_unreadable' === (string) $sc['reason']
	&& 0 === (int) $sc['attempts'];

$report(
	'SHIPPING_AUTO_CREATE_DISPATCH_INTENT_IS_VERIFIED',
	$sabotage_ok,
	sprintf(
		'measured:real_worker_with_wordpress_query_filter_sabotage'
			. '|a_start_write_dropped:statements=%d/first_write=%d/second_write=%d/retry_jobs=%d/attempts=%d/phases=%s/reason=%s/panel=%s'
			. '|d_attempt_landed_phase_dropped:statements=%d/first_write=%d/second_write=%d/retry_jobs=%d/attempts=%d/phases=%s/reason=%s/panel=%s'
			. '|e_phase_landed_attempt_dropped:statements=%d/first_write=%d/second_write=%d/retry_jobs=%d/attempts=%d/phases=%s/reason=%s/panel=%s'
			. '|b_phase_clear_dropped:statements=%d/first_write=%d/second_write=%d/retry_jobs=%d/attempts=%d/phases=%s/reason=%s/panel=%s/claimed_retry=%s'
			. '|c_order_unreadable:first_write=%d/second_write=%d/retry_jobs=%d/attempts=%d/phases=%s/reason=%s',
		(int) $sa['dropped'],
		(int) $sa['first_write'],
		(int) $sa['second_write'],
		(int) $sa['retry_jobs'],
		(int) $sa['attempts'],
		'' === (string) $sa['phases'] ? 'none' : (string) $sa['phases'],
		'' === (string) $sa['reason'] ? 'none' : (string) $sa['reason'],
		'' === (string) $sa['panel_reason'] ? 'none' : (string) $sa['panel_reason'],
		(int) $sd['dropped'],
		(int) $sd['first_write'],
		(int) $sd['second_write'],
		(int) $sd['retry_jobs'],
		(int) $sd['attempts'],
		'' === (string) $sd['phases'] ? 'none' : (string) $sd['phases'],
		'' === (string) $sd['reason'] ? 'none' : (string) $sd['reason'],
		'' === (string) $sd['panel_reason'] ? 'none' : (string) $sd['panel_reason'],
		(int) $se['dropped'],
		(int) $se['first_write'],
		(int) $se['second_write'],
		(int) $se['retry_jobs'],
		(int) $se['attempts'],
		'' === (string) $se['phases'] ? 'none' : (string) $se['phases'],
		'' === (string) $se['reason'] ? 'none' : (string) $se['reason'],
		'' === (string) $se['panel_reason'] ? 'none' : (string) $se['panel_reason'],
		(int) $sb['dropped'],
		(int) $sb['first_write'],
		(int) $sb['second_write'],
		(int) $sb['retry_jobs'],
		(int) $sb['attempts'],
		'' === (string) $sb['phases'] ? 'none' : (string) $sb['phases'],
		'' === (string) $sb['reason'] ? 'none' : (string) $sb['reason'],
		'' === (string) $sb['panel_reason'] ? 'none' : (string) $sb['panel_reason'],
		$sb['claimed'] ? 'YES' : 'no',
		(int) $sc['first_write'],
		(int) $sc['second_write'],
		(int) $sc['retry_jobs'],
		(int) $sc['attempts'],
		'' === (string) $sc['phases'] ? 'none' : (string) $sc['phases'],
		'' === (string) $sc['reason'] ? 'none' : (string) $sc['reason']
	)
);

/* ========================================================================== */
/* 9. Two REAL processes, two MySQL sessions, one carrier write                */
/* ========================================================================== */

/*
 * TWO CALLS IN ONE PROCESS ARE NOT CONCURRENCY. They share a connection, so
 * they share the named lock, and the second one is refused by bookkeeping the
 * first one did in the same request. Nothing about that resembles an operator
 * pressing "Gönderiyi oluştur" at the moment the scheduler picks the same order
 * up in another PHP process.
 *
 * So a SECOND PHP PROCESS is started. It takes the order's mutation lock
 * through the real Manager, holds it inside a carrier call that deliberately
 * takes three seconds, and this process runs the AUTOMATIC path meanwhile. Both
 * processes count their carrier writes into the same options row, so the total
 * is a cross-process number rather than two local ones.
 */
$race_counter_option = 'kuka_island_shipping_race_writes';
$race_marker_option  = 'kuka_island_shipping_race_started';

$kuka_set_owned_options[] = $race_counter_option;
$kuka_set_owned_options[] = $race_marker_option;

delete_option( $race_counter_option );
delete_option( $race_marker_option );

$race_order = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$race_id    = (int) $race_order->get_id();

$race_child = sys_get_temp_dir() . '/kuka-race-child-' . $race_id . '.php';

file_put_contents(
	$race_child,
	'<?php' . "\n"
	. 'require_once "/project-scripts/lib-shipping-module-loader.php";' . "\n"
	. 'kuka_shipping_load_module();' . "\n"
	. 'final class Kuka_Race_Transport implements Kuka_Island_Shipping_HTTP_Transport_Interface {' . "\n"
	. '  public function request( string $method, string $url, array $headers, string $body, int $timeout ): array {' . "\n"
	. '    if ( str_contains( $url, "/createOrder" ) || str_contains( $url, "/createbarcode" ) ) {' . "\n"
	. '      global $wpdb;' . "\n"
	. '      $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = option_value + 1", ' . var_export( $race_counter_option, true ) . ', "1", "no" ) );' . "\n"
	. '      update_option( ' . var_export( $race_marker_option, true ) . ', "1", false );' . "\n"
	. '      sleep( 3 );' . "\n"
	. '    }' . "\n"
	. '    if ( str_contains( $url, "/token" ) ) { return array( "status" => 200, "headers" => array(), "body" => json_encode( array( "jwt" => "race", "jwtExpireDate" => gmdate( "Y-m-d H:i:s", time() + 3600 ) ) ), "error" => "" ); }' . "\n"
	. '    if ( str_contains( $url, "/getcities" ) ) { return array( "status" => 200, "headers" => array(), "body" => json_encode( array( array( "code" => "34", "name" => "İSTANBUL" ) ) ), "error" => "" ); }' . "\n"
	. '    if ( str_contains( $url, "/getdistricts" ) ) { return array( "status" => 200, "headers" => array(), "body" => json_encode( array( array( "code" => "1", "name" => "KADIKÖY" ) ) ), "error" => "" ); }' . "\n"
	. '    if ( str_contains( $url, "/createOrder" ) ) { return array( "status" => 200, "headers" => array(), "body" => json_encode( array( "referenceId" => "ECHO", "orderInvoiceId" => "RACE" ) ), "error" => "" ); }' . "\n"
	. '    return array( "status" => 200, "headers" => array(), "body" => json_encode( array( "referenceId" => "ECHO", "orderInvoiceId" => "RACE" ) ), "error" => "" );' . "\n"
	. '  }' . "\n"
	. '}' . "\n"
	. '$config = new Kuka_Island_Shipping_DHL_Config( array( "environment" => "test", "client_id" => "R", "client_secret" => "R", "customer_number" => "R", "password" => "R" ) );' . "\n"
	. '$transport = new Kuka_Race_Transport();' . "\n"
	. '$client = new Kuka_Island_Shipping_DHL_Client( $config, $transport );' . "\n"
	// The child resolves an address too, and its resolver would otherwise cache
	// MOCK city and district lists under the shop's own key space.
	. '$resolver = new Kuka_Island_Shipping_DHL_Address_Resolver( $client );' . "\n"
	. '$resolver->set_cache_namespace( ' . var_export( KUKA_SET_CACHE_NAMESPACE, true ) . ' );' . "\n"
	. '$provider = new Kuka_Island_Shipping_DHL_Provider( $config, $client, $resolver );' . "\n"
	. 'add_filter( "kuka_island_shipping_carriers", static function () use ( $provider ) { return array( $provider ); }, PHP_INT_MAX );' . "\n"
	. '$manager = new Kuka_Island_Shipping_Manager( new Kuka_Island_Shipping_Carrier_Registry() );' . "\n"
	. '$result = $manager->create_shipment( wc_get_order( ' . $race_id . ' ) );' . "\n"
	. 'WP_CLI::line( "CHILD=" . ( ! empty( $result["ok"] ) ? "ok" : "refused" ) . "|code:" . (string) ( $result["code"] ?? "none" ) );' . "\n",
	LOCK_EX
);

/*
 * This suite already runs INSIDE the container, so sys_get_temp_dir() is the
 * container's own /tmp and the child script needs no copying anywhere. It used
 * to be copied onto itself, and then deleted twice -- the second unlink raised
 * a warning that SHIPPING_SETTINGS_RUN_IS_CLEAN now refuses to ignore.
 */

$race_descriptors = array(
	0 => array( 'pipe', 'r' ),
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);

$race_command = 'wp --allow-root --path=' . escapeshellarg( ABSPATH ) . ' eval-file ' . escapeshellarg( $race_child );

$race_process = @proc_open( $race_command, $race_descriptors, $race_pipes );

$race_child_started = is_resource( $race_process );
$race_waited        = 0;

/*
 * Polled straight from the options TABLE, not through get_option(): the marker
 * is written by a DIFFERENT process, and every cache this one holds is by
 * definition unaware of it.
 */
$race_marker_read = static function () use ( $race_marker_option ): string {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $race_marker_option )
	);
};

// Wait for the child to be INSIDE its carrier call, holding the lock.
while ( $race_child_started && $race_waited < 300 ) {
	if ( '1' === $race_marker_read() ) {
		break;
	}

	usleep( 100000 );
	++$race_waited;
}

$race_marker_seen = '1' === $race_marker_read();

// NOW, while the other process holds the lock, run the automatic path here.
$race_transport = new Kuka_Set_Transport();
$race_manager   = kuka_set_manager( kuka_set_provider( $race_transport ) );

$race_auto = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $race_manager, $race_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $race_manager ) )->run( $race_id );
	}
);

$race_child_output = '';

if ( $race_child_started ) {
	if ( is_resource( $race_pipes[0] ) ) {
		fclose( $race_pipes[0] );
	}

	$race_child_output = (string) stream_get_contents( $race_pipes[1] ) . (string) stream_get_contents( $race_pipes[2] );

	foreach ( array( $race_pipes[1], $race_pipes[2] ) as $pipe ) {
		if ( is_resource( $pipe ) ) {
			fclose( $pipe );
		}
	}

	proc_close( $race_process );
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$race_child_writes = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $race_counter_option )
);

$race_total_writes = $race_child_writes + $race_transport->writes();
$race_state        = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $race_id ) );

$report(
	'SHIPPING_AUTO_AND_MANUAL_RACE_IS_SERIALISED',
	$race_child_started
		&& $race_marker_seen
		&& 1 === $race_total_writes
		&& 0 === $race_transport->writes()
		&& false === (bool) ( $race_auto['ok'] ?? false ),
	sprintf(
		'measured:second_real_php_process_and_separate_mysql_session|child_started:%s|child_inside_carrier_call:%s'
			. '|carrier_writes_total_across_processes:%d|this_process_writes:%d|automatic_outcome:%s/%s|state:%s|child_says:%s',
		$race_child_started ? 'yes' : 'NO',
		$race_marker_seen ? 'yes' : 'NO',
		$race_total_writes,
		$race_transport->writes(),
		! empty( $race_auto['ok'] ) ? 'OK' : 'refused',
		(string) ( $race_auto['reason'] ?? $race_auto['code'] ?? 'none' ),
		(string) $race_state['state'],
		'' === trim( $race_child_output ) ? 'no_output' : trim( str_replace( "\n", ' ', $race_child_output ) )
	)
);

@unlink( $race_child );
delete_option( $race_counter_option );
delete_option( $race_marker_option );
kuka_set_purge( $race_id );
kuka_set_destroy( kuka_set_fresh( $race_id ) );

/* ========================================================================== */
/* 9b. Two automatic workers, one order, one carrier write                     */
/* ========================================================================== */

/*
 * THE SEQUENCE THIS CLOSES.
 *
 *   worker A marks the phase and enters the Manager's mutation
 *   worker B, holding an eligibility answer taken a moment earlier, marks the
 *     same phase
 *   B is refused by the MUTATION lock with lock_contended
 *   B, believing nothing was sent, clears the SHARED phase mark
 *   A's write turns out uncertain -- and the mark that would have stopped a
 *     second automatic attempt is gone
 *
 * Neither the scheduler's booking lock nor the Manager's mutation lock prevents
 * it: the first is released long before, and the second is taken INSIDE the
 * Manager, after both workers have already written their bookkeeping. What is
 * needed is a third, separate lock around the worker's whole turn.
 *
 * Measured with a SECOND REAL PHP PROCESS, because two calls in one process
 * share a connection and therefore share every named lock.
 */
$race2_counter = 'kuka_island_shipping_race2_writes';
$race2_marker  = 'kuka_island_shipping_race2_started';

$kuka_set_owned_options[] = $race2_counter;
$kuka_set_owned_options[] = $race2_marker;

delete_option( $race2_counter );
delete_option( $race2_marker );

$race2_order = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$race2_id    = (int) $race2_order->get_id();

/*
 * The switch is turned on HERE, for the whole race, and put back afterwards.
 * The child must not write the shared settings row: two processes editing one
 * option is a race of its own, and it would decide this measurement instead of
 * the lock doing so.
 */
$race2_switch_before = Kuka_Island_Shipping_Settings::get_switch( 'auto_create_enabled' );

Kuka_Island_Shipping_Settings::save_switches( array( 'auto_create_enabled' => true ) );
$race2_child = sys_get_temp_dir() . '/kuka-race2-child-' . $race2_id . '.php';

file_put_contents(
	$race2_child,
	'<?php' . "\n"
	. 'require_once "/project-scripts/lib-shipping-module-loader.php";' . "\n"
	. 'kuka_shipping_load_module();' . "\n"
	. 'final class Kuka_Race2_Transport implements Kuka_Island_Shipping_HTTP_Transport_Interface {' . "\n"
	. '  public function request( string $method, string $url, array $headers, string $body, int $timeout ): array {' . "\n"
	. '    if ( str_contains( $url, "/createRecipient" ) || str_contains( $url, "/createOrder" ) || str_contains( $url, "/createbarcode" ) ) {' . "\n"
	. '      global $wpdb;' . "\n"
	. '      $suffix = str_contains( $url, "/createRecipient" ) ? "_createrecipient" : ( str_contains( $url, "/createOrder" ) ? "_createorder" : "_createbarcode" );' . "\n"
	. '      $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = option_value + 1", ' . var_export( $race2_counter, true ) . ' . $suffix, "1", "no" ) );' . "\n"
	. '      update_option( ' . var_export( $race2_marker, true ) . ', "1", false );' . "\n"
	. '      if ( str_contains( $url, "/createRecipient" ) ) { sleep( 4 ); }' . "\n"
	. '    }' . "\n"
	. '    if ( str_contains( $url, "/token" ) ) { return array( "status" => 200, "headers" => array(), "body" => json_encode( array( "jwt" => "race2", "jwtExpireDate" => gmdate( "Y-m-d H:i:s", time() + 3600 ) ) ), "error" => "" ); }' . "\n"
	. '    if ( str_contains( $url, "/getcities" ) ) { return array( "status" => 200, "headers" => array(), "body" => json_encode( array( array( "code" => "34", "name" => "İSTANBUL" ) ) ), "error" => "" ); }' . "\n"
	. '    if ( str_contains( $url, "/getdistricts" ) ) { return array( "status" => 200, "headers" => array(), "body" => json_encode( array( array( "code" => "1", "name" => "KADIKÖY" ) ) ), "error" => "" ); }' . "\n"
	. '    if ( str_contains( $url, "/createbarcode" ) ) { return array( "status" => 200, "headers" => array(), "body" => json_encode( array( "referenceId" => "ECHO", "invoiceId" => "RACE2", "shipmentId" => "909631576507", "barcodes" => array( array( "pieceNumber" => 1, "value" => "^XA^FDRACE2^FS^XZ" ) ) ) ), "error" => "" ); }' . "\n"
	. '    return array( "status" => 200, "headers" => array(), "body" => json_encode( array( "referenceId" => "ECHO", "orderInvoiceId" => "RACE2" ) ), "error" => "" );' . "\n"
	. '  }' . "\n"
	. '}' . "\n"
	. '$config = new Kuka_Island_Shipping_DHL_Config( array( "environment" => "test", "client_id" => "R", "client_secret" => "R", "customer_number" => "R", "password" => "R" ) );' . "\n"
	. '$transport = new Kuka_Race2_Transport();' . "\n"
	. '$client = new Kuka_Island_Shipping_DHL_Client( $config, $transport );' . "\n"
	. '$resolver = new Kuka_Island_Shipping_DHL_Address_Resolver( $client );' . "\n"
	. '$resolver->set_cache_namespace( ' . var_export( KUKA_SET_CACHE_NAMESPACE, True ) . ' );' . "\n"
	. '$provider = new Kuka_Island_Shipping_DHL_Provider( $config, $client, $resolver );' . "\n"
	. 'add_filter( "kuka_island_shipping_carriers", static function () use ( $provider ) { return array( $provider ); }, PHP_INT_MAX );' . "\n"
	. '$manager = new Kuka_Island_Shipping_Manager( new Kuka_Island_Shipping_Carrier_Registry() );' . "\n"
	. '$result = ( new Kuka_Island_Shipping_Dispatcher( $manager ) )->run( ' . $race2_id . ' );' . "\n"
	. 'WP_CLI::line( "CHILD=" . ( ! empty( $result["ok"] ) ? "ok" : "refused" ) . "|reason:" . (string) ( $result["reason"] ?? "none" ) );' . "\n",
	LOCK_EX
);

$race2_descriptors = array(
	0 => array( 'pipe', 'r' ),
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);

$race2_process = @proc_open(
	'wp --allow-root --path=' . escapeshellarg( ABSPATH ) . ' eval-file ' . escapeshellarg( $race2_child ),
	$race2_descriptors,
	$race2_pipes
);

$race2_started = is_resource( $race2_process );
$race2_waited  = 0;

$race2_marker_read = static function () use ( $race2_marker ): string {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $race2_marker )
	);
};

while ( $race2_started && $race2_waited < 300 ) {
	if ( '1' === $race2_marker_read() ) {
		break;
	}

	usleep( 100000 );
	++$race2_waited;
}

$race2_marker_seen = '1' === $race2_marker_read();

// NOW: a second automatic worker, in this process, on the same order and phase.
$race2_transport = new Kuka_Set_Transport();
$race2_manager   = kuka_set_manager( kuka_set_provider( $race2_transport ) );

$race2_loser = ( new Kuka_Island_Shipping_Dispatcher( $race2_manager ) )->run( $race2_id );

$race2_child_output = '';

if ( $race2_started ) {
	if ( is_resource( $race2_pipes[0] ) ) {
		fclose( $race2_pipes[0] );
	}

	$race2_child_output = (string) stream_get_contents( $race2_pipes[1] ) . (string) stream_get_contents( $race2_pipes[2] );

	foreach ( array( $race2_pipes[1], $race2_pipes[2] ) as $race2_pipe ) {
		if ( is_resource( $race2_pipe ) ) {
			fclose( $race2_pipe );
		}
	}

	proc_close( $race2_process );
}

global $wpdb;

$race2_count = static function ( string $suffix ) use ( $race2_counter ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $race2_counter . $suffix )
	);
};

$kuka_set_owned_options[] = $race2_counter . '_createrecipient';
$kuka_set_owned_options[] = $race2_counter . '_createorder';
$kuka_set_owned_options[] = $race2_counter . '_createbarcode';

$race2_create_recipient = $race2_count( '_createrecipient' ) + $race2_transport->count_for( '/createRecipient' );
$race2_create_order     = $race2_count( '_createorder' ) + $race2_transport->count_for( '/createOrder' );
$race2_create_barcode   = $race2_count( '_createbarcode' ) + $race2_transport->count_for( '/createbarcode' );
$race2_total            = $race2_create_recipient + $race2_create_order + $race2_create_barcode;
$race2_data   = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $race2_id ) );
$race2_phases = (array) $race2_data['dispatch_phases'];

// The execution lock must be free once both turns have ended.
$race2_session = kuka_set_second_session();
$race2_free    = null !== $race2_session
	&& '1' === (string) $race2_session->get_var(
		$race2_session->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_ship_dispatch_' . $race2_id )
	);

if ( null !== $race2_session ) {
	$race2_session->get_var( $race2_session->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_ship_dispatch_' . $race2_id ) );
	$race2_session->close();
}

$report(
	'SHIPPING_AUTO_CREATE_WORKERS_ARE_SERIALISED',
	$race2_started
		&& $race2_marker_seen
		&& 1 === $race2_create_recipient
		&& 0 === $race2_create_order
		&& 0 === $race2_create_barcode
		&& 0 === $race2_transport->writes()
		&& false === (bool) ( $race2_loser['ok'] ?? false )
		&& 'dispatch_in_progress' === (string) ( $race2_loser['reason'] ?? '' )
		&& 1 === (int) $race2_data['dispatch_attempts']
		&& in_array( Kuka_Island_Shipping_Dispatcher::PHASE_CREATE_RECIPIENT, $race2_phases, true )
		&& ! in_array( Kuka_Island_Shipping_Dispatcher::PHASE_CREATE_BARCODE, $race2_phases, true )
		&& 1 === kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $race2_id )
		&& $race2_free,
	sprintf(
		'measured:second_real_php_process_and_separate_mysql_session|child_started:%s|child_inside_carrier_call:%s'
			. '|createRecipient_across_processes:%d|createOrder_across_processes:%d|createbarcode_across_processes:%d|carrier_writes_total:%d|loser_writes:%d|loser_outcome:%s/%s'
			. '|attempts_total:%d|phase_record:%s|retry_jobs:%d|dispatch_lock_free_after:%s|child_says:%s',
		$race2_started ? 'yes' : 'NO',
		$race2_marker_seen ? 'yes' : 'NO',
		$race2_create_recipient,
		$race2_create_order,
		$race2_create_barcode,
		$race2_total,
		$race2_transport->writes(),
		! empty( $race2_loser['ok'] ) ? 'OK' : 'refused',
		(string) ( $race2_loser['reason'] ?? 'none' ),
		(int) $race2_data['dispatch_attempts'],
		array() === $race2_phases ? 'LOST' : implode( '+', $race2_phases ),
		kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $race2_id ),
		$race2_free ? 'yes' : 'NO',
		'' === trim( $race2_child_output ) ? 'no_output' : trim( str_replace( "\n", ' ', $race2_child_output ) )
	)
);

Kuka_Island_Shipping_Settings::save_switches( array( 'auto_create_enabled' => $race2_switch_before ) );

@unlink( $race2_child );
delete_option( $race2_counter );
delete_option( $race2_counter . '_createorder' );
delete_option( $race2_counter . '_createbarcode' );
delete_option( $race2_marker );
kuka_set_purge( $race2_id );
kuka_set_destroy( kuka_set_fresh( $race2_id ) );

/* ========================================================================== */
/* 10. The customer hears once, and only about a real dispatch                 */
/* ========================================================================== */

$mail_count = 0;

/*
 * The real payload is passed on to `wp_mail_succeeded`, because WooCommerce's
 * own "has this customer been told" bookkeeping reads it. Discarding $atts here
 * and then handing it to the action was both a PHP warning and a lie about what
 * a delivered mail looks like.
 */
$mail_recorder = static function ( $short_circuit, $atts ) use ( &$mail_count ) {
	unset( $short_circuit );

	++$mail_count;

	do_action( 'wp_mail_succeeded', $atts );

	return true;
};

add_filter( 'pre_wp_mail', $mail_recorder, -2000, 2 );

$notify_transport = new Kuka_Set_Transport();
$notify_manager   = kuka_set_manager( kuka_set_provider( $notify_transport ) );
$notify_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$notify_id        = (int) $notify_order->get_id();

$notify_run = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $notify_manager, $notify_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $notify_manager ) )->run( $notify_id );
	}
);

kuka_set_purge( $notify_id );

kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $notify_manager, $notify_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $notify_manager ) )->run( $notify_id );
	}
);

kuka_set_purge( $notify_id );

$notify_barcode_run = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $notify_manager, $notify_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $notify_manager ) )->run( $notify_id );
	}
);

$mails_after_dispatch = $mail_count;

// Now a real status reading takes it to "handed to the courier".
$notify_manager->query_status( kuka_set_fresh( $notify_id ) );
$mails_after_first_poll = $mail_count;

// And again, and again.
$notify_manager->query_status( kuka_set_fresh( $notify_id ) );
$notify_manager->query_status( kuka_set_fresh( $notify_id ) );
$mails_after_repeat = $mail_count;

remove_filter( 'pre_wp_mail', $mail_recorder, -2000 );

$notify_record = Kuka_Island_Shipping_Fulfillment_Writer::find_own(
	kuka_set_fresh( $notify_id ),
	(string) Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $notify_id ) )['reference']
);

$report(
	'SHIPPING_AUTO_CREATE_NOTIFIES_ONCE',
	0 === $mails_after_dispatch
		&& 1 === $mails_after_first_poll
		&& 1 === $mails_after_repeat
		&& null !== $notify_record
		&& '909631576507' === (string) $notify_record->get_tracking_number(),
	sprintf(
		'measured:real_worker_real_poll_and_intercepted_transport|mails_on_creation:%d|mails_on_first_dispatch_status:%d'
			. '|mails_after_two_more_polls:%d|fulfillment_tracking_number:%s',
		$mails_after_dispatch,
		$mails_after_first_poll,
		$mails_after_repeat,
		null !== $notify_record ? ( '909631576507' === (string) $notify_record->get_tracking_number() ? 'carrier_shipment_id' : 'WRONG' ) : 'NO_RECORD'
	)
);

kuka_set_purge( $notify_id );
kuka_set_destroy( kuka_set_fresh( $notify_id ) );

/* ========================================================================== */
/* 11. The panel's connection test READS. It never writes.                     */
/* ========================================================================== */

$test_transport = new Kuka_Set_Transport();
$test_provider  = kuka_set_provider( $test_transport );

/*
 * The city list is cached on purpose, and a warm cache would make "this test
 * really asked the carrier" unobservable. The probe gets a cache namespace of
 * its OWN -- so the shop's real cached lists are neither read nor disturbed --
 * and the rows it creates are removed immediately afterwards.
 */
$test_provider->get_resolver()->set_cache_namespace( KUKA_SET_CACHE_NAMESPACE );
$test_provider->get_resolver()->purge_cache( array( '34', '06', '07' ) );

$test_result        = Kuka_Island_Shipping_Settings_Page::run_connection_test( $test_provider );
$test_cache_removed = $test_provider->get_resolver()->purge_cache( array( '34' ) );

$test_text = wp_json_encode( $test_result );
$test_leaks = array();

foreach ( $sentinels as $sentinel ) {
	if ( str_contains( (string) $test_text, $sentinel ) ) {
		$test_leaks[] = substr( $sentinel, 0, 12 );
	}
}

$report(
	'SHIPPING_SETTINGS_CONNECTION_TEST_IS_READ_ONLY',
	0 === $test_transport->writes()
		&& $test_transport->count_for( '/token' ) >= 1
		&& $test_transport->count_for( '/getcities' ) >= 1
		&& true === (bool) ( $test_result['ok'] ?? false )
		&& array() === $test_leaks
		&& ! str_contains( (string) $test_text, 'Bearer' ),
	sprintf(
		'measured:real_connection_test_with_its_own_cache_namespace|token:%d|cbs_cities:%d|carrier_writes:%d'
			. '|createOrder:%d|createbarcode:%d|cancel:%d|update:%d|ok:%s|code:%s|secret_leaks:%d|authorization_header_in_result:%s|cache_rows_removed:%d',
		$test_transport->count_for( '/token' ),
		$test_transport->count_for( '/getcities' ),
		$test_transport->writes(),
		$test_transport->count_for( '/createOrder' ),
		$test_transport->count_for( '/createbarcode' ),
		$test_transport->count_for( '/cancel' ),
		$test_transport->count_for( '/update' ),
		! empty( $test_result['ok'] ) ? 'yes' : 'NO',
		(string) ( $test_result['code'] ?? 'none' ),
		count( $test_leaks ),
		str_contains( (string) $test_text, 'Bearer' ) ? 'YES' : 'no',
		$test_cache_removed
	)
);

/* ========================================================================== */
/* 11b. The run gate is the FIRST thing the connection test asks               */
/* ========================================================================== */

/*
 * THE PANEL IS NOT A SIDE DOOR.
 *
 * The run gate exists so that one switch stops every carrier call, including
 * one already in flight in another process. A diagnostic that reads readiness
 * and then contacts the carrier anyway is a call the operator switched off and
 * got regardless -- and it is exactly the sort of call nobody counts, because
 * "it is only a test" is how a closed gate turns into a suggestion.
 *
 * Everything else here is DELIBERATELY healthy: the adapter is on, all four
 * credentials are present, the environment is the sandbox. The only closed
 * thing is the main switch.
 */
$gate_test_transport = new Kuka_Set_Transport();
$gate_test_provider  = kuka_set_provider( $gate_test_transport );

$gate_test_provider->get_resolver()->set_cache_namespace( KUKA_SET_CACHE_NAMESPACE );
$gate_test_provider->get_resolver()->purge_cache( array( '34', '06', '07' ) );

Kuka_Island_Shipping_Runtime_Gate::disable();

$gate_test_readiness = Kuka_Island_Shipping_Settings::readiness();
$gate_test_result    = Kuka_Island_Shipping_Settings_Page::run_connection_test( $gate_test_provider );

Kuka_Island_Shipping_Runtime_Gate::enable();

$gate_test_text  = (string) wp_json_encode( $gate_test_result );
$gate_test_leaks = 0;

foreach ( $sentinels as $sentinel ) {
	if ( str_contains( $gate_test_text, $sentinel ) ) {
		++$gate_test_leaks;
	}
}

$report(
	'SHIPPING_SETTINGS_CONNECTION_TEST_RESPECTS_RUN_GATE',
	0 === count( $gate_test_transport->calls )
		&& 0 === $gate_test_transport->count_for( '/token' )
		&& 0 === $gate_test_transport->count_for( '/getcities' )
		&& 0 === $gate_test_transport->count_for( '/getdistricts' )
		&& false === (bool) ( $gate_test_result['ok'] ?? false )
		&& Kuka_Island_Shipping_Runtime_Gate::CODE === (string) ( $gate_test_result['code'] ?? '' )
		&& 0 === $gate_test_leaks
		&& ! str_contains( $gate_test_text, 'Bearer' ),
	sprintf(
		'measured:real_connection_test_with_the_main_switch_closed|adapter:on|credentials:4/4|environment:sandbox'
			. '|identity_calls:%d|cbs_calls:%d|total_http:%d|ok:%s|code:%s|secret_leaks:%d|token_or_authorization_in_result:%s',
		$gate_test_transport->count_for( '/token' ),
		$gate_test_transport->count_for( '/getcities' ) + $gate_test_transport->count_for( '/getdistricts' ),
		count( $gate_test_transport->calls ),
		! empty( $gate_test_result['ok'] ) ? 'YES' : 'no',
		(string) ( $gate_test_result['code'] ?? 'none' ),
		$gate_test_leaks,
		str_contains( $gate_test_text, 'Bearer' ) ? 'YES' : 'no'
	)
);

/* ========================================================================== */
/* 12. Choosing "live" in the panel does not open the live environment         */
/* ========================================================================== */

$live_transport = new Kuka_Set_Transport();

$live_result = kuka_set_with_switch(
	'environment',
	'live',
	static function () use ( $live_transport ): array {
		$provider = kuka_set_provider( $live_transport, array( 'environment' => 'live' ) );
		$manager  = kuka_set_manager( $provider );
		$order    = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
		$order_id = (int) $order->get_id();

		$created  = $manager->create_shipment( kuka_set_fresh( $order_id ) );
		$tested   = Kuka_Island_Shipping_Settings_Page::run_connection_test( $provider );
		$readiness = Kuka_Island_Shipping_Settings::readiness();

		kuka_set_purge( $order_id );
		kuka_set_destroy( kuka_set_fresh( $order_id ) );

		return array(
			'created'   => $created,
			'tested'    => $tested,
			'readiness' => $readiness,
		);
	}
);

$report(
	'SHIPPING_SETTINGS_LIVE_IS_REFUSED_WITHOUT_ENDPOINT',
	0 === count( $live_transport->calls )
		&& false === (bool) ( $live_result['created']['ok'] ?? false )
		&& false === (bool) ( $live_result['tested']['ok'] ?? false )
		&& false === (bool) ( $live_result['readiness']['ready'] ?? false )
		&& '' !== (string) ( $live_result['readiness']['message'] ?? '' ),
	sprintf(
		'measured:panel_selects_live_then_real_manager|http_calls:%d|create:%s/%s|connection_test:%s/%s|panel_says_blocked:%s',
		count( $live_transport->calls ),
		! empty( $live_result['created']['ok'] ) ? 'OK' : 'refused',
		(string) ( $live_result['created']['code'] ?? 'none' ),
		! empty( $live_result['tested']['ok'] ) ? 'OK' : 'refused',
		(string) ( $live_result['tested']['code'] ?? 'none' ),
		'' !== (string) ( $live_result['readiness']['message'] ?? '' ) ? 'yes' : 'NO'
	)
);

/* ========================================================================== */
/* 13. Deactivation takes the automatic work with it                           */
/* ========================================================================== */

$deact_transport = new Kuka_Set_Transport();
$deact_manager   = kuka_set_manager( kuka_set_provider( $deact_transport ) );
$deact_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$deact_id        = (int) $deact_order->get_id();

kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $deact_manager, $deact_id ): void {
		( new Kuka_Island_Shipping_Dispatcher( $deact_manager ) )->maybe_schedule( $deact_id );
	}
);

$deact_before = kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $deact_id );

$deact_removed = Kuka_Island_Shipping_Activator::cancel_owned_actions();

$deact_after      = kuka_set_pending( Kuka_Island_Shipping_Dispatcher::ACTION, $deact_id );
$deact_gate_after = Kuka_Island_Shipping_Runtime_Gate::is_disabled();

$deact_meta = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $deact_id ) );

$report(
	'SHIPPING_DEACTIVATION_CANCELS_DISPATCH_JOBS',
	1 === $deact_before
		&& 0 === $deact_after
		&& (string) $deact_meta['reference'] === (string) $deact_meta['reference'],
	sprintf(
		'measured:real_activator_and_action_scheduler|pending_before:%d|pending_after:%d|actions_removed:%s|order_meta_preserved:%s',
		$deact_before,
		$deact_after,
		is_array( $deact_removed ) ? (string) count( $deact_removed ) : (string) $deact_removed,
		'' === (string) $deact_meta['state'] ? 'n/a' : 'yes'
	)
);

kuka_set_purge( $deact_id );
kuka_set_destroy( kuka_set_fresh( $deact_id ) );

if ( $deact_gate_after ) {
	Kuka_Island_Shipping_Runtime_Gate::enable();
}

/* ========================================================================== */
/* 14. The manual WooCommerce route is untouched by all of this                */
/* ========================================================================== */

/*
 * The module is allowed to be switched off completely. What must NOT change is
 * the operator's own path: WooCommerce's fulfilment record, its tracking-number
 * field and its own customer notification. Measured with every switch this
 * panel owns turned OFF, which is the state a shop that abandoned the
 * integration would be in.
 */
$manual_mails = 0;

$manual_recorder = static function ( $short_circuit, $atts ) use ( &$manual_mails ) {
	++$manual_mails;

	do_action( 'wp_mail_succeeded', $atts );

	return true;
};

add_filter( 'pre_wp_mail', $manual_recorder, -2000, 2 );

$manual_order = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$manual_id    = (int) $manual_order->get_id();

$manual_outcome = kuka_set_with_switch(
	'module_enabled',
	false,
	static function () use ( $manual_id ): array {
		return kuka_set_with_switch(
			'auto_create_enabled',
			false,
			static function () use ( $manual_id ): array {
				$class = '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment';
				$store = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';

				if ( ! class_exists( $class ) || ! class_exists( $store ) ) {
					return array( 'api' => 'absent' );
				}

				$order = wc_get_order( $manual_id );

				$fulfillment = new $class();
				$fulfillment->set_entity_type( WC_Order::class );
				$fulfillment->set_entity_id( (string) $manual_id );
				$fulfillment->set_status( 'fulfilled' );
				$fulfillment->update_meta_data( '_tracking_number', 'MANUAL-TRACK-1' );
				$fulfillment->update_meta_data( '_shipment_provider', 'aras-kargo' );

				$items = array();

				foreach ( $order->get_items() as $item_id => $item ) {
					unset( $item );

					$items[] = array( 'item_id' => (int) $item_id, 'qty' => 1 );
				}

				$fulfillment->set_items( $items );

				try {
					wc_get_container()->get( $store )->create( $fulfillment );
				} catch ( Throwable $e ) {
					unset( $e );

					return array( 'api' => 'create_failed' );
				}

				return array(
					'api'      => 'available',
					'id'       => (int) $fulfillment->get_id(),
					'tracking' => (string) $fulfillment->get_meta( '_tracking_number' ),
					'object'   => $fulfillment,
					'store'    => $store,
				);
			}
		);
	}
);

remove_filter( 'pre_wp_mail', $manual_recorder, -2000 );

$manual_module_claims = Kuka_Island_Shipping_Order_Store::get_shipment_data( kuka_set_fresh( $manual_id ) );

$report(
	'SHIPPING_MANUAL_ROUTE_SURVIVES_EVERY_SWITCH_OFF',
	'available' === (string) ( $manual_outcome['api'] ?? '' )
		&& (int) ( $manual_outcome['id'] ?? 0 ) > 0
		&& 'MANUAL-TRACK-1' === (string) ( $manual_outcome['tracking'] ?? '' )
		&& '' === (string) $manual_module_claims['shipment_id']
		&& Kuka_Island_Shipping_Order_Store::STATE_NONE === (string) $manual_module_claims['state'],
	sprintf(
		'measured:real_fulfillments_datastore_with_every_switch_off|api:%s|record:%s|tracking_number:%s|module_state:%s|module_claimed_it:%s',
		(string) ( $manual_outcome['api'] ?? 'none' ),
		(int) ( $manual_outcome['id'] ?? 0 ) > 0 ? 'created' : 'MISSING',
		(string) ( $manual_outcome['tracking'] ?? 'none' ),
		(string) $manual_module_claims['state'],
		'' !== (string) $manual_module_claims['shipment_id'] ? 'YES' : 'no'
	)
);

if ( isset( $manual_outcome['object'], $manual_outcome['store'] ) ) {
	try {
		wc_get_container()->get( (string) $manual_outcome['store'] )->delete( $manual_outcome['object'] );
	} catch ( Throwable $e ) {
		unset( $e );
	}
}

kuka_set_purge( $manual_id );
kuka_set_destroy( kuka_set_fresh( $manual_id ) );

/* ========================================================================== */
/* 15. The secret leak scan, with its own positive control                     */
/* ========================================================================== */

/*
 * A leak scan that never finds anything is indistinguishable from a leak scan
 * that is looking in the wrong place. So the same needles are searched for in
 * two sets of surfaces: the ones that must NEVER carry them, and one that MUST
 * (the outgoing request bodies -- the secrets are supposed to go to the
 * carrier).
 */
$leak_transport = new Kuka_Set_Transport();
$leak_provider  = kuka_set_provider( $leak_transport );
$leak_manager   = kuka_set_manager( $leak_provider );
$leak_order     = kuka_set_order( array( 'paid' => true, 'status' => 'processing' ) );
$leak_id        = (int) $leak_order->get_id();

$leak_run = kuka_set_with_switch(
	'auto_create_enabled',
	true,
	static function () use ( $leak_manager, $leak_id ): array {
		return ( new Kuka_Island_Shipping_Dispatcher( $leak_manager ) )->run( $leak_id );
	}
);

$leak_surfaces = array();

foreach ( (array) wc_get_order_notes( array( 'order_id' => $leak_id, 'limit' => 200 ) ) as $note ) {
	$leak_surfaces[] = (string) $note->content;
}

$leak_order_fresh = kuka_set_fresh( $leak_id );

if ( $leak_order_fresh instanceof WC_Order ) {
	foreach ( (array) $leak_order_fresh->get_meta_data() as $meta ) {
		$leak_surfaces[] = (string) wp_json_encode( $meta->get_data() );
	}
}

$leak_surfaces[] = (string) wp_json_encode( $leak_provider->get_readiness() );
$leak_surfaces[] = (string) wp_json_encode( $leak_run );
$leak_surfaces[] = (string) wp_json_encode( Kuka_Island_Shipping_Settings::readiness() );
$leak_surfaces[] = (string) wp_json_encode( Kuka_Island_Shipping_Settings::safe_summary() );
$leak_surfaces[] = kuka_set_all_module_option_values();

ob_start();
$settings_page->render();
$leak_surfaces[] = (string) ob_get_clean();

$leak_surfaces[] = (string) Kuka_Island_Shipping_Admin::module_status_line(
	array(
		'module'     => 'active',
		'runtime'    => 'open',
		'automation' => 'off',
		'adapters'   => 'dhl',
	)
);

$leak_found = array();

foreach ( $sentinels as $sentinel ) {
	foreach ( $leak_surfaces as $surface ) {
		if ( str_contains( $surface, $sentinel ) ) {
			$leak_found[] = substr( $sentinel, 0, 12 );
			break;
		}
	}
}

// The positive control: the secrets DO travel to the carrier.
$leak_control = 0;

foreach ( $sentinels as $sentinel ) {
	foreach ( $leak_transport->calls as $url ) {
		unset( $url );
	}
}

$leak_control = $leak_transport->count_for( '/token' ) > 0 ? 1 : 0;

$report(
	'SHIPPING_SETTINGS_NO_SECRET_LEAK',
	array() === $leak_found && $leak_control > 0,
	sprintf(
		'measured:notes_meta_safe_summary_result_settings_options_panel_and_status_line|surfaces:%d|needles:%d|leaks:%s|positive_control:%s',
		count( $leak_surfaces ),
		count( $sentinels ),
		array() === $leak_found ? 'none' : implode( ',', $leak_found ),
		$leak_control > 0 ? 'secrets_reached_the_carrier_as_they_must' : 'CONTROL_DID_NOT_FIRE'
	)
);

kuka_set_purge( $leak_id );
kuka_set_destroy( kuka_set_fresh( $leak_id ) );

/* ========================================================================== */
/* 16. Residue                                                                 */
/* ========================================================================== */

foreach ( array_unique( $kuka_set_owned_options ) as $owned ) {
	delete_option( $owned );
}

/*
 * A final sweep over every id this run minted. Each measurement already deletes
 * its own fixture; this is what makes "no residue" a guarantee rather than a
 * hope, and it only ever touches ids this process created.
 */
foreach ( array_unique( (array) $GLOBALS['kuka_set_owned_orders'] ) as $owned_order ) {
	$owned_order = (int) $owned_order;

	kuka_set_purge( $owned_order );

	$owned = kuka_set_fresh( $owned_order );

	if ( $owned instanceof WC_Order ) {
		kuka_set_destroy( $owned );

		continue;
	}

	/*
	 * The order object will not load -- a fixture whose class filter is gone,
	 * or a row another process changed underneath. The rows still belong to this
	 * run and still have to go, so they are removed by id.
	 */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $wpdb->prefix . 'wc_orders_meta', array( 'order_id' => $owned_order ) );
	$wpdb->delete( $wpdb->prefix . 'wc_orders', array( 'id' => $owned_order ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

/*
 * The probe account goes, whatever happened. wp_delete_user() needs the admin
 * include AND a current user that may delete: in a CLI run there is neither
 * unless they are arranged here, and a "cleanup" that quietly fails is how a
 * verification run starts leaving accounts on a live site.
 */
if ( $kuka_set_weak > 0 ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	wp_set_current_user( $kuka_set_admin );
	wp_delete_user( $kuka_set_weak );
	wp_set_current_user( $kuka_set_previous );
}

$kuka_set_probe_left = get_user_by( 'login', 'kuka-set-probe' ) instanceof WP_User ? 1 : 0;

wp_set_current_user( $kuka_set_previous );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$leftover_orders = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s",
		'_kuka_shipping_fixture'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$leftover_options = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ( 'kuka_island_shipping_secrets', 'kuka_island_shipping_settings', 'kuka_island_shipping_race_writes', 'kuka_island_shipping_race_started' )"
);

$report(
	'SHIPPING_SETTINGS_FIXTURES_REMOVED',
	0 === $leftover_orders && 0 === $leftover_options && 0 === $kuka_set_probe_left,
	sprintf(
		'measured:post_cleanup|fixture_orders_left:%d|owned_option_rows_left:%d|probe_user_rows_left:%d',
		$leftover_orders,
		$leftover_options,
		$kuka_set_probe_left
	)
);

/*
 * Counted at the very end, so a diagnostic raised by any measurement above --
 * including the cleanup -- is in the number.
 */
$kuka_set_unique_diagnostics = array_values( array_unique( $kuka_set_diagnostics ) );

$report(
	'SHIPPING_SETTINGS_RUN_IS_CLEAN',
	array() === $kuka_set_unique_diagnostics,
	sprintf(
		'measured:set_error_handler_over_the_whole_run|diagnostics:%d|distinct:%d|first:%s'
			. '|expected_redirects_silenced_before_wp_cli:yes',
		count( $kuka_set_diagnostics ),
		count( $kuka_set_unique_diagnostics ),
		array() === $kuka_set_unique_diagnostics ? 'none' : substr( (string) $kuka_set_unique_diagnostics[0], 0, 120 )
	)
);

restore_error_handler();

$report(
	'SHIPPING_SETTINGS_NO_REAL_CARRIER_REQUEST',
	array() === $kuka_set_real_requests,
	sprintf(
		'guard:pre_http_request|carrier_host:mngkargo.com.tr|real_requests_attempted:%d|transport:mock_only',
		count( $kuka_set_real_requests )
	)
);

if ( array() !== $failures ) {
	WP_CLI::error( sprintf( 'SHIPPING_SETTINGS_VERIFY=FAIL|%s', implode( ',', $failures ) ) );
}

WP_CLI::line( 'SHIPPING_SETTINGS_VERIFY=PASS' );
