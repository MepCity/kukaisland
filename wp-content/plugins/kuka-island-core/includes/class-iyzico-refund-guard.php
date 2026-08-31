<?php
/**
 * Save-before-gateway guard for automatic iyzico refunds.
 *
 * WooCommerce writes the refund record first and calls the gateway second:
 *
 *   do_action( 'woocommerce_create_refund', ... );   // ← this guard runs here
 *   if ( $refund->save() ) {
 *       if ( $args['refund_payment'] ) { wc_refund_payment( ... ); }
 *   }
 *
 * The gateway's RefundProcessor takes the newest row of its own table with
 * `ORDER BY iyzico_order_id DESC LIMIT 1` and hands whatever it finds to
 * `AmountBaseRefundRequest::setPaymentId()`. When that newest row carries a
 * NULL payment id — which happens whenever a later checkout attempt added a row
 * that never settled — the typed setter raises a TypeError. By then the local
 * `shop_order_refund` already exists, so the shop shows a refund that iyzico
 * never performed. Order #762 on order #361 is exactly that.
 *
 * The guard runs while the refund is still unsaved. Throwing here is caught by
 * `wc_create_refund()`, which returns a WP_Error, so WooCommerce answers with a
 * JSON error and no refund record is written. Only the automatic path is
 * touched: a manual refund and every other gateway pass straight through, and
 * no vendor file is modified.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Iyzico_Refund_Guard {
	/** Gateway id whose automatic refunds are guarded. */
	public const GATEWAY_ID = 'iyzico';

	/** Message shown to the operator and returned to the AJAX caller. */
	public const BLOCKED_MESSAGE = 'İyzico otomatik iadesi güvenli biçimde başlatılamadı. Ödeme kaydı eksik veya belirsiz; hiçbir iade kaydı oluşturulmadı.';

	public function register(): void {
		add_action( 'woocommerce_create_refund', array( $this, 'guard_automatic_refund' ), 10, 2 );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'render_notice' ) );
	}

	/**
	 * Stop an unsafe automatic refund before the record exists.
	 *
	 * @param WC_Order_Refund $refund Unsaved refund.
	 * @param array           $args   Refund arguments.
	 * @throws Exception When the payment record cannot be trusted.
	 */
	public function guard_automatic_refund( $refund, $args ): void {
		unset( $refund );
		if ( empty( $args['refund_payment'] ) ) {
			// Manual refund: nothing is sent to the gateway, nothing to guard.
			return;
		}
		$order = wc_get_order( (int) ( $args['order_id'] ?? 0 ) );
		if ( ! $order instanceof WC_Order || self::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		$verdict = self::preflight( $order, self::latest_provider_row( $order->get_id() ), self::verified_payment_ids( $order ) );
		if ( ! $verdict['allowed'] ) {
			// The reason stays internal; the operator sees the fixed sentence.
			throw new Exception( esc_html( self::BLOCKED_MESSAGE ) );
		}
	}

	/**
	 * Pure preflight verdict.
	 *
	 * Every clause is required. The row examined is deliberately the same one
	 * the gateway itself would pick, so an older, healthier row can never make
	 * this look safe when the gateway is about to use a broken one.
	 *
	 * @param WC_Order|null             $order                Parent order.
	 * @param array<string, mixed>|null $row                  Newest gateway row.
	 * @param array<int, string>        $verified_payment_ids Payment ids the idempotency guard verified.
	 * @return array{allowed:bool,reason:string}
	 */
	public static function preflight( ?WC_Order $order, ?array $row, array $verified_payment_ids ): array {
		if ( ! $order instanceof WC_Order ) {
			return array( 'allowed' => false, 'reason' => 'order_missing' );
		}
		if ( ! is_array( $row ) ) {
			return array( 'allowed' => false, 'reason' => 'no_provider_row' );
		}
		$payment_id = $row['payment_id'] ?? null;
		if ( ! is_string( $payment_id ) || '' === trim( $payment_id ) ) {
			return array( 'allowed' => false, 'reason' => 'payment_id_missing' );
		}
		$conversation_id = $row['conversation_id'] ?? null;
		if ( ! is_string( $conversation_id ) || '' === trim( $conversation_id ) ) {
			return array( 'allowed' => false, 'reason' => 'conversation_id_missing' );
		}
		if ( 'SUCCESS' !== strtoupper( trim( (string) ( $row['status'] ?? '' ) ) ) ) {
			return array( 'allowed' => false, 'reason' => 'provider_status_not_success' );
		}
		if ( 'SUCCESS' !== strtoupper( trim( (string) ( $row['payment_status'] ?? '' ) ) ) ) {
			return array( 'allowed' => false, 'reason' => 'payment_status_not_success' );
		}
		if ( (int) ( $row['order_id'] ?? 0 ) !== $order->get_id() ) {
			return array( 'allowed' => false, 'reason' => 'order_id_mismatch' );
		}
		$verified = array_map( 'strval', $verified_payment_ids );
		if ( ! $verified || ! in_array( trim( $payment_id ), $verified, true ) ) {
			return array( 'allowed' => false, 'reason' => 'payment_id_unverified' );
		}

		return array( 'allowed' => true, 'reason' => 'allowed' );
	}

	/**
	 * The very row the gateway's RefundProcessor would use.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function latest_provider_row( int $order_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'iyzico_order';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return null;
		}
		// Mirrors DatabaseManager::findOrderByOrderId() exactly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT order_id, payment_id, conversation_id, status, payment_status FROM {$table} WHERE order_id = %d ORDER BY iyzico_order_id DESC LIMIT 1", $order_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Payment ids the idempotency guard confirmed against iyzico itself.
	 *
	 * @return array<int, string>
	 */
	public static function verified_payment_ids( WC_Order $order ): array {
		if ( ! class_exists( 'Kuka_Island_Core_Iyzico_Idempotency' ) ) {
			return array();
		}
		$ids = array();
		foreach ( Kuka_Island_Core_Iyzico_Idempotency::processed_events( $order ) as $event ) {
			$payment_id = trim( (string) ( $event['payment_id'] ?? '' ) );
			if ( '' !== $payment_id ) {
				$ids[] = $payment_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Read-only warning next to the refund controls.
	 *
	 * No payment id, token, key or gateway row content is ever rendered.
	 *
	 * @param int $order_id Current order.
	 */
	public function render_notice( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || self::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		$verdict = self::preflight( $order, self::latest_provider_row( $order->get_id() ), self::verified_payment_ids( $order ) );
		if ( $verdict['allowed'] ) {
			return;
		}
		$english = ! str_starts_with( (string) get_user_locale(), 'tr' );
		$message = $english
			? 'Automatic iyzico refund is unavailable: no verified payment record was found. Check the iyzico dashboard.'
			: 'İyzico otomatik iadesi kullanılamıyor: doğrulanmış ödeme kaydı bulunamadı. İyzico panelinden kontrol edin.';
		echo '<tr class="kuka-iyzico-refund-warning"><td class="label" colspan="2"><p class="description">'
			. esc_html( $message )
			. '</p></td></tr>';
	}
}
