# Faz 3 Aktarma Haritası

> Faz 3D kapanışı: override bütçesi yine iki dosyadır. Sepet, hesap, mobil menü, filtre ve ürün lightbox erişilebilirliği tek `storefront.js` altyapısındadır. Sepet verisi, stok ve kimlik doğrulama WooCommerce'tir.

Bu belge prototipin React/Next yapısını üretim WordPress katmanlarına çevirmek için minimum override planıdır. Faz 2'de yalnız token katmanı taşındı; aşağıdaki bileşen portları yapılmadı.

## CSS eşlemesi

| Prototip | Child theme hedefi | Faz 2 durumu |
|---|---|---|
| `app/tokens.css` | `assets/css/tokens.css` | Ölçekler taşındı, soğuk palet sıcak marka paletiyle değiştirildi |
| `app/globals.css` | `assets/css/global.css` | Font, taban renk ve odak iskeleti var; bileşen portu Faz 3 |
| `app/catalog.css` | `assets/css/catalog.css` | 4:5 kart kuralı var; grid/filtre portu Faz 3 |
| `app/product.css` | `assets/css/product.css` | Ayrı dosya/enqueue hazır |
| `app/cart.css` | `assets/css/cart.css` | Ayrı dosya/enqueue hazır |
| `app/checkout.css` | `assets/css/checkout.css` | Klasik checkout hedefi için ayrı dosya hazır |
| `app/content.css` | `assets/css/content.css` | Gutenberg/yasal sayfa hedefi hazır |

Soğuk renkler (`#16181A`, `#EEEFEC`, `#E4E6E2`, `#616560`, `#CFD2CC`) taşınmaz. `mist` yerine `sand` kullanılır. Ürün rengi hex değerleri tema CSS'ine değil `pa_renk` terim metasına yazılır.

## Etkileşim eşlemesi

| Prototip modülü | Enqueue edilen hedef | Taşıma sınırı |
|---|---|---|
| `lib/storefront-interactions.ts` (233 satır) | `assets/js/storefront.js` | Mobil menü, filtre, sepet ve hesap için tek panel/Escape/odak döngüsü; `kuka:panel-open` yalnız bu altyapıya niyet bildirir |
| `lib/catalog-interactions.ts` (56 satır) | `assets/js/catalog.js` | Grid yoğunluğu taşınabilir; filtreleme WooCommerce query/filter bloklarına bırakılır |
| `lib/product-interactions.ts` (427 satır) | `assets/js/product.js` | Galeri/lightbox sunumu taşınabilir; varyasyon, fiyat, stok ve add-to-cart WooCommerce event/API'sine bağlanır |
| `lib/cart-interactions.ts` (244 satır) | `assets/js/cart.js` | Yalnız WooCommerce sepet formunu asenkron gönderme, fragment yenileme ve `added_to_cart` niyeti taşındı; panel odağı, localStorage, demo seed ve sepet matematiği taşınmadı |

Toplam 960 satır prototip etkileşim kodunun yaklaşık %42'si davranış fikri/odak yönetimi olarak yeniden kullanılabilir; doğrudan kopyalanabilir kod oranı markup ve veri kaynağı değiştiği için yaklaşık %20'dir.

## Minimum WooCommerce override envanteri

Faz 2'de override yoktur. Faz 3 başlamadan önce önce hook/Blocksy extension point denenir.

