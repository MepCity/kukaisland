<?php
/**
 * Ownership contract for iyzico integration-test fixtures.
 *
 * The integration test creates real orders in the permanent database, so its
 * cleanup is the dangerous part, not its assertions. This file holds the single
 * predicate that decides whether a record may be removed, so the destructive
 * path can be reviewed and dry-run on its own — see
 * scripts/verify-iyzico-test-isolation.php.
 *
 * A record is only ever removed when all three hold:
 *
 *   1. its id is in the list this run built while creating it,
 *   2. its `_kuka_sandbox_run` meta equals this run's UUID exactly,
 *   3. its `_kuka_sandbox_audit` marker equals the fixture marker exactly.
 *
 * Nothing is matched on e-mail, name, date range or any wildcard, and the
 * long-lived sandbox orders are refused outright even if a caller passes them.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

/** Orders that must never be touched, whatever a caller claims. */
const KUKA_IYZ_PROTECTED_ORDERS = array( 125, 189, 190, 192, 193 );

/** Meta key carrying this run's UUID. */
const KUKA_IYZ_RUN_META = '_kuka_sandbox_run';

/** Meta key marking a record as a sandbox fixture. */
const KUKA_IYZ_FIXTURE_META = '_kuka_sandbox_audit';

/** Exact value the fixture marker must carry. */
const KUKA_IYZ_FIXTURE_MARKER = '1';

/**
 * A run identity must be a real UUID, not merely 36 characters long.
 *
 * A length check would accept 36 spaces, so the shape is validated instead.
 */
function kuka_iyzico_is_uuid( string $candidate ): bool {
	return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $candidate );
}

/**
 * Pure verdict for one gateway row this run believes it created.
 *
 * All three recorded facts must still be true on the row as it stands now: its
 * primary key, the order it belongs to and the token it was created with.
 *
 * @param array<string, mixed>|null $row      Row as re-read from the table.
 * @param int                       $row_id   Primary key this run recorded.
 * @param int                       $order_id Order this run recorded.
 * @param string                    $token    Token this run recorded.
 * @return array{owned:bool,reason:string}
 */
function kuka_iyzico_provider_row_verdict( ?array $row, int $row_id, int $order_id, string $token ): array {
	if ( $row_id <= 0 || $order_id <= 0 || '' === $token ) {
		return array( 'owned' => false, 'reason' => 'incomplete_record' );
	}
	if ( in_array( $order_id, KUKA_IYZ_PROTECTED_ORDERS, true ) ) {
		return array( 'owned' => false, 'reason' => 'protected_order' );
	}
	if ( ! is_array( $row ) ) {
		return array( 'owned' => false, 'reason' => 'row_not_found' );
	}
	if ( (int) ( $row['iyzico_order_id'] ?? 0 ) !== $row_id ) {
		return array( 'owned' => false, 'reason' => 'row_id_mismatch' );
	}
	if ( (int) ( $row['order_id'] ?? 0 ) !== $order_id ) {
		return array( 'owned' => false, 'reason' => 'order_id_mismatch' );
	}
	if ( ! hash_equals( $token, (string) ( $row['token'] ?? '' ) ) ) {
		return array( 'owned' => false, 'reason' => 'token_mismatch' );
	}

	return array( 'owned' => true, 'reason' => 'owned' );
}

/**
 * Pure verdict for one analytics customer row this run believes it created.
 *
 * E-mail equality alone is not ownership: a real shopper could use the same
 * address, and a row that already existed before the run is never this run's to
 * remove. Six facts must all hold — the id was recorded as a candidate by this
 * run, it was absent from the pre-run key set, it belongs to no registered
 * user, it carries this run's unique e-mail, at least one order points at it,
 * and every order pointing at it is one this run created.
 *
 * @param array<string, mixed>|null $row            Row as re-read from the table.
 * @param int                       $customer_id    Candidate id.
 * @param string                    $run_email      This run's unique fixture e-mail.
 * @param array<int, int>           $linked_orders  Orders referencing the row now.
 * @param array<int, int>           $created_orders Orders this run created.
 * @param array<int, int>           $candidate_ids  Ids this run recorded as its own.
 * @param array<int, string>        $preexisting    Customer key set before the run.
 * @return array{owned:bool,reason:string}
 */
