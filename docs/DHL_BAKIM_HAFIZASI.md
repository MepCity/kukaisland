# DHL Kargo Bakım Hafızası

Bu dosya **bakım kaydıdır**, teknik sözleşme değil. Sözleşme:
[DHL_ENTEGRASYONU.md](DHL_ENTEGRASYONU.md). Etkinleştirme:
[DHL_AKTIVASYON_REHBERI.md](DHL_AKTIVASYON_REHBERI.md).

Amaç tek: bir belirti tekrar ortaya çıktığında **nereye bakılacağını** saniyeler
içinde bulmak. Her kayıt aynı alanları taşır ve `Ctrl+F` ile belirti üzerinden
aranacak şekilde yazılmıştır.

**Bu dosyaya asla yazılmaz:** client id, client secret, müşteri numarası,
parola, JWT, istek/yanıt gövdesi, müşteri adresi.

Modül yolu: `wp-content/plugins/kuka-island-shipping-automation/`. Kısaltma
olarak aşağıda `SHIP/` kullanılır.

---

## Kısa kronoloji

| Tarih | Ne oldu | Sonuç / ilgili kayıt |
| --- | --- | --- |
| 2026-09-03 | Kargo otomasyonu ayrı, varsayılan pasif eklenti olarak kuruldu; ilk adaptör DHL eCommerce Türkiye (MNG altyapısı) | Core değişmedi, manuel kargo yolu korundu (K-01) |
| 2026-09-03 | Token önbelleği önce karamsar zaman dilimi okumasıyla hesaplanıyordu; bir saatlik token'da pencere sıfıra düşüp her istek yeni token alıyordu | Sabit 5 dakikalık pencereye geçildi, `jwtExpireDate` yalnız veto olarak kullanılıyor (K-02) |
| 2026-09-03 | Adres eşleşmesi yalnız Türkçe katlama kullanıyordu; `Istanbul` yazımı reddediliyordu | İkinci adım olarak **benzersizlik kanıtlı** ASCII eşleşmesi eklendi; çakışma reddediliyor (K-05) |
| 2026-09-03 | Koruma taraması, korumayı **anlatan** yorumu ihlal sayıyordu | Tarama öncesi PHP token'lanıp yorumlar ayıklanıyor (K-06) |
| 2026-09-03 | İkinci `make verify` turunda referans testi rastgele patladı | Son ek 3 bayttan 4 bayta çıktı, benzersizlik `build_unused()` ile kanıtlanır oldu (K-13) |
| 2026-09-03 | İptal her iki dalda da `getshipment` ile doğrulanıyordu; yalnız sipariş kaydı olan dalda 404 kaçınılmazdı | Doğrulama yazmanın hedefini izliyor: `cancelorder` → `getorder` (K-14) |
| 2026-09-03 | `createOrder` başarılı, `createbarcode` değil: sipariş `order_created` çıkmazında kalıyordu | Yalnız barkod aşamasını sürdüren ayrı operatör işlemi eklendi (K-15) |
| 2026-09-03 | Başarısız durum sorgusu deneme sayacını artırmıyordu; hata zincirinde tavan hiç dolmuyordu | Sayaç tek merkeze taşındı, gerçek Action Scheduler ile ölçüldü (K-16) |
| 2026-09-03 | "Taşıyıcıdan bağımsız" iddiası kaynakta doğru değildi; registry testi ikinci kez DHL ekliyordu | Sözleşme genişletildi, gerçek sahte ikinci adaptörle ölçüldü (K-17) |

---

## Açık ölçümler — sandbox'a bağlanınca ilk yapılacaklar

Bunlar **bilinmeyen**dir, varsayım değil. Her biri ölçüldüğünde bu dosyaya
sonucu yazılır.

**2026-09-03 itibarıyla Ö-01…Ö-05'in hiçbiri ölçülmemiştir.** Aşağıdaki K
kayıtlarının hiçbiri bunları kapatmaz; kod düzeltmeleri sandbox ölçümünün yerine
geçmez.

### Ö-01 — `Authorization` başlığının biçimi

- **Durum:** ölçülmedi.
- **Neden belirsiz:** Identity API `jwt` adında bir alan döner. Command ve Query
  dokümanları `Authorization` adında, `string` tipinde, **zorunlu** bir başlık
  beyan eder ve biçimi hakkında tek kelime yazmaz.
- **Şu anki davranış:** varsayılan `Bearer <jwt>`. Sözleşmedir, dokümandan
  gelmez.
- **Nasıl ölçülür:** Aşama 3 salt-okunur testi. `DHL_SANDBOX_IDENTITY=PASS`
  fakat sonraki bir Query çağrısı `code:unauthorized` verirse
  `KUKA_DHL_AUTHORIZATION_SCHEME=raw` ile tekrar deneyin.
- **İlgili dosya:** `SHIP/includes/shipping/dhl/class-dhl-client.php`
  (`$authorization_scheme`).

### Ö-02 — CBS uçlarının token isteyip istemediği

- **Durum:** ölçülmedi.
- **Neden belirsiz:** `CBS_Info_API-1.0.json` hiçbir operasyonunda
  `Authorization` parametresi beyan etmez — yalnız `x-api-version` ve global
  güvenlik bloğundaki ağ geçidi anahtarları vardır.
- **Şu anki davranış:** CBS çağrılarında token **gönderilmiyor**. Doküman böyle
  diyor.
- **Nasıl ölçülür:** Aşama 3. `DHL_SANDBOX_CBS_CITIES` `code:unauthorized`
  verirse doküman eksik demektir; `is_cbs_operation()` kaldırılır.
