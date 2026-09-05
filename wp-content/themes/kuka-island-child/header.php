<?php
/** Site header. */
defined( 'ABSPATH' ) || exit;
$site_content = kuka_island_content();
$announcements = $site_content['announcement']['items'] ?? array();
$main_menu = kuka_island_header_menu();
$overlay_header = is_front_page();
// Ödeme adımında çıkış yolu azaltılır: menü, arama ve panel tetikleyicileri
// yerine yalnız marka kilidi ve sepete dönüş kalır. Sipariş alındı sayfası
// akışın dışındadır; orada tam header geri gelir.
$checkout_flow = function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page();
$has_logo = ! empty( $site_content['brand']['logo_id'] );
$emblem_html = $has_logo ? '' : kuka_island_emblem_markup();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<script>document.documentElement.classList.add('has-js');</script>
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="kuka-skip-link" href="#main"><?php esc_html_e( 'İçeriğe geç', 'kuka-island' ); ?></a>
<?php if ( ! empty( $site_content['announcement']['enabled'] ) && $announcements ) : ?>
	<div class="kuka-announcement" aria-label="<?php esc_attr_e( 'Duyurular', 'kuka-island' ); ?>">
		<div class="kuka-announcement__lang"><?php
			$languages = kuka_island_languages();
			if ( count( $languages ) > 1 ) :
				$current_lang = 'en' === kuka_island_locale() ? $languages[1] : $languages[0];
				?>
					<details class="kuka-lang-switcher" data-lang-switcher>
					<summary class="kuka-lang-switcher__button" aria-haspopup="listbox" aria-expanded="false"><?php echo esc_html( $current_lang['label'] ); ?></summary>
						<ul class="kuka-lang-switcher__list" role="listbox">
							<?php foreach ( $languages as $lang ) : ?>
								<li role="option"<?php echo $lang['code'] === kuka_island_locale() ? ' aria-selected="true"' : ''; ?>><a href="<?php echo esc_url( $lang['url'] ); ?>" hreflang="<?php echo esc_attr( $lang['code'] ); ?>"><?php echo esc_html( $lang['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<span class="kuka-announcement__message"><?php
			$first_announcement = reset( $announcements );
			$first_index = array_key_first( $announcements );
			$announcement_url = $site_content['announcement']['link_urls'][ $first_index ] ?? '';
			if ( $announcement_url ) : ?>
				<a href="<?php echo esc_url( kuka_island_content_url( $announcement_url ) ); ?>"><?php echo esc_html( $first_announcement ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $first_announcement ); ?>
			<?php endif; ?>
		</span>
	</div>
<?php endif; ?>
<header class="kuka-header<?php echo $overlay_header ? ' kuka-header--overlay' : ''; ?><?php echo $checkout_flow ? ' kuka-header--checkout' : ''; ?>" data-site-header>
	<?php if ( $checkout_flow ) : ?>
	<a class="kuka-header-back" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><span aria-hidden="true">←</span><?php esc_html_e( 'Sepete dön', 'kuka-island' ); ?></a>
	<?php else : ?>
	<button class="kuka-icon-button kuka-menu-toggle" type="button" data-panel-trigger="kuka-mobile-menu" aria-label="<?php esc_attr_e( 'Menüyü aç', 'kuka-island' ); ?>" aria-controls="kuka-mobile-menu" aria-expanded="false"><?php echo kuka_island_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	<nav class="kuka-desktop-nav" aria-label="<?php esc_attr_e( 'Ana menü', 'kuka-island' ); ?>">
		<ul><?php foreach ( $main_menu as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul>
	</nav>
	<?php endif; ?>
	<a class="kuka-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Kuka Island ana sayfa', 'kuka-island' ); ?>">
		<?php if ( $has_logo ) : ?><picture><?php if ( ! empty( $site_content['brand']['mobile_logo_id'] ) ) : ?><source media="(max-width: 47.5em)" srcset="<?php echo esc_url( wp_get_attachment_image_url( $site_content['brand']['mobile_logo_id'], 'medium' ) ); ?>"><?php endif; ?><?php echo wp_get_attachment_image( $site_content['brand']['logo_id'], 'medium', false, array( 'alt' => get_bloginfo( 'name' ) ) ); ?></picture><?php else : ?><span class="kuka-logo__emblem-wrap"><?php echo $emblem_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="kuka-logo__text">KUKA ISLAND</span><span class="kuka-logo__emblem-wrap kuka-logo__emblem-wrap--mirror" aria-hidden="true"><?php echo $emblem_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php endif; ?>
	</a>
	<?php if ( $checkout_flow ) : ?>
	<span class="kuka-header-actions" aria-hidden="true"></span>
	<?php else : ?>
	<div class="kuka-header-actions">
		<a class="kuka-icon-button kuka-search-button" href="<?php echo esc_url( home_url( '/?s=&post_type=product' ) ); ?>" data-panel-trigger="kuka-search-panel" aria-controls="kuka-search-panel" aria-expanded="false" aria-label="<?php esc_attr_e( 'Ürün ara', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'search' ); // phpcs:ignore ?></a>
		<?php /* Erişilebilir ad görünür sayacı da içersin diye aria-label yerine ekran okuyucu metni kullanılır: "Sepeti aç 0". */ ?>
		<a class="kuka-icon-button kuka-bag-button" href="<?php echo esc_url( wc_get_cart_url() ); ?>" data-panel-trigger="kuka-cart-panel" aria-controls="kuka-cart-panel" aria-expanded="false"><span class="kuka-sr-only"><?php esc_html_e( 'Sepeti aç', 'kuka-island' ); ?></span><?php echo kuka_island_icon( 'bag' ); // phpcs:ignore ?><?php echo kuka_island_cart_count_markup(); // phpcs:ignore ?></a>
	</div>
	<?php endif; ?>
</header>
<?php if ( ! $checkout_flow ) : ?>
<div class="kuka-panel-overlay kuka-panel-overlay--light" data-panel-overlay hidden></div>
<aside id="kuka-mobile-menu" class="kuka-mobile-menu" role="dialog" aria-modal="true" aria-labelledby="kuka-mobile-menu-title" aria-hidden="true" inert>
	<div class="kuka-panel-head"><span id="kuka-mobile-menu-title"><?php esc_html_e( 'Menü', 'kuka-island' ); ?></span><button class="kuka-icon-button" type="button" data-panel-close aria-label="<?php esc_attr_e( 'Menüyü kapat', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'close' ); // phpcs:ignore ?></button></div>
	<nav aria-label="<?php esc_attr_e( 'Mobil menü', 'kuka-island' ); ?>"><ul><?php foreach ( $main_menu as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul></nav>
</aside>
<div class="kuka-panel-overlay kuka-panel-overlay--light" data-panel-overlay hidden></div>
<?php /* JS kapalıyken tetikleyici bağlantı arama sonuçları sayfasına gider; panel yalnız zenginleştirmedir. */ ?>
<aside id="kuka-search-panel" class="kuka-side-panel kuka-search-panel" role="dialog" aria-modal="true" aria-labelledby="kuka-search-panel-title" aria-hidden="true" inert>
	<div class="kuka-panel-head"><span id="kuka-search-panel-title"><?php esc_html_e( 'Ara', 'kuka-island' ); ?></span><button class="kuka-icon-button" type="button" data-panel-close aria-label="<?php esc_attr_e( 'Aramayı kapat', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'close' ); // phpcs:ignore ?></button></div>
	<form class="kuka-search-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<label class="kuka-search-form__label" for="kuka-search-field"><?php esc_html_e( 'Ne arıyorsunuz?', 'kuka-island' ); ?></label>
		<input class="kuka-search-form__field" type="search" id="kuka-search-field" data-panel-autofocus name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php esc_attr_e( 'Ürün, renk veya kesim', 'kuka-island' ); ?>" autocomplete="off">
		<input type="hidden" name="post_type" value="product">
		<button class="kuka-button kuka-search-form__submit" type="submit"><?php esc_html_e( 'Ara', 'kuka-island' ); ?></button>
	</form>
	<?php $search_menu = kuka_island_header_menu(); ?>
	<?php if ( $search_menu ) : ?>
		<nav class="kuka-search-panel__suggestions" aria-label="<?php esc_attr_e( 'Hızlı bağlantılar', 'kuka-island' ); ?>">
			<p class="kuka-eyebrow"><?php esc_html_e( 'Sık aranan', 'kuka-island' ); ?></p>
			<ul><?php foreach ( array_slice( $search_menu, 0, 5 ) as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul>
		</nav>
	<?php endif; ?>
</aside>
<div class="kuka-panel-overlay kuka-panel-overlay--light" data-panel-overlay hidden></div>
<aside id="kuka-cart-panel" class="kuka-side-panel kuka-cart-panel" role="dialog" aria-modal="true" aria-labelledby="kuka-cart-panel-title" aria-hidden="true" inert>
	<div class="kuka-panel-head"><?php echo kuka_island_cart_title_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><button class="kuka-icon-button" type="button" data-panel-close aria-label="<?php esc_attr_e( 'Sepeti kapat', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'close' ); // phpcs:ignore ?></button></div>
	<?php kuka_island_cart_panel_content(); ?>
</aside>
<?php endif; ?>
<main id="main" class="kuka-main">
