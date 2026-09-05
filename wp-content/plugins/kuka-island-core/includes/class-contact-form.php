<?php
/** Public contact form backed by the existing fail-safe mail transport. */

defined( 'ABSPATH' ) || exit;

use PHPMailer\PHPMailer\PHPMailer;

final class Kuka_Island_Core_Contact_Form {
	private const MIGRATION_OPTION = 'kuka_contact_form_version';
	private const MIGRATION_VERSION = 1;
	private const RESULT_PREFIX = 'kuka_contact_result_';
	private const RATE_SECONDS = 600;
	private const RATE_LIMIT = 5;
	private const PAIR_RATE_SECONDS = 60;
	private const SUBJECT_LIMIT = 120;
	private const MESSAGE_LIMIT = 4000;

	/** @var null|array{email:string,name:string} */
	private static ?array $reply_to = null;

	public function register(): void {
		add_action( 'init', array( $this, 'migrate_contact_page' ), 25 );
		add_shortcode( 'kuka_contact_form', array( $this, 'form' ) );
		add_action( 'admin_post_nopriv_kuka_contact_submit', array( $this, 'submit' ) );
		add_action( 'admin_post_kuka_contact_submit', array( $this, 'submit' ) );
		add_action( 'phpmailer_init', array( $this, 'apply_reply_to' ), 20 );
	}

	/** Render the Turkish or English form without persisting visitor data. */
	public function form(): string {
		$english = self::is_english();
		$result  = self::consume_result();
		$copy    = $english ? self::english_copy() : self::turkish_copy();
		$html    = '<div class="kuka-contact-form" id="kuka-contact-form">';

		if ( isset( $copy['messages'][ $result ] ) ) {
			$type  = 'success' === $result ? 'success' : 'error';
			$html .= '<p class="kuka-contact-form__message kuka-contact-form__message--' . esc_attr( $type ) . '" role="status">' . esc_html( $copy['messages'][ $result ] ) . '</p>';
		}

		$html .= '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		$html .= '<input type="hidden" name="action" value="kuka_contact_submit">';
		$html .= '<input type="hidden" name="kuka_lang" value="' . ( $english ? 'en' : 'tr' ) . '">';
		$html .= wp_nonce_field( 'kuka_contact_submit', 'kuka_contact_nonce', true, false );
		$html .= '<div class="kuka-contact-form__field"><label for="kuka-contact-email">' . esc_html( $copy['email'] ) . '</label><input id="kuka-contact-email" name="email" type="email" inputmode="email" autocomplete="email" maxlength="254" required></div>';
		$html .= '<div class="kuka-contact-form__field"><label for="kuka-contact-subject">' . esc_html( $copy['subject'] ) . '</label><input id="kuka-contact-subject" name="subject" type="text" autocomplete="off" maxlength="' . self::SUBJECT_LIMIT . '" required></div>';
		$html .= '<div class="kuka-contact-form__field"><label for="kuka-contact-message">' . esc_html( $copy['message'] ) . '</label><textarea id="kuka-contact-message" name="message" rows="8" maxlength="' . self::MESSAGE_LIMIT . '" required></textarea></div>';
		$html .= '<div class="kuka-contact-form__trap" aria-hidden="true"><label for="kuka-contact-company">' . esc_html( $copy['company'] ) . '</label><input id="kuka-contact-company" name="company" type="text" tabindex="-1" autocomplete="off"></div>';
		$html .= '<p class="kuka-contact-form__privacy">' . wp_kses_post( $copy['privacy'] ) . '</p>';
		$html .= '<button class="kuka-button" type="submit">' . esc_html( $copy['submit'] ) . '</button>';
		return $html . '</form></div>';
	}

