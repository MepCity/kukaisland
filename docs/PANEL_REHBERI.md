# Kuka Island Panel Rehberi

Günlük kullanıcı `[removed-manager-user]` hesabıyla giriş yapar. Sol menüden **Kuka Island → Site Görünümü** açılır. Sayfanın altındaki **Site görünümünü kaydet** düğmesi nonce ve `manage_woocommerce` yetkisiyle çalışır.

## Site Görünümü grupları

1. **Marka:** masaüstü/mobil logo, favicon, sosyal paylaşım görseli, e-posta, telefon, WhatsApp ve `Etiket|URL` sosyal bağlantıları.
2. **Duyuru Bandı:** görünürlük, en fazla üç metin ve aynı sıradaki bağlantı etiketi/URL'si. Satır sırası yayın sırasıdır.
3. **Ana Hero:** görünürlük, masaüstü/mobil medya, üst başlık, başlık, metin, buton ve güvenli hizalama/metin tonu seçimleri.
4. **Ana Sayfa Bölümleri:** kesim indeksi etiketi; yeni gelenler görünürlüğü, kaynak/kategori/koleksiyon/manüel ID ve grid/carousel sunumu; kart swatch ve stok satırı anahtarları; editoryal görsel/video; manifesto ve hizmet satırı.
5. **Navigasyon:** her satır `Etiket|/adres/` biçimindedir. Hatalı ya da eksik satır kaydedilmez.
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

- Medya ID'si yerine mümkünse önce Ortamlar'dan doğru dosyayı doğrulayın.
- Canlı yasal metni hukuk onayı olmadan yer tutucudan çıkarmayın.
- Güncelleme öncesi staging/yedek alın; WooCommerce, iyzico veya parent tema dosyalarını doğrudan düzenlemeyin.
