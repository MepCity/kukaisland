# Müşteri e-postası tasarım sözleşmesi

Bütün müşteri işlem e-postaları tek bir iskelet, tek bir stil katmanı ve tek
bir görsel kapısı kullanır. Bu dosya o sözleşmeyi ve nedenlerini yazar.

## Nerede yaşıyor

| Katman | Dosya |
|---|---|
| Stil, logo, banner, taşıyıcı adı, görsel kapısı | `wp-content/plugins/kuka-island-core/includes/class-email-design.php` |
| Belge iskeleti (üst yarı) | `wp-content/themes/kuka-island-child/woocommerce/emails/email-header.php` |
| Belge iskeleti (alt yarı) | `.../emails/email-footer.php` |
| Kargo bilgisi bloğu | `.../emails/email-fulfillment-details.php` |
| Konu, başlık, giriş metni | `.../kuka-island-core/includes/class-fulfillments.php` |
| Sipariş diline göre anahtar | `.../kuka-island-core/includes/class-language.php` |
| Ölçüm | `scripts/verify-email-design.php` |

E-posta CSS'i storefront CSS'ine **bağlanmaz**. Bir e-posta istemcisinde flex,
grid, harici stil sayfası, CSS değişkeni ve `position` yoktur; stil
`woocommerce_email_styles` kancasından verilir ve Emogrifier onu elemanlara
gömer. `@media` bloğu belgede kalır, mobil istemcide çalışır.

## Neden üç vendor şablonu kopyalandı

Kopyalama son çare olarak yapıldı ve üçü de küçük dosyalardır. Gerekçeler
filtrelenemeyen HTML'dir:

1. **`email-header.php` (kaynak sürüm 10.7.0)** — WooCommerce içeriği
   `<td width="600">` ile sabit 600 piksele kilitler. Bu bir HTML niteliğidir;
   filtresi yoktur ve CSS ile güvenilir biçimde geri alınamaz. 104 pikselik
   ürün görseli, ad, varyasyon, adet ve fiyatın yan yana durabilmesi için
   sözleşme **760-800 piksel** aralığıdır; uygulanan değer 780'dir. Kopya ayrıca
   dış zemini ekran genişliğine yayar, mobilde 18 piksel güvenli kenar bırakır
   ve Outlook için hayalet tablo ekler.
2. **`email-footer.php` (10.4.0)** — başlıkta açılan etiketleri kapatır. İkisi
   bir belgenin iki yarısıdır; biri değişince öteki de değişmek zorundadır.
3. **`email-fulfillment-details.php` (10.7.0)** — WooCommerce'in bloğu
   müşteriye "Fulfillment summary" başlığı, **ham taşıyıcı anahtarı** (`dhl`),
   takip adresi boşken bile `<a href="">`, misafir siparişinde bile
   "Hesabım > Siparişler" bağlantısı ve 48 pikselik görsel basar. Hiçbirinin
   kancası yoktur.

Geri kalan her şey kancayla çözülür: `woocommerce_email_styles`,
`woocommerce_email_header`, `woocommerce_email_footer`,
`woocommerce_order_item_thumbnail`, `woocommerce_order_item_name`,
`woocommerce_date_format`, `option_woocommerce_email_header_image`.

**Sürüm farkı testi:** `EMAIL_DESIGN_TEMPLATE_DRIFT` üç kopyanın yukarı akış
`@version` değerini karşılaştırır. WooCommerce şablonu güncellerse ölçüm FAIL
verir ve kopya elden geçirilir. `WOOCOMMERCE_OVERRIDES=8` sayısı da sabitlidir:
dördüncü bir e-posta şablonu sessizce kopyalanamaz.

## Görsel kapısı — tek kural, ve ne kanıtlamadığı

`Email_Design::image_gate()` bir adresi yalnız şu koşullarda geçirir: şema
**https**, sunucu adı **biçim olarak** yerel ya da özel bir aralık değil,
uzantı **SVG değil**. Red gerekçesi ölçülebilir bir koddur: `not_https`,
`private_host`, `vector`, `empty`.

