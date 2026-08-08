# Faz 4C teknik raporu

Tarih: 2026-08-09

## Sonuç

Faz 4C hero, marka anlatısı ve bülten kayıt kapsamı tamamlandı. Canlıya deploy veya canlı anahtar kullanımı yapılmadı; vendor dosyalarında değişiklik yoktur.

## Hero

- Önce: tüm hero görseli gradient ile soluyordu. Kanıt: `qa/faz4c-before-hero-desktop.png`, `qa/faz4c-before-hero-mobile.png`.
- Sonra: hero `background-image` değeri yalnız görseldir; perde `.kuka-hero__content::before` ile metin alanında kalır. Kanıt: `qa/faz4c-after-hero-desktop.png`, `qa/faz4c-after-hero-mobile.png`.
- `Site Görünümü → Ana Hero → Metin perdesi yoğunluğu (%)` alanı 0–100 aralığında yönetilir. Panel: `qa/faz4c-hero-overlay-panel.png`; %58 ve %88 sonuçları: `qa/faz4c-hero-overlay-58.png`, `qa/faz4c-hero-overlay-88.png`. Varsayılan değer %78'dir.
- Kontrast için muhafazakâr alt sınır, fotoğraf pikselinin siyah olduğu varsayımıyla hesaplandı. `--color-paper` %78 alfa ile siyah üzerine birleştiğinde arka plan `(195.78, 193.44, 188.76)`, `--color-ink` `(60, 42, 18)` olur. WCAG bağıl parlaklık oranı masaüstü ve mobilde **7.66:1**; AA 4.5:1 eşiğinin üzerindedir.

## Footer, manifesto ve Hakkımızda

- Footer deneme A (`qa/faz4c-footer-trial-a.png`) daha büyük wordmark ve geniş amblem aralıklarıyla alt şeridi ağırlaştırıyordu. Seçilen deneme B (`qa/faz4c-footer-trial-b-selected.png`) amblem/wordmark ölçeğini ve aralığı küçültüp üç link kolonu ile optik denge kurduğu için seçildi.
- Manifesto `--manifesto-min-height` değeri masaüstünde 780'den 520'ye, mobilde 640'tan 420'ye indi; başlık ölçeği `clamp(42px, 5.5vw, 80px)` token'ına düştü. İki içerik satırı tek grid içinde gösterilir. Önce/sonra: `qa/faz4c-before-manifesto.png`, `qa/faz4c-after-manifesto.png`.
- `/hakkimizda/` sola yaslı, `--measure-copy` satır ölçülü kaynak metin ve ayrı çizgili imza bloğu kullanır. “Bir yer değil. Bir his.” açılışı `[kuka_manifesto_line_2]` kısa koduyla paneldeki manifesto alanından gelir. Önce/sonra masaüstü ve mobil: `qa/faz4c-before-about-desktop.png`, `qa/faz4c-after-about-desktop.png`, `qa/faz4c-before-about-mobile.png`, `qa/faz4c-after-about-mobile.png`.
- PDF ve seed edilmiş `.kuka-brand-story__source` metni boşlukları normalize edilerek karşılaştırıldı. İki SHA-256 değeri de `5a5abe434287ee46f1977009e8c031bc350f760dd9770230eba8c36663046823`; `BRAND_STORY_DIFF=EMPTY`. `make verify` ayrıca `BRAND_STORY_PDF_MATCH=yes` üretir.

## Bülten

- Form düz HTML `method="post"` ile `admin-post.php` uç noktasına gider; JS bağımlılığı yoktur. `required` e-posta/onay kontrollerine ek olarak sunucu doğrulaması vardır.
- `wp_kuka_newsletter_subscribers` tablosunda e-posta, gönderim anındaki onay metni, UTC tarih ve IP tutulur. Boş bildirim e-postasıyla kayıt sonucu: `before:0`, `first:success`, `after:1`. Kayıt kanıtı: `qa/faz4c-newsletter-admin-list.png`.
- Panel bildirim alanı: `qa/faz4c-newsletter-panel-email.png`. Alan boşsa `wp_mail` atlanır, veritabanı yazımı değişmez.
- Başarılı kayıt: `qa/faz4c-newsletter-success.png`. Onay hatası: `qa/faz4c-newsletter-consent-error.png`. Rate-limit hatası: `qa/faz4c-newsletter-rate-error.png`.
- Sunucu testi: `consent:consent`, `honeypot:success` ve kayıt sayısı değişmedi, ilk gerçek POST `success`, aynı IP+e-posta ile ikinci POST `rate`, son kayıt sayısı `1`.
- WooCommerce altındaki “Bülten Kayıtları” ekranı son 500 kaydı listeler. Nonce ve yetki kontrollü CSV çıktısının başlığı `id,email,consent_text,consented_at_utc,ip_address`; test `CSV_EXPORT=PASS`.
- Toplu e-posta gönderimi yoktur. Sınıfta tek `wp_mail` çağrısı yalnız yeni tekil kayıt bildirimidir; `make verify` bunu `NEWSLETTER_WP_MAIL_CALLS=1` olarak kilitler.

## Regresyon

- Header metni “Hikâyemiz”; `make verify`: `STORY_MENU_LABEL=Hikâyemiz`.
- Beden sırası kart, ürün, filtre, sepet, ödeme ve beden rehberinde S–M–L: `qa/faz4b-size-card.png`, `qa/faz4b-size-product.png`, `qa/faz4b-size-filter.png`, `qa/faz4b-size-cart.png`, `qa/faz4b-size-checkout.png`, `qa/faz4b-size-guide.png`.
- 320, 390, 768, 1024, 1280, 1440 ve 1920 genişliklerinde `scrollWidth - clientWidth = 0`. Görüntüler: `qa/faz4c-overflow-320.png`, `qa/faz4c-overflow-390.png`, `qa/faz4c-overflow-768.png`, `qa/faz4c-overflow-1024.png`, `qa/faz4c-overflow-1280.png`, `qa/faz4c-overflow-1440.png`, `qa/faz4c-overflow-1920.png`.
- Token ölçümü: ham renk 0, ham px 0, gölge 0, tanımsız token 0. Vendor değişikliği 0.

## Kabul durumu

1–17 numaralı kriterler yukarıdaki kod, ölçüm ve görsellerle karşılandı. `make reset && make verify` temiz volume'dan art arda iki kez `VERIFY=PASS` ve `SMOKE=PASS (5/5)` verdi. Kriter 18'in uzak CI bölümü ilk push sonrasında ayrıca işlenecektir; CI sonucu gelmeden tüm kriterler tamamlandı sayılmaz.
