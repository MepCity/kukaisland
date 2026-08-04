<?php
/** Site footer. */
defined( 'ABSPATH' ) || exit;
$site_content = kuka_island_content();
$help_menu = kuka_island_menu_lines( $site_content['navigation']['help'] ?? '' );
?>
</main>
<footer class="kuka-footer">
	<div class="kuka-footer__brand"><a class="kuka-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">KUKA ISLAND</a><p><?php echo esc_html( $site_content['footer']['brand_copy'] ?? '' ); ?></p></div>
	<div><p class="kuka-footer__title"><?php esc_html_e( 'Yardım', 'kuka-island' ); ?></p><?php foreach ( $help_menu as $item ) : ?><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a><?php endforeach; ?></div>
	<div><p class="kuka-footer__title"><?php echo esc_html( $site_content['footer']['newsletter_title'] ?? '' ); ?></p><p><?php echo esc_html( $site_content['footer']['newsletter_copy'] ?? '' ); ?></p><form class="kuka-newsletter"><label for="kuka-newsletter-email"><?php esc_html_e( 'E-posta adresi', 'kuka-island' ); ?></label><div><input id="kuka-newsletter-email" type="email" autocomplete="email" required><button type="submit"><?php esc_html_e( 'Katıl', 'kuka-island' ); ?></button></div><label class="kuka-check"><input type="checkbox" required> <span><?php echo esc_html( $site_content['footer']['newsletter_consent'] ?? '' ); ?></span></label></form></div>
	<div class="kuka-footer__company"><p><?php echo esc_html( $site_content['footer']['company_name'] ?? '' ); ?></p><p><?php echo esc_html( $site_content['footer']['company_address'] ?? '' ); ?></p><p>© <?php echo esc_html( gmdate( 'Y' ) ); ?> Kuka Island</p></div>
</footer>
<?php wp_footer(); ?>
</body></html>
