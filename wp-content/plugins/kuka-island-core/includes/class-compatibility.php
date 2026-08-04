<?php
/**
 * WooCommerce feature compatibility declarations.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Compatibility {
	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
	}

	public function declare_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', KUKA_ISLAND_CORE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', KUKA_ISLAND_CORE_FILE, true );
		}
	}
}

