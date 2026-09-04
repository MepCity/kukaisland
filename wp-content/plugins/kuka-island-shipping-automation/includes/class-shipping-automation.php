<?php
/**
 * Module composition root.
 *
 * Loads the carrier-agnostic core, then the one adapter that exists today, and
 * attaches that adapter through the same public filter a third-party adapter
 * would use. The plugin's own carrier is therefore not privileged: if the DHL
 * adapter were removed tomorrow the registry would simply be empty, and the
 * order screen would say so.
 *
 * register() attaches exactly two things: the admin panel and the poller's
 * worker hook. There is no order-status hook, no checkout hook, no payment hook
 * and no cron entry, because none of those may ever book a shipment.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Automation {

	private Kuka_Island_Shipping_Carrier_Registry $registry;
	private Kuka_Island_Shipping_Manager $manager;
	private Kuka_Island_Shipping_Status_Poller $poller;
	private Kuka_Island_Shipping_Admin $admin;

	public function __construct() {
		self::load_dependencies();

		$this->registry = new Kuka_Island_Shipping_Carrier_Registry();
		$this->manager  = new Kuka_Island_Shipping_Manager( $this->registry );
		$this->poller   = new Kuka_Island_Shipping_Status_Poller( $this->manager );
		$this->admin    = new Kuka_Island_Shipping_Admin( $this->manager );
	}

	public function register(): void {
		add_filter( 'kuka_island_shipping_carriers', array( self::class, 'register_default_carrier' ) );
		add_filter( 'kuka_island_shipping_configuration_notices', array( self::class, 'adapter_notice' ) );

		$this->admin->register();
		$this->poller->register();
	}

	/**
	 * Attach the built-in DHL adapter.
	 *
	 * Built lazily inside the filter rather than in the constructor so that a
	 * request which never touches shipping never constructs a client, a token
	 * store or a configuration object.
	 *
	 * Attached only while the adapter's own switch is on, and the switch fails
	 * CLOSED on a value it does not recognise; see DHL_Config::adapter_state().
	 *
	 * @param array<int, mixed> $carriers Adapters registered so far.
	 * @return array<int, mixed>
	 */
	public static function register_default_carrier( $carriers ): array {
		$carriers = is_array( $carriers ) ? $carriers : array();

		/*
		 * The adapter has its own switch, separate from the plugin's. A shop
		 * that has moved to another courier turns this one off and the registry
		 * never learns about it: nothing is constructed, no client is built, and
		 * every operation refuses with carrier_not_registered before the
		 * network. An order pinned to it then refuses rather than being quietly
		 * re-routed to whatever else is registered.
		 */
		$switch = Kuka_Island_Shipping_DHL_Config::adapter_state();

		if ( ! $switch['enabled'] ) {
			/*
			 * Nothing is constructed on this path -- no provider, no client, no
			 * token store, no transport -- so a wrong value cannot produce a
			 * single HTTP call. The reason travels to the order screen through
			 * Shipment_Admin::module_status(), which prints it when the value
			 * was not understood at all.
			 */
			return $carriers;
		}

		$carriers[] = new Kuka_Island_Shipping_DHL_Provider();

		return $carriers;
	}

	/**
	 * Tell the order screen when this adapter's switch was not understood.
	 *
	 * The screen itself must not read a courier's configuration -- it prints
	 * whatever the adapters hand it and names none of them -- so the sentence
	 * is written here, in the one layer that already knows which courier ships
	 * with the module.
	 *
	 * Only the value that could not be understood produces a notice. A switch
	 * deliberately turned off is a decision, not a fault, and does not need a
	 * warning; a mistyped one does, because the operator believes they
	 * configured something and the adapter has failed closed on them.
	 *
	 * @param array<int, string> $notices Sentences collected so far.
	 * @return array<int, string>
	 */
	public static function adapter_notice( $notices ): array {
		$notices = is_array( $notices ) ? $notices : array();

		if ( Kuka_Island_Shipping_DHL_Config::ADAPTER_STATE_INVALID === (string) Kuka_Island_Shipping_DHL_Config::adapter_state()['reason'] ) {
			$notices[] = sprintf(
				/* translators: %s: configuration constant name. */
				__( '%s değeri tanınmadı; DHL adaptörü güvenli tarafta kapatıldı. Geçerli değerler: 1/true/yes/on veya 0/false/no/off (boşluksuz, küçük harf).', 'kuka-island-shipping-automation' ),
				Kuka_Island_Shipping_DHL_Config::ADAPTER_SETTING
			);
		}

		return $notices;
	}

	public function get_manager(): Kuka_Island_Shipping_Manager {
		return $this->manager;
	}

	public function get_registry(): Kuka_Island_Shipping_Carrier_Registry {
		return $this->registry;
	}

	/**
	 * Every file of the module, in dependency order.
	 *
	 * Public and static so the verification suite can load the module from disk
	 * without the plugin being active -- which is how the passive delivery
	 * contract and the active behaviour can both be measured in the same run.
	 */
	public static function load_dependencies(): void {
		$base = KUKA_ISLAND_SHIPPING_PATH . 'includes/shipping/';

		foreach ( self::module_files() as $file ) {
			require_once $base . $file;
		}
	}

	/**
	 * @return array<int, string>
	 */
	public static function module_files(): array {
		return array(
			'interface-http-transport.php',
			'interface-carrier-provider.php',
			'class-carrier-result.php',
			'class-carrier-fault-classifier.php',
			'class-shipment-status.php',
			'class-shipment-runtime-gate.php',
			'class-shipment-reference.php',
			'class-shipment-order-store.php',
			'class-carrier-registry.php',
			'class-fulfillment-writer.php',
			'class-shipment-notification.php',
			'class-shipment-status-poller.php',
			'class-shipment-manager.php',
			'class-shipment-admin.php',
			'dhl/class-dhl-config.php',
			'dhl/class-dhl-http-transport.php',
			'dhl/class-dhl-token-store.php',
			'dhl/class-dhl-client.php',
			'dhl/class-dhl-address-resolver.php',
			'dhl/class-dhl-order-mapper.php',
			'dhl/class-dhl-provider.php',
		);
	}
}
