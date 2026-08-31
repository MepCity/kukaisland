<?php
/**
 * EDM e-Invoice / e-Archive SOAP Client adapter.
 *
 * Implements the verified EDM Bilişim Connector Service WSDL protocol specification.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_EDM_Client {
	private Kuka_Island_Core_Invoice_Config $config;
	private Kuka_Island_Core_SOAP_Transport_Interface $transport;
	private ?string $session_id = null;

	public function __construct( Kuka_Island_Core_Invoice_Config $config, ?Kuka_Island_Core_SOAP_Transport_Interface $transport = null ) {
		$this->config    = $config;
		$this->transport = $transport ?? new Kuka_Island_Core_EDM_SOAP_Transport(
			$config->get_wsdl(),
			$config->get_timeout(),
			$config->is_trace_enabled()
		);
	}

	public function get_config(): Kuka_Island_Core_Invoice_Config {
		return $this->config;
	}

	public function get_transport(): Kuka_Island_Core_SOAP_Transport_Interface {
		return $this->transport;
	}

	public function get_session_id(): ?string {
		return $this->session_id;
	}

	public function set_session_id( ?string $session_id ): void {
		$this->session_id = $session_id;
	}

	/**
	 * Build a REQUEST_HEADER.
	 *
	 * Delegates to Kuka_Island_Core_EDM_Request_Header, which is the single
	 * generator this client and the isolated sandbox experiment share. They
	 * drifted apart once; routing both through one pure builder is what stops
	 * that happening again.
	 *
	 * @param string $session_id    Session identifier, or '0' for Login.
	 * @param string $reason        Operation name recorded as REASON.
	 * @param string $client_txn_id Caller-chosen transaction id. Empty means a
	 *                              fresh UUID; SendInvoice passes the document
	 *                              UUID so the header stays idempotency-bound.
	 * @return array<string, string>
	 */
	private function build_request_header( string $session_id, string $reason, string $client_txn_id = '' ): array {
		return Kuka_Island_Core_EDM_Request_Header::build(
			$session_id,
			$reason,
			$this->config->get_application_name(),
			$client_txn_id
		);
	}

	/**
	 * Map a classified login fault onto the right exception.
	 *
	 * Categories that cannot improve on retry become permanent; transport-level
	 * ones stay transient so the queue may retry them. The safe verdict rides
	 * along on the exception; the fault message does not.
	 *
	 * @param array<string, mixed> $verdict Output of the fault classifier.
	 */
	private static function login_exception_for( array $verdict ): Kuka_Island_Core_Invoice_Exception {
		$category = (string) ( $verdict['category'] ?? Kuka_Island_Core_EDM_Fault_Classifier::CAT_UNCLASSIFIED );

		switch ( $category ) {
			case Kuka_Island_Core_EDM_Fault_Classifier::CAT_CREDENTIALS:
				$exception = new Kuka_Island_Core_Invoice_Permanent_Exception(
					'EDM login authentication failed.',
					'edm_auth_failed',
					__( 'EDM giriş bilgileri hatalı.', 'kuka-island-core' )
				);
				break;

			case Kuka_Island_Core_EDM_Fault_Classifier::CAT_CONTRACT:
				$exception = new Kuka_Island_Core_Invoice_Permanent_Exception(
					'EDM refused the login request contract.',
					'edm_login_contract_rejected',
					__( 'EDM giriş isteği biçimi reddedildi.', 'kuka-island-core' )
				);
				break;

			case Kuka_Island_Core_EDM_Fault_Classifier::CAT_NOT_FOUND:
				$exception = new Kuka_Island_Core_Invoice_Permanent_Exception(
					'EDM login endpoint did not resolve to the service.',
					'edm_login_endpoint_not_found',
					__( 'EDM servis adresi bulunamadı.', 'kuka-island-core' )
				);
				break;

			case Kuka_Island_Core_EDM_Fault_Classifier::CAT_SESSION:
				$exception = new Kuka_Island_Core_Invoice_Permanent_Exception(
					'EDM rejected the login session state.',
					'edm_login_session_rejected',
					__( 'EDM oturum bilgisi reddedildi.', 'kuka-island-core' )
				);
				break;

			case Kuka_Island_Core_EDM_Fault_Classifier::CAT_TLS:
				$exception = new Kuka_Island_Core_Invoice_Transient_Exception(
					'EDM login TLS failure.',
					'edm_login_tls_failure',
					__( 'EDM sunucusuyla güvenli bağlantı kurulamadı.', 'kuka-island-core' )
				);
				break;

			case Kuka_Island_Core_EDM_Fault_Classifier::CAT_TIMEOUT:
				$exception = new Kuka_Island_Core_Invoice_Transient_Exception(
					'EDM login timed out.',
					'edm_login_timeout',
					__( 'EDM sunucusu zamanında yanıt vermedi.', 'kuka-island-core' )
				);
				break;

			case Kuka_Island_Core_EDM_Fault_Classifier::CAT_SERVER:
				$exception = new Kuka_Island_Core_Invoice_Transient_Exception(
					'EDM login remote server error.',
					'edm_login_server_error',
					__( 'EDM sunucusunda geçici bir hata oluştu.', 'kuka-island-core' )
				);
				break;

			default:
				$exception = new Kuka_Island_Core_Invoice_Transient_Exception(
					'EDM login network / server fault.',
					'edm_login_fault',
					__( 'EDM sunucusuna bağlanılamadı. Lütfen kısa süre sonra tekrar deneyin.', 'kuka-island-core' )
				);
				break;
		}

		return $exception->set_diagnostic( $verdict );
	}

	/**
	 * Authenticate with EDM and obtain a SessionID.
	 *
	 * @return string SessionID.
	 * @throws Kuka_Island_Core_Invoice_Exception On failure.
	 */
	public function login(): string {
		// Login is an authentication call. Its only precondition is a username
		// and a password. Fiscal configuration (sender VKN, mailbox alias,
		// invoice series, company title/tax office/address) is NOT required to
		// authenticate, so it must not gate this call -- otherwise read-only
		// diagnostics such as CheckCounter and GetInvoiceSerial become
		// unreachable on an account that is perfectly able to log in.
		//
		// The strict contracts stay where they belong:
		// - Kuka_Island_Core_Invoice_Config::is_configured()     -> username + password + sender VKN
		// - Kuka_Island_Core_Invoice_Config::can_send_invoice()  -> all 12 fiscal readiness fields
		// - Kuka_Island_Core_Invoice_Config::is_auto_send_enabled() -> can_send_invoice() + opt-in
		// SECRET_KEY remains optional and is only sent when configured.
		if ( ! $this->config->has_login_credentials() ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'EDM login credentials are not configured.',
				'edm_not_configured',
				__( 'EDM kullanıcı adı ve şifre yapılandırması eksik.', 'kuka-island-core' )
			);
		}

		$request = array(
			'REQUEST_HEADER' => self::build_request_header( Kuka_Island_Core_EDM_Request_Header::NO_SESSION, 'Login' ),
			'USER_NAME'      => $this->config->get_username(),
			'PASSWORD'       => $this->config->get_password(),
		);

		if ( '' !== $this->config->get_secret_key() ) {
			$request['SECRET_KEY'] = $this->config->get_secret_key();
		}

		try {
			$response = $this->transport->call( 'Login', $request );
		} catch ( SoapFault $fault ) {
			$this->session_id = null;

			// The fault message is untrusted remote text and may quote the
			// request back, so it is classified rather than read. Only the
			// resulting fixed vocabulary is ever carried or printed.
			$verdict = Kuka_Island_Core_EDM_Fault_Classifier::classify(
				isset( $fault->faultcode ) ? (string) $fault->faultcode : '',
				(string) $fault->getMessage()
			);

			throw self::login_exception_for( $verdict );
		} catch ( Exception $e ) {
			$this->session_id = null;
			$verdict = Kuka_Island_Core_EDM_Fault_Classifier::classify( '', (string) $e->getMessage() );

			throw ( new Kuka_Island_Core_Invoice_Transient_Exception(
				'EDM login unexpected error.',
				'edm_login_error',
				__( 'EDM oturumu açılırken iletişim hatası oluştu.', 'kuka-island-core' )
			) )->set_diagnostic( $verdict );
		}

		$session_id = $this->extract_session_id_from_response( $response );
		if ( '' === $session_id ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'EDM login did not return a valid session ID.',
				'edm_session_empty',
				__( 'EDM sunucusundan geçerli bir oturum kimliği alınamadı.', 'kuka-island-core' )
			);
		}

		$this->session_id = $session_id;

		return $this->session_id;
	}

	/**
	 * Terminate active EDM session.
	 */
	public function logout(): bool {
		if ( empty( $this->session_id ) ) {
			return true;
		}

		$request = array(
			'REQUEST_HEADER' => self::build_request_header( (string) $this->session_id, 'Logout' ),
		);

		try {
			$this->transport->call( 'Logout', $request );
		} catch ( Exception $e ) {
			// Logout failure is non-fatal.
		} finally {
			$this->session_id = null;
		}

		return true;
	}

	/**
	 * Check if a tax number (VKN/TCKN) is an e-Invoice user.
	 *
	 * @param string $identifier 10 or 11-digit VKN/TCKN.
	 * @return array{is_einvoice_user: bool, title: string, alias: string, register_time: string, raw_data: array<string, mixed>}
	 */
	public function check_user( string $identifier ): array {
		$clean_id = preg_replace( '/\D/', '', $identifier );
		if ( ! in_array( strlen( $clean_id ), array( 10, 11 ), true ) ) {
			throw new Kuka_Island_Core_Invoice_Permanent_Exception(
				'Invalid tax identifier length.',
				'invalid_tax_identifier',
				__( 'Geçersiz vergi/kimlik numarası.', 'kuka-island-core' )
			);
		}

		return $this->execute_with_session(
			function ( string $session_id ) use ( $clean_id ): array {
				$request = array(
					'REQUEST_HEADER' => self::build_request_header( (string) $session_id, 'CheckUser' ),
					'USER'           => array(
						'IDENTIFIER'   => $clean_id,
						'DOCUMENTTYPE' => 'INVOICE',
					),
				);

				$response = $this->transport->call( 'CheckUser', $request );
				return $this->parse_check_user_response( $response, $clean_id );
			}
		);
	}

	/**
	 * Send an invoice to EDM.
	 *
	 * WSDL contract mapping:
	 * - SENDER: attribute vkn, attribute alias
	 * - RECEIVER: attribute vkn, attribute alias
	 * - INVOICE.HEADER: SENDER, RECEIVER, FROM, TO, PROFILEID, INVOICE_TYPE, ISSUE_DATE, PAYABLE_AMOUNT, INTERNETSALES, EARCHIVE...
	 * - INVOICE.CONTENT: raw XML string (SoapClient serializes to base64Binary without double-encoding)
	 *
	 * @param array<string, mixed> $payload Invoice payload with header and XML content.
	 * @return Kuka_Island_Core_Invoice_Result Result DTO.
	 */
	public function send_invoice( array $payload ): Kuka_Island_Core_Invoice_Result {
		return $this->execute_with_session(
			function ( string $session_id ) use ( $payload ): Kuka_Island_Core_Invoice_Result {
				$issue_date  = $payload['issue_date'] ?? gmdate( 'Y-m-d' );
				$is_earchive = ( 'EARSIVFATURA' === ( $payload['profile_id'] ?? 'EARSIVFATURA' ) );

				$receiver_alias = (string) ( $payload['receiver_alias'] ?? '' );
				$invoice_serial = (string) ( $payload['invoice_serial'] ?? '' );
				$invoice_number = (string) ( $payload['invoice_number'] ?? '' );

				// WSDL: SendInvoiceRequest/INVOICE is tns:INVOICE, which declares the
				// required attribute TRXID (xs:long) plus the optional UUID and ID
				// attributes.
				$invoice_entry = array(
					'TRXID' => (int) ( $payload['trx_id'] ?? 0 ),
					'UUID'  => (string) ( $payload['uuid'] ?? '' ),
				);

				if ( '' !== $invoice_number ) {
					$invoice_entry['ID'] = $invoice_number;
				}

				$header = array(
					'SENDER'                          => $this->config->get_sender_vkn(),
					'RECEIVER'                        => (string) ( $payload['receiver_vkn'] ?? '' ),
					'FROM'                            => $this->config->get_sender_alias(),
					'PROFILEID'                       => (string) ( $payload['profile_id'] ?? 'EARSIVFATURA' ),
					'INVOICE_TYPE'                    => (string) ( $payload['invoice_type_code'] ?? 'SATIS' ),
					'ISSUE_DATE'                      => $issue_date,
					'PAYABLE_AMOUNT'                  => (string) ( $payload['payable_amount'] ?? '0.00' ),
					'INTERNETSALES'                   => (bool) ( $payload['is_internet_sales'] ?? true ),
					'EARCHIVE'                        => $is_earchive,
					'EARCHIVE_REPORT_SENDDATE'        => $issue_date,
					'CANCEL_EARCHIVE_REPORT_SENDDATE' => $issue_date,
					'ISACTIVE'                        => true,
					'MARKED'                          => false,
				);

				// WSDL: INVOICE/HEADER/TO is minOccurs="0" and RECEIVER/@alias is an
				// optional attribute. e-Arşiv recipients have no GİB mailbox, so both
				// are omitted instead of being filled with an invented alias.
				if ( '' !== $receiver_alias ) {
					$header['TO'] = $receiver_alias;
				}

				// WSDL: INVOICE/HEADER/INVOICESERIAL_REQUESTED (xs:token) binds the
				// document to a serial registered at EDM through CreateSerial /
				// GetInvoiceSerial.
				if ( '' !== $invoice_serial ) {
					$header['INVOICESERIAL_REQUESTED'] = $invoice_serial;
				}

				$invoice_entry['HEADER']  = $header;
				$invoice_entry['CONTENT'] = (string) ( $payload['ubl_xml'] ?? '' );

				$receiver = array( 'vkn' => (string) ( $payload['receiver_vkn'] ?? '' ) );
				if ( '' !== $receiver_alias ) {
					$receiver['alias'] = $receiver_alias;
				}

				$request = array(
					// CLIENT_TXN_ID stays bound to the document UUID: it is the
					// idempotency key EDM sees for this invoice.
					'REQUEST_HEADER' => self::build_request_header( (string) $session_id, 'SendInvoice', (string) ( $payload['uuid'] ?? '' ) ),
					'SENDER'         => array(
						'vkn'   => $this->config->get_sender_vkn(),
						'alias' => $this->config->get_sender_alias(),
					),
					'RECEIVER'       => $receiver,
					'INVOICE'        => array( $invoice_entry ),
				);

				$response = $this->transport->call( 'SendInvoice', $request );
				return $this->parse_send_invoice_response( $response, $payload['uuid'] ?? '', $payload['invoice_number'] ?? '' );
			}
		);
	}

	/**
	 * Query invoice status from EDM.
	 *
	 * @param string $uuid Invoice UUID.
	 * @param string $invoice_number Optional invoice ID.
	 * @return Kuka_Island_Core_Invoice_Result Status result.
	 */
	public function get_invoice_status( string $uuid, string $invoice_number = '' ): Kuka_Island_Core_Invoice_Result {
		return $this->execute_with_session(
			function ( string $session_id ) use ( $uuid, $invoice_number ): Kuka_Island_Core_Invoice_Result {
				$now_date = gmdate( 'Y-m-d' );
				$request  = array(
					'REQUEST_HEADER' => self::build_request_header( (string) $session_id, 'GetInvoiceStatus' ),
					'INVOICE'        => array(
						'UUID' => $uuid,
					),
					'START_DATE'     => $now_date,
					'END_DATE'       => $now_date,
					'CR_START_DATE'  => $now_date,
					'CR_END_DATE'    => $now_date,
				);

				if ( '' !== $invoice_number ) {
					$request['INVOICE']['ID'] = $invoice_number;
				}

				$response = $this->transport->call( 'GetInvoiceStatus', $request );
				return $this->parse_invoice_status_response( $response, $uuid, $invoice_number );
			}
		);
	}

	/**
	 * Retrieve invoice document (PDF, XML or HTML).
	 *
	 * @param string $uuid Invoice UUID.
	 * @param string $format 'PDF', 'XML' or 'HTML'.
	 * @return string Decoded document binary or XML string.
	 */
	public function get_invoice_document( string $uuid, string $format = 'PDF' ): string {
		return $this->execute_with_session(
			function ( string $session_id ) use ( $uuid, $format ): string {
				$request = array(
					'REQUEST_HEADER'       => self::build_request_header( (string) $session_id, 'GetInvoice' ),
					'INVOICE_SEARCH_KEY'   => array(
						'UUID' => $uuid,
					),
					'HEADER_ONLY'          => 'N',
					'INVOICE_CONTENT_TYPE' => strtoupper( $format ),
				);

				$response = $this->transport->call( 'GetInvoice', $request );
				return $this->parse_get_invoice_response( $response );
			}
		);
	}

	/**
	 * Request EDM to email the invoice to the customer.
	 */
	public function email_invoice( string $uuid, string $email, string $format = 'PDF' ): bool {
		if ( ! is_email( $email ) ) {
			return false;
		}

		return $this->execute_with_session(
			function ( string $session_id ) use ( $uuid, $email, $format ): bool {
				$request = array(
					'REQUEST_HEADER'       => self::build_request_header( (string) $session_id, 'EmailInvoice' ),
					'INVOICE'              => array(
						array(
							'UUID' => $uuid,
						),
					),
					'EMAILS'               => sanitize_email( $email ),
					'INVOICE_CONTENT_TYPE' => strtoupper( $format ),
				);

				$this->transport->call( 'EmailInvoice', $request );
				return true;
			}
		);
	}

	/**
	 * Query credit/counter status from EDM.
	 *
	 * @return array{counter_left: int}
	 */
	public function check_counter(): array {
		return $this->execute_with_session(
			function ( string $session_id ): array {
				$request = array(
					'REQUEST_HEADER' => self::build_request_header( (string) $session_id, 'CheckCounter' ),
				);

				$response = $this->transport->call( 'CheckCounter', $request );

				// WSDL: CheckCounterResponse/COUNTER_LEFT (xs:int, minOccurs="1").
				// PHP SoapClient may expose it at the top level or nested inside a
				// *Result wrapper, so look the exact field up recursively.
				$left = self::find_scalar_field( $response, 'COUNTER_LEFT' );

				return array(
					'counter_left' => null === $left ? 0 : (int) $left,
				);
			}
		);
	}

	/**
	 * Query the invoice serials registered at EDM (read-only).
	 *
	 * Verified WSDL contract:
	 * - `GetInvoiceSerialRequest` extends tns:REQUEST with INVOICESERIALCODE
	 *   (xs:token, optional), INVOICESENDTYPE (xs:token, optional) and YEAR
	 *   (xs:int, required).
	 * - `GetInvoiceSerialResponse/Items` is tns:GetInvoiceSerialResponseX, whose
	 *   `Items` entries are tns:INVOICESERIALLIST records carrying
	 *   INVOICESERIALCODE, YEAR, LASTSERIALUSED (xs:int nillable),
	 *   LASTINVOICEDATEUSED, COMPANYNAME, COMPANYID and SOURCESYSTEMNAME.
	 *
	 * This operation reports serial state; it does not reserve or hand out the
	 * next document number, which is why Kuka_Island_Core_Invoice_Numbering stays
	 * fail-closed. Writing serials (`CreateSerial`) is deliberately NOT
	 * implemented: registering a fiscal serial is a provisioning act for the
	 * accountant / EDM portal, not something an automated web request performs.
	 *
	 * @param string $serial_code Optional 3-character serial code filter.
	 * @param int    $year Fiscal year (defaults to the current UTC year).
	 * @param string $send_type Optional INVOICESENDTYPE filter.
	 * @return array{serials: array<int, array{code: string, year: int, last_serial_used: int}>}
	 */
	public function get_invoice_serial( string $serial_code = '', int $year = 0, string $send_type = '' ): array {
		$fiscal_year = $year > 0 ? $year : (int) gmdate( 'Y' );

		return $this->execute_with_session(
			function ( string $session_id ) use ( $serial_code, $fiscal_year, $send_type ): array {
				$request = array(
					'REQUEST_HEADER' => self::build_request_header( (string) $session_id, 'GetInvoiceSerial' ),
					'YEAR'           => $fiscal_year,
				);

				if ( '' !== $serial_code ) {
					$request['INVOICESERIALCODE'] = $serial_code;
				}
				if ( '' !== $send_type ) {
					$request['INVOICESENDTYPE'] = $send_type;
				}

				$response = $this->transport->call( 'GetInvoiceSerial', $request );

				return $this->parse_invoice_serial_response( $response );
			}
		);
	}

	/* --------------------------------------------------------------------- */
	/* Session Guard & Auto-retry                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * Execute a SOAP call with session management.
	 *
	 * If the session is missing or has expired, logs in and retries AT MOST ONCE.
	 *
	 * @template T
	 * @param callable(string): T $callback
	 * @return T
	 */
	private function execute_with_session( callable $callback ) {
		if ( empty( $this->session_id ) ) {
			$this->login();
		}

		try {
			return $callback( (string) $this->session_id );
		} catch ( SoapFault $fault ) {
			if ( $this->is_session_expired_fault( $fault ) ) {
				// Clear expired session and attempt single login retry.
				$this->session_id = null;
				$this->login();
				try {
					return $callback( (string) $this->session_id );
				} catch ( SoapFault $retry_fault ) {
					$this->handle_soap_fault( $retry_fault );
				}
			}

			$this->handle_soap_fault( $fault );
		} catch ( Kuka_Island_Core_Invoice_Exception $e ) {
			throw $e;
		} catch ( Exception $e ) {
			throw new Kuka_Island_Core_Invoice_Transient_Exception(
				'EDM connection / network error.',
				'edm_network_error',
				__( 'EDM servisi ile bağlantı kurulurken hata oluştu.', 'kuka-island-core' )
			);
		}
	}

	private function is_session_expired_fault( SoapFault $fault ): bool {
		$msg = strtolower( (string) $fault->getMessage() );
		return str_contains( $msg, 'aktif session' )
			|| str_contains( $msg, 'session bulunamadı' )
			|| str_contains( $msg, 'session expired' )
			|| str_contains( $msg, 'oturum süresi' )
			|| str_contains( $msg, 'geçersiz session' )
			|| str_contains( $msg, 'invalid session' );
	}

	/**
	 * Differentiate SOAP Faults into transient vs permanent exceptions without
	 * leaking secrets, payload details or remote fault text.
	 *
	 * The document-level rejection markers are kept, because they carry
	 * business meaning the generic classifier does not model. Everything else
	 * is handed to Kuka_Island_Core_EDM_Fault_Classifier, and in both cases the
	 * exception carries only the safe verdict -- never the message.
	 */
	private function handle_soap_fault( SoapFault $fault ): never {
		$verdict = Kuka_Island_Core_EDM_Fault_Classifier::classify(
			isset( $fault->faultcode ) ? (string) $fault->faultcode : '',
			(string) $fault->getMessage()
		);

		$msg = strtolower( (string) $fault->getMessage() );

		// Permanent business validation / duplicate / permission errors. These
		// are document-level verdicts, so they stay distinct from the transport
		// vocabulary above.
		if ( str_contains( $msg, 'aynı fatura' )
			|| str_contains( $msg, 'mükerrer' )
			|| str_contains( $msg, 'duplicate' )
			|| str_contains( $msg, 'şema' )
			|| str_contains( $msg, 'schema' )
			|| str_contains( $msg, 'geçersiz vkn' )
			|| str_contains( $msg, 'kullanıcı bulunamadı' )
			|| str_contains( $msg, 'yetkisiz' ) ) {
			throw ( new Kuka_Island_Core_Invoice_Permanent_Exception(
				'EDM business rejection.',
				'edm_business_rejection',
				__( 'Fatura EDM tarafından iş kuralı veya şema hatası sebebiyle reddedildi.', 'kuka-island-core' )
			) )->set_diagnostic( $verdict );
		}

		if ( ! (bool) ( $verdict['retryable'] ?? true ) ) {
			throw ( new Kuka_Island_Core_Invoice_Permanent_Exception(
				'EDM refused the request.',
				'edm_request_refused',
				__( 'EDM isteği kalıcı olarak reddetti.', 'kuka-island-core' )
			) )->set_diagnostic( $verdict );
		}

		// Transient / network / timeout errors.
		throw ( new Kuka_Island_Core_Invoice_Transient_Exception(
			'EDM SOAP Fault.',
			'edm_soap_fault',
			__( 'EDM servisi geçici bir hata bildirdi. İşlem tekrar denenebilir.', 'kuka-island-core' )
		) )->set_diagnostic( $verdict );
	}

	/* --------------------------------------------------------------------- */
	/* Response Parsers                                                      */
	/* --------------------------------------------------------------------- */

	private function extract_session_id_from_response( $response ): string {
		if ( is_string( $response ) ) {
			return trim( $response );
		}
		if ( is_object( $response ) ) {
			if ( ! empty( $response->SESSION_ID ) ) {
				return trim( (string) $response->SESSION_ID );
			}
			if ( ! empty( $response->SessionID ) ) {
				return trim( (string) $response->SessionID );
			}
			if ( ! empty( $response->LoginResult ) ) {
				return trim( (string) $response->LoginResult );
			}
		}
		if ( is_array( $response ) ) {
			if ( ! empty( $response['SESSION_ID'] ) ) {
				return trim( (string) $response['SESSION_ID'] );
			}
			if ( ! empty( $response['SessionID'] ) ) {
				return trim( (string) $response['SessionID'] );
			}
		}

		return '';
	}

	private function parse_check_user_response( $response, string $clean_id ): array {
		$users = array();
		if ( is_object( $response ) && isset( $response->USER ) ) {
			$raw_user = $response->USER;
			$users    = is_array( $raw_user ) ? $raw_user : array( $raw_user );
		} elseif ( is_array( $response ) && isset( $response['USER'] ) ) {
			$raw_user = $response['USER'];
			$users    = is_array( $raw_user ) ? $raw_user : array( $raw_user );
		} elseif ( is_array( $response ) ) {
			$users = $response;
		}

		if ( empty( $users ) ) {
			return array(
				'is_einvoice_user' => false,
				'title'            => '',
				'alias'            => '',
				'register_time'    => '',
				'raw_data'         => array(),
			);
		}

		$first_user = (object) $users[0];
		$title      = (string) ( $first_user->TITLE ?? ( $first_user->NAME ?? '' ) );
		$alias      = (string) ( $first_user->ALIAS ?? ( $first_user->DEFAULT_PK ?? '' ) );
		$reg_time   = (string) ( $first_user->REGISTER_TIME ?? '' );

		return array(
			'is_einvoice_user' => '' !== $alias,
			'title'            => $title,
			'alias'            => $alias,
			'register_time'    => $reg_time,
			'raw_data'         => (array) $first_user,
		);
	}

	private function parse_send_invoice_response( $response, string $expected_uuid, string $expected_number ): Kuka_Island_Core_Invoice_Result {
		$invoice_obj = null;
		$status_code = 'SUCCESS';
		$status_desc = 'Fatura EDM sistemine iletildi.';

		if ( is_object( $response ) ) {
			$invoice_obj = $response->INVOICE ?? ( $response->INVOICE_STATUS ?? null );
			if ( isset( $response->REQUEST_RETURN->RETURN_CODE ) ) {
				$status_code = (string) $response->REQUEST_RETURN->RETURN_CODE;
			}
		} elseif ( is_array( $response ) ) {
			$invoice_obj = $response['INVOICE'] ?? ( $response['INVOICE_STATUS'] ?? null );
			if ( isset( $response['REQUEST_RETURN']['RETURN_CODE'] ) ) {
				$status_code = (string) $response['REQUEST_RETURN']['RETURN_CODE'];
			}
		}

		if ( is_array( $invoice_obj ) && ! empty( $invoice_obj[0] ) ) {
			$invoice_obj = $invoice_obj[0];
		}

		$invoice_obj = is_object( $invoice_obj ) ? $invoice_obj : (object) ( is_array( $invoice_obj ) ? $invoice_obj : array() );

		$uuid           = (string) ( $invoice_obj->UUID ?? ( $invoice_obj->GUID ?? $expected_uuid ) );
		$invoice_number = (string) ( $invoice_obj->ID ?? ( $invoice_obj->INVOICE_NUMBER ?? $expected_number ) );

		if ( ! empty( $invoice_obj->STATUS ) ) {
			$status_code = (string) $invoice_obj->STATUS;
		}
		if ( ! empty( $invoice_obj->STATUS_DESCRIPTION ) ) {
			$status_desc = (string) $invoice_obj->STATUS_DESCRIPTION;
		}

		$status = match ( true ) {
			str_contains( strtoupper( $status_code ), 'SUCCESS' ) || str_contains( strtoupper( $status_code ), '1300' ) || str_contains( strtoupper( $status_code ), 'GIB_SUCCESS' ) || str_contains( strtoupper( $status_code ), 'KABUL' ) || '0' === $status_code => Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED,
			str_contains( strtoupper( $status_code ), 'REJECT' ) || str_contains( strtoupper( $status_code ), 'ERROR' ) || str_contains( strtoupper( $status_code ), 'RED' ) => Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
			default => Kuka_Island_Core_Invoice_Status::STATUS_SENT,
		};

		return Kuka_Island_Core_Invoice_Result::success(
			$uuid,
			$invoice_number,
			$status,
			$status_code,
			$status_desc,
			(array) $invoice_obj
		);
	}

	private function parse_invoice_status_response( $response, string $uuid, string $invoice_number ): Kuka_Island_Core_Invoice_Result {
		$status_obj = is_object( $response ) ? ( $response->INVOICE_STATUS ?? $response ) : ( is_array( $response ) ? ( $response['INVOICE_STATUS'] ?? $response ) : null );
		if ( is_array( $status_obj ) && ! empty( $status_obj[0] ) ) {
			$status_obj = $status_obj[0];
		}
		$status_obj = is_object( $status_obj ) ? $status_obj : (object) ( is_array( $status_obj ) ? $status_obj : array() );

		$res_uuid   = (string) ( $status_obj->UUID ?? ( $status_obj->GUID ?? $uuid ) );
		$res_num    = (string) ( $status_obj->ID ?? ( $status_obj->INVOICE_NUMBER ?? $invoice_number ) );
		$code       = (string) ( $status_obj->STATUS_CODE ?? ( $status_obj->GIB_STATUS_CODE ?? ( $status_obj->STATUS ?? '' ) ) );
		$desc       = (string) ( $status_obj->STATUS_DESCRIPTION ?? ( $status_obj->GIB_STATUS_DESCRIPTION ?? '' ) );

		$status = match ( true ) {
			str_contains( strtoupper( $code ), 'SUCCESS' ) || str_contains( strtoupper( $code ), '1300' ) || str_contains( strtoupper( $code ), 'GIB_SUCCESS' ) || str_contains( strtoupper( $code ), 'KABUL' ) || '0' === $code => Kuka_Island_Core_Invoice_Status::STATUS_COMPLETED,
			str_contains( strtoupper( $code ), 'REJECT' ) || str_contains( strtoupper( $code ), 'ERROR' ) || str_contains( strtoupper( $code ), 'RED' ) => Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
			default => Kuka_Island_Core_Invoice_Status::STATUS_PENDING_APPROVAL,
		};

		return Kuka_Island_Core_Invoice_Result::success(
			$res_uuid,
			$res_num,
			$status,
			$code,
			$desc,
			(array) $status_obj
		);
	}

	/**
	 * Parse GetInvoiceSerialResponse into a flat serial list.
	 *
	 * @param mixed $response SOAP response.
	 * @return array{serials: array<int, array{code: string, year: int, last_serial_used: int}>}
	 */
	private function parse_invoice_serial_response( $response ): array {
		$serials = array();

		foreach ( self::collect_records( $response, 'INVOICESERIALCODE' ) as $record ) {
			$serials[] = array(
				'code'             => (string) ( $record['INVOICESERIALCODE'] ?? '' ),
				'year'             => (int) ( $record['YEAR'] ?? 0 ),
				'last_serial_used' => (int) ( $record['LASTSERIALUSED'] ?? 0 ),
			);
		}

		return array( 'serials' => $serials );
	}

	/**
	 * Recursively find the first scalar value stored under an exact key.
	 *
	 * @param mixed  $node Response node.
	 * @param string $field Exact field name.
	 * @return int|float|string|bool|null
	 */
	private static function find_scalar_field( $node, string $field ) {
		if ( is_object( $node ) ) {
			$node = get_object_vars( $node );
		}
		if ( ! is_array( $node ) ) {
			return null;
		}

		if ( array_key_exists( $field, $node ) && is_scalar( $node[ $field ] ) ) {
			return $node[ $field ];
		}

		foreach ( $node as $child ) {
			$found = self::find_scalar_field( $child, $field );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Recursively collect every associative record that contains a marker key.
	 *
	 * @param mixed  $node Response node.
	 * @param string $marker_field Key that identifies a record.
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_records( $node, string $marker_field ): array {
		if ( is_object( $node ) ) {
			$node = get_object_vars( $node );
		}
		if ( ! is_array( $node ) ) {
			return array();
		}

		if ( array_key_exists( $marker_field, $node ) ) {
			return array( $node );
		}

		$found = array();
		foreach ( $node as $child ) {
			foreach ( self::collect_records( $child, $marker_field ) as $record ) {
				$found[] = $record;
			}
		}

		return $found;
	}

	private function parse_get_invoice_response( $response ): string {
		if ( is_string( $response ) ) {
			$decoded = base64_decode( $response, true );
			return false !== $decoded ? $decoded : $response;
		}

		if ( is_object( $response ) ) {
			$content = $response->INVOICE->CONTENT ?? ( $response->CONTENT ?? ( $response->INVOICE ?? null ) );
			if ( is_string( $content ) ) {
				$decoded = base64_decode( $content, true );
				return false !== $decoded ? $decoded : $content;
			}
		}

		if ( is_array( $response ) ) {
			$content = $response['INVOICE']['CONTENT'] ?? ( $response['CONTENT'] ?? null );
			if ( is_string( $content ) ) {
				$decoded = base64_decode( $content, true );
				return false !== $decoded ? $decoded : $content;
			}
		}

		return '';
	}
}
