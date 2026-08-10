<?php
/**
 * Classic checkout presentation layer.
 *
 * Only ordering, headings and wrappers are owned here. Coupon, discount, tax
 * and total arithmetic stays with WooCommerce (§17.3); the iyzico gateway
 * markup is never touched.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce prints its own coupon form as a nested <form> above the checkout.
 * The summary column owns the coupon field instead, so the default block is
 * dropped rather than styled twice. Payment moves to the form column; the AJAX
 * fragment targets `.woocommerce-checkout-payment` by class, so refreshes keep
 * working wherever the block sits.
 */
add_action(
	'wp_loaded',
	static function (): void {
		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
		remove_action( 'woocommerce_before_checkout_form_cart_notices', 'woocommerce_output_all_notices', 10 );
		remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
	}
);

/**
 * Use the visible field label in required-field notices.
 *
 * WooCommerce prefixes checkout validation labels with their fieldset context
 * ("Billing" / "Shipping"). The form itself already supplies that context in
 * its section headings, so repeating it makes Turkish notices read unnaturally.
 * Looking the original label up by field key changes only the supported notice
 * filter; field names, posted keys and validation remain untouched.
 *
 * @param string $notice      WooCommerce's formatted notice.
 * @param string $field_label Context-prefixed validation label.
 * @param string $key         Checkout field key.
 * @return string
 */
function kuka_island_checkout_required_field_notice( string $notice, string $field_label, string $key ): string {
	unset( $field_label );
	$checkout = WC()->checkout();
	if ( ! $checkout instanceof WC_Checkout ) {
		return $notice;
	}

	foreach ( $checkout->get_checkout_fields() as $fields ) {
		if ( ! isset( $fields[ $key ]['label'] ) ) {
			continue;
		}
		$label = trim( wp_strip_all_tags( (string) $fields[ $key ]['label'] ) );
		if ( '' === $label ) {
			return $notice;
		}
		/* translators: %s: checkout field name */
		return sprintf( __( '%s is a required field.', 'woocommerce' ), '<strong>' . esc_html( $label ) . '</strong>' );
	}

	return $notice;
}
add_filter( 'woocommerce_checkout_required_field_notice', 'kuka_island_checkout_required_field_notice', 20, 3 );

/**
 * Keep the store administrator address out of storefront checkout previews.
 * Real customer account data and values deliberately entered in the session
 * continue to use WooCommerce's normal persistence rules.
 *
 * @param mixed  $value Checkout field value.
 * @param string $key   Checkout field key.
 * @return mixed
 */
function kuka_island_checkout_hide_manager_email( $value, string $key ) {
	if ( 'billing_email' !== $key || ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
		return $value;
	}
	return '';
}
add_filter( 'woocommerce_checkout_get_value', 'kuka_island_checkout_hide_manager_email', 20, 2 );

/**
 * Which optional fields the operator has made mandatory.
 *
 * Only fields the law leaves to the seller are listed. Name, surname, e-mail,
 * street address, province and postcode are required by the distance-selling
 * rules, so they never appear in the panel and cannot be switched off.
 *
 * @return array<string, bool>
 */
function kuka_island_checkout_required_fields(): array {
	$settings = kuka_island_content()['checkout'] ?? array();
	return array(
		'billing_phone'     => (bool) ( $settings['require_phone'] ?? true ),
		'billing_company'   => (bool) ( $settings['require_company'] ?? false ),
		'billing_address_2' => (bool) ( $settings['require_address_2'] ?? false ),
		'billing_city'      => (bool) ( $settings['require_city'] ?? false ),
	);
}

/**
 * Group the billing fields under readable headings: personal details first,
 * delivery address second, invoice details last. Only the documented
 * `priority` and `required` contracts are used; every field keeps its
 * WooCommerce key, type and validation.
 *
 * @param array<string, array<string, mixed>> $fields Checkout fields.
 * @return array<string, array<string, mixed>>
 */
