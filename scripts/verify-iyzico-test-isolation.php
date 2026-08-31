<?php
/**
 * Read-only dry run of the integration test's destructive cleanup contract.
 *
 * The integration test creates real orders, so its cleanup is reviewed here on
 * its own: every refusal path of the ownership predicate is exercised without
 * touching a record, and the test source is checked for the delete shapes that
 * are forbidden outright.
 *
 * Nothing in this script writes or deletes.
 */

defined( 'WP_CLI' ) || exit( 1 );

require_once __DIR__ . '/lib-iyzico-test-ownership.php';

$test_file = __DIR__ . '/test-iyzico-idempotency.php';
$source    = file_exists( $test_file ) ? (string) file_get_contents( $test_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

WP_CLI::line( 'ISOLATION_TEST_FILE=' . ( '' !== $source ? 'present' : 'MISSING' ) );

/* 1. Every refusal path, purely. */
$valid_run = '11111111-2222-4333-8444-555555555555';
$other_run = '99999999-8888-4777-8666-555555555555';
$cases     = array(
	'owned'                   => array( 9001, $valid_run, array( 9001 ), $valid_run, '1', true, 'owned' ),
	'protected_order'         => array( 125, $valid_run, array( 125 ), $valid_run, '1', false, 'protected_order' ),
	'protected_order_189'     => array( 189, $valid_run, array( 189 ), $valid_run, '1', false, 'protected_order' ),
	'missing_run_id'          => array( 9001, '', array( 9001 ), '', '1', false, 'missing_run_id' ),
	'invalid_order_id'        => array( 0, $valid_run, array( 0 ), $valid_run, '1', false, 'invalid_order_id' ),
	'not_created_by_this_run' => array( 9002, $valid_run, array( 9001 ), $valid_run, '1', false, 'not_created_by_this_run' ),
	'run_meta_absent'         => array( 9001, $valid_run, array( 9001 ), null, '1', false, 'run_meta_mismatch' ),
	'run_meta_other_run'      => array( 9001, $valid_run, array( 9001 ), $other_run, '1', false, 'run_meta_mismatch' ),
	'fixture_marker_absent'   => array( 9001, $valid_run, array( 9001 ), $valid_run, null, false, 'fixture_marker_mismatch' ),
	'fixture_marker_wrong'    => array( 9001, $valid_run, array( 9001 ), $valid_run, '0', false, 'fixture_marker_mismatch' ),
);

$results = array();
foreach ( $cases as $name => $case ) {
	[ $order_id, $run_id, $created, $run_meta, $fixture_meta, $expect_owned, $expect_reason ] = $case;
	$verdict   = kuka_iyzico_ownership_verdict( $order_id, $run_id, $created, $run_meta, $fixture_meta );
	$ok        = $verdict['owned'] === $expect_owned && $verdict['reason'] === $expect_reason;
	$results[] = $name . ':' . ( $ok ? 'PASS' : 'FAIL(' . $verdict['reason'] . ')' );
}
WP_CLI::line( 'ISOLATION_OWNERSHIP=' . implode( '|', $results ) );

/* 2. The long-lived sandbox orders are refused even when fully "owned". */
$protected_refused = 0;
foreach ( KUKA_IYZ_PROTECTED_ORDERS as $id ) {
	$verdict = kuka_iyzico_ownership_verdict( $id, $valid_run, KUKA_IYZ_PROTECTED_ORDERS, $valid_run, '1' );
	$protected_refused += $verdict['owned'] ? 0 : 1;
}
WP_CLI::line( 'ISOLATION_PROTECTED=' . $protected_refused . '/' . count( KUKA_IYZ_PROTECTED_ORDERS ) );

/* 3. Forbidden delete shapes must not appear in the test source. */
$forbidden = array(
	'like_delete'   => (bool) preg_match( '/DELETE[^;]*LIKE/i', $source ),
	'email_delete'  => (bool) preg_match( '/DELETE[^;]*billing_email/i', $source ),
	'date_delete'   => (bool) preg_match( '/DELETE[^;]*date_created/i', $source ),
	'wildcard_meta' => (bool) preg_match( "/meta_key[^;]*LIKE/i", $source ),
	'bulk_wc_orders' => (bool) preg_match( '/DELETE\s+FROM\s+\S*wc_orders(?![^;]*order_id\s*=)/i', $source ),
);
$hits = array();
foreach ( $forbidden as $name => $found ) {
	$hits[] = $name . ':' . ( $found ? 'FOUND' : 'none' );
}
WP_CLI::line( 'ISOLATION_FORBIDDEN_DELETES=' . implode( '|', $hits ) );

/* 4. Cleanup must route through the shared predicate. */
$uses_predicate = str_contains( $source, 'kuka_iyzico_fixture_is_owned(' )
	&& str_contains( $source, "require_once __DIR__ . '/lib-iyzico-test-ownership.php'" );
$fails_on_refusal = str_contains( $source, 'CLEANUP_REFUSED' );
WP_CLI::line( 'ISOLATION_CLEANUP=' . ( $uses_predicate ? 'predicate-gated' : 'UNGATED' ) . '|refusal_fails_run:' . ( $fails_on_refusal ? 'yes' : 'NO' ) );

/* 5. Run identity must be cryptographic and per run. */
$run_scoped = str_contains( $source, 'wp_generate_uuid4()' ) && str_contains( $source, 'KUKA_IYZ_RUN_META' );
WP_CLI::line( 'ISOLATION_RUN_ID=' . ( $run_scoped ? 'uuid-per-run' : 'MISSING' ) );

/* 6. Run identity must be a real UUID, not merely 36 characters. */
$uuid_cases = array(
	'valid'        => array( '11111111-2222-4333-8444-555555555555', true ),
	'thirty_six_spaces' => array( str_repeat( ' ', 36 ), false ),
	'thirty_six_chars'  => array( str_repeat( 'z', 36 ), false ),
	'wrong_version'     => array( '11111111-2222-1333-8444-555555555555', false ),
	'wrong_variant'     => array( '11111111-2222-4333-2444-555555555555', false ),
	'empty'             => array( '', false ),
);
$uuid_results = array();
foreach ( $uuid_cases as $name => $case ) {
	$uuid_results[] = $name . ':' . ( kuka_iyzico_is_uuid( $case[0] ) === $case[1] ? 'PASS' : 'FAIL' );
}
WP_CLI::line( 'ISOLATION_RUN_ID_FORMAT=' . implode( '|', $uuid_results ) );

/* 7. Gateway row cleanup needs id, order and token to agree. */
$good_row  = array( 'iyzico_order_id' => 42, 'order_id' => 7001, 'token' => 'tok-a' );
$row_cases = array(
	'owned'             => array( $good_row, 42, 7001, 'tok-a', true, 'owned' ),
	'row_not_found'     => array( null, 42, 7001, 'tok-a', false, 'row_not_found' ),
	'row_id_mismatch'   => array( array( 'iyzico_order_id' => 43, 'order_id' => 7001, 'token' => 'tok-a' ), 42, 7001, 'tok-a', false, 'row_id_mismatch' ),
	'order_id_mismatch' => array( array( 'iyzico_order_id' => 42, 'order_id' => 9999, 'token' => 'tok-a' ), 42, 7001, 'tok-a', false, 'order_id_mismatch' ),
	'token_mismatch'    => array( array( 'iyzico_order_id' => 42, 'order_id' => 7001, 'token' => 'tok-b' ), 42, 7001, 'tok-a', false, 'token_mismatch' ),
	'protected_order'   => array( $good_row, 42, 125, 'tok-a', false, 'protected_order' ),
	'incomplete_record' => array( $good_row, 0, 7001, 'tok-a', false, 'incomplete_record' ),
);
$row_results = array();
foreach ( $row_cases as $name => $case ) {
	[ $row, $row_id, $order_id, $token, $expect_owned, $expect_reason ] = $case;
	$verdict       = kuka_iyzico_provider_row_verdict( $row, $row_id, $order_id, $token );
	$row_results[] = $name . ':' . ( $verdict['owned'] === $expect_owned && $verdict['reason'] === $expect_reason ? 'PASS' : 'FAIL(' . $verdict['reason'] . ')' );
}
WP_CLI::line( 'ISOLATION_PROVIDER_ROWS=' . implode( '|', $row_results ) );

/* 8. A customer row is only removable when every clause holds. */
$run_email      = 'sandbox-idempotency+abcdef12@example.com';
$guest_row      = array( 'customer_id' => 5, 'user_id' => 0, 'email' => $run_email );
$customer_cases = array(
	// row, id, email, linked, created, candidates, preexisting, expect_owned, reason
	'only_run_orders'           => array( $guest_row, 5, $run_email, array( 8001, 8002 ), array( 8001, 8002 ), array( 5 ), array(), true, 'owned' ),
	'empty_linked_orders'       => array( $guest_row, 5, $run_email, array(), array( 8001 ), array( 5 ), array(), false, 'no_linked_run_order' ),
	'preexisting_customer'      => array( $guest_row, 5, $run_email, array( 8001 ), array( 8001 ), array( 5 ), array( '5' ), false, 'preexisting_customer' ),
	'mixed_real_and_run_orders' => array( $guest_row, 5, $run_email, array( 8001, 125 ), array( 8001 ), array( 5 ), array(), false, 'referenced_by_other_order' ),
	'not_a_run_candidate'       => array( $guest_row, 5, $run_email, array( 8001 ), array( 8001 ), array( 9 ), array(), false, 'not_a_run_candidate' ),
	'row_not_found'             => array( null, 5, $run_email, array(), array(), array( 5 ), array(), false, 'row_not_found' ),
	'email_mismatch'            => array( array( 'customer_id' => 5, 'user_id' => 0, 'email' => 'real@example.com' ), 5, $run_email, array( 8001 ), array( 8001 ), array( 5 ), array(), false, 'email_mismatch' ),
	'registered_user'           => array( array( 'customer_id' => 5, 'user_id' => 3, 'email' => $run_email ), 5, $run_email, array( 8001 ), array( 8001 ), array( 5 ), array(), false, 'registered_user' ),
	'missing_run_email'         => array( $guest_row, 5, '', array( 8001 ), array( 8001 ), array( 5 ), array(), false, 'missing_run_email' ),
);
$customer_results = array();
foreach ( $customer_cases as $name => $case ) {
	[ $row, $customer_id, $email, $linked, $created, $candidates, $preexisting, $expect_owned, $expect_reason ] = $case;
	$verdict            = kuka_iyzico_customer_row_verdict( $row, $customer_id, $email, $linked, $created, $candidates, $preexisting );
	$customer_results[] = $name . ':' . ( $verdict['owned'] === $expect_owned && $verdict['reason'] === $expect_reason ? 'PASS' : 'FAIL(' . $verdict['reason'] . ')' );
}
WP_CLI::line( 'ISOLATION_CUSTOMER_ROWS=' . implode( '|', $customer_results ) );

/* 8b. Every order an analytics customer row points at is re-checked live. */
$linked_cases = array(
	'linked_full_ownership'    => array( 8001, $valid_run, array( 8001 ), $valid_run, '1', true, true, 'owned' ),
	'linked_run_meta_mismatch' => array( 8001, $valid_run, array( 8001 ), $other_run, '1', true, false, 'run_meta_mismatch' ),
	'linked_marker_missing'    => array( 8001, $valid_run, array( 8001 ), $valid_run, null, true, false, 'fixture_marker_mismatch' ),
	'linked_protected_order'   => array( 125, $valid_run, array( 125 ), $valid_run, '1', true, false, 'protected_order' ),
	'linked_order_missing'     => array( 8001, $valid_run, array( 8001 ), null, null, false, false, 'linked_order_missing' ),
	'linked_not_created'       => array( 8002, $valid_run, array( 8001 ), $valid_run, '1', true, false, 'not_created_by_this_run' ),
);
$linked_results = array();
foreach ( $linked_cases as $name => $case ) {
	[ $order_id, $run, $created, $run_meta, $marker, $exists, $expect_owned, $expect_reason ] = $case;
	$verdict          = kuka_iyzico_linked_order_verdict( $order_id, $run, $created, $run_meta, $marker, $exists );
	$linked_results[] = $name . ':' . ( $verdict['owned'] === $expect_owned && $verdict['reason'] === $expect_reason ? 'PASS' : 'FAIL(' . $verdict['reason'] . ')' );
}
WP_CLI::line( 'ISOLATION_LINKED_ORDERS=' . implode( '|', $linked_results ) );

/* 8c. The e-mail acceptance script must clean up after itself too. */
$email_file   = __DIR__ . '/verify-email-delivery.php';
$email_source = file_exists( $email_file ) ? (string) file_get_contents( $email_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$email_owned  = str_contains( $email_source, 'KUKA_IYZ_RUN_META' )
	&& str_contains( $email_source, 'KUKA_IYZ_FIXTURE_MARKER' )
	&& str_contains( $email_source, 'kuka_iyzico_fixture_is_owned(' )
	&& str_contains( $email_source, 'kuka_iyzico_delete_owned_order(' )
	&& 1 === substr_count( $email_source, 'register_shutdown_function(' );
WP_CLI::line( 'ISOLATION_EMAIL_FIXTURE=' . ( $email_owned ? 'run-owned-teardown' : 'UNOWNED' ) . '|raw_delete:' . ( preg_match( '/\$order->delete\(\s*true\s*\)/', $email_source ) ? 'FOUND' : 'none' ) );

/* 8c2. Every state transition, measured purely — no DB, no file. */
$transitions = array(
	'idle_starts'          => array( 'idle', 'running', true, '' ),
	'running_no_reentry'   => array( 'running', 'failed', false, 'cleanup:reentered_while_running' ),
	'succeeded_terminal'   => array( 'succeeded', 'succeeded', false, '' ),
	'failed_terminal'      => array( 'failed', 'failed', false, '' ),
);
$transition_results = array();
foreach ( $transitions as $name => $case ) {
	[ $from, $expect_state, $expect_proceed, $expect_refusal ] = $case;
	$got                  = kuka_iyzico_cleanup_enter( $from );
	$transition_results[] = $name . ':' . ( $got['state'] === $expect_state && $got['proceed'] === $expect_proceed && $got['refusal'] === $expect_refusal ? 'PASS' : 'FAIL(' . $got['state'] . ')' );
}
$transition_results[] = 'finish_clean:' . ( 'succeeded' === kuka_iyzico_cleanup_finish( array() ) ? 'PASS' : 'FAIL' );
foreach ( array( 'provider#1:token_mismatch', 'customer#1:preexisting_customer', 'option:run_id_mismatch', 'mu_plugin:run_id_mismatch' ) as $refusal ) {
	$label                = explode( ':', explode( '#', $refusal )[0] )[0];
	$transition_results[] = 'finish_' . $label . ':' . ( 'failed' === kuka_iyzico_cleanup_finish( array( $refusal ) ) ? 'PASS' : 'FAIL' );
}
WP_CLI::line( 'ISOLATION_CLEANUP_TRANSITIONS=' . implode( '|', $transition_results ) );

/* 8c3. No runtime fault injection may exist in the integration script. */
$injection = substr_count( $source, 'KUKA_IYZ_FAULT' ) + substr_count( $source, 'FAULT_INJECTED' );
$preflight = str_contains( $source, "HARNESS_PREFLIGHT=stale-resource" )
	&& strpos( $source, 'HARNESS_PREFLIGHT=clean' ) < strpos( $source, 'file_put_contents(' )
	&& strpos( $source, 'HARNESS_PREFLIGHT=clean' ) < strpos( $source, 'update_option( KUKA_IYZ_TEST_OPTION' )
	&& strpos( $source, 'HARNESS_LOCK=acquired' ) < strpos( $source, "HARNESS_PREFLIGHT=stale-resource" );
$write_checked = str_contains( $source, 'HARNESS_WRITE=mu-plugin-unverified' )
	&& str_contains( $source, 'HARNESS_WRITE=option-unverified' )
	&& str_contains( $source, 'HARNESS_WRITE=verified' );
WP_CLI::line( 'ISOLATION_HARNESS_SAFETY=fault_injection:' . $injection . '|preflight:' . ( $preflight ? 'before-writes' : 'MISSING' ) . '|write_verified:' . ( $write_checked ? 'yes' : 'NO' ) );

/* 8d. Cleanup is a four state machine, and success is conditional. */
$states_present = static function ( string $code ): bool {
	foreach ( array( 'cleanup_state', "'succeeded'" ) as $state ) {
		if ( ! str_contains( $code, $state ) ) {
			return false;
		}
	}
	return true;
};
$conditional_success = str_contains( $source, 'kuka_iyzico_cleanup_finish( $cleanup_refusals )' );
$no_reentry          = str_contains( $source, 'kuka_iyzico_cleanup_enter( $cleanup_state )' ) && str_contains( $source, "if ( ! \$entry['proceed'] ) {" );
$exit_nonzero        = str_contains( $source, "if ( 0 !== \$failures || \$cleanup_refusals || 'succeeded' !== \$cleanup_state ) {" );
$booleans_gone       = ! str_contains( $source, '$cleanup_done' ) && ! str_contains( $source, '$cleanup_running' );
WP_CLI::line( 'ISOLATION_CLEANUP_STATE=' . ( $states_present( $source ) && $conditional_success && $no_reentry && $exit_nonzero && $booleans_gone ? 'four-state|success-conditional|no-reentry|refusal-exits-nonzero' : 'UNSAFE' ) );

$email_state_ok = $states_present( $email_source )
	&& str_contains( $email_source, "\$cleanup_state = \$cleanup_refusals ? 'failed' : 'succeeded';" )
	&& str_contains( $email_source, "if ( 'succeeded' !== \$cleanup_state ) {" )
	&& ! str_contains( $email_source, '$cleanup_done' );
WP_CLI::line( 'ISOLATION_EMAIL_CLEANUP_STATE=' . ( $email_state_ok ? 'four-state|refusal-exits-nonzero' : 'UNSAFE' ) );

/* 9. The harness lock and the shared resources it protects. */
$harness = str_contains( $source, "SELECT GET_LOCK(%s, 0)" )
	&& str_contains( $source, 'HARNESS_LOCK=held-elsewhere' )
	&& strpos( $source, 'HARNESS_LOCK=acquired' ) < strpos( $source, 'file_put_contents(' )
	&& strpos( $source, 'HARNESS_LOCK=acquired' ) < strpos( $source, 'update_option( KUKA_IYZ_TEST_OPTION' );
$run_scoped_teardown = str_contains( $source, "hash_equals( \$run_id, (string) ( \$stored['run_id'] ?? '' ) )" )
	&& str_contains( $source, "str_contains( \$contents, '// run_id: ' . \$run_id )" );
WP_CLI::line( 'ISOLATION_HARNESS_LOCK=' . ( $harness ? 'before-shared-writes' : 'MISSING' ) . '|run_scoped_teardown:' . ( $run_scoped_teardown ? 'yes' : 'NO' ) );

/* 9b. One shutdown coordinator, cleanup strictly before the lock release. */
$coordinator      = strpos( $source, 'register_shutdown_function(' );
$coordinator_body = false === $coordinator ? '' : substr( $source, $coordinator );
$order_ok         = str_contains( $coordinator_body, '$cleanup();' )
	&& strpos( $coordinator_body, '$cleanup();' ) < strpos( $coordinator_body, "RELEASE_LOCK(%s)', \$harness_lock" );
$single_registrar = 1 === substr_count( $source, 'register_shutdown_function(' );
WP_CLI::line( 'ISOLATION_SHUTDOWN_ORDER=' . ( $order_ok ? 'cleanup-then-release' : 'UNSAFE' ) . '|single_coordinator:' . ( $single_registrar ? 'yes' : 'NO' ) . '|idempotent:' . ( str_contains( $source, 'kuka_iyzico_cleanup_enter( $cleanup_state )' ) ? 'yes' : 'NO' ) );

/* 9c. Lock unavailable and missing fixture must exit non-zero, silently. */
// A bare count would break on every new guard; the named guards are asserted.
$guards = array(
	'lock'         => 'HARNESS_LOCK=held-elsewhere',
	'stale'        => 'HARNESS_PREFLIGHT=stale-resource',
	'fixture'      => 'FIXTURE_PRODUCT=missing:',
	'mu_write'     => 'HARNESS_WRITE=mu-plugin-unverified',
	'option_write' => 'HARNESS_WRITE=option-unverified',
);
$guard_report = array();
foreach ( $guards as $name => $needle ) {
	$guard_report[] = $name . ':' . ( str_contains( $source, $needle ) ? 'yes' : 'NO' );
}
$guard_report[] = 'final:' . ( str_contains( $source, "if ( 0 !== \$failures || \$cleanup_refusals || 'succeeded' !== \$cleanup_state ) {" ) ? 'yes' : 'NO' );
$guard_report[] = 'created_by_test:' . ( str_contains( $source, 'new WC_Product_Simple()' ) ? 'YES' : 'no' );
WP_CLI::line( 'ISOLATION_FAIL_EXIT=' . implode( '|', $guard_report ) );

/* 10. Baseline must be compared as key sets, not counts. */
$keysets = str_contains( $source, 'kuka_iyzico_permanent_key_sets()' )
	&& str_contains( $source, 'PERMANENT_KEYSETS=' )
	&& str_contains( $source, 'Koşu öncesinde var olan hiçbir kayıt silinmedi' );
WP_CLI::line( 'ISOLATION_BASELINE=' . ( $keysets ? 'primary-key-sets' : 'COUNTS-ONLY' ) );

/* 11. Permanent-state fingerprint the run has to restore. */
$state = kuka_iyzico_permanent_state();
$parts = array();
foreach ( $state as $key => $value ) {
	$parts[] = $key . ':' . $value;
}
WP_CLI::line( 'ISOLATION_PERMANENT_STATE=' . implode( '|', $parts ) );
