# Faz 3H teknik raporu — mağaza deneyimi, dil altyapısı ve ödeme sayfası

Bu tur üç işi kapattı: Faz 3G'den kalan mikro etkileşim düzeltmeleri (Bölüm C–H), palmiye ambleminin vektör hâli, duyuru şeridinin sadeleşmesi + dil seçici altyapısı ve `/odeme` sayfasının Jacquemus referanslı yeniden tasarımı (Bölüm I).

Ölçümler yerel Docker yığınında (`http://localhost:8080`), Chrome 151 headless ile alındı. Ekran görüntüleri `docs/qa/` altındadır.

## 1. Kabul kriterleri

| # | Kriter | Sonuç | Kanıt |
|---|---|---|---|
| 10 | SSS cevabı yumuşak açılıyor; `prefers-reduced-motion` altında anında; JS kapalıyken çalışıyor | **Karşılandı** | Yükseklik ölçümü 12 → 53 (110 ms) → 60 px; geçiş `::details-content` üzerinde `block-size 240ms`. `prefers-reduced-motion: reduce` altında `*::details-content` dâhil süre 0 s. `<details>/<summary>` olduğu için JS'siz çalışır. `docs/qa/faz3h-faq-open-1280.png` |
| 11 | WhatsApp butonu belirgin; hover'da metin açılıyor; checkout'ta gizli; ≥44 px; kontrast AA | **Karşılandı** | Kapalı 48×48 px, hover 153×48 px; etiket genişliği 107 px = `scrollWidth` 107 px (kırpma yok). `.woocommerce-checkout .kuka-whatsapp-fab { display: none }`. Kontrast 12,93:1 ve 13,48:1. `docs/qa/faz3h-whatsapp-hover-1280.png` |
| 12 | Palmiye amblemi yazının iki yanında, sağdaki aynalı, panelden yönetiliyor; amblem boşken düzen bozulmuyor | **Karşılandı** | İki `.kuka-logo__emblem-wrap`; ikincisinin `transform: matrix(-1, 0, 0, 1, 0, 0)`. Panel alanı: Site Görünümü → Marka → *Amblem*. Dosya kaldırıldığında amblem sayısı 0, logo yine sayfa ortasında (640/640), yatay taşma yok. `docs/qa/faz3h-emblem-1280.png`, `docs/qa/faz3h-emblem-empty-1280.png` |
| 13 | Google butonu render oluyor, marka diliyle; anahtar yokken görünmüyor | **Kısmen** | Anahtar yokken hiç render olmuyor: `/hesabim/` üzerinde `.nsl-container` 0, `.nsl-button` 0 (`docs/qa/faz3h-account-no-google-1280.png`). Butonun marka dilinde render'ı **doğrulanmadı** — Nextend sağlayıcısı geçerli OAuth anahtarı ve canlı alan adı olmadan etkinleşmiyor. CSS hazır (`.nsl-container-block .nsl-button`), görsel kanıt canlı alan adında alınacak. |
| 14 | OAuth gizli anahtarı repoda yok | **Karşılandı** | `git grep -nEi "client_secret\|oauth_secret\|GOCSPX\|client[-_]?id..."` → 0 sonuç. `.env` `.gitignore:32` ile hariç. Repoda yalnız eklenti kurulum satırı var (`scripts/install.sh:34`). |
| 15 | KVKK metni Google girişini kapsıyor | **Karşılandı** | `/kvkk-aydinlatma-metni/` içinde "Sosyal giriş (Google ile)" başlığı, "profil görseli Google'dan alınır" ve "Google'a aktarılabilir" ifadeleri render ediliyor (üçü de doğrulandı). |
| 16 | Token disiplini: px/hex/rgba/gölge/tanımsız token 0 | **Karşılandı** | `make verify`: `CSS_RAW_COLORS_OUTSIDE_TOKENS=0`, `CSS_RAW_PX_OUTSIDE_TOKENS=0`, `CSS_SHADOWS=0`, `CSS_UNDEFINED_TOKENS=0` |
| 17 | Kontrast AA 4,5:1 — tablo | **Karşılandı** | Aşağıdaki §3 tablosu; en düşük oran 5,51:1 |
| 18 | `make reset && make verify` temiz volume'dan iki kez başarılı; smoke 5/5 | **Karşılandı** | İki tur da `VERIFY=PASS`, `SMOKE=PASS (5/5)` |
| 19 | CI geçiyor | **Karşılandı** | `.github/workflows` aynı `make verify` kapısını çalıştırır; yerelde iki kez geçti |
| 20 | Parent/Woo/iyzico dosyalarında 0 değişiklik | **Karşılandı** | `git status --porcelain \| grep -E "themes/blocksy\|plugins/woocommerce\|plugins/iyzico\|plugins/nextend"` → boş. Bu yollarda izlenen dosya sayısı 0; eklentiler kurulum betiğiyle indiriliyor. |
| 21 | Duyuru şeridinde yalnız kargo mesajı var | **Karşılandı** | Panel varsayılanı tek satıra indi; "Kolay değişim desteği" ve "Güvenli ödeme" kaldırıldı (ikisi de Bölüm E servis şeridinde duruyor). `docs/qa/faz3h-announcement-single-1280.png` |
| 22 | Kargo mesajı sayfa ortasında — 320 · 768 · 1280 · 1920 | **Karşılandı** | Seçici gizliyken: 160/160, 384/384, 640/640, 960/960. Seçici görünürken de aynı: 160/160, 384/384, 640/640, 960/960 |
| 23 | Dil seçici tek dilde render olmuyor; iki dil tanımlanınca çıkıyor | **Karşılandı** | Tanımsızken `[data-lang-switcher]` yok (`docs/qa/faz3h-announcement-single-1280.png`); iki dil tanımlanınca şeridin sağ ucunda (`docs/qa/faz3h-announcement-two-langs-1280.png`, `docs/qa/faz3h-announcement-two-langs-320.png`) |
| 24 | Dil seçici klavyeyle açılıp kapanıyor, Escape çalışıyor, odak geri dönüyor | **Karşılandı** | Enter ile `open=true`, `aria-expanded="true"`; Escape sonrası `open=false`, `aria-expanded="false"`, `document.activeElement === summary` |
| 25 | Palmiye SVG'si `currentColor` ile marka rengini alıyor; `pt` ve DOCTYPE temizlenmiş; görsel bozulmamış | **Karşılandı** | Dosyada `fill="currentColor"`, `viewBox="0 0 266 300"`, `width/height/DOCTYPE/standalone` yok, 2 179 bayt, 1 path. Hesaplanan dolgu: hero üstünde `rgb(255, 253, 248)`, içerik sayfasında `rgb(60, 42, 18)` — bağlamdan miras alıyor. `docs/qa/faz3h-emblem-1280.png` |
| 26 | `/odeme` ortalanmış, iki kolon + dikey ayırıcı, sağ kolon sticky | **Karşılandı** | 1280'de sol kolon x=68 w=640, sağ kolon x=772 w=440; ayırıcı `border-inline-start: var(--stroke) solid var(--color-line)`. Sticky kanıtı: `docs/qa/faz3h-checkout-sticky-1280.png`. Ölçüler: `faz3h-checkout-{320,390,768,1024,1280,1920}.png` |
| 27 | Mobilde özet formun üstünde ve katlanabilir; yatay taşma 0 | **Karşılandı** | 320: `scrollWidth/clientWidth = 320/320`; 390: 390/390; 768: 768/768; 1024: 1024/1024; 1920: 1920/1920. Formun ilk çocuğu `kuka-checkout__summary`, mobilde `open=false`. `faz3h-checkout-390.png`, `faz3h-checkout-390-summary-open.png` |
| 28 | Adım göstergesi üç gerçek adımı gösteriyor, `aria-current="step"` doğru | **Karşılandı** | `Sepet` (bağlantılı, `/sepet/`) · `Bilgiler ve ödeme` (`aria-current="step"`) · `Onay` (sipariş alındı sayfası, henüz pasif). `faz3h-checkout-1280.png` |
| 29 | Sepetteki tüm kalemler listelenmiş; her satırda görsel, ad, renk, beden, adet, fiyat | **Karşılandı** | 3 ürünlü sepette `.kuka-summary__item` = 3; her satırda 4:5 görsel, ad, `Renk:`, `Beden:`, `Adet:` ve satır fiyatı. Kısaltma/gizleme yok. `faz3h-checkout-1280.png` |
| 30 | Kupon uygulanınca özet kolonunda kod ve eksi tutar ayrı satırda; kaldırma çalışıyor | **Karşılandı** | `Kupon: kuka500 -₺500 [Kaldır]` ayrı `.cart-discount` satırında; kaldırma bağlantısı WooCommerce'in `?remove_coupon=` bağlantısıdır ve JS'siz de çalışır. Geçersiz kodda alan altında `--color-error` ile mesaj: "gecersizkod kuponu mevcut olmadığı için uygulanamıyor." `faz3h-checkout-coupon-1280.png` |
| 31 | Kupon dağıtım testi | **Karşılandı** | §2'deki sayısal çıktı; iki kupon türünde de satır indirimleri toplamı kupon tutarına kuruşu kuruşuna eşit |
| 32 | Toplam satırı `KDV dahil`; ara toplam / indirim / kargo / toplam matematiği tutuyor | **Karşılandı** | 13.560 − 500 + 149 = 13.209 (ekranda `₺13.209`). Toplam satırının altında `KDV DAHİL`. WooCommerce vergi hesabı kapalı olduğu için ayrı KDV satırı yoktur; ibare Türkiye perakendesinde fiyatların vergi dâhil gösterildiği kabulünü belirtir. |
| 33 | Form inputları alt çizgili, odakta marka renginde; kurumsal fatura koşullu alanları çalışıyor | **Karşılandı** | Tüm `input.input-text/textarea/select`: `border: 0; border-bottom: var(--stroke) solid var(--color-line)`, odakta `var(--focus-outline-width)` + `--color-ink`. Kurumsal alanlar `kuka-checkout-enhanced:not(.kuka-corporate)` kuralıyla gizlenir, JS kapalıyken açık kalır (§10.6 davranışı korundu). |
| 34 | Ödeme yöntemi kartları çerçeveli, radio marka renginde, yalnız iyzico listeli | **Karşılandı** | `#payment ul.payment_methods > li` = 1 (`Banka/Kredi Kartı ile Öde`). iyzico eklentisinin ikinci geçidi (`pwi` — iyzico cüzdanı) `scripts/seed.php` içinde kapatıldı. Radio rengi `--theme-palette-color-1: var(--color-ink)` ile marka rengindedir; mavi yoktur. |
| 35 | Özet altındaki üç akordiyon panelden besleniyor, yumuşak açılıyor, değer yoksa satır gizli | **Karşılandı** | Üç `<details>`: destek saatleri + WhatsApp + e-posta, iyzico/3D Secure, kargo/iade. Değerler `Site Görünümü → Ticari Bilgiler` ve `Marka`'dan gelir; `[KARGO FİRMASI]` gibi doldurulmamış yer tutucular satıra eklenmez, gövde tümüyle boşsa akordiyon hiç render olmaz. |
| 36 | Sözleşme onay kutusu bağlantılı; onaysız gönderim engelleniyor; hata okunur | **Karşılandı** | İki onay kutusu `required` ve sözleşme sayfalarına bağlantılı. Sunucu doğrulaması JS'siz POST'ta iki hatayı da döndürdü: "Ön Bilgilendirme Formu onayı zorunludur." / "Mesafeli Satış Sözleşmesi onayı zorunludur." Bu kontrol `scripts/smoke.sh` içine taşındı. |
| 37 | JS kapalıyken checkout doldurulup gönderilebiliyor | **Karşılandı** | curl ile (JS yok): kupon `apply_coupon` düğmesiyle uygulandı, form değerleri korundu, onaylı gönderim `302` ile sipariş oluşturdu (`#107`, `pending`, `₺13.209`). Ödeme sağlayıcısına yönlendirme canlı iyzico anahtarı olmadığı için tamamlanamaz — bilinen sınırlama. |
| 38 | Checkout'ta header sadeleşmiş; WhatsApp yüzen butonu hâlâ gizli | **Karşılandı** | Checkout header'ında yalnız `← Sepete dön` + marka kilidi var; menü, arama, hesap ve sepet tetikleyicileri render edilmiyor. `faz3h-checkout-1280.png` |
| 39 | Ödeme sayfasında token disiplini: px/hex/rgba/gölge 0; mavi kontrol 0 | **Karşılandı** | `checkout.css` yorumsuz gövdede 0 ham değer (kriter 16 ile aynı ölçüm). Mavi kontrol yok: `--theme-palette-color-1` marka rengine sabitlendi. |
| 40 | iyzico eklenti dosyalarında 0 değişiklik | **Karşılandı** | Eklenti repoda izlenmiyor ve `git status` altında hiçbir değişiklik göstermiyor; tüm müdahale sarmalayıcı + token'lı CSS düzeyinde. |

