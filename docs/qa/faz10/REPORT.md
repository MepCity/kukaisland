# Faz 10 — Checkout doğrulama uyarısı

Tarih: 10 Ağustos 2026

## Sonuç

- AJAX ve sunucu uyarıları `form.checkout` içindeki tek `data-checkout-notices` grid satırında, iki kolonu kapsayacak biçimde render edildi.
- Alan ve özet kolonları aynı açık ikinci grid satırında kaldı. 1280 görünümünde özet x konumu hata öncesi ve sonrası `771.84375`; yatay sapma `0`.
- Görünen alan etiketleri değiştirilmedi. `woocommerce_checkout_required_field_notice` filtresi, doğrulama metninde WooCommerce'in eklediği Billing/Fatura bağlamını çıkarıp alanın asıl etiketini kullandı.
- Türkçe AJAX: `Ad gerekli bir alandır.`; `Fatura Ad` sayısı `0`.
- İngilizce AJAX: `First name is a required field.`; `Billing First name` sayısı `0`.
- İki dilde yedi alan hatasının `7/7` tanesi `data-id` hedefli bağlantıdır. İlk odak iki dilde de `billing_first_name`; Soyad bağlantısı tıklama testi sonrası odak `billing_last_name` oldu.
- Canlı bölge sayısı `1`: AJAX grubunun mevcut `role="alert"` niteliği tekrar edilmedi.

## Viewport ölçümü

Her dil bağımsız ölçüldü. Değer, `document.documentElement.scrollWidth - clientWidth` sonucudur.

| Viewport | Türkçe taşma | İngilizce taşma | Uyarı / form genişliği |
|---:|---:|---:|---:|
| 320 × 800 | 0 | 0 | 288 / 288 |
| 390 × 844 | 0 | 0 | 358 / 358 |
| 768 × 1024 | 0 | 0 | 734.21875 / 734.21875 |
| 1024 × 900 | 0 | 0 | 978.953125 / 978.953125 |
| 1280 × 900 | 0 | 0 | 1143.6875 / 1143.6875 |
| 1440 × 1000 | 0 | 0 | 1136.640625 / 1136.640625 |
| 1920 × 1080 | 0 | 0 | 1115.53125 / 1115.53125 |

Toplam: `14/14` dil-viewport ölçümünde yatay taşma `0`.

## Hareket ve kaydırma

- Açılış/kapanış: `--duration-fast = 180ms`, easing `--ease-out = cubic-bezier(0.22, 1, 0.36, 1)`.
- Uyarı grid satırı, alt boşluk, opaklık ve translate birlikte geçiş yapar; içerik ani yeniden akış yerine aynı 180 ms boyunca hareket eder.
- `prefers-reduced-motion: reduce` altında CSS `transition: none`; JS kaydırma dalı `smooth` yerine `auto` seçer.
- Uyarı ödeme düğmesinden tetiklendiğinde başlangıç scrollY `1907` idi. Yumuşak kaydırma sonunda scrollY `272`, uyarı üstü `79.9375`, altı `374.4375`, viewport yüksekliği `900`; tamamı görünür alandaydı.

## JS kapalı ve sunucu çıktısı

Bağımsız cookie jar ile iki ayrı POST çalıştırıldı. Her iki HTML çıktısında `.woocommerce-error`, `data-checkout-notices-inner` ile `<aside>` arasında bulundu; form öncesindeki hata sayısı `0`.

- TR: doğal `Ad` mesajı `1`, `Fatura Ad` `0`.
- EN: doğal `First name` mesajı `1`, `Billing First name` `0`.
- `scripts/smoke.sh` aynı iki sunucu sözleşmesini JS olmadan doğrular.

## Görsel kanıt

- `before-error-1280.jpg`: eski otomatik grid yerleşimi; kutu sağ kolonu kaplıyor.
- `fixed-before-error-1280.jpg`: hata öncesi sabit kolon düzeni.
- `after-error-1280.jpg`: Türkçe AJAX uyarısı üstte ve iki kolonda.
- `after-error-en-1280.jpg`: İngilizce AJAX uyarısı üstte ve iki kolonda.
- `transition-start-1280.jpg`, `transition-mid-1280.jpg`: ödeme alanından uyarıya yumuşak kaydırmanın sıralı ara durumları.

## Disiplin ve regresyon

- `CSS_RAW_COLORS_OUTSIDE_TOKENS=0`
- `CSS_RAW_PX_OUTSIDE_TOKENS=0`
- `CSS_SHADOWS=0`
- `CSS_UNDEFINED_TOKENS=0`
- `VENDOR_CHANGES=0`
- `SMOKE=PASS (5/5)`
- `make reset && make verify`: PASS tur 1
- `make reset && make verify`: PASS tur 2

İlk temiz kurulum girişimi WordPress.org Blocksy indirmesinde ağ zaman aşımıyla reset sırasında kesildi; verify başlamadı ve iki başarılı turun hiçbirine sayılmadı.
