<?php
/**
 * Lifecycle hooks; no customer data is removed on deactivation.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Activator {
	public static function activate(): void {
		update_option( 'kuka_island_core_version', '0.1.0', false );
	}

	public static function deactivate(): void {
		// Deliberately non-destructive; product/order data belongs to the customer.
	}
}

