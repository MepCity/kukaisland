# Faz 2 Teknik Pilot Raporu

Ölçüm tarihi: 4 Ağustos 2026. Ortam: WordPress 7.0.2, WooCommerce 10.9.4, Blocksy/Companion 2.1.51, iyzico for WooCommerce 3.5.28, PHP 8.3 konteynerleri ve MariaDB 11.4.

## Kurulum ve veri kanıtı

`make reset && make verify` iki ardışık turda, her seferinde WordPress ve MariaDB volume'ları kaldırılarak başarıyla tamamlandı. İki turda da sonuç aynıydı: 4 variable ürün, 50 varyasyon, 35 varyasyonlu `KI-TOP-001`, 6/6 swatch meta ve tüm HPOS/locale/görsel ayarları geçti. İlk geliştirme denemesinde bulunan “yeni nitelik taksonomisi aynı PHP isteğinde hazır değil” hatası, schema ve veri seed'ini iki ayrı WP-CLI isteğine ayırarak düzeltildi; kanıt temiz volume turlarından alınmıştır.

`make verify` ölçümü:

- Dil `tr_TR`, zaman dilimi `Europe/Istanbul`, para birimi `TRY`
- Aktif tema `kuka-island-child`; parent `blocksy`
- HPOS `yes`, misafir checkout `yes`
- 3 global nitelik; Beden tek listede 10 terim
- 4 variable ürün, toplam 50 varyasyon
- `KI-TOP-001`: 35 varyasyon; 35/35 SKU, stok, düşük stok eşiği ve gerçek Blocksy özel galeri metası mevcut
- 6 Renk teriminin 6/6'sında `kuka_swatch_hex` meta değeri var

35 varyasyonlu ürün seed ve tekrar-seed sırasında hata vermedi. WooCommerce `get_children()` sonucu 35; varyasyonlar yönetilen stokla senkronlandı. Yönetim panelinde kombinasyon patlaması bu ölçekte teknik olarak çalışıyor, ancak tek ürünün elle bakımı 35 satırda zaman alacağı için gerçek katalogda gereksiz renk×beden kombinasyonu üretilmemeli.

## Marka kontrastı

WCAG göreli parlaklık formülüyle ölçüldü.

| Kullanım | Oran |
|---|---:|
| ink `#3C2A12` / paper `#FBF8F2` | 12.93:1 |
| ink / sand `#F0E9DC` | 11.35:1 |
| muted `#71634E` / paper | 5.51:1 |
| muted / sand | 4.84:1 |
| muted-on-ink `#C4B69E` / ink | 6.87:1 |
| warm white `#FFFDF8` / ink | 13.48:1 |
| error `#9A3328` / paper | 6.88:1 |
| success `#3D6B4F` / paper | 5.80:1 |

## Görsel hattı

13 gerçek demo JPEG ölçüldü. Ürün dosyaları 900×1200 veya 1086×1448; hero dosyaları 1122×1402 ve 1672×941. En uzun gerçek kenar 1672 px. `big_image_size_threshold` 2000 px seçildi: ölçülen maksimumun 328 px üzerinde, WordPress varsayılan 2560 px'in altında. İçe aktarılan 13 dosyanın `-scaled` üreten sayısı 0'dır; filtre kör biçimde kapatılmadı.

| Kullanım | Kayıtlı/ölçülen boyut |
|---|---|
| Katalog kartı | 600×750, zorunlu 4:5 crop |
| Ürün detay | 1080 px genişlik, crop yok |
| Galeri thumbnail | 180×240, 3:4 crop |
| Zoom | özgün dosya; ürünlerde en çok 1086×1448 |

## Kombin adayları

Gereksinim numaraları: (1) ayrı ürün/fiyat, (2) ortak fotoğraf, (3) bağımsız beden, (4) ayrı kombin fiyatı, (5) panel eşlemesi, (6) bileşen stoğundan türetme.

