# Faz 6A teknik raporu — marka hikâyesi

## Sonuç

`/hakkimizda/` altı panel kontrollü sahneden oluşur. `48em` ve üstünde, hareket azaltma kapalıyken sahne `sticky` kalır ve merkez çizgisini geçen görünmez adımlar `IntersectionObserver` ile aktif sahneyi değiştirir. Kaydırma yakalanmaz; scroll listener, snap, `preventDefault`, animasyon kütüphanesi ve `animation-timeline` yoktur.

`48em` eşiği; mobil adres çubuğu ile dinamik viewport değişimi, uzun sticky alanın geri gezinme maliyeti ve `100svh` kırılganlığı nedeniyle seçildi. Daha dar ekranda, JS kapalıyken ve `prefers-reduced-motion: reduce` altında aynı sunucu HTML'i düz makale olarak görünür: görsel üstte, metin altta. Sahnelerde `aria-hidden` yoktur; dekoratif medya geçişleri duyurulmaz.

## Ölçümler

| Ölçüm | Türkçe | English |
|---|---:|---:|
| Render edilen metin satırı | 48 | 51 |
| En düşük satır kontrastı | 13.48:1 | 13.48:1 |
| 4.5:1 altı satır | 0 | 0 |
| Açılış istek sayısı | 35 | 35 |
| Açılış encoded byte | 562,563 | 562,463 |
| Açılış hikâye görseli | 1 / 158,385 byte | 1 / 158,385 byte |

Kontrastta her metin bloğu gerçek Chrome render'ında DOM `Range.getClientRects()` ile satır kutularına ayrıldı; render edilen önplan ve panel zemini WCAG göreli parlaklık formülüyle satır satır ölçüldü. Ham kayıtlar `qa/faz6a/story-contrast-tr.json` ve `story-contrast-en.json` içindedir. Ağ ölçümü yeni gizli Chrome bağlamında, cache kapalı, 1440×900, load sonrası iki saniye penceresinde yapıldı; ham kayıt `story-opening-network.json` dosyasındadır.

İlk sahne `eager` ve yüksek önceliklidir. Diğer beş sahnenin `src/srcset` değerleri aktif olana kadar ertelenir ve `loading="lazy"` taşır. Mobil/reduced-motion düz makalede tarayıcı lazy yükleme yapar. Sahneler düz makalede `content-visibility: auto` kullanır. Observer `pagehide` sırasında ve mod değişiminde sökülür.

## Kaynak metin

Manifesto PDF gövdesi sahne 02–06 panel metinleri normalize edilerek karşılaştırıldı: fark `0`. PDF'de olmayan fakat kabul dağılımında açıkça istenen “Bir yer değil. Bir his.” açılışı sahne 01'dir. İmza `Love,` / `KÜBRA` olarak iki satır ve değişmeden saklanır. Otomatik kapı `STORY_PDF_BODY_MATCH=yes` sonucunu üretir.

## Kanıt dizini

- Altı masaüstü sahne: `qa/faz6a/story-tr-scene-01-1440.png` … `story-tr-scene-06-1440.png`
- Sahne 04 satır sırası: `story-tr-scene-04-lines-1.png` … `lines-4.png`
- Mobil düz makale: `story-tr-mobile-320.png`, `story-tr-mobile-390.png`
- Reduced motion ve JS kapalı: `story-tr-reduced-motion-1440.png`, `story-tr-js-disabled-1440.png`, `story-js-disabled-dom.json`
- İngilizce: `story-en-scene-01-1440.png`
- Panel: `story-panel-scenes.png` — 6 sahne, 12 metin, 24 medya, 12 ton alanı; ekle/çıkar testi 6→7→6

Gerçek cihaz Core Web Vitals turu yapılmadı ve profesyonel hikâye çekimleri henüz yoktur; ikisi de `BILINEN_SINIRLAMALAR.md` içinde açık kalır.
