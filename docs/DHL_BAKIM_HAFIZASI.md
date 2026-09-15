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
| 2026-09-03 | İptal ve güncelleme kilitsizdi; iptal yalnız `reconcile_required` durumunu engelliyordu | Tek mutation kilidi, kilit içinde taze okuma, izin listesi ve `already_cancelled` (K-18) |
| 2026-09-03 | `make verify` izin listesi kararını gerçek runner'ı çalıştırıp `head -n 1` ile alıyordu; PHP'yi durduran şey SIGPIPE zamanlamasıydı | Açık çevrimdışı mod `--check-script`; süreç/ağ/kimlik okuması 0 ölçüldü (K-19) |
| 2026-09-03 | Suite temizliği, kendisinin oluşturmadığı CBS önbellek satırlarını da siliyordu | Anlık görüntü + bayt bayt geri yükleme; ölçüm "korundu, kalıntı 0" (K-20) |
| 2026-09-03 | Sipariş, mağazanın **güncel** varsayılan taşıyıcısına yönlendiriliyordu; timeout olan `createOrder` sahipliği kaybediyordu | Sahiplik ilk yazmadan önce sabitleniyor, tek merkezden çözülüyor, fail-closed (K-21) |
| 2026-09-03 | "Her taşıyıcı operasyonu ortak kapıdan geçer" yorumu okumalar için doğru değildi | `guarded_read()`; bloke okuma yokluk kanıtı değil (K-22) |
| 2026-09-03 | Test temizliği yalnız normal sona bağlıydı; assertion/fatal durumunda mağazanın önbelleği mock veriyle kalıyordu | Shutdown guard'lı, idempotent, sahipliği tam cleanup coordinator (K-23) |
| 2026-09-03 | Taşıyıcıya ulaşmış bir iptal, doğrulama başarısız olduğunda tekrar gönderilebiliyordu | `cancel_reconciliation_required`: kanıt yazmadan önce, çıkış yalnız okumayla (K-24) |
| 2026-09-03 | Belirsiz güncelleme, nesnenin varlığıyla başarılı sayılıyordu | `update_reconciliation_required` + alan bazında geri okuma; DHL `readback_unsupported` (K-25) |
| 2026-09-03 | Provider, yerel adres doğrulaması başarısız olsa bile sabitleniyordu | Pin `guarded_write()` içine, geçerli istekten sonra ve yazmadan hemen önce taşındı (K-26) |
| 2026-09-03 | Test önbelleği sahipliği çıkarma ile belirliyordu; cleanup wildcard silme kullanıyordu | Koşuya ait namespace + birebir ad bildirimi; wildcard silme kaldırıldı (K-27) |
| 2026-09-03 | Deaktivasyon bekleyen Action Scheduler işlerini gerçekte iptal etmiyordu | Boş args hash'i yerine hook+grup ile numaralayıp id bazında iptal (K-28) |
| 2026-09-03 | Kalıcı mutation intent yoktu; süreç istek uçarken ölürse durum `none` kalıyor ve ikinci yazma açılıyordu | Altı operasyonun tamamında intent gönderimden önce yazılıp veritabanından geri okunuyor (K-29) |
| 2026-09-03 | `guarded_write()`'ın `before_write`'ı `void`'di; kaydedilemeyen bir sabitleme kaydedilenden ayırt edilemiyordu | Callback doğrulama sonucu döndürüyor; `ok` değilse `$write()` hiç çağrılmıyor (K-30) |
| 2026-09-03 | "Kesin ret hiçbir şeyi değiştirmemiştir" varsayımı satıcı sözleşmesinde yazılı değildi | Allowlist boş; intent'i okumadan kapatan tek şey ağa çıkmamış `local_refusal` (K-31) |
| 2026-09-03 | Sonuç geçişleri iki save'di: durum ve intent temizliği ayrı yazılıyordu | `settle_mutation()` + tek yazma noktası `persist()` + sayaç ölçümü (K-32) |
| 2026-09-03 | `KUKA_DHL_ADAPTER` tanınmayan değerde açık kalıyordu; `flase` yazan operatör kargonun durduğunu sanıyordu | Tam eşleşme dışındaki her değer `configuration_invalid` → kapalı, ekranda yazılı (K-33) |
| 2026-09-03 | `fields_match()` iki tarafı da `trim()` ediyordu; "birebir" iddiası yanlıştı | Tek kanonik biçim gönderim öncesi uygulanıyor, karşılaştırmada sıfır tolerans (K-34) |
| 2026-09-03 | "Planlı durum sorgusu doğrulanmamış iptali izleyen tek şeydir" yorumu doğru değildi | Yorum düzeltildi; belge ve sipariş ekranı bunun **manuel** mutabakat olduğunu yazıyor (K-35) |
| 2026-09-03 | Eklenti etkinleştirilince pasif sözleşme suite'i sıfırdan farklı dönüyor ve `set -e` bütün kargo doğrulamasını kesiyordu | Cevaplanamaz üç ölçüm gerekçeli `SKIPPED`; yerlerine her iki durumda sorulabilen iki ölçüm (K-36) |
| 2026-09-03 | `has_carrier_evidence()` iki korumalı durumu ve intent kaydını kanıt saymıyordu; sahipsiz bir iptal-bekleyen kayıt varsayılan taşıyıcıya düşüyordu | Kanıt listesine iki durum + dolu `pending_mutation` eklendi (K-37) |
| 2026-09-03 | İptali kanıtlanmış bir sipariş üzerinden `createbarcode` gönderilebiliyordu; create kapısı deny-list soruyordu ve `cancelled` listede yoktu | Tek merkezî allow-list: createOrder 3 durum, createbarcode 1 durum; barkod aşaması ayrıca kapılandı (K-38) |
| 2026-09-03 | EDM pasifken gerçek `make verify` exit 2; 21 mock ölçümü `edm_runtime_disabled` ile düşüyordu | `Invoice_Manager`'a varsayılanı gerçek kapı olan enjekte edilebilir kapı; kapının kendi testi varsayılanı kullanır (K-39) |
| 2026-09-04 | Sandbox uygulamasında ürün aboneliği yoktu; uygulama anahtarı tek başına API erişimi vermiyordu | Identity 1.0.1, CBS Info, Standard Command, Barcode Command ve Standard Query Default Plan abonelikleri portalda tamamlandı; test müşteri numarası/parolası destekten istendi |
| 2026-09-04 | Identity OpenAPI içindeki örnek `customerNumber/password` değerlerinin ortak sandbox hesabı olabileceği kontrol edildi | Geçici kimlik dosyasıyla yapılan salt-okunur Identity çağrısı `401 unauthorized` verdi; örnekler kimlik değildir, gerçek dosya değişmedi ve geçici dosya temizlendi |
| 2026-09-04 | Gerçek üretim müşteri numarası + online şube şifresinin sandbox Identity'de geçebileceği hipotezi, operatör onayıyla TEK salt-okunur denemeyle ölçüldü | `401 unauthorized` (`reached_carrier:yes`, yazma 0, tekrar yok). Sandbox üretim kimliğini tanımıyor; test çifti yalnız MNG/DHL entegrasyon kanalından tanımlanır. Denenen değerler dosyadan geri çıkarıldı; şifre sohbet kanalına yazıldığı için online şube şifresi rotasyonu önerildi |
| 2026-09-04 | Fulfillment teslim tarihini modül değil WooCommerce'in data store'u yazıyordu; sözleşme hiçbir yerde yazılı değildi | Tarih artık modülün kendi kodunda, açık `+00:00` offset'iyle ve yalnız ilk geçişte yazılıyor (K-40) |
| 2026-09-04 | `reconcile_order()` kilit almayan tek dış giriş noktasıydı; uçuştaki bir yazmanın intent'ini kapatabiliyordu | Aynı mutasyon kilidi sıfır beklemeyle alınıyor, sipariş kilit içinde yeniden okunuyor; alt yardımcılar kilitsiz kalıyor (K-41) |
| 2026-09-04 | Taşıyıcıya hiç ulaşmayan yerel ret, poll zincirini ~14 gün boyunca yeniden planlıyor ve her turda not/geçmiş ekliyordu | `query_status()` artık `contacted` bilgisini döndürüyor; ulaşılmayan ret zinciri bitiriyor ve aynı gerçek bir kez kaydediliyor (K-42) |
| 2026-09-04 | Mutabakatla benimsenen gönderide `created_at` 0 kalıyor, ilk poll turu `MAX_ELAPSED` ile vazgeçiyordu | `save_shipment_created()` boşsa aynı atomik persist içinde `time()` yazıyor; mevcut değere dokunmuyor (K-43) |
| 2026-09-04 | Modülün kendi fulfillment kaydı `fulfilled` olurken müşteriye hiçbir e-posta gitmiyordu: bildirim olayı 0, mail denemesi 0 | Bildirim ilk geçişte modülün kendisi tarafından tetikleniyor; kalıcı ve çökme güvenli durum tek iletiyi garanti ediyor (K-44) |
| 2026-09-04 | Gönderim e-postasının Türkçesi makine çevirisiydi ("bir öğe yerine getirildi!", "Öğeniz yolda!") | Konu, başlık ve gövde Core'da doğal Türkçeye çevrildi; İngilizce sipariş doğal İngilizce metin alıyor (K-45) |
| 2026-09-04 | `WC_Email` nesnesi yeniden kullanıldığı ve bu iki e-postada `$this->object` `setup_locale()`'dan SONRA atandığı için, aynı istekteki ikinci bildirim dilini ÖNCEKİ siparişten alıyordu | Bildirim eylemi önceliği 9'da sipariş kenara yazılıyor ve dil anahtarında `$email->object`'ten önce geliyor (K-46) |
| 2026-09-04 | `FS_CHMOD_FILE` sabitinin varlığı bir eklentinin yükleme sırasına ve `get_filesystem_method()` cevabına bağlıydı | Core sabiti WordPress'in kendi formülüyle, yalnız tanımsızsa tanımlıyor; vendor dosyasına dokunulmadı (K-47) |
| 2026-09-04 | Gerçek SMTP açılınca e-posta kabul ölçümleri kırıldı: üçü panelden değişen marka adresini sabit yazıyordu, ikisi `mail()` yerine SMTP'ye kayıp her koşuda dışarı gerçek mesaj bırakıyordu | Ölçümler adres yerine tek kaynağa bağlandı; `disabled-mail` taşıyıcısı `isMail()`'e geri çekilip `DISABLED_MAIL_TRANSPORT` ile ölçülüyor (K-48) |
| 2026-09-04 | Kargo e-postası WooCommerce'in 600 pikselik varsayılan görünümünde, ham `dhl` taşıyıcı adıyla ve misafirde Hesabım bağlantısıyla gidiyordu; ürün fotoğrafı Gmail'de `localhost` adresi yüzünden görünmüyordu | Ortak tasarım katmanı, 780 pikselik mobil uyumlu iskelet ve tek görsel kapısı; sözleşme `docs/EPOSTA_TASARIMI.md` (K-49) |
| 2026-09-04 | İki eşzamanlı süreç aynı ilk `fulfilled` geçişine girip müşteriye iki e-posta gönderiyordu; ikinci yazma birincinin metasını ezdiği için durum makinesi tek gönderim gibi görünüyordu | Bildirim kararı sipariş bazlı sıfır beklemeli advisory lock altına alındı ve kayıtlar kilit içinde veritabanından taze okunuyor (K-50) |
| 2026-09-04 | Bildirim borcu iki yerden düşüyordu: `pending` durumu `first_transition` kapısına takılıp hiç yeniden denenmiyordu, ve kayıt kaydedildikten sonra niyetten önce ölen süreç borcu kalıcı olarak kaybediyordu | Yeni `due` durumu kayıt kaydedilmeden önce yazılıyor; `due` ve `pending` borçlu sayılıyor; teslim anı borçla birlikte bir kez yazılıyor (K-51) |
| 2026-09-04 | Claim'in üç sınırı fail-open'dı: kilit alınamasa, sipariş okunamasa ya da meta yazması diske düşmese bile kayıt `fulfilled` yapılıp e-posta gönderiliyordu | `claim()` artık ok/outcome/handover döndürüyor, yazma bayt bayt geri okunuyor ve başarısızlıkta fulfillment yazması hiç başlamıyor (K-52) |
| 2026-09-05 | Taşıyıcının ilk ve tek durumu doğrudan terminal olup claim reddedildiğinde kayıt `unfulfilled` kalıyor, e-posta gitmiyor, ama poller `stop:terminal_lifecycle` ile bitip hiçbir şey planlamıyordu | Manager `sync_status()` sonucunu değerlendiriyor; taşıyıcıya hiç dokunmayan sınırlı yerel bir fulfillment/bildirim retry'ı planlanıyor (K-53) |
| 2026-09-05 | Yerel retry sözleşmesinin dört sınırı yanlıştı: her planlama sonucu kanıt sayılıyor, taşıyıcı ve yerel sayaçlar birbirini eziyor, ilk gecikme 1 saniye oluyor ve operatör panelde hiçbir şey görmüyordu | Yalnız `created`/`already_pending` kanıt; sayaçlar ayrıldı; gecikme gerçek Action Scheduler satırından 120 sn ölçüldü; panel dört olguyu ve elle müdahale cümlesini gösteriyor (K-54) |
| 2026-09-05 | Yerel sync worker'ı, başarı olmadan da operatöre gösterilecek neden/deneme/planlama kaydını siliyordu; ayrıca kanıtlanmış yeni booking eski planlama hatasını temizlemiyordu | Tek yerleşim yolu `settle_sync()`: temizlik yalnız gerçek başarıda, nonretryable sonuç kalıcı ve tek notla görünür, `clear_sync_schedule_error()` yalnız planlama hatasını siliyor (K-55) |
| 2026-09-12 | DHL/MNG sandbox Identity çifti ulaştı; gerçek bağlantı ve üç kontrollü oluştur–barkod–sorgu–iptal turu çalıştırıldı | Kimlik ve CBS geçti; sorguların tek elemanlı liste zarfı ölçülüp ayrıştırıcı düzeltildi; son tur bütünüyle geçti ve açık taşıyıcı kaydı kalmadı (K-61) |
| 2026-09-12 | Gerçek CBS okumasından sonra davranış suite'i önceden var olan üretim önbelleğini test kalıntısı saydı | Kontrol transient'leri benzersiz test adlarına taşındı; başlangıçtaki bütün CBS satırları bitişte bayt düzeyinde aynı olmak zorunda (K-62) |

---

## Açık ölçümler — sandbox'a bağlanınca ilk yapılacaklar

Bunlar **bilinmeyen**dir, varsayım değil. Her biri ölçüldüğünde bu dosyaya
sonucu yazılır.

**2026-09-12 itibarıyla Ö-01…Ö-04 gerçek sandbox ile kapandı.** Ö-05 yalnız
satıcının resmî canlı uçlarıyla kapanabilir; canlı ortam bu yüzden blokeli
kalmaktadır.

### Ö-01 — `Authorization` başlığının biçimi

- **Durum:** ölçüldü — `Bearer <jwt>` ile Identity, Command ve Query çağrıları
  HTTP 200 verdi.
- **Neden belirsiz:** Identity API `jwt` adında bir alan döner. Command ve Query
  dokümanları `Authorization` adında, `string` tipinde, **zorunlu** bir başlık
  beyan eder ve biçimi hakkında tek kelime yazmaz.
- **Şu anki davranış:** yalnız `Bearer <jwt>` kabul edilir; eski `raw` kaçış
  yolu kapalıdır.
- **Kanıt:** gerçek `createOrder`, `getorder`, `createbarcode` ve
  `getshipmentstatus` çağrıları HTTP 200 verdi (K-61).
- **İlgili dosya:** `SHIP/includes/shipping/dhl/class-dhl-client.php`
  (`$authorization_scheme`).

### Ö-02 — CBS uçlarının token isteyip istemediği

- **Durum:** ölçüldü — token gönderilmeyen CBS şehir ve ilçe çağrıları HTTP 200
  verdi.
- **Neden belirsiz:** `CBS_Info_API-1.0.json` hiçbir operasyonunda
  `Authorization` parametresi beyan etmez — yalnız `x-api-version` ve global
  güvenlik bloğundaki ağ geçidi anahtarları vardır.
- **Şu anki davranış:** CBS çağrılarında token **gönderilmiyor**. Doküman böyle
  diyor.
- **Kanıt:** `DHL_SANDBOX_CBS_CITIES=PASS` ve
  `DHL_SANDBOX_CBS_DISTRICTS=PASS` (K-61).
- **İlgili dosya:** `SHIP/includes/shipping/dhl/class-dhl-client.php`
  (`is_cbs_operation`).

### Ö-03 — Hangi değer WooCommerce takip numarası

- **Durum:** ölçüldü — takip numarası `shipmentId`.
- **Neden belirsiz:** `createbarcode` yanıtı hem `shipmentId` hem parça bazında
  `barcodes[].value` döner. İkisi de "numara" gibi görünür.
- **Kanıt:** gerçek durum yanıtındaki HTTPS `trackingUrl`, dönen `shipmentId`
  değerini birebir taşıdı. `barcodes[].value` ise bir ZPL baskı gövdesidir ve
  takip numarası değildir (K-59, K-61).
- **İlgili dosyalar:** `SHIP/includes/shipping/class-fulfillment-writer.php`
  (`tracking_number`) ve seçimin geldiği yer:
  `SHIP/includes/shipping/interface-carrier-provider.php`
  (`TRACKING_SOURCE_*`, `get_tracking_number_source()`). K-17 cevabın **nereden
  sorulduğunu** değiştirdi; cevabın kendisi hâlâ ölçülmedi.

### Ö-04 — `recipient.customerId`

- **Durum:** alan gönderilmeden ölçüldü; gerçek `createOrder` ve
  `createbarcode` HTTP 200 verdi. Alanın atlanması kabul ediliyor.
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
- **Not (K-24, K-25):** Bu kayıt **create/barcode** için geçerlidir. Bir iptal
  ya da bir güncellemeden sonra genel `reconcile()` **kullanılmaz**: kaydı
  bulmak orada kanıt değildir. Onların kendi kanıt durumları ve kendi
  mutabakatları vardır.
- **Not (K-22):** Üçüncü bir cevapsızlık biçimi var: okuma **hiç yapılamazsa**
  (kapı kapalı) verdict `blocked` olur. Bu da yokluk değildir; durum
  `reconcile_required` kalır ve hiçbir şey yazılmaz.
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
- **Bu kayıt tamamlanmamıştı (K-24):** doğrulayan sorgu düzeltildi, fakat
  doğrulama **başarısız olduğunda** sipariş eski durumunda kalıyor ve iptal
  düğmesi canlı kalıyordu. Gönderilmiş bir iptal artık kalıcı bir kanıt durumu
  yazar. Bkz. K-24.
- **Not (K-22):** Doğrulama okuması artık `guarded_read()` üzerinden geçer.
  Kapı yazma ile okuma arasında kapanırsa sonuç `cancel_unconfirmed` olur, durum
  değişmez ve sorgu zinciri iptal edilmez.
- **Bu kayıt tamamlanmamıştı (K-18):** doğrulama nesnesi düzeltildi ama
  `cancel()` hâlâ **kilitsizdi** ve yalnız `reconcile_required` durumunu
  engelliyordu. İki eşzamanlı basış iki iptal yazımı gönderebiliyor,
  `cancelled` durumundaki bir sipariş yeniden iptal edilebiliyordu. Dalın hangi
  nesneyi seçtiği artık **durum**dan gelir, `shipment_id`'nin dolu olup
  olmamasından değil: `shipment_created` fakat boş `shipment_id` durumunda eski
  kod `cancel_order` gönderiyordu — yine yanlış nesne. Bkz. K-18.

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
    `shipment_created` görüp durur. (K-18 ile bu kilit `kuka_ship_mutate_<id>`
    adını aldı ve güncelleme ile iptali de kapsıyor.)
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
- **Not (K-20 turu):** `poll_exhausted` ve başarısız sorgu mesajları deneme
  numarasını taşıdığı için msgid'leri değişti; çeviri kataloğu bu yüzden
  yeniden üretildi ve `SHIPPING_POT_CATALOG` ölçümü kaynakla katalogun birebir
  örtüştüğünü sayıyor.

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
- **Bu kayıt tamamlanmamıştı (K-21):** "ikinci taşıyıcı yalnız adaptör
  eklemekle" iddiası doğruydu, fakat ikinci taşıyıcı **eklendiği anda** eski
  kayıtların sahipliği kayboluyordu: her giriş noktası boş anahtarda güncel
  varsayılana düşüyordu. Bkz. K-21.
- **Not:** Bu düzeltme Ö-03'ü **kapatmaz**. Hangi değerin gerçek takip numarası
  olduğu hâlâ ölçülmemiştir; değişen tek şey, cevabın nereden sorulduğudur.
- **Bu kayıt tamamlanmamıştı (K-18):** ortak `preflight()` metodu oluşturma
  politikasını (kapıda ödeme) da içeriyordu, bu yüzden güncelleme ve iptali ona
  bağlamak COD siparişini geri alınamaz hâle getirirdi. Kapı ikiye ayrıldı.
  Ayrıca `update_shipment()` ve `cancel()` o metottan hiç geçmiyordu: çalışma
  kapısı kapalıyken bile taşıyıcıya yazabiliyorlardı. Bkz. K-18.

---

## K-18 — Mutation kilidi ve terminal durum idempotency'si

- **Tarih:** 2026-09-03
- **Belirti:** Sipariş ekranında `Taşıyıcı kaydını iptal et` düğmesine iki kez
  hızlı basıldığında taşıyıcıya iki `cancelshipment` gidiyor. Ya da: iptal
  başarıyla tamamlandıktan sonra aynı düğme üçüncü kez basıldığında yine bir
  iptal yazımı gidiyor. Ya da: iptal başarılıyken gecikmiş bir `updateshipment`
  artık var olmayan bir kayda gönderiliyor.
- **Kesin kök neden:** Advisory kilidi yalnız **oluşturma** yolu alıyordu
  (`kuka_ship_create_<id>`). `cancel()` ve `update_shipment()` hiç kilit
  almıyor, durumu çağrı **öncesinde** alınmış anlık görüntüden okuyordu.
  Üstüne, `cancel()` yalnız `reconcile_required` durumunu engelliyordu — yani
  `cancelled`, `delivered`, `absent_confirmed`, `none` ve tanımadığı her durum
  yazma yapabiliyordu. `update_shipment()` isteği durum kontrolünden **önce**
  kuruyordu.
- **Uygulanan düzeltme:**
  1. Kilit tek aile: `kuka_ship_mutate_<id>`. Oluşturma, barkod sürdürme,
     güncelleme ve iptal aynı anahtarı alır. Kilidi alamayan `lock_contended`
     ile hemen döner.
  2. Durum, `shipment_id` ve referans **kilit içinde** yeniden okunur; taşıyıcı
     isteği o okumadan kurulur.
  3. Yazabilen durumlar **izin listesi**dir, yasak listesi değil.
     `cancel()`: `order_created` → `cancelorder`, `shipment_created` + dolu
     `shipment_id` → `cancelshipment`. `update_shipment()`: aynı iki durum.
     Diğer her şey 0 yazma.
  4. `cancelled` durumu ayrı ve sabit bir kodla döner: `already_cancelled`.
  5. `shipment_created` fakat `shipment_id` boş → `not_cancellable`. Gönderi
     var, numarası bilinmiyor; onun yerine **siparişi** iptal etmek yanlış
     nesneye istek göndermek olurdu (bkz. K-14).
- **Neden izin listesi:** yasak listesi, durum makinesine yeni bir değer
  eklendiği ilk anda delik verir. Tanımadığı bir durumda yazan bir iptal, bu
  modüldeki en pahalı hata sınıfıdır.
- **Neden gerçek ikinci MySQL oturumu:** advisory kilit **bağlantı başına**
  tutulur. Tek bağlantıda yapılan iki ardışık PHP çağrısı kilidi özyinelemeli
  alır ve hiçbir şey ölçmez. Ölçüm ikinci bir `wpdb` örneği açar ve iki farklı
  `CONNECTION_ID()` olduğunu kanıtlamadan devam etmez.
- **Kanıt:**
  `SHIPPING_SECOND_DB_SESSION=PASS|separate:yes`,
  `SHIPPING_MUTATION_LOCK_IS_ONE_FAMILY=PASS|create:lock_contended|resume:lock_contended|update:lock_contended|cancel:lock_contended|carrier_writes:0`,
  `SHIPPING_CANCEL_SERIALISED_AND_IDEMPOTENT=PASS|concurrent_call:lock_contended|writes_while_lock_held:0|second:already_cancelled|stale_handle:already_cancelled|total_carrier_writes:1`,
  `SHIPPING_CANCEL_REFUSES_EVERY_OTHER_STATE=PASS|states_checked:9|wrong:none|carrier_writes:0`,
  `SHIPPING_UPDATE_SERIALISED_AND_FRESH=PASS|late_update_from_stale_handle:nothing_to_update|total_updates:1`,
  `SHIPPING_UPDATE_REFUSES_EVERY_OTHER_STATE=PASS|states_checked:8|carrier_writes:0`
- **Ayrıca:** ortak güvenlik kapısı ikiye ayrıldı.
  `carrier_gate()` (taşıyıcı/runtime/ortam/kimlik) **her** operasyonun,
  `create_policy()` (kapıda ödeme) yalnız oluşturma ve barkod sürdürmenin
  sınırıdır. Kapıda ödeme kontrolünü iptale de uygulamak COD siparişini geri
  alınamaz hâle getirirdi. Altı yazmanın tamamı `guarded_write()` boğazından
  geçer ve o boğaz kapıyı **yazmadan hemen önce yeniden** sorar; giriş kontrolü
  kilitten önceydi ve arada eklenti devre dışı bırakılabilir.
  Kanıt: `SHIPPING_MUTATION_GATE_SHARED=PASS|doors:create+resume+update+cancel|conditions:3|wrong:none`,
  `SHIPPING_GATE_RECHECKED_UNDER_LOCK=PASS|doors:4|create:credentials_missing(checks:2,writes:0)|...`,
  `SHIPPING_RUNTIME_GATE_CLOSED_MIDFLIGHT=PASS|carrier_writes:0`
- **İlgili dosyalar:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`carrier_gate`, `create_policy`, `gate_closed_now`, `guarded_write`,
  `cancel`, `update_shipment`, `cancel_refusal_message`,
  `update_refusal_message`),
  `SHIP/includes/shipping/class-shipment-admin.php` (düğme koşulları)
- **Tekrar yaşanırsa ilk bak:** `cancel()` ve `update_shipment()` içinde
  `acquire_lock` çağrısının var olup olmadığı, ve durum kontrolünün `wc_get_order()`
  yeniden okumasından **sonra** gelip gelmediği. Bir de `$carrier->` ile
  başlayan yazma çağrılarının hepsinin `guarded_write()` içinde olup olmadığı:
  `grep -nE '\\$carrier->(create|update|cancel)' ` altı satır vermeli ve altısı
  da closure içinde olmalı.

---

## K-19 — Çevrimdışı doğrulama SIGPIPE'a güvenmemeli

- **Tarih:** 2026-09-03
- **Belirti:** `make verify` çıktısında kargo bloğu yeşil, fakat kimlikler
  yerindeyken koşu taşıyıcıya gerçekten bağlanmış olabilir. Belirtisi yok —
  bu kaydın konusu tam olarak **belirtisi olmayan** bir risktir.
- **Kesin kök neden:** `verify.sh`, izin listesi kararını almak için gerçek
  komutu çalıştırıp çıktısının yalnız ilk satırını okuyordu:

  ```sh
  ./scripts/dhl-test-run.sh test-dhl-sandbox.php | head -n 1
  ```

  `dhl-test-run.sh` `DHL_TEST_RUN=STARTING` satırını basar, sonra
  `exec docker compose run ...` yapar. `head -n 1` ilk satırı alıp çıkar, boru
  kapanır ve docker istemcisi EPIPE ile ölür. **Konteynerin PHP'si çalışmaz —
  ama bunu sağlayan şey bir kural değil, zamanlamadır.** Daha yavaş bir okuyucu
  ya da daha hızlı bir konteyner, `make verify`'ı gerçek bir Identity çağrısına
  ve CBS il listesi okumasına götürürdü.
- **Ölçülen:** Aynı boru düzeneği zararsız bir işaretleyici betikle kuruldu.
  Boru açıkken işaretleyici yazıldı; `head -n 1` ile boru kapanınca yazılmadı.
  Yani bugünkü davranış güvenliydi, garanti değildi.
- **Uygulanan düzeltme:** `dhl-test-run.sh` içine açık bir çevrimdışı mod:
  `--check-script=<ad>`. Yalnız izin listesi kararını döndürür; kimlik
  dosyasını okumaz, `stat`'lamaz, mount etmez, Docker başlatmaz, PHP
  çalıştırmaz, ağa çıkmaz. Karar `allowlist_reason()` fonksiyonundan gelir ve
  enforce eden yol **aynı fonksiyonu** kullanır, bu yüzden çevrimdışı cevap
  uygulanan cevaptan sapamaz. Operatör komutunun davranışı değişmedi.
