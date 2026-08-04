<?php
/** Site header. */
defined( 'ABSPATH' ) || exit;
$site_content = kuka_island_content();
$announcements = $site_content['announcement']['items'] ?? array();
$main_menu = kuka_island_menu_lines( $site_content['navigation']['main'] ?? '' );
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
		<?php foreach ( array_slice( $announcements, 0, 3 ) as $index => $announcement ) : $announcement_url = $site_content['announcement']['link_urls'][ $index ] ?? ''; ?><span><?php echo esc_html( $announcement ); ?><?php if ( $announcement_url ) : ?> <a href="<?php echo esc_url( kuka_island_content_url( $announcement_url ) ); ?>"><?php echo esc_html( $site_content['announcement']['link_labels'][ $index ] ?? '' ); ?></a><?php endif; ?></span><?php endforeach; ?>
	</div>
<?php endif; ?>
<header class="kuka-header<?php echo $overlay_header ? ' kuka-header--overlay' : ''; ?>" data-site-header>
	<button class="kuka-icon-button kuka-menu-toggle" type="button" data-panel-trigger="kuka-mobile-menu" aria-label="<?php esc_attr_e( 'Menüyü aç', 'kuka-island' ); ?>" aria-controls="kuka-mobile-menu" aria-expanded="false"><?php echo kuka_island_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	<nav class="kuka-desktop-nav" aria-label="<?php esc_attr_e( 'Ana menü', 'kuka-island' ); ?>">
		<ul><?php foreach ( $main_menu as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul>
	</nav>
	<a class="kuka-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Kuka Island ana sayfa', 'kuka-island' ); ?>">
		<?php if ( ! empty( $site_content['brand']['logo_id'] ) ) : ?><picture><?php if ( ! empty( $site_content['brand']['mobile_logo_id'] ) ) : ?><source media="(max-width: 47.5em)" srcset="<?php echo esc_url( wp_get_attachment_image_url( $site_content['brand']['mobile_logo_id'], 'medium' ) ); ?>"><?php endif; ?><?php echo wp_get_attachment_image( $site_content['brand']['logo_id'], 'medium', false, array( 'alt' => get_bloginfo( 'name' ) ) ); ?></picture><?php else : ?>KUKA ISLAND<?php endif; ?>
	</a>
	<div class="kuka-header-actions">
		<a class="kuka-icon-button" href="<?php echo esc_url( home_url( '/?s=&post_type=product' ) ); ?>" aria-label="<?php esc_attr_e( 'Ürün ara', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'search' ); // phpcs:ignore ?></a>
		<a class="kuka-icon-button kuka-account-button" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" data-panel-trigger="kuka-account-panel" aria-label="<?php esc_attr_e( 'Hesabı aç', 'kuka-island' ); ?>" aria-controls="kuka-account-panel" aria-expanded="false"><?php echo kuka_island_icon( 'account' ); // phpcs:ignore ?></a>
		<a class="kuka-icon-button kuka-bag-button" href="<?php echo esc_url( wc_get_cart_url() ); ?>" data-panel-trigger="kuka-cart-panel" aria-label="<?php esc_attr_e( 'Sepeti aç', 'kuka-island' ); ?>" aria-controls="kuka-cart-panel" aria-expanded="false"><?php echo kuka_island_icon( 'bag' ); // phpcs:ignore ?><?php echo kuka_island_cart_count_markup(); // phpcs:ignore ?></a>
	</div>
</header>
<div class="kuka-panel-overlay kuka-panel-overlay--light" data-panel-overlay hidden></div>
<aside id="kuka-mobile-menu" class="kuka-mobile-menu" role="dialog" aria-modal="true" aria-labelledby="kuka-mobile-menu-title" aria-hidden="true" inert>
	<div class="kuka-panel-head"><span id="kuka-mobile-menu-title"><?php esc_html_e( 'Menü', 'kuka-island' ); ?></span><button class="kuka-icon-button" type="button" data-panel-close aria-label="<?php esc_attr_e( 'Menüyü kapat', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'close' ); // phpcs:ignore ?></button></div>
	<nav aria-label="<?php esc_attr_e( 'Mobil menü', 'kuka-island' ); ?>"><ul><?php foreach ( $main_menu as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul></nav>
</aside>
<div class="kuka-panel-overlay kuka-panel-overlay--light" data-panel-overlay hidden></div>
<aside id="kuka-account-panel" class="kuka-side-panel kuka-account-panel" role="dialog" aria-modal="true" aria-labelledby="kuka-account-panel-title" aria-hidden="true" inert <?php echo kuka_island_account_panel_requires_attention() ? 'data-panel-open-on-load' : ''; ?>>
	<div class="kuka-panel-head"><span id="kuka-account-panel-title"><?php esc_html_e( 'Hesap', 'kuka-island' ); ?></span><button class="kuka-icon-button" type="button" data-panel-close aria-label="<?php esc_attr_e( 'Hesap panelini kapat', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'close' ); // phpcs:ignore ?></button></div>
	<?php kuka_island_account_panel_content(); ?>
</aside>
<div class="kuka-panel-overlay kuka-panel-overlay--light" data-panel-overlay hidden></div>
<aside id="kuka-cart-panel" class="kuka-side-panel kuka-cart-panel" role="dialog" aria-modal="true" aria-labelledby="kuka-cart-panel-title" aria-hidden="true" inert>
	<div class="kuka-panel-head"><?php echo kuka_island_cart_title_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><button class="kuka-icon-button" type="button" data-panel-close aria-label="<?php esc_attr_e( 'Sepeti kapat', 'kuka-island' ); ?>"><?php echo kuka_island_icon( 'close' ); // phpcs:ignore ?></button></div>
	<?php kuka_island_cart_panel_content(); ?>
</aside>
<main id="main" class="kuka-main">
