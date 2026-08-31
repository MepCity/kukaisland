<?php
/**
 * Comprehensive verification for EDM e-Invoice / e-Archive integration (Stage 1).
 *
 * Every SOAP contract assertion runs through the PRODUCTION
 * Kuka_Island_Core_EDM_Client, whose transport hands the real request to a
 * SoapClient built from the real EDM WSDL. The intercepted, WSDL-serialised
 * request XML is then asserted with DOMXPath. No hand-rolled SOAP array is used
 * as evidence of what production sends.
 *
 * Run with:
 * docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-invoice-integration.php
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

define( 'KUKA_INVOICE_KEYSET_LIBRARY_ONLY', true );
require_once __DIR__ . '/verify-invoice-keyset.php';

// Suppress WooCommerce emails during test execution to prevent mail subprocesses and notes.
add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_refunded_order', '__return_false' );

$failures = array();
$report    = static function ( string $name, bool $passed, string $detail = '' ) use ( &$failures ): void {
	WP_CLI::line( sprintf( '%s=%s%s', $name, $passed ? 'PASS' : 'FAIL', '' !== $detail ? '|' . $detail : '' ) );
	if ( ! $passed ) {
		$failures[] = $name;
	}
};
$note = static function ( string $line ): void {
	WP_CLI::line( $line );
};

/* ========================================================================== */
/* Harness: run-scoped, DB-discoverable fixtures with a 4-state cleanup machine */
/* ========================================================================== */

const KUKA_TEST_RUN_META = '_kuka_isolation_run_id';
const KUKA_TEST_QUEUE_HOOK = 'kuka_island_process_order_invoice';

$test_run_id  = wp_generate_uuid4();
$probe_run_id = wp_generate_uuid4();

$GLOBALS['kuka_invoice_tracked_orders'] = array();

// Cleanup coordinator states: idle -> running -> succeeded | failed.
$GLOBALS['kuka_invoice_cleanup_state'] = 'idle';
$GLOBALS['kuka_probe_cleanup_state']   = 'idle';
$GLOBALS['kuka_probe_cleanup_state_2'] = 'idle';

/**
 * Discover every order belonging to a run directly from the database.
 *
 * This is the fatal-error safety net: even if the in-process registry is lost,
 * each fixture remains discoverable by its run ID.
 *
 * @param string $run_id Isolation run ID.
 * @return array<int, int> Order IDs.
 */
function kuka_discover_run_order_ids( string $run_id ): array {
	global $wpdb;

	$ids = array();

	$hpos_table = $wpdb->prefix . 'wc_orders_meta';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $hpos_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$ids = array_merge(
			$ids,
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT order_id FROM ' . $hpos_table . ' WHERE meta_key = %s AND meta_value = %s',
					KUKA_TEST_RUN_META,
					$run_id
				)
			)
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = array_merge(
		$ids,
		(array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				KUKA_TEST_RUN_META,
				$run_id
			)
		)
	);

	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	sort( $ids );

	return $ids;
}

/**
 * Create a tracked test order.
 *
 * Every fixture carries KUKA_TEST_RUN_META so it stays discoverable from the
 * database. The production fixture marker
 * (Kuka_Island_Core_Invoice_Fixture_Guard::META_FIXTURE) is applied only when
 * the scenario is meant to be permanently barred from real invoicing.
 *
 * @param string               $run_id Isolation run ID.
 * @param array<string, mixed> $props Order properties.
 * @param bool                 $mark_production_fixture Apply the production fixture marker.
 */
function kuka_create_test_order( string $run_id, array $props = array(), bool $mark_production_fixture = false ): WC_Order {
	$order = wc_create_order();
	$order->update_meta_data( KUKA_TEST_RUN_META, $run_id );
	if ( $mark_production_fixture ) {
		$order->update_meta_data( Kuka_Island_Core_Invoice_Fixture_Guard::META_FIXTURE, '1' );
	}

	$setters = array(
		'status'         => 'set_status',
		'payment_method' => 'set_payment_method',
		'first_name'     => 'set_billing_first_name',
		'last_name'      => 'set_billing_last_name',
		'email'          => 'set_billing_email',
		'address_1'      => 'set_billing_address_1',
		'city'           => 'set_billing_city',
		'postcode'       => 'set_billing_postcode',
		'country'        => 'set_billing_country',
		'company'        => 'set_billing_company',
		'currency'       => 'set_currency',
	);
	foreach ( $setters as $key => $method ) {
		if ( isset( $props[ $key ] ) && '' !== $props[ $key ] ) {
			$order->{$method}( $props[ $key ] );
		}
	}
	if ( isset( $props['total'] ) ) {
		$order->set_total( $props['total'] );
	}

	$order->save();

	$GLOBALS['kuka_invoice_tracked_orders'][ $order->get_id() ] = $run_id;

	return $order;
}

/**
 * Delete one fixture order after strict run-ID ownership validation.
 *
 * @param int    $order_id Order ID.
 * @param string $expected_run_id Run ID that must own the fixture.
 * @return bool True when deleted (or already gone); false on ownership refusal.
 */
function kuka_test_delete_order( int $order_id, string $expected_run_id ): bool {
	if ( $order_id <= 0 ) {
		return true;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		unset( $GLOBALS['kuka_invoice_tracked_orders'][ $order_id ] );
		return true; // Already deleted: idempotent re-entry.
	}

	$run_id = (string) $order->get_meta( KUKA_TEST_RUN_META, true );
	if ( '' === $run_id || $run_id !== $expected_run_id ) {
		WP_CLI::warning(
			sprintf(
				'Ownership refusal: order #%d is not owned by run %s (found run_id: %s). Refusing cleanup.',
				$order_id,
				$expected_run_id,
				'' === $run_id ? '<none>' : $run_id
			)
		);
		return false;
	}

	foreach ( wc_get_order_notes( array( 'order_id' => $order_id ) ) as $order_note ) {
		wp_delete_comment( $order_note->id, true );
	}

	$order->delete( true );
	unset( $GLOBALS['kuka_invoice_tracked_orders'][ $order_id ] );

	return true;
}

/**
 * Remove any queue scheduling residue this run created for the given orders.
 *
 * @param array<int, int> $order_ids Order IDs.
 * @return int Rows/events removed.
 */
function kuka_purge_queue_scheduling( array $order_ids ): int {
	global $wpdb;

	$removed = 0;

	foreach ( $order_ids as $order_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( KUKA_TEST_QUEUE_HOOK, array( 'order_id' => $order_id ), 'kuka-island-invoice' );
		}
		$timestamp = wp_next_scheduled( KUKA_TEST_QUEUE_HOOK, array( $order_id ) );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, KUKA_TEST_QUEUE_HOOK, array( $order_id ) );
			++$removed;
		}
	}

	$actions_table = $wpdb->prefix . 'actionscheduler_actions';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $actions_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_table ) ) ) {
		return $removed;
	}

	foreach ( $order_ids as $order_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$action_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT action_id FROM ' . $actions_table . ' WHERE hook = %s AND args LIKE %s',
				KUKA_TEST_QUEUE_HOOK,
				'%' . $wpdb->esc_like( '"order_id":' . $order_id ) . '%'
			)
		);

		foreach ( $action_ids as $action_id ) {
			$logs_table = $wpdb->prefix . 'actionscheduler_logs';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $logs_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $logs_table, array( 'action_id' => (int) $action_id ), array( '%d' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $actions_table, array( 'action_id' => (int) $action_id ), array( '%d' ) );
			++$removed;
		}
	}

	return $removed;
}

/**
 * Count queue scheduling rows still referencing the given orders.
 *
 * @param array<int, int> $order_ids Order IDs.
 */
function kuka_count_queue_scheduling( array $order_ids ): int {
	global $wpdb;

	$count = 0;
	foreach ( $order_ids as $order_id ) {
		if ( false !== wp_next_scheduled( KUKA_TEST_QUEUE_HOOK, array( $order_id ) ) ) {
			++$count;
		}
	}

	$actions_table = $wpdb->prefix . 'actionscheduler_actions';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $actions_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_table ) ) ) {
		return $count;
	}

	foreach ( $order_ids as $order_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$count += (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $actions_table . ' WHERE hook = %s AND args LIKE %s',
				KUKA_TEST_QUEUE_HOOK,
				'%' . $wpdb->esc_like( '"order_id":' . $order_id ) . '%'
			)
		);
	}

	return $count;
}

/**
 * Cleanup coordinator with an explicit four-state machine and re-entry guard.
 *
 * idle -> running -> succeeded | failed
 *
 * @param string          $expected_run_id Run ID whose fixtures may be deleted.
 * @param string          $state_key Global key holding this coordinator's state.
 * @param array<int, int> $candidate_ids Extra order IDs to consider.
 * @return array{state: string, reentry_blocked: bool, refused: array<int, int>, leftover: array<int, int>, considered: array<int, int>}
 */
function kuka_run_cleanup( string $expected_run_id, string $state_key, array $candidate_ids = array() ): array {
	$current = (string) ( $GLOBALS[ $state_key ] ?? 'idle' );
	if ( 'idle' !== $current ) {
		return array(
			'state'           => $current,
			'reentry_blocked' => true,
			'refused'         => array(),
			'leftover'        => array(),
			'considered'      => array(),
		);
	}

	$GLOBALS[ $state_key ] = 'running';

	$considered = array_values(
		array_unique(
			array_map( 'intval', array_merge( $candidate_ids, kuka_discover_run_order_ids( $expected_run_id ) ) )
		)
	);
	sort( $considered );

	$refused = array();
	foreach ( $considered as $order_id ) {
		if ( ! kuka_test_delete_order( $order_id, $expected_run_id ) ) {
			$refused[] = $order_id;
		}
	}

	$leftover = kuka_discover_run_order_ids( $expected_run_id );

	$GLOBALS[ $state_key ] = ( empty( $refused ) && empty( $leftover ) ) ? 'succeeded' : 'failed';

	return array(
		'state'           => $GLOBALS[ $state_key ],
		'reentry_blocked' => false,
		'refused'         => $refused,
		'leftover'        => $leftover,
		'considered'      => $considered,
	);
}

/**
 * Map a coordinator state to a process exit code. Anything but success is non-zero.
 */
function kuka_cleanup_exit_code( string $state ): int {
	return 'succeeded' === $state ? 0 : 1;
}

// Fatal-error safety net: if the explicit cleanup never ran, run it here and
// force a non-zero exit whenever it did not fully succeed.
register_shutdown_function(
	static function () use ( $test_run_id, $probe_run_id ): void {
		foreach ( array( $test_run_id, $probe_run_id ) as $index => $run_id ) {
			$state_key = 0 === $index ? 'kuka_invoice_cleanup_state' : 'kuka_probe_cleanup_state_2';
			if ( 'idle' === (string) ( $GLOBALS[ $state_key ] ?? 'idle' ) ) {
				kuka_run_cleanup( $run_id, $state_key, array_keys( $GLOBALS['kuka_invoice_tracked_orders'] ) );
			}
			$state = (string) ( $GLOBALS[ $state_key ] ?? 'idle' );
			if ( 0 !== kuka_cleanup_exit_code( $state ) ) {
				WP_CLI::warning( sprintf( 'Cleanup coordinator ended in state "%s" for run %s.', $state, $run_id ) );
				exit( kuka_cleanup_exit_code( $state ) );
			}
		}
	}
);

/* ========================================================================== */
/* Shared fixtures                                                            */
/* ========================================================================== */

$pre_keysets = kuka_invoice_capture_keysets();

$ready_overrides = array(
	'username'          => 'test_user',
	'password'          => 'secret_password_123',
	'secret_key'        => 'secret_key_456',
	'sender_vkn'        => '1234567890',
	'sender_alias'      => 'urn:mail:defaultgb@kukaisland.com',
	'sender_title'      => 'Kuka Island Tasarım A.Ş.',
	'sender_tax_office' => 'Kadıköy',
	'sender_address'    => 'Caferağa Mah. Moda Cad. No:1',
	'sender_district'   => 'Kadıköy',
	'sender_city'       => 'İstanbul',
	'sender_postcode'   => '34710',
	'series_einvoice'   => 'KUK',
	'series_earchive'   => 'KUK',
);

$config = new Kuka_Island_Core_Invoice_Config( $ready_overrides );

$billing_props = array(
	'status'         => 'processing',
	'payment_method' => 'iyzico',
	'first_name'     => 'Can',
	'last_name'      => 'Yılmaz',
	'email'          => 'can@example.com',
	'address_1'      => 'Caferağa Mah. Moda Cad. No:1',
	'city'           => 'İstanbul',
	'postcode'       => '34710',
	'country'        => 'TR',
	'currency'       => 'TRY',
);

/**
 * Add a taxed product line to an order.
 *
 * @param WC_Order $order Order.
 * @param string   $name Item name.
 * @param string   $subtotal Gross (pre-discount) amount.
 * @param string   $total Net (post-discount) amount.
 * @param int      $rate_id WooCommerce tax rate ID.
 * @param string   $tax Tax charged by WooCommerce on the net amount.
 */
function kuka_add_line( WC_Order $order, string $name, string $subtotal, string $total, int $rate_id, string $tax ): void {
	$item = new WC_Order_Item_Product();
	$item->set_name( $name );
	$item->set_quantity( 1 );
	$item->set_subtotal( $subtotal );
	$item->set_total( $total );
	$item->set_taxes(
		array(
			'total'    => array( $rate_id => $tax ),
			'subtotal' => array( $rate_id => $tax ),
		)
	);
	$order->add_item( $item );
}

/**
 * Add an order-level tax rate row.
 */
function kuka_add_tax_rate( WC_Order $order, int $rate_id, int $percent, string $total ): void {
	$tax_item = new WC_Order_Item_Tax();
	$tax_item->set_rate_id( $rate_id );
	$tax_item->set_rate_percent( $percent );
	$tax_item->set_tax_total( $total );
	$order->add_item( $tax_item );
}

/* ========================================================================== */
/* TEST 1 - PHP SOAP extension                                                */
/* ========================================================================== */

