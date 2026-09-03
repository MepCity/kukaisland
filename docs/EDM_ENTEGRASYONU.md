# EDM Bilişim e-Fatura ve e-Arşiv Entegrasyonu — Aşama 1

Bu doküman, Kuka Island WooCommerce mağazası için hazırlanan **EDM Bilişim e-Fatura (TICARIFATURA / TEMELFATURA)** ve **e-Arşiv Fatura (EARSIVFATURA)** entegrasyonunun Aşama 1 durumunu açıklar.

**Bu doküman güncel teknik sözleşmedir**, kronolojik bir günlük değil. Bakım
kayıtları ayrı tutulur: [docs/EDM_BAKIM_HAFIZASI.md](EDM_BAKIM_HAFIZASI.md).
Etkinleştirme akışı için [docs/EDM_AKTIVASYON_REHBERI.md](EDM_AKTIVASYON_REHBERI.md).

### Modül nerede yaşıyor

EDM/fatura kodu artık Kuka Island Core'un içinde değil, **ayrı ve varsayılan
olarak pasif** bir eklentide:

```
wp-content/plugins/kuka-island-edm/
  kuka-island-edm.php            plugin başlığı ve bootstrap
  includes/class-plugin.php      composition root + bağımlılık kontrolü
  includes/class-activator.php   aktivasyon/deaktivasyon
  includes/class-invoice.php     modül yükleyicisi
  includes/invoice/              EDM client, UBL, manager, queue, poller, ...
```

Bağımlılık **tek yönlüdür**: `kuka-island-edm → kuka-island-core`. Core bu
eklentiye hiçbir şekilde bağlı değildir ve eklenti pasifken hiçbir dosyası
yüklenmez.

**Gönderim üretimde kapalıdır**, ve bunun üç ayrı sebebi var — herhangi biri tek
başına yeterli:

1. Eklenti **pasif** teslim ediliyor; pasifken hiçbir hook, panel, SOAP
   bağlantısı, Action Scheduler işi veya sipariş metası oluşmaz.
2. `KUKA_INVOICE_AUTO_SEND` **kapalı**.
3. Canlı EDM kimlik bilgileri **yapılandırılmamış**.

Mali belge numarası sözleşmesi ise artık **doğrulanmıştır** (§4); bu bir engel
değildir.

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
- `EARCHIVE_REPORT_SENDDATE`, `CANCEL_EARCHIVE_REPORT_SENDDATE` (xs:date, `minOccurs="1"` — şemada zorunlu, EDM'e göre iş kuralı olarak gereksiz; bkz. §16.2)
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

WSDL'in kanıtlamadığı şey, o zaman şuydu: EDM'nin atadığı numarayı gönderilen
`CONTENT` içindeki UBL `cbc:ID` alanına geri yazıp yazmadığı. UBL-TR 2.1 TR1.2
`cbc:ID`'yi zorunlu tutar ve bu değer yerel olarak **uydurulmadan** üretilemez.

**Bu soru artık kapandı.** EDM teknik desteği yazılı olarak bildirdi ve iki
gerçek çağrıyla ölçüldü: gönderilen UBL'in `cbc:ID` alanına EDM'in portal yer
tutucusu `ABC2009123456789` yazılır, SOAP `INVOICE/@ID` **hiç gönderilmez**, ve
gerçek numara **yalnız yanıttan** okunur. İki ölçümde de EDM 16 karakterlik bir
numara atadı (§17.1). Aşağıdaki fail-closed politika bu yüzden değişmedi:
politikanın amacı numaranın kaynağını kanıtlamaktı, ve kaynak artık kanıtlı.

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
- e-Arşiv akışı `INVOICE/HEADER/EARCHIVE` (xs:boolean) ile tanımlanır. `EARCHIVE_REPORT_SENDDATE` bu tanımın parçası **değildir**: EDM'in GİB'e raporlama tarihidir (§16.2).

e-Arşiv alıcısının GİB posta kutusu yoktur; alanın atlanması şema geçerlidir. Uydurma bir posta kutusu etiketi yazmak değildi ve kaldırıldı. e-Fatura tarafında alias `CheckUser`'dan gelir ve eksikse `missing_recipient_alias` ile fail-closed davranılır.

### Bireysel alıcı kimliği

Bu bölüm bir zamanlar `KUKA_EDM_ALLOW_GENERIC_INDIVIDUAL_VKN` adlı bir kapıyı
anlatıyordu. **O kapı kodda yok**; anlatım kaynakla çelişiyordu ve kaldırıldı.

Ölçülen davranış (`Kuka_Island_Core_Invoice_Order_Mapper`):

- Bireysel alıcıda TCKN **koşulsuz** `11111111111` olur. EDM'in yazılı cevabı bu
  değeri test ortamı için onayladı ve nihai tüketici için ayrı bir TCKN
  sorulmuyor: checkout'ta TCKN alanı **yok** ve eklenmemeli.
- Karşılığında **isim zorunlu**. Ad veya soyad eksikse `missing_individual_name`
  ile fail-closed davranılır. "Nihai Tüketici" gibi genel bir unvan mali belgeye
  uydurma taraf adı yazmak olurdu; üretilmez.
- Sipariş TCKN taşıyorsa 11 hane olmak zorundadır; değilse
  `invalid_individual_tckn`.

Kanıt: `INVOICE_INDIVIDUAL_EARCHIVE_RECEIVER_CONTRACT` gerçek sipariş → gerçek
mapper → gerçek UBL ölçer; `INVOICE_INDIVIDUAL_RECEIVER_FAIL_CLOSED` eksik
isim/e-posta hallerini kapatır.

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

### 13.1.3 Mutabakat sonrası sıfırlama

`uncertain` durumu ikinci yazmayı yapısal olarak reddeder. Operatör belgenin EDM'de
bulunmadığını dışarıdan saptadıysa geçiş desteklenen yoldan yapılır — **hiçbir EDM çağrısı
yapılmaz**, JSON elle düzenlenmez, geçmiş korunur ve yalnızca eklenir:

```bash
./scripts/edm-sandbox-run.sh reset=document_absent_at_edm audit=<etiket>
```

**Bu akış tamamen çevrimdışıdır.** Önceki sürümde reset kontrolü `Login`, `GetInvoiceSerial`
ve `CheckUser` çalıştıktan **sonra** yer alıyordu; "hiçbir EDM çağrısı yapmaz" ifadesi o
hâliyle doğru değildi.

CLI argümanları artık dosyanın en başında ayrıştırılıyor ve reset dalı; kimlik dosyası
okunmadan, `Invoice_Config` kurulmadan, endpoint doğrulanmadan, client/transport
oluşturulmadan çalışıp çıkıyor.

**Host tarafındaki yazma kapısı.** Sarmalayıcı reset modunda `KUKA_EDM_ALLOW_SANDBOX_WRITE`
değişkenini konteynere iletmez — bu nedenle sürücünün kendi `write_gate_open_during_reset`
kontrolü o değişkeni hiç göremez. Bu yüzden reddin **host'ta**, docker başlamadan, state
dizinine dokunulmadan yapılması gerekir:

```
$ KUKA_EDM_ALLOW_SANDBOX_WRITE=true ./scripts/edm-sandbox-run.sh reset=document_absent_at_edm audit=edm_portal_absent
EDM_SANDBOX_RUN=BLOCKED|reason:write_gate_open_during_reset|credentials_mounted:no|docker_started:no|state_unchanged:yes
$ echo $?
1
```

`allow` değeri `true` dışında herhangi bir şeyse reset çevrimdışı çalışır. PLAN/LoadInvoice
yazma kapıları değişmedi.

**Nasıl ölçülüyor.** İki katman ayrı ayrı etiketlenir; hangisinin ne kanıtladığı karıştırılmaz.

| Kontrol | Ölçüm türü | Ne kanıtlar |
|---|---|---|
| `SANDBOX_RESET_PRECEDES_EVERY_EDM_PATH` | `measured:source_position` | Reset ayrıştırma ve çıkış; credential, config, client, `Login`, `GetInvoiceSerial`, `CheckUser` konumlarının **hepsinden önce** |
| `SANDBOX_RESET_STATE_MACHINE` | `measured:claim_class` | `Kuka_Sandbox_Claim` geçiş semantiği |
| `SANDBOX_RESET_WRAPPER_MOUNTS_NO_CREDENTIALS` | `measured:wrapper_source` | Sarmalayıcı reset dalında kimlik mount'u ve write env yok; host kapısı docker'dan önce |
| `SANDBOX_RESET_HOST_WRITE_GATE` | **davranışsal, host** | Gerçek sarmalayıcı; exit 1, docker hiç başlatılmadı, state byte-identical |
| `SANDBOX_RESET_REAL_WRAPPER_DRIVER` | **davranışsal, host** | Gerçek sarmalayıcı + gerçek sürücü; `uncertain → idle`, kimlik mount'u yok (docker'ın gerçek argüman vektöründen okunur) |

