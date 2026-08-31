<?php
/**
 * Database keyset fingerprint for external (cross-process) test isolation.
 *
 * Prints a single line so the shell harness can compare the fingerprint before
 * and after the invoice verification run without embedding SQL in verify.sh.
 *
 * Covered tables: wc_orders, wc_orders_meta, wc_order_addresses,
 * wc_order_operational_data, woocommerce_order_items,
 * woocommerce_order_itemmeta, order notes (comments), wc_order_stats,
 * wc_customer_lookup, wc_order_product_lookup, wc_order_tax_lookup,
 * wc_order_coupon_lookup.
 *
 * Run with:
 * docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-invoice-keyset.php
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

/**
 * Table -> ordered key expression map used for the fingerprint.
 *
 * @return array<string, array{table: string, sql: string}>
 */
function kuka_invoice_keyset_definitions(): array {
	global $wpdb;

	return array(
		'orders'          => array(
			'table' => $wpdb->prefix . 'wc_orders',
			'sql'   => 'SELECT CONCAT(id, ":", status, ":", COALESCE(total_amount, "")) FROM %1$s ORDER BY id ASC',
		),
		'orders_meta'     => array(
			'table' => $wpdb->prefix . 'wc_orders_meta',
			'sql'   => 'SELECT CONCAT(order_id, ":", meta_key, "=", COALESCE(meta_value, "")) FROM %1$s ORDER BY order_id ASC, meta_key ASC, id ASC',
		),
		'order_addresses' => array(
			'table' => $wpdb->prefix . 'wc_order_addresses',
			'sql'   => 'SELECT CONCAT(order_id, ":", address_type) FROM %1$s ORDER BY order_id ASC, address_type ASC',
		),
		'order_op_data'   => array(
			'table' => $wpdb->prefix . 'wc_order_operational_data',
			'sql'   => 'SELECT CONCAT(order_id, ":", COALESCE(order_key, "")) FROM %1$s ORDER BY order_id ASC',
		),
		'order_items'     => array(
			'table' => $wpdb->prefix . 'woocommerce_order_items',
			'sql'   => 'SELECT CONCAT(order_item_id, ":", order_id, ":", order_item_type) FROM %1$s ORDER BY order_item_id ASC',
		),
		'order_itemmeta'  => array(
			'table' => $wpdb->prefix . 'woocommerce_order_itemmeta',
			'sql'   => 'SELECT CONCAT(order_item_id, ":", meta_key) FROM %1$s ORDER BY meta_id ASC',
		),
		'order_notes'     => array(
			'table' => $wpdb->comments,
			'sql'   => 'SELECT CONCAT(comment_ID, ":", comment_post_ID) FROM %1$s WHERE comment_type = "order_note" ORDER BY comment_ID ASC',
		),
		'order_stats'     => array(
			'table' => $wpdb->prefix . 'wc_order_stats',
			'sql'   => 'SELECT CONCAT(order_id, ":", status, ":", COALESCE(total_sales, "")) FROM %1$s ORDER BY order_id ASC',
		),
		'customer_lookup' => array(
			'table' => $wpdb->prefix . 'wc_customer_lookup',
			'sql'   => 'SELECT CONCAT(customer_id, ":", COALESCE(email, "")) FROM %1$s ORDER BY customer_id ASC',
		),
		'product_lookup'  => array(
			'table' => $wpdb->prefix . 'wc_order_product_lookup',
			'sql'   => 'SELECT CONCAT(order_item_id, ":", order_id, ":", product_id) FROM %1$s ORDER BY order_item_id ASC',
		),
		'tax_lookup'      => array(
			'table' => $wpdb->prefix . 'wc_order_tax_lookup',
			'sql'   => 'SELECT CONCAT(order_id, ":", tax_rate_id, ":", COALESCE(total_tax, "")) FROM %1$s ORDER BY order_id ASC, tax_rate_id ASC',
		),
		'coupon_lookup'   => array(
			'table' => $wpdb->prefix . 'wc_order_coupon_lookup',
			'sql'   => 'SELECT CONCAT(order_id, ":", coupon_id, ":", COALESCE(discount_amount, "")) FROM %1$s ORDER BY order_id ASC, coupon_id ASC',
		),
	);
}

/**
 * Capture the per-table keysets plus a combined fingerprint.
 *
 * @return array{tables: array<string, array<int, string>>, missing: array<int, string>, hash: string}
 */
function kuka_invoice_capture_keysets(): array {
	global $wpdb;

	$tables  = array();
	$missing = array();

	foreach ( kuka_invoice_keyset_definitions() as $key => $definition ) {
		$table = $definition['table'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $exists ) {
			$missing[]      = $key;
			$tables[ $key ] = array();
			continue;
		}

		$sql = sprintf( $definition['sql'], $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$tables[ $key ] = array_map( 'strval', (array) $wpdb->get_col( $sql ) );
	}

	return array(
		'tables'  => $tables,
		'missing' => $missing,
		'hash'    => md5( wp_json_encode( $tables ) ),
	);
}

if ( ! defined( 'KUKA_INVOICE_KEYSET_LIBRARY_ONLY' ) ) {
	$snapshot = kuka_invoice_capture_keysets();
	WP_CLI::line(
		sprintf(
			'INVOICE_DB_KEYSET=%s|tables:%d|missing:%s',
			$snapshot['hash'],
			count( $snapshot['tables'] ),
			empty( $snapshot['missing'] ) ? 'none' : implode( ',', $snapshot['missing'] )
		)
	);
}
