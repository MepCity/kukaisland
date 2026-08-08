# Faz 5B — İngilizce dil desteği teknik raporu

Tarih: 2026-08-09  
Ortam: yerel Docker, `http://localhost:8080`  
Kapsam: Türkçe varsayılan + URL-kaynaklı `/en/`; deploy ve canlı anahtar kullanılmadı.

## Mimari ve ölçülen envanter

- Çeviri eklentisi: **0**. Aktif eklenti taraması `TRANSLATION_PLUGIN=none`.
- Site Görünümü: **42** çevrilebilir alan çifti. URL, sayı, medya ve şirket alanları ortak kaldı.
- Ürün modeli: **4 ürün / 24 varyasyon**; ikinci ürün kaydı yok. EN ürün şeması **5 meta alanı**.
- İçerik modeli: **15/15** istenen sayfada aynı kayıt üzerinde **2 EN meta alanı** kullanılabilir.
- Yasal metinler: **0/16 dolu EN başlık/içerik değeri**; sekiz metin bilerek boş.
- Taksonomi: kategori, renk, kesim ve beden için tek `_kuka_name_en` term metası.
- Locale: `/en/` → `en_US`; siparişte `_kuka_order_locale`; e-posta sonrası locale `tr_TR>en_US>tr_TR` olarak geri yükleniyor.
- Responsive: Türkçe ve İngilizce için 320, 390, 768, 1024, 1280 ve 1920 genişliklerinin **12/12** ölçümünde `scrollWidth = innerWidth`.
- Tasarım disiplini: ham renk **0**, ham px **0**, gölge **0**, tanımsız token **0**, vendor değişikliği **0**.

## Davranış kararları

- Dilin tek kaynağı URL'dir; cookie yoktur.
- İngilizce alan boşsa Türkçe kaynak görünür. Genel sayfada bilgi notu, yasal sayfada Türkçe sürümün bağlayıcı olduğunu söyleyen özel not vardır.
- WooCommerce teknik sayfaları (mağaza, sepet, ödeme) kendi İngilizce çekirdek arayüzünü kullanır; bu sayfalara içerik-fallback uyarısı eklenmez.
- Woo AJAX istekleri `/en/odeme/` referer'ından locale'i korur; sipariş özeti sonradan Türkçeye dönmez.
- Ürün, sayfa ve terim için ikinci kayıt yoktur. Fiyat, stok, SKU, varyasyon ve görsel ortak kayıttır.
- Otomatik çeviri, yasal metin çevirisi ve örnek İngilizce seed içeriği yoktur. Görsellerdeki `EN-QA-*` değerleri yalnız kabul turunda geçici olarak yazıldı ve reset ile silindi.

## SEO ve route ölçümü

- `/en/`, katalog, ürün, sepet, ödeme, takip ve içerik route'ları HTTP 200.
- Ürün canonical'ı `/en/urun/azur-bralet-bikini-ustu/` olarak ölçüldü.
- Aynı üründe `hreflang=tr`, `en`, `x-default`; x-default Türkçe URL.
- `og:locale` İngilizcede `en_US`, Türkçede `tr_TR`.
- `wp-sitemap.xml` içinde `wp-sitemap-english-1.xml`; EN sitemap ürün, sayfa ve ürün taksonomilerini içerir, 302 dönen `/en/hesabim/` içermez.
- XML kaynak sayfası Chrome istemcisinde `ERR_BLOCKED_BY_CLIENT` ile görselleştirilemedi; sitemap kriteri curl + smoke ile makine kanıtlıdır, ekran görüntülü kabul sayılmamıştır.

## Görsel kanıt dizini

Ana kanıtlar:

- [İngilizce katalog](qa/faz5b/01-en-catalog.jpg)
- [İngilizce ürün](qa/faz5b/02-en-product.jpg)
- [İngilizce içerik sayfası](qa/faz5b/03-en-page.jpg)
- [Yasal Türkçe fallback + bağlayıcılık notu](qa/faz5b/04-legal-fallback.jpg)
- [Bulunulan ürün sayfasına bağlı dil seçici](qa/faz5b/05-language-switcher-current-page.jpg)
- [İngilizce sepet](qa/faz5b/06-en-cart.jpg)
- [İngilizce checkout ve AJAX sipariş özeti](qa/faz5b/07-en-checkout.jpg)
- [42 alanlı iki dilli Site Görünümü](qa/faz5b/08-site-appearance-bilingual.jpg)
- [Beş ürün EN alanı](qa/faz5b/09-product-english-fields.jpg)
- [Taksonomi EN term meta alanı](qa/faz5b/10-taxonomy-english-field.jpg)
- [Sayfa EN başlık/içerik alanı](qa/faz5b/11-page-english-fields.jpg)
- [Boş yasal EN alanları](qa/faz5b/12-legal-fields-empty.jpg)
- [Çeviri eklentisi bulunmayan eklenti listesi](qa/faz5b/13-plugin-list.jpg)
- [İngilizce sipariş e-postası + EN ürün adı](qa/faz5b/14-english-order-email.jpg)
- [320 genişlikte uzun EN ürün başlığı](qa/faz5b/16-long-english-title-320.jpg)
- [Türkçe ürün, ortak fiyat/SKU](qa/faz5b/17-tr-product-shared-commerce.jpg)
- [Filtrede EN term meta](qa/faz5b/19-en-taxonomy-filter.jpg)
- [İngilizce sipariş takibi](qa/faz5b/20-en-order-tracking.jpg)
- [Tek kayıtlı dört ürün](qa/faz5b/21-product-list-four-records.jpg)
- [Temiz seed EN değer sayımı](qa/faz5b/seed-english-values.txt)
- [Birinci temiz reset + verify günlüğü](qa/faz5b/reset-verify-1.txt)
- [İkinci temiz reset + verify günlüğü](qa/faz5b/reset-verify-2.txt)
- [Vendor diff çıktısı](qa/faz5b/vendor-diff.txt)