```
SANDBOX_RESET_HOST_WRITE_GATE=PASS|exit:1|reason:write_gate_open_during_reset|docker_started:no|credentials_mounted:no|state_unchanged:yes
SANDBOX_RESET_REAL_WRAPPER_DRIVER=PASS|credentials_file:absent|credentials_mounted:no|from:uncertain|to:idle|uuid_unchanged:yes|history:append_only|real_claim_unchanged:yes
```

Davranışsal iki kontrol `scripts/verify-reset-offline.sh` içinde, host'ta çalışır (sarmalayıcı
kendi başlattığı konteynerin içinden çalıştırılamaz). Geçici bir `XDG_CONFIG_HOME` kullanır,
`uncertain` kaydı durum makinesiyle üretir, `PATH` başına docker'ın argüman vektörünü kaydeden
bir shim koyar ve geliştiricinin gerçek claim dosyasına hiç dokunmaz.

**Kaldırılan yanıltıcı ölçüm.** Bir önceki sürümde konteyner içinde bir "counting transport"
kuruluyor, reset kendi closure'ıyla yeniden yazılıyor ve transport `unset` ediliyordu. Sayaç
sıfır çıkıyordu ama test edilen koddan o transport'a hiç erişilemiyordu; yani sonuç gerçek
sürücü hakkında hiçbir şey kanıtlamıyordu. Kaldırıldı.

Reset girdileri fail-closed reddedilir:

| Girdi | Ret nedeni |
|---|---|
| Yanlış kanıt | `reset_requires_document_absent_evidence` |
| İki kez `reset=` | `duplicate_parameter_reset` |
| `reset=` + `confirm=` | `confirm_combined_with_reset` |
| `reset=` + `KUKA_EDM_ALLOW_SANDBOX_WRITE=true` | `write_gate_open_during_reset` |
| Bilinmeyen parametre | `unknown_parameter_<ad>` |
| Beklenmedik karakterli `audit=` | `audit_label_has_unexpected_characters` |

Her ret `soap_calls:0|state_unchanged:yes` ile raporlanır.

