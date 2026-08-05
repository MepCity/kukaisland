# Kuka Island Panel Rehberi

Günlük kullanıcı `kuka_manager` hesabıyla giriş yapar. Sol menüde **Kuka Island → Başlangıç** günlük işlerin bağlantılı haritasıdır; içerik ayarları **Kuka Island → Site Görünümü** altındadır. Sayfanın altındaki **Site görünümünü kaydet** düğmesi nonce ve `manage_woocommerce` yetkisiyle çalışır.

## Site Görünümü grupları

1. **Marka:** medya seçicili masaüstü/mobil logo, favicon, sosyal paylaşım görseli, e-posta, telefon, WhatsApp ve `Etiket|URL` sosyal bağlantıları.
2. **Duyuru Bandı:** görünürlük, en fazla üç metin ve aynı sıradaki bağlantı etiketi/URL'si. Satır sırası yayın sırasıdır.
3. **Ana Hero:** görünürlük, medya seçicili masaüstü/mobil görsel, üst başlık, başlık, metin, buton ve güvenli hizalama/metin tonu seçimleri. İç URL'ler `/magaza/`, dış URL'ler `https://...` biçiminde yazılabilir.
4. **Ana Sayfa Bölümleri:** kesim indeksi etiketi; yeni gelenler görünürlüğü, kaynak/kategori/koleksiyon/manüel ID ve grid/carousel sunumu; kart swatch ve stok satırı anahtarları; editoryal görsel/video; manifesto ve hizmet satırı.
5. **Navigasyon:** sabit bağlantılar `Etiket|/adres/` biçimindedir. Hatalı/eksik satır kaydedilmez ve kayıttan sonra satır numarasıyla uyarı görünür. Kategori tablosundaki “Üst menü” ve “Ana sayfa indeksi” kutuları aynı kategori kaynağının iki görünürlüğünü yönetir.
6. **Footer:** marka/bülten/şirket metinleri ile yardım ve yasal bağlantılar.
7. **Ticari Bilgiler:** ücretsiz kargo eşiği, kalan tutar metni (`%s` fiyatla değiştirilir), tamamlandı mesajı, kargo/değişim/güvenli ödeme/destek metinleri.
8. **Panel Metinleri:** hesap paneli karşılama başlığı ve kısa açıklaması.

## Ürün ve stok günlük akışı

- **Ürünler** bölümünde ürün/varyasyon fiyatı, SKU, stok adedi ve görselleri düzenlenir.
- Renk değerleri global `Renk` niteliğinden; kart rengi de terimdeki swatch alanından gelir.
- Bir varyasyonun stoğu sıfıra indiğinde karttaki ilgili beden otomatik üstü çizili görünür.
- Siparişler ve raporlar WooCommerce/Analiz menülerindedir.

## Kilitli desenler

Sayfa düzenleyicide **Desenler → Kuka Island** altında iki desen bulunur: “Kilitli editoryal hikâye” ve “Kilitli yasal bölüm”. Metin değiştirilebilir; sütun/bölüm yapısı taşınamaz veya silinemez. Font, renk, grid, breakpoint, animasyon süresi, kart oranı ve ana sayfa iskelet sırası panelde açılmamıştır.

## Güvenli kullanım

- Görsel/video alanlarında **Medyadan seç** düğmesini kullanın; medya ID'sini elle bulmanız gerekmez.
- Metin alanları bilerek boş bırakılabilir; boş değer kaydedilir. Yalnız teknik olarak eksik kayıt ana varsayılanla tamamlanır.
- Canlı yasal metni hukuk onayı olmadan yer tutucudan çıkarmayın.
- Güncelleme öncesi staging/yedek alın; WooCommerce, iyzico veya parent tema dosyalarını doğrudan düzenlemeyin.
