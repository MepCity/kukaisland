<?php
/** Site header. */
defined( 'ABSPATH' ) || exit;
$site_content = kuka_island_content();
$announcements = $site_content['announcement']['items'] ?? array();
$overlay_header = is_front_page();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="kuka-skip-link" href="#main"><?php esc_html_e( 'İçeriğe geç', 'kuka-island' ); ?></a>
<?php if ( ! empty( $site_content['announcement']['enabled'] ) && $announcements ) : ?>
	<div class="kuka-announcement" aria-label="<?php esc_attr_e( 'Duyurular', 'kuka-island' ); ?>">
		<?php foreach ( array_slice( $announcements, 0, 3 ) as $announcement ) : ?><span><?php echo esc_html( $announcement ); ?></span><?php endforeach; ?>
	</div>
<?php endif; ?>
<header class="kuka-header<?php echo $overlay_header ? ' kuka-header--overlay' : ''; ?>" data-site-header>
	<button class="kuka-icon-button kuka-menu-toggle" type="button" data-panel-trigger="kuka-mobile-menu" aria-label="<?php esc_attr_e( 'Menüyü aç', 'kuka-island' ); ?>" aria-controls="kuka-mobile-menu" aria-expanded="false"><?php echo kuka_island_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	<nav class="kuka-desktop-nav" aria-label="<?php esc_attr_e( 'Ana menü', 'kuka-island' ); ?>">
		<?php wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'items_wrap' => '<ul>%3$s</ul>', 'fallback_cb' => false, 'depth' => 2 ) ); ?>
	</nav>
	<a class="kuka-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Kuka Island ana sayfa', 'kuka-island' ); ?>">
		<?php if ( ! empty( $site_content['brand']['logo_id'] ) ) : ?><?php echo wp_get_attachment_image( $site_content['brand']['logo_id'], 'medium', false, array( 'alt' => get_bloginfo( 'name' ) ) ); ?><?php else : ?>KUKA ISLAND<?php endif; ?>
	</a>
	<div class="kuka-header-actions">
		<a class="kuka-icon-button" href="<?php echo esc_url( home_url( '/?s=&post_type=product' ) ); ?>" aria-label="<?php esc_attr_e( 'Ürün ara', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'search' ); // phpcs:ignore ?></a>
		<a class="kuka-icon-button" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" aria-label="<?php esc_attr_e( 'Hesabım', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'account' ); // phpcs:ignore ?></a>
		<a class="kuka-icon-button kuka-bag-button" href="<?php echo esc_url( wc_get_cart_url() ); ?>" aria-label="<?php esc_attr_e( 'Sepete git', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'bag' ); // phpcs:ignore ?><span class="kuka-cart-count" aria-live="polite"><?php echo esc_html( WC()->cart ? WC()->cart->get_cart_contents_count() : 0 ); ?></span></a>
	</div>
</header>
<div class="kuka-panel-overlay" data-panel-overlay hidden></div>
<aside id="kuka-mobile-menu" class="kuka-mobile-menu" role="dialog" aria-modal="true" aria-labelledby="kuka-mobile-menu-title" aria-hidden="true" inert>
	<div class="kuka-panel-head"><span id="kuka-mobile-menu-title"><?php esc_html_e( 'Menü', 'kuka-island' ); ?></span><button class="kuka-icon-button" type="button" data-panel-close aria-label="<?php esc_attr_e( 'Menüyü kapat', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'close' ); // phpcs:ignore ?></button></div>
	<nav aria-label="<?php esc_attr_e( 'Mobil menü', 'kuka-island' ); ?>"><?php wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'items_wrap' => '<ul>%3$s</ul>', 'fallback_cb' => false, 'depth' => 2 ) ); ?></nav>
</aside>
<main id="main" class="kuka-main">