$report( 'INVOICE_SOAP_EXTENSION_AVAILABLE', extension_loaded( 'soap' ) );

/* ========================================================================== */
/* TEST 2 - Credential privacy                                                */
/* ========================================================================== */

$summary      = $config->get_safe_summary();
$summary_json = (string) wp_json_encode( $summary );
$report(
	'INVOICE_CONFIG_SECURITY',
	! str_contains( $summary_json, 'secret_password_123' )
	&& ! str_contains( $summary_json, 'secret_key_456' )
	&& str_contains( (string) $summary['sender_vkn'], '****' ),
	'credentials_hidden:yes|vkn_masked:yes'
);

/* ========================================================================== */
/* TEST 3 - Live readiness validation                                         */
/* ========================================================================== */

$empty_config = new Kuka_Island_Core_Invoice_Config(
	array(
		'username'   => '',
		'password'   => '',
		'sender_vkn' => '',
	)
);
$readiness = $empty_config->check_live_readiness();
$report(
	'INVOICE_LIVE_READINESS_VALIDATION',
	false === $readiness['ready'] && count( $readiness['missing'] ) >= 3,
	sprintf( 'ready:%s|missing_count:%d', $readiness['ready'] ? 'yes' : 'no', count( $readiness['missing'] ) )
);

/* ========================================================================== */
/* TEST 4 (audit item 2) - Generic individual VKN policy defaults to false     */
/* ========================================================================== */

$vkn_default_config = new Kuka_Island_Core_Invoice_Config( $ready_overrides );
$vkn_explicit_true  = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'allow_generic_individual_vkn' => true ) ) );
$vkn_truthy_string  = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'allow_generic_individual_vkn' => '1' ) ) );
$vkn_explicit_false = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'allow_generic_individual_vkn' => false ) ) );

$report(
	'INVOICE_GENERIC_VKN_DEFAULT_FALSE',
	! defined( 'KUKA_EDM_ALLOW_GENERIC_INDIVIDUAL_VKN' ) && false === $vkn_default_config->allow_generic_individual_vkn(),
	sprintf(
		'constant_defined:%s|default_allow:%s',
		defined( 'KUKA_EDM_ALLOW_GENERIC_INDIVIDUAL_VKN' ) ? 'yes' : 'no',
		$vkn_default_config->allow_generic_individual_vkn() ? 'yes' : 'no'
	)
);

$report(
	'INVOICE_GENERIC_VKN_STRICT_TRUE_ONLY',
	true === $vkn_explicit_true->allow_generic_individual_vkn()
	&& false === $vkn_truthy_string->allow_generic_individual_vkn()
	&& false === $vkn_explicit_false->allow_generic_individual_vkn(),
	sprintf(
		'explicit_true:%s|truthy_string:%s|explicit_false:%s',
		$vkn_explicit_true->allow_generic_individual_vkn() ? 'allow' : 'deny',
		$vkn_truthy_string->allow_generic_individual_vkn() ? 'allow' : 'deny',
		$vkn_explicit_false->allow_generic_individual_vkn() ? 'allow' : 'deny'
	)
);

// Behavioural proof through the production mapper: an individual customer with
// no TCKN is rejected by default and only accepted under the explicit policy.
$vkn_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'total' => '110.00' ) ) );
kuka_add_line( $vkn_order, 'Test Ürün', '100.00', '100.00', 1, '10.00' );
kuka_add_tax_rate( $vkn_order, 1, 10, '10.00' );
$vkn_order->save();

$vkn_default_code = '';
try {
	( new Kuka_Island_Core_Invoice_Order_Mapper( $vkn_default_config ) )->map_order_to_invoice_data(
		$vkn_order,
		Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
		'EARSIVFATURA',
		'',
		'KUK2026000000001'
	);
} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
	$vkn_default_code = $e->get_safe_error_code();
}

$vkn_enabled_number = '';
try {
	$vkn_enabled_data   = ( new Kuka_Island_Core_Invoice_Order_Mapper( $vkn_explicit_true ) )->map_order_to_invoice_data(
		$vkn_order,
		Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
		'EARSIVFATURA',
		'',
		'KUK2026000000001'
	);
	$vkn_enabled_number = (string) $vkn_enabled_data['customer']['tax_number'];
} catch ( Exception $e ) {
	$vkn_enabled_number = 'exception:' . $e->getMessage();
}

$report(
	'INVOICE_GENERIC_VKN_RUNTIME_BEHAVIOUR',
	'missing_individual_tckn' === $vkn_default_code && '11111111111' === $vkn_enabled_number,
	sprintf( 'default_error:%s|explicit_true_vkn:%s', $vkn_default_code ?: 'none', $vkn_enabled_number )
);

kuka_test_delete_order( $vkn_order->get_id(), $test_run_id );

/* ========================================================================== */
/* TEST 5 (audit item 3) - Auto-send honours the full can_send_invoice contract */
/* ========================================================================== */

$auto_send_ready = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'auto_send' => true ) ) );
$auto_gaps       = array();
$auto_all_closed = true;

foreach ( array( 'sender_alias', 'series_einvoice', 'series_earchive', 'sender_title', 'sender_tax_office', 'sender_address', 'sender_district', 'sender_city', 'sender_postcode', 'sender_vkn', 'username', 'password' ) as $field ) {
	$broken = new Kuka_Island_Core_Invoice_Config(
		array_merge( $ready_overrides, array( 'auto_send' => true, $field => '' ) )
	);
	$enabled = $broken->is_auto_send_enabled();
	$auto_gaps[ $field ] = $enabled ? 'ENABLED' : 'blocked';
	if ( $enabled ) {
		$auto_all_closed = false;
	}
}

$report(
	'INVOICE_AUTO_SEND_FULL_READINESS_CONTRACT',
	true === $auto_send_ready->is_auto_send_enabled() && $auto_all_closed,
	sprintf(
		'ready_enabled:%s|fields_checked:%d|leaks:%s',
		$auto_send_ready->is_auto_send_enabled() ? 'yes' : 'no',
		count( $auto_gaps ),
		implode( ',', array_keys( array_filter( $auto_gaps, static fn( $v ) => 'ENABLED' === $v ) ) ) ?: 'none'
	)
);

// auto_send=false must never enable the queue even with a complete config.
$auto_send_off = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'auto_send' => false ) ) );
$report(
	'INVOICE_AUTO_SEND_REQUIRES_OPT_IN',
	false === $auto_send_off->is_auto_send_enabled(),
	sprintf( 'auto_send_off_enabled:%s', $auto_send_off->is_auto_send_enabled() ? 'yes' : 'no' )
);

/* ========================================================================== */
/* TEST 6 (audit items 1 + 10) - Fixture guard on the real runtime path        */
/* ========================================================================== */

/**
 * Array-based transport used to count production SOAP operations.
 */
final class Kuka_Island_Test_Tracking_Transport implements Kuka_Island_Core_SOAP_Transport_Interface {
	public array $calls                     = array();
	public bool $simulate_timeout_on_send   = false;
	public bool $simulate_timeout_on_status = false;

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}

	public function call( string $operation, array $parameters ): array {
		$this->calls[ $operation ] = ( $this->calls[ $operation ] ?? 0 ) + 1;

		if ( 'Login' === $operation ) {
			return array(
				'SESSION_ID'     => 'test-session-uuid',
				'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			);
		}

		if ( 'SendInvoice' === $operation ) {
			if ( $this->simulate_timeout_on_send ) {
				throw new SoapFault( 'HTTP', 'Connection timed out after 30 seconds' );
			}
			return array(
				'INVOICE'        => array(
					'UUID' => $parameters['INVOICE'][0]['UUID'] ?? 'uuid-123',
					'ID'   => $parameters['INVOICE'][0]['ID'] ?? 'KUK-UNSET',
				),
				'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			);
		}

		if ( 'GetInvoiceStatus' === $operation ) {
			if ( $this->simulate_timeout_on_status ) {
				throw new SoapFault( 'HTTP', 'Connection timed out during status reconciliation' );
			}
			return array(
				'INVOICE_STATUS' => array(
					'UUID'               => $parameters['INVOICE']['UUID'] ?? 'uuid-123',
					'STATUS'             => '1300',
					'STATUS_DESCRIPTION' => 'Basariyla Tamamlandi',
				),
			);
		}

		if ( 'CheckUser' === $operation ) {
			return array( 'USER' => array() );
		}

		if ( 'CheckCounter' === $operation ) {
			return array( 'COUNTER_LEFT' => 1250 );
		}

		if ( 'Logout' === $operation ) {
			return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}

		return array();
	}
}

/* ========================================================================== */
/* TEST 5B - Login contract: authentication must not require fiscal config     */
/* ========================================================================== */

// Username + password present, sender VKN absent: Login must actually reach the
// transport. Fiscal configuration is not an authentication precondition.
$login_only_config = new Kuka_Island_Core_Invoice_Config(
	array(
		'username'   => 'login_only_user',
		'password'   => 'login_only_pass',
		'sender_vkn' => '',
	)
);
$login_only_transport = new Kuka_Island_Test_Tracking_Transport();
$login_only_client    = new Kuka_Island_Core_EDM_Client( $login_only_config, $login_only_transport );

$login_only_session = '';
$login_only_error   = '';
try {
	$login_only_session = $login_only_client->login();
} catch ( Throwable $t ) {
	$login_only_error = get_class( $t ) . ':' . ( method_exists( $t, 'get_safe_error_code' ) ? $t->get_safe_error_code() : $t->getMessage() );
}

$report(
	'INVOICE_LOGIN_WITHOUT_FISCAL_CONFIG',
	'test-session-uuid' === $login_only_session
	&& 1 === ( $login_only_transport->calls['Login'] ?? 0 )
	&& true === $login_only_config->has_login_credentials()
	&& false === $login_only_config->is_configured()
	&& false === $login_only_config->can_send_invoice()
	&& false === $login_only_config->is_auto_send_enabled(),
	sprintf(
		'transport_Login_calls:%d|session_obtained:%s|has_login_credentials:%s|is_configured:%s|can_send_invoice:%s|auto_send:%s|error:%s',
		$login_only_transport->calls['Login'] ?? 0,
		'' !== $login_only_session ? 'yes' : 'no',
		$login_only_config->has_login_credentials() ? 'yes' : 'no',
		$login_only_config->is_configured() ? 'yes' : 'no',
		$login_only_config->can_send_invoice() ? 'yes' : 'no',
		$login_only_config->is_auto_send_enabled() ? 'yes' : 'no',
		'' === $login_only_error ? 'none' : $login_only_error
	)
);

// SECRET_KEY stays optional: Login works without it and the element is omitted.
$no_secret_login_transport = new Kuka_Island_Test_Tracking_Transport();
$no_secret_login_client    = new Kuka_Island_Core_EDM_Client(
	new Kuka_Island_Core_Invoice_Config(
		array(
			'username'   => 'login_only_user',
			'password'   => 'login_only_pass',
			'secret_key' => '',
			'sender_vkn' => '',
		)
	),
	$no_secret_login_transport
);
$no_secret_session = '';
try {
	$no_secret_session = $no_secret_login_client->login();
} catch ( Throwable $t ) {
	$no_secret_session = '';
}
$report(
	'INVOICE_LOGIN_SECRET_KEY_OPTIONAL',
	'test-session-uuid' === $no_secret_session && 1 === ( $no_secret_login_transport->calls['Login'] ?? 0 ),
	sprintf( 'transport_Login_calls:%d|session_obtained:%s', $no_secret_login_transport->calls['Login'] ?? 0, '' !== $no_secret_session ? 'yes' : 'no' )
);

// Missing username or password: rejected BEFORE any transport call.
$login_reject_cases = array(
	'no_username'      => array( 'username' => '', 'password' => 'p' ),
	'no_password'      => array( 'username' => 'u', 'password' => '' ),
	'neither'          => array( 'username' => '', 'password' => '' ),
	'whitespace_user'  => array( 'username' => '   ', 'password' => 'p' ),
);
$login_reject_results = array();
$login_reject_ok      = true;
foreach ( $login_reject_cases as $case => $overrides ) {
	$transport = new Kuka_Island_Test_Tracking_Transport();
	$client    = new Kuka_Island_Core_EDM_Client( new Kuka_Island_Core_Invoice_Config( $overrides ), $transport );
	$code      = 'NO_EXCEPTION';
	try {
		$client->login();
	} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
		$code = $e->get_safe_error_code();
	}
	$calls = array_sum( $transport->calls );
	$login_reject_results[ $case ] = $code . '/calls=' . $calls;
	if ( 'edm_not_configured' !== $code || 0 !== $calls ) {
		$login_reject_ok = false;
	}
}
$report(
	'INVOICE_LOGIN_REJECTS_WITHOUT_TRANSPORT_CALL',
	$login_reject_ok,
	implode( '|', array_map( static fn( $k, $v ) => $k . ':' . $v, array_keys( $login_reject_results ), $login_reject_results ) )
);

$guard_transport = new Kuka_Island_Test_Tracking_Transport();
$guard_provider  = new Kuka_Island_Core_EDM_Provider( $auto_send_ready, $guard_transport );
$guard_manager   = new Kuka_Island_Core_Invoice_Manager( $auto_send_ready, $guard_provider );
$guard_queue     = new Kuka_Island_Core_Invoice_Queue( $guard_manager );

// A fixture-marked order on the exact automatic-send entry point.
$fixture_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'total' => '110.00' ) ), true );
kuka_add_line( $fixture_order, 'Test Ürün', '100.00', '100.00', 1, '10.00' );
kuka_add_tax_rate( $fixture_order, 1, 10, '10.00' );
$fixture_order->save();

// Non-fixture control proving the same call really reaches past the guard.
$control_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'total' => '110.00' ) ) );
kuka_add_line( $control_order, 'Test Ürün', '100.00', '100.00', 1, '10.00' );
kuka_add_tax_rate( $control_order, 1, 10, '10.00' );
$control_order->save();

$queue_fatal   = '';
$fixture_state = '';
$control_state = '';

