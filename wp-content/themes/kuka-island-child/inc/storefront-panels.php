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

/** Render the free-shipping progress message from Site Appearance content. */
function kuka_island_shipping_progress(): string {
	$content   = kuka_island_content();
	$threshold = max( 0.0, (float) ( $content['commercial']['free_shipping_threshold'] ?? 0 ) );
	$subtotal  = WC()->cart ? (float) WC()->cart->get_displayed_subtotal() : 0.0;
	$remaining = max( 0.0, $threshold - $subtotal );
	$price     = html_entity_decode( wp_strip_all_tags( wc_price( $remaining ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );

	if ( $threshold <= 0 ) {
		return (string) ( $content['commercial']['shipping_copy'] ?? '' );
	}

	return $remaining > 0
		? sprintf( __( 'Ücretsiz kargo için %s daha ekleyin.', 'kuka-island' ), $price )
		: __( 'Ücretsiz kargo hakkınız hazır.', 'kuka-island' );
}

/** Render one cart row. WooCommerce remains the source of price, variation and stock data. */
function kuka_island_cart_panel_item( string $cart_item_key, array $cart_item ): void {
	$product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
	if ( ! $product instanceof WC_Product || ! $product->exists() || $cart_item['quantity'] < 1 ) {
		return;
	}

	$parent    = ! empty( $cart_item['variation_id'] ) ? wc_get_product( $cart_item['product_id'] ) : false;
	$name      = apply_filters( 'woocommerce_cart_item_name', $parent ? $parent->get_name() : $product->get_name(), $cart_item, $cart_item_key );
	$permalink = apply_filters( 'woocommerce_cart_item_permalink', $product->is_visible() ? $product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
	$maximum   = $product->get_max_purchase_quantity();
	$maximum   = $maximum < 0 ? '' : (string) $maximum;
	?>
	<article class="kuka-cart-panel__item">
		<?php if ( $permalink ) : ?><a class="kuka-cart-panel__image" href="<?php echo esc_url( $permalink ); ?>"><?php echo $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a><?php else : ?><span class="kuka-cart-panel__image"><?php echo $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); // phpcs:ignore ?></span><?php endif; ?>
		<div class="kuka-cart-panel__body">
			<div class="kuka-cart-panel__summary">
				<?php if ( $permalink ) : ?><a class="kuka-cart-panel__name" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $name ); ?></a><?php else : ?><span class="kuka-cart-panel__name"><?php echo esc_html( $name ); ?></span><?php endif; ?>
				<strong><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ) ); ?></strong>
			</div>
			<?php if ( ! empty( $cart_item['variation'] ) ) : ?>
				<dl class="kuka-cart-panel__meta">
					<?php foreach ( $cart_item['variation'] as $attribute => $value ) :
						$taxonomy = str_replace( 'attribute_', '', $attribute );
						$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : false;
						?>
						<div><dt><?php echo esc_html( wc_attribute_label( $taxonomy ) ); ?>:</dt> <dd><?php echo esc_html( $term ? $term->name : $value ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
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
				<div class="kuka-cart-panel__actions"><a href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Sepete git', 'kuka-island' ); ?></a><a class="kuka-button" href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php esc_html_e( 'Ödemeye geç', 'kuka-island' ); ?></a></div>
				<p class="kuka-cart-panel__legal"><a href="<?php echo esc_url( home_url( '/on-bilgilendirme-formu/' ) ); ?>"><?php esc_html_e( 'Ön Bilgilendirme Formu', 'kuka-island' ); ?></a><span aria-hidden="true">·</span><a href="<?php echo esc_url( home_url( '/mesafeli-satis-sozlesmesi/' ) ); ?>"><?php esc_html_e( 'Mesafeli Satış Sözleşmesi', 'kuka-island' ); ?></a></p>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/** Return custom cart fragments alongside WooCommerce's core fragments. */
function kuka_island_cart_fragments( array $fragments ): array {
	$fragments['.kuka-cart-count'] = kuka_island_cart_count_markup();
	ob_start();
	kuka_island_cart_panel_content();
	$fragments['#kuka-cart-panel-content'] = ob_get_clean();
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'kuka_island_cart_fragments' );

/** A non-JavaScript add-to-cart submission finishes on the full cart page. */
add_filter( 'woocommerce_add_to_cart_redirect', static fn(): string => wc_get_cart_url() );

/** Whether a failed native WooCommerce login should reopen the account panel. */
function kuka_island_account_panel_requires_attention(): bool {
	return isset( $_POST['login'] ) && wc_notice_count( 'error' ) > 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce validates its own login nonce.
}

/** Render native account actions or WooCommerce's nonce-protected login form. */
function kuka_island_account_panel_content(): void {
	?>
	<div class="kuka-account-panel__content">
		<?php if ( is_user_logged_in() ) :
			$user = wp_get_current_user();
			?>
			<p class="kuka-eyebrow"><?php esc_html_e( 'Hoş geldiniz', 'kuka-island' ); ?></p>
			<h2><?php echo esc_html( $user->display_name ); ?></h2>
			<nav aria-label="<?php esc_attr_e( 'Hesap işlemleri', 'kuka-island' ); ?>">
				<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"><?php esc_html_e( 'Hesabım', 'kuka-island' ); ?><span aria-hidden="true">→</span></a>
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>"><?php esc_html_e( 'Siparişler', 'kuka-island' ); ?><span aria-hidden="true">→</span></a>
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-address' ) ); ?>"><?php esc_html_e( 'Adresler', 'kuka-island' ); ?><span aria-hidden="true">→</span></a>
				<a href="<?php echo esc_url( wc_logout_url() ); ?>"><?php esc_html_e( 'Çıkış yap', 'kuka-island' ); ?><span aria-hidden="true">→</span></a>
			</nav>
		<?php else : ?>
			<p class="kuka-eyebrow"><?php esc_html_e( 'Tekrar hoş geldiniz', 'kuka-island' ); ?></p>
			<h2><?php esc_html_e( 'Hesabınıza giriş yapın.', 'kuka-island' ); ?></h2>
			<?php if ( kuka_island_account_panel_requires_attention() ) : ?>
				<div class="kuka-account-panel__errors" role="alert"><strong><?php esc_html_e( 'Giriş tamamlanamadı.', 'kuka-island' ); ?></strong><?php wc_print_notices(); ?></div>
			<?php endif; ?>
			<?php woocommerce_login_form( array( 'redirect' => wc_get_page_permalink( 'myaccount' ) ) ); ?>
			<p class="kuka-account-panel__register"><?php esc_html_e( 'Henüz hesabınız yok mu?', 'kuka-island' ); ?> <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) . '#customer_login' ); ?>"><?php esc_html_e( 'Hesap oluşturun', 'kuka-island' ); ?></a></p>
		<?php endif; ?>
	</div>
	<?php
}
