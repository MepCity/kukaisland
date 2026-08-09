<?php
/**
 * Idempotent storefront and information page seed.
 *
 * Sayfa gövdeleri parça parça dizilir. Ölçüldü: `wp eval-file` 4096 baytı geçen
 * tek bir kaynak satırında dosyayı sessizce yarıda bırakıyor — hata da basmıyor,
 * çıkış kodu da 0 kalıyor. Uzun hukuk metinleri bu yüzden paragraf paragraf
 * yazılır; metin böylece okunabilir ve diff'lenebilir de kalır.
 */
defined( 'WP_CLI' ) || exit( 1 );

function kuka_content_page( string $slug, string $title, string $content, string $status = 'publish' ): int {
	$existing = get_page_by_path( $slug );
	$data = array( 'post_type' => 'page', 'post_status' => $status, 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content );
	if ( $existing ) { $data['ID'] = $existing->ID; }
	$id = $existing ? wp_update_post( $data, true ) : wp_insert_post( $data, true );
	if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
	return (int) $id;
}

/** @param array<int, string> $blocks */
function kuka_content_html( array $blocks ): string {
	return implode( '', $blocks );
}

// Sözleşme sayfaları müşterinin teslim ettiği sekiz PDF'ten birebir alınır.
// Metin yeniden yazılmaz; yalnız PDF'teki köşeli parantezli yer tutucular panel
// değerlerine bağlanır. Satıcı bloğu her sözleşmede tek kaynaktan basılır.
$seller_block = '[kuka_company_details]';

// Müşterinin KUKA_ISLAND_Manifesto.pdf dosyasındaki marka hikâyesi. Kübra'nın
// birinci ağızdan metni; kısaltılmadan ve yeniden yazılmadan aktarıldı. PDF'in
// sabit sayfa genişliğinden doğan cümle ortası satır sarmaları birleştirildi;
// bilinçli kısa satırlar ve boşluklar korundu.
$brand_story = kuka_content_html(
	array(
		'<div class="kuka-brand-story">',
		'<p class="kuka-brand-story__opening">[kuka_manifesto_line_2]</p>',
		'<div class="kuka-brand-story__source">',
		'<p class="kuka-brand-story__lede">KUKA ISLAND<br>Hayatta bazen sıfırdan başlamak gerekir.</p>',
		'<p>Benim için KUKA ISLAND tam olarak böyle başladı.</p>',
		'<p>Yeni bir sayfa açarken, sadece bir marka kurmak istemedim. Bana iyi hissettiren her şeyi tek bir çatı altında toplamak istedim.</p>',
		'<p class="kuka-brand-story__list">Denizi…<br>Yazı…<br>Özgürlüğü…<br>Ve kadınların kendini en güzel hissettiği anları…</p>',
		'<p>İşte KUKA ISLAND böyle doğdu.</p>',
		'<p>Her koleksiyon, sadece bir sezon için değil; yıllar sonra bile giydiğinde sana aynı hissi yaşatsın diye hazırlanıyor.</p>',
		'<p>Bu yolculuk daha yeni başlıyor.</p>',
		'<p>İyi ki buradasın.</p>',
		'<p>Ve bu hikâyenin ilk sayfalarında bize eşlik ediyorsun.</p>',
		'<footer class="kuka-brand-story__sign"><span>Love,</span><strong>KÜBRA</strong></footer>',
		'</div>',
		'</div>',
	)
);

$contact_page = kuka_content_html(
	array(
		'<p>Sipariş, ürün ve beden soruları için aşağıdaki güncel kanalları kullanabilirsiniz.</p>',
		'[kuka_contact_details]',
		'<h2>İletişim formu</h2>',
		'<p class="kuka-service-disabled"><strong>Form şu anda devre dışıdır.</strong> Mesaj gönderim altyapısı bağlanana kadar e-posta, telefon, WhatsApp veya Instagram kanalını kullanın.</p>',
	)
);

$faq_page = kuka_content_html(
	array(
		'<h2>Beden ve kalıp</h2>',
		'<details class="kuka-faq"><summary>Bedenimi nasıl seçerim?</summary><div class="kuka-faq__panel"><p>Göğüs, göğüs altı, bel ve kalça ölçünüzü santimetre olarak alın; Beden Rehberi tablosu ile ürünün kalıp notunu birlikte değerlendirin.</p></div></details>',
		'<details class="kuka-faq"><summary>İki beden arasında kalırsam ne yapmalıyım?</summary><div class="kuka-faq__panel"><p>Üründeki kalıp önerisi önceliklidir. Standart pilot kalıplarda daha rahat kullanım için büyük beden değerlendirilebilir.</p></div></details>',
		'<details class="kuka-faq"><summary>Üst ve altı farklı beden alabilir miyim?</summary><div class="kuka-faq__panel"><p>Evet. Bikini üstleri ve altları ayrı ürünlerdir; beden ve renklerini bağımsız seçebilirsiniz.</p></div></details>',
		'<details class="kuka-faq"><summary>Beden rehberi nerede?</summary><div class="kuka-faq__panel"><p>Her ürünün beden bağlantısından veya <a href="/beden-rehberi/">Beden Rehberi</a> sayfasından açılır.</p></div></details>',
		'<h2>Ürün ve kumaş</h2>',
		'<details class="kuka-faq"><summary>Kumaş içeriğini nerede görürüm?</summary><div class="kuka-faq__panel"><p>Her ürün sayfasındaki Malzeme bölümünde ürüne özgü oranlar bulunur.</p></div></details>',
		'<details class="kuka-faq"><summary>Bakım nasıl yapılır?</summary><div class="kuka-faq__panel"><p>Ürünün bakım notunu izleyin; genel olarak soğuk suda elde yıkayın, sıkmayın ve gölgede düz kurutun.</p></div></details>',
		'<details class="kuka-faq"><summary>Klor ve güneş kremi ürünü etkiler mi?</summary><div class="kuka-faq__panel"><p>Klorlu veya tuzlu sudan sonra durulayın. Güneş kremi ve yağla uzun temas renk ve elastikiyeti etkileyebilir.</p></div></details>',
		'<details class="kuka-faq"><summary>Astar veya dolgu var mı?</summary><div class="kuka-faq__panel"><p>Astar ve çıkarılabilir/sabit dolgu bilgisi ürün açıklamasında belirtilir; ürünler arasında farklılık gösterebilir.</p></div></details>',
		'<h2>Kargo</h2>',
		'<p>Standart kargo ücreti [kuka_value name="flat_shipping_fee"], ücretsiz kargo eşiği [kuka_value name="free_shipping_threshold"] değeridir. Taşıyıcı [kuka_value name="shipping_carrier"], tahmini süre [kuka_value name="delivery_time"] olarak panelden güncellenir. Kargoya verildiğinde takip bilgisi paylaşılır.</p>',
		'<h2>Cayma hakkı ve iade</h2>',
		'<p>Cayma bildiriminizi teslimattan sonra [kuka_value name="cayma_hakki_gun"] gün içinde, sipariş numaranızla [kuka_value name="email"] adresine veya site üzerindeki iade kanalına iletin. Telefon, ispat kolaylığı nedeniyle cayma bildirimi kanalı değildir. İade gönderim masrafı için güncel karar: [kuka_value name="return_shipping_responsibility"].</p>',
		'<p>Farklı bir beden istiyorsanız cayma hakkınızı kullanıp yeni sipariş oluşturabilirsiniz.</p>',
		'<p>[kuka_hygiene_policy]</p>',
		'<p>[kuka_hygiene_try_on]</p>',
		'<h2>Ödeme</h2>',
		'<p>Checkout ekranında gösterilen kart yöntemleri kullanılır. Taksit seçeneği banka ve iyzico koşullarına göre ödeme sırasında görünür. Kart verisi Kuka Island sunucusunda saklanmaz; ödeme kuruluşunun güvenli altyapısında işlenir.</p>',
		'<h2>Sipariş</h2>',
		'<p>Sipariş durumunu sipariş numarası ve e-posta ile <a href="/siparis-takibi/">Sipariş Takibi</a> sayfasından sorgulayabilirsiniz. Hazırlık başlamadan önce iptal talebi destek ekibine iletilebilir.</p>',
	)
);