function kuka_iyzico_customer_row_verdict( ?array $row, int $customer_id, string $run_email, array $linked_orders, array $created_orders, array $candidate_ids, array $preexisting ): array {
	if ( '' === $run_email ) {
		return array( 'owned' => false, 'reason' => 'missing_run_email' );
	}
	if ( ! is_array( $row ) ) {
		return array( 'owned' => false, 'reason' => 'row_not_found' );
	}
	if ( ! in_array( $customer_id, array_map( 'intval', $candidate_ids ), true ) ) {
		return array( 'owned' => false, 'reason' => 'not_a_run_candidate' );
	}
	// A row that already existed cannot have been created by this run, whatever
	// e-mail it happens to carry.
	if ( in_array( (string) $customer_id, array_map( 'strval', $preexisting ), true ) ) {
		return array( 'owned' => false, 'reason' => 'preexisting_customer' );
	}
	if ( (int) ( $row['user_id'] ?? 0 ) > 0 ) {
		return array( 'owned' => false, 'reason' => 'registered_user' );
	}
	if ( ! hash_equals( strtolower( $run_email ), strtolower( (string) ( $row['email'] ?? '' ) ) ) ) {
		return array( 'owned' => false, 'reason' => 'email_mismatch' );
	}
	$linked = array_map( 'intval', $linked_orders );
	if ( ! $linked ) {
		// Nothing points at the row, so nothing proves this run made it.
		return array( 'owned' => false, 'reason' => 'no_linked_run_order' );
	}
	$created = array_map( 'intval', $created_orders );
	foreach ( $linked as $order_id ) {
		if ( ! in_array( $order_id, $created, true ) ) {
			return array( 'owned' => false, 'reason' => 'referenced_by_other_order' );
		}
	}

	return array( 'owned' => true, 'reason' => 'owned' );
}

/**
 * Pure verdict for one order that an analytics customer row points at.
 *
 * Membership of the created-id list is not enough on its own: the order may
 * have been removed, replaced, or may be a protected one. Every linked order is
 * re-checked against the live record right before the customer row is removed.
 *
 * @param int         $order_id     Linked order.
 * @param string      $run_id       This run's UUID.
 * @param array<int,int> $created_ids Ids this run created.
 * @param string|null $run_meta     Run meta as read now, null when absent.
 * @param string|null $fixture_meta Fixture marker as read now, null when absent.
 * @param bool        $exists       Whether the order still exists.
 * @return array{owned:bool,reason:string}
 */
function kuka_iyzico_linked_order_verdict( int $order_id, string $run_id, array $created_ids, ?string $run_meta, ?string $fixture_meta, bool $exists ): array {
	if ( in_array( $order_id, KUKA_IYZ_PROTECTED_ORDERS, true ) ) {
		return array( 'owned' => false, 'reason' => 'protected_order' );
	}
	if ( ! $exists ) {
		return array( 'owned' => false, 'reason' => 'linked_order_missing' );
	}

	return kuka_iyzico_ownership_verdict( $order_id, $run_id, $created_ids, $run_meta, $fixture_meta );
}

/**
 * Database-backed re-check of every order an analytics customer row points at.
 *
 * @param array<int,int>    $linked      Orders referencing the row now.
 * @param string            $run_id      This run's UUID.
 * @param array<int,int>    $created_ids Ids this run created.
 * @param array<int,string> $refusals    Filled with `linked_order_not_owned:<id>`.
 */
function kuka_iyzico_linked_orders_owned( array $linked, string $run_id, array $created_ids, array &$refusals = array() ): bool {
	$refusals = array();
	foreach ( array_map( 'intval', $linked ) as $order_id ) {
		$order        = in_array( $order_id, KUKA_IYZ_PROTECTED_ORDERS, true ) ? null : wc_get_order( $order_id );
		$exists       = $order instanceof WC_Order;
		$run_meta     = $exists ? (string) $order->get_meta( KUKA_IYZ_RUN_META, true ) : null;
		$fixture_meta = $exists ? (string) $order->get_meta( KUKA_IYZ_FIXTURE_META, true ) : null;
		$verdict      = kuka_iyzico_linked_order_verdict( $order_id, $run_id, $created_ids, $run_meta, $fixture_meta, $exists );
		if ( ! $verdict['owned'] ) {
			$refusals[] = 'linked_order_not_owned:' . $order_id . '(' . $verdict['reason'] . ')';
		}
	}

	return ! $refusals;
}

