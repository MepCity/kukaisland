<?php
/**
 * Update-safe wrapper around the iyzico webhook route and return callback.
 *
 * The gateway acts on every delivery unconditionally: a repeated
 * CHECKOUT_FORM_AUTH re-adds the confirmation note and calls
 * update_status( 'processing' ) again, which drags an already completed order
 * backwards and — with an installment fee present — appends a second fee line.
 * Its REST callback also discards the WP_Error returned by the signature check,
 * so an invalid signature answers 200.
 *
 * This wrapper sits in front of the vendor flow and never edits a vendor file:
 *
 *   1. The V3 signature is verified first, in constant time.
 *   2. The payment is then verified against iyzico itself. Neither the webhook
 *      body nor the gateway's local row is treated as proof of payment; the
 *      vendor mutation only runs once the API agrees on token, conversation id,
 *      basket id, status, payment status, payment id, paid price and currency.
 *   3. Nothing is recorded before the work happens, and a delivery only counts
 *      as processed once the order and the gateway row both confirm it.
 *   4. Concurrency is settled by one connection-scoped MariaDB advisory lock per
 *      **order**, so the webhook and the browser callback of the same payment
 *      queue behind each other instead of running side by side. A timed claim
 *      could not work here — PHP runs with max_execution_time = 0, so a request
 *      may outlive any TTL — while GET_LOCK has no expiry at all and the server
 *      frees it the moment the holder's connection ends.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Iyzico_Idempotency {
	/** Durable per-order record of deliveries that completed successfully. */
	public const PROCESSED_META = '_kuka_iyzico_processed_events';

	/** Deliveries that already produced their single audit note. */
	public const NOTED_META = '_kuka_iyzico_noted_events';

	/**
	 * Prefix of the connection-scoped advisory lock that serialises a payment.
	 *
	 * `kuka_iyz_` plus a sha1 is 49 characters, inside MariaDB's 64 character
	 * limit for user level lock names.
	 */
	public const LOCK_PREFIX = 'kuka_iyz_';

	/** The only webhook event this storefront receives and guards. */
	public const SUPPORTED_EVENT = 'CHECKOUT_FORM_AUTH';

	private const REQUIRED_PARAMS = array( 'iyziEventType', 'iyziPaymentId', 'token', 'paymentConversationId', 'status' );

	/**
	 * Statuses that mean the payment result is already on the order.
	 *
	 * `cancelled` is deliberately absent: a cancelled order is not a paid one,
	 * and treating it as settled would swallow a genuine retry.
	 */
	private const PAID_STATUSES = array( 'processing', 'on-hold', 'completed', 'refunded' );

	private const WEBHOOK_ROUTE = '/iyzico/v1/webhook/';

	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_webhook' ), 10, 3 );
		// The gateway hooks woocommerce_api_request at the default priority, so
		// priority 1 lets the guard decide before the callback is processed.
		add_action( 'woocommerce_api_request', array( $this, 'guard_callback' ), 1 );
		add_action( 'template_redirect', array( $this, 'serve_status_probe' ), 1 );
	}

	/* --------------------------------------------------------------------- */
	/* Webhook                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Verify, guard and run one signed webhook delivery.
	 *
	 * @param mixed           $result  Existing pre-dispatch result.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Incoming request.
	 * @return mixed Untouched result, a WP_Error or a WP_REST_Response.
	 */
	public function guard_webhook( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		if ( ! str_starts_with( (string) $request->get_route(), self::WEBHOOK_ROUTE ) ) {
			return $result;
		}

		$signature = self::signature_header( $request );
		if ( '' === $signature ) {
			// Unsigned legacy delivery: out of scope, the gateway keeps it.
			return $result;
		}

		$params = (array) $request->get_json_params();
		foreach ( self::REQUIRED_PARAMS as $param ) {
			if ( empty( $params[ $param ] ) ) {
				return new WP_Error(
					'kuka_iyzico_missing_param',
					__( 'iyzico bildiriminde zorunlu alan eksik.', 'kuka-island-core' ),
					array( 'status' => 400 )
				);
			}
		}

		$event           = strtoupper( sanitize_text_field( (string) $params['iyziEventType'] ) );
		$payment_id      = sanitize_text_field( (string) $params['iyziPaymentId'] );
		$token           = sanitize_text_field( (string) $params['token'] );
		$conversation_id = sanitize_text_field( (string) $params['paymentConversationId'] );
		$status          = sanitize_text_field( (string) $params['status'] );

		if ( ! self::signature_is_valid( $event, $payment_id, $token, $conversation_id, $status, $signature ) ) {
			// The signature itself is never echoed, logged or stored.
			return new WP_Error(
				'kuka_iyzico_invalid_signature',
				__( 'iyzico bildiriminin imzası doğrulanamadı.', 'kuka-island-core' ),
				array( 'status' => 401 )
			);
		}

		if ( self::SUPPORTED_EVENT !== $event ) {
			// Credit, BKM and bank-transfer events are not guarded here; the
			// gateway keeps its own behaviour for them.
			return $result;
		}

		$order = self::order_for_token( $token );
		if ( ! $order ) {
			return $result;
		}

		$event_key = self::event_key( 'webhook', array( $event, $status, $payment_id, $token ) );
		$processed = self::processed_events( $order );

		if ( isset( $processed[ $event_key ] ) ) {
			self::note_duplicate_once( $order, $event_key );
			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}

		if ( self::payment_id_conflicts( $token, $payment_id, $processed ) ) {
			return new WP_Error(
				'kuka_iyzico_payment_mismatch',
				__( 'iyzico bildirimindeki ödeme kimliği sipariş kaydıyla uyuşmuyor.', 'kuka-island-core' ),
				array( 'status' => 409 )
			);
		}

		$order_id = $order->get_id();
		$lock_key = self::payment_lock_key( $order_id );

		// One lock per order, shared with the browser callback: the two channels
		// carry different identifiers for the same payment, so a per-delivery
		// lock would let both of them into the vendor mutation at once.
		if ( ! self::acquire_lock( $lock_key ) ) {
			// Another request is inside this order's payment right now. The lock
			// has no expiry, so no amount of elapsed time lets this request take
			// over. Answering 2xx here would tell iyzico the event was handled
			// before it actually was.
			return new WP_Error(
				'kuka_iyzico_in_progress',
				__( 'Aynı siparişin ödemesi şu anda işleniyor, lütfen yeniden deneyin.', 'kuka-island-core' ),
				array( 'status' => 409 )
			);
		}

		$released = false;
		// The gateway can exit() mid-flow. MariaDB frees the lock when the
		// connection ends, and this releases it earlier on a clean shutdown.
		register_shutdown_function(
			static function () use ( &$released, $lock_key ): void {
				if ( ! $released ) {
					self::release_lock( $lock_key );
				}
			}
		);

		// Everything read before the lock may be stale by now: re-read the order,
		// the gateway row and the processed record before deciding anything.
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			self::release_lock( $lock_key );
			$released = true;
			return $result;
		}
		$processed = self::processed_events( $order );

		if ( isset( $processed[ $event_key ] ) ) {
			self::release_lock( $lock_key );
			$released = true;
			self::note_duplicate_once( $order, $event_key );
			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}

		if ( self::order_payment_confirmed( $order, $token, $payment_id ) ) {
			// The other channel already settled this very payment. Complete the
			// record instead of running the vendor mutation a second time.
			self::mark_processed( $order, $event_key, $payment_id, 'webhook' );
			self::note_duplicate_once( $order, $event_key );
			self::release_lock( $lock_key );
			$released = true;
			return new WP_REST_Response( array( 'status' => 'already_processed' ), 200 );
		}

		if ( self::order_is_paid( $order ) || self::payment_id_conflicts( $token, $payment_id, $processed ) ) {
			// The order is settled by a different payment, or this delivery names
			// one. Either way a second mutation would double the stock movement.
			self::release_lock( $lock_key );
			$released = true;
			return new WP_Error(
				'kuka_iyzico_payment_mismatch',
				__( 'Sipariş başka bir ödemeyle kesinleşmiş; bu bildirim uygulanmadı.', 'kuka-island-core' ),
				array( 'status' => 409 )
			);
		}

		// Authoritative check. Until iyzico itself confirms this payment against
		// this order, nothing touches the order, the stock or the gateway row.
		$verification = self::verify_payment_with_iyzico(
			array(
				'token'            => $token,
				'conversation_id'  => (string) self::provider_field( $token, 'conversation_id' ),
				'order_id'         => (string) $order_id,
				'payment_id'       => $payment_id,
				'expected_total'   => (float) $order->get_total(),
				'expected_currency' => (string) $order->get_currency(),
			)
		);

		if ( empty( $verification['ok'] ) ) {
			self::release_lock( $lock_key );
			$released = true;
			return new WP_Error(
				'kuka_iyzico_unverified_payment',
				__( 'Ödeme iyzico tarafında doğrulanamadı; sipariş değiştirilmedi.', 'kuka-island-core' ),
				array( 'status' => 502, 'reason' => (string) ( $verification['reason'] ?? 'unverified' ) )
			);
		}

		try {
			self::run_vendor_webhook( $event, $token, $conversation_id, $status );
		} catch ( Throwable $throwable ) {
			self::release_lock( $lock_key );
			$released = true;
			return new WP_Error(
				'kuka_iyzico_processing_failed',
				__( 'iyzico bildirimi işlenemedi.', 'kuka-island-core' ),
				array( 'status' => 500 )
			);
		}

		// The gateway never writes the payment id on the webhook path, which
		// would leave a refund without one. The verified API values are stored
		// here so the row and the payment agree.
		self::store_verified_payment( $token, $verification );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! self::order_payment_confirmed( $order, $token, $payment_id ) ) {
			// A 200 or a null return from the gateway proves nothing.
			self::release_lock( $lock_key );
			$released = true;
			return new WP_Error(
				'kuka_iyzico_not_settled',
				__( 'iyzico bildirimi sonrasında sipariş ödenmiş duruma geçmedi.', 'kuka-island-core' ),
				array( 'status' => 500 )
			);
		}

		self::mark_processed( $order, $event_key, $payment_id, 'webhook' );
		self::release_lock( $lock_key );
		$released = true;

		return new WP_REST_Response( array( 'status' => 'processed' ), 200 );
	}

	/** Call the gateway's own signed-webhook handler. */
	private static function run_vendor_webhook( string $event, string $token, string $conversation_id, string $status ): void {
		$processor_class = '\\Iyzico\\IyzipayWoocommerce\\Common\\Helpers\\PaymentProcessor';
		if ( ! class_exists( $processor_class ) ) {
			throw new RuntimeException( 'iyzico PaymentProcessor missing' );
		}
		$processor = new $processor_class();
		$processor->processWebhookWithSignature(
			array(
				'token'                 => $token,
				'iyziEventType'         => $event,
				'paymentConversationId' => $conversation_id,
				'status'                => $status,
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Authoritative payment verification                                     */
	/* --------------------------------------------------------------------- */

	/**
	 * Ask iyzico what actually happened, and require it to match this order.
	 *
	 * @param array<string, mixed> $context Expected values.
	 * @return array{ok:bool,reason:string,payment_id:string,paid_price:string,currency:string}
	 */
	public static function verify_payment_with_iyzico( array $context ): array {
		$override = self::verification_override( $context );
		$result   = null === $override ? self::retrieve_checkout_form( $context ) : $override;

		$fail = static function ( string $reason ): array {
			return array( 'ok' => false, 'reason' => $reason, 'payment_id' => '', 'paid_price' => '', 'currency' => '' );
		};

		if ( ! is_array( $result ) ) {
			return $fail( 'no_result' );
		}
		if ( 'success' !== strtolower( (string) ( $result['status'] ?? '' ) ) ) {
			return $fail( 'api_status' );
		}
		if ( (string) ( $result['token'] ?? '' ) !== (string) $context['token'] ) {
			return $fail( 'token_mismatch' );
		}
		if ( (string) ( $result['conversation_id'] ?? '' ) !== (string) $context['conversation_id'] ) {
			return $fail( 'conversation_mismatch' );
		}
		if ( (string) ( $result['basket_id'] ?? '' ) !== (string) $context['order_id'] ) {
			return $fail( 'basket_mismatch' );
		}
		if ( 'SUCCESS' !== strtoupper( (string) ( $result['payment_status'] ?? '' ) ) ) {
			return $fail( 'payment_status' );
		}
		$api_payment_id = (string) ( $result['payment_id'] ?? '' );
		if ( '' === $api_payment_id || $api_payment_id !== (string) $context['payment_id'] ) {
			return $fail( 'payment_id_mismatch' );
		}
		$paid_price = (string) ( $result['paid_price'] ?? '' );
		if ( '' === $paid_price || abs( (float) $paid_price - (float) $context['expected_total'] ) >= 0.01 ) {
			return $fail( 'amount_mismatch' );
		}
		// Fail closed: a missing currency is a missing guarantee, not a pass.
		$currency = strtoupper( trim( (string) ( $result['currency'] ?? '' ) ) );
		$expected_currency = strtoupper( trim( (string) ( $context['expected_currency'] ?? '' ) ) );
		if ( '' === $currency || '' === $expected_currency || $currency !== $expected_currency ) {
			return $fail( 'currency_mismatch' );
		}

		return array(
			'ok'         => true,
			'reason'     => 'verified',
			'payment_id' => $api_payment_id,
			'paid_price' => $paid_price,
			'currency'   => $currency,
		);
	}

	/**
	 * Narrow seam for test doubles.
	 *
	 * The filter is only consulted inside an explicit test context, so a
	 * production request always reaches the real iyzico API below.
	 *
	 * @param array<string, mixed> $context Expected values.
	 * @return array<string, mixed>|null
	 */
	private static function verification_override( array $context ): ?array {
		if ( ! self::test_context() ) {
			return null;
		}
		$override = apply_filters( 'kuka_island_iyzico_payment_verification', null, $context );

		return is_array( $override ) ? $override : null;
	}

	/** WP-CLI, or an explicit constant a test harness drops in and removes. */
	private static function test_context(): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'KUKA_ISLAND_IYZICO_TEST_MODE' ) && KUKA_ISLAND_IYZICO_TEST_MODE );
	}

	/**
	 * Retrieve the checkout form result from iyzico using the gateway's own SDK.
	 *
	 * @param array<string, mixed> $context Expected values.
	 * @return array<string, mixed>|null
	 */
	private static function retrieve_checkout_form( array $context ): ?array {
		$model   = '\\Iyzipay\\Model\\CheckoutForm';
		$request = '\\Iyzipay\\Request\\RetrieveCheckoutFormRequest';
		$options = '\\Iyzipay\\Options';
		if ( ! class_exists( $model ) || ! class_exists( $request ) || ! class_exists( $options ) ) {
			return null;
		}

		$settings = get_option( 'woocommerce_iyzico_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		if ( empty( $settings['api_key'] ) || empty( $settings['secret_key'] ) ) {
			return null;
		}

		try {
			$api_options = new $options();
			$api_options->setApiKey( (string) $settings['api_key'] );
			$api_options->setSecretKey( (string) $settings['secret_key'] );
			$api_options->setBaseUrl( (string) ( $settings['api_type'] ?? '' ) );

			$api_request = new $request();
			$api_request->setToken( (string) $context['token'] );
			$api_request->setConversationId( (string) $context['conversation_id'] );

			$response = $model::retrieve( $api_request, $api_options );
		} catch ( Throwable $throwable ) {
			return null;
		}

		if ( ! is_object( $response ) ) {
			return null;
		}

		return array(
			'status'          => (string) $response->getStatus(),
			'token'           => (string) $response->getToken(),
			'conversation_id' => (string) $response->getConversationId(),
			'basket_id'       => (string) $response->getBasketId(),
			'payment_status'  => (string) $response->getPaymentStatus(),
			'payment_id'      => (string) $response->getPaymentId(),
			'paid_price'      => (string) $response->getPaidPrice(),
			'currency'        => (string) $response->getCurrency(),
		);
	}

	/** Persist the verified payment facts on the gateway's own row. */
	private static function store_verified_payment( string $token, array $verification ): void {
		global $wpdb;
		$table = self::provider_table();
		if ( null === $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'payment_id'     => (string) $verification['payment_id'],
				'status'         => 'success',
				'payment_status' => 'SUCCESS',
			),
			array( 'token' => $token ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Browser callback                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Keep a reloaded return page from re-running the gateway callback.
	 *
	 * Only a return whose payment is provably complete is short-circuited to the
	 * order-received page. A first callback, a failed callback and a fresh
	 * payment attempt (which carries a new token) all reach the gateway. A
	 * concurrent return gets a retryable 409 rather than a thank-you page it has
	 * not earned.
	 */
	public function guard_callback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'iyzipay' !== ( $_GET['wc-api'] ?? '' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		if ( '' === $token ) {
			return;
		}

		$order = self::order_for_token( $token );
		if ( ! $order ) {
			return;
		}

		$order_id  = $order->get_id();
		$event_key = self::event_key( 'callback', array( $token ) );
		$lock_key  = self::payment_lock_key( $order_id );

		// The decision is made from state, not from a record of our own: the
		// order and its gateway rows must already agree that it is paid. This is
		// judged per order, not per token, so a late return carrying a different
		// token cannot start a second mutation.
		if ( self::order_is_paid( $order ) ) {
			self::note_duplicate_once( $order, $event_key );
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		// Same lock the webhook uses, so the two channels cannot both enter.
		if ( ! self::acquire_lock( $lock_key ) ) {
			self::render_in_progress_page( $order );
		}

		// Whatever was read before the lock may be stale: if the webhook settled
		// the payment while this request waited, forward to the order-received
		// page instead of running the gateway callback a second time.
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && self::order_is_paid( $order ) ) {
			self::note_duplicate_once( $order, $event_key );
			self::release_lock( $lock_key );
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		// The gateway ends processCallback() with exit(), so the lock is dropped
		// at shutdown. Nothing is recorded here: the next delivery re-reads the
		// real payment state instead of trusting a marker.
		register_shutdown_function(
			static function () use ( $lock_key ): void {
				self::release_lock( $lock_key );
			}
		);
	}

	/**
	 * Read-only payment state probe used by the holding page.
	 *
	 * It carries no payment token: the order id comes from the endpoint and the
	 * order key already present in the address bar authorises the read. A GET
	 * answers JSON for the poller; a POST is the no-JavaScript retry and either
	 * forwards to the order-received page or re-renders the holding page.
	 */
	public function serve_status_probe(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['kuka-iyzico-status'] ) ) {
			return;
		}
		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return;
		}

		$confirmed = self::order_is_paid( $order );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			if ( $confirmed ) {
				wp_safe_redirect( $order->get_checkout_order_received_url(), 303 );
				exit;
			}
			self::render_in_progress_page( $order );
		}

		nocache_headers();
		wp_send_json( array( 'confirmed' => $confirmed ), 200 );
	}

	/**
	 * A retryable holding page shown while another request settles the payment.
	 *
	 * There is no meta refresh: the callback token only exists in the POST body,
	 * so a GET reload would silently drop it. Instead the page polls a read-only
	 * status probe that needs no token, and only navigates to the order-received
	 * page once the payment is genuinely confirmed. With JavaScript off the same
	 * check is one button press away.
	 */
	private static function render_in_progress_page( WC_Order $order ): void {
		$english = self::english_context( $order );
		$probe   = add_query_arg( 'kuka-iyzico-status', '1', $order->get_checkout_order_received_url() );

		$title    = $english ? 'Your payment is being processed' : 'Ödemeniz işleniyor';
		$body     = $english
			? 'This payment is being confirmed right now. Please do not pay again and do not close this page; it will continue on its own as soon as the confirmation arrives.'
			: 'Bu ödeme şu anda doğrulanıyor. Lütfen tekrar ödeme yapmayın ve sayfayı kapatmayın; onay geldiği anda kendiliğinden devam edecek.';
		$button   = $english ? 'Check payment status again' : 'Ödeme durumunu yeniden kontrol et';
		$fallback = $english
			? 'If nothing happens, press the button below.'
			: 'Bir şey olmazsa aşağıdaki düğmeye basın.';

		status_header( 409 );
		nocache_headers();
		header( 'Retry-After: 5' );
		header( 'Content-Type: text/html; charset=utf-8' );

		$html  = '<!doctype html><html lang="' . ( $english ? 'en' : 'tr' ) . '"><head><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
		$html .= '<meta name="robots" content="noindex,nofollow">';
		$html .= '<title>' . esc_html( $title ) . '</title></head><body>';
		$html .= '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $body ) . '</p>';
		$html .= '<p>' . esc_html( $fallback ) . '</p>';
		// The retry is a POST to the probe, never a GET reload of this URL: the
		// payment token lives in the original POST body and must not be lost.
		$html .= '<form method="post" action="' . esc_url( $probe ) . '">';
		$html .= '<button type="submit">' . esc_html( $button ) . '</button></form>';
		$html .= '<script>(function(){var u=' . wp_json_encode( $probe ) . ',d=' . wp_json_encode( $order->get_checkout_order_received_url() ) . ';';
		$html .= 'function p(){fetch(u,{credentials:"same-origin",headers:{"Accept":"application/json"}}).then(function(r){return r.json();})';
		$html .= '.then(function(j){if(j&&j.confirmed){window.location.replace(d);}else{window.setTimeout(p,5000);}})';
		$html .= '.catch(function(){window.setTimeout(p,5000);});}window.setTimeout(p,5000);})();</script>';
		$html .= '</body></html>';

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/** Follow the storefront language, falling back to the order's own locale. */
	private static function english_context( WC_Order $order ): bool {
		if ( function_exists( 'kuka_island_is_english' ) && kuka_island_is_english() ) {
			return true;
		}
		return 'en_US' === (string) $order->get_meta( '_kuka_order_locale', true );
	}

	/* --------------------------------------------------------------------- */
	/* Signature                                                              */
	/* --------------------------------------------------------------------- */

	private static function signature_header( WP_REST_Request $request ): string {
		foreach ( array( 'x_iyz_signature_v3', 'x-iyz-signature-v3' ) as $header ) {
			$value = (string) $request->get_header( $header );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Recompute the gateway's own V3 HMAC and compare it in constant time.
	 *
	 * The secret and the signature never leave this method.
	 */
	private static function signature_is_valid( string $event, string $payment_id, string $token, string $conversation_id, string $status, string $signature ): bool {
		$settings = get_option( 'woocommerce_iyzico_settings', array() );
		$secret   = is_array( $settings ) ? (string) ( $settings['secret_key'] ?? '' ) : '';
		if ( '' === $secret ) {
			return false;
		}
		$material = $secret . $event . $payment_id . $token . $conversation_id . $status;
		$expected = bin2hex( hash_hmac( 'sha256', $material, $secret, true ) );

		return hash_equals( $expected, $signature );
	}

	/* --------------------------------------------------------------------- */
	/* Connection-scoped advisory lock                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * The one lock key that guards an order's payment mutation.
	 *
	 * Webhook deliveries and browser callbacks identify the same payment
	 * differently — event type plus payment id versus token — so keying the lock
	 * on either of them would leave the two channels unsynchronised. The order
	 * is what they actually share, and it also covers a late delivery that
	 * carries a second token for the same order.
	 */
	public static function payment_lock_key( int $order_id ): string {
		return 'payment:' . $order_id;
	}

	/**
	 * MariaDB user level lock name for one key.
	 *
	 * Fixed prefix plus a sha1 keeps every name at 49 characters, well inside
	 * the server's 64 character limit, and never collides across keys.
	 */
	public static function lock_name( string $event_key ): string {
		return self::LOCK_PREFIX . sha1( $event_key );
	}

	/**
	 * Take the lock without waiting.
	 *
	 * A timed claim cannot be correct here: PHP runs with max_execution_time = 0,
	 * so the holder may legitimately outlive any TTL, and a stale-takeover rule
	 * would then cut in on a live vendor mutation. GET_LOCK has no expiry — the
	 * only way it is released is the holder releasing it or the holder's
	 * connection ending, which the server detects itself. That removes both the
	 * stale-takeover hazard and the risk of a permanently parked lock after a
	 * crash, and it costs nothing else in the request: a user level lock touches
	 * no table and no row, so the rest of the request's queries are untouched.
	 */
	public static function acquire_lock( string $event_key ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name( $event_key ) ) );

		return '1' === (string) $acquired;
	}

	/**
	 * Release our own lock.
	 *
	 * RELEASE_LOCK only acts for the connection that holds the lock, so a late
	 * call from an earlier request can never drop a successor's lock.
	 */
	public static function release_lock( string $event_key ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $event_key ) ) );
	}

	/** True while any connection holds this delivery's lock. */
	public static function lock_is_held( string $event_key ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$holder = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', self::lock_name( $event_key ) ) );

		return null !== $holder;
	}

	/* --------------------------------------------------------------------- */
	/* Durable processed record                                               */
	/* --------------------------------------------------------------------- */

	/** @return array<string, array<string, mixed>> */
	public static function processed_events( WC_Order $order ): array {
		$stored = $order->get_meta( self::PROCESSED_META, true );
		return is_array( $stored ) ? $stored : array();
	}

	/** Written only after the payment was verified as settled. */
	private static function mark_processed( WC_Order $order, string $event_key, string $payment_id, string $channel ): void {
		$events = self::processed_events( $order );
		if ( isset( $events[ $event_key ] ) ) {
			return;
		}
		$events[ $event_key ] = array(
			'payment_id' => $payment_id,
			'channel'    => $channel,
			'at'         => time(),
		);
		$order->update_meta_data( self::PROCESSED_META, $events );
		$order->save_meta_data();
	}

	/**
	 * Leave a single audit note per delivery, however often it repeats.
	 *
	 * A short lock of its own makes the check-and-write atomic, so two
	 * simultaneous repeats cannot both add the note. MariaDB lets one connection
	 * hold several user level locks, so this nests inside the payment lock.
	 */
	private static function note_duplicate_once( WC_Order $order, string $event_key ): void {
		$note_key = $event_key . ':note';
		if ( ! self::acquire_lock( $note_key ) ) {
			return;
		}
		$fresh = wc_get_order( $order->get_id() );
		if ( ! $fresh instanceof WC_Order ) {
			self::release_lock( $note_key );
			return;
		}
		$noted = $fresh->get_meta( self::NOTED_META, true );
		$noted = is_array( $noted ) ? $noted : array();
		if ( in_array( $event_key, $noted, true ) ) {
			self::release_lock( $note_key );
			return;
		}
		$noted[] = $event_key;
		$fresh->update_meta_data( self::NOTED_META, $noted );
		$fresh->save_meta_data();
		$fresh->add_order_note(
			__( 'Aynı iyzico bildirimi tekrar geldi; sipariş durumu, tutarı ve stoğu korunarak yok sayıldı. Sonraki tekrarlar için ayrıca not düşülmez.', 'kuka-island-core' ),
			0,
			false
		);
		self::release_lock( $note_key );
	}

	/* --------------------------------------------------------------------- */
	/* Payment state                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * The gateway row's own settlement contract.
	 *
	 * Every clause is required: a row is only settled when both status columns
	 * read SUCCESS and it carries exactly the payment id the caller expects.
	 * Partial agreement — one column SUCCESS, a blank id, a different id — is
	 * not settlement.
	 *
	 * @param array<string, mixed>|null $row Gateway row.
	 */
	public static function payment_is_settled( ?array $row, string $expected_payment_id ): bool {
		if ( ! is_array( $row ) ) {
			return false;
		}
		if ( 'SUCCESS' !== strtoupper( trim( (string) ( $row['status'] ?? '' ) ) ) ) {
			return false;
		}
		if ( 'SUCCESS' !== strtoupper( trim( (string) ( $row['payment_status'] ?? '' ) ) ) ) {
			return false;
		}
		$stored_payment_id = trim( (string) ( $row['payment_id'] ?? '' ) );
		$expected          = trim( $expected_payment_id );

		return '' !== $stored_payment_id && '' !== $expected && $stored_payment_id === $expected;
	}

	/**
	 * Is this order paid, judged from its own gateway row?
	 *
	 * Used by the token-free status probe: the stored payment id is both the
	 * value and the expectation, so the strict contract still demands two
	 * SUCCESS columns and a non-empty id.
	 */
	public static function order_is_paid( WC_Order $order ): bool {
		if ( ! in_array( $order->get_status(), self::PAID_STATUSES, true ) ) {
			return false;
		}
		// Any settled row counts. A second checkout attempt adds a second row, so
		// looking only at the newest one would call a paid order unpaid.
		foreach ( self::provider_rows_for_order( $order->get_id() ) as $row ) {
			if ( self::payment_is_settled( $row, (string) ( $row['payment_id'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<int, array<string, mixed>> */
	private static function provider_rows_for_order( int $order_id ): array {
		global $wpdb;
		$table = self::provider_table();
		if ( null === $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, payment_id, status, payment_status, conversation_id FROM {$table} WHERE order_id = %d", $order_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** Settled gateway row plus an order that actually reached a paid status. */
	public static function order_payment_confirmed( WC_Order $order, string $token, string $expected_payment_id ): bool {
		if ( ! in_array( $order->get_status(), self::PAID_STATUSES, true ) ) {
			return false;
		}

		return self::payment_is_settled( self::provider_row( $token ), $expected_payment_id );
	}

	/**
	 * True when this delivery names a different payment than the order carries.
	 *
	 * @param array<string, array<string, mixed>> $processed Existing records.
	 */
	private static function payment_id_conflicts( string $token, string $payment_id, array $processed ): bool {
		$stored = self::provider_payment_id( $token );
		if ( '' !== $stored && $stored !== $payment_id ) {
			return true;
		}
		foreach ( $processed as $event ) {
			$seen = (string) ( $event['payment_id'] ?? '' );
			if ( 'webhook' === ( $event['channel'] ?? '' ) && '' !== $seen && $seen !== $payment_id ) {
				return true;
			}
		}
		return false;
	}

	public static function provider_payment_id( string $token ): string {
		return (string) self::provider_field( $token, 'payment_id' );
	}

	private static function provider_field( string $token, string $field ): string {
		$row = self::provider_row( $token );

		return $row ? (string) ( $row[ $field ] ?? '' ) : '';
	}

	/** @return array<string, mixed>|null */
	public static function provider_row( string $token ): ?array {
		global $wpdb;
		$table = self::provider_table();
		if ( null === $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT order_id, payment_id, status, payment_status, conversation_id FROM {$table} WHERE token = %s LIMIT 1", $token ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** The gateway owns this table, so its absence simply disables the guard. */
	private static function provider_table(): ?string {
		global $wpdb;
		$table = $wpdb->prefix . 'iyzico_order';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return null;
		}
		return $table;
	}

	private static function order_for_token( string $token ): ?WC_Order {
		$row = self::provider_row( $token );
		if ( ! $row ) {
			return null;
		}
		$order = wc_get_order( (int) ( $row['order_id'] ?? 0 ) );

		return $order instanceof WC_Order ? $order : null;
	}

	/** Stable hash of one delivery; distinct deliveries never collide. */
	public static function event_key( string $channel, array $parts ): string {
		return sha1( $channel . '|' . implode( '|', array_map( 'strval', $parts ) ) );
	}
}
