# Faz 3C teknik raporu

Tarih: 4 Ağustos 2026  
Ortam: `http://localhost:8080`  
Kapsam: sepet ve hesap panelleri, hareket yumuşatma, menü çizgisi ve açık filtre örtüsü

## Sonuç

Faz 3C kabul edildi. Blocksy Pro alınmadan WooCommerce fragment tabanlı sepet çekmecesi ve native hesap/giriş paneli eklendi. Mobil menü, filtre, sepet ve hesap aynı erişilebilir yan panel kontrolünü kullanır. WooCommerce override sayısı **2** olarak kaldı; parent theme, WooCommerce ve iyzico dosyası değiştirilmedi.

## Sepet çekmecesi

- JS'siz varyasyon ekleme `/sepet/` sayfasına yönlendi ve sayaç **0 → 1** oldu.
- Panelde ürün görseli, `Asimetrik Bikini Üstü`, `Renk: Siyah`, `Beden: 36`, adet ve WooCommerce fiyatı göründü.
- Adet **1 → 2**, sayaç **1 → 2**; kaldırma sonrası sayaç **2 → 0** ve boş durum fragment ile yenilendi.
- Adet/kaldırma formu WooCommerce'in `woocommerce-cart-nonce` değerini ve çekirdek cart form handler'ını kullanır. Tema fiyat, stok, toplam veya sepet anahtarı matematiği yazmaz.
- `woocommerce_add_to_cart_fragments` bir PHP dosyasında kayıtlıdır; header sayacı ile panel gövdesini değiştirir.
- Ücretsiz kargo eşiği `Site Görünümü > commercial.free_shipping_threshold` kaynağından gelir. Ölçüm sepeti eşik üstünde olduğu için görünür metin “Ücretsiz kargo hakkınız hazır.” oldu.
- “Sepete git” ikincil metin bağlantısı, “Ödemeye geç” dolu birincil butondur. Yasal metinler bağlantıdır; zorunlu onay checkout'ta kalır.

## Hesap paneli

- Panel formunda gerçek `woocommerce-login-nonce` bulundu; form WooCommerce'e gönderilir.
- Geçersiz kullanıcı testinde panel yeniden açıldı. Odak `UL[role=alert]` öğesine taşındı ve “Giriş tamamlanamadı / Hata” ile başlayan Türkçe açıklama gösterildi; geri bildirim yalnız renge bağlı değildir.
- Native kayıt ayarı seed'de `yes` oldu; “Hesap oluşturun” bağlantısı `/hesabim/#customer_login` hedefine gider.
- Geçici müşteri hesabıyla gerçek giriş yapıldı. Panelde **Hesabım, Siparişler, Adresler, Çıkış yap** bağlantıları ölçüldü; ardından oturum kapatıldı ve geçici kullanıcı silindi.
- JS kapalı temel bağlantı `/hesabim/` olarak kalır.

## Ortak panel mantığı kanıtı

`PANEL_HANDLER_FILES=1`. `activePanel`, örtü eşleme, `inert`, Escape, Tab döngüsü ve odak iadesi yalnız `assets/js/storefront.js` içindedir. `cart.js` panel açmaz; `kuka:panel-open` olayı gönderir. `product.js` içindeki ayrı Escape/inert kodu yan panel değil, bağımsız ürün lightbox'ıdır.

Tarayıcı ölçümleri:

| Davranış | Ölçülen sonuç |
|---|---|
| Sepet açılış odağı | `Sepeti kapat` |
| Escape sonrası odak | `Sepeti aç` |
| Açık panel | `aria-hidden=false`, `inert=false`, body `overflow:hidden` |
| Kapalı panel | `aria-hidden=true`, `inert=true` |
| Panel genişliği, 1280 viewport | 480 px |

## Hareket ve yumuşatma

| Alan | Hesaplanan süre | Easing | Kullanım |
|---|---:|---|---|
| Hızlı durum | 180 ms | standard / ease-out | görünürlük, akordiyon |
| Mikro | 240 ms | standard | buton, chip, kenarlık ve renk |
| Menü çizgisi | 240 ms | ease-out | `scaleX(0) → scaleX(1)` |
| Görsel | 240 ms | ease-out / standard | kart ve galeri; eski 650 ms mevcut 240 ms token'a bağlandı |
| Yan panel | 420 ms | ease-out | mobil menü, filtre, sepet, hesap |

