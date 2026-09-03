<?php
/**
 * Activation and deactivation for the shipping automation plugin.
 *
 * Activation is deliberately inert. It opens the run gate and records a version
 * and NOTHING else: it contacts no carrier, books no shipment, enqueues no
 * order and schedules no job. An operator who activates the plugin to look at
 * the admin panel must not discover afterwards that forty orders were handed to
 * a courier.
 *
 * Deactivation is deliberately narrow. It closes the run gate and cancels the
 * pending jobs THIS plugin booked, and it touches nothing else: no order meta,
 * no shipment id, no barcode, no audit history, no WooCommerce fulfilment and
 * no other plugin's scheduled actions. A deactivated integration that erased
 * its own audit trail would leave the shop unable to answer what had already
 * been handed to the carrier -- which is precisely the question that matters
 * after an operator turns automation off.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Activator {

	/** Only this identifier is ever unscheduled. */
	public const OWNED_HOOKS = array(
		'kuka_island_shipping_query_status',
	);

	public const OWNED_GROUP = 'kuka-island-shipping';

	public static function activate(): void {
		update_option( 'kuka_island_shipping_version', '0.1.0', false );

		require_once KUKA_ISLAND_SHIPPING_PATH . 'includes/shipping/class-shipment-runtime-gate.php';

		/*
		 * Activation opens the run gate, and nothing more. It does NOT enqueue
		 * anything for orders that already exist and it does not resume a poll
		 * chain that was cancelled on deactivation: an order whose shipment was
		 * booked -- or refused -- before this plugin was switched off keeps its
		 * recorded outcome. Reactivation is not a reason to re-book.
		 */
		Kuka_Island_Shipping_Runtime_Gate::enable();
	}

	public static function deactivate(): void {
		require_once KUKA_ISLAND_SHIPPING_PATH . 'includes/shipping/class-shipment-runtime-gate.php';

		/*
		 * The gate closes FIRST. Unscheduling can take a moment, and a worker
		 * that is already inside a carrier call is stopped by the gate rather
		 * than by the unschedule -- so the gate must be shut before anything
		 * else is attempted.
		 */
		Kuka_Island_Shipping_Runtime_Gate::disable();

		self::cancel_owned_actions();
	}

	/**
	 * Cancel this plugin's own pending jobs, by hook AND by group.
	 *
	 * Both filters are applied together: the hook name is ours, and the group
	 * narrows it further so a same-named action booked by something else in
	 * another group is left alone. Completed and failed actions are not touched,
	 * because they are the record of what happened.
	 *
	 * Only PENDING actions are enumerated, and each is cancelled by its own id.
	 *
	 * @return array<string, int> Cancelled marker per hook: 1 when nothing is
	 *                            pending afterwards, 0 when something remains.
	 */
	public static function cancel_owned_actions(): array {
		$cancelled = array();

		foreach ( self::OWNED_HOOKS as $hook ) {
			$cancelled[ $hook ] = 0;

			if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) || ! class_exists( 'ActionScheduler' ) ) {
				continue;
			}

			/*
			 * Cancelled one action id at a time, enumerated by hook AND group.
			 *
			 * as_unschedule_all_actions( $hook, array(), $group ) does NOT do
			 * this. An empty args array is not "any args" to the data store; it
			 * is the args hash of an action called with no arguments at all. The
			 * poller books its queries with array( 'order_id' => N ), so every
			 * one of them survived a deactivation -- the unschedule matched
			 * nothing and reported success, because nothing with empty args was
			 * pending. The whole point of closing the module down is that its
			 * booked work stops.
			 */
			$pending = (array) as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'group'    => self::OWNED_GROUP,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 500,
					'orderby'  => 'none',
				),
				'ids'
			);

			$store = ActionScheduler::store();

			foreach ( $pending as $action_id ) {
				try {
					$store->cancel_action( (int) $action_id );
				} catch ( Throwable $e ) {
					// Already gone, or gone while we looked. Either is fine:
					// the count below is what decides the outcome.
				}
			}

			$left = (array) as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'group'    => self::OWNED_GROUP,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
					'orderby'  => 'none',
				),
				'ids'
			);

			$cancelled[ $hook ] = array() === $left ? 1 : 0;
		}

		return $cancelled;
	}
}
