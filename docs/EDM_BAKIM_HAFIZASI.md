# EDM Bakım Hafızası

Bu dosya **bakım kaydıdır**, teknik sözleşme değil. Sözleşme:
[EDM_ENTEGRASYONU.md](EDM_ENTEGRASYONU.md). Etkinleştirme:
[EDM_AKTIVASYON_REHBERI.md](EDM_AKTIVASYON_REHBERI.md).

Amaç tek: bir belirti tekrar ortaya çıktığında **nereye bakılacağını** saniyeler
içinde bulmak. Kök neden kayıtları belirti, neden, düzeltme, kanıt, ilgili
dosyalar ve “ilk bakılacak yer” bilgilerini taşır; durum kayıtları aynı yapıyı
yalnız konuya uyan alanlarla kullanır. Tümü `Ctrl+F` ile belirti üzerinden
aranacak şekilde yazılmıştır.

**Bu dosyaya asla yazılmaz:** kullanıcı adı, parola, secret key, session ID, tam
belge UUID'si, tam fatura numarası, SOAP gövdesi.

Modül yolu: `wp-content/plugins/kuka-island-edm/`. Kısaltma olarak aşağıda
`EDM/` kullanılır.

---

## Kısa kronoloji — nereden nereye geldik

Bu özet, ayrıntılı kayıtların yerine geçmez; bakım yapan kişinin doğru kayda
hızla gitmesi içindir.

| Tarih | Ne oldu | Sonuç / ilgili kayıt |
| --- | --- | --- |
| 2026-08-30 | EDM'in e-postadaki eski servis yolu ile çalışan gerçek test WSDL'i birbirine karıştı | Kanonik `EFaturaEDM21ea` yolu ölçülüp allow-list'e alındı (K-01) |
| 2026-08-30–31 | Login ve SOAP istekleri önce eksik/sapmış sözleşmelerle kuruldu | Login mali readiness'ten ayrıldı; sekiz alanlı ortak `REQUEST_HEADER` yazıldı (K-02, K-03) |
| 2026-08-31 | Yanıt parser'ı boş durumu başarı sayıyor, poller gerçek akışa bağlanmıyor ve send kuyruğu sorguyu sahipleniyordu | Durumlar birebir sözlükle okunur hâle geldi; sorgu ve gönderim kuyrukları ayrıldı (K-08, K-09, K-20, K-21, K-22) |
| 2026-09-01 | Numara, bireysel alıcı, e-posta/alias ve başarısız belge kurtarma kuralları kesin değildi | EDM yazılı cevapları ve davranış testleriyle sentinel, `11111111111` + gerçek ad/soyad, e-Arşiv adresleme ve yeni-belge kurtarma sözleşmeleri kuruldu (K-04, K-06, K-07, K-12) |
| 2026-09-02 | İnternet satış/kargo bilgileri hazırdı fakat gönderime bağlı değildi; tarih timezone'u ve taşıyıcı kimliği belirsizdi | Tam fulfillment kapısı, UTC→mağaza günü dönüşümü ve 10 haneli tüzel taşıyıcı VKN kuralı kuruldu (K-14) |
| 2026-09-02 | Tek `LoadInvoice` deneyi yapıldı | Taslak başarıyla yüklendi ve EDM numara atadı; bunun fatura kesmek olmadığı kayda geçirildi (K-15) |
| 2026-09-02 | İlk gerçek `SendInvoice` genel bir ret verdi; özgün zarf güvenlik gereği diskte tutulmamıştı | Belirsiz kayıt kilitlendi; salt-okunur mutabakat yapıldı; redakte destek paketi üretme sınırları yazıldı (K-11, K-23) |
| 2026-09-03 | EDM teknik destek, reddin TCKN'li alıcıdaki `cac:Person` element sırasından geldiğini bildirdi | `Person`, `Contact` sonrasına taşındı (K-05) |
| 2026-09-03 | Düzeltilmiş tek `SendInvoice` EDM tarafından kabul edildi ve numaralandı | Son ölçülen durum `PACKAGE - PROCESSING`; belge var fakat terminal başarı henüz görülmedi (K-16, K-17) |
| 2026-09-03 | Entegrasyon Core'dan ayrıldı | `kuka-island-edm` ayrı, varsayılan pasif eklenti oldu; manuel süreç korunuyor (K-18, K-19, K-24) |
| 2026-09-03 | EDM pasif teslim durumunda gerçek `make verify` exit 2; 21 mock ölçümü kapalı çalışma kapısında düşüyordu | `Invoice_Manager`'a varsayılanı gerçek kapı olan enjekte edilebilir kapı; kapının kendi testi varsayılanı kullanır (K-28) |
| 2026-09-03 | Lifecycle suite'i başlangıç durumunun "hiç deaktive edilmemiş kurulum" olmasını dayatıyordu | Başlangıç durumu kaydediliyor, dayatılmıyor; geri yükleme kontrolü kapı **değerini** de karşılaştırıyor (K-29) |
| Tüm süreç | Sandbox yazma araçlarında “bir daha deneyelim” ve test PASS'ini gerçek EDM sonucu sanma riski tekrarlandı | Kalıcı claim/reset sözleşmesi, byte-katı endpoint/kimlik girişi ve üç ayrı kanıt düzeyi yazıldı (K-25, K-26, K-27) |


---

## K-28 — Pasif teslimde gerçek `make verify` çalışmıyordu

- **Tarih:** 2026-09-03
- **Belirti:** Teslim durumunda (EDM pasif) gerçek `make verify` **exit 2**.
  `verify-invoice-integration.php` içindeki mock tabanlı **21** ölçüm
  `edm_runtime_disabled` ile düşüyor, betik sıfırdan farklı dönüyor ve
  `verify.sh` `set -eu` ile çalıştığı için sonraki bütün bloklar (kargo dâhil)
  hiç koşmuyor.
- **Kesin kök neden:** Deaktivasyon kalıcı çalışma kapısı option'ını yazar ve
  `Invoice_Manager::process_order()` bu kapıyı gönderimden **hemen önce** okur;
  kapının varlık sebebi tam olarak budur (K-19). Teslim durumunda kapı doğru
  şekilde kapalıdır, dolayısıyla ölçümler mock transport'a hiç ulaşamıyordu.
  Ölçümler yanlış değildi — **ön koşulları söylenmemişti**.
