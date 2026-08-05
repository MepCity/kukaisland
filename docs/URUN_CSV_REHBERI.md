# Ürün CSV şablonu kullanım rehberi

`URUN_AKTARIM_SABLONU.csv`, müşteri kataloğunu WooCommerce'e hazırlamak için kullanılan veri toplama şablonudur. Örnek satır içe aktarılmamalı; gerçek ürün satırlarıyla değiştirilmelidir.

## Bir satır neyi temsil eder?

Her satır bir renk + beden varyasyonunu temsil eder. Aynı ana ürünün tüm satırlarında ana SKU kökü, ürün adı, kesim, kategori ve açıklama aynı yazılır; renk, beden, stok ve gerekiyorsa görseller değişir. Renk ve beden değerleri WordPress'teki global `Renk` ve `Beden` nitelikleriyle birebir eşleşmelidir.

## Sütunlar

| Sütun | Kural |
|---|---|
| `sku` | Benzersiz varyasyon SKU'su; boşluk kullanmayın. |
| `urun_adi` | Müşteriye görünen özgün ürün adı. |
| `kesim` | Global Kesim niteliğindeki değer. |
| `kategori` | WooCommerce kategori adı. |
| `renk` | Global Renk niteliğindeki değer. |
| `beden` | Global Beden değerlerinden biri: 34–42 veya XS–XL. |
| `fiyat` | Para birimi simgesiz, vergisel karara uygun sayısal fiyat. |
| `stok` | Bu varyasyonun tam sayı stok adedi. |
| `gorsel_dosya_adlari` | Önce ana görsel; birden fazlaysa `|` ile ayırın. Dosya adları teslim edilen medya ile birebir aynı olmalı. |
| `aciklama` | Ürüne özgü, başka kaynaktan kopyalanmamış açıklama. |
| `kumas` | Kumaş bileşimi ve oranları. |
| `bakim` | Yıkama, kurutma ve kullanım talimatı. |
| `kalip` | Dar/standart/rahat kalıp ve iki beden arası öneri. |
| `model_olcusu` | Modelin boyu ile çekimde giydiği beden; örnek: `178 cm / 36 beden`. |

## İçe aktarma öncesi

1. CSV UTF-8 ve virgül ayrımlı kaydedilir.
2. SKU tekrarı, boş zorunlu hücre, negatif stok ve bulunamayan görsel adı kontrol edilir.
3. Önce staging üzerinde küçük bir ürün grubu içe aktarılır.
4. Varyasyon, fiyat, stok, renk swatch'ı, beden ve görseller ön yüzde doğrulanır.
5. SEO başlığı ve meta açıklaması ürün panelindeki Kuka Island alanlarında ürün bazında tamamlanır; bu iki alan toplu içe aktarma eşlemesi ayrıca onaylanmadan otomatik yazılmaz.

WooCommerce alan eşlemesi katalog yapısına göre staging'de kaydedilir. Canlıda doğrudan, ön izlemesiz toplu içe aktarma yapılmaz.
