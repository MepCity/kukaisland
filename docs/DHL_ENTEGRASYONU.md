# DHL eCommerce Türkiye Kargo Entegrasyonu — Teknik Sözleşme

Bu belge `kuka-island-shipping-automation` eklentisinin **güncel teknik
sözleşmesi**dir. Aktivasyon: [DHL_AKTIVASYON_REHBERI.md](DHL_AKTIVASYON_REHBERI.md).
Bakım kayıtları: [DHL_BAKIM_HAFIZASI.md](DHL_BAKIM_HAFIZASI.md).

**Bu dosyaya asla yazılmaz:** client id, client secret, müşteri numarası,
parola, JWT, istek/yanıt gövdesi.

---

## 1. Ne yapar, ne yapmaz

Eklenti WooCommerce siparişini taşıyıcıda kargo gönderisine dönüştürür ve
gönderinin durumunu sınırlı bir zincirle takip eder.

**Yapmaz:**

- Sipariş durumu değiştiğinde kendiliğinden gönderi oluşturmaz. Hiçbir
  `woocommerce_order_status_*` kancasına bağlı değildir.
- Belirsiz bir yanıttan sonra isteği tekrarlamaz.
- Kapıda ödeme gönderisi oluşturmaz.
- Canlı ortama çağrı yapmaz.
- Manuel kargo yolunu kapatmaz. Operatör her zaman WooCommerce kargo
  çekmecesinden elle takip numarası girebilir.

## 2. Katmanlar

```
WooCommerce siparişi
        │
        ▼
Kuka_Island_Shipping_Manager        kapılar, kilit, mutabakat, durum geçişleri
        │
        ├── Kuka_Island_Shipping_Order_Store      HPOS uyumlu sipariş metası
        ├── Kuka_Island_Shipping_Fulfillment_Writer  WooCommerce Fulfillments
        ├── Kuka_Island_Shipping_Status_Poller    Action Scheduler zinciri
        │
        ▼
Kuka_Island_Shipping_Carrier_Interface           taşıyıcıdan bağımsız sözleşme
        │
        ▼
Kuka_Island_Shipping_DHL_Provider                tek adaptör
        ├── DHL_Config          fail-closed yapılandırma, endpoint allow-list
        ├── DHL_Client          HTTP çağrıları, yanıt ayrıştırma
        ├── DHL_Token_Store     süreç içi JWT oturumu
        ├── DHL_Address_Resolver CBS il/ilçe çözümü ve önbellek
        └── DHL_Order_Mapper    satıcı alan adları ve sayısal kodları
```

Taşıyıcıya özgü hiçbir bilgi `Manager` ve üstüne çıkmaz. `Manager` `'package'`,
`'sender'`, `'to_address'` gibi anlamsal jeton kullanır; bunları `3`, `1`, `1`
sayılarına çevirmek adaptörün işidir.

### 2.1 Sipariş–taşıyıcı sahipliği

Bir siparişte taşıyıcı işlemi başladıktan sonra o kayıt, mağazanın **sonradan
değişebilecek** varsayılan taşıyıcısına göre yönlendirilmez. Sahiplik siparişin
kendi metasındadır (`_kuka_shipping_provider`) ve tek bir yerden çözülür:
`Manager::carrier_ownership()`.

Üç durum vardır ve **yalnız biri** varsayılanı kullanabilir:

| Durum | Koşul | Sonuç |
| --- | --- | --- |
| **pinned** | siparişte provider yazılı | O taşıyıcı kullanılır. Açıkça verilen `carrier_key` çelişirse `shipment_provider_mismatch` ile reddedilir; dış çağrı 0, siparişin taşıyıcısı değişmez. |
| **orphaned** | taşıyıcı kanıtı var, provider yok | `shipment_provider_missing` ile reddedilir; dış çağrı 0. Varsayılan **kullanılmaz**: hangi kuryenin paketi tuttuğunu kimse bilmiyor ve varsayılan, paketi olan bir tahmindir. |
| **untouched** | hiç dış çağrı yapılmamış | Yalnız burada `KUKA_SHIPPING_DEFAULT_CARRIER`/filtre karar verir, ve karar ilk yazmadan **önce** sabitlenir. |

"Taşıyıcı kanıtı" (`Order_Store::has_carrier_evidence()`): `order_created`,
`shipment_created`, `reconcile_required`, `absent_confirmed`, `delivered`,
`manual_review`, `cancelled` durumlarından biri; veya dolu `shipment_id`,
`orderInvoiceId`, sıfırdan farklı durum kodu, ya da kayıtlı barkod. **Referansın
tek başına var olması kanıt değildir** — referans yerel olarak, hiçbir şey
gönderilmeden basılır.

**Sabitleme geçerli istekten sonra, ilk yazmadan hemen önce, tek save içinde —
ve tek başına yeterli değildir.** Sıra şudur:

1. Mutation kilidi alınır.
2. Taze sipariş üzerinden sahiplik/varsayılan taşıyıcı çözülür.
3. Referans yalnız **yerel olarak** hazırlanır (`prepare_reference()`, hiçbir
   şey yazmaz).
4. `build_request()` ve bütün yerel doğrulamalar tamamlanır.
5. Kapı yeniden kontrol edilir; **açıksa** `Order_Store::begin_mutation()`
   çağrılır: provider + referans + referans geçmişi **ve** siparişin o
   operasyona ait korumalı durumu **ve** ne yapılacağını tarif eden kalıcı
   intent kaydı **tek save** ile yazılır.
6. Aynı metot siparişi **veritabanından taze olarak geri okur** ve kritik
   alanların tamamını `!==` ile karşılaştırır.
7. Doğrulama `ok` dönerse — ve yalnız o zaman — yazma yapılır.

6 ve 7 arasında hiçbir yerel başarısızlık yolu yoktur ve 5'in sonucu
kontrol edilmeden 7'ye geçilemez: `guarded_write()`'ın dördüncü argümanı
**zorunludur** ve `begin_mutation()`'ın verdiği doğrulamayı döndürür (§2.2).

**"Provider ve referansı sabitlemek yeterlidir" ifadesi yanlıştı ve
kaldırılmıştır.** Provider anahtarı bir yazmanın *kime* ait olduğunu söyler;
*başladığını* söylemez. `states_blocking_create()` durum üzerinden karar
verirdi, durum ise cevap geldikten sonra yazılıyordu — cevap hiç gelmezse
(fatal, OOM, deploy, kopan veritabanı bağlantısı) yazan kod yolu hiç çalışmaz ve
sipariş `none` durumunda kalırdı. Bu yüzden 5'te **durum da** taşınır: bkz. §7.0
ve K-29.

Neden 4'ten önce değil: adresi eşleşmeyen bir sipariş — hiçbir taşıyıcıya çağrı
yapılmamış, tamamen yerel bir başarısızlık — eskiden kendisini hiç duymamış bir
kuryeye kalıcı olarak bağlanmış hâlde çıkıyordu ve başka bir kuryeye
verilemiyordu. Neden 7'den sonra değil: timeout olan bir `createOrder` kimin
sorulduğunu kaydetmemiş olurdu (K-21).

Provider eskiden yalnız `save_order_created()` tarafından, yani taşıyıcının
**onayladığı** bir `createOrder`dan sonra yazılıyordu; timeout olan bir
`createOrder` bu yüzden kimin sorulduğunu kaydetmeden `reconcile_required`da
bırakıyor, mutabakat da o an varsayılan olan taşıyıcıyı okuyordu. Sabitleme
önce yapıldığı için pin timeout'tan sağ çıkar — ve intent kaydı süreç
ölümünden de sağ çıkar.

**Taşıyıcı kanıtı nedir.** `has_carrier_evidence()` "bu referans altında
**bir** taşıyıcıya istek gönderilmiş mi?" sorusunu cevaplar, ve kanıt listesi şu
durumları içerir: `order_created`, `shipment_created`, `reconcile_required`,
`cancel_reconciliation_required`, `update_reconciliation_required`,
`absent_confirmed`, `delivered`, `manual_review`, `cancelled` — artı **dolu bir
`pending_mutation` kaydı**, durum ne derse desin. Çıplak bir referans kanıt
**değildir**: `prepare_reference()` onu yerel olarak, hiçbir şey gönderilmeden
üretir.

İki korumalı durum bu listeye sonradan eklendi (K-37) ve en güçlü kanıttır:
bir sipariş oraya yalnız `begin_mutation()` üzerinden girer. Eksik oldukları
sürece, sahipsiz bir "iptal sonucu doğrulanıyor" kaydı mağazanın **güncel**
varsayılan taşıyıcısına düşüyordu.

**Üzerine yazılmaz.** Zaten yazılı bir provider, çağrıcı ne gönderirse
göndersin olduğu gibi kalır. Canlı bir siparişi başka bir kuryeye yöneltmek bu
kodun yapabileceği bir şey değildir.

