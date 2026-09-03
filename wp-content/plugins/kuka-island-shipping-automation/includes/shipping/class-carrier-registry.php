<?php
/**
 * The carrier registry: the seam a second courier is added through.
 *
 * Adding a courier means writing one adapter that implements
 * Kuka_Island_Shipping_Carrier_Interface and attaching it to the
 * 'kuka_island_shipping_carriers' filter. Nothing else changes: not Core, not
 * the manager, not the order store, not the poller, not the admin panel, not
 * WooCommerce, and not the manual fulfilment route.
 *
 * The registry is deliberately dumb. It does not decide which carrier an order
 * goes to and it does not fall back: an unknown key returns null, and the caller
 * says so. A registry that silently substituted "the only carrier available"
 * would hand parcels to a courier nobody chose.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Carrier_Registry {

	/** @var array<string, Kuka_Island_Shipping_Carrier_Interface>|null */
	private ?array $carriers = null;

	/**
	 * Every registered carrier, keyed by its own get_key().
	 *
	 * Built once per request. Entries that are not adapters are dropped rather
	 * than trusted, so a filter that returns a string or a half-built object
	 * cannot reach the manager.
	 *
	 * @return array<string, Kuka_Island_Shipping_Carrier_Interface>
	 */
	public function all(): array {
		if ( null !== $this->carriers ) {
			return $this->carriers;
		}

		/**
		 * Register carrier adapters.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int|string, mixed> $carriers Adapter instances.
		 */
		$raw = apply_filters( 'kuka_island_shipping_carriers', array() );

		$resolved = array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $candidate ) {
				if ( ! $candidate instanceof Kuka_Island_Shipping_Carrier_Interface ) {
					continue;
				}

				$key = strtolower( trim( $candidate->get_key() ) );
				if ( '' === $key ) {
					continue;
				}

				$resolved[ $key ] = $candidate;
			}
		}

		ksort( $resolved );

		$this->carriers = $resolved;

		return $this->carriers;
	}

	/**
	 * One carrier by key, or null when nothing is registered under it.
	 *
	 * @param string $key Carrier key.
	 */
	public function get( string $key ): ?Kuka_Island_Shipping_Carrier_Interface {
		$carriers = $this->all();

		return $carriers[ strtolower( trim( $key ) ) ] ?? null;
	}

	/** @return array<int, string> */
	public function keys(): array {
		return array_keys( $this->all() );
	}

	/**
	 * Forget the built list.
	 *
	 * Only tests need this: they attach a filter after the registry has already
	 * answered once, and without it the second measurement would read the first
	 * measurement's list.
	 */
	public function reset(): void {
		$this->carriers = null;
	}
}
