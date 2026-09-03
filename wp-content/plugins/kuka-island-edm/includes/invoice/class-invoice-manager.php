<?php
/**
 * Invoice Integration Manager.
 *
 * Coordinates provider authentication, user routing (e-Fatura vs e-Arşiv),
 * UBL XML generation, order persistence, network reconciliation and duplicate prevention.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

class Kuka_Island_Core_Invoice_Manager {
	private Kuka_Island_Core_Invoice_Config $config;
	private Kuka_Island_Core_Invoice_Provider_Interface $provider;
	private Kuka_Island_Core_Invoice_Order_Mapper $mapper;

	public function __construct( ?Kuka_Island_Core_Invoice_Config $config = null, ?Kuka_Island_Core_Invoice_Provider_Interface $provider = null ) {
		$this->config   = $config ?? new Kuka_Island_Core_Invoice_Config();
		$this->provider = $provider ?? new Kuka_Island_Core_EDM_Provider( $this->config );
		$this->mapper   = new Kuka_Island_Core_Invoice_Order_Mapper( $this->config );
	}

	public function get_config(): Kuka_Island_Core_Invoice_Config {
		return $this->config;
	}

	public function get_provider(): Kuka_Island_Core_Invoice_Provider_Interface {
		return $this->provider;
	}

	public function get_mapper(): Kuka_Island_Core_Invoice_Order_Mapper {
		return $this->mapper;
	}

	/**
	 * Statuses that only ever exist after a transmission was attempted.
	 *
	 * needs_manual_review and failed are deliberately absent: a pre-transmission
	 * validation error also lands in them, and those orders keep their ordinary
	 * retry behaviour. What separates the two cases is the persistent evidence
	 * listed in transmission_evidence(), not the status alone.
	 */
	private const POST_TRANSMISSION_STATUSES = array(
		Kuka_Island_Core_Invoice_Status::STATUS_SENDING,
		Kuka_Island_Core_Invoice_Status::STATUS_SENT,
		Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL,
		Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN,
		Kuka_Island_Core_Invoice_Status::STATUS_RECONCILIATION_REQUIRED,
	);

	/** Reconciliation could not be performed at all. */
	public const ERROR_RECONCILE_UNAVAILABLE = 'reconciliation_required';
	/** EDM answered, but with nothing the closed status list recognises. */
	public const ERROR_RECONCILE_INDEFINITE = 'post_transmission_status_indefinite';
	/** A transmission was attempted and there is no UUID left to ask about. */
	public const ERROR_RECONCILE_NO_UUID = 'post_transmission_uuid_missing';

	/** A physical order whose goods have not all left yet. */
	/** The EDM plugin was deactivated while a worker was already running. */
	public const ERROR_RUNTIME_DISABLED = 'edm_runtime_disabled';

	public const ERROR_SHIPMENT_INCOMPLETE = 'shipment_not_complete';
	/** The internet-sales block could not be produced from observed facts. */
	public const ERROR_INTERNET_SALES_INCOMPLETE = 'internet_sales_details_incomplete';

	/**
	 * Is this order's shipment far enough along to invoice the whole order?
	 *
	 * The single gate every entry point consults: the queue before it enqueues,
	 * the worker before it sends, the order screen before it offers a button,
	 * and process_order() itself so a direct call cannot go round them.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array{ok: bool, state: string, facts: array<string, mixed>}
	 */
	public static function shipment_gate( WC_Order $order ): array {
		$facts = Kuka_Island_Core_Internet_Sales_Details::read_shipment_facts( $order );
		$state = (string) $facts['shipment_state'];

		return array(
			'ok'    => Kuka_Island_Core_Internet_Sales_Details::is_invoiceable_shipment( $state ),
			'state' => $state,
			'facts' => $facts,
		);
	}

	/**
	 * Persistent evidence that this order has already been through SendInvoice.
	 *
	 * Read from what is durably on the order, so it survives a crash, a new
	 * process, a cron run and an operator pressing a button twice. Any single
	 * fact is enough: the question is not "did it arrive?" but "might it have?".
	 *
	 * The document number is deliberately NOT evidence. Numbering resolves an
	 * EDM-assigned number BEFORE transmission, so a number with no UUID and no
	 * attempt behind it belongs to an order that has never been sent.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<int, string> Evidence names, empty when nothing was sent.
	 */
	public static function transmission_evidence( WC_Order $order ): array {
		$evidence = array();

		if ( '' !== trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true ) ) ) {
			$evidence[] = 'uuid';
		}

		if ( in_array( Kuka_Island_Core_Invoice_Order_Store::get_status( $order ), self::POST_TRANSMISSION_STATUSES, true ) ) {
			$evidence[] = 'status';
		}

		if ( (int) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_SENT_AT, true ) > 0 ) {
			$evidence[] = 'sent_at';
		}

		// Advanced only by save_invoice_sent(), save_invoice_error() and
		// save_send_uncertain(), each of which runs after the SendInvoice call.
		// save_blocked() -- the pre-transmission fail-closed path -- does not
		// touch it.
		if ( (int) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_ATTEMPTS, true ) > 0 ) {
			$evidence[] = 'send_attempts';
		}

		return $evidence;
	}

	/**
	 * Is this a reconciliation answer definite enough to act on?
	 *
	 * completed, rejected and cancelled are settled. sent and pending_approval
	 * are EDM's own official "still with us". failed is PACKAGE - FAIL or
	 * SEND - FAILED, which is a real answer about a real document.
	 *
	 * Everything else -- above all the unrecognised literal that maps to
	 * needs_manual_review -- is not an answer, and is never treated as one.
	 *
	 * @param string $lifecycle Lifecycle status the reconciliation produced.
	 */
	public static function is_definite_reconcile_status( string $lifecycle ): bool {
		return Kuka_Island_Core_Invoice_Status::is_terminal( $lifecycle )
			|| in_array(
				$lifecycle,
				array(
					Kuka_Island_Core_Invoice_Status::STATUS_SENT,
					Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL,
					Kuka_Island_Core_Invoice_Status::STATUS_FAILED,
				),
				true
			);
	}

	/**
	 * May a NEVER-TRANSMITTED order start a transmission from this status?
	 *
	 * Two separate questions live here, and they used to be conflated into one.
	 *
	 * can_retry() answers "may an operator ask for this again?" and drives the
	 * order screen's re-send button. STATUS_QUEUED must NOT be in it: an order
	 * the queue has already claimed is not waiting for a human to press
	 * anything, and offering the button would invite a second chain.
	 *
	 * This predicate answers "is the worker allowed to pick this up?", which
	 * STATUS_QUEUED is precisely the status for. maybe_enqueue_order() writes it
	 * and then schedules ACTION_PROCESS_INVOICE, so the worker's own unforced
	 * call arrived at a gate built only from can_retry() and was refused with
	 * invalid_invoice_status_transition -- meaning the automatic path could not
	 * send anything at all.
	 *
	 * This says nothing about a document that may already exist. The
	 * transmission_evidence() guard is consulted first and outranks this
	 * entirely; nothing here can be reached by an order that has been sent.
	 *
	 * @param string $status Current invoice status.
	 */
	public static function may_start_transmission( string $status ): bool {
		return Kuka_Island_Core_Invoice_Status::can_retry( $status )
			|| Kuka_Island_Core_Invoice_Status::STATUS_QUEUED === $status;
	}

	/**
	 * What the shop is told when a transmitted document cannot be resolved.
	 */
	public static function manual_query_message(): string {
		return __( 'Fatura EDM sistemine gönderilmiş olabilir ve durumu doğrulanamadı. Mükerrer gönderim engellendi; lütfen EDM üzerinden fatura durumunu manuel olarak sorgulayın.', 'kuka-island-edm' );
	}

	/**
	 * Process invoice generation and transmission for a paid order.
	 *
	 * Rules:
	 * - Terminal statuses (completed) can NEVER be re-sent, even with $force.
	 * - In-flight statuses (sent, pending_approval, sending, send_uncertain) CANNOT re-send; only reconcile via GetInvoiceStatus.
	 * - Database advisory lock guards concurrency.
	 * - UUID, invoice number, and 'sending' status are persisted in a single atomic store operation before SendInvoice.
	 * - Ambiguous network errors during SendInvoice transition to 'send_uncertain' (never needs_manual_review).
	 * - A document left in flight (sent, pending_approval, send_uncertain) gets exactly one
	 *   GetInvoiceStatus query booked on the poller's own Action Scheduler action. The poller
	 *   cannot reach SendInvoice, so this never becomes a second transmission.
	 * - STATUS_QUEUED is a valid start status for the background worker, and is deliberately
	 *   NOT in can_retry(), so the order screen offers no re-send button for a queued order.
	 *   See may_start_transmission().
	 * - CENTRAL GUARD: any persistent evidence of a previous transmission attempt (UUID,
	 *   a post-transmission status, sent_at, or an advanced attempt counter) makes this
	 *   method reconcile-only. $force does not lift it, and no caller can bypass it,
	 *   because every caller comes through here.
	 *
	 * @param WC_Order $order WooCommerce Order.
	 * @param bool     $force Force retry for failed states only.
	 * @return Kuka_Island_Core_Invoice_Result Result DTO.
	 * @throws Kuka_Island_Core_Invoice_Exception On failure.
	 */
	public function process_order( WC_Order $order, bool $force = false ): Kuka_Island_Core_Invoice_Result {
		$order_id = $order->get_id();

		// 1. Guard against test/sandbox fixture pollution in production. The
		// decision lives in the shared final guard, so it cannot be relaxed by
		// a subclass, a filter, an option or a constant.
		Kuka_Island_Core_Invoice_Fixture_Guard::assert_not_fixture( $order );

		// 2. Preflight payment settlement check.
		if ( ! $this->is_order_settled( $order ) ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Order payment is not settled.',
				'unsettled_payment',
				__( 'Ödemesi kesinleşmemiş sipariş için fatura oluşturulamaz.', 'kuka-island-edm' )
			);
		}

		// 3. Terminal state check: completed invoices are immutable and CANNOT be re-sent.
		$current_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $order );
		if ( Kuka_Island_Core_Invoice_Status::is_terminal( $current_status ) ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Invoice has already reached terminal success and cannot be re-sent.',
				'already_terminal_invoice',
				__( 'Bu siparişin faturası zaten başarıyla kesilmiştir ve mükerrer gönderilemez.', 'kuka-island-edm' )
			);
		}

		/*
		 * Central post-transmission guard. Once there is persistent evidence
		 * that SendInvoice was already called for this order, this method may
		 * only reconcile. $force does not lift it: what it guards against is a
		 * second fiscal document, not a stuck status, and no caller -- admin
		 * button, queue worker, cron or a direct call -- has a reason to want
		 * one. The authoritative check runs again inside the lock below.
		 */
		$reconcile_only = array() !== self::transmission_evidence( $order );

		// Non-force calls refuse a status the send path may not start from. A
		// reconcile-only order is not starting anything, so this gate does not
		// apply to it. Re-verified on the fresh record inside the lock.
		if ( ! $reconcile_only && ! $force && ! self::may_start_transmission( $current_status ) ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Invoice status cannot be retried automatically.',
				'invalid_invoice_status_transition',
				__( 'Fatura durumu şu anda yeniden gönderime uygun değildir.', 'kuka-island-edm' )
			);
		}

		// 4. Concurrency lock on order ID.
		$lock_key = 'kuka_inv_' . $order_id;
		if ( ! $this->acquire_lock( $lock_key ) ) {
			throw new Kuka_Island_Core_Invoice_Transient_Exception(
				'Invoice processing lock is currently held by another worker.',
				'lock_collision',
				__( 'Bu siparişin fatura işlemi şu anda başka bir süreç tarafından yürütülüyor.', 'kuka-island-edm' )
			);
		}

		try {
			// Re-read order after acquiring lock to prevent race conditions.
			$fresh_order = wc_get_order( $order_id );
			if ( ! $fresh_order instanceof WC_Order ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception( 'Order not found.', 'order_not_found' );
			}

			// Re-check terminal status on the fresh record inside the lock.
			$locked_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $fresh_order );
			if ( Kuka_Island_Core_Invoice_Status::is_terminal( $locked_status ) ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'Invoice has already reached terminal success and cannot be re-sent.',
					'already_terminal_invoice',
					__( 'Bu siparişin faturası zaten başarıyla kesilmiştir ve mükerrer gönderilemez.', 'kuka-island-edm' )
				);
			}

			/*
			 * 5. The post-transmission guard, decided on the record read inside
			 * the lock. This is the only place that matters: past here lies
			 * SendInvoice, and an order with any evidence of a previous
			 * transmission attempt never reaches it. Reconciliation returns or
			 * throws; it cannot fall through.
			 */
			$locked_evidence = self::transmission_evidence( $fresh_order );
			if ( array() !== $locked_evidence ) {
				$recon_result = $this->reconcile_only( $fresh_order, $locked_evidence );

				if ( $order !== $fresh_order ) {
					$order->read_meta_data( true );
				}

				return $recon_result;
			}

			// The same start decision, re-taken on the record read inside the
			// lock: the status may have moved while this worker was queueing for
			// it, and the pre-lock answer is no longer evidence of anything.
			if ( ! $force && ! self::may_start_transmission( $locked_status ) ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'Invoice status cannot be retried automatically.',
					'invalid_invoice_status_transition',
					__( 'Fatura durumu şu anda yeniden gönderime uygun değildir.', 'kuka-island-edm' )
				);
			}

			/*
			 * 6a. The shipment gate, re-taken on the record read inside the lock.
			 *
			 * A physical order is invoiced when it has ALL left. Checking it
			 * again here is what makes an unfulfilled shipment fail closed even
			 * when the order was queued while it still looked complete -- a
			 * fulfillment reverted or edited after enqueue must not reach
			 * SendInvoice.
			 */
			$shipment = self::shipment_gate( $fresh_order );
			if ( ! $shipment['ok'] ) {
				Kuka_Island_Core_Invoice_Order_Store::save_blocked(
					$fresh_order,
					self::ERROR_SHIPMENT_INCOMPLETE,
					self::shipment_incomplete_message( $shipment['state'] )
				);

				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					sprintf( 'Order shipment state %s is not invoiceable as a whole order.', $shipment['state'] ),
					self::ERROR_SHIPMENT_INCOMPLETE,
					self::shipment_incomplete_message( $shipment['state'] )
				);
			}

			// 6. Determine document type and profile (e-Fatura vs e-Arşiv).
			$routing = $this->resolve_routing( $fresh_order );

			/*
			 * 7. Ask EDM to assign the fiscal document number.
			 *
			 * The submitted UBL's cbc:ID carries EDM's automatic-numbering
			 * sentinel; the number itself comes back in the response and is the
			 * only value ever recorded as this document's number. A locally
			 * derived number (order ID, local counter) remains prohibited.
			 *
			 * The registered three-character serial prefix is validated here and
			 * comes only from the reviewed environment configuration, so a
			 * document is never submitted against a serial the shop has not
			 * registered. An unconfigured serial is fail-closed BLOCKED.
			 */
			try {
				$invoice_number = Kuka_Island_Core_Invoice_Numbering::resolve_requested_number( $this->config, $routing['document_type'] );
			} catch ( Kuka_Island_Core_Invoice_Permanent_Exception $numbering_e ) {
				Kuka_Island_Core_Invoice_Order_Store::save_blocked(
					$fresh_order,
					$numbering_e->get_safe_error_code(),
					$numbering_e->get_user_message()
				);
				throw $numbering_e;
			}

			// 8. Build UBL-TR data & XML with minor-unit cents precision.
			$invoice_data = $this->mapper->map_order_to_invoice_data(
				$fresh_order,
				$routing['document_type'],
				$routing['profile_id'],
				$routing['receiver_alias'],
				$invoice_number
			);

			$ubl_builder = new Kuka_Island_Core_UBL_TR_Builder( $invoice_data );
			$ubl_xml     = $ubl_builder->build_xml();

			/*
			 * 8a. The internet-sales block, from observed facts only. Every
			 * refusal code is recorded so the order screen can say which fact is
			 * missing, and nothing is transmitted until they are all present.
			 */
			$internet_sales = $this->build_internet_sales_details( $fresh_order, $invoice_data, $shipment['facts'] );
			if ( ! $internet_sales['ok'] ) {
				Kuka_Island_Core_Invoice_Order_Store::save_blocked(
					$fresh_order,
					self::ERROR_INTERNET_SALES_INCOMPLETE,
					self::internet_sales_incomplete_message( $internet_sales['errors'] )
				);

				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					sprintf( 'INTERNETSALESDETAILS is incomplete: %s.', implode( ',', $internet_sales['errors'] ) ),
					self::ERROR_INTERNET_SALES_INCOMPLETE,
					self::internet_sales_incomplete_message( $internet_sales['errors'] )
				);
			}

			/*
			 * 8b. The deactivation gate, checked as late as possible and BEFORE
			 * any state is written.
			 *
			 * This worker may have started before the plugin was deactivated.
			 * Removing hooks cannot stop a request that is already inside this
			 * method, so the gate is read here, from the database, past the
			 * object cache. Placing it above mark_sending() matters: a document
			 * that is never transmitted must not leave a reserved UUID and a
			 * `sending` status behind, because that residue is what the
			 * duplicate-protection rules then have to reason about.
			 *
			 * ERROR_RUNTIME_DISABLED is a permanent refusal, not a transient
			 * one: retrying while the plugin is off would fail identically, and
			 * the order keeps the status it had.
			 */
			if ( Kuka_Island_Core_Invoice_Runtime_Gate::is_disabled() ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'EDM plugin is deactivated; transmission refused before any state was written.',
					self::ERROR_RUNTIME_DISABLED,
					Kuka_Island_Core_Invoice_Runtime_Gate::message()
				);
			}

			// Store in-progress status AND atomic UUID/number in a SINGLE atomic store operation BEFORE transmitting.
			// The number argument is '' on purpose: nothing local may be recorded
			// as this document's number, and the sentinel least of all.
			Kuka_Island_Core_Invoice_Order_Store::mark_sending(
				$fresh_order,
				$invoice_data['uuid'],
				'',
				__( 'Fatura XML oluşturuldu, EDM gönderimi başlatılıyor.', 'kuka-island-edm' )
			);

			// 9. Transmit to provider.
			$payload = array(
				'trx_id'            => $order_id,
				'uuid'              => $invoice_data['uuid'],
				'invoice_number'    => $invoice_data['invoice_number'],
				'invoice_serial'    => $invoice_data['series'],
				'profile_id'        => $invoice_data['profile_id'],
				'invoice_type_code' => $invoice_data['invoice_type_code'],
				'issue_date'        => $invoice_data['issue_date'],
				'payable_amount'    => (string) ( $invoice_data['totals']['payable_amount'] ?? '0.00' ),
				'receiver_vkn'      => $invoice_data['customer']['tax_number'],
				'receiver_alias'    => $invoice_data['receiver_alias'],
				// e-Arşiv: INVOICE/HEADER/TO carries the buyer's e-mail address,
				// which is how EDM delivers the document. Same address as the
				// UBL's cbc:ElectronicMail.
				'customer_email'    => $invoice_data['customer']['email'],
				'is_internet_sales' => true,
				// Serialised into SendInvoiceRequest/INVOICE/HEADER/INTERNETSALESDETAILS.
				'internet_sales_details' => $internet_sales['details'],
				'ubl_xml'           => $ubl_xml,
			);

			try {
				$result = $this->provider->send_invoice( $payload );
			} catch ( Exception $send_exception ) {
				// Ambiguous network/timeout error during transmission -> transition to send_uncertain (never needs_manual_review).
				$err_code = method_exists( $send_exception, 'get_safe_error_code' ) ? $send_exception->get_safe_error_code() : 'send_network_timeout';
				$err_msg  = method_exists( $send_exception, 'get_user_message' ) ? $send_exception->get_user_message() : $send_exception->getMessage();
				Kuka_Island_Core_Invoice_Order_Store::save_send_uncertain( $fresh_order, $err_code, $err_msg );

				/*
				 * The transmission may or may not have landed. Booking a
				 * GetInvoiceStatus query is the only safe next move: the
				 * poller cannot reach SendInvoice, so asking can never turn
				 * into a blind second transmission.
				 */
				$this->start_status_polling( $fresh_order );

				throw $send_exception;
			}

			// 10. Persist result.
			if ( $result->is_success() ) {
				Kuka_Island_Core_Invoice_Order_Store::save_invoice_sent(
					$fresh_order,
					$result,
					$routing['document_type'],
					$routing['profile_id']
				);
			} else {
				Kuka_Island_Core_Invoice_Order_Store::save_invoice_error(
					$fresh_order,
					$result->get_safe_error_code(),
					$result->get_status_description()
				);
			}

			/*
			 * 11. A document that is still on its way gets exactly one status
			 * query booked, from the persisted status rather than the result
			 * object, so what is polled is what was recorded. sent,
			 * pending_approval and send_uncertain are the only three that
			 * qualify; completed, rejected, cancelled and the error states are
			 * answers and are never asked about again.
			 *
			 * This sits on the single path both the queue worker and the order
			 * screen's manual send take, so the two behave identically.
			 */
			$this->start_status_polling( $fresh_order );

			if ( $order !== $fresh_order ) {
				$order->read_meta_data( true );
			}

			return $result;
		} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
			if ( $order !== ( $fresh_order ?? null ) ) {
				$order->read_meta_data( true );
			}
			throw $e;
		} finally {
			$this->release_lock( $lock_key );
		}
	}

	/**
	 * Book the automatic status query for a document that is still on its way.
	 *
	 * The gate is the status actually persisted on the order, and
	 * Kuka_Island_Core_Invoice_Status_Poller::start() refuses anything that is
	 * not sent, pending_approval or send_uncertain -- so a completed, rejected,
	 * cancelled or failed document books nothing.
	 *
	 * A booking that does not happen must not fail the send: the document is
	 * already transmitted and persisted, and throwing from here would reach the
	 * queue worker's generic handler, which writes needs_manual_review -- a
	 * status can_retry() permits, so the next run could send the document
	 * again. It must not be silent either, which is why the poller records the
	 * reason on the order instead of a bare false being dropped here.
	 *
	 * @param WC_Order $order Order carrying the persisted send outcome.
	 * @return array{ok: bool, outcome: string, pending_verified: bool|null, error_code: string}
	 */
	private function start_status_polling( WC_Order $order ): array {
		try {
			return Kuka_Island_Core_Invoice_Status_Poller::start(
				$order,
				Kuka_Island_Core_Invoice_Order_Store::get_status( $order )
			);
		} catch ( Throwable $scheduling_error ) {
			// The exception text is deliberately not read, logged or stored:
			// only the poller's safe code goes on the order.
			unset( $scheduling_error );

			return Kuka_Island_Core_Invoice_Status_Poller::record_scheduling_exception( $order );
		}
	}

	/**
	 * Collect the internet-sales facts from the real order and build the block.
	 *
	 * Every source is a verified read, never a derivation:
	 *
	 *   webAdresi       the shop's canonical HTTPS home address
	 *   payment_gateway WC_Order::get_payment_method() -- the gateway id, never
	 *                   its shop-editable checkout title
	 *   odemeTarihi     WC_Order::get_date_paid(), with no fallback
	 *   shipment state  WooCommerce Fulfillments, aggregated over the whole order
	 *   shipment date   the LATEST fulfilled date, i.e. when the order finished
	 *                   leaving
	 *   carrier VKN /   only from the reviewed carrier configuration, looked up
	 *   unvan           by Fulfillment::get_shipment_provider()
	 *
	 * @param WC_Order             $order          Order.
	 * @param array<string, mixed> $invoice_data   Mapped invoice data.
	 * @param array<string, mixed> $shipment_facts read_shipment_facts() output.
	 * @return array{ok: bool, errors: array<int, string>, details: array<string, mixed>, provider_key: string}
	 */
	private function build_internet_sales_details( WC_Order $order, array $invoice_data, array $shipment_facts ): array {
		$shipment_state = (string) ( $shipment_facts['shipment_state'] ?? Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_PENDING );

		$carrier = array(
			'ok'           => true,
			'error'        => '',
			'provider_key' => '',
			'vkn'          => '',
			'title'        => '',
		);

		// A digital or service-only order has no carrier to identify.
		if ( Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_COMPLETE === $shipment_state ) {
			$carrier = Kuka_Island_Core_Internet_Sales_Details::resolve_carrier(
				$this->config,
				(array) ( $shipment_facts['provider_keys'] ?? array() )
			);
		}

		$built = Kuka_Island_Core_Internet_Sales_Details::build(
			array(
				'web_address'     => self::shop_web_address(),
				'payment_gateway' => Kuka_Island_Core_Internet_Sales_Details::read_payment_gateway( $order ),
				'payment_date'    => Kuka_Island_Core_Internet_Sales_Details::read_payment_date( $order ),
				'shipment_state'  => $shipment_state,
				'shipment_date'   => self::shipment_date_only( (string) ( $shipment_facts['shipment_date'] ?? '' ) ),
				'carrier_vkn'     => $carrier['vkn'],
				'carrier_title'   => $carrier['title'],
			)
		);

		$errors = (array) $built['errors'];

		/*
		 * A fulfilled shipment whose handover time cannot be read must not turn
		 * into a plausible wrong date, and must not silently become today or
		 * the order date either. It is reported as its own refusal.
		 */
		$date_unreadable = true === ( $shipment_facts['shipment_date_invalid'] ?? false )
			|| ( Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_COMPLETE === $shipment_state
				&& '' !== trim( (string) ( $shipment_facts['shipment_date'] ?? '' ) )
				&& '' === self::shipment_date_only( (string) $shipment_facts['shipment_date'] ) );

		if ( $date_unreadable && ! in_array( Kuka_Island_Core_Internet_Sales_Details::ERROR_SHIPMENT_DATE_INVALID, $errors, true ) ) {
			$errors[] = Kuka_Island_Core_Internet_Sales_Details::ERROR_SHIPMENT_DATE_INVALID;
		}

		if ( ! $carrier['ok'] && '' !== $carrier['error'] && ! in_array( $carrier['error'], $errors, true ) ) {
			// The carrier's own refusal is more specific than build()'s "no VKN",
			// so it is reported alongside rather than swallowed by it.
			$errors[] = $carrier['error'];
		}

		return array(
			'ok'           => $carrier['ok'] && true === $built['ok'] && ! $date_unreadable,
			'errors'       => array_values( array_unique( $errors ) ),
			'details'      => (array) $built['details'],
			'provider_key' => (string) $carrier['provider_key'],
		);
	}

	/**
	 * The shop's canonical HTTPS address for webAdresi.
	 */
	public static function shop_web_address(): string {
		$home = trim( (string) home_url( '/' ) );
		if ( '' === $home ) {
			return '';
		}

		// A fiscal document states where the sale happened; an http:// address
		// for a shop served over TLS would misstate it.
		return preg_replace( '#^http://#i', 'https://', untrailingslashit( $home ) );
	}

	/**
	 * A WooCommerce fulfilled date reduced to its shop-local calendar day.
	 *
	 * Delegates to the one strict parser, which is also what
	 * Kuka_Island_Core_Internet_Sales_Details::read_shipment_facts() uses to
	 * pick the latest shipment -- so the day reported and the shipment chosen
	 * can never be derived differently.
	 *
	 * @param string $raw Raw fulfilled date.
	 */
	public static function shipment_date_only( string $raw ): string {
		return Kuka_Island_Core_Internet_Sales_Details::fulfillment_calendar_day( $raw );
	}

	/**
	 * What the order screen is told while the goods have not all left.
	 *
	 * @param string $shipment_state One of the SHIPMENT_* constants.
	 */
	public static function shipment_incomplete_message( string $shipment_state ): string {
		if ( Kuka_Island_Core_Internet_Sales_Details::SHIPMENT_PARTIAL === $shipment_state ) {
			return __( 'Kısmi gönderim var; tüm ürünler kargoya verilmeden fatura oluşturulmaz.', 'kuka-island-edm' );
		}

		return __( 'Fatura için siparişin tamamen kargoya verilmesi bekleniyor.', 'kuka-island-edm' );
	}

	/**
	 * What the order screen is told when the internet-sales block is short a fact.
	 *
	 * Names the missing thing in plain Turkish. No raw meta, no SOAP detail.
	 *
	 * @param array<int, string> $errors Safe refusal codes from the producer.
	 */
	public static function internet_sales_incomplete_message( array $errors ): string {
		$carrier_codes = array(
			Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_UNMAPPED,
			Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_VKN_MISSING,
			Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_VKN_INVALID,
			Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_TITLE_MISSING,
			Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_PROVIDER_MISSING,
		);

		if ( array() !== array_intersect( $errors, $carrier_codes ) ) {
			return __( 'Kargo firmasının mali bilgileri (VKN ve unvan) yapılandırılmamış; fatura oluşturulmadı.', 'kuka-island-edm' );
		}

		if ( in_array( Kuka_Island_Core_Internet_Sales_Details::ERROR_CARRIER_MULTIPLE_PROVIDERS, $errors, true ) ) {
			return __( 'Sipariş birden fazla kargo firmasıyla gönderilmiş; tek faturada tek taşıyıcı bildirilebildiği için manuel inceleme gerekiyor.', 'kuka-island-edm' );
		}

		if ( in_array( Kuka_Island_Core_Internet_Sales_Details::ERROR_SHIPMENT_DATE_INVALID, $errors, true ) ) {
			return __( 'Kargoya verilme tarihi okunamadı; fatura oluşturulmadı.', 'kuka-island-edm' );
		}

		if ( in_array( Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_DATE_MISSING, $errors, true ) ) {
			return __( 'Ödeme tarihi bulunamadı; fatura oluşturulmadı.', 'kuka-island-edm' );
		}

		if ( in_array( Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_UNMAPPED, $errors, true )
			|| in_array( Kuka_Island_Core_Internet_Sales_Details::ERROR_PAYMENT_METHOD_MISSING, $errors, true ) ) {
			return __( 'Ödeme yöntemi için doğrulanmış mali karşılık tanımlı değil; fatura oluşturulmadı.', 'kuka-island-edm' );
		}

		return __( 'İnternet satış bilgileri eksik olduğu için fatura oluşturulmadı.', 'kuka-island-edm' );
	}

	/**
	 * Resolve an order that has already been through SendInvoice.
	 *
	 * GetInvoiceStatus is the only EDM operation this method can reach, and it
	 * has exactly three ways out, none of which is a transmission:
	 *
	 * - EDM gives a definite answer -> record it and return.
	 * - EDM cannot be asked -> lock the order into reconciliation_required and
	 *   throw a transient error. Failing to ask is not permission to send.
	 * - EDM answers with something the closed status list does not recognise ->
	 *   record what it said, then lock the order into reconciliation_required.
	 *   An unrecognised literal is not a licence to send the document again.
	 *
	 * reconciliation_required sits outside can_retry(), so the order screen's
	 * re-send button disappears and the queue will not pick the order up.
	 *
	 * @param WC_Order          $order    Order with transmission evidence.
	 * @param array<int, string> $evidence Which facts established that.
	 * @return Kuka_Island_Core_Invoice_Result Reconciliation result.
	 * @throws Kuka_Island_Core_Invoice_Exception When the document cannot be resolved.
	 */
	private function reconcile_only( WC_Order $order, array $evidence ): Kuka_Island_Core_Invoice_Result {
		$uuid   = trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_UUID, true ) );
		$number = trim( (string) $order->get_meta( Kuka_Island_Core_Invoice_Order_Store::META_NUMBER, true ) );

		if ( '' === $uuid ) {
			// Something was sent and the identifier for asking about it is gone.
			// There is nothing to reconcile, and still nothing safe to send.
			$this->lock_for_manual_query( $order, self::ERROR_RECONCILE_NO_UUID );

			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				sprintf( 'Transmission evidence (%s) without an invoice UUID; refusing to send again.', implode( ',', $evidence ) ),
				self::ERROR_RECONCILE_NO_UUID,
				self::manual_query_message()
			);
		}

		try {
			$result = $this->provider->get_invoice_status( $uuid, $number );
		} catch ( Exception $recon_e ) {
			// The exception text is not read, logged or stored: only the safe
			// code goes on the order.
			unset( $recon_e );
			$this->lock_for_manual_query( $order, self::ERROR_RECONCILE_UNAVAILABLE );

			throw new Kuka_Island_Core_Invoice_Transient_Exception(
				'Invoice already transmitted and its EDM status could not be reconciled.',
				self::ERROR_RECONCILE_UNAVAILABLE,
				self::manual_query_message()
			);
		}

		if ( ! $result->is_success() ) {
			$this->lock_for_manual_query( $order, self::ERROR_RECONCILE_UNAVAILABLE );

			return $result;
		}

		// Record what EDM actually said, whatever it was.
		Kuka_Island_Core_Invoice_Order_Store::save_status_query( $order, $result );

		if ( ! self::is_definite_reconcile_status( $result->get_status() ) ) {
			$this->lock_for_manual_query( $order, self::ERROR_RECONCILE_INDEFINITE );
		}

		return $result;
	}

	/**
	 * Put an unresolvable transmitted document into the manual-query lock.
	 *
	 * The note is added only when the status actually changes, so a repeated
	 * retry does not fill the order with identical notes.
	 *
	 * @param WC_Order $order           Order.
	 * @param string   $safe_error_code Safe classification code.
	 */
	private function lock_for_manual_query( WC_Order $order, string $safe_error_code ): void {
		$transitioned = Kuka_Island_Core_Invoice_Order_Store::save_reconciliation_required(
			$order,
			$safe_error_code,
			self::manual_query_message()
		);

		if ( $transitioned ) {
			Kuka_Island_Core_Invoice_Order_Store::add_operator_note( $order, self::manual_query_message(), $safe_error_code );
		}
	}

	/**
	 * Reconcile in-flight order status via GetInvoiceStatus instead of sending again.
	 */
	public function reconcile_in_flight_order( WC_Order $order ): Kuka_Island_Core_Invoice_Result {
		$uuid   = (string) $order->get_meta( '_kuka_invoice_uuid', true );
		$number = (string) $order->get_meta( '_kuka_invoice_number', true );

		if ( '' === $uuid ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'In-flight order has no invoice UUID.',
				'missing_invoice_uuid',
				__( 'İşlemdeki faturanın UUID kaydı bulunamadı.', 'kuka-island-edm' )
			);
		}

		$result = $this->provider->get_invoice_status( $uuid, $number );
		Kuka_Island_Core_Invoice_Order_Store::save_status_query( $order, $result );

		return $result;
	}

	/**
	 * Query latest status of an existing invoice from the provider.
	 */
	public function query_order_status( WC_Order $order ): Kuka_Island_Core_Invoice_Result {
		return $this->reconcile_in_flight_order( $order );
	}

	/**
	 * Resolve e-Fatura vs e-Arşiv routing for an order.
	 *
	 * @return array{document_type: string, profile_id: string, receiver_alias: string}
	 */
	public function resolve_routing( WC_Order $order ): array {
		$customer_type = (string) $order->get_meta( '_billing_customer_type', true );
		$tax_number    = trim( (string) $order->get_meta( '_billing_tax_number', true ) );
		$is_corporate  = 'corporate' === $customer_type || ! empty( $order->get_billing_company() );

		if ( $is_corporate && preg_match( '/^\d{10,11}$/', $tax_number ) ) {
			try {
				$check_user = $this->provider->check_user( $tax_number );
			} catch ( Exception $e ) {
				// Ambiguous error: do NOT guess that it is e-Archive. Stop for manual review.
				throw new Kuka_Island_Core_Invoice_Transient_Exception(
					'GİB CheckUser query failed with ambiguous result.',
					'check_user_ambiguous',
					__( 'Alıcının e-Fatura mükellefiyeti GİB üzerinden sorgulanamadı. Hatalı belge türü kesilmemesi için işlem durduruldu.', 'kuka-island-edm' )
				);
			}

			if ( ! empty( $check_user['is_einvoice_user'] ) ) {
				$alias = trim( (string) ( $check_user['alias'] ?? '' ) );
				if ( '' === $alias ) {
					throw new Kuka_Island_Core_Invoice_Permanent_Exception(
						'e-Invoice recipient alias is missing.',
						'missing_recipient_alias',
						__( 'e-Fatura mükellefinin GİB posta kutusu etiketi (alias) bulunamadı.', 'kuka-island-edm' )
					);
				}

				return array(
					'document_type'  => Kuka_Island_Core_Invoice_Status::TYPE_EINVOICE,
					'profile_id'     => 'TICARIFATURA',
					'receiver_alias' => $alias,
				);
			}
		}

		// Individual or non-GİB customer: e-Arşiv.
		//
		// Verified EDM WSDL evidence (EFaturaEDM.svc?singleWsdl):
		// SendInvoiceRequest/RECEIVER declares `vkn` and `alias` as optional
		// xs:attribute entries, and INVOICE/HEADER/TO is
		// minOccurs="0". e-Arşiv recipients have no GİB mailbox, and the flow is
		// identified by INVOICE/HEADER/EARCHIVE (xs:boolean) -- not by a
		// receiver alias. Omitting the alias is therefore schema-valid; the
		// previously invented default-mailbox label was not, and has been
		// removed. (This comment used to cite EARCHIVE_REPORT_SENDDATE as part
		// of that identification. The outgoing request no longer sends it: it
		// is EDM's own GİB reporting date, per their written answer of
		// 3 September 2026.)
		return array(
			'document_type'  => Kuka_Island_Core_Invoice_Status::TYPE_EARCHIVE,
			'profile_id'     => 'EARSIVFATURA',
			'receiver_alias' => '',
		);
	}

	public function is_order_settled( WC_Order $order ): bool {
		if ( class_exists( 'Kuka_Island_Core_Iyzico_Idempotency' ) && method_exists( 'Kuka_Island_Core_Iyzico_Idempotency', 'order_is_paid' ) ) {
			if ( Kuka_Island_Core_Iyzico_Idempotency::order_is_paid( $order ) ) {
				return true;
			}
		}

		if ( $order->is_paid() && in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Fixture decision, delegated to the shared final production guard.
	 *
	 * Kept as a thin public accessor so callers (queue, admin, diagnostics) have
	 * one entry point. It is final: no subclass can weaken the guard.
	 */
	final public function is_test_fixture_order( WC_Order $order ): bool {
		return Kuka_Island_Core_Invoice_Fixture_Guard::is_test_fixture_order( $order );
	}

	private function acquire_lock( string $key ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$res = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', substr( $key, 0, 60 ) ) );
		return '1' === (string) $res;
	}

	private function release_lock( string $key ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', substr( $key, 0, 60 ) ) );
	}
}
