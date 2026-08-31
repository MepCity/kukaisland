<?php
/**
 * Invoice Admin Order Details Metabox (HPOS Compatible).
 *
 * Provides a clean, read-only administration interface within the WooCommerce
 * order screen without altering native order fulfillment or payment panels.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Admin {
	private Kuka_Island_Core_Invoice_Manager $manager;

	public function __construct( ?Kuka_Island_Core_Invoice_Manager $manager = null ) {
		$this->manager = $manager ?? new Kuka_Island_Core_Invoice_Manager();
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20, 2 );
		add_action( 'admin_post_kuka_invoice_requery', array( $this, 'handle_requery_action' ) );
		add_action( 'admin_post_kuka_invoice_manual_send', array( $this, 'handle_manual_send_action' ) );
	}

	public function add_meta_box( string $post_type_or_screen, $post_or_order = null ): void {
		$screen_id = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop_order' ) : 'shop_order';

		$screens = array( 'shop_order', 'woocommerce_page_wc-orders', $screen_id );
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'kuka_island_invoice_box',
				__( 'e-Fatura / e-Arşiv Durumu', 'kuka-island-core' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the order metabox.
	 *
	 * @param WP_Post|WC_Order $post_or_order Order or post.
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Sipariş bilgisi bulunamadı.', 'kuka-island-core' ) . '</p>';
			return;
		}

		$config       = $this->manager->get_config();
		$data         = Kuka_Island_Core_Invoice_Order_Store::get_invoice_data( $order );
		$status       = $data['status'];
		$status_label = Kuka_Island_Core_Invoice_Status::get_label( $status );
		$type_label   = Kuka_Island_Core_Invoice_Status::get_type_label( $data['document_type'] );
		$style        = Kuka_Island_Core_Invoice_Status::get_badge_style( $status );

		$order_id = $order->get_id();
		?>
		<div class="kuka-invoice-panel" style="font-size: 13px; line-height: 1.5;">
			<?php if ( ! $config->is_auto_send_enabled() ) : ?>
				<div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 4px; padding: 8px 10px; margin-bottom: 12px; color: #92400e; font-size: 12px;">
					<strong><?php esc_html_e( 'Bilgi:', 'kuka-island-core' ); ?></strong>
					<?php esc_html_e( 'EDM test erişimi ve muhasebe onayları bekleniyor. Otomatik gönderim kapalıdır.', 'kuka-island-core' ); ?>
				</div>
			<?php endif; ?>

			<div style="margin-bottom: 10px;">
				<span style="display: inline-block; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 12px; background: <?php echo esc_attr( $style['bg'] ); ?>; color: <?php echo esc_attr( $style['color'] ); ?>; border: 1px solid <?php echo esc_attr( $style['border'] ); ?>;">
					<?php echo esc_html( $status_label ); ?>
				</span>
				<span style="display: inline-block; margin-left: 6px; font-weight: 500; color: #4b5563;">
					(<?php echo esc_html( $type_label ); ?>)
				</span>
			</div>

			<table style="width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 12px;">
				<?php if ( ! empty( $data['invoice_number'] ) ) : ?>
					<tr>
						<td style="padding: 4px 0; color: #6b7280; width: 40%;"><?php esc_html_e( 'Fatura No:', 'kuka-island-core' ); ?></td>
						<td style="padding: 4px 0; font-weight: 600; font-family: monospace;"><?php echo esc_html( $data['invoice_number'] ); ?></td>
					</tr>
				<?php endif; ?>

				<?php if ( ! empty( $data['uuid'] ) ) : ?>
					<tr>
						<td style="padding: 4px 0; color: #6b7280;"><?php esc_html_e( 'EDM UUID:', 'kuka-island-core' ); ?></td>
						<td style="padding: 4px 0; font-family: monospace; font-size: 11px; word-break: break-all;"><?php echo esc_html( $data['uuid'] ); ?></td>
					</tr>
				<?php endif; ?>

				<?php if ( ! empty( $data['sent_at'] ) ) : ?>
					<tr>
						<td style="padding: 4px 0; color: #6b7280;"><?php esc_html_e( 'Gönderim:', 'kuka-island-core' ); ?></td>
						<td style="padding: 4px 0;"><?php echo esc_html( wp_date( 'd.m.Y H:i', $data['sent_at'] ) ); ?></td>
					</tr>
				<?php endif; ?>

				<?php if ( ! empty( $data['last_queried_at'] ) ) : ?>
					<tr>
						<td style="padding: 4px 0; color: #6b7280;"><?php esc_html_e( 'Son Sorgu:', 'kuka-island-core' ); ?></td>
						<td style="padding: 4px 0;"><?php echo esc_html( wp_date( 'd.m.Y H:i', $data['last_queried_at'] ) ); ?></td>
					</tr>
				<?php endif; ?>

				<tr>
					<td style="padding: 4px 0; color: #6b7280;"><?php esc_html_e( 'Ortam:', 'kuka-island-core' ); ?></td>
					<td style="padding: 4px 0;"><?php echo esc_html( $config->is_live() ? 'Canlı (Production)' : 'Test (Sandbox)' ); ?></td>
				</tr>
			</table>

			<?php if ( Kuka_Island_Core_Invoice_Status::is_blocked( $status ) ) : ?>
				<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 6px 8px; margin-bottom: 10px; color: #991b1b; font-size: 11px;">
					<?php esc_html_e( 'Fatura hiç gönderilmedi: zorunlu bir sözleşme doğrulanmadığı için işlem güvenli biçimde durduruldu.', 'kuka-island-core' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $data['last_error'] ) ) : ?>
				<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 6px 8px; margin-bottom: 10px; color: #991b1b; font-size: 11px;">
					<strong><?php esc_html_e( 'Hata Kodu:', 'kuka-island-core' ); ?></strong>
					<code><?php echo esc_html( $data['last_error'] ); ?></code>
				</div>
			<?php endif; ?>

			<div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px;">
				<?php if ( ! empty( $data['uuid'] ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
						<?php wp_nonce_field( 'kuka_invoice_requery_' . $order_id, '_kuka_inv_nonce' ); ?>
						<input type="hidden" name="action" value="kuka_invoice_requery">
						<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>">
						<button type="submit" class="button button-secondary" style="font-size: 11px; padding: 0 8px; height: 26px; line-height: 24px;">
							<?php esc_html_e( 'Durumu Sorgula', 'kuka-island-core' ); ?>
						</button>
					</form>
				<?php endif; ?>

				<?php if ( $config->can_send_invoice() && Kuka_Island_Core_Invoice_Status::can_retry( $status ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
						<?php wp_nonce_field( 'kuka_invoice_manual_send_' . $order_id, '_kuka_inv_nonce' ); ?>
						<input type="hidden" name="action" value="kuka_invoice_manual_send">
						<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>">
						<button type="submit" class="button button-primary" style="font-size: 11px; padding: 0 8px; height: 26px; line-height: 24px;" onclick="return confirm('<?php esc_attr_e( 'Fatura EDM sistemine iletilecek. Onaylıyor musunuz?', 'kuka-island-core' ); ?>');">
							<?php esc_html_e( 'Faturayı Gönder', 'kuka-island-core' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function handle_requery_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order_id = absint( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'kuka_invoice_requery_' . $order_id, '_kuka_inv_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yetersiz.', 'kuka-island-core' ) );
		}

		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			try {
				$this->manager->query_order_status( $order );
			} catch ( Exception $e ) {
				// Handled by manager and store.
			}
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders' ) );
		exit;
	}

	public function handle_manual_send_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order_id = absint( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'kuka_invoice_manual_send_' . $order_id, '_kuka_inv_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yetersiz.', 'kuka-island-core' ) );
		}

		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$status = Kuka_Island_Core_Invoice_Order_Store::get_status( $order );
			if ( ! Kuka_Island_Core_Invoice_Status::is_terminal( $status ) && Kuka_Island_Core_Invoice_Status::can_retry( $status ) ) {
				try {
					$this->manager->process_order( $order, true );
				} catch ( Exception $e ) {
					// Handled by manager and store.
				}
			}
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders' ) );
		exit;
	}
}
