# DHL Kargo Aktivasyon Rehberi

Bu rehber `kuka-island-shipping-automation` eklentisini pasif teslim
durumundan otomatik durum takibine kadar **adım adım** taşır. Her aşama kendi
kanıtını üretir ve bir sonraki aşama ancak o kanıt varsa açılır.

Neden kademeli: taşıyıcıda oluşturulan bir gönderi **dışa dönük** bir işlemdir.
Etiket basılır, ücret tahakkuk eder, paketi biri iptal etmek zorunda kalır. Bu
yüzden hiçbir aşama bir sonrakini otomatik açmaz.

Teknik sözleşme: [DHL_ENTEGRASYONU.md](DHL_ENTEGRASYONU.md).
Bakım kayıtları: [DHL_BAKIM_HAFIZASI.md](DHL_BAKIM_HAFIZASI.md).

---

## Aşama 0 — Pasif teslim (mevcut durum)

Eklenti pakette bulunur fakat **etkin değildir**. Pasifken WordPress bu
eklentinin **hiçbir dosyasını yüklemez**; dolayısıyla:

- kargo sınıfları ve bağımlılıkları yüklenmez
- sipariş ekranındaki kargo paneli görünmez
- hiçbir hook kayıt edilmez
- HTTP istemcisi kurulmaz, hiçbir token istenmez
- siparişlere kargo metası yazılmaz
- Action Scheduler işi oluşmaz

WooCommerce sipariş, ödeme, manuel fatura ve **manuel kargo** süreçleri aynen
çalışır. Operatör kargo çekmecesinden takip numarasını elle girmeye devam eder.

**Doğrulama:**

```bash
make verify
```

