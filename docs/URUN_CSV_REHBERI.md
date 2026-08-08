# Ürün CSV şablonu kullanım rehberi

`URUN_AKTARIM_SABLONU.csv`, müşteri kataloğunu WooCommerce'e hazırlamak için kullanılan veri toplama şablonudur. Örnek satır içe aktarılmamalı; gerçek ürün satırlarıyla değiştirilmelidir.

## Bir satır neyi temsil eder?

Her satır bir renk + beden varyasyonunu temsil eder. Aynı ana ürünün tüm satırlarında ana SKU kökü, ürün adı, kesim, kategori ve açıklama aynı yazılır; renk, beden, stok ve gerekiyorsa görseller değişir. Renk ve beden değerleri WordPress'teki global `Renk` ve `Beden` nitelikleriyle birebir eşleşmelidir.

## Sütunlar

| Sütun | Kural |
|---|---|
| `sku` | Benzersiz varyasyon SKU'su; boşluk kullanmayın. |
| `urun_adi` | Müşteriye görünen özgün ürün adı. |
| `urun_adi_en` | İngilizce ürün adı; boşsa Türkçe ad gösterilir. |
| `kesim` | Global Kesim niteliğindeki değer. |
| `kesim_en` | Kesim teriminin İngilizce adı; aynı terimin `_kuka_name_en` metasına eşlenir. |
| `kategori` | WooCommerce kategori adı. |
| `kategori_en` | Kategori teriminin İngilizce adı; boşsa Türkçesi kullanılır. |
| `renk` | Global Renk niteliğindeki değer. |
| `renk_en` | Renk teriminin İngilizce adı; boşsa Türkçesi kullanılır. |
| `beden` | Global Beden değerlerinden biri: 34–42 veya XS–XL. |
| `beden_en` | Aynı beden teriminin İngilizce adı; S/M/L için boş bırakılabilir. |
| `fiyat` | Para birimi simgesiz, vergisel karara uygun sayısal fiyat. |
| `stok` | Bu varyasyonun tam sayı stok adedi. |
| `gorsel_dosya_adlari` | Önce ana görsel; birden fazlaysa `|` ile ayırın. Dosya adları teslim edilen medya ile birebir aynı olmalı. |
| `kisa_aciklama` / `kisa_aciklama_en` | Türkçe ve İngilizce kısa açıklama. İngilizce boşsa Türkçe fallback gösterilir. |
| `aciklama` / `aciklama_en` | Türkçe ve İngilizce uzun açıklama. İngilizce boşsa Türkçe fallback gösterilir. |
| `seo_basligi_en` / `meta_aciklamasi_en` | İngilizce SEO alanları; müşteri tarafından sağlanmadıysa boş bırakılır. |
| `kumas` | Kumaş bileşimi ve oranları. |
| `bakim` | Yıkama, kurutma ve kullanım talimatı. |
| `kalip` | Dar/standart/rahat kalıp ve iki beden arası öneri. |
| `model_olcusu` | Modelin boyu ile çekimde giydiği beden; örnek: `178 cm / 36 beden`. |

## İçe aktarma öncesi

1. CSV UTF-8 ve virgül ayrımlı kaydedilir.
2. SKU tekrarı, boş zorunlu hücre, negatif stok ve bulunamayan görsel adı kontrol edilir.
3. Önce staging üzerinde küçük bir ürün grubu içe aktarılır.
4. Varyasyon, fiyat, stok, renk swatch'ı, beden ve görseller ön yüzde doğrulanır.
5. İngilizce hücreler otomatik çevrilmez ve yer tutucuyla doldurulmaz. Müşteri metni yoksa boş kalır; vitrinde Türkçe kaynak gösterilir.
6. Türkçe/İngilizce ürün içeriği aynı ürün kaydına yazılır. Fiyat, stok, SKU, varyasyon ve görseller için ikinci kayıt oluşturulmaz.

WooCommerce alan eşlemesi katalog yapısına göre staging'de kaydedilir. Canlıda doğrudan, ön izlemesiz toplu içe aktarma yapılmaz.
