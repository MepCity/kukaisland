<?php
/**
 * Integration test for the iyzico webhook/callback idempotency wrapper.
 *
 * Real signed HTTP deliveries against the live REST route and the live return
 * callback, on orders this script creates itself. Catalog products are never
 * used as fixtures and never mutated: a dedicated private fixture product
 * carries all test stock, and the catalog is hashed before and after.
 *
 * Run with:
 * docker compose run --rm -T wp-cli wp eval-file /project-scripts/test-iyzico-idempotency.php
 *
 * Signatures, secrets, tokens and order keys are handled in memory and never
 * printed.
 */

defined( 'WP_CLI' ) || exit( 1 );

global $wpdb;

require_once __DIR__ . '/lib-iyzico-test-ownership.php';

const KUKA_IYZ_FIXTURE_SKU   = 'KUKA-SANDBOX-IYZ-FIXTURE';
const KUKA_IYZ_FIXTURE_STOCK = 500;
const KUKA_IYZ_TEST_OPTION   = 'kuka_iyz_test_verification';

/*
 * An isolated throwaway database was considered first and rejected: the test
 * drives the live REST route and the live return callback over HTTP, so it has
 * to run against the WordPress this stack serves. Isolation is therefore built
 * on ownership instead — every record this run creates is stamped with a
 * per-run UUID, its id is remembered at creation time, and the cleanup removes
 * a record only when id, run UUID and fixture marker all match.
 */
$run_id = wp_generate_uuid4();
if ( ! kuka_iyzico_is_uuid( $run_id ) ) {
	WP_CLI::error( 'run id is not a UUID' );
}

/*
 * Harness lock. The shared test option and the shared MU-plugin file are single
 * global resources: two runs writing them at once would corrupt each other and
 * one would delete the other's file. The lock is taken before either is touched
 * and released on the way out; a second run finds it held and stops without
 * changing anything at all.
 */
$harness_lock = 'kuka_iyz_harness_' . sha1( 'integration-test' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$harness_taken = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $harness_lock ) );
if ( ! $harness_taken ) {
	WP_CLI::line( 'HARNESS_LOCK=held-elsewhere' );
	WP_CLI::line( 'IYZICO_INTEGRATION=FAIL (harness lock unavailable; nothing was changed)' );
	// A printed line is not a result: exit non-zero so a caller can act on it.
	// Nothing shared has been written at this point.
	WP_CLI::halt( 1 );
}
WP_CLI::line( 'HARNESS_LOCK=acquired' );

$created_orders        = array();
$created_provider_rows = array();
$customer_candidates   = array();
$fixture_email         = 'sandbox-idempotency+' . substr( $run_id, 0, 8 ) . '@example.com';
$permanent_before      = kuka_iyzico_permanent_key_sets();
$customer_keyset_before = $permanent_before['wc_customer_lookup'];

$guard = 'Kuka_Island_Core_Iyzico_Idempotency';
if ( ! class_exists( $guard ) ) {
	WP_CLI::error( 'Kuka_Island_Core_Iyzico_Idempotency missing' );
}

$provider_table = $wpdb->prefix . 'iyzico_order';
$settings       = get_option( 'woocommerce_iyzico_settings', array() );
$secret         = (string) ( $settings['secret_key'] ?? '' );
if ( '' === $secret ) {
	WP_CLI::error( 'iyzico secret key is not configured' );
}
$webhook_path = '/wp-json/iyzico/v1/webhook/' . get_option( 'iyzicoWebhookUrlKey' );

$failures = 0;
$check    = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	WP_CLI::line( sprintf( '%s %s%s', $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? ' | ' . $detail : '' ) );
	if ( ! $ok ) {
		++$failures;
	}
};

/* --------------------------------------------------------------------- */
/* Catalog protection                                                     */
/* --------------------------------------------------------------------- */

$catalog_fingerprint = static function ( array $exclude ) use ( $wpdb ): array {
	$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') ORDER BY ID ASC" );
	$parts = array();
	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( in_array( $id, $exclude, true ) ) {
			continue;
		}
		$product = wc_get_product( $id );
		if ( ! $product ) {
			continue;
		}
		$parts[] = implode(
			':',
			array(
				$id,
				var_export( $product->get_stock_quantity(), true ),
				$product->get_stock_status(),
				var_export( $product->get_manage_stock(), true ),
				(string) $product->get_backorders(),
			)
		);
	}
	return array( 'hash' => sha1( implode( '|', $parts ) ), 'count' => count( $parts ) );
};

/** Exact stock contract of one product, so it can be put back verbatim. */
$stock_snapshot = static function ( int $product_id ): array {
	$product = wc_get_product( $product_id );
	return array(
		'quantity'     => $product->get_stock_quantity(),
		'stock_status' => $product->get_stock_status(),
		'manage_stock' => $product->get_manage_stock(),
		'backorders'   => $product->get_backorders(),
	);
};

$stock_restore = static function ( int $product_id, array $snapshot ): void {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return;
	}
	$product->set_manage_stock( $snapshot['manage_stock'] );
	$product->set_backorders( $snapshot['backorders'] );
	$product->set_stock_quantity( $snapshot['quantity'] );
	$product->set_stock_status( $snapshot['stock_status'] );
	$product->save();
};

/*
 * The fixture product belongs to install/seed, not to the test.
 *
 * A test that creates it would either leave a permanent catalog record behind
 * or have to delete a product on every run. scripts/seed.php creates it once,
 * privately and idempotently; if it is missing the run stops here without
 * writing anything. The SKU is overridable so the missing-fixture path itself
 * can be exercised.
 */
$fixture_sku = (string) ( getenv( 'KUKA_IYZ_FIXTURE_SKU' ) ?: KUKA_IYZ_FIXTURE_SKU );
$fixture_id  = (int) wc_get_product_id_by_sku( $fixture_sku );
if ( $fixture_id <= 0 ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $harness_lock ) );
	WP_CLI::line( 'FIXTURE_PRODUCT=missing:' . $fixture_sku );
	WP_CLI::line( 'IYZICO_INTEGRATION=FAIL (fixture product missing; run `make seed`. Nothing was changed.)' );
	WP_CLI::halt( 1 );
}

$catalog_before = $catalog_fingerprint( array( $fixture_id ) );
$fixture_before = $stock_snapshot( $fixture_id );

$mu_dir  = WP_CONTENT_DIR . '/mu-plugins';
$mu_file = $mu_dir . '/kuka-iyzico-test-double.php';

/*
 * Shared-resource preflight.
 *
 * The test double file and the verification option are fixed, shared names. If
 * either already exists the previous run did not finish, and overwriting it
 * would destroy the evidence of that. Nothing is written, nothing is deleted,
 * and the run stops for a human to look — a stale resource is never cleaned up
 * automatically.
 */
$stale = array();
if ( file_exists( $mu_file ) ) {
	$stale[] = 'mu-plugin';
}
if ( null !== get_option( KUKA_IYZ_TEST_OPTION, null ) ) {
	$stale[] = 'option';
}
if ( $stale ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $harness_lock ) );
	// Only the kind of resource is reported; its contents are never printed.
	WP_CLI::line( 'HARNESS_PREFLIGHT=stale-resource|' . implode( ',', $stale ) );
	WP_CLI::line( 'IYZICO_INTEGRATION=FAIL (stale shared resource; inspect it before rerunning. Nothing was changed.)' );
	WP_CLI::halt( 1 );
}
WP_CLI::line( 'HARNESS_PREFLIGHT=clean' );

/* The fixture product and the test double must go back however this ends. */
$cleanup_refusals = array();

/*
 * Explicit teardown state instead of two booleans.
 *
 *   idle      — not attempted yet
 *   running   — in progress; re-entry must not start a second pass
 *   succeeded — every target removed and no refusal recorded
 *   failed    — at least one target refused, or a pass was interrupted
 *
 * `failed` is never treated as quiet success: it keeps the run's exit code
 * non-zero and stops any further deletion attempt.
 */
$cleanup_state = 'idle';

/**
 * Remove exactly what this run created, and refuse anything else.
 *
 * Every candidate goes through the shared ownership predicate: its id must be
 * one this run recorded at creation time, its run UUID must match this run, and
 * it must still carry the fixture marker. A candidate that fails any of the
 * three is left untouched and reported; the run then fails rather than reaching
 * for a broader delete.
 */
