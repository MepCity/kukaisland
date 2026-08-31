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

		// Prevent duplicate scheduled actions.
		if ( $this->is_action_scheduled( $order_id ) ) {
			return;
		}

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
			return;
		}

		try {
			$this->manager->process_order( $order );
		} catch ( Kuka_Island_Core_Invoice_Transient_Exception $transient_e ) {
			$attempts = (int) $order->get_meta( '_kuka_invoice_attempts', true );
			if ( $attempts < self::MAX_RETRY_ATTEMPTS ) {
				// Limited exponential backoff: 2m (120s), 8m (480s), 32m (1920s).
				$delay = (int) ( 120 * pow( 4, max( 0, $attempts - 1 ) ) );
				$this->schedule_action( $order_id, time() + $delay );
			} else {
				// Exhausted max retry attempts -> transition to manual review.
				Kuka_Island_Core_Invoice_Order_Store::set_status(
					$order,
					Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
					__( 'Otomatik deneme limiti aşıldı. Fatura manuel inceleme gerektiriyor.', 'kuka-island-core' )
				);
			}
		} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $perm_e ) {
			// Permanent error: no auto-retry. A status the manager already
			// persisted as a deliberate fail-closed block (e.g. unconfirmed EDM
			// numbering) is preserved instead of being flattened into
			// needs_manual_review.
			if ( Kuka_Island_Core_Invoice_Status::STATUS_BLOCKED !== Kuka_Island_Core_Invoice_Order_Store::get_status( $order ) ) {
				Kuka_Island_Core_Invoice_Order_Store::set_status(
					$order,
					Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
					$perm_e->get_user_message()
				);
			}
		} catch ( Exception $e ) {
			Kuka_Island_Core_Invoice_Order_Store::set_status(
				$order,
				Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
				__( 'Beklenmeyen hata sebebiyle işlem durduruldu.', 'kuka-island-core' )
			);
		}
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