try {
	// This is the call that used to fatal: the queue asking the manager for a
	// fixture decision.
	$guard_queue->maybe_enqueue_order( $fixture_order->get_id(), $fixture_order );
	$fixture_order->read_meta_data( true );
	$fixture_state = Kuka_Island_Core_Invoice_Order_Store::get_status( $fixture_order );

	$guard_queue->maybe_enqueue_order( $control_order->get_id(), $control_order );
	$control_order->read_meta_data( true );
	$control_state = Kuka_Island_Core_Invoice_Order_Store::get_status( $control_order );
} catch ( Throwable $t ) {
	$queue_fatal = get_class( $t ) . ': ' . $t->getMessage();
}

$report(
	'INVOICE_QUEUE_FIXTURE_GUARD_RUNTIME_PATH',
	'' === $queue_fatal
	&& Kuka_Island_Core_Invoice_Status::STATUS_NONE === $fixture_state
	&& Kuka_Island_Core_Invoice_Status::STATUS_QUEUED === $control_state
	&& true === $auto_send_ready->is_auto_send_enabled()
	&& true === $guard_manager->is_order_settled( $fixture_order ),
	sprintf(
		'throwable:%s|fixture_status:%s|control_status:%s|auto_send:%s|settled:%s',
		'' === $queue_fatal ? 'none' : $queue_fatal,
		$fixture_state,
		$control_state,
		$auto_send_ready->is_auto_send_enabled() ? 'on' : 'off',
		$guard_manager->is_order_settled( $fixture_order ) ? 'yes' : 'no'
	)
);

// Clean up the scheduling row the control deliberately created.
$purged      = kuka_purge_queue_scheduling( array( $fixture_order->get_id(), $control_order->get_id() ) );
$residue     = kuka_count_queue_scheduling( array( $fixture_order->get_id(), $control_order->get_id() ) );
$report(
	'INVOICE_QUEUE_SCHEDULING_RESIDUE_ZERO',
	0 === $residue,
	sprintf( 'purged:%d|residual_rows:%d', $purged, $residue )
);

// The manager's own entry point must reject the same fixture order.
$manager_fixture_code = '';
try {
	$guard_manager->process_order( $fixture_order );
} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
	$manager_fixture_code = $e->get_safe_error_code();
}

$report(
	'INVOICE_MANAGER_FIXTURE_GUARD',
	Kuka_Island_Core_Invoice_Fixture_Guard::ERROR_CODE === $manager_fixture_code
	&& 0 === ( $guard_transport->calls['SendInvoice'] ?? 0 ),
	sprintf( 'code:%s|SendInvoice:%d', $manager_fixture_code ?: 'none', $guard_transport->calls['SendInvoice'] ?? 0 )
);

// Structural proof for audit item 10: the guard is genuinely unreachable for
// weakening. The class is final, the decision method is static, the manager's
// accessor is final, and no public toggle exists anywhere in the invoice module.
$guard_reflection   = new ReflectionClass( 'Kuka_Island_Core_Invoice_Fixture_Guard' );
$guard_method       = $guard_reflection->getMethod( 'is_test_fixture_order' );
$manager_reflection = new ReflectionClass( 'Kuka_Island_Core_Invoice_Manager' );
$manager_accessor   = $manager_reflection->getMethod( 'is_test_fixture_order' );

$invoice_module_dir   = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/invoice/';
$invoice_module_files = glob( $invoice_module_dir . '*.php' ) ?: array();

$toggle_hits = array();
foreach ( $invoice_module_files as $module_file ) {
	$contents = (string) file_get_contents( $module_file );
	foreach ( array( 'enable_test_mode', 'set_test_allowed_run_id', 'allow_test_fixture', 'disable_fixture_guard', 'bypass_fixture' ) as $needle ) {
		if ( str_contains( $contents, $needle ) ) {
			$toggle_hits[] = basename( $module_file ) . ':' . $needle;
		}
	}
}

$report(
	'INVOICE_FIXTURE_GUARD_NOT_OVERRIDABLE',
	count( $invoice_module_files ) >= 15
	&& $guard_reflection->isFinal()
	&& $guard_method->isStatic()
	&& $guard_method->isPublic()
	&& $manager_accessor->isFinal()
	&& empty( $toggle_hits ),
	sprintf(
		'module_files_scanned:%d|guard_final:%s|guard_static:%s|manager_accessor_final:%s|toggles:%s',
		count( $invoice_module_files ),
		$guard_reflection->isFinal() ? 'yes' : 'no',
		$guard_method->isStatic() ? 'yes' : 'no',
		$manager_accessor->isFinal() ? 'yes' : 'no',
		empty( $toggle_hits ) ? 'none' : implode( ',', $toggle_hits )
	)
);

kuka_test_delete_order( $fixture_order->get_id(), $test_run_id );
kuka_test_delete_order( $control_order->get_id(), $test_run_id );

/* ========================================================================== */
/* TEST 7 (audit item 7) - SOAP contract through the PRODUCTION client         */
/* ========================================================================== */

/**
 * Real-WSDL SoapClient that captures the serialised request instead of sending it.
 */
class Kuka_Island_Test_WSDL_Interceptor extends SoapClient {
	public string $last_request_xml   = '';
	public string $mock_response_body = '';

	public function __doRequest( $request, $location, $action, $version, $one_way = 0 ): ?string {
		$this->last_request_xml = (string) $request;

		return '' !== $this->mock_response_body
			? $this->mock_response_body
			: '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body/></s:Envelope>';
	}
}

/**
 * Production transport contract backed by the real-WSDL interceptor.
 *
 * The production Kuka_Island_Core_EDM_Client builds the request array; this
 * transport hands that exact array to a SoapClient constructed from the real EDM
 * WSDL, so the captured XML is the serialisation production would put on the
 * wire.
 */
final class Kuka_Island_Test_WSDL_Transport implements Kuka_Island_Core_SOAP_Transport_Interface {
	public array $operations = array();
	private Kuka_Island_Test_WSDL_Interceptor $client;

	public function __construct( Kuka_Island_Test_WSDL_Interceptor $client ) {
		$this->client = $client;
	}

	public function call( string $action, array $parameters ) {
		$this->operations[] = $action;

		return $this->client->__soapCall( $action, array( $parameters ) );
	}

	public function get_last_request(): string {
		return $this->client->last_request_xml;
	}

	public function get_last_response(): string {
		return (string) $this->client->__getLastResponse();
	}
}

/**
 * Assert XPath expectations against a captured SOAP request.
 *
 * @param string               $xml SOAP request XML.
 * @param array<string, mixed> $queries XPath => expected value, true (present) or false (absent).
 * @return array{passed: bool, count: int, failed: array<int, string>}
 */
function kuka_assert_soap_xpath( string $xml, array $queries ): array {
	$failed = array();

	if ( '' === trim( $xml ) ) {
		return array(
			'passed' => false,
			'count'  => count( $queries ),
			'failed' => array( 'empty request XML captured' ),
		);
	}

	$dom = new DOMDocument();
	if ( ! $dom->loadXML( $xml ) ) {
		return array(
			'passed' => false,
			'count'  => count( $queries ),
			'failed' => array( 'request XML did not parse' ),
		);
	}
	$xpath = new DOMXPath( $dom );

	foreach ( $queries as $query => $expected ) {
		$nodes = $xpath->query( $query );

		if ( false === $expected ) {
			if ( false !== $nodes && $nodes->length > 0 ) {
				$failed[] = $query . ' (expected absent, found "' . trim( (string) $nodes->item( 0 )->nodeValue ) . '")';
			}
			continue;
		}

		if ( false === $nodes || 0 === $nodes->length ) {
			$failed[] = $query . ' (expected present, was absent)';
			continue;
		}

		if ( true === $expected ) {
			continue;
		}

		$actual = trim( (string) $nodes->item( 0 )->nodeValue );
		if ( $actual !== (string) $expected ) {
			$failed[] = sprintf( '%s (expected "%s", got "%s")', $query, (string) $expected, $actual );
		}
	}

	return array(
		'passed' => empty( $failed ),
		'count'  => count( $queries ),
		'failed' => $failed,
	);
}

/**
 * Wrap a response body fragment in a SOAP envelope.
 */
function kuka_soap_envelope( string $inner ): string {
	return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $inner . '</s:Body></s:Envelope>';
}

$soap_session_id = 'sess-verify-0001';
$soap_interceptor = null;
$soap_wsdl_error  = '';

try {
	$soap_interceptor = new Kuka_Island_Test_WSDL_Interceptor(
		Kuka_Island_Core_Invoice_Config::DEFAULT_TEST_WSDL,
		array(
			'trace'      => 1,
			'exceptions' => 1,
			'cache_wsdl' => WSDL_CACHE_MEMORY,
		)
	);
} catch ( Throwable $t ) {
	$soap_wsdl_error = $t->getMessage();
}

$report(
	'INVOICE_WSDL_INTERCEPTOR_INIT',
	$soap_interceptor instanceof Kuka_Island_Test_WSDL_Interceptor,
	'' === $soap_wsdl_error ? 'wsdl_loaded:yes' : 'wsdl_error:' . $soap_wsdl_error
);