**Kapı gerçek erişilebilirliği ÖLÇMEZ.** DNS sorgusu yapmaz, HTTP isteği
atmaz. `https://yanlis-alan-adi.example.com/a.jpg` ya da 404 veren bir yol
buradan geçer ve müşteride yine kırık resim olur. Kapının işi tek bir sınıf
hatayı kesmektir: adresin erişilemez olduğu **biçiminden** belli olan durum —
`http://localhost:8080/...` gibi. Gerçek erişilebilirlik ancak üretim alan
adında bir gönderimle görülür.

Bu kapı olmadan yerel ortamda ürün fotoğrafının adresi
`http://localhost:8080/wp-content/uploads/...` olur. Şablon `show_image=true`
kullanır ve **görsel gerçekten vardır**; Gmail'de görünmemesinin nedeni
şablonda resim olmaması değil, Gmail'in `localhost` adresine erişememesidir.
Kapı reddettiğinde kırık resim çerçevesi basılmaz: temiz tipografik satıra
dönülür ve HTML'e adres taşımayan bir yorum düşülür
(`<!-- kuka-image-gate:not_https -->`).

Aynı kapı üç yerde çalışır: ürün satırı, WooCommerce'in kendi sipariş satırı
görseli (`customer_processing_order` ve kardeşleri) ve logo.

## Logo ve wordmark

Logo panelden gelir: **Site Görünümü > Marka > Logo**, yalnız attachment ID
saklanır. Adres kapıdan geçerse `<img>`, geçmezse **tipografik wordmark**
(mağaza adının büyük harfli hâli) yazılır. Hayalî ya da yeni bir logo
üretilmez, SVG ve yerel adres dış e-postaya konulmaz.

### Panelin şu andaki hâli — 4 Eylül 2026

`logo_id` **0**, `email_banner_id` **0**. Yani bu kurulumda e-postada
**logo görseli yok**: müşteri tipografik "KUKA ISLAND" wordmark'ını görüyor ve
banner hiç render edilmiyor. Ölçüm bunu açıkça yazar
(`configured_logo_id:0`, `panel_banner_id:0`); wordmark'ın görünmesi logonun
yapılandırıldığı anlamına **gelmez**, tam tersini gösterir. Operatör panelden
bir logo seçtiğinde ve o görselin adresi halka açık HTTPS olduğunda `<img>`
çıkar — ölçümün `public_logo_img:2` satırı bunu aynı kod yolundan kanıtlar.

## Banner

**Site Görünümü > Marka > E-posta kapak/banner görseli** isteğe bağlıdır. Alan
boşsa banner **hiç render edilmez**. Doluysa işlem bilgilerinin **altında**,
altbilgiden önce tek yatay şerit çıkar; yalnız marka atmosferi ve mağaza
bağlantısı taşır. Pazarlama izni olmayan müşteriye ürün öneri listesi
basılmaz. Sağda solda yüzen dekoratif görsel yoktur; Outlook ve mobilde bozulur.

## Dil

Konu, başlık, etiket ve giriş metni siparişin diline göre seçilir
(`_kuka_order_locale`). İngilizce metin **yazılıdır**, çeviri katmanından
beklenmez: `switch_to_locale( 'en_US' )` sonrasında `woocommerce` alanının
tr_TR girdileri bellekte kalabiliyor (bkz. K-45, K-46).

Müşteri metninde **hiçbir zaman** görünmeyecek ifadeler: `Woo!`, `öğe`,
`yerine getirildi`, `fulfillment`. Ölçüm bunları beş ayrı render üzerinde sayar
ve toplam 0 olmak zorundadır.

Şablonlardaki iki dilli metinler `__()` ile **sarılmaz**, ikisi de yazılıdır
ve sipariş diline göre seçilir. Bu yüzden "Paketinizdeki ürünler" ya da
"Kargonu takip et" hiçbir `.pot` dosyasında görünmez; bu bir eksik değil,
K-45/K-46'nın sonucudur. Core sınıflarındaki konu ve başlık metinleri ise
`__()` ile sarılıdır ve `kuka-island-core.pot` içindedir.

