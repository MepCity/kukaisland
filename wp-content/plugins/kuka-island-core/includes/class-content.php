<?php
/**
 * Central, operator-editable content values and storefront shortcodes.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Content {

	/**
	 * Build a wa.me link from a free-form phone number.
	 *
	 * Normalization strips spaces, parentheses and hyphens; a leading 0 is
	 * replaced with the TR country code 90, and an explicit +90 / 90 prefix is
	 * kept. An empty input yields an empty link so callers can hide the entry.
	 */
	public static function whatsapp_url( string $phone ): string {
		$digits = preg_replace( '/[^0-9]/', '', $phone );
		if ( '' === $digits ) {
			return '';
		}
		if ( str_starts_with( $digits, '90' ) ) {
			// Already carries the country code.
		} elseif ( str_starts_with( $digits, '0' ) ) {
			$digits = '9' . $digits;
		} else {
			$digits = '90' . $digits;
		}
		return 'https://wa.me/' . $digits;
	}

	public function register(): void {
		add_shortcode( 'kuka_company_details', array( $this, 'company_details' ) );
		add_shortcode( 'kuka_hygiene_policy', array( $this, 'hygiene_policy' ) );
		add_shortcode( 'kuka_hygiene_try_on', array( $this, 'hygiene_try_on' ) );
		add_shortcode( 'kuka_preinfo_products', array( $this, 'preinfo_products' ) );
		add_shortcode( 'kuka_payment_methods', array( $this, 'payment_methods' ) );
		add_shortcode( 'kuka_value', array( $this, 'value' ) );
		add_shortcode( 'kuka_contact_details', array( $this, 'contact_details' ) );
		add_shortcode( 'kuka_size_guide', array( $this, 'size_guide' ) );
		add_shortcode( 'kuka_manifesto_line_2', array( $this, 'manifesto_line_2' ) );
	}

	/** The About-page opening follows the same panel value as the home manifesto. */
	public function manifesto_line_2(): string {
		$content = Kuka_Island_Core_Site_Appearance::get();
		if ( class_exists( 'Kuka_Island_Core_Language' ) ) { $content = Kuka_Island_Core_Language::localized_content( $content ); }
		$copy = trim( (string) ( $content['home']['manifesto_line_2'] ?? '' ) );
		return '' === $copy ? '' : '<span data-kuka-manifesto-opening>' . esc_html( $copy ) . '</span>';
	}

	/**
	 * Seller block used by every contract page. The labels and their order match
	 * the customer's signed PDFs so the published page and the contract read the
	 * same; the values come from the panel so one edit reaches all of them.
	 */
	public function company_details(): string {
		$legal = Kuka_Island_Core_Site_Appearance::get()['legal'];
		$brand = Kuka_Island_Core_Site_Appearance::get()['brand'];
		$rows  = array(
			array( __( 'Satıcı', 'kuka-island-core' ), $legal['company_title'] ),
			array( __( 'Marka', 'kuka-island-core' ), $legal['brand_name'] ),
			array( __( 'Vergi Dairesi', 'kuka-island-core' ), $legal['tax_office'] ),
			array( __( 'Vergi Kimlik No', 'kuka-island-core' ), $legal['tax_number'] ),
			array( __( 'Adres', 'kuka-island-core' ), $legal['address_full'] ),
			array( __( 'Telefon', 'kuka-island-core' ), $legal['telephone'] ),
			array( __( 'E-posta', 'kuka-island-core' ), $brand['email'] ),
			array( __( 'MERSİS No', 'kuka-island-core' ), $legal['mersis_number'] ),
			array( __( 'KEP adresi', 'kuka-island-core' ), $legal['kep_address'] ?? '' ),
			array( __( 'Kayıtlı olunan meslek odası', 'kuka-island-core' ), $legal['professional_chamber'] ?? '' ),
			array( __( 'Uygulanan mesleki davranış kuralları', 'kuka-island-core' ), $legal['professional_rules_url'] ?? '', true ),
			array( __( 'ETBİS numarası', 'kuka-island-core' ), $legal['etbis_number'] ),
		);
		$html  = '<dl class="kuka-company-details">';
		foreach ( $rows as $row ) {
			[ $label, $detail ] = $row;
			$value = (string) $detail;
			if ( '' === trim( $value ) || str_contains( $value, '[' ) ) { continue; }
			$display = ! empty( $row[2] ) ? '<a href="' . esc_url( $value ) . '">' . esc_html( $value ) . '</a>' : esc_html( $value );
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $display . '</dd></div>';
		}
		return $html . '</dl>';
	}

	/**
	 * The customer's own hygiene wording, followed by the defective-goods
	 * sentence their return contract (§6, §10) requires so the notice cannot be
	 * read as overriding statutory rights.
	 */
	public function hygiene_policy(): string {
		$commercial = Kuka_Island_Core_Site_Appearance::get()['commercial'];
		$sentences  = array_filter(
			array(
				trim( (string) ( $commercial['hygiene_copy'] ?? '' ) ),
				trim( (string) ( $commercial['hygiene_defect_copy'] ?? '' ) ),
			)
		);
		if ( ! $sentences ) { return ''; }
		return '<span data-kuka-hygiene-policy>' . esc_html( implode( ' ', $sentences ) ) . '</span>';
	}

	/**
	 * Enabled payment methods, read from WooCommerce rather than restated in the
	 * contract text. The customer's PDF leaves this as a bracketed placeholder.
	 */
	public function payment_methods(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) { return ''; }
		$titles = array();
		foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
			$titles[] = $gateway->get_title();
		}
		if ( ! $titles ) { return esc_html__( 'internet sitesinde sunulan ödeme yöntemleri', 'kuka-island-core' ); }
		return '<span data-kuka-payment-methods>' . esc_html( implode( ' / ', $titles ) ) . '</span>';
	}

	public function hygiene_try_on(): string {
		$copy = trim( (string) ( Kuka_Island_Core_Site_Appearance::get()['commercial']['hygiene_try_on_copy'] ?? '' ) );
		return '' === $copy ? '' : '<span data-kuka-hygiene-try-on>' . esc_html( $copy ) . '</span>';
	}

	/**
	 * Pre-information form §2 — the order the consumer is about to place.
	 *
	 * The Mesafeli Sözleşmeler Yönetmeliği wants this form shown with the
	 * concrete order before the contract is formed, so the block reads the live
	 * cart. Every figure is asked of WooCommerce (§17.3); nothing is recomputed
	 * here. With an empty cart the page stays the general information page the
	 * customer's PDF describes.
	 */
	public function preinfo_products(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '<p>' . esc_html__( 'Siparişe konu ürünün adı, bedeni, rengi, adedi, birim fiyatı, kargo bedeli ve KDV dahil toplam tutarı; siparişiniz onaylanmadan önce bu formda ve sipariş özetinde gösterilir.', 'kuka-island-core' ) . '</p>';
		}

		$cart = WC()->cart;
		$rows = '';
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) { continue; }
			$size  = '';
			$color = '';
			foreach ( (array) ( $item['variation'] ?? array() ) as $taxonomy => $slug ) {
				$label = wc_attribute_label( str_replace( 'attribute_', '', (string) $taxonomy ) );
				$term  = get_term_by( 'slug', (string) $slug, str_replace( 'attribute_', '', (string) $taxonomy ) );
				$name  = $term instanceof WP_Term ? $term->name : (string) $slug;
				if ( str_contains( (string) $taxonomy, 'beden' ) ) { $size = $name; }
				if ( str_contains( (string) $taxonomy, 'renk' ) ) { $color = $name; }
				unset( $label );
			}
			$rows .= '<tr>'
				. '<th scope="row">' . esc_html( $product->get_name() ) . '</th>'
				. '<td>' . esc_html( '' !== $size ? $size : '—' ) . '</td>'
				. '<td>' . esc_html( '' !== $color ? $color : '—' ) . '</td>'
				. '<td>' . esc_html( (string) (int) $item['quantity'] ) . '</td>'
				. '<td>' . wp_kses_post( wc_price( wc_get_price_to_display( $product ) ) ) . '</td>'
				. '<td>' . wp_kses_post( $cart->get_product_subtotal( $product, (int) $item['quantity'] ) ) . '</td>'
				. '</tr>';
		}
		if ( '' === $rows ) { return ''; }

		$shipping = (float) $cart->get_shipping_total() > 0
			? wc_price( (float) $cart->get_shipping_total() )
			: esc_html__( 'Ücretsiz', 'kuka-island-core' );

		return '<div class="kuka-table-scroll" tabindex="0"><table class="kuka-preinfo-order">'
			. '<caption>' . esc_html__( 'Siparişinizin güncel bilgileri', 'kuka-island-core' ) . '</caption>'
			. '<thead><tr>'
			. '<th scope="col">' . esc_html__( 'Ürün', 'kuka-island-core' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Beden', 'kuka-island-core' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Renk', 'kuka-island-core' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Adet', 'kuka-island-core' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Birim fiyat', 'kuka-island-core' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Ara toplam', 'kuka-island-core' ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody>'
			. '<tfoot>'
			. '<tr><th scope="row" colspan="5">' . esc_html__( 'Kargo/teslimat', 'kuka-island-core' ) . '</th><td>' . wp_kses_post( $shipping ) . '</td></tr>'
			. '<tr><th scope="row" colspan="5">' . esc_html__( 'Toplam (KDV dahil)', 'kuka-island-core' ) . '</th><td>' . wp_kses_post( wc_price( (float) $cart->get_total( 'edit' ) ) ) . '</td></tr>'
			. '</tfoot></table></div>';
	}

	/** @param array<string, string>|string $attributes */
	public function value( array|string $attributes ): string {
		$attributes = shortcode_atts( array( 'name' => '' ), (array) $attributes, 'kuka_value' );
		$content    = Kuka_Island_Core_Site_Appearance::get();
		if ( class_exists( 'Kuka_Island_Core_Language' ) ) { $content = Kuka_Island_Core_Language::localized_content( $content ); }
		$shipping_carrier = (string) $content['commercial']['shipping_carrier'];
		if ( function_exists( 'kuka_island_is_english' ) && kuka_island_is_english() && '[KARGO FİRMASI]' === $shipping_carrier ) {
			$shipping_carrier = '[SHIPPING CARRIER]';
		}
		$values     = array(
			'free_shipping_threshold' => wc_price( (float) $content['commercial']['free_shipping_threshold'] ),
			'flat_shipping_fee' => wc_price( (float) $content['commercial']['flat_shipping_fee'] ),
			'shipping_carrier' => esc_html( $shipping_carrier ),
			'delivery_time' => esc_html( (string) $content['commercial']['delivery_time'] ),
			'cayma_hakki_gun' => esc_html( (string) absint( $content['commercial']['cayma_hakki_gun'] ) ),
			'return_shipping_responsibility' => esc_html( (string) $content['commercial']['return_shipping_responsibility'] ),
			'support_hours' => esc_html( (string) $content['commercial']['support_hours'] ),
			'email' => esc_html( (string) $content['brand']['email'] ),
			'phone' => esc_html( (string) $content['brand']['phone'] ),
			'address_full' => esc_html( (string) $content['legal']['address_full'] ),
			'address_short' => esc_html( (string) $content['legal']['address_short'] ),
			'company_title' => esc_html( (string) $content['legal']['company_title'] ),
			'brand_name' => esc_html( (string) $content['legal']['brand_name'] ),
			'telephone' => esc_html( (string) $content['legal']['telephone'] ),
			'tax_office' => esc_html( (string) $content['legal']['tax_office'] ),
			'tax_number' => esc_html( (string) $content['legal']['tax_number'] ),
			'mersis_number' => esc_html( (string) $content['legal']['mersis_number'] ),
		);
		$name = sanitize_key( (string) $attributes['name'] );
		return isset( $values[ $name ] ) ? '<span data-kuka-value="' . esc_attr( $name ) . '">' . wp_kses_post( $values[ $name ] ) . '</span>' : '';
	}

	public function contact_details(): string {
		$content = Kuka_Island_Core_Site_Appearance::get();
		$whats   = self::whatsapp_url( (string) ( $content['brand']['whatsapp_phone'] ?? '' ) );
		$html    = '<ul class="kuka-contact-details">';
		if ( $whats ) {
			$html .= '<li><strong>WhatsApp:</strong> <a href="' . esc_url( $whats ) . '" rel="noopener">' . esc_html__( 'Mesaj gönder', 'kuka-island-core' ) . '</a></li>';
		}
		$html .= '<li><strong>' . esc_html__( 'Destek saatleri:', 'kuka-island-core' ) . '</strong> ' . esc_html( (string) $content['commercial']['support_hours'] ) . '</li>';
		$html .= '<li><strong>Instagram:</strong> <a href="https://www.instagram.com/kukaisland" rel="noopener">@kukaisland</a></li>';
		return $html . '</ul>';
	}

	public function size_guide(): string {
		$rows = Kuka_Island_Core_Site_Appearance::get()['content'];
		$html = '<div class="kuka-size-guide">';
		$html .= $this->size_table( __( 'Bikini üstü', 'kuka-island-core' ), array( __( 'Beden', 'kuka-island-core' ), __( 'Göğüs (cm)', 'kuka-island-core' ), __( 'Göğüs altı (cm)', 'kuka-island-core' ), __( 'Kupa', 'kuka-island-core' ) ), (string) $rows['size_top_rows'] );
		$html .= $this->size_table( __( 'Bikini altı', 'kuka-island-core' ), array( __( 'Beden', 'kuka-island-core' ), __( 'Bel (cm)', 'kuka-island-core' ), __( 'Kalça (cm)', 'kuka-island-core' ) ), (string) $rows['size_bottom_rows'] );
		$html .= $this->size_table( __( 'Mayo', 'kuka-island-core' ), array( __( 'Beden', 'kuka-island-core' ), __( 'Göğüs (cm)', 'kuka-island-core' ), __( 'Bel (cm)', 'kuka-island-core' ), __( 'Kalça (cm)', 'kuka-island-core' ) ), (string) $rows['size_swimsuit_rows'] );
		return $html . '</div>';
	}

	/** @param array<int, string> $headers */
	private function size_table( string $title, array $headers, string $source ): string {
		$html = '<section class="kuka-size-table"><h2>' . esc_html( $title ) . '</h2><div class="kuka-table-scroll" tabindex="0"><table><thead><tr>';
		foreach ( $headers as $header ) { $html .= '<th scope="col">' . esc_html( $header ) . '</th>'; }
		$html .= '</tr></thead><tbody>';
		foreach ( preg_split( '/\R/', $source ) ?: array() as $line ) {
			$cells = array_map( 'trim', explode( '|', $line ) );
			if ( count( $cells ) !== count( $headers ) ) { continue; }
			$html .= '<tr>';
			foreach ( $cells as $index => $cell ) { $html .= ( 0 === $index ? '<th scope="row">' : '<td>' ) . esc_html( $cell ) . ( 0 === $index ? '</th>' : '</td>' ); }
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div></section>';
	}
}
