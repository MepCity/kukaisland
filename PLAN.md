# Kuka Island E-Ticaret Projesi — Ana Uygulama Planı

> **Belge durumu:** Onaylı ana plan / tek doğruluk kaynağı; müşteri girdileri faz önkoşulu olarak açıktır
> **Sürüm:** 2.5 (katalog ve ürün detay veri kaynağı birleştirildi)
> **Tarih:** 4 Ağustos 2026
> **Proje dizini:** `~/Desktop/kukaisland-canli` (kanonik üretim deposu)
> **Hedef pazar:** Türkiye
> **Başlangıç dili ve para birimi:** Türkçe / TRY
> **Üretim altyapısı:** WordPress + WooCommerce
> **Faz 1 prototip altyapısı:** Next.js + Cloudflare Workers (bu depo) — yalnızca tasarım sunumu, üretim mağazası değil
> **Tasarım referansı:** Jacquemus'un mağaza deneyiminden esinlenen, özgün bir bikini ve mayo mağazası

---

## 1. Bu belgenin amacı ve kullanım kuralı

Bu dosya projenin tasarım, geliştirme, yönetim paneli, içerik, entegrasyon, test ve canlıya alma kararlarının ana kaynağıdır. Projeyi daha sonra devralan geliştirici veya yapay zekâ önce bu dosyanın tamamını okumalı, ardından mevcut kodu incelemelidir.

Bu dosyada açıkça belirtilen kararlar, konuşmalardaki geçici fikirlerden daha önceliklidir. Plan değişirse eski madde sessizce silinmemeli; §38 Karar Günlüğü'ne tarih ve gerekçe eklenerek belge güncellenmelidir.

Uygulama sırasında uyulacak temel çalışma kuralı:

1. Önce ilgili aşamanın kapsamı okunur.
2. Bir aşamanın kabul kriterleri tamamlanmadan sonraki aşamaya geçilmez.
3. Müşteri onayı gereken noktalar onaysız varsayımla üretime taşınmaz.
4. Tasarım ve içerik birbirinden, tema ve işlevler birbirinden ayrılır.
5. Üçüncü taraf lisans satın almadan önce gerçekten gerekli olduğu kanıtlanır.
6. Kod, panelde yönetilecek veri yapısı düşünülerek yazılır.
7. Eski prototip veya önceki çalışma bu projenin kod tabanı olarak kullanılmaz.

---

## 2. Projenin özeti

Kuka Island; bikini, bikini üstü, bikini altı, mayo ve seçili plaj giyim ürünlerinin satılacağı, görsel kalitesi yüksek, sade ve moda odaklı bir e-ticaret sitesi olacaktır.

Projenin amacı yalnızca güzel bir vitrin yapmak değildir. Sistem aynı zamanda:

- Siteyi devralan teknik bilgisi sınırlı bir kullanıcının günlük işleri panelden yapabilmesini,
- Ürün, fiyat, stok, renk, beden ve fotoğrafları yönetebilmesini,
- Ana sayfa kampanyalarını kod yazmadan değiştirebilmesini,
- Tasarımın yanlışlıkla bozulmamasını,
- iyzico ile ödeme alınmasını,
- Kargo, e-Fatura ve pazaryeri entegrasyonlarının daha sonra eklenebilmesini,
- Tema ve eklenti güncellemelerinin özel geliştirmeleri ezmemesini,
- Arama motorları ve yapay zekâ tabanlı alışveriş sistemleri tarafından doğru okunabilmesini

sağlamalıdır.

### 2.1 Projeyi tanımlayan kısıt

**Site devredildikten sonra sürekli bir teknik bakım anlaşması başlangıç kapsamına dahil değildir.**

Bu kısıt bakım ihtiyacını ortadan kaldırmaz; sorumluluğun müşteriye devredileceği anlamına gelir. Yönetilen hosting, düşük eklenti sayısı, staging, yedek, kontrollü güncelleme ve izleme bu riski azaltır. Otomatik güncelleme ve monitör tek başına bakımın yerine geçmez. Devirde müşteriye aylık/üç aylık kontrol listesi verilir; kritik güncellemeler staging üzerinde doğrulanmadan canlıya uygulanmaz.

---

## 3. Kesinleşmiş ana kararlar

### 3.1 Kullanılacak yaklaşım

- E-ticaret motoru sıfırdan yazılmayacaktır.
- Üretim mağazası WordPress ve WooCommerce üzerinde kurulacaktır.
- **Faz 1 tasarım prototipi bu depoda Next.js + Cloudflare Workers ile geliştirilecektir.** Prototip yalnızca müşteri onayı almak içindir; sepet, ödeme, sipariş ve panel içermez. Onaydan sonra tasarım Faz 3'te üretim temasına taşınır (§16.5).
- Tasarım sıfırdan hazırlanacaktır.
- İlk teknik deneme Blocksy Free ile yapılacaktır.
- Görsel katman özel bir **Blocksy child theme** içinde tutulacaktır.
- Siteye özgü veri alanları ve davranışlar ayrı bir **Kuka Island Core** proje eklentisinde tutulacaktır.
- Elementor, WPBakery ve benzeri ağır sayfa oluşturucular kullanılmayacaktır.
- Gutenberg blok editörü yalnızca kontrollü ve kilitli desenlerle kullanılacaktır.
- Blocksy Pro başlangıçta zorunlu kabul edilmeyecektir.
- Blocksy Pro, ancak sağladığı zaman tasarrufu satın alma kararını haklı çıkarırsa alınacaktır.
- iyzico ilk canlı sürümde bulunacaktır.
- e-Fatura, özel kargo API'leri ve pazaryeri entegrasyonları sonraki fazdadır.
- Site ve tüm veri müşterinin mülkiyetinde olacaktır.

### 3.2 Elenen alternatifler

Bu kararların neden verildiğini bilmek, ileride birinin aynı tartışmayı yeniden açmasını engeller.

| Alternatif | Eleme gerekçesi |
|---|---|
| Medusa v2, Saleor, Vendure (headless) | iyzico için hazır ödeme provider'ı yok; `AbstractPaymentProvider` extend edilerek yazılması ve kalıcı olarak bakılması gerekir. §2.1 kısıtıyla doğrudan çelişir |
| ikas, Ticimax (Türk SaaS) | Yüksek sabit abonelik gideri, mülkiyet ve kod erişimi yok, fiyat artışına karşı koz yok. Ücretsiz paketlerinde ise platformu cazip kılan POS ve pazaryeri avantajları devrede değil |
| Shopify | Türkiye'de Shopify Payments yok; iyzico üçüncü parti gateway olarak bağlanır ve ek işlem ücreti doğar. Mesafeli satış, ön bilgilendirme ve e-arşiv akışları çekirdekte değil |
| OpenCart, PrestaShop | iyzico modülü var ancak tema kalitesi ve ekosistem WooCommerce'ın gerisinde |
| Ajans üzerinden SaaS kurulumu | Hız ve sıfır teknik sorumluluk sunuyor; sahiplik ve düşük işletme gideri tercih edildiği için elendi. Bu tercih değişirse bu tablo yeniden okunmalıdır |

### 3.3 Tasarım yaklaşımı

- Jacquemus'un boşluk, tipografi, ürün odaklı grid, fotoğraf gezinme ve ürün detay yaklaşımı referans alınacaktır.
- Jacquemus'un kodu, özel fontu, logosu, görselleri, metinleri veya sayfa düzeni birebir kopyalanmayacaktır.
- Kuka Island'ın kendine ait marka kimliği oluşturulacaktır.
- Tasarım, içerik az olduğunda da çok olduğunda da bozulmayacak bir sistem halinde kurulacaktır.
- Masaüstü, tablet ve mobil eşit derecede önemlidir.
- Gösterişli animasyonlar yerine hızlı, kontrollü ve moda hissi veren mikro etkileşimler kullanılacaktır.

**Kabul edilen bedel:** Minimalizm affetmez. Kalabalık tasarım kötü fotoğrafı saklar; bol boşluk fotoğrafı çıplak bırakır. Ürün fotoğrafları profesyonel değilse sonuç "premium" değil "yarım kalmış" görünür. Bu, geliştiricinin kontrolünde olmayan tek değişkendir ve müşteriye yazılı olarak bildirilmiştir.

### 3.3.1 Prototip görsel ve metin kaynağı — zorunlu kural

Referans olarak incelenen sitelerin (Jacquemus, Aslora) ürün fotoğrafları, kampanya görselleri ve yasal metinleri **hiçbir ortamda kullanılmaz** — yerel geliştirme, şifre korumalı sunum ve canlı dahil.

| Alan | Kullanılacak kaynak |
|---|---|
| Prototip ürün ve editoryal görselleri | Ticari kullanıma açık lisanslı görseller (Unsplash, Pexels vb.) veya özgün üretim |
| Yasal metinler | Kuka Island bilgileriyle sıfırdan üretilir; şirket verisi gelene kadar `[ŞİRKET UNVANI]` biçiminde yer tutucu bırakılır |

Gerekçe: Rakip veya referans sitenin fotoğrafı telif ihlalidir. Yasal metinlerin kopyalanması ayrıca üç sorun doğurur — telif ihlali, metnin Kuka Island için hukuken geçersiz olması ve başka bir şirketin unvan/VKN/adres/ETBİS verisinin sitede yayınlanması. Bu iyzico üye işyeri incelemesinde de sorun oluşturur.

Yasal metinlerin nihai onayı müşteri veya hukuk danışmanındadır (§10.9, §20.3).

### 3.4 Panel yaklaşımı

- Günlük mağaza işlemleri WooCommerce panelinden yürütülecektir.
- Ana sayfa ve marka görünümü için sade bir "Site Görünümü" alanı hazırlanacaktır.
- Kullanıcı içerikleri değiştirebilecek ancak tipografi ölçeği, grid, boşluk ve animasyon sistemi gibi tasarım kurallarını bozamayacaktır.
- Panel hibrit olacaktır: sık kullanılan alanlarda basit formlar, editoryal alanlarda kilitli bloklar, ürünlerde WooCommerce editörü.

İlke: **marka görünümü kilitli, içerik özgür.** Bu müşteriye kısıtlama değil koruma olarak anlatılır.

### 3.5 Kapsam yaklaşımı

- İlk sürüm kapsamı §30 ve §31'de sayılıdır ve korunması esastır.
- e-Fatura, pazaryeri, özel kargo API'si, yüzlerce ürünün elle girilmesi ve sınırsız revizyon ilk sürüm kapsamı dışındadır. Türkçe/İngilizce arayüz Faz 5B kapsam eklemesiyle yapılmıştır; ihracat, çoklu para birimi ve İngilizce hukuk metinleri kapsam dışıdır.
- Yeni sayfa veya yeni özellik talebi revizyon değil kapsam değişikliğidir ve ayrıca değerlendirilir.

---

## 4. Teknoloji yığını

Kullanılacak teknolojiler ve her birinin projedeki rolü.

### 4.1 Çekirdek