$cleanup = static function () use (
	$fixture_id,
	$fixture_before,
	$stock_restore,
	$mu_file,
	$run_id,
	$fixture_email,
	$harness_lock,
	&$created_orders,
	&$created_provider_rows,
	&$customer_candidates,
	&$customer_keyset_before,
	&$cleanup_refusals,
	&$cleanup_state
): void {
	// Only an untouched teardown may start. A second entry while running, or
	// after a failure, must not try to delete anything again.
	$entry         = kuka_iyzico_cleanup_enter( $cleanup_state );
	$cleanup_state = $entry['state'];
	if ( '' !== $entry['refusal'] ) {
		$cleanup_refusals[] = $entry['refusal'];
	}
	if ( ! $entry['proceed'] ) {
		return;
	}
	global $wpdb;
	$provider_table = $wpdb->prefix . 'iyzico_order';
	$customer_table = $wpdb->prefix . 'wc_customer_lookup';

	/* --- Gateway rows: id + order + token must all still match. --- */
	foreach ( $created_provider_rows as $record ) {
		$row_id   = (int) ( $record['row_id'] ?? 0 );
		$order_id = (int) ( $record['order_id'] ?? 0 );
		$token    = (string) ( $record['token'] ?? '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT iyzico_order_id, order_id, token FROM {$provider_table} WHERE iyzico_order_id = %d", $row_id ), ARRAY_A );
		$verdict = kuka_iyzico_provider_row_verdict( is_array( $row ) ? $row : null, $row_id, $order_id, $token );
		if ( ! $verdict['owned'] ) {
			$cleanup_refusals[] = 'provider#' . $row_id . ':' . $verdict['reason'];
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $provider_table, array( 'iyzico_order_id' => $row_id ), array( '%d' ) );
		if ( 1 !== (int) $wpdb->rows_affected ) {
			$cleanup_refusals[] = 'provider#' . $row_id . ':affected_rows_' . (int) $wpdb->rows_affected;
		}
	}

	/* --- Analytics customer rows: recorded candidates only, never e-mail alone. --- */
	// Candidates are recorded here, after the analytics import has had a chance
	// to run, but a row still has to clear every ownership clause below.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT customer_id FROM {$customer_table} WHERE email = %s", $fixture_email ) ) ) as $seen ) {
		if ( ! in_array( $seen, $customer_candidates, true ) ) {
			$customer_candidates[] = $seen;
		}
	}
	foreach ( $customer_candidates as $customer_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT customer_id, user_id, email FROM {$customer_table} WHERE customer_id = %d", $customer_id ), ARRAY_A );
		// Every order still pointing at this row must be one this run created.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$linked = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wc_order_stats WHERE customer_id = %d", $customer_id ) ) );
		$verdict = kuka_iyzico_customer_row_verdict(
			is_array( $row ) ? $row : null,
			(int) $customer_id,
			$fixture_email,
			$linked,
			$created_orders,
			$customer_candidates,
			$customer_keyset_before
		);
		if ( ! $verdict['owned'] ) {
			$cleanup_refusals[] = 'customer#' . $customer_id . ':' . $verdict['reason'];
			continue;
		}
		// Set membership is a claim about the past. Re-read every linked order
		// now and require full ownership on the live record before removing the
		// analytics row it belongs to.
		$linked_refusals = array();
		if ( ! kuka_iyzico_linked_orders_owned( $linked, $run_id, $created_orders, $linked_refusals ) ) {
			foreach ( $linked_refusals as $refusal ) {
				$cleanup_refusals[] = 'customer#' . $customer_id . ':' . $refusal;
			}
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $customer_table, array( 'customer_id' => $customer_id ), array( '%d' ) );
		if ( 1 !== (int) $wpdb->rows_affected ) {
			$cleanup_refusals[] = 'customer#' . $customer_id . ':affected_rows_' . (int) $wpdb->rows_affected;
		}
	}

	/* --- Orders: id, run UUID and fixture marker must all match. --- */
	foreach ( array_unique( array_map( 'intval', $created_orders ) ) as $order_id ) {
		$reason = '';
		if ( ! kuka_iyzico_fixture_is_owned( $order_id, $run_id, $created_orders, $reason ) ) {
			$cleanup_refusals[] = 'order#' . $order_id . ':' . $reason;
			continue;
		}
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			// HPOS keeps order notes in the comments table and delete() leaves
			// them behind, so each note is removed by its own id first.
			foreach ( wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => 500 ) ) as $note ) {
				wc_delete_order_note( (int) $note->id );
			}
			$order->delete( true );
		}
		// Analytics rows are removed by exact order id, never by a pattern.
		foreach ( array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_coupon_lookup', 'wc_order_tax_lookup' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->prefix . $table, array( 'order_id' => $order_id ), array( '%d' ) );
		}
	}

	/* --- Shared resources: only removed when they carry this run's id. --- */
	$stored = get_option( KUKA_IYZ_TEST_OPTION, null );
	if ( is_array( $stored ) ) {
		if ( hash_equals( $run_id, (string) ( $stored['run_id'] ?? '' ) ) ) {
			delete_option( KUKA_IYZ_TEST_OPTION );
		} else {
			$cleanup_refusals[] = 'option:run_id_mismatch';
		}
	}
	if ( file_exists( $mu_file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = (string) file_get_contents( $mu_file );
		if ( str_contains( $contents, '// run_id: ' . $run_id ) ) {
			wp_delete_file( $mu_file );
		} else {
			$cleanup_refusals[] = 'mu_plugin:run_id_mismatch';
		}
	}

	$stock_restore( $fixture_id, $fixture_before );
	// Success is conditional on there being nothing refused anywhere above.
	$cleanup_state = kuka_iyzico_cleanup_finish( $cleanup_refusals );

	// Behavioural proof of the shutdown order: while the cleanup is still
	// running a second connection must not be able to take the harness lock.
	$observer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$taken    = $observer->get_var( $observer->prepare( 'SELECT GET_LOCK(%s, 0)', $harness_lock ) );
	if ( '1' === (string) $taken ) {
		$observer->get_var( $observer->prepare( 'SELECT RELEASE_LOCK(%s)', $harness_lock ) );
	}
	$observer->close();
	WP_CLI::line( 'SHUTDOWN_ORDER=lock_held_during_cleanup:' . ( '1' === (string) $taken ? 'NO' : 'yes' ) );
};
/*
 * One coordinator, one order: cleanup first, the harness lock only afterwards.
 *
 * Registering the lock release separately (and earlier) would let a fatal error
 * drop the lock while the cleanup was still running, so a second run could walk
 * into a half-torn-down state. The coordinator is idempotent, so the normal
 * flow calling $cleanup() itself costs nothing here.
 */
register_shutdown_function(
	static function () use ( $cleanup, $wpdb, $harness_lock, &$cleanup_state, &$cleanup_refusals ): void {
		// idle      → run the teardown
		// running   → do not re-enter; the pass never finished, so it failed
		// failed    → never attempt a second, broader deletion
		// succeeded → nothing left to remove
		$cleanup();
		// The lock is released only after the teardown has been evaluated, in
		// every one of those states.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $harness_lock ) );
		WP_CLI::line( 'CLEANUP_STATE=' . $cleanup_state );
		if ( 'succeeded' !== $cleanup_state ) {
			WP_CLI::line( 'CLEANUP_REFUSED=' . ( $cleanup_refusals ? implode( ' | ', $cleanup_refusals ) : 'state:' . $cleanup_state ) );
			exit( 1 );
		}
	}
);

/*
 * Narrow test double. It exists only while this script runs: the file defines
 * the test-mode constant the guard requires, so a production install — which
 * has no such file — always reaches the real iyzico API.
 */
if ( ! is_dir( $mu_dir ) ) {
	wp_mkdir_p( $mu_dir );
}
// Both shared resources carry this run's id, so the teardown can prove the file
// and the option it removes are the ones this run wrote.
file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents
	$mu_file,
	"<?php\n"
	. "// GEÇİCİ TEST DOSYASI. scripts/test-iyzico-idempotency.php oluşturur ve siler.\n"
	. "// run_id: " . $run_id . "\n"
	. "defined( 'ABSPATH' ) || exit;\n"
	. "define( 'KUKA_ISLAND_IYZICO_TEST_MODE', true );\n"
	. "add_filter( 'kuka_island_iyzico_payment_verification', function ( \$override, \$context ) {\n"
	. "\t\$stored = get_option( '" . KUKA_IYZ_TEST_OPTION . "', array() );\n"
	. "\t\$map = is_array( \$stored['tokens'] ?? null ) ? \$stored['tokens'] : array();\n"
	. "\t\$token = (string) ( \$context['token'] ?? '' );\n"
	. "\treturn isset( \$map[ \$token ] ) && is_array( \$map[ \$token ] ) ? \$map[ \$token ] : \$override;\n"
	. "}, 10, 2 );\n"
);

/*
 * A write that silently failed would leave the run testing nothing, so the file
 * is read back and must carry this run's id before anything else happens. On
 * failure only a file that provably belongs to this run is removed.
 */
$mu_written = file_exists( $mu_file )
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	&& str_contains( (string) file_get_contents( $mu_file ), '// run_id: ' . $run_id );
if ( ! $mu_written ) {
	if ( file_exists( $mu_file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = (string) file_get_contents( $mu_file );
		if ( str_contains( $contents, '// run_id: ' . $run_id ) ) {
			wp_delete_file( $mu_file );
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $harness_lock ) );
	WP_CLI::line( 'HARNESS_WRITE=mu-plugin-unverified' );
	WP_CLI::line( 'IYZICO_INTEGRATION=FAIL (test double could not be written)' );
	WP_CLI::halt( 1 );
}

$set_double = static function ( string $token, ?array $result ) use ( $run_id ): void {
	$stored = get_option( KUKA_IYZ_TEST_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$map    = is_array( $stored['tokens'] ?? null ) ? $stored['tokens'] : array();
	if ( null === $result ) {
		unset( $map[ $token ] );
	} else {
		$map[ $token ] = $result;
	}
	update_option( KUKA_IYZ_TEST_OPTION, array( 'run_id' => $run_id, 'tokens' => $map ), false );
	// Read back: an option write that did not land would make every verified
	// scenario silently fall through to the real API.
	$verify = get_option( KUKA_IYZ_TEST_OPTION, null );
	if ( ! is_array( $verify ) || ! hash_equals( $run_id, (string) ( $verify['run_id'] ?? '' ) ) ) {
		WP_CLI::line( 'HARNESS_WRITE=option-unverified' );
		WP_CLI::line( 'IYZICO_INTEGRATION=FAIL (verification option could not be written)' );
		WP_CLI::halt( 1 );
	}
};

// Prove both shared resources are in place and owned before the first request.
$set_double( 'harness-preflight-probe', null );
WP_CLI::line( 'HARNESS_WRITE=verified' );

/* --------------------------------------------------------------------- */
/* HTTP helpers                                                           */
/* --------------------------------------------------------------------- */

$sign = static function ( array $body ) use ( $secret ): string {
	$material = $secret . $body['iyziEventType'] . $body['iyziPaymentId'] . $body['token'] . $body['paymentConversationId'] . $body['status'];
	return bin2hex( hash_hmac( 'sha256', $material, $secret, true ) );
};

$post_webhook = static function ( array $body, ?string $signature ) use ( $webhook_path ): array {
	$response = wp_remote_post(
		'http://wordpress' . $webhook_path,
		array(
			'timeout' => 45,
			'headers' => array_filter(
				array(
					'Host'               => 'localhost:8080',
					'Content-Type'       => 'application/json',
					'X-IYZ-SIGNATURE-V3' => $signature,
				)
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 'code' => 0, 'body' => $response->get_error_message() );
	}
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => trim( (string) wp_remote_retrieve_body( $response ) ),
	);
};

$post_webhook_concurrent = static function ( array $body, string $signature, int $count ) use ( $webhook_path ): array {
	$multi   = curl_multi_init();
	$handles = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$handle = curl_init();
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL            => 'http://wordpress' . $webhook_path,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 45,
				CURLOPT_HTTPHEADER     => array(
					'Host: localhost:8080',
					'Content-Type: application/json',
					'X-IYZ-SIGNATURE-V3: ' . $signature,
				),
			)
		);
		curl_multi_add_handle( $multi, $handle );
		$handles[] = $handle;
	}
	$running = null;
	do {
		curl_multi_exec( $multi, $running );
		curl_multi_select( $multi, 1.0 );
	} while ( $running > 0 );

	$results = array();
	foreach ( $handles as $handle ) {
		$results[] = array(
			'code' => (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ),
			'body' => trim( (string) curl_multi_getcontent( $handle ) ),
		);
		curl_multi_remove_handle( $multi, $handle );
		curl_close( $handle );
	}
	curl_multi_close( $multi );

	return $results;
};

