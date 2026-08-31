# Bilinen sınırlamalar

Bu depo çalışan yerel WooCommerce üretim pilotudur; canlı satışa geçiş için aşağıdaki dış girdiler ve operasyon adımları hâlâ gereklidir.

## Yayın öncesi dış bağımlılıklar

### Sipariş e-postası ve cron ölçümü — 10 Ağustos 2026

- Veridyen `public_html/error_log` kaydında PHP `mail()` işlevinin `disable_functions` nedeniyle kapalı olduğu ve sipariş #87 için `PHPMailer\\PHPMailer\\mail()` çağrısının fatal verdiği ölçüldü. Core artık bütün `wp_mail()` çalışmasını `Throwable` sınırında yürütür; başarısızlığı WooCommerce loguna ve sipariş notuna kaydeder. Teslimat için SMTP hâlâ zorunludur.
- Yerelde gerçekten `php -d disable_functions=mail` ile çalışan kabul sürecinde `function_exists('mail')=false`, güvenli dönüş ve sipariş notu doğrulandı. Bu ölçüm canlı SMTP teslimatı değildir.
- Canlı sipariş #87'nin mevcut durumu ve geçmişte müşteriye ulaşıp ulaşmadığı yalnız hata logundan belirlenemez; canlı WooCommerce sipariş ekranı/veritabanı erişimi verilmediği için durum raporu açık kalır. SMTP kurulduktan sonra yerleşik yeniden gönderme eylemiyle kontrollü doğrulama gerekir.
- Canlı logda `action_scheduler_run_queue` ve `wp_1_wc_regenerate_images_cron` için `invalid_schedule` görülmüştür. Yerel temiz kurulumda iki olay da kayıtlıdır: Action Scheduler `every_minute=60`, görsel yenileme `wp_1_wc_regenerate_images_cron_interval=300`; olayların cron dizisindeki recurrence anahtarları aynı anda `wp_get_schedules()` içinde mevcuttur. Yerelde bozuk zamanlama yeniden üretilemedi.
- `invalid_schedule`, olay yeniden zamanlanırken recurrence anahtarının o istekte kayıtlı olmadığını kanıtlar; fakat yalnız iki log satırı bunun eklenti yükleme sırası, yarım kalmış görsel kuyruğu veya hosting cron çağrısından hangisi olduğunu ayırmaz. Tahmine dayalı silme/yeniden zamanlama yapılmadı. Canlıda zaman damgalı `wp cron event list`, `wp cron test`, `wp_get_schedules()` ve Action Scheduler durum ölçümü alınmadan müdahale edilmemelidir.

