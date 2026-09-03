<?php
/**
 * Shipment state on the order, through WooCommerce CRUD.
 *
 * Every read and write goes through WC_Order::get_meta() /
 * update_meta_data() / save_meta_data(), which is what makes this HPOS
 * compatible: the same code addresses the orders table and the legacy post meta
 * table without knowing which is in use. Nothing here touches $wpdb, and no
 * meta key is written by any other class in this plugin.
 *
 * The state machine is the safety property. Its purpose is not tidiness; it is
 * that STATE_RECONCILE_REQUIRED cannot be left by repeating the request that
 * produced it. A shipment whose existence is unknown is resolved by reading,
 * never by writing again.
 *
 *   none                 nothing has been attempted
 *     -> order_created       createOrder confirmed
 *     -> reconcile_required  a write returned an uncertain answer
 *   order_created
 *     -> shipment_created    createbarcode confirmed, shipmentId known
 *     -> reconcile_required
 *   shipment_created
 *     -> delivered           status code 5
 *     -> manual_review       status code 6/7/8 or an unrecognised value
 *     -> cancelled           an operator cancelled and the carrier confirmed
 *   reconcile_required
 *     -> order_created / shipment_created   a read found it
 *     -> absent_confirmed                   a read proved it is not there
 *   absent_confirmed
 *     -> order_created       only via a NEW deliberate operator action
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Order_Store {

	public const META_PROVIDER           = '_kuka_shipping_provider';
	public const META_STATE              = '_kuka_shipping_state';
	public const META_REFERENCE          = '_kuka_shipping_reference';
	public const META_REFERENCE_HISTORY  = '_kuka_shipping_reference_history';
	public const META_SHIPMENT_ID        = '_kuka_shipping_shipment_id';
	public const META_BARCODES           = '_kuka_shipping_barcodes';
	public const META_TRACKING_URL       = '_kuka_shipping_tracking_url';
	public const META_ORDER_INVOICE_ID   = '_kuka_shipping_order_invoice_id';
	public const META_STATUS_CODE        = '_kuka_shipping_status_code';
	public const META_STATUS_LIFECYCLE   = '_kuka_shipping_status_lifecycle';
	public const META_LAST_ERROR         = '_kuka_shipping_last_error';
	public const META_LAST_OPERATION     = '_kuka_shipping_last_operation';
	public const META_CREATED_AT         = '_kuka_shipping_created_at';
	public const META_LAST_QUERIED_AT    = '_kuka_shipping_last_queried_at';
	public const META_QUERY_ATTEMPTS     = '_kuka_shipping_query_attempts';
	public const META_HISTORY            = '_kuka_shipping_history';

	public const STATE_NONE                = 'none';
	public const STATE_ORDER_CREATED       = 'order_created';
	public const STATE_SHIPMENT_CREATED    = 'shipment_created';
	public const STATE_RECONCILE_REQUIRED  = 'reconcile_required';
	public const STATE_ABSENT_CONFIRMED    = 'absent_confirmed';
	public const STATE_DELIVERED           = 'delivered';
	public const STATE_MANUAL_REVIEW       = 'manual_review';
	public const STATE_CANCELLED           = 'cancelled';
	public const STATE_BLOCKED             = 'blocked';

	/**
	 * States in which a create call is forbidden because something may already
	 * exist at the carrier under this order's reference.
	 *
	 * STATE_RECONCILE_REQUIRED is the important entry: it is precisely the state
	 * where nobody knows, and "nobody knows" must never be resolved by sending
	 * the create again.
	 *
	 * @return array<int, string>
	 */
	public static function states_blocking_create(): array {
		return array(
			self::STATE_ORDER_CREATED,
			self::STATE_SHIPMENT_CREATED,
			self::STATE_RECONCILE_REQUIRED,
			self::STATE_DELIVERED,
			self::STATE_MANUAL_REVIEW,
		);
	}

	public static function get_state( WC_Order $order ): string {
		$state = (string) $order->get_meta( self::META_STATE, true );

		return '' !== $state ? $state : self::STATE_NONE;
	}

	/**
	 * Everything the admin panel and the verification suite need, in one read.
	 *
	 * @return array{provider: string, state: string, reference: string, shipment_id: string, barcodes: array<int, string>, tracking_url: string, order_invoice_id: string, status_code: int, status_lifecycle: string, last_error: string, last_operation: string, created_at: int, last_queried_at: int, query_attempts: int, history: array<int, array<string, mixed>>, reference_history: array<int, string>}
	 */
	public static function get_shipment_data( WC_Order $order ): array {
		return array(
			'provider'          => (string) $order->get_meta( self::META_PROVIDER, true ),
			'state'             => self::get_state( $order ),
			'reference'         => (string) $order->get_meta( self::META_REFERENCE, true ),
			'reference_history' => array_values( array_filter( array_map( 'strval', (array) ( $order->get_meta( self::META_REFERENCE_HISTORY, true ) ?: array() ) ) ) ),
			'shipment_id'       => (string) $order->get_meta( self::META_SHIPMENT_ID, true ),
			'barcodes'          => array_values( array_filter( array_map( 'strval', (array) ( $order->get_meta( self::META_BARCODES, true ) ?: array() ) ) ) ),
			'tracking_url'      => (string) $order->get_meta( self::META_TRACKING_URL, true ),
			'order_invoice_id'  => (string) $order->get_meta( self::META_ORDER_INVOICE_ID, true ),
			'status_code'       => (int) $order->get_meta( self::META_STATUS_CODE, true ),
			'status_lifecycle'  => (string) $order->get_meta( self::META_STATUS_LIFECYCLE, true ) ?: Kuka_Island_Shipping_Status::LIFECYCLE_UNKNOWN,
			'last_error'        => (string) $order->get_meta( self::META_LAST_ERROR, true ),
			'last_operation'    => (string) $order->get_meta( self::META_LAST_OPERATION, true ),
			'created_at'        => (int) $order->get_meta( self::META_CREATED_AT, true ),
			'last_queried_at'   => (int) $order->get_meta( self::META_LAST_QUERIED_AT, true ),
			'query_attempts'    => (int) $order->get_meta( self::META_QUERY_ATTEMPTS, true ),
			'history'           => (array) ( $order->get_meta( self::META_HISTORY, true ) ?: array() ),
		);
	}

	/**
	 * The order's carrier reference, minting one on first use.
	 *
	 * Idempotent by construction: once written the same value is returned for
	 * the lifetime of the order. This is the method every caller uses; nothing
	 * else writes META_REFERENCE except mint_replacement().
	 *
	 * @param WC_Order $order Order.
	 */
	public static function reference( WC_Order $order ): string {
		$existing = (string) $order->get_meta( self::META_REFERENCE, true );

		if ( Kuka_Island_Shipping_Reference::is_valid( $existing ) ) {
			return $existing;
		}

		$reference = Kuka_Island_Shipping_Reference::build( (int) $order->get_id() );

		$order->update_meta_data( self::META_REFERENCE, $reference );
		self::append_reference_history( $order, $reference );
		$order->save_meta_data();

		return $reference;
	}

	/**
	 * Mint a replacement reference after a cancellation the carrier confirmed.
	 *
	 * Deliberately separate from reference(): minting a new identifier makes the
	 * previous one unreachable by this order's own screens, so it is an act, not
	 * a fallback. The old reference stays in the history for ever.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function mint_replacement( WC_Order $order ): string {
		$history = array_values( array_filter( array_map( 'strval', (array) ( $order->get_meta( self::META_REFERENCE_HISTORY, true ) ?: array() ) ) ) );

		$reference = Kuka_Island_Shipping_Reference::build_unused( (int) $order->get_id(), $history );

		if ( in_array( $reference, $history, true ) ) {
			/*
			 * Astronomically unlikely, and still refused rather than written:
			 * re-using an identifier the carrier already knows would point every
			 * later query, update and cancellation at the previous shipment.
			 * Returning the current reference leaves the order exactly where it
			 * was, which the caller can see.
			 */
			return (string) $order->get_meta( self::META_REFERENCE, true );
		}

		$order->update_meta_data( self::META_REFERENCE, $reference );
		self::append_reference_history( $order, $reference );
		$order->save_meta_data();

		return $reference;
	}

	/**
	 * @param WC_Order $order     Order.
	 * @param string   $reference Reference to record.
	 */
	private static function append_reference_history( WC_Order $order, string $reference ): void {
		$history = (array) ( $order->get_meta( self::META_REFERENCE_HISTORY, true ) ?: array() );

		if ( ! in_array( $reference, $history, true ) ) {
			$history[] = $reference;
		}

		$order->update_meta_data( self::META_REFERENCE_HISTORY, $history );
	}

	/**
	 * Move to a new state and record why, in one atomic save.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $state   One of the STATE_* constants.
	 * @param string   $message Operator-facing sentence. Never carrier text.
	 */
	public static function set_state( WC_Order $order, string $state, string $message = '' ): void {
		$previous = self::get_state( $order );

		$order->update_meta_data( self::META_STATE, $state );
		self::add_history_entry(
			$order,
			$state,
			'' !== $message ? $message : sprintf( 'Durum değişti: %s -> %s', $previous, $state )
		);
		$order->save_meta_data();
	}

	/**
	 * Record a confirmed createOrder.
	 *
	 * @param WC_Order $order            Order.
	 * @param string   $provider_key     Carrier key, e.g. 'dhl'.
	 * @param string   $order_invoice_id Carrier's own order receipt id, '' when absent.
	 */
	public static function save_order_created( WC_Order $order, string $provider_key, string $order_invoice_id ): void {
		$order->update_meta_data( self::META_PROVIDER, $provider_key );
		$order->update_meta_data( self::META_STATE, self::STATE_ORDER_CREATED );
		$order->update_meta_data( self::META_LAST_OPERATION, 'create_order' );
		$order->update_meta_data( self::META_LAST_ERROR, '' );

		if ( '' !== $order_invoice_id ) {
			$order->update_meta_data( self::META_ORDER_INVOICE_ID, $order_invoice_id );
		}

		if ( 0 === (int) $order->get_meta( self::META_CREATED_AT, true ) ) {
			$order->update_meta_data( self::META_CREATED_AT, time() );
		}

		self::add_history_entry( $order, self::STATE_ORDER_CREATED, __( 'Taşıyıcıda sipariş oluşturuldu.', 'kuka-island-shipping-automation' ) );
		$order->save_meta_data();
	}

	/**
	 * Record a confirmed createbarcode.
	 *
	 * @param WC_Order          $order       Order.
	 * @param string            $shipment_id Carrier shipment id.
	 * @param array<int,string> $barcodes    Piece barcodes the carrier returned.
	 */
	public static function save_shipment_created( WC_Order $order, string $shipment_id, array $barcodes ): void {
		$order->update_meta_data( self::META_STATE, self::STATE_SHIPMENT_CREATED );
		$order->update_meta_data( self::META_LAST_OPERATION, 'create_barcode' );
		$order->update_meta_data( self::META_LAST_ERROR, '' );

		if ( '' !== $shipment_id ) {
			$order->update_meta_data( self::META_SHIPMENT_ID, $shipment_id );
		}

		$clean = array();
		foreach ( $barcodes as $barcode ) {
			$barcode = trim( (string) $barcode );
			if ( '' !== $barcode ) {
				$clean[] = $barcode;
			}
		}
		$order->update_meta_data( self::META_BARCODES, $clean );

		self::add_history_entry( $order, self::STATE_SHIPMENT_CREATED, __( 'Taşıyıcıda gönderi ve barkodlar oluşturuldu.', 'kuka-island-shipping-automation' ) );
		$order->save_meta_data();
	}

	/**
	 * Record a status reading.
	 *
	 * The lifecycle is derived by the dictionary, never by the caller, so an
	 * unrecognised code cannot be written as if it were in progress.
	 *
	 * @param WC_Order $order        Order.
	 * @param mixed    $raw_code     Status value exactly as it arrived.
	 * @param string   $tracking_url Tracking URL the carrier returned, '' when absent.
	 */
	public static function save_status( WC_Order $order, $raw_code, string $tracking_url = '' ): string {
		$code      = Kuka_Island_Shipping_Status::normalize_code( $raw_code );
		$lifecycle = Kuka_Island_Shipping_Status::lifecycle_for( $raw_code );

		$order->update_meta_data( self::META_STATUS_CODE, $code );
		$order->update_meta_data( self::META_STATUS_LIFECYCLE, $lifecycle );
		$order->update_meta_data( self::META_LAST_QUERIED_AT, time() );
		$order->update_meta_data( self::META_LAST_OPERATION, 'get_shipment_status' );

		if ( '' !== $tracking_url ) {
			$order->update_meta_data( self::META_TRACKING_URL, $tracking_url );
		}

		$attempts = (int) $order->get_meta( self::META_QUERY_ATTEMPTS, true );
		$order->update_meta_data( self::META_QUERY_ATTEMPTS, $attempts + 1 );

		if ( Kuka_Island_Shipping_Status::LIFECYCLE_DELIVERED === $lifecycle ) {
			$order->update_meta_data( self::META_STATE, self::STATE_DELIVERED );
		} elseif ( Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW === $lifecycle ) {
			$order->update_meta_data( self::META_STATE, self::STATE_MANUAL_REVIEW );
		}

		self::add_history_entry(
			$order,
			self::get_state( $order ),
			sprintf(
				/* translators: 1: status label, 2: numeric status code or 'tanımsız'. */
				__( 'Durum okundu: %1$s (kod: %2$s)', 'kuka-island-shipping-automation' ),
				Kuka_Island_Shipping_Status::label_for( $raw_code ),
				0 !== $code ? (string) $code : __( 'tanımsız', 'kuka-island-shipping-automation' )
			)
		);

		$order->save_meta_data();

		return $lifecycle;
	}

	/**
	 * Record an operation that failed with a code, without changing the state.
	 *
	 * Used for permanent and transient failures alike: neither of them can have
	 * created anything, so neither of them may move the order out of the state
	 * it was in.
	 *
	 * @param WC_Order $order           Order.
	 * @param string   $operation       Logical operation name.
	 * @param string   $safe_error_code Allow-listed code.
	 * @param string   $message         Operator-facing sentence.
	 */
	public static function save_failure( WC_Order $order, string $operation, string $safe_error_code, string $message ): void {
		$order->update_meta_data( self::META_LAST_OPERATION, $operation );
		$order->update_meta_data( self::META_LAST_ERROR, $safe_error_code );

		self::add_history_entry( $order, self::get_state( $order ), $message );
		$order->save_meta_data();
	}

	/**
	 * Record that a write returned an answer nobody can act on.
	 *
	 * This is the one transition that closes the door on retrying: from here
	 * only a read-only reconciliation can move the order.
	 *
	 * @param WC_Order $order           Order.
	 * @param string   $operation       Logical operation name.
	 * @param string   $safe_error_code Allow-listed code.
	 */
	public static function save_uncertain( WC_Order $order, string $operation, string $safe_error_code ): void {
		$order->update_meta_data( self::META_STATE, self::STATE_RECONCILE_REQUIRED );
		$order->update_meta_data( self::META_LAST_OPERATION, $operation );
		$order->update_meta_data( self::META_LAST_ERROR, $safe_error_code );

		self::add_history_entry(
			$order,
			self::STATE_RECONCILE_REQUIRED,
			sprintf(
				/* translators: 1: operation name, 2: safe error code. */
				__( 'Belirsiz sonuç (%1$s / %2$s). Gönderi oluşmuş olabilir; yeniden gönderim yapılmadı, salt-okunur mutabakat gerekiyor.', 'kuka-island-shipping-automation' ),
				$operation,
				$safe_error_code
			)
		);

		$order->save_meta_data();
	}

	/**
	 * Record a deliberate refusal that happened BEFORE any network call.
	 *
	 * Distinct from save_failure(): nothing was sent, so no attempt counter
	 * moves and the reason is a configuration or business-rule gap rather than
	 * a carrier answer.
	 *
	 * @param WC_Order $order           Order.
	 * @param string   $safe_error_code Allow-listed code.
	 * @param string   $message         Operator-facing sentence.
	 */
	public static function save_blocked( WC_Order $order, string $safe_error_code, string $message ): void {
		$order->update_meta_data( self::META_LAST_ERROR, $safe_error_code );

		// A block never overwrites a state that records something existing at
		// the carrier: the shipment is still there, the request was simply not
		// made.
		if ( in_array( self::get_state( $order ), array( self::STATE_NONE, self::STATE_BLOCKED, self::STATE_ABSENT_CONFIRMED ), true ) ) {
			$order->update_meta_data( self::META_STATE, self::STATE_BLOCKED );
		}

		self::add_history_entry( $order, self::get_state( $order ), $message );
		$order->save_meta_data();
	}

	/**
	 * Append one audit entry.
	 *
	 * Bounded at 40 entries. The bound is a real trade-off and it is made
	 * deliberately: order meta is loaded with the order on every admin screen,
	 * and an unbounded array would grow for the lifetime of a shop. Forty
	 * covers a create, a reconciliation and a full poll chain several times
	 * over. The entries that fall off the front are the oldest, and the
	 * reference history -- the part that says WHAT was ever addressed at the
	 * carrier -- is stored separately and is never trimmed.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $state   State at the time of the entry.
	 * @param string   $message Operator-facing sentence.
	 */
	private static function add_history_entry( WC_Order $order, string $state, string $message ): void {
		$history   = (array) ( $order->get_meta( self::META_HISTORY, true ) ?: array() );
		$history[] = array(
			'time'    => time(),
			'state'   => $state,
			'message' => sanitize_text_field( $message ),
			'user_id' => get_current_user_id(),
		);

		if ( count( $history ) > 40 ) {
			$history = array_slice( $history, -40 );
		}

		$order->update_meta_data( self::META_HISTORY, $history );
	}
}