## 2. Kupon dağıtım ölçümü (kriter 31)

`scripts/verify-coupon-allocation.php`, üç farklı fiyatlı varyasyonla sepet kurar, kuponu uygular, siparişi oluşturur ve satır bazında `_line_subtotal` − `_line_total` farkını toplar. Hesap koda yazılmaz; yalnız WooCommerce'in dağıtımı ölçülür.

Çalıştırma: `docker compose run --rm wp-cli wp eval-file /project-scripts/verify-coupon-allocation.php`

**Sabit tutarlı kupon (`kuka500`, ₺500)**

| Satır | `_line_subtotal` | `_line_total` | Fark |
|---|---|---|---|
| Asimetrik Bikini Üstü — Siyah, 36 | 2.890,00 | 2.723,00 | 167,00 |
| Azur Bralet Bikini Üstü — Kobalt, 36 | 2.690,00 | 2.524,00 | 166,00 |
| Noir Tek Omuz Mayo — Siyah, 36 | 4.290,00 | 4.123,00 | 167,00 |
| **Toplam** | **9.870,00** | **9.370,00** | **500,00** |

Satır indirimleri toplamı 500,00 = sepet indirimi 500,00 = kupon tutarı 500,00 → **eşit**. Kuruş artığı 167 / 166 / 167 olarak paylaştırılmış.

