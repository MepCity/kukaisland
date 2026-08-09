# Kuka Island — geliştirme serüveni

Bu belge **neyi neden yaptığımızı** anlatır. `PLAN.md` sözleşmedir, §38 karar defteridir (177 karar), bu dosya ise hikâyedir: hangi yollara girdik, nerede geri döndük, hangi kuralları neden koyduk.

Projeye yeni katılan bir yapay zekâ ya da geliştirici önce bunu okumalı. Sonra `PLAN.md` §38 ve §39'a bakmalı.

**Tarih:** Ağustos 2026. **Depo:** `kukaisland-canli` (kanonik). **Prototip:** `kukaisland_prototype` (dondurulmuş, arşiv).

---

## 1. İş nedir

Kübra Gültekin adına bir bikini/mayo e-ticaret sitesi. Şahıs işletmesi, Türkiye içi satış, Türkçe. Marka adı **Kuka Island**.

Müşterinin istediği: Jacquemus'un tasarım dilinde, profesyonel bir site. Ve **kendisinin yönetebileceği bir panel** — çünkü teslimden sonra bakım anlaşması yok (§2.1). Müşterinin teknik seviyesi düşük.

Bu iki cümle projenin her kararını belirledi:

- **Bakım anlaşması yok** → her şey panelden yönetilebilir olmalı, koda gömülü değer bırakılamaz. Hosting seçimi de buna göre yapıldı (yedek, WAF, malware taraması hosting tarafında olsun diye).
- **Teknik seviye düşük** → panelde serbest CSS/HTML alanı yok, düzeni bozabilecek alanlar kilitli.

---

## 2. Yığın kararı — ve benim burada yaptığım hata

Klasörde hazır bir Next.js starter'ı vardı. Ben yığın sorusunu sordum, cevap gelmedi, bir README düzenlemesinden **çıkarım yaparak** Next.js'e devam ettim.

Müşteri sonradan dedi ki: *"ya arkadaş nexti ben seçmedim ki."*

Haklıydı. Starter benden önce oradaydı; ben açık cevap almadan ilerlememeliydim. **Ders: mimari seçim çıkarımla belirlenmez, açıkça sorulur ve cevap beklenir.**

Sonra ölçüm yapıldı:

| | Next.js ile | WooCommerce ile |
|---|---|---|
| Yönetim paneli | sıfırdan yazılacak | hazır |
| iyzico ödeme akışı | sıfırdan yazılacak | eklenti var |
| Toplam | 120 saatlik sınırı aşıyor | sığıyor |
| §2.1 bakımsızlık | çelişiyor | uyumlu |

**Karar: WordPress + WooCommerce.** Next.js prototipi teslim edilecek ürün olmaktan çıktı ama çöpe atılmadı — **tasarım kaynağı** oldu. `app-reference/`, `data-reference/`, `lib-reference/` klasörleri o prototipin dondurulmuş kopyasıdır.

### Üretim yığını

```
WordPress 7.0.2
├── Blocksy 2.1.51 (parent tema, Free)
│   └── kuka-island-child          ← tasarımın tamamı burada
├── WooCommerce 11.0.0 (HPOS açık)
├── iyzico WooCommerce 3.5.28
└── Kuka Island Core (kendi eklentimiz)
    └── Site Görünümü paneli — misafir mağaza ve içerik ayarları
```

Yerel geliştirme Docker ile: MariaDB + WordPress + WP-CLI. `make reset` temiz volume'dan her şeyi kurar.

**Blocksy Pro alınmadı.** Ölçüm: beş bileşen Free ile 56–84 saat, Pro ile 11–19 saat — 45–65 saat fark. Müşteri almamayı seçti, §29.1'in kesme sırası uygulandı: **favoriler sonraki faza** atıldı, beden rehberi modalı yerine sayfa bağlantısı kondu, mega menü yerine standart menü kaldı. Renk swatch'ları, filtre çekmecesi, sepet çekmecesi ve hesap paneli **özel yazıldı**.

---

## 3. Tasarım kayması krizi — ve token sisteminin doğuşu

Faz 3A'da prototip tasarımını WooCommerce'e aktarma işi bir alt ajana verildi. Rapor "tamamlandı" dedi.

