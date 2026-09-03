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
require_once __DIR__ . '/lib-edm-module-loader.php';
$kuka_edm_module = kuka_edm_load_module();

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
// The status poller's own action. Any scenario that reaches SendInvoice now
// books one of these, so fixture cleanup has to account for it.
const KUKA_TEST_POLL_HOOK = 'kuka_island_query_invoice_status';

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
		'paid_date'      => 'set_date_paid',
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

	// Any scenario that reached SendInvoice booked a status query. Removing the
	// fixture without removing the booking would leave an action pointing at a
	// deleted order.
	kuka_purge_queue_scheduling( array( $order_id ) );

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

	$hooks = array( KUKA_TEST_QUEUE_HOOK, KUKA_TEST_POLL_HOOK );

	foreach ( $order_ids as $order_id ) {
		foreach ( $hooks as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array( 'order_id' => $order_id ), 'kuka-island-invoice' );
			}
		}
		// Only the queue hook has a wp-cron fallback shape.
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
				'SELECT action_id FROM ' . $actions_table . ' WHERE hook IN (%s, %s) AND args LIKE %s',
				KUKA_TEST_QUEUE_HOOK,
				KUKA_TEST_POLL_HOOK,
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
				'SELECT COUNT(*) FROM ' . $actions_table . ' WHERE hook IN (%s, %s) AND args LIKE %s',
				KUKA_TEST_QUEUE_HOOK,
				KUKA_TEST_POLL_HOOK,
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
	// A settled order has a payment date, and INTERNETSALESDETAILS reports it.
	// Fixtures that must be UNPAID pass 'paid_date' => '' to skip the setter.
	'paid_date'      => '2026-08-20 10:15:00',
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
/* TEST 4 - Individual e-Archive receiver contract (EDM support, in writing)   */
/* ========================================================================== */

/*
 * EDM technical support confirmed the individual e-Arşiv recipient contract:
 * the generic consumer TCKN 11111111111, with the buyer's REAL name from the
 * WooCommerce billing fields in the UBL cac:Person block. So no TCKN is asked
 * for at checkout -- and in exchange the name and the e-mail address are not
 * optional, because a generic title such as "Nihai Tüketici" would be a
 * fabricated party on a fiscal document and a missing address means a document
 * the buyer never receives.
 */
$individual_order = kuka_create_test_order(
	$test_run_id,
	array_merge(
		$billing_props,
		array(
			'total'      => '110.00',
			'first_name' => 'Zeynep',
			'last_name'  => 'Aydın',
			'email'      => 'zeynep.aydin@example.com',
		)
	)
);
kuka_add_line( $individual_order, 'Test Ürün', '100.00', '100.00', 1, '10.00' );
kuka_add_tax_rate( $individual_order, 1, 10, '10.00' );
$individual_order->save();

$individual_mapper = new Kuka_Island_Core_Invoice_Order_Mapper( $config );
$individual_data   = null;
$individual_error  = '';
try {
	$individual_data = $individual_mapper->map_order_to_invoice_data(
		wc_get_order( $individual_order->get_id() ),
		Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
		'EARSIVFATURA',
		'',
		Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL
	);
} catch ( Throwable $t ) {
	$individual_error = get_class( $t ) . ': ' . $t->getMessage();
}

$individual_customer = (array) ( $individual_data['customer'] ?? array() );
$individual_ubl      = '';
if ( null !== $individual_data ) {
	$individual_ubl = ( new Kuka_Island_Core_UBL_TR_Builder( $individual_data ) )->build_xml();
}

$individual_dom = new DOMDocument();
if ( '' !== $individual_ubl ) {
	$individual_dom->loadXML( $individual_ubl );
}
$individual_xp = new DOMXPath( $individual_dom );

/**
 * First text value at an XPath in the produced UBL, or '' when absent.
 *
 * @param DOMXPath $xp   Prepared XPath.
 * @param string   $expr Expression.
 */
$ubl_text = static function ( DOMXPath $xp, string $expr ): string {
	$nodes = $xp->query( $expr );

	return ( false !== $nodes && $nodes->length > 0 ) ? trim( (string) $nodes->item( 0 )->nodeValue ) : '';
};

$individual_customer_scope = '//*[local-name()="AccountingCustomerParty"]/*[local-name()="Party"]';
$ubl_tckn       = $ubl_text( $individual_xp, $individual_customer_scope . '/*[local-name()="PartyIdentification"]/*[local-name()="ID"]' );
$ubl_first      = $ubl_text( $individual_xp, $individual_customer_scope . '/*[local-name()="Person"]/*[local-name()="FirstName"]' );
$ubl_family     = $ubl_text( $individual_xp, $individual_customer_scope . '/*[local-name()="Person"]/*[local-name()="FamilyName"]' );
$ubl_mail       = $ubl_text( $individual_xp, $individual_customer_scope . '/*[local-name()="Contact"]/*[local-name()="ElectronicMail"]' );
$ubl_party_name = $ubl_text( $individual_xp, $individual_customer_scope . '/*[local-name()="PartyName"]/*[local-name()="Name"]' );
$ubl_cbc_id     = $ubl_text( $individual_xp, '/*[local-name()="Invoice"]/*[local-name()="ID"]' );
$id_scheme_node = $individual_xp->query( $individual_customer_scope . '/*[local-name()="PartyIdentification"]/*[local-name()="ID"]/@schemeID' );
$ubl_id_scheme  = ( false !== $id_scheme_node && $id_scheme_node->length > 0 ) ? trim( (string) $id_scheme_node->item( 0 )->nodeValue ) : '';

$report(
	'INVOICE_INDIVIDUAL_EARCHIVE_RECEIVER_CONTRACT',
	'' === $individual_error
	&& Kuka_Island_Core_Invoice_Order_Mapper::GENERIC_INDIVIDUAL_TCKN === (string) ( $individual_customer['tax_number'] ?? '' )
	&& '11111111111' === $ubl_tckn
	&& 'TCKN' === $ubl_id_scheme
	// The buyer's real name, exactly as WooCommerce recorded it.
	&& 'Zeynep' === $ubl_first
	&& 'Aydın' === $ubl_family
	// And no generic party name standing in for it.
	&& '' === $ubl_party_name
	// The same address that goes into SendInvoiceRequest HEADER/TO.
	&& 'zeynep.aydin@example.com' === $ubl_mail
	&& 'zeynep.aydin@example.com' === (string) ( $individual_customer['email'] ?? '' )
	// cbc:ID is the automatic-numbering request, not a number.
	&& Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL === $ubl_cbc_id,
	sprintf(
		'measured:production_mapper_and_ubl|tckn:%s|id_scheme:%s|first_name:%s|family_name:%s|party_name:%s|electronic_mail:%s|cbc_id:%s|error:%s',
		$ubl_tckn ?: 'none',
		$ubl_id_scheme ?: 'none',
		$ubl_first ?: 'ABSENT',
		$ubl_family ?: 'ABSENT',
		'' === $ubl_party_name ? 'none' : $ubl_party_name,
		$ubl_mail ?: 'ABSENT',
		$ubl_cbc_id ?: 'none',
		'' === $individual_error ? 'none' : $individual_error
	)
);

/* ------------------------------------------------------------------------ */
/* cac:Person sits where UBL PartyType puts it: after cac:Contact            */
/* ------------------------------------------------------------------------ */

/*
 * EDM refused a real SendInvoice on 3 September 2026 because cac:Person was
 * not in a valid UBL position for a TCKN-identified buyer. The builder was
 * appending it next to cac:PartyIdentification, so the produced order was
 * PartyIdentification, Person, PostalAddress, PartyTaxScheme, Contact.
 *
 * PartyType's sequence -- in UBL 2.1 and in EDM's own WSDL, which declares
 * PartyIdentification, PartyName, PostalAddress, PhysicalLocation,
 * PartyTaxScheme, PartyLegalEntity, Contact, Person, AgentParty -- puts Person
 * LAST of those. This measures the produced document, not the source: the
 * child element names are read out of the real builder's XML in document
 * order and checked against that sequence.
 */
$party_children = static function ( DOMXPath $xp, string $scope ): array {
	$names = array();
	$nodes = $xp->query( $scope . '/*' );
	if ( false !== $nodes ) {
		foreach ( $nodes as $node ) {
			if ( $node instanceof DOMElement ) {
				$names[] = (string) $node->localName;
			}
		}
	}

	return $names;
};

/** PartyType's declared order, as EDM's WSDL lists it. */
$party_type_sequence = array(
	'PartyIdentification',
	'PartyName',
	'PostalAddress',
	'PhysicalLocation',
	'PartyTaxScheme',
	'PartyLegalEntity',
	'Contact',
	'Person',
	'AgentParty',
);

$ordered_by_sequence = static function ( array $sent, array $declared ): bool {
	$positions = array();
	foreach ( $sent as $name ) {
		$idx = array_search( $name, $declared, true );
		if ( false === $idx ) {
			return false;
		}
		$positions[] = (int) $idx;
	}
	$sorted = $positions;
	sort( $sorted );

	return $positions === $sorted;
};

$ind_party_order  = $party_children( $individual_xp, $individual_customer_scope );
$ind_person_count = (int) ( ( false !== $individual_xp->query( $individual_customer_scope . '/*[local-name()="Person"]' ) )
	? $individual_xp->query( $individual_customer_scope . '/*[local-name()="Person"]' )->length
	: 0 );

$person_idx  = array_search( 'Person', $ind_party_order, true );
$contact_idx = array_search( 'Contact', $ind_party_order, true );

// Person carries exactly FirstName and FamilyName -- nothing more, nothing less.
$person_kids = $party_children( $individual_xp, $individual_customer_scope . '/*[local-name()="Person"]' );

/*
 * The corporate path must be untouched: a company buyer gets PartyName and no
 * Person at all. Built through the same production builder, from the same
 * mapper output, with only the company and a 10-digit VKN changed.
 */
$corporate_person_count = -1;
$corporate_party_order  = array();
$corporate_party_name   = '';
$corporate_id_scheme    = '';
$corporate_error        = '';
if ( null !== $individual_data ) {
	$corporate_data                          = $individual_data;
	$corporate_data['customer']['company']    = 'Kuka Test Kurumsal A.Ş.';
	$corporate_data['customer']['tax_number'] = '1234567890';
	$corporate_data['customer']['tax_office'] = 'Beşiktaş';
	try {
		$corp_dom = new DOMDocument();
		$corp_dom->loadXML( ( new Kuka_Island_Core_UBL_TR_Builder( $corporate_data ) )->build_xml() );
		$corp_xp                = new DOMXPath( $corp_dom );
		$corporate_party_order  = $party_children( $corp_xp, $individual_customer_scope );
		$corp_person_nodes      = $corp_xp->query( $individual_customer_scope . '/*[local-name()="Person"]' );
		$corporate_person_count = ( false !== $corp_person_nodes ) ? $corp_person_nodes->length : -1;
		$corporate_party_name   = $ubl_text( $corp_xp, $individual_customer_scope . '/*[local-name()="PartyName"]/*[local-name()="Name"]' );
		$scheme_node            = $corp_xp->query( $individual_customer_scope . '/*[local-name()="PartyIdentification"]/*[local-name()="ID"]/@schemeID' );
		$corporate_id_scheme    = ( false !== $scheme_node && $scheme_node->length > 0 ) ? trim( (string) $scheme_node->item( 0 )->nodeValue ) : '';
	} catch ( Throwable $t ) {
		$corporate_error = get_class( $t );
	}
}

$person_order_ok = 1 === $ind_person_count
	&& false !== $person_idx
	&& false !== $contact_idx
	&& $person_idx > $contact_idx
	// The whole produced sequence, not only the Person/Contact pair.
	&& $ordered_by_sequence( $ind_party_order, $party_type_sequence )
	// The exact old defective order must no longer be producible.
	&& array( 'PartyIdentification', 'Person', 'PostalAddress', 'PartyTaxScheme', 'Contact' ) !== $ind_party_order
	&& array( 'FirstName', 'FamilyName' ) === $person_kids
	&& '' !== $ubl_first
	&& '' !== $ubl_family
	&& 'TCKN' === $ubl_id_scheme
	// Corporate behaviour unchanged.
	&& '' === $corporate_error
	&& 0 === $corporate_person_count
	&& 'Kuka Test Kurumsal A.Ş.' === $corporate_party_name
	&& 'VKN' === $corporate_id_scheme
	&& $ordered_by_sequence( $corporate_party_order, $party_type_sequence );

$report(
	'INVOICE_INDIVIDUAL_PERSON_USES_VALID_PARTY_ORDER',
	$person_order_ok,
	sprintf(
		'measured:production_builder_xml_dom|individual_order:%s|person_nodes:%d|person_after_contact:%s|person_children:%s|first_name:%s|family_name:%s|id_scheme:%s|old_defective_order_producible:%s|corporate_order:%s|corporate_person_nodes:%d|corporate_party_name:%s|corporate_id_scheme:%s|error:%s',
		implode( ',', $ind_party_order ),
		$ind_person_count,
		( false !== $person_idx && false !== $contact_idx && $person_idx > $contact_idx ) ? 'yes' : 'NO',
		implode( ',', $person_kids ),
		'' !== $ubl_first ? 'present' : 'ABSENT',
		'' !== $ubl_family ? 'present' : 'ABSENT',
		$ubl_id_scheme ?: 'none',
		array( 'PartyIdentification', 'Person', 'PostalAddress', 'PartyTaxScheme', 'Contact' ) === $ind_party_order ? 'YES' : 'no',
		implode( ',', $corporate_party_order ),
		$corporate_person_count,
		'' === $corporate_party_name ? 'ABSENT' : $corporate_party_name,
		$corporate_id_scheme ?: 'none',
		'' === $corporate_error ? 'none' : $corporate_error
	)
);

kuka_test_delete_order( $individual_order->get_id(), $test_run_id );

// Each missing fact is refused by its own name, and produces no document.
$receiver_gap_cases = array(
	'no_first_name' => array( array( 'first_name' => '' ), 'missing_individual_name' ),
	'no_last_name'  => array( array( 'last_name' => '' ), 'missing_individual_name' ),
	'no_name_at_all' => array( array( 'first_name' => '', 'last_name' => '' ), 'missing_individual_name' ),
	'no_email'      => array( array( 'email' => '' ), 'missing_customer_email' ),
);

/*
 * A malformed address never reaches the mapper: WooCommerce itself refuses to
 * store one on the order. Measured rather than assumed, because it is why the
 * mapper's own is_email() check is a second line and not the only one.
 */
$malformed_email_refused = false;
try {
	$malformed_probe = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'total' => '110.00' ) ) );
	$malformed_probe->set_billing_email( 'not-an-address' );
	kuka_test_delete_order( $malformed_probe->get_id(), $test_run_id );
} catch ( WC_Data_Exception $e ) {
	$malformed_email_refused = true;
	if ( isset( $malformed_probe ) && $malformed_probe instanceof WC_Order ) {
		kuka_test_delete_order( $malformed_probe->get_id(), $test_run_id );
	}
}

$receiver_gap_ok      = true;
$receiver_gap_details = array();
foreach ( $receiver_gap_cases as $case => $spec ) {
	$gap_order = kuka_create_test_order(
		$test_run_id,
		array_merge(
			$billing_props,
			array(
				'total'      => '110.00',
				'first_name' => 'Zeynep',
				'last_name'  => 'Aydın',
				'email'      => 'zeynep.aydin@example.com',
			),
			$spec[0]
		)
	);
	kuka_add_line( $gap_order, 'Test Ürün', '100.00', '100.00', 1, '10.00' );
	kuka_add_tax_rate( $gap_order, 1, 10, '10.00' );
	$gap_order->save();

	$gap_code = '';
	$gap_built = false;
	try {
		$individual_mapper->map_order_to_invoice_data(
			wc_get_order( $gap_order->get_id() ),
			Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
			'EARSIVFATURA',
			'',
			Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL
		);
		$gap_built = true;
	} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
		$gap_code = $e->get_safe_error_code();
	}

	$hit = false === $gap_built && $spec[1] === $gap_code;
	$receiver_gap_details[] = $case . '=' . ( $gap_code ?: ( $gap_built ? 'BUILT' : 'refused' ) );
	if ( ! $hit ) {
		$receiver_gap_ok = false;
	}

	kuka_test_delete_order( $gap_order->get_id(), $test_run_id );
}

