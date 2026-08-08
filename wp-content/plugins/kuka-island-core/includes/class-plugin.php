<?php
/**
 * Plugin composition root.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Plugin {
	private static ?self $instance = null;

	/** @var array<object> */
	private array $modules = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		$files = array(
			'class-compatibility.php',
			'class-site-appearance.php',
			'class-content.php',
			'class-newsletter.php',
			'class-membership.php',
			'class-shipping.php',
			'class-product-fields.php',
			'class-combination-relations.php',
			'class-image-threshold.php',
			'class-legal-displays.php',
			'class-corporate-billing.php',
			'class-swatch-meta.php',
			'class-admin-experience.php',
		);

		foreach ( $files as $file ) {
			require_once KUKA_ISLAND_CORE_PATH . 'includes/' . $file;
		}

		$this->modules = array(
			new Kuka_Island_Core_Compatibility(),
			new Kuka_Island_Core_Site_Appearance(),
			new Kuka_Island_Core_Content(),
			new Kuka_Island_Core_Newsletter(),
			new Kuka_Island_Core_Membership(),
			new Kuka_Island_Core_Shipping(),
			new Kuka_Island_Core_Product_Fields(),
			new Kuka_Island_Core_Combination_Relations(),
			new Kuka_Island_Core_Image_Threshold(),
			new Kuka_Island_Core_Legal_Displays(),
			new Kuka_Island_Core_Corporate_Billing(),
			new Kuka_Island_Core_Swatch_Meta(),
			new Kuka_Island_Core_Admin_Experience(),
		);

		foreach ( $this->modules as $module ) {
			if ( method_exists( $module, 'register' ) ) {
				$module->register();
			}
		}
	}

	private function __construct() {}
}
