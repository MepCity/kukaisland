<?php
/**
 * "Çok yakında" ekranı.
 *
 * WooCommerce, site görünürlüğü "Çok yakında" iken temanın `coming-soon`
 * şablonunu `get_query_template()` ile arar (ComingSoonRequestHandler).
 * Bu dosya varken kendi blok deseni yerine bu ekran gösterilir.
 *
 * Sayfa header/footer kullanmaz: lansman öncesi ziyaretçiye menü, sepet ve
 * arama sunmanın anlamı yok. Şifresi olanlar alttaki bağlantıdan giriş yapar
 * ve gerçek siteyi görür.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'kuka_island_coming_soon_assets', 100 );

$site_content = function_exists( 'kuka_island_content' ) ? kuka_island_content() : array();
$brand_name   = $site_content['legal']['brand_name'] ?? get_bloginfo( 'name' );
$owner        = $site_content['legal']['company_title'] ?? '';
$logo_id      = absint( $site_content['brand']['logo_id'] ?? 0 );
$emblem_id    = absint( $site_content['brand']['emblem_id'] ?? 0 );
$media_uri    = get_stylesheet_directory_uri() . '/assets/media/';
$media_path   = get_stylesheet_directory() . '/assets/media/';
$has_video    = file_exists( $media_path . 'coming-soon-desktop.mp4' )
	&& file_exists( $media_path . 'coming-soon-mobile.mp4' )
	&& file_exists( $media_path . 'coming-soon-desktop-poster.jpg' )
	&& file_exists( $media_path . 'coming-soon-mobile-poster.jpg' );

// Marka kilidi yüklüyse tek görsel yeter; değilse amblem + yazı kurulur.
$emblem_html = '';
if ( ! $logo_id ) {
	if ( $emblem_id ) {
		$emblem_html = wp_get_attachment_image( $emblem_id, 'full', false, array( 'class' => 'kuka-splash__emblem', 'alt' => '', 'aria-hidden' => 'true' ) );
	} else {
		// <img src="…svg"> izole render bağlamında currentColor devralamaz;
		// satır içi gömmek tek çalışan yol. Statik tema varlığı, kullanıcı girdisi değil.
		$palmiye_path = get_stylesheet_directory() . '/assets/img/palmiye.svg';
		if ( file_exists( $palmiye_path ) ) {
			$emblem_html = preg_replace( '/<svg /', '<svg class="kuka-splash__emblem" ', file_get_contents( $palmiye_path ), 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}
}

// Marka adının son kelimesi alt satıra iner: "KUKA" üstte, "ISLAND" altında.
$words         = preg_split( '/\s+/u', trim( (string) $brand_name ), -1, PREG_SPLIT_NO_EMPTY ) ?: array( $brand_name );
$lockup_line   = count( $words ) > 1 ? (string) array_pop( $words ) : '';
$wordmark_line = implode( ' ', $words );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'kuka-splash-body' ); ?>>
<?php wp_body_open(); ?>
<main class="kuka-splash">
	<?php if ( $has_video ) : ?>
		<div class="kuka-splash__media" aria-hidden="true">
			<picture>
				<source media="(max-width: 47.5em)" srcset="<?php echo esc_url( $media_uri . 'coming-soon-mobile-poster.jpg' ); ?>">
				<img src="<?php echo esc_url( $media_uri . 'coming-soon-desktop-poster.jpg' ); ?>" alt="" width="1920" height="1080" fetchpriority="high">
			</picture>
			<video class="kuka-splash__video" autoplay loop muted playsinline preload="metadata" disablepictureinpicture tabindex="-1">
				<source media="(prefers-reduced-motion: no-preference) and (max-width: 47.5em)" src="<?php echo esc_url( $media_uri . 'coming-soon-mobile.mp4' ); ?>" type="video/mp4">
				<source media="(prefers-reduced-motion: no-preference) and (min-width: 47.501em)" src="<?php echo esc_url( $media_uri . 'coming-soon-desktop.mp4' ); ?>" type="video/mp4">
			</video>
		</div>
	<?php endif; ?>
	<section class="kuka-splash__mark" aria-labelledby="kuka-splash-status">
		<?php if ( $logo_id ) : ?>
			<?php echo wp_get_attachment_image( $logo_id, 'full', false, array( 'class' => 'kuka-splash__logo', 'alt' => esc_attr( $brand_name ) ) ); ?>
		<?php else : ?>
			<?php echo $emblem_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p class="kuka-splash__wordmark">
				<?php echo esc_html( $wordmark_line ); ?>
				<?php if ( '' !== $lockup_line ) : ?>
					<span class="kuka-splash__lockup"><?php echo esc_html( $lockup_line ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<div class="kuka-splash__divider" aria-hidden="true"></div>
		<h1 class="kuka-splash__status" id="kuka-splash-status"><?php esc_html_e( 'Çok yakında', 'kuka-island' ); ?></h1>
		<?php if ( '' !== $owner ) : ?>
			<p class="kuka-splash__signature"><?php echo esc_html( sprintf( /* translators: %s: mağaza sahibinin adı. */ __( 'by %s', 'kuka-island' ), $owner ) ); ?></p>
		<?php endif; ?>
	</section>

	<div class="kuka-splash__foot">
		<a class="kuka-splash__login" href="<?php echo esc_url( wp_login_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Oturum aç', 'kuka-island' ); ?></a>
		<p class="kuka-splash__year"><?php echo esc_html( wp_date( 'Y' ) ); ?></p>
	</div>
</main>
<?php wp_footer(); ?>
</body>
</html>
