<?php
/**
 * Turkish shipping vocabulary for WooCommerce's Fulfillments screens.
 *
 * WooCommerce ships "fulfillment" as "yerine getirme", which is a literal
 * translation of a warehouse term and says nothing to a shop owner packing a
 * parcel. Everything the operator actually does here is cargo work: hand the
 * parcel to the courier, record the tracking number, tell the customer. The map
 * below renames the whole feature in those words.
 *
 * Two delivery paths carry these strings and both are covered:
 *
 *   - The drawer is a React bundle whose strings arrive through `wp.i18n`, so a
 *     gettext filter never sees them. Its overrides are pushed with
 *     setLocaleData() before the bundle runs.
 *   - The order list column, the badges and the filters are rendered in PHP and
 *     are covered by an exact-match gettext filter.
 *
 * Both are scoped to the WooCommerce orders screens and to a Turkish admin
 * locale, so an English administrator and every other screen are untouched. No
 * vendor file is modified.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Fulfillments_Language {
	/** Script handle WooCommerce registers for the fulfillments drawer. */
	private const DRAWER_HANDLE = 'wc-admin-fulfillments';

	/** Screens that may show fulfillment wording. */
	private const SCREENS = array(
		'woocommerce_page_wc-orders',
		'shop_order',
		'edit-shop_order',
	);

	public function register(): void {
		add_action( 'current_screen', array( $this, 'boot' ) );
	}

	/** Attach only where the wording appears. */
	public function boot(): void {
		if ( ! self::is_target_screen() ) {
			return;
		}
		// The order list filter widths are language independent, so the
		// stylesheet loads for every admin locale.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_order_screen_assets' ), 99 );

		if ( ! self::is_turkish_admin() ) {
			return;
		}
		add_filter( 'gettext', array( $this, 'translate' ), 20, 3 );
		add_filter( 'gettext_with_context', array( $this, 'translate_with_context' ), 20, 4 );
		add_action( 'admin_enqueue_scripts', array( $this, 'push_drawer_strings' ), 99 );
	}

	/**
	 * Scoped stylesheet for the orders screens.
	 *
	 * Filter-strip layout plus the narrowly scoped wheel/trackpad fix that makes
	 * WooCommerce's outer fulfillment panel the only scroll container. Do not
	 * add a document scroll-lock script here; see KARGO_SCROLL_KORUMA_NOTU.md.
	 */
	public function enqueue_order_screen_assets(): void {
		wp_enqueue_style(
			'kuka-island-admin-orders',
			plugins_url( 'assets/admin-orders.css', KUKA_ISLAND_CORE_FILE ),
			array(),
			'0.6.0'
		);
	}

	private static function is_target_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();

		return $screen instanceof WP_Screen && in_array( $screen->id, self::SCREENS, true );
	}

	/** The English admin keeps WooCommerce's own wording untouched. */
	private static function is_turkish_admin(): bool {
		return str_starts_with( (string) get_user_locale(), 'tr' );
	}

	/**
	 * Exact-match replacement for the PHP rendered strings.
	 *
	 * Matching is on the untranslated English original, never on the Turkish
	 * output, so a WooCommerce update that changes its own translation cannot
	 * make this silently miss or double-apply.
	 *
	 * @param string $translated Current translation.
	 * @param string $original   Untranslated string.
	 * @param string $domain     Text domain.
	 */
	public function translate( $translated, $original, $domain ) {
		if ( 'woocommerce' !== $domain ) {
			return $translated;
		}
		$map = self::php_strings();

		return $map[ $original ] ?? $translated;
	}

	/**
	 * @param string $translated Current translation.
	 * @param string $original   Untranslated string.
	 * @param string $context    Gettext context.
	 * @param string $domain     Text domain.
	 */
	public function translate_with_context( $translated, $original, $context, $domain ) {
		unset( $context );

		return $this->translate( $translated, $original, $domain );
	}

	/**
	 * Hand the drawer bundle its Turkish strings before it renders.
	 *
	 * setLocaleData() merges into the `woocommerce` domain the bundle already
	 * uses, so only the listed messages change.
	 */
	public function push_drawer_strings(): void {
		if ( ! wp_script_is( self::DRAWER_HANDLE, 'registered' ) && ! wp_script_is( self::DRAWER_HANDLE, 'enqueued' ) ) {
			return;
		}
		$messages = array();
		foreach ( self::drawer_strings() as $original => $turkish ) {
			$messages[ $original ] = array( $turkish );
		}
		wp_add_inline_script(
			self::DRAWER_HANDLE,
			'( function ( i18n ) { if ( i18n && i18n.setLocaleData ) { i18n.setLocaleData( '
				. wp_json_encode( $messages )
				. ', "woocommerce" ); } } )( window.wp && window.wp.i18n );',
			'before'
		);
	}

	/**
	 * React drawer wording.
	 *
	 * @return array<string, string>
	 */
	public static function drawer_strings(): array {
		return array(
			'Close fulfillment drawer'                     => 'Kargo işlemlerini kapat',
			'Fulfillment status:'                          => 'Gönderim durumu:',
			'Fulfillment details'                          => 'Kargo bilgileri',
			'Fulfillment #%s'                              => '%s numaralı gönderim',
			'Editing fulfillment #%s'                      => '%s numaralı gönderim düzenleniyor',
			'Edit fulfillment'                             => 'Gönderimi düzenle',
			'Remove fulfillment'                           => 'Gönderimi sil',
			'Are you sure you want to remove this fulfillment?' => 'Bu gönderimi silmek istediğinizden emin misiniz?',
			'Applies changes to the existing fulfillment'  => 'Mevcut gönderimdeki değişiklikleri uygular',
			'Deletes this fulfillment permanently'         => 'Bu gönderimi kalıcı olarak siler',
			'Opens the fulfillment editor to modify fulfillment details' => 'Kargo bilgilerini değiştirmek için gönderim düzenleyicisini açar',
			'Select items for fulfillment'                 => 'Kargoya verilecek ürünleri seçin',
			'Select items to be fulfilled.'                => 'Kargoya verilecek ürünleri seçin.',
			'Fulfill items'                                => 'Kargoya verildi olarak işaretle',
			'Marks the selected items as fulfilled and updates their status' => 'Seçilen ürünleri kargoya verildi olarak işaretler ve durumlarını günceller',
			'Fulfilling…'                                  => 'Kargoya veriliyor…',
			'Saves the fulfillment without marking items as fulfilled' => 'Kargo taslağını, ürünleri kargoya verildi saymadan kaydeder',
			'Fulfillment notification'                     => 'Kargo bildirimini müşteriye gönder',
			'Automatically send an email to the customer when the selected items are fulfilled.' => 'Seçilen ürünler kargoya verildiğinde müşteriye otomatik kargo bildirimi gönder.',
			'Automatically send an email to the customer when the fulfillment is updated.' => 'Gönderim güncellendiğinde müşteriye otomatik kargo bildirimi gönder.',
			'Automatically send an email to the customer notifying that the fulfillment is cancelled.' => 'Gönderim iptal edildiğinde müşteriye otomatik bilgilendirme gönder.',
			'Shipment Information'                         => 'Kargo bilgileri',
			'No shipment information'                      => 'Kargo bilgisi girilmemiş',
			'Provide the shipment information for this fulfillment.' => 'Bu gönderim için kargo bilgilerini girin.',
			'Provide the shipment tracking number to find the shipment provider and tracking URL.' => 'Kargo firmasını ve takip bağlantısını bulmak için kargo takip numarasını girin.',
			'Failed to fetch shipment information.'        => 'Kargo bilgileri alınamadı.',
			'No information found for this tracking number. Check the number or enter the details manually.' => 'Bu kargo takip numarası için bilgi bulunamadı. Numarayı kontrol edin veya bilgileri elle girin.',
			'Tracking number'                              => 'Kargo takip numarası',
			'Tracking Number'                              => 'Kargo takip numarası',
			'Enter tracking number'                        => 'Kargo takip numarasını girin',
			'Edit tracking number'                         => 'Kargo takip numarasını düzenle',
			'Tracking URL'                                 => 'Kargo takip bağlantısı',
			'Enter tracking URL'                           => 'Kargo takip bağlantısını girin',
			'Searching for tracking information…'          => 'Kargo takip bilgisi aranıyor…',
			'Tracking information found successfully.'     => 'Kargo takip bilgisi bulundu.',
		);
	}

	/**
	 * PHP rendered wording: order list column, badges, filters, order notes.
	 *
	 * @return array<string, string>
	 */
	public static function php_strings(): array {
		return array(
			'View Fulfillments'            => 'Kargo işlemlerini aç',
			'No fulfillments'              => 'Henüz kargo işlemi yok',
			'Fulfilled'                    => 'Kargoya verildi',
			'Partially fulfilled'          => 'Kısmen kargoya verildi',
			'Unfulfilled'                  => 'Henüz kargoya verilmedi',
			'Fulfillment Status'           => 'Gönderim durumu',
			'Mark as fulfilled'            => 'Kargoya verildi olarak işaretle',
			'Shipment Provider'            => 'Kargo firması',
			'Shipment Tracking'            => 'Kargo takip numarası',
			'Shipping provider'            => 'Kargo firması',
			'Shipping providers'           => 'Kargo firmaları',
			'Add new shipping provider'    => 'Yeni kargo firması ekle',
			'Edit shipping provider'       => 'Kargo firmasını düzenle',
			'Multiple providers'           => 'Birden fazla kargo firması',
			'Multiple trackings'           => 'Birden fazla takip numarası',
			'No tracking number available' => 'Kargo takip numarası girilmemiş',
			'Filter by fulfillment'        => 'Kargo durumuna göre filtrele',
			'Filter by shipping provider'  => 'Kargo firmasına göre filtrele',
			'Provider: %s'                 => 'Kargo firması: %s',
			'Tracking: %s.'                => 'Kargo takip numarası: %s.',
			'Item #%d'                     => '%d numaralı ürün',
			'<b>Shipment %1$s</b> was shipped on <b>%2$s</b>' => '<b>%1$s</b> numaralı gönderim <b>%2$s</b> tarihinde kargoya verildi',
			'It has <mark class="fulfillment-status">no fulfillments</mark> yet.' => 'Bu siparişte henüz <mark class="fulfillment-status">kargo işlemi yok</mark>.',
			'It has been <mark class="fulfillment-status">Fulfilled</mark>.' => 'Sipariş <mark class="fulfillment-status">kargoya verildi</mark>.',
			'It has been <mark class="fulfillment-status">Partially fulfilled</mark>.' => 'Sipariş <mark class="fulfillment-status">kısmen kargoya verildi</mark>.',
			'It is currently <mark class="fulfillment-status">Unfulfilled</mark>.' => 'Sipariş <mark class="fulfillment-status">henüz kargoya verilmedi</mark>.',
		);
	}

	/** Every mapping, for the acceptance snapshot. @return array<string, string> */
	public static function all_strings(): array {
		return self::drawer_strings() + self::php_strings();
	}
}
