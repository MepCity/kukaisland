<?php
/** Behavioural verification for the public contact form; never reaches SMTP. */

defined( 'WP_CLI' ) || exit( 1 );

if ( ! class_exists( PHPMailer\PHPMailer\PHPMailer::class ) ) {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
}

$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$form = new Kuka_Island_Core_Contact_Form();
$captured = array();
$mail_result = true;
$mail_filter = static function ( $short_circuit, array $atts ) use ( &$captured, &$mail_result ) {
	unset( $short_circuit );
	$captured[] = $atts;
	return $mail_result;
};
add_filter( 'pre_wp_mail', $mail_filter, -2000, 2 );

$run_id = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
$ip = '198.51.100.' . (string) ( 10 + random_int( 0, 89 ) );
$email = 'contact-' . $run_id . '@example.com';
$rate_method = new ReflectionMethod( Kuka_Island_Core_Contact_Form::class, 'rate_key' );
$rate_method->setAccessible( true );
$rate_keys = array();
$rate_keys[] = $rate_method->invoke( null, 'ip', $ip );
$rate_keys[] = $rate_method->invoke( null, 'pair', $ip . '|' . strtolower( $email ) );

$valid = $form->process(
	array( 'email' => $email, 'subject' => 'Beden seçimi', 'message' => "Merhaba, beden seçimi konusunda bilgi rica ediyorum.\nTeşekkürler." ),
	$ip,
	'tr'
);
$assert( 'success' === $valid, 'valid submission was not accepted' );
$assert( 1 === count( $captured ), 'valid submission did not produce exactly one mail attempt' );
$content = Kuka_Island_Core_Site_Appearance::get();
$recipient = sanitize_email( (string) ( $content['brand']['email'] ?? '' ) );
$sent = $captured[0] ?? array();
$headers = implode( "\n", (array) ( $sent['headers'] ?? array() ) );
$assert( $recipient === ( $sent['to'] ?? '' ), 'recipient does not use the brand email source' );
$assert( str_contains( $headers, 'Reply-To: ' . $email ), 'visitor email is not the Reply-To header' );
$assert( ! str_contains( $headers, 'From:' ), 'visitor email was allowed to become the sender' );
$assert( str_contains( (string) ( $sent['subject'] ?? '' ), 'Beden seçimi' ), 'subject was not preserved' );
$assert( str_contains( (string) ( $sent['message'] ?? '' ), 'beden seçimi' ), 'message was not preserved' );

$repeat = $form->process( array( 'email' => $email, 'subject' => 'Tekrar', 'message' => 'Bu istek oran sınırına takılmalıdır.' ), $ip, 'tr' );
$assert( 'rate' === $repeat, 'pair rate limit did not refuse the immediate repeat' );
$assert( 1 === count( $captured ), 'rate-limited submission reached mail transport' );

$before_invalid = count( $captured );
$invalid_cases = array(
	array( 'email' => "victim@example.com\r\nBcc: leak@example.com", 'subject' => 'Konu', 'message' => 'Geçerli uzunlukta mesaj.' ),
	array( 'email' => 'valid@example.com', 'subject' => "Konu\r\nBcc: leak@example.com", 'message' => 'Geçerli uzunlukta mesaj.' ),
	array( 'email' => 'not-an-email', 'subject' => 'Konu', 'message' => 'Geçerli uzunlukta mesaj.' ),
	array( 'email' => 'valid@example.com', 'subject' => '', 'message' => 'Geçerli uzunlukta mesaj.' ),
	array( 'email' => 'valid@example.com', 'subject' => 'Konu', 'message' => '' ),
	array( 'email' => array( 'valid@example.com' ), 'subject' => 'Konu', 'message' => 'Geçerli uzunlukta mesaj.' ),
);
foreach ( $invalid_cases as $case ) {
	$assert( 'invalid' === $form->process( $case, '203.0.113.9', 'tr' ), 'invalid input was accepted' );
}
$assert( $before_invalid === count( $captured ), 'invalid input reached mail transport' );

$failure_ip = '203.0.113.41';
$failure_email = 'failure-' . $run_id . '@example.com';
$rate_keys[] = $rate_method->invoke( null, 'ip', $failure_ip );
$rate_keys[] = $rate_method->invoke( null, 'pair', $failure_ip . '|' . strtolower( $failure_email ) );
$mail_result = false;
$failure = $form->process( array( 'email' => $failure_email, 'subject' => 'Teslim testi', 'message' => 'Taşıyıcının reddi güvenli bir sonuç olmalıdır.' ), $failure_ip, 'tr' );
$assert( 'error' === $failure, 'mail refusal was not surfaced as an error' );
$assert( $before_invalid + 1 === count( $captured ), 'mail refusal was retried or skipped' );
$mail_result = true;

