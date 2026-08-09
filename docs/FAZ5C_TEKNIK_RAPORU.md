# Faz 5C — Header kontrastı teknik raporu

Tarih: 2026-08-09  
Ortam: yerel Docker, `http://localhost:8080`  
Kısıt: deploy ve canlı anahtar kullanılmadı.

## Seçilen çözüm

Header hero üzerinde kalır; yalnız kendi yüksekliğinde `%82` kâğıt yüzeyi kullanır ve tüm etkileşim/marka öğeleri koyu marka rengini devralır. Bu seçenek, müşterinin değiştirebildiği hero fotoğrafının açık/koyu dağılımından bağımsızdır. Fotoğrafın gövdesine perde eklemez; menü, arama, sepet, sayaç, mobil menü ve iki palmiye dahil marka kilidini aynı güvenli yüzeyde tutar.

Hero metin perdesi masaüstünde sağa doğru, mobilde yukarı doğru saydamlaşır. Sağ uzama için içerik ölçüsünde fiziksel pay ayrıldığı için `overflow-x` ile gizleme kullanılmaz.

## Render piksel ölçümü

Ön plan `rgb(60, 42, 18)` değeri render edilmiş öğelerin `getComputedStyle` sonucudur. Arka plan değerleri tarayıcı ekran görüntülerindeki gerçek piksellerden örneklenmiştir; token tablosundan hesaplanmamıştır.

| Render / bölge | Arka plan pikseli | Kontrast |
|---|---:|---:|
| Varsayılan hero, header koyu fotoğraf bölgesi | `rgb(214, 218, 221)` | **9.75:1** |
| Varsayılan hero, header açık fotoğraf bölgesi | `rgb(248, 245, 240)` | **12.60:1** |
| Alternatif hero, header açık fotoğraf bölgesi | `rgb(247, 246, 241)` | **12.67:1** |
| Alternatif hero, header koyu/mavi fotoğraf bölgesi | `rgb(208, 211, 218)` | **9.15:1** |
| Mobil header, koyu fotoğraf bölgesi | `rgb(213, 215, 214)` | **9.48:1** |
| Mobil header, açık fotoğraf bölgesi | `rgb(230, 223, 215)` | **10.38:1** |
| Hero başlığı, masaüstü en düşük örnek | `rgb(204, 204, 204)` | **8.54:1** |
| Hero başlığı, mobil en düşük örnek | `rgb(199, 198, 194)` | **8.02:1** |

Menü, arama, sepet ikonu, sayaç, mobil menü ikonu, marka yazısı ve palmiye SVG'leri aynı render edilmiş `currentColor` değerini kullandığı için header tablosundaki en düşük oranlarının tümü **9.15:1** masaüstü ve **9.48:1** mobildir.

## Görsel kanıt

- Önce, keskin dikdörtgen: [masaüstü](qa/faz4c-after-hero-desktop.png), [mobil](qa/faz4c-after-hero-mobile.png)
- Sonra, varsayılan hero + yumuşak perde: [1440](qa/faz5c/01-header-hero-after-1440.png)
- Panel alanı: [`ignore_discounts`](qa/faz5c/02-ignore-discounts-panel.png)
- Panelden seçilen ikinci hero: [panel](qa/faz5c/05-alternate-hero-panel.png), [render](qa/faz5c/03-header-alternate-hero-1440.png)
- Mobil header + dikey geçiş: [390](qa/faz5c/04-mobile-header-gradient-390.png)
- Responsive set: `qa/faz5c/responsive-{320|390|768|1024|1280|1440|1920}.png`
- Temiz kurulumlar: [tur 1](qa/faz5c/reset-verify-1.txt), [tur 2](qa/faz5c/reset-verify-2.txt)

## Panel ve WooCommerce eşleşmesi

Alan iki açıklamalı seçenek sunar: varsayılan **indirimden sonraki tutar** (`no`) ve **indirimden önceki tutar** (`yes`). Panelde `yes` seçilip kaydedildiğinde hem Site Görünümü hem WooCommerce free-shipping instance değeri `yes` ölçüldü (`ignore-discounts-panel-yes.txt`). Temiz seed varsayılanı `no` olarak ayrıca verify kapısındadır. Sepet ilerleme metni WooCommerce'in aynı indirim/vergi çıkarma sırasını kullanır; fiyat motoru yeniden yazılmamıştır.

## Kabul kriterleri

| # | Sonuç | Kanıt |
|---:|---|---|
| 1 | Karşılandı | İki fotoğraf bölgesinde 9.15–12.67:1; tüm header öğeleri ortak render rengi; tablo + `01`, `03` |
| 2 | Karşılandı | İkinci görsel panelden medya ID 12 olarak seçildi; `05` panel ve `03` render |
| 3 | Karşılandı | Mobil menü/marka kilidi 9.48–10.38:1; `04` |
| 4 | Karşılandı | Faz 4C keskin önce görüntüleri ve Faz 5C yatay/dikey yumuşak sonra görüntüleri |
| 5 | Karşılandı | Hero başlığı masaüstü 8.54:1, mobil 8.02:1 |
| 6 | Karşılandı | Açıklamalı panel seçimi; `yes` senkron testi ve verify'da varsayılan `no` |
| 7 | Karşılandı | Token disiplin kapısı 0; yedi viewport `scrollWidth = innerWidth` |
| 8 | Yerel kapı karşılandı, CI push sonrası | İki temiz `make reset && make verify` turu `VERIFY=PASS`; ikisinde smoke `5/5` |
