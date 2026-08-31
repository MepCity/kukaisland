<?php
/**
 * Verdict for the long-lived local sandbox orders.
 *
 * These order IDs exist only in the developer database. A clean install -- CI,
 * or a fresh `make reset` -- has none of them. That absence is a different
 * situation, not a failure, and the verdict says which one it is instead of
 * quietly passing.
 *
 * Kept as a pure function so all three branches can be proved with fixtures,
 * including the clean-database branch that cannot be reproduced against the
 * developer database.
 *
 * @package Kuka_Island_Core
 */

defined( 'WP_CLI' ) || exit( 1 );

/**
 * Classify the observed state of the protected snapshot.
 *
 * @param array<int, string>                                                     $snapshot Expected order_id => 'status/total'.
 * @param array<int, array{exists: bool, is_fixture: bool, signature: string}>   $observed What the database actually holds.
 * @return array{state: string, reason: string, matching: array<int, int>, drifted: array<int, int>, absent: array<int, int>, line: string}
 */
function kuka_protected_orders_verdict( array $snapshot, array $observed ): array {
	$total    = count( $snapshot );
	$matching = array();
	$drifted  = array();
	$absent   = array();

	foreach ( $snapshot as $order_id => $expected_signature ) {
		$row = $observed[ $order_id ] ?? array( 'exists' => false );

		if ( true !== ( $row['exists'] ?? false ) ) {
			$absent[] = (int) $order_id;
			continue;
		}

		// An ID held by a transient fixture from another verification script is
		// not one of these long-lived orders, so it is absence rather than drift.
		if ( true === ( $row['is_fixture'] ?? false ) ) {
			$absent[] = (int) $order_id;
			continue;
		}

		if ( (string) ( $row['signature'] ?? '' ) === (string) $expected_signature ) {
			$matching[] = (int) $order_id;
		} else {
			$drifted[] = (int) $order_id;
		}
	}

	if ( 0 === $total ) {
		$state  = 'DRIFT';
		$reason = 'empty_snapshot_definition';
	} elseif ( count( $matching ) === $total ) {
		$state  = 'verified';
		$reason = 'all_snapshot_orders_present_and_unchanged';
	} elseif ( count( $absent ) === $total ) {
		$state  = 'not_applicable';
		$reason = 'clean_database_without_local_sandbox_orders';
	} else {
		$state  = 'DRIFT';
		$reason = 'partial_presence_or_signature_change';
	}

	$line = sprintf(
		'PROTECTED_ORDERS=%s|present:%d/%d|matching:%d|drifted:%d|absent:%d|reason:%s%s',
		$state,
		count( $matching ) + count( $drifted ),
		$total,
		count( $matching ),
		count( $drifted ),
		count( $absent ),
		$reason,
		empty( $drifted ) ? '' : '|drifted_ids:' . implode( ',', $drifted )
	);

	return array(
		'state'    => $state,
		'reason'   => $reason,
		'matching' => $matching,
		'drifted'  => $drifted,
		'absent'   => $absent,
		'line'     => $line,
	);
}