Tarih biçimi: site seçeneği `F j, Y` olduğu için Türkçe bir e-postada
"Eylül 4, 2026" çıkıyordu. `woocommerce_date_format` filtresi biçimi **yalnız
bir e-posta render edilirken** `j F Y` yapar; operatörün site ayarı
değiştirilmez, sipariş ekranı ve mağaza etkilenmez.

## Erişim bağlantısı

- Misafir sipariş: **süreli, imzalı** sipariş takip bağlantısı
  (`Membership::tracking_link()`, `kuka_track=`). E-posta adresi ya da sipariş
  numarası adrese yazılmaz.
- Hesaba bağlı sipariş **ve üyelik açık**: "Siparişlerimi görüntüle".
- Üyelik kapalı: hesaba bağlı siparişte de Hesabım bağlantısı **yoktur** —
  müşteriyi var olmayan bir sayfaya göndermek olurdu. Hesap açmaya yönlendiren
  metin bulunmaz.

Takip adresi yoksa ya da `http(s)` değilse **düğme basılmaz**; boş `href`
üretilmez.

## İletişim formu

`[kuka_contact_form]` kısa kodu Türkçe ve İngilizce iletişim sayfasında aynı
güvenli teslim yolunu kullanır. Ziyaretçiden yalnız e-posta adresi, konu ve
mesaj alınır. Alıcı **Site Görünümü > Marka** bölümündeki yayınlanmış marka
e-postasıdır; SMTP gönderen kimliği her zaman site alanında kalır, ziyaretçi
adresi yalnız `Reply-To` olur. Böylece SPF/DMARC göndereni taklit edilmez ve
yanıt düğmesi ziyaretçiye döner.

Form nonce, görünmez honeypot, katı alan türü ve uzunluk kontrolü, CR/LF
header-injection reddi, IP ve IP/e-posta çifti oran sınırı kullanır. Oran
anahtarlarında IP ve e-posta açık metin tutulmaz; HMAC özeti transient anahtarı
olur. Bir gönderim yalnız bir kez `wp_mail()` çağırır. Kesin posta reddinde
otomatik ikinci deneme yoktur ve ziyaretçiye taşıyıcı hata metni gösterilmez.
Post/Redirect/Get dönüşündeki sorgu yalnız tek kullanımlık rastgele sonuç
anahtarı taşır; e-posta, konu ve mesaj URL'ye yazılmaz.

Mevcut kurulumlar için göç yalnız eski, birebir eşleşen “form devre dışı”
kutusunu kısa kodla değiştirir. Operatörün sonradan özelleştirdiği iletişim
metni eşleşmiyorsa sessizce ezilmez.

Davranış ölçümü `scripts/verify-contact-form.php` içindedir ve gerçek SMTP'ye
çıkmadan geçerli gönderimi, anlık tekrarı, altı bozuk girdi biçimini, kesin
posta reddini, Reply-To üstünlüğünü ve sır sızıntısını sınar. Tarayıcı kabulü:
TR/EN sayfada form birer kez; masaüstünde 420 piksel, 390 piksel viewport'ta
358 piksel; yatay taşma sıfır.

## Ölçümler

`make verify` içinde, `scripts/verify-email-design.php measure`:

