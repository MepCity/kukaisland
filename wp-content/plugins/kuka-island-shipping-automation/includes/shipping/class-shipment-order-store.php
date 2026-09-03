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
 * OWNERSHIP IS PART OF THE STATE. META_PROVIDER says which carrier this order
 * belongs to, it is pinned by begin_mutation() before the first external write,
 * and it is never overwritten. Everything afterwards -- resume, query, poll,
 * amend, cancel and every reconciliation read -- addresses that carrier and not
 * the shop's current default, which may since have changed.
 *
 * AND SO IS INTENT. Before any of the six external writes, begin_mutation()
 * moves the order into that operation's protected state and records what is
 * about to be attempted, in one save, and then READS IT BACK FROM THE DATABASE
 * before the caller is allowed to send anything. A process that dies with a
 * request in flight therefore leaves behind an order that already knows a write
 * was started, which is what stops the retry from sending it again.
 *
 *   none                 nothing has been attempted
 *     -> order_created       createOrder confirmed
 *     -> reconcile_required  a write returned an uncertain answer
 *   order_created
 *     -> shipment_created    createbarcode confirmed, shipmentId known; reached
 *                            only through Manager::resume_barcode(), which is a
 *                            separate operator action and never calls
 *                            createOrder again
 *     -> reconcile_required
 *   shipment_created
 *     -> delivered           status code 5
 *     -> manual_review       status code 6/7/8 or an unrecognised value
 *     -> cancel_reconciliation_required   a cancellation was ISSUED
 *     -> update_reconciliation_required   an amendment was ISSUED
 *   cancel_reconciliation_required
 *     -> cancelled           a read proved the object is gone
 *     -> stays               anything else, including "still there". NEVER back
 *                            to order_created or shipment_created: that would
 *                            re-open the cancel button on a cancellation that
 *                            may already have worked.
 *   update_reconciliation_required
 *     -> previous state      a field-level read-back matched what was sent
 *     -> manual_review       a field-level read-back did NOT match
 *     -> stays               no read-back is available, or it could not be read
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

	/**
	 * The write whose effect at the carrier is not yet established.
	 *
	 * Written the instant a cancellation or an amendment has been ISSUED, and
	 * cleared only when a read has established what it did. It carries what the
	 * reconciliation needs and cannot re-derive: which object was addressed,
	 * which state the order was in beforehand, and -- for an amendment -- the
	 * exact field values that were sent.
	 */
	public const META_PENDING_MUTATION   = '_kuka_shipping_pending_mutation';

	/**
	 * The three kinds of external mutation, and nothing else.
	 *
	 * Each one has its own protected state, because leaving each one takes
	 * different evidence: a create is resolved by finding or not finding the
	 * record, a cancellation only by the record being GONE, an amendment only
	 * by reading the amended fields back.
	 */
	public const MUTATION_CREATE = 'create';
	public const MUTATION_UPDATE = 'update';
	public const MUTATION_CANCEL = 'cancel';

	public const STATE_NONE                = 'none';
	public const STATE_ORDER_CREATED       = 'order_created';
	public const STATE_SHIPMENT_CREATED    = 'shipment_created';
	public const STATE_RECONCILE_REQUIRED  = 'reconcile_required';

	/**
	 * A cancellation has been ISSUED and its effect is not established.
	 *
	 * Distinct from STATE_RECONCILE_REQUIRED on purpose. That state is left by
	 * finding the record, because for a CREATE, finding it means the create
	 * worked. For a CANCELLATION the opposite is true: finding the record means
	 * the cancellation has not been proved, and the generic reconciliation --
	 * which writes order_created or shipment_created when it finds something --
	 * would put the order back into a state whose cancel button is live. That is
	 * how one cancellation became two.
	 */
	public const STATE_CANCEL_RECONCILE_REQUIRED = 'cancel_reconciliation_required';

	/**
	 * An amendment has been ISSUED and its effect is not established.
	 *
	 * The record existing proves nothing here either: the object was there
	 * before the amendment as well. Only a read-back of the amended FIELDS that
	 * matches what was sent proves anything, and a carrier whose query API does
	 * not return those fields can never provide that proof -- in which case the
	 * order stays here and a person decides.
	 */
	public const STATE_UPDATE_RECONCILE_REQUIRED = 'update_reconciliation_required';

	public const STATE_ABSENT_CONFIRMED    = 'absent_confirmed';
	public const STATE_DELIVERED           = 'delivered';
	public const STATE_MANUAL_REVIEW       = 'manual_review';
	public const STATE_CANCELLED           = 'cancelled';
	public const STATE_BLOCKED             = 'blocked';

	/**
	 * The ONLY states from which createOrder may be sent.
	 *
	 * AN ALLOW-LIST, AND THE HOLE IT CLOSES WAS EXACTLY THE DENY-LIST. This
	 * used to be states_blocking_create(): a list of states that refuse, with
	 * everything else -- including everything a later version might add --
	 * falling through to the create path. STATE_CANCELLED was not on it. So an
	 * order whose shipment had been cancelled AND PROVED cancelled passed the
	 * create door, skipped the createOrder branch (its state was not 'none'),
	 * and dropped straight into run_barcode(): a createbarcode against a
	 * cancelled record, with no createOrder behind it in this life of the
	 * order.
	 *
	 *   none              nothing has been attempted
	 *   blocked           a local refusal, nothing was ever sent
	 *   absent_confirmed  two reads PROVED nothing exists under this reference
	 *
	 * Those three, and nothing else. Not 'cancelled', not 'delivered', not
	 * 'manual_review', not any of the three protected states, and not a value
	 * this version has never heard of.
	 *
	 * @return array<int, string>
	 */
	public static function states_allowing_create_order(): array {
		return array(
			self::STATE_NONE,
			self::STATE_BLOCKED,
			self::STATE_ABSENT_CONFIRMED,
		);
	}

	/**
	 * The ONLY state from which createbarcode may be sent.
	 *
	 * Exactly one: the carrier confirmed an ORDER and no shipment exists for it
	 * yet. Reached either by a createOrder that has just succeeded inside
	 * run_creation(), or deliberately by Manager::resume_barcode().
	 *
	 * @return array<int, string>
	 */
	public static function states_allowing_create_barcode(): array {
		return array(
			self::STATE_ORDER_CREATED,
		);
	}

	/**
	 * States in which a create call is forbidden because something may already
	 * exist at the carrier under this order's reference.
	 *
	 * KEPT FOR THE OPERATOR-FACING DISTINCTION ONLY. The decision is taken by
	 * states_allowing_create_order() above; this list separates "something is
	 * already in progress at the carrier" from "this state is not a create
	 * state at all", so the refusal can say which. It is never the gate.
	 *
	 * @return array<int, string>
	 */
	public static function states_blocking_create(): array {
		return array(
			self::STATE_ORDER_CREATED,
			self::STATE_SHIPMENT_CREATED,
			self::STATE_RECONCILE_REQUIRED,
			self::STATE_CANCEL_RECONCILE_REQUIRED,
			self::STATE_UPDATE_RECONCILE_REQUIRED,
			self::STATE_DELIVERED,
			self::STATE_MANUAL_REVIEW,
		);
	}

	/**
	 * The ONE place this class writes to the database.
	 *
	 * Every method above routes through here, which is what makes "one
	 * transition, one save" a property that can be MEASURED rather than
	 * asserted: the counter below says how many times the order was written,
	 * and a transition that leaves the state and the pending-mutation record in
	 * two separate saves shows up as two.
	 *
	 * That mattered because the two were separated by a window. Cancellation
	 * used to move the state in one save and clear the pending record in
	 * another; a crash in between left an order whose state said "resolved"
	 * and whose meta still described a write in flight -- or the reverse.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function persist( WC_Order $order ): void {
		++self::$saves;

		$order->save_meta_data();
	}

	/** How many times this class has written an order in this process. */
	private static int $saves = 0;

	public static function save_count(): int {
		return self::$saves;
	}

	public static function reset_save_count(): void {
		self::$saves = 0;
	}

	public static function get_state( WC_Order $order ): string {
		$state = (string) $order->get_meta( self::META_STATE, true );

		return '' !== $state ? $state : self::STATE_NONE;
	}

	/**
	 * Which state an order must sit in while a mutation of this kind is in
	 * flight, or '' for a kind this version does not know.
	 *
	 * @param string $kind One of the MUTATION_* constants.
	 */
	public static function protected_state_for( string $kind ): string {
		return match ( $kind ) {
			self::MUTATION_CREATE => self::STATE_RECONCILE_REQUIRED,
			self::MUTATION_UPDATE => self::STATE_UPDATE_RECONCILE_REQUIRED,
			self::MUTATION_CANCEL => self::STATE_CANCEL_RECONCILE_REQUIRED,
			default               => '',
		};
	}

	/**
	 * WRITE THE INTENT DOWN, AND PROVE IT IS ON DISK, BEFORE ANYTHING IS SENT.
	 *
	 * THE HOLE THIS CLOSES. The provider and the reference were pinned before
	 * the first external write, and that was read as "the intent is durable".
	 * It was not. If the process died between the request leaving and the answer
	 * arriving -- a fatal, a timeout kill, a deploy, a lost database connection
	 * -- what survived was an order whose state still said 'none' and whose
	 * only trace of the attempt was a provider key. The next press read 'none',
	 * passed states_blocking_create(), and sent the create again. One parcel,
	 * two bookings, and the second one invisible to this shop.
	 *
	 * So the order is moved INTO its operation's protected state, and the full
	 * description of what is about to be attempted is written with it, in ONE
	 * save, BEFORE the caller is allowed to open a socket:
	 *
	 *   mutation_id     a value unique to this attempt, so a later reading can
	 *                   tell THIS attempt's record from a previous one's
	 *   kind            create / update / cancel -- which evidence closes it
	 *   operation       the exact operation name, e.g. 'cancel_shipment'
	 *   target          'order' or 'shipment' -- WHICH object to read back
	 *   previous_state  where to return to if it turns out nothing was sent
	 *   provider        whose parcel this is, so the read-back goes to the
	 *                   carrier that was actually addressed
	 *   reference       the identifier the request carries
	 *   expected        for an amendment, the exact canonical field values sent
	 *   created_at      when the attempt began
	 *
	 * AND THEN IT IS READ BACK FROM THE DATABASE. Writing is not persisting.
	 * update_meta_data() populates an object; save_meta_data() can still fail,
	 * silently, on a lost connection or a filter that intercepts the query, and
	 * the object in memory looks exactly the same either way. So a FRESH order
	 * is loaded -- with every cache dropped first, see fresh_copy() -- and every
	 * critical field is compared byte-for-byte against what was meant. Only if
	 * that comparison passes may the caller write to the carrier.
	 *
	 * A FAILURE HERE COSTS NOTHING AT THE CARRIER. No request has gone out, so
	 * the honest outcome is to refuse the operation entirely; guarded_write()
	 * does exactly that and the carrier call count stays at zero.
	 *
	 * AND IT IS NOT ROLLED BACK. A verification failure means this process
	 * cannot trust what it writes, so writing again -- to undo the state change
	 * -- would be trusting the same mechanism that just failed, and the failure
	 * mode it could produce is the dangerous one: an order back in a state
	 * whose write button is live while a record describing a write in flight
	 * survives. The order is therefore LEFT in the protected state, which is
	 * the restrictive side of the mistake, and a read-only reconciliation is
	 * what moves it afterwards. Manager::reconcile_cancellation() is written to
	 * cope with the intent record being the part that did not survive.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $spec  kind, operation, target, provider, reference, expected.
	 * @return array{ok: bool, code: string, message: string, detail: string, intent: array<string, mixed>}
	 */
	public static function begin_mutation( WC_Order $order, array $spec ): array {
		$kind      = (string) ( $spec['kind'] ?? '' );
		$operation = trim( (string) ( $spec['operation'] ?? '' ) );
		$target    = (string) ( $spec['target'] ?? '' );
		$provider  = trim( (string) ( $spec['provider'] ?? '' ) );
		$reference = (string) ( $spec['reference'] ?? '' );
		$expected  = self::canonical_expected( (array) ( $spec['expected'] ?? array() ) );
		$protected = self::protected_state_for( $kind );

		if ( '' === $protected
			|| '' === $operation
			|| '' === $provider
			|| ! in_array( $target, array( 'order', 'shipment' ), true )
			|| ! Kuka_Island_Shipping_Reference::is_valid( $reference )
			|| ( self::MUTATION_UPDATE === $kind && array() === $expected ) ) {

			return self::intent_refused(
				'mutation_intent_invalid',
				__( 'Kargo işlemi başlatılmadı: yapılacak işlemin kalıcı kaydı eksiksiz kurulamadı. Taşıyıcıya hiçbir istek gönderilmedi.', 'kuka-island-shipping-automation' ),
				'kind:' . $kind . '|operation:' . $operation . '|target:' . $target
			);
		}

		$stored_provider = self::provider( $order );

		if ( '' !== $stored_provider && $stored_provider !== $provider ) {
			// Belongs to somebody else. The caller was already refused with
			// shipment_provider_mismatch before reaching here; this is the
			// second lock on the same door.
			return self::intent_refused(
				'mutation_intent_provider_conflict',
				__( 'Kargo işlemi başlatılmadı: bu sipariş başka bir taşıyıcıya kayıtlı. Taşıyıcıya hiçbir istek gönderilmedi.', 'kuka-island-shipping-automation' ),
				'stored:' . $stored_provider
			);
		}

		$intent = array(
			'mutation_id'    => self::mint_mutation_id(),
			'kind'           => $kind,
			'operation'      => $operation,
			'target'         => $target,
			'previous_state' => self::get_state( $order ),
			'provider'       => $provider,
			'reference'      => $reference,
			'expected'       => $expected,
			'created_at'     => time(),
		);

		$existing_reference = (string) $order->get_meta( self::META_REFERENCE, true );

		if ( ! Kuka_Island_Shipping_Reference::is_valid( $existing_reference ) ) {
			$order->update_meta_data( self::META_REFERENCE, $reference );
			self::append_reference_history( $order, $reference );
		}

		if ( '' === $stored_provider ) {
			$order->update_meta_data( self::META_PROVIDER, $provider );
		}

		$order->update_meta_data( self::META_STATE, $protected );
		$order->update_meta_data( self::META_LAST_OPERATION, $operation );
		$order->update_meta_data( self::META_PENDING_MUTATION, $intent );

		self::add_history_entry(
			$order,
			$protected,
			sprintf(
				/* translators: 1: operation name, 2: object addressed, 3: attempt identifier. */
				__( 'Taşıyıcı isteği başlatılıyor (%1$s / %2$s / %3$s). Kayıt gönderim ÖNCESİNDE yazıldı; süreç burada kesilse bile yeni yazma açılmaz, yalnız salt-okunur mutabakat yapılır.', 'kuka-island-shipping-automation' ),
				$operation,
				$target,
				(string) $intent['mutation_id']
			)
		);

		self::persist( $order );

		$fresh = self::fresh_copy( (int) $order->get_id() );

		if ( ! $fresh instanceof WC_Order ) {
			return self::intent_refused(
				'mutation_intent_unreadable',
				__( 'Kargo işlemi başlatılmadı: işlem kaydı veritabanından geri okunamadı. Taşıyıcıya hiçbir istek gönderilmedi.', 'kuka-island-shipping-automation' ),
				'order:' . (string) $order->get_id()
			);
		}

		$verified = self::verify_mutation_intent( $fresh, $intent );

		if ( ! $verified['ok'] ) {
			return self::intent_refused(
				'mutation_intent_unverified',
				__( 'Kargo işlemi başlatılmadı: işlem kaydı diske yazıldığı gibi geri okunamadı. Taşıyıcıya hiçbir istek gönderilmedi.', 'kuka-island-shipping-automation' ),
				'mismatched:' . implode( ',', $verified['mismatched'] )
			);
		}

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
			'detail'  => 'mutation:' . (string) $intent['mutation_id'] . '|state:' . $protected,
			'intent'  => $intent,
		);
	}

	/**
	 * @return array{ok: bool, code: string, message: string, detail: string, intent: array<string, mixed>}
	 */
	private static function intent_refused( string $code, string $message, string $detail ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'detail'  => $detail,
			'intent'  => array(),
		);
	}

	/**
	 * Is what the DATABASE holds byte-for-byte what was meant?
	 *
	 * Every field is compared with !== against the value that was written, and
	 * a field the stored record does not have at all is a mismatch rather than
	 * a pass. Strict comparison matters after a round trip through meta: an
	 * integer that came back as the string '1' is not the value that was
	 * written, and treating it as equal would hide exactly the kind of
	 * persistence fault this check exists to catch.
	 *
	 * @param WC_Order             $fresh  Order re-read from the database.
	 * @param array<string, mixed> $intent What was meant to be stored.
	 * @return array{ok: bool, mismatched: array<int, string>}
	 */
	public static function verify_mutation_intent( WC_Order $fresh, array $intent ): array {
		$mismatched = array();
		$expected   = (array) ( $intent['expected'] ?? array() );

		if ( self::get_state( $fresh ) !== self::protected_state_for( (string) ( $intent['kind'] ?? '' ) ) ) {
			$mismatched[] = 'state';
		}

		if ( self::provider( $fresh ) !== (string) ( $intent['provider'] ?? '' ) ) {
			$mismatched[] = 'provider';
		}

		if ( (string) $fresh->get_meta( self::META_REFERENCE, true ) !== (string) ( $intent['reference'] ?? '' ) ) {
			$mismatched[] = 'reference';
		}

		$stored = self::pending_mutation( $fresh );

		foreach ( array( 'mutation_id', 'kind', 'operation', 'target', 'previous_state', 'provider', 'reference', 'created_at' ) as $field ) {
			if ( ! array_key_exists( $field, $stored ) || $stored[ $field ] !== ( $intent[ $field ] ?? null ) ) {
				$mismatched[] = 'intent.' . $field;
			}
		}

		$stored_expected = is_array( $stored['expected'] ?? null ) ? (array) $stored['expected'] : array();

		if ( count( $stored_expected ) !== count( $expected ) ) {
			$mismatched[] = 'intent.expected.count';
		}

		foreach ( $expected as $field => $value ) {
			if ( ! array_key_exists( $field, $stored_expected ) || $stored_expected[ $field ] !== $value ) {
				$mismatched[] = 'intent.expected.' . (string) $field;
			}
		}

		return array(
			'ok'         => array() === $mismatched,
			'mismatched' => $mismatched,
		);
	}

	/**
	 * A copy of the order loaded from the DATABASE, not from any cache.
	 *
	 * The verification is worthless if it reads back the same object, or a
	 * cached copy populated from it: both would agree with the write even when
	 * the write never landed. So every cache that could answer is dropped
	 * first, and the meta read is forced.
	 *
	 * Still no $wpdb: wp_cache_delete() and WooCommerce's own order cache are
	 * the supported ways to say "forget what you know about this order", and
	 * the class stays storage-agnostic, which is what makes it work on HPOS and
	 * on legacy post meta alike.
	 *
	 * @param int $order_id Order id.
	 */
	private static function fresh_copy( int $order_id ): ?WC_Order {
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				$cache = wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class );

				if ( is_object( $cache ) && method_exists( $cache, 'remove' ) ) {
					$cache->remove( $order_id );
				}
			} catch ( Throwable $unavailable ) {
				// An installation without that service is not a reason to skip
				// the verification: the forced meta read below is what the
				// comparison actually depends on.
				unset( $unavailable );
			}
		}

		wp_cache_delete( $order_id, 'orders' );
		wp_cache_delete( $order_id, 'order-items' );
		wp_cache_delete( $order_id, 'posts' );
		wp_cache_delete( $order_id, 'post_meta' );

		$fresh = wc_get_order( $order_id );

		if ( ! $fresh instanceof WC_Order ) {
			return null;
		}

		// Forced: without the argument this returns whatever the meta cache
		// already holds, which may be the values that failed to save.
		$fresh->read_meta_data( true );

		return $fresh;
	}

	/**
	 * One value per attempt, so two attempts can never be confused.
	 *
	 * A timestamp alone is not enough: two attempts inside the same second
	 * would produce the same record, and the verification would then accept a
	 * PREVIOUS attempt's intent as proof that this one was stored.
	 */
	private static function mint_mutation_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}

		return sprintf( '%d-%s', time(), bin2hex( random_bytes( 8 ) ) );
	}

	/**
	 * The stored form of the amendment's expected values.
	 *
	 * Strings, and key-sorted. Both are about making "byte-for-byte" mean
	 * something: a value that goes in as an integer and comes back as a string
	 * would fail a strict comparison for a reason that has nothing to do with
	 * persistence, and an unsorted array serialises differently from one save
	 * to the next.
	 *
	 * @param array<string, mixed> $expected Raw field values.
	 * @return array<string, string>
	 */
	private static function canonical_expected( array $expected ): array {
		$clean = array();

		foreach ( $expected as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$clean[ (string) $key ] = (string) $value;
			}
		}

		ksort( $clean );

		return $clean;
	}

	/**
	 * Close a mutation intent and move the order, in ONE save.
	 *
	 * The state, the last operation, the cleared pending record, any extra meta
	 * the outcome carries and the history entry all go down together. There is
	 * no instant at which an order is out of its protected state while the
	 * pending record still describes a write in flight.
	 *
	 * @param WC_Order             $order     Order.
	 * @param string               $state     State to move to.
	 * @param string               $operation Operation that produced the outcome, '' to keep.
	 * @param string               $message   Operator-facing sentence.
	 * @param array<string, mixed> $meta      Extra meta keys to write in the same save.
	 */
	public static function settle_mutation( WC_Order $order, string $state, string $operation, string $message, array $meta = array() ): void {
		$order->update_meta_data( self::META_STATE, $state );
		$order->update_meta_data( self::META_PENDING_MUTATION, array() );

		if ( '' !== $operation ) {
			$order->update_meta_data( self::META_LAST_OPERATION, $operation );
		}

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( (string) $key, $value );
		}

		self::add_history_entry( $order, $state, $message );
		self::persist( $order );
	}

	/**
	 * The carrier this order BELONGS to, or '' when nothing has been pinned.
	 *
	 * Ownership, not preference. Once a carrier has been addressed under this
	 * order's reference, every later query, amendment, cancellation and
	 * reconciliation has to go to THAT carrier -- the shop's current default is
	 * irrelevant and following it would send a cancellation to a courier that
	 * never had the parcel.
	 */
	public static function provider( WC_Order $order ): string {
		return trim( (string) $order->get_meta( self::META_PROVIDER, true ) );
	}

	/**
	 * Is there any sign that SOME carrier was addressed under this reference?
	 *
	 * The question exists for records written before ownership was pinned. If
	 * this is true and provider() is empty, nobody knows which courier holds
	 * the parcel, and guessing is how a cancellation reaches the wrong one. The
	 * caller must fail closed rather than fall back to a default.
	 *
	 * A bare reference is NOT evidence: reference() mints one locally, before
	 * anything is sent. The evidence is a state that only a carrier answer can
	 * produce, or a value that only a carrier can supply.
	 */
	public static function has_carrier_evidence( WC_Order $order ): bool {
		$evidence_states = array(
			self::STATE_ORDER_CREATED,
			self::STATE_SHIPMENT_CREATED,
			self::STATE_RECONCILE_REQUIRED,
			/*
			 * THE TWO PROTECTED STATES WERE MISSING, AND THEY ARE THE STRONGEST
			 * EVIDENCE THERE IS. An order only reaches them because
			 * begin_mutation() wrote an intent immediately before a request
			 * went out -- a cancellation or an amendment addressed to some
			 * carrier under this reference. Without them, a record in one of
			 * those states with no provider fell through to the shop's current
			 * default, which is how a cancellation reaches a courier that never
			 * had the parcel.
			 */
			self::STATE_CANCEL_RECONCILE_REQUIRED,
			self::STATE_UPDATE_RECONCILE_REQUIRED,
			self::STATE_ABSENT_CONFIRMED,
			self::STATE_DELIVERED,
			self::STATE_MANUAL_REVIEW,
			self::STATE_CANCELLED,
		);

		if ( in_array( self::get_state( $order ), $evidence_states, true ) ) {
			return true;
		}

		/*
		 * And the intent record itself, whatever the state says. It exists only
		 * between begin_mutation() and the outcome that settles it, so its mere
		 * presence means a request was started against a carrier -- even on a
		 * record whose state was later overwritten by something this module did
		 * not write.
		 */
		if ( array() !== self::pending_mutation( $order ) ) {
			return true;
		}

		if ( '' !== trim( (string) $order->get_meta( self::META_SHIPMENT_ID, true ) )
			|| '' !== trim( (string) $order->get_meta( self::META_ORDER_INVOICE_ID, true ) )
			|| 0 !== (int) $order->get_meta( self::META_STATUS_CODE, true ) ) {
			return true;
		}

		return array() !== array_filter( array_map( 'strval', (array) ( $order->get_meta( self::META_BARCODES, true ) ?: array() ) ) );
	}

	/**
	 * The reference this order will use, WITHOUT writing anything.
	 *
	 * A candidate, so the request can be built and validated before the order
	 * is committed to anything. An order whose address cannot be mapped must
	 * come out of the attempt exactly as it went in -- no reference, no owner --
	 * so that a different carrier can be chosen afterwards.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function prepare_reference( WC_Order $order ): string {
		$existing = (string) $order->get_meta( self::META_REFERENCE, true );

		if ( Kuka_Island_Shipping_Reference::is_valid( $existing ) ) {
			return $existing;
		}

		return Kuka_Island_Shipping_Reference::build( (int) $order->get_id() );
	}

	/** How many carrier status queries this order has actually spent. */
	public static function query_attempts( WC_Order $order ): int {
		return (int) $order->get_meta( self::META_QUERY_ATTEMPTS, true );
	}

	/**
	 * Spend one unit of this order's status-query budget.
	 *
	 * THE ONLY PLACE THE COUNTER MOVES, and it moves for every query that was
	 * actually issued -- successful, transient or permanent alike. It used to
	 * move inside save_status(), which is reached only by a SUCCESSFUL reading:
	 * a failed query therefore spent nothing, the poller recomputed the same
	 * "attempts + 1" from the same stale value on every turn, and MAX_ATTEMPTS
	 * never arrived. A chain of failures rescheduled itself for ever.
	 *
	 * A refusal that made no call is not an attempt and must not reach here.
	 *
	 * @param WC_Order $order Order.
	 * @return int The attempt this query is, counting from one.
	 */
	public static function record_query_attempt( WC_Order $order ): int {
		$attempts = self::query_attempts( $order ) + 1;

		$order->update_meta_data( self::META_QUERY_ATTEMPTS, $attempts );
		$order->update_meta_data( self::META_LAST_QUERIED_AT, time() );
		self::persist( $order );

		return $attempts;
	}

	/**
	 * Everything the admin panel and the verification suite need, in one read.
	 *
	 * @return array{provider: string, state: string, reference: string, shipment_id: string, barcodes: array<int, string>, tracking_url: string, order_invoice_id: string, status_code: int, status_lifecycle: string, last_error: string, last_operation: string, created_at: int, last_queried_at: int, query_attempts: int, pending_mutation: array<string, mixed>, history: array<int, array<string, mixed>>, reference_history: array<int, string>}
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
			'pending_mutation'  => self::pending_mutation( $order ),
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
		self::persist( $order );

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
		self::persist( $order );

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
		self::persist( $order );
	}

	/**
	 * Record a confirmed createOrder.
	 *
	 * @param WC_Order $order            Order.
	 * @param string   $provider_key     Carrier key, e.g. 'dhl'.
	 * @param string   $order_invoice_id Carrier's own order receipt id, '' when absent.
	 */
	public static function save_order_created( WC_Order $order, string $provider_key, string $order_invoice_id ): void {
		// Ownership is pinned by begin_mutation() BEFORE the request goes out,
		// so this is normally a no-op. It writes only when the field is empty,
		// and it never re-points an order that already has an owner: this
		// method is also reached from a reconciliation, and a reconciliation
		// must not be able to change whose parcel this is.
		if ( '' === self::provider( $order ) && '' !== trim( $provider_key ) ) {
			$order->update_meta_data( self::META_PROVIDER, trim( $provider_key ) );
		}

		$order->update_meta_data( self::META_STATE, self::STATE_ORDER_CREATED );
		$order->update_meta_data( self::META_LAST_OPERATION, 'create_order' );
		$order->update_meta_data( self::META_LAST_ERROR, '' );

		// The intent this confirms is closed HERE, in the same save as the
		// state it moves to. Two saves would leave a window in which the order
		// says 'order_created' and the meta still describes a create in flight.
		$order->update_meta_data( self::META_PENDING_MUTATION, array() );

		if ( '' !== $order_invoice_id ) {
			$order->update_meta_data( self::META_ORDER_INVOICE_ID, $order_invoice_id );
		}

		if ( 0 === (int) $order->get_meta( self::META_CREATED_AT, true ) ) {
			$order->update_meta_data( self::META_CREATED_AT, time() );
		}

		self::add_history_entry( $order, self::STATE_ORDER_CREATED, __( 'Taşıyıcıda sipariş oluşturuldu.', 'kuka-island-shipping-automation' ) );
		self::persist( $order );
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

		// Same save as the state, for the same reason as save_order_created().
		$order->update_meta_data( self::META_PENDING_MUTATION, array() );

		/*
		 * AND THE START TIME, WHEN NOTHING HAS SET IT YET. This method is
		 * reached two ways: after a createbarcode this module sent, in which
		 * case save_order_created() has already stamped META_CREATED_AT -- and
		 * directly from Manager::reconcile(), when a read finds a SHIPMENT
		 * under this reference with no confirmed createOrder behind it in this
		 * life of the order. On that second path created_at stayed 0, and the
		 * poller computes elapsed as time() - created_at: zero means every
		 * second since 1970, so the very first turn exceeded MAX_ELAPSED and
		 * the chain gave up before reading anything. A parcel existed at the
		 * carrier and nothing followed it.
		 *
		 * Written in THIS save, not a second one, and only when empty: an
		 * existing value is the moment the shipment actually began and must not
		 * be moved to the moment it happened to be adopted.
		 */
		if ( 0 === (int) $order->get_meta( self::META_CREATED_AT, true ) ) {
			$order->update_meta_data( self::META_CREATED_AT, time() );
		}

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
		self::persist( $order );
	}

	/**
	 * The write whose effect is not yet established, or an empty array.
	 *
	 * @return array<string, mixed>
	 */
	public static function pending_mutation( WC_Order $order ): array {
		$pending = $order->get_meta( self::META_PENDING_MUTATION, true );

		return is_array( $pending ) ? $pending : array();
	}

	/**
	 * Record a status reading.
	 *
	 * The lifecycle is derived by the dictionary, never by the caller, so an
	 * unrecognised code cannot be written as if it were in progress.
	 *
	 * It does NOT touch the attempt counter or the query timestamp: those belong
	 * to record_query_attempt(), which every issued query passes through whether
	 * it succeeded or not. Counting here would count successes only.
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
		$order->update_meta_data( self::META_LAST_OPERATION, 'get_shipment_status' );

		if ( '' !== $tracking_url ) {
			$order->update_meta_data( self::META_TRACKING_URL, $tracking_url );
		}

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

		self::persist( $order );

		return $lifecycle;
	}

	/**
	 * Record an operation that failed with a code, without changing the state.
	 *
	 * Used for permanent and transient failures alike: neither of them can have
	 * created anything, so neither of them may move the order out of the state
	 * it was in.
	 *
	 * It does not touch the attempt counter either. A failed status query does
	 * spend an attempt, but it spends it through record_query_attempt() at the
	 * call site, so the budget has exactly one owner.
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
		self::persist( $order );
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
		// Re-asserted rather than set: begin_mutation() moved the order here
		// BEFORE the request left, which is the whole point -- a process that
		// died mid-flight never reached this method and the state is correct
		// anyway. Writing it again costs nothing and keeps the method honest if
		// it is ever reached from somewhere else.
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

		self::persist( $order );
	}

	/**
	 * Record a local refusal ONCE, however many times it recurs.
	 *
	 * The poller can meet the same wall on every turn -- credentials still
	 * missing, gate still closed -- and each meeting used to append a history
	 * entry and an order note. Four runner turns produced eight of them, which
	 * is noise an operator has to read past to find the one fact that matters.
	 *
	 * So this is a no-op when the order already carries this exact code: the
	 * order screen shows the same thing either way, and the audit trail keeps
	 * the first occurrence, which is the one with information in it.
	 *
	 * @param WC_Order $order           Order.
	 * @param string   $operation       Logical operation name.
	 * @param string   $safe_error_code Allow-listed code.
	 * @param string   $message         Operator-facing sentence.
	 * @return bool True when this was the first occurrence and was recorded.
	 */
	public static function record_local_refusal( WC_Order $order, string $operation, string $safe_error_code, string $message ): bool {
		if ( (string) $order->get_meta( self::META_LAST_ERROR, true ) === $safe_error_code ) {
			return false;
		}

		self::save_failure( $order, $operation, $safe_error_code, $message );

		return true;
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
	 * @return bool True when this was new information and was recorded.
	 */
	public static function save_blocked( WC_Order $order, string $safe_error_code, string $message ): bool {
		$previous_error = (string) $order->get_meta( self::META_LAST_ERROR, true );
		$previous_state = self::get_state( $order );

		$order->update_meta_data( self::META_LAST_ERROR, $safe_error_code );

		// A block never overwrites a state that records something existing at
		// the carrier: the shipment is still there, the request was simply not
		// made.
		if ( in_array( $previous_state, array( self::STATE_NONE, self::STATE_BLOCKED, self::STATE_ABSENT_CONFIRMED ), true ) ) {
			$order->update_meta_data( self::META_STATE, self::STATE_BLOCKED );
		}

		/*
		 * THE SAME UNCHANGED FACT IS RECORDED ONCE.
		 *
		 * A refusal taken before the network can recur on every turn of an
		 * automated chain: credentials still missing, gate still closed. Each
		 * recurrence used to append an audit entry (and the caller an order
		 * note), so four poller turns produced four of each -- noise an
		 * operator has to read past to reach the one entry with information in
		 * it. When the code AND the resulting state are both exactly what they
		 * already were, nothing happened that anybody needs telling twice.
		 *
		 * The meta is still written every time: it is a current value, not a
		 * log, and writing it is how a later reading knows the wall is still
		 * there.
		 */
		$is_repeat = $previous_error === $safe_error_code && self::get_state( $order ) === $previous_state;

		if ( ! $is_repeat ) {
			self::add_history_entry( $order, self::get_state( $order ), $message );
		}

		self::persist( $order );

		return ! $is_repeat;
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