- **Nasıl kanıtlanıyor:** `scripts/verify-dhl-runner-offline.sh`
  `docker`, `docker-compose`, `php`, `wp`, `curl`, `wget` ve `nc` için
  işaretleyici yazan shim'lerle `PATH`'i öne alır ve **kimlikleri 4/4 mevcut
  gösteren** geçici bir fixture altında çalıştırır: hiçbir shim çağrılmıyor.
  Önce bir **pozitif kontrol** çalışır — shim'ler kasten aynı `PATH` üzerinden
  çağrılır ve işaretleyicinin gerçekten yazıldığı doğrulanır. Aksi hâlde hiç
  danışılmayan bir `PATH`, hiçbir şey başlatmayan bir sarmalayıcıyla aynı sessiz
  PASS'i üretirdi.
  İkinci fixture kimlik dizinini `000` yapar — dosyaya dokunmak isteyen kod
  hata verirdi; mod aynı cevabı veriyor. Fixture'daki sahte nöbetçi değer
  çıktıda hiç görünmüyor. Operatörün gerçek dosyası bu ölçümlerin hiçbirinde
  kullanılmaz: her koşu için `XDG_CONFIG_HOME` geçici bir dizine bakar.
- **Kanıt:**
  `DHL_RUNNER_OFFLINE=PASS|mode:offline_allowlist_check|allowlisted_answered:yes|refusals:8/8|credentials_4of4_fixture:yes|credential_value_in_output:no|answer_identical_with_unreadable_credential_dir:yes|processes_launched:0|network_calls:0`,
  `DHL_RUNNER_ENFORCED_REFUSALS=PASS|refused:5/5|processes_launched:0|operator_command_unchanged:yes`,
  `DHL_RUNNER_ALLOWLIST=mode:offline_allowlist_check|leaks:0|allowlisted_decision:yes|credentials_read:no|docker_started:no|php_started:no|network_calls:0|write_tool_refusals:4/4`
- **İlgili dosyalar:** `scripts/dhl-test-run.sh` (`allowlist_reason`,
  `--check-script`), `scripts/verify-dhl-runner-offline.sh`,
  `scripts/verify.sh`
- **Not:** EDM runner'ında (`scripts/edm-test-run.sh`) **aynı desen duruyor**.
  Bu tur yalnız DHL sınırını düzeltti; EDM tarafı ayrı bir iştir.
- **Tekrar yaşanırsa ilk bak:** `verify.sh` içinde `dhl-test-run.sh` çağrısının
  `--check-script=` ile mi yapıldığı. Boruya bağlı bir `head -n 1` görürsen
  hata geri gelmiştir.

---

## K-20 — Test, önceden var olan önbelleği silmemeli

- **Tarih:** 2026-09-03
- **Belirti:** Doğrulama koşusundan sonra `wp_options` içindeki dört
  `kuka_dhl_cbs*` transient kaydı yok. Kimse silmeyi istemedi.
- **Kesin kök neden:** Her senaryo adres çözmeden **önce**
  `Address_Resolver::purge_cache()` çağırır — doğru bir davranış, çünkü dolu bir
  önbellek mock'un `/getcities` çağrısını hiç yapmamasına ve çağrı sayaçlarının
  anlamını kaybetmesine yol açar. Fakat suite'in temizlik bloğu da aynı purge'ü
  çağırıyordu, yani koşu **kendisinin oluşturmadığı** satırları da siliyordu.
  Silinen veriler mock kaynaklıydı (1 il / 1 ilçe), ama bu tesadüftü: gerçek 81
  illik bir liste de aynı şekilde silinirdi.
- **Uygulanan düzeltme:** Suite artık **ödünç alıyor**.
  1. İlk adres çözümünden önce `kuka_dhl_cbs*` option satırlarının tamamı
     anlık görüntüye alınır: `option_name`, `option_value`, `autoload` —
     timeout eşlik satırları dahil.
  2. Sonunda geri yüklenir. Hâlâ var olan satır `UPDATE` edilir (silinip
     yeniden yazılmaz), eksik satır `INSERT` edilir, anlık görüntüde olmayan
     satır **koşunun kendi kalıntısıdır** ve silinir. Nesne önbelleği anahtarları
     da düşürülür, yoksa aynı süreçte sonraki `get_transient()` az önce
     değiştirilen değeri döndürürdü.
  3. Ölçüm "kalan kayıt 0" değil, **"önceden var olan korundu, koşuya ait
     kalıntı 0"**.
- **Neden pozitif kontrol gerekti:** bugünkü anlık görüntü boş olabilir ve boş
  bir anlık görüntünün geri yüklenmesi hiçbir şey kanıtlamaz. Bu yüzden ayrı bir
  ölçüm nöbetçi bir değer **eker**, gerçek bir senaryonun üzerine yazmasına izin
  verir, geri yükler ve değerin bayt bayt döndüğünü doğrular.
- **Kanıt:**
  `SHIPPING_CBS_CACHE_PRESERVED=PASS|control:planted_then_overwritten_then_restored|overwritten_by_run:yes|value_and_timeout_rows:2|fingerprint_recovered:yes|value_identical:yes|bytes_identical:yes`
  ve `SHIPPING_FIXTURES_REMOVED=PASS|...|cache_keyset_identical:yes|cache_fingerprint_identical:yes|run_owned_cache_residue:N`
- **`option_id` neden parmak izinde yok:** silinip yeniden yazılan bir transient
  yeni bir satır kimliğine düşer ama aynı değeri taşır. Mağazanın hiç okumadığı
  bir tanımlayıcı, korunması gereken şeyin parçası değildir. Parmak izi ad,
  değer ve `autoload` üzerinden alınır.
- **Önceki koşuda silinen dört satır geri getirilmedi:** içerikleri mock
  kaynaklıydı ve uydurma önbellek verisi yazmak, silmekten daha kötüdür. Bir
  günlük TTL'li bu önbellek ilk gerçek çağrıda kendini yeniden kurar.
- **İlgili dosya:** `scripts/verify-shipping-automation.php`
  (`kuka_ship_cbs_rows`, `kuka_ship_cbs_fingerprint`, `kuka_ship_cbs_restore`)
- **Tekrar yaşanırsa ilk bak:** temizlik bloğunda `purge_cache()` çağrısı olup
  olmadığı. Orada bir purge görürsen, koşu yine mağazanın verisini siliyor.
- **Bu kayıt tamamlanmamıştı (K-23):** geri yükleme yalnız dosyanın altında,
  yani **normal sonda** çalışıyordu. Bir ölçüm patladığında ya da fatal
  olduğunda hiç çalışmıyordu. Bkz. K-23.

---

## K-21 — Sipariş, mağazanın güncel varsayılan taşıyıcısına yönlendiriliyordu

- **Tarih:** 2026-09-03
- **Belirti:** İkinci taşıyıcı eklenip varsayılan değiştirildikten sonra:
  sipariş ekranı eski DHL siparişini yeni kuryenin adıyla gösteriyor; durum
  sorgusu yeni kuryeye gidiyor ve "kayıt yok" cevabı alıyor; iptal düğmesi
  paketi hiç görmemiş bir kuryeye `cancelorder` gönderiyor ve o kuryenin
  `not_found` cevabı **iptalin kanıtı** sayılıyor. Sonuç: sipariş `cancelled`
  yazılıyor, sorgu zinciri iptal ediliyor, canlı paket izlenmez oluyor.
- **Kesin kök nedenler — dört tane:**
  1. `render_meta_box()` siparişin `_kuka_shipping_provider` değerini hiç
     okumuyor, `default_carrier_key()` kullanıyordu.
  2. `reconcile_order()`, `query_status()`, `resume_barcode()`,
     `update_shipment()` ve `cancel()` boş `carrier_key` aldığında — yani
     poller'ın ve yönetim ekranının yaptığı her çağrıda — siparişte saklanan
     provider yerine **güncel** varsayılanı seçiyordu.
  3. `META_PROVIDER` yalnız `save_order_created()` tarafından, yani taşıyıcının
     **onayladığı** bir `createOrder`dan sonra yazılıyordu. İlk `createOrder`
     timeout dönerse hangi taşıyıcının sahibi olduğu kayboluyor, mutabakat da
     o an varsayılan olanı okuyordu.
  4. Sahiplik hiçbir yerde tek merkezden çözülmüyordu; her giriş noktası kendi
     `registry->get( ... ?: default )` satırını taşıyordu.
- **Uygulanan çözüm:**
  - Tek merkez: `Manager::carrier_ownership()` (yan etkisiz karar) ve
    `resolve_carrier()` (aynı karar + registry + ret kaydı). `admit()` ikisini
    kapıyla birlikte uygular ve **her mutation kilidi alındıktan sonra tekrar**
    çağrılır.
  - Üç durum, yalnız biri varsayılanı kullanabilir: **pinned** (siparişteki
    provider), **orphaned** (taşıyıcı kanıtı var, provider yok →
    `shipment_provider_missing`, dış çağrı 0), **untouched** (hiç çağrı
    yapılmamış → varsayılan, ve ilk yazmadan önce sabitlenir).
  - Açıkça verilen `carrier_key` kayıtlı provider ile çelişirse
    `shipment_provider_mismatch`; dış çağrı 0, siparişin provider'ı değişmez.
  - `Order_Store::begin_mutation()` provider'ı, referansı, referans geçmişini,
    operasyona ait **korumalı durumu** ve kalıcı **intent kaydını** tek save
    içinde, ilk dış yazmadan önce yazar; sonra siparişi veritabanından taze
    okuyup birebir doğrular ve doğrulama geçmezse yazma hiç yapılmaz. Pin bu
    yüzden timeout'tan, intent ise süreç ölümünden sağ çıkar (K-29, K-30).
    `save_order_created()` artık yalnız alan boşsa yazar; mutabakat siparişin
    sahibini değiştiremez.
  - `has_carrier_evidence()` kanıt listesine `cancel_reconciliation_required`,
    `update_reconciliation_required` ve **dolu bir `pending_mutation` kaydı** de
    dâhildir (K-37). Bunlar `begin_mutation()` olmadan oluşamaz, dolayısıyla
    provider'sız bulunmaları varsayılana düşme gerekçesi değil, en güçlü
    fail-closed gerekçesidir.
  - Yönetim paneli `carrier_ownership()` sorar. Bu metot **yan etkisiz** olmak
    zorundadır: `resolve_carrier()` reddi siparişe kaydeder ve not düşer, ve bir
    sayfa render'ı her yüklemede not bırakamaz.
- **Neden varsayılan yasak:** varsayılan, paketi olan bir tahmindir. Yanlış
  kuryeye sorulan "bu gönderi sende mi" sorusunun cevabı her zaman "hayır"dır ve
  bu kod "hayır"ı yokluk kanıtı sayar.
- **Kanıt (iki kayıt tutan adaptör, sayaçlar ayrı):**
  `SHIPPING_PROVIDER_AFFINITY=PASS|stored_provider:dhl|default_now:kuka-other-kargo|dhl.status_reads:1|dhl.reconcile_reads:1|dhl.updates:1|dhl.cancels:1|dhl.cancel_confirm_reads:1|other.contacts:0`,
  `SHIPPING_PROVIDER_AFFINITY_RESUME=PASS|dhl.createbarcode:1|dhl.createOrder:0|other.contacts:0`,
  `SHIPPING_PROVIDER_PINNED_BEFORE_FIRST_WRITE=PASS|measured:database_read_inside_the_first_write|provider_at_first_write:kuka-test-kargo|reference_at_first_write:stored`,
  `SHIPPING_UNCERTAIN_CREATE_RETAINS_PROVIDER=PASS|provider_after_timeout:dhl|dhl.createOrder_total:1|dhl.second_createOrder:0|other.contacts:0`,
  `SHIPPING_PROVIDER_MISMATCH_FAILS_CLOSED=PASS|doors:6|wrong:none|dhl.requests:0|other.contacts:0|stored_provider_unchanged:yes`,
  `SHIPPING_LEGACY_MISSING_PROVIDER_FAILS_CLOSED=PASS|carrier_evidence:yes|doors:6|dhl.requests:0|other.contacts:0|default_written_in:no`,
  `SHIPPING_UNTOUCHED_ORDER_USES_DEFAULT=PASS|before_create:kuka-test-kargo(default)|after_create:kuka-test-kargo(order)`,
  `SHIPPING_PROVIDER_FRESH_UNDER_LOCK=PASS|entry_answer:kuka-other-kargo|winner:in_lock_reading|entry_default.contacts:0`,
  `SHIPPING_ADMIN_USES_STORED_PROVIDER=PASS|pinned_order:kuka-pinned-kargo(order)|untouched_order:kuka-other-kargo(default)|render_wrote_notes:0`
- **İlgili dosyalar:**
  `SHIP/includes/shipping/class-shipment-order-store.php`
  (`provider`, `has_carrier_evidence`, `begin_mutation`,
  `save_order_created`),
  `SHIP/includes/shipping/class-shipment-manager.php`
  (`carrier_ownership`, `resolve_carrier`, `admit`, tüm giriş noktaları),
  `SHIP/includes/shipping/class-shipment-admin.php`
  (`render_meta_box`, `carrier_label`, `operator_hint`)
- **Tekrar yaşanırsa ilk bak:** `default_carrier_key()` çağrılarının nerede
  olduğu. `carrier_ownership()` dışında bir yerde geçiyorsa hata geri gelmiştir.
  Bir de `SHIPPING_PROVIDER_FRESH_UNDER_LOCK`: kilit altındaki kararın kilit
  öncesindekini yenmesi bu ölçümle kilitli.

---

## K-22 — "Her taşıyıcı operasyonu kapıdan geçer" yorumu doğru değildi

- **Tarih:** 2026-09-03
- **Belirti:** —
- **Kesin kök neden:** Manager sınıf docblock'u "EVERY carrier operation
  crosses carrier_gate()" diyordu. Üç okuma operasyonundan (`read_shipment`,
  `read_order`, `read_shipment_status`) ikisinin çağrıcısı — `reconcile_order()`
  ve `query_status()` — kapıyı **hiç** kontrol etmiyordu, hiçbiri de dış
  çağrıdan hemen önce yeniden kontrol etmiyordu. Yorum, kodun yapmadığı bir şeyi
  anlatıyordu; bu, sonraki geliştiriciyi yanlış yönlendiren en pahalı hata
  türüdür.
- **Uygulanan çözüm:** `Manager::guarded_read()`. Üç okumanın tamamı buradan
  geçer, kapı okumadan hemen önce yeniden sorulur, ve metot bir `Result` değil
  **ret** döndürür.
- **Neden ret, Result değil:** `reconcile()` yokluğu `not_found`dan kanıtlar.
  Kapalı kapı `not_found` döndürseydi, yokluğu **kapalı olduğu için** kanıtlamış
  olurdu ve bu, ikinci bir `createOrder`a izin verirdi — aynı paketin iki kez
  kargolanması. Bloke okuma sonucu:
  `reconcile()` → verdict `blocked`, durum `reconcile_required` kalır;
  `query_status()` → gate kodu, **deneme harcanmaz**;
  iptal doğrulaması → `cancel_unconfirmed`, durum değişmez, sorgu zinciri iptal
  edilmez.
- **Kanıt:**
  `SHIPPING_READ_GATE_SHARED=PASS|operations:3|status_read.reads:0|status_read.attempt_spent:no|reconcile.verdict:blocked|reconcile.reads:0|reconcile.state:reconcile_required|cancel_confirm.code:cancel_unconfirmed|cancel_confirm.writes:1|cancel_confirm.reads:0`
  ve `SHIPPING_UNCERTAIN_READ_BLOCKED_STAYS_UNCERTAIN=PASS|createOrder_calls:1|reconcile_reads:0|state:reconcile_required|absence_assumed:no|total_writes:1`
- **Nasıl ölçüldü:** iptal doğrulaması için sahte adaptör, yazmayı kabul ettiği
  **anda gerçek çalışma kapısını kapatıyor**. Yani kapının kapanması yazma ile
  okuma arasına, tam olarak acıdığı yere yerleştiriliyor; çağrı sırası tahmin
  edilmiyor.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`guarded_read`, `reconcile_blocked`, `reconcile`, `query_status`, `cancel`)
- **Tekrar yaşanırsa ilk bak:** `grep -nE '\\$carrier->(read_|create_|update_|cancel_)'`
  on bir satır vermeli ve on biri de closure içinde olmalı — altısı
  `guarded_write()`, beşi `guarded_read()`.

---

## K-23 — Test temizliği yalnız normal sona bağlıydı

- **Tarih:** 2026-09-03
- **Belirti:** Doğrulama koşusu bir assertion'da patladıktan sonra mağazanın
  `kuka_dhl_cbs*` önbelleğinde tek illik mock verisi kalıyor.
- **Kesin kök neden:** K-20'de eklenen anlık görüntü/geri yükleme dosyanın
  **altında** çalışıyordu. Bir ölçüm başarısız olduğunda `WP_CLI::error()`
  süreci bitirir; fatal hata daha da sert bitirir. İki durumda da geri yükleme
  hiç çalışmıyordu ve mağazanın satırları mock veriyle kalıyordu.
- **Uygulanan çözüm:** `Kuka_Shipping_Cache_Custodian`
  (`scripts/lib-shipping-cache-custodian.php`). Anlık görüntü alınır **alınmaz**
  `register_shutdown_function()` ile geri yükleme kaydedilir. Normal yol da aynı
  metodu çağırır; metot idempotenttir, hangisi önce çalışırsa o kazanır, ikinci
  çağrı no-op'tur.
  - Sahiplik **tam**, çıkarım değil: anlık görüntü koşudan önceki **birebir
    option adlarını** tutar. Sonrasında adı o kümede olan satır kaydedilen
    değer ve `autoload` ile geri yazılır; adı kümede olmayan satır koşunun
    kendisine aittir ve silinir. Zaman damgasından ya da değerin şeklinden
    hiçbir şey tahmin edilmez.
  - Bir yazma reddedilirse (`$wpdb->update`/`insert`/`delete` `false`) sonuç
    `ok:false` olur; **temizlik başarılı raporlanmaz**.
- **Nasıl ölçüldü:** `scripts/verify-shipping-cache-custodian.sh` üç ayrı süreç
  kullanır: birinci süreç nöbetçi bir satır eker ve parmak izini basar; ikinci
  süreç custodian'ı kurar, önbelleği gerçek bir senaryo gibi kirletir ve **ölür**;
  üçüncü süreç nöbetçinin bayt bayt döndüğünü ve koşuya ait satır kalmadığını
  ölçer. İki ölüm biçimi ayrı ayrı ölçülür — açık çıkış (`WP_CLI::error()`) ve
  yakalanmayan fatal (var olmayan bir fonksiyon çağrısı) — çünkü PHP'den farklı
  kapılardan çıkarlar.
- **Kanıt:**
  `SHIPPING_CACHE_CUSTODIAN_exit=PASS|measured:separate_process|cache_dirtied_before_death:yes|restored_by:shutdown_guard|fingerprint_match:yes|sentinel_value_intact:yes|run_owned_rows_left:0`,
  `SHIPPING_CACHE_CUSTODIAN_fatal=PASS|...|run_owned_rows_left:0`,
  `SHIPPING_CBS_CACHE_PRESERVED=PASS|coordinator:shared|refused:0|second_call_is_noop:yes`,
  `SHIPPING_FIXTURES_REMOVED=PASS|cache_keyset_identical:yes|cache_fingerprint_identical:yes|cache_restore_refused:0`
- **İlgili dosyalar:** `scripts/lib-shipping-cache-custodian.php`,
  `scripts/verify-shipping-cache-custodian.php`,
  `scripts/verify-shipping-cache-custodian.sh`,
  `scripts/verify-shipping-automation.php`
- **Bu kayıt tamamlanmamıştı (K-27):** sahiplik **çıkarma** ile
  belirleniyordu — "anlık görüntümde yoktu, demek ki benim" — ve cleanup fazı
  geniş bir `DELETE ... LIKE` kullanıyordu. Koşu artık kendi anahtar alanında
  çalışır ve yalnız birebir bildirdiği adları siler. Bkz. K-27.
- **Tekrar yaşanırsa ilk bak:** custodian'ın `guard()` çağrısının anlık
  görüntüden hemen sonra gelip gelmediği. Kontrol ölçümünde ikinci bir custodian
  **guard edilmez**: nöbetçi bir fixture'dır, mağazanın verisi değil, ve ikinci
  bir shutdown fonksiyonu onu dıştaki geri yükleme sildikten sonra tekrar geri
  koyardı.

---

## K-24 — Gönderilmiş bir iptal tekrar gönderilebiliyordu

- **Tarih:** 2026-09-03
- **Belirti:** `Taşıyıcı kaydını iptal et` basılıyor, taşıyıcı kabul ediyor,
  doğrulama sorgusu cevap veremiyor (ya da kaydı "hâlâ var" diye buluyor).
  Sipariş `shipment_created` kalıyor, düğme canlı kalıyor, ikinci basış aynı
  iptali **tekrar** gönderiyor. Ya da: `uncertain` iptalden sonra genel
  mutabakat kaydı buluyor, `shipment_created` yazıyor ve düğmeyi yeniden açıyor.
- **Kesin kök neden — iki tane:**
  1. Yalnız `uncertain` iptal kapıyı kapatıyordu. `success` cevabı ise
     "acknowledgement"tan fazlası sayılıp doğrulama başarısız olduğunda **eski
     durum korunuyordu**. Oysa `success`, ağ geçidinin isteği aldığını söyler;
     iptalin uygulandığını söylemez.
  2. `uncertain` iptal `reconcile_required`a gidiyordu ve o durumdan çıkış
     **genel** `reconcile()` ile oluyordu. O metot bir CREATE için yazılmıştır:
     kaydı bulduğunda `shipment_created` yazar. İptalden sonra bu tam tersidir
     — kaydı bulmak iptalin **kanıtlanmadığı** anlamına gelir — ve
     `shipment_created` yazmak iptal düğmesini yeniden açar.
- **Uygulanan düzeltme:** İptale ait kalıcı, ayrı bir kanıt durumu:
  `STATE_CANCEL_RECONCILE_REQUIRED` (`cancel_reconciliation_required`), yanında
  `META_PENDING_MUTATION` içinde hangi nesnenin adreslendiği ve öncesinde hangi
  durumda olunduğu.
  - Durum, yazma taşıyıcıya ulaşır ulaşmaz — **okuma yapılmadan önce** —
    yazılır. `success` de `uncertain` de aynı yere gider.
  - Çıkış yalnız `reconcile_cancellation()` iledir ve o metot **yalnız okur**.
    Tek çıkış `not_found`; "hâlâ var", "kapı kapalı" ve "cevap yok" durumda
    hiçbir şeyi değiştirmez.
  - `cancel()` bu durumda `cancel_in_progress` ile reddeder: ikinci basış, bayat
    sipariş nesnesi, eşzamanlı ikinci istek — hepsi.
  - Sorgu zinciri **iptal edilmez**: doğrulanmamış bir iptal hâlâ hareket
    ediyor olabilecek bir pakettir, ve planlı sorgu onu izleyen tek şeydir.
    `cancel_queries()` yalnız iptal kanıtlandığında çalışır.
  - **Tek istisna:** `permanent` ret. Taşıyıcı hayır dediyse hiçbir şey
    değişmemiştir, sipariş eski durumunda kalır ve düğme yeniden kullanılabilir.
- **Kanıt (her ölçümde toplam iptal yazması 1):**
  `SHIPPING_CANCEL_EVIDENCE_SURVIVES_BLOCKED_CONFIRM=PASS|first:cancel_unconfirmed_blocked|state:cancel_reconciliation_required|third_press_gate_open:cancel_in_progress|total_cancel_writes:1`,
  `SHIPPING_CANCEL_EVIDENCE_SURVIVES_RECORD_PRESENT=PASS|reconcile_1:cancel_unconfirmed_record_present|reconcile_2:cancel_unconfirmed_record_present|state_after_two_reconciles:cancel_reconciliation_required|total_cancel_writes:1|reopened_to_shipment_created:no`,
  `SHIPPING_CANCEL_EVIDENCE_SURVIVES_UNCERTAIN=PASS|cancel:uncertain|total_cancel_writes:1`,
  `SHIPPING_CANCEL_EVIDENCE_CLEARED_ON_PROOF=PASS|reconcile_verdict:cancelled|pending_evidence:cleared|press_after:already_cancelled|total_cancel_writes:1`,
  `SHIPPING_CANCEL_EVIDENCE_ORDER_BRANCH=PASS|cancel_order:1|cancel_shipment:0|read_order:1|read_shipment:0`,
  `SHIPPING_PENDING_CANCEL_KEEPS_THE_POLL_BOOKING=PASS|pending_after_cancel:yes|status_reads:0`,
  `SHIPPING_CANCEL_DEFINITIVE_REFUSAL_KEEPS_STATE=PASS|state_after_refusal:shipment_created|retry_allowed:yes|cancel_writes:2`
- **İlgili dosyalar:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`STATE_CANCEL_RECONCILE_REQUIRED`, `META_PENDING_MUTATION`,
  `save_cancel_pending`, `pending_mutation`, `clear_pending_mutation`),
  `SHIP/includes/shipping/class-shipment-manager.php`
  (`cancel`, `reconcile_cancellation`, `cancel_still_unproven`,
  `reconcile_order` dağıtımı),
  `SHIP/includes/shipping/class-shipment-admin.php`
- **Tekrar yaşanırsa ilk bak:** `cancel()` içinde `save_cancel_pending()`
  çağrısının doğrulama okumasından **önce** gelip gelmediği. Sonra gelirse,
  aradaki bir çökme kapıyı yeniden açar. Bir de `reconcile_order()`'ın duruma
  göre dağıtım yapıp yapmadığı: genel `reconcile()` bir iptalden sonra
  çalıştırılırsa hata aynen geri gelir.

---

## K-25 — Belirsiz güncelleme, nesnenin varlığıyla başarılı sayılıyordu

- **Tarih:** 2026-09-03
- **Belirti:** `Taşıyıcı kaydını güncelle` basılıyor, istek timeout oluyor,
  mutabakat "sipariş taşıyıcıda mevcut" diyor ve sipariş `order_created`a
  dönüyor — güncelleme düğmesi yeniden canlı. İkinci basış aynı güncellemeyi
  tekrar gönderiyor.
- **Kesin kök neden:** `uncertain` güncelleme `save_uncertain()` ile
  `reconcile_required`a gidiyordu ve oradan çıkış genel `reconcile()` ileydi. O
  metot nesnenin **varlığını** kanıt sayar. Bir güncelleme için bu geçersizdir:
  nesne güncellemeden **önce** de oradaydı. "Gönderi hâlâ var", "güncelleme
  uygulandı" demek değildir.
- **Uygulanan düzeltme:** Güncellemeye ait kalıcı, ayrı bir kanıt durumu:
  `STATE_UPDATE_RECONCILE_REQUIRED`, yanında **gönderilen alan değerleri**.
  - Tek kanıt alan bazında geri okumadır. Taşıyıcı sözleşmesine
    `read_amendable_fields()` eklendi; taşıyıcının o an tuttuğu değerleri
    semantik alan adlarıyla döndürür.
  - Karşılaştırma **tam**dır: her beklenen alan cevapta bulunmalı ve eşit
    olmalıdır. Cevapta **bulunmayan** alan eşleşmezliktir — "bizi yalanlamadı"
    kanıt değildir.
  - Birebir eşleşme → önceki duruma dönülür, kanıt temizlenir, yeni güncelleme
    yapılabilir. Bir alan farklı/eksik → `manual_review`. Geri okuma
    desteklenmiyorsa ya da yapılamıyorsa → durum korunur, **ikinci güncelleme
    gönderilmez**.
  - **DHL adaptörü `readback_unsupported` döndürür.** Satıcının Standard Query
    yanıtlarında güncellenebilir alanların hiçbiri yoktur; uydurulmuş bir
    karşılaştırma tam olarak düzeltilen hatayı geri getirirdi. Bu bir
    başarısızlık değil, bir **rettir**.
- **Kanıt:**
  `SHIPPING_UPDATE_EVIDENCE_EXISTENCE_IS_NOT_PROOF=PASS|object_present:yes|state_after_reconcile:update_reconciliation_required|second_press:nothing_to_update|stale_handle:nothing_to_update|update_writes:1|read_shipment:0|read_amendable_fields:2|reopened:no`,
  `SHIPPING_UPDATE_EVIDENCE_READBACK_UNSUPPORTED=PASS|expected_fields_recorded:9|dhl_adapter_answer:readback_unsupported`,
  `SHIPPING_UPDATE_EVIDENCE_READBACK_MATCHES=PASS|fields_compared:9|code:update_confirmed|state:shipment_created|second_update_allowed:yes`,
  `SHIPPING_UPDATE_EVIDENCE_READBACK_MISMATCH=PASS|code:update_mismatch|state:manual_review|absent_field_is_mismatch:yes`
- **İlgili dosyalar:**
  `SHIP/includes/shipping/interface-carrier-provider.php`
  (`read_amendable_fields`),
  `SHIP/includes/shipping/dhl/class-dhl-provider.php`,
  `SHIP/includes/shipping/class-shipment-manager.php`
  (`reconcile_update`, `amendable_fields`, `fields_match`,
  `update_still_unproven`),
  `SHIP/includes/shipping/class-shipment-order-store.php`
  (`STATE_UPDATE_RECONCILE_REQUIRED`, `save_update_pending`)
