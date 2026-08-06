# Bilinen sınırlamalar

Bu depo çalışan yerel WooCommerce üretim pilotudur; canlı satışa geçiş için aşağıdaki dış girdiler ve operasyon adımları hâlâ gereklidir.

## Yayın öncesi dış bağımlılıklar

- Gerçek sepet, sipariş ve panel akışları vardır; canlı iyzico anahtarları olmadığı için gerçek tahsilat/3D dönüşü yapılmamıştır.
- Ürün adları, çeşitler, stoklar ve fiyatlar pilot veridir. Gerçek katalog müşteriden alınacaktır.
- Fotoğraflar lisanslı veya proje için hazırlanmış yer tutuculardır; gerçek ürün çekimleri değildir.
- Hakkımızda metni özgün fakat geçici marka anlatısıdır; gerçek kuruluş hikâyesi müşteriden beklenmektedir.

## Müşteri girdisi bekleyen konular

- Kesim listesi, ürün başına renk sayıları ve gerçek fiyatlar henüz teslim edilmedi (soru 4, 5 ve 22).
- Kargo firması, teslimat süresi, iade kargo sorumluluğu ve nihai ücretsiz kargo eşiği onaylanmadı. Pilot 149 TL ücret, 1.500 TL eşik ve 14 gün panel/sayfa/WooCommerce kaynaklarında senkron tutulur; yayın öncesi müşteri onayıyla değiştirilecektir.
- Hijyen bandının kullanım ve iade koşulları karara bağlanmadı (soru 16).
- e-Fatura durumu ve ETBİS kaydı bilgisi bekleniyor (soru 17 ve 24). Şirket bilgileri (satıcı, VKN, vergi dairesi, adres, telefon) Faz 3G'de girildi; ETBİS numarası panelde yer tutucu olarak `[ETBİS NO]` görünmeye devam eder.
- TC Kimlik No bilinçli olarak hiçbir yere girilmedi ve yayınlanmayacak: mesafeli satış için gerekmiyor, işletme sahibinin kimlik verisini açığa çıkarmak KVKW maruziyeti yaratır.
- MERSİS numarası alanı kaldırıldı; işletme şahıs işletmesi olduğu için MERSİS taşımıyor.
- Üst-alt takım alımında indirim uygulanıp uygulanmayacağı bekleniyor (soru 19).
- Logo, kurumsal renk/font dosyaları ve varsa marka kullanım kuralları bekleniyor (soru 11). Sunumdaki yazı karakterli logo geçicidir.
- Altı yasal sayfa özgün çalışma taslaklarıyla test yayınına hazırdır; görünür hukuk onayı uyarısı kalır. `[ETBİS NO]` alanı müşteriden gelmediği için merkezî panelde yer tutucudur. Şirket bilgileri (satıcı, VKN, vergi dairesi, adres, telefon) Faz 3G'de gerçek değerlerle dolduruldu; MERSİS alanı şahıs işletmesi olduğu için kaldırıldı, TC Kimlik No bilinçli olarak yayınlanmıyor. Hukuk danışmanı ve şirket yetkilisi onayı olmadan metinler yürürlüğe alınamaz.

## İlk sürümden sonraki fazlar

İlk canlı sürümden sonraki fazlarda değerlendirilecek işler: e-Fatura; pazaryeri bağlantıları; özel kargo API'si; iade/değişim portalı; çoklu dil ve para birimi; ERP/muhasebe bağlantısı; profesyonel çekimin üretilmesi; ürün yorumları ve puanlama; takım set indirimi; ana sayfa videosu; ürün açıklama metinlerinin yazılması; ayrıca anlaşılmadıkça logo ve tam kurumsal kimlik tasarımı; özel mobil uygulama; sadakat/puan ve abonelik sistemi; gelişmiş pazarlama otomasyonları; devir sonrası sürekli bakım/destek. Bunlar iptal edilmiş değildir; sonraki fazların kapsamıdır. Yüzlerce ürünün elle temizlenmesi/girilmesi, kurala aykırı fotoğrafların elle eşleştirilmesi, sınırsız revizyon ve referans sitelerin birebir kopyalanması da mevcut kapsamda değildir.

## Onaylanan tasarım gereksinimleri

- Ana sayfadaki kesim/kategori indeksi kalıcı gereksinimdir; içerik gerçek kategori ve `pa_kesim` verisinden gelir.
- Ürün kartındaki swatch, SKU ve beden/stok satırı kalıcı gereksinimdir. Swatch ve beden/stok katmanı müşteri isterse Site Görünümü'nden ayrı ayrı kapatılabilir.

## Üretim hazırlığı durumu

- WordPress, WooCommerce, Blocksy/Companion ve iyzico sürümleri; MariaDB/WordPress/WP-CLI imajları sabitlenmiştir. Güncelleme yalnız staging yedeği ve `make verify` sonrası yapılır.
- Smoke kapsamı ana sayfa/hero, katalog+filtre, ürün varyasyonu+stok dışı beden, doğru varyasyonu sepete ekleme ve checkout iki-onay kilididir. Canlı ödeme, e-posta teslimatı ve gerçek cihaz motorları bu smoke kapsamına girmez.
- Veridyen dosya/DB/medya aktarımı, coming soon erişim seçenekleri, güvenlik, SMTP, yayın sonrası kontrol ve gerçek geri dönüş testi `docs/DEPLOY_RUNBOOK.md` içinde hazırdır; fiili deploy yapılmamıştır.
- PHPCS yayın kapısı WordPress standardının kritik (severity 9) ihlallerini engeller. Daha düşük önemdeki mevcut biçimlendirme borcu toplu bir yayın değişikliğine çevrilmemiştir. PHPStan, WordPress/WooCommerce dinamik hook/global stub bakımı nedeniyle bu turda eklenmemiştir.
# Faz 2 ekleri

