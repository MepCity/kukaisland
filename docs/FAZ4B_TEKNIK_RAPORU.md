# Faz 4B teknik raporu

Tarih: 2026-08-09

## Sonuç

Faz 4A sonrasında bildirilen üç sapma düzeltildi. Editoryal başlık manifesto ölçeğinden ayrıldı, ana menü etiketi kayıtlı panel verisiyle birlikte “Hikâyemiz” olarak güncellendi ve WooCommerce beden terimlerine `order` metası yazıldı.

## Kabul kanıtları

| Kriter | Ölçüm | Sonuç |
|---|---|---|
| 1. “Ada Günlüğü” kelime ortasından kırılmıyor | 320, 390, 768, 1024, 1280, 1440 ve 1920 genişliklerinde her iki kelimenin `Range.getClientRects()` parça sayısı `1`; yedi ekran görüntüsü aşağıda | Karşılandı |
| 2. Manifesto bozulmadı | 1440 görüntüsünde iki manifesto satırı doğal kelime sınırlarında; “Güneş.”, “Ten.” ve “Özgürlük.” sözcüklerinin parça sayısı `1`; yatay taşma `0` | Karşılandı |
| 3. Menü etiketi “Hikâyemiz” | 42 rota/viewport render taramasında eski ön ek `0`; `STORY_MENU_LABEL=Hikâyemiz`; şema 2 → 3 geçiş testi kayıtlı eski etiketi dönüştürdü | Karşılandı |
| 4. Beden sırası S M L | Kart, ürün seçici, filtre, sepet, ödeme özeti ve beden rehberi görüntüleri üretildi; DOM sırası her yüzeyde S, M, L | Karşılandı |
| 5. Seed ve reset sonrası term meta | İki temiz kurulumda `SIZE_TERM_ORDER=S:0|M:1|L:2`; `scripts/seed.php` ve mevcut mağaza geçişi `scripts/migrate-sizes.php` aynı metayı yazar | Karşılandı |
| 6. Token ve taşma disiplini | Ham renk `0`, ham px `0`, gölge `0`, tanımsız token `0`; altı rota × yedi viewport = 42 kontrolde yatay taşma `0` | Karşılandı |
| 7. İki temiz kurulum | `make reset && make verify` iki bağımsız turda `VERIFY=PASS`, `SMOKE=PASS (5/5)` | Karşılandı |
| 8. CI | GitHub Actions `Quality` koşusu `31280565112`: production kurulumu, syntax, WordPress standartları ve kabul/smoke adımları geçti | Karşılandı |

## Editoryal viewport görüntüleri

- `docs/qa/faz4b-editorial-320.png`
- `docs/qa/faz4b-editorial-390.png`
- `docs/qa/faz4b-editorial-768.png`
- `docs/qa/faz4b-editorial-1024.png`
- `docs/qa/faz4b-editorial-1280.png`
- `docs/qa/faz4b-editorial-1440.png`
- `docs/qa/faz4b-editorial-1920.png`
- `docs/qa/faz4b-manifesto-1440.png`

Ölçülen editoryal font boyutları sırasıyla 67,2; 76; 53,76; 71,68; 89,6; 100,8 ve 104 px oldu. Değerlerin tamamı `--text-heading-editorial` token’ından gelir; bildirim CSS’inde ham değer yoktur. Manifesto eski geniş ölçeğini ayrı `--text-heading-manifesto` token’ıyla korur.

## Beden sırası görüntüleri

- `docs/qa/faz4b-size-card.png`
- `docs/qa/faz4b-size-product.png`
- `docs/qa/faz4b-size-filter.png`
- `docs/qa/faz4b-size-cart.png`
- `docs/qa/faz4b-size-checkout.png`
- `docs/qa/faz4b-size-guide.png`

Sepet ve ödeme görüntülerinde aynı ürünün Kobalt renkli S, M ve L varyasyonları üç ayrı satır olarak kullanıldı. Böylece yalnız seçiciler değil, siparişe taşınan varyasyon sırası da görünür biçimde kanıtlandı.

## Kapsam sınırı

Vendor dosyaları değiştirilmedi. Deploy, canlı anahtar veya gerçek ödeme yapılmadı.