// No generic consumer title anywhere in the module, and no checkout field
// asking a retail customer for a TCKN.
$receiver_module_files = (array) ( glob( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/*.php' ) ?: array() );
$module_generic_titles = array();
foreach ( $receiver_module_files as $receiver_module_file ) {
	$receiver_module_source = (string) file_get_contents( $receiver_module_file );
	foreach ( array( 'Nihai Tüketici', 'NIHAI TUKETICI', 'Nihai Tuketici', 'Final Consumer', 'Genel Müşteri' ) as $needle ) {
		// A PHP string literal, not the word in a comment: the mapper's own
		// docblock names the title to explain why it is never produced.
		$hits = preg_match_all( '/[\'"]' . preg_quote( $needle, '/' ) . '[\'"]/u', $receiver_module_source );
		if ( $hits > 0 ) {
			$module_generic_titles[] = sprintf( '%s:%s(%d)', basename( $receiver_module_file ), $needle, $hits );
		}
	}
}

/*
 * Core keeps the checkout and billing-preference fields; the EDM module keeps
 * everything fiscal. Both trees are scanned, because a retail-customer TCKN
 * field appearing on either side would be the same defect.
 */
$checkout_files = array_merge(
	(array) glob( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/*.php' ),
	(array) glob( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/*.php' ),
	(array) glob( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/*.php' ),
	(array) glob( trailingslashit( WP_CONTENT_DIR ) . 'themes/kuka-island-child/*.php' ),
	(array) glob( trailingslashit( WP_CONTENT_DIR ) . 'themes/kuka-island-child/inc/*.php' )
);
$checkout_tckn_fields = array();
foreach ( $checkout_files as $checkout_file ) {
	if ( ! is_readable( $checkout_file ) ) {
		continue;
	}
	$checkout_source = (string) file_get_contents( $checkout_file );
	// A checkout/billing field definition asking for a TCKN.
	if ( preg_match( '/(woocommerce_checkout_fields|woocommerce_billing_fields|billing_tckn|_billing_tckn)/i', $checkout_source )
		&& preg_match( '/tckn|kimlik\s*numaras/i', $checkout_source ) ) {
		$checkout_tckn_fields[] = basename( $checkout_file );
	}
}

$report(
	'INVOICE_INDIVIDUAL_RECEIVER_FAIL_CLOSED',
	$receiver_gap_ok
	&& true === $malformed_email_refused
	&& array() === $module_generic_titles
	&& array() === $checkout_tckn_fields,
	sprintf(
		'measured:production_mapper|cases:%d|%s|malformed_email_refused_by_woocommerce:%s|generic_titles:%s|checkout_tckn_fields:%s|checkout_files_scanned:%d',
		count( $receiver_gap_cases ),
		implode( ' ', $receiver_gap_details ),
		$malformed_email_refused ? 'yes' : 'no',
		empty( $module_generic_titles ) ? 'none' : implode( ',', $module_generic_titles ),
		empty( $checkout_tckn_fields ) ? 'none' : implode( ',', $checkout_tckn_fields ),
		count( $checkout_files )
	) . sprintf( '|module_files_scanned:%d', count( $receiver_module_files ) )
);

/* ========================================================================== */
/* TEST 5 (audit item 3) - Auto-send honours the full can_send_invoice contract */
/* ========================================================================== */

$auto_send_ready = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'auto_send' => true ) ) );
$auto_gaps       = array();
$auto_all_closed = true;

// sender_postcode is deliberately absent: it is optional, and the assertion
// right after this loop proves that clearing it keeps auto-send enabled.
foreach ( array( 'sender_alias', 'series_einvoice', 'series_earchive', 'sender_title', 'sender_tax_office', 'sender_address', 'sender_district', 'sender_city', 'sender_vkn', 'username', 'password' ) as $field ) {
	$broken = new Kuka_Island_Core_Invoice_Config(
		array_merge( $ready_overrides, array( 'auto_send' => true, $field => '' ) )
	);
	$enabled = $broken->is_auto_send_enabled();
	$auto_gaps[ $field ] = $enabled ? 'ENABLED' : 'blocked';
	if ( $enabled ) {
		$auto_all_closed = false;
	}
}

// An empty sender postcode must NOT close any gate.
$no_postcode_config = new Kuka_Island_Core_Invoice_Config(
	array_merge( $ready_overrides, array( 'auto_send' => true, 'sender_postcode' => '' ) )
);

$report(
	'INVOICE_AUTO_SEND_FULL_READINESS_CONTRACT',
	true === $auto_send_ready->is_auto_send_enabled()
	&& $auto_all_closed
	&& true === $no_postcode_config->is_auto_send_enabled()
	&& true === $no_postcode_config->can_send_invoice()
	&& ! in_array( 'sender_postcode', $no_postcode_config->get_send_readiness_gaps(), true ),
	sprintf(
		'ready_enabled:%s|fields_checked:%d|leaks:%s|postcode_optional:%s',
		$auto_send_ready->is_auto_send_enabled() ? 'yes' : 'no',
		count( $auto_gaps ),
		implode( ',', array_keys( array_filter( $auto_gaps, static fn( $v ) => 'ENABLED' === $v ) ) ) ?: 'none',
		( $no_postcode_config->is_auto_send_enabled() && ! in_array( 'sender_postcode', $no_postcode_config->get_send_readiness_gaps(), true ) ) ? 'yes' : 'NO'
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
			// EDM assigns the number and returns it. INVOICE/@ID is never sent,
			// so there is nothing here to echo back.
			return array(
				'INVOICE'        => array(
					'UUID' => $parameters['INVOICE'][0]['UUID'] ?? 'uuid-123',
					'ID'   => 'EDM2026000000042',
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

$invoice_module_dir   = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/';
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
		// The UBL carries EDM's automatic-numbering sentinel; nothing local is
		// ever offered as the document number.
		'invoice_number'    => Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL,
		'invoice_serial'    => 'KUK',
		'profile_id'        => 'EARSIVFATURA',
		'invoice_type_code' => 'SATIS',
		'issue_date'        => '2026-08-31',
		'payable_amount'    => '990.00',
		'receiver_vkn'      => '11111111111',
		'receiver_alias'    => '',
		'customer_email'    => 'alici@example.com',
		'is_internet_sales' => true,
		/*
		 * The internet-sales block, in the WSDL's own sequence for the inline
		 * INTERNETSALESDETAILS complexType. Serialised below by a SoapClient
		 * built from the real EDM WSDL, so a wrong element name or a field the
		 * schema does not have would be dropped and the assertions would fail.
		 */
		'internet_sales_details' => array(
			'webAdresi'        => 'https://kukaisland.com',
			'odemeSekli'       => Kuka_Island_Core_Internet_Sales_Details::PAYMENT_INTERMEDIARY,
			'odemeAracisiAdi'  => Kuka_Island_Core_Internet_Sales_Details::AGENT_IYZICO,
			'odemeTarihi'      => '2026-08-30',
			'gonderiBilgileri' => array(
				'gonderimTarihi' => '2026-08-31',
				'gonderiTasiyan' => array(
					'tuzelKisi' => array(
						'vkn'   => '9990001111',
						'unvan' => 'TEST KARGO A.S. - GERCEK DEGIL',
					),
				),
			),
		),
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
			// e-Arşiv has no GİB mailbox: the alias attribute is omitted, not
			// sent empty.
			'//*[local-name()="RECEIVER"]/@alias'                                      => false,
			'//*[local-name()="INVOICE"]/@TRXID'                                       => '4242',
			'//*[local-name()="INVOICE"]/@UUID'                                        => 'uuid-verify-0001',
			// EDM assigns the number. @ID is never sent, and the sentinel is
			// certainly not sent here.
			'//*[local-name()="INVOICE"]/@ID'                                          => false,
			'//*[local-name()="HEADER"]/*[local-name()="INVOICESERIAL_REQUESTED"]'      => 'KUK',
			'//*[local-name()="HEADER"]/*[local-name()="EARCHIVE"]'                    => 'true',
			// e-Arşiv: TO is the buyer's e-mail address, which is how EDM
			// delivers the document.
			'//*[local-name()="HEADER"]/*[local-name()="TO"]'                          => 'alici@example.com',
			'//*[local-name()="INVOICE"]/*[local-name()="CONTENT"]'                    => true,
			// The internet-sales block, once, with its confirmed fiscal values.
			'//*[local-name()="HEADER"]/*[local-name()="INTERNETSALESDETAILS"]'        => true,
			'//*[local-name()="INTERNETSALESDETAILS"]/*[local-name()="webAdresi"]'     => 'https://kukaisland.com',
			'//*[local-name()="INTERNETSALESDETAILS"]/*[local-name()="odemeSekli"]'    => 'ODEMEARACISI',
			'//*[local-name()="INTERNETSALESDETAILS"]/*[local-name()="odemeAracisiAdi"]' => 'iyzico',
			'//*[local-name()="INTERNETSALESDETAILS"]/*[local-name()="odemeTarihi"]'   => '2026-08-30',
			'//*[local-name()="gonderiBilgileri"]/*[local-name()="gonderimTarihi"]'    => '2026-08-31',
			'//*[local-name()="gonderiTasiyan"]/*[local-name()="tuzelKisi"]/*[local-name()="vkn"]' => '9990001111',
			'//*[local-name()="gonderiTasiyan"]/*[local-name()="tuzelKisi"]/*[local-name()="unvan"]' => 'TEST KARGO A.S. - GERCEK DEGIL',
			// A natural person carrier is not what shipped this, so its branch
			// is absent rather than emitted empty.
			'//*[local-name()="gonderiTasiyan"]/*[local-name()="gercekKisi"]'          => false,
			'//*[local-name()="HEADER"]/*[local-name()="INTERNETSALES"]'               => 'true',
		)
	);

	// Exactly one block, and no imaginary *Specified companion element -- the
	// WSDL's INTERNETSALESDETAILS sequence has none.
	$isd_nodes      = $send_xp->query( '//*[local-name()="INTERNETSALESDETAILS"]' );
	$isd_node_count = false === $isd_nodes ? 0 : $isd_nodes->length;

	/*
	 * The carrier identity branch, counted on XML the real EDM WSDL produced.
	 * gonderiTasiyan carries either tuzelKisi (a legal person, vkn + unvan) or
	 * gercekKisi (a natural person, tckn + adiSoyadi). This integration models
	 * only the legal person, so exactly one tuzelKisi and no gercekKisi.
	 */
	$isd_tuzel_query  = $send_xp->query( '//*[local-name()="gonderiTasiyan"]/*[local-name()="tuzelKisi"]' );
	$isd_gercek_query = $send_xp->query( '//*[local-name()="gercekKisi"]' );
	$isd_tuzel_nodes  = false === $isd_tuzel_query ? -1 : $isd_tuzel_query->length;
	$isd_gercek_nodes = false === $isd_gercek_query ? -1 : $isd_gercek_query->length;
	$isd_specified  = array();
	// Kept for the ten-digit VKN contract test further down: these are the
	// values the real WSDL serialiser actually placed in the request.
	$GLOBALS['kuka_isd_xml_facts'] = array();
	$all_send_nodes = $send_xp->query( '//*' );
	if ( false !== $all_send_nodes ) {
		foreach ( $all_send_nodes as $send_node ) {
			if ( str_ends_with( (string) $send_node->localName, 'Specified' ) ) {
				$isd_specified[] = (string) $send_node->localName;
			}
		}
	}

	$isd_vkn_query   = $send_xp->query( '//*[local-name()="tuzelKisi"]/*[local-name()="vkn"]' );
	$isd_unvan_query = $send_xp->query( '//*[local-name()="tuzelKisi"]/*[local-name()="unvan"]' );
	$GLOBALS['kuka_isd_xml_facts'] = array(
		'vkn'          => ( false !== $isd_vkn_query && $isd_vkn_query->length > 0 ) ? trim( (string) $isd_vkn_query->item( 0 )->nodeValue ) : '',
		'unvan'        => ( false !== $isd_unvan_query && $isd_unvan_query->length > 0 ) ? trim( (string) $isd_unvan_query->item( 0 )->nodeValue ) : '',
		'tuzel_nodes'  => $isd_tuzel_nodes,
		'gercek_nodes' => $isd_gercek_nodes,
	);

	$report(
		'INVOICE_SOAP_XPATH_SEND_INVOICE_EARCHIVE',
		$send_xpath['passed']
		&& $single_base64_ok
		&& '' === $send_error
		&& 1 === $isd_node_count
		&& 1 === $isd_tuzel_nodes
		&& 0 === $isd_gercek_nodes
		&& array() === $isd_specified,
		sprintf(
			'assertions:%d|single_base64_sha256_match:%s|internetsalesdetails_nodes:%d|tuzelKisi_nodes:%d|gercekKisi_nodes:%d|specified_elements:%s|error:%s|failed:%s',
			$send_xpath['count'] + 1,
			$single_base64_ok ? 'yes' : 'no',
			$isd_node_count,
			$isd_tuzel_nodes,
			$isd_gercek_nodes,
			empty( $isd_specified ) ? 'none' : implode( ',', array_unique( $isd_specified ) ),
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
			// e-Fatura alias behaviour is unchanged: the GİB mailbox goes in
			// both RECEIVER/@alias and HEADER/TO, and the customer e-mail does
			// not displace it.
			'//*[local-name()="RECEIVER"]/@alias'                                 => 'urn:mail:defaultgb@acme.com',
			'//*[local-name()="HEADER"]/*[local-name()="TO"]'                     => 'urn:mail:defaultgb@acme.com',
			'//*[local-name()="HEADER"]/*[local-name()="PROFILEID"]'              => 'TICARIFATURA',
			'//*[local-name()="HEADER"]/*[local-name()="EARCHIVE"]'               => 'false',
			'//*[local-name()="HEADER"]/*[local-name()="INVOICESERIAL_REQUESTED"]' => 'KUK',
			'//*[local-name()="INVOICE"]/@ID'                                     => false,
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

	/* --- 7G2: why EDM's own GİB report dates are still in the request ----- */

	/*
	 * EDM technical support confirmed in writing on 3 September 2026 that
	 * EARCHIVE_REPORT_SENDDATE and CANCEL_EARCHIVE_REPORT_SENDDATE are not
	 * required in a SendInvoice request: both carry dates on which EDM itself
	 * reports the e-Arşiv document, and its cancellation, to GİB.
	 *
	 * Removing them was attempted and it is not possible. This check records
	 * WHY, by measuring the encoder rather than reading the schema and
	 * assuming: the live WSDL declares both minOccurs="1", and ext-soap
	 * enforces minOccurs at encoding time, so omitting either produces NO
	 * envelope at all -- not a rejected request, no request. Sending the
	 * document would become impossible.
	 *
	 * It runs on its own SoapClient so the production client's operation
	 * ledger is untouched, and it transmits nothing: __doRequest is overridden.
	 */
	$senddate_names = array( 'EARCHIVE_REPORT_SENDDATE', 'CANCEL_EARCHIVE_REPORT_SENDDATE' );

	$node_count = static function ( string $xml, string $local ): int {
		if ( '' === trim( $xml ) ) {
			return -1;
		}
		$dom = new DOMDocument();
		if ( ! @$dom->loadXML( $xml ) ) {
			return -1;
		}
		$nodes = ( new DOMXPath( $dom ) )->query( sprintf( '//*[local-name()="%s"]', $local ) );

		return ( false !== $nodes ) ? $nodes->length : -1;
	};

	$senddate_probe = new Kuka_Island_Test_WSDL_Interceptor(
		Kuka_Island_Core_Invoice_Config::DEFAULT_TEST_WSDL,
		array(
			'trace'        => 1,
			'exceptions'   => true,
			'cache_wsdl'   => WSDL_CACHE_MEMORY,
			'soap_version' => SOAP_1_1,
			'encoding'     => 'UTF-8',
		)
	);

	$senddate_header = array(
		'SENDER'         => '1234567890',
		'RECEIVER'       => '11111111111',
		'ISSUE_DATE'     => '2026-08-31',
		'PAYABLE_AMOUNT' => '120.00',
		'FROM'           => 'urn:mail:defaultgb@example.com',
		'TO'             => 'alici@example.com',
		'PROFILEID'      => 'EARSIVFATURA',
		'INTERNETSALES'  => false,
		'EARCHIVE'       => true,
		'INVOICE_TYPE'   => 'SATIS',
		'ISACTIVE'       => true,
		'MARKED'         => false,
	);

	$senddate_request = static function ( array $header ): array {
		return array(
			'REQUEST_HEADER' => Kuka_Island_Core_EDM_Request_Header::build(
				'verify-session',
				'SendInvoice',
				'ozelyazilim.kukaisland',
				'uuid-verify-senddate',
				'2026-08-31T00:00:00'
			),
			'SENDER'         => array( 'vkn' => '1234567890', 'alias' => 'urn:mail:defaultgb@example.com' ),
			'RECEIVER'       => array( 'vkn' => '11111111111' ),
			'INVOICE'        => array(
				array(
					'TRXID'   => 1,
					'UUID'    => 'uuid-verify-senddate',
					'HEADER'  => $header,
					'CONTENT' => base64_encode( '<Invoice/>' ),
				),
			),
		);
	};

	$senddate_attempt = static function ( Kuka_Island_Test_WSDL_Interceptor $probe, array $request ): array {
		$probe->last_request_xml = '';
		$verdict                 = 'serialised';
		$message                 = '';
		try {
			$probe->__soapCall( 'SendInvoice', array( $request ) );
		} catch ( Throwable $t ) {
			$verdict = get_class( $t );
			$message = $t->getMessage();
		}

		return array(
			'verdict' => $verdict,
			'message' => $message,
			'xml'     => $probe->last_request_xml,
		);
	};

	// (a) Omitted -- the behavioural attempt EDM's answer would authorise.
	$omit_attempt = $senddate_attempt( $senddate_probe, $senddate_request( $senddate_header ) );

	// (b) Present -- the control, proving the probe itself is sound.
	$with_attempt = $senddate_attempt(
		$senddate_probe,
		$senddate_request(
			array_merge(
				$senddate_header,
				array(
					'EARCHIVE_REPORT_SENDDATE'        => '2026-08-31',
					'CANCEL_EARCHIVE_REPORT_SENDDATE' => '2026-08-31',
				)
			)
		)
	);

	$omission_refused = 'serialised' !== $omit_attempt['verdict']
		&& '' === trim( (string) $omit_attempt['xml'] )
		&& str_contains( (string) $omit_attempt['message'], 'EARCHIVE_REPORT_SENDDATE' );

	$control_serialises = 'serialised' === $with_attempt['verdict']
		&& 1 === $node_count( (string) $with_attempt['xml'], 'EARCHIVE_REPORT_SENDDATE' )
		&& 1 === $node_count( (string) $with_attempt['xml'], 'CANCEL_EARCHIVE_REPORT_SENDDATE' );

	// The exact declarations, quoted from the WSDL rather than characterised.
	$senddate_declarations = array();
	$senddate_wsdl_dom     = new DOMDocument();
	if ( @$senddate_wsdl_dom->load( Kuka_Island_Core_Invoice_Config::DEFAULT_TEST_WSDL ) ) {
		$senddate_wsdl_xp = new DOMXPath( $senddate_wsdl_dom );
		$senddate_wsdl_xp->registerNamespace( 'xs', 'http://www.w3.org/2001/XMLSchema' );
		foreach ( $senddate_names as $decl_name ) {
			$decl = $senddate_wsdl_xp->query( sprintf( '//xs:complexType[@name="INVOICE"]//xs:element[@name="%s"]', $decl_name ) );
			if ( false !== $decl && $decl->length > 0 && $decl->item( 0 ) instanceof DOMElement ) {
				$senddate_declarations[] = sprintf(
					'%s(type=%s,minOccurs=%s)',
					$decl_name,
					$decl->item( 0 )->getAttribute( 'type' ),
					$decl->item( 0 )->hasAttribute( 'minOccurs' ) ? $decl->item( 0 )->getAttribute( 'minOccurs' ) : '1'
				);
			}
		}
	}

	/*
	 * BLOCKED, not FAIL and not PASS. Omission cannot be encoded against this
	 * WSDL; the fields are sent as 0001-01-01, the documented .NET MinValue
	 * that EDM's own request examples and connector produce for "no value".
	 * Both halves of the measurement have to hold for the verdict to be
	 * trustworthy: omission must be refused AND the control must serialise,
	 * otherwise the probe is broken rather than the schema being strict.
	 */
	/*
	 * Emitted directly rather than through $report(): nothing here is broken,
	 * so this must not fail the suite. It is a recorded impossibility, and the
	 * expectation in scripts/verify.sh pins the whole line -- so if EDM ever
	 * relaxes the WSDL and omission starts serialising, that expectation breaks
	 * and forces this to be re-measured instead of quietly staying true.
	 */
	WP_CLI::line(
		sprintf(
			'INVOICE_OUTGOING_REQUEST_OMITS_REPORT_SENDDATES=%s|%s',
			( $omission_refused && $control_serialises ) ? 'BLOCKED' : 'BLOCKED_PROBE_UNSOUND',
			sprintf(
				'measured:real_wsdl_soap_encoder|network_soap_operations:0|omission_verdict:%s|omission_envelope_produced:%s|encoder_message:%s|control_serialises:%s|control_senddate_nodes:%d,%d|wsdl_declares:%s|conflict:edm_written_answer_says_not_required_but_wsdl_says_minOccurs_1|action:fields_sent_as_0001-01-01_matching_official_request_examples|resolution:documented_dotnet_minvalue_means_no_value|probe_sound:%s',
				$omit_attempt['verdict'],
				'' === trim( (string) $omit_attempt['xml'] ) ? 'no' : 'YES',
				'' === $omit_attempt['message'] ? 'none' : $omit_attempt['message'],
				'serialised' === $with_attempt['verdict'] ? 'yes' : 'NO',
				$node_count( (string) $with_attempt['xml'], 'EARCHIVE_REPORT_SENDDATE' ),
				$node_count( (string) $with_attempt['xml'], 'CANCEL_EARCHIVE_REPORT_SENDDATE' ),
				array() === $senddate_declarations ? 'not_read' : implode( ' ', $senddate_declarations ),
				( $omission_refused && $control_serialises ) ? 'yes' : 'NO'
			)
		)
	);

	/* --- 7H: GetInvoiceStatus -------------------------------------------- */
	// The published status literal, in its published place.
	$soap_interceptor->mock_response_body = kuka_soap_envelope(
		'<GetInvoiceStatusResponse xmlns="http://tempuri.org/">'
		. '<INVOICE_STATUS TRXID="4242" UUID="uuid-verify-0001" ID="KUK2026000000042">'
		. '<HEADER><STATUS>SEND - SUCCEED</STATUS>'
		. '<STATUS_DESCRIPTION>Basariyla gonderildi</STATUS_DESCRIPTION>'
		. '<GIB_STATUS_CODE>-1</GIB_STATUS_CODE></HEADER>'
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
			// Not part of GetInvoiceStatusRequest. A same-day window used to
			// hide any document issued on another date.
			'//*[local-name()="START_DATE"]'               => false,
			'//*[local-name()="END_DATE"]'                 => false,
			'//*[local-name()="CR_START_DATE"]'            => false,
			'//*[local-name()="CR_END_DATE"]'              => false,
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

/*
 * The serial prefix is never guessed. It is chosen in the EDM portal and
 * reaches the code only through the reviewed environment configuration, so a
 * send attempted without one is fail-closed BLOCKED and never transmits.
 */
$no_series_config    = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'series_earchive' => '' ) ) );
$numbering_transport = new Kuka_Island_Test_Tracking_Transport();
$numbering_provider  = new Kuka_Island_Core_EDM_Provider( $no_series_config, $numbering_transport );
$numbering_manager   = new Kuka_Island_Core_Invoice_Manager( $no_series_config, $numbering_provider );

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

// No three-character literal is hard-coded anywhere in the module.
$series_literals = array();
foreach ( (array) ( glob( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/*.php' ) ?: array() ) as $series_file ) {
	$series_source = (string) file_get_contents( $series_file );
	if ( preg_match_all( '/(get_series_einvoice|get_series_earchive)\s*\(\s*\)\s*\?\?\s*[\'"]/', $series_source ) > 0 ) {
		$series_literals[] = basename( $series_file ) . ':series_default';
	}
	// A bare three-letter series assignment such as $series = 'KUK'.
	if ( preg_match( '/\$series[a-z_]*\s*=\s*[\'"][A-Z0-9]{3}[\'"]/', $series_source ) ) {
		$series_literals[] = basename( $series_file ) . ':series_literal';
	}
}

$report(
	'INVOICE_SERIES_FAIL_CLOSED_BLOCKED',
	Kuka_Island_Core_Invoice_Numbering::ERROR_SERIES_UNCONFIGURED === $numbering_code
	&& Kuka_Island_Core_Invoice_Status::STATUS_BLOCKED === $numbering_status
	&& 0 === ( $numbering_transport->calls['SendInvoice'] ?? 0 )
	// The readiness gate also names the gap before anything is queued.
	&& false === $no_series_config->can_send_invoice()
	&& in_array( 'series_earchive', $no_series_config->get_send_readiness_gaps(), true )
	&& array() === $series_literals,
	sprintf(
		'code:%s|status:%s|SendInvoice:%d|can_send_invoice:%s|readiness_gap:%s|hardcoded_series:%s',
		$numbering_code ?: 'none',
		$numbering_status,
		$numbering_transport->calls['SendInvoice'] ?? 0,
		$no_series_config->can_send_invoice() ? 'YES' : 'no',
		in_array( 'series_earchive', $no_series_config->get_send_readiness_gaps(), true ) ? 'series_earchive' : 'MISSING',
		empty( $series_literals ) ? 'none' : implode( ',', $series_literals )
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
	Kuka_Island_Core_Invoice_Numbering::ERROR_NUMBER_NOT_ASSIGNED === $mapper_empty_code,
	sprintf( 'code:%s', $mapper_empty_code ?: 'none' )
);

kuka_test_delete_order( $numbering_order->get_id(), $test_run_id );

/*
 * A number left behind by the removed local generator carries no EDM provenance
 * and must never be treated as a fiscal identifier. Orders 967, 973, 981 and 989
 * in this database all carry the same legacy value (KUK2026000000777), which is
 * exactly why a bare number is not trusted.
 *
 * Under the confirmed EDM contract the send no longer reads the order's number
 * at all: cbc:ID carries the automatic-numbering sentinel, INVOICE/@ID is not
 * sent, and the number recorded afterwards is the one EDM returned.
 */
$legacy_transport = new class() implements Kuka_Island_Core_SOAP_Transport_Interface {
	/** @var array<string, int> */
	public array $calls = array();
	/** @var array<string, array<string, mixed>> Last request per operation. */
	public array $requests = array();

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}

	public function call( string $operation, array $parameters ) {
		$this->calls[ $operation ]    = ( $this->calls[ $operation ] ?? 0 ) + 1;
		$this->requests[ $operation ] = $parameters;

		if ( 'Login' === $operation ) {
			return array( 'SESSION_ID' => 'session-legacy-fixture', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}

		if ( 'SendInvoice' === $operation ) {
			// EDM assigns the number and returns it. Deliberately unlike the
			// legacy value on the order.
			return array(
				'INVOICE'        => array(
					'UUID'   => $parameters['INVOICE'][0]['UUID'] ?? 'uuid-legacy',
					'ID'     => 'EDM2026000000123',
					'HEADER' => array( 'STATUS' => 'SEND - SUCCEED' ),
				),
				'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			);
		}

		return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
	}
};

$legacy_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $legacy_transport ) );
$legacy_order   = kuka_create_lock_order( $test_run_id, $billing_props, array( '_kuka_invoice_number' => 'KUK2026000000777' ) );

$legacy_error = '';
try {
	$legacy_manager->process_order( $legacy_order );
} catch ( Throwable $t ) {
	$legacy_error = get_class( $t ) . ': ' . $t->getMessage();
}
$legacy_order->read_meta_data( true );
$legacy_data    = Kuka_Island_Core_Invoice_Order_Store::get_invoice_data( $legacy_order );
$legacy_request = (array) ( $legacy_transport->requests['SendInvoice'] ?? array() );
$legacy_entry   = (array) ( $legacy_request['INVOICE'][0] ?? array() );
$legacy_ubl     = (string) ( $legacy_entry['CONTENT'] ?? '' );

$report(
	'INVOICE_NUMBERING_REJECTS_LEGACY_NUMBER',
	'' === $legacy_error
	&& 1 === ( $legacy_transport->calls['SendInvoice'] ?? 0 )
	// The legacy value is not offered to EDM in any form.
	&& ! array_key_exists( 'ID', $legacy_entry )
	&& ! str_contains( $legacy_ubl, 'KUK2026000000777' )
	// cbc:ID is the sentinel, not the legacy number.
	&& str_contains( $legacy_ubl, '<cbc:ID>' . Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL . '</cbc:ID>' )
	// And the number on the order afterwards is EDM's, with provenance.
	&& 'EDM2026000000123' === $legacy_data['invoice_number']
	&& Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM === $legacy_data['number_source'],
	sprintf(
		'measured:production_send|SendInvoice:%d|soap_invoice_id:%s|legacy_value_in_ubl:%s|ubl_cbc_id_sentinel:%s|number_after:%s|number_source:%s|error:%s',
		$legacy_transport->calls['SendInvoice'] ?? 0,
		array_key_exists( 'ID', $legacy_entry ) ? 'PRESENT' : 'absent',
		str_contains( $legacy_ubl, 'KUK2026000000777' ) ? 'YES' : 'no',
		str_contains( $legacy_ubl, '<cbc:ID>' . Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL . '</cbc:ID>' ) ? 'yes' : 'no',
		$legacy_data['invoice_number'] ?: 'none',
		$legacy_data['number_source'] ?: 'none',
		'' === $legacy_error ? 'none' : $legacy_error
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
	// The number is EDM's, from the response -- not the one seeded on the order.
	&& 'EDM2026000000042' === $happy_data['invoice_number'],
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

/* --- The deactivation gate stops an in-flight worker ---------------------- */

/*
 * Deactivating the plugin removes its hooks for the NEXT request. It cannot
 * reach a worker that is already inside process_order(), and Action Scheduler
 * workers are exactly that: long-running, already loaded, already past the
 * point where a hook would have mattered. So the module carries a persistent
 * run gate, and this proves the gate actually stops the send rather than merely
 * existing.
 *
 * Measured through the production manager with a tracking transport, so the
 * evidence is a SendInvoice count and the order's own meta -- not the presence
 * of an if statement.
 */
$gate_option_before = get_option( Kuka_Island_Core_Invoice_Runtime_Gate::OPTION, null );

$gate_setup = static function ( string $run_id ) use ( $billing_props ) {
	return kuka_create_lock_order(
		$run_id,
		$billing_props,
		array(
			'_kuka_invoice_number'        => 'KUK2026000000043',
			'_kuka_invoice_number_source' => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
		)
	);
};

// (a) Gate closed, as deactivation leaves it.
Kuka_Island_Core_Invoice_Runtime_Gate::disable();
$gate_closed_reads_disabled = Kuka_Island_Core_Invoice_Runtime_Gate::is_disabled();

$blocked_transport = new Kuka_Island_Test_Tracking_Transport();
$blocked_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $blocked_transport ) );
$blocked_order     = $gate_setup( $test_run_id );

$blocked_code  = '';
$blocked_error = '';
try {
	$blocked_manager->process_order( $blocked_order );
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	$blocked_code = $e->get_safe_error_code();
} catch ( Throwable $t ) {
	$blocked_error = get_class( $t );
}

$blocked_order->read_meta_data( true );
$blocked_data = Kuka_Island_Core_Invoice_Order_Store::get_invoice_data( $blocked_order );

/*
 * Nothing may have been written. The gate sits above mark_sending() precisely
 * so a refused transmission leaves no reserved UUID and no `sending` status --
 * residue the duplicate-protection rules would then have to reason about.
 */
$blocked_uuid_absent   = '' === (string) ( $blocked_data['uuid'] ?? '' );
$blocked_not_sending   = 'sending' !== (string) ( $blocked_data['status'] ?? '' );
$blocked_no_send_call  = 0 === ( $blocked_transport->calls['SendInvoice'] ?? 0 );

// (b) Gate open again: the same setup must reach EDM, or (a) proved nothing.
Kuka_Island_Core_Invoice_Runtime_Gate::enable();
$gate_open_reads_enabled = ! Kuka_Island_Core_Invoice_Runtime_Gate::is_disabled();

$control_transport = new Kuka_Island_Test_Tracking_Transport();
$control_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $control_transport ) );
$control_order     = $gate_setup( $test_run_id );

$control_error = '';
try {
	$control_manager->process_order( $control_order );
} catch ( Throwable $t ) {
	$control_error = get_class( $t ) . ': ' . $t->getMessage();
}
$control_sent = 1 === ( $control_transport->calls['SendInvoice'] ?? 0 );

/*
 * The gate is read past the object cache, so a value cached earlier in the same
 * request cannot let a send through. Measured by priming the cache with the
 * open state, closing the gate behind it, and reading again.
 */
get_option( Kuka_Island_Core_Invoice_Runtime_Gate::OPTION );
Kuka_Island_Core_Invoice_Runtime_Gate::disable();
$sees_change_midrequest = Kuka_Island_Core_Invoice_Runtime_Gate::is_disabled();

// Restore whatever the option was before this block ran.
if ( null === $gate_option_before ) {
	Kuka_Island_Core_Invoice_Runtime_Gate::enable();
} else {
	update_option( Kuka_Island_Core_Invoice_Runtime_Gate::OPTION, $gate_option_before, false );
}

$report(
	'EDM_DEACTIVATION_GATE_STOPS_INFLIGHT_SEND',
	$gate_closed_reads_disabled
	&& $blocked_no_send_call
	&& Kuka_Island_Core_Invoice_Manager::ERROR_RUNTIME_DISABLED === $blocked_code
	&& $blocked_uuid_absent
	&& $blocked_not_sending
	&& '' === $blocked_error
	&& $gate_open_reads_enabled
	&& $control_sent
	&& '' === $control_error
	&& $sees_change_midrequest,
	sprintf(
		'measured:production_manager_and_tracking_transport|gate_closed_SendInvoice:%d|error_code:%s|uuid_written:%s|status_after:%s|gate_open_SendInvoice:%d|sees_change_past_object_cache:%s|control_error:%s|unexpected:%s',
		$blocked_transport->calls['SendInvoice'] ?? 0,
		'' === $blocked_code ? 'none' : $blocked_code,
		$blocked_uuid_absent ? 'no' : 'YES',
		'' === (string) ( $blocked_data['status'] ?? '' ) ? 'unchanged' : (string) $blocked_data['status'],
		$control_transport->calls['SendInvoice'] ?? 0,
		$sees_change_midrequest ? 'yes' : 'NO',
		'' === $control_error ? 'none' : $control_error,
		'' === $blocked_error ? 'none' : $blocked_error
	)
);

kuka_test_delete_order( $blocked_order->get_id(), $test_run_id );
kuka_test_delete_order( $control_order->get_id(), $test_run_id );

/* --- The dependency notice names the plugin that is actually missing ------ */

/*
 * This reported 'kuka-island-edm' when Kuka_Island_Core_Plugin was the class
 * that had not loaded -- telling an administrator to activate the plugin they
 * were already looking at, while the one they needed went unnamed. A wrong
 * diagnostic is worse than none: it sends the reader somewhere else.
 *
 * The pairing is now a map, and this measures the map AND the sentence an
 * administrator would actually read.
 */
$edm_root_file = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/class-plugin.php';
if ( is_readable( $edm_root_file ) ) {
	require_once $edm_root_file;
}

$dep_map        = class_exists( 'Kuka_Island_EDM_Plugin' ) ? Kuka_Island_EDM_Plugin::dependency_map() : array();
$dep_own_slug   = class_exists( 'Kuka_Island_EDM_Plugin' ) ? Kuka_Island_EDM_Plugin::OWN_SLUG : '';
$dep_notice     = class_exists( 'Kuka_Island_EDM_Plugin' )
	? Kuka_Island_EDM_Plugin::dependency_notice_text( array( 'kuka-island-core' ) )
	: '';

// Every declared slug must be a plugin that exists on disk, and none of them
// may be this plugin itself.
$dep_unresolvable = array();
$dep_self         = array();
foreach ( $dep_map as $dep_class => $dep_slug ) {
	if ( ! is_dir( trailingslashit( WP_PLUGIN_DIR ) . $dep_slug ) ) {
		$dep_unresolvable[] = $dep_slug;
	}
	if ( $dep_slug === $dep_own_slug ) {
		$dep_self[] = $dep_class . '=>' . $dep_slug;
	}
}

$dep_ok = array(
	'WooCommerce'             => 'woocommerce',
	'Kuka_Island_Core_Plugin' => 'kuka-island-core',
) === $dep_map
	&& array() === $dep_unresolvable
	&& array() === $dep_self
	&& 'kuka-island-edm' === $dep_own_slug
	// The rendered sentence names Core, and does not name this plugin.
	&& str_contains( $dep_notice, 'kuka-island-core' )
	&& ! str_contains( $dep_notice, 'kuka-island-edm' );

$report(
	'EDM_DEPENDENCY_NOTICE_NAMES_THE_MISSING_PLUGIN',
	$dep_ok,
	sprintf(
		'measured:dependency_map_and_rendered_notice|pairs:%s|own_slug:%s|self_dependency:%s|slugs_without_plugin_dir:%s|notice_names_core:%s|notice_names_self:%s',
		array() === $dep_map
			? 'none'
			: implode( ' ', array_map( static fn( $k, $v ) => $k . '=>' . $v, array_keys( $dep_map ), $dep_map ) ),
		'' === $dep_own_slug ? 'ABSENT' : $dep_own_slug,
		array() === $dep_self ? 'none' : implode( ',', $dep_self ),
		array() === $dep_unresolvable ? 'none' : implode( ',', $dep_unresolvable ),
		str_contains( $dep_notice, 'kuka-island-core' ) ? 'yes' : 'NO',
		str_contains( $dep_notice, 'kuka-island-edm' ) ? 'YES' : 'no'
	)
);

/* --- The module loads from the EDM plugin, not from Core ------------------ */

$report(
	'EDM_MODULE_LOADS_FROM_EDM_PLUGIN',
	true === ( $kuka_edm_module['ok'] ?? false )
	&& str_contains( (string) ( $kuka_edm_module['path'] ?? '' ), '/kuka-island-edm/' )
	&& 24 === (int) ( $kuka_edm_module['classes'] ?? 0 )
	// Core must not carry the module any more, in either direction.
	&& ! is_readable( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/class-invoice.php' )
	&& ! is_dir( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/invoice' ),
	sprintf(
		'measured:runtime_require|loaded:%s|reason:%s|files:%d|core_still_has_class_invoice:%s|core_still_has_invoice_dir:%s',
		true === ( $kuka_edm_module['ok'] ?? false ) ? 'yes' : 'NO',
		(string) ( $kuka_edm_module['reason'] ?? 'unknown' ),
		(int) ( $kuka_edm_module['classes'] ?? 0 ),
		is_readable( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/class-invoice.php' ) ? 'YES' : 'no',
		is_dir( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-core/includes/invoice' ) ? 'YES' : 'no'
	)
);

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
/* Supplier postcode is optional: emitted when known, omitted when not         */
/* ========================================================================== */

/*
 * Ground truth: EDM's own XML ÖRNEKLERİ package ships sixteen sample invoices
 * (satis_temel.xml among them) and NONE of them carries cbc:PostalZone inside
 * the supplier cac:PostalAddress. The EDM test portal
 * (Tanımlar -> Firmalarım -> Görüntüle/Güncelle) has no postcode field either,
 * so the value cannot be sourced without inventing it. It is therefore optional
 * everywhere, and an absent one must omit the element rather than emit an empty
 * node -- an empty cbc:PostalZone is a schema violation, not a neutral blank.
 */
$postcode_supplier_path = '/*[local-name()="Invoice"]/*[local-name()="AccountingSupplierParty"]/*[local-name()="Party"]/*[local-name()="PostalAddress"]';

/**
 * Build the UBL and return an XPath over it.
 *
 * @param array<string, mixed> $data Builder payload.
 */
$postcode_xpath = static function ( array $data ): DOMXPath {
	$dom = new DOMDocument();
	$dom->loadXML( ( new Kuka_Island_Core_UBL_TR_Builder( $data ) )->build_xml() );

	return new DOMXPath( $dom );
};

// Case A: postcode supplied -> cbc:PostalZone present, value byte-for-byte.
$with_postcode                          = $builder_base;
$with_postcode['supplier']['postcode']  = '34381';
$xp_with                                = $postcode_xpath( $with_postcode );
$zone_with                              = $xp_with->query( $postcode_supplier_path . '/*[local-name()="PostalZone"]' );
$zone_with_value                        = ( false !== $zone_with && $zone_with->length > 0 ) ? trim( (string) $zone_with->item( 0 )->nodeValue ) : '';

// Case B: postcode empty -> the element is absent entirely, not empty.
$without_postcode                         = $builder_base;
$without_postcode['supplier']['postcode'] = '';
$xp_without                               = $postcode_xpath( $without_postcode );
$zone_without                             = $xp_without->query( $postcode_supplier_path . '/*[local-name()="PostalZone"]' );
$zone_without_count                       = ( false !== $zone_without ) ? $zone_without->length : -1;

// The rest of the supplier block must be intact in case B.
$required_supplier_nodes = array(
	'street'    => $postcode_supplier_path . '/*[local-name()="StreetName"]',
	'district'  => $postcode_supplier_path . '/*[local-name()="CitySubdivisionName"]',
	'city'      => $postcode_supplier_path . '/*[local-name()="CityName"]',
	'country'   => $postcode_supplier_path . '/*[local-name()="Country"]/*[local-name()="Name"]',
	'vkn'       => '/*[local-name()="Invoice"]/*[local-name()="AccountingSupplierParty"]/*[local-name()="Party"]/*[local-name()="PartyIdentification"]/*[local-name()="ID"]',
	'name'      => '/*[local-name()="Invoice"]/*[local-name()="AccountingSupplierParty"]/*[local-name()="Party"]/*[local-name()="PartyName"]/*[local-name()="Name"]',
	'tax_office' => '/*[local-name()="Invoice"]/*[local-name()="AccountingSupplierParty"]/*[local-name()="Party"]/*[local-name()="PartyTaxScheme"]/*[local-name()="TaxScheme"]/*[local-name()="Name"]',
);
$supplier_missing = array();
foreach ( $required_supplier_nodes as $label => $query ) {
	$found = $xp_without->query( $query );
	if ( false === $found || 0 === $found->length || '' === trim( (string) $found->item( 0 )->nodeValue ) ) {
		$supplier_missing[] = $label;
	}
}

// The customer postcode contract is untouched by this change.
$customer_zone = $xp_without->query( '/*[local-name()="Invoice"]/*[local-name()="AccountingCustomerParty"]/*[local-name()="Party"]/*[local-name()="PostalAddress"]/*[local-name()="PostalZone"]' );

$report(
	'INVOICE_SUPPLIER_POSTCODE_OPTIONAL',
	'34381' === $zone_with_value
	&& 0 === $zone_without_count
	&& array() === $supplier_missing
	// Unchanged: the customer address still emits its PostalZone element.
	&& false !== $customer_zone && 1 === $customer_zone->length,
	sprintf(
		'with_postcode:present|value_roundtrip:%s|without_postcode:%s|empty_node_emitted:%s|supplier_fields_missing:%s|customer_postal_zone:%s',
		'34381' === $zone_with_value ? 'exact' : 'MISMATCH',
		0 === $zone_without_count ? 'omitted' : 'PRESENT',
		0 === $zone_without_count ? 'no' : 'YES',
		empty( $supplier_missing ) ? 'none' : implode( ',', $supplier_missing ),
		( false !== $customer_zone && 1 === $customer_zone->length ) ? 'unchanged' : 'CHANGED'
	)
);

// The mapper must no longer refuse a configuration whose only gap is the
// postcode, and must still refuse one that is missing a genuinely required
// field.
$mapper_postcode_code = 'NO_EXCEPTION';
$mapper_city_code     = 'NO_EXCEPTION';
$mapper_reflection    = new ReflectionMethod( Kuka_Island_Core_Invoice_Order_Mapper::class, 'get_supplier_data' );
$mapper_reflection->setAccessible( true );

try {
	$supplier_no_postcode = (array) $mapper_reflection->invoke(
		new Kuka_Island_Core_Invoice_Order_Mapper( new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'sender_postcode' => '' ) ) ) )
	);
} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
	$mapper_postcode_code = $e->get_safe_error_code();
	$supplier_no_postcode = array();
}

try {
	$mapper_reflection->invoke(
		new Kuka_Island_Core_Invoice_Order_Mapper( new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'sender_city' => '' ) ) ) )
	);
} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
	$mapper_city_code = $e->get_safe_error_code();
}

$report(
	'INVOICE_MAPPER_POSTCODE_OPTIONAL',
	'NO_EXCEPTION' === $mapper_postcode_code
	&& '' === (string) ( $supplier_no_postcode['postcode'] ?? 'unset' )
	&& '1234567890' === (string) ( $supplier_no_postcode['vkn'] ?? '' )
	// A genuinely required field still fails closed.
	&& 'missing_supplier_configuration' === $mapper_city_code,
	sprintf(
		'missing_postcode:%s|postcode_value:empty|missing_city:%s',
		'NO_EXCEPTION' === $mapper_postcode_code ? 'accepted' : $mapper_postcode_code,
		$mapper_city_code
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
/* EDM document status contract, polling and internet-sales details            */
/* ========================================================================== */

/**
 * Transport that answers SendInvoice / GetInvoiceStatus from a fixture and
 * counts every operation, so "the poller never sends" is measured rather than
 * asserted.
 */
final class Kuka_Island_Test_Status_Transport implements Kuka_Island_Core_SOAP_Transport_Interface {
	/** @var array<string, int> */
	public array $calls = array();
	/** @var array<string, array<string, mixed>> Last request per operation. */
	public array $requests = array();
	/** @var mixed */
	public $send_response;
	/** @var mixed */
	public $status_response;

	public function __construct( $send_response = null, $status_response = null ) {
		$this->send_response   = $send_response;
		$this->status_response = $status_response;
	}

	public function call( string $action, array $parameters ) {
		$this->calls[ $action ]    = ( $this->calls[ $action ] ?? 0 ) + 1;
		$this->requests[ $action ] = $parameters;

		if ( 'Login' === $action ) {
			return array( 'SESSION_ID' => 'session-status-fixture', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}
		if ( 'SendInvoice' === $action ) {
			return $this->send_response;
		}
		if ( 'GetInvoiceStatus' === $action ) {
			return $this->status_response;
		}

		return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
	}

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}
}

/**
 * Build a SendInvoiceResponse with the status in its published place.
 *
 * @param int|null $return_code REQUEST_RETURN.RETURN_CODE, or null to omit.
 * @param string   $status      INVOICE > HEADER > STATUS, or '' to omit.
 * @param bool     $nested      Put the status in HEADER (true) or at the
 *                              INVOICE root (false) to prove the root is not read.
 */
$build_send_response = static function ( ?int $return_code, string $status, bool $nested = true ): array {
	$invoice = array(
		'UUID' => 'uuid-status-fixture',
		'ID'   => 'KUK2026000000900',
	);
	if ( '' !== $status ) {
		if ( $nested ) {
			$invoice['HEADER'] = array(
				'STATUS'             => $status,
				'STATUS_DESCRIPTION' => 'fixture description',
			);
		} else {
			$invoice['STATUS'] = $status;
		}
	}
	$response = array( 'INVOICE' => array( $invoice ) );
	if ( null !== $return_code ) {
		$response['REQUEST_RETURN'] = array( 'RETURN_CODE' => $return_code );
	}

	return $response;
};

$send_status_of = static function ( array $response ) use ( $config ): Kuka_Island_Core_Invoice_Result {
	$transport = new Kuka_Island_Test_Status_Transport( $response, null );
	$client    = new Kuka_Island_Core_EDM_Client( $config, $transport );
	$client->login();
	$method = new ReflectionMethod( Kuka_Island_Core_EDM_Client::class, 'parse_send_invoice_response' );
	$method->setAccessible( true );

	return $method->invoke( $client, $response, 'uuid-status-fixture', 'KUK2026000000900' );
};

// RETURN_CODE = 0 means the CALL was accepted; it says nothing about the
// document. None of these may come back completed.
$send_cases = array(
	'rc0_package_processing' => array( $build_send_response( 0, 'PACKAGE - PROCESSING' ), Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL ),
	'rc0_no_status'          => array( $build_send_response( 0, '' ), Kuka_Island_Core_Invoice_Status::STATUS_SENT ),
	'rc0_send_succeed'       => array( $build_send_response( 0, 'SEND - SUCCEED' ), Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED ),
	'rc0_send_processing'    => array( $build_send_response( 0, 'SEND - PROCESSING' ), Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL ),
	'rc0_rejected'           => array( $build_send_response( 0, 'REJECTED - SUCCEED' ), Kuka_Island_Core_Invoice_Status::STATUS_REJECTED ),
	'rc0_cancelled'          => array( $build_send_response( 0, 'CANCELLED - SUCCEED' ), Kuka_Island_Core_Invoice_Status::STATUS_CANCELLED ),
	'rc0_package_fail'       => array( $build_send_response( 0, 'PACKAGE - FAIL' ), Kuka_Island_Core_Invoice_Status::STATUS_FAILED ),
	'rc0_unknown_status'     => array( $build_send_response( 0, 'TOTALLY - MADE_UP' ), Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW ),
	// A status at the INVOICE root is NOT the contract location: it must be
	// ignored, leaving the document undescribed rather than complete.
	'root_status_ignored'    => array( $build_send_response( 0, 'SEND - SUCCEED', false ), Kuka_Island_Core_Invoice_Status::STATUS_SENT ),
	'no_return_code'         => array( $build_send_response( null, 'PACKAGE - PROCESSING' ), Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL ),
);

$send_ok      = true;
$send_details = array();
foreach ( $send_cases as $case => $spec ) {
	$actual = $send_status_of( $spec[0] )->get_status();
	$hit    = $actual === $spec[1];
	$send_details[] = $case . '=' . $actual;
	if ( ! $hit ) {
		$send_ok = false;
		$send_details[ array_key_last( $send_details ) ] = $case . '=WRONG(' . $actual . ' expected ' . $spec[1] . ')';
	}
}

$report(
	'INVOICE_SEND_RESPONSE_STATUS_CONTRACT',
	$send_ok,
	sprintf( 'cases:%d|%s', count( $send_cases ), implode( ' ', $send_details ) )
);

// Whitespace-normalised exact matching. A description containing "RED" is not a
// rejection, and a padded literal still matches.
$classify_cases = array(
	'padded_send_succeed'   => array( "  SEND -   SUCCEED \n", Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED, true ),
	'lowercase_literal'     => array( 'send - succeed', Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED, true ),
	'accepted_succeed'      => array( 'ACCEPTED - SUCCEED', Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED, true ),
	'wait_gib'              => array( 'SEND - WAIT_GIB_RESPONSE', Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, true ),
	'wait_system'           => array( 'SEND - WAIT_SYSTEM_RESPONSE', Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, true ),
	'wait_application'      => array( 'SEND - WAIT_APPLICATION_RESPONSE', Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, true ),
	'unknown_unknown'       => array( 'UNKNOWN - UNKNOWN', Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, true ),
	'send_failed'           => array( 'SEND - FAILED', Kuka_Island_Core_Invoice_Status::STATUS_FAILED, true ),
	// Substring traps that used to fire.
	'unrelated_red_text'    => array( 'BELGE REDDEDILMEDI', Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW, false ),
	'unrelated_success'     => array( 'PROCESS SUCCESS NOTE', Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW, false ),
	'bare_zero'             => array( '0', Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW, false ),
	'kabul_text'            => array( 'KABUL EDILDI', Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW, false ),
);

$classify_ok      = true;
$classify_details = array();
foreach ( $classify_cases as $case => $spec ) {
	$classified = Kuka_Island_Core_EDM_Document_Status::classify( $spec[0] );
	$lifecycle  = Kuka_Island_Core_EDM_Document_Status::resolve_lifecycle( $classified );
	$hit        = $lifecycle === $spec[1] && $classified['known'] === $spec[2];
	$classify_details[] = $case . '=' . $lifecycle . ( $classified['known'] ? '/known' : '/unknown' );
	if ( ! $hit ) {
		$classify_ok = false;
	}
}

$report(
	'INVOICE_EDM_STATUS_EXACT_MATCH',
	$classify_ok,
	sprintf( 'cases:%d|%s', count( $classify_cases ), implode( ' ', $classify_details ) )
);

/*
 * GetInvoiceStatus: the request contract, and GIB_STATUS_CODE never standing in
 * for STATUS. The request is read off the mocked transport, so it is the actual
 * PHP array the client would hand SoapClient.
 */
$status_response_earchive = array(
	'INVOICE_STATUS' => array(
		array(
			'UUID'   => 'uuid-status-fixture',
			'ID'     => 'KUK2026000000900',
			'HEADER' => array(
				'STATUS'                 => 'SEND - SUCCEED',
				'STATUS_DESCRIPTION'     => 'Basariyla gonderildi',
				// Normal for e-Archive: not routed through the GİB e-Invoice
				// channel. It must not mask the SEND - SUCCEED above.
				'GIB_STATUS_CODE'        => '-1',
				'RESPONSE_CODE'          => '200',
				'EARCHIVE_REPORT_STATUS' => 'NOT_REPORTED',
			),
		),
	),
);

$status_transport = new Kuka_Island_Test_Status_Transport( null, $status_response_earchive );
$status_client    = new Kuka_Island_Core_EDM_Client( $config, $status_transport );
$status_result    = $status_client->get_invoice_status( 'uuid-status-fixture', 'KUK2026000000900' );
$status_request   = $status_transport->requests['GetInvoiceStatus'] ?? array();
$status_raw       = $status_result->get_raw_data();

$forbidden_date_fields = array_values(
	array_filter(
		array( 'START_DATE', 'END_DATE', 'CR_START_DATE', 'CR_END_DATE' ),
		static fn( string $field ): bool => array_key_exists( $field, $status_request )
	)
);

$report(
	'INVOICE_GET_STATUS_REQUEST_CONTRACT',
	array() === $forbidden_date_fields
	&& array( 'REQUEST_HEADER', 'INVOICE' ) === array_keys( $status_request )
	&& 'uuid-status-fixture' === ( $status_request['INVOICE']['UUID'] ?? '' )
	&& 'KUK2026000000900' === ( $status_request['INVOICE']['ID'] ?? '' )
	&& 1 === ( $status_transport->calls['GetInvoiceStatus'] ?? 0 ),
	sprintf(
		'measured:mock_transport_request|top_level_keys:%s|date_fields:%s|calls:%d',
		implode( ',', array_keys( $status_request ) ),
		empty( $forbidden_date_fields ) ? 'none' : implode( ',', $forbidden_date_fields ),
		$status_transport->calls['GetInvoiceStatus'] ?? 0
	)
);

$report(
	'INVOICE_EARCHIVE_GIB_MINUS_ONE_IS_SUCCESS',
	Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED === $status_result->get_status()
	&& 'SEND - SUCCEED' === ( $status_raw['status'] ?? '' )
	// Recorded, but as their own fields.
	&& '-1' === ( $status_raw['gib_status_code'] ?? '' )
	&& '200' === ( $status_raw['response_code'] ?? '' )
	&& 'NOT_REPORTED' === ( $status_raw['earchive_report_status'] ?? '' ),
	sprintf(
		'lifecycle:%s|status:%s|gib_status_code:%s|response_code:%s|earchive_report_status:%s|report_blocks_success:%s',
		$status_result->get_status(),
		(string) ( $status_raw['status'] ?? '' ),
		(string) ( $status_raw['gib_status_code'] ?? '' ),
		(string) ( $status_raw['response_code'] ?? '' ),
		(string) ( $status_raw['earchive_report_status'] ?? '' ),
		Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED === $status_result->get_status() ? 'no' : 'YES'
	)
);

// Commercial accept/reject/cancel are three different answers.
$terminal_cases = array(
	'accepted'  => array( 'ACCEPTED - SUCCEED', Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED, true ),
	'rejected'  => array( 'REJECTED - SUCCEED', Kuka_Island_Core_Invoice_Status::STATUS_REJECTED, false ),
	'cancelled' => array( 'CANCELLED - SUCCEED', Kuka_Island_Core_Invoice_Status::STATUS_CANCELLED, false ),
);
$terminal_ok      = true;
$terminal_details = array();
foreach ( $terminal_cases as $case => $spec ) {
	$transport = new Kuka_Island_Test_Status_Transport(
		null,
		// An EDM-assigned ID is present, so a positive status may complete. The
		// number-less case has its own test below.
		array( 'INVOICE_STATUS' => array( array( 'UUID' => 'u', 'ID' => 'EDM2026000000900', 'HEADER' => array( 'STATUS' => $spec[0] ) ) ) )
	);
	$client   = new Kuka_Island_Core_EDM_Client( $config, $transport );
	$lifecyc  = $client->get_invoice_status( 'u' )->get_status();
	$hit      = $lifecyc === $spec[1]
		&& Kuka_Island_Core_Invoice_Status::is_terminal( $lifecyc )
		&& Kuka_Island_Core_Invoice_Status::is_successful( $lifecyc ) === $spec[2];
	$terminal_details[] = $case . '=' . $lifecyc . ( Kuka_Island_Core_Invoice_Status::is_successful( $lifecyc ) ? '/successful' : '/not_successful' );
	if ( ! $hit ) {
		$terminal_ok = false;
	}
}

$report(
	'INVOICE_TERMINAL_STATUS_SEPARATION',
	$terminal_ok,
	sprintf( 'cases:%d|%s', count( $terminal_cases ), implode( ' ', $terminal_details ) )
);

/* -------------------------------------------------------------------------- */
/* Polling lifecycle                                                           */
/* -------------------------------------------------------------------------- */

$poll_cases = array(
	'pending_reschedules'   => array( Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, 0, 0, 'reschedule' ),
	'sent_reschedules'      => array( Kuka_Island_Core_Invoice_Status::STATUS_SENT, 1, 600, 'reschedule' ),
	'uncertain_reschedules' => array( Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN, 2, 900, 'reschedule' ),
	'completed_stops'       => array( Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED, 1, 600, 'stop' ),
	'rejected_stops'        => array( Kuka_Island_Core_Invoice_Status::STATUS_REJECTED, 1, 600, 'stop' ),
	'cancelled_stops'       => array( Kuka_Island_Core_Invoice_Status::STATUS_CANCELLED, 1, 600, 'stop' ),
	'failed_stops'          => array( Kuka_Island_Core_Invoice_Status::STATUS_FAILED, 1, 600, 'stop' ),
	'attempt_cap'           => array( Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, Kuka_Island_Core_Invoice_Status_Poller::MAX_ATTEMPTS, 600, 'give_up' ),
	'elapsed_cap'           => array( Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, 1, Kuka_Island_Core_Invoice_Status_Poller::MAX_ELAPSED, 'give_up' ),
);
$poll_ok      = true;
$poll_details = array();
foreach ( $poll_cases as $case => $spec ) {
	$decision = Kuka_Island_Core_Invoice_Status_Poller::decide( $spec[0], $spec[1], $spec[2] );
	$hit      = $decision['action'] === $spec[3];
	$poll_details[] = $case . '=' . $decision['action'];
	if ( ! $hit ) {
		$poll_ok = false;
	}
}
// The delay is bounded at both ends.
$delays_bounded = true;
for ( $attempt = 0; $attempt <= 40; $attempt++ ) {
	$delay = Kuka_Island_Core_Invoice_Status_Poller::delay_for_attempt( $attempt );
	if ( $delay < Kuka_Island_Core_Invoice_Status_Poller::MIN_DELAY || $delay > Kuka_Island_Core_Invoice_Status_Poller::MAX_DELAY ) {
		$delays_bounded = false;
	}
}

$report(
	'INVOICE_STATUS_POLL_LIFECYCLE',
	$poll_ok && $delays_bounded,
	sprintf(
		'cases:%d|%s|delay_bounded:%s|max_attempts:%d|max_elapsed:%d',
		count( $poll_cases ),
		implode( ' ', $poll_details ),
		$delays_bounded ? 'yes' : 'no',
		Kuka_Island_Core_Invoice_Status_Poller::MAX_ATTEMPTS,
		Kuka_Island_Core_Invoice_Status_Poller::MAX_ELAPSED
	)
);

/*
 * The poller must never send. Driven through the real poll path with a counting
 * transport and a real order fixture, then the operation counts are read off
 * the transport.
 */
$poll_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'status' => 'processing' ) ) );
kuka_add_line( $poll_order, 'Poll Fixture', '100.00', '100.00', 1, '10.00' );
kuka_add_tax_rate( $poll_order, 1, 10, '10.00' );
$poll_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_UUID, 'uuid-poll-fixture' );
$poll_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, 'KUK2026000000901' );
$poll_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_SENT );
$poll_order->save();

