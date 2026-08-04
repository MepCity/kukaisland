# Faz 3 Aktarma Haritası

> Faz 3A kapanışı: minimum envanterden yalnızca `woocommerce/content-product.php` override edildi. Ürün detay, galeri, sepet ve checkout hook/CSS ile tamamlandı; variation, cart ve checkout template override'ları gerekmedi.

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
| `lib/storefront-interactions.ts` (233 satır) | `assets/js/storefront.js` | Panel/Escape/odak döngüsü ve header davranışı uyarlanır; markup seçicileri yeniden bağlanır |
| `lib/catalog-interactions.ts` (56 satır) | `assets/js/catalog.js` | Grid yoğunluğu taşınabilir; filtreleme WooCommerce query/filter bloklarına bırakılır |
| `lib/product-interactions.ts` (427 satır) | `assets/js/product.js` | Galeri/lightbox sunumu taşınabilir; varyasyon, fiyat, stok ve add-to-cart WooCommerce event/API'sine bağlanır |
| `lib/cart-interactions.ts` (244 satır) | `assets/js/cart.js` | Çekmece DOM/odak davranışı taşınabilir; localStorage, demo seed ve sepet matematiği taşınmaz |

Toplam 960 satır prototip etkileşim kodunun yaklaşık %42'si davranış fikri/odak yönetimi olarak yeniden kullanılabilir; doğrudan kopyalanabilir kod oranı markup ve veri kaynağı değiştiği için yaklaşık %20'dir.

## Minimum WooCommerce override envanteri

Faz 2'de override yoktur. Faz 3 başlamadan önce önce hook/Blocksy extension point denenir.

| Prototip bileşeni | Olası WooCommerce hedefi | Karar |
|---|---|---|
| Ürün kartı | `content-product.php` | Hook'lar yetersiz kalırsa tek override; ilk aday |
| Ürün detay bilgi paneli | `content-single-product.php` + single product hook'ları | Önce hook; tam override son çare |
| Varyasyon seçim alanı | `single-product/add-to-cart/variable.php` | Swatch DOM sözleşmesi için gerekirse dar override |
| Galeri | Blocksy Product Variations Gallery | Override yok; Free özelliği kullanılacak |
| Sepet satırı/sayfası | `cart/cart.php` | Önce Woo hook'ları; görsel ayrışma büyükse override |
| Klasik checkout formu | `checkout/form-checkout.php` | Alanlar hook ile; layout gerçekten gerekirse override |
| Sipariş özeti | `checkout/review-order.php` | Önce CSS/hook; override ertelendi |
| Header/footer/sepet çekmecesi | Blocksy builder ve child theme hook'ları | WooCommerce override değil |

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
| `ticariMesajlar` | Kargo/değişim/destek | Pozitif sayılar; müşteri onaylı değer fallback'i |

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
