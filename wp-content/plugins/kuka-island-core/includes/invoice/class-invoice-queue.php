<?php
/**
 * Invoice Background Queue & Event Listener.
 *
 * Uses Action Scheduler (or wp-cron fallback) to asynchronously process
 * invoice generation and delivery only for settled orders with limited exponential backoff.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Queue {
	public const ACTION_PROCESS_INVOICE = 'kuka_island_process_order_invoice';
	public const MAX_RETRY_ATTEMPTS     = 3;

	/**
	 * Failed worker runs in the CURRENT queue chain.
	 *
	 * Deliberately its own key. This used to be read from
	 * _kuka_invoice_attempts, which is the fiscal record of how many times
	 * SendInvoice was actually called -- and reconciling a transmitted document
	 * is a GetInvoiceStatus call, so that counter never moves on the
	 * reconciliation path. The cap therefore never arrived and the worker could
	 * reschedule itself without end.
	 *
	 * The value belongs to ONE live chain of ACTION_PROCESS_INVOICE actions and
	 * to nothing else. Every path that ends this worker's ownership clears it,
	 * and maybe_enqueue_order() clears it again when it starts a new chain, so a
	 * count left by an earlier chain can never be inherited as a smaller retry
	 * budget by a later one.
	 */
	public const META_QUEUE_RETRIES = '_kuka_invoice_queue_retries';

	private Kuka_Island_Core_Invoice_Manager $manager;

	public function __construct( ?Kuka_Island_Core_Invoice_Manager $manager = null ) {
		$this->manager = $manager ?? new Kuka_Island_Core_Invoice_Manager();
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_enqueue_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_enqueue_order' ), 20, 2 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_order_refund' ), 20, 2 );
		add_action( self::ACTION_PROCESS_INVOICE, array( $this, 'process_queued_order' ), 10, 1 );
	}

	/**
	 * Order status changed to processing/completed -> enqueue if eligible.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order Order instance.
	 */
	public function maybe_enqueue_order( int $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$config = $this->manager->get_config();

		// Auto send must be explicitly enabled and configured.
		if ( ! $config->is_auto_send_enabled() ) {
			return;
		}

		// Never enqueue test/sandbox fixture orders. The decision lives in the
		// shared production guard so the queue does not have to reach into a
		// protected manager method (which previously produced a fatal error on
		// this exact automatic send path).
		if ( Kuka_Island_Core_Invoice_Fixture_Guard::is_test_fixture_order( $order ) ) {
			return;
		}

		// Check if payment is really settled.
		if ( ! $this->manager->is_order_settled( $order ) ) {
			return;
		}

		$current_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $order );
		if ( Kuka_Island_Core_Invoice_Status::is_terminal( $current_status ) || Kuka_Island_Core_Invoice_Status::is_in_progress( $current_status ) ) {
			return;
		}

		// Any persistent evidence of a previous SendInvoice makes the manager
		// reconcile-only, so queueing the order would only spend a worker on a
		// status query the poller already owns.
		if ( array() !== Kuka_Island_Core_Invoice_Manager::transmission_evidence( $order ) ) {
			return;
		}

		// Prevent duplicate scheduled actions.
		if ( $this->is_action_scheduled( $order_id ) ) {
			return;
		}

		// A new chain starts from zero. Any count still on the order belongs to
		// a chain that is already over, and inheriting it would silently give
		// this one a shorter retry budget.
		$this->clear_queue_retries( $order );

		// Mark status as queued.
		Kuka_Island_Core_Invoice_Order_Store::set_status( $order, Kuka_Island_Core_Invoice_Status::STATUS_QUEUED, __( 'Fatura kuyruğa eklendi.', 'kuka-island-core' ) );

		$this->schedule_action( $order_id );
	}

	/**
	 * Background worker entrypoint.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 */
	public function process_queued_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$config = $this->manager->get_config();
		if ( ! $config->is_auto_send_enabled() ) {
			// Auto-send was switched off mid-chain. This worker owns nothing
			// further, so it leaves no retry budget behind either.
			$this->clear_queue_retries( $order );

			return;
		}

		try {
			$this->manager->process_order( $order );

			// The send path completed. Whatever this chain had been counting is
			// spent, so a later genuine pre-send hiccup starts from zero.
			$this->clear_queue_retries( $order );
		} catch ( Kuka_Island_Core_Invoice_Transient_Exception $transient_e ) {
			$this->handle_transient_failure( $order_id );
		} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $perm_e ) {
			// Permanent error: no auto-retry, so the chain is over and its count
			// goes with it. Cleared before escalating, because escalation may
			// legitimately decline to touch a protected status and must not
			// decide whether the counter survives.
			$this->clear_queue_retries( $order );
			$this->escalate_to_manual_review( $order, $perm_e->get_user_message() );
		} catch ( Exception $e ) {
			$this->clear_queue_retries( $order );
			$this->escalate_to_manual_review(
				$order,
				__( 'Beklenmeyen hata sebebiyle işlem durduruldu.', 'kuka-island-core' )
			);
		}
	}

	/**
	 * Decide whether a transient failure deserves another send worker run.
	 *
	 * The order is re-read first, because process_order() has just written to it
	 * and the decision depends entirely on what it wrote.
	 *
	 * Two states end the chain instead of extending it:
	 *
	 * - Any persistent evidence of a previous transmission. process_order() can
	 *   only reconcile such an order, and reconciling is GetInvoiceStatus, so
	 *   rescheduling the SEND worker to do it was both pointless and unbounded:
	 *   the old cap was read from the fiscal send-attempt counter, which a
	 *   status query never advances. Ownership of an in-flight document belongs
	 *   to the status poller's own action and to the manual EDM query in the
	 *   order screen -- not to the send queue.
	 * - An escalation-protected status, for the same reason from the other side.
	 *
	 * Everything else is a genuine pre-transmission transient error and keeps
	 * its bounded, backed-off retry.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	private function handle_transient_failure( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$status = Kuka_Island_Core_Invoice_Order_Store::get_status( $order );

		if ( array() !== Kuka_Island_Core_Invoice_Manager::transmission_evidence( $order )
			|| Kuka_Island_Core_Invoice_Status::is_escalation_protected( $status ) ) {
			// Ownership has moved to the poller or to a manual EDM query. The
			// send chain is finished, so its count must not sit on the order
			// waiting to shorten some future chain's budget.
			$this->clear_queue_retries( $order );

			return;
		}

		$retries = (int) $order->get_meta( self::META_QUEUE_RETRIES, true ) + 1;
		$order->update_meta_data( self::META_QUEUE_RETRIES, (string) $retries );
		$order->save_meta_data();

		if ( $retries >= self::MAX_RETRY_ATTEMPTS ) {
			// Exhausted this chain. No new action is created, so the chain ends
			// here rather than continuing on a counter that never moves.
			$this->escalate_to_manual_review(
				$order,
				__( 'Otomatik deneme limiti aşıldı. Fatura manuel inceleme gerektiriyor.', 'kuka-island-core' )
			);
			// A fresh budget for the next chain: the cap is per chain, and a new
			// chain only ever starts from a new order-status event.
			$this->clear_queue_retries( $order );

			return;
		}

		// Limited exponential backoff: 2m (120s), 8m (480s).
		$delay = (int) ( 120 * pow( 4, max( 0, $retries - 1 ) ) );
		$this->schedule_action( $order_id, time() + $delay );
	}

	/**
	 * Forget the queue's retry count for this order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function clear_queue_retries( WC_Order $order ): void {
		if ( '' === (string) $order->get_meta( self::META_QUEUE_RETRIES, true ) ) {
			return;
		}

		$order->delete_meta_data( self::META_QUEUE_RETRIES );
		$order->save_meta_data();
	}

	/**
	 * Move an order to manual review, unless its current status must not be
	 * overwritten.
	 *
	 * needs_manual_review is in Kuka_Island_Core_Invoice_Status::can_retry(), so
	 * writing it over a status that records a transmitted document -- or a
	 * deliberate fail-closed block -- hands that document back to the send path.
	 * That is how a reconciliation failure used to turn into a second fiscal
	 * document, and it is why the decision now goes through
	 * is_escalation_protected() rather than a single blocked-status check.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $message Operator-facing message.
	 */
	private function escalate_to_manual_review( WC_Order $order, string $message ): void {
		if ( Kuka_Island_Core_Invoice_Status::is_escalation_protected( Kuka_Island_Core_Invoice_Order_Store::get_status( $order ) ) ) {
			return;
		}

		Kuka_Island_Core_Invoice_Order_Store::set_status(
			$order,
			Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
			$message
		);
	}

	/**
	 * Handle order refund event.
	 */
	public function handle_order_refund( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$status = Kuka_Island_Core_Invoice_Order_Store::get_status( $order );
		if ( Kuka_Island_Core_Invoice_Status::is_terminal( $status ) || $status === Kuka_Island_Core_Invoice_Status::STATUS_SENT ) {
			$inv_num = (string) $order->get_meta( '_kuka_invoice_number', true );
			$order->add_order_note(
				sprintf(
					/* translators: %s: Invoice number */
					__( 'Sipariş için iade işlemi yapıldı (Fatura No: %s). Faturanın iptali veya e-Arşiv/GİB iade faturası işlemleri için muhasebe kontrolü gereklidir.', 'kuka-island-core' ),
					$inv_num ?: 'Belirtilmemiş'
				),
				0,
				false
			);
		}
	}

	private function is_action_scheduled( int $order_id ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return as_has_scheduled_action( self::ACTION_PROCESS_INVOICE, array( 'order_id' => $order_id ), 'kuka-island-invoice' );
		}

		return false !== wp_next_scheduled( self::ACTION_PROCESS_INVOICE, array( $order_id ) );
	}

	private function schedule_action( int $order_id, ?int $timestamp = null ): void {
		$time = $timestamp ?? time();

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $time, self::ACTION_PROCESS_INVOICE, array( 'order_id' => $order_id ), 'kuka-island-invoice' );
		} else {
			wp_schedule_single_event( $time, self::ACTION_PROCESS_INVOICE, array( $order_id ) );
		}
	}
}
