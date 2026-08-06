<?php
/**
 * Review order table — Kuka Island order summary.
 *
 * Overrides woocommerce/templates/checkout/review-order.php @version 11.0.0.
 * Every cart line is listed in full with image, colour, size, quantity and line
 * price. All money values still come from WooCommerce helpers; no discount,
 * shipping or tax arithmetic is reimplemented here (§17.3).
 *
 * The root keeps the `woocommerce-checkout-review-order-table` class because
 * WC_AJAX::update_order_review replaces that exact selector on every refresh.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

$kuka_cart      = WC()->cart;
$kuka_count     = $kuka_cart->get_cart_contents_count();
$kuka_delivery  = function_exists( 'kuka_island_delivery_estimate' ) ? kuka_island_delivery_estimate() : '';
$kuka_progress  = function_exists( 'kuka_island_shipping_progress' ) ? kuka_island_shipping_progress() : '';
$kuka_coupons   = $kuka_cart->get_coupons();
?>
<table class="woocommerce-checkout-review-order-table kuka-summary">
	<caption class="screen-reader-text"><?php esc_html_e( 'Sipariş özeti', 'kuka-island' ); ?></caption>
	<thead>
		<tr class="kuka-summary__head">
			<th colspan="2">
				<span class="kuka-summary__count"><?php echo esc_html( sprintf( /* translators: %d is the number of items in the cart. */ _n( '%d ürün', '%d ürün', $kuka_count, 'kuka-island' ), $kuka_count ) ); ?></span>
				<a class="kuka-summary__edit" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Sepeti düzenle', 'kuka-island' ); ?></a>
			</th>
		</tr>
	</thead>
	<tbody>
		<?php
		do_action( 'woocommerce_review_order_before_cart_contents' );

		foreach ( $kuka_cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			$visible  = apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key );

			if ( ! $_product instanceof WC_Product || ! $_product->exists() || $cart_item['quantity'] <= 0 || ! $visible ) {
				continue;
			}

			$parent = ! empty( $cart_item['variation_id'] ) ? wc_get_product( $cart_item['product_id'] ) : false;
			$name   = $parent instanceof WC_Product ? $parent->get_name() : $_product->get_name();
			$pairs  = array();
			foreach ( (array) ( $cart_item['variation'] ?? array() ) as $attribute => $value ) {
				$taxonomy = str_replace( 'attribute_', '', $attribute );
				if ( ! taxonomy_exists( $taxonomy ) ) { continue; }
				$term    = get_term_by( 'slug', $value, $taxonomy );
				$pairs[] = array( wc_attribute_label( $taxonomy ), $term ? $term->name : $value );
			}
			?>
			<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?> kuka-summary__item">
				<td class="product-name">
					<span class="kuka-summary__thumb"><?php echo $_product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="kuka-summary__body">
						<span class="kuka-summary__name"><?php echo esc_html( $name ); ?></span>
						<?php if ( $pairs ) : ?>
							<span class="kuka-summary__attrs">
								<?php foreach ( $pairs as $pair ) : ?>
									<span class="kuka-summary__attr"><span><?php echo esc_html( $pair[0] ); ?></span><?php echo esc_html( $pair[1] ); ?></span>
								<?php endforeach; ?>
							</span>
						<?php endif; ?>
						<span class="kuka-summary__attr kuka-summary__qty"><span><?php esc_html_e( 'Adet', 'kuka-island' ); ?></span><?php echo esc_html( (string) $cart_item['quantity'] ); ?></span>
					</span>
				</td>
				<td class="product-total kuka-summary__price">
					<?php echo apply_filters( 'woocommerce_cart_item_subtotal', $kuka_cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</td>
			</tr>
			<?php
		}

		do_action( 'woocommerce_review_order_after_cart_contents' );
		?>
	</tbody>
	<tfoot>
		<?php if ( wc_coupons_enabled() ) : ?>
			<tr class="kuka-summary__coupon-row">
				<td colspan="2">
					<?php /* Kupon alanı checkout formunun içinde; iç içe <form> üretmemek
					         için gönderim `apply_coupon` düğmesiyle yapılır. JS açıkken
					         cart.js aynı düğmeyi WooCommerce'in apply_coupon uç noktasına
					         yönlendirir, JS kapalıyken form doğrudan gönderilir. */ ?>
					<details class="kuka-coupon">
						<summary class="kuka-coupon__toggle"><?php esc_html_e( 'Kupon kodunuz varsa girin', 'kuka-island' ); ?></summary>
						<div class="kuka-coupon__body">
							<label class="kuka-coupon__label" for="kuka_coupon_code"><?php esc_html_e( 'Kupon kodu', 'kuka-island' ); ?></label>
							<div class="kuka-coupon__row">
								<input type="text" id="kuka_coupon_code" name="coupon_code" class="kuka-coupon__input" value="" autocomplete="off" autocapitalize="characters">
								<button type="submit" class="kuka-coupon__submit" name="apply_coupon" value="1" data-kuka-apply-coupon><?php esc_html_e( 'Uygula', 'kuka-island' ); ?></button>
							</div>
							<p class="kuka-coupon__error" data-kuka-coupon-error role="alert" hidden></p>
						</div>
					</details>
				</td>
			</tr>
		<?php endif; ?>

		<tr class="cart-subtotal kuka-summary__row">
			<th><?php esc_html_e( 'Ara toplam', 'kuka-island' ); ?></th>
			<td><?php wc_cart_totals_subtotal_html(); ?></td>
		</tr>

		<?php foreach ( $kuka_coupons as $code => $coupon ) : ?>
			<tr class="cart-discount kuka-summary__row kuka-summary__row--discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
				<th><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
				<td><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( $kuka_cart->needs_shipping() && $kuka_cart->show_shipping() ) : ?>
			<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>
			<?php wc_cart_totals_shipping_html(); ?>
			<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>
		<?php endif; ?>

		<?php if ( '' !== $kuka_progress ) : ?>
			<tr class="kuka-summary__note-row">
				<td colspan="2"><p class="kuka-summary__note"><?php echo esc_html( $kuka_progress ); ?></p></td>
			</tr>
		<?php endif; ?>

		<?php foreach ( $kuka_cart->get_fees() as $fee ) : ?>
			<tr class="fee kuka-summary__row">
				<th><?php echo esc_html( $fee->name ); ?></th>
				<td><?php wc_cart_totals_fee_html( $fee ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( wc_tax_enabled() && ! $kuka_cart->display_prices_including_tax() ) : ?>
			<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) : ?>
				<?php foreach ( $kuka_cart->get_tax_totals() as $code => $tax ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited ?>
					<tr class="tax-rate kuka-summary__row tax-rate-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
						<th><?php echo esc_html( $tax->label ); ?></th>
						<td><?php echo wp_kses_post( $tax->formatted_amount ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr class="tax-total kuka-summary__row">
					<th><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
					<td><?php wc_cart_totals_taxes_total_html(); ?></td>
				</tr>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( '' !== $kuka_delivery ) : ?>
			<tr class="kuka-summary__row kuka-summary__row--delivery">
				<th><?php esc_html_e( 'Tahmini teslim', 'kuka-island' ); ?></th>
				<td><?php echo esc_html( $kuka_delivery ); ?></td>
			</tr>
		<?php endif; ?>

		<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>

		<tr class="order-total kuka-summary__total-row">
			<th><?php esc_html_e( 'Toplam', 'kuka-island' ); ?></th>
			<td><?php wc_cart_totals_order_total_html(); ?><span class="kuka-summary__tax-note"><?php esc_html_e( 'KDV dahil', 'kuka-island' ); ?></span></td>
		</tr>

		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>
	</tfoot>
</table>