if ( $soap_interceptor instanceof Kuka_Island_Test_WSDL_Interceptor ) {
	$soap_transport = new Kuka_Island_Test_WSDL_Transport( $soap_interceptor );
	$soap_client    = new Kuka_Island_Core_EDM_Client( $config, $soap_transport );

	$app_name       = Kuka_Island_Core_Invoice_Config::DEFAULT_APPLICATION_NAME;
	$xp_app         = '//*[local-name()="REQUEST_HEADER"]/*[local-name()="APPLICATION_NAME"]';
	$xp_session     = '//*[local-name()="REQUEST_HEADER"]/*[local-name()="SESSION_ID"]';

	/* --- 7A: Login with SECRET_KEY, via production client ------------------ */
	$soap_interceptor->mock_response_body = kuka_soap_envelope(
		'<LoginResponse xmlns="http://tempuri.org/">'
		. '<REQUEST_RETURN><INTL_TXN_ID>1</INTL_TXN_ID><RETURN_CODE>0</RETURN_CODE></REQUEST_RETURN>'
		. '<SESSION_ID>' . $soap_session_id . '</SESSION_ID>'
		. '</LoginResponse>'
	);

	$login_session = '';
	$login_error   = '';
	try {
		$login_session = $soap_client->login();
	} catch ( Throwable $t ) {
		$login_error = get_class( $t ) . ': ' . $t->getMessage();
	}

	$login_xpath = kuka_assert_soap_xpath(
		$soap_interceptor->last_request_xml,
		array(
			'//*[local-name()="LoginRequest"]'      => true,
			$xp_app                                => $app_name,
			'//*[local-name()="USER_NAME"]'         => 'test_user',
			'//*[local-name()="PASSWORD"]'          => 'secret_password_123',
			'//*[local-name()="SECRET_KEY"]'        => 'secret_key_456',
		)
	);

	$report(
		'INVOICE_SOAP_XPATH_LOGIN_WITH_SECRET',
		$login_xpath['passed'] && $soap_session_id === $login_session,
		sprintf(
			'assertions:%d|session_parsed:%s|failed:%s',
			$login_xpath['count'],
			'' === $login_error ? ( $soap_session_id === $login_session ? 'yes' : 'no' ) : $login_error,
			empty( $login_xpath['failed'] ) ? 'none' : implode( ' ; ', $login_xpath['failed'] )
		)
	);

	/* --- 7B: Login without SECRET_KEY ------------------------------------- */
	$no_secret_config    = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'secret_key' => '' ) ) );
	$no_secret_client    = new Kuka_Island_Core_EDM_Client( $no_secret_config, $soap_transport );
	$no_secret_error     = '';
	try {
		$no_secret_client->login();
	} catch ( Throwable $t ) {
		$no_secret_error = $t->getMessage();
	}
	$login_no_secret_xpath = kuka_assert_soap_xpath(
		$soap_interceptor->last_request_xml,
		array(
			'//*[local-name()="LoginRequest"]' => true,
			$xp_app                            => $app_name,
			'//*[local-name()="USER_NAME"]'     => 'test_user',
			'//*[local-name()="SECRET_KEY"]'    => false,
		)
	);
	$report(
		'INVOICE_SOAP_XPATH_LOGIN_NO_SECRET',
		$login_no_secret_xpath['passed'],
		sprintf(
			'assertions:%d|failed:%s',
			$login_no_secret_xpath['count'],
			empty( $login_no_secret_xpath['failed'] ) ? 'none' : implode( ' ; ', $login_no_secret_xpath['failed'] )
		)
	);

	/* --- 7C: CheckCounter, verified through counter_left ------------------- */
	$soap_interceptor->mock_response_body = kuka_soap_envelope(
		'<CheckCounterResponse xmlns="http://tempuri.org/"><COUNTER_LEFT>1250</COUNTER_LEFT></CheckCounterResponse>'
	);
	$counter_result = array( 'counter_left' => -1 );
	$counter_error  = '';
	try {
		$counter_result = $soap_client->check_counter();
	} catch ( Throwable $t ) {
		$counter_error = $t->getMessage();
	}
	$counter_xpath = kuka_assert_soap_xpath(
		$soap_interceptor->last_request_xml,
		array(
			'//*[local-name()="CheckCounterRequest"]' => true,
			$xp_app                                   => $app_name,
			$xp_session                               => $soap_session_id,
		)
	);
	$report(
		'INVOICE_SOAP_XPATH_CHECK_COUNTER',
		$counter_xpath['passed'] && 1250 === ( $counter_result['counter_left'] ?? -1 ),
		sprintf(
			'assertions:%d|counter_left:%s|failed:%s',
			$counter_xpath['count'],
			'' === $counter_error ? (string) ( $counter_result['counter_left'] ?? 'null' ) : $counter_error,
			empty( $counter_xpath['failed'] ) ? 'none' : implode( ' ; ', $counter_xpath['failed'] )
		)
	);

	/* --- 7D: CheckUser ---------------------------------------------------- */
	$soap_interceptor->mock_response_body = kuka_soap_envelope(
		'<CheckUserResponse xmlns="http://tempuri.org/"><USER>'
		. '<IDENTIFIER>1234567890</IDENTIFIER><ALIAS>urn:mail:defaultgb@acme.com</ALIAS><TITLE>Acme A.Ş.</TITLE>'
		. '</USER></CheckUserResponse>'
	);
	$check_user_result = array();
	$check_user_error  = '';
	try {
		$check_user_result = $soap_client->check_user( '1234567890' );
	} catch ( Throwable $t ) {
		$check_user_error = $t->getMessage();
	}
	$check_user_xpath = kuka_assert_soap_xpath(
		$soap_interceptor->last_request_xml,
		array(
			'//*[local-name()="CheckUserRequest"]'                    => true,
			$xp_app                                                    => $app_name,
			$xp_session                                                => $soap_session_id,
			'//*[local-name()="USER"]/*[local-name()="IDENTIFIER"]'    => '1234567890',
			'//*[local-name()="USER"]/*[local-name()="DOCUMENTTYPE"]'  => 'INVOICE',
		)
	);
	$report(
		'INVOICE_SOAP_XPATH_CHECK_USER',
		$check_user_xpath['passed']
		&& true === ( $check_user_result['is_einvoice_user'] ?? false )
		&& 'urn:mail:defaultgb@acme.com' === ( $check_user_result['alias'] ?? '' ),
		sprintf(
			'assertions:%d|alias_parsed:%s|failed:%s',
			$check_user_xpath['count'],
			'' === $check_user_error ? ( $check_user_result['alias'] ?? 'none' ) : $check_user_error,
			empty( $check_user_xpath['failed'] ) ? 'none' : implode( ' ; ', $check_user_xpath['failed'] )
		)
	);

	/* --- 7E: GetInvoiceSerial (verified numbering contract) --------------- */
	$soap_interceptor->mock_response_body = kuka_soap_envelope(
		'<GetInvoiceSerialResponse xmlns="http://tempuri.org/"><Items><Items>'
		. '<INVOICESERIALCODE>KUK</INVOICESERIALCODE><YEAR>2026</YEAR>'
		. '<SOURCESYSTEMNAME>EDM</SOURCESYSTEMNAME><COMPANYNAME>Kuka</COMPANYNAME><COMPANYID>1</COMPANYID>'
		. '<LASTINVOICEDATEUSED>2026-08-01T00:00:00</LASTINVOICEDATEUSED><LASTSERIALUSED>42</LASTSERIALUSED>'
		. '</Items></Items></GetInvoiceSerialResponse>'
	);
	$serial_result = array();
	$serial_error  = '';
	try {
		$serial_result = $soap_client->get_invoice_serial( 'KUK', 2026, Kuka_Island_Core_Invoice_Numbering::SERIAL_TYPE_EARCHIVE );
	} catch ( Throwable $t ) {
		$serial_error = get_class( $t ) . ': ' . $t->getMessage();
	}
	$serial_xpath = kuka_assert_soap_xpath(
		$soap_interceptor->last_request_xml,
		array(
			'//*[local-name()="GetInvoiceSerialRequest"]'  => true,
			$xp_app                                        => $app_name,
			$xp_session                                    => $soap_session_id,
			'//*[local-name()="INVOICESERIALCODE"]'         => 'KUK',
			'//*[local-name()="INVOICESENDTYPE"]'           => 'EARSIV',
			'//*[local-name()="YEAR"]'                      => '2026',
		)
	);
	$first_serial = $serial_result['serials'][0] ?? array();
	$report(
		'INVOICE_SOAP_XPATH_GET_INVOICE_SERIAL',
		$serial_xpath['passed']
		&& 'KUK' === ( $first_serial['code'] ?? '' )
		&& 42 === ( $first_serial['last_serial_used'] ?? -1 ),
		sprintf(
			'assertions:%d|serial_code:%s|last_serial_used:%s|failed:%s',
			$serial_xpath['count'],
			'' === $serial_error ? ( $first_serial['code'] ?? 'none' ) : $serial_error,
			(string) ( $first_serial['last_serial_used'] ?? 'null' ),
			empty( $serial_xpath['failed'] ) ? 'none' : implode( ' ; ', $serial_xpath['failed'] )
		)
	);

	/* --- 7F: SendInvoice, e-Arşiv (no receiver alias) --------------------- */
	$raw_ubl = '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><cbc:ID xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">KUK2026000000042</cbc:ID></Invoice>';
	$soap_interceptor->mock_response_body = kuka_soap_envelope(
		'<SendInvoiceResponse xmlns="http://tempuri.org/">'
		. '<REQUEST_RETURN><INTL_TXN_ID>2</INTL_TXN_ID><RETURN_CODE>0</RETURN_CODE></REQUEST_RETURN>'
		. '<INVOICE TRXID="4242" UUID="uuid-verify-0001" ID="KUK2026000000042"/>'
		. '</SendInvoiceResponse>'
	);

	$earchive_payload = array(
		'trx_id'            => 4242,
		'uuid'              => 'uuid-verify-0001',
		'invoice_number'    => 'KUK2026000000042',
		'invoice_serial'    => 'KUK',
		'profile_id'        => 'EARSIVFATURA',
		'invoice_type_code' => 'SATIS',
		'issue_date'        => '2026-08-31',
		'payable_amount'    => '990.00',
		'receiver_vkn'      => '11111111111',
		'receiver_alias'    => '',
		'ubl_xml'           => $raw_ubl,
	);

	$send_error = '';
	try {
		$soap_client->send_invoice( $earchive_payload );
	} catch ( Throwable $t ) {
		$send_error = get_class( $t ) . ': ' . $t->getMessage();
	}

	$send_request_xml = $soap_interceptor->last_request_xml;
	$send_dom         = new DOMDocument();
	$send_dom->loadXML( $send_request_xml );
	$send_xp          = new DOMXPath( $send_dom );
	$content_node     = $send_xp->query( '//*[local-name()="CONTENT"]' )->item( 0 );
	$content_base64   = null !== $content_node ? trim( (string) $content_node->nodeValue ) : '';
	$decoded_ubl      = (string) base64_decode( $content_base64, true );
	$single_base64_ok = ( '' !== $content_base64 )
		&& hash_equals( hash( 'sha256', $raw_ubl ), hash( 'sha256', $decoded_ubl ) )
		&& base64_encode( $decoded_ubl ) === $content_base64;

	$send_xpath = kuka_assert_soap_xpath(
		$send_request_xml,
		array(
			'//*[local-name()="SendInvoiceRequest"]'                                 => true,
			$xp_app                                                                   => $app_name,
			$xp_session                                                                => $soap_session_id,
			'//*[local-name()="SENDER"]/@vkn'                                          => '1234567890',
			'//*[local-name()="SENDER"]/@alias'                                        => 'urn:mail:defaultgb@kukaisland.com',
			'//*[local-name()="RECEIVER"]/@vkn'                                        => '11111111111',
			'//*[local-name()="RECEIVER"]/@alias'                                      => false,
			'//*[local-name()="INVOICE"]/@TRXID'                                       => '4242',
			'//*[local-name()="INVOICE"]/@UUID'                                        => 'uuid-verify-0001',
			'//*[local-name()="INVOICE"]/@ID'                                          => 'KUK2026000000042',
			'//*[local-name()="HEADER"]/*[local-name()="INVOICESERIAL_REQUESTED"]'      => 'KUK',
			'//*[local-name()="HEADER"]/*[local-name()="EARCHIVE"]'                    => 'true',
			'//*[local-name()="HEADER"]/*[local-name()="TO"]'                          => false,
			'//*[local-name()="INVOICE"]/*[local-name()="CONTENT"]'                    => true,
		)
	);

	$report(
		'INVOICE_SOAP_XPATH_SEND_INVOICE_EARCHIVE',
		$send_xpath['passed'] && $single_base64_ok && '' === $send_error,
		sprintf(
			'assertions:%d|single_base64_sha256_match:%s|error:%s|failed:%s',
			$send_xpath['count'] + 1,
			$single_base64_ok ? 'yes' : 'no',
			'' === $send_error ? 'none' : $send_error,
			empty( $send_xpath['failed'] ) ? 'none' : implode( ' ; ', $send_xpath['failed'] )
		)
	);

	/* --- 7G: SendInvoice, e-Fatura (receiver alias present) -------------- */
	$einvoice_payload                   = $earchive_payload;
	$einvoice_payload['profile_id']     = 'TICARIFATURA';
	$einvoice_payload['receiver_alias'] = 'urn:mail:defaultgb@acme.com';
	$einvoice_payload['receiver_vkn']   = '1234567890';

	$send2_error = '';
	try {
		$soap_client->send_invoice( $einvoice_payload );
	} catch ( Throwable $t ) {
		$send2_error = $t->getMessage();
	}

	$send2_xpath = kuka_assert_soap_xpath(
		$soap_interceptor->last_request_xml,
		array(
			'//*[local-name()="SendInvoiceRequest"]'                              => true,
			'//*[local-name()="RECEIVER"]/@alias'                                 => 'urn:mail:defaultgb@acme.com',
			'//*[local-name()="HEADER"]/*[local-name()="TO"]'                     => 'urn:mail:defaultgb@acme.com',
			'//*[local-name()="HEADER"]/*[local-name()="PROFILEID"]'              => 'TICARIFATURA',
			'//*[local-name()="HEADER"]/*[local-name()="EARCHIVE"]'               => 'false',
			'//*[local-name()="HEADER"]/*[local-name()="INVOICESERIAL_REQUESTED"]' => 'KUK',
		)
	);
	$report(
		'INVOICE_SOAP_XPATH_SEND_INVOICE_EINVOICE',
		$send2_xpath['passed'],
		sprintf(
			'assertions:%d|failed:%s',
			$send2_xpath['count'],
			empty( $send2_xpath['failed'] ) ? 'none' : implode( ' ; ', $send2_xpath['failed'] )
		)
	);

	/* --- 7H: GetInvoiceStatus -------------------------------------------- */
	$soap_interceptor->mock_response_body = kuka_soap_envelope(
		'<GetInvoiceStatusResponse xmlns="http://tempuri.org/">'
		. '<INVOICE_STATUS TRXID="4242" UUID="uuid-verify-0001" ID="KUK2026000000042">'
		. '<STATUS>1300</STATUS><STATUS_DESCRIPTION>Basariyla Tamamlandi</STATUS_DESCRIPTION>'
		. '</INVOICE_STATUS></GetInvoiceStatusResponse>'
	);
	$status_result = null;
	$status_error  = '';
	try {
		$status_result = $soap_client->get_invoice_status( 'uuid-verify-0001', 'KUK2026000000042' );
	} catch ( Throwable $t ) {
		$status_error = $t->getMessage();
	}
	$status_xpath = kuka_assert_soap_xpath(
		$soap_interceptor->last_request_xml,
		array(
			'//*[local-name()="GetInvoiceStatusRequest"]' => true,
			$xp_app                                       => $app_name,
			$xp_session                                   => $soap_session_id,
			'//*[local-name()="INVOICE"]/@UUID'            => 'uuid-verify-0001',
			'//*[local-name()="INVOICE"]/@ID'              => 'KUK2026000000042',
			'//*[local-name()="START_DATE"]'               => true,
			'//*[local-name()="END_DATE"]'                 => true,
		)
	);
	$report(
		'INVOICE_SOAP_XPATH_GET_INVOICE_STATUS',
		$status_xpath['passed']
		&& $status_result instanceof Kuka_Island_Core_Invoice_Result
		&& Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED === $status_result->get_status(),
		sprintf(
			'assertions:%d|parsed_status:%s|failed:%s',
			$status_xpath['count'],
			$status_result instanceof Kuka_Island_Core_Invoice_Result ? $status_result->get_status() : ( $status_error ?: 'null' ),
			empty( $status_xpath['failed'] ) ? 'none' : implode( ' ; ', $status_xpath['failed'] )
		)
	);

	/* --- 7I: GetInvoice --------------------------------------------------- */
	$soap_interceptor->mock_response_body = kuka_soap_envelope(
		'<GetInvoiceResponse xmlns="http://tempuri.org/"><INVOICE TRXID="4242" UUID="uuid-verify-0001">'
		. '<CONTENT>' . base64_encode( 'PDF_MOCK_DATA' ) . '</CONTENT></INVOICE></GetInvoiceResponse>'
	);
	$get_invoice_error = '';
	try {
		$soap_client->get_invoice_document( 'uuid-verify-0001', 'PDF' );
	} catch ( Throwable $t ) {
		$get_invoice_error = $t->getMessage();
	}
	$get_inv_xpath = kuka_assert_soap_xpath(
		$soap_interceptor->last_request_xml,
		array(
			'//*[local-name()="GetInvoiceRequest"]'                            => true,
			$xp_app                                                            => $app_name,
			$xp_session                                                        => $soap_session_id,
			'//*[local-name()="INVOICE_SEARCH_KEY"]/*[local-name()="UUID"]'    => 'uuid-verify-0001',
			'//*[local-name()="INVOICE_CONTENT_TYPE"]'                         => 'PDF',
			'//*[local-name()="HEADER_ONLY"]'                                  => 'N',
		)
	);
	$report(
		'INVOICE_SOAP_XPATH_GET_INVOICE',
		$get_inv_xpath['passed'],
		sprintf(
			'assertions:%d|error:%s|failed:%s',
			$get_inv_xpath['count'],
			'' === $get_invoice_error ? 'none' : $get_invoice_error,
			empty( $get_inv_xpath['failed'] ) ? 'none' : implode( ' ; ', $get_inv_xpath['failed'] )
		)
	);

	/* --- 7J: EmailInvoice ------------------------------------------------- */
	$soap_interceptor->mock_response_body = kuka_soap_envelope(
		'<EmailInvoiceResponse xmlns="http://tempuri.org/">'
		. '<REQUEST_RETURN><INTL_TXN_ID>3</INTL_TXN_ID><RETURN_CODE>0</RETURN_CODE></REQUEST_RETURN>'
		. '</EmailInvoiceResponse>'
	);
	$email_error = '';
	try {
		$soap_client->email_invoice( 'uuid-verify-0001', 'can@example.com', 'PDF' );
	} catch ( Throwable $t ) {
		$email_error = $t->getMessage();
	}
	$email_xpath = kuka_assert_soap_xpath(
		$soap_interceptor->last_request_xml,
		array(
			'//*[local-name()="EmailInvoiceRequest"]'   => true,
			$xp_app                                     => $app_name,
			$xp_session                                 => $soap_session_id,
			'//*[local-name()="INVOICE"]/@UUID'          => 'uuid-verify-0001',
			'//*[local-name()="EMAILS"]'                 => 'can@example.com',
			'//*[local-name()="INVOICE_CONTENT_TYPE"]'   => 'PDF',
		)
	);
	$report(
		'INVOICE_SOAP_XPATH_EMAIL_INVOICE',
		$email_xpath['passed'],
		sprintf(
			'assertions:%d|error:%s|failed:%s',
			$email_xpath['count'],
			'' === $email_error ? 'none' : $email_error,
			empty( $email_xpath['failed'] ) ? 'none' : implode( ' ; ', $email_xpath['failed'] )
		)
	);

	/* --- 7K: Logout ------------------------------------------------------- */
	$soap_interceptor->mock_response_body = kuka_soap_envelope(
		'<LogoutResponse xmlns="http://tempuri.org/">'
		. '<REQUEST_RETURN><INTL_TXN_ID>4</INTL_TXN_ID><RETURN_CODE>0</RETURN_CODE></REQUEST_RETURN>'
		. '</LogoutResponse>'
	);
	$soap_client->logout();
	$logout_xpath = kuka_assert_soap_xpath(
		$soap_interceptor->last_request_xml,
		array(
			'//*[local-name()="LogoutRequest"]' => true,
			$xp_app                              => $app_name,
			$xp_session                          => $soap_session_id,
		)
	);
	$report(
		'INVOICE_SOAP_XPATH_LOGOUT',
		$logout_xpath['passed'] && null === $soap_client->get_session_id(),
		sprintf(
			'assertions:%d|session_cleared:%s|failed:%s',
			$logout_xpath['count'],
			null === $soap_client->get_session_id() ? 'yes' : 'no',
			empty( $logout_xpath['failed'] ) ? 'none' : implode( ' ; ', $logout_xpath['failed'] )
		)
	);

	/* --- 7L: every operation went through the production client ---------- */
	$expected_ops = array( 'Login', 'Login', 'CheckCounter', 'CheckUser', 'GetInvoiceSerial', 'SendInvoice', 'SendInvoice', 'GetInvoiceStatus', 'GetInvoice', 'EmailInvoice', 'Logout' );
	$report(
		'INVOICE_SOAP_OPS_VIA_PRODUCTION_CLIENT',
		$expected_ops === $soap_transport->operations,
		sprintf( 'observed:%s', implode( ',', $soap_transport->operations ) )
	);
} else {
	foreach ( array( 'LOGIN_WITH_SECRET', 'LOGIN_NO_SECRET', 'CHECK_COUNTER', 'CHECK_USER', 'GET_INVOICE_SERIAL', 'SEND_INVOICE_EARCHIVE', 'SEND_INVOICE_EINVOICE', 'GET_INVOICE_STATUS', 'GET_INVOICE', 'EMAIL_INVOICE', 'LOGOUT' ) as $op ) {
		$note( sprintf( 'INVOICE_SOAP_XPATH_%s=BLOCKED_WSDL_UNAVAILABLE', $op ) );
	}
	$note( 'INVOICE_SOAP_OPS_VIA_PRODUCTION_CLIENT=BLOCKED_WSDL_UNAVAILABLE' );
	$failures[] = 'INVOICE_SOAP_CONTRACT_BLOCKED';
}