$shipping_page = kuka_content_html(
	array(
		'<h2>Hazırlık ve teslimat</h2>',
		'<p>Siparişler ödeme onayından sonra hazırlanır. Tahmini teslimat süresi [kuka_value name="delivery_time"], taşıyıcı [kuka_value name="shipping_carrier"] olarak panelde tutulur. Hafta sonu, resmî tatil, yoğun dönem ve teslimat bölgesi süreyi etkileyebilir.</p>',
		'<h2>Ücret ve ücretsiz kargo</h2>',
		'<p>Standart gönderim ücreti [kuka_value name="flat_shipping_fee"], ücretsiz gönderim eşiği [kuka_value name="free_shipping_threshold"] değeridir. Checkout ekranındaki sipariş özeti uygulanacak son tutarı gösterir.</p>',
		'<h2>Takip</h2>',
		'<p>Kargo takip numarası yönetici tarafından siparişe girildiğinde e-posta ile paylaşılır ve Sipariş Takibi ekranında kullanılabilir.</p>',
		'<h2>Yanlış adres</h2>',
		'<p>Kargoya verilmeden önce destek ekibine ulaşın. Gönderim başladıktan sonra adres değişikliği taşıyıcının kabulüne bağlıdır; teslim edilemeyen paketin yeniden gönderim bedeli doğabilir.</p>',
		'<h2>Hasarlı teslimat</h2>',
		'<p>Pakette belirgin hasar varsa mümkünse teslimat görevlisi yanında kayıt tutun; paket ve ürün fotoğraflarıyla sipariş numarasını gecikmeden destek ekibine iletin.</p>',
	)
);

// 04_Cayma_Hakki_ve_Iade_Sozlesmesi_KUKA_ISLAND.pdf — URL sabit kalır.
$withdrawal_page = kuka_content_html(
	array(
		'<h2>Satıcı bilgileri</h2>',
		$seller_block,
		'<h2>1 – CAYMA HAKKI</h2>',
		'<p>Tüketici, malın tesliminden itibaren on dört gün içinde gerekçe göstermeksizin cayma hakkını kullanabilir. Cayma hakkı teslimden önce de kullanılabilir.</p>',
		'<h2>2 – CAYMA BİLDİRİMİ</h2>',
		'<p>Cayma bildirimi e-posta: [kuka_value name="email"] veya KUKA ISLAND internet sitesindeki iade/cayma kanalı üzerinden yapılabilir. Açık bir beyan yeterlidir.</p>',
		'<h2>3 – ÜRÜNÜN İADESİ</h2>',
		'<p>Tüketici, cayma bildiriminden itibaren on gün içinde ürünü satıcıya geri göndermelidir. Ürün mümkün olduğunca kullanılmamış, yıkanmamış, hasarsız ve varsa koruyucu unsurları açılmamış olarak gönderilmelidir.</p>',
		'<h2>4 – BİKİNİLERDE HİJYEN</h2>',
		'<p>Bikini ürünlerinde varsa koruyucu hijyen bandı, mühür, bant veya benzeri unsurun açılması, ürünün sağlık ve hijyen açısından iadesi uygun olmayan mallara ilişkin yasal istisna kapsamında değerlendirilmesine neden olabilir. Ürünün somut niteliği ve koruyucu unsurun durumu esas alınır. Bu hüküm, ayıplı maldan doğan hakları ortadan kaldırmaz.</p>',
		'<p>[kuka_hygiene_policy]</p>',
		'<p>[kuka_hygiene_try_on]</p>',
		'<h2>5 – BEDEN DEĞİŞİMİ</h2>',
		'<p>Beden değişimi stok durumuna bağlı olarak yapılabilir. Değişim için ürünün değişime uygun olması ve varsa hijyen koruyucularının açılmamış olması aranabilir. Tüketicinin kanuni cayma ve ayıplı mal hakları saklıdır.</p>',
		'<h2>6 – AYIPLI ÜRÜN</h2>',
		'<p>Ayıplı ürünlerde 6502 sayılı Kanun kapsamındaki seçimlik haklar saklıdır. Ayıplı mal nedeniyle tüketici kanuni haklarını kullanırken ek bir kısıtlama getirilemez.</p>',
		'<h2>7 – İADE KARGO</h2>',
		'<p>İade için öngörülen taşıyıcı ve masraf bilgisi ön bilgilendirme formunda açıkça gösterilir. Satıcının bu bilgileri hiç vermemesi veya belirtilen taşıyıcının tüketicinin bulunduğu yerde şubesinin olmaması halinde mevzuattaki satıcı yükümlülükleri uygulanır.</p>',
		'<h2>8 – BEDEL İADESİ</h2>',
		'<p>Cayma bildiriminin geçerli olması halinde satıcı, mevzuatta öngörülen on dört günlük süre içinde teslim masrafları dahil tahsil edilen ödemeleri, satın alma sırasında kullanılan ödeme aracına uygun ve tüketiciye ek masraf yüklemeden iade eder.</p>',
		'<h2>9 – İADE ADRESİ</h2>',
		'<p>[kuka_value name="brand_name"]<br>[kuka_value name="address_full"]<br>Telefon: [kuka_value name="telephone"]<br>E-posta: [kuka_value name="email"]</p>',
		'<h2>10 – YASAL HAKLAR</h2>',
		'<p>Bu metin, tüketicinin 6502 sayılı Kanun ve ilgili mevzuattan doğan emredici haklarını sınırlamaz.</p>',
	)
);