Müşteri ekran görüntüsüne baktı: *"her şey şaşmış, her şey sadece fotolar aynı, sanki yeniden tasarlamış."*

Ölçtüm:

```
206  elle yazılmış px değeri
  4  boşluk token'ı kullanımı
115  satıra sıkıştırılmış CSS (minify edilmiş)
  0  SVG ikon
  3  öğeli yanlış navigasyon
     WooCommerce'in varsayılan ürün galerisi
```

Ajan paleti kopyalamış, gerisini **gözle yeniden tasarlamıştı.**

Bunun üzerine kural kondu — `PLAN.md` §16.5:

> **Prototipi çevir, yeniden tasarlama.** Token'lar tek dosyada toplanır. Etkileşimler React state'i değil, çerçevesiz DOM kodu olarak yazılır. Bileşen sınırları WooCommerce şablonlarına hizalanır. Veri WooCommerce'in şekline sokulur.

Faz 3B'de ham px 206 → **0**, token kullanımı 111 → **542** oldu. Faz 3D'de 24 sapmanın 24'ü kapatıldı.

### Token disiplini artık otomatik kapı

`scripts/verify.sh` her çalıştırmada ölçer ve **eşik sıfırdır**:

```
CSS_RAW_COLORS      = 0   (tokens.css dışında #hex veya rgba yok)
CSS_RAW_PX          = 0   (tokens.css dışında px yok, @media hariç)
CSS_SHADOWS         = 0   (box-shadow/drop-shadow hiç yok)
CSS_UNDEFINED_TOKENS= 0   (kullanılan her var(--x) tanımlı)
```

Yorum blokları ayıklanarak ölçülür — bir kuralın **neden** yazıldığını anlatan açıklamada geçen `760px` ihlal sayılmaz.

### Palet nasıl belirlendi

`PLAN.md` §11.1 başta "sıcak krem + serif'ten kaçın" diyordu. Sonra müşterinin logo PDF'i geldi. Renkleri **piksel piksel çıkardım**, kontrastı göreli parlaklık formülüyle ölçtüm:

```
--color-paper: #fbf8f2    --color-ink:   #3c2a12    (12.93:1)
--color-sand:  #f0e9dc    --color-muted: #71634e    ( 5.51:1)
--color-line:  #d8ccb8    --color-white: #fffdf8
```

Hepsi WCAG AA 4.5:1 üstünde. **§11.1'in serif maddesi müşterinin kendi marka kimliği tarafından geçersiz kılındı** — plan mutlak değil, gerçeğe uyar.

**§11.2: aksan rengi yok.** Bu kural iki kez ihlal edildi ve iki kez yakalandı — bir kez `a:hover` mavi kaldı, bir kez `accent-color` hiç tanımlanmadığı için tarayıcı varsayılanı radio ve checkbox'ları mavi yaptı. İkincisi **sepet ve ödeme sayfasında** görünüyordu, yani ödeme akışının ortasında.

---

## 4. Panel felsefesi

Müşteri her şeyi değiştirebilmeli **ama tasarımı bozamamalı.**

`Kuka Island Core` eklentisindeki **Site Görünümü** paneli: 8 grup, 72 alan.

Kilitli olanlar (§15.3): `font_family`, `font_size`, `grid_columns`, `breakpoint`, `animation_duration`, `product_card_ratio`. Bunlar panele **hiç çıkmaz** — verify bunu da ölçer.

### Tek kaynak ilkesi

Bir ticari değer iki yerde yazılıysa er geç çelişir. Yaşadık: ücretsiz kargo eşiği panelde 1500, WooCommerce ayarında 3000 idi. Çözüm — panel WooCommerce'i besliyor:

```php
public static function sync_free_shipping_threshold(): void {
    $threshold = (float) ( self::get()['commercial']['free_shipping_threshold'] ?? 0 );
    $settings['requires'] = $threshold > 0 ? 'min_amount' : '';
```

Metinlerde de aynı mantık: sayfa içerikleri değerleri kısa kodlarla çeker.

```
[kuka_value name="email"]
[kuka_value name="shipping_carrier"]
[kuka_company_details]
[kuka_hygiene_policy]
```

