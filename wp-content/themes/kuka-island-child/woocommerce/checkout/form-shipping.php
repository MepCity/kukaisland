<?php
/**
 * Checkout shipping form — Kuka Island section headings.
 *
 * Overrides woocommerce/templates/checkout/form-shipping.php.
 * Only the headings change. The `ship-to-different-address` wrapper id, the
 * checkbox name/id/classes and the `.shipping_address` container are kept
 * verbatim because WooCommerce's checkout.js toggles the block through them.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="woocommerce-shipping-fields">
	<?php if ( true === WC()->cart->needs_shipping_address() ) : ?>

		<?php /* Blok, teslimat adresinden farklı bir FATURA adresi sorar. WooCommerce'in
		         alan adları korunur (checkout.js bloğu bu id/class üzerinden açıp kapatır);
		         kutu işaretlenirse iki adres `woocommerce_checkout_posted_data` üzerinde
		         yer değiştirir, böylece fatura yine `billing_*` alanlarından üretilir. */ ?>
		<p id="ship-to-different-address" class="kuka-checkout-toggle">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input id="ship-to-different-address-checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" <?php checked( apply_filters( 'woocommerce_ship_to_different_address_checked', 0 ), 1 ); ?> type="checkbox" name="ship_to_different_address" value="1" /> <span><?php esc_html_e( 'Fatura adresim teslimat adresimden farklı', 'kuka-island' ); ?></span>
			</label>
		</p>

		<div class="shipping_address">

			<h3 class="kuka-checkout-section"><?php esc_html_e( 'Fatura adresi', 'kuka-island' ); ?></h3>

			<?php do_action( 'woocommerce_before_checkout_shipping_form', $checkout ); ?>

			<div class="woocommerce-shipping-fields__field-wrapper">
				<?php
				$fields = $checkout->get_checkout_fields( 'shipping' );

				foreach ( $fields as $key => $field ) {
					woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
				}
				?>
			</div>

			<?php do_action( 'woocommerce_after_checkout_shipping_form', $checkout ); ?>

		</div>

	<?php endif; ?>
</div>
<div class="woocommerce-additional-fields">
	<?php do_action( 'woocommerce_before_order_notes', $checkout ); ?>

	<?php if ( apply_filters( 'woocommerce_enable_order_notes_field', 'yes' === get_option( 'woocommerce_enable_order_comments', 'yes' ) ) ) : ?>

		<h3 class="kuka-checkout-section"><?php esc_html_e( 'Sipariş notu', 'kuka-island' ); ?></h3>

		<div class="woocommerce-additional-fields__field-wrapper">
			<?php foreach ( $checkout->get_checkout_fields( 'order' ) as $key => $field ) : ?>
				<?php woocommerce_form_field( $key, $field, $checkout->get_value( $key ) ); ?>
			<?php endforeach; ?>
		</div>

	<?php endif; ?>

	<?php do_action( 'woocommerce_after_order_notes', $checkout ); ?>
</div>