	/** Validate the public request, send at most once, then use PRG. */
	public function submit(): never {
		$locale = self::request_locale();
		$nonce  = isset( $_POST['kuka_contact_nonce'] ) && is_string( $_POST['kuka_contact_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['kuka_contact_nonce'] ) )
			: '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'kuka_contact_submit' ) ) {
			$this->redirect( 'error', $locale );
		}

		$company = isset( $_POST['company'] ) && is_string( $_POST['company'] ) ? trim( wp_unslash( $_POST['company'] ) ) : '';
		if ( '' !== $company ) {
			$this->redirect( 'success', $locale );
		}

		$status = $this->process(
			array(
				'email'   => $_POST['email'] ?? '',
				'subject' => $_POST['subject'] ?? '',
				'message' => $_POST['message'] ?? '',
			),
			self::request_ip(),
			$locale
		);
		$this->redirect( $status, $locale );
	}

	/**
	 * Process one already-CSRF-checked submission.
	 *
	 * @param array<string, mixed> $input Submitted fields.
	 */
	public function process( array $input, string $ip, string $locale ): string {
		if ( ! is_string( $input['email'] ?? null ) || ! is_string( $input['subject'] ?? null ) || ! is_string( $input['message'] ?? null ) ) {
			return 'invalid';
		}

		$raw_email   = wp_unslash( $input['email'] );
		$raw_subject = wp_unslash( $input['subject'] );
		$raw_message = wp_unslash( $input['message'] );
		if ( preg_match( '/[\r\n]/', $raw_email ) || preg_match( '/[\r\n]/', $raw_subject ) ) {
			return 'invalid';
		}

		$email   = sanitize_email( $raw_email );
		$subject = trim( sanitize_text_field( $raw_subject ) );
		$message = trim( sanitize_textarea_field( $raw_message ) );
		if ( ! is_email( $email ) || '' === $subject || '' === $message || self::length( $subject ) > self::SUBJECT_LIMIT || self::length( $message ) > self::MESSAGE_LIMIT ) {
			return 'invalid';
		}

		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		if ( ! self::claim_rate_limit( $ip, $email ) ) {
			return 'rate';
		}

		$content   = Kuka_Island_Core_Site_Appearance::get();
		$recipient = sanitize_email( (string) ( $content['brand']['email'] ?? '' ) );
		if ( ! is_email( $recipient ) ) {
			return 'error';
		}

		$english = 'en' === $locale;
		$mail_subject = $english ? '[Kuka Island Contact] ' . $subject : '[Kuka Island İletişim] ' . $subject;
		$mail_body = $english
			? "A new message was sent through the contact form.\n\nSender: {$email}\nSubject: {$subject}\n\nMessage:\n{$message}"
			: "İletişim formundan yeni bir mesaj gönderildi.\n\nGönderen: {$email}\nKonu: {$subject}\n\nMesaj:\n{$message}";

		self::$reply_to = array( 'email' => $email, 'name' => $email );
		try {
			$sent = wp_mail(
				$recipient,
				$mail_subject,
				$mail_body,
				array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $email )
			);
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$sent = false;
		} finally {
			self::$reply_to = null;
		}

		return $sent ? 'success' : 'error';
	}

	/** Ensure the visitor address wins only for this one contact-form message. */
	public function apply_reply_to( PHPMailer $phpmailer ): void {
		if ( null === self::$reply_to ) {
			return;
		}
		$phpmailer->clearReplyTos();
		$phpmailer->addReplyTo( self::$reply_to['email'], self::$reply_to['name'] );
	}

	/** Replace only the exact retired placeholder on existing installations. */
	public function migrate_contact_page(): void {
		if ( self::MIGRATION_VERSION <= (int) get_option( self::MIGRATION_OPTION, 0 ) ) {
			return;
		}
		$page = get_page_by_path( 'iletisim' );
		if ( ! $page instanceof WP_Post ) {
			return;
		}

		$old_tr = '<p class="kuka-service-disabled"><strong>Form şu anda devre dışıdır.</strong> Mesaj gönderim altyapısı bağlanana kadar e-posta, telefon, WhatsApp veya Instagram kanalını kullanın.</p>';
		$old_en = '<p class="kuka-service-disabled"><strong>The form is currently unavailable.</strong> Until the messaging service is connected, please contact us by email, phone, WhatsApp or Instagram.</p>';
		$tr     = str_replace( $old_tr, '[kuka_contact_form]', (string) $page->post_content );
		$en     = str_replace( $old_en, '[kuka_contact_form]', (string) get_post_meta( $page->ID, '_kuka_content_en', true ) );
		if ( ! str_contains( $tr, '[kuka_contact_form]' ) || ! str_contains( $en, '[kuka_contact_form]' ) ) {
			return;
		}

		if ( $tr !== $page->post_content ) {
			$updated = wp_update_post( array( 'ID' => $page->ID, 'post_content' => $tr ), true );
			if ( is_wp_error( $updated ) ) {
				return;
			}
		}
		if ( ! update_post_meta( $page->ID, '_kuka_content_en', $en ) ) {
			$stored_en = (string) get_post_meta( $page->ID, '_kuka_content_en', true );
			if ( $stored_en !== $en ) {
				return;
			}
		}
		update_option( self::MIGRATION_OPTION, self::MIGRATION_VERSION, false );
	}