/* ========================================================================== */
/* TEST 8 (audit item 4) - No locally generated fiscal document numbers        */
/* ========================================================================== */

$mapper_has_generator = method_exists( 'Kuka_Island_Core_Invoice_Order_Mapper', 'generate_invoice_number' );

$module_files = $invoice_module_files;

/**
 * Count literal occurrences of a needle across the production invoice module.
 *
 * @param array<int, string> $files Module files.
 * @param string             $needle Literal needle.
 * @return array<string, int> Basename => occurrences (only non-zero entries).
 */
function kuka_scan_module( array $files, string $needle ): array {
	$hits = array();
	foreach ( $files as $file ) {
		$count = substr_count( (string) file_get_contents( $file ), $needle );
		if ( $count > 0 ) {
			$hits[ basename( $file ) ] = $count;
		}
	}

	return $hits;
}

$numbering_scan = array();
foreach ( array( 'generate_invoice_number', 'str_pad( (string) $order_id', 'STR_PAD_LEFT' ) as $needle ) {
	foreach ( kuka_scan_module( $module_files, $needle ) as $file => $count ) {
		$numbering_scan[] = sprintf( '%s:%s(%d)', $file, $needle, $count );
	}
}

$report(
	'INVOICE_NUMBER_LOCAL_GENERATION_REMOVED',
	count( $module_files ) >= 15 && ! $mapper_has_generator && empty( $numbering_scan ),
	sprintf(
		'module_files_scanned:%d|mapper_generator_exists:%s|source_hits:%s',
		count( $module_files ),
		$mapper_has_generator ? 'yes' : 'no',
		empty( $numbering_scan ) ? 'none' : implode( ',', $numbering_scan )
	)
);

// The production pipeline must fail closed when EDM has assigned no number.
$numbering_transport = new Kuka_Island_Test_Tracking_Transport();
$numbering_provider  = new Kuka_Island_Core_EDM_Provider( $config, $numbering_transport );
$numbering_manager   = new Kuka_Island_Core_Invoice_Manager( $config, $numbering_provider );

$numbering_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'total' => '100.00' ) ) );
kuka_add_line( $numbering_order, 'Numarasız Ürün', '100.00', '100.00', 0, '0.00' );
$numbering_order->update_meta_data( '_billing_tax_number', '12345678901' );
$numbering_order->save();

$numbering_code = '';
try {
	$numbering_manager->process_order( $numbering_order );
} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
	$numbering_code = $e->get_safe_error_code();
}
$numbering_order->read_meta_data( true );
$numbering_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $numbering_order );

$report(
	'INVOICE_NUMBERING_FAIL_CLOSED_BLOCKED',
	Kuka_Island_Core_Invoice_Numbering::ERROR_UNCONFIRMED === $numbering_code
	&& Kuka_Island_Core_Invoice_Status::STATUS_BLOCKED === $numbering_status
	&& 0 === ( $numbering_transport->calls['SendInvoice'] ?? 0 ),
	sprintf(
		'code:%s|status:%s|SendInvoice:%d',
		$numbering_code ?: 'none',
		$numbering_status,
		$numbering_transport->calls['SendInvoice'] ?? 0
	)
);

// The queue's permanent-error handler must preserve the blocked status.
$numbering_queue = new Kuka_Island_Core_Invoice_Queue( $numbering_manager );
$numbering_queue->process_queued_order( $numbering_order->get_id() );
$numbering_order->read_meta_data( true );
$queued_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $numbering_order );
$report(
	'INVOICE_NUMBERING_BLOCKED_STATUS_PRESERVED',
	Kuka_Island_Core_Invoice_Status::STATUS_BLOCKED === $queued_status,
	sprintf( 'status_after_queue_worker:%s', $queued_status )
);

// The mapper itself refuses an empty number even if called directly.
$mapper_empty_code = '';
try {
	( new Kuka_Island_Core_Invoice_Order_Mapper( $config ) )->map_order_to_invoice_data(
		$numbering_order,
		Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
		'EARSIVFATURA',
		'',
		''
	);
} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
	$mapper_empty_code = $e->get_safe_error_code();
}
$report(
	'INVOICE_MAPPER_REJECTS_EMPTY_NUMBER',
	Kuka_Island_Core_Invoice_Numbering::ERROR_UNCONFIRMED === $mapper_empty_code,
	sprintf( 'code:%s', $mapper_empty_code ?: 'none' )
);

kuka_test_delete_order( $numbering_order->get_id(), $test_run_id );

// A number left behind by the removed local generator carries no EDM
// provenance and must never be treated as a fiscal identifier. Orders 967, 973,
// 981 and 989 in this database all carry the same legacy value
// (KUK2026000000777), which is exactly why a bare number is not trusted.
$legacy_transport = new Kuka_Island_Test_Tracking_Transport();
$legacy_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $legacy_transport ) );
$legacy_order     = kuka_create_lock_order( $test_run_id, $billing_props, array( '_kuka_invoice_number' => 'KUK2026000000777' ) );

$legacy_code = '';
try {
	$legacy_manager->process_order( $legacy_order );
} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
	$legacy_code = $e->get_safe_error_code();
}
$legacy_order->read_meta_data( true );
$report(
	'INVOICE_NUMBERING_REJECTS_LEGACY_NUMBER',
	Kuka_Island_Core_Invoice_Numbering::ERROR_UNCONFIRMED === $legacy_code
	&& Kuka_Island_Core_Invoice_Status::STATUS_BLOCKED === Kuka_Island_Core_Invoice_Order_Store::get_status( $legacy_order )
	&& 0 === ( $legacy_transport->calls['SendInvoice'] ?? 0 ),
	sprintf(
		'code:%s|status:%s|SendInvoice:%d|seeded_number_without_provenance:yes',
		$legacy_code ?: 'none',
		Kuka_Island_Core_Invoice_Order_Store::get_status( $legacy_order ),
		$legacy_transport->calls['SendInvoice'] ?? 0
	)
);
kuka_test_delete_order( $legacy_order->get_id(), $test_run_id );

// Happy path: with an EDM-assigned number the production pipeline sends exactly
// once and records the provenance of the fiscal number.
$happy_transport = new Kuka_Island_Test_Tracking_Transport();
$happy_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $happy_transport ) );
$happy_order     = kuka_create_lock_order(
	$test_run_id,
	$billing_props,
	array(
		'_kuka_invoice_number'        => 'KUK2026000000042',
		'_kuka_invoice_number_source' => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
	)
);

$happy_result = null;
$happy_error  = '';
try {
	$happy_result = $happy_manager->process_order( $happy_order );
} catch ( Throwable $t ) {
	$happy_error = get_class( $t ) . ': ' . $t->getMessage();
}
$happy_order->read_meta_data( true );
$happy_data = Kuka_Island_Core_Invoice_Order_Store::get_invoice_data( $happy_order );

$report(
	'INVOICE_SEND_RECORDS_EDM_PROVENANCE',
	$happy_result instanceof Kuka_Island_Core_Invoice_Result
	&& $happy_result->is_success()
	&& 1 === ( $happy_transport->calls['SendInvoice'] ?? 0 )
	&& Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM === $happy_data['number_source']
	&& 'KUK2026000000042' === $happy_data['invoice_number'],
	sprintf(
		'SendInvoice:%d|status:%s|number:%s|number_source:%s|error:%s',
		$happy_transport->calls['SendInvoice'] ?? 0,
		$happy_data['status'],
		$happy_data['invoice_number'],
		$happy_data['number_source'] ?: 'none',
		'' === $happy_error ? 'none' : $happy_error
	)
);
kuka_test_delete_order( $happy_order->get_id(), $test_run_id );

/* ========================================================================== */
/* TEST 9 (audit item 5) - Fiscal fallbacks removed from the production path   */
/* ========================================================================== */

$fallback_scan = array();
foreach ( array( "'defaultpk'", "?? 'İstanbul'", "?? 'Kuka Island'", "?? '1111111111'", "?? '11111111111'", "?? 10 )" ) as $needle ) {
	foreach ( kuka_scan_module( $module_files, $needle ) as $file => $count ) {
		$fallback_scan[] = sprintf( '%s:%s(%d)', $file, $needle, $count );
	}
}

// The generic retail VKN literal may appear exactly once, inside the
// explicitly-enabled policy branch of the mapper.
$generic_vkn_hits  = kuka_scan_module( $module_files, "'11111111111'" );
$generic_vkn_legal = array( 'class-invoice-order-mapper.php' => 1 ) === $generic_vkn_hits;

$report(
	'INVOICE_FISCAL_FALLBACKS_REMOVED',
	count( $module_files ) >= 15 && empty( $fallback_scan ) && $generic_vkn_legal,
	sprintf(
		'module_files_scanned:' . count( $module_files ) . '|fallback_hits:%s|generic_vkn_occurrences:%s',
		empty( $fallback_scan ) ? 'none' : implode( ',', $fallback_scan ),
		empty( $generic_vkn_hits ) ? 'none' : implode( ',', array_map( static fn( $f, $c ) => $f . '(' . $c . ')', array_keys( $generic_vkn_hits ), $generic_vkn_hits ) )
	)
);

// e-Arşiv routing must not invent a recipient mailbox alias.
$routing_transport = new Kuka_Island_Test_Tracking_Transport();
$routing_provider  = new Kuka_Island_Core_EDM_Provider( $config, $routing_transport );
$routing_manager   = new Kuka_Island_Core_Invoice_Manager( $config, $routing_provider );

$individual_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'total' => '100.00' ) ) );
$individual_order->update_meta_data( '_billing_tax_number', '12345678901' );
$individual_order->save();
$individual_routing = $routing_manager->resolve_routing( $individual_order );

$report(
	'INVOICE_EARCHIVE_ALIAS_NOT_INVENTED',
	'' === $individual_routing['receiver_alias']
	&& Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE === $individual_routing['document_type']
	&& 'EARSIVFATURA' === $individual_routing['profile_id'],
	sprintf(
		'receiver_alias:%s|document_type:%s|profile:%s',
		'' === $individual_routing['receiver_alias'] ? '<empty>' : $individual_routing['receiver_alias'],
		$individual_routing['document_type'],
		$individual_routing['profile_id']
	)
);

kuka_test_delete_order( $individual_order->get_id(), $test_run_id );