function kuka_island_checkout_field_order( array $fields ): array {
	$priorities = array(
		'billing_first_name'    => 10,
		'billing_last_name'     => 20,
		'billing_email'         => 30,
		'billing_phone'         => 40,
		'billing_country'       => 55,
		'billing_address_1'     => 60,
		'billing_address_2'     => 65,
		'billing_postcode'      => 70,
		'billing_city'          => 75,
		'billing_state'         => 80,
		'billing_customer_type' => 110,
		'billing_company'       => 115,
		'billing_tax_office'    => 116,
		'billing_tax_number'    => 117,
	);
	foreach ( $priorities as $key => $priority ) {
		if ( isset( $fields['billing'][ $key ] ) ) {
			$fields['billing'][ $key ]['priority'] = $priority;
		}
	}
	foreach ( kuka_island_checkout_required_fields() as $key => $required ) {
		if ( isset( $fields['billing'][ $key ] ) ) {
			$fields['billing'][ $key ]['required'] = $required;
		}
	}
	if ( isset( $fields['billing']['billing_email'], $fields['billing']['billing_phone'] ) ) {
		$fields['billing']['billing_email']['class'] = array( 'form-row-first' );
		$fields['billing']['billing_phone']['class'] = array( 'form-row-last' );
	}
	// Fatura adresi bloğu yalnız adres bileşenlerini sorar; alıcı adı ve şirket
	// bilgisi kişisel/fatura bölümlerinden gelir ve gönderimde kopyalanır.
	unset( $fields['shipping']['shipping_first_name'], $fields['shipping']['shipping_last_name'], $fields['shipping']['shipping_company'] );
	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'kuka_island_checkout_field_order', 30 );

/**
 * Keep the country locale priorities in step with the field order above.
 *
 * WooCommerce's address-i18n script rewrites `data-priority` and the column
 * classes of the locale fields from this array and then re-sorts the wrapper on
 * the client. Without this filter the rendered order and the sorted order
 * disagree and the address block jumps on load.
 *
 * @return array<string, int>
 */
function kuka_island_checkout_address_priorities(): array {
	return array(
		'phone' => 40, 'country' => 55, 'address_1' => 60,
		'address_2' => 65, 'postcode' => 70, 'city' => 75, 'state' => 80,
	);
}

/**
 * The same required flags, keyed the way the locale array is.
 *
 * WooCommerce's address-i18n script rebuilds the label and the
 * `validate-required` class of every locale field on load, so a rule applied
 * only to `woocommerce_checkout_fields` is correct on the server and undone in
 * the browser — the phone rendered as "Telefon *" but read "(isteğe bağlı)"
 * once the script ran.
 *
 * @return array<string, bool>
 */
function kuka_island_checkout_address_required(): array {
	$required = array();
	foreach ( kuka_island_checkout_required_fields() as $key => $value ) {
		$required[ substr( $key, strlen( 'billing_' ) ) ] = $value;
	}
	return $required;
}

/**
 * Per-country overrides. `default` is rebuilt after this filter runs, so the
 * default set is handled by `woocommerce_default_address_fields` below.
 *
 * @param array<string, array<string, array<string, mixed>>> $locale Country locale map.
 * @return array<string, array<string, array<string, mixed>>>
 */
function kuka_island_checkout_locale( array $locale ): array {
	$required = kuka_island_checkout_address_required();
	foreach ( array_keys( $locale ) as $code ) {
		foreach ( kuka_island_checkout_address_priorities() as $field => $priority ) {
			if ( isset( $locale[ $code ][ $field ] ) ) {
				$locale[ $code ][ $field ]['priority'] = $priority;
			}
		}
		foreach ( $required as $field => $is_required ) {
			if ( isset( $locale[ $code ][ $field ] ) ) {
				$locale[ $code ][ $field ]['required'] = $is_required;
			}
		}
	}
	return $locale;
}
add_filter( 'woocommerce_get_country_locale', 'kuka_island_checkout_locale', 20 );

/**
 * Default address field priorities and the phone column.
 *
 * @param array<string, array<string, mixed>> $fields Default address fields.
 * @return array<string, array<string, mixed>>
 */