/** Fire heterogeneous POSTs at the same instant, each in its own PHP process. */
$post_concurrent = static function ( array $requests ): array {
	$multi   = curl_multi_init();
	$handles = array();
	foreach ( $requests as $request ) {
		$handle = curl_init();
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL            => 'http://wordpress' . $request['path'],
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $request['body'],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 45,
				CURLOPT_HTTPHEADER     => array_merge( array( 'Host: localhost:8080' ), $request['headers'] ),
			)
		);
		curl_multi_add_handle( $multi, $handle );
		$handles[] = $handle;
	}
	$running = null;
	do {
		curl_multi_exec( $multi, $running );
		curl_multi_select( $multi, 1.0 );
	} while ( $running > 0 );

	$results = array();
	foreach ( $handles as $index => $handle ) {
		$raw         = (string) curl_multi_getcontent( $handle );
		$header_size = (int) curl_getinfo( $handle, CURLINFO_HEADER_SIZE );
		$results[]   = array(
			'label'   => (string) ( $requests[ $index ]['label'] ?? (string) $index ),
			'code'    => (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ),
			'headers' => substr( $raw, 0, $header_size ),
			'body'    => trim( substr( $raw, $header_size ) ),
		);
		curl_multi_remove_handle( $multi, $handle );
		curl_close( $handle );
	}
	curl_multi_close( $multi );

	return $results;
};

$callback_path = static function ( int $order_id ): string {
	wp_cache_flush();
	$order = wc_get_order( $order_id );
	$url   = add_query_arg( 'wc-api', 'iyzipay', $order->get_checkout_order_received_url() );
	return (string) wp_parse_url( $url, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $url, PHP_URL_QUERY );
};

$post_callback = static function ( int $order_id, string $token ): array {
	wp_cache_flush();
	$order = wc_get_order( $order_id );
	$url   = add_query_arg( 'wc-api', 'iyzipay', $order->get_checkout_order_received_url() );
	$path  = (string) wp_parse_url( $url, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $url, PHP_URL_QUERY );
	$response = wp_remote_post(
		'http://wordpress' . $path,
		array(
			'timeout'     => 45,
			'redirection' => 0,
			'headers'     => array( 'Host' => 'localhost:8080' ),
			'body'        => array( 'token' => $token ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 'code' => 0, 'location' => '', 'body' => $response->get_error_message() );
	}
	return array(
		'code'        => (int) wp_remote_retrieve_response_code( $response ),
		'location'    => (string) wp_remote_retrieve_header( $response, 'location' ),
		'retry_after' => (string) wp_remote_retrieve_header( $response, 'retry-after' ),
		'body'        => (string) wp_remote_retrieve_body( $response ),
	);
};

$probe_url = static function ( int $order_id ) : string {
	wp_cache_flush();
	$order = wc_get_order( $order_id );
	$url   = add_query_arg( 'kuka-iyzico-status', '1', $order->get_checkout_order_received_url() );
	return (string) wp_parse_url( $url, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $url, PHP_URL_QUERY );
};

$call_probe = static function ( int $order_id, string $method ) use ( $probe_url ): array {
	$args = array(
		'timeout'     => 30,
		'redirection' => 0,
		'headers'     => array( 'Host' => 'localhost:8080', 'Accept' => 'application/json' ),
	);
	$url = 'http://wordpress' . $probe_url( $order_id );
	$response = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
	if ( is_wp_error( $response ) ) {
		return array( 'code' => 0, 'body' => $response->get_error_message(), 'location' => '' );
	}
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'body'     => trim( (string) wp_remote_retrieve_body( $response ) ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
	);
};

/* --------------------------------------------------------------------- */
/* Measurement                                                            */
/* --------------------------------------------------------------------- */

$snapshot = static function ( int $order_id, string $token ) use ( $wpdb, $provider_table, $guard, $fixture_id ): array {
	// The HTTP requests run in other PHP processes; without dropping this
	// process' object cache every measurement would report pre-request state.
	wp_cache_flush();
	$order   = wc_get_order( $order_id );
	$product = wc_get_product( $fixture_id );
	$notes   = wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => 500 ) );
	$confirm = 0;
	$audit   = 0;
	foreach ( $notes as $note ) {
		$text = wp_strip_all_tags( $note->content );
		if ( str_contains( $text, 'web kancası aracılığıyla onaylandı' ) || str_contains( $text, 'confirmed via webhook' ) ) {
			++$confirm;
		}
		if ( str_contains( $text, 'Aynı iyzico bildirimi tekrar geldi' ) ) {
			++$audit;
		}
	}
	$fee_total = 0.0;
	$fees      = $order->get_items( 'fee' );
	foreach ( $fees as $fee ) {
		$fee_total += (float) $fee->get_total();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT payment_id, status, payment_status FROM {$provider_table} WHERE token = %s", $token ), ARRAY_A );

	return array(
		'status'          => $order->get_status(),
		'total'           => (float) $order->get_total(),
		'stock'           => (int) $product->get_stock_quantity(),
		'fee_count'       => count( $fees ),
		'fee_total'       => round( $fee_total, 2 ),
		'confirm_notes'   => $confirm,
		'audit_notes'     => $audit,
		'note_count'      => count( $notes ),
		'provider_pid'    => (string) ( $row['payment_id'] ?? '' ),
		'provider_status' => (string) ( $row['status'] ?? '' ) . '/' . (string) ( $row['payment_status'] ?? '' ),
		'processed'       => count( $guard::processed_events( $order ) ),
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		'claims'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'kuka_iyz_claim_%'" ),
		'lock'            => $guard::lock_is_held( $guard::payment_lock_key( $order_id ) ) ? 1 : 0,
	);
};

$render = static function ( array $before, array $after ): string {
	$parts = array();
	foreach ( $before as $key => $value ) {
		$left  = is_float( $value ) ? number_format( $value, 2, '.', '' ) : $value;
		$right = is_float( $after[ $key ] ) ? number_format( $after[ $key ], 2, '.', '' ) : $after[ $key ];
		$parts[] = $key . ':' . $left . '→' . $right;
	}
	return implode( ' ', $parts );
};

$no_side_effects = static function ( array $before, array $after ): bool {
	foreach ( array( 'status', 'total', 'stock', 'fee_count', 'fee_total', 'confirm_notes', 'audit_notes', 'note_count', 'provider_pid', 'provider_status', 'processed' ) as $key ) {
		if ( $before[ $key ] !== $after[ $key ] ) {
			return false;
		}
	}
	return true;
};

$make_fixture = static function ( string $case, string $provider_status, ?string $payment_status, ?string $payment_id ) use ( $wpdb, $provider_table, $fixture_id, $run_id, $fixture_email, &$created_orders, &$created_provider_rows ): array {
	$order = wc_create_order();
	$order->add_product( wc_get_product( $fixture_id ), 1 );
	$order->set_billing_first_name( 'Sandbox' );
	$order->set_billing_last_name( 'Idempotency' );
	$order->set_billing_email( $fixture_email );
	$order->set_billing_country( 'TR' );
	$order->set_payment_method( 'iyzico' );
	$order->calculate_totals();
	$order->update_meta_data( KUKA_IYZ_FIXTURE_META, KUKA_IYZ_FIXTURE_MARKER );
	$order->update_meta_data( KUKA_IYZ_RUN_META, $run_id );
	$order->update_meta_data( '_kuka_sandbox_case', $case );
	$order->set_status( 'pending' );
	$order->save();
	// Remembered at creation time; the cleanup never discovers targets by query.
	$created_orders[] = (int) $order->get_id();
	$order->add_order_note( 'SANDBOX TEST SİPARİŞİ — iyzico idempotency entegrasyon testi (' . $case . '). Gerçek ödeme değildir.', 0, false );

	$token = wp_generate_uuid4();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->insert(
		$provider_table,
		array(
			'payment_id'      => $payment_id,
			'order_id'        => $order->get_id(),
			'conversation_id' => 'sandbox-' . $order->get_id(),
			'token'           => $token,
			'total_amount'    => $order->get_total(),
			'status'          => $provider_status,
			'payment_status'  => $payment_status,
		),
		array( '%s', '%d', '%s', '%s', '%f', '%s', '%s' )
	);
	$created_provider_rows[] = array(
		'row_id'   => (int) $wpdb->insert_id,
		'order_id' => (int) $order->get_id(),
		'token'    => $token,
	);

	return array(
		'order_id'        => $order->get_id(),
		'token'           => $token,
		'conversation_id' => 'sandbox-' . $order->get_id(),
		'total'           => (float) $order->get_total(),
	);
};