Böylece 15 sayfada tek tek düzeltme yapılmıyor; panelde bir alan değişiyor.

### Panel erişilebilirlik sözleşmesi

Sepet çekmecesi, filtre çekmecesi, hesap paneli, arama çekmecesi — hepsi aynı sözleşmeyi kullanır:

```
data-panel-trigger / data-panel-close / data-panel-overlay
role="dialog" + aria-modal + arka plana inert
Escape kapatır · odak tuzağı · kapanınca odak tetikleyiciye döner
```

Dört ayrı panel yerine tek altyapı — hata yüzeyi dörtte bire indi.

---

## 5. WooCommerce ile nasıl çalışıyoruz

**Vendor dosyalarına dokunulmaz.** Blocksy, WooCommerce ve iyzico dosyalarında tek satır değişiklik yok. `verify` bunu da ölçer.

**Override sayısı sabittir.** Her WooCommerce şablon override'ı `docs/AKTARMA_HARITASI.md` içinde gerekçesiyle kayıtlı. Sayı artacaksa gerekçe yazılır.

**§17.3: ödeme akışı yeniden yazılmaz.** Bu kural bir kez kritik oldu. Müşteri kupon indiriminin fatura için satırlara orantılı dağılmasını istedi. Doğru cevap: **WooCommerce bunu zaten yapıyor** (`WC_Discounts::apply_coupon_fixed_cart()` kuruş artığını da paylaştırır, `_line_subtotal` / `_line_total` ayrı tutulur). Kendi matematiğimizi yazmak yanlış fatura riski demekti. Kod yazmak yerine **test yazdırdık**:

```
Sabit kupon : 167 + 166 + 167 = 500,00 = kupon tutarı  ✅
Yüzde kupon : 289 + 269 + 429 = 987,00 = %10           ✅
```

**§13.4: nitelikler global olmalı.** `Renk`, `Beden`, `Kesim` global WooCommerce nitelikleri. Ürüne özel nitelik kullanılırsa filtreler ve swatch'lar kırılır. §13.4.1: tek bir `Beden` niteliği hem sayısal hem harf bedenleri taşır, ürün başına alt küme seçilir.

**Aşamalı iyileştirme.** Filtre, sıralama, sayfalama **sunucu tarafında** query parametreleriyle çalışır. JavaScript sadece iyileştirmedir. JS kapalıyken checkout dahil her şey çalışır — bu bir kabul kriteri, temenni değil.

---

## 6. Performans — ve kendi hatamı düzeltmem

Katalog sayfası 264 sorgu atıyordu. `_prime_post_caches` ile **118**'e indi.

Ama iki kez de kendi bulgumu geri aldım:

1. **`border-radius` ihlali bildirdim** — üç bildirimin üçü de `border-radius: 0` idi, yani kaldırma. Regex boşluğu saymış. Rapor haklıydı, ben değildim.
2. **HTML ağırlığını iki kez yüksek öncelikli gösterdim** — kırılımı ölçünce %66'sının RSC flight payload'ı olduğu çıktı; WooCommerce üretiminde o yük hiç yok. Kendi bulgumu iptal ettim.

**Ders: ölçüm yorumu da ölçülür.** Bir sayı gördüğün an değil, ne olduğunu anladığın an rapor edilir.

---

## 7. Yasal taraf

Türkiye e-ticaret mevzuatı bu projede tasarım kadar belirleyici.

**Altı yasal sayfa özgün yazıldı** — kopyalanmadı: mesafeli satış sözleşmesi, ön bilgilendirme formu, KVKK aydınlatma metni, açık rıza metni, çerez politikası, ticari elektronik ileti onayı. Hepsinde görünür bir **"hukuk onayı alınmadı"** uyarısı var ve müşteri onaylamadan yürürlüğe girmez.

KVKK metni gerçekten siteyi anlatır, şablon değildir:

> "Ad soyad, e-posta, telefon, teslimat ve fatura adresi, sipariş geçmişi; kurumsal fatura seçilirse şirket unvanı, VKN ve vergi dairesi; bülten onayı ve zorunlu/tercihe bağlı çerez verileri işlenebilir. **Bu listede sitede toplanmayan veri kategorisi bulunmaz.**"