- **Uygulanan düzeltme:** `class-invoice-runtime-gate.php` içinde
  `Kuka_Island_Core_Invoice_Transmission_Gate` arayüzü tanımlandı (gerçek kapının
  yanında, çünkü sözleşme ve kanonik uygulama tek kavramdır ve dosyanın her
  `require_once`'u ikisini birlikte getirir). `Invoice_Manager` üçüncü, isteğe
  bağlı bir kapı argümanı aldı; **varsayılan gerçek kapıdır** ve bütün üretim
  çağrı siteleri varsayılanı kullanır. Offline ölçümler ön koşullarını tek bir
  görünür yerde belirtir: `kuka_invoice_test_manager()` + açık test kapısı.
- **Kapının kendi testi değişmedi.** `EDM_DEACTIVATION_GATE_STOPS_INFLIGHT_SEND`
  manager'ı **argüman vermeden** kurar, yani gerçek option tabanlı kapının
  kapalı/açık davranışını ölçmeye devam eder.
- **Seam'in kendisi de ölçülüyor.** `EDM_TRANSMISSION_GATE_SEAM`: üretim
  varsayılanı gerçek kapı mı, enjekte edilen kapı gerçekten sorguluyor mu, ve
  **enjekte edilen kapalı bir kapı reddi zayıflatabiliyor mu** (hayır: aynı hata
  kodu, UUID yok, gönderim yok). Ayrıca hiçbir üretim çağrı sitesinin kapı
  geçirmediği sayılıyor: `production_sites_passing_a_gate:0`.
- **Ek koruma:** Kapının kendi testi bir kontrol gönderimi için gerçek kapıyı
  bir an açmak zorundadır — koşunun dokunduğu **tek canlı ayar**. Option satırı
  (varlık + değer + autoload) ölçümlerden önce anlık görüntüye alınır,
  `register_shutdown_function` ile **her** çıkışta (fatal ve `WP_CLI::error()`
  dâhil) birebir geri yazılır, ve eşleşmezse shutdown handler `exit(1)` ile
  koşuyu başarısız yapar: `EDM_RUNTIME_OPTION_RESTORED=PASS|byte_equivalent:yes`.
- **İlgili dosya:** `includes/invoice/class-invoice-runtime-gate.php`,
  `includes/invoice/class-invoice-manager.php`,
  `scripts/verify-invoice-integration.php`
- **Tekrar yaşanırsa ilk bak:** `EDM_TRANSMISSION_GATE_SEAM`'in
  `production_sites_passing_a_gate` değeri. 0 değilse bir üretim yolu test
  kapısıyla kurulmuş demektir ve üretim kapısı artık ölçülmüyor.

---

## K-29 — Lifecycle suite'i "hiç deaktive edilmemiş kurulum" dayatıyordu

- **Tarih:** 2026-09-03
- **Belirti:** `EDM_LIFECYCLE_START=FAIL|...|gate_option:yes`, ve tek bu satır
  yüzünden `EDM_LIFECYCLE=FAIL|failures:1` — oysa `ACTIVATION`,
  `DEACTIVATION` ve `RESTORED` üçü de PASS.
- **Kesin kök neden:** Başlangıç kontrolü eklentinin pasif **ve** çalışma kapısı
  option'ının **yok** olmasını istiyordu. Bu, hiç deaktive edilmemiş bir kurulum
  demektir. Operatörü eklentiyi devre dışı bırakmış bir sitede kapının kapalı
  olması **doğru** davranıştır (K-19): satır, doğru olan şey için başarısız
  oluyordu.
- **Uygulanan düzeltme:** Başlangıç eklenti durumu ve kapı satırı **kaydediliyor**
  (`starting_state:recorded_not_asserted`); dayatılan tek şey Core ve
  WooCommerce'in etkin olması — aşağıdaki aktivasyon/deaktivasyon turu ancak o
  zaman bu modülü ölçer. Geri yükleme kontrolü ise **sıkılaştırıldı**: artık
  kapı option'ının yalnız varlığını değil **değerini** de karşılaştırıyor
  (`gate_value_identical:yes`), çünkü kapalı bir kapıyı açık olarak geri yazmak
  bu suite'in üretebileceği en kötü artıktır.
- **Kanıt:** `EDM_LIFECYCLE=PASS`, `EDM_LIFECYCLE_RESTORED=PASS|gate_option:yes|gate_value_identical:yes|active_plugins_identical:yes`,
  ve gerçek `make verify` iki kez exit 0 — EDM pasif kalarak.
- **İlgili dosya:** `scripts/verify-edm-activation-lifecycle.sh`,
  `scripts/verify.sh`
- **Tekrar yaşanırsa ilk bak:** Bir ölçüm site **durumunu** mu yoksa
  **davranışı** mı dayatıyor. Başlangıç durumunu dayatan bir kontrol, o durumdan
  meşru şekilde çıkıldığı gün suite'i kilitler.
---

## K-01 — Yanlış test endpoint'i

- **Tarih:** 2026-08-30
- **Belirti:** `Login` çalışıyor gibi görünüyor fakat fatura operasyonları
  bulunamıyor; ya da WSDL hiç yüklenmiyor.
- **Kesin kök neden:** Adres olarak EDM'in *e-posta/portal* uçlarından biri
  kullanılmış. Doğru e-Fatura test servisi tek bir kanonik adrestir.
- **Uygulanan düzeltme:** Endpoint bir allow-list'e karşı doğrulanıyor:
  `scheme` kesinlikle `https`, host kesinlikle `test.edmbilisim.com.tr`, path
  kesinlikle `/EFaturaEDM21ea/EFaturaEDM.svc`. `userinfo`, port ve fragment
  kabul edilmiyor — `:443` dahil. Ortam etiketi tek başına hiçbir şeyi
  açmıyor: `KUKA_EDM_WSDL` bağımsız olarak başka yere işaret edebildiği için
  gerçek URL **Login'den önce** doğrulanıyor.
- **Kaynak:** EDM WSDL'in kendisi (`?singleWsdl`).
- **Kanıt:** `SANDBOX_SEND_ENDPOINT=PASS|environment:test|...|userinfo:absent|port:absent|fragment:absent`
- **İlgili dosyalar:** `scripts/lib-edm-sandbox.php`
  (`kuka_sandbox_verify_test_endpoint`), `EDM/includes/invoice/class-invoice-config.php`
- **Tekrar yaşanırsa ilk bak:** `.env` / kimlik dosyasındaki `KUKA_EDM_WSDL`
  değeri; sonra allow-list.

---

## K-02 — `APPLICATION_NAME` sözleşmesi

- **Tarih:** 2026-08-30
- **Belirti:** İstek reddediliyor ya da EDM tarafında ilişkilendirilemiyor.
- **Kesin kök neden:** `APPLICATION_NAME` EDM ile mutabık kalınan sabit
  değerdir; serbest metin değildir.
- **Uygulanan düzeltme:** Değer `ozelyazilim.kukaisland` olarak sabit. Sandbox
  araçları farklı bir değer görürse **çalışmayı reddediyor**
  (`unexpected_application_name`).
- **Kaynak:** EDM teknik desteği ile mutabakat.
- **Kanıt:** `SUPPORT_ENDPOINT`/`SANDBOX_SEND_*` çıktılarındaki
  `APPLICATION_NAME` alanı; sandbox sürücülerindeki sabit kontrol.
- **İlgili dosyalar:** `EDM/includes/invoice/class-invoice-config.php`,
  `scripts/edm-sandbox-send.php`
- **Tekrar yaşanırsa ilk bak:** Config'teki `get_application_name()`.

---

## K-03 — `REQUEST_HEADER`'ın sekiz alanı

- **Tarih:** 2026-08-31
- **Belirti:** Her operasyonda genel reddetme; oturum açılıyor fakat işlem
  ilerlemiyor.
- **Kesin kök neden:** `REQUEST_HEADER` eksik gönderiliyordu. WSDL sekiz alan
  bekliyor ve sırası dizidir.
- **Uygulanan düzeltme:** Tek ortak üretici:
  `SESSION_ID`, `CLIENT_TXN_ID`, `ACTION_DATE`, `REASON`, `APPLICATION_NAME`,
  `HOSTNAME`, `CHANNEL_NAME`, `COMPRESSED`. Üretim client'ı **ve** sandbox
  araçları aynı üreticiden geçiyor; bir zamanlar ayrışmışlardı.
  `CLIENT_TXN_ID` belge UUID'sine bağlıdır — EDM'in gördüğü idempotency
  anahtarı budur.
- **Kaynak:** WSDL `REQUEST_HEADERType`.
- **Kanıt:** `INVOICE_SOAP_XPATH_*` XPath iddiaları; `SUPPORT_WSDL_CONFORMANCE`
  element sırası kontrolü.
- **İlgili dosyalar:** `EDM/includes/invoice/class-edm-request-header.php`
- **Tekrar yaşanırsa ilk bak:** `Kuka_Island_Core_EDM_Request_Header::build()`
  ve onu çağıran her yer (tek üretici olması şart).

---

## K-04 — Bireysel alıcı: `11111111111` + gerçek ad/soyad

- **Tarih:** 2026-09-01
- **Belirti:** Bireysel siparişte fatura kesilemiyor, ya da belgede taraf adı
  "Nihai Tüketici" gibi genel bir unvan.
- **Kesin kök neden:** Nihai tüketiciden checkout'ta TCKN istenmiyor ve
  istenmemeli. Kimlik için genel TCKN kullanılır; **isim** ise uydurulamaz.
- **Uygulanan düzeltme:** TCKN koşulsuz `11111111111`
  (`GENERIC_INDIVIDUAL_TCKN`), `schemeID="TCKN"`. Ad/soyad WooCommerce'in
  fatura adından alınır ve **eksikse fail-closed** (`missing_individual_name`).
  Genel unvan hiç üretilmez. Sipariş TCKN taşıyorsa 11 hane olmak zorunda
  (`invalid_individual_tckn`).
- **Kaynak:** EDM teknik desteği 2026-09-03 yazılı cevabı: canlıda genel TCKN
  `11111111111` kullanılsa bile `cac:Person` içindeki gerçek ad ve soyadın
  zorunlu olduğu açıklandı.
- **Kanıt:** `INVOICE_INDIVIDUAL_EARCHIVE_RECEIVER_CONTRACT`,
  `INVOICE_INDIVIDUAL_RECEIVER_FAIL_CLOSED`
- **İlgili dosyalar:** `EDM/includes/invoice/class-invoice-order-mapper.php`
- **Tekrar yaşanırsa ilk bak:** Mapper'daki `GENERIC_INDIVIDUAL_TCKN` bloğu.
  Checkout'a TCKN alanı **eklenmemeli**.

---

## K-05 — `cac:Person`, `cac:Contact`'tan **sonra** gelir

- **Tarih:** 2026-09-03
- **Belirti:** Gerçek `SendInvoice` EDM tarafından reddedildi. Şema hatası
  mesajı bizim tarafta görünmedi.
- **Kesin kök neden:** Builder `cac:Person`'ı `cac:PartyIdentification`'ın
  hemen ardına ekliyordu. Üretilen sıra:
  `PartyIdentification, Person, PostalAddress, PartyTaxScheme, Contact`.
  UBL `PartyType` dizisinde `Person`, `Contact`'tan sonradır.
- **Uygulanan düzeltme:** Düğüm adın bilindiği yerde oluşturulup **en sona**
  ekleniyor. Kopyalanmadı — iki `Person` aynı belirtiyi veren farklı bir kusur
  olurdu. Üretilen sıra artık:
  `PartyIdentification, PostalAddress, PartyTaxScheme, Contact, Person`.
  Kurumsal yol değişmedi (`PartyName`, `Person` yok, `schemeID=VKN`).
- **Kaynak:** EDM teknik desteği 2026-09-03 yazılı cevabı; ayrıca **EDM'in
  kendi WSDL'i**: `PartyIdentification, PartyName, PostalAddress,
  PhysicalLocation, PartyTaxScheme, PartyLegalEntity, Contact, Person,
  AgentParty`.
- **Kanıt:** `INVOICE_INDIVIDUAL_PERSON_USES_VALID_PARTY_ORDER` — gerçek
  builder XML'i DOM ile okunur, dizinin tamamına karşı doğrulanır, eski hatalı
  sıranın üretilemediği de ölçülür.
- **İlgili dosyalar:** `EDM/includes/invoice/class-ubl-tr-builder.php`
  (`append_customer_party`)
- **Tekrar yaşanırsa ilk bak:** Party çocuk sırası. UBL element sırası
  hatalarında EDM sebebi ayrıntılı bildirmiyor; sırayı WSDL'deki `PartyType`
  dizisine karşı doğrula.

---

## K-06 — e-Arşiv adresleme: e-posta iki yerde, alias hiç

- **Tarih:** 2026-09-01
- **Belirti:** Belge alıcıya ulaşmıyor; ya da alias alanı için uydurma bir
  posta kutusu etiketi yazma isteği.
- **Kesin kök neden:** e-Arşiv alıcısının GİB posta kutusu **yoktur**. Teslim
  `INVOICE/HEADER/TO` üzerinden EDM tarafından yapılır.
- **Uygulanan düzeltme:** Tek ortak adresleme kuralı
  (`Kuka_Island_Core_EDM_Client::recipient_addressing()`):
  - e-Arşiv: `HEADER.TO` = müşteri e-postası, UBL `cbc:ElectronicMail` = **aynı**
    adres, `RECEIVER/@alias` **hiç serileştirilmez** (boş string bile değil).
  - e-Fatura: alias hem `RECEIVER/@alias` hem `HEADER.TO`'da; müşteri e-postası
    alias'ı **yerinden etmez**.
  `EmailInvoice` **çağrılmaz**; EDM zaten teslim eder.
- **Kaynak:** EDM teknik desteği yazılı cevabı; 2026-09-03 cevabında adresleme
  şeklimiz **kabul edildi**.
- **Kanıt:** `INVOICE_SOAP_XPATH_SEND_INVOICE_EARCHIVE`,
  `INVOICE_SOAP_XPATH_SEND_INVOICE_EINVOICE`, `SANDBOX_SEND_RECIPIENT`
- **İlgili dosyalar:** `EDM/includes/invoice/class-edm-client.php`,
  `EDM/includes/invoice/class-ubl-tr-builder.php`
- **Tekrar yaşanırsa ilk bak:** `recipient_addressing()`. Alias için asla
  varsayılan/uydurma değer üretme.

---

## K-07 — Numaralandırma: `ABC2009123456789` sentinel, `INVOICE/@ID` yok

- **Tarih:** 2026-09-01
- **Belirti:** Numara çakışması, ya da yerel sayaçtan numara üretme isteği.
- **Kesin kök neden:** Mali numarayı EDM atar. UBL-TR `cbc:ID`'yi zorunlu
  tutar, dolayısıyla gönderilen belgede bir değer bulunmak zorundadır — fakat o
  değer numara **değildir**.
- **Uygulanan düzeltme:** Gönderilen UBL'in `cbc:ID` alanı EDM'in dokümante
  ettiği portal yer tutucusunu taşır: `ABC2009123456789`. SOAP `INVOICE/@ID`
  **hiç gönderilmez**. Gerçek numara **yalnız yanıttan** okunur ve
  `_kuka_invoice_number_source = 'edm'` kaynak kanıtı olmadan hiçbir numara
  kabul edilmez (`invoice_numbering_unconfirmed`). Sentinel asla numara olarak
  kaydedilmez.
- **Kaynak:** EDM teknik desteği yazılı cevabı; iki gerçek çağrıda ölçüldü
  (16 karakterlik numara atandı).
- **Kanıt:** `SANDBOX_SEND_NUMBERING=PASS|ubl_cbc_id:ABC2009123456789|ubl_cbc_id_count:1|soap_invoice_id:omitted|number_source:edm_response_only`
- **İlgili dosyalar:** `EDM/includes/invoice/class-invoice-numbering.php`,
  `EDM/includes/invoice/class-invoice-order-store.php` (`write_edm_number`)
- **Tekrar yaşanırsa ilk bak:** `resolve_assigned_number()` ve
  `write_edm_number()` — tek yazıcı olması şart, sentinel'i reddeder.

---

## K-08 — `SendInvoice` durumu `INVOICE > HEADER > STATUS` yolundan okunur

- **Tarih:** 2026-08-31
- **Belirti:** Gönderim başarılı görünüyor fakat durum boş; ya da tersi.
- **Kesin kök neden:** `STATUS`, yanıtın kökünde değil `INVOICE` girdisinin
  `HEADER` düğümündedir. Yalnız üst seviyeye bakan parser hiçbir şey görmez.
- **Uygulanan düzeltme:** Parser `HEADER.STATUS` ve girdi seviyesini birlikte
  dener; literal EDM'in kendi yazımıyla saklanır ve tabloya **birebir**
  eşleşmeyle bakılır (substring ile değil — `KABUL`/`REJECT` gibi kısmi
  eşleşmeler iki yönde de yanlış sonuç veriyordu).
- **Kaynak:** WSDL + gerçek yanıtlar.
- **Kanıt:** `INVOICE_SEND_RESPONSE_STATUS_CONTRACT` (10 vaka),
  `INVOICE_EDM_STATUS_EXACT_MATCH` (12 vaka)
- **İlgili dosyalar:** `EDM/includes/invoice/class-edm-client.php`
  (`parse_send_invoice_response`), `EDM/includes/invoice/class-edm-document-status.php`
- **Tekrar yaşanırsa ilk bak:** `classify()` tablosu; bilinmeyen literal
  fail-closed `needs_manual_review`'a düşer, sessizce başarı sayılmaz.

---

## K-09 — `RETURN_CODE=0` tek başına "completed" demez

- **Tarih:** 2026-08-31
- **Belirti:** Belge tamamlandı sanılıyor, oysa numara yok.
- **Kesin kök neden:** `RETURN_CODE`/`GIB_STATUS_CODE` taşıma seviyesinde
  başarıyı ifade eder; belgenin mali durumunu ifade etmez.
- **Uygulanan düzeltme:** Lifecycle **yalnız** `STATUS` literalinden
  türetilir. Ek olarak `withhold_completion_without_number()`: numarası olmayan
  pozitif bir durum `completed` değil `pending_approval` olur. e-Arşivde
  `GIB_STATUS_CODE = -1` başarıyı maskelemez (ayrı ölçüm).
- **Kaynak:** WSDL alan anlamları + gerçek yanıtlar.
- **Kanıt:** `INVOICE_EARCHIVE_GIB_MINUS_ONE_IS_SUCCESS`,
  `INVOICE_SEND_RESPONSE_STATUS_CONTRACT`
- **İlgili dosyalar:** `EDM/includes/invoice/class-edm-client.php`
- **Tekrar yaşanırsa ilk bak:** `withhold_completion_without_number()`.

---

## K-10 — Yazma çağrılarında oturum yenileme tekrarı **kapalı**

- **Tarih:** 2026-09-02
- **Belirti:** Bir satış için iki mali belge riski.
- **Kesin kök neden:** `execute_with_session()` oturum süresi bittiğinde
  callback'i bir kez yeniden çalıştırıyordu. Okumada bedava; `SendInvoice`'ta
  **aynı belgenin ikinci kez iletilmesi**. EDM oturum kontrolünü gövdeyi
  işlemeden önce yapar, dolayısıyla pratikte ilk çağrı bir şey yapmamış olur —
  fakat bu, kodun verebileceği bir garanti değil.
- **Uygulanan düzeltme:** `send_invoice()` artık
  `allow_session_retry = false` geçiyor. Hata yüzeye çıkar, manager
  `send_uncertain` yazar, poller EDM'e ne olduğunu sorar. Okuma yolları
  değişmedi.
- **Kaynak:** Kod incelemesi + davranışsal ölçüm.
- **Kanıt:** `INVOICE_SESSION_EXPIRY_NEVER_RETRANSMITS=PASS|SendInvoice=1|Login=1|LoadInvoice=0|threw:yes|status:send_uncertain|poll_actions_pending:1|read_path_unaffected:yes`
- **İlgili dosyalar:** `EDM/includes/invoice/class-edm-client.php`
- **Tekrar yaşanırsa ilk bak:** `execute_with_session()`'ın
  `$allow_session_retry` parametresi ve `send_invoice()`'ın onu nasıl geçtiği.

---

## K-11 — Belirsiz gönderimde **kör resend yok**

- **Tarih:** 2026-09-01
- **Belirti:** Timeout sonrası "tekrar deneyelim" refleksi.
- **Kesin kök neden:** Belirsiz bir gönderimden sonra belgenin EDM'de var olup
  olmadığı bilinmez. Yeniden göndermek mükerrer belge üretir.
- **Uygulanan düzeltme:** Merkezî guard: `transmission_evidence()` dört kalıcı
  kanıttan birini görürse (`uuid`, gönderim-sonrası `status`, `sent_at` veya
  `send_attempts > 0`) `SendInvoice` yolu **kapanır**; yalnız
  `reconcile_only` okuma yolu kalır. **Fatura numarası kanıt değildir:** numara
  gönderimden önce çözülebilir ve hiç iletilmemiş bir siparişte kalabilir.
  Poller `SendInvoice`'a **ulaşamaz**. Sandbox tarafında da aynı ilke:
  `uncertain` kayıt ikinci gönderimi reddeder, çözüm yalnız salt-okunur
  mutabakattır.
- **Kaynak:** Tasarım kararı; davranışsal testlerle kilitli.
- **Kanıt:** `INVOICE_POST_TRANSMISSION_GUARD`, `SANDBOX_RESOLVE_VERDICT`
  (11 vaka, varsayılan `unknown`)
- **İlgili dosyalar:** `EDM/includes/invoice/class-invoice-manager.php`,
  `EDM/includes/invoice/class-invoice-status-poller.php`,
  `scripts/lib-edm-sandbox.php`
- **Tekrar yaşanırsa ilk bak:** `transmission_evidence()` ve
  `may_start_transmission()`. Yokluğu **kanıtlamadan** iddia etmek bu dosyadaki
  tek ölümcül hata.

---

## K-12 — `SEND - FAILED` / `PACKAGE - FAIL` kurtarma: **yeni UUID ve yeni numara**

- **Tarih:** 2026-09-01
- **Belirti:** Reddedilmiş belge tekrar gönderilmek isteniyor.
- **Kesin kök neden:** Başarısız bir belge aynı UUID ile yeniden
  gönderilemez; EDM onu zaten görmüştür.
- **Uygulanan düzeltme:** `Kuka_Island_Core_Invoice_Recovery`: operatör onayı
  şart, eski belge `superseded` olarak **arşivlenir** (silinmez), yeni belge
  **yeni UUID** alır ve numarayı yine EDM atar. Poll durumu yeni belgeye
  devredilmez — devredilmiş bir poll eski belgenin cevabını yenisine yazardı.
  Advisory lock `kuka_inv_recreate_<id>` ile tek seferlik.
- **Kaynak:** EDM durum sınıflandırması + tasarım.
- **Kanıt:** `INVOICE_RECOVERY_*` ölçümleri; `superseded` meta arşivi.
- **İlgili dosyalar:** `EDM/includes/invoice/class-invoice-recovery.php`,
  `EDM/includes/invoice/class-invoice-order-store.php`
  (`archive_superseded_document`, `superseded_poll_meta_keys`)
- **Tekrar yaşanırsa ilk bak:** `is_eligible()` ve `reserved_uuid()`.

---

## K-13 — Rapor tarihleri: şema zorunlu, iş kuralı gereksiz, değer `0001-01-01`

- **Tarih:** 2026-09-03
- **Belirti:** `EARCHIVE_REPORT_SENDDATE` alanlarına ne yazılacağı belirsiz;
  ya da alanları kaldırma isteği.
- **Kesin kök neden:** İki gerçek birbiriyle çelişiyor. EDM: bu alanlar
  outgoing request için **gerekli değil** ve EDM'in GİB'e raporlama tarihlerini
  ifade ediyor. WSDL: her ikisi de `type="xs:date" minOccurs="1"`.
- **Uygulanan düzeltme:** Kaldırmak **teknik olarak imkânsız** — ölçüldü:
  alanlar olmadan `SoapFault`,
  `SOAP-ERROR: Encoding: object has no 'EARCHIVE_REPORT_SENDDATE' property`,
  ve **hiç zarf üretilmiyor** (0 byte). ext-soap `minOccurs`'u kodlama
  aşamasında uygular. Bu yüzden alanlar gönderiliyor, fakat değer
  `issue_date` **değil** `0001-01-01`. Bu uydurma dolgu değil: EDM'in resmî
  "Web API v4 Request-Response" belgesindeki her iki `SendInvoice` örneği bu
  değeri taşıyor ve resmî C# connector alanlara hiç değer atamadığı için .NET
  `DateTime.MinValue` serileştiriyor — aynı değer. `issue_date` henüz
  gerçekleşmemiş bir raporlama tarihi iddia ediyordu; boş/`null` ise `xs:date`
  için geçersiz.
- **Kaynak:** EDM teknik desteği 2026-09-03 yazılı cevabı + resmî istek
  örnekleri + WSDL.
- **Kanıt:** `INVOICE_OUTGOING_REQUEST_OMITS_REPORT_SENDDATES=BLOCKED|...|omission_verdict:SoapFault|omission_envelope_produced:no|control_serialises:yes|wsdl_declares:...minOccurs=1|probe_sound:yes`
  — bilinçli olarak **BLOCKED**: adı "atlanıyor" diyor, atlanmıyor.
- **İlgili dosyalar:** `EDM/includes/invoice/class-edm-client.php`,
  `scripts/lib-edm-sandbox.php`
- **Tekrar yaşanırsa ilk bak:** Kontrolün `wsdl_declares` alanı. EDM WSDL'i
  gevşetirse atlama serileşmeye başlar, beklenti kırılır ve konu yeniden
  ölçülmeye zorlanır. Bu konu artık destek cevabı beklemiyor: EDM alanların iş
  kuralı bakımından zorunlu olmadığını yazılı doğruladı; mevcut WSDL ise
  serileştirmede ikisini de zorunlu tuttuğu için resmî örneklerle aynı
  `0001-01-01` değeri kullanılıyor. Bu şekil gerçek `SendInvoice` tarafından
  kabul edildi; WSDL değişirse yeniden ölçülmelidir.

---

## K-14 — `INTERNETSALESDETAILS`, gönderi tarihi ve taşıyıcı VKN

- **Tarih:** 2026-09-02
- **Belirti:** Mesafeli satış bloğu eksik/yanlış; ya da gönderi tarihi bir gün
  kayıyor.
- **Kesin kök neden:** Üç ayrı şey.
  1. Blok WSDL'de `minOccurs="0"` ve mesafeli satışı **tarif eder**; her belgeye
     iliştirilecek bir şey değil.
  2. WooCommerce'in `date_fulfilled` değeri **UTC**'dir, yerel duvar saati
     değil. Doğrudan tarih olarak yazmak gün kaydırır.
  3. Tüzel taşıyıcı VKN'si 10 hane olmak zorundadır.
- **Uygulanan düzeltme:**
  - Blok yalnız gerçek mesafeli satışta ve **tüm** olgular gözlemlendiğinde
    gönderilir; eksikse yarı dolu gönderilmez, `internet_sales_details_incomplete`
    ile durur. WSDL dizisi: `webAdresi, odemeSekli, odemeAracisiAdi,
    odemeTarihi, gonderiBilgileri{gonderimTarihi, gonderiTasiyan{tuzelKisi|gercekKisi}}`.
    Şemada `*Specified` companion element **yok**; gönderilmiyor.
  - Gönderi tarihi: `date_fulfilled` UTC olarak katı ayrıştırılır
    (`!Y-m-d H:i:s`, açık `UTC` timezone, round-trip karşılaştırma), sonra
    `wp_timezone()`'a çevrilip **takvim günü** alınır. Baştaki/sondaki boşluk
    reddedilir.
  - Taşıyıcı VKN'si tam olarak `/^\d{10}$/`. Uydurma DHL VKN/unvanı
    **kullanılmaz**.
- **Kaynak:** WSDL inline complexType + WooCommerce Fulfillments kaynağı
  (`normalize_date_to_utc`).
- **Kanıt:** `INVOICE_SOAP_XPATH_SEND_INVOICE_EARCHIVE` (ISD düğüm sayıları),
  `INVOICE_LEGAL_CARRIER_REQUIRES_10_DIGIT_VKN` (7 vaka),
  `INVOICE_FULFILLMENT_DATE_*`
- **İlgili dosyalar:** `EDM/includes/invoice/class-internet-sales-details.php`,
  `EDM/includes/invoice/class-invoice-manager.php`
- **Tekrar yaşanırsa ilk bak:** `parse_fulfillment_datetime()` ve
  `resolve_carrier()`.

---

## K-15 — `LoadInvoice` ile `SendInvoice` aynı şey değildir

- **Tarih:** 2026-09-02
- **Belirti:** "Test için bir tane deneyelim" isteği.
- **Kesin kök neden:** İkisi farklı sonuç üretir ve karıştırılması geri
  alınamaz.
- **Uygulanan düzeltme:** Kayıt altına alındı ve araçlar ayrıldı:
  - `LoadInvoice` belgeyi EDM'de **taslak** olarak saklar, fatura kesmez,
    alıcıya hiçbir şey iletmez. `GENERATEINVOICEIDONLOAD=true` ile EDM numara
    atar.
  - `SendInvoice` **fatura keser** ve EDM belgeyi `HEADER.TO` adresine
    kendisi teslim eder.
  İki deneyin kapıları, tohumları ve state dosyaları **tamamen ayrı**; ikisini
  birlikte açmak host seviyesinde reddedilir.
- **Kaynak:** WSDL + EDM davranışı.
- **Kanıt:** `SANDBOX_SEND_GATES_AND_ISOLATION` (10 vaka),
  `edm-sandbox-send-run.sh` host reddi
  (`loadinvoice_write_gate_open_during_send`)
- **İlgili dosyalar:** `scripts/edm-sandbox-invoice.php` (LoadInvoice),
  `scripts/edm-sandbox-send.php` (SendInvoice)
- **Tekrar yaşanırsa ilk bak:** `kuka_sandbox_send_gates()`.

---

## K-16 — Mevcut sandbox belgesinin son ölçülen durumu

- **Tarih:** 2026-09-03 (son salt-okunur ölçüm)
- **Belirti:** —
- **Kesin kök neden:** —
- **Durum:** `cac:Person` sırası düzeltildikten sonra yapılan **tek**
  `SendInvoice` EDM tarafından **kabul edildi**. Kalıcı kayıt:
  `state=confirmed`, `outcome=success`, EDM 16 karakterlik numara atadı,
  `edm_status = PACKAGE - PROCESSING`.
  Bu literal üretim tablosunda `CLASS_PENDING`, yani **terminal değil**.
  `SEND - SUCCEED` **görülmedi**; numara atanmış olması belgenin GİB'e
  ulaştığını göstermez. `GetInvoice` XML'i hâlâ boş `CONTENT` döndürüyor —
  gönderilmiş fakat işlenmekte olan bir belge için de böyle olduğu ölçüldü;
  nedeni ölçülmemiştir.
- **Kanıt:** `SANDBOX_SEND_STATUS=PASS|edm_status:PACKAGE - PROCESSING|status_class:pending|terminal:pending|document_present_at_edm:yes|number_returned:yes|number_length:16|number_matches_record:yes|status_response_uuid_match:yes`
- **İlgili dosyalar:** `scripts/edm-sandbox-send.php` (`status=confirm` modu)
- **Tekrar yaşanırsa ilk bak:** Aşağıdaki K-17.

---

## K-17 — Arka plan sandbox izleyicisi **yoktur**

- **Tarih:** 2026-09-03
- **Belirti:** "Arka plan kontrolü kuruldu" ifadesi. **Kurulmadı.**
- **Kesin kök neden:** Geçmişte böyle bir iddia yazıldı fakat çalışan hiçbir
  süreç yoktu.
- **Uygulanan düzeltme:** Kontrol **elle** yapılır ve çıktı bunu açıkça yazar:
  ```bash
  ./scripts/edm-sandbox-send-run.sh status=confirm
  ```
  Mod yapısal olarak salt-okunurdur: allow-list'li transport yalnız `Login`,
  `GetInvoiceStatus`, `GetInvoice`, `Logout` taşır (diğerleri **iç transport'a
  ulaşmadan** reddedilir), state dizini `:ro` mount edilir, claim **hiç
  alınmaz**, state dosyasının SHA-256'sı başta ve sonda raporlanır.
  Çıktı `no_background_process_started:yes` ve önerilen en erken yeniden
  kontrol zamanını içerir — bu bir **öneri**, zamanlanmış iş değil.
- **Kanıt:** `SANDBOX_STATUS_MODE_IS_READ_ONLY` (10 operasyon: 4 izinli,
  6 reddedilir; reddedilenlerin iç transport'a ulaşmadığı ayrıca ölçülür),
  `SANDBOX_SEND_STATUS_OPERATIONS=PASS|SendInvoice:0|LoadInvoice:0`
- **İlgili dosyalar:** `scripts/edm-sandbox-send.php`,
  `scripts/edm-sandbox-send-run.sh`, `scripts/lib-edm-sandbox.php`
  (`Kuka_Sandbox_Readonly_Transport`)
- **Tekrar yaşanırsa ilk bak:** Hiçbir cron/AS işi aramayın; yoktur.

---

## K-18 — Canlı aktivasyon **henüz yapılmadı**

- **Tarih:** 2026-09-03
- **Durum:** Canlı EDM kimlik bilgileri **yapılandırılmamış**. Otomatik
  gönderim **kapalı**. EDM eklentisi **pasif** teslim ediliyor.
  Şimdiye kadar yapılan her gerçek çağrı **yalnız EDM TEST ortamında**.
- **Uygulanan düzeltme:** Üç seviyeli çalışma modeli ve pasif teslim
  sözleşmesi; ayrıntı [EDM_AKTIVASYON_REHBERI.md](EDM_AKTIVASYON_REHBERI.md).
- **Kanıt:** `EDM_PASSIVE_PLUGIN_STATE=PASS|plugin_file_present:yes|plugin_active:no`,
  `EDM_PASSIVE_CLASSES_ABSENT`, `EDM_PASSIVE_HOOKS_ABSENT`,
  `EDM_PASSIVE_ACTIONS_ABSENT`, `EDM_PASSIVE_ORDER_LIFECYCLE`
- **İlgili dosyalar:** `wp-content/plugins/kuka-island-edm/kuka-island-edm.php`,
  `scripts/install.sh`, `docs/DEPLOY_RUNBOOK.md`
- **Tekrar yaşanırsa ilk bak:** Aktivasyon rehberinin kontrol listesi.

---

## K-19 — Deaktivasyon, çalışan bir worker'ı da durdurur

- **Tarih:** 2026-09-03
- **Belirti:** "Eklentiyi kapattım, ama gönderim yapılmış olabilir mi?"
- **Kesin kök neden:** Deaktivasyon hook'ları **sonraki** istek için kaldırır.
  Action Scheduler worker'ı çoktan yüklenmiş ve gönderimin içinde olabilir;
  hook kaldırmak o isteğe erişemez.
- **Uygulanan düzeltme:** Kalıcı çalışma kapısı
  (`Kuka_Island_Core_Invoice_Runtime_Gate`). Deaktivasyon **önce kapıyı
  kapatır**, sonra kendi bekleyen işlerini iptal eder. Gönderim yolu kapıyı
  `mark_sending()`'den **önce** ve **object cache'i atlayarak** okur — aynı
  istek içinde daha önce cache'lenmiş bir değer tam olarak gönderime izin
  verecek değerdir. Kapı kapalıyken hiçbir state yazılmaz: rezerve UUID ve
  `sending` durumu kalmaz.
- **Kaynak:** Tasarım; WordPress hook yaşam döngüsü.
- **Kanıt:** `EDM_DEACTIVATION_GATE_STOPS_INFLIGHT_SEND=PASS|gate_closed_SendInvoice:0|error_code:edm_runtime_disabled|uuid_written:no|status_after:none|gate_open_SendInvoice:1|sees_change_past_object_cache:yes`
  — kontrol kolu (`gate_open_SendInvoice:1`) olmadan ilk ölçüm hiçbir şey
  kanıtlamazdı.
  Ayrıca `EDM_LIFECYCLE_DEACTIVATION` gerçek `wp plugin deactivate` sonrası
  **yeni bir WordPress sürecinde** ölçer: sınıflar yüklü değil, hook yok,
  bekleyen EDM action'ı 0, Core/WooCommerce çalışıyor ve fatura metası
  **korunmuş** (`invoice_meta_preserved:yes`).
- **İlgili dosyalar:** `EDM/includes/invoice/class-invoice-runtime-gate.php`,
  `EDM/includes/class-activator.php`,
  `EDM/includes/invoice/class-invoice-manager.php`
- **Tekrar yaşanırsa ilk bak:** `kuka_island_edm_runtime_disabled` option'ı.
  Deaktivasyon **sipariş metasını, geçmişi, UUID/numara kayıtlarını veya
  superseded kayıtlarını silmez** ve başka eklentilerin action'larına
  dokunmaz.

---

## K-20 — Durum sorgusunun sahibi send kuyruğu değil, poller'dır

- **Tarih:** 2026-08-31
- **Belirti:** İlk durum sorgusundan sonra zincir sessizce duruyor, sonsuza
  kadar send worker yeniden planlanıyor veya planlama hatası görünmüyor.
- **Kesin kök neden:** Üç kusur üst üste gelmişti. Poller'ın `start()` metodu
  gerçek gönderim akışından çağrılmıyordu; `as_has_scheduled_action()` çalışan
  action'ı da pending saydığı için callback kendi devamını planlayamıyordu;
  planlama sonucundaki `false`/exception sessizce yutuluyordu.
- **Uygulanan düzeltme:** Gönderim sonucunun yazıldığı tek merkez
  `Invoice_Manager::process_order()` poller'ı başlatır. Poller'ın ayrı
  `ACTION_QUERY_STATUS` action'ı yalnız `GetInvoiceStatus` çağırabilir.
  Duplicate kontrolü yalnız pending action'a bakar ve sipariş bazlı ayrı
  advisory lock kullanır. Planlama sonucu `created`, `already_pending`,
  `lock_contended`, `scheduler_unavailable` veya `schedule_failed` olarak
  saklanır; hata operatör notuna güvenli kodla yansır.
- **Önemli sınır:** `MAX_ATTEMPTS=12` ve artan gecikmelerin toplamı fiilen
  23.400 saniye, yani yaklaşık **6,5 saat**tir. `MAX_ELAPSED=24 saat` mevcut
  matematikte önce dolmaz. Uzun sürebilen ticari fatura alıcı cevapları için
  ayrı seyrek izleme tasarlanmadan bu pencere “24 saat” diye anlatılmamalıdır.
- **Kanıt:** `INVOICE_POLLER_AUTOSTARTS_FROM_SEND`,
  `INVOICE_POLL_FOLLOWUP_ON_REAL_RUNNER`,
  `INVOICE_POLL_FIRST_SCHEDULE_FAILURE_VISIBLE`,
  `INVOICE_POLL_LOCK_RACE_FAIL_VISIBLE`
- **İlgili dosyalar:** `EDM/includes/invoice/class-invoice-status-poller.php`,
  `EDM/includes/invoice/class-invoice-manager.php`
- **Tekrar yaşanırsa ilk bak:** Pending action sayısı, poller'ın son planlama
  sonucu ve callback sırasında action statüsünün `in-progress` oluşu. Send
  kuyruğuna sorgu sorumluluğu geri verilmemelidir.

---

## K-21 — Kuyruk retry sayacı mali gönderim sayacı değildir

- **Tarih:** 2026-08-31
- **Belirti:** Ön-gönderim transient hatası sonsuz action zinciri oluşturuyor
  veya yeni bir zincir önceki zincirin kalan bütçesiyle başlıyor.
- **Kesin kök neden:** Retry bütçesi `_kuka_invoice_attempts` mali
  `SendInvoice` sayacından okunuyordu. Ön-gönderim kilit/altyapı hatası bu
  sayacı artırmadığı için cap hiç gelmiyordu. Daha sonra ayrı sayaç eklendi,
  fakat tüm çıkışlarda temizlenmediği için yeni zincir eski bütçeyi miras
  alabiliyordu.
- **Uygulanan düzeltme:** Yalnız send kuyruğuna ait
  `_kuka_invoice_queue_retries` kullanılır; en fazla üç ön-gönderim denemesi
  vardır. Başarı, cap, permanent/generic exception, poller'a devir,
  auto-send-kapalı çıkışı ve yeni zincir başlangıcında temizlenir. Mali
  `_kuka_invoice_attempts` bütçe olarak okunmaz.
- **Kanıt:** `INVOICE_QUEUE_PRETRANSMISSION_RETRY_CAP`,
  `INVOICE_QUEUE_RETRY_COUNTER_CLEARED_ON_SUCCESS`,
  `INVOICE_QUEUE_RETRY_META_CLEARED_ON_EVERY_CHAIN_EXIT`,
  `INVOICE_QUEUE_NEW_CHAIN_STARTS_AT_ZERO`
- **İlgili dosyalar:** `EDM/includes/invoice/class-invoice-queue.php`
- **Tekrar yaşanırsa ilk bak:** İki meta anahtarını karıştırma. Queue retry
  değeri yeni zincirde yok olmalı; mali attempt değeri geçmiş kanıtıdır ve
  silinmemelidir.

---

## K-22 — `queued`: worker için geçerli, operatör resend'i için geçersiz

- **Tarih:** 2026-09-01
- **Belirti:** Otomatik kuyruk siparişi `queued` yaptıktan sonra worker
  `invalid_invoice_status_transition` ile duruyor; `queued` statüsünü genel
  retry listesine eklemek ise panelde ikinci “Faturayı Gönder” yolunu açıyor.
- **Kesin kök neden:** “Worker bu kaydı alabilir mi?” ile “operatör yeniden
  gönderebilir mi?” aynı predicate'e bağlanmıştı.
- **Uygulanan düzeltme:** `can_retry(queued)=false` kalır; panel resend sunmaz.
  Ayrı `Invoice_Manager::may_start_transmission(queued)=true` yalnız hiç
  gönderilmemiş worker başlangıcına izin verir. Kalıcı
  `transmission_evidence()` guard'ı önce çalışır ve bu predicate'i ezer.
- **Kanıt:** `INVOICE_AUTO_SEND_QUEUED_ORDER_REACHES_SEND`,
  `INVOICE_QUEUED_STATUS_DOES_NOT_ENABLE_ADMIN_RESEND`
- **İlgili dosyalar:** `EDM/includes/invoice/class-invoice-manager.php`,
  `EDM/includes/invoice/class-invoice-status.php`
- **Tekrar yaşanırsa ilk bak:** `queued` değerini `can_retry()` listesine
  eklemeyin; worker kapısını `may_start_transmission()` üzerinden ölçün.

---

## K-23 — Destek paketi özgün request değildir

- **Tarih:** 2026-09-02–03
- **Belirti:** EDM “request'i gönderin” diyor, fakat reddedilen çağrının ham
  SOAP zarfı diskte yok; yeniden üretilen zarf “birebir aynı” sanılıyor.
- **Kesin kök neden:** Sıfır-sır-sızıntısı politikası ham SOAP gövdesini ve
  session bilgisini kalıcı olarak saklamıyordu — bu doğru güvenlik
  davranışıydı. Sonradan aynı kayıtlı girdilerle üretilen zarfın
  `ACTION_DATE`/zaman alanları değişebildiği için özgün çağrıyla byte-identik
  olduğu iddia edilemez.
- **Uygulanan düzeltme:** Destek paketi gerçek üretim kodundan yeniden üretildi;
  session ve kimlik işaretleri redakte edildi, dış zarfın `CONTENT` değeri ile
  ayrı UBL dosyasının bit düzeyinde aynı olduğu ve XML'in well-formed olduğu
  hash/round-trip kontrolleriyle ölçüldü. Özet açıkça bunun özgün request değil,
  yeniden üretim olduğunu ve WSDL uyumunun UBL-TR/GİB/EDM iş kurallarının
  tamamını kanıtlamadığını yazdı. EDM'in request logu tutmadığı destek
  cevabıyla öğrenildi; paket sayesinde asıl `cac:Person` sıra kusuru bulundu.
- **Güvenlik:** Paket repoya alınmaz; kullanıcı adı, parola, secret key,
  session ID, tam UUID ve tam belge numarası bakım belgelerine yazılmaz.
- **Kaynak:** EDM teknik destek yazışması ve redakte paket doğrulamaları.
- **İlgili dosyalar:** `scripts/edm-sandbox-send.php`,
  `docs/EDM_ENTEGRASYONU.md` §15.5–§16.1
- **Tekrar yaşanırsa ilk bak:** Önce mevcut çağrıyı yeniden göndermeyin. Ayrı
  onaylı bir teşhis koşusunda mode-700 geçici dizin/mode-600 dosya, bağımsız
  sır taraması ve açık “yeniden üretilmiştir” ibaresi kullanın.

---

## K-24 — EDM ayrı ve varsayılan pasif eklentidir

- **Tarih:** 2026-09-03
- **Belirti:** Mağaza EDM kullanmaya hazır değilken Core'un her istekte fatura
  sınıflarını yüklemesi; entegrasyonu kapatmanın güvenli ve anlaşılır bir yolu
  olmaması.
- **Kesin kök neden:** Mali entegrasyon başlangıçta `kuka-island-core` içine
  gömülüydü. Müşteri belirli sipariş hacmine ulaşana kadar faturayı manuel
  keseceği için bu, ürün yaşam döngüsüyle uyuşmuyordu.
- **Uygulanan düzeltme:** Kod geçmişi korunarak ayrı
  `wp-content/plugins/kuka-island-edm/` eklentisine taşındı. Bağımlılık yalnız
  `EDM → Core`; tersi yasak. Deploy paketi eklentiyi ve üç rehberi taşır,
  `install.sh` eklentiyi yeni kurulumda etkinleştirmez ve daha önce bilinçli
  etkinleştirilmişse de kapatmaz. Pasifken sınıf, hook, panel, SOAP çağrısı,
  action ve sipariş metası oluşmaz; manuel fatura/kargo akışı değişmez.
- **Kanıt:** `EDM_PASSIVE_*`, `EDM_LIFECYCLE_*`,
  `verify-deploy-package.sh`
- **İlgili dosyalar:** `EDM/kuka-island-edm.php`,
  `EDM/includes/class-plugin.php`, `EDM/includes/class-activator.php`,
  `scripts/install.sh`, `scripts/build-deploy-package.sh`
- **Tekrar yaşanırsa ilk bak:** Aktivasyon rehberi. Eklentiyi etkinleştirmek
  auto-send'i açmaz; canlı kimlik ve gönderim onayı ayrı aşamalardır.

---

## K-25 — Sandbox yazma claim'i kalıcıdır; reset tamamen çevrimdışıdır

- **Tarih:** 2026-08-31–2026-09-03
- **Belirti:** Belirsiz bir `LoadInvoice`/`SendInvoice` sonrasında araç yeniden
  yazmaya çalışıyor; bozuk state dosyasını boş sayıyor; “reset” komutu çalışırken
  önce Login/CheckUser gibi EDM çağrıları yapıyor.
- **Kesin kök neden:** İlk tasarımlarda yazma sahipliği ile sonuç durumu yeterince
  ayrılmamıştı. Reset kontrolü de credential yükleme, client oluşturma ve EDM
  okumalarının **sonrasında** duruyordu. Daha kötüsü, ilk “0 SOAP çağrısı” testi
  gerçek runner'ı değil testin kendi closure'ını çalıştırdığı için hiçbir şeyi
  kanıtlamıyordu.
- **Uygulanan düzeltme:** Her sandbox yazma türünün ayrı, mode-600 state dosyası
  ve tek-sahipli kilidi vardır. Geçişler `idle → in_flight → confirmed |
  failed_definitive | uncertain`; `uncertain`, terminal ve bozuk kayıtlar yeni
  yazmayı fail-closed reddeder. Reset CLI argümanları dosyanın başında ayrıştırılır
  ve credential/client/EDM yollarından **önce** çıkar. Wrapper reset modunda
  credential dosyasını mount etmez, yazma env'ini iletmez ve açık yazma kapısını
  Docker başlamadan host'ta reddeder. Reset yalnız kanıtlı yoklukla
  `uncertain → idle` yapar; UUID'yi değiştirmez ve geçmişi append-only korur.
- **Kanıt:** `SANDBOX_CLAIM_*`, `SANDBOX_STATE_CORRUPTION_FAIL_CLOSED`,
  `SANDBOX_CORRUPT_STATE_BLOCKS_WRITE`,
  `SANDBOX_RESET_PRECEDES_EVERY_EDM_PATH`,
  `SANDBOX_RESET_HOST_WRITE_GATE`, `SANDBOX_RESET_REAL_WRAPPER_DRIVER`
- **İlgili dosyalar:** `scripts/lib-edm-sandbox.php`,
  `scripts/edm-sandbox-invoice.php`, `scripts/edm-sandbox-run.sh`,
  `scripts/verify-reset-offline.sh`
- **Tekrar yaşanırsa ilk bak:** State'i elle düzenlemeyin veya silmeyin. Önce
  salt-okunur EDM kanıtını alın; sonra yalnız belgelenmiş reset komutunu kullanın.
  “Testte sayaç sıfır” demeden önce testin **gerçek wrapper ve gerçek driver**
  yolunu çalıştırdığını doğrulayın.

---

## K-26 — Endpoint ve kimlik girdileri normalize edilmez

- **Tarih:** 2026-08-31
- **Belirti:** Başında/sonunda boşluk, tab, yeni satır veya kontrol byte'ı olan
  WSDL değeri kanonik endpoint gibi kabul ediliyor; kimlik dosyasındaki özel
  karakterler kabuk tarafından değişiyor.
- **Kesin kök neden:** Endpoint doğrulayıcı `$wsdl` üzerinde önce `trim()`
  çalıştırdığı için kendi “whitespace/control reddi” kuralını uçlarda
  ulaşılamaz yapıyordu. Kimlikleri komut argümanı/env metni gibi taşımak da
  boşluk, `=` ve sır sızıntısı riski oluşturuyordu.
- **Uygulanan düzeltme:** Endpoint ham byte dizisi olarak, hiç
  `trim`/`ltrim`/`rtrim` yapılmadan doğrulanır; boşluk/kontrol, backslash,
  fragment, userinfo, scheme/host/path/query/port sırayla fail-closed kontrol
  edilir. Kimlikler repo dışında mode-600 dosyada tutulur; değerler yankılanmaz,
  argv'ye girmez ve parser boşluk ile `=` karakterlerini verbatim korur.
- **Kanıt:** `SANDBOX_ENDPOINT_ALLOWLIST` (42 vaka),
  `SANDBOX_ENDPOINT_REJECTS_PADDING`,
  `SANDBOX_ENDPOINT_DOES_NOT_NORMALISE`, `SANDBOX_CRED_PARSER_VERBATIM`,
  `EDM_RUNNER_ALLOWLIST`
- **İlgili dosyalar:** `scripts/lib-edm-sandbox.php`,
  `scripts/edm-test-credentials.sh`, `scripts/edm-test-run.sh`
- **Tekrar yaşanırsa ilk bak:** Endpoint'i “düzeltmek” için kırpmayın; girdiyi
  üreten kaynağı düzeltin. Kimliği shell komutuna, `.env` çıktısına, WordPress
  option'ına veya repoya kopyalamayın.

---

## K-27 — `make verify` gerçek EDM sonucunu kanıtlamaz

- **Tarih:** 2026-09-03
- **Belirti:** Yüzlerce PASS görüldüğünde “EDM'ye bağlandık, fatura kesin
  kesiliyor” sonucu çıkarılıyor; ya da sandbox portalındaki sonuç ile yerel mock
  test aynı kanıt düzeyinde anlatılıyor.
- **Kesin kök neden:** Üç ayrı kanıt yüzeyi tek raporda karıştırılmıştı:
  kaynak/sözleşme taraması, yerel davranışsal test ve gerçek EDM sandbox çağrısı.
  `make verify` gerçek EDM `SendInvoice` çağırmaz; kimlik yoksa gerçek Login ve
  CheckCounter satırları açıkça `BLOCKED`, gönderim satırı `SKIPPED` kalır.
- **Uygulanan düzeltme:** Kanıtlar ayrı adlandırılır:
  1. `make verify`: mock/fixture, gerçek WordPress/Action Scheduler ve izolasyon
     sözleşmeleri; dış EDM yazması **yok**.
  2. Salt-okunur probe/status: gerçek EDM Login/okuma; belge yazması **yok**.
  3. Açık iki kapılı sandbox `LoadInvoice`/`SendInvoice`: gerçek EDM yazması;
     sonucu ayrıca state ve portal/status kanıtıyla kaydedilir.
  Kod testi PASS olsa bile tarayıcı/portal veya gerçek sandbox ölçümü gereken
  bir kabul maddesine PASS yazılmaz.
- **Kanıt:** `REAL_EDM_LOGIN=BLOCKED|reason:no_runtime_credentials`,
  `REAL_EDM_SEND_INVOICE=SKIPPED|reason:read_only_verification_never_sends`,
  `SANDBOX_SEND_WRITE_OPERATIONS`, `SANDBOX_SEND_STATUS_OPERATIONS`
- **İlgili dosyalar:** `scripts/verify.sh`, `scripts/test-edm-sandbox.php`,
  `scripts/edm-sandbox-send-run.sh`, `docs/EDM_AKTIVASYON_REHBERI.md`
- **Tekrar yaşanırsa ilk bak:** Raporda önce “hangi yüzey ölçüldü?” sorusunu
  cevaplayın. Mock PASS, EDM kabulü ve GİB terminal başarısı üç farklı olgudur.

---

## K-28 — Native Linux bind mount'larında UID farkı hesaba katılmalıdır

- **Tarih:** 2026-09-04
- **Belirti:** EDM çevrimdışı reset testi macOS Docker Desktop'ta geçerken GitHub
  Actions'ta `fixture_claim_not_created` ile duruyor.
- **Kesin kök neden:** Host'un `mktemp` dizini `0700` ve CI runner kullanıcısına
  aitti; Compose içindeki `wp-cli` ise kasıtlı olarak `33:33` çalışıyordu. Native
  Linux bind mount bu sayısal sahipliği koruduğu için container geçici state
  dizinine ulaşamıyor ve claim'i oluşturamıyordu. Seed hatasının bastırılması da
  asıl izin hatasını belirsiz bir fixture hatasına dönüştürüyordu.
- **Uygulanan düzeltme:** Yalnız rastgele geçici yol geçilebilir, state yaprağı
  container UID'si tarafından yazılabilir yapıldı. Claim yine container içinde
  `0600` oluşturulur. Host claim'i doğrudan okumaz; aynı UID ile çalışan ayrı bir
  helper container state dizinini salt-okunur mount ederek JSON'u test sürecine
  aktarır. Gerçek kullanıcı state'i ve credential dosyası bu yola girmez.
- **Kanıt:** `SANDBOX_RESET_HOST_WRITE_GATE`,
  `SANDBOX_RESET_REAL_WRAPPER_DRIVER`; GitHub Actions `Quality` işi.
- **İlgili dosya:** `scripts/verify-reset-offline.sh`
- **Tekrar yaşanırsa ilk bak:** Docker Desktop'ta geçen bind-mount testini native
  Linux için kanıt saymayın. Container UID/GID'sini, üst dizinlerin execute
  izinlerini ve claim'in gerçek modunu birlikte ölçün; güvenlik dosyasını host
  okuyabilsin diye gevşetmeyin.