| Aday | Karşıladığı | Karşılamadığı / ücretsiz sınırı | Sonuç |
|---|---|---|---|
| WooCommerce Grouped Product | 1, 2, 5; stok parçaların kendi kayıtlarında | Render ölçümünde 2 variable çocuk için 0 inline variation formu ve 0 attribute select; 4 “seçenek seç” bağlantısı ayrı ürün sayfasına gidiyor. 3 ve 4 yok; tek kombin sepet satırı yok | Elendi |
| WPC Product Bundles Free 8.5.9 | Basit ürünlerde bundle fiyatı/sepet ve bileşen stok yaklaşımı | Resmî özellik tablosunda variable ürün veya belirli variation ekleme Premium; 3 yok. Üst/alt beden seçilemediğinden 6 hedefi bu katalogda test edilemez | Ücretsiz katman elendi; kurulmadı |
| YITH Product Bundles Free | Basit ürün bundle oluşturma | Resmî Free/Premium tablosunda variable ürünler Premium. Ayrı beden seçimi, sabit kombin fiyatı ve stok türetme birlikte ücretsiz değil | Ücretsiz katman elendi; kurulmadı |
| Özel kod | Panel ilişkisi Core'da saklanabilir | Özel kombin fiyatı, tek sepet satırı ve iki variation stok düşümü sepet/fiyat kuralını yeniden yazmayı gerektirir; §17.3 yasak | Kapalı |

Karar: ücretsiz adayların hiçbiri altı şartı birlikte karşılamıyor. Ayrı kombin fiyatı ticari zorunluluk olarak korunduğu için “Takımı tamamla / iki ayrı sepet satırı” modeli de madde 4'ü karşılamaz. Satın alma yapılmadı. Premium kısa liste ancak müşteri sabit kombin fiyatını onayladıktan sonra, sandbox üzerinde WPC Premium ve YITH Premium ile gerçek iki variation stok düşümü test edilerek verilebilir; doküman vaadi satın alma kararı için tek başına yeterli değildir.

