<?php
/**
 * The order-screen panel.
 *
 * A side metabox, exactly like the invoice module's. It adds NOTHING to the
 * WooCommerce fulfilment drawer: no stylesheet, no script, no markup inside the
 * drawer, no MutationObserver, no wheel or touch handler and no document scroll
 * lock. The drawer's scroll behaviour is governed by one CSS rule in Core that
 * this plugin does not touch and must not touch -- see
 * docs/KARGO_SCROLL_KORUMA_NOTU.md.
 *
 * Every action is a POST with a nonce and a capability check, and every one of
 * them is a DELIBERATE press. There is no automatic path from an order status
 * change, a payment or a checkout to any of these buttons. Each action carries
 * its OWN nonce, namespaced by the action name and the order id, so a form
 * issued for one action cannot be replayed as another -- the resume action in
 * particular is a carrier write and does not ride on the create action's nonce.
 *
 * Every button label that names the courier gets the name from
 * $carrier->get_label(). Hard-coding one courier's name on a screen that is
 * meant to serve any registered adapter is how an operator ends up reading
 * "DHL" while a parcel goes to somebody else.
 *
 * AND THE COURIER IS THE ORDER'S, NOT THE SHOP'S. The panel used to render
 * whatever default_carrier_key() answered, so a shop that changed its default
 * would show every historical order as belonging to the new courier -- and the
 * buttons underneath would have acted on it. The carrier comes from
 * Manager::carrier_ownership(), which reads the order; the default is used only
 * for an order nothing has been sent for. A record whose owner is unknown says
 * so and offers no buttons at all.
 *
 * The manual route is stated on the panel itself, in every state, because an
 * operator looking at a failed automation needs to be told -- there, not in a
 * document -- that WooCommerce's own tracking-number field still works.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Admin {

	private Kuka_Island_Shipping_Manager $manager;

	public function __construct( ?Kuka_Island_Shipping_Manager $manager = null ) {
		$this->manager = $manager ?? new Kuka_Island_Shipping_Manager();
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20, 2 );
		add_action( 'admin_post_kuka_shipping_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_kuka_shipping_resume', array( $this, 'handle_resume' ) );
		add_action( 'admin_post_kuka_shipping_requery', array( $this, 'handle_requery' ) );
		add_action( 'admin_post_kuka_shipping_reconcile', array( $this, 'handle_reconcile' ) );
		add_action( 'admin_post_kuka_shipping_update', array( $this, 'handle_update' ) );
		add_action( 'admin_post_kuka_shipping_cancel', array( $this, 'handle_cancel' ) );
	}

	/**
	 * @param string           $post_type_or_screen Screen or post type.
	 * @param WP_Post|WC_Order $post_or_order       Order or post.
	 */
	public function add_meta_box( string $post_type_or_screen, $post_or_order = null ): void {
		$screen_id = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop_order' ) : 'shop_order';

		foreach ( array_unique( array( 'shop_order', 'woocommerce_page_wc-orders', $screen_id ) ) as $screen ) {
			add_meta_box(
				'kuka_island_shipping_box',
				__( 'Kargo Otomasyonu', 'kuka-island-shipping-automation' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * One short sentence saying what an operator can do next.
	 *
	 * Public and static so the exact wording can be asserted without rendering
	 * an admin screen.
	 *
	 * @param WC_Order                                     $order    Order.
	 * @param Kuka_Island_Shipping_Carrier_Interface|null  $carrier  Carrier, or null when none is registered.
	 */
	public static function operator_hint( WC_Order $order, ?Kuka_Island_Shipping_Carrier_Interface $carrier, string $ownership_code = '' ): string {
		if ( 'shipment_provider_missing' === $ownership_code ) {
			return __( 'Bu siparişte taşıyıcı kaydı var fakat hangi taşıyıcıya ait olduğu yazılı değil. Otomatik işlem yapılmaz; varsayılan taşıyıcı kullanılmaz. Manuel kargo süreci kullanılabilir.', 'kuka-island-shipping-automation' );
		}

		if ( null === $carrier ) {
			return __( 'Kayıtlı kargo firması yok. Manuel kargo süreci kullanılabilir.', 'kuka-island-shipping-automation' );
		}

		$readiness = $carrier->get_readiness();

		if ( $readiness['live_blocked'] ) {
			return __( 'Canlı ortam bloke: resmî üretim uçları doğrulanmadı. Otomatik kargo kapalı, manuel kargo açık.', 'kuka-island-shipping-automation' );
		}

		if ( ! $readiness['ready'] ) {
			return sprintf(
				/* translators: %s: comma separated configuration field names. */
				__( 'Kimlik yapılandırması eksik (%s). Otomatik kargo kapalı, manuel kargo açık.', 'kuka-island-shipping-automation' ),
				implode( ', ', $readiness['gaps'] )
			);
		}

		$cod = Kuka_Island_Shipping_Manager::cod_gate( $order );

		if ( ! $cod['ok'] ) {
			return $cod['message'];
		}

		$state = Kuka_Island_Shipping_Order_Store::get_state( $order );

		return match ( $state ) {
			Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED       => __( 'Taşıyıcıda sipariş kaydı var, gönderi/barkod aşaması tamamlanmamış. Sipariş yeniden oluşturulmaz; yalnız barkod aşaması sürdürülür.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED => __( 'Belirsiz taşıyıcı yanıtı var. Yeniden gönderim yapılmaz; önce mutabakat sorgusu çalıştırın.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED   => __( 'Mutabakat taşıyıcıda kayıt olmadığını gösterdi. Yeniden oluşturma açık bir işlemdir.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW      => __( 'Kargo durumu manuel inceleme bekliyor.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_DELIVERED          => __( 'Gönderi teslim edildi.', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_CANCELLED          => __( 'Taşıyıcı kaydı iptal edildi.', 'kuka-island-shipping-automation' ),
			default                                                    => '',
		};
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order Order or post.
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order );

		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Sipariş bilgisi bulunamadı.', 'kuka-island-shipping-automation' ) . '</p>';

			return;
		}

		/*
		 * The order's own carrier. carrier_ownership() writes nothing, which
		 * matters here: the operations' resolver records a refusal on the order
		 * and adds a note, and a page render must not do either.
		 */
		$ownership = $this->manager->carrier_ownership( $order );
		$carrier   = '' === (string) $ownership['code']
			? $this->manager->get_registry()->get( (string) $ownership['key'] )
			: null;
		$data      = Kuka_Island_Shipping_Order_Store::get_shipment_data( $order );
		$hint      = self::operator_hint( $order, $carrier, (string) $ownership['code'] );
		$order_id  = (int) $order->get_id();

		echo '<p><strong>' . esc_html__( 'Taşıyıcı:', 'kuka-island-shipping-automation' ) . '</strong> ';
		echo esc_html( self::carrier_label( $carrier, (string) $ownership['key'], (string) $ownership['code'] ) );
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Durum:', 'kuka-island-shipping-automation' ) . '</strong> ';
		echo esc_html( self::state_label( $data['state'] ) );
		echo '</p>';

		if ( '' !== $data['reference'] ) {
			echo '<p><strong>' . esc_html__( 'Referans:', 'kuka-island-shipping-automation' ) . '</strong> <code>' . esc_html( $data['reference'] ) . '</code></p>';
		}

		if ( '' !== $data['shipment_id'] ) {
			echo '<p><strong>' . esc_html__( 'Gönderi no:', 'kuka-island-shipping-automation' ) . '</strong> <code>' . esc_html( $data['shipment_id'] ) . '</code></p>';
		}

		if ( array() !== $data['barcodes'] ) {
			echo '<p><strong>' . esc_html__( 'Barkodlar:', 'kuka-island-shipping-automation' ) . '</strong> ' . esc_html( implode( ', ', $data['barcodes'] ) ) . '</p>';
		}

		if ( 0 !== $data['status_code'] ) {
			echo '<p><strong>' . esc_html__( 'Kargo durumu:', 'kuka-island-shipping-automation' ) . '</strong> ' . esc_html( Kuka_Island_Shipping_Status::label_for( $data['status_code'] ) ) . '</p>';
		}

		if ( '' !== $data['tracking_url'] ) {
			echo '<p><a href="' . esc_url( $data['tracking_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Takip bağlantısı', 'kuka-island-shipping-automation' ) . '</a></p>';
		}

		if ( '' !== $data['last_error'] ) {
			echo '<p><strong>' . esc_html__( 'Son hata kodu:', 'kuka-island-shipping-automation' ) . '</strong> <code>' . esc_html( $data['last_error'] ) . '</code></p>';
		}

		if ( '' !== $hint ) {
			echo '<p>' . esc_html( $hint ) . '</p>';
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$creatable = null !== $carrier
			&& $carrier->get_readiness()['ready']
			&& Kuka_Island_Shipping_Manager::cod_gate( $order )['ok']
			&& ! in_array( $data['state'], Kuka_Island_Shipping_Order_Store::states_blocking_create(), true );

		if ( $creatable ) {
			$this->action_button( $order_id, 'kuka_shipping_create', self::create_button_label( $carrier ) );
		}

		/*
		 * The way out of order_created. The carrier already holds an order, so
		 * the create button is correctly absent; this one continues from the
		 * barcode stage and never re-registers the order.
		 */
		$resumable = null !== $carrier
			&& $carrier->get_readiness()['ready']
			&& Kuka_Island_Shipping_Manager::cod_gate( $order )['ok']
			&& Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $data['state'];

		if ( $resumable ) {
			$this->action_button( $order_id, 'kuka_shipping_resume', self::resume_button_label( $carrier ) );
		}

		if ( Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED === $data['state'] ) {
			$this->action_button( $order_id, 'kuka_shipping_reconcile', __( 'Mutabakat sorgusu çalıştır (salt-okunur)', 'kuka-island-shipping-automation' ) );
		}

		if ( in_array( $data['state'], array( Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED, Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW, Kuka_Island_Shipping_Order_Store::STATE_DELIVERED ), true ) ) {
			$this->action_button( $order_id, 'kuka_shipping_requery', __( 'Kargo durumunu sorgula', 'kuka-island-shipping-automation' ) );
		}

		/*
		 * The screen offers exactly what the manager will accept, including the
		 * shipment-id condition: a record that says a shipment exists without
		 * saying which one cannot be addressed, so neither button appears. A
		 * button that leads only to a refusal teaches an operator to distrust
		 * the panel.
		 */
		$mutable = null !== $carrier
			&& $carrier->get_readiness()['ready']
			&& (
				Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED === $data['state']
				|| ( Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED === $data['state'] && '' !== $data['shipment_id'] )
			);

		if ( $mutable ) {
			$this->action_button( $order_id, 'kuka_shipping_update', __( 'Taşıyıcı kaydını güncelle', 'kuka-island-shipping-automation' ) );
			$this->action_button( $order_id, 'kuka_shipping_cancel', __( 'Taşıyıcı kaydını iptal et', 'kuka-island-shipping-automation' ) );
		}

		echo '<p class="description">' . esc_html__( 'Manuel kargo yolu her zaman açıktır: WooCommerce kargo çekmecesinden takip numarasını elle girebilirsiniz.', 'kuka-island-shipping-automation' ) . '</p>';
	}

	/**
	 * What the panel prints next to "Taşıyıcı:".
	 *
	 * The adapter's own label when it is registered; the stored key when the
	 * order names a carrier this installation no longer has -- which is worth
	 * seeing verbatim, because it is the reason nothing works -- and an explicit
	 * "unknown" when the record has no owner at all.
	 *
	 * Public and static so the exact wording can be asserted without rendering
	 * an admin screen.
	 */
	public static function carrier_label( ?Kuka_Island_Shipping_Carrier_Interface $carrier, string $key, string $ownership_code = '' ): string {
		if ( null !== $carrier ) {
			return $carrier->get_label();
		}

		if ( 'shipment_provider_missing' === $ownership_code ) {
			return __( 'kayıtlı değil (siparişte taşıyıcı yazılı değil)', 'kuka-island-shipping-automation' );
		}

		if ( '' !== $key ) {
			return sprintf(
				/* translators: %s: stored carrier key, e.g. dhl. */
				__( '%s (bu kurulumda kayıtlı değil)', 'kuka-island-shipping-automation' ),
				$key
			);
		}

		return __( 'kayıtlı değil', 'kuka-island-shipping-automation' );
	}

	/**
	 * "Create a shipment at <carrier>", in the carrier's own name.
	 *
	 * Public and static so the wording an operator reads can be asserted
	 * without an admin request, and so a second adapter's label appears here by
	 * construction rather than by somebody remembering to change a string.
	 */
	public static function create_button_label( Kuka_Island_Shipping_Carrier_Interface $carrier ): string {
		return sprintf(
			/* translators: %s: carrier name, e.g. DHL eCommerce Türkiye. */
			__( '%s gönderisi oluştur', 'kuka-island-shipping-automation' ),
			$carrier->get_label()
		);
	}

	/**
	 * "Continue the shipment/barcode stage at <carrier>".
	 *
	 * Says what it does and what it does NOT do, because the state it appears in
	 * is the one where an operator most needs to know that pressing it cannot
	 * register a second order at the carrier.
	 */
	public static function resume_button_label( Kuka_Island_Shipping_Carrier_Interface $carrier ): string {
		return sprintf(
			/* translators: %s: carrier name, e.g. DHL eCommerce Türkiye. */
			__( '%s gönderi/barkod oluşturmayı sürdür (sipariş yeniden oluşturulmaz)', 'kuka-island-shipping-automation' ),
			$carrier->get_label()
		);
	}

	/**
	 * One nonce-protected POST button.
	 */
	private function action_button( int $order_id, string $action, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:8px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '" />';
		wp_nonce_field( $action . '_' . $order_id, '_kuka_ship_nonce' );
		echo '<button type="submit" class="button">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	public static function state_label( string $state ): string {
		return match ( $state ) {
			Kuka_Island_Shipping_Order_Store::STATE_NONE               => __( 'Kargo işlemi başlatılmadı', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_ORDER_CREATED      => __( 'Taşıyıcıda sipariş oluşturuldu', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_SHIPMENT_CREATED   => __( 'Gönderi oluşturuldu', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_RECONCILE_REQUIRED => __( 'Belirsiz — mutabakat gerekiyor', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_ABSENT_CONFIRMED   => __( 'Taşıyıcıda kayıt yok (doğrulandı)', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_DELIVERED          => __( 'Teslim edildi', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_MANUAL_REVIEW      => __( 'Manuel inceleme gerekiyor', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_CANCELLED          => __( 'İptal edildi', 'kuka-island-shipping-automation' ),
			Kuka_Island_Shipping_Order_Store::STATE_BLOCKED            => __( 'Engellendi — çağrı yapılmadı', 'kuka-island-shipping-automation' ),
			default                                                    => $state,
		};
	}

	/* ---------------------------------------------------------------------- */
	/* Action handlers                                                         */
	/* ---------------------------------------------------------------------- */

	public function handle_create(): void {
		$order = $this->authorise( 'kuka_shipping_create' );

		if ( $order instanceof WC_Order ) {
			$this->manager->create_shipment( $order );
		}

		$this->go_back();
	}

	/**
	 * Continue the barcode stage for an order the carrier already registered.
	 *
	 * Its own action name, therefore its own nonce namespace: the nonce a create
	 * form carries does not verify here and cannot. The capability is checked
	 * independently of every other action, in authorise(), before the manager is
	 * reached.
	 */
	public function handle_resume(): void {
		$this->run_resume();
		$this->go_back();
	}

	/**
	 * The resume handler without the redirect.
	 *
	 * Split out for one reason: go_back() ends the request, so a test driving
	 * handle_resume() would measure nothing. This method is the whole handler --
	 * nonce, capability, order lookup and the manager call -- so a measurement
	 * against it exercises the real authorisation path rather than a copy of it.
	 *
	 * @return array<string, mixed> The manager's result, or [] when there was no order.
	 */
	public function run_resume(): array {
		$order = $this->authorise( 'kuka_shipping_resume' );

		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		return $this->manager->resume_barcode( $order );
	}

	public function handle_requery(): void {
		$order = $this->authorise( 'kuka_shipping_requery' );

		if ( $order instanceof WC_Order ) {
			$this->manager->query_status( $order );
		}

		$this->go_back();
	}

	public function handle_reconcile(): void {
		$order = $this->authorise( 'kuka_shipping_reconcile' );

		if ( $order instanceof WC_Order ) {
			$this->manager->reconcile_order( $order );
		}

		$this->go_back();
	}

	public function handle_update(): void {
		$order = $this->authorise( 'kuka_shipping_update' );

		if ( $order instanceof WC_Order ) {
			$this->manager->update_shipment( $order );
		}

		$this->go_back();
	}

	public function handle_cancel(): void {
		$order = $this->authorise( 'kuka_shipping_cancel' );

		if ( $order instanceof WC_Order ) {
			$this->manager->cancel( $order );
		}

		$this->go_back();
	}

	/**
	 * Nonce, capability and order, in that order.
	 *
	 * @param string $action Action name; also the nonce namespace.
	 * @return WC_Order|null
	 */
	private function authorise( string $action ): ?WC_Order {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order_id = absint( $_POST['order_id'] ?? 0 );

		check_admin_referer( $action . '_' . $order_id, '_kuka_ship_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yetersiz.', 'kuka-island-shipping-automation' ) );
		}

		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order ? $order : null;
	}

	private function go_back(): void {
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders' ) );
		exit;
	}
}
