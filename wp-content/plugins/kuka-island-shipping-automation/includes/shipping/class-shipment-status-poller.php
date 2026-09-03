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

	private const SCHEDULE_LOCK_PREFIX = 'kuka_ship_query_';

	/** Hard ceiling on how many times one order is queried. */
	public const MAX_ATTEMPTS = 10;

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

			return $decision['action'] . ':' . $decision['reason'];
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

		return 'rescheduled:' . $outcome;
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
