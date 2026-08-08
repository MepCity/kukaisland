# Faz 3B teknik raporu

Tarih: 4 Ağustos 2026  
Ortam: `http://localhost:8080`  
Kapsam: prototip bileşen yapısının Blocksy child theme ve Kuka Island Core bağlantılarına aktarılması

## Sonuç

Faz 3A'nın renk ağırlıklı katmanı gerçek bileşen yapısına çevrildi. Header/footer child şablonları, WordPress menü hiyerarşisi, editoryal ürün kartı, WooCommerce sorgusuna bağlı filtre çekmecesi, `pa_renk` meta tabanlı swatch'lar ve özel ürün galerisi çalışır. Parent tema ve vendor eklenti dosyası değişmedi.

WooCommerce override sayısı **2**:

1. `woocommerce/content-product.php`: 4:5 kart, görsel gezintisi, kesim/renk/fiyat anatomisi ve erişilebilir indirim ayrımı.
2. `woocommerce/single-product/product-image.php`: masaüstü editoryal akış, mobil scroll-snap/sayaç ve açılışta boş özel lightbox.

## Kaynak → üretim eşlemesi

| Kaynak | Üretim karşılığı |
|---|---|
| `components/Icons.tsx` | `kuka_island_icon()` içindeki beş `currentColor` SVG |
| `Header`, `Footer`, panel sözleşmesi | `header.php`, `footer.php`, `storefront.js` |
| `ProductCard` | `content-product.php`, `catalog.js`, `catalog.css` |
| `FilterDrawer` | `inc/catalog-filters.php`; gerçek GET input'ları ve WooCommerce tax/meta query |
| `ProductDetail` | dar galeri override'ı, `product.js`, `product.css` |
| Tasarım token'ları | tek doğruluk kaynağı `assets/css/tokens.css` |

## Etkileşim kanıtı

- Ana menü iki seviyeli WordPress menüsüdür: `Yeni Gelenler`, `Bikini > Bikini Üstleri / Bikini Altları / Takımlar`, `Mayo`, `Plaj Giyim`, `Koleksiyonlar`, `Hikâyemiz`.
- Filtre çekmecesi `role="dialog"`, `inert`, Escape, odak dönüşü ve gerçek form submit sözleşmesini geçti. `?ki_cut[]=asimetrik` sunucu yanıtında sonuç sayısı 4'ten 1'e indi ve kaldırılabilir `Asimetrik` chip'i göründü.
- Kart oku URL'i değiştirmeden aktif görseli `0 → 1` yaptı; sayaç `1 / 3 → 2 / 3` oldu. Kontroller native düğme olduğu için klavye ve dokunma girdisini korur.
- Renk ve beden grupları `radiogroup/radio` semantiğindedir. Renk hedefleri **48×48 px**, beden hedefleri en az **70.3×48 px** ölçüldü. Sağ ok odağı ve seçimi Kobalt'tan Kum'a taşıdı; native `pa_renk` değeri `kum` oldu.
- Lightbox kapalıyken görsel host çocuk sayısı **0**, açıldığında **1**, Escape sonrası yeniden **0** oldu. Odak açan galeri öğesine geri döndü. Tab odağı modal içinde döner; oklar, `+/-`, tekerlek, sürükleme ve iki pointer pinch kod yolu aktiftir.
- Üç ilk galeri görselinde sayfa kaynağı `768×1024` ara boyuttur ve `data-full` URL'inden farklıdır. Tam kaynak yalnız lightbox açılışında `img` olarak oluşturulur. Varyasyon galerisi yinelenen ID'leri temizler.
- Masaüstü ürün bilgi paneli 64 px header + 16 px aralıkla **80 px** üst konumda sticky; mobilde statiktir.

## Token ve kaynak kod denetimi

`tokens.css` dışındaki altı kaynak CSS dosyası ölçüldü:

| Dosya | >1 px literal | hex | `rgb/rgba()` | 240 karakterden uzun satır |
|---|---:|---:|---:|---:|
| `cart.css` | 0 | 0 | 0 | 0 |
| `catalog.css` | 0 | 0 | 0 | 0 |
| `checkout.css` | 0 | 0 | 0 | 0 |
| `content.css` | 0 | 0 | 0 | 0 |
| `global.css` | 0 | 0 | 0 | 0 |
| `product.css` | 0 | 0 | 0 | 0 |

Tüm `font-size` bildirimleri `--text-*` token'ına bağlıdır. İç boşluk/margin/gap değerleri 8 px tabanlı `--space-*` ölçeğinden gelir. `1px` sınırlar ve sıfır değerleri tanımlı istisnadır. Medya sorgusu eşikleri ve viewport birimleri boşluk değeri sayılmamıştır.

