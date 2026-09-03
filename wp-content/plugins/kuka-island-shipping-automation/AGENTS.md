# AGENTS.md — Kuka Island Shipping Automation

Bu dosya bu modülde çalışan agent için **bağlayıcıdır**. Buradaki kurallar
öneri değil; ihlali müşteriye gitmeyen ya da iki kez giden kargo üretir.

## Önce oku

Kod değiştirmeden önce, sırayla:

1. `docs/DHL_BAKIM_HAFIZASI.md` — doğrulanmış kök nedenler ve "tekrar yaşanırsa
   ilk bak" kayıtları.
2. `docs/DHL_AKTIVASYON_REHBERI.md` — hangi seviyede ne yapılabilir.
3. `docs/DHL_ENTEGRASYONU.md` — güncel teknik sözleşme.

Bir davranışı değiştirmeden önce onu **ölç**. Bu modüldeki her sözleşme bir
davranış testiyle kilitlidir; kaynak okuyup varsaymak yeterli değildir.

## API alanı uydurma

Yol, alan adı, sayısal kod ve durum sözlüğü **yalnız** resmî OpenAPI
dosyalarından alınır:

```
~/.config/kuka-island/dhl-openapi/Identity_API-1.0.json
~/.config/kuka-island/dhl-openapi/Standard_Command_API-1.0.json
~/.config/kuka-island/dhl-openapi/Barcode_Command_API-1.0.json
~/.config/kuka-island/dhl-openapi/Standard_Query_API-1.0.json
~/.config/kuka-island/dhl-openapi/CBS_Info_API-1.0.json
```

`SHA256SUMS` ile doğrulanır; dosyalar değiştiyse önce
`scripts/verify-dhl-openapi-contract.sh` çalıştırılır.

Satıcının yazım hataları **düzeltilmez**: `/updateorder` küçük harftir,
`/cancelorder/{refrenceId}` parametresi böyle yazılmıştır. Sunucu niyeti değil
yazımı uygular.

## Taşıyıcıya yazan çağrılar

`createOrder`, `createbarcode`, `updateorder`, `updateshipment`, `cancelorder`,
`cancelshipment` — **kullanıcının o tura ait açık izni olmadan çalıştırılmaz.**
Geçmiş bir turda verilmiş izin bu tur için geçerli değildir.

Salt-okunur çağrılar (`/token`, `getorder`, `getshipment`, `getshipmentstatus`,
`trackshipment`, CBS listeleri) gerekçesi yazılarak yapılabilir.

## Belirsiz sonuçta yeniden gönderim yok

Bir yazma çağrısı `uncertain` döndüyse **tekrarlanmaz**. Kaydın var olup
olmadığı yalnız salt-okunur mutabakatla belirlenir; yokluk `getshipment` ve
`getorder` sorgularının **ikisinin de** `not_found` demesiyle kanıtlanır.
Timeout yokluk kanıtı değildir.

`STATE_RECONCILE_REQUIRED` durumundan çıkış yalnız okumayla olur. Bu kuralı
gevşetmek bu modüldeki tek ölümcül hatadır: aynı paket iki kez kargolanır.

## Kimlik bilgileri

Client ID, client secret, müşteri numarası, parola ve JWT **repoya, log'a,
çıktıya, sipariş notuna veya commit'e yazılmaz**. Maskeli biçim de yazılmaz;
yalnız varlık bilgisi (`has_client_id:yes`) raporlanır.

JWT diske veya veritabanına **yazılmaz**; süreç içi tutulur.

Kimlik dosyası repo dışında, mod `600` kalır:
`~/.config/kuka-island/dhl-sandbox.env`.

## Dokunulmayacaklar

- `wp-content/plugins/kuka-island-core/assets/admin-orders.css` ve kargo
  çekmecesindeki `height:auto; overflow:visible; overscroll-behavior:auto`
  kuralı. Bkz. `docs/KARGO_SCROLL_KORUMA_NOTU.md`.
- `admin-orders.js` adında bir dosya oluşturulmaz; MutationObserver,
  `wheel/touchmove` yönlendirmesi ve `body/html` scroll kilidi eklenmez.
- WooCommerce ve diğer vendor dosyaları.
- Manuel fulfillment davranışı. Operatör her zaman elle takip numarası
  girebilmelidir.
- `kuka-island-edm`. Bu modül EDM'i aktive etmez, EDM ayarına dokunmaz.

## Bağımlılık yönü

`kuka-island-shipping-automation → kuka-island-core`. **Asla tersi.** Core bu
eklentiye bağımlı hâle getirilemez; Core, eklenti pasifken fatal vermeden
çalışmak zorundadır.

Eksik bağımlılıkta fail-closed: hiçbir hook açılmaz, hiçbir gönderim yolu
kurulmaz.

## Canlı ortam

`KUKA_DHL_ENVIRONMENT=live` **bloke**dir. Resmî dokümanlarda tek sunucu
sandbox'tır. Blok, doğrulanmış üretim base URL'i bu sınıfa eklenerek kalkar;
boolean çevirerek değil.

## Kapıda ödeme

`isCOD` her zaman `0` gönderilir. Kapıda ödeme siparişleri reddedilir. Açılması
ayrı bir iş kuralı doğrulaması gerektirir.

## Otomasyon

`KUKA_SHIPPING_AUTOMATION` **açılmaz**. Açılması ayrı bir kullanıcı onayı
gerektirir ve aktivasyon rehberinin son aşamasıdır. Açık olsa bile hiçbir hook
gönderi **oluşturmaz**; yalnız sınırlı durum sorgusu zinciri çalışır.

## Commit ve push

- Commit mesajı **tek satırlık kısa subject**tir.
- Commit **body eklenmez**.
- `Co-Authored-By` satırı eklenmez.
- Claude / Anthropic / OpenAI / herhangi bir AI atfı eklenmez.
- **Push yapılmaz.** Kullanıcı ayrıca onaylamalıdır.
