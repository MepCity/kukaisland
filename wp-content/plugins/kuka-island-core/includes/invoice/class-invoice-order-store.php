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
		if ( '' !== $invoice_number ) {
			$order->update_meta_data( self::META_NUMBER, $invoice_number );
		}
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
		if ( '' !== $result->get_invoice_number() ) {
			$order->update_meta_data( self::META_NUMBER, $result->get_invoice_number() );
			$order->update_meta_data( self::META_NUMBER_SOURCE, self::NUMBER_SOURCE_EDM );
		}

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

	public static function save_status_query( WC_Order $order, Kuka_Island_Core_Invoice_Result $result ): void {
		$order->update_meta_data( self::META_STATUS, $result->get_status() );
		$order->update_meta_data( self::META_LAST_QUERIED_AT, time() );

		if ( '' !== $result->get_invoice_number() ) {
			$order->update_meta_data( self::META_NUMBER, $result->get_invoice_number() );
			$order->update_meta_data( self::META_NUMBER_SOURCE, self::NUMBER_SOURCE_EDM );
		}

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