- **Tekrar yaşanırsa ilk bak:** `fields_match()` içindeki eksik-alan dalı.
  Cevapta olmayan bir alan "eşleşti" sayılırsa kanıt çöker. Ve
  `read_amendable_fields()` uygulaması kısmi bir cevap döndürmemeli:
  ya tam, ya `readback_unsupported`.

---

## K-26 — Provider, geçerli bir istek olmadan sabitleniyordu

- **Tarih:** 2026-09-03
- **Belirti:** Adresi taşıyıcı listesinde bulunamayan bir siparişte
  `gönderi oluştur` basılıyor, `city_not_found` ile reddediliyor — hiçbir
  taşıyıcıya çağrı yapılmıyor — fakat sipariş o taşıyıcıya **kalıcı olarak**
  bağlanmış oluyor. Başka bir taşıyıcı seçmek artık
  `shipment_provider_mismatch` veriyor.
- **Kesin kök neden:** K-21'in sırası şuydu:
  `begin_carrier_session()` → `build_request()` → `guarded_write()`. Pin,
  tamamen yerel olan adres/mapping doğrulamasından **önce** yapılıyordu.
  (`begin_carrier_session()` K-29 ile kaldırıldı; yerine `begin_mutation()`
  geldi ve sıra aşağıdaki hâliyle korunuyor.)
- **Uygulanan düzeltme:** Sıra tersine çevrildi ve pin yazmayla atomik hâle
  getirildi:
  1. Kilit alınır. 2. Sahiplik/varsayılan çözülür. 3. Referans yalnız yerel
  hazırlanır (`prepare_reference()`, hiçbir şey yazmaz). 4. `build_request()` ve
  bütün yerel doğrulamalar. 5. Kapı yeniden kontrol edilir; açıksa pin tek save
  ile yazılır. 6. Yazma.
  5 ve 6 arasında başka yerel başarısızlık yolu yoktur: pin,
  `guarded_write()`'ın `$before_write` argümanı olarak çalışır — kapı
  kontrolünden sonra, istekten hemen önce.
- **Neden hâlâ yazmadan önce:** timeout olan bir `createOrder` kimin
  sorulduğunu kaydetmemiş olurdu; K-21 tam olarak bunu düzeltmişti. İki
  gereksinim çelişmiyor, sıralamayı belirliyor.
- **Kanıt:**
  `SHIPPING_PROVIDER_NOT_PINNED_WITHOUT_A_WRITE=PASS|local_validation_failed:city_not_found|provider_after_local_failure:empty|reference_after_local_failure:empty|writes_on_first_carrier:0|second_carrier_accepted:yes|owner_now:kuka-fallback-kargo|gate_closed_before_write:credentials_missing|provider_after_gate_close:empty|writes_on_gated_carrier:0`
  ve değişmeyenler:
  `SHIPPING_PROVIDER_PINNED_BEFORE_FIRST_WRITE`,
  `SHIPPING_UNCERTAIN_CREATE_RETAINS_PROVIDER`
- **İlgili dosyalar:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`prepare_reference`, `begin_mutation`),
  `SHIP/includes/shipping/class-shipment-manager.php`
  (`guarded_write`'ın zorunlu dördüncü argümanı, `intent_writer`,
  `run_creation`, `run_barcode`, `resume_barcode`)
- **Tekrar yaşanırsa ilk bak:** `run_creation()` içinde `begin_mutation()`
  çağrısının nerede olduğu. `build_request()`'ten önce görürsen hata geri
  gelmiştir; `guarded_write()`'ın dördüncü argümanı olarak verilmemişse arada
  bir başarısızlık penceresi var.

---

## K-27 — Test önbelleği, sahipliği tahminle belirliyordu

- **Tarih:** 2026-09-03
- **Belirti:** Doğrulama koşusu sırasında gerçek bir istek tarafından oluşturulan
  bir `kuka_dhl_cbs_*` satırı, koşu bittiğinde yok.
- **Kesin kök neden:** K-20/K-23'ün custodian'ı sahipliği **çıkarma** ile
  belirliyordu: "bu satır anlık görüntümde yoktu, demek ki ben oluşturdum". Bu
  sahiplik değildir. Bir satır koşu sırasında başka bir süreçte oluşabilir ve
  onu silmek, tahmine dayanarak başkasının verisini silmektir. Ayrıca cleanup
  fazı geniş bir `DELETE ... LIKE` kullanıyordu — bir LIKE deseni sahipliği
  ifade edemez.
- **Uygulanan düzeltme:** Paylaşılan anahtar alanı **tamamen** kaldırıldı.
  - `DHL_Address_Resolver::set_cache_namespace()` eklendi. Doğrulama koşusu
    kendi namespace'ini alır (`testrun-<hex>`), bu yüzden mağazanın satırlarını
    ne okur, ne yazar, ne siler. Geri yüklenecek bir şey yoktur.
  - Custodian, koşunun oluşturacağı **birebir option adlarını** önceden
    bildirir (`own_resolver_keys()`), ve yalnız o adları siler — ad başına bir
    `$wpdb->delete`. **Hiçbir yerde wildcard silme yok.**
  - Bildirilmeyen her satır dokunulmadan bırakılır ve `foreign_preserved`
    olarak sayılır. Mağazanın satırları ayrıca okunur ve bayt bayt
    karşılaştırılır (`foreign_changed:0`).
  - `$done` yalnız **temiz** bir release sonunda işaretlenir; yarım kalan bir
    release açık kalır ve shutdown turu tekrar dener.
- **Nasıl ölçüldü:** `verify-shipping-cache-custodian.sh` üç ayrı süreçle ve
  **üç bitiş biçimiyle** çalışır — normal, `exit`, `fatal`. Her turda mağazaya
  ait bir satır ekilir, koşu kendi namespace'inde satır oluşturur, **ve
  bildirmediği bir satır** da oluşturur (eşzamanlı bir sürecin işi). Kontrol
  süreci mağazanın satırının bayt bayt aynı olduğunu, bildirilmeyen satırın
  **hâlâ orada** olduğunu ve koşunun kendi satırlarının gittiğini ölçer.
- **Kanıt:**
  `SHIPPING_CACHE_CUSTODIAN_normal=PASS|isolation:own_namespace|declared_exact_names:6|released_cleanly:yes|shop_rows_fingerprint_match:yes|undeclared_midrun_row_preserved:yes|run_rows_left:0|wildcard_delete:none`,
  `SHIPPING_CACHE_CUSTODIAN_exit=PASS|...`, `SHIPPING_CACHE_CUSTODIAN_fatal=PASS|...`,
  `SHIPPING_CBS_CACHE_PRESERVED=PASS|isolation:own_namespace|shop_row_bytes_identical:yes|undeclared_midrun_row_preserved:yes|foreign_changed:0|wildcard_delete:none`
- **İlgili dosyalar:**
  `SHIP/includes/shipping/dhl/class-dhl-address-resolver.php`
  (`set_cache_namespace`, `cities_cache_key`, `districts_cache_key`),
  `scripts/lib-shipping-cache-custodian.php`,
  `scripts/verify-shipping-cache-custodian.php`,
  `scripts/verify-shipping-cache-custodian.sh`,
  `scripts/verify-shipping-automation.php`
- **Tekrar yaşanırsa ilk bak:** `grep -rn "LIKE" scripts/ | grep -i delete`
  boş dönmeli. Ve custodian'ın sahiplik listesinin **bildirim** ile mi yoksa
  çıkarma ile mi kurulduğu.

---

## K-28 — Deaktivasyon bekleyen işleri gerçekte iptal etmiyordu

- **Tarih:** 2026-09-03
- **Belirti:** Eklenti devre dışı bırakıldıktan sonra
  `kuka_island_shipping_query_status` işleri hâlâ `pending`. Deaktivasyon
  başarılı raporlamıştı.
- **Kesin kök neden:** `Activator::cancel_owned_actions()`
  `as_unschedule_all_actions( $hook, array(), $group )` çağırıyordu. **Boş args
  dizisi, "herhangi bir args" demek değildir**; hiç argümansız çağrılmış bir
  action'ın args hash'idir. Poller sorgularını `array( 'order_id' => N )` ile
  planlar, dolayısıyla hiçbiri eşleşmiyordu — unschedule hiçbir şey bulmuyor ve
  başarılı raporluyordu, çünkü boş args'lı bekleyen bir iş yoktu.
  Bu, ancak bekleyen bir iş varken görülebilir; önceki lifecycle ölçümü
  otomasyon kapalıyken çalıştığı için hiçbir zaman bekleyen iş olmuyordu.
- **Uygulanan düzeltme:** Bekleyen işler hook **ve** grup ile numaralanıp
  action id bazında iptal ediliyor; sonra kalan bekleyen iş sayısı ölçülüyor.
  Tamamlanmış ve başarısız kayıtlara dokunulmuyor.
- **Kanıt:**
  `SHIPPING_DEACTIVATION_PRESERVES_OWNERSHIP=PASS|pending_before:yes|gate_after_deactivate:closed|pending_after_deactivate:unscheduled|gate_after_activate:open|pending_after_activate:none|provider_unchanged:yes|state_unchanged:yes|reference_unchanged:yes`
- **İlgili dosya:** `SHIP/includes/class-activator.php`
  (`cancel_owned_actions`)
- **Tekrar yaşanırsa ilk bak:** `as_unschedule_all_actions` çağrısına `array()`
  geçilip geçilmediği. Args filtresi kullanılacaksa `null` olmalı; en güvenlisi
  id bazında iptaldir.

---

## K-29 — Kalıcı mutation intent yoktu

- **Tarih:** 2026-09-03
- **Belirti:** Bir `createOrder` gönderildi, süreç cevap gelmeden öldü (fatal,
  OOM, deploy, kopan veritabanı bağlantısı). Sipariş `none` durumunda,
  `_kuka_shipping_provider` dolu. Operatör düğmeye bastı ve **ikinci bir
  createOrder** gitti. Tek paket, iki kayıt, ikincisi mağazanın göremediği.
- **Kesin kök neden:** Sahiplik ilk yazmadan önce sabitleniyordu ve bu
  "niyet kalıcı" diye okunmuştu. Değildi: provider anahtarı bir yazmanın
  **başladığını** söylemez, yalnız kime ait olduğunu söyler. `states_blocking_create()`
  durum üzerinden karar verir, durum ise cevap geldikten **sonra** yazılıyordu.
  Cevap hiç gelmezse yazan kod yolu hiç çalışmaz.
- **Uygulanan düzeltme:** `Order_Store::begin_mutation()`. Altı operasyonun
  tamamı için, **dış HTTP çağrısından önce**, tek save ile: operasyona özel
  korumalı durum (`reconcile_required` / `update_reconciliation_required` /
  `cancel_reconciliation_required`) + `mutation_id` (UUID4), `kind`,
  `operation`, `target`, `previous_state`, `provider`, `reference`,
  güncellemelerde kanonik `expected` alan değerleri, `created_at`. Ardından
  sipariş **veritabanından taze** okunuyor (her önbellek düşürülüyor,
  `read_meta_data( true )` zorlanıyor) ve kritik alanların tamamı `!==` ile
  karşılaştırılıyor.
- **Kanıt:** `SHIPPING_MUTATION_INTENT_DURABLE` — altı operasyon, niyet **ayrı
  bir MySQL oturumundan** (yalnız commit edilmiş satırları görebilen bir
  bağlantı) okunuyor. `SHIPPING_MUTATION_CRASH_BOUNDARY` — yazmanın içinde
  `Throwable` ile kontrol akışı kesiliyor, sonra **yeni sipariş nesnesi + yeni
  `Manager` + yeni adaptör** ile tekrar deneniyor: ikinci yazma sayısı **0**,
  açık kalan tek yol operasyona özel salt-okunur mutabakat.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`begin_mutation`, `verify_mutation_intent`, `fresh_copy`),
  `class-shipment-manager.php` (`intent_writer`, `guarded_write`)
- **Tekrar yaşanırsa ilk bak:** Ölçümde `state_at_first_write` değeri. `none`
  ise intent yazılmıyor demektir ve bütün koruma yoktur. Sonra
  `guarded_write()`'ın dördüncü argümanının hâlâ zorunlu olup olmadığı.

---

## K-30 — `before_write` `void` olduğu için doğrulanmıyordu

- **Tarih:** 2026-09-03
- **Belirti:** Yok — ve sorun tam olarak buydu. `save_meta_data()` sessizce
  başarısız olsa (kopan bağlantı, sorguyu yutan bir filtre) istek yine
  gidiyordu ve bellekteki nesne her iki durumda da aynı görünüyordu.
- **Kesin kök neden:** `guarded_write()`'ın dördüncü argümanı `?callable` ve
  `void` dönüşlüydü; çağrıldı, sonucuna bakılmadı. "Yazmak" ile "kalıcı
  kılmak" aynı şey değildir.
- **Uygulanan düzeltme:** Argüman **zorunlu** oldu ve
  `array{ok, code, message, detail, intent}` döndürüyor. `true !== $prepared['ok']`
  ise `$write()` **hiç çağrılmıyor**; tanımadığı bir şekil de (null, string)
  reddediliyor, çünkü hiçbir şey kanıtlamamış olur. Doğrulama başarısızsa
  sipariş korumalı durumda **bırakılıyor** — geri alma denemek, az önce
  başarısız olan mekanizmaya yeniden güvenmek olurdu ve üretebileceği hata
  tehlikeli olanıdır: yazma düğmesi açık bir durum.
- **Kanıt:** `SHIPPING_MUTATION_INTENT_UNPERSISTED_BLOCKS_WRITE` — `query`
  filtresiyle **yalnız** `_kuka_shipping_pending_mutation` yazan INSERT/UPDATE
  cümleleri `SELECT 1`'e çevriliyor; sonuç `mutation_intent_unverified`,
  taşıyıcı çağrısı **0**, sipariş korumalı durumda, ve kalan artık
  `reconcile_cancellation()` ile okunarak çözülüyor.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`guarded_write`)
- **Tekrar yaşanırsa ilk bak:** Callback'in dönüş tipi. `void` ise koruma yok.

---

## K-31 — "Kesin ret hiçbir şeyi değiştirmemiştir" varsayımı

- **Tarih:** 2026-09-03
- **Belirti:** Taşıyıcı `cancelshipment`'a 400 döndü; sipariş
  `shipment_created`'a geri alındı ve iptal düğmesi yeniden açıldı. Aynısı
  `createOrder` ve `updateshipment` için de geçerliydi.
- **Kesin kök neden:** Kod, `permanent` bir cevabı "istek işlenmedi" diye
  okuyordu. Satıcının resmî OpenAPI belgeleri bunu desteklemiyor: altı yazma
  operasyonunun **tamamı** yalnız `200`, `400 Bad Request`,
  `401 Unauthorized`, `500 Server Error` tanımlar ve **hiçbiri yan etkiden söz
  etmez**. 400'ün kayıt bırakıp bırakmadığı yazılı değildir. Dolayısıyla "yan
  etkisi olmadığı belgelenmiş hata" listesi **boştur**.
- **Uygulanan düzeltme:** `Result::local_refusal()` + `reached_carrier()`.
  Yalnız `call()` çağrılmadan, soket açılmadan verilen retler intent'i
  kapatabiliyor; bu, durum koduna bakılarak tahmin edilmiyor, **kod yolundan**
  kanıtlanıyor. DHL istemcisindeki ve adaptöründeki 20 ön-ağ reddi bu factory'ye
  çevrildi. Ağdan gelen her cevap — `success` dâhil — intent'i açık bırakıyor.
- **Kanıt:** `SHIPPING_CANCEL_REFUSAL_POLICY` — cevaplanan 400: intent
  korunuyor, durum `cancel_reconciliation_required`, ikinci basış
  `cancel_in_progress`, yazma 1. Ağa çıkmamış ret: yazma **0**, intent
  temizleniyor, `shipment_created`'a dönülüyor, düğme açık.
- **İlgili dosya:** `SHIP/includes/shipping/class-carrier-result.php`,
  `dhl/class-dhl-client.php`, `dhl/class-dhl-provider.php`,
  `class-shipment-manager.php` (`record_failure`, `close_unsent_intent`)
- **Tekrar yaşanırsa ilk bak:** `to_safe_line()` çıktısındaki
  `reached_carrier:` alanı. `yes` ise intent kapatılamaz.

---

## K-32 — Sonuç geçişleri iki save'di

- **Tarih:** 2026-09-03
- **Belirti:** Durumu `cancelled` olan bir siparişin metasında hâlâ uçuşta bir
  iptali tarif eden `_kuka_shipping_pending_mutation` kaydı — ya da tersi.
- **Kesin kök neden:** `set_state()` bir save, `clear_pending_mutation()`
  ikinci bir save yapıyordu. Aradaki pencerede süreç ölürse ikisi
  çelişiyordu, ve tersi yön tehlikeli olanıdır: intent temizlenmiş ama durum
  korumalı kalmamışsa yazma düğmesi açılır.
- **Uygulanan düzeltme:** `Order_Store::settle_mutation()` durumu, intent
  temizliğini, son operasyonu, ek metayı ve geçmiş kaydını **aynı**
  `save_meta_data()` içinde yazıyor. `save_order_created()` ve
  `save_shipment_created()` de intent'i kendi save'lerinde kapatıyor. Sınıftaki
  **tek** yazma noktası `persist()` ve sayıyor.
- **Kanıt:** `SHIPPING_MUTATION_OUTCOME_ATOMIC` — iptal onaylandı **1**,
  güncelleme onaylandı **1**, güncelleme uyuşmadı **1**, intent açılıp önceki
  duruma dönüş **2** (biri açmak, biri kapatmak), oluşturma + barkod **4**. Her
  geçişten sonra durum ve intent ayrı bağlantıdan okunup tutarlılık ölçülüyor.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`persist`, `save_count`, `settle_mutation`)
- **Tekrar yaşanırsa ilk bak:** `save_meta_data()` çağrısı sayısı. Sınıfta
  `persist()` dışında bir tane bile varsa sayaç yalan söyler.

---

## K-33 — `KUKA_DHL_ADAPTER` yazım hatasında açık kalıyordu

- **Tarih:** 2026-09-03
- **Belirti:** Operatör `KUKA_DHL_ADAPTER=flase` yazdı, kargonun durduğunu
  sandı, gönderi hâlâ oluşturulabiliyordu ve hiçbir ekranda değerin
  anlaşılmadığı yazılı değildi. Aynısı `of`, `' 0'`, `''`, `ON` için de geçerli.
- **Kesin kök neden:** Kural "yalnız dört açık olumsuz kapatır, gerisi açık
  kalır"dı. Gerekçe "yazım hatası kargoyu sessizce durdurmasın"dı; hatanın
  diğer yönü daha kötüsüdür ve gerçekleşiyordu.
- **Uygulanan düzeltme:** `DHL_Config::adapter_state()` →
  `array{enabled, reason}`. Tanımsız → `unset_default_on` (açık, tarihsel
  varsayılan korunuyor). Tam eşleşen `1/true/yes/on` → `explicitly_on`. Tam
  eşleşen `0/false/no/off` → `explicitly_off`. **Her diğer değer** →
  `configuration_invalid`, kapalı. Sessiz normalizasyon yok: kırpma yok, harf
  katlama yok. Tek istisna gerçek PHP boolean'ı. Geçersiz değerde kompozisyon
  kökü adaptörü **hiç kurmuyor**, ve cümlesini
  `kuka_island_shipping_configuration_notices` filtresine koyuyor — sipariş
  ekranı hiçbir kuryeyi adıyla anmadan onu basıyor.
- **Kanıt:** `SHIPPING_ADAPTER_KEY_FAIL_CLOSED` — 19 değer, hatalı 0, geçersiz
  değerde kayıtlı adaptör **0** ve HTTP **0**, kapı `carrier_not_registered`,
  durum satırı ayarın adını ve "tanınmadı" cümlesini içeriyor.
- **İlgili dosya:** `SHIP/includes/shipping/dhl/class-dhl-config.php`,
  `SHIP/includes/class-shipping-automation.php` (`adapter_notice`),
  `SHIP/includes/shipping/class-shipment-admin.php` (`module_status`)
- **Tekrar yaşanırsa ilk bak:** `classify_adapter_value()` içinde `trim()` ya
  da `strtolower()` var mı. Varsa sessiz normalizasyon geri gelmiş demektir.

---

## K-34 — `fields_match()` "birebir" değildi

- **Tarih:** 2026-09-03
- **Belirti:** Güncelleme "alan bazında birebir doğrulandı" raporlandı, oysa
  taşıyıcı `recipient_full_name`'i baştaki bir boşlukla tutuyordu.
- **Kesin kök neden:** Karşılaştırma iki tarafı da `trim()` ediyordu. Farkı
  karşılaştırmanın içinde soğurmak, doğrulamanın tersidir.
- **Uygulanan düzeltme:** Daha gevşek değil, **tanımlı** bir karşılaştırma.
  `Manager::canonical_amendable_value()` tek kanonik biçimi tanımlıyor (baş/son
  boşluk atılır, içteki boşluk dizileri tek boşluğa iner, Unicode duyarlı);
  `canonicalize_request()` bunu `build_request()`'in **en sonunda**,
  `kuka_island_shipping_request` filtresinden **sonra** uyguluyor — yani
  taşıyıcıdan istenen baytlar karşılaştırmanın talep edeceği baytlarla aynı.
  `fields_match()` içinde artık hiç `trim()` yok, karşılaştırma `!==`, skaler
  olmayan cevap da eşleşmezlik.
- **Kanıt:** `SHIPPING_AMENDABLE_CANONICAL_EXACT` — kanonik biçim 4 vaka,
  karşılaştırma 6 vaka, gönderilen değerlerin tamamının kanonik olduğu
  ölçülüyor, ve baştaki tek boşlukla geri okunan alan `update_mismatch` →
  `manual_review` üretiyor. Suite ayrıca `fields_match()` gövdesinde `trim(`
  bulunmadığını da sayıyor.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`canonical_amendable_value`, `canonicalize_request`, `amendable_fields`,
  `fields_match`)
- **Tekrar yaşanırsa ilk bak:** `fields_match()` gövdesinde `trim(`. Bir tane
  bile varsa "birebir" yine yanlıştır.

---

## K-35 — "Planlı sorgu onu izleyen tek şeydir" yorumu doğru değildi

- **Tarih:** 2026-09-03
- **Belirti:** Doğrulanmamış bir iptalde planlanmış durum sorgusu bir kez
  koşuyor, `status_reads:0` ile bitiyor ve ardına yeni iş planlamıyor. Kaynaktaki
  yorum ise onun paketi "izleyen tek şey" olduğunu söylüyordu.
- **Kesin kök neden:** Poller'ın işçisi önce durumu okur ve
  `STATE_SHIPMENT_CREATED` değilse `state_not_pollable` dönerek biter —
  taşıyıcıya tek bir okuma bile yapmadan. `cancel_reconciliation_required` o
  listede yoktur. Yorum, kodun yapmadığı bir şeyi anlatıyordu.
- **Uygulanan düzeltme:** İki seçenekten (operasyona özel sınırlı iptal
  mutabakat poll'u eklemek, ya da bunun **manuel** olduğunu açıkça yazmak)
  ikincisi seçildi ve her yere yazıldı: yorum düzeltildi, sözleşme §7.1
  düzeltildi, ve sipariş ekranındaki metin *"Otomatik durum sorgusu bu durumu
  ÇÖZMEZ ve yeni sorgu planlamaz; doğrulamayı Mutabakat düğmesiyle siz
  başlatmalısınız"* diyor. Planlanmış iş yine iptal edilmiyor — zamanlayıcıya
  ikinci bir yazma hiçbir şey kazandırmaz — fakat sonucu artık gizlenmiyor.
- **Kanıt:** `SHIPPING_PENDING_CANCEL_IS_MANUAL_ONLY` — gerçek Action Scheduler
  runner'ı işi bir kez koşuyor, `worker_outcome:state_not_pollable`,
  `status_reads:0`, `follow_up_booked:no`, operatörün okuması durumu
  ilerletiyor, ve yanlış yorum kaynakta artık yok.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`cancel_still_unproven`),
  `SHIP/includes/shipping/class-shipment-admin.php` (`operator_hint`)
- **Tekrar yaşanırsa ilk bak:** Bir yorum davranış iddia ediyorsa, o iddianın
  bir ölçümü var mı. Yoksa yorum silinmeli ya da ölçüm yazılmalı.

---

## K-36 — Etkinleştirme bütün kargo doğrulamasını devre dışı bırakıyordu

- **Tarih:** 2026-09-03
- **Belirti:** Eklenti etkinleştirildikten sonra `make verify` kargo bloğuna
  **hiç** ulaşmıyor: davranış suite'i, lifecycle suite'i ve önbellek
  custodian'ı çalışmıyor. Çıktının son satırı
  `Error: SHIPPING_PASSIVE=FAIL|SHIPPING_PASSIVE_PLUGIN_STATE,SHIPPING_PASSIVE_CLASSES_ABSENT,SHIPPING_PASSIVE_HOOKS_ABSENT`.
- **Kesin kök neden:** Üç ölçüm yalnız eklenti **pasifken** gözlemlenebilir
  şeyler soruyor ("tek bir sınıf yüklü değil", "tek bir hook kayıtlı değil").
  Eklenti etkinken bunlar yanlış değil, **cevapsız**dır. Betik başarısızlıkta
  `WP_CLI::error()` ile sıfırdan farklı dönüyor, `verify.sh` ise `set -e` ile
  çalışıyor: cevapsız üç soru, kendilerinden sonra gelen her şeyi öldürüyordu.
  Ayrıca aynı sınıftan iki ölçüm daha vardı: `SHIPPING_LIFECYCLE_START`
  başlangıç durumunun pasif teslim durumu olmasını **dayatıyordu**, ve davranış
  suite'inde canlı poller harness poller'ıyla çakışıyordu (aynı action'ı iki
  worker işlediği için zincir sayıları birer fazla çıkıyordu).
- **Uygulanan düzeltme:** Dört parça, hiçbiri gevşetme değil.
  1. Üç ölçüm gerekçesi yazılı olarak `SKIPPED|reason:plugin_active` raporlanır
     ve nerede ölçüldüklerini söyler
     (`measured_instead_by:SHIPPING_LIFECYCLE_DEACTIVATION`). Pasif teslim
     durumunda üçü yine **PASS zorunludur**; `verify.sh` iki biçimi kabul eder,
     üçüncüyü (FAIL ya da başka gerekçeli skip) etmez.
  2. Yerlerine **her iki durumda** sorulabilen iki ölçüm eklendi:
     `SHIPPING_PASSIVE_DELIVERY_ARTEFACT` (dosya yerinde, başlık bağımlılıkları
     bildiriyor, bağımlılıklar etkin) ve `SHIPPING_PASSIVE_NO_AUTOMATIC_ROUTES`
     — modülün, sipariş hareket ettiğinde kendiliğinden tetiklenen **9** kancanın
     hiçbirinde callback'i olmadığı. `add_meta_boxes` bilerek hariç: etkin
     modülün panelini çizmesi beklenir, tehlikeli olan sipariş durumu ve
     fulfillment olaylarıdır.
  3. `SHIPPING_LIFECYCLE_START` başlangıç durumunu **kaydeder, dayatmaz**
     (`starting_state:recorded_not_asserted`); dayattığı tek şey Core ve
     WooCommerce'in etkin olması. Suite ayrıca `active_plugins` option'ını
     birebir geri yazar — `wp plugin activate` diziye **sona** eklediği için tur
     sonunda eklenti yükleme sırası değişiyordu.
  4. Davranış suite'inde `kuka_ship_attach_sole_poller()` ölçüm süresince canlı
     poller'ı hook'tan ayırır (hook'lar sürece özeldir, siteye etkisi yoktur), ve
     "yüklemek kayıt yapmaz" ölçümü mutlak sayı yerine **delta** ölçer.
- **Kanıt:** Etkin eklentiyle iki ardışık tur: `SHIPPING_PASSIVE=PASS|skipped:3`,
  `SHIPPING_VERIFY=PASS`, `SHIPPING_LIFECYCLE=PASS`,
  `SHIPPING_PASSIVE_ORDER_LIFECYCLE=PASS|shipping_meta_keys:none|actions_booked:0`
  ve `SHIPPING_PASSIVE_NO_AUTOMATIC_ROUTES=PASS|module_callbacks:none`.
- **İlgili dosya:** `scripts/verify-shipping-passive-contract.php`,
  `scripts/verify-shipping-activation-lifecycle.sh`,
  `scripts/verify-shipping-automation.php`, `scripts/verify.sh`
- **Tekrar yaşanırsa ilk bak:** Bir ölçümün **cevaplanamaz** mı yoksa
  **başarısız** mı olduğu. İkisi aynı şey değildir ve cevapsız olanı FAIL
  raporlamak, kendisinden sonraki bütün ölçümleri de öldürür. Cevapsız ölçüm
  gerekçesiyle atlanır ve garanti nerede ölçülüyorsa oraya işaret eder.

---

## K-37 — Korumalı durumlar taşıyıcı kanıtı sayılmıyordu

- **Tarih:** 2026-09-03
- **Belirti:** Durumu `cancel_reconciliation_required` olan, provider'ı boş bir
  sipariş. `carrier_ownership()` `shipment_provider_missing` demiyor, mağazanın
  **güncel varsayılan** taşıyıcısını döndürüyor. İkinci bir kurye eklenmişse
  iptal doğrulaması o kuryeye gidiyor ve onun "kayıt yok" cevabı iptalin kanıtı
  sayılıyor.
- **Kesin kök neden:** `has_carrier_evidence()`'ın kanıt listesi K-21'de
  yazılmıştı ve K-24/K-25'te eklenen iki korumalı durum listeye hiç girmemişti.
  `pending_mutation` kaydı da hiç sorulmuyordu. Oysa bir sipariş bu durumlara
  **yalnız** `begin_mutation()` üzerinden girer, yani bu referans altında bir
  taşıyıcıya istek gönderilmiş olduğunun en güçlü kanıtıdır.
- **Uygulanan düzeltme:** Kanıt listesine `STATE_CANCEL_RECONCILE_REQUIRED` ve
  `STATE_UPDATE_RECONCILE_REQUIRED` eklendi; ayrıca durum ne derse desin **dolu
  bir `pending_mutation`** tek başına kanıt sayılıyor — o kayıt yalnız
  `begin_mutation()` ile onu kapatan sonuç arasında var olur.
- **Kanıt:** `SHIPPING_ORPHANED_PROTECTED_STATE_FAILS_CLOSED` — üç vaka
  (iki korumalı durum + yalnız intent kaydı), her birinde altı kapı
  `shipment_provider_missing`, iki adaptörün de teması **0**.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`has_carrier_evidence`)
