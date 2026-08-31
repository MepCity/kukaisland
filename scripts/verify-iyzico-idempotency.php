<?php
/**
 * Read-only contract snapshot for the iyzico idempotency wrapper.
 *
 * The behavioural proof lives in scripts/test-iyzico-idempotency.php, which
 * issues real signed HTTP deliveries. This script only asserts the structural
 * contract that must survive a gateway update: where the guard lives, which
 * hooks it owns, that it claims atomically rather than through add_option(),
 * that no secret or signature can reach a log, and that the earlier
 * record-before-processing approach is gone.
 *
 * Nothing here writes to the database.
 */

defined( 'WP_CLI' ) || exit( 1 );

$guard = 'Kuka_Island_Core_Iyzico_Idempotency';
if ( ! class_exists( $guard ) ) {
	WP_CLI::line( 'IYZICO_GUARD=missing' );
	return;
}

$guard_file = WP_PLUGIN_DIR . '/kuka-island-core/includes/class-iyzico-idempotency.php';
$source     = file_exists( $guard_file ) ? (string) file_get_contents( $guard_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

WP_CLI::line( 'IYZICO_GUARD_LOCATION=' . ( '' !== $source ? 'core-plugin' : 'missing' ) );

/** Which priority, if any, the guard holds on a hook. */
$hooked = static function ( string $hook ) use ( $guard ): string {
	global $wp_filter;
	if ( ! isset( $wp_filter[ $hook ] ) ) {
		return 'absent';
	}
	foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$target = $callback['function'] ?? null;
			$object = is_array( $target ) ? ( $target[0] ?? null ) : null;
			$name   = is_object( $object ) ? get_class( $object ) : ( is_string( $object ) ? $object : '' );
			if ( $guard === $name ) {
				return (string) $priority;
			}
		}
	}
	return 'absent';
};

WP_CLI::line( 'IYZICO_GUARD_HOOKS=rest:' . $hooked( 'rest_pre_dispatch' ) . '|callback:' . $hooked( 'woocommerce_api_request' ) . '|probe:' . $hooked( 'template_redirect' ) );

/* The wrapper must guard exactly the flow this storefront uses. */
WP_CLI::line( 'IYZICO_GUARD_SCOPE=' . $guard::SUPPORTED_EVENT );

/* Every clause of the settlement contract is required, not merely one of them. */
$row = static fn( string $status, string $payment_status, string $payment_id ): array => array(
	'status'         => $status,
	'payment_status' => $payment_status,
	'payment_id'     => $payment_id,
);
$matrix = array(
	'both_success_same_id'  => array( $row( 'success', 'SUCCESS', '1' ), '1', true ),
	'success_failure'       => array( $row( 'success', 'FAILURE', '1' ), '1', false ),
	'failure_success'       => array( $row( 'failure', 'SUCCESS', '1' ), '1', false ),
	'empty_success'         => array( $row( '', 'SUCCESS', '1' ), '1', false ),
	'success_empty'         => array( $row( 'success', '', '1' ), '1', false ),
	'empty_stored_id'       => array( $row( 'success', 'SUCCESS', '' ), '1', false ),
	'empty_expected_id'     => array( $row( 'success', 'SUCCESS', '1' ), '', false ),
	'different_id'          => array( $row( 'success', 'SUCCESS', '1' ), '2', false ),
);
$results = array();
foreach ( $matrix as $name => $case ) {
	$results[] = $name . ':' . ( $guard::payment_is_settled( $case[0], $case[1] ) === $case[2] ? 'PASS' : 'FAIL' );
}
WP_CLI::line( 'IYZICO_SETTLED_MATRIX=' . implode( '|', $results ) );