// 06_Gizlilik_Politikasi_KUKA_ISLAND.pdf
$privacy_page = kuka_content_html(
	array(
		'<h2>Veri sorumlusu bilgileri</h2>',
		$seller_block,
		'<h2>1. KAPSAM</h2>',
		'<p>Bu Gizlilik Politikası, KUKA ISLAND internet sitesini ziyaret eden ve/veya site üzerinden alışveriş yapan kişilerin bilgilerinin gizliliğinin korunmasına ilişkin genel esasları açıklar.</p>',
		'<h2>2. TOPLANAN BİLGİLER</h2>',
		'<p>Sipariş, teslimat, iletişim, iade ve müşteri hizmetleri süreçlerinde kullanıcı tarafından sağlanan bilgiler ile sitenin çalışması için gerekli teknik bilgiler toplanabilir.</p>',
		'<h2>3. KULLANIM</h2>',
		'<p>Bilgiler siparişlerin tamamlanması, müşteri desteği, güvenlik, muhasebe, yasal yükümlülükler ve mevzuata uygun pazarlama faaliyetleri için kullanılabilir.</p>',
		'<h2>4. GÜVENLİK</h2>',
		'<p>KUKA ISLAND, kişisel verilerin yetkisiz erişim, kayıp, kötüye kullanım ve hukuka aykırı değişikliklere karşı korunması için makul teknik ve idari tedbirleri uygular. İnternet üzerinden yapılan hiçbir veri aktarımının mutlak güvenliği garanti edilemez.</p>',
		'<h2>5. ÜÇÜNCÜ TARAFLAR</h2>',
		'<p>Siparişin yerine getirilmesi için kargo, ödeme ve teknik hizmet sağlayıcılarıyla gerekli bilgiler paylaşılabilir. Paylaşımlar amaçla sınırlı tutulur.</p>',
		'<h2>6. ÇOCUKLAR</h2>',
		'<p>Site, çocuklara yönelik bir satış hizmeti olarak tasarlanmamıştır. Yasal temsilcinin bilgisi ve onayı olmaksızın çocuklardan kişisel veri toplanması amaçlanmamaktadır.</p>',
		'<h2>7. GÜNCELLEMELER</h2>',
		'<p>Bu politika mevzuat veya hizmetlerdeki değişikliklere bağlı olarak güncellenebilir. Güncel metin internet sitesinde yayımlanır.</p>',
	)
);

// 07_Cerez_Politikasi_KUKA_ISLAND.pdf
$cookie_page = kuka_content_html(
	array(
		'<h2>Veri sorumlusu bilgileri</h2>',
		$seller_block,
		'<h2>1. ÇEREZ NEDİR?</h2>',
		'<p>Çerezler, internet sitesinin kullanıcının cihazında belirli bilgileri saklamasını veya erişmesini sağlayan küçük metin dosyalarıdır.</p>',
		'<h2>2. ÇEREZ TÜRLERİ</h2>',
		'<p>Zorunlu çerezler sitenin çalışması ve güvenliği için kullanılabilir. Tercihe bağlı analiz, performans, işlevsel veya reklam/pazarlama çerezleri ise gerekli olduğu ölçüde ve mevzuata uygun şekilde kullanılır.</p>',
		'<h2>3. AMAÇLAR</h2>',
		'<p>Çerezler oturum yönetimi, sepet işlemleri, site güvenliği, tercihlerin hatırlanması, performans ölçümü ve gerekli hukuki şartlar sağlandığında pazarlama faaliyetleri için kullanılabilir.</p>',
		'<h2>4. YÖNETİM</h2>',
		'<p>Kullanıcı, tarayıcı ayarları ve sitede sunulan çerez tercih paneli üzerinden tercihlerini yönetebilir. Zorunlu çerezlerin devre dışı bırakılması sitenin bazı işlevlerinin çalışmamasına yol açabilir.</p>',
		'<h2>5. ÜÇÜNCÜ TARAF ÇEREZLERİ</h2>',
		'<p>Ödeme, analiz, sosyal medya veya reklam hizmetlerinde üçüncü taraf teknolojileri kullanılabilir. Bu teknolojilerin kullanımı, gerekli hukuki şartlar ve ilgili hizmet sağlayıcıların politikaları çerçevesinde yürütülür.</p>',
		'<h2>6. İLETİŞİM</h2>',
		'<p>Çerezler hakkında sorularınız için: [kuka_value name="email"]</p>',
	)
);

// 05_KVKK_Aydinlatma_Metni_KUKA_ISLAND.pdf
$kvkk_page = kuka_content_html(
	array(
		'<h2>1. VERİ SORUMLUSU</h2>',
		$seller_block,
		'<h2>2. AMAÇ</h2>',
		'<p>Bu Aydınlatma Metni, KUKA ISLAND internet sitesi ve satış faaliyetleri kapsamında kişisel verilerin 6698 sayılı Kişisel Verilerin Korunması Kanunu (“KVKK”) uyarınca işlenmesine ilişkin olarak ilgili kişilerin bilgilendirilmesi amacıyla hazırlanmıştır.</p>',
		'<h2>3. İŞLENEBİLECEK KİŞİSEL VERİLER</h2>',
		'<p>Kimlik ve iletişim bilgileri; sipariş ve teslimat bilgileri; müşteri işlem bilgileri; işlem güvenliği kayıtları; ödeme işlemine ilişkin gerekli bilgiler; talep, şikâyet ve iletişim kayıtları ile mevzuatın izin verdiği ölçüde internet sitesi kullanımına ilişkin teknik veriler işlenebilir. Kart bilgilerinin tamamı KUKA ISLAND tarafından tutulmaz; ödeme kuruluşunun kendi güvenlik ve saklama politikaları uygulanır.</p>',
		'<h2>4. İŞLEME AMAÇLARI</h2>',
		'<p>Siparişlerin alınması ve yerine getirilmesi, ödeme ve teslimat süreçlerinin yürütülmesi, müşteri taleplerinin cevaplanması, iade işlemleri, muhasebe ve finans süreçleri, yasal yükümlülüklerin yerine getirilmesi, bilgi güvenliği ve gerektiğinde uyuşmazlıkların yönetilmesi amaçlarıyla veri işlenebilir.</p>',
		'<h2>5. HUKUKİ SEBEPLER</h2>',
		'<p>Kişisel veriler; sözleşmenin kurulması veya ifası, kanunlarda açıkça öngörülmesi, veri sorumlusunun hukuki yükümlülüğünü yerine getirmesi, hakkın tesisi/kullanılması/korunması ve gerekli şartlarda açık rıza gibi KVKK\'da düzenlenen hukuki sebeplere dayanılarak işlenebilir.</p>',
		'<h2>6. AKTARIM</h2>',
		'<p>Kişisel veriler, siparişin yerine getirilmesi için kargo şirketleri, ödeme kuruluşları, bilişim hizmet sağlayıcıları, muhasebe/finans hizmeti sağlayıcıları, yetkili kamu kurumları ve kanunen yetkili kişilerle, amaçla sınırlı ve mevzuata uygun şekilde paylaşılabilir.</p>',
		'<h2>7. TOPLAMA YÖNTEMİ</h2>',
		'<p>Veriler; internet sitesi, sipariş formları, e-posta, telefon, ödeme ve teslimat süreçleri ile teknik sistemler üzerinden elektronik veya gerektiğinde fiziksel yöntemlerle toplanabilir.</p>',
		'<h2>8. İLGİLİ KİŞİNİN HAKLARI</h2>',
		'<p>KVKK\'nın 11. maddesi kapsamında ilgili kişi; kişisel verilerinin işlenip işlenmediğini öğrenme, işlenmişse bilgi talep etme, amacını ve amaca uygun kullanılıp kullanılmadığını öğrenme, aktarıldığı üçüncü kişileri bilme, eksik veya yanlış işlenmişse düzeltilmesini isteme, şartları oluştuğunda silinmesini veya yok edilmesini isteme ve kanundaki diğer haklarını kullanabilir.</p>',
		'<h2>9. BAŞVURU</h2>',
		'<p>KVKK kapsamındaki taleplerinizi yazılı olarak veya mevzuatta öngörülen diğer yöntemlerle Veri Sorumlusuna iletebilirsiniz. Başvuru e-posta: [kuka_value name="email"]. Başvurular KVKK ve ilgili ikincil mevzuatta öngörülen sürelerde sonuçlandırılır.</p>',
	)
);

