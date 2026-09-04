<?php
/**
 * Kuka Island e-posta başlığı.
 *
 * NEDEN KOPYALANDI: WooCommerce'in kendi başlığı içeriği `<td width="600">`
 * ile SABİT 600 piksele kilitler. Bu bir HTML niteliğidir, filtrelenemez ve
 * CSS ile güvenilir biçimde geri alınamaz; 390 piksellik bir ekranda da yatay
 * taşma üretir. Ürün satırında 104 pikselik görsel, ad, varyasyon, adet ve
 * fiyatın yan yana durabilmesi için sözleşme 760-800 piksel aralığıdır.
 * Kopyalanan tek şey belge iskeletidir: renk, tipografi ve boşluk yine
 * `woocommerce_email_styles` kancasından gelir.
 *
 * Kaynak: WooCommerce templates/emails/email-header.php sürüm 10.7.0.
 * Yukarı akış sürümü `scripts/verify-email-templates.php` ile karşılaştırılır;
 * WooCommerce şablonu güncellerse ölçüm FAIL verir ve bu dosya elden geçirilir.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

$store_name    = $store_name ?? get_bloginfo( 'name', 'display' );
$email_heading = $email_heading ?? '';
$design_ready  = class_exists( 'Kuka_Island_Core_Email_Design' );

/*
 * WooCommerce bu şablona e-posta nesnesini GEÇİRMEZ; yalnız başlık metnini
 * verir (`WC_Emails::email_header( $email_heading )`). Hangi e-posta olduğunu
 * Core `woocommerce_email_header` eyleminden kenara yazar.
 */
$email        = $email ?? ( $design_ready ? Kuka_Island_Core_Email_Design::current_email() : null );
$kuka_logo    = $design_ready ? Kuka_Island_Core_Email_Design::logo_url() : '';
$kuka_eyebrow = $design_ready ? Kuka_Island_Core_Email_Design::eyebrow( $email ) : '';
$kuka_width    = $design_ready ? (int) Kuka_Island_Core_Email_Design::CONTENT_WIDTH : 780;

/** This filter is documented in WooCommerce templates/emails/email-header.php. */
$header_image_url = apply_filters( 'woocommerce_email_header_image_url', home_url() );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
		<meta content="width=device-width, initial-scale=1.0" name="viewport">
		<title><?php echo esc_html( $store_name ); ?></title>
	</head>
	<body <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0">
		<table width="100%" border="0" cellpadding="0" cellspacing="0" id="outer_wrapper" role="presentation">
			<tr>
				<td align="center" valign="top" class="kuka-outer-cell">
					<?php
					/*
					 * Outlook'un Word motoru `max-width` tanımaz. Hayalet tablo
					 * yalnız orada görünür ve içeriği aynı genişlikte tutar;
					 * diğer istemcilerde hiç yoktur, dolayısıyla mobil akış
					 * bozulmaz.
					 */
					?>
					<!--[if mso]><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="<?php echo esc_attr( (string) $kuka_width ); ?>" align="center"><tr><td><![endif]-->
					<div id="wrapper" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
						<table border="0" cellpadding="0" cellspacing="0" width="100%" id="inner_wrapper" role="presentation">
							<tr>
								<td align="center" valign="top">
									<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
										<tr>
											<td id="template_header_image" align="center">
												<?php
												if ( '' !== $kuka_logo ) {
													$kuka_logo_html = '<img src="' . esc_url( $kuka_logo ) . '" alt="' . esc_attr( $store_name ) . '" />';

													if ( $header_image_url ) {
														echo '<a href="' . esc_url( $header_image_url ) . '" style="display:inline-block;text-decoration:none;" target="_blank">' . wp_kses_post( $kuka_logo_html ) . '</a>';
													} else {
														echo wp_kses_post( $kuka_logo_html );
													}
												} else {
													/*
													 * Logo yoksa ya da adresi halka açık HTTPS değilse
													 * hayalî bir logo ÜRETİLMEZ; mağaza adı tipografik
													 * wordmark olarak yazılır.
													 */
													$kuka_wordmark = function_exists( 'mb_strtoupper' )
														? mb_strtoupper( $store_name, 'UTF-8' )
														: strtoupper( $store_name );

													if ( $header_image_url ) {
														echo '<p class="kuka-wordmark"><a href="' . esc_url( $header_image_url ) . '" target="_blank">' . esc_html( $kuka_wordmark ) . '</a></p>';
													} else {
														echo '<p class="kuka-wordmark">' . esc_html( $kuka_wordmark ) . '</p>';
													}
												}
												?>
											</td>
										</tr>
									</table>
									<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_container" role="presentation">
										<tr>
											<td align="center" valign="top">
												<!-- Header -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" role="presentation">
													<tr>
														<td id="header_wrapper">
															<?php if ( '' !== $kuka_eyebrow ) : ?>
																<p class="kuka-eyebrow"><?php echo esc_html( $kuka_eyebrow ); ?></p>
															<?php endif; ?>
															<h1><?php echo esc_html( $email_heading ); ?></h1>
														</td>
													</tr>
												</table>
												<!-- End Header -->
											</td>
										</tr>
										<tr>
											<td align="center" valign="top">
												<!-- Body -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_body" role="presentation">
													<tr>
														<td valign="top" id="body_content">
															<!-- Content -->
															<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
																<tr>
																	<td valign="top" id="body_content_inner_cell">
																		<div id="body_content_inner">