/* The vendor mutation may only follow an authoritative API verification. */
$preflight = static function ( string $source ): string {
	$verify = strpos( $source, 'verify_payment_with_iyzico(' . "\n" );
	$verify = false === $verify ? strpos( $source, '$verification = self::verify_payment_with_iyzico(' ) : $verify;
	$vendor = strpos( $source, 'self::run_vendor_webhook(' );
	if ( false === $verify || false === $vendor ) {
		return 'unknown';
	}
	return $verify < $vendor ? 'before-vendor' : 'AFTER-VENDOR';
};
WP_CLI::line( 'IYZICO_PREFLIGHT=' . $preflight( $source ) . '|default:' . ( str_contains( $source, 'retrieve_checkout_form( $context )' ) ? 'live-api' : 'MISSING' ) . '|override:' . ( str_contains( $source, 'if ( ! self::test_context() ) {' ) ? 'test-only' : 'UNGATED' ) );

/* A concurrent return must not be shown a thank-you page, and the holding page
   must never fall back to a GET reload that would drop the payment token. */
$holding = str_contains( $source, 'self::render_in_progress_page( $order );' )
	&& str_contains( $source, 'status_header( 409 )' )
	&& ! str_contains( $source, 'http-equiv="refresh"' )
	&& str_contains( $source, "add_query_arg( 'kuka-iyzico-status', '1'" )
	&& str_contains( $source, '<form method="post"' );
WP_CLI::line( 'IYZICO_CALLBACK_INPROGRESS=' . ( $holding ? '409-holding-page|no-meta-refresh|post-retry' : 'UNSAFE' ) );

/* The status probe must never carry a payment token. */
$probe_start = strpos( $source, 'public function serve_status_probe' );
$probe_end   = strpos( $source, 'private static function render_in_progress_page' );
$probe_body  = false !== $probe_start && false !== $probe_end ? substr( $source, $probe_start, $probe_end - $probe_start ) : '';
// Only real code counts; the docblock is allowed to say the word "token".
$probe_code = '';
foreach ( token_get_all( '<?php ' . $probe_body ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}
	$probe_code .= is_array( $token ) ? $token[1] : $token;
}
WP_CLI::line( 'IYZICO_STATUS_PROBE=' . ( '' === $probe_body ? 'MISSING' : ( stripos( $probe_code, 'token' ) !== false ? 'TOKEN-EXPOSED' : 'token-free' ) ) . '|auth:' . ( str_contains( $probe_body, 'hash_equals( $order->get_order_key(), $key )' ) ? 'order-key' : 'NONE' ) );

/* Currency must fail closed. */
$currency_fail_closed = str_contains( $source, "if ( '' === \$currency || '' === \$expected_currency || \$currency !== \$expected_currency ) {" );
WP_CLI::line( 'IYZICO_CURRENCY=' . ( $currency_fail_closed ? 'fail-closed' : 'FAIL-OPEN' ) );

/*
 * A timed claim cannot bound a request that runs with max_execution_time = 0.
 * The lock must therefore be connection scoped, taken without waiting, and free
 * of any expiry rule that could cut in on a live vendor mutation.
 */
$primitive = str_contains( $source, 'SELECT GET_LOCK(%s, 0)' )
	&& str_contains( $source, 'SELECT RELEASE_LOCK(%s)' )
	&& str_contains( $source, 'SELECT IS_USED_LOCK(%s)' );
$expiry_rules = 0;
foreach ( array( 'CLAIM_TTL', 'handle_is_stale', 'age_claim', 'swap_claim' ) as $symbol ) {
	$expiry_rules += substr_count( $source, $symbol );
}
WP_CLI::line( 'IYZICO_LOCK_PRIMITIVE=' . ( $primitive ? 'advisory-lock' : 'NOT-ADVISORY' ) . '|expiry_rules:' . $expiry_rules . '|name_length:' . strlen( $guard::lock_name( 'sample' ) ) );

/* Signature and secret must never reach a log, a note or a response body. */
$leaks = preg_match( '/(error_log|Logger|->info|->error|->webhook|WP_CLI::line|add_order_note)[^;]*\$(signature|secret)/', $source );
WP_CLI::line( 'IYZICO_SECRET_LEAKS=' . (int) $leaks );

/* The rejected approach recorded the delivery before it was verified. */
$removed = ! str_contains( $source, '_kuka_iyzico_handled_events' )
	&& ! str_contains( $source, 'function is_repeat' )
	&& ! str_contains( $source, 'function remember' )
	&& ! method_exists( $guard, 'is_repeat' )
	&& ! method_exists( $guard, 'remember' );
