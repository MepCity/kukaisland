# Faz 5C — müşteri onay düzeltmeleri teknik raporu

Tarih: 2026-08-09  
Ortam: yerel Docker, `http://localhost:8080`  
Kısıt: deploy ve canlı anahtar kullanılmadı.

## D–E — Perdesiz hero ve iki durumlu header

Hero metin perdesi, `--hero-overlay-strength` tokenı ve panel alanı tamamen kaldırıldı. Metin, gölge kullanılmadan fotoğrafın üstünde durur. İlk yerleşimde başlığın sağ ucu siyah ürüne taşıdığı için AA sağlanmıyordu; ayrı `--measure-hero-copy` ve `--text-heading-hero` tokenlarıyla başlık mevcut görselin açık beton alanına gerçekten sığdırıldı.

`hero.text_tone` alanı korunur ve panelde “Fotoğrafınızın metin bölgesi koyuysa açık, açıksa koyu seçin.” açıklamasını gösterir. Görsel değiştirildiğinde bu seçim ve kontrast yeniden ölçülmelidir; sınır `docs/BILINEN_SINIRLAMALAR.md` içindedir.

Ana sayfanın tepesinde header, duyuru/dil seçici, menü, arama, sepet, sayaç, mobil menü ve palmiye amblemleri beyazdır. Yalnız header şeridinde `%72` koyu yüzey bulunur; bu, hero gövdesini örtmeden fotoğrafın dağılımından bağımsız kontrast sağlar. Kaydırma eşiği render edilmiş header yüksekliğidir: `63` birimde üst durum, `64` birimde kâğıt/koyu durum ölçüldü. JS yoksa `.has-js` sınıfı eklenmediği için başlangıç doğrudan kâğıt/koyudur. Diğer sayfalar daima bu normal durumu kullanır.

### Render ölçümleri

| Yüzey / örnek | Ön plan | Render arka plan pikseli | Kontrast |
|---|---:|---:|---:|
| Header — koyu fotoğraf bölgesi | `rgb(255, 253, 248)` | `rgb(54, 51, 46)` | **12.37:1** |
| Header — açık fotoğraf bölgesi | `rgb(255, 253, 248)` | `rgb(107, 94, 77)` | **6.20:1** |
| Duyuru + dil seçici | `rgb(255, 253, 248)` | `rgb(94, 85, 70)` | **7.21:1** |
| Kaydırılmış/diğer sayfa header | `rgb(60, 42, 18)` | `rgb(251, 248, 242)` | **12.93:1** |
| Hero başlığı — mevcut görselde en koyu beton örneği | `rgb(60, 42, 18)` | `rgb(199, 192, 186)` | **7.62:1** |

Menü, marka kilidi, palmiyeler, arama, sepet, sayaç ve mobil menü renderda aynı `currentColor` değerini kullanır. Görsel kanıtlar: [önceki perdeli hero](qa/faz5c/01-header-hero-after-1440.png), [perdesiz ve koyu tonlu hero](qa/faz5c/hero-no-overlay-token-fit-1280.png), [aynı görselde açık ton seçimi](qa/faz5c/hero-text-tone-light-1280.png), [beyaz üst header](qa/faz5c/header-top-white-no-hero-overlay-1440.png), [kâğıt/koyu kaydırılmış header](qa/faz5c/header-scrolled-paper-dark-1440.png), [mobil üst durum](qa/faz5c/header-top-mobile-white-no-overlay-390.png).

## C — Ücretsiz kargo kupon tabanı

`ignore_discounts`, Site Görünümü panelinde açıklamalı “indirimden sonra/önce” seçimi olarak yönetilir. Varsayılan `no` değişmedi. Panelde `yes` seçilip kaydedildiğinde Site Görünümü ile WooCommerce free-shipping instance değeri birlikte `yes` ölçüldü: [panel](qa/faz5c/02-ignore-discounts-panel.png), [senkron çıktı](qa/faz5c/ignore-discounts-panel-yes.txt).

## F–G — İngilizce ilk geçiş ve dil etiketleri

Yasal olmayan içeriklerde Türkçe fallback sunum akışından çıkarıldı. Temiz seed; `42/42` Site Görünümü EN alanını, `16/16` yasal olmayan sayfa başlık/gövde alanını ve dört ürünün `36/36` görünür İngilizce alanını doldurur. Otomatik çeviri API'si veya çeviri eklentisi kullanılmadı.

Marka hikâyesi Kübra'nın birinci ağız sıcaklığını korur. `The sea… / Summer… / Freedom…` kısa satır dizisi ve `Love, KÜBRA` imza bloğu yayındadır. Müşteri gözden geçirmesi `MUSTERI_SORULARI.md` içinde kayıtlıdır. Sekiz hukuk sayfasının EN alanları `0/16` boş kalır; “bağlayıcı sürüm Türkçedir” uyarısı yalnız bu sekiz sayfada görünür. Kanıtlar: [tarama](qa/faz5c/en-fallback-scan.txt), [İngilizce Hakkımızda](qa/faz5c/en-about-first-pass.png), [yasal fallback](qa/faz5c/en-legal-binding-fallback.png).

Dil seçici iki dilde de kendi dil adlarını `Türkçe` ve `English` olarak gösterir; seed kayıtlı eski değeri de düzeltir.

## H — Footer marka kilidi