$poll_transport = new Kuka_Island_Test_Status_Transport(
	null,
	array( 'INVOICE_STATUS' => array( array( 'UUID' => 'uuid-poll-fixture', 'HEADER' => array( 'STATUS' => 'SEND - SUCCEED' ) ) ) )
);
$poll_provider = new Kuka_Island_Core_EDM_Provider( $config, $poll_transport );
$poll_manager  = new Kuka_Island_Core_Invoice_Manager( $config, $poll_provider );
$poller        = new Kuka_Island_Core_Invoice_Status_Poller( $poll_manager );

$poller->poll_order( $poll_order->get_id() );

$reloaded_poll_order = wc_get_order( $poll_order->get_id() );
$poll_send_calls     = (int) ( $poll_transport->calls['SendInvoice'] ?? 0 );
$poll_load_calls     = (int) ( $poll_transport->calls['LoadInvoice'] ?? 0 );
$poll_status_calls   = (int) ( $poll_transport->calls['GetInvoiceStatus'] ?? 0 );

$report(
	'INVOICE_POLLER_NEVER_SENDS',
	0 === $poll_send_calls
	&& 0 === $poll_load_calls
	&& 1 === $poll_status_calls
	&& Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED === (string) $reloaded_poll_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, true )
	&& 'SEND - SUCCEED' === (string) $reloaded_poll_order->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_LAST_EDM_STATUS, true ),
	sprintf(
		'measured:mock_transport|SendInvoice=%d|LoadInvoice=%d|GetInvoiceStatus=%d|order_status:%s|recorded_edm_status:%s',
		$poll_send_calls,
		$poll_load_calls,
		$poll_status_calls,
		(string) $reloaded_poll_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, true ),
		(string) $reloaded_poll_order->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_LAST_EDM_STATUS, true )
	)
);

kuka_test_delete_order( $poll_order->get_id(), $test_run_id );

// Duplicate scheduling: the second request for the same order must not create a
// second action, and must say so as 'already_pending' rather than as a bare
// refusal that reads the same as a scheduler failure.
$dup_order_id   = 999000001;
Kuka_Island_Core_Invoice_Status_Poller::unschedule( $dup_order_id );
$dup_first      = Kuka_Island_Core_Invoice_Status_Poller::schedule_query( $dup_order_id, 300 );
$dup_second     = Kuka_Island_Core_Invoice_Status_Poller::schedule_query( $dup_order_id, 300 );
$dup_as_present = function_exists( 'as_schedule_single_action' );
Kuka_Island_Core_Invoice_Status_Poller::unschedule( $dup_order_id );

$dup_expected_first  = $dup_as_present
	? Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_CREATED
	: Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_SCHEDULER_UNAVAILABLE;
$dup_expected_second = $dup_as_present
	? Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_ALREADY_PENDING
	: Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_SCHEDULER_UNAVAILABLE;

$report(
	'INVOICE_POLL_NO_DUPLICATE_SCHEDULE',
	$dup_expected_first === $dup_first
	&& $dup_expected_second === $dup_second
	// already_pending is a success; scheduler_unavailable and schedule_failed
	// are not. That distinction is the whole point of the outcome codes.
	&& '' === Kuka_Island_Core_Invoice_Status_Poller::error_code_for( Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_ALREADY_PENDING )
	&& '' === Kuka_Island_Core_Invoice_Status_Poller::error_code_for( Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_CREATED )
	&& Kuka_Island_Core_Invoice_Status_Poller::ERROR_SCHEDULER_UNAVAILABLE === Kuka_Island_Core_Invoice_Status_Poller::error_code_for( Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_SCHEDULER_UNAVAILABLE )
	&& Kuka_Island_Core_Invoice_Status_Poller::ERROR_SCHEDULE_FAILED === Kuka_Island_Core_Invoice_Status_Poller::error_code_for( Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_FAILED )
	&& Kuka_Island_Core_Invoice_Status_Poller::ERROR_LOCK_WITHOUT_PENDING === Kuka_Island_Core_Invoice_Status_Poller::error_code_for( Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_LOCK_CONTENDED )
	&& Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS !== Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE,
	sprintf(
		'action_scheduler:%s|first:%s|second:%s|success_outcomes:created,already_pending|distinct_from_send_action:%s',
		$dup_as_present ? 'present' : 'absent',
		$dup_first,
		$dup_second,
		Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS !== Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE ? 'yes' : 'no'
	)
);

// unschedule() cancels rows rather than removing them, and this scenario uses a
// synthetic order ID that no fixture cleanup covers. Purge it so repeated verify
// runs do not accumulate rows in the Action Scheduler table.
kuka_purge_queue_scheduling( array( $dup_order_id ) );

/* -------------------------------------------------------------------------- */
/* The poller starts by itself, and keeps going, on the real runner            */
/* -------------------------------------------------------------------------- */

/**
 * Count the status queries still WAITING for one order.
 *
 * Pending only. A running action is not a booking; it is the query currently
 * happening, which is exactly the distinction the scheduler got wrong.
 */
$poll_pending_ids = static function ( int $order_id ): array {
	if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
		return array();
	}

	return array_map(
		'intval',
		(array) as_get_scheduled_actions(
			array(
				'hook'     => Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS,
				'args'     => array( 'order_id' => $order_id ),
				'group'    => Kuka_Island_Core_Invoice_Status_Poller::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 50,
				'orderby'  => 'none',
			),
			'ids'
		)
	);
};

/*
 * A send has to start the poll on its own. Everything below runs through the
 * production Kuka_Island_Core_Invoice_Manager::process_order() -- the single
 * method both Kuka_Island_Core_Invoice_Queue::process_queued_order() and the
 * order screen's manual send call -- and the booking is then read out of Action
 * Scheduler, not out of the poller.
 */
$autostart_cases = array(
	// name => [ force, send response or null for the tracking transport,
	//           timeout on send, expected lifecycle, queries expected ]
	'queue_worker'    => array( false, null, false, Kuka_Island_Core_Invoice_Status::STATUS_SENT, 1 ),
	'manual_send'     => array( true, null, false, Kuka_Island_Core_Invoice_Status::STATUS_SENT, 1 ),
	// Ambiguous network error: ask, never resend.
	'send_uncertain'  => array( false, null, true, Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN, 1 ),
	// Terminal answers book nothing.
	'completed'       => array( false, $build_send_response( 0, 'SEND - SUCCEED' ), false, Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED, 0 ),
	'rejected'        => array( false, $build_send_response( 0, 'REJECTED - SUCCEED' ), false, Kuka_Island_Core_Invoice_Status::STATUS_REJECTED, 0 ),
	'cancelled'       => array( false, $build_send_response( 0, 'CANCELLED - SUCCEED' ), false, Kuka_Island_Core_Invoice_Status::STATUS_CANCELLED, 0 ),
	'failed'          => array( false, $build_send_response( 0, 'SEND - FAILED' ), false, Kuka_Island_Core_Invoice_Status::STATUS_FAILED, 0 ),
	'unknown_status'  => array( false, $build_send_response( 0, 'PROCESS SUCCESS NOTE' ), false, Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW, 0 ),
);

$autostart_ok      = true;
$autostart_details = array();
$autostart_sends   = 0;
$autostart_loads   = 0;
$autostart_queries = 0;

foreach ( $autostart_cases as $case => $spec ) {
	if ( null === $spec[1] ) {
		$transport = new Kuka_Island_Test_Tracking_Transport();
		$transport->simulate_timeout_on_send = (bool) $spec[2];
	} else {
		$transport = new Kuka_Island_Test_Status_Transport( $spec[1], null );
	}

	$case_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $transport ) );
	$case_order   = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array(
			'_kuka_invoice_number'        => 'KUK2026000000042',
			'_kuka_invoice_number_source' => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
		)
	);
	$case_order_id = (int) $case_order->get_id();

	// Nothing booked before the send.
	$booked_before = count( $poll_pending_ids( $case_order_id ) );

	try {
		$case_manager->process_order( $case_order, (bool) $spec[0] );
	} catch ( Throwable $t ) {
		// send_uncertain rethrows on purpose; the booking still has to exist.
		unset( $t );
	}

	$case_order->read_meta_data( true );
	$booked_after  = count( $poll_pending_ids( $case_order_id ) );
	$case_status   = Kuka_Island_Core_Invoice_Order_Store::get_status( $case_order );
	$poll_started  = (string) $case_order->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_POLL_STARTED_AT, true );

	$autostart_sends   += (int) ( $transport->calls['SendInvoice'] ?? 0 );
	$autostart_loads   += (int) ( $transport->calls['LoadInvoice'] ?? 0 );
	$autostart_queries += (int) ( $transport->calls['GetInvoiceStatus'] ?? 0 );

	$hit = 0 === $booked_before
		&& $booked_after === (int) $spec[4]
		&& $case_status === (string) $spec[3]
		// Exactly one transmission per case, whatever the answer was.
		&& 1 === (int) ( $transport->calls['SendInvoice'] ?? 0 )
		// A booked poll also records when polling began; an unbooked one does not.
		&& ( 0 === (int) $spec[4] ? '' === $poll_started : '' !== $poll_started );

	$autostart_details[] = $case . '=' . $case_status . '/' . $booked_after;
	if ( ! $hit ) {
		$autostart_ok = false;
	}

	kuka_test_delete_order( $case_order_id, $test_run_id );
}

// Both entry points reach the same method, which is why they cannot diverge.
$queue_source = (string) file_get_contents( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/class-invoice-queue.php' );
$admin_source = (string) file_get_contents( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/class-invoice-admin.php' );
$shared_entry = str_contains( $queue_source, '->process_order(' ) && str_contains( $admin_source, '->process_order(' );

$report(
	'INVOICE_POLLER_AUTOSTARTS_FROM_SEND',
	$autostart_ok
	&& $shared_entry
	// The send path never reaches a status query, and never a second document.
	&& 0 === $autostart_queries
	&& 0 === $autostart_loads
	&& count( $autostart_cases ) === $autostart_sends,
	sprintf(
		'measured:manager_process_order|cases:%d|%s|SendInvoice=%d|LoadInvoice=%d|GetInvoiceStatus=%d|shared_entry_point:%s',
		count( $autostart_cases ),
		implode( ' ', $autostart_details ),
		$autostart_sends,
		$autostart_loads,
		$autostart_queries,
		$shared_entry ? 'process_order' : 'DIVERGENT'
	)
);

/*
 * The follow-up query, proved on the real Action Scheduler runner.
 *
 * as_has_scheduled_action() counts pending AND running actions, so the previous
 * scheduler could not book a follow-up from inside its own callback: the action
 * running at that moment made every schedule() call look like a duplicate. The
 * only way to show the fix is to let a real action reach STATUS_RUNNING and
 * observe what it manages to book.
 *
 * ActionScheduler_Abstract_QueueRunner::process_action() runs one action through
 * the real lifecycle -- pending -> running -> complete -- and nothing else in
 * the queue is touched.
 */
$runner_available = class_exists( 'ActionScheduler_QueueRunner' )
	&& class_exists( 'ActionScheduler_Store' )
	&& class_exists( 'ActionScheduler' );

if ( ! $runner_available ) {
	$report( 'INVOICE_POLL_FOLLOWUP_ON_REAL_RUNNER', false, 'action_scheduler_runner:absent' );
} else {
	/**
	 * GetInvoiceStatus answers, one per call, so the chain can be walked.
	 */
	$scripted_transport = new class() implements Kuka_Island_Core_SOAP_Transport_Interface {
		/** @var array<string, int> */
		public array $calls = array();
		/** @var array<int, string> STATUS literals to hand back, in order. */
		public array $script = array();
		/** @var int */
		public int $index = 0;

		public function get_last_request(): string {
			return '';
		}

		public function get_last_response(): string {
			return '';
		}

		public function call( string $operation, array $parameters ) {
			$this->calls[ $operation ] = ( $this->calls[ $operation ] ?? 0 ) + 1;

			if ( 'Login' === $operation ) {
				return array( 'SESSION_ID' => 'session-runner-fixture', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
			}

			if ( 'GetInvoiceStatus' === $operation ) {
				$status = $this->script[ $this->index ] ?? end( $this->script );
				++$this->index;

				return array(
					'INVOICE_STATUS' => array(
						array(
							'UUID'   => $parameters['INVOICE']['UUID'] ?? 'uuid-runner-fixture',
							'HEADER' => array( 'STATUS' => $status ),
						),
					),
				);
			}

			return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}
	};

	// Two in-flight answers, then a terminal one.
	$scripted_transport->script = array( 'PACKAGE - PROCESSING', 'SEND - WAIT_GIB_RESPONSE', 'SEND - SUCCEED' );

	$runner_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'status' => 'processing' ) ) );
	kuka_add_line( $runner_order, 'Runner Fixture', '100.00', '100.00', 1, '10.00' );
	$runner_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_UUID, 'uuid-runner-fixture' );
	$runner_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, 'KUK2026000000903' );
	$runner_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_SENT );
	$runner_order->save();
	$runner_order_id = (int) $runner_order->get_id();

	$runner_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $scripted_transport ) );
	$runner_poller  = new Kuka_Island_Core_Invoice_Status_Poller( $runner_manager );

	// Only the mock-backed poller may answer this action, so no production
	// callback can reach a real endpoint from inside the runner.
	$saved_callbacks = $GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS ] ?? null;
	remove_all_actions( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS );
	$runner_poller->register();

	// What status does the action carry while its own callback is executing?
	$running_action_id  = 0;
	$observed_running   = array();
	add_action(
		Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS,
		static function () use ( &$running_action_id, &$observed_running ): void {
			$observed_running[] = (string) ActionScheduler::store()->get_status( $running_action_id );
		},
		1,
		1
	);

	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $runner_order_id );
	$runner_started = Kuka_Island_Core_Invoice_Status_Poller::start( $runner_order, Kuka_Island_Core_Invoice_Status::STATUS_SENT );

	$runner_steps    = array();
	$runner_statuses = array();
	$runner_future   = array();
	$now             = time();

	for ( $round = 1; $round <= 3; $round++ ) {
		$pending = $poll_pending_ids( $runner_order_id );
		$runner_steps[] = 'before' . $round . ':' . count( $pending );
		if ( 1 !== count( $pending ) ) {
			break;
		}

		$running_action_id = (int) $pending[0];
		$scheduled_at      = ActionScheduler::store()->fetch_action( $running_action_id )->get_schedule()->get_date();
		$runner_future[]   = ( $scheduled_at instanceof DateTime && $scheduled_at->getTimestamp() > $now ) ? 'future' : 'NOT_FUTURE';

		ActionScheduler_QueueRunner::instance()->process_action( $running_action_id, 'kuka-verify' );

		$runner_statuses[] = (string) ActionScheduler::store()->get_status( $running_action_id );
		$runner_steps[]    = 'after' . $round . ':' . count( $poll_pending_ids( $runner_order_id ) );
	}

	$pending_after_terminal = count( $poll_pending_ids( $runner_order_id ) );
	$runner_chain_order     = wc_get_order( $runner_order_id );
	// Captured before the race scenarios below rewrite the status on purpose.
	$runner_chain_status    = $runner_chain_order instanceof WC_Order
		? (string) $runner_chain_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, true )
		: 'order_missing';
	$runner_chain_error     = $runner_chain_order instanceof WC_Order
		? (string) $runner_chain_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, true )
		: 'order_missing';

	/*
	 * The lock race, both ways round.
	 *
	 * The advisory lock is a MySQL session lock, so the rival worker is a real
	 * second connection holding the very lock the scheduler takes. Losing that
	 * lock is only safe if the winner actually left a query behind, and the
	 * winner may have failed exactly as this call could have -- so the pending
	 * query has to be looked for, never assumed.
	 */
	$race_lock_name = 'kuka_inv_poll_' . $runner_order_id;
	$rival          = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

	$race_order = wc_get_order( $runner_order_id );
	// Put the document back in flight, so "the in-flight status is preserved"
	// is a claim the race can actually falsify.
	$race_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_SENT );
	$race_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, '' );
	$race_order->save_meta_data();

	// (a) The rival booked a query and then took the lock. This is an ordinary
	// already_pending, and nothing may be recorded as a failure.
	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $runner_order_id );
	$rival_booked = Kuka_Island_Core_Invoice_Status_Poller::schedule_query( $runner_order_id, 300 );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rival_holds_a = '1' === (string) $rival->get_var( $rival->prepare( 'SELECT GET_LOCK(%s, 0)', $race_lock_name ) );

	$race_with_pending    = Kuka_Island_Core_Invoice_Status_Poller::book_query( $race_order, 300 );
	$pending_with_pending = count( $poll_pending_ids( $runner_order_id ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rival->get_var( $rival->prepare( 'SELECT RELEASE_LOCK(%s)', $race_lock_name ) );

	$race_order->read_meta_data( true );
	$error_after_with_pending = (string) $race_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, true );

	// (b) The rival holds the lock and booked nothing. Reporting success here
	// is the silent failure being removed, so it has to leave a visible record.
	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $runner_order_id );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rival_holds_b = '1' === (string) $rival->get_var( $rival->prepare( 'SELECT GET_LOCK(%s, 0)', $race_lock_name ) );

	$notes_before_race       = count( wc_get_order_notes( array( 'order_id' => $runner_order_id ) ) );
	$race_without_pending    = Kuka_Island_Core_Invoice_Status_Poller::book_query( $race_order, 300 );
	$pending_without_pending = count( $poll_pending_ids( $runner_order_id ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rival->get_var( $rival->prepare( 'SELECT RELEASE_LOCK(%s)', $race_lock_name ) );

	$race_order->read_meta_data( true );
	$error_after_race  = (string) $race_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, true );
	$status_after_race = Kuka_Island_Core_Invoice_Order_Store::get_status( $race_order );
	$notes_after_race  = count( wc_get_order_notes( array( 'order_id' => $runner_order_id ) ) );

	// (c) With the lock free again, one booking is created and the next is an
	// already_pending success rather than an indistinguishable refusal.
	$attempt_after_release = Kuka_Island_Core_Invoice_Status_Poller::schedule_query( $runner_order_id, 300 );
	$attempt_duplicate     = Kuka_Island_Core_Invoice_Status_Poller::schedule_query( $runner_order_id, 300 );
	$pending_after_race    = count( $poll_pending_ids( $runner_order_id ) );

	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $runner_order_id );

	// Restore whatever the plugin had registered.
	remove_all_actions( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS );
	if ( null !== $saved_callbacks ) {
		$GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS ] = $saved_callbacks;
	}

	$expected_steps = array( 'before1:1', 'after1:1', 'before2:1', 'after2:1', 'before3:1', 'after3:0' );
	$running_seen   = array( ActionScheduler_Store::STATUS_RUNNING, ActionScheduler_Store::STATUS_RUNNING, ActionScheduler_Store::STATUS_RUNNING );
	$complete_seen  = array( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler_Store::STATUS_COMPLETE );

	$report(
		'INVOICE_POLL_FOLLOWUP_ON_REAL_RUNNER',
		true === $runner_started['ok']
		&& Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_CREATED === $runner_started['outcome']
		// (a) each action really executed while it was RUNNING...
		&& $running_seen === $observed_running
		// ...and finished COMPLETE.
		&& $complete_seen === $runner_statuses
		// (b) and (c) exactly one future query after each in-flight answer.
		&& $expected_steps === $runner_steps
		&& array( 'future', 'future', 'future' ) === $runner_future
		// (d) the terminal answer leaves nothing booked.
		&& 0 === $pending_after_terminal
		&& Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED === $runner_chain_status
		// A chain that worked records no scheduling failure.
		&& '' === $runner_chain_error
		// GetInvoiceStatus is still the only operation the poller can reach.
		&& 3 === (int) ( $scripted_transport->calls['GetInvoiceStatus'] ?? 0 )
		&& 0 === (int) ( $scripted_transport->calls['SendInvoice'] ?? 0 )
		&& 0 === (int) ( $scripted_transport->calls['LoadInvoice'] ?? 0 ),
		sprintf(
			'measured:action_scheduler_runner|start_outcome:%s|steps:%s|action_status_during_run:%s|action_status_after_run:%s|followup_dates:%s|pending_after_terminal:%d|order_status:%s|last_error:%s|SendInvoice=%d|LoadInvoice=%d|GetInvoiceStatus=%d',
			(string) $runner_started['outcome'],
			implode( ' ', $runner_steps ),
			empty( $observed_running ) ? 'none' : implode( ',', $observed_running ),
			empty( $runner_statuses ) ? 'none' : implode( ',', $runner_statuses ),
			empty( $runner_future ) ? 'none' : implode( ',', $runner_future ),
			$pending_after_terminal,
			$runner_chain_status,
			'' === $runner_chain_error ? 'none' : $runner_chain_error,
			$scripted_transport->calls['SendInvoice'] ?? 0,
			$scripted_transport->calls['LoadInvoice'] ?? 0,
			$scripted_transport->calls['GetInvoiceStatus'] ?? 0
		)
	);

	$report(
		'INVOICE_POLL_LOCK_RACE_FAIL_VISIBLE',
		// The rival's own booking really happened, so (a) is a genuine race.
		Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_CREATED === $rival_booked
		&& true === $rival_holds_a
		// (a) A lost lock WITH a pending query behind it is an ordinary success.
		&& true === $race_with_pending['ok']
		&& Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_ALREADY_PENDING === $race_with_pending['outcome']
		&& true === $race_with_pending['pending_verified']
		&& '' === $race_with_pending['error_code']
		&& '' === $error_after_with_pending
		&& 1 === $pending_with_pending
		// (b) A lost lock with NOTHING behind it is not silent success.
		&& true === $rival_holds_b
		&& false === $race_without_pending['ok']
		&& Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_LOCK_CONTENDED === $race_without_pending['outcome']
		&& false === $race_without_pending['pending_verified']
		&& Kuka_Island_Core_Invoice_Status_Poller::ERROR_LOCK_WITHOUT_PENDING === $race_without_pending['error_code']
		&& Kuka_Island_Core_Invoice_Status_Poller::ERROR_LOCK_WITHOUT_PENDING === $error_after_race
		&& 0 === $pending_without_pending
		// The document keeps its in-flight status: needs_manual_review would
		// let can_retry() send it a second time.
		&& Kuka_Island_Core_Invoice_Status::STATUS_SENT === $status_after_race
		&& false === Kuka_Island_Core_Invoice_Status::can_retry( $status_after_race )
		// And somebody is told, in the order's own note list.
		&& $notes_after_race === $notes_before_race + 1
		// (c) With the lock free, one created and one already_pending.
		&& Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_CREATED === $attempt_after_release
		&& Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_ALREADY_PENDING === $attempt_duplicate
		&& 1 === $pending_after_race
		// Neither branch of the race touches a document.
		&& 0 === (int) ( $scripted_transport->calls['SendInvoice'] ?? 0 )
		&& 0 === (int) ( $scripted_transport->calls['LoadInvoice'] ?? 0 ),
		sprintf(
			'measured:second_mysql_session|rival_booking:%s|with_pending:held=%s,outcome=%s,verified=%s,error=%s,pending=%d|without_pending:held=%s,outcome=%s,verified=%s,error=%s,pending=%d|status_preserved:%s|retryable:%s|notes_added:%d|after_release:%s|duplicate:%s|pending_total:%d|SendInvoice=%d|LoadInvoice=%d',
			$rival_booked,
			$rival_holds_a ? 'yes' : 'no',
			(string) $race_with_pending['outcome'],
			true === $race_with_pending['pending_verified'] ? 'yes' : 'no',
			'' === $race_with_pending['error_code'] ? 'none' : $race_with_pending['error_code'],
			$pending_with_pending,
			$rival_holds_b ? 'yes' : 'no',
			(string) $race_without_pending['outcome'],
			false === $race_without_pending['pending_verified'] ? 'no' : 'yes',
			'' === $race_without_pending['error_code'] ? 'NONE' : $race_without_pending['error_code'],
			$pending_without_pending,
			$status_after_race,
			Kuka_Island_Core_Invoice_Status::can_retry( $status_after_race ) ? 'YES' : 'no',
			$notes_after_race - $notes_before_race,
			$attempt_after_release,
			$attempt_duplicate,
			$pending_after_race,
			$scripted_transport->calls['SendInvoice'] ?? 0,
			$scripted_transport->calls['LoadInvoice'] ?? 0
		)
	);

	kuka_test_delete_order( $runner_order_id, $test_run_id );
}

/* -------------------------------------------------------------------------- */
/* A booking that does not happen is visible, and never resends               */
/* -------------------------------------------------------------------------- */

/**
 * Make Action Scheduler genuinely return 0 for the poller's hook only.
 *
 * pre_as_schedule_single_action short-circuits as_schedule_single_action(), and
 * a non-null return is handed straight back to the caller -- so 0 here is the
 * real "I could not schedule that" answer, not a stubbed method.
 *
 * Scoped to the poll hook so nothing else scheduled in this process is
 * affected.
 */
$refuse_poll_scheduling = static function ( $pre, $timestamp, $hook ) {
	if ( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS === $hook ) {
		return 0;
	}

	return $pre;
};

/**
 * Words that must never reach the order from a scheduling failure.
 *
 * @return array<int, string>
 */
$forbidden_needles = array( 'SoapFault', 'Exception', 'Stack trace', 'PASSWORD', 'SECRET_KEY', 'SESSION_ID', 'Envelope', '<soap', 'wp_password', 'kukaisland_edm' );

/*
 * (1) The FIRST booking fails, straight after a real SendInvoice.
 *
 * Previously Kuka_Island_Core_Invoice_Manager::start_status_polling() swallowed
 * this and returned false into nothing: the document sat in flight with no query
 * booked and nothing on the order to say so.
 */
$fail_transport = new Kuka_Island_Test_Tracking_Transport();
$fail_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $fail_transport ) );
$fail_order     = kuka_create_lock_order(
	$test_run_id,
	$billing_props,
	array(
		'_kuka_invoice_number'        => 'KUK2026000000042',
		'_kuka_invoice_number_source' => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
	)
);
$fail_order_id    = (int) $fail_order->get_id();
$fail_notes_before = count( wc_get_order_notes( array( 'order_id' => $fail_order_id ) ) );

add_filter( 'pre_as_schedule_single_action', $refuse_poll_scheduling, 10, 3 );
$fail_error = '';
try {
	$fail_manager->process_order( $fail_order );
} catch ( Throwable $t ) {
	$fail_error = get_class( $t );
}
remove_filter( 'pre_as_schedule_single_action', $refuse_poll_scheduling, 10 );

$fail_order->read_meta_data( true );
$fail_status   = Kuka_Island_Core_Invoice_Order_Store::get_status( $fail_order );
$fail_code     = (string) $fail_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, true );
$fail_outcome  = (string) $fail_order->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_LAST_SCHEDULE_OUTCOME, true );
$fail_pending  = count( $poll_pending_ids( $fail_order_id ) );
$fail_history  = (array) ( $fail_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_HISTORY, true ) ?: array() );
$fail_last     = (array) ( end( $fail_history ) ?: array() );
$fail_notes    = wc_get_order_notes( array( 'order_id' => $fail_order_id ) );
$fail_note_txt = '';
foreach ( $fail_notes as $note ) {
	if ( str_contains( (string) $note->content, Kuka_Island_Core_Invoice_Status_Poller::ERROR_SCHEDULE_FAILED ) ) {
		$fail_note_txt = (string) $note->content;
	}
}

// Nothing about the failure leaks: no exception text, no credential, no payload.
$fail_leaks = array();
foreach ( $forbidden_needles as $needle ) {
	$haystack = $fail_note_txt . '|' . (string) ( $fail_last['message'] ?? '' ) . '|' . $fail_code;
	if ( '' !== $needle && stripos( $haystack, $needle ) !== false ) {
		$fail_leaks[] = $needle;
	}
}

// already_pending is an ordinary success and writes no failure at all.
$ok_order = kuka_create_lock_order(
	$test_run_id,
	$billing_props,
	array(
		'_kuka_invoice_status'        => Kuka_Island_Core_Invoice_Status::STATUS_SENT,
		'_kuka_invoice_uuid'          => 'uuid-already-pending',
		'_kuka_invoice_number'        => 'KUK2026000000042',
		'_kuka_invoice_number_source' => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
	)
);
$ok_order_id = (int) $ok_order->get_id();
Kuka_Island_Core_Invoice_Status_Poller::unschedule( $ok_order_id );
$ok_notes_before = count( wc_get_order_notes( array( 'order_id' => $ok_order_id ) ) );
$ok_first        = Kuka_Island_Core_Invoice_Status_Poller::book_query( $ok_order, 300 );
$ok_second       = Kuka_Island_Core_Invoice_Status_Poller::book_query( $ok_order, 300 );
$ok_pending      = count( $poll_pending_ids( $ok_order_id ) );
$ok_order->read_meta_data( true );
$ok_code        = (string) $ok_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, true );
$ok_notes_after = count( wc_get_order_notes( array( 'order_id' => $ok_order_id ) ) );

$report(
	'INVOICE_POLL_FIRST_SCHEDULE_FAILURE_VISIBLE',
	// The document really was transmitted, exactly once, and nothing else was
	// called on the send path.
	1 === (int) ( $fail_transport->calls['SendInvoice'] ?? 0 )
	&& 0 === (int) ( $fail_transport->calls['GetInvoiceStatus'] ?? 0 )
	&& 0 === (int) ( $fail_transport->calls['LoadInvoice'] ?? 0 )
	// The send itself still succeeded: a booking problem is not a send problem.
	&& '' === $fail_error
	// No query was booked, and the order says so by safe code.
	&& 0 === $fail_pending
	&& Kuka_Island_Core_Invoice_Status_Poller::ERROR_SCHEDULE_FAILED === $fail_code
	&& Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_FAILED === $fail_outcome
	// The in-flight status stands. needs_manual_review would let can_retry()
	// send this document a second time.
	&& Kuka_Island_Core_Invoice_Status::STATUS_SENT === $fail_status
	&& false === Kuka_Island_Core_Invoice_Status::can_retry( $fail_status )
	// History records the failure against the status the document still has.
	&& Kuka_Island_Core_Invoice_Status::STATUS_SENT === (string) ( $fail_last['status'] ?? '' )
	&& str_contains( (string) ( $fail_last['message'] ?? '' ), Kuka_Island_Core_Invoice_Status_Poller::ERROR_SCHEDULE_FAILED )
	// And an order note tells whoever opens the order what to do.
	&& '' !== $fail_note_txt
	&& count( $fail_notes ) === $fail_notes_before + 1
	&& str_contains( $fail_note_txt, 'manuel' )
	&& array() === $fail_leaks
	// already_pending is success, and leaves nothing behind.
	&& Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_CREATED === $ok_first['outcome']
	&& true === $ok_first['ok']
	&& Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_ALREADY_PENDING === $ok_second['outcome']
	&& true === $ok_second['ok']
	&& '' === $ok_second['error_code']
	&& '' === $ok_code
	&& $ok_notes_after === $ok_notes_before
	&& 1 === $ok_pending,
	sprintf(
		'measured:pre_as_schedule_single_action=0|SendInvoice=%d|GetInvoiceStatus=%d|LoadInvoice=%d|send_threw:%s|pending:%d|outcome:%s|error_code:%s|status:%s|retryable:%s|history_status:%s|note_added:%d|leaks:%s|already_pending:%s/%s|already_pending_error:%s|already_pending_notes:%d|already_pending_pending:%d',
		$fail_transport->calls['SendInvoice'] ?? 0,
		$fail_transport->calls['GetInvoiceStatus'] ?? 0,
		$fail_transport->calls['LoadInvoice'] ?? 0,
		'' === $fail_error ? 'no' : $fail_error,
		$fail_pending,
		$fail_outcome ?: 'none',
		$fail_code ?: 'none',
		$fail_status,
		Kuka_Island_Core_Invoice_Status::can_retry( $fail_status ) ? 'YES' : 'no',
		(string) ( $fail_last['status'] ?? 'none' ),
		count( $fail_notes ) - $fail_notes_before,
		empty( $fail_leaks ) ? 'none' : implode( ',', $fail_leaks ),
		(string) $ok_first['outcome'],
		(string) $ok_second['outcome'],
		'' === $ok_second['error_code'] ? 'none' : $ok_second['error_code'],
		$ok_notes_after - $ok_notes_before,
		$ok_pending
	)
);

Kuka_Island_Core_Invoice_Status_Poller::unschedule( $ok_order_id );
kuka_test_delete_order( $fail_order_id, $test_run_id );
kuka_test_delete_order( $ok_order_id, $test_run_id );

/*
 * (2) The FOLLOW-UP booking fails, inside a real Action Scheduler run.
 *
 * Previously poll_order() called schedule() and returned without looking at the
 * answer, so the chain simply ended: no next query, no record, and the caps that
 * were supposed to escalate were never reached.
 */
if ( $runner_available ) {
	$followup_transport = new class() implements Kuka_Island_Core_SOAP_Transport_Interface {
		/** @var array<string, int> */
		public array $calls = array();

		public function get_last_request(): string {
			return '';
		}

		public function get_last_response(): string {
			return '';
		}

		public function call( string $operation, array $parameters ) {
			$this->calls[ $operation ] = ( $this->calls[ $operation ] ?? 0 ) + 1;

			if ( 'Login' === $operation ) {
				return array( 'SESSION_ID' => 'session-followup-fixture', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
			}

			if ( 'GetInvoiceStatus' === $operation ) {
				return array(
					'INVOICE_STATUS' => array(
						array(
							'UUID'   => $parameters['INVOICE']['UUID'] ?? 'uuid-followup-fixture',
							// Still in flight, so a follow-up is wanted.
							'HEADER' => array( 'STATUS' => 'PACKAGE - PROCESSING' ),
						),
					),
				);
			}

			return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}
	};

	$followup_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'status' => 'processing' ) ) );
	kuka_add_line( $followup_order, 'Followup Fixture', '100.00', '100.00', 1, '10.00' );
	$followup_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_UUID, 'uuid-followup-fixture' );
	$followup_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, 'KUK2026000000904' );
	$followup_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_SENT );
	$followup_order->save();
	$followup_order_id    = (int) $followup_order->get_id();
	$followup_notes_before = count( wc_get_order_notes( array( 'order_id' => $followup_order_id ) ) );

	$followup_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $followup_transport ) );
	$followup_poller  = new Kuka_Island_Core_Invoice_Status_Poller( $followup_manager );

	$followup_saved = $GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS ] ?? null;
	remove_all_actions( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS );
	$followup_poller->register();

	// The first action is created normally; only the follow-up is refused.
	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $followup_order_id );
	$followup_first  = Kuka_Island_Core_Invoice_Status_Poller::schedule_query( $followup_order_id, 300 );
	$followup_ids    = $poll_pending_ids( $followup_order_id );
	$followup_action = (int) ( $followup_ids[0] ?? 0 );

	add_filter( 'pre_as_schedule_single_action', $refuse_poll_scheduling, 10, 3 );
	if ( $followup_action > 0 ) {
		ActionScheduler_QueueRunner::instance()->process_action( $followup_action, 'kuka-verify' );
	}
	remove_filter( 'pre_as_schedule_single_action', $refuse_poll_scheduling, 10 );

	$followup_action_status = $followup_action > 0 ? (string) ActionScheduler::store()->get_status( $followup_action ) : 'none';
	$followup_pending       = count( $poll_pending_ids( $followup_order_id ) );

	remove_all_actions( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS );
	if ( null !== $followup_saved ) {
		$GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS ] = $followup_saved;
	}

	$followup_reloaded = wc_get_order( $followup_order_id );
	$followup_status   = Kuka_Island_Core_Invoice_Order_Store::get_status( $followup_reloaded );
	$followup_code     = (string) $followup_reloaded->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, true );
	$followup_outcome  = (string) $followup_reloaded->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_LAST_SCHEDULE_OUTCOME, true );
	$followup_attempts = (int) $followup_reloaded->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_POLL_ATTEMPTS, true );
	$followup_notes    = wc_get_order_notes( array( 'order_id' => $followup_order_id ) );
	$followup_note_txt = '';
	foreach ( $followup_notes as $note ) {
		if ( str_contains( (string) $note->content, Kuka_Island_Core_Invoice_Status_Poller::ERROR_SCHEDULE_FAILED ) ) {
			$followup_note_txt = (string) $note->content;
		}
	}

	$followup_leaks = array();
	foreach ( $forbidden_needles as $needle ) {
		if ( '' !== $needle && stripos( $followup_note_txt . '|' . $followup_code, $needle ) !== false ) {
			$followup_leaks[] = $needle;
		}
	}

	$report(
		'INVOICE_POLL_FOLLOWUP_SCHEDULE_FAILURE_VISIBLE',
		Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_CREATED === $followup_first
		&& $followup_action > 0
		// The action itself ran and completed; the query was made.
		&& ActionScheduler_Store::STATUS_COMPLETE === $followup_action_status
		&& 1 === (int) ( $followup_transport->calls['GetInvoiceStatus'] ?? 0 )
		// Nothing was transmitted or reloaded from the poll path.
		&& 0 === (int) ( $followup_transport->calls['SendInvoice'] ?? 0 )
		&& 0 === (int) ( $followup_transport->calls['LoadInvoice'] ?? 0 )
		// The chain really did stop, and it said so.
		&& 0 === $followup_pending
		&& Kuka_Island_Core_Invoice_Status_Poller::ERROR_SCHEDULE_FAILED === $followup_code
		&& Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_FAILED === $followup_outcome
		&& '' !== $followup_note_txt
		&& count( $followup_notes ) === $followup_notes_before + 1
		&& array() === $followup_leaks
		// EDM said PACKAGE - PROCESSING, so the document stays in flight and
		// cannot be re-sent.
		&& Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL === $followup_status
		&& false === Kuka_Island_Core_Invoice_Status::can_retry( $followup_status )
		// The attempt that happened is counted; the caps are not bypassed.
		&& 1 === $followup_attempts,
		sprintf(
			'measured:action_scheduler_runner+pre_as_schedule_single_action=0|first_booking:%s|action_status:%s|GetInvoiceStatus=%d|SendInvoice=%d|LoadInvoice=%d|pending:%d|outcome:%s|error_code:%s|status:%s|retryable:%s|attempts:%d|note_added:%d|leaks:%s',
			$followup_first,
			$followup_action_status,
			$followup_transport->calls['GetInvoiceStatus'] ?? 0,
			$followup_transport->calls['SendInvoice'] ?? 0,
			$followup_transport->calls['LoadInvoice'] ?? 0,
			$followup_pending,
			$followup_outcome ?: 'none',
			$followup_code ?: 'none',
			$followup_status,
			Kuka_Island_Core_Invoice_Status::can_retry( $followup_status ) ? 'YES' : 'no',
			$followup_attempts,
			count( $followup_notes ) - $followup_notes_before,
			empty( $followup_leaks ) ? 'none' : implode( ',', $followup_leaks )
		)
	);

	kuka_test_delete_order( $followup_order_id, $test_run_id );
}

