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
  `dhl` sağlayıcı anahtarı, aynı takip alanları.
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

`make verify` bunların hepsini çalıştırır ve çıktılarını satır satır sabitler.

## 17. Dokunulmayacaklar

- `wp-content/plugins/kuka-island-core/assets/admin-orders.css` kargo çekmecesi
  kuralı — bkz. [KARGO_SCROLL_KORUMA_NOTU.md](KARGO_SCROLL_KORUMA_NOTU.md).
- WooCommerce ve diğer vendor dosyaları.
- Manuel fulfillment davranışı.
- `kuka-island-edm`. Bu eklenti EDM'i aktive etmez, ayarına dokunmaz.
