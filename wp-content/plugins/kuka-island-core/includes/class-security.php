<?php
/**
 * Public response hardening and the RFC 9116 security contact.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Security {
	public function register(): void {
		add_action( 'init', array( $this, 'serve_security_txt' ), 0 );
		add_action( 'init', array( $this, 'send_security_headers' ), 1 );
	}

	public function send_security_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff', true );
		header( 'Referrer-Policy: strict-origin-when-cross-origin', true );
		header( 'X-Frame-Options: SAMEORIGIN', true );
		header( 'Permissions-Policy: camera=(), geolocation=(), microphone=(), browsing-topics=()', true );

		// Yönetim ve AJAX ekranları üçüncü taraf eklentilerin kendi betiklerini
		// yükler. CSP yalnız müşteri yüzeyinde uygulanır ve iyzico kaynaklarını
		// açıkça sınırlar.
		if ( ! is_admin() ) {
			header( 'Content-Security-Policy: ' . self::content_security_policy(), true );
		}

		// Yerel kurulum HTTP'dir. HSTS yalnız üretim HTTPS yanıtında düşük bir
		// başlangıç süresiyle açılır; alt alan adları ve preload kapsam dışıdır.
		if ( is_ssl() && 'local' !== wp_get_environment_type() ) {
			header( 'Strict-Transport-Security: max-age=300', true );
		}
	}

	public static function content_security_policy(): string {
		$directives = array(
			"default-src 'self'",
			"base-uri 'self'",
			"object-src 'none'",
			"frame-ancestors 'self'",
			"form-action 'self' https://iyzipay.com https://*.iyzipay.com https://iyzico.com https://*.iyzico.com",
			"script-src 'self' 'unsafe-inline' https://iyzipay.com https://*.iyzipay.com https://iyzico.com https://*.iyzico.com",
			"style-src 'self' 'unsafe-inline'",
			"img-src 'self' data: blob: https://iyzipay.com https://*.iyzipay.com https://iyzico.com https://*.iyzico.com",
			"font-src 'self' data:",
			"connect-src 'self' https://iyzipay.com https://*.iyzipay.com https://iyzico.com https://*.iyzico.com",
			"frame-src 'self' https://iyzipay.com https://*.iyzipay.com https://iyzico.com https://*.iyzico.com",
			"media-src 'self' blob:",
			"worker-src 'self' blob:",
			"manifest-src 'self'",
		);

		if ( is_ssl() ) {
			$directives[] = 'upgrade-insecure-requests';
		}

		return implode( '; ', $directives );
	}

	public function serve_security_txt(): void {
		$request_path = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '/.well-known/security.txt' !== $request_path ) {
			return;
		}

		$appearance = class_exists( 'Kuka_Island_Core_Site_Appearance' ) ? Kuka_Island_Core_Site_Appearance::get() : array();
		$email      = sanitize_email( (string) ( $appearance['brand']['email'] ?? 'info@kukaisland.com' ) );
		if ( '' === $email ) {
			$email = 'info@kukaisland.com';
		}

		status_header( 200 );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'Content-Type: text/plain; charset=utf-8', true );
		header( 'Cache-Control: public, max-age=86400', true );
		echo 'Contact: mailto:' . $email . "\n";
		echo 'Expires: ' . gmdate( 'Y-m-d\\TH:i:s\\Z', time() + YEAR_IN_SECONDS ) . "\n";
		echo 'Preferred-Languages: tr, en' . "\n";
		echo 'Canonical: ' . esc_url_raw( home_url( '/.well-known/security.txt' ) ) . "\n";
		exit;
	}
}