Responsive kanıtlar `qa/faz5b/responsive-{tr|en}-{320|390|768|1024|1280|1920}.jpg` adlandırmasıyla **12 görüntüdür**.

## Kabul kriterleri

| # | Sonuç | Kanıt |
|---:|---|---|
| 1 | Karşılandı | Home/katalog/ürün/sepet/ödeme/sayfa ekranları + English smoke |
| 2 | Karşılandı | Üründe “Add to cart”, checkout'ta İngilizce çekirdek alanlar; `02`, `07` |
| 3 | Karşılandı | Türkçe route smoke'u, `17` ve TR responsive seti |
| 4 | Karşılandı | Beş URL eşleşmesi ölçüldü; ürün örneği `05` |
| 5 | Karşılandı | Genel fallback `03`; yasal fallback `04`; boş sayfa yok |
| 6 | Makine kanıtlı, görsel kabul değil | Canonical/hreflang DOM ölçümü doğru; kaynak metadata ekranı tarayıcı politikasıyla açılamadı |
| 7 | Makine kanıtlı, görsel kabul değil | İki dil sitemap curl + smoke PASS; XML tarayıcı istemcisinde engellendi |
| 8 | Karşılandı | 42 alan; sayı/URL ikizi yok; `08` |
| 9 | Karşılandı | Boş EN alanlı ana sayfa İngilizce route'ta Türkçe fallback; EN responsive seti |
| 10 | Karşılandı | Beş ürün EN alanı; `09` |
| 11 | 4/5 yüzey görsel, JSON-LD makine kanıtlı | Katalog `01`, ürün `02`, sepet `06`, checkout `07`; JSON-LD `name/description` DOM ölçümü doğru fakat kaynak görüntüsü yok |
| 12 | Karşılandı | `21`; sorgu 4 ürün / 24 varyasyon |
| 13 | Karşılandı | TR/EN fiyat ₺2.690, SKU KI-TOP-002 ve tek varyasyon kaydı; `02`, `17` |
| 14 | Karşılandı | Term alanı `10`, filtre `19` |
| 15 | Karşılandı | 15/15 sayfa; alan `11`; kısa kodlu EN QA sayfası `03` |
| 16 | Karşılandı | EN değer 0/16; boş alan `12`; fallback `04` |
| 17 | Kısmi görsel + nihai HTML makine kanıtı | `14` başlık/gövde/ürün/takip İngilizceydi; görsel sonrası bulunan Türkçe ek içerik düzeltildi. Nihai `english-order-email.html` tamamen İngilizce, fakat düzeltme sonrası yeni ekran görüntüsü yok |
| 18 | Karşılandı | E-posta ürün adı `EN-QA-KI-TOP-002`; `14` |
| 19 | Karşılandı | Etiket, hata/onay metni ve AJAX özeti; `07` + English smoke |
| 20 | Makine kanıtlı | Temiz reset sonrası ürün/sayfa, terim ve Site Görünümü EN seed değerleri ayrı ayrı `0`; `seed-english-values.txt`. Otomatik çeviri entegrasyonu yok; taramadaki tek eşleşme bu durumu sınayan verify deseni |
| 21 | Karşılandı | Aktif çeviri eklentisi 0; `13` |
| 22 | Karşılandı | 12/12 viewport taşma 0; uzun EN başlık 320'de taşma 0; 13 görüntü |
| 23 | Karşılandı | İki bağımsız temiz `make reset && make verify` turu `VERIFY=PASS`, her ikisinde smoke `5/5`; `reset-verify-1.txt`, `reset-verify-2.txt` |
| 24 | Karşılandı | Vendor diff boş (`vendor-diff.txt`); GitHub Actions Quality run `31284362895` production checks PASS |

Görsel kanıtı bulunmayan 6, 7 ve 20 ile JSON-LD alt maddesi ve e-posta düzeltme-sonrası görüntüsü özellikle “ekran görüntülü karşılandı” olarak yazılmamıştır.

İlk temiz tur, e-posta ek içeriğinin Türkçe locale'de önbelleğe alınmasını yakaladı; düzeltme ve yeniden başlatılan iki geçerli tur öncesindeki bu negatif regresyon kanıtı `reset-verify-failed-email.txt` dosyasında korunmuştur.

## Kapsam dışı

TRY dışı para birimi, yurt dışı kargo, ihracat faturası, yabancı tüketici hukuku, İngilizce slug'lar ve sekiz yasal metnin İngilizce çevirisi Faz 5B kapsamında değildir. İngilizce kullanım amacı Türkiye'ye satış yapan mağazada yabancı ziyaretçinin arayüzü anlaması varsayımıdır.
