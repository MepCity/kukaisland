# Kuka Island — Veridyen test yayını runbook'u

Bu belge Local → Veridyen test alanı → Production sırasını tarif eder. Fiili aktarım, alan adı, SMTP ve iyzico anahtarları mağaza sahibinin yetkisindedir. Repoya kullanıcı adı, parola, anahtar, IP veya gerçek veritabanı bilgisi yazılmaz.

## 1. Yayın öncesi kapı

1. Temiz çalışma kopyasında `git pull --ff-only`, `composer install` ve `make verify` çalıştırılır. Yalnız `VERIFY=PASS` ile devam edilir.
2. `make deploy-package` çalıştırılır. `dist-deploy/` altındaki `.tar.gz` ile `.sha256` dosyasının adı aktarım kaydına yazılır; dizin Git dışıdır.
3. `wp core version`, `wp plugin list` ve `wp theme list` çıktıları saklanır. Sürümler `scripts/install.sh` pinleriyle karşılaştırılır.
4. Yerel SQL ve `wp-content/uploads/`; Veridyen'deki mevcut web kökü, veritabanı ve medya ayrı zaman damgalı yedeklenir.
5. Test alan adı, coming soon erişim kararı ve geri dönüş sorumlusu müşteri tarafından yazılı olarak seçilir.

## 2. Veridyen paneli ve dosya aktarımı

Veridyen Müşteri Paneli'nde **Hizmetler → ilgili hosting → Kontrol Paneline Giriş** yoluyla paketin sunduğu kontrol paneli açılır. Menü adı pakete göre değişirse Veridyen destek kaydında “web kökü, FTP hesabı, veritabanı/phpMyAdmin ve varsa SSH erişimi” istenir; gizli değerler destek kaydının dışına kopyalanmaz.

### Seçenek A — kontrol paneli dosya yöneticisi

1. Alan adının web kökü (`public_html`, `httpdocs` veya panelin gösterdiği document root) kesin olarak doğrulanır.
2. Mevcut child tema ve Core eklenti klasörleri indirilerek yedeklenir.
3. Deploy arşivi yerel bilgisayarda açılır. Yalnız şu dizinler hedefte aynı yollara yüklenir:
   - `wp-content/themes/kuka-island-child/`
   - `wp-content/plugins/kuka-island-core/`
4. Dizin izinleri `755`, dosya izinleri `644`; sahiplik hosting PHP kullanıcısında olmalıdır.

### Seçenek B — SFTP/FTP

Panelde ayrı bir aktarım kullanıcısı oluşturulur; mümkünse düz FTP yerine SFTP seçilir. Sunucu, port, kullanıcı ve parola yalnız parola yöneticisinde tutulur. İstemcide hedef web kökü görülmeden yükleme başlatılmaz. Aynı iki proje dizini aktarılır; WordPress core veya `uploads` üzerine yanlışlıkla sürükle-bırak yapılmaz.

### Seçenek C — SSH (pakette açıksa)

Arşiv kullanıcının home dizinine SFTP ile yüklenir, checksum doğrulanır ve web köküne yalnız yetkili kullanıcıyla açılır. Örneklerdeki değerler yer tutucudur:

```sh
sha256sum -c [PAKET].tar.gz.sha256
tar -xzf [PAKET].tar.gz -C [WEB_KOKU]
```

SSH yoksa açılması varsayılmaz; kontrol paneli yöntemi kullanılır. Blocksy parent, WooCommerce ve iyzico dosyaları deploy arşivinden gelmez. Hedefte pinned sürümlerle kurulur ve doğrudan düzenlenmez.

## 3. Veritabanı dışa/içe aktarma ve URL dönüşümü

1. Yerelde `wp db export [YEREL_YEDEK].sql` ile SQL alınır.
2. Veridyen panelinde yeni, boş veritabanı ve en az yetkili kullanıcı oluşturulur. İsimler/parola `wp-config.php` veya hosting secret alanına girilir; repoya yazılmaz.
3. Hedef mevcutsa phpMyAdmin/SSH ile önce `[HEDEF_ONCESI].sql` yedeği alınır.
4. SQL, phpMyAdmin **İçe Aktar** veya SSH/WP-CLI ile hedefe yüklenir. Büyük dosyada panel limiti aşılırsa Veridyen desteğinden güvenli içe aktarma yolu istenir; SQL web kökünde bırakılmaz.
5. Serileştirilmiş veriyi bozmamak için düz `sed` kullanılmaz. SSH/WP-CLI varsa:

```sh
wp search-replace 'http://localhost:8080' 'https://[TEST_ALAN_ADI]' --all-tables-with-prefix --precise --skip-columns=guid
wp option update home 'https://[TEST_ALAN_ADI]'
wp option update siteurl 'https://[TEST_ALAN_ADI]'
wp rewrite flush --hard
wp cache flush
```

6. WP-CLI yoksa URL dönüşümü serileştirilmiş veriyi destekleyen güvenilir bir migration aracıyla veya Veridyen desteğiyle yapılır. Düz SQL metin değiştirme yapılmaz.

## 4. Medya

`wp-content/uploads/` yılı/ayı ve dosya adları korunarak ayrıca aktarılır. Kaynak/hedef dosya sayısı ve toplam byte karşılaştırılır. Hero, ürün ana görselleri, varyasyon galerileri, logo ve sosyal paylaşım görselinden örnekler HTTPS/200 ile açılır. `seed-media/`, `app-reference/`, `data-reference/`, `lib-reference/` ve `dist-deploy/` üretim web köküne gönderilmez.

## 5. Üretim `wp-config.php`

- `WP_DEBUG`, `WP_DEBUG_DISPLAY` ve `WP_DEBUG_LOG` kapalıdır.
- WordPress salts/keys üretim için yeniden üretilir; yerel tuzlar taşınmaz.
- `define( 'DISALLOW_FILE_EDIT', true );` eklenir.
- Veritabanı parolası ve iyzico anahtarları yalnız hosting secret/config alanındadır.
- HTTPS zorlanır; dosya izinleri ve sahiplik doğrulanır; kullanılmayan yönetici hesapları kaldırılır.
- Gerçek lansman onayına kadar **Ayarlar → Okuma → Arama motorlarının bu siteyi indekslemesine engel olmaya çalış** açık kalır (`blog_public=0`). Coming soon kalkması bu ayarı otomatik açmaz.

## 6. Coming soon ile birlikte test erişimi — müşteri kararı

### WooCommerce özel paylaşım bağlantısı — iyzico incelemesi

Yönetici `WooCommerce → Ayarlar → Site görünürlüğü` ekranını açar, **Özel bağlantıyla paylaş / Share store** alanından WooCommerce'in ürettiği bağlantıyı kopyalar ve yalnız iyzico başvuru kanalından iletir. Bağlantı hesap gerektirmez; URL'deki erişim tokenı parola gibi ele alınır. Gerçek URL/token bu depoya, ekran görüntüsüne, issue'a veya e-postanın konu satırına yazılmaz. İnceleme bittiğinde aynı ekrandan bağlantı yenilenir ya da erişim kapatılır.

Alternatif olarak inceleme süresince `Çok yakında` kapatılabilir. Artısı iyzico'nun normal ziyaretçi yolunu görmesidir; eksisi URL bilen herkesin pilot ürünleri ve eksik içerikleri görebilmesidir. `noindex` erişim kontrolü değildir. Bu alternatif ancak müşteri kararı, kısa zaman penceresi ve inceleme sonrası yeniden kapatma kaydıyla kullanılır.

| Seçenek | Nasıl çalışır | Bedel/risk |
|---|---|---|
| Şifre korumalı test alt alan adı — önerilen | `test.[ALAN_ADI]` ayrı document root ve HTTP Basic Auth ile yalnız davetlilere açılır; ana alan adındaki coming soon kalır | En temiz ayrım; alt alan adı, SSL ve ikinci kurulum gerekir |
| IP kısıtlı test yolu | Test alanına yalnız belirtilen IP'ler erişir | Dinamik/mobil IP değişince erişim kesilebilir; iyzico dış dönüşleri engelleyebilir |
| Ana alanda coming soon'u geçici kaldırma + `noindex` | Site dışarıdan erişilir, `blog_public=0` ve robots/meta noindex kalır | URL bilen herkes görebilir; noindex erişim kontrolü değildir |

