<?php
/**
 * Exact mapping of EDM outbound document statuses.
 *
 * EDM publishes a closed list of document statuses at
 * https://docs.edmbilisim.com.tr/api/api-documentation/einvoice/document-statuses.html
 * and every one of them is a two-part literal such as "SEND - SUCCEED".
 *
 * The previous mapping searched the value for substrings -- SUCCESS, RED,
 * KABUL, REJECT -- which is wrong in both directions. "PACKAGE - PROCESSING"
 * contains none of them and fell through to a default that treated the invoice
 * as further along than it was, while any unrelated Turkish text containing
 * "RED" (as in "REDDEDILMEDI", or a description mentioning "CREDIT") would be
 * read as a rejection. Matching is now exact, after collapsing whitespace and
 * upper-casing, so a value either IS a known status or is unknown.
 *
 * Unknown is deliberately not an error and not a success: it maps to manual
 * review, so a status EDM adds later cannot silently complete an invoice.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_EDM_Document_Status {

	/** The document is on its way; query again later. */
	public const CLASS_PENDING = 'pending';
	/** The document reached its destination successfully. */
	public const CLASS_SUCCEEDED = 'succeeded';
	/** A final answer that is NOT an acceptance. Never shown as completed. */
	public const CLASS_NEGATIVE_TERMINAL = 'negative_terminal';
	/** EDM reported a processing failure. */
	public const CLASS_ERROR = 'error';
	/** Not in the published list. Fail-closed to manual review. */
	public const CLASS_UNKNOWN = 'unknown';

	/**
	 * The published outbound statuses, exact literals.
	 *
	 * @return array<string, string> status => class
	 */
	public static function table(): array {
		return array(
			// In flight. None of these may be presented as a finished invoice.
			'PACKAGE - PROCESSING'           => self::CLASS_PENDING,
			'SEND - PROCESSING'              => self::CLASS_PENDING,
			'SEND - WAIT_GIB_RESPONSE'       => self::CLASS_PENDING,
			'SEND - WAIT_SYSTEM_RESPONSE'    => self::CLASS_PENDING,
			'SEND - WAIT_APPLICATION_RESPONSE' => self::CLASS_PENDING,
			'UNKNOWN - UNKNOWN'              => self::CLASS_PENDING,

			// Delivered / accepted.
			'SEND - SUCCEED'                 => self::CLASS_SUCCEEDED,
			'ACCEPTED - SUCCEED'             => self::CLASS_SUCCEEDED,

			// Final, but not an acceptance. A rejected or cancelled invoice is
			// a settled fiscal fact that needs its own handling; showing it as
			// "completed" would be a false statement about the document.
			'REJECTED - SUCCEED'             => self::CLASS_NEGATIVE_TERMINAL,
			'CANCELLED - SUCCEED'            => self::CLASS_NEGATIVE_TERMINAL,

			// Processing failures.
			'PACKAGE - FAIL'                 => self::CLASS_ERROR,
			'SEND - FAILED'                  => self::CLASS_ERROR,
		);
	}

	/**
	 * Collapse a raw STATUS value to its comparable form.
	 *
	 * EDM's literals contain spaces around the dash and the wire value may
	 * arrive with padding, tabs or newlines. Only whitespace and case are
	 * normalised: no characters are added, removed or substituted, so a value
	 * that is not on the list stays off it.
	 *
	 * @param string $raw Raw STATUS from the response.
	 */
	public static function normalize( string $raw ): string {
		$collapsed = preg_replace( '/\s+/u', ' ', $raw );

		return strtoupper( trim( (string) $collapsed ) );
	}

	/**
	 * Classify a raw STATUS value.
	 *
	 * @param string $raw Raw STATUS from the response.
	 * @return array{known: bool, normalized: string, class: string, lifecycle: string}
	 */
	public static function classify( string $raw ): array {
		$normalized = self::normalize( $raw );
		$table      = self::table();

		if ( '' === $normalized ) {
			// Absent is not success. The document exists somewhere; we simply
			// have not been told where.
			return array(
				'known'      => false,
				'normalized' => '',
				'class'      => self::CLASS_UNKNOWN,
				'lifecycle'  => Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL,
			);
		}

		if ( ! array_key_exists( $normalized, $table ) ) {
			return array(
				'known'      => false,
				'normalized' => $normalized,
				'class'      => self::CLASS_UNKNOWN,
				'lifecycle'  => Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
			);
		}

		$class = $table[ $normalized ];

		return array(
			'known'      => true,
			'normalized' => $normalized,
			'class'      => $class,
			'lifecycle'  => self::lifecycle_for( $class ),
		);
	}

	/**
	 * Map a status class onto the plugin's invoice lifecycle.
	 *
	 * @param string $class One of the CLASS_* constants.
	 */
	public static function lifecycle_for( string $class ): string {
		return match ( $class ) {
			self::CLASS_SUCCEEDED         => Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED,
			self::CLASS_PENDING           => Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL,
			self::CLASS_ERROR             => Kuka_Island_Core_Invoice_Status::STATUS_FAILED,
			// Rejection and cancellation are settled outcomes with their own
			// lifecycle states, resolved by the caller from the literal.
			self::CLASS_NEGATIVE_TERMINAL => Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
			default                       => Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
		};
	}

	/**
	 * Resolve the lifecycle status for a classified value.
	 *
	 * Separates the two negative terminals from each other, so the order screen
	 * can say which of the two actually happened.
	 *
	 * @param array<string, mixed> $classified Output of classify().
	 */
	public static function resolve_lifecycle( array $classified ): string {
		$normalized = (string) ( $classified['normalized'] ?? '' );

		if ( self::CLASS_NEGATIVE_TERMINAL === ( $classified['class'] ?? '' ) ) {
			return 'REJECTED - SUCCEED' === $normalized
				? Kuka_Island_Core_Invoice_Status::STATUS_REJECTED
				: Kuka_Island_Core_Invoice_Status::STATUS_CANCELLED;
		}

		return (string) ( $classified['lifecycle'] ?? Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW );
	}

	/**
	 * Should the poller ask again?
	 *
	 * @param string $lifecycle Lifecycle status.
	 */
	public static function should_keep_polling( string $lifecycle ): bool {
		return in_array(
			$lifecycle,
			array(
				Kuka_Island_Core_Invoice_Status::STATUS_SENT,
				Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL,
				Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN,
			),
			true
		);
	}
}