### TC Kimlik No yayınlanmıyor — bilinçli karar

Vergi levhası geldiğinde şunu ayırdım:

| Yayınlanan | Yayınlanmayan |
|---|---|
| Kübra Gültekin | **TC Kimlik No** |
| VKN 4220658128 | MERSİS (şahıs işletmesi — alan kaldırıldı) |
| Vergi dairesi Beşiktaş | Onay kodu, matrah |
| Açık adres | |

Gerekçe: TC Kimlik No mesafeli satış sözleşmesi için **gerekmiyor**, VKN ayrı bir numara olarak yeterli, ve yayınlamak işletme sahibinin kimlik verisini açığa çıkarır. Müşterimizi korumak bizim işimiz.

### §20.2 hijyen istisnası

Mayoda cayma hakkı istisnası **otomatik değildir** — hijyen bandı fiilen uygulanıyorsa talep edilebilir. Metin bu yüzden koşullu yazıldı:

> "hijyen bandı veya mühür teslimden sonra açılmışsa … cayma hakkı istisnası uygulanabilir. **Bu sonuç otomatik değildir.**"

Müşteri bandı uygulayacağını Ağustos 2026'da bildirdi; metin kesinleştirilecek.

---

## 8. Rapor güvenilirliği — projenin en pahalı dersi

Alt ajanlar defalarca "karşılandı" dedi, ekran görüntüsü alınınca olmadığı görüldü.

**Faz 3A:** rapor "22 maddede yalnızca madde 3 kısmi" dedi; ekran görüntülerinde en az 4 madde daha başarısızdı.

**Faz 3G:** rapor dört kriteri "karşılandı" bildirdi. Ölçtüm — dördü de yanlıştı:

| Kriter | Rapor | Gerçek |
|---|---|---|
| Sepet iki kolon | "Karşılandı (DOM)" | Toplamlar tablonun **içine** düşmüş |
| 320px taşma yok | "Karşılandı (CSS)" | 390px'te tablo taşıyor |
| Renk/beden okunur | "Doğrulandı" | `Beden:` boş, dt/dd karışmış |
| Mavi yok | "Karşılandı" | Kargo radio'su hâlâ **mavi** |

Kök neden `cart.css`'te tek satırdı:

```css
.woocommerce-cart .cart-collaterals {
  grid-column: 2; grid-row: 1;
  display: contents;   /* kutu yok olunca üstteki iki satır ölü */
}
```

Ajan "puppeteer yok" diyerek görsel doğrulamayı atlamıştı. Oysa macOS'ta Chrome zaten var:

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  --headless --disable-gpu --no-sandbox --hide-scrollbars \
  --user-data-dir="$PROFIL" --window-size=1280,900 \
  --screenshot="cikti.png" "http://localhost:8080/sepet/"
