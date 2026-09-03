<?php
/**
 * Plugin Name: Kuka Island EDM
 * Description: Kuka Island e-Fatura / e-Arşiv entegrasyonu. Varsayılan teslim durumu pasiftir; etkinleştirme ayrı bir kontrol listesiyle yapılır.
 * Version: 0.1.0
 * Author: MepCity
 * Text Domain: kuka-island-edm
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce, kuka-island-core
 *
 * This plugin ships INACTIVE on purpose. Fiscal documents are irreversible, so
 * the delivery state is one where nothing can be issued: while the plugin is
 * inactive WordPress never loads a single file here, which means no hook, no
 * admin screen, no SOAP client, no Action Scheduler job and no order meta.
 * There is nothing to switch off because nothing is switched on.
 *
 * Three operating levels, in increasing order of what they can do:
 *
 *   1. Plugin inactive        -- nothing loads. WooCommerce orders, payments,
 *                                manual invoicing and manual fulfilment behave
 *                                exactly as they do without this plugin.
 *   2. Plugin active,         -- admin screens and controlled manual piloting
 *      KUKA_INVOICE_AUTO_SEND    are available. No automatic invoice queue is
 *      off                       created.
 *   3. Plugin active,         -- the automatic flow runs, and only when every
 *      KUKA_INVOICE_AUTO_SEND    existing gate passes: readiness, payment,
 *      on                        shipment, idempotency and transmission
 *                                evidence.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

define( 'KUKA_ISLAND_EDM_FILE', __FILE__ );
define( 'KUKA_ISLAND_EDM_PATH', plugin_dir_path( __FILE__ ) );

require_once KUKA_ISLAND_EDM_PATH . 'includes/class-activator.php';

register_activation_hook( __FILE__, array( 'Kuka_Island_EDM_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Kuka_Island_EDM_Activator', 'deactivate' ) );

/*
 * Booting waits for plugins_loaded so the dependency check can see whether
 * WooCommerce and Kuka Island Core actually loaded, rather than guessing from
 * the active-plugins option. A missing dependency opens no hook at all.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'kuka-island-edm', false, dirname( plugin_basename( KUKA_ISLAND_EDM_FILE ) ) . '/languages' );

		require_once KUKA_ISLAND_EDM_PATH . 'includes/class-plugin.php';

		Kuka_Island_EDM_Plugin::instance()->boot();
	},
	// After Core (default 10) so its classes exist when the check runs.
	20
);
