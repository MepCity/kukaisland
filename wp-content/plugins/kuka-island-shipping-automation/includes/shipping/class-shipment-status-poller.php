<?php
/**
 * The bounded status poller.
 *
 * Three properties matter, and each of them is enforced rather than intended.
 *
 * BOUNDED. At most MAX_ATTEMPTS queries, and never past MAX_ELAPSED after the
 * shipment was created. A poll chain with no ceiling is a poll chain that is
 * still running a year later against an order nobody remembers.
 *
 * The attempt count is not derived here. It is returned by the query that spent
 * it, because a query that FAILS spends an attempt too: this worker used to read
 * the count from a snapshot taken before the call and add one, and since a failed
 * query wrote no counter, every turn of a failing chain re-derived the same
 * number. The ceiling was unreachable and the chain was, in practice, infinite.
 *
 * INCREASING. The delay grows with each attempt, from a quarter of an hour to a
 * day. A parcel does not change state every five minutes, and a fixed short
 * interval is how an integration turns into a source of load on somebody else's
 * gateway.
 *
 * FINITE, AND IT STOPS ON PURPOSE. The chain ends the moment the dictionary
 * says the lifecycle is terminal -- delivered, or one of the states that needs a
 * person. It does not keep asking about a delivered parcel.
 *
 * Booking is guarded twice: a per-order advisory lock serialises the decision
 * across processes, and inside the lock only PENDING actions are counted.
 * Counting running actions too would refuse every follow-up, because the
 * follow-up is booked from inside the action that is running.
 *
 * Nothing here books anything while automation is off. The switch is checked at
 * booking time and again in the worker, so turning it off stops a chain that is
 * already in flight rather than only preventing new ones.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Status_Poller {

	public const ACTION = 'kuka_island_shipping_query_status';
	public const GROUP  = 'kuka-island-shipping';

	/**
	 * The safe local fulfilment retry, which NEVER touches the carrier.
	 *
	 * A separate hook rather than another poll, for two reasons. The carrier
	 * has already answered -- re-asking it would be a request nobody needs and
	 * would spend an attempt from a budget that exists to protect the carrier.
	 * And a terminal answer moves the order's own state to `delivered`, so the
	 * poll worker's first gate refuses it with `state_not_pollable`: reusing
	 * that hook would book an action that stops before it does anything.
	 *
	 * This worker re-reads the code the carrier ALREADY gave, from the order's
	 * own meta, and asks the fulfilment writer to finish what the claim refusal
	 * interrupted.
	 */
	public const SYNC_ACTION = 'kuka_island_shipping_sync_fulfillment';

	private const SCHEDULE_LOCK_PREFIX = 'kuka_ship_query_';

	/** Hard ceiling on how many times one order is queried. */
	public const MAX_ATTEMPTS = 10;

	/**
	 * How many safe local fulfilment retries one order may have.
	 *
	 * Small on purpose: the refusals it recovers from are transient by
	 * definition, so a handful of turns either fixes them or proves a person
	 * is needed. Nothing here contacts the carrier, so the ceiling protects
	 * the scheduler and the order notes, not the carrier.
	 */
	public const MAX_SYNC_ATTEMPTS = 5;

	/** Seconds before the first safe local retry, then the poll ladder. */
	public const SYNC_FIRST_DELAY = 2 * MINUTE_IN_SECONDS;

	/**
	 * The order lost its carrier reference, so there is nothing local to
	 * finish. Recorded rather than cleared: a delivered parcel whose customer
	 * notification stalled is never allowed to look finished.
	 */
	public const SYNC_REFERENCE_MISSING = 'sync_reference_missing';

	/** No carrier status was ever persisted, so the local half cannot run. */
	public const SYNC_STATUS_MISSING = 'sync_status_code_missing';

	/** A refusal with no reason of its own; never silently a success. */
	public const SYNC_UNKNOWN_FAILURE = 'sync_unknown_failure';

	/** Hard ceiling on how long one order is followed. */
	public const MAX_ELAPSED = 14 * DAY_IN_SECONDS;

	public const SCHEDULE_CREATED             = 'created';
	public const SCHEDULE_ALREADY_PENDING     = 'already_pending';
	public const SCHEDULE_LOCK_CONTENDED      = 'lock_contended';
	public const SCHEDULE_FAILED              = 'schedule_failed';
	public const SCHEDULE_SCHEDULER_UNAVAILABLE = 'scheduler_unavailable';
	public const SCHEDULE_AUTOMATION_DISABLED = 'automation_disabled';
	public const SCHEDULE_EXHAUSTED           = 'exhausted';

	private Kuka_Island_Shipping_Manager $manager;

	public function __construct( ?Kuka_Island_Shipping_Manager $manager = null ) {
		$this->manager = $manager ?? new Kuka_Island_Shipping_Manager();
	}

	public function register(): void {
		add_action( self::ACTION, array( $this, 'run' ), 10, 1 );
		add_action( self::SYNC_ACTION, array( $this, 'run_sync' ), 10, 1 );
	}

	/**
	 * Is automatic status polling switched on?
	 *
	 * Off unless the constant explicitly says otherwise. Every value that is not
	 * one of the four affirmatives -- including an unset constant, an empty
	 * string and any word nobody anticipated -- leaves it off.
	 */
	public static function automation_enabled(): bool {
		if ( ! defined( 'KUKA_SHIPPING_AUTOMATION' ) ) {
			$from_env = getenv( 'KUKA_SHIPPING_AUTOMATION' );

			return false !== $from_env && in_array( strtolower( trim( (string) $from_env ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		$value = constant( 'KUKA_SHIPPING_AUTOMATION' );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * The delay before attempt number $attempts + 1, in seconds.
	 *
	 * Fifteen minutes, then half an hour, then one, two, four, eight, twelve and
	 * twenty-four hours; anything beyond the table is a day.
	 */
	public static function delay_for_attempt( int $attempts ): int {
		$ladder = array(
			15 * MINUTE_IN_SECONDS,
			30 * MINUTE_IN_SECONDS,
			HOUR_IN_SECONDS,
			2 * HOUR_IN_SECONDS,
			4 * HOUR_IN_SECONDS,
			8 * HOUR_IN_SECONDS,
			12 * HOUR_IN_SECONDS,
			DAY_IN_SECONDS,
		);

		$index = max( 0, $attempts );

		return $ladder[ $index ] ?? DAY_IN_SECONDS;
	}

	/**
	 * What should happen next for an order in this condition?
	 *
	 * A pure function so the whole policy can be asserted without Action
	 * Scheduler, without a database and without an order.
	 *
	 * @param string $lifecycle Lifecycle from the dictionary.
	 * @param int    $attempts  Queries already made.
	 * @param int    $elapsed   Seconds since the shipment was created.
	 * @return array{action: string, delay: int, reason: string}
	 */
	public static function decide( string $lifecycle, int $attempts, int $elapsed ): array {
		if ( Kuka_Island_Shipping_Status::is_terminal( $lifecycle ) ) {
			return array(
				'action' => 'stop',
				'delay'  => 0,
				'reason' => 'terminal_lifecycle',
			);
		}

		if ( ! Kuka_Island_Shipping_Status::should_keep_polling( $lifecycle )
			&& Kuka_Island_Shipping_Status::LIFECYCLE_UNKNOWN !== $lifecycle ) {
			return array(
				'action' => 'stop',
				'delay'  => 0,
				'reason' => 'not_pollable',
			);
		}

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return array(
				'action' => 'give_up',
				'delay'  => 0,
				'reason' => 'max_attempts_reached',
			);
		}

		if ( $elapsed >= self::MAX_ELAPSED ) {
			return array(
				'action' => 'give_up',
				'delay'  => 0,
				'reason' => 'max_elapsed_reached',
			);
		}

		return array(
			'action' => 'reschedule',
			'delay'  => self::delay_for_attempt( $attempts ),
			'reason' => 'still_moving',
		);
	}

	/**
	 * Is a FUTURE query already booked for this order?
	 *
	 * Only STATUS_PENDING is counted. as_has_scheduled_action() also counts the
	 * action that is currently running, and the follow-up is booked from inside
	 * that action -- so a pending-or-running check would refuse every follow-up
	 * and the chain would stop dead after one attempt.
	 *
	 * @param int $order_id Order id.
	 */
	public static function has_pending_query( int $order_id ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION,
				'args'     => array( 'order_id' => $order_id ),
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);

		return array() !== (array) $pending;
	}

	/**
	 * Book one status query, and say exactly what happened.
	 *
	 * A code rather than a boolean, because 'already_pending' and
	 * 'schedule_failed' both mean "this call created nothing" and treating them
	 * alike is how an order ends up with no query booked and nobody aware.
	 *
	 * @param int $order_id Order id.
	 * @param int $delay    Seconds from now; 0 uses the first rung of the ladder.
	 */
	public static function schedule_query( int $order_id, int $delay = 0 ): string {
		if ( ! self::automation_enabled() ) {
			return self::SCHEDULE_AUTOMATION_DISABLED;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return self::SCHEDULE_SCHEDULER_UNAVAILABLE;
		}

		if ( ! self::acquire_lock( $order_id ) ) {
			return self::SCHEDULE_LOCK_CONTENDED;
		}

		try {
			if ( self::has_pending_query( $order_id ) ) {
				return self::SCHEDULE_ALREADY_PENDING;
			}

			$action_id = (int) as_schedule_single_action(
				time() + max( 1, 0 !== $delay ? $delay : self::delay_for_attempt( 0 ) ),
				self::ACTION,
				array( 'order_id' => $order_id ),
				self::GROUP
			);

			return $action_id > 0 ? self::SCHEDULE_CREATED : self::SCHEDULE_FAILED;
		} finally {
			self::release_lock( $order_id );
		}
	}

	/**
	 * Cancel every pending query for one order.
	 *
	 * @param int $order_id Order id.
	 */
	public static function cancel_queries( int $order_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array( 'order_id' => $order_id ), self::GROUP );
		}
	}

	/**
	 * The scheduled worker.
	 *
	 * @param mixed $order_id Order id as Action Scheduler stored it.
	 * @return string The outcome, returned for the verification suite.
	 */
	public function run( $order_id ): string {
		if ( ! self::automation_enabled() ) {
			// The switch was turned off while this chain was in flight. Nothing
			// is queried and nothing further is booked.
			return 'automation_disabled';
		}

		if ( Kuka_Island_Shipping_Runtime_Gate::is_disabled() ) {
			return 'runtime_disabled';
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order ) {
			return 'order_missing';
		}

		$data = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );

		if ( Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED !== $data['state'] ) {
			// Cancelled, delivered, under manual review, or never shipped. All
			// four are reasons to stop, not to query.
			return 'state_not_pollable';
		}

		$queried = $this->manager->query_status( $order );

		/*
		 * A REFUSAL THAT NEVER REACHED THE CARRIER ENDS THE CHAIN.
		 *
		 * query_status() can refuse before the network entirely: credentials
		 * missing, the runtime gate closed, the carrier not registered, a
		 * configuration value nobody understood, no reference on the order.
		 * Those are not carrier attempts, so they correctly spend nothing from
		 * the attempt budget -- and that is exactly what made this a trap. The
		 * decision below was taken from "ok:false plus an unknown lifecycle",
		 * which is the still-moving branch, so another query was booked; with
		 * the counter standing still MAX_ATTEMPTS never arrived, and the only
		 * thing that ended the chain was MAX_ELAPSED. Roughly fourteen days of
		 * scheduler work, and a history entry and an order note on every turn,
		 * for an order whose carrier was never contacted once.
		 *
		 * The fact is asked for rather than guessed from a list of codes: a
		 * code list would go stale the first time a new refusal was added.
		 *
		 * Nothing here is retried on a timer. The reason is recorded ONCE -- the
		 * order screen shows it and says the manual query is still available --
		 * and an operator who fixes the credentials or the setting presses the
		 * button themselves. That press goes through query_status() exactly as
		 * before; this branch closes no door.
		 */
		if ( false === ( $queried['contacted'] ?? true ) ) {
			$code = (string) ( $queried['code'] ?? 'shipping_local_refusal' );

			$recorded = Kuka_Island_Shipping_Order_Store::record_local_refusal(
				$order,
				'poll',
				$code,
				sprintf(
					/* translators: %s: allow-listed refusal code. */
					__( 'Otomatik durum sorgusu taşıyıcıya hiç gönderilmedi (%s). Deneme harcanmadı ve yeni sorgu planlanmadı; ayar düzeltildikten sonra sorgu elle başlatılabilir.', 'kuka-island-shipping-automation' ),
					$code
				)
			);

			if ( $recorded ) {
				// The first occurrence only. A wall this module meets on every
				// turn is one fact, not one note per turn.
				$order->add_order_note(
					sprintf(
						/* translators: %s: allow-listed refusal code. */
						__( 'Otomatik kargo durum sorgusu yapılandırma nedeniyle gönderilemedi (%s). Deneme harcanmadı, yeni sorgu planlanmadı. Ayar düzeltildikten sonra sorgu elle başlatılabilir.', 'kuka-island-shipping-automation' ),
						$code
					)
				);
			}

			return 'stop:local_refusal:' . $code;
		}

		/*
		 * The attempt number comes back FROM the query, which is the only thing
		 * that knows a request was issued. The fallback re-reads the persisted
		 * counter rather than computing one, so even a caller that returned no
		 * attempt cannot make the budget stand still.
		 */
		$attempts = isset( $queried['attempts'] )
			? (int) $queried['attempts']
			: Kuka_Island_Shipping_Order_Store::query_attempts( $order );

		// A failed READ is safe to try again, within the same ceilings; it is
		// not safe to try again for ever, which is what the attempt costs.
		$decision = self::decide(
			$queried['ok'] ? (string) $queried['lifecycle'] : Kuka_Island_Shipping_Status::LIFECYCLE_UNKNOWN,
			$attempts,
			time() - (int) $data['created_at']
		);

		/*
		 * A TERMINAL CARRIER STATUS IS NOT THE END OF THE WORK.
		 *
		 * The carrier chain is genuinely finished -- there is nothing left to
		 * ask -- but the local half may have been interrupted by a claim
		 * refusal, in which case a safe local retry is already booked and the
		 * outcome must say so. Reporting a bare `stop:terminal_lifecycle` here
		 * is what made the gap invisible.
		 */
		$local_retry = (string) ( $queried['fulfillment_retry'] ?? '' );
		$local_error = (string) ( $queried['fulfillment_error'] ?? '' );
		$local_note  = '';

		if ( '' !== $local_error ) {
			$local_note = '' !== $local_retry
				? ':fulfillment_retry:' . $local_retry
				: ':fulfillment_unfinished:' . $local_error;
		}

		if ( 'reschedule' !== $decision['action'] ) {
			if ( 'give_up' === $decision['action'] ) {
				$exhausted = sprintf(
					/* translators: 1: attempts made, 2: attempt ceiling. */
					__( 'Otomatik kargo durum sorgusu sınırına ulaşıldı (%1$d/%2$d deneme). Yeni sorgu planlanmadı; durum artık manuel sorgulanmalı.', 'kuka-island-shipping-automation' ),
					$attempts,
					self::MAX_ATTEMPTS
				);

				Kuka_Island_Shipping_Order_Store::save_failure( $order, 'poll', 'poll_exhausted', $exhausted );

				// Meta and history are where a suite looks; a note is where an
				// operator looks. Exhaustion is the one poll outcome that needs
				// a person, so it is written to all three.
				$order->add_order_note( $exhausted );
			}

			return $decision['action'] . ':' . $decision['reason'] . $local_note;
		}

		$outcome = self::schedule_query( (int) $order->get_id(), $decision['delay'] );

		if ( self::SCHEDULE_CREATED !== $outcome && self::SCHEDULE_ALREADY_PENDING !== $outcome ) {
			Kuka_Island_Shipping_Order_Store::save_failure(
				$order,
				'poll',
				'poll_not_scheduled',
				__( 'Sonraki kargo durum sorgusu planlanamadı. Durum manuel sorgulanmalı.', 'kuka-island-shipping-automation' )
			);
		}

		return 'rescheduled:' . $outcome . $local_note;
	}

	/**
	 * Does this scheduler outcome PROVE an action exists?
	 *
	 * Only two of them do. `lock_contended`, `schedule_failed` and
	 * `scheduler_unavailable` all mean "this call created nothing", and
	 * treating a non-empty string as evidence is how an order ends up with a
	 * reported retry that was never booked -- reported as
	 * `fulfillment_retry:lock_contended` with zero pending actions.
	 *
	 * @param string $outcome One of the SCHEDULE_* codes.
	 */
	public static function schedule_proves_action( string $outcome ): bool {
		return in_array( $outcome, array( self::SCHEDULE_CREATED, self::SCHEDULE_ALREADY_PENDING ), true );
	}

	/**
	 * Is a FUTURE safe local retry already booked for this order?
	 *
	 * @param int $order_id Order id.
	 */
	public static function has_pending_sync( int $order_id ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::SYNC_ACTION,
				'args'     => array( 'order_id' => $order_id ),
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);

		return array() !== (array) $pending;
	}

	/**
	 * Book one safe local fulfilment retry, and say exactly what happened.
	 *
	 * Deliberately NOT gated on automation_enabled(): that switch exists to
	 * stop carrier traffic, and this worker makes no carrier call. The runtime
	 * gate -- the emergency stop -- still applies, and is checked by the worker
	 * itself so a booked retry cannot outlive the stop.
	 *
	 * @param int $order_id Order id.
	 * @param int $delay    Seconds from now.
	 */
	public static function schedule_sync( int $order_id, int $delay = 0 ): string {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return self::SCHEDULE_SCHEDULER_UNAVAILABLE;
		}

		if ( ! self::acquire_lock( $order_id ) ) {
			return self::SCHEDULE_LOCK_CONTENDED;
		}

		try {
			if ( self::has_pending_sync( $order_id ) ) {
				return self::SCHEDULE_ALREADY_PENDING;
			}

			$action_id = (int) as_schedule_single_action(
				time() + max( 1, 0 !== $delay ? $delay : self::SYNC_FIRST_DELAY ),
				self::SYNC_ACTION,
				array( 'order_id' => $order_id ),
				self::GROUP
			);

			return $action_id > 0 ? self::SCHEDULE_CREATED : self::SCHEDULE_FAILED;
		} finally {
			self::release_lock( $order_id );
		}
	}

	/**
	 * What one `sync_status()` result means for the local bookkeeping.
	 *
	 * Three classes, and the difference matters because only ONE of them may
	 * erase what the operator can see:
	 *
	 *   succeeded  the local half is done. Clear everything.
	 *   retryable  a transient refusal. Count it and book a retry.
	 *   blocked    a person is needed. Record it, note it once, book nothing.
	 *
	 * @param array<string, mixed> $synced A Fulfillment_Writer::sync_status() result.
	 */
	public static function classify_sync( array $synced ): string {
		$reason = (string) ( $synced['reason'] ?? '' );

		if ( '' === $reason && true === ( $synced['ok'] ?? false ) ) {
			return 'succeeded';
		}

		$transient = array_merge(
			Kuka_Island_Shipping_Notification::claim_refusals(),
			Kuka_Island_Shipping_Notification::notify_refusals()
		);

		return in_array( $reason, $transient, true ) ? 'retryable' : 'blocked';
	}

	/**
	 * Apply one sync result to the order, and book a retry when one is owed.
	 *
	 * THE SINGLE PATH. The manager and this worker used to do this twice, with
	 * different rules, and the worker's copy called clear_sync_refusal() for
	 * anything that was not a claim refusal -- so a debt that had become
	 * `claim_other_record`, `own_fulfillment_absent` or a missing reference had
	 * its reason, its attempt count and its schedule error silently erased
	 * while the customer was still not notified. Clearing now happens on ONE
	 * condition: the sync actually succeeded.
	 *
	 * @param WC_Order             $order  Order.
	 * @param array<string, mixed> $synced A Fulfillment_Writer::sync_status() result.
	 * @param int                  $delay  Seconds before the retry; 0 uses the first rung.
	 * @return array{class: string, reason: string, schedule: string, retry: string, attempts: int}
	 */
	public static function settle_sync( WC_Order $order, array $synced, int $delay = 0 ): array {
		$class  = self::classify_sync( $synced );
		$reason = (string) ( $synced['reason'] ?? '' );
		$reason = '' === $reason ? self::SYNC_UNKNOWN_FAILURE : $reason;

		if ( 'succeeded' === $class ) {
			Kuka_Island_Shipping_Order_Store::clear_sync_refusal( $order );

			return self::sync_settlement( 'succeeded', '', '', '', 0 );
		}

		if ( 'blocked' === $class ) {
			if ( Kuka_Island_Shipping_Order_Store::record_sync_block( $order, $reason ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: allow-listed refusal code. */
						__( 'Kargo bildirimi yerel olarak tamamlanamadı (%s) ve otomatik olarak yeniden denenmez. Bildirimin elle kontrol edilmesi gerekiyor.', 'kuka-island-shipping-automation' ),
						$reason
					)
				);
			}

			return self::sync_settlement( 'blocked', $reason, '', '', Kuka_Island_Shipping_Order_Store::sync_attempts( $order ) );
		}

		$attempts = Kuka_Island_Shipping_Order_Store::record_sync_refusal( $order, $reason );

		if ( $attempts >= self::MAX_SYNC_ATTEMPTS ) {
			if ( Kuka_Island_Shipping_Order_Store::record_sync_schedule_error( $order, self::SCHEDULE_EXHAUSTED ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: allow-listed refusal code, 2: attempts made, 3: attempt ceiling. */
						__( 'Kargo bildirimi yerel olarak tamamlanamadı (%1$s, %2$d/%3$d deneme). Yeni deneme planlanmadı; kargo durumu doğru, bildirim manuel kontrol gerektiriyor.', 'kuka-island-shipping-automation' ),
						$reason,
						$attempts,
						self::MAX_SYNC_ATTEMPTS
					)
				);
			}

			return self::sync_settlement( 'retryable', $reason, self::SCHEDULE_EXHAUSTED, '', $attempts );
		}

		$schedule = self::schedule_sync( (int) $order->get_id(), $delay );
		$retry    = '';

		if ( self::schedule_proves_action( $schedule ) ) {
			$retry = $schedule;
		} elseif ( self::has_pending_sync( (int) $order->get_id() ) ) {
			// Unproven, but a pending action is really there. That is a read.
			$retry = self::SCHEDULE_ALREADY_PENDING;
		}

		if ( '' !== $retry ) {
			// A booking exists now, so a stale scheduling error is no longer
			// true and must not sit next to it on the order screen.
			Kuka_Island_Shipping_Order_Store::clear_sync_schedule_error( $order );
		} elseif ( Kuka_Island_Shipping_Order_Store::record_sync_schedule_error( $order, $schedule ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: allow-listed refusal code, 2: scheduler outcome. */
					__( 'Kargo bildirimi yerel olarak tamamlanamadı (%1$s) ve yeniden deneme planlanamadı (%2$s). Bildirimin elle kontrol edilmesi gerekiyor.', 'kuka-island-shipping-automation' ),
					$reason,
					$schedule
				)
			);
		}

		return self::sync_settlement( 'retryable', $reason, $schedule, $retry, $attempts );
	}

	/**
	 * @return array{class: string, reason: string, schedule: string, retry: string, attempts: int}
	 */
	private static function sync_settlement( string $class, string $reason, string $schedule, string $retry, int $attempts ): array {
		return array(
			'class'    => $class,
			'reason'   => $reason,
			'schedule' => $schedule,
			'retry'    => $retry,
			'attempts' => $attempts,
		);
	}

	/**
	 * The safe local retry worker. NO carrier call happens here.
	 *
	 * The status code is the one the carrier already gave and this module
	 * already persisted, so the whole turn is a local write: the fulfilment
	 * record and the customer notification that a claim refusal interrupted.
	 *
	 * @param mixed $order_id Order id as Action Scheduler stored it.
	 * @return string The outcome, returned for the verification suite.
	 */
	public function run_sync( $order_id ): string {
		if ( Kuka_Island_Shipping_Runtime_Gate::is_disabled() ) {
			return 'runtime_disabled';
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order ) {
			return 'order_missing';
		}

		$data      = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
		$reference = (string) $data['reference'];
		$code      = (int) $data['status_code'];

		/*
		 * A missing prerequisite is NOT a success. This branch used to call
		 * clear_sync_refusal() and answer `nothing_to_sync`, which erased the
		 * reason, the attempt count and the schedule error an operator was
		 * meant to see -- for an order whose customer had still not been told.
		 * It is recorded as its own visible reason instead.
		 */
		if ( '' === $reference ) {
			$settled = self::settle_sync( $order, array( 'ok' => false, 'reason' => self::SYNC_REFERENCE_MISSING ) );

			return 'sync_blocked:' . (string) $settled['reason'];
		}

		if ( 0 === $code ) {
			$settled = self::settle_sync( $order, array( 'ok' => false, 'reason' => self::SYNC_STATUS_MISSING ) );

			return 'sync_blocked:' . (string) $settled['reason'];
		}

		$synced = Kuka_Island_Shipping_Fulfillment_Writer::sync_status( $order, $reference, $code );

		/*
		 * The order is re-read because sync_status() may have written meta, and
		 * the settlement writes more. A read that fails does NOT become a
		 * false argument to a typed method: the object in hand is used.
		 */
		$fresh   = wc_get_order( (int) $order->get_id() );
		$target  = $fresh instanceof WC_Order ? $fresh : $order;
		$settled = self::settle_sync( $target, $synced, self::delay_for_attempt( Kuka_Island_Shipping_Order_Store::sync_attempts( $target ) ) );

		if ( 'succeeded' === (string) $settled['class'] ) {
			return 'synced:' . (string) ( $synced['action'] ?? 'none' );
		}

		if ( 'blocked' === (string) $settled['class'] ) {
			return 'sync_blocked:' . (string) $settled['reason'];
		}

		if ( '' !== (string) $settled['retry'] ) {
			return 'sync_retry:' . (string) $settled['reason'] . ':' . (string) $settled['retry'];
		}

		if ( self::SCHEDULE_EXHAUSTED === (string) $settled['schedule'] ) {
			return 'sync_exhausted:' . (string) $settled['reason'];
		}

		return 'sync_not_scheduled:' . (string) $settled['reason'] . ':' . (string) $settled['schedule'];
	}

	private static function acquire_lock( int $order_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::SCHEDULE_LOCK_PREFIX . $order_id ) );

		return '1' === (string) $acquired;
	}

	private static function release_lock( int $order_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::SCHEDULE_LOCK_PREFIX . $order_id ) );
	}
}