Kapı değişmedi: `reset_after_reconcile()` hâlâ literal `document_absent_at_edm` kanıtını ve
`uncertain` durumunu şart koşar. `audit` yalnızca operatörün kendi etiketini geçmişe yazar,
kanıtın yerine geçmez.

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
- Üretim mapper'ındaki `11111111111` sabiti **tek kez** tanımlıdır ve modülde başka literal
  kopyası yoktur (`generic_tckn_declared_once:yes|extra_literal_copies:none` ölçer).

  Bu madde bir zamanlar bu değerin `allow_generic_individual_vkn` adlı bir politika kapısının
  arkasında olduğunu ve kapının varsayılan kapalı olduğunu söylüyordu. **Öyle bir kapı
  önceden vardı, kaldırıldı**; anlatım kodla çelişiyordu ve hiçbir test onu doğrulamıyordu.
  Güncel davranış §5'te: bireysel e-Arşiv alıcısında `11111111111` **koşulsuz** kullanılır,
  gerçek ad ve soyad **zorunludur**, eksikse `missing_individual_name` ile fail-closed
  davranılır. Sandbox'a hapsedilen şey bu değer değil, sandbox'ın **kendi** profil ve alıcı
  override'larıdır.

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

### `cbc:ID` ile `INVOICE/@ID` ayrı alanlardır

Önceki sürüm UBL'den `cbc:ID` elemanını DOM ile **siliyordu**. UBL-TR bu alanı zorunlu tutar,
dolayısıyla gönderilen belge yapısal olarak geçersizdi.

| Alan | Değer | Neden |
|---|---|---|
| UBL `cbc:ID` | **`ABC2009123456789`** | EDM'in resmî portal-seri placeholder'ı. Mali fatura numarası **değildir**, saklanmaz, numara diye sunulmaz |
| SOAP `INVOICE/@ID` | **gönderilmez** | `GENERATEINVOICEIDONLOAD=true` ile numarayı EDM atasın diye |

EDM yanıtındaki gerçek `INVOICE.ID` / `UUID` ayrıştırması değişmedi.

```
SANDBOX_UBL_CBC_ID_PLACEHOLDER=PASS|cbc_id_count:1|cbc_id:ABC2009123456789|matches_literal:yes|dom_removal_code:removed|old_placeholder:gone
SANDBOX_REQUEST_KEEPS_UBL_ID_AND_OMITS_SOAP_ID=PASS|soap_invoice_id_attribute:absent|ubl_cbc_id_in_content:present|generate_invoice_id_on_load:true
```

### Tek ortak REQUEST_HEADER üreticisi

Sandbox dört alan gönderiyordu, production client sekiz. Sözleşmenin ikinci kez ayrışmaması
için tek saf üretici çıkarıldı: `Kuka_Island_Core_EDM_Request_Header::build()`. Hem
`class-edm-client.php` hem `lib-edm-sandbox.php` onu çağırır; sandbox'ın kendi header literal'i
kalmadı.

Zarf sırasıyla sekiz alan: `SESSION_ID`, `CLIENT_TXN_ID`, `ACTION_DATE`, `REASON`,
`APPLICATION_NAME`, `HOSTNAME`, `CHANNEL_NAME`, `COMPRESSED`. LoadInvoice değerleri:
`REASON=LoadInvoice`, `APPLICATION_NAME=ozelyazilim.kukaisland`, `HOSTNAME=kukaisland`,
`CHANNEL_NAME=WEB`, `COMPRESSED=N`, `CLIENT_TXN_ID=belge UUID'si`.

```
SANDBOX_LOAD_REQUEST_HEADER_CONTRACT=PASS|fields:8|order_matches_contract:yes|duplicates:none|wrong_values:none|reason:LoadInvoice|hostname:kukaisland|channel:WEB|compressed:N|client_txn_id_is_uuid:yes
SANDBOX_HEADER_GENERATOR_IS_SHARED=PASS|sandbox_uses_shared_builder:yes|sandbox_own_header_literals:none|builder_is_pure_static:yes
```

### Çözülmemiş kapı: e-Arşiv alıcı alias'ı

`EARSIVFATURA` + nihai tüketici `11111111111` için `LoadInvoiceRequest.RECEIVER.alias` /
`INVOICE.HEADER.TO` değerinin ne olması gerektiği **hiçbir resmî EDM kaynağıyla
kesinleşmedi**. Tahmin yürütülmüyor: `defaultpk` kullanılmıyor, e-posta alias sanılmıyor, boş
dize doğruymuş gibi sunulmuyor.

```
SANDBOX_RECEIVER_ALIAS=BLOCKED|reason:official_earchive_alias_not_established
SANDBOX_DRAFT_UPLOAD=BLOCKED|reason:receiver_alias_contract_unresolved
```

Bu kapı **iki yazma kapısının da açık** olduğu koşulda bile bloklar; ölçüldü. EDM'den yazılı
yanıt geldiğinde `edm-sandbox-invoice.php` içindeki `$receiver_alias_established` bayrağı
açılır ve yanıt aynı anda bu dokümana işlenir.

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

---

## e-Arşiv alıcı adresleme sözleşmesi (EDM teknik desteğinden yazılı cevap)

Bu bölüm, sandbox aracındaki `receiver_alias_contract_unresolved` engelinin
neden kaldırıldığını kayda geçirir. Engel, hiçbir resmî kaynak
`RECEIVER.alias` ve `INVOICE.HEADER.TO` alanlarının ne taşıyacağını
söylemediği için vardı ve hiçbir değer tahmin edilmemişti.

EDM teknik desteğinin yazılı cevabı:

1. Müşteri e-postası UBL içindeki `cbc:ElectronicMail` alanına yazılır.
2. Aynı e-posta `INVOICE/HEADER/TO` alanına yazılır.
3. EDM faturayı bu adrese kendisi iletir; ayrı bir `EmailInvoice` çağrısı yapılmaz.
4. `RECEIVER.alias` e-Arşiv'de **hiç serileştirilmez** — boş string de gönderilmez.
   Alias, alıcının sahip olmadığı bir GİB posta kutusunu adresler.

Bu kural tek yerde tanımlıdır:
`Kuka_Island_Core_EDM_Client::recipient_addressing()`. Üretimdeki `SendInvoice`
ve sandbox aracındaki `LoadInvoice` aynı yardımcıyı kullanır; eşdeğerlik
`SANDBOX_MATCHES_PRODUCTION_RECIPIENT_ADDRESSING` ile ölçülür.