function kuka_island_checkout_default_address_fields( array $fields ): array {
	foreach ( kuka_island_checkout_address_priorities() as $field => $priority ) {
		if ( isset( $fields[ $field ] ) ) {
			$fields[ $field ]['priority'] = $priority;
		}
	}
	foreach ( kuka_island_checkout_address_required() as $field => $is_required ) {
		if ( isset( $fields[ $field ] ) ) {
			$fields[ $field ]['required'] = $is_required;
		}
	}
	if ( isset( $fields['phone'] ) ) {
		$fields['phone']['class'] = array( 'form-row-last' );
	}
	return $fields;
}
add_filter( 'woocommerce_default_address_fields', 'kuka_island_checkout_default_address_fields', 20 );

/**
 * Prepend a section heading to the first field of each billing group.
 *
 * The heading is emitted as a `.form-row` carrying its own `data-priority`
 * because the address-i18n script re-appends only `.form-row` elements when it
 * sorts the wrapper; anything else would be torn off its group. It stays markup
 * only — never a form field — so posted data, validation and order meta are
 * untouched.
 *
 * @param string $field Rendered field markup.
 * @param string $key   Field key.
 * @return string
 */
function kuka_island_checkout_section_headings( string $field, string $key ): string {
	if ( ! is_checkout() ) { return $field; }
	$headings = array(
		'billing_country'       => array( __( 'Teslimat adresi', 'kuka-island' ), 50 ),
		'billing_customer_type' => array( __( 'Fatura bilgileri', 'kuka-island' ), 105 ),
	);
	if ( ! isset( $headings[ $key ] ) ) { return $field; }
	list( $label, $priority ) = $headings[ $key ];
	return '<p class="form-row form-row-wide kuka-checkout-heading" data-priority="' . esc_attr( (string) $priority ) . '">'
		. '<span class="kuka-checkout-section" role="heading" aria-level="3">' . esc_html( $label ) . '</span></p>' . $field;
}
add_filter( 'woocommerce_form_field', 'kuka_island_checkout_section_headings', 10, 2 );

/**
 * Preserve field-level required errors in the server-rendered, no-JS response.
 * The enhanced checkout builds the same markup from AJAX error data.
 *
 * @param string               $field Rendered field markup.
 * @param string               $key   Field key.
 * @param array<string, mixed> $args  Field arguments.
 * @param mixed                $value Field value.
 * @return string
 */
