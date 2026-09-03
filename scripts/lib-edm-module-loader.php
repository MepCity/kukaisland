<?php
/**
 * Load the EDM invoice module for offline verification, without activating it.
 *
 * The plugin ships inactive, so WordPress does not load these classes and the
 * verification scripts cannot assume they exist. They are required directly
 * from the plugin's own path instead of the plugin being activated, for two
 * reasons:
 *
 *   1. Activating it would make the passive-contract measurement impossible in
 *      the same run, and would risk leaving the shop in a state the delivery
 *      contract says it must not be in.
 *   2. Requiring the module's own composition file is what proves the module
 *      still loads correctly FROM ITS NEW LOCATION -- which is the thing the
 *      move could have broken.
 *
 * Loading the classes registers nothing: class-invoice.php's constructor wires
 * objects, and only register() attaches hooks. Nothing here constructs it.
 *
 * @package Kuka_Island_EDM
 */

defined( 'WP_CLI' ) || defined( 'ABSPATH' ) || exit( 1 );

/**
 * Require the invoice module from the EDM plugin.
 *
 * @return array{ok: bool, path: string, reason: string, classes: int}
 */
function kuka_edm_load_module(): array {
	$plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . 'kuka-island-edm/';
	$composer   = $plugin_dir . 'includes/class-invoice.php';

	if ( ! is_readable( $composer ) ) {
		return array(
			'ok'      => false,
			'path'    => $plugin_dir,
			'reason'  => 'module_composition_file_missing',
			'classes' => 0,
		);
	}

	// The module's own loader pulls in every dependency in dependency order.
	require_once $composer;

	if ( ! class_exists( 'Kuka_Island_Core_Invoice' ) ) {
		return array(
			'ok'      => false,
			'path'    => $plugin_dir,
			'reason'  => 'composition_class_absent_after_require',
			'classes' => 0,
		);
	}

	/*
	 * class-invoice.php declares the composition class but loads the rest in
	 * its constructor's load_dependencies(). Calling that here would build
	 * objects; instead the same file list is required directly, so the classes
	 * exist without anything being instantiated or hooked.
	 */
	$dir = $plugin_dir . 'includes/invoice/';

	$files = array(
		'interface-invoice-provider.php',
		'interface-soap-transport.php',
		'class-invoice-exceptions.php',
		'class-edm-fault-classifier.php',
		'class-edm-request-header.php',
		'class-invoice-status.php',
		'class-edm-document-status.php',
		'class-invoice-runtime-gate.php',
		'class-invoice-fixture-guard.php',
		'class-invoice-config.php',
		'class-invoice-result.php',
		'class-edm-soap-transport.php',
		'class-edm-client.php',
		'class-edm-provider.php',
		'class-ubl-tr-builder.php',
		'class-invoice-order-mapper.php',
		'class-invoice-order-store.php',
		'class-invoice-numbering.php',
		'class-invoice-recovery.php',
		'class-internet-sales-details.php',
		'class-invoice-manager.php',
		'class-invoice-status-poller.php',
		'class-invoice-queue.php',
		'class-invoice-admin.php',
	);

	$loaded = 0;
	foreach ( $files as $file ) {
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
		'reason'  => 'loaded_from_edm_plugin',
		'classes' => $loaded,
	);
}