// The UBL builder must fail closed instead of substituting a placeholder.
$builder_base = array(
	'uuid'              => 'uuid-fallback-test',
	'invoice_number'    => 'KUK2026000000042',
	'series'            => 'KUK',
	'profile_id'        => 'EARSIVFATURA',
	'document_type'     => Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
	'invoice_type_code' => 'SATIS',
	'issue_date'        => '2026-08-31',
	'issue_time'        => '10:00:00',
	'currency'          => 'TRY',
	'order_number'      => '1',
	'order_date'        => '2026-08-31',
	'receiver_alias'    => '',
	'notes'             => array( 'Sipariş No: #1' ),
	'supplier'          => array(
		'vkn'        => '1234567890',
		'name'       => 'Kuka Island Tasarım A.Ş.',
		'tax_office' => 'Kadıköy',
		'address'    => 'Moda Cad. No:1',
		'district'   => 'Kadıköy',
		'city'       => 'İstanbul',
		'postcode'   => '34710',
		'country'    => 'Türkiye',
		'email'      => '',
		'phone'      => '',
	),
	'customer'          => array(
		'first_name' => 'Can',
		'last_name'  => 'Yılmaz',
		'company'    => '',
		'tax_number' => '12345678901',
		'tax_office' => '',
		'address'    => 'Moda Cad. No:2',
		'district'   => 'Kadıköy',
		'city'       => 'İstanbul',
		'postcode'   => '34710',
		'country'    => 'Türkiye',
		'email'      => 'can@example.com',
		'phone'      => '',
	),
	'payment'           => array(
		'code'     => '48',
		'due_date' => '2026-08-31',
		'channel'  => 'IYZICO',
		'terms'    => 'Peşin',
	),
	'totals'            => array(
		'line_extension_amount'   => '100.00',
		'tax_exclusive_amount'    => '100.00',
		'tax_inclusive_amount'    => '110.00',
		'allowance_total_amount'  => '0.00',
		'charge_total_amount'     => '0.00',
		'payable_rounding_amount' => '0.00',
		'payable_amount'          => '110.00',
	),
	'tax_summary'       => array(
		'total_tax' => '10.00',
		'rates'     => array(
			array(
				'percent'        => 10,
				'taxable_amount' => '100.00',
				'tax_amount'     => '10.00',
			),
		),
	),
	'lines'             => array(
		array(
			'name'                  => 'Test Ürün',
			'sku'                   => '',
			'quantity'              => 1,
			'unit_price'            => '100.00',
			'gross_amount'          => '100.00',
			'allowance_amount'      => '0.00',
			'line_extension_amount' => '100.00',
			'taxable_amount'        => '100.00',
			'tax_percent'           => 10,
			'tax_amount'            => '10.00',
		),
	),
);

$builder_cases = array(
	'supplier_city'      => static function ( array $d ): array {
		$d['supplier']['city'] = '';
		return $d;
	},
	'supplier_name'      => static function ( array $d ): array {
		$d['supplier']['name'] = '';
		return $d;
	},
	'supplier_vkn'       => static function ( array $d ): array {
		$d['supplier']['vkn'] = '';
		return $d;
	},
	'customer_tax_number' => static function ( array $d ): array {
		$d['customer']['tax_number'] = '';
		return $d;
	},
	'customer_city'      => static function ( array $d ): array {
		$d['customer']['city'] = '';
		return $d;
	},
	'line_tax_percent'   => static function ( array $d ): array {
		unset( $d['lines'][0]['tax_percent'] );
		return $d;
	},
	'rate_percent'       => static function ( array $d ): array {
		unset( $d['tax_summary']['rates'][0]['percent'] );
		return $d;
	},
	'currency'           => static function ( array $d ): array {
		$d['currency'] = '';
		return $d;
	},
);

$builder_results   = array();
$builder_all_closed = true;
foreach ( $builder_cases as $case_name => $mutator ) {
	$code = 'NO_EXCEPTION';
	try {
		( new Kuka_Island_Core_UBL_TR_Builder( $mutator( $builder_base ) ) )->build_xml();
	} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
		$code = $e->get_safe_error_code();
	}
	$builder_results[ $case_name ] = $code;
	if ( 'ubl_missing_field' !== $code ) {
		$builder_all_closed = false;
	}
}

// Control: the complete payload must still build.
$builder_control_ok = false;
try {
	$control_xml        = ( new Kuka_Island_Core_UBL_TR_Builder( $builder_base ) )->build_xml();
	$builder_control_ok = str_contains( $control_xml, 'KUK2026000000042' );
} catch ( Throwable $t ) {
	$builder_control_ok = false;
}

$report(
	'INVOICE_UBL_BUILDER_FAIL_CLOSED',
	$builder_all_closed && $builder_control_ok,
	sprintf(
		'cases:%d|control_builds:%s|codes:%s',
		count( $builder_results ),
		$builder_control_ok ? 'yes' : 'no',
		implode( ',', array_map( static fn( $k, $v ) => $k . '=' . $v, array_keys( $builder_results ), $builder_results ) )
	)
);

/* ========================================================================== */
/* TEST 10 (audit item 6) - Coupon / VAT arithmetic on real WC_Order data      */
/* ========================================================================== */

/**
 * Build an order from a scenario definition, map it, render UBL and assert
 * every monetary invariant with DOMXPath.
 *
 * @param string               $run_id Isolation run ID.
 * @param array<string, mixed> $scenario Scenario definition.
 * @param Kuka_Island_Core_Invoice_Config $config Config.
 * @param array<string, mixed> $billing_props Shared billing props.
 * @return array<string, mixed> Assertion outcome.
 */
function kuka_run_monetary_scenario( string $run_id, array $scenario, Kuka_Island_Core_Invoice_Config $config, array $billing_props ): array {
	$order = kuka_create_test_order( $run_id, array_merge( $billing_props, array( 'total' => $scenario['order_total'] ) ) );
	$order->update_meta_data( '_billing_tax_number', '12345678901' );

	$rates = array();
	foreach ( $scenario['lines'] as $index => $line ) {
		$rate_id        = 100 + (int) $line['percent'];
		$rates[ $rate_id ] = (int) $line['percent'];
		kuka_add_line( $order, sprintf( 'Ürün %d (%%%d)', $index + 1, (int) $line['percent'] ), $line['gross'], $line['net'], $rate_id, $line['tax'] );
	}

	if ( ! empty( $scenario['shipping'] ) ) {
		$rate_id           = 100 + (int) $scenario['shipping']['percent'];
		$rates[ $rate_id ] = (int) $scenario['shipping']['percent'];
		$shipping_item     = new WC_Order_Item_Shipping();
		$shipping_item->set_method_title( 'Kargo' );
		$shipping_item->set_total( $scenario['shipping']['net'] );
		$shipping_item->set_taxes( array( 'total' => array( $rate_id => $scenario['shipping']['tax'] ) ) );
		$order->add_item( $shipping_item );
		$order->set_shipping_total( $scenario['shipping']['net'] );
		$order->set_shipping_tax( $scenario['shipping']['tax'] );
	}

	foreach ( $rates as $rate_id => $percent ) {
		kuka_add_tax_rate( $order, (int) $rate_id, (int) $percent, '0.00' );
	}

	$order->set_discount_total( $scenario['discount_total'] );
	$order->save();

	$outcome = array(
		'order_id'        => $order->get_id(),
		'error_code'      => '',
		'invariants'      => array(),
		'subtotal_checks' => 0,
		'passed'          => false,
	);

	try {
		$data = ( new Kuka_Island_Core_Invoice_Order_Mapper( $config ) )->map_order_to_invoice_data(
			$order,
			Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
			'EARSIVFATURA',
			'',
			'KUK2026000000042'
		);
		$xml  = ( new Kuka_Island_Core_UBL_TR_Builder( $data ) )->build_xml();
	} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
		$outcome['error_code'] = $e->get_safe_error_code();
		$outcome['passed']     = ( ( $scenario['expect_error'] ?? '' ) === $e->get_safe_error_code() );
		kuka_test_delete_order( $order->get_id(), $run_id );
		return $outcome;
	}

	if ( ! empty( $scenario['expect_error'] ) ) {
		$outcome['error_code'] = 'NO_EXCEPTION';
		kuka_test_delete_order( $order->get_id(), $run_id );
		return $outcome;
	}

	$dom = new DOMDocument();
	$dom->loadXML( $xml );
	$xp = new DOMXPath( $dom );

	$cents = static fn( ?string $value ): int => Kuka_Island_Core_Invoice_Order_Mapper::amount_to_cents( (string) $value );
	$one   = static function ( DOMXPath $xp, string $query ) {
		$nodes = $xp->query( $query );
		return ( false !== $nodes && $nodes->length > 0 ) ? trim( (string) $nodes->item( 0 )->nodeValue ) : null;
	};

	$doc = '/*[local-name()="Invoice"]/*[local-name()="LegalMonetaryTotal"]/';
	$line_ext  = $cents( $one( $xp, $doc . '*[local-name()="LineExtensionAmount"]' ) );
	$tax_excl  = $cents( $one( $xp, $doc . '*[local-name()="TaxExclusiveAmount"]' ) );
	$tax_incl  = $cents( $one( $xp, $doc . '*[local-name()="TaxInclusiveAmount"]' ) );
	$charge    = $cents( $one( $xp, $doc . '*[local-name()="ChargeTotalAmount"]' ) ?? '0.00' );
	$rounding  = $cents( $one( $xp, $doc . '*[local-name()="PayableRoundingAmount"]' ) ?? '0.00' );
	$payable_s = (string) $one( $xp, $doc . '*[local-name()="PayableAmount"]' );
	$payable   = $cents( $payable_s );

	// A document-level allowance must NOT be emitted: the coupon lives on the lines.
	$doc_allowance_nodes = $xp->query( $doc . '*[local-name()="AllowanceTotalAmount"]' );
	$doc_allowance_absent = ( false === $doc_allowance_nodes || 0 === $doc_allowance_nodes->length );

	$doc_tax_total = $cents( $one( $xp, '/*[local-name()="Invoice"]/*[local-name()="TaxTotal"]/*[local-name()="TaxAmount"]' ) );

	// Invariant 1: TaxableAmount x Percent / 100 = TaxAmount for EVERY TaxSubtotal.
	$subtotal_ok    = true;
	$subtotal_count = 0;
	$subtotal_fail  = array();
	foreach ( $xp->query( '//*[local-name()="TaxSubtotal"]' ) as $node ) {
		++$subtotal_count;
		$taxable = $cents( $xp->evaluate( 'string(*[local-name()="TaxableAmount"])', $node ) );
		$tax     = $cents( $xp->evaluate( 'string(*[local-name()="TaxAmount"])', $node ) );
		$percent = (int) $xp->evaluate( 'string(*[local-name()="Percent"])', $node );
		$derived = Kuka_Island_Core_Invoice_Order_Mapper::tax_from_taxable( $taxable, $percent );
		if ( $derived !== $tax ) {
			$subtotal_ok     = false;
			$subtotal_fail[] = sprintf( 'taxable=%d percent=%d expected=%d got=%d', $taxable, $percent, $derived, $tax );
		}
	}

	// Invariant 2: the document TaxTotal equals the sum of its own TaxSubtotals.
	$doc_subtotal_sum = 0;
	foreach ( $xp->query( '/*[local-name()="Invoice"]/*[local-name()="TaxTotal"]/*[local-name()="TaxSubtotal"]' ) as $node ) {
		$doc_subtotal_sum += $cents( $xp->evaluate( 'string(*[local-name()="TaxAmount"])', $node ) );
	}

	// Invariant 3: line extension amounts sum to the document line extension.
	$line_sum = 0;
	$line_allowance_sum = 0;
	foreach ( $xp->query( '//*[local-name()="InvoiceLine"]' ) as $node ) {
		$line_sum           += $cents( $xp->evaluate( 'string(*[local-name()="LineExtensionAmount"])', $node ) );
		$line_allowance_sum += $cents( $xp->evaluate( 'string(*[local-name()="AllowanceCharge"]/*[local-name()="Amount"])', $node ) );
	}

	$expected_discount = $cents( $scenario['discount_total'] );
	$expected_payable  = $cents( $scenario['order_total'] );

	$outcome['invariants'] = array(
		'per_subtotal_tax'     => $subtotal_ok,
		'doc_tax_sum'          => ( $doc_tax_total === $doc_subtotal_sum ),
		'tax_exclusive'        => ( $line_ext + $charge === $tax_excl ),
		'tax_inclusive'        => ( $tax_excl + $doc_tax_total === $tax_incl ),
		'payable_with_round'   => ( $tax_incl + $rounding === $payable ),
		'payable_equals_order' => ( $payable === $expected_payable ),
		'line_sum'             => ( $line_sum === $line_ext ),
		'line_allowance_sum'   => ( $line_allowance_sum === $expected_discount ),
		'doc_allowance_absent' => $doc_allowance_absent,
		'rounding_expected'    => ( $rounding === $cents( $scenario['expect_rounding'] ?? '0.00' ) ),
	);

	$outcome['subtotal_checks'] = $subtotal_count;
	$outcome['subtotal_fail']   = $subtotal_fail;
	$outcome['rounding']        = Kuka_Island_Core_Invoice_Order_Mapper::cents_to_amount( $rounding );
	$outcome['payable']         = $payable_s;
	$outcome['passed']          = ! in_array( false, $outcome['invariants'], true );

	kuka_test_delete_order( $order->get_id(), $run_id );

	return $outcome;
}

