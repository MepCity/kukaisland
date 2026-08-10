<?php
/**
 * Fail-safe transactional e-mail delivery and optional SMTP transport.
 */

defined( 'ABSPATH' ) || exit;

use PHPMailer\PHPMailer\PHPMailer;

final class Kuka_Island_Core_Email_Delivery {
	private const FAILURE_META = '_kuka_email_delivery_failures';
	private const TEST_RESULT_PREFIX = 'kuka_email_test_result_';

	private static bool $sending = false;
	private static ?WC_Order $current_order = null;
	private static string $current_email_id = 'unknown';
	private static string $last_error = '';

	public function register(): void {
		add_filter( 'pre_wp_mail', array( $this, 'send_safely' ), -1000, 2 );
		add_action( 'wp_mail_failed', array( $this, 'capture_wp_mail_error' ) );
		add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		add_filter( 'woocommerce_email_from_address', array( $this, 'mail_from' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );
		add_filter( 'woocommerce_email_headers', array( $this, 'capture_order_context' ), 1, 4 );
		add_filter( 'kuka_island_operator_warnings', array( $this, 'add_operator_warnings' ) );
		add_action( 'kuka_island_start_page_email_tools', array( $this, 'render_test_tool' ) );
		add_action( 'admin_post_kuka_island_test_email', array( $this, 'handle_test_email' ) );
	}

	/**
	 * Run the complete WordPress mail call inside a Throwable boundary.
	 *
	 * The nested call is intentional: WordPress has no post-send filter and its
	 * own catch block does not catch PHP Error instances raised by a disabled
	 * mail(). The recursion guard lets the nested call reach the native sender.
	 *
	 * @param null|bool $short_circuit Existing pre_wp_mail result.
	 * @param array     $atts          Normalized wp_mail arguments.
	 * @return null|bool
	 */
	public function send_safely( $short_circuit, array $atts ) {
		if ( self::$sending || null !== $short_circuit ) {
			return $short_circuit;
		}

		self::$sending = true;
		self::$last_error = '';
		$sent = false;

		try {
			if ( ! self::smtp_is_configured() && ! function_exists( 'mail' ) ) {
				throw new RuntimeException( __( 'Sunucuda PHP mail() kapalı ve SMTP yapılandırılmamış.', 'kuka-island-core' ) );
			}

			$sent = wp_mail(
				$atts['to'] ?? '',
				(string) ( $atts['subject'] ?? '' ),
				(string) ( $atts['message'] ?? '' ),
				$atts['headers'] ?? '',
				$atts['attachments'] ?? array()
			);
		} catch ( Throwable $throwable ) {
			self::$last_error = self::safe_error_message( $throwable->getMessage() );
			$sent = false;
		} finally {
			self::$sending = false;
		}

		if ( ! $sent ) {
			$message = self::$last_error ?: __( 'E-posta altyapısı gönderimi reddetti.', 'kuka-island-core' );
			$this->record_failure( $message );
		} else {
			$this->clear_current_failure();
		}

		self::$current_order = null;
		self::$current_email_id = 'unknown';
		return (bool) $sent;
	}

	public function capture_wp_mail_error( WP_Error $error ): void {
		self::$last_error = self::safe_error_message( $error->get_error_message() );
	}

	/** Configure WordPress' existing PHPMailer instance from wp-config.php only. */
	public function configure_smtp( PHPMailer $phpmailer ): void {
		$config = self::smtp_config();
		if ( null === $config ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host = $config['host'];
		$phpmailer->Port = $config['port'];
		$phpmailer->SMTPAuth = true;
		$phpmailer->Username = $config['username'];
		$phpmailer->Password = $config['password'];
		$phpmailer->SMTPSecure = 'none' === $config['encryption'] ? '' : $config['encryption'];
		$phpmailer->setFrom( $config['from_email'], $config['from_name'], false );

		if ( '' !== $config['reply_to_email'] ) {
			$phpmailer->clearReplyTos();
			$phpmailer->addReplyTo( $config['reply_to_email'], $config['reply_to_name'] );
		}
	}

	public function mail_from( string $from ): string {
		unset( $from );
		return self::sender_email();
	}

	public function mail_from_name( string $name ): string {
		$config = self::smtp_config();
		return null === $config ? $name : $config['from_name'];
	}

	/** @param mixed $object WC passes either an order or a non-order object. */
	public function capture_order_context( string $headers, string $email_id, $object, $email = null ): string {
		unset( $email );
		self::$current_order = $object instanceof WC_Order ? $object : null;
		self::$current_email_id = sanitize_key( $email_id ) ?: 'unknown';
		return $headers;
	}

	/** Add measured transport and failed-order findings to the Start screen. */
	public function add_operator_warnings( array $warnings ): array {
		$orders_url = admin_url( 'admin.php?page=wc-orders' );
		if ( ! function_exists( 'mail' ) ) {
			$warnings[] = array(
				__( 'Sunucuda PHP mail() kapalı. SMTP kurulmadan sipariş e-postaları gönderilemez.', 'kuka-island-core' ),
				admin_url( 'admin.php?page=kuka-island#kuka-email-test' ),
			);
		}

		$failed_count = self::failed_order_count();
		if ( $failed_count > 0 ) {
			$warnings[] = array(
				/* translators: %d is the number of failed order e-mails. */
				sprintf( _n( '%d gönderilemeyen sipariş e-postası var.', '%d gönderilemeyen sipariş e-postası var.', $failed_count, 'kuka-island-core' ), $failed_count ),
				$orders_url,
			);
		}

		return $warnings;
	}

	public function render_test_tool(): void {
		$result = get_transient( self::TEST_RESULT_PREFIX . get_current_user_id() );
		delete_transient( self::TEST_RESULT_PREFIX . get_current_user_id() );
		?>
		<section id="kuka-email-test" class="card">
			<h2><?php esc_html_e( 'Sipariş e-postası', 'kuka-island-core' ); ?></h2>
			<p><?php esc_html_e( 'SMTP bağlantısını yöneticinin e-posta adresine zararsız bir test iletisi göndererek doğrulayın.', 'kuka-island-core' ); ?></p>
			<?php if ( is_array( $result ) ) : ?>
				<div class="notice inline <?php echo ! empty( $result['success'] ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( (string) ( $result['message'] ?? '' ) ); ?></p></div>
			<?php endif; ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="kuka_island_test_email">
				<?php wp_nonce_field( 'kuka_island_test_email' ); ?>
				<?php submit_button( __( 'Test e-postası gönder', 'kuka-island-core' ), 'secondary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}

	public function handle_test_email(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'kuka-island-core' ) );
		}
		check_admin_referer( 'kuka_island_test_email' );

		$recipient = sanitize_email( (string) get_option( 'admin_email' ) );
		$sent = false;
		if ( is_email( $recipient ) ) {
			$sent = wp_mail(
				$recipient,
				__( 'Kuka Island SMTP testi', 'kuka-island-core' ),
				__( 'Bu ileti Kuka Island sipariş e-postası bağlantı testi için gönderildi.', 'kuka-island-core' )
			);
		}

		if ( $sent ) {
			$message = __( 'Test e-postası gönderildi.', 'kuka-island-core' );
		} else {
			/* translators: %s is a sanitized mail transport error. */
			$message = sprintf( __( 'Test e-postası gönderilemedi: %s', 'kuka-island-core' ), self::$last_error ?: __( 'E-posta altyapısı gönderimi reddetti.', 'kuka-island-core' ) );
		}
		set_transient(
			self::TEST_RESULT_PREFIX . get_current_user_id(),
			array( 'success' => $sent, 'message' => self::safe_error_message( $message ) ),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=kuka-island#kuka-email-test' ) );
		exit;
	}

	public static function smtp_is_configured(): bool {
		return null !== self::smtp_config();
	}

	/** @return null|array<string, mixed> */
	private static function smtp_config(): ?array {
		$required = array( 'KUKA_SMTP_HOST', 'KUKA_SMTP_PORT', 'KUKA_SMTP_USERNAME', 'KUKA_SMTP_PASSWORD', 'KUKA_SMTP_ENCRYPTION', 'KUKA_SMTP_FROM_NAME' );
		foreach ( $required as $constant ) {
			if ( ! defined( $constant ) || '' === trim( (string) constant( $constant ) ) ) {
				return null;
			}
		}

		$encryption = strtolower( trim( (string) KUKA_SMTP_ENCRYPTION ) );
		$from_email = self::sender_email();
		if ( ! in_array( $encryption, array( 'tls', 'ssl', 'none' ), true ) || (int) KUKA_SMTP_PORT < 1 || ! is_email( $from_email ) || ! str_ends_with( strtolower( $from_email ), '@kukaisland.com' ) ) {
			return null;
		}

		$reply_to = defined( 'KUKA_SMTP_REPLY_TO_EMAIL' ) ? sanitize_email( (string) KUKA_SMTP_REPLY_TO_EMAIL ) : '';
		return array(
			'host' => trim( (string) KUKA_SMTP_HOST ),
			'port' => (int) KUKA_SMTP_PORT,
			'username' => (string) KUKA_SMTP_USERNAME,
			'password' => (string) KUKA_SMTP_PASSWORD,
			'encryption' => $encryption,
			'from_email' => $from_email,
			'from_name' => sanitize_text_field( (string) KUKA_SMTP_FROM_NAME ),
			'reply_to_email' => is_email( $reply_to ) ? $reply_to : '',
			'reply_to_name' => defined( 'KUKA_SMTP_REPLY_TO_NAME' ) ? sanitize_text_field( (string) KUKA_SMTP_REPLY_TO_NAME ) : '',
		);
	}

	/** Keep WordPress and WooCommerce on the public site e-mail source. */
	private static function sender_email(): string {
		$content = class_exists( 'Kuka_Island_Core_Site_Appearance' ) ? Kuka_Island_Core_Site_Appearance::get() : array();
		$email   = sanitize_email( (string) ( $content['brand']['email'] ?? '' ) );
		return is_email( $email ) ? $email : 'info@kukaisland.com';
	}

	private function record_failure( string $message ): void {
		$message = self::safe_error_message( $message );
		if ( self::$current_order instanceof WC_Order ) {
			$failures = self::$current_order->get_meta( self::FAILURE_META, true );
			$failures = is_array( $failures ) ? $failures : array();
			$failures[ self::$current_email_id ] = array( 'message' => $message, 'time' => time() );
			self::$current_order->update_meta_data( self::FAILURE_META, $failures );
			/* translators: %s is a sanitized mail transport error. */
			self::$current_order->add_order_note( sprintf( __( 'Sipariş e-postası gönderilemedi: %s', 'kuka-island-core' ), $message ) );
			self::$current_order->save();
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'kuka-email-delivery' ) );
		}
	}

