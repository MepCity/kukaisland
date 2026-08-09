# Kuka Island Panel Rehberi

Günlük kullanıcı `[removed-manager-user]` hesabıyla giriş yapar. Sol menüde **Kuka Island → Başlangıç** günlük işlerin bağlantılı haritasıdır; içerik ayarları **Kuka Island → Site Görünümü** altındadır. Sayfanın altındaki **Site görünümünü kaydet** düğmesi nonce ve `manage_woocommerce` yetkisiyle çalışır.

## Türkçe ve İngilizce içerik

- Site Görünümü'ndeki standart çevrilebilir alanlar aynı satırda **Türkçe** ve **English** olarak görünür. Toplam 41 standart içerik alanının İngilizce ikizi vardır; marka hikâyesinin sahne metinleri kendi iki sütunlu düzeninde ayrıca yönetilir.
- URL, medya, telefon, VKN, kargo tutarı, gün sayısı ve benzeri ortak/sayısal alanların İngilizce ikizi yoktur; iki dil aynı değeri kullanır.
- English alanı boş bırakılırsa vitrinde Türkçe kaynak gösterilir. Sistem otomatik çeviri yapmaz ve boş sayfa üretmez.
- Bağlantı listelerinde English sütununa yalnız etiketler aynı satır sırasında yazılır; URL Türkçe kaynaktaki ortak değer olarak kalır.
- Ürün düzenlemede **English product content**, sayfa düzenlemede **English page content**, kategori/renk/kesim/beden düzenlemede **English name** alanı kullanılır. Fiyat, stok, SKU, varyasyon ve görseller ortak kalır.
- Yasal sayfaların İngilizce alanlarını hukuk danışmanından onaylı metin gelmeden doldurmayın. Boşken ziyaretçi Türkçe bağlayıcı metni açıklama notuyla görür.

Görsel örnekler: [iki dilli Site Görünümü](qa/faz5b/08-site-appearance-bilingual.jpg), [ürün EN alanları](qa/faz5b/09-product-english-fields.jpg), [sayfa EN alanları](qa/faz5b/11-page-english-fields.jpg), [terim EN alanı](qa/faz5b/10-taxonomy-english-field.jpg).

## Site Görünümü grupları

1. **Marka:** medya seçicili masaüstü/mobil logo, favicon, sosyal paylaşım görseli, e-posta, telefon, WhatsApp ve `Etiket|URL` sosyal bağlantıları.
2. **Duyuru Bandı:** görünürlük, en fazla üç metin ve aynı sıradaki bağlantı etiketi/URL'si. Satır sırası yayın sırasıdır.
3. **Ana Hero:** görünürlük, medya seçicili masaüstü/mobil görsel, üst başlık, başlık, metin, buton ve güvenli hizalama/metin tonu seçimleri. İç URL'ler `/magaza/`, dış URL'ler `https://...` biçiminde yazılabilir.
4. **Ana Sayfa Bölümleri:** kesim indeksi etiketi; yeni gelenler görünürlüğü, kaynak/kategori/koleksiyon/manüel ID ve grid/carousel sunumu; kart swatch ve stok satırı anahtarları; editoryal görsel/video; manifesto ve hizmet satırı.
5. **Marka Hikâyesi:** her sahnede Türkçe/English metin, her dil için ayrı masaüstü/mobil görsel ve ayrı açık/koyu metin tonu bulunur. **Yukarı / Aşağı** ile sıralayın, **Sahne ekle** veya **Sahneyi kaldır** ile sayıyı değiştirin. Paragraf için boş satır; sahne 04 gibi bilinçli kısa dizilerde tek satır sonu kullanın ve **Satırları sırayla aç** kutusunu işaretleyin. `Love,` ve `KÜBRA` son iki satır olarak aynen tutulur. Görsel boş bırakılırsa sahne düz zeminle çalışır.
6. **Navigasyon:** sabit bağlantılar `Etiket|/adres/` biçimindedir. Hatalı/eksik satır kaydedilmez ve kayıttan sonra satır numarasıyla uyarı görünür. Kategori tablosundaki “Üst menü” ve “Ana sayfa indeksi” kutuları aynı kategori kaynağının iki görünürlüğünü yönetir.
7. **Footer:** marka/bülten metinleri ile yardım ve yasal bağlantılar. Entegrasyon bağlanana kadar bülten kayıt formu devre dışıdır.
8. **Ticari Bilgiler:** standart kargo ücreti, ücretsiz kargo eşiği, kupon indiriminin eşikten önce/sonra uygulanması, kargo firması, tahmini süre, `cayma_hakki_gun`, hijyen metinleri ve iade kargo sorumluluğu. Varsayılan “indirimden sonraki tutar”dır (`ignore_discounts=no`). Bu değerler SSS, kargo, iade ve yasal metinlerde otomatik kullanılır; kargo ücreti/eşiği ve kupon tabanı WooCommerce yöntemlerine de yazılır.
9. **Şirket ve Yasal Yer Tutucular:** şirket unvanı, VKN, vergi dairesi, adres, telefon, ETBİS ve MERSİS tek noktadan yönetilir. Gerçek bilgi gelene kadar köşeli parantezli yer tutucular silinmez.
10. **Beden Rehberi Verileri:** bikini üstü, bikini altı ve mayo satırları `|` ayrımlı olarak düzenlenir. Sütun sayısı etiketin yanında gösterilir; ölçüler santimetredir.
11. **Üyelik:** varsayılan kapalıdır. Kapalıyken misafir ödeme zorlanır; kayıt ve checkout girişi kapatılır. Misafir sepeti ömrü saat olarak aynı gruptan yönetilir.

## Ürün ve stok günlük akışı

- **Ürünler** bölümünde ürün/varyasyon fiyatı, SKU, stok adedi ve görselleri düzenlenir. Genel ürün verisindeki Kuka Island alanlarında kumaş, bakım, kalıp, model boyu/bedeni, beden rehberi, SEO başlığı ve meta açıklaması zorunlu şablon alanlarıdır.
- Renk değerleri global `Renk` niteliğinden; kart rengi de terimdeki swatch alanından gelir.
- Bir varyasyonun stoğu sıfıra indiğinde karttaki ilgili beden otomatik üstü çizili görünür.
- Siparişler ve raporlar WooCommerce/Analiz menülerindedir.

## Kilitli desenler

Sayfa düzenleyicide **Desenler → Kuka Island** altında iki desen bulunur: “Kilitli editoryal hikâye” ve “Kilitli yasal bölüm”. Metin değiştirilebilir; sütun/bölüm yapısı taşınamaz veya silinemez. Font, renk, grid, breakpoint, animasyon süresi, kart oranı ve ana sayfa iskelet sırası panelde açılmamıştır.

## Güvenli kullanım

- Görsel/video alanlarında **Medyadan seç** düğmesini kullanın; medya ID'sini elle bulmanız gerekmez.
- Metin alanları bilerek boş bırakılabilir; boş değer kaydedilir. Yalnız teknik olarak eksik kayıt ana varsayılanla tamamlanır.
- Canlı yasal metni hukuk onayı olmadan yer tutucudan çıkarmayın.
- Yasal sayfalardaki `[kuka_*]` kısa kodlarını silmeyin; taslak uyarısı, şirket bilgileri ve ticari değerler bu merkezi bağlantılarla güncel kalır.
- Güncelleme öncesi staging/yedek alın; WooCommerce, iyzico veya parent tema dosyalarını doğrudan düzenlemeyin.
