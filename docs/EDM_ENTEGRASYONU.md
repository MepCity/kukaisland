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
3. `can_send_invoice()` — 12 alanın tamamı: kullanıcı adı, parola, gönderici VKN, gönderici etiket (alias), her iki fatura serisi (`/^[A-Z0-9]{3}$/`), şirket unvanı, vergi dairesi, adres, ilçe, şehir, posta kodu.
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
| Auto-send tam hazırlık sözleşmesi | **PASS** | `INVOICE_AUTO_SEND_FULL_READINESS_CONTRACT=PASS\|fields_checked:12\|leaks:none` |
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

Kurulum ve çalıştırma:

```bash
./scripts/edm-test-credentials.sh            # gizli giriş, ekrana/history'ye yazmaz
./scripts/edm-test-credentials.sh --status   # yalnız varlık/izin bilgisi
./scripts/edm-test-run.sh test-edm-sandbox.php
```

`application_name` her istekte `ozelyazilim.kukaisland`; probe bunu ayrıca doğrular.

---

## 13. İzole sandbox fatura deneyi

`scripts/edm-sandbox-invoice.php` + `scripts/edm-sandbox-run.sh`, §4'teki numaralandırma
sorularını EDM **test** ortamında ölçmek için kurulmuş ayrı bir araçtır.

Ölçtüğü sorular:

1. `INVOICE/@ID` gönderilmez ve `LoadInvoice` `GENERATEINVOICEIDONLOAD=true` ile çağrılırsa
   EDM numarayı atıyor mu?
2. Atanan numara, `GetInvoice` (XML) ile geri okunan belgenin UBL `cbc:ID` alanına yazılmış mı?
3. UUID, ödenecek tutar ve KDV tur dönüşünde birebir korunuyor mu?

Güvenlik sözleşmesi — hepsi sağlanmazsa araç reddeder:

| Kapı | Kural |
|---|---|
| Ortam | Yalnız test endpoint'i. `is_live()` → koşulsuz BLOCKED |
| Varsayılan mod | **PLAN**. Hiçbir belge oluşturulmaz |
| Yazma kapısı 1 | `KUKA_EDM_ALLOW_SANDBOX_WRITE` **literal** `true` |
| Yazma kapısı 2 | `--confirm=LoadInvoice` — planın çözdüğü operasyon adıyla birebir eşleşmeli |
| DB | WooCommerce siparişi oluşturmaz; hiçbir tabloya yazmaz. Durum yalnız host tarafındaki JSON dosyasında (`~/.config/kuka-island/edm-sandbox-state/`) |
| Gönderici kimliği | VKN, alias, unvan, vergi dairesi, adres, ilçe, şehir, posta kodu **sağlanmış** olmalı; eksikse alan adları listelenerek BLOCKED. Hiçbir değer uydurulmaz |
| Gönderici doğrulaması | `GetInvoiceSerial` (filtresiz) ve `CheckUser` ile salt-okunur teyit |
| KDV | Bu dosyada sabit `KUKA_SANDBOX_VAT_PERCENT = 20`. Mağazanın vergi ayarları okunmaz ve değiştirilmez |
| Belge | Sentetik ve açıkça TEST işaretli (`cbc:Note`: "TEST BELGESI … GERCEK SATIS DEGILDIR", kalem adı "SANDBOX TEST KALEMI") |
| Mükerrerlik | UUID sabit bir tohumdan deterministik üretilir; kayıtlı önceki koşu ikinci belgeyi reddeder. EDM'in kendi mükerrer denetimi ikinci katman |
| Üretim koruması | `Kuka_Island_Core_Invoice_Manager` ve `invoice_numbering_unconfirmed` guard'ı **kullanılmaz ve gevşetilmez**. `LoadInvoice` isteği tamamen test harness'ında kurulur; eklentiye yeni bir yazma yeteneği eklenmez |

`cbc:ID` deneyin çekirdeği: belge üretim builder'ı ile kurulur, ardından `cbc:ID` elemanı
DOM üzerinden **çıkarılır**, yani hiçbir numara gönderilmez ve çıktıda
`ubl_cbc_id_sent:absent` olarak raporlanır.

```bash
./scripts/edm-sandbox-run.sh                                    # PLAN, hiçbir şey oluşturmaz
KUKA_EDM_ALLOW_SANDBOX_WRITE=true ./scripts/edm-sandbox-run.sh --confirm=LoadInvoice
```

İkinci komut EDM test hesabında **kalıcı bir test kaydı** oluşturur; bu yüzden yazma çağrısından
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

# İzole sandbox fatura deneyi (varsayılan PLAN; hiçbir belge oluşturmaz)
./scripts/edm-sandbox-run.sh

# Tüm kabul ölçümleri
make verify
```

EDM kimlik bilgileri yalnız `wp-config.php` sabitleri veya ortam değişkenleri üzerinden okunur; Git'e, loglara, komut çıktısına veya veritabanına yazılmaz.
