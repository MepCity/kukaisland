<?php
/**
 * The carrier reference cache custodian, shared by every suite that uses it.
 *
 * WHAT IT PROTECTS. The DHL address resolver caches the courier's city and
 * district lists in wp_options transients. A verification run has to work with
 * a cold cache -- a warm one means the mock's /getcities is never called and
 * the request counts stop meaning anything -- and those cached rows belong to
 * the SHOP.
 *
 * HOW, AFTER TWO WRONG ANSWERS.
 *
 * The first version purged the shop's rows and refilled them with mock data.
 * The second snapshotted them and promised to put them back, which is a promise
 * a crashed process cannot keep, and which decided ownership by subtraction:
 * "this row was not in my snapshot, therefore I created it". That is not
 * ownership. A row can appear during a run because a real request came in on
 * another process, and deleting it is deleting somebody else's data on a guess.
 *
 * So this version does not share a key space with the shop at all. The run
 * gives the resolver a namespace of its own (see
 * DHL_Address_Resolver::set_cache_namespace), tells the custodian the EXACT
 * option names it will create, and the custodian removes exactly those names
 * and nothing else -- one delete per name, by name.
 *
 * There is no wildcard delete anywhere in this file, and there must never be
 * one: a LIKE pattern cannot express ownership.
 *
 * WHAT IT STILL VERIFIES. The shop's rows are read at snapshot time and read
 * again at release, and every one of them has to be byte-identical -- name,
 * value and autoload flag. Anything the run did not declare is left untouched
 * and counted, so a row that appeared meanwhile is visible as a foreign row
 * rather than deleted as a suspected leftover.
 *
 * WHEN. The release is registered as a shutdown function at construction time
 * and the normal path calls the same method. It is idempotent, and it marks
 * itself done ONLY on a clean result: a half-finished release stays open so the
 * shutdown pass can try again.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || defined( 'ABSPATH' ) || exit( 1 );

/**
 * Declare, isolate and release the carrier reference cache rows a run creates.
 */
final class Kuka_Shipping_Cache_Custodian {

	/**
	 * The production key namespace: what the shop's own rows carry.
	 *
	 * Used for READING only -- to prove the shop's rows were not touched.
	 */
	public const PRODUCTION_NAMESPACE = 'v1';

	/** @var array<string, array{option_value: string, autoload: string}> */
	private array $foreign_snapshot;

	/** @var array<string, bool> Exact option names this run declared as its own. */
	private array $owned = array();

	private string $namespace;

	private bool $done = false;

	/** @var array<string, mixed> */
	private array $outcome;

	/**
	 * @param string $namespace Key namespace this run will use, '' for a fresh one.
	 */
	public function __construct( string $namespace = '' ) {
		$this->namespace        = '' !== $namespace ? $namespace : self::mint_namespace();
		$this->foreign_snapshot = self::rows();
		$this->outcome          = array(
			'ok'                => false,
			'owned_declared'    => 0,
			'owned_removed'     => 0,
			'owned_absent'      => 0,
			'foreign_preserved' => 0,
			'foreign_changed'   => 0,
			'refused'           => 0,
			'invoked_by'        => 'never',
		);
	}

	/** A namespace no other process can be using. */
	public static function mint_namespace(): string {
		return 'testrun-' . strtolower( bin2hex( random_bytes( 5 ) ) );
	}

	public function namespace_key(): string {
		return $this->namespace;
	}

	/**
	 * Register the release to run at shutdown as well.
	 *
	 * Called immediately after construction, so the window in which a crash
	 * would leave declared rows behind is as short as it can be made.
	 */
	public function guard(): self {
		register_shutdown_function(
			function (): void {
				$this->release( 'shutdown' );
			}
		);

		return $this;
	}

	/**
	 * Declare one transient as this run's own, by its EXACT option names.
	 *
	 * Both rows are declared: the value row and the timeout row WordPress
	 * writes beside it. A transient whose timeout row is left behind is a
	 * transient that never expires.
	 *
	 * @param string $transient Transient name, without the option prefix.
	 */
	public function own_transient( string $transient ): self {
		$this->owned[ '_transient_' . $transient ]         = true;
		$this->owned[ '_transient_timeout_' . $transient ] = true;

		return $this;
	}

