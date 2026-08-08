<?php
/**
 * Guest-only storefront.
 *
 * The customer decided not to offer accounts, so the store must never ask for
 * one. WooCommerce already keeps a guest cart in `wp_woocommerce_sessions` and
 * matches it with a cookie, so nothing about the cart is reimplemented here
 * (§17.3) — only its lifetime is made panel-controlled.
 *
 * The registration and login options are forced from code as well as seeded,
 * so a stray click in WooCommerce's own settings screen cannot leave the store
 * half-membered. Flipping the panel switch back on releases every one of them.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Membership {
	/** WooCommerce options that must stay off while membership is disabled. */
	private const DISABLED_OPTIONS = array(
		'woocommerce_enable_signup_and_login_from_checkout' => 'no',
		'woocommerce_enable_checkout_login_reminder'        => 'no',
		'woocommerce_enable_myaccount_registration'         => 'no',
		'woocommerce_enable_guest_checkout'                 => 'yes',
	);
	private const ENABLED_OPTIONS = array(
		'woocommerce_enable_signup_and_login_from_checkout' => 'yes',
		'woocommerce_enable_checkout_login_reminder'        => 'yes',
		'woocommerce_enable_myaccount_registration'         => 'yes',
		'woocommerce_enable_guest_checkout'                 => 'yes',
	);

	public static function enabled(): bool {
		return (bool) ( Kuka_Island_Core_Site_Appearance::get()['membership']['enabled'] ?? false );
	}

	public function register(): void {
		add_filter( 'wc_session_expiring', array( $this, 'session_expiring' ) );
		add_filter( 'wc_session_expiration', array( $this, 'session_expiration' ) );

		foreach ( array_keys( self::DISABLED_OPTIONS ) as $option ) {
			add_filter( 'pre_option_' . $option, array( $this, 'forced_option' ), 10, 3 );
		}
		add_filter( 'pre_option_users_can_register', static fn(): string => self::enabled() ? '1' : '0' );

		if ( self::enabled() ) {
			return;
		}

		add_filter( 'option_woocommerce_registration_generate_username', static fn(): string => 'yes' );

		add_action( 'template_redirect', array( $this, 'redirect_account_page' ) );
		add_filter( 'woocommerce_checkout_registration_enabled', '__return_false', 99 );
		add_filter( 'woocommerce_checkout_registration_required', '__return_false', 99 );
		add_filter( 'woocommerce_checkout_show_terms', '__return_true' );
		add_action( 'init', array( $this, 'strip_checkout_login_prompt' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_tracking_link' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_tracking_link' ), 20, 4 );
	}

	/**
	 * @param mixed  $pre    Short-circuit value.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public function forced_option( $pre, string $option ) {
		$values = self::enabled() ? self::ENABLED_OPTIONS : self::DISABLED_OPTIONS;
		return $values[ $option ] ?? $pre;
	}

	/** The panel owns how long an abandoned guest cart survives. */
	public function session_expiration(): int {
		$hours = absint( Kuka_Island_Core_Site_Appearance::get()['membership']['guest_session_hours'] ?? 48 );
		return max( 1, $hours ) * HOUR_IN_SECONDS;
	}

	/** WooCommerce refreshes the session one hour before it expires. */
	public function session_expiring(): int {
		return max( HOUR_IN_SECONDS, $this->session_expiration() - HOUR_IN_SECONDS );
	}

	/**
	 * `/hesabim/` stays published because WooCommerce reads
	 * `woocommerce_myaccount_page_id` in several internal paths; visiting it
	 * simply lands on the home page.
	 */
	public function redirect_account_page(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || is_admin() ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}

	/** Remove WooCommerce's "Returning customer? Log in" prompt above checkout. */
	public function strip_checkout_login_prompt(): void {
		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
	}

	/**
	 * Without accounts the order confirmation and its e-mail are the customer's
	 * only handle on the order, so both carry the order-tracking link.
	 *
	 * @param WC_Order $order Order being displayed.
	 */
	public function order_tracking_link( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		printf(
			'<p class="kuka-order-tracking"><a href="%1$s">%2$s</a></p>',
			esc_url( $this->tracking_url( $order ) ),
			esc_html__( 'Siparişinizi sipariş numarası ve e-posta adresinizle takip edin', 'kuka-island-core' )
		);
	}

	/**
	 * Add a personalized link to every customer order e-mail. The standard
	 * tracking form still validates the order number and billing e-mail; the
	 * query merely pre-fills both values.
	 *
	 * @param WC_Order $order Order represented by the e-mail.
	 */
	public function email_tracking_link( $order, bool $sent_to_admin ): void {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}
		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( $this->tracking_url( $order ) ),
			esc_html__( 'Siparişinizi sipariş numaranız ve e-posta adresinizle takip edin', 'kuka-island-core' )
		);
	}

	private function tracking_url( WC_Order $order ): string {
		return add_query_arg(
			array(
				'orderid'    => $order->get_order_number(),
				'order_email' => $order->get_billing_email(),
			),
			home_url( '/siparis-takibi/' )
		);
	}
}