/* -------------------------------------------------------------------------- */
/* Central post-transmission guard: no blind resend, ever                      */
/* -------------------------------------------------------------------------- */

/**
 * GetInvoiceStatus answers with a fixed STATUS literal, or refuses outright.
 */
final class Kuka_Island_Test_Status_Literal_Transport implements Kuka_Island_Core_SOAP_Transport_Interface {
	/** @var array<string, int> */
	public array $calls = array();
	/** @var string STATUS literal to hand back. */
	public string $status_literal;
	/** @var bool Refuse the query instead of answering. */
	public bool $fail_status;

	public function __construct( string $status_literal = 'PACKAGE - PROCESSING', bool $fail_status = false ) {
		$this->status_literal = $status_literal;
		$this->fail_status    = $fail_status;
	}

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}

	public function call( string $operation, array $parameters ) {
		$this->calls[ $operation ] = ( $this->calls[ $operation ] ?? 0 ) + 1;

		if ( 'Login' === $operation ) {
			return array( 'SESSION_ID' => 'session-guard-fixture', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}

		if ( 'GetInvoiceStatus' === $operation ) {
			if ( $this->fail_status ) {
				throw new SoapFault( 'HTTP', 'Connection timed out during status reconciliation' );
			}

			return array(
				'INVOICE_STATUS' => array(
					array(
						'UUID'   => $parameters['INVOICE']['UUID'] ?? 'uuid-guard-fixture',
						'HEADER' => array( 'STATUS' => $this->status_literal ),
					),
				),
			);
		}

		if ( 'SendInvoice' === $operation ) {
			return array(
				'INVOICE'        => array(
					'UUID' => $parameters['INVOICE'][0]['UUID'] ?? 'uuid-guard-send',
					'ID'   => $parameters['INVOICE'][0]['ID'] ?? 'KUK-UNSET',
					// Not on EDM's published list, so not an answer.
					'HEADER' => array( 'STATUS' => $this->status_literal ),
				),
				'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			);
		}

		return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
	}
}

/*
 * (A) The poller gives up. It used to write needs_manual_review here, which
 * can_retry() permits -- so the document became sendable again the moment a
 * reconciliation failed. Driven through the real Action Scheduler runner.
 */
if ( $runner_available ) {
	$giveup_cases = array(
		// name => [ seeded poll attempts, started_at offset, expected safe code ]
		'attempt_cap' => array( Kuka_Island_Core_Invoice_Status_Poller::MAX_ATTEMPTS - 1, 0, Kuka_Island_Core_Invoice_Status_Poller::ERROR_MAX_ATTEMPTS ),
		'elapsed_cap' => array( 1, -( Kuka_Island_Core_Invoice_Status_Poller::MAX_ELAPSED + 60 ), Kuka_Island_Core_Invoice_Status_Poller::ERROR_MAX_ELAPSED ),
	);

	$giveup_ok      = true;
	$giveup_details = array();
	$giveup_sends   = 0;
	$giveup_loads   = 0;

	foreach ( $giveup_cases as $case => $spec ) {
		$giveup_transport = new Kuka_Island_Test_Status_Literal_Transport( 'PACKAGE - PROCESSING' );
		$giveup_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $giveup_transport ) );
		$giveup_poller    = new Kuka_Island_Core_Invoice_Status_Poller( $giveup_manager );

		$giveup_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'status' => 'processing' ) ) );
		kuka_add_line( $giveup_order, 'Give-up Fixture', '100.00', '100.00', 1, '10.00' );
		$giveup_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_UUID, 'uuid-giveup-' . $case );
		$giveup_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, 'KUK2026000000905' );
		$giveup_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL );
		$giveup_order->update_meta_data( Kuka_Island_Core_Invoice_Status_Poller::META_POLL_ATTEMPTS, (string) $spec[0] );
		$giveup_order->update_meta_data( Kuka_Island_Core_Invoice_Status_Poller::META_POLL_STARTED_AT, (string) ( time() + $spec[1] ) );
		$giveup_order->save();
		$giveup_order_id     = (int) $giveup_order->get_id();
		$giveup_notes_before = count( wc_get_order_notes( array( 'order_id' => $giveup_order_id ) ) );

		$giveup_saved = $GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS ] ?? null;
		remove_all_actions( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS );
		$giveup_poller->register();

		Kuka_Island_Core_Invoice_Status_Poller::unschedule( $giveup_order_id );
		Kuka_Island_Core_Invoice_Status_Poller::schedule_query( $giveup_order_id, 300 );
		$giveup_ids    = $poll_pending_ids( $giveup_order_id );
		$giveup_action = (int) ( $giveup_ids[0] ?? 0 );
		if ( $giveup_action > 0 ) {
			ActionScheduler_QueueRunner::instance()->process_action( $giveup_action, 'kuka-verify' );
		}

		remove_all_actions( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS );
		if ( null !== $giveup_saved ) {
			$GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS ] = $giveup_saved;
		}

		$giveup_reloaded = wc_get_order( $giveup_order_id );
		$giveup_status   = Kuka_Island_Core_Invoice_Order_Store::get_status( $giveup_reloaded );
		$giveup_code     = (string) $giveup_reloaded->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, true );
		$giveup_pending  = count( $poll_pending_ids( $giveup_order_id ) );
		$giveup_history  = (array) ( $giveup_reloaded->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_HISTORY, true ) ?: array() );
		$giveup_last     = (array) ( end( $giveup_history ) ?: array() );
		$giveup_notes    = wc_get_order_notes( array( 'order_id' => $giveup_order_id ) );
		$giveup_note_hit = false;
		foreach ( $giveup_notes as $note ) {
			if ( str_contains( (string) $note->content, $spec[2] ) ) {
				$giveup_note_hit = true;
			}
		}

		$giveup_sends += (int) ( $giveup_transport->calls['SendInvoice'] ?? 0 );
		$giveup_loads += (int) ( $giveup_transport->calls['LoadInvoice'] ?? 0 );

		$hit = 0 === $giveup_pending
			&& Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED === $giveup_status
			&& false === Kuka_Island_Core_Invoice_Status::can_retry( $giveup_status )
			&& $spec[2] === $giveup_code
			&& Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED === (string) ( $giveup_last['status'] ?? '' )
			&& str_contains( (string) ( $giveup_last['message'] ?? '' ), $spec[2] )
			&& $giveup_note_hit
			&& count( $giveup_notes ) === $giveup_notes_before + 1
			// The old status is gone for good: never needs_manual_review.
			&& Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW !== $giveup_status;

		$giveup_details[] = $case . '=' . $giveup_status . '/' . ( $giveup_code ?: 'none' ) . '/pending' . $giveup_pending;
		if ( ! $hit ) {
			$giveup_ok = false;
		}

		kuka_test_delete_order( $giveup_order_id, $test_run_id );
	}

	$report(
		'INVOICE_POLL_GIVE_UP_IS_NOT_RETRYABLE',
		$giveup_ok
		&& 0 === $giveup_sends
		&& 0 === $giveup_loads,
		sprintf(
			'measured:action_scheduler_runner|cases:%d|%s|retryable:no|SendInvoice=%d|LoadInvoice=%d',
			count( $giveup_cases ),
			implode( ' ', $giveup_details ),
			$giveup_sends,
			$giveup_loads
		)
	);
}

/*
 * (B..F) The guard itself, measured through the production process_order().
 *
 * Every scenario below carries persistent evidence of a transmission attempt and
 * is then asked to send again, with force=true and with reconciliation failing --
 * the exact combination that used to fall through to SendInvoice.
 */
$guard_matrix = array(
	// name => [ seeded meta, force, GetInvoiceStatus behaviour, expected status ]
	'give_up_locked'        => array(
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_STATUS   => Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
			Kuka_Island_Core_Invoice_Order_Store::META_UUID     => 'uuid-guard-giveup',
			Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS => '1',
		),
		true,
		'fail',
		Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
	),
	'unrecognised_status'   => array(
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_STATUS   => Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
			Kuka_Island_Core_Invoice_Order_Store::META_UUID     => 'uuid-guard-unknown',
			Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS => '1',
		),
		true,
		'unknown',
		Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
	),
	'package_fail'          => array(
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_STATUS   => Kuka_Island_Core_Invoice_Status::STATUS_FAILED,
			Kuka_Island_Core_Invoice_Order_Store::META_UUID     => 'uuid-guard-packagefail',
			Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS => '1',
		),
		true,
		'package_fail',
		Kuka_Island_Core_Invoice_Status::STATUS_FAILED,
	),
	'schedule_failed'       => array(
		array(
			// The state a37a8b8 leaves behind when Action Scheduler refuses.
			Kuka_Island_Core_Invoice_Order_Store::META_STATUS     => Kuka_Island_Core_Invoice_Status::STATUS_SENT,
			Kuka_Island_Core_Invoice_Order_Store::META_UUID       => 'uuid-guard-schedfail',
			Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR => Kuka_Island_Core_Invoice_Status_Poller::ERROR_SCHEDULE_FAILED,
			Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS   => '1',
		),
		true,
		'fail',
		Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
	),
	// Evidence one fact at a time, each on its own, all with force=true.
	'evidence_uuid_only'    => array(
		array( Kuka_Island_Core_Invoice_Order_Store::META_UUID => 'uuid-guard-only' ),
		true,
		'fail',
		Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
	),
	'evidence_status_only'  => array(
		array( Kuka_Island_Core_Invoice_Order_Store::META_STATUS => Kuka_Island_Core_Invoice_Status::STATUS_SENDING ),
		true,
		'fail',
		Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
	),
	'evidence_sent_at_only' => array(
		array( Kuka_Island_Core_Invoice_Order_Store::META_SENT_AT => (string) ( time() - 3600 ) ),
		true,
		'fail',
		Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
	),
	'evidence_attempts_only' => array(
		array( Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS => '1' ),
		true,
		'fail',
		Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
	),
	// A non-force caller is covered by the same guard. This is a direct manager
	// call, not the queue worker -- the real worker is measured further down.
	'unforced_manager_call' => array(
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_STATUS   => Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
			Kuka_Island_Core_Invoice_Order_Store::META_UUID     => 'uuid-guard-unforced',
			Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS => '1',
		),
		false,
		'fail',
		Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
	),
);

$guard_ok      = true;
$guard_details = array();
$guard_sends   = 0;
$guard_loads   = 0;

foreach ( $guard_matrix as $case => $spec ) {
	$guard_transport = match ( $spec[2] ) {
		'unknown'      => new Kuka_Island_Test_Status_Literal_Transport( 'PROCESS SUCCESS NOTE' ),
		'package_fail' => new Kuka_Island_Test_Status_Literal_Transport( 'PACKAGE - FAIL' ),
		default        => new Kuka_Island_Test_Status_Literal_Transport( 'PACKAGE - PROCESSING', true ),
	};

	$guard_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $guard_transport ) );
	$guard_order   = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array_merge(
			array(
				Kuka_Island_Core_Invoice_Order_Store::META_NUMBER        => 'KUK2026000000042',
				Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
			),
			$spec[0]
		)
	);
	$guard_order_id  = (int) $guard_order->get_id();
	$uuid_before     = (string) $guard_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true );
	$number_before   = (string) $guard_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, true );

	try {
		$guard_manager->process_order( $guard_order, (bool) $spec[1] );
	} catch ( Throwable $t ) {
		unset( $t );
	}

	$guard_order->read_meta_data( true );
	$guard_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $guard_order );
	$uuid_after   = (string) $guard_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true );
	$number_after = (string) $guard_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, true );

	$case_sends = (int) ( $guard_transport->calls['SendInvoice'] ?? 0 );
	$case_loads = (int) ( $guard_transport->calls['LoadInvoice'] ?? 0 );
	$guard_sends += $case_sends;
	$guard_loads += $case_loads;

	$hit = 0 === $case_sends
		&& 0 === $case_loads
		&& $guard_status === (string) $spec[3]
		// Neither identifier is rewritten: a manual GetInvoiceStatus needs both.
		&& $uuid_after === $uuid_before
		&& $number_after === $number_before;

	$guard_details[] = $case . '=' . $guard_status . '/send' . $case_sends;
	if ( ! $hit ) {
		$guard_ok = false;
	}

	kuka_test_delete_order( $guard_order_id, $test_run_id );
}

$report(
	'INVOICE_POST_TRANSMISSION_GUARD_NO_RESEND',
	$guard_ok
	&& 0 === $guard_sends
	&& 0 === $guard_loads,
	sprintf(
		'measured:manager_process_order|cases:%d|%s|SendInvoice=%d|LoadInvoice=%d|identifiers_preserved:yes',
		count( $guard_matrix ),
		implode( ' ', $guard_details ),
		$guard_sends,
		$guard_loads
	)
);

/*
 * (C) The whole unrecognised-status story end to end, counted on ONE transport:
 * the first SendInvoice happens, EDM answers with a literal that is not on its
 * published list, and the manual re-send that follows must not produce a second
 * document.
 */
$unknown_transport = new Kuka_Island_Test_Status_Literal_Transport( 'PROCESS SUCCESS NOTE' );
$unknown_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $unknown_transport ) );
$unknown_order     = kuka_create_lock_order(
	$test_run_id,
	$billing_props,
	array(
		Kuka_Island_Core_Invoice_Order_Store::META_NUMBER        => 'KUK2026000000042',
		Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
	)
);
$unknown_order_id = (int) $unknown_order->get_id();

try {
	$unknown_manager->process_order( $unknown_order );
} catch ( Throwable $t ) {
	unset( $t );
}
$unknown_order->read_meta_data( true );
$unknown_first_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $unknown_order );
$unknown_first_sends  = (int) ( $unknown_transport->calls['SendInvoice'] ?? 0 );
$unknown_uuid         = (string) $unknown_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true );
// The evidence the order screen and the queue now consult before offering a
// re-send. can_retry() alone said yes here, which is what used to put a
// "Faturayı Gönder" button on an already-transmitted document.
$unknown_first_evidence = Kuka_Island_Core_Invoice_Manager::transmission_evidence( $unknown_order );
$unknown_offers_send    = array() === $unknown_first_evidence && Kuka_Island_Core_Invoice_Status::can_retry( $unknown_first_status );

// The order screen's re-send button, and then the queue worker, both go through
// process_order(). Neither may produce a second document.
try {
	$unknown_manager->process_order( $unknown_order, true );
} catch ( Throwable $t ) {
	unset( $t );
}
try {
	$unknown_manager->process_order( $unknown_order );
} catch ( Throwable $t ) {
	unset( $t );
}
$unknown_order->read_meta_data( true );
$unknown_final_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $unknown_order );
$unknown_final_sends  = (int) ( $unknown_transport->calls['SendInvoice'] ?? 0 );
$unknown_final_uuid   = (string) $unknown_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true );

$report(
	'INVOICE_UNRECOGNISED_STATUS_NEVER_RESENDS',
	// Exactly one transmission for the life of this document.
	1 === $unknown_first_sends
	&& 1 === $unknown_final_sends
	&& 0 === (int) ( $unknown_transport->calls['LoadInvoice'] ?? 0 )
	// The unrecognised SendInvoice answer is a manual-review state...
	&& Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW === $unknown_first_status
	// ...which can_retry() allows, which is exactly why the guard, not the
	// status, is what stops the second send.
	&& true === Kuka_Island_Core_Invoice_Status::can_retry( $unknown_first_status )
	// ...and the evidence is what makes the admin and the queue refuse to offer
	// the re-send in the first place.
	&& array() !== $unknown_first_evidence
	&& false === $unknown_offers_send
	// After the retry attempts the order is locked out of the send path.
	&& Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED === $unknown_final_status
	&& false === Kuka_Island_Core_Invoice_Status::can_retry( $unknown_final_status )
	&& '' !== $unknown_uuid
	&& $unknown_final_uuid === $unknown_uuid,
	sprintf(
		'measured:manager_process_order|SendInvoice_after_first:%d|SendInvoice_after_two_retries:%d|LoadInvoice=%d|first_status:%s|first_retryable:%s|evidence:%s|admin_offers_send:%s|final_status:%s|final_retryable:%s|uuid_stable:%s',
		$unknown_first_sends,
		$unknown_final_sends,
		$unknown_transport->calls['LoadInvoice'] ?? 0,
		$unknown_first_status,
		Kuka_Island_Core_Invoice_Status::can_retry( $unknown_first_status ) ? 'yes' : 'no',
		empty( $unknown_first_evidence ) ? 'NONE' : implode( '+', $unknown_first_evidence ),
		$unknown_offers_send ? 'YES' : 'no',
		$unknown_final_status,
		Kuka_Island_Core_Invoice_Status::can_retry( $unknown_final_status ) ? 'YES' : 'no',
		'' !== $unknown_uuid && $unknown_final_uuid === $unknown_uuid ? 'yes' : 'no'
	)
);

kuka_test_delete_order( $unknown_order_id, $test_run_id );

/*
 * (E) The other side of the guard: an order that was NEVER transmitted keeps its
 * ordinary retry behaviour. A guard that locked unsent orders would quietly stop
 * the shop invoicing at all, which is a different failure, not a safer one.
 */
$presend_cases = array(
	'never_sent_none'          => Kuka_Island_Core_Invoice_Status::STATUS_NONE,
	'never_sent_manual_review' => Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
	'never_sent_failed'        => Kuka_Island_Core_Invoice_Status::STATUS_FAILED,
	'never_sent_blocked'       => Kuka_Island_Core_Invoice_Status::STATUS_BLOCKED,
);

$presend_ok      = true;
$presend_details = array();
$presend_sends   = 0;

foreach ( $presend_cases as $case => $seed_status ) {
	$presend_transport = new Kuka_Island_Test_Tracking_Transport();
	$presend_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $presend_transport ) );
	$presend_order     = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_STATUS         => $seed_status,
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER         => 'KUK2026000000042',
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE  => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
		)
	);
	$presend_order_id = (int) $presend_order->get_id();

	// No UUID, no sent_at, no advanced attempt counter: nothing was transmitted.
	$evidence = Kuka_Island_Core_Invoice_Manager::transmission_evidence( $presend_order );

	try {
		$presend_manager->process_order( $presend_order, true );
	} catch ( Throwable $t ) {
		unset( $t );
	}

	$presend_order->read_meta_data( true );
	$case_sends     = (int) ( $presend_transport->calls['SendInvoice'] ?? 0 );
	$presend_sends += $case_sends;
	$presend_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $presend_order );

	$hit = array() === $evidence
		&& 1 === $case_sends
		&& Kuka_Island_Core_Invoice_Status::STATUS_SENT === $presend_status;

	$presend_details[] = $case . '=' . $presend_status . '/send' . $case_sends;
	if ( ! $hit ) {
		$presend_ok = false;
	}

	kuka_test_delete_order( $presend_order_id, $test_run_id );
}

$report(
	'INVOICE_PRE_TRANSMISSION_STILL_SENDS',
	$presend_ok
	&& count( $presend_cases ) === $presend_sends,
	sprintf(
		'measured:manager_process_order|cases:%d|%s|evidence:none|SendInvoice=%d',
		count( $presend_cases ),
		implode( ' ', $presend_details ),
		$presend_sends
	)
);

/* -------------------------------------------------------------------------- */
/* The REAL send queue worker, on the real Action Scheduler runner             */
/* -------------------------------------------------------------------------- */

/*
 * Everything below runs Kuka_Island_Core_Invoice_Queue::process_queued_order()
 * as an Action Scheduler action, through
 * ActionScheduler_Abstract_QueueRunner::process_action(). Calling the manager
 * directly would not exercise the worker's own catch blocks, which is where the
 * rescheduling decision lives.
 *
 * auto_send is satisfied by a per-test config object, so the worker's own
 * readiness gate is honoured rather than bypassed. No option, constant or
 * production default is touched.
 */