Sabitlendikten sonra **resume, mutabakat, sorgu, poller, güncelleme, iptal ve
iptal doğrulama okumaları** yalnız bu kayıtlı provider üzerinden yürür. Yönetim
paneli de aynı soruyu sorar; varsayılan yalnız işlem görmemiş siparişte
kullanılır. İkinci taşıyıcı desteği yine yalnız adaptör eklemekle sınırlıdır ve
eski kayıtların sahipliği korunur.

Her mutation kilidi alındıktan sonra sipariş yeniden okunur ve **sahiplik
yeniden doğrulanır**; kilit öncesi snapshot'a güvenilmez.

Ölçüm: `SHIPPING_PROVIDER_AFFINITY`, `SHIPPING_PROVIDER_AFFINITY_RESUME`,
`SHIPPING_PROVIDER_PINNED_BEFORE_FIRST_WRITE`,
`SHIPPING_UNTOUCHED_ORDER_USES_DEFAULT`,
`SHIPPING_UNCERTAIN_CREATE_RETAINS_PROVIDER`,
`SHIPPING_PROVIDER_MISMATCH_FAILS_CLOSED`,
`SHIPPING_LEGACY_MISSING_PROVIDER_FAILS_CLOSED`,
`SHIPPING_PROVIDER_FRESH_UNDER_LOCK`, `SHIPPING_ADMIN_USES_STORED_PROVIDER`.
Hepsi iki kayıt tutan adaptörle ölçülür ve her adaptörün okuma/yazma sayacı
ayrı raporlanır.

### 2.2 Yazma ve okuma sınırı: iki katman, iki boğaz

Taşıyıcıya yazan **altı** operasyon vardır: `create_order`, `create_barcode`,
`update_order`, `update_shipment`, `cancel_order`, `cancel_shipment`.
Taşıyıcıdan okuyan **üç** operasyon vardır: `read_shipment`, `read_order`,
`read_shipment_status`.

**Katman 0 — `Manager::carrier_ownership()`.** §2.1. Her operasyonun ilk
sorusu.

**Katman 1 — `Manager::carrier_gate()`.** Her taşıyıcı operasyonunun —
**okumalar dâhil** — geçtiği ortak sınır: çalışma kapısı açık mı, ortam bloke
değil mi, kimlikler tam mı. Bunlardan biri sağlanmıyorsa dış çağrı sayısı
**0**'dır. `reconcile_order()` ve `query_status()` bu kapıyı eskiden hiç
kontrol etmiyordu.

**Katman 2 — `Manager::create_policy()`.** Yalnız **oluşturma** ve **barkod
sürdürme** işlemlerinin tabi olduğu iş kuralı: kapıda ödeme. Bu kural bilinçli
olarak katman 1'in dışındadır. Kapıda ödeme kontrolünü iptale de uygulamak, COD
siparişini **geri alınamaz** hâle getirirdi; tehlikeli olan COD siparişini
peşin gibi kargolamaktır, iptal etmek çözümdür.

**Yazma boğazı — `Manager::guarded_write()`.** Altı yazmanın tamamı bu metottan
geçer ve başka hiçbir yerden geçmez. Metot, taşıyıcıya dokunmadan **hemen önce**
katman 1'i yeniden sorar. Giriş kontrolü kilit alınmadan önce yapılmıştı; o
noktayla yazma arasında operatör eklentiyi devre dışı bırakabilir — çalışma
kapısı tam olarak bu senaryo için vardır. `Runtime_Gate::is_disabled()` her
çağrıda option tablosundan doğrudan okur, bu yüzden ikinci okuma birincinin
tekrarı değildir.

**Yazma boğazının ikinci koşulu: kalıcı niyet.** `guarded_write()`'ın dördüncü
argümanı **zorunludur** ve `void` değildir: `Order_Store::begin_mutation()`
sonucunu döndürür. Bu argüman eskiden yalnız provider'ı sabitleyen bir `void`
callback'ti; sonucunu hiçbir şey kontrol etmediği için **kaydedilemeyen bir
sabitleme ile kaydedilen bir sabitleme ayırt edilemiyordu** ve istek yine
gidiyordu. Artık:

1. `begin_mutation()` siparişi **o operasyonun korumalı durumuna** taşır ve
   yapılacak işin tam tarifini (`mutation_id`, `kind`, `operation`, `target`,
   `previous_state`, `provider`, `reference`, güncellemeler için `expected`,
   `created_at`) **tek save** ile yazar.
2. Aynı metot siparişi **veritabanından taze olarak yeniden okur** — her önbellek
   düşürülür, meta okuması zorlanır — ve kritik alanların tamamını `!==` ile
   birebir karşılaştırır.
3. Doğrulama `ok` dönmezse `$write()` **hiç çağrılmaz**. Taşıyıcı çağrısı 0
   kalır, kod `mutation_intent_unverified` olur.

Doğrulama başarısız olduğunda sipariş korumalı durumda **bırakılır**; geri
alınmaz. Yazmalarına güvenilemeyen bir süreçte geri alma denemek, aynı
mekanizmaya yeniden güvenmek olur ve üretebileceği hata tehlikeli olanıdır:
yazma düğmesi açık bir durum. Kalan artığı `reconcile_cancellation()` okuyarak
çözer; intent kaydının kaybolduğu durum orada özel olarak ele alınır.

Ölçüm: `SHIPPING_MUTATION_INTENT_DURABLE` (altı operasyon, niyet **ayrı bir
MySQL oturumundan** okunuyor), `SHIPPING_MUTATION_CRASH_BOUNDARY` (altı
operasyon, yazmanın içinde `Throwable` ile kontrol akışı kesiliyor; yeni sipariş
nesnesi + yeni `Manager` + yeni adaptörle tekrar deneme **0** yazma yapıyor) ve
`SHIPPING_MUTATION_INTENT_UNPERSISTED_BLOCKS_WRITE` (intent satırının yazımı
sabote ediliyor; taşıyıcı çağrısı 0).

**Okuma boğazı — `Manager::guarded_read()`.** Üç okumanın tamamı buradan geçer
ve kapı yine okumadan **hemen önce** sorulur.

**Bloke okuma, kaydın yokluğu değildir.** `guarded_read()` bir `Result` değil,
bir **ret** döndürür; hiçbir çağrıcı kapalı kapıyı 404 sanamaz. Bu ayrım
okumaların ayrı boğazdan geçmesinin bütün sebebidir: `reconcile()` yokluğu
`not_found`dan kanıtlar, ve `not_found` döndüren bir kapı yokluğu **kapalı
olduğu için** kanıtlamış olurdu. Okuma bloke olursa:

- `reconcile()` → verdict `blocked`, durum `reconcile_required` kalır, hiçbir
  şey yazılmaz, yokluk varsayılmaz.
- `query_status()` → gate kodu döner; **deneme harcanmaz** (hiçbir çağrı
  yapılmadı).
- İptal doğrulaması → `cancel_unconfirmed`, durum değişmez, sorgu zinciri
  iptal edilmez.

Ölçüm: `SHIPPING_MUTATION_GATE_SHARED` (dört kapı × üç koşul, yazma 0),
`SHIPPING_GATE_RECHECKED_UNDER_LOCK` (dört kapıda `readiness` sorgusu 2, yazma
0), `SHIPPING_RUNTIME_GATE_CLOSED_MIDFLIGHT` (kapı kilit altındayken kapanıyor,
yazma 0), `SHIPPING_READ_GATE_SHARED` (üç okuma, okuma 0) ve
`SHIPPING_UNCERTAIN_READ_BLOCKED_STAYS_UNCERTAIN` (belirsiz yazmadan sonra
okuma bloke; durum belirsiz kalır, ikinci yazma yok).

**İkinci kargo firması eklemek** için tek gereken:
`Kuka_Island_Shipping_Carrier_Interface` uygulayan bir sınıf yazmak ve
`kuka_island_shipping_carriers` filtresine eklemek. Core, Manager, Order Store,
Poller, Admin ve WooCommerce değişmez.

Bu bir iddia değil, ölçümdür: `SHIPPING_SECOND_CARRIER_ADAPTER_ONLY` hiçbir DHL
sınıfına dokunmayan sahte bir adaptörü yalnız filtreye ekleyerek
oluşturma/sorgulama/iptal akışlarından geçirir ve fulfillment kaydının o
adaptörün kendi sağlayıcı anahtarıyla yazıldığını doğrular.
`SHIPPING_CORE_NAMES_NO_ADAPTER` ise ortak sınıfların kaynağında (yorumlar
ayıklandıktan sonra) tek bir `Kuka_Island_Shipping_DHL*` ya da `KUKA_DHL_`
geçmediğini sayar.

