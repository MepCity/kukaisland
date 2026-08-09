# Faz 5D — iki dilli hero okunabilirliği teknik raporu

Tarih: 2026-08-09  
Ortam: yerel Docker, `http://localhost:8080`  
Kısıt: hero perdesi, gradient, gölge, deploy ve canlı anahtar kullanılmadı.

## Sonuç

Hero içeriği alt kenara bağlı kaldı. Başlık 32 karakteri geçtiğinde ayrı `--text-heading-hero-long` tokenı kullanılıyor; mobil iç boşluklar da tokenlarla sıkılaştırıldı. Böylece Türkçe ve 40 karakterlik İngilizce başlık yedi genişliğin tamamında iki satırda kaldı. Fotoğrafın üstündeki koyu metin için ayrı `--color-hero-ink` tokenı kullanıldı; diğer sayfa metinlerinin marka rengi değişmedi.

Panelde başlık alanına “Uzun başlıklar fotoğrafın koyu bölgesine taşabilir; yükledikten sonra kontrol edin.” rehberi eklendi. Metin tonu açıklaması, fotoğraf veya başlık değiştiğinde iki dilin de yeniden kontrol edilmesini açıkça söylüyor. [Panel kanıtı](qa/faz5d/panel-hero-guidance.png).

## Render kontrast yöntemi

Her viewport gerçek tarayıcıda render edildi. Başlığın satır kutuları DOM `Range` ölçümüyle bulundu. Her satırın ekran görüntüsü kırpıldı; ön plan rengine Öklid RGB uzaklığı 54 veya daha az olan harf ve kenar yumuşatma pikselleri ayıklandı. Kalan fotoğraf piksellerinin kanal bazlı medyanı arka plan kabul edildi ve WCAG göreli parlaklık formülüyle kontrast hesaplandı. Tek nokta örneklemesi kullanılmadı. Ham ölçümler [başlık](qa/faz5d/hero-contrast.json) ve [diğer hero bileşenleri](qa/faz5d/hero-component-contrast.json) dosyalarındadır.

### 1440 başlık satırları

| Dil / satır | Medyan render arka planı | Kontrast |
|---|---:|---:|
| TR — “Adanın ritmini” | `rgb(235, 230, 225)` | **15.67:1** |
| TR — “yanında taşı.” | `rgb(229, 224, 219)` | **14.82:1** |
| EN — “Carry the rhythm of” | `rgb(227, 222, 217)` | **14.54:1** |
| EN — “the island with you.” | `rgb(220, 214, 208)` | **13.48:1** |

Yedi genişlik × iki dilde en düşük başlık satırı `/en/` 320'de **6.52:1** oldu. Üst başlık en az **10.77:1**, alt metin en az **5.79:1**, düğme **13.48:1** ölçüldü. Yatay taşma 14/14 görüntüde `0`.

## Görsel matris

| Genişlik | Türkçe | English |
|---:|---|---|
| 320 | [görüntü](qa/faz5d/hero-tr-320.png) | [görüntü](qa/faz5d/hero-en-320.png) |
| 390 | [görüntü](qa/faz5d/hero-tr-390.png) | [görüntü](qa/faz5d/hero-en-390.png) |
| 768 | [görüntü](qa/faz5d/hero-tr-768.png) | [görüntü](qa/faz5d/hero-en-768.png) |
| 1024 | [görüntü](qa/faz5d/hero-tr-1024.png) | [görüntü](qa/faz5d/hero-en-1024.png) |
| 1280 | [görüntü](qa/faz5d/hero-tr-1280.png) | [görüntü](qa/faz5d/hero-en-1280.png) |
| 1440 | [görüntü](qa/faz5d/hero-tr-1440.png) | [görüntü](qa/faz5d/hero-en-1440.png) |
| 1920 | [görüntü](qa/faz5d/hero-tr-1920.png) | [görüntü](qa/faz5d/hero-en-1920.png) |

Ana sayfanın manifesto, editoryal, ürün kartı, servis şeridi, bülten ve footer yüzeyleri iki dilde tam sayfa olarak ayrıca kaydedildi: [TR](qa/faz5d/surfaces-home-tr-1440.png), [EN](qa/faz5d/surfaces-home-en-1440.png). Katalog/filtre, ürün, sepet ve ödeme yüzeyleri Faz 5E'nin iki dilli E2E matrisinde yer alır.

## Kabul durumu

- Başlık, üst başlık, alt metin ve düğme: 14/14 renderda AA.
- Uzun İngilizce başlık: iki satır, alt tabana bağlı ve koyu deniz bölgesine taşmıyor.
- Hero perde/gölge: `0`.
- Token kapıları ve yatay taşma: `0`.
- Panel rehberi: mevcut.