Beklenen (gerçek WordPress runtime'ında ölçülür, kaynak taramasıyla değil):

```
SHIPPING_PASSIVE_PLUGIN_STATE=PASS|measured:wordpress_runtime|plugin_file_present:yes|plugin_active:no|...
SHIPPING_PASSIVE_CLASSES_ABSENT=PASS|checked:12|declared:none|http_client_loadable:no
SHIPPING_PASSIVE_HOOKS_ABSENT=PASS|own_hooks_registered:none|module_callbacks_on_shared_hooks:0
SHIPPING_PASSIVE_ACTIONS_ABSENT=PASS|by_hook:0|by_group:0
SHIPPING_PASSIVE_ORDER_LIFECYCLE=PASS|transitions:processing->completed|shipping_meta_keys:none|actions_booked:0
SHIPPING_PASSIVE_MANUAL_ROUTE=PASS|created:yes|provider:dhl|tracking_number:stored|fulfilled:yes|automation_marker:absent
SHIPPING_PASSIVE_CORE_INTACT=PASS|core_files_referencing_shipping_plugin:0|dependency_direction:shipping_to_core_only
SHIPPING_DRAWER_PROTECTION_INTACT=PASS|core_rule_present:yes|forbidden_patterns_in_shipping_plugin:0|...
```

Aktivasyon ve deaktivasyonun kendisi de ölçülür. Dosyaları doğrudan `require`
etmek plugin bootstrap'ini kanıtlamaz;
`scripts/verify-shipping-activation-lifecycle.sh` gerçek
`wp plugin activate` / `deactivate` yolunu kullanır, her durumu **yeni bir
WordPress sürecinde** ölçer ve başlangıç durumunu başarısızlıkta bile geri
yükler:

```
SHIPPING_LIFECYCLE_ACTIVATION=PASS|active:yes|composition_root:loaded|booted:yes|missing_deps:none|classes_absent:none|hooks_unregistered:none|order_status_routes:none|runtime_gate_open:yes|automation:off|poll_actions:0
SHIPPING_LIFECYCLE_DEACTIVATION=PASS|classes_declared:none|hooks_registered:none|pending_poll_actions:0|shipping_meta_preserved:0|runtime_gate_closed:yes|core_works:yes
```

`order_status_routes:none` satırı önemlidir: aktivasyon bile bir sipariş
durumundan taşıyıcıya giden yol **açmaz**.

---

## Aşama 1 — Sözleşme doğrulaması

Kod satıcının dokümanına uyuyor mu?

```bash
./scripts/verify-dhl-openapi-contract.sh
```

Beklenen:

```
DHL_OPENAPI_CONTRACT=PASS|checksums:5/5|documents:5|operations_declared:21|operations_used:13|status_codes:8|host:pinned|base_paths:matched
```

`FAIL|reason:checksum_mismatch` görürseniz **durun**. Satıcı dokümanı
değişmiştir; önce yeni dokümanı okuyup farkı çıkarın, sonra kodu güncelleyin.
`SKIPPED|reason:spec_directory_absent` yalnız dokümanların bulunmadığı bir
makinede beklenir.

---

## Aşama 2 — Kimlik dosyasını tamamlama

Şu anda dosyada API ağ geçidi çifti vardır, Identity çifti yoktur:

```bash
./scripts/dhl-test-credentials.sh --status
```

```
DHL_TEST_CREDENTIALS=PRESENT|mode:600|path_outside_repo:yes|git_reachable:no
  KUKA_DHL_SANDBOX_CLIENT_ID=supplied
  KUKA_DHL_SANDBOX_CLIENT_SECRET=supplied
  KUKA_DHL_SANDBOX_CUSTOMER_NUMBER=absent
  KUKA_DHL_SANDBOX_PASSWORD=absent
```

Eksik iki değeri eklemek için:

```bash
./scripts/dhl-test-credentials.sh
```

- Hiçbir şey ekrana yazılmaz, kabuk geçmişine girmez, argüman olarak
  geçirilmez.
- **Boş bırakılan alan mevcut değeri korur.** Mevcut client id/secret'ı
  kaybetmemek için sadece son iki soruyu doldurun.
- Dosya atomik olarak yazılır; yarıda kesilen çalışma dosyayı bozmaz.

Bu iki değer olmadan **hiçbir dış çağrı yapılmaz** ve araç bunu söyler:

```
DHL_SANDBOX_CREDENTIALS=INCOMPLETE|present:2/4|missing:KUKA_DHL_CUSTOMER_NUMBER,KUKA_DHL_PASSWORD
DHL_SANDBOX_CONNECTION=BLOCKED|reason:credentials_incomplete|external_calls:0
```

---

## Aşama 3 — Salt-okunur bağlantı testi

**Bu aşama hiçbir şey oluşturmaz.** Yalnız token alır ve il/ilçe listelerini
okur. Yazma yoluna kod seviyesinde erişimi yoktur.

```bash
./scripts/dhl-test-run.sh test-dhl-sandbox.php
```

Bu, kimlik dosyasını salt-okunur mount eder ve taşıyıcıya **gerçekten**
bağlanır. `make verify` bu komutu hiçbir koşulda çalıştırmaz; izin listesi
kararına ihtiyaç duyduğunda çevrimdışı modu kullanır:

```bash
./scripts/dhl-test-run.sh --check-script=test-dhl-sandbox.php
```

Çevrimdışı mod yalnız "bu ad izin listesinde mi?" sorusunu yanıtlar. Kimlik
dosyasını okumaz, mount etmez, Docker başlatmaz, PHP çalıştırmaz, ağa çıkmaz.
Aşama 3'te **birinci** komutu kullanın; ikincisi bir teşhis aracıdır.

Beklenen:

```
DHL_SANDBOX_CREDENTIALS=READY|present:4/4|missing:none
DHL_SANDBOX_CONFIG=READY|environment:test|live_blocked:no|ready:yes|automation:off|cod:off|tracking_number_source:unmeasured
DHL_SANDBOX_IDENTITY=PASS|operation:authenticate|outcome:success|code:none|http:200|token_stored_in_database:no|token_printed:no
DHL_SANDBOX_CBS_CITIES=PASS|operation:get_cities|outcome:success|code:none|http:200|count:81
DHL_SANDBOX_CBS_DISTRICTS=PASS|operation:get_districts|outcome:success|code:none|http:200|count:...
DHL_SANDBOX_CACHE_CLEARED=PASS|entries_removed:2
DHL_SANDBOX_CONNECTION=PASS|read_only:yes|orders_created:0|barcodes_created:0|shipments_touched:0
```

Bu turda **not edilmesi gereken iki ölçüm** vardır:

1. `DHL_SANDBOX_IDENTITY` başarısız ve `code:unauthorized` ise
   `Authorization` başlığı biçimi ya da müşteri numarası/parola yanlıştır.
   Başlık biçimi için `KUKA_DHL_AUTHORIZATION_SCHEME=raw` denenir
   (bkz. bakım hafızası **Ö-01 — `Authorization` başlığının biçimi**).
2. `DHL_SANDBOX_CBS_CITIES` `code:unauthorized` verirse CBS uçları dokümanda
   yazmasa da token istiyor demektir (bkz. bakım hafızası
   **Ö-02 — CBS uçlarının token isteyip istemediği**).

Her iki sonucu da bakım hafızasına yazın. Bunlar **ölçülecek** maddelerdir,
tahmin edilecek değil.

---

## Aşama 4 — Eklentiyi etkinleştirme

Aşama 1–3 geçmeden buraya gelinmez.

```bash
docker compose run --rm wp-cli wp plugin activate kuka-island-shipping-automation
```

Etkinleştirme:

- yalnız çalışma kapısını açar ve sürüm yazar
- **hiçbir API çağrısı yapmaz**
- **hiçbir gönderi oluşturmaz**
- mevcut siparişleri kuyruğa almaz
- otomasyonu açmaz

`wp-config.php` içine dört kimlik sabitini ekleyin. Değerleri buraya yazarken
dosyanın repo dışında olduğundan ve sunucuda okunamayacağından emin olun:

```php
define( 'KUKA_DHL_ENVIRONMENT', 'test' );
define( 'KUKA_DHL_CLIENT_ID', '...' );
define( 'KUKA_DHL_CLIENT_SECRET', '...' );
define( 'KUKA_DHL_CUSTOMER_NUMBER', '...' );
define( 'KUKA_DHL_PASSWORD', '...' );
```

Sipariş ekranında **Kargo Otomasyonu** paneli görünür. Panelde durum, referans
ve — kimlik tamsa — `DHL gönderisi oluştur` düğmesi bulunur. Panel her durumda
şu cümleyi taşır:

> Manuel kargo yolu her zaman açıktır: WooCommerce kargo çekmecesinden takip
> numarasını elle girebilirsiniz.

---

## Aşama 5 — Tek sandbox gönderisi (ayrı onay gerekir)

**Bu aşama taşıyıcıda gerçek bir sandbox gönderisi oluşturur.** Kullanıcının o
tura ait açık onayı olmadan çalıştırılmaz.

Araç oluştur → sorgula → iptal zincirini birlikte yürütür. İptali ayırmak,
taşıyıcıda birinin kapatması gereken canlı bir paket bırakmak demektir.

```bash
./scripts/dhl-sandbox-run.sh --order=<sipariş-id> --confirm=TEK-SANDBOX-GONDERISI-ONAYLIYORUM
```

Onay cümlesi eksik ya da yanlışsa hiçbir çağrı yapılmaz:

```
DHL_SANDBOX_RUN=BLOCKED|reason:confirmation_phrase_missing_or_wrong|external_calls:0
```

Beklenen başarı:

```
DHL_SANDBOX_CREATE=PASS|state:shipment_created|code:none|shipment_id_present:yes|barcodes:1|...
DHL_SANDBOX_QUERY=PASS|lifecycle:in_progress|stored_code:1|...
DHL_SANDBOX_CANCEL=PASS|state:cancelled|code:none|...
DHL_SANDBOX_SHIPMENT=PASS|created:1|queried:1|cancelled:1|left_at_carrier:0
```

**Bu turda ölçülmesi gereken asıl şey:** `shipmentId` ve `barcodes[].value`
değerlerinden hangisinin taşıyıcının kendi takip ekranında çalıştığı. Bunu
taşıyıcı panelinden veya `trackingUrl` bağlantısından doğrulayın ve sonucu
bakım hafızasına yazın.

`DHL_SANDBOX_CREATE=FAIL` ve durum `reconcile_required` ise: **yeniden
denemeyin.** Sipariş ekranındaki `Mutabakat sorgusu çalıştır (salt-okunur)`
düğmesini kullanın.

`DHL_SANDBOX_CANCEL=FAIL` ise taşıyıcıda gönderi hâlâ var olabilir; taşıyıcı
panelinden elle kontrol edin.

---

## Aşama 6 — Takip numarası kaynağını sabitleme

Aşama 5'te hangi değerin gerçekten takip ettiği ölçüldüyse:

```php
define( 'KUKA_DHL_TRACKING_NUMBER_SOURCE', 'shipment_id' ); // veya 'barcode'
```

Bu sabit yokken fulfillment kaydının takip numarası alanı **boş** kalır ve
siparişe şu not düşülür:

> Fulfillment kaydı yazıldı. Takip numarası alanı boş bırakıldı: taşıyıcı
> yanıtındaki hangi değerin WooCommerce takip numarası olduğu sandbox ölçümüyle
> doğrulanmadı.

Ölçmeden bu sabiti yazmayın. Takip etmeyen bir numara müşteri e-postasına ve
destek konuşmalarına girer.

---

## Aşama 7 — Otomatik durum takibi (son aşama)

Otomasyon **yalnız durum sorgusu zincirini** açar. Hiçbir seviyede gönderi
kendiliğinden oluşturulmaz.

```php
define( 'KUKA_SHIPPING_AUTOMATION', true );
```

Açıkken:

- gönderi oluşturulduğunda ilk sorgu planlanır
- aralık 15 dk → 30 dk → 1 sa → 2 sa → 4 sa → 8 sa → 12 sa → 24 sa büyür
- en fazla 10 sorgu, en fazla 14 gün
- kod 5 (teslim) veya 6/7/8/tanınmayan geldiğinde zincir **durur**
- kod ≥ 2'de WooCommerce fulfillment `fulfilled` olur, kod 5'te teslim kaydı
  düşülür

Kapatmak için sabiti kaldırın veya `false` yapın; uçuştaki zincir bir sonraki
adımda durur.

**Doğrulama:**

```
SHIPPING_POLL_POLICY=PASS|ladder:15m,30m,60m,120m,240m,480m,720m,1440m|monotonic:yes|max_attempts:10|max_elapsed_days:14|terminal_stops:yes|automation_default:off
SHIPPING_NO_SCHEDULER_RESIDUE=PASS|pending_by_group:0|pending_by_hook:0|automation_off_books_nothing:yes
```

---

## Açılmayan kapılar

| Kapı | Durum | Açılması için |
| --- | --- | --- |
| Canlı ortam (`KUKA_DHL_ENVIRONMENT=live`) | **bloke** | Doğrulanmış üretim base URL'i `DHL_Config` sınıfına eklenmeli. Boolean çevirerek açılmaz. |
| Kapıda ödeme (`KUKA_DHL_COD_ENABLED`) | **bloke** | Toplanan paranın mutabakatı, mağazaya ulaşımı ve WooCommerce ödeme kaydının durumu yazılı olarak belirlenmeli. |
| Otomatik gönderi oluşturma | **yok** | Kapsam dışıdır. Bu tur boyunca gönderi yalnız operatörün açık işlemiyle oluşur. |
| SMS tercihleri | **kapalı (0,0,0)** | Taşıyıcı mağaza adına müşteriye mesaj atar; rıza kaydı gerekir. `kuka_island_shipping_request` filtresiyle açılır. |

---

## Geri alma

```bash
docker compose run --rm wp-cli wp plugin deactivate kuka-island-shipping-automation
```

Deaktivasyon:

- çalışma kapısını **önce** kapatır (uçuştaki bir worker'ı hook silmek
  durduramaz, option durdurur)
- yalnız kendi bekleyen action'larını iptal eder
- sipariş metasına, referans geçmişine, barkodlara, fulfillment kayıtlarına ve
  başka eklentinin işlerine **dokunmaz**

Deaktive edilmiş bir entegrasyonun kendi denetim kaydını silmesi, operatörü
"taşıyıcıya ne gitmişti?" sorusuna cevapsız bırakırdı — kapatıldıktan sonra
önemli olan tek soru budur.