### Tek kontrollü LoadInvoice taslağının gözlemleri

Test ortamına (`test.edmbilisim.com.tr`) tek bir `LoadInvoice` çağrısı yapıldı.
`LoadInvoice`, `SendInvoice` **değildir**: belgeyi EDM'de taslak olarak saklar,
fatura kesmez ve alıcıya hiçbir şey iletmez.

- Belge numarası EDM tarafından atandı: 16 karakter, `GENERATEINVOICEIDONLOAD =
  true` ile. Numara yalnız yanıttan okundu; `INVOICE/@ID` gönderilmedi ve
  gönderilen UBL'in `cbc:ID` alanı EDM'nin portal yer tutucusunu taşıdı.
- `GetInvoiceStatus`, yüklenen taslak için **`LOAD - SUCCEED`** literalini
  döndürdü. Bu literal EDM'nin yayımlanmış *giden belge* durum listesinde
  bulunmadığı için `Kuka_Island_Core_EDM_Document_Status` onu fail-closed olarak
  `needs_manual_review`'a eşliyor. Bu doğru davranıştır ve üretim yolu
  `LoadInvoice` çağırmadığı için üretimi etkilemez. Literal, taslak yolunun
  bilinen sonucu olarak burada kayıtlıdır; giden belge tablosuna
  **eklenmemiştir**, çünkü kesilmiş bir belgeyi tanımlamaz.
- `GetInvoice` taslak için **boş CONTENT** döndürdü
  (`reason:empty_content_returned`). Ölçülen gerçek budur: LoadInvoice
  taslağında bu operasyon XML vermedi. Neden vermediği ve hangi koşulda
  vereceği ölçülmemiştir; XML readback bu nedenle raporlarda dürüstçe FAIL
  kalıyor ve gönderim sonrasında tekrar ölçülecektir.

Taslağın EDM'de var olduğunun kanıtı, durum sorgusunun bizim UUID'imiz
hakkında kendi literalini döndürmesidir: `document_present_at_edm:yes`.
Doğrulama, ikinci bir belge oluşturmadan tekrarlanabilir:

```bash
./scripts/edm-sandbox-run.sh readback=confirm
```

## 15. İzole sandbox GERÇEK GÖNDERİM denemesi ve sonucu

Test ortamına, açık operatör onayıyla **tek bir** `SendInvoice` çağrısı yapıldı.
EDM çağrıyı reddetti. Belgenin oluşup oluşmadığı **kesin olarak
doğrulanamadı**; kalıcı durum bu nedenle `uncertain` tutuluyor ve araçtaki her
kapı ikinci gönderimi reddediyor. Ölçüm, belgenin oluşmamış olmasını **güçlü
olasılık** yapar (§15.2), fakat EDM teyidi beklenmektedir — ve `uncertain`
kaydını çözecek olan da o teyittir, buradaki çıkarım değil.

### 15.1 Reddetme neden tek başına bir cevap değildir

`SendInvoice` hata verdikten sonra belgenin EDM'de var olup olmadığı
**bilinmiyordu**, ve bu soruyu yeniden göndererek cevaplamak yasaktır. Aynı
belgeyi bir daha iletmek, bu kod tabanındaki her korumanın engellemek için var
olduğu şeydir.

`resolve` modu bunu yalnız okuma ile cevaplar (`GetInvoiceStatus` + `GetInvoice`;
kapı gerektirmez, çünkü hiçbir şey oluşturmaz; iki kapıdan biri veya bir onay
argümanı varsa reddeder):

```bash
./scripts/edm-sandbox-send-run.sh resolve
```

İlk resolve **sonuçsuz** çıktı, ve sebebi kayda değer: gönderilen belge için
`GetInvoiceStatus` **de** reddedildi, gönderimin aldığı güvenli kodun aynısıyla
(`edm_request_refused`). Bir reddetme tek başına belirsizdir — "sana cevap
vermem" ile "böyle bir belgem yok" aynı biçimde gelir.

### 15.2 Reddetmeyi okunur kılan iki kontrol

| Kontrol | Sorulan | EDM'nin cevabı |
| --- | --- | --- |
| **Pozitif** | onaylı `LoadInvoice` taslağı (var olduğu bilinen belge) | `LOAD - SUCCEED`, 16 karakter numara, `document_present_at_edm:yes` |
| **Negatif** | koşu içinde üretilen, hiç iletilmemiş UUID | **birebir aynı reddetme**, durum literali yok, numara yok |

Pozitif kontrol okumaların, kimlik bilgilerinin ve endpoint'in sağlam olduğunu
gösterir. Negatif kontrol, EDM'nin **tanımadığı bir UUID'ye** verdiği cevabın
tam olarak bu reddetme olduğunu gösterir. Bu ikisi birlikte, gönderilen belgenin
EDM'de bulunmamasını güçlü olasılık yapar.

Ne kadarını kanıtladığı konusunda dürüst olmak gerekir: bu bir **çıkarım**, EDM'in
"böyle bir belgem yok" beyanı değil. Kanıtlanan şey, reddin hiç iletilmemiş bir
UUID'nin aldığı reddin aynısı olduğudur. Reddin başka bir sebepten de aynı
biçimde gelebileceği dışlanmadı, bu yüzden `absent` verdict'i kaydı otomatik
olarak `idle`'a döndürmez ve `uncertain` yerinde kalır:

```
SANDBOX_SEND_RESOLVE=PASS|verdict:absent|status_answered:no|status_literal:none
  |status_number_length:0|status_error:edm_request_refused
  |xml_error:empty_content_returned|uuid_match:no
SANDBOX_SEND_RESOLVE_CONTROL=never_transmitted_uuid|status_answered:no
  |status_error:edm_request_refused|status_literal:none|status_number_length:0
SANDBOX_SEND_RESOLVE_REASON=refusal_matches_the_never_transmitted_control_uuid
```