if ( $runner_available ) {
	$queue_config = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'auto_send' => true ) ) );

	/**
	 * Pending action IDs for one hook and one order.
	 *
	 * @param string $hook     Action hook.
	 * @param int    $order_id Order ID.
	 * @return array<int, int>
	 */
	$hook_pending_ids = static function ( string $hook, int $order_id ): array {
		return array_map(
			'intval',
			(array) as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => array( 'order_id' => $order_id ),
					'group'    => Kuka_Island_Core_Invoice_Status_Poller::GROUP,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 50,
					'orderby'  => 'none',
				),
				'ids'
			)
		);
	};

	/**
	 * Install a queue worker as the ONLY callback for the send action.
	 *
	 * register() is deliberately not used: it would also hook
	 * maybe_enqueue_order() onto the order-status transitions, and fixtures
	 * created later in this run would start enqueueing themselves.
	 *
	 * @param Kuka_Island_Core_Invoice_Queue $queue Worker to install.
	 * @return array<string, mixed>|null Callbacks that were displaced.
	 */
	$install_queue_worker = static function ( Kuka_Island_Core_Invoice_Queue $queue ) {
		$saved = $GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE ] ?? null;
		remove_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE );
		add_action( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( $queue, 'process_queued_order' ), 10, 1 );

		return $saved;
	};

	$restore_queue_worker = static function ( $saved ): void {
		remove_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE );
		if ( null !== $saved ) {
			$GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE ] = $saved;
		}
	};

	/**
	 * Run every pending send action for one order, once each, in order.
	 *
	 * Returns how many actions actually executed, which is the number of failed
	 * worker runs when none of them succeeds.
	 *
	 * @param int $order_id Order ID.
	 * @param int $limit    Safety stop, so a runaway chain ends the test rather
	 *                      than the process.
	 * @return array{runs: int, hit_limit: bool}
	 */
	$drain_send_actions = static function ( int $order_id, int $limit = 8 ) use ( $hook_pending_ids ): array {
		$runs = 0;

		while ( $runs < $limit ) {
			$pending = $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $order_id );
			if ( array() === $pending ) {
				break;
			}

			ActionScheduler_QueueRunner::instance()->process_action( (int) $pending[0], 'kuka-verify' );
			++$runs;
		}

		return array(
			'runs'      => $runs,
			'hit_limit' => $runs >= $limit,
		);
	};

	/*
	 * (1) SendInvoice times out. The manager records send_uncertain and books the
	 * status query. The worker used to ALSO schedule another send action, which
	 * is a second SendInvoice waiting to happen; the status query is the
	 * poller's job.
	 */
	$qto_transport = new Kuka_Island_Test_Tracking_Transport();
	$qto_transport->simulate_timeout_on_send = true;
	$qto_manager = new Kuka_Island_Core_Invoice_Manager( $queue_config, new Kuka_Island_Core_EDM_Provider( $queue_config, $qto_transport ) );
	$qto_queue   = new Kuka_Island_Core_Invoice_Queue( $qto_manager );
	$qto_order   = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER        => 'KUK2026000000042',
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
		)
	);
	$qto_order_id = (int) $qto_order->get_id();

	$qto_saved = $install_queue_worker( $qto_queue );
	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $qto_order_id );
	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $qto_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );

	as_schedule_single_action( time(), Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $qto_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	$qto_drain = $drain_send_actions( $qto_order_id );
	$restore_queue_worker( $qto_saved );

	$qto_reloaded    = wc_get_order( $qto_order_id );
	$qto_status      = Kuka_Island_Core_Invoice_Order_Store::get_status( $qto_reloaded );
	$qto_send_calls  = (int) ( $qto_transport->calls['SendInvoice'] ?? 0 );
	$qto_send_queued = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $qto_order_id ) );
	$qto_poll_queued = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS, $qto_order_id ) );
	$qto_retry_meta  = (string) $qto_reloaded->get_meta( Kuka_Island_Core_Invoice_Queue::META_QUEUE_RETRIES, true );

	$report(
		'INVOICE_QUEUE_SEND_TIMEOUT_OWNED_BY_POLLER',
		1 === $qto_send_calls
		&& 0 === $qto_send_queued
		&& 1 === $qto_poll_queued
		&& Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN === $qto_status
		&& 0 === (int) ( $qto_transport->calls['LoadInvoice'] ?? 0 )
		// Exactly one worker run, and the chain did not extend itself.
		&& 1 === $qto_drain['runs']
		&& false === $qto_drain['hit_limit']
		// The send queue keeps no retry budget for a transmitted document.
		&& '' === $qto_retry_meta,
		sprintf(
			'SendInvoice=%d|send_actions_pending=%d|poll_actions_pending=%d|status=%s|measured:real_queue_worker_on_action_scheduler|worker_runs:%d|LoadInvoice=%d|queue_retry_meta:%s',
			$qto_send_calls,
			$qto_send_queued,
			$qto_poll_queued,
			$qto_status,
			$qto_drain['runs'],
			$qto_transport->calls['LoadInvoice'] ?? 0,
			'' === $qto_retry_meta ? 'none' : $qto_retry_meta
		)
	);

	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $qto_order_id );
	kuka_test_delete_order( $qto_order_id, $test_run_id );

	/*
	 * (2) Reconciliation fails for a document that carries transmission
	 * evidence. The worker must not reschedule itself, and must not overwrite
	 * the manager's reconciliation_required with needs_manual_review.
	 */
	$qrf_transport = new Kuka_Island_Test_Status_Literal_Transport( 'PACKAGE - PROCESSING', true );
	$qrf_manager   = new Kuka_Island_Core_Invoice_Manager( $queue_config, new Kuka_Island_Core_EDM_Provider( $queue_config, $qrf_transport ) );
	$qrf_queue     = new Kuka_Island_Core_Invoice_Queue( $qrf_manager );
	$qrf_order     = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_STATUS         => Kuka_Island_Core_Invoice_Status::STATUS_SENT,
			Kuka_Island_Core_Invoice_Order_Store::META_UUID           => 'uuid-queue-reconcile',
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER         => 'KUK2026000000906',
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE  => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
			Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS       => '1',
		)
	);
	$qrf_order_id  = (int) $qrf_order->get_id();
	$qrf_uuid_pre  = (string) $qrf_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true );
	$qrf_num_pre   = (string) $qrf_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, true );

	$qrf_saved = $install_queue_worker( $qrf_queue );
	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $qrf_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	as_schedule_single_action( time(), Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $qrf_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	$qrf_drain = $drain_send_actions( $qrf_order_id );
	$restore_queue_worker( $qrf_saved );

	$qrf_reloaded   = wc_get_order( $qrf_order_id );
	$qrf_status     = Kuka_Island_Core_Invoice_Order_Store::get_status( $qrf_reloaded );
	$qrf_uuid_post  = (string) $qrf_reloaded->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true );
	$qrf_num_post   = (string) $qrf_reloaded->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, true );
	$qrf_identifiers = $qrf_uuid_post === $qrf_uuid_pre && $qrf_num_post === $qrf_num_pre;
	$qrf_send_queued = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $qrf_order_id ) );

	$report(
		'INVOICE_QUEUE_RECONCILIATION_FAILURE_DOES_NOT_RESCHEDULE_SEND',
		0 === (int) ( $qrf_transport->calls['SendInvoice'] ?? 0 )
		&& 1 === (int) ( $qrf_transport->calls['GetInvoiceStatus'] ?? 0 )
		&& 0 === $qrf_send_queued
		&& Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED === $qrf_status
		&& $qrf_identifiers
		&& 0 === (int) ( $qrf_transport->calls['LoadInvoice'] ?? 0 )
		// The worker's catch block did not flatten the manager's decision.
		&& Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW !== $qrf_status
		&& false === Kuka_Island_Core_Invoice_Status::can_retry( $qrf_status )
		// One run, and no successor: the old code looped here for ever because
		// the fiscal attempt counter never advances on a status query.
		&& 1 === $qrf_drain['runs']
		&& false === $qrf_drain['hit_limit'],
		sprintf(
			'SendInvoice=%d|GetInvoiceStatus=%d|send_actions_pending=%d|status=%s|identifiers_preserved:%s|measured:real_queue_worker_on_action_scheduler|worker_runs:%d|retryable:%s|LoadInvoice=%d',
			$qrf_transport->calls['SendInvoice'] ?? 0,
			$qrf_transport->calls['GetInvoiceStatus'] ?? 0,
			$qrf_send_queued,
			$qrf_status,
			$qrf_identifiers ? 'yes' : 'no',
			$qrf_drain['runs'],
			Kuka_Island_Core_Invoice_Status::can_retry( $qrf_status ) ? 'YES' : 'no',
			$qrf_transport->calls['LoadInvoice'] ?? 0
		)
	);

	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $qrf_order_id );
	kuka_test_delete_order( $qrf_order_id, $test_run_id );

	/*
	 * (3) A genuine PRE-transmission transient error keeps its bounded retry.
	 *
	 * The manager's own send lock is held by a real second MySQL session, so
	 * process_order() raises lock_collision before it reaches routing, numbering
	 * or SendInvoice. Nothing is transmitted and no evidence is written, which is
	 * exactly the case that should still be retried -- and still stop.
	 */
	$qrc_transport = new Kuka_Island_Test_Tracking_Transport();
	$qrc_manager   = new Kuka_Island_Core_Invoice_Manager( $queue_config, new Kuka_Island_Core_EDM_Provider( $queue_config, $qrc_transport ) );
	$qrc_queue     = new Kuka_Island_Core_Invoice_Queue( $qrc_manager );
	$qrc_order     = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER        => 'KUK2026000000042',
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
		)
	);
	$qrc_order_id = (int) $qrc_order->get_id();

	$qrc_rival = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$qrc_lock_held = '1' === (string) $qrc_rival->get_var( $qrc_rival->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_inv_' . $qrc_order_id ) );

	$qrc_saved = $install_queue_worker( $qrc_queue );
	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $qrc_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	as_schedule_single_action( time(), Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $qrc_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	$qrc_drain = $drain_send_actions( $qrc_order_id );
	$restore_queue_worker( $qrc_saved );

	$qrc_reloaded    = wc_get_order( $qrc_order_id );
	$qrc_status      = Kuka_Island_Core_Invoice_Order_Store::get_status( $qrc_reloaded );
	$qrc_send_queued = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $qrc_order_id ) );
	$qrc_fiscal      = (int) $qrc_reloaded->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS, true );

	$report(
		'INVOICE_QUEUE_PRETRANSMISSION_RETRY_CAP',
		true === $qrc_lock_held
		&& Kuka_Island_Core_Invoice_Queue::MAX_RETRY_ATTEMPTS === $qrc_drain['runs']
		&& false === $qrc_drain['hit_limit']
		&& 0 === $qrc_send_queued
		&& Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW === $qrc_status
		// Nothing was transmitted, and the fiscal counter proves it: the queue's
		// budget is its own meta key, not this one.
		&& 0 === (int) ( $qrc_transport->calls['SendInvoice'] ?? 0 )
		&& 0 === $qrc_fiscal,
		sprintf(
			'failed_runs:%d|send_actions_pending=%d|status=%s|infinite_chain:%s|measured:real_queue_worker_on_action_scheduler|max_retry_attempts:%d|SendInvoice=%d|fiscal_send_attempts:%d|lock_held_by_second_session:%s',
			$qrc_drain['runs'],
			$qrc_send_queued,
			$qrc_status,
			$qrc_drain['hit_limit'] ? 'YES' : 'no',
			Kuka_Island_Core_Invoice_Queue::MAX_RETRY_ATTEMPTS,
			$qrc_transport->calls['SendInvoice'] ?? 0,
			$qrc_fiscal,
			$qrc_lock_held ? 'yes' : 'no'
		)
	);

	/*
	 * (4) The queue's retry counter is its own, and a successful run clears it.
	 *
	 * Same order, same held lock: one failed run leaves a count of 1 while the
	 * fiscal send-attempt counter is still 0. Releasing the lock lets the next
	 * run succeed, and the count goes away.
	 */
	$qcc_transport = new Kuka_Island_Test_Tracking_Transport();
	$qcc_manager   = new Kuka_Island_Core_Invoice_Manager( $queue_config, new Kuka_Island_Core_EDM_Provider( $queue_config, $qcc_transport ) );
	$qcc_queue     = new Kuka_Island_Core_Invoice_Queue( $qcc_manager );
	$qcc_order     = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER        => 'KUK2026000000042',
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
		)
	);
	$qcc_order_id = (int) $qcc_order->get_id();

	$qcc_rival = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$qcc_lock_held = '1' === (string) $qcc_rival->get_var( $qcc_rival->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_inv_' . $qcc_order_id ) );

	$qcc_saved = $install_queue_worker( $qcc_queue );
	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $qcc_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	as_schedule_single_action( time(), Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $qcc_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );

	// One failed run while the lock is held.
	$qcc_first = $drain_send_actions( $qcc_order_id, 1 );
	$qcc_mid   = wc_get_order( $qcc_order_id );
	$qcc_retry_after_failure  = (string) $qcc_mid->get_meta( Kuka_Island_Core_Invoice_Queue::META_QUEUE_RETRIES, true );
	$qcc_fiscal_after_failure = (string) $qcc_mid->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS, true );
	$qcc_queued_after_failure = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $qcc_order_id ) );

	// Release the lock so the retry succeeds.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$qcc_rival->get_var( $qcc_rival->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_inv_' . $qcc_order_id ) );

	$qcc_second = $drain_send_actions( $qcc_order_id );
	$restore_queue_worker( $qcc_saved );

	$qcc_reloaded    = wc_get_order( $qcc_order_id );
	$qcc_retry_after_success  = (string) $qcc_reloaded->get_meta( Kuka_Island_Core_Invoice_Queue::META_QUEUE_RETRIES, true );
	$qcc_fiscal_after_success = (int) $qcc_reloaded->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS, true );
	$qcc_status      = Kuka_Island_Core_Invoice_Order_Store::get_status( $qcc_reloaded );
	$qcc_send_queued = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $qcc_order_id ) );

	$report(
		'INVOICE_QUEUE_RETRY_COUNTER_CLEARED_ON_SUCCESS',
		true === $qcc_lock_held
		&& 1 === $qcc_first['runs']
		// The queue counted its own failure...
		&& '1' === $qcc_retry_after_failure
		// ...while the fiscal send-attempt counter stayed untouched. Those two
		// being the same key is what let the old cap never arrive.
		&& ( '' === $qcc_fiscal_after_failure || '0' === $qcc_fiscal_after_failure )
		&& 1 === $qcc_queued_after_failure
		// The retry then succeeded and the queue's count went away.
		&& 1 === $qcc_second['runs']
		&& false === $qcc_second['hit_limit']
		&& '' === $qcc_retry_after_success
		&& 1 === (int) ( $qcc_transport->calls['SendInvoice'] ?? 0 )
		&& 1 === $qcc_fiscal_after_success
		&& Kuka_Island_Core_Invoice_Status::STATUS_SENT === $qcc_status
		&& 0 === $qcc_send_queued,
		sprintf(
			'measured:real_queue_worker_on_action_scheduler|failed_runs:%d|queue_retries_after_failure:%s|fiscal_send_attempts_after_failure:%s|rescheduled:%d|successful_runs:%d|queue_retries_after_success:%s|fiscal_send_attempts_after_success:%d|SendInvoice=%d|status=%s|send_actions_pending=%d',
			$qcc_first['runs'],
			'' === $qcc_retry_after_failure ? 'none' : $qcc_retry_after_failure,
			'' === $qcc_fiscal_after_failure ? 'none' : $qcc_fiscal_after_failure,
			$qcc_queued_after_failure,
			$qcc_second['runs'],
			'' === $qcc_retry_after_success ? 'cleared' : $qcc_retry_after_success,
			$qcc_fiscal_after_success,
			$qcc_transport->calls['SendInvoice'] ?? 0,
			$qcc_status,
			$qcc_send_queued
		)
	);

	// Ownership-checked cleanup for both fixtures and every action they created.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$qrc_rival->get_var( $qrc_rival->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_inv_' . $qrc_order_id ) );
	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $qrc_order_id );
	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $qcc_order_id );
	kuka_test_delete_order( $qrc_order_id, $test_run_id );
	kuka_test_delete_order( $qcc_order_id, $test_run_id );
	/* ---------------------------------------------------------------------- */
	/* The queue retry counter belongs to one chain and outlives none of them  */
	/* ---------------------------------------------------------------------- */

	/**
	 * Transport for the chain-ownership cases.
	 *
	 * Normal answers by default; each flag turns one specific failure on.
	 */
	$queue_case_transport_class = new class() implements Kuka_Island_Core_SOAP_Transport_Interface {
		/** @var array<string, int> */
		public array $calls = array();
		/** @var bool Raise the SOAP timeout the client classifies as transient. */
		public bool $timeout_on_send = false;
		/** @var bool Raise a NON-Kuka exception, so the queue's generic catch runs. */
		public bool $generic_on_send = false;

		public function get_last_request(): string {
			return '';
		}

		public function get_last_response(): string {
			return '';
		}

		public function call( string $operation, array $parameters ) {
			$this->calls[ $operation ] = ( $this->calls[ $operation ] ?? 0 ) + 1;

			if ( 'Login' === $operation ) {
				return array( 'SESSION_ID' => 'session-queue-chain', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
			}

			if ( 'SendInvoice' === $operation ) {
				if ( $this->generic_on_send ) {
					throw new RuntimeException( 'Non-Kuka failure raised below the client.' );
				}
				if ( $this->timeout_on_send ) {
					throw new SoapFault( 'HTTP', 'Connection timed out after 30 seconds' );
				}

				return array(
					'INVOICE'        => array(
						'UUID' => $parameters['INVOICE'][0]['UUID'] ?? 'uuid-queue-chain',
						'ID'   => $parameters['INVOICE'][0]['ID'] ?? 'KUK-UNSET',
					),
					'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
				);
			}

			return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}
	};

	/*
	 * Every one of these starts the same way: a real lock_collision leaves the
	 * chain with a count of 1 and one pending action. Then the chain ends a
	 * different way each time, and the count must not survive any of them --
	 * a leftover 1 or 2 silently shortens the retry budget of whatever chain
	 * runs next for that order.
	 */
	$chain_exit_cases = array(
		// name => [ mutation, expected status, expected fiscal attempts, expected poll actions ]
		'permanent_pre_send'  => array( 'strip_billing_city', Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW, 0, 0 ),
		'evidence_handover'   => array( 'timeout_on_send', Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN, 1, 1 ),
		'generic_exception'   => array( 'generic_on_send', Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN, 1, 1 ),
		'auto_send_disabled'  => array( 'auto_send_off', Kuka_Island_Core_Invoice_Status::STATUS_NONE, 0, 0 ),
	);

	$chain_ok       = true;
	$chain_details  = array();
	$chain_first_ok = true;

	foreach ( $chain_exit_cases as $case => $spec ) {
		$chain_transport = clone $queue_case_transport_class;
		$chain_transport->calls = array();
		$chain_transport->timeout_on_send = false;
		$chain_transport->generic_on_send = false;

		$chain_manager = new Kuka_Island_Core_Invoice_Manager( $auto_send_ready, new Kuka_Island_Core_EDM_Provider( $auto_send_ready, $chain_transport ) );
		$chain_queue   = new Kuka_Island_Core_Invoice_Queue( $chain_manager );
		$chain_order   = kuka_create_lock_order(
			$test_run_id,
			$billing_props,
			array(
				Kuka_Island_Core_Invoice_Order_Store::META_NUMBER        => 'KUK2026000000042',
				Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
			)
		);
		$chain_order_id = (int) $chain_order->get_id();
		$chain_lock     = 'kuka_inv_' . $chain_order_id;

		$chain_rival = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$chain_held = '1' === (string) $chain_rival->get_var( $chain_rival->prepare( 'SELECT GET_LOCK(%s, 0)', $chain_lock ) );

		$chain_saved = $install_queue_worker( $chain_queue );
		as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $chain_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
		as_schedule_single_action( time(), Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $chain_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );

		// Run 1: a genuine pre-transmission transient.
		$chain_run1 = $drain_send_actions( $chain_order_id, 1 );
		$chain_mid  = wc_get_order( $chain_order_id );
		$mid_retries = (string) $chain_mid->get_meta( Kuka_Island_Core_Invoice_Queue::META_QUEUE_RETRIES, true );
		$mid_fiscal  = (string) $chain_mid->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS, true );
		$mid_pending = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $chain_order_id ) );

		if ( true !== $chain_held
			|| 1 !== $chain_run1['runs']
			|| '1' !== $mid_retries
			|| ! ( '' === $mid_fiscal || '0' === $mid_fiscal )
			|| 1 !== $mid_pending ) {
			$chain_first_ok = false;
		}

		// The lock goes, and the chain now ends this case's own way.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$chain_rival->get_var( $chain_rival->prepare( 'SELECT RELEASE_LOCK(%s)', $chain_lock ) );

		switch ( $spec[0] ) {
			case 'strip_billing_city':
				// A mandatory receiver field gone -> the mapper raises a
				// PERMANENT pre-transmission error, before mark_sending() and
				// before any transmission.
				$chain_mid->set_billing_city( '' );
				$chain_mid->save();
				break;

			case 'timeout_on_send':
				$chain_transport->timeout_on_send = true;
				break;

			case 'generic_on_send':
				$chain_transport->generic_on_send = true;
				break;

			case 'auto_send_off':
				// The same order, a worker whose config now says auto-send is
				// off. It owns nothing further and must leave nothing behind.
				$off_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $chain_transport ) );
				$restore_queue_worker( $chain_saved );
				$chain_saved = $install_queue_worker( new Kuka_Island_Core_Invoice_Queue( $off_manager ) );
				break;
		}

		// Run 2: the chain ends.
		$chain_run2 = $drain_send_actions( $chain_order_id );
		$restore_queue_worker( $chain_saved );

		$chain_after   = wc_get_order( $chain_order_id );
		$after_retries = (string) $chain_after->get_meta( Kuka_Island_Core_Invoice_Queue::META_QUEUE_RETRIES, true );
		$after_fiscal  = (int) $chain_after->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS, true );
		$after_status  = Kuka_Island_Core_Invoice_Order_Store::get_status( $chain_after );
		$after_pending = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $chain_order_id ) );
		$after_poll    = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS, $chain_order_id ) );

		$hit = 1 === $chain_run2['runs']
			&& false === $chain_run2['hit_limit']
			// The counter is gone, on every one of these exits.
			&& '' === $after_retries
			&& 0 === $after_pending
			&& $after_status === (string) $spec[1]
			&& $after_fiscal === (int) $spec[2]
			&& $after_poll === (int) $spec[3];

		$chain_details[] = $case . '=' . $after_status . '/retries:' . ( '' === $after_retries ? 'absent' : $after_retries ) . '/send_pending:' . $after_pending . '/poll_pending:' . $after_poll;
		if ( ! $hit ) {
			$chain_ok = false;
		}

		Kuka_Island_Core_Invoice_Status_Poller::unschedule( $chain_order_id );
		as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $chain_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
		kuka_test_delete_order( $chain_order_id, $test_run_id );
	}

	$report(
		'INVOICE_QUEUE_RETRY_META_CLEARED_ON_EVERY_CHAIN_EXIT',
		$chain_ok && $chain_first_ok,
		sprintf(
			'measured:real_queue_worker_on_action_scheduler|cases:%d|first_run_transient:%s|%s|fiscal_counter_untouched_by_queue:yes',
			count( $chain_exit_cases ),
			$chain_first_ok ? 'retries:1/fiscal:0/pending:1' : 'WRONG',
			implode( ' ', $chain_details )
		)
	);

	/*
	 * A new chain must not inherit an older chain's count. maybe_enqueue_order()
	 * is where a chain begins, so that is where the slate is wiped.
	 */
	$stale_transport = new Kuka_Island_Test_Tracking_Transport();
	$stale_manager   = new Kuka_Island_Core_Invoice_Manager( $auto_send_ready, new Kuka_Island_Core_EDM_Provider( $auto_send_ready, $stale_transport ) );
	$stale_queue     = new Kuka_Island_Core_Invoice_Queue( $stale_manager );
	$stale_order     = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER        => 'KUK2026000000042',
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
			// Residue from an earlier chain, one short of the cap.
			Kuka_Island_Core_Invoice_Queue::META_QUEUE_RETRIES        => '2',
		)
	);
	$stale_order_id  = (int) $stale_order->get_id();
	$stale_seeded    = (string) $stale_order->get_meta( Kuka_Island_Core_Invoice_Queue::META_QUEUE_RETRIES, true );

	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $stale_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );

	// The real enqueue entry point, on a settled, non-fixture order.
	$stale_queue->maybe_enqueue_order( $stale_order_id, wc_get_order( $stale_order_id ) );

	$stale_after_enqueue = wc_get_order( $stale_order_id );
	$stale_cleared       = (string) $stale_after_enqueue->get_meta( Kuka_Island_Core_Invoice_Queue::META_QUEUE_RETRIES, true );
	$stale_status        = Kuka_Island_Core_Invoice_Order_Store::get_status( $stale_after_enqueue );
	$stale_queued        = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $stale_order_id ) );

	// The status stays exactly as the real enqueue left it: queued. Nothing here
	// rewrites production state, so the worker run below is the real one.
	$stale_rival = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$stale_held = '1' === (string) $stale_rival->get_var( $stale_rival->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_inv_' . $stale_order_id ) );

	$stale_saved = $install_queue_worker( $stale_queue );
	$stale_run   = $drain_send_actions( $stale_order_id, 1 );
	$restore_queue_worker( $stale_saved );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$stale_rival->get_var( $stale_rival->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_inv_' . $stale_order_id ) );

	$stale_final   = wc_get_order( $stale_order_id );
	$stale_retries = (string) $stale_final->get_meta( Kuka_Island_Core_Invoice_Queue::META_QUEUE_RETRIES, true );
	$stale_pending = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $stale_order_id ) );
	$stale_status_final = Kuka_Island_Core_Invoice_Order_Store::get_status( $stale_final );

	$report(
		'INVOICE_QUEUE_NEW_CHAIN_STARTS_AT_ZERO',
		'2' === $stale_seeded
		// The enqueue wiped the residue before scheduling anything.
		&& '' === $stale_cleared
		&& Kuka_Island_Core_Invoice_Status::STATUS_QUEUED === $stale_status
		&& 1 === $stale_queued
		&& true === $stale_held
		&& 1 === $stale_run['runs']
		// The new chain's first transient counts 1, not 3: the old value is not
		// inherited, so this chain gets its full budget.
		&& '1' === $stale_retries
		&& 1 === $stale_pending
		&& 0 === (int) ( $stale_transport->calls['SendInvoice'] ?? 0 )
		// The real queued status carried the run: no manual rewrite here.
		&& Kuka_Island_Core_Invoice_Status::STATUS_QUEUED === $stale_status_final,
		sprintf(
			'measured:real_enqueue_plus_real_queue_worker_on_action_scheduler|seeded:%s|after_enqueue:%s|status_after_enqueue:%s|actions_after_enqueue:%d|first_transient_retries:%s|send_actions_pending:%d|SendInvoice=%d|status_after_worker:%s|manual_status_rewrite:none',
			$stale_seeded,
			'' === $stale_cleared ? 'cleared' : $stale_cleared,
			$stale_status,
			$stale_queued,
			'' === $stale_retries ? 'absent' : $stale_retries,
			$stale_pending,
			$stale_transport->calls['SendInvoice'] ?? 0,
			$stale_status_final
		)
	);

	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $stale_order_id );
	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $stale_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	kuka_test_delete_order( $stale_order_id, $test_run_id );

	/* ---------------------------------------------------------------------- */
	/* The real automatic path, end to end, with nothing rewritten by hand     */
	/* ---------------------------------------------------------------------- */

	/*
	 * maybe_enqueue_order() writes STATUS_QUEUED and schedules
	 * ACTION_PROCESS_INVOICE. The worker's unforced process_order() call then met
	 * a gate built only from can_retry(), which does not list 'queued' -- so the
	 * automatic path refused every order it had just queued, with
	 * invalid_invoice_status_transition, and SendInvoice was never called.
	 *
	 * This runs the production chain exactly as it stands: the real enqueue entry
	 * point, the real Action Scheduler action, the real worker. No status, meta or
	 * counter is touched by the test between the two steps, and force is never
	 * used.
	 */
	$e2e_transport = new Kuka_Island_Test_Tracking_Transport();
	$e2e_manager   = new Kuka_Island_Core_Invoice_Manager( $auto_send_ready, new Kuka_Island_Core_EDM_Provider( $auto_send_ready, $e2e_transport ) );
	$e2e_queue     = new Kuka_Island_Core_Invoice_Queue( $e2e_manager );
	$e2e_order     = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER        => 'KUK2026000000042',
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
		)
	);
	$e2e_order_id = (int) $e2e_order->get_id();

	// Nothing has been transmitted, and the order is not a fixture-marked one.
	$e2e_evidence_before = Kuka_Island_Core_Invoice_Manager::transmission_evidence( $e2e_order );
	$e2e_is_fixture      = Kuka_Island_Core_Invoice_Fixture_Guard::is_test_fixture_order( $e2e_order );
	$e2e_settled         = $e2e_manager->is_order_settled( $e2e_order );

	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $e2e_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $e2e_order_id );

	// Step 1: the real enqueue.
	$e2e_queue->maybe_enqueue_order( $e2e_order_id, wc_get_order( $e2e_order_id ) );

	$e2e_after_enqueue  = wc_get_order( $e2e_order_id );
	$e2e_status_queued  = Kuka_Island_Core_Invoice_Order_Store::get_status( $e2e_after_enqueue );
	$e2e_actions_queued = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $e2e_order_id ) );

	// Step 2: the real worker, on the real action. The status is left alone.
	$e2e_saved = $install_queue_worker( $e2e_queue );
	$e2e_drain = $drain_send_actions( $e2e_order_id );
	$restore_queue_worker( $e2e_saved );

	$e2e_final        = wc_get_order( $e2e_order_id );
	$e2e_status_final = Kuka_Island_Core_Invoice_Order_Store::get_status( $e2e_final );
	$e2e_retry_meta   = (string) $e2e_final->get_meta( Kuka_Island_Core_Invoice_Queue::META_QUEUE_RETRIES, true );
	$e2e_fiscal       = (int) $e2e_final->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS, true );
	$e2e_last_error   = (string) $e2e_final->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, true );
	$e2e_send_pending = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $e2e_order_id ) );
	$e2e_poll_pending = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS, $e2e_order_id ) );

	$report(
		'INVOICE_AUTO_SEND_QUEUED_ORDER_REACHES_SEND',
		array() === $e2e_evidence_before
		&& false === $e2e_is_fixture
		&& true === $e2e_settled
		&& Kuka_Island_Core_Invoice_Status::STATUS_QUEUED === $e2e_status_queued
		&& 1 === $e2e_actions_queued
		&& 1 === $e2e_drain['runs']
		&& false === $e2e_drain['hit_limit']
		// The document was actually transmitted, once.
		&& 1 === (int) ( $e2e_transport->calls['SendInvoice'] ?? 0 )
		&& 0 === (int) ( $e2e_transport->calls['LoadInvoice'] ?? 0 )
		// The mock answers with no HEADER STATUS, so the contract's own outcome
		// for "accepted, not yet described" is 'sent' -- a polling job.
		&& Kuka_Island_Core_Invoice_Status::STATUS_SENT === $e2e_status_final
		&& '' === $e2e_last_error
		&& 0 === $e2e_send_pending
		&& '' === $e2e_retry_meta
		&& 1 === $e2e_fiscal
		// And the status query is booked on the poller's own action.
		&& 1 === $e2e_poll_pending,
		sprintf(
			'measured:real_enqueue_plus_real_queue_worker_on_action_scheduler|status_after_enqueue=%s|send_actions_after_enqueue=%d|worker_runs=%d|SendInvoice=%d|LoadInvoice=%d|status_after_worker=%s|send_actions_pending=%d|queue_retry_meta=%s|fiscal_send_attempts=%d|poll_actions_pending=%d|last_error=%s|manual_status_rewrite:none|force_used:no',
			$e2e_status_queued,
			$e2e_actions_queued,
			$e2e_drain['runs'],
			$e2e_transport->calls['SendInvoice'] ?? 0,
			$e2e_transport->calls['LoadInvoice'] ?? 0,
			$e2e_status_final,
			$e2e_send_pending,
			'' === $e2e_retry_meta ? 'absent' : $e2e_retry_meta,
			$e2e_fiscal,
			$e2e_poll_pending,
			'' === $e2e_last_error ? 'none' : $e2e_last_error
		)
	);

	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $e2e_order_id );
	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $e2e_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	kuka_test_delete_order( $e2e_order_id, $test_run_id );

	/*
	 * Letting the worker start from 'queued' must not put a re-send button in
	 * front of an operator for an order the queue already owns. can_retry() is
	 * what the order screen consults, and 'queued' is deliberately absent from
	 * it; may_start_transmission() is a separate question with a separate answer.
	 */
	$queued_order = kuka_create_lock_order(
		$test_run_id,
		$billing_props,
		array(
			Kuka_Island_Core_Invoice_Order_Store::META_STATUS         => Kuka_Island_Core_Invoice_Status::STATUS_QUEUED,
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER        => 'KUK2026000000042',
			Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
		)
	);
	$queued_order_id = (int) $queued_order->get_id();
	$queued_status   = Kuka_Island_Core_Invoice_Order_Store::get_status( $queued_order );

	// The exact expression the order screen and the manual-send handler use.
	$queued_admin_offers = array() === Kuka_Island_Core_Invoice_Manager::transmission_evidence( $queued_order )
		&& Kuka_Island_Core_Invoice_Status::can_retry( $queued_status );

	// The duplicate-enqueue guard still recognises a queued order.
	$queued_enqueue_transport = new Kuka_Island_Test_Tracking_Transport();
	$queued_enqueue_manager   = new Kuka_Island_Core_Invoice_Manager( $auto_send_ready, new Kuka_Island_Core_EDM_Provider( $auto_send_ready, $queued_enqueue_transport ) );
	$queued_enqueue_queue     = new Kuka_Island_Core_Invoice_Queue( $queued_enqueue_manager );
	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $queued_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	$queued_enqueue_queue->maybe_enqueue_order( $queued_order_id, wc_get_order( $queued_order_id ) );
	$queued_double_actions = count( $hook_pending_ids( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, $queued_order_id ) );

	$report(
		'INVOICE_QUEUED_STATUS_DOES_NOT_ENABLE_ADMIN_RESEND',
		false === Kuka_Island_Core_Invoice_Status::can_retry( Kuka_Island_Core_Invoice_Status::STATUS_QUEUED )
		&& false === $queued_admin_offers
		&& true === Kuka_Island_Core_Invoice_Status::is_in_progress( Kuka_Island_Core_Invoice_Status::STATUS_QUEUED )
		// The worker, and only the worker, may start from it.
		&& true === Kuka_Island_Core_Invoice_Manager::may_start_transmission( Kuka_Island_Core_Invoice_Status::STATUS_QUEUED )
		// A queued order is not enqueued a second time.
		&& 0 === $queued_double_actions
		&& 0 === (int) ( $queued_enqueue_transport->calls['SendInvoice'] ?? 0 ),
		sprintf(
			'measured:production_predicates_and_real_enqueue|can_retry(queued)=%s|admin_offers_send=%s|is_in_progress(queued)=%s|may_start_transmission(queued)=%s|duplicate_enqueue_actions=%d',
			Kuka_Island_Core_Invoice_Status::can_retry( Kuka_Island_Core_Invoice_Status::STATUS_QUEUED ) ? 'true' : 'false',
			$queued_admin_offers ? 'yes' : 'no',
			Kuka_Island_Core_Invoice_Status::is_in_progress( Kuka_Island_Core_Invoice_Status::STATUS_QUEUED ) ? 'true' : 'false',
			Kuka_Island_Core_Invoice_Manager::may_start_transmission( Kuka_Island_Core_Invoice_Status::STATUS_QUEUED ) ? 'true' : 'false',
			$queued_double_actions
		)
	);

	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $queued_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	kuka_test_delete_order( $queued_order_id, $test_run_id );
}

/* -------------------------------------------------------------------------- */
/* INTERNETSALESDETAILS                                                        */
/* -------------------------------------------------------------------------- */

$isd_base = array(
	'web_address'     => 'https://kukaisland.com',
	// The WooCommerce gateway ID, not its checkout title.
	'payment_gateway' => 'iyzico',
	'payment_date'    => '2026-08-30',
	'shipment_state'  => Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_COMPLETE,
	'shipment_date'   => '2026-08-31',
	'carrier_vkn'     => '1234567890',
	'carrier_title'   => 'Kargo A.S.',
);

$isd_cases = array(
	'complete_shipment'   => array( array(), true, '' ),
	'digital_no_shipment' => array( array( 'shipment_state' => Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_NONE, 'shipment_date' => '', 'carrier_vkn' => '', 'carrier_title' => '' ), true, '' ),
	'partial_shipment'    => array( array( 'shipment_state' => Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_PARTIAL ), false, Kuka_Island_Core_Internet_Sales_Details::ERROR_SHIPMENT_PARTIAL ),
	'pending_shipment'    => array( array( 'shipment_state' => Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_PENDING ), false, Kuka_Island_Core_Internet_Sales_Details::ERROR_SHIPMENT_PENDING ),
	'no_payment_date'     => array( array( 'payment_date' => '' ), false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_DATE_MISSING ),
	'no_shipment_date'    => array( array( 'shipment_date' => '' ), false, Kuka_Island_Core_Internet_Sales_Details::ERROR_SHIPMENT_DATE_MISSING ),
	'no_carrier_vkn'      => array( array( 'carrier_vkn' => '' ), false, Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_VKN_MISSING ),
	'no_carrier_title'    => array( array( 'carrier_title' => '' ), false, Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_TITLE_MISSING ),
	'no_web_address'      => array( array( 'web_address' => '' ), false, Kuka_Island_Core_Internet_Sales_Details::ERROR_WEB_ADDRESS_MISSING ),
	'no_payment_gateway'  => array( array( 'payment_gateway' => '' ), false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_MISSING ),
	'unmapped_gateway'    => array( array( 'payment_gateway' => 'bacs' ), false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED ),
	'gateway_title'       => array( array( 'payment_gateway' => 'Banka/Kredi Kartı ile Öde' ), false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED ),
);

$isd_ok      = true;
$isd_details = array();
foreach ( $isd_cases as $case => $spec ) {
	$built = Kuka_Island_Core_Internet_Sales_Details::build( array_merge( $isd_base, $spec[0] ) );
	$hit   = $built['ok'] === $spec[1]
		&& ( '' === $spec[2] || in_array( $spec[2], $built['errors'], true ) )
		// A refused build yields no partial block.
		&& ( $built['ok'] || array() === $built['details'] );
	$isd_details[] = $case . '=' . ( $built['ok'] ? 'built' : 'refused' );
	if ( ! $hit ) {
		$isd_ok = false;
	}
}

$isd_full  = Kuka_Island_Core_Internet_Sales_Details::build( $isd_base );
$isd_ship  = $isd_full['details']['gonderiBilgileri'] ?? array();
$isd_shape = '2026-08-30' === ( $isd_full['details']['odemeTarihi'] ?? '' )
	&& '2026-08-31' === ( $isd_ship['gonderimTarihi'] ?? '' )
	&& '1234567890' === ( $isd_ship['gonderiTasiyan']['tuzelKisi']['vkn'] ?? '' )
	&& 'Kargo A.S.' === ( $isd_ship['gonderiTasiyan']['tuzelKisi']['unvan'] ?? '' );

$report(
	'INVOICE_INTERNET_SALES_DETAILS_CONTRACT',
	$isd_ok && $isd_shape,
	sprintf(
		'cases:%d|%s|shape:%s',
		count( $isd_cases ),
		implode( ' ', $isd_details ),
		$isd_shape ? 'ok' : 'WRONG'
	)
);

/*
 * odemeSekli is a fiscal enumeration, so a nonempty payment string is not a
 * licence to write it there. Two doors are measured: the gateway lookup, and
 * the pair validator any producer has to pass.
 */
$isd_iyzico = Kuka_Island_Core_Internet_Sales_Details::payment_for_gateway( 'iyzico' );
$isd_pwi    = Kuka_Island_Core_Internet_Sales_Details::payment_for_gateway( 'pwi' );

$gateway_cases = array(
	// Both iyzico gateways: the intermediary collected the money.
	'iyzico_checkout' => array( 'iyzico', true, '', 'ODEMEARACISI', 'iyzico' ),
	'iyzico_pwi'      => array( 'pwi', true, '', 'ODEMEARACISI', 'iyzico' ),
	'iyzico_uppercase' => array( 'IYZICO', true, '', 'ODEMEARACISI', 'iyzico' ),
	'iyzico_padded'   => array( "  iyzico\t", true, '', 'ODEMEARACISI', 'iyzico' ),
	// Empty is a missing fact, not a default.
	'empty'           => array( '', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_MISSING, '', '' ),
	// Real WooCommerce gateways with no confirmed EDM literal. Refused, not guessed.
	'bacs'            => array( 'bacs', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED, '', '' ),
	'cod'             => array( 'cod', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED, '', '' ),
	'cheque'          => array( 'cheque', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED, '', '' ),
	// User-facing checkout titles, which are what a get_payment_method_title()
	// call would hand over. None of them is a gateway id or a fiscal literal.
	'title_card'      => array( 'Banka/Kredi Kartı ile Öde', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED, '', '' ),
	'title_credit'    => array( 'Kredi kartı ile öde', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED, '', '' ),
	'title_pwi'       => array( 'iyzico ile öde', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED, '', '' ),
	'title_transfer'  => array( 'Banka havalesi/EFT', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED, '', '' ),
	// A fiscal literal is not a gateway id either.
	'literal_as_id'   => array( 'ODEMEARACISI', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED, '', '' ),
);

$gateway_ok      = true;
$gateway_details = array();
foreach ( $gateway_cases as $case => $spec ) {
	$resolved = Kuka_Island_Core_Internet_Sales_Details::payment_for_gateway( $spec[0] );
	$hit      = $resolved['ok'] === $spec[1]
		&& $resolved['error'] === $spec[2]
		&& $resolved['odemeSekli'] === $spec[3]
		&& $resolved['odemeAracisiAdi'] === $spec[4];
	$gateway_details[] = $case . '=' . ( $resolved['ok'] ? $resolved['odemeSekli'] : ( $resolved['error'] ?: 'refused' ) );
	if ( ! $hit ) {
		$gateway_ok = false;
	}
}

$pair_cases = array(
	'intermediary_named'  => array( 'ODEMEARACISI', 'iyzico', true, '' ),
	// ODEMEARACISI states somebody else collected the money; a blank name
	// leaves that half-stated, so it is refused.
	'intermediary_blank'  => array( 'ODEMEARACISI', '', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_AGENT_MISSING ),
	'intermediary_spaces' => array( 'ODEMEARACISI', '   ', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_AGENT_MISSING ),
	// A gateway slug, a checkout title and an unproven literal are all refused
	// as odemeSekli values.
	'gateway_slug'        => array( 'iyzico', 'iyzico', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_NOT_FISCAL ),
	'checkout_title'      => array( 'Kredi kartı ile öde', 'iyzico', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_NOT_FISCAL ),
	'unproven_literal'    => array( 'KREDIKARTI', '', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_NOT_FISCAL ),
	'unproven_literal_2'  => array( 'EFT/HAVALE', '', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_NOT_FISCAL ),
	'lowercase_literal'   => array( 'odemearacisi', 'iyzico', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_NOT_FISCAL ),
	'empty_literal'       => array( '', 'iyzico', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_MISSING ),
);

$pair_ok      = true;
$pair_details = array();
foreach ( $pair_cases as $case => $spec ) {
	$verdict = Kuka_Island_Core_Internet_Sales_Details::validate_payment( $spec[0], $spec[1] );
	$hit     = $verdict['ok'] === $spec[2] && $verdict['error'] === $spec[3];
	$pair_details[] = $case . '=' . ( $verdict['ok'] ? 'accepted' : ( $verdict['error'] ?: 'refused' ) );
	if ( ! $hit ) {
		$pair_ok = false;
	}
}

// Every refused gateway must also refuse the whole block, with no partial
// details left behind.
$refused_titles       = array( 'Banka/Kredi Kartı ile Öde', 'Kredi kartı ile öde', 'iyzico ile öde', 'Banka havalesi/EFT', 'Kapıda ödeme', 'PayPal', 'ODEMEARACISI' );
$refused_title_count  = 0;
$refused_title_leaks  = array();
foreach ( $refused_titles as $title ) {
	$built = Kuka_Island_Core_Internet_Sales_Details::build( array_merge( $isd_base, array( 'payment_gateway' => $title ) ) );
	if ( false === $built['ok'] && array() === $built['details'] ) {
		++$refused_title_count;
	} else {
		$refused_title_leaks[] = $title;
	}
}

// The built block carries the fiscal literal, never the gateway id or a title,
// and carries no *Specified companion key: the WSDL has no such element.
$isd_iyzico_block = Kuka_Island_Core_Internet_Sales_Details::build( $isd_base );

/**
 * Collect every key in a nested block, at any depth.
 *
 * @param array<string, mixed> $node Block or sub-block.
 * @return array<int, string>
 */
$collect_keys = static function ( array $node ) use ( &$collect_keys ): array {
	$keys = array();
	foreach ( $node as $key => $value ) {
		$keys[] = (string) $key;
		if ( is_array( $value ) ) {
			$keys = array_merge( $keys, $collect_keys( $value ) );
		}
	}

	return $keys;
};

$specified_keys = array_values(
	array_filter(
		$collect_keys( (array) $isd_iyzico_block['details'] ),
		static fn( string $key ): bool => str_ends_with( $key, 'Specified' )
	)
);

$isd_class_source = (string) file_get_contents( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/class-internet-sales-details.php' );
// A *Specified companion key written as an array key anywhere in the producer.
// The class docblock mentions the name to explain why it is absent, which is
// why the scan looks for a key assignment rather than the bare word.
$specified_source_hits = preg_match( '/[\'"][A-Za-z_]*Specified[\'"]\s*=>/', $isd_class_source );

$report(
	'INVOICE_INTERNET_SALES_PAYMENT_CONTRACT',
	$gateway_ok
	&& $pair_ok
	&& count( $refused_titles ) === $refused_title_count
	&& array() === $specified_keys
	// A single confirmed literal; nothing was invented alongside it.
	&& array( 'ODEMEARACISI' ) === Kuka_Island_Core_Internet_Sales_Details::fiscal_payment_literals()
	&& 'ODEMEARACISI' === ( $isd_iyzico_block['details']['odemeSekli'] ?? '' )
	&& 'iyzico' === ( $isd_iyzico_block['details']['odemeAracisiAdi'] ?? '' )
	// The gateway id itself never reaches the fiscal field.
	&& 'iyzico' !== ( $isd_iyzico_block['details']['odemeSekli'] ?? '' )
	&& $isd_iyzico['ok']
	&& $isd_pwi['ok']
	// The class reads the gateway id, never the shop-editable title. The
	// docblock names the title getter to say why it is not used, so the scan
	// looks for an actual call.
	&& ! str_contains( $isd_class_source, '->get_payment_method_title(' )
	&& 0 === $specified_source_hits,
	sprintf(
		'gateway_cases:%d|%s|pair_cases:%d|%s|nonempty_titles_refused:%d/%d%s|odemeSekli:%s|odemeAracisiAdi:%s|fiscal_literals:%s|specified_keys:%s|reads_title:%s',
		count( $gateway_cases ),
		implode( ' ', $gateway_details ),
		count( $pair_cases ),
		implode( ' ', $pair_details ),
		$refused_title_count,
		count( $refused_titles ),
		empty( $refused_title_leaks ) ? '' : '|LEAKED:' . implode( ',', $refused_title_leaks ),
		(string) ( $isd_iyzico_block['details']['odemeSekli'] ?? 'absent' ),
		(string) ( $isd_iyzico_block['details']['odemeAracisiAdi'] ?? 'absent' ),
		implode( ',', Kuka_Island_Core_Internet_Sales_Details::fiscal_payment_literals() ),
		empty( $specified_keys ) ? 'none' : implode( ',', $specified_keys ),
		str_contains( $isd_class_source, '->get_payment_method_title(' ) ? 'YES' : 'no'
	)
);

// The gateway id is read from WooCommerce, and a real iyzico order resolves to
// the intermediary literal end to end.
$gateway_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'status' => 'processing' ) ) );
$gateway_order->set_payment_method( 'iyzico' );
$gateway_order->set_payment_method_title( 'Banka/Kredi Kartı ile Öde' );
$gateway_order->save();

$read_gateway   = Kuka_Island_Core_Internet_Sales_Details::read_payment_gateway( wc_get_order( $gateway_order->get_id() ) );
$read_resolved  = Kuka_Island_Core_Internet_Sales_Details::payment_for_gateway( $read_gateway );

$report(
	'INVOICE_INTERNET_SALES_GATEWAY_SOURCE',
	'iyzico' === $read_gateway
	&& 'Banka/Kredi Kartı ile Öde' !== $read_gateway
	&& $read_resolved['ok']
	&& 'ODEMEARACISI' === $read_resolved['odemeSekli']
	&& 'iyzico' === $read_resolved['odemeAracisiAdi'],
	sprintf(
		'measured:real_order|gateway_id:%s|gateway_title:%s|odemeSekli:%s|odemeAracisiAdi:%s',
		$read_gateway,
		(string) wc_get_order( $gateway_order->get_id() )->get_payment_method_title(),
		$read_resolved['odemeSekli'] ?: 'none',
		$read_resolved['odemeAracisiAdi'] ?: 'none'
	)
);

kuka_test_delete_order( $gateway_order->get_id(), $test_run_id );

// The producer is now ON the transmission path, at exactly one orchestration
// point: the manager builds the block and hands it to the client, which
// serialises it into SendInvoiceRequest/INVOICE/HEADER/INTERNETSALESDETAILS.
$isd_orchestrators = array();
foreach ( array( 'class-invoice-manager.php', 'class-edm-client.php' ) as $isd_file ) {
	$isd_path = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/' . $isd_file;
	if ( is_readable( $isd_path ) && str_contains( (string) file_get_contents( $isd_path ), 'internet_sales_details' ) ) {
		$isd_orchestrators[] = $isd_file;
	}
}

// And nowhere else: the mapper, the UBL builder, the queue and the poller have
// no business producing or reshaping a fiscal block.
$isd_stray = array();
foreach ( array( 'class-invoice-order-mapper.php', 'class-ubl-tr-builder.php', 'class-invoice-queue.php', 'class-invoice-status-poller.php' ) as $isd_file ) {
	$isd_path = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/' . $isd_file;
	if ( is_readable( $isd_path ) && str_contains( (string) file_get_contents( $isd_path ), 'Internet_Sales_Details::build' ) ) {
		$isd_stray[] = $isd_file;
	}
}

$report(
	'INVOICE_INTERNET_SALES_WIRED_AT_ONE_POINT',
	array( 'class-invoice-manager.php', 'class-edm-client.php' ) === $isd_orchestrators
	&& array() === $isd_stray,
	sprintf(
		'orchestration_points:%s|stray_producers:%s',
		empty( $isd_orchestrators ) ? 'NONE' : implode( ',', $isd_orchestrators ),
		empty( $isd_stray ) ? 'none' : implode( ',', $isd_stray )
	)
);

// The payment date must come from get_date_paid(), never from the creation
// date. Proved against two real orders: one paid on a different day, one unpaid.
$paid_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'status' => 'processing' ) ) );
$paid_order->set_date_created( '2026-08-01 09:00:00' );
$paid_order->set_date_paid( '2026-08-05 14:30:00' );
$paid_order->save();

$unpaid_order = kuka_create_test_order( $test_run_id, array_merge( $billing_props, array( 'status' => 'pending', 'paid_date' => '' ) ) );
$unpaid_order->set_date_created( '2026-08-01 09:00:00' );
$unpaid_order->save();

$paid_date   = Kuka_Island_Core_Internet_Sales_Details::read_payment_date( wc_get_order( $paid_order->get_id() ) );
$unpaid_date = Kuka_Island_Core_Internet_Sales_Details::read_payment_date( wc_get_order( $unpaid_order->get_id() ) );
$unpaid_build = Kuka_Island_Core_Internet_Sales_Details::build( array_merge( $isd_base, array( 'payment_date' => $unpaid_date ) ) );

$report(
	'INVOICE_INTERNET_SALES_PAYMENT_DATE_SOURCE',
	'2026-08-05' === $paid_date
	&& '2026-08-01' !== $paid_date
	&& '' === $unpaid_date
	&& false === $unpaid_build['ok']
	&& in_array( Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_DATE_MISSING, $unpaid_build['errors'], true ),
	sprintf(
		'measured:real_orders|created:2026-08-01|paid:%s|equals_created:%s|unpaid_date:%s|unpaid_build:%s',
		$paid_date,
		'2026-08-01' === $paid_date ? 'YES' : 'no',
		'' === $unpaid_date ? 'empty' : $unpaid_date,
		$unpaid_build['ok'] ? 'BUILT' : 'refused'
	)
);