Varsayılan taşıyıcı anahtarı `Manager` içinde sabit **değildir**:
`KUKA_SHIPPING_DEFAULT_CARRIER` yapılandırmasından ya da
`kuka_island_shipping_default_carrier` filtresinden gelir. Kayıtlı olmayan bir
anahtar **olduğu gibi** döndürülür; kayıtlı bir taşıyıcıyla ikame edilmez, çağrı
yapılmadan `carrier_not_registered` ile reddedilir. Hiçbir şey yapılandırılmadıysa
tek kayıtlı adaptör kullanılır; iki veya daha fazlası varsa anahtar boştur ve
yine reddedilir.

## 3. Resmî kaynak

Yol, alan adı ve sayısal kodların **tek kaynağı** satıcının OpenAPI
dosyalarıdır:

```
~/.config/kuka-island/dhl-openapi/Identity_API-1.0.json
~/.config/kuka-island/dhl-openapi/Standard_Command_API-1.0.json
~/.config/kuka-island/dhl-openapi/Barcode_Command_API-1.0.json
~/.config/kuka-island/dhl-openapi/Standard_Query_API-1.0.json
~/.config/kuka-island/dhl-openapi/CBS_Info_API-1.0.json
~/.config/kuka-island/dhl-openapi/SHA256SUMS
```

`scripts/verify-dhl-openapi-contract.sh` bu dosyaların SHA-256 toplamlarını
doğrular ve kullanılan 13 operasyonun her birinin dokümanda **beyan edilmiş**
olduğunu ölçer.

### 3.1 Kullanılan uçlar (sandbox)

| Servis | Metot | Yol | Yazma? |
| --- | --- | --- | --- |
| Identity | POST | `/mngapi/api/token` | hayır |
| Standard Command | POST | `/mngapi/api/standardcmdapi/createOrder` | **evet** |
| Standard Command | PUT | `/mngapi/api/standardcmdapi/updateorder` | **evet** |
| Standard Command | PUT | `/mngapi/api/standardcmdapi/cancelorder/{refrenceId}` | **evet** |
| Barcode Command | POST | `/mngapi/api/barcodecmdapi/createbarcode` | **evet** |
| Barcode Command | PUT | `/mngapi/api/barcodecmdapi/updateshipment` | **evet** |
| Barcode Command | PUT | `/mngapi/api/barcodecmdapi/cancelshipment` | **evet** |
| Standard Query | GET | `/mngapi/api/standardqueryapi/getorder/{referenceId}` | hayır |
| Standard Query | GET | `/mngapi/api/standardqueryapi/getshipment/{referenceId}` | hayır |
| Standard Query | GET | `/mngapi/api/standardqueryapi/getshipmentstatus/{referenceId}` | hayır |
| Standard Query | GET | `/mngapi/api/standardqueryapi/trackshipment/{referenceId}` | hayır |
| CBS Info | GET | `/mngapi/api/cbsinfoapi/getcities` | hayır |
| CBS Info | GET | `/mngapi/api/cbsinfoapi/getdistricts/{cityCode}` | hayır |

Host tek: `testapi.mngkargo.com.tr`.

**Satıcının yazımı korunur.** `/createOrder` camelCase, `/updateorder` küçük
harf, `cancelorder` parametresi dokümanda `refrenceId` yazılıdır. Sunucu niyeti
değil yazımı uygular; bunlar düzeltilmez.

### 3.2 Canlı ortam

Beş dokümanın `x-ibm-configuration.servers` bloğunda **tek sunucu** vardır ve o
sandbox'tır. Doğrulanmış üretim base URL'i olmadığı için canlı ortam
**bloke**dir:

- `KUKA_DHL_ENVIRONMENT=live` → `endpoints()` boş dizi döner,
  `is_allowed_url()` her URL için `false` döner, her çağrı
  `live_environment_blocked` ile reddedilir.
- Blok bir boolean çevrilerek değil, `DHL_Config` sınıfına doğrulanmış üretim
  URL'i eklenerek kalkar.

## 4. Kimlik

İki bağımsız çift vardır ve **ikisi de** zorunludur:

| Amaç | Sabit | Nereye gider |
| --- | --- | --- |
| API ağ geçidi | `KUKA_DHL_CLIENT_ID` | `X-IBM-Client-Id` başlığı |
| API ağ geçidi | `KUKA_DHL_CLIENT_SECRET` | `X-IBM-Client-Secret` başlığı |
| Kargo hesabı | `KUKA_DHL_CUSTOMER_NUMBER` | `/token` gövdesi `customerNumber` |
| Kargo hesabı | `KUKA_DHL_PASSWORD` | `/token` gövdesi `password` |

`identityType` protokol sabitidir ve `1`e sabitlenmiştir; yapılandırılamaz.

Değerler **yalnız** `wp-config.php` sabitlerinden veya süreç ortamından okunur.
Option, ayar ekranı veya kaynak dosya kullanılmaz.

Dördünden biri eksikse `get_readiness_gaps()` eksik **alan adını** döndürür ve
hiçbir dış çağrı yapılmaz. Eksik alan adı raporlanır; değer hiçbir biçimde,
maskeli olarak bile raporlanmaz.

Yerel sandbox kimlik dosyası: `~/.config/kuka-island/dhl-sandbox.env`, mod
`600`, repo dışında. Konteynere yalnız `scripts/dhl-test-run.sh` ve
`scripts/dhl-sandbox-run.sh` tarafından salt-okunur bind-mount edilir; ortam
değişkeni olarak geçirilmez (`docker inspect` ile okunabilirdi).

### 4.1 Oturum

JWT **veritabanına, dosyaya, log'a veya çereze yazılmaz**. Yalnız süreç içinde,
özel bir property'de tutulur; istek bitince kaybolur.

Önbellek penceresi **sabit 5 dakika**dır. `jwtExpireDate` alanının zaman dilimi
dokümanda yoktur, bu yüzden süre hesabında kullanılmaz; yalnız **veto** olarak
kullanılır: en cömert okumayla bile geçmişte kalan bir değer önbelleği tamamen
kapatır. Yanlış tahmin edilen bir süre, salt-okunur çağrılardaki tek seferlik
yeniden kimlik doğrulamayla düzelir.

`Authorization` başlığının biçimi dokümanda yazmaz. Varsayılan `Bearer <jwt>`
sözleşmedir; `KUKA_DHL_AUTHORIZATION_SCHEME=raw` ile token tek başına
gönderilir. Bu, sandbox ölçümüyle kesinleştirilecek açık bir maddedir
(bkz. bakım hafızası Ö-01).

CBS Info dokümanı hiçbir operasyonunda `Authorization` parametresi beyan
etmez; bu yüzden CBS çağrılarında token gönderilmez (bkz. bakım hafızası Ö-02).

## 5. Durum sözlüğü

`ShipmentOUT.shipmentStatusCode` ve `TrackShipmentResponse.eventStatus`
açıklamalarından birebir alınmıştır:

| Kod | Anlam | Yaşam döngüsü | Sonuç |
| --- | --- | --- | --- |
| 1 | Gönderi hazırlandı | `in_progress` | sorgu sürer |
| 2 | Transfer aşamasında | `in_progress` | sorgu sürer, fulfillment `fulfilled` |
| 3 | Teslimat birimine ulaştı | `in_progress` | sorgu sürer, fulfillment `fulfilled` |
| 4 | Alıcı adresine yönlendirildi | `in_progress` | sorgu sürer, fulfillment `fulfilled` |
| 5 | Teslim edildi | `delivered` | zincir biter, teslim kaydı yazılır |
| 6 | Teslim edilemedi | `manual_review` | zincir biter, insana düşer |
| 7 | Geri geliyor | `manual_review` | zincir biter, insana düşer |
| 8 | Destek gerekiyor | `manual_review` | zincir biter, insana düşer |
| diğer | — | `manual_review` | zincir biter, insana düşer |

1–8 dışındaki her değer — `0`, `9`, boş dize, kelime, ondalık, `null`, dizi —
**tahmin edilmez**. Manuel incelemeye düşer.

## 6. Durum makinesi

```
none ──create_order──▶ order_created ──create_barcode──▶ shipment_created
  │                        │                                  │
  │                        │                                  ├─kod 5─▶ delivered
  │                        │                                  ├─kod 6/7/8/? ─▶ manual_review
  │                        │                                  └─cancel+doğrulama─▶ cancelled
  │                        │
  └────belirsiz yanıt──────┴──────────▶ reconcile_required
                                              │
                            ┌─────────────────┼──────────────────┐
                     okuma buldu        okuma yok dedi       okuma cevapsız
                            │                 │                  │
                    order/shipment     absent_confirmed    reconcile_required
                       _created         (yeni deneme          (kapalı kalır)
                                        açık bir işlem)
```

`states_blocking_create()` = `order_created`, `shipment_created`,
`reconcile_required`, `delivered`, `manual_review`. Bu durumlarda yeni gönderi
oluşturulmaz.

### 6.1 `order_created` durumundan çıkış

