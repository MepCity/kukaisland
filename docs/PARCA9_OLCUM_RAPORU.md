# Faz 1 / Parça 9 — Ölçüm Raporu

Ölçüm tarihi: 4 Ağustos 2026. Yerel üretim derlemesi (`vinext build/start`), yerel `IMAGES` dönüşümü ve gerçek tarayıcı oturumları kullanıldı. Aktarım tabloları soğuk önbellek için gzip metin + AVIF görsel gövdesi toplamıdır; HTTP başlıkları ve protokol ek yükü dahil değildir.

## Kontrast ve etkileşim hedefleri

| Metin / zemin | Oran | AA 4.5:1 |
|---|---:|---|
| `#616560` / paper `#eeefec` | 5.14:1 | Geçti |
| `#616560` / mist `#e4e6e2` | 4.72:1 | Geçti |
| `#8b8e8a` / ink `#16181a` | 5.37:1 | Geçti |

Ayırıcı `--color-line` metin taşımadığı için dekoratif bırakıldı. Global `:focus-visible` 2px ink outline ve 4px offset kullanıyor; ink zeminlerde beyaz varyant devreye giriyor. Kart ve ürün renk seçeneklerinde görsel nokta aynı kaldı, gerçek düğme kutusu 40×40px oldu. Ana sayfa, kategori, ürün, sepet ve ödeme ekranlarında 390px genişlikte görünen `button/input/select/summary/radio` hedefleri ölçüldü: etkili kutusu 40px altına düşen hedef sayısı **0**.

## Responsive ekran matrisi

Her genişlikte şu 13 ekran çalıştırıldı: ana sayfa, beş kategori/koleksiyon ekranı, ürün detayı, sepet, ödeme, beden rehberi, yasal sayfa, SSS ve hesabım. Kontrol: `document.documentElement.scrollWidth <= clientWidth` ve tek `h1`.

| Genişlik | Ekran | Codex tarayıcı taşma | Chrome taşma |
|---:|---:|---:|---:|
| 320 | 13 | 0 | 0 |
| 360 | 13 | 0 | 0 |
| 390 | 13 | 0 | 0 |
| 430 | 13 | 0 | 0 |
| 768 | 13 | 0 | 0 |
| 1024 | 13 | 0 | 0 |
| 1280 | 13 | 0 | 0 |
| 1920 | 13 | 0 | 0 |

Toplam **208 ekran/genişlik kontrolü**, hata **0**. İlk turda 320px newsletter başlığı ve 320–390px dört renkli kart satırı kök taşma üretti; akışkan başlık ve sarmalanan hedeflerle kapatıldı. Beden rehberi tablosu 320px'te 544px içerik genişliğini kendi `overflow-x:auto` kapsayıcısında tutuyor; kök belge 320px kalıyor.

## İçerik dayanıklılığı

| Durum | Kalıcı kanıt |
|---|---|
| Uzun ürün adı | `content-resilience.test.mjs` |
| Uzun kesim adı | `content-resilience.test.mjs` |
| İndirimli fiyat | `content-resilience.test.mjs` |
| Dört renk / tek renk | `content-resilience.test.mjs` |
| Tek görsel / dört görsel | `content-resilience.test.mjs`, `product.test.mjs` |
| Boş açıklama | `site-content.test.mjs` |
| Tüm bedenler tükenmiş | `content-resilience.test.mjs` |
| Yalnız bir adet kalmış | `content-resilience.test.mjs` |
| Boş filtre sonucu | `catalog.test.mjs` sunucu filtre akışı |
| Tek / üç duyuru | `site-content.test.mjs` |
| Kapalı ana sayfa bölümü | `site-content.test.mjs` |
| Varyasyonsuz ürün | `content-resilience.test.mjs` güvenli “Yakında” modeli |

Demo katalog kalıcı olarak değiştirilmedi; testler klonlanmış kontrollü veriler kullanıyor.

## Erişilebilirlik kanıtı

- 13 kritik rotada tek `h1`, `h1` ile başlayan ve seviye atlamayan başlık sırası, tek skip link ve odaklanabilir `#main-content` test edildi.
- Aynı rotalardaki tüm `<img>` öğelerinde boş olmayan `alt`, sayısal `width` ve `height` doğrulandı.
- Newsletter, checkout ve hesap alanlarında görünür etiket doğrulandı. Hata/durumlar metin ve `aria-live`/`role=status|alert` ile aktarılıyor.
- Zoom engeli yok; `maximum-scale` ve `user-scalable=no` bulunmuyor.
- `prefers-reduced-motion` yalnız `app/globals.css` içinde bir kez bulunuyor.
- Renk ve beden radiogroup'ları Left/Right/Up/Down/Home/End tuşlarıyla geziyor. Gerçek tarayıcı ölçümünde seçim, roving `tabIndex`, `aria-checked` ve odak birlikte değişti.

| Panel | Odaklanabilir öğe | Açılış odağı içeride | Tab döngüsü | Escape | Odak dönüşü | Kapalıyken inert |
|---|---:|---|---|---|---|---|
| Mobil menü | 9 | Evet | Geçti | Geçti | Geçti | Evet |
| Sepet çekmecesi | 16 | Evet | Geçti | Geçti | Geçti | Evet |
| Filtre çekmecesi | 24 | Evet | Geçti | Geçti | Geçti | Evet |
| Ürün lightbox | 4 | Evet | Geçti | Geçti | Geçti | Evet |

