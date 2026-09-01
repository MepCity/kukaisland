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
 * Polling begins from Kuka_Island_Core_Invoice_Manager::process_order(), the one
 * place a SendInvoice result is persisted, so the automatic queue and the manual
 * send button in the order screen behave identically.
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
	/**
	 * Outcome of the last booking attempt, as one of the SCHEDULE_* codes.
	 *
	 * Recorded on every attempt, successful or not, so "is a query booked for
	 * this document?" is answerable from the order rather than inferred.
	 */
	public const META_LAST_SCHEDULE_OUTCOME = '_kuka_invoice_poll_schedule_outcome';

	/*
	 * Booking outcomes. A boolean cannot tell "a query is already waiting"
	 * apart from "Action Scheduler refused to create one", and those two need
	 * opposite responses: the first is a normal success, the second means the
	 * document has no query booked and nobody knows.
	 */
	/** This call created the query. */
	public const SCHEDULE_CREATED = 'created';
	/** A query was already waiting. Nothing to do, and nothing wrong. */
	public const SCHEDULE_ALREADY_PENDING = 'already_pending';
	/** Another worker holds the booking decision for this order. */
	public const SCHEDULE_LOCK_CONTENDED = 'lock_contended';
	/** Action Scheduler is not loaded, so nothing can be booked at all. */
	public const SCHEDULE_SCHEDULER_UNAVAILABLE = 'scheduler_unavailable';
	/** Action Scheduler was asked and returned action ID 0. */
	public const SCHEDULE_FAILED = 'schedule_failed';
	/** The document is answered; no query is wanted. Not a failure. */
	public const SCHEDULE_NOT_APPLICABLE = 'not_applicable';

	/*
	 * Safe error codes for a query that was wanted and did not get booked.
	 * Codes only: no exception text, no credential, no SOAP payload, no
	 * customer data is ever written to the order from this class.
	 */
	/** Action Scheduler was absent. */
	public const ERROR_SCHEDULER_UNAVAILABLE = 'status_poll_scheduler_unavailable';
	/** Action Scheduler refused to create the action. */
	public const ERROR_SCHEDULE_FAILED = 'status_poll_schedule_failed';
	/** The booking lock was held and no pending query could be found after it. */
	public const ERROR_LOCK_WITHOUT_PENDING = 'status_poll_lock_contended_without_pending';

	/**
	 * Advisory lock name prefix for the booking decision.
	 *
	 * One lock per order, and deliberately NOT the send lock
	 * ('kuka_inv_' . $order_id) the manager holds: booking a query must never
	 * be able to block, or be blocked by, a transmission.
	 */
	private const SCHEDULE_LOCK_PREFIX = 'kuka_inv_poll_';

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
	 * Is a FUTURE query already booked for this order?
	 *
	 * Deliberately not as_has_scheduled_action(): that helper queries
	 * STATUS_RUNNING together with STATUS_PENDING, so the action currently
	 * executing counts as "already booked". The follow-up query is scheduled
	 * from inside that very callback, where the action is running, so a
	 * pending-or-running check refuses every follow-up and the poll chain stops
	 * dead after one attempt.
	 *
	 * Only STATUS_PENDING answers the question that matters here: is there a
	 * query still waiting to run?
	 *
	 * @param int $order_id Order ID.
	 */
	public static function has_pending_query( int $order_id ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION_QUERY_STATUS,
				'args'     => array( 'order_id' => $order_id ),
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);

		return array() !== (array) $pending;
	}

	/**
	 * Attempt the booking and say exactly what happened.
	 *
	 * Two guards, because the pending-only check on its own is a
	 * check-then-act race: two workers that both notice the same pending
	 * document can both see zero pending queries and both create one.
	 *
	 * 1. A per-order MySQL advisory lock serialises the decision across
	 *    processes. A worker that cannot take the lock does not queue behind it.
	 * 2. Inside the lock, the pending-only query decides.
	 *
	 * Returns a code rather than a boolean on purpose. 'already_pending' and
	 * 'schedule_failed' are both "this call created nothing", and treating them
	 * the same is how a document ends up with no query booked and nobody aware
	 * of it.
	 *
	 * @param int $order_id Order ID.
	 * @param int $delay    Seconds from now.
	 * @return string One of the SCHEDULE_* codes.
	 */
	public static function schedule_query( int $order_id, int $delay = self::FIRST_DELAY ): string {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return self::SCHEDULE_SCHEDULER_UNAVAILABLE;
		}

		if ( ! self::acquire_schedule_lock( $order_id ) ) {
			// Somebody else is deciding. Whether they actually booked anything
			// is a separate question, answered by book_query().
			return self::SCHEDULE_LOCK_CONTENDED;
		}

		try {
			if ( self::has_pending_query( $order_id ) ) {
				return self::SCHEDULE_ALREADY_PENDING;
			}

			$action_id = (int) as_schedule_single_action(
				time() + max( 1, $delay ),
				self::ACTION_QUERY_STATUS,
				array( 'order_id' => $order_id ),
				self::GROUP
			);

			// Action Scheduler returns 0 when it could not schedule.
			return $action_id > 0 ? self::SCHEDULE_CREATED : self::SCHEDULE_FAILED;
		} finally {
			self::release_schedule_lock( $order_id );
		}
	}

	/**
	 * The safe error code a booking outcome deserves, or '' when it is fine.
	 *
	 * 'lock_contended' maps to the without-pending code because book_query()
	 * only leaves that outcome standing once it has looked for a pending query
	 * and not found one.
	 *
	 * @param string $outcome One of the SCHEDULE_* codes.
	 */
	public static function error_code_for( string $outcome ): string {
		return match ( $outcome ) {
			self::SCHEDULE_SCHEDULER_UNAVAILABLE => self::ERROR_SCHEDULER_UNAVAILABLE,
			self::SCHEDULE_FAILED                => self::ERROR_SCHEDULE_FAILED,
			self::SCHEDULE_LOCK_CONTENDED        => self::ERROR_LOCK_WITHOUT_PENDING,
			default                              => '',
		};
	}

	/**
	 * What the shop is told when a query could not be booked.
	 *
	 * A fixed sentence. The safe code is appended by the caller; nothing else
	 * about the failure is written anywhere.
	 */
	public static function unbooked_message(): string {
		return __( 'Fatura gönderilmiş olabilir; otomatik durum sorgusu planlanamadı, lütfen fatura durumunu manuel olarak sorgulayın.', 'kuka-island-core' );
	}

	/**
	 * Book a query for a document and report the outcome, visibly.
	 *
	 * The single entry point for both the send path and the poll chain, so the
	 * two cannot classify the same failure differently.
	 *
	 * A lost lock is not success on its own. The winner may have booked a query
	 * or may have failed the same way this call could have, so the pending
	 * query is looked for rather than assumed. If it is there, this is an
	 * ordinary 'already_pending'; if it is not, the failure is recorded.
	 *
	 * The invoice status is never touched here. sent, pending_approval and
	 * send_uncertain all refuse a re-send, and rewriting one of them into
	 * needs_manual_review -- which can_retry() allows -- would turn a
	 * scheduling problem into a duplicate fiscal document.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $delay Seconds from now.
	 * @return array{ok: bool, outcome: string, pending_verified: bool|null, error_code: string}
	 */
	public static function book_query( WC_Order $order, int $delay = self::FIRST_DELAY ): array {
		$order_id = (int) $order->get_id();
		$outcome  = self::schedule_query( $order_id, $delay );

		$pending_verified = null;
		if ( self::SCHEDULE_LOCK_CONTENDED === $outcome ) {
			$pending_verified = self::has_pending_query( $order_id );
			if ( $pending_verified ) {
				$outcome = self::SCHEDULE_ALREADY_PENDING;
			}
		}

		$error_code = self::error_code_for( $outcome );

		self::record_schedule_outcome( $order, $outcome, $error_code );

		return array(
			'ok'               => '' === $error_code,
			'outcome'          => $outcome,
			'pending_verified' => $pending_verified,
			'error_code'       => $error_code,
		);
	}

	/**
	 * Record a booking failure raised as an exception rather than an outcome.
	 *
	 * Letting it escape would hand the order to the queue worker's generic
	 * handler, which writes needs_manual_review -- and that status permits a
	 * re-send of a document that has already been transmitted.
	 *
	 * @param WC_Order $order Order.
	 * @return array{ok: bool, outcome: string, pending_verified: bool|null, error_code: string}
	 */
	public static function record_scheduling_exception( WC_Order $order ): array {
		self::record_schedule_outcome( $order, self::SCHEDULE_FAILED, self::ERROR_SCHEDULE_FAILED );

		return array(
			'ok'               => false,
			'outcome'          => self::SCHEDULE_FAILED,
			'pending_verified' => null,
			'error_code'       => self::ERROR_SCHEDULE_FAILED,
		);
	}

	/**
	 * Persist what the booking attempt did, and say so on the order when it
	 * did not do what was wanted.
	 *
	 * @param string $outcome    One of the SCHEDULE_* codes.
	 * @param string $error_code Safe error code, or '' when the outcome is fine.
	 */
	private static function record_schedule_outcome( WC_Order $order, string $outcome, string $error_code ): void {
		try {
			$order->update_meta_data( self::META_LAST_SCHEDULE_OUTCOME, $outcome );

			if ( '' === $error_code ) {
				$order->save_meta_data();

				return;
			}

			Kuka_Island_Core_Invoice_Order_Store::save_polling_not_scheduled(
				$order,
				$error_code,
				self::unbooked_message()
			);
		} catch ( Throwable $meta_error ) {
			// The order meta could not be written. There is no quieter place to
			// put this and nothing further to try; the order note below is the
			// remaining chance of it being seen.
			unset( $meta_error );
		}

		if ( '' === $error_code ) {
			return;
		}

		try {
			// Visible in the order screen's own note list, where somebody
			// working the order will actually read it.
			$order->add_order_note(
				sprintf(
					/* translators: 1: warning sentence, 2: safe error code */
					__( '%1$s (%2$s)', 'kuka-island-core' ),
					self::unbooked_message(),
					$error_code
				),
				0,
				false
			);
		} catch ( Throwable $note_error ) {
			unset( $note_error );
		}
	}

	/**
	 * Take the per-order booking lock, or report that somebody else has it.
	 *
	 * Timeout 0 on purpose: the loser of the race has no work to do, so waiting
	 * would only hold a worker open.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function acquire_schedule_lock( int $order_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::SCHEDULE_LOCK_PREFIX . $order_id )
		);

		return '1' === (string) $acquired;
	}

	/**
	 * Release the per-order booking lock.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function release_schedule_lock( int $order_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::SCHEDULE_LOCK_PREFIX . $order_id )
		);
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
	 * Begin polling a document that has just been transmitted.
	 *
	 * Called from Kuka_Island_Core_Invoice_Manager::process_order() once the
	 * SendInvoice outcome is persisted -- including the ambiguous-network case,
	 * where asking is the only safe move. Only sent, pending_approval and
	 * send_uncertain start a poll; completed, rejected, cancelled and the error
	 * states are answers, and an answered document is not asked about again.
	 *
	 * Meta is written with save_meta_data() rather than save(): the counters are
	 * order meta, and this runs inside the manager's send lock, where a full
	 * order save would fire the order-save hooks for no reason.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $lifecycle Lifecycle status the send produced.
	 * @return array{ok: bool, outcome: string, pending_verified: bool|null, error_code: string}
	 */
	public static function start( WC_Order $order, string $lifecycle ): array {
		if ( ! Kuka_Island_Core_EDM_Document_Status::should_keep_polling( $lifecycle ) ) {
			// An answered document is not a failed booking. Nothing is recorded
			// on the order, because nothing went wrong.
			return array(
				'ok'               => true,
				'outcome'          => self::SCHEDULE_NOT_APPLICABLE,
				'pending_verified' => null,
				'error_code'       => '',
			);
		}

		if ( '' === (string) $order->get_meta( self::META_POLL_STARTED_AT, true ) ) {
			$order->update_meta_data( self::META_POLL_STARTED_AT, (string) time() );
			$order->update_meta_data( self::META_POLL_ATTEMPTS, '0' );
			$order->save_meta_data();
		}

		return self::book_query( $order, self::FIRST_DELAY );
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
		$order->save_meta_data();

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
			$order->save_meta_data();
		}

		$decision = self::decide( $lifecycle, $attempts, $elapsed );

		if ( 'reschedule' === $decision['action'] ) {
			$booking = self::book_query( $order, $decision['delay'] );

			if ( $booking['ok'] ) {
				return;
			}

			/*
			 * The chain has no next link. book_query() has already put the
			 * reason on the order by safe code, added the note, and left the
			 * in-flight status alone -- so a person requeries from the order
			 * screen and nothing here retransmits a document that may already
			 * have arrived. Falling through to unschedule() would only cancel
			 * a query that was never created.
			 */
			return;
		}

		if ( 'give_up' === $decision['action'] ) {
			// The document is still unresolved and we have asked enough. A
			// person decides from here; nothing is resent.
			$order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW );
			$order->update_meta_data( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, 'status_polling_' . $decision['reason'] );
			$order->save_meta_data();
		}

		self::unschedule( (int) $order->get_id() );
	}
}