- Dikey poster logo 64 px header'a uygun değildir. Müşteriden SVG, yatay lockup, açık/koyu varyant ve favicon bekleniyor; o zamana kadar tipografik `KUKA ISLAND` wordmark kullanılır.
- Logo serif ailesinin adı ve lisansı bilinmediği için serif font eklenmedi. Geist sans + mono, child theme içinde self-hosted kullanılır.
- iyzico sandbox anahtarları yok; gateway işlem testi yapılmadı.
- Kombin için altı ticari şartı birlikte karşılayan ücretsiz çözüm bulunmadı.
- Kesim landing page'leri müşteri kesim listesi ve SEO kararı gelene kadar açılmadı.

## Faz 3H kapsam sınırı

- **Google ile giriş canlı alan adı olmadan test edilemez.** Nextend Social Login kurulu ve etkin; sağlayıcı geçerli bir OAuth istemci kimliği/gizli anahtarı ve Google konsolunda kayıtlı bir yönlendirme URL'si olmadan etkinleşmiyor. Anahtar yokken buton hiç render olmuyor (doğrulandı); butonun marka dilindeki görünümü canlı alan adında doğrulanacak. Gizli anahtar repoya girmeyecek; panelden/eklenti ayarından girilecek.
- **Dil seçici altyapısı hazır fakat gizli.** Site Görünümü → Dil Seçici alanına iki satır girildiğinde seçici duyuru şeridinin sağ ucunda görünür; tek dil (varsayılan) tanımlıyken hiç render olmaz. İkinci dil kararı (ücretsiz TranslatePress vs. ücretli çözüm — §3.5, §31) beklendiği için bu turda ikinci dil eklenmedi. Çeviri eklentisi seçilince liste ondan beslenecek şekilde bağlanacak.
- **Ödeme sayfasında "Teslimat yöntemi" ayrı bir form bölümü değildir.** Kargo yöntemi seçimi WooCommerce'in `update_order_review` parçasında yaşar ve yalnız orada yenilenir; sipariş özeti kolonunda, kargo tutarının hemen üstünde görünür. Sol kolona taşımak parçayı bozar ve checkout akışını yeniden yazmayı gerektirirdi (§17.3).
- **Tahmini teslim tarihi panelde sayı olmadan gösterilmez.** `Site Görünümü → Ticari Bilgiler → Tahmini teslimat süresi` alanı `[TESLİMAT SÜRESİ]` yer tutucusunda kaldığı sürece özet kolonunda tarih satırı hiç açılmaz. Alana "2-4 iş günü" gibi bir değer girildiğinde tarih aralığı iş günü sayılarak hesaplanır.
- **KDV satırı yoktur.** `woocommerce_calc_taxes = no`; toplam satırındaki "KDV dahil" ibaresi fiyatların vergi dâhil girildiği kabulünü belirtir. Vergi yapılandırılırsa hem özet hem kupon denetim betiği vergi matrahını ayrıca raporlar.
- **iyzico cüzdanı (`pwi`) kapalıdır.** Eklenti iki geçit kaydeder; checkout'ta tek yöntem istendiği için cüzdan geçidi seed sırasında pasifleştirilir. Müşteri isterse WooCommerce ödeme ayarlarından açılabilir.
- **JS kapalıyken kupon hatası bildirim alanında görünür.** JS açıkken hata kupon alanının hemen altında `--color-error` ile yazılır; JS kapalıyken WooCommerce'in sayfa üstündeki standart bildirim alanı kullanılır.

## Faz 3B kapsam sınırı

- Favoriler, beden rehberi modalı ve mega menü yapılmadı; ayrı veri/hesap, erişilebilir panel ve içerik kararı gerektirir. WooCommerce fragment tabanlı sepet çekmecesi Faz 3C'de tamamlandı.
- Gerçek müşteri kesim listesi teslim edilmedi; indeks mevcut `pa_kesim` terimleriyle çalışır ve yeni terimler eklendikçe otomatik güncellenir.
- Takım ürünlerde bağımsız iki beden ve ayrı paket fiyatı, ücretsiz çözümün stok/fiyat koşullarını karşılamadığı için ürün eşleştirme bağlantısı düzeyinde kalır.
- Gerçek yedi fotoğraflı müşteri ürünü teslim edilmedi. Galeri 2–4 görselli pilot medya ve görsel sayısından bağımsız DOM sözleşmesiyle doğrulandı; yedi gerçek fotoğraflı kabul turu açıktır.
- iyzico sandbox anahtarları bulunmadığından gerçek tahsilat/3D dönüşü test edilmedi. Ödeme yöntemleri korunmuştur; yalnız `#iyzico-bpo1[data-type="page-overlay"]` yüzen promosyonu, eklentide kapatma ayarı bulunmadığı için child CSS ile gizlenir.
