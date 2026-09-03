<?php
/**
 * The orchestrator: gates, locks, reconciliation and state transitions.
 *
 * Everything dangerous in this integration is decided here, in one place, so it
 * can be read in one sitting and measured as a whole.
 *
 * THE UNCERTAIN RULE. A write whose outcome is unknown never becomes a second
 * write. create_shipment() reacts to an uncertain answer by recording
 * STATE_RECONCILE_REQUIRED and then performing a READ-ONLY reconciliation, and
 * the read is the only thing that may move the order onwards. If the read finds
 * the object, the order continues from where it actually is. If the read proves
 * the object absent, the order lands in STATE_ABSENT_CONFIRMED, which a person
 * -- not this code -- turns back into an attempt. If the read itself cannot
 * answer, the order stays in reconciliation and nothing is sent at all.
 *
 * THE LOCK. Every path that could create something takes a MySQL advisory lock
 * keyed by order id, and does not queue behind it: a caller that cannot take the
 * lock returns immediately, because two workers both waiting to create the same
 * shipment is the exact scenario the lock exists to prevent. The lock is
 * cross-process, which an in-PHP flag would not be.
 *
 * THE STATE CHECK IS INSIDE THE LOCK. Reading the state before taking the lock
 * would be a check-then-act race: two requests can both read 'none' and both
 * proceed. The state is therefore re-read after the lock is held.
 *
 * TWO DOORS, ONE LOCK. create_shipment() begins at createOrder;
 * resume_barcode() begins at createbarcode and can never reach createOrder.
 * They share the lock and each accepts a disjoint set of states, so an order
 * whose carrier order exists but whose barcode does not can be finished without
 * the order ever being registered twice.
 *
 * A CANCELLATION IS CONFIRMED BY READING THE OBJECT THAT WAS CANCELLED. Not by
 * the carrier's acknowledgement, and not by reading a different object: see
 * cancel().
 *
 * NOTHING RUNS BY ITSELF. There is no order-status hook, no checkout hook and
 * no cron entry that calls create_shipment() or resume_barcode(). Both are
 * reached from the order screen's explicit buttons and from nowhere else.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Manager {

	private const CREATE_LOCK_PREFIX = 'kuka_ship_create_';

	/**
	 * Configuration name that selects the carrier this shop books with.
	 *
	 * A constant or an environment variable, read by name so this class never
	 * has to mention an adapter. Nothing is shipped set: an unconfigured shop
	 * with one adapter registered uses that one, and a shop with two must say
	 * which.
	 */
	public const DEFAULT_CARRIER_SETTING = 'KUKA_SHIPPING_DEFAULT_CARRIER';

	private Kuka_Island_Shipping_Carrier_Registry $registry;

	public function __construct( ?Kuka_Island_Shipping_Carrier_Registry $registry = null ) {
		$this->registry = $registry ?? new Kuka_Island_Shipping_Carrier_Registry();
	}

	public function get_registry(): Kuka_Island_Shipping_Carrier_Registry {
		return $this->registry;
	}

	/**
	 * The carrier this shop books with.
	 *
	 * A single default rather than a per-order choice, because a per-order
	 * choice with one registered carrier is a setting nobody would ever change
	 * and a screen nobody would read.
	 *
	 * DELIBERATELY NOT AN ADAPTER NAME. This class must be readable, and
	 * testable, without knowing which couriers exist; naming one here is what
	 * made the "a second carrier is only an adapter plus a filter" claim
	 * untrue. The key comes from configuration and the filter is the seam a
	 * multi-carrier shop uses.
	 *
	 * FAIL-CLOSED IN BOTH DIRECTIONS.
	 *
	 * A configured key that no adapter answers to is returned UNCHANGED, so the
	 * caller looks it up, finds nothing and refuses with carrier_not_registered
	 * before any network call. Substituting "the carrier that happens to be
	 * registered" would hand a parcel to a courier nobody chose.
	 *
	 * With nothing configured, one registered adapter is used -- one adapter is
	 * not a choice -- and two or more yield '', which is again a refusal rather
	 * than a guess.
	 */
	public function default_carrier_key(): string {
		$configured = '';

		if ( defined( self::DEFAULT_CARRIER_SETTING ) ) {
			$configured = (string) constant( self::DEFAULT_CARRIER_SETTING );
		} else {
			$from_env = getenv( self::DEFAULT_CARRIER_SETTING );

			if ( false !== $from_env ) {
				$configured = (string) $from_env;
			}
		}

		/**
		 * The carrier key used when an order does not name one.
		 *
		 * @since 0.1.0
		 *
		 * @param string             $key  Configured carrier key, '' when unset.
		 * @param array<int, string> $keys Keys every registered adapter answers to.
		 */
		$configured = strtolower( trim( (string) apply_filters( 'kuka_island_shipping_default_carrier', $configured, $this->registry->keys() ) ) );

		if ( '' !== $configured ) {
			return $configured;
		}

		$keys = $this->registry->keys();

		return 1 === count( $keys ) ? (string) $keys[0] : '';
	}

	/* ---------------------------------------------------------------------- */
	/* Creation                                                                */
	/* ---------------------------------------------------------------------- */

	/**
	 * The gates every operator action that WRITES at the carrier shares.
	 *
	 * Extracted so the barcode-resume door cannot accidentally have fewer of
	 * them than the create door: a second copy of five checks is a second copy
	 * that drifts. Every refusal here happens BEFORE any network call and is
	 * recorded on the order.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key, '' for the default.
	 * @return array{carrier: ?Kuka_Island_Shipping_Carrier_Interface, refusal: array<string, mixed>}
	 */
	private function preflight( WC_Order $order, string $carrier_key ): array {
		$carrier_key = '' !== $carrier_key ? $carrier_key : $this->default_carrier_key();
		$carrier     = $this->registry->get( $carrier_key );

		if ( null === $carrier ) {
			return array(
				'carrier' => null,
				'refusal' => $this->refuse( $order, 'carrier_not_registered', __( 'Bu kargo firması kayıtlı değil; hiçbir çağrı yapılmadı.', 'kuka-island-shipping-automation' ) ),
			);
		}

		if ( Kuka_Island_Shipping_Runtime_Gate::is_disabled() ) {
			return array(
				'carrier' => $carrier,
				'refusal' => $this->refuse( $order, Kuka_Island_Shipping_Runtime_Gate::CODE, Kuka_Island_Shipping_Runtime_Gate::message() ),
			);
		}

		$readiness = $carrier->get_readiness();

		if ( $readiness['live_blocked'] ) {
			return array(
				'carrier' => $carrier,
				'refusal' => $this->refuse(
					$order,
					'live_environment_blocked',
					__( 'Canlı ortam bloke: resmî üretim uçları doğrulanmadı. Hiçbir çağrı yapılmadı.', 'kuka-island-shipping-automation' )
				),
			);
		}

		if ( ! $readiness['ready'] ) {
			return array(
				'carrier' => $carrier,
				'refusal' => $this->refuse(
					$order,
					'credentials_missing',
					sprintf(
						/* translators: %s: comma separated configuration field names. */
						__( 'Kargo kimlik yapılandırması eksik (%s). Hiçbir çağrı yapılmadı.', 'kuka-island-shipping-automation' ),
						implode( ', ', $readiness['gaps'] )
					)
				),
			);
		}

		$cod = self::cod_gate( $order );

		if ( ! $cod['ok'] ) {
			return array(
				'carrier' => $carrier,
				'refusal' => $this->refuse( $order, 'cod_not_supported', $cod['message'] ),
			);
		}

		return array(
			'carrier' => $carrier,
			'refusal' => array(),
		);
	}

	/**
	 * Book a shipment for one order.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key, '' for the default.
	 * @return array{ok: bool, state: string, code: string, message: string, detail: string}
	 */
	public function create_shipment( WC_Order $order, string $carrier_key = '' ): array {
		$preflight = $this->preflight( $order, $carrier_key );

		if ( array() !== $preflight['refusal'] ) {
			return $preflight['refusal'];
		}

		$carrier = $preflight['carrier'];

		if ( ! $this->acquire_lock( self::CREATE_LOCK_PREFIX . $order->get_id() ) ) {
			// Somebody else holds it. Not queued behind, and nothing recorded:
			// the other holder is the one whose outcome counts.
			return array(
				'ok'      => false,
				'state'   => Kuka_Island_Shipping_Order_Store::get_state( $order ),
				'code'    => 'lock_contended',
				'message' => __( 'Bu sipariş için başka bir kargo işlemi sürüyor. Yeni çağrı yapılmadı.', 'kuka-island-shipping-automation' ),
				'detail'  => '',
			);
		}

		try {
			// Re-read INSIDE the lock. The value read before the lock was taken
			// belongs to a moment that has passed.
			$order = wc_get_order( $order->get_id() ) ?: $order;
			$state = Kuka_Island_Shipping_Order_Store::get_state( $order );

			if ( in_array( $state, Kuka_Island_Shipping_Order_Store::states_blocking_create(), true ) ) {
				return array(
					'ok'      => false,
					'state'   => $state,
					'code'    => 'already_in_progress',
					'message' => self::state_message( $state ),
					'detail'  => '',
				);
			}

			return $this->run_creation( $order, $carrier );
		} finally {
			$this->release_lock( self::CREATE_LOCK_PREFIX . $order->get_id() );
		}
	}

	/**
	 * The creation sequence, with the lock already held.
	 *
	 * @param WC_Order                                $order   Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface  $carrier Carrier.
	 * @return array{ok: bool, state: string, code: string, message: string, detail: string}
	 */
	private function run_creation( WC_Order $order, Kuka_Island_Shipping_Carrier_Interface $carrier ): array {
		$reference = Kuka_Island_Shipping_Order_Store::reference( $order );
		$request   = $this->build_request( $order, $carrier, $reference );

		if ( ! $request['ok'] ) {
			Kuka_Island_Shipping_Order_Store::save_blocked( $order, $request['code'], $request['message'] );
			$this->note( $order, $request['message'] );

			return array(
				'ok'      => false,
				'state'   => Kuka_Island_Shipping_Order_Store::get_state( $order ),
				'code'    => $request['code'],
				'message' => $request['message'],
				'detail'  => '',
			);
		}

		$shipment = $request['shipment'];
		$state    = Kuka_Island_Shipping_Order_Store::get_state( $order );

		// STATE_ABSENT_CONFIRMED means a reconciliation proved nothing exists
		// under this reference, so a create is legitimate again.
		if ( Kuka_Island_Shipping_Order_Store::STATE_NONE === $state
			|| Kuka_Island_Shipping_Order_Store::STATE_BLOCKED === $state
			|| Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED === $state ) {

			$created = $carrier->create_order( $shipment );

			if ( $created->is_uncertain() ) {
				return $this->handle_uncertain( $order, $carrier, $reference, $created );
			}

			if ( ! $created->is_success() ) {
				return $this->record_failure( $order, $created );
			}

			Kuka_Island_Shipping_Order_Store::save_order_created(
				$order,
				$carrier->get_key(),
				(string) $created->get( 'order_invoice_id', '' )
			);
			$this->note( $order, __( 'Taşıyıcıda sipariş oluşturuldu.', 'kuka-island-shipping-automation' ) . ' ' . $created->to_safe_line() );
		}

		return $this->run_barcode( $order, $carrier, $reference, $shipment );
	}

	/**
	 * Turn a registered order into a shipment.
	 *
	 * @param WC_Order                               $order     Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier   Carrier.
	 * @param string                                 $reference Carrier reference.
	 * @param array<string, mixed>                   $shipment  Shipment request.
	 * @return array{ok: bool, state: string, code: string, message: string, detail: string}
	 */
	private function run_barcode( WC_Order $order, Kuka_Island_Shipping_Carrier_Interface $carrier, string $reference, array $shipment ): array {
		$barcoded = $carrier->create_barcode( $shipment );

		if ( $barcoded->is_uncertain() ) {
			return $this->handle_uncertain( $order, $carrier, $reference, $barcoded );
		}

		if ( ! $barcoded->is_success() ) {
			return $this->record_failure( $order, $barcoded );
		}

		$shipment_id = (string) $barcoded->get( 'shipment_id', '' );
		$barcodes    = array_values( array_filter( explode( ',', (string) $barcoded->get( 'barcodes', '' ) ) ) );

		Kuka_Island_Shipping_Order_Store::save_shipment_created( $order, $shipment_id, $barcodes );

		$this->write_fulfillment( $order, $carrier, $reference, $shipment_id, $barcodes );

		// A first status query, once. Everything after it is booked by the
		// poller itself, and only while the status says the parcel is moving.
		Kuka_Island_Shipping_Status_Poller::schedule_query( (int) $order->get_id() );

		$this->note( $order, __( 'Taşıyıcıda gönderi oluşturuldu.', 'kuka-island-shipping-automation' ) . ' ' . $barcoded->to_safe_line() );

		return array(
			'ok'      => true,
			'state'   => Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED,
			'code'    => '',
			'message' => __( 'Kargo gönderisi oluşturuldu.', 'kuka-island-shipping-automation' ),
			'detail'  => $barcoded->to_safe_line(),
		);
	}

	/**
	 * Continue a shipment whose carrier ORDER exists but whose barcode never did.
	 *
	 * THE DEAD END THIS EXISTS FOR. createOrder succeeded and createbarcode did
	 * not -- it failed, or it timed out and the reconciliation then found the
	 * order and nothing else. The order sits in STATE_ORDER_CREATED, which
	 * states_blocking_create() refuses, and refuses correctly: create_shipment()
	 * begins by calling createOrder, and calling it again would register a
	 * second order at the carrier. Before this method existed the shipment could
	 * not be finished at all, and the operator's only route was to cancel a
	 * carrier order they could not see.
	 *
	 * createOrder IS UNREACHABLE FROM HERE. The only carrier write on this path
	 * is create_barcode(), through the same run_barcode() the create path uses,
	 * so an uncertain barcode still lands in read-only reconciliation and is
	 * still never repeated.
	 *
	 * EXACTLY ONE STATE IS ACCEPTED. Not "anything that is not shipment_created"
	 * -- a list of what is refused grows a hole the first time a state is added.
	 * The state is re-read INSIDE the lock, and the lock is the SAME one
	 * create_shipment() takes, so a create and a resume can never overlap and a
	 * double press cannot produce a second barcode: the second holder finds
	 * shipment_created and stops.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key, '' for the default.
	 * @return array{ok: bool, state: string, code: string, message: string, detail: string}
	 */
	public function resume_barcode( WC_Order $order, string $carrier_key = '' ): array {
		$preflight = $this->preflight( $order, $carrier_key );

		if ( array() !== $preflight['refusal'] ) {
			return $preflight['refusal'];
		}

		$carrier = $preflight['carrier'];

		if ( ! $this->acquire_lock( self::CREATE_LOCK_PREFIX . $order->get_id() ) ) {
			return array(
				'ok'      => false,
				'state'   => Kuka_Island_Shipping_Order_Store::get_state( $order ),
				'code'    => 'lock_contended',
				'message' => __( 'Bu sipariş için başka bir kargo işlemi sürüyor. Yeni çağrı yapılmadı.', 'kuka-island-shipping-automation' ),
				'detail'  => '',
			);
		}

		try {
			// Re-read INSIDE the lock. The state read before the lock was taken
			// belongs to a moment that has passed, and the moment that matters
			// is this one.
			$order = wc_get_order( $order->get_id() ) ?: $order;
			$state = Kuka_Island_Shipping_Order_Store::get_state( $order );

			if ( Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED !== $state ) {
				return array(
					'ok'      => false,
					'state'   => $state,
					'code'    => 'not_resumable',
					'message' => self::resume_refusal_message( $state ),
					'detail'  => '',
				);
			}

			$reference = Kuka_Island_Shipping_Order_Store::reference( $order );
			$request   = $this->build_request( $order, $carrier, $reference );

			if ( ! $request['ok'] ) {
				Kuka_Island_Shipping_Order_Store::save_blocked( $order, $request['code'], $request['message'] );
				$this->note( $order, $request['message'] );

				return array(
					'ok'      => false,
					'state'   => Kuka_Island_Shipping_Order_Store::get_state( $order ),
					'code'    => $request['code'],
					'message' => $request['message'],
					'detail'  => '',
				);
			}

			return $this->run_barcode( $order, $carrier, $reference, $request['shipment'] );
		} finally {
			$this->release_lock( self::CREATE_LOCK_PREFIX . $order->get_id() );
		}
	}

	/**
	 * Write the confirmed shipment into WooCommerce's own fulfilment record.
	 *
	 * A failure here never undoes the shipment: the parcel exists at the
	 * carrier whatever WooCommerce managed to store, and pretending otherwise
	 * would be the start of a duplicate.
	 *
	 * @param WC_Order                               $order       Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier     Carrier.
	 * @param string                                 $reference   Carrier reference.
	 * @param string                                 $shipment_id Shipment id.
	 * @param array<int, string>                     $barcodes    Piece barcodes.
	 */
	private function write_fulfillment( WC_Order $order, Kuka_Island_Shipping_Carrier_Interface $carrier, string $reference, string $shipment_id, array $barcodes ): void {
		$data = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );

		/*
		 * Asked of the CONTRACT, not of a class name. The previous
		 * `instanceof DHL_Provider` here meant every other adapter silently got
		 * "unmeasured" for ever, however well its own mapping was known -- which
		 * is not carrier-agnostic, it is one carrier with a fallback.
		 */
		$source = $carrier->get_tracking_number_source();

		$written = Kuka_Island_Shipping_Fulfillment_Writer::record_shipment(
			$order,
			$carrier->get_key(),
			$reference,
			$shipment_id,
			$barcodes,
			(string) $data['tracking_url'],
			$source
		);

		if ( ! $written['ok'] ) {
			$this->note(
				$order,
				sprintf(
					/* translators: %s: safe reason code. */
					__( 'Gönderi taşıyıcıda oluştu fakat WooCommerce fulfillment kaydı yazılamadı (%s). Manuel kargo kaydı hâlâ kullanılabilir.', 'kuka-island-shipping-automation' ),
					$written['reason']
				)
			);

			return;
		}

		if ( ! $written['tracking_number_set'] ) {
			$this->note(
				$order,
				__( 'Fulfillment kaydı yazıldı. Takip numarası alanı boş bırakıldı: taşıyıcı yanıtındaki hangi değerin WooCommerce takip numarası olduğu sandbox ölçümüyle doğrulanmadı.', 'kuka-island-shipping-automation' )
			);
		}
	}

	/* ---------------------------------------------------------------------- */
	/* Reconciliation                                                          */
	/* ---------------------------------------------------------------------- */

	/**
	 * React to an uncertain write: record it, then read.
	 *
	 * @param WC_Order                               $order     Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier   Carrier.
	 * @param string                                 $reference Carrier reference.
	 * @param Kuka_Island_Shipping_Result            $result    The uncertain answer.
	 * @return array{ok: bool, state: string, code: string, message: string, detail: string}
	 */
	private function handle_uncertain( WC_Order $order, Kuka_Island_Shipping_Carrier_Interface $carrier, string $reference, Kuka_Island_Shipping_Result $result ): array {
		Kuka_Island_Shipping_Order_Store::save_uncertain( $order, $result->get_operation(), $result->get_safe_error_code() );
		$this->note(
			$order,
			__( 'Belirsiz taşıyıcı yanıtı. Yeniden gönderim YAPILMADI; salt-okunur mutabakat başlatıldı.', 'kuka-island-shipping-automation' ) . ' ' . $result->to_safe_line()
		);

		$verdict = $this->reconcile( $order, $carrier, $reference );

		return array(
			'ok'      => false,
			'state'   => Kuka_Island_Shipping_Order_Store::get_state( $order ),
			'code'    => $result->get_safe_error_code(),
			'message' => $verdict['message'],
			'detail'  => $result->to_safe_line() . '|reconcile:' . $verdict['verdict'],
		);
	}

	/**
	 * Establish what actually exists at the carrier, using reads only.
	 *
	 * Order matters: the shipment is asked about first, because a shipment
	 * existing implies the order existed. 404 on both is the only evidence this
	 * integration accepts for "nothing was created" -- absence is proved, never
	 * assumed from a timeout.
	 *
	 * @param WC_Order                               $order     Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier   Carrier.
	 * @param string                                 $reference Carrier reference.
	 * @return array{verdict: string, message: string}
	 */
	public function reconcile( WC_Order $order, Kuka_Island_Shipping_Carrier_Interface $carrier, string $reference ): array {
		$shipment = $carrier->read_shipment( $reference );

		if ( $shipment->is_success() ) {
			$shipment_id = (string) $shipment->get( 'shipment_id', '' );

			Kuka_Island_Shipping_Order_Store::save_shipment_created( $order, $shipment_id, array() );
			$this->write_fulfillment( $order, $carrier, $reference, $shipment_id, array() );
			Kuka_Island_Shipping_Status_Poller::schedule_query( (int) $order->get_id() );

			$message = __( 'Mutabakat: gönderi taşıyıcıda mevcut. Yeni gönderi oluşturulmadı.', 'kuka-island-shipping-automation' );
			$this->note( $order, $message );

			return array(
				'verdict' => 'shipment_present',
				'message' => $message,
			);
		}

		$carrier_order = $carrier->read_order( $reference );

		if ( $carrier_order->is_success() ) {
			Kuka_Island_Shipping_Order_Store::save_order_created( $order, $carrier->get_key(), '' );

			$message = __( 'Mutabakat: sipariş taşıyıcıda mevcut, gönderiye dönüşmemiş. Yeni sipariş oluşturulmadı.', 'kuka-island-shipping-automation' );
			$this->note( $order, $message );

			return array(
				'verdict' => 'order_present',
				'message' => $message,
			);
		}

		// Absence counts only when BOTH reads answered, and both said not found.
		if ( 'not_found' === $shipment->get_safe_error_code() && 'not_found' === $carrier_order->get_safe_error_code() ) {
			Kuka_Island_Shipping_Order_Store::set_state(
				$order,
				Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED,
				__( 'Mutabakat: taşıyıcıda bu referansla sipariş veya gönderi bulunamadı.', 'kuka-island-shipping-automation' )
			);

			$message = __( 'Mutabakat sonucu: taşıyıcıda kayıt yok. Yeniden oluşturma ayrı ve açık bir işlemdir; otomatik yapılmadı.', 'kuka-island-shipping-automation' );
			$this->note( $order, $message );

			return array(
				'verdict' => 'absent_confirmed',
				'message' => $message,
			);
		}

		$message = __( 'Mutabakat sonuçsuz: taşıyıcı sorguları cevap veremedi. Durum belirsiz kaldı, hiçbir gönderim yapılmadı.', 'kuka-island-shipping-automation' );
		$this->note( $order, $message . ' ' . $shipment->to_safe_line() );

		return array(
			'verdict' => 'inconclusive',
			'message' => $message,
		);
	}

	/**
	 * Run a reconciliation from the order screen.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key, '' for the default.
	 * @return array{verdict: string, message: string}
	 */
	public function reconcile_order( WC_Order $order, string $carrier_key = '' ): array {
		$carrier = $this->registry->get( '' !== $carrier_key ? $carrier_key : $this->default_carrier_key() );

		if ( null === $carrier ) {
			return array(
				'verdict' => 'carrier_not_registered',
				'message' => __( 'Bu kargo firması kayıtlı değil; hiçbir çağrı yapılmadı.', 'kuka-island-shipping-automation' ),
			);
		}

		$reference = (string) Kuka_Island_Shipping_Order_Store::get_shipment_data( $order )['reference'];

		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
			return array(
				'verdict' => 'no_reference',
				'message' => __( 'Bu siparişte taşıyıcı referansı yok; sorgulanacak bir kayıt bulunmuyor.', 'kuka-island-shipping-automation' ),
			);
		}

		return $this->reconcile( $order, $carrier, $reference );
	}

	/* ---------------------------------------------------------------------- */
	/* Status                                                                  */
	/* ---------------------------------------------------------------------- */

	/**
	 * Read the carrier status once and record it.
	 *
	 * THE ATTEMPT IS BOOKED HERE, once, for every query that was actually
	 * issued -- successful, transient or permanent alike. This method is the only
	 * place in the plugin that knows a status request left the building, so it is
	 * the only place that may spend the poller's budget. The refusals above the
	 * call made no request and are therefore not attempts.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key, '' for the default.
	 * @return array{ok: bool, lifecycle: string, code: string, message: string, detail: string, attempts: int}
	 */
	public function query_status( WC_Order $order, string $carrier_key = '' ): array {
		$carrier = $this->registry->get( '' !== $carrier_key ? $carrier_key : $this->default_carrier_key() );

		if ( null === $carrier ) {
			return array(
				'ok'        => false,
				'lifecycle' => Kuka_Island_Shipping_Status::LIFECYCLE_UNKNOWN,
				'code'      => 'carrier_not_registered',
				'message'   => __( 'Bu kargo firması kayıtlı değil; hiçbir çağrı yapılmadı.', 'kuka-island-shipping-automation' ),
				'detail'    => '',
				'attempts'  => Kuka_Island_Shipping_Order_Store::query_attempts( $order ),
			);
		}

		$data      = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
		$reference = (string) $data['reference'];

		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
			return array(
				'ok'        => false,
				'lifecycle' => Kuka_Island_Shipping_Status::LIFECYCLE_UNKNOWN,
				'code'      => 'no_reference',
				'message'   => __( 'Bu siparişte taşıyıcı referansı yok.', 'kuka-island-shipping-automation' ),
				'detail'    => '',
				'attempts'  => (int) $data['query_attempts'],
			);
		}

		$result = $carrier->read_shipment_status( $reference );

		/*
		 * The query happened. Whatever came back -- an answer, a 500, silence --
		 * it costs one attempt, and the cost is recorded before the outcome is
		 * even looked at so no branch below can forget it. The counter used to
		 * move only on success, which meant a chain of failures re-derived the
		 * same "attempts + 1" from the same stale value on every turn and the
		 * ceiling was never reached.
		 */
		$attempts = Kuka_Island_Shipping_Order_Store::record_query_attempt( $order );

		if ( ! $result->is_success() ) {
			Kuka_Island_Shipping_Order_Store::save_failure(
				$order,
				'get_shipment_status',
				$result->get_safe_error_code(),
				sprintf(
					/* translators: 1: safe operation summary, 2: attempt number, 3: attempt ceiling. */
					__( 'Durum sorgusu başarısız (%1$s). Deneme %2$d/%3$d.', 'kuka-island-shipping-automation' ),
					$result->to_safe_line(),
					$attempts,
					Kuka_Island_Shipping_Status_Poller::MAX_ATTEMPTS
				)
			);

			return array(
				'ok'        => false,
				'lifecycle' => Kuka_Island_Shipping_Status::LIFECYCLE_UNKNOWN,
				'code'      => $result->get_safe_error_code(),
				'message'   => __( 'Kargo durumu okunamadı.', 'kuka-island-shipping-automation' ),
				'detail'    => $result->to_safe_line(),
				'attempts'  => $attempts,
			);
		}

		$raw_code  = $result->get( 'status_code', '' );
		$lifecycle = Kuka_Island_Shipping_Order_Store::save_status( $order, $raw_code, (string) $result->get( 'tracking_url', '' ) );

		Kuka_Island_Shipping_Fulfillment_Writer::sync_status( $order, $reference, $raw_code );

		if ( Kuka_Island_Shipping_Status::LIFECYCLE_MANUAL_REVIEW === $lifecycle ) {
			$this->note(
				$order,
				sprintf(
					/* translators: %s: status label. */
					__( 'Kargo durumu manuel inceleme gerektiriyor: %s', 'kuka-island-shipping-automation' ),
					Kuka_Island_Shipping_Status::label_for( $raw_code )
				)
			);
		}

		return array(
			'ok'        => true,
			'lifecycle' => $lifecycle,
			'code'      => '',
			'message'   => Kuka_Island_Shipping_Status::label_for( $raw_code ),
			'detail'    => $result->to_safe_line(),
			'attempts'  => $attempts,
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Operator-controlled amendments                                          */
	/* ---------------------------------------------------------------------- */

	/**
	 * Amend the registered order or the shipment, whichever exists.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key, '' for the default.
	 * @return array{ok: bool, code: string, message: string, detail: string}
	 */
	public function update_shipment( WC_Order $order, string $carrier_key = '' ): array {
		$carrier = $this->registry->get( '' !== $carrier_key ? $carrier_key : $this->default_carrier_key() );

		if ( null === $carrier ) {
			return $this->simple( false, 'carrier_not_registered', __( 'Bu kargo firması kayıtlı değil.', 'kuka-island-shipping-automation' ) );
		}

		$data      = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
		$reference = (string) $data['reference'];
		$request   = $this->build_request( $order, $carrier, $reference );

		if ( ! $request['ok'] ) {
			return $this->simple( false, $request['code'], $request['message'] );
		}

		$shipment = $request['shipment'];

		if ( Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $data['state'] && '' !== $data['shipment_id'] ) {
			$shipment['shipment_id'] = $data['shipment_id'];
			$result                  = $carrier->update_shipment( $shipment );
		} elseif ( Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $data['state'] ) {
			$result = $carrier->update_order( $shipment );
		} else {
			return $this->simple(
				false,
				'nothing_to_update',
				__( 'Bu siparişte güncellenebilecek bir taşıyıcı kaydı yok.', 'kuka-island-shipping-automation' )
			);
		}

		if ( $result->is_uncertain() ) {
			Kuka_Island_Shipping_Order_Store::save_uncertain( $order, $result->get_operation(), $result->get_safe_error_code() );
			$this->note( $order, __( 'Güncelleme belirsiz sonuçlandı; tekrar denenmedi.', 'kuka-island-shipping-automation' ) . ' ' . $result->to_safe_line() );

			return $this->simple( false, $result->get_safe_error_code(), __( 'Güncelleme belirsiz sonuçlandı; tekrar denenmedi.', 'kuka-island-shipping-automation' ), $result->to_safe_line() );
		}

		if ( ! $result->is_success() ) {
			Kuka_Island_Shipping_Order_Store::save_failure( $order, $result->get_operation(), $result->get_safe_error_code(), __( 'Güncelleme reddedildi.', 'kuka-island-shipping-automation' ) . ' ' . $result->to_safe_line() );

			return $this->simple( false, $result->get_safe_error_code(), __( 'Güncelleme reddedildi.', 'kuka-island-shipping-automation' ), $result->to_safe_line() );
		}

		$this->note( $order, __( 'Taşıyıcı kaydı güncellendi.', 'kuka-island-shipping-automation' ) . ' ' . $result->to_safe_line() );

		return $this->simple( true, '', __( 'Taşıyıcı kaydı güncellendi.', 'kuka-island-shipping-automation' ), $result->to_safe_line() );
	}

	/**
	 * Cancel the shipment, or the registered order when no shipment exists.
	 *
	 * THE CONFIRMING READ MUST ASK ABOUT THE OBJECT THAT WAS CANCELLED. Both
	 * branches used to be confirmed with read_shipment(), and on the ORDER
	 * branch that is not a confirmation at all: no shipment was ever created
	 * under this reference, so getshipment answers not_found whether the
	 * cancellation worked or not. A cancelorder the carrier acknowledged but
	 * did not perform was therefore written down as `cancelled`, the status
	 * chain was unscheduled, and a live carrier order stopped being watched by
	 * anybody. The read now follows the write: shipment for cancelshipment,
	 * order for cancelorder.
	 *
	 * AN ACKNOWLEDGEMENT IS NOT EVIDENCE. A 200 on the cancel call moves
	 * nothing on its own. Only not_found from the matching read moves the state,
	 * and a read that says "still there" or cannot answer at all leaves the
	 * order exactly where it was -- which keeps the parcel being tracked.
	 *
	 * AN UNCERTAIN CANCELLATION IS NOT REPEATED. It lands in
	 * STATE_RECONCILE_REQUIRED, and this method refuses to run again from
	 * there: a second cancel would be a second write against a state nobody has
	 * established. The read-only reconciliation is the only way out.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key, '' for the default.
	 * @return array{ok: bool, code: string, message: string, detail: string}
	 */
	public function cancel( WC_Order $order, string $carrier_key = '' ): array {
		$carrier = $this->registry->get( '' !== $carrier_key ? $carrier_key : $this->default_carrier_key() );

		if ( null === $carrier ) {
			return $this->simple( false, 'carrier_not_registered', __( 'Bu kargo firması kayıtlı değil.', 'kuka-island-shipping-automation' ) );
		}

		$data      = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
		$reference = (string) $data['reference'];

		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
			return $this->simple( false, 'no_reference', __( 'Bu siparişte taşıyıcı referansı yok.', 'kuka-island-shipping-automation' ) );
		}

		if ( Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED === $data['state'] ) {
			return $this->simple(
				false,
				'reconcile_required',
				__( 'Bu siparişte belirsiz bir taşıyıcı yanıtı var. İptal tekrarlanmadı; önce salt-okunur mutabakat çalıştırılmalı.', 'kuka-island-shipping-automation' )
			);
		}

		// WHICH object is cancelled decides WHICH read confirms it.
		if ( '' !== $data['shipment_id'] ) {
			$result       = $carrier->cancel_shipment( $reference, (string) $data['shipment_id'] );
			$confirmed_by = 'read_shipment';
		} else {
			$result       = $carrier->cancel_order( $reference );
			$confirmed_by = 'read_order';
		}

		if ( $result->is_uncertain() ) {
			Kuka_Island_Shipping_Order_Store::save_uncertain( $order, $result->get_operation(), $result->get_safe_error_code() );
			$this->note( $order, __( 'İptal belirsiz sonuçlandı; tekrar denenmedi.', 'kuka-island-shipping-automation' ) . ' ' . $result->to_safe_line() );

			return $this->simple( false, $result->get_safe_error_code(), __( 'İptal belirsiz sonuçlandı; tekrar denenmedi.', 'kuka-island-shipping-automation' ), $result->to_safe_line() );
		}

		if ( ! $result->is_success() ) {
			Kuka_Island_Shipping_Order_Store::save_failure( $order, $result->get_operation(), $result->get_safe_error_code(), __( 'İptal reddedildi.', 'kuka-island-shipping-automation' ) . ' ' . $result->to_safe_line() );

			return $this->simple( false, $result->get_safe_error_code(), __( 'İptal reddedildi.', 'kuka-island-shipping-automation' ), $result->to_safe_line() );
		}

		$confirm = 'read_shipment' === $confirmed_by
			? $carrier->read_shipment( $reference )
			: $carrier->read_order( $reference );

		$detail = $confirm->to_safe_line() . '|confirmed_by:' . $confirmed_by;

		if ( 'not_found' === $confirm->get_safe_error_code() ) {
			Kuka_Island_Shipping_Order_Store::set_state(
				$order,
				Kuka_Island_Shipping_Order_Store::STATE_CANCELLED,
				__( 'Taşıyıcı kaydı iptal edildi ve iptal salt-okunur sorguyla doğrulandı.', 'kuka-island-shipping-automation' )
			);
			Kuka_Island_Shipping_Status_Poller::cancel_queries( (int) $order->get_id() );
			$this->note( $order, __( 'Kargo kaydı iptal edildi (sorguyla doğrulandı).', 'kuka-island-shipping-automation' ) . ' ' . $detail );

			return $this->simple( true, '', __( 'Kargo kaydı iptal edildi.', 'kuka-island-shipping-automation' ), $detail );
		}

		$this->note(
			$order,
			__( 'Taşıyıcı iptali kabul etti fakat doğrulama sorgusu kaydın hâlâ var olduğunu ya da cevapsız kaldığını gösterdi. Durum değiştirilmedi.', 'kuka-island-shipping-automation' ) . ' ' . $detail
		);

		return $this->simple( false, 'cancel_unconfirmed', __( 'İptal doğrulanamadı; durum değiştirilmedi.', 'kuka-island-shipping-automation' ), $detail );
	}

	/* ---------------------------------------------------------------------- */
	/* Request building                                                        */
	/* ---------------------------------------------------------------------- */

	/**
	 * Build the carrier-agnostic shipment request from the order.
	 *
	 * @param WC_Order                               $order     Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier   Carrier.
	 * @param string                                 $reference Carrier reference.
	 * @return array{ok: bool, code: string, message: string, shipment: array<string, mixed>}
	 */
	public function build_request( WC_Order $order, Kuka_Island_Shipping_Carrier_Interface $carrier, string $reference ): array {
		$city     = $order->get_shipping_city() ?: $order->get_billing_city();
		$district = $order->get_shipping_state() ?: $order->get_billing_state();

		/*
		 * WooCommerce stores the Turkish district in the state field for TR
		 * addresses, and the state field can hold a code (TR34) rather than a
		 * name. address_2 is where a shop's checkout commonly collects the
		 * district as free text, so it is preferred when present -- and when
		 * neither yields anything the request is refused rather than sent with
		 * a guess.
		 */
		$address_2 = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
		if ( '' !== trim( (string) $address_2 ) ) {
			$district = $address_2;
		}

		$located = $carrier->resolve_location( (string) $city, (string) $district );

		if ( ! $located->is_success() ) {
			return array(
				'ok'       => false,
				'code'     => $located->get_safe_error_code(),
				'message'  => self::location_message( $located->get_safe_error_code() ),
				'shipment' => array(),
			);
		}

		$name = trim( $order->get_formatted_shipping_full_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_formatted_billing_full_name() );
		}

		$address = trim( (string) ( $order->get_shipping_address_1() ?: $order->get_billing_address_1() ) );

		$shipment = array(
			'reference'          => $reference,
			'service'            => 'standard',
			'packaging'          => 'package',
			'payment'            => 'sender',
			'delivery'           => 'to_address',
			'content'            => self::order_content( $order ),
			'description'        => sprintf(
				/* translators: %s: WooCommerce order number. */
				__( 'Sipariş %s', 'kuka-island-shipping-automation' ),
				$order->get_order_number()
			),
			'bill_of_landing_id' => '',
			/*
			 * SMS preferences default to off, all three of them. The carrier
			 * would message the customer on this shop's behalf, and consent for
			 * that is not something an integration may assume; a shop that wants
			 * them turns them on through the filter below.
			 */
			'sms1'               => 0,
			'sms2'               => 0,
			'sms3'               => 0,
			'cod'                => array(
				'enabled' => false,
				'amount'  => 0,
			),
			'pieces'             => array(
				array(
					'barcode' => Kuka_Island_Shipping_Reference::piece_barcode( $reference, 1 ),
					'desi'    => self::order_desi( $order ),
					'kg'      => self::order_kg( $order ),
					'content' => self::order_content( $order ),
				),
			),
			'recipient'          => array(
				'ref_customer_id' => (string) $order->get_id(),
				'full_name'       => $name,
				'address'         => $address,
				'city_code'       => (int) $located->get( 'city_code', 0 ),
				'district_code'   => (int) $located->get( 'district_code', 0 ),
				'email'           => (string) $order->get_billing_email(),
				'mobile_phone'    => (string) ( $order->get_shipping_phone() ?: $order->get_billing_phone() ),
				'home_phone'      => '',
				'business_phone'  => '',
				'tax_office'      => '',
				'tax_number'      => '',
			),
		);

		/**
		 * Adjust the shipment request before it reaches the carrier adapter.
		 *
		 * The place a shop turns SMS on, changes the packaging type or fills in
		 * a bill-of-landing number. Filtering cannot switch cash on delivery on:
		 * the adapter refuses it independently.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $shipment Shipment request.
		 * @param WC_Order             $order    Order.
		 */
		$shipment = (array) apply_filters( 'kuka_island_shipping_request', $shipment, $order );

		return array(
			'ok'       => true,
			'code'     => '',
			'message'  => '',
			'shipment' => $shipment,
		);
	}

	/**
	 * A short, non-identifying description of what is in the parcel.
	 *
	 * Product names, because that is what a courier's own paperwork carries and
	 * what a customs or damage claim needs. Bounded, and deduplicated so a
	 * ten-line order does not produce a paragraph.
	 */
	public static function order_content( WC_Order $order ): string {
		$names = array();

		foreach ( $order->get_items() as $item ) {
			$name = trim( (string) $item->get_name() );

			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}

			if ( count( $names ) >= 5 ) {
				break;
			}
		}

		$content = implode( ', ', $names );

		return '' !== $content ? $content : __( 'Tekstil ürünü', 'kuka-island-shipping-automation' );
	}

	/**
	 * Total billable weight in whole kilograms, at least 1.
	 *
	 * Rounded UP, because a parcel that weighs 1.2 kg is not a 1 kg parcel to a
	 * courier, and understating it is what produces a surcharge nobody expected.
	 */
	public static function order_kg( WC_Order $order ): int {
		$total = 0.0;

		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;

			if ( $product instanceof WC_Product && '' !== (string) $product->get_weight() ) {
				$total += (float) $product->get_weight() * max( 1, (int) $item->get_quantity() );
			}
		}

		return max( 1, (int) ceil( $total ) );
	}

	/**
	 * Volumetric weight ("desi") in whole units, at least 1.
	 *
	 * Turkish couriers price on length x width x height in centimetres divided
	 * by 3000. Products without dimensions contribute nothing rather than a
	 * guessed box size, so an order with no dimensions anywhere returns 1 and
	 * the courier reweighs it -- which is the honest outcome.
	 */
	public static function order_desi( WC_Order $order ): int {
		$total = 0.0;

		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$length = (float) $product->get_length();
			$width  = (float) $product->get_width();
			$height = (float) $product->get_height();

			if ( $length > 0 && $width > 0 && $height > 0 ) {
				$total += ( $length * $width * $height / 3000 ) * max( 1, (int) $item->get_quantity() );
			}
		}

		return max( 1, (int) ceil( $total ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Gates and helpers                                                       */
	/* ---------------------------------------------------------------------- */

	/**
	 * Cash on delivery: refused, and refused by looking at the order.
	 *
	 * The check is on the ORDER's payment method, not only on a configuration
	 * switch, because the dangerous case is a COD order being shipped as if it
	 * were prepaid: the parcel goes out, the courier collects nothing, and the
	 * shop has given away the goods.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function cod_gate( WC_Order $order ): array {
		$method = strtolower( (string) $order->get_payment_method() );

		$is_cod = in_array( $method, array( 'cod', 'kapida_odeme', 'cash_on_delivery' ), true )
			|| str_contains( $method, 'cod' )
			|| str_contains( $method, 'kapida' );

		if ( ! $is_cod ) {
			return array(
				'ok'      => true,
				'message' => '',
			);
		}

		return array(
			'ok'      => false,
			'message' => __( 'Kapıda ödeme siparişleri için otomatik kargo kapalıdır. İş kuralı ayrıca doğrulanana kadar bu sipariş manuel kargolanmalıdır.', 'kuka-island-shipping-automation' ),
		);
	}

	/**
	 * Operator-facing sentence for a location failure.
	 */
	public static function location_message( string $code ): string {
		return match ( $code ) {
			'city_missing'         => __( 'Siparişte şehir bilgisi yok.', 'kuka-island-shipping-automation' ),
			'district_missing'     => __( 'Siparişte ilçe bilgisi yok.', 'kuka-island-shipping-automation' ),
			'city_not_found'       => __( 'Şehir taşıyıcının il listesinde bulunamadı. Tahmin yapılmadı.', 'kuka-island-shipping-automation' ),
			'district_not_found'   => __( 'İlçe taşıyıcının ilçe listesinde bulunamadı. Tahmin yapılmadı.', 'kuka-island-shipping-automation' ),
			'city_ambiguous'       => __( 'Şehir adı taşıyıcı listesinde birden fazla kayda uyuyor. Seçim yapılmadı; adres elle düzeltilmeli.', 'kuka-island-shipping-automation' ),
			'district_ambiguous'   => __( 'İlçe adı taşıyıcı listesinde birden fazla kayda uyuyor. Seçim yapılmadı; adres elle düzeltilmeli.', 'kuka-island-shipping-automation' ),
			'empty_reference_data' => __( 'Taşıyıcı il/ilçe listesi boş döndü. Adres çözülemedi.', 'kuka-island-shipping-automation' ),
			default                => __( 'Adres taşıyıcı kodlarına çevrilemedi.', 'kuka-island-shipping-automation' ),
		};
	}

	/**
	 * Operator-facing sentence for a state that blocks a new shipment.
	 */
	public static function state_message( string $state ): string {
		return match ( $state ) {
			Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED      => __( 'Bu siparişin taşıyıcı kaydı zaten var. Yeni sipariş oluşturulmadı.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED   => __( 'Bu siparişin kargo gönderisi zaten oluşturulmuş. Yeni gönderi oluşturulmadı.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED => __( 'Bu siparişte belirsiz bir taşıyıcı yanıtı var. Önce salt-okunur mutabakat yapılmalı; yeniden gönderim yapılmaz.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_DELIVERED          => __( 'Bu sipariş teslim edilmiş. Yeni gönderi oluşturulmadı.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW      => __( 'Bu siparişin kargo durumu manuel inceleme bekliyor. Yeni gönderi oluşturulmadı.', 'kuka-island-shipping-automation' ),
			default                                                    => __( 'Bu sipariş için yeni gönderi oluşturulmadı.', 'kuka-island-shipping-automation' ),
		};
	}

	/**
	 * Operator-facing sentence for a state the barcode stage cannot resume from.
	 *
	 * Public and static so the exact wording can be asserted without an admin
	 * screen, exactly like state_message().
	 */
	public static function resume_refusal_message( string $state ): string {
		return match ( $state ) {
			Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED   => __( 'Bu siparişin kargo gönderisi zaten oluşturulmuş. Yeni barkod istenmedi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED => __( 'Bu siparişte belirsiz bir taşıyıcı yanıtı var. Barkod aşaması sürdürülmedi; önce salt-okunur mutabakat çalıştırılmalı.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_DELIVERED          => __( 'Bu sipariş teslim edilmiş. Barkod aşaması sürdürülmedi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW      => __( 'Bu siparişin kargo durumu manuel inceleme bekliyor. Barkod aşaması sürdürülmedi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_CANCELLED          => __( 'Bu siparişin taşıyıcı kaydı iptal edilmiş. Barkod aşaması sürdürülmedi.', 'kuka-island-shipping-automation' ),
			default                                                    => __( 'Bu siparişte taşıyıcıda bekleyen bir sipariş kaydı yok; sürdürülecek bir barkod aşaması bulunmuyor.', 'kuka-island-shipping-automation' ),
		};
	}

	/**
	 * Record and return a refusal that happened before any network call.
	 *
	 * @return array{ok: bool, state: string, code: string, message: string, detail: string}
	 */
	private function refuse( WC_Order $order, string $code, string $message ): array {
		Kuka_Island_Shipping_Order_Store::save_blocked( $order, $code, $message );
		$this->note( $order, $message );

		return array(
			'ok'      => false,
			'state'   => Kuka_Island_Shipping_Order_Store::get_state( $order ),
			'code'    => $code,
			'message' => $message,
			'detail'  => '',
		);
	}

	/**
	 * @return array{ok: bool, state: string, code: string, message: string, detail: string}
	 */
	private function record_failure( WC_Order $order, Kuka_Island_Shipping_Result $result ): array {
		$message = __( 'Taşıyıcı isteği reddedildi.', 'kuka-island-shipping-automation' );

		Kuka_Island_Shipping_Order_Store::save_failure(
			$order,
			$result->get_operation(),
			$result->get_safe_error_code(),
			$message . ' ' . $result->to_safe_line()
		);
		$this->note( $order, $message . ' ' . $result->to_safe_line() );

		return array(
			'ok'      => false,
			'state'   => Kuka_Island_Shipping_Order_Store::get_state( $order ),
			'code'    => $result->get_safe_error_code(),
			'message' => $message,
			'detail'  => $result->to_safe_line(),
		);
	}

	/**
	 * @return array{ok: bool, code: string, message: string, detail: string}
	 */
	private function simple( bool $ok, string $code, string $message, string $detail = '' ): array {
		return array(
			'ok'      => $ok,
			'code'    => $code,
			'message' => $message,
			'detail'  => $detail,
		);
	}

	/**
	 * Add an order note.
	 *
	 * Everything written here is either this project's own Turkish sentence or
	 * a Result::to_safe_line(), which contains an operation name, an outcome, an
	 * allow-listed code and a numeric HTTP status. No carrier text, no token, no
	 * credential and no request or response body ever reaches an order note.
	 */
	private function note( WC_Order $order, string $message ): void {
		$order->add_order_note( $message );
	}

	private function acquire_lock( string $key ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', substr( $key, 0, 60 ) ) );

		return '1' === (string) $acquired;
	}

	private function release_lock( string $key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', substr( $key, 0, 60 ) ) );
	}
}