`order_created`, `create_shipment()` tarafından bloke edilir ve **doğru** bloke
edilir: `create_shipment()` `createOrder` ile başlar, tekrar çağrılması
taşıyıcıda ikinci bir sipariş kaydı üretir.

Bu durumdan çıkış ayrı bir operatör işlemidir: `Manager::resume_barcode()`,
yönetim ekranında `admin_post_kuka_shipping_resume`.

- Yalnız **tam olarak** `order_created` kabul edilir. `shipment_created`,
  `reconcile_required`, `manual_review`, `delivered`, `cancelled`,
  `absent_confirmed`, `blocked` ve `none` `not_resumable` ile reddedilir.
- Durum, `create_shipment()` ile **aynı** advisory lock içinde yeniden okunur;
  iki kapı asla üst üste binmez ve çift tıklama ikinci barkod yazmaz.
- Bu yolda tek yazma çağrısı `createbarcode`'dur. `createOrder` erişilemez.
- `createbarcode` `uncertain` dönerse tekrar edilmez; §7'deki salt-okunur
  mutabakata devredilir.
- Kendi nonce alanı vardır (`kuka_shipping_resume_<sipariş-id>`); oluşturma
  düğmesinin nonce'u burada doğrulanmaz. Yetki kontrolü (`manage_woocommerce`)
  bu işlem için bağımsız olarak yapılır.

### 6.2 Oluşturma kapıları bir izin listesidir

Taşıyıcıda kayıt yaratan **iki** operasyon vardır ve her birinin izin verilen
durumları `Order_Store` içinde tek merkezde yazılıdır:

| Operasyon | İzin veren durumlar | Liste |
| --- | --- | --- |
| `createOrder` | `none`, `blocked`, `absent_confirmed` | `states_allowing_create_order()` |
| `createbarcode` | yalnız `order_created` | `states_allowing_create_barcode()` |

Bu listeleri **dört** yer sorar ve dördü de aynı cevabı alır: create kapısı
(`create_shipment()`, kilit içinde), `run_creation()`'ın createOrder dalı,
`run_creation()`'ın barkod aşamasına **geçişi** (durum yeniden okunarak), ve
yönetim panelindeki düğme. Listede olmayan her durumda — `cancelled`,
`delivered`, `manual_review`, üç korumalı durum, ve bu sürümün hiç duymadığı bir
değer dâhil — dış yazma **0**'dır.

**Neden izin listesi.** Burası eskiden bir yasak listesiydi
(`states_blocking_create()`) ve `cancelled` o listede yoktu. İptali
**kanıtlanmış** bir sipariş kapıyı geçiyor, `run_creation()`'ın createOrder dalı
durumu kabul etmediği için atlanıyor, ve metot koşulsuz `run_barcode()` ile
bitiyordu: taşıyıcının iptal ettiği kayda `createbarcode`. Bilinmeyen bir durum
da aynı yoldan düşüyordu. Yasak listesi, yeni bir durum eklendiği ilk anda delik
verir; `states_blocking_create()` artık yalnız ret **mesajını** seçer ve hiçbir
yerde kapı değildir.

Ölçüm: `SHIPPING_CANCELLED_RECORD_IS_FAIL_CLOSED` (gerçek create → gerçek iptal
→ salt-okunur kanıt → taze sipariş + taze Manager + taze adaptör: dört kapı,
sıfır çağrı) ve `SHIPPING_CREATE_DOORS_ARE_AN_ALLOWLIST` (12 durum × 2 aksiyon,
hangi kapının açıldığı ölçülüyor, allow-list dışında hiçbiri).

## 7. Belirsizlik kuralı

Sonuçlar dört türdür, iki değil:

| Sonuç | Anlamı | İzin verilen davranış |
| --- | --- | --- |
| `success` | Taşıyıcı cevap verdi, işlem tamam | devam |
| `permanent` | Taşıyıcı hayır dedi | dur, bildir |
| `transient` | Karar oluşmadı ve işlem **okuma** | tekrar edilebilir |
| `uncertain` | İşlem taşıyıcıda gerçekleşmiş **olabilir** | **asla tekrar yok** |

Yazma çağrılarında `uncertain` üreten durumlar: bağlantı hatası/timeout,
gövdesi okunamayan 2xx, 409, 429, 5xx, 3xx ve beklenmeyen durum kodları.

`uncertain` sonrasında `Manager` şunu yapar:

1. Durumu `reconcile_required` yazar ve sipariş notu düşer.
2. **Salt-okunur** mutabakat çalıştırır: önce `getshipment`, sonra `getorder`.
3. Biri bulursa kayıt sahiplenilir, yeni yazma yapılmaz.
4. **İkisi de** `not_found` derse durum `absent_confirmed` olur.
5. Sorgular cevap veremezse durum `reconcile_required` kalır ve hiçbir şey
   gönderilmez.

Yokluk **kanıtlanır**; timeout yokluk kanıtı değildir.

### 7.0 Yazma kanıtı: kapı istek gitmeden **önce** kapanır

Bir yazma çağrısı taşıyıcıya **ulaştıktan** sonra — cevabı `success` da olsa —
aynı belge için ikinci bir yazma yapılamaz. `success`, ağ geçidinin isteği
aldığını söyler; işlemin uygulandığını söylemez.

**Kapı cevap beklenerek kapatılamaz.** Önceki tur kapıyı cevap geldikten sonra
kapatıyordu; bu, cevabın geldiği varsayımına dayanır. Süreç istek uçarken
ölürse (fatal, OOM, deploy, kopan veritabanı bağlantısı) hiçbir kod yolu geri
dönmez ve siparişi koruyabilecek tek şey **o an diskte olan**dır. Bu yüzden
`begin_mutation()` durumu **istek gitmeden önce** taşır ve niyeti diske
yazıp geri okur (§2.2).

Altı operasyonun ilk yazması sırasında siparişin durumu:

| Operasyon | İlk yazma sırasındaki durum | Kapalı kapıyı açan tek kanıt |
| --- | --- | --- |
| `create_order` | `reconcile_required` | `getshipment` + `getorder`; **ikisi de** `not_found` → `absent_confirmed` |
| `create_barcode` | `reconcile_required` | aynı |
| `update_order` | `update_reconciliation_required` | alan bazında geri okuma **birebir** eşleşirse → önceki durum; eşleşmezse → `manual_review` |
| `update_shipment` | `update_reconciliation_required` | aynı |
| `cancel_order` | `cancel_reconciliation_required` | `getorder` → `not_found` → `cancelled` |
| `cancel_shipment` | `cancel_reconciliation_required` | `getshipment` → `not_found` → `cancelled` |

Üç korumalı durumun tamamı `states_blocking_create()` içindedir ve hepsinden
çıkış **yalnız okumayla**dır.

**"Kesin ret hiçbir şeyi değiştirmemiştir" varsayımı kaldırıldı.** Önceki tur
`permanent` bir cevabı — 400, 401, 403, 404 — "istek reddedildi, hiçbir şey
olmadı" diye okuyordu ve siparişi eski durumuna döndürüyordu. Satıcının resmî
OpenAPI belgeleri bunu **desteklemiyor**: altı yazma operasyonunun tamamı tam
olarak dört cevap tanımlar —

```
200  <Order|Shipment> created/updated/canceled
400  Bad Request
401  Unauthorized
500  Server Error
```

— ve **hiçbiri** yan etkiden söz etmez. 400'ün kayıt bırakıp bırakmadığı yazılı
değildir. Dolayısıyla "yan etkisi olmadığı belgelenmiş hata" allowlist'i
**boştur**, ve intent'i okumadan kapatan tek şey şudur:

**Adaptörün ağa çıkmadan verdiği ret.** `Result::local_refusal()` yalnız
`call()` çağrılmadan önce, soket açılmadan verilen retler için kullanılır:
eksik payload, kapıda ödeme, geçersiz referans, izin listesinde olmayan uç.
Bunlarda taşıyıcının değişmediği **kod yolundan kanıtlanır**, durum koduna
bakılarak tahmin edilmez. `Result::reached_carrier()` bu ayrımı taşır ve
`to_safe_line()` çıktısında `reached_carrier:yes|no` olarak görünür.

| Cevap | intent | Sipariş durumu |
| --- | --- | --- |
| `local_refusal` (ağa çıkılmadı) | kapatılır | `previous_state`'e döner, düğme açık |
| `success` | **açık kalır** | korumalı durumda kalır, salt-okunur kanıt beklenir |
| `permanent` (taşıyıcı cevapladı: 400/401/403/404) | **açık kalır** | korumalı durumda kalır |
| `uncertain` (timeout, 409, 429, 5xx, okunamayan 2xx) | **açık kalır** | korumalı durumda kalır |

