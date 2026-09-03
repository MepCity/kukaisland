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
 * THE LOCK. EVERY state-changing path -- create, barcode resume, amend, cancel
 * -- takes the same MySQL advisory lock keyed by order id, and does not queue
 * behind it: a caller that cannot take the lock returns immediately, because two
 * workers both waiting to mutate the same shipment is the exact scenario the
 * lock exists to prevent. The lock is cross-process, which an in-PHP flag would
 * not be.
 *
 * THE STATE CHECK IS INSIDE THE LOCK. Reading the state before taking the lock
 * would be a check-then-act race: two requests can both read 'none' and both
 * proceed. State, shipment id and reference are therefore all re-read after the
 * lock is held, and the request is built from that reading.
 *
 * THREE LAYERS AT THE DOOR. resolve_carrier() answers whose parcel this is;
 * carrier_gate() is the boundary every carrier operation crosses -- all six
 * writes AND all three reads -- and asks whether that carrier may be contacted
 * at all: runtime gate open, environment not blocked, credentials complete.
 * create_policy() carries what only creation is subject to, cash on delivery.
 * Keeping the last one apart matters: applying the COD refusal to cancellation
 * would make a COD order impossible to un-book.
 *
 * The claim in this docblock was once broader than the code: it said every
 * carrier operation crossed the gate while reconcile_order() and query_status()
 * did not call it at all. Both do now, and the read boundary below is what
 * makes the sentence true rather than aspirational.
 *
 * ONE CHOKE POINT FOR WRITES, ONE FOR READS. create_order, create_barcode,
 * update_order, update_shipment, cancel_order and cancel_shipment reach the
 * carrier through guarded_write(); read_shipment, read_order and
 * read_shipment_status through guarded_read(). Both ask the gate AGAIN
 * immediately beforehand. The admission check happened before the lock; the
 * plugin can be deactivated in between, and that is precisely what the runtime
 * gate is for.
 *
 * A BLOCKED READ IS NOT AN ABSENT RECORD. guarded_read() returns a refusal, not
 * a Result, so no caller can mistake a closed gate for a 404. reconcile()
 * answers 'blocked', leaves the order in reconcile_required and writes nothing.
 *
 * OWNERSHIP COMES FIRST. resolve_carrier() answers "whose parcel is this" from
 * the ORDER, never from the shop's current default, for every operation
 * including the reads. The order's carrier is pinned before the first external
 * write and is never overwritten; see Order_Store::begin_mutation().
 *
 * FOUR DOORS, ONE LOCK. create_shipment() begins at createOrder;
 * resume_barcode() begins at createbarcode and can never reach createOrder;
 * update_shipment() and cancel() each accept exactly two states and write
 * nothing from any other. The state sets are disjoint per door and the lock is
 * shared, so an order whose carrier order exists but whose barcode does not can
 * be finished without the order ever being registered twice, and a cancellation
 * cannot overlap the create it would be undoing.
 *
 * A CANCELLATION IS CONFIRMED BY READING THE OBJECT THAT WAS CANCELLED. Not by
 * the carrier's acknowledgement, and not by reading a different object. It is
 * also idempotent: an order already in 'cancelled' answers 'already_cancelled'
 * and sends nothing. See cancel().
 *
 * NOTHING RUNS BY ITSELF. There is no order-status hook, no checkout hook and
 * no cron entry that calls create_shipment(), resume_barcode(),
 * update_shipment() or cancel(). All four are reached from the order screen's
 * explicit buttons and from nowhere else.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Manager {

	/**
	 * The advisory lock every state-changing carrier operation takes.
	 *
	 * ONE FAMILY, ONE KEY PER ORDER. Creation, barcode resume, amendment and
	 * cancellation all take this same key, so they cannot interleave: a cancel
	 * that lands while a create is halfway through would otherwise cancel a
	 * record the create is still writing, and two cancels would send two
	 * cancellations. Named for what it protects rather than for the first caller
	 * that needed it -- it was 'kuka_ship_create_' while only creation held it.
	 */
	private const MUTATION_LOCK_PREFIX = 'kuka_ship_mutate_';

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

	/* ---------------------------------------------------------------------- */
	/* The safety boundary every carrier write crosses                         */
	/* ---------------------------------------------------------------------- */

	/**
	 * Is the carrier contactable AT THIS INSTANT?
	 *
	 * Three conditions, and each of them can change while an operation is in
	 * flight: an operator deactivates the plugin, an administrator switches the
	 * environment, a constant is removed from wp-config.php. So this is not a
	 * one-off admission check -- it is a predicate, cheap enough to ask twice,
	 * and gate_closed_now() is asked again immediately before every write.
	 *
	 * The runtime gate is read straight from the options table on every call
	 * (see Runtime_Gate::is_disabled), which is what makes a second reading
	 * meaningful rather than a cached repeat of the first.
	 *
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier Carrier.
	 * @return array{code: string, message: string} Empty when the gate is open.
	 */
	private function gate_closed_now( Kuka_Island_Shipping_Carrier_Interface $carrier ): array {
		if ( Kuka_Island_Shipping_Runtime_Gate::is_disabled() ) {
			return array(
				'code'    => Kuka_Island_Shipping_Runtime_Gate::CODE,
				'message' => Kuka_Island_Shipping_Runtime_Gate::message(),
			);
		}

		$readiness = $carrier->get_readiness();

		if ( $readiness['live_blocked'] ) {
			return array(
				'code'    => 'live_environment_blocked',
				'message' => __( 'Canlı ortam bloke: resmî üretim uçları doğrulanmadı. Hiçbir çağrı yapılmadı.', 'kuka-island-shipping-automation' ),
			);
		}

		if ( ! $readiness['ready'] ) {
			return array(
				'code'    => 'credentials_missing',
				'message' => sprintf(
					/* translators: %s: comma separated configuration field names. */
					__( 'Kargo kimlik yapılandırması eksik (%s). Hiçbir çağrı yapılmadı.', 'kuka-island-shipping-automation' ),
					implode( ', ', $readiness['gaps'] )
				),
			);
		}

		return array();
	}

	/**
	 * LAYER ZERO: WHOSE parcel is this?
	 *
	 * Answered before anything else, from the ORDER rather than from the shop's
	 * settings, and answered the same way for every operation -- create, resume,
	 * query, poll, amend, cancel and every reconciliation read.
	 *
	 * THE BUG THIS EXISTS FOR. Every entry point used to fall back to
	 * default_carrier_key() when no key was passed, which is every call the
	 * poller and the admin screen make. A shop that added a second adapter and
	 * changed its default would then have had yesterday's DHL shipments queried,
	 * amended and CANCELLED at the new courier -- a courier that never had the
	 * parcel, answering "not found", which this integration reads as proof that
	 * a cancellation succeeded.
	 *
	 * THREE CASES, AND ONLY ONE OF THEM MAY USE A DEFAULT.
	 *
	 *   pinned    The order names its carrier. That carrier is used. An explicit
	 *             key that disagrees is refused with shipment_provider_mismatch
	 *             -- not silently honoured, and not silently ignored.
	 *   orphaned  Something was addressed at some carrier (see
	 *             Order_Store::has_carrier_evidence) but no owner is recorded.
	 *             A record written before ownership was pinned. Refused with
	 *             shipment_provider_missing: nobody knows which courier to ask,
	 *             and a default is a guess with a parcel attached.
	 *   untouched Nothing has ever been sent. Only here may the configured
	 *             default decide, and the decision is pinned before the first
	 *             write.
	 *
	 * Public and side-effect free so the order screen can ask the same question
	 * the operations ask, without writing anything while rendering.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key the caller asked for, '' for none.
	 * @return array{key: string, source: string, code: string, message: string} source is order|orphaned|requested|default.
	 */
	public function carrier_ownership( WC_Order $order, string $carrier_key = '' ): array {
		$requested = strtolower( trim( $carrier_key ) );
		$stored    = strtolower( Kuka_Island_Shipping_Order_Store::provider( $order ) );

		if ( '' !== $stored ) {
			if ( '' !== $requested && $requested !== $stored ) {
				return array(
					'key'     => $stored,
					'source'  => 'order',
					'code'    => 'shipment_provider_mismatch',
					'message' => __( 'Bu siparişin kargo kaydı başka bir taşıyıcıya ait. İstenen taşıyıcıya hiçbir çağrı yapılmadı; siparişin taşıyıcısı değiştirilmedi.', 'kuka-island-shipping-automation' ),
				);
			}

			return array(
				'key'     => $stored,
				'source'  => 'order',
				'code'    => '',
				'message' => '',
			);
		}

		if ( Kuka_Island_Shipping_Order_Store::has_carrier_evidence( $order ) ) {
			return array(
				'key'     => '',
				'source'  => 'orphaned',
				'code'    => 'shipment_provider_missing',
				'message' => __( 'Bu siparişte taşıyıcı kaydı var fakat hangi taşıyıcıya ait olduğu yazılı değil. Varsayılan taşıyıcı kullanılmadı; hiçbir çağrı yapılmadı. Kayıt elle belirlenmelidir.', 'kuka-island-shipping-automation' ),
			);
		}

		return array(
			'key'     => '' !== $requested ? $requested : $this->default_carrier_key(),
			'source'  => '' !== $requested ? 'requested' : 'default',
			'code'    => '',
			'message' => '',
		);
	}

	/**
	 * The same decision, plus the registry lookup, plus the refusal record.
	 *
	 * Separate from carrier_ownership() because refuse() WRITES: it stores a
	 * reason on the order and adds an order note. An admin screen asking "which
	 * carrier does this order belong to" must not leave a trail of notes behind
	 * every page load, so rendering uses the pure decision and only the
	 * operations use this one.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key the caller asked for, '' for none.
	 * @return array{carrier: ?Kuka_Island_Shipping_Carrier_Interface, key: string, source: string, refusal: array<string, mixed>}
	 */
	private function resolve_carrier( WC_Order $order, string $carrier_key ): array {
		$ownership = $this->carrier_ownership( $order, $carrier_key );

		if ( '' !== $ownership['code'] ) {
			return array(
				'carrier' => null,
				'key'     => (string) $ownership['key'],
				'source'  => (string) $ownership['source'],
				'refusal' => $this->refuse( $order, (string) $ownership['code'], (string) $ownership['message'] ),
			);
		}

		$carrier = $this->registry->get( (string) $ownership['key'] );

		if ( null === $carrier ) {
			return array(
				'carrier' => null,
				'key'     => (string) $ownership['key'],
				'source'  => (string) $ownership['source'],
				'refusal' => $this->refuse( $order, 'carrier_not_registered', __( 'Bu kargo firması kayıtlı değil; hiçbir çağrı yapılmadı.', 'kuka-island-shipping-automation' ) ),
			);
		}

		return array(
			'carrier' => $carrier,
			'key'     => (string) $ownership['key'],
			'source'  => (string) $ownership['source'],
			'refusal' => array(),
		);
	}

	/**
	 * LAYER ONE: the gate EVERY carrier operation shares -- reads included.
	 *
	 * Runtime gate open, environment not blocked, credentials complete. Nothing
	 * about creation is decided here, which is the point: an earlier version of
	 * this method also applied the cash-on-delivery refusal, so wiring
	 * cancellation through it would have made a COD order impossible to CANCEL
	 * -- refusing to undo a booking is not a safety property, it is a trapped
	 * parcel. Ownership is not decided here either; that is resolve_carrier().
	 *
	 * @param WC_Order                               $order   Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier Carrier the order belongs to.
	 * @return array<string, mixed> Empty when the gate is open.
	 */
	private function carrier_gate( WC_Order $order, Kuka_Island_Shipping_Carrier_Interface $carrier ): array {
		$closed = $this->gate_closed_now( $carrier );

		if ( array() !== $closed ) {
			return $this->refuse( $order, $closed['code'], $closed['message'] );
		}

		return array();
	}

	/**
	 * Resolve ownership AND cross the gate, in that order.
	 *
	 * The pair every entry point begins with, and the pair every entry point
	 * repeats once the mutation lock is held -- the answer to "whose parcel is
	 * this, and may we contact them" belongs to the moment of the call, not to
	 * the moment the caller started waiting.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key the caller asked for, '' for none.
	 * @return array{carrier: ?Kuka_Island_Shipping_Carrier_Interface, key: string, source: string, refusal: array<string, mixed>}
	 */
	private function admit( WC_Order $order, string $carrier_key ): array {
		$resolved = $this->resolve_carrier( $order, $carrier_key );

		if ( array() !== $resolved['refusal'] ) {
			return $resolved;
		}

		$refusal = $this->carrier_gate( $order, $resolved['carrier'] );

		if ( array() !== $refusal ) {
			$resolved['refusal'] = $refusal;
		}

		return $resolved;
	}

	/**
	 * LAYER TWO: the policy only CREATION and BARCODE RESUME are subject to.
	 *
	 * Cash on delivery belongs here and nowhere else. The dangerous case is a
	 * COD order being SHIPPED as if it were prepaid; cancelling or amending a
	 * COD booking is not dangerous, it is the remedy.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed> Empty when creation may proceed.
	 */
	private function create_policy( WC_Order $order ): array {
		$cod = self::cod_gate( $order );

		if ( ! $cod['ok'] ) {
			return $this->refuse( $order, 'cod_not_supported', $cod['message'] );
		}

		return array();
	}

	/**
	 * Perform ONE carrier write, and only while the gate is still open.
	 *
	 * THE SINGLE CHOKE POINT. create_order, create_barcode, update_order,
	 * update_shipment, cancel_order and cancel_shipment all reach the carrier
	 * through here and through nowhere else, so "every write is gated" is a
	 * property of one function rather than of six call sites that have to stay
	 * in agreement.
	 *
	 * WHY THE GATE IS ASKED AGAIN. The admission check happened before the
	 * advisory lock was taken and before the order was re-read. Between those
	 * two moments an operator can deactivate the plugin -- which is exactly the
	 * scenario Runtime_Gate exists for -- and a caller that only checked on the
	 * way in would then send the request anyway. The re-check is the last thing
	 * that happens before the carrier is contacted.
	 *
	 * WHERE THE OWNERSHIP PIN HAPPENS. $before_write runs after the gate has
	 * been found open and before the request is issued, and nothing else may
	 * come between the two. The creation paths use it to persist the provider
	 * and the reference: any earlier and a purely local validation failure would
	 * leave the order owned by a courier that was never contacted; any later and
	 * a timeout would leave nobody knowing who had been asked.
	 *
	 * @param WC_Order                               $order        Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier      Carrier.
	 * @param callable                               $write        Returns Kuka_Island_Shipping_Result.
	 * @param callable|null                          $before_write Runs only if the gate is open.
	 * @return array{result: ?Kuka_Island_Shipping_Result, refusal: array<string, string>}
	 */
	private function guarded_write( WC_Order $order, Kuka_Island_Shipping_Carrier_Interface $carrier, callable $write, callable $persist_intent ): array {
		$closed = $this->gate_closed_now( $carrier );

		if ( array() !== $closed ) {
			Kuka_Island_Shipping_Order_Store::save_blocked( $order, $closed['code'], $closed['message'] );
			$this->note( $order, $closed['message'] );

			return array(
				'result'  => null,
				'refusal' => $closed,
			);
		}

		/*
		 * THE INTENT IS WRITTEN AND READ BACK HERE, AND ITS VERDICT DECIDES
		 * WHETHER ANYTHING IS SENT AT ALL.
		 *
		 * This argument used to be a void callback that pinned the provider and
		 * then returned nothing. Nothing checked whether the pin had landed, so
		 * a save that failed -- a lost connection, a filter that swallowed the
		 * query -- was indistinguishable from one that worked, and the request
		 * went out anyway. The order was then left with no record that anything
		 * had been attempted, and the next press sent the same create again.
		 *
		 * So the callback returns a verdict, and a verdict that is not an
		 * explicit ok refuses the operation. Not "logs and continues": returns,
		 * with $write() never invoked and the carrier call count still zero.
		 * A shape this method does not recognise is refused too -- a callback
		 * that returns null or a string has not proved anything.
		 */
		$prepared = $persist_intent();

		if ( ! is_array( $prepared ) || true !== ( $prepared['ok'] ?? null ) ) {
			$refusal = array(
				'code'    => is_array( $prepared ) && '' !== (string) ( $prepared['code'] ?? '' )
					? (string) $prepared['code']
					: 'mutation_intent_unverified',
				'message' => is_array( $prepared ) && '' !== (string) ( $prepared['message'] ?? '' )
					? (string) $prepared['message']
					: __( 'Kargo işlemi başlatılmadı: yapılacak işlemin kalıcı kaydı doğrulanamadı. Taşıyıcıya hiçbir istek gönderilmedi.', 'kuka-island-shipping-automation' ),
			);

			$this->note( $order, $refusal['message'] );

			return array(
				'result'  => null,
				'refusal' => $refusal,
			);
		}

		return array(
			'result'  => $write(),
			'refusal' => array(),
		);
	}

	/**
	 * The callback guarded_write() demands: persist the intent, then prove it.
	 *
	 * Every one of the six external mutations builds one of these, so the shape
	 * of "what is about to be attempted" is written by one method and cannot
	 * drift between operations.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $spec  kind, operation, target, provider, reference, expected.
	 * @return callable(): array{ok: bool, code: string, message: string, detail: string, intent: array<string, mixed>}
	 */
	private static function intent_writer( WC_Order $order, array $spec ): callable {
		return static fn (): array => Kuka_Island_Shipping_Order_Store::begin_mutation( $order, $spec );
	}

	/**
	 * Perform ONE carrier read, and only while the gate is still open.
	 *
	 * read_shipment, read_order and read_shipment_status reach the carrier
	 * through here and through nowhere else. The class docblock claimed every
	 * carrier operation crossed the boundary while these three did not: two of
	 * their callers never checked the gate at all, and none of them re-checked
	 * it before the request.
	 *
	 * A BLOCKED READ IS NOT AN ABSENT RECORD. The refusal is returned as a
	 * refusal, never as a Result, so no caller can mistake it for a 404. That
	 * distinction is the whole reason reads are gated separately from writes:
	 * reconcile() proves absence from not_found, and a gate that answered
	 * not_found would prove absence by being closed.
	 *
	 * Unlike a write, a blocked read records nothing on the order. Nothing was
	 * attempted, so there is no failure to book and no attempt to spend.
	 *
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier Carrier.
	 * @param callable                               $read    Returns Kuka_Island_Shipping_Result.
	 * @return array{result: ?Kuka_Island_Shipping_Result, refusal: array<string, string>}
	 */
	private function guarded_read( Kuka_Island_Shipping_Carrier_Interface $carrier, callable $read ): array {
		$closed = $this->gate_closed_now( $carrier );

		if ( array() !== $closed ) {
			return array(
				'result'  => null,
				'refusal' => $closed,
			);
		}

		return array(
			'result'  => $read(),
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
		$admitted = $this->admit( $order, $carrier_key );

		if ( array() !== $admitted['refusal'] ) {
			return $admitted['refusal'];
		}

		$policy = $this->create_policy( $order );

		if ( array() !== $policy ) {
			return $policy;
		}

		if ( ! $this->acquire_lock( self::MUTATION_LOCK_PREFIX . $order->get_id() ) ) {
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
			// Re-read INSIDE the lock, and resolve ownership again from that
			// reading. The values read before the lock was taken belong to a
			// moment that has passed -- including the answer to whose parcel
			// this is, which the previous holder may have just pinned.
			$order    = wc_get_order( $order->get_id() ) ?: $order;
			$admitted = $this->admit( $order, $carrier_key );

			if ( array() !== $admitted['refusal'] ) {
				return $admitted['refusal'];
			}

			$carrier = $admitted['carrier'];
			$state   = Kuka_Island_Shipping_Order_Store::get_state( $order );

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
			$this->release_lock( self::MUTATION_LOCK_PREFIX . $order->get_id() );
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
		/*
		 * The reference is only PREPARED here -- nothing is written. An order
		 * whose address cannot be mapped is a purely local failure with no
		 * carrier contacted, and it must come out of the attempt unowned so a
		 * different courier can be tried. The durable intent -- which pins the
		 * owner and moves the order into reconcile_required -- is handed to
		 * guarded_write() below and lands between the gate check and the first
		 * byte on the wire.
		 */
		$reference = Kuka_Island_Shipping_Order_Store::prepare_reference( $order );
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

			$guarded = $this->guarded_write(
				$order,
				$carrier,
				static fn (): Kuka_Island_Shipping_Result => $carrier->create_order( $shipment ),
				self::intent_writer(
					$order,
					array(
						'kind'      => Kuka_Island_Shipping_Order_Store::MUTATION_CREATE,
						'operation' => 'create_order',
						'target'    => 'order',
						'provider'  => $carrier->get_key(),
						'reference' => $reference,
					)
				)
			);

			if ( array() !== $guarded['refusal'] ) {
				return array(
					'ok'      => false,
					'state'   => Kuka_Island_Shipping_Order_Store::get_state( $order ),
					'code'    => $guarded['refusal']['code'],
					'message' => $guarded['refusal']['message'],
					'detail'  => '',
				);
			}

			$created = $guarded['result'];

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
		$guarded = $this->guarded_write(
			$order,
			$carrier,
			static fn (): Kuka_Island_Shipping_Result => $carrier->create_barcode( $shipment ),
			self::intent_writer(
				$order,
				array(
					'kind'      => Kuka_Island_Shipping_Order_Store::MUTATION_CREATE,
					'operation' => 'create_barcode',
					'target'    => 'shipment',
					'provider'  => $carrier->get_key(),
					'reference' => $reference,
				)
			)
		);

		if ( array() !== $guarded['refusal'] ) {
			return array(
				'ok'      => false,
				'state'   => Kuka_Island_Shipping_Order_Store::get_state( $order ),
				'code'    => $guarded['refusal']['code'],
				'message' => $guarded['refusal']['message'],
				'detail'  => '',
			);
		}

		$barcoded = $guarded['result'];

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
		$admitted = $this->admit( $order, $carrier_key );

		if ( array() !== $admitted['refusal'] ) {
			return $admitted['refusal'];
		}

		$policy = $this->create_policy( $order );

		if ( array() !== $policy ) {
			return $policy;
		}

		if ( ! $this->acquire_lock( self::MUTATION_LOCK_PREFIX . $order->get_id() ) ) {
			return array(
				'ok'      => false,
				'state'   => Kuka_Island_Shipping_Order_Store::get_state( $order ),
				'code'    => 'lock_contended',
				'message' => __( 'Bu sipariş için başka bir kargo işlemi sürüyor. Yeni çağrı yapılmadı.', 'kuka-island-shipping-automation' ),
				'detail'  => '',
			);
		}

		try {
			// Re-read INSIDE the lock, ownership included. The state and the
			// owner read before the lock was taken belong to a moment that has
			// passed, and the moment that matters is this one.
			$order    = wc_get_order( $order->get_id() ) ?: $order;
			$admitted = $this->admit( $order, $carrier_key );

			if ( array() !== $admitted['refusal'] ) {
				return $admitted['refusal'];
			}

			$carrier = $admitted['carrier'];
			$state   = Kuka_Island_Shipping_Order_Store::get_state( $order );

			if ( Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED !== $state ) {
				return array(
					'ok'      => false,
					'state'   => $state,
					'code'    => 'not_resumable',
					'message' => self::resume_refusal_message( $state ),
					'detail'  => '',
				);
			}

			$reference = Kuka_Island_Shipping_Order_Store::prepare_reference( $order );
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
			$this->release_lock( self::MUTATION_LOCK_PREFIX . $order->get_id() );
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
		$guarded_shipment = $this->guarded_read(
			$carrier,
			static fn (): Kuka_Island_Shipping_Result => $carrier->read_shipment( $reference )
		);

		if ( array() !== $guarded_shipment['refusal'] ) {
			return $this->reconcile_blocked( $order, $guarded_shipment['refusal'] );
		}

		$shipment = $guarded_shipment['result'];

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

		$guarded_order = $this->guarded_read(
			$carrier,
			static fn (): Kuka_Island_Shipping_Result => $carrier->read_order( $reference )
		);

		if ( array() !== $guarded_order['refusal'] ) {
			return $this->reconcile_blocked( $order, $guarded_order['refusal'] );
		}

		$carrier_order = $guarded_order['result'];

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
			Kuka_Island_Shipping_Order_Store::settle_mutation(
				$order,
				Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED,
				'reconcile',
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
	 * A reconciliation that could not read.
	 *
	 * The most important non-answer in this module. Absence is proved by two
	 * not_found readings; a gate that refused to ask has proved nothing, so the
	 * order stays exactly where it was -- in reconcile_required, from which
	 * nothing may be written again. Reporting this as absence would license a
	 * second createOrder, which is how one parcel becomes two.
	 *
	 * @param WC_Order              $order   Order.
	 * @param array<string, string> $refusal Gate refusal.
	 * @return array{verdict: string, message: string}
	 */
	private function reconcile_blocked( WC_Order $order, array $refusal ): array {
		$message = __( 'Mutabakat yapılamadı: taşıyıcıya salt-okunur sorgu bile gönderilemedi. Durum belirsiz kaldı, yokluk varsayılmadı, hiçbir şey gönderilmedi.', 'kuka-island-shipping-automation' );

		Kuka_Island_Shipping_Order_Store::save_failure( $order, 'reconcile', $refusal['code'], $message . ' ' . $refusal['code'] );
		$this->note( $order, $message . ' ' . $refusal['code'] );

		return array(
			'verdict' => 'blocked',
			'message' => $message,
		);
	}

	/**
	 * Establish what an ISSUED CANCELLATION actually did. Reads only.
	 *
	 * WHY THIS IS NOT reconcile(). The generic reconciliation exists for a
	 * CREATE: finding the record there means the create worked, so it writes
	 * order_created or shipment_created and moves on. Run after a cancellation,
	 * that logic is exactly backwards -- finding the record means the
	 * cancellation is UNPROVEN, and putting the order back into
	 * shipment_created re-opens the cancel button on a cancellation that may
	 * already have taken effect. The second press is then a second cancellation.
	 *
	 * So this reconciliation has one exit and one exit only: a read that says
	 * not_found. Everything else -- the record still there, the gate shut, a
	 * gateway that will not answer -- leaves the order where it is.
	 *
	 * @param WC_Order                               $order     Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier   Carrier the order belongs to.
	 * @param string                                 $reference Carrier reference.
	 * @return array{verdict: string, message: string}
	 */
	public function reconcile_cancellation( WC_Order $order, Kuka_Island_Shipping_Carrier_Interface $carrier, string $reference ): array {
		$pending = Kuka_Island_Shipping_Order_Store::pending_mutation( $order );
		$target  = (string) ( $pending['target'] ?? '' );
		$issued  = Kuka_Island_Shipping_Order_Store::MUTATION_CANCEL === (string) ( $pending['kind'] ?? '' )
			|| Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === Kuka_Island_Shipping_Order_Store::get_state( $order );

		if ( ! $issued ) {
			return array(
				'verdict' => 'no_pending_cancellation',
				'message' => __( 'Bu siparişte doğrulanacak bir iptal isteği kayıtlı değil.', 'kuka-island-shipping-automation' ),
			);
		}

		if ( ! in_array( $target, array( 'order', 'shipment' ), true ) ) {
			/*
			 * THE STATE SAYS A CANCELLATION WENT OUT AND THE INTENT RECORD DOES
			 * NOT SAY WHICH OBJECT IT ADDRESSED. Only one thing can produce
			 * that: begin_mutation() moved the state and its own record did not
			 * survive the same save -- a partial meta write, which the readback
			 * check refuses the operation for but deliberately does not roll
			 * back, because the protected state is the restrictive side and
			 * un-protecting an order with a database this untrustworthy would
			 * be the worse mistake.
			 *
			 * The order must still be resolvable. The target is derived the way
			 * cancel() derives it, and this path stays read-only: it can only
			 * ever move the order to 'cancelled' on a not_found, which is the
			 * one transition that needs no intent record to be safe.
			 */
			$target = '' !== (string) Kuka_Island_Shipping_Order_Store::get_shipment_data( $order )['shipment_id']
				? 'shipment'
				: 'order';
		}

		$guarded = $this->guarded_read(
			$carrier,
			'shipment' === $target
				? static fn (): Kuka_Island_Shipping_Result => $carrier->read_shipment( $reference )
				: static fn (): Kuka_Island_Shipping_Result => $carrier->read_order( $reference )
		);

		if ( array() !== $guarded['refusal'] ) {
			return $this->cancel_still_unproven(
				$order,
				'cancel_unconfirmed_blocked',
				__( 'İptal doğrulaması yapılamadı: taşıyıcıya salt-okunur sorgu bile gönderilemedi. Durum korunuyor; yeni iptal gönderilmez.', 'kuka-island-shipping-automation' ),
				'blocked:' . $guarded['refusal']['code'] . '|target:' . $target
			);
		}

		$confirm = $guarded['result'];

		if ( 'not_found' === $confirm->get_safe_error_code() ) {
			// ONE save: the state and the closed intent go down together, so a
			// crash cannot leave an order that says 'cancelled' while its meta
			// still describes a cancellation in flight.
			Kuka_Island_Shipping_Order_Store::settle_mutation(
				$order,
				Kuka_Island_Shipping_Order_Store::STATE_CANCELLED,
				'cancel_confirm',
				__( 'Taşıyıcı kaydı iptal edildi ve iptal salt-okunur sorguyla doğrulandı.', 'kuka-island-shipping-automation' )
			);

			// Only NOW, with the cancellation proved, is it safe to stop
			// following the parcel.
			Kuka_Island_Shipping_Status_Poller::cancel_queries( (int) $order->get_id() );

			$message = __( 'Kargo kaydı iptal edildi (sorguyla doğrulandı).', 'kuka-island-shipping-automation' );
			$this->note( $order, $message . ' ' . $confirm->to_safe_line() . '|target:' . $target );

			return array(
				'verdict' => 'cancelled',
				'message' => $message,
			);
		}

		if ( $confirm->is_success() ) {
			return $this->cancel_still_unproven(
				$order,
				'cancel_unconfirmed_record_present',
				__( 'Taşıyıcı iptali kabul etti fakat kayıt hâlâ mevcut görünüyor. Durum korunuyor; yeni iptal gönderilmez, yalnız sorgu tekrarlanır.', 'kuka-island-shipping-automation' ),
				$confirm->to_safe_line() . '|target:' . $target
			);
		}

		return $this->cancel_still_unproven(
			$order,
			'cancel_unconfirmed',
			__( 'İptal doğrulanamadı: sorgu cevap veremedi. Durum korunuyor; yeni iptal gönderilmez.', 'kuka-island-shipping-automation' ),
			$confirm->to_safe_line() . '|target:' . $target
		);
	}

	/**
	 * The cancellation is still unproven: record why, change nothing.
	 *
	 * NOTHING IS WATCHING THIS ORDER, AND SAYING OTHERWISE WAS THE DEFECT. This
	 * comment used to claim that the already-booked status query was "the only
	 * thing still watching it". It is not watching anything: the poller's worker
	 * reads the state first, finds cancel_reconciliation_required, returns
	 * 'state_not_pollable' without issuing a single carrier read, and books no
	 * follow-up. The chain therefore ends here whether the action is left
	 * booked or not.
	 *
	 * The booked action is still left alone -- cancelling it would be a second
	 * write to the scheduler for no gain, and the action costs one no-op run --
	 * but the consequence is stated plainly rather than dressed up: AN
	 * UNCONFIRMED CANCELLATION IS RESOLVED BY A PERSON. The order screen says
	 * so, the order note says so, and the operator's lever is the read-only
	 * reconciliation button, which runs reconcile_cancellation() again. Nothing
	 * in this module will retry it on a timer.
	 *
	 * @return array{verdict: string, message: string}
	 */
	private function cancel_still_unproven( WC_Order $order, string $code, string $message, string $detail ): array {
		Kuka_Island_Shipping_Order_Store::save_failure( $order, 'cancel_confirm', $code, $message . ' ' . $detail );
		$this->note( $order, $message . ' ' . $detail );

		return array(
			'verdict' => $code,
			'message' => $message,
		);
	}

	/**
	 * Establish what an ISSUED AMENDMENT actually did. Reads only.
	 *
	 * THE RECORD EXISTING IS NOT EVIDENCE. That was the bug: an uncertain
	 * updateorder was reconciled with the generic reconciliation, which found
	 * the order -- of course it did, the order was there before the amendment
	 * too -- and wrote order_created, which re-opened the update button. The
	 * amendment may have been applied, may not, and the second press sends it
	 * again.
	 *
	 * THE ONLY EVIDENCE IS A FIELD-LEVEL READ-BACK. The carrier is asked what
	 * values it currently holds for the amendable fields, and every field that
	 * was sent has to match exactly. An adapter whose query API does not return
	 * those fields answers 'readback_unsupported', and then there is no proof to
	 * be had: the order stays in update_reconciliation_required and a person
	 * decides. A partial comparison would be worse than no comparison.
	 *
	 * @param WC_Order                               $order     Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface $carrier   Carrier the order belongs to.
	 * @param string                                 $reference Carrier reference.
	 * @return array{verdict: string, message: string}
	 */
	public function reconcile_update( WC_Order $order, Kuka_Island_Shipping_Carrier_Interface $carrier, string $reference ): array {
		$pending  = Kuka_Island_Shipping_Order_Store::pending_mutation( $order );
		$expected = (array) ( $pending['expected'] ?? array() );

		if ( Kuka_Island_Shipping_Order_Store::MUTATION_UPDATE !== (string) ( $pending['kind'] ?? '' ) || array() === $expected ) {
			if ( Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED === Kuka_Island_Shipping_Order_Store::get_state( $order ) ) {
				/*
				 * The state says an amendment went out and the values it
				 * carried are not on record. Unlike a cancellation, this cannot
				 * be settled by reading: the proof IS the comparison against
				 * those values, and there is nothing to compare against. No
				 * guess is made and no state is written -- the order waits for
				 * a person, who can still cancel the parcel.
				 */
				return array(
					'verdict' => 'update_record_lost',
					'message' => __( 'Gönderilen güncelleme değerleri kayıtlı değil, bu yüzden güncellemenin uygulandığı doğrulanamaz. Tahmin yapılmadı; yeni güncelleme gönderilmez, iptal hâlâ yapılabilir.', 'kuka-island-shipping-automation' ),
				);
			}

			return array(
				'verdict' => 'no_pending_update',
				'message' => __( 'Bu siparişte doğrulanacak bir güncelleme isteği kayıtlı değil.', 'kuka-island-shipping-automation' ),
			);
		}

		$guarded = $this->guarded_read(
			$carrier,
			static fn (): Kuka_Island_Shipping_Result => $carrier->read_amendable_fields( $reference )
		);

		if ( array() !== $guarded['refusal'] ) {
			return $this->update_still_unproven(
				$order,
				'update_unconfirmed_blocked',
				__( 'Güncelleme doğrulaması yapılamadı: taşıyıcıya salt-okunur sorgu bile gönderilemedi. Durum korunuyor; yeni güncelleme gönderilmez.', 'kuka-island-shipping-automation' ),
				'blocked:' . $guarded['refusal']['code']
			);
		}

		$readback = $guarded['result'];

		if ( ! $readback->is_success() ) {
			$code = 'readback_unsupported' === $readback->get_safe_error_code()
				? 'readback_unsupported'
				: 'update_unconfirmed';

			return $this->update_still_unproven(
				$order,
				$code,
				'readback_unsupported' === $code
					? __( 'Bu taşıyıcı güncellenen alanları geri okuyamıyor, bu yüzden güncellemenin uygulandığı kanıtlanamaz. Kaydın var olması kanıt değildir. Durum manuel incelemeye bırakıldı; yeni güncelleme gönderilmez.', 'kuka-island-shipping-automation' )
					: __( 'Güncelleme doğrulanamadı: alan sorgusu cevap veremedi. Durum korunuyor; yeni güncelleme gönderilmez.', 'kuka-island-shipping-automation' ),
				$readback->to_safe_line()
			);
		}

		$comparison = self::fields_match( $expected, $readback->get_data() );

		if ( ! $comparison['match'] ) {
			Kuka_Island_Shipping_Order_Store::settle_mutation(
				$order,
				Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW,
				'update_confirm',
				__( 'Güncelleme doğrulaması alan bazında eşleşmedi. Manuel inceleme gerekiyor; yeni güncelleme gönderilmedi.', 'kuka-island-shipping-automation' )
			);

			$message = __( 'Güncelleme doğrulaması eşleşmedi; manuel inceleme gerekiyor.', 'kuka-island-shipping-automation' );
			$this->note( $order, $message . ' fields:' . implode( ',', $comparison['mismatched'] ) );

			return array(
				'verdict' => 'update_mismatch',
				'message' => $message,
			);
		}

		$previous = (string) ( $pending['previous_state'] ?? Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED );

		Kuka_Island_Shipping_Order_Store::settle_mutation(
			$order,
			$previous,
			'update_confirm',
			__( 'Güncelleme alan bazında geri okundu ve gönderilen değerlerle birebir eşleşti.', 'kuka-island-shipping-automation' )
		);

		$message = __( 'Güncelleme uygulandı ve alan bazında doğrulandı.', 'kuka-island-shipping-automation' );
		$this->note( $order, $message . ' fields:' . (string) count( $expected ) );

		return array(
			'verdict' => 'update_confirmed',
			'message' => $message,
		);
	}

	/**
	 * The amendment is still unproven: record why, change nothing.
	 *
	 * @return array{verdict: string, message: string}
	 */
	private function update_still_unproven( WC_Order $order, string $code, string $message, string $detail ): array {
		Kuka_Island_Shipping_Order_Store::save_failure( $order, 'update_confirm', $code, $message . ' ' . $detail );
		$this->note( $order, $message . ' ' . $detail );

		return array(
			'verdict' => $code,
			'message' => $message,
		);
	}

	/**
	 * The amendable fields of a shipment request, as comparable strings.
	 *
	 * Named semantically, because the comparison happens above the adapter: the
	 * carrier answers in the same vocabulary the request was built in, and this
	 * class never learns which JSON field any of them came from.
	 *
	 * @param array<string, mixed> $shipment Shipment request.
	 * @return array<string, string>
	 */
	public static function amendable_fields( array $shipment ): array {
		$recipient = (array) ( $shipment['recipient'] ?? array() );
		$pieces    = (array) ( $shipment['pieces'] ?? array() );
		$first     = (array) ( $pieces[0] ?? array() );

		return array(
			'recipient_full_name'     => self::canonical_amendable_value( $recipient['full_name'] ?? '' ),
			'recipient_address'       => self::canonical_amendable_value( $recipient['address'] ?? '' ),
			'recipient_city_code'     => (string) (int) ( $recipient['city_code'] ?? 0 ),
			'recipient_district_code' => (string) (int) ( $recipient['district_code'] ?? 0 ),
			'recipient_mobile_phone'  => self::canonical_amendable_value( $recipient['mobile_phone'] ?? '' ),
			'content'                 => self::canonical_amendable_value( $shipment['content'] ?? '' ),
			'description'             => self::canonical_amendable_value( $shipment['description'] ?? '' ),
			'desi'                    => (string) (int) ( $first['desi'] ?? 0 ),
			'kg'                      => (string) (int) ( $first['kg'] ?? 0 ),
		);
	}

	/**
	 * Does the carrier hold exactly what was sent?
	 *
	 * EXACT, and total. Every expected field has to be present in the answer and
	 * has to be equal to it. A field the carrier did not answer for is a
	 * MISMATCH, not a pass: "it did not contradict us" is not evidence.
	 *
	 * NO TRIMMING. Both sides used to be trimmed here, and that single call
	 * made the word "exact" untrue: a read-back of ' Ada Lovelace' matched a
	 * sent 'Ada Lovelace', so a carrier that had reformatted the field was
	 * reported as holding it verbatim. The canonical form is now established
	 * BEFORE the request leaves -- canonical_amendable_value(), applied by
	 * build_request() -- which means the carrier was asked to store exactly
	 * these bytes and there is nothing left for this method to forgive.
	 *
	 * A non-scalar answer is a mismatch as well: a nested structure where a
	 * string was expected cannot be compared, and casting one to a string
	 * produces a value the carrier never sent.
	 *
	 * @param array<string, mixed> $expected What was sent, already canonical.
	 * @param array<string, mixed> $actual   What the carrier says it holds.
	 * @return array{match: bool, mismatched: array<int, string>}
	 */
	public static function fields_match( array $expected, array $actual ): array {
		$mismatched = array();

		foreach ( $expected as $field => $value ) {
			if ( ! array_key_exists( $field, $actual ) ) {
				$mismatched[] = (string) $field . ':absent';
				continue;
			}

			if ( ! is_scalar( $actual[ $field ] ) ) {
				$mismatched[] = (string) $field . ':untyped';
				continue;
			}

			if ( (string) $actual[ $field ] !== (string) $value ) {
				$mismatched[] = (string) $field . ':differs';
			}
		}

		return array(
			'match'      => array() === $mismatched,
			'mismatched' => $mismatched,
		);
	}

	/**
	 * Run a reconciliation from the order screen.
	 *
	 * Addresses the carrier the ORDER belongs to. Reconciling a DHL shipment
	 * against whatever the shop now calls its default is how a reconciliation
	 * reports "nothing there" about a parcel that exists.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key, '' to use the order's own.
	 * @return array{verdict: string, message: string}
	 */
	public function reconcile_order( WC_Order $order, string $carrier_key = '' ): array {
		$admitted = $this->admit( $order, $carrier_key );

		if ( array() !== $admitted['refusal'] ) {
			return array(
				'verdict' => (string) $admitted['refusal']['code'],
				'message' => (string) $admitted['refusal']['message'],
			);
		}

		$reference = (string) Kuka_Island_Shipping_Order_Store::get_shipment_data( $order )['reference'];

		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
			return array(
				'verdict' => 'no_reference',
				'message' => __( 'Bu siparişte taşıyıcı referansı yok; sorgulanacak bir kayıt bulunmuyor.', 'kuka-island-shipping-automation' ),
			);
		}

		/*
		 * WHICH reconciliation depends on WHAT was issued. Running the generic
		 * one after a cancellation would write shipment_created the moment it
		 * found the record and re-open the cancel button; running it after an
		 * amendment would read "the object exists" as "the amendment was
		 * applied". Both were real: see reconcile_cancellation() and
		 * reconcile_update().
		 */
		$state = Kuka_Island_Shipping_Order_Store::get_state( $order );

		if ( Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $state ) {
			return $this->reconcile_cancellation( $order, $admitted['carrier'], $reference );
		}

		if ( Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED === $state ) {
			return $this->reconcile_update( $order, $admitted['carrier'], $reference );
		}

		return $this->reconcile( $order, $admitted['carrier'], $reference );
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
		$admitted = $this->admit( $order, $carrier_key );

		if ( array() !== $admitted['refusal'] ) {
			return array(
				'ok'        => false,
				'lifecycle' => Kuka_Island_Shipping_Status::LIFECYCLE_UNKNOWN,
				'code'      => (string) $admitted['refusal']['code'],
				'message'   => (string) $admitted['refusal']['message'],
				'detail'    => '',
				'attempts'  => Kuka_Island_Shipping_Order_Store::query_attempts( $order ),
			);
		}

		$carrier   = $admitted['carrier'];
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

		$guarded_status = $this->guarded_read(
			$carrier,
			static fn (): Kuka_Island_Shipping_Result => $carrier->read_shipment_status( $reference )
		);

		if ( array() !== $guarded_status['refusal'] ) {
			// Nothing left the building, so nothing is spent and nothing is
			// recorded as a carrier failure.
			return array(
				'ok'        => false,
				'lifecycle' => Kuka_Island_Shipping_Status::LIFECYCLE_UNKNOWN,
				'code'      => $guarded_status['refusal']['code'],
				'message'   => $guarded_status['refusal']['message'],
				'detail'    => '',
				'attempts'  => Kuka_Island_Shipping_Order_Store::query_attempts( $order ),
			);
		}

		$result = $guarded_status['result'];

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
	 * UNDER THE MUTATION LOCK, AND FROM A STATE READ INSIDE IT. This method
	 * used to take no lock and to build its request from a snapshot taken
	 * before any check: an amendment could therefore be sent while a create was
	 * still running, or after a cancellation had already succeeded -- a request
	 * addressed to a record that no longer exists, sent because the caller was
	 * looking at a moment that had passed.
	 *
	 * EXACTLY TWO STATES WRITE. order_created amends the registered order;
	 * shipment_created with a known shipment id amends the shipment. Every
	 * other state, the empty shipment id included, makes no call at all.
	 *
	 * No cash-on-delivery refusal here: amending a COD order is not shipping
	 * one. That gate belongs to creation.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key, '' for the default.
	 * @return array{ok: bool, code: string, message: string, detail: string}
	 */
	public function update_shipment( WC_Order $order, string $carrier_key = '' ): array {
		$admitted = $this->admit( $order, $carrier_key );

		if ( array() !== $admitted['refusal'] ) {
			return $this->simple( false, (string) $admitted['refusal']['code'], (string) $admitted['refusal']['message'] );
		}

		if ( ! $this->acquire_lock( self::MUTATION_LOCK_PREFIX . $order->get_id() ) ) {
			return $this->simple(
				false,
				'lock_contended',
				__( 'Bu sipariş için başka bir kargo işlemi sürüyor. Yeni çağrı yapılmadı.', 'kuka-island-shipping-automation' )
			);
		}

		try {
			// Re-read INSIDE the lock. Owner, state, shipment id and reference
			// all come from this reading and from no earlier one.
			$order    = wc_get_order( $order->get_id() ) ?: $order;
			$admitted = $this->admit( $order, $carrier_key );

			if ( array() !== $admitted['refusal'] ) {
				return $this->simple( false, (string) $admitted['refusal']['code'], (string) $admitted['refusal']['message'] );
			}

			$carrier   = $admitted['carrier'];
			$data      = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
			$reference = (string) $data['reference'];

			if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
				return $this->simple( false, 'no_reference', __( 'Bu siparişte taşıyıcı referansı yok.', 'kuka-island-shipping-automation' ) );
			}

			$amends_shipment = Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $data['state']
				&& '' !== $data['shipment_id'];
			$amends_order    = Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $data['state'];

			if ( ! $amends_shipment && ! $amends_order ) {
				return $this->simple(
					false,
					'nothing_to_update',
					self::update_refusal_message( (string) $data['state'], '' === $data['shipment_id'] )
				);
			}

			$request = $this->build_request( $order, $carrier, $reference );

			if ( ! $request['ok'] ) {
				return $this->simple( false, (string) $request['code'], (string) $request['message'] );
			}

			$shipment  = $request['shipment'];
			$target    = $amends_shipment ? 'shipment' : 'order';
			$operation = $amends_shipment ? 'update_shipment' : 'update_order';

			if ( $amends_shipment ) {
				$shipment['shipment_id'] = $data['shipment_id'];
			}

			/*
			 * The exact values this request carries, in the one canonical form
			 * the comparison will later demand. They go into the intent BEFORE
			 * the request leaves, because they are the only thing that can ever
			 * prove what the amendment did -- and a process that dies mid-flight
			 * takes the in-memory copy with it.
			 */
			$expected = self::amendable_fields( $shipment );

			$guarded = $this->guarded_write(
				$order,
				$carrier,
				$amends_shipment
					? static fn (): Kuka_Island_Shipping_Result => $carrier->update_shipment( $shipment )
					: static fn (): Kuka_Island_Shipping_Result => $carrier->update_order( $shipment ),
				self::intent_writer(
					$order,
					array(
						'kind'      => Kuka_Island_Shipping_Order_Store::MUTATION_UPDATE,
						'operation' => $operation,
						'target'    => $target,
						'provider'  => $carrier->get_key(),
						'reference' => $reference,
						'expected'  => $expected,
					)
				)
			);

			if ( array() !== $guarded['refusal'] ) {
				return $this->simple( false, $guarded['refusal']['code'], $guarded['refusal']['message'] );
			}

			$result = $guarded['result'];

			/*
			 * THE ONE ANSWER THAT CLOSES THE INTENT WITHOUT A READ. The adapter
			 * refused before opening a socket, so it is provable from the code
			 * path -- not inferred from a status code -- that the carrier holds
			 * nothing new. The order goes back to where it was and the update
			 * button stays live.
			 */
			if ( ! $result->reached_carrier() ) {
				return $this->close_unsent_intent( $order, $result );
			}

			/*
			 * EVERY OTHER ANSWER, THE SUCCESS INCLUDED, LEAVES THE DOOR SHUT.
			 *
			 * A success here is an ACKNOWLEDGEMENT: the vendor's OpenAPI
			 * documents the 200 as "Order updated" and says nothing about which
			 * fields it took, and treating it as proof is how a shop believes an
			 * address was corrected when it was not. A 400 is no better in the
			 * other direction: the same contract documents it as "Bad Request"
			 * and never says whether the record was left untouched, so it cannot
			 * license a second attempt either.
			 *
			 * The only thing that settles this is a read-back of the fields, and
			 * that is what runs next -- read-only, and the same method a later
			 * reconciliation runs.
			 */
			$this->note(
				$order,
				__( 'Güncelleme isteği taşıyıcıya gönderildi. Uygulandığı alan bazında doğrulanana kadar yeni güncelleme gönderilmez.', 'kuka-island-shipping-automation' ) . ' ' . $result->to_safe_line()
			);

			$verdict = $this->reconcile_update( $order, $carrier, $reference );
			$detail  = 'issued:' . $result->get_outcome() . '|target:' . $target . '|verdict:' . $verdict['verdict'];

			if ( 'update_confirmed' === $verdict['verdict'] ) {
				return $this->simple( true, '', $verdict['message'], $detail );
			}

			return $this->simple( false, (string) $verdict['verdict'], $verdict['message'], $detail );
		} finally {
			$this->release_lock( self::MUTATION_LOCK_PREFIX . $order->get_id() );
		}
	}

	/**
	 * Cancel the shipment, or the registered order when no shipment exists.
	 *
	 * SERIALISED, IDEMPOTENT AND CONFIRMED. Three separate properties, and each
	 * of them was missing.
	 *
	 * SERIALISED. The mutation lock is taken and the order is re-read inside it.
	 * Without the lock two concurrent presses both read 'shipment_created' and
	 * both sent cancelshipment: two cancellations for one parcel, and the second
	 * one answering "already cancelled" is the harmless outcome, not the
	 * guaranteed one.
	 *
	 * IDEMPOTENT. Exactly two states may write -- order_created cancels the
	 * ORDER, shipment_created with a known shipment id cancels the SHIPMENT.
	 * Everything else makes no call: an order that is already cancelled answers
	 * 'already_cancelled', and 'none', 'blocked', 'absent_confirmed',
	 * 'delivered', 'manual_review', 'reconcile_required' and any value this
	 * version has never heard of answer 'not_cancellable'. Refusing by an
	 * allow-list rather than by a deny-list is the difference: a deny-list grows
	 * a hole the first time a state is added.
	 *
	 * A shipment_created record with an EMPTY shipment id is refused too. A
	 * shipment exists but its identifier is unknown, so nothing can address it
	 * -- and cancelling the ORDER instead would send a request about the wrong
	 * object, which is the mistake this method already had once.
	 *
	 * CONFIRMED BY READING THE OBJECT THAT WAS CANCELLED. The carrier's
	 * "canceled" is an acknowledgement, not evidence. cancel_shipment is proved
	 * by read_shipment, cancel_order by read_order, and only not_found moves the
	 * state. A read that says "still there", or cannot answer, leaves the order
	 * where it was -- which keeps the parcel being tracked.
	 *
	 * AN UNCERTAIN CANCELLATION IS NEVER REPEATED. It lands in
	 * reconcile_required, which is not in the allow-list above, so the next
	 * press -- concurrent or minutes later -- writes nothing.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $carrier_key Carrier key, '' for the default.
	 * @return array{ok: bool, code: string, message: string, detail: string}
	 */
	public function cancel( WC_Order $order, string $carrier_key = '' ): array {
		$admitted = $this->admit( $order, $carrier_key );

		if ( array() !== $admitted['refusal'] ) {
			return $this->simple( false, (string) $admitted['refusal']['code'], (string) $admitted['refusal']['message'] );
		}

		if ( ! $this->acquire_lock( self::MUTATION_LOCK_PREFIX . $order->get_id() ) ) {
			// Somebody else is mutating this order. Not queued behind: two
			// callers waiting to cancel the same parcel is the scenario the
			// lock exists to prevent.
			return $this->simple(
				false,
				'lock_contended',
				__( 'Bu sipariş için başka bir kargo işlemi sürüyor. Yeni çağrı yapılmadı.', 'kuka-island-shipping-automation' )
			);
		}

		try {
			// Re-read INSIDE the lock, ownership included. A caller that waited
			// for the lock is looking at a state -- and at an owner -- the
			// previous holder may have just changed.
			$order    = wc_get_order( $order->get_id() ) ?: $order;
			$admitted = $this->admit( $order, $carrier_key );

			if ( array() !== $admitted['refusal'] ) {
				return $this->simple( false, (string) $admitted['refusal']['code'], (string) $admitted['refusal']['message'] );
			}

			$carrier   = $admitted['carrier'];
			$data      = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
			$reference = (string) $data['reference'];

			if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
				return $this->simple( false, 'no_reference', __( 'Bu siparişte taşıyıcı referansı yok.', 'kuka-island-shipping-automation' ) );
			}

			if ( Kuka_Island_Shipping_Order_Store::STATE_CANCELLED === $data['state'] ) {
				return $this->simple(
					false,
					'already_cancelled',
					__( 'Bu siparişin taşıyıcı kaydı zaten iptal edilmiş ve iptal sorguyla doğrulanmıştı. Yeni iptal çağrısı yapılmadı.', 'kuka-island-shipping-automation' )
				);
			}

			/*
			 * A cancellation has already reached the carrier and its effect is
			 * not established. It may well have worked. Sending another one is
			 * forbidden here for the same reason an uncertain create is never
			 * repeated -- and this is the state a success answer lands in, not
			 * only an uncertain one, because a success answer is an
			 * acknowledgement and nothing more.
			 */
			if ( Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED === $data['state'] ) {
				return $this->simple(
					false,
					'cancel_in_progress',
					__( 'Bu sipariş için iptal isteği zaten taşıyıcıya gönderildi ve sonucu doğrulanıyor. Yeni iptal çağrısı yapılmadı; yalnız salt-okunur mutabakat çalıştırılabilir.', 'kuka-island-shipping-automation' )
				);
			}

			/*
			 * AN UNPROVEN AMENDMENT DOES NOT PUT THE PARCEL OUT OF REACH.
			 *
			 * This module cannot prove that an amendment was applied when the
			 * carrier's query API does not return the amended fields, so such an
			 * order stays in update_reconciliation_required indefinitely. That
			 * is the honest state, and refusing a SECOND amendment from it is
			 * right. Refusing a CANCELLATION from it would not be: an amendment
			 * whose effect is unknown does not make cancelling unsafe -- the
			 * object either exists, and the cancellation addresses it, or it
			 * does not, and the carrier says so -- and refusing would leave the
			 * operator with a parcel they can neither correct nor stop.
			 *
			 * WHICH object to address comes from the amendment's own intent
			 * record, which says which state the order was in before it. Not
			 * from a guess, and not from the shipment id alone.
			 */
			$effective_state = (string) $data['state'];

			if ( Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED === $effective_state ) {
				$pending         = Kuka_Island_Shipping_Order_Store::pending_mutation( $order );
				$effective_state = (string) ( $pending['previous_state'] ?? '' );
			}

			// WHICH object is cancelled decides WHICH read confirms it.
			if ( Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $effective_state && '' !== $data['shipment_id'] ) {
				$shipment_id    = (string) $data['shipment_id'];
				$confirm_target = 'shipment';
				$operation      = 'cancel_shipment';
				$write          = static fn (): Kuka_Island_Shipping_Result => $carrier->cancel_shipment( $reference, $shipment_id );
			} elseif ( Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $effective_state ) {
				$confirm_target = 'order';
				$operation      = 'cancel_order';
				$write          = static fn (): Kuka_Island_Shipping_Result => $carrier->cancel_order( $reference );
			} else {
				return $this->simple(
					false,
					'not_cancellable',
					self::cancel_refusal_message( (string) $data['state'], '' === $data['shipment_id'] )
				);
			}

			$guarded = $this->guarded_write(
				$order,
				$carrier,
				$write,
				self::intent_writer(
					$order,
					array(
						'kind'      => Kuka_Island_Shipping_Order_Store::MUTATION_CANCEL,
						'operation' => $operation,
						'target'    => $confirm_target,
						'provider'  => $carrier->get_key(),
						'reference' => $reference,
					)
				)
			);

			if ( array() !== $guarded['refusal'] ) {
				return $this->simple( false, $guarded['refusal']['code'], $guarded['refusal']['message'] );
			}

			$result = $guarded['result'];

			/*
			 * THE ONE ANSWER THAT RE-OPENS THE CANCEL BUTTON. The adapter
			 * refused before opening a socket, which is provable from the code
			 * path: no cancellation request exists, so the order returns to the
			 * state it was in and can be cancelled again.
			 *
			 * THIS USED TO BE EVERY 'PERMANENT' ANSWER, and that was the defect.
			 * A 400 from the gateway was read as "nothing happened", but the
			 * vendor's OpenAPI documents that status as nothing more than "Bad
			 * Request" -- it does not say the record was left alone, and a 409,
			 * a 500, a timeout or an unreadable body say even less. So only a
			 * refusal that never left the building qualifies now; everything
			 * else keeps the door shut and is settled by reading.
			 */
			if ( ! $result->reached_carrier() ) {
				$closed = $this->close_unsent_intent( $order, $result );

				return $this->simple( false, (string) $closed['code'], (string) $closed['message'], (string) $closed['detail'] );
			}

			/*
			 * THE DOOR IS ALREADY SHUT, AND IT WAS SHUT BEFORE THE REQUEST LEFT.
			 * begin_mutation() moved the order into
			 * cancel_reconciliation_required and wrote down what was about to be
			 * attempted, so a process that died in flight leaves exactly this
			 * state behind. No second cancellation may follow -- not from a
			 * second button press, not from a stale order object, not from a
			 * concurrent request, and not from a retry after a crash.
			 */
			$this->note(
				$order,
				__( 'İptal isteği taşıyıcıya gönderildi. Sonucu doğrulanana kadar yeni iptal gönderilmez.', 'kuka-island-shipping-automation' ) . ' ' . $result->to_safe_line()
			);

			// Read-only from here on, and this same method is what a later
			// reconciliation runs.
			$verdict = $this->reconcile_cancellation( $order, $carrier, $reference );
			$detail  = 'issued:' . $result->get_outcome() . '|target:' . $confirm_target . '|verdict:' . $verdict['verdict'];

			if ( 'cancelled' === $verdict['verdict'] ) {
				return $this->simple( true, '', $verdict['message'], $detail );
			}

			return $this->simple( false, (string) $verdict['verdict'], $verdict['message'], $detail );
		} finally {
			$this->release_lock( self::MUTATION_LOCK_PREFIX . $order->get_id() );
		}
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

		/*
		 * CANONICALISED AFTER THE FILTER, NOT BEFORE. What leaves this method
		 * is what the carrier is asked to store, and it is also what a later
		 * read-back will be compared against byte-for-byte -- so the two have
		 * to be the same bytes. Doing it before the filter would let a shop's
		 * own callback put a trailing space back in, and the comparison would
		 * then fail for a reason that has nothing to do with the carrier.
		 */
		$shipment = self::canonicalize_request( $shipment );

		return array(
			'ok'       => true,
			'code'     => '',
			'message'  => '',
			'shipment' => $shipment,
		);
	}

	/**
	 * Put every amendable value into its one canonical form.
	 *
	 * @param array<string, mixed> $shipment Shipment request.
	 * @return array<string, mixed>
	 */
	public static function canonicalize_request( array $shipment ): array {
		foreach ( array( 'content', 'description' ) as $field ) {
			$shipment[ $field ] = self::canonical_amendable_value( $shipment[ $field ] ?? '' );
		}

		$recipient = (array) ( $shipment['recipient'] ?? array() );

		foreach ( array( 'full_name', 'address', 'mobile_phone' ) as $field ) {
			$recipient[ $field ] = self::canonical_amendable_value( $recipient[ $field ] ?? '' );
		}

		$shipment['recipient'] = $recipient;

		$pieces = (array) ( $shipment['pieces'] ?? array() );

		foreach ( $pieces as $index => $piece ) {
			$piece            = (array) $piece;
			$piece['content'] = self::canonical_amendable_value( $piece['content'] ?? '' );
			$pieces[ $index ] = $piece;
		}

		$shipment['pieces'] = $pieces;

		return $shipment;
	}

	/**
	 * THE canonical form of an amendable value. One definition, both sides.
	 *
	 * "EXACT" HAS TO MEAN SOMETHING. fields_match() used to trim both sides
	 * before comparing, which made the word false: a carrier that had stored
	 * ' Ada Lovelace' when 'Ada Lovelace' was sent passed the check, and the
	 * shop was told the amendment had been applied exactly. Silently absorbing
	 * a difference is the opposite of verifying one.
	 *
	 * The fix is not a looser comparison, it is a DEFINED one. There is exactly
	 * one canonical form; it is applied to the value before the request leaves
	 * (see canonicalize_request(), which runs last in build_request()); and the
	 * read-back is then compared against it with no tolerance whatsoever. The
	 * carrier is asked to store precisely the bytes the comparison will demand.
	 *
	 * The form: surrounding whitespace removed, internal runs of whitespace --
	 * including the tabs and newlines a pasted address carries -- collapsed to
	 * one space. Unicode-aware, because Turkish addresses are not ASCII.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function canonical_amendable_value( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$collapsed = preg_replace( '/\s+/u', ' ', (string) $value );

		return trim( null !== $collapsed ? $collapsed : (string) $value );
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
			Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED => __( 'Bu siparişte gönderilmiş bir iptal isteği var ve sonucu doğrulanıyor. Yeni gönderi oluşturulmadı.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED => __( 'Bu siparişte gönderilmiş bir güncelleme isteği var ve sonucu doğrulanıyor. Yeni gönderi oluşturulmadı.', 'kuka-island-shipping-automation' ),
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
			Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED => __( 'Bu siparişte gönderilmiş bir iptal isteği doğrulanıyor. Barkod aşaması sürdürülmedi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED => __( 'Bu siparişte gönderilmiş bir güncelleme doğrulanıyor. Barkod aşaması sürdürülmedi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_DELIVERED          => __( 'Bu sipariş teslim edilmiş. Barkod aşaması sürdürülmedi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW      => __( 'Bu siparişin kargo durumu manuel inceleme bekliyor. Barkod aşaması sürdürülmedi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_CANCELLED          => __( 'Bu siparişin taşıyıcı kaydı iptal edilmiş. Barkod aşaması sürdürülmedi.', 'kuka-island-shipping-automation' ),
			default                                                    => __( 'Bu siparişte taşıyıcıda bekleyen bir sipariş kaydı yok; sürdürülecek bir barkod aşaması bulunmuyor.', 'kuka-island-shipping-automation' ),
		};
	}

	/**
	 * Operator-facing sentence for a state a cancellation cannot be sent from.
	 *
	 * Public and static so the exact wording can be asserted without an admin
	 * screen, exactly like state_message() and resume_refusal_message().
	 *
	 * @param string $state             State read inside the lock.
	 * @param bool   $shipment_id_empty True when the record says a shipment
	 *                                  exists but does not say which.
	 */
	public static function cancel_refusal_message( string $state, bool $shipment_id_empty = false ): string {
		if ( Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $state && $shipment_id_empty ) {
			return __( 'Bu siparişte gönderi var fakat gönderi numarası bilinmiyor; iptal edilecek kayıt adreslenemiyor. Önce salt-okunur mutabakat çalıştırılmalı.', 'kuka-island-shipping-automation' );
		}

		return match ( $state ) {
			Kuka_Island_Shipping_Order_Store::STATE_NONE               => __( 'Bu siparişte taşıyıcı kaydı yok; iptal edilecek bir şey bulunmuyor.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_BLOCKED            => __( 'Bu sipariş için taşıyıcıya hiç çağrı yapılmadı; iptal edilecek bir kayıt yok.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED   => __( 'Mutabakat taşıyıcıda bu referansla kayıt olmadığını gösterdi; iptal edilecek bir şey yok.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED => __( 'Bu siparişte belirsiz bir taşıyıcı yanıtı var. İptal tekrarlanmadı; önce salt-okunur mutabakat çalıştırılmalı.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED => __( 'Bu siparişte doğrulanmayı bekleyen bir güncelleme var ve güncellemenin hangi kayda gönderildiği okunamadı; iptal edilecek kayıt adreslenemiyor. Önce salt-okunur mutabakat çalıştırılmalı.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_DELIVERED          => __( 'Bu sipariş teslim edilmiş. Teslim edilmiş bir gönderi iptal edilmez; iade süreci ayrıdır.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW      => __( 'Bu siparişin kargo durumu manuel inceleme bekliyor. İptal otomatik yapılmaz.', 'kuka-island-shipping-automation' ),
			default                                                    => __( 'Bu siparişin durumu iptal için tanınmıyor; hiçbir çağrı yapılmadı.', 'kuka-island-shipping-automation' ),
		};
	}

	/**
	 * Operator-facing sentence for a state an amendment cannot be sent from.
	 *
	 * @param string $state             State read inside the lock.
	 * @param bool   $shipment_id_empty True when the shipment id is unknown.
	 */
	public static function update_refusal_message( string $state, bool $shipment_id_empty = false ): string {
		if ( Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $state && $shipment_id_empty ) {
			return __( 'Bu siparişte gönderi var fakat gönderi numarası bilinmiyor; güncellenecek kayıt adreslenemiyor. Önce salt-okunur mutabakat çalıştırılmalı.', 'kuka-island-shipping-automation' );
		}

		return match ( $state ) {
			Kuka_Island_Shipping_Order_Store::STATE_CANCELLED          => __( 'Bu siparişin taşıyıcı kaydı iptal edilmiş; güncellenecek bir kayıt yok.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_CANCEL_RECONCILE_REQUIRED => __( 'Bu siparişte gönderilmiş bir iptal isteği doğrulanıyor. Güncelleme gönderilmedi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_UPDATE_RECONCILE_REQUIRED => __( 'Bu siparişte gönderilmiş bir güncelleme zaten doğrulanmayı bekliyor. İkinci güncelleme gönderilmedi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED => __( 'Bu siparişte belirsiz bir taşıyıcı yanıtı var. Güncelleme yapılmadı; önce salt-okunur mutabakat çalıştırılmalı.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_DELIVERED          => __( 'Bu sipariş teslim edilmiş; taşıyıcı kaydı güncellenmedi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW      => __( 'Bu siparişin kargo durumu manuel inceleme bekliyor; taşıyıcı kaydı güncellenmedi.', 'kuka-island-shipping-automation' ),
			default                                                    => __( 'Bu siparişte güncellenebilecek bir taşıyıcı kaydı yok.', 'kuka-island-shipping-automation' ),
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
		if ( ! $result->reached_carrier() ) {
			return $this->close_unsent_intent( $order, $result );
		}

		/*
		 * THE CARRIER ANSWERED, AND THE ANSWER IS NOT EVIDENCE OF ABSENCE.
		 *
		 * This branch used to leave the order in whatever state it was in, on
		 * the assumption that a 'permanent' answer means nothing was created.
		 * The vendor's own contract does not support that assumption: all six
		 * write operations document exactly four responses -- 200, 400 "Bad
		 * Request", 401 "Unauthorized", 500 "Server Error" -- and not one of
		 * them says whether a record was left behind. There is therefore no
		 * error this integration may treat as "no side effect", and the
		 * allow-list for closing an intent on an answer alone is EMPTY.
		 *
		 * So the order stays in the protected state begin_mutation() put it in,
		 * and the only way out is the read-only reconciliation -- which, for a
		 * create, can prove absence and hand the order back with
		 * absent_confirmed so a fresh attempt is legitimate again.
		 */
		$message = __( 'Taşıyıcı isteği reddedildi. Reddin kayıt oluşturmadığı sözleşmede yazılı olmadığı için yeniden gönderim açılmadı; salt-okunur mutabakat gerekiyor.', 'kuka-island-shipping-automation' );

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
	 * Close an intent because the request provably never left the building.
	 *
	 * THE ONLY WAY AN INTENT IS CLOSED WITHOUT READING. The adapter refused
	 * before opening a socket -- an incomplete payload, a cash-on-delivery
	 * flag, an endpoint that is not on the allow-list -- so the carrier cannot
	 * hold anything new, and that is provable from the code path rather than
	 * inferred from a status code. Result::local_refusal() is the only factory
	 * that produces such an answer.
	 *
	 * The order returns to the state its intent recorded, and the pending
	 * record is cleared in the SAME save, so there is no instant in which the
	 * two disagree.
	 *
	 * @return array{ok: bool, state: string, code: string, message: string, detail: string}
	 */
	private function close_unsent_intent( WC_Order $order, Kuka_Island_Shipping_Result $result ): array {
		$pending  = Kuka_Island_Shipping_Order_Store::pending_mutation( $order );
		$previous = (string) ( $pending['previous_state'] ?? '' );

		if ( '' === $previous ) {
			$previous = Kuka_Island_Shipping_Order_Store::get_state( $order );
		}

		$message = __( 'Taşıyıcıya hiçbir istek gönderilmedi: adaptör isteği ağa çıkmadan reddetti. Kayıt önceki durumuna döndürüldü.', 'kuka-island-shipping-automation' );

		Kuka_Island_Shipping_Order_Store::settle_mutation(
			$order,
			$previous,
			$result->get_operation(),
			$message . ' ' . $result->to_safe_line(),
			array( Kuka_Island_Shipping_Order_Store::META_LAST_ERROR => $result->get_safe_error_code() )
		);
		$this->note( $order, $message . ' ' . $result->to_safe_line() );

		return array(
			'ok'      => false,
			'state'   => $previous,
			'code'    => $result->get_safe_error_code(),
			'message' => $message,
			'detail'  => $result->to_safe_line() . '|restored:' . $previous,
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