/**
 * Pure ownership verdict.
 *
 * Kept free of database access so every refusal path can be dry-run without
 * creating or touching a single record.
 *
 * @param int             $order_id     Candidate order.
 * @param string          $run_id       This run's UUID.
 * @param array<int, int> $created_ids  Ids this run created.
 * @param string|null     $run_meta     Value of the run meta, null when absent.
 * @param string|null     $fixture_meta Value of the fixture marker, null when absent.
 * @return array{owned:bool,reason:string}
 */
function kuka_iyzico_ownership_verdict( int $order_id, string $run_id, array $created_ids, ?string $run_meta, ?string $fixture_meta ): array {
	if ( in_array( $order_id, KUKA_IYZ_PROTECTED_ORDERS, true ) ) {
		return array( 'owned' => false, 'reason' => 'protected_order' );
	}
	if ( ! kuka_iyzico_is_uuid( $run_id ) ) {
		return array( 'owned' => false, 'reason' => 'missing_run_id' );
	}
	if ( $order_id <= 0 ) {
		return array( 'owned' => false, 'reason' => 'invalid_order_id' );
	}
	if ( ! in_array( $order_id, array_map( 'intval', $created_ids ), true ) ) {
		return array( 'owned' => false, 'reason' => 'not_created_by_this_run' );
	}
	if ( null === $run_meta || ! hash_equals( $run_id, $run_meta ) ) {
		return array( 'owned' => false, 'reason' => 'run_meta_mismatch' );
	}
	if ( null === $fixture_meta || ! hash_equals( KUKA_IYZ_FIXTURE_MARKER, $fixture_meta ) ) {
		return array( 'owned' => false, 'reason' => 'fixture_marker_mismatch' );
	}

	return array( 'owned' => true, 'reason' => 'owned' );
}

/**
 * Database-backed wrapper: loads the record, then applies the pure verdict.
 *
 * @param int             $order_id    Candidate order.
 * @param string          $run_id      This run's UUID.
 * @param array<int, int> $created_ids Ids this run created.
 * @param string          $reason      Filled with the refusal reason.
 */
function kuka_iyzico_fixture_is_owned( int $order_id, string $run_id, array $created_ids, string &$reason = '' ): bool {
	$order = $order_id > 0 && ! in_array( $order_id, KUKA_IYZ_PROTECTED_ORDERS, true ) ? wc_get_order( $order_id ) : null;
	if ( $order_id > 0 && ! in_array( $order_id, KUKA_IYZ_PROTECTED_ORDERS, true ) && ! $order instanceof WC_Order ) {
		$reason = 'order_not_found';
		return false;
	}
	$run_meta     = $order instanceof WC_Order ? (string) $order->get_meta( KUKA_IYZ_RUN_META, true ) : null;
	$fixture_meta = $order instanceof WC_Order ? (string) $order->get_meta( KUKA_IYZ_FIXTURE_META, true ) : null;
	$verdict      = kuka_iyzico_ownership_verdict( $order_id, $run_id, $created_ids, $run_meta, $fixture_meta );
	$reason       = $verdict['reason'];

	return $verdict['owned'];
}

/**
 * Teardown state machine, kept pure so every transition is testable.
 *
 *   idle      — not attempted yet
 *   running   — in progress; re-entry must not start a second pass
 *   succeeded — every target removed and no refusal recorded
 *   failed    — at least one target refused, or a pass was interrupted
 *
 * `failed` is never quiet success: it keeps the exit code non-zero and stops
 * any further deletion attempt.
 */
const KUKA_IYZ_CLEANUP_STATES = array( 'idle', 'running', 'succeeded', 'failed' );

