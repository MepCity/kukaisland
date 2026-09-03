# EDM Aktivasyon Rehberi

Bu rehber, `kuka-island-edm` eklentisini pasif teslim durumundan canlı otomatik
gönderime kadar **adım adım** taşır. Her adım kendi kanıtını üretir ve bir
sonraki adım ancak o kanıt varsa açılır.

Neden bu kadar kademeli: mali belge **geri alınamaz**. Yanlış kesilmiş bir
faturayı iptal etmek ayrı bir mali işlemdir. Bu yüzden hiçbir adım bir
sonrakini otomatik açmaz.

Teknik sözleşme: [EDM_ENTEGRASYONU.md](EDM_ENTEGRASYONU.md).
Bakım kayıtları: [EDM_BAKIM_HAFIZASI.md](EDM_BAKIM_HAFIZASI.md).

---

## Aşama 0 — Pasif teslim (mevcut durum)

Eklenti pakette bulunur fakat **etkin değildir**. Pasifken WordPress bu
eklentinin **hiçbir dosyasını yüklemez**; dolayısıyla:

- EDM sınıfları ve bağımlılıkları yüklenmez
- EDM yönetim paneli görünmez
- fatura/kargo/gönderim/poll hook'ları kayıt edilmez
- SOAP bağlantısı kurulmaz
- siparişlere EDM metası yazılmaz
- Action Scheduler işi oluşmaz

WooCommerce sipariş, ödeme, manuel fatura ve manuel kargo süreçleri **aynen**
çalışır.

**Doğrulama:**

```bash
make verify
```