// 03_Uyelik_Eticaret_Sitesi_Kullanim_Sozlesmesi_KUKA_ISLAND.pdf
$terms_page = kuka_content_html(
	array(
		'<h2>1 – TARAFLAR</h2>',
		$seller_block,
		'<h2>2 – KONU</h2>',
		'<p>Bu sözleşme, KUKA ISLAND internet sitesine üyelik ve site kullanım şartlarını düzenler. Ürün satışlarına ilişkin tüketici sözleşmelerinde ayrıca Mesafeli Satış Sözleşmesi ve Ön Bilgilendirme Formu uygulanır.</p>',
		'<h2>3 – ÜYELİK</h2>',
		'<p>Kullanıcı, üyelik sırasında verdiği bilgilerin doğru ve güncel olduğunu kabul eder. Hesap bilgilerinin gizliliğinden kullanıcı sorumludur.</p>',
		'<h2>4 – SİPARİŞ VE ÖDEME</h2>',
		'<p>Üyelik hesabı oluşturulması tek başına satın alma yükümlülüğü doğurmaz. Satın alma, kullanıcının sipariş oluşturması ve ödeme işlemini tamamlamasıyla gerçekleşir.</p>',
		'<h2>5 – SİTENİN KULLANIMI</h2>',
		'<p>Kullanıcı siteyi hukuka aykırı amaçlarla kullanamaz; sistemin güvenliğini bozacak, başkalarının hesaplarına izinsiz erişecek veya kötü amaçlı yazılım yayacak faaliyetlerde bulunamaz.</p>',
		'<h2>6 – FİKRİ MÜLKİYET</h2>',
		'<p>KUKA ISLAND adı, logosu, fotoğrafları, ürün görselleri, metinleri, tasarımları, grafik ve diğer içerikler üzerindeki haklar KUKA ISLAND\'a veya ilgili hak sahibine aittir. İzinsiz kopyalama ve ticari kullanım yasaktır.</p>',
		'<h2>7 – KİŞİSEL VERİLER VE ELEKTRONİK İLETİ</h2>',
		'<p>Kişisel veriler KVKK Aydınlatma Metni kapsamında işlenir. Ticari elektronik iletiler bakımından ilgili mevzuat ve gerekli onay süreçleri uygulanır.</p>',
		'<h2>8 – ÜYELİĞİN SONLANDIRILMASI</h2>',
		'<p>Kullanıcı üyeliğini sonlandırabilir. KUKA ISLAND, hukuka aykırı veya güvenliği tehdit eden kullanım halinde hesabı geçici olarak askıya alabilir veya kapatabilir. Bu durum tüketicinin tamamlanmış siparişlerden doğan haklarını ortadan kaldırmaz.</p>',
		'<h2>9 – TEKNİK AKSAKLIKLAR</h2>',
		'<p>Bakım, teknik arıza, internet altyapısı veya KUKA ISLAND\'ın makul kontrolü dışındaki sebeplerle oluşan geçici kesintilerde ilgili mevzuat hükümleri saklıdır.</p>',
		'<h2>10 – DEĞİŞİKLİKLER</h2>',
		'<p>Site kullanım koşulları mevzuat ve teknik/ticari gereklilikler doğrultusunda güncellenebilir. Tamamlanmış siparişler bakımından sipariş tarihinde yürürlükte olan tüketici sözleşmesi hükümleri uygulanır.</p>',
		'<h2>11 – UYUŞMAZLIKLAR VE YÜRÜRLÜK</h2>',
		'<p>Tüketicinin emredici hakları saklıdır. Kullanıcının üyelik işlemini tamamlamasıyla sözleşme yürürlüğe girer.</p>',
	)
);

// 02_On_Bilgilendirme_Formu_KUKA_ISLAND.pdf — §2 sepetteki gerçek kalemlerle dolar.
$preinfo_page = kuka_content_html(
	array(
		'<h2>1 – SATICI BİLGİLERİ</h2>',
		$seller_block,
		'<h2 id="urun-bilgileri">2 – ÜRÜN BİLGİLERİ</h2>',
		'<p>Sipariş öncesinde ürünün temel özellikleri, satış fiyatı, varsa indirimleri, KDV dahil toplam bedeli ve teslimat/kargo bedeli ürün sayfası ve sipariş özeti üzerinden tüketiciye gösterilir.</p>',
		'[kuka_preinfo_products]',
		'<h2>3 – ÖDEME VE SİPARİŞ</h2>',
		'<p>Tüketici, siparişini onaylamadan önce toplam ödeme yükümlülüğünü açıkça görür. Ödeme [kuka_payment_methods] ile yapılır. Siparişin onaylanması ödeme yükümlülüğü doğurur.</p>',
		'<h2>4 – TESLİMAT</h2>',
		'<p>Teslimat süresi ürün sayfasında veya sipariş öncesi ekranda belirtilir. Ayrı bir süre taahhüt edilmemişse yasal azami teslim süresi otuz gündür.</p>',
		'<h2>5 – CAYMA HAKKI</h2>',
		'<p>Tüketici, malın tesliminden itibaren on dört gün içinde gerekçe göstermeksizin cayma hakkını kullanabilir. Cayma hakkı teslimden önce de kullanılabilir. Cayma bildirimi açık bir beyanla e-posta veya internet sitesi üzerinden yapılabilir. Telefonla yapılan bildirim, ispat kolaylığı ve mevzuat gereği cayma bildirimi kanalı olarak kullanılmamalıdır.</p>',
		'<h2>6 – CAYMA HAKKININ İSTİSNALARI</h2>',
		'<p>Mevzuattaki istisnalar uygulanır. Özellikle tüketicinin özel isteklerine göre hazırlanan mallar ve koruyucu unsurları açılmış olması kaydıyla sağlık ve hijyen açısından iadesi uygun olmayan mallar cayma hakkı dışında kalabilir. Bikini ürünlerinde bu değerlendirme ürünün somut niteliğine ve koruyucu hijyen unsurlarına göre yapılır.</p>',
		'<h2>7 – İADE VE İADE KARGO</h2>',
		'<p>Cayma bildiriminin ardından tüketici ürünü on gün içinde geri göndermelidir. İade için öngörülen taşıyıcı: [kuka_value name="shipping_carrier"]. İade masrafı: [kuka_value name="return_shipping_responsibility"]. Masrafın karşılanması ve farklı taşıyıcı kullanılması halinde uygulanacak kurallar Mesafeli Sözleşmeler Yönetmeliği\'ne göre belirlenir.</p>',
		'<h2>8 – BEDEL İADESİ</h2>',
		'<p>Satıcı, cayma bildiriminin kendisine ulaşmasından itibaren mevzuatta öngörülen on dört günlük süre içinde, varsa teslim masrafları dahil tahsil edilen tüm ödemeleri tüketicinin satın alırken kullandığı ödeme aracına uygun ve tek seferde iade eder.</p>',
		'<h2>9 – ŞİKAYET VE UYUŞMAZLIK</h2>',
		'<p>Tüketici, yürürlükteki parasal sınırlar çerçevesinde Tüketici Hakem Heyetine veya Tüketici Mahkemesine başvurabilir.</p>',
		'<h2>10 – ONAY</h2>',
		'<p>Tüketici, siparişini tamamlamadan önce işbu formdaki bilgileri edindiğini ve sözleşme kurulmadan önce bilgilendirildiğini kabul eder.</p>',
	)
);