Menü çizgisi odak testinde hesaplanan transform `matrix(0,0,0,1,0,0) → matrix(1,0,0,1,0,0)` oldu. `prefers-reduced-motion: reduce` global kuralı transition ve animation süresini `0s !important` yapar.

## Token harness

`tokens.css` dışındaki kaynak CSS dosyaları:

| Dosya | >1 px literal | hex | `rgb/rgba()` | `var()` kullanımı |
|---|---:|---:|---:|---:|
| `cart.css` | 0 | 0 | 0 | 89 |
| `catalog.css` | 0 | 0 | 0 | 168 |
| `checkout.css` | 0 | 0 | 0 | 20 |
| `content.css` | 0 | 0 | 0 | 35 |
| `global.css` | 0 | 0 | 0 | 228 |
| `product.css` | 0 | 0 | 0 | 114 |

`box-shadow`: **0**. Dairesel %50 ve sıfır değerleri dışında `border-radius`: **0**. `1px` yalnız sınır/çizgi istisnasıdır.

Değişen semantik token'lar: `--focus-color: var(--color-ink-soft)`, `--duration-micro: var(--duration-normal)` (**240 ms**), `--duration-panel: var(--duration-slow)` (**420 ms**) ve `--duration-image: var(--duration-normal)` (**240 ms**). Yeni gölge, radius, renk veya sayısal süre eklenmedi.

## Kontrast

WCAG göreli parlaklık hesabı:

| Ön plan / zemin | Oran | AA 4.5:1 |
|---|---:|---|
| ink / paper | 12.93:1 | Geçti |
| muted / paper | 5.51:1 | Geçti |
| ink / sand | 11.35:1 | Geçti |
| white / ink | 13.48:1 | Geçti |
| white / ink-soft | 6.57:1 | Geçti |
| error / paper | 6.88:1 | Geçti |
| focus / paper | 6.30:1 | Geçti |
| focus / sand | 5.53:1 | Geçti |

## Genişlik × yatay taşma

Değerler `documentElement.scrollWidth - innerWidth` piksel farkıdır; paneller açıkken ölçüldü.

| Genişlik | Ana sayfa | Hesap paneli | Filtre paneli | Sepet paneli |
|---:|---:|---:|---:|---:|
| 320 | 0 | 0 | 0 | 0 |
| 390 | 0 | 0 | 0 | 0 |
| 768 | 0 | 0 | 0 | 0 |
| 1024 | 0 | 0 | 0 | 0 |
| 1280 | 0 | 0 | 0 | 0 |
| 1920 | 0 | 0 | 0 | 0 |

## Filtre örtüsü

Filtre açıkken hesaplanan örtü `paper / 0.55`; diğer paneller `ink / 0.55` oldu. Ürün görselinin hesaplanan `filter` değeri **none** kaldı. Siyah ürün açık örtü altında nötr gri/siyah, kobalt ürün mavi görünür; renkleri kahve/zeytin tonuna çeken koyu örtü filtreye uygulanmaz.

## Görsel QA

- [Sepet paneli, 1280×720](qa/faz3c-cart-panel-1280.jpg)
- [Hesap hatası, 1280×720](qa/faz3c-account-error-1280.jpg)
- [Açık filtre örtüsü, 1280×720](qa/faz3c-filter-light-overlay-1280.jpg)

## Yapılmayanlar ve gerekçesi

- Özel kimlik doğrulama, sepet matematiği, stok/fiyat hesabı ve REST sepet motoru yazılmadı; WooCommerce tek doğruluk kaynağıdır.
- Zorunlu yasal onaylar çekmeceye taşınmadı; checkout'ta kalır.
- Blocksy Pro ve yeni eklenti alınmadı; bu kapsam child theme + Woo çekirdeğiyle karşılandı.
- Safari, Firefox, iOS Safari ve Android Chrome gerçek cihaz turu yapılmadı; bu motor/cihazlar mevcut yerel tarayıcı yüzeyinde yoktur ve §39'da açık kalır.
- iyzico gerçek tahsilat testi yapılmadı; sandbox anahtarları beklenir.
