<?php
/** Consent-led newsletter signup storage and administration. */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Newsletter {
	private const DB_VERSION = '2';
	private const DB_OPTION = 'kuka_newsletter_db_version';
	private const RATE_SECONDS = 60;
	private const IP_RATE_SECONDS = 600;
	private const IP_RATE_LIMIT = 5;
	private const CONFIRMATION_SECONDS = 172800;

	public function register(): void {
		add_action( 'init', array( $this, 'maybe_install_schema' ), 5 );
		add_action( 'admin_post_nopriv_kuka_newsletter_subscribe', array( $this, 'subscribe' ) );
		add_action( 'admin_post_kuka_newsletter_subscribe', array( $this, 'subscribe' ) );
		add_action( 'admin_post_nopriv_kuka_newsletter_confirm', array( $this, 'confirm' ) );
		add_action( 'admin_post_kuka_newsletter_confirm', array( $this, 'confirm' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 30 );
		add_action( 'admin_post_kuka_newsletter_export', array( $this, 'export_csv' ) );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'kuka_newsletter_subscribers';
	}

	public static function install_schema(): void {
		global $wpdb;
		$previous_version = (string) get_option( self::DB_OPTION, '' );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(191) NOT NULL,
				consent_text text NOT NULL,
				consented_at datetime NOT NULL,
				ip_address varchar(45) NOT NULL,
				locale varchar(5) NOT NULL DEFAULT 'tr',
				status varchar(20) NOT NULL DEFAULT 'pending',
				confirmation_hash char(64) NOT NULL DEFAULT '',
				confirmation_expires_at datetime NULL,
				confirmed_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				KEY status (status)
			) {$charset};"
		);
		// Version 1 rows already completed the former single-step consent flow.
		// Preserve that evidence and mark it confirmed instead of asking again.
		if ( '' !== $previous_version && version_compare( $previous_version, '2', '<' ) ) {
			$wpdb->query( "UPDATE {$table} SET status = 'confirmed', confirmed_at = consented_at WHERE confirmation_hash = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public function maybe_install_schema(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install_schema();
		}
	}

	public static function form(): string {
		$content = Kuka_Island_Core_Site_Appearance::get();
		if ( class_exists( 'Kuka_Island_Core_Language' ) ) { $content = Kuka_Island_Core_Language::localized_content( $content ); }
		$consent = trim( (string) ( $content['footer']['newsletter_consent'] ?? '' ) );
		$status = sanitize_key( (string) ( $_GET['newsletter'] ?? '' ) );
		$messages = array(
			'success' => array( 'success', __( 'Doğrulama bağlantısını e-posta adresinize gönderdik.', 'kuka-island-core' ) ),
			'confirmed' => array( 'success', __( 'E-posta adresiniz doğrulandı. Bülten kaydınız tamamlandı.', 'kuka-island-core' ) ),
			'expired' => array( 'error', __( 'Doğrulama bağlantısı geçersiz veya süresi dolmuş. Lütfen yeniden kaydolun.', 'kuka-island-core' ) ),
			'consent' => array( 'error', __( 'Devam etmek için onay kutusunu işaretleyin.', 'kuka-island-core' ) ),
			'invalid' => array( 'error', __( 'Geçerli bir e-posta adresi girin.', 'kuka-island-core' ) ),
			'rate' => array( 'error', __( 'Lütfen yeniden göndermeden önce kısa bir süre bekleyin.', 'kuka-island-core' ) ),
			'error' => array( 'error', __( 'Kayıt şu anda tamamlanamadı. Lütfen tekrar deneyin.', 'kuka-island-core' ) ),
		);
		$html = '';
		if ( isset( $messages[ $status ] ) ) {
			$html .= '<p class="kuka-newsletter__message kuka-newsletter__message--' . esc_attr( $messages[ $status ][0] ) . '" role="status">' . esc_html( $messages[ $status ][1] ) . '</p>';
		}
		$html .= '<form class="kuka-newsletter__form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		$html .= '<input type="hidden" name="action" value="kuka_newsletter_subscribe">';
		$html .= '<input type="hidden" name="kuka_lang" value="' . esc_attr( self::request_locale() ) . '">';
		$html .= wp_nonce_field( 'kuka_newsletter_subscribe', 'kuka_newsletter_nonce', true, false );
		$html .= '<label class="kuka-newsletter__label" for="kuka-newsletter-email">' . esc_html__( 'E-posta adresi', 'kuka-island-core' ) . '</label>';
		$html .= '<div class="kuka-newsletter__field"><input id="kuka-newsletter-email" name="email" type="email" autocomplete="email" placeholder="name@example.com" required><button class="kuka-button" type="submit">' . esc_html__( 'Katıl', 'kuka-island-core' ) . '</button></div>';
		$html .= '<div class="kuka-newsletter__trap" aria-hidden="true"><label for="kuka-newsletter-company">' . esc_html__( 'Şirket', 'kuka-island-core' ) . '</label><input id="kuka-newsletter-company" name="company" type="text" tabindex="-1" autocomplete="off"></div>';
		$html .= '<label class="kuka-newsletter__consent"><input type="checkbox" name="consent" value="1" required><span>' . esc_html( $consent ) . '</span></label>';
		return $html . '</form>';
	}

	public function subscribe(): void {
		if ( ! isset( $_POST['kuka_newsletter_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kuka_newsletter_nonce'] ) ), 'kuka_newsletter_subscribe' ) ) {
			$this->redirect( 'error' );
		}
		if ( '' !== trim( (string) ( $_POST['company'] ?? '' ) ) ) {
			$this->redirect( 'success' );
		}
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			$this->redirect( 'invalid' );
		}
		if ( '1' !== (string) ( $_POST['consent'] ?? '' ) ) {
			$this->redirect( 'consent' );
		}
		$ip = self::request_ip();
		$rate_key = 'kuka_newsletter_rate_' . hash( 'sha256', $ip . '|' . strtolower( $email ) );
		$ip_rate_key = 'kuka_newsletter_ip_rate_' . hash( 'sha256', $ip );
		$ip_attempts = (int) get_transient( $ip_rate_key );
		if ( get_transient( $rate_key ) || $ip_attempts >= self::IP_RATE_LIMIT ) {
			$this->redirect( 'rate' );
		}
		set_transient( $rate_key, 1, self::RATE_SECONDS );
		set_transient( $ip_rate_key, $ip_attempts + 1, self::IP_RATE_SECONDS );

		$content = Kuka_Island_Core_Site_Appearance::get();
		$consent = trim( (string) ( $content['footer']['newsletter_consent'] ?? '' ) );
		global $wpdb;
		$table = self::table_name();
		$existing_status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'confirmed' === $existing_status ) {
			$this->redirect( 'success' );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$token_hash = self::confirmation_hash( $token );
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::CONFIRMATION_SECONDS );
		$locale = self::request_locale();
		// A duplicate pending request may rotate only its verification token. The
		// original consent wording, timestamp and IP evidence are never replaced.
		$stored = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (email, consent_text, consented_at, ip_address, locale, status, confirmation_hash, confirmation_expires_at) VALUES (%s, %s, %s, %s, %s, 'pending', %s, %s) ON DUPLICATE KEY UPDATE confirmation_hash = VALUES(confirmation_hash), confirmation_expires_at = VALUES(confirmation_expires_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email,
				$consent,
				current_time( 'mysql', true ),
				$ip,
				$locale,
				$token_hash,
				$expires
			)
		);
		if ( false === $stored ) {
			$this->redirect( 'error' );
		}
		$subscriber_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $subscriber_id < 1 ) {
			$this->redirect( 'error' );
		}
		$confirmation_url = add_query_arg(
			array( 'action' => 'kuka_newsletter_confirm', 'subscriber' => $subscriber_id, 'token' => $token, 'kuka_lang' => $locale ),
			admin_url( 'admin-post.php' )
		);
		$subject = 'en' === $locale ? 'Confirm your Kuka Island subscription' : 'Kuka Island bülten kaydınızı doğrulayın';
		$message = 'en' === $locale
			? "Confirm your subscription using this link (valid for 48 hours):\n\n{$confirmation_url}"
			: "Bülten kaydınızı bu bağlantıyla doğrulayın (48 saat geçerlidir):\n\n{$confirmation_url}";
		if ( ! self::send_mail( $email, $subject, $message ) ) {
			$this->redirect( 'error' );
		}
		$this->redirect( 'success' );
	}

	/** Complete double opt-in without changing the original consent evidence. */
	public function confirm(): void {
		$subscriber_id = absint( $_GET['subscriber'] ?? 0 );
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		if ( $subscriber_id < 1 || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			$this->redirect( 'expired' );
		}
		global $wpdb;
		$table = self::table_name();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT email, status, confirmation_hash, confirmation_expires_at FROM {$table} WHERE id = %d", $subscriber_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) || 'pending' !== $row['status'] || strtotime( (string) $row['confirmation_expires_at'] . ' UTC' ) < time() || ! hash_equals( (string) $row['confirmation_hash'], self::confirmation_hash( $token ) ) ) {
			$this->redirect( 'expired' );
		}
		$updated = $wpdb->update(
			$table,
			array( 'status' => 'confirmed', 'confirmed_at' => current_time( 'mysql', true ), 'confirmation_hash' => '', 'confirmation_expires_at' => null ),
			array( 'id' => $subscriber_id, 'status' => 'pending' ),
			array( '%s', '%s', '%s', null ),
			array( '%d', '%s' )
		);
		if ( 1 !== $updated ) {
			$this->redirect( 'error' );
		}
		$content = Kuka_Island_Core_Site_Appearance::get();
		$notify = sanitize_email( (string) ( $content['footer']['newsletter_notification_email'] ?? '' ) );
		if ( $notify ) {
			/* translators: %s: confirmed subscriber email address. */
			self::send_mail( $notify, __( 'Yeni bülten kaydı', 'kuka-island-core' ), sprintf( __( 'Yeni doğrulanmış kayıt: %s', 'kuka-island-core' ), $row['email'] ) );
		}
		$this->redirect( 'confirmed' );
	}

	private static function confirmation_hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	private static function request_locale(): string {
		$requested = sanitize_key( (string) wp_unslash( $_REQUEST['kuka_lang'] ?? '' ) );
		if ( 'en' === $requested ) {
			return 'en';
		}
		return class_exists( 'Kuka_Island_Core_Language' ) && Kuka_Island_Core_Language::is_english_request() ? 'en' : 'tr';
	}

	private static function send_mail( string $recipient, string $subject, string $message ): bool {
		return wp_mail( $recipient, $subject, $message );
	}

	private static function request_ip(): string {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private function redirect( string $status ): never {
		$referer = wp_get_referer() ?: ( 'en' === self::request_locale() ? home_url( '/en/' ) : home_url( '/' ) );
		wp_safe_redirect( add_query_arg( 'newsletter', $status, $referer ) . '#kuka-newsletter-title' );
		exit;
	}

	public function admin_menu(): void {
		add_submenu_page( 'kuka-island', __( 'Bülten Kayıtları', 'kuka-island-core' ), __( 'Bülten Kayıtları', 'kuka-island-core' ), 'manage_woocommerce', 'kuka-newsletter', array( $this, 'admin_page' ) );
	}

	public function admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'kuka-island-core' ) ); }
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, email, consent_text, consented_at, ip_address, status, confirmed_at FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT 500', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Bülten Kayıtları', 'kuka-island-core' ); ?></h1>
		<p><?php esc_html_e( 'Bu ekran yalnız kayıt ve onay kanıtını listeler; toplu e-posta göndermez.', 'kuka-island-core' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kuka_newsletter_export' ), 'kuka_newsletter_export' ) ); ?>"><?php esc_html_e( 'CSV dışa aktar', 'kuka-island-core' ); ?></a></p>
		<table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'E-posta', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'Onay metni', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'İlk onay (UTC)', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'Durum', 'kuka-island-core' ); ?></th><th><?php esc_html_e( 'Doğrulama (UTC)', 'kuka-island-core' ); ?></th><th>IP</th></tr></thead><tbody>
		<?php foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( (string) $row['id'] ); ?></td><td><?php echo esc_html( $row['email'] ); ?></td><td><?php echo esc_html( $row['consent_text'] ); ?></td><td><?php echo esc_html( $row['consented_at'] ); ?></td><td><?php echo esc_html( $row['status'] ); ?></td><td><?php echo esc_html( (string) $row['confirmed_at'] ); ?></td><td><?php echo esc_html( $row['ip_address'] ); ?></td></tr><?php endforeach; ?>
		<?php if ( ! $rows ) : ?><tr><td colspan="7"><?php esc_html_e( 'Henüz kayıt yok.', 'kuka-island-core' ); ?></td></tr><?php endif; ?>
		</tbody></table></div>
		<?php
	}

	public function export_csv(): never {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'kuka-island-core' ), 403 ); }
		check_admin_referer( 'kuka_newsletter_export' );
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, email, consent_text, consented_at, ip_address, status, confirmed_at FROM ' . self::table_name() . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="kuka-newsletter.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'email', 'consent_text', 'consented_at_utc', 'ip_address', 'status', 'confirmed_at_utc' ) );
		foreach ( $rows as $row ) { fputcsv( $out, $row ); }
		fclose( $out );
		exit;
	}
}