// 01_Mesafeli_Satis_Sozlesmesi_KUKA_ISLAND.pdf
$distance_page = kuka_content_html(
	array(
		'<h2>MADDE 1 – TARAFLAR</h2>',
		$seller_block,
		'<h2>MADDE 2 – KONU</h2>',
		'<p>İşbu sözleşme, tüketicinin KUKA ISLAND internet sitesi üzerinden elektronik ortamda sipariş verdiği malın satışı ve teslimi ile tarafların hak ve yükümlülüklerini düzenler. Sözleşme 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği hükümlerine tabidir.</p>',
		'<h2>MADDE 3 – ÜRÜN VE SİPARİŞ BİLGİLERİ</h2>',
		'<p>Siparişe konu ürünün adı, modeli, rengi, bedeni, adedi, birim fiyatı, indirim tutarı, KDV dahil toplam bedeli ve varsa teslimat/kargo bedeli sipariş özeti üzerinde gösterilir. Tüketici siparişi onaylamadan önce bu bilgileri inceleyebilir.</p>',
		'<h2>MADDE 4 – SÖZLEŞMENİN KURULMASI</h2>',
		'<p>Tüketicinin siparişi elektronik ortamda onaylaması ve ödeme işleminin tamamlanmasıyla sözleşme kurulur. Siparişin alındığına ilişkin teyit, tüketiciye kalıcı veri saklayıcısı aracılığıyla iletilir.</p>',
		'<h2>MADDE 5 – FİYAT VE ÖDEME</h2>',
		'<p>Ürün bedeli, sipariş anında internet sitesinde gösterilen KDV dahil fiyattır. Ödeme, internet sitesinde sunulan ödeme yöntemlerinden biriyle yapılır. Siparişin onaylanması tüketici açısından ödeme yükümlülüğü doğurur.</p>',
		'<h2>MADDE 6 – TESLİMAT</h2>',
		'<p>Ürün, sipariş sırasında bildirilen teslimat adresine gönderilir. Tüketiciye ayrıca bir teslim süresi taahhüt edilmemişse mal en geç otuz gün içinde teslim edilir. Malın tüketiciye teslimine kadar oluşan kayıp ve hasarlardan satıcı sorumludur; tüketicinin satıcının belirlediği taşıyıcı dışında kendi seçtiği taşıyıcıyı kullanmayı talep ettiği haller saklıdır.</p>',
		'<h2>MADDE 7 – CAYMA HAKKI</h2>',
		'<p>Tüketici, malın kendisine veya belirlediği üçüncü kişiye tesliminden itibaren on dört gün içinde herhangi bir gerekçe göstermeksizin ve cezai şart ödemeksizin cayma hakkını kullanabilir. Cayma hakkı teslimden önce de kullanılabilir. Bildirim e-posta veya internet sitesi gibi kalıcı veri saklayıcısı üzerinden açık bir beyanla yapılabilir.</p>',
		'<h2>MADDE 8 – CAYMA HAKKININ İSTİSNALARI</h2>',
		'<p>Mevzuatta düzenlenen istisnalar saklıdır. Özellikle tüketicinin istekleri veya kişisel ihtiyaçları doğrultusunda hazırlanan mallar ile ambalaj, bant, mühür veya paket gibi koruyucu unsurları açılmış olması kaydıyla iadesi sağlık ve hijyen açısından uygun olmayan mallarda cayma hakkı kullanılamaz. Bikini ürünlerinde, ürünün somut niteliği ve koruyucu hijyen unsurlarının açılıp açılmadığı mevzuata göre değerlendirilir.</p>',
		'<h2>MADDE 9 – ÜRÜNÜN İADESİ</h2>',
		'<p>Tüketici, cayma bildirimini yönelttiği tarihten itibaren on gün içinde ürünü satıcıya veya yetkilendirdiği kişiye geri göndermelidir. Ürün, cayma hakkı kapsamında mümkün olduğunca kullanılmamış, yıkanmamış, hasarsız ve varsa koruyucu unsurları açılmamış şekilde gönderilmelidir. Tüketicinin ürünü yalnızca gerekli inceleme ölçüsünde kullanmasından doğan değişiklikler mevzuat çerçevesinde değerlendirilir.</p>',
		'<h2>MADDE 10 – İADE KARGO</h2>',
		'<p>İade için satıcı tarafından belirlenen taşıyıcı ve iade masrafı ile kimin karşılayacağı ön bilgilendirme formunda açıkça belirtilir. Ön bilgilendirmede bu bilgiler bulunmadığında veya belirtilen taşıyıcının tüketicinin bulunduğu yerde şubesi olmadığında mevzuatta satıcıya yüklenen masraf ve yükümlülükler uygulanır.</p>',
		'<h2>MADDE 11 – BEDEL İADESİ</h2>',
		'<p>Cayma hakkının usulüne uygun kullanılması halinde satıcı, mevzuatta öngörülen süre içinde teslim masrafları dahil tahsil edilen ödemeleri, tüketicinin satın alırken kullandığı ödeme aracına uygun ve tüketiciye ek masraf yüklemeksizin iade eder. Kanundaki istisnalar saklıdır.</p>',
		'<h2>MADDE 12 – AYIPLI MAL</h2>',
		'<p>Ürünün ayıplı olması halinde tüketicinin 6502 sayılı Kanun kapsamındaki seçimlik hakları saklıdır. Ayıplı ürün nedeniyle yapılan iade işlemlerinde tüketici yasal haklarından mahrum bırakılamaz.</p>',
		'<h2>MADDE 13 – KİŞİSEL VERİLER</h2>',
		'<p>Kişisel veriler, KUKA ISLAND KVKK Aydınlatma Metni ve yürürlükteki mevzuat kapsamında işlenir. Ticari elektronik ileti gönderimi için ilgili mevzuattaki onay ve izin süreçleri ayrıca uygulanır.</p>',
		'<h2>MADDE 14 – UYUŞMAZLIKLAR</h2>',
		'<p>Tüketici uyuşmazlıklarında yürürlükteki parasal sınırlar ve mevzuat çerçevesinde Tüketici Hakem Heyetleri ve Tüketici Mahkemelerine başvurulabilir. Tüketicinin kanundan doğan emredici hakları saklıdır.</p>',
		'<h2>MADDE 15 – YÜRÜRLÜK</h2>',
		'<p>Tüketicinin siparişi elektronik ortamda onaylamasıyla yürürlüğe girer. Tüketici sözleşmeyi okuyup kabul ettiğini beyan eder.</p>',
	)
);

