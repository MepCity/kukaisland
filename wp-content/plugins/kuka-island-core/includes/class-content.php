<?php
/**
 * Central, operator-editable content values and storefront shortcodes.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Content {
	public const LEGAL_DRAFT_WARNING = 'Hukuki taslak uyarısı: Bu metin Kuka Island için özgün olarak hazırlanmış bir çalışma taslağıdır; müşteri veya hukuk danışmanı onayı olmadan yürürlüğe girmez.';
	public const HYGIENE_POLICY = 'Koruyucu unsur, hijyen bandı veya mühür teslimden sonra açılmışsa; ürünün niteliği ve yürürlükteki mevzuat değerlendirilerek cayma hakkı istisnası uygulanabilir. Bu sonuç otomatik değildir ve her talep kendi koşullarıyla incelenir.';

	public function register(): void {
		add_shortcode( 'kuka_legal_warning', array( $this, 'legal_warning' ) );
		add_shortcode( 'kuka_company_details', array( $this, 'company_details' ) );
		add_shortcode( 'kuka_hygiene_policy', array( $this, 'hygiene_policy' ) );
		add_shortcode( 'kuka_value', array( $this, 'value' ) );
		add_shortcode( 'kuka_contact_details', array( $this, 'contact_details' ) );
		add_shortcode( 'kuka_size_guide', array( $this, 'size_guide' ) );
	}

	public function legal_warning(): string {
		return '<p class="kuka-legal-warning"><strong>' . esc_html( self::LEGAL_DRAFT_WARNING ) . '</strong></p>';
	}

	public function company_details(): string {
		$legal = Kuka_Island_Core_Site_Appearance::get()['legal'];
		$rows  = array(
			__( 'Şirket unvanı', 'kuka-island-core' ) => $legal['company_title'],
			__( 'VKN', 'kuka-island-core' ) => $legal['tax_number'],
			__( 'Vergi dairesi', 'kuka-island-core' ) => $legal['tax_office'],
			__( 'Adres', 'kuka-island-core' ) => $legal['address'],
			__( 'Telefon', 'kuka-island-core' ) => $legal['telephone'],
			__( 'ETBİS numarası', 'kuka-island-core' ) => $legal['etbis_number'],
			__( 'MERSİS numarası', 'kuka-island-core' ) => $legal['mersis_number'],
		);
		$html  = '<dl class="kuka-company-details">';
		foreach ( $rows as $label => $detail ) {
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $detail ) . '</dd></div>';
		}
		return $html . '</dl>';
	}

	public function hygiene_policy(): string {
		return '<span data-kuka-hygiene-policy>' . esc_html( self::HYGIENE_POLICY ) . '</span>';
	}

	/** @param array<string, string> $attributes */
	public function value( array $attributes ): string {
		$attributes = shortcode_atts( array( 'name' => '' ), $attributes, 'kuka_value' );
		$content    = Kuka_Island_Core_Site_Appearance::get();
		$values     = array(
			'free_shipping_threshold' => wc_price( (float) $content['commercial']['free_shipping_threshold'] ),
			'flat_shipping_fee' => wc_price( (float) $content['commercial']['flat_shipping_fee'] ),
			'shipping_carrier' => esc_html( (string) $content['commercial']['shipping_carrier'] ),
			'delivery_time' => esc_html( (string) $content['commercial']['delivery_time'] ),
			'return_period_days' => esc_html( (string) absint( $content['commercial']['return_period_days'] ) ),
			'return_shipping_responsibility' => esc_html( (string) $content['commercial']['return_shipping_responsibility'] ),
			'support_hours' => esc_html( (string) $content['commercial']['support_hours'] ),
			'email' => esc_html( (string) $content['brand']['email'] ),
			'phone' => esc_html( (string) $content['brand']['phone'] ),
		);
		$name = sanitize_key( (string) $attributes['name'] );
		return isset( $values[ $name ] ) ? '<span data-kuka-value="' . esc_attr( $name ) . '">' . wp_kses_post( $values[ $name ] ) . '</span>' : '';
	}

	public function contact_details(): string {
		$content = Kuka_Island_Core_Site_Appearance::get();
		$email   = (string) $content['brand']['email'];
		$phone   = (string) $content['brand']['phone'];
		$whats   = (string) $content['brand']['whatsapp_url'];
		$html    = '<ul class="kuka-contact-details">';
		$html   .= '<li><strong>' . esc_html__( 'E-posta:', 'kuka-island-core' ) . '</strong> <a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></li>';
		$html   .= '<li><strong>' . esc_html__( 'Telefon:', 'kuka-island-core' ) . '</strong> <a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a></li>';
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
		$html .= $this->size_table( __( 'Bikini üstü', 'kuka-island-core' ), array( 'EU', __( 'Harf', 'kuka-island-core' ), __( 'Göğüs (cm)', 'kuka-island-core' ), __( 'Göğüs altı (cm)', 'kuka-island-core' ), __( 'Kupa', 'kuka-island-core' ) ), (string) $rows['size_top_rows'] );
		$html .= $this->size_table( __( 'Bikini altı', 'kuka-island-core' ), array( 'EU', __( 'Harf', 'kuka-island-core' ), __( 'Bel (cm)', 'kuka-island-core' ), __( 'Kalça (cm)', 'kuka-island-core' ) ), (string) $rows['size_bottom_rows'] );
		$html .= $this->size_table( __( 'Mayo', 'kuka-island-core' ), array( 'EU', __( 'Harf', 'kuka-island-core' ), __( 'Göğüs (cm)', 'kuka-island-core' ), __( 'Bel (cm)', 'kuka-island-core' ), __( 'Kalça (cm)', 'kuka-island-core' ) ), (string) $rows['size_swimsuit_rows'] );
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