Müşteri seçimi deploy kaydına yazılır. Önerilen varsayılan, ana coming soon'u koruyup şifreli test alt alan adı kullanmaktır. Ödeme kuruluşunun test callback'i gerekiyorsa Basic Auth/IP kuralıyla uyumluluk ayrıca doğrulanır. Gerçek lansmana kadar `blog_public=0` korunur.

## 7. SMTP ve ödeme

1. Alan adına ait SMTP hesabı müşteri tarafından açılır. WordPress ve WooCommerce gönderen adresi Site Görünümü'ndeki marka e-postasından gelir; varsayılan `info@kukaisland.com` adresidir. Gmail göndereni kullanılmaz.
2. Hosting secret alanında veya üretim `wp-config.php` dosyasında, `/* That's all, stop editing! */` satırından önce şu sabitler tanımlanır: `KUKA_SMTP_HOST`, `KUKA_SMTP_PORT`, `KUKA_SMTP_USERNAME`, `KUKA_SMTP_PASSWORD`, `KUKA_SMTP_ENCRYPTION`, `KUKA_SMTP_FROM_NAME`. Şifreleme değeri yalnız `tls`, `ssl` veya `none` olabilir.
3. Yanıt adresi gönderen posta kutusundan ayrılacaksa aynı yerde isteğe bağlı `KUKA_SMTP_REPLY_TO_EMAIL` ve `KUKA_SMTP_REPLY_TO_NAME` tanımlanır. Bu değerler veritabanına, Git'e, destek kaydına veya ekran görüntüsüne yazılmaz.
4. Sabitlerin tamamı yoksa Core SMTP'yi etkinleştirmez. Sunucuda `mail()` de kapalıysa Başlangıç ekranı bunu `function_exists('mail')` ölçümüyle bildirir; gönderim hatası checkout'u durdurmaz ve ilgili siparişe not düşer.
5. **Kuka Island → Başlangıç → Test e-postası gönder** ile yönetici adresine test atılır. Başarı/hata ekranda görülür; SMTP parolası hiçbir çıktıda gösterilmez.
6. SPF, DKIM ve DMARC kayıtları doğrulanır. Parola sıfırlama, yeni sipariş, yönetici bildirimi ve müşteri sipariş iletisi gerçek posta kutularında sınanır.
7. Başarısız bir sipariş iletisi düzeltildikten sonra sipariş ekranındaki yerleşik **Sipariş ayrıntılarını müşteriye gönder** veya **Yeni sipariş bildirimini yeniden gönder** eylemi kullanılır.
8. iyzico önce sandbox/test anahtarlarıyla sınanır. Canlı anahtarlar yalnız mağaza sahibi ve iyzico aktivasyonundan sonra secret alana girilir. Ödeme/checkout özel kodla değiştirilmez.

## 8. Test yayını doğrulama listesi

- Ana sayfa, katalog, filtre, ürün, sepet ve checkout HTTPS/200; karma içerik 0.
- Doğru varyasyon sepete eklenir, stok dışı beden pasiftir.
- Sepet kargo ücreti/eşiği, SSS, Kargo ve Teslimat ve checkout aynı panel değerlerini gösterir.
- Altı yasal sayfa erişilebilir; taslak uyarısı ve şirket yer tutucuları görünürdür.
- İki yasal onay olmadan ödeme düğmesi pasiftir; kurumsal alanlar JS kapalıyken görünürdür.
- Shop Manager ürün, sipariş ve Kuka Island ekranlarına erişir; eklenti/tema düzenleyemez.
- Canonical, robots/noindex, sitemap, favicon, OG görseli, 404 ve yönlendirmeler kontrol edilir.
- SMTP testleri geçer; yalnız sandbox iyzico ile kontrollü test yapılır.
- Dosya sayısı/checksum ve veritabanı satır örnekleri aktarım kaydına eklenir.

## 9. Yedekten geri dönüş testi

Test yayını kabul edilmeden önce hedefin yedeği ayrı bir test veritabanı/dizine geri yüklenir. `siteurl`/`home`, yönetici girişi, ana sayfa ve bir ürün görseli açılarak yedeğin gerçekten kullanılabilir olduğu kanıtlanır. Yalnız “yedek alındı” mesajı yeterli değildir.

