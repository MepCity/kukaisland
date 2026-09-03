<?php
/**
 * Activation and deactivation for the EDM plugin.
 *
 * Deactivation is deliberately narrow. It cancels the pending jobs THIS plugin
 * booked and closes the run gate, and it touches nothing else: no order meta,
 * no invoice history, no UUID or document-number record, no superseded
 * document, and no other plugin's scheduled actions. A deactivated integration
 * that erased its own audit trail would leave the shop unable to answer what
 * had already been issued -- which is precisely the question that matters after
 * an operator turns invoicing off.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_EDM_Activator {

	/** Only these three identifiers are ever unscheduled. */
	public const OWNED_HOOKS = array(
		'kuka_island_process_order_invoice',
		'kuka_island_query_invoice_status',
	);

	public const OWNED_GROUP = 'kuka-island-invoice';

	public static function activate(): void {
		update_option( 'kuka_island_edm_version', '0.1.0', false );

		require_once KUKA_ISLAND_EDM_PATH . 'includes/invoice/class-invoice-runtime-gate.php';

		/*
		 * Activation opens the run gate, and nothing more. It does NOT enqueue
		 * anything for orders that already exist: an order invoiced -- or
		 * refused -- before this plugin was switched off keeps its recorded
		 * outcome, and the transmission-evidence guard is what decides whether
		 * it may ever be sent again. Reactivation is not a reason to re-send.
		 */
		Kuka_Island_Core_Invoice_Runtime_Gate::enable();
	}

	public static function deactivate(): void {
		require_once KUKA_ISLAND_EDM_PATH . 'includes/invoice/class-invoice-runtime-gate.php';

		/*
		 * The gate closes FIRST. Unscheduling can take a moment, and a worker
		 * that is already inside a transmission is stopped by the gate rather
		 * than by the unschedule -- so the gate must be shut before anything
		 * else is attempted.
		 */
		Kuka_Island_Core_Invoice_Runtime_Gate::disable();

		self::cancel_owned_actions();
	}

	/**
	 * Cancel this plugin's own pending jobs, by hook AND by group.
	 *
	 * Both filters are applied together: the hook names are ours, and the group
	 * narrows it further so a same-named action booked by something else in
	 * another group is left alone. Completed and failed actions are not
	 * touched, because they are the record of what happened.
	 *
	 * @return array<string, int> Cancelled count per hook.
	 */
	public static function cancel_owned_actions(): array {
		$cancelled = array();

		foreach ( self::OWNED_HOOKS as $hook ) {
			$cancelled[ $hook ] = 0;

			if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
				continue;
			}

			/*
			 * as_unschedule_all_actions() cancels pending actions for the hook
			 * within the group. The args argument stays empty so every pending
			 * job for the hook is covered regardless of which order it carries.
			 */
			as_unschedule_all_actions( $hook, array(), self::OWNED_GROUP );

			if ( function_exists( 'as_has_scheduled_action' ) ) {
				$cancelled[ $hook ] = as_has_scheduled_action( $hook, null, self::OWNED_GROUP ) ? 0 : 1;
			}
		}

		return $cancelled;
	}
}
