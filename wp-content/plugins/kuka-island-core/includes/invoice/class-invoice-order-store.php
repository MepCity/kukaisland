<?php
/**
 * Invoice Order Meta Store.
 *
 * Persists and retrieves invoice state on WC_Order using standard WC CRUD,
 * guaranteeing full compatibility with HPOS and legacy post meta.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Order_Store {
	public const META_STATUS            = '_kuka_invoice_status';
	public const META_DOCUMENT_TYPE     = '_kuka_invoice_document_type';
	public const META_UUID              = '_kuka_invoice_uuid';
	public const META_NUMBER            = '_kuka_invoice_number';
	/**
	 * Provenance of META_NUMBER. Only ever set to self::NUMBER_SOURCE_EDM, and
	 * only when the integrator itself returned the number. Legacy rows written
	 * by the removed local generator carry no provenance and are therefore not
	 * usable as fiscal numbers.
	 */
	public const META_NUMBER_SOURCE     = '_kuka_invoice_number_source';
	public const NUMBER_SOURCE_EDM      = 'edm';
	public const META_PROVIDER          = '_kuka_invoice_provider';
	public const META_PROFILE_ID        = '_kuka_invoice_profile_id';
	public const META_CREATED_AT        = '_kuka_invoice_created_at';
	public const META_SENT_AT           = '_kuka_invoice_sent_at';
	public const META_LAST_QUERIED_AT   = '_kuka_invoice_last_queried_at';
	public const META_LAST_ERROR        = '_kuka_invoice_last_error';
	public const META_ATTEMPTS          = '_kuka_invoice_attempts';
	public const META_HISTORY           = '_kuka_invoice_history';

	/**
	 * Append-only record of documents this order has superseded.
	 *
	 * Written by the operator-approved recreate flow. Nothing removes entries:
	 * a document that once existed at EDM stays on the record even after a
	 * replacement is issued.
	 */
	public const META_SUPERSEDED = '_kuka_invoice_superseded';

	/**
	 * Write an EDM-assigned document number, or refuse to.
	 *
	 * The single place META_NUMBER is set. The automatic-numbering sentinel is
	 * rejected here rather than at each call site: it is EDM's "assign this
	 * yourself" request, it looks exactly like a fiscal identifier, and writing
	 * it would put a number on the order that no tax authority ever issued.
	 *
	 * @param WC_Order $order  WooCommerce order.
	 * @param string   $number Candidate document number from an EDM response.
	 * @return bool Whether the number was accepted and written.
	 */
	private static function write_edm_number( WC_Order $order, string $number ): bool {
		$number = trim( $number );

		if ( '' === $number || Kuka_Island_Core_Invoice_Numbering::is_auto_number_sentinel( $number ) ) {
			return false;
		}

		$order->update_meta_data( self::META_NUMBER, $number );
		$order->update_meta_data( self::META_NUMBER_SOURCE, self::NUMBER_SOURCE_EDM );

		return true;
	}

	public static function get_status( WC_Order $order ): string {
		$status = (string) $order->get_meta( self::META_STATUS, true );
		return '' !== $status ? $status : Kuka_Island_Core_Invoice_Status::STATUS_NONE;
	}

	public static function get_invoice_data( WC_Order $order ): array {
		return array(
			'status'          => self::get_status( $order ),
			'document_type'   => (string) $order->get_meta( self::META_DOCUMENT_TYPE, true ) ?: Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
			'uuid'            => (string) $order->get_meta( self::META_UUID, true ),
			'invoice_number'  => (string) $order->get_meta( self::META_NUMBER, true ),
			'number_source'   => (string) $order->get_meta( self::META_NUMBER_SOURCE, true ),
			'provider'        => (string) $order->get_meta( self::META_PROVIDER, true ) ?: 'edm',
			'profile_id'      => (string) $order->get_meta( self::META_PROFILE_ID, true ) ?: 'EARSIVFATURA',
			'created_at'      => (int) $order->get_meta( self::META_CREATED_AT, true ),
			'sent_at'         => (int) $order->get_meta( self::META_SENT_AT, true ),
			'last_queried_at' => (int) $order->get_meta( self::META_LAST_QUERIED_AT, true ),
			'last_error'      => (string) $order->get_meta( self::META_LAST_ERROR, true ),
			'attempts'        => (int) $order->get_meta( self::META_ATTEMPTS, true ),
			'history'         => (array) ( $order->get_meta( self::META_HISTORY, true ) ?: array() ),
		);
	}

	public static function set_status( WC_Order $order, string $status, string $note = '' ): void {
		$old_status = self::get_status( $order );
		$order->update_meta_data( self::META_STATUS, $status );

		self::add_history_entry( $order, $status, $note ?: sprintf( 'Durum değiştirildi: %s -> %s', $old_status, $status ) );
		$order->save_meta_data();
	}

	/**
	 * Atomically persist UUID, planned invoice number, and 'sending' status before SendInvoice network call.
	 */
	public static function mark_sending( WC_Order $order, string $uuid, string $invoice_number, string $note = '' ): void {
		$order->update_meta_data( self::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_SENDING );
		$order->update_meta_data( self::META_UUID, $uuid );

		/*
		 * Writing the live UUID is what spends an operator-approved replacement
		 * identity, so the reservation goes in the SAME atomic write.
		 *
		 * Releasing it after the provider returned was not safe: a SendInvoice
		 * that threw, or a process killed mid-call, left the reservation next to
		 * a live UUID it had already become. Recovery::approve() then read that
		 * leftover as "a replacement is still waiting to be sent" and refused to
		 * mint one for the document that had actually just failed.
		 */
		$order->delete_meta_data( Kuka_Island_Core_Invoice_Recovery::META_RESERVED_UUID );

		// Pre-transmission there is no document number to record: the UBL asks
		// EDM to assign one. write_edm_number() refuses the sentinel, so even a
		// caller that passed it cannot leave it on the order.
		self::write_edm_number( $order, $invoice_number );
		self::add_history_entry( $order, Kuka_Island_Core_Invoice_Status::STATUS_SENDING, $note ?: 'Fatura XML oluşturuldu, EDM gönderimi başlatılıyor.' );
		$order->save_meta_data();
	}

	public static function save_invoice_sent( WC_Order $order, Kuka_Island_Core_Invoice_Result $result, string $doc_type, string $profile_id ): void {
		$now = time();

		$order->update_meta_data( self::META_STATUS, $result->get_status() );
		$order->update_meta_data( self::META_DOCUMENT_TYPE, $doc_type );
		$order->update_meta_data( self::META_PROFILE_ID, $profile_id );
		$order->update_meta_data( self::META_PROVIDER, 'edm' );

		if ( '' !== $result->get_uuid() ) {
			$order->update_meta_data( self::META_UUID, $result->get_uuid() );
		}

		// EDM's own INVOICE/@ID from the response is the only fiscal number.
		self::write_edm_number( $order, $result->get_invoice_number() );

		$order->update_meta_data( self::META_SENT_AT, $now );
		$order->update_meta_data( self::META_LAST_QUERIED_AT, $now );
		$order->update_meta_data( self::META_LAST_ERROR, '' );

		$attempts = (int) $order->get_meta( self::META_ATTEMPTS, true );
		$order->update_meta_data( self::META_ATTEMPTS, $attempts + 1 );

		self::add_history_entry( $order, $result->get_status(), sprintf( 'Fatura EDM sistemine iletildi (%s: %s).', Kuka_Island_Core_Invoice_Status::get_type_label( $doc_type ), $result->get_invoice_number() ?: $result->get_uuid() ) );

		$order->save_meta_data();
	}

	public static function save_invoice_error( WC_Order $order, string $safe_error_code, string $message ): void {
		$attempts = (int) $order->get_meta( self::META_ATTEMPTS, true );
		$order->update_meta_data( self::META_ATTEMPTS, $attempts + 1 );
		$order->update_meta_data( self::META_LAST_ERROR, $safe_error_code );
		$order->update_meta_data( self::META_LAST_QUERIED_AT, time() );
		$order->update_meta_data( self::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW );

		self::add_history_entry( $order, Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW, sprintf( 'Hata (%s): %s', $safe_error_code, $message ) );

		$order->save_meta_data();
	}

	/**
	 * Persist a deliberate fail-closed block that happened BEFORE any transmission.
	 *
	 * Distinct from save_invoice_error(): nothing was sent, so the attempt
	 * counter is not advanced and the status is not needs_manual_review.
	 */
	public static function save_blocked( WC_Order $order, string $safe_error_code, string $message ): void {
		$order->update_meta_data( self::META_LAST_ERROR, $safe_error_code );
		$order->update_meta_data( self::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_BLOCKED );

		self::add_history_entry( $order, Kuka_Island_Core_Invoice_Status::STATUS_BLOCKED, sprintf( 'Fail-closed engel (%s): %s', $safe_error_code, $message ) );

		$order->save_meta_data();
	}

	/**
	 * Persist uncertain / network timeout state during SendInvoice requiring reconciliation before retry.
	 */
	public static function save_send_uncertain( WC_Order $order, string $safe_error_code, string $message ): void {
		$attempts = (int) $order->get_meta( self::META_ATTEMPTS, true );
		$order->update_meta_data( self::META_ATTEMPTS, $attempts + 1 );
		$order->update_meta_data( self::META_LAST_ERROR, $safe_error_code );
		$order->update_meta_data( self::META_LAST_QUERIED_AT, time() );
		$order->update_meta_data( self::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN );

		self::add_history_entry( $order, Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN, sprintf( 'Ağ Belirsizliği / Zaman Aşımı (%s): %s. Mükerrerliği önlemek için uzlaştırma bekleniyor.', $safe_error_code, $message ) );

		$order->save_meta_data();
	}

	/**
	 * Poll-state meta keys that belong to ONE document.
	 *
	 * Archived with the document they describe and removed from the live record,
	 * so a replacement starts from a clean budget and carries no side signal
	 * from the document it replaced.
	 *
	 * @return array<int, string>
	 */
	public static function superseded_poll_meta_keys(): array {
		return array(
			Kuka_Island_Core_Invoice_Status_Poller::META_POLL_ATTEMPTS,
			Kuka_Island_Core_Invoice_Status_Poller::META_POLL_STARTED_AT,
			Kuka_Island_Core_Invoice_Status_Poller::META_LAST_EDM_STATUS,
			Kuka_Island_Core_Invoice_Status_Poller::META_RESPONSE_CODE,
			Kuka_Island_Core_Invoice_Status_Poller::META_EARCHIVE_REPORT_STATUS,
			Kuka_Island_Core_Invoice_Status_Poller::META_GIB_STATUS_CODE,
			Kuka_Island_Core_Invoice_Status_Poller::META_LAST_SCHEDULE_OUTCOME,
		);
	}

	/**
	 * Move a refused document into the audit archive and free the send path.
	 *
	 * Append-only: the failed document's UUID, EDM-assigned number, status and
	 * last EDM status literal are all kept, and nothing is ever removed from the
	 * archive. What is cleared is the LIVE identity -- UUID, number, provenance,
	 * sent_at and the send-attempt counter -- because those four are what
	 * Kuka_Island_Core_Invoice_Manager::transmission_evidence() reads, and the
	 * replacement genuinely has not been transmitted.
	 *
	 * Clearing them is safe only because the caller has established that EDM
	 * REFUSED the old document, and because the archive keeps it on the record.
	 *
	 * @param WC_Order             $order           WooCommerce order.
	 * @param array<string, mixed> $superseded      Snapshot of the refused document.
	 * @param string               $replacement_uuid UUID minted for the replacement.
	 * @param int                  $generation      Replacement count for this order.
	 * @param string               $safe_error_code Safe classification code.
	 * @param string               $message         Fixed operator-facing sentence.
	 */
	public static function archive_superseded_document( WC_Order $order, array $superseded, string $replacement_uuid, int $generation, string $safe_error_code, string $message ): void {
		$archive   = $order->get_meta( self::META_SUPERSEDED, true );
		$archive   = is_array( $archive ) ? array_values( $archive ) : array();
		$archive[] = array_merge(
			$superseded,
			array(
				'generation'       => $generation,
				'replacement_uuid' => $replacement_uuid,
			)
		);

		$order->update_meta_data( self::META_SUPERSEDED, $archive );

		// The live identity of a document that no longer exists here.
		$order->delete_meta_data( self::META_UUID );
		$order->delete_meta_data( self::META_NUMBER );
		$order->delete_meta_data( self::META_NUMBER_SOURCE );
		$order->delete_meta_data( self::META_SENT_AT );
		$order->update_meta_data( self::META_ATTEMPTS, 0 );

		// A stale replacement identity, if the previous attempt left one.
		$order->delete_meta_data( Kuka_Island_Core_Invoice_Recovery::META_RESERVED_UUID );

		/*
		 * And the refused document's polling state. It is archived above, and it
		 * must not stay live: META_POLL_STARTED_AT and META_POLL_ATTEMPTS are the
		 * attempt and elapsed budget Kuka_Island_Core_Invoice_Status_Poller::start()
		 * only initialises when they are absent, so a replacement would inherit a
		 * spent budget and give up on its first query. The EDM side signals
		 * (STATUS, RESPONSE_CODE, EARCHIVE_REPORT_STATUS, GIB_STATUS_CODE) describe
		 * the OLD document and would be read as the new one's.
		 */
		foreach ( self::superseded_poll_meta_keys() as $poll_meta_key ) {
			$order->delete_meta_data( $poll_meta_key );
		}

		$order->update_meta_data( self::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_NONE );
		$order->update_meta_data( self::META_LAST_ERROR, $safe_error_code );

		self::add_history_entry(
			$order,
			Kuka_Island_Core_Invoice_Status::STATUS_NONE,
			sprintf(
				'%s (%s) [superseded_uuid:%s|superseded_number:%s|superseded_edm_status:%s|replacement_uuid:%s|generation:%d]',
				$message,
				$safe_error_code,
				'' === (string) ( $superseded['uuid'] ?? '' ) ? 'none' : (string) $superseded['uuid'],
				'' === (string) ( $superseded['invoice_number'] ?? '' ) ? 'none' : (string) $superseded['invoice_number'],
				'' === (string) ( $superseded['edm_status'] ?? '' ) ? 'none' : (string) $superseded['edm_status'],
				$replacement_uuid,
				$generation
			)
		);

		$order->save_meta_data();
	}

	/**
	 * Persist the fail-closed post-transmission lock.
	 *
	 * A transmission was attempted and EDM has not given a usable answer, so the
	 * document may exist. STATUS_RECONCILIATION_REQUIRED is outside can_retry(),
	 * which is the whole point: needs_manual_review would let the send path pick
	 * this document up again and produce a second fiscal document.
	 *
	 * The attempt counter is not advanced -- no new transmission happened -- and
	 * the UUID and document number are left exactly as they are, because they are
	 * what a manual GetInvoiceStatus needs.
	 *
	 * Only the safe error code is stored: no exception text, credential, SOAP
	 * payload or customer data.
	 *
	 * @param WC_Order $order           WooCommerce order.
	 * @param string   $safe_error_code Safe classification code.
	 * @param string   $message         Fixed operator-facing sentence.
	 * @return bool Whether this call changed the status (a first transition).
	 */
	public static function save_reconciliation_required( WC_Order $order, string $safe_error_code, string $message ): bool {
		$was = self::get_status( $order );

		$order->update_meta_data( self::META_STATUS, Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED );
		$order->update_meta_data( self::META_LAST_ERROR, $safe_error_code );
		$order->update_meta_data( self::META_LAST_QUERIED_AT, time() );

		self::add_history_entry(
			$order,
			Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
			sprintf( '%s (%s)', $message, $safe_error_code )
		);

		$order->save_meta_data();

		return Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED !== $was;
	}

	/**
	 * Leave a fixed, safe sentence in the order's own note list.
	 *
	 * The order screen's notes are where somebody working the order will read
	 * it, so a fail-closed lock that only exists in meta is not visible enough.
	 *
	 * Best effort by design: the history entry written by the caller is the
	 * record, and a note that cannot be created must not take a completed
	 * transmission down with it. Only the fixed sentence and the safe code are
	 * ever written.
	 *
	 * @param WC_Order $order           WooCommerce order.
	 * @param string   $message         Fixed operator-facing sentence.
	 * @param string   $safe_error_code Safe classification code.
	 */
	public static function add_operator_note( WC_Order $order, string $message, string $safe_error_code ): void {
		try {
			$order->add_order_note(
				sprintf(
					/* translators: 1: warning sentence, 2: safe error code */
					__( '%1$s (%2$s)', 'kuka-island-core' ),
					$message,
					$safe_error_code
				),
				0,
				false
			);
		} catch ( Throwable $note_error ) {
			unset( $note_error );
		}
	}

	/**
	 * Record that an automatic status query could not be booked.
	 *
	 * The invoice status is deliberately left exactly as it is. sent,
	 * pending_approval and send_uncertain all refuse a re-send, while
	 * needs_manual_review is a status can_retry() permits -- so rewriting an
	 * in-flight document into it would turn a scheduling problem into a second
	 * fiscal document. The attempt counter is not advanced either: no
	 * transmission happened.
	 *
	 * Only the safe error code reaches the order. No exception text, credential,
	 * SOAP payload or customer data is written here.
	 *
	 * @param WC_Order $order           WooCommerce order.
	 * @param string   $safe_error_code One of the poller's ERROR_* codes.
	 * @param string   $message         Fixed operator-facing sentence.
	 */
	public static function save_polling_not_scheduled( WC_Order $order, string $safe_error_code, string $message ): void {
		$status = self::get_status( $order );

		$order->update_meta_data( self::META_LAST_ERROR, $safe_error_code );

		// The history entry carries the status the document still has, which is
		// the point: the record says "unchanged", not "escalated".
		self::add_history_entry( $order, $status, sprintf( '%s (%s)', $message, $safe_error_code ) );

		$order->save_meta_data();
	}

	public static function save_status_query( WC_Order $order, Kuka_Island_Core_Invoice_Result $result ): void {
		$order->update_meta_data( self::META_STATUS, $result->get_status() );
		$order->update_meta_data( self::META_LAST_QUERIED_AT, time() );

		self::write_edm_number( $order, $result->get_invoice_number() );

		self::add_history_entry( $order, $result->get_status(), sprintf( 'EDM durum sorgulandı: %s (Kod: %s)', $result->get_status_description(), $result->get_status_code() ) );

		$order->save_meta_data();
	}

	private static function add_history_entry( WC_Order $order, string $status, string $message ): void {
		$history   = (array) ( $order->get_meta( self::META_HISTORY, true ) ?: array() );
		$history[] = array(
			'time'    => time(),
			'status'  => $status,
			'message' => sanitize_text_field( $message ),
			'user_id' => get_current_user_id(),
		);

		// Keep last 30 history entries to avoid unbounded growth.
		if ( count( $history ) > 30 ) {
			$history = array_slice( $history, -30 );
		}

		$order->update_meta_data( self::META_HISTORY, $history );
	}
}