$monetary_scenarios = array(
	// Single-rate coupon: 1000.00 gross, 100.00 coupon, %10 KDV.
	'single_rate_coupon'      => array(
		'lines'          => array(
			array(
				'gross'   => '1000.00',
				'net'     => '900.00',
				'tax'     => '90.00',
				'percent' => 10,
			),
		),
		'discount_total' => '100.00',
		'order_total'    => '990.00',
	),
	// Multi-rate coupon: %10 + %20 buckets, 70.00 coupon split by WooCommerce.
	'multi_rate_coupon'       => array(
		'lines'          => array(
			array(
				'gross'   => '300.00',
				'net'     => '270.00',
				'tax'     => '27.00',
				'percent' => 10,
			),
			array(
				'gross'   => '400.00',
				'net'     => '360.00',
				'tax'     => '72.00',
				'percent' => 20,
			),
		),
		'discount_total' => '70.00',
		'order_total'    => '729.00',
	),
	// Shipping charge sharing the %20 bucket with a discounted line.
	'shipping_plus_coupon'    => array(
		'lines'          => array(
			array(
				'gross'   => '500.00',
				'net'     => '400.00',
				'tax'     => '80.00',
				'percent' => 20,
			),
		),
		'shipping'       => array(
			'net'     => '45.00',
			'tax'     => '9.00',
			'percent' => 20,
		),
		'discount_total' => '100.00',
		'order_total'    => '534.00',
	),
	// Free shipping, no coupon.
	'free_shipping'           => array(
		'lines'          => array(
			array(
				'gross'   => '500.00',
				'net'     => '500.00',
				'tax'     => '50.00',
				'percent' => 10,
			),
		),
		'discount_total' => '0.00',
		'order_total'    => '550.00',
	),
	// Kuruş distribution remainder: two 0.05 lines share the %10 bucket, so
	// WooCommerce's per-line tax (0.01 + 0.01) exceeds the bucket tax (0.01).
	// The difference is carried by cbc:PayableRoundingAmount.
	'kurus_remainder_shared'  => array(
		'lines'          => array(
			array(
				'gross'   => '0.05',
				'net'     => '0.05',
				'tax'     => '0.01',
				'percent' => 10,
			),
			array(
				'gross'   => '0.05',
				'net'     => '0.05',
				'tax'     => '0.01',
				'percent' => 10,
			),
		),
		'discount_total'  => '0.00',
		'order_total'     => '0.12',
		'expect_rounding' => '0.01',
	),
	// Three thirds of 100.00 at %20: per-line rounding yields 20.01, the bucket
	// yields 20.00, leaving a single kuruş of rounding.
	'kurus_thirds'            => array(
		'lines'          => array(
			array(
				'gross'   => '33.34',
				'net'     => '33.34',
				'tax'     => '6.67',
				'percent' => 20,
			),
			array(
				'gross'   => '33.33',
				'net'     => '33.33',
				'tax'     => '6.67',
				'percent' => 20,
			),
			array(
				'gross'   => '33.33',
				'net'     => '33.33',
				'tax'     => '6.67',
				'percent' => 20,
			),
		),
		'discount_total'  => '0.00',
		'order_total'     => '120.01',
		'expect_rounding' => '0.01',
	),
	// Coupon plus kuruş remainder in the same document.
	'coupon_with_remainder'   => array(
		'lines'          => array(
			array(
				'gross'   => '10.00',
				'net'     => '6.67',
				'tax'     => '1.33',
				'percent' => 20,
			),
			array(
				'gross'   => '10.00',
				'net'     => '6.67',
				'tax'     => '1.33',
				'percent' => 20,
			),
			array(
				'gross'   => '10.00',
				'net'     => '6.66',
				'tax'     => '1.33',
				'percent' => 20,
			),
		),
		'discount_total'  => '10.00',
		'order_total'     => '23.99',
		'expect_rounding' => '-0.01',
	),
);

// This shop is configured for whole-lira prices
// (woocommerce_price_num_decimals = 0), and WC_Abstract_Order::set_total()
// rounds through wc_get_price_decimals(). Kuruş-level fixtures therefore need
// two-decimal representation, which the wc_get_price_decimals filter provides
// in-process without touching any stored shop option.
$kuka_force_two_decimals = static fn (): int => 2;
add_filter( 'wc_get_price_decimals', $kuka_force_two_decimals, 99 );

$report(
	'INVOICE_MONETARY_FIXTURE_PRECISION',
	2 === (int) wc_get_price_decimals() && 1 === Kuka_Island_Core_Invoice_Order_Mapper::price_granularity_cents(),
	sprintf(
		'filtered_decimals:%d|granularity_cents:%d|stored_shop_decimals:%d',
		(int) wc_get_price_decimals(),
		Kuka_Island_Core_Invoice_Order_Mapper::price_granularity_cents(),
		(int) get_option( 'woocommerce_price_num_decimals', 2 )
	)
);

$monetary_all_passed = true;
$monetary_details    = array();
$monetary_subtotals  = 0;

foreach ( $monetary_scenarios as $name => $scenario ) {
	$outcome            = kuka_run_monetary_scenario( $test_run_id, $scenario, $config, $billing_props );
	$monetary_subtotals += (int) $outcome['subtotal_checks'];
	if ( ! $outcome['passed'] ) {
		$monetary_all_passed = false;
		$broken              = array_keys( array_filter( $outcome['invariants'] ?? array(), static fn( $v ) => false === $v ) );
		$monetary_details[]  = sprintf(
			'%s[err=%s broken=%s subtotal_fail=%s]',
			$name,
			$outcome['error_code'] ?: 'none',
			implode( '+', $broken ) ?: 'none',
			implode( '+', $outcome['subtotal_fail'] ?? array() ) ?: 'none'
		);
	} else {
		$monetary_details[] = sprintf( '%s[payable=%s rounding=%s]', $name, $outcome['payable'] ?? '?', $outcome['rounding'] ?? '?' );
	}
}

$report(
	'INVOICE_COUPON_VAT_KURUS_INVARIANTS',
	$monetary_all_passed,
	sprintf( 'scenarios:%d|tax_subtotals_checked:%d|%s', count( $monetary_scenarios ), $monetary_subtotals, implode( ' ', $monetary_details ) )
);

// Negative tests: inconsistent fiscal data must fail closed.
$negative_scenarios = array(
	'payable_total_mismatch'       => array(
		'lines'          => array(
			array(
				'gross'   => '100.00',
				'net'     => '100.00',
				'tax'     => '10.00',
				'percent' => 10,
			),
		),
		'discount_total' => '0.00',
		'order_total'    => '999.00',
		'expect_error'   => 'payable_total_mismatch',
	),
	'discount_allocation_mismatch' => array(
		'lines'          => array(
			array(
				'gross'   => '100.00',
				'net'     => '90.00',
				'tax'     => '9.00',
				'percent' => 10,
			),
		),
		'discount_total' => '25.00',
		'order_total'    => '99.00',
		'expect_error'   => 'discount_allocation_mismatch',
	),
);

$negative_results   = array();
$negative_all_ok    = true;
foreach ( $negative_scenarios as $name => $scenario ) {
	$outcome                 = kuka_run_monetary_scenario( $test_run_id, $scenario, $config, $billing_props );
	$negative_results[ $name ] = $outcome['error_code'];
	if ( ! $outcome['passed'] ) {
		$negative_all_ok = false;
	}
}

// An unresolvable tax rate on a taxed line must also fail closed.
$rate_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'total' => '110.00' ) ) );
$rate_order->update_meta_data( '_billing_tax_number', '12345678901' );
kuka_add_line( $rate_order, 'Oranı Bilinmeyen Ürün', '100.00', '100.00', 987654, '10.00' );
$rate_order->save();
$rate_code = '';
try {
	( new Kuka_Island_Core_Invoice_Order_Mapper( $config ) )->map_order_to_invoice_data(
		$rate_order,
		Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
		'EARSIVFATURA',
		'',
		'KUK2026000000042'
	);
} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
	$rate_code = $e->get_safe_error_code();
}
kuka_test_delete_order( $rate_order->get_id(), $test_run_id );
$negative_results['missing_tax_rate'] = $rate_code;
if ( 'missing_tax_rate' !== $rate_code ) {
	$negative_all_ok = false;
}

// The rate normaliser must not mangle WooCommerce's '10.0000' style strings.
$percent_cases = array(
	'10.0000' => 10,
	'20'      => 20,
	'0.0000'  => 0,
	'8.5000'  => null,
	''        => null,
	'abc'     => null,
);
$percent_ok = true;
foreach ( $percent_cases as $raw => $expected ) {
	if ( Kuka_Island_Core_Invoice_Order_Mapper::normalize_percent( $raw ) !== $expected ) {
		$percent_ok = false;
	}
}

// Back to the shop's real precision: whole-lira orders must still balance and
// the rounding bound must follow the shop's own granularity.
remove_filter( 'wc_get_price_decimals', $kuka_force_two_decimals, 99 );

$native_scenarios = array(
	'native_whole_lira_coupon' => array(
		'lines'          => array(
			array(
				'gross'   => '600',
				'net'     => '500',
				'tax'     => '100',
				'percent' => 20,
			),
		),
		'discount_total' => '100',
		'order_total'    => '600',
	),
	'native_untaxed_order'     => array(
		'lines'          => array(
			array(
				'gross'   => '604',
				'net'     => '604',
				'tax'     => '0',
				'percent' => 0,
			),
		),
		'discount_total' => '0',
		'order_total'    => '604',
	),
);

$native_all_passed = true;
$native_details    = array();
foreach ( $native_scenarios as $name => $scenario ) {
	$outcome = kuka_run_monetary_scenario( $test_run_id, $scenario, $config, $billing_props );
	if ( ! $outcome['passed'] ) {
		$native_all_passed = false;
		$broken            = array_keys( array_filter( $outcome['invariants'] ?? array(), static fn( $v ) => false === $v ) );
		$native_details[]  = sprintf( '%s[err=%s broken=%s]', $name, $outcome['error_code'] ?: 'none', implode( '+', $broken ) ?: 'none' );
	} else {
		$native_details[] = sprintf( '%s[payable=%s rounding=%s]', $name, $outcome['payable'] ?? '?', $outcome['rounding'] ?? '?' );
	}
}

$report(
	'INVOICE_COUPON_VAT_NATIVE_SHOP_PRECISION',
	$native_all_passed,
	sprintf(
		'shop_decimals:%d|granularity_cents:%d|%s',
		(int) wc_get_price_decimals(),
		Kuka_Island_Core_Invoice_Order_Mapper::price_granularity_cents(),
		implode( ' ', $native_details )
	)
);

$report(
	'INVOICE_MONETARY_NEGATIVE_TESTS',
	$negative_all_ok && $percent_ok,
	sprintf(
		'codes:%s|percent_normaliser:%s',
		implode( ',', array_map( static fn( $k, $v ) => $k . '=' . ( $v ?: 'none' ), array_keys( $negative_results ), $negative_results ) ),
		$percent_ok ? 'ok' : 'broken'
	)
);

/* ========================================================================== */
/* TEST 11 - Duplicate-send state machine through the PRODUCTION manager       */
/* (no test subclass, no overridden guard: these orders are not fixtures)      */
/* ========================================================================== */

/**
 * Create a complete, invoiceable order for the duplicate-send state machine.
 *
 * @param string               $run_id Isolation run ID.
 * @param array<string, mixed> $billing_props Shared billing props.
 * @param array<string, string> $meta Invoice meta to seed.
 */
function kuka_create_lock_order( string $run_id, array $billing_props, array $meta = array() ): WC_Order {
	$order = kuka_create_test_order( $run_id, array_merge( $billing_props, array( 'total' => '100.00' ) ) );
	$order->update_meta_data( '_billing_tax_number', '12345678901' );

	$item = new WC_Order_Item_Product();
	$item->set_name( 'Kilit Testi Ürünü' );
	$item->set_quantity( 1 );
	$item->set_subtotal( '100.00' );
	$item->set_total( '100.00' );
	$order->add_item( $item );

	foreach ( $meta as $key => $value ) {
		$order->update_meta_data( $key, $value );
	}

	$order->save();

	return $order;
}

$lock_scenarios = array(
	'INVOICE_LOCK_SENT_RECONCILE'    => Kuka_Island_Core_Invoice_Status::STATUS_SENT,
	'INVOICE_LOCK_PENDING_RECONCILE' => Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL,
	'INVOICE_LOCK_SENDING_RECONCILE' => Kuka_Island_Core_Invoice_Status::STATUS_SENDING,
);

foreach ( $lock_scenarios as $test_name => $seed_status ) {
	$transport = new Kuka_Island_Test_Tracking_Transport();
	$manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $transport ) );
	$order     = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array(
			'_kuka_invoice_status'        => $seed_status,
			'_kuka_invoice_uuid'          => 'uuid-' . $seed_status,
			'_kuka_invoice_number'        => 'KUK2026000000042',
			'_kuka_invoice_number_source' => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
		)
	);

	$error = '';
	try {
		$manager->process_order( $order );
	} catch ( Throwable $t ) {
		$error = get_class( $t ) . ': ' . $t->getMessage();
	}

	$passed = 0 === ( $transport->calls['SendInvoice'] ?? 0 ) && 1 === ( $transport->calls['GetInvoiceStatus'] ?? 0 );
	$report(
		$test_name,
		$passed,
		sprintf(
			'SendInvoice:%d|GetInvoiceStatus:%d|error:%s',
			$transport->calls['SendInvoice'] ?? 0,
			$transport->calls['GetInvoiceStatus'] ?? 0,
			'' === $error ? 'none' : $error
		)
	);

	kuka_test_delete_order( $order->get_id(), $test_run_id );
}

// Network drop during SendInvoice -> send_uncertain; the retry reconciles
// instead of sending again (total SendInvoice stays 1).
$drop_transport = new Kuka_Island_Test_Tracking_Transport();
$drop_transport->simulate_timeout_on_send = true;
$drop_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $drop_transport ) );
$drop_order   = kuka_create_lock_order(
	$test_run_id,
	$billing_props,
	array(
		'_kuka_invoice_number'        => 'KUK2026000000042',
		'_kuka_invoice_number_source' => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
	)
);

$drop_caught = false;
try {
	$drop_manager->process_order( $drop_order );
} catch ( Exception $e ) {
	$drop_caught = true;
}
$drop_order->read_meta_data( true );
$drop_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $drop_order );

$drop_transport->simulate_timeout_on_send = false;
$retry_result = null;
$retry_error  = '';
try {
	$retry_result = $drop_manager->process_order( $drop_order );
} catch ( Throwable $t ) {
	$retry_error = get_class( $t ) . ': ' . $t->getMessage();
}

