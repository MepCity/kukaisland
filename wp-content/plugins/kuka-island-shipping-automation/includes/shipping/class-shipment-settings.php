<?php
/**
 * Everything a shop owner can configure, and where each answer comes from.
 *
 * FOUR SWITCHES, FOUR DIFFERENT THINGS. They are kept apart on purpose, because
 * conflating them is how an operator turns off "shipping" and discovers that
 * parcels are still being booked -- or turns off automation and discovers that
 * the manual buttons went with it.
 *
 *   module_enabled       every carrier operation stops, manual and automatic
 *   adapter_enabled      the carrier client is never even constructed
 *   auto_create_enabled  paid orders book a shipment on their own
 *   auto_poll_enabled    booked shipments are followed up on their own
 *
 * `environment` is not a switch in that sense: selecting `live` does not open
 * anything, because the live block is structural (DHL_Config has no verified
 * production base URL). The panel can offer the choice and the choice still
 * refuses; see readiness().
 *
 * PRECEDENCE IS FIXED AND DOCUMENTED:
 *
 *     constant  >  environment  >  panel
 *
 * A site with a deployment pipeline keeps using wp-config and the panel becomes
 * a read-only mirror of it. A site without one uses the panel. Neither can
 * silently override the other, and secret_source() / switch_source() name which
 * one answered so the panel can say so on screen.
 *
 * THE RUN GATE IS NOT DUPLICATED. `module_enabled` is not a second flag that
 * has to agree with Runtime_Gate; it IS Runtime_Gate, read and written through
 * this class. Two booleans meaning the same thing is how they end up
 * disagreeing.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Settings {

	/** Switches only. No secret ever reaches this row. Never autoloaded. */
	public const OPTION = 'kuka_island_shipping_settings';

	public const SOURCE_CONSTANT = 'constant';
	public const SOURCE_ENV      = 'environment';
	public const SOURCE_PANEL    = 'panel';
	public const SOURCE_VAULT    = 'vault';
	public const SOURCE_DEFAULT  = 'default';
	public const SOURCE_ABSENT   = 'absent';

	/**
	 * Secret field => the wp-config constant that outranks the panel.
	 *
	 * @return array<string, string>
	 */
	public static function secret_fields(): array {
		return array(
			'client_id'       => 'KUKA_DHL_CLIENT_ID',
			'client_secret'   => 'KUKA_DHL_CLIENT_SECRET',
			'customer_number' => 'KUKA_DHL_CUSTOMER_NUMBER',
			'password'        => 'KUKA_DHL_PASSWORD',
		);
	}

	/**
	 * Switch name => the constant that outranks the panel.
	 *
	 * `module_enabled` has no constant: it is the run gate, which deactivation
	 * writes and activation clears, and a constant that disagreed with it would
	 * have no way to stop a worker already in flight.
	 *
	 * @return array<string, string>
	 */
	public static function switch_constants(): array {
		return array(
			/*
			 * Named as a string, not read from the adapter's class: this layer
			 * serves any registered courier and must not know one by name. The
			 * adapter declares the same name in its own ADAPTER_SETTING
			 * constant, and SHIPPING_ADAPTER_KEY_FAIL_CLOSED measures that the
			 * two agree.
			 */
			'adapter_enabled'     => 'KUKA_DHL_ADAPTER',
			'environment'         => 'KUKA_DHL_ENVIRONMENT',
			'auto_create_enabled' => 'KUKA_SHIPPING_AUTO_CREATE',
			'auto_poll_enabled'   => 'KUKA_SHIPPING_AUTOMATION',
			'default_carrier'     => 'KUKA_SHIPPING_DEFAULT_CARRIER',
		);
	}

	/**
	 * What a site that has configured nothing gets.
	 *
	 * AUTOMATIC CREATION IS OFF. It is the only switch here that can produce an
	 * outward-facing action -- a label printed, a courier dispatched, a charge
	 * raised -- without anybody pressing anything, so it starts closed and stays
	 * closed until a person opens it.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_switches(): array {
		return array(
			'module_enabled'      => true,
			'adapter_enabled'     => true,
			'environment'         => Kuka_Island_Shipping_Carrier_Interface::ENVIRONMENT_TEST,
			'auto_create_enabled' => false,
			'auto_poll_enabled'   => false,
			'default_carrier'     => '',
		);
	}

	/**
	 * The panel's own stored row.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Every switch, resolved, with the run gate answering for module_enabled.
	 *
	 * @return array<string, mixed>
	 */
	public static function switches(): array {
		$resolved = array();

		foreach ( self::default_switches() as $name => $default ) {
			$resolved[ $name ] = self::get_switch( $name );
		}

		unset( $default );

		return $resolved;
	}

	/**
	 * One switch, by the documented precedence.
	 *
	 * @return mixed
	 */
	public static function get_switch( string $name ) {
		$defaults = self::default_switches();

		if ( ! array_key_exists( $name, $defaults ) ) {
			return null;
		}

		if ( 'module_enabled' === $name ) {
			// The gate is the truth. Nothing else is consulted.
			return ! Kuka_Island_Shipping_Runtime_Gate::is_disabled();
		}

		$constants = self::switch_constants();
		$constant  = (string) ( $constants[ $name ] ?? '' );

		if ( '' !== $constant && defined( $constant ) ) {
			return self::cast( $name, constant( $constant ) );
		}

		if ( '' !== $constant ) {
			$from_env = getenv( $constant );

			if ( false !== $from_env ) {
				return self::cast( $name, $from_env );
			}
		}

		$stored = self::stored();

		if ( array_key_exists( $name, $stored ) ) {
			return self::cast( $name, $stored[ $name ] );
		}

		return $defaults[ $name ];
	}

	/** Which layer answered for this switch. */
	public static function switch_source( string $name ): string {
		if ( 'module_enabled' === $name ) {
			return self::SOURCE_PANEL;
		}

		$constant = (string) ( self::switch_constants()[ $name ] ?? '' );

		if ( '' !== $constant && defined( $constant ) ) {
			return self::SOURCE_CONSTANT;
		}

		if ( '' !== $constant && false !== getenv( $constant ) ) {
			return self::SOURCE_ENV;
		}

		return array_key_exists( $name, self::stored() ) ? self::SOURCE_PANEL : self::SOURCE_DEFAULT;
	}

	/**
	 * Coerce a raw value into the shape its switch has.
	 *
	 * `environment` is an allow-list, not a free string: an unrecognised value
	 * falls back to the sandbox rather than to whatever was typed.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function cast( string $name, $value ) {
		if ( 'environment' === $name ) {
			$text = strtolower( trim( (string) $value ) );

			return in_array( $text, array( Kuka_Island_Shipping_Carrier_Interface::ENVIRONMENT_TEST, Kuka_Island_Shipping_Carrier_Interface::ENVIRONMENT_LIVE ), true )
				? $text
				: Kuka_Island_Shipping_Carrier_Interface::ENVIRONMENT_TEST;
		}

		if ( 'default_carrier' === $name ) {
			return trim( (string) $value );
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Write the switches a person just chose.
	 *
	 * Only names this class declares are written; an unknown key is dropped
	 * rather than stored, so a crafted POST cannot put arbitrary data into the
	 * row. The run gate is written through Runtime_Gate, not into this row.
	 *
	 * @param array<string, mixed> $values Raw values from the form.
	 */
	public static function save_switches( array $values ): void {
		$stored = self::stored();

		foreach ( self::default_switches() as $name => $default ) {
			unset( $default );

			if ( ! array_key_exists( $name, $values ) ) {
				continue;
			}

			if ( 'module_enabled' === $name ) {
				if ( self::cast( $name, $values[ $name ] ) ) {
					Kuka_Island_Shipping_Runtime_Gate::enable();
				} else {
					Kuka_Island_Shipping_Runtime_Gate::disable();
				}

				continue;
			}

			$stored[ $name ] = self::cast( $name, $values[ $name ] );
		}

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * One credential, by the documented precedence.
	 *
	 * Returns an empty string for "not configured" AND for "stored but
	 * unreadable". The caller cannot act on either, and readiness() is where the
	 * difference is reported.
	 */
	public static function resolve_secret( string $field ): string {
		$constant = (string) ( self::secret_fields()[ $field ] ?? '' );

		if ( '' === $constant ) {
			return '';
		}

		if ( defined( $constant ) ) {
			return (string) constant( $constant );
		}

		$from_env = getenv( $constant );

		if ( false !== $from_env ) {
			return (string) $from_env;
		}

		$stored = Kuka_Island_Shipping_Secret_Vault::get( $field );

		return is_string( $stored ) ? $stored : '';
	}

	/** Which layer answered for this credential. Never the value. */
	public static function secret_source( string $field ): string {
		$constant = (string) ( self::secret_fields()[ $field ] ?? '' );

		if ( '' === $constant ) {
			return self::SOURCE_ABSENT;
		}

		if ( defined( $constant ) ) {
			return self::SOURCE_CONSTANT;
		}

		if ( false !== getenv( $constant ) ) {
			return self::SOURCE_ENV;
		}

		return in_array( $field, Kuka_Island_Shipping_Secret_Vault::stored_fields(), true )
			? self::SOURCE_VAULT
			: self::SOURCE_ABSENT;
	}

	public static function is_auto_create_enabled(): bool {
		return (bool) self::get_switch( 'auto_create_enabled' );
	}

	public static function is_auto_poll_enabled(): bool {
		return (bool) self::get_switch( 'auto_poll_enabled' );
	}

	public static function is_module_enabled(): bool {
		return (bool) self::get_switch( 'module_enabled' );
	}

	public static function environment(): string {
		return (string) self::get_switch( 'environment' );
	}

	/**
	 * Is this installation configured well enough to contact the carrier, and
	 * if not, what is the ONE sentence an operator should read?
	 *
	 * @return array{ready: bool, code: string, message: string, gaps: array<int, string>, vault: array<string, mixed>}
	 */
	public static function readiness(): array {
		$vault = Kuka_Island_Shipping_Secret_Vault::state();
		$gaps  = array();

		foreach ( array_keys( self::secret_fields() ) as $field ) {
			if ( '' === self::resolve_secret( $field ) ) {
				$gaps[] = $field;
			}
		}

		if ( ! $vault['ok'] ) {
			return array(
				'ready'   => false,
				'code'    => (string) $vault['code'],
				'message' => Kuka_Island_Shipping_Secret_Vault::CODE_UNAVAILABLE === (string) $vault['code']
					? __( 'Bu sunucuda şifreli ayar kasası kullanılamıyor (libsodium yok). Kimlik bilgileri yalnız wp-config.php sabitleriyle verilebilir.', 'kuka-island-shipping-automation' )
					: __( 'Kayıtlı kimlik bilgileri okunamıyor. Site anahtarları (salt) değişmiş olabilir; bilgileri panelden yeniden girmeniz gerekiyor. Bu sürede hiçbir kargo çağrısı yapılmaz.', 'kuka-island-shipping-automation' ),
				'gaps'    => $gaps,
				'vault'   => $vault,
			);
		}

		if ( Kuka_Island_Shipping_Carrier_Interface::ENVIRONMENT_LIVE === self::environment() ) {
			return array(
				'ready'   => false,
				'code'    => 'live_environment_blocked',
				'message' => __( 'Canlı ortam kapalıdır. Taşıyıcının resmî dokümanlarında doğrulanmış bir canlı adres yoktur; bu yüzden canlı seçilse bile hiçbir çağrı yapılmaz. Test ortamına dönün.', 'kuka-island-shipping-automation' ),
				'gaps'    => $gaps,
				'vault'   => $vault,
			);
		}

		if ( array() !== $gaps ) {
			return array(
				'ready'   => false,
				'code'    => 'credentials_missing',
				'message' => __( 'Kimlik bilgileri eksik. Taşıyıcıya hiçbir çağrı yapılmaz; eksik alanları doldurun.', 'kuka-island-shipping-automation' ),
				'gaps'    => $gaps,
				'vault'   => $vault,
			);
		}

		return array(
			'ready'   => true,
			'code'    => '',
			'message' => '',
			'gaps'    => array(),
			'vault'   => $vault,
		);
	}

	/**
	 * Everything the panel prints. Presence and sources, never values.
	 *
	 * @return array<string, mixed>
	 */
	public static function safe_summary(): array {
		$secrets = array();

		foreach ( array_keys( self::secret_fields() ) as $field ) {
			$secrets[ $field ] = array(
				'present' => '' !== self::resolve_secret( $field ),
				'source'  => self::secret_source( $field ),
			);
		}

		$switches = array();

		foreach ( self::switches() as $name => $value ) {
			$switches[ $name ] = array(
				'value'  => $value,
				'source' => self::switch_source( $name ),
			);
		}

		$readiness = self::readiness();

		return array(
			'secrets'   => $secrets,
			'switches'  => $switches,
			'readiness' => array(
				'ready'   => $readiness['ready'],
				'code'    => $readiness['code'],
				'message' => $readiness['message'],
				'gaps'    => $readiness['gaps'],
			),
			'vault'     => array(
				'available' => Kuka_Island_Shipping_Secret_Vault::is_available(),
				'stored'    => Kuka_Island_Shipping_Secret_Vault::stored_fields(),
			),
		);
	}
}
