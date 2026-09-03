<?php
/**
 * Load the shipping automation module for offline verification, without
 * activating the plugin.
 *
 * The plugin ships inactive, so WordPress does not load these classes and the
 * verification scripts cannot assume they exist. They are required directly
 * from the plugin's own path instead of the plugin being activated, for two
 * reasons:
 *
 *   1. Activating it would make the passive-contract measurement impossible in
 *      the same run, and would risk leaving the shop in a state the delivery
 *      contract says it must not be in.
 *   2. Requiring the module's own file list is what proves the module still
 *      loads correctly from its own location.
 *
 * Loading the classes registers nothing: no constructor here attaches a hook,
 * and register() is never called.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'WP_CLI' ) || defined( 'ABSPATH' ) || exit( 1 );

/**
 * Require the shipping module from the plugin directory.
 *
 * @return array{ok: bool, path: string, reason: string, classes: int}
 */
function kuka_shipping_load_module(): array {
	$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-shipping-automation/';

	if ( ! defined( 'KUKA_ISLAND_SHIPPING_PATH' ) ) {
		define( 'KUKA_ISLAND_SHIPPING_PATH', $plugin_dir );
	}

	if ( ! defined( 'KUKA_ISLAND_SHIPPING_FILE' ) ) {
		define( 'KUKA_ISLAND_SHIPPING_FILE', $plugin_dir . 'kuka-island-shipping-automation.php' );
	}

	$composer = $plugin_dir . 'includes/class-shipping-automation.php';

	if ( ! is_readable( $composer ) ) {
		return array(
			'ok'      => false,
			'path'    => $plugin_dir,
			'reason'  => 'module_composition_file_missing',
			'classes' => 0,
		);
	}

	require_once $composer;

	if ( ! class_exists( 'Kuka_Island_Shipping_Automation' ) ) {
		return array(
			'ok'      => false,
			'path'    => $plugin_dir,
			'reason'  => 'composition_class_absent_after_require',
			'classes' => 0,
		);
	}

	$dir    = $plugin_dir . 'includes/shipping/';
	$loaded = 0;

	foreach ( Kuka_Island_Shipping_Automation::module_files() as $file ) {
		if ( ! is_readable( $dir . $file ) ) {
			return array(
				'ok'      => false,
				'path'    => $plugin_dir,
				'reason'  => 'module_file_missing:' . $file,
				'classes' => $loaded,
			);
		}

		require_once $dir . $file;
		++$loaded;
	}

	return array(
		'ok'      => true,
		'path'    => $plugin_dir,
		'reason'  => 'loaded_from_shipping_plugin',
		'classes' => $loaded,
	);
}
