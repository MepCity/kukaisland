<?php
/**
 * Shipping method presentation.
 *
 * Measured before this class existed: with a ₺5.780 subtotal against a ₺1.500
 * free-shipping threshold, WooCommerce offered both the free method and the
 * flat rate and kept the flat rate selected, so ₺149 was still added. The rate
 * amounts are WooCommerce's (§17.3); this only decides which of the offered
 * methods the customer is shown.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Shipping {
	private const REQUIREMENT_MIGRATION_OPTION = 'kuka_free_shipping_requirement_version';
	private const REQUIREMENT_MIGRATION_VERSION = 2;

	public function register(): void {
		add_action( 'init', array( $this, 'migrate_free_shipping_requirement' ), 20 );
		add_filter( 'woocommerce_package_rates', array( $this, 'hide_paid_rates_when_free' ), 100 );
		add_filter( 'woocommerce_shipping_rate_label', array( $this, 'translate_rate_label' ), 20, 2 );
	}

	/**
	 * Upgrade existing zones from threshold-only to threshold-or-coupon.
	 *
	 * New installs are seeded with the same contract. This one-time migration is
	 * needed for stores whose shipping instance already predates that seed.
	 */
	public function migrate_free_shipping_requirement(): void {
		if ( self::REQUIREMENT_MIGRATION_VERSION <= (int) get_option( self::REQUIREMENT_MIGRATION_OPTION, 0 ) ) {
			return;
		}

		Kuka_Island_Core_Site_Appearance::sync_free_shipping_threshold();
		update_option( self::REQUIREMENT_MIGRATION_OPTION, self::REQUIREMENT_MIGRATION_VERSION, true );

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	/**
	 * Drop every other rate once a free-shipping rate qualifies for the package.
	 *
	 * @param array<string, WC_Shipping_Rate> $rates Rates offered for the package.
	 * @return array<string, WC_Shipping_Rate>
	 */
	public function hide_paid_rates_when_free( array $rates ): array {
		$free = array();
		foreach ( $rates as $rate_id => $rate ) {
			if ( 'free_shipping' === $rate->get_method_id() ) {
				$free[ $rate_id ] = $rate;
			}
		}
		return $free ? $free : $rates;
	}

	/** Return storefront shipping method names in the active language. */
	public function translate_rate_label( string $label, WC_Shipping_Rate $rate ): string {
		if ( ! Kuka_Island_Core_Language::is_english_context() ) {
			return $label;
		}
		if ( 'free_shipping' === $rate->get_method_id() ) {
			return 'Free shipping';
		}
		if ( 'flat_rate' === $rate->get_method_id() ) {
			return 'Flat rate';
		}
		return $label;
	}
}
