# Kuka Island panel rehberi

Bu rehber günlük mağaza kullanıcısı içindir. Giriş yaptıktan sonra sol menüdeki palmiye ikonlu **Kuka Island** bölümünü açın.

## Nereden başlamalıyım?

**Kuka Island → Başlangıç** ekranında mağazanın “Çok yakında/Yayında” durumu, arama motoru engeli, ürün özeti ve dikkat isteyen tutarsızlıklar görünür. Günlük iş kartları ürünlere, siparişlere, Site Görünümü'ne ve Yönetim Haritası'na doğrudan gider.

Bir ayarı nerede bulacağınızdan emin değilseniz **Kuka Island → Yönetim Haritası** sayfasını kullanın. “Yeni ürün eklemek”, “WhatsApp numarasını değiştirmek” veya “siteyi yayına açmak” gibi her işin yanında doğru ekrana giden düğme vardır.

## Site Görünümü

**Kuka Island → Site Görünümü** 13 sekmeye ayrılır. Üstteki arama kutusuna “favicon”, “kargo”, “hero” gibi bir ifade yazdığınızda bütün sekmelerde eşleşen alanlar gösterilir. Kaydet düğmesi ekranın altında sabit kalır ve kayıt sonrasında aynı sekmeye dönersiniz.

Çevrilebilir satırlarda Türkçe kaynak solda, `(EN)` karşılığı sağdadır. `(EN)` boşsa ziyaretçiye Türkçe kaynak gösterilir. URL, medya, e-posta, telefon, şirket bilgisi ve sayısal değerler iki dilde ortaktır.

Görsel alanında **Medyadan seç** düğmesini kullanın. Görselin alternatif metni boşsa panel uyarır; Medya Kütüphanesi'nden görseli düzenleyip açıklayıcı alternatif metin ekleyin. Metin alanlarındaki karakter sayacı, başlığın veya açıklamanın gereksiz uzamasını fark etmenize yardım eder.

Sekmelerin yönettiği yerlerin tam listesi [PANEL_HARITASI.md](PANEL_HARITASI.md) belgesindedir.

## Ürün ekleme ve düzenleme

1. **Ürünler → Yeni ekle** yolunu açın.
2. Ürün adını Türkçe yazın; hemen altındaki **Ürün adı (EN)** alanına İngilizce karşılığını girin.
3. “Türkçe ve İngilizce ürün içeriği” kutusunda her içerik satırının Türkçesi solda, `(EN)` karşılığı sağdadır.
4. Fiyat, stok, SKU, varyasyon ve fotoğrafları WooCommerce'in Ürün verisi/Görseller alanlarında yönetin; bunlar iki dilde ortaktır.
5. Sağdaki **Yayın kontrol listesi** eksik sayısını ve eksik alanları gösterir. Liste yayınlamayı engellemez; tamamlamanız gerekenleri görünür kılar.
6. Yayındaki ürünü kontrol etmek için **Sitede gör** bağlantısını kullanın.

Kumaş, bakım, kalıp, model bilgisi, beden rehberi ilişkisi, SEO başlığı ve meta açıklaması ürün şablonunun parçasıdır. Renk ve bedenleri ürüne özel metin olarak değil, global WooCommerce nitelikleriyle seçin.

## Sayfalar

Sayfa başlığının Türkçesini ana başlık alanına, İngilizcesini hemen altındaki **Sayfa başlığı (EN)** alanına yazın. “Türkçe ve İngilizce sayfa içeriği” kutusunda iki editör yan yanadır.

Yasal sayfaların `(EN)` alanlarını yalnız hukuk danışmanından onaylı çeviri geldiğinde doldurun. `[kuka_*]` kısa kodlarını silmeyin; şirket, kargo ve iade bilgileri bu bağlantılarla merkezi ayarlardan güncel kalır.

## Kategori ve nitelikler