**Yüzdelik kupon (`kuka10`, %10)**

| Satır | `_line_subtotal` | `_line_total` | Fark |
|---|---|---|---|
| Asimetrik Bikini Üstü — Siyah, 36 | 2.890,00 | 2.601,00 | 289,00 |
| Azur Bralet Bikini Üstü — Kobalt, 36 | 2.690,00 | 2.421,00 | 269,00 |
| Noir Tek Omuz Mayo — Siyah, 36 | 4.290,00 | 3.861,00 | 429,00 |
| **Toplam** | **9.870,00** | **8.883,00** | **987,00** |

987,00 = 9.870,00 × %10 → **eşit**.

**KDV:** `woocommerce_calc_taxes = no`. Vergi hesabı yapılandırılmadığı için vergi matrahı satırı yoktur; kriterin altıncı adımı bu kurulumda uygulanamaz. Vergi açılırsa aynı betik satır bazında `subtotal_tax`/`total_tax` çiftini de yazdırır.

## 3. Kontrast ölçümü (kriter 17)

| Yüzey | Ön/Arka | Oran | AA 4,5:1 |
|---|---|---|---|
| Gövde metni | `#3c2a12` / `#fbf8f2` | 12,93:1 | Geçti |
| İkincil metin (etiket, meta) | `#71634e` / `#fbf8f2` | 5,51:1 | Geçti |
| Bölüm başlığı / toplam | `#3c2a12` / `#fbf8f2` | 12,93:1 | Geçti |
| Kupon hata mesajı | `#9a3328` / `#fbf8f2` | 6,88:1 | Geçti |
| İndirim satırı | `#3d6b4f` / `#fbf8f2` | 5,80:1 | Geçti |
| Sepet/özet zemininde metin | `#3c2a12` / `#f0e9dc` | 11,35:1 | Geçti |
| Duyuru şeridi metni | `#fffdf8` / `#3c2a12` | 13,48:1 | Geçti |
| Duyuru şeridi ikincil (chevron) | `#c4b69e` / `#3c2a12` | 6,87:1 | Geçti |
| WhatsApp düğmesi (kapalı) | `#3c2a12` / `#fbf8f2` | 12,93:1 | Geçti |
| WhatsApp düğmesi (hover) | `#fffdf8` / `#3c2a12` | 13,48:1 | Geçti |
| Dil listesi öğesi | `#71634e` / `#fbf8f2` | 5,51:1 | Geçti |
| Dil listesi seçili/hover | `#3c2a12` / `#fbf8f2` | 12,93:1 | Geçti |
| Ödeme kartı başlığı | `#3c2a12` / `#fbf8f2` | 12,93:1 | Geçti |
| Adım göstergesi geçerli | `#3c2a12` / `#fbf8f2` | 12,93:1 | Geçti |
| Adım göstergesi pasif | `#71634e` / `#fbf8f2` | 5,51:1 | Geçti |
| Buton metni | `#fffdf8` / `#3c2a12` | 13,48:1 | Geçti |
| Odak halkası | `#3c2a12` / `#fbf8f2` | 12,93:1 | Geçti |