Chrome'da mobil menü için aynı açılış/Escape/odak dönüşü/inert kontrolü ayrıca geçti.

## Görsel optimizasyonu

Tüm dosyalar aynı yerel bağlayıcıya `w=640&q=78` ve `Accept: image/avif,image/webp` ile gönderildi.

| Görsel | Orijinal B | AVIF B | Küçülme | Tasarruf |
|---|---:|---:|---:|---:|
| azur-bikini-bottom-detail.jpg | 119,269 | 7,354 | 16.2× | %93.8 |
| azur-bikini-bottom.jpg | 199,125 | 5,596 | 35.6× | %97.2 |
| azur-bralet-top-detail.jpg | 106,945 | 7,473 | 14.3× | %93.0 |
| azur-bralet-top.jpg | 193,643 | 6,610 | 29.3× | %96.6 |
| cobalt-asymmetric-top-detail.jpg | 125,485 | 8,880 | 14.1× | %92.9 |
| cobalt-asymmetric-top.jpg | 212,543 | 6,889 | 30.9× | %96.8 |
| cobalt-set.jpg | 456,055 | 13,586 | 33.6× | %97.0 |
| hero-aegean-black-mobile.jpg | 440,299 | 19,572 | 22.5× | %95.6 |
| hero-aegean-black.jpg | 441,221 | 10,003 | 44.1× | %97.7 |
| noir-asymmetric-top-detail.jpg | 92,636 | 6,144 | 15.1× | %93.4 |
| noir-asymmetric-top.jpg | 166,130 | 4,821 | 34.5× | %97.1 |
| noir-one-piece-detail.jpg | 101,065 | 7,150 | 14.1× | %92.9 |
| noir-one-piece.jpg | 183,846 | 6,111 | 30.1× | %96.7 |
| **Toplam** | **2,838,262** | **110,189** | **25.8×** | **%96.1** |

## Aktarım boyutları

Üretim tarayıcısının gerçekten istediği yerel CSS/JS listesi, route HTML'i ve aynı genişlikteki gerçek AVIF yanıtları toplandı. Metin gzip, fontlar zaten sıkıştırılmış WOFF2 gövde boyutudur.

| Sayfa şablonu | Mobil 390 | Masaüstü 1280 |
|---|---:|---:|
| Ana sayfa | 310.0 KiB | 358.2 KiB |
| Kategori | 281.9 KiB | 286.7 KiB |
| Ürün detay | 288.1 KiB | 290.5 KiB |
| Sepet | 268.3 KiB | 268.3 KiB |
| Ödeme | 267.1 KiB | 267.1 KiB |
| İçerik / beden rehberi | 269.4 KiB | 269.4 KiB |

İlk ekranda mobil ve masaüstünde yalnız hero görünür: mobil AVIF 19,572 B (`w=640`), masaüstü AVIF 65,536 B (`w=1672`). Hero `loading=eager` ve `fetchPriority=high`; ekran dışı alt görseller `loading=lazy`. Üretim açılışında 7 yerel script, 1 yerel stylesheet, 0 üçüncü taraf script ölçüldü. Otomatik Link prefetch'i kapatılmadan önce 15 RSC isteği, düzeltmeden sonra **0** RSC isteği vardı.

Fontlar self-hosted ve `font-display:swap`; vinext çıktısı 11 WOFF2 preload'u indiriyor, toplam 146,464 B. Bu maliyet tabloya dahildir. Fontu değiştirmek tasarım dili kısıtını, vinext/Next sürümünü değiştirmek bağımlılık kısıtını ihlal edeceği için bu turda azaltılmadı.

## Tarayıcılar ve ölçülemeyenler

| Ortam | Sonuç |
|---|---|
| Chrome | 104/104 ekran-genişlik kontrolü ve mobil menü klavye kontrolü geçti |
| Codex Chromium tabanlı tarayıcı | 104/104 ekran-genişlik, dört panel ve radiogroup kontrolü geçti |
| Safari | Erişemedim; geçti olarak işaretlenmedi |
| Firefox | Erişemedim; geçti olarak işaretlenmedi |
| iOS Safari / gerçek iOS cihaz | Erişemedim; geçti olarak işaretlenmedi |
| Android Chrome / gerçek Android cihaz | Erişemedim; geçti olarak işaretlenmedi |

Tarayıcı yüzeylerinde ağ/CPU throttling ve güvenilir CWV ölçüm API'si yoktu. Bu nedenle LCP/INP/CLS “iyi” sınırları ölçülmedi ve geçti denmedi. Gerçek cihaz turu ile Safari/Firefox turu müşteri sunumundan önce ayrı kabul adımıdır.

## Kabul kriteri özeti

1–15 ve 17–20 ölçüm/test ile karşılandı. Kriter 16 yalnız Chrome'da karşılandı; Safari, Firefox, iOS Safari ve Android Chrome erişilemediği için **tam karşılanmadı**. Core Web Vitals hedefi de araç kısıtı nedeniyle **ölçülmedi**. `npm run lint`: temiz. `npm test`: 67/67, fail 0. `npm audit --omit=dev`: 0 vulnerability.
