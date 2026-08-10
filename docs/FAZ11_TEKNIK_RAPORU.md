# Faz 11 teknik raporu

## Sonuç

- Footer ödeme etiketi ve iki dilli panel alanı kaldırıldı. Görünürlük anahtarı açıkken kart + iyzico `1+1`, kapalıyken ödeme kabı `0`; tekrar açıldığında `1+1` döndü.
- Footer alt bloğu 1440 px viewport'ta `183` CSS px'ten `127` CSS px'e indi. Grid aralığı `16→8` CSS px, üst dolgu `64→48` CSS px oldu.
- Kart şeridi `28→27` CSS px, iyzico logosu `20→18` CSS px oldu. Resmî kart görseli ve iki iyzico SVG'sinin SHA-256 doğrulaması `3/3 match`; renk/oran değişmedi.
- Yeni `--text-heading-document: clamp(22px, 2vw, 30px)` yalnız `.kuka-prose h2` için kullanılıyor. 1440 px'te TR ve EN başlık `43,2→28,8` CSS px, üst boşluk `64→48` CSS px, alt boşluk `22,96→16` CSS px ölçüldü.
- `--text-heading-medium` değiştirilmedi: içerik sayfası `h1`, sepet, katalog, checkout, tipografi testi ve marka hikâyesi kullanımları aynı kaldı.
- Site e-postası ve WordPress/WooCommerce göndericisi `info@kukaisland.com` tek kaynağında birleşti. `make reset` sonrasında `RESET_SITE_EMAIL=info@kukaisland.com`, `MAIL_FROM_IDENTITIES=wp=woo:info@kukaisland.com`.

## Visa 5 mm hesabı

Faz 8 ölçümünde 28 CSS px kart şeridindeki Visa işareti 20 CSS px idi. Aynı oranda yeni değer:

`20 / 28 × 27 = 19,2857 CSS px`

96 CSS px/inç kabulüyle:

`19,2857 × 25,4 / 96 = 5,1027 mm`

Sonuç **19,29 CSS px / 5,10 mm**. Bir alt tam sayı şerit değeri 26 CSS px olsaydı Visa `18,57 CSS px / 4,91 mm` olurdu. Bu nedenle küçültme 27 CSS px'te durduruldu.

## İki dil ve responsive ölçüm

Kontrol edilen 15 rota: Hakkımızda, İletişim, SSS, Kargo, İade, Gizlilik, Çerez, KVKK, Kullanım Koşulları, Ön Bilgilendirme, Mesafeli Satış, Açık Rıza, Ticari Elektronik İleti, Beden Rehberi ve Sipariş Takibi.

| Dil | Rota × viewport | Yatay taşma 0 | Footer e-postası |
|---|---:|---:|---:|
| TR | 15 × 7 = 105 | 105/105 | 105/105 |
| EN | 15 × 7 = 105 | 105/105 | 105/105 |
| Toplam | 210 | 210/210 | 210/210 |

Viewport genişlikleri: `320, 390, 768, 1024, 1280, 1440, 1920` CSS px. Ham kayıt: [content-matrix.json](qa/faz11/content-matrix.json).

## Yasal metin bütünlüğü

Değişiklik öncesi ve ikinci temiz reset sonrası sekiz WordPress hukuk sayfasının `post_content` SHA-256 değerleri birebir eşleşti: **8/8**. `scripts/seed-content.php` diff'i yalnız emekli footer etiketinin iki anahtarını temizleyen tek ek satırdır; hukuk metni satırı değişmedi.

| Sayfa | SHA-256 |
|---|---|
| Mesafeli Satış | `afcaafedd6340fa127f3e6add30c7413c1c50e20a2c34e05cf0dba3445ff7af6` |
| Ön Bilgilendirme | `a4006f3123f859adae5a71ad8977a1d5ce42e5362ae1ff2030ad643862ed8d99` |
| Kullanım Koşulları | `e6e98ac55cfd7b0cfbdb26667d291c7d03649b10d82d90c22ca4a2b6e9133236` |
| İade / Cayma | `f791a3fcc47cf07517d7295b44151973ff3231d8211ecec164b579d8c4c4a9c8` |
| KVKK | `f5c962362abb870225a1134fcdaa50a651ee429af9ac904e1baf50b076dbd801` |
| Gizlilik | `3ba81a763dfcfb0fa2f97d00b82240bdc865023048ba3b45f7f2f0b6f0e23aa8` |
| Çerez | `e8a007644720ca76c958c4cab6b2a30052a2f71beaa7eb622d7255437efc1bd1` |
| Açık Rıza | `3888452f69fefb495b84b5e1a5e938772f4d52f3c37e14b608d9d1dbda25dcab` |

`02` ve `04` hukuk belgelerindeki eski adres değiştirilmedi; yönlendirme/hukuk kararı [bilinen sınırlamalara](BILINEN_SINIRLAMALAR.md) ve [müşteri sorularına](MUSTERI_SORULARI.md) kaydedildi.

## Görsel kanıt

- TR footer: [önce](qa/faz11/before-tr-footer-1440.png) · [sonra](qa/faz11/after-tr-footer-1440.png)
- EN footer: [önce](qa/faz11/before-en-footer-1440.png) · [sonra](qa/faz11/after-en-footer-1440.png)
- TR belge: [önce](qa/faz11/before-tr-legal-1440.png) · [sonra](qa/faz11/after-tr-legal-1440.png)
- EN içerik: [önce](qa/faz11/before-en-content-1440.png) · [sonra](qa/faz11/after-en-content-1440.png)

## Kapılar

- `make reset && make verify`: **PASS × 2**
- Smoke: **5/5 × 2**
- `CSS_RAW_COLORS_OUTSIDE_TOKENS=0`
- `CSS_RAW_PX_OUTSIDE_TOKENS=0`
- `CSS_SHADOWS=0`
- `VENDOR_CHANGES=0`
- WooCommerce/Blocksy/iyzico dosya değişikliği: **0**
- Deploy: yapılmadı; canlı anahtar kullanılmadı.