// The carrier identity is never inferred from a provider label.
$isd_source = (string) file_get_contents( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/class-internet-sales-details.php' );
$invented   = array();
foreach ( array( 'DHL', 'dhl', 'Yurtici', 'Aras', 'MNG', 'PTT', 'UPS' ) as $carrier ) {
	if ( preg_match( '/[\'"]' . preg_quote( $carrier, '/' ) . '[\'"]\s*=>/', $isd_source ) ) {
		$invented[] = $carrier;
	}
}
$facts_shape = Kuka_Island_Core_Internet_Sales_Details::read_shipment_facts( wc_get_order( $unpaid_order->get_id() ) );

$report(
	'INVOICE_CARRIER_IDENTITY_NEVER_INVENTED',
	array() === $invented
	// The reader returns labels and dates only: no VKN or title is produced.
	&& ! array_key_exists( 'carrier_vkn', (array) $facts_shape )
	&& ! array_key_exists( 'carrier_title', (array) $facts_shape )
	&& array_key_exists( 'provider_label', (array) $facts_shape ),
	sprintf(
		'carrier_lookup_table:%s|reader_emits_vkn:%s|reader_emits_title:%s|reader_keys:%s',
		empty( $invented ) ? 'none' : implode( ',', $invented ),
		array_key_exists( 'carrier_vkn', (array) $facts_shape ) ? 'YES' : 'no',
		array_key_exists( 'carrier_title', (array) $facts_shape ) ? 'YES' : 'no',
		implode( ',', array_keys( (array) $facts_shape ) )
	)
);

kuka_test_delete_order( $paid_order->get_id(), $test_run_id );
kuka_test_delete_order( $unpaid_order->get_id(), $test_run_id );

/* ========================================================================== */
/* EDM-confirmed numbering, delivery and failed-document recovery             */
/* ========================================================================== */

/**
 * SendInvoice answers with a chosen STATUS and a chosen (or absent) ID.
 */
final class Kuka_Island_Test_Numbering_Transport implements Kuka_Island_Core_SOAP_Transport_Interface {
	/** @var array<string, int> */
	public array $calls = array();
	/** @var array<string, array<string, mixed>> Last request per operation. */
	public array $requests = array();
	/** @var string STATUS literal for the SendInvoice answer. */
	public string $status_literal = 'SEND - SUCCEED';
	/** @var string|null ID to return, or null to omit the attribute entirely. */
	public ?string $assigned_id = 'EDM2026000000777';

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}

	public function call( string $operation, array $parameters ) {
		$this->calls[ $operation ]    = ( $this->calls[ $operation ] ?? 0 ) + 1;
		$this->requests[ $operation ] = $parameters;

		if ( 'Login' === $operation ) {
			return array( 'SESSION_ID' => 'session-numbering-fixture', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}

		if ( 'SendInvoice' === $operation ) {
			$invoice = array(
				'UUID'   => $parameters['INVOICE'][0]['UUID'] ?? 'uuid-numbering',
				'HEADER' => array( 'STATUS' => $this->status_literal ),
			);
			if ( null !== $this->assigned_id ) {
				$invoice['ID'] = $this->assigned_id;
			}

			return array(
				'INVOICE'        => $invoice,
				'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			);
		}

		if ( 'GetInvoiceStatus' === $operation ) {
			$entry = array(
				'UUID'   => $parameters['INVOICE']['UUID'] ?? 'uuid-numbering',
				'HEADER' => array( 'STATUS' => $this->status_literal ),
			);
			if ( null !== $this->assigned_id ) {
				$entry['ID'] = $this->assigned_id;
			}

			return array( 'INVOICE_STATUS' => array( $entry ) );
		}

		return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
	}
}

/*
 * A positive STATUS is not a finished document if EDM has not numbered it. Such
 * a response becomes a polling job so the number is picked up by the next
 * GetInvoiceStatus, instead of the invoice being closed without one.
 */
$completion_cases = array(
	// name => [ returned ID, expected lifecycle, expected persisted number ]
	'assigned_number'  => array( 'EDM2026000000777', Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED, 'EDM2026000000777' ),
	'no_number'        => array( null, Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, '' ),
	'sentinel_echoed'  => array( Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL, Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, '' ),
	'empty_number'     => array( '', Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, '' ),
);

$completion_ok      = true;
$completion_details = array();
$completion_sends   = 0;

foreach ( $completion_cases as $case => $spec ) {
	$completion_transport = new Kuka_Island_Test_Numbering_Transport();
	$completion_transport->assigned_id = $spec[0];
	$completion_manager  = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $completion_transport ) );
	$completion_order    = kuka_create_lock_order( $test_run_id, $billing_props, array() );
	$completion_order_id = (int) $completion_order->get_id();

	try {
		$completion_manager->process_order( $completion_order );
	} catch ( Throwable $t ) {
		unset( $t );
	}

	$completion_order->read_meta_data( true );
	$completion_data = Kuka_Island_Core_Invoice_Order_Store::get_invoice_data( $completion_order );
	$completion_sends += (int) ( $completion_transport->calls['SendInvoice'] ?? 0 );

	$hit = $completion_data['status'] === (string) $spec[1]
		&& $completion_data['invoice_number'] === (string) $spec[2]
		&& 1 === (int) ( $completion_transport->calls['SendInvoice'] ?? 0 )
		// A document with no number is never presented as successful.
		&& ( '' !== (string) $spec[2] || ! Kuka_Island_Core_Invoice_Status::is_successful( $completion_data['status'] ) );

	$completion_details[] = $case . '=' . $completion_data['status'] . '/' . ( '' === $completion_data['invoice_number'] ? 'no_number' : $completion_data['invoice_number'] );
	if ( ! $hit ) {
		$completion_ok = false;
	}

	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $completion_order_id );
	kuka_test_delete_order( $completion_order_id, $test_run_id );
}

$report(
	'INVOICE_COMPLETION_REQUIRES_ASSIGNED_NUMBER',
	$completion_ok && count( $completion_cases ) === $completion_sends,
	sprintf(
		'measured:production_send|cases:%d|%s|SendInvoice=%d|sentinel:%s',
		count( $completion_cases ),
		implode( ' ', $completion_details ),
		$completion_sends,
		Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL
	)
);

/*
 * The sentinel is a request, not an identifier. It must never end up on an order
 * as a document number -- not from the send path, and not from a direct store
 * call either.
 */
$sentinel_transport = new Kuka_Island_Test_Numbering_Transport();
$sentinel_transport->assigned_id = Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL;
$sentinel_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $sentinel_transport ) );
$sentinel_order   = kuka_create_lock_order( $test_run_id, $billing_props, array() );
$sentinel_order_id = (int) $sentinel_order->get_id();

try {
	$sentinel_manager->process_order( $sentinel_order );
} catch ( Throwable $t ) {
	unset( $t );
}

$sentinel_order->read_meta_data( true );
$sentinel_number = (string) $sentinel_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, true );
$sentinel_source = (string) $sentinel_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE, true );
$sentinel_ubl    = (string) ( $sentinel_transport->requests['SendInvoice']['INVOICE'][0]['CONTENT'] ?? '' );
$sentinel_entry  = (array) ( $sentinel_transport->requests['SendInvoice']['INVOICE'][0] ?? array() );

// Captured before the probe below, which deliberately moves the status.
$sentinel_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $sentinel_order );

// A direct store call is refused too: the guard lives in one place.
Kuka_Island_Core_Invoice_Order_Store::mark_sending( $sentinel_order, 'uuid-sentinel-probe', Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL, 'probe' );
$sentinel_order->read_meta_data( true );
$sentinel_after_direct = (string) $sentinel_order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, true );

// The whole order record, scanned for the literal.
$sentinel_meta_hits = array();
foreach ( (array) $sentinel_order->get_meta_data() as $meta_row ) {
	$meta_value = $meta_row->value;
	if ( is_scalar( $meta_value ) && Kuka_Island_Core_Invoice_Numbering::is_auto_number_sentinel( (string) $meta_value ) ) {
		$sentinel_meta_hits[] = (string) $meta_row->key;
	}
}

$report(
	'INVOICE_SENTINEL_NEVER_PERSISTED',
	// It IS what the submitted UBL asks with...
	str_contains( $sentinel_ubl, '<cbc:ID>' . Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL . '</cbc:ID>' )
	// ...and it is NOT sent as the SOAP attribute...
	&& ! array_key_exists( 'ID', $sentinel_entry )
	// ...and never recorded as a number, from either direction.
	&& '' === $sentinel_number
	&& '' === $sentinel_source
	&& '' === $sentinel_after_direct
	&& array() === $sentinel_meta_hits
	// Echoed back by EDM it means "not numbered yet", so not completed.
	&& Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL === $sentinel_status,
	sprintf(
		'measured:production_send_and_store|ubl_cbc_id:%s|soap_invoice_id:%s|order_number:%s|number_source:%s|after_direct_store_call:%s|order_meta_hits:%s|status:%s',
		str_contains( $sentinel_ubl, '<cbc:ID>' . Kuka_Island_Core_Invoice_Numbering::AUTO_NUMBER_SENTINEL . '</cbc:ID>' ) ? 'sentinel' : 'MISSING',
		array_key_exists( 'ID', $sentinel_entry ) ? 'PRESENT' : 'absent',
		'' === $sentinel_number ? 'none' : $sentinel_number,
		'' === $sentinel_source ? 'none' : $sentinel_source,
		'' === $sentinel_after_direct ? 'none' : $sentinel_after_direct,
		empty( $sentinel_meta_hits ) ? 'none' : implode( ',', $sentinel_meta_hits ),
		$sentinel_status
	)
);

Kuka_Island_Core_Invoice_Status_Poller::unschedule( $sentinel_order_id );
kuka_test_delete_order( $sentinel_order_id, $test_run_id );

/*
 * EDM delivers the e-Arşiv document from the address in HEADER/TO, which is the
 * same address the UBL carries. There is no second EmailInvoice call, and the
 * e-Fatura alias path is untouched.
 */
$delivery_cases = array(
	'earchive' => array( Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE, 'EARSIVFATURA', '' ),
	'einvoice' => array( Kuka_Island_Core_Invoice_Status::TYPE_EINVOICE, 'TICARIFATURA', 'urn:mail:defaultgb@acme.com' ),
);

$delivery_ok      = true;
$delivery_details = array();
$delivery_emails  = 0;

foreach ( $delivery_cases as $case => $spec ) {
	$delivery_transport = new Kuka_Island_Test_Numbering_Transport();
	$delivery_client    = new Kuka_Island_Core_EDM_Client( $config, $delivery_transport );
	$delivery_client->send_invoice(
		array(
			'trx_id'            => 9001,
			'uuid'              => 'uuid-delivery-' . $case,
			'invoice_serial'    => 'KUK',
			'profile_id'        => $spec[1],
			'invoice_type_code' => 'SATIS',
			'issue_date'        => '2026-09-01',
			'payable_amount'    => '110.00',
			'receiver_vkn'      => '11111111111',
			'receiver_alias'    => $spec[2],
			'customer_email'    => 'alici@example.com',
			'ubl_xml'           => '<Invoice/>',
		)
	);

	$delivery_request = (array) ( $delivery_transport->requests['SendInvoice'] ?? array() );
	$delivery_header  = (array) ( $delivery_request['INVOICE'][0]['HEADER'] ?? array() );
	$delivery_recv    = (array) ( $delivery_request['RECEIVER'] ?? array() );
	$delivery_to      = (string) ( $delivery_header['TO'] ?? '' );
	$delivery_emails += (int) ( $delivery_transport->calls['EmailInvoice'] ?? 0 );

	$expected_to = Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE === $spec[0] ? 'alici@example.com' : $spec[2];
	$alias_sent  = array_key_exists( 'alias', $delivery_recv );

	$hit = $delivery_to === $expected_to
		// RECEIVER/@alias is an e-Fatura attribute. e-Arşiv omits it rather
		// than sending an empty string.
		&& $alias_sent === ( Kuka_Island_Core_Invoice_Status::TYPE_EINVOICE === $spec[0] )
		&& 0 === (int) ( $delivery_transport->calls['EmailInvoice'] ?? 0 )
		&& 0 === (int) ( $delivery_transport->calls['LoadInvoice'] ?? 0 );

	$delivery_details[] = $case . '=TO:' . ( '' === $delivery_to ? 'absent' : $delivery_to ) . '/alias:' . ( $alias_sent ? (string) $delivery_recv['alias'] : 'omitted' );
	if ( ! $hit ) {
		$delivery_ok = false;
	}
}

// Nothing on the send or poll path can even reach EmailInvoice.
$email_call_sites = array();
foreach ( array( 'class-invoice-manager.php', 'class-invoice-queue.php', 'class-invoice-status-poller.php', 'class-invoice-recovery.php', 'class-invoice-admin.php' ) as $email_scan_file ) {
	$email_scan_path = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/' . $email_scan_file;
	if ( is_readable( $email_scan_path ) && preg_match( '/->\s*email_invoice\s*\(/', (string) file_get_contents( $email_scan_path ) ) ) {
		$email_call_sites[] = $email_scan_file;
	}
}

$report(
	'INVOICE_EARCHIVE_DELIVERY_BY_EDM',
	$delivery_ok
	&& 0 === $delivery_emails
	&& array() === $email_call_sites,
	sprintf(
		'measured:production_client|cases:%d|%s|EmailInvoice=%d|email_invoice_call_sites:%s',
		count( $delivery_cases ),
		implode( ' ', $delivery_details ),
		$delivery_emails,
		empty( $email_call_sites ) ? 'none' : implode( ',', $email_call_sites )
	)
);

/*
 * A document EDM refused is never resent, and never has its UUID or number
 * reused. The replacement is an operator decision, it archives what it replaces,
 * and a double click produces one document.
 */
$recovery_transport = new Kuka_Island_Test_Numbering_Transport();
$recovery_transport->assigned_id = 'EDM2026000000999';
$recovery_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $recovery_transport ) );
$recovery_order   = kuka_create_lock_order(
	$test_run_id,
	$billing_props,
	array(
		Kuka_Island_Core_Invoice_Order_Store::META_STATUS                    => Kuka_Island_Core_Invoice_Status::STATUS_FAILED,
		Kuka_Island_Core_Invoice_Order_Store::META_UUID                      => 'uuid-refused-document',
		Kuka_Island_Core_Invoice_Order_Store::META_NUMBER                    => 'EDM2026000000111',
		Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE             => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
		Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS                  => '1',
		Kuka_Island_Core_Invoice_Status_Poller::META_LAST_EDM_STATUS         => 'SEND - FAILED',
	)
);
$recovery_order_id = (int) $recovery_order->get_id();

// Before anything: the refused document cannot be resent, force or not.
$recovery_forced_error = '';
try {
	$recovery_manager->process_order( $recovery_order, true );
} catch ( Throwable $t ) {
	$recovery_forced_error = get_class( $t );
}
$recovery_order->read_meta_data( true );
$recovery_sends_before_approval = (int) ( $recovery_transport->calls['SendInvoice'] ?? 0 );

// Put the refused state back: the forced attempt above reconciled it.
$recovery_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_FAILED );
$recovery_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_UUID, 'uuid-refused-document' );
$recovery_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, 'EDM2026000000111' );
$recovery_order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE, Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM );
$recovery_order->save_meta_data();

$recovery_eligible = Kuka_Island_Core_Invoice_Recovery::is_eligible( wc_get_order( $recovery_order_id ) );

// One approval.
$recovery_first = Kuka_Island_Core_Invoice_Recovery::approve( wc_get_order( $recovery_order_id ) );
$recovery_after_first = wc_get_order( $recovery_order_id );
$recovery_archive_1   = Kuka_Island_Core_Invoice_Recovery::superseded_documents( $recovery_after_first );
$recovery_evidence    = Kuka_Island_Core_Invoice_Manager::transmission_evidence( $recovery_after_first );

// The second click, and a genuinely concurrent request holding the lock.
$recovery_second = Kuka_Island_Core_Invoice_Recovery::approve( wc_get_order( $recovery_order_id ) );

$recovery_rival = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$recovery_lock_held = '1' === (string) $recovery_rival->get_var( $recovery_rival->prepare( 'SELECT GET_LOCK(%s, 0)', 'kuka_inv_recreate_' . $recovery_order_id ) );
$recovery_concurrent = Kuka_Island_Core_Invoice_Recovery::approve( wc_get_order( $recovery_order_id ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$recovery_rival->get_var( $recovery_rival->prepare( 'SELECT RELEASE_LOCK(%s)', 'kuka_inv_recreate_' . $recovery_order_id ) );

$recovery_archive_2 = Kuka_Island_Core_Invoice_Recovery::superseded_documents( wc_get_order( $recovery_order_id ) );

// The replacement is then sent by the ordinary path, once.
$recovery_send_error = '';
try {
	$recovery_manager->process_order( wc_get_order( $recovery_order_id ) );
} catch ( Throwable $t ) {
	$recovery_send_error = get_class( $t ) . ': ' . $t->getMessage();
}

$recovery_final     = wc_get_order( $recovery_order_id );
$recovery_final_data = Kuka_Island_Core_Invoice_Order_Store::get_invoice_data( $recovery_final );
$recovery_archive_3  = Kuka_Island_Core_Invoice_Recovery::superseded_documents( $recovery_final );
$recovery_history    = (array) ( $recovery_final->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_HISTORY, true ) ?: array() );
$recovery_audit_hit  = false;
foreach ( $recovery_history as $entry ) {
	$entry_message = (string) ( $entry['message'] ?? '' );
	if ( str_contains( $entry_message, Kuka_Island_Core_Invoice_Recovery::ERROR_RECREATE_APPROVED )
		&& str_contains( $entry_message, 'uuid-refused-document' )
		&& str_contains( $entry_message, 'EDM2026000000111' ) ) {
		$recovery_audit_hit = true;
	}
}

// An order whose document was NOT refused cannot be recreated.
$recovery_ineligible_order = kuka_create_lock_order(
	$test_run_id,
	$billing_props,
	array(
		Kuka_Island_Core_Invoice_Order_Store::META_STATUS => Kuka_Island_Core_Invoice_Status::STATUS_SENT,
		Kuka_Island_Core_Invoice_Order_Store::META_UUID   => 'uuid-in-flight-document',
	)
);
$recovery_ineligible = Kuka_Island_Core_Invoice_Recovery::approve( $recovery_ineligible_order );
$recovery_ineligible_order->read_meta_data( true );
$recovery_ineligible_archive = Kuka_Island_Core_Invoice_Recovery::superseded_documents( $recovery_ineligible_order );

$report(
	'INVOICE_FAILED_DOCUMENT_OPERATOR_RECREATE',
	// The refused document is not resent by a forced retry.
	true === $recovery_eligible
	&& 0 === $recovery_sends_before_approval
	// One approval mints one new identity, and it is not the refused one.
	&& Kuka_Island_Core_Invoice_Recovery::OUTCOME_APPROVED === $recovery_first['outcome']
	&& '' !== $recovery_first['reserved_uuid']
	&& 'uuid-refused-document' !== $recovery_first['reserved_uuid']
	&& 1 === count( $recovery_archive_1 )
	&& 'uuid-refused-document' === (string) ( $recovery_archive_1[0]['uuid'] ?? '' )
	&& 'EDM2026000000111' === (string) ( $recovery_archive_1[0]['invoice_number'] ?? '' )
	&& 'SEND - FAILED' === (string) ( $recovery_archive_1[0]['edm_status'] ?? '' )
	// The replacement has not been transmitted, so the guard lets it be sent.
	&& array() === $recovery_evidence
	// A double click and a concurrent request add nothing.
	&& Kuka_Island_Core_Invoice_Recovery::OUTCOME_ALREADY_APPROVED === $recovery_second['outcome']
	&& $recovery_second['reserved_uuid'] === $recovery_first['reserved_uuid']
	&& true === $recovery_lock_held
	&& Kuka_Island_Core_Invoice_Recovery::OUTCOME_LOCK_CONTENDED === $recovery_concurrent['outcome']
	&& 1 === count( $recovery_archive_2 )
	// The replacement is sent once, with the reserved UUID and a NEW number.
	&& '' === $recovery_send_error
	&& 1 === (int) ( $recovery_transport->calls['SendInvoice'] ?? 0 )
	&& $recovery_final_data['uuid'] === $recovery_first['reserved_uuid']
	&& 'EDM2026000000999' === $recovery_final_data['invoice_number']
	&& 'EDM2026000000111' !== $recovery_final_data['invoice_number']
	// The refused document is still on the record, and the audit entry names it.
	&& 1 === count( $recovery_archive_3 )
	&& true === $recovery_audit_hit
	// And an unresolved document is not recreatable at all.
	&& Kuka_Island_Core_Invoice_Recovery::OUTCOME_NOT_ELIGIBLE === $recovery_ineligible['outcome']
	&& array() === $recovery_ineligible_archive,
	sprintf(
		'measured:production_recovery_and_send|eligible:%s|forced_resend_SendInvoice:%d|first:%s|second:%s|concurrent:%s|archive_entries:%d/%d/%d|reserved_uuid_new:%s|final_uuid_is_reserved:%s|old_number:%s|new_number:%s|audit_names_old_document:%s|ineligible:%s|SendInvoice=%d|send_error:%s',
		$recovery_eligible ? 'yes' : 'no',
		$recovery_sends_before_approval,
		(string) $recovery_first['outcome'],
		(string) $recovery_second['outcome'],
		(string) $recovery_concurrent['outcome'],
		count( $recovery_archive_1 ),
		count( $recovery_archive_2 ),
		count( $recovery_archive_3 ),
		'uuid-refused-document' !== $recovery_first['reserved_uuid'] ? 'yes' : 'no',
		$recovery_final_data['uuid'] === $recovery_first['reserved_uuid'] ? 'yes' : 'no',
		(string) ( $recovery_archive_3[0]['invoice_number'] ?? 'none' ),
		$recovery_final_data['invoice_number'] ?: 'none',
		$recovery_audit_hit ? 'yes' : 'no',
		(string) $recovery_ineligible['outcome'],
		$recovery_transport->calls['SendInvoice'] ?? 0,
		'' === $recovery_send_error ? 'none' : $recovery_send_error
	)
);

Kuka_Island_Core_Invoice_Status_Poller::unschedule( $recovery_order_id );
kuka_test_delete_order( $recovery_order_id, $test_run_id );
kuka_test_delete_order( $recovery_ineligible_order->get_id(), $test_run_id );

/* -------------------------------------------------------------------------- */
/* A spent replacement identity never blocks the next recreation              */
/* -------------------------------------------------------------------------- */

/**
 * SendInvoice that can be made to fail the way a lost network does.
 */
final class Kuka_Island_Test_Recovery_Transport implements Kuka_Island_Core_SOAP_Transport_Interface {
	/** @var array<string, int> */
	public array $calls = array();
	/** @var bool Raise the timeout the client classifies as transient. */
	public bool $timeout_on_send = false;
	/** @var string STATUS literal for a successful SendInvoice. */
	public string $send_status = 'SEND - SUCCEED';
	/** @var string|null ID EDM assigns, or null to omit it. */
	public ?string $assigned_id = 'EDM2026000001000';
	/** @var string STATUS literal for GetInvoiceStatus. */
	public string $status_literal = 'PACKAGE - PROCESSING';

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}

	public function call( string $operation, array $parameters ) {
		$this->calls[ $operation ] = ( $this->calls[ $operation ] ?? 0 ) + 1;

		if ( 'Login' === $operation ) {
			return array( 'SESSION_ID' => 'session-recovery-fixture', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}

		if ( 'SendInvoice' === $operation ) {
			if ( $this->timeout_on_send ) {
				throw new SoapFault( 'HTTP', 'Connection timed out after 30 seconds' );
			}

			$invoice = array(
				'UUID'   => $parameters['INVOICE'][0]['UUID'] ?? 'uuid-recovery',
				'HEADER' => array( 'STATUS' => $this->send_status ),
			);
			if ( null !== $this->assigned_id ) {
				$invoice['ID'] = $this->assigned_id;
			}

			return array(
				'INVOICE'        => $invoice,
				'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ),
			);
		}

		if ( 'GetInvoiceStatus' === $operation ) {
			return array(
				'INVOICE_STATUS' => array(
					array(
						'UUID'   => $parameters['INVOICE']['UUID'] ?? 'uuid-recovery',
						'HEADER' => array( 'STATUS' => $this->status_literal ),
					),
				),
			);
		}

		return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
	}
}

/**
 * Seed an order whose document EDM refused.
 *
 * @param string               $run_id   Isolation run ID.
 * @param array<string, mixed> $billing  Billing props.
 * @param string               $uuid     Refused document UUID.
 * @param string               $number   Refused document number.
 * @param array<string, mixed> $extra    Extra meta.
 */
$make_refused_order = static function ( string $run_id, array $billing, string $uuid, string $number, array $extra = array() ): WC_Order {
	return kuka_create_lock_order(
		$run_id,
		$billing,
		array_merge(
			array(
				Kuka_Island_Core_Invoice_Order_Store::META_STATUS            => Kuka_Island_Core_Invoice_Status::STATUS_FAILED,
				Kuka_Island_Core_Invoice_Order_Store::META_UUID              => $uuid,
				Kuka_Island_Core_Invoice_Order_Store::META_NUMBER            => $number,
				Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE     => Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM,
				Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS          => '1',
				Kuka_Island_Core_Invoice_Status_Poller::META_LAST_EDM_STATUS => 'SEND - FAILED',
			),
			$extra
		)
	);
};

/*
 * The reservation is spent by mark_sending(), in the same write that records the
 * live UUID. It used to be released only after the provider answered, so a
 * SendInvoice that threw left the reservation next to the UUID it had already
 * become -- and approve() then read that as "a replacement is still waiting",
 * refusing to mint one for the document that had just failed.
 */
$spent_transport = new Kuka_Island_Test_Recovery_Transport();
$spent_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $spent_transport ) );
$spent_order     = $make_refused_order( $test_run_id, $billing_props, 'uuid-refused-generation-1', 'EDM2026000000001' );
$spent_order_id  = (int) $spent_order->get_id();

$spent_first = Kuka_Island_Core_Invoice_Recovery::approve( wc_get_order( $spent_order_id ) );

// The replacement's transmission is lost on the wire.
$spent_transport->timeout_on_send = true;
$spent_send_error = '';
try {
	$spent_manager->process_order( wc_get_order( $spent_order_id ) );
} catch ( Throwable $t ) {
	$spent_send_error = get_class( $t );
}

$spent_after      = wc_get_order( $spent_order_id );
$spent_live_uuid  = (string) $spent_after->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true );
$spent_reserved   = Kuka_Island_Core_Invoice_Recovery::reserved_uuid( $spent_after );
$spent_status     = Kuka_Island_Core_Invoice_Order_Store::get_status( $spent_after );
$spent_sends      = (int) ( $spent_transport->calls['SendInvoice'] ?? 0 );

// The poll then finds out the replacement was refused too.
$spent_after->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_FAILED );
$spent_after->update_meta_data( Kuka_Island_Core_Invoice_Status_Poller::META_LAST_EDM_STATUS, 'SEND - FAILED' );
$spent_after->save_meta_data();

$spent_second = Kuka_Island_Core_Invoice_Recovery::approve( wc_get_order( $spent_order_id ) );
// And the same call again is still idempotent.
$spent_second_again = Kuka_Island_Core_Invoice_Recovery::approve( wc_get_order( $spent_order_id ) );

$spent_archive = Kuka_Island_Core_Invoice_Recovery::superseded_documents( wc_get_order( $spent_order_id ) );
$spent_archived_uuids = array_map( static fn( array $entry ): string => (string) ( $entry['uuid'] ?? '' ), $spent_archive );
$spent_sends_total = (int) ( $spent_transport->calls['SendInvoice'] ?? 0 );

$report(
	'INVOICE_RECOVERY_SPENT_RESERVATION_DOES_NOT_BLOCK',
	Kuka_Island_Core_Invoice_Recovery::OUTCOME_APPROVED === $spent_first['outcome']
	// The lost transmission still used the approved identity...
	&& '' !== $spent_first['reserved_uuid']
	&& $spent_live_uuid === $spent_first['reserved_uuid']
	&& Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN === $spent_status
	// ...and the reservation is gone, even though the provider threw.
	&& '' === $spent_reserved
	&& 1 === $spent_sends
	// The replacement's own failure can then be recreated in turn.
	&& Kuka_Island_Core_Invoice_Recovery::OUTCOME_APPROVED === $spent_second['outcome']
	&& 2 === (int) $spent_second['generation']
	&& $spent_second['reserved_uuid'] !== $spent_first['reserved_uuid']
	&& 'uuid-refused-generation-1' !== $spent_second['reserved_uuid']
	// Repeating that call changes nothing.
	&& Kuka_Island_Core_Invoice_Recovery::OUTCOME_ALREADY_APPROVED === $spent_second_again['outcome']
	&& $spent_second_again['reserved_uuid'] === $spent_second['reserved_uuid']
	// Both refused documents are on the record, and neither identifier came back.
	&& array( 'uuid-refused-generation-1', $spent_first['reserved_uuid'] ) === $spent_archived_uuids
	// No blind resend at any point.
	&& 1 === $spent_sends_total,
	sprintf(
		'measured:production_recovery_and_send|first:%s|live_uuid_is_reserved:%s|status_after_exception:%s|reservation_after_exception:%s|send_threw:%s|second:%s|generation:%d|new_uuid_differs:%s|repeat:%s|archived_uuids:%d|SendInvoice=%d',
		(string) $spent_first['outcome'],
		$spent_live_uuid === $spent_first['reserved_uuid'] ? 'yes' : 'no',
		$spent_status,
		'' === $spent_reserved ? 'consumed' : 'STALE',
		'' === $spent_send_error ? 'no' : $spent_send_error,
		(string) $spent_second['outcome'],
		(int) $spent_second['generation'],
		$spent_second['reserved_uuid'] !== $spent_first['reserved_uuid'] ? 'yes' : 'no',
		(string) $spent_second_again['outcome'],
		count( $spent_archived_uuids ),
		$spent_sends_total
	)
);

Kuka_Island_Core_Invoice_Status_Poller::unschedule( $spent_order_id );
kuka_test_delete_order( $spent_order_id, $test_run_id );

/*
 * The crash-like record: a live UUID and a reservation present at once, which is
 * what a process killed between the two writes would leave. The live evidence is
 * the truth; the reservation is stale and must not lock the flow.
 */
$crash_order = $make_refused_order(
	$test_run_id,
	$billing_props,
	'uuid-crash-live-document',
	'EDM2026000000002',
	array(
		Kuka_Island_Core_Invoice_Recovery::META_RESERVED_UUID => 'uuid-crash-stale-reservation',
		Kuka_Island_Core_Invoice_Recovery::META_GENERATION    => '1',
	)
);
$crash_order_id = (int) $crash_order->get_id();

$crash_result  = Kuka_Island_Core_Invoice_Recovery::approve( wc_get_order( $crash_order_id ) );
$crash_archive = Kuka_Island_Core_Invoice_Recovery::superseded_documents( wc_get_order( $crash_order_id ) );

$report(
	'INVOICE_RECOVERY_STALE_RESERVATION_IS_NOT_A_PENDING_APPROVAL',
	// Not already_approved: the stale reservation is ignored.
	Kuka_Island_Core_Invoice_Recovery::OUTCOME_APPROVED === $crash_result['outcome']
	&& 2 === (int) $crash_result['generation']
	// And the new identity is neither the live one nor the stale one.
	&& 'uuid-crash-live-document' !== $crash_result['reserved_uuid']
	&& 'uuid-crash-stale-reservation' !== $crash_result['reserved_uuid']
	&& 1 === count( $crash_archive )
	&& 'uuid-crash-live-document' === (string) ( $crash_archive[0]['uuid'] ?? '' ),
	sprintf(
		'measured:production_recovery|fixture:live_uuid+stale_reservation|outcome:%s|generation:%d|new_uuid_is_live:%s|new_uuid_is_stale:%s|archived_uuid:%s',
		(string) $crash_result['outcome'],
		(int) $crash_result['generation'],
		'uuid-crash-live-document' === $crash_result['reserved_uuid'] ? 'YES' : 'no',
		'uuid-crash-stale-reservation' === $crash_result['reserved_uuid'] ? 'YES' : 'no',
		(string) ( $crash_archive[0]['uuid'] ?? 'none' )
	)
);

kuka_test_delete_order( $crash_order_id, $test_run_id );

/*
 * The replacement must not inherit the refused document's polling budget. The
 * attempt and elapsed caps live in META_POLL_ATTEMPTS and META_POLL_STARTED_AT,
 * which Kuka_Island_Core_Invoice_Status_Poller::start() only initialises when
 * they are absent -- so a spent budget would make the replacement give up on its
 * first query. The EDM side signals describe the old document and must not read
 * as the new one's either.
 */
if ( $runner_available ) {
	$stale_poll_transport = new Kuka_Island_Test_Recovery_Transport();
	$stale_poll_transport->assigned_id    = 'EDM2026000001111';
	// The replacement is accepted but not yet described, so it stays in flight.
	$stale_poll_transport->send_status    = '';
	$stale_poll_transport->status_literal = 'PACKAGE - PROCESSING';
	$stale_poll_manager = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $stale_poll_transport ) );
	$stale_poll_poller  = new Kuka_Island_Core_Invoice_Status_Poller( $stale_poll_manager );

	$old_started_at   = time() - ( Kuka_Island_Core_Invoice_Status_Poller::MAX_ELAPSED + 3600 );
	$stale_poll_order = $make_refused_order(
		$test_run_id,
		$billing_props,
		'uuid-old-polled-document',
		'EDM2026000000003',
		array(
			// A fully spent budget, and every side signal the old document left.
			Kuka_Island_Core_Invoice_Status_Poller::META_POLL_ATTEMPTS           => (string) Kuka_Island_Core_Invoice_Status_Poller::MAX_ATTEMPTS,
			Kuka_Island_Core_Invoice_Status_Poller::META_POLL_STARTED_AT         => (string) $old_started_at,
			Kuka_Island_Core_Invoice_Status_Poller::META_RESPONSE_CODE           => '500',
			Kuka_Island_Core_Invoice_Status_Poller::META_EARCHIVE_REPORT_STATUS  => 'NOT_REPORTED',
			Kuka_Island_Core_Invoice_Status_Poller::META_GIB_STATUS_CODE         => '-1',
			Kuka_Island_Core_Invoice_Status_Poller::META_LAST_SCHEDULE_OUTCOME   => Kuka_Island_Core_Invoice_Status_Poller::SCHEDULE_CREATED,
		)
	);
	$stale_poll_order_id = (int) $stale_poll_order->get_id();

	// A query booked for the refused document, and a send action that must be
	// left alone.
	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $stale_poll_order_id );
	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $stale_poll_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	Kuka_Island_Core_Invoice_Status_Poller::schedule_query( $stale_poll_order_id, 300 );
	as_schedule_single_action( time() + 600, Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $stale_poll_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );

	$stale_poll_before_poll = count( $poll_pending_ids( $stale_poll_order_id ) );
	$stale_poll_before_send = count(
		(array) as_get_scheduled_actions(
			array(
				'hook'     => Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE,
				'args'     => array( 'order_id' => $stale_poll_order_id ),
				'group'    => Kuka_Island_Core_Invoice_Status_Poller::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 50,
				'orderby'  => 'none',
			),
			'ids'
		)
	);

	$approved_at         = time();
	$stale_poll_approval = Kuka_Island_Core_Invoice_Recovery::approve( wc_get_order( $stale_poll_order_id ) );

	$after_approval      = wc_get_order( $stale_poll_order_id );
	$live_poll_meta      = array();
	foreach ( Kuka_Island_Core_Invoice_Order_Store::superseded_poll_meta_keys() as $poll_meta_key ) {
		$live_value = trim( (string) $after_approval->get_meta( $poll_meta_key, true ) );
		if ( '' !== $live_value ) {
			$live_poll_meta[] = $poll_meta_key . '=' . $live_value;
		}
	}
	$stale_poll_after_poll = count( $poll_pending_ids( $stale_poll_order_id ) );
	$stale_poll_after_send = count(
		(array) as_get_scheduled_actions(
			array(
				'hook'     => Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE,
				'args'     => array( 'order_id' => $stale_poll_order_id ),
				'group'    => Kuka_Island_Core_Invoice_Status_Poller::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 50,
				'orderby'  => 'none',
			),
			'ids'
		)
	);

	$archived_poll_state = (array) ( Kuka_Island_Core_Invoice_Recovery::superseded_documents( $after_approval )[0]['poll_state'] ?? array() );

	// Send the replacement through the production path.
	$stale_poll_saved = $GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS ] ?? null;
	remove_all_actions( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS );
	$stale_poll_poller->register();

	$stale_poll_send_error = '';
	try {
		$stale_poll_manager->process_order( wc_get_order( $stale_poll_order_id ) );
	} catch ( Throwable $t ) {
		$stale_poll_send_error = get_class( $t ) . ': ' . $t->getMessage();
	}

	$after_send        = wc_get_order( $stale_poll_order_id );
	$new_attempts      = (string) $after_send->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_POLL_ATTEMPTS, true );
	$new_started_at    = (int) $after_send->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_POLL_STARTED_AT, true );
	$new_status        = Kuka_Island_Core_Invoice_Order_Store::get_status( $after_send );
	$booked_after_send = $poll_pending_ids( $stale_poll_order_id );

	// The replacement's first query really runs, and books its follow-up.
	$first_query_ran = false;
	if ( 1 === count( $booked_after_send ) ) {
		ActionScheduler_QueueRunner::instance()->process_action( (int) $booked_after_send[0], 'kuka-verify' );
		$first_query_ran = true;
	}

	remove_all_actions( Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS );
	if ( null !== $stale_poll_saved ) {
		$GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Status_Poller::ACTION_QUERY_STATUS ] = $stale_poll_saved;
	}

	$after_query        = wc_get_order( $stale_poll_order_id );
	$attempts_after_run = (string) $after_query->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_POLL_ATTEMPTS, true );
	$booked_after_run   = count( $poll_pending_ids( $stale_poll_order_id ) );
	$edm_status_after   = (string) $after_query->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_LAST_EDM_STATUS, true );

	$report(
		'INVOICE_RECOVERY_NEW_DOCUMENT_FRESH_POLL_BUDGET',
		Kuka_Island_Core_Invoice_Recovery::OUTCOME_APPROVED === $stale_poll_approval['outcome']
		// The refused document's polling state is off the live record...
		&& array() === $live_poll_meta
		// ...and kept with the document it describes.
		&& (string) Kuka_Island_Core_Invoice_Status_Poller::MAX_ATTEMPTS === (string) ( $archived_poll_state[ Kuka_Island_Core_Invoice_Status_Poller::META_POLL_ATTEMPTS ] ?? '' )
		&& (string) $old_started_at === (string) ( $archived_poll_state[ Kuka_Island_Core_Invoice_Status_Poller::META_POLL_STARTED_AT ] ?? '' )
		&& 'SEND - FAILED' === (string) ( $archived_poll_state[ Kuka_Island_Core_Invoice_Status_Poller::META_LAST_EDM_STATUS ] ?? '' )
		&& '500' === (string) ( $archived_poll_state[ Kuka_Island_Core_Invoice_Status_Poller::META_RESPONSE_CODE ] ?? '' )
		&& '-1' === (string) ( $archived_poll_state[ Kuka_Island_Core_Invoice_Status_Poller::META_GIB_STATUS_CODE ] ?? '' )
		// Only this order's poll hook was cancelled; the send action stands.
		&& 1 === $stale_poll_before_poll
		&& 0 === $stale_poll_after_poll
		&& 1 === $stale_poll_before_send
		&& 1 === $stale_poll_after_send
		// The replacement was sent once and is in flight.
		&& '' === $stale_poll_send_error
		&& 1 === (int) ( $stale_poll_transport->calls['SendInvoice'] ?? 0 )
		&& Kuka_Island_Core_Invoice_Status::STATUS_SENT === $new_status
		// Its budget starts from zero, with a fresh clock.
		&& '0' === $new_attempts
		&& $new_started_at >= $approved_at
		&& $new_started_at > $old_started_at
		// One query booked, which really ran and booked its own follow-up.
		&& 1 === count( $booked_after_send )
		&& true === $first_query_ran
		&& '1' === $attempts_after_run
		&& 1 === $booked_after_run
		// And the answer recorded is the replacement's, not the old document's.
		&& 'PACKAGE - PROCESSING' === $edm_status_after,
		sprintf(
			'measured:production_recovery_send_and_runner|outcome:%s|live_poll_meta:%s|archived_attempts:%s|archived_started_at:%s|archived_edm_status:%s|poll_actions:%d->%d|send_actions:%d->%d|SendInvoice=%d|status:%s|new_attempts:%s|new_started_at_fresh:%s|booked_after_send:%d|attempts_after_run:%s|booked_after_run:%d|edm_status_after:%s|send_error:%s',
			(string) $stale_poll_approval['outcome'],
			empty( $live_poll_meta ) ? 'none' : implode( ',', $live_poll_meta ),
			(string) ( $archived_poll_state[ Kuka_Island_Core_Invoice_Status_Poller::META_POLL_ATTEMPTS ] ?? 'none' ),
			(string) ( $archived_poll_state[ Kuka_Island_Core_Invoice_Status_Poller::META_POLL_STARTED_AT ] ?? 'none' ),
			(string) ( $archived_poll_state[ Kuka_Island_Core_Invoice_Status_Poller::META_LAST_EDM_STATUS ] ?? 'none' ),
			$stale_poll_before_poll,
			$stale_poll_after_poll,
			$stale_poll_before_send,
			$stale_poll_after_send,
			$stale_poll_transport->calls['SendInvoice'] ?? 0,
			$new_status,
			'' === $new_attempts ? 'absent' : $new_attempts,
			$new_started_at >= $approved_at ? 'yes' : 'no',
			count( $booked_after_send ),
			'' === $attempts_after_run ? 'absent' : $attempts_after_run,
			$booked_after_run,
			$edm_status_after ?: 'none',
			'' === $stale_poll_send_error ? 'none' : $stale_poll_send_error
		)
	);

	Kuka_Island_Core_Invoice_Status_Poller::unschedule( $stale_poll_order_id );
	as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $stale_poll_order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	kuka_test_delete_order( $stale_poll_order_id, $test_run_id );
}

