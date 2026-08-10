<?php
/** Site footer. */
defined( 'ABSPATH' ) || exit;
$site_content = kuka_island_content();
$help_menu = kuka_island_menu_lines( $site_content['footer']['help_links'] ?? ( $site_content['navigation']['help'] ?? '' ) );
$legal_links = kuka_island_menu_lines( $site_content['footer']['legal_links'] ?? '' );
$social_links = kuka_island_menu_lines( $site_content['brand']['social_links'] ?? '' );
$whatsapp_url = kuka_island_whatsapp_url();
// Marka kilidinin iki yanındaki palmiye, header'daki amblemin aynısıdır;
// sağdaki CSS ile aynalanır. Boşsa marka adı tek başına durur.
$emblem_html = kuka_island_emblem_markup();
?>
</main>
<footer class="kuka-footer">
	<?php if ( ! empty( $site_content['footer']['newsletter_enabled'] ) ) : ?><section class="kuka-newsletter" aria-labelledby="kuka-newsletter-title">
		<p class="kuka-eyebrow"><?php echo esc_html( $site_content['footer']['newsletter_eyebrow'] ?? __( 'Ada mektupları', 'kuka-island' ) ); ?></p>
		<div><h2 id="kuka-newsletter-title"><?php echo esc_html( $site_content['footer']['newsletter_title'] ?? '' ); ?></h2><p><?php echo esc_html( $site_content['footer']['newsletter_copy'] ?? '' ); ?></p><?php echo Kuka_Island_Core_Newsletter::form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	</section><?php endif; ?>
	<?php /* Kategoriler sütunu kaldırıldı; kategoriler header menüsünde zaten var. */ ?>
	<section class="kuka-footer-links">
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Yardım', 'kuka-island' ); ?></p><ul><?php foreach ( $help_menu as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul></div>
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Yasal', 'kuka-island' ); ?></p><ul><?php foreach ( $legal_links as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul></div>
		<div><p class="kuka-footer-title"><?php esc_html_e( 'Sosyal', 'kuka-island' ); ?></p><ul><?php foreach ( $social_links as $item ) : ?><li><a href="<?php echo esc_url( kuka_island_content_url( $item['url'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['label'] ); ?> <span class="kuka-text-arrow" aria-hidden="true">↗︎</span></a></li><?php endforeach; ?><?php if ( $whatsapp_url ) : ?><li><a href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener">WhatsApp <span class="kuka-text-arrow" aria-hidden="true">↗︎</span></a></li><?php endif; ?></ul></div>
	</section>
	<div class="kuka-footer-bottom">
		<a class="kuka-logo kuka-footer-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Kuka Island ana sayfa', 'kuka-island' ); ?>"><span class="kuka-logo__emblem-wrap"><?php echo $emblem_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="kuka-logo__text">KUKA ISLAND</span><span class="kuka-logo__emblem-wrap kuka-logo__emblem-wrap--mirror" aria-hidden="true"><?php echo $emblem_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></a>
		<?php /* Yıl koda gömülmez; site saat dilimine göre wp_date ile üretilir. */ ?>
		<p class="kuka-footer-copyright">&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> Kuka Island</p>
	</div>
</footer>
<?php if ( $whatsapp_url && ! is_checkout() ) : ?>
<a class="kuka-whatsapp-fab" href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'WhatsApp destek hattı', 'kuka-island' ); ?>"><svg class="kuka-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M4 19l1.2-3.4a7.6 7.6 0 1 1 3.2 3.1L4 19Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M9 9.2c.2-.5.4-.5.7-.5.2 0 .5 0 .7.4l.5 1c.1.3 0 .5-.1.7-.3.3-.6.5-.3.9.4.5 1 1.1 1.5 1.4.4.2.6 0 .8-.2.2-.2.4-.3.7-.1l.9.6c.3.2.3.4.3.6-.1.6-.7 1.1-1.2 1.1-1.6 0-4.6-2.4-4.6-4.4 0-.4.1-.7.4-.9Z" fill="currentColor"/></svg><span class="kuka-whatsapp-fab__label"><?php esc_html_e( 'WhatsApp destek', 'kuka-island' ); ?></span></a>
<?php endif; ?>
<?php wp_footer(); ?>
</body></html>