function kuka_island_checkout_server_field_error( string $field, string $key, array $args, $value ): string {
	unset( $value );
	if ( ! is_checkout() || 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || empty( $args['required'] ) ) {
		return $field;
	}
	$posted = isset( $_POST[ $key ] ) ? trim( (string) wc_clean( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( '' !== $posted ) {
		return $field;
	}

	$error_id = $key . '_error';
	$field    = preg_replace( '/class="form-row\s+/', 'class="form-row kuka-field-invalid ', $field, 1 ) ?? $field;
	$field    = str_replace(
		'id="' . esc_attr( $key ) . '"',
		'id="' . esc_attr( $key ) . '" aria-invalid="true" aria-describedby="' . esc_attr( $error_id ) . '"',
		$field
	);
	$error = '<span class="kuka-field-error" id="' . esc_attr( $error_id ) . '">' . esc_html__( 'Bu alan zorunludur.', 'kuka-island' ) . '</span>';
	return preg_replace( '/<\/p>\s*$/', $error . '</p>', $field, 1 ) ?? $field . $error;
}
add_filter( 'woocommerce_form_field', 'kuka_island_checkout_server_field_error', 20, 4 );

/**
 * Short security note above the payment cards. The sentence is panel-owned so
 * the operator can restate it; the gateway's own markup stays untouched.
 */
add_action(
	'woocommerce_review_order_before_payment',
	static function (): void {
		$copy = trim( (string) ( kuka_island_content()['commercial']['secure_payment_copy'] ?? '' ) );
		if ( '' === $copy ) { return; }
		echo '<p class="kuka-payment-note">' . esc_html( $copy ) . '</p>';
	}
);

/** Open the billing fieldset with its own section heading. */
add_action(
	'woocommerce_before_checkout_billing_form',
	static function (): void {
		echo '<h3 class="kuka-checkout-section kuka-checkout-section--first">' . esc_html__( 'Kişisel bilgiler', 'kuka-island' ) . '</h3>';
	}
);

/**
 * Swap the two addresses so WooCommerce keeps its own meaning of the fields.
 *
 * The form asks for the delivery address first and offers a separate invoice
 * address behind the checkbox — the mirror of WooCommerce's own wording. In
 * WooCommerce, `billing_*` is the invoice address and `shipping_*` the delivery
 * one, so when the box is ticked the two posted address blocks are exchanged.
 * With the box clear WooCommerce already copies billing to shipping, so one
 * address serves both and nothing needs moving. Only address components move;
 * name, e-mail, phone and the corporate invoice fields stay where they were
 * entered, and no total is touched (§17.3).
 *
 * @param array<string, mixed> $data Posted checkout data.
 * @return array<string, mixed>
 */
function kuka_island_checkout_swap_addresses( array $data ): array {
	if ( empty( $data['ship_to_different_address'] ) ) {
		return $data;
	}
	foreach ( array( 'country', 'address_1', 'address_2', 'postcode', 'city', 'state' ) as $part ) {
		if ( ! array_key_exists( 'billing_' . $part, $data ) || ! array_key_exists( 'shipping_' . $part, $data ) ) {
			continue;
		}
		$delivery                    = $data[ 'billing_' . $part ];
		$data[ 'billing_' . $part ]  = $data[ 'shipping_' . $part ];
		$data[ 'shipping_' . $part ] = $delivery;
	}
	$data['shipping_first_name'] = $data['billing_first_name'] ?? '';
	$data['shipping_last_name']  = $data['billing_last_name'] ?? '';
	$data['shipping_company']    = $data['billing_company'] ?? '';
	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', 'kuka_island_checkout_swap_addresses' );

/**
 * Turn the panel delivery-time value into a concrete date window.
 *
 * The panel field is free text, so a value without digits — the untouched
 * `[TESLİMAT SÜRESİ]` placeholder, for example — yields an empty string and the
 * summary row is skipped. No date is invented.
 */
function kuka_island_delivery_estimate(): string {
	$raw = trim( (string) ( kuka_island_content()['commercial']['delivery_time'] ?? '' ) );
	if ( '' === $raw ) { return ''; }
	preg_match_all( '/\d+/', $raw, $matches );
	$days = array_values( array_filter( array_map( 'absint', $matches[0] ?? array() ) ) );
	if ( ! $days ) { return ''; }
	$first = kuka_island_business_day( min( $days ) );
	$last  = kuka_island_business_day( max( $days ) );
	return $first === $last ? $first : $first . ' – ' . $last;
}

/** Add business days to today in the site timezone and format them locally. */
function kuka_island_business_day( int $days ): string {
	$date  = current_datetime();
	$added = 0;
	while ( $added < $days ) {
		$date = $date->modify( '+1 day' );
		if ( (int) $date->format( 'N' ) < 6 ) { ++$added; }
	}
	return wp_date( 'j F', $date->getTimestamp() );
}

/** Whether a panel value is still an unfilled `[YER TUTUCU]` marker. */
function kuka_island_is_placeholder( string $value ): bool {
	return (bool) preg_match( '/^\s*\[.*\]\s*$/u', $value );
}

/**
 * Render the three-step indicator. Every step is a real page: the cart, this
 * checkout and the order-received screen.
 */
function kuka_island_checkout_steps(): void {
	$steps = array(
		array( 'label' => __( 'Sepet', 'kuka-island' ), 'url' => wc_get_cart_url(), 'state' => 'done' ),
		array( 'label' => __( 'Bilgiler ve ödeme', 'kuka-island' ), 'url' => '', 'state' => 'current' ),
		array( 'label' => __( 'Onay', 'kuka-island' ), 'url' => '', 'state' => 'upcoming' ),
	);
	$total = count( $steps );
	echo '<nav class="kuka-checkout-steps" aria-label="' . esc_attr__( 'Ödeme adımları', 'kuka-island' ) . '"><ol>';
	foreach ( $steps as $index => $step ) {
		/* translators: 1: current step number, 2: total step count. */
		$counter = '<span class="kuka-checkout-steps__count">' . esc_html( sprintf( __( '%1$d / %2$d', 'kuka-island' ), $index + 1, $total ) ) . '</span>';
		$inner   = $counter . '<span class="kuka-checkout-steps__label">' . esc_html( $step['label'] ) . '</span>';
		echo '<li class="kuka-checkout-steps__item kuka-checkout-steps__item--' . esc_attr( $step['state'] ) . '"';
		if ( 'current' === $step['state'] ) { echo ' aria-current="step"'; }
		echo '>';
		if ( $step['url'] ) {
			echo '<a href="' . esc_url( $step['url'] ) . '">' . $inner . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<span>' . $inner . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</li>';
	}
	echo '</ol></nav>';
}

/**
 * Render one summary accordion. An empty body means the operator left the
 * panel field blank, so the whole row disappears instead of showing a gap.
 */
function kuka_island_checkout_accordion( string $title, string $body ): void {
	if ( '' === trim( wp_strip_all_tags( $body ) ) ) { return; }
	echo '<details class="kuka-checkout-help__item"><summary>' . esc_html( $title ) . '</summary><div class="kuka-faq__panel"><p>' . wp_kses( $body, array( 'a' => array( 'href' => true, 'rel' => true, 'target' => true ) ) ) . '</p></div></details>';
}

/** Render the help, security and shipping accordions under the order summary. */
function kuka_island_checkout_help(): void {
	$content      = kuka_island_content();
	$commercial   = $content['commercial'] ?? array();
	$brand        = $content['brand'] ?? array();
	$whatsapp_url = kuka_island_whatsapp_url();

	$support = array();
	if ( ! empty( $commercial['support_hours'] ) ) { $support[] = esc_html( (string) $commercial['support_hours'] ); }
	if ( $whatsapp_url ) { $support[] = '<a href="' . esc_url( $whatsapp_url ) . '" rel="noopener" target="_blank">WhatsApp</a>'; }
	if ( ! empty( $brand['email'] ) ) { $support[] = '<a href="mailto:' . esc_attr( (string) $brand['email'] ) . '">' . esc_html( (string) $brand['email'] ) . '</a>'; }

	$shipping = array();
	foreach ( array( 'shipping_carrier', 'delivery_time' ) as $key ) {
		$value = (string) ( $commercial[ $key ] ?? '' );
		if ( '' !== trim( $value ) && ! kuka_island_is_placeholder( $value ) ) { $shipping[] = esc_html( $value ); }
	}
	if ( ! empty( $commercial['cayma_hakki_gun'] ) ) {
		/* translators: %d is the statutory withdrawal window in days. */
		$shipping[] = esc_html( sprintf( __( '%d gün içinde cayma hakkı', 'kuka-island' ), absint( $commercial['cayma_hakki_gun'] ) ) );
	}

	echo '<div class="kuka-checkout-help">';
	kuka_island_checkout_accordion( __( 'Yardım gerekiyor mu?', 'kuka-island' ), implode( ' · ', $support ) );
	kuka_island_checkout_accordion( __( 'Güvenli ödeme', 'kuka-island' ), esc_html__( 'Ödeme iyzico altyapısında alınır ve 3D Secure doğrulamasıyla tamamlanır. Kart bilgileriniz Kuka Island sunucusunda saklanmaz.', 'kuka-island' ) );
	kuka_island_checkout_accordion( __( 'Kargo ve iade', 'kuka-island' ), implode( ' · ', $shipping ) );
	echo '</div>';
}
