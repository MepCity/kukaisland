<?php
/**
 * The DHL eCommerce Türkiye HTTP client.
 *
 * Every path, method and field name in this file is transcribed from the five
 * official OpenAPI documents and from nothing else. Where the vendor's spelling
 * looks like a mistake it is still used verbatim, because the server implements
 * the spelling, not the intention:
 *
 *   Standard_Command_API-1.0.json  POST /createOrder            (camelCase)
 *                                  PUT  /updateorder            (all lower)
 *                                  PUT  /cancelorder/{refrenceId}
 *                                       -- the vendor's own parameter name is
 *                                          spelled "refrenceId"; only the
 *                                          position matters in the URL, but the
 *                                          spelling is recorded here so nobody
 *                                          "fixes" it against the document.
 *   Barcode_Command_API-1.0.json   POST /createbarcode
 *                                  PUT  /updateshipment
 *                                  PUT  /cancelshipment
 *   Standard_Query_API-1.0.json    GET  /getorder/{referenceId}
 *                                  GET  /getshipment/{referenceId}
 *                                  GET  /getshipmentstatus/{referenceId}
 *                                  GET  /trackshipment/{referenceId}
 *   CBS_Info_API-1.0.json          GET  /getcities
 *                                  GET  /getdistricts/{cityCode}
 *
 * Three rules hold throughout.
 *
 * WRITES ARE NEVER RETRIED. Not on a timeout, not on a 5xx, not on a 401. The
 * is_write flag travels with every request and the retry branch is unreachable
 * when it is true. A read may be repeated once after re-authentication because
 * repeating a read cannot create a parcel.
 *
 * NO RESPONSE TEXT LEAVES THIS CLASS. Bodies are parsed for the specific
 * documented fields and everything else is dropped. Failures are reported as
 * the classifier's own codes. Nothing writes a body, a header or an exception
 * message to a log, an order note or a return value.
 *
 * NOTHING IS SENT WITHOUT AN ALLOWED URL. Every request URL is checked against
 * the configuration's allow-list immediately before it is used, so an appended
 * path segment cannot redirect a request off the sandbox host.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_DHL_Client {

	private Kuka_Island_Shipping_DHL_Config $config;
	private Kuka_Island_Shipping_HTTP_Transport_Interface $transport;
	private Kuka_Island_Shipping_DHL_Token_Store $tokens;

	/**
	 * How the JWT is presented in the Authorization header.
	 *
	 * Identity_API-1.0.json returns a field called 'jwt'; the command and query
	 * documents declare a required header called 'Authorization' of type string
	 * and say nothing at all about its format. 'Bearer <jwt>' is the convention
	 * a JWT is normally carried by, so it is the default -- but it IS a
	 * convention, not something the vendor wrote down, so it is a single
	 * switchable value rather than a hard-coded string, and it is recorded in
	 * the maintenance notes as unverified until a sandbox call settles it.
	 * KUKA_DHL_AUTHORIZATION_SCHEME=raw sends the token on its own.
	 */
	private string $authorization_scheme;

	public function __construct(
		Kuka_Island_Shipping_DHL_Config $config,
		?Kuka_Island_Shipping_HTTP_Transport_Interface $transport = null,
		?Kuka_Island_Shipping_DHL_Token_Store $tokens = null
	) {
		$this->config    = $config;
		$this->transport = $transport ?? new Kuka_Island_Shipping_DHL_HTTP_Transport();
		$this->tokens    = $tokens ?? new Kuka_Island_Shipping_DHL_Token_Store();

		$scheme = defined( 'KUKA_DHL_AUTHORIZATION_SCHEME' ) ? strtolower( trim( (string) KUKA_DHL_AUTHORIZATION_SCHEME ) ) : 'bearer';
		$this->authorization_scheme = 'raw' === $scheme ? 'raw' : 'bearer';
	}

	public function get_config(): Kuka_Island_Shipping_DHL_Config {
		return $this->config;
	}

	public function get_token_store(): Kuka_Island_Shipping_DHL_Token_Store {
		return $this->tokens;
	}

	/* ---------------------------------------------------------------------- */
	/* Identity                                                                */
	/* ---------------------------------------------------------------------- */

	/**
	 * Obtain (or reuse) a session. Read-only: nothing at the carrier changes.
	 */
	public function authenticate(): Kuka_Island_Shipping_Result {
		$gate = $this->preflight( false );

		if ( null !== $gate ) {
			return $gate;
		}

		$session = $this->tokens->acquire( $this->config, $this->transport );

		if ( ! $session['ok'] ) {
			return new Kuka_Island_Shipping_Result( $session['outcome'], 'authenticate', array(), $session['code'], $session['http'] );
		}

		return Kuka_Island_Shipping_Result::success(
			'authenticate',
			array(
				// The token itself is deliberately absent. Callers need to know
				// that a session exists, never what it is.
				'authenticated' => true,
				'issued_calls'  => $this->tokens->get_issue_count(),
			),
			$session['http'] > 0 ? $session['http'] : 200
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Standard Command                                                        */
	/* ---------------------------------------------------------------------- */

	/**
	 * POST /createOrder
	 *
	 * @param array<string, mixed> $payload Request body, already in vendor shape.
	 */
	public function create_order( array $payload ): Kuka_Island_Shipping_Result {
		return $this->call(
			'create_order',
			'POST',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_STANDARD_CMD_URL . '/createOrder',
			$payload,
			true,
			static function ( $decoded ): ?array {
				if ( ! is_array( $decoded ) ) {
					return null;
				}

				$reference   = trim( (string) ( $decoded['referenceId'] ?? '' ) );
				$invoice_id  = trim( (string) ( $decoded['orderInvoiceId'] ?? '' ) );
				$detail_id   = trim( (string) ( $decoded['orderInvoiceDetailId'] ?? '' ) );
				$branch_code = trim( (string) ( $decoded['shipperBranchCode'] ?? '' ) );

				// A 200 that carries neither identifier tells us nothing about
				// whether the order exists. Refusing to call that a success is
				// what sends the caller to reconciliation instead of onward.
				if ( '' === $reference && '' === $invoice_id ) {
					return null;
				}

				return array(
					'reference_id'           => $reference,
					'order_invoice_id'       => $invoice_id,
					'order_invoice_detail_id' => $detail_id,
					'shipper_branch_code'    => $branch_code,
				);
			}
		);
	}

	/**
	 * PUT /updateorder
	 *
	 * @param array<string, mixed> $payload Request body.
	 */
	public function update_order( array $payload ): Kuka_Island_Shipping_Result {
		return $this->call(
			'update_order',
			'PUT',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_STANDARD_CMD_URL . '/updateorder',
			$payload,
			true,
			// Documented response schema is a bare string ("Order updated"), so
			// a 2xx is the whole answer and there is no field to require.
			static fn ( $decoded ): ?array => array( 'acknowledged' => true )
		);
	}

	/**
	 * PUT /cancelorder/{refrenceId}
	 *
	 * @param string $reference Carrier reference.
	 */
	public function cancel_order( string $reference ): Kuka_Island_Shipping_Result {
		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
			return Kuka_Island_Shipping_Result::permanent( 'cancel_order', 'bad_request' );
		}

		return $this->call(
			'cancel_order',
			'PUT',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_STANDARD_CMD_URL . '/cancelorder/' . rawurlencode( $reference ),
			null,
			true,
			static fn ( $decoded ): ?array => array( 'acknowledged' => true )
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Barcode Command                                                         */
	/* ---------------------------------------------------------------------- */

	/**
	 * POST /createbarcode
	 *
	 * @param array<string, mixed> $payload Request body.
	 */
	public function create_barcode( array $payload ): Kuka_Island_Shipping_Result {
		return $this->call(
			'create_barcode',
			'POST',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_BARCODE_CMD_URL . '/createbarcode',
			$payload,
			true,
			static function ( $decoded ): ?array {
				if ( ! is_array( $decoded ) ) {
					return null;
				}

				$shipment_id = trim( (string) ( $decoded['shipmentId'] ?? '' ) );

				if ( '' === $shipment_id ) {
					return null;
				}

				$values = array();
				foreach ( (array) ( $decoded['barcodes'] ?? array() ) as $barcode ) {
					if ( ! is_array( $barcode ) ) {
						continue;
					}

					$value = trim( (string) ( $barcode['value'] ?? '' ) );
					if ( '' !== $value ) {
						$values[] = $value;
					}
				}

				return array(
					'shipment_id'  => $shipment_id,
					'reference_id' => trim( (string) ( $decoded['referenceId'] ?? '' ) ),
					'invoice_id'   => trim( (string) ( $decoded['invoiceId'] ?? '' ) ),
					// Flattened to a string because Result holds scalars only;
					// the store splits it back into a list.
					'barcodes'     => implode( ',', $values ),
					'barcode_count' => count( $values ),
				);
			}
		);
	}

	/**
	 * PUT /updateshipment
	 *
	 * @param array<string, mixed> $payload Request body.
	 */
	public function update_shipment( array $payload ): Kuka_Island_Shipping_Result {
		return $this->call(
			'update_shipment',
			'PUT',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_BARCODE_CMD_URL . '/updateshipment',
			$payload,
			true,
			static fn ( $decoded ): ?array => array( 'acknowledged' => true )
		);
	}

	/**
	 * PUT /cancelshipment
	 *
	 * @param string $reference   Carrier reference.
	 * @param string $shipment_id Carrier shipment id.
	 */
	public function cancel_shipment( string $reference, string $shipment_id ): Kuka_Island_Shipping_Result {
		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) || '' === trim( $shipment_id ) ) {
			return Kuka_Island_Shipping_Result::permanent( 'cancel_shipment', 'bad_request' );
		}

		return $this->call(
			'cancel_shipment',
			'PUT',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_BARCODE_CMD_URL . '/cancelshipment',
			array(
				'referenceId' => $reference,
				'shipmentId'  => trim( $shipment_id ),
			),
			true,
			static fn ( $decoded ): ?array => array( 'acknowledged' => true )
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Standard Query -- all read-only                                         */
	/* ---------------------------------------------------------------------- */

	/**
	 * GET /getorder/{referenceId}
	 *
	 * @param string $reference Carrier reference.
	 */
	public function get_order( string $reference ): Kuka_Island_Shipping_Result {
		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
			return Kuka_Island_Shipping_Result::permanent( 'get_order', 'bad_request' );
		}

		return $this->call(
			'get_order',
			'GET',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_STANDARD_QUERY_URL . '/getorder/' . rawurlencode( $reference ),
			null,
			false,
			static function ( $decoded ): ?array {
				if ( ! is_array( $decoded ) || ! isset( $decoded['order'] ) || ! is_array( $decoded['order'] ) ) {
					return null;
				}

				$order = $decoded['order'];

				return array(
					'reference_id'              => trim( (string) ( $order['referenceId'] ?? '' ) ),
					'shipment_id'               => trim( (string) ( $order['shipmentId'] ?? '' ) ),
					'is_transformed_to_shipment' => (int) ( $order['isTransformedToShipment'] ?? 0 ),
					'exists'                    => true,
				);
			}
		);
	}

	/**
	 * GET /getshipment/{referenceId}
	 *
	 * @param string $reference Carrier reference.
	 */
	public function get_shipment( string $reference ): Kuka_Island_Shipping_Result {
		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
			return Kuka_Island_Shipping_Result::permanent( 'get_shipment', 'bad_request' );
		}

		return $this->call(
			'get_shipment',
			'GET',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_STANDARD_QUERY_URL . '/getshipment/' . rawurlencode( $reference ),
			null,
			false,
			static function ( $decoded ): ?array {
				if ( ! is_array( $decoded ) || ! isset( $decoded['shipment'] ) || ! is_array( $decoded['shipment'] ) ) {
					return null;
				}

				$shipment = $decoded['shipment'];

				return array(
					'reference_id'  => trim( (string) ( $shipment['referenceId'] ?? '' ) ),
					'shipment_id'   => trim( (string) ( $shipment['shipmentId'] ?? '' ) ),
					// Passed through unnormalised on purpose: the dictionary
					// decides what an unrecognised value means, not the adapter.
					'status_code'   => $shipment['shipmentStatusCode'] ?? '',
					'is_delivered'  => (int) ( $shipment['isDelivered'] ?? 0 ),
					'piece_count'   => (int) ( $shipment['pieceCount'] ?? 0 ),
					'exists'        => true,
				);
			}
		);
	}

	/**
	 * GET /getshipmentstatus/{referenceId}
	 *
	 * @param string $reference Carrier reference.
	 */
	public function get_shipment_status( string $reference ): Kuka_Island_Shipping_Result {
		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
			return Kuka_Island_Shipping_Result::permanent( 'get_shipment_status', 'bad_request' );
		}

		return $this->call(
			'get_shipment_status',
			'GET',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_STANDARD_QUERY_URL . '/getshipmentstatus/' . rawurlencode( $reference ),
			null,
			false,
			static function ( $decoded ): ?array {
				if ( ! is_array( $decoded ) ) {
					return null;
				}

				if ( ! array_key_exists( 'shipmentStatusCode', $decoded ) && ! array_key_exists( 'shipmentId', $decoded ) ) {
					return null;
				}

				return array(
					'reference_id'  => trim( (string) ( $decoded['referenceId'] ?? '' ) ),
					'shipment_id'   => trim( (string) ( $decoded['shipmentId'] ?? '' ) ),
					'status_code'   => $decoded['shipmentStatusCode'] ?? '',
					'is_delivered'  => (int) ( $decoded['isDelivered'] ?? 0 ),
					'tracking_url'  => trim( (string) ( $decoded['trackingUrl'] ?? '' ) ),
				);
			}
		);
	}

	/**
	 * GET /trackshipment/{referenceId}
	 *
	 * The documented 200 schema is an ARRAY of movement events. Only the count
	 * and the latest event's status code are kept: event text carries locations,
	 * addresses, phone numbers and recipient names, none of which belongs on an
	 * order note or in a verification output.
	 *
	 * @param string $reference Carrier reference.
	 */
	public function track_shipment( string $reference ): Kuka_Island_Shipping_Result {
		if ( ! Kuka_Island_Shipping_Reference::is_valid( $reference ) ) {
			return Kuka_Island_Shipping_Result::permanent( 'track_shipment', 'bad_request' );
		}

		return $this->call(
			'track_shipment',
			'GET',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_STANDARD_QUERY_URL . '/trackshipment/' . rawurlencode( $reference ),
			null,
			false,
			static function ( $decoded ): ?array {
				if ( ! is_array( $decoded ) ) {
					return null;
				}

				$events = array_values( array_filter( $decoded, 'is_array' ) );
				$latest = array() !== $events ? $events[ count( $events ) - 1 ] : array();

				return array(
					'event_count' => count( $events ),
					'status_code' => $latest['eventStatus'] ?? '',
					'shipment_id' => trim( (string) ( $latest['shipmentId'] ?? '' ) ),
				);
			}
		);
	}

	/* ---------------------------------------------------------------------- */
	/* CBS Info -- read-only reference data                                    */
	/* ---------------------------------------------------------------------- */

	/**
	 * GET /getcities
	 *
	 * CBS_Info_API-1.0.json declares NO Authorization parameter on any of its
	 * operations -- only x-api-version, plus the gateway keys from the global
	 * security block. The bearer token is therefore not sent here. That is what
	 * the document says; it is flagged in the maintenance notes as a behaviour
	 * to confirm on the first sandbox call rather than assumed to be complete.
	 *
	 * @return array{ok: bool, cities: array<int, array{code: string, name: string}>, result: Kuka_Island_Shipping_Result}
	 */
	public function get_cities(): array {
		$result = $this->call(
			'get_cities',
			'GET',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_CBS_INFO_URL . '/getcities',
			null,
			false,
			static fn ( $decoded ): ?array => is_array( $decoded ) ? array( 'count' => count( $decoded ) ) : null,
			false,
			$rows
		);

		return array(
			'ok'     => $result->is_success(),
			'cities' => $result->is_success() ? self::normalize_places( $rows ) : array(),
			'result' => $result,
		);
	}

	/**
	 * GET /getdistricts/{cityCode}
	 *
	 * @param string $city_code City code from getcities.
	 * @return array{ok: bool, districts: array<int, array{code: string, name: string}>, result: Kuka_Island_Shipping_Result}
	 */
	public function get_districts( string $city_code ): array {
		$city_code = trim( $city_code );

		if ( 1 !== preg_match( '/^[0-9]{1,10}$/', $city_code ) ) {
			return array(
				'ok'        => false,
				'districts' => array(),
				'result'    => Kuka_Island_Shipping_Result::permanent( 'get_districts', 'bad_request' ),
			);
		}

		$result = $this->call(
			'get_districts',
			'GET',
			Kuka_Island_Shipping_DHL_Config::SANDBOX_CBS_INFO_URL . '/getdistricts/' . rawurlencode( $city_code ),
			null,
			false,
			static fn ( $decoded ): ?array => is_array( $decoded ) ? array( 'count' => count( $decoded ) ) : null,
			false,
			$rows
		);

		return array(
			'ok'        => $result->is_success(),
			'districts' => $result->is_success() ? self::normalize_places( $rows ) : array(),
			'result'    => $result,
		);
	}

	/**
	 * Reduce a CBS listing to code/name pairs.
	 *
	 * GetCitiesResponse and GetDistrictsResponse both carry 'code' and 'name';
	 * the district rows additionally carry cityCode/cityName, which are dropped
	 * because the caller already knows the city it asked about.
	 *
	 * @param mixed $rows Decoded body.
	 * @return array<int, array{code: string, name: string}>
	 */
	private static function normalize_places( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$places = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$code = trim( (string) ( $row['code'] ?? '' ) );
			$name = trim( (string) ( $row['name'] ?? '' ) );

			if ( '' === $code || '' === $name ) {
				continue;
			}

			$places[] = array(
				'code' => $code,
				'name' => $name,
			);
		}

		return $places;
	}

	/* ---------------------------------------------------------------------- */
	/* Request pipeline                                                        */
	/* ---------------------------------------------------------------------- */

	/**
	 * Refuse before the network when anything is not in order.
	 *
	 * @param bool $is_write Whether the intended call would change carrier state.
	 * @return Kuka_Island_Shipping_Result|null Null when the call may proceed.
	 */
	private function preflight( bool $is_write ): ?Kuka_Island_Shipping_Result {
		if ( Kuka_Island_Shipping_Runtime_Gate::is_disabled() ) {
			return Kuka_Island_Shipping_Result::permanent( 'preflight', Kuka_Island_Shipping_Runtime_Gate::CODE );
		}

		if ( $this->config->is_live_blocked() ) {
			return Kuka_Island_Shipping_Result::permanent( 'preflight', 'live_environment_blocked' );
		}

		if ( array() !== $this->config->get_readiness_gaps() ) {
			return Kuka_Island_Shipping_Result::permanent( 'preflight', 'credentials_missing' );
		}

		if ( $is_write && ! $this->config->is_ready() ) {
			return Kuka_Island_Shipping_Result::permanent( 'preflight', 'credentials_missing' );
		}

		return null;
	}

	/**
	 * One carrier call, from preflight to parsed result.
	 *
	 * @param string               $operation   Logical operation name.
	 * @param string               $method      HTTP method.
	 * @param string               $url         Absolute URL.
	 * @param array<string,mixed>|null $payload Body, or null for no body.
	 * @param bool                 $is_write    Whether this can change carrier state.
	 * @param callable             $parser      Turns the decoded body into scalars, or null when unusable.
	 * @param bool                 $retried     Internal: true on the single post-401 read retry.
	 * @param mixed                $raw_decoded Out-parameter receiving the decoded body for list endpoints.
	 */
	private function call(
		string $operation,
		string $method,
		string $url,
		?array $payload,
		bool $is_write,
		callable $parser,
		bool $retried = false,
		&$raw_decoded = null
	): Kuka_Island_Shipping_Result {
		$raw_decoded = null;

		$gate = $this->preflight( $is_write );

		if ( null !== $gate ) {
			return new Kuka_Island_Shipping_Result( $gate->get_outcome(), $operation, array(), $gate->get_safe_error_code(), 0 );
		}

		if ( ! $this->config->is_allowed_url( $url ) ) {
			return Kuka_Island_Shipping_Result::permanent( $operation, 'endpoint_not_allowed' );
		}

		$needs_token = ! self::is_cbs_operation( $operation );
		$headers     = array(
			'Accept'              => 'application/json',
			'X-IBM-Client-Id'     => $this->config->get_client_id(),
			'X-IBM-Client-Secret' => $this->config->get_client_secret(),
		);

		if ( $needs_token ) {
			$session = $this->tokens->acquire( $this->config, $this->transport );

			if ( ! $session['ok'] ) {
				/*
				 * Authentication never reached the operation, so nothing at the
				 * carrier can have changed -- not even for a write. The outcome
				 * is reported as the authentication's own, which for a timeout
				 * is transient rather than uncertain.
				 */
				return new Kuka_Island_Shipping_Result( $session['outcome'], $operation, array(), $session['code'], $session['http'] );
			}

			$headers['Authorization'] = 'raw' === $this->authorization_scheme
				? $session['token']
				: 'Bearer ' . $session['token'];
		}

		$body = '';

		if ( null !== $payload ) {
			$encoded = wp_json_encode( $payload );

			if ( ! is_string( $encoded ) ) {
				return Kuka_Island_Shipping_Result::permanent( $operation, 'bad_request' );
			}

			$body                    = $encoded;
			$headers['Content-Type'] = 'application/json';
		}

		$response = $this->transport->request( $method, $url, $headers, $body, $this->config->get_timeout() );

		$decoded = null;
		if ( '' !== (string) $response['body'] ) {
			$candidate = json_decode( (string) $response['body'], true );
			$decoded   = ( JSON_ERROR_NONE === json_last_error() ) ? $candidate : null;
		}

		$parsed = null;
		if ( 200 <= (int) $response['status'] && (int) $response['status'] < 300 ) {
			$parsed = $parser( $decoded );
		}

		$verdict = Kuka_Island_Shipping_Fault_Classifier::classify(
			(int) $response['status'],
			(string) $response['error'],
			is_array( $parsed ),
			$is_write
		);

		/*
		 * A 401 on a READ may mean the cached session expired between the
		 * expiry estimate and the call. Dropping the token and repeating once
		 * is safe because a read cannot create anything. A write is never
		 * repeated, whatever the status.
		 */
		if ( ! $is_write && ! $retried && 401 === (int) $response['status'] && $needs_token ) {
			$this->tokens->forget();

			return $this->call( $operation, $method, $url, $payload, false, $parser, true, $raw_decoded );
		}

		if ( Kuka_Island_Shipping_Result::OUTCOME_SUCCESS !== $verdict['outcome'] ) {
			return new Kuka_Island_Shipping_Result( $verdict['outcome'], $operation, array(), $verdict['code'], (int) $response['status'] );
		}

		$raw_decoded = $decoded;

		return Kuka_Island_Shipping_Result::success( $operation, (array) $parsed, (int) $response['status'] );
	}

	/**
	 * Is this one of the CBS Info operations, which carry no Authorization
	 * header in the vendor's document?
	 */
	private static function is_cbs_operation( string $operation ): bool {
		return in_array( $operation, array( 'get_cities', 'get_districts' ), true );
	}
}