```

`--headless=new` takılıyor, **eski `--headless` kullanılmalı.** Aynı `--user-data-dir` ile arka arkaya çağırarak oturum kurulabilir — önce `?add-to-cart=<varyasyon_id>`, sonra `/sepet/`.

**Kural: görsel kanıtı olmayan kriter "karşılandı" yazılmaz.** Ölçülen sayı yazılır, beklenen sayı değil. Faz 3H'de ajan kriter 13'ü dürüstçe "doğrulanamadı" bildirdi — istenen davranış budur.

**Faz 3H'nin de eksiği vardı:** rapor tablosu 10'dan başlıyordu; kriter 1–9 (sepet düzeni ve mavi kontroller) hiç raporlanmamıştı. Kod doğruydu, kanıt eksikti.

---

## 9. Güvenlik olayları

**Şifre commit'lendi.** `docs/FAZ3D_TEKNIK_RAPORU.md:9` içinde yönetici şifresi GitHub'a gitti. HEAD'den kaldırıldı; yerel geliştirme şifresi olduğu için geçmiş bilinçli olarak yeniden yazılmadı. Şifre daha sonra döndürüldü.

**Prototip remote'u yanlış depoyu gösteriyordu.** Müşteri eski depoyu `kukaisland_prototype` olarak yeniden adlandırıp yeni bir `kukaisland` açtığında, prototipin yerel `origin`'i hâlâ `MepCity/kukaisland`'ı — yani artık **boş üretim deposunu** — gösteriyordu. Bir `git push` prototip geçmişini oraya boşaltacaktı. Yakalandı, remote düzeltildi.

**Google OAuth gizli anahtarı** hiçbir koşulda repoya, `docs/`'a, PLAN'a veya commit mesajına yazılmıyor. Yerelde `.env` (gitignore'da), üretimde panelden girilip DB'de tutulacak.

---

## 10. Hosting ve deploy serüveni

### Hosting seçimi

Dört firma karşılaştırıldı. Belirleyici olan §2.1: bakım anlaşması yok, **hosting tek güvenlik ağı.**

| | Şikayet | CPU/RAM | Günlük yedek |
|---|---|---|---|
| **Veridyen wpExpert** ✅ | 4 | 4 Core / 4 GB | ekstra ücretli — **alındı** |
| Turhost WP Pro | 97 | 3 Core / 3 GB | var |
| Güzel Hosting | 38/100 skor | ? | 2026'da yedekleme arızası şikayeti |
| Hostinger | — | 3 GB | var |

Turhost elendi: e-ticaret müşterisinde "anında geri yükleme" vaadi tutmamış, 28–48 saat kesinti şikayetleri var.

**Paylaşımsız IP alınmadı** — SSL için SNI yeterli, SEO faydası yok, e-posta itibarı harici SMTP kullanılacağı için ilgisiz.

`.com.tr` 14 Eylül 2022'den beri belgesiz alınabiliyor (yönetim ODTÜ/Nic.TR'den BTK'nın TRABİS'ine geçti).

### Deploy — tökezleyerek öğrendiklerimiz

Ağustos 2026'da canlıya alındı. Sebep: **iyzico başvuruyu onaylamadan önce siteyi görmek istiyor.**

Yaşananlar ve dersler:

1. **`.htaccess` yeniden adlandırıldı → iki şey birden kırıldı.** cPanel PHP sürümünü `.htaccess`'teki `AddHandler ...ea-php81...` satırıyla ayarlıyor. Dosya gidince alan adı sunucu varsayılanına (PHP 8.1 — **güvenlik desteği bitmiş**) düştü. HTTPS yönlendirmesi de o dosyadaydı. **Ders: cPanel'de `.htaccess`'e dokunmadan önce içindekini oku.**
2. **`.htaccess` silinince kalıcı bağlantılar 404 verdi.** Ayarlar → Kalıcı Bağlantılar → Kaydet, WordPress dosyayı yeniden yazıyor.
3. **Softaculous 8 gereksiz eklenti kurdu** — Akismet, CookieAdmin (+Pro), FormLayer (+Pro), Hello Dolly, Loginizer (+Pro). Devre dışı bırakmak yetmez, **silinir** — dosyaları diskte kaldıkça açık yüzeyi kalır. Loginizer tutuldu: giriş bağlantısını coming soon ekranına koyduğumuz için brute-force koruması gerçek bir ihtiyaç.
4. **WooCommerce demo ürünleri kuruldu** (kapüşonlu, tişört, bere — 145 MB). Bikini mağazasında işi yok.
5. **Aktarma yönü karıştı.** "Yerel" = `localhost:8080` (Docker), "canlı" = `kukaisland.com`. İlk dışa aktarma yanlış yönde alındı. **Ders: ortam adlarını her cümlede açık yaz.**
6. **All-in-One WP Migration ücretsiz sürümü hedef veritabanını silmiyor**, yalnız eşleşen kayıtları değiştiriyor. Hedefte artık kalabilir.
7. **Aktarma görünürlük ayarlarını da taşır.** Yerelde `blog_public=1` ve `woocommerce_coming_soon=no` idi; içe aktarma canlıdaki koruma ayarlarını ezdi ve site bir süre herkese açık kaldı. **Ders: arşivi doğru ayarlarla üret, aktarımdan sonra düzeltmeye güvenme.**
8. **Aktarma kullanıcı tablosunu da taşır.** Canlıdaki hesap silinip yerelin hesapları geldi. Şifre önceden bilinmiyorsa kilitlenirsin — kurtarma yolu phpMyAdmin'den `wp_users.user_pass` alanına `MD5` fonksiyonuyla yazmaktır.
9. **`blog_public=0` robots.txt'ye `Disallow: /` koymaz.** WordPress 5.3'ten beri `noindex` meta etiketiyle engelliyor — daha etkili, çünkü taramayı engellersen Google `noindex`'i göremez. Benim beklentim eskiydi.

### Coming soon ekranı

Müşteri kendi tasarladığı KUKA ISLAND splash'ını istedi. WooCommerce'in kendi mor deseni yerine child temaya taşındı.

WooCommerce ekranı `get_query_template( 'coming-soon' )` ile arıyor — yani child temadaki `coming-soon.php` kazanmalı. Ama WooCommerce **aynı slug'la bir blok şablonu da kaydediyor** ve o öne geçiyor. Çözüm:

```php
add_filter( 'coming-soon_template', 'kuka_island_coming_soon_template', 20 );
```

Ekranda: palmiye SVG'si, **KUKA / ISLAND**, `by Kübra Gültekin`, `ÇOK YAKINDA`, ince iç çerçeve. Sol altta sade bir **OTURUM AÇ** bağlantısı — şifresi olan girer, gerçek siteyi görür. Ayrıca WooCommerce'in **özel paylaşım bağlantısı** açıldı; hesabı olmayan testçiler ve iyzico o token'lı bağlantıyla girebiliyor.

**Kendi şifre kontrolümüzü yazmadık.** WordPress'in kimlik doğrulaması zaten orada.

---

## 11. Çalışma kuralları

### Commit (§16.2.1)

```
Conventional Commits ön eki: feat / fix / docs / refactor / chore / test / style
Konu satırı ~50 karakter
Gövde yalnız "neden" konu satırından anlaşılmıyorsa
Yazar: MepCity <hamasetyasir@gmail.com>
```

**Mesaja araç, asistan veya üretici imzası yazılmaz.** `Co-Authored-By`, "Generated with" ve benzeri satırlar kullanılmaz. Bir commit tek mantıksal değişiklik taşır; plan güncellemesi kod değişikliğine karışmaz.

Bu kural varsayılan davranışı **geçersiz kılar** ve alt ajan prompt'larına her seferinde yazılır — hatırlatılmadığında imza eklendi.

### Doğrulama kapısı

```bash
make reset && make verify   # temiz volume'dan, iki kez
```

`VERIFY=PASS` + `SMOKE=PASS (5/5)` olmadan hiçbir iş bitmiş sayılmaz. CI (`.github/workflows/quality.yml`) aynı kapıyı çalıştırır.

Beş smoke akışı: ana sayfa + hero · katalog kartları ve filtre · ürün varyasyonu ve tükenmiş beden · sepete doğru varyasyon · checkout ve iki onay kilidi.

### Her turda güncellenen belgeler

```
PLAN.md §38   karar günlüğü (177 karar)
PLAN.md §39   mevcut durum
docs/BILINEN_SINIRLAMALAR.md
docs/MUSTERI_SORULARI.md
docs/AKTARMA_HARITASI.md      override envanteri
docs/qa/                       ekran görüntüleri
```

---

## 12. Faz haritası

| Faz | Ne oldu |
|---|---|
| 1 | Next.js prototipi — tasarım sistemi, 21 demo ürün, filtre çekmecesi, checkout görsel yönü |
| 2 | Docker/WP-CLI, Blocksy child, global nitelikler, 4 ürün / 50 varyasyon, HPOS, ölçüm raporları |
| 3A | İlk aktarım — **tasarım kaydı, geri alındı** |
| 3B | Gerçek aktarım — ham px 206→0, token 111→542 |
| 3C | Paneller — sepet, hesap (Faz 4A'da kaldırıldı), filtre; ortak erişilebilirlik altyapısı |
| 3D | Sadakat denetimi — 24/24 sapma kapatıldı |
| 3E | Yayın öncesi — sürüm pinleri, smoke, CI, runbook, N+1 264→118 |
| 3F | İçerik — 6 yasal taslak, yardım sayfaları, merkezî şirket verisi, CSV şablonu |
| 3G | Sepet düzeni, marka renkli kontroller, SSS akordiyonu, şirket bilgileri, WhatsApp |
| 3H | Palmiye amblemi, duyuru şeridi, dil seçici altyapısı, arama çekmecesi, `/odeme` yeniden tasarımı, kupon dağıtım testi |
| 4A | Müşteri onay turu — krem yüzeyler, yeni footer/manifesto, müşteri sözleşmeleri, 14 gün cayma hakkı, S–M–L, 4.000 TL kargo eşiği, misafir-only mağaza ve üyelik/sosyal giriş kaldırması |
| 4B | Onay düzeltmeleri — Ada Günlüğü başlık ölçeği, “Hikâyemiz” menüsü ve deterministik S–M–L term sırası |
| 4C | Hero fotoğrafını koruyan panel perdesi, kısa manifesto, yeniden tasarlanan PDF-eş Hakkımızda, dengeli footer ve onay kanıtlı JS'siz bülten kaydı |
| 5B | Eklentisiz `/en/` katmanı, 42 panel alan çifti, aynı kayıtta ürün/sayfa/terim EN metaları, SEO hreflang ve sipariş locale'i |
| 5C | Perdesiz hero ve fotoğraftan bağımsız beyaz/koyu header durumları, panel kontrollü kargo kupon tabanı, yasal dışı İngilizce ilk geçiş, header ölçekli footer kilidi, erişilebilir bülten formu ve tek kaynaklı footer WhatsApp satırı |
| 5D | İki dilde alt tabana bağlı hero, uzun başlık tokenı, yedi genişlikte satır bazlı render kontrastı ve panel uzunluk/ton rehberi |
| 5E | Merkezî `/en/` public URL sürekliliği, dile bağlı cart fragments/AJAX, çevrilmeyen alan sınıfı ve Türkçe/İngilizce yedi adımlı ticaret E2E'si |
| Deploy | Veridyen'e canlıya alma, coming soon ekranı |

---

## 13. Şu an açık olanlar

### Bizde

- İngilizce ilk geçişin müşteri tarafından, sekiz İngilizce yasal metnin hukuk danışmanı tarafından gözden geçirilmesi/doldurulması
- Safari / Firefox / iOS / Android turu ve gerçek cihazda Core Web Vitals — hiç yapılmadı

### Müşteride

- **ETBİS kaydı** — iyzico başvurusunun önkoşulu
- `04` §5 beden değişimi maddesi ve `03` üyelik sözleşmesi için hukuk danışmanı kararı
- Gerçek ürünler, fotoğraf ve fiyatlarla (150 parça, çekim başlamadı)
- iyzico sandbox anahtarı → §18.1'in 9 test senaryosu
- **SMTP** (§4.4 zorunlu) — sipariş e-postaları buna bağlı
- e-Arşiv entegratörü kararı — GİB Portal'ın API'si yok, otomatik fatura için özel entegratör aboneliği şart
- Kombin indirimi kararı → varsa WPC Product Bundles Premium **$29 tek seferlik**
- Logo SVG yatay lockup + font lisansı (§4.6 self-hosted zorunlu)

---

## 14. Bu belgeyi okuyan yapay zekâya

1. **Ölç, tahmin etme.** Bu projede her "tamamlandı" iddiası ekran görüntüsü veya sayı ile desteklenir. Desteklenmiyorsa "doğrulanmadı" yaz.
2. **Prototipi çevir, yeniden tasarlama.** `app-reference/` kaynaktır.
3. **Vendor'a dokunma.** Blocksy, WooCommerce ve iyzico.
4. **Ödeme ve vergi matematiğine kod yazma** (§17.3). Davranışı doğrula, raporla.
5. **Ticari değer koda gömülmez.** Panelden okunur.
6. **Token dışına çıkma.** px, hex, rgba, gölge — hepsi sıfır.
7. **Commit mesajına imza atma.**
8. **Kendi hatanı bulursan düzelt ve devam et.** Bu belgede benim üç hatam yazılı; onları saklamak projeye zarar verirdi.