/* ========================================================================== */
/* Physical orders are invoiced when the goods leave, not when the money does  */
/* ========================================================================== */

if ( $runner_available
	&& class_exists( '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment' )
	&& class_exists( '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore' ) ) {

	/*
	 * A synthetic carrier identity. It is NOT a claim about DHL: the courier's
	 * real VKN and legal title are facts nobody here has, and inventing them is
	 * exactly what the production code refuses to do. Production reads this map
	 * only from reviewed environment configuration (KUKA_EDM_CARRIERS).
	 */
	$test_carrier_vkn   = '9990001111';
	$test_carrier_title = 'TEST KARGO A.S. - GERCEK DEGIL';

	$carrier_map      = array( 'dhl' => array( 'vkn' => $test_carrier_vkn, 'title' => $test_carrier_title ) );
	$fulfil_config    = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'auto_send' => true, 'carriers' => $carrier_map ) ) );
	$no_carrier_config = new Kuka_Island_Core_Invoice_Config( array_merge( $ready_overrides, array( 'auto_send' => true ) ) );

	$fulfil_store_class = '\Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';
	$fulfil_class       = '\Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment';
	$fulfil_utils       = '\Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils';
	$fulfil_store       = wc_get_container()->get( $fulfil_store_class );

	$GLOBALS['kuka_test_products'] = array();
	$fulfil_product_title          = 'Kargo Testi Ürünü';

	/*
	 * Self-healing, ownership-checked purge of fixture products a crashed run
	 * may have left behind. A published, priced product counts towards the
	 * shop's own launch-readiness rows, so a leftover one does not just sit
	 * there -- it changes an unrelated measurement. Matched by the distinctive
	 * fixture title, and refused while any order item still references it.
	 */
	$purge_fixture_products = static function ( string $title ): array {
		global $wpdb;

		$purged = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s", 'product', $title ) );

		foreach ( $ids as $product_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$referenced = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = %s AND meta_value = %d",
					'_product_id',
					(int) $product_id
				)
			);
			if ( $referenced > 0 ) {
				continue;
			}

			wp_delete_post( (int) $product_id, true );
			$purged[] = (int) $product_id;
		}

		return $purged;
	};

	$stale_products_purged = $purge_fixture_products( $fulfil_product_title );

	/**
	 * A real shippable product, so needs_shipping() is genuinely true.
	 */
	$make_shippable_product = static function () use ( $fulfil_product_title ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $fulfil_product_title );
		$product->set_regular_price( '100' );
		$product->set_virtual( false );
		$product->save();

		$GLOBALS['kuka_test_products'][] = (int) $product->get_id();

		return $product;
	};

	/**
	 * A settled physical order carrying $qty of one shippable product.
	 *
	 * @param string           $run_id  Isolation run ID.
	 * @param array            $billing Billing props.
	 * @param WC_Product       $product Shippable product.
	 * @param int              $qty     Quantity.
	 */
	$make_physical_order = static function ( string $run_id, array $billing, WC_Product $product, int $qty = 2 ): WC_Order {
		$order = kuka_create_test_order( $run_id, array_merge( $billing, array( 'total' => (string) ( 100 * $qty ) ) ) );
		// Placed and paid before it shipped, which is the realistic shape and
		// what makes "the invoice date is not the order date" measurable.
		$order->set_date_created( '2026-08-19 09:00:00' );
		$order->update_meta_data( '_billing_tax_number', '12345678901' );
		$order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, 'KUK2026000000042' );
		$order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE, Kuka_Island_Core_Invoice_Order_Store::NUMBER_SOURCE_EDM );

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $qty );
		$item->set_subtotal( (string) ( 100 * $qty ) );
		$item->set_total( (string) ( 100 * $qty ) );
		$order->add_item( $item );
		$order->save();

		return wc_get_order( $order->get_id() );
	};

	/**
	 * Create a REAL fulfillment through WooCommerce's own datastore.
	 *
	 * Saving it fires woocommerce_fulfillment_after_create (which updates the
	 * order's aggregate _fulfillment_status) and then
	 * woocommerce_fulfillment_after_fulfill -- the hook production listens on.
	 *
	 * @param string $fulfil_class Fulfillment class name.
	 * @param int    $order_id     Order ID.
	 * @param int    $item_id      Order item ID.
	 * @param int    $qty          Quantity shipped.
	 * @param string $provider     WooCommerce shipment provider key.
	 */
	$fulfil_items = static function ( string $fulfil_class, int $order_id, int $item_id, int $qty, string $provider ) {
		$fulfillment = new $fulfil_class();
		$fulfillment->set_entity_type( WC_Order::class );
		$fulfillment->set_entity_id( (string) $order_id );
		$fulfillment->set_items( array( array( 'item_id' => $item_id, 'qty' => $qty ) ) );
		$fulfillment->set_shipment_provider( $provider );
		$fulfillment->set_status( 'fulfilled' );
		$fulfillment->save();

		return $fulfillment;
	};

	/**
	 * Remove every fulfillment an order has, and its send/poll actions.
	 */
	$purge_fulfillments = static function ( $fulfil_store, int $order_id ): void {
		foreach ( (array) $fulfil_store->read_fulfillments( WC_Order::class, (string) $order_id ) as $existing ) {
			try {
				$fulfil_store->delete( $existing, array( 'force_delete' => true ) );
			} catch ( Throwable $t ) {
				unset( $t );
			}
		}

		Kuka_Island_Core_Invoice_Status_Poller::unschedule( $order_id );
		as_unschedule_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( 'order_id' => $order_id ), Kuka_Island_Core_Invoice_Status_Poller::GROUP );
	};

	/**
	 * Pending send actions for one order.
	 */
	$send_pending_ids = static function ( int $order_id ): array {
		return array_map(
			'intval',
			(array) as_get_scheduled_actions(
				array(
					'hook'     => Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE,
					'args'     => array( 'order_id' => $order_id ),
					'group'    => Kuka_Island_Core_Invoice_Status_Poller::GROUP,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 50,
					'orderby'  => 'none',
				),
				'ids'
			)
		);
	};

	/**
	 * Install the production listeners for one queue instance only.
	 */
	$install_fulfil_listeners = static function ( Kuka_Island_Core_Invoice_Queue $queue ): array {
		$saved = array(
			'fulfil' => $GLOBALS['wp_filter']['woocommerce_fulfillment_after_fulfill'] ?? null,
			'worker' => $GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE ] ?? null,
		);

		remove_all_actions( 'woocommerce_fulfillment_after_fulfill' );
		remove_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE );
		// The two production callbacks, wired exactly as register() wires them.
		add_action( 'woocommerce_fulfillment_after_fulfill', array( $queue, 'handle_fulfillment_fulfilled' ), 20, 1 );
		add_action( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE, array( $queue, 'process_queued_order' ), 10, 1 );

		return $saved;
	};

	$restore_fulfil_listeners = static function ( array $saved ): void {
		remove_all_actions( 'woocommerce_fulfillment_after_fulfill' );
		remove_all_actions( Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE );
		if ( null !== $saved['fulfil'] ) {
			$GLOBALS['wp_filter']['woocommerce_fulfillment_after_fulfill'] = $saved['fulfil'];
		}
		if ( null !== $saved['worker'] ) {
			$GLOBALS['wp_filter'][ Kuka_Island_Core_Invoice_Queue::ACTION_PROCESS_INVOICE ] = $saved['worker'];
		}
	};

	/* ---------------------------------------------------------------------- */
	/* 1-3: nothing shipped, partially shipped, fully shipped                  */
	/* ---------------------------------------------------------------------- */

	$lifecycle_transport = new Kuka_Island_Test_Numbering_Transport();
	$lifecycle_transport->assigned_id = 'EDM2026000002000';
	$lifecycle_manager   = new Kuka_Island_Core_Invoice_Manager( $fulfil_config, new Kuka_Island_Core_EDM_Provider( $fulfil_config, $lifecycle_transport ) );
	$lifecycle_queue     = new Kuka_Island_Core_Invoice_Queue( $lifecycle_manager );

	$lifecycle_product = $make_shippable_product();
	$lifecycle_order   = $make_physical_order( $test_run_id, $billing_props, $lifecycle_product, 2 );
	$lifecycle_id      = (int) $lifecycle_order->get_id();
	$lifecycle_items   = $lifecycle_order->get_items();
	$lifecycle_item    = reset( $lifecycle_items );
	$lifecycle_item_id = (int) $lifecycle_item->get_id();

	$purge_fulfillments( $fulfil_store, $lifecycle_id );
	$lifecycle_saved = $install_fulfil_listeners( $lifecycle_queue );

	// (1) Paid, nothing shipped. The order-status hook path is exercised too.
	$lifecycle_queue->maybe_enqueue_order( $lifecycle_id, wc_get_order( $lifecycle_id ) );
	$state_unshipped   = Kuka_Island_Core_Invoice_Manager::shipment_gate( wc_get_order( $lifecycle_id ) );
	$actions_unshipped = count( $send_pending_ids( $lifecycle_id ) );
	$hint_unshipped    = Kuka_Island_Core_Invoice_Admin::operator_hint( wc_get_order( $lifecycle_id ), $fulfil_config );

	// (2) Half of it ships.
	$fulfil_items( $fulfil_class, $lifecycle_id, $lifecycle_item_id, 1, 'dhl' );
	$state_partial   = Kuka_Island_Core_Invoice_Manager::shipment_gate( wc_get_order( $lifecycle_id ) );
	$actions_partial = count( $send_pending_ids( $lifecycle_id ) );
	$hint_partial    = Kuka_Island_Core_Invoice_Admin::operator_hint( wc_get_order( $lifecycle_id ), $fulfil_config );

	// (3) The rest ships: the fulfillment hook enqueues exactly once.
	$last_fulfillment = $fulfil_items( $fulfil_class, $lifecycle_id, $lifecycle_item_id, 1, 'dhl' );
	$state_complete   = Kuka_Island_Core_Invoice_Manager::shipment_gate( wc_get_order( $lifecycle_id ) );
	$actions_complete = count( $send_pending_ids( $lifecycle_id ) );
	$status_queued    = Kuka_Island_Core_Invoice_Order_Store::get_status( wc_get_order( $lifecycle_id ) );
	$hint_queued      = Kuka_Island_Core_Invoice_Admin::operator_hint( wc_get_order( $lifecycle_id ), $fulfil_config );
	$issue_at_enqueue = (string) wc_get_order( $lifecycle_id )->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_ISSUE_DATE, true );

	// The same event again, plus the order-status entry point: still one action.
	do_action( 'woocommerce_fulfillment_after_fulfill', $last_fulfillment );
	$lifecycle_queue->maybe_enqueue_order( $lifecycle_id, wc_get_order( $lifecycle_id ) );
	$actions_after_duplicate = count( $send_pending_ids( $lifecycle_id ) );

	// The real worker runs it.
	$lifecycle_runs = 0;
	$lifecycle_pending = $send_pending_ids( $lifecycle_id );
	if ( 1 === count( $lifecycle_pending ) ) {
		ActionScheduler_QueueRunner::instance()->process_action( (int) $lifecycle_pending[0], 'kuka-verify' );
		$lifecycle_runs = 1;
	}

	$restore_fulfil_listeners( $lifecycle_saved );

	$lifecycle_final   = wc_get_order( $lifecycle_id );
	$lifecycle_data    = Kuka_Island_Core_Invoice_Order_Store::get_invoice_data( $lifecycle_final );
	$lifecycle_request = (array) ( $lifecycle_transport->requests['SendInvoice'] ?? array() );
	$lifecycle_isd     = (array) ( $lifecycle_request['INVOICE'][0]['HEADER']['INTERNETSALESDETAILS'] ?? array() );
	$lifecycle_ship    = (array) ( $lifecycle_isd['gonderiBilgileri'] ?? array() );

	$report(
		'INVOICE_FULFILLMENT_GATES_THE_INVOICE',
		// (1) Paid but unshipped: no action, no send, and the screen says why.
		Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_PENDING === $state_unshipped['state']
		&& false === $state_unshipped['ok']
		&& 0 === $actions_unshipped
		&& 'Fatura için siparişin tamamen kargoya verilmesi bekleniyor.' === $hint_unshipped
		// (2) Partially shipped: still nothing, with its own message.
		&& Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_PARTIAL === $state_partial['state']
		&& false === $state_partial['ok']
		&& 0 === $actions_partial
		&& 'Kısmi gönderim var; tüm ürünler kargoya verilmeden fatura oluşturulmaz.' === $hint_partial
		// (3) Fully shipped: exactly one action, from the fulfillment hook.
		&& Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_COMPLETE === $state_complete['state']
		&& true === $state_complete['ok']
		&& 1 === $actions_complete
		&& Kuka_Island_Core_Invoice_Status::STATUS_QUEUED === $status_queued
		&& 'Fatura kuyruğa alındı.' === $hint_queued
		// A repeat event and the order-status path add nothing.
		&& 1 === $actions_after_duplicate
		// The real worker sends once.
		&& 1 === $lifecycle_runs
		&& 1 === (int) ( $lifecycle_transport->calls['SendInvoice'] ?? 0 )
		&& 0 === (int) ( $lifecycle_transport->calls['LoadInvoice'] ?? 0 )
		&& 'EDM2026000002000' === $lifecycle_data['invoice_number']
		// And the block it sent carries the shipment and the carrier.
		&& 'ODEMEARACISI' === (string) ( $lifecycle_isd['odemeSekli'] ?? '' )
		&& 'iyzico' === (string) ( $lifecycle_isd['odemeAracisiAdi'] ?? '' )
		&& $test_carrier_vkn === (string) ( $lifecycle_ship['gonderiTasiyan']['tuzelKisi']['vkn'] ?? '' )
		&& $test_carrier_title === (string) ( $lifecycle_ship['gonderiTasiyan']['tuzelKisi']['unvan'] ?? '' )
		&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $lifecycle_ship['gonderimTarihi'] ?? '' ) ),
		sprintf(
			'measured:real_fulfillments_datastore_and_action_scheduler|unshipped:%s/actions%d|partial:%s/actions%d|complete:%s/actions%d|status:%s|after_duplicate_event:%d|worker_runs:%d|SendInvoice=%d|LoadInvoice=%d|number:%s|odemeSekli:%s|carrier_vkn:%s|gonderimTarihi:%s|hint_unshipped:%s|hint_partial:%s',
			$state_unshipped['state'],
			$actions_unshipped,
			$state_partial['state'],
			$actions_partial,
			$state_complete['state'],
			$actions_complete,
			$status_queued,
			$actions_after_duplicate,
			$lifecycle_runs,
			$lifecycle_transport->calls['SendInvoice'] ?? 0,
			$lifecycle_transport->calls['LoadInvoice'] ?? 0,
			$lifecycle_data['invoice_number'] ?: 'none',
			(string) ( $lifecycle_isd['odemeSekli'] ?? 'absent' ),
			(string) ( $lifecycle_ship['gonderiTasiyan']['tuzelKisi']['vkn'] ?? 'absent' ),
			(string) ( $lifecycle_ship['gonderimTarihi'] ?? 'absent' ),
			$hint_unshipped,
			$hint_partial
		)
	);

	/* ---------------------------------------------------------------------- */
	/* 10: the IssueDate is the invoice's day, frozen, and not the order's      */
	/* ---------------------------------------------------------------------- */

	$issue_order_created = (string) wc_get_order( $lifecycle_id )->get_date_created()->date( 'Y-m-d' );
	$issue_today         = (string) wp_date( 'Y-m-d' );
	$issue_ubl           = (string) ( $lifecycle_request['INVOICE'][0]['CONTENT'] ?? '' );
	$issue_in_ubl        = '';
	if ( preg_match( '#<cbc:IssueDate>([^<]+)</cbc:IssueDate>#', $issue_ubl, $issue_match ) ) {
		$issue_in_ubl = trim( $issue_match[1] );
	}
	$issue_soap = (string) ( $lifecycle_request['INVOICE'][0]['HEADER']['ISSUE_DATE'] ?? '' );

	// A retry re-reads the frozen value rather than re-deriving it.
	$issue_retry = Kuka_Island_Core_Invoice_Order_Store::resolve_issue_date( wc_get_order( $lifecycle_id ) );

	$report(
		'INVOICE_ISSUE_DATE_FROZEN_AT_ENQUEUE',
		// Frozen at enqueue, in the shop's timezone, and it is today -- not the
		// day the order was placed.
		$issue_at_enqueue === $issue_today
		&& $issue_in_ubl === $issue_today
		&& $issue_soap === $issue_today
		&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue_at_enqueue )
		// The order's own creation date is a different fact and is not reused.
		&& $issue_order_created !== $issue_today
		// A later read does not move it.
		&& $issue_retry['date'] === $issue_at_enqueue
		&& false === $issue_retry['frozen_now']
		// And the shipment date is its own fact, from the fulfillment.
		&& (string) ( $lifecycle_ship['gonderimTarihi'] ?? '' ) === $issue_today,
		sprintf(
			'measured:production_send|order_created:%s|frozen_at_enqueue:%s|ubl_issue_date:%s|soap_issue_date:%s|shop_today:%s|equals_order_created:%s|reread:%s/frozen_now:%s|gonderimTarihi:%s',
			$issue_order_created,
			$issue_at_enqueue ?: 'none',
			$issue_in_ubl ?: 'none',
			$issue_soap ?: 'none',
			$issue_today,
			$issue_order_created === $issue_at_enqueue ? 'YES' : 'no',
			$issue_retry['date'],
			$issue_retry['frozen_now'] ? 'YES' : 'no',
			(string) ( $lifecycle_ship['gonderimTarihi'] ?? 'absent' )
		)
	);

	$purge_fulfillments( $fulfil_store, $lifecycle_id );
	kuka_test_delete_order( $lifecycle_id, $test_run_id );

	/* ---------------------------------------------------------------------- */
	/* 4: the fulfillment is reverted after the order was queued               */
	/* ---------------------------------------------------------------------- */

	$revert_transport = new Kuka_Island_Test_Numbering_Transport();
	$revert_manager   = new Kuka_Island_Core_Invoice_Manager( $fulfil_config, new Kuka_Island_Core_EDM_Provider( $fulfil_config, $revert_transport ) );
	$revert_queue     = new Kuka_Island_Core_Invoice_Queue( $revert_manager );

	$revert_product = $make_shippable_product();
	$revert_order   = $make_physical_order( $test_run_id, $billing_props, $revert_product, 1 );
	$revert_id      = (int) $revert_order->get_id();
	$revert_items   = $revert_order->get_items();
	$revert_item    = reset( $revert_items );

	$purge_fulfillments( $fulfil_store, $revert_id );
	$revert_saved = $install_fulfil_listeners( $revert_queue );

	$fulfil_items( $fulfil_class, $revert_id, (int) $revert_item->get_id(), 1, 'dhl' );
	$revert_queued_actions = count( $send_pending_ids( $revert_id ) );

	// The shipment is taken back before the worker gets to it.
	foreach ( (array) $fulfil_store->read_fulfillments( WC_Order::class, (string) $revert_id ) as $to_revert ) {
		$fulfil_store->delete( $to_revert, array( 'force_delete' => true ) );
	}
	$revert_state = Kuka_Island_Core_Invoice_Manager::shipment_gate( wc_get_order( $revert_id ) );

	$revert_pending = $send_pending_ids( $revert_id );
	$revert_runs    = 0;
	if ( 1 === count( $revert_pending ) ) {
		ActionScheduler_QueueRunner::instance()->process_action( (int) $revert_pending[0], 'kuka-verify' );
		$revert_runs = 1;
	}

	$restore_fulfil_listeners( $revert_saved );

	$revert_final  = wc_get_order( $revert_id );
	$revert_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $revert_final );
	$revert_error  = (string) $revert_final->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, true );

	$report(
		'INVOICE_REVERTED_FULFILLMENT_STOPS_THE_WORKER',
		1 === $revert_queued_actions
		&& false === $revert_state['ok']
		&& 1 === $revert_runs
		// Nothing was transmitted, and the order says why.
		&& 0 === (int) ( $revert_transport->calls['SendInvoice'] ?? 0 )
		&& 0 === (int) ( $revert_transport->calls['LoadInvoice'] ?? 0 )
		&& Kuka_Island_Core_Invoice_Status::STATUS_BLOCKED === $revert_status
		&& Kuka_Island_Core_Invoice_Manager::ERROR_SHIPMENT_INCOMPLETE === $revert_error
		&& 0 === count( $send_pending_ids( $revert_id ) ),
		sprintf(
			'measured:real_fulfillments_datastore_and_action_scheduler|queued_actions:%d|state_after_revert:%s|worker_runs:%d|SendInvoice=%d|status:%s|error_code:%s|send_actions_pending:%d',
			$revert_queued_actions,
			$revert_state['state'],
			$revert_runs,
			$revert_transport->calls['SendInvoice'] ?? 0,
			$revert_status,
			$revert_error ?: 'none',
			count( $send_pending_ids( $revert_id ) )
		)
	);

	$purge_fulfillments( $fulfil_store, $revert_id );
	kuka_test_delete_order( $revert_id, $test_run_id );

	/* ---------------------------------------------------------------------- */
	/* 5-8: carrier identity                                                   */
	/* ---------------------------------------------------------------------- */

	$carrier_cases = array(
		// name => [ list of [provider, qty], config, expected ok, expected error ]
		'dhl_configured'   => array( array( array( 'dhl', 2 ) ), 'mapped', true, '' ),
		'dhl_two_parcels'  => array( array( array( 'dhl', 1 ), array( 'dhl', 1 ) ), 'mapped', true, '' ),
		'unmapped_carrier' => array( array( array( 'aras-kargo', 2 ) ), 'mapped', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_UNMAPPED ),
		'free_text_other'  => array( array( array( 'other', 2 ) ), 'mapped', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_UNMAPPED ),
		'two_carriers'     => array( array( array( 'dhl', 1 ), array( 'ups', 1 ) ), 'mapped', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_MULTIPLE_PROVIDERS ),
		'nothing_mapped'   => array( array( array( 'dhl', 2 ) ), 'unmapped', false, Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_UNMAPPED ),
	);

	$carrier_ok      = true;
	$carrier_details = array();
	$carrier_sends   = 0;
	$carrier_hint_unmapped = '';

	foreach ( $carrier_cases as $case => $spec ) {
		$case_config    = 'mapped' === $spec[1] ? $fulfil_config : $no_carrier_config;
		$case_transport = new Kuka_Island_Test_Numbering_Transport();
		$case_transport->assigned_id = 'EDM2026000002' . str_pad( (string) count( $carrier_details ), 3, '0', STR_PAD_LEFT );
		$case_manager   = new Kuka_Island_Core_Invoice_Manager( $case_config, new Kuka_Island_Core_EDM_Provider( $case_config, $case_transport ) );

		$case_product = $make_shippable_product();
		$case_qty     = array_sum( array_map( static fn( array $parcel ): int => (int) $parcel[1], $spec[0] ) );
		$case_order   = $make_physical_order( $test_run_id, $billing_props, $case_product, $case_qty );
		$case_id      = (int) $case_order->get_id();
		$case_items   = $case_order->get_items();
		$case_item    = reset( $case_items );

		$purge_fulfillments( $fulfil_store, $case_id );
		// No listeners: this case measures the SEND path, not the enqueue path.
		foreach ( $spec[0] as $parcel ) {
			$fulfil_items( $fulfil_class, $case_id, (int) $case_item->get_id(), (int) $parcel[1], (string) $parcel[0] );
		}

		$case_facts   = Kuka_Island_Core_Invoice_Manager::shipment_gate( wc_get_order( $case_id ) );
		$case_carrier = Kuka_Island_Core_Internet_Sales_Details::resolve_carrier( $case_config, (array) $case_facts['facts']['provider_keys'] );

		$case_error = '';
		try {
			$case_manager->process_order( wc_get_order( $case_id ) );
		} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
			$case_error = $e->get_safe_error_code();
		} catch ( Throwable $t ) {
			$case_error = get_class( $t );
		}

		$case_sends     = (int) ( $case_transport->calls['SendInvoice'] ?? 0 );
		$carrier_sends += $case_sends;
		$case_reloaded  = wc_get_order( $case_id );
		$case_isd       = (array) ( $case_transport->requests['SendInvoice']['INVOICE'][0]['HEADER']['INTERNETSALESDETAILS'] ?? array() );
		$case_vkn       = (string) ( $case_isd['gonderiBilgileri']['gonderiTasiyan']['tuzelKisi']['vkn'] ?? '' );

		if ( 'nothing_mapped' === $case ) {
			$carrier_hint_unmapped = Kuka_Island_Core_Invoice_Admin::operator_hint( $case_reloaded, $case_config );
		}

		$hit = $case_carrier['ok'] === $spec[2]
			&& ( true === $spec[2] ? '' === $case_carrier['error'] : $spec[3] === $case_carrier['error'] )
			&& ( true === $spec[2] ? 1 === $case_sends : 0 === $case_sends )
			&& ( true === $spec[2]
				? ( $test_carrier_vkn === $case_vkn && '' === $case_error )
				: ( '' === $case_vkn && Kuka_Island_Core_Invoice_Manager::ERROR_INTERNET_SALES_INCOMPLETE === $case_error ) );

		$carrier_details[] = $case . '=' . ( $case_carrier['ok'] ? 'ok/send' . $case_sends : ( $case_carrier['error'] . '/send' . $case_sends ) );
		if ( ! $hit ) {
			$carrier_ok = false;
		}

		$purge_fulfillments( $fulfil_store, $case_id );
		kuka_test_delete_order( $case_id, $test_run_id );
	}

	// The provider KEY is what identity is looked up by, and WooCommerce's
	// display label for it is not that key.
	$dhl_label = '';
	$dhl_key   = '';
	if ( method_exists( $fulfil_utils, 'get_shipping_providers' ) ) {
		$providers = (array) call_user_func( array( $fulfil_utils, 'get_shipping_providers' ) );
		$dhl_entry = $providers['dhl'] ?? null;
		if ( is_object( $dhl_entry ) && method_exists( $dhl_entry, 'get_name' ) ) {
			$dhl_label = (string) $dhl_entry->get_name();
			$dhl_key   = method_exists( $dhl_entry, 'get_key' ) ? (string) $dhl_entry->get_key() : '';
		}
	}

	/*
	 * DHL's display label happens to be its key in a different case, so it is
	 * not the sharp example. Aras Kargo is: WooCommerce keys it 'aras-kargo' and
	 * shows it as 'Aras Kargo', and the carrier map answers only to the key.
	 */
	$aras_config = new Kuka_Island_Core_Invoice_Config(
		array_merge(
			$ready_overrides,
			array(
				'auto_send' => true,
				'carriers'  => array( 'aras-kargo' => array( 'vkn' => $test_carrier_vkn, 'title' => $test_carrier_title ) ),
			)
		)
	);
	$aras_label      = Kuka_Island_Core_Internet_Sales_Details::provider_display_label( 'aras-kargo' );
	$aras_by_key     = $aras_config->get_carrier( 'aras-kargo' );
	$aras_by_label   = $aras_config->get_carrier( $aras_label );

	$report(
		'INVOICE_CARRIER_IDENTITY_FROM_PROVIDER_KEY',
		$carrier_ok
		// Only the two accepted cases transmitted anything.
		&& 2 === $carrier_sends
		// WooCommerce's own key for DHL is exactly 'dhl'...
		&& 'dhl' === $dhl_key
		&& array() !== $fulfil_config->get_carrier( 'dhl' )
		&& array( 'dhl' ) === $fulfil_config->get_configured_carrier_keys()
		// ...and a display label is not a lookup key: Aras Kargo resolves by
		// 'aras-kargo' and not by 'Aras Kargo'.
		&& 'DHL' === $dhl_label
		&& 'Aras Kargo' === $aras_label
		&& array() !== $aras_by_key
		&& array() === $aras_by_label
		// A carrier with no reviewed identity says so, in plain Turkish.
		&& 'DHL mali taşıyıcı bilgileri yapılandırılmamış.' === $carrier_hint_unmapped,
		sprintf(
			'measured:real_fulfillments_datastore_and_production_send|cases:%d|%s|SendInvoice=%d|provider_key:%s|configured_keys:%s|display_label:%s|label_as_lookup_key:%s|hint:%s',
			count( $carrier_cases ),
			implode( ' ', $carrier_details ),
			$carrier_sends,
			$dhl_key ?: 'none',
			implode( ',', $fulfil_config->get_configured_carrier_keys() ),
			$dhl_label ?: 'none',
			array() === $aras_by_label ? 'not_found' : 'FOUND',
			$carrier_hint_unmapped
		)
	);


	/* ---------------------------------------------------------------------- */
	/* The handover date is the shop's calendar day, not PHP's                 */
	/* ---------------------------------------------------------------------- */

	/*
	 * Measured, not assumed: Fulfillment::set_date_fulfilled() normalises its
	 * input through normalize_date_to_utc() and get_date_fulfilled() returns a
	 * UTC 'Y-m-d H:i:s' string. So the stored value is UTC and the day that
	 * belongs on the document is that moment in the SHOP's timezone.
	 *
	 * The old strtotime() reached the right answer on this install only because
	 * WordPress leaves PHP's default timezone at UTC -- the input zone was
	 * inherited rather than stated, and strtotime() would also accept loose
	 * input and answer with a plausible wrong date. Both are stated explicitly
	 * now, and the whole point is measured through WooCommerce's own setter:
	 * a LOCAL handover time in, the correct local calendar day out.
	 */
	$tz_roundtrip_cases = array(
		// name => [ local handover time, expected stored UTC, expected day ]
		'late_evening'        => array( '2026-09-02 23:30:00', '2026-09-02 20:30:00', '2026-09-02' ),
		'just_after_midnight' => array( '2026-09-02 00:30:00', '2026-09-01 21:30:00', '2026-09-02' ),
		'noon'                => array( '2026-09-02 12:00:00', '2026-09-02 09:00:00', '2026-09-02' ),
		'last_second_of_day'  => array( '2026-09-02 23:59:59', '2026-09-02 20:59:59', '2026-09-02' ),
		'first_second_of_day' => array( '2026-09-02 00:00:00', '2026-09-01 21:00:00', '2026-09-02' ),
		// Istanbul is +03:00 all year, so a winter date behaves the same way.
		'winter_date'         => array( '2026-01-15 23:30:00', '2026-01-15 20:30:00', '2026-01-15' ),
	);

	$tz_ok      = true;
	$tz_details = array();
	foreach ( $tz_roundtrip_cases as $case => $spec ) {
		$probe = new $fulfil_class();
		$probe->set_date_fulfilled( $spec[0] );
		$stored = (string) ( $probe->get_date_fulfilled() ?? '' );
		$day    = Kuka_Island_Core_Invoice_Manager::shipment_date_only( $stored );

		if ( $stored !== $spec[1] || $day !== $spec[2] ) {
			$tz_ok = false;
		}
		$tz_details[] = $case . '=' . $spec[0] . '->' . ( '' === $stored ? 'unstored' : $stored ) . '->' . ( '' === $day ? 'refused' : $day );
	}

	// Raw UTC boundary either side of local midnight.
	$tz_boundary = array(
		'utc_20_59_59' => array( '2026-09-02 20:59:59', '2026-09-02' ),
		'utc_21_00_00' => array( '2026-09-02 21:00:00', '2026-09-03' ),
	);
	$tz_boundary_ok      = true;
	$tz_boundary_details = array();
	foreach ( $tz_boundary as $case => $spec ) {
		$day = Kuka_Island_Core_Invoice_Manager::shipment_date_only( $spec[0] );
		if ( $day !== $spec[1] ) {
			$tz_boundary_ok = false;
		}
		$tz_boundary_details[] = $case . '=' . $day;
	}

	/*
	 * Anything that is not exactly the stored format is refused, not coerced.
	 *
	 * The whitespace rows matter on their own: the parser used to trim its input
	 * before the round-trip compare, so a stored value carrying stray leading or
	 * trailing whitespace was quietly repaired and accepted -- which contradicted
	 * the strictness the method claims. Nothing is normalised now, so a corrupt
	 * row reads as corrupt.
	 */
	$tz_refusals = array(
		'empty'              => '',
		'date_only'          => '2026-09-02',
		'iso_t_separator'    => '2026-09-02T23:30:00',
		'trailing_words'     => '2026-09-02 23:30:00 extra',
		'impossible_day'     => '2026-02-30 10:00:00',
		'impossible_month'   => '2026-13-01 10:00:00',
		'free_text'          => 'not-a-date',
		'wrong_field_order'  => '02-09-2026 23:30:00',
		'leading_space'      => ' 2026-09-02 20:30:00',
		'trailing_space'     => '2026-09-02 20:30:00 ',
		'leading_tab'        => "\t2026-09-02 20:30:00",
		'trailing_tab'       => "2026-09-02 20:30:00\t",
		'leading_newline'    => "\n2026-09-02 20:30:00",
		'trailing_crlf'      => "2026-09-02 20:30:00\r\n",
		'whitespace_only'    => '   ',
		'tab_only'           => "\t",
		'inner_double_space' => '2026-09-02  20:30:00',
	);
	$tz_refused  = 0;
	$tz_accepted = array();
	foreach ( $tz_refusals as $refusal_case => $bad ) {
		if ( '' === Kuka_Island_Core_Invoice_Manager::shipment_date_only( $bad ) ) {
			++$tz_refused;
			continue;
		}
		$tz_accepted[] = $refusal_case;
	}

	// The canonical stored value is still accepted, unchanged.
	$tz_canonical_day = Kuka_Island_Core_Invoice_Manager::shipment_date_only( '2026-09-02 20:30:00' );

	// Two shipments either side of local midnight order as moments.
	$before_midnight = Kuka_Island_Core_Internet_Sales_Details::parse_fulfillment_datetime( '2026-09-02 20:50:00' );
	$after_midnight  = Kuka_Island_Core_Internet_Sales_Details::parse_fulfillment_datetime( '2026-09-02 21:10:00' );
	$midnight_order  = ( $after_midnight instanceof DateTimeImmutable && $before_midnight instanceof DateTimeImmutable )
		&& $after_midnight > $before_midnight
		&& '2026-09-02' === $before_midnight->format( 'Y-m-d' )
		&& '2026-09-03' === $after_midnight->format( 'Y-m-d' )
		&& 1200 === ( $after_midnight->getTimestamp() - $before_midnight->getTimestamp() )
		// The result is expressed where the shop lives.
		&& '+03:00' === $before_midnight->format( 'P' );

	// End to end: the day in the real request is this helper's answer for the
	// date WooCommerce actually stored.
	$tz_product = $make_shippable_product();
	$tz_order   = $make_physical_order( $test_run_id, $billing_props, $tz_product, 1 );
	$tz_id      = (int) $tz_order->get_id();
	$tz_items   = $tz_order->get_items();
	$tz_item    = reset( $tz_items );

	$purge_fulfillments( $fulfil_store, $tz_id );
	$fulfil_items( $fulfil_class, $tz_id, (int) $tz_item->get_id(), 1, 'dhl' );

	$tz_stored_raw = '';
	foreach ( (array) $fulfil_store->read_fulfillments( WC_Order::class, (string) $tz_id ) as $stored_fulfillment ) {
		$tz_stored_raw = (string) ( $stored_fulfillment->get_date_fulfilled() ?? '' );
	}

	$tz_transport = new Kuka_Island_Test_Numbering_Transport();
	$tz_transport->assigned_id = 'EDM2026000003000';
	$tz_manager   = new Kuka_Island_Core_Invoice_Manager( $fulfil_config, new Kuka_Island_Core_EDM_Provider( $fulfil_config, $tz_transport ) );

	$tz_send_error = '';
	try {
		$tz_manager->process_order( wc_get_order( $tz_id ) );
	} catch ( Throwable $t ) {
		$tz_send_error = get_class( $t ) . ': ' . $t->getMessage();
	}

	$tz_isd      = (array) ( $tz_transport->requests['SendInvoice']['INVOICE'][0]['HEADER']['INTERNETSALESDETAILS'] ?? array() );
	$tz_soap_day = (string) ( $tz_isd['gonderiBilgileri']['gonderimTarihi'] ?? '' );
	$tz_expected = Kuka_Island_Core_Invoice_Manager::shipment_date_only( $tz_stored_raw );
	$tz_shop_day = (string) wp_date( 'Y-m-d' );
	// The stored value really is UTC, so it differs from the shop clock.
	$tz_stored_is_utc = $tz_stored_raw === gmdate( 'Y-m-d H:i:s', strtotime( $tz_stored_raw . ' UTC' ) )
		&& substr( $tz_stored_raw, 11, 2 ) !== substr( (string) wp_date( 'H:i:s' ), 0, 2 );

	$purge_fulfillments( $fulfil_store, $tz_id );
	kuka_test_delete_order( $tz_id, $test_run_id );

	/*
	 * An unreadable stored date fails the whole block closed. Written straight
	 * into the meta, because set_date_fulfilled() rejects a malformed value
	 * outright -- this is the bad-import / hand-edited-row shape.
	 */
	$bad_date_product = $make_shippable_product();
	$bad_date_order   = $make_physical_order( $test_run_id, $billing_props, $bad_date_product, 1 );
	$bad_date_id      = (int) $bad_date_order->get_id();
	$bad_date_items   = $bad_date_order->get_items();
	$bad_date_item    = reset( $bad_date_items );

	$purge_fulfillments( $fulfil_store, $bad_date_id );
	$bad_fulfillment = $fulfil_items( $fulfil_class, $bad_date_id, (int) $bad_date_item->get_id(), 1, 'dhl' );
	$bad_fulfillment->update_meta_data( '_date_fulfilled', 'not-a-date' );
	$bad_fulfillment->save_meta_data();

	$bad_date_raw = '';
	foreach ( (array) $fulfil_store->read_fulfillments( WC_Order::class, (string) $bad_date_id ) as $stored_fulfillment ) {
		$bad_date_raw = (string) ( $stored_fulfillment->get_date_fulfilled() ?? '' );
	}

	$bad_facts     = Kuka_Island_Core_Invoice_Manager::shipment_gate( wc_get_order( $bad_date_id ) );
	$bad_transport = new Kuka_Island_Test_Numbering_Transport();
	$bad_manager   = new Kuka_Island_Core_Invoice_Manager( $fulfil_config, new Kuka_Island_Core_EDM_Provider( $fulfil_config, $bad_transport ) );

	$bad_code = '';
	try {
		$bad_manager->process_order( wc_get_order( $bad_date_id ) );
	} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
		$bad_code = $e->get_safe_error_code();
	} catch ( Throwable $t ) {
		$bad_code = get_class( $t );
	}

	$bad_reloaded = wc_get_order( $bad_date_id );
	$bad_hint     = Kuka_Island_Core_Invoice_Admin::operator_hint( $bad_reloaded, $fulfil_config );

	$report(
		'INVOICE_FULFILLMENT_DATE_USES_SHOP_TIMEZONE',
		// The environment this is measured in really is the mismatched one.
		'UTC' === date_default_timezone_get()
		&& 'Europe/Istanbul' === wp_timezone()->getName()
		// A local handover time round-trips to the correct local day.
		&& $tz_ok
		&& $tz_boundary_ok
		&& count( $tz_refusals ) === $tz_refused
		&& array() === $tz_accepted
		// Refusing padding did not cost the canonical value.
		&& '2026-09-02' === $tz_canonical_day
		&& true === $midnight_order
		// The real request carries the helper's answer for the stored value.
		&& '' === $tz_send_error
		&& '' !== $tz_stored_raw
		&& '' !== $tz_soap_day
		&& $tz_soap_day === $tz_expected
		&& $tz_soap_day === $tz_shop_day
		&& true === $tz_stored_is_utc
		// An unreadable stored date is refused, not coerced to today.
		&& 'not-a-date' === $bad_date_raw
		&& true === ( $bad_facts['facts']['shipment_date_invalid'] ?? false )
		&& Kuka_Island_Core_Invoice_Manager::ERROR_INTERNET_SALES_INCOMPLETE === $bad_code
		&& 0 === (int) ( $bad_transport->calls['SendInvoice'] ?? 0 )
		&& 0 === (int) ( $bad_transport->calls['LoadInvoice'] ?? 0 )
		&& Kuka_Island_Core_Invoice_Status::STATUS_BLOCKED === Kuka_Island_Core_Invoice_Order_Store::get_status( $bad_reloaded )
		&& 'Kargoya verilme tarihi okunamadı; fatura oluşturulmadı.' === $bad_hint,
		sprintf(
			'measured:woocommerce_setter_roundtrip_and_real_send|php_tz:%s|wp_tz:%s|storage:utc|roundtrip_cases:%d|%s|boundary:%s|refused:%d/%d|wrongly_accepted:%s|canonical:%s|midnight_ordering:%s|stored_raw:%s|soap_gonderimTarihi:%s|helper_day:%s|shop_today:%s|invalid_date:%s/SendInvoice=%d|status:%s|hint:%s',
			date_default_timezone_get(),
			wp_timezone()->getName(),
			count( $tz_roundtrip_cases ),
			implode( ' ', $tz_details ),
			implode( ' ', $tz_boundary_details ),
			$tz_refused,
			count( $tz_refusals ),
			empty( $tz_accepted ) ? 'none' : implode( ',', $tz_accepted ),
			$tz_canonical_day ?: 'REFUSED',
			$midnight_order ? 'correct' : 'WRONG',
			$tz_stored_raw ?: 'none',
			$tz_soap_day ?: 'none',
			$tz_expected ?: 'none',
			$tz_shop_day,
			$bad_code ?: 'none',
			$bad_transport->calls['SendInvoice'] ?? 0,
			Kuka_Island_Core_Invoice_Order_Store::get_status( $bad_reloaded ),
			$bad_hint
		)
	);

	$purge_fulfillments( $fulfil_store, $bad_date_id );
	kuka_test_delete_order( $bad_date_id, $test_run_id );

	/* ---------------------------------------------------------------------- */
	/* A legal-person carrier needs a ten-digit VKN, never a TCKN             */
	/* ---------------------------------------------------------------------- */

	/*
	 * The serialisation always writes gonderiTasiyan/tuzelKisi/vkn, so what goes
	 * there has to be a legal person's tax number: exactly ten digits. Eleven
	 * digits is a TCKN, which identifies a natural person and belongs in the
	 * gercekKisi branch -- deliberately not modelled this round.
	 */
	$vkn_cases = array(
		'ten_digits'    => array( '1234567890', true ),
		'eleven_digits' => array( '12345678901', false ),
		'nine_digits'   => array( '123456789', false ),
		'twelve_digits' => array( '123456789012', false ),
		'with_letters'  => array( '12345678AB', false ),
		'with_spaces'   => array( '123 456 789', false ),
		'empty'         => array( '', false ),
	);

	$vkn_ok      = true;
	$vkn_details = array();
	foreach ( $vkn_cases as $case => $spec ) {
		$case_conf = new Kuka_Island_Core_Invoice_Config(
			array_merge(
				$ready_overrides,
				array(
					'auto_send' => true,
					'carriers'  => array( 'dhl' => array( 'vkn' => $spec[0], 'title' => $test_carrier_title ) ),
				)
			)
		);
		$resolved = Kuka_Island_Core_Internet_Sales_Details::resolve_carrier( $case_conf, array( 'dhl' ) );

		$expected_error = '';
		if ( false === $spec[1] ) {
			$expected_error = '' === $spec[0]
				? Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_VKN_MISSING
				: Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_VKN_INVALID;
		}

		if ( $resolved['ok'] !== $spec[1] || $resolved['error'] !== $expected_error ) {
			$vkn_ok = false;
		}
		$vkn_details[] = $case . '=' . ( $resolved['ok'] ? 'accepted' : ( $resolved['error'] ?: 'refused' ) );
	}

	// An eleven-digit value, on the real send path, transmits nothing.
	$tckn_product = $make_shippable_product();
	$tckn_order   = $make_physical_order( $test_run_id, $billing_props, $tckn_product, 1 );
	$tckn_id      = (int) $tckn_order->get_id();
	$tckn_items   = $tckn_order->get_items();
	$tckn_item    = reset( $tckn_items );

	$purge_fulfillments( $fulfil_store, $tckn_id );
	$fulfil_items( $fulfil_class, $tckn_id, (int) $tckn_item->get_id(), 1, 'dhl' );

	$tckn_config    = new Kuka_Island_Core_Invoice_Config(
		array_merge(
			$ready_overrides,
			array(
				'auto_send' => true,
				// Eleven digits: a natural person's number in a legal person's slot.
				'carriers'  => array( 'dhl' => array( 'vkn' => '12345678901', 'title' => $test_carrier_title ) ),
			)
		)
	);
	$tckn_transport = new Kuka_Island_Test_Numbering_Transport();
	$tckn_manager   = new Kuka_Island_Core_Invoice_Manager( $tckn_config, new Kuka_Island_Core_EDM_Provider( $tckn_config, $tckn_transport ) );

	$tckn_code = '';
	try {
		$tckn_manager->process_order( wc_get_order( $tckn_id ) );
	} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $e ) {
		$tckn_code = $e->get_safe_error_code();
	} catch ( Throwable $t ) {
		$tckn_code = get_class( $t );
	}

	$purge_fulfillments( $fulfil_store, $tckn_id );
	kuka_test_delete_order( $tckn_id, $test_run_id );

	/*
	 * And the accepted ten-digit value lands in exactly the right node. Read off
	 * the request the real-WSDL SoapClient serialised earlier in this run, so
	 * the schema itself is what placed the elements.
	 */
	$isd_xml       = (array) ( $GLOBALS['kuka_isd_xml_facts'] ?? array() );
	$xml_available = array() !== $isd_xml;
	$xml_vkn       = (string) ( $isd_xml['vkn'] ?? '' );
	$xml_unvan     = (string) ( $isd_xml['unvan'] ?? '' );
	$xml_tuzel     = (int) ( $isd_xml['tuzel_nodes'] ?? -1 );
	$xml_gercek    = (int) ( $isd_xml['gercek_nodes'] ?? -1 );

	$report(
		'INVOICE_LEGAL_CARRIER_REQUIRES_10_DIGIT_VKN',
		$vkn_ok
		// The eleven-digit case is specifically measured as refused, on the real
		// send path, with nothing transmitted.
		&& Kuka_Island_Core_Invoice_Manager::ERROR_INTERNET_SALES_INCOMPLETE === $tckn_code
		&& 0 === (int) ( $tckn_transport->calls['SendInvoice'] ?? 0 )
		&& 0 === (int) ( $tckn_transport->calls['LoadInvoice'] ?? 0 )
		// The accepted value is in tuzelKisi/vkn, ten digits, with its unvan
		// beside it, and the natural-person branch is not emitted at all.
		&& true === $xml_available
		&& 1 === preg_match( '/^\d{10}$/', $xml_vkn )
		&& '9990001111' === $xml_vkn
		&& 'TEST KARGO A.S. - GERCEK DEGIL' === $xml_unvan
		&& 1 === $xml_tuzel
		&& 0 === $xml_gercek,
		sprintf(
			'measured:production_resolver_real_send_and_real_wsdl|cases:%d|%s|eleven_digit_send:%s/SendInvoice=%d|xml_tuzel_vkn:%s|xml_vkn_digits:%d|xml_tuzel_unvan:%s|tuzelKisi_nodes:%d|gercekKisi_nodes:%d',
			count( $vkn_cases ),
			implode( ' ', $vkn_details ),
			$tckn_code ?: 'none',
			$tckn_transport->calls['SendInvoice'] ?? 0,
			$xml_vkn ?: 'absent',
			strlen( $xml_vkn ),
			$xml_unvan ?: 'absent',
			$xml_tuzel,
			$xml_gercek
		)
	);

	// Every product this section created goes away with it, and the sweep is run
	// again so nothing with the fixture title survives the script either way.
	$product_residue = array();
	foreach ( (array) $GLOBALS['kuka_test_products'] as $product_id ) {
		wp_delete_post( (int) $product_id, true );
		if ( null !== get_post( (int) $product_id ) ) {
			$product_residue[] = (int) $product_id;
		}
	}
	$GLOBALS['kuka_test_products'] = array();
	$purge_fixture_products( $fulfil_product_title );

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$fixture_products_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s", 'product', $fulfil_product_title ) );

	$report(
		'INVOICE_FULFILLMENT_FIXTURES_CLEANED',
		array() === $product_residue && 0 === $fixture_products_left,
		sprintf(
			'product_residue:%s|fixture_products_left:%d|stale_purged_on_entry:%d',
			empty( $product_residue ) ? 'none' : implode( ',', $product_residue ),
			$fixture_products_left,
			count( $stale_products_purged )
		)
	);
}

