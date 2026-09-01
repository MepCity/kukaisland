<?php
/**
 * Automatic GetInvoiceStatus polling.
 *
 * SendInvoice being accepted tells us the call was taken, not that the document
 * arrived. Statuses such as PACKAGE - PROCESSING resolve minutes or hours
 * later, so an invoice left at 'sent' would sit there until somebody opened the
 * order by hand.
 *
 * This scheduler is deliberately narrow. It is a SEPARATE Action Scheduler
 * action from the send queue, and the only EDM operation reachable from it is
 * GetInvoiceStatus: there is no code path from a poll to SendInvoice, so a
 * document can never be transmitted twice because a query was slow. That
 * matters most in the send_uncertain case, where the safe move is to ask, never
 * to resend blind.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Status_Poller {

	/** Distinct from the send queue's action, on purpose. */
	public const ACTION_QUERY_STATUS = 'kuka_island_query_invoice_status';
	/** Action Scheduler group. */
	public const GROUP = 'kuka-island-invoice';

	/** Delay before the first query, in seconds. */
	public const FIRST_DELAY = 300;
	/** Lower bound for a follow-up query. */
	public const MIN_DELAY = 300;
	/** Upper bound, so backoff cannot grow without limit. */
	public const MAX_DELAY = 3600;
	/** Hard cap on queries per document. */
	public const MAX_ATTEMPTS = 12;
	/** Hard cap on elapsed time, in seconds. Twenty-four hours. */
	public const MAX_ELAPSED = 86400;

	/** Attempt counter for the current document. */
	public const META_POLL_ATTEMPTS = '_kuka_invoice_poll_attempts';
	/** UTC timestamp of the first scheduled query. */
	public const META_POLL_STARTED_AT = '_kuka_invoice_poll_started_at';
	/** Last STATUS literal EDM returned. */
	public const META_LAST_EDM_STATUS = '_kuka_invoice_edm_status';
	/** RESPONSE_CODE, stored separately from STATUS. */
	public const META_RESPONSE_CODE = '_kuka_invoice_response_code';
	/** EARCHIVE_REPORT_STATUS, a reconciliation signal of its own. */
	public const META_EARCHIVE_REPORT_STATUS = '_kuka_invoice_earchive_report_status';
	/** GIB_STATUS_CODE, recorded but never used as the document status. */
	public const META_GIB_STATUS_CODE = '_kuka_invoice_gib_status_code';

	private Kuka_Island_Core_Invoice_Manager $manager;

	public function __construct( ?Kuka_Island_Core_Invoice_Manager $manager = null ) {
		$this->manager = $manager ?? new Kuka_Island_Core_Invoice_Manager();
	}

	public function register(): void {
		add_action( self::ACTION_QUERY_STATUS, array( $this, 'poll_order' ), 10, 1 );
	}

	/**
	 * Compute the delay for a given attempt.
	 *
	 * Linear-then-clamped rather than unbounded exponential: the point is to
	 * stop asking often, not to eventually stop asking at all.
	 *
	 * @param int $attempt Attempts already made.
	 */
	public static function delay_for_attempt( int $attempt ): int {
		if ( $attempt <= 0 ) {
			return self::FIRST_DELAY;
		}

		return (int) min( self::MAX_DELAY, max( self::MIN_DELAY, self::FIRST_DELAY * ( $attempt + 1 ) ) );
	}

	/**
	 * Decide what to do with a document, without touching WordPress.
	 *
	 * Pure, so the whole lifecycle can be proved from fixtures.
	 *
	 * @param string $lifecycle Current invoice lifecycle status.
	 * @param int    $attempts  Queries already made.
	 * @param int    $elapsed   Seconds since polling began.
	 * @return array{action: string, delay: int, reason: string}
	 */
	public static function decide( string $lifecycle, int $attempts, int $elapsed ): array {
		if ( Kuka_Island_Core_Invoice_Status::is_terminal( $lifecycle ) ) {
			// completed, rejected and cancelled are all final answers.
			return array(
				'action' => 'stop',
				'delay'  => 0,
				'reason' => 'terminal_status',
			);
		}

		if ( ! Kuka_Island_Core_EDM_Document_Status::should_keep_polling( $lifecycle ) ) {
			return array(
				'action' => 'stop',
				'delay'  => 0,
				'reason' => 'not_a_pollable_status',
			);
		}

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return array(
				'action' => 'give_up',
				'delay'  => 0,
				'reason' => 'max_attempts_reached',
			);
		}

		if ( $elapsed >= self::MAX_ELAPSED ) {
			return array(
				'action' => 'give_up',
				'delay'  => 0,
				'reason' => 'max_elapsed_reached',
			);
		}

		return array(
			'action' => 'reschedule',
			'delay'  => self::delay_for_attempt( $attempts ),
			'reason' => 'still_pending',
		);
	}

	/**
	 * Is a query already booked for this order?
	 *
	 * @param int $order_id Order ID.
	 */
	public static function is_scheduled( int $order_id ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		return (bool) as_has_scheduled_action( self::ACTION_QUERY_STATUS, array( 'order_id' => $order_id ), self::GROUP );
	}

	/**
	 * Book a query, unless one is already booked.
	 *
	 * The duplicate check is what keeps a single order from accumulating a
	 * queue of identical queries when several code paths notice the same
	 * pending document.
	 *
	 * @param int $order_id Order ID.
	 * @param int $delay    Seconds from now.
	 * @return bool Whether a new action was created.
	 */
	public static function schedule( int $order_id, int $delay = self::FIRST_DELAY ): bool {
		if ( self::is_scheduled( $order_id ) ) {
			return false;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		as_schedule_single_action( time() + max( 1, $delay ), self::ACTION_QUERY_STATUS, array( 'order_id' => $order_id ), self::GROUP );

		return true;
	}

	/**
	 * Cancel any booked query for this order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function unschedule( int $order_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_QUERY_STATUS, array( 'order_id' => $order_id ), self::GROUP );
		}
	}

	/**
	 * Begin polling a document that has just been accepted.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $lifecycle Lifecycle status the send produced.
	 */
	public static function start( WC_Order $order, string $lifecycle ): bool {
		if ( ! Kuka_Island_Core_EDM_Document_Status::should_keep_polling( $lifecycle ) ) {
			return false;
		}

		if ( '' === (string) $order->get_meta( self::META_POLL_STARTED_AT, true ) ) {
			$order->update_meta_data( self::META_POLL_STARTED_AT, (string) time() );
			$order->update_meta_data( self::META_POLL_ATTEMPTS, '0' );
			$order->save();
		}

		return self::schedule( (int) $order->get_id(), self::FIRST_DELAY );
	}

	/**
	 * Action Scheduler callback: query one order's document status.
	 *
	 * GetInvoiceStatus is the only operation this method can reach.
	 *
	 * @param int $order_id Order ID.
	 */
	public function poll_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$attempts = (int) $order->get_meta( self::META_POLL_ATTEMPTS, true );
		$started  = (int) $order->get_meta( self::META_POLL_STARTED_AT, true );
		$elapsed  = $started > 0 ? max( 0, time() - $started ) : 0;

		try {
			// The ONLY EDM operation reachable from this method.
			$result = $this->manager->query_order_status( $order );
		} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
			$result = null;
		}

		++$attempts;
		$order->update_meta_data( self::META_POLL_ATTEMPTS, (string) $attempts );
		$order->save();

		// A failed query is not a verdict about the document: keep polling
		// within the caps rather than declaring anything.
		$lifecycle = $result instanceof Kuka_Island_Core_Invoice_Result
			? $result->get_status()
			: Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL;

		if ( $result instanceof Kuka_Island_Core_Invoice_Result ) {
			$raw = $result->get_raw_data();
			$order->update_meta_data( self::META_LAST_EDM_STATUS, (string) ( $raw['status'] ?? '' ) );
			$order->update_meta_data( self::META_RESPONSE_CODE, (string) ( $raw['response_code'] ?? '' ) );
			$order->update_meta_data( self::META_EARCHIVE_REPORT_STATUS, (string) ( $raw['earchive_report_status'] ?? '' ) );
			$order->update_meta_data( self::META_GIB_STATUS_CODE, (string) ( $raw['gib_status_code'] ?? '' ) );
			$order->save();
		}

		$decision = self::decide( $lifecycle, $attempts, $elapsed );

		if ( 'reschedule' === $decision['action'] ) {
			self::schedule( (int) $order->get_id(), $decision['delay'] );

			return;
		}

		if ( 'give_up' === $decision['action'] ) {
			// The document is still unresolved and we have asked enough. A
			// person decides from here; nothing is resent.
			$order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW );
			$order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, 'status_polling_' . $decision['reason'] );
			$order->save();
		}

		self::unschedule( (int) $order->get_id() );
	}
}
