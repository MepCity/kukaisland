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
	public function register(): void {
		add_filter( 'woocommerce_package_rates', array( $this, 'hide_paid_rates_when_free' ), 100 );
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
}