- **Tekrar yaşanırsa ilk bak:** Yeni bir durum eklendiğinde kanıt listesine de
  eklendi mi. Liste bir deny-list değil, "bu durum yalnız bir dış istekten sonra
  oluşabilir mi" sorusunun cevabıdır.

---

## K-38 — İptal edilmiş sipariş üzerinden barkod oluşturulabiliyordu

- **Tarih:** 2026-09-03
- **Belirti:** Gönderisi iptal edilmiş ve iptali **salt-okunur sorguyla
  doğrulanmış** bir siparişte `gönderi oluştur` basılıyor. `createOrder`
  gönderilmiyor ama `createbarcode` gönderiliyor: taşıyıcının zaten iptal ettiği
  kayda barkod isteği, arkasında bu sipariş ömründe hiç `createOrder` olmadan.
  Aynı sipariş üzerinden `updateshipment` ve `cancelshipment` da açılıyordu.
- **Kesin kök neden:** İki ayrı yerde aynı hata. Create kapısı bir **deny-list**
  soruyordu (`states_blocking_create()`) ve `STATE_CANCELLED` o listede yoktu —
  yasak listeleri, yeni bir durum eklendiği ilk anda delik verir. Kapıyı geçen
  çağrı `run_creation()`'a giriyor, oradaki `createOrder` dalı durumu kabul
  etmediği için atlanıyor, ve metot **koşulsuz** olarak `run_barcode()` ile
  bitiyordu. Yani "createOrder dalına girmeyen her durum" doğrudan barkod
  yoluna düşüyordu; bilinmeyen bir durum da aynı şekilde düşüyordu.
- **Uygulanan düzeltme:** Tek merkezî **allow-list**, `Order_Store` içinde:
  `states_allowing_create_order()` = `none`, `blocked`, `absent_confirmed`;
  `states_allowing_create_barcode()` = yalnız `order_created`. Create kapısı,
  `run_creation()`'ın createOrder dalı, `run_creation()`'ın **barkod geçişi**
  (durum yeniden okunarak) ve yönetim panelindeki düğme — dördü de aynı listeyi
  sorar. `states_blocking_create()` yalnız ret **mesajını** seçmek için kaldı;
  artık hiçbir yerde kapı değildir.
- **Kanıt:** `SHIPPING_CANCELLED_RECORD_IS_FAIL_CLOSED` — gerçek create, gerçek
  iptal, salt-okunur kanıt, sonra **taze `WC_Order` + taze `Manager` + taze
  adaptör**: `createOrder:0|createbarcode:0|update:0|cancel:0|reads:0`, durum
  `cancelled`, intent yok, dört kapının dördü de gerekçeli ret.
  `SHIPPING_CREATE_DOORS_ARE_AN_ALLOWLIST` — 12 durum × 2 aksiyon, hangi
  **kapının açıldığı** ölçülüyor (adaptör her iki create işlemini ağdan önce
  reddediyor), allow-list dışında hiçbir durum hiçbir kapıya ulaşmıyor,
  taşıyıcı yazması 0. Düzeltme geri alınarak ölçüldü: eski kodda aynı testler
  `createbarcode:1` ve `cancelled/create=>barcode:1` ile **FAIL** veriyor,
  ayrıca bilinmeyen durum da barkod kapısına düşüyor.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`states_allowing_create_order`, `states_allowing_create_barcode`),
  `SHIP/includes/shipping/class-shipment-manager.php` (`create_shipment`,
  `run_creation`, `create_refusal_message`),
  `SHIP/includes/shipping/class-shipment-admin.php` (`render_meta_box`)
- **Tekrar yaşanırsa ilk bak:** `run_creation()`'ın sonu. `return
  $this->run_barcode(...)` bir durum kontrolü olmadan duruyorsa hata geri
  gelmiştir. Ve herhangi bir kapı `states_blocking_create()` ile karar veriyorsa
  o kapı bir deny-list'e dönmüş demektir.

---

## K-39 — Gerçek `make verify` EDM pasifken çalışmıyordu

- **Tarih:** 2026-09-03
- **Belirti:** Teslim durumunda (EDM pasif, kargo etkin) gerçek `make verify`
  **exit 2**. `verify-invoice-integration.php` içindeki mock tabanlı 21 ölçüm
  `edm_runtime_disabled` ile düşüyor, betik sıfırdan farklı dönüyor ve
  `set -eu` yüzünden kargo bloğuna hiç ulaşılmıyor.
- **Kesin kök neden:** EDM deaktivasyonu kalıcı çalışma kapısı option'ını yazar
  ve `Invoice_Manager::process_order()` bu kapıyı gönderimden hemen önce
  okur — kapının varlık sebebi tam olarak budur. Teslim durumunda kapı doğru
  şekilde **kapalı**dır, dolayısıyla mock transport'a hiç ulaşılamıyordu.
  Ölçümler yanlış değildi; ön koşulları söylenmemişti.
- **Uygulanan düzeltme:** `Invoice_Manager`'a üçüncü, isteğe bağlı bir
  `Kuka_Island_Core_Invoice_Transmission_Gate` argümanı. **Varsayılan gerçek
  kapıdır** ve bütün üretim çağrı siteleri (`class-invoice.php`,
  `Invoice_Admin`, `Invoice_Queue`, `Invoice_Status_Poller`) varsayılanı
  kullanır; üretim davranışı değişmedi. Offline ölçümler ön koşullarını tek bir
  görünür yerde belirtir (`kuka_invoice_test_manager()` + açık test kapısı).
  Kapının **kendi** ölçümü (`EDM_DEACTIVATION_GATE_STOPS_INFLIGHT_SEND`)
  argümanı **vermez**, yani gerçek option tabanlı kapının kapalı/açık
  davranışını ölçmeye devam eder.
- **Ek koruma:** O ölçüm bir kontrol gönderimi için gerçek kapıyı bir an
  açmak zorundadır. Bu, koşunun dokunduğu **tek canlı ayar**dır, ve bırakabileceği
  artık bu depodaki en kötüsüdür: operatörünün pasifleştirdiği bir sitede EDM
  gönderiminin **açık** kalması. Bu yüzden option satırı (varlık + değer +
  autoload) ölçümlerden önce anlık görüntüye alınır, `register_shutdown_function`
  ile **her** çıkışta (fatal ve `WP_CLI::error()` dâhil) birebir geri yazılır, ve
  geri yükleme byte olarak eşleşmezse shutdown handler `exit(1)` ile koşuyu
  başarısız yapar.
- **Kanıt:** `EDM_TRANSMISSION_GATE_SEAM=PASS|production_default:Kuka_Island_Core_Invoice_Runtime_Gate|open_gate_consulted:1|open_gate_SendInvoice:1|closed_gate_consulted:1|closed_gate_code:edm_runtime_disabled|closed_gate_SendInvoice:0|closed_gate_uuid:absent|production_sites_passing_a_gate:0`
  — enjekte edilen kapalı bir kapı gerçek kapı gibi reddediyor, yani seam
  kontrolü **zayıflatamıyor**. `EDM_RUNTIME_OPTION_RESTORED=PASS|byte_equivalent:yes`.
  Ve gerçek `make verify` iki kez **exit 0**, EDM pasif kalarak.
- **İlgili dosya:**
  `wp-content/plugins/kuka-island-edm/includes/invoice/class-invoice-runtime-gate.php`
  (arayüz + gerçek kapı),
  `.../class-invoice-manager.php` (üçüncü argüman, `get_transmission_gate`),
  `scripts/verify-invoice-integration.php` (test kapıları, fabrika, shutdown
  coordinator)
- **Tekrar yaşanırsa ilk bak:** Bir üretim çağrı sitesinin `Invoice_Manager`'a
  kapı geçirip geçirmediği — `EDM_TRANSMISSION_GATE_SEAM` bunu sayar ve 0
  olmak zorundadır. Ve ölçümün ön koşulunu site option'ını yazarak sağlayan bir
  kod eklenirse, shutdown coordinator'ın hâlâ orada olduğu.

---

## K-40 — Teslim tarihini modül değil vendor yazıyordu

- **Tarih:** 2026-09-04
- **Belirti:** Yok — ve önemli olan buydu. `sync_status()` fulfillment'ı
  `fulfilled` durumuna geçiriyor, **tarih yazmıyordu**. WooCommerce 11.0.1'in
  `FulfillmentsDataStore`'u kaydederken `is_fulfilled` doğru ve tarih boşsa
  `_date_fulfilled` alanını kendisi dolduruyor (`current_time( 'mysql' )`), bu
  yüzden değer yine oluşuyordu.
- **Kesin kök neden:** Modül, hiçbir yerde yazmadığı bir **vendor yan
  etkisine** dayanıyordu. O alanın değeri mali bir belgedeki teslim tarihidir:
  EDM'in `Internet_Sales_Details` sınıfı tam olarak bu alanı okur ve alan boşsa
  belgeyi `internet_sales_shipment_date_missing` ile reddeder. WooCommerce
  tarafında bir değişiklik, hiçbir yerel test düşmeden mali tarihleri
  kaydırabilirdi.
- **Saat dilimi ölçüldü, tahmin edilmedi.** `set_date_fulfilled()` girdisini
  `normalize_date_to_utc()`'ye verir; o da **zonu belirtilmemiş** bir dizgiyi
  `wp_timezone()` ile okur ve UTC karşılığını saklar. Bu kurulumda (PHP UTC,
  WordPress Europe/Istanbul) round-trip:
  `'2026-09-04 12:00:00' → '2026-09-04 09:00:00'`,
  `'2026-09-04 12:00:00+00:00' → '2026-09-04 12:00:00'`.
  Yani çıplak bir `gmdate()` dizgisi mağaza-yerel sanılır ve burada **üç saat
  erken** saklanır.
- **Uygulanan düzeltme:** Tarih modülün kendi kodunda yazılıyor, **kendi
  offset'ini belirten** bir dizgiyle (`Y-m-d H:i:sP`), ve **yalnız alan boşsa**
  — kod 3/4/5 ve her tekrar sorgu bu metottan yine geçer, dokunmamaları gereken
  şey paketin gerçekten çıktığı andır. `sync_status()` artık ne yaptığını da
  bildiriyor: `date_fulfilled:set|present|untouched`.
- **Kanıt:** `SHIPPING_FULFILLMENT_DATE_ON_FIRST_FULFILL` — kod 2 öncesi tarih
  yok; ilk geçişte modülün kendi raporu `writer_date:set`; saklanan değer birebir
  UTC biçiminde ve gerçek ana **0 sn** uzaklıkta (çıplak `gmdate()` burada
  10.800 sn sapardı); kod 3/4/5 ve tekrar sorguda değer **byte olarak** aynı;
  EDM okuyucusu `shipment_date` üretiyor ve mağazanın yerel gününü veriyor
  (ölçüm anında UTC günü 2026-09-03, mağaza günü 2026-09-04 — sınır durumu).
  Düzeltme geri alınarak ölçüldü: modülün raporu `writer_date:present`e dönüyor
  ve ölçüm **FAIL** veriyor, saklanan tarih yine doğru olsa bile.
- **İlgili dosya:** `SHIP/includes/shipping/class-fulfillment-writer.php`
  (`sync_status`)
- **Tekrar yaşanırsa ilk bak:** `sync_status()` içinde `set_date_fulfilled()`
  çağrısının olup olmadığı ve girdinin offset taşıdığı. Offset yoksa tarih
  mağaza saatiyle okunur ve mali belgeye yanlış gün gider.

---

## K-41 — `reconcile_order()` kilit almayan tek dış kapıydı

- **Tarih:** 2026-09-04
- **Belirti:** Bir create uçuşta iken operatör "Mutabakat" düğmesine basıyor.
  Mutabakat çalışıyor, taşıyıcıya iki okuma yapıyor ve **bitmemiş bir yazmanın**
  intent'ini kapatıyor. İki operatör aynı anda bastığında ikisi de aynı açık
  intent'i okuyup ikisi de kapatıyor.
- **Kesin kök neden:** `create/resume/update/cancel` dördü de sipariş mutasyon
  kilidini alıyordu; `reconcile_order()` **hiç** almıyordu. Provider, state,
  bekleyen intent ve referans kararlarını çağrıcının elindeki nesneden veriyordu
  — kilidi tutan tarafın az önce değiştirmiş olabileceği değerlerden.
- **Uygulanan düzeltme:** Aynı kilit, **sıfır beklemeyle** (`lock_contended`),
  ve kilit alındıktan sonra sipariş DB'den yeniden okunup dört karar **yalnız o
  taze nesneden** veriliyor. Kilit **tek noktada**: `reconcile()`,
  `reconcile_update()` ve `reconcile_cancellation()` kilitsiz kalıyor, çünkü
  `run_creation()`, `update_shipment()` ve `cancel()` onları kilit **zaten
  tutulurken** çağırır — içlerine ikinci bir alma koymak, kilidin serileştirmek
  için var olduğu yolları kilitlerdi.
- **Ek koruma:** Salt-okunur bir düğme, okumayla **zaten varılmış** bir sonucu
  değiştirmemeli. `cancelled` ve `absent_confirmed` durumlarında mutabakat
  `already_settled` ile reddediliyor; aksi hâlde genel mutabakat kanıtlanmış bir
  iptali sonraki okumadan `absent_confirmed`'e çevirebiliyordu.
- **Kanıt:** `SHIPPING_RECONCILE_TAKES_THE_MUTATION_LOCK` — **gerçek ikinci
  MySQL oturumu** kilidi tutarken `lock_contended`, taşıyıcı okuma/yazma **0**,
  ve state/provider/reference/pending-mutation dördü **byte olarak** değişmiyor;
  kilit bırakılınca taze state üzerinden `absent_confirmed` ile doğru mutabakat
  çalışıyor; kapanmış bir intent ikinci kez kapatılamıyor (`already_settled`).
  Düzeltmeden önce aynı ölçüm `reads_while_held:2` ve
  `decisions_byte_identical:NO` veriyordu.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`reconcile_order`, `settled_message`)
- **Tekrar yaşanırsa ilk bak:** `reconcile_order()` içinde `acquire_lock()`
  olup olmadığı, ve alt yardımcılara **ikinci** bir kilit eklenmemiş olduğu.
  İkincisi eklenirse `cancel()` kendi kilidinde kilitlenir.

---

## K-42 — Ulaşılmayan ret ~14 günlük boş zincir üretiyordu

- **Tarih:** 2026-09-04
- **Belirti:** Kimlikleri eksik ya da çalışma kapısı kapalı bir siparişte
  otomatik durum sorgusu her turda yeniden planlanıyor. Taşıyıcı teması **0**,
  `query_attempts` **0**, fakat zincir yaşıyor ve her tur bir geçmiş kaydı ve
  bir sipariş notu ekliyor. Dört tur → sekiz kayıt.
- **Kesin kök neden:** `query_status()` taşıyıcıya hiç ulaşmadan reddedebilir
  (kimlik eksik, kapı kapalı, taşıyıcı kayıtlı değil, ayar tanınmadı, referans
  yok). Bunlar taşıyıcı denemesi olmadığı için **doğru şekilde** deneme
  harcamaz — ve tuzak tam buydu: poller sonraki adımı `ok:false` + bilinmeyen
  lifecycle'dan çıkarıyordu, ki o "hâlâ hareket ediyor" dalıdır. Sayaç
  ilerlemediği için `MAX_ATTEMPTS` hiç gelmiyor, zinciri bitiren tek şey
  `MAX_ELAPSED` (≈14 gün) oluyordu.
- **Uygulanan düzeltme:** `query_status()` artık **`contacted`** bilgisini
  döndürüyor — kod listesi değil, olgu; kod listesi yeni bir ret eklendiği ilk
  gün bayatlar. `contacted === false` ise poller `stop:local_refusal:<kod>` ile
  biter: yeni iş planlamaz, deneme harcamaz. Gerekçe **bir kez** kaydedilir
  (`Order_Store::save_blocked()` ve `record_local_refusal()` aynı kod + aynı
  state tekrarında geçmiş/not eklemez), ve operatörün manuel sorgusu aynen
  açıktır.
- **Kanıt:** `SHIPPING_LOCAL_REFUSAL_ENDS_THE_POLL_CHAIN` — gerçek Action
  Scheduler runner'ı üzerinden: taşıyıcı okuma **0**, deneme **0**, planlanan
  takip **yok**, dört tur sonunda not **1** ve geçmiş kaydı **1**; kontrol
  olarak gerçek bir `transient` ağ sonucu hâlâ **1** deneme harcıyor ve sınırlı
  yeniden deneme zincirini koruyor. Düzeltmeden önce aynı ölçüm
  `follow_up_booked:YES` ve `notes_added_by_4_turns:8` veriyordu.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`query_status`), `.../class-shipment-status-poller.php` (`run`),
  `.../class-shipment-order-store.php` (`save_blocked`, `record_local_refusal`)
- **Tekrar yaşanırsa ilk bak:** Poller'ın `contacted` alanını okuyup okumadığı.
  Karar yine `ok`/lifecycle üzerinden veriliyorsa boş zincir geri gelmiştir.

---

## K-43 — Benimsenen gönderinin başlangıç zamanı yoktu

- **Tarih:** 2026-09-04
- **Belirti:** Mutabakat taşıyıcıda bir **gönderi** buluyor ve benimsiyor;
  durum `shipment_created` oluyor. Sonraki ilk poll turu tek okuma yapmadan
  `give_up:max_elapsed_reached` ile bitiyor. Paket taşıyıcıda var ve hiçbir şey
  onu izlemiyor.
- **Kesin kök neden:** `META_CREATED_AT` yalnız `save_order_created()`
  tarafından yazılıyordu, o da onaylanmış bir `createOrder`dan sonra çalışır.
  `Manager::reconcile()` bulunan bir gönderiyi doğrudan
  `save_shipment_created()` ile benimser — bu sipariş ömründe arkasında
  onaylanmış bir `createOrder` olmadan. O yolda `created_at` **0** kalıyordu,
  ve poller geçen süreyi `time() - created_at` ile hesaplar: sıfır, 1970'ten
  beri geçen her saniye demektir, yani ilk tur `MAX_ELAPSED`'i aşar.
- **Uygulanan düzeltme:** `save_shipment_created()` alan **boşsa** aynı atomik
  persist içinde `time()` yazıyor. Mevcut bir değere dokunmuyor: o değer
  gönderinin gerçekten başladığı andır, benimsendiği an değil.
  `save_order_created()` davranışı değişmedi.
- **Kanıt:** `SHIPPING_ADOPTED_SHIPMENT_HAS_A_START_TIME` — `created_at`
  öncesinde 0, benimsemeden sonra gerçek bir an (**0 sn** sapma, taze sipariş
  nesnesinden okunuyor), ilk poll kararı `reschedule/still_moving` ve gerçek
  runner takip işini planlıyor; önceden var olan `1700000000` değeri
  değişmiyor. Düzeltmeden önce aynı ölçüm `created_at_after:0` ve
  `first_poll_decision:give_up/max_elapsed_reached` veriyordu.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`save_shipment_created`)
- **Tekrar yaşanırsa ilk bak:** `save_shipment_created()` içinde
  `META_CREATED_AT` yazımı. Yoksa mutabakatla benimsenen her gönderi ilk turda
  vazgeçilir.

---

## K-44 — Otomatik kargo bildirimi hiç gönderilmiyordu

- **Tarih:** 2026-09-04
- **Belirti:** Taşıyıcı durumu 2'ye geçiyor, WooCommerce fulfillment kaydı
  `fulfilled` oluyor, müşteriye **hiçbir e-posta gitmiyor**. Davranış
  ölçümünde bildirim olayı **0**, mail denemesi **0**.
- **Kesin kök neden:** `woocommerce_fulfillment_created_notification` eylemini
  WooCommerce **tek bir yerden** tetikler: kendi REST controller'ından, çekmecedeki
  "müşteriye bildir" işareti işaretliyken. Ne veri deposu ne `Fulfillment`
  nesnesi bu eylemi üretir. Modül kaydı doğrudan kaydettiği için eylem hiç
  oluşmuyordu.
- **Uygulanan düzeltme:** `Kuka_Island_Shipping_Notification::on_fulfilled()`.
  Eylem modülün kendisi tarafından, **yalnız kendi kaydının** ilk
  `unfulfilled → fulfilled` geçişinde tetikleniyor. Manuel yol değişmedi:
  operatörün işareti yine WooCommerce'in controller'ından geçer, ve elle
  oluşturulmuş bir kayıt taşıyıcı referansı taşımadığı için
  `Fulfillment_Writer::find_own()` onu hiç döndürmez.
- **Neden bir durum makinesi var:** taşıyıcı durumu tekrar tekrar sorulur ve
  "transfer aşamasındayken e-posta gönder" iki kez çalışırsa müşteri iki ileti
  alır. Tetik durum değil **geçiş**tir, ve geçiş taşıyıcıya değil **taşımaya**
  dokunmadan önce kalıcı yazılır:
  `pending → sending → sent | failed | reconciliation_required`.
  `sending` bulmak, önceki sürecin SMTP konuşması açıkken öldüğü anlamına
  gelir; müşteride ileti olabilir, bu yüzden o durum **otomatik olarak
  tekrarlanmaz** ve `reconciliation_required`'a geçer.
- **Kanıt:** `SHIPPING_NOTIFIES_CUSTOMER_ONCE_ON_DISPATCH` — kod 2 öncesi mail
  **0**; ilk geçişte **1**; aynı durumun tekrar sorgusunda hâlâ **1**; kod 3, 4
  ve 5'ten sonra hâlâ **1**; sipariş `completed`'a **geçmiyor**.
  `SHIPPING_NOTIFICATION_OUTCOME_IS_SAFE` — kesin ret: `failed`, güvenli kod
  `wp_mail_failed`, sınırlı yeniden deneme başarılı; belirsiz sonuç:
  `reconciliation_required`, taşıma sonradan mükemmel çalışsa bile **ikinci
  gönderim 0**. `SHIPPING_MANUAL_FULFILLMENT_ROUTE_UNTOUCHED` — notify=false → 0,
  notify=true → 1, modül o kayıt hakkında hiçbir şey yazmıyor.
  `SHIPPING_MANUAL_ROUTE_WITH_PLUGIN_INACTIVE` — aynı ölçüm, eklenti gerçekten
  **kapalıyken** taze bir süreçte: `plugin_active:no|module_loaded:no`,
  notify=false → 0, notify=true → 1. Yaşam döngüsü testinin `core_works` alanı
  yalnız iki Core sınıfının tanımlı olduğunu söyler, operatörün yolunun hâlâ
  e-posta gönderdiğini söylemez; bu ölçüm onu söyler. Ayrımın kanıtı: aynı
  betik eklenti **açıkken** koşturulduğunda `plugin_active:YES` ile FAIL verir.
- **Sır sızıntısı:** taşıma katmanının hata metni bu yolda kimlik taşıyabilecek
  tek dizgidir ve **hiç saklanmaz**; yalnız izin listesindeki bir kod
  kaydedilir. Ölçüm, hata mesajına SMTP kullanıcı adı gibi görünen bir sentinel
  koyup modülün bütün yüzeylerini birebir adla arıyor: `secret_leaks:0`,
  `raw_transport_text:0`.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-notification.php`,
  `SHIP/includes/shipping/class-fulfillment-writer.php` (`sync_status`),
  `scripts/verify-shipping-manual-route-passive.php`
- **Tekrar yaşanırsa ilk bak:** `_kuka_shipping_notify_state` metası. `sent` ise
  bildirim gitmiş; boşsa geçiş hiç algılanmamış demektir ve
  `sync_status()` içindeki `$first_transition` okumasına bakılır.

---

## K-45 — Gönderim e-postasının Türkçesi makine çevirisiydi

- **Tarih:** 2026-09-04
- **Belirti:** Müşteriye giden konu ve başlık, 4 Eylül 2026'da ölçülen hâliyle:
  `Kuka Island Siparişteki  bir öğe yerine getirildi!` (çift boşluk dâhil) ve
  `Öğeniz yolda!`. Gövde: `Woo! Satın aldığınız bazı öğeler yerine
  getiriliyor.` Bir kargo bildiriminde "öğe yerine getirilmez".
- **Kesin kök neden:** WooCommerce'in kendi tr_TR çevirisi. Konu ve başlık
  filtrelenebilir; gövde metni şablonun içinde `esc_html__()` ile basılır ve
  kancası yoktur.
- **Uygulanan düzeltme:** Metin **Core'da** düzeltildi, kargo eklentisinde
  değil: aynı e-posta hem operatörün işaretinden hem eklentinin otomatik
  bildiriminden aynı sınıfla gönderilir, dolayısıyla tek doğru yer ikisinin de
  ortak olduğu katmandır — ve eklenti pasifken manuel yol da doğru Türkçeyi
  alır. Konu/başlık `woocommerce_email_subject_*`/`_heading_*` ile; gövdenin beş
  dizgisi dar bir `gettext` haritasıyla. Şablon **kopyalanmadı**.
- **İngilizce siparişler:** metin `__()`'den beklenmiyor, doğrudan yazılı.
  `switch_to_locale( 'en_US' )` sonrasında `woocommerce` alanının tr_TR
  girdileri bellekte kalabildiği için `get_default_subject()` yine Türkçe
  dönebiliyordu; ölçülen hâli tam olarak buydu. Bu yüzden iki e-postanın konu
  ve başlığı Core'un genel `english_email_subject/heading` listesinden
  **çıkarıldı** ve iki dil tek yerde, siparişin diline bakılarak veriliyor.
- **Kanıt:** `SHIPPING_NOTIFICATION_TEXT_FOLLOWS_ORDER_LANGUAGE` — gerçek
  gönderim üzerinden: TR konu `Kuka Island siparişiniz kargoya verildi!`,
  başlık `Siparişiniz yola çıktı`, doğal gövde, makine ifadesi **0**, takip
  numarası ve takip bağlantısı gösteriliyor; EN konu
  `Your Kuka Island order <n> has shipped!`, gövde `Your order is on its way`,
  Türkçe artık **yok**.
- **İlgili dosya:** `wp-content/plugins/kuka-island-core/includes/class-fulfillments.php`,
  `.../class-language.php`
- **Tekrar yaşanırsa ilk bak:** `english_email_subject()`'in bu iki id'yi
  yeniden kapsayıp kapsamadığı. Kapsıyorsa doğru İngilizce metnin üstüne
  Türkçe makine çevirisi yazar.

---

## K-46 — İkinci bildirim dilini önceki siparişten alıyordu

- **Tarih:** 2026-09-04
- **Belirti:** Aynı istekte iki gönderim bildirimi gönderildiğinde, ikincisi
  birincinin diliyle yazılıyor. İngilizce bir sipariş, kendisinden önce
  gönderilen Türkçe siparişin diliyle Türkçe gidiyor.
- **Kesin kök neden:** `WC_Email` nesnesi yeniden kullanılır. Bu iki e-postanın
  `trigger()` metodu **önce** `setup_locale()` çağırır, `$this->object`
  siparişi ondan **sonra** atanır — standart sipariş e-postalarında sıra
  terstir. Core'un dil anahtarı `$email->object` okuduğu için ilk bildirimde
  `null`, ikincisinde **bayat** (önceki sipariş) görüyordu. Boş olması hemen
  fark edilirdi; bayat olması fark edilmedi.
- **Uygulanan düzeltme:** Bildirim eylemi WooCommerce'in trigger'ından (öncelik
  10) önce, öncelik 9'da dinlenip sipariş kenara yazılıyor ve 999'da
  bırakılıyor. Dil anahtarında kenara yazılan değer **`$email->object`'ten önce**
  gelir; eylem dışında null olduğu için diğer bütün e-postalarda davranış
  değişmez.