Negatif kontrol her resolve koşusunun parçasıdır, elle yapılan tek seferlik bir
ölçüm değil: reddetmeyi okuyan operatör kalibrasyonu aynı çıktıda görmelidir, ve
EDM'nin bu davranışı bu depoya sabitlenecek bir gerçek değildir.

### 15.3 Yokluk asla varsayılan değildir

`kuka_sandbox_resolve_verdict()` saf ve üç değerlidir; **varsayılanı `unknown`**.

| Verdict | Ne zaman |
| --- | --- |
| `present` | EDM bu belge hakkında bir şey söyledi: saklı XML bizim UUID'imizi yansıttı, ya da durum sorgusu bir numara veya bir durum literali döndürdü |
| `absent` | EDM **cevap verdi** ve belge hakkında hiçbir şey söylemedi (literal yok, numara yok, içerik yok); ya da reddetme, hiç iletilmemiş kontrol UUID'siyle birebir eşleşti |
| `unknown` | Diğer her şey. Taşıma hatası, okunamayan cevap, UUID'imizi taşımayan içerik (o anahtarda bir şey saklı; ne olduğu belirlenmedi) |

Bu dosyadaki tek ölçümlü hata—yokluğu kanıtlamadan iddia etmek—mükerrer mali
belge üretir. `SANDBOX_RESOLVE_VERDICT` on bir şekli ölçer: boş okuma, temiz
cevap veren kontrol, kendisi numara taşıyan kontrol ve iki farklı reddetme
dahil.

`absent`, bu aracın verdict adıdır — EDM'in "böyle bir belgem yok" beyanı
değil. İkisini karıştırmamak önemli: verdict, reddin hiç iletilmemiş bir UUID'nin
aldığı reddin aynısı olduğu ölçümüne dayanır, EDM'in belge hakkındaki
açıklamasına değil. Bu yüzden `absent` kaydı kendiliğinden `idle`'a döndürmez.

Kaydı `idle`'a döndürmek ayrı ve açık bir eylemdir: `reconcile=absent`, ve yalnız
yokluk **aynı koşuda** ölçülmüşse uygulanır — çünkü gönderim kapısını yeniden
açar.

### 15.4 Yan bulgu: oturum yenilemesi gönderimi tekrarlıyordu

`Kuka_Island_Core_EDM_Client::execute_with_session()`, EDM oturum süresi bittiğini
bildirdiğinde callback'i bir kez yeniden çalıştırıyordu. Okumada bu bedavadır;
`SendInvoice`'ta **aynı belgenin ikinci kez iletilmesidir**. EDM oturum
kontrolünü gövdeyi işlemeden önce yapar, dolayısıyla pratikte ilk çağrı bir şey
yapmamış olur — ama bu, bu kodun verebileceği bir garanti değildir ve yanılmanın
bedeli bir satış için iki mali belgedir.

`send_invoice()` artık `allow_session_retry = false` geçiyor. Hata yüzeye çıkar,
manager `send_uncertain` yazar, poller EDM'e ne olduğunu sorar — tam olarak bu
durum için var olan yol. Okuma yolları değişmedi.

### 15.5 Destek paketi ve doğrulamanın kapsam sınırları

EDM request loglarını tutmadığı için gönderdiğimiz isteği istedi. Bizde de yok:
`SoapClient` trace yalnız `WP_DEBUG` ile açılıyor, entegrasyon paketi SOAP
gövdesini diske yazmıyor, kalıcı kayıt yalnız UUID ile sonucu tutuyor. Bu yüzden
istek **yeniden üretildi**: aynı üretim kodu (`send_invoice()`), aynı fixture,
aynı belge UUID'si, aynı WSDL ve aynı ext-soap serileştirmesi. Hiçbir SOAP
operasyonu ağa gitmedi — `set_session_id()` ile Login atlandı ve `SoapClient`
alt sınıfı `__doRequest()`'i override ederek zarfı yakaladı.

Buna **orijinal request denmiyor**. İki yeniden üretim koşusu arasında yalnız
saate bağlı iki alan değişiyor (`ACTION_DATE` ve CONTENT içindeki
`cbc:IssueTime`); geri kalanı aynı üretim kodu ve aynı kayıtlı girdilerden
yeniden oluşuyor. Orijinal saklanmadığı için **byte-identiklik kanıtlanamaz** ve
iddia edilmiyor.

Yapılan doğrulamanın kapsam sınırları, paketin içinde de yazılı:

- WSDL doğrulaması **SOAP zarfını ve `SendInvoice` request yapısını** kapsar:
  element sırası (`xs:sequence`), zorunlu element ve attribute, namespace formu
  (bu servis 603 element'te açık `form="unqualified"` taşır), `xs:date` /
  `xs:boolean` / `xs:long` biçimleri, boş element ve `xsi:nil`. Base64 `CONTENT`
  içindeki UBL belgesinin **UBL-TR/GİB Schematron ve EDM iş kurallarının
  tamamını geçtiğini kanıtlamaz.**
- `LoadInvoice`'in kabul edilmiş olması, **aynı UBL içeriğinin `SendInvoice`
  aşamasındaki mali ve iş kuralı doğrulamalarından geçeceğini kanıtlamaz.**
  `LoadInvoice` belgeyi taslak olarak saklar, `SendInvoice` fatura keser; bu iki
  aşamada uygulanan doğrulamaların aynı olduğu ölçülmedi.

Kabul edilen işlemle karşılaştırma yine de yararlıdır: kabul edilmiş
`LoadInvoice` isteği de aynı yolla offline yakalandığında `REQUEST_HEADER` ve
`INVOICE/HEADER` birebir aynı çıkıyor, tek yapısal fark `LoadInvoice`'e özgü
`GENERATEINVOICEIDONLOAD`. Bu, reddi bizim zarf biçimimizle açıklayan bir ölçüm
bulunmadığını gösterir — reddin sebebini göstermez.

### 15.6 EDM teknik desteğine sorulacaklar

Reddetmenin **sebebi** henüz bilinmiyor: onu raporlayacak olan koşu, teşhisi
fazla kaba olan koşuydu. Teşhis düzeltildi (`SANDBOX_SEND_ERROR_CODE` artık
client'ın kendi belirlediği güvenli kodu basıyor), ama cevabı öğrenmek yeni bir
gönderim gerektirir ve bu ayrı bir karardır.

EDM teknik desteği test kullanıcısının **bütün web servis işlemlerine yetkili**
olduğunu yazılı olarak doğruladı, dolayısıyla yetki sorusu kapandı.

Sorulacaklar — hepsi ölçülmüş olgulara dayanıyor:

1. Bu istekte EDM tarafında görünen **hata mesajı / hata kodu** nedir? EDM
   request loglarını tutmadığı için istek yeniden üretilip gönderildi (§15.5).
2. `GetInvoiceStatus`, **tanımadığı bir UUID** için hata mı döndürür? Ölçümümüz
   bunu gösteriyor; teyit ederseniz mutabakat mantığımız bu davranışa
   dayanabilir. Belge yok mesajı için ayrı bir kod varsa hangisidir?
3. `GetInvoice`, **`LoadInvoice` ile yüklenmiş bir taslak** için boş `CONTENT`
   döndürüyor. Beklenen bu mu? Taslağın XML'i hangi koşulda okunabilir?

Ayrıca, bu izole testin bilinçli seçimleri. Hiçbiri bizim tarafımızda kesin hata
olarak tespit **edilmedi**; reddin sebebi bilinmediği için EDM iş kuralları
açısından sakınca taşıyıp taşımadıkları soruluyor:

| Alan | Değer | Sorulan |
| --- | --- | --- |
| `HEADER.TO` | `sandbox-test-alici@example.invalid` | RFC 6761 ile ayrılmış, teslim edilemeyen alan adı. Test gönderiminde gerçek ve teslim edilebilir adres zorunlu mu? |
| Alıcı TCKN | `11111111111` | Test ortamında kabul edilir mi, yoksa belirli bir TCKN mi kullanılmalı? |
| Alıcı ad/soyad | sentetik | `cac:Person` isim gerektirdiği için gerçek görünümlü, kimseyi tanımlamayan ad. Sakıncası var mı? |
| `HEADER.INTERNETSALES` | `false` | Arkasında sipariş, ödeme aracısı veya gönderi olmayan bu testte `false`. e-Arşiv faturasında kabul edilir mi? |
| `INTERNETSALESDETAILS` | gönderilmedi | WSDL'de `minOccurs="0"`; `INTERNETSALES=false` iken atlanması doğru mu? |
| Alıcı `PartyTaxScheme/TaxScheme/Name` | boş | Satıcıda dolu, alıcıda (nihai tüketici) boş. Boş bırakılabilir, doldurulmalı, yoksa tamamen atlanmalı mı? |

Bu tablo `request-summary.txt` içindeki listeyle aynı tutulmalıdır: destek
paketiyle doküman farklı şeyler söylerse hangisinin gönderildiği belirsizleşir.

## 16. EDM teknik desteğinin 3 Eylül 2026 yazılı cevabı

Reddedilen `SendInvoice` için hazırlanan destek paketi (§15.5) gönderildikten
sonra EDM yazılı olarak cevap verdi. Aşağıdakiler EDM'in beyanıdır; bizim
çıkarımımız değil. Hangisinin hâlâ çıkarım olduğu ayrıca belirtiliyor.

### 16.1 Reddin sebebi: `cac:Person`'ın geçersiz UBL konumu

EDM, TCKN ile tanımlanan alıcıda **`cac:Person`'ın geçerli UBL konumunda
bulunmadığı** için isteğin reddedildiğini bildirdi.

Gerçekten gönderilen UBL'de bireysel alıcının `cac:Party` çocuk sırası şuydu:

```
PartyIdentification
Person            <-- geçersiz konum
PostalAddress
PartyTaxScheme
Contact
```

Doğru sıra `Person`'ın `Contact`'tan **sonra** gelmesidir. Bu, EDM'in kendi
WSDL'indeki `PartyType` dizisiyle de birebir örtüşür:

```
PartyIdentification, PartyName, PostalAddress, PhysicalLocation,
PartyTaxScheme, PartyLegalEntity, Contact, Person, AgentParty
```

`Kuka_Island_Core_UBL_TR_Builder::append_customer_party()` düzeltildi:
`cac:Person` düğümü hâlâ **bir kez** oluşturuluyor, fakat `cac:Contact`'tan
sonra ekleniyor. Düğüm kopyalanmadı — iki `Person`, aynı belirtiyi veren farklı
bir kusur olurdu. EDM'in e-postasındaki örnekte kalın yazılmış ikinci blok doğru
konumu gösteriyordu, iki `Person` kullanılmasını istemiyordu.

Üretilen sıra artık:

```
PartyIdentification, PostalAddress, PartyTaxScheme, Contact, Person
```

Kurumsal alıcı davranışı değişmedi: `company` doluysa `cac:PartyName` üretilir,
`cac:Person` **hiç** oluşmaz, `schemeID` `VKN` kalır.

`INVOICE_INDIVIDUAL_PERSON_USES_VALID_PARTY_ORDER` bunu kaynak araması yapmadan
ölçer: gerçek sipariş → gerçek mapper → gerçek builder XML'i, DOM ile okunup
`PartyType` dizisine karşı doğrulanır. Eski hatalı sıranın artık üretilemediği
de ayrıca kontrol edilir.

### 16.2 Rapor tarihi alanları: iş kuralı gereksiz, şema zorunlu

EDM, `EARCHIVE_REPORT_SENDDATE` ve `CANCEL_EARCHIVE_REPORT_SENDDATE` alanlarının
`SendInvoice` isteğinde **zorunlu olmadığını** ve e-Arşiv belgesinin **EDM
tarafından GİB'e raporlanma** tarihlerini ifade ettiğini yazılı olarak
doğruladı. Dolayısıyla bu tarihler EDM'in kaydedeceği olgulardır, bizim iddia
edeceğimiz değerler değil.

Alanları çıkarmayı denedik. **Teknik olarak mümkün değil** — ve bu bir okuma
değil, ölçüm:

| Deneme | Sonuç |
| --- | --- |
| İki alan **olmadan** serileştirme | `SoapFault` — `SOAP-ERROR: Encoding: object has no 'EARCHIVE_REPORT_SENDDATE' property`, **hiç zarf üretilmedi** (0 byte) |
| İki alan **ile** serileştirme (kontrol) | Başarılı, 1246 byte, her iki element birer düğüm |

Canlı test WSDL'i her ikisini de şöyle ilan ediyor:

```xml
<xs:element name="EARCHIVE_REPORT_SENDDATE"        type="xs:date" minOccurs="1" maxOccurs="1"/>
<xs:element name="CANCEL_EARCHIVE_REPORT_SENDDATE" type="xs:date" minOccurs="1" maxOccurs="1"/>
```

ext-soap `minOccurs`'u **kodlama aşamasında** uygular, yani taşımadan önce.
Atlamak reddedilen bir istek üretmiyor; **hiç istek üretmiyor**. Alanlar
kaldırılmış bırakılsaydı hiçbir fatura gönderilemezdi.

Bu yüzden alanlar **hâlâ gönderiliyor**, fakat değer artık `issue_date` değil:

```
EARCHIVE_REPORT_SENDDATE        = 0001-01-01
CANCEL_EARCHIVE_REPORT_SENDDATE = 0001-01-01
```

`0001-01-01` **uydurulmuş bir dolgu değildir**; bu şemanın dokümante edilmiş
"değer yok" ifadesidir:

- "EDM E-Fatura Web API v4 Request-Response" belgesindeki **her iki resmî
  SendInvoice request örneği** `<EARCHIVE_REPORT_SENDDATE>0001-01-01<` taşıyor.
- Resmî C# connector bu iki alana **hiç değer atamıyor**; .NET
  `DateTime.MinValue` serileştiriyor — aynı `0001-01-01`.

Önceki davranış (`issue_date`) **henüz gerçekleşmemiş bir GİB raporlama tarihi
iddia ediyordu**. Boş string veya `null` ise şema `xs:date` beklediği için
geçersizdir. Yani üç seçenekten yalnız biri hem şemayı hem de anlamı koruyor.

`INVOICE_OUTGOING_REQUEST_OMITS_REPORT_SENDDATES` bu yüzden **BLOCKED** olarak
raporlanır, PASS değil: adı "atlanıyor" diyor, atlanmıyor. Satır her koşuda
encoder'ı yeniden ölçer ve iki yarısı birlikte tutmalıdır — atlama reddedilmeli
**ve** kontrol serileşmeli; aksi hâlde katı olan şema değil, bozuk olan sondadır.
EDM ileride WSDL'i gevşetirse atlama serileşmeye başlar, beklenti kırılır ve
konu sessizce doğru kalmak yerine yeniden ölçülmeye zorlanır.

**EDM'den çözüm gerekiyor:** ya WSDL'de `minOccurs="0"` yapılmalı, ya da bu
alanlara hangi değerin yazılmasını istediklerini belirtmeleri gerekiyor.

### 16.3 Belge oluşmadığı kesinleşti

EDM, hatalı çağrıda **belge oluşmadığını kesin olarak teyit etti**. §15'te bu
bir çıkarımdı — reddin, hiç iletilmemiş bir kontrol UUID'sinin aldığı reddin
aynısı olması — ve `uncertain` kaydını çözecek şeyin EDM teyidi olduğu
yazılmıştı. Teyit geldi: çıkarım doğruydu, ve artık çıkarım değil.

### 16.4 Kabul edilen diğer noktalar

| Konu | EDM'in cevabı |
| --- | --- |
| Test e-posta adresi | Yalnız **format olarak geçerli** olması yeterli; teslim edilebilir olması gerekmiyor. `sandbox-test-alici@example.invalid` bu turda değiştirilmiyor |
| Bireysel alıcı `PartyTaxScheme/TaxScheme/Name` | **Boş olabilir**. Kaldırılmadı, doldurulmadı |
| e-Arşiv alıcı adresleme şeklimiz | **Kabul edildi**: `HEADER.TO` = müşteri e-postası, UBL `cbc:ElectronicMail` = aynı adres, `RECEIVER.alias` atlanmış |

Bu üçü, önceki turda "EDM'den kontrol istediğimiz alanlar" listesinde soru
olarak duruyordu (§15.6). Artık cevaplandılar ve davranış değişmedi — çünkü
mevcut davranış zaten doğruydu.

Alıcı TCKN `11111111111`, sentetik ad/soyad, `INTERNETSALES=false` ve
`INTERNETSALESDETAILS`'in atlanması hakkında ayrı bir itiraz gelmedi; reddin tek
sebebi olarak `cac:Person` konumu bildirildi.

## 17. İlk başarılı sandbox `SendInvoice` ve durum takibi

### 17.1 Gönderim gerçekleşti, belge numaralandı

`cac:Person` konumu düzeltildikten (§16.1) ve rapor tarihleri `0001-01-01`
yapıldıktan (§16.2) sonra tek bir `SendInvoice` daha yapıldı ve **EDM kabul
etti**. Kayıt:

```
state           = confirmed
outcome         = success
operation       = SendInvoice
assigned_number = 16 karakter (EDM atadı)
edm_status      = PACKAGE - PROCESSING
```

Kaydın geçmişi, aradan geçen mutabakatı da gösteriyor:

```
in_flight -> uncertain                                   (§15, reddedilen deneme)
          -> idle       evidence: document_absent_at_edm  (§15.3, açık mutabakat)
          -> in_flight
          -> confirmed                                    (kabul edilen gönderim)
```

`idle`'a dönüş, `reconcile=absent` ile ve yalnız yokluğun aynı koşuda ölçülmüş
olması sayesinde yapıldı — gönderim kapısını yeniden açan tek yol bu. İkinci
belge oluşmadı: aynı UUID yeniden kullanıldı, çünkü tohum deterministiktir.

### 17.2 `status=confirm`: yapısal olarak salt-okunur durum sorgusu

Kabul edilmiş bir belgenin EDM'deki **güncel** durumunu sormak için ayrı ve açık
isimli bir mod var:

```bash
./scripts/edm-sandbox-send-run.sh status=confirm
```

Tehlike sorgulamak değil; ileride birinin aynı client üzerinde
`send_invoice()`'a uzanması. Bu yüzden salt-okunurluk **yapısal**:

| Katman | Nasıl |
| --- | --- |
| Transport | `Kuka_Sandbox_Readonly_Transport` yalnız `Login`, `GetInvoiceStatus`, `GetInvoice`, `Logout` taşır. Başka her operasyon **iç transport'a ulaşmadan** reddedilir |
| Mount | Runner state dizinini **`:ro`** mount eder; state veya history yazmak imkânsız, yalnız istenmiyor değil |
| Kilit | Claim **hiç alınmaz** — kilit yazılabilir dosya gerektirir. `status()` yalnız okur |
| Kanıt | State dosyasının SHA-256'sı başta ve sonda alınıp raporlanır |
| Defter | Her operasyon kaydedilir; `SendInvoice=0` / `LoadInvoice=0` çağrı yerinin yokluğundan değil **defterden** okunur |

Mod, `confirm=SendInvoice` veya açık bir gönderim kapısıyla birlikte
çalıştırılırsa reddeder, ve `status=` yalnız literal `status=confirm` kabul eder
(`status=yes` → `invalid_status_confirmation`).

`SANDBOX_STATUS_MODE_IS_READ_ONLY` on operasyonu ölçer: dördü izinli, altısı
reddedilir (`SendInvoice`, `LoadInvoice`, `EmailInvoice`, `CancelInvoice`,
`CreateSerial` ve hiç duyulmamış bir operasyon — bilinmeyene şüphenin faydası
tanınmaz). Reddedilenlerin iç transport'a **ulaşmadığı** ayrıca kontrol edilir;
ulaşsalardı reddetme kozmetik olurdu, istek çoktan yola çıkmış olurdu.

### 17.3 Ölçülen durum: `PACKAGE - PROCESSING`, yani **pending**

```
SANDBOX_SEND_STATUS=PASS|edm_status:PACKAGE - PROCESSING|status_class:pending
  |terminal:pending|mapped_status:pending_approval|document_present_at_edm:yes
  |number_returned:yes|number_length:16|number_matches_record:yes
  |status_response_uuid_match:yes|xml_uuid_match:no|status_error:none
SANDBOX_SEND_STATUS_XML=PENDING|xml_retrieved:no|reason:empty_content_returned
SANDBOX_SEND_STATUS_OPERATIONS=PASS|observed:Login,GetInvoiceStatus,GetInvoice,Logout
  |SendInvoice:0|LoadInvoice:0|refused_write_attempts:none|logout:ok
SANDBOX_SEND_STATUS_STATE_UNCHANGED=PASS|state:confirmed|history_entries:5
  |claim_transitions:0|state_writes:0
```

Belge EDM'de **var**: durum sorgusu bizim UUID'imizi yansıttı, kaydımızdaki 16
karakterlik numarayı döndürdü ve kendi literalini verdi.

`PACKAGE - PROCESSING`, üretim tablosunda `CLASS_PENDING`. **Terminal değil**, ve
`unknown` de terminal sayılmaz: tanınmayan bir literali yerleşmiş ilan etmek, o
belgeyi izlemeyi bırakmak demektir.

Bu yüzden **nihai gönderim başarısı henüz kaydedilmedi**. `SEND - SUCCEED`
görülmedi; görülen, paketin EDM'de işlenmekte olduğu. Aradaki fark önemli:
numara atanmış olması belgenin GİB'e ulaştığını göstermez.

`GetInvoice` XML'i hâlâ boş `CONTENT` döndürüyor. §15'te bunun taslak yoluna
özgü olabileceği yazılmıştı; artık ölçülen şu: **gönderilmiş fakat henüz işlenen**
bir belge için de boş dönüyor. Neden döndüğü ve hangi koşulda XML verileceği
hâlâ ölçülmemiştir.

### 17.4 Tekrar gönderim yok, sonsuz polling yok, arka plan süreci yok

Durum pending olduğu için hiçbir şey yapılmadı. Yeniden gönderilmedi, polling
döngüsü kurulmadı ve **hiçbir arka plan süreci başlatılmadı** — bir sonraki
kontrol istenirse komut elle tekrar çalıştırılır:

```
SANDBOX_SEND_STATUS_NEXT_CHECK=pending|not_resent:yes|no_polling_loop:yes
  |no_background_process_started:yes|earliest_useful_recheck_utc:...
  |command:status=confirm
```

Önerilen en erken yeniden kontrol, ölçüm anından **30 dakika** sonrasıdır.
Bu bir zamanlama değil, bir öneridir; çalışan hiçbir iş yoktur.
