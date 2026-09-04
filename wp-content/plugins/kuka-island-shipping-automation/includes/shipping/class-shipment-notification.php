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
 *     -> pending             owed, nothing attempted (transport not available)
 *     -> sending             INTENT WRITTEN, transport about to be contacted
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
	 * Send the customer e-mail for a fulfilment that has just become fulfilled.
	 *
	 * @param WC_Order $order            Order.
	 * @param object   $fulfillment      This module's own fulfilment record.
	 * @param bool     $first_transition True when THIS call flipped the record.
	 * @return array{sent: bool, outcome: string, code: string, attempts: int}
	 */
	public static function on_fulfilled( WC_Order $order, $fulfillment, bool $first_transition ): array {
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

		if ( self::STATE_FAILED === $state ) {
			if ( self::attempts( $order ) >= self::MAX_ATTEMPTS ) {
				return self::outcome( false, 'retry_exhausted', self::code( $order ), self::attempts( $order ) );
			}
		} elseif ( ! $first_transition ) {
			// Nothing is owed: the record was already fulfilled before this poll.
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
}