- **İlgili dosya:** `SHIP/includes/shipping/dhl/class-dhl-client.php`
  (`is_cbs_operation`).

### Ö-03 — Hangi değer WooCommerce takip numarası

- **Durum:** ölçülmedi. **Bu yüzden hiçbir takip numarası yazılmıyor.**
- **Neden belirsiz:** `createbarcode` yanıtı hem `shipmentId` hem parça bazında
  `barcodes[].value` döner. İkisi de "numara" gibi görünür.
- **Şu anki davranış:** `KUKA_DHL_TRACKING_NUMBER_SOURCE` tanımsızken
  fulfillment kaydının takip numarası **boş** bırakılır ve siparişe not düşülür.
- **Nasıl ölçülür:** Aşama 5'te oluşturulan tek gönderinin `trackingUrl`
  bağlantısı veya taşıyıcı paneli. Hangi numara gerçekten takip ediyorsa o.
- **İlgili dosyalar:** `SHIP/includes/shipping/class-fulfillment-writer.php`
  (`tracking_number`) ve seçimin geldiği yer:
  `SHIP/includes/shipping/interface-carrier-provider.php`
  (`TRACKING_SOURCE_*`, `get_tracking_number_source()`). K-17 cevabın **nereden
  sorulduğunu** değiştirdi; cevabın kendisi hâlâ ölçülmedi.

### Ö-04 — `recipient.customerId`

- **Durum:** ölçülmedi. **Bu yüzden alan hiç gönderilmiyor.**
- **Neden belirsiz:** `Customer.customerId` "Müşteri Numarası" diye
  tanımlanmıştır ve örnekte açıklanmayan bir sayı taşır. Hiçbir zorunlu listede
  değildir.
- **Şu anki davranış:** alan gövdeye konulmuyor. `refCustomerId` alanına
  WooCommerce sipariş id'si yazılıyor — o alan "müşterinin kendi sistemindeki
  numarası" olarak belgelenmiştir.
- **Risk:** uydurulmuş bir `customerId` paketi başka bir hesaba bağlayabilir.
- **İlgili dosya:** `SHIP/includes/shipping/dhl/class-dhl-order-mapper.php`.

### Ö-05 — Canlı uçlar

- **Durum:** doğrulanmadı. **Canlı ortam bloke.**
- **Neden belirsiz:** beş dokümanın `x-ibm-configuration.servers` bloğunda tek
  sunucu vardır ve o sandbox'tır.
- **Nasıl açılır:** satıcıdan yazılı üretim base URL'i alınır,
  `DHL_Config` sınıfına eklenir, `is_live_blocked()` gerçekten koşullu hâle
  getirilir ve endpoint allow-list testi yeni host için genişletilir.

---

## K-01 — Entegrasyonun Core'a değil ayrı eklentiye konması

- **Tarih:** 2026-09-03
- **Belirti:** —
- **Karar nedeni:** Kargo firması kararı henüz kesin değil (`PLAN.md` §39,
  "Kesim listesi, renk sayıları, fiyatlar ve kargo firması"). Core'a gömülen bir
  taşıyıcı entegrasyonu, firma değişirse Core'u değiştirmeyi gerektirirdi.
- **Uygulanan çözüm:** `kuka-island-shipping-automation` ayrı ve varsayılan
  pasif eklenti. Bağımlılık tek yönlü: `shipping → core`. İkinci taşıyıcı
  `kuka_island_shipping_carriers` filtresine bir adaptör eklenerek gelir; Core,
  Manager, Order Store, Poller, Admin ve WooCommerce değişmez.
- **Kanıt:** `SHIPPING_PASSIVE_CORE_INTACT=PASS|core_files_referencing_shipping_plugin:0`
  ve `SHIPPING_CARRIER_REGISTRY=PASS|registered:dhl+kuka-test-kargo|non_adapters_dropped:yes`
- **Düzeltme (K-17):** Bu kaydın ilk kanıtı `registered:dhl` idi ve registry
  testi ikinci taşıyıcı olarak **ikinci kez DHL adaptörü** ekliyordu. İddia o
  hâliyle ölçülmüş değildi; bkz. K-17.
- **İlgili dosyalar:** `SHIP/includes/class-plugin.php`,
  `SHIP/includes/shipping/class-carrier-registry.php`
- **Tekrar yaşanırsa ilk bak:** `Kuka_Island_Shipping_Plugin::dependency_map()`.

---

## K-02 — Token önbelleği hiç tutmuyordu

- **Tarih:** 2026-09-03
- **Belirti:** Her `authenticate()` çağrısı yeni bir `/token` isteği üretiyor;
  üç çağrı üç istek yapıyor.
- **Kesin kök neden:** Önbellek süresi `jwtExpireDate` değerinin **karamsar**
  okumasından türetiliyordu: değer hem UTC hem `Europe/Istanbul` olarak
  yorumlanıp kalan sürenin **küçüğü** alınıyordu. İki yorum arasında üç saat
  fark var; satıcının kendi örneği ise yaklaşık bir saatlik ömür ima ediyor.
  Bir saatlik token'da karamsar hesap negatife düşüyor, pencere sıfırlanıyordu.
- **Uygulanan düzeltme:** Pencere **sabit 5 dakika**. `jwtExpireDate` süre
  hesabında kullanılmıyor; yalnız **veto**: en cömert okumayla bile geçmişte
  kalan değer önbelleği tamamen kapatıyor. Yanlış tahmin edilen süre,
  salt-okunur çağrılardaki tek seferlik yeniden kimlik doğrulamayla düzeliyor.