$body_for = static function ( array $fixture, string $event, string $status, string $payment_id ): array {
	return array(
		'iyziEventType'         => $event,
		'iyziPaymentId'         => $payment_id,
		'token'                 => $fixture['token'],
		'paymentConversationId' => $fixture['conversation_id'],
		'status'                => $status,
	);
};

/** Authoritative-API answer the double returns for a genuinely paid form. */
$verified_result = static function ( array $fixture, string $payment_id, array $overrides = array() ): array {
	return array_merge(
		array(
			'status'          => 'success',
			'token'           => $fixture['token'],
			'conversation_id' => $fixture['conversation_id'],
			'basket_id'       => (string) $fixture['order_id'],
			'payment_status'  => 'SUCCESS',
			'payment_id'      => $payment_id,
			'paid_price'      => number_format( $fixture['total'], 2, '.', '' ),
			'currency'        => 'TRY',
		),
		$overrides
	);
};

WP_CLI::line( '=== iyzico idempotency entegrasyon testi ===' );
WP_CLI::line( sprintf( 'FIXTURE_PRODUCT=%d|stock:%s', $fixture_id, var_export( $fixture_before['quantity'], true ) ) );
WP_CLI::line( sprintf( 'CATALOG_BEFORE=%s|products:%d', $catalog_before['hash'], $catalog_before['count'] ) );
WP_CLI::line( 'PROTECTED_ORDERS=' . implode( ',', KUKA_IYZ_PROTECTED_ORDERS ) );
$protected_before = array();
foreach ( KUKA_IYZ_PROTECTED_ORDERS as $id ) {
	$order                   = wc_get_order( $id );
	$protected_before[ $id ] = $order ? $order->get_status() . '/' . $order->get_total() . '/' . count( wc_get_order_notes( array( 'order_id' => $id, 'limit' => 500 ) ) ) : 'missing';
}

/* -- A: payment_is_settled() sözleşmesi, sekiz kombinasyon --------------- */

$settled_cases = array(
	'SUCCESS/SUCCESS + eşit ID'      => array( array( 'status' => 'success', 'payment_status' => 'SUCCESS', 'payment_id' => '900001' ), '900001', true ),
	'SUCCESS/FAILURE'                => array( array( 'status' => 'success', 'payment_status' => 'FAILURE', 'payment_id' => '900001' ), '900001', false ),
	'FAILURE/SUCCESS'                => array( array( 'status' => 'failure', 'payment_status' => 'SUCCESS', 'payment_id' => '900001' ), '900001', false ),
	'boş/SUCCESS'                    => array( array( 'status' => '', 'payment_status' => 'SUCCESS', 'payment_id' => '900001' ), '900001', false ),
	'SUCCESS/boş'                    => array( array( 'status' => 'success', 'payment_status' => '', 'payment_id' => '900001' ), '900001', false ),
	'iki SUCCESS + boş stored ID'    => array( array( 'status' => 'success', 'payment_status' => 'SUCCESS', 'payment_id' => '' ), '900001', false ),
	'iki SUCCESS + boş expected ID'  => array( array( 'status' => 'success', 'payment_status' => 'SUCCESS', 'payment_id' => '900001' ), '', false ),
	'iki SUCCESS + farklı ID'        => array( array( 'status' => 'success', 'payment_status' => 'SUCCESS', 'payment_id' => '900001' ), '900002', false ),
);
$settled_report = array();
foreach ( $settled_cases as $label => $case ) {
	[ $row, $expected_id, $want ] = $case;
	$got              = $guard::payment_is_settled( $row, $expected_id );
	$settled_report[] = $label . '=' . var_export( $got, true );
	$check( 'A. payment_is_settled — ' . $label, $got === $want, 'beklenen ' . var_export( $want, true ) );
}
WP_CLI::line( 'SETTLED_MATRIX=' . implode( ' | ', $settled_report ) );

/* -- 1, 2, 7: ilk teslimat, 10 tekrar, farklı payment id ----------------- */