	private function clear_current_failure(): void {
		if ( ! self::$current_order instanceof WC_Order ) {
			return;
		}
		$failures = self::$current_order->get_meta( self::FAILURE_META, true );
		if ( ! is_array( $failures ) || ! isset( $failures[ self::$current_email_id ] ) ) {
			return;
		}
		unset( $failures[ self::$current_email_id ] );
		if ( $failures ) {
			self::$current_order->update_meta_data( self::FAILURE_META, $failures );
		} else {
			self::$current_order->delete_meta_data( self::FAILURE_META );
		}
		self::$current_order->save();
	}

	private static function failed_order_count(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$order_ids = wc_get_orders(
			array(
				'limit' => -1,
				'return' => 'ids',
				'meta_key' => self::FAILURE_META,
				'meta_compare' => 'EXISTS',
			)
		);
		$count = 0;
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			$failures = $order instanceof WC_Order ? $order->get_meta( self::FAILURE_META, true ) : array();
			$count += is_array( $failures ) ? count( $failures ) : 0;
		}
		return $count;
	}

	private static function safe_error_message( string $message ): string {
		$secrets = array();
		foreach ( array( 'KUKA_SMTP_PASSWORD', 'KUKA_SMTP_USERNAME' ) as $constant ) {
			if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
				$secrets[] = (string) constant( $constant );
			}
		}
		$message = str_replace( $secrets, '[gizlendi]', $message );
		$message = wp_strip_all_tags( $message );
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
	}
}
