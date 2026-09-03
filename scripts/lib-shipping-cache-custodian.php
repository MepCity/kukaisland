<?php
/**
 * The carrier reference cache custodian, shared by every suite that disturbs it.
 *
 * WHAT IT PROTECTS. The DHL address resolver caches the courier's city and
 * district lists in wp_options transients. A verification run has to empty that
 * cache before it resolves an address -- a warm cache means the mock's
 * /getcities is never called and the request counts stop meaning anything -- and
 * it then refills it with one-city mock data. Those rows belong to the SHOP, not
 * to the run.
 *
 * WHY A CUSTODIAN RATHER THAN A CLEANUP BLOCK. A cleanup that only runs at the
 * bottom of the file does not run when the file does not reach the bottom. A
 * failed assertion calls WP_CLI::error(), which exits; a fatal error exits
 * harder. Either way the shop's rows would have been left holding a one-city
 * list. So the restore is registered as a shutdown function AT SNAPSHOT TIME and
 * is idempotent, and the normal path calls the same method: whichever happens
 * first wins and the second call is a no-op.
 *
 * OWNERSHIP IS EXACT, NOT INFERRED. The snapshot records the exact option names
 * present before the run. Afterwards, a matching row whose name is in that set
 * is RESTORED to its recorded value and autoload flag; a matching row whose name
 * is not is the run's own and is removed. Nothing is guessed from timestamps or
 * from the shape of a value.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || defined( 'ABSPATH' ) || exit( 1 );

/**
 * Snapshot, restore and account for the carrier reference cache.
 */
final class Kuka_Shipping_Cache_Custodian {

	/** Matches the resolver's own key prefixes, value rows and timeout rows alike. */
	public const LIKE = '%kuka\\_dhl\\_cbs\\_%';

	/** @var array<string, array{option_value: string, autoload: string}> */
	private array $snapshot;

	/** @var array<int, string> Exact option names present at snapshot time. */
	private array $owned_before;

	private string $fingerprint;

	private bool $done = false;

	/** @var array{ok: bool, restored: int, inserted: int, run_owned_removed: int, refused: int, fingerprint_match: bool, invoked_by: string} */
	private array $outcome;

	public function __construct() {
		$this->snapshot     = self::rows();
		$this->owned_before = array_keys( $this->snapshot );
		$this->fingerprint  = self::fingerprint( $this->snapshot );
		$this->outcome      = array(
			'ok'                => false,
			'restored'          => 0,
			'inserted'          => 0,
			'run_owned_removed' => 0,
			'refused'           => 0,
			'fingerprint_match' => false,
			'invoked_by'        => 'never',
		);
	}

	/**
	 * Register the restore to run at shutdown as well.
	 *
	 * Called immediately after construction so the window in which a crash
	 * would leave the cache dirty is as short as it can be made.
	 */
	public function guard(): self {
		register_shutdown_function(
			function (): void {
				$this->restore( 'shutdown' );
			}
		);

		return $this;
	}

	/** Every row of the cache, raw and ordered. @return array<string, array{option_value: string, autoload: string}> */
	public static function rows(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
				self::LIKE
			),
			ARRAY_A
		);

		$rows = array();

		foreach ( $found as $row ) {
			$rows[ (string) $row['option_name'] ] = array(
				'option_value' => (string) $row['option_value'],
				'autoload'     => (string) $row['autoload'],
			);
		}

		return $rows;
	}

	/**
	 * A fingerprint over the cache's MEANING: names, values, autoload flags.
	 *
	 * option_id is excluded. A transient that is deleted and written again lands
	 * on a new row id while holding the same value, and an identifier the shop
	 * never reads is not part of what has to be preserved.
	 *
	 * @param array<string, array{option_value: string, autoload: string}> $rows Rows.
	 */
	public static function fingerprint( array $rows ): string {
		$parts = array();

		foreach ( $rows as $name => $row ) {
			$parts[] = $name . "\x1f" . $row['option_value'] . "\x1f" . $row['autoload'];
		}

		sort( $parts );

		return md5( implode( "\x1e", $parts ) );
	}

	public function snapshot_fingerprint(): string {
		return $this->fingerprint;
	}

	/** @return array<int, string> */
	public function names_before(): array {
		return $this->owned_before;
	}

	/**
	 * Put the cache back exactly as it was found. Safe to call more than once.
	 *
	 * @param string $invoked_by 'normal' or 'shutdown', for the report.
	 * @return array{ok: bool, restored: int, inserted: int, run_owned_removed: int, refused: int, fingerprint_match: bool, invoked_by: string}
	 */
	public function restore( string $invoked_by = 'normal' ): array {
		if ( $this->done ) {
			return $this->outcome;
		}

		$this->done = true;

		global $wpdb;

		$restored = 0;
		$inserted = 0;
		$removed  = 0;
		$refused  = 0;
		$wanted   = array();

		foreach ( $this->snapshot as $name => $row ) {
			$wanted[ $name ] = true;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $name ) );

			if ( $exists > 0 ) {
				// Updated rather than deleted and re-inserted, so a row the run
				// only overwrote keeps its identity.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$written = $wpdb->update(
					$wpdb->options,
					array(
						'option_value' => $row['option_value'],
						'autoload'     => $row['autoload'],
					),
					array( 'option_name' => $name )
				);

				if ( false === $written ) {
					++$refused;
				} else {
					++$restored;
				}
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$written = $wpdb->insert(
					$wpdb->options,
					array(
						'option_name'  => $name,
						'option_value' => $row['option_value'],
						'autoload'     => $row['autoload'],
					)
				);

				if ( false === $written ) {
					++$refused;
				} else {
					++$inserted;
				}
			}

			wp_cache_delete( $name, 'options' );
		}

		foreach ( array_keys( self::rows() ) as $name ) {
			if ( isset( $wanted[ $name ] ) ) {
				continue;
			}

			// Not present when the snapshot was taken, so this run created it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );

			if ( false === $deleted ) {
				++$refused;
			} else {
				++$removed;
			}

			wp_cache_delete( $name, 'options' );
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$match = self::fingerprint( self::rows() ) === $this->fingerprint;

		$this->outcome = array(
			// A refusal means the cache was NOT put back, whatever else happened.
			'ok'                => 0 === $refused && $match,
			'restored'          => $restored,
			'inserted'          => $inserted,
			'run_owned_removed' => $removed,
			'refused'           => $refused,
			'fingerprint_match' => $match,
			'invoked_by'        => $invoked_by,
		);

		return $this->outcome;
	}

	/** @return array{ok: bool, restored: int, inserted: int, run_owned_removed: int, refused: int, fingerprint_match: bool, invoked_by: string} */
	public function outcome(): array {
		return $this->outcome;
	}
}
