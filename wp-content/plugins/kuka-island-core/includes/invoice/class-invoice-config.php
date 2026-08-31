<?php
/**
 * Invoice integration configuration value object.
 *
 * Encapsulates environment, credentials and series settings with fail-closed security.
 * Secret values are read only from wp-config constants or environment variables,
 * never from plain database options or source code.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Config {
	public const ENV_TEST = 'test';
	public const ENV_LIVE = 'live';

	public const DEFAULT_TEST_WSDL        = 'https://test.edmbilisim.com.tr/EFaturaEDM21ea/EFaturaEDM.svc?singleWsdl';
	public const DEFAULT_LIVE_WSDL        = 'https://portal2.edmbilisim.com.tr/EFaturaEDM/EFaturaEDM.svc?singleWsdl';
	public const DEFAULT_APPLICATION_NAME = 'ozelyazilim.kukaisland';

	private string $environment;
	private string $username;
	private string $password;
	private string $secret_key;
	private string $sender_vkn;
	private string $sender_alias;
	private string $sender_title;
	private string $sender_tax_office;
	private string $sender_address;
	private string $sender_district;
	private string $sender_city;
	private string $sender_postcode;
	private string $series_einvoice;
	private string $series_earchive;
	private bool $allow_generic_individual_vkn;
	private bool $auto_send;
	private int $timeout;
	private string $custom_wsdl;

	public function __construct( array $overrides = array() ) {
		$env_val = defined( 'KUKA_INVOICE_ENVIRONMENT' ) ? (string) KUKA_INVOICE_ENVIRONMENT : ( defined( 'KUKA_EDM_ENVIRONMENT' ) ? (string) KUKA_EDM_ENVIRONMENT : self::ENV_TEST );
		$this->environment = in_array( strtolower( (string) ( $overrides['environment'] ?? $env_val ) ), array( self::ENV_TEST, self::ENV_LIVE ), true )
			? strtolower( (string) ( $overrides['environment'] ?? $env_val ) )
			: self::ENV_TEST;

		$this->username          = trim( (string) ( $overrides['username'] ?? ( defined( 'KUKA_EDM_USERNAME' ) ? KUKA_EDM_USERNAME : '' ) ) );
		$this->password          = (string) ( $overrides['password'] ?? ( defined( 'KUKA_EDM_PASSWORD' ) ? KUKA_EDM_PASSWORD : '' ) );
		$this->secret_key        = (string) ( $overrides['secret_key'] ?? ( defined( 'KUKA_EDM_SECRET_KEY' ) ? KUKA_EDM_SECRET_KEY : '' ) );
		$this->sender_vkn        = trim( (string) ( $overrides['sender_vkn'] ?? ( defined( 'KUKA_EDM_SENDER_VKN' ) ? KUKA_EDM_SENDER_VKN : ( defined( 'KUKA_LEGAL_TAX_NUMBER' ) ? KUKA_LEGAL_TAX_NUMBER : '' ) ) ) );
		$this->sender_alias      = trim( (string) ( $overrides['sender_alias'] ?? ( defined( 'KUKA_EDM_SENDER_ALIAS' ) ? KUKA_EDM_SENDER_ALIAS : '' ) ) );
		$this->sender_title      = trim( (string) ( $overrides['sender_title'] ?? ( defined( 'KUKA_LEGAL_COMPANY_NAME' ) ? KUKA_LEGAL_COMPANY_NAME : '' ) ) );
		$this->sender_tax_office = trim( (string) ( $overrides['sender_tax_office'] ?? ( defined( 'KUKA_LEGAL_TAX_OFFICE' ) ? KUKA_LEGAL_TAX_OFFICE : '' ) ) );
		$this->sender_address    = trim( (string) ( $overrides['sender_address'] ?? ( defined( 'KUKA_LEGAL_ADDRESS' ) ? KUKA_LEGAL_ADDRESS : '' ) ) );
		$this->sender_district   = trim( (string) ( $overrides['sender_district'] ?? ( defined( 'KUKA_LEGAL_DISTRICT' ) ? KUKA_LEGAL_DISTRICT : '' ) ) );
		$this->sender_city       = trim( (string) ( $overrides['sender_city'] ?? ( defined( 'KUKA_LEGAL_CITY' ) ? KUKA_LEGAL_CITY : '' ) ) );
		$this->sender_postcode   = trim( (string) ( $overrides['sender_postcode'] ?? ( defined( 'KUKA_LEGAL_POSTCODE' ) ? KUKA_LEGAL_POSTCODE : '' ) ) );
		$this->series_einvoice   = trim( (string) ( $overrides['series_einvoice'] ?? ( defined( 'KUKA_EDM_SERIES_EINVOICE' ) ? KUKA_EDM_SERIES_EINVOICE : '' ) ) );
		$this->series_earchive   = trim( (string) ( $overrides['series_earchive'] ?? ( defined( 'KUKA_EDM_SERIES_EARCHIVE' ) ? KUKA_EDM_SERIES_EARCHIVE : '' ) ) );

		// Fail-closed: the generic GİB retail consumer VKN (11111111111) is only
		// usable when an explicit, reviewed policy constant is set to the literal
		// boolean true. An undefined, empty, string or truthy-but-not-true value
		// keeps the policy disabled.
		$this->allow_generic_individual_vkn = array_key_exists( 'allow_generic_individual_vkn', $overrides )
			? true === $overrides['allow_generic_individual_vkn']
			: ( defined( 'KUKA_EDM_ALLOW_GENERIC_INDIVIDUAL_VKN' ) && true === KUKA_EDM_ALLOW_GENERIC_INDIVIDUAL_VKN );

		$auto_send_const  = defined( 'KUKA_INVOICE_AUTO_SEND' ) ? (bool) KUKA_INVOICE_AUTO_SEND : ( defined( 'KUKA_EDM_AUTO_SEND' ) ? (bool) KUKA_EDM_AUTO_SEND : false );
		$this->auto_send  = (bool) ( $overrides['auto_send'] ?? $auto_send_const );

		$timeout_const   = defined( 'KUKA_EDM_TIMEOUT' ) ? (int) KUKA_EDM_TIMEOUT : 30;
		$this->timeout   = max( 5, min( 120, (int) ( $overrides['timeout'] ?? $timeout_const ) ) );
		$this->custom_wsdl = trim( (string) ( $overrides['custom_wsdl'] ?? ( defined( 'KUKA_EDM_WSDL' ) ? KUKA_EDM_WSDL : '' ) ) );
	}

	public function get_environment(): string {
		return $this->environment;
	}

	public function is_live(): bool {
		return self::ENV_LIVE === $this->environment;
	}

	public function is_trace_enabled(): bool {
		return ! $this->is_live() && defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	public function get_application_name(): string {
		return self::DEFAULT_APPLICATION_NAME;
	}

	public function get_username(): string {
		return $this->username;
	}

	public function get_password(): string {
		return $this->password;
	}

	public function get_secret_key(): string {
		return $this->secret_key;
	}

	public function get_sender_vkn(): string {
		return $this->sender_vkn;
	}

	public function get_sender_alias(): string {
		return $this->sender_alias;
	}

	public function get_sender_title(): string {
		return $this->sender_title;
	}

	public function get_sender_tax_office(): string {
		return $this->sender_tax_office;
	}

	public function get_sender_address(): string {
		return $this->sender_address;
	}

	public function get_sender_district(): string {
		return $this->sender_district;
	}

	public function get_sender_city(): string {
		return $this->sender_city;
	}

	public function get_sender_postcode(): string {
		return $this->sender_postcode;
	}

	public function get_series_einvoice(): string {
		return $this->series_einvoice;
	}

	public function get_series_earchive(): string {
		return $this->series_earchive;
	}

	public function allow_generic_individual_vkn(): bool {
		return $this->allow_generic_individual_vkn;
	}

	public function get_timeout(): int {
		return $this->timeout;
	}

	public function get_wsdl(): string {
		if ( '' !== $this->custom_wsdl ) {
			return $this->custom_wsdl;
		}

		return $this->is_live() ? self::DEFAULT_LIVE_WSDL : self::DEFAULT_TEST_WSDL;
	}

	/**
	 * Does the configuration possess at least valid username and password credentials?
	 */
	public function has_login_credentials(): bool {
		return '' !== $this->username && '' !== $this->password;
	}

	/**
	 * Can read-only sandbox tests (Login, CheckCounter, CheckUser, Logout) be executed?
	 */
	public function can_run_read_only_sandbox(): bool {
		return $this->has_login_credentials();
	}

	/**
	 * Can invoice generation and transmission proceed?
	 */
	public function can_send_invoice(): bool {
		return array() === $this->get_send_readiness_gaps();
	}

	/**
	 * Which readiness fields block can_send_invoice()?
	 *
	 * @return array<string> Missing field identifiers (empty when ready).
	 */
	public function get_send_readiness_gaps(): array {
		$gaps = array();

		if ( '' === $this->username ) {
			$gaps[] = 'username';
		}
		if ( '' === $this->password ) {
			$gaps[] = 'password';
		}
		if ( '' === $this->sender_vkn ) {
			$gaps[] = 'sender_vkn';
		}
		if ( '' === $this->sender_alias ) {
			$gaps[] = 'sender_alias';
		}
		if ( ! preg_match( '/^[A-Z0-9]{3}$/', $this->series_einvoice ) ) {
			$gaps[] = 'series_einvoice';
		}
		if ( ! preg_match( '/^[A-Z0-9]{3}$/', $this->series_earchive ) ) {
			$gaps[] = 'series_earchive';
		}
		if ( '' === $this->sender_title ) {
			$gaps[] = 'sender_title';
		}
		if ( '' === $this->sender_tax_office ) {
			$gaps[] = 'sender_tax_office';
		}
		if ( '' === $this->sender_address ) {
			$gaps[] = 'sender_address';
		}
		if ( '' === $this->sender_district ) {
			$gaps[] = 'sender_district';
		}
		if ( '' === $this->sender_city ) {
			$gaps[] = 'sender_city';
		}
		if ( '' === $this->sender_postcode ) {
			$gaps[] = 'sender_postcode';
		}

		return $gaps;
	}

	/**
	 * Is the configuration minimally populated with mandatory credentials?
	 */
	public function is_configured(): bool {
		return '' !== $this->username
			&& '' !== $this->password
			&& '' !== $this->sender_vkn;
	}

	/**
	 * Is automatic invoice sending active?
	 *
	 * Auto-send must satisfy the full can_send_invoice() readiness contract --
	 * not merely username/password/VKN. A missing sender alias, invoice series,
	 * company title, tax office or address keeps orders out of the queue.
	 */
	public function is_auto_send_enabled(): bool {
		return $this->auto_send && $this->can_send_invoice();
	}

	/**
	 * Explicit readiness verification before switching to live environment.
	 *
	 * @return array{ready: bool, missing: array<string>}
	 */
	public function check_live_readiness(): array {
		$missing = array();

		if ( '' === $this->username ) {
			$missing[] = 'KUKA_EDM_USERNAME';
		}
		if ( '' === $this->password ) {
			$missing[] = 'KUKA_EDM_PASSWORD';
		}
		if ( ! preg_match( '/^\d{10,11}$/', $this->sender_vkn ) ) {
			$missing[] = 'KUKA_EDM_SENDER_VKN (10 veya 11 hane)';
		}
		if ( '' === $this->sender_alias ) {
			$missing[] = 'KUKA_EDM_SENDER_ALIAS';
		}
		if ( '' === $this->sender_title ) {
			$missing[] = 'KUKA_LEGAL_COMPANY_NAME';
		}
		if ( '' === $this->sender_tax_office ) {
			$missing[] = 'KUKA_LEGAL_TAX_OFFICE';
		}
		if ( '' === $this->sender_address ) {
			$missing[] = 'KUKA_LEGAL_ADDRESS';
		}
		if ( '' === $this->sender_district ) {
			$missing[] = 'KUKA_LEGAL_DISTRICT';
		}
		if ( '' === $this->sender_city ) {
			$missing[] = 'KUKA_LEGAL_CITY';
		}
		if ( '' === $this->sender_postcode ) {
			$missing[] = 'KUKA_LEGAL_POSTCODE';
		}
		if ( ! preg_match( '/^[A-Z0-9]{3}$/', $this->series_einvoice ) ) {
			$missing[] = 'KUKA_EDM_SERIES_EINVOICE (3 büyük harf/rakam)';
		}
		if ( ! preg_match( '/^[A-Z0-9]{3}$/', $this->series_earchive ) ) {
			$missing[] = 'KUKA_EDM_SERIES_EARCHIVE (3 büyük harf/rakam)';
		}

		return array(
			'ready'   => empty( $missing ),
			'missing' => $missing,
		);
	}

	/**
	 * Safe summary for admin view that never exposes secrets.
	 */
	public function get_safe_summary(): array {
		return array(
			'environment'     => $this->environment,
			'is_live'         => $this->is_live(),
			'is_configured'   => $this->is_configured(),
			'auto_send'       => $this->auto_send,
			'has_username'    => '' !== $this->username,
			'has_password'    => '' !== $this->password,
			'has_secret_key'  => '' !== $this->secret_key,
			'sender_vkn'      => '' !== $this->sender_vkn ? substr( $this->sender_vkn, 0, 2 ) . '****' . substr( $this->sender_vkn, -2 ) : '',
			'sender_alias'    => $this->sender_alias,
			'series_einvoice' => $this->series_einvoice,
			'series_earchive' => $this->series_earchive,
			'timeout'         => $this->timeout,
		);
	}
}