// 08_Acik_Riza_Metni_KUKA_ISLAND.pdf
$consent_page = kuka_content_html(
	array(
		'<h2>Veri sorumlusu bilgileri</h2>',
		$seller_block,
		'<h2>1. KAPSAM</h2>',
		'<p>Bu metin, KVKK kapsamında açık rıza alınması gereken durumlarda ilgili kişinin rızasının hangi konularda talep edildiğini açıklamak amacıyla hazırlanmıştır. Açık rıza, belirli bir konuya ilişkin, bilgilendirmeye dayanan ve özgür iradeyle açıklanan rızadır.</p>',
		'<h2>2. PAZARLAMA VE TİCARİ ELEKTRONİK İLETİLER</h2>',
		'<p>Açık rıza verilmesi halinde KUKA ISLAND tarafından kampanya, indirim, yeni koleksiyon, lansman ve benzeri ticari iletişim içerikleri e-posta, SMS veya diğer elektronik iletişim kanallarıyla gönderilebilir. Ticari elektronik ileti gönderiminde ayrıca ilgili mevzuat ve gerekli onay süreçleri uygulanır.</p>',
		'<h2>3. ÇEREZLER VE PAZARLAMA TEKNOLOJİLERİ</h2>',
		'<p>Gerekli hukuki şartların oluşması halinde analiz, reklam veya pazarlama amaçlı çerez ve benzeri teknolojilerin kullanımı için kullanıcıdan tercih/rıza alınabilir. Kullanıcı tercihlerini daha sonra değiştirebilir.</p>',
		'<h2>4. RIZANIN GERİ ALINMASI</h2>',
		'<p>Açık rıza her zaman geri alınabilir. Rızanın geri alınması, geri alma tarihinden önce rızaya dayanılarak gerçekleştirilmiş işlemlerin hukuka uygunluğunu etkilemez. Ticari elektronik iletiler bakımından ileti içinde sunulan ret/abonelikten çıkma yöntemleri de kullanılabilir.</p>',
		'<h2>5. ONAY</h2>',
		'<p>Aşağıdaki seçimler üzerinden tercihimi özgür irademle belirleyebilirim:</p>',
		'<ul><li>☐ KUKA ISLAND\'ın ticari elektronik ileti göndermesine izin veriyorum.</li>',
		'<li>☐ Pazarlama/analiz amaçlı tercihe bağlı çerezlerin kullanılmasına izin veriyorum.</li>',
		'<li>☐ İzin vermiyorum.</li></ul>',
		'<h2>6. İLETİŞİM</h2>',
		'<p>[kuka_value name="brand_name"]<br>E-posta: [kuka_value name="email"]<br>Telefon: [kuka_value name="telephone"]</p>',
	)
);

$size_guide_page = kuka_content_html(
	array(
		'<p>Tüm vücut ölçüleri santimetredir (cm). Mezurayı bedeni sıkıştırmadan ve yere paralel tutun.</p>',
		'<h2>Nasıl ölçülür?</h2>',
		'<ul><li><strong>Göğüs:</strong> Göğsün en dolgun noktasından yatay ölçün.</li>',
		'<li><strong>Göğüs altı:</strong> Göğsün hemen altından, mezura düz kalacak biçimde ölçün.</li>',
		'<li><strong>Bel:</strong> Doğal bel çizgisinin en dar noktasını ölçün.</li>',
		'<li><strong>Kalça:</strong> Kalçanın en geniş noktasından yatay ölçün.</li></ul>',
		'<p>İki beden arasında kalırsanız ürün sayfasındaki kalıp notunu değerlendirin. Bikini üstü ve altı ayrı satıldığı için farklı bedenler seçebilirsiniz.</p>',
		'[kuka_size_guide]',
	)
);

$order_tracking_page = kuka_content_html(
	array(
		'<p>Sipariş numaranızı ve siparişte kullandığınız e-posta adresini girin. Yönetici kargo takip numarasını siparişe eklediğinde gönderi bilgisi sipariş detayında görünür.</p>',
		'[woocommerce_order_tracking]',
	)
);

// First-pass English editorial copy. It is written by hand, keeps Kübra's
// first-person warmth and remains editable on the same WordPress page record.
$brand_story_en = kuka_content_html(
	array(
		'<div class="kuka-brand-story">',
		'<p class="kuka-brand-story__opening">[kuka_manifesto_line_2]</p>',
		'<div class="kuka-brand-story__source">',
		'<p class="kuka-brand-story__lede">KUKA ISLAND<br>Sometimes, life asks you to begin again.</p>',
		'<p>That is exactly how KUKA ISLAND began for me.</p>',
		'<p>As I turned a new page, I did not want to create just another brand. I wanted to bring everything that makes me feel good together under one roof.</p>',
		'<p class="kuka-brand-story__list">The sea…<br>Summer…<br>Freedom…<br>And those moments when women feel most beautiful in their own skin…</p>',
		'<p>That is how KUKA ISLAND came to life.</p>',
		'<p>Every collection is made for more than a single season—to bring back that same feeling, even when you wear it years from now.</p>',
		'<p>This journey is only just beginning.</p>',
		'<p>I am so glad you are here.</p>',
		'<p>And that you are with us for the first pages of this story.</p>',
		'<footer class="kuka-brand-story__sign"><span>Love,</span><strong>KÜBRA</strong></footer>',
		'</div>',
		'</div>',
	)
);