| Prototip bileşeni | Olası WooCommerce hedefi | Karar |
|---|---|---|
| Ürün kartı | `content-product.php` | Hook'lar yetersiz kalırsa tek override; ilk aday |
| Ürün detay bilgi paneli | `content-single-product.php` + single product hook'ları | Önce hook; tam override son çare |
| Varyasyon seçim alanı | `single-product/add-to-cart/variable.php` | Swatch DOM sözleşmesi için gerekirse dar override |
| Galeri | `single-product/product-image.php` | Blocksy Free galerisi prototipteki tek editoryal sütun, mobil snap/sayaç ve açılışta boş lightbox host sözleşmesini vermedi. `blocksy:woocommerce:product-view:use-default` filtresiyle dar override etkinleştirildi |
| Sepet satırı/sayfası | `cart/cart.php` | Önce Woo hook'ları; görsel ayrışma büyükse override |
| Klasik checkout formu | `checkout/form-checkout.php` | Alanlar hook ile; layout gerçekten gerekirse override |
| Sipariş özeti | `checkout/review-order.php` | Önce CSS/hook; override ertelendi |
| Header/footer/sepet/hesap panelleri | Child header + `inc/storefront-panels.php` | WooCommerce override değil; login formu, cart form handler ve fragment API çekirdektir |

## Site Görünümü alanları

`data/site-content.ts` Faz 4 alan sözleşmesine şu şekilde çevrilir:

| Kaynak | Panel grubu | Doğrulama/fallback |
|---|---|---|
| `marka` | Marka | URL/e-posta/telefon sanitize; wordmark fallback |
| `duyuruBandi` | Duyuru | En fazla 3 aktif kayıt; boş metin gizlenir |
| `anaHero` | Ana hero | Attachment ID, güvenli enum hizalama/renk; görsel veya başlık yoksa gizle |
| `anaSayfaBolumleri` | Bölüm sırası/içeriği | Kaynak türü allowlist; bozuk ilişki bölümü gizler |
| `navigasyon` | Menü eşlemeleri | WordPress menu ID; varsayılan yardım menüsü |
| `footer` | Footer ve şirket | Link URL sanitize; şirket yer tutucuları canlıda yayınlanmaz |
| `ticariMesajlar` | Kargo/değişim/destek | `commercial.free_shipping_threshold` çekmecede kalan tutarı üretir; pozitif sayı ve müşteri onaylı fallback |

## İçerik aktarımı

`data/catalog-copy.ts` kayıtları WordPress sayfalarına dönüşür: Koleksiyonlar, Mesafeli Satış Sözleşmesi, Ön Bilgilendirme Formu, İade ve Değişim, KVKK Aydınlatma Metni, Çerez Politikası, Gizlilik Politikası, Beden Rehberi, Kargo ve Teslimat, SSS, İletişim, Sipariş Takibi, Hakkımızda ve Hesabım. Yasal taslaklar şirket/hukuk onayı olmadan yürürlükte metin olarak yayınlanmaz.

## Veri modeli notları

`Kesim`, özel taksonomi yerine global `pa_kesim` niteliğidir. Böylece WooCommerce Attribute Filter blokları Renk ve Beden ile aynı doğal sorgu hattını kullanır; ek taksonomi sorgusu, indeks ve yönetim UI'si gerekmez. SEO değeri kanıtlanan kesimler ileride kontrollü landing page olabilir, ancak bu turda oluşturulmaz.

## Taşınmayacaklar

- React state, Next route/render yapısı ve Tailwind sınıfları: üretim runtime'ı değil.
- localStorage demo sepeti ve saf prototip sepet matematiği: gerçek sepet WooCommerce'tir.
- Prototip checkout ödeme simülasyonu: iyzico eklentisi kullanılır.
- Demo ürün kimlikleri ve statik fiyat formatlama: WooCommerce kayıtları/API'si kullanılır.
- Soğuk palet ve CSS'e gömülü ürün renkleri: marka ve global terim meta kurallarına aykırı.
- Kesim landing page'leri: müşteri listesi/SEO kararı açık.
- Ödeme imzası, webhook veya özel kombin fiyat kuralı: proje kısıtıyla yasak.

## Faz 3B gerçekleşen bileşen sınırı