- **Kanıt:** `SHIPPING_NOTIFICATION_TEXT_FOLLOWS_ORDER_LANGUAGE` ölçümü gövde
  render anındaki dili kaydeder: `locale_at_body_render:tr_TR/en_US`. Düzeltme
  öncesi ikisi de `tr_TR` idi.
- **İlgili dosya:** `wp-content/plugins/kuka-island-core/includes/class-language.php`
  (`remember_fulfillment_order`, `forget_fulfillment_order`,
  `switch_email_locale`)
- **Tekrar yaşanırsa ilk bak:** `switch_email_locale()` içinde `$email->object`
  önce mi okunuyor. Önce okunuyorsa ikinci bildirim yine bayat dili alır.

---

## K-47 — `FS_CHMOD_FILE` bir yükleme sırası kazasına bağlıydı

- **Tarih:** 2026-09-04
- **Belirti:** WP-CLI'de `FS_CHMOD_FILE` sabitiyle ilgili kırılganlık.
- **Ölçülen durum, dürüst hâliyle:** hata bu ortamda **üretilemedi**.
  `get_filesystem_method()` `direct` döndüğü ve iyzico'nun `AbstractLogger`
  sınıfı bootstrap sırasında `WP_Filesystem()` çağırdığı için sabit tanımlı ve
  değeri `0644`.
- **Kırılganlığın kesin yeri:** WordPress bu sabiti **yalnız**
  `WP_Filesystem()` içinde tanımlar, o da `wp-admin/includes/file.php` yüklüyse
  vardır; WP-CLI yönetim tarafını yüklemez. iyzico'nun logger'ı
  `$wp_filesystem` doluysa kurulum bloğunu **atlar** ve doğrudan
  `\FS_CHMOD_FILE` kullanır: global dolu ama sabit tanımsızsa PHP "Undefined
  constant" ile ölür. `get_filesystem_method()` `direct` dönmediğinde de
  eklentinin yedek yolu erken döner ve sabiti hiç tanımlamaz.
- **Uygulanan düzeltme:** Vendor dosyasına **dokunulmadı**. Core sabiti
  `Compatibility::ensure_chmod_file_constant()` ile, WordPress'in
  `wp-admin/includes/file.php` içindeki **kendi formülüyle**
  (`fileperms( ABSPATH . 'index.php' ) & 0777 | 0644`) ve **yalnız tanımsızsa**
  tanımlıyor. Tanımlıysa hiçbir şey yapmaz, dolayısıyla WordPress'in ya da
  başka bir eklentinin değeri hiçbir koşulda değişmez.
- **Kanıt:** `SHIPPING_FS_CHMOD_FILE_GUARDED_IN_PROJECT` — Core'un değeri
  WordPress'in formülüyle **birebir aynı**, ve sabite ihtiyaç duyan gerçek
  vendor yolu (iyzico logger'ının `.htaccess` yazması) geçici bir dizinde
  çalıştırılıp `Deny from all` içeriği doğrulanıyor; dizin birebir yolla
  siliniyor.
- **İlgili dosya:** `wp-content/plugins/kuka-island-core/includes/class-compatibility.php`
- **Tekrar yaşanırsa ilk bak:** Ölçümdeki `guard:` alanı. `defined_now` ise
  sabiti artık Core tanımlıyor demektir ve bu beklenen davranıştır; `already_defined`
  ise başka bir yol daha önce tanımlamıştır. İkisi de doğrudur — yanlış olan,
  hiç tanımlanmamış olmasıdır.

---

## K-48 — Gerçek SMTP açılınca e-posta kabul ölçümleri iki yerden kırıldı

- **Tarih:** 2026-09-04
- **Belirti:** Sekizinci turun ilk `make verify` koşusu beş FAIL verdi:
  `SITE_EMAIL`, `SMTP_IDENTITY`, `MAIL_FROM_IDENTITIES`, `DISABLED_MAIL_SAFE`,
  `DISABLED_MAIL_ORDER_NOTE`. Hiçbiri kargo bildirimiyle ilgili değildi.
- **Birinci kök neden — sabite bağlanmış kullanıcı verisi:** Gönderici adresi
  `Email_Delivery::sender_email()` üzerinden Site Görünümü panelindeki marka
  e-postasından türer. Operatör adresi `info@kukaisland.com` yerine
  `hello@kukaisland.com` yaptığında üç ölçüm birlikte kırıldı, çünkü hem
  `verify.php` hem `verify-email-delivery.php` **eski adresi sabit** yazıyordu.
  Bu tür bir FAIL'in "düzeltmesi" kullanıcı verisini geri yazmak gibi görünür;
  değildir. Ölçümler adrese değil **tek kaynağa** bağlandı: `SITE_EMAIL`
  artık `configured` (geçerli ve marka alan adında) döndürür,
  `MAIL_FROM_IDENTITIES` ise `wp=woo:brand`. Ayrışma yine FAIL'dir.
- **İkinci kök neden — canlı SMTP kabul testini sessizce dışarı taşıdı:**
  `disabled-mail` ölçümü PHP `mail()` kapalıyken güvenli başarısızlığı sınar.
  Ortama gerçek SMTP girdiği anda `wp_mail()` artık `mail()` üzerinden geçmez:
  ölçüm anlamını yitirir **ve her `make verify` koşusu operatörün üretim posta
  sunucusuna gerçek bir mesaj bırakır**. Taşıyıcı bu tek gönderim için
  `phpmailer_init` üzerinde `PHP_INT_MAX` önceliğiyle `isMail()`'e geri
  çekiliyor ve fiilen kullanılan taşıyıcı `DISABLED_MAIL_TRANSPORT=mail`
  olarak **ölçülüyor**. Sözleşme bir daha sessizce dışarı kaymaz.
- **İlgili dosya:** `scripts/verify.php`, `scripts/verify-email-delivery.php`,
  `scripts/verify.sh`
- **Açık kalan, proje tarafından düzeltilemeyen nokta:** `smtp` ölçümü sentetik
  kimlikleri `wp-load`'dan önce tanımlar, böylece gerçek kimlik bilgileri
  ölçüme hiç girmez. Operatörün `docker-compose.yml` dosyasındaki wp-config
  bloğu aynı sabitleri **korumasız** `define()` ettiği için koşu başına sekiz
  `Constant ... already defined` uyarısı düşer. Uyarı çıkışı etkilemez; tek
  satırlık çözüm o blokta `defined( 'X' ) ||` koruması eklemektir ve o dosya
  operatöre aittir.
- **Tekrar yaşanırsa ilk bak:** `DISABLED_MAIL_TRANSPORT`. `smtp` yazıyorsa
  kabul testi dış sunucuya mesaj bırakıyor demektir; ölçümü zayıflatmak değil,
  taşıyıcıyı geri çekmek doğru cevaptır.

---

## K-49 — Kargo e-postası WooCommerce'in varsayılan görünümünde çıkıyordu

- **Tarih:** 2026-09-04
- **Belirti:** Bildirim gidiyor ama görünüm mağazanın değil WooCommerce'in:
  600 piksel genişlik, mor bağlantılar, ham `dhl` yazan taşıyıcı satırı,
  "Fulfillment summary" başlığı, misafir siparişinde "Hesabım > Siparişler"
  bağlantısı ve takip adresi boşken bile bir bağlantı.
- **Gmail'de ürün fotoğrafının görünmemesinin kesin nedeni:** Şablon
  `show_image=true` kullanır ve test ürününün görseli **vardır**. Adres
  `http://localhost:8080/wp-content/uploads/...` olduğu için Gmail ona
  erişemez. Bu "şablonda resim yok" değildir; adres sorunudur.
- **Uygulanan düzeltme:** Ortak tasarım katmanı
  `Kuka_Island_Core_Email_Design` ve üç küçük şablon kopyası. Tam sözleşme,
  gerekçeleri ve ölçüm listesi: `docs/EPOSTA_TASARIMI.md`.
- **Kargo e-postasına özgü olanlar:** taşıyıcı adı WooCommerce'in taşıyıcı
  kaydından çözülür (`dhl` → `DHL`), bulunamazsa Türkiye taşıyıcıları
  tablosundan, sonra `kuka_island_email_carrier_label` filtresinden — Core
  kargo eklentisine bağımlı olmadan. Takip kartı kargo firması, takip numarası
  ve **varsa** tahmini teslim tarihini taşır; adres yoksa düğme basılmaz.
  Ürün satırı 104 piksel görsel, ad, varyasyon, adet ve fiyat gösterir;
  varyasyon görseli yoksa ana ürünün görseline dönülür.
- **Manuel ve otomatik yol aynı şablonu kullanır:** ikisi de WooCommerce'in
  `woocommerce_fulfillment_created_notification` eylemi, aynı e-posta sınıfı ve
  aynı `email-fulfillment-details.php` kopyasıdır (bkz. K-44).
- **İlgili dosya:** `docs/EPOSTA_TASARIMI.md`,
  `wp-content/plugins/kuka-island-core/includes/class-email-design.php`,
  `wp-content/themes/kuka-island-child/woocommerce/emails/`
- **Tekrar yaşanırsa ilk bak:** `EMAIL_DESIGN_IMAGES` satırındaki
  `localhost_gate`. `not_https` ise kapı çalışıyor ve müşteriye kırık resim
  gitmiyor demektir; üretimde `public_https_img:1` beklenir.

---

## K-50 — İki eşzamanlı süreç aynı kargoya iki e-posta gönderiyordu

- **Tarih:** 2026-09-04
- **Belirti:** Tekrar-poll ölçümleri yeşilken müşteri iki "kargoya verildi"
  e-postası alabiliyordu. O ölçümler SIRALI çalışıyor: biri bitmeden öteki
  başlamaz ve o sırada durum makinesi çoktan `sent` yazmış olur. Yarışı
  kanıtlamazlar.
- **Kesin kök neden:** Bildirim kararı kilitsiz bir "oku, sonra yaz"dı.
  `query_status()` mutasyon kilidi almıyor; `sync_status()` fulfillment
  durumunu ve `_kuka_shipping_notify_state` metasını ayrı ayrı, kilitsiz
  okuyordu. Üretimdeki iki yol — Action Scheduler'ın zamanlanmış sorgusu ve
  operatörün "durumu sorgula" basışı — siparişi isteğin BAŞINDA belleğe alır.
  İkisi de `fulfilled` olmayan bir kayıt ve boş bir bildirim durumu görüp
  gönderiyordu.
- **Ölçülen kırmızı** (iki gerçek PHP süreci, iki ayrı MySQL oturumu, ortak
  mikro saniye bariyeri):

  ```text
  SHIPPING_NOTIFICATION_CONCURRENT_FIRST_DISPATCH=FAIL|processes:2
  |both_started_from_unfulfilled:yes|lock_winners:2|mail_attempts:2
  |notification_events:2|final_state:sent|attempts:1|order_status:pending
  ```

  İki e-posta, iki bildirim olayı — ve **ikinci yazma birincinin metasını
  eziyor**, dolayısıyla `sent|attempts:1` tek gönderim gibi görünüyor. Durum
  makinesinin kendi kanıtı mükerrer iletiyi saklıyordu.
- **Uygulanan düzeltme:** Bildirim kararı sipariş bazlı, **sıfır beklemeli**
  bir advisory lock altına alındı: `kuka_ship_notify_<order_id>`. Kilit
  alındıktan sonra sipariş **veritabanından taze okunuyor** (HPOS sipariş
  önbelleği düşürülerek) ve fulfillment kaydı `find_own()` ile yeniden
  okunuyor; "ilk geçiş mi" ve durum kararı yalnız bu taze kayıtlardan
  veriliyor. SMTP niyeti yine dış çağrıdan **önce** kalıcı yazılıyor.
- **Kilidi alamayan süreç ne yapar:** hiçbir şey göndermez, `lock_contended`
  döner ve **beklemez**. Kazananın sonucu kalıcı olduğu için sonraki poll onu
  taze okur ve `already_sent` der.
- **Neden deadlock üretmez:** Bu modüldeki bütün adlandırılmış kilitler
  `GET_LOCK(..., 0)`, yani hiçbir süreç bir kilidi tutarken başka bir kilidi
  BEKLEMEZ. Bekleme olmadan döngü kurulamaz. Bildirim kilidi taşıyıcı mutasyon
  kilidinden (`kuka_ship_mutate_`) ayrı bir addır: bir bildirim taşıyıcı
  yazmasını, taşıyıcı yazması bildirimi reddedemez.
- **İki yarım düzeltme, iki ayrı ölçüm:** kilit tek başına yetmez, çünkü
  kazanan kilidi bıraktıktan sonra giren süreç onu çekişmesiz alır ve elindeki
  meta bayattır. `SHIPPING_NOTIFICATION_STALE_SECOND_PROCESS` o sıralamayı
  ölçer: `first:sent|second:already_sent`. Taze okuma kaldırılınca bu ölçüm
  FAIL verir (`second:not_due` — karar bayat durumdan, kazara güvenli).
- **İlgili dosya:**
  `SHIP/includes/shipping/class-shipment-notification.php` (`on_fulfilled`,
  `decide`, `reload_order`, `acquire_lock`),
  `SHIP/includes/shipping/class-fulfillment-writer.php` (`sync_status`
  referansı geçiriyor), `scripts/verify-shipping-notification-race.php`
- **Kapsam dışı bırakılan, dürüstçe:** Fulfillment kaydının kendi yazması hâlâ
  kilitsizdir. İki süreç aynı anda `set_status('fulfilled')` ve boşsa
  `_date_fulfilled` yazabilir; sonuç ikisinde de `fulfilled`, tarih ise mikro
  saniye farkıyla ikinci sürecin damgası olur. Kilidin kapsamı bilinçli olarak
  **yalnız bildirim yaşam döngüsüdür**; taşıyıcı yolunu da aynı kilide almak
  taşıyıcı davranışını değiştirirdi.
- **Dış çağrı yok, ölçülmüş hâliyle:** ölçüm durum kodunu doğrudan
  `sync_status()` içine verir, taşıyıcı istemcisi hiç kurulmaz, posta taşıyıcısı
  `pre_wp_mail` üzerinde kesilir ve işçiler `pre_http_request` kancasını
  fail-closed tutar: `outbound_http:0`. Fixture'lar `SHIPPING_DB_ISOLATION`
  parantezinin içindedir ve `keyset_match:yes` verir.
- **Tekrar yaşanırsa ilk bak:** `SHIPPING_NOTIFICATION_RACE_OUTCOMES`.
  `sent,sent` görünüyorsa kilit alınmıyor demektir; `lock_released:NO`
  görünüyorsa `finally` yolu kırılmıştır.

---

## K-51 — Bildirim borcu iki yerden düşüyordu: `pending` ödenmiyor, çökme kaybediyor

- **Tarih:** 2026-09-04
- **Belirti:** Mükerrer gönderim kapandıktan sonra ters yönde iki kayıp yolu
  kaldı. İkisinde de müşteri **hiç** bilgilendirilmiyor ve sistemde bir borç
  görünmüyor.
- **Kök neden 1 — `pending` yeniden denenmiyordu.** `recipient_missing` ve
  `mailer_unavailable` "gönderilmedi, sonra denenmeli" anlamında `pending`
  yazıyor. Ama `decide()` yalnız `failed` durumunu özel işliyordu; `pending`
  `first_transition` kapısına düşüyordu. Sonraki poll'da kayıt zaten
  `fulfilled` olduğu için `first_transition` FALSE gelir ve
  `elseif ( ! $first_transition ) return not_due;` dalı çalışır. Borç kalıcı
  olarak ödenmez.
- **Ölçülen kırmızı:**

  ```text
  SHIPPING_NOTIFICATION_PENDING_RETRIES_WHEN_DUE=FAIL|first:recipient_missing
  |state_after_first:pending|attempts_after_first:0|mails_first:0
  |second_first_transition:no|second:not_due|mails_second:0|state:pending
  |attempts:0|third:not_due|mails_total:0|order_status:pending
  ```

- **Kök neden 2 — kayıt ile niyet arasında çökme aralığı.** `sync_status()`
  kaydı `fulfilled` yapıp `save()` ediyor, bildirim niyeti ancak ondan SONRA
  `on_fulfilled()` içinde yazılıyordu. Arada ölen süreç, diskte `fulfilled`
  bir kayıt ve **boş** bir bildirim durumu bırakır; sonraki her poll
  `first_transition` false görüp `not_due` der.
- **Ölçülen kırmızı** (süreç, `woocommerce_fulfillment_after_update` içinden
  öldürüldü — o kanca satır yazıldıktan sonra ve hiçbir transaction içinde
  olmadan tetikleniyor, yani ölüm tam bildirilen aralıkta):

  ```text
  SHIPPING_NOTIFICATION_CRASH_BEFORE_SEND_INTENT_RECOVERS=FAIL|crash_exit:nonzero
  |crash_mails:0|record_fulfilled_after_crash:yes|state_after_crash:absent
  |recovery:not_due|mails_total:0|state:absent|attempts:0
  |second_automatic_send:0|order_status:pending
  ```

- **Uygulanan düzeltme:** Yeni bir durum, `due`, ve onu yazan
  `Notification::claim()`. Borç **kayıt kaydedilmeden ÖNCE** yazılıyor ve yalnız
  modülün kendi kaydının gerçek ilk otomatik geçişinde. `decide()` artık `due`
  ve `pending` durumlarını **borçlu** sayıyor: `first_transition` ne derse desin
  koşul düzeldiğinde gönderiyor, ve ikisi de deneme hakkı harcamıyor.
- **Kendiliğinden sahiplenme yok:** durumun BOŞ olması gönderime izin vermez.
  Manuel fulfillment bu satırdan hiç geçmez; modülün referansını taşıyan ama
  borcu yazılmamış, önceden `fulfilled` bir kayıt da `not_due` alır
  (`SHIPPING_NOTIFICATION_NO_SELF_ADOPTION`). Borç ayrıca kendi kaydına
  bağlıdır: `_kuka_shipping_notify_reference` başka bir referans gösteriyorsa
  karar `other_record` ile durur.
- **Değişmeyenler:** `sending` çökme davranışı (`reconciliation_required`,
  otomatik ikinci gönderim 0), `failed` sınırlı yeniden deneme, sipariş bazlı
  sıfır beklemeli bildirim kilidi ve kilit sonrası taze sipariş/kayıt okuması.
- **Fulfillment tarihi, ölçümle:** ezme gerçek —
  `concurrent_date_writes:2`. Ama 120 ms arayla koşan ikinci süreç tarihi zaten
  dolu gördüğü için hiç yazmıyor (`offset_process_date_writes:1`), yani ezme
  yalnız ikisi de kaydı kaydetmeden önce okuduğu pencerede oluyor ve o pencerede
  iki damga birbirinden mikro saniyeler kadar uzak. Saklanan değer saniye
  çözünürlüğünde olduğu için fark ancak iki damga bir saniye tikinin iki yanına
  düşerse ortaya çıkar. Bir milisaniyelik yarışı kovalayan ölçüm kararsızdır ve
  hiçbir şey kanıtlamaz; bu yüzden soru **mekanizma** olarak sorulup kapatıldı:
  teslim anı borçla birlikte **bir kez** yazılıyor
  (`_kuka_shipping_notify_handover_at`) ve tarih o değerden alınıyor, dolayısıyla
  ikinci yazma AYNI dizgiyi yazar. Ölçüm bunu, borcunu yazıp çökmüş bir sürecin
  bıraktığı hâli 26 saat öncesine kurup saklanan tarihin onu takip etmesiyle
  kanıtlıyor: `stored_matches_agreed:yes|retry_moves_date:no`. Anlaşma
  kaldırılınca ölçüm FAIL verir (`retry_moves_date:yes`). Yan kazanç: bir gün
  sonra çalışan yeniden deneme mali tarihi kaydırmıyor.
- **Neden `claim()` bekleyebiliyor:** kendi kilidi (`kuka_ship_notify_claim_`)
  bir YAPRAK kilit — tutan süreç bir meta okur, bir meta yazar ve hiçbir şey
  beklemez, dolayısıyla her zaman bırakır. Bekleyen her zaman ilerler ve döngü
  kurulamaz; çağıran taşıyıcı mutasyon kilidini tutuyor olsa bile. Zaman aşımı
  ölümcül değildir: o durumda claim kendi anını kullanır, yani düzeltme
  öncesindeki davranışa döner.
- **İlgili dosya:**
  `SHIP/includes/shipping/class-shipment-notification.php` (`claim`, `decide`,
  `STATE_DUE`, `META_HANDOVER_AT`),
  `SHIP/includes/shipping/class-fulfillment-writer.php` (`sync_status`),
  `scripts/verify-shipping-notification-race.php`
- **Tekrar yaşanırsa ilk bak:** `_kuka_shipping_notify_state` metası. `due` ise
  borç yazılmış ama gönderim henüz olmamıştır ve sonraki poll onu ödemelidir;
  `pending` ise bir koşul eksikti (`_kuka_shipping_notify_code`), koşul
  düzeldiğinde gönderilir. Boş ve kayıt `fulfilled` ise bu modül o geçişi hiç
  görmemiştir — kendiliğinden sahiplenmez.

---

## K-52 — Claim'in her sınırı fail-open'dı

- **Tarih:** 2026-09-04
- **Belirti:** K-51'in `claim()` akışı doğru borcu yazıyordu, ama yazamadığı
  durumları **sessizce geçiyordu**. `claim()` yalnız bir dizgi döndürüyordu;
  başarısızlığı anlatacak bir kanalı yoktu, dolayısıyla `sync_status()` her
  koşulda kaydı `fulfilled` yapıp tarihi yazıyor ve e-postayı gönderiyordu.
- **Ölçülen kırmızı, üç sınır:**

  ```text
  CLAIM_LOCK_IS_FAIL_CLOSED=FAIL|lock_held_by_other_session:yes|outcome:none
  |notification_meta_writes:5|fulfilled:yes|date:present|mails:1
  CLAIM_READBACK_IS_VERIFIED=FAIL|outcome:none|notification_meta_writes:0
  |fulfilled:yes|date:present|mails:1|retry_outcome:not_due
  CLAIM_UNREADABLE_ORDER_IS_FAIL_CLOSED=FAIL|outcome:none
  |notification_meta_writes:0|fulfilled:yes|date:present|mails:0
  ```

  İkinci satır en kötüsü: meta hiç yazılmadığı hâlde müşteriye e-posta gitti ve
  sonraki poll `not_due` dedi — yani borç kayboldu, üstelik ileti gitmişti.
- **Nasıl ölçüldü, üretim yolundan:**
  - **Kilit çekişmesi:** ölçüm süreci `kuka_ship_notify_claim_<order_id>`
    kilidini kendi MySQL oturumunda tutar; işçi ayrı bir süreçtir, dolayısıyla
    ayrı bir oturumdur ve `GET_LOCK` oturum başına çalışır.
  - **Yazmanın düşürülmesi:** WordPress'in kendi `query` filtresi ile
    `_kuka_shipping_notify` içeren INSERT/UPDATE ifadeleri `SELECT 1`'e
    çevrilir. `save_meta_data()` başarılı sanır, satır diske hiç düşmez.
  - **Okunamayan sipariş:** `woocommerce_order_class` filtresi var olmayan bir
    sınıf adı döndürür; `WC_Order_Factory` `false` döner ve `wc_get_order()`
    başarısız olur. Filtre işçi kendi siparişini yükledikten SONRA takılır,
    böylece başarısız olan tek şey claim'in kilit içindeki taze okumasıdır.

  Üretim kodunda hiçbir test kancası yok; üçü de WordPress'in kendi
  yüzeylerinden geliyor.
- **Uygulanan düzeltme:** `claim()` artık
  `array{ok: bool, outcome: string, handover: string}` döndürüyor. Her sınır
  reddediyor, tahmin etmiyor:
  - kilit alınamadı → `claim_lock_contended`
  - sipariş taze okunamadı → `claim_order_unreadable`
  - referans yok → `claim_reference_missing`
  - yazma geri okumada doğrulanamadı → `notification_claim_unverified`
  - mevcut borç başka bir referansa ait → `claim_other_record`
  - mevcut borcun sahibi ya da anı boş → `notification_claim_unverified`

  Geri okuma **bayt bayt**: durum `due` mü, sahip beklenen taşıyıcı referansı
  mı, teslim anı yazılan değerin aynısı mı. `save_meta_data()` hata vermemesi
  satırın düştüğünün kanıtı değildir.
- **`sync_status()` tarafı:** claim `ok:false` ise `set_status('fulfilled')`,
  `set_date_fulfilled()` ve `save()` aşamalarına **hiç geçilmez**; dönen
  `reason` claim'in kendi outcome'ıdır.
- **DÜZELTME (K-53):** Bu kaydın ilk hâlinde "taşıyıcının durumu ellenmediği
  için sonraki poll yeniden dener" yazıyordu. Bu ifade **yalnız taşıyıcı durumu
  terminal DEĞİLKEN** doğruydu. İlk ve tek gözlenen durum doğrudan terminal
  olduğunda (kod 5) yeni bir poll hiç planlanmıyordu ve "sonraki poll" diye bir
  şey yoktu. Yeniden denemeyi artık taşıyıcıya hiç dokunmayan ayrı bir yerel
  işçi yapıyor; ölçülen hâli K-53'te.
- **Neden kaydı da durdurmak doğru:** `fulfilled` diyen ama arkasında borç
  olmayan bir kayıt, K-51'de müşterinin bildirimini kalıcı olarak kaybeden
  durumun ta kendisidir. Onu bilerek üretmemek, taşıyıcı durumunu bir poll
  gecikmesi kadar geride bırakmaktan daha güvenlidir.
- **İlgili dosya:**
  `SHIP/includes/shipping/class-shipment-notification.php` (`claim`,
  `claim_result`), `SHIP/includes/shipping/class-fulfillment-writer.php`
  (`sync_status`), `scripts/verify-shipping-notification-race.php`
- **Tekrar yaşanırsa ilk bak:** `sync_status()` dönüşündeki `reason`.
  `claim_lock_contended` ise başka bir süreç aynı siparişte karar veriyordu ve
  bir sonraki poll ilerler; `notification_claim_unverified` ise yazma diske
  düşmüyor demektir ve sorun veritabanı tarafındadır.

---

## K-53 — Terminal ilk cevap bildirim zincirini kapatıyordu

- **Tarih:** 2026-09-05
- **Belirti:** Taşıyıcının **ilk ve tek** gözlenen durumu doğrudan terminal
  olduğunda (kod 5, teslim edildi) ve bildirim claim'i reddedildiğinde: kayıt
  `unfulfilled` kalıyor, tarih yazılmıyor, e-posta gitmiyor — ama
  `query_status()` terminal BAŞARI döndürüyor, poller
  `stop:terminal_lifecycle` ile bitiyor ve hiçbir şey planlanmıyor. Müşteri
  hiçbir zaman bilgilendirilmiyor.
- **Kesin kök neden:** `Shipment_Manager::query_status()` taşıyıcı
  lifecycle'ını `save_status()` ile kaydediyor, ardından
  `Fulfillment_Writer::sync_status()` çağrısının sonucunu **tamamen yok
  sayıyordu**. Claim reddi hiçbir yere yazılmıyordu, dolayısıyla ne poller ne
  operatör bunu görebiliyordu.
- **Ölçülen kırmızı** (gerçek Action Scheduler worker'ı, üç engel için de
  birebir aynı):

  ```text
  lifecycle:delivered | claim:none | fulfilled:no | date:absent | mails:0
  follow_up:0 | runner:stop:terminal_lifecycle | total_mails:0
  ```

- **Neden mevcut poll action'ı yeniden kullanılamaz:** terminal bir cevap
  `save_status()` içinde siparişin durumunu `delivered` yapar, ve poll
  worker'ının ilk kapısı `STATE_SHIPMENT_CREATED` değilse `state_not_pollable`
  ile durur. O hooka planlanan bir action hiçbir şey yapmadan biter. Ayrıca
  taşıyıcıya yeniden sormak gereksiz bir istek olur ve taşıyıcıyı korumak için
  var olan deneme bütçesinden harcar.
- **Uygulanan düzeltme:** Taşıyıcıya **hiçbir yazma yapmayan** ayrı bir yerel
  işçi: `kuka_island_shipping_sync_fulfillment`. Taşıyıcının ÇOKTAN verdiği ve
  bu modülün çoktan kalıcılaştırdığı durum kodunu siparişin kendi metasından
  okuyup `sync_status()`'u yeniden çalıştırır. Sınırlı:
  `MAX_SYNC_ATTEMPTS = 5`, ilk gecikme 2 dakika, sonrası poll merdiveni;
  tavanda **tek** sipariş notu yazılır, her turda değil. `automation_enabled()`
  kapısına **bağlı değildir** — o anahtar taşıyıcı trafiğini durdurmak için
  vardır ve bu işçi taşıyıcıya çıkmaz — ama çalışma kapısı (acil durdurma)
  işçinin kendi içinde kontrol edilir.
- **Manager artık sonucu değerlendiriyor:** `sync_status()` dönüşü okunuyor,
  claim reddi `_kuka_shipping_sync_last_reason` metasına **güvenli kodla**
  yazılıyor (operatör görebiliyor), retry planlanıyor, ve dönüş dizisi
  `fulfillment`, `fulfillment_ok`, `fulfillment_error`, `fulfillment_retry`
  alanlarını taşıyor. Poller'ın çıktısı da artık bunu söylüyor:
  `stop:terminal_lifecycle:fulfillment_retry:created`. Sync başarılıysa red
  kaydı temizleniyor.
- **Yeniden denenmeyenler:** `claim_reference_missing` ve `claim_other_record`
  veri problemleridir; bir retry onları sonsuza kadar tekrarlar, bu yüzden
  `Notification::claim_refusals()` içinde **yok**. Yalnız geçici üç red
  (`claim_lock_contended`, `claim_order_unreadable`,
  `notification_claim_unverified`) yerel retry alır.
- **Ölçülen yeşil, üç engel için de:**

  ```text
  lifecycle:delivered | claim:<red kodu> | fulfilled:no | date:absent | mails:0
  follow_up:1 | runner:stop:terminal_lifecycle:fulfillment_retry:created
  retry_fulfilled:yes | retry_mails:1 | total_mails:1
  ```

  Engel kaldırıldıktan sonra yerel retry işi bitiriyor, tarih **ilk başarılı
  claim anından** geliyor, taşıyıcının terminal durumu korunuyor, ve sonraki
  koşu ikinci ileti üretmiyor. Taşıyıcı yazması **0**.
- **Engeller nasıl üretildi** (üretim kodunda test kancası yok):
  - `claim_lock_contended`: ikinci bir `wpdb` bağlantısı — aynı süreç içinde
    bile ayrı bir MySQL oturumu, çünkü adlandırılmış kilitler bağlantı başınadır
    — `kuka_ship_notify_claim_<id>` kilidini tutar.
  - `notification_claim_unverified`: WordPress'in `query` filtresi
    `_kuka_shipping_notify` içeren INSERT/UPDATE'i `SELECT 1`'e çevirir.
  - `claim_order_unreadable`: sipariş önce önbelleğe alınır, sonra
    `woocommerce_order_class` filtresi o siparişin **her fabrika okumasını**
    düşürür. Worker'ın kendi yüklemesi önbellekten gelir ve çalışır; claim'in
    o önbelleği bilerek düşüren taze okuması çalışmaz.
- **İlgili dosya:**
  `SHIP/includes/shipping/class-shipment-manager.php` (`query_status`),
  `SHIP/includes/shipping/class-shipment-status-poller.php` (`SYNC_ACTION`,
  `schedule_sync`, `run_sync`),
  `SHIP/includes/shipping/class-shipment-order-store.php`
  (`record_sync_refusal`, `clear_sync_refusal`),
  `SHIP/includes/shipping/class-shipment-notification.php` (`claim_refusals`),
  `scripts/verify-shipping-automation.php`
- **Tekrar yaşanırsa ilk bak:** `_kuka_shipping_sync_last_reason` metası.
  Doluysa bildirim yerel olarak tamamlanamamış demektir ve
  `_kuka_shipping_sync_attempts` kaçıncı denemede olduğunu söyler; tavana
  gelindiğinde sipariş notu yazılır. Boşsa yerel yarım iş yok.

---

## K-54 — Yerel retry sözleşmesinin dört sınırı

- **Tarih:** 2026-09-05
- **Belirti:** K-53'ün yerel retry'ı doğru çalışıyordu, ama etrafındaki dört
  sınır yanlış davranıyordu. Üçü sessizce yanlış rapor veriyor, biri operatörü
  hiç bilgilendirmiyordu.

### 1. Planlama sonucu kanıt sayılıyordu

`query_status()` `schedule_sync()`'in **boş olmayan her** cevabını "retry
planlandı" diye taşıyordu. Oysa yalnız `created` ve `already_pending` bir
action'ın var olduğunu kanıtlar. `lock_contended`, `schedule_failed` ve
`scheduler_unavailable` üçü de "bu çağrı hiçbir şey oluşturmadı" demektir.

Ölçülen kırmızı, gerçek Action Scheduler yüzeyleriyle:

```text
lock_contended : follow_up:yes | pending:0 | error_meta:none | notes:0
schedule_failed: follow_up:yes | pending:0 | error_meta:none | notes:0
```

`follow_up:yes` derken bekleyen action **sıfır**. Poll zinciri terminal olarak
kapanıyor ve kimse bir şey bilmiyor.

**Düzeltme:** `Status_Poller::schedule_proves_action()` — yalnız iki kod
kanıttır. Kanıtlanmayan bir sonuç için `has_pending_sync()` bir kez daha
sorulur (bu bir okuma, varsayım değil); o da yoksa
`_kuka_shipping_sync_schedule_error` metasına güvenli kod yazılır ve **tek**
sipariş notu düşer. `run_sync()` kendi yeniden planlamasını aynı biçimde
değerlendirir. Aynı planlama hatası her turda yeni not üretmez.

Ölçülen yeşil:

```text
created        : follow_up:yes | pending:1 | error_meta:none
already_pending: follow_up:yes | pending:1 | error_meta:none
lock_contended : follow_up:no  | pending:0 | error_meta:lock_contended  | notes:1 | panel:yes
schedule_failed: follow_up:no  | pending:0 | error_meta:schedule_failed | notes:1 | panel:yes
```

`scheduler_unavailable` uçtan uca üretilemez — bildirilmiş bir PHP fonksiyonu
geri alınamaz — bu yüzden saf yüklem üzerinde ölçülür
(`scheduler_unavailable:policy_only`); girdiği manager yolu `schedule_failed`
ile uçtan uca kanıtlanmıştır.

### 2. İki sayaç birbirini eziyordu

Manager'da taşıyıcı sorgu denemesi ile yerel retry sayacı **tek bir
`$attempts` değişkeniydi**. Bir claim reddi, poller'ın `MAX_ATTEMPTS`'e karşı
harcadığı taşıyıcı bütçesini sıfırlıyordu.

Ölçülen kırmızı: mağazada `stored_query_attempts:7`, dönen `attempts:1`.

**Düzeltme:** `$query_attempts` ve `$sync_attempts` ayrı değişkenler. Dönen
`attempts` **daima** taşıyıcı sorgu sayısı; yerel sayaç ayrı
`fulfillment_sync_attempts` alanında. Yeşil: `attempts:7`,
`fulfillment_sync_attempts:1`.

### 3. İlk gecikme 1 saniyeydi

Manager `schedule_sync( $order_id, 1 )` çağırıyordu — bir saniye — oysa
`SYNC_FIRST_DELAY = 120`. Rapor iki dakika diyordu, kod bir saniye yapıyordu.

**Düzeltme:** gecikme verilmez, sabit kullanılır. Ölçüm sabitin adına değil
**gerçek Action Scheduler satırının timestamp farkına** bakar:
`first_delay_seconds:120`.

### 4. Operatör hiçbir şey görmüyordu

`_kuka_shipping_sync_last_reason` yazılıyor, **hiç okunmuyordu**. Bildirimi
sessizce takılmış teslim edilmiş bir kargo, gönderilmiş olanla ekranda birebir
aynı görünüyordu.

**Düzeltme:** Kargo panelinde `Admin::render_sync_state()` dört olguyu ve
gerektiğinde bir cümleyi gösterir: son yerel sync nedeni, `Deneme: N/5`,
bekleyen yeniden deneme var/yok, planlama sonucu, ve elle müdahale gerekiyorsa
doğal Türkçe açıklama. Retry beklerken cümle "otomatik olarak yeniden
denenecek" der, elle iş gerektiğinde "elle iletilmesi gerekiyor".

`claim_reference_missing` ve `claim_other_record` otomatik retry **almaz**
(`Notification::claim_blocks()`), ama kalıcı neden yazılır, panelde görünür ve
**tek** not oluşur; paneli tekrar açmak ya da sorguyu yinelemek notu
çoğaltmaz (`notes_after_first:1|notes_after_repeat:1`). Bu red bir deneme
harcamaz.

Başarılı sync'te neden, sayaç ve planlama hatası metaları **birlikte**
temizlenir; nonretryable bir hata başarı gibi temizlenmez.

- **İlgili dosya:**
  `SHIP/includes/shipping/class-shipment-manager.php` (`query_status`),
  `SHIP/includes/shipping/class-shipment-status-poller.php`
  (`schedule_proves_action`, `run_sync`),
  `SHIP/includes/shipping/class-shipment-order-store.php`
  (`record_sync_block`, `record_sync_schedule_error`, `clear_sync_refusal`),
  `SHIP/includes/shipping/class-shipment-notification.php` (`claim_blocks`),
  `SHIP/includes/shipping/class-shipment-admin.php` (`render_sync_state`)
- **Tekrar yaşanırsa ilk bak:** Kargo panelindeki "Müşteri bildirimi" satırı.
  Kod varsa yerel yarım iş var; "Bekleyen yeniden deneme: yok" ile birlikte
  görünüyorsa otomasyon bitmiştir ve kargo bilgisini müşteriye elle iletmek
  gerekir. "Planlama sonucu" satırı doluysa sorun Action Scheduler
  tarafındadır.

---

## K-55 — Yerel sync worker'ı başarı olmadan temizliyordu

- **Tarih:** 2026-09-05
- **Belirti:** K-53/K-54'ün yerel retry'ı beklerken sipariş verisi değişirse,
  operatöre gösterilecek bütün kayıt sessizce siliniyordu: neden, deneme sayısı
  ve planlama hatası. Müşteri hâlâ bilgilendirilmemişken sipariş ekranı
  gönderilmiş bir kargodan ayırt edilemez hâle geliyordu.
- **Kesin kök neden, iki yerde:** `run_sync()`
  - referans ya da durum kodu eksikse `clear_sync_refusal()` çağırıp
    `nothing_to_sync` diyordu — bir başarı değil, bir önkoşul eksikliği;
  - neden `claim_refusals()` dışında kaldığında, **başarı olup olmadığına
    bakmadan** `clear_sync_refusal()` çağırıyordu. `claim_other_record`,
    `claim_reference_missing`, `own_fulfillment_absent`,
    `fulfillments_api_unavailable` ve `fulfillment_write_failed` bu dala
    düşüyordu.

  Ayrıca `clear_sync_refusal( wc_get_order( ... ) )` çağrısı, okuma başarısız
  olursa tipli parametreye `false` veriyordu: TypeError.
- **Ölçülen kırmızı** (gerçek Action Scheduler worker'ı, planlanmış action
  çalışmadan önce veri değiştirildi):

  ```text
  owner_changed : reason:CLEARED | attempts:0 | notes:0 | panel_manual:no | fulfilled:no
  reference_gone: reason:CLEARED | attempts:0 | notes:0 | panel_manual:no
  record_gone   : reason:CLEARED | attempts:0 | notes:0 | panel_manual:no
  ```

- **Uygulanan düzeltme:** Tek bir yerleşim yolu,
  `Status_Poller::settle_sync()`, manager ile worker'ın ortak kullandığı.
  `classify_sync()` sonucu üçe ayırır:
  - **succeeded** — `ok:true` ve neden yok. **Yalnız burada** temizlik yapılır.
  - **retryable** — geçici red. Sayılır, sınırlı retry planlanır.
  - **blocked** — kişi gerekir. `record_sync_block()` ile kalıcı yazılır, tek
    not düşer, hiçbir şey planlanmaz, deneme sayısı korunur.

  Eksik önkoşullar artık kendi görünür nedenleriyle kaydediliyor:
  `sync_reference_missing`, `sync_status_code_missing`. Nedeni olmayan bir red
  `sync_unknown_failure` olur — hiçbir koşulda sessiz başarı yok. Her
  `wc_get_order()` sonucu tipli çağrıdan önce `instanceof` ile korunuyor.
- **İkinci açık — bayat planlama hatası:** İlk turda `schedule_failed`
  yazıldıktan sonra bir sonraki girişte booking gerçekten oluşuyorsa, eski
  `schedule_failed` metası panelde kalıyordu; operatör aynı anda hem
  "planlanamadı" hem "bekleyen deneme var" görüyordu. `clear_sync_schedule_error()`
  eklendi: **yalnız** planlama hatası temizlenir — booking `created`,
  `already_pending` ya da `has_pending_sync()` ile kanıtlandığında. Sync nedeni
  ve deneme sayısı gerçek başarıya kadar korunur. Aynı kural manager ve
  `run_sync()` yollarında aynı fonksiyondan gelir.
- **Bir yan bulgu:** `sync_status()`'un "değişiklik yok" dalı, bildirimin
  kendi geçici redlerini (`lock_contended`, `order_unreadable`,
  `record_unreadable`) `reason` olarak yüzeye çıkarmıyordu. O hâliyle
  `settle_sync()` onları başarı sayıp temizleyecekti. `Notification::notify_refusals()`
  ile ayrı bir sözlük olarak tanımlandı ve retryable sınıfına dahil edildi.
- **Ölçülen yeşil:**

  ```text
  owner_changed : reason:claim_other_record     | attempts:1 | notes:1 | pending:0 | panel_manual:yes
  reference_gone: reason:sync_reference_missing | attempts:1 | notes:1 | pending:0 | panel_manual:yes
  record_gone   : reason:own_fulfillment_absent | attempts:1 | notes:1 | pending:0 | panel_manual:yes
  bayat hata    : first_error:schedule_failed/pending:0 -> after_error:cleared/pending:1
                  reason korunmuş, attempts korunmuş, panel iki şeyi birlikte göstermiyor
                  action çalıştıktan sonra bütün sync metaları temiz, mail 1
  ```

- **İlgili dosya:**
  `SHIP/includes/shipping/class-shipment-status-poller.php`
  (`classify_sync`, `settle_sync`, `run_sync`, `SYNC_REFERENCE_MISSING`),
  `SHIP/includes/shipping/class-shipment-order-store.php`
  (`clear_sync_schedule_error`),
  `SHIP/includes/shipping/class-shipment-notification.php` (`notify_refusals`),
  `SHIP/includes/shipping/class-shipment-manager.php` (`query_status`)
- **Tekrar yaşanırsa ilk bak:** panelin "Müşteri bildirimi" satırı hâlâ
  doluyken "Bekleyen yeniden deneme: yok" yazıyorsa otomasyon durmuştur ve
  kargo bilgisi müşteriye elle iletilmelidir. Satır tamamen kaybolmuşsa ve
  müşteriye ileti gitmemişse, temizliğin yeniden başarı dışında bir yerden
  çağrıldığına bakılır.

---

## K-56 — Yazılan receipt'e hiçbir public girişten ulaşılamıyordu

- **Tarih:** 2026-09-11
- **Belirti:** `createbarcode` başarılı döner, cevap diske yazılır, süreç ölür.
  Gönderi DHL'de vardır, cevabı elimizdedir — ve hiçbir operatör basışı yerel
  yarıyı bitiremez.
- **Kesin kök neden:** `begin_mutation()` taşıyıcıyı aramadan **önce** durumu
  `reconcile_required` yapar. Receipt yazıldıktan sonraki bir çökme siparişi
  orada bırakır. `resume_barcode()` ise yalnız `order_created` kabul ediyordu;
  dolayısıyla `run_barcode()` içindeki receipt kurtarması üretimde **hiç
  erişilemez** koddu.
- **Ölçülen kırmızı** (gerçek public resume, taze `WC_Order` + taze manager +
  taze adaptör):

  ```text
  verified_receipt   : createbarcode=0 token=0 state=reconcile_required ok=no
  no_pending_mutation: createbarcode=0 token=0 state=shipment_created    ok=yes   <-- sahiplenme
  ```

  İkinci satır ayrı bir kusuru da açığa çıkardı: sahibi belli olmayan bir
  receipt kendini benimsiyordu.
- **Uygulanan düzeltme:** `recover_from_receipt()`, `resume_barcode()` içinde
  **mutation kilidinden ve taze DB okumasından sonra, durum allow-list'inden
  önce** değerlendirilir. Kanıt eksiksiz olmadan açılmaz: sipariş provider'ı ==
  çağrılan taşıyıcı, receipt `referenceId` siparişin çivili referansıyla
  byte-aynı, `shipmentId` boş değil, açık bir pending mutation var ve o
  mutation `kind=create` / `operation=create_barcode` / `target=shipment` ile
  aynı provider+reference'ı taşıyor. Aksi hâlde `barcode_receipt_unclaimed` ile
  görünür manuel inceleme; taşıyıcıya hiçbir istek yok.

  Kurtarma yalnız **kesilmiş bir createbarcode'un bırakabileceği** iki durumda
  açılır: `order_created` ve `reconcile_required`. `cancelled`, `delivered`,
  `manual_review` ve iptal/güncelleme mutabakat durumları allow-list'e düşer ve
  orada reddedilir — yerleşmiş bir kaydı kendi başarı kanıtı manuel incelemeye
  sürükleyemez.
- **Ölçülen yeşil:**

  ```text
  SHIPPING_RECEIPT_RECOVERY_IS_REACHABLE=PASS
  |verified_receipt:createbarcode=0/token=0/state=shipment_created/labels=2/ok=yes
  |wrong_reference|empty_shipment_id|pending_is_cancel|no_pending_mutation:
     hepsi createbarcode=0/token=0/state=manual_review/ok=no
  ```

- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`recover_from_receipt`, `resume_barcode`),
  `SHIP/includes/shipping/class-shipment-order-store.php` (`RECEIPT_UNCLAIMED`)