**Yalnız alındı olan bir `success` uygulanmış sayılmaz.** Bu özellikle
güncellemede önemlidir: `updateshipment` 200 dönse bile hangi alanları aldığı
yazılı değildir, bu yüzden durum `update_reconciliation_required`'da kalır ve
yalnız alan bazında geri okuma onu çözer. DHL adaptörü bu geri okumayı
yapamadığı için (`readback_unsupported`) her DHL güncellemesi doğrulanmamış
kalır — ve bu, gizlenecek bir kusur değil, sözleşmenin dürüst sonucudur.

**Doğrulanmamış bir güncelleme paketi ulaşılmaz yapmaz.** İkinci **güncelleme**
reddedilir (doğru), fakat **iptal** `update_reconciliation_required` durumundan
da yapılabilir: hangi nesnenin adreslendiği güncellemenin kendi intent
kaydındaki `previous_state`'ten okunur. Aksi hâlde operatör paketi ne
düzeltebilir ne durdurabilir hâlde kalırdı.

**Sonuç geçişleri tek save'dir.** `Order_Store::settle_mutation()` durumu,
`META_PENDING_MUTATION`'ın temizlenmesini, son operasyonu ve geçmiş kaydını
**aynı** `save_meta_data()` içinde yazar; `save_order_created()` ve
`save_shipment_created()` de intent'i kendi save'lerinde kapatır. Sınıftaki tek
yazma noktası `Order_Store::persist()`'tir ve sayar: `save_count()`. Ölçüm
`SHIPPING_MUTATION_OUTCOME_ATOMIC` — iptal onaylandı 1, güncelleme onaylandı 1,
güncelleme uyuşmadı 1, intent açılıp önceki duruma dönüş 2 (biri açmak, biri
kapatmak), oluşturma + barkod 4.

### 7.1 İptal: serileştirilmiş, idempotent, doğrulanmış

**Serileştirilmiş.** `cancel()` mutation kilidini alır ve durumu kilit içinde
yeniden okur. Kilit olmadan iki eşzamanlı basış ikisi de `shipment_created`
okuyup iki `cancelshipment` gönderiyordu. Kilidi alamayan çağrı **beklemez**,
`lock_contended` ile döner.

**İdempotent.** Yazabilen **tam olarak iki** durum vardır:

| Kilit içinde okunan durum | Gönderilen yazma | Doğrulayan sorgu |
| --- | --- | --- |
| `order_created` | `cancelorder` | `getorder` |
| `shipment_created` **ve** `shipment_id` dolu | `cancelshipment` | `getshipment` |

Diğer her durumda dış çağrı **0**'dır:

| Durum | Kod |
| --- | --- |
| `cancelled` | `already_cancelled` |
| `cancel_reconciliation_required` | `cancel_in_progress` |
| `none`, `blocked`, `absent_confirmed`, `reconcile_required`, `delivered`, `manual_review`, tanımsız | `not_cancellable` |
| `shipment_created` fakat `shipment_id` boş | `not_cancellable` |
| `update_reconciliation_required` **ve** intent kaydı okunamıyor | `not_cancellable` |

`update_reconciliation_required` durumu tek başına iptali engellemez: intent
kaydındaki `previous_state` hangi nesnenin adreslendiğini söylüyorsa iptal o
nesneye gönderilir (§7.0). Yalnız o kayıt da okunamıyorsa reddedilir, çünkü o
noktada adres bir tahmin olurdu.

Son satır önemlidir: gönderi vardır ama numarası bilinmiyorsa adreslenecek kayıt
yoktur, ve **onun yerine siparişi iptal etmek** yanlış nesneye istek göndermek
olur — bu modülün bir kez yaptığı hata tam olarak buydu.

Reddetme bir **izin listesiyle** yapılır, yasak listesiyle değil: yasak listesi,
yeni bir durum eklendiği ilk anda delik verir.

**Doğrulanmış.** Taşıyıcının "iptal edildi" cevabı **kanıt değildir**; yalnız
bir alındıdır. Yazma taşıyıcıya ulaşır ulaşmaz — okuma yapılmadan **önce** —
durum `cancel_reconciliation_required` olur. Sonra `reconcile_cancellation()`
yalnız okur:

| Okuma sonucu | Sonuç |
| --- | --- |
| `not_found` | `cancelled`; kanıt temizlenir; sorgu zinciri **o zaman** iptal edilir |
| Kayıt hâlâ mevcut | `cancel_unconfirmed_record_present`; durum korunur |
| Kapı kapalı, okuma yapılamadı | `cancel_unconfirmed_blocked`; durum korunur |
| Sorgu cevap veremedi | `cancel_unconfirmed`; durum korunur |

Son üç satırda **hiçbir şey** değişmez: yeni iptal gönderilmez, `order_created`
ya da `shipment_created`a **geri dönülmez**, ve sorgu zinciri iptal edilmez.

**Doğrulanmamış iptali çözen şey bir kişidir, otomatik sorgu değildir.** Kodda
bir yorum, planlı durum sorgusunun "onu izleyen tek şey" olduğunu söylüyordu.
Söylediği doğru değildi: poller'ın işçisi önce durumu okur,
`cancel_reconciliation_required` görür ve **tek bir taşıyıcı okuması yapmadan**
`state_not_pollable` dönerek biter; ardına yeni bir iş de planlamaz. Yani
planlanmış iş bırakılsa da bırakılmasa da zincir orada durur.

Planlanmış iş yine iptal **edilmez** — zamanlayıcıya ikinci bir yazma yapmak
hiçbir şey kazandırmaz — fakat sonucu açıkça yazılıdır: bu durumu **operatör**
çözer, sipariş ekranındaki "Mutabakat" düğmesiyle, ve o düğme
`reconcile_cancellation()`'ı yeniden çalıştırır. Bu modülde hiçbir zamanlayıcı
iptali yeniden denemez. Sipariş ekranındaki metin de bunu aynen söyler:
*"Otomatik durum sorgusu bu durumu ÇÖZMEZ ve yeni sorgu planlamaz."*

Ölçüm: `SHIPPING_PENDING_CANCEL_IS_MANUAL_ONLY` — işçi bir kez koşuyor,
`status_reads:0`, yeni iş planlanmıyor, operatörün okuması durumu ilerletiyor,
ve yanlış yorum kaynakta artık yok.

`cancel_reconciliation_required` durumundaki bir siparişte iptal düğmesi
`cancel_in_progress` ile reddedilir — ikinci basış, bayat sipariş nesnesi ve
eşzamanlı ikinci istek dâhil.

**Neden genel `reconcile()` kullanılamaz:** o metot bir CREATE için yazılmıştır
ve kaydı bulduğunda `shipment_created` yazar. İptalden sonra bu tam tersidir —
kaydı bulmak iptalin **kanıtlanmadığı** anlamına gelir — ve `shipment_created`
yazmak iptal düğmesini yeniden açar. Bir iptal böyle ikiye çıkar.

### 7.2 Güncelleme: aynı kilit, aynı tazelik kuralı

`update_shipment()` de mutation kilidini alır ve durumu, `shipment_id`'yi ve
referansı kilit içinde yeniden okur; istek **o okumadan** kurulur. Yazabilen iki
durum vardır — `order_created` → `updateorder`, `shipment_created` + dolu
`shipment_id` → `updateshipment` — diğer her durumda kod `nothing_to_update` ve
dış çağrı 0'dır.

Bu, "gecikmiş güncelleme" senaryosunu kapatır: iptal başarıyla tamamlandıktan
sonra, iptalden **önce** alınmış bir sipariş nesnesiyle çağrılan güncelleme,
kilit içindeki taze okuma `cancelled` gördüğü için gönderilmez.

**Hiçbir güncelleme, nesnenin varlığıyla başarılı sayılamaz — `success` dâhil.**
Nesne güncellemeden **önce** de oradaydı, ve satıcının 200'ü hangi alanların
alındığını söylemez. Bu yüzden `update_reconciliation_required` durumu
`uncertain`'e özel değildir: `begin_mutation()` onu **istek gitmeden önce**
yazar ve gönderilen **alan değerlerini** yanına koyar. Tek kanıt, alan bazında
geri okumadır:

`Carrier_Interface::read_amendable_fields()` taşıyıcının o an tuttuğu değerleri
semantik alan adlarıyla döndürür (`recipient_full_name`, `recipient_address`,
`recipient_city_code`, `recipient_district_code`, `recipient_mobile_phone`,
`content`, `description`, `desi`, `kg`). Karşılaştırma **tam**dır: her beklenen
alan cevapta bulunmalı ve eşit olmalıdır. Cevapta **bulunmayan** bir alan
eşleşmezliktir — "bizi yalanlamadı" kanıt değildir.

**"Birebir" gerçekten birebirdir: tek kanonik biçim, sıfır tolerans.**
`fields_match()` eskiden iki tarafı da `trim()` ediyordu, ve o tek çağrı
"birebir" kelimesini yanlış yapıyordu: gönderilen `Ada Lovelace` ile geri okunan
` Ada Lovelace` **eşleşiyordu**, yani alanı sessizce yeniden biçimlendirmiş bir
taşıyıcı "aynen tutuyor" diye raporlanıyordu. Farkı karşılaştırmanın içinde
soğurmak, doğrulamanın tersidir.