- **Neden bu daha güvenli:** eski davranış her istekte kimlik trafiği üretiyordu
  — daha az risk değil, daha çok.
- **Kanıt:** `SHIPPING_TOKEN_SESSION=PASS|authenticate_calls:3|token_requests:1|reused:yes|expired_string_vetoes_cache:0|far_future_capped:300|unparsable_window:300`
- **İlgili dosya:** `SHIP/includes/shipping/dhl/class-dhl-token-store.php`
- **Tekrar yaşanırsa ilk bak:** `cache_seconds()` ve `remaining_seconds()`;
  ikincisi **max** almalı, min değil.

---

## K-03 — Yazma çağrısında 401 sonrası tekrar yok

- **Tarih:** 2026-09-03
- **Belirti:** —
- **Karar nedeni:** Süresi dolmuş bir oturum salt-okunur çağrıda zararsızdır:
  token unutulur, bir kez yeniden alınır, istek tekrarlanır. Aynı davranışı
  yazma çağrısında uygulamak, ağ geçidinin isteği gerçekten işlemediğini
  **varsaymak** olurdu.
- **Uygulanan çözüm:** `is_write` bayrağı her isteğe eşlik eder. `true` iken
  yeniden deneme dalı erişilemezdir. Okuma çağrısı bir kez tekrarlanır.
- **Kanıt:** `SHIPPING_401_RETRY_POLICY=PASS|write_attempts:1|write_outcome:permanent|read_attempts:2|reauth_calls:2`
- **İlgili dosya:** `SHIP/includes/shipping/dhl/class-dhl-client.php` (`call()`)
- **Tekrar yaşanırsa ilk bak:** `call()` içindeki `! $is_write && ! $retried`
  koşulu.

---

## K-04 — Okunamayan 2xx başarı sayılmaz

- **Tarih:** 2026-09-03
- **Belirti:** —
- **Karar nedeni:** `createOrder` 200 dönüp gövdesi `{}` ya da JSON olmayan bir
  metin olabilir. Bunu başarı saymak, var olmayan bir gönderi kaydı yazmak
  demektir; başarısızlık saymak ise var **olan** bir gönderiyi ikinci kez
  oluşturmak demektir.
- **Uygulanan çözüm:** Ayrıştırıcı beklenen alanı bulamazsa sonuç `uncertain`
  olur ve mutabakat başlar. Beş vaka ölçülür: JSON olmayan metin, boş nesne,
  yanlış şekil, `null`, kesik JSON.
- **Kanıt:** `SHIPPING_UNREADABLE_SUCCESS_IS_UNCERTAIN=PASS|cases:5|all_uncertain:yes`
- **İlgili dosyalar:** `SHIP/includes/shipping/class-carrier-fault-classifier.php`,
  `SHIP/includes/shipping/dhl/class-dhl-client.php`
- **Tekrar yaşanırsa ilk bak:** ilgili operasyonun `$parser` closure'ı; boş
  yanıtta `null` döndürmelidir.

---

## K-05 — `Istanbul` yazımı reddediliyordu

- **Tarih:** 2026-09-03
- **Belirti:** Türkçe klavye kullanmayan müşterinin adresi
  `city_not_found` / `district_not_found` ile reddediliyor.
- **Kesin kök neden:** Katlama Türkçe kuralını doğru uyguluyordu: `ISTANBUL`
  **`İstanbul`un Türkçe büyük hâli değildir**, `İSTANBUL`dur. `I` harfi `ı`ya
  düşer. Kural doğruydu, kapsam eksikti.
- **Uygulanan düzeltme:** İki adımlı eşleşme. Birinci adım Türkçe katlamayla
  birebir. Bulamazsa ikinci adım her iki tarafı ASCII'ye katlar ve sonucu
  **yalnız tek aday eşleşiyorsa** kabul eder. Bu bir tahmin değil, benzersizlik
  kanıtıdır. İki yer ASCII'de çakışırsa `city_ambiguous` /
  `district_ambiguous` ile reddedilir.
- **Neden yaklaşık eşleşme yok:** önek/düzenleme mesafesi ile "en yakın ilçe"
  seçmek, paketi yanlış kasabaya gönderir ve operatör bunu hiç görmez.
- **Kanıt:** `SHIPPING_ADDRESS_RESOLUTION=PASS|folding:ok|exact:yes|ascii_unique:yes|ascii_collision_refused:district_ambiguous|approximate_matching:none`
- **İlgili dosya:** `SHIP/includes/shipping/dhl/class-dhl-address-resolver.php`
  (`fold`, `fold_ascii`, `match`)
- **Tekrar yaşanırsa ilk bak:** sonucun `match_mode` alanı; `ascii_unique`
  ikinci adımın devreye girdiğini söyler.

---

## K-06 — Koruma taraması korumayı anlatan yorumu ihlal sayıyordu

- **Tarih:** 2026-09-03
- **Belirti:** `SHIPPING_DRAWER_PROTECTION_INTACT=FAIL|forbidden_patterns_in_shipping_plugin:2`
  — hâlbuki eklentide tek bir satır JavaScript yok.
- **Kesin kök neden:** Tarama, `class-shipment-admin.php` docblock'undaki
  "no MutationObserver, no wheel or touch handler" cümlesini yakalıyordu. Yani
  sözleşmenin **belgelenmesi** ihlal sayılıyordu.
