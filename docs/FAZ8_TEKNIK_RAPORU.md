# Faz 8 teknik raporu — iyzico başvuru şartları

Ölçüm tarihi: 10 Ağustos 2026. Ortam: yerel WordPress `http://localhost:8080`, PHP 8.3, WordPress 7.0.3, WooCommerce 11.0.0, iyzico 3.5.28.

## Ölçülen sonuçlar

- Otomatik başvuru hazırlığı: **7/12 tamam, 5 eksik**. Tamam: beş içerik grubu, checkout iyzico/kart varlığı, footer tema varlıkları. Eksik: yerel HTTPS, MERSİS/KEP/oda/kurallar, ETBİS, pilot olmayan gerçek fiyatlı ürün, mağazanın herkese açılması.
- Manuel belge listesi: temiz kurulumda **0/5**; tarayıcı kayıt testinde **5/5** kutu kaydedildi ve yeniden yüklendi.
- Site Görünümü: **13 grup, 115 görünen satır, 157 saklanan kontrol; 42/42 EN değer**.
- Ödeme varlıkları: **3/3** hash/bayt karşılaştırması geçti. Tema kart PNG'si çalışan iyzico eklentisiyle aynı; TR/EN SVG SHA-256 değerleri `docs/GORSEL_KAYNAKLARI.md` içindedir.
- Kart kaynağı **200×21 px**, render **266,66×28 CSS px**. Visa bileşeni kaynakta **15 px** yüksek: `15/21 × 28 = 20 CSS px`; 96 dpi eşdeğeri `20/96 × 25,4 = 5,29 mm`.
- Retina değerlendirmesi: 2× ekranda 266,66×28 CSS px alan 533,32×56 cihaz pikselidir; 200×21 kaynak yalnız **0,375 kaynak pikseli / cihaz pikseli** sağlar. Raster şerit keskin kabul edilmedi; onaylı vektör/2× kaynak sorusu açıldı.
- Yatay taşma: **14/14 sıfır**. 320, 390, 768, 1024, 1280, 1440, 1920 genişliklerinin her birinde TR ve EN `scrollWidth - clientWidth = 0`. Ham ölçüm: `docs/qa/faz8/overflow-measurements.json`.
- Tema CSS: logolar dışındaki ham renk **0**, ham px **0**, gölge **0**, root overflow maskesi **0**. Tam renk istisnası **3 dosya**.
- İletişim: kaynak sayfada `[kuka_company_details]` **1**, `[kuka_contact_details]` **1**. Destek bloğu yalnız WhatsApp, destek saati ve Instagram'ı basar; şirket bloğundaki e-posta/telefon tekrarlanmaz.

## Görsel kanıt

| Durum | Dosya |
|---|---|
| Faz 8 öncesi footer | `docs/qa/faz4a-after-footer-1280.png` |
| TR ödeme şeridi | `docs/qa/faz8/footer-tr-payment-strip.jpg` |
| EN ödeme şeridi | `docs/qa/faz8/footer-en-payment-strip.jpg` |
| TR mobil | `docs/qa/faz8/footer-tr-390.jpg` |
| EN mobil | `docs/qa/faz8/footer-en-390.jpg` |
| Panel görünürlük + TR/EN etiket | `docs/qa/faz8/panel-footer-toggle-bilingual.jpg` |
| Logolar kapalı | `docs/qa/faz8/footer-logos-disabled.jpg` |
| Şirket alanları boş | `docs/qa/faz8/panel-legal-fields-empty.jpg` |
| Şirket alanları dolu | `docs/qa/faz8/panel-legal-fields-filled.jpg` |
| TR iletişim boş | `docs/qa/faz8/contact-tr-empty.jpg` |
| TR iletişim dolu | `docs/qa/faz8/contact-tr-filled.jpg` |
| EN iletişim dolu | `docs/qa/faz8/contact-en-filled.jpg` |
| Başlangıç 7/12 + 0/5 | `docs/qa/faz8/baslangic-iyzico-7-of-12.jpg` |
| Manuel belge kaydı 5/5 | `docs/qa/faz8/baslangic-belgeler-5-of-5.jpg` |

## Kabul kriterleri

| # | Sonuç | Kanıt |
|---:|---|---|
| 1 | Görsel kanıtlı | TR ve EN footer ekranları; farklı sağlayıcı SVG URL/alt metni DOM ölçümü |
| 2 | Görsel kanıtlı | Önceki footer ile Faz 8 TR footer; marka kilidi üstte ve ana odak, logolar çerçevesiz/gölgesiz |
| 3 | Ölçümle doğrulandı | Tema yolu `assets/img/payment/`; footer kaynakta eklenti URL'si 0 |
| 4 | Belgelendi | `docs/GORSEL_KAYNAKLARI.md` hash ve kaynak tablosu |
| 5 | Ölçümle doğrulandı | `PAYMENT_ASSETS=cards:match|tr:match|en:match` |
| 6 | Ölçümle doğrulandı | Visa **20 CSS px / 5,29 mm** |
| 7 | Değerlendirildi, dış girdi açık | Retina keskinliği yetersiz; müşteri sorusu 2 |
| 8 | DOM + görsel kanıtlı | TR/EN anlamlı kart ve iyzico alt metinleri |
| 9 | Görsel ve etkileşim kanıtlı | Açık footer, panel anahtarı, kapalı footer; kapalı DOM sayısı 0 |
| 10 | Kaynak denetimiyle doğrulandı | Panelde medya/payment asset yükleme alanı yok; yalnız anahtar ve metin |
| 11 | Ölçümle doğrulandı | PLAN §38; ham renk 0, istisna 3 |
| 12 | Görsel + kaynak ölçümü | TR/EN iletişim; kısa kodlar 1+1, destek tekrarı yok |
| 13 | Görsel + render ölçümü | Boş/dolu panel ve iletişim; temiz durumda beş opsiyonel satır 0 |
| 14 | Grep/temiz kurulumla doğrulandı | MERSİS/KEP/oda/ETBİS boş; köşeli yer tutucu render edilmez |
| 15 | Görsel + etkileşim kanıtlı | Otomatik 7/12; manuel 0/5→5/5 ve yeniden yüklemede korunuyor |
| 16 | DOM ölçümüyle doğrulandı | **12/12** satırda ilgili yönetim URL'si |
| 17 | Bilinçli ertelendi | HSTS eklenmedi; yerel HTTP ve üretim TLS yetkisi yok. Runbook §10 düşük süre/kanıt/geri alma yolunu verir |
| 18 | Kaynak + secret taraması | Runbook §6 özel bağlantı yolu; gerçek token depoda yok |
| 19 | Git ölçümü | WooCommerce, Blocksy ve iyzico eklenti dosyalarında değişiklik 0 |
| 20 | Ölçümle doğrulandı | ham renk 0, gölge 0, **14/14** viewport/dil taşma 0 |
| 21 | Ölçümle doğrulandı | İki ayrı temiz kurulumda `VERIFY=PASS` + `SMOKE=PASS (5/5)`; CI eşdeğeri Composer strict + PHP lint + PHPCS PASS |

HSTS, canlı ödeme/3D, gerçek ürün ve production erişimi görsel veya çalışma zamanı kanıtı olmadığı için “karşılandı” olarak işaretlenmedi.