Çözüm daha gevşek bir karşılaştırma değil, **tanımlı** bir karşılaştırmadır:

1. `Manager::canonical_amendable_value()` tek kanonik biçimi tanımlar — baştaki
   ve sondaki boşluk atılır, içteki boşluk dizileri (yapıştırılmış adreslerin
   taşıdığı sekme ve satır sonları dâhil) tek boşluğa indirilir, Unicode
   duyarlı.
2. `Manager::canonicalize_request()` bunu `build_request()`'in **en sonunda**,
   `kuka_island_shipping_request` filtresinden **sonra** uygular. Yani
   taşıyıcıdan istenen baytlar, karşılaştırmanın daha sonra talep edeceği
   baytlarla aynıdır; mağazanın kendi filtresi araya boşluk geri koyamaz.
3. `fields_match()` içinde **hiçbir** `trim()` yoktur; karşılaştırma `!==` ile
   yapılır ve skaler olmayan bir cevap da eşleşmezliktir.

Ölçüm `SHIPPING_AMENDABLE_CANONICAL_EXACT`: kanonik biçim dört vaka,
karşılaştırma altı vaka, gönderilen değerlerin tamamının kanonik olduğu
ölçülüyor, ve baştaki tek boşlukla geri okunan bir alan `update_mismatch` →
`manual_review` üretiyor.

| Geri okuma | Sonuç |
| --- | --- |
| Birebir eşleşti | Önceki duruma dönülür; kanıt temizlenir; yeni güncelleme yapılabilir |
| Bir alan farklı ya da eksik | `manual_review`; kanıt temizlenir; otomatik güncelleme yapılmaz |
| `readback_unsupported` | Durum korunur; **yeni güncelleme gönderilmez** |
| Kapı kapalı / cevap yok | Durum korunur |

**DHL adaptörü `readback_unsupported` döndürür.** Satıcının Standard Query
dokümanlarında `getorder` ve `getshipment` yanıtları kimlik alanları, dönüşüm
bayrağı, durum kodu, teslim bayrağı ve parça sayısı taşır; güncellenebilir
alanların **hiçbiri** yoktur. Karşılaştırılacak bir şey olmadığı için
uydurulmuş bir karşılaştırma "gönderi hâlâ var"ı "güncelleme uygulandı"ya
çevirirdi. Bu bir başarısızlık değil, bir **rettir**: belirsiz güncelleme
insana bırakılır. Gerçek bir geri okuma, ancak dokümante edilmiş ve sandbox'ta
ölçülmüş alan döndüren bir uç `DHL_Client`a eklenirse mümkün olur — bu belgedeki
diğer açık ölçümlerle aynı eşik.

Ölçüm: `SHIPPING_CANCEL_SERIALISED_AND_IDEMPOTENT`,
`SHIPPING_CANCEL_REFUSES_EVERY_OTHER_STATE`,
`SHIPPING_CANCEL_EVIDENCE_SURVIVES_BLOCKED_CONFIRM`,
`SHIPPING_CANCEL_EVIDENCE_SURVIVES_RECORD_PRESENT`,
`SHIPPING_CANCEL_EVIDENCE_SURVIVES_UNCERTAIN`,
`SHIPPING_CANCEL_EVIDENCE_CLEARED_ON_PROOF`,
`SHIPPING_CANCEL_EVIDENCE_ORDER_BRANCH`,
`SHIPPING_CANCEL_REFUSAL_POLICY`,
`SHIPPING_PENDING_CANCEL_KEEPS_THE_POLL_BOOKING`,
`SHIPPING_PENDING_CANCEL_IS_MANUAL_ONLY`,
`SHIPPING_MUTATION_INTENT_DURABLE`,
`SHIPPING_MUTATION_CRASH_BOUNDARY`,
`SHIPPING_MUTATION_INTENT_UNPERSISTED_BLOCKS_WRITE`,
`SHIPPING_MUTATION_OUTCOME_ATOMIC`,
`SHIPPING_AMENDABLE_CANONICAL_EXACT`,
`SHIPPING_UPDATE_SERIALISED_AND_FRESH`,
`SHIPPING_UPDATE_REFUSES_EVERY_OTHER_STATE`,
`SHIPPING_UPDATE_EVIDENCE_EXISTENCE_IS_NOT_PROOF`,
`SHIPPING_UPDATE_EVIDENCE_READBACK_UNSUPPORTED`,
`SHIPPING_UPDATE_EVIDENCE_READBACK_MATCHES`,
`SHIPPING_UPDATE_EVIDENCE_READBACK_MISMATCH`. Eşzamanlılık ölçümleri **gerçek
ikinci MySQL oturumu** kullanır (`SHIPPING_SECOND_DB_SESSION` iki farklı
`CONNECTION_ID()` olduğunu kanıtlar); advisory lock bağlantı başına tutulduğu
için tek bağlantıda yapılan iki ardışık çağrı eşzamanlılık kanıtı değildir.

## 8. Eşzamanlılık

- **Durumu değiştiren her yol** sipariş başına aynı MySQL advisory kilidini alır
  (`GET_LOCK('kuka_ship_mutate_<id>', 0)`): `create_shipment()`,
  `resume_barcode()`, `update_shipment()` ve `cancel()`. Kilidi alamayan
  **beklemez**, `lock_contended` ile hemen döner. Tek aile olması gerekir: bir
  iptalin, geri aldığı oluşturmayla üst üste binmemesi bununla sağlanır.
  (Kilit adı yalnız oluşturma tuttuğu sürece `kuka_ship_create_` idi.)
- Durum, `shipment_id` ve referans kilit **alındıktan sonra** tekrar okunur;
  kilit öncesi okunan değer geçmiş bir ana aittir. Taşıyıcı isteği o okumadan
  kurulur.
- Sorgu planlaması ayrı bir kilit kullanır
  (`kuka_ship_query_<id>`) ve kilit içinde yalnız `STATUS_PENDING` action'lar
  sayılır. `as_has_scheduled_action()` çalışan action'ı da saydığı için
  kullanılmaz: zincir tek adımdan sonra dururdu.

## 9. ReferenceId

- Biçim: `KI<sipariş-id>-<8 hex>`, tamamı büyük harf.
- Doğrulayıcı: `/^[A-Z0-9][A-Z0-9-]{4,39}$/`. URL yolunda taşındığı için boşluk,
  eğik çizgi ve yüzde işareti kabul edilmez.
- `barcode` alanı referansın **aynısıdır** (satıcı böyle şart koşar).
- Parça barkodu: `<REFERANS>P<n>`.
- Bir kez üretilir ve sipariş metasında kalır. Her referans
  `_kuka_shipping_reference_history` içinde **kalıcı** tutulur; hiçbir zaman
  silinmez.
- Yeni referans yalnız `mint_replacement()` ile üretilir; bu da doğrulanmış bir
  iptalden sonra çağrılır. `build_unused()` adayı siparişin kendi referans
  geçmişine karşı kontrol eder; geçmişte olan bir değer **yazılmaz**, çünkü
  taşıyıcının bildiği bir kimliği yeniden kullanmak sonraki her sorguyu, her
  güncellemeyi ve her iptali eski gönderiye yöneltirdi.
- Farklı siparişlerin referansları çakışamaz: sipariş id'si dizenin parçasıdır.
  Rastgele son ek yalnız **aynı sipariş** için üretilen yedek referansları
  ayırır.

## 10. Sipariş metası (HPOS uyumlu)

Tümü WooCommerce CRUD üzerinden yazılır; `$wpdb` kullanılmaz.

`_kuka_shipping_provider` sahiplik alanıdır: ilk dış yazmadan önce, mutation
kilidi altında, referansla **aynı save** içinde yazılır ve bir daha üzerine
yazılmaz. Bkz. §2.1.

| Meta | İçerik |
| --- | --- |
| `_kuka_shipping_provider` | taşıyıcı anahtarı (`dhl`) |
| `_kuka_shipping_state` | durum makinesi durumu |
| `_kuka_shipping_reference` | güncel referans |
| `_kuka_shipping_reference_history` | üretilmiş tüm referanslar |
| `_kuka_shipping_shipment_id` | taşıyıcı gönderi numarası |
| `_kuka_shipping_barcodes` | parça barkodları |
| `_kuka_shipping_tracking_url` | taşıyıcının döndüğü takip bağlantısı |
| `_kuka_shipping_order_invoice_id` | `createOrder` yanıtındaki sipariş numarası |
| `_kuka_shipping_status_code` | yalnız 1–8; tanınmayan değerde `0` |
| `_kuka_shipping_status_lifecycle` | `in_progress` / `delivered` / `manual_review` |
| `_kuka_shipping_last_error` | allow-list'teki güvenli hata kodu |
| `_kuka_shipping_last_operation` | son işlem adı |
| `_kuka_shipping_created_at`, `_kuka_shipping_last_queried_at` | zaman damgaları |
| `_kuka_shipping_query_attempts` | sorgu sayacı |
| `_kuka_shipping_history` | denetim kaydı, son 40 giriş |

