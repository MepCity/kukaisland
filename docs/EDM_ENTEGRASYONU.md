# EDM Bilişim e-Fatura ve e-Arşiv Entegrasyonu — Aşama 1

Bu doküman, Kuka Island WooCommerce mağazası için hazırlanan **EDM Bilişim e-Fatura (TICARIFATURA / TEMELFATURA)** ve **e-Arşiv Fatura (EARSIVFATURA)** entegrasyonunun Aşama 1 durumunu açıklar.

**Aşama 1 sonucu: gönderim üretimde kapalıdır (fail-closed BLOCKED).** Sebep, mali belge numarasının EDM tarafından nasıl atandığının sözleşme düzeyinde doğrulanmamış olmasıdır. Ayrıntı: [§4 Mali Belge Numarası](#4-mali-belge-numarası--fail-closed).

---

## 1. Kanıt kaynağı

Bu dokümandaki tüm SOAP sözleşme iddiaları, gerçek test WSDL'inden okunmuştur:

```
https://test.edmbilisim.com.tr/EFaturaEDM21ea/EFaturaEDM.svc?singleWsdl
```

- `targetNamespace`: `http://tempuri.org/`
- Servis 55 operasyon yayımlar (`SoapClient::__getFunctions()` sayımı, wp-cli konteynerinden ölçüldü).
- Canlı uç nokta: `https://portal2.edmbilisim.com.tr/EFaturaEDM/EFaturaEDM.svc?singleWsdl` (henüz sözleşme karşılaştırması yapılmadı).

Doğrulama testi WSDL'i ağdan yükler; yükleyemezse ilgili adımları **PASS değil `BLOCKED_WSDL_UNAVAILABLE`** olarak raporlar.

---

## 2. Mimari

```
WooCommerce sipariş akışı (iyzico idempotent callback / settlement)
        │
        ▼
Kuka_Island_Core_Invoice_Queue
  · Action Scheduler / wp-cron, sınırlı üstel geri çekilme (2m, 8m, 32m / maks 3)
  · Mükerrer planlanmış iş engeli (as_has_scheduled_action)
  · is_auto_send_enabled(): auto_send && can_send_invoice() (tam hazırlık sözleşmesi)
  · Kuka_Island_Core_Invoice_Fixture_Guard ile fixture reddi
        │
        ▼
Kuka_Island_Core_Invoice_Manager
  · GET_LOCK('kuka_inv_{order_id}') ile eşzamanlılık kilidi
  · Terminal başarı (completed) kesin kilidi — force ile bile aşılamaz
  · Kesinti sonrası GetInvoiceStatus uzlaştırması (körlemesine yeniden gönderim yok)
  · Kurumsal alıcı için Provider->check_user(); belirsizlikte fail-closed
  · Kuka_Island_Core_Invoice_Numbering ile mali numara çözümü (fail-closed)
        │
        ├──────────────────────────────┬──────────────────────────────┐
        ▼                              ▼                              ▼
Kuka_Island_Core_Invoice_    Kuka_Island_Core_UBL_TR_    Kuka_Island_Core_EDM_
Order_Mapper                 Builder                      Client
· kuruş tamsayı aritmetiği   · UBL-TR 2.1 / TR1.2         · Gerçek WSDL istek/yanıt
· satır bazlı kupon dağıtımı · zorunlu alan yoksa hata    · SECRET_KEY opsiyonel
· fail-closed doğrulamalar   · placeholder üretmez        · oturum düşerse tek retry
                                                          · loglarda sır yok
        │
        ▼
Kuka_Island_Core_Invoice_Order_Store  (HPOS + klasik meta CRUD, durum tarihçesi)
        │
        ▼
Kuka_Island_Core_Invoice_Admin  (salt-okunur durum paneli, terminal faturada gönderim engeli)
```

---

## 3. Doğrulanmış WSDL sözleşmesi

Tüm isteklerde `REQUEST_HEADER/APPLICATION_NAME = ozelyazilim.kukaisland`.

`REQUEST_HEADER` tipi `tns:REQUEST_HEADERType`; kullanılan alanlar `SESSION_ID` (xs:token), `CLIENT_TXN_ID` (xs:token), `ACTION_DATE` (xs:dateTime), `APPLICATION_NAME` (xs:token).

| Operasyon | İstek (WSDL tipi) | Yanıt (WSDL tipi) | Not |
|---|---|---|---|
| `Login` | `LoginRequest` ⊂ `REQUEST` + `USER_NAME`, `PASSWORD`, `SECRET_KEY` (hepsi xs:string, minOccurs=0) | `LoginResponse`: `REQUEST_RETURN`, `SESSION_ID` (xs:token) | `SECRET_KEY` yalnız tanımlıysa gönderilir. Hatalı girişte `edm_auth_failed`; şifre loglanmaz. |
| `Logout` | `LogoutRequest` ⊂ `REQUEST` | `LogoutResponse`: `REQUEST_RETURN` | Yerel oturum temizlenir. |
| `CheckUser` | `CheckUserRequest` ⊂ `REQUEST` + `USER` (`tns:GIBUSER`: `IDENTIFIER`, `DOCUMENTTYPE` … element) | `ArrayOfGIBUSER`: `USER*` (`ALIAS`, `TITLE`, `REGISTER_TIME` …) | Ağ hatasında fail-closed: e-Arşiv varsayılmaz (`check_user_ambiguous`). |
| `CheckCounter` | `CheckCounterRequest` ⊂ `REQUEST` | `CheckCounterResponse`: **`COUNTER_LEFT` (xs:int, minOccurs=1)** | Kalan kontör doğrudan bu alandan okunur. |
| `GetInvoiceSerial` | `GetInvoiceSerialRequest` ⊂ `REQUEST` + `INVOICESERIALCODE` (xs:token), `INVOICESENDTYPE` (xs:token), **`YEAR` (xs:int, zorunlu)** | `GetInvoiceSerialResponse/Items` → `GetInvoiceSerialResponseX/Items*` → `INVOICESERIALLIST`: `INVOICESERIALCODE`, `YEAR`, `SOURCESYSTEMNAME`, `COMPANYNAME`, `COMPANYID`, `LASTINVOICEDATEUSED`, **`LASTSERIALUSED` (xs:int, nillable)** | Salt-okunur. Serinin **durumunu** bildirir; sıradaki numarayı rezerve etmez. |
| `CreateSerial` | `CreateSerialRequest` ⊂ `REQUEST` + `SERIAL` (xs:string), `SERIALTYPE` enum {`EARSIV`, `EFATURA`, `INTERNETSATIS`} | — | **Kasıtlı olarak uygulanmadı.** Seri tescili muhasebe/EDM portalı işidir; otomatik web isteği bunu yapmaz. |
| `SendInvoice` | `SendInvoiceRequest` ⊂ `REQUEST` + `SENDER`(@vkn,@alias), `RECEIVER`(@vkn,@alias), `INVOICE*` (`tns:INVOICE`) | `SendInvoiceResponse`: `REQUEST_RETURN`, `INVOICE*` | Bkz. aşağıdaki `INVOICE` ayrıntısı. |
| `GetInvoiceStatus` | `GetInvoiceStatusRequest` ⊂ `REQUEST` + `INVOICE` (`tns:INVOICE`), `START_DATE`, `END_DATE`, `CR_START_DATE`, `CR_END_DATE` | `GetInvoiceStatusResponse/INVOICE_STATUS` ⊂ `INVOICE` + `STATUS`, `GIB_STATUS_CODE` … | GİB kodları (`1300` vb.) incelenir. |
| `GetInvoice` | `GetInvoiceRequest` ⊂ `REQUEST` + `INVOICE_SEARCH_KEY` (`UUID` **element**), `HEADER_ONLY`, `INVOICE_CONTENT_TYPE` | `ArrayOfINVOICE` | `CONTENT` base64Binary; SoapClient çözer. |
| `EmailInvoice` | `EmailInvoiceRequest` ⊂ `REQUEST` + `INVOICE*` (`tns:INVOICE`), `EMAILS` (xs:token), `INVOICE_CONTENT_TYPE` | `EmailInvoiceResponse`: `REQUEST_RETURN`, `REMARK` | — |

### `tns:INVOICE` — kritik ayrıntılar

Öznitelikler: **`TRXID` (xs:long, `use="required"`)**, `UUID` (xs:token), `ID` (xs:token, **opsiyonel**).

`HEADER` içinde kullandığımız alanlar:

- `SENDER`, `RECEIVER` (xs:token)
- `FROM` (xs:token), **`TO` (xs:token, `minOccurs="0"`)**
- `PROFILEID`, `INVOICE_TYPE`, `ISSUE_DATE`, `PAYABLE_AMOUNT`
- `INTERNETSALES`, `EARCHIVE` (xs:boolean, zorunlu)
- `EARCHIVE_REPORT_SENDDATE`, `CANCEL_EARCHIVE_REPORT_SENDDATE` (xs:date, zorunlu)
- `ISACTIVE`, `MARKED` (xs:boolean)
- **`INVOICESERIAL_REQUESTED` (xs:token, `minOccurs="0"`)**

`CONTENT`: `xmlmime:base64Binary`. Ham UTF-8 UBL XML verilir; SoapClient tek kez base64'ler (çift kodlama yok, test bunu SHA-256 ile ölçer).

### Kademeli kimlik ve yetki seviyeleri

**Login ön koşulu yalnız `has_login_credentials()`'tır.** `Kuka_Island_Core_EDM_Client::login()`
önceden `is_configured()` istiyordu; bu, kullanıcı adı ve parola tam olsa bile gönderici VKN
boşken `edm_not_configured` üretiyor ve `CheckCounter` / `GetInvoiceSerial` gibi salt-okunur
teşhis çağrılarını erişilemez kılıyordu. Kimlik doğrulama artık mali yapılandırmaya bağlı değil.
Sıkı sözleşmeler yerinde: `is_configured()` (kullanıcı adı + parola + gönderici VKN),
`can_send_invoice()` (12 mali alan) ve `is_auto_send_enabled()` değişmedi. `SECRET_KEY` opsiyonel.

1. `has_login_credentials()` — kullanıcı adı + parola. **`Login`, `CheckCounter`,
   `GetInvoiceSerial`, `Logout` bu seviyede çalışır.** `CheckUser` ayrıca gönderici VKN ister.
2. `can_run_read_only_sandbox()` — salt-okunur sorgular için hazır.
3. `can_send_invoice()` — 11 zorunlu alan: kullanıcı adı, parola, gönderici VKN, gönderici etiket (alias), her iki fatura serisi (`/^[A-Z0-9]{3}$/`), şirket unvanı, vergi dairesi, adres, ilçe, şehir.
   **Posta kodu zorunlu değildir** — §5.1.
4. `is_auto_send_enabled()` — `auto_send` açık **ve** `can_send_invoice()` tam. Eksik tek alan siparişi kuyruğa sokmaz.
5. `check_live_readiness()` — canlıya geçiş öncesi eksik alan listesi.

---

## 4. Mali belge numarası — fail-closed

**Sipariş ID'sinden veya yerel sayaçtan numara üretimi tamamen kaldırıldı.**

WSDL'in kanıtladığı şey:

- `CreateSerial` bir seri ön ekini tescil eder (tek seferlik provizyon).
- `GetInvoiceSerial` tescilli serileri ve `LASTSERIALUSED` değerini **bildirir**; sıradaki numarayı vermez veya rezerve etmez.
- `INVOICE/HEADER/INVOICESERIAL_REQUESTED` alanı belgeyi tescilli bir seriye bağlar ve `INVOICE/@ID` opsiyoneldir — yani numarayı EDM'nin ataması sözleşmeyle uyumludur.

WSDL'in kanıtlamadığı şey: EDM'nin atadığı numarayı gönderilen `CONTENT` içindeki UBL `cbc:ID` alanına geri yazıp yazmadığı. UBL-TR 2.1 TR1.2 `cbc:ID`'yi zorunlu tutar ve bu değer yerel olarak **uydurulmadan** üretilemez.

Bu nedenle:

- `Kuka_Island_Core_Invoice_Numbering::resolve_assigned_number()` yalnız **EDM kaynaklı** bir numarayı kabul eder.
- Kaynak kanıtı zorunludur: `_kuka_invoice_number` yanında `_kuka_invoice_number_source = 'edm'` meta'sı bulunmalıdır. Bu meta yalnız `save_invoice_sent()` ve `save_status_query()` tarafından, EDM yanıtından gelen numara için yazılır.
- Aksi hâlde `Kuka_Island_Core_Invoice_Permanent_Exception` / `invoice_numbering_unconfirmed` atılır, sipariş `blocked` durumuna geçer ve `SendInvoice` **hiç çağrılmaz**.
- `Kuka_Island_Core_Invoice_Queue::process_queued_order()` bu `blocked` durumunu `needs_manual_review` ile ezmez.

### Kaldırılan üretimin bıraktığı veri (mevcut DB'de)

Reddedilen sürümün testleri gerçek siparişlere uydurma numara yazmış:

| Sipariş | `_kuka_invoice_number` | `_kuka_invoice_status` |
|---|---|---|
| 964 | `KUK2026000000001` | `sent` |
| 967 | `KUK2026000000777` | `completed` |
| 973 | `KUK2026000000777` | `completed` |
| 981 | `KUK2026000000777` | `completed` |
| 989 | `KUK2026000000777` | `completed` |

Dört sipariş **aynı** mali belge numarasını taşıyor. Bu, kaldırılan numaralandırmanın tek başına yeterli kanıtı. Kayıtlara dokunulmadı (koruma kuralı); yerine kaynak kanıtı zorunluluğu getirildi, böylece bu numaralar hiçbir koşulda gönderime giremez.

**Aşama 2 için gereken:** EDM canlı/test hesabıyla `GetInvoiceSerial` üzerinden tescilli seri doğrulanması ve `INVOICESERIAL_REQUESTED` ile gönderimde `cbc:ID` davranışının EDM'den yazılı/ölçülü teyidi.

---

## 5. Mali veri: fail-closed, placeholder yok

Üretim UBL yolundan kaldırılan uydurma varsayılanlar:

| Kaldırılan | Nerede | Yerine |
|---|---|---|
| `'defaultpk'` alıcı etiketi | `class-invoice-manager.php::resolve_routing()` | e-Arşiv'de `receiver_alias = ''`; `RECEIVER/@alias` ve `HEADER/TO` hiç yazılmaz |
| `?? 'İstanbul'` şehir | `class-ubl-tr-builder.php` | `ubl_missing_field` hatası |
| `?? 'Kuka Island'` unvan | `class-ubl-tr-builder.php` | `ubl_missing_field` hatası |
| `?? '1111111111'` gönderici VKN | `class-ubl-tr-builder.php` (Signature) | `ubl_missing_field` hatası |
| `?? '11111111111'` alıcı VKN | `class-ubl-tr-builder.php` | `ubl_missing_field` hatası |
| `?? 10` KDV oranı | `class-ubl-tr-builder.php` (satır) | `ubl_missing_field` hatası |
| Rastgele `AdditionalDocumentReference/ID` | `class-ubl-tr-builder.php` | Fatura UUID'i (yeniden üretimde XML aynı kalır) |

`Kuka_Island_Core_UBL_TR_Builder::required()` zorunlu her alanı denetler; eksikse belge üretilmez.

### 5.1 Gönderici posta kodu opsiyoneldir

**Kanıt.** EDM'in kendi gönderdiği `XML ÖRNEKLERİ` paketindeki **on altı** örnek faturanın
(`satis_temel.xml` dahil) hiçbirinde satıcının `cac:PostalAddress` bloğunda `cbc:PostalZone`
yok. Bağımsız olarak tarandı; hepsinde alt eleman kümesi şu:

```
cbc:BuildingName, cbc:CitySubdivisionName, cbc:CityName, cbc:IdentificationCode, cbc:Name
```

EDM test portalında **Tanımlar → Firmalarım → Görüntüle/Güncelle** ekranında posta kodu alanı
da bulunmuyor. Yani değer, uydurulmadan hiçbir kaynaktan alınamıyor ve EDM açısından zorunlu
olduğu iddia edilemez.

**Sözleşme.**

| Yer | Davranış |
|---|---|
| `can_send_invoice()` / `get_send_readiness_gaps()` | Posta kodu **gap değil** (11 zorunlu alan kaldı) |
| `check_live_readiness()` | `KUKA_LEGAL_POSTCODE` eksik listesinden çıkarıldı |
| Sandbox `kuka_sandbox_verify_sender()` | `required_company` yedi alan; posta kodu yok |
| Production order mapper `get_supplier_data()` | Posta kodu eksikse **artık reddetmiyor**; diğer altı alan hâlâ fail-closed |
| UBL üretimi | Doluysa `cbc:PostalZone` **aynen** yazılır; boşsa eleman **tamamen atlanır** — boş düğüm şema ihlalidir, nötr boşluk değil |
| Müşteri/fatura adresi posta kodu | **Değişmedi** |

Ölçüm:

```
INVOICE_SUPPLIER_POSTCODE_OPTIONAL=PASS|with_postcode:present|value_roundtrip:exact|without_postcode:omitted|empty_node_emitted:no|supplier_fields_missing:none|customer_postal_zone:unchanged
INVOICE_MAPPER_POSTCODE_OPTIONAL=PASS|missing_postcode:accepted|postcode_value:empty|missing_city:missing_supplier_configuration
```

Posta kodu boşken üretilen UBL'de satıcının `StreetName`, `CitySubdivisionName`, `CityName`,
`Country/Name`, `PartyIdentification/ID`, `PartyName/Name` ve `PartyTaxScheme/TaxScheme/Name`
alanlarının hepsi dolu kalır. Bu değişiklik yalnız posta kodu kapsamındadır; seri,
numaralandırma ve otomatik gönderim kapıları değişmedi.

### e-Arşiv alıcı etiketi politikası

WSDL kanıtı:

- `SendInvoiceRequest/RECEIVER` içinde `vkn` ve `alias` **opsiyonel `xs:attribute`**.
- `INVOICE/HEADER/TO` `minOccurs="0"`.
- e-Arşiv akışı `INVOICE/HEADER/EARCHIVE` (xs:boolean) ve `EARCHIVE_REPORT_SENDDATE` ile tanımlanır.

e-Arşiv alıcısının GİB posta kutusu yoktur; alanın atlanması şema geçerlidir. Uydurma bir posta kutusu etiketi yazmak değildi ve kaldırıldı. e-Fatura tarafında alias `CheckUser`'dan gelir ve eksikse `missing_recipient_alias` ile fail-closed davranılır.

### Genel bireysel VKN politikası

`KUKA_EDM_ALLOW_GENERIC_INDIVIDUAL_VKN` **tanımsızsa varsayılan `false`**. `11111111111` yalnız sabit **literal `true`** olduğunda kullanılır; `'1'` gibi truthy değerler politikayı açmaz. Kapalıyken TCKN'siz bireysel siparişte `missing_individual_tckn` atılır.

---

## 6. Kupon ve KDV matematiği

Yaklaşım: **satır bazlı iskonto (line-level allowance)**.

- Kupon/indirim belge düzeyinde `AllowanceTotalAmount` olarak **yazılmaz**. WooCommerce'in fiilen tahsil ettiği biçimde satırlara atfedilir: `allowance_i = subtotal_i − total_i`. Yeniden dağıtım sezgiseli kullanılmaz, dolayısıyla tahsil edilen tutardan sapma olmaz.
- `InvoiceLine/LineExtensionAmount` net (indirim sonrası) tutardır; indirimli her satır kendi `cac:AllowanceCharge` (`ChargeIndicator=false`, `BaseAmount` = brüt) öğesini taşır.
- Kargo belge düzeyinde `ChargeTotalAmount` olarak kalır.
- `TaxExclusiveAmount = LineExtensionAmount + ChargeTotalAmount`. `AllowanceTotalAmount` kasıtlı olarak yoktur; indirim iki kez düşülmez.
- **Üretilen her `TaxSubtotal` için** (belge düzeyi ve satır düzeyi):

  ```
  TaxAmount = round_half_up( TaxableAmount × Percent / 100 )    [kuruş tamsayı]
  ```

  Tek tanım: `Kuka_Island_Core_Invoice_Order_Mapper::tax_from_taxable()`. Eşitlik tasarım gereği sağlanır, tesadüfen değil.
- `TaxTotal/TaxAmount` = belge düzeyi `TaxSubtotal` toplamı.
- `TaxInclusiveAmount = TaxExclusiveAmount + TaxTotal/TaxAmount`.

### Kuruş dağıtım artığı ve `PayableRoundingAmount`

WooCommerce vergiyi **satır başına** yuvarlar; bu fatura ise her oran kovasının **toplam matrahından** hesaplar. İki yöntem, kova üyesi başına en fazla bir yuvarlama adımı kadar ayrışabilir. UBL-TR 2.1 bu farkı tam olarak `cbc:PayableRoundingAmount` ile modeller:

```
PayableAmount = TaxInclusiveAmount + PayableRoundingAmount
```

Böylece hem her `TaxSubtotal` eşitliği tam sağlanır, hem `PayableAmount` tahsil edilen tutarla birebir aynı kalır. `LegalMonetaryTotal` sırası UBL 2.1 dizisine uyar: `LineExtensionAmount`, `TaxExclusiveAmount`, `TaxInclusiveAmount`, `ChargeTotalAmount`, `PayableRoundingAmount`, `PayableAmount`.

Yuvarlama sınırı mağazanın kendi para adımından türetilir:

```
granularity  = 10^(2 − wc_get_price_decimals())   [kuruş]
bound        = (vergili kova üyesi sayısı + 1) × granularity
```

Sınır aşılırsa bu bir yuvarlama artığı değildir: `payable_total_mismatch` ile fail-closed.

### Diğer fail-closed mali denetimler

| Kod | Koşul |
|---|---|
| `discount_allocation_mismatch` | `Σ(subtotal_i − total_i) ≠ WC_Order::get_discount_total()` |
| `payable_total_mismatch` | Hesaplanan tutar ile tahsil edilen tutar farkı yuvarlama sınırının üstünde |
| `missing_tax_rate` | Vergisi olan satırın doğrulanmış KDV oranı çözülemiyor |
| `missing_shipping_tax_rate` | Vergili kargonun oranı çözülemiyor |
| `invalid_line_discount` | Satır net tutarı brütten büyük |
| `invalid_shipping_total` | Negatif kargo tutarı |
| `missing_order_currency`, `missing_order_date` | Zorunlu sipariş alanı yok |

### Düzeltilen oran ayrıştırma hatası

Eski kod `preg_replace('/\D/', '', '10.0000')` ile oranı **100000** yapıyordu. Yeni `normalize_percent()` sayısal ayrıştırma yapar, `0–100` aralığını doğrular ve tamsayı olmayan oranda `null` döner (fail-closed). Ölçülen davranış: `'10.0000' → 10`, `'20' → 20`, `'0.0000' → 0`, `'8.5000' → null`, `'' → null`, `'abc' → null`.

---

## 7. Mükerrer fatura ve kesinti koruması

1. **Terminal kilit** — `completed` faturada `SendInvoice` sunucu tarafında kesin engellidir; panel veya `force` parametresi bunu aşamaz (`already_terminal_invoice`).
2. **Advisory lock** — sipariş başına `SELECT GET_LOCK('kuka_inv_{order_id}', 0)`; eşzamanlı isteklerde transport `SendInvoice` sayısı 1 ile sınırlıdır.
3. **Uzlaştırma** — `sending` / `sent` / `pending_approval` / `send_uncertain` durumlarında körlemesine yeniden gönderim yok; önce UUID üzerinden `GetInvoiceStatus`.
4. **Belirsiz ağ hatası** — gönderim sırasındaki zaman aşımı `send_uncertain` yazar (`needs_manual_review` değil), sonraki deneme uzlaştırmaya düşer.
5. **Uzlaştırma da başarısızsa** — `SendInvoice` yine çağrılmaz; transient hata ile beklenir.

---

## 8. Test fixture koruması ve sınırının doğru ifadesi

`Kuka_Island_Core_Invoice_Fixture_Guard`:

- `final` sınıf, `public static` karar metodu → **alt sınıf ile geçersiz kılınamaz**.
- Kararı hem `Kuka_Island_Core_Invoice_Queue::maybe_enqueue_order()` hem `Kuka_Island_Core_Invoice_Manager::process_order()` kullanır. Kuyruk artık korumalı bir manager metodunu çağırmıyor — reddedilen sürümdeki otomatik gönderim fatal'i buradan geliyordu.
- Kapatan hiçbir toggle, filter, option veya sabit yok. Test bunu kaynak taramasıyla da ölçer (`enable_test_mode`, `set_test_allowed_run_id`, `allow_test_fixture`, `disable_fixture_guard`, `bypass_fixture` → 0 eşleşme, 17 modül dosyası tarandı).
- İşaret: `_kuka_test_fixture` meta'sı. Bu meta'yı taşıyan sipariş hiçbir koşulda gerçek fatura kesemez.

**Sınırın doğru ifadesi:** Doğrulama testi artık fixture guard'ı geçersiz kılan bir manager alt sınıfı kullanmıyor. Kaldırıldı. Durum makinesi testleri **üretim `Kuka_Island_Core_Invoice_Manager` sınıfıyla**, `_kuka_test_fixture` işareti taşımayan siparişler üzerinde çalışır; bu siparişler yalnız temizlik için `_kuka_isolation_run_id` taşır ve ağa çıkmayan bir stub transport kullanılır. Dolayısıyla "üretimde bypass edilemez" iddiası artık bir test sınıfının nezaketine değil, `final` + `static` yapıya ve toggle yokluğuna dayanıyor.

---

## 9. Test izolasyonu

`scripts/verify-invoice-integration.php` ve `scripts/verify-invoice-keyset.php`:

- **Dört durumlu temizlik koordinatörü:** `idle → running → succeeded | failed`. `running` veya terminal durumda ikinci çağrı reddedilir (`reentry_blocked`).
- **DB'den keşfedilebilirlik:** her fixture `_kuka_isolation_run_id` taşır. Koordinatör kayıtları hem bellekteki kayıttan hem `wc_orders_meta` + `postmeta` sorgusundan bulur; ölümcül hata bellekteki listeyi kaybetse bile temizlik çalışır (`register_shutdown_function`).
- **Ownership reddi:** run ID eşleşmezse silme reddedilir, durum `failed` olur ve süreç `kuka_cleanup_exit_code('failed') = 1` ile non-zero çıkar. Test bunu gerçek koordinatör üzerinden ölçer.
- **Takip dışı kayıt yok:** testteki "foreign order" da kendi run ID'si ile takip edilir ve sahibi tarafından temizlenir.
- **Kuyruk planlama artığı:** kontrol senaryosunun ürettiği Action Scheduler satırı silinir; kalan satır sayısı 0 olarak ölçülür.
- **Keyset kapsamı (12 tablo):** `wc_orders`, `wc_orders_meta`, `wc_order_addresses`, `wc_order_operational_data`, `woocommerce_order_items`, `woocommerce_order_itemmeta`, sipariş notları (`comments`), `wc_order_stats`, `wc_customer_lookup`, `wc_order_product_lookup`, `wc_order_tax_lookup`, `wc_order_coupon_lookup`.
- Keyset hem test içinde (aynı süreç) hem `verify.sh` içinde **dış süreçten** (test öncesi/sonrası) karşılaştırılır.

---

## 10. Doğrulama durumu

Aşağıdaki sonuçlar `docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-invoice-integration.php` ve `make verify` çıktısından alınmıştır.

| Kapsam | Durum | Kanıt |
|---|---|---|
| PHP ext-soap ve Docker katmanı | **PASS** | `INVOICE_SOAP_EXTENSION_AVAILABLE=PASS` |
| Kimlik gizliliği ve VKN maskeleme | **PASS** | `INVOICE_CONFIG_SECURITY=PASS` |
| Genel bireysel VKN varsayılanı `false` | **PASS** | `INVOICE_GENERIC_VKN_DEFAULT_FALSE`, `..._STRICT_TRUE_ONLY`, `..._RUNTIME_BEHAVIOUR` |
| Auto-send tam hazırlık sözleşmesi | **PASS** | `INVOICE_AUTO_SEND_FULL_READINESS_CONTRACT=PASS\|fields_checked:11\|leaks:none\|postcode_optional:yes` |
| Fixture guard — gerçek runtime yolu | **PASS** | `INVOICE_QUEUE_FIXTURE_GUARD_RUNTIME_PATH`, `INVOICE_MANAGER_FIXTURE_GUARD`, `INVOICE_FIXTURE_GUARD_NOT_OVERRIDABLE` |
| SOAP sözleşmesi — üretim client üzerinden | **PASS** | 11 operasyon, `INVOICE_SOAP_OPS_VIA_PRODUCTION_CLIENT=PASS` |
| UBL CONTENT tek base64 | **PASS** | `single_base64_sha256_match:yes` |
| Kupon/KDV kuruş değişmezleri | **PASS** | `INVOICE_COUPON_VAT_KURUS_INVARIANTS=PASS\|scenarios:7\|tax_subtotals_checked:21` |
| Mağazanın kendi hassasiyetinde matematik | **PASS** | `INVOICE_COUPON_VAT_NATIVE_SHOP_PRECISION=PASS\|shop_decimals:0\|granularity_cents:100` |
| Mali veri fail-closed | **PASS** | `INVOICE_UBL_BUILDER_FAIL_CLOSED=PASS\|cases:8`, `INVOICE_MONETARY_NEGATIVE_TESTS=PASS` |
| Mükerrer fatura güvenliği | **PASS** | 6 senaryo; terminal ve uzlaştırma kilitlerinde `SendInvoice:0` |
| DB keyset izolasyonu (iç + dış) | **PASS** | `tables:12\|diff:none`, `INVOICE_EXTERNAL_ISOLATION=keyset_match:yes` |
| Temizlik durum makinesi | **PASS** | `..._OWNERSHIP_REFUSAL`, `..._REENTRY_GUARD`, `..._STATE_MACHINE_PROBE`, `..._STATE_MACHINE_MAIN` |
| **Mali belge numarası sözleşmesi** | **BLOCKED** | `invoice_numbering_unconfirmed` — bkz. §4 |
| Gerçek EDM sandbox salt-okunur sorgu | **BLOCKED** | `REAL_EDM_LOGIN=BLOCKED\|reason:no_runtime_credentials` (kimlik verilmedi) |
| Gerçek EDM sandbox e-Fatura kesimi | **BLOCKED** | Numaralandırma sözleşmesi + muhasebe onayı bekliyor |
| Canlı GİB entegrasyon onayı | **BLOCKED** | Mali müşavir ve EDM canlı ortam onayı bekliyor |

`SendInvoice` doğrulama akışlarında **hiç** çağrılmaz: `REAL_EDM_SEND_INVOICE=SKIPPED|reason:read_only_verification_never_sends`.

---

## 11. Aşama 2 öncesi kapatılması gereken mağaza yapılandırması

Doğrulama sırasında ölçülen, entegrasyon kodunun dışındaki iki engel:

1. **WooCommerce vergi oranı tanımlı değil.** `{prefix}woocommerce_tax_rates` boş; mevcut siparişlerin `tax_amount` değerleri `0`. Bugün kesilecek bir fatura %0 KDV ile çıkar. Perakende satış için doğru KDV oranlarının tanımlanması gerekir.
2. **Fiyat ondalığı 0** (`woocommerce_price_num_decimals = 0`). Mağaza tam lira tahsil ediyor. Kod bunu `PayableRoundingAmount` ve `granularity` ile doğru şekilde taşıyor; ancak muhasebenin bu yuvarlama politikasını yazılı onaylaması gerekir.

Bu iki maddeye karar verilmeden Aşama 2 gönderimi açılmamalıdır.

---

## 12. Kimlik bilgilerinin yerel aktarımı

Kimlikler kaynak koda, Git'e, `.env`/`.env.example`'a veya komut satırı argümanına **yazılmaz**.

Kanonik konum: `~/.config/kuka-island/edm-test.env`, mod **600**, **git çalışma ağacının dışında**.
Dosya depo içinde olmadığı için `git add` ona hiçbir şekilde erişemez — bu, kural tabanlı bir
filtreden değil, konumdan gelen kesin bir garantidir.

Katmanlı savunma (depo içine yanlışlıkla kopyalanma senaryosu için):

| Katman | Ne yapar |
|---|---|
| `~/.config/kuka-island/edm-test.env` | Çalışma ağacı dışında → `git add` erişemez |
| `.gitignore` | `edm-test.env`, `*.edm-test.env`, `*edm-credentials*` desenleri (`scripts/` allow-list'inden **sonra**, son eşleşen kural kazanır) |
| `.git/info/exclude` | Aynı desenler, yalnız yerel, commit edilmez, pull ile ezilmez |
| `.git/hooks/pre-commit` | Kimlik dosyası veya literal `KUKA_EDM_(USERNAME\|PASSWORD\|SECRET_KEY)=<değer>` staged ise commit'i reddeder. Yerel, push edilmez. |

**Konteynere aktarım: bind mount, ortam değişkeni değil.**
`docker compose run -e VAR` ile geçirilen değerler, konteyner nesnesi var olduğu sürece
`docker inspect` çıktısından **okunabilir**. Bu yüzden kimlik dosyası salt-okunur bind mount ile
`/run/edm/edm-test.env` yoluna bağlanır; değerler konteyner ortamına hiç girmez.

Üretim kodu değişmedi: eklenti kimlikleri hâlâ yalnız `wp-config` sabitlerinden veya süreç
ortamından okur. Kimlik dosyasını okuyan `scripts/lib-edm-test-credentials.php` yalnız test
scriptleri tarafından kullanılır ve değerleri `Kuka_Island_Core_Invoice_Config` yapıcı
override'ı olarak geçirir. Yükleyici hiçbir değeri yazdırmaz; yalnız boolean **varlık** haritası
döndürür.

**Çalıştırıcı allow-list.** `scripts/edm-test-run.sh` kimlik dosyasını **yalnız tek bir**
salt-okunur scripte mount eder: `test-edm-sandbox.php`. Script adı kullanıcı/typo kontrollü bir
girdi olduğu için mutlak yol, `..`, `/` içeren isim, beklenmeyen karakter ve allow-list dışındaki
her değer **kimlik kapısına ulaşmadan** reddedilir. Sandbox yazma aracı buradan çalıştırılamaz;
kendi ayrı `scripts/edm-sandbox-run.sh` wrapper'ı vardır. Ölçüm: `EDM_RUNNER_ALLOWLIST=leaks:0`.

**Değerler birebir saklanır.** Dosya formatı `KEY=value`; değer, **ilk** `=` işaretinden sonraki
her şeydir. Trim edilmez, tırnak soyulmaz, içindeki `=` karakterleri korunur. Yalnız CRLF
dosyalarındaki sondaki CR atılır. Böylece boşluk veya `=` içeren bir parola bozulmadan geçer.
Yazıcı script `stty -echo` ile okur, `trap` ile her çıkış yolunda terminal echo'sunu geri açar ve
dosyayı temp + `rename` ile atomik yayımlar — kesilen bir koşu yarım kimlik dosyası bırakmaz.

Alanlar:

| Anahtar | Zorunlu | Kullanım |
|---|---|---|
| `KUKA_EDM_USERNAME` | evet | Login |
| `KUKA_EDM_PASSWORD` | evet | Login |
| `KUKA_EDM_SECRET_KEY` | opsiyonel | Login |
| `KUKA_EDM_SENDER_VKN` | CheckUser için | CheckUser, gönderici kimliği |
| `KUKA_EDM_SENDER_ALIAS` | sandbox için | Alias birebir eşleşme kontrolü |
| `KUKA_EDM_SENDER_TITLE` | sandbox için | UBL gönderici unvanı |
| `KUKA_EDM_SENDER_TAX_OFFICE` | sandbox için | UBL vergi dairesi |
| `KUKA_EDM_SENDER_ADDRESS` | sandbox için | UBL adres |
| `KUKA_EDM_SENDER_DISTRICT` | sandbox için | UBL ilçe |
| `KUKA_EDM_SENDER_CITY` | sandbox için | UBL şehir |
| `KUKA_EDM_SENDER_POSTCODE` | **opsiyonel** | Doluysa `cbc:PostalZone` üretilir, boşsa eleman hiç yazılmaz — §5.1 |
| `KUKA_EDM_SERIES_EARCHIVE` | **opsiyonel** | Verilirse biçimi **ve** EDM'deki tescili doğrulanır; tescil gözlemlenemezse BLOCKED. Verilmezse taslak deneyi yine koşar; numarayı EDM atar |
| `KUKA_EDM_SERIES_EINVOICE` | opsiyonel | — |
| `KUKA_EDM_SANDBOX_RECEIVER_VKN` | **opsiyonel override** | Boşsa resmî EDM örneğindeki örnek alıcı kimliği fixture olarak kullanılır. Verilirse biçim + güvenlik doğrulaması yapılır; hatalıysa BLOCKED (varsayılana sessizce düşmez) |
| `KUKA_EDM_SANDBOX_PROFILE_ID` | **opsiyonel override** | Boşsa resmî EDM örneğindeki `PROFILEID` kullanılır. Verilirse biçim doğrulaması yapılır; hatalıysa BLOCKED |

`--status` çıktısı yalnız `supplied` / `absent` gösterir; hiçbir değer, uzunluk veya parça yazılmaz.

Kurulum ve çalıştırma:

```bash
./scripts/edm-test-credentials.sh            # gizli giriş, ekrana/history'ye yazmaz
./scripts/edm-test-credentials.sh --status   # yalnız varlık/izin bilgisi
./scripts/edm-test-run.sh test-edm-sandbox.php
```

`application_name` her istekte `ozelyazilim.kukaisland`; probe bunu ayrıca doğrular.

---

## 12.5 Login REQUEST_HEADER sözleşmesi ve güvenli hata sınıflandırması

### 12.5.1 Kök neden: eksik REQUEST_HEADER

`Login` gerçek EDM test endpoint'inde `edm_login_fault` veriyordu. Sebep kimlik bilgisi
değildi: istemci `REQUEST_HEADER` içinde yalnız **üç** alan gönderiyordu
(`ACTION_DATE`, `CLIENT_TXN_ID`, `APPLICATION_NAME`).

Güncel test WSDL'i (`tns:REQUEST_HEADERType`, `xs:sequence`, hepsi `minOccurs="0"`):

```
SESSION_ID, CLIENT_TXN_ID, INTL_TXN_ID, INTL_PARENT_TXN_ID, ACTION_DATE,
CHANGE_INFO, REASON, APPLICATION_NAME, HOSTNAME, CHANNEL_NAME,
SIMULATION_FLAG, COMPRESSED, ATTRIBUTES
```

EDM'in resmî zarfı bunlardan sekizini doldurur. `Kuka_Island_Core_EDM_Client::build_request_header()`
artık tam olarak bu sekizini üretiyor:

| Alan | Değer |
|---|---|
| `SESSION_ID` | Login'de literal `0` (henüz oturum yok), diğerlerinde gerçek oturum |
| `CLIENT_TXN_ID` | Yeni UUID. **İstisna:** `SendInvoice` belge UUID'sini geçirir — EDM'in gördüğü idempotency anahtarı odur |
| `ACTION_DATE` | `Y-m-d\TH:i:s` (UTC) |
| `REASON` | Operasyon adı (`Login`, `CheckCounter`, `GetInvoiceSerial`, …) |
| `APPLICATION_NAME` | Her zaman `ozelyazilim.kukaisland`. İstekten, host'tan veya kullanıcı girdisinden **türetilmez** |
| `HOSTNAME` | Sabit `kukaisland` etiketi. Gerçek makine adı iç altyapıyı sızdırır, istekten gelen `Host` başlığı saldırgan kontrollüdür; ikisi de kullanılmaz |
| `CHANNEL_NAME` | Sabit `WEB` |
| `COMPRESSED` | Literal `N` — yük gzip'lenmiyor |

Endpoint notu: EDM'in maildeki `/EFaturaEDM21/` adresi 404 veriyor. Aktif olan
`/EFaturaEDM21ea/EFaturaEDM.svc` ve WSDL'in kendi `soap:address` değeri de bunu gösteriyor;
§13.1 allow-list'i zaten yalnız bunu kabul ediyor.

### 12.5.2 Güvenli SOAP hata sınıflandırması

Bir `SoapFault` mesajı **güvenilmeyen uzak metindir**: isteği geri alıntılayabilir (kullanıcı
adı dahil), çevrilmemiştir ve kararlı değildir. Bu yüzden hiçbir çıktıya, log satırına, sipariş
notuna veya veritabanına yazılmaz.

`Kuka_Island_Core_EDM_Fault_Classifier` mesajı **sabit bir kelime dağarcığına** indirger:

| Alan | İçerik |
|---|---|
| `category` | `credentials_rejected`, `session_invalid`, `endpoint_not_found`, `request_contract_rejected`, `remote_server_error`, `network_timeout`, `tls_failure`, `http_transport_failure`, `unclassified_fault` |
| `fault_kind` | Faultcode katlanmış hâli: `http`, `client`, `server`, `wsdl`, `protocol`, `none`, `other` |
| `marker` | **Eşleşen grubun adı** (`authentication`, `timeout`, `http_not_found`, …) — eşleşen metin değil |
| `retryable` | Yeniden denemenin makul olup olmadığı. **Yalnız gerçek boolean** kabul edilir |

Alan sayısı tam olarak dörttür. **Mesajın digest'i üretilmez:** geri yansıtılmış parola
içerebilen bir metnin hash'i, parola tahminini çevrimdışı doğrulamaya yarayan bir oracle olur;
"geçen koşuyla aynı hata mı" kolaylığı bunu karşılamaz.

Sınıflandırma sırası önce taşıma katmanı kanıtına bakar (404, timeout, TLS, 5xx), çünkü bunlar
kimlik doğrulama gibi görünen kelimeler taşıyabilir. Hiçbir işaretçi eşleşmezse **sessizlikten
kimlik doğrulama sonucu uydurulmaz**; yalnız faultcode'a göre karar verilir.

Sonuç `Kuka_Island_Core_Invoice_Exception::set_diagnostic()` ile istisnaya iliştirilir ve
`get_safe_diagnostic_line()` ile basılır.

**Kapalı allow-list.** Dört alanın her biri kapalı bir listeden gelir; liste dışı, eksik veya
yanlış tipteki her değer sabit güvenli varsayılana çöker ve fazladan anahtarlar düşürülür:

| Alan | Kabul edilen | Güvensiz girdide |
|---|---|---|
| `category` | dokuz `CAT_*` sabitinden biri | `unclassified_fault` |
| `fault_kind` | `http`, `client`, `server`, `wsdl`, `protocol`, `none`, `other` | `other` |
| `marker` | marker tablosundaki adlar veya `none` | `none` |
| `retryable` | yalnız `true`/`false` (katı) | `false` |

`retryable` için güvenli varsayılan `false`'tur: bilinmeyen bir karar, sonucu kimsenin
saptamadığı bir işlemin yeniden denenmesini savunamamalıdır.

Normalizasyon üç yerde birden uygulanır — `normalize()` tek boğaz noktasıdır ve
`set_diagnostic()` (girişte), `get_diagnostic()` (çıkışta, alt sınıf property'ye doğrudan
yazabileceği için) ve `to_safe_line()` (public olduğu için `set_diagnostic()`'ten geçmemiş
dizi alabilir) hepsi onu çağırır. Bu yüzden çıktı satırı **yalnız allow-list token'larından**
kurulur ve uzak metnin sızması yapısal olarak mümkün değildir:

```
category:<allow-listed>|fault_kind:<allow-listed>|marker:<allow-listed>|retryable:yes|no
```

Adversarial test (`INVOICE_DIAGNOSTIC_INJECTION_REFUSED`) dört alanın her birine sırayla
kullanıcı adı, parola, session ID, VKN ve secret key enjekte eder; ayrıca tüm alanları birden,
iç içe dizi, nesne, truthy-string ve sayısal `retryable`, büyük harf ve dolgulu varyant dener.
27 vakanın hiçbirinde bu değerler `getMessage()`, `get_safe_error_code()`,
`get_user_message()`, `get_diagnostic()` veya `get_safe_diagnostic_line()` yüzeyinde
görünmez.

Kategoriden istisna tipine eşleme: `credentials_rejected`, `request_contract_rejected`,
`endpoint_not_found`, `session_invalid` → **kalıcı**; `network_timeout`, `tls_failure`,
`remote_server_error`, `http_transport_failure`, `unclassified_fault` → **geçici** (kuyruk
yeniden deneyebilir).

### 12.5.3 Ölçülen gerçek sonuç

`./scripts/edm-test-run.sh test-edm-sandbox.php` — EDM **test** endpoint'i, yalnız salt-okunur:

```
REAL_EDM_WSDL=PASS|environment:test|application_name_ok:yes
REAL_EDM_LOGIN=PASS|session_obtained:yes
REAL_EDM_CHECK_COUNTER=PASS|counter_left:<sayı>
REAL_EDM_GET_INVOICE_SERIAL=PASS|unfiltered_registered_serials:<sayı>|…
REAL_EDM_CHECK_USER=BLOCKED|reason:no_sender_vkn_supplied
REAL_EDM_LOGOUT=PASS|session_closed:yes
REAL_EDM_WRITE_OPERATIONS=NONE|send_invoice:0|load_invoice:0|create_serial:0|email_invoice:0
```

`CheckUser` gönderici VKN gerektirir; kimlik dosyasında yoksa **BLOCKED** yazılır, sahte PASS
üretilmez. Oturum her koşuda `Logout` ile kapatılır.

Yalnız `Login`, `CheckCounter`, `GetInvoiceSerial` ve `Logout` gerçek EDM'ye karşı ölçülmüştür.
Aynı başlık `SendInvoice`, `GetInvoice`, `EmailInvoice` ve `GetInvoiceStatus` için de üretilir
fakat **bu operasyonlar EDM'ye karşı çalıştırılmamıştır**; kendi kapıları arkasında BLOCKED
kalmaya devam eder.

---

## 13. İzole sandbox TASLAK YÜKLEME deneyi

`scripts/edm-sandbox-invoice.php` + `scripts/edm-sandbox-run.sh`, §4'teki numaralandırma
sorularını EDM **test** ortamında ölçmek için kurulmuş ayrı bir araçtır.

### 13.0 `LoadInvoice` ile `SendInvoice` aynı şey değildir

| Operasyon | Ne yapar | Bu araç çağırır mı |
|---|---|---|
| `LoadInvoice` | Belgeyi EDM'ye **taslak** olarak yükler; daha sonra gönderilmek üzere saklanır. Alıcıya hiçbir şey iletilmez, fatura kesilmez | **Evet** — tek yazma çağrısı budur |
| `SendInvoice` | Daha önce yüklenmiş taslağı UUID ile **gerçekten gönderir** | **Hayır** — bu turun kapsamı dışında |

Kaynaklar:
[LoadInvoiceRequest](https://docs.edmbilisim.com.tr/api/api-documentation/einvoice/referenced/EFaturaEDMConnectorService.LoadInvoiceRequest.html) ·
[SendInvoiceRequest](https://docs.edmbilisim.com.tr/api/api-documentation/einvoice/referenced/EFaturaEDMConnectorService.SendInvoiceRequest.html) ·
[e-Fatura SOAP zarfları](https://docs.edmbilisim.com.tr/api/api-documentation/einvoice/efatura-soap-envelopes.html)

Bu ayrım çıktıda da tutulur: başarılı bir koşu `SANDBOX_DRAFT_UPLOAD=PASS|effect:draft_upload_only`
ve `result:draft_uploaded` yazar — **"gönderildi" veya "kesildi" değil**. Her çıkış yolunda
tam olarak bir kez `SANDBOX_SENDINVOICE=NOT_EXECUTED|reason:out_of_scope_this_round|documents_sent:0|recipient_delivery:none`
basılır. Harness (`SANDBOX_LOAD_VS_SEND_SEMANTICS`) transport'a geçen operasyon adlarını tarar ve
`LoadInvoice` dışında bir ada izin vermez.

### 13.1 Gerçek endpoint doğrulaması

`is_live()` yalnız ortam **etiketini** okur; `KUKA_EDM_WSDL` ise URL'i bu etiketten bağımsız
olarak ezebilir. Yani `environment=test` iken WSDL canlıyı gösterebilir. Bu yüzden sandbox,
gerçek `Kuka_Island_Core_Invoice_Config::get_wsdl()` değerini `kuka_sandbox_verify_test_endpoint()`
ile **Login'den önce** doğrular ve tek bir endpoint'i kabul eder:

```
https://test.edmbilisim.com.tr/EFaturaEDM21ea/EFaturaEDM.svc[?singleWsdl]
```

| Kural | Değer |
|---|---|
| scheme | kesinlikle `https` |
| host | kesinlikle `test.edmbilisim.com.tr` (birebir; önek/sonek/alt alan/sondaki nokta ret) |
| path | kesinlikle `/EFaturaEDM21ea/EFaturaEDM.svc` |
| query | ya yok ya da birebir `singleWsdl` |
| userinfo | yasak (ham dizgede `@` bile ret) |
| port | **hiçbir açık port kabul edilmez**, `:443` dahil — kanonik adreste port yoktur |
| fragment / `\` | yasak |
| boşluk / kontrol karakteri | **hiçbir yerde** kabul edilmez — baştaki/sondaki dahil |

Değer **trim edilmez ve normalize edilmez**; config'te ne varsa o byte dizisi doğrulanır.
Kanonik URL'in başına veya sonuna eklenen tek bir boşluk, tab, `\n`, `\r`, NUL, dikey tab,
form feed veya DEL karakteri `wsdl_contains_whitespace_or_control` ile **reddedilir**, sessizce
kanonik değere dönüştürülmez. Doğrulamayı geçen dizgi, SOAP istemcisine verilecek dizginin
aynısı olmalıdır.

Reddedilenler arasında: canlı WSDL, `test.edmbilisim.com.tr.evil.example`, gerçek adı yalnız
path'inde taşıyan host, `localhost`, IP literal, düz HTTP, farklı servis path'i, bozuk URL,
başında/sonunda dolgu karakteri bulunan kanonik URL.

Doğrulama başarısızsa `SANDBOX_ENDPOINT=BLOCKED|reason:<token>|login_attempted:no` yazılır,
tüm adımlar BLOCKED olur ve **Login denenmez**. URL hiçbir zaman basılmaz: özel bir WSDL
userinfo taşıyabilir, bu yüzden yalnız neden token'ı dışarı çıkar.

### 13.1.1 `CheckUser` ne değildir

`CheckUser`, **GİB e-Fatura mükellef listesini** sorgular. EDM'in kendi gönderdiği
`EFaturaEDMConnectorLibrary.cs` dosyasında (satır 2017, `CheckUser_byIdentifier`) tanım aynen:

> Vergi Kimlik No ile e-fatura mükellefi arama

Bu yüzden **e-Arşiv göndereni** için `CheckUser`'ın USER kaydı döndürmesi zorunlu değildir;
e-Arşiv zaten e-Fatura kaydı olmayan tarafa kesilen belgedir. Gerçek ölçüm (EDM test hesabı):
çağrı `PASS`, `user_entry:absent`, `response_fields:none`. Bu bir SOAP veya parser hatası değil,
boş USER dizisidir.

Gönderici alias'ını bu sorgudan türetmek yanlış otoriteye başvurmaktı. Sözleşme profile göre
ayrıldı:

| Profil | Gönderici kimliği otoritesi | `CheckUser` |
|---|---|---|
| `EARSIVFATURA` | Bağımsız **portal fixture**'ı ile birebir karşılaştırma | Bloklamaz. Boş sonuç `not_applicable_for_earchive_sender` bilgi etiketiyle raporlanır |
| e-Fatura profilleri | `CheckUser` USER kaydı **ve** dönen alias'ın birebir eşleşmesi | **Zorunlu, gevşetilmedi** |

Çıktıdaki kaynak etiketleri makine tarafından test edilebilir:

```
sender_identity_source=portal_verified_test_fixture      (e-Arşiv)
sender_identity_source=edm_checkuser_registry_alias      (e-Fatura)
check_user_role=einvoice_registry_lookup
check_user_requirement=not_applicable_for_earchive_sender | required_for_einvoice_sender
```

**Fixture'ın bağımsızlığı.** `kuka_sandbox_expected_sender_fixture()` **parametre almaz** ve
gövdesinde `$config`, `$facts`, `$loaded`, `get_sender_`, `getenv`, `get_option`,
`KUKA_EDM_SENDER`, `func_get_arg` geçmez — aksi hâlde değer kendisiyle karşılaştırılmış olurdu.
`kuka_sandbox_sender_fixture_for()` onu yalnız **kanıtlanmış test endpoint'i + `test` etiketi**
birlikteyken serbest bırakır; canlı yapılandırma boş dizi alır ve gönderici doğrulaması tek
otorite olarak `CheckUser`'a düşer. Değerler WordPress ayarına, veritabanına veya üretim
çalışma yoluna hiç yazılmaz.

**Yanlış yönlendirme düzeltildi.** PLAN çıktısı artık gerçekten başarısız olan kontrole göre
konuşuyor: eksik alan varsa yalnız eksik alan adlarını, fixture uyuşmazlığı varsa yalnız
uyuşmayan alan adlarını (değerleri değil) bildiriyor; e-Fatura tarafında boş `CheckUser` sonucu
"GİB e-Fatura kaydı meselesi" olarak açıklanıyor; e-Arşiv tarafında boş sonuç hata olarak
gösterilmiyor.

### 13.1.2 Onay kapısının çağrı biçimi

`wp eval-file` **yalnız çıplak positional** argüman aktarır; `--confirm=LoadInvoice` WP-CLI
tarafından kendi bilinmeyen parametresi sanılıp script çalışmadan `unknown --confirm parameter`
ile reddedilir. Belgelenen biçim bu yüzden çıplaktır:

```bash
KUKA_EDM_ALLOW_SANDBOX_WRITE=true ./scripts/edm-sandbox-run.sh confirm=LoadInvoice
```

Kapının gücü değişmedi: operasyon adı hâlâ tam olarak yazılmak zorunda.

### 13.2 Sandbox fixture kimlikleri

Bu bölümdeki iki değer için raporlanan kaynak etiketi `documented_example_fixture`'dır —
"bu hesaba atanmış varsayılan" değil, resmî örnekten alınmış fixture. Operatör override
verdiğinde etiket `operator_override` olur.

EDM'in resmî e-Arşiv SOAP örneğinde `PROFILEID=EARSIVFATURA` ve örnek alıcı kimliği
`11111111111` kullanılır. Bunlar **yalnız izole sandbox fixture'ında yararlanılan örnek
değerlerdir**. EDM'nin bu değerleri bizim test hesabımıza atadığı iddia edilmemektedir; alıcı
kimliği tahsis edilmiş bir test karşı tarafı değil, resmî örnekteki genel bireysel tüketici
kimliğidir.

Sandbox'a hapsedilmişlerdir:

- `kuka_sandbox_resolve_defaults()` **iki bağımsız koşul** ister: ortam etiketi `test` **ve**
  §13.1'deki endpoint doğrulaması geçmiş olmalı. Endpoint verdict'i fonksiyona **kanıt olarak**
  geçirilir; fonksiyon kendi başına "test endpoint" iddiasında bulunamaz. Herhangi biri eksikse —
  override verilse bile — `sandbox_values_refused_without_verified_test_endpoint` ile boş döner.
- Eklenti tarafında hiçbir dosya `lib-edm-sandbox`, `kuka_sandbox_`, `KUKA_SANDBOX_` veya
  `KUKA_EDM_SANDBOX_` referansı içermez (`SANDBOX_DEFAULTS_NOT_IN_PRODUCTION` ölçer).
- Üretim mapper'ındaki `11111111111`, değişmeden `allow_generic_individual_vkn` politika
  kapısının arkasındadır ve politika **varsayılan olarak kapalıdır**; aynı test bunu da doğrular.

Override verilirse doğrulanır: `PROFILEID` için `/^[A-Z][A-Z0-9_]{3,31}$/`, alıcı için
`/^\d{10,11}$/` **ve** gönderici VKN'sine eşit olmama, salt sıfır olmama. Hatalı override
varsayılana düşmez; `SANDBOX_DEFAULTS=BLOCKED` verir.

### 13.3 Seri artık zorunlu değil

`LoadInvoice` her koşulda `GENERATEINVOICEIDONLOAD=true` ile çağrılır.

| `KUKA_EDM_SERIES_EARCHIVE` | Davranış |
|---|---|
| Yok | `INVOICESERIAL_REQUESTED` gönderilmez; numarayı EDM sistem serisinden atar. **BLOCKED değil** |
| Var, biçim geçersiz | BLOCKED (`series_override_invalid_format`) — sessizce atlanmaz |
| Var, `GetInvoiceSerial` okunabildi ve tescilli değil | BLOCKED (`series_override_not_registered_at_edm`) |
| Var, tescilli | `INVOICESERIAL_REQUESTED` gönderilir |
| Var, `GetInvoiceSerial` **okunamadı** | **BLOCKED** (`series_override_registration_unverified`) — tescil gözlemlenemediği için iddia edilmez, seri gönderilmez |
| Yok, `GetInvoiceSerial` okunamadı | Geçer; seri hakkında hiçbir iddia yok, EDM sistem serisini seçer |

Seri **hiç verilmemesi** ile **verilip doğrulanamaması** farklı durumlardır: birincisinde
hiçbir şey iddia edilmez, ikincisinde operatörün iddiası doğrulanamamıştır ve fail-closed
davranılır.

`GetInvoiceSerial` salt-okunur keşif olarak kalır; yokluğu tek başına yazma kapısı değildir.
Her durumda EDM'in atadığı numara `SANDBOX_NUMBER_ASSIGNED` + `SANDBOX_CBC_ID_READBACK` ile
geri okunup doğrulanır.

Ölçtüğü sorular:

1. `INVOICE/@ID` gönderilmez ve `LoadInvoice` `GENERATEINVOICEIDONLOAD=true` ile çağrılırsa
   EDM numarayı atıyor mu?
2. Atanan numara, `GetInvoice` (XML) ile geri okunan belgenin UBL `cbc:ID` alanına yazılmış mı?
3. UUID, ödenecek tutar ve KDV tur dönüşünde birebir korunuyor mu?

Karar mantığının tamamı `scripts/lib-edm-sandbox.php` içindedir; böylece
`scripts/verify-edm-sandbox-harness.php` her reddetme yolunu fixture ve mock ile, **ağa çıkmadan
ve belge oluşturmadan** kanıtlar. Bu harness `make verify` kapsamındadır.

**Fail-closed gönderici/alıcı doğrulaması — altı bloklayıcı kontrolün tamamı geçmeden PLAN
aşamasına dahi geçilmez:**

| Kontrol | Kural |
|---|---|
| `check_user_ok` | `CheckUser` başarılı |
| `alias_exact_match` | EDM'in döndürdüğü alias, yapılandırılan alias ile **birebir** (büyük/küçük harf ve boşluk dahil) aynı |
| `company_fields_complete` | Sekiz gönderici mali alanının hepsi dolu |
| `profile_id_resolved` | `PROFILEID` test endpoint'inde çözülmüş (varsayılan veya geçerli override) |
| `receiver_identity_resolved` | Alıcı kimliği çözülmüş ve 10–11 hane |
| `series_selection_valid` | Seri ya yok (EDM atar) ya da gerçekten kullanılabilir |

Bloklamayan bilgi alanları ayrıca yazılır: `profile_source`, `receiver_source`, `series_mode`,
`series_sent` — hepsi **etiket**, hiçbiri değer değil.

**Gönderici mali alanlarında tahmin yok.** Kullanıcı adı ve parola yalnız bağlantı kimliğidir;
mükellef kimliği değildir. VKN, alias, unvan, vergi dairesi, adres, ilçe, şehir **ve posta kodu**
UBL için hâlâ zorunludur ve EDM portalı/API'sinden gelmelidir. Tek bir alan bile eksikse çıktı
`SANDBOX_SENDER_IDENTITY=BLOCKED|failed:company_fields_complete|missing_sender_fields:<alan adları>`
biçiminde **hangi alanın eksik olduğunu adıyla** raporlar. Bu test şirketi değerleri üretim
Kuka Island şirket bilgisi olarak kullanılmaz.

Negatif matris 20 vakayla ölçülür (`leaked:none`).

**Yazma öncesi kilitli idempotency durum makinesi** (`Kuka_Sandbox_Claim`):

```
idle -> in_flight -> confirmed
                  -> failed_definitive
                  -> uncertain
```

| Kural | Uygulama |
|---|---|
| Eşzamanlılık | `flock(LOCK_EX\|LOCK_NB)`; kilit alınamazsa BLOCKED. İki süreçten yalnız biri sahip olur |
| Claim | Yalnız `idle`'dan. `in_flight`, `uncertain`, `confirmed`, `failed_definitive`, `corrupt` → ikinci yazma **koşulsuz reddedilir** |
| Timeout / bağlantı kopması | Çağrı EDM'de başarılı olmuş olabileceği için `uncertain`. **Otomatik tekrar yok** |
| `uncertain` çıkışı | Yalnız UUID ile `GetInvoiceStatus` / `GetInvoice` uzlaştırması sonrası, belgenin **yok olduğu kesin kanıtlanırsa** ve operatör istediğinde `reset_after_reconcile('document_absent_at_edm')` |
| Kalıcılık | temp dosya + `rename`, mod **600**. Yazma başarısızsa `state_recorded:yes` **yazılmaz** |

**Bozuk durum kaydı fail-closed.** `status()` "dosya yok" ile "dosya bozuk"u ayırır. Yalnız
**dosyanın hiç bulunmaması** `idle` sayılır:

| Girdi | Durum |
|---|---|
| Dosya yok | `idle` (`no_state_file`) |
| Geçerli `idle` kaydı | `idle` (`ok`) |
| Dosya okunamıyor | `corrupt` (`state_file_unreadable`) |
| Boş / yalnız boşluk | `corrupt` (`state_file_empty`) |
| Geçersiz JSON, JSON nesne değil | `corrupt` (`state_file_invalid_json`) |
| `state` alanı yok | `corrupt` (`state_field_missing`) |
| Bilinmeyen state değeri | `corrupt` (`unknown_state_value`) |
| `idle` dışı state'te `uuid` veya `operation` eksik | `corrupt` (`missing_uuid_for_state_*` / `missing_operation_for_state_*`) |
| `confirmed` ama `assigned_number` yok | `corrupt` (`confirmed_without_assigned_number`) |

Bozuk kayıt **hiçbir koşulda** claim/write alamaz. Araç kullanıcıyı dosyayı silmeye veya
sıfırlamaya yönlendirmez; çıktı açıkça şunu söyler: önce deterministik UUID ile EDM üzerinde
salt-okunur uzlaştırma yapılmalı.

**Yazma sonucu sınıflandırması asimetrik.** Yalnız **yapısal olarak eksiksiz bir ret**
`failed_definitive` olabilir: `REQUEST_RETURN` mevcut **ve** `RETURN_CODE` sayısal **ve**
`RETURN_CODE != 0`. Diğer her non-success şekli, çağrı yapılmış ve belge oluşmuş olabileceği için
`uncertain`:

| Yanıt | Sınıf |
|---|---|
| `RETURN_CODE != 0` (sayısal) | `definitive_rejection` |
| `RETURN_CODE = 0`, `INVOICE` yok | `uncertain` |
| `RETURN_CODE = 0`, `ID` boş | `uncertain` |
| `RETURN_CODE = 0`, `UUID` eksik/farklı | `uncertain` |
| Beklenen şemaya uymayan başarı yanıtı | `uncertain` |
| Parse edilemeyen yanıt | `uncertain` |
| Ağ / timeout / SOAP bağlantı kopması | `uncertain` |

`uncertain` durumunda otomatik ikinci `LoadInvoice` **kesinlikle yasak**; yalnız salt-okunur
uzlaştırma yapılır.

**Settle persist hatası.** Dış çağrı başarılı olsa bile `confirmed` durumu diske yazılamazsa:
`SANDBOX_DRAFT_UPLOAD=PASS` **yazılmaz**, `result:draft_uploaded` **yazılmaz**, durum
`state_persist_failed_manual_reconciliation_required` olur, disk kaydı `in_flight` kalır, ikinci
yazma reddedilir ve komut **non-zero** çıkar. Numara ve readback ölçümleri yine de yapılır.

**LoadInvoice yanıtı kesin doğrulanır.** Hiçbir yanıt varsayılan olarak başarı sayılmaz:

- `REQUEST_RETURN.RETURN_CODE` kesin yolundan okunur; sayısal değilse `malformed`.
- `RETURN_CODE != 0` → `business_error`, `create_ok=false`, durum `failed_definitive`.
- Atanan numara **yalnız** `LoadInvoiceResponse/INVOICE[0]/@ID` alanından okunur. **Recursive arama
  yapılmaz**, bu yüzden `HEADER/ID` gibi ilgisiz bir nested ID asla mali belge numarası sanılmaz.
- Dönen `UUID`, gönderilen deterministik UUID ile eşleşmezse `uuid_mismatch`.
- Numara boş/boşluksa `empty_id` → `NUMBER_ASSIGNED=FAIL` ve `confirmed` durumuna **geçilmez**.

Parser 12 fixture ile ölçülür: başarı (dizi ve tek nesne), iş hatası, boş ID, boşluk ID, yanlış
UUID, ilgisiz nested ID, string/null malformed, eksik `REQUEST_RETURN`, sayısal olmayan kod,
eksik `INVOICE`.

**Readback verdictleri ayrı ayrı raporlanır.** `SANDBOX_STATUS_READBACK` yalnız `GetInvoiceStatus`
sorgusu başarılıysa PASS; tekrar koşuda `query_failed` **PASS etiketi altında gösterilmez**.
`SANDBOX_XML_READBACK` yalnız beş zorunlu kontrolün (`xml_retrieved`, `xml_parsed`, `uuid_match`,
`payable_match`, `tax_match`) tamamı geçtiğinde PASS. `SANDBOX_CBC_ID_READBACK` ayrı bir ölçümdür:
atanan numaranın saklanan UBL `cbc:ID` alanına yansıyıp yansımadığı.

Diğer kapılar:

| Kapı | Kural |
|---|---|
| Ortam | `is_live()` → koşulsuz BLOCKED |
| Endpoint | Gerçek `get_wsdl()` değeri allow-list'ten geçmeli. Geçmezse Login denenmeden BLOCKED (§13.1) |
| `application_name` | `ozelyazilim.kukaisland` değilse BLOCKED |
| Varsayılan mod | **PLAN**. Hiçbir belge oluşturulmaz |
| Yazma kapısı 1 | `KUKA_EDM_ALLOW_SANDBOX_WRITE` **literal** `true` |
| Yazma kapısı 2 | `--confirm=LoadInvoice` — planın çözdüğü operasyon adıyla birebir eşleşmeli |
| DB | WooCommerce siparişi oluşturmaz; hiçbir tabloya yazmaz. Durum yalnız host tarafındaki JSON dosyasında |
| KDV | Bu dosyada sabit `KUKA_SANDBOX_VAT_PERCENT = 20`, `cbc:Note` içinde açıkça TEST etiketiyle belirtilir. Mağazanın vergi ayarları okunmaz ve değiştirilmez |
| Alıcı kimliği | Resmî EDM örneğindeki örnek bireysel kimlik; `KUKA_EDM_SANDBOX_RECEIVER_VKN` ile override edilebilir. Doğrulanmamış endpoint'te hiçbir değer çözülmez |
| PROFILEID | Resmî EDM örneğindeki `EARSIVFATURA`; `KUKA_EDM_SANDBOX_PROFILE_ID` ile override edilebilir. Doğrulanmamış endpoint'te hiçbir değer çözülmez. Yazılı teyit kapısı kaldırıldı (§13.2) |
| `INVOICESERIAL_REQUESTED` | Yalnız tescili **gözlemlenmiş** bir seri override'ı varsa gönderilir; serinin hiç verilmemesi yazma kapısı **değildir** (§13.3) |
| `SendInvoice` | Çağrılmaz. Her çıkış yolunda `SANDBOX_SENDINVOICE=NOT_EXECUTED` raporlanır |
| Belge | Sentetik ve açıkça TEST işaretli (`cbc:Note`: "TEST BELGESI … GERCEK SATIS DEGILDIR", kalem adı "SANDBOX TEST KALEMI") |
| Mükerrerlik | UUID sabit tohumdan deterministik; durum makinesi ikinci belgeyi reddeder. EDM'in kendi mükerrer denetimi ikinci katman |
| Üretim koruması | `Kuka_Island_Core_Invoice_Manager` ve `invoice_numbering_unconfirmed` guard'ı **kullanılmaz ve gevşetilmez**. `LoadInvoice` isteği tamamen test harness'ında kurulur; eklentide `LoadInvoice`/`CreateSerial`/`CancelInvoice` yazma metodu **yok** (harness bunu da ölçer) |

`cbc:ID` deneyin çekirdeği: belge üretim builder'ı ile kurulur, ardından `cbc:ID` elemanı
DOM üzerinden **çıkarılır**, yani hiçbir numara gönderilmez ve çıktıda
`ubl_cbc_id_sent:absent` olarak raporlanır.

```bash
./scripts/edm-sandbox-run.sh                                    # PLAN, hiçbir şey oluşturmaz
KUKA_EDM_ALLOW_SANDBOX_WRITE=true ./scripts/edm-sandbox-run.sh --confirm=LoadInvoice
```

İkinci komut EDM test hesabında **kalıcı bir taslak kaydı** oluşturur (gönderim değil); bu yüzden yazma çağrısından
hemen önce hangi operasyonun çağrılacağı açıkça yazdırılır ve iki kapı birlikte sağlanmadan
işlem yapılmaz.

---

## 14. Çalıştırma

```bash
# Hedefli fatura doğrulaması
docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-invoice-integration.php

# DB keyset parmak izi (dış süreç izolasyonu için)
docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-invoice-keyset.php

# Gerçek EDM salt-okunur sonda (kimlik yoksa BLOCKED yazar, ağa çıkmaz)
./scripts/edm-test-run.sh test-edm-sandbox.php

# İzole sandbox taslak yükleme deneyi (varsayılan PLAN; hiçbir belge oluşturmaz)
./scripts/edm-sandbox-run.sh

# Sandbox harness kanıtları (fixture/mock; ağa çıkmaz, belge oluşturmaz)
docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-edm-sandbox-harness.php

# Tüm kabul ölçümleri
make verify
```

EDM kimlik bilgileri yalnız `wp-config.php` sabitleri veya ortam değişkenleri üzerinden okunur; Git'e, loglara, komut çıktısına veya veritabanına yazılmaz.
