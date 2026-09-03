<?php
/**
 * Plugin Name: Kuka Island Shipping Automation
 * Description: Kuka Island taşıyıcıdan bağımsız kargo otomasyonu. İlk adaptör DHL eCommerce Türkiye. Varsayılan teslim durumu pasiftir; otomasyon ayrıca açılır.
 * Version: 0.1.0
 * Author: MepCity
 * Text Domain: kuka-island-shipping-automation
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce, kuka-island-core
 *
 * This plugin ships INACTIVE on purpose. A shipment booked at a carrier is an
 * outward-facing act with a cost attached, so the delivery state is one where
 * nothing can be booked: while the plugin is inactive WordPress never loads a
 * single file here, which means no hook, no admin screen, no HTTP client, no
 * Action Scheduler job and no order meta. There is nothing to switch off
 * because nothing is switched on.
 *
 * Four operating levels, in increasing order of what they can do:
 *
 *   1. Plugin inactive        -- nothing loads. WooCommerce orders, payments,
 *                                manual fulfilment and the manual tracking
 *                                number field behave exactly as they do without
 *                                this plugin.
 *   2. Plugin active,         -- admin panel visible, every carrier call
 *      credentials absent        refused before the network. Read-only and
 *                                write paths alike.
 *   3. Plugin active,         -- an operator may press the carrier's own
 *      credentials present,      "<carrier> gönderisi oluştur" button on one
 *      automation off            order at a time, and continue a half-finished
 *                                one from its barcode stage. Nothing happens on
 *                                its own: no order status hook books anything.
 *   4. Plugin active,         -- the bounded status poller runs for orders that
 *      KUKA_DHL_AUTOMATION       already have a shipment. Even here nothing
 *      on                        CREATES a shipment without an operator.
 *
 * The manual route never closes. At every level an operator can type a tracking
 * number into WooCommerce's own fulfilment drawer and be done.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

define( 'KUKA_ISLAND_SHIPPING_FILE', __FILE__ );
define( 'KUKA_ISLAND_SHIPPING_PATH', plugin_dir_path( __FILE__ ) );

require_once KUKA_ISLAND_SHIPPING_PATH . 'includes/class-activator.php';

register_activation_hook( __FILE__, array( 'Kuka_Island_Shipping_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Kuka_Island_Shipping_Activator', 'deactivate' ) );

/*
 * Booting waits for plugins_loaded so the dependency check can see whether
 * WooCommerce and Kuka Island Core actually loaded, rather than guessing from
 * the active-plugins option. A missing dependency opens no hook at all.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain(
			'kuka-island-shipping-automation',
			false,
			dirname( plugin_basename( KUKA_ISLAND_SHIPPING_FILE ) ) . '/languages'
		);

		require_once KUKA_ISLAND_SHIPPING_PATH . 'includes/class-plugin.php';

		Kuka_Island_Shipping_Plugin::instance()->boot();
	},
	// After Core (default 10) so its classes exist when the check runs.
	20
);
