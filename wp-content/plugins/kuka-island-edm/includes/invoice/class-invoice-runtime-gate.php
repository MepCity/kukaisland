<?php
/**
 * The persistent run gate that survives deactivation mid-request.
 *
 * Deactivating a plugin removes its hooks for the NEXT request. It does nothing
 * about a worker that is already running: Action Scheduler may have loaded this
 * module, picked up an invoice job and be several steps into it at the moment
 * an operator clicks "Deactivate". Removing a hook cannot reach into that
 * request. Only a check the worker itself performs can.
 *
 * So deactivation writes a persistent flag and the transmission path reads it
 * immediately before contacting EDM. The read deliberately bypasses the object
 * cache -- see is_disabled() -- because a value cached earlier in the same
 * request is exactly the value that would let the send through.
 *
 * Absent option means allowed. A fresh install ships the plugin inactive, so
 * nothing here ever loads until someone activates it, and activation clears the
 * flag explicitly.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one question the transmission path asks before contacting EDM.
 *
 * DECLARED HERE, BESIDE ITS ONLY PRODUCTION IMPLEMENTATION, on purpose: the
 * contract and the real gate are one concept, and every `require_once` of this
 * file already brings both -- including the activator's, which loads the gate
 * directly and would otherwise need a second require in the right order.
 *
 * WHY A SEAM EXISTS AT ALL. The real gate answers from the options table, and
 * that answer is the site's delivery state: with the EDM plugin inactive the
 * gate is CLOSED, which is correct and must stay correct. But the offline
 * behaviour suite has to drive the send path against a mock transport, and a
 * closed gate refuses before the transport is ever reached -- so 21 mock-based
 * measurements were failing for a reason that had nothing to do with what they
 * measure.
 *
 * The seam does NOT loosen the production gate. Invoice_Manager defaults to the
 * real gate, every production construction site uses that default, and the
 * gate's own measurement constructs the manager with the default so it still
 * proves the closed and open behaviour of the real thing. A test may hand in an
 * explicitly open gate, and then it is the TEST that is stating the
 * precondition, in one visible place, instead of a suite quietly writing to the
 * site's option.
 */
interface Kuka_Island_Core_Invoice_Transmission_Gate {

	/** Is transmission forbidden right now? */
	public function is_closed(): bool;

	/**
	 * Operator-facing reason, safe for an order note.
	 *
	 * Deliberately NOT named message(): the real gate's operator sentence is a
	 * static method with that name and PHP cannot satisfy a non-static
	 * interface method with a static one. Two names beats a second class that
	 * would have to keep the same sentence in step.
	 */
	public function closed_message(): string;
}

final class Kuka_Island_Core_Invoice_Runtime_Gate implements Kuka_Island_Core_Invoice_Transmission_Gate {

	/** Autoload is off: this must never be served from a request-start snapshot. */
	public const OPTION = 'kuka_island_edm_runtime_disabled';

	/** Safe error code recorded on an order when the gate stops a transmission. */
	public const CODE = 'edm_runtime_disabled';

	/** Close the gate. Called on deactivation. */
	public static function disable(): void {
		update_option( self::OPTION, '1', false );
	}

	/** Open the gate. Called on activation. */
	public static function enable(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Whether transmission is currently forbidden.
	 *
	 * Read straight from the options table rather than through get_option().
	 * get_option() answers from the object cache, and under a persistent cache
	 * it would happily return a value read before deactivation happened --
	 * which is the one case this gate exists for. A direct read is the only
	 * version of this check that works mid-request.
	 *
	 * Fail-open on a missing $wpdb is deliberate: without a database there is
	 * no option to have been set, and no order to write either.
	 */
	public static function is_disabled(): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

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
		return __( 'EDM eklentisi devre dışı bırakıldığı için gönderim yapılmadı. Belge oluşturulmadı.', 'kuka-island-edm' );
	}

	/*
	 * The interface, answered by the static methods above. Instance methods
	 * rather than a second implementation: there is exactly one real gate and
	 * exactly one place that reads the option, so the injectable form and the
	 * static form can never disagree.
	 */

	public function is_closed(): bool {
		return self::is_disabled();
	}

	public function closed_message(): string {
		return self::message();
	}
}
