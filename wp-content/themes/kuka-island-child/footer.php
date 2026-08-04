<?php
/** Site footer. */
defined( 'ABSPATH' ) || exit;
$site_content = kuka_island_content();
$help_menu = kuka_island_menu_lines( $site_content['navigation']['help'] ?? '' );
$legal_links = array(
	__( 'Gizlilik Politikası', 'kuka-island' ) => '/gizlilik-politikasi/',
	__( 'Çerez Politikası', 'kuka-island' ) => '/cerez-politikasi/',
	__( 'KVKK Aydınlatma Metni', 'kuka-island' ) => '/kvkk-aydinlatma-metni/',
	__( 'Mesafeli Satış Sözleşmesi', 'kuka-island' ) => '/mesafeli-satis-sozlesmesi/',
);
?>
</main>
<footer class="kuka-footer">
	<section class="kuka-newsletter" aria-labelledby="kuka-newsletter-title">
		<p class="kuka-eyebrow"><?php esc_html_e( 'Ada mektupları', 'kuka-island' ); ?></p>
		<div><h2 id="kuka-newsletter-title"><?php echo esc_html( $site_content['footer']['newsletter_title'] ?? '' ); ?></h2><p><?php echo esc_html( $site_content['footer']['newsletter_copy'] ?? '' ); ?></p>
		<form><label class="kuka-newsletter__label" for="kuka-newsletter-email"><?php esc_html_e( 'E-posta adresi', 'kuka-island' ); ?></label><div class="kuka-newsletter__field"><input id="kuka-newsletter-email" type="email" autocomplete="email" required><button type="submit"><?php esc_html_e( 'Katıl', 'kuka-island' ); ?> ↗</button></div><label class="kuka-newsletter__consent"><input type="checkbox" required> <span><?php echo esc_html( $site_content['footer']['newsletter_consent'] ?? '' ); ?></span></label></form></div>
	</section>
	<section class="kuka-footer-links">
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Kategoriler', 'kuka-island' ); ?></p><?php wp_nav_menu( array( 'theme_location' => 'footer_categories', 'container' => false, 'items_wrap' => '<ul>%3$s</ul>', 'fallback_cb' => false, 'depth' => 1 ) ); ?></div>
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Yardım', 'kuka-island' ); ?></p><ul><?php foreach ( $help_menu as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul></div>
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Yasal', 'kuka-island' ); ?></p><ul><?php foreach ( $legal_links as $label => $url ) : ?><li><a href="<?php echo esc_url( home_url( $url ) ); ?>"><?php echo esc_html( $label ); ?></a></li><?php endforeach; ?></ul></div>
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Sosyal', 'kuka-island' ); ?></p><ul><li><a href="<?php echo esc_url( $site_content['brand']['instagram_url'] ?? '#' ); ?>" rel="noopener">Instagram ↗</a></li><li><a href="<?php echo esc_url( $site_content['brand']['pinterest_url'] ?? '#' ); ?>" rel="noopener">Pinterest ↗</a></li></ul></div>
	</section>
	<div class="kuka-footer-bottom"><div><a class="kuka-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">KUKA ISLAND</a><p><?php echo esc_html( $site_content['footer']['brand_copy'] ?? '' ); ?></p></div><div><p><?php echo esc_html( $site_content['footer']['company_name'] ?? '' ); ?></p><p><?php echo esc_html( $site_content['footer']['company_address'] ?? '' ); ?></p></div><p>© <?php echo esc_html( gmdate( 'Y' ) ); ?> Kuka Island</p></div>
</footer>
<?php wp_footer(); ?>
</body></html>