Beklenen (gerçek WordPress runtime'ında ölçülür, kaynak taramasıyla değil):

```
EDM_PASSIVE_PLUGIN_STATE=PASS|plugin_file_present:yes|plugin_active:no|core_active:yes|...
EDM_PASSIVE_CLASSES_ABSENT=PASS|declared:none|soap_client_loadable:no
EDM_PASSIVE_HOOKS_ABSENT=PASS|edm_callbacks:none|own_action_hooks_registered:none
EDM_PASSIVE_ACTIONS_ABSENT=PASS|by_hook:none|by_group:none
EDM_PASSIVE_ORDER_LIFECYCLE=PASS|transitions:processing->completed|invoice_meta_keys:none|actions_booked:none
EDM_PASSIVE_CORE_INTACT=PASS|core_loads_invoice_module:no|dependency_direction:edm_to_core_only
```

Aktivasyon ve deaktivasyonun kendisi de ölçülür — dosyaları doğrudan `require`
etmek plugin bootstrap'ini, aktivasyonu, deaktivasyonu ve bağımlılık kusurlarını
kanıtlamaz. `scripts/verify-edm-activation-lifecycle.sh` gerçek
`wp plugin activate` / `deactivate` yolunu kullanır ve her durumu **yeni bir
WordPress sürecinde** ölçer; başlangıç durumunu snapshot alıp başarısızlıkta
bile geri yükler:

```
EDM_LIFECYCLE_ACTIVATION=PASS|composition_root:loaded|booted:yes|missing_deps:none|hooks_unregistered:none|runtime_gate_open:yes|auto_send_off:yes|SendInvoice:0|LoadInvoice:0
EDM_LIFECYCLE_DEACTIVATION=PASS|classes_declared:none|hooks_registered:none|pending_edm_actions:0|core_works:yes|invoice_meta_preserved:yes
EDM_LIFECYCLE_RESTORED=PASS|edm:inactive|gate_option:no|active_plugins_identical:yes|sandbox_state_touched:no
```

Deploy paketinin içeriği de kalıcı sözleşmedir
(`scripts/verify-deploy-package.sh`): gerçek bir arşiv üretilip listesi
okunur, 13 zorunlu yolun tamamı aranır ve pakete kimlik dosyası sızmadığı
doğrulanır.

`install.sh` bu eklentiyi **etkinleştirmez** ve daha önce bilinçli olarak
etkinleştirilmişse **devre dışı bırakmaz**; yalnız durumu bildirir:

```
EDM_PLUGIN=inactive|delivery_state:as_designed|activation:manual_with_checklist
```

---

## Aşama 1 — Test aktivasyonu

Yalnız test ortamında. Canlı kimlik bilgileri **henüz girilmez**.

```bash
docker compose run --rm wp-cli wp plugin activate kuka-island-edm
```

Aktivasyon iki şey yapar ve fazlası **yapmaz**: sürüm option'ını yazar ve
çalışma kapısını açar. Mevcut siparişler için **hiçbir iş kuyruğa alınmaz** —
eklentiyi yeniden açmak, daha önce faturalanmış ya da reddedilmiş bir siparişi
yeniden göndermek için bir sebep değildir.

**Kontrol listesi:**

- [ ] `wp plugin list` → `kuka-island-edm` `active`
- [ ] Bağımlılık uyarısı **yok** (WooCommerce ve `kuka-island-core` yüklü).
      Eksikse eklenti fail-closed davranır: hiçbir hook açılmaz, yalnız yönetici
      panelinde neden çalışmadığını söyleyen bir uyarı görünür.
- [ ] `KUKA_INVOICE_AUTO_SEND` **kapalı**
- [ ] `make verify` → `VERIFY=PASS`

---

## Aşama 2 — Readiness kontrolü

Mali hazırlık alanları eksikse hiçbir gönderim başlamaz. Bu aşamada yalnız
**okuyup** eksikleri tamamlarsınız.

**Kontrol listesi:**

- [ ] Satıcı VKN, unvan, vergi dairesi, adres, il/ilçe, posta kodu
- [ ] Gönderen alias (`urn:mail:...`)
- [ ] `APPLICATION_NAME = ozelyazilim.kukaisland` (bkz. K-02)
- [ ] KDV oranları ve para birimi
- [ ] Sipariş ekranında fatura kutusunun göründüğü

Eksik alan varsa gönderim yolu `blocked` verir; bu **doğru** davranıştır.

---

## Aşama 3 — Salt-okunur EDM kontrolleri

Hiçbir belge oluşturmayan, hiçbir şey yazmayan çağrılar. İlk gerçek temas
budur.

```bash
./scripts/edm-test-probe.sh          # kimlik yoksa BLOCKED yazar, ağa çıkmaz
```

**Kontrol listesi:**

- [ ] Endpoint allow-list'ten geçiyor (`environment:test`, host/path birebir)
- [ ] `Login` başarılı
- [ ] `CheckUser` / `CheckCounter` okunabiliyor
- [ ] Hiçbir yazma operasyonu çağrılmadı

**Yapılmayacak:** bu aşamada `SendInvoice` veya `LoadInvoice` **yok**.

---

## Aşama 4 — Kontrollü manuel pilot

Tek belge, açık onayla, izole araçla. Otomatik kuyruk hâlâ kapalı.

Önce **PLAN** (hiçbir şey iletilmez):

```bash
./scripts/edm-sandbox-send-run.sh
```

PLAN'ın her satırı `PASS` ise, ve **yalnız o zaman**, tek gönderim:

```bash
KUKA_EDM_ALLOW_SANDBOX_SEND=true ./scripts/edm-sandbox-send-run.sh confirm=SendInvoice
```

İki ayrı literal kapı gerekir ve LoadInvoice kapısıyla **birlikte** açılamaz
(host seviyesinde reddedilir). Gönderim **en fazla bir kez** yapılır; belirsiz
yanıtta otomatik ikinci çağrı **yasaktır** (bkz. K-11).

**Kontrol listesi:**

- [ ] PLAN'da `SANDBOX_SEND_WRITE_OPERATIONS=NONE|count:0`
- [ ] Gönderimden sonra `state=confirmed`, `outcome=success`
- [ ] EDM numara atadı (16 karakter), kaynak yalnız yanıt

---

## Aşama 5 — Durum / numara / XML doğrulaması

Gönderim sonrası **yalnız okuma**:

```bash
./scripts/edm-sandbox-send-run.sh status=confirm
```

Bu mod yapısal olarak salt-okunurdur: allow-list'li transport yalnız `Login`,
`GetInvoiceStatus`, `GetInvoice`, `Logout` taşır; state dizini `:ro` mount
edilir; claim alınmaz; state dosyasının SHA-256'sı başta ve sonda raporlanır.

**Kontrol listesi:**

- [ ] `document_present_at_edm:yes`
- [ ] `status_response_uuid_match:yes`
- [ ] `number_matches_record:yes`
- [ ] `edm_status` literali ve `terminal:` değeri kaydedildi
- [ ] `SendInvoice:0`, `LoadInvoice:0`
- [ ] `sha256_before == sha256_after`

**`terminal:pending` ise:** yeniden gönderme, polling döngüsü kurma. Durumu
pending olarak kaydet ve çıktının önerdiği en erken zamandan sonra komutu
**elle** tekrar çalıştır. Çalışan arka plan izleyicisi **yoktur** (K-17).

---

## Aşama 6 — Canlı kimlik bilgileri

Ayrı ve bilinçli bir adım.

**Kurallar:**

- Kimlik bilgileri **repoya, `wp-config.php`'ye, option'a, log'a veya commit'e
  yazılmaz**.
- Kimlik dosyası repo dışında, mod `600`, dizin `700`.
- Canlı endpoint'e geçiş, test endpoint allow-list'inin **bilinçli** olarak
  değiştirilmesini gerektirir; kazara olmaz.

**Kontrol listesi:**

- [ ] Canlı WSDL sözleşmesi test WSDL'i ile karşılaştırıldı
- [ ] Canlı ortamda yalnız salt-okunur çağrılarla `Login` doğrulandı
- [ ] Kimlik dosyası izinleri doğrulandı

---

## Aşama 7 — Seri ve mali bilgiler

- [ ] Seri kodu **EDM'de tescilli** (uydurulmuş seri kullanılmaz)
- [ ] `GetInvoiceSerial` ile tescil **gözlemlendi**; gözlemlenemezse seri
      gönderilmez ve EDM kendi serisini kullanır
- [ ] Numara kaynağı yalnız EDM yanıtı (K-07)

---

## Aşama 8 — Manuel gönderim açık, auto-send **kapalı**

Bu, canlıda ilk gerçek dönem. Operatör her belgeyi sipariş ekranından **elle**
gönderir. Otomatik kuyruk hâlâ oluşmaz.

**Kontrol listesi:**

- [ ] `KUKA_INVOICE_AUTO_SEND` kapalı
- [ ] Birkaç gerçek sipariş elle faturalandı ve numarası doğrulandı
- [ ] `PACKAGE - PROCESSING` → terminal duruma geçiş gözlemlendi
- [ ] Hata yollarının bıraktığı durumlar (`blocked`, `send_uncertain`)
      operatöre anlaşılır görünüyor

Bu dönem **acele geçilmez**. Terminal durumu en az bir kez uçtan uca
gözlemlenmeden sonraki aşamaya geçilmez.

---

## Aşama 9 — Auto-send açılışı (ayrı onay)

**Yalnız kullanıcının açık onayıyla.**

`KUKA_INVOICE_AUTO_SEND` açıldığında otomatik akış çalışır, fakat **yalnız**
mevcut kapıların tamamı geçerse: readiness, ödeme, kargo/fulfillment,
idempotency ve transmission-evidence. Kapılardan biri geçmezse sipariş
`blocked` kalır — bu doğru davranıştır, düzeltilecek bir hata değildir.

**Kontrol listesi:**

- [ ] Aşama 8 en az bir tam terminal döngü ile tamamlandı
- [ ] Kullanıcı auto-send için **ayrıca** onay verdi
- [ ] Açıldıktan sonra ilk otomatik belge elle doğrulandı

---

## Aşama 10 — Geri kapatma ve deaktivasyon kontrolü

Her aşamadan geri dönülebilir.

**Auto-send'i kapatmak:** `KUKA_INVOICE_AUTO_SEND` kapatılır. Kuyruğa alınmış
işler varsa bekleyenler iptal edilebilir; **tamamlanmış** işler ve sipariş
metası silinmez.

**Eklentiyi devre dışı bırakmak:**

```bash
docker compose run --rm wp-cli wp plugin deactivate kuka-island-edm
```

Deaktivasyon **yalnız** şunları yapar:

1. Kalıcı çalışma kapısını **kapatır** — ilk iş bu. Zaten çalışmakta olan bir
   worker `SendInvoice`'a **ulaşamaz**; kapı `mark_sending()`'den önce ve
   object cache'i atlayarak okunur, dolayısıyla aynı istek içindeki bir
   değişikliği görür (K-19).
2. **Kendi** bekleyen Action Scheduler işlerini iptal eder:
   `kuka_island_process_order_invoice`, `kuka_island_query_invoice_status`,
   `kuka-island-invoice` grubu.

Deaktivasyon **yapmaz:**

- sipariş fatura metalarını silmek
- fatura geçmişini silmek
- UUID/numara kayıtlarını silmek
- `superseded` belge kayıtlarını silmek
- başka eklentilerin action'larına dokunmak

Devre dışı bir entegrasyonun kendi denetim izini silmesi, operatörün "ne
kesilmişti?" sorusuna cevap verememesi demektir — ve bu, tam olarak faturalama
kapatıldıktan sonra önemli olan sorudur.

**Kontrol listesi:**

- [ ] `wp plugin list` → `inactive`
- [ ] `make verify` → `EDM_PASSIVE_*` satırlarının tamamı `PASS`
- [ ] Bekleyen EDM action sayısı **0**
- [ ] Sipariş fatura metaları **yerinde**

**Yeniden aktivasyon:** eski bir sipariş otomatik olarak yeniden
gönderilmez. Transmission-evidence ve mutabakat kuralları korunur.