- Gerçek sepet, sipariş ve panel akışları vardır; canlı iyzico anahtarları olmadığı için gerçek tahsilat/3D dönüşü yapılmamıştır.
- Ürün adları, çeşitler, stoklar ve fiyatlar pilot veridir. Gerçek katalog müşteriden alınacaktır.
- Fotoğraflar lisanslı veya proje için hazırlanmış yer tutuculardır; gerçek ürün çekimleri değildir.
- Marka hikâyesinin altı sahnesi Pexels lisanslı, kaynakları `docs/GORSEL_KAYNAKLARI.md` içinde kayıtlı geçici sanat yönü kareleriyle kurulmuştur. Bunlar Kuka Island'a özel profesyonel çekim değildir. Gerçek çekimde masaüstü/mobil on iki kare `docs/FOTOGRAF_TALIMATI.md` brief'ine göre değiştirilip iki dilde satır bazlı kontrast yeniden ölçülmelidir.
- Hakkımızda metni müşterinin teslim ettiği manifesto PDF'inden birebir aktarılmıştır.
- **SMTP yayın engelidir.** Üyelik olmadığı için sipariş e-postası müşterinin siparişle temel bağıdır. Canlıya almadan önce gönderici alan adı SPF/DKIM/DMARC kayıtlarıyla doğrulanmalı; sipariş alındı, durum ve kişiselleştirilmiş sipariş takip bağlantısı gerçek posta kutularında teslim testi görmelidir.
- Bülten formu yalnız onaylı kayıt ve kanıt saklar; toplu veya otomatik pazarlama e-postası göndermez. Bildirim adresi boş bırakılabilir ve bu durumda kayıt yine veritabanında tutulur. Ticari ileti gönderimine geçmeden önce İYS süreci, onay metni, saklama süresi ve silme prosedürü hukuk danışmanıyla kesinleştirilmelidir.
- Hero metin perdesi müşteri kararıyla tamamen kaldırıldı. Metin okunurluğu seçilen fotoğrafın metin bölgesine, başlık uzunluğuna ve Site Görünümü panelindeki açık/koyu metin tonu seçimine bağlıdır; görsel veya metin değiştirildiğinde Türkçe ve İngilizce birlikte, mobil ve masaüstünde satır bazlı render kontrastıyla yeniden ölçülmelidir.
- Safari, Firefox, iOS Safari, Android Chrome ve gerçek cihaz Core Web Vitals turu bu projede hâlâ hiç yapılmadı. Faz 6A altı büyük hikâye görseli eklediği için özellikle LCP, INP, bellek ve mobil adres çubuğu davranışı gerçek cihazda yayın öncesi yeniden ölçülmelidir; yerel Chrome sonucu gerçek cihaz kabulü değildir.
- Ödeme sayfasındaki iyzico eklenti şeridi resmî `cards_v2.png` çıktısıdır; eklenti güncellemelerinde görünürlük ve keskinlik staging üzerinde yeniden kontrol edilir.
- Site e-postası `info@kukaisland.com`. Ön Bilgilendirme Formu ve Cayma Hakkı Sözleşmesi hâlâ `Gultekinkubraa@gmail.com` adresini gösteriyor. İki adres birbirine yönlendirilmeli ya da sözleşmeler hukuk danışmanınca güncellenmeli; aksi hâlde cayma bildirimi sözleşmedeki adrese gidip sitede takip edilmez.
- HSTS yerel HTTP ortamında ve üretim TLS doğrulaması olmadan eklenmedi. Düşük `max-age=300` ile kontrollü üretim prosedürü ve geri alma yolu `docs/DEPLOY_RUNBOOK.md` §10'dadır.

## Müşteri girdisi bekleyen konular

- Kesim listesi, ürün başına renk sayıları ve gerçek fiyatlar henüz teslim edilmedi (soru 4, 5 ve 22).
- Kargo firması, teslimat süresi ve iade kargo sorumluluğu onaylanmadı. Pilot 149 TL ücret ve müşteri tarafından seçilen 4.000 TL ücretsiz kargo eşiği panel/sayfa/WooCommerce kaynaklarında senkron tutulur.
- İade için tek süre 14 günlük cayma hakkıdır; değişim hizmeti sunulmaz. Müşteri hijyen metni üç yüzeyde panelden yönetilir ve ayıplı ürün hakları ayrıca saklı tutulur.
- e-Fatura durumu ve ETBİS kaydı bilgisi bekleniyor (soru 17 ve 24). ETBİS alanı boş bırakıldı ve bilgi gelene kadar vitrinde satır basmıyor.
- TC Kimlik No bilinçli olarak hiçbir yere girilmedi ve yayınlanmayacak: mesafeli satış için gerekmiyor, işletme sahibinin kimlik verisini açığa çıkarmak KVKW maruziyeti yaratır.
- MERSİS, KEP, meslek odası ve davranış kuralları bilgisi bekleniyor. Uydurma veya “bulunmamaktadır” satırı yayımlanmıyor; iyzico'nun şahıs işletmesi için bu alanları isteyip istemediği müşterinin soracağı açık konudur.
- Üst-alt takım alımında indirim uygulanıp uygulanmayacağı bekleniyor (soru 19).
- Logo, kurumsal renk/font dosyaları ve varsa marka kullanım kuralları bekleniyor (soru 11). Sunumdaki yazı karakterli logo geçicidir.
- Sekiz yasal metin müşterinin PDF'lerinden aktarıldı ve görünür taslak uyarısı kaldırıldı. `03 Üyelik ve E-Ticaret Sitesi Kullanım Sözleşmesi`, üyelik sunulmayan siteyle çeliştiği için hukuk danışmanı kararı gelene kadar `/kullanim-kosullari/` altında taslak durumunda ve menülerden gizlidir. `04` §5 beden değişimine izin veren metni de müşteri talebiyle çelişir; hukuk danışmanından çıkarılması istendi, belge bizim tarafımızdan değiştirilmedi. ETBİS alanı bilgi gelene kadar boş ve vitrinde gizlidir.