Kategori, renk, kesim ve beden terimi formlarında Türkçe **Ad** alanıyla **Ad (EN)** aynı ekrandadır. Renk teriminde ayrıca swatch rengi vardır. Beden sırası S–M–L olarak kayıtlıdır; yeni beden eklenirse sırası da kontrol edilmelidir.

## Uyarılar nasıl okunur?

Başlangıç ekranındaki her uyarının yanında **Düzelt** bağlantısı vardır. Sistem şu durumları denetler: yakında modu, noindex, favicon, sosyal görsel, iki hero görseli, yasal yer tutucular, e-posta, WhatsApp, 14 günlük cayma süresi, bülten onayı ve üyelik kararı.

## Bülten kayıtları

**Kuka Island → Bülten Kayıtları** yalnız e-posta, onay metni, zaman ve IP kanıtını listeler. **CSV dışa aktar** nonce ve mağaza yönetim yetkisiyle çalışır. Bu ekran toplu e-posta göndermez.

## Siteyi yayına açma

Gerçek lansman onayı gelene kadar iki ayarı değiştirmeyin:

- WooCommerce → Ayarlar → Site görünürlüğü: **Çok yakında**; “yalnızca mağaza sayfaları” kapalı
- Ayarlar → Okuma → Arama motorlarının indekslemesini engelle: **açık** (`blog_public=0`)

Özel önizleme bağlantısı yalnız kabul testi içindir. “Çok yakında” ekranını veya arama motoru engelini kaldırmak aynı işlem değildir; lansmanda ikisi ayrı ayrı ve yazılı onayla değerlendirilir.

## Güvenli kullanım

- Font, renk, grid, breakpoint, animasyon, kart oranı veya serbest HTML/CSS alanı aramayın; tasarımı korumak için panelde yoktur.
- SVG yükleme kapalıdır. Logo/görsel için desteklenen raster biçimleri veya mevcut güvenli marka varlıklarını kullanın.
- WooCommerce, iyzico veya Blocksy dosyalarını düzenlemeyin.
- Büyük değişiklikten önce yedek alın; kayıttan sonra Türkçe ve İngilizce vitrini **Sitede gör** ile kontrol edin.

## Görsel kanıtlar

- [Başlangıç, durum özeti ve görev kartları](qa/faz7/01-baslangic.png)
- [Site Görünümü sekmeleri ve “kargo” alan araması](qa/faz7/02-site-gorunumu-arama.png)
- [14 satırlı Yönetim Haritası](qa/faz7/03-yonetim-haritasi.png)
- [Ürün TR/(EN) alanları](qa/faz7/04-urun-tr-en.png)
- [Ürün TR/(EN) alanları ve tam ürün kontrol listesi](qa/faz7/04-urun-tr-en.png)
- [Sayfa TR/(EN) editörleri](qa/faz7/06-sayfa-tr-en.png)
- [Kategori adı TR/(EN)](qa/faz7/07-kategori-tr-en.png)
- [Türkçe hero: cümle üstte, yalnız Est. 2026 altta](qa/faz7/08-hero-tr.png)
- [İngilizce hero: cümle üstte, yalnız Est. 2026 altta](qa/faz7/09-hero-en.png)
- [Shop Manager'ın sadeleştirilmiş menüsü](qa/faz7/10-shop-manager.png)
- [Ziyaretçiye gösterilen tüm-site yakında ekranı ve noindex](qa/faz7/11-coming-soon-noindex.png)
- [Geçici olarak tetiklenmiş 10 eylem bağlantılı uyarı](qa/faz7/12-uyarilar-tetiklenmis.png)
- [Yedi eksiği doğru sayan ürün kontrol listesi](qa/faz7/13-urun-kontrol-listesi-eksik.png)

Uyarı ve eksik ürün görselleri için veriler geçici yedekten tetiklendi ve ekran görüntüsünden hemen sonra birebir geri yüklendi. Yakında/noindex ayarları bu işlem sırasında değiştirilmedi.
