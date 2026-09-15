<?php
/**
 * The shop owner's settings screen.
 *
 * ONE PAGE, UNDER WOOCOMMERCE, because that is where a shop owner looks for
 * anything to do with orders. It is a plain form: no REST route, no AJAX, no
 * JavaScript. Every write is a POST to admin-post.php behind a capability check
 * and an action-specific nonce, and the three actions -- save, forget one
 * secret, test the connection -- carry three DIFFERENT nonces, so a form issued
 * for one can never be replayed as another.
 *
 * A SECRET IS NEVER PRINTED BACK. Not in full, not masked, not as a length, not
 * as a `value` attribute on a password input. The panel shows whether each
 * credential is present and WHICH LAYER answered for it -- a constant, the
 * environment, or the encrypted vault -- and nothing else. A masked secret is
 * still a secret with its search space reduced, and a value attribute is a
 * secret in the page source, in the browser's form cache and in any extension
 * that reads the DOM.
 *
 * AN EMPTY FIELD MEANS "LEAVE IT ALONE". The alternative -- blank erases --
 * would delete a working credential every time somebody changed an unrelated
 * switch, because the panel cannot pre-fill the field it is not allowed to
 * show. Deleting one is a separate button with its own nonce.
 *
 * THE CONNECTION TEST READS ONLY. Identity and the CBS city list, both
 * documented as queries. createOrder, createbarcode, update and cancel are not
 * reachable from this screen at all -- not disabled, not guarded: absent.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Settings_Page {

	public const MENU_SLUG   = 'kuka-island-shipping';
	public const CAPABILITY  = 'manage_woocommerce';

	public const NONCE_SAVE   = 'kuka_shipping_settings_save';
	public const NONCE_FORGET = 'kuka_shipping_settings_forget';
	public const NONCE_TEST   = 'kuka_shipping_settings_test';

	/** Where the last connection test's outcome is kept. Never a value. */
	public const OPTION_LAST_TEST = 'kuka_island_shipping_last_test';

	private Kuka_Island_Shipping_Manager $manager;

	public function __construct( ?Kuka_Island_Shipping_Manager $manager = null ) {
		$this->manager = $manager ?? new Kuka_Island_Shipping_Manager();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_post_kuka_shipping_settings_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_kuka_shipping_settings_forget', array( $this, 'handle_forget' ) );
		add_action( 'admin_post_kuka_shipping_settings_test', array( $this, 'handle_test' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Kargo Entegrasyonu', 'kuka-island-shipping-automation' ),
			__( 'Kargo Entegrasyonu', 'kuka-island-shipping-automation' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Capability and nonce, in that order, for one action.
	 *
	 * wp_die() on failure rather than a redirect: a request that failed one of
	 * these is not a user mistake to be explained, it is a request that should
	 * not have been made.
	 */
	private function authorise( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu ayarları değiştirme yetkiniz yok.', 'kuka-island-shipping-automation' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce_action, '_kuka_shipping_settings_nonce' );
	}

	/** Save the switches, and any credential that was actually typed. */
	public function handle_save(): void {
		$this->authorise( self::NONCE_SAVE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorise() checked it.
		Kuka_Island_Shipping_Settings::save_switches(
			array(
				'module_enabled'      => isset( $_POST['kuka_shipping_module_enabled'] ),
				'adapter_enabled'     => isset( $_POST['kuka_shipping_adapter_enabled'] ),
				'auto_create_enabled' => isset( $_POST['kuka_shipping_auto_create_enabled'] ),
				'auto_poll_enabled'   => isset( $_POST['kuka_shipping_auto_poll_enabled'] ),
				'environment'         => sanitize_text_field( wp_unslash( (string) ( $_POST['kuka_shipping_environment'] ?? '' ) ) ),
				'default_carrier'     => sanitize_text_field( wp_unslash( (string) ( $_POST['kuka_shipping_default_carrier'] ?? '' ) ) ),
			)
		);

		$written = array();

		foreach ( array_keys( Kuka_Island_Shipping_Settings::secret_fields() ) as $field ) {
			$key = 'kuka_shipping_' . $field;

			/*
			 * Raw, deliberately. A credential is an opaque byte string chosen by
			 * the carrier; sanitize_text_field() would silently strip characters
			 * out of a working secret and the failure would look like a wrong
			 * password. It is never echoed, so there is nothing to escape for.
			 */
			$value = isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';

			if ( '' === trim( $value ) ) {
				// Blank means "leave it alone", never "erase it".
				continue;
			}

			if ( Kuka_Island_Shipping_Secret_Vault::put( $field, $value ) ) {
				$written[] = $field;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->go_back( array() !== $written ? 'saved_with_credentials' : 'saved' );
	}

	/** The exact value the confirmation checkbox must carry. */
	public const FORGET_CONFIRMATION = '1';

	/**
	 * Delete one stored credential. Deliberate, confirmed, and confirmed HERE.
	 *
	 * `required` on the checkbox is a courtesy to somebody using a browser. It
	 * is NOT a security boundary: any POST can omit the field, and a handler
	 * that trusted the attribute would delete a working credential for a
	 * request that never showed a form. The confirmation is therefore checked
	 * on the server, strictly, against one exact value -- not "is it set", not
	 * "is it truthy", because 'on', '0' and '' are all things a browser or a
	 * script can send and none of them is the answer this asked for.
	 */
	public function handle_forget(): void {
		$this->authorise( self::NONCE_FORGET );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorise() checked it.
		$confirmation = isset( $_POST['kuka_shipping_forget_confirm'] )
			? (string) wp_unslash( $_POST['kuka_shipping_forget_confirm'] )
			: '';

		if ( self::FORGET_CONFIRMATION !== $confirmation ) {
			// Nothing is deleted, and the operator is told why.
			$this->go_back( 'forget_not_confirmed' );

			return;
		}

		$field = sanitize_key( wp_unslash( (string) ( $_POST['kuka_shipping_forget'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $field, Kuka_Island_Shipping_Secret_Vault::fields(), true ) ) {
			$this->go_back( 'forget_unknown_field' );

			return;
		}

		Kuka_Island_Shipping_Secret_Vault::forget( $field );

		$this->go_back( 'forgotten' );
	}

	/** Run the read-only connection test and record only its verdict. */
	public function handle_test(): void {
		$this->authorise( self::NONCE_TEST );

		$carrier = $this->manager->get_registry()->get( $this->manager->default_carrier_key() );
		$result  = self::run_connection_test( $carrier instanceof Kuka_Island_Shipping_Carrier_Interface ? $carrier : null );

		update_option(
			self::OPTION_LAST_TEST,
			array(
				'at'   => time(),
				'ok'   => (bool) $result['ok'],
				'code' => (string) $result['code'],
			),
			false
		);

		$this->go_back( $result['ok'] ? 'test_ok' : 'test_failed' );
	}

	/**
	 * Identity, then one CBS list. Nothing else, ever.
	 *
	 * Public and static so the exact call set can be counted without an admin
	 * request. The returned array carries a safe code and NOTHING from the
	 * carrier's answer: no token, no header, no body, no address list.
	 *
	 * @return array{ok: bool, code: string, checks: array<string, string>}
	 */
	public static function run_connection_test( ?Kuka_Island_Shipping_Carrier_Interface $carrier ): array {
		$checks = array(
			'configuration' => 'not_run',
			'identity'      => 'not_run',
			'city_list'     => 'not_run',
		);

		if ( ! $carrier instanceof Kuka_Island_Shipping_Carrier_Interface ) {
			return array(
				'ok'     => false,
				'code'   => 'carrier_not_registered',
				'checks' => $checks,
			);
		}

		/*
		 * THE MAIN SWITCH IS ASKED FIRST, HERE.
		 *
		 * The bundled adapter's client already refuses on a closed gate in its
		 * own preflight, and that is where the guarantee has to live for every
		 * caller. But this screen must not depend on an adapter remembering to:
		 * a second courier added through the public filter would otherwise be
		 * contacted by a "diagnostic" the operator had switched off. So the
		 * panel asks the gate itself, before it touches a carrier object at all.
		 */
		if ( Kuka_Island_Shipping_Runtime_Gate::is_disabled() ) {
			$checks['configuration'] = 'failed';

			return array(
				'ok'     => false,
				'code'   => Kuka_Island_Shipping_Runtime_Gate::CODE,
				'checks' => $checks,
			);
		}

		$readiness = Kuka_Island_Shipping_Settings::readiness();

		if ( ! $readiness['ready'] ) {
			$checks['configuration'] = 'failed';

			return array(
				'ok'     => false,
				'code'   => (string) $readiness['code'],
				'checks' => $checks,
			);
		}

		$checks['configuration'] = 'ok';

		$gaps = $carrier->get_readiness();

		if ( ! empty( $gaps['live_blocked'] ) ) {
			$checks['configuration'] = 'failed';

			return array(
				'ok'     => false,
				'code'   => 'live_environment_blocked',
				'checks' => $checks,
			);
		}

		if ( array() !== (array) ( $gaps['gaps'] ?? array() ) ) {
			$checks['configuration'] = 'failed';

			return array(
				'ok'     => false,
				'code'   => 'credentials_missing',
				'checks' => $checks,
			);
		}

		/*
		 * TWO READS, BOTH ALREADY IN THE CARRIER CONTRACT.
		 *
		 * ping() is the Identity call and resolve_location() is the CBS city and
		 * district lookup. Neither is a write, and no new interface method was
		 * invented for this screen: a method that existed only to be called from
		 * a settings page would be a method nobody audits, and the next person
		 * to need "just one more check" would add a write to it.
		 */
		$identity = $carrier->ping();

		$checks['identity'] = $identity->is_success() ? 'ok' : 'failed';

		if ( ! $identity->is_success() ) {
			return array(
				'ok'     => false,
				'code'   => (string) $identity->get_safe_error_code(),
				'checks' => $checks,
			);
		}

		$location = $carrier->resolve_location( 'İstanbul', 'Kadıköy' );

		$checks['city_list'] = $location->is_success() ? 'ok' : 'failed';

		return array(
			'ok'     => $location->is_success(),
			'code'   => $location->is_success() ? '' : (string) $location->get_safe_error_code(),
			'checks' => $checks,
		);
	}

	private function go_back( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => self::MENU_SLUG,
					'kuka_shipping_notice'  => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * One sentence per notice code, so the screen never echoes a raw parameter.
	 */
	private static function notice_text( string $code ): string {
		$map = array(
			'saved'                  => __( 'Ayarlar kaydedildi.', 'kuka-island-shipping-automation' ),
			'saved_with_credentials' => __( 'Ayarlar ve kimlik bilgileri kaydedildi. Kimlik bilgileri şifrelenerek saklandı ve bu ekranda bir daha gösterilmez.', 'kuka-island-shipping-automation' ),
			'forgotten'              => __( 'Seçilen kimlik bilgisi silindi.', 'kuka-island-shipping-automation' ),
			'forget_not_confirmed'   => __( 'Hiçbir şey silinmedi: silme onayı kutusu işaretlenmemişti.', 'kuka-island-shipping-automation' ),
			'forget_unknown_field'   => __( 'Hiçbir şey silinmedi: seçilen alan tanınmadı.', 'kuka-island-shipping-automation' ),
			'test_ok'                => __( 'Bağlantı testi başarılı. Yalnız salt-okunur sorgular yapıldı; hiçbir gönderi oluşturulmadı.', 'kuka-island-shipping-automation' ),
			'test_failed'            => __( 'Bağlantı testi başarısız. Ayrıntı için aşağıdaki son test satırına bakın. Hiçbir gönderi oluşturulmadı.', 'kuka-island-shipping-automation' ),
		);

		return (string) ( $map[ $code ] ?? '' );
	}

	/** The screen. */
	public function render(): void {
		$summary   = Kuka_Island_Shipping_Settings::safe_summary();
		$readiness = $summary['readiness'];
		$switches  = $summary['switches'];
		$last_test = get_option( self::OPTION_LAST_TEST, array() );
		$last_test = is_array( $last_test ) ? $last_test : array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice text.
		$notice = self::notice_text( sanitize_key( wp_unslash( (string) ( $_GET['kuka_shipping_notice'] ?? '' ) ) ) );

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Kargo Entegrasyonu', 'kuka-island-shipping-automation' ) );

		if ( '' !== $notice ) {
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $notice ) );
		}

		printf(
			'<div class="notice notice-%s"><p><strong>%s</strong> %s</p></div>',
			$readiness['ready'] ? 'success' : 'warning',
			esc_html( $readiness['ready'] ? __( 'Yapılandırma hazır.', 'kuka-island-shipping-automation' ) : __( 'Yapılandırma eksik.', 'kuka-island-shipping-automation' ) ),
			esc_html( (string) $readiness['message'] )
		);

		printf(
			'<p>%s</p>',
			esc_html__( 'Manuel kargo yolu her zaman açıktır: bu sayfadaki her anahtar kapalı olsa bile WooCommerce kargo çekmecesinden takip numarasını elle girebilirsiniz.', 'kuka-island-shipping-automation' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="kuka_shipping_settings_save" />';
		wp_nonce_field( self::NONCE_SAVE, '_kuka_shipping_settings_nonce' );

		echo '<h2>' . esc_html__( 'Anahtarlar', 'kuka-island-shipping-automation' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->switch_row( 'module_enabled', __( 'Modül çalışma anahtarı', 'kuka-island-shipping-automation' ), __( 'Kapalıyken hiçbir kargo işlemi yapılmaz; uçuştaki bir iş bile durur.', 'kuka-island-shipping-automation' ), $switches );
		$this->switch_row( 'adapter_enabled', __( 'DHL/MNG adaptörü', 'kuka-island-shipping-automation' ), __( 'Kapalıyken taşıyıcı istemcisi hiç kurulmaz.', 'kuka-island-shipping-automation' ), $switches );
		$this->switch_row( 'auto_create_enabled', __( 'Otomatik gönderi oluşturma', 'kuka-island-shipping-automation' ), __( 'Açıkken ödemesi tamamlanmış ve kargolanabilir siparişler için tek bir arka plan işi planlanır. Varsayılan kapalıdır.', 'kuka-island-shipping-automation' ), $switches );
		$this->switch_row( 'auto_poll_enabled', __( 'Otomatik durum sorgulama', 'kuka-island-shipping-automation' ), __( 'Açıkken oluşturulmuş gönderilerin durumu sınırlı bir zincirle sorgulanır. Gönderi oluşturmaz.', 'kuka-island-shipping-automation' ), $switches );

		$environment = (string) $switches['environment']['value'];

		echo '<tr><th scope="row">' . esc_html__( 'Çalışma ortamı', 'kuka-island-shipping-automation' ) . '</th><td>';
		echo '<select name="kuka_shipping_environment">';
		printf(
			'<option value="test" %s>%s</option>',
			selected( $environment, Kuka_Island_Shipping_DHL_Config::ENV_TEST, false ),
			esc_html__( 'Sandbox (test)', 'kuka-island-shipping-automation' )
		);
		printf(
			'<option value="live" %s>%s</option>',
			selected( $environment, Kuka_Island_Shipping_DHL_Config::ENV_LIVE, false ),
			esc_html__( 'Canlı — kullanılamıyor', 'kuka-island-shipping-automation' )
		);
		echo '</select>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Canlı ortam kapalıdır. Taşıyıcının resmî dokümanlarında doğrulanmış bir canlı adres yoktur; canlı seçilse bile hiçbir çağrı yapılmaz ve tüm işlemler reddedilir. Bu bir görüntü kısıtı değildir.', 'kuka-island-shipping-automation' )
		);
		printf( '<p class="description">%s</p>', esc_html( $this->source_sentence( (string) $switches['environment']['source'] ) ) );
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Varsayılan taşıyıcı', 'kuka-island-shipping-automation' ) . '</th><td>';
		printf(
			'<input type="text" class="regular-text" name="kuka_shipping_default_carrier" value="%s" />',
			esc_attr( (string) $switches['default_carrier']['value'] )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Yalnız yeni siparişler için kullanılır. Bir siparişte taşıyıcı işlemi başladıysa o sipariş kendi taşıyıcısına bağlı kalır.', 'kuka-island-shipping-automation' )
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'API bilgileri', 'kuka-island-shipping-automation' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html__( 'Girilen değerler site anahtarlarınızdan türetilen bir anahtarla şifrelenerek saklanır ve bu ekranda bir daha gösterilmez. Alanı boş bırakırsanız mevcut değer korunur. wp-config.php sabiti veya ortam değişkeni varsa o öncelikli olur ve panel değeri kullanılmaz.', 'kuka-island-shipping-automation' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		$labels = array(
			'client_id'       => __( 'API Client ID', 'kuka-island-shipping-automation' ),
			'client_secret'   => __( 'API Client Secret', 'kuka-island-shipping-automation' ),
			'customer_number' => __( 'Müşteri numarası', 'kuka-island-shipping-automation' ),
			'password'        => __( 'API parolası', 'kuka-island-shipping-automation' ),
		);

		foreach ( $labels as $field => $label ) {
			$state = $summary['secrets'][ $field ];

			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			printf(
				'<input type="password" class="regular-text" name="%s" value="" autocomplete="new-password" />',
				esc_attr( 'kuka_shipping_' . $field )
			);
			printf(
				'<p class="description">%s %s</p>',
				esc_html(
					$state['present']
						? __( 'Kayıtlı.', 'kuka-island-shipping-automation' )
						: __( 'Girilmedi.', 'kuka-island-shipping-automation' )
				),
				esc_html( $this->source_sentence( (string) $state['source'] ) )
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		submit_button( __( 'Ayarları kaydet', 'kuka-island-shipping-automation' ) );
		echo '</form>';

		// Deleting a stored credential is its own form, with its own nonce.
		if ( array() !== (array) $summary['vault']['stored'] ) {
			echo '<h2>' . esc_html__( 'Kayıtlı bilgiyi sil', 'kuka-island-shipping-automation' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="kuka_shipping_settings_forget" />';
			wp_nonce_field( self::NONCE_FORGET, '_kuka_shipping_settings_nonce' );
			echo '<select name="kuka_shipping_forget">';

			foreach ( (array) $summary['vault']['stored'] as $field ) {
				printf( '<option value="%s">%s</option>', esc_attr( (string) $field ), esc_html( (string) ( $labels[ $field ] ?? $field ) ) );
			}

			echo '</select> ';
			printf(
				'<label><input type="checkbox" name="kuka_shipping_forget_confirm" value="%s" required /> %s</label>',
				esc_attr( self::FORGET_CONFIRMATION ),
				esc_html__( 'Bu bilgiyi kalıcı olarak silmek istediğimi onaylıyorum.', 'kuka-island-shipping-automation' )
			);
			submit_button( __( 'Seçilen bilgiyi sil', 'kuka-island-shipping-automation' ), 'delete', 'submit', false );
			echo '</form>';
		}

		echo '<h2>' . esc_html__( 'Bağlantıyı test et', 'kuka-island-shipping-automation' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html__( 'Test yalnız salt-okunur sorgular yapar: kimlik doğrulama ve il listesi. Gönderi oluşturmaz, güncellemez, iptal etmez.', 'kuka-island-shipping-automation' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="kuka_shipping_settings_test" />';
		wp_nonce_field( self::NONCE_TEST, '_kuka_shipping_settings_nonce' );
		submit_button( __( 'Bağlantıyı test et', 'kuka-island-shipping-automation' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( isset( $last_test['at'] ) ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: date and time, 2: outcome. */
						__( 'Son test: %1$s — %2$s', 'kuka-island-shipping-automation' ),
						wp_date( 'd.m.Y H:i', (int) $last_test['at'] ),
						! empty( $last_test['ok'] )
							? __( 'başarılı', 'kuka-island-shipping-automation' )
							: sprintf(
								/* translators: %s: allow-listed safe code. */
								__( 'başarısız (%s)', 'kuka-island-shipping-automation' ),
								(string) ( $last_test['code'] ?? '' )
							)
					)
				)
			);
		}

		echo '</div>';
	}

	/**
	 * One checkbox row, with the layer that actually answered for it.
	 *
	 * @param array<string, mixed> $switches Resolved switches.
	 */
	private function switch_row( string $name, string $label, string $description, array $switches ): void {
		$state    = $switches[ $name ];
		$locked   = in_array( (string) $state['source'], array( Kuka_Island_Shipping_Settings::SOURCE_CONSTANT, Kuka_Island_Shipping_Settings::SOURCE_ENV ), true );

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s %s /> %s</label>',
			esc_attr( 'kuka_shipping_' . $name ),
			checked( (bool) $state['value'], true, false ),
			$locked ? 'disabled="disabled"' : '',
			esc_html__( 'Açık', 'kuka-island-shipping-automation' )
		);
		printf( '<p class="description">%s</p>', esc_html( $description ) );
		printf( '<p class="description">%s</p>', esc_html( $this->source_sentence( (string) $state['source'] ) ) );
		echo '</td></tr>';
	}

	/** Which layer answered, in a sentence an operator can act on. */
	private function source_sentence( string $source ): string {
		switch ( $source ) {
			case Kuka_Island_Shipping_Settings::SOURCE_CONSTANT:
				return __( 'Kaynak: wp-config.php sabiti. Panel değeri kullanılmaz.', 'kuka-island-shipping-automation' );
			case Kuka_Island_Shipping_Settings::SOURCE_ENV:
				return __( 'Kaynak: ortam değişkeni. Panel değeri kullanılmaz.', 'kuka-island-shipping-automation' );
			case Kuka_Island_Shipping_Settings::SOURCE_VAULT:
				return __( 'Kaynak: şifreli panel kasası.', 'kuka-island-shipping-automation' );
			case Kuka_Island_Shipping_Settings::SOURCE_PANEL:
				return __( 'Kaynak: bu panel.', 'kuka-island-shipping-automation' );
			case Kuka_Island_Shipping_Settings::SOURCE_DEFAULT:
				return __( 'Kaynak: varsayılan değer.', 'kuka-island-shipping-automation' );
			default:
				return __( 'Kaynak: yok.', 'kuka-island-shipping-automation' );
		}
	}
}
