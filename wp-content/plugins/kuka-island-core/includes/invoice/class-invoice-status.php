<?php
/**
 * Invoice status constants and state definitions.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Status {
	// Document types.
	public const TYPE_EINVOICE = 'einvoice';
	public const TYPE_EARCHIVE = 'earchive';

	// Invoice lifecycle statuses.
	public const STATUS_NONE                = 'none';
	public const STATUS_QUEUED              = 'queued';
	public const STATUS_SENDING             = 'sending';
	public const STATUS_SENT                = 'sent';
	public const STATUS_PENDING_APPROVAL    = 'pending_approval';
	public const STATUS_SEND_UNCERTAIN      = 'send_uncertain';
	public const STATUS_COMPLETED           = 'completed';
	public const STATUS_NEEDS_MANUAL_REVIEW = 'needs_manual_review';
	public const STATUS_FAILED              = 'failed';
	/**
	 * EDM reported REJECTED - SUCCEED. The exchange finished; the recipient
	 * refused the document. Terminal, and deliberately NOT completed.
	 */
	public const STATUS_REJECTED            = 'rejected';
	/** EDM reported CANCELLED - SUCCEED. Terminal, and not completed. */
	public const STATUS_CANCELLED           = 'cancelled';
	/**
	 * Deliberate fail-closed block: a required contract is unconfirmed, so the
	 * invoice was never transmitted. Distinct from a runtime error.
	 */
	public const STATUS_BLOCKED             = 'blocked';

	/**
	 * Map status to human-readable Turkish label.
	 */
	public static function get_label( string $status ): string {
		return match ( $status ) {
			self::STATUS_QUEUED              => __( 'Kuyrukta', 'kuka-island-core' ),
			self::STATUS_SENDING             => __( 'Gönderiliyor', 'kuka-island-core' ),
			self::STATUS_SENT                => __( 'Gönderildi', 'kuka-island-core' ),
			self::STATUS_PENDING_APPROVAL    => __( 'EDM/GİB Sonucu Bekleniyor', 'kuka-island-core' ),
			self::STATUS_SEND_UNCERTAIN      => __( 'Ağ/Durum Belirsiz (Uzlaştırma Gerekli)', 'kuka-island-core' ),
			self::STATUS_COMPLETED           => __( 'Tamamlandı', 'kuka-island-core' ),
			self::STATUS_NEEDS_MANUAL_REVIEW => __( 'Manuel Müdahale Gerekli', 'kuka-island-core' ),
			self::STATUS_FAILED              => __( 'Hata Oluştu', 'kuka-island-core' ),
			self::STATUS_REJECTED            => __( 'Alıcı Tarafından Reddedildi', 'kuka-island-core' ),
			self::STATUS_CANCELLED           => __( 'İptal Edildi', 'kuka-island-core' ),
			self::STATUS_BLOCKED             => __( 'Fail-Closed Engellendi (Sözleşme Doğrulanmadı)', 'kuka-island-core' ),
			default                          => __( 'Fatura Oluşturulmadı', 'kuka-island-core' ),
		};
	}

	/**
	 * Map document type to human-readable label.
	 */
	public static function get_type_label( string $type ): string {
		return match ( $type ) {
			self::TYPE_EINVOICE => __( 'e-Fatura', 'kuka-island-core' ),
			self::TYPE_EARCHIVE => __( 'e-Arşiv Fatura', 'kuka-island-core' ),
			default             => __( 'Belirsiz', 'kuka-island-core' ),
		};
	}

	/**
	 * Is this status terminal and complete?
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( self::STATUS_COMPLETED, self::STATUS_REJECTED, self::STATUS_CANCELLED ), true );
	}

	/**
	 * Did the document finish successfully?
	 *
	 * Distinct from is_terminal(): rejection and cancellation are final answers
	 * too, but they are not acceptances and must never be reported as one.
	 */
	public static function is_successful( string $status ): bool {
		return self::STATUS_COMPLETED === $status;
	}

	/**
	 * Is this status pending, uncertain, or in progress?
	 */
	public static function is_in_progress( string $status ): bool {
		return in_array( $status, array( self::STATUS_QUEUED, self::STATUS_SENDING, self::STATUS_SENT, self::STATUS_PENDING_APPROVAL, self::STATUS_SEND_UNCERTAIN ), true );
	}

	/**
	 * Can a retry or re-send be initiated for this status?
	 * Note: send_uncertain, sending, sent, pending_approval and completed CANNOT re-send directly!
	 */
	public static function can_retry( string $status ): bool {
		return in_array( $status, array( self::STATUS_NONE, self::STATUS_NEEDS_MANUAL_REVIEW, self::STATUS_FAILED, self::STATUS_BLOCKED ), true );
	}

	/**
	 * Was the invoice deliberately blocked before any transmission?
	 */
	public static function is_blocked( string $status ): bool {
		return self::STATUS_BLOCKED === $status;
	}

	/**
	 * Badge CSS color class for WooCommerce admin.
	 */
	public static function get_badge_style( string $status ): array {
		return match ( $status ) {
			self::STATUS_COMPLETED           => array(
				'bg'     => '#ecfdf5',
				'color'  => '#065f46',
				'border' => '#a7f3d0',
			),
			self::STATUS_SENT, self::STATUS_PENDING_APPROVAL => array(
				'bg'     => '#eff6ff',
				'color'  => '#1e40af',
				'border' => '#bfdbfe',
			),
			self::STATUS_QUEUED, self::STATUS_SENDING, self::STATUS_SEND_UNCERTAIN => array(
				'bg'     => '#fffbeb',
				'color'  => '#92400e',
				'border' => '#fde68a',
			),
			self::STATUS_NEEDS_MANUAL_REVIEW, self::STATUS_FAILED, self::STATUS_BLOCKED, self::STATUS_REJECTED, self::STATUS_CANCELLED => array(
				'bg'     => '#fef2f2',
				'color'  => '#991b1b',
				'border' => '#fecaca',
			),
			default                          => array(
				'bg'     => '#f3f4f6',
				'color'  => '#4b5563',
				'border' => '#e5e7eb',
			),
		};
	}
}