$form_html = $form->form();
$assert( str_contains( $form_html, 'kuka_contact_nonce' ), 'form has no nonce' );
$assert( str_contains( $form_html, 'name="company"' ), 'form has no honeypot' );
$assert( str_contains( $form_html, 'name="email"' ) && str_contains( $form_html, 'name="subject"' ) && str_contains( $form_html, 'name="message"' ), 'required fields are missing' );
$assert( str_contains( $form_html, 'maxlength="120"' ) && str_contains( $form_html, 'maxlength="4000"' ), 'browser length limits are missing' );

$page = get_page_by_path( 'iletisim' );
$tr_content = $page instanceof WP_Post ? (string) $page->post_content : '';
$en_content = $page instanceof WP_Post ? (string) get_post_meta( $page->ID, '_kuka_content_en', true ) : '';
$assert( 1 === substr_count( $tr_content, '[kuka_contact_form]' ), 'Turkish contact page is not migrated exactly once' );
$assert( 1 === substr_count( $en_content, '[kuka_contact_form]' ), 'English contact page is not migrated exactly once' );
$assert( ! str_contains( $tr_content . $en_content, 'kuka-service-disabled' ), 'retired disabled marker remains' );

$mailer = new PHPMailer\PHPMailer\PHPMailer( true );
$reply_property = new ReflectionProperty( Kuka_Island_Core_Contact_Form::class, 'reply_to' );
$reply_property->setAccessible( true );
$reply_property->setValue( null, array( 'email' => $email, 'name' => $email ) );
$mailer->addReplyTo( 'fallback@kukaisland.com', 'Fallback' );
$form->apply_reply_to( $mailer );
$reply_property->setValue( null, null );
$reply_addresses = $mailer->getReplyToAddresses();
$reply_emails = array_map(
	static fn ( array $address ): string => strtolower( (string) ( $address[0] ?? '' ) ),
	$reply_addresses
);
$assert( in_array( strtolower( $email ), $reply_emails, true ), 'contact Reply-To did not win at phpmailer_init' );
$assert( ! in_array( 'fallback@kukaisland.com', $reply_emails, true ), 'fallback Reply-To remained beside the visitor address' );

$secret_leaks = 0;
$surfaces = serialize( array( $captured, $form_html, $failures ) );
foreach ( array( 'KUKA_SMTP_PASSWORD' ) as $constant ) {
	if ( defined( $constant ) && '' !== (string) constant( $constant ) && str_contains( $surfaces, (string) constant( $constant ) ) ) {
		++$secret_leaks;
	}
}
if ( defined( 'KUKA_SMTP_USERNAME' ) && '' !== (string) KUKA_SMTP_USERNAME && str_contains( $form_html . serialize( $failures ), (string) KUKA_SMTP_USERNAME ) ) {
	++$secret_leaks;
}
$assert( 0 === $secret_leaks, 'SMTP secret leaked into a contact surface' );

remove_filter( 'pre_wp_mail', $mail_filter, -2000 );
foreach ( array_unique( $rate_keys ) as $rate_key ) {
	delete_transient( $rate_key );
}

$report = 'CONTACT_FORM_DELIVERY=' . ( $failures ? 'FAIL' : 'PASS' )
	. '|valid:' . $valid
	. '|mail_attempts:' . count( $captured )
	. '|recipient:brand_source'
	. '|reply_to:visitor'
	. '|repeat:' . $repeat
	. '|invalid_refused:' . count( $invalid_cases ) . '/' . count( $invalid_cases )
	. '|failure:' . $failure
	. '|nonce:yes|honeypot:yes'
	. '|page_shortcodes:tr' . substr_count( $tr_content, '[kuka_contact_form]' ) . '+en' . substr_count( $en_content, '[kuka_contact_form]' )
	. '|secret_leaks:' . $secret_leaks;
WP_CLI::line( $report );

if ( $failures ) {
	foreach ( $failures as $failure_message ) {
		WP_CLI::warning( $failure_message );
	}
	WP_CLI::error( 'Contact form verification failed.' );
}
WP_CLI::success( 'Contact form delivery verified without reaching SMTP.' );
