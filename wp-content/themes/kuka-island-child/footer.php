<?php
/** Site footer. */
defined( 'ABSPATH' ) || exit;
$site_content = kuka_island_content();
$help_menu = kuka_island_menu_lines( $site_content['footer']['help_links'] ?? ( $site_content['navigation']['help'] ?? '' ) );
$legal_links = kuka_island_menu_lines( $site_content['footer']['legal_links'] ?? '' );
$social_links = kuka_island_menu_lines( $site_content['brand']['social_links'] ?? '' );
$whatsapp_url = kuka_island_whatsapp_url();
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
	<div class="kuka-footer-bottom"><div><a class="kuka-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">KUKA ISLAND</a><p><?php echo esc_html( $site_content['footer']['brand_copy'] ?? '' ); ?></p><p><a href="mailto:<?php echo esc_attr( $site_content['brand']['email'] ?? '' ); ?>"><?php echo esc_html( $site_content['brand']['email'] ?? '' ); ?></a> · <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $site_content['brand']['phone'] ?? '' ) ); ?>"><?php echo esc_html( $site_content['brand']['phone'] ?? '' ); ?></a><?php if ( $whatsapp_url ) : ?> · <a href="<?php echo esc_url( $whatsapp_url ); ?>" rel="noopener">WhatsApp ↗</a><?php endif; ?></p></div><div><p><?php echo esc_html( $site_content['legal']['company_title'] ?? '' ); ?></p><p><?php echo esc_html( $site_content['legal']['address'] ?? '' ); ?></p></div><p>© <?php echo esc_html( gmdate( 'Y' ) ); ?> Kuka Island</p></div>
</footer>
<?php if ( $whatsapp_url && ! is_checkout() ) : ?>
<a class="kuka-whatsapp-fab" href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'WhatsApp destek hattı', 'kuka-island' ); ?>"><svg class="kuka-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M4 19l1.2-3.4a7.6 7.6 0 1 1 3.2 3.1L4 19Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M9 9.2c.2-.5.4-.5.7-.5.2 0 .5 0 .7.4l.5 1c.1.3 0 .5-.1.7-.3.3-.6.5-.3.9.4.5 1 1.1 1.5 1.4.4.2.6 0 .8-.2.2-.2.4-.3.7-.1l.9.6c.3.2.3.4.3.6-.1.6-.7 1.1-1.2 1.1-1.6 0-4.6-2.4-4.6-4.4 0-.4.1-.7.4-.9Z" fill="currentColor"/></svg><span class="kuka-whatsapp-fab__label"><?php esc_html_e( 'WhatsApp destek', 'kuka-island' ); ?></span></a>
<?php endif; ?>
<?php wp_footer(); ?>
</body></html>