- **Tekrar yaşanırsa ilk bak:** `_kuka_shipping_barcode_receipt` dolu ama durum
  `reconcile_required` ise kurtarma kapısı çalışmıyor demektir; `token` ve
  `createbarcode` sayacı 0 kalmalı, aksi hâlde ikinci gönderi riski vardır.

---

## K-57 — Etiket geçersizse taşıyıcının cevabı hiç yazılmıyordu

- **Tarih:** 2026-09-11
- **Belirti:** `createbarcode` `shipmentId` ile döner ama yazdırılabilir etiket
  vermezse, modül receipt'i **yazmadan** halt ediyordu. Gönderi taşıyıcıda
  vardı; yerelde numarasını ve ham cevabı tutan hiçbir kayıt yoktu. Sonraki
  süreç "hiç gönderilmedi" ile "gönderildi ama etiketi kullanılamaz"ı ayırt
  edemiyordu.
- **Kesin kök neden:** Sıra tersti — önce etiket yargısı, sonra kayıt.
- **Uygulanan düzeltme:** Sıra `shipmentId` zorunlu → kanonik receipt kurulur
  (etiket geçerliliğine **bakılmadan**) → pending mutation korunarak yazılır →
  taze `WC_Order` ile byte-aynı geri okuma → ancak bundan sonra etiket sorusu.
  İki halt şekli artık ayrı metotlar, çünkü sözleşmeleri farklı:

  | durum | yöntem | pending mutation | `created_at` |
  |---|---|---|---|
  | receipt kanıtlandı, etiket kullanılamaz | `settle_invalid_labels()` | **kapanır** | yazılmaz |
  | receipt kanıtlanamadı | `halt_barcode()` | **açık kalır** | yazılmaz |

  `halt_barcode()` ayrıca `save_carrier_evidence()` ile `shipmentId`'yi
  siparişin kendi kalıcı kanıtına yazar; fulfillment yazımı kendi başına
  başarısız olabildiği için koli takip edilebilir kalmalıdır.

  Son yerleşim de taze bir `WC_Order` ile geri okunur
  (`settlement_matches()`: durum, `shipmentId`, receipt byte-aynı, pending
  kapalı, `created_at` yalnız gerçek gönderi yerleşiminde, provider/reference
  değişmemiş). Geri okuma tutmazsa `ok:true` dönülmez ve fulfillment/poller
  adımına geçilmez.
- **Ölçülen yeşil** (gerçek manager + mock transport + `query` sabotajı):

  ```text
  invalid_labels       : createbarcode=1 code=label_response_invalid   receipt=stored intent=closed booked=0
  receipt_write_dropped: createbarcode=1 code=label_storage_unverified receipt=absent intent=open   booked=0
  settlement_dropped   : createbarcode=1 code=label_storage_unverified receipt=stored intent=closed booked=0
  üçünde de ikinci basış: createbarcode=1 (yeni istek yok)
  ```

- **Yan etki — `SHIPPING_MUTATION_OUTCOME_ATOMIC` 4'ten 5'e çıktı.** Bir
  createOrder+createbarcode artık beş ayrı geçiştir: create_order intent'i,
  sipariş onayı, create_barcode intent'i, **cevabın yazılması**, yerleşim.
  Dördüncüsü bu düzeltmenin kendisidir; 4 ile 5 arasında ölen bir süreç
  receipt'i diskte ve intent'i açık bulmak zorundadır.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-manager.php`
  (`run_barcode`, `settle_barcode`, `settle_invalid_labels`, `halt_barcode`),
  `SHIP/includes/shipping/class-shipment-order-store.php`
  (`save_barcode_receipt`, `settle_barcode_receipt`, `save_carrier_evidence`,
  `settlement_matches`)

---

## K-58 — Siparişin saati ile kolinin saati aynı alandaydı

- **Tarih:** 2026-09-11
- **Belirti:** İki aşamalı akışta createOrder ile createbarcode haftalar
  ayrı olabilir. Tek alan kullanıldığında 15 gün önce kaydedilmiş bir siparişin
  bugün oluşan kolisi, **ilk sorguda** `MAX_ELAPSED`'i aşmış sayılıyor ve zincir
  hiçbir şey okumadan vazgeçiyordu.
- **Uygulanan düzeltme:** `_kuka_shipping_order_registered_at` taşıyıcının
  **siparişi** kabul ettiği an; `_kuka_shipping_created_at` **kolinin** var
  olduğu an. İkincisi yalnız gerçek gönderi yerleşiminde, bir kez yazılır;
  retry de ikinci basış da oynatmaz. Mutabakatla benimsenen gönderide boş değer
  bir kez doldurulur, dolu değer korunur (K-43).
- **Ölçülen yeşil:**

  ```text
  after_createOrder  : created_at=0 / order_registered_at=set
  after_createbarcode: created_at=set / skew_seconds=0 / order_registered_at_unchanged=yes
  first_poll         : reschedule/still_moving
  tek alan olsaydı   : give_up/max_elapsed_reached
  retry ve ikinci basış: created_at_moved=no
  ```

- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`META_ORDER_REGISTERED_AT`, `save_order_created`, `settle_barcode_receipt`)

---

## K-59 — ZPL etiketi kişisel veridir, takip numarası değildir

- **Tarih:** 2026-09-11
- **Belirti:** `barcodes[].value` alanı alıcının adını, adresini ve telefonunu
  içeren kilobaytlarca ZPL yazdırma komutudur. Bir kimlik değildir; takip
  numarası olarak kaydedilmesi bu veriyi gösteren her ekrana taşır.
- **Uygulanan düzeltme:** Takip numarası `shipmentId`'dir — satıcının kendi
  belgesinde "Gönderi Numarası". Yapılandırmada `barcode` kaynağı **reddedilir**
  (`TRACKING_SOURCE_UNSET`). Etiketler yalnız kanonik receipt'te saklanır;
  eski `META_BARCODES` satırı yeni akışta boş kalır — parça numarası barkod
  değildir. Operatör etiketi ancak `admin-post` üzerinden, aynı nonce ailesi ve
  `manage_woocommerce` ile, parça parça indirir; uploads altında dosya yoktur,
  tahmin edilebilir URL yoktur.
- **Ölçülen yeşil:**

  ```text
  SHIPPING_LABEL_DOWNLOAD_IS_GUARDED=PASS|pieces:2|piece_1_bytes:exact|piece_2_bytes:exact
  |content_type:application/vnd.zebra.zpl; charset=utf-8|nosniff:nosniff|content_length:exact
  |disposition:attachment|missing_piece:refused(404)|wrong_action_nonce:refused
  |cross_order_nonce:refused|reverse_cross_order:refused|unauthorised:refused
  |zpl_in_notes:no|zpl_in_panel:no|zpl_files_under_uploads:0
  ```

- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-admin.php`
  (`handle_label`, `label_response`),
  `SHIP/includes/shipping/dhl/class-dhl-config.php` (`tracking_number_source`)

---

## K-60 — Fatura doğrulaması EDM'in canlı WSDL'ine bağlıydı

- **Tarih:** 2026-09-12
- **Neyin doğru olmadığını önce yazalım:** Bu suite **gerçek bir EDM
  SendInvoice çağrısı yapmıyor** ve hiç yapmadı.
  `Kuka_Island_Test_WSDL_Interceptor::__doRequest()` SOAP gönderimini yakalar ve
  mock cevap döndürür. Ölçüm adlarındaki eski `real_send` etiketi "gerçek ağ
  yazması" değil "üretim gönderim kod yolu" demekti; bu belgede bir kez yanlış
  yorumlandı ve düzeltilmiştir. Etiketler artık
  `production_send_path_intercepted` ve `pinned_wsdl_fixture_soap_encoder`.
- **Asıl kusur — okuma tarafı:** `SoapClient` constructor'ı ve
  `DOMDocument::load()` `Invoice_Config::DEFAULT_TEST_WSDL` **URL'sini** her
  koşuda uzaktan okuyordu. `make verify` böylece dış ağa ve EDM'in istediği
  zaman değiştirebileceği bir belgeye bağlıydı.
- **Üç kırmızının ölçülen kök nedeni** (ağ kapalıyken de birebir tekrar eder,
  yani ağ hiç sebep değildi):

  ```text
  SOAP-ERROR: Encoding: object has no 'OTHER_ENTEGRATION' property
  ```

  EDM test WSDL'i `INVOICE/HEADER` içinde
  `<xs:element name="OTHER_ENTEGRATION" type="xs:int" minOccurs="1"/>`
  tanımlar. ext-soap `minOccurs`'u **kodlama** anında uygular: alan
  gönderilmezse hiç zarf üretilmez. `last_request_xml` boş kaldığı için
  `INVOICE_SOAP_XPATH_SEND_INVOICE_EARCHIVE` ve `..._EINVOICE` bütün XPath'leri
  "absent" raporladı, `INVOICE_LEGAL_CARRIER_REQUIRES_10_DIGIT_VKN` aynı boş
  XML'i okudu, ve üretim istemcisinin fault sınıflandırıcısı bunu EDM'in hiç
  görmediği bir istek için **"EDM refused the request."** diye bildirdi. "EDM
  reddetti" sonucu bir yanlış teşhisti.
- **Uygulanan düzeltme, dört parça:**
  1. WSDL'in gözden geçirilmiş yerel kopyası
     `scripts/fixtures/edm/edm-efaturaedm-test.wsdl`; SHA-256 koda çivili ve ilk
     `SoapClient`'tan önce doğrulanıyor — uyuşmazlıkta koşu durur. Dosya saf
     WSDL/XSD'dir: sıfır metin düğümü, hiçbir kimlik/oturum/belge verisi yok.
  2. Fixture'ın ilan ettiği uç nokta ayrılmış TLD'ye çevrildi
     (`http://edm-offline-fixture.invalid/EFaturaEDM.svc`), böylece
     `__doRequest` geçersiz kılınmasa bile hiçbir taşıyıcı EDM'e ulaşamaz.
  3. Üretim istemcisi eksik zorunlu alanı gönderiyor:
     `'OTHER_ENTEGRATION' => 0`. Sıfır bir **anlam değil**, kodlayıcı tabanıdır:
     `xs:int`'in null'u yoktur ve bu alanı hiç atamayan resmî C# connector
     `default(int)` yani 0 serileştirir. İki `SENDDATE` alanının aksine bunun
     için **EDM'den yazılı bir cevap yok**; canlı gönderimden önce EDM desteğine
     sorulmalıdır.
  4. `INVOICE_VERIFY_NETWORK_ISOLATED` ölçümü eklendi; fixture yükleyicisi,
     WordPress HTTP katmanı ve `__doRequest` kendi işlerini sayar.
- **Ölçülen yeşil:**

  ```text
  INVOICE_VERIFY_NETWORK_ISOLATED=PASS|wsdl_source:local_file|wsdl_reads:3
  |all_reads_local_file:yes|wsdl_sha256_pinned:yes|fixture_names_an_edm_host:no
  |edm_endpoint_posts:0|edm_http_requests_attempted:0
  |production_send_path_intercepted:11|SendInvoice_through_production_client:2
  |mock_responses_used:11|soap_endpoints_offered:http://edm-offline-fixture.invalid/EFaturaEDM.svc
  ```

  Ayrıca konteyner içinde `edmbilisim.com.tr` 127.0.0.1'e çivilenerek koşuldu:
  suite birebir aynı sonuçla tamamen yeşil. Dış ağa bağımlılık yok.
- **İlgili dosya:** `scripts/verify-invoice-integration.php`,
  `scripts/fixtures/edm/edm-efaturaedm-test.wsdl`, `scripts/verify.sh`,
  `EDM/includes/invoice/class-edm-client.php`, `scripts/lib-edm-sandbox.php`
- **Asıl kayıt EDM tarafındadır:**
  [EDM_BAKIM_HAFIZASI.md K-31](EDM_BAKIM_HAFIZASI.md) — uzaktaki WSDL
  değişiminin nasıl fark edildiği, hiçbir gerçek `SendInvoice` yapılmadığının
  dört ayrı kanıtı, `0` değerinin neden yalnız int kodlayıcı tabanı olduğu,
  EDM'in bu alanı **yazılı olarak teyit etmediği** ve aynı kodlama hatası
  tekrarlarsa izlenecek altı adımlık sıra orada yazılıdır. Olay bu kargo turunda
  ortaya çıktığı için burada da duruyor; bakım sırasında o kayıt esastır.