| Satır | Ne kanıtlar |
|---|---|
| `EMAIL_DESIGN_RENDERS` | TR/EN × processing/kargo, dördü de kendi tetikleyicisinden |
| `EMAIL_DESIGN_LAYOUT` | 780 px, mobil sorgu var, `width="600"` niteliği 0, ortak başlık/altbilgi 4/4 |
| `EMAIL_DESIGN_COPY` | konu, etiket, adla selamlama, yasak ifade 0 |
| `EMAIL_DESIGN_IMAGES` | yerelde `<img>` 0, halka açık HTTPS'te 1, alt metin ürün adından, kapının yedi gerekçesi |
| `EMAIL_DESIGN_LOGO` | logo yoksa wordmark, varsa ve halka açıksa `<img>`, varsa ama yerelse yine wordmark |
| `EMAIL_DESIGN_ACCESS` | misafirde Hesabım 0, imzalı bağlantı 1, üyelik açıkken Hesabım var, adres yokken düğme 0, boş `href` 0 |
| `EMAIL_DESIGN_BANNER` | panelde seçili banner kimliği, alan boşken 0, doluyken 1, ürünler yine görünür |
| `EMAIL_DESIGN_SECRETS` | parola müşteri HTML'inde 0; kullanıcı adı bu kurulumda mağazanın **yayınlanmış** adresiyle aynı ve modül şablonlarında 0 |
| `EMAIL_DESIGN_ADMIN` | yönetici iletisi çalışıyor, müşteri etiketi taşımıyor |
| `EMAIL_DESIGN_PLAIN_TEXT` | düz metinde HTML etiketi 0, takip numarası var |
| `EMAIL_DESIGN_TEMPLATE_DRIFT` | üç kopya yukarı akış sürümüne sabit |

İletişim formu ayrı olarak `CONTACT_FORM_DELIVERY` satırıyla ölçülür; bu satır
SMTP sunucusuna gerçek teslim iddiası değildir. Gerçek teslim, kontrollü canlı
bir form gönderimi ve hedef posta kutusu gözlemiyle ayrıca doğrulanır.

5 Eylül 2026'da bu ikinci kanıt da tek iletiyle alındı: üretim form yolu
`success` döndü, ileti marka posta kutusunda bulundu ve teslim edilmiş başlığın
RFC adres ayrıştırmasında `Reply-To` ziyaretçi adresiyle eşleşti. Kontrol posta
kutusunu salt okunur açtı; ikinci ileti gönderilmedi. Kimlik bilgisi, ileti
içeriği veya açık adres bakım kaydına yazılmadı.

Aynı gün müşteri posta kutusuyla sipariş zinciri de uçtan uca gözlendi. Kontrollü
bir sipariş `processing` durumuna geçirildi; müşteri kutusunda yalnız beklenen
“siparişiniz alındı” iletisi görüldü. Aynı siparişe WooCommerce'in manuel
fulfillment yolu üzerinden Aras Kargo ve bir test takip numarası girilip müşteri
bildirimi açıkça istendi; kutuda yalnız beklenen “siparişiniz kargoya verildi”
iletisi görüldü. İkinci ileti taşıyıcı adını, takip numarasını, takip düğmesini,
ürün adını, varyasyonu, adedi ve fiyatı doğru taşıdı. Sipariş `processing`
kaldı, stok değişmedi ve hiçbir taşıyıcı API çağrısı yapılmadı.

Bu kabul yerel alan adında yapıldığı için logo ve ürün görseli kasıtlı olarak
tipografik geri dönüşe düştü; bağlantılar da yerel siteyi gösterdi. Bu, kırık
`localhost` görselinin müşteriye taşınmadığını kanıtlar; üretim HTTPS alanında
görselin gerçekten indirilebildiğini kanıtlamaz. O son kabul canlı alan adı
yayına alındığında ayrıca yapılmalıdır.

Tarayıcı ölçümü (`scripts/verify-email-design.php <mod>` çıktısı bir dosyaya
render edilip açılır): masaüstü `#wrapper` genişliği **780 px**, yatay taşma
**0**; 390 piksel genişlikte `#wrapper` **390 px**, yatay taşma **0**, viewport
dışına çıkan eleman **0**.

## Bu alanda yapılmaması gerekenler

- Vendor e-posta şablonlarının tamamını child theme'e kopyalamak.
- WooCommerce ya da başka bir vendor dosyasını doğrudan değiştirmek.
- E-posta stilini `assets/css` altındaki storefront dosyalarına bağlamak.
- Görsel kapısını gevşetmek. Kapı gevşerse müşteriye kırık resim gider.
- Yönetici e-postalarına müşteri etiketi ya da müşteri bağlantısı eklemek.
- SMTP kullanıcı adı veya parolasını panelde ya da veritabanında saklamak.
