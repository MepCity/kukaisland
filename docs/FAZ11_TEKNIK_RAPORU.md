# Faz 11 teknik raporu

## Sonuç

- Müşteri takibinde footer ödeme logoları bütünüyle kaldırıldı. `.kuka-payment-trust` HTML/CSS'i, `payment_logos_enabled` panel alanı/varsayılanı, iki ödeme token'ı ve tema `assets/img/payment/` klasörü yoktur; yorumda tutulmadı.
- Footer alt bloğu yalnız marka kilidi ve telif satırından oluşur. Çizgiden footer tabanına yükseklik `124→92` CSS px, çizgi–logo mesafesi `49→25` CSS px ve marka–telif aralığı `16→8` CSS px oldu. Alttaki `24` CSS px boşlukla grubun merkez sapması yalnız `0,5` CSS px ölçüldü.
- TR `7/7`, EN `7/7` viewportta footer ödeme kabı `0/14`, footer ödeme görseli `0/14`, marka kilidi `14/14`, telif `14/14` ölçüldü.
- Ödeme sayfasındaki eklenti şeridi korunmuştur: TR ve EN ayrı ayrı `iyzico-woocommerce/assets/images/cards_v2.png`, görünür `200×21` CSS px. Tema varlığı değildir.
- Site Görünümü çalışma zamanı envanteri ölçülerek `13 grup / 113 satır / 154 kontrol / 41 EN` olarak güncellendi.
- Yeni `--text-heading-document: clamp(22px, 2vw, 30px)` yalnız `.kuka-prose h2` için kullanılıyor. 1440 px'te TR ve EN başlık `43,2→28,8` CSS px, üst boşluk `64→48` CSS px, alt boşluk `22,96→16` CSS px ölçüldü.
- `--text-heading-medium` değiştirilmedi: içerik sayfası `h1`, sepet, katalog, checkout, tipografi testi ve marka hikâyesi kullanımları aynı kaldı.
- Site e-postası ve WordPress/WooCommerce göndericisi `info@kukaisland.com` tek kaynağında birleşti. `make reset` sonrasında `RESET_SITE_EMAIL=info@kukaisland.com`, `MAIL_FROM_IDENTITIES=wp=woo:info@kukaisland.com`. Son görsel takipte e-posta footer Sosyal sütunundan kaldırıldı; burada yalnız Instagram ve WhatsApp kalır.

## İki dil ve responsive ölçüm

Footer kaldırma matrisi:

| Dil | Viewport | Yatay taşma 0 | Footer ödeme kabı/görseli | Marka/telif |
|---|---:|---:|---:|---:|
| TR | 7 | 7/7 | 0/7 · 0/7 | 7/7 · 7/7 |
| EN | 7 | 7/7 | 0/7 · 0/7 | 7/7 · 7/7 |
| Toplam | 14 | 14/14 | 0/14 · 0/14 | 14/14 · 14/14 |

Ham kayıt: [footer-payment-removal-matrix.json](qa/faz11/footer-payment-removal-matrix.json).

Faz 11 içerik sayfaları matrisi:

Kontrol edilen 15 rota: Hakkımızda, İletişim, SSS, Kargo, İade, Gizlilik, Çerez, KVKK, Kullanım Koşulları, Ön Bilgilendirme, Mesafeli Satış, Açık Rıza, Ticari Elektronik İleti, Beden Rehberi ve Sipariş Takibi.

| Dil | Rota × viewport | Yatay taşma 0 | İlk teslim footer e-postası (sonradan kaldırıldı) |
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

- TR footer: [önce — ödeme logolu](qa/faz11/after-tr-footer-1440.png) · [sonra — yalnız marka ve telif](qa/faz11/after-tr-footer-no-payment-1440.png)
- EN footer: [önce — ödeme logolu](qa/faz11/after-en-footer-1440.png) · [sonra — yalnız marka ve telif](qa/faz11/after-en-footer-no-payment-1440.png)
- Ödeme sayfası eklenti kart şeridi: [TR](qa/faz11/checkout-iyzico-cards-v2.png) · [EN](qa/faz11/checkout-iyzico-cards-v2-en.png)
- Footer Sosyal sütunu (Instagram + WhatsApp): [görüntü](qa/faz11/footer-social-instagram-whatsapp.png)
- `136` CSS px servis şeridi ve sabit sağ kolon sırası: [görüntü](qa/faz11/service-strip-136.png)
- Kısaltılmış ve yeniden ortalanmış marka/telif bloğu: [görüntü](qa/faz11/footer-bottom-compact-1440.png)
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