Denetim kaydı 40 girişle sınırlıdır; bu bilinçli bir dengedir. Sipariş metası
her yönetim ekranında yüklenir ve sınırsız bir dizi mağazanın ömrü boyunca
büyür. **Referans geçmişi ayrı tutulur ve hiç kırpılmaz**, çünkü taşıyıcıda
nelerin adreslendiği sorusunun cevabı odur.

## 11. WooCommerce Fulfillments

- Kayıt **sıradan** bir WooCommerce fulfillment'ıdır: aynı tablo, aynı varlık,
  aynı takip alanları ve sağlayıcı anahtarı olarak adaptörün kendi `get_key()`
  değeri — DHL adaptöründe `dhl`.
- Eklentinin kendi kaydı fulfillment metasındaki `_kuka_shipping_reference` ile
  tanınır. **İnsanın oluşturduğu kayda dokunulmaz**: okunmaz, düzenlenmez,
  silinmez.
- Gönderi oluşunca kayıt `unfulfilled` açılır. Etiket üretmek teslim etmek
  değildir.
- Kayıt `fulfilled` olur ancak taşıyıcı **kod ≥ 2** dediğinde. Kod 1 "gönderi
  hazırlandı" demektir, "taşıyıcıda" demek değildir.
- Kod 5'te ayrıca fulfillment metasına `_kuka_shipping_delivered_at` yazılır.
- **Geri alma yoktur.** `fulfilled` olan kayıt sonraki hiçbir okumayla
  `unfulfilled` yapılmaz; bunun müşteri sonuçları vardır ve karar insanındır.
- Siparişin tüm kalemleri zaten başka bir fulfillment içindeyse yeni kayıt
  **oluşturulmaz**; taşıyıcı verisi sipariş metasında kalır ve operatöre not
  düşülür.

### 11.1 Takip numarası

`createbarcode` yanıtı hem `shipmentId` hem parça bazında `barcodes[].value`
döner. **Hangisinin WooCommerce takip numarası olduğu sandbox'ta ölçülmemiştir**,
bu yüzden varsayılan olarak **hiçbiri yazılmaz** ve operatöre not düşülür.

`KUKA_DHL_TRACKING_NUMBER_SOURCE` yalnız iki değeri kabul eder:
`shipment_id` veya `barcode`. Tanınmayan her değer "ölçülmedi"ye düşer.

Seçim, fulfillment writer'a **taşıyıcı sözleşmesinden** gelir:
`Kuka_Island_Shipping_Carrier_Interface::get_tracking_number_source()` ve
sözleşmenin kendi `TRACKING_SOURCE_*` sabitleri. Writer hangi taşıyıcının
cevapladığını bilmez; DHL yapılandırmasının sabitleri bu sözleşme sabitlerinin
takma adıdır.

Takip **bağlantısı** ayrıdır: `getshipmentstatus` yanıtındaki `trackingUrl`
ölçülmüş bir değerdir, geldiğinde saklanır ve fulfillment kaydına yazılır.

## 12. Adres çözümü

İki adım, ikisi de tahmin yapmaz:

1. **Türkçe katlama ile birebir eşleşme.** `İstanbul`, `İSTANBUL` ve `istanbul`
   eşitlenir; noktalama ve boşluk atılır.
2. Birinci adım bulamazsa **ASCII katlama ile benzersizlik**: `Istanbul`,
   `Kadikoy`, `Sisli` gibi Türkçe klavyesiz yazımlar kabul edilir — **ancak tek
   bir aday eşleşiyorsa**. Birden fazla aday çakışırsa sonuç `city_ambiguous` /
   `district_ambiguous` ile reddedilir.

Yaklaşık eşleşme yoktur: önek, düzenleme mesafesi ya da "en yakın ilçe" yok.
Eşleşme modu (`exact` / `ascii_unique`) sonuçta raporlanır.

İl/ilçe listesi 1 gün önbelleklenir. **Başarısız veya boş yanıt asla
önbelleklenmez**; aksi hâlde bir kesinti bir gün boyunca "il yok" cevabı
verirdi. `purge_cache()` açık bir temizleme yoludur.

## 13. Kapıda ödeme

Fail-closed, üç bağımsız katmanda:

1. `Manager::cod_gate()` siparişin ödeme yöntemine bakar; `cod`, `kapida_odeme`
   veya adında `cod`/`kapida` geçen bir yöntemse reddeder.
2. `DHL_Provider` istek içinde `cod.enabled` görürse bağımsız olarak reddeder.
3. `DHL_Order_Mapper` her yükte `isCOD` ve `codAmount` alanlarını koşulsuz `0`
   yazar.

Açılması ayrı bir iş kuralı doğrulaması gerektirir: toplanan paranın kim
tarafından mutabakatlandığı, mağazaya nasıl ulaştığı ve bu arada WooCommerce
ödeme kaydının ne olacağı yazılı olarak belirlenmeden açılmaz.

## 14. Durum sorgusu zinciri

- Action: `kuka_island_shipping_query_status`, grup `kuka-island-shipping`.
- Aralıklar: 15 dk, 30 dk, 1 sa, 2 sa, 4 sa, 8 sa, 12 sa, 24 sa; sonrası 24 sa.
- Tavan: **10 sorgu** veya gönderi oluşumundan **14 gün** sonra durur.
- Deneme bütçesi **gerçekten yapılan her sorguda** bir düşer: başarılı,
  transient ve permanent cevap aynı şekilde sayılır. Sayacı yazan tek yer
  `Order_Store::record_query_attempt()`'tir ve `Manager::query_status()` onu
  taşıyıcı çağrısından hemen sonra, sonuca bakmadan çağırır. Hiç çağrı
  yapılmayan erken retler (`carrier_not_registered`, `no_reference`) deneme
  saymaz.
- Onuncu sorgudan sonra yeni iş oluşmaz. `poll_exhausted` sipariş metasına
  (`_kuka_shipping_last_error`), sipariş geçmişine ve sipariş **notuna** yazılır.
- Terminal yaşam döngüsünde (`delivered`, `manual_review`) hemen durur.
- `KUKA_SHIPPING_AUTOMATION` kapalıyken hiçbir sorgu planlanmaz; zincir uçuşta
  bile bir sonraki adımda durur. Operatör sipariş ekranından elle sorgulayabilir.

## 15. Sır sızıntısı sınırı

- Taşıyıcının yazdığı hiçbir bayt sipariş notuna, sipariş metasına, log'a veya
  test çıktısına ulaşmaz. Hatalar `Fault_Classifier::CODES` listesindeki 12
  kodun birine indirgenir.
- `Result::to_safe_line()` yalnız işlem adı, sonuç türü, güvenli kod ve sayısal
  HTTP durumu içerir.
- `Config::get_safe_summary()` yalnız varlık boolean'ları döner; maskeli değer
  bile döndürmez.
- `Token_Store::__debugInfo()` token'ı `[redacted]` gösterir.
- `verify-shipping-automation.php` içindeki `SHIPPING_NO_SECRET_LEAK` ölçümü
  beş nöbetçi değeri sipariş notlarında, sipariş metasında, güvenli özette ve
  sonuç satırlarında arar; kontrol olarak aynı taramayı giden isteklerde
  **pozitif** bulur (sırlar oraya gitmelidir).

## 16. Ölçüm

| Suite | Ne ölçer |
| --- | --- |
| `scripts/verify-dhl-openapi-contract.sh` | SHA-256 + 13 operasyon + durum sözlüğü + base path |
| `scripts/verify-shipping-passive-contract.php` | pasif teslim + manuel yol + çekmece koruması |
| `scripts/verify-shipping-automation.php` | 93 davranış ölçümü, mock transport |
| `scripts/verify-shipping-activation-lifecycle.sh` | gerçek `wp plugin activate/deactivate` |
| `scripts/verify-dhl-runner-offline.sh` | runner'ın çevrimdışı izin listesi modu hiçbir süreç başlatmıyor |
| `scripts/verify-shipping-cache-custodian.sh` | normal/çıkış/fatal: koşu mağazanın CBS önbelleğine hiç dokunmuyor |
| `scripts/verify-deploy-package.sh` | paket içeriği |
| `scripts/test-dhl-sandbox.php` | salt-okunur Identity + CBS bağlantısı |
| `scripts/dhl-sandbox-shipment.php` | onaylı tek sandbox gönderisi: oluştur, sorgula, iptal |

