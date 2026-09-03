<?php
/**
 * The persistent run gate that survives deactivation mid-request.
 *
 * Deactivating a plugin removes its hooks for the NEXT request. It does nothing
 * about a worker that is already running: Action Scheduler may have loaded this
 * module, picked up a status query and be several steps into it at the moment
 * an operator clicks "Deactivate". Removing a hook cannot reach into that
 * request. Only a check the worker itself performs can.
 *
 * So deactivation writes a persistent flag and every carrier call reads it
 * immediately before contacting the network. The read deliberately bypasses the
 * object cache -- see is_disabled() -- because a value cached earlier in the
 * same request is exactly the value that would let the call through.
 *
 * Absent option means allowed. A fresh install ships the plugin inactive, so
 * nothing here ever loads until someone activates it, and activation clears the
 * flag explicitly.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Runtime_Gate {

	/** Autoload is off: this must never be served from a request-start snapshot. */
	public const OPTION = 'kuka_island_shipping_runtime_disabled';

	/** Safe error code recorded on an order when the gate stops a call. */
	public const CODE = 'shipping_runtime_disabled';

	/** Close the gate. Called on deactivation. */
	public static function disable(): void {
		update_option( self::OPTION, '1', false );
	}

	/** Open the gate. Called on activation. */
	public static function enable(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Whether carrier calls are currently forbidden.
	 *
	 * Read straight from the options table rather than through get_option().
	 * get_option() answers from the object cache, and under a persistent cache
	 * it would happily return a value read before deactivation happened --
	 * which is the one case this gate exists for. A direct read is the only
	 * version of this check that works mid-request.
	 *
	 * Fail-open on a missing $wpdb is deliberate: without a database there is no
	 * option to have been set, and no order to write either.
	 */
	public static function is_disabled(): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION
			)
		);

		return '1' === (string) $value;
	}

	/** Operator-facing reason, safe for an order note. */
	public static function message(): string {
		return __( 'Kargo otomasyonu eklentisi devre dışı bırakıldığı için çağrı yapılmadı. Gönderi oluşturulmadı.', 'kuka-island-shipping-automation' );
	}
}
