<?php
/**
 * Invoice Integration Manager.
 *
 * Coordinates provider authentication, user routing (e-Fatura vs e-Arşiv),
 * UBL XML generation, order persistence, network reconciliation and duplicate prevention.
 *
 * @package Kuka_Island_Core
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
				__( 'Ödemesi kesinleşmemiş sipariş için fatura oluşturulamaz.', 'kuka-island-core' )
			);
		}

		// 3. Terminal state check: completed invoices are immutable and CANNOT be re-sent.
		$current_status = Kuka_Island_Core_Invoice_Order_Store::get_status( $order );
		if ( Kuka_Island_Core_Invoice_Status::is_terminal( $current_status ) ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Invoice has already reached terminal success and cannot be re-sent.',
				'already_terminal_invoice',
				__( 'Bu siparişin faturası zaten başarıyla kesilmiştir ve mükerrer gönderilemez.', 'kuka-island-core' )
			);
		}

		// In-flight or uncertain statuses must reconcile via GetInvoiceStatus, never SendInvoice.
		if ( in_array( $current_status, array( Kuka_Island_Core_Invoice_Status::STATUS_SENT, Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, Kuka_Island_Core_Invoice_Status::STATUS_SENDING, Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN ), true ) ) {
			return $this->reconcile_in_flight_order( $order );
		}

		// Non-force calls refuse non-retryable states.
		if ( ! $force && ! Kuka_Island_Core_Invoice_Status::can_retry( $current_status ) ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Invoice status cannot be retried automatically.',
				'invalid_invoice_status_transition',
				__( 'Fatura durumu şu anda yeniden gönderime uygun değildir.', 'kuka-island-core' )
			);
		}

		// 4. Concurrency lock on order ID.
		$lock_key = 'kuka_inv_' . $order_id;
		if ( ! $this->acquire_lock( $lock_key ) ) {
			throw new Kuka_Island_Core_Invoice_Transient_Exception(
				'Invoice processing lock is currently held by another worker.',
				'lock_collision',
				__( 'Bu siparişin fatura işlemi şu anda başka bir süreç tarafından yürütülüyor.', 'kuka-island-core' )
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
					__( 'Bu siparişin faturası zaten başarıyla kesilmiştir ve mükerrer gönderilemez.', 'kuka-island-core' )
				);
			}

			// 5. Network loss / in-flight status reconciliation:
			// If UUID already exists on order (e.g. earlier interrupted attempt or sending/sent/uncertain status),
			// query EDM first to avoid blind duplicate sends.
			$existing_uuid   = (string) $fresh_order->get_meta( '_kuka_invoice_uuid', true );
			$existing_number = (string) $fresh_order->get_meta( '_kuka_invoice_number', true );

			if ( '' !== $existing_uuid && '' !== $locked_status && Kuka_Island_Core_Invoice_Status::STATUS_NONE !== $locked_status && Kuka_Island_Core_Invoice_Status::STATUS_QUEUED !== $locked_status ) {
				try {
					$recon_result = $this->provider->get_invoice_status( $existing_uuid, $existing_number );
					if ( $recon_result->is_success() ) {
						Kuka_Island_Core_Invoice_Order_Store::save_status_query( $fresh_order, $recon_result );
						if ( Kuka_Island_Core_Invoice_Status::is_terminal( $recon_result->get_status() ) || Kuka_Island_Core_Invoice_Status::STATUS_SENT === $recon_result->get_status() || Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL === $recon_result->get_status() ) {
							if ( $order !== $fresh_order ) {
								$order->read_meta_data( true );
							}
							return $recon_result;
						}
					}
				} catch ( Exception $recon_e ) {
					if ( in_array( $locked_status, array( Kuka_Island_Core_Invoice_Status::STATUS_SENDING, Kuka_Island_Core_Invoice_Status::STATUS_SENT, Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL, Kuka_Island_Core_Invoice_Status::STATUS_SEND_UNCERTAIN ), true ) ) {
						throw new Kuka_Island_Core_Invoice_Transient_Exception(
							'Invoice in-flight or status uncertain. Reconciliation required before retry.',
							'reconciliation_required',
							__( 'Fatura durumu uzlaştırılamadı. Mükerrer gönderimi önlemek için durum sorgulaması bekleniyor.', 'kuka-island-core' )
						);
					}
				}
			}

			// 6. Determine document type and profile (e-Fatura vs e-Arşiv).
			$routing = $this->resolve_routing( $fresh_order );

			// 7. Resolve the fiscal document number. Only EDM may assign it; a
			// locally derived number (order ID, local counter) is prohibited, so
			// an unconfirmed numbering contract is a fail-closed BLOCKED state.
			try {
				$invoice_number = Kuka_Island_Core_Invoice_Numbering::resolve_assigned_number( $fresh_order );
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

			// Store in-progress status AND atomic UUID/number in a SINGLE atomic store operation BEFORE transmitting.
			Kuka_Island_Core_Invoice_Order_Store::mark_sending(
				$fresh_order,
				$invoice_data['uuid'],
				$invoice_data['invoice_number'],
				__( 'Fatura XML oluşturuldu, EDM gönderimi başlatılıyor.', 'kuka-island-core' )
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
	 * Failure to book is not allowed to fail the send: the invoice was already
	 * transmitted and persisted, and the order screen's requery button remains.
	 *
	 * @param WC_Order $order Order carrying the persisted send outcome.
	 * @return bool Whether a query was booked by this call.
	 */
	private function start_status_polling( WC_Order $order ): bool {
		try {
			return Kuka_Island_Core_Invoice_Status_Poller::start(
				$order,
				Kuka_Island_Core_Invoice_Order_Store::get_status( $order )
			);
		} catch ( Throwable $e ) {
			return false;
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
				__( 'İşlemdeki faturanın UUID kaydı bulunamadı.', 'kuka-island-core' )
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
					__( 'Alıcının e-Fatura mükellefiyeti GİB üzerinden sorgulanamadı. Hatalı belge türü kesilmemesi için işlem durduruldu.', 'kuka-island-core' )
				);
			}

			if ( ! empty( $check_user['is_einvoice_user'] ) ) {
				$alias = trim( (string) ( $check_user['alias'] ?? '' ) );
				if ( '' === $alias ) {
					throw new Kuka_Island_Core_Invoice_Permanent_Exception(
						'e-Invoice recipient alias is missing.',
						'missing_recipient_alias',
						__( 'e-Fatura mükellefinin GİB posta kutusu etiketi (alias) bulunamadı.', 'kuka-island-core' )
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
		// identified by INVOICE/HEADER/EARCHIVE (xs:boolean) plus
		// EARCHIVE_REPORT_SENDDATE -- not by a receiver alias. Omitting the alias
		// is therefore schema-valid; the previously invented default-mailbox
		// label was not, and has been removed.
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
