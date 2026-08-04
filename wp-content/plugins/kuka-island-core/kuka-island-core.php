<?php
/**
 * Plugin Name: Kuka Island Core
 * Description: Kuka Island mağazasının tema bağımsız veri ve entegrasyon iskeleti.
 * Version: 0.1.0
 * Author: MepCity
 * Text Domain: kuka-island-core
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'KUKA_ISLAND_CORE_FILE', __FILE__ );
define( 'KUKA_ISLAND_CORE_PATH', plugin_dir_path( __FILE__ ) );

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'kuka-island-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

require_once KUKA_ISLAND_CORE_PATH . 'includes/class-plugin.php';
require_once KUKA_ISLAND_CORE_PATH . 'includes/class-activator.php';

register_activation_hook( __FILE__, array( 'Kuka_Island_Core_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Kuka_Island_Core_Activator', 'deactivate' ) );

Kuka_Island_Core_Plugin::instance()->boot();
