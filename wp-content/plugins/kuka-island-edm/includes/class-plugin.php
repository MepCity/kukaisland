<?php
/**
 * Composition root for the EDM plugin.
 *
 * Nothing is required or registered until boot() has satisfied itself that the
 * dependencies are present. A fiscal integration with half its dependencies is
 * not a degraded integration, it is an unpredictable one, so the missing-
 * dependency path opens no hook, builds no client and writes nothing.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_EDM_Plugin {

	private static ?self $instance = null;

	private ?Kuka_Island_Core_Invoice $invoice = null;

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
	public const OWN_SLUG = 'kuka-island-edm';

	/**
	 * Loaded class => plugin slug, for every dependency.
	 *
	 * WooCommerce supplies the orders and the fulfilment events; Core supplies
	 * the billing preference fields and the payment guards the invoice gates
	 * read.
	 *
	 * Kept as a map rather than as a chain of if statements because the pairing
	 * is the part that can be wrong: this method once reported
	 * 'kuka-island-edm' when Kuka_Island_Core_Plugin was missing, which told an
	 * administrator to go and activate the plugin they were already looking at.
	 * A map can be asserted; a hand-written string in a branch cannot.
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
	 * Separated from rendering so the exact text an administrator would read
	 * can be asserted without a WordPress admin request.
	 *
	 * @param array<int, string> $missing Dependency slugs.
	 */
	public static function dependency_notice_text( array $missing ): string {
		return sprintf(
			/* translators: %s: comma separated plugin slugs. */
			__( 'Kuka Island EDM devre dışı: gerekli eklentiler yüklenmedi (%s). Hiçbir fatura işlemi kaydedilmedi.', 'kuka-island-edm' ),
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

		require_once KUKA_ISLAND_EDM_PATH . 'includes/class-invoice.php';

		$this->invoice = new Kuka_Island_Core_Invoice();
		$this->invoice->register();
		$this->booted = true;
	}

	public function is_booted(): bool {
		return $this->booted;
	}

	/** @return array<int, string> */
	public function missing(): array {
		return $this->missing;
	}

	public function get_invoice(): ?Kuka_Island_Core_Invoice {
		return $this->invoice;
	}

	/**
	 * Say why nothing is running, in the one place an administrator will look.
	 *
	 * Silence would be worse than a notice: an operator who thinks invoicing is
	 * live when no hook is registered has no reason to check.
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