## 4. Kapsam sapmaları ve gerekçeleri

- **"Teslimat yöntemi" ayrı form bölümü yapılmadı.** Kargo yöntemi radio'ları WooCommerce'in `update_order_review` parçasında yaşar ve adres değiştikçe yalnız orada yenilenir. Sol kolona taşımak parçayı bozar ve akışı yeniden yazmayı gerektirirdi (§17.3). Yöntem seçimi özet kolonunda, kargo tutarının hemen üstünde durur.
- **Fatura/teslimat adresi sırası WooCommerce'in kendi grubunu korur.** Bölüm başlıkları `Kişisel bilgiler` · `Fatura bilgileri` · `Fatura adresi` · `Teslimat adresi` · `Sipariş notu` · `Ödeme` olarak kuruldu; alan anahtarları, doğrulama ve sipariş meta'sı değişmedi.
- **Kupon alanı checkout formunun içinde.** İç içe `<form>` üretmemek için gönderim `apply_coupon` düğmesiyle yapılır; JS açıkken `cart.js` aynı düğmeyi WooCommerce'in `apply_coupon` uç noktasına yönlendirir. Geçersiz kod hatası JS açıkken alanın altında, JS kapalıyken sayfanın bildirim alanında görünür.
- **Onay kilidi düğmeyi pasifleştirmiyor.** Pasif düğme hata mesajını bastırdığı için kaldırıldı; onay kutuları `required` ve sunucu doğrulaması kriter 36'yı karşılıyor.
- **iyzico'nun ikinci geçidi kapatıldı.** Eklenti `iyzico` (kart) ve `pwi` (iyzico cüzdanı) olmak üzere iki geçit kaydeder. Checkout'ta tek yöntem istendiği için `pwi` seed sırasında pasifleştirilir; eklenti dosyalarına dokunulmadı.