/* ========================================================================== */
/* A session-expired fault never re-transmits the document                     */
/* ========================================================================== */

/*
 * Kuka_Island_Core_EDM_Client::execute_with_session() re-runs its callback once
 * when EDM reports an expired session. That is right for a read and for an
 * idempotent call, and it was WRONG for SendInvoice: re-running that callback is
 * a second transmission of the same document. EDM reports session expiry before
 * it processes the body, so in practice the first call did nothing -- but that is
 * not a guarantee anyone here can make, and the cost of being wrong is two
 * fiscal documents for one sale.
 *
 * SendInvoice now forbids the retry. The fault surfaces, the manager records
 * send_uncertain, and the poller asks EDM what happened.
 */
final class Kuka_Island_Test_Session_Expiry_Transport implements Kuka_Island_Core_SOAP_Transport_Interface {
	/** @var array<string, int> */
	public array $calls = array();
	/** @var string Operation that should raise the session-expired fault. */
	public string $expire_on;

	public function __construct( string $expire_on ) {
		$this->expire_on = $expire_on;
	}

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}

	public function call( string $operation, array $parameters ) {
		$this->calls[ $operation ] = ( $this->calls[ $operation ] ?? 0 ) + 1;

		if ( 'Login' === $operation ) {
			return array( 'SESSION_ID' => 'session-expiry-fixture', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}

		if ( $operation === $this->expire_on ) {
			// The shape the client's is_session_expired_fault() recognises.
			throw new SoapFault( 'Client', 'Session expired or invalid session id.' );
		}

		if ( 'GetInvoiceStatus' === $operation ) {
			return array(
				'INVOICE_STATUS' => array(
					array(
						'UUID'   => $parameters['INVOICE']['UUID'] ?? 'uuid-expiry',
						'ID'     => 'EDM2026000004000',
						'HEADER' => array( 'STATUS' => 'SEND - SUCCEED' ),
					),
				),
			);
		}

		return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
	}
}

// A transmission: the fault must NOT be retried, so SendInvoice stays at one.
$expiry_send_transport = new Kuka_Island_Test_Session_Expiry_Transport( 'SendInvoice' );
$expiry_send_manager   = new Kuka_Island_Core_Invoice_Manager( $config, new Kuka_Island_Core_EDM_Provider( $config, $expiry_send_transport ) );
$expiry_send_order     = kuka_create_lock_order( $test_run_id, $billing_props, array() );
$expiry_send_order_id  = (int) $expiry_send_order->get_id();

$expiry_send_error = '';
try {
	$expiry_send_manager->process_order( $expiry_send_order );
} catch ( Throwable $t ) {
	$expiry_send_error = get_class( $t );
}

$expiry_send_order->read_meta_data( true );
$expiry_send_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $expiry_send_order );
$expiry_send_calls  = (int) ( $expiry_send_transport->calls['SendInvoice'] ?? 0 );
$expiry_send_logins = (int) ( $expiry_send_transport->calls['Login'] ?? 0 );
$expiry_poll_booked = count( $poll_pending_ids( $expiry_send_order_id ) );

Kuka_Island_Core_Invoice_Status_Poller::unschedule( $expiry_send_order_id );
kuka_test_delete_order( $expiry_send_order_id, $test_run_id );

/*
 * A READ still retries, because re-asking a question is free. Measured on the
 * same fault shape so the difference is the operation, not the fixture.
 */
$expiry_read_transport = new Kuka_Island_Test_Session_Expiry_Transport( 'nothing' );
$expiry_read_client    = new Kuka_Island_Core_EDM_Client( $config, $expiry_read_transport );
$expiry_read_ok        = false;
try {
	$expiry_read_ok = $expiry_read_client->get_invoice_status( 'uuid-expiry', 'EDM2026000004000' ) instanceof Kuka_Island_Core_Invoice_Result;
} catch ( Throwable $t ) {
	$expiry_read_ok = false;
}

// And the client source states the rule where the retry lives.
$client_source     = (string) file_get_contents( trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/includes/invoice/class-edm-client.php' );
$retry_flag_wired  = str_contains( $client_source, 'bool $allow_session_retry = true' )
	&& str_contains( $client_source, '$allow_session_retry && $this->is_session_expired_fault( $fault )' );

$report(
	'INVOICE_SESSION_EXPIRY_NEVER_RETRANSMITS',
	// Exactly one SendInvoice, despite the session-expired fault.
	1 === $expiry_send_calls
	&& 0 === (int) ( $expiry_send_transport->calls['LoadInvoice'] ?? 0 )
	// One Login only: the client did not re-login to try again.
	&& 1 === $expiry_send_logins
	// The fault surfaced, and the manager put the document in the uncertain
	// state whose whole purpose is "ask, never resend".
	&& '' !== $expiry_send_error
	&& Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN === $expiry_send_status
	&& 1 === $expiry_poll_booked
	// A read is unaffected.
	&& true === $expiry_read_ok
	&& true === $retry_flag_wired,
	sprintf(
		'measured:production_send_with_session_expired_fault|SendInvoice=%d|Login=%d|LoadInvoice=%d|threw:%s|status:%s|poll_actions_pending:%d|read_path_unaffected:%s|retry_flag_wired:%s',
		$expiry_send_calls,
		$expiry_send_logins,
		$expiry_send_transport->calls['LoadInvoice'] ?? 0,
		'' === $expiry_send_error ? 'no' : 'yes',
		$expiry_send_status,
		$expiry_poll_booked,
		$expiry_read_ok ? 'yes' : 'no',
		$retry_flag_wired ? 'yes' : 'no'
	)
);

/* ========================================================================== */
/* REQUEST_HEADER contract and safe SOAP fault classification                  */
/* ========================================================================== */

/**
 * Transport that records every request instead of sending it.
 *
 * Answers just enough for the client to proceed. No network, no document.
 */
final class Kuka_Island_Test_Header_Capture_Transport implements Kuka_Island_Core_SOAP_Transport_Interface {
	/** @var array<string, array<string, mixed>> Last request per operation. */
	public array $captured = array();

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}

	public function call( string $operation, array $parameters ) {
		$this->captured[ $operation ] = $parameters;

		switch ( $operation ) {
			case 'Login':
				return array( 'SESSION_ID' => 'session-fixture-0001', 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
			case 'CheckCounter':
				return array( 'COUNTER_LEFT' => 42 );
			case 'GetInvoiceSerial':
				return array( 'INVOICESERIAL' => array() );
			case 'CheckUser':
				return array( 'USER' => array() );
			default:
				return array( 'REQUEST_RETURN' => array( 'RETURN_CODE' => 0 ) );
		}
	}
}

$header_transport = new Kuka_Island_Test_Header_Capture_Transport();
$header_client    = new Kuka_Island_Core_EDM_Client( $config, $header_transport );
$header_client->login();
$header_client->check_counter();
$header_client->get_invoice_serial( '', (int) gmdate( 'Y' ), '' );
$header_client->check_user( '1234567890' );
$header_client->logout();

// WSDL tns:REQUEST_HEADERType, and the fields EDM's reference envelope fills.
$expected_header_fields = array( 'SESSION_ID', 'CLIENT_TXN_ID', 'ACTION_DATE', 'REASON', 'APPLICATION_NAME', 'HOSTNAME', 'CHANNEL_NAME', 'COMPRESSED' );

$login_header  = (array) ( $header_transport->captured['Login']['REQUEST_HEADER'] ?? array() );
$missing_login = array();
foreach ( $expected_header_fields as $field ) {
	if ( ! array_key_exists( $field, $login_header ) || '' === (string) $login_header[ $field ] ) {
		$missing_login[] = $field;
	}
}

$report(
	'INVOICE_LOGIN_REQUEST_HEADER_CONTRACT',
	array() === $missing_login
	// Login carries no session yet, so the reference envelope sends 0.
	&& '0' === (string) ( $login_header['SESSION_ID'] ?? '' )
	&& 'Login' === (string) ( $login_header['REASON'] ?? '' )
	&& 'N' === (string) ( $login_header['COMPRESSED'] ?? '' )
	&& 'ozelyazilim.kukaisland' === (string) ( $login_header['APPLICATION_NAME'] ?? '' )
	&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', (string) ( $login_header['ACTION_DATE'] ?? '' ) )
	&& 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) ( $login_header['CLIENT_TXN_ID'] ?? '' ) ),
	sprintf(
		'fields:%d|missing:%s|session_id:%s|reason:%s|compressed:%s|application_name_ok:%s|action_date_shape:%s|client_txn_id_uuid:%s',
		count( $expected_header_fields ),
		empty( $missing_login ) ? 'none' : implode( ',', $missing_login ),
		(string) ( $login_header['SESSION_ID'] ?? 'absent' ),
		(string) ( $login_header['REASON'] ?? 'absent' ),
		(string) ( $login_header['COMPRESSED'] ?? 'absent' ),
		'ozelyazilim.kukaisland' === (string) ( $login_header['APPLICATION_NAME'] ?? '' ) ? 'yes' : 'no',
		1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', (string) ( $login_header['ACTION_DATE'] ?? '' ) ) ? 'ok' : 'bad',
		1 === preg_match( '/^[0-9a-f-]{36}$/i', (string) ( $login_header['CLIENT_TXN_ID'] ?? '' ) ) ? 'yes' : 'no'
	)
);

// Every session-bearing operation carries the same complete header, its own
// REASON and the real session id.
$session_ops     = array( 'CheckCounter', 'GetInvoiceSerial', 'CheckUser', 'Logout' );
$session_problem = array();
foreach ( $session_ops as $op ) {
	$hdr = (array) ( $header_transport->captured[ $op ]['REQUEST_HEADER'] ?? array() );
	foreach ( $expected_header_fields as $field ) {
		if ( ! array_key_exists( $field, $hdr ) || '' === (string) $hdr[ $field ] ) {
			$session_problem[] = $op . ':' . $field;
		}
	}
	if ( 'session-fixture-0001' !== (string) ( $hdr['SESSION_ID'] ?? '' ) ) {
		$session_problem[] = $op . ':session_id';
	}
	if ( $op !== (string) ( $hdr['REASON'] ?? '' ) ) {
		$session_problem[] = $op . ':reason';
	}
}

$report(
	'INVOICE_SESSION_REQUEST_HEADER_CONTRACT',
	array() === $session_problem && count( $session_ops ) === count( array_intersect( $session_ops, array_keys( $header_transport->captured ) ) ),
	sprintf(
		'operations:%d|complete:%s|problems:%s',
		count( $session_ops ),
		array() === $session_problem ? 'yes' : 'no',
		empty( $session_problem ) ? 'none' : implode( ',', $session_problem )
	)
);

// SendInvoice keeps CLIENT_TXN_ID bound to the document UUID: it is the
// idempotency key EDM sees, so the richer header must not replace it.
$send_transport = new Kuka_Island_Test_Header_Capture_Transport();
$send_client    = new Kuka_Island_Core_EDM_Client( $config, $send_transport );
$send_reflection = new ReflectionMethod( Kuka_Island_Core_EDM_Client::class, 'build_request_header' );
$send_reflection->setAccessible( true );
$bound_header = (array) $send_reflection->invoke( $send_client, 'session-fixture-0001', 'SendInvoice', 'uuid-fixture-abc' );

$report(
	'INVOICE_SENDINVOICE_HEADER_KEEPS_UUID',
	'uuid-fixture-abc' === (string) ( $bound_header['CLIENT_TXN_ID'] ?? '' )
	&& 'SendInvoice' === (string) ( $bound_header['REASON'] ?? '' )
	&& 'session-fixture-0001' === (string) ( $bound_header['SESSION_ID'] ?? '' )
	&& 'N' === (string) ( $bound_header['COMPRESSED'] ?? '' ),
	sprintf(
		'client_txn_id_bound:%s|reason:%s|compressed:%s',
		'uuid-fixture-abc' === (string) ( $bound_header['CLIENT_TXN_ID'] ?? '' ) ? 'yes' : 'no',
		(string) ( $bound_header['REASON'] ?? 'absent' ),
		(string) ( $bound_header['COMPRESSED'] ?? 'absent' )
	)
);

// Fault classification: fixed vocabulary, correct retry semantics.
$fault_cases = array(
	'auth_turkish'      => array( 's:Client', 'Kullanıcı adı veya şifre hatalı', Kuka_Island_Core_EDM_Fault_Classifier::CAT_CREDENTIALS, false ),
	'auth_english'      => array( 's:Client', 'Invalid login: user name not recognised', Kuka_Island_Core_EDM_Fault_Classifier::CAT_CREDENTIALS, false ),
	'unauthorised'      => array( 'Client', 'Unauthorized', Kuka_Island_Core_EDM_Fault_Classifier::CAT_CREDENTIALS, false ),
	'session'           => array( 's:Client', 'Aktif session bulunamadi', Kuka_Island_Core_EDM_Fault_Classifier::CAT_NOT_FOUND, false ),
	'session_expired'   => array( 's:Client', 'Session expired, please login again', Kuka_Island_Core_EDM_Fault_Classifier::CAT_SESSION, false ),
	'not_found'         => array( 'HTTP', 'Error Fetching http headers: 404 Not Found', Kuka_Island_Core_EDM_Fault_Classifier::CAT_NOT_FOUND, false ),
	'timeout'           => array( 'HTTP', 'Connection timed out after 30 seconds', Kuka_Island_Core_EDM_Fault_Classifier::CAT_TIMEOUT, true ),
	'tls'               => array( 'HTTP', 'SSL certificate problem: unable to verify', Kuka_Island_Core_EDM_Fault_Classifier::CAT_TLS, true ),
	'server_500'        => array( 'HTTP', 'Internal Server Error', Kuka_Island_Core_EDM_Fault_Classifier::CAT_SERVER, true ),
	'schema'            => array( 's:Client', 'The formatter threw an exception: element was not expected', Kuka_Island_Core_EDM_Fault_Classifier::CAT_CONTRACT, false ),
	'validation'        => array( 's:Client', 'Zorunlu alan eksik', Kuka_Island_Core_EDM_Fault_Classifier::CAT_CONTRACT, false ),
	'bare_client'       => array( 's:Client', 'islem gerceklestirilemedi', Kuka_Island_Core_EDM_Fault_Classifier::CAT_CONTRACT, false ),
	'bare_server'       => array( 's:Server', 'islem gerceklestirilemedi', Kuka_Island_Core_EDM_Fault_Classifier::CAT_SERVER, true ),
	'bare_http'         => array( 'HTTP', 'unexpected transport condition', Kuka_Island_Core_EDM_Fault_Classifier::CAT_TRANSPORT, true ),
	'no_code_no_marker' => array( '', 'something entirely unmodelled', Kuka_Island_Core_EDM_Fault_Classifier::CAT_UNCLASSIFIED, true ),
);

/*
 * The safe line must be built ENTIRELY from the fixed vocabulary. Matching a
 * strict grammar of allow-listed alternatives is what proves no remote text can
 * appear -- searching for message words would not, because a category name
 * legitimately shares ordinary English words such as "server" or "session" with
 * fault text.
 */
$safe_line_grammar = sprintf(
	'/^category:(%s)\|fault_kind:(%s)\|marker:(%s)\|retryable:(yes|no)$/',
	implode( '|', array_map( 'preg_quote', Kuka_Island_Core_EDM_Fault_Classifier::categories() ) ),
	implode( '|', array_map( 'preg_quote', Kuka_Island_Core_EDM_Fault_Classifier::fault_kinds() ) ),
	implode( '|', array_map( 'preg_quote', Kuka_Island_Core_EDM_Fault_Classifier::marker_names() ) )
);

$classifier_ok    = true;
$classifier_wrong = array();
foreach ( $fault_cases as $case => $spec ) {
	$verdict = Kuka_Island_Core_EDM_Fault_Classifier::classify( $spec[0], $spec[1] );
	$hit     = $verdict['category'] === $spec[2]
		&& $verdict['retryable'] === $spec[3]
		// Exactly four fields. A digest of remote text would be a password
		// verification oracle, so none is produced.
		&& array( 'category', 'fault_kind', 'marker', 'retryable' ) === array_keys( $verdict )
		&& is_bool( $verdict['retryable'] );

	if ( 1 !== preg_match( $safe_line_grammar, Kuka_Island_Core_EDM_Fault_Classifier::to_safe_line( $verdict ) ) ) {
		$hit = false;
	}
	if ( ! $hit ) {
		$classifier_ok      = false;
		$classifier_wrong[] = $case . '(' . $verdict['category'] . ')';
	}
}

$report(
	'INVOICE_FAULT_CLASSIFIER_MATRIX',
	$classifier_ok,
	sprintf(
		'cases:%d|wrong:%s|fields:4|digest_field:absent',
		count( $fault_cases ),
		empty( $classifier_wrong ) ? 'none' : implode( ',', $classifier_wrong )
	)
);

/*
 * Adversarial injection. Every diagnostic field is filled with a credential in
 * turn, plus a whole-array fill and a truthy-but-not-boolean retryable, and the
 * result is pushed through the real exception surface. Nothing may survive.
 */
$injection_secrets = array(
	'user'     => 'test_user',
	'password' => 'secret_password_123',
	'session'  => 'sess-abc-999',
	'vkn'      => '1234567890',
	'secret'   => 'secret_key_456',
);
$injection_fields = array( 'category', 'fault_kind', 'marker', 'retryable' );

$injection_cases = array();
foreach ( $injection_fields as $field ) {
	foreach ( $injection_secrets as $label => $value ) {
		$injection_cases[ $field . '_' . $label ] = array( $field => $value );
	}
}
// Every field poisoned at once, and an unlisted extra key.
$injection_cases['all_fields'] = array(
	'category'   => 'secret_password_123',
	'fault_kind' => 'test_user',
	'marker'     => 'sess-abc-999',
	'retryable'  => '1234567890',
	'extra_key'  => 'secret_key_456',
);
// Shapes that a lax implementation would pass straight through.
$injection_cases['nested_array']   = array( 'category' => array( 'secret_password_123' ) );
$injection_cases['object_value']   = array( 'marker' => (object) array( 'x' => 'sess-abc-999' ) );
$injection_cases['truthy_retry']   = array( 'category' => 'network_timeout', 'retryable' => 'yes' );
$injection_cases['numeric_retry']  = array( 'category' => 'network_timeout', 'retryable' => 1 );
$injection_cases['case_variant']   = array( 'category' => 'CREDENTIALS_REJECTED' );
$injection_cases['padded_valid']   = array( 'category' => ' network_timeout ' );

$injection_leaks       = array();
$injection_shape_bad   = array();
$injection_retry_leaks = array();
foreach ( $injection_cases as $case => $payload ) {
	$exception = ( new Kuka_Island_Core_Invoice_Transient_Exception(
		'EDM SOAP Fault.',
		'edm_soap_fault'
	) )->set_diagnostic( $payload );

	$surface = implode(
		"\n",
		array(
			$exception->getMessage(),
			$exception->get_safe_error_code(),
			$exception->get_user_message(),
			(string) wp_json_encode( $exception->get_diagnostic() ),
			$exception->get_safe_diagnostic_line(),
		)
	);

	foreach ( $injection_secrets as $secret ) {
		if ( str_contains( $surface, $secret ) ) {
			$injection_leaks[] = $case;
			break;
		}
	}

	$stored = $exception->get_diagnostic();
	if ( array( 'category', 'fault_kind', 'marker', 'retryable' ) !== array_keys( $stored )
		|| ! is_bool( $stored['retryable'] )
		|| 1 !== preg_match( $safe_line_grammar, $exception->get_safe_diagnostic_line() ) ) {
		$injection_shape_bad[] = $case;
	}
	// A non-boolean retryable must fail closed, never become true.
	if ( in_array( $case, array( 'truthy_retry', 'numeric_retry', 'retryable_user', 'retryable_password', 'retryable_session', 'retryable_vkn', 'retryable_secret', 'all_fields' ), true )
		&& true === $stored['retryable'] ) {
		$injection_retry_leaks[] = $case;
	}
}

// An exception that never had a diagnostic must still print nothing.
$no_diagnostic_line = ( new Kuka_Island_Core_Invoice_Transient_Exception( 'x', 'edm_soap_fault' ) )->get_safe_diagnostic_line();

$report(
	'INVOICE_DIAGNOSTIC_INJECTION_REFUSED',
	array() === $injection_leaks
	&& array() === $injection_shape_bad
	&& array() === $injection_retry_leaks
	&& '' === $no_diagnostic_line,
	sprintf(
		'cases:%d|secrets:%d|leaked:%s|bad_shape:%s|retry_forced_open:%s|unset_diagnostic_prints:%s',
		count( $injection_cases ),
		count( $injection_secrets ),
		empty( $injection_leaks ) ? 'none' : implode( ',', array_unique( $injection_leaks ) ),
		empty( $injection_shape_bad ) ? 'none' : implode( ',', array_unique( $injection_shape_bad ) ),
		empty( $injection_retry_leaks ) ? 'none' : implode( ',', $injection_retry_leaks ),
		'' === $no_diagnostic_line ? 'nothing' : 'SOMETHING'
	)
);

/*
 * A fault message that quotes credentials back must not survive anywhere on the
 * exception: not in the message, the safe code, the user message or the
 * diagnostic line.
 */
$leaky_message = 'Login failed for user name test_user with password secret_password_123 (session sess-abc-999, VKN 1234567890)';
$leak_transport = new class( $leaky_message ) implements Kuka_Island_Core_SOAP_Transport_Interface {
	private string $message;

	public function __construct( string $message ) {
		$this->message = $message;
	}

	public function get_last_request(): string {
		return '';
	}

	public function get_last_response(): string {
		return '';
	}

	public function call( string $operation, array $parameters ) {
		throw new SoapFault( 's:Client', $this->message );
	}
};

$leak_needles = array( 'test_user', 'secret_password_123', 'sess-abc-999', '1234567890', 'secret_key_456' );
$leak_found   = array();
try {
	( new Kuka_Island_Core_EDM_Client( $config, $leak_transport ) )->login();
} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
	$surface = implode(
		"\n",
		array(
			$e->getMessage(),
			$e->get_safe_error_code(),
			$e->get_user_message(),
			$e->get_safe_diagnostic_line(),
			(string) wp_json_encode( $e->get_diagnostic() ),
		)
	);
	foreach ( $leak_needles as $needle ) {
		if ( str_contains( $surface, $needle ) ) {
			$leak_found[] = $needle;
		}
	}
	$leak_code       = $e->get_safe_error_code();
	$leak_diagnostic = $e->get_safe_diagnostic_line();
	$leak_shape_ok   = 1 === preg_match( $safe_line_grammar, $leak_diagnostic );
}

$report(
	'INVOICE_FAULT_MESSAGE_NEVER_LEAKS',
	array() === $leak_found
	&& 'edm_auth_failed' === ( $leak_code ?? '' )
	&& true === ( $leak_shape_ok ?? false )
	&& str_contains( (string) ( $leak_diagnostic ?? '' ), 'category:credentials_rejected' )
	&& str_contains( (string) ( $leak_diagnostic ?? '' ), 'marker:authentication' ),
	sprintf(
		'needles_checked:%d|leaked:%s|safe_code:%s|diagnostic:%s',
		count( $leak_needles ),
		empty( $leak_found ) ? 'none' : implode( ',', $leak_found ),
		(string) ( $leak_code ?? 'none' ),
		(string) ( $leak_diagnostic ?? 'none' )
	)
);

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