## İlk sürümden sonraki fazlar

İlk canlı sürümden sonraki fazlarda değerlendirilecek işler: e-Fatura; pazaryeri bağlantıları; özel kargo API'si; iade/değişim portalı; ikiden fazla dil ve çoklu para birimi; ERP/muhasebe bağlantısı; profesyonel çekimin üretilmesi; ürün yorumları ve puanlama; takım set indirimi; ana sayfa videosu; ürün açıklama metinlerinin yazılması; ayrıca anlaşılmadıkça logo ve tam kurumsal kimlik tasarımı; özel mobil uygulama; sadakat/puan ve abonelik sistemi; gelişmiş pazarlama otomasyonları; devir sonrası sürekli bakım/destek. Bunlar iptal edilmiş değildir; sonraki fazların kapsamıdır. Yüzlerce ürünün elle temizlenmesi/girilmesi, kurala aykırı fotoğrafların elle eşleştirilmesi, sınırsız revizyon ve referans sitelerin birebir kopyalanması da mevcut kapsamda değildir.

## Faz 5B iki dil kapsamı

- İngilizce sürüm Türkiye'ye satış yapan mağazada yabancı ziyaretçinin arayüzü anlayacağı varsayımıyla kurulmuştur; ihracat altyapısı değildir.
- Para birimi iki dilde de TRY'dir. Çoklu para birimi, yurt dışı kargo, ihracat faturası ve yabancı tüketici hukuku kapsam dışıdır.
- İngilizce URL'ler `/en/` ön eki kullanır; ürün, kategori ve sayfa slug'ları Türkçe kalır. İngilizce slug/redirect çalışması ayrı kapsamdır.
- Yasal olmayan İngilizce metinler ilk editoryal geçiş olarak yazılmıştır; marka hikâyesi dahil müşteri gözden geçirmesine açıktır. Türkçe fallback uyarısı yalnız sekiz hukuk sayfasında kalır.
- Sekiz yasal metnin İngilizce çevirisi yapılmamıştır; EN alanları boştur. `/en/` yasal sayfası bağlayıcı sürümün Türkçe olduğunu bildirip Türkçe metni gösterir.
- Mevcut geçmiş siparişlerde dil metası yoktur; Faz 5B sonrasında checkout'ta oluşan siparişler `tr_TR` veya `en_US` locale'iyle kaydedilir.
- Dil adları, marka adları, URL/sayı/medya/renk/telefon ve şirket alanları çevrilmez; iki dil aynı tek kaynağı kullanır. Seçici her iki vitrinde `Türkçe / English` gösterir.
- İngilizce public URL sürekliliği tema/çekirdek permalink ve WooCommerce dönüş filtreleriyle korunur; teknik admin, REST ve AJAX uçları bilinçli olarak `/en/` almaz.
- E-posta HTML'i doğru locale'de üretilebilir; gerçek posta kutusuna teslim/SPF/DKIM/DMARC doğrulaması SMTP kurulumu beklediği için kapsam dışı kalır.

## Onaylanan tasarım gereksinimleri

- Ana sayfadaki kesim/kategori indeksi müşteri isteğiyle varsayılan kapalıdır; ileride Site Görünümü panelinden geri açılabilir.
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

## Faz 4A kapsam sınırı

- **Üyelik ve sosyal giriş kaldırıldı.** Storefront misafir ödeme ile çalışır; `/hesabim/` WooCommerce iç bağımlılıkları için durur ve ana sayfaya 302 yönlenir. Nextend kurulmaz. Yönetici girişi ve Loginizer kapsam dışı değildir; `wp-login.php` kullanılmaya devam eder.
- **Dil seçici Türkçe/English olarak çalışır.** `/en/` Faz 5B'de yayındadır; müşteri tarafından doldurulmayan İngilizce içerik Türkçe kaynağa düşer. Yurt dışı satış ayrı fazdır.
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

## Test taşınabilirliği: yerele özgü siparişler