$report(
	'INVOICE_NETWORK_DROP_UNCERTAIN_LOCK',
	$drop_caught
	&& Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN === $drop_status
	&& 1 === ( $drop_transport->calls['SendInvoice'] ?? 0 )
	&& 1 === ( $drop_transport->calls['GetInvoiceStatus'] ?? 0 )
	&& $retry_result instanceof Kuka_Island_Core_Invoice_Result
	&& $retry_result->is_success(),
	sprintf(
		'SendInvoice:%d|GetInvoiceStatus:%d|uncertain_status:%s|retry_error:%s',
		$drop_transport->calls['SendInvoice'] ?? 0,
		$drop_transport->calls['GetInvoiceStatus'] ?? 0,
		$drop_status,
		'' === $retry_error ? 'none' : $retry_error
	)
);
kuka_test_delete_order( $drop_order->get_id(), $test_run_id );

// Reconciliation also fails -> SendInvoice stays blocked.
$recon_transport = new Kuka_Island_Test_Tracking_Transport();
$recon_transport->simulate_timeout_on_status = true;
$recon_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $recon_transport ) );
$recon_order   = kuka_create_lock_order(
	$test_run_id,
	$billing_props,
	array(
		'_kuka_invoice_status'        => Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN,
		'_kuka_invoice_uuid'          => 'uuid-uncertain-test',
		'_kuka_invoice_number'        => 'KUK2026000000042',
		'_kuka_invoice_number_source' => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
	)
);

$recon_blocked = false;
$recon_code    = '';
try {
	$recon_manager->process_order( $recon_order );
} catch ( Kuka_Island_Core_Invoice_Transient_Exception $e ) {
	$recon_code    = $e->get_safe_error_code();
	$recon_blocked = in_array( $recon_code, array( 'reconciliation_required', 'edm_soap_fault', 'edm_network_error' ), true );
}
$report(
	'INVOICE_RECONCILIATION_TIMEOUT_LOCK',
	$recon_blocked && 0 === ( $recon_transport->calls['SendInvoice'] ?? 0 ),
	sprintf( 'SendInvoice:%d|code:%s', $recon_transport->calls['SendInvoice'] ?? 0, $recon_code ?: 'none' )
);
kuka_test_delete_order( $recon_order->get_id(), $test_run_id );

// Terminal completed invoices can never be re-sent, even with force=true.
$terminal_transport = new Kuka_Island_Test_Tracking_Transport();
$terminal_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $terminal_transport ) );
$terminal_order     = kuka_create_lock_order(
	$test_run_id,
	$billing_props,
	array(
		'_kuka_invoice_status'        => Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED,
		'_kuka_invoice_uuid'          => 'uuid-completed-test',
		'_kuka_invoice_number'        => 'KUK2026000000042',
		'_kuka_invoice_number_source' => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
	)
);

$terminal_code = '';
try {
	$terminal_manager->process_order( $terminal_order, true );
} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
	$terminal_code = $e->get_safe_error_code();
}
$report(
	'INVOICE_TERMINAL_COMPLETED_LOCK',
	'already_terminal_invoice' === $terminal_code && 0 === ( $terminal_transport->calls['SendInvoice'] ?? 0 ),
	sprintf( 'SendInvoice:%d|code:%s', $terminal_transport->calls['SendInvoice'] ?? 0, $terminal_code ?: 'none' )
);
kuka_test_delete_order( $terminal_order->get_id(), $test_run_id );

/* ========================================================================== */
/* TEST 12 - Refund audit note                                                */
/* ========================================================================== */

$refund_order = kuka_create_lock_order(
	$test_run_id,
	$billing_props,
	array(
		'_kuka_invoice_status' => Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED,
		'_kuka_invoice_number' => 'KUK2026000000099',
	)
);

( new Kuka_Island_Core_Invoice_Queue( new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, new Kuka_Island_Test_Tracking_Transport() ) ) ) )
	->handle_order_refund( $refund_order->get_id(), 1 );

$refund_note_found = false;
foreach ( wc_get_order_notes( array( 'order_id' => $refund_order->get_id() ) ) as $order_note ) {
	if ( str_contains( $order_note->content, 'KUK2026000000099' ) && str_contains( $order_note->content, 'iade' ) ) {
		$refund_note_found = true;
		break;
	}
}
$report( 'INVOICE_REFUND_HANDLING', $refund_note_found, 'refund_note_added:' . ( $refund_note_found ? 'yes' : 'no' ) );
kuka_test_delete_order( $refund_order->get_id(), $test_run_id );

/* ========================================================================== */
/* TEST 13 (audit item 8) - Real read-only EDM test-endpoint query             */
/* ========================================================================== */

$real_user   = (string) ( getenv( 'KUKA_EDM_USERNAME' ) ?: ( defined( 'KUKA_EDM_USERNAME' ) ? KUKA_EDM_USERNAME : '' ) );
$real_pass   = (string) ( getenv( 'KUKA_EDM_PASSWORD' ) ?: ( defined( 'KUKA_EDM_PASSWORD' ) ? KUKA_EDM_PASSWORD : '' ) );
$real_secret = (string) ( getenv( 'KUKA_EDM_SECRET_KEY' ) ?: ( defined( 'KUKA_EDM_SECRET_KEY' ) ? KUKA_EDM_SECRET_KEY : '' ) );

if ( '' === $real_user || '' === $real_pass ) {
	// Explicitly BLOCKED, never reported as PASS.
	$note( 'REAL_EDM_LOGIN=BLOCKED|reason:no_runtime_credentials' );
	$note( 'REAL_EDM_CHECK_COUNTER=BLOCKED|reason:no_runtime_credentials' );
	$note( 'REAL_EDM_LOGOUT=BLOCKED|reason:no_runtime_credentials' );
	$note( 'REAL_EDM_SEND_INVOICE=SKIPPED|reason:read_only_verification_never_sends' );
} else {
	$real_config = new Kuka_Island_Core_Invoice_Config(
		array(
			'environment' => Kuka_Island_Core_Invoice_Config::ENV_TEST,
			'username'    => $real_user,
			'password'    => $real_pass,
			'secret_key'  => $real_secret,
			'sender_vkn'  => (string) ( getenv( 'KUKA_EDM_SENDER_VKN' ) ?: '' ),
		)
	);
	$real_client = new Kuka_Island_Core_EDM_Client( $real_config );

	// 1. Login, measured on its own.
	$real_session    = '';
	$real_login_code = '';
	try {
		$real_session = $real_client->login();
	} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
		$real_login_code = $e->get_safe_error_code();
	}
	$login_ok = '' !== $real_session;
	$report( 'REAL_EDM_LOGIN', $login_ok, $login_ok ? 'session_obtained:yes' : 'safe_code:' . ( $real_login_code ?: 'unknown' ) );

	// 2. CheckCounter, verified through the counter_left field.
	if ( $login_ok ) {
		$counter_left  = null;
		$counter_code  = '';
		try {
			$real_counter = $real_client->check_counter();
			$counter_left = $real_counter['counter_left'] ?? null;
		} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
			$counter_code = $e->get_safe_error_code();
		}
		$report(
			'REAL_EDM_CHECK_COUNTER',
			is_int( $counter_left ) && $counter_left >= 0,
			is_int( $counter_left ) ? sprintf( 'counter_left:%d', $counter_left ) : 'safe_code:' . ( $counter_code ?: 'counter_left_missing' )
		);

		// 3. Logout, measured on its own.
		$logout_ok = false;
		try {
			$logout_ok = $real_client->logout() && null === $real_client->get_session_id();
		} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
			$logout_ok = false;
		}
		$report( 'REAL_EDM_LOGOUT', $logout_ok, 'session_closed:' . ( $logout_ok ? 'yes' : 'no' ) );
	} else {
		$note( 'REAL_EDM_CHECK_COUNTER=BLOCKED|reason:login_failed' );
		$note( 'REAL_EDM_LOGOUT=BLOCKED|reason:login_failed' );
	}

	$note( 'REAL_EDM_SEND_INVOICE=SKIPPED|reason:read_only_verification_never_sends' );
}

/* ========================================================================== */
/* TEST 14 (audit item 9) - Cleanup state machine, DB discoverability,         */
/* ownership refusal and re-entry protection                                   */
/* ========================================================================== */

// Every fixture is discoverable from the database by its run ID, so a fatal
// error cannot orphan it.
$discovery_order = kuka_create_lock_order( $test_run_id, $billing_props );
$discovered_ids  = kuka_discover_run_order_ids( $test_run_id );
$report(
	'INVOICE_FIXTURE_DB_DISCOVERABLE',
	in_array( $discovery_order->get_id(), $discovered_ids, true )
	&& count( $discovered_ids ) === count( $GLOBALS['kuka_invoice_tracked_orders'] ),
	sprintf(
		'run_meta:%s|discovered:%d|registry:%d',
		KUKA_TEST_RUN_META,
		count( $discovered_ids ),
		count( $GLOBALS['kuka_invoice_tracked_orders'] )
	)
);

// Ownership refusal through the real coordinator: a tracked fixture owned by a
// different run must be refused, leaving state=failed and a non-zero exit code.
$probe_order    = kuka_create_test_order( $probe_run_id, array_merge( $billing_props, array( 'total' => '100.00' ) ) );
$probe_order_id = $probe_order->get_id();

$probe_cleanup = kuka_run_cleanup( $test_run_id, 'kuka_probe_cleanup_state', array( $probe_order_id ) );
$probe_survived = wc_get_order( $probe_order_id ) instanceof WC_Order;

$report(
	'INVOICE_CLEANUP_OWNERSHIP_REFUSAL',
	'failed' === $probe_cleanup['state']
	&& in_array( $probe_order_id, $probe_cleanup['refused'], true )
	&& 1 === kuka_cleanup_exit_code( $probe_cleanup['state'] )
	&& $probe_survived,
	sprintf(
		'state:%s|refused:%d|exit_code:%d|record_preserved:%s',
		$probe_cleanup['state'],
		count( $probe_cleanup['refused'] ),
		kuka_cleanup_exit_code( $probe_cleanup['state'] ),
		$probe_survived ? 'yes' : 'no'
	)
);

// Re-entry protection: a second run on the same coordinator is refused and the
// state is left untouched.
$probe_reentry = kuka_run_cleanup( $test_run_id, 'kuka_probe_cleanup_state', array( $probe_order_id ) );
$report(
	'INVOICE_CLEANUP_REENTRY_GUARD',
	true === $probe_reentry['reentry_blocked']
	&& 'failed' === $probe_reentry['state']
	&& 'failed' === $GLOBALS['kuka_probe_cleanup_state'],
	sprintf( 'reentry_blocked:%s|state:%s', $probe_reentry['reentry_blocked'] ? 'yes' : 'no', $probe_reentry['state'] )
);

// The rightful owner cleans it up: idle -> running -> succeeded.
$probe_owner_cleanup = kuka_run_cleanup( $probe_run_id, 'kuka_probe_cleanup_state_2' );
$report(
	'INVOICE_CLEANUP_STATE_MACHINE_PROBE',
	'succeeded' === $probe_owner_cleanup['state']
	&& empty( $probe_owner_cleanup['refused'] )
	&& empty( $probe_owner_cleanup['leftover'] )
	&& 0 === kuka_cleanup_exit_code( $probe_owner_cleanup['state'] )
	&& ! wc_get_order( $probe_order_id ) instanceof WC_Order,
	sprintf(
		'state:%s|considered:%d|refused:%d|leftover:%d|exit_code:%d',
		$probe_owner_cleanup['state'],
		count( $probe_owner_cleanup['considered'] ),
		count( $probe_owner_cleanup['refused'] ),
		count( $probe_owner_cleanup['leftover'] ),
		kuka_cleanup_exit_code( $probe_owner_cleanup['state'] )
	)
);

// Primary coordinator run for this test run.
$main_cleanup = kuka_run_cleanup( $test_run_id, 'kuka_invoice_cleanup_state', array_keys( $GLOBALS['kuka_invoice_tracked_orders'] ) );
$main_reentry = kuka_run_cleanup( $test_run_id, 'kuka_invoice_cleanup_state', array() );

$report(
	'INVOICE_CLEANUP_STATE_MACHINE_MAIN',
	'succeeded' === $main_cleanup['state']
	&& empty( $main_cleanup['refused'] )
	&& empty( $main_cleanup['leftover'] )
	&& true === $main_reentry['reentry_blocked']
	&& empty( $GLOBALS['kuka_invoice_tracked_orders'] ),
	sprintf(
		'state:%s|considered:%d|refused:%d|leftover:%d|reentry_blocked:%s|registry_remaining:%d',
		$main_cleanup['state'],
		count( $main_cleanup['considered'] ),
		count( $main_cleanup['refused'] ),
		count( $main_cleanup['leftover'] ),
		$main_reentry['reentry_blocked'] ? 'yes' : 'no',
		count( $GLOBALS['kuka_invoice_tracked_orders'] )
	)
);

/* ========================================================================== */
/* TEST 15 - Database keyset isolation                                        */
/* ========================================================================== */

$post_keysets = kuka_invoice_capture_keysets();

$table_diff = array();
foreach ( $pre_keysets['tables'] as $table_key => $pre_rows ) {
	$post_rows = $post_keysets['tables'][ $table_key ] ?? array();
	if ( $pre_rows !== $post_rows ) {
		$table_diff[] = sprintf( '%s(%d->%d)', $table_key, count( $pre_rows ), count( $post_rows ) );
	}
}

$report(
	'INVOICE_TEST_DATABASE_ISOLATION',
	$pre_keysets['hash'] === $post_keysets['hash'] && empty( $table_diff ),
	sprintf(
		'tables:%d|missing:%s|pre_hash:%s|post_hash:%s|diff:%s',
		count( $pre_keysets['tables'] ),
		empty( $pre_keysets['missing'] ) ? 'none' : implode( ',', $pre_keysets['missing'] ),
		substr( $pre_keysets['hash'], 0, 12 ),
		substr( $post_keysets['hash'], 0, 12 ),
		empty( $table_diff ) ? 'none' : implode( ',', $table_diff )
	)
);

if ( ! empty( $failures ) ) {
	WP_CLI::error( sprintf( 'EDM Invoice integration verification failed (%d failures: %s).', count( $failures ), implode( ', ', $failures ) ) );
}

WP_CLI::success( 'All EDM Invoice integration Stage 1 verification tests passed cleanly.' );
