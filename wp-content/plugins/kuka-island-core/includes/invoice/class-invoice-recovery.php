<?php
/**
 * Operator-approved recreation of a failed fiscal document.
 *
 * EDM reports PACKAGE - FAIL and SEND - FAILED for documents its processing
 * refused. Those are answers about a document that EXISTED at EDM, which is why
 * nothing in this plugin retries them automatically: the central
 * Kuka_Island_Core_Invoice_Manager::transmission_evidence() guard makes such an
 * order reconcile-only, and no force flag lifts it.
 *
 * EDM's own documentation does allow a corrected resubmission for some errors,
 * reusing the same document number. This integration does NOT do that: the
 * error classification that would decide when it is legal, and the same-UUID /
 * same-number contract it depends on, are not established here. Guessing either
 * one risks two fiscal documents for one sale.
 *
 * What this class provides instead is the honest alternative: a person looks at
 * the failure and asks for a NEW document. That request
 *
 * - never reuses the failed document's UUID or its EDM-assigned number,
 * - deletes nothing -- the failed document, its identifiers and the whole
 *   history stay on the order, appended to an audit record,
 * - mints one new UUID up front and reserves it for the replacement,
 * - leaves the number to EDM again, through the automatic-numbering sentinel,
 * - and cannot produce two documents from a double click or two concurrent
 *   requests, because the decision is serialised by a per-order advisory lock
 *   and made idempotent by a generation counter.
 *
 * Approving a recreation does not transmit anything. It clears the transmission
 * evidence for the NEW document only, so the ordinary send path may run exactly
 * once more, with every gate it normally has.
 *
 * The reservation is spent by Kuka_Island_Core_Invoice_Order_Store::mark_sending(),
 * in the same atomic write that records the live UUID -- not after the provider
 * answers. A SendInvoice that throws, or a process killed mid-call, therefore
 * cannot leave a consumed reservation behind; and if one is found next to a live
 * UUID anyway, approve() treats the live evidence as the truth and the
 * reservation as stale.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Recovery {

	/**
	 * UUID minted for the replacement, before it is transmitted.
	 *
	 * Deliberately NOT META_UUID: writing it there would be transmission
	 * evidence, and the replacement has not been transmitted.
	 */
	public const META_RESERVED_UUID = '_kuka_invoice_recreate_uuid';

	/** How many replacements this order has been granted. */
	public const META_GENERATION = '_kuka_invoice_recreate_generation';

	/** Advisory lock name prefix for the approval decision. */
	private const APPROVAL_LOCK_PREFIX = 'kuka_inv_recreate_';

	/** Outcome: a replacement identity was minted by this call. */
	public const OUTCOME_APPROVED = 'approved';
	/** Outcome: a replacement is already waiting to be sent. */
	public const OUTCOME_ALREADY_APPROVED = 'already_approved';
	/** Outcome: another request holds the decision for this order. */
	public const OUTCOME_LOCK_CONTENDED = 'lock_contended';
	/** Outcome: this order's document was not refused by EDM. */
	public const OUTCOME_NOT_ELIGIBLE = 'not_eligible';

	/** Safe code recorded on the order when a replacement is approved. */
	public const ERROR_RECREATE_APPROVED = 'document_recreate_approved';
	/** Safe code for a refused approval request. */
	public const ERROR_RECREATE_NOT_ELIGIBLE = 'document_recreate_not_eligible';

	/**
	 * May this order be granted a replacement document?
	 *
	 * Only a document EDM refused. 'failed' is the lifecycle
	 * Kuka_Island_Core_EDM_Document_Status maps PACKAGE - FAIL and SEND - FAILED
	 * onto, and it is deliberately the whole list: an unresolved document
	 * (reconciliation_required, send_uncertain, pending_approval) may still turn
	 * out to exist, and a replacement for one of those would be the duplicate
	 * this class exists to avoid.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public static function is_eligible( WC_Order $order ): bool {
		if ( Kuka_Island_Core_Invoice_Status::STATUS_FAILED !== Kuka_Island_Core_Invoice_Order_Store::get_status( $order ) ) {
			return false;
		}

		// There has to be a document to replace.
		return array() !== Kuka_Island_Core_Invoice_Manager::transmission_evidence( $order );
	}

	/**
	 * The UUID reserved for a replacement, or '' when none is waiting.
	 *
	 * Read by Kuka_Island_Core_Invoice_Order_Mapper when it mints the next
	 * document, so the identity a person approved is the identity that is sent.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public static function reserved_uuid( WC_Order $order ): string {
		return trim( (string) $order->get_meta( self::META_RESERVED_UUID, true ) );
	}

	/**
	 * Every document this order has superseded, oldest first.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<int, array<string, mixed>>
	 */
	public static function superseded_documents( WC_Order $order ): array {
		$archive = $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_SUPERSEDED, true );

		return is_array( $archive ) ? array_values( $archive ) : array();
	}

	/**
	 * Grant one replacement document, or say why not.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array{ok: bool, outcome: string, reserved_uuid: string, superseded: array<string, mixed>, generation: int}
	 */
	public static function approve( WC_Order $order ): array {
		$order_id = (int) $order->get_id();

		if ( ! self::acquire_lock( $order_id ) ) {
			/*
			 * Somebody else is deciding for this order right now -- the second
			 * half of a double click, or a second worker. It is not this call's
			 * job to produce a second identity, so it produces none.
			 */
			return self::outcome( self::OUTCOME_LOCK_CONTENDED, '', array(), (int) $order->get_meta( self::META_GENERATION, true ) );
		}

		try {
			// Re-read inside the lock: the state this decision depends on may
			// have moved while the lock was being taken.
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh instanceof WC_Order ) {
				return self::outcome( self::OUTCOME_NOT_ELIGIBLE, '', array(), 0 );
			}

			$generation = (int) $fresh->get_meta( self::META_GENERATION, true );
			$reserved   = self::reserved_uuid( $fresh );
			$evidence   = Kuka_Island_Core_Invoice_Manager::transmission_evidence( $fresh );

			/*
			 * A reservation only means "a replacement is waiting to be sent"
			 * while nothing has been transmitted under it. Once the live UUID
			 * exists the identity is spent, whatever the reservation still says
			 * -- mark_sending() removes it in the same atomic write, but a
			 * process killed between the two, or a record restored from an
			 * older copy, can still present both at once. Reading that as a
			 * pending approval is what locked the recovery flow: the document
			 * that had actually just failed could never be replaced.
			 *
			 * So the live evidence is checked first, and a reservation standing
			 * beside it is simply stale.
			 */
			if ( array() === $evidence && '' !== $reserved ) {
				// Idempotent: the replacement really has not been sent yet, so
				// the answer is the identity that was already minted.
				return self::outcome( self::OUTCOME_ALREADY_APPROVED, $reserved, array(), $generation );
			}

			if ( ! self::is_eligible( $fresh ) ) {
				Kuka_Island_Core_Invoice_Order_Store::save_polling_not_scheduled(
					$fresh,
					self::ERROR_RECREATE_NOT_ELIGIBLE,
					self::not_eligible_message()
				);

				return self::outcome( self::OUTCOME_NOT_ELIGIBLE, '', array(), $generation );
			}

			$superseded = self::snapshot( $fresh );
			$new_uuid   = wp_generate_uuid4();

			// Never the failed document's UUID, and never its number.
			if ( $new_uuid === ( $superseded['uuid'] ?? '' ) ) {
				$new_uuid = wp_generate_uuid4();
			}

			++$generation;

			Kuka_Island_Core_Invoice_Order_Store::archive_superseded_document(
				$fresh,
				$superseded,
				$new_uuid,
				$generation,
				self::ERROR_RECREATE_APPROVED,
				self::approved_message()
			);

			/*
			 * A query still booked for the refused document would run against a
			 * UUID that is no longer live. Only this order's ACTION_QUERY_STATUS
			 * is cancelled -- the send queue's ACTION_PROCESS_INVOICE and every
			 * other action are left exactly as they are.
			 */
			Kuka_Island_Core_Invoice_Status_Poller::unschedule( $order_id );

			$fresh->update_meta_data( self::META_RESERVED_UUID, $new_uuid );
			$fresh->update_meta_data( self::META_GENERATION, (string) $generation );
			$fresh->save_meta_data();

			Kuka_Island_Core_Invoice_Order_Store::add_operator_note(
				$fresh,
				self::approved_message(),
				self::ERROR_RECREATE_APPROVED
			);

			if ( $order !== $fresh ) {
				$order->read_meta_data( true );
			}

			return self::outcome( self::OUTCOME_APPROVED, $new_uuid, $superseded, $generation );
		} finally {
			self::release_lock( $order_id );
		}
	}

	/**
	 * What the failed document was, before it is replaced.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<string, mixed>
	 */
	private static function snapshot( WC_Order $order ): array {
		return array(
			'uuid'            => trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true ) ),
			'invoice_number'  => trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, true ) ),
			'number_source'   => trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER_SOURCE, true ) ),
			'invoice_status'  => Kuka_Island_Core_Invoice_Order_Store::get_status( $order ),
			// The refused document's own IssueDate, kept with it.
			'issue_date'      => trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_ISSUE_DATE, true ) ),
			'issue_time'      => trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_ISSUE_TIME, true ) ),
			'edm_status'      => trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Status_Poller::META_LAST_EDM_STATUS, true ) ),
			'last_error'      => trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_LAST_ERROR, true ) ),
			'superseded_at'   => time(),
			// The refused document's own polling state, kept with it. It is
			// removed from the live record so a replacement inherits neither its
			// spent attempt budget nor its EDM side signals.
			'poll_state'      => self::poll_state_snapshot( $order ),
		);
	}

	/**
	 * The polling meta a document leaves behind, as an archive entry.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<string, string>
	 */
	private static function poll_state_snapshot( WC_Order $order ): array {
		$snapshot = array();

		foreach ( Kuka_Island_Core_Invoice_Order_Store::superseded_poll_meta_keys() as $poll_meta_key ) {
			$snapshot[ $poll_meta_key ] = trim( (string) $order->get_meta( $poll_meta_key, true ) );
		}

		return $snapshot;
	}

	/**
	 * Shape one answer.
	 *
	 * @param string               $outcome    One of the OUTCOME_* constants.
	 * @param string               $uuid       Reserved UUID, or ''.
	 * @param array<string, mixed> $superseded Snapshot of the replaced document.
	 * @param int                  $generation Replacement count for this order.
	 * @return array{ok: bool, outcome: string, reserved_uuid: string, superseded: array<string, mixed>, generation: int}
	 */
	private static function outcome( string $outcome, string $uuid, array $superseded, int $generation ): array {
		return array(
			'ok'            => in_array( $outcome, array( self::OUTCOME_APPROVED, self::OUTCOME_ALREADY_APPROVED ), true ),
			'outcome'       => $outcome,
			'reserved_uuid' => $uuid,
			'superseded'    => $superseded,
			'generation'    => $generation,
		);
	}

	/**
	 * Operator-facing sentence for an approved replacement.
	 */
	public static function approved_message(): string {
		return __( 'EDM tarafından reddedilen belge için yeni bir fatura belgesi oluşturulması onaylandı. Eski belge kayıtları korunmuştur; numara yeniden EDM tarafından atanacaktır.', 'kuka-island-core' );
	}

	/**
	 * Operator-facing sentence for a refused request.
	 */
	public static function not_eligible_message(): string {
		return __( 'Bu sipariş için yeni belge oluşturulamaz: yalnızca EDM tarafından reddedilmiş (PACKAGE - FAIL / SEND - FAILED) belgeler yeniden oluşturulabilir.', 'kuka-island-core' );
	}

	/**
	 * Take the per-order approval lock, timeout 0.
	 *
	 * The loser of the race must not queue behind the winner: it would then mint
	 * a second identity the moment the lock came free, which is exactly the
	 * double-click case.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function acquire_lock( int $order_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::APPROVAL_LOCK_PREFIX . $order_id )
		);

		return '1' === (string) $acquired;
	}

	/**
	 * Release the per-order approval lock.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function release_lock( int $order_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::APPROVAL_LOCK_PREFIX . $order_id )
		);
	}
}