- Header/footer child theme şablonlarıdır; beş inline SVG `currentColor` kullanır. Ana menü WordPress menü konumundan ve seed edilen iki seviyeli hiyerarşiden gelir.
- Katalog kartı tek WooCommerce override'ıdır; filtre sorgusu WooCommerce ana sorgusunun taksonomi/meta filtrelerine eklenir. Gerçek GET input'ları JS olmadan da çalışır.
- Renk swatch'ı `pa_renk` terim metasını okur; native varyasyon select'leri erişilebilir yedek ve WooCommerce'in tek doğruluk kaynağı olarak DOM'da kalır.
- Ürün galerisi ikinci ve son override'dır. Liste görselleri `large`, lightbox görseli yalnız açıldığında `full` kaynaktan oluşturulur.
- Blocksy parent, WooCommerce ve iyzico dosyaları değiştirilmedi. Blocksy galeri/breadcrumb extension point'leri ve iyzico page-overlay için dar child CSS seçicisi kullanıldı.

## Faz 3C panel ve hareket sınırı

- Mobil menü soldan; filtre, sepet ve hesap sağdan açılır. Sepet/hesap header'ın sağ eylemlerinden geldiği için aynı yönü paylaşır.
- Dört yan panel `data-panel-trigger`, bitişik `data-panel-overlay`, `role="dialog"`, `aria-modal`, `aria-labelledby` ve kapalıyken `inert` sözleşmesine sahiptir. Açma/kapama, Escape, Tab döngüsü, scroll kilidi ve odak iadesi yalnız `assets/js/storefront.js` içindedir.
- Sepet markup'ı `inc/storefront-panels.php` içindedir. Adet/kaldırma native WooCommerce sepet formuna nonce ile POST edilir; `woocommerce_add_to_cart_fragments` panel gövdesini ve header sayacını yeniler. JS'siz ekleme `/sepet` sayfasına yönlenir.
- Hesap paneli `woocommerce_login_form()` ve WooCommerce endpoint URL'lerini kullanır. Başarısız girişte Türkçe metinli `role="alert"` paneli yeniden açar; kayıt seçeneği native Hesabım sayfasında aktiftir.
- `--duration-micro` 240 ms, `--duration-panel` 420 ms'tir. Menü çizgisi ve giriş hareketi `--ease-out`, buton/chip/durum değişimleri `--ease-standard` kullanır. Reduced-motion global olarak sıfırlar.
- Dört yan panelin tamamı açık `paper` %55 örtü kullanır; ürün görsellerinde `filter` uygulanmaz.

## Faz 3D sadakat ve panel tamamlama

- Ana sayfa kategori/kesim indeksi `product_cat` ve `pa_kesim` kayıtlarından sunucu tarafında üretilir. Karttaki swatch, SKU ve beden/stok satırı WooCommerce varyasyon verisidir; iki kart katmanı Site Görünümü'nden ayrı ayrı kapatılabilir.
- Kök `html/body` yatay taşma maskelemesi kaldırıldı. Beş rota 320–1920 aralığındaki altı hedef genişlikte 30/30 kez sıfır taşmayla ölçüldü; ürün bilgi paneli 1280'de `top:80px` sticky eşiğinde kalır.
- Ürün lightbox tetikleyicileri `data-panel-trigger` sözleşmesine geçti. `product.js` yalnız galeri/zoom durumunu tutar; `inert`, Tab, Escape ve odak dönüşü `storefront.js` sorumluluğudur.
- Site Görünümü sekiz gruba tamamlandı. Marka, duyuru bağlantıları, bölüm kaynakları/görünürlüğü, kart anahtarları, footer bağlantıları, kargo durum metinleri ve hesap karşılama metni doğrulanmış varsayılanlarla ön yüze bağlıdır.
- `Kuka Island` Gutenberg kategorisinde iki `templateLock:all` desen bulunur. Günlük `kuka_manager` kullanıcısı `shop_manager` rolündedir; menüsü içerik, medya, WooCommerce, ürünler, raporlar ve Site Görünümü işlerine odaklıdır.
