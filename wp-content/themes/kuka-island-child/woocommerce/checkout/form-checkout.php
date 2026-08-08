<?php
/**
 * Checkout form — Kuka Island two-column layout.
 *
 * Overrides woocommerce/templates/checkout/form-checkout.php @version 9.4.0.
 * The flow stays single page (§17.3): the same fields, the same actions and
 * the same `form.checkout` submit contract. Only the wrapper, the column split
 * and the summary/payment placement belong to the theme.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_checkout_form', $checkout );

// If checkout registration is disabled and not logged in, the user cannot checkout.
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html__( 'Ödeme şu anda kullanılamıyor.', 'kuka-island' );
	return;
}

$kuka_summary_total = html_entity_decode( wp_strip_all_tags( wc_price( (float) WC()->cart->get_total( 'edit' ) ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
?>
<div class="kuka-checkout">
	<?php kuka_island_checkout_steps(); ?>

	<form name="checkout" method="post" class="checkout woocommerce-checkout kuka-checkout__grid" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php esc_attr_e( 'Ödeme', 'kuka-island' ); ?>">

		<aside class="kuka-checkout__summary">
			<?php /* Masaüstünde <summary> gizlenir ve blok hep açık kalır; mobilde
			         cart.js kapatır. JS kapalıyken özet açık gelir, yani içerik
			         hiçbir koşulda erişilemez olmaz. */ ?>
			<details class="kuka-checkout-summary" open data-checkout-summary>
				<summary class="kuka-checkout-summary__toggle">
					<span id="order_review_heading"><?php esc_html_e( 'Sipariş özeti', 'kuka-island' ); ?></span>
					<span class="kuka-checkout-summary__total"><?php echo esc_html( $kuka_summary_total ); ?></span>
				</summary>
				<div class="kuka-checkout-summary__body">
					<?php /* Blocksy `before_order_review_heading` üzerinde bir sarmalayıcı
					         açıp `after_order_review` üzerinde kapatıyor; iki kanca aynı
					         düzeyde çağrılmazsa sarmalayıcı <aside>'ı yutuyor. */ ?>
					<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
					<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

					<div id="order_review" class="woocommerce-checkout-review-order">
						<?php do_action( 'woocommerce_checkout_order_review' ); ?>
					</div>

					<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>

					<?php kuka_island_checkout_help(); ?>
				</div>
			</details>
		</aside>

		<div class="kuka-checkout__fields">
			<?php if ( $checkout->get_checkout_fields() ) : ?>

				<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

				<div class="col2-set" id="customer_details">
					<div class="col-1">
						<?php do_action( 'woocommerce_checkout_billing' ); ?>
					</div>

					<div class="col-2">
						<?php do_action( 'woocommerce_checkout_shipping' ); ?>
					</div>
				</div>

				<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

			<?php endif; ?>

			<div class="kuka-checkout__payment">
				<h3 class="kuka-checkout-section"><?php esc_html_e( 'Ödeme', 'kuka-island' ); ?></h3>
				<?php woocommerce_checkout_payment(); ?>
			</div>
		</div>

	</form>
</div>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