- **Uygulanan düzeltme:** Tarama öncesi PHP `token_get_all()` ile token'lanıp
  `T_COMMENT`/`T_DOC_COMMENT` ayıklanıyor; CSS/JS için blok ve satır yorumları
  siliniyor. Bu, CSS token disiplini ölçümünün zaten yaptığı düzeltmenin
  aynısıdır.
- **Neden önemli:** aksi hâlde bir sonraki geliştirici, testi geçirmek için
  açıklamayı silerdi — kodu değil.
- **Kanıt:** `SHIPPING_DRAWER_PROTECTION_INTACT=PASS|core_rule_present:yes|forbidden_patterns_in_shipping_plugin:0|shipping_plugin_assets:0`
- **İlgili dosya:** `scripts/verify-shipping-passive-contract.php`
- **Tekrar yaşanırsa ilk bak:** `$strip_comments` closure'ı.

---

## K-07 — Kapıda ödeme üç katmanda kapalı

- **Tarih:** 2026-09-03
- **Belirti:** —
- **Karar nedeni:** API `isCOD`/`codAmount` taşıyor ve bu entegrasyon onları
  doldurabilir. Belirsiz olan iş kuralı: toplanan parayı kim mutabakatlıyor,
  mağazaya nasıl ulaşıyor, o arada WooCommerce ödeme kaydı ne oluyor. Bu
  cevaplar olmadan `isCOD=1` göndermek, kimsenin takip etmediği bir alacak
  üretir.
- **Uygulanan çözüm:** Üç bağımsız kapı — `Manager::cod_gate()` siparişin ödeme
  yöntemine bakar; `DHL_Provider` istekteki `cod.enabled` bayrağını bağımsız
  reddeder; `DHL_Order_Mapper` her yükte `isCOD`/`codAmount` alanlarını koşulsuz
  `0` yazar.
- **Kanıt:** `SHIPPING_COD_FAIL_CLOSED=PASS|manager_code:cod_not_supported|http_requests:0|adapter_code:cod_not_supported|config_default:disabled`
  ve `SHIPPING_COD_ZERO_IN_PAYLOADS=PASS|payloads:4|isCOD_always_zero:yes`
- **İlgili dosyalar:** `SHIP/includes/shipping/class-shipment-manager.php`,
  `SHIP/includes/shipping/dhl/class-dhl-provider.php`,
  `SHIP/includes/shipping/dhl/class-dhl-order-mapper.php`
- **Tekrar yaşanırsa ilk bak:** `Manager::cod_gate()` içindeki ödeme yöntemi
  listesi; yeni bir kapıda ödeme geçidi eklendiyse oraya girmeli.

---

## K-08 — Fulfillment yalnız kod ≥ 2'de `fulfilled` olur

- **Tarih:** 2026-09-03
- **Belirti:** —
- **Karar nedeni:** WooCommerce'in yalnız iki durumu var: `fulfilled` ve
  `unfulfilled`. Etiket üretmek teslim etmek değildir; kod 1 "gönderi
  hazırlandı" der, "taşıyıcıda" demez. Gönderi oluşur oluşmaz `fulfilled`
  yazmak, müşteriye kargoya verildi e-postası göndermek demektir.
- **Uygulanan çözüm:** Kayıt `unfulfilled` açılır. Taşıyıcı kod 2/3/4/5 dediğinde
  `fulfilled` olur. Kod 5'te ayrıca `_kuka_shipping_delivered_at` yazılır.
  Kod 6/7/8 ve tanınmayan değerler kaydı **hiç değiştirmez**.
- **Geri alma yok:** `fulfilled` olan kayıt sonraki hiçbir okumayla geri
  alınmaz; müşteri sonucu olan bir karar insana aittir.
- **Kanıt:** `SHIPPING_FULFILLMENT_RECORD=PASS|status_on_create:unfulfilled` ve
  `SHIPPING_STATUS_TO_FULFILLMENT=PASS|stored_code:2|fulfilled_at_code_2:yes` ve
  `SHIPPING_UNKNOWN_STATUS_TO_MANUAL_REVIEW=PASS|fulfilment_not_downgraded:yes`
- **İlgili dosya:** `SHIP/includes/shipping/class-fulfillment-writer.php`
  (`sync_status`)
- **Tekrar yaşanırsa ilk bak:** `$should_be_fulfilled` eşiği.

---

## K-09 — Bütün kalemler zaten fulfillment içindeyse yeni kayıt açılmaz

- **Tarih:** 2026-09-03
- **Belirti:** Gönderi taşıyıcıda oluştu, sipariş notunda
  `no_unfulfilled_items` yazıyor.
- **Kesin kök neden:** Operatör siparişi zaten elle kargolamış; WooCommerce'te
  bekleyen kalem kalmamış. Fulfillment kaydı en az bir kalem ister.
- **Uygulanan çözüm:** Yeni kayıt oluşturulmuyor, insanın kaydından kalem
  **alınmıyor** ve o kayıt düzenlenmiyor. Taşıyıcı verisi sipariş metasında
  kalıyor, operatöre not düşülüyor.
- **Neden bölmüyoruz:** Birinin elle oluşturduğu fulfillment kaydını sorulmadan
  bölmek, bu otomasyonu güvenilmez yapardı.
- **İlgili dosya:** `SHIP/includes/shipping/class-fulfillment-writer.php`
  (`record_shipment`, `pending_items`)
- **Tekrar yaşanırsa ilk bak:** `FulfillmentUtils::get_pending_items()` çıktısı.

---