GitHub Actions `Quality` koşusu (`make install` ile temiz veritabanı, ardından `make verify`) iki kabul kontrolünde başarısız oluyordu. Kök neden üretim kodu veya EDM entegrasyonu değil, testlerin **yalnız geliştirici veritabanında bulunan sipariş numaralarını evrensel sözleşme gibi kullanmasıydı**.

### 1. Billing paneli alan davranışı

Eski hâl: `scripts/verify-order-experience.php`, `ORDER_BILLING_FIELDS` çıktısını sabit `#297` ve `#125` siparişlerinden üretiyordu. Temiz veritabanında bu siparişler yok, çıktı boş kalıyor ve beklenti tutmuyordu.

Yeni hâl: davranış `scripts/verify-order-billing-panel.php` içinde, bu koşunun kendi oluşturup sahiplendiği ve tamamen sildiği fixture'lar üzerinde kanıtlanıyor. `verify-order-experience.php` salt-okunur sözleşmesini koruyor; hiçbir yazma işlemi içermiyor.

Sözleşme:

```
ORDER_BILLING_FIELDS=full:first:set,last:set,email:set,phone:set|no_phone:first:set,last:set,email:set,phone:empty
ORDER_BILLING_FIELD_PRESENCE=PASS|cases:2|...
ORDER_BILLING_ROUNDTRIP=PASS|fields:12|mismatches:none
ORDER_BILLING_FIXTURE_CLEANUP=PASS|state:succeeded|created:2|db_discoverable:2|refused:0|leftover:0|reentry_blocked:yes
ORDER_BILLING_DB_ISOLATION=PASS|tables:12|pre_hash:...|post_hash:...|diff:none
```

İki fixture: telefonu dolu bir müşteri ve telefonu boş bir müşteri — eski `#125` / `#297` çiftinin ölçtüğü ayrımın aynısı. Değerler yazıldıktan sonra veritabanından yeniden okunup **byte-for-byte** karşılaştırılıyor, yani alanları bozan bir filtre veya store yakalanıyor. Fixture'lar `_kuka_isolation_run_id` taşıdığı için ölümcül hatadan sonra da veritabanından bulunup temizlenebiliyor; 12 tablo keyset'i öncesi/sonrası karşılaştırılıyor.

### 2. Uzun ömürlü yerel sandbox siparişleri

`#125`, `#189`, `#190`, `#192`, `#193` yalnız geliştirici veritabanında var. Bunların değişmediğini doğrulamak yerelde anlamlı; temiz kurulumda bulunmamaları **hata değil, farklı bir durum**.

Sözleşme üç durumlu (`scripts/lib-protected-orders.php`):

| Durum | Anlam |
|---|---|
| `verified` | Snapshot'taki her sipariş mevcut ve imzası (`status/total`) birebir aynı |
| `not_applicable` | Hiçbiri yok — temiz veritabanı / CI |
| `DRIFT` | Kısmen mevcut, ya da bir imza değişmiş → **FAIL** |

```
PROTECTED_ORDERS=verified|present:5/5|matching:5|drifted:0|absent:0|reason:all_snapshot_orders_present_and_unchanged
PROTECTED_ORDERS=not_applicable|present:0/5|matching:0|drifted:0|absent:5|reason:clean_database_without_local_sandbox_orders
PROTECTED_ORDERS=DRIFT|present:5/5|matching:4|drifted:1|absent:0|reason:partial_presence_or_signature_change|drifted_ids:190
```

`verify.sh` yalnız ilk iki satırı kabul eder; her `DRIFT` şekli FAIL üretir. Bu bir "sipariş yoksa PASS" kısayolu değildir: kısmi mevcudiyet ve imza değişimi hâlâ hata sayılır.

Başka bir doğrulama scriptinin geçici fixture'ı bu ID'lerden birini tutuyorsa (temiz veritabanında mümkün), run işareti taşıdığı için o satır uzun ömürlü sipariş sayılmaz ve `absent` olarak sınıflanır — sahte `DRIFT` üretmez.

Sınıflandırıcı saf bir fonksiyon olduğu için üç dalın tamamı fixture ile kanıtlanıyor; temiz veritabanı dalı geliştirici veritabanında yeniden üretilemediği hâlde ölçülebiliyor (`PROTECTED_ORDERS_VERDICT_MATRIX`, 8 vaka).