`make verify` bu tablonun **ilk altı** satırını çalıştırır ve çıktılarını satır
satır sabitler: OpenAPI sözleşmesi, pasif teslim sözleşmesi, davranış ölçümleri
(mock transport), gerçek etkinleştirme/devre dışı bırakma turu ve paket içeriği.
Bu beşinin hiçbiri ağa çıkmaz, hiçbir kimlik bilgisi okumaz ve taşıyıcıda
hiçbir kayıt oluşturmaz. Kargo bloğunda `expect_shipping_match` ile sabitlenen
satır sayısı **114**'tür.

**`make verify` kargo bloğuna ulaşmak için EDM eklentisinin etkin olmasını
gerektirir.** `scripts/verify.sh` `set -eu` ile çalışır ve kargo bloğundan önce
`verify-invoice-integration.php` çağrılır; o suite `kuka-island-edm` pasifken
"EDM plugin is deactivated" ile 21 ölçümü FAIL eder ve komut sıfırdan farklı
dönerek betiği **orada** keser. Eklenti varsayılan olarak pasif teslim edildiği
için, tam yeşil bir `make verify` turu `docs/EDM_AKTIVASYON_REHBERI.md`'deki
etkinleştirme adımından **sonra** alınabilir. Yalnız kargo bloğunu ölçmek
gerektiğinde tek satırlık düzenleme yeterlidir: o atamanın sonuna `|| true`
eklemek, `INVOICE_*` ölçümlerini gizlemez, yalnız betiğin devam etmesini sağlar.

Son iki satır **`make verify` kapsamında değildir** ve olamaz:

- `scripts/test-dhl-sandbox.php` gerçek Identity ve CBS uçlarına bağlanır. Bu,
  repo dışındaki kimlik dosyasını okumayı gerektirir. Yalnız operatör
  çalıştırır: `./scripts/dhl-test-run.sh test-dhl-sandbox.php`. Salt-okunurdur.

  `make verify` bu betiği **hiçbir koşulda başlatmaz**. İzin listesi kararına
  ihtiyacı vardır, kararı da `./scripts/dhl-test-run.sh --check-script=<ad>`
  ile **çevrimdışı** alır: kimlik dosyasını okumaz, `stat`'lamaz, mount etmez,
  Docker başlatmaz, PHP çalıştırmaz, ağa çıkmaz. Karar fonksiyonu enforce eden
  yolla **aynıdır**, bu yüzden çevrimdışı cevap uygulanan cevaptan sapamaz.
  Önceki sürüm kararı gerçek komutu çalıştırıp çıktısının yalnız ilk satırını
  `head -n 1` ile okuyarak alıyordu: konteynerin PHP'sini durduran şey kapanan
  borunun SIGPIPE'ıydı, bir kural değil.
- `scripts/dhl-sandbox-shipment.php` sandbox'ta **gerçekten gönderi
  oluşturur**. Operatörün o tura ait açık onayı ve tam onay ifadesi olmadan
  çalışmaz: `./scripts/dhl-sandbox-run.sh --order=<id>
  --confirm=TEK-SANDBOX-GONDERISI-ONAYLIYORUM`.

`make verify` bu ikisi hakkında yalnız **reddetme davranışını** ölçer:
`DHL_RUNNER_ALLOWLIST` satırı, izin listesi dışındaki her betik adının ve
onay ifadesi olmayan her yazma çağrısının kimlik kapısına ulaşmadan
reddedildiğini sayar. Yani `make verify` çıktısında yeşil bir kargo bloğu
görmek, sandbox bağlantısının doğrulandığı anlamına **gelmez**.

## 17. Modüler açma/kapatma

Dört bağımsız anahtar vardır ve yönetim panelinde hepsi birlikte yazılıdır
(`Admin::module_status_line()`):

| Anahtar | Kapalıyken | Nerede |
| --- | --- | --- |
| WordPress eklentisi | Hiçbir dosya yüklenmez: sınıf, admin hook'u, poller, Action Scheduler işi ve API istemcisi yok; ağ çağrısı 0 | `wp plugin activate/deactivate` |
| Çalışma kapısı | Yüklü ama hiçbir taşıyıcı çağrısı yapılmaz; uçuştaki worker bile durur | `kuka_island_shipping_runtime_disabled` option'ı; deaktivasyon kapatır, aktivasyon açar |
| Otomatik durum sorgusu | Hiçbir sorgu planlanmaz; uçuştaki zincir bir sonraki adımda durur | `KUKA_SHIPPING_AUTOMATION`, **varsayılan kapalı** |
| Adaptör | Registry o adaptörü hiç öğrenmez; nesne kurulmaz, istemci kurulmaz, her işlem `carrier_not_registered` ile ağdan önce reddedilir | `KUKA_DHL_ADAPTER`, tanımsızsa açık |

**`KUKA_DHL_ADAPTER` tanınmayan her değerde KAPANIR.** Eski kural "yalnız dört
açık olumsuz kapatır, gerisi açık kalır"dı ve gerekçesi "yazım hatası kargoyu
sessizce durdurmasın"dı. Hatanın diğer yönü daha kötüsüdür ve gerçekleşiyordu:
`flase`, `of`, `' 0'` ve `''` adaptörü **açık** bırakıyordu. Olumsuz yazmak
isteyip yanlış yazan operatör kargonun durduğunu sanıyordu, oysa gönderi hâlâ
oluşturulabiliyordu — ve hiçbir yerde değerin anlaşılmadığı yazılı değildi.

| Değer | Sonuç | `reason` |
| --- | --- | --- |
| tanımsız | açık | `unset_default_on` |
| `1`, `true`, `yes`, `on` (tam eşleşme) | açık | `explicitly_on` |
| `0`, `false`, `no`, `off` (tam eşleşme) | kapalı | `explicitly_off` |
| PHP `true` / `false` sabiti | değere göre | `explicitly_on` / `explicitly_off` |
| `''`, `flase`, `of`, `' 1'`, `'1 '`, `ON`, `True`, `evet`, `2`, dizi/nesne | **kapalı** | `configuration_invalid` |

**Sessiz normalizasyon yoktur.** Kırpma yok, büyük-küçük harf katlama yok, tür
zorlaması yok. `' 1'` ayarın kendisi değil, kimsenin kontrol etmediği bir
değerdir; onu sessizce `1` okumak yapılandırma dosyasının yanlış kalmaya devam
etmesinin yoludur. Tek istisna gerçek bir PHP boolean'ıdır: o bir yazım değil,
bir değerdir ve yanlış yazılamaz.

`configuration_invalid` sipariş ekranında **görünür**: adaptör kendi cümlesini
`kuka_island_shipping_configuration_notices` filtresine koyar (registry'nin
kendisi de aynı desenle çalışır) ve `module_status_line()` onu basar. Taşıyıcıyı
adıyla anan katman kompozisyon köküdür; sipariş ekranı hiçbir kuryeyi adıyla
anmaz, yalnız kendisine verileni yazar.

Ölçüm `SHIPPING_ADAPTER_KEY_FAIL_CLOSED`: 19 değer, hatalı 0, geçersiz değerde
kayıtlı adaptör 0 ve HTTP 0, kapı `carrier_not_registered`, ve durum satırı
ayarın adını + "tanınmadı" cümlesini içeriyor.

**Deaktivasyon** çalışma kapısını **önce** kapatır, sonra `kuka-island-shipping`
grubundaki bekleyen `kuka_island_shipping_query_status` işlerini action id
bazında iptal eder. Tamamlanmış ve başarısız kayıtlar korunur: onlar ne olduğunun
kaydıdır.

**Yeniden aktivasyon** yalnız kapıyı açar ve sürüm yazar. Hiçbir siparişi
kuyruğa almaz, iptal edilmiş bir zinciri devam ettirmez, ve **sipariş
sahipliğine ya da eski sipariş metasına dokunmaz**.

**Aktiflik tek başına kargo oluşturmaz.** Etkin bir eklentide bile gönderi
yalnız operatörün açık basışıyla oluşur; hiçbir sipariş durumu kancası bu yola
bağlı değildir.

Ölçüm: `SHIPPING_ADAPTER_SWITCH`, `SHIPPING_ADAPTER_KEY_FAIL_CLOSED`,
`SHIPPING_MODULE_STATUS_VISIBLE`, `SHIPPING_DEACTIVATION_PRESERVES_OWNERSHIP`,
ve gerçek `wp plugin activate/deactivate` turu için `SHIPPING_LIFECYCLE_*`.

## 18. Dokunulmayacaklar

- `wp-content/plugins/kuka-island-core/assets/admin-orders.css` kargo çekmecesi
  kuralı — bkz. [KARGO_SCROLL_KORUMA_NOTU.md](KARGO_SCROLL_KORUMA_NOTU.md).
- WooCommerce ve diğer vendor dosyaları.
- Manuel fulfillment davranışı.
- `kuka-island-edm`. Bu eklenti EDM'i aktive etmez, ayarına dokunmaz.
