<?php
/**
 * Composition root for the shipping automation plugin.
 *
 * Nothing is required or registered until boot() has satisfied itself that the
 * dependencies are present. A carrier integration with half its dependencies is
 * not a degraded integration, it is an unpredictable one, so the missing-
 * dependency path opens no hook, builds no client and writes nothing.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Plugin {

	private static ?self $instance = null;

	private ?Kuka_Island_Shipping_Automation $automation = null;

	/** @var array<int, string> */
	private array $missing = array();

	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** This plugin's own slug, which can therefore never be a dependency. */
	public const OWN_SLUG = 'kuka-island-shipping-automation';

	/**
	 * Loaded class => plugin slug, for every dependency.
	 *
	 * WooCommerce supplies the orders, the addresses and the Fulfillments
	 * entity a booked shipment is written back to; Core supplies the shared
	 * language and admin surface the panel sits in.
	 *
	 * Kept as a map rather than as a chain of if statements because the pairing
	 * is the part that can be wrong: naming the wrong slug tells an
	 * administrator to activate the plugin they are already looking at.
	 *
	 * Membership is checked as loaded CLASSES rather than as entries in the
	 * active-plugins option: a plugin can be active and still have failed to
	 * load.
	 *
	 * @return array<string, string>
	 */
	public static function dependency_map(): array {
		return array(
			'WooCommerce'             => 'woocommerce',
			'Kuka_Island_Core_Plugin' => 'kuka-island-core',
		);
	}

	/**
	 * Slugs of the dependencies that did not load.
	 *
	 * @return array<int, string> Empty when every dependency is satisfied.
	 */
	public static function missing_dependencies(): array {
		$missing = array();

		foreach ( self::dependency_map() as $class_name => $slug ) {
			if ( ! class_exists( $class_name ) ) {
				$missing[] = $slug;
			}
		}

		return $missing;
	}

	/**
	 * The administrator-facing sentence, as a pure function of the slug list.
	 *
	 * Separated from rendering so the exact text an administrator would read can
	 * be asserted without a WordPress admin request.
	 *
	 * @param array<int, string> $missing Dependency slugs.
	 */
	public static function dependency_notice_text( array $missing ): string {
		return sprintf(
			/* translators: %s: comma separated plugin slugs. */
			__( 'Kuka Island Shipping Automation devre dışı: gerekli eklentiler yüklenmedi (%s). Hiçbir kargo işlemi yapılmadı; manuel kargo süreci etkilenmedi.', 'kuka-island-shipping-automation' ),
			implode( ', ', $missing )
		);
	}

	public function boot(): void {
		// Idempotent: a second boot() must not register a second set of hooks.
		if ( $this->booted ) {
			return;
		}

		$this->missing = self::missing_dependencies();

		if ( array() !== $this->missing ) {
			$this->booted = true;

			add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );

			return;
		}

		require_once KUKA_ISLAND_SHIPPING_PATH . 'includes/class-shipping-automation.php';

		$this->automation = new Kuka_Island_Shipping_Automation();
		$this->automation->register();
		$this->booted = true;
	}

	public function is_booted(): bool {
		return $this->booted;
	}

	/** @return array<int, string> */
	public function missing(): array {
		return $this->missing;
	}

	public function get_automation(): ?Kuka_Island_Shipping_Automation {
		return $this->automation;
	}

	/**
	 * Say why nothing is running, in the one place an administrator will look.
	 *
	 * Silence would be worse than a notice: an operator who thinks shipments are
	 * being booked when no hook is registered has no reason to check.
	 */
	public function render_dependency_notice(): void {
		if ( array() === $this->missing || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( self::dependency_notice_text( $this->missing ) )
		);
	}

	private function __construct() {}
}