/**
 * Decide whether a teardown pass may start, and what the state becomes.
 *
 * @param string $state Current state.
 * @return array{state:string,proceed:bool,refusal:string}
 */
function kuka_iyzico_cleanup_enter( string $state ): array {
	if ( 'idle' === $state ) {
		return array( 'state' => 'running', 'proceed' => true, 'refusal' => '' );
	}
	if ( 'running' === $state ) {
		// Re-entered mid-pass: the first pass never finished, so this is a
		// failure, not a success.
		return array( 'state' => 'failed', 'proceed' => false, 'refusal' => 'cleanup:reentered_while_running' );
	}

	// succeeded and failed are both terminal: never delete a second time.
	return array( 'state' => $state, 'proceed' => false, 'refusal' => '' );
}

/**
 * Final state of a completed pass. Success is conditional on zero refusals.
 *
 * @param array<int, string> $refusals Refusals recorded during the pass.
 */
function kuka_iyzico_cleanup_finish( array $refusals ): string {
	return $refusals ? 'failed' : 'succeeded';
}

/**
 * Remove one order this run created, notes first.
 *
 * HPOS keeps order notes in the comments table and `delete()` leaves them
 * behind, which is how a run can silently orphan notes. Each note is removed by
 * its own id, then the order, then its analytics rows by exact order id.
 *
 * The caller must have proven ownership first; this function does not decide.
 */
function kuka_iyzico_delete_owned_order( int $order_id ): void {
	global $wpdb;
	$order = wc_get_order( $order_id );
	if ( $order instanceof WC_Order ) {
		foreach ( wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => 500 ) ) as $note ) {
			wc_delete_order_note( (int) $note->id );
		}
		$order->delete( true );
	}
	foreach ( array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_coupon_lookup', 'wc_order_tax_lookup' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . $table, array( 'order_id' => $order_id ), array( '%d' ) );
	}
}

/**
 * Primary-key sets of every table a run must leave exactly as it found it.
 *
 * Counts alone would hide a swap — one record deleted, another created — and
 * would also mask a concurrently created record being removed by the cleanup.
 * Comparing the ordered key sets makes both visible.
 *
 * @return array<string, array<int, string>>
 */
function kuka_iyzico_permanent_key_sets(): array {
	global $wpdb;
	$provider = $wpdb->prefix . 'iyzico_order';
	$keys     = static function ( string $sql ) use ( $wpdb ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$values = (array) $wpdb->get_col( $sql );
		$values = array_map( 'strval', $values );
		sort( $values, SORT_STRING );

		return $values;
	};

	return array(
		'wc_orders'          => $keys( "SELECT id FROM {$wpdb->prefix}wc_orders" ),
		'wc_order_stats'     => $keys( "SELECT order_id FROM {$wpdb->prefix}wc_order_stats" ),
		'wc_customer_lookup' => $keys( "SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup" ),
		'product_lookup'     => $keys( "SELECT order_item_id FROM {$wpdb->prefix}wc_order_product_lookup" ),
		'provider_rows'      => $keys( "SELECT iyzico_order_id FROM {$provider}" ),
		'order_notes'        => $keys( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type = 'order_note'" ),
		'products'           => $keys( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ),
	);
}

/**
 * Counts derived from the key sets, kept for the readable report line.
 *
 * @return array<string, int>
 */
function kuka_iyzico_permanent_state(): array {
	return array_map( 'count', kuka_iyzico_permanent_key_sets() );
}

/**
 * Compare two key-set snapshots.
 *
 * @param array<string, array<int, string>> $before Snapshot before the run.
 * @param array<string, array<int, string>> $after  Snapshot after the run.
 * @return array<string, array{equal:bool,removed:int,added:int}>
 */
function kuka_iyzico_key_set_diff( array $before, array $after ): array {
	$diff = array();
	foreach ( $before as $table => $keys ) {
		$now            = $after[ $table ] ?? array();
		$diff[ $table ] = array(
			'equal'   => $keys === $now,
			'removed' => count( array_diff( $keys, $now ) ),
			'added'   => count( array_diff( $now, $keys ) ),
		);
	}

	return $diff;
}