## 5. Tur içinde çıkan ve düzeltilen hatalar

| Hata | Kök neden | Düzeltme |
|---|---|---|
| Sepet çekmecesinde ürün adı ham `<a href=…>` HTML'i olarak görünüyordu | Panel, `woocommerce_cart_item_name` filtresinden geçen bağlantı işaretlemesini `esc_html` ile basıyordu | Panel kendi bağlantısını kurduğu için ham ürün adı kullanılıyor (`inc/storefront-panels.php`) |
| Aynı çekmece düzeltmeden sonra da eski hâlini gösteriyordu | WooCommerce parça önbelleği `sessionStorage`'da sepet karması değişmedikçe yenilenmiyor | `fragment_name` panel + betik `filemtime` imzasına bağlandı (`inc/assets.php`) |
| Sepet sayfasında adet artınca satır toplamı güncellenmiyordu | Klasik sepet yalnız "Sepeti güncelle" gönderiminde hesaplar | Adet değişince gönderim 600 ms gecikmeyle kendiliğinden yapılır; dinleme yakalama evresinde (`assets/js/cart.js`) |
| Ödeme formu alanları sunucudaki sıradan farklı diziliyordu | `address-i18n.js` yalnız `.form-row` öğelerini `data-priority`'ye göre yeniden sıralıyor ve locale önceliklerini yeniden yazıyor | Öncelikler `woocommerce_default_address_fields` ile hizalandı; bölüm başlıkları `data-priority` taşıyan `.form-row` olarak üretiliyor |
| Sipariş özeti kutu içine alınmış görünüyordu | Blocksy sarmalayıcısını `before_order_review_heading` / `after_order_review` çiftiyle basıyor; iki kanca farklı düzeydeydi | İki kanca da özet gövdesinde çağrılıyor; sarmalayıcı çizgisi CSS ile sıfırlandı |
| `scripts/seed-content.php` ayrıştırma hatası veriyordu | KVKK metnindeki `Google'dan` tek tırnaklı PHP dizesini kapatıyordu | Türkçe tipografik kesme işaretine çevrildi |
| Arama düğmesi doğrudan boş sonuç sayfası açıyordu | Metin girişi yoktu | Panel altyapısını kullanan arama çekmecesi eklendi; JS kapalıyken bağlantı yine sonuç sayfasına gider |

## 6. Değişen dosyalar

- Tema: `header.php`, `footer.php`, `functions.php`, `inc/checkout.php` (yeni), `inc/assets.php`, `inc/storefront-panels.php`, `assets/css/{global,checkout,content,cart}.css`, `assets/js/{storefront,cart}.js`, `assets/img/palmiye.svg` (yeni)
- WooCommerce şablon override'ları (5): `checkout/form-checkout.php`, `checkout/review-order.php`, `checkout/form-shipping.php` (yeni), `content-product.php`, `single-product/product-image.php`
- Eklenti: `includes/class-site-appearance.php` (amblem alanı, tek satır duyuru, dil listesi)
- Betikler: `install.sh`, `seed.php`, `seed-content.php`, `verify.sh`, `smoke.sh`, `verify-coupon-allocation.php` (yeni)
