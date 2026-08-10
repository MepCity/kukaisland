<?php
/**
 * Isolated e-mail acceptance checks. Run inside the WordPress container.
 */

$mode = $argv[1] ?? 'throwables';

if ( 'smtp' === $mode ) {
	define( 'KUKA_SMTP_HOST', 'smtp.' . 'example.test' );
	define( 'KUKA_SMTP_PORT', 587 );
	define( 'KUKA_SMTP_USERNAME', 'acceptance-' . 'user' );
	define( 'KUKA_SMTP_PASSWORD', hash( 'sha256', __FILE__ ) );
	define( 'KUKA_SMTP_ENCRYPTION', 'tls' );
	define( 'KUKA_SMTP_FROM_NAME', 'Kuka ' . 'Island' );
	define( 'KUKA_SMTP_REPLY_TO_EMAIL', 'destek@' . 'kukaisland.com' );
	define( 'KUKA_SMTP_REPLY_TO_NAME', 'Kuka ' . 'Island Destek' );
}

require '/var/www/html/wp-load.php';

if ( 'smtp' === $mode ) {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	$mailer = new PHPMailer\PHPMailer\PHPMailer( true );
	do_action( 'phpmailer_init', $mailer );
	echo 'SMTP_TRANSPORT=' . ( 'smtp' === $mailer->Mailer ? 'smtp' : $mailer->Mailer ) . PHP_EOL;
	$wp_from = apply_filters( 'wp_mail_from', 'wordpress@example.test' );
	$woo_from = apply_filters( 'woocommerce_email_from_address', 'store@example.test' );
	echo 'SMTP_IDENTITY=' . ( 'info@kukaisland.com' === $wp_from && 'Kuka Island' === apply_filters( 'wp_mail_from_name', 'WordPress' ) ? 'fixed' : 'invalid' ) . PHP_EOL;
	echo 'MAIL_FROM_IDENTITIES=' . ( $wp_from === $woo_from ? 'wp=woo:' . $wp_from : 'diverged' ) . PHP_EOL;
	$reply_to_addresses = $mailer->getReplyToAddresses();
	echo 'SMTP_REPLY_TO=' . ( in_array( 'destek@kukaisland.com', array_column( $reply_to_addresses, 0 ), true ) ? 'separate' : 'missing' ) . PHP_EOL;
	exit;
}

add_filter( 'wp_mail_from', static fn(): string => 'acceptance@example.test', -999 );
$delivery = new Kuka_Island_Core_Email_Delivery();
$order = wc_create_order();
$order->set_billing_email( 'acceptance@example.test' );
$order->save();

if ( 'disabled-mail' === $mode ) {
	$delivery->capture_order_context( '', 'new_order', $order );
	$sent = wp_mail( 'acceptance@example.test', 'Disabled mail acceptance', 'No message is sent.' );
	$order = wc_get_order( $order->get_id() );
	$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
	$note_text = implode( ' ', wp_list_pluck( $notes, 'content' ) );
	$warning_text = wp_json_encode( $delivery->add_operator_warnings( array() ), JSON_UNESCAPED_UNICODE );
	echo 'PHP_MAIL_FUNCTION=' . ( function_exists( 'mail' ) ? 'enabled' : 'disabled' ) . PHP_EOL;
	echo 'DISABLED_MAIL_SAFE=' . ( false === $sent ? 'yes' : 'no' ) . PHP_EOL;
	echo 'DISABLED_MAIL_ORDER_NOTE=' . ( str_contains( $note_text, 'Sipariş e-postası gönderilemedi:' ) ? 'yes' : 'no' ) . PHP_EOL;
	echo 'DISABLED_MAIL_START_WARNING=' . ( str_contains( $warning_text, 'PHP mail() kapalı' ) ? 'yes' : 'no' ) . PHP_EOL;
	echo 'FAILED_EMAIL_START_WARNING=' . ( str_contains( $warning_text, '1 gönderilemeyen sipariş e-postası var' ) ? 'yes' : 'no' ) . PHP_EOL;
	$order->delete( true );
	exit;
}

$caught = array();
foreach ( array( 'Exception', 'Error' ) as $type ) {
	$delivery->capture_order_context( '', strtolower( $type ) . '_email', $order );
	$thrower = static function () use ( $type ): void {
		if ( 'Error' === $type ) {
			throw new Error( 'Forced transport Error' );
		}
		throw new RuntimeException( 'Forced transport Exception' );
	};
	add_action( 'phpmailer_init', $thrower, 999 );
	$caught[ $type ] = false === wp_mail( 'acceptance@example.test', $type . ' acceptance', 'No message is sent.' );
	remove_action( 'phpmailer_init', $thrower, 999 );
}

$order = wc_get_order( $order->get_id() );
$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
$note_text = implode( ' ', wp_list_pluck( $notes, 'content' ) );
echo 'THROWABLE_EXCEPTION_CAUGHT=' . ( $caught['Exception'] ? 'yes' : 'no' ) . PHP_EOL;
echo 'THROWABLE_ERROR_CAUGHT=' . ( $caught['Error'] ? 'yes' : 'no' ) . PHP_EOL;
echo 'THROWABLE_ORDER_NOTES=' . ( str_contains( $note_text, 'Forced transport Exception' ) && str_contains( $note_text, 'Forced transport Error' ) ? '2/2' : 'missing' ) . PHP_EOL;
echo 'ORDER_RESEND_ACTIONS=' . ( class_exists( 'WC_Meta_Box_Order_Actions' ) ? 'customer+admin' : 'missing' ) . PHP_EOL;
$order->delete( true );