	/**
	 * Declare the rows a resolver in this namespace can create.
	 *
	 * @param array<int, string> $city_codes City codes whose districts are cached.
	 */
	public function own_resolver_keys( array $city_codes ): self {
		$this->own_transient( 'kuka_dhl_cbs_cities_' . $this->namespace );

		foreach ( $city_codes as $city_code ) {
			$this->own_transient( 'kuka_dhl_cbs_districts_' . $this->namespace . '_' . (string) $city_code );
		}

		return $this;
	}

	/** @return array<int, string> */
	public function owned_names(): array {
		return array_keys( $this->owned );
	}

	/**
	 * Every carrier reference cache row, raw.
	 *
	 * A read, not a delete: the pattern is here to observe the whole key space,
	 * which is exactly what proves the run stayed out of the shop's part of it.
	 *
	 * @return array<string, array{option_value: string, autoload: string}>
	 */
	public static function rows(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
				'%kuka\\_dhl\\_cbs\\_%'
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
	 * The rows that are NOT this run's, with their values.
	 *
	 * @param array<string, array{option_value: string, autoload: string}> $rows Rows.
	 * @return array<string, array{option_value: string, autoload: string}>
	 */
	private function foreign( array $rows ): array {
		return array_diff_key( $rows, $this->owned );
	}

	/**
	 * A fingerprint over names, values and autoload flags.
	 *
	 * option_id is excluded: a row the shop never reads by id is not part of
	 * what has to be preserved.
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

	/** The fingerprint of everything that was not this run's, at snapshot time. */
	public function foreign_fingerprint(): string {
		return self::fingerprint( $this->foreign( $this->foreign_snapshot ) );
	}

	/**
	 * Remove exactly the rows this run declared. Safe to call more than once.
	 *
	 * @param string $invoked_by 'normal' or 'shutdown', for the report.
	 * @return array<string, mixed>
	 */
	public function release( string $invoked_by = 'normal' ): array {
		if ( $this->done ) {
			return $this->outcome;
		}

		global $wpdb;

		$removed = 0;
		$absent  = 0;
		$refused = 0;
		$present = self::rows();

		foreach ( array_keys( $this->owned ) as $name ) {
			if ( ! isset( $present[ $name ] ) ) {
				++$absent;
				continue;
			}

			// One delete, one exact name. No pattern, ever.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );

			if ( false === $deleted || 0 === (int) $deleted ) {
				++$refused;
				continue;
			}

			wp_cache_delete( $name, 'options' );
			++$removed;
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$after   = self::rows();
		$foreign = $this->foreign( $after );
		$changed = 0;

		foreach ( $this->foreign( $this->foreign_snapshot ) as $name => $row ) {
			if ( ! isset( $foreign[ $name ] )
				|| $foreign[ $name ]['option_value'] !== $row['option_value']
				|| $foreign[ $name ]['autoload'] !== $row['autoload'] ) {
				++$changed;
			}
		}

		$owned_left = 0;
		foreach ( array_keys( $this->owned ) as $name ) {
			if ( isset( $after[ $name ] ) ) {
				++$owned_left;
			}
		}

		$ok = 0 === $refused && 0 === $changed && 0 === $owned_left;

		$this->outcome = array(
			'ok'                => $ok,
			'owned_declared'    => count( $this->owned ),
			'owned_removed'     => $removed,
			'owned_absent'      => $absent,
			'foreign_preserved' => count( $foreign ),
			'foreign_changed'   => $changed,
			'refused'           => $refused,
			'invoked_by'        => $invoked_by,
		);

		// Done ONLY on a clean release. A half-finished one stays open so the
		// shutdown pass gets another go at it.
		$this->done = $ok;

		return $this->outcome;
	}

	/** @return array<string, mixed> */
	public function outcome(): array {
		return $this->outcome;
	}
}