- **Tekrar yaşanırsa ilk bak:** `SOAP-ERROR: Encoding: object has no '<AD>'
  property` görülüyorsa EDM WSDL'i yeni bir `minOccurs=1` alan eklemiştir.
  Doğru tepki beklentiyi gevşetmek değil, alanı şemanın nötr değeriyle
  göndermek ve EDM'e anlamını sormaktır — `EARCHIVE_REPORT_SENDDATE` ve
  `OTHER_ENTEGRATION` bu yolla eklendi. Ayrıntılı sıra: EDM_BAKIM_HAFIZASI.md
  K-31.

---

## K-63 — Entegrasyonu yapılandırmanın tek yolu wp-config'di

- **Tarih:** 2026-09-14
- **Belirti:** Müşteriye teslim edilebilir bir kurulum yoktu. Dört kimlik
  bilgisi, ortam seçimi ve dört anahtar yalnız `wp-config.php` sabitleriyle
  verilebiliyordu. Site sahibi bu dosyaya erişemez; dolayısıyla entegrasyon
  ya hiç yapılandırılmayacak ya da her değişiklik için geliştirici
  gerekecekti.
- **Kesin kök neden ve neden eski kural doğruydu:** Önceki sözleşme mutlaktı —
  "sır asla option'a yazılmaz". Gerekçesi hâlâ geçerli: düz bir option sitedeki
  her eklenti tarafından okunabilir ve veritabanı dökümüne girer. Ama o kural
  wp-config'i düzenleyebilen birinin varlığını varsayıyordu. Seçenek "sırları
  dikkatle saklayan bir panel" ile "hiç panel yok" arasındaydı; ikincisi daha
  güvenli değil, yalnızca kullanılmayan bir entegrasyon demekti.
- **Uygulanan düzeltme — yasak değil, ÖNCELİK:**

  ```text
  wp-config sabiti  >  ortam değişkeni  >  şifreli panel kasası
  ```

  Kasa (`Kuka_Island_Shipping_Secret_Vault`):
  - Anahtar **saklanmaz**, her okumada sitenin kendi `wp-config` salt'larından
    HKDF ile türetilir. Veritabanı dökümünde şifreli metin vardır, onu çözecek
    hiçbir şey yoktur; saldırganın ayrıca dosya sistemine ihtiyacı olur.
  - `sodium_crypto_secretbox` — **kimliği doğrulanmış** şifreleme. Elle
    düzenlenmiş bir ciphertext çözülmez; bir sonraki kod yolunun parola sanacağı
    çöp üretmez. libsodium yoksa kasa kendini kullanılamaz ilan eder ve hiçbir
    şey saklamaz; ev yapımı bir şifre, eksik özellikten kötüdür.
  - Salt döndürüldüğünde **susmaz**: anahtar parmak izi satırla birlikte durur,
    eşleşmezse `credentials_unreadable` der. Boş dönmek "hiç girilmemiş" ile
    aynı görünürdü ve operatör girdiği ayarı arardı.
  - Option satırı `autoload=no`.
  - Panel kayıtlı sırrı **hiçbir biçimde geri basmaz** — maskeli de, uzunluk da,
    `value` özniteliği olarak da. Boş bırakılan alan mevcut değeri korur; silmek
    ayrı bir form, ayrı bir nonce ve açık bir onay kutusudur.
- **Dört anahtar dört ayrı şeydir** ve panelde ayrı ayrı durur:

  | Anahtar | Kapalıyken |
  | --- | --- |
  | Modül çalışma anahtarı | Bütün kargo işlemleri durur; uçuştaki worker bile. Bu anahtar `Runtime_Gate`'in kendisidir, ikinci bir bayrak değil |
  | DHL/MNG adaptörü | İstemci hiç kurulmaz; her işlem ağdan önce `carrier_not_registered` |
  | Otomatik gönderi oluşturma | Ödenmiş siparişler kendiliğinden gönderi oluşturmaz. **Varsayılan kapalı** |
  | Otomatik durum sorgusu | Oluşmuş gönderiler kendiliğinden sorgulanmaz |

- **Otomatik oluşturma checkout isteğinin içinde çağrı yapmaz.** Ödeme kancası
  tek bir Action Scheduler işi planlar ve döner; taşıyıcı yalnız o işin içinden
  aranır. Uygunluk bir **izin listesidir**: ödeme `date_paid` ile kanıtlanmış,
  durum `processing`/`completed`, iptal/iade/başarısız değil, en az bir fiziksel
  ürün, ad/adres/şehir/telefon tam, modül+kapı+adaptör açık, yapılandırma
  eksiksiz, ve siparişte **hiçbir** taşıyıcı kaydı yok (`state:none`, pending
  mutation yok). Aklımıza gelmeyen bir koşul listede olmadığı için kapalı kalır.
- **İki faz korunuyor.** `run()` createOrder yapar, siparişi **veritabanından
  taze okur**, ve yalnız durum tam olarak `order_created` ise createbarcode'a
  geçer. Belirsiz bir yazmadan sonra otomatik ikinci yazma yoktur; çıkış yalnız
  salt-okunur mutabakattır ve kişi gerektirir.
- **Ölçülen yeşil** (39 ölçüm, `scripts/verify-shipping-admin-settings.php`):

  ```text
  SHIPPING_SETTINGS_REQUIRE_CAPABILITY=PASS|user:subscriber|outcome:refused|settings_changed:no
  SHIPPING_SETTINGS_REQUIRE_NONCE=PASS|outcome:refused|settings_changed:no
  SHIPPING_SETTINGS_SECRET_NOT_PLAINTEXT_IN_OPTIONS=PASS|plaintext_hits:none
  SHIPPING_SETTINGS_SECRET_OPTIONS_NOT_AUTOLOADED=PASS|vault:off|settings:off
  SHIPPING_SETTINGS_SECRET_NEVER_RENDERED=PASS|in_html:none|password_inputs_prefilled:no
  SHIPPING_SETTINGS_VAULT_ROUNDTRIP=PASS|sodium:yes|fields_recovered:4/4|byte_identical:yes
  SHIPPING_SETTINGS_VAULT_TAMPER_FAILS_CLOSED=PASS|code:credentials_unreadable|ready:no
  SHIPPING_SETTINGS_SALT_ROTATION_IS_UNREADABLE=PASS|operator_told_to_re_enter:yes
  SHIPPING_SETTINGS_CONSTANT_BEATS_VAULT=PASS|order:constant>environment>vault
  SHIPPING_SETTINGS_EMPTY_SECRET_KEEPS_VALUE=PASS|value_after:unchanged
  SHIPPING_SETTINGS_FORGET_SECRET_IS_GUARDED=PASS|wrong_nonce:refused|no_capability:refused|authorised:ran
  SHIPPING_AUTO_CREATE_DEFAULTS_OFF=PASS|auto_create:off|auto_poll:off
  SHIPPING_AUTO_CREATE_BOOKS_ONE_JOB=PASS|jobs:1|http_during_scheduling:0|carrier_writes:0
  SHIPPING_AUTO_CREATE_IS_IDEMPOTENT=PASS|events:3|jobs_booked:1
  SHIPPING_AUTO_CREATE_ELIGIBILITY_IS_AN_ALLOWLIST=PASS|cases:9|wrong:none
  SHIPPING_AUTO_CREATE_IS_TWO_PHASE=PASS|createOrder:1|createbarcode:1|state:shipment_created
  SHIPPING_AUTO_CREATE_PHASE_ONE_CRASH_IS_SAFE=PASS|retry:createOrder=0/writes=0
  SHIPPING_AUTO_CREATE_PHASE_TWO_UNCERTAIN_IS_SAFE=PASS|retry:createbarcode=0/writes=0
  SHIPPING_AUTO_AND_MANUAL_RACE_IS_SERIALISED=PASS|child_started:yes|child_inside_carrier_call:yes
                                                  |carrier_writes_total_across_processes:1|this_process_writes:0
  SHIPPING_AUTO_CREATE_NOTIFIES_ONCE=PASS|mails_on_creation:0|mails_on_first_dispatch_status:1
                                         |mails_after_two_more_polls:1
  SHIPPING_SETTINGS_CONNECTION_TEST_IS_READ_ONLY=PASS|token:1|cbs_cities:1|carrier_writes:0|secret_leaks:0
  SHIPPING_SETTINGS_LIVE_IS_REFUSED_WITHOUT_ENDPOINT=PASS|http_calls:0|create:refused/live_environment_blocked
  SHIPPING_DEACTIVATION_CANCELS_DISPATCH_JOBS=PASS|pending_before:1|pending_after:0
  SHIPPING_MANUAL_ROUTE_SURVIVES_EVERY_SWITCH_OFF=PASS|tracking_number:MANUAL-TRACK-1|module_claimed_it:no
  SHIPPING_SETTINGS_NO_SECRET_LEAK=PASS|surfaces:31|leaks:none
                                       |positive_control:secrets_reached_the_carrier_as_they_must
  ```

- **Yarış ölçümü gerçek iki süreçtir.** Tek süreçteki iki ardışık çağrı
  eşzamanlılık değildir: aynı bağlantıyı, dolayısıyla aynı adlandırılmış kilidi
  paylaşırlar. Ölçüm ikinci bir **PHP sürecini** başlatır, o süreç gerçek
  Manager üzerinden kilidi alır ve üç saniye süren bir taşıyıcı çağrısının
  içinde tutar; bu süreç o sırada otomatik yolu çalıştırır. İki süreç de
  yazmalarını aynı options satırına sayar, yani toplam süreçler arası bir
  sayıdır: **1**.
- **İki sözleşme bilerek değişti, gevşetilerek değil:**
  - `SHIPPING_PASSIVE_NO_AUTOMATIC_ROUTES` artık "hiç callback yok" değil,
    "`Kuka_Island_Shipping_Dispatcher` dışında callback yok" der. Onun o
    kancalarda yaptığı tek şey iş planlamaktır ve anahtarı kapalıyken onu bile
    yapmaz; korunan şeyler ayrı ve tam olarak ölçülür.
  - `SHIPPING_LIFECYCLE_ACTIVATION` aynı şekilde güncellendi ve üstüne
    `auto_create:off` alanını kazandı: aktivasyon otomatik oluşturmayı açmaz.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-secret-vault.php`,
  `class-shipment-settings.php`, `class-shipment-settings-page.php`,
  `class-shipment-dispatcher.php`; `class-shipment-admin.php`
  (`dispatch_status_line`), `class-shipment-order-store.php`
  (`META_DISPATCH_*`), `dhl/class-dhl-config.php` (öncelik),
  `class-shipment-status-poller.php` (anahtar + paylaşılan kilit),
  `includes/class-activator.php` (deaktivasyon planlanan işi de iptal eder)
- **Tekrar yaşanırsa ilk bak:**
  1. Panel "Kayıtlı kimlik bilgileri okunamıyor" diyorsa site salt'ları
     değişmiştir. Değerleri panelden yeniden girin; eski satır yeni anahtarla
     yazıldığında temizlenir.
  2. Sipariş ekranındaki ikinci durum satırı otomatik oluşturmanın neden
     çalışmadığını **koduyla** yazar (`order_status_not_shippable`,
     `nothing_to_ship`, `address_incomplete:phone`, `carrier_record_exists:...`).
     Önce o satırı okuyun; tahmin etmeyin.
  3. Aynı sipariş için iş planlanmıyorsa `retry_budget_spent` olabilir: üç
     denemeden sonra otomatik yol durur ve gönderi manuel düğmelerle oluşturulur.
  4. Bir sır sızdığından şüpheleniyorsanız `SHIPPING_SETTINGS_NO_SECRET_LEAK`
     ölçümünün **pozitif kontrolü** vardır: aynı tarama giden isteklerde sırları
     bulmak zorundadır. Kontrol ateşlenmiyorsa tarama boş yere yeşildir.

---

## K-64 — HTML `required` sunucu doğrulaması sanıldı

- **Tarih:** 2026-09-14
- **Belirti:** Kayıtlı bir kimlik bilgisini silen form bir onay kutusu taşıyordu
  ve kutuda `required` vardı. Sunucu tarafında o alan **hiç okunmuyordu**.
- **Kesin kök neden:** `required` tarayıcı kullanan bir insana gösterilen bir
  nezakettir, bir güvenlik sınırı değil. Alanı hiç göndermeyen bir POST — curl,
  bir script, tarayıcı eklentisi, ya da devre dışı bırakılmış JavaScript —
  doğrudan silme yapıyordu. Yetki ve nonce doğruydu; eksik olan, isteğin
  **niyetinin** doğrulanmasıydı.
- **Ölçülen kırmızı** (gerçek `admin-post` handler'ı):

  ```text
  no_confirmation    : ran / secret=erased
  wrong_confirmation : ran / secret=erased
  empty_confirmation : ran / secret=erased
  ```

- **Uygulanan düzeltme:** `handle_forget()` onayı sunucuda, **tam değer**
  karşılaştırmasıyla arar (`'1' === ...`). "Set mi", "truthy mi" değil: `'on'`,
  `'0'` ve `''` bir tarayıcının ya da bir script'in gönderebileceği şeylerdir ve
  hiçbiri sorulan sorunun cevabı değildir. Onay yoksa hiçbir şey silinmez ve
  `forget_not_confirmed` görünür bir cümleye dönüşür. Kutu artık gerçekten
  `value="1"` taşıyor.
- **Ölçülen yeşil:**

  ```text
  no_confirmation / wrong_confirmation / empty_confirmation : ran   / secret=kept
  wrong_nonce      / no_capability                          : refused / secret=kept
  confirmed                                                 : ran   / secret=erased
  other_three_secrets:unchanged
  ```

- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-settings-page.php`
  (`FORGET_CONFIRMATION`, `handle_forget`)
- **Tekrar yaşanırsa ilk bak:** Bir form alanının varlığına dayanan her karar
  için "bu alanı göndermeyen bir istek ne yapar?" sorusunu sorun. `required`,
  `disabled`, `readonly` ve `hidden` istemci tarafı ifadelerdir; hiçbiri sunucu
  kontrolü değildir.

---

## K-65 — Panel tanılaması ana kapıyı kendi sormuyordu

- **Tarih:** 2026-09-14
- **Belirti:** İnceleme, ayar sayfasındaki "Bağlantıyı test et" düğmesinin
  `readiness()` ve taşıyıcı üzerinden gittiğini, ana çalışma kapısını
  danışmadığını bildirdi.
- **Ölçülen sonuç — kusur bu biçimde ÜREMEDİ.** Ana kapı kapalı, adaptör açık,
  dört kimlik tam ve ortam sandbox iken gerçek `run_connection_test()` yolu:

  ```text
  identity_calls:0 | cbs_calls:0 | total_http:0
  ok:no | code:shipping_runtime_disabled | secret_leaks:0
  ```

  Neden: garanti zaten **doğru yerde** duruyordu. Paketle gelen adaptörün
  istemcisi her isteğin önünde `preflight()` çalıştırıyor ve ilk baktığı şey
  `Runtime_Gate::is_disabled()`. Panel kapıyı sormasa da ilk ağ sınırı onu
  soruyordu.
- **Yine de yapılan:** Ölçüm sözleşme olarak bırakıldı ve panel kapıyı **kendisi**
  de soruyor. Gerekçesi garantiyi ikiye katlamak değil, onu adaptörden bağımsız
  kılmak: `kuka_island_shipping_carriers` filtresiyle eklenen ikinci bir taşıyıcı
  kendi preflight'ında bu kontrolü unutabilir, ve o durumda operatörün kapattığı
  bir "tanılama" yine de dışarı çıkardı.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-settings-page.php`
  (`run_connection_test`), `SHIP/includes/shipping/dhl/class-dhl-client.php`
  (`preflight`)
- **Tekrar yaşanırsa ilk bak:** Bir kapının nerede uygulandığını **ölçmeden**
  taşımayın. "Bu kod yolu kapıyı çağırmıyor" doğru olabilir ve davranış yine de
  doğru olabilir; ikisi farklı iddialardır.

---

## K-66 — Sayaç vardı, retry yoktu; ve retry faz-farkında olmalıydı

- **Tarih:** 2026-09-14
- **Belirti:** `MAX_ATTEMPTS = 3` ve `dispatch_attempts` metası vardı, belgeler
  "yerel/ağ öncesi red sınırlı retry alabilir" diyordu. Fakat `Dispatcher::run()`
  hiçbir başarısız sonuçtan sonra yeni bir Action Scheduler işi planlamıyordu.
  `retry_budget_spent` normal otomatik akışta **ulaşılamaz** bir koddu.
- **Kesin kök neden:** Bir sayacın var olması onun harcandığını kanıtlamaz.
  Sözleşme belgede vardı, kodda yoktu — ve belgeyi koda uydurmak (yani "retry
  yok" yazmak) gerçek boşluğu kapatır, ihtiyacı kapatmazdı: kilit çekişmesi
  yüzünden düşen bir sipariş sessizce hiç gönderilmiyordu.
- **Ölçülen kırmızı** (gerçek worker + siparişin mutasyon kilidini tutan gerçek
  ikinci MySQL oturumu):

  ```text
  a_before_create_order   : blocked_writes=0 / retry_jobs=0   <-- hiç retry planlanmadı
  b_before_create_barcode : entry_state=order_created / createbarcode_total=0
                            <-- faz iki hiç sürdürülemedi
  ```

- **Uygulanan düzeltme, dört parça:**
  1. **Tek merkezî politika.** `Dispatcher::retryable_reasons()` ilan edilmiş
     listedir (bugün yalnız `lock_contended`), `Dispatcher::retryable()` ise
     **yapısal kanıtı** arar: sipariş taze okunur, bekleyen mutation yok,
     gönderi numarası yok ve durum hâlâ o fazın başlangıç durumu. `begin_mutation()`
     niyeti ve korumalı durumu istekten **önce** yazdığı için bu bir kanıttır.
     **HTTP kodundan çıkarım yok**, dağınık string kontrolü yok.
  2. **Faz farkındalığı.** Faz durumdan seçilir: `none` → createOrder,
     `order_created` → createbarcode. Tamamlanmış bir createOrder tekrarlanmaz.
  3. **Gönderilen faz işaretlenir.** `_kuka_shipping_dispatch_phases`, çağrıdan
     **önce** yazılır ve yalnız yapısal kanıt "gönderilmedi" dediğinde geri
     alınır. Bu gerekli: salt-okunur bir mutabakat siparişi meşru biçimde
     `order_created`'a geri koyabilir, ve yalnız duruma bakan bir worker bunu
     ikinci bir `createbarcode` için izin sayardı — ölçümde tam olarak bu oldu.
  4. **Planlama geri okunur.** `as_schedule_single_action()` bir id döndürmesi
     deponun satırı tuttuğunu söylemez; `has_pending_job()` ile doğrulanır.
     Doğrulanmazsa `retry_schedule_failed` yazılır, "retry planlandı" denmez.
- **Ölçülen yeşil** (`SHIPPING_AUTO_CREATE_RETRY_IS_REAL_AND_PHASE_AWARE`):

  ```text
  a createOrder öncesi çekişme : blocked_writes=0 retry_jobs=1 createOrder=1 createbarcode=1 state=shipment_created
  b createbarcode öncesi çekişme: entry=order_created createOrder_total=1 createbarcode_total=1 state=shipment_created
  c belirsiz createOrder       : createOrder=1 retry_jobs=0 later_writes=0 state=reconcile_required
  d belirsiz createbarcode     : createbarcode=1 retry_jobs=0 later_writes=0
  e bütçe                      : turns=4 attempts=3 last=retry_budget_spent retry_jobs=0 writes=0 max_note_repeat=1
  f planlama başarısız         : claimed_retry=no retry_jobs=0 panel_reason=retry_schedule_failed writes=0
  g anahtar kapatıldı          : gate=shipping_runtime_disabled switch=auto_create_disabled retry_jobs=0 writes=0
  ```

  Ayrıca `SHIPPING_AUTO_CREATE_PHASE_TWO_UNCERTAIN_IS_SAFE` artık
  `reason=phase_already_attempted:create_barcode` ile geçiyor.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-dispatcher.php`,
  `SHIP/includes/shipping/class-shipment-order-store.php`
  (`META_DISPATCH_PHASES`, `mark_dispatch_phase`, `unmark_dispatch_phase`)
- **Tekrar yaşanırsa ilk bak:** Bir sayaç, bir sabit ya da bir belge cümlesi
  gördüğünüzde onu **harcayan** kod yolunu arayın. Yoksa sözleşme yoktur. Ve
  "durum bu fazı kabul ediyor" bir izin değildir: o duruma hangi yoldan
  gelindiği sorulur.

---

## K-67 — Planlama kilidi ile mutasyon kilidi aynı sanıldı

- **Tarih:** 2026-09-14
- **Belirti:** `Dispatcher::maybe_schedule()` içindeki yorum "mutasyonların
  kullandığı aynı adlandırılmış kilit" diyordu. Kod
  `Status_Poller::acquire_lock()` çağırıyor, o da `kuka_ship_query_<id>`
  kullanıyor. Manager mutasyonları `kuka_ship_mutate_<id>` kullanıyor. **Aynı
  kilit değiller.**
- **Kesin kök neden:** Yorum, kodun yaptığı şeyi değil, yazarın kastettiği şeyi
  anlatıyordu. Davranış doğruydu; iddia yanlıştı — ve bu belgeye, teknik
  sözleşmeye ve GECMIS'e taşınmıştı.
- **Uygulanan düzeltme:** Davranış **değiştirilmedi**; iddia düzeltildi. İkisi
  ayrı şeyleri korur ve ayrı olmaları gerekir:

  | Kilit | Kim alır | Ne korur |
  | --- | --- | --- |
  | `kuka_ship_query_<id>` | Dispatcher ve Poller planlaması | İki isteğin ikisinin de "bekleyen iş yok" görüp iş oluşturması. Altında hiçbir şey gönderilmez |
  | `kuka_ship_mutate_<id>` | Manager, her dış mutasyonda | İki sürecin aynı sipariş hakkında taşıyıcıyla konuşması |

  Tek kilit kullanmak, planlamanın taşıyıcı çağrısını beklemesi demek olurdu.
- **Ölçüm korunuyor:** Worker'ın gerçek taşıyıcı mutasyonunda Manager'ın
  mutasyon kilidini aldığı, ikinci bir MySQL oturumu o kilidi tutarken ölçülüyor
  (`SHIPPING_AUTO_AND_MANUAL_RACE_IS_SERIALISED`,
  `SHIPPING_AUTO_CREATE_RETRY_IS_REAL_AND_PHASE_AWARE`). İki eşzamanlı ödeme
  olayının yine tek iş ürettiği `SHIPPING_AUTO_CREATE_IS_IDEMPOTENT` ile
  ölçülüyor.
- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-dispatcher.php`
  (yorumlar), `docs/DHL_ENTEGRASYONU.md` §17.2
- **Tekrar yaşanırsa ilk bak:** Bir yorum bir kilit, bir kapı ya da bir garanti
  adı veriyorsa, o adı kodda **arayın**. Yorumu doğrulamak için davranışı
  değiştirmeyin; yanlış olan yorumdur.

---

## K-68 — Faz işareti doğrulanmadan taşıyıcıya çıkılıyordu

- **Tarih:** 2026-09-14
- **Belirti:** Dispatcher `record_dispatch_attempt()` ve `mark_dispatch_phase()`
  çağırıyor, sonra doğrudan `create_shipment()` / `resume_barcode()` çağırıyordu.
  İkisi de `void` idi ve hiçbiri yazdığını geri okumuyordu.
- **Kesin kök neden:** `update_meta_data()` bir nesneyi doldurur;
  `save_meta_data()` diske yazar ve **hata vermeden başarısız olabilir**. Bu
  modül bunu iki kez öğrenmişti — mutation intent (K-29 sınıfı) ve notification
  claim (K-52) — ve ikisi de artık kendi yazmasını geri okuyor. Dispatcher'ın
  defteri tam olarak aynı ölçüde geri alınamaz: deneme sayısı retry bütçesidir,
  faz işareti ise ikinci bir otomatik taşıyıcı yazmasını durduran şeydir.
- **Ölçülen kırmızı** (WordPress'in kendi `query` filtresiyle, ilgili
  INSERT/UPDATE `SELECT 1`e çevrilerek):

  ```text
  a_start_write_dropped: statements=3 | first_write=2   <-- taşıyıcı iki kez arandı
                         attempts=0 | phases=none | reason=none
  ```

  Yani hiçbir satır diske inmemişken `createOrder` **ve** `createbarcode`
  gönderildi; sonraki worker aynı yazmayı tekrar gönderecekti.
- **Uygulanan düzeltme:** İki doğrulanmamış yazma yerine tek ve **doğrulanan**
  başlangıç: `Order_Store::begin_dispatch_phase( $order, $phase,
  $count_attempt )`.
  - deneme sayısı ve faz listesi **tek persist turunda** yazılır;
  - bütün sipariş önbellekleri düşürülüp **taze `WC_Order`** ile geri okunur;
  - beklenen sayı ve beklenen faz listesi **değer eşitliğiyle** karşılaştırılır;
  - uymazsa `dispatch_intent_unverified` döner ve taşıyıcıya hiçbir şey gitmez.
- **Güvencenin ne OLMADIĞI — bu kaydın en kolay yanlış okunan yeri.** Tek
  persist turu **atomik değildir**: `save_meta_data()` birden çok SQL ifadesi
  çıkarabilir, dolayısıyla iki değer **yarım inebilir**. İddia "bölünmez yazma"
  değil, şudur: *iki değer tek persist turunda denenir, sonra taze bir
  veritabanı okuması ikisinin de beklenen değerde olduğunu kanıtlamak
  zorundadır*. Tam kayıp ve — her iki yönde — yarım kayıp aynı kapıda,
  taşıyıcıya çıkmadan kapanır. Bu yüzden ölçüm üç ayrı sabotaj kurar: kaydın
  tamamının düşürülmesi (`a_start_write_dropped`), yalnız faz işaretinin
  düşürülüp sayacın inmesi (`d_attempt_landed_phase_dropped`) ve yalnız sayacın
  düşürülüp faz işaretinin inmesi (`e_phase_landed_attempt_dropped`). Üçünde de
  beklenen: taşıyıcı yazması `0`, retry `0`, neden `dispatch_intent_unverified`.
  Beklenen çıktının tamamı `scripts/verify.sh` içinde satır satır sabitlidir.

  Deneme **tur başına** sayılır, faz başına değil: aynı turun ikinci fazı
  `$count_attempt = false` ile açılır, yoksa tek başarılı bir koşu üç turluk
  bütçenin ikisini yerdi.
- **Ölçülen yeşil:**

  ```text
  a_start_write_dropped: statements=2 | first_write=0 | second_write=0
                         retry_jobs=0 | attempts=0 | phases=none
                         reason=dispatch_intent_unverified | panel=dispatch_intent_unverified
  ```

- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`begin_dispatch_phase`, `DISPATCH_INTENT_UNVERIFIED`),
  `SHIP/includes/shipping/class-shipment-dispatcher.php` (`run_locked`)
- **Tekrar yaşanırsa ilk bak:** Bir dış çağrıdan önce yazılan her satır için
  "bu satırın diske indiğini nereden biliyorum?" sorusunu sorun. `void` dönen
  bir kaydedici, ağ kapısının önünde durmamalıdır.

---

## K-69 — Faz işaretini kaldırmak da doğrulanmalıydı

- **Tarih:** 2026-09-14
- **Belirti:** `unmark_dispatch_phase()` `void` idi. Yalnız isteğin hiç
  gönderilmediği yapısal olarak kanıtlandığında çağrılıyordu — ama kaldırmanın
  kendisi diske inmiş miydi, kimse bakmıyordu.
- **Kesin kök neden:** Kaldırma inmezse işaret diskte kalır. O inanışla
  planlanan bir retry, "bu faz zaten gönderildi" diyen bir işaretle karşılaşır:
  yalnız kendini reddedebilen bir tur. Panelde "retry planlandı" yazarken
  gerçekte hiçbir şey ilerlemez.
- **Uygulanan düzeltme:** `Order_Store::clear_dispatch_phase()` kaldırmayı yazar
  ve **taze `WC_Order`** ile geri okur. Doğrulanamazsa
  `dispatch_phase_clear_unverified` döner, **retry planlanmaz**, bekleyen action
  0 kalır ve neden panelde görünür.
- **Ölçülen yeşil** (yalnız kaldırma yazması sabote edildi; açılış yazması
  serbest bırakıldı, Manager gerçek bir ikinci MySQL oturumu yüzünden
  `lock_contended` verdi ve hiçbir şey göndermedi):

  ```text
  b_phase_clear_dropped: first_write=0 | second_write=0 | retry_jobs=0
                         attempts=1 | phases=create_order | claimed_retry=no
                         reason=dispatch_phase_clear_unverified
  ```

- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-order-store.php`
  (`clear_dispatch_phase`, `DISPATCH_CLEAR_UNVERIFIED`)
- **Tekrar yaşanırsa ilk bak:** Bir güvenlik işaretini **kaldıran** kod, onu
  koyan kod kadar dikkatli olmalıdır. Koyma doğrulanıp kaldırma doğrulanmazsa
  sistem yalnız bir yönde güvenlidir.

---

## K-70 — İki otomatik worker ortak faz işaretini birbirine siliyordu