$case1 = $make_fixture( 'ilk-teslimat', 'success', null, null );
$body1 = $body_for( $case1, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000001' );
$sig1  = $sign( $body1 );
$set_double( $case1['token'], $verified_result( $case1, '700000001' ) );

$before = $snapshot( $case1['order_id'], $case1['token'] );
$r      = $post_webhook( $body1, $sig1 );
$after  = $snapshot( $case1['order_id'], $case1['token'] );
WP_CLI::line( sprintf( 'CASE1 order=%d http=%d body=%s %s', $case1['order_id'], $r['code'], $r['body'], $render( $before, $after ) ) );
$check( '1. geçerli imza + doğrulanmış ödeme işlenir', 200 === $r['code'] && str_contains( $r['body'], '"processed"' ) && 'processing' === $after['status'] && 1 === $after['confirm_notes'] && 1 === $after['processed'] && $after['stock'] === $before['stock'] - 1 && '700000001' === $after['provider_pid'] && 'success/SUCCESS' === $after['provider_status'] && 0 === $after['claims'] );

$before = $snapshot( $case1['order_id'], $case1['token'] );
$codes  = array();
$bodies = array();
for ( $i = 0; $i < 10; $i++ ) {
	$repeat   = $post_webhook( $body1, $sig1 );
	$codes[]  = $repeat['code'];
	$bodies[] = $repeat['body'];
}
$after = $snapshot( $case1['order_id'], $case1['token'] );
WP_CLI::line( sprintf( 'CASE2 order=%d http=%s duplicate=%d %s', $case1['order_id'], implode( ',', array_unique( $codes ) ), count( array_filter( $bodies, static fn( string $b ): bool => str_contains( $b, '"duplicate"' ) ) ), $render( $before, $after ) ) );
$check(
	'2. aynı imzalı 10 tekrar hiçbir yan etki üretmez',
	array( 200 ) === array_values( array_unique( $codes ) )
	&& 10 === count( array_filter( $bodies, static fn( string $b ): bool => str_contains( $b, '"duplicate"' ) ) )
	&& $after['status'] === $before['status']
	&& $after['total'] === $before['total']
	&& $after['stock'] === $before['stock']
	&& $after['fee_count'] === $before['fee_count']
	&& $after['confirm_notes'] === $before['confirm_notes']
	&& 1 === $after['audit_notes']
	&& 0 === $after['claims']
);

$before = $snapshot( $case1['order_id'], $case1['token'] );
$body7  = $body_for( $case1, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000999' );
$r      = $post_webhook( $body7, $sign( $body7 ) );
$after  = $snapshot( $case1['order_id'], $case1['token'] );
WP_CLI::line( sprintf( 'CASE7 order=%d http=%d %s', $case1['order_id'], $r['code'], $render( $before, $after ) ) );
$check( '7. aynı token farklı payment id reddedilir', 409 === $r['code'] && $no_side_effects( $before, $after ) && 0 === $after['claims'] );

/* -- 3, 4: geçersiz imza, eksik parametre -------------------------------- */

$case3  = $make_fixture( 'imza-parametre', 'success', null, null );
$body3  = $body_for( $case3, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000003' );
$set_double( $case3['token'], $verified_result( $case3, '700000003' ) );

$before = $snapshot( $case3['order_id'], $case3['token'] );
$r      = $post_webhook( $body3, str_repeat( 'a', 64 ) );
$after  = $snapshot( $case3['order_id'], $case3['token'] );
WP_CLI::line( sprintf( 'CASE3 order=%d http=%d code=%s %s', $case3['order_id'], $r['code'], str_contains( $r['body'], 'invalid_signature' ) ? 'invalid_signature' : 'other', $render( $before, $after ) ) );
$check( '3. geçersiz imza 401 döner ve hiçbir şey işlemez', 401 === $r['code'] && str_contains( $r['body'], 'kuka_iyzico_invalid_signature' ) && $no_side_effects( $before, $after ) && 0 === $after['claims'] );

$missing = $body3;
unset( $missing['iyziPaymentId'] );
$before = $snapshot( $case3['order_id'], $case3['token'] );
$r      = $post_webhook( $missing, str_repeat( 'a', 64 ) );
$after  = $snapshot( $case3['order_id'], $case3['token'] );
WP_CLI::line( sprintf( 'CASE4 order=%d http=%d code=%s %s', $case3['order_id'], $r['code'], str_contains( $r['body'], 'missing_param' ) ? 'missing_param' : 'other', $render( $before, $after ) ) );
$check( '4. eksik parametre 400 döner', 400 === $r['code'] && str_contains( $r['body'], 'kuka_iyzico_missing_param' ) && $no_side_effects( $before, $after ) && 0 === $after['claims'] );

/* -- 5: yetkili doğrulama başarısızlıkları — hepsi yan etkisiz ----------- */

$preflight_cases = array(
	'5a. gerçek iyzico API doğrulaması başarısız (test double yok)' => null,
	'5b. API status failure'      => array( 'status' => 'failure' ),
	'5c. tutar uyuşmazlığı'       => array( 'paid_price' => '1.00' ),
	'5d. basket/order id uyuşmazlığı' => array( 'basket_id' => '999999' ),
	'5e. payment status FAILURE'  => array( 'payment_status' => 'FAILURE' ),
	'5f. payment id uyuşmazlığı'  => array( 'payment_id' => '700000555' ),
	'5g. para birimi uyuşmazlığı' => array( 'currency' => 'USD' ),
);
$case5 = $make_fixture( 'yetkili-dogrulama', 'success', null, null );
$body5 = $body_for( $case5, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000005' );
$sig5  = $sign( $body5 );
foreach ( $preflight_cases as $label => $overrides ) {
	$set_double( $case5['token'], null === $overrides ? null : $verified_result( $case5, '700000005', $overrides ) );
	$before = $snapshot( $case5['order_id'], $case5['token'] );
	$r      = $post_webhook( $body5, $sig5 );
	$after  = $snapshot( $case5['order_id'], $case5['token'] );
	WP_CLI::line( sprintf( 'CASE%s order=%d http=%d %s', substr( $label, 0, 2 ), $case5['order_id'], $r['code'], $render( $before, $after ) ) );
	$check( $label, 502 === $r['code'] && str_contains( $r['body'], 'kuka_iyzico_unverified_payment' ) && $no_side_effects( $before, $after ) && 0 === $after['claims'] );
}

$set_double( $case5['token'], $verified_result( $case5, '700000005' ) );
$before = $snapshot( $case5['order_id'], $case5['token'] );
$r      = $post_webhook( $body5, $sig5 );
$after  = $snapshot( $case5['order_id'], $case5['token'] );
WP_CLI::line( sprintf( 'CASE5h order=%d http=%d body=%s %s', $case5['order_id'], $r['code'], $r['body'], $render( $before, $after ) ) );
$check( '5h. doğrulama düzelince aynı teslimat engellenmeden işlenir', 200 === $r['code'] && str_contains( $r['body'], '"processed"' ) && 'processing' === $after['status'] && 1 === $after['processed'] && $after['stock'] === $before['stock'] - 1 && 0 === $after['claims'] );

/* -- 6: desteklenmeyen olay engellenmez ---------------------------------- */

$case6  = $make_fixture( 'farkli-olay', 'success', null, null );
$body6  = $body_for( $case6, 'BALANCE', 'SUCCESS', '700000006' );
$before = $snapshot( $case6['order_id'], $case6['token'] );
$r      = $post_webhook( $body6, $sign( $body6 ) );
$after  = $snapshot( $case6['order_id'], $case6['token'] );
WP_CLI::line( sprintf( 'CASE6 order=%d http=%d body=%s %s', $case6['order_id'], $r['code'], '' === $r['body'] ? '<bos>' : $r['body'], $render( $before, $after ) ) );
$check( '6. desteklenmeyen olay sarmalayıcıya takılmaz, vendor işler', 200 === $r['code'] && ! str_contains( $r['body'], 'duplicate' ) && ! str_contains( $r['body'], 'processed' ) && 'processing' === $after['status'] && 0 === $after['processed'] && 0 === $after['claims'] );

/* -- 8: eşzamanlılık ----------------------------------------------------- */

$case8 = $make_fixture( 'esqzamanli-2', 'success', null, null );
$body8 = $body_for( $case8, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000008' );
$set_double( $case8['token'], $verified_result( $case8, '700000008' ) );
$before = $snapshot( $case8['order_id'], $case8['token'] );
$pair   = $post_webhook_concurrent( $body8, $sign( $body8 ), 2 );
$after  = $snapshot( $case8['order_id'], $case8['token'] );
$processed_pair = count( array_filter( $pair, static fn( array $p ): bool => str_contains( $p['body'], '"processed"' ) ) );
WP_CLI::line( sprintf( 'CASE8 order=%d http=%s processed=%d %s', $case8['order_id'], implode( ',', array_column( $pair, 'code' ) ), $processed_pair, $render( $before, $after ) ) );
$check(
	'8. iki eşzamanlı istekten yalnız biri vendor işlemine ulaşır',
	1 === $processed_pair && 1 === $after['confirm_notes'] && $after['stock'] === $before['stock'] - 1 && $after['fee_count'] === $before['fee_count'] && 'processing' === $after['status'] && 0 === $after['claims'],
	sprintf( 'vendor_islem=%d stok_degisimi=%d fee_eklenen=%d geriye_gitme=0', $after['confirm_notes'], $before['stock'] - $after['stock'], $after['fee_count'] - $before['fee_count'] )
);

$case8b = $make_fixture( 'esqzamanli-5', 'success', null, null );
$body8b = $body_for( $case8b, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000018' );
$set_double( $case8b['token'], $verified_result( $case8b, '700000018' ) );
$before = $snapshot( $case8b['order_id'], $case8b['token'] );
$five   = $post_webhook_concurrent( $body8b, $sign( $body8b ), 5 );
$after  = $snapshot( $case8b['order_id'], $case8b['token'] );
$processed_five = count( array_filter( $five, static fn( array $p ): bool => str_contains( $p['body'], '"processed"' ) ) );
WP_CLI::line( sprintf( 'CASE8b order=%d http=%s processed=%d %s', $case8b['order_id'], implode( ',', array_column( $five, 'code' ) ), $processed_five, $render( $before, $after ) ) );
$check( '8b. beş eşzamanlı istekten yalnız biri vendor işlemine ulaşır', 1 === $processed_five && 1 === $after['confirm_notes'] && $after['stock'] === $before['stock'] - 1 && 0 === $after['claims'], sprintf( 'vendor_islem=%d stok_degisimi=%d fee_eklenen=%d', $after['confirm_notes'], $before['stock'] - $after['stock'], $after['fee_count'] - $before['fee_count'] ) );

$before = $snapshot( $case8b['order_id'], $case8b['token'] );
$dupes  = $post_webhook_concurrent( $body8b, $sign( $body8b ), 5 );
$after  = $snapshot( $case8b['order_id'], $case8b['token'] );
WP_CLI::line( sprintf( 'CASE8c order=%d http=%s %s', $case8b['order_id'], implode( ',', array_column( $dupes, 'code' ) ), $render( $before, $after ) ) );
$check( '8c. eşzamanlı tekrarlar tek denetim notu bırakır', 1 === $after['audit_notes'] && $after['confirm_notes'] === $before['confirm_notes'] && $after['stock'] === $before['stock'] && 0 === $after['claims'] );

/* -- 9: callback --------------------------------------------------------- */

$case9a = $make_fixture( 'callback-ilk', 'success', null, null );
$before = $snapshot( $case9a['order_id'], $case9a['token'] );
$r      = $post_callback( $case9a['order_id'], $case9a['token'] );
$after  = $snapshot( $case9a['order_id'], $case9a['token'] );
WP_CLI::line( sprintf( 'CASE9a order=%d http=%d redirect=%s %s', $case9a['order_id'], $r['code'], '' === $r['location'] ? 'yok' : 'var', $render( $before, $after ) ) );
$check( '9a. ilk callback engellenmez, vendor akışına ulaşır', '' === $r['location'] && 0 === $after['audit_notes'] && 0 === $after['claims'] );

$case9b = $make_fixture( 'callback-tekrar', 'success', 'SUCCESS', '700000009' );
$paid   = wc_get_order( $case9b['order_id'] );
$paid->update_status( 'processing', 'Sandbox fixture: tamamlanmış ödeme.' );
$paid->save();
$before = $snapshot( $case9b['order_id'], $case9b['token'] );
$r      = $post_callback( $case9b['order_id'], $case9b['token'] );
$after  = $snapshot( $case9b['order_id'], $case9b['token'] );
WP_CLI::line( sprintf( 'CASE9b order=%d http=%d redirect=%s %s', $case9b['order_id'], $r['code'], '' === $r['location'] ? 'yok' : 'var', $render( $before, $after ) ) );
$check( '9b. tamamlanmış ödemenin tekrarı sipariş-alındı sayfasına yönlendirilir', in_array( $r['code'], array( 302, 303 ), true ) && '' !== $r['location'] && $after['status'] === $before['status'] && $after['stock'] === $before['stock'] && $after['provider_status'] === $before['provider_status'] && $after['provider_pid'] === $before['provider_pid'] && 1 === $after['audit_notes'] && 0 === $after['claims'] );

$before = $after;
$codes  = array();
for ( $i = 0; $i < 9; $i++ ) {
	$codes[] = $post_callback( $case9b['order_id'], $case9b['token'] )['code'];
}
$after = $snapshot( $case9b['order_id'], $case9b['token'] );
WP_CLI::line( sprintf( 'CASE9c order=%d http=%s %s', $case9b['order_id'], implode( ',', array_unique( $codes ) ), $render( $before, $after ) ) );
$check( '9c. dokuz tekrar daha tek denetim notunu ve not sayısını korur', 1 === $after['audit_notes'] && $after['note_count'] === $before['note_count'] && 0 === $after['claims'] );

$case9d = $make_fixture( 'callback-basarisiz', 'failure', 'FAILURE', null );
$failed = wc_get_order( $case9d['order_id'] );
$failed->update_status( 'failed', 'Sandbox fixture: başarısız ödeme.' );
$failed->save();
$before = $snapshot( $case9d['order_id'], $case9d['token'] );
$r      = $post_callback( $case9d['order_id'], $case9d['token'] );
$after  = $snapshot( $case9d['order_id'], $case9d['token'] );
WP_CLI::line( sprintf( 'CASE9d order=%d http=%d redirect=%s %s', $case9d['order_id'], $r['code'], '' === $r['location'] ? 'yok' : 'var', $render( $before, $after ) ) );
$check( '9d. başarısız ödemenin yeniden denemesi engellenmez', '' === $r['location'] && 0 === $after['audit_notes'] && 0 === $after['claims'] );

/* 9e. Ödeme kesinleşmemişken eşzamanlı callback teşekkür sayfasına gitmemeli. */
$case9e     = $make_fixture( 'callback-surerken', 'success', null, null );
$callback_key = $guard::payment_lock_key( $case9e['order_id'] );
$guard::acquire_lock( $callback_key );
$before       = $snapshot( $case9e['order_id'], $case9e['token'] );
$r            = $post_callback( $case9e['order_id'], $case9e['token'] );
$after        = $snapshot( $case9e['order_id'], $case9e['token'] );
$guard::release_lock( $callback_key );
WP_CLI::line( sprintf( 'CASE9e order=%d http=%d redirect=%s in_progress=%s %s', $case9e['order_id'], $r['code'], '' === $r['location'] ? 'yok' : 'var', str_contains( $r['body'], 'Ödemeniz işleniyor' ) ? 'evet' : 'hayır', $render( $before, $after ) ) );
$check( '9e. işlem sürerken callback teşekkür sayfasına gitmez, 409 alır', 409 === $r['code'] && '' === $r['location'] && str_contains( $r['body'], 'Ödemeniz işleniyor' ) && $no_side_effects( $before, $after ) );

/* 9f. Karışık provider durumları callback tarafında ödenmiş sayılmamalı. */
$mixed_rows = array(
	'SUCCESS/FAILURE'               => array( 'success', 'FAILURE', '700000010' ),
	'FAILURE/SUCCESS'               => array( 'failure', 'SUCCESS', '700000010' ),
	'boş/SUCCESS'                   => array( '', 'SUCCESS', '700000010' ),
	'SUCCESS/boş'                   => array( 'success', '', '700000010' ),
	'iki SUCCESS + boş payment id'  => array( 'success', 'SUCCESS', null ),
);
$mixed_report = array();
foreach ( $mixed_rows as $label => $row ) {
	$mixed  = $make_fixture( 'karisik-' . sanitize_key( $label ), $row[0], $row[1], $row[2] );
	$order  = wc_get_order( $mixed['order_id'] );
	$order->update_status( 'processing', 'Sandbox fixture: karışık provider durumu.' );
	$order->save();
	$before = $snapshot( $mixed['order_id'], $mixed['token'] );
	$r      = $post_callback( $mixed['order_id'], $mixed['token'] );
	$after  = $snapshot( $mixed['order_id'], $mixed['token'] );
	$blocked = '' !== $r['location'];
	$mixed_report[] = $label . '=' . ( $blocked ? 'YONLENDIRDI' : 'gecti' );
	$check( '9f. karışık provider durumu ödenmiş sayılmaz — ' . $label, ! $blocked && 0 === $after['audit_notes'] );
}
WP_CLI::line( 'MIXED_CALLBACK=' . implode( ' | ', $mixed_report ) );

/* -- 10: kilit yaşam döngüsü — canlı işlem devredilemez ------------------ */

$case10 = $make_fixture( 'canli-kilit', 'success', null, null );
$body10 = $body_for( $case10, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000020' );
$sig10  = $sign( $body10 );
$set_double( $case10['token'], $verified_result( $case10, '700000020' ) );
$key10  = $guard::payment_lock_key( $case10['order_id'] );

// A, bu CLI bağlantısında kilidi alır ve testin sonuna kadar canlı tutar.
$check( '10a. A kilidi alır ve canlı tutar', $guard::acquire_lock( $key10 ) && $guard::lock_is_held( $key10 ) );

$before = $snapshot( $case10['order_id'], $case10['token'] );
$first  = $post_webhook( $body10, $sig10 );
sleep( 3 );
$second = $post_webhook( $body10, $sig10 );
$after  = $snapshot( $case10['order_id'], $case10['token'] );
WP_CLI::line( sprintf( 'CASE10b order=%d http=%d,%d (t+0 ve t+3sn) %s', $case10['order_id'], $first['code'], $second['code'], $render( $before, $after ) ) );
$check(
	'10b. A canlıyken B hiçbir zaman devralamaz, vendor\'a giremez ve 409 alır',
	409 === $first['code'] && 409 === $second['code']
	&& str_contains( $first['body'], 'kuka_iyzico_in_progress' )
	&& $no_side_effects( $before, $after )
);

// Zaman aşımına dayalı devralma kodda hiç yok: GET_LOCK'un süresi yoktur.
$guard_source = (string) file_get_contents( WP_PLUGIN_DIR . '/kuka-island-core/includes/class-iyzico-idempotency.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$expiry_symbols = 0;
foreach ( array( 'CLAIM_TTL', 'handle_is_stale', 'age_claim', 'swap_claim' ) as $symbol ) {
	$expiry_symbols += substr_count( $guard_source, $symbol );
}
$check( '10c. süreye dayalı devralma kuralı kodda bulunmuyor', 0 === $expiry_symbols, 'expiry_rules:' . $expiry_symbols );

// Başka bir bağlantının gecikmiş release çağrısı sahipliği düşüremez.
$foreign = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$foreign_release = $foreign->get_var( $foreign->prepare( 'SELECT RELEASE_LOCK(%s)', $guard::lock_name( $key10 ) ) );
$check( '10d. yabancı bağlantının release çağrısı kilidi düşürmez', '0' === (string) $foreign_release && $guard::lock_is_held( $key10 ), 'release:' . var_export( $foreign_release, true ) );

$third  = $post_webhook( $body10, $sig10 );
$check( '10e. sahiplik hâlâ A\'da olduğu için B yine 409 alır', 409 === $third['code'] );

// A bırakınca B girebilir.
$guard::release_lock( $key10 );
$check( '10f. A bıraktıktan sonra kilit serbest', ! $guard::lock_is_held( $key10 ) );
$before = $snapshot( $case10['order_id'], $case10['token'] );
$fourth = $post_webhook( $body10, $sig10 );
$after  = $snapshot( $case10['order_id'], $case10['token'] );
WP_CLI::line( sprintf( 'CASE10f order=%d http=%d body=%s %s', $case10['order_id'], $fourth['code'], $fourth['body'], $render( $before, $after ) ) );
$check( '10g. A bırakınca B işlemi tamamlar', 200 === $fourth['code'] && str_contains( $fourth['body'], '"processed"' ) && 'processing' === $after['status'] && 1 === $after['confirm_notes'] && $after['stock'] === $before['stock'] - 1 && 0 === $after['lock'] );

// Bağlantı sona erdiğinde kilit kendiliğinden bırakılır: kalıcı kilit yok.
$orphan_key = 'kuka-orphan-' . wp_generate_uuid4();
$orphan     = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$orphan_got = $orphan->get_var( $orphan->prepare( 'SELECT GET_LOCK(%s, 0)', $guard::lock_name( $orphan_key ) ) );
$orphan->close();
unset( $orphan );
$check( '10h. bağlantı kapanınca kilit otomatik bırakılır', '1' === (string) $orphan_got && ! $guard::lock_is_held( $orphan_key ) );

// İki gerçek eşzamanlı HTTP isteği: vendor mutasyonu 1, stok 1, onay notu 1.
$case10b = $make_fixture( 'kilit-esqzamanli', 'success', null, null );
$body10b = $body_for( $case10b, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000021' );
$set_double( $case10b['token'], $verified_result( $case10b, '700000021' ) );
$before  = $snapshot( $case10b['order_id'], $case10b['token'] );
$racers  = $post_webhook_concurrent( $body10b, $sign( $body10b ), 2 );
$after   = $snapshot( $case10b['order_id'], $case10b['token'] );
$processed_racers = count( array_filter( $racers, static fn( array $p ): bool => str_contains( $p['body'], '"processed"' ) ) );
WP_CLI::line( sprintf( 'CASE10i order=%d http=%s processed=%d %s', $case10b['order_id'], implode( ',', array_column( $racers, 'code' ) ), $processed_racers, $render( $before, $after ) ) );
$check(
	'10i. iki gerçek eşzamanlı istekte vendor 1, stok 1, onay notu 1',
	1 === $processed_racers && 1 === $after['confirm_notes'] && $after['stock'] === $before['stock'] - 1 && $after['fee_count'] === $before['fee_count'] && 0 === $after['lock'],
	sprintf( 'vendor_islem=%d stok_degisimi=%d onay_notu=%d', $after['confirm_notes'], $before['stock'] - $after['stock'], $after['confirm_notes'] )
);
$check( '10j. istekler bittikten sonra kilit artığı yok', ! $guard::lock_is_held( $guard::payment_lock_key( $case10b['order_id'] ) ) );

/* -- 11: para birimi fail-closed ----------------------------------------- */

$currency_cases = array(
	'TRY/TRY'  => array( array( 'currency' => 'TRY' ), true ),
	'boş/TRY'  => array( array( 'currency' => '' ), false ),
	'USD/TRY'  => array( array( 'currency' => 'USD' ), false ),
	'null/TRY' => array( array( 'currency' => null ), false ),
);
$currency_report = array();
foreach ( $currency_cases as $label => $spec ) {
	$fixture = $make_fixture( 'para-birimi-' . sanitize_key( $label ), 'success', null, null );
	$body    = $body_for( $fixture, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000030' );
	$set_double( $fixture['token'], $verified_result( $fixture, '700000030', $spec[0] ) );
	$before = $snapshot( $fixture['order_id'], $fixture['token'] );
	$r      = $post_webhook( $body, $sign( $body ) );
	$after  = $snapshot( $fixture['order_id'], $fixture['token'] );
	$passed = $spec[1] ? ( 200 === $r['code'] && str_contains( $r['body'], '"processed"' ) ) : ( 502 === $r['code'] && $no_side_effects( $before, $after ) );
	$currency_report[] = $label . '=' . $r['code'];
	WP_CLI::line( sprintf( 'CASE11 %s order=%d http=%d %s', $label, $fixture['order_id'], $r['code'], $render( $before, $after ) ) );
	$check( '11. para birimi fail-closed — ' . $label, $passed );
}
WP_CLI::line( 'CURRENCY_MATRIX=' . implode( ' | ', $currency_report ) );

/* -- 12: bekleme sayfası ve durum sorgusu -------------------------------- */

$case12  = $make_fixture( 'bekleme-tr', 'success', null, null );
$key12   = $guard::payment_lock_key( $case12['order_id'] );
$guard::acquire_lock( $key12 );
$before  = $snapshot( $case12['order_id'], $case12['token'] );
$r       = $post_callback( $case12['order_id'], $case12['token'] );
$after   = $snapshot( $case12['order_id'], $case12['token'] );
$guard::release_lock( $key12 );
WP_CLI::line( sprintf( 'CASE12a order=%d http=%d redirect=%s meta_refresh=%s %s', $case12['order_id'], $r['code'], '' === $r['location'] ? 'yok' : 'var', str_contains( $r['body'], 'http-equiv="refresh"' ) ? 'VAR' : 'yok', $render( $before, $after ) ) );
$check(
	'12a. eşzamanlı callback 409 + Retry-After alır, teşekkür sayfasına gitmez',
	409 === $r['code'] && '' === $r['location']
	&& str_contains( $r['body'], 'Ödemeniz işleniyor' )
	&& str_contains( $r['body'], 'lang="tr"' )
	&& ! str_contains( $r['body'], 'http-equiv="refresh"' )
	&& str_contains( $r['body'], 'method="post"' )
	&& $no_side_effects( $before, $after )
);

$case12en = $make_fixture( 'bekleme-en', 'success', null, null );
$en_order = wc_get_order( $case12en['order_id'] );
$en_order->update_meta_data( '_kuka_order_locale', 'en_US' );
$en_order->save();
$key12en  = $guard::payment_lock_key( $case12en['order_id'] );
$guard::acquire_lock( $key12en );
$r        = $post_callback( $case12en['order_id'], $case12en['token'] );
$guard::release_lock( $key12en );
WP_CLI::line( sprintf( 'CASE12b order=%d http=%d lang=%s', $case12en['order_id'], $r['code'], str_contains( $r['body'], 'lang="en"' ) ? 'en' : 'tr' ) );
$check( '12b. İngilizce siparişte bekleme sayfası İngilizce', 409 === $r['code'] && str_contains( $r['body'], 'Your payment is being processed' ) && str_contains( $r['body'], 'lang="en"' ) && ! str_contains( $r['body'], 'http-equiv="refresh"' ) );

// Durum sorgusu: ödenmemiş sipariş sahte başarı göstermemeli.
$probe_get  = $call_probe( $case12['order_id'], 'GET' );
$probe_post = $call_probe( $case12['order_id'], 'POST' );
WP_CLI::line( sprintf( 'CASE12c order=%d get=%d body=%s post=%d', $case12['order_id'], $probe_get['code'], $probe_get['body'], $probe_post['code'] ) );
$check( '12c. ödenmemiş siparişte durum sorgusu confirmed:false ve POST tekrar 409', 200 === $probe_get['code'] && str_contains( $probe_get['body'], '"confirmed":false' ) && 409 === $probe_post['code'] && '' === $probe_post['location'] );

// Ödeme tamamlandıysa sipariş-alındı sayfasına gider.
$case12paid = $make_fixture( 'bekleme-odenmis', 'success', 'SUCCESS', '700000040' );
$paid12     = wc_get_order( $case12paid['order_id'] );
$paid12->update_status( 'processing', 'Sandbox fixture: tamamlanmış ödeme.' );
$paid12->save();
$probe_get  = $call_probe( $case12paid['order_id'], 'GET' );
$probe_post = $call_probe( $case12paid['order_id'], 'POST' );
WP_CLI::line( sprintf( 'CASE12d order=%d get_body=%s post=%d redirect=%s', $case12paid['order_id'], $probe_get['body'], $probe_post['code'], '' === $probe_post['location'] ? 'yok' : 'var' ) );
$check( '12d. ödeme tamamlandıysa durum sorgusu onaylar ve POST sipariş-alındı sayfasına yönlendirir', str_contains( $probe_get['body'], '"confirmed":true' ) && 303 === $probe_post['code'] && '' !== $probe_post['location'] );

/* -- 13: webhook ve callback ortak sipariş kilidini paylaşır -------------- */

/* A. Aynı sipariş için gerçek eşzamanlı webhook + callback. */
$caseA  = $make_fixture( 'ortak-kilit-a', 'success', null, null );
$bodyA  = $body_for( $caseA, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000050' );
$set_double( $caseA['token'], $verified_result( $caseA, '700000050' ) );
$before = $snapshot( $caseA['order_id'], $caseA['token'] );
$pair   = $post_concurrent(
	array(
		array(
			'label'   => 'webhook',
			'path'    => $webhook_path,
			'body'    => wp_json_encode( $bodyA ),
			'headers' => array( 'Content-Type: application/json', 'X-IYZ-SIGNATURE-V3: ' . $sign( $bodyA ) ),
		),
		array(
			'label'   => 'callback',
			'path'    => $callback_path( $caseA['order_id'] ),
			'body'    => http_build_query( array( 'token' => $caseA['token'] ) ),
			'headers' => array( 'Content-Type: application/x-www-form-urlencoded' ),
		),
	)
);
$after = $snapshot( $caseA['order_id'], $caseA['token'] );
$codesA = array();
foreach ( $pair as $one ) {
	$codesA[] = $one['label'] . ':' . $one['code'];
}
WP_CLI::line( sprintf( 'CASE13A order=%d %s %s', $caseA['order_id'], implode( ' ', $codesA ), $render( $before, $after ) ) );
$check(
	'A. eşzamanlı çiftte en fazla bir vendor mutasyonu olur',
	$after['confirm_notes'] <= 1
	&& $after['stock'] >= $before['stock'] - 1
	&& $after['fee_count'] === $before['fee_count']
	&& in_array( $after['status'], array( 'pending', 'processing' ), true )
	&& 0 === $after['lock'],
	sprintf( 'vendor=%d stok_degisimi=%d fee_duplicate=%d', $after['confirm_notes'], $before['stock'] - $after['stock'], $after['fee_count'] - $before['fee_count'] )
);
$safe_pair = true;
foreach ( $pair as $one ) {
	$ok = in_array( $one['code'], array( 200, 302, 303, 409 ), true );
	// Sadece webhook kanalı JSON sözleşmesi taşır; callback kanalında vendor'ın
	// kendi boş 200'ü de geçerli bir "işledi, değiştirecek bir şey bulamadı"dır.
	if ( 'webhook' === $one['label'] && 200 === $one['code']
		&& ! str_contains( $one['body'], '"processed"' )
		&& ! str_contains( $one['body'], '"duplicate"' )
		&& ! str_contains( $one['body'], '"already_processed"' ) ) {
		$ok = false;
	}
	$safe_pair = $safe_pair && $ok;
}
$check( 'A. iki yanıttan biri işler, diğeri 409 bekleme veya güvenli duplicate', $safe_pair, implode( ' ', $codesA ) );

// Yarışın kazananı hangi kanal olursa olsun ödeme tam olarak bir kez kesinleşir.
$post_webhook( $bodyA, $sign( $bodyA ) );
$post_webhook( $bodyA, $sign( $bodyA ) );
$final = $snapshot( $caseA['order_id'], $caseA['token'] );
WP_CLI::line( sprintf( 'CASE13A2 order=%d %s', $caseA['order_id'], $render( $before, $final ) ) );
$check(
	'A. yarış sonrası toplam: vendor 1, stok düşüşü 1, onay notu 1, fee çoğalması 0',
	1 === $final['confirm_notes']
	&& $final['stock'] === $before['stock'] - 1
	&& $final['fee_count'] === $before['fee_count']
	&& 'processing' === $final['status']
	&& 0 === $final['lock'],
	sprintf( 'vendor=%d stock_delta=%d confirmation_note=%d fee_duplicate=%d', $final['confirm_notes'], $final['stock'] - $before['stock'], $final['confirm_notes'], $final['fee_count'] - $before['fee_count'] )
);

/* B. Webhook önce, callback hemen arkasından. */
$caseB  = $make_fixture( 'ortak-kilit-b', 'success', null, null );
$bodyB  = $body_for( $caseB, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000051' );
$set_double( $caseB['token'], $verified_result( $caseB, '700000051' ) );
$post_webhook( $bodyB, $sign( $bodyB ) );
$before = $snapshot( $caseB['order_id'], $caseB['token'] );
$r      = $post_callback( $caseB['order_id'], $caseB['token'] );
$after  = $snapshot( $caseB['order_id'], $caseB['token'] );
WP_CLI::line( sprintf( 'CASE13B order=%d http=%d redirect=%s %s', $caseB['order_id'], $r['code'], '' === $r['location'] ? 'yok' : 'var', $render( $before, $after ) ) );
$check(
	'B. webhook kesinleştirdikten sonra callback vendor\'ı çalıştırmaz, güvenli yönlenir',
	in_array( $r['code'], array( 302, 303 ), true )
	&& '' !== $r['location']
	&& $after['confirm_notes'] === $before['confirm_notes']
	&& $after['stock'] === $before['stock']
	&& $after['status'] === $before['status']
	&& $after['provider_status'] === $before['provider_status']
	&& $after['provider_pid'] === $before['provider_pid']
);

/* C. Callback sürerken webhook ortak kilide takılır; sonra tekrar çalışmaz. */
$caseC   = $make_fixture( 'ortak-kilit-c', 'success', null, null );
$bodyC   = $body_for( $caseC, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000052' );
$sigC    = $sign( $bodyC );
$set_double( $caseC['token'], $verified_result( $caseC, '700000052' ) );
$lockC   = $guard::payment_lock_key( $caseC['order_id'] );
$guard::acquire_lock( $lockC );
$before  = $snapshot( $caseC['order_id'], $caseC['token'] );
$blocked = $post_webhook( $bodyC, $sigC );
$after   = $snapshot( $caseC['order_id'], $caseC['token'] );
WP_CLI::line( sprintf( 'CASE13C1 order=%d http=%d %s', $caseC['order_id'], $blocked['code'], $render( $before, $after ) ) );
$check( 'C. callback kilidi elindeyken webhook vendor\'a giremez', 409 === $blocked['code'] && str_contains( $blocked['body'], 'kuka_iyzico_in_progress' ) && $no_side_effects( $before, $after ) );

$guard::release_lock( $lockC );
$settled = $post_webhook( $bodyC, $sigC );
$mid     = $snapshot( $caseC['order_id'], $caseC['token'] );
$retry   = $post_webhook( $bodyC, $sigC );
$after   = $snapshot( $caseC['order_id'], $caseC['token'] );
WP_CLI::line( sprintf( 'CASE13C2 order=%d ilk=%d retry=%d body=%s %s', $caseC['order_id'], $settled['code'], $retry['code'], $retry['body'], $render( $mid, $after ) ) );
$check(
	'C. retry sonrası sipariş kesinleşmişse vendor webhook ikinci kez çalışmaz',
	200 === $retry['code']
	&& ( str_contains( $retry['body'], '"duplicate"' ) || str_contains( $retry['body'], '"already_processed"' ) )
	&& $after['confirm_notes'] === $mid['confirm_notes']
	&& $after['stock'] === $mid['stock']
	&& $after['status'] === $mid['status']
);

/* D. Aynı sipariş, iki farklı token. */
$caseD  = $make_fixture( 'ortak-kilit-d', 'success', null, null );
$bodyD  = $body_for( $caseD, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000053' );
$set_double( $caseD['token'], $verified_result( $caseD, '700000053' ) );
$stock_before_d = $snapshot( $caseD['order_id'], $caseD['token'] )['stock'];
$post_webhook( $bodyD, $sign( $bodyD ) );
$mid = $snapshot( $caseD['order_id'], $caseD['token'] );

$late_token = wp_generate_uuid4();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->insert(
	$provider_table,
	array(
		'payment_id'      => null,
		'order_id'        => $caseD['order_id'],
		'conversation_id' => 'sandbox-late-' . $caseD['order_id'],
		'token'           => $late_token,
		'total_amount'    => $caseD['total'],
		'status'          => 'success',
		'payment_status'  => null,
	),
	array( '%s', '%d', '%s', '%s', '%f', '%s', '%s' )
);
$created_provider_rows[] = array(
	'row_id'   => (int) $wpdb->insert_id,
	'order_id' => (int) $caseD['order_id'],
	'token'    => $late_token,
);
$late_fixture = array( 'order_id' => $caseD['order_id'], 'token' => $late_token, 'conversation_id' => 'sandbox-late-' . $caseD['order_id'], 'total' => $caseD['total'] );
$late_body    = $body_for( $late_fixture, 'CHECKOUT_FORM_AUTH', 'SUCCESS', '700000054' );
$set_double( $late_token, $verified_result( $late_fixture, '700000054' ) );

$before_late = $snapshot( $caseD['order_id'], $caseD['token'] );
$late_hook   = $post_webhook( $late_body, $sign( $late_body ) );
$late_call   = $post_callback( $caseD['order_id'], $late_token );
$after       = $snapshot( $caseD['order_id'], $caseD['token'] );
WP_CLI::line( sprintf( 'CASE13D order=%d gec_webhook=%d gec_callback=%d %s', $caseD['order_id'], $late_hook['code'], $late_call['code'], $render( $before_late, $after ) ) );
$check(
	'D. aynı siparişe geç gelen ikinci token ikinci mutasyon üretmez',
	409 === $late_hook['code']
	&& in_array( $late_call['code'], array( 302, 303 ), true )
	&& 1 === $after['confirm_notes']
	&& $after['stock'] === $stock_before_d - 1
	&& $after['fee_count'] === $before_late['fee_count']
	&& 'processing' === $after['status'],
	sprintf( 'vendor=%d stock_delta=%d confirmation_note=%d fee_duplicate=%d', $after['confirm_notes'], $after['stock'] - $stock_before_d, $after['confirm_notes'], $after['fee_count'] - $before_late['fee_count'] )
);

/* E. Bekleme sayfası: gerçek Retry-After başlığı ve token sızıntısı. */
$caseE = $make_fixture( 'bekleme-basliklari', 'success', null, null );
$lockE = $guard::payment_lock_key( $caseE['order_id'] );
$guard::acquire_lock( $lockE );
$rE = $post_callback( $caseE['order_id'], $caseE['token'] );
$guard::release_lock( $lockE );
WP_CLI::line( sprintf( 'CASE13E order=%d http=%d retry_after=%s token_in_body=%s', $caseE['order_id'], $rE['code'], '' === $rE['retry_after'] ? 'yok' : $rE['retry_after'], str_contains( $rE['body'], $caseE['token'] ) ? 'VAR' : 'yok' ) );
$check( 'E. bekleme sayfası Retry-After: 5 döner ve gövdede ödeme tokenı yoktur', 409 === $rE['code'] && '5' === $rE['retry_after'] && ! str_contains( $rE['body'], $caseE['token'] ) && str_contains( $rE['body'], 'lang="tr"' ) );

/* -- korunan siparişler, katalog ve temizlik ----------------------------- */

wp_cache_flush();
$untouched = true;
foreach ( KUKA_IYZ_PROTECTED_ORDERS as $id ) {
	$order = wc_get_order( $id );
	$now   = $order ? $order->get_status() . '/' . $order->get_total() . '/' . count( wc_get_order_notes( array( 'order_id' => $id, 'limit' => 500 ) ) ) : 'missing';
	if ( $now !== $protected_before[ $id ] ) {
		$untouched = false;
		WP_CLI::line( sprintf( 'PROTECTED_CHANGED=%d %s → %s', $id, $protected_before[ $id ], $now ) );
	}
}
$check( 'Korunan sandbox siparişleri değişmedi', $untouched, implode( ',', KUKA_IYZ_PROTECTED_ORDERS ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$legacy_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'kuka_iyz_claim_%'" );
$held_locks  = 0;
foreach ( array( $case1, $case3, $case5, $case6, $case8, $case8b, $case10, $case10b, $caseA, $caseB, $caseC, $caseD, $caseE, $case9a, $case9b, $case9d ) as $fixture ) {
	$held_locks += $guard::lock_is_held( $guard::payment_lock_key( $fixture['order_id'] ) ) ? 1 : 0;
}
$check( 'Test sonunda kilit ve eski claim artığı yok', 0 === $legacy_rows && 0 === $held_locks, 'legacy_rows:' . $legacy_rows . ' held_locks:' . $held_locks );

$cleanup();
wp_cache_flush();

// Cleanup refusals are reported, never worked around with a broader delete.
if ( $cleanup_refusals ) {
	WP_CLI::line( 'CLEANUP_REFUSED=' . implode( ' | ', $cleanup_refusals ) );
}
$check( 'Temizlik hiçbir hedefi doğrulayamadan silmedi', empty( $cleanup_refusals ), $cleanup_refusals ? implode( ' | ', $cleanup_refusals ) : 'reddedilen yok' );
$check( 'Temizlik durumu succeeded', 'succeeded' === $cleanup_state, 'state:' . $cleanup_state );

/*
 * Counts alone could hide a swap, and would not show a record created by
 * someone else during the run being removed by this cleanup. The ordered
 * primary-key sets are compared instead.
 */
$permanent_after = kuka_iyzico_permanent_key_sets();
$diff            = kuka_iyzico_key_set_diff( $permanent_before, $permanent_after );
$count_rows      = array();
$key_rows        = array();
$keys_equal      = true;
$nothing_removed = true;
foreach ( $permanent_before as $table => $keys ) {
	$count_rows[] = $table . ':' . count( $keys ) . '→' . count( $permanent_after[ $table ] ?? array() );
	$key_rows[]   = $table . ':' . ( $diff[ $table ]['equal'] ? 'same' : 'removed:' . $diff[ $table ]['removed'] . ',added:' . $diff[ $table ]['added'] );
	$keys_equal   = $keys_equal && $diff[ $table ]['equal'];
	// A record that existed before the run must still exist, whoever made it.
	$nothing_removed = $nothing_removed && 0 === $diff[ $table ]['removed'];
}
WP_CLI::line( 'PERMANENT_COUNTS=' . implode( ' ', $count_rows ) );
WP_CLI::line( 'PERMANENT_KEYSETS=' . implode( ' ', $key_rows ) );
$check( 'Kalıcı tablolarda başlangıç kimlik kümeleri birebir korundu', $keys_equal );
$check( 'Koşu öncesinde var olan hiçbir kayıt silinmedi', $nothing_removed );
WP_CLI::line( 'RUN_ORDERS_CREATED=' . count( array_unique( $created_orders ) ) . '|provider_rows:' . count( $created_provider_rows ) );

$catalog_after = $catalog_fingerprint( array( $fixture_id ) );
$fixture_after = $stock_snapshot( $fixture_id );
WP_CLI::line( sprintf( 'CATALOG_AFTER=%s|products:%d', $catalog_after['hash'], $catalog_after['count'] ) );
WP_CLI::line( sprintf( 'FIXTURE_RESTORED=%s→%s', var_export( $fixture_before['quantity'], true ), var_export( $fixture_after['quantity'], true ) ) );
$restored = $catalog_before['hash'] === $catalog_after['hash'] && $fixture_before == $fixture_after; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
WP_CLI::line( 'CATALOG_STOCK_RESTORED=' . ( $restored ? 'yes' : 'no' ) );
$check( 'Katalog stokları birebir geri yüklendi', $restored );
$check( 'Test double dosyası kaldırıldı', ! file_exists( $mu_file ) );

WP_CLI::line( 0 === $failures ? 'IYZICO_INTEGRATION=PASS' : 'IYZICO_INTEGRATION=FAIL (' . $failures . ')' );
if ( 0 !== $failures || $cleanup_refusals || 'succeeded' !== $cleanup_state ) {
	// A refusal is a real result, not a log line: leave with a non-zero code.
	WP_CLI::halt( 1 );
}
