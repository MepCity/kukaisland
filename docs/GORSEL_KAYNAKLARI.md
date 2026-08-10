# Görsel kaynakları — Faz 6B marka hikâyesi

İndirme tarihi: **2026-08-09**. Altı kaynak fotoğraf Pexels üzerinde “Free to use” olarak yayımlanmıştır ve Pexels License kapsamında kırpılıp web sitesinde kullanılabilir. Lisans: <https://www.pexels.com/license/>.

| Hazırlanan dosya | Kaynak sayfası | Fotoğrafçı | Lisans | İndirme tarihi |
|---|---|---|---|---|
| `story-01-desktop.jpg` | <https://www.pexels.com/photo/tranquil-ocean-horizon-at-dawn-30923399/> | Marianna Sigov | Pexels License | 2026-08-09 |
| `story-01-mobile.jpg` | <https://www.pexels.com/photo/tranquil-ocean-horizon-at-dawn-30923399/> | Marianna Sigov | Pexels License | 2026-08-09 |
| `story-02-desktop.jpg` | <https://www.pexels.com/photo/tranquil-beach-sunrise-with-soft-morning-glow-30049907/> | Steve Hauptman | Pexels License | 2026-08-09 |
| `story-02-mobile.jpg` | <https://www.pexels.com/photo/tranquil-beach-sunrise-with-soft-morning-glow-30049907/> | Steve Hauptman | Pexels License | 2026-08-09 |
| `story-03-desktop.jpg` | <https://www.pexels.com/photo/textured-beige-concrete-surface-background-20536225/> | Timothy Huliselan | Pexels License | 2026-08-09 |
| `story-03-mobile.jpg` | <https://www.pexels.com/photo/textured-beige-concrete-surface-background-20536225/> | Timothy Huliselan | Pexels License | 2026-08-09 |
| `story-04-desktop.jpg` | <https://www.pexels.com/photo/sunlight-on-water-surface-19457051/> | Eugenia Remark | Pexels License | 2026-08-09 |
| `story-04-mobile.jpg` | <https://www.pexels.com/photo/sunlight-on-water-surface-19457051/> | Eugenia Remark | Pexels License | 2026-08-09 |
| `story-05-desktop.jpg` | <https://www.pexels.com/photo/sand-on-the-beach-in-the-sunlight-21554937/> | Fuka jaz | Pexels License | 2026-08-09 |
| `story-05-mobile.jpg` | <https://www.pexels.com/photo/sand-on-the-beach-in-the-sunlight-21554937/> | Fuka jaz | Pexels License | 2026-08-09 |
| `story-06-desktop.jpg` | <https://www.pexels.com/photo/tranquil-ocean-horizon-under-expansive-sky-37049868/> | Marianna Sigov | Pexels License | 2026-08-09 |
| `story-06-mobile.jpg` | <https://www.pexels.com/photo/tranquil-ocean-horizon-under-expansive-sky-37049868/> | Marianna Sigov | Pexels License | 2026-08-09 |

`scripts/prepare-media.sh` doğrulanmış Pexels özgün dosyalarını geçici dizine indirir; `1920×1080` masaüstü ve `1200×1500` mobil kadrajları `seed-media/` altında üretir. Özgün dosyalar depoya veya WordPress medya kütüphanesine alınmaz. Hazırlanan dosyaların uzun kenarı `2000 px` eşiğinin altındadır; WordPress içe aktarımı responsive alt boyutları ve `srcset` değerlerini üretir.

Bu görseller profesyonel Kuka Island çekimleri gelene kadar **geçici sanat yönü medyasıdır**. Görsel incelemede tanınabilir yüz, logo/marka, ürün markası veya tescilli mekân görünmedi; bu kayıt mülkiyet/kişilik hakkı için bağımsız hukuk görüşü yerine geçmez.

## Faz 8 ödeme varlıkları

| Tema dosyası | Kaynak | SHA-256 | Kullanım |
|---|---|---|---|
| `assets/img/payment/cards_v2.png` | Resmî `iyzico-woocommerce/assets/images/cards_v2.png` | `6c040c50f43ae84ead1c0a60fa3e0685dcd19e2968c2d39ec5e09a6d2b9931b7` | Mastercard, Visa, Troy, American Express tam renk şeridi |
| `assets/img/payment/iyzico_ile_ode_colored_horizontal.svg` | Repodaki resmî `iyzico/` kaynak paketi | `4a3fabf8992903a8fae84b5b01f5288bcedb635694d082fb0f8fde6e9d7149a6` | Türkçe footer |
| `assets/img/payment/pay_with_iyzico_horizontal_colored.svg` | Repodaki resmî `iyzico/` kaynak paketi | `7d584fda356b7cd25ccc093f83e9265d75bd2da568c39415e40980dea7d6d023` | İngilizce `/en/` footer |

Dosyalar renk, oran, kırpma veya iç boşluk değiştirilmeden temaya kopyalandı. `verify`, PNG'yi çalışan eklenti kopyasıyla bayt bazında; iki SVG'yi yukarıdaki sabit SHA-256 değerleriyle karşılaştırır. Bunlar PLAN §38'de kayıtlı üç tam renk istisnasıdır; CSS ham renk eşiği 0 kalır.

Kart kaynağı 200×21 pikseldir ve 28 CSS px yüksekliğe büyütülür. Şeritte ölçülen Visa iç yüksekliği `15/21 × 28 = 20 CSS px = 5,29 mm` olur. 2× retina ekranda 266,66×28 CSS px gösterim için kaynak yalnız 0,375 piksel/cihaz piksel sağlar; keskinlik yeterli kabul edilmedi ve vektör kart şeridi müşteri sorularına eklendi.
