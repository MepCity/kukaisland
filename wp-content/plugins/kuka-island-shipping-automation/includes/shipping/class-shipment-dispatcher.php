<?php
/**
 * Automatic shipment creation, for shops that ask for it.
 *
 * THE DEFAULT IS OFF, AND THAT IS NOT TIMIDITY. Booking a shipment is an
 * outward-facing act: a label is printed, a courier is dispatched, a charge is
 * raised, and somebody has to cancel it if it was wrong. Everything in this
 * module up to now happened because an operator pressed a button. This class is
 * the one place where a machine decides, so it is the one place that has to be
 * hardest to open by accident.
 *
 * NOTHING IS SENT INSIDE THE CHECKOUT REQUEST. A payment callback that waited on
 * a carrier API would make the shop's slowest page the courier's uptime, and a
 * timeout there is a customer staring at a spinner after paying. The hooks book
 * ONE Action Scheduler job and return; the job is where the carrier is
 * contacted.
 *
 * THREE LOCKS, THREE DIFFERENT JOBS. They are separate on purpose and none of
 * them is a spelling of another:
 *
 *   kuka_ship_query_<id>     BOOKING. Two requests must not both see "no
 *                            pending job" and both create one. Nothing is sent
 *                            under it; it is released before the job runs.
 *   kuka_ship_dispatch_<id>  EXECUTION. One worker turn per order at a time,
 *                            held from the fresh read through the eligibility
 *                            decision, the bookkeeping and the Manager call to
 *                            the settlement. Zero wait: a second worker leaves
 *                            rather than queues.
 *   kuka_ship_mutate_<id>    CARRIER MUTATION. Taken inside the Manager, and
 *                            taken by an operator's button press too, so a
 *                            worker and a person cannot talk to the carrier
 *                            about one order at the same time.
 *
 * The execution lock is what the other two cannot replace. The booking lock is
 * long gone by the time the job runs; the mutation lock is taken INSIDE the
 * Manager, after both workers have already written their own bookkeeping -- and
 * the loser, believing it sent nothing, would then clear the phase mark the
 * winner is relying on.
 *
 * ONE JOB PER ORDER, WHATEVER HAPPENS. woocommerce_payment_complete, the
 * gateway's own callback and the status transition can all fire for the same
 * order within the same second, from different requests. The booking is taken
 * under the BOOKING lock and checked against the pending rows, so three events
 * still produce one job.
 *
 * ELIGIBILITY IS AN ALLOW-LIST. Not "everything except the cases we thought of":
 * an order qualifies only by satisfying every condition in eligibility(), and a
 * condition nobody anticipated fails closed by simply not being on the list.
 *
 * THE TWO PHASES ARE NOT MERGED. One run() turn calls createOrder, reads the
 * order back from the database and, only when the state is exactly
 * `order_created`, books a distinct later worker for createbarcode. DHL says a
 * back-to-back barcode call can race destination-branch resolution. Anything
 * else -- an uncertain write, a refusal, a state somebody else moved -- stops.
 * There is no automatic second attempt at a write that may already have reached
 * the carrier; the read-only reconciliation path is the only way out of that,
 * and it needs a person.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Dispatcher {

	/** The scheduled worker's hook. */
	public const ACTION = 'kuka_island_shipping_auto_create';

	/** Shared with the poller so deactivation can cancel both by group. */
	public const GROUP = 'kuka-island-shipping';

	/** How long after payment the job runs. Long enough for a refund reflex. */
	public const DELAY = 300;

	/** How many worker turns one order may ever get. */
	public const MAX_ATTEMPTS = 4;

	/** How long after a retryable refusal the next turn runs. */
	public const RETRY_DELAY = 120;

	/**
	 * Minimum separation between createOrder and createbarcode.
	 *
	 * DHL says the destination branch can still be unresolved when the two
	 * writes are made back to back. The interval is an operational buffer, not
	 * a claim that branch readiness has been proved; the API exposes no such
	 * readiness field.
	 */
	public const BARCODE_DELAY = 300;

	/** Inter-phase buffer in the test environment. Live stays at BARCODE_DELAY. */
	public const PHASE_DELAY_TEST = 60;

	public const PHASE_NONE             = 'none';
	public const PHASE_CREATE_RECIPIENT = 'create_recipient';
	public const PHASE_CREATE_ORDER     = 'create_order';
	public const PHASE_CREATE_BARCODE   = 'create_barcode';

	/** The worker's own per-order execution lock. See the class comment. */
	private const EXECUTION_LOCK_PREFIX = 'kuka_ship_dispatch_';

	/**
	 * Seconds between automatic phases.
	 *
	 * Test uses 60. Live, and any environment that is not explicitly test,
	 * stays at 300 until a shorter live buffer is measured. An explicit
	 * KUKA_SHIPPING_PHASE_DELAY of at least 60 overrides both.
	 */
	public static function phase_delay(): int {
		if ( defined( 'KUKA_SHIPPING_PHASE_DELAY' ) ) {
			$configured = (int) constant( 'KUKA_SHIPPING_PHASE_DELAY' );

			if ( $configured >= self::PHASE_DELAY_TEST ) {
				return $configured;
			}
		}

		$from_env = getenv( 'KUKA_SHIPPING_PHASE_DELAY' );

		if ( false !== $from_env && '' !== $from_env && (int) $from_env >= self::PHASE_DELAY_TEST ) {
			return (int) $from_env;
		}

		if ( class_exists( 'Kuka_Island_Shipping_Settings' )
			&& Kuka_Island_Shipping_Carrier_Interface::ENVIRONMENT_TEST === Kuka_Island_Shipping_Settings::environment() ) {
			return self::PHASE_DELAY_TEST;
		}

		return self::BARCODE_DELAY;
	}

	/**
	 * THE ONE PLACE A RETRY IS ALLOWED FROM. Nowhere else may decide this.
	 *
	 * A reason qualifies only if it is transient AND the request provably never
	 * left this process. Today exactly one refusal is both: another process held
	 * the order's mutation lock, so the Manager returned before building
	 * anything. `credentials_missing`, `carrier_not_registered`,
	 * `shipping_runtime_disabled`, `cod_not_supported` and the state refusals
	 * are all "a person must change something" -- retrying them is a busy loop
	 * with an audit trail.
	 *
	 * Membership in this list is NOT sufficient on its own; see retryable().
	 *
	 * @return array<int, string>
	 */
	public static function retryable_reasons(): array {
		return array( 'lock_contended' );
	}

	private Kuka_Island_Shipping_Manager $manager;

	public function __construct( ?Kuka_Island_Shipping_Manager $manager = null ) {
		$this->manager = $manager ?? new Kuka_Island_Shipping_Manager();
	}

	/**
	 * Attach the booking hooks and the worker.
	 *
	 * Three entry points because a shop can reach "paid and shippable" by three
	 * different routes, and none of them is guaranteed to fire. They are
	 * deliberately redundant; maybe_schedule() is what makes the redundancy
	 * harmless.
	 */
	public function register(): void {
		add_action( 'woocommerce_payment_complete', array( $this, 'on_event' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_event' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_event' ), 20, 1 );
		add_action( self::ACTION, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * A WooCommerce event happened. Book at most one job, and never call out.
	 *
	 * @param mixed $order_id Order id as WooCommerce passed it.
	 */
	public function on_event( $order_id ): void {
		$this->maybe_schedule( (int) $order_id );
	}

	/**
	 * Would this order be booked automatically, and if not, why not?
	 *
	 * Pure and public so the order screen can print the same answer the
	 * scheduler would reach, without booking anything to find out.
	 *
	 * @return array{eligible: bool, reason: string, phase: string}
	 */
	public static function eligibility( ?WC_Order $order ): array {
		if ( ! $order instanceof WC_Order ) {
			return self::no( 'order_unreadable' );
		}

		if ( ! Kuka_Island_Shipping_Settings::is_auto_create_enabled() ) {
			return self::no( 'auto_create_disabled' );
		}

		if ( ! Kuka_Island_Shipping_Settings::is_module_enabled() ) {
			return self::no( Kuka_Island_Shipping_Runtime_Gate::CODE );
		}

		$readiness = Kuka_Island_Shipping_Settings::readiness();

		if ( ! $readiness['ready'] ) {
			return self::no( (string) $readiness['code'] );
		}

		/*
		 * PAID, PROVEN BY THE ORDER ITSELF. A status of processing is not
		 * payment: a shop can move an order there by hand. date_paid is what
		 * WooCommerce writes when money was actually taken, and the two are
		 * required together so neither a manual status nor a stale timestamp is
		 * enough on its own.
		 */
		if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return self::no( 'order_status_not_shippable' );
		}

		if ( null === $order->get_date_paid() ) {
			return self::no( 'payment_not_confirmed' );
		}

		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) ) ) {
			return self::no( 'order_not_active' );
		}

		if ( (float) $order->get_total_refunded() > 0 ) {
			return self::no( 'order_refunded' );
		}

		if ( ! self::has_physical_item( $order ) ) {
			return self::no( 'nothing_to_ship' );
		}

		$address = self::address_gaps( $order );

		if ( array() !== $address ) {
			return self::no( 'address_incomplete:' . implode( '+', $address ) );
		}

		$data = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );

		if ( array() !== (array) $data['pending_mutation'] ) {
			return self::no( 'mutation_in_progress' );
		}

		if ( (int) $data['dispatch_attempts'] >= self::MAX_ATTEMPTS ) {
			return self::no( 'retry_budget_spent' );
		}

		/*
		 * WHICH PHASE, IF ANY, THIS ORDER IS AT.
		 *
		 * Only two states are open to the automatic path, and they are open to
		 * DIFFERENT work:
		 *
		 *   none           nothing has been sent -> the createOrder phase
		 *   order_created  the carrier has the ORDER and no shipment exists for
		 *                  it -> the createbarcode phase, and createOrder must
		 *                  NOT be repeated
		 *
		 * Everything else -- reconciliation states, manual review, cancelled,
		 * delivered, blocked, or anything unrecognised -- belongs to a person
		 * who is already looking at it, and a machine joining in is how the same
		 * parcel goes twice.
		 */
		$state = (string) $data['state'];

		/*
		 * A PHASE THE AUTOMATIC PATH ALREADY ISSUED IS NEVER ISSUED AGAIN.
		 *
		 * The state alone cannot decide this. A read-only reconciliation can
		 * legitimately put an order back into a state a phase is allowed from --
		 * `order_created` after an inconclusive shipment query, for instance --
		 * and a worker reading only the state would take that as permission to
		 * send the same createbarcode a second time. The mark is written before
		 * the call and removed only when a refusal PROVES nothing was sent.
		 *
		 * An operator can still press the button: this is the automatic path's
		 * own restraint, not a lock on the order.
		 */
		$issued = (array) $data['dispatch_phases'];

		if ( Kuka_Island_Shipping_Order_Store::STATE_NONE === $state ) {
			if ( '' !== (string) $data['shipment_id'] ) {
				return self::no( 'shipment_already_recorded' );
			}

			if ( in_array( self::PHASE_CREATE_RECIPIENT, $issued, true ) ) {
				return self::no( 'phase_already_attempted:' . self::PHASE_CREATE_RECIPIENT );
			}

			return array(
				'eligible' => true,
				'reason'   => '',
				'phase'    => self::PHASE_CREATE_RECIPIENT,
			);
		}

		if ( Kuka_Island_Shipping_Order_Store::STATE_RECIPIENT_CREATED === $state ) {
			if ( in_array( self::PHASE_CREATE_ORDER, $issued, true ) ) {
				return self::no( 'phase_already_attempted:' . self::PHASE_CREATE_ORDER );
			}

			return array(
				'eligible' => true,
				'reason'   => '',
				'phase'    => self::PHASE_CREATE_ORDER,
			);
		}

		if ( Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $state ) {
			if ( in_array( self::PHASE_CREATE_BARCODE, $issued, true ) ) {
				return self::no( 'phase_already_attempted:' . self::PHASE_CREATE_BARCODE );
			}

			return array(
				'eligible' => true,
				'reason'   => '',
				'phase'    => self::PHASE_CREATE_BARCODE,
			);
		}

		return self::no( 'carrier_record_exists:' . $state );
	}

	/**
	 * @return array{eligible: false, reason: string, phase: string}
	 */
	private static function no( string $reason ): array {
		return array(
			'eligible' => false,
			'reason'   => $reason,
			'phase'    => self::PHASE_NONE,
		);
	}

	/** Is there anything in this order that actually has to travel? */
	private static function has_physical_item( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof WC_Product ) {
				// A line whose product was deleted cannot be proven virtual, and
				// a parcel that should have shipped is worse than one that was
				// refused: it is treated as physical.
				return true;
			}

			if ( ! $product->is_virtual() && ! $product->is_downloadable() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Which recipient fields the carrier would refuse the order for.
	 *
	 * Names only, and checked here rather than left to the carrier: a request
	 * rejected at the carrier for a missing telephone number is a round trip
	 * that had no chance, and its refusal lands in the order's history as if
	 * something had gone wrong at the courier.
	 *
	 * @return array<int, string>
	 */
	private static function address_gaps( WC_Order $order ): array {
		$gaps = array();

		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );

		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		if ( '' === $name ) {
			$gaps[] = 'name';
		}

		if ( '' === trim( $order->get_shipping_address_1() ) && '' === trim( $order->get_billing_address_1() ) ) {
			$gaps[] = 'address';
		}

		if ( '' === trim( $order->get_shipping_state() ) && '' === trim( $order->get_billing_state() ) ) {
			$gaps[] = 'city';
		}

		if ( '' === trim( $order->get_shipping_city() ) && '' === trim( $order->get_billing_city() ) ) {
			$gaps[] = 'district';
		}

		if ( '' === trim( (string) $order->get_billing_phone() ) ) {
			$gaps[] = 'phone';
		}

		return $gaps;
	}

	/**
	 * Book one job for this order, or say why not.
	 *
	 * @return array{scheduled: bool, reason: string}
	 */
	public function maybe_schedule( int $order_id, bool $is_retry = false, ?int $delay = null ): array {
		$order       = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$eligibility = self::eligibility( $order instanceof WC_Order ? $order : null );

		if ( ! $eligibility['eligible'] ) {
			return array(
				'scheduled' => false,
				'reason'    => (string) $eligibility['reason'],
			);
		}

		/*
		 * A WooCommerce EVENT books work only for an order nothing has been sent
		 * for. An order already at `order_created` got there because somebody --
		 * an operator, or an earlier turn of this job -- made the first carrier
		 * write, and a payment hook firing again is not a reason to finish their
		 * work for them. Only a retry booked by this class may target phase two.
		 */
		if ( ! $is_retry && self::PHASE_CREATE_RECIPIENT !== (string) $eligibility['phase'] ) {
			return array(
				'scheduled' => false,
				'reason'    => 'phase_not_bookable_by_event:' . (string) $eligibility['phase'],
			);
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return array(
				'scheduled' => false,
				'reason'    => 'scheduler_unavailable',
			);
		}

		/*
		 * THE BOOKING LOCK, `kuka_ship_query_<id>`. Not the execution lock and
		 * not the mutation lock: this one only stops two requests from both
		 * seeing "no pending job" and both creating one. Nothing is sent under
		 * it, and it is released before the job it books ever runs.
		 */
		if ( ! Kuka_Island_Shipping_Status_Poller::acquire_lock( $order_id ) ) {
			return array(
				'scheduled' => false,
				'reason'    => 'lock_contended',
			);
		}

		try {
			if ( self::has_pending_job( $order_id ) ) {
				return array(
					'scheduled' => false,
					'reason'    => 'already_scheduled',
				);
			}

			$action_id = (int) as_schedule_single_action(
				time() + max( 1, $delay ?? ( $is_retry ? self::RETRY_DELAY : self::DELAY ) ),
				self::ACTION,
				array( 'order_id' => $order_id ),
				self::GROUP
			);

			/*
			 * THE ROW IS READ BACK. as_schedule_single_action() returning an id
			 * is the scheduler saying it accepted the booking, not the store
			 * saying it holds one -- and "a retry was scheduled" written into an
			 * order that has no pending row is exactly the kind of note that
			 * stops anybody looking further.
			 */
			$booked = $action_id > 0 && self::has_pending_job( $order_id );

			return array(
				'scheduled' => $booked,
				'reason'    => $booked ? '' : 'schedule_failed',
			);
		} finally {
			Kuka_Island_Shipping_Status_Poller::release_lock( $order_id );
		}
	}

	/** Is a job already waiting for this order? */
	public static function has_pending_job( int $order_id ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$rows = (array) as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION,
				'args'     => array( 'order_id' => $order_id ),
				'status'   => 'pending',
				'group'    => self::GROUP,
				'per_page' => 1,
			),
			'ids'
		);

		return array() !== $rows;
	}

	/** Cancel every pending job for one order. */
	public static function cancel_jobs( int $order_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array( 'order_id' => $order_id ), self::GROUP );
		}
	}

	/**
	 * The scheduled worker: one proven phase per turn.
	 *
	 * @param mixed $order_id Order id as Action Scheduler stored it.
	 * @return array<string, mixed>
	 */
	public function run( $order_id ): array {
		$order_id = (int) ( is_array( $order_id ) ? ( $order_id['order_id'] ?? 0 ) : $order_id );

		if ( $order_id <= 0 ) {
			return self::outcome( false, 'dispatch_order_unreadable', self::PHASE_NONE, false );
		}

		/*
		 * THE EXECUTION LOCK IS TAKEN FIRST -- before the fresh read, before the
		 * eligibility decision, before any bookkeeping. A second worker that got
		 * as far as reading the order would already be holding an answer the
		 * first worker is about to invalidate.
		 */
		if ( ! self::acquire_execution_lock( $order_id ) ) {
			// Nothing is written, nothing is marked, no attempt is spent and no
			// retry is booked: this turn did not happen.
			return self::outcome( false, 'dispatch_in_progress', self::PHASE_NONE, false );
		}

		try {
			return $this->run_locked( $order_id );
		} finally {
			// Released on every exit, including a Throwable on the way out.
			self::release_execution_lock( $order_id );
		}
	}

	/**
	 * One worker turn, with this order's execution lock already held.
	 *
	 * @return array<string, mixed>
	 */
	private function run_locked( int $order_id ): array {
		$order = self::reload( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return self::outcome( false, 'dispatch_order_unreadable', self::PHASE_NONE, false );
		}

		$eligibility = self::eligibility( $order );

		/*
		 * RE-CHECKED HERE, NOT ONLY AT BOOKING. Minutes passed between the two,
		 * and in those minutes the order can have been refunded, the module can
		 * have been switched off, or an operator can have created the shipment
		 * by hand. The booking decided what to try; this decides what to do, and
		 * WHICH PHASE to do it from.
		 */
		if ( ! $eligibility['eligible'] ) {
			$this->record( $order_id, (string) $eligibility['reason'] );

			return self::outcome( false, (string) $eligibility['reason'], self::PHASE_NONE, false );
		}

		$phase      = (string) $eligibility['phase'];
		$phases_run = array();

		/*
		 * THE ATTEMPT AND THE PHASE MARK ARE WRITTEN, READ BACK, AND ONLY THEN
		 * IS THE CARRIER CONTACTED. A save that returns without an error is not
		 * proof that a row landed; the carrier is the one party that cannot be
		 * asked to forget.
		 */
		$opened = Kuka_Island_Shipping_Order_Store::begin_dispatch_phase( self::reload( $order_id ), $phase );

		if ( ! $opened['ok'] ) {
			$this->record( $order_id, (string) $opened['code'] );

			return self::outcome( false, (string) $opened['code'], self::PHASE_NONE, false );
		}

		if ( self::PHASE_CREATE_RECIPIENT === $phase ) {
			$phases_run[] = 'create_recipient';
			$created      = $this->manager->create_recipient( self::reload( $order_id ) );

			if ( empty( $created['ok'] ) ) {
				return $this->settle_refusal( $order_id, (string) ( $created['code'] ?? 'create_recipient_refused' ), self::PHASE_CREATE_RECIPIENT, $phases_run );
			}

			$state = Kuka_Island_Shipping_Order_Store::get_state( self::reload( $order_id ) );

			if ( Kuka_Island_Shipping_Order_Store::STATE_RECIPIENT_CREATED !== $state ) {
				$this->record( $order_id, 'order_not_allowed:' . $state );

				return self::outcome( false, 'order_not_allowed:' . $state, implode( '+', $phases_run ), false );
			}

			return $this->schedule_follow_up( $order_id, $phases_run, Kuka_Island_Shipping_Order_Store::STATE_RECIPIENT_CREATED );
		}

		// PHASE TWO: the carrier registers the ORDER, and stops there.
		if ( self::PHASE_CREATE_ORDER === $phase ) {
			$phases_run[] = 'create_order';

			$created = $this->manager->create_shipment( self::reload( $order_id ) );

			if ( empty( $created['ok'] ) ) {
				return $this->settle_refusal( $order_id, (string) ( $created['code'] ?? 'create_order_refused' ), self::PHASE_CREATE_ORDER, $phases_run );
			}

			/*
			 * THE STATE IS READ BACK FROM THE DATABASE, not inferred from the
			 * result that was just returned. Phase two is allowed only from the
			 * one state that means "an order exists at the carrier and no
			 * shipment does".
			 */
			$state = Kuka_Island_Shipping_Order_Store::get_state( self::reload( $order_id ) );

			if ( ! in_array( $state, Kuka_Island_Shipping_Order_Store::states_allowing_create_barcode(), true ) ) {
				$this->record( $order_id, 'barcode_not_allowed:' . $state );

				return self::outcome( false, 'barcode_not_allowed:' . $state, implode( '+', $phases_run ), false );
			}

			/*
			 * DHL explicitly warns against sending createbarcode immediately after
			 * createOrder: destination-branch resolution may still be running. End
			 * this worker turn and book a distinct phase-two turn. This separation
			 * is structural; BARCODE_DELAY is only a buffer because the documented
			 * read APIs expose no branch-ready flag.
			 */
			return $this->schedule_follow_up( $order_id, $phases_run, Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED );
		}

		// PHASE TWO: a separate, deliberate second write.
		$phases_run[] = 'create_barcode';

		$barcoded = $this->manager->resume_barcode( self::reload( $order_id ) );

		if ( empty( $barcoded['ok'] ) ) {
			return $this->settle_refusal( $order_id, (string) ( $barcoded['code'] ?? 'create_barcode_refused' ), self::PHASE_CREATE_BARCODE, $phases_run );
		}

		$this->record( $order_id, '' );

		return array(
			'ok'              => true,
			'reason'          => '',
			'phases'          => implode( '+', $phases_run ),
			'retry_scheduled' => false,
			'state'           => (string) $barcoded['state'],
		);
	}

	/**
	 * A refusal: decide whether it may be retried, and book it if so.
	 *
	 * @param array<int, string> $phases_run Phases this turn actually entered.
	 * @return array<string, mixed>
	 */
	/**
	 * End this worker and book the next phase as its own job.
	 *
	 * @param array<int, string> $phases_run Phases this turn entered.
	 * @return array<string, mixed>
	 */
	private function schedule_follow_up( int $order_id, array $phases_run, string $state ): array {
		$booking = $this->maybe_schedule( $order_id, true, self::phase_delay() );
		$booked  = (bool) $booking['scheduled'] || self::has_pending_job( $order_id );

		if ( ! $booked ) {
			$reason = 'next_phase_' . (string) $booking['reason'];
			$this->record( $order_id, $reason );

			return self::outcome( false, $reason, implode( '+', $phases_run ), false );
		}

		$this->record( $order_id, '' );

		return array(
			'ok'                  => true,
			'reason'              => '',
			'phases'              => implode( '+', $phases_run ),
			'retry_scheduled'     => false,
			'next_phase_scheduled' => true,
			'state'               => $state,
		);
	}

	private function settle_refusal( int $order_id, string $reason, string $phase, array $phases_run ): array {
		$retry = false;

		if ( self::retryable( $reason, self::reload( $order_id ), $phase ) ) {
			/*
			 * PROVEN NOT SENT, so the phase mark comes back off and another turn
			 * may try it. This is the only place a mark is ever removed -- and
			 * the removal is read back, because a retry booked while the mark is
			 * still on disk would meet it and could only refuse itself.
			 */
			$cleared = Kuka_Island_Shipping_Order_Store::clear_dispatch_phase( self::reload( $order_id ), $phase );

			if ( ! $cleared['ok'] ) {
				$this->record( $order_id, (string) $cleared['code'] );

				return self::outcome( false, (string) $cleared['code'], implode( '+', $phases_run ), false );
			}

			$data = Kuka_Island_Shipping_Order_Store::get_shipment_data( self::reload( $order_id ) );

			if ( (int) $data['dispatch_attempts'] >= self::MAX_ATTEMPTS ) {
				// The budget is already gone; booking a turn nobody may take
				// would leave a pending row that can only refuse itself.
				$reason = 'retry_budget_spent';
			} else {
				$booking = $this->maybe_schedule( $order_id, true );

				/*
				 * A row that was ALREADY pending is a scheduled retry too. The
				 * distinction that matters is "is a turn waiting", not "did this
				 * call create it".
				 */
				$retry = (bool) $booking['scheduled'] || self::has_pending_job( $order_id );

				if ( ! $retry ) {
					// The booking did not happen. Say THAT, not "retry scheduled".
					$reason = 'retry_' . (string) $booking['reason'];
				}
			}
		}

		$this->record( $order_id, $reason );

		return self::outcome( false, $reason, implode( '+', $phases_run ), $retry );
	}

	/**
	 * May this refusal be tried again, and is it provable that nothing was sent?
	 *
	 * TWO CONDITIONS, BOTH REQUIRED, AND NEITHER IS AN HTTP CODE.
	 *
	 * 1. The reason is on the one declared list of transient, pre-network
	 *    refusals (retryable_reasons()).
	 * 2. The ORDER ITSELF, read fresh from the database, still looks exactly as
	 *    it did before the turn: no pending mutation, no shipment id, and the
	 *    state still the one this phase starts from.
	 *
	 * The second condition is the structural proof. begin_mutation() writes the
	 * pending intent AND moves the order into its protected state BEFORE the
	 * request is built, so an order that shows neither cannot have had a request
	 * sent for it. Nothing here reads a status code, and nothing infers "it was
	 * probably local" from a transport error -- an answer this module cannot
	 * account for leaves the intent behind, and that is what stops the retry.
	 */
	public static function retryable( string $reason, ?WC_Order $order, string $phase ): bool {
		if ( ! in_array( $reason, self::retryable_reasons(), true ) ) {
			return false;
		}

		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$data = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );

		if ( array() !== (array) $data['pending_mutation'] ) {
			return false;
		}

		if ( '' !== (string) $data['shipment_id'] ) {
			return false;
		}

		$expected = match ( $phase ) {
			self::PHASE_CREATE_RECIPIENT => Kuka_Island_Shipping_Order_Store::STATE_NONE,
			self::PHASE_CREATE_ORDER     => Kuka_Island_Shipping_Order_Store::STATE_RECIPIENT_CREATED,
			default                      => Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED,
		};

		return $expected === (string) $data['state'];
	}

	/**
	 * Record the outcome where an operator can see it, without repeating it.
	 *
	 * A note is added only when the reason CHANGED. Three turns refused for the
	 * same contention are one fact, and three identical notes on an order is how
	 * a panel becomes unreadable.
	 */
	private function record( int $order_id, string $reason ): void {
		$order = self::reload( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$previous = (string) Kuka_Island_Shipping_Order_Store::get_shipment_data( $order )['dispatch_reason'];

		Kuka_Island_Shipping_Order_Store::record_dispatch_reason( $order, $reason );

		if ( '' === $reason || $reason === $previous ) {
			return;
		}

		$fresh = self::reload( $order_id );

		if ( $fresh instanceof WC_Order ) {
			$fresh->add_order_note(
				sprintf(
					/* translators: %s: allow-listed safe reason code. */
					__( 'Otomatik kargo oluşturma bu turda tamamlanmadı (%s). Taşıyıcıya tekrar eden bir istek gönderilmedi.', 'kuka-island-shipping-automation' ),
					$reason
				)
			);
		}
	}

	/**
	 * @return array{ok: bool, reason: string, phases: string, retry_scheduled: bool}
	 */
	private static function outcome( bool $ok, string $reason, string $phases, bool $retry ): array {
		return array(
			'ok'              => $ok,
			'reason'          => $reason,
			'phases'          => '' === $phases ? 'none' : $phases,
			'retry_scheduled' => $retry,
		);
	}

	/**
	 * Take this order's EXECUTION lock, or leave. Zero wait, by design.
	 *
	 * A second worker that waited would eventually run with an eligibility
	 * answer taken minutes ago, which is the situation this lock exists to
	 * prevent. Leaving is correct: the order still has whatever the first worker
	 * decided, and a retry is booked only by the turn that actually ran.
	 */
	private static function acquire_execution_lock( int $order_id ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::EXECUTION_LOCK_PREFIX . $order_id )
		);

		return '1' === (string) $acquired;
	}

	private static function release_execution_lock( int $order_id ): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::EXECUTION_LOCK_PREFIX . $order_id )
		);
	}

	/** An order read past this process's caches. */
	private static function reload( int $order_id ): ?WC_Order {
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}

		wp_cache_delete( $order_id, 'orders' );

		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order ? $order : null;
	}
}