WP_CLI::line( 'IYZICO_LEGACY_GUARD=' . ( $removed ? 'removed' : 'PRESENT' ) );

/* mark_processed() may only be reachable after the settlement check. */
$order_of_operations = static function ( string $source ): string {
	$steps = array(
		'signature' => strpos( $source, '! self::signature_is_valid(' ),
		'lock'      => strpos( $source, '! self::acquire_lock( $lock_key )' ),
		'preflight' => strpos( $source, '$verification = self::verify_payment_with_iyzico(' ),
		'vendor'    => strpos( $source, 'self::run_vendor_webhook(' ),
		'settled'   => strpos( $source, '! self::order_payment_confirmed( $order, $token, $payment_id )' ),
		// The already-settled branch records earlier on purpose; the success path
		// is the last one, and that is the one that must follow the checks.
		'record'    => strrpos( $source, "self::mark_processed( \$order, \$event_key, \$payment_id, 'webhook' );" ),
	);
	if ( in_array( false, $steps, true ) ) {
		return 'unknown';
	}
	$sorted = $steps;
	asort( $sorted );

	return array_keys( $steps ) === array_keys( $sorted )
		? 'signature<lock<preflight<vendor<settled<record'
		: 'OUT-OF-ORDER';
};
WP_CLI::line( 'IYZICO_GUARD_ORDER=' . $order_of_operations( $source ) );

/*
 * One lock per order, shared by both channels. A per-delivery lock would let a
 * webhook and a browser callback of the same payment run side by side.
 */
$webhook_start = strpos( $source, 'public function guard_webhook' );
$callback_start = strpos( $source, 'public function guard_callback' );
$probe_start2  = strpos( $source, 'public function serve_status_probe' );
$webhook_body  = substr( $source, $webhook_start, $callback_start - $webhook_start );
$callback_body = substr( $source, $callback_start, $probe_start2 - $callback_start );
$order_scoped  = str_contains( $webhook_body, 'self::acquire_lock( $lock_key )' )
	&& str_contains( $webhook_body, '$lock_key = self::payment_lock_key( $order_id );' )
	&& str_contains( $callback_body, 'self::acquire_lock( $lock_key )' )
	&& str_contains( $callback_body, '$lock_key  = self::payment_lock_key( $order_id );' );
$recheck = str_contains( $webhook_body, '// Everything read before the lock may be stale' )
	&& str_contains( $callback_body, '// Whatever was read before the lock may be stale' );
WP_CLI::line( 'IYZICO_LOCK_SCOPE=' . ( $order_scoped ? 'order-level' : 'PER-DELIVERY' ) . '|recheck_after_lock:' . ( $recheck ? 'yes' : 'NO' ) . '|shared_name:' . ( $guard::lock_name( $guard::payment_lock_key( 4242 ) ) === $guard::lock_name( 'payment:4242' ) ? 'stable' : 'UNSTABLE' ) );

/* The timed claim implementation must be gone, names and comments included. */
$legacy_symbols = 0;
foreach ( array( 'CLAIM_PREFIX', 'CLEANUP_HOOK', 'schedule_cleanup', 'sweep_claims', 'claim_count', 'add_option' ) as $symbol ) {
	$legacy_symbols += substr_count( $source, $symbol );
}
WP_CLI::line( 'IYZICO_LEGACY_CLAIM=' . ( 0 === $legacy_symbols ? 'removed' : 'PRESENT:' . $legacy_symbols ) . '|daily_cron:' . ( wp_next_scheduled( 'kuka_island_iyzico_claim_cleanup' ) ? 'SCHEDULED' : 'none' ) );

/* A cancelled order is not a paid one; treating it as settled would swallow a retry. */
WP_CLI::line( 'IYZICO_CANCELLED_NOT_PAID=' . ( preg_match( "/PAID_STATUSES\s*=\s*array\([^)]*'cancelled'/", $source ) ? 'NO' : 'yes' ) );