	/** @return array<string, mixed> */
	private static function turkish_copy(): array {
		return array(
			'email' => 'E-posta adresiniz', 'subject' => 'Konu', 'message' => 'Mesajınız', 'company' => 'Şirket', 'submit' => 'Mesajı gönder',
			'privacy' => 'Bilgileriniz yalnız talebinizi yanıtlamak için kullanılır. Ayrıntılar için <a href="' . esc_url( home_url( '/gizlilik-politikasi/' ) ) . '">Gizlilik Politikası</a> sayfasını inceleyin.',
			'messages' => array(
				'success' => 'Mesajınız bize ulaştı. En kısa sürede e-posta adresinize dönüş yapacağız.',
				'invalid' => 'E-posta adresi, konu veya mesaj geçerli değil. Lütfen alanları kontrol edin.',
				'rate' => 'Çok kısa sürede birden fazla mesaj gönderdiniz. Lütfen biraz sonra yeniden deneyin.',
				'error' => 'Mesajınız şu anda gönderilemedi. Lütfen daha sonra yeniden deneyin veya diğer iletişim kanallarını kullanın.',
			),
		);
	}

	/** @return array<string, mixed> */
	private static function english_copy(): array {
		return array(
			'email' => 'Your email address', 'subject' => 'Subject', 'message' => 'Your message', 'company' => 'Company', 'submit' => 'Send message',
			'privacy' => 'Your details are used only to answer your request. Read our <a href="' . esc_url( home_url( '/en/gizlilik-politikasi/' ) ) . '">Privacy Policy</a> for details.',
			'messages' => array(
				'success' => 'Your message has reached us. We will reply to your email address as soon as possible.',
				'invalid' => 'The email address, subject or message is invalid. Please check the fields.',
				'rate' => 'You have sent several messages in a short time. Please try again later.',
				'error' => 'Your message could not be sent right now. Please try again later or use another contact channel.',
			),
		);
	}

	private static function claim_rate_limit( string $ip, string $email ): bool {
		$ip_key   = self::rate_key( 'ip', $ip );
		$pair_key = self::rate_key( 'pair', $ip . '|' . strtolower( $email ) );
		$attempts = (int) get_transient( $ip_key );
		if ( $attempts >= self::RATE_LIMIT || get_transient( $pair_key ) ) {
			return false;
		}
		set_transient( $ip_key, $attempts + 1, self::RATE_SECONDS );
		set_transient( $pair_key, 1, self::PAIR_RATE_SECONDS );
		return true;
	}

	private static function rate_key( string $scope, string $value ): string {
		return 'kuka_contact_rate_' . hash_hmac( 'sha256', $scope . '|' . $value, wp_salt( 'auth' ) );
	}

	private static function consume_result(): string {
		$token = isset( $_GET['contact'] ) && is_string( $_GET['contact'] ) ? sanitize_key( wp_unslash( $_GET['contact'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return '';
		}
		$key    = self::RESULT_PREFIX . $token;
		$status = sanitize_key( (string) get_transient( $key ) );
		delete_transient( $key );
		return $status;
	}

	private function redirect( string $status, string $locale ): never {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::RESULT_PREFIX . $token, $status, 5 * MINUTE_IN_SECONDS );
		$path = 'en' === $locale ? '/en/iletisim/' : '/iletisim/';
		wp_safe_redirect( add_query_arg( 'contact', $token, home_url( $path ) ) . '#kuka-contact-form' );
		exit;
	}

	private static function request_locale(): string {
		$requested = isset( $_REQUEST['kuka_lang'] ) && is_string( $_REQUEST['kuka_lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['kuka_lang'] ) ) : '';
		return 'en' === $requested ? 'en' : 'tr';
	}

	private static function request_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private static function is_english(): bool {
		return class_exists( 'Kuka_Island_Core_Language' ) && Kuka_Island_Core_Language::is_english_request();
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
