<?php
/**
 * WooCommerce'in yerleşik sipariş karşılama ve kargo takip özelliği.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Fulfillments {
	private const SETUP_OPTION  = 'kuka_island_fulfillments_setup_version';
	private const SETUP_VERSION = 1;

	/**
	 * Özellik seçeneği WooCommerce'in init:10 kontrolünden önce yazılmalıdır.
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'install' ), 5 );
	}

	/**
	 * WooCommerce tablo şemasına doğrudan müdahale etmez. Özellik açılınca
	 * çekirdeğin FulfillmentsController sınıfı tabloları dbDelta ile kurar.
	 */
	public static function install(): void {
		if ( 'yes' !== get_option( 'woocommerce_feature_fulfillments_enabled', 'no' ) ) {
			update_option( 'woocommerce_feature_fulfillments_enabled', 'yes', true );
		}

		if ( self::SETUP_VERSION === (int) get_option( self::SETUP_OPTION, 0 ) ) {
			return;
		}

		// Yeni gönderim, gönderim güncellemesi ve iptali bildirimleri varsayılan
		// olarak açık gelir. Varsa satıcının konu/başlık ayarlarını koruruz.
		$email_ids = array(
			'customer_fulfillment_created',
			'customer_fulfillment_updated',
			'customer_fulfillment_deleted',
		);

		foreach ( $email_ids as $email_id ) {
			$option_key = 'woocommerce_' . $email_id . '_settings';
			$settings   = get_option( $option_key, array() );
			$settings   = is_array( $settings ) ? $settings : array();

			if ( ! array_key_exists( 'enabled', $settings ) ) {
				$settings['enabled'] = 'yes';
				update_option( $option_key, $settings, true );
			}
		}

		update_option( self::SETUP_OPTION, self::SETUP_VERSION, true );
	}
}
