# Faz 6B teknik raporu — marka hikâyesinin sanat yönü

Tarih: **2026-08-09**
Kapsam: `/hakkimizda/` marka hikâyesinin fotoğraf, geçiş, metin yerleşimi ve tipografik sanat yönü. Faz 6A'nın `IntersectionObserver`, sticky sahne, reduced-motion, mobil ve JavaScript-kapalı fallback mekanikleri kapsam dışı bırakıldı ve korunmuştur.

## Sonuç

**OLDU.** Altı sahne, iki dil, ayrı masaüstü/mobil medya, altı farklı geçiş, panel alanları, kontrast, açılış yükü ve mobil taşma kontrolleri geçti. İki bağımsız `make reset && make verify` turunun ikisi de `VERIFY=PASS` ve `SMOKE=PASS (5/5)` verdi.

## Uygulanan sanat yönü

| Sahne | Görsel yaklaşım | Geçiş | Metin konumu | Gradyan | Tipografik rol |
|---|---|---|---|---|---|
| 01 | Pastel deniz/ufuk | zoom-out | sol-alt | orta, açık | en büyük açılış cümlesi |
| 02 | Yumuşak gün doğumu/kıyı | crossfade-left | sol-orta | güçlü, açık | sakin manifesto paragrafı |
| 03 | Krem dokulu yüzey | fade-center | merkez | orta, açık | daha küçük, içe dönük blok |
| 04 | Su yüzeyinde ışık | line-sequence | sol-orta | güçlü, açık | ritmik satır dizisi |
| 05 | Güneşli kum dokusu | grow-right | sağ-orta | güçlü, açık | doğuş ve koleksiyon metni |
| 06 | Açık ufuk/gökyüzü | gather | merkez | orta, açık | büyük final, ardından küçük satırlar |

Metinler fotoğrafın üzerinde doğrudan yer alır; sert panel, gölge, text-shadow veya radius eklenmedi. 1, 2 ve 4. sahneler kullanıcı geri bildirimi üzerine koyu görünümden çıkarıldı: koyu metin ve daha aydınlık, yönlü açık gradyan kullanıldı. Final imzası `KÜBRA ♥︎` oldu; kalp `currentColor` ile yazıyla aynı rengi alır ve `aria-hidden="true"` olduğu için ekran okuyucu imzayı tekrar etmez.

Kapanış geri bildirimleri de aynı şema sürümüne alındı: ana hero başlığı TR `Kaçışınız için tasarlandı. Est. 2026`, EN `Designed for your escape. Est. 2026`; editoryal başlık TR `Sonsuz yazlar için tasarlandı`, EN `Designed for endless summers` oldu. Editoryal başlık ölçeği masaüstü ve mobilde küçültüldü. Footer ve diğer metinsel dış-bağlantı oklarına `U+FE0E` text-presentation seçicisi ile marka fontu uygulandı; böylece mobil Safari'nin `↗` karakterini mavi düğme emojisine dönüştürmesi engellendi.

`Est. 2026` iki dilde de hero başlığının ayrı satırına alındı. Dil menüsündeki hover/focus durumu artık metin rengini değiştirmez; yalnızca alt çizgi ekler. Ana sayfanın koyu duyuru şeridindeki genel hover kuralı dil kartı için özel olarak geçersiz kılındı, böylece açık kart üzerindeki dil seçeneği beyaza dönmez.

## Panel ve veri modeli

- Altı sahne için Türkçe/İngilizce metin, ayrı masaüstü/mobil görsel ve ton alanları korunmuştur.
- Her sahneye geçiş, metin konumu ve gradyan yoğunluğu eklendi: toplam `18/18` sanat yönü alanı.
- Doğrulanan medya alanı `24/24`; benzersiz geçiş `6/6`.
- Şema sürümü 7'ye yükseltildi; eski kayıtlar yeni sanat yönü varsayılanlarıyla güvenli biçimde zenginleştirilir.

## Görseller ve lisans

Altı Pexels kaynağı iki kadraja hazırlanır: masaüstü `1920×1080`, mobil `1200×1500`. İndirme sayfası, fotoğrafçı, lisans ve tarih [Görsel Kaynakları](GORSEL_KAYNAKLARI.md) belgesinde kayıtlıdır. Özgünler geçici dizinde tutulur; yalnızca web kadrajları seed sürecine girer. Görseller profesyonel Kuka Island çekimleri gelene kadar geçici sanat yönü medyasıdır.

## Ölçümler

| Kontrol | Sonuç |
|---|---|
| Türkçe satır kontrast örneği | 26 satır, en düşük medyan `13.35:1`, `<4.5:1` satır `0` |
| İngilizce satır kontrast örneği | 29 satır, en düşük medyan `13.35:1`, `<4.5:1` satır `0` |
| Açılış isteği | 30 asset; 1 görsel; yalnızca ilk hikâye görseli eager |
| Açılış sıkıştırılmış toplamı | `478,557` byte; hikâye görseli `88,734` byte |
| Mobil/duyarlı görünüm | 320, 360, 390, 480, 768, 1024, 1440 px: yatay taşma `0/7` |
| Kalp/yazı rengi | 390 px: ikisi de `rgb(60, 42, 18)`; 1440 px: ikisi de `rgb(18, 12, 6)` |
| Tasarım disiplini | ham renk `0`, ham px `0`, gölge `0`, tanımsız token `0` |

Kontrast ölçümü her render edilmiş satır kutusunda glyph/antialias örneklerini ayırıp kalan arka plan örneklerinin medyanını metin rengiyle karşılaştırır. Bu, piksel-minimum WCAG sertifikası değil; iki dilde satır bazlı görsel regresyon ölçümüdür.

## Fallback ve performans

Faz 6A'nın `IntersectionObserver`, sticky sahne, observer cleanup, no-scroll-listener, reduced-motion ve mobil/JS-off fallback sözleşmesi korunmuştur. Kapanış kontrolünde son sahneye hızlı atlandığında fotoğraf yüklenmeden önce eski medyanın kapanabildiği bir yarış durumu görüldü. Medya geçişi yarış-korumalı hâle getirildi: hedef fotoğraf yüklenene kadar mevcut fotoğraf görünür kalır, yükleme tamamlanınca hedef sahne atomik olarak devralır. Açılışta hâlâ yalnız ilk görsel istenir; ziyaretçi ikinci sahneye geçtikten sonra yalnız sıradaki sahne ısıtılır. Böylece 5. sahnedeyken özgün 6. sahne ufuk fotoğrafı hazırlanır ve finalde kum görseli bekleme yüzeyi olarak kalmaz. İkinci temiz doğrulama `server` progressive DOM ve `io+cleanup+no-scroll` sonucunu verdi. 320–480 px gerçek render'larda enhanced mod kapandı ve içerik statik akışta kaldı. Yeni animasyon kütüphanesi, vendor paketi, canlı anahtar veya deploy değişikliği yoktur.

## Kanıtlar

Tüm ekran görüntüleri, satır kutuları, kontrast JSON'ları, ağ envanteri, yedi viewport sonucu ve iki reset günlüğü [Faz 6B QA dizininde](qa/faz6b/README.md) bulunur.

## Bilinen sınırlar

- Pexels görselleri geçicidir; son üretim öncesi profesyonel Kuka Island çekimleriyle değiştirilmelidir.
- Kontrast hesabı medyan render örneğidir; bağımsız erişilebilirlik denetiminin yerine geçmez.
- Tarayıcı matrisi yerel Chromium render'ı ve yedi genişlikle sınanmıştır; gerçek iOS Safari/Android Chrome cihaz turu yayın öncesi ayrıca yapılmalıdır.
