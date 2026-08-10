<?php
/**
 * Progressive-enhancement storefront panels backed by WooCommerce.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

/** Return the current cart item count without assuming a cart session exists. */
function kuka_island_cart_count(): int {
	return WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
}

/** Render the cart count fragment used in the header. */
function kuka_island_cart_count_markup(): string {
	return '<span class="kuka-cart-count" aria-live="polite">' . esc_html( (string) kuka_island_cart_count() ) . '</span>';
}

/** Render the cart panel title so fragments keep its count current. */
function kuka_island_cart_title_markup(): string {
	return '<span id="kuka-cart-panel-title" class="kuka-cart-panel__title">' . esc_html( sprintf( __( 'Sepet / %d', 'kuka-island' ), kuka_island_cart_count() ) ) . '</span>';
}

/** Render the free-shipping progress message from Site Appearance content. */
function kuka_island_shipping_progress(): string {
	$content   = kuka_island_content();
	$threshold = max( 0.0, (float) ( $content['commercial']['free_shipping_threshold'] ?? 0 ) );
	$subtotal             = WC()->cart ? (float) WC()->cart->get_displayed_subtotal() : 0.0;
	$free_shipping_coupon = false;
	if ( WC()->cart ) {
		foreach ( WC()->cart->get_coupons() as $coupon ) {
			if ( $coupon instanceof WC_Coupon && $coupon->get_free_shipping() ) {
				$free_shipping_coupon = true;
				break;
			}
		}
	}
	if ( WC()->cart && 'no' === ( $content['commercial']['ignore_discounts'] ?? 'no' ) ) {
		$subtotal -= (float) WC()->cart->get_discount_total();
		if ( WC()->cart->display_prices_including_tax() ) {
			$subtotal -= (float) WC()->cart->get_discount_tax();
		}
	}
	$remaining = $free_shipping_coupon ? 0.0 : max( 0.0, $threshold - $subtotal );
	$price     = html_entity_decode( wp_strip_all_tags( wc_price( $remaining ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );

	if ( $threshold <= 0 ) {
		return (string) ( $content['commercial']['shipping_copy'] ?? '' );
	}

	return $remaining > 0
		? str_replace( '%s', $price, (string) ( $content['commercial']['free_shipping_remaining_copy'] ?? __( 'Ücretsiz kargo için %s daha ekleyin.', 'kuka-island' ) ) )
		: (string) ( $content['commercial']['free_shipping_ready_copy'] ?? __( 'Ücretsiz kargo hakkınız hazır.', 'kuka-island' ) );
}

/** Render one cart row. WooCommerce remains the source of price, variation and stock data. */
function kuka_island_cart_panel_item( string $cart_item_key, array $cart_item ): void {
	$product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
	if ( ! $product instanceof WC_Product || ! $product->exists() || $cart_item['quantity'] < 1 ) {
		return;
	}

	$parent = ! empty( $cart_item['variation_id'] ) ? wc_get_product( $cart_item['product_id'] ) : false;
	// `woocommerce_cart_item_name` bu mağazada bağlantı işaretlemesi döndürüyor;
	// panel adı kendi <a> etiketine sardığı ve metni kaçırdığı için ham ad kullanılır.
	$name      = $parent instanceof WC_Product ? $parent->get_name() : $product->get_name();
	$permalink = apply_filters( 'woocommerce_cart_item_permalink', $product->is_visible() ? $product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
	$maximum   = $product->get_max_purchase_quantity();
	$maximum   = $maximum < 0 ? '' : (string) $maximum;
	$variation = array();
	foreach ( (array) ( $cart_item['variation'] ?? array() ) as $attribute => $value ) {
		$taxonomy   = str_replace( 'attribute_', '', $attribute );
		$term       = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : false;
		$variation[] = $term ? $term->name : $value;
	}
	?>
	<article class="kuka-cart-panel__item">
		<?php if ( $permalink ) : ?><a class="kuka-cart-panel__image" href="<?php echo esc_url( $permalink ); ?>"><?php echo $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a><?php else : ?><span class="kuka-cart-panel__image"><?php echo $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); // phpcs:ignore ?></span><?php endif; ?>
		<div class="kuka-cart-panel__body">
			<?php if ( $permalink ) : ?><a class="kuka-cart-panel__name" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $name ); ?></a><?php else : ?><span class="kuka-cart-panel__name"><?php echo esc_html( $name ); ?></span><?php endif; ?>
			<?php if ( $variation ) : ?><p class="kuka-cart-panel__meta"><?php echo esc_html( implode( ' · ', $variation ) ); ?></p><?php endif; ?>
			<strong class="kuka-cart-panel__price"><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ) ); ?></strong>
			<form class="kuka-cart-panel__form" method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>" data-kuka-cart-update>
				<div class="kuka-cart-panel__quantity" role="group" aria-label="<?php echo esc_attr( sprintf( __( '%s adedi', 'kuka-island' ), $name ) ); ?>">
					<button type="button" data-kuka-quantity-step="-1" aria-label="<?php esc_attr_e( 'Adedi azalt', 'kuka-island' ); ?>">−</button>
					<input type="number" name="cart[<?php echo esc_attr( $cart_item_key ); ?>][qty]" value="<?php echo esc_attr( (string) $cart_item['quantity'] ); ?>" min="0" <?php echo '' !== $maximum ? 'max="' . esc_attr( $maximum ) . '"' : ''; ?> step="1" inputmode="numeric" aria-label="<?php echo esc_attr( sprintf( __( '%s adedi', 'kuka-island' ), $name ) ); ?>">
					<button type="button" data-kuka-quantity-step="1" aria-label="<?php esc_attr_e( 'Adedi artır', 'kuka-island' ); ?>">+</button>
				</div>
				<button class="kuka-cart-panel__remove" type="button" data-kuka-cart-remove><?php esc_html_e( 'Kaldır', 'kuka-island' ); ?></button>
				<input type="hidden" name="update_cart" value="1">
				<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
			</form>
		</div>
	</article>
	<?php
}

