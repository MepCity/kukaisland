<?php
/** Site header. */
defined( 'ABSPATH' ) || exit;
$site_content = kuka_island_content();
$announcements = $site_content['announcement']['items'] ?? array();
$main_menu = kuka_island_menu_lines( $site_content['navigation']['main'] ?? '' );
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
<header class="kuka-header">
	<a class="kuka-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Kuka Island ana sayfa', 'kuka-island' ); ?>">
		<?php if ( ! empty( $site_content['brand']['logo_id'] ) ) : ?>
			<?php echo wp_get_attachment_image( $site_content['brand']['logo_id'], 'medium', false, array( 'alt' => get_bloginfo( 'name' ) ) ); ?>
		<?php else : ?>KUKA ISLAND<?php endif; ?>
	</a>
	<nav class="kuka-desktop-nav" aria-label="<?php esc_attr_e( 'Ana menü', 'kuka-island' ); ?>">
		<?php foreach ( $main_menu as $item ) : ?><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a><?php endforeach; ?>
	</nav>
	<div class="kuka-header-actions">
		<a href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Sepet', 'kuka-island' ); ?> <span class="kuka-cart-count"><?php echo esc_html( WC()->cart ? WC()->cart->get_cart_contents_count() : 0 ); ?></span></a>
		<button class="kuka-menu-toggle" type="button" aria-controls="kuka-mobile-menu" aria-expanded="false"><?php esc_html_e( 'Menü', 'kuka-island' ); ?></button>
	</div>
</header>
<div class="kuka-menu-backdrop" hidden></div>
<aside id="kuka-mobile-menu" class="kuka-mobile-menu" aria-hidden="true" inert>
	<button class="kuka-menu-close" type="button"><?php esc_html_e( 'Menüyü kapat', 'kuka-island' ); ?></button>
	<nav aria-label="<?php esc_attr_e( 'Mobil menü', 'kuka-island' ); ?>">
		<?php foreach ( $main_menu as $item ) : ?><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a><?php endforeach; ?>
	</nav>
</aside>
<main id="main" class="kuka-main">
