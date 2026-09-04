<?php
/**
 * Kuka Island e-posta altbilgisi.
 *
 * NEDEN KOPYALANDI: Bu dosya `email-header.php` içinde açılan etiketleri
 * kapatır. Başlık iskeleti değiştiği için altbilgi de aynı iskeleti kapatmak
 * zorundadır; ikisi tek bir belgenin iki yarısıdır ve ayrı ayrı geçerli
 * değildir. Altbilgi metni yine `woocommerce_email_footer_text` seçeneğinden
 * ve filtresinden gelir.
 *
 * Kaynak: WooCommerce templates/emails/email-footer.php sürüm 10.4.0.
 *
 * @package KukaIslandChild
 */

defined( 'ABSPATH' ) || exit;

$email = $email ?? null;

?>
																		</div>
																	</td>
																</tr>
															</table>
															<!-- End Content -->
														</td>
													</tr>
												</table>
												<!-- End Body -->
											</td>
										</tr>
									</table>
								</td>
							</tr>
							<tr>
								<td align="center" valign="top">
									<!-- Footer -->
									<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_footer" role="presentation">
										<tr>
											<td valign="top">
												<table border="0" cellpadding="10" cellspacing="0" width="100%" role="presentation">
													<tr>
														<td colspan="2" valign="middle" id="credit">
															<?php
															$email_footer_text = get_option( 'woocommerce_email_footer_text' );

															/** This filter is documented in WooCommerce templates/emails/email-styles.php. */
															if ( apply_filters( 'woocommerce_is_email_preview', false ) ) {
																$text_transient    = get_transient( 'woocommerce_email_footer_text' );
																$email_footer_text = false !== $text_transient ? $text_transient : $email_footer_text;
															}

															echo wp_kses_post(
																wpautop(
																	wptexturize(
																		/** This filter is documented in WooCommerce templates/emails/email-footer.php. */
																		apply_filters( 'woocommerce_email_footer_text', $email_footer_text, $email )
																	)
																)
															);
															?>
														</td>
													</tr>
												</table>
											</td>
										</tr>
									</table>
									<!-- End Footer -->
								</td>
							</tr>
						</table>
					</div>
					<!--[if mso]></td></tr></table><![endif]-->
				</td>
			</tr>
		</table>
	</body>
</html>