Footer'a özel `30–58` ölçeği ve amblem tokenı kaldırıldı. Footer artık header ile aynı `.kuka-logo` kuralını, `16` yazı ölçeğini ve aynı orantılı palmiye ölçüsünü devralır. Telif satırı aralığı bir kademe azaltıldı.

İki önceki tasarım denemesi [A](qa/faz4c-footer-trial-a.png) ve [B](qa/faz4c-footer-trial-b-selected.png) içinde büyük kilit ikincil bir odak oluşturuyordu. Seçilen yeni yaklaşım header ölçeğini tekrar ederek link mimarisini önde bırakır: [güncel footer](qa/faz5c/footer-newsletter-whatsapp-desktop.png).

## I — Bülten formu

E-posta alanı kalıcı etiket ve `name@example.com` placeholder kullanır; kendi alt çizgisi vardır ve düğmeyle çizgi paylaşmaz. Düğme ortak `.kuka-button` dilindedir, mobilde tam genişliktir. Kare onay kutusunun radiusu `0`, işaretli zemini marka rengidir; bütün etiket `48` yüksekliğinde tıklama hedefidir.

| Render ölçümü | Sonuç |
|---|---:|
| Masaüstü e-posta alanı | `344.24 × 48` |
| Masaüstü düğme | `59.76 × 48` |
| Masaüstü onay etiketi hedefi | `420 × 48` |
| Mobil düğme | `358 × 48`, tam genişlik |
| Mobil onay etiketi hedefi | `358 × 48` |
| Etiket/onay metni kontrastı | **11.35:1** |
| Hata mesajı kontrastı | **6.04:1** |
| Bülten kapsamındaki mavi renk taraması | **0** |
| TR ve EN mobil yatay taşma | **0** |

Klavye testinde kutu odaktayken `Space` sonrasında `active=true`, `checked=true` ölçüldü. JS'siz protokol testinde native `required` hem e-posta hem onay alanında bulundu; form nonce ile normal POST edildi, başarı yanıtı döndü ve `qa-js-off-faz5c@example.com` kaydı onay metni/tarih/IP ile veritabanına düştü. Honeypot, hız sınırı, panel kayıt listesi/CSV ve tek bildirim adresi altyapısı değiştirilmedi; toplu gönderim hâlâ yoktur.

Kanıtlar: [masaüstü + normal çizgi](qa/faz5c/footer-newsletter-whatsapp-desktop.png), [odak](qa/faz5c/newsletter-focus-desktop.png), [mobil](qa/faz5c/newsletter-mobile-390.png), [İngilizce mobil](qa/faz5c/newsletter-english-390.png), [hata](qa/faz5c/newsletter-error-390.png), [klavyeyle işaretli](qa/faz5c/newsletter-checkbox-keyboard-checked.png).

## J — Footer WhatsApp

WhatsApp, panel sosyal bağlantılarından sonra otomatik eklenir. Footer, servis şeridi ve yüzen düğme aynı `brand.whatsapp_phone` alanını ve aynı URL yardımcısını kullanır. Varsayılan bağlantı `https://wa.me/905309481996` olarak ölçüldü. Telefon boşken footer WhatsApp ve yüzen düğme kayboldu, Instagram kaldı. Geçici `0532 111 22 33` değerinde üç yüzey de `https://wa.me/905321112233` üretti; sonra seed varsayılanı geri yüklendi.

Kanıtlar: [varsayılan](qa/faz5c/footer-newsletter-whatsapp-desktop.png), [telefon boş](qa/faz5c/footer-whatsapp-empty.png), [değişen footer](qa/faz5c/footer-whatsapp-changed.png), [değişen servis şeridi](qa/faz5c/whatsapp-service-changed.png).

## Kabul kriterleri özeti

| # | Sonuç | Kanıt |
|---:|---|---|
| 9–10 | Karşılandı | Perde/gradient katmanı yok; token ve panel alanı verify kapısında yok |
| 11 | Karşılandı | Mevcut görselde en düşük örnek **7.62:1**; güncel hero görüntüsü |
| 12 | Karşılandı | Açıklamalı iki panel seçeneği; koyu ve açık tonun ayrı render görüntüleri |
| 13–14 | Karşılandı | Üst/kaydırılmış görüntüler; header öğeleri **6.20–12.93:1** |
| 15 | Davranış doğrulandı; JS-kapalı ekran görüntüsü yok | Base kural kâğıt/koyu, açık durum yalnız `.has-js` altında |
| 16 | Karşılandı | Açık durum `.home` ile sınırlı; Hakkımızda renderı normal header |
| 17–22 | Karşılandı | İngilizce tarama, ürün/sayfa seed sayıları ve F bölümündeki render kanıtları |
| 23 | Karşılandı | İki eski tasarım + güncel header ölçekli footer |
| 24 | Karşılandı | Token kapıları 0; yedi genişlikte ölçülen yatay taşma 0 |
| 25 | Yerel kapılar karşılandı; CI sonucu push sonrası izlenecek | İki temiz `make reset && make verify`: `VERIFY=PASS`, smoke `5/5` |
| 26–35 | Karşılandı | I bölümündeki ölçüm, native POST ve altı render kanıtı |
| 36–39 | Karşılandı | J bölümündeki varsayılan/boş/değişen tek kaynak kanıtları |
