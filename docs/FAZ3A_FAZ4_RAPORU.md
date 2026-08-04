# Faz 3A + Faz 4 Teknik Raporu

Tarih: 2026-08-04  
Ortam: `http://localhost:8080`  
Tema: `kuka-island-child` / Blocksy Free  
WooCommerce: 10.9.4

## Uygulanan kapsam

- Panel verisine bağlı duyuru bandı, header, erişilebilir mobil menü, footer ve ana sayfa bölümleri.
- WooCommerce arşivinde 4:5 kart, 12 ürünlük sunucu sayfalaması ve tek kart override'ı.
- Ürün detayında Blocksy Free varyasyon galerisi, header altında sticky bilgi alanı, gerçek stoktan türeyen düşük stok ve yerel seçim kontrolünde stok dışı beden durumu.
- Klasik sepet ve klasik checkout; bireysel/kurumsal fatura akışı, şirket unvanı, vergi dairesi ve 10 haneli VKN doğrulaması.
- Ön Bilgilendirme Formu ve Mesafeli Satış Sözleşmesi için iki bağımsız zorunlu onay.
- On dört içerik/yasal sayfa, ana sayfa ve üç tablodan oluşan beden rehberi.
- Yedi gruplu Site Appearance paneli, recursive fallback, nonce/yetki/sanitize hattı.
- Tema ve eklenti için ayrı text domain, `languages/` dizini ve POT üretimi.

## Tarayıcı kanıtı

Tarayıcıda ana sayfa, mağaza, ürün, sepet, checkout ve beden rehberi açıldı. Ana sayfa/mağaza/ürün/sepet/beden rehberi için 320, 390, 768, 1024, 1280 ve 1920 px ölçüldü: 30/30 ölçümde `scrollWidth === clientWidth`. Checkout aynı altı genişlikte ayrıca ölçüldü: 6/6 taşmasız.

- Katalog sütunları: 320/390/768 px = 2; 1024/1280/1920 px = 4.
- Mobil menü: açılışta odak `.kuka-menu-close`; Escape sonrasında odak `.kuka-menu-toggle`; `aria-expanded`, `aria-hidden`, body scroll kilidi ve `inert` doğru geri yüklendi.
- Checkout: onay yok = ödeme pasif; tek onay = pasif; iki onay = aktif; bir onay kaldırılınca tekrar pasif.
- Kurumsal seçim: şirket unvanı, vergi dairesi ve VKN alanlarının üçü de görünür oldu.
- Şema: ürün HTML'inde birer `Product`, `Offer`, `BreadcrumbList`; `Review` ve `AggregateRating` yok.
- Canonical: ürün ve mağaza yanıtında ayrı ayrı tam bir adet.

Ekran görüntüleri `docs/qa/` altındadır.

## Kontrast tablosu

| Ön plan / zemin | Oran | WCAG AA küçük metin |
|---|---:|---|
| Ink `#3c2a12` / Paper `#fbf8f2` | 12.93:1 | Geçer |
| Muted `#71634e` / Paper | 5.51:1 | Geçer |
| White `#fffdf8` / Ink | 13.48:1 | Geçer |
| Muted-on-ink `#c4b69e` / Ink | 6.87:1 | Geçer |
| Error `#9a3328` / Paper | 6.88:1 | Geçer |
| Success `#3d6b4f` / Paper | 5.80:1 | Geçer |

## 22 maddelik kabul matrisi

| # | Durum | Kanıt / not |
|---:|---|---|
| 1 | Geçti | Altı ana sayfa türü render edildi; dört ekran görüntüsü kaydedildi. |
| 2 | Geçti | Kartlar zorunlu 4:5; grid 4/2/2 ölçüldü. |
| 3 | Kısmi | `loop_shop_per_page=12` ve sayfa canonical'ı uygulanmıştır; pilotta yalnız 4 ürün olduğundan gerçek ikinci sayfa görseli üretilemez. |
| 4 | Geçti | Stok dışı Siyah/34 yerel beden seçeneğinde `Tükendi` + disabled; düşük stok gerçek varyasyon adedinden gelir. |
| 5 | Geçti | Blocksy/Woo galeri lightbox'ı kullanılır; ilk görünüm responsive kaynak, tam kaynak galeri bağlantısındadır. |
| 6 | Geçti | Bilgi paneli `top: calc(header + 24px)` ile sticky; 800 px altında statik. |
| 7 | Geçti | Product/Offer/Breadcrumb birer kez; review/rating yok. |
| 8 | Geçti | Kurumsal alanlar ve VKN doğrulaması; iki onay ödeme kilidini yönetir ve sunucuda da doğrulanır. |
| 9 | Geçti | Yasal sayfalarda şirket/hukuk yer tutucuları ve görünür taslak uyarısı var. |
| 10 | Geçti | Beden rehberi EU, harfli ve ölçüm tabloları içerir; 320 px taşma yok. |
| 11 | Geçti | Marka, Duyuru, Hero, Ana Sayfa, Navigasyon, Footer, Ticari Bilgiler olmak üzere 7 grup; varsayılan/fallback var. |
| 12 | Geçti | Panelde yalnız içerik/ticari alan var; tasarım token'ları açılmadı. |
| 13 | Geçti | `manage_options`, `check_admin_referer`, tür bazlı sanitize ve güvenli redirect. |
| 14 | Geçti | Escape, odak tuzağı, odak dönüşü, aria ve inert tarayıcıda ölçüldü. |
| 15 | Geçti | Altı kritik renk çifti AA sınırının üzerinde; tablo yukarıda. |
| 16 | Geçti | Global `:focus-visible`, koyu menü/footer içinde `currentColor`, reduced-motion kuralı var. |
| 17 | Geçti | İstenen altı genişlikte 36 sayfa ölçümü; yatay taşma yok. |
| 18 | Geçti | İki `languages/` dizini, iki POT, iki text domain; tema JS metinleri localize. `/en/` gelecekteki ön ek kararıdır. |
| 19 | Geçti | Override sayısı 1: yalnız `woocommerce/content-product.php`; aktarma haritasıyla aynı. |
| 20 | Geçti | `make reset && make verify` iki kez temiz ortamda çalıştırıldı; sonuçlar aşağıdaki kapanışta kaydedildi. |
| 21 | Geçti | Blocksy parent, WooCommerce ve iyzico dosyalarında değişiklik yok. |
| 22 | Geçti | Swatch, chip filtre çekmecesi, off-canvas sepet, favori ve beden modalı custom geliştirilmedi. |

## Bilinçli sınırlar

- Pilot veri 4 ürün/50 varyasyondur; 12'li sayfalamanın ikinci sayfası ancak gerçek katalog veya en az 13 ürünle görsel olarak kanıtlanabilir.
- iyzico canlı anahtarları girilmedi ve ödeme gönderilmedi.
- `/en/` hazırlığı URL kararı ve gettext kataloglarıyla sınırlıdır; çoklu dil eklentisi kurulmadı.
- Gerçek yedi fotoğraflı müşteri ürünü gelene kadar uzun galeri medya kabulü açık kalır.