$english_pages = array(
	'hakkimizda' => array( 'About Us', $brand_story_en ),
	'iletisim' => array( 'Contact', kuka_content_html( array(
		'<p>For questions about orders, products or sizing, please use one of the current contact channels below.</p>',
		'[kuka_contact_details]',
		'<h2>Contact form</h2>',
		'<p class="kuka-service-disabled"><strong>The form is currently unavailable.</strong> Until the messaging service is connected, please contact us by email, phone, WhatsApp or Instagram.</p>',
	) ) ),
	'sik-sorulan-sorular' => array( 'Frequently Asked Questions', kuka_content_html( array(
		'<h2>Size and fit</h2>',
		'<details class="kuka-faq"><summary>How do I choose my size?</summary><div class="kuka-faq__panel"><p>Measure your bust, underbust, waist and hips in centimetres, then consider both the Size Guide and the fit note on the product page.</p></div></details>',
		'<details class="kuka-faq"><summary>What if I am between two sizes?</summary><div class="kuka-faq__panel"><p>Follow the fit note for the product first. For standard pilot fits, consider the larger size if you prefer a more relaxed feel.</p></div></details>',
		'<details class="kuka-faq"><summary>Can I order the top and bottom in different sizes?</summary><div class="kuka-faq__panel"><p>Yes. Bikini tops and bottoms are sold separately, so you can choose each size and colour independently.</p></div></details>',
		'<details class="kuka-faq"><summary>Where can I find the size guide?</summary><div class="kuka-faq__panel"><p>Open it from the size link on any product page or visit the <a href="/en/beden-rehberi/">Size Guide</a>.</p></div></details>',
		'<h2>Product and fabric</h2>',
		'<details class="kuka-faq"><summary>Where can I find the fabric composition?</summary><div class="kuka-faq__panel"><p>Each product page lists its specific composition under Material.</p></div></details>',
		'<details class="kuka-faq"><summary>How should I care for my swimwear?</summary><div class="kuka-faq__panel"><p>Follow the product care note. As a general rule, hand wash in cold water, do not wring and dry flat in the shade.</p></div></details>',
		'<details class="kuka-faq"><summary>Will chlorine or sunscreen affect the fabric?</summary><div class="kuka-faq__panel"><p>Rinse after swimming in chlorinated or salt water. Prolonged contact with sunscreen or oil may affect colour and elasticity.</p></div></details>',
		'<details class="kuka-faq"><summary>Is the product lined or padded?</summary><div class="kuka-faq__panel"><p>Lining and removable or fixed padding are described on each product page and may vary by style.</p></div></details>',
		'<h2>Shipping</h2><p>Standard shipping is [kuka_value name="flat_shipping_fee"] and the free-shipping threshold is [kuka_value name="free_shipping_threshold"]. The carrier is [kuka_value name="shipping_carrier"] and the estimated delivery time is [kuka_value name="delivery_time"]. Tracking details are shared when your order ships.</p>',
		'<h2>Right of withdrawal and returns</h2><p>Send your withdrawal notice within [kuka_value name="cayma_hakki_gun"] days of delivery, quoting your order number, to [kuka_value name="email"] or through the return channel on the site. For easier proof of notice, withdrawal requests are not accepted by phone. Current return-shipping responsibility: [kuka_value name="return_shipping_responsibility"].</p>',
		'<p>If you need a different size, use your right of withdrawal and place a new order.</p><p>[kuka_hygiene_policy]</p><p>[kuka_hygiene_try_on]</p>',
		'<h2>Payment</h2><p>You can use the card methods shown at checkout. Instalment options depend on your bank and iyzico and appear during payment. Kuka Island does not store card data; it is processed by the payment provider’s secure infrastructure.</p>',
		'<h2>Orders</h2><p>Track your order with your order number and email address on the <a href="/en/siparis-takibi/">Order Tracking</a> page. You may contact support to request cancellation before preparation begins.</p>',
	) ) ),
	'kargo-teslimat' => array( 'Shipping & Delivery', kuka_content_html( array(
		'<h2>Preparation and delivery</h2><p>Orders are prepared after payment is confirmed. The estimated delivery time is [kuka_value name="delivery_time"] and the carrier is [kuka_value name="shipping_carrier"]. Weekends, public holidays, busy periods and the delivery region may affect timing.</p>',
		'<h2>Shipping fee and free shipping</h2><p>Standard shipping is [kuka_value name="flat_shipping_fee"] and the free-shipping threshold is [kuka_value name="free_shipping_threshold"]. The order summary at checkout shows the final amount that applies.</p>',
		'<h2>Tracking</h2><p>Once the administrator adds a tracking number to your order, it is sent by email and becomes available through Order Tracking.</p>',
		'<h2>Incorrect address</h2><p>Contact support before the parcel ships. Once it is in transit, address changes depend on the carrier, and reshipping costs may apply to an undeliverable parcel.</p>',
		'<h2>Damaged delivery</h2><p>If the parcel is visibly damaged, ask the courier to record it where possible. Send support your order number and photos of the parcel and product without delay.</p>',
	) ) ),
	'ticari-elektronik-ileti-onayi' => array( 'Commercial Electronic Communication Consent', '<h2>Communication preference</h2><p>The newsletter stores only consented registrations. No bulk-email tool is provided; any future campaign or product communication must follow the applicable consent, opt-out and İYS processes.</p>' ),
	'beden-rehberi' => array( 'Size Guide', kuka_content_html( array(
		'<p>All body measurements are in centimetres (cm). Keep the tape level with the floor without pulling it tight.</p>',
		'<h2>How to measure</h2>',
		'<ul><li><strong>Bust:</strong> Measure horizontally around the fullest part of your bust.</li>',
		'<li><strong>Underbust:</strong> Measure directly below your bust, keeping the tape level.</li>',
		'<li><strong>Waist:</strong> Measure the narrowest part of your natural waist.</li>',
		'<li><strong>Hips:</strong> Measure horizontally around the fullest part of your hips.</li></ul>',
		'<p>If you are between sizes, check the fit note on the product page. Bikini tops and bottoms are sold separately, so you may choose different sizes.</p>',
		'[kuka_size_guide]',
	) ) ),
	'siparis-takibi' => array( 'Order Tracking', '<p>Enter your order number and the email address used for the order. Once a tracking number is added, shipment details appear with your order.</p>[woocommerce_order_tracking]' ),
	'tipografi-testi' => array( 'Typography Test', '<section class="kuka-type-test" aria-label="English heading test"><h2>Bikini Top</h2><h2>High-waisted Bikini Bottom</h2><h2>Strapless Swimsuit</h2><h2>Tie-side</h2></section>' ),
);