## K-10 — `reconcile_required` durumundan çıkış yalnız okumayla

- **Tarih:** 2026-09-03
- **Belirti:** Sipariş ekranında "Belirsiz — mutabakat gerekiyor" yazıyor ve
  `DHL gönderisi oluştur` düğmesi görünmüyor.
- **Bu bir hata değildir.** Tasarım budur.
- **Kural:** Yokluk **kanıtlanır**: `getshipment` ve `getorder` sorgularının
  **ikisi de** `not_found` demelidir. Timeout yokluk kanıtı değildir; o durumda
  kayıt `reconcile_required` kalır ve hiçbir şey gönderilmez.
- **Kanıt:** `SHIPPING_UNCERTAIN_NO_RESEND=PASS|createOrder_attempts:1|read_only_reconcile_calls:2|verdict_state:absent_confirmed`
  ve `SHIPPING_INCONCLUSIVE_STAYS_SHUT=PASS|state:reconcile_required|second_attempt:already_in_progress`
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`handle_uncertain`, `reconcile`)
- **Tekrar yaşanırsa ilk bak:** `Order_Store::states_blocking_create()`.
- **Not (K-15):** Mutabakat yalnız **siparişi** bulursa durum `order_created`
  olur ve bu da `states_blocking_create()` içindedir. Oradan çıkış
  `Manager::resume_barcode()`'dur — `createOrder`'ı tekrar çağırmayan, yalnız
  barkod aşamasını sürdüren ayrı bir operatör işlemi.

---

## K-11 — Sorgu planlamada `as_has_scheduled_action()` kullanılmaz

- **Tarih:** 2026-09-03
- **Belirti:** Zincir tek sorgudan sonra duruyor.
- **Kesin kök neden:** `as_has_scheduled_action()` `STATUS_RUNNING` action'ları
  da sayar. Sonraki sorgu, çalışan action'ın **içinden** planlanır; dolayısıyla
  "zaten planlı" cevabı her seferinde geliyordu.
- **Uygulanan düzeltme:** Yalnız `STATUS_PENDING` sorgulanıyor, ve bu kontrol
  sipariş başına advisory lock içinde yapılıyor (kontrol-et-sonra-yap yarışı
  aksi hâlde iki kayıt üretir).