Kaynaklar: [WooCommerce Additional Checkout Fields API](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/), [WPC Free özellikleri](https://wordpress.org/plugins/woo-product-bundle/), [YITH Free/Premium karşılaştırması](https://yithemes.com/themes/plugins/yith-woocommerce-product-bundles/).

## Checkout mimarisi kararı

Karar: **klasik checkout** (`[woocommerce_checkout]`).

Her iki yolun yapısal testi:

| Kriter | Checkout Blocks | Klasik checkout |
|---|---|---|
| Kurumsal alanlar | Woo 10.9.4'te `woocommerce_register_additional_checkout_field` mevcut; text/select/checkbox destekli. Ancak bireysel/kurumsal seçime göre VKN ve vergi dairesini koşullu gösterip zorunlu kılmak ek Store API validation + front-end block extension gerektiriyor | Desteklenen `woocommerce_checkout_fields`, render ve validation hook'larıyla koşullu alan grubu mümkün; ödeme akışı yeniden yazılmaz |
| İki zorunlu onay | İki required checkbox API ile mümkün ve Store API doğrular | `woocommerce_review_order_before_submit` + `woocommerce_checkout_process` ile mümkün |
| iyzico | 3.5.28 kaynağı `woocommerce_blocks_payment_method_type_registration` ile iki block yöntemi kaydediyor ve `cart_checkout_blocks` uyumluluğunu `true` bildiriyor | `WC_Payment_Gateway` yolu mevcut ve resmî eklentinin geleneksel akışı |
| HPOS | iyzico `custom_order_tables=true` bildiriyor | Aynı bildirim geçerli |

Blocks teknik olarak mümkün olsa da bu mağazanın belirleyici alanı koşullu kurumsal fatura grubudur. Klasik checkout bu davranışı daha küçük, desteklenen ve bakımı daha kolay bir hook yüzeyiyle sağlar. Core'daki kurumsal/yasal modüller Faz 2'de placeholder bırakıldı; hukuk metni ve alan davranışı Faz 5'te bu hook'larla uygulanacak. iyzico gateway anahtarsız bırakıldı ve etkinleştirilmedi; canlı anahtar kullanılmadı.

Kaynaklar: [WooCommerce ek alan API'si](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/), [iyzico resmî WooCommerce dokümanı](https://docs.iyzico.com/platformlar/woocommerce), [iyzico WordPress eklentisi](https://wordpress.org/plugins/iyzico-woocommerce/).

## Blocksy Pro ölçümü

Tahminler Faz 3 kapsamındaki özel geliştirme + temel QA saatleridir; teklif değildir.

| Özellik | Blocksy Free özel iş | Pro hazır mı / tahmini kurulum | Tasarruf | Prototip DOM aktarımı |
|---|---:|---:|---:|---|
| Variation swatch | 8–12 saat | Evet, 1–2 saat | 7–10 saat | `product-interactions` seçim fikri %35; Woo event'leri yeniden bağlanır |
| Chip grid filtre/drawer | 12–18 saat | Shop Filters + swatch ile 4–6 saat | 8–12 saat | `catalog-interactions` yaklaşık %55; sorgu mantığı taşınmaz |
| Sepet çekmecesi | 12–18 saat | Shop Extra ile 2–4 saat | 10–14 saat | `storefront` odak/panel %45; `cart-interactions` veri kısmı %0 |
| Favoriler | 16–24 saat | Evet, 2–4 saat | 14–20 saat | Yalnız toggle sunumu yaklaşık %15; kalıcılık/hesap sıfırdan |
| Beden rehberi modalı | 8–12 saat | Evet, 2–3 saat | 6–9 saat | Ortak dialog/odak davranışı yaklaşık %45 |

Toplam Free özel geliştirme: 56–84 saat. Pro ile yapılandırma/uyarlama: 11–19 saat. Brüt tahmini tasarruf: **45–65 saat**. Buna rağmen satın alma yapılmadı; fiyat/lisans kararı müşteri adına ve güncel teklif görülerek verilir. Product Variations Gallery Free'de mevcut olduğundan galeri Pro gerekçesi değildir.

Kaynaklar: [Blocksy Free Product Variations Gallery](https://creativethemes.com/blocksy/docs/woocommerce/product-variations-gallery/), [Blocksy Pro Variation Swatches](https://creativethemes.com/blocksy/docs/woocommerce/variation-swatches/), [Blocksy Shop Extra](https://creativethemes.com/blocksy/docs/extensions/woocommerce-extra/).

## Bekleyen kullanıcı işleri

- iyzico sandbox API anahtarı ve secret; ardından iki checkout mimarisinden seçilen klasik akışta başarılı/başarısız/3D/iptal-iade testi
- SVG logo, yatay lockup, açık/koyu varyant ve favicon; font/lisans kararı
- Kesim listesi, kargo firması, 149 TL geçici sabit ücret ve 3000 TL geçici ücretsiz kargo eşiğinin onayı
- Kombin ayrı fiyat zorunluluğunun ve ücretli bundle pilot bütçesinin onayı
- Şirket/VKN/adres/ETBİS ve hukuk onaylı metinler

## Yapılmayanlar

- Tasarım Faz 3'e taşınmadı; yalnız token ve enqueue iskeleti kuruldu.
- Kesim landing page'i oluşturulmadı.
- Ücretli eklenti/lisans satın alınmadı; bundle adayları aktif mağaza eklentisi yapılmadı.
- Canlı veya sandbox iyzico işlemi yapılmadı; anahtar yok.
- Parent Blocksy, WooCommerce ve iyzico dosyaları değiştirilmedi.
- Kart tahsilatı, imza, webhook ve özel kombin fiyat/stock motoru yazılmadı.
- Deploy/hosting işlemi yapılmadı.

## Kabul durumu

Sandbox iyzico işlemi anahtar beklediği için Faz 2'nin PLAN §28 sandbox kabul cümlesi **karşılanmadı**. Yapısal Blocks/HPOS uyumluluğu karşılandı. Gerçek tarayıcı checkout ödeme testi de anahtar gelene kadar bekliyor. Diğer ölçülebilir depo ve local pilot kriterleri `make verify`, Git kapsam kontrolü ve **2/2 başarılı temiz volume kurulumu** ile kapanır.
