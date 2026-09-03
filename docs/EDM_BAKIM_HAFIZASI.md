# EDM Bakım Hafızası

Bu dosya **bakım kaydıdır**, teknik sözleşme değil. Sözleşme:
[EDM_ENTEGRASYONU.md](EDM_ENTEGRASYONU.md). Etkinleştirme:
[EDM_AKTIVASYON_REHBERI.md](EDM_AKTIVASYON_REHBERI.md).

Amaç tek: bir belirti tekrar ortaya çıktığında **nereye bakılacağını** saniyeler
içinde bulmak. Her kayıt aynı sekiz alanı taşır ve `Ctrl+F` ile belirti üzerinden
aranacak şekilde yazılmıştır.

**Bu dosyaya asla yazılmaz:** kullanıcı adı, parola, secret key, session ID, tam
belge UUID'si, tam fatura numarası, SOAP gövdesi.

Modül yolu: `wp-content/plugins/kuka-island-edm/`. Kısaltma olarak aşağıda
`EDM/` kullanılır.

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
- **Kaynak:** EDM teknik desteği 2026-09-03 yazılı cevabı (test ortamı için
  `11111111111` kabul).
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
- **Uygulanan düzeltme:** Merkezî guard: `transmission_evidence()` bir gönderim
  kanıtı görürse (`uuid`, numara, `sent_at`, `sending`/`send_uncertain`
  durumu) `SendInvoice` yolu **kapanır**; yalnız `reconcile_only` okuma yolu
  kalır. Poller `SendInvoice`'a **ulaşamaz**. Sandbox tarafında da aynı ilke:
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
  ölçülmeye zorlanır. **EDM'den çözüm bekliyor:** ya `minOccurs="0"`, ya da
  hangi değerin istendiğinin belirtilmesi.

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