- **Kanıt:** `SHIPPING_POLL_POLICY=PASS|...|terminal_stops:yes` ve
  `SHIPPING_NO_SCHEDULER_RESIDUE=PASS|pending_by_group:0|pending_by_hook:0`
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-status-poller.php`
  (`has_pending_query`, `schedule_query`)
- **Not:** Aynı hata EDM tarafında da yaşanmıştı (`EDM/` poller). Bu, Action
  Scheduler'ın bilinen bir tuzağıdır.

---

## K-12 — Satıcının yazım hataları düzeltilmez

- **Tarih:** 2026-09-03
- **Belirti:** `/updateorder` ya da `cancelorder` çağrısı 404 veriyor.
- **Kesin kök neden:** Yol "düzeltilmiş". Dokümanda `/createOrder` camelCase,
  `/updateorder` tamamen küçük harf, cancel parametresi `refrenceId` (eksik
  harf) yazılıdır. Sunucu niyeti değil yazımı uygular.
- **Uygulanan çözüm:** Yollar birebir kopyalanıyor ve
  `verify-dhl-openapi-contract.sh` her birinin dokümanda beyan edildiğini ve
  istemci kaynağında geçtiğini ölçüyor.
- **Kanıt:** `DHL_OPENAPI_CONTRACT=PASS|operations_used:13`
- **İlgili dosya:** `SHIP/includes/shipping/dhl/class-dhl-client.php`
- **Tekrar yaşanırsa ilk bak:** `verify-dhl-openapi-contract.sh` içindeki `used`
  listesi ile dokümanın `paths` bloğu.

---

## K-13 — Referans testi ikinci turda patladı

- **Tarih:** 2026-09-03
- **Belirti:** Birinci `make verify` turu geçti, ikinci tur
  `SHIPPING_VERIFY=FAIL|SHIPPING_REFERENCE_SHAPE` verdi. Aradaki tek fark
  rastgeleliktir.
- **Kesin kök neden:** Test aynı sipariş için 200 referans üretip hepsinin
  benzersiz olmasını bekliyordu. Son ek 3 bayttı: 16.777.216 değer. 200 çekiliş
  için doğum günü çakışma olasılığı yaklaşık **binde 1,2**. Yani test yaklaşık
  her 800 turda bir patlıyordu — ve patladığında öğrettiği şey "suite güvenilmez"
  olurdu.
- **İki ayrı düzeltme yapıldı:**
  1. **Üretici:** son ek 4 bayta çıktı ve `build_unused()` eklendi. Bu metot
     adayı verilen listeye karşı kontrol eder; `mint_replacement()` bunu
     siparişin kendi referans geçmişiyle çağırır ve geçmişte olan bir değeri
     **yazmaz**.
  2. **Test:** benzersizlik iddiası artık `build_unused()` üzerinden ölçülüyor —
     yani garantiyi taşıyan metot üzerinden. Ayrıca "verilen değer kaçınıldı" ve
     "sipariş id'si referansın içinde" ölçümleri eklendi.
- **Neden üretici de değişti:** testi gevşetmek yeterdi, ama gerçek risk şuydu:
  aynı sipariş için üretilen bir yedek referans, o siparişin **eski** referansına
  eşit çıkarsa sonraki her sorgu ve iptal eski gönderiyi hedefler.
- **Neden panik yok:** farklı siparişler zaten çakışamaz; sipariş id'si dizenin
  parçasıdır. Risk yalnız aynı siparişin yedek referanslarındaydı.
- **Kanıt:** `SHIPPING_REFERENCE_SHAPE=PASS|validator_cases:9|minted:200|unique:200|uppercase:yes|seeded_value_avoided:yes|order_id_in_reference:yes`
  ve arka arkaya üç bağımsız tur.
- **İlgili dosyalar:** `SHIP/includes/shipping/class-shipment-reference.php`,
  `SHIP/includes/shipping/class-shipment-order-store.php`
  (`mint_replacement`), `scripts/verify-shipping-automation.php`
- **Tekrar yaşanırsa ilk bak:** testin rastgele çekilişe dayanıp dayanmadığı.
  Rastgeleliğe dayanan bir eşitlik iddiası er geç patlar; garantiyi taşıyan
  metot üzerinden ölçün.

---

## K-14 — İptal doğrulaması yanlış nesneyi sorguluyordu

- **Tarih:** 2026-09-03
- **Belirti:** Sipariş ekranında durum `İptal edildi` yazıyor, sorgu zinciri
  duruyor; fakat taşıyıcı panelinde sipariş kaydı hâlâ duruyor. Ya da: iptal
  başarılı görünüyor ama kimse gönderiyi izlemiyor.
- **Kesin kök neden:** `Manager::cancel()` iki dala ayrılıyordu —
  `shipment_id` varsa `cancel_shipment()`, yoksa `cancel_order()` — fakat
  **her iki daldan sonra da yalnız `read_shipment()`** sorgulanıyordu.
  Yalnız sipariş kaydı bulunan dalda o referansla hiçbir gönderi hiç
  oluşmamıştı; dolayısıyla `getshipment` **her koşulda** `not_found` dönüyordu.
  Kod bunu "gitmiş" kanıtı sayıp `STATE_CANCELLED` yazıyor ve
  `cancel_queries()` ile sorgu zincirini iptal ediyordu. Yani taşıyıcı iptali
  hiç yapmamış olsa bile sipariş "iptal edildi" oluyor ve bir daha kimse
  bakmıyordu.
- **Uygulanan düzeltme:** Doğrulayan sorgu **yazmanın hedefini** izliyor.
  `cancel_shipment()` sonrası `read_shipment()`, `cancel_order()` sonrası
  `read_order()`. Taşıyıcının "iptal edildi" cevabı tek başına kanıt değil;
  yalnız eşleşen sorgunun `not_found` demesi durumu değiştiriyor. Sorgu
  "hâlâ var" derse veya cevapsız kalırsa durum korunuyor, zincir iptal
  edilmiyor, sonuç `cancel_unconfirmed` oluyor. Ayrıca `uncertain` iptalden
  sonra `cancel()` `reconcile_required` koduyla dönüyor: belirsiz iptal
  tekrarlanmıyor.
- **Neden alındı kanıt değildir:** 200 dönen bir `cancelorder`, ağ geçidinin
  isteği aldığını söyler; kaydın gerçekten kapandığını söylemez. Bu farkın
  bedeli, izlenmeyen canlı bir kargodur.
- **Kanıt:**
  `SHIPPING_CANCEL_SHIPMENT_BRANCH=PASS|branch:shipment|cancelshipment_calls:1|cancelorder_calls:0|getshipment_calls:1|getorder_calls:0|state:cancelled|confirmed_by:read_shipment`,
  `SHIPPING_CANCEL_ORDER_BRANCH=PASS|branch:order|...|getorder_calls:1|getshipment_calls:0|state:cancelled|confirmed_by:read_order`,
  `SHIPPING_CANCEL_ORDER_NOT_CANCELLED_ON_SHIPMENT_404=PASS|cancel_order:success|read_shipment:not_found|read_order:present|...|code:cancel_unconfirmed|state:order_created|cancelled_written:no`,
  `SHIPPING_CANCEL_UNCERTAIN_NOT_REPEATED=PASS|...|second_code:reconcile_required|cancelshipment_calls:1`
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`cancel()`)
- **Tekrar yaşanırsa ilk bak:** `cancel()` içindeki `$confirmed_by` değişkeni.
  İki dal da aynı sorguya gidiyorsa hata geri gelmiştir. Sonuç satırındaki
  `confirmed_by:` alanı hangi sorgunun kanıt sayıldığını söyler.

---

## K-15 — `order_created` çıkmazı: barkod aşaması sürdürülemiyordu

- **Tarih:** 2026-09-03
- **Belirti:** Durum `Taşıyıcıda sipariş oluşturuldu` yazıyor. `gönderi
  oluştur` düğmesi yok, `Mutabakat sorgusu çalıştır` düğmesi de yok. Sipariş
  ilerletilemiyor.
- **Kesin kök neden:** `createOrder` başarılı olup `createbarcode` başarısız
  ya da belirsiz olduğunda taşıyıcıda **sipariş kaydı kalıyor**. Mutabakat
  yalnız siparişi bulduğunda durum `order_created` oluyor. `order_created`
  `states_blocking_create()` içinde — ve orada olması **doğru**, çünkü
  `create_shipment()` `createOrder` ile başlar ve tekrar çağrılması taşıyıcıda
  ikinci bir sipariş kaydı üretir. Fakat barkod aşamasını tek başına
  sürdürecek bir yol yoktu. Operatörün elinde kalan tek seçenek, göremediği bir
  taşıyıcı siparişini iptal etmekti.
- **Uygulanan düzeltme:** Ayrı bir operatör işlemi:
  `Manager::resume_barcode()` ve `admin_post_kuka_shipping_resume`.
  - Bu yolda tek yazma çağrısı `createbarcode`'dur; `createOrder` erişilemez.
  - Yalnız **tam olarak** `order_created` kabul edilir. Reddedilen durumların
    listesi değil, kabul edilen tek durum yazılıdır: bir durum eklendiğinde
    liste delik vermez.
  - Durum, `create_shipment()` ile **aynı** advisory lock içinde yeniden
    okunur. İki kapı üst üste binmez; çift tıklamada ikinci sahip
    `shipment_created` görüp durur.
  - `createbarcode` `uncertain` dönerse tekrar edilmez; salt-okunur mutabakata
    devredilir (K-10 kuralı aynen geçerli).
  - Kendi nonce alanı (`kuka_shipping_resume_<id>`) ve bağımsız yetki kontrolü
    vardır; oluşturma düğmesinin nonce'u burada doğrulanmaz.
  - Yönetim düğmesi metni taşıyıcı adını `get_label()`'dan alır.
- **Kanıt:**
  `SHIPPING_RESUME_ORDER_CREATED=PASS|...|createOrder_calls_during_resume:0|createbarcode_calls_during_resume:1|state_after:shipment_created|second_press_code:not_resumable|second_press_writes:0`,
  `SHIPPING_RESUME_REFUSES_OTHER_STATES=PASS|states_refused:8|states_allowed:none|http_requests:0`,
  `SHIPPING_RESUME_UNCERTAIN_TO_RECONCILE=PASS|createbarcode_calls:1|state:reconcile_required|read_only_reconcile_calls:2`,
  `SHIPPING_RESUME_ADMIN_ACTION=PASS|separate_nonce:yes|wrong_nonce:refused|wrong_nonce_writes:0|no_capability:refused|no_capability_writes:0|authorised_writes:1`
- **İlgili dosyalar:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`resume_barcode`, `preflight`, `resume_refusal_message`),
  `SHIP/includes/shipping/class-shipment-admin.php`
  (`handle_resume`, `run_resume`, `resume_button_label`)
- **Tekrar yaşanırsa ilk bak:** `resume_barcode()` içindeki durum kontrolünün
  `!==` mi yoksa bir liste mi olduğu. Liste hâline getirilmişse çıkmaz geri
  gelmez ama fazladan durum kabul edilir hâle gelir — ikisi de yanlıştır.

---

## K-16 — Başarısız sorgular deneme bütçesinden düşmüyordu

- **Tarih:** 2026-09-03
- **Belirti:** Taşıyıcı sorgu ucu cevap vermiyor ve sipariş için sonsuz bir
  Action Scheduler zinciri oluşuyor. `_kuka_shipping_query_attempts` `0`da
  kalıyor, `MAX_ATTEMPTS=10` hiç devreye girmiyor.
- **Kesin kök neden:** Sayaç yalnız `Order_Store::save_status()` içinde
  artıyordu; `save_status()` ise **yalnız başarılı** sorgudan sonra çağrılıyor.
  Başarısız `read_shipment_status()` `save_failure()` yoluna gidiyor ve
  `save_failure()` sayaca dokunmuyordu. Poller ise kararı, çağrıdan **önce**
  alınmış anlık görüntüden `(int) $data['query_attempts'] + 1` diye
  hesaplıyordu. Zincirin her turu aynı eski değerden aynı `+1`i üretiyor;
  `$attempts >= MAX_ATTEMPTS` koşulu hiç sağlanmıyordu.
- **Uygulanan düzeltme:** Sayaç tek merkeze taşındı:
  `Order_Store::record_query_attempt()`. `Manager::query_status()` bu metodu
  taşıyıcı çağrısından **hemen sonra, sonuca bakmadan** çağırır ve deneme
  numarasını sonuç dizisinde `attempts` olarak döndürür. `save_status()`
  artık ne sayacı ne de `_kuka_shipping_last_queried_at` alanını yazar, yani
  başarılı sorguda çift artış yoktur. Poller kararı `$queried['attempts']`
  üzerinden verir; hiç çağrı yapılmayan erken retler
  (`carrier_not_registered`, `no_reference`) deneme saymaz. Onuncu başarısız
  sorgudan sonra yeni iş oluşmaz ve `poll_exhausted` sipariş metasına,
  geçmişine **ve** sipariş notuna yazılır.
- **Neden gerçek runner ile ölçüldü:** `decide()`'ı doğrudan çağıran bir test
  bu hatayı hiç görmezdi; hata tam olarak "poller sayacı nereden okuyor"
  sorusundaydı. Ölçüm bu yüzden gerçek Action Scheduler deposundan
  `ActionScheduler_QueueRunner::process_action()` ile — WP-Cron ve async
  runner'ların her action için çağırdığı metotla — kayıtlı hook üzerinden
  yürütülür.
- **Kanıt:**
  `SHIPPING_POLL_FAILURE_CHAIN_BOUNDED=PASS|runner:action_scheduler|actions_executed:10|external_status_reads:10|query_attempts:10|pending_after:0|eleventh_call:none|runner_errors:0|poll_exhausted_meta:yes|poll_exhausted_history:yes|poll_exhausted_note:yes`
  ve `SHIPPING_POLL_SUCCESS_CHAIN_INTACT=PASS|actions_executed:3|external_status_reads:3|query_attempts:3|attempts_equal_reads:yes|state:delivered`
- **İlgili dosyalar:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`record_query_attempt`, `query_attempts`, `save_status`),
  `SHIP/includes/shipping/class-shipment-manager.php` (`query_status`),
  `SHIP/includes/shipping/class-shipment-status-poller.php` (`run`)
- **Tekrar yaşanırsa ilk bak:** `run()` içinde sayacın nereden geldiği. Çağrı
  öncesi bir anlık görüntüden `+1` hesaplanıyorsa hata geri gelmiştir. Mali ve
  fatura sayaçları bu değişiklikten etkilenmez; bunlar ayrı modüldedir.

---

## K-17 — "Taşıyıcıdan bağımsız" iddiası kaynakta doğru değildi

- **Tarih:** 2026-09-03
- **Belirti:** —
- **Karar nedeni:** K-01 ve entegrasyon belgesi "ikinci taşıyıcı yalnız bir
  adaptör ve bir filtre ile eklenir" diyordu. Kaynak bunu doğrulamıyordu:
  - `Manager::default_carrier_key()` `DHL_Provider::KEY` sabitini döndürüyordu.
  - `Manager::write_fulfillment()` `instanceof DHL_Provider` yapıyor, başka her
    adaptör için takip numarası kaynağını koşulsuz "ölçülmedi" sayıyordu.
  - `Fulfillment_Writer::tracking_number()` `DHL_Config` sabitlerini
    karşılaştırıyordu.
  - Yönetim düğmesi `DHL gönderisi oluştur` diye sabitti.
  - Registry testi gerçek ikinci taşıyıcı yerine **ikinci kez DHL adaptörü**
    ekliyordu; bu test başarısız olamazdı, dolayısıyla hiçbir şey kanıtlamıyordu.
- **Uygulanan çözüm:**
  - Takip numarası seçimi sözleşmeye taşındı:
    `Carrier_Interface::TRACKING_SOURCE_*` sabitleri ve
    `get_tracking_number_source()` metodu. `DHL_Config` sabitleri artık bu
    sözleşme sabitlerinin takma adıdır, ikinci bir sözlük değil.
  - `default_carrier_key()` anahtarı `KUKA_SHIPPING_DEFAULT_CARRIER`
    yapılandırmasından veya `kuka_island_shipping_default_carrier` filtresinden
    alır. **Fail-closed:** kayıtlı olmayan anahtar olduğu gibi döner ve
    `carrier_not_registered` ile reddedilir; kayıtlı bir taşıyıcıyla ikame
    edilmez. Hiçbir şey yapılandırılmadıysa tek kayıtlı adaptör kullanılır,
    iki veya daha fazlası varsa anahtar boştur.
  - Yönetim metinleri `carrier->get_label()` üzerinden üretilir
    (`create_button_label()`, `resume_button_label()`).
  - Ölçüm gerçek, asgari bir **sahte ikinci adaptörle** yapılır: yalnız
    `Carrier_Interface` uygular, hiçbir DHL sınıfına ya da sabitine değmez,
    yalnız registry filtresine eklenir ve manager'ın oluşturma/sorgulama/iptal
    akışlarından geçer.
  - Ayrıca ortak sınıfların kaynağı taranır. Tarama öncesi PHP yorumları
    `token_get_all()` ile ayıklanır (K-06 dersi): bir bağımlılığın **neden
    kaldırıldığını** anlatan cümle, bağımlılığın kendisi sayılmaz.
- **Kanıt:**
  `SHIPPING_SECOND_CARRIER_ADAPTER_ONLY=PASS|carrier:kuka-test-kargo|create_order:1|create_barcode:1|status_reads:1|cancel_shipment:1|fulfillment_provider:kuka-test-kargo|fulfillment_tracking:FAKE-BC-1|state:cancelled|needs_no_dhl_class:yes|dhl_types_in_adapter:0`,
  `SHIPPING_DEFAULT_CARRIER_FAIL_CLOSED=PASS|two_registered_none_configured:refused|one_registered:kuka-test-kargo|filter_selects:kuka-test-kargo|unknown_key_returned_verbatim:kargo-yok|unknown_key_code:carrier_not_registered|carrier_calls_on_unknown:0`,
  `SHIPPING_CORE_NAMES_NO_ADAPTER=PASS|files:8|dhl_class_or_constant_references:0|comments_stripped:yes|scan_control_positive:yes`,
  `SHIPPING_ADMIN_TEXT_IS_CARRIER_AGNOSTIC=PASS|create_label:Kuka Test Kargo gönderisi oluştur`
- **İlgili dosyalar:**
  `SHIP/includes/shipping/interface-carrier-provider.php`,
  `SHIP/includes/shipping/class-shipment-manager.php`,
  `SHIP/includes/shipping/class-fulfillment-writer.php`,
  `SHIP/includes/shipping/class-shipment-admin.php`,
  `SHIP/includes/shipping/dhl/class-dhl-config.php`,
  `SHIP/includes/shipping/dhl/class-dhl-provider.php`,
  `scripts/verify-shipping-automation.php`
- **Tekrar yaşanırsa ilk bak:** `SHIPPING_CORE_NAMES_NO_ADAPTER` ölçümünün
  dosya listesi. Yeni bir ortak sınıf eklendiğinde o listeye girmelidir; yoksa
  bağımlılık sessizce geri döner.
- **Not:** Bu düzeltme Ö-03'ü **kapatmaz**. Hangi değerin gerçek takip numarası
  olduğu hâlâ ölçülmemiştir; değişen tek şey, cevabın nereden sorulduğudur.