/** Render the replaceable cart panel body. */
function kuka_island_cart_panel_content(): void {
	$items = WC()->cart ? WC()->cart->get_cart() : array();
	?>
	<div id="kuka-cart-panel-content" class="kuka-cart-panel__content" aria-live="polite" aria-busy="false">
		<?php if ( ! $items ) : ?>
			<div class="kuka-cart-panel__empty">
				<p class="kuka-eyebrow"><?php esc_html_e( 'Sepetiniz boş', 'kuka-island' ); ?></p>
				<h2><?php esc_html_e( 'Ada seçkisini keşfedin.', 'kuka-island' ); ?></h2>
				<a class="kuka-button" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Alışverişe başla', 'kuka-island' ); ?></a>
			</div>
		<?php else : ?>
			<div class="kuka-cart-panel__items">
				<?php foreach ( $items as $cart_item_key => $cart_item ) { kuka_island_cart_panel_item( $cart_item_key, $cart_item ); } ?>
			</div>
			<div class="kuka-cart-panel__foot">
				<p class="kuka-cart-panel__shipping"><?php echo esc_html( kuka_island_shipping_progress() ); ?></p>
				<div class="kuka-cart-panel__subtotal"><span><?php esc_html_e( 'Ara toplam', 'kuka-island' ); ?></span><strong><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></strong></div>
				<div class="kuka-cart-panel__actions"><a class="kuka-button" href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php esc_html_e( 'Ödemeye geç', 'kuka-island' ); ?></a><a class="kuka-button kuka-button--outline" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Sepete git', 'kuka-island' ); ?></a></div>
				<p class="kuka-cart-panel__legal"><a href="<?php echo esc_url( home_url( '/on-bilgilendirme-formu/' ) ); ?>"><?php esc_html_e( 'Ön Bilgilendirme Formu', 'kuka-island' ); ?></a><span aria-hidden="true">·</span><a href="<?php echo esc_url( home_url( '/mesafeli-satis-sozlesmesi/' ) ); ?>"><?php esc_html_e( 'Mesafeli Satış Sözleşmesi', 'kuka-island' ); ?></a></p>
				<p class="kuka-cart-panel__security"><?php echo esc_html( kuka_island_content()['commercial']['secure_payment_copy'] ?? __( 'Ödeme bilgileriniz güvenli ödeme altyapısında korunur', 'kuka-island' ) ); ?></p>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/** Return custom cart fragments alongside WooCommerce's core fragments. */
function kuka_island_cart_fragments( array $fragments ): array {
	$fragments['.kuka-cart-count'] = kuka_island_cart_count_markup();
	$fragments['.kuka-cart-panel__title'] = kuka_island_cart_title_markup();
	ob_start();
	kuka_island_cart_panel_content();
	$fragments['#kuka-cart-panel-content'] = ob_get_clean();
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'kuka_island_cart_fragments' );

/** A non-JavaScript add-to-cart submission finishes on the full cart page. */
add_filter( 'woocommerce_add_to_cart_redirect', static fn(): string => wc_get_cart_url() );
