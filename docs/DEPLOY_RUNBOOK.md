# Kuka Island üretim aktarım runbook'u

Bu belge Local → Staging → Production sırasını tarif eder. Canlıya alma, alan adı ve iyzico anahtarları mağaza sahibinin yetkisindedir; bu depodaki komutlar kendiliğinden deploy yapmaz.

## 1. Yayın öncesi kapı

1. `git pull --ff-only`, `composer install --no-dev` ve `make verify` çalıştırılır; yalnızca `VERIFY=PASS` ile devam edilir.
2. `wp core version`, `wp plugin list` ve `wp theme list` çıktıları saklanır. Beklenen sürümler `scripts/install.sh` içindeki pinlerle aynı olmalıdır.
3. Kaynak ve hedef veritabanlarının, `wp-content/uploads` dizininin ve hedefteki mevcut tema/eklenti dosyalarının zaman damgalı yedeği alınır.
4. Bakım penceresi duyurulur; staging üzerinde ödeme canlı anahtarı kullanılmaz.

## 2. Dosyaların aktarımı

Yalnızca proje sahipli şu dizinler paketlenip SFTP/SSH veya hosting dosya yöneticisiyle aktarılır:

- `wp-content/themes/kuka-island-child/`
- `wp-content/plugins/kuka-island-core/`

Blocksy, WooCommerce ve iyzico hedefte `scripts/install.sh` içindeki sabit sürümlerle kurulur. Vendor dosyaları bu depodan kopyalanmaz ve düzenlenmez. Dosya izinleri dizinlerde `755`, dosyalarda `644`; sahiplik hosting PHP kullanıcısında olmalıdır.

## 3. Veritabanı ve URL dönüşümü

1. Kaynak SQL dışa aktarılır: `wp db export kuka-before-deploy.sql`.
2. Hedefin mevcut SQL yedeği alınır, ardından kaynak SQL içe aktarılır.
3. Serileştirilmiş veriyi bozmamak için düz metin `sed` yerine WP-CLI kullanılır:
   `wp search-replace 'http://localhost:8080' 'https://alanadi.example' --all-tables-with-prefix --precise --skip-columns=guid`
4. `wp option update home 'https://alanadi.example'` ve `wp option update siteurl 'https://alanadi.example'` çalıştırılır.
5. `wp rewrite flush --hard`, ardından `wp cache flush` çalıştırılır.

## 4. Medya

`wp-content/uploads/` bütünü korunarak aktarılır. Dosya sayısı ve toplam byte kaynak/hedefte karşılaştırılır; en az hero, ürün ana görselleri, varyasyon galerileri, logo ve sosyal paylaşım görseli HTTP 200 ile açılmalıdır. `seed-media/`, `app-reference/`, `data-reference/` ve `lib-reference/` üretime gönderilmez.

## 5. Üretim `wp-config.php`

- `WP_DEBUG`, `WP_DEBUG_DISPLAY` ve `WP_DEBUG_LOG` kapalıdır.
- Benzersiz WordPress salts/keys üretilir; yerel değerler taşınmaz.
- `define( 'DISALLOW_FILE_EDIT', true );` eklenir.
- Veritabanı parolası ve iyzico anahtarları yalnız hosting secret/env alanında tutulur; Git'e veya panel notuna yazılmaz.
- HTTPS zorlanır, dosya izinleri kontrol edilir ve kullanılmayan yönetici hesapları kaldırılır.
- Gerçek lansman onayı verilene kadar Ayarlar → Okuma → “Arama motorlarının bu siteyi indekslemesine engel olmaya çalış” açık kalır (`blog_public=0`). Lansman anında bilinçli olarak açılır.

## 6. E-posta ve ödeme

Hosting üzerinde alan adına ait SMTP hesabı bir SMTP eklentisiyle tanımlanır. SPF, DKIM ve DMARC kayıtları doğrulanır; parola sıfırlama, yeni sipariş ve yönetici bildirimi gerçek teslimat testi yapılır. iyzico önce sandbox/test bilgileriyle doğrulanır; canlı anahtarlar yalnız mağaza sahibi onayı ve iyzico aktivasyonu sonrası secret alana girilir. Ödeme akışı özel kodla değiştirilmez.

## 7. Yayın sonrası doğrulama

- Ana sayfa, katalog, filtre, ürün ve checkout HTTPS/200 açılır; karma içerik yoktur.
- Stok dışı varyasyon pasiftir; stoklu doğru varyasyon sepete eklenir.
- Sepet toplamı, 1.500 TL ücretsiz kargo eşiği ve checkout kargo yöntemi aynıdır.
- İki yasal onay verilmeden ödeme düğmesi pasiftir; kurumsal alanlar JS kapalıyken görünürdür.
- Shop Manager hesabı ürün, sipariş ve Kuka Island ekranlarına erişir; eklenti/tema düzenleyemez.
- Canonical, robots, sitemap, favicon, OG görseli, 404 ve yönlendirmeler kontrol edilir.
- SMTP testleri ve kontrollü test siparişi tamamlanır; gerçek lansmanda `blog_public=1` ayrıca onaylanır.

## 8. Geri dönüş

Kritik hata halinde trafik bakım sayfasına alınır. Yeni child tema ve core eklenti dizinleri sürüm etiketli önceki paketle değiştirilir; gerekiyorsa yayın öncesi SQL ve `uploads` yedeği geri yüklenir. `siteurl`/`home` tekrar doğrulanır, cache temizlenir ve beş smoke akışı yeniden çalıştırılır. Hata nedeni ile geri alınan Git commit'i kayıt altına alınır; veri kaybı şüphesinde yeni sipariş alınmadan mağaza sahibine bildirilir.