- **Tarih:** 2026-09-14
- **Belirti — tehlikeli sıra:**

  ```text
  worker A  fazı işaretler, Manager mutasyonuna girer
  worker B  bir an önce alınmış uygunluk cevabıyla aynı fazı işaretler
  worker B  Manager'dan lock_contended alır
  worker B  hiçbir şey göndermediğine inanarak ORTAK faz işaretini kaldırır
  worker A  yazması belirsiz kalır -- ve ikinci otomatik denemeyi durduracak
            işaret artık yoktur
  ```

- **Kesin kök neden:** Mevcut iki kilidin ikisi de bunu kapatamaz. **Planlama
  kilidi** (`kuka_ship_query_<id>`) iş çalışmadan çok önce bırakılır;
  **mutasyon kilidi** (`kuka_ship_mutate_<id>`) Manager'ın *içinde* alınır, yani
  iki worker da kendi defterini çoktan yazmış olur.
- **Uygulanan düzeltme:** Üçüncü ve ayrı bir kilit —
  **`kuka_ship_dispatch_<id>`**, worker yürütme kilidi. Kurallar:
  - taze sipariş okumasından **ve** uygunluk kararından **önce** alınır;
  - `begin_dispatch_phase` → Manager çağrısı → yerleşim boyunca tutulur;
  - **bekleme süresi 0**: alamayan ikinci worker kuyruğa girmez, çıkar;
  - çıkan worker hiçbir faz işaretini, deneme sayısını veya sipariş metasını
    değiştirmez, taşıyıcıya çıkmaz, ilk worker'ın işaretini silemez;
  - her çıkışta `finally` ile bırakılır.
- **Ölçülen yeşil** (gerçek ikinci PHP süreci; çocuk süreç `createOrder`
  çağrısının içinde dört saniye tutuluyor, bu süreç o sırada aynı sipariş ve
  faz için kendi worker'ını çalıştırıyor):

  ```text
  createOrder_across_processes:1 | createbarcode_across_processes:1
  loser_writes:0 | loser_outcome:refused/dispatch_in_progress
  attempts_total:1 | phase_record:create_order+create_barcode
  retry_jobs:0 | dispatch_lock_free_after:yes
  ```

- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-dispatcher.php`
  (`EXECUTION_LOCK_PREFIX`, `acquire_execution_lock`, `run`, `run_locked`)
- **Tekrar yaşanırsa ilk bak:** "Bu zaten kilitli" demeden önce **hangi** kilit
  olduğunu ve **ne zaman** tutulduğunu sorun. Bir kilit, korumak istediğiniz
  bütün diziyi kapsamıyorsa o diziyi korumaz.

---

## K-71 — Üç kilit vardı, yorum ikisini aynı sanıyordu

- **Tarih:** 2026-09-14
- **Belirti:** K-67'de düzeltildiği raporlanan iddia üretim dosyasında **hâlâ
  duruyordu**: sınıf yorumunda "the same named lock the mutations use", ve
  `maybe_schedule()` içinde "The same named lock the mutations take". Araya
  giren bir yeniden yazma düzeltmeyi geri almıştı.
- **Kesin kök neden:** Bir düzeltmenin uygulandığını rapor etmek, uygulandığını
  kanıtlamaz. İki yorum da kaynakta aranmadan "düzeldi" sayılmıştı.
- **Uygulanan düzeltme:** Üç kilit, üç amaç, kaynakta ve belgede birebir aynı
  cümlelerle:

  | Kilit | Kim alır | Ne korur |
  | --- | --- | --- |
  | `kuka_ship_query_<id>` | Dispatcher ve Poller **planlaması** | İki isteğin ikisinin de "bekleyen iş yok" görüp iş oluşturması. Altında hiçbir şey gönderilmez; iş çalışmadan önce bırakılır |
  | `kuka_ship_dispatch_<id>` | Dispatcher **worker yürütmesi** | Bir siparişte aynı anda tek worker turu. Taze okuma → uygunluk → defter → Manager → yerleşim boyunca tutulur, bekleme 0 |
  | `kuka_ship_mutate_<id>` | Manager, her **dış mutasyonda** | İki sürecin aynı sipariş hakkında taşıyıcıyla konuşması. Operatörün düğmesi de aynısını alır |

- **İlgili dosya:** `SHIP/includes/shipping/class-shipment-dispatcher.php`,
  `docs/DHL_ENTEGRASYONU.md` §17.2, `GECMIS.md` §15.8
- **Tekrar yaşanırsa ilk bak:** Bir yorum düzeltmesini `grep` ile doğrulayın.
  Aynı dosyada sonradan yapılan bir yeniden yazma, bayat metni geri getirebilir.

---

## K-72 — Doğrulama koşusunun kendi gürültüsü

- **Tarih:** 2026-09-15
- **Belirti:** Gerçek `make verify` çıktısında, beklenen `wp_safe_redirect()`
  çağrıları için WP-CLI'nin uzun "Some code is trying to do a URL redirect"
  backtrace'leri ve bir `PHP Warning: Undefined variable $atts` satırı vardı.
- **Neden önemli:** Bir doğrulama koşusunda uyarı kozmetik değildir. PHP, bir
  değerin kodun varsaydığı şey olmadığını söylüyordur — ve işi emin olmak olan
  bir dosyada. Bir koşu rutin olarak uyarı basmaya başladığında bir sonraki
  uyarı kaydırma geçmişinde kaybolur.
- **Uygulanan düzeltme, üç parça:**
  1. **Beklenen redirect susturuldu, gizlenmedi.** Test harness'ı `wp_redirect`
     filtresini `PHP_INT_MIN` önceliğiyle bağlar ve oradan bir istisna atar;
     WP-CLI'nin kendi `wp_redirect` işleyicisi (öncelik 10) hiç çalışmaz.
     Üretim kodu değişmez, filtre yalnız o çağrı boyunca vardır, ve **başka**
     bir yerdeki beklenmedik bir redirect hâlâ kendini bildirir.
  2. **Redirect hedefi ölçülüyor.** İstisnadan önce konum kaydedilir, böylece
     biten bir handler'ın operatörü **nereye** gönderdiği iddia edilebilir:
     `notice=forget_not_confirmed` ile `notice=forgotten` artık ayrı ayrı
     doğrulanıyor.
  3. **Koşunun kendi diagnostikleri sayılıyor.** `set_error_handler` bütün
     koşuyu sarar, önceki handler'ı çağırmaya devam eder (hiçbir şey
     yutulmaz) ve tek bir notice koşuyu düşürür:
     `SHIPPING_SETTINGS_RUN_IS_CLEAN`.
- **Kapı ilk koşuda gerçek bir şey yakaladı:** ilk yarış testi çocuk betiğini
  kendi üstüne kopyalıyor ve sonra **iki kez** siliyordu; ikinci `unlink()`
  `No such file or directory` uyarısı üretiyordu. Kopyalama gereksizdi (suite
  zaten konteynerin içinde çalışıyor, `sys_get_temp_dir()` onun `/tmp`'i) ve
  kaldırıldı.
- **Ölçülen yeşil:**

  ```text
  SHIPPING_SETTINGS_RUN_IS_CLEAN=PASS|diagnostics:0|distinct:0|first:none
                                      |expected_redirects_silenced_before_wp_cli:yes
  gerçek make verify çıktısı: PHP Warning/Notice/Deprecated 0, "URL redirect" 0,
                              backtrace satırı 0
  ```

- **İlgili dosya:** `scripts/verify-shipping-admin-settings.php`
  (`kuka_set_press`, `kuka_set_last_redirect`, `SHIPPING_SETTINGS_RUN_IS_CLEAN`),
  `scripts/verify.sh`
- **Tekrar yaşanırsa ilk bak:** Bir uyarıyı "beklenen" diye görmezden gelmeden
  önce, onu **susturan** mekanizmanın beklenmedik olanı hâlâ gösterdiğini
  kanıtlayın. Bir çıktı filtresi ikisini de siler; önceliği doğru seçilmiş bir
  hook yalnız hedefi susturur.

---

## K-73 — Ayar suite'i eklentinin başlangıç durumuna bağlıydı

- **Tarih:** 2026-09-15
- **Belirti:** Yerelde iki gerçek `make verify` geçerken GitHub Actions temiz
  kurulumunda `Kuka_Island_Shipping_Activator` sınıfı bulunamadığı için ayar
  suite'i fatal ile durdu.
- **Kök neden:** Yerelde eklenti aktifti ve WordPress bootstrap sınıfı zaten
  yüklemişti. Teslim durumunda eklenti pasif olduğunda çevrimdışı modül
  yükleyicisi yalnız davranış sınıflarını yüklüyor; aktivatör ise suite'in
  deaktivasyon temizliği ölçümüne gelene kadar hiç yüklenmiyordu.
- **Çözüm:** Ayar suite'i gerçek aktivatör dosyasını açıkça yükler. Böylece
  ölçüm eklentinin başlangıçta aktif veya pasif olmasına bağlı değildir;
  üretim yükleme sırası ve eklenti davranışı değişmez.
- **Tekrar yaşanırsa ilk bak:** Yerelde var, temiz CI'da yok olan bir sınıfta
  önce sınıfın gerçekten suite tarafından mı yüklendiğini, yoksa aktif eklenti
  durumundan tesadüfen mi miras kaldığını kontrol et.

---

## Bakım sırası

Bir kargo belirtisi geldiğinde izlenecek sıra:

1. **Modülün hangi anahtarı kapalı?** Sipariş ekranındaki durum satırı dördünü
   birlikte yazar: eklenti, çalışma kapısı, otomatik sorgu, kayıtlı taşıyıcı.
   Bkz. DHL_ENTEGRASYONU.md §17.
2. **Sipariş hangi taşıyıcıya ait?** `_kuka_shipping_provider`. Boşsa ve
   taşıyıcı kanıtı varsa `shipment_provider_missing` beklenir; bu bir hata
   değil, fail-closed davranıştır (K-21).
3. **Bekleyen bir yazma kanıtı var mı?** `_kuka_shipping_pending_mutation` ve
   durum. `cancel_reconciliation_required` / `update_reconciliation_required`
   ise doğru davranış **hiçbir şey göndermemektir**; yalnız salt-okunur
   mutabakat çalıştırılır (K-24, K-25).
4. **Ret ağa çıktı mı, çıkmadı mı?** Soru "kesin ret mi" değildir (K-31).
   `to_safe_line()` çıktısındaki `reached_carrier:no` — yani
   `Result::local_refusal()` — tek başına intent'i kapatır ve siparişi önceki
   durumuna döndürür. `reached_carrier:yes` olan her cevap, `success` ve 400
   dâhil, korumalı durumda kalır ve yalnız salt-okunur kanıtla çözülür.
5. **Ölçümler.** Önce `docker compose run --rm wp-cli wp eval-file
   /project-scripts/verify-shipping-automation.php`; sonra `make verify`.
   Sandbox'a bağlanmadan bunların hepsi mock transport üzerinden çalışır.
6. **Gerçek sandbox** yalnız ayrı ve operatör kontrollü komutlarla; bkz. bu
   dosyanın altındaki "Sandbox hazırlığı".

---

## Sandbox hazırlığı — 2026-09-04 (tarihsel anlık görüntü)

**Güncel durum 2026-09-12:** Kimlikler `4/4`, salt-okunur bağlantı PASS ve
kontrollü tam gönderi zinciri PASS. Aşağıdaki `2/4`, “yapılmadı” ve “onay
bekliyor” ifadeleri 4 Eylül'deki hazırlık turunun tarihsel kaydıdır; güncel
sonuç ve iki gerçek yanıt uyumsuzluğu K-61'dedir.

Bu bölüm **ölçülen** durumu kaydeder. Gerçek sandbox kanıtı ile mock/offline
kanıtı burada kasten ayrı tutulur; ikisi aynı şey değildir.

### Aşama 0 — Portal uygulaması ve ürün abonelikleri

`Kuka Island WooCommerce Sandbox` uygulaması portalda etkin durumdadır. 4 Eylül
2026'da uygulamanın abonelik tablosunda şu beş ücretsiz Default Plan ayrı ayrı
doğrulandı: Identity 1.0.1, CBS Info 1.0.0, Standard Command 1.0.0, Barcode
Command 1.0.0 ve Standard Query 1.0.0.

Portal uygulama anahtarı/güvenlik dizgisi üretir; Identity isteğinin ayrıca
beklediği test `customerNumber` ve `password` değerlerini göstermiyor. Identity
ürün sayfasında başka geliştiricilerin de aynı iki test bilgisini talep ettiği
görüldü. Portal destek formundan, uygulama ve kuruluş adıyla bu iki değer ve
`Authorization` başlığı biçimi soruldu; portal `Mesajınız gönderildi.` sonucunu
verdi. Kimlik değeri, API anahtarı veya gizli dizi mesaja ve bu belgeye
yazılmadı.

OpenAPI'deki `GenerateTokenRequest.example` içinde bir müşteri numarası/parola
çifti vardır; bu değerlerin çalışan ortak sandbox hesabı olduğu **yazmaz**.
Gerçek kimlik dosyasını değiştirmeyen, geçici mod-600 kopyayla salt-okunur
Identity çağrısında bu örnek çift denendi ve sunucu `401 unauthorized` verdi.
CBS çağrısı oturum oluşmadığı için çalışmadı; yazma operasyonu yoktu. Geçici
dizin koşu sonunda silindi, gerçek dosya yine `present:2/4` kaldı. Dolayısıyla
bu örnekler yapılandırmaya alınmaz ve destekten gelecek hesaba özgü çift
beklenir.

### Aşama 1 — Kimlik dosyası: yalnız varlık

`~/.config/kuka-island/dhl-sandbox.env`, mod `600`, repo dışında. Dosyanın
**içeriği okunmadı, raporlanmadı, kopyalanmadı**; yalnız hangi anahtarların var
olduğu sayıldı.

```
DHL_SANDBOX_CREDENTIALS=INCOMPLETE|reason:credentials_incomplete|present:2/4|missing:KUKA_DHL_CUSTOMER_NUMBER,KUKA_DHL_PASSWORD
```

İki anahtar **eksik**: `KUKA_DHL_CUSTOMER_NUMBER` ve `KUKA_DHL_PASSWORD` —
yani kargo hesabı çifti. API ağ geçidi çifti (`KUKA_DHL_CLIENT_ID`,
`KUKA_DHL_CLIENT_SECRET`) mevcut. Eksik değerler yalnız operatör tarafından,
hiçbir şeyi ekrana yazmayan `./scripts/dhl-test-credentials.sh` ile eklenir.

### Aşama 2 — Salt-okunur bağlantı testi: planlanan

Komut: `./scripts/dhl-test-run.sh test-dhl-sandbox.php`

Yapacağı **tam** dış çağrılar:

| # | Metot | Yol | Yazma? |
| --- | --- | --- | --- |
| 1 | POST | `/mngapi/api/token` | hayır |
| 2 | GET | `/mngapi/api/cbsinfoapi/getcities` | hayır |
| 3 | GET | `/mngapi/api/cbsinfoapi/getdistricts/{cityCode}` | hayır |

Host tek: `testapi.mngkargo.com.tr`. Kaynak denetimi: betik `Manager`'ı ve
`Order_Mapper`'ı **hiç kurmaz** ve altı yazma metodundan hiçbirini çağırmaz —
yazma yolu yapısal olarak yoktur, sözleşme gereği değil. Yerel etki: il/ilçe
listesi üretim namespace'i transient'lerine yazılır ve betik sonunda
`purge_cache()` ile silinir.

### Aşama 3 — Salt-okunur test: ÇALIŞTIRILDI, ağa çıkmadı

```
DHL_TEST_RUN=STARTING|script:test-dhl-sandbox.php|allow_listed:yes|credentials:mounted_read_only|mode:600|writes:none
DHL_SANDBOX_CONNECTION=BLOCKED|reason:credentials_incomplete|external_calls:0
```

Betik kimlik kapısında durdu. **Dış çağrı sayısı 0.** Kimlik doğrulama
denenmedi, CBS listesi istenmedi, önbelleğe hiçbir satır yazılmadı
(`cbs_rows` öncesi 0, sonrası 0).

Bu, testin başarısız olduğu anlamına gelmez; **çalıştırılamadığı** anlamına
gelir. Fail-closed davranış doğru çalıştı: eksik kimlikle hiçbir ağ isteği
yapılmadı.

### Aşama 4 — Yazma çağrıları: yapılmadı

`createOrder`, `createbarcode`, `updateorder`, `updateshipment`, `cancelorder`,
`cancelshipment` — hiçbiri çalıştırılmadı. Bu turda sandbox gönderisi
oluşturulmadı.

### Aşama 5 — Tek sandbox gönderisi: komut ve etkileri (ONAY BEKLİYOR)

**Bu komut açık kullanıcı onayı olmadan çalıştırılmaz.** Önce Aşama 1'deki iki
eksik anahtar eklenmeli ve Aşama 3 gerçekten `PASS` vermelidir.

```
./scripts/dhl-sandbox-run.sh --order=<WooCommerce sipariş id> --confirm=TEK-SANDBOX-GONDERISI-ONAYLIYORUM
```

Onay ifadesi tam olarak yazılmalıdır; eksik ya da yanlış ifadeyle araç
`DHL_SANDBOX_RUN=BLOCKED|reason:confirmation_phrase_missing_or_wrong|external_calls:0`
verir ve hiçbir çağrı yapmaz. Sipariş id'si sayısal olmalıdır.

**Beklenen dış etkiler, sırayla:**

1. `POST /token` — oturum alınır (JWT diske/DB'ye yazılmaz).
2. CBS il/ilçe listeleri okunur (adres kodlarına çevirmek için).
3. `POST /createOrder` — **taşıyıcıda gerçek bir sipariş kaydı oluşur.**
4. `POST /createbarcode` — **taşıyıcıda gerçek bir gönderi ve barkod oluşur.**
   WooCommerce'te fulfillment kaydı `unfulfilled` olarak açılır.
5. `GET /getshipmentstatus/{ref}` — durum bir kez okunur.
6. `PUT /cancelshipment` — gönderi iptal edilir.
7. `GET /getshipment/{ref}` — iptal salt-okunur sorguyla doğrulanır.

**Geri alma / iptal zinciri.** Adım 6–7 aracın kendi dizisinin parçasıdır:
oluşturup iptal etmemek, kimsenin takip etmediği bir kargo bırakmak olurdu.
Adım 7 `not_found` derse sipariş `cancelled` olur ve zincir kapanır. Adım 7
kaydı **hâlâ mevcut** bulursa ya da cevap veremezse sipariş
`cancel_reconciliation_required` durumunda kalır (K-24) ve **hiçbir ikinci
iptal gönderilmez**; operatör yalnız salt-okunur mutabakat sorgusunu
tekrarlar. Adım 3 veya 4 `uncertain` dönerse sipariş `reconcile_required`
durumunda kalır ve yeniden gönderim **yapılmaz** (K-10).

**Elle geri alma gerekirse** taşıyıcı panelinden iptal edilir; bu modül
`cancel_reconciliation_required` durumundan yalnız okuma ile çıkar, yani panel
tarafındaki iptal bir sonraki mutabakat sorgusunda `not_found` olarak görünür ve
sipariş kendiliğinden `cancelled` olur.

### Açık ölçümler bu turda kapanmadı

`Ö-01`…`Ö-05` **ölçülmedi ve ölçülemedi**: hepsi gerçek bir sandbox
bağlantısına bağlıdır ve bağlantı Aşama 3'te kimlik kapısında durdu.

| Madde | Neden hâlâ açık |
| --- | --- |
| Ö-01 `Authorization` başlığı | Bir Query çağrısı yapılamadı |
| Ö-02 CBS token gereksinimi | CBS çağrısı yapılamadı |
| Ö-03 Takip numarası kaynağı | Gönderi oluşturulmadı; K-25 ile cevabın **nereden** sorulduğu değişti, cevap değişmedi |
| Ö-04 `recipient.customerId` | Yük gönderilmedi |
| Ö-05 Canlı uçlar | Canlı ortam hâlâ bloke |

### Bu turda kanıtın türü

| Kanıt | Türü |
| --- | --- |
| K-24…K-35 davranış ölçümleri | **mock transport / sahte adaptör**, ağ yok |
| `SHIPPING_*` 93 davranış ölçümü + `verify.sh`'te sabitlenen 114 satır, iki ardışık tur | **offline**, mock transport |
| Kalıcı intent ve çökme sınırı (K-29) | **gerçek ikinci MySQL oturumu** + yazmanın içinde `Throwable` |
| `DHL_OPENAPI_CONTRACT` | **offline**, satıcının dosyalarının SHA-256'sı |
| `DHL_RUNNER_OFFLINE` | **offline**, süreç başlatılmadığı kanıtlanarak |
| Kimlik `present:2/4` | **gerçek dosya**, yalnız varlık; içerik okunmadı |
| Sandbox bağlantısı | **yapılmadı** — dış çağrı 0 |
| Sandbox gönderisi | **yapılmadı** |

### `make verify` bu ortamda nerede duruyor

K-39'dan sonra standart `make verify`, EDM pasif ve kargo eklentisi aktif teslim
durumunda scratchpad, `|| true` veya çıktı filtresi olmadan çalışır. 3 Eylül
2026'daki iki geliştirme koşusu ve bir bağımsız kontrol koşusu `exit 0`,
`VERIFY=PASS` verdi; bağımsız koşuda `SHIPPING_VERIFY=PASS`, EDM pasif yaşam
döngüsü PASS ve öncesi/sonrası veritabanı keyset'i aynıydı.

EDM testleri üretimdeki kapıyı gevşetmez: test seam'i yalnız mock gönderim
önkoşulunu açıkça enjekte eder; üretim kurulumlarının tamamı varsayılan gerçek
`Runtime_Gate` kullanır. Test, çalışma kapısı option satırının varlık, değer ve
autoload durumunu başlangıçtaki baytlarla geri yükler.

### Modülün şu andaki teslim durumu

- WordPress eklentisi: **etkin** (`kuka-island-shipping-automation,active`).
  Beşinci turda kullanıcının açık kararıyla etkinleştirildi. Etkinlik tek başına
  hiçbir şey göndermez: kimlikler eksik olduğu için her çalışma zamanı yazması
  `credentials_missing` ile ağdan önce reddedilir.
- Etkinleştirmenin ölçülen sonucu (K-36): üç pasif ölçüm **cevaplanamaz** hâle
  gelir ve `SKIPPED|reason:plugin_active` raporlar. Bunları FAIL bırakmak
  `set -e` yüzünden **bütün kargo doğrulamasını** devre dışı bırakıyordu; artık
  yerlerine her iki durumda sorulabilen iki ölçüm var
  (`SHIPPING_PASSIVE_DELIVERY_ARTEFACT`,
  `SHIPPING_PASSIVE_NO_AUTOMATIC_ROUTES`) ve garantinin kendisi
  `SHIPPING_LIFECYCLE_DEACTIVATION=PASS|classes_declared:none|hooks_registered:none`
  ile gerçek bir deaktivasyondan sonra taze süreçte ölçülüyor. Teslim
  durumuna dönüş tek komuttur:
  `wp plugin deactivate kuka-island-shipping-automation`; o durumda üç ölçüm
  yine PASS zorunludur.
- Davranış suite'i etkin eklentiyle **yeşil** çalışır: canlı poller ile harness
  poller'ının çakışması `kuka_ship_attach_sole_poller()` ile kaldırıldı (iki
  worker aynı action'ı işlediği için sayılar birer fazla çıkıyordu), ve
  "yüklemek kayıt yapmaz" ölçümü delta biçimine çevrildi.
- Çalışma kapısı: **açık** (aktivasyon açtı).
- Otomatik durum sorgusu: **kapalı** (`KUKA_SHIPPING_AUTOMATION` tanımsız).
- Adaptör: **açık** (`KUKA_DHL_ADAPTER` tanımsız → `unset_default_on`);
  tanınmayan bir değer verilirse `configuration_invalid` ile **kapanır** (K-33).
- Aktiflik tek başına kargo oluşturmaz; gönderi yalnız operatörün açık
  basışıyla oluşur.

## K-61 — Gerçek sandbox sorguları tek elemanlı liste döndürdü

- **Tarih:** 2026-09-12
- **Belirti:** Gerçek `createOrder` ve `createbarcode` HTTP 200 verirken
  `getorder` ile `getshipmentstatus` HTTP 200 gövdeleri
  `malformed_response` sayılıyordu. İlk tur barkod kapısından önce, ikinci tur
  durum sorgusunda durdu.
- **Kök neden:** OpenAPI sorgu cevabını nesne olarak tarif ediyor; sandbox aynı
  nesneyi tek elemanlı JSON listesi içinde döndürüyor. Ölçülen alan şekilleri:
  `getorder => list(1):object(order+orderPieceList+recipient+shipper)` ve
  `getshipmentstatus => list(1):object(...+shipmentStatusCode+trackingUrl)`.
  Yanıt değerleri ve kişisel veriler raporlanmadı.
- **Çözüm:** `DHL_Client::get_order()` ve `get_shipment_status()` ayrıştırıcıları
  oluşturma cevaplarında zaten kullanılan `unwrap()` sınırını kullanıyor;
  belgelenmiş çıplak nesne biçimi de çalışmaya devam ediyor. Sandbox aracı
  güncel iki aşamalı üretim akışını uygular: `createOrder`, salt-okunur
  `getorder`, ayrı `resume_barcode`, durum sorgusu, iptal ve iptali okuma ile
  doğrulama. Daha önce tamamlanmış `order_created` kaydında `createOrder`
  tekrarlanmaz; `cancelled` kayıtta yalnız teşhis okuması yapılır.
- **Gerçek kanıt:** Kimlik `4/4`; Identity HTTP 200; token taşımayan CBS şehir
  ve ilçe okumaları HTTP 200. Üç kontrollü test kaydının her birinde bir
  `createOrder`, bir `createbarcode` ve doğrulanmış bir `cancelshipment` oldu.
  Son turda `getorder`, `createbarcode`, `getshipmentstatus` ve iptal zinciri
  bütünüyle PASS; durum `1 / in_progress`; her üç yerel kayıt `cancelled`,
  pending mutation yok, receipt'te bir ZPL etiket var ve taşıyıcıda açık kayıt
  kalmadı. Canlı uç kullanılmadı.
- **Takip numarası:** gerçek `trackingUrl` HTTPS ve taşıyıcı alan adında; URL
  `shipmentId` değerini birebir içeriyor. `barcodes[].value` takip numarası
  değil, kişisel veri taşıyan ZPL baskı içeriğidir.
- **Kapanan ölçümler:** Ö-01 `Bearer`, Ö-02 CBS tokensız, Ö-03 `shipmentId`,
  Ö-04 `recipient.customerId` atlanabilir. Ö-05 canlı uçlar açık kalır ve canlı
  ortam blokesi kaldırılmaz.
- **Tekrar yaşanırsa ilk bak:** HTTP 200 ile `malformed_response` birlikteyse
  önce yalnız JSON alan **şeklini** ölç; gövdeyi loglama. Sonra
  `DHL_Client::unwrap()` ve `SHIPPING_QUERY_LIST_ENVELOPES` ölçümüne bak.

## K-62 — Doğrulama gerçek CBS önbelleğini kalıntı saymamalı

- **Tarih:** 2026-09-12
- **Belirti:** Gerçek salt-okunur CBS çağrısından sonra ilk `make verify`,
  `SHIPPING_CBS_CACHE_PRESERVED` ve `SHIPPING_FIXTURES_REMOVED` ölçümlerinde
  düştü. Entegrasyon çağrıları değil, testin temizlik varsayımı hatalıydı.
- **Kök neden:** Kontrol senaryosu üretimin sabit `v1` şehir ve ilçe transient
  adlarını kullanıyor, sonra bu adları kendi fixture'ı kabul edip siliyordu.
  Son kapı da başlangıç durumunu kaydetmeden toplam CBS satır sayısının sıfır
  olmasını istiyordu. Gerçek bir okumanın bıraktığı geçerli TTL önbelleği bu
  varsayımı bozuyordu.
- **Çözüm:** Kontrol transient'leri her koşuda benzersiz bir `testrun-*`
  namespace'ine taşındı. Suite başlamadan önce bütün CBS option satırları ham
  değer ve `autoload` bayrağıyla kaydediliyor; yalnız suite'in açıkça sahiplendiği
  adlar silindikten sonra son kümenin başlangıç kümesine birebir eşit olması
  zorunlu. `cache_rows_left:0` artık bir başarı koşulu değil;
  `cache_rows_restored:yes` başarı koşuludur.
- **Kanıt:** Suite dışından iki satırlık geçici bir CBS kontrol transient'i
  oluşturuldu. Davranış suite'i `cache_rows_restored:yes|cache_rows_left:2`
  ile geçti; kontrol transient'i sonrasında tam adıyla kaldırıldı. Ardından
  standart `make verify` de geçti.
- **Tekrar yaşanırsa ilk bak:** Bir temizlik testi “satır sayısı sıfır” diyorsa
  önce bu satırların gerçekten teste mi ait olduğunu sor. Önbellek için
  sahiplik yalnız benzersiz namespace ve açık exact-name listesiyle kurulur;
  üretim namespace'ine test verisi yazılmaz ve `LIKE` ile silme yapılmaz.