## Kontrast

WCAG göreli parlaklık formülüyle kullanılan metin/zemin çiftleri:

| Metin | Zemin | Oran | AA 4.5:1 |
|---|---|---:|---|
| `ink` | `paper` | 12.93:1 | Geçti |
| `ink` | `sand` | 11.35:1 | Geçti |
| `muted` | `paper` | 5.51:1 | Geçti |
| `muted` | `sand` | 4.84:1 | Geçti |
| `white` | `ink` | 13.48:1 | Geçti |
| `muted-on-ink` | `ink` | 6.87:1 | Geçti |
| `error` | `paper` | 6.88:1 | Geçti |
| `success` | `paper` | 5.80:1 | Geçti |
| `ink-soft` | `paper` | 6.30:1 | Geçti |

`ink-line` yalnız dekoratif sınırdır; metin rengi olarak kullanılmaz.

## Responsive taşma matrisi

Tarayıcıda `documentElement.scrollWidth - innerWidth` ölçümü:

| Genişlik | Ana sayfa | Mağaza | Ürün | Checkout |
|---:|---:|---:|---:|---:|
| 320 | 0 | 0 | 0 | 0 |
| 390 | 0 | 0 | 0 | 0 |
| 768 | 0 | 0 | 0 | 0 |
| 1024 | 0 | 0 | 0 | 0 |
| 1280 | 0 | 0 | 0 | 0 |
| 1920 | 0 | 0 | 0 | 0 |

## Görünür kusur kapanışı

- Başlıklar sıcak `ink` veya koyu zeminde `white`; Blocksy laciverti görünmüyor.
- Mağaza başlığı `Mağaza`; Blocksy arşiv hero katmanı bastırıldı. Header altı ile özel başlık başlangıcı **32 px** tek ritimdir.
- Para `₺2.890`; ayarlar `left | . | , | 0` olarak seed edilir.
- `Shop`, `Default sorting`, `Showing all N results`, `Choose an option`, `HOME` görünür metinlerde bulunmadı.
- Blocksy'nin bulunmayan Türkçe paketindeki sepet metin boşlukları child theme localization shim'iyle; checkout gizlilik metni seed option'ıyla Türkçedir.
- `Fatura türü` billing kolonunun %100 genişliğindedir. Kurumsal seçimde şirket, vergi dairesi ve VKN alanları görünür ve hizalıdır.
- `Bikini Üstü`, `Yüksek Bel Bikini Altı`, `Straplez Mayo`, `İpli / Yan Bağlamalı` başlıklarında satır yüksekliği 82.944 px, blok aralığı 24 px; çakışma yoktur.
- iyzico eklentisinde page-overlay kapatma ayarı bulunmadı. Yalnız `#iyzico-bpo1[data-type="page-overlay"]` gizlendi; checkout'taki iki iyzico ödeme seçeneği ve marka görselleri korunur.

## Görsel QA

| Önce | Sonra |
|---|---|
| [Mobil ana sayfa](qa/home-mobile-390.png) | [Mobil ana sayfa](qa/faz3b-after-home-mobile-390.png) |
| [Masaüstü mağaza](qa/shop-desktop-1280.png) | [Masaüstü mağaza](qa/faz3b-after-shop-desktop-1280.png) |
| [Masaüstü ürün](qa/product-desktop-1280.png) | [Masaüstü ürün](qa/faz3b-after-product-desktop-1280.png) |
| [Masaüstü checkout](qa/checkout-desktop-1280.png) | [Kurumsal checkout](qa/faz3b-after-checkout-corporate-1280.png) |

Ek son durum kanıtları: [filtre çekmecesi](qa/faz3b-after-filter-desktop-1280.png), [mobil ürün galerisi](qa/faz3b-after-product-mobile-390.png), [Türkçe tipografi testi](qa/faz3b-after-typography-1280.png), [masaüstü ana sayfa](qa/faz3b-after-home-desktop-1280.png).

## Reset, smoke ve sınırlar

İki ardışık `make reset && make verify` çalıştırması temiz kurulumdan geçmiştir. Her turda 4 variable ürün, 50 varyasyon, 14/14 zorunlu içerik sayfası, tipografi test sayfası, exact ana menü, TRY formatı, iki override, pasif WC galeri destekleri ve sıfır kapsam dışı custom özellik doğrulanır.

Chrome/Chromium tabanlı yerel tarayıcı QA tamamlandı. Safari, Firefox, iOS/Android gerçek cihaz turu; gerçek yedi fotoğraflı müşteri ürünü ve iyzico sandbox tahsilatı ilgili veri/anahtarlar gelene kadar açık kabul maddeleridir.
