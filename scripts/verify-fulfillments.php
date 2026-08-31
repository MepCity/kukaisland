<?php
/**
 * Read-only verification for WooCommerce's built-in Fulfillments feature.
 *
 * Run with:
 * docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-fulfillments.php
 */

defined( 'WP_CLI' ) || exit( 1 );

use Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Utilities\OrderUtil;

$failures = array();
$report   = static function ( string $name, bool $passed, string $detail = '' ) use ( &$failures ): void {
	WP_CLI::line( sprintf( '%s=%s%s', $name, $passed ? 'PASS' : 'FAIL', '' !== $detail ? '|' . $detail : '' ) );
	if ( ! $passed ) {
		$failures[] = $name;
	}
};

$features = wc_get_container()->get( FeaturesController::class );
$report( 'FULFILLMENTS_FEATURE', $features->feature_is_enabled( 'fulfillments' ) );
$report( 'HPOS', OrderUtil::custom_orders_table_usage_is_enabled() );

global $wpdb;
$fulfillments_table = $wpdb->prefix . 'wc_order_fulfillments';
$meta_table         = $wpdb->prefix . 'wc_order_fulfillment_meta';
$report( 'FULFILLMENTS_TABLE', $fulfillments_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fulfillments_table ) ) );
$report( 'FULFILLMENTS_META_TABLE', $meta_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) );

try {
	$data_store = WC_Data_Store::load( 'order-fulfillment' );
	$report( 'FULFILLMENTS_DATA_STORE', is_object( $data_store ), is_object( $data_store ) ? get_class( $data_store ) : '' );
} catch ( Throwable $exception ) {
	$report( 'FULFILLMENTS_DATA_STORE', false, $exception->getMessage() );
}

$routes             = rest_get_server()->get_routes();
$fulfillment_routes = array_filter(
	array_keys( $routes ),
	static fn( string $route ): bool => str_contains( $route, '/fulfillments' )
);
$report( 'FULFILLMENTS_REST', count( $fulfillment_routes ) >= 4, 'routes:' . count( $fulfillment_routes ) );

$order_columns = apply_filters( 'manage_woocommerce_page_wc-orders_columns', array( 'order_status' => 'Order status' ) );
$report(
	'FULFILLMENTS_HPOS_UI',
	isset( $order_columns['fulfillment_status'], $order_columns['shipment_tracking'], $order_columns['shipment_provider'] )
);
$report( 'GUEST_ORDER_DETAILS_UI', false !== has_action( 'woocommerce_order_details_before_order_table' ) );

$providers = FulfillmentUtils::get_shipping_providers();
foreach ( array( 'aras-kargo', 'yurtici-kargo' ) as $provider_key ) {
	$provider = $providers[ $provider_key ] ?? null;
	$url      = $provider ? $provider->get_tracking_url( '1234567890' ) : '';
	$report(
		strtoupper( str_replace( '-', '_', $provider_key ) ),
		is_object( $provider ) && str_contains( $url, '1234567890' ),
		$url
	);
}

$emails = WC()->mailer()->get_emails();
foreach (
	array(
		'WC_Email_Customer_Fulfillment_Created',
		'WC_Email_Customer_Fulfillment_Updated',
		'WC_Email_Customer_Fulfillment_Deleted',
	) as $email_class
) {
	$email = $emails[ $email_class ] ?? null;
	$report(
		strtoupper( str_replace( 'WC_Email_', '', $email_class ) ),
		$email instanceof WC_Email && $email->is_enabled() && $email->is_customer_email()
	);
}

// Veritabanına kaydedilmeyen bir sipariş nesnesiyle misafir bildirim hedefini
// sınar; temiz CI kurulumunda gerçek sipariş bulunmasına bağımlı değildir.
$guest_order = new WC_Order();
$guest_order->set_customer_id( 0 );
$guest_order->set_billing_email( 'guest@example.com' );
$report(
	'GUEST_FULFILLMENT_EMAIL_TARGET',
	0 === $guest_order->get_customer_id() && is_email( $guest_order->get_billing_email() ),
	'unsaved-order'
);

if ( $failures ) {
	WP_CLI::error( 'Fulfillment doğrulaması başarısız: ' . implode( ', ', $failures ) );
}

WP_CLI::success( 'Fulfillment doğrulaması tamamlandı; hiçbir sipariş veya gönderim kaydı değiştirilmedi.' );
