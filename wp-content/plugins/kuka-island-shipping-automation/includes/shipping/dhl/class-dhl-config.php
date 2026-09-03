<?php
/**
 * DHL eCommerce Türkiye configuration, fail-closed in every direction.
 *
 * Secrets are read ONLY from wp-config constants or the process environment.
 * Never from an option, never from a settings screen, never from source. An
 * option is readable by every plugin in the site and lands in database dumps;
 * a settings screen puts a password in a POST body and in browser history.
 *
 * There are two independent credential pairs and BOTH are required before any
 * call leaves the process:
 *
 *   - the API gateway pair (X-IBM-Client-Id / X-IBM-Client-Secret), sent as
 *     headers on every request, per the securityDefinitions block of all five
 *     official documents;
 *   - the Identity pair (customerNumber / password), posted to /token to obtain
 *     the JWT, per Identity_API-1.0.json definitions.GenerateTokenRequest,
 *     whose required list is exactly customerNumber, password, identityType.
 *
 * They are kept apart on purpose. The gateway pair identifies the integration;
 * the Identity pair identifies the shipping account. Holding one is not
 * permission to act with the other, and get_readiness() names each missing
 * field separately so an operator is never left guessing which half is absent.
 *
 * LIVE IS BLOCKED. The vendor's own documents carry exactly one server, the
 * sandbox one, in x-ibm-configuration.servers. This integration therefore has
 * no verified production base URL, and inventing one is how requests get sent
 * to a host nobody checked. Selecting the live environment does not degrade to
 * sandbox and does not warn-and-continue: it refuses.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_DHL_Config {

	public const ENV_TEST = 'test';
	public const ENV_LIVE = 'live';

	/**
	 * The official sandbox host, from x-ibm-configuration.servers and from the
	 * host key of all five documents.
	 */
	public const SANDBOX_HOST = 'testapi.mngkargo.com.tr';

	/**
	 * Base paths, transcribed from each document's own basePath.
	 *
	 * Identity_API        basePath /mngapi/api            + path /token
	 * Standard_Command    basePath /mngapi/api/standardcmdapi
	 * Barcode_Command     basePath /mngapi/api/barcodecmdapi
	 * Standard_Query      basePath /mngapi/api/standardqueryapi
	 * CBS_Info            basePath /mngapi/api/cbsinfoapi
	 */
	public const SANDBOX_IDENTITY_URL       = 'https://testapi.mngkargo.com.tr/mngapi/api/token';
	public const SANDBOX_STANDARD_CMD_URL   = 'https://testapi.mngkargo.com.tr/mngapi/api/standardcmdapi';
	public const SANDBOX_BARCODE_CMD_URL    = 'https://testapi.mngkargo.com.tr/mngapi/api/barcodecmdapi';
	public const SANDBOX_STANDARD_QUERY_URL = 'https://testapi.mngkargo.com.tr/mngapi/api/standardqueryapi';
	public const SANDBOX_CBS_INFO_URL       = 'https://testapi.mngkargo.com.tr/mngapi/api/cbsinfoapi';

	/**
	 * identityType, fixed at the value the specification documents.
	 *
	 * "Identity Type of Customer, for default set 1." It is a protocol constant,
	 * not a preference, so it is not configurable: a shop that could change it
	 * could only change it to something undocumented.
	 */
	public const IDENTITY_TYPE = 1;

	/** Allowed values of the tracking-number source setting. */
	public const TRACKING_SOURCE_UNSET       = '';
	public const TRACKING_SOURCE_SHIPMENT_ID = 'shipment_id';
	public const TRACKING_SOURCE_BARCODE     = 'barcode';

	private string $environment;
	private string $client_id;
	private string $client_secret;
	private string $customer_number;
	private string $password;
	private bool $automation_enabled;
	private bool $cod_enabled;
	private string $tracking_number_source;
	private int $timeout;

	/**
	 * @param array<string, mixed> $overrides Test-only direct values.
	 */
	public function __construct( array $overrides = array() ) {
		$environment = strtolower( trim( (string) ( $overrides['environment'] ?? self::read( 'KUKA_DHL_ENVIRONMENT', self::ENV_TEST ) ) ) );
		$this->environment = in_array( $environment, array( self::ENV_TEST, self::ENV_LIVE ), true ) ? $environment : self::ENV_TEST;

		$this->client_id       = trim( (string) ( $overrides['client_id'] ?? self::read( 'KUKA_DHL_CLIENT_ID' ) ) );
		$this->client_secret   = (string) ( $overrides['client_secret'] ?? self::read( 'KUKA_DHL_CLIENT_SECRET' ) );
		$this->customer_number = trim( (string) ( $overrides['customer_number'] ?? self::read( 'KUKA_DHL_CUSTOMER_NUMBER' ) ) );
		$this->password        = (string) ( $overrides['password'] ?? self::read( 'KUKA_DHL_PASSWORD' ) );

		/*
		 * ONE automation switch for the whole plugin, not one per carrier. A
		 * second courier added later must not come with a second switch an
		 * operator has to remember: "is automation on" has to have a single
		 * answer. The scheduler reads the same constant through
		 * Kuka_Island_Shipping_Status_Poller::automation_enabled().
		 */
		$this->automation_enabled = (bool) ( $overrides['automation_enabled'] ?? self::read_bool( 'KUKA_SHIPPING_AUTOMATION' ) );

		/*
		 * Cash on delivery stays closed until the business rule is confirmed
		 * separately. The API carries isCOD and codAmount and this integration
		 * can fill them; what has not been established is who reconciles the
		 * collected money, how it reaches the shop, and what happens to the
		 * WooCommerce payment record in the meantime. Sending isCOD=1 without
		 * those answers creates a debt nobody is tracking.
		 */
		$this->cod_enabled = (bool) ( $overrides['cod_enabled'] ?? self::read_bool( 'KUKA_DHL_COD_ENABLED' ) );

		/*
		 * Which value in the carrier's answer IS the WooCommerce tracking
		 * number has not been measured against the sandbox, so the default is
		 * "do not claim to know". createbarcode returns a shipmentId and a list
		 * of per-piece barcode values, and picking one of them by intuition
		 * would put a number on the customer's order e-mail that may not track
		 * anything.
		 */
		$source = strtolower( trim( (string) ( $overrides['tracking_number_source'] ?? self::read( 'KUKA_DHL_TRACKING_NUMBER_SOURCE', self::TRACKING_SOURCE_UNSET ) ) ) );
		$this->tracking_number_source = in_array(
			$source,
			array( self::TRACKING_SOURCE_SHIPMENT_ID, self::TRACKING_SOURCE_BARCODE ),
			true
		) ? $source : self::TRACKING_SOURCE_UNSET;

		$timeout       = (int) ( $overrides['timeout'] ?? self::read( 'KUKA_DHL_TIMEOUT', 30 ) );
		$this->timeout = max( 5, min( 120, $timeout ) );
	}

	/**
	 * Read a constant, falling back to the environment and then to a default.
	 *
	 * @param string $name    Constant name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	private static function read( string $name, $default = '' ) {
		if ( defined( $name ) ) {
			return constant( $name );
		}

		$from_env = getenv( $name );

		return false !== $from_env ? $from_env : $default;
	}

	/**
	 * Read a boolean switch, closed unless explicitly opened.
	 *
	 * '1', 'true', 'yes' and 'on' open it; everything else -- including an
	 * unset value, an empty string and any word nobody anticipated -- leaves it
	 * closed.
	 */
	private static function read_bool( string $name ): bool {
		$value = self::read( $name, false );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	public function get_environment(): string {
		return $this->environment;
	}

	public function is_live(): bool {
		return self::ENV_LIVE === $this->environment;
	}

	/**
	 * Is the live environment refused?
	 *
	 * Always true while it is selected. This is not a placeholder to be relaxed
	 * by editing a boolean: unblocking live means adding a verified production
	 * base URL to this class, which is a change a person reviews.
	 */
	public function is_live_blocked(): bool {
		return $this->is_live();
	}

	public function get_timeout(): int {
		return $this->timeout;
	}

	public function get_client_id(): string {
		return $this->client_id;
	}

	public function get_client_secret(): string {
		return $this->client_secret;
	}

	public function get_customer_number(): string {
		return $this->customer_number;
	}

	public function get_password(): string {
		return $this->password;
	}

	public function is_automation_enabled(): bool {
		return $this->automation_enabled;
	}

	public function is_cod_enabled(): bool {
		return $this->cod_enabled;
	}

	public function get_tracking_number_source(): string {
		return $this->tracking_number_source;
	}

	/**
	 * The five endpoints, or an empty array when the environment is blocked.
	 *
	 * Returning nothing for live is what makes the block structural: a caller
	 * that ignored is_live_blocked() still has no URL to send anything to.
	 *
	 * @return array<string, string>
	 */
	public function endpoints(): array {
		if ( $this->is_live_blocked() ) {
			return array();
		}

		return array(
			'identity'       => self::SANDBOX_IDENTITY_URL,
			'standard_cmd'   => self::SANDBOX_STANDARD_CMD_URL,
			'barcode_cmd'    => self::SANDBOX_BARCODE_CMD_URL,
			'standard_query' => self::SANDBOX_STANDARD_QUERY_URL,
			'cbs_info'       => self::SANDBOX_CBS_INFO_URL,
		);
	}

	/**
	 * Is this URL one this integration is willing to contact?
	 *
	 * A separate check from endpoints() because the client builds request URLs
	 * by appending a path, and an appended path is exactly where a traversal or
	 * an injected host would appear. Scheme, host, port, userinfo and fragment
	 * are all pinned; the path must start with the base path of one of the five
	 * documented services.
	 *
	 * @param string $url Absolute URL about to be requested.
	 */
	public function is_allowed_url( string $url ): bool {
		if ( $this->is_live_blocked() ) {
			return false;
		}

		if ( '' === $url || $url !== trim( $url ) ) {
			return false;
		}

		if ( 1 === preg_match( '/[\s\x00-\x1F\x7F\\\\]/', $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return false;
		}

		if ( 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return false;
		}

		if ( self::SANDBOX_HOST !== strtolower( (string) ( $parts['host'] ?? '' ) ) ) {
			return false;
		}

		if ( isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		$path = (string) ( $parts['path'] ?? '' );

		if ( str_contains( $path, '..' ) || str_contains( $path, '//' ) ) {
			return false;
		}

		foreach ( $this->endpoints() as $base ) {
			$base_path = (string) ( wp_parse_url( $base, PHP_URL_PATH ) ?? '' );

			if ( $path === $base_path || str_starts_with( $path, $base_path . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Which configuration fields block any call at all.
	 *
	 * Field NAMES only. A gap list that echoed values would put a client secret
	 * into the admin panel the first time somebody mistyped one.
	 *
	 * @return array<int, string>
	 */
	public function get_readiness_gaps(): array {
		$gaps = array();

		if ( '' === $this->client_id ) {
			$gaps[] = 'KUKA_DHL_CLIENT_ID';
		}
		if ( '' === $this->client_secret ) {
			$gaps[] = 'KUKA_DHL_CLIENT_SECRET';
		}
		if ( '' === $this->customer_number ) {
			$gaps[] = 'KUKA_DHL_CUSTOMER_NUMBER';
		}
		if ( '' === $this->password ) {
			$gaps[] = 'KUKA_DHL_PASSWORD';
		}

		return $gaps;
	}

	/**
	 * Are the gateway headers alone available?
	 *
	 * CBS Info is the one service whose documented operations declare no
	 * Authorization parameter -- only x-api-version, plus the gateway keys from
	 * the global security block. This method exists so that fact is expressed
	 * once, in the place that owns credentials, instead of being re-derived
	 * wherever a CBS call is made.
	 */
	public function has_gateway_credentials(): bool {
		return '' !== $this->client_id && '' !== $this->client_secret;
	}

	public function has_identity_credentials(): bool {
		return '' !== $this->customer_number && '' !== $this->password;
	}

	public function is_ready(): bool {
		return ! $this->is_live_blocked() && array() === $this->get_readiness_gaps();
	}

	/**
	 * A summary safe to print anywhere.
	 *
	 * Presence booleans only. No prefix, no suffix, no length, no masked
	 * fragment: a masked secret is still a secret with its search space
	 * reduced, and none of these values needs to be recognisable on screen.
	 *
	 * @return array<string, scalar>
	 */
	public function get_safe_summary(): array {
		return array(
			'environment'            => $this->environment,
			'live_blocked'           => $this->is_live_blocked(),
			'has_client_id'          => '' !== $this->client_id,
			'has_client_secret'      => '' !== $this->client_secret,
			'has_customer_number'    => '' !== $this->customer_number,
			'has_password'           => '' !== $this->password,
			'automation_enabled'     => $this->automation_enabled,
			'cod_enabled'            => $this->cod_enabled,
			'tracking_number_source' => '' !== $this->tracking_number_source ? $this->tracking_number_source : 'unmeasured',
			'timeout'                => $this->timeout,
			'ready'                  => $this->is_ready(),
		);
	}
}
