# AGENTS.md — Kuka Island EDM

Bu dosya bu modülde çalışan agent için **bağlayıcıdır**. Buradaki kurallar
öneriler değil; ihlali mali sonuç doğurur.

## Önce oku

Kod değiştirmeden önce, sırayla:

1. `docs/EDM_BAKIM_HAFIZASI.md` — doğrulanmış kök nedenler ve "tekrar
   yaşanırsa ilk bak" kayıtları. Buradaki 19 kaydın çoğu, bir kez yanlış
   yapılmış ve ölçümle düzeltilmiş şeylerdir.
2. `docs/EDM_AKTIVASYON_REHBERI.md` — hangi seviyede ne yapılabilir.
3. `docs/EDM_ENTEGRASYONU.md` — güncel teknik sözleşme.

Bir davranışı değiştirmeden önce onu **ölç**. Bu modüldeki her sözleşme bir
davranış testiyle kilitlidir; kaynak okuyup varsaymak yeterli değildir.

## EDM'ye yazan çağrılar

`SendInvoice`, `LoadInvoice`, `EmailInvoice`, `reconcile`, `reset` —
**kullanıcının o tura ait açık izni olmadan çalıştırılmaz.** Geçmiş bir turda
verilmiş izin bu tur için geçerli değildir. İzin bir kez içindir.

Salt-okunur çağrılar (`Login`, `GetInvoiceStatus`, `GetInvoice`, `Logout`)
gerekçesi yazılarak yapılabilir.

## Belirsiz kayıtta yeniden gönderim yok

Bir belge `uncertain` ya da belirsiz bir durumdaysa **yeniden gönderilmez**.
Belgenin var olup olmadığı yalnız salt-okunur mutabakatla belirlenir. Yokluğu
**kanıtlamadan** iddia etmek bu modüldeki tek ölümcül hatadır: mükerrer mali
belge üretir.

`absent` verdict'i EDM'in beyanı değil, bu araçların ölçümüdür. Kaydı `idle`'a
döndürmek ayrı ve açık bir eylemdir.

## Kimlik bilgileri

Kullanıcı adı, parola, secret key, session ID, tam belge UUID'si, tam fatura
numarası ve SOAP gövdesi **repoya, log'a, çıktıya veya commit'e yazılmaz**.
Raporlarda maskeli biçim kullan: `uuid_prefix`, `number_length`.

Kimlik dosyası repo dışında, mod `600` kalır. `wp-config.php`, option veya
kaynak dosyaya kimlik yazılmaz.

## Dokunulmayacaklar

- `wp-content/plugins/kuka-island-core/assets/admin-orders.css` ve kargo
  çekmecesindeki `height:auto; overflow:visible; overscroll-behavior:auto`
  kuralı. Bu bir koruma sözleşmesidir; bkz.
  `docs/KARGO_SCROLL_KORUMA_NOTU.md`.
- WooCommerce ve diğer vendor dosyaları.
- Iyzico ödeme korumaları.
- DHL'den bağımsız manuel fulfillment davranışı. DHL VKN/unvanı **uydurulmaz**.
- Mevcut sandbox state dosyaları ve sipariş fatura metaları.

## Bağımlılık yönü

`kuka-island-edm → kuka-island-core`. **Asla tersi.** Core bu eklentiye bağımlı
hâle getirilemez; Core, eklenti pasifken fatal vermeden çalışmak zorundadır.

Eksik bağımlılıkta fail-closed: hiçbir hook açılmaz, hiçbir gönderim yolu
kurulmaz.

## Commit ve push

- Commit mesajı **tek satırlık kısa subject**tir.
- Commit **body eklenmez**.
- `Co-Authored-By` satırı eklenmez.
- Claude / Anthropic / OpenAI / herhangi bir AI atfı eklenmez.
- **Push yapılmaz.** Kullanıcı ayrıca onaylamalıdır.

## Auto-send

`KUKA_INVOICE_AUTO_SEND` veya `KUKA_EDM_AUTO_SEND` **açılmaz**. Açılması ayrı
bir kullanıcı onayı gerektirir ve aktivasyon rehberinin son aşamasıdır.
