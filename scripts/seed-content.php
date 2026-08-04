<?php
/** Idempotent storefront and information page seed. */
defined( 'WP_CLI' ) || exit( 1 );

function kuka_content_page( string $slug, string $title, string $content ): int {
	$existing = get_page_by_path( $slug );
	$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content );
	if ( $existing ) { $data['ID'] = $existing->ID; }
	$id = $existing ? wp_update_post( $data, true ) : wp_insert_post( $data, true );
	if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
	return (int) $id;
}

$legal_warning = '<p class="kuka-legal-warning"><strong>Hukuki taslak uyarısı:</strong> Bu sayfa yer tutucu içeriktir; yayına alınmadan önce hukuk danışmanı ve şirket yetkilisi tarafından onaylanmalıdır.</p>';
$pages = array(
	'hakkimizda' => array( 'Hakkımızda', '<h2>Adadan doğan günlük parçalar</h2><p>Kuka Island, hareket özgürlüğünü ve yalın formu odağına alan bağımsız bir giyim markasıdır. Bu pilot metin panel ve tema bağlantısını doğrulamak amacıyla hazırlanmıştır.</p><h2>Yaklaşımımız</h2><p>Az sayıda, birbiriyle uyumlu ve uzun süre kullanılabilecek parçalar tasarlıyoruz.</p>' ),
	'iletisim' => array( 'İletişim', '<h2>Bize ulaşın</h2><p>E-posta: hello@kukaisland.com<br>Telefon: +90 850 000 00 00<br>Destek saatleri: Hafta içi 09.00–18.00</p><p>[Fiziksel adres müşteri onayı sonrası eklenecek]</p>' ),
	'sik-sorulan-sorular' => array( 'Sık Sorulan Sorular', '<h2>Siparişimi nasıl takip ederim?</h2><p>Sipariş gönderildiğinde takip bağlantısı e-posta ile paylaşılır.</p><h2>Beden konusunda yardım alabilir miyim?</h2><p>Beden rehberini inceleyebilir veya destek ekibine ulaşabilirsiniz.</p><h2>Değişim talebi nasıl açılır?</h2><p>Sipariş numaranızla destek adresine yazabilirsiniz.</p>' ),
	'kargo-teslimat' => array( 'Kargo ve Teslimat', '<h2>Gönderim</h2><p>1.500 TL üzeri siparişlerde ücretsiz kargo hedeflenmektedir. Kesin süre, ücret ve taşıyıcı bilgileri müşteri onayı sonrasında güncellenecektir.</p>' ),
	'iade-degisim' => array( 'İade ve Değişim', $legal_warning . '<h2>Değişim süreci</h2><p>Değişim talebinizi teslimattan sonraki 14 gün içinde iletebilirsiniz. İade koşulları ve istisnaları hukuk onayı sonrasında kesinleşecektir.</p>' ),
	'gizlilik-politikasi' => array( 'Gizlilik Politikası', $legal_warning . '<h2>Veri sorumlusu</h2><p>[Şirket unvanı, MERSİS ve iletişim bilgileri eklenecek]</p><h2>İşlenen veriler ve amaçlar</h2><p>[KVKK envanteri ve saklama süreleri hukuk onayı sonrası eklenecek]</p>' ),
	'cerez-politikasi' => array( 'Çerez Politikası', $legal_warning . '<h2>Kullanılan çerezler</h2><p>[Zorunlu, analitik ve pazarlama çerezlerinin sağlayıcı/süre tablosu eklenecek]</p>' ),
	'kvkk-aydinlatma-metni' => array( 'KVKK Aydınlatma Metni', $legal_warning . '<h2>Aydınlatma</h2><p>[6698 sayılı Kanun kapsamındaki veri kategorileri, hukuki sebepler, aktarım tarafları ve başvuru yöntemi eklenecek]</p>' ),
	'kullanim-kosullari' => array( 'Kullanım Koşulları', $legal_warning . '<h2>Kapsam</h2><p>[Site kullanım şartları, fikri mülkiyet hükümleri ve uyuşmazlık çözümü eklenecek]</p>' ),
	'on-bilgilendirme-formu' => array( 'Ön Bilgilendirme Formu', $legal_warning . '<h2>Satıcı</h2><p>[Ticaret unvanı, açık adres, telefon, MERSİS ve vergi bilgileri]</p><h2>Ürün ve ödeme</h2><p>Siparişe özgü ürün, adet, toplam bedel, kargo ve ödeme bilgileri checkout aşamasında gösterilir.</p><h2>Cayma hakkı</h2><p>[Cayma süresi, yöntem, istisnalar ve iade masrafları hukuk onayı sonrası eklenecek]</p>' ),
	'mesafeli-satis-sozlesmesi' => array( 'Mesafeli Satış Sözleşmesi', $legal_warning . '<h2>Taraflar</h2><p>ALICI: [Sipariş sırasında girilen bilgiler]</p><p>SATICI: [Ticaret unvanı ve açık adres]</p><h2>Sözleşmenin konusu</h2><p>[Siparişe özgü ürünler, toplam bedel, teslimat ve cayma hükümleri dinamik sözleşme üretimi onaylandıktan sonra eklenecek]</p>' ),
	'acik-riza-metni' => array( 'Açık Rıza Metni', $legal_warning . '<h2>İsteğe bağlı işlemler</h2><p>[Açık rıza gerektiren veri işleme faaliyetleri ve rızanın geri alınma yöntemi eklenecek]</p>' ),
	'ticari-elektronik-ileti-onayi' => array( 'Ticari Elektronik İleti Onayı', $legal_warning . '<h2>İletişim izni</h2><p>[İleti kanalları, kapsam, ret yöntemi ve İYS bilgileri müşteri/hukuk onayı sonrası eklenecek]</p>' ),
	'beden-rehberi' => array( 'Beden Rehberi', '<p>Ölçü bandını vücudunuza paralel tutun. İki beden arasındaysanız ürünün kalıp notunu kontrol edin.</p><h2>EU beden tablosu (cm)</h2><table><thead><tr><th>Beden</th><th>Göğüs</th><th>Bel</th><th>Basen</th></tr></thead><tbody><tr><td>34</td><td>80–83</td><td>62–65</td><td>88–91</td></tr><tr><td>36</td><td>84–87</td><td>66–69</td><td>92–95</td></tr><tr><td>38</td><td>88–91</td><td>70–73</td><td>96–99</td></tr><tr><td>40</td><td>92–95</td><td>74–77</td><td>100–103</td></tr><tr><td>42</td><td>96–99</td><td>78–81</td><td>104–107</td></tr></tbody></table><h2>Harfli beden tablosu</h2><table><thead><tr><th>Harf</th><th>EU karşılığı</th><th>Göğüs</th></tr></thead><tbody><tr><td>XS</td><td>34</td><td>80–83</td></tr><tr><td>S</td><td>36</td><td>84–87</td></tr><tr><td>M</td><td>38</td><td>88–91</td></tr><tr><td>L</td><td>40</td><td>92–95</td></tr><tr><td>XL</td><td>42</td><td>96–99</td></tr></tbody></table><h2>Nasıl ölçülür?</h2><table><tbody><tr><th>Göğüs</th><td>En geniş noktadan yatay ölçün.</td></tr><tr><th>Bel</th><td>Doğal bel çizgisinden ölçün.</td></tr><tr><th>Basen</th><td>Kalçanın en geniş noktasından ölçün.</td></tr></tbody></table>' ),
);
foreach ( $pages as $slug => $page ) { kuka_content_page( $slug, $page[0], $page[1] ); }
$home_id = kuka_content_page( 'ana-sayfa', 'Ana Sayfa', '' );
update_option( 'show_on_front', 'page' ); update_option( 'page_on_front', $home_id );

$attachment_id = static function ( string $source ): int {
	$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'meta_key' => '_kuka_seed_source', 'meta_value' => $source, 'fields' => 'ids' ) );
	return $ids ? (int) $ids[0] : 0;
};
if ( class_exists( 'Kuka_Island_Core_Site_Appearance' ) ) {
	$current = Kuka_Island_Core_Site_Appearance::get();
	$current['hero']['desktop_image_id'] = $attachment_id( 'hero-aegean-black.jpg' );
	$current['hero']['mobile_image_id'] = $attachment_id( 'hero-aegean-black-mobile.jpg' );
	$current['home']['editorial_image_id'] = $attachment_id( 'cobalt-set.jpg' );
	update_option( Kuka_Island_Core_Site_Appearance::OPTION_NAME, $current, false );
}
flush_rewrite_rules( false );
WP_CLI::success( sprintf( '%d içerik sayfası ve Site Appearance görselleri hazır.', count( $pages ) + 1 ) );
