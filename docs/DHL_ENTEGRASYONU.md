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

### 7.1 İptal doğrulaması

Taşıyıcının "iptal edildi" cevabı **kanıt değildir**; yalnız bir alındıdır.
Durum ancak **yazmanın hedefi olan nesnenin** salt-okunur sorgusu `not_found`
derse değişir:

| Yapılan yazma | Doğrulayan sorgu |
| --- | --- |
| `cancelshipment` (sipariş metasında `shipment_id` var) | `getshipment` |
| `cancelorder` (yalnız sipariş kaydı var) | `getorder` |

Sorgu "hâlâ var" derse veya hiç cevap veremezse durum **değişmez**, sorgu
zinciri iptal edilmez ve sonuç `cancel_unconfirmed` olur.

İptal `uncertain` dönerse durum `reconcile_required` olur ve **iptal
tekrarlanmaz**: `cancel()` bu durumdan `reconcile_required` koduyla döner.
Çıkış yalnız salt-okunur mutabakattır.

## 8. Eşzamanlılık

- Oluşturma yolu sipariş başına MySQL advisory lock alır
  (`GET_LOCK('kuka_ship_create_<id>', 0)`). Kilidi alamayan **beklemez**,
  hemen döner.
- Durum kontrolü kilit **alındıktan sonra** tekrar okunur; kilit öncesi okunan
  değer geçmiş bir ana aittir.
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
| `scripts/verify-shipping-automation.php` | 30 davranış ölçümü, mock transport |
| `scripts/verify-shipping-activation-lifecycle.sh` | gerçek `wp plugin activate/deactivate` |
| `scripts/verify-deploy-package.sh` | paket içeriği |
| `scripts/test-dhl-sandbox.php` | salt-okunur Identity + CBS bağlantısı |
| `scripts/dhl-sandbox-shipment.php` | onaylı tek sandbox gönderisi: oluştur, sorgula, iptal |

`make verify` bu tablonun **ilk beş** satırını çalıştırır ve çıktılarını satır
satır sabitler: OpenAPI sözleşmesi, pasif teslim sözleşmesi, davranış ölçümleri
(mock transport), gerçek etkinleştirme/devre dışı bırakma turu ve paket içeriği.
Bu beşinin hiçbiri ağa çıkmaz, hiçbir kimlik bilgisi okumaz ve taşıyıcıda
hiçbir kayıt oluşturmaz.

Son iki satır **`make verify` kapsamında değildir** ve olamaz:

- `scripts/test-dhl-sandbox.php` gerçek Identity ve CBS uçlarına bağlanır. Bu,
  repo dışındaki kimlik dosyasını okumayı gerektirir. Yalnız operatör
  çalıştırır: `./scripts/dhl-test-run.sh test-dhl-sandbox.php`. Salt-okunurdur.
- `scripts/dhl-sandbox-shipment.php` sandbox'ta **gerçekten gönderi
  oluşturur**. Operatörün o tura ait açık onayı ve tam onay ifadesi olmadan
  çalışmaz: `./scripts/dhl-sandbox-run.sh --order=<id>
  --confirm=TEK-SANDBOX-GONDERISI-ONAYLIYORUM`.

`make verify` bu ikisi hakkında yalnız **reddetme davranışını** ölçer:
`DHL_RUNNER_ALLOWLIST` satırı, izin listesi dışındaki her betik adının ve
onay ifadesi olmayan her yazma çağrısının kimlik kapısına ulaşmadan
reddedildiğini sayar. Yani `make verify` çıktısında yeşil bir kargo bloğu
görmek, sandbox bağlantısının doğrulandığı anlamına **gelmez**.

## 17. Dokunulmayacaklar

- `wp-content/plugins/kuka-island-core/assets/admin-orders.css` kargo çekmecesi
  kuralı — bkz. [KARGO_SCROLL_KORUMA_NOTU.md](KARGO_SCROLL_KORUMA_NOTU.md).
- WooCommerce ve diğer vendor dosyaları.
- Manuel fulfillment davranışı.
- `kuka-island-edm`. Bu eklenti EDM'i aktive etmez, ayarına dokunmaz.
