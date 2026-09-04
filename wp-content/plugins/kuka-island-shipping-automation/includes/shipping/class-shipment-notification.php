<?php
/**
 * The one customer e-mail this module sends, and the state that keeps it one.
 *
 * WooCommerce fires woocommerce_fulfillment_created_notification from exactly
 * one place: its REST controller, when a person ticks "notify customer" in the
 * fulfilment drawer. Nothing in the data store or in the Fulfillment entity
 * fires it. So a fulfilment this module flips to `fulfilled` from a carrier
 * status reading produced no e-mail at all -- measured as notification event 0
 * and mail attempt 0 while the record was fulfilled.
 *
 * This class fires that action itself, once, and never touches the manual
 * route: the operator's tick still goes through WooCommerce's controller
 * exactly as before, and a fulfilment somebody created by hand is never seen
 * here, because Fulfillment_Writer only ever hands over its OWN record.
 *
 * WHY THERE IS A STATE MACHINE FOR ONE E-MAIL. A carrier status is polled
 * repeatedly, and "send an e-mail when the parcel is in transfer" run twice is
 * two e-mails to a customer. The trigger is therefore not the status but the
 * TRANSITION, and the transition is recorded durably before the transport is
 * contacted -- so a process that dies with an SMTP conversation open leaves
 * evidence that a send was started, and the retry finds it:
 *
 *   (absent)                 nothing owed yet
 *     -> due                 OWED. Written BEFORE the fulfilment is saved.
 *   due
 *     -> pending             owed, nothing attempted (address or mailer missing)
 *     -> sending             INTENT WRITTEN, transport about to be contacted
 *   pending
 *     -> sending             the missing condition was fixed; retried
 *   sending
 *     -> sent                the transport accepted the message
 *     -> failed              the transport definitively refused it
 *     -> reconciliation_required   the outcome is unknown
 *   failed
 *     -> sending             a bounded retry
 *   sent, reconciliation_required
 *     -> terminal. Nothing in this module sends again.
 *
 * `reconciliation_required` is deliberately a dead end for automation. An
 * unknown outcome means the customer may already have the e-mail; sending a
 * second one is a decision with a customer consequence and it belongs to a
 * person. This is the same rule the carrier writes follow -- see
 * Order_Store::begin_mutation() -- applied to the mail transport.
 *
 * WHY THE DEBT IS WRITTEN BEFORE THE FULFILMENT IS SAVED. `sync_status()` used
 * to save the record first and only then reach this class, so a process that
 * died in between left a record that was fulfilled on disk with no notification
 * state at all. Every later poll then read `first_transition = false` -- the
 * record was already fulfilled -- and answered `not_due`: the customer was
 * never told, and nothing in the system said anything was owed. Measured
 * through the production path, killing the process inside
 * `woocommerce_fulfillment_after_update` (which fires after the row is written
 * and inside no transaction): record fulfilled yes, state absent, next poll
 * not_due, mails 0. The debt is therefore claimed FIRST, as `due`, and only
 * for this module's own record on its real first automatic transition. A
 * manual fulfilment and a record somebody else already fulfilled are never
 * adopted, because nothing ever wrote a debt for them.
 *
 * `pending` is the other half of the same defect. `recipient_missing` and
 * `mailer_unavailable` mean "not sent, try again later", but `pending` used to
 * fall through the `first_transition` gate too, so the retry never came. `due`
 * and `pending` are now both OWED states: they send when the condition is
 * fixed, whatever `first_transition` says, and neither of them spends an
 * attempt.
 *
 * WHY THERE IS A LOCK AS WELL AS A STATE. The state alone is a read followed
 * by a write, and two real processes can sit between them. A scheduled status
 * query and an operator's "durumu sorgula" press both load the order at the
 * start of their request; both then see a record that is not fulfilled yet and
 * an empty notification state, and both send. Measured with two processes and
 * two MySQL sessions before this lock existed: mail attempts 2, notification
 * events 2 -- and the second write left `sent|attempts:1` behind, so the state
 * machine's own evidence hid the duplicate. The decision is therefore taken
 * under an order-keyed advisory lock, and the records it decides from are
 * re-read from the database INSIDE the lock.
 *
 * The lock waits for nobody: GET_LOCK with a zero timeout either takes it or
 * refuses immediately. A process that does not get it does not send, and does
 * not wait either -- which is also why it cannot deadlock against the carrier
 * mutation lock (Order_Store / Shipment_Manager, same zero timeout): no process
 * in this module ever blocks holding a lock, so no cycle can form. The lock
 * covers the notification lifecycle only; the carrier writes keep their own.
 *
 * NOTHING SECRET IS RECORDED. The transport's own error text can carry the SMTP
 * user name, the server's banner or part of the message, so it is never stored:
 * only an allow-listed code reaches meta, notes and history.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Notification {

	/** Where the notification stands for this order. */
	public const META_STATE = '_kuka_shipping_notify_state';

	/** The allow-listed reason for the current state, '' when there is none. */
	public const META_CODE = '_kuka_shipping_notify_code';

	/** How many times the transport has been contacted for this order. */
	public const META_ATTEMPTS = '_kuka_shipping_notify_attempts';

	/** When the current state was written. */
	public const META_AT = '_kuka_shipping_notify_at';

	/**
	 * Which fulfilment record the current debt is about.
	 *
	 * Without it an owed state could be spent on a different record -- a
	 * manual fulfilment, or a second shipment -- simply because the order
	 * carried a debt. The reference is this module's ownership marker, so a
	 * debt can only ever be paid for the record that created it.
	 */
	public const META_DUE_REFERENCE = '_kuka_shipping_notify_reference';

	/**
	 * The handover instant both processes agree on, written with the debt.
	 *
	 * Two concurrent dispatches both find `_date_fulfilled` empty and both
	 * write their own `now`; the later save wins. The values are under a second
	 * apart, which is invisible everywhere except at day granularity -- and the
	 * handover date is exactly what EDM's Internet_Sales_Details reads. Taking
	 * the instant from this single agreed value makes the second write a write
	 * of the SAME string, so no local midnight can fall between them.
	 */
	public const META_HANDOVER_AT = '_kuka_shipping_notify_handover_at';

	/** Owed, recorded before the fulfilment reached the database. */
	public const STATE_DUE           = 'due';

	public const STATE_PENDING       = 'pending';
	public const STATE_SENDING       = 'sending';
	public const STATE_SENT          = 'sent';
	public const STATE_FAILED        = 'failed';
	public const STATE_MANUAL_REVIEW = 'reconciliation_required';

	/**
	 * How many times a DEFINITE refusal may be retried.
	 *
	 * Bounded because a refusal that repeats is a configuration fault, not a
	 * transient one, and an unbounded retry turns it into a queue nobody reads.
	 * An uncertain outcome is not retried at all, at any count.
	 */
	public const MAX_ATTEMPTS = 3;

	/** The WooCommerce action a person's "notify customer" tick fires. */
	private const CREATED_NOTIFICATION = 'woocommerce_fulfillment_created_notification';

	/**
	 * Advisory lock name prefix, order-keyed.
	 *
	 * Deliberately NOT the carrier mutation prefix: a notification must not be
	 * able to refuse a carrier write, and a carrier write must not be able to
	 * refuse a notification. Both are zero-wait, so neither can block the other.
	 */
	private const LOCK_PREFIX = 'kuka_ship_notify_';

	/**
	 * The claim's own lock, and the only one in this module that waits.
	 *
	 * Waiting is safe here because this is a LEAF lock: a process holding it
	 * reads one meta value and writes one meta value, and waits for nothing.
	 * A holder therefore always releases, so a waiter always makes progress and
	 * no cycle can form -- even when the caller already holds the carrier
	 * mutation lock.
	 */
	private const CLAIM_LOCK_PREFIX = 'kuka_ship_notify_claim_';

	/** Seconds the claim may wait for its leaf lock before giving up. */
	private const CLAIM_LOCK_WAIT = 2;

	/**
	 * Codes this class is allowed to store. Anything else becomes 'mail_failed'.
	 *
	 * An allow-list rather than a sanitiser: the transport's message is the one
	 * string in this path that can contain a credential, and the only safe way
	 * to handle it is never to keep it.
	 *
	 * @return array<int, string>
	 */
	public static function safe_codes(): array {
		return array(
			'wp_mail_failed',
			'mail_failed',
			'mail_not_attempted',
			'send_outcome_unknown',
			'recipient_missing',
			'mailer_unavailable',
		);
	}

	public static function state( WC_Order $order ): string {
		return (string) $order->get_meta( self::META_STATE, true );
	}

	public static function code( WC_Order $order ): string {
		return (string) $order->get_meta( self::META_CODE, true );
	}

	public static function attempts( WC_Order $order ): int {
		return (int) $order->get_meta( self::META_ATTEMPTS, true );
	}

	/**
	 * Everything an admin screen or a measurement needs, in one read.
	 *
	 * @return array{state: string, code: string, attempts: int, at: int}
	 */
	public static function status( WC_Order $order ): array {
		return array(
			'state'    => self::state( $order ),
			'code'     => self::code( $order ),
			'attempts' => self::attempts( $order ),
			'at'       => (int) $order->get_meta( self::META_AT, true ),
		);
	}

	/**
	 * Record that a customer notification is owed, BEFORE the record is saved.
	 *
	 * Called only when the caller has established that this is the real first
	 * automatic transition of this module's own record. Idempotent: an order
	 * that already carries any notification state is left alone, so a repeated
	 * poll, a retry and a second shipment cannot reopen a settled decision.
	 *
	 * EVERY BOUNDARY HERE IS FAIL-CLOSED. The lock, the fresh read and the
	 * readback each refuse rather than guess, and the caller is told which one
	 * refused. A `false` result means nothing was written and nothing may
	 * move: no fulfilment status, no handover date, no e-mail.
	 *
	 * On success the result carries the agreed handover instant, as a string
	 * with its own UTC offset. Both concurrent processes get the same value:
	 * the first one to hold the leaf lock writes it and the other reads it.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $reference This module's carrier reference for the record.
	 * @return array{ok: bool, outcome: string, handover: string}
	 */
	public static function claim( WC_Order $order, string $reference ): array {
		$order_id = (int) $order->get_id();

		/*
		 * FAIL-CLOSED, FIRST LINE. Without the lock this method cannot know
		 * whether another process is writing the same debt, so it writes
		 * nothing and says so. The caller must not fulfil the record either:
		 * a record that says `fulfilled` with no debt behind it is exactly the
		 * state that loses the customer's notification for good (K-51).
		 */
		if ( ! self::acquire_claim_lock( $order_id ) ) {
			return self::claim_result( false, 'claim_lock_contended', '' );
		}

		try {
			$fresh = self::reload_order( $order_id );

			if ( ! $fresh instanceof WC_Order ) {
				// The database could not answer. Nothing is written, nothing moves.
				return self::claim_result( false, 'claim_order_unreadable', '' );
			}

			if ( '' === $reference ) {
				return self::claim_result( false, 'claim_reference_missing', '' );
			}

			$state = self::state( $fresh );

			if ( '' !== $state ) {
				/*
				 * A debt already exists. It is reused ONLY when it provably
				 * belongs to THIS record: same carrier reference, and an
				 * instant actually recorded. An empty owner or a different one
				 * is never adopted silently -- that is how one shipment's debt
				 * would be spent on another's dispatch.
				 */
				$owner    = (string) $fresh->get_meta( self::META_DUE_REFERENCE, true );
				$handover = (string) $fresh->get_meta( self::META_HANDOVER_AT, true );

				if ( '' === $owner || '' === $handover ) {
					return self::claim_result( false, 'notification_claim_unverified', '' );
				}

				if ( $owner !== $reference ) {
					return self::claim_result( false, 'claim_other_record', '' );
				}

				return self::claim_result( true, 'already_claimed', $handover );
			}

			$now = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:sP' );

			$fresh->update_meta_data( self::META_STATE, self::STATE_DUE );
			$fresh->update_meta_data( self::META_CODE, '' );
			$fresh->update_meta_data( self::META_DUE_REFERENCE, $reference );
			$fresh->update_meta_data( self::META_HANDOVER_AT, $now );
			$fresh->update_meta_data( self::META_AT, time() );
			$fresh->save_meta_data();

			/*
			 * READBACK, BYTE FOR BYTE, FROM THE DATABASE.
			 *
			 * `save_meta_data()` returning without an error is not evidence
			 * that the row landed: a filter, a full disk, a dropped statement
			 * or a replication lag all produce a silent success. Measured with
			 * the write dropped at the `query` filter: the record was still
			 * fulfilled, the date still written and the e-mail still sent,
			 * while nothing at all was persisted -- and the next poll then
			 * answered `not_due`. So the debt is read back and compared
			 * exactly; anything else is a refusal.
			 */
			$verify = self::reload_order( $order_id );

			if ( ! $verify instanceof WC_Order ) {
				return self::claim_result( false, 'notification_claim_unverified', '' );
			}

			if ( self::STATE_DUE !== self::state( $verify )
				|| $reference !== (string) $verify->get_meta( self::META_DUE_REFERENCE, true )
				|| $now !== (string) $verify->get_meta( self::META_HANDOVER_AT, true ) ) {
				return self::claim_result( false, 'notification_claim_unverified', '' );
			}

			return self::claim_result( true, 'claimed', $now );
		} finally {
			self::release_claim_lock( $order_id );
		}
	}

	/**
	 * @param bool   $ok       Whether the debt is recorded and verified.
	 * @param string $outcome  Fixed, measurable reason.
	 * @param string $handover Agreed handover instant, '' when there is none.
	 * @return array{ok: bool, outcome: string, handover: string}
	 */
	private static function claim_result( bool $ok, string $outcome, string $handover ): array {
		return array(
			'ok'       => $ok,
			'outcome'  => $outcome,
			'handover' => $handover,
		);
	}

	/**
	 * Send the customer e-mail for a fulfilment that has just become fulfilled.
	 *
	 * Everything here happens under an order-keyed, zero-wait advisory lock,
	 * and the order and the fulfilment record are re-read from the database
	 * once the lock is held. The caller's objects were loaded before its own
	 * decision to write, so they cannot answer "has anybody already notified
	 * this customer?" -- only a fresh read can.
	 *
	 * @param WC_Order $order            Order.
	 * @param object   $fulfillment      This module's own fulfilment record.
	 * @param bool     $first_transition True when THIS call flipped the record.
	 * @param string   $reference        Carrier reference, for the fresh re-read.
	 * @return array{sent: bool, outcome: string, code: string, attempts: int}
	 */
	public static function on_fulfilled( WC_Order $order, $fulfillment, bool $first_transition, string $reference = '' ): array {
		$order_id = (int) $order->get_id();

		if ( ! self::acquire_lock( $order_id ) ) {
			/*
			 * Another process is inside this decision right now. It does not
			 * matter what it decides: this process must not send, and must not
			 * wait for it either. The winner's result is durable, so the next
			 * poll reads it and answers 'already_sent' without a second
			 * message.
			 */
			return self::outcome( false, 'lock_contended', self::code( $order ), self::attempts( $order ) );
		}

		try {
			$fresh = self::reload_order( $order_id );

			if ( ! $fresh instanceof WC_Order ) {
				return self::outcome( false, 'order_unreadable', '', 0 );
			}

			if ( '' === $reference ) {
				$reference = is_object( $fulfillment ) && method_exists( $fulfillment, 'get_meta' )
					? (string) $fulfillment->get_meta( Kuka_Island_Shipping_Fulfillment_Writer::META_REFERENCE, true )
					: '';
			}

			$record = Kuka_Island_Shipping_Fulfillment_Writer::find_own( $fresh, $reference );

			if ( null === $record ) {
				/*
				 * The record this notification is about cannot be re-read. A
				 * "your parcel has shipped" message for a dispatch that cannot
				 * be confirmed is not sent.
				 */
				return self::outcome( false, 'record_unreadable', self::code( $fresh ), self::attempts( $fresh ) );
			}

			return self::decide( $fresh, $record, $first_transition, $reference );
		} finally {
			self::release_lock( $order_id );
		}
	}

	/**
	 * The decision itself, taken only from records read inside the lock.
	 *
	 * @param WC_Order $order            Freshly read order.
	 * @param object   $fulfillment      Freshly read fulfilment record.
	 * @param bool     $first_transition True when the caller flipped the record.
	 * @param string   $reference        The record this decision is about.
	 * @return array{sent: bool, outcome: string, code: string, attempts: int}
	 */
	private static function decide( WC_Order $order, $fulfillment, bool $first_transition, string $reference ): array {
		$state = self::state( $order );

		if ( self::STATE_SENT === $state ) {
			// The repeated-poll case, and the whole reason this state exists.
			return self::outcome( false, 'already_sent', self::code( $order ), self::attempts( $order ) );
		}

		if ( self::STATE_MANUAL_REVIEW === $state ) {
			return self::outcome( false, 'manual_review', self::code( $order ), self::attempts( $order ) );
		}

		if ( self::STATE_SENDING === $state ) {
			/*
			 * A previous attempt wrote its intent and never wrote an outcome:
			 * the process died with the transport conversation open. The
			 * customer may already have the message, so this is exactly the
			 * case that must NOT be retried.
			 */
			self::settle( $order, self::STATE_MANUAL_REVIEW, 'send_outcome_unknown' );

			return self::outcome( false, 'crashed_previous_attempt', 'send_outcome_unknown', self::attempts( $order ) );
		}

		/*
		 * OWED STATES. `due` was written before the record was saved, and
		 * `pending` means a condition was missing when the transport was
		 * reached for. Both are debts this module wrote for itself, so they
		 * are paid whatever `first_transition` says -- that flag only answers
		 * "did THIS call flip the record", and by the second poll it is false
		 * for a debt that is still perfectly real.
		 */
		$owed = in_array( $state, array( self::STATE_DUE, self::STATE_PENDING ), true );

		if ( self::STATE_FAILED === $state ) {
			if ( self::attempts( $order ) >= self::MAX_ATTEMPTS ) {
				return self::outcome( false, 'retry_exhausted', self::code( $order ), self::attempts( $order ) );
			}

			$owed = true;
		} elseif ( ! $owed && ! $first_transition ) {
			/*
			 * Nothing is owed and this call did not flip anything: the record
			 * was already fulfilled and no debt was ever written for it. A
			 * manual fulfilment and somebody else's already-fulfilled record
			 * both land here, and neither is adopted.
			 */
			return self::outcome( false, 'not_due', self::code( $order ), self::attempts( $order ) );
		}

		/*
		 * A debt belongs to the record that created it. If the order carries a
		 * reference and it is not this one, the debt is somebody else's.
		 */
		if ( $owed ) {
			$owner = (string) $order->get_meta( self::META_DUE_REFERENCE, true );

			if ( '' !== $owner && '' !== $reference && $owner !== $reference ) {
				return self::outcome( false, 'other_record', self::code( $order ), self::attempts( $order ) );
			}
		}

		/*
		 * Read inside the lock, from the database: the record must really be
		 * fulfilled right now. The caller decided to write before this lock was
		 * held, so its own view is not evidence.
		 */
		if ( ! self::is_fulfilled( $fulfillment ) ) {
			return self::outcome( false, 'not_due', self::code( $order ), self::attempts( $order ) );
		}

		$recipient = self::recipient( $order );

		if ( '' === $recipient ) {
			self::settle( $order, self::STATE_PENDING, 'recipient_missing' );

			return self::outcome( false, 'recipient_missing', 'recipient_missing', self::attempts( $order ) );
		}

		/*
		 * The e-mail classes attach themselves to the action in their own
		 * constructors, and in a WP-CLI or Action Scheduler context nothing has
		 * necessarily built them yet. Loading the mailer is idempotent.
		 */
		if ( ! function_exists( 'WC' ) || ! method_exists( WC(), 'mailer' ) ) {
			self::settle( $order, self::STATE_PENDING, 'mailer_unavailable' );

			return self::outcome( false, 'mailer_unavailable', 'mailer_unavailable', self::attempts( $order ) );
		}

		WC()->mailer();

		// THE INTENT, BEFORE THE TRANSPORT. Everything after this line may die.
		$attempts = self::attempts( $order ) + 1;
		self::settle( $order, self::STATE_SENDING, '', $attempts );

		$seen = self::fire( $order, $fulfillment, $recipient );

		if ( $seen['succeeded'] ) {
			self::settle( $order, self::STATE_SENT, '', $attempts );

			return self::outcome( true, 'sent', '', $attempts );
		}

		if ( $seen['failed'] ) {
			// Definite: the transport itself reported the refusal.
			self::settle( $order, self::STATE_FAILED, $seen['code'], $attempts );
			self::note(
				$order,
				sprintf(
					/* translators: %s: allow-listed mail transport code. */
					__( 'Kargo bildirimi e-postası gönderilemedi (%s). Müşteriye ileti ulaşmadı; sınırlı sayıda yeniden denenecek.', 'kuka-island-shipping-automation' ),
					$seen['code']
				)
			);

			return self::outcome( false, 'failed', $seen['code'], $attempts );
		}

		if ( ! $seen['attempted'] ) {
			/*
			 * Nothing was handed to the transport at all -- the e-mail is
			 * switched off in WooCommerce, or a filter stopped it. Not an
			 * uncertainty: nothing was sent, so a later attempt is safe.
			 */
			self::settle( $order, self::STATE_FAILED, 'mail_not_attempted', $attempts );

			return self::outcome( false, 'not_attempted', 'mail_not_attempted', $attempts );
		}

		/*
		 * The message went to the transport and neither signal came back. The
		 * customer may have it. Automation stops here for good.
		 */
		self::settle( $order, self::STATE_MANUAL_REVIEW, 'send_outcome_unknown', $attempts );
		self::note(
			$order,
			__( 'Kargo bildirimi e-postası gönderildi fakat sonucu doğrulanamadı. Mükerrer ileti riski nedeniyle otomatik olarak tekrar gönderilmez; manuel inceleme gerekiyor.', 'kuka-island-shipping-automation' )
		);

		return self::outcome( false, 'outcome_unknown', 'send_outcome_unknown', $attempts );
	}

	/**
	 * Fire the notification with listeners scoped to THIS message only.
	 *
	 * The three listeners are attached immediately before the action and
	 * removed immediately after it, in a finally, and each one additionally
	 * checks that the message it is looking at is addressed to this order's
	 * customer. Both guards matter: WooCommerce may send other mail inside the
	 * same request -- an admin copy, an unrelated notification -- and a listener
	 * that counted those would report a success this module never caused.
	 *
	 * @param WC_Order $order       Order.
	 * @param object   $fulfillment Fulfilment record.
	 * @param string   $recipient   Normalised customer address.
	 * @return array{attempted: bool, succeeded: bool, failed: bool, code: string}
	 */
	private static function fire( WC_Order $order, $fulfillment, string $recipient ): array {
		$seen = array(
			'attempted' => false,
			'succeeded' => false,
			'failed'    => false,
			'code'      => '',
		);

		$mine = static function ( $to ) use ( $recipient ): bool {
			foreach ( (array) ( is_array( $to ) ? $to : explode( ',', (string) $to ) ) as $address ) {
				if ( strtolower( trim( (string) $address ) ) === $recipient ) {
					return true;
				}
			}

			return false;
		};

		/*
		 * 'pre_wp_mail' rather than 'wp_mail': it is the first thing wp_mail()
		 * does, so it fires even when an SMTP plugin short-circuits the rest of
		 * the function -- which is precisely the case where the message HAS
		 * been handed over. Read-only; the value passes through untouched.
		 */
		$on_attempt = static function ( $short_circuit, $args = array() ) use ( &$seen, $mine ) {
			if ( is_array( $args ) && $mine( $args['to'] ?? '' ) ) {
				$seen['attempted'] = true;
			}

			return $short_circuit;
		};

		$on_success = static function ( $mail_data ) use ( &$seen, $mine ): void {
			if ( is_array( $mail_data ) && $mine( $mail_data['to'] ?? '' ) ) {
				$seen['succeeded'] = true;
			}
		};

		$on_failure = static function ( $error ) use ( &$seen, $mine ): void {
			if ( ! $error instanceof WP_Error ) {
				return;
			}

			$data = $error->get_error_data();

			if ( is_array( $data ) && ! $mine( $data['to'] ?? '' ) ) {
				return;
			}

			$seen['failed'] = true;
			// The code, never the message: the message can carry a credential.
			$code           = (string) $error->get_error_code();
			$seen['code']   = in_array( $code, self::safe_codes(), true ) ? $code : 'mail_failed';
		};

		add_filter( 'pre_wp_mail', $on_attempt, PHP_INT_MAX, 2 );
		add_action( 'wp_mail_succeeded', $on_success, PHP_INT_MAX );
		add_action( 'wp_mail_failed', $on_failure, PHP_INT_MAX );

		try {
			/**
			 * WooCommerce's own customer notification, fired for a fulfilment
			 * this module owns and has just seen become fulfilled.
			 *
			 * Same action, same e-mail class and same template a person's
			 * "notify customer" tick uses. Nothing about the manual route
			 * changes.
			 */
			do_action( self::CREATED_NOTIFICATION, (int) $order->get_id(), $fulfillment, $order );
		} catch ( Throwable $thrown ) {
			// Never the exception text: it can quote the SMTP conversation.
			unset( $thrown );
			$seen['failed'] = true;
			$seen['code']   = 'mail_failed';
		} finally {
			remove_filter( 'pre_wp_mail', $on_attempt, PHP_INT_MAX );
			remove_action( 'wp_mail_succeeded', $on_success, PHP_INT_MAX );
			remove_action( 'wp_mail_failed', $on_failure, PHP_INT_MAX );
		}

		return $seen;
	}

	/**
	 * Write the state, the reason and the attempt count in ONE save.
	 *
	 * The order's status is deliberately untouched: a shipment notification is
	 * not a completion, and moving the order would send WooCommerce's own
	 * completed-order e-mail on top of this one.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $state    One of the STATE_* constants.
	 * @param string   $code     Allow-listed reason, '' when there is none.
	 * @param int|null $attempts Attempt count to store, null to keep.
	 */
	private static function settle( WC_Order $order, string $state, string $code, ?int $attempts = null ): void {
		$order->update_meta_data( self::META_STATE, $state );
		$order->update_meta_data( self::META_CODE, in_array( $code, self::safe_codes(), true ) ? $code : '' );
		$order->update_meta_data( self::META_AT, time() );

		if ( null !== $attempts ) {
			$order->update_meta_data( self::META_ATTEMPTS, $attempts );
		}

		$order->save_meta_data();
	}

	/** The customer address this notification is for, normalised, or ''. */
	private static function recipient( WC_Order $order ): string {
		$email = sanitize_email( (string) $order->get_billing_email() );

		return is_email( $email ) ? strtolower( $email ) : '';
	}

	/** @return array{sent: bool, outcome: string, code: string, attempts: int} */
	private static function outcome( bool $sent, string $outcome, string $code, int $attempts ): array {
		return array(
			'sent'     => $sent,
			'outcome'  => $outcome,
			'code'     => $code,
			'attempts' => $attempts,
		);
	}

	/** An order note carrying only this project's own sentence. */
	private static function note( WC_Order $order, string $message ): void {
		$order->add_order_note( $message );
	}

	/** Is this fulfilment record fulfilled, as the database has it right now? */
	private static function is_fulfilled( $fulfillment ): bool {
		return is_object( $fulfillment )
			&& method_exists( $fulfillment, 'get_is_fulfilled' )
			&& (bool) $fulfillment->get_is_fulfilled();
	}

	/**
	 * The order as the database has it, not as this process remembers it.
	 *
	 * The caller loaded its order object at the start of its own work, before
	 * any decision was written. Under HPOS that object also sits in this
	 * process's order cache, so wc_get_order() would hand back the same stale
	 * meta. It is dropped from the caches first; what comes back is a read.
	 */
	private static function reload_order( int $order_id ): ?WC_Order {
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				$cache = wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class );

				if ( is_object( $cache ) && method_exists( $cache, 'remove' ) ) {
					$cache->remove( $order_id );
				}
			} catch ( Throwable $unavailable ) {
				unset( $unavailable );
			}
		}

		wp_cache_delete( $order_id, 'orders' );
		wp_cache_delete( $order_id, 'post_meta' );

		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Take the notification lock for one order, or refuse immediately.
	 *
	 * Zero timeout, per connection: a second process gets 0 rather than
	 * waiting, which is what makes a deadlock against the carrier mutation lock
	 * impossible -- nothing in this module ever waits while holding a lock.
	 */
	private static function acquire_lock( int $order_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK_PREFIX . $order_id ) );

		return '1' === (string) $acquired;
	}

	private static function release_lock( int $order_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_PREFIX . $order_id ) );
	}

	/**
	 * Take the claim's leaf lock, waiting a bounded time.
	 *
	 * The only wait in this module, and it cannot deadlock: the holder does one
	 * meta read and one meta write and waits for nothing, so it always
	 * releases. A timeout is not fatal -- the claim falls back to its own
	 * instant, which is what the code did before this lock existed.
	 */
	private static function acquire_claim_lock( int $order_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::CLAIM_LOCK_PREFIX . $order_id, self::CLAIM_LOCK_WAIT )
		);

		return '1' === (string) $acquired;
	}

	private static function release_claim_lock( int $order_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::CLAIM_LOCK_PREFIX . $order_id ) );
	}
}