$pages = array(
	'hakkimizda' => array( 'Hakkımızda', $brand_story ),
	'iletisim' => array( 'İletişim', $contact_page ),
	'sik-sorulan-sorular' => array( 'Sık Sorulan Sorular', $faq_page ),
	'kargo-teslimat' => array( 'Kargo ve Teslimat', $shipping_page ),
	'iade-degisim' => array( 'Cayma Hakkı ve İade', $withdrawal_page ),
	'gizlilik-politikasi' => array( 'Gizlilik Politikası', $privacy_page ),
	'cerez-politikasi' => array( 'Çerez Politikası', $cookie_page ),
	'kvkk-aydinlatma-metni' => array( 'KVKK Aydınlatma Metni', $kvkk_page ),
	'kullanim-kosullari' => array( 'Üyelik ve E-Ticaret Sitesi Kullanım Sözleşmesi', $terms_page ),
	'on-bilgilendirme-formu' => array( 'Ön Bilgilendirme Formu', $preinfo_page ),
	'mesafeli-satis-sozlesmesi' => array( 'Mesafeli Satış Sözleşmesi', $distance_page ),
	'acik-riza-metni' => array( 'Açık Rıza Metni', $consent_page ),
	'ticari-elektronik-ileti-onayi' => array( 'Ticari Elektronik İleti Onayı', '<h2>İletişim tercihi</h2><p>Bülten altyapısı şu anda devre dışıdır. Etkinleştirildiğinde e-posta ile kampanya/ürün iletişimi yalnız ayrı onayla gönderilecek; her iletide ret yolu sunulacaktır. Onayın kapsamı ve geri alınması Açık Rıza Metni’nde açıklanır.</p>' ),
	'beden-rehberi' => array( 'Beden Rehberi', $size_guide_page ),
	'siparis-takibi' => array( 'Sipariş Takibi', $order_tracking_page ),
	'tipografi-testi' => array( 'Tipografi Testi', '<section class="kuka-type-test" aria-label="Türkçe diakritik başlık testi"><h2>Bikini Üstü</h2><h2>Yüksek Bel Bikini Altı</h2><h2>Straplez Mayo</h2><h2>İpli / Yan Bağlamalı</h2></section>' ),
);

// Üyelik sözleşmesi üyelik hükümleri taşıyor; site üyelik sunmadığı için hukuk
// danışmanının kararı gelene kadar taslakta bırakılır (docs/MUSTERI_SORULARI.md).
$draft_pages = array( 'kullanim-kosullari' );
foreach ( $pages as $slug => $page ) {
	$page_id = kuka_content_page( $slug, $page[0], $page[1], in_array( $slug, $draft_pages, true ) ? 'draft' : 'publish' );
	if ( isset( $english_pages[ $slug ] ) ) {
		update_post_meta( $page_id, '_kuka_title_en', $english_pages[ $slug ][0] );
		update_post_meta( $page_id, '_kuka_content_en', $english_pages[ $slug ][1] );
	} else {
		// The only untranslated public records are the eight customer-supplied
		// legal contracts; publishing a draft translation would create legal risk.
		delete_post_meta( $page_id, '_kuka_title_en' );
		delete_post_meta( $page_id, '_kuka_content_en' );
	}
}
$home_id = kuka_content_page( 'ana-sayfa', 'Ana Sayfa', '' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home_id );

$attachment_id = static function ( string $source ): int {
	$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'meta_key' => '_kuka_seed_source', 'meta_value' => $source, 'fields' => 'ids' ) );
	return $ids ? (int) $ids[0] : 0;
};
if ( class_exists( 'Kuka_Island_Core_Site_Appearance' ) ) {
	$current = Kuka_Island_Core_Site_Appearance::get();
	$defaults = Kuka_Island_Core_Site_Appearance::defaults();
	foreach ( Kuka_Island_Core_Language::translation_defaults() as $group => $values ) {
		$current[ $group ] = array_merge( $current[ $group ] ?? array(), $values );
	}
	$current['hero']['desktop_image_id'] = $attachment_id( 'hero-aegean-black.jpg' );
	$current['hero']['mobile_image_id'] = $attachment_id( 'hero-aegean-black-mobile.jpg' );
	$current['home']['editorial_image_id'] = $attachment_id( 'cobalt-set.jpg' );
	$current['story']['scenes'] = $defaults['story']['scenes'];
	$story_media = array(
		array( 'hero-aegean-black.jpg', 'hero-aegean-black-mobile.jpg', 'light' ),
		array( 'cobalt-set.jpg', 'cobalt-set.jpg', 'dark' ),
		array( 'noir-asymmetric-top-detail.jpg', 'noir-asymmetric-top.jpg', 'dark' ),
		array( 'cobalt-asymmetric-top-detail.jpg', 'cobalt-asymmetric-top.jpg', 'dark' ),
		array( 'hero-aegean-black.jpg', 'hero-aegean-black-mobile.jpg', 'light' ),
		array( 'noir-one-piece-detail.jpg', 'noir-one-piece.jpg', 'dark' ),
	);
	foreach ( $story_media as $scene_index => $scene_media ) {
		$desktop_id = $attachment_id( $scene_media[0] );
		$mobile_id  = $attachment_id( $scene_media[1] );
		$current['story']['scenes'][ $scene_index ]['desktop_image_id']    = $desktop_id;
		$current['story']['scenes'][ $scene_index ]['desktop_image_id_en'] = $desktop_id;
		$current['story']['scenes'][ $scene_index ]['mobile_image_id']     = $mobile_id;
		$current['story']['scenes'][ $scene_index ]['mobile_image_id_en']  = $mobile_id;
		$current['story']['scenes'][ $scene_index ]['text_tone']           = $scene_media[2];
		$current['story']['scenes'][ $scene_index ]['text_tone_en']        = $scene_media[2];
	}
	$current['brand']['social_links'] = $defaults['brand']['social_links'];
	$current['brand']['email'] = $defaults['brand']['email'];
	unset( $current['brand']['whatsapp_url'] );
	$current['brand']['whatsapp_phone'] = $defaults['brand']['whatsapp_phone'];
	$current['announcement']['items'] = $defaults['announcement']['items'];
	$current['navigation']['help'] = $defaults['navigation']['help'];
	// Bu turda sözleşmeye bağlanan gruplar seed tarafından kesinleştirilir.
	foreach ( array( 'languages', 'commercial', 'legal', 'checkout', 'membership', 'content' ) as $group ) {
		$current[ $group ] = $defaults[ $group ];
	}
	$current['footer']['help_links'] = $defaults['footer']['help_links'];
	$current['footer']['legal_links'] = $defaults['footer']['legal_links'];
	$current['home']['category_index_enabled'] = $defaults['home']['category_index_enabled'];
	foreach ( array( 'manifesto_line_1', 'manifesto_line_1_en', 'manifesto_line_2', 'manifesto_line_2_en', 'service_2_title', 'service_2_copy' ) as $home_key ) {
		$current['home'][ $home_key ] = $defaults['home'][ $home_key ];
	}
	$current['hero']['text_tone'] = $defaults['hero']['text_tone'];
	unset( $current['home']['service_1'], $current['home']['service_2'], $current['home']['service_3'] );
	update_option( Kuka_Island_Core_Site_Appearance::OPTION_NAME, $current, false );
	Kuka_Island_Core_Site_Appearance::sync_free_shipping_threshold();
}
flush_rewrite_rules( false );
WP_CLI::success( sprintf( '%d içerik sayfası ve Site Appearance görselleri hazır.', count( $pages ) + 1 ) );