Kritik hata halinde trafik coming soon/bakım sayfasına alınır; yeni sipariş kabulü durdurulur. Child tema ve Core eklenti önceki paketle değiştirilir; gerekirse yayın öncesi SQL ile `uploads` yedeği geri yüklenir. URL'ler ve cache tekrar doğrulanır, beş smoke akışı çalıştırılır. Geri alınan commit, zaman, sebep ve veri kaybı ihtimali kayıt altına alınır.

## 10. HTTP güvenlik başlıkları ve HSTS

Core eklentisi müşteri yüzeyinde CSP; bütün PHP yanıtlarında `nosniff`, `Referrer-Policy`, `SAMEORIGIN` ve sınırlı `Permissions-Policy` gönderir. CSP, ödeme sayfasındaki eklenti şeridini ve checkout içeriğini bozmamak için yalnız iyzico/iyzipay HTTPS kaynaklarına ayrıca izin verir. `/.well-known/security.txt` güvenlik bildirim adresini yayımlar.

HSTS yerel HTTP ortamında kapalıdır. Üretimde WordPress isteği HTTPS algıladığında yalnız `max-age=300` ile kendiliğinden açılır; bu aşamada `includeSubDomains` ve `preload` kullanılmaz. `.htaccess` içine ikinci bir HSTS satırı eklenmez.

Aktarım sonrası ana sayfa, giriş, ürün ve checkout yanıtlarında başlıklar ayrı ayrı ölçülür:

```sh
curl -sSI https://[ALAN_ADI] | grep -Ei '^(content-security-policy|strict-transport-security|x-content-type-options|referrer-policy|x-frame-options|permissions-policy):'
curl -fsS https://[ALAN_ADI]/.well-known/security.txt
```

HSTS `max-age=300`, güvenlik metni `Contact`, `Expires`, `Preferred-Languages` ve `Canonical` satırlarını göstermelidir. Checkout sandbox ödemesi ve iyzico kart şeridi doğrulandıktan sonra en az bir hafta gözlenir; ancak bundan sonra HSTS süresi ayrı bir değişiklikle kademeli artırılır.

## 11. Canlı performans kapısı

Önbellek ve veritabanı katmanları hosting paketine aittir; depoda Redis varmış gibi `object-cache.php` kopyalanmaz ve paylaşımlı sunucuda sabit bir InnoDB bellek değeri varsayılmaz. Lansmandan önce Veridyen paneli veya destek kaydıyla aşağıdaki değerler gerçek canlı PHP/MySQL sürecinde ölçülür:

1. OPcache açık olmalı; `opcache.memory_consumption` en az `192`, `opcache.interned_strings_buffer` en az `16`, `opcache.max_accelerated_files` en az `20000` olmalıdır. Yerel Docker aynı sözleşmeyi `docker/php/opcache.ini` ile kullanır.
2. Redis/Memcached yalnız hosting kalıcı object cache hizmeti sunuyorsa etkinleştirilir. Sonrasında `wp eval 'var_export( wp_using_ext_object_cache() );'` çıktısı `true` ve yönetim ekranı/checkout smoke testi PASS olmalıdır. Hizmet yokken sahte drop-in bırakılmaz.
3. LiteSpeed veya eşdeğer tam sayfa cache yalnız oturum açmamış vitrin sayfalarında açılır. `/sepet/`, `/odeme/`, `/hesabim/`, `?wc-ajax=*`, WooCommerce session/cart çerezli istekler ve ödeme callback'leri cache dışında kalır. Aynı anonim ana sayfa isteğinde ikinci yanıtın cache HIT, sepet ve checkout yanıtlarının daima BYPASS/MISS olduğu yanıt başlıklarıyla kaydedilir.
4. `innodb_buffer_pool_size` değeri ve veritabanının gerçek veri+indeks çalışma kümesi hosting tarafından raporlanır. Ayrılmış sunucuda çalışma kümesini taşıyacak değer seçilir; paylaşımlı hostingte körlemesine `512M` zorlanmaz.
5. Soğuk ve sıcak cache için ana sayfa, katalog, ürün ve checkout TTFB değerleri ayrı ölçülür; WooCommerce beşli smoke `5/5` geçmeden cache ayarı kabul edilmez.

Bu kapı tamamlanmadan “Redis/sayfa cache/MySQL ayarı hazır” yazılmaz; üretim katmanı yerel `docker-compose.yml` üzerinden tahmin edilmez.