| Teknoloji | Rol |
|---|---|
| **WordPress** (güncel sürüm) | İçerik, kullanıcı, medya, sayfa ve yetkilendirme altyapısı. Blocksy Customizer + Gutenberg içerik blokları; Full Site Editing block theme kullanılmaz |
| **WooCommerce** | Ürün, varyasyon, stok, sepet, sipariş, kupon ve checkout motoru. HPOS (High-Performance Order Storage) etkin |
| **PHP** (hosting'in desteklediği güncel sürüm) | Sunucu tarafı |
| **MySQL / MariaDB** | Veritabanı |

### 4.2 Tema katmanı

| Teknoloji | Rol |
|---|---|
| **Blocksy** (Free ile başlanır) | Parent tema. Header/footer builder, WooCommerce uyumluluğu, dahili schema, lazy loading, varyasyon galerisi |
| **Blocksy Companion** (ücretsiz) | Tema yardımcı eklentisi. Gutenberg tema blokları ve ücretsiz Cookies Consent gibi uzantılar. Custom Code Snippets Pro özelliğidir. Çerez kutusunun analitik scriptlerini izin öncesi gerçekten engelleyip engellemediği ayrıca test edilir |
| **Blocksy Pro** (koşullu) | Variation swatches, beden rehberi modülü, ürün filtreleri, mega menü, favoriler, quick view, gelişmiş galeri düzenleri. Karar kuralı §17.2 |
| **Kuka Island child theme** | Tasarım token'ları, global CSS, WooCommerce şablon override'ları, sunum JavaScript'i |

### 4.3 Özel geliştirme

| Bileşen | Rol |
|---|---|
| **Kuka Island Core** (proje eklentisi) | Site Görünümü paneli, özel veri alanları, üst/alt ilişki alanı, `big_image_size_threshold` filtresi, desteklenen WooCommerce API/hook'larıyla yasal gösterimler ve kurumsal fatura alanları, entegrasyon iş mantığı, gerekirse özel REST uçları. Kart tahsilatı veya ödeme imzası burada yeniden yazılmaz |
| **Vanilla JavaScript** | Galeri, lightbox, zoom, sepet çekmecesi etkileşimleri. Ek JS framework kullanılmaz |
| **CSS custom properties** | Tasarım token sistemi. Preprocessor kullanılmaz |

### 4.4 Ödeme ve ticaret

| Teknoloji | Rol |
|---|---|
| **iyzico WooCommerce eklentisi** | Ödeme geçidi. Resmî ve güncel sürüm doğrulanarak kurulur. Taksit gösterimi ayrı eklentiyle desteklenebilir |
| **WooCommerce kargo bölgeleri** | İlk sürümde sabit ücret + ücretsiz kargo eşiği. Kargo firması API'si Faz 8 |

### 4.5 Altyapı ve operasyon

| Teknoloji | Rol |
|---|---|
| **Yönetilen WordPress hosting** | Günlük yedek, WAF, malware taraması ve mümkünse staging/SSH. Güvenlik güncellemeleri otomatik olabilir; WooCommerce, Blocksy ve iyzico güncellemeleri önce staging üzerinde doğrulanır |
| **Let's Encrypt SSL** | Sertifika |
| **SMTP servisi** (Brevo, Mailgun veya benzeri) | İşlemsel e-posta teslimi. **Zorunlu** — WordPress varsayılan PHP mail gönderimi spam'e düşer |
| **Görsel CDN** (Cloudflare, Bunny veya benzeri) | Görsel dağıtımı ve WebP servisi. Karşılaştırma yapılarak seçilir |
| **S3 uyumlu obje depolama** (koşullu) | Depolama büyürse medya offload. Başlangıçta kurulmaz |
| **Git** | PLAN.md, child theme, Kuka Island Core, proje betikleri ve teknik dokümantasyon versiyonlanır; medya ve gizli anahtarlar eklenmez |
| **LocalWP veya benzeri** | Yerel geliştirme ortamı. Müşteri ön izlemesi için güvenilir staging/geçici yayın ortamı ayrıca kullanılır |
| **Uptime + checkout izleme** | Seçilen servisin gerçek checkout senaryosu ve alarm kanalı doğrulanır. Ücretsiz katmanın yeterli olduğu varsayılmaz |

### 4.6 SEO, analitik ve fontlar

| Teknoloji | Rol |
|---|---|
| **SEO eklentisi** (bir adet) | Yalnızca gerçekten gereken özellikler için. Blocksy'nin dahili schema'sıyla çakışmaması sağlanır |
| **GA4 veya seçilen analitik** | Çerez onayına bağlı |
| **Google Search Console** | İndeksleme takibi |
| **Self-hosted web fontları** | Instrument Sans, Inter, Archivo veya Geist adaylarından biri; veri satırları için bir mono aile. Google Fonts'a doğrudan bağlanılmaz (KVKK/GDPR) |

### 4.7 Kullanılmayacaklar

| Kullanılmayacak | Gerekçe |
|---|---|
| Elementor, WPBakery, Divi ve benzeri page builder'lar | İçeriği kendi shortcode yapısına gömer; güncelleme kırılganlığı ve performans sorunlarının ana kaynağı |
| ThemeForest mega temaları (Flatsome, Woodmart vb.) | Çok sayıda kullanılmayan özellik, geniş override yüzeyi ve proje için gereksiz karmaşıklık. Lisans bitince site kilitlenmez; ancak güncelleme/destek ve uzun vadeli uyumluluk riski doğar |
| Nulled / group-buy lisanslar | Malware vektörü. Ödeme alan ve kişisel veri tutan bir sitede kabul edilemez |
| Ağır çok amaçlı güvenlik paketleri | Hosting seviyesinde koruma + temel sertleştirme tercih edilir |
| Aynı işi yapan ikinci bir eklenti | §17.3 |
| Doğrudan Google Fonts bağlantısı | KVKK/GDPR riski |

### 4.8 Eklenti sayısı hedefi

Hedef aktif eklenti: **WooCommerce + Blocksy Companion + iyzico + Kuka Island Core + SMTP + bir SEO eklentisi.**

Bu sayıyı düşük tutmak, bu projenin en önemli tek mimari kararıdır. Her yeni eklenti önerisinde sırayla sorulur:

1. Bunu Kuka Island Core içinde makul bir kod miktarıyla çözebilir miyim?
2. Çözemiyorsam, bu eklenti aktif olarak bakılıyor ve kullanıcı sayısı yüksek mi?
3. Ödeme veya sipariş akışına dokunuyor mu? Dokunuyorsa **özel kod yerine bakımlı eklenti tercih edilir** — çünkü eklentiyi başkası günceller, bizim kodumuzu kimse güncellemez.

### 4.9 Faz 1 prototip yığını (yalnızca tasarım sunumu)

Yukarıdaki tablolar **üretim mağazasını** tanımlar. Faz 1 tasarım prototipi bu depoda ayrı bir yığınla çalışır:

| Teknoloji | Rol |
|---|---|
| **Next.js (App Router) + React** | Prototip sayfaları ve bileşenleri |
| **Cloudflare Workers / vinext** | Prototipin sunumu; şifre korumalı önizleme |
| **Tailwind CSS + CSS custom properties** | Tasarım token'ları token olarak tanımlanır; Tailwind yalnızca yerleşim aracıdır |
| **Vanilla JS / React state** | Galeri, lightbox, zoom, sepet çekmecesi etkileşimleri |
| **`data/` altında statik demo veri** | WooCommerce'e geçilmeden önce ürün/varyasyon modelinin taslağı |

**Prototipin kapsamı dışında:** gerçek sepet, ödeme, iyzico, sipariş, e-posta, yönetim paneli, veritabanı, kullanıcı hesabı. Sepet çekmecesi ve checkout ekranları **yalnızca görsel yön** olarak üretilir.

**Port maliyeti — bilinçli kabul edilen bedel:** Prototip React/Tailwind, üretim PHP/CSS olduğu için tasarım Faz 3'te bir kez yeniden yazılır. Karşılığında görsel onay hızlı ve ücretsiz ortamda alınır, onay öncesi hosting/lisans satın alınmaz (§27.3). Bu maliyeti düşük tutmak için §16.5 kuralları uygulanır.

## 5. Müşteri tarafından kesinleştirilecek bilgiler

Her soru, cevaplanmadan hangi fazın başlayamayacağıyla birlikte listelenmiştir. "Bloke" kolonu boş olan sorular tasarımın ilerlemesine engel değildir.

**Cevap durumu 3 Ağustos 2026 itibarıyladır.** "Teslim sonrası" işaretli maddeler kodda sabitlenmez; panelden yönetilecek biçimde kurulur.

| # | Soru | Bloke ettiği faz | Cevap |
|---|---|---|---|
| 1 | Bikini üstleri ve altları ayrı ayrı mı satılacak, takım olarak mı, yoksa iki yöntem de olacak mı? | Faz 2, Faz 3 | ✅ **İkisi de** — hem takım hem ayrı parça satılacak |
| 2 | Ayrı satılan üst ve alt parçalar farklı bedenlerde birleştirilebilecek mi? | Faz 3 (ürün sayfası şablonu) | ✅ **Evet**, karışık beden serbest |
| 3 | **Beden sistemi hangisi olacak: XS–XL mi, 34–42 mi?** | Faz 2, Faz 6 | ✅ **İkisi de olabilir; beden seti ürün bazlı panelden seçilir** (§13.4.1) |
| 4 | Kaç farklı **kesim** olacak? (10 altı / 10–30 / 30 üstü) | Faz 2, Faz 3 (menü derinliği ve filtre yapısı) | ⏳ Teslim sonrası belli olacak → kesim panelden yönetilen taksonomi (§8.1) |
| 5 | Kesim başına kaç renk bulunacak? | Faz 2, Faz 6 | ⏳ Teslim sonrası belli olacak |
| 6 | 30+ kesim açılış kataloğu mu, zamanla ulaşılacak hedef mi? | Faz 6 (emek tahmini) | ✅ **Açılışta ~150 parça** ilanda olacak |
| 7 | Her renk için ayrı ürün fotoğrafı seti var mı? | Faz 2 | ⏳ Teslim sonrası belli olacak → model her iki duruma hazır kurulur |
| 8 | Başlangıçta hangi kargo yöntemi kullanılacak? | Faz 5 | ❌ **Kargo anlaşması yok**, teslim sonrası |
| 9 | Ücretsiz kargo limiti olacak mı, kaç TL? | Faz 5 | ⏳ Teslim sonrası → panel ayarı |
| 10 | Yalnızca Türkçe ve TL mi kullanılacak? | Faz 3 | ✅ **Evet**, yalnızca Türkçe / TRY |
| 11 | Mevcut logo, marka renkleri ve font lisansı var mı? | — | ✅ **Logo yok, renk kısıtı yok.** Tipografik wordmark placeholder kullanılacak, zamanla doldurulacak |
| 12 | Profesyonel ürün fotoğrafları ne zaman teslim edilecek? | Faz 6 | ✅ **Müşteri sorumluluğunda.** Adlandırma kuralı (§14.2) çekim öncesi yazılı teslim edilecek |
| 13 | İade/değişim politikası ve yasal metinler hazır mı? | Faz 5, Faz 7 | ⚠️ Hazır değil. Referans siteden **kopyalanmayacak** (§3.3.1); Kuka Island bilgileriyle şablon üretilecek, müşteri/hukuk onaylayacak |
| 14 | Şirket türü nedir (şahıs / limited / anonim)? | Faz 5 (iyzico başvurusu) | ✅ **Şahıs şirketi** (kişi üstüne) |
| 15 | Vergi levhası ve şirket bilgileri temin edildi mi? | Faz 5 | ✅ **Vergi levhası alındı** |
| 16 | Hijyen bandı uygulaması olacak mı? | Faz 5 (iade politikası metni) | ❓ Cevap bekliyor — §20.2 gereği kritik |
| 17 | e-Fatura zorunluluk eşiğinin altında mıyız? (mali müşavire sorulacak) | Faz 8 | ❓ Cevap bekliyor |
| 18 | Marka adı ve yazımı kesin mi? ("Kuka Island") | Faz 1 (wordmark), Faz 6 (SKU formatı) | ✅ **Kuka Island** kesinleşti |
| 19 | Takım alana indirim uygulanacak mı? | Faz 2 (takım modeli seçimi) | ⏳ Şimdilik **indirim yok** varsayılıyor (§13.1.1) |
| 20 | Alan adı ve hosting alındı mı? | Faz 7 | ✅ **Alınmadı.** Tasarım beğenilirse **müşteri adına** alınacak (§17.4, §27.3) |
| 21 | Favoriler ve ürün yorumları ilk sürümde olacak mı? | Faz 3 | ✅ **Favoriler: evet.** Ürün yorumları: **ilk sürüm dışı** |
| 22 | Fiyat aralığı nedir? | Faz 5 (taksit gösterimi kararı) | ⏳ Teslim sonrası belli olacak |
| 23 | iyzico hesabı açıldı mı? | Faz 5 | 🔄 Paralel açılıyor |
| 24 | ETBİS kaydı yapıldı mı? | Faz 7 | ❓ Cevap bekliyor |

### 5.1 Hâlâ açık ve takvimi etkileyen maddeler

Cevap bekleyenler: **16** (hijyen bandı), **17** (e-Fatura), **24** (ETBİS). Üçü de Faz 5/7'yi etkiler, Faz 1'i bloke etmez.

Müşteri tarafında tek karar verici henüz belirlenmedi. Revizyon iki toplu turla sınırlı olduğu için (§27.2) bu kişinin netleşmesi gerekir.

### 5.2 Kalan varsayımlar

- Takım parçaları "Takımı tamamla" ilişkisiyle birbirine bağlanır; kullanıcı farklı üst ve alt beden seçebilir (§13.1.1).
- Kesim sayısı bilinmiyor. Veri modeli büyümeye uygun kurulur; menü, kategori ağacı ve ana sayfa kesim indeksi gerçek katalog görülmeden kilitlenmez.
- Her renk ayrı **varyasyondur** ve kendi fotoğraf galerisine sahip olabilir.
- Misafir alışverişi açıktır.
- Prototipte lisanslı/özgün demo içerikleri kullanılır (§3.3.1).
- Gerçek marka materyalleri geldiğinde panelden değiştirilir.
- Ana sayfa hero'su **yalnızca fotoğraf**; video ilk sürümde yok (§15.2).

**Kural:** Varsayımla ilerlenen her madde teklif metnine yazılır. Yazılmayan varsayım geliştiriciyi korumaz.

---

## 6. Başarı ölçütleri

- Site ilk bakışta hazır tema değil, özgün moda markası hissi verir.
- Kullanıcı ürünleri görsel olarak rahatça keşfedebilir.
- Ürün kartlarında fotoğraflar arasında gezinilebilir.
- Ürün detayında yüksek çözünürlüklü fotoğraflar hızlı açılır.
- Galeri klavye, fare, dokunma ve mobil pinch hareketleriyle kullanılabilir.
- Renk seçildiğinde ilgili renge ait görseller ve stok bilgisi gelir.
- Kullanıcı tükenmiş bedeni sepete eklemeye çalışmadan önce görür.
- Site sahibi ürün ve kampanya içeriklerini kod yazmadan değiştirebilir.
- Panelde yapılan normal içerik değişiklikleri tasarımı bozmaz.
- Mobil alışveriş akışı masaüstünden geri kalmaz.
- iyzico test ve canlı ödeme senaryoları çalışır.
- Sayfalar performans, SEO ve erişilebilirlik açısından temel kalite sınırlarını karşılar.
- Sistem daha sonra kargo, e-Fatura ve pazaryeri entegrasyonlarına açılabilir.
- Müşteri, geliştiriciye ihtiyaç duymadan yeni koleksiyon ekleyebilir.

---

## 7. Hedef kullanıcılar ve temel senaryolar

### 7.1 Ziyaretçi / müşteri

- Yeni koleksiyonu görmek ister.
- Bikini üstü, altı veya mayo kategorisine gider.
- Kesime göre daraltarak arar (üçgen, bralet, cheeky vb.).
- Ürünün farklı fotoğraflarını inceler.
- Fotoğrafı tam ekran açar ve yakınlaştırır.
- Renk ve beden seçer.
- Stok durumunu görür.
- Beden rehberini açar.
- Takımın eşleşen diğer parçasını bulur.
- Sepete ekler.
- Üye olmadan ödeme yapabilir.
- Sipariş onayı alır ve siparişini takip eder.

### 7.2 Mağaza yöneticisi

- Ürün ekler ve düzenler.
- Fiyat ve indirim belirler.
- Renk ve beden varyasyonları oluşturur.
- Varyasyon bazında stok yönetir.
- Her renge ait fotoğrafları yükler ve sıralar.
- Yeni koleksiyonu CSV şablonuyla toplu ekler.
- Ana sayfa kampanyasını değiştirir.
- Duyuru bandını günceller.
- Öne çıkan koleksiyonu seçer.
- Kupon oluşturur.
- Siparişleri ve ödeme durumlarını görür.
- Kargo takip numarasını girer.
- İade talebini işler.

### 7.3 Teknik yönetici / geliştirici

- Temayı ve proje eklentisini Git üzerinden sürdürür.
- Entegrasyonları tema koduna karıştırmadan ekler.
- Test ortamında güncelleme yapar.
- Yedek alır ve gerektiğinde geri döner.
- Log ve hata kayıtlarını kontrol eder.
- Tema veya WooCommerce güncellemesinden önce uyumluluk testi yapar.

---

## 8. Bilgi mimarisi ve site haritası

URL yapısı Türkçe, kısa ve kalıcı olmalıdır. Sonradan değiştirmek SEO kaybı ve yönlendirme işi demektir.

```text
/
/yeni-gelenler/
/bikini/
/bikini-ustleri/
/bikini-ustleri/{kesim-slug}/   (yalnızca SEO/değer taşıyan kesimler için koşullu landing page)
/bikini-altlari/
/bikini-altlari/{kesim-slug}/  (yalnızca SEO/değer taşıyan kesimler için koşullu landing page)
/mayolar/
/plaj-giyim/
/koleksiyon/{koleksiyon-slug}/
/urun/{urun-slug}/
/arama/
/favoriler/                    (kapsamda; Pro mu özel geliştirme mi kararı Faz 2'de)
/sepet/
/odeme/
/hesabim/
/hesabim/siparisler/
/siparis-takibi/
/beden-rehberi/
/hakkimizda/
/iletisim/
/sss/
/kargo-ve-teslimat/
/iade-degisim/
/gizlilik-politikasi/
/kvkk-aydinlatma-metni/
/cerez-politikasi/
/mesafeli-satis-sozlesmesi/
/on-bilgilendirme-formu/
```

### 8.1 Kategori ağacı

Kesim başlangıçta **global ürün niteliği veya özel ürün taksonomisi** olarak kurulur; filtrelerde kullanılabilir. Gerçek katalog ve arama değeri görüldükten sonra yalnızca önemli kesimler menü/SEO landing page katmanına çıkarılır. Her kesim için baştan kategori ve URL üretmek zorunlu değildir.

```text
Bikini
├── Bikini Üstleri
│   ├── Üçgen
│   ├── Bralet
│   ├── Straplez
│   ├── Halter
│   ├── Bandeau
│   └── Balconette
├── Bikini Altları
│   ├── Cheeky
│   ├── Brazilian
│   ├── Tam Kapama
│   ├── İpli / Yan Bağlamalı
│   ├── Yüksek Bel
│   └── Şortlu
└── Takımlar / Eşleşen Parçalar

Mayo
├── Tek Parça
└── Straplez Mayo

Plaj Giyim
├── Pareo
├── Etek
├── Elbise
└── Fular

Koleksiyonlar (sezonluk, panelden yönetilir)
```

Kesim listesi 4. sorunun cevabına göre kesinleşir. Liste uzarsa mega menü seçeneklerden biri olur (§9.3); zorunlu karar teknik ve görsel pilotta verilir.

### 8.2 Ana navigasyon

- Yeni Gelenler
- Bikini → Bikini Üstleri (kesimler) / Bikini Altları (kesimler) / Takımlar
- Mayo
- Plaj Giyim
- Koleksiyonlar
- Hikâyemiz

### 8.3 Yardım navigasyonu

- Beden Rehberi
- Kargo ve Teslimat
- İade
- Sık Sorulan Sorular
- İletişim
- Sipariş Takibi

---

## 9. Global arayüz bileşenleri

### 9.1 Duyuru bandı

- Tek veya en fazla üç duyuru destekler.
- Metin ve isteğe bağlı bağlantı panelden değişir.
- Otomatik geçiş varsayılan olarak kapalıdır; açılırsa hareket azaltma tercihini dikkate alır.

### 9.2 Header

- Masaüstünde sade, yatay ve düşük yükseklikte olur.
- Logo merkezde veya solda konumlanabilir; nihai yer tasarım onayında kilitlenir.
- Ana kategori bağlantıları görünürdür.
- Arama, hesap, favori ve sepet ikonları sağ alandadır.
- Sayfa başında şeffaf kullanılabilir; kaydırmada zemine geçer.
- Yapışkan header yalnızca kullanılabilirliği artıracak kadar devrede olur.

### 9.3 Mega menü

- Kesim sayısı 4. sorunun cevabıyla netleşir.
- Kategori/kesim listesi standart menüde okunamaz hale gelirse mega menü kullanılır.
- Menü görseli panelden yönetilebilir ancak zorunlu değildir.
- Mega menü Blocksy Pro özelliğidir; Free ile çalışılırsa çok kolonlu özel bir menü child theme'de yazılır.

### 9.4 Mobil menü

- Tam ekran veya ekranın çoğunu kaplayan panel şeklinde açılır.
- Kategoriler, yardım bağlantıları ve sosyal medya düzenli ayrılır.
- Menü açıldığında arka plan kaydırması durur.
- Klavye odağı menü içinde tutulur; Escape ile kapanır.

### 9.5 Arama

- Ürün adı, kategori, SKU, kesim ve koleksiyon içinde arama yapar.
- Masaüstünde overlay, mobilde tam ekran çalışır.
- Sonuç kartları fotoğraf, ad, renk bilgisi ve fiyat gösterir.
- Sonuç yok durumu yönlendirici kategori bağlantıları içerir.

### 9.6 Sepet çekmecesi

- Ürün sepete eklenince sayfadan koparmadan sağdan açılır.
- Ürün fotoğrafı, seçilen renk, beden, adet ve fiyat görünür.
- Adet değiştirilebilir ve ürün kaldırılabilir.
- Ücretsiz kargo hedefine kalan tutar gösterilebilir.
- "Sepete git" ve "Ödemeye geç" eylemleri açıkça ayrılır.
- Ön Bilgilendirme Formu ve Mesafeli Satış Sözleşmesi bağlantıları gösterilebilir; zorunlu onay checkout aşamasındadır (§20.3).
- Çekmece Blocksy Pro özelliğidir; Free ile çalışılırsa ayrı sepet sayfası veya child theme'de özel çekmece kullanılır.

### 9.7 Footer

- Marka açıklaması
- Newsletter (ticari elektronik ileti onayı ayrı kutu, ön işaretli değil)
- Kategoriler
- Yardım bağlantıları
- Yasal bağlantılar
- Sosyal medya
- İletişim bilgileri
- Ödeme ikonları gerekiyorsa sade biçimde
- Telif ve şirket bilgileri

---

## 10. Sayfa bazında tasarım ve işlev gereksinimleri

### 10.1 Ana sayfa

Amaç: Markanın moda dilini ilk ekranda kurmak, ziyaretçiyi hızlıca koleksiyonlara ve ürünlere taşımak.

Bölüm sırası:

1. Duyuru bandı
2. Header
3. Ana kampanya hero alanı
4. **Kesim indeksi tasarım hipotezi** — katalog yeterince genişse iki kolonlu tipografik liste; Faz 1'de standart kategori vitriniyle karşılaştırılarak onaylanır (§11.6)
5. Bikini / Mayo iki yönlü kategori vitrini
6. Yeni gelen ürünler
7. Tam genişlik editoryal fotoğraf veya video
8. Öne çıkan koleksiyon
9. Takımı tamamla / eşleşen parçalar
10. Marka hikâyesi kısa bölümü
11. Sosyal içerik / Instagram seçkisi
12. Güven unsurları
13. Newsletter
14. Footer

**Hero alanı:**

- Masaüstü ve mobil için ayrı görsel/video alanı bulunur.
- Başlık, kısa metin, buton ve bağlantı panelden değişir.
- Başlık fotoğrafı kapatmayacak şekilde kontrollü yerleşir.
- Video sessiz, döngülü ve `playsinline` olabilir; hareket azaltma tercihinde statik poster gösterilir. **İlk sürümde video yok** (§5.2) — bu madde ileride video eklenirse geçerli olacak kural olarak korunur.
- Ağır video mobilde zorunlu tutulmaz.
- Hero görseli lazy-load edilmez; `fetchpriority="high"` verilir.

**Ürün şeridi:**

- Geniş masaüstünde 4, masaüstünde 3, tablet ve mobilde 2 kart gösterir.
- Yatay kaydırma kullanılacaksa kaydırma göstergesi görünür olur.
- Kart bilgisi sınırlıdır (§10.2 Ürün kartı).

**Panel kontrolü:** bölüm aç/kapat, hero medya ve metin, kategori kutuları, öne çıkan seçim, editoryal medya ve metin, bölüm başlıkları, önceden tanımlı sınırlar içinde sıralama.

### 10.2 Kategori / koleksiyon sayfası

- Kategori adı ve isteğe bağlı kısa açıklama
- Sonuç sayısı
- Grid yoğunluğu seçimi: geniş / normal / kompakt
- Varsayılan geniş masaüstü grid: 4 sütun
- Sıralama: önerilen, en yeni, fiyat artan, fiyat azalan
- Filtreler: kesim, beden, renk, fiyat, stok ve ürün özelliği
- Mobilde filtreler alt veya yan panel olarak açılır
- Aktif filtreler görünür etiketlerle gösterilir
- Tüm filtreleri temizle seçeneği bulunur
- Filtre sonucu boşsa uygun açıklama ve sıfırlama düğmesi gösterilir
- Sayfalama klasik veya "daha fazla yükle" olabilir; SEO nedeniyle sonsuz kaydırma tek başına kullanılmaz

**Not:** Gelişmiş filtreler Blocksy Pro özelliğidir. Free ile çalışılırsa WooCommerce'ın kendi filtre blokları kullanılır — işlevsel ancak estetik olarak daha zayıf.

#### Ürün kartı

- Ürün fotoğraf oranı `4:5` olur ve **tüm kartlarda tek ve değiştirilemezdir**.
- Açık gri / kırık beyaz fon kullanılır.
- Fareyle üzerine gelince ikinci görsel gösterilir.
- Önceki/sonraki görsel düğmeleri klavye ve dokunmayla çalışır.
- Kartın tamamı ürün detayına gider; gezinme düğmeleri yanlışlıkla sayfayı açmaz.
- "Yeni", "Sınırlı", "Tükendi" gibi rozetler sade biçimde gösterilir.
- Favori düğmesi yalnızca özellik kapsamdaysa görünür.
- Ürün adı, kesim adı, seçili renk, renk sayısı ve fiyat görünür.
- **Beden/stok satırı tasarım hipotezidir.** Faz 1'de özellikle mobil kart yoğunluğu test edilir; onaylanırsa tükenen bedenler erişilebilir biçimde işaretlenir (§11.6).
- İndirimli fiyatta eski ve yeni fiyat erişilebilir biçimde ayrılır.
- Renk seçimi kart görselini değiştirebilir.
- Hızlı sepete ekleme beden seçimi gerektiği için varsayılan olarak kullanılmaz.

### 10.3 Ürün detay sayfası

Amaç: ürün fotoğrafını merkeze almak, beden/renk seçimini hatasız yaptırmak ve satın alma kararına gereken bilgiyi yakın tutmak.

**Masaüstü düzeni**

- Galeri yaklaşık %60–70 genişlik kaplar.
- Ürün bilgi paneli yaklaşık %30–40 genişlikte ve kaydırma sırasında kontrollü biçimde sabit kalır.
- Galeri tek büyük sütun veya iki kolonlu editoryal grid olabilir; nihai karar tasarım prototipinde verilir.
- Fotoğraf sayısı azsa gereksiz boş grid oluşturulmaz.

**Mobil düzen**

- Fotoğraflar yatay kaydırılabilir.
- Görsel sayacı veya noktalar bulunur.
- Ürün adı, fiyat, renk ve beden kontrolü galeri altında gelir.
- Sepete ekle düğmesi uygun görülürse ekran altında sabitlenebilir.

**Ürün bilgi paneli**

- Kesim adı (küçük, büyük harf, üst etiket)
- Ürün adı
- Kısa ürün tanımı
- Fiyat / indirimli fiyat
- Taksit veya ödeme açıklaması gerekiyorsa sade bilgi
- Seçili renk adı ve ürün kodu / SKU
- Renk swatch'ları
- Beden seçenekleri; stok dışı bedenler işaretli ve pasif
- Beden rehberi bağlantısı
- Düşük stok mesajı, yalnızca gerçek stok verisiyle
- Sepete ekle
- Favoriye ekle, kapsam dahilindeyse
- Akordiyon: Ürün detayları / Kumaş ve bakım / Kalıp ve beden önerisi / Model ölçüleri / Kargo, teslimat ve iade
- **İade akordiyonunda hijyen istisnası açıkça yazılır** (§20.2)

**İlişkili içerikler:** Takımı tamamla, benzer ürünler, son görüntülenenler (gerekirse sonraki faz).

### 10.4 Beden rehberi

- Genel bir sayfa ve ürün sayfasında modal/panel biçimi bulunur.
- Bikini üstü, bikini altı ve mayo tabloları ayrılır.
- Üst için göğüs/kupa, alt için bel/kalça ölçüleri yer alır.
- Santimetre değerleri açıkça belirtilir.
- Ölçümün nasıl yapılacağını gösteren metin ve ileride özgün görsel desteklenir.
- Panelden tablo düzenlenebilir, tasarım yapısı kilitlidir.
- Beden sistemi 3. sorunun cevabıyla belirlenir.

### 10.5 Sepet sayfası

Ürün fotoğrafı, ad, renk, beden, adet, fiyat · adet güncelleme · ürün kaldırma · kupon alanı · kargo hedefi · ara toplam · tahmini kargo bilgisi · ödemeye geç · güvenli ödeme ve iade kısa bilgileri · stok değiştiyse açık uyarı.

### 10.6 Ödeme sayfası

- Gereksiz header/footer kalabalığı azaltılır.
- Misafir alışverişi varsayılan olarak açıktır.
- İletişim, teslimat adresi, fatura bilgisi, kargo ve ödeme mantıklı sıralanır.
- **Bireysel / Kurumsal fatura seçimi;** kurumsal seçilirse VKN ve vergi dairesi alanları açılır. Bu, ileride e-Fatura fazı için zemin hazırlar.
- Sipariş özeti masaüstünde görünür, mobilde açılır panel olur.
- Ön bilgilendirme ve mesafeli satış onayları doğru yerde gösterilir ve **işaretlenmeden ödemeye geçilemez**.
- iyzico alanı güven veren fakat marka tasarımına uyumlu görünür.
- Hatalar alanın yakınında ve anlaşılır Türkçe gösterilir.
- Ödeme düğmesine art arda basma engellenir.

### 10.7 Hesabım

Giriş / kayıt · siparişler · sipariş detayı · adresler · hesap bilgileri · çıkış · iade veya kargo bağlantıları (entegrasyon kapsamına göre).

### 10.8 Sipariş takibi

- Sipariş numarası ve e-posta ile sorgulama
- Sipariş durumu
- Kargo takip numarası varsa bağlantı
- Kargo API'si yoksa yönetici tarafından girilen takip numarası kullanılır

### 10.9 İçerik ve yasal sayfalar

Hakkımızda · İletişim · SSS · Kargo ve teslimat · Cayma Hakkı ve İade · Gizlilik · KVKK · Çerez politikası · Mesafeli satış sözleşmesi · Ön bilgilendirme formu.

Yasal metinler geliştirici tarafından hukuki danışmanlık verilerek oluşturulmuş sayılmaz. Müşteri veya hukuk danışmanı nihai metinleri onaylamalıdır.

---

## 11. Görsel tasarım sistemi

### 11.1 Marka karakteri

**Hedef sıfatlar:** Modern · Özgüvenli · Sade · Editoryal · Akdenizli · Sıcak fakat gösterişsiz · Premium fakat erişilemez görünmeyen

**Kaçınılacak görünüm:** Hazır pazaryeri şablonu hissi · Aşırı yuvarlatılmış SaaS arayüzü · Çok fazla renk ve rozet · Büyük gölgeler · Sürekli hareket eden alanlar · Serif fontla klişe "lüks" görünümü · Sıcak krem zemin + serif + terracotta aksan üçlüsü (yaygın bir varsayılan hâline geldi) · İnce ve okunamayacak metinler

### 11.2 Renk sistemi

Palet tasarım aşamasında test edilecek; yapı şu kadar kontrollü tutulacaktır:

| Token | Rol | Başlangıç önerisi |
|---|---|---|
| `ink` | Ana metin, neredeyse siyah, soğuk tonda | `#16181A` |
| `paper` | Ana zemin, hafif soğuk kırık beyaz | `#EEEFEC` |
| `mist` | Ürün kartı ve blok zemini | `#E4E6E2` |
| `muted` | İkincil metin, meta bilgi | `#767A74` |
| `line` | İnce ayırıcı | `#D6D9D3` |
| `error` | Hata | Tasarım aşamasında |
| `success` | Başarı | Tasarım aşamasında |

Ana arayüzde **aksan rengi kullanılmaz.** Renk zenginliğini ürün fotoğrafları sağlar. Bu bilinçli bir kısıtlamadır ve imza öğesine (§11.6) yer açar.

Panele yalnızca önceden onaylanmış palet preset'leri açılır. Serbest renk seçici verilmez; kontrastı bozabilecek `ink`, `paper` ve `line` kombinasyonları kullanıcıya bırakılmaz.

### 11.3 Tipografi

- Tek ana sans-serif aile kullanılır; veri satırları için bir mono aile eklenir.
- Açık lisanslı ve **kendi sunucumuzda barındırılabilen** font seçilir.
- Ana aile adayları: Instrument Sans, Inter, Archivo, Geist.
- Mono aile adayları: IBM Plex Mono, Space Mono.
- Marka logosu sonradan özel bir wordmark olarak değiştirilebilir.
- En fazla üç font ağırlığı kullanılır.
- Başlıklar büyük olabilir ancak mobilde ekranı anlamsız biçimde kaplamaz.
- Gövde yazısı minimum okunabilirlik sınırının altına düşmez.
- Büyük harf ve geniş harf aralığı yalnızca kısa etiketlerde kullanılır.
- Türkçe karakter desteği zorunlu kriterdir.

### 11.4 Boşluk ve ölçü sistemi

- 8 piksel tabanlı ölçek kullanılır.
- Token'lar: 4, 8, 12, 16, 24, 32, 48, 64, 96, 128.
- **Geliştirici rastgele aralık değeri eklemez.**
- Mobil kenar boşluğu ~16–20 px, masaüstü ~24–40 px aralığında token'la belirlenir.
- Maksimum metin satırı uzunluğu kontrol edilir.

### 11.5 Görsel oranları

Tek oran site geneline uygulanmaz. Yalnızca ürün kartlarında tek oran zorunludur.

| Alan | Oran |
|---|---|
| Ürün kartı (katalog) | `4:5` — tüm kartlarda tek ve değiştirilemez |
| Ürün detay galerisi | `3:4` veya orijinal oran |
| Ana sayfa hero, masaüstü | `16:9` veya daha geniş |
| Ana sayfa hero, mobil | `4:5` |
| Kategori kutucuğu | `3:4` |
| Editoryal blok | Tasarıma göre serbest |
| Video alanı | Masaüstü ve mobil için ayrı kırpma |

**Uyarı:** Ürün kartı oranı WooCommerce görsel kırpma ayarında tanımlanır. Sonradan değiştirmek tüm görsellerin yeniden üretilmesi demektir. Faz 1'de kesinleştirilir.

### 11.6 Onaylanan imza öğeleri

- **Ürün kartı envanter satırı kalıcı gereksinimdir:** SKU solda; beden dizisi sağda; tükenen bedenler üstü çizili. Renk swatch'ları görselin sağ üstündedir ve seçili renk işaretlenir. Swatch ile beden/stok katmanı müşteri tercihi için ayrı görünürlük anahtarına sahiptir.
- **Ana sayfa kesim indeksi kalıcı gereksinimdir:** `FORMUNU BUL` etiketi, dört kategori satırı, numara/kategori/kesim/ok anatomisi ve hover zemini korunur. Kesimler koddan değil `pa_kesim` verisinden türetilir.

Bu maddeler 4 Ağustos 2026 müşteri onayıyla hipotez statüsünden çıkarılmıştır.

### 11.7 Animasyon

- Mikro geçişler ~160–300 ms.
- Büyük panel geçişleri ~280–450 ms.
- Easing değerleri ortak token olarak tanımlanır.
- Scroll-jacking yapılmaz.
- İçerik okunmadan kaybolan animasyon kullanılmaz.
- `prefers-reduced-motion` desteklenir.

---

## 12. Responsive kurallar

Kesin breakpoint değerleri tema uygulamasında test edilerek sabitlenecek; hedef davranış:

| Görünüm | Ürün grid'i | Navigasyon | Ürün detayı |
|---|---:|---|---|
| Geniş masaüstü | 4 (kullanıcı seçimine göre 3/6) | Tam menü | Galeri + sabit bilgi paneli |
| Masaüstü | 3–4 | Tam menü | Galeri + bilgi paneli |
| Tablet | 2 | Daraltılmış menü | İki bölüm veya tek kolon |
| Mobil | 2 | Tam ekran mobil menü | Slider + ürün bilgisi |

Kurallar:

- Hover gerektiren hiçbir temel işlev mobilde kaybolmaz.
- Tıklanabilir alanlar dokunmaya uygun büyüklükte olur.
- Mobilde yatay taşma olmaz.
- Filtreler mobilde ayrı paneldir.
- Uzun başlık ve fiyatlar grid'i bozmaz.
- Türkçe karakter ve uzun kategori adları test edilir.
- Trafiğin ağırlıklı olarak mobil beklendiği için tüm testler önce mobilde yapılır.

---

## 13. Ürün ve varyasyon veri modeli

### 13.1 Ürün türleri

| Tür | Kesim ayrımı |
|---|---|
| Bikini üstü | Üçgen, Bralet, Straplez, Halter, Bandeau, Balconette |
| Bikini altı | Cheeky, Brazilian, Tam kapama, İpli, Yüksek bel, Şortlu |
| Mayo | Tek parça, Straplez |
| Plaj giyim | Pareo, Etek, Elbise, Fular |
| Aksesuar | Sonraki ihtiyaç halinde |
| Takım | **Kapsamda** — müşteri hem takım hem ayrı parça satacak (§5 soru 1) |

Kesim global nitelik/özel taksonomi ve filtre katmanıdır. SEO değeri taşıyan kesimler ayrıca landing page olabilir. "Bikini üstü" ve "Bikini altı" ana ürün kategorileri olarak korunur.

#### 13.1.1 Takım satış modeli

Müşteri hem takım hem ayrı parça satacağını ve karışık beden seçimine izin vereceğini bildirdi. Bu, planın önceki sürümünde şarta bağlı olan takım modelini kapsama sokar. Üç seçenek var; **karar Faz 2 teknik pilotunda verilir.**

| Seçenek | Nasıl çalışır | Bedel |
|---|---|---|
| **(a) "Takımı tamamla" özel UI — seçilen varsayılan** | Üst ürün sayfasında eşleşen alt ürün gösterilir; kullanıcı her ikisinin bedenini ayrı seçer, iki ayrı sepet satırı oluşur | Ücretsiz. Child theme + Core ile çözülür. Karışık beden doğal çalışır. **Set indirimi veremez** |
| (b) WooCommerce Grouped Product | Çekirdek ürün tipi | Ücretsiz, ancak varyasyonlu alt ürünlerde sayfa içi beden seçimi sınırlıdır; Faz 2'de doğrulanmadan seçilmez |
| (c) Product Bundles / Composite eklentisi | Tek sepet satırı, set fiyatı, karışık beden | Ücretli lisans, eklenti sayısını artırır ve **sepet/checkout akışına dokunur** |

**Seçim kuralı:** Takım fiyatının parçaların toplamından **düşük** olması ticari zorunluluk değilse (a) uygulanır. §5 soru 19 cevaplanana kadar varsayım (a)'dır.

Set indirimi zorunlu hâle gelirse §17.3 gereği bu iş özel kodla değil bakımlı eklentiyle yapılır — çünkü sepet fiyat kuralı, iade ve kısmi iptal davranışı ödeme akışına yakındır. Bu durumda ek lisans ve ek eklenti kalemi doğar.

### 13.2 Ürün alanları

Ürün adı · slug · ürün kodu/SKU · GTIN/EAN (varsa) · kısa açıklama · uzun açıklama · normal fiyat · indirimli fiyat · indirim başlangıç/bitiş · vergi sınıfı · kategori · **kesim** · koleksiyon · etiketler · ürün özellikleri · kumaş içeriği · bakım talimatı · kalıp bilgisi · **modelin boyu ve üzerindeki beden** · beden rehberi ilişkisi · ana ürün fotoğrafı · genel galeri · video (varsa) · SEO başlığı · meta açıklama · sosyal paylaşım görseli · **ilişkili üst/alt parça** · benzer ürünler · yayın durumu.

"Modelin boyu ve üzerindeki beden" alanı mayoda iade oranını doğrudan düşürür; opsiyonel değil, standart alan olarak kurulur.

### 13.3 Varyasyon alanları

Renk · beden · varyasyon SKU'su · stok miktarı · stok durumu · düşük stok eşiği · geri sipariş izni · varyasyon fiyatı (farklıysa) · ana varyasyon görseli · renge özel galeri · ağırlık ve ölçüler (kargo için gerekirse).

### 13.4 Varyasyon kuralları

**Global nitelik kuralı — kritik.** Renk ve Beden **global nitelik** olarak tanımlanır, ürün bazlı nitelik olarak değil. Swatch'lar ve filtreler yalnızca global niteliklerle çalışır. Ürün bazlı nitelik girilen bir ürün filtrede görünmez ve swatch'ı çıkmaz. Bu kural, müşterinin panelden yanlış ürün girmesini imkânsız hale getirir — hazır listeden seçer.

**Renk varyasyondur, ayrı ürün değildir.** Renk ve beden kombinasyonları tek ürün altında varyasyon olarak yönetilir. Böylece ürün sayfası sayısı düşer, veri girişi emeği azalır ve tek sayfada renk gezinme deneyimi mümkün olur.

**Farklı kesim ayrı üründür.** Tamamen farklı kalıp veya ürün tipi, yalnızca renk farkı gibi gösterilmez.

**Üst ve alt ayrı üründür.** Bikini üstü ve altı ayrı satılıyorsa ayrı WooCommerce ürünleridir. Birbirlerine "Takımı tamamla" ilişkisiyle bağlanırlar; böylece kullanıcı farklı üst ve alt beden seçebilir.

**Varyasyon pilotu.** WooCommerce'te çok sayıda varyasyon ön yüz dinamik seçim ve yönetim davranışını etkileyebilir. 30 ve üzeri kombinasyonlar pilot üründe özellikle test edilir; keyfi bir “50 tavanı” varsayılmaz.

#### 13.4.1 Beden sistemi — ürün bazlı seçim, global nitelik korunur

Müşteri hem `XS–XL` hem `34–42` kullanmak istiyor ve bunun **ürün bazında panelden ayarlanabilmesini** talep ediyor (§5 soru 3). Bu talep, yukarıdaki global nitelik kuralını bozmadan karşılanır:

- Tek **global** nitelik `Beden` tanımlanır.
- Terim listesi her iki sistemi içerir: `34, 36, 38, 40, 42, XS, S, M, L, XL`.
- Her ürün, panelden bu global listeden **hangi terimleri kullanacağını seçer.** Bir ürün `36–42`, diğeri `S–L` olabilir.
- Filtre ve swatch çalışmaya devam eder, çünkü nitelik globaldir.

**Yapılmayacak:** ürün bazlı (custom) nitelik girmek. O ürün filtrede görünmez, swatch'ı çıkmaz ve §13.4 kuralı ihlal edilir.

**Kabul edilen bedel:** Filtre panelinde harf ve numara bedenleri birlikte listelenir. Kesim ve renk kararları geldikten sonra katalog tek sisteme indirilebilir; terim silmek yerine kullanılmayan terimler filtreden gizlenir.

---

## 14. Medya ve yüksek çözünürlüklü görsel sistemi

### 14.1 Kaynak dosya standardı

- Orijinal fotoğraflar uzun kenarda 3000–4000 px aralığında teslim edilir.
- sRGB renk profili kullanılır.
- Aşırı sıkıştırılmış mesajlaşma uygulaması görselleri kaynak olarak kabul edilmez.
- Ürün kartları için kadraj güvenli alanı düşünülür.
- **Her renk için aynı çekim sırası korunur.**

Çekim sırası:

1. Ön görünüm
2. Arka görünüm
3. Yan / üç çeyrek görünüm
4. Yakın detay
5. Kumaş / bağ / aksesuar detayı
6. Editoryal kullanım (varsa)

### 14.2 Dosya adlandırma — zorunlu

CSV içe aktarma görselleri **dosya adına göre** eşleştirir. Kural belirlenmeden fotoğraf istenirse yüzlerce dosya elle eşleştirilir.

```text
{urun-slug}-{renk-slug}-{sira}.jpg

bralet-meltem-tuz-01.jpg
bralet-meltem-tuz-02.jpg
bralet-meltem-gece-01.jpg
```

Türkçe karakter, boşluk ve anlamsız kamera dosya adı kullanılmaz. Bu kural müşteriye **fotoğraf çekimi başlamadan önce** yazılı olarak bildirilir.

**Açılış kataloğu ~150 parça olduğu için bu madde kapsam açısından kritiktir.** Fotoğraf çekimi müşteri sorumluluğundadır (§5 soru 12). Adlandırma kuralına uyulmazsa yüzlerce dosyanın ürün ve varyasyonlarla elle eşleştirilmesi gerekir; bu iş ilk sürüm kapsamında **değildir** (§31) ve ayrıca değerlendirilir.

Talimat ayrı bir tek sayfalık belge olarak teslim edilir ve teslim tarihi kayda geçirilir.

### 14.3 WordPress görsel işlemi

1. Orijinal dosya korunur ve **site dışında ayrıca yedeklenir.**
2. Katalog, ürün detayı, thumbnail ve zoom için farklı boyutlar oluşturulur.
3. WordPress'in büyük görsel eşiği (`big_image_size_threshold`) **kör biçimde kapatılmaz; gerçek dosyalarla ölçülerek ayarlanır.** Varsayılan davranışta 2560 px üstü yüklemeler küçültülür ve `-scaled` sürüm kullanılır; zoom için yüksek çözünürlük gerekiyorsa eşik ölçülerek yükseltilir. Filtre `Kuka Island Core` içinde tanımlanır.
4. Kullanıcıya 4000 px kaynak her sayfa yüklemesinde gönderilmez.
5. `srcset` ve `sizes` doğru kullanılır.
6. WebP/AVIF üretimi hosting yeteneklerine göre etkinleştirilir.
7. Ekran dışında kalan görseller lazy-load edilir.
8. İlk ekran ana görseli lazy-load edilmez.
9. Zoom görseli kullanıcı etkileşimiyle yüklenir.

### 14.4 CDN ve depolama

- **CDN dağıtım hızını artırır; depolama çözümüyle karıştırılmaz.** Orijinal dosyalar yine kendi sunucumuzda durur.
- İlk 20–30 ürünün gerçek dosya boyutları ölçülür ve ölçeklenir. Depolama ihtiyacı varsayımla sabitlenmez.
- Hosting depolaması yeterliyse başlangıçta ek servis alınmaz.
- Depolama büyürse S3 uyumlu obje depolama / offload değerlendirilir.
- CDN olarak Cloudflare, Bunny veya uygun servis teknik açıdan karşılaştırılır.

---

## 15. Yönetim paneli bilgi mimarisi

### 15.1 WooCommerce'in yöneteceği alanlar

Ürünler · varyasyonlar · fiyatlar · stok · kuponlar · siparişler · müşteriler · vergi · temel kargo bölgeleri ve yöntemleri · ödeme durumları · raporlar.

### 15.2 "Kuka Island / Site Görünümü" alanı

**Marka:** logo · mobil logo (gerekirse) · favicon · sosyal paylaşım varsayılan görseli · iletişim e-postası · telefon/WhatsApp · sosyal medya bağlantıları.

**Duyuru bandı:** açık/kapalı · duyuru metni · bağlantı metni · bağlantı adresi · birden fazla duyuru varsa sıralama.

**Ana hero:** açık/kapalı · masaüstü görseli · mobil görseli · başlık · açıklama · buton metni · buton bağlantısı · metin hizası (önceden tanımlı seçenekler) · metin rengi (yalnızca açık/koyu).

> **Video ilk sürümde yok.** Müşteri ana sayfada yalnızca fotoğraf kullanılacağını bildirdi (§5.2). §10.1'deki video davranış kuralları (sessiz/döngülü/`playsinline`, reduced-motion poster) ileride video eklenirse geçerli olacak referans olarak korunur; Faz 1 ve ilk sürüm kapsamında video alanı üretilmez.

**Ana sayfa bölümleri:** bölüm açık/kapalı · başlık ve kısa açıklama · kaynak türü (kategori / koleksiyon / manuel ürün seçimi) · seçili kategori/koleksiyon/ürünler · büyük kart veya normal grid gibi sınırlı sunum seçimi · editoryal görsel/video · bağlantı. Hero ve temel iskelet sabittir; orta içerik bölümleri yalnızca izin verilen sınırlar içinde sıralanabilir.

**Footer:** kısa marka metni · newsletter metni · yardım bağlantıları · şirket bilgileri · sosyal medya.

**Global ticari mesajlar:** ücretsiz kargo limiti metni · 14 günlük cayma hakkı metni · destek çalışma saatleri · güvenli ödeme kısa metni.

### 15.3 Panelden açılmayacak alanlar

Font ölçüleri · font ailesi (günlük kullanıcı için) · rastgele renk ekleme · grid CSS değerleri · mobil breakpoint'ler · animasyon süreleri · galeri JavaScript davranışı · rastgele HTML/JavaScript · tema dosyaları · görsel dönüştürme boyutları · ürün kartı görsel oranı · hero/temel sayfa iskeletinin sırası · izin verilmeyen blokların taşınması ve silinmesi.

### 15.4 Hibrit yaklaşımın bedeli

Basit form alanları ACF ile veya WordPress Settings/Meta API kullanılarak Kuka Island Core içinde geliştirilebilir. ACF zorunlu değildir; her iki yöntem de test ve bakım gerektirir.

Bu yüzden özel form alanları yalnızca müşterinin gerçekten sık dokunacağı 3–4 alanda kullanılır (hero, kampanya blokları, kategori kutucukları). Geri kalan yerde kilitli blok desenleri tercih edilir; bu yaklaşım üçüncü taraf bağımlılığını azaltır fakat bakım ihtiyacını sıfırlamaz.

### 15.5 Faz 7 operatör sözleşmesi

Site Görünümü ölçümü **13 sekme, 113 görünen satır ve 154 saklanan kontrol**dür; 41 kontrol aynı satırdaki `(EN)` karşılığıdır. Sekmeler WordPress `nav-tab-wrapper` desenini kullanır, aktif sekme URL'de kalır, alan araması bütün sekmeleri filtreler ve yapışkan kaydet çubuğu kayıttan sonra aynı sekmeye döner.

Ürün, sayfa ve kategori/nitelik terimlerinde Türkçe kaynak ile `(EN)` karşılığı aynı ekrandadır. Eski ayrı “English product/page content” kutuları yoktur. Ürün yayın kontrol listesi bilgilendiricidir ve yayınlamayı engellemez.

Palmiye ikonlu `Kuka Island` menüsünde Başlangıç, Site Görünümü, Yönetim Haritası ve Bülten Kayıtları bulunur. Başlangıç mağaza/noindex durumunu ve eyleme bağlı tutarlılık uyarılarını gösterir; Yönetim Haritası görevden doğrudan doğru WordPress/WooCommerce ekranına götürür.

Gerçek lansman onayına kadar `woocommerce_coming_soon=yes` ve `blog_public=0` birlikte korunur. Otomatik storefront testi WooCommerce'in gizli özel önizleme anahtarını yerel olarak okur; anahtar yazdırılmaz veya depoya girmez.

---

## 16. Teknik mimari

### 16.1 Katmanlar

**WordPress çekirdeği** — içerik, kullanıcı, medya, sayfa ve yetkilendirme altyapısı.

**WooCommerce** — ürün, varyasyon, stok, sepet, sipariş, kupon ve checkout motoru.

**Blocksy parent theme** — temel tema altyapısı ve WooCommerce uyumluluğu. Parent theme dosyaları doğrudan değiştirilmez.

**Kuka Island child theme** — tasarım token'ları · global CSS · header/footer görünümü · WooCommerce şablon uyarlamaları · ürün kartı görünümü · ürün detay yerleşimi · responsive kurallar · sunum amaçlı JavaScript.

**Kuka Island Core eklentisi** — tema bağımsız özel alanlar · Site Görünümü paneli · ilişkili bikini üst/alt alanları · özel galeri verisi (gerekiyorsa) · görsel eşik filtresi · yasal onay kutusu · kurumsal fatura alanları · entegrasyon iş mantığı · özel REST uçları (gerekiyorsa) · güvenli veri doğrulama ve yetkilendirme.

**Altın kural:** İş kuralı tema içine, görsel sunum eklenti içine karıştırılmaz. Örnek: e-Fatura kodu child theme'e konursa tema değişikliğinde faturalar kesilmeyi durdurur.

### 16.2 Kod kalite kuralları

- WordPress ve WooCommerce hook/filter mekanizması önceliklidir.
- **Parent theme, WooCommerce ve iyzico eklentisi dosyaları değiştirilmez.** Otomatik güncelleme açık olduğu için doğrudan düzenleme ilk güncellemede silinir ve kimse fark etmez.
- Gereksiz template override yapılmaz.
- Override gerekiyorsa WooCommerce güncellemelerinde uyumluluğu takip edilir.
- Şablon override'ları `kuka-island-child/woocommerce/` altında tutulur.
- Tüm girişler doğrulanır, temizlenir; çıktılar escape edilir.
- Nonce ve yetki kontrolü olmadan yönetim işlemi yapılmaz.
- Gizli anahtarlar repoya yazılmaz.
- Üretim ve test ayarları ayrılır.
- Büyük özel işlevler tek dosyada toplanmaz.

#### 16.2.1 Commit kuralları

- **Commit mesajları kısa ve öz olur.** Konu satırı en fazla ~50 karakter, tek satır yeterliyse gövde yazılmaz.
- Conventional Commits ön eki kullanılır: `feat:`, `fix:`, `docs:`, `refactor:`, `chore:`, `test:`, `style:`.
- Gövde yalnızca "neden" konu satırından anlaşılmıyorsa eklenir.
- **Mesaja araç, asistan veya üretici imzası yazılmaz.** `Co-Authored-By`, "Generated with" ve benzeri satırlar kullanılmaz. Mesaj yalnızca yapılan işi anlatır.
- Commit yazarı: `MepCity <hamasetyasir@gmail.com>`.
- Bir commit tek bir mantıksal değişikliği taşır. Plan güncellemesi ile kod değişikliği aynı commit'e karışmaz.
- Gizli anahtar, `.env` dosyası, medya ve `node_modules` commit edilmez (§16.2, `.gitignore`).

### 16.3 Ortamlar

Local geliştirme → Staging / müşteri ön izleme → Production / canlı.

Canlıda doğrudan dosya düzenlenmez. Değişiklik önce local/staging üzerinde test edilir.

### 16.4 Customizer, Gutenberg ve yedekleme uyarısı

Blocksy Customizer ayarları, Gutenberg sayfa içeriği ve bazı tema ayarları **veritabanında** tutulur. Child theme ve Kuka Island Core ise dosya/Git tarafındadır. Full Site Editing block theme kullanılmaz; yine de tasarımın tamamı yalnızca Git'te değildir.

**Sonuç: yedekleme hem dosyaları hem veritabanını kapsamak zorundadır.** Yalnızca dosya yedeği alınırsa tasarım kaybedilir. Git deposu tek başına yeterli değildir.

### 16.5 Prototipten üretime taşıma kuralları

Faz 1 prototipi Next.js/React, üretim WordPress/PHP olduğu için tasarım bir kez yeniden yazılır (§4.9). Bu portun maliyetini düşük tutmak için prototip şu kurallara uyar:

1. **Tüm tasarım kararları CSS custom property olarak tanımlanır.** Renk, tipografi ölçeği, boşluk, radius, easing ve süre değerleri tek bir token dosyasında toplanır. Bu dosya child theme'e neredeyse birebir kopyalanır.
2. **Tailwind yalnızca yerleşim aracıdır.** Renk ve ölçü değerleri Tailwind sınıflarına gömülmez; token'lardan okunur. Böylece port sırasında değer avı yapılmaz.
3. **Etkileşim mantığı framework'e bağlanmaz.** Galeri, lightbox, zoom, çekmece ve filtre davranışı React state'ine gömülü tutulmaz; DOM üzerinden çalışan, çerçeveden bağımsız taşınabilir mantık olarak yazılır (§4.3'teki vanilla JS hedefi).
4. **Bileşen sınırları WooCommerce şablon sınırlarıyla hizalanır.** Ürün kartı, galeri, bilgi paneli, çekmece gibi bileşenler WooCommerce'te hangi şablon dosyasına karşılık gelecekse ona göre bölünür.
5. **Demo veri şekli WooCommerce ürün/varyasyon modelini taklit eder** (§13.2, §13.3). Prototipte uydurma alan adı kullanılmaz; alanlar WooCommerce karşılıklarıyla adlandırılır.
6. **Prototipte iş mantığı yazılmaz.** Sepet, ödeme, sipariş ve panel prototipte simüle edilir; gerçek mantık üretimde WooCommerce'e bırakılır.

**Prototip kodu üretim kod tabanı değildir.** Onaydan sonra prototip arşiv/referans olarak kalır; üretim deposu child theme ve Kuka Island Core üzerinden yürür.

---

## 17. Eklenti ve lisans stratejisi

### 17.1 İlk kurulumda

WordPress · WooCommerce · Blocksy Free · Blocksy Companion · Kuka Island child theme · Kuka Island Core · iyzico WooCommerce eklentisi (resmî/güncel sürüm doğrulanarak) · SEO eklentisi (bir adet, yalnızca gerçekten gereken özellikler için) · SMTP çözümü · yedekleme (hosting veya seçilen çözüm) · güvenlik (ağır paket yerine hosting ve temel sertleştirme).

### 17.2 Blocksy Pro satın alma kararı

Pro **teknik bir zorunluluk değildir.** Renge göre galerinin tamamen değişmesi (Product Variations Gallery) Blocksy'nin resmî dokümanına göre ücretsiz sürümde mevcuttur.

Pro'nun getirdikleri: variation swatch'ları · gelişmiş ürün galeri düzenleri · beden rehberi modülü · favoriler · gelişmiş filtreler · mega menü · quick view · sepet çekmecesi · özel WooCommerce içerik blokları.

**Karar yöntemi:**
1. Prototip ve teknik pilot ücretsiz sürümle tamamlanır.
2. Pro'nun kaç saatlik geliştirmeden tasarruf sağladığı ölçülür.
3. Tasarruf anlamlıysa alınır.

**Lisans tipi:** Bütçe ve kullanım süresi uygunsa lifetime tercih edilir. Lisans yenilenmezse site anında bozulmayabilir; güncelleme ve destek kesilmesi zamanla risk oluşturur. Lifetime lisans bu riski azaltır ancak satıcının ürünü sürdürmesini veya gelecekteki uyumluluğu garanti etmez.

**Ek fayda:** Pro, sınırsız geliştirme ve staging sitesi için ücretsiz development lisansı verir.

### 17.3 Eklenti sınırı

- Aynı işi yapan birden fazla eklenti kurulmaz.
- Güncellenmeyen veya düşük güvenilirlikli eklenti kullanılmaz.
- Eklenti yüklemek özel kod yazmaktan otomatik olarak daha iyi kabul edilmez.
- Özel kod yazmak da her durumda daha iyi kabul edilmez; bakım maliyeti karşılaştırılır.
- **Kart tahsilatı, ödeme imzası, webhook güvenliği ve ödeme geçidi özel olarak yeniden yazılmaz.** Kurumsal fatura alanı, sözleşme gösterimi ve benzeri sınırlı checkout uyarlamaları WooCommerce'in desteklenen hook/block API'leriyle yapılabilir. Checkout Blocks ile klasik checkout kararı iyzico teknik pilotunda verilir.

### 17.4 Sahiplik kuralı — devir için kritik

Alan adı, hosting ve satın alınan tüm lisanslar **müşterinin adına ve müşterinin e-postasıyla** kaydedilir; ödeme müşterinin kendi kartıyla yapılır.

Gerekçe:
- Kur riski geliştiricide kalmaz.
- Hosting ve alan adı zaten müşteri adına kayıtlı olmalıdır.
- Devir tesliminde transfer sorunu çıkmaz.
- **Lisans geliştiricinin hesabına bağlı kalırsa müşteri güncelleme ve destek için sürekli geliştiriciye bağımlı hâle gelir** — bu, projenin temel amacıyla doğrudan çelişir.

Bu kalemlerin bedeli teklife dahil edilse bile kayıtlar müşteri adına açılır.

---

## 18. Ödeme, kargo ve sipariş

### 18.1 iyzico

Şu senaryolar test edilmeden canlı anahtar kullanılmaz:

- Başarılı ödeme
- Başarısız ödeme
- Kullanıcı tarafından iptal
- Zaman aşımı
- Çift tıklama / tekrar istek (idempotency)
- 3D Secure dönüşü
- Sipariş durumu eşleşmesi
- İade ve kısmi iade (eklenti desteğine göre)
- Webhook / geri bildirim doğrulaması

Önce sandbox hesabı kullanılır. Test ve canlı anahtarlar ayrı tutulur.

Footer'da tema sahipliğinde, iyzico eklentisinden bayt değiştirilmeden kopyalanmış kart şeridi ile dile göre iyzico logosu gösterilir. Ödeme sağlayıcısı değişirse tek panel anahtarıyla iki logo birlikte kapatılır; dosyalar Medya Kütüphanesi'nden değiştirilemez. Başlangıç ekranı 12 otomatik başvuru kriterini ve beş manuel belge kutusunu ilgili yönetim ekranlarına bağlar.

**Başvuru sırası:** İncelemeyi yapacak iyzico ekibinin erişebileceği staging/geçici mağaza gerçek ürün/fiyatlar ve gerekli şirket-yasal bilgilerle kullanıma hazır hale getirilir; ardından iyzico başvurusu yapılır. Başvuru sürerken sandbox entegrasyonu test edilir, canlı anahtar onaydan sonra bağlanır. “Erken başvuru kesin reddedilir” varsayılmaz; iyzico'nun güncel başvuru koşulları takip edilir.

### 18.2 Kargo ilk sürümü

- Türkiye kargo bölgesi
- Sabit kargo ücreti
- Belirli sepet tutarı üzerinde ücretsiz kargo
- Gerekirse yerel teslimat veya mağazadan teslim, sonraki ihtiyaç olarak
- Yönetici tarafından takip numarası girişi
- Sipariş e-postasında takip bağlantısı

Kargo firması API entegrasyonu ilk sürüm dışındadır.

### 18.3 Sipariş durumları

Ödeme bekliyor · İşleniyor/hazırlanıyor · Kargoya verildi (özel durum gerekirse) · Tamamlandı · İptal edildi · İade edildi · Başarısız.

Müşteriye gösterilen Türkçe karşılıklar tutarlı olmalıdır.

### 18.4 E-posta ve bildirimler

- **SMTP zorunludur.** WordPress varsayılan PHP mail gönderimi spam'e düşer.
- SMTP eklentisi kurulmaz; `kuka-island-core`, yalnız `wp-config.php` sabitleri tam olduğunda PHPMailer'ı SMTP'ye geçirir. Gönderen `@kukaisland.com` alanında sabitlenir, Reply-To ayrı olabilir ve gizli değerler veritabanına/loga/panele girmez.
- `wp_mail()` başarısızlığı `Throwable` düzeyinde yakalanır. Sipariş tamamlanması e-posta teslimine bağlı değildir; hata sipariş notuna, WooCommerce loguna ve Başlangıç uyarısına düşer. Başlangıç ekranı ayrıca gerçek `function_exists('mail')` sonucunu ve yöneticiye test gönderimini gösterir.
- Sipariş e-postaları marka kimliğine göre şablonlanır (child theme içinde override).
- Sepet hatırlatma ve yorum hatırlatma Faz 8 kapsamındadır; ilk sürümde eklenti sayısını artırmaz.

---

## 19. İçerik ve ürün aktarımı

### 19.1 Pilot veri girişi

- İlk 3–5 gerçek ürün elle girilir.
- Renk, beden, stok ve galeri modeli doğrulanır.
- Panel kullanım kolaylığı test edilir.
- **Model kesinleşmeden tüm katalog içe aktarılmaz.**

### 19.2 CSV stratejisi

**Açılış kataloğu ~150 parça olarak teyit edildi (§5 soru 6). CSV içe aktarma artık koşullu değil, zorunludur.** Bu ölçekte elle giriş projenin emek bütçesinin büyük kısmını tek başına tüketir. İlk sürüme yalnızca **3–5 pilot ürünün** elle girişi dahildir (§29.1); kalan katalog müşterinin doldurduğu onaylı CSV ile aktarılır.

Renk ve beden sayıları henüz bilinmediği için gerçek varyasyon adedi belirsizdir. 150 parça × renk × beden kombinasyonu büyük bir varyasyon kümesi oluşturabilir; §13.4 varyasyon pilotu bu yüzden atlanamaz.

- Model onaylandıktan sonra standart CSV/Excel şablonu hazırlanır.
- Sütunlar: SKU, ürün adı, kesim, kategori, renk, beden, fiyat, stok, görsel dosya adları, açıklama.
- Müşteri şablonu doldurur.
- Önce küçük test içe aktarımı yapılır.
- Hatalı satırlar raporlanır.
- Üretimde toplu aktarım öncesi yedek alınır.

**Devir açısından değeri:** Müşteri, geliştiriciye ihtiyaç duymadan yeni koleksiyonu aynı şablonla kendi ekleyebilir. Bu, "panel teslim etmek" ifadesinin pratik karşılığıdır.

### 19.3 Kapsam sınırı

Teklif içine kaç ürünün elle girileceği ve CSV içe aktarma kapsamı teklif metninde açıkça belirtilir. Müşteriden düzensiz gelen yüzlerce ürün ve görselin temizlenmesi ayrıca değerlendirilir.

Örnek ifade: *"Excel şablonu tarafımızca hazırlanır; veri girişi ve görsel adlandırma tarafınızdan yapılır. İçe aktarma ve kontrol dahildir. Elle ürün girişi talep edilirse ayrıca değerlendirilir."*

---

## 20. Yasal ve uyum

### 20.1 Zorunlu sayfalar

Mesafeli Satış Sözleşmesi · Ön Bilgilendirme Formu · İade ve Teslimat Koşulları · KVKK Aydınlatma Metni · Çerez Politikası · İletişim.

Bunların tamamı iyzico başvurusundan **önce** canlı olmalıdır.

İletişim sayfası merkezî şirket kısa kodundan ticari unvan, marka, merkez adresi, e-posta, telefon ve yalnız mevcutsa MERSİS, KEP, meslek odası, davranış kuralları ve ETBİS satırlarını gösterir. Eksik değer için yer tutucu veya uydurma bilgi yayımlanmaz; MERSİS/KEP/oda/ETBİS müşteri girdisi bekler.

### 20.2 Kategoriye özgü — mayo ve iç giyim

Cayma hakkında hijyen istisnası uygulanabilir; otomatik kabul edilmez. Güncel mevzuata göre sağlık/hijyen açısından iadesi uygun olmayan ürünlerde teslimden sonra ambalaj, bant, mühür, paket veya benzeri koruyucu unsurun açılması önem taşır. Uygulama müşteri/hukuk danışmanı tarafından doğrulanır.

- Üründe uygun koruyucu unsur/hijyen bandı ve bunun açıldığına ilişkin operasyonel kayıt bulunmalı,
- İstisna **ürün sayfasında** (iade akordiyonunda) ve **iade politikası sayfasında** açıkça yazılmalı.

Bu baştan kurgulanmazsa müşteri ciddi bir iade sorunu yaşar. Mayo kategorisinde iade oranı yüksektir ve bu maddenin atlanması operasyonel bir risktir.

### 20.3 Diğer yükümlülükler

- **ETBİS kaydı** zorunlu.
- **Sepette/checkout'ta zorunlu onay kutusu:** Ön Bilgilendirme Formu + Mesafeli Satış Sözleşmesi. İşaretlenmeden ödemeye geçilemez.
- **Newsletter:** ticari elektronik ileti onayı ayrı kutu, ön işaretli değil.
- **Çerez onayı:** zorunlu olmayan çerezler varsayılan olarak kapalı.
- **e-Belge:** e-Fatura/e-Arşiv yükümlülüğü, yöntem ve güncel eşikler canlıya alma öncesi mali müşavir ve yürürlükteki GİB tebliğiyle doğrulanır. GİB Portal'ın yeterli olduğu varsayılmaz.
- **e-İmza / mali mühür:** Gereklilik şirket türü ve seçilen e-Belge yöntemine göre mali müşavir/entegratörle doğrulanır.
- Yasal metinler geliştirici tarafından hukuki danışmanlık verilerek oluşturulmuş sayılmaz; müşteri veya hukuk danışmanı onaylar.

---

## 21. SEO ve yapay zekâ uyumluluğu

- Sunucu tarafında okunabilir ürün içeriği
- Anlamlı HTML başlık hiyerarşisi
- Ürün ve kategori için benzersiz title/meta description
- Temiz ve kalıcı URL'ler
- Canonical etiketleri
- XML sitemap
- Robots ayarları
- `Product`, `Offer`, `BreadcrumbList` yapılandırılmış verileri
- Gerçek yorum varsa `Review`/`AggregateRating`; sahte puan kullanılmaz
- Stok, fiyat, para birimi ve SKU'nun yapılandırılmış veride doğru olması
- Görsel alt metinleri
- Open Graph ve sosyal paylaşım meta verileri
- Her ürün için özgün açıklama: kesim, kumaş, dolgu, kime uygun, beden aralığı
- Temiz ve tutarlı varyasyon yapısı (beden ve renk ayrıştırılabilir olmalı — §13.4 bunu sağlar)
- Google Merchant Center ürün feed'i, sonraki ticari hazırlık olarak
- WooCommerce REST API ve ürün feed'lerinin gelecekteki agent entegrasyonlarına kapı bırakması
- Yapay zekâ botlarının (GPTBot, OAI-SearchBot, ClaudeBot, PerplexityBot) robots kuralıyla gereksiz engellenmemesi

**Schema çakışması uyarısı:** Blocksy dahili schema verir ve WooCommerce Product schema çıkarır. Bir SEO eklentisi de kurulursa çift schema üretmemek için biri kapatılır.

### 21.1 Yapılmayacak

**LLM'lere görünür, insanlara gizli metin enjekte etmek.** Bu cloaking'dir, arama motorları cezalandırır, kazancı belirsizdir.

### 21.2 Agentic commerce — mevcut gerçeklik

Altyapı tek başına yapay zekâ görünürlüğü sağlamaz. Doğru ürün verisi, yapılandırılmış veri, feed, performans ve güvenilir içerik esastır.

Ajan üzerinden **satın alma** Türkiye ve iyzico için bugün hazır, garantili bir akış değildir. Birden fazla agentic commerce protokolü gelişmektedir; yalnızca Stripe'a bakarak alanın tamamen kapalı olduğu söylenmez. iyzico, WooCommerce ve büyük alışveriş ajanlarının yol haritaları takip edilir.

WooCommerce ürün ve sipariş işlemleri için MCP/Abilities altyapısı sunmaktadır; bu özellik güncel belgelerde developer preview olarak işaretlenmiştir. Agentic ödeme/checkout çekirdekte hazır kabul edilmez. Açık API ve yapılandırılmış ürün verisi geleceğe dönük olumlu bir temel sağlar.

**Not:** Ajan kaynaklı siparişler tarayıcı yüklenmeden tamamlanır; standart `gtag` tabanlı dönüşüm takibi çalışmaz, sunucu taraflı yükleme gerekir. Bugünün problemi değil, ileride çıkacak bir iş kalemi.

---

## 22. Performans hedefleri

Hedefler gerçek cihaz ve bağlantıda ölçülür:

- Ana içerik mümkün olduğunca hızlı görünmelidir.
- İlk ekranda gereksiz script ve eklenti yüklenmemelidir.
- Ürün görselleri doğru boyutta servis edilmelidir.
- Layout shift düşük tutulmalıdır.
- Kullanıcı etkileşimleri gecikmemelidir.
- Font dosyaları optimize ve self-hosted olmalıdır; `preload` ve `font-display: swap` kullanılır.
- Video mobil performansı bozmamalıdır.
- Üçüncü taraf pazarlama scriptleri izin ve ihtiyaç olmadan eklenmemelidir.

Teknik hedef olarak güncel Core Web Vitals "iyi" sınırları amaçlanır; müşteri içeriği ve üçüncü taraf scriptler sonucu etkileyebilir.

---

## 23. Erişilebilirlik

Semantik HTML · klavye ile tam gezinme · görünür odak stili · yeterli renk kontrastı · anlamlı alternatif metinler · form alanlarında görünür etiket · hataların yalnızca renkle anlatılmaması · modal ve drawer odak yönetimi · Escape ile kapanma · ekran okuyucuya uygun sepet ve galeri durum mesajları · dokunmatik alan boyutları · `prefers-reduced-motion` · zoom kapatılmadan kullanılabilir sayfa.

Hedef en az WCAG 2.2 AA prensiplerine yakın, temel ticari kullanılabilirliktir. Resmî erişilebilirlik sertifikasyonu ayrı kapsamdır.

---

## 24. Güvenlik, gizlilik ve yedekleme

- WordPress güvenlik güncellemeleri otomatik olabilir. WooCommerce, Blocksy ve iyzico gibi kritik bileşenler staging + yedek + regresyon kontrolünden sonra güncellenir.
- Yönetici hesaplarında güçlü parola ve mümkünse iki faktörlü doğrulama kullanılır.
- Gereksiz yönetici hesabı açılmaz.
- Dosya düzenleme ve hassas yönetim erişimi sınırlandırılır.
- Düzenli **veritabanı ve medya** yedeği alınır (§16.4).
- **Yedekten dönüş en az bir kez test edilir.** Yedeğin var olması ile çalışması ayrı şeylerdir.
- Test ve canlı iyzico anahtarları ayrılır.
- API anahtarları Git'e yazılmaz.
- Spam ve brute-force koruması uygulanır.
- KVKK/çerez izinleri kullanılan takip araçlarına göre yapılandırılır.
- Kişisel veriler gereğinden uzun saklanmaz; ticari ve yasal zorunluluklar müşteri tarafından doğrulanır.

### 24.1 Bakımsız çalışma önlemi

Sürekli bakım anlaşması olmayacağı için (§2.1):

- Güncelleme politikası yazılı olmalı: hangi bileşenin otomatik, hangisinin kontrollü güncelleneceği belirtilir.
- **Uptime ve checkout monitörü kurulmalı; alarm müşterinin erişebildiği kanala gitmeli.** Monitör arızayı haber verir, bakımın yerine geçmez.
- Müşteriye aylık/üç aylık kontrol listesi ve gerektiğinde teknik destek alma sorumluluğu devredilir.
- Eklenti sayısı §4.8'deki hedefte tutulmalı.
- Ödeme akışında özel kod bulunmamalı.

---

## 25. Analitik ve izleme

Müşteri onayı ve çerez politikasıyla:

- GA4 veya seçilen analitik çözümü
- Google Search Console
- Temel e-ticaret olayları: ürün görüntüleme, liste görüntüleme, sepete ekleme, sepetten çıkarma, checkout başlatma, satın alma
- Hata/log izleme, altyapı imkânına göre
- Uptime izleme

Satın alma verilerinde kişisel veri analitik servislere gönderilmemelidir.

---

## 26. Test planı

### 26.1 Fonksiyonel testler

Menü ve tüm ana bağlantılar · arama · filtre ve sıralama · ürün kartı fotoğraf gezinmesi · **onaylanırsa kart üzerindeki beden/stok satırı** · renk seçimi · beden seçimi · stok dışı varyasyon · galeri lightbox · zoom · sepete ekleme · sepet güncelleme · kupon · **yasal onay kutusu olmadan ödemeye geçilememesi** · misafir checkout · üyelik checkout · **kurumsal fatura alanları** · iyzico başarılı/başarısız senaryolar · sipariş e-postalarında SPF/DKIM/DMARC ve teslim testi · takip numarası · iade/iptal akışı.

### 26.2 Görsel ve responsive testler

Geniş masaüstü · dizüstü · tablet yatay/dikey · küçük ve büyük mobil · uzun ürün adı · uzun kesim adı · indirimli fiyat · çok renkli ürün · tek görselli ürün · çok görselli ürün · videosuz/videolu ürün · içerik eksikliği durumları.

### 26.3 Tarayıcılar

Güncel Chrome · Safari · Firefox · iOS Safari · Android Chrome.

### 26.4 Regresyon

Tema, WooCommerce veya kritik eklenti güncellemesinden sonra en az ürün, sepet, checkout ve ödeme akışı tekrar test edilir. Bakımsız bir sitede tek gerçek koruma budur.

---

## 27. Tasarım onayı ve revizyon süreci

### 27.1 Sunum kapsamı

Müşteriye yalnızca statik ana sayfa gösterilmez. Sunum en az şunları içerir:

- Masaüstü ana sayfa
- Mobil ana sayfa
- Kategori sayfası
- Ürün kartı etkileşimi
- Ürün detay sayfası
- Galeri ve zoom
- Renk/beden seçimi
- Sepet çekmecesi
- Ödeme sayfası görsel yönü

Sunum bağlantısı **şifre korumalı** olur: arama motoruna düşmemesi ve müşterinin "site yayında mı?" paniği yaşamaması için.

### 27.2 Revizyon turları

- **Tur 1:** genel yön, tipografi, renk, grid ve sayfa yapısı
- **Tur 2:** küçük görsel düzeltmeler ve içerik yerleşimi

Revizyonlar tek, birleştirilmiş liste halinde alınır. Yeni sayfa veya yeni özellik talebi revizyon değil kapsam değişikliğidir.

Onay yazılı alınır — kısa bir "tasarımı onaylıyorum" e-postası yeterlidir. Bu, canlıya geçtikten sonra tasarım tartışması açılmasını engeller.

### 27.3 Onay kapıları

1. Site haritası ve ürün modeli onayı
2. Tasarım sistemi onayı
3. Ana sayfa onayı
4. Kategori ve ürün detay onayı
5. Panel alanları onayı
6. Staging mağaza onayı
7. Ödeme ve canlıya alma onayı

**Kritik kural:** Tasarım onayı ücretsiz lokal/geçici ortamda alınır. Onay gelmeden hosting, alan adı ve tema lisansı satın alınmaz.

---

## 28. Uygulama aşamaları

### Faz 0 — Plan ve keşif

**Çıktılar:** bu planın onayı · müşteri sorularının cevapları · içerik envanteri · site haritası · ürün veri modeli.

**Kabul kriteri:** Belirsiz ticari kararların listesi ve varsayımlar müşteri tarafından görülmüştür.

### Faz 1 — Tasarım sistemi ve yüksek kaliteli prototip

**Çıktılar:** yeni, temiz tasarım projesi · tasarım token'ları · ana sayfa · kategori · ürün detay · galeri/zoom prototipi · sepet çekmecesi · mobil davranışlar · şifre korumalı müşteri sunum bağlantısı.

**Kabul kriteri:** Müşteri alışveriş akışını gezebilir ve görsel yön hakkında karar verebilir.

**Prototip katalog yükleme kararı:** Yaklaşık 150 ürün tek sayfada ve tek HTML belgesinde sunulmaz. Kategori ekranı sunucu tarafında sayfalanır; ilk hedef sayfa başına 24 üründür ve gerçek katalog/görsel ölçümüyle 16–32 aralığında ayarlanabilir. Her kart yalnızca kendi renk, beden, stok ve galeri verisini tek bir `application/json` yükünde taşır; aynı veri HTML özniteliklerinde tekrarlanmaz. Filtreleme sonrasında yalnızca görünür sayfanın ürünleri ve gerekli sonraki sayfa yüklenir. Bu karar hem HTML boyutunu hem başlangıç JavaScript/veri maliyetini sınırlar.

### Faz 2 — WordPress/WooCommerce teknik pilot

**Çıktılar:** local/staging WordPress · WooCommerce · Blocksy Free · Blocksy Companion · child theme · Kuka Island Core iskeleti · git deposu · global nitelikler/taksonomi · koşullu kategori ağacı · 3–5 pilot ürün · renk/beden/stok modeli · varyasyon galerisi · iyzico ile Checkout Blocks ve klasik checkout uyumluluk pilotu.

**Kabul kriteri:** Gerçek ürün verisi panelden girildiğinde tasarım doğru çalışır; iyzico/HPOS ve seçilen checkout mimarisi sandbox üzerinde doğrulanmıştır.

### Faz 3 — Tasarımın üretim temasına aktarılması

**Çıktılar:** global header/footer · ana sayfa dinamik alanları · onaylanırsa kesim indeksi · kategori grid ve filtreler · ürün kartları ve onaylanırsa beden/stok satırı · ürün detay galerisi · sepet ve checkout görünümü · görsel işleme hattı · responsive ve erişilebilirlik düzenlemeleri.

**Kabul kriteri:** Onaylanan tasarım statik veri olmadan WooCommerce verisiyle çalışır.

### Faz 4 — Yönetim paneli

**Çıktılar:** Site Görünümü ayarları · kilitli bloklar · ürün alanları · ilişkili ürün/takım alanı · yönetici rol ve menü sadeleştirmesi · kısa kullanım rehberi.

**Kabul kriteri:** Teknik olmayan yönetici ana kampanya ve ürün verisini kod yardımı olmadan değiştirebilir.

### Faz 5 — Satış ve iyzico

**Çıktılar:** yasal sayfalar · ETBİS kaydı · sepet · checkout · yasal onay alanları · kurumsal fatura alanları · iyzico sandbox ve canlı ayarları · temel kargo · SMTP · sipariş e-postaları.

**Kabul kriteri:** Test siparişleri başarıyla tamamlanır, hata senaryoları doğru işlenir.

### Faz 6 — İçerik, SEO, performans ve güvenlik

**Çıktılar:** CSV şablonu ve pilot sonrası ürün aktarımı · SEO meta ve yapılandırılmış veri · görsel optimizasyonu ve boyut ölçümü · cache/CDN · güvenlik ayarları · yedekleme ve geri dönüş testi · analitik.

**Kabul kriteri:** Ürünler doğru indekslenebilir, sayfalar hızlı ve güvenli çalışır.

### Faz 7 — QA, eğitim ve canlıya alma

**Çıktılar:** tarayıcı/cihaz testleri · ödeme test raporu · alan adı ve SSL · uptime ve checkout monitörü · canlı yayın · panel eğitimi · devir teslim belgesi · bilinen sınırlamalar listesi.

**Kabul kriteri:** Kritik hata yoktur; müşteri panelde temel işlemleri yapabilir; §34 kontrol listesi tamamdır.

### Faz 8 — Sonraki entegrasyonlar

Kargo firması API'si · e-Fatura · pazaryeri entegratörü · sepet hatırlatma · yorum hatırlatma · gelişmiş otomasyon · iade/değişim portalı · ikiden fazla dil/çoklu para birimi · ERP/muhasebe.

Her biri ayrı keşif, kapsam ve kabul kriteri gerektirir.

---

## 29. Tahmini emek dağılımı

Bu tahmin **kapsam planlama içindir**, kesin teklif değildir.

| İş paketi | Tahmini süre |
|---|---:|
| Keşif, ürün modeli ve plan | 4–8 saat |
| Tasarım sistemi | 8–14 saat |
| Ana sayfa ve global bileşenler | 12–18 saat |
| Kategori, grid ve filtre deneyimi | 8–14 saat |
| Ürün detay ve gelişmiş galeri | 12–20 saat |
| Sepet/checkout görsel ve işlevsel uyarlama | 8–14 saat |
| Panel ve özel alanlar | 8–14 saat |
| iyzico, temel kargo ve sipariş akışı | 8–14 saat |
| Görsel pipeline, SEO, performans ve güvenlik | 8–14 saat |
| CSV şablonu, içe aktarma ve pilot veri | 6–10 saat |
| QA, revizyon, eğitim ve canlıya alma | 10–18 saat |

Toplam geniş aralık yaklaşık **92–158 saat**.

Süreyi doğrudan etkileyen değişkenler: kesim ve renk sayısı, üst/alt beden karışımı kararı, dil sayısı, Blocksy Pro kullanımı, revizyon miktarı, hazır gelen fotoğrafların kalitesi ve adlandırma düzeni, elle girilecek ürün sayısı.

Kapsamın kontrol altında kalması için:

- İlk sürüm özellikleri korunmalı,
- Ürün giriş sayısı ve CSV kapsamı sayıyla sınırlandırılmalı,
- Revizyon iki toplu turla sınırlandırılmalı,
- Ücretli lisanslar gereksiz alınmamalı,
- Sonraki faz entegrasyonları ilk sürüme eklenmemelidir.

### 29.1 Bütçe koruma kuralı

Toplam teklif ₺35.000–₺40.000 ve dış masraflar dahil olduğu için 158 saatlik üst risk senaryosu ticari hedef değildir.

- **Hedef uygulama bandı:** 92–110 saat.
- **Onaysız aşılmayacak üst sınır:** 120 saat.
- Tahmin 120 saati geçerse favoriler, quick view, özel mega menü, özel off-canvas sepet, kesim indeksi, kartta beden/stok satırı ve WooCommerce çekirdeğinden daha gelişmiş filtreler sırasıyla sonraki faza taşınır.
- İlk sürüme yalnızca 3–5 pilot ürünün elle girişi dahildir. Kalan katalog müşteri tarafından doldurulan onaylı CSV ile içe aktarılır; veri temizleme ayrıca kapsamlanır.
- Blocksy Pro ancak maliyetinden daha fazla geliştirme süresi kazandırdığı teknik pilotla gösterilirse alınır.
- Dış masraf ve kalan işçilik payı satın alma öncesi bütçe tablosunda güncellenir.

### 29.2 Faz 1 takvimi — 10 gün

Müşteriye verilen 10 günlük hedef **Faz 1 tasarım prototipi ve müşteri sunumunu** kapsar. Canlı satan mağazayı kapsamaz.

| Gün | İş |
|---|---|
| 1 | Tasarım token'ları (renk, tipografi, 8px ölçek, hareket), global CSS, demo veri modeli, ~24 temsili demo ürün |
| 2 | Header, duyuru bandı, mobil menü, footer, tipografik wordmark placeholder |
| 3–4 | Ana sayfa: hero (fotoğraf), kategori vitrini, ürün şeritleri, editoryal blok, kesim indeksi hipotezi |
| 5 | Kategori sayfası: 4:5 grid, filtre paneli, sıralama, ürün kartı — beden/stok satırı hipotezi iki varyantlı |
| 6–7 | Ürün detay: galeri, lightbox, zoom, renk/beden seçimi, akordiyonlar, "Takımı tamamla" |
| 8 | Sepet çekmecesi, sepet sayfası, checkout görsel yönü (bireysel/kurumsal fatura, yasal onay kutuları) |
| 9 | Mobil geçiş, erişilebilirlik (klavye, odak, reduced-motion), performans |
| 10 | Şifre korumalı sunum yayını, iki hipotezin karşılaştırmalı sunumu, bilinen sınırlar listesi |

Kod dışı paralel çıktılar: güncel müşteri soruları · fotoğraf adlandırma talimatı (§14.2) · yasal metin şablonları (§3.3.1).

**10 günde yapılamayacak olan ve gerekçesi.** Bu maddeler geliştirme hızından bağımsızdır ve müşteriye yazılı bildirilir:

| Engel | Neden 10 güne sığmaz |
|---|---|
| iyzico canlı ödeme | Üye işyeri başvurusunun onay süresi bizde değil; başvuru için yasal sayfaların canlıda olması gerekir (§20.1) |
| ETBİS kaydı | Ayrı resmî süreç |
| 150 parçanın kataloğa girişi | Fotoğraflar çekilmedi; kesim, renk ve fiyat listesi teslim sonrası belli olacak (§5 soru 4, 5, 22) |
| Alan adı ve hosting | Tasarım onayına bağlı; §27.3 gereği onaydan önce satın alınmaz |
| Yasal metinlerin nihai hâli | Müşteri veya hukuk danışmanı onayı gerekir (§20.3) |

---

## 30. İlk sürüme dahil olanlar

Özgün responsive tasarım · ana sayfa · kategori ve koleksiyon sayfaları · gerçek kataloğa göre kesim filtresi/navigasyonu · ürün detay · gelişmiş galeri ve zoom · renk/beden varyasyonları · varyasyon galerisi · **takım/ayrı parça satışı ve "Takımı tamamla" ilişkisi** · **favoriler** · sepet · checkout · yasal onay akışı · iyzico · temel kargo kuralı · WooCommerce paneli · Site Görünümü kontrolleri · temel SEO · yapılandırılmış ürün verisi · e-posta şablonlarının marka uyarlaması · SMTP · güvenlik ve yedekleme temel kurulumu · uptime ve checkout izleme · hosting ve alan adı kurulumu · CSV şablonu · 3–5 pilot ürün girişi · müşteri eğitimi · canlıya alma. §29.1'de sonraki faza taşınabilecek hipotezler bu garanti kapsamına dahil değildir.

---

## 31. İlk sürüme dahil olmayanlar

e-Fatura · pazaryeri entegrasyonu · özel kargo API'si · iade/değişim portalı · ikiden fazla dil · çoklu para birimi · ERP/muhasebe · profesyonel fotoğraf çekimi · **ürün yorumları ve puanlama** · **takım set indirimi (bundle fiyat kuralı)** · **ana sayfa video alanı** · ürün açıklama metinleri · logo ve tam kurumsal kimlik tasarımı (ayrıca kararlaştırılmadıkça) · yüzlerce ürünün elle temizlenmesi ve girilmesi · **adlandırma kuralına uymayan görsellerin elle eşleştirilmesi** · sınırsız revizyon · özel mobil uygulama · sadakat/puan programı · abonelik sistemi · gelişmiş pazarlama otomasyonları · devir sonrası sürekli bakım ve destek · referans sitelerin birebir kopyası · **referans sitelerin görsel ve yasal metinlerinin kullanımı** (§3.3.1). Türkçe/İngilizce arayüz Faz 5B'de sonradan kapsama alınmıştır; yasal İngilizce çeviri ve ihracat altyapısı alınmamıştır.

---

## 32. Riskler ve önlemler

| Risk | Etki | Önlem |
|---|---|---|
| Bakımsız WordPress'te güvenlik açığı birikmesi | Site ele geçirilir, veri sızar | Yönetilen hosting, yazılı kontrollü güncelleme politikası, periyodik kontrol ve düşük eklenti sayısı |
| Güncelleme checkout'u sessizce bozar | Sessiz gelir kaybı | Uptime + checkout monitörü, alarm müşterinin telefonuna |
| Gerçek ürün fotoğraflarının geç gelmesi | Tasarım ve içerik gecikir | Demo görseliyle tasarım onayı, gerçek görselle ayrı içerik turu |
| Fotoğraf kalitesinin minimalist tasarımı çökertmesi | Site amatör görünür | Müşteriye yazılı bildirim, kadraj ve çözünürlük standardı |
| Çok sayıda renk/beden kombinasyonu | Panel ve veri girişi büyür | Pilot ürün, CSV şablonu, net SKU standardı, global nitelikler |
| Görsel adlandırma kuralına uyulmaması | Yüzlerce dosyanın elle eşleştirilmesi | Kural çekim öncesi yazılı bildirilir |
| Sınırsız revizyon | Süre aşılır | İki toplu tur ve kapsam değişikliği kaydı |
| Gereksiz eklenti kullanımı | Performans ve bakım sorunu | Her eklenti için gerekçe ve alternatif karşılaştırması |
| Blocksy/WooCommerce güncellemesi | Şablon uyumsuzluğu | Minimum override, staging regresyon testi |
| Customizer/Gutenberg verisinin yedeklenmemesi | Tasarım ve içerik kaybı | Yedek hem dosya hem veritabanını kapsar |
| iyzico eklentisinin tema/checkout güncellemesiyle uyumsuzlaşması | Ödeme alınamaz veya sipariş durumu bozulur | Faz 2 sandbox pilotu, staging regresyonu, webhook/log kontrolü ve geri dönüş planı |
| Büyük görseller | Yavaşlık ve depolama | Responsive boyutlar, ölçüm, CDN/offload kararı |
| iyzico dönüş hatası | Sipariş/ödeme uyuşmazlığı | Sandbox senaryoları, log ve idempotency kontrolü |
| Yasal metin eksikliği | Canlıya alma ve iyzico onayı riski | Müşteri/hukuk onayı olmadan nihai kabul yok |
| Hijyen istisnasının atlanması | Yönetilemez iade yükü | §20.2 maddeleri ürün ve politika sayfalarına yazılır |
| Kapsama sonradan entegrasyon eklenmesi | Süre ve kapsam aşımı | Faz 8'e taşıma ve ayrı değerlendirme |
| Lisans/hosting geliştirici adına kayıtlı kalması | Müşteri devirden sonra bağımlı kalır | §17.4 sahiplik kuralı |
| Referansın Aslora'dan Jacquemus'a kayması | Beklenti kontrolsüz büyür | Yazılı kapsam ve "birebir kopya değildir" maddesi |

---

## 33. Tamamlanmış sayılma kriteri

İlk sürüm ancak aşağıdakilerin tamamı sağlandığında tamamlanmış sayılır:

- Onaylanan tasarım masaüstü ve mobilde uygulanmıştır.
- Ana sayfa, kategori, ürün, sepet ve checkout dinamik veriyle çalışır.
- Ürün yöneticisi ürün, renk, beden, stok ve fotoğraf ekleyebilir.
- Ana hero ve ana sayfa seçkileri panelden değiştirilebilir.
- Galeri, lightbox, zoom ve renk galerisi kullanılabilir durumdadır.
- Kart üzerindeki beden/stok satırı tasarımda onaylandıysa doğru çalışır; onaylanmadıysa bu kriter uygulanmaz.
- iyzico testleri tamamlanmıştır.
- Temel kargo ve sipariş e-postaları çalışır; SPF/DKIM/DMARC yapılandırılır ve teslim testi yapılır. Her alıcıda spam'e düşmeme garantisi verilmez.
- Yasal onay kutusu olmadan ödemeye geçilemez.
- Kritik erişilebilirlik ve responsive sorun yoktur.
- Kritik tarayıcı hatası yoktur.
- Yedekleme ve geri dönüş yöntemi tanımlı ve **test edilmiştir**.
- SEO başlıkları, sitemap ve ürün yapılandırılmış verisi mevcuttur.
- Yasal metinler müşteri tarafından sağlanmış/onaylanmıştır.
- ETBİS kaydı yapılmıştır.
- Müşteri panel eğitimi almıştır.
- Bilinen sınırlamalar ve sonraki faz listesi teslim edilmiştir.

---

## 34. Devir teslim kontrol listesi

- [ ] Alan adı müşteri adına kayıtlı, yönetim paneli erişimi müşteride
- [ ] Hosting hesabı müşteri adına, fatura müşteriye gidiyor
- [ ] Blocksy lisansı (alındıysa) müşterinin hesabına bağlı
- [ ] WordPress yönetici hesabı müşteri adına; geliştirici hesabı ayrı ve kaldırılabilir
- [ ] iyzico hesabı müşteri adına, API anahtarları müşteride
- [ ] SMTP hesabı müşteri adına
- [ ] Analitik ve Search Console mülkiyeti müşteride
- [ ] Güncelleme politikası yazılı; kritik bileşenler staging üzerinde kontrollü güncelleniyor
- [ ] Günlük yedek çalışıyor **ve geri yükleme testi yapılmış**
- [ ] Uptime + checkout monitörü kurulu, alarm müşterinin telefonuna gidiyor
- [ ] Gerçek kartla test siparişi verilmiş ve iade edilmiş
- [ ] Mobil ve masaüstü tarayıcı testleri tamam
- [ ] Tüm yasal sayfalar canlı ve güncel
- [ ] ETBİS kaydı yapılmış
- [ ] CSV ürün şablonu ve kullanım talimatı teslim edilmiş
- [ ] Görsel adlandırma kuralı yazılı olarak teslim edilmiş
- [ ] Panel kullanım dokümantasyonu teslim edilmiş (hangi alan neyi değiştirir)
- [ ] Müşteri eğitimi yapılmış: ürün ekleme, CSV ile koleksiyon ekleme, sipariş yönetimi, kampanya kurma, içerik güncelleme
- [ ] Git deposu erişimi müşteriye devredilmiş veya arşivi teslim edilmiş
- [ ] Bu `PLAN.md` dosyasının güncel hâli müşteriye teslim edilmiş
- [ ] Bilinen sınırlamalar listesi teslim edilmiş

---

## 35. Projeyi sürdürecek yapay zekâ / geliştirici için çalışma talimatı

1. Her işe başlamadan önce bu `PLAN.md` dosyasını tamamen oku.
2. Git durumunu kontrol et; kullanıcıya ait değişiklikleri silme veya ezme.
3. Aynı anda yalnızca bir fazı aktif tut.
4. Tasarım onayı olmadan üretim panelini gereksiz ayrıntıda geliştirme.
5. Gerçek veri modeli doğrulanmadan toplu ürün aktarımı yapma.
6. Parent theme, WooCommerce ve iyzico eklentisi dosyalarını değiştirme.
7. İş mantığını child theme'e gömme; `Kuka Island Core` eklentisinde tut.
8. Görsel sunum kodunu işlev eklentisine taşıma; child theme'de tut.
9. Yeni eklenti eklemeden önce neden gerekli olduğunu ve bakım riskini bu belgeye yaz.
10. Ücretli lisans satın alma veya canlı anahtar kullanma öncesinde kullanıcı onayı al.
11. Yasal, ödeme ve canlıya alma değişikliklerinde test kanıtı oluştur.
12. Panelden yönetilecek her içerik için veri alanı, varsayılan değer, doğrulama ve ön yüz fallback'i tanımla.
13. Renk ve Beden'i **global nitelik** olarak tanımla; ürün bazlı nitelik kullanma.
14. Ürün kartı görsel oranını değiştirme; değiştirmek tüm görsellerin yeniden üretilmesi demektir.
15. Yeni sayfa veya özellik talebini önce kapsam tablosuna ekle.
16. Bir faz tamamlanınca kabul kriterlerini işaretle ve §38 Karar Günlüğü'nü güncelle.
17. Kullanıcı istemeden eski prototipten kod kopyalama.
18. Birebir Jacquemus kopyası üretme; yalnızca tasarım ilkelerini özgün biçimde uygula.
19. §5'te "Bloke" işaretli soru cevapsızken ilgili faza başlama.
20. **Commit mesajlarını kısa ve öz yaz; mesaja araç, asistan veya üretici imzası ekleme** (§16.2.1).
21. **Referans sitelerin (Jacquemus, Aslora) görsellerini ve yasal metinlerini hiçbir ortamda kullanma** — yerel, sunum ve canlı dahil (§3.3.1).
22. Prototipte iş mantığı yazma. Sepet, ödeme, sipariş ve panel yalnızca görsel olarak simüle edilir (§4.9, §16.5).
23. Prototipte renk ve ölçü değerlerini Tailwind sınıflarına gömme; CSS custom property token'larından oku (§16.5).
24. `Beden`'i tek global nitelik olarak kur; ürün bazlı seçim terim alt kümesiyle yapılır, custom nitelikle değil (§13.4.1).
25. Takım satışında varsayılan model "Takımı tamamla" özel UI'dır; set indirimi talebi gelmeden bundle eklentisi kurma (§13.1.1).

---

## 36. İlk uygulanacak görev sırası

Günlük dağılım için §29.2'deki 10 günlük Faz 1 takvimi esas alınır.

1. ~~Müşteri sorularını (§5) tek listede hazırla ve gönder.~~ **Tamamlandı** — `docs/MUSTERI_SORULARI.md`; cevaplar §5 tablosuna işlendi, üç madde açık (16, 17, 24).
2. Geçici içerik ve ürün veri setini oluştur. Görseller yalnızca lisanslı/özgün kaynaktan (§3.3.1).
3. ~~Marka tasarım brief'ini netleştir.~~ **Tamamlandı** — `docs/TASARIM_BRIEFI.md`.
4. Tipografi, renk, grid, boşluk ve hareket token'larını belirle (§11).
5. Ana sayfanın masaüstü ve mobil ilk tasarımını hazırla.
6. Kategori ve ürün kartı davranışını hazırla; beden/stok satırını alternatifli tasarım hipotezi olarak test et.
7. Ürün detay galerisi ve zoom prototipini hazırla.
8. Sepet çekmecesi ve checkout görsel yönünü hazırla.
9. Şifre korumalı müşteri sunum bağlantısını oluştur.
10. Birinci revizyon turunu uygula.
11. Tasarım onayından sonra WooCommerce teknik pilotuna geç (Faz 2).

Adım 1 ile erken görsel keşif kısmen paralel yürütülebilir. Ancak kategori, filtre, ürün kartı ve ürün detay yapısı ürün veri modelinden bağımsız değildir; §5'te ilgili fazı bloke eden cevaplar gelmeden bu ekranlar kilitlenmez.

---

## 37. Referanslar

- Jacquemus ana mağaza: <https://www.jacquemus.com/en_il>
- Jacquemus kadın beachwear kategorisi: <https://www.jacquemus.com/en_il/beachwear-women>
- Aslora (Türkiye, Shopify tabanlı bikini markası, yerel pazar referansı): <https://asloraswim.com/>
- WooCommerce variable product dokümanı: <https://woocommerce.com/document/variable-product/>
- WooCommerce ürün görselleri ve galerileri: <https://woocommerce.com/document/adding-product-images-and-galleries/>
- Blocksy varyasyon galerisi (Free'de mevcut): <https://creativethemes.com/blocksy/docs/woocommerce/product-variations-gallery/>
- Blocksy WooCommerce Extra (Pro özellik listesi): <https://creativethemes.com/blocksy/docs/extensions/woocommerce-extra/>
- Blocksy Free / Pro karşılaştırması: <https://creativethemes.com/blocksy/pricing/>
- Blocksy Customizer yaklaşımı: <https://creativethemes.com/blocksy/docs/theme-options/theme-options-intro/>
- Blocksy Free/Pro uzantıları: <https://creativethemes.com/blocksy/docs/extensions/extensions-general/>
- WordPress `big_image_size_threshold`: <https://developer.wordpress.org/reference/hooks/big_image_size_threshold/>
- iyzico WooCommerce teknik dokümanı: <https://docs.iyzico.com/platformlar/woocommerce>
- iyzico WooCommerce eklenti ve uyumluluk geçmişi: <https://wordpress.org/plugins/iyzico-woocommerce/>
- iyzico üye işyeri başvuru koşulları: <https://www.iyzico.com/isim-icin/hesap-olustur>
- ETBİS resmî SSS: <https://etbis.ticaret.gov.tr/tr/SSS>
- GİB güncel e-Belge mevzuatı: <https://www.gib.gov.tr/>
- WooCommerce MCP / Abilities dokümanı: <https://developer.woocommerce.com/docs/features/mcp/>

Referanslar tasarım ilkelerini ve teknik davranışı anlamak içindir; üçüncü taraf içerik ve tasarımlar kopyalanmayacaktır.

---

## 38. Karar günlüğü

| Tarih | Karar | Gerekçe |
|---|---|---|
| 2026-08-24 | Checkout DOM mutasyonları hata alanlarını senkronize ederken odak/kaydırma yapmaz; ilk hata odağı yalnız ilk doğrulama yükünde ve `checkout_error` olayında çalışır, kullanıcı bir form alanında yazıyorsa programatik odak taşıma atlanır. İki özel yasal onay `update_checkout` öncesinde saklanıp `updated_checkout` sonrasında geri yüklenir | Alan hata düğümünün silinmesi MutationObserver'ı tetikleyip kalan tuşları başka alana taşıyordu; WooCommerce ödeme fragment'ini yenilediğinde özel onay kutularını yeniden oluşturup müşteri seçimini kaybediyordu |
| 2026-08-24 | Ana sayfa ve çok yakında videosu aynı H.264 responsive kaynakları kullanır; mobil `720×1280 / 2.213.198 bayt`, masaüstü `1920×1080 / 4.542.803 bayt`; kaynak yalnız JS ile uygun viewportta yüklenir, `prefers-reduced-motion` veya `saveData` durumunda video indirilmez ve poster kalır | Otomatik oynatma `preload=metadata` olsa da eski 10,9/13,7 MB dosyaların tamamını indiriyordu; yeniden kodlama mobilde `%79,7`, masaüstünde `%66,7` aktarım azalması sağladı ve desteklemeyen tarayıcının yanlış ilk kaynağı seçme riski kaldırıldı |
| 2026-08-24 | URL-dil sonucu istek imzasıyla statik önbelleğe alınır; gettext haritaları yalnız ilk ilgili çağrıda kurulur ve ilgisiz domainler dil çözümünden önce döner | WooCommerce sayfalarındaki binlerce gettext çağrısında aynı URL ayrıştırma/regex ve yaklaşık 230 girdili dizi kurulumunu tekrarlamamak; testlerin aynı süreçte istek URI'sini değiştirebilmesini de korumak |
| 2026-08-24 | Yerel PHP OPcache `192 MB / 16 MB interned strings / 20.000 dosya` sözleşmesine alınır; üretim Redis/tam sayfa cache/InnoDB ayarı hostingte ölçülmeden hazır sayılmaz ve WooCommerce istisnalarıyla yayın kapısında doğrulanır | Docker üretim ortamı değildir; doğrulanmamış Redis drop-in'i veya kör `512M` MySQL ayarı sepet/checkout tutarlılığını ya da paylaşımlı hostingi bozabilir. Canlı katman Veridyen yetenekleri ve HIT/BYPASS ölçümüyle seçilmelidir |
| 2026-08-24 | Yerel yönetici ve Shop Manager kullanıcı adları/parolaları ilk kurulumda rastgele üretilip yalnız Git dışı `.env` içinde tutulur; eski sabit kullanıcı adları kaynak ve belgelerden, parola içeren QA günlükleri tüm Git geçmişinden kaldırılır | Yönetici giriş adını tahmin edilebilir olmaktan çıkarmak, geçmiş committe kalan test parolasını geçersizleştirip depodan bütünüyle temizlemek ve yeniden sızıntıyı otomatik kurulum sözleşmesiyle önlemek |
| 2026-08-24 | WordPress 7.0.4, WooCommerce 11.0.1, Blocksy/Companion 2.1.53 ve Loginizer 2.1.0 güvenlik bakım sürümlerine sabitlenir; müşteri yüzüne iyzico izinli CSP ve standart güvenlik başlıkları, üretim HTTPS'e başlangıç HSTS'i ve `security.txt` eklenir; kurulum parolaları etkileşimli WP-CLI günlüğüne verilmez | WordPress 7.0.2 giriş ekranındaki yayımlanmış pre-auth reflected XSS/RCE zincirini, sonraki Author+ RCE açığını, WooCommerce mağaza bildirimi XSS'ini, Blocksy dinamik içerik script riskini ve Loginizer yarış koşulunu kapatmak; taramada doğrulanan eksik başlıkları ödeme akışını koruyarak gidermek ve test parolasının kurulum çıktısına sızmasını önlemek |
| 2026-08-24 | “Çok yakında” için optimize edilen aynı responsive video çifti ana sayfa hero arka planında da kullanılır; paneldeki masaüstü/mobil hero görselleri yüklenme ve reduced-motion durumlarında poster olarak korunur | Müşteri ana vitrinde hareketli görüntüyü tercih etti; aynı dosyaları yeniden kullanmak ikinci medya kopyası üretmeden görsel süreklilik sağlar ve panel görselleri güvenli geri dönüş olarak kalır |
| 2026-08-24 | “Çok yakında” ekranında teslim edilen yatay videonun ilk 25 saniyesi kullanılır; masaüstü 1920×1080 ve mobil 1080×1920 ayrı, sessiz MP4 kaynakları sunulur; hareket azaltma tercihinde video indirilmeden poster gösterilir | Ürün → deniz → tek model → iki model akışı ilk deneme için dengeli bir teaser verir; ayrı kadrajlar mobilde kaliteyi korur, 25 saniye ve sessiz kaynaklar tam uzunluktaki 62 MB dosyanın ilk açılış yükünü azaltır ve daha sonra kaynak değiştirilebilir |
| 2026-08-11 | Checkout telefonu `5XX XXX XX XX` görünümünde, `5XXXXXXXXX` kayıt biçiminde standartlaştırılır; `0` ve `+90` önekli yapıştırmalar normalize edilir, diğer başlangıçlar ve eksik/fazla rakamlar istemci ile sunucuda reddedilir | Sipariş telefonlarının tek biçimde tutulması, mobil klavyede kolay giriş ve hatalı/eksik numaranın siparişe geçmeden alan üzerinde açıklanması gerekir |
| 2026-08-11 | Ürün detayındaki iyzico güven şeridi bilgi kolonunda ortalanır, ilk ürün akordiyonundan 24 px ayrılır ve eklentinin resmî CDN SVG kart logoları 32 × 24 px net gösterim kutusunda tutulur | Müşteri şeridin sola yaslı, detay çizgisine yapışık ve kart işaretlerinin bulanık göründüğünü bildirdi; eklenti varlığı kopyalanmadan vektör kaynak ve güncelleme sınırı korunur |
| 2026-08-11 | Checkout doğrulaması JS açıkken alanla eşleşen üst özet satırlarını kaldırır; her hatayı ilgili kontrol üzerinde token tabanlı kırmızı çerçeve ve tek satır içi mesajla gösterir, ilk boş alana kaydırıp odaklar ve geçerli girişte durumu anında temizler. Yönetici/test e-postası checkout önizlemesine taşınmaz | Müşteri yalnız alan bazlı geri bildirim istedi; WooCommerce 11'in açıklama düğümü tek kaynaktan yeniden kullanılarak yinelenen mesaj önlenir, JS'siz sunucu özeti ve gerçek müşteri verisi korunur |
| 2026-08-10 | Faz 10 checkout uyarıları form içindeki sabit tam-genişlik grid satırında birleştirilir; doğrulama metni görünen alan etiketinden `woocommerce_checkout_required_field_notice` filtresiyle üretilir, alan şeması değiştirilmez | Sunucu ve AJAX uyarılarını JS'siz aynı yerde tutmak, sağ kolonu otomatik grid yerleşiminden korumak ve `Fatura Ad` / `Billing First name` tekrarını ödeme alanlarına dokunmadan gidermek |
| 2026-08-10 | Faz 9 sipariş e-postası Core içinde `Throwable` korumalıdır; yapılandırma yalnız `wp-config.php` sabitlerinden okunur, PHPMailer SMTP kancası kullanılır ve eklenti kurulmaz | Veridyen'de kapalı PHP `mail()` işlevinin ödeme sonrası isteği fatal ile kesmesi; e-posta teslimini sipariş kaydından ayırmak ve gizli bilgileri veritabanı/repo dışında tutmak |
| 2026-08-10 | Faz 8 ödeme logoları tam renkli ve değiştirilmeden tema varlığı olarak tutulur; kart/iyzico varlıkları §11.2 ham renk kuralının belgeli tek istisnasıdır, panel yalnız görünürlüğü yönetir | Mastercard/Visa/iyzico marka kuralları yeniden renklendirmeyi yasaklar; eklenti yoluna doğrudan bağlanmadan güncelleme dayanıklılığı ve operatör güvenliği korunur |
| 2026-08-09 | Faz 7 paneli görev odaklı Başlangıç/Yönetim Haritası, 13 sekmeli ve aranabilir Site Görünümü, yan yana TR/EN düzenleyiciler ve engellemeyen ürün kontrol listesi kullanır; yakında + noindex korunur | Bakım anlaşması olmayan, teknik seviyesi düşük mağaza sahibinin ekran aramadan doğru yazma kaynağına ulaşması ve kabul sırasında mağazanın/indeksin açılmaması |
| 2026-08-09 | Faz 6A marka hikâyesi altı sahneli, panel kontrollü ve `IntersectionObserver` tabanlı kaydırma anlatısına dönüştü; `48em` altı, JS kapalı ve `prefers-reduced-motion: reduce` durumları aynı sunucu DOM'undan düz makale gösterir | Kaydırmayı ele geçirmeden editoryal anlatı sağlamak; mobil adres çubuğu/`100svh` kırılganlığını, hareket hassasiyetini ve JS bağımlılığını içerikten ayırmak |
| 2026-08-09 | Dile göre ayrılan WooCommerce fragment HTML'iyle birlikte `cart_hash_key` de dile göre ayrılır; WooCommerce session cookie ve gerçek sepet ortak kalır | İngilizce sepete ekleme sonrası Türkçe taraftaki eski boş fragment'in ortak yeni hash ile yanlışlıkla geçerli sayılmasını önlemek |
| 2026-08-09 | Faz 5E'de tüm public İngilizce permalink, WooCommerce URL ve yönlendirmeleri tek `/en/` dönüştürücüsünden geçer; teknik admin/REST/AJAX uçları ön eksiz kalır. Cart fragments anahtarı ve AJAX dil parametresi dile bağlıdır | İngilizce ürün → sepet → ödeme → sipariş alındı zincirinin Türkçe URL veya fragment içeriğine düşmesini önlemek |
| 2026-08-09 | Dil adları, marka adları, URL/sayı/medya/renk/telefon/şirket alanları çevrilmeyen tek-kaynak sınıfıdır; seçici her iki vitrinde `Türkçe / English` gösterir | Teknik ve marka değerlerinde gereksiz `_en` ikizlerinin ayrışmasını önlemek; her dilin adını kendi dilinde korumak |
| 2026-08-09 | Faz 5D hero içeriği alt tabana bağlıdır; uzun başlık ayrı tokenla küçülür ve iki dil her görsel kabulde birlikte ölçülür | İngilizce metnin Türkçeden uzun olması nedeniyle tek dildeki kontrast ve yerleşim sonucunu diğer dile genellememek |
| 2026-08-09 | Faz 5C İngilizce içerikleri ilk editoryal geçiş olarak yazıldı; marka hikâyesi Kübra'nın birinci ağız sesini, kısa satır ritmini ve `Love, KÜBRA` imzasını koruyarak `/en/hakkimizda/` altında yayımlandı | Sunumda İngilizce yüzeylerde Türkçe fallback bırakmamak; ilk geçişi müşterinin doğal gözden geçirme akışına açmak |
| 2026-08-09 | İngilizce fallback uyarısı yalnız müşterinin sekiz yasal sözleşmesinde kalır; bu sözleşmelerin Türkçe sürümü bağlayıcıdır ve EN alanları boş tutulur | Hukuk danışmanından gelmeyen taslak sözleşmeyi yayımlayarak bağlayıcılık tartışması yaratmamak |
| 2026-08-09 | Faz 5C ek kararında hero metin perdesi ve panel yoğunluk alanı kaldırıldı; fotoğraf tam görünür, metin okunurluğu paneldeki açık/koyu tona bağlıdır | Müşteri fotoğrafın ürün üstünü kesen perdeyi istemedi; görsel değişiminde metin bölgesi kontrastı yeniden ölçülür |
| 2026-08-09 | Ana sayfa en üstteyken header ve dil seçici beyazdır; yalnız header alanındaki %72 koyu yüzey fotoğraftan bağımsız AA sağlar. Bir header yüksekliği kaydırılınca kâğıt yüzey/koyu metne geçer; JS kapalı fallback doğrudan kâğıt/koyudur | Fotoğraf değişse de menü/ikon/marka okunurluğunu korumak, müşterinin istediği açık üst durumunu ve erişilebilir JS'siz başlangıcı birlikte sağlamak |
| 2026-08-09 | Hero metni perde ve gölge olmadan, ayrı `--measure-hero-copy` ve `--text-heading-hero` tokenlarıyla mevcut fotoğrafın açık beton alanına sığdırılır | Fotoğrafı örtmeden mevcut görselde başlığın siyah ürün üzerine taşmasını ve kontrast kaybını önlemek |
| 2026-08-09 | Footer marka kilidi header ile aynı `.kuka-logo` ölçeğini devralır; footer'a özel büyük yazı ve amblem tokenları kaldırıldı | Üst ve alt marka imzasını aynı görsel ağırlığa getirip footer'daki ikinci odak noktasını sakinleştirmek |
| 2026-08-09 | Bülten alanı kalıcı etiket + placeholder, bağımsız alt çizgili e-posta alanı, ortak site düğmesi ve kare özel onay kutusu kullanır; yerel kayıt/izin altyapısı değişmez | Uzun İngilizce metin ve dar ekranda okunur, klavyeyle çalışan ve JS'siz gönderilebilen form elde etmek |
| 2026-08-09 | Footer WhatsApp satırı sosyal bağlantılardan sonra, `brand.whatsapp_phone` kaynağından otomatik üretilir; telefon boşsa hiç render edilmez | Footer, servis şeridi ve yüzen düğmenin tek numara kaynağından birlikte güncellenmesini sağlamak |
| 2026-08-09 | Ücretsiz kargo `ignore_discounts` tercihi Site Görünümü'nde “indirimden sonra/önce” olarak yönetilir; varsayılan `no` kalır ve WooCommerce yöntem ayarına yazılır | Operatörün eşik davranışını tek kaynaktan seçmesi ve sepet ilerleme metniyle WooCommerce uygunluk hesabının aynı kupon tabanını kullanması gerekir |
| 2026-08-09 | Faz 5B Türkçe/İngilizce arayüzü eklentisiz, URL-kaynaklı özel katmandır: Türkçe ön eksiz, İngilizce `/en/`; ürün/sayfa için ikinci kayıt yoktur, EN değerler aynı kayıt metasındadır | TranslatePress ürün düzenleme akışında çeviri istemez; WPML/Polylang ikinci kayıt ve yıllık bağımlılık getirir. Tek stok/fiyat kaynağı ve bakım anlaşmasız mimari korunur |
| 2026-08-09 | İngilizce alan boşsa Türkçe fallback gösterilir; sekiz hukuk metninin EN alanı boş ve üstte Türkçe sürümün bağlayıcı olduğu notu vardır | Otomatik/uydurma çeviri yapılmaz; hukuk metninin sorumluluğu müşterinin hukuk danışmanındadır |
| 2026-08-09 | Sipariş dili `_kuka_order_locale` olarak kaydedilir ve müşteri e-postası gönderilirken locale geçici olarak o dile alınır | URL ile seçilen checkout dili e-posta ve sipariş sonrası yüzeylerde korunur; ürün/fiyat/stok tek kayıttan kalır |
| 2026-08-09 | Faz 4C hero perdesi yalnız metin tarafına alındı ve yoğunluğu panelden yönetilir | Ürün fotoğrafını soldurmadan metinde AA kontrastı korumak; %78 varsayılanında muhafazakâr alt sınır 7.66:1'dir |
| 2026-08-09 | Manifesto daha kısa iki içerik satırına indirildi; Hakkımızda açılışı aynı panel alanına bağlandı; PDF kaynak metni ayrı blokta birebir korunur | Ana sayfa yüksekliğini azaltırken marka hikâyesini yeniden yazmamak ve tek içerik kaynağını korumak |
| 2026-08-09 | Bülten yalnız onaylı kayıt toplar; onay metni/tarih/IP saklanır, panelde liste/CSV vardır, toplu gönderim yoktur | JS'siz ve denetlenebilir kayıt gerekir; ticari elektronik ileti gönderimi İYS/hukuk süreci tamamlanmadan kapsam dışıdır |
| 2026-08-09 | Faz 4B'de editoryal başlık manifesto ölçeğinden ayrıldı; menü etiketi “Hikâyemiz” oldu; beden terimleri `order` metasıyla S–M–L sıralanır | Dar editoryal kolonda kelime bölünmesini ölçeği gerçekten sığdırarak önlemek, kayıtlı panel verisini yeni etikete taşımak ve WooCommerce `menu_order` davranışını temiz kurulumlarda deterministik kılmak |
| 2026-08-08 | Müşteri değişim hizmeti sunmamayı seçti; sitede yalnız 14 günlük cayma hakkı anlatılır. `04_Cayma_Hakki_ve_Iade_Sozlesmesi` `/iade-degisim/` URL'sinde, “Cayma Hakkı ve İade” başlığıyla kalır | URL atıfları kırılmaz. Sözleşme §5 ile tutarsızlık müşteriye bildirildi, metin bizim tarafımızdan değiştirilmedi |
| 2026-08-08 | Üyelik sistemi ve Nextend Social Login kaldırıldı; misafir ödeme kod tarafından zorlanır, `/hesabim/` silinmeden ana sayfaya 302 yönlenir, `/siparis-takibi/` kalır | WooCommerce misafir sepeti kendi oturum tablosu ve çereziyle çalışır; ayrı localStorage sepeti yazmak kargo/kupon/stok/vergi sözleşmesini böler. `03` üyelik sözleşmesi hukuk kararı gelene kadar taslaktır |
| 2026-08-08 | Google girişi Faz 3H'de eklendi, Faz 4A'da üyelik kaldırıldığı için geri çekildi | Toplanmayan hesap ve sosyal profil verisini vaat etmek hem arayüz hem KVKK metniyle tutarsız olurdu; Loginizer yönetici girişi için kalır |
| 2026-08-08 | Büyük koyu yüzeyler kreme, servis hücreleri koyu kahveye çevrildi; footer üç sütuna ve ortalanmış marka kilidine indi | Müşteri onayı: sayfa daha açık olurken servis şeridi ayrışır; marka paleti ve AA kontrast korunur |
| 2026-08-08 | Beden seti S–M–L ile sınırlandı; 50 varyasyon önce yeni terimlere taşındı, eski terimler sonra kaldırıldı | Müşterinin “şimdilik” talebi ürünleri kırmadan uygulandı; global nitelik modeli ileride yeni terim eklemeye açık kaldı |
| 2026-08-08 | Ana sayfa kategori/kesim indeksi varsayılan kapalıya alındı, panel anahtarı korundu | Önceki onay müşteri tarafından geri çekildi; kodu silmeden ve boşluk bırakmadan geri alınabilir kılındı |
| 2026-08-08 | Müşterinin sekiz PDF hukuk metni sayfalara aktarıldı; köşeli parantezli ticari değerler panel kısa kodlarına bağlandı | Müşteri metinleri yeniden yazılmadı; sipariş bazlı Ön Bilgilendirme Formu WooCommerce sepetinden dinamik dolar |
| 2026-08-08 | Ücretsiz kargo eşiği 4.000 TL oldu; eşik karşılanınca yalnız ücretsiz yöntem gösterilir | Panel tek kaynaktır; WooCommerce tutar hesabı yeniden yazılmadan yalnız sunulan yöntemler filtrelenir |
| 2026-08-06 | Google ile giriş **kapsam eklemesi** olarak kabul edildi; Nextend Social Login kurulur, gizli anahtar depoya hiçbir biçimde girmez, buton anahtar yokken render olmaz | Sosyal giriş ilk sürüm listesinde değildi; müşteri istediği için eklendi. Anahtarın depoda tutulması sızıntı riski, panelde tutulması geri alınabilir bir karardır |
| 2026-08-06 | Duyuru şeridi tek mesaja indirildi; "Kolay değişim" ve "Güvenli ödeme" yalnız ana sayfa servis şeridinde kalır | Aynı iki vaadin iki şeritte tekrarı ekranın en üstündeki tek satırlık alanı harcıyordu |
| 2026-08-06 | Dil seçici duyuru şeridinin **sağ ucunda** mutlak konumlanır; kargo mesajı akışta ortalanır | Üç sütunlu grid'de seçici genişledikçe ortadaki sütunu itip mesajı "kalan alanın ortası"na kaydırıyordu; mutlak konum mesajı sayfanın gerçek ortasında tutar |
| 2026-08-06 | Dil listesi paneldendir ve **tek dil tanımlıyken seçici hiç render olmaz**; ikinci dil bu turda eklenmedi | İngilizce için ücretsiz/ücretli çeviri kararı beklenirken boş bir seçici göstermek yanlış vaat olurdu (§3.5, §31) |
| 2026-08-06 | Palmiye amblemi satır içi SVG olarak gömülür; `fill="currentColor"`, `pt` birimi ve DOCTYPE temizlendi | `<img src="...svg">` ile referanslanan SVG sayfanın rengini devralamaz; koyu hero üstünde beyaz, içerik sayfasında mürekkep rengi gerekiyordu |
| 2026-08-06 | Ödeme sayfası iki kolona alındı; sipariş özeti sağ kolonda **sticky**, mobilde formun üstünde katlanabilir | Jacquemus referansı; uzun formda toplamın ekrandan çıkması alışverişi belirsizleştiriyordu |
| 2026-08-06 | Kupon alanı checkout formunun içinde `apply_coupon` düğmesiyle çalışır; iç içe `<form>` üretilmez | Geçerli HTML ve JS kapalıyken çalışan tek yol; JS açıkken aynı düğme WooCommerce'in `apply_coupon` uç noktasına yönlendirilir |
| 2026-08-06 | Sözleşme onay kilidi ödeme düğmesini pasifleştirmez; `required` + sunucu doğrulaması kullanılır | Pasif düğme hata mesajını bastırıyordu; okunur hata, sessiz engelden üstündür |
| 2026-08-06 | Kupon/indirim/vergi matematiği **yazılmadı, ölçüldü**: `scripts/verify-coupon-allocation.php` satır bazında `_line_subtotal`/`_line_total` farkını kupon tutarıyla karşılaştırır | §17.3 — yanlış fatura riski kozmetik hatadan ağırdır; WooCommerce'in dağıtımı doğrulanır, yeniden uygulanmaz |
| 2026-08-06 | iyzico'nun cüzdan geçidi (`pwi`) seed sırasında kapatılır; checkout'ta tek ödeme yöntemi listelenir | Eklenti dosyasına dokunmadan tek yöntem isteği karşılanır; müşteri isterse ayarlardan geri açabilir |
| 2026-08-06 | `wp-cli` servisinin imaj entrypoint'i devre dışı bırakıldı | Entrypoint `wp help <ilk-argüman>` ile tahmin yürütüp komutu `wp wp …` hâline getirebiliyor ve kurulumu düşürüyordu |
| 2026-08-06 | Nextend etkinleştirildikten sonra yönlendirmesi kurulum betiğinde bilerek bir kez tüketilir | Eklenti `plugins_loaded` üzerinde `wp_redirect(); exit;` çağırıyor; WP-CLI bu noktada süreci sonlandırıp bir sonraki adımı yarıda bırakıyordu |
| 2026-08-06 | Token disiplini ölçümü yorum bloklarını ayıklar; eşik yine 0'dır | Bir kuralın gerekçesini anlatan yorumdaki "760px" alıntısı ihlal sayılmamalı, bildirimdeki ham değer sayılmalı |
| 2026-08-05 | Faz 3F yasal, yardım ve marka metinleri başka siteden kopyalanmadan Kuka Island için sıfırdan üretildi; hukuki sayfalar görünür taslak uyarısıyla kalır | §3.3.1 telif/yanlış beyan riskini ve §10.9 hukuk onayı sınırını korumak |
| 2026-08-05 | Şirket yer tutucuları, ticari değerler, ortak hijyen metni ve üç beden tablosu Site Görünümü + Core kısa kod katmanında tek kaynaktır | Müşteri verisi geldiğinde altı sayfada arama yapmak yerine tek panel kaydıyla tüm yüzeyleri güncellemek |
| 2026-08-05 | Deploy runbook'u Veridyen paneli/SFTP/koşullu SSH, coming soon erişim seçenekleri ve geri dönüş testiyle özelleştirildi; fiili deploy müşteriye bırakıldı | Gizli erişim bilgilerini istemeden test yayınını tekrarlanabilir ve geri alınabilir hazırlamak |
| 2026-08-05 | Ücretsiz kargo eşiğinin tek kaynağı Site Görünümü ticari alanıdır; kayıt sırasında WooCommerce free-shipping instance ayarlarına yazılır | Operatörün tek ekrandan yönettiği duyuru/sepet/checkout değerlerinin ayrışmasını ve tutulmayan ticari vaadi önlemek |
| 2026-08-05 | Header ve ana sayfa kategori indeksi Site Görünümü'ndeki kategori görünürlük tablosundan beslenir; WordPress primary menüsü kullanılmaz | §15.2 navigasyonu panel içeriği sayar; iki menü kaynağını ve seed sonrası farklılaşmayı kaldırmak |
| 2026-08-05 | Katalog döngüsü ürün, varyasyon, meta ve renk terimi cache'lerini toplu ısıtır | Kart sözleşmesini değiştirmeden 12 ürün tabanında sorgu sayısını 264'ten 118'e düşürmek |
| 2026-08-05 | WordPress 7.0.2, WooCommerce 11.0.0, Blocksy/Companion 2.1.51 ve iyzico 3.5.28; Docker imajları digest ile sabittir | Kontrolsüz güncelleme ve iki kurulum arasında yığın farkı oluşmasını önlemek |
| 2026-08-05 | İç URL alanları HTML `url` yerine metin olarak render edilir; sunucu yalnız `/yol/` veya `http(s)` kabul eder | Tarayıcı doğrulamasının geçerli göreli URL'lerle panel kaydını bloke etmesini önlemek |
| 2026-08-05 | Hosting aktarımı `docs/DEPLOY_RUNBOOK.md` adımlarıyla yürütülür | Kod, veritabanı, medya, güvenlik, SMTP, yayın kontrolü ve geri dönüşü tek operasyon belgesine bağlamak |
| 2026-08-04 | Kesim indeksi ile kart swatch/SKU/beden-stok anatomisi kalıcı gereksinimdir | Müşteri iki Faz 1 hipotezini onayladı; veriler WooCommerce taksonomi/varyasyonlarından gelir ve kartın iki yoğun katmanı panelden kapatılabilir |
| 2026-08-04 | Beş dialog yüzeyi tek `storefront.js` erişilebilirlik altyapısını kullanır | İkinci lightbox odak tuzağını kaldırmak; Escape, Tab, inert ve odak dönüşünü tek bakım noktasında tutmak |
| 2026-08-04 | Tüm yan paneller açık `paper` %55 örtü kullanır | Arka plandaki ürün renklerini kaybetmeden panel odağını korumak |
| 2026-08-04 | Günlük panel kullanıcısı Shop Manager, içerik düzenleri iki kilitli Gutenberg desenidir | Teknik olmayan kullanıcının ürün/sipariş/içerik işlerini yaparken tasarım iskeletini bozmamasını sağlamak |
| 2026-08-04 | Üretim geliştirmesinin kanonik deposu `kukaisland-canli`; bu PLAN kanonik kopyadır | Prototip salt okunur referans olarak donduruldu; WordPress/WooCommerce kodu ayrı ve devredilebilir kalır |
| 2026-08-04 | Marka paleti beyaz + bej olarak güncellendi | Logo ölçümündeki `paper #FBF8F2`, `ink #3C2A12` ve sıcak yardımcı renkler AA kontrastla doğrulandı; soğuk prototip paleti üretime taşınmadı |
| 2026-08-04 | Kesim `pa_kesim` global niteliğidir | WooCommerce Attribute Filter bloklarıyla doğal çalışır; kesim landing page'i gerçek liste/SEO değeri gelene kadar açılmaz |
| 2026-08-04 | `big_image_size_threshold` 2000 px | 13 gerçek görselde en uzun kenar 1672 px; 328 px pay bırakıldı, 13 içe aktarmada 0 scaled dosya ölçüldü |
| 2026-08-04 | Checkout mimarisi klasik checkout | iyzico 3.5.28 Blocks ve HPOS bildiriyor; ancak koşullu kurumsal alanlar klasik desteklenen hook'larla daha küçük bakım yüzeyine sahip |
| 2026-08-04 | Ücretsiz kombin adaylarının hiçbiri altı koşulu birlikte karşılamıyor | Grouped Product inline variable seçim sunmadı; WPC/YITH ücretsiz katmanda variable bileşen yok; özel fiyat/stok motoru §17.3 gereği yazılmaz |
| 2026-08-04 | Blocksy Pro zorunlu değil; ölçülen potansiyel tasarruf 45–65 saat | Free varyasyon galerisi gerçek meta sözleşmesiyle çalıştı; swatch/filtre/çekmece/favori/beden rehberi için Pro kararı tablo ve güncel lisans fiyatından sonra verilir |
| 2026-08-03 | WordPress + WooCommerce kullanılacak | Yönetilebilir panel, geniş entegrasyon ekosistemi, sahiplik, iyzico'nun resmî eklentisi |
| 2026-08-03 | Headless (Medusa vb.) elendi | iyzico provider'ı yok; ödeme yolunun kalıcı bakımı geliştiriciye kalır |
| 2026-08-03 | SaaS (ikas/Ticimax/Shopify) elendi | Sabit abonelik gideri, mülkiyet yok, Türkiye'de Shopify Payments yok |
| 2026-08-03 | Yönetilen hosting zorunlu | Sürekli bakım anlaşması olmayacağı için bakım hosting firmasına devredilir |
| 2026-08-03 | Tasarım sıfırdan hazırlanacak | Eski prototip hedeflenen seviyenin temeli olmayacak |
| 2026-08-03 | Jacquemus dili referans, birebir kopya değil | Güçlü moda dili ile hukuki/marka özgünlüğü dengesi |
| 2026-08-03 | Page builder kullanılmayacak | Güncelleme kırılganlığı ve performans |
| 2026-08-03 | Blocksy Pro başlangıçta zorunlu değil | Varyasyon galerisi Free'de mevcut (resmî doküman ile doğrulandı); Pro yalnızca zaman kazandırırsa alınacak |
| 2026-08-03 | Panel hibrit olacak | Kullanım kolaylığı ile tasarım bütünlüğünü birlikte korumak |
| 2026-08-03 | Özel form alanları yalnızca 3–4 alanda | ACF/meta box bir bağımlılık ve bakım kalemi; her yere yayılmaz |
| 2026-08-03 | Önce tasarım sunumu, sonra üretim paneli | Revizyonları erken almak, tekrar işi azaltmak, onay öncesi satın alma yapmamak |
| 2026-08-03 | Renk **varyasyon** olarak modellenecek | Ürün sayfası sayısı ve veri girişi emeği düşer, swatch deneyimi mümkün olur |
| 2026-08-03 | Renk ve Beden **global nitelik** olacak | Swatch ve filtreler yalnızca global niteliklerle çalışır; müşterinin yanlış giriş yapması engellenir |
| 2026-08-03 | Büyük katalogda ürün girişi **CSV içe aktarma** ile | Gerçek ürün/varyasyon sayısı yüksekse elle giriş emek bütçesini tüketir; ayrıca müşteriye devredilebilir akış oluşur |
| 2026-08-03 | Kesim global nitelik/özel taksonomi olacak; seçili kesimler landing page olabilir | 2.1 denetiminde 30+ kesim varsayımı kaldırıldı; ana ürün kategorileri Bikini Üstü/Altı olarak korunur |
| 2026-08-03 | Tek görsel oranı **yalnızca ürün kartlarında** | Banner, editoryal ve video alanları kendi oranlarına ihtiyaç duyar |
| 2026-08-03 | Grid sabit değil, **breakpoint bazlı** | Sabit kolon sayısı bir stil görüşüydü, kural değil |
| 2026-08-03 | Galeri için "eklenti gerekmez" varsayımı kaldırıldı | Standart PhotoSwipe temeli veriyor; sayaç, kontrollü zoom, önden yükleme ve odak yönetimi özel JS işi |
| 2026-08-03 | Görsel eşiği **ölçülerek** ayarlanacak | Kör biçimde kapatmak veya her yerde 4000 px sunmak yanlış |
| 2026-08-03 | Depolama tahmini **varsayımdan ölçüme** çevrildi | İlk 20–30 üründe gerçek dosya boyutu ölçülecek |
| 2026-08-03 | CDN'in depolamayı çözdüğü varsayımı düzeltildi | CDN dağıtımı hızlandırır; orijinaller sunucuda durur |
| 2026-08-03 | Zemin rengi sıcak kremden **soğuk kırık beyaza** | Sıcak krem + serif + terracotta kombinasyonu yaygın bir varsayılan hâline geldi |
| 2026-08-03 | Aksan rengi **hiç kullanılmayacak** | Renk üründen gelir; disiplin imza öğesine yer açar |
| 2026-08-03 | Kartta beden/stok satırı ve ana sayfada kesim indeksi **test hipotezi** | Mobil yoğunluk ve ticari etkisi prototipte doğrulanmadan kalıcı gereksinim sayılmaz |
| 2026-08-03 | Alan adı, hosting ve lisanslar **müşteri adına** kaydedilecek | Lisans geliştirici hesabına bağlı kalırsa müşteri devirden sonra bağımlı kalır |
| 2026-08-03 | Hijyen istisnası koruyucu unsur ve hukuk onayına bağlandı | Mayo/iç giyimde istisna otomatik değildir; güncel mevzuat ve operasyon birlikte doğrulanır |
| 2026-08-03 | Emek tahmini 92–158 saat bandına revize edildi | Sepet/checkout uyarlaması ve CSV/pilot veri için ayrı kalem açıldı |
| 2026-08-03 | Müşteri sorularına "bloke ettiği faz" kolonu eklendi | Hangi cevabın hangi işi durdurduğu belirsizdi |
| 2026-08-03 | Teknoloji yığını ayrı bölüm olarak yazıldı (§4) | Kullanılacak her teknolojinin ve rolünün tek yerde görünmesi |
| 2026-08-03 | Plan 2.1 mimari denetiminden geçti | Blocksy/Site Editor çelişkisi, bakım politikası, iyzico pilotu, hukuki kesinlikler ve bütçe sınırı düzeltildi |
| 2026-08-03 | 30+ kesim kesin varsayımı kaldırıldı | Gerçek katalog bilinmeden menü, kategori ve tasarım imzası kilitlenmemeli |
| 2026-08-03 | Kesim indeksi ve kart stok satırı hipoteze çevrildi | Mobil yoğunluk ve ticari etkisi prototipte doğrulanmalı |
| 2026-08-03 | Agentic checkout ifadesi düzeltildi | WooCommerce MCP/Abilities developer preview; hazır iyzico agentic ödeme garantisi yok |
| 2026-08-03 | Ticari emek üst sınırı 120 saat olarak tanımlandı | 92–158 saat risk aralığı ₺35–40 bin dahil bütçeyle sınırsız kabul edilemez |
| 2026-08-03 | Faz 1 prototipi ayrı ve veri odaklı bir web projesi olarak başlatıldı | Tasarım onayını WordPress maliyeti ve tema kısıtlarından önce almak; sonradan panel verisine bağlanacak bileşen sınırlarını erkenden kurmak |
| 2026-08-03 | İlk görsel keşifte özgün, modelsiz ürün natürmortları kullanılacak | Gerçek marka fotoğrafları gelene kadar telifli referans görsellere bağımlı kalmamak; görsel oran ve renk sistemini gerçek medya ile sınamak |
| 2026-08-03 | Faz 1 prototipi **Next.js + Cloudflare Workers** yığınında yürüyecek; üretim WooCommerce kalacak | Görsel onay hızlı ve ücretsiz ortamda alınır, onay öncesi hosting/lisans satın alınmaz. Port maliyeti §16.5 kurallarıyla sınırlanır |
| 2026-08-03 | Marka adı **Kuka Island** olarak teyit edildi | Wordmark ve SKU formatı buna bağlıydı |
| 2026-08-03 | Logo yok; tipografik wordmark placeholder kullanılacak | Müşteri logoyu sonraya bıraktı; panelden değiştirilebilir kalır |
| 2026-08-03 | Renk kısıtı yok; §11.2 paleti uygulanacak | Müşteri zorunlu veya yasak renk bildirmedi; beğenilmezse revizyon turunda değişir |
| 2026-08-03 | Ana sayfada **video yok**, yalnızca fotoğraf | Müşteri kararı; hero video alanı ilk sürüm dışına alındı |
| 2026-08-03 | **Takım + ayrı parça ikisi de** satılacak, karışık beden serbest | Müşteri kararı; takım modeli koşullu olmaktan çıkıp kapsama girdi |
| 2026-08-03 | Takım modeli varsayılanı **"Takımı tamamla" özel UI** | Ücretsiz, karışık beden doğal çalışır, sepet/checkout akışına dokunmaz. Set indirimi zorunlu olursa bundle eklentisine geçilir (§13.1.1) |
| 2026-08-03 | Beden: **tek global nitelik, ürün bazlı terim alt kümesi** | Müşteri hem XS–XL hem 34–42 istedi ve ürün bazlı ayar talep etti; global nitelik kuralı bozulmadan karşılanır (§13.4.1) |
| 2026-08-03 | Açılış kataloğu **~150 parça**; CSV içe aktarma zorunlu hâle geldi | Bu ölçekte elle giriş emek bütçesini tüketir; ilk sürüme yalnızca 3–5 pilot ürün dahil |
| 2026-08-03 | Kesim, renk, fiyat, kargo ve ücretsiz kargo limiti **kodda sabitlenmeyecek** | Hepsi teslim sonrası belli olacak; panelden yönetilen taksonomi ve ayar olarak kurulur |
| 2026-08-03 | Referans sitelerin **görselleri ve yasal metinleri kullanılmayacak** | Telif ihlali; kopyalanan yasal metin Kuka Island için geçersiz olur ve başka şirketin unvan/VKN/ETBİS verisini yayınlar. iyzico incelemesinde de sorun çıkarır (§3.3.1) |
| 2026-08-03 | **Favoriler** ilk sürüme dahil; **ürün yorumları** ilk sürüm dışı | Müşteri kararı |
| 2026-08-03 | Şirket türü **şahıs**, vergi levhası alındı, iyzico başvurusu paralel yürüyor | Faz 5 önkoşulları ilerliyor |
| 2026-08-03 | Alan adı ve hosting **tasarım onayından sonra müşteri adına** alınacak | §17.4 sahiplik kuralı ve §27.3 satın alma sırasıyla uyumlu |
| 2026-08-03 | 10 günlük hedef **Faz 1 sunumu** olarak tanımlandı | iyzico onayı, ETBİS, katalog verisi ve fotoğraflar geliştirme hızından bağımsız; canlı mağaza 10 güne sığmaz (§29.2) |
| 2026-08-03 | Commit kuralları yazıldı: kısa mesaj, araç/asistan imzası yok | Geçmişin okunabilir kalması ve mesajın yalnızca yapılan işi anlatması (§16.2.1) |
| 2026-08-03 | Prototip token katmanı `app/tokens.css` içinde ayrıldı | §11.4 ve §16.5 gereği renk, boşluk, tipografi, oran, hareket ve katman kararlarını WordPress child theme'e taşınabilir tutmak |
| 2026-08-03 | Demo katalog renk × beden varyasyon modeline geçirildi | Renge göre SKU, stok ve galeri değişimini WooCommerce ürün/varyasyon yapısına yakın biçimde test etmek |
| 2026-08-03 | Etkileşimler DOM tabanlı ortak kontrol katmanına taşındı | Panel, kart galerisi, renk seçimi ve karşılaştırma davranışını React state'ine bağlamadan üretim temasına taşınabilir tutmak (§16.5) |
| 2026-08-03 | Panel erişilebilirliği prototip kabul koşulu oldu | Escape, odak tuzağı, odağı geri verme, `inert`, görünürlük ve semantik dialog davranışı olmadan mobil menü/sepet tamamlanmış sayılmaz |
| 2026-08-03 | Mobil hero ve her demo ürün için ayrı medya seti kullanılacak | Ağır masaüstü kırpmasını mobilde kullanmamak; ayrı satılan üst/alt ürün anlatısını kartlarda bozmamak |
| 2026-08-04 | Prototip görsel hattı responsive `srcset` + optimize uç nokta olarak sabitlendi | Mobil hero ve kart etkileşimlerinin ham orijinale dönmesini engellemek; Cloudflare yayında genişlik/format dönüşümü yaparken yerel ortam güvenli passthrough kullanır |
| 2026-08-04 | Kategori sayfaları 24 ürünle sunucu tarafında sayfalanacak; kart verisi tek JSON yükü olacak | Yaklaşık 150 ürünün tamamını ve yinelenen varyasyon JSON'unu ilk HTML'e gömmemek; katalog büyürken aktarım maliyetini sınırlamak |
| 2026-08-04 | Beş kategori/koleksiyon URL'i paylaşılan şablonla açıldı | `/bikini-ustleri`, `/bikini-altlari`, `/mayolar`, `/plaj-giyim`, `/koleksiyon/{slug}` tek `CategoryShell` üzerinden çalışır; kesim bazlı landing page açılmadı (§8.1) |
| 2026-08-04 | Katalog filtre/sıralama/yoğunluk/sayfalama DOM tabanlı yazıldı | React state'ine gömmeden üretim temasına taşınabilir tutmak (§16.5 kural 3); `lib/catalog-interactions.ts` URL ile senkron çalışır |
| 2026-08-04 | Filtreler yalnızca çekmecedir; masaüstünde sol kolon yok | Geniş grid alanı korunur; tek tutarlı etkileşim tüm kırılımlarda |
| 2026-08-04 | Sayfalama klasik (URL `?sayfa=N`, 12/sayfa) seçildi | SEO için taranabilir (§10.2); "daha fazla yükle" ve sonsuz kaydırma elendi |
| 2026-08-04 | Grid yoğunluğu `data-density` attribute üzerinden CSS ile ayarlanır, inline style değil | Mobil medya sorgusu inline style tarafından geçersiz kılınmasın; density ne olursa olsun mobilde 2 sütun korunur (§12) |
| 2026-08-04 | Panelden yönetilecek site içeriğinin Faz 4 alan taslağı `data/site-content.ts` içinde §15.2 başlıklarıyla merkezileştirildi | Duyuru, hero, ana sayfa bölümleri, menüler, footer ve ticari mesajların JSX içinde ikinci kopyasını önlemek; boş/uzun/kapalı içerik fallback'lerini erken doğrulamak |
| 2026-08-04 | Sepet matematiği saf `lib/cart-model.ts`, kalıcılık ve render ise `lib/cart-interactions.ts` sınırında tutulur | Stok üst sınırı, varyasyon anahtarı, toplam ve ücretsiz kargo hesabını tarayıcısız test etmek; DOM davranışını React state'ine bağlamadan taşınabilir tutmak (§16.5) |
| 2026-08-04 | Faz 1 sepeti boş depoda iki demo satırla tohumlanır; checkout ödeme çağrısı yapmaz | Müşteri sunumunda dolu sepet yönünü göstermek ve prototipin gerçek ticari işlem yaptığı izlenimini açık notlarla engellemek |
| 2026-08-04 | Filtre çekmecesi mevcut `storefront-interactions.ts` panel altyapısına DOM sözleşmesiyle bağlandı | Kod kopyalanmadı; `data-panel-trigger`/`role="dialog"`/`inert` attribute'ları mevcut Escape, odak tuzağı ve odağı geri verme davranışını otomatik bağlar (§23) |
| 2026-08-04 | Kesimler koda gömülmedi, demo veri katmanından türetildi | Müşteri kesim listesini teslim sonrası sağlayacak (§5 soru 4); filtre ve grid 5 ile 40 kesim arasında bozulmadan çalışır |
| 2026-08-04 | Ürün detay galerisi masaüstünde tek büyük editoryal sütun olacak | Mevcut ürünlerde yalnızca iki fotoğraf var; iki kolonlu düzen az görselde boşluk üretirken tek sütun 2–7+ fotoğrafı aynı bileşenle dengeli taşır. Mobilde yatay scroll-snap, sayaç ve oklar kullanılır |
| 2026-08-04 | Mobil ürün detayında sepete ekle eylemi ekran altında `sticky` tutulacak | Uzun galeri ve akordiyonlarda ana eylem erişilebilir kalır; düğme beden seçilene kadar pasiftir ve sayfayı kaplayan sabit bir katman oluşturmaz |
| 2026-08-04 | Lightbox büyük görseli yalnızca kullanıcı açtığında oluşturup yükleyecek | İlk HTML'de yüksek çözünürlüklü lightbox görseli bulunmaz; gereksiz ağ tüketimi önlenir. Ok tuşları, Escape, odak tuzağı, fare tekeri/çift tıklama ve dokunmatik pinch DOM etkileşim katmanında çalışır |
| 2026-08-04 | Ürün detay etkileşimleri `lib/product-interactions.ts` içinde DOM tabanlı kalacak | Renk, SKU, fiyat, stok, galeri, iki ayrı bedenli takım ve lightbox davranışları React state'ine bağlanmadan WooCommerce temasına taşınabilir |
| 2026-08-04 | Cloudflare `IMAGES` bağlayıcısı örtük varsayılmayacak; her hedef ortamda açıkça doğrulanacak | Cloudflare Workers yapılandırması `images.binding` ister; vinext'in standart üretim yapılandırması da `images: { binding: "IMAGES" }` üretir. Yerel Vite yapılandırmasına bağlayıcı eklendi. `.openai/hosting.json` şemasında `IMAGES` alanı bulunmadığından Sites yayını öncesi gerçek optimize URL smoke testi zorunludur; bağlayıcı yoksa uygulama teşhis başlığı ve tek seferlik uyarı verir ([Cloudflare Images binding](https://developers.cloudflare.com/images/optimization/binding/), [vinext](https://github.com/cloudflare/vinext)) |
| 2026-08-04 | Yedi fotoğraflı ürün kabulü gerçek medya gelene kadar açık veri kabulü olarak kalacak | `data/catalog.ts` içindeki dört demo ürünün her birinde iki galeri görseli var ve bu turda veri dosyasını değiştirmek yasaktı. Galeri fotoğraf sayısından bağımsız yazıldı ve iki görselde doğrulandı; gerçek 7 görselli medya seti geldiğinde aynı test 7 öğeyle çalıştırılacak |
| 2026-08-04 | Prototip şema URL'leri gerçek alan adı verilene kadar `.example` kullanacak | `Product`, `Offer` ve `BreadcrumbList` üretilir; sahte puan/yorum üretilmez. Canonical ve şema URL'leri canlı alan adı alındığında tek merkezden değiştirilecek |
| 2026-08-04 | Katalog filtreleme, sıralama ve 12 ürünlük sayfalama sunucu katmanına taşındı | GET formları ve düz bağlantılar JS kapalıyken de ayrı sunucu yanıtları üretir; facet sayıları seçenekleri korumak için filtrelenmemiş kaynak kümeden hesaplanır |
| 2026-08-04 | Sayfa numarası `/page/2/` yerine `?sayfa=2` olarak kalacak | Beş kategori/koleksiyon için ek route iskeleti yalnızca URL kozmetiği sağlar; query URL'i de sunucu render'lı, ayrı ve kendine canonical verilebilir. Filtre veya sıralama değişince `sayfa` düşürülür |
| 2026-08-04 | `StorefrontRuntime` pathname değişiminde temizlenip yeniden bağlanacak | Kök layout istemci gezinmesinde yeniden mount olmadığı için element bazlı panel/kart dinleyicileri yeni rotanın DOM'una pathname bağımlı effect ile bağlanır |
| 2026-08-04 | Katalog kart wrapper'larındaki filtre `data-*` kopyaları kaldırıldı | Sonuç kümesi artık sunucuda hesaplandığından `data-cut`, `data-colors`, `data-sizes`, `data-price` ve `data-stock` gereksizdir; kartın kendi etkileşim verisi korunur |
| 2026-08-04 | Katalog ve ürün detay sayfaları aynı `allDemoProducts` kaynağını kullanacak | Kategori kartlarının yalnızca ilk dört ürününün detay sayfasına ulaşması engellendi. `generateStaticParams`, metadata, sayfa gövdesi ve ilişkili ürün çözümleme yalnızca `publish` durumundaki 21 ürünü ortak kaynaktan okur |
| 2026-08-04 | Ürün tipi → kategori kimliği ve adı eşlemesi exhaustive tek tabloda tutulacak | `bikini-top`, `bikini-bottom`, `one-piece` ve `beachwear` hem görünür breadcrumb hem JSON-LD için aynı kaynağı kullanır. Yeni bir `ProductType` eşleme eklenmeden tanımlanırsa TypeScript derlemeyi reddeder |
| 2026-08-04 | Sticky ürün bilgi paneli header yüksekliği + 16 px boşlukla konumlanacak | Masaüstü tarayıcı ölçümünde sabit header altı 64 px, panel üstü 80 px ve aradaki boşluk 16 px'tir. Mobilde panel statiktir; görünür başlangıç noktası 61 px ile 56 px header'ın altında kalmaz |
| 2026-08-04 | Kısa ve uzun galeri testi mevcut görsellerle gerçek veri üzerinde korunacak | İki fotoğraflı ürün yanında dört fotoğraflı bir demo varyasyon seti oluşturuldu; yeni medya üretilmedi. Her iki sayfa boş galeri yuvası olmadan render edilir; gerçek yedi fotoğraflı müşteri seti ayrıca beklenir |
| 2026-08-04 | Yeni gelenler ve Bikini üst kategorisi mevcut `CategoryShell` ile sunucu tarafında filtrelenir, sıralanır ve sayfalanır | Yeni navigasyon rotalarında mevcut katalog davranışının ve JS'siz gezinmenin ayrışmasını önlemek |
| 2026-08-04 | İçerik, yardım, yasal, marka ve hesap sayfalarının metni `data/catalog-copy.ts` veri sözleşmesinden okunur | WordPress/panel aktarımında JSX içinde string aramayı önlemek |
| 2026-08-04 | Yasal metinler prototip taslağıdır; şirket bilgi yer tutucuları hukuk onayına kadar görünür kalır | Onaysız metni yürürlükte göstermemek ve başka marka verisi kullanmamak |
| 2026-08-04 | Hijyen istisnası, koruyucu unsur açıldığında uygulanabilecek koşullu değerlendirme olarak anlatılır | §20.2 ve ürün detayındaki uyarıyla tutarlılık |
| 2026-08-04 | Beden rehberi 34–42 ve XS–XL sistemlerini üç ayrı veri tablosunda gösterir | Ürün gruplarının farklı ölçülerini ve panelden düzenlenebilirliği korumak |
| 2026-08-04 | Hesap, iletişim ve sipariş takibi ekranları görsel prototip olarak kalır | Gerçek servis mantığını WooCommerce ve seçilecek servis katmanına bırakmak |
| 2026-08-04 | `app/content.css` mevcut tasarım token'larını kullanır | Tasarım sistemi tek doğruluk kaynağını korumak |
| 2026-08-04 | Sunum erişimi için Sites `custom` e-posta izin listesi önerilir; ortam değişkenli HTTP Basic Auth yedektir | Önerilen yolda kodda sır tutmamak; gerektiğinde statik varlıkları da koruyan varsayılan-kapalı seçenek sunmak |
| 2026-08-04 | Görsel optimizasyon `X-Image-Optimization: enabled/disabled` ile teşhis edilir | Yayın sonrası WebP ve boyut kontrolünü ölçülebilir kılmak |
| 2026-08-04 | Faz 1 müşteri sunumu ayrı rehber, sınırlamalar, yayın ve fotoğraf belgeleriyle kapanır | Tasarım onayını demo veri ve sonraki faz işlerinden ayırmak |
| 2026-08-04 | İkincil metin token'ı `#616560`, koyu zemin ikincil metni `#8b8e8a` olacak | Ölçülen en düşük oranlar paper 5.14:1, mist 4.72:1 ve ink 5.37:1; küçük metinde WCAG AA sınırı korunur |
| 2026-08-04 | Görünür klavye odağı, skip link ve reduced-motion kuralı global erişilebilirlik sözleşmesidir | 13 kritik rotada tek giriş noktası; dört panelde Escape, odak tuzağı, odak dönüşü ve inert davranışı aynı katmanda doğrulandı |
| 2026-08-04 | Renk, ikon, beden, adet ve onay etkileşimlerinin etkili mobil hedefi en az 40×40 px olacak | 390px'te beş kritik ekranın görünen kontrollerinde 40px altına düşen etkili hedef ölçülmedi; renk noktalarının görsel çapı değişmedi |
| 2026-08-04 | Mağaza bağlantıları açılışta otomatik RSC prefetch yapmayacak | Üretim ana sayfasındaki gereksiz RSC isteği 15'ten 0'a indi; istemci tarafı gezinme korunurken mobil ilk yük sınırlandı |
| 2026-08-04 | Parça 9 performans tabanı ölçüm raporuyla korunacak | `w=640` demo görseller toplam 25.8× küçüldü; altı şablonun soğuk aktarımı 267.1–358.2 KiB ölçüldü. 11 self-hosted font preload'u 146,464 B maliyet olarak kaydedildi |
| 2026-08-04 | Safari, Firefox, iOS Safari, Android Chrome ve gerçek cihaz CWV turu açık kabul maddesidir | Chrome ve Chromium tabanlı Codex tarayıcıda 208 responsive kontrol geçti; erişilemeyen motorlar ve throttling sonucu geçmiş sayılmadı |
| 2026-08-04 | Filtre seçenekleri gerçek `radio`/`checkbox` kontrollerini koruyan responsive chip grid olarak sunulacak | JS'siz GET formu ve klavye/ekran okuyucu erişimi korunurken beden 5→3, renk 3→2, kesim 2 kolonla daha hızlı taranır |
| 2026-08-04 | Kesim, renk, beden ve fiyat grupları doğal `<details>`/`<summary>` akordiyonu olacak | JS akordiyonu eklemeden klavye davranışı sağlanır; yalnız aktif filtresi olan grup sunucu yanıtında açık gelir |
| 2026-08-04 | Filtre çekmecesi `--filter-panel-width: 640px`, sepet ve menü `--panel-width: 480px` kullanacak | Chip grid masaüstünde nefes alır; mevcut sepet ve navigasyon oranları değişmez, mobilde filtre paneli tam genişlik olur |
| 2026-08-04 | Açık `paper` örtü yalnız açık filtre paneline uygulanacak; diğer paneller koyu örtüyü koruyacak | Referansın sönük katalog bağlamı Kuka paletine uyarlanır; `border-left` panel sınırını korur ve `:has()` ile değişiklik filtreye izole edilir |
| 2026-08-04 | Ürün şeması WooCommerce'in tek üreticisine bırakıldı; child theme yalnız review/aggregateRating alanlarını kaldırır | Product, Offer ve BreadcrumbList'in birer kez çıkması ve ikinci şema üreticisi oluşmaması için |
| 2026-08-04 | WooCommerce override bütçesi yalnız `content-product.php` ile kapatıldı | Ürün detay, galeri, sepet ve checkout hook/CSS ile karşılandığından yükseltme maliyetini sınırlamak için |
| 2026-08-04 | i18n URL hazırlığı gelecekte `/en/` ön eki varsayımıdır; bu fazda çoklu dil eklentisi kurulmaz | Text domain ve POT hazırlığını yayın/routing kararından ayırmak için |
| 2026-08-04 | Kesim indeksi, kart stok/beden satırı ve Pro'ya bağımlı beş bileşen üretim temasına taşınmadı | Onaylanmayan hipotezleri ve lisans gerektiren davranışları custom kodla taklit etmemek için |
| 2026-08-04 | Faz 3B'de prototip header/footer, editoryal ürün kartı, filtre çekmecesi, swatch ve özel ürün galerisi child theme bileşenlerine taşındı | Faz 3A'nın palette kalan ince CSS katmanını gerçek DOM ve etkileşim sözleşmesine dönüştürmek için |
| 2026-08-04 | WooCommerce override bütçesi iki dosya olarak kapatıldı | Kart anatomisi hook'larla güvenilir kurulamadı; Blocksy de ürün galeri template'ini WooCommerce'den önce bastırdığı için yalnız kart ve galeri dar override edildi |
| 2026-08-04 | Renk swatch'ı ve filtre çekmecesi Blocksy Pro alınmadan geliştirildi | Faz 3B kapsamı bunları mayo mağazası için vazgeçilmez kabul etti; native select/GET input ve WooCommerce sorgusu tek doğruluk kaynağı olarak korundu |
| 2026-08-04 | iyzico ödeme alanı korunup yalnız page-overlay promosyonu dar child CSS seçicisiyle gizlendi | Eklentide kapatma ayarı bulunmadı; sabit promosyon galeri ve checkout alanlarını örtüyordu |
| 2026-08-04 | Favoriler, off-canvas sepet, beden modalı, mega menü, kesim indeksi ve kart beden/stok satırı Faz 3B dışında kaldı | Ayrı veri/senkronizasyon veya onaylanmamış tasarım kararı gerektiren işleri bu aktarım turuna gizlice eklememek için |
| 2026-08-04 | Özel sepet çekmecesi §29.1 kesmesinden Faz 3C kapsamına geri alındı | Kullanıcı bu tur için 12–18 saatlik geliştirme payını açıkça kapsama aldı; WooCommerce fragment'ları, çekirdek sepet form işleyicisi ve JS'siz `/sepet` akışı korunarak ayrı sepet motoru yazılmadı |
| 2026-08-04 | Hesabım sayfası korunup §10.7'ye sağdan açılan hesap paneli ek yüzey olarak eklendi | Header hesabına hızlı erişim sağlanırken WooCommerce'in nonce'lı giriş/kayıt/çıkış akışı tek doğruluk kaynağı kaldı; JS kapalı bağlantı `/hesabim` sayfasına gider |
| 2026-08-04 | Mobil menü soldan, sepet ve hesap panelleri sağdan açılır | Navigasyon başlangıç yönünde; header'ın sağındaki kişisel/ticari eylemler kendi fiziksel konumlarından gelir. Dördü aynı Escape, odak tuzağı, odak iadesi, `inert` ve örtü altyapısını paylaşır |
| 2026-08-04 | Filtre örtüsü `paper` %55, diğer panel örtüleri `ink` %55 karışımıdır | Açık filtre bağlamındaki ürün renkleri `filter:none` ile korunur; koyu örtü yalnız menü, hesap ve sepet odağında kalır |
| 2026-08-04 | Yumuşatma semantik hareket ve odak token'larıyla yapılır | `--duration-micro` mevcut 240 ms'e, `--duration-panel` mevcut 420 ms'e, `--duration-image` mevcut 240 ms'e ve `--focus-color` mevcut `ink-soft`a bağlandı; gölge, radius veya yeni sayısal değer eklenmedi |
| 2026-08-06 | Şirket bilgileri girildi: satıcı Kübra Gültekin (şahıs işletmesi), VKN 4220658128, Beşiktaş VD, Akat Mah. adresi, iletişim telefonu +90 530 948 19 96 | Müşteri vergi levhasından geldi; tek kaynak Site Görünümü → Şirket ve Yasal; panelden düzenlenebilir |
| 2026-08-06 | TC Kimlik No bilinçli olarak hiçbir yere girilmedi ve yayınlanmıyor | Mesafeli satış sözleşmesi için gerekmiyor; VKN ayrı bir numara olarak yeterli; işletme sahibinin kimlik verisini açığa çıkarmak KVKW maruziyeti yaratır ve müşterimizi korumak zorundayız |
| 2026-08-06 | MERSİS numarası alanı ve şablon satırı kaldırıldı | Şahıs işletmeleri MERSİS numarası taşımaz; boş yer tutucu göstermek yerine alan kapatıldı |
| 2026-08-06 | "Şirket unvanı" etiketi yerine "Satıcı / unvan" ve ek "İşletme adı" satırı kullanıldı | Şahıs işletmesinde ticaret ünvanı yoktur; satıcı gerçek kişi adıdır, işletme adı Kuka Island ayrı satırda |
| 2026-08-06 | WhatsApp panel alanı URL'den telefon numarasına çevrildi; wa.me bağlantısı koddan üretiliyor | Müşteri yalnızca numara girer; boşluk/parantez/tire temizlenir, baştaki 0 → 90, +90 kabul edilir. 0530 948 19 96 → https://wa.me/905309481996. Boşken WhatsApp arayüzü hiç görünmez |
| 2026-08-06 | Yüzen WhatsApp düğmesi sağ altta sabit, marka `ink` renginde; checkout ve ödeme sayfasında gizli | iyzico yüzen promosyonunun yerine geçmesin diye sade ve küçük; gölge/büyük radius yok (§11.1); WhatsApp yeşili kullanılmadı (§11.2) |
| 2026-08-06 | Servis şeridi yeniden tasarlandı: mono numara + başlık + açıklama + sağ ok; hücre tamamı tıklanabilir; başlık/açıklama/bağlantı panelden | Prototipteki anatomi geri getirildi; üçüncü hücre WhatsApp'a, boşsa /iletisim'e düşer. Açıklamalar panelden okunur (§15.2), uydurma bilgi yok |
| 2026-08-06 | Sepet sayfası düzeni düzeltildi: ölçü `content` → `wide`; Woo tablosu sol, `.cart_totals` sağ kolon grid; kolonlara min-width ve nowrap; kupon + "Sepeti güncelle" tek satır grid; mobilde tablo kart düzenine döner | Başlıklar ve fiyatlar sarmıyordu; güncelle düğmesi satır dışına taşmıştı; 2000px'te içerik sıkışıyordu |
| 2026-08-06 | Sepet satırında ürün adı parent adına indirildi; renk/beden `dl.variation` ile ayrı satırda "Renk: x · Beden: y" okunur | Varyasyon takısı üründen adından kopuk kırılıyordu; `woocommerce_cart_item_name` parent adını, `woocommerce_after_cart_item_name` ise meta DL'ini basar (Woo'nun kendi formatlayıcısı bu mağazada boş dönüyor) |
| 2026-08-06 | Tüm form kontrolleri `accent-color: var(--color-ink)` token'a çekildi; footer/hero koyu zemininde `--color-white`; odak halkası da `--color-ink` | §11.2 aksan yok kuralı ihlal ediliyordu; tarayıcı/Blocksy varsayılan mavi radio/checkbox kazanmıştı |
| 2026-08-06 | SSS soruları `<details>`/`<summary>` akordiyona çevrildi; grup başlıkları h2 olarak kaldı; varsayılan hepsi kapalı | JS'siz açılır/kapanır, klavyeyle çalışır; `prefers-reduced-motion` altında chevron geçişi global olarak sıfırlanır |
| 2026-08-09 | Faz 6B değişiklik ölçeği yalnız `/hakkimizda/` sanat katmanı, hikâye panelinin üç sunum alanı, lisanslı hikâye medyası ve bunların seed/QA belgeleriyle sınırlandı | Faz 6A `IntersectionObserver`, sticky sahne, mobil/JS'siz/reduced-motion düz makale, iki dil metni ve genel site bileşenleri değişmeden kalsın; animasyon kütüphanesi, vendor, deploy ve canlı anahtar eklenmesin |
| 2026-08-10 | Footer ödeme logoları müşteri isteğiyle kaldırıldı; iyzico şartı ödeme sayfasındaki eklenti şeridiyle karşılanmaya devam ediyor | Başvuru onaylandı; footer yalnız marka kilidi ve telif satırını taşır, eklentinin `cards_v2.png` çıktısı checkout'ta korunur |
| 2026-08-10 | İçerik belgesi `h2` başlıkları yeni `--text-heading-document` token'ına ayrıldı | Global `--text-heading-medium` tema genelinde katalog, sepet, checkout ve hikâyede kullanılıyor; onu değiştirmek yerine yalnız `.kuka-prose h2` 1440'ta 43,2 px'ten 28,8 px'e indirilir |
| 2026-08-10 | Site ve işlemsel gönderici e-postasının tek kaynağı `brand.email=info@kukaisland.com` oldu | `wp_mail_from`, WooCommerce gönderici filtresi, SMTP From ve footer aynı panel/seed değerini okur; hukuk belgelerindeki eski adres hukuk danışmanı kararı olmadan değiştirilmez |
| 2026-08-10 | Footer Sosyal sütunundan site e-postası kaldırıldı; yalnız Instagram ve WhatsApp kaldı | Müşteri görsel geri bildirimi önceki footer görünürlüğü kararını geçersiz kıldı; `brand.email`, iletişim/yasal yüzeyler ve WordPress/WooCommerce gönderici kaynağı değişmedi |
| 2026-08-11 | Ücretsiz kargo yöntemi `minimum tutar veya ücretsiz kargo kuponu` koşuluna geçirildi | Kupon WooCommerce tarafından kabul edilmesine rağmen eşik-altı sepette yöntem `min_amount` ile kilitli kaldığı için 149 TL sabit ücret düşmüyordu; geçerli ücretsiz kargo kuponu artık ücretsiz yöntemi açar ve ücretli yöntemi gizler |
| 2026-08-11 | Ürün kartı fiyatı WooCommerce'in `get_price_html()` çıktısına bağlandı | Değişken ürünlerde parent indirim alanları boş olduğu halde doğrudan biçimlendirilince `0 TL` üretiliyordu; kart artık varyasyonların gerçek minimum/aralık fiyatını kullanır |
| 2026-08-24 | Bülten kaydı çift onaya geçirildi; ilk KVKK kanıtı değişmez kayıt olarak korunur | Yeniden kayıt yalnız doğrulama tokenını yeniler; ilk metin/tarih/IP ezilmez, 48 saatlik HMAC token doğrulanmadan kayıt `confirmed` olmaz ve IP başına 10 dakikada 5 istek sınırı uygulanır |
| 2026-08-24 | WooCommerce checkout ve katalog orta bulguları birlikte kapatıldı | Mobil toplam AJAX sonrası eşlenir; telefon boş kalabilir; kurumsal alanlar yalnız kurumsal seçimde görünür/zorunludur; içerik, shortcode ve varyasyon önbellekleri istek içinde hazırlanır; sepet fragment isteği yalnız sepet mutasyonunda yapılır |
| 2026-08-24 | Kurulum sırları STDIN sınırına, GitHub Actions salt-okunur ve SHA-sabit bağımlılıklara alındı | Gerçek yönetici/mağaza yöneticisi parolaları süreç argümanında veya çıktıda bulunmaz; workflow `contents: read` taşır ve iki üçüncü taraf action tam commit SHA'sına sabitlenir |

---

## 39. Mevcut durum

- [x] Dokuz orta bulgu kapatıldı: bülten çift onay/kanıt koruma/IP limiti; checkout toplam/şirket/telefon; içerik ve ürün önbelleği; isteğe bağlı cart fragment; STDIN parola aktarımı; SHA-sabit ve salt-okunur CI; son kodla iki bağımsız temiz `reset+verify` `2/2 PASS`, smoke `5/5`
- [x] Orta bulgu ölçümü: bülten `pending→confirmed`, token özeti `64` karakter ve doğrulamada temiz; kurumsal alan görünür+zorunlu, bireyselde gizli+zorunlu değil; isteğe bağlı telefon değeri boş; ana sayfa eager cart-fragment `0`
- [x] Katalog sorgu ölçümü: `4` ürün ve `24` varyasyon için priming sonrası `QUERIES_COLD=12`, aynı istek sıcak tur `QUERIES_WARM=0`; parola argv `0`, mutable Action `0`, SHA-sabit Action `2`, workflow permissions bloğu `1`
- [x] Güvenlik sürüm hattı WordPress `7.0.4`, WooCommerce `11.0.1`, Blocksy/Companion `2.1.53`, Loginizer `2.1.0` olarak sabitlendi; CSP, `nosniff`, Referrer, frame, Permissions Policy, üretim HTTPS HSTS ve RFC 9116 güvenlik iletişim uç noktası Core'a eklendi
- [x] Checkout odak/onay regresyonu kapatıldı: gerçek tarayıcıda ilk hata odağı `billing_first_name`; `Y→Yasir` boyunca odak aynı alanda ve tamamlanan alanın hata/ARIA'sı temiz; `update_order_review` sonrasında iki yasal onay `2/2` işaretli kaldı
- [x] Dil sıcak yolu statik önbellekli: istek dili aynı istek imzasında bir kez çözülür, yaklaşık 230 girdili Core ve 14 girdili tema gettext haritaları yalnız ilk ilgili çağrıda kurulur
- [x] Yerel OPcache `192 MB`, interned strings `16 MB`, dosya eşiği `20.000`; Redis/LiteSpeed/InnoDB canlı değeri doğrulanmadığı için `docs/DEPLOY_RUNBOOK.md` §11 performans kapısında açık ölçüm olarak tutulur
- [x] Bu regresyon turunda iki bağımsız temiz `make reset && make verify` sonucu `2/2 PASS`; her turda smoke `5/5`, ham renk `0`, ham px `0`, tanımsız token `0`
- [x] Ana sayfa hero aynı `25,000 sn` responsive video çiftini arka planda oynatıyor; viewport kaynağı JS ile seçiliyor, mevcut posterler ve `prefers-reduced-motion`/`saveData` indirmeme kapısı, başlık-kopya-buton katmanı ve iki dil korunuyor
- [x] “Çok yakında” video ekranı: ilk `25,000 sn`; masaüstü `1920×1080 / 4.542.803 bayt`, mobil `720×1280 / 2.213.198 bayt`, ses izi `0/0`; toplam video aktarımı `24.565.601 → 6.756.001 bayt` (`%72,5` azalma), responsive poster ve `by Kübra Gültekin` katmanı tamamlandı
- [x] Değişken ürün kartı fiyat düzeltmesi: minimum varyasyon `1,00 TL`; TR `₺1/₺0 = 1/0`, EN `₺1/₺0 = 1/0`; geçici denetim ürünü test sonunda silinir
- [x] Ücretsiz kargo kuponu düzeltmesi: 2.890 TL eşik-altı sepette TR `149→0 TL`, EN `149→0 TL`; iki dilde toplam `3.039→2.890 TL`, yalnız ücretsiz yöntem görünür ve ilerleme metni hazır durumuna geçer
- [x] Faz 11 footer sadeleştirmesi tamamlandı: ödeme logoları ve panel anahtarı tamamen kaldırıldı; TR `7/7`, EN `7/7` viewport taşma `0`, ödeme kabı/görseli `0/14`, marka kilidi ve telif `14/14`; iyzico `cards_v2.png` şeridi TR ve EN ödeme sayfasında ayrı ayrı görünür (`200×21` CSS px)
- [x] Faz 11 belge başlıkları `--text-heading-document` ile 1440'ta iki dilde 43,2→28,8 CSS px; 15 sayfa × 2 dil × 7 viewport = 210/210 yatay taşma 0
- [x] Faz 11 e-posta tek kaynağı `info@kukaisland.com`: reset seed ve `wp_mail_from=woocommerce_email_from_address`; müşteri takibinde footer e-posta satırı kaldırıldı, Sosyal sütun yalnız Instagram + WhatsApp; sekiz hukuk sayfası hash'i 8/8 değişmedi
- [x] Faz 10 checkout doğrulama uyarısı tamamlandı: AJAX + sunucu çıktısı tek tam-genişlik grid satırında, 180 ms token geçişi/reduced-motion kapısı, assertive duyuru, alan bağlantıları ve ilk hata odağı
- [x] Faz 10 ölçümü: TR/EN ayrı `14/14` viewport-dil taşma `0`; hata bağlantıları iki dilde `7/7`; `Fatura Ad` ve `Billing First name` `0`; JS'siz sunucu yuvası iki dilde PASS; iki temiz reset+verify PASS, smoke `5/5`
- [x] Faz 10 alan içi doğrulama düzeltmesi: TR `9/9`, EN `9/9` boş zorunlu öğe alan üzerinde tek mesajla işaretli; JS açık üst özet TR `0`, EN `0`; bir alan doldurulduğunda her dilde `9 → 8`, sonraki boş alana odak/kaydırma PASS; yönetici e-posta ön dolumu TR `0`, EN `0`; JS'siz özet, alan ve sözleşme bağları iki dilde otomatik testte PASS
- [x] Ürün iyzico güven şeridi ölçümü: yatay merkez sapması `0 px`, ilk detay akordiyonu aralığı `24 px`, yatay taşma `0`; Mastercard/Visa/Amex/Troy `4/4` resmî CDN SVG ve `32 × 24 px` gösterim kutusu
- [x] Checkout telefon standardı: doğrudan/`0`/`+90` girişleri `3/3` → `530 948 19 96`; eksik TR ve EN mesajları ayrı PASS, odak `billing_phone`, görünür konum `352 px`, üst özet `0`; geçerli girişte hata/ARIA `0`; JS'siz EN hata ve TR `0` normalizasyonu PASS
- [x] Faz 9 e-posta katmanı `Throwable` koruması, `wp-config.php` tabanlı PHPMailer SMTP, alan adı göndereni, ayrı Reply-To, sipariş notu/logu, Başlangıç uyarısı ve test düğmesiyle tamamlandı
- [x] Faz 9 yerel ölçümü: kapalı `mail()` güvenli; `Exception` + `Error` 2/2 yakalandı; SMTP taşıyıcısı `smtp`; yerleşik müşteri/yönetici yeniden gönderme eylemleri mevcut
- [ ] Canlı sipariş #87 durumu, gerçek SMTP teslimatı/SPF/DKIM ve canlı cron anlık ölçümü üretim erişimiyle doğrulanacak
- [x] Faz 8 footer ödeme şeridi, iki dil logosu, şirket/iletişim alanları ve 12 otomatik + 5 manuel iyzico hazırlık kontrolü tamamlandı
- [x] Faz 8 ölçümü: otomatik hazırlık 7/12; 14/14 TR/EN viewport yatay taşma 0; Visa 20 CSS px / 5,29 mm
- [ ] MERSİS, KEP, meslek odası, davranış kuralları ve ETBİS müşteri/iyzico cevabıyla doldurulacak

- [x] Ana yaklaşım belirlendi
- [x] Ana plan oluşturuldu
- [x] İki plan taslağı birleştirildi (sürüm 2.0)
- [x] Plan kullanıcı tarafından onaylandı ve 2.1 denetimi uygulandı
- [x] Marka adı ve yazımı teyit edildi — **Kuka Island**
- [x] Müşteri soruları (§5) tek belgede hazırlandı
- [x] Müşteri soruları gönderildi ve cevaplandı — üç madde açık (16 hijyen bandı, 17 e-Fatura, 24 ETBİS)
- [x] Faz 1 tasarım brief'i hazırlandı
- [x] Plan 2.5'e güncellendi: müşteri cevapları, prototip mimarisi, görsel hattı, katalog veri taşıma ve ürün detay kararları işlendi
- [x] Ana sayfa görsel hattı düzeltildi: responsive kaynaklar, optimize mobil hero, etkileşim sonrası korunan `srcset`
- [x] Kategori ve koleksiyon sayfaları kuruldu: 5 URL, paylaşılan şablon, sunucu taraflı filtre/sıralama/12 ürünlük sayfalama, istemci taraflı grid yoğunluğu, erişilebilir filtre çekmecesi, 21 demo ürün
- [x] 21 demo ürünün detay sayfası kuruldu: katalog bağlantılarında 21/21 erişim, responsive galeri, renk/beden/stok akışı, erişilebilir lightbox ve zoom, çözülebilen ilişkili ürünler ve JSON-LD
- [x] Görsel optimizasyon hattı kapatıldı: yerel `IMAGES` bağlayıcısı, ortak genişlik allowlist'i, gerçek medya ölçüleri ve bağlayıcı eksikliği teşhisi
- [x] Sepet çekmecesi, sepet sayfası ve bireysel/kurumsal checkout görsel yönü tamamlandı; saf sepet modeli ve onay kilidi test edildi
- [x] §15.2 panel içerikleri `data/site-content.ts` alan sözleşmesinde toplandı; uzun/boş/kapalı içerik dayanıklılığı test edildi
- [x] Faz 1 tasarım sistemi ve yüksek kaliteli prototip tamamlandı
- [x] Parça 9 mobil, klavye, içerik dayanıklılığı ve yerel performans turu ölçüm raporuyla tamamlandı
- [x] Parça 11 filtre çekmecesi chip grid, doğal akordiyon, sabit eylem çubuğu ve responsive panel ölçümleriyle tamamlandı
- [x] Müşteri sunumu, erişim hazırlığı, bilinen sınırlamalar ve fotoğraf teslim belgeleri hazırlandı
- [x] Faz 2 Docker/WP-CLI deposu, Blocksy child theme ve Kuka Island Core iskeleti kuruldu
- [x] Renk, Beden ve Kesim global nitelikleri; 4 variable pilot ürün ve toplam 50 varyasyon seed edildi
- [x] Blocksy Free özel variation gallery metası 50/50 varyasyonda doğrulandı
- [x] 35 varyasyonlu ürün, stok/SKU/eşik davranışı doğrulandı
- [x] HPOS, TRY, İstanbul, misafir checkout, 4:5 kart crop ve geçici Türkiye kargo bölgesi ayarlandı
- [x] Kombin, Checkout mimarisi ve Blocksy Pro ölçüm raporları tamamlandı; satın alma yapılmadı
- [x] `docs/AKTARMA_HARITASI.md` Faz 3 minimum override envanteriyle oluşturuldu
- [ ] iyzico sandbox anahtarları gelince gerçek ödeme/3D/başarısız işlem testi çalıştırılacak
- [ ] Safari, Firefox, iOS Safari, Android Chrome ve gerçek cihaz Core Web Vitals kabul turu çalıştırılacak
- [ ] Gerçek 7 fotoğraflı ürün medya seti ve veri kaydıyla uzun galeri kabul testi çalıştırılacak
- [ ] Müşteri tasarım onayı alınacak
- [x] WooCommerce teknik pilotu local ortamda kuruldu; sandbox ödeme kabulü anahtar bekliyor
- [x] Faz 3A tasarım aktarımı: global vitrin, ana sayfa, katalog, ürün, sepet, klasik checkout ve içerik sayfaları tamamlandı
- [x] Faz 4 Site Appearance paneli yedi içerik grubuyla, güvenli kayıt ve fallback sözleşmesiyle tamamlandı
- [x] Tema/eklenti i18n hazırlığı, POT katalogları ve gelecekteki `/en/` URL varsayımı kaydedildi
- [x] Faz 3B bileşen aktarımı tamamlandı: gerçek header/footer, WordPress menüsü, editoryal kart/galeri, Pro'suz swatch/filtre, token disiplini ve responsive tarayıcı QA
- [x] Faz 3C panelleri tamamlandı: Woo fragment sepeti, Woo hesap/giriş paneli, ortak erişilebilir panel altyapısı, mikro etkileşimler ve açık filtre örtüsü
- [x] Faz 3D sadakat denetimi tamamlandı: 24/24 sapma düzeltildi; kesim indeksi ve kart envanter katmanı kalıcılaştırıldı; sticky/overflow ve ortak lightbox doğrulandı
- [x] Faz 4 paneli tamamlandı: sekiz Site Görünümü grubu, 72 görünür alan, iki kilitli desen, Shop Manager hesabı/menüsü ve panel rehberi hazır
- [x] Faz 3E yayın öncesi düzeltmeleri tamamlandı: sürüm pinleri, beş smoke akışı, CI kapısı, deploy runbook, panel kullanılabilirliği ve kod kalitesi
- [x] Üretim planı bu depoda kanonik ilan edildi; prototip PLAN kopyası arşiv işaretine indirildi
- [x] Faz 3F içerik tamamlandı: altı yasal taslak, yardım/marka sayfaları, merkezî şirket/ticari/beden verisi, dört ürün SEO alanı ve CSV şablonu
- [x] Faz 3G mağaza deneyimi tamamlandı: sepet sayfası düzeni, marka renkli form kontrolleri, SSS akordiyonu, gerçek şirket bilgileri, WhatsApp telefon alanı + yüzen düğme ve işlevsel servis şeridi
- [x] Faz 3H tamamlandı: palmiye amblemi (SVG), sadeleşen duyuru şeridi + panelden yönetilen dil seçici altyapısı, arama çekmecesi, yumuşak açılıp kapanan akordiyonlar ve `/odeme` sayfasının iki kolonlu yeniden tasarımı; kupon dağıtımı kuruşu kuruşuna doğrulandı — ayrıntı `docs/FAZ3H_TEKNIK_RAPORU.md`
- [x] Faz 4A müşteri onay turu tamamlandı: açık renk yüzeyler, footer/manifesto, müşteri hukuk metinleri, yalnız 14 gün cayma hakkı, S–M–L bedenler, 4.000 TL ücretsiz kargo, checkout alanları, görünür dil seçici ve okunur bildirimler
- [x] Faz 4B onay düzeltmeleri tamamlandı: editoryal başlık yedi genişlikte kelime bölünmeden sığıyor, menü etiketi “Hikâyemiz”, beden sırası `order` term metasıyla S–M–L
- [x] Faz 4C arayüz ve bülten kapsamı tamamlandı: yerel hero perdesi, AA kontrast, dengeli footer, kısa manifesto, PDF'le eş Hakkımızda ve onay kanıtlı/JS'siz bülten kayıtları
- [x] Faz 5B iki dil desteği tamamlandı: `/en/` route/SEO, 41 geçerli panel alan çifti, aynı kayıtta ürün/taksonomi/sayfa EN metaları ve sipariş locale'i; Faz 5C'de tüm yasal olmayan İngilizce içerikler ilk geçiş olarak dolduruldu, Türkçe fallback yalnız sekiz yasal sözleşmede bağlayıcılık notuyla kaldı
- [x] Faz 5C müşteri düzeltmeleri uygulandı: perdesiz ve token ölçülü hero, tepede beyaz/kaydırmada koyu header, ilk geçiş İngilizce içerik, aynı ölçekli footer kilidi, erişilebilir bülten formu ve tek kaynaklı footer WhatsApp bağlantısı
- [x] Faz 5D hero düzeltmesi tamamlandı: iki dilde yedi genişlik, satır bazlı medyan render kontrastı, uzun başlık tokenı ve panel rehberi
- [x] Faz 5E dil sürekliliği tamamlandı: merkezî `/en/` public URL filtresi, dile bağlı cart fragments/AJAX, iki dilli ticaret E2E'si ve çevrilmeyen alan sözleşmesi
- [x] Faz 6A marka hikâyesi tamamlandı: panelden eklenip çıkarılan altı iki dilli sahne, ayrı dil/viewport medyası ve tonu, masaüstü sticky IO anlatısı, mobil/reduced-motion/JS'siz düz makale ve iki dilde satır bazlı kontrast kanıtı
- [x] Faz 6B sanat yönü tamamlandı: altı lisanslı geçici görsel, ayrı masaüstü/mobil kadraj, fotoğraf üstü yönlü gradyan, altı farklı geçiş, sahne ölçekli tipografi ve panelden geçiş/konum/gradyan seçimi
- [x] Üyelik, hesap paneli ve sosyal giriş geri çekildi; misafir ödeme, 48 saatlik panel kontrollü WooCommerce oturumu ve sipariş numarası + e-posta takip akışı korundu
- [ ] `04` §5 beden değişimi maddesi ve `03` üyelik sözleşmesi için hukuk danışmanı cevabı beklenecek
- [x] Dil seçici URL-kaynaklı Türkçe/İngilizce karşılıklara bağlandı; çerez ve çeviri eklentisi kullanılmıyor
- [x] Veridyen test yayını için deploy arşiv betiği ve sağlayıcıya özgü runbook hazırlandı; fiili deploy yapılmadı
- [ ] iyzico ve satış akışı bağlanacak
- [ ] Test ve canlıya alma tamamlanacak

**Faz 2 local teknik pilotu tamamlandı; iyzico sandbox işlemi anahtar bekliyor.** Docker/WP-CLI kurulumu, global nitelikler, 4 variable ürün/50 varyasyon, Blocksy Free renk galerileri, HPOS ve görsel hattı doğrulandı. Checkout için klasik mimari seçildi. Ücretsiz kombin adayları ayrı kombin fiyatı + bağımsız beden + bileşen stoğu şartlarını birlikte karşılamadı; özel ödeme/sepet motoru yazılmadı ve satın alma yapılmadı. Ayrıntılı kanıt `docs/FAZ2_TEKNIK_RAPORU.md`, Faz 3 planı `docs/AKTARMA_HARITASI.md` içindedir. §33 ilk canlı sürüm kriteridir ve bu Faz 2 kapanışında işaretlenmemiştir.

**Paralel yürüyen, koda bağlı olmayan işler.** Bunlar gecikirse canlıya alma kayar ve gecikme geliştirme hızından bağımsızdır:

- iyzico üye işyeri başvurusu (şirket bilgileri hazır, başvuru için yasal sayfalar canlıda olmalı — §20.1)
- ETBİS kaydı
- `04` §5 ve `03` üyelik sözleşmesi için hukuk danışmanı kararı; e-Fatura durumunun mali müşavirle netleşmesi
- Fotoğraf çekimi — müşteri sorumluluğunda; `docs/FOTOGRAF_TALIMATI.md` çekim öncesi teslim edilmeye hazır
- Kesim listesi, renk sayıları, fiyatlar ve kargo firması
- Müşteri tarafında tek karar vericinin belirlenmesi (§27.2 iki revizyon turu için gerekli)
- Sunum erişim yolunun seçilmesi, kontrollü yayın ve canlı `IMAGES` doğrulaması

**Bekleyen kapsam kararı:** Kombin fiyatı parçaların toplamından bağımsızdır; bu nedenle ücretsiz §13.1.1 (a) modeli artık altı şartın tamamını karşılamaz. Ücretli bundle sandbox pilotu için müşteri bütçe/onayı ve iyzico sandbox anahtarları beklenir.
