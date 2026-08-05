<?php
/** Site footer. */
defined( 'ABSPATH' ) || exit;
$site_content = kuka_island_content();
$help_menu = kuka_island_menu_lines( $site_content['footer']['help_links'] ?? ( $site_content['navigation']['help'] ?? '' ) );
$legal_links = kuka_island_menu_lines( $site_content['footer']['legal_links'] ?? '' );
$social_links = kuka_island_menu_lines( $site_content['brand']['social_links'] ?? '' );
?>
</main>
<footer class="kuka-footer">
	<?php if ( ! empty( $site_content['footer']['newsletter_enabled'] ) ) : ?><section class="kuka-newsletter" aria-labelledby="kuka-newsletter-title">
		<p class="kuka-eyebrow"><?php echo esc_html( $site_content['footer']['newsletter_eyebrow'] ?? __( 'Ada mektupları', 'kuka-island' ) ); ?></p>
		<div><h2 id="kuka-newsletter-title"><?php echo esc_html( $site_content['footer']['newsletter_title'] ?? '' ); ?></h2><p><?php echo esc_html( $site_content['footer']['newsletter_copy'] ?? '' ); ?></p><p class="kuka-newsletter__disabled"><?php esc_html_e( 'E-posta kaydı yakında açılacak.', 'kuka-island' ); ?></p></div>
	</section><?php endif; ?>
	<section class="kuka-footer-links">
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Kategoriler', 'kuka-island' ); ?></p><?php wp_nav_menu( array( 'theme_location' => 'footer_categories', 'container' => false, 'items_wrap' => '<ul>%3$s</ul>', 'fallback_cb' => false, 'depth' => 1 ) ); ?></div>
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Yardım', 'kuka-island' ); ?></p><ul><?php foreach ( $help_menu as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul></div>
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Yasal', 'kuka-island' ); ?></p><ul><?php foreach ( $legal_links as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul></div>
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Sosyal', 'kuka-island' ); ?></p><ul><?php foreach ( $social_links as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>" rel="noopener"><?php echo esc_html( $item['label'] ); ?> ↗</a></li><?php endforeach; ?></ul></div>
	</section>
	<div class="kuka-footer-bottom"><div><a class="kuka-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">KUKA ISLAND</a><p><?php echo esc_html( $site_content['footer']['brand_copy'] ?? '' ); ?></p><p><a href="mailto:<?php echo esc_attr( $site_content['brand']['email'] ?? '' ); ?>"><?php echo esc_html( $site_content['brand']['email'] ?? '' ); ?></a> · <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $site_content['brand']['phone'] ?? '' ) ); ?>"><?php echo esc_html( $site_content['brand']['phone'] ?? '' ); ?></a><?php if ( ! empty( $site_content['brand']['whatsapp_url'] ) ) : ?> · <a href="<?php echo esc_url( $site_content['brand']['whatsapp_url'] ); ?>" rel="noopener">WhatsApp ↗</a><?php endif; ?></p></div><div><p><?php echo esc_html( $site_content['footer']['company_name'] ?? '' ); ?></p><p><?php echo esc_html( $site_content['footer']['company_address'] ?? '' ); ?></p></div><p>© <?php echo esc_html( gmdate( 'Y' ) ); ?> Kuka Island</p></div>
</footer>
<?php wp_footer(); ?>
</body></html>
