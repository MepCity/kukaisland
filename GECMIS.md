# Kuka Island — geliştirme serüveni

Bu belge **neyi neden yaptığımızı** anlatır. `PLAN.md` sözleşmedir, §38 karar
defteridir; bu dosya ise hikâyedir: hangi yollara girdik, nerede geri döndük,
hangi kuralları neden koyduk.

Projeye yeni katılan bir yapay zekâ ya da geliştirici önce bunu okumalı. Sonra
`PLAN.md` §38 ve §39'a bakmalı. İş EDM ile ilgiliyse bu sıranın önüne
`docs/EDM_BAKIM_HAFIZASI.md` ve `docs/EDM_AKTIVASYON_REHBERI.md` gelir.

**Tarih:** Ağustos–Eylül 2026. **Depo:** `kukaisland-canli` (kanonik). **Prototip:** `kukaisland_prototype` (dondurulmuş, arşiv).

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
WordPress 7.1
├── Blocksy 2.1.53 (parent tema, Free)
│   └── kuka-island-child          ← tasarımın tamamı burada
├── WooCommerce 11.0.1 (HPOS açık)
├── iyzico WooCommerce 3.5.28
├── Kuka Island Core (kendi eklentimiz)
│   └── Site Görünümü + manuel WooCommerce fulfillment
└── Kuka Island EDM (ayrı, varsayılan pasif eklenti)
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

## 8. Faz 7 — paneli müşterinin zihnine göre kurmak

Site Görünümü büyürken panel 13 grup ve 146 saklanan kontrole ulaştı. Teknik olarak her alan vardı ama operatör 105 satırlık tek bir kaydırma duvarında doğru alanı bulmak zorundaydı. Ürün ve sayfa İngilizceleri ayrı, İngilizce başlıklı kutulardaydı; bülten kayıtları da Kuka Island yerine WooCommerce altında kalmıştı.

Faz 7'de ölçüt “alan var mı?” değil, **“mağaza sahibi nereye gideceğini biliyor mu?”** oldu:

- Site Görünümü 13 numaralı sekmeye ayrıldı; URL aktif sekmeyi, kayıt aynı sekmeyi koruyor. Tüm sekmeleri tarayan alan araması ve yapışkan kaydet çubuğu eklendi.
- Ürün, sayfa ve taksonomide Türkçe kaynak ile `(EN)` aynı ekrana alındı; eski ayrı English kutuları kaldırıldı.
- Başlangıç ekranı mağaza/noindex durumu, ürün özeti, görev kartları ve eyleme bağlı 12 tutarlılık denetimi gösteriyor.
- Yönetim Haritası “ne yapmak istiyorum?” sorusundan doğru WordPress/WooCommerce ekranına doğrudan gidiyor.
- Ürün yayın kontrol listesi eksikleri sayıyor ama yayını engellemiyor; bakım anlaşmasız işletmede görünür yardım kapı bekçiliğinden daha güvenli.
- Bülten Kayıtları palmiye ikonlu Kuka Island menüsüne taşındı.

Ek müşteri geri bildiriminde hero başlığı Türkçe ve İngilizcede cümle + `Est. 2026` olarak iki görsel satıra ayrıldı; geniş ekranda cümle bölünmüyor. Aynı turda yerel seed'in mağazayı yanlışlıkla canlı ve indekslenebilir yaptığı ölçüldü. Seed ve aktif yerel ayar **Çok yakında + noindex** durumuna alındı; smoke testi yalnız gizli WooCommerce önizleme anahtarıyla çalışıyor.

Alanların tamamı, yazma kaynakları, çakışmalar ve bilinçli kilitler `docs/PANEL_HARITASI.md` içinde kayıtlıdır.

---

## 9. Rapor güvenilirliği — projenin en pahalı dersi

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

## 10. Güvenlik olayları

**Şifre commit'lendi.** `docs/FAZ3D_TEKNIK_RAPORU.md:9` içinde yönetici şifresi GitHub'a gitti. HEAD'den kaldırıldı; yerel geliştirme şifresi olduğu için geçmiş bilinçli olarak yeniden yazılmadı. Şifre daha sonra döndürüldü.

**Prototip remote'u yanlış depoyu gösteriyordu.** Müşteri eski depoyu `kukaisland_prototype` olarak yeniden adlandırıp yeni bir `kukaisland` açtığında, prototipin yerel `origin`'i hâlâ `MepCity/kukaisland`'ı — yani artık **boş üretim deposunu** — gösteriyordu. Bir `git push` prototip geçmişini oraya boşaltacaktı. Yakalandı, remote düzeltildi.

**Google OAuth gizli anahtarı** hiçbir koşulda repoya, `docs/`'a, PLAN'a veya commit mesajına yazılmıyor. Yerelde `.env` (gitignore'da), üretimde panelden girilip DB'de tutulacak.

---

## 11. Hosting ve deploy serüveni

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

## 12. Çalışma kuralları

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
PLAN.md §38   karar günlüğü (178 karar)
PLAN.md §39   mevcut durum
docs/BILINEN_SINIRLAMALAR.md
docs/MUSTERI_SORULARI.md
docs/AKTARMA_HARITASI.md      override envanteri
docs/EDM_BAKIM_HAFIZASI.md    EDM sorun → kök neden → çözüm kayıtları
docs/EDM_AKTIVASYON_REHBERI.md
docs/EDM_ENTEGRASYONU.md      güncel teknik sözleşme
docs/qa/                       ekran görüntüleri
```

---

## 13. Faz haritası

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
| 6A | Altı sahneli, panel kontrollü, JS kapalı/mobil/reduced-motion güvenli marka hikâyesi |
| 7 | Görev odaklı yönetim haritası, durum/uyarı başlangıcı, 13 sekmeli arama, TR/EN yan yana düzenleme, ürün kontrol listesi ve korunan yakında/noindex |
| 8 | Tema sahipliğinde iki dilli iyzico/footer ödeme şeridi, iletişimde tek kaynak şirket bilgileri, 12 otomatik + 5 manuel başvuru kontrolü ve ölçümlü QA |
| 9 | Sipariş e-postası hata görünürlüğü, SMTP hazırlığı, yeniden gönderme yüzeyleri ve kapalı `mail()` davranış testi |
| 10 | Checkout doğrulama özetleri, alan içi hata/odak davranışı ve iki dilli tarayıcı ölçümü |
| 11 | Footer ödeme logolarını kaldırma, içerik başlık ölçeği ve site e-postasını tek kaynağa bağlama |
| Deploy | Veridyen'e canlıya alma, coming soon ekranı |
| EDM | EDM SOAP/UBL entegrasyonu, güvenli sandbox taslak ve gönderim deneyleri, mükerrerlik/poller/kurtarma korumaları; sonra ayrı ve varsayılan pasif eklentiye çıkarma |

---

## 14. EDM entegrasyonu — neden uzadı, ne öğrendik

Bu iş ilk bakışta “ödeme oldu, API'ye faturayı gönder” görünüyordu. Gerçekte
geri alınamaz bir mali belgeyi üreten SOAP sözleşmesi, UBL element sırası,
numaralandırma, belirsiz ağ sonucu, durum takibi ve kargo zamanı aynı anda doğru
olmak zorundaydı. Çalışmanın uzamasının nedeni tek bir hata değil, her biri
gerçek çağrıda farklı sonuç doğuran katmanların sırayla ortaya çıkmasıydı.

### Portal, servis ve doküman birbirinden farklı şeyler

EDM'in test portalı insanın gelen/giden belge ve tanımları gördüğü arayüzdür;
WooCommerce oraya ekran otomasyonu yapmaz. Kod SOAP web servisine bağlanır.
E-postada verilen servis yolu eskiydi ve 404 dönüyordu; çalışan WSDL'den
ölçülen kanonik test yolu `EFaturaEDM21ea/EFaturaEDM.svc` oldu. Bu yüzden URL
bir “test” etiketine güvenerek değil, Login'den önce tam allow-list ile
doğrulanıyor.

EDM ayrıca her çağrıda sekiz alanlı `REQUEST_HEADER` ve
`APPLICATION_NAME=ozelyazilim.kukaisland` bekliyor. İlk istemci bu sözleşmeyi
eksik kurduğu için Login'in başarılı olması fatura isteğinin doğru olduğu
anlamına gelmiyordu. Üretim ve sandbox araçlarının farklı başlık üretmesi
engellendi; ikisi tek yardımcıya bağlandı.

### Taslak yüklemek fatura kesmek değildir

`LoadInvoice` EDM portalına bir **taslak** kaydeder; alıcıya göndermez ve mali
belgeyi kesmez. `SendInvoice` ise geri alınamaz gönderimdir. Bu ayrım başta net
değildi; sonradan ayrı komutlar, ayrı state dosyaları ve birbirini dışlayan
host kapılarıyla yapısal hâle getirildi.

Tek kontrollü `LoadInvoice` deneyi başarılı oldu: EDM taslağı aldı ve 16
karakterlik numara atadı. Bu deney numaralandırma yolunu kanıtladı fakat aynı
UBL'in `SendInvoice` iş kurallarından geçeceğini kanıtlamadı.

### Mali numarayı biz üretmiyoruz

UBL `cbc:ID` alanı zorunlu olsa da sipariş numarasından fatura numarası türetmek
yanlıştı. EDM'in resmî sözleşmesi ve yazılı cevabı uyarınca UBL'de
`ABC2009123456789` sentinel'i kullanılıyor, SOAP `INVOICE/@ID` gönderilmiyor ve
gerçek numara yalnız EDM yanıtından kaydediliyor. Sentinel'in sipariş metasında
fatura numarası olarak kalmasını tek merkezî yazıcı reddediyor.

### Bireysel alıcı ve ilk gerçek ret

Nihai tüketiciden checkout'ta TCKN istemiyoruz. EDM'nin doğruladığı sözleşme:
`11111111111` + WooCommerce fatura alanlarındaki **gerçek ad/soyad**. E-posta
hem UBL `ElectronicMail` hem `HEADER.TO` alanına gidiyor; e-Arşivde
`RECEIVER.alias` yazılmıyor ve teslimi EDM yapıyor.

İlk gerçek `SendInvoice` yine de genel bir ret verdi. Sistem doğru biçimde
`uncertain` kaldı ve ikinci gönderimi kapattı. EDM request logu tutmadığını
bildirdiği için aynı kayıtlı girdilerden redakte destek paketi üretildi. Paket
özgün zarfla byte-identik diye sunulmadı; zaman alanlarının değişebileceği ve
WSDL uyumunun UBL/GİB iş kurallarının tamamını kanıtlamadığı açıkça yazıldı.

EDM desteği kök nedeni buldu: TCKN'li müşteride `cac:Person` vardı fakat UBL
`PartyType` dizisinde yanlış yerdeydi. `Person`, `Contact` sonrasına taşındı;
ikinci bir düğüm eklenmedi. Rapor tarihleri de WSDL'in zorunlu serileştirmesi ve
EDM'in resmî örnekleri nedeniyle `0001-01-01` oldu.

### İlk kabul edilen gerçek gönderim ve dürüst son durum

Düzeltilmiş tek `SendInvoice` EDM tarafından kabul edildi, UUID yanıtla eşleşti
ve 16 karakterlik numara atandı. Belge yaklaşık 24 saat `PACKAGE - PROCESSING`
durumunda bekledi; EDM desteğine soruldu, cevap yazılıydı: test ortamı kuyruğu
ara ara böyle bekletir, canlıda yaşanmaz, tetiklemeyi yazılım birimi yaptı.
Tetikleme sonrası salt-okunur ölçüm (4 Eylül 12:06): **`SEND - SUCCEED` —
terminal başarı gözlemlendi**, durum sözlüğü terminali `completed` olarak doğru
sınıfladı. Kalan tek açık uç: `GetInvoice` saklı XML'i terminal sonrası da boş
`CONTENT` ile döndürüyor (EDM'e sorulmuş, cevap bekleyen davranış sorusu).

Arka planda çalışan sandbox izleyicisi yoktur. Tekrar kontrol gerekirse
`./scripts/edm-sandbox-send-run.sh status=confirm` elle çalıştırılır; bu mod
yalnız Login/GetInvoiceStatus/GetInvoice/Logout'a izin verir ve state'i
salt-okunur bağlar.

Buradaki test raporları da aynı kanıt değildir. `make verify` yüzlerce yerel,
fixture ve gerçek WordPress davranış kontrolünü çalıştırır ama EDM'e gerçek
fatura göndermez. Salt-okunur probe gerçek EDM bağlantısını, iki kapılı
sandbox komutu gerçek belge yazmasını, terminal `SEND - SUCCEED` ise EDM/GİB
sonucunu kanıtlar. Bu üçü ileride tek “PASS” cümlesinde birleştirilmemelidir.

### Neden bu kadar çok mükerrerlik koruması var

Bir timeout “gönderilmedi” demek değildir. Bu nedenle kalıcı UUID, gönderim
durumu, `sent_at` veya mali attempt sayacından biri bile varsa send yolu yalnız
uzlaştırmaya döner; `force` bunu aşamaz. `SendInvoice` oturum yenileme retry'ı
kapalıdır. Durum sorgusu ayrı poller action'ına aittir ve send kuyruğuna geri
dönemez. Poll planlanamazsa hata görünür olur; sessizce başarı sayılmaz.

Ön-gönderim altyapı retry'ı ile mali gönderim attempt'i de ayrıldı. Queue
sayacı en fazla üç denemelik tek zincire aittir ve zincirin her çıkışında
temizlenir. `queued` statüsü worker için geçerli, operatör resend düğmesi için
geçersizdir. Başarısız gerçek bir belge yeniden kullanılmaz: operatör onaylı
kurtarma eski kimlikleri append-only arşivler, yeni UUID ve EDM'den yeni numara
ile yeni belge hazırlar.

### Kargo zamanı ve internet satış bilgisi

Fatura ödeme anında değil, fiziksel siparişin **tamamı kargoya verildiğinde**
hazırlanır. Kısmi fulfillment bekler. WooCommerce fulfillment tarihi UTC
saklandığı için katı UTC ayrıştırılıp mağaza timezone'una çevrilir; bozuk tarih
bugüne düşürülmez. Mesafeli satış taşıyıcısı tüzel kişiyse VKN tam 10 hanedir;
gerçek DHL/MNG mali kimliği yapılandırılmadan uydurma değerle fatura gönderilmez.

### Son mimari karar: ayrılabilir ve pasif modül

Müşteri belirli sipariş hacmine ulaşana kadar faturayı elle kesecek. Bu yüzden
EDM kodu Core'un ayrılmaz parçası olarak bırakılmadı; geçmişi korunarak
`kuka-island-edm` eklentisine taşındı. Bağımlılık yalnız EDM → Core yönünde.
Deploy paketi eklentiyi taşır fakat yeni kurulumda etkinleştirmez. Pasifken EDM
sınıfı, paneli, SOAP çağrısı, hook'u, action'ı ve sipariş metası yoktur;
WooCommerce'in manuel fatura ve kargo süreci aynen çalışır.

Bu bölüm bakım sözleşmesi değildir. Bir belirtiyi çözmek için önce
[EDM_BAKIM_HAFIZASI.md](docs/EDM_BAKIM_HAFIZASI.md), sonra
[EDM_AKTIVASYON_REHBERI.md](docs/EDM_AKTIVASYON_REHBERI.md) ve
[EDM_ENTEGRASYONU.md](docs/EDM_ENTEGRASYONU.md) okunmalıdır.

---

## 15. Şu an açık olanlar

### Bizde

- İngilizce ilk geçişin müşteri tarafından, sekiz İngilizce yasal metnin hukuk danışmanı tarafından gözden geçirilmesi/doldurulması
- Safari / Firefox / iOS / Android turu ve gerçek cihazda Core Web Vitals — hiç yapılmadı
- EDM sandbox belgesi 4 Eylül'de terminal `SEND - SUCCEED`'e ulaştı (test kuyruğu destek tetiklemesiyle işledi); açık kalan yalnız `GetInvoice` boş-CONTENT davranışı
- DHL eCommerce sandbox uygulaması oluşturuldu; Identity, CBS Info, Standard
  Command, Barcode Command ve Standard Query abonelikleri 4 Eylül 2026'da
  portalda tamamlandı. Token için gereken test müşteri numarası/şifresi portal
  destek formundan istendi; yanıt bekleniyor

### Müşteride

- **ETBİS kaydı** — iyzico başvurusunun önkoşulu
- `04` §5 beden değişimi maddesi ve `03` üyelik sözleşmesi için hukuk danışmanı kararı
- Gerçek ürünler, fotoğraf ve fiyatlarla (150 parça, çekim başlamadı)
- iyzico canlı üye işyeri bilgileri ve canlı ilk ödeme kontrolü; sandbox ödeme,
  callback/idempotency ve iade korumaları geliştirme ortamında doğrulandı
- **SMTP** (§4.4 zorunlu) — sipariş e-postaları buna bağlı
- EDM canlı başvuru/kimlik/seri kurulumu — müşteri hacme ulaşana kadar eklenti pasif, faturalar manuel
- Kombin indirimi kararı → varsa WPC Product Bundles Premium **$29 tek seferlik**
- Logo SVG yatay lockup + font lisansı (§4.6 self-hosted zorunlu)

---

## 15.1 Kargo otomasyonu — sekiz turda öğrenilenler

EDM gibi, kargo da **ayrı** bir eklenti. **Sekiz** bağımsız düzeltme turu geçti
ve her turda bir öncekinin *eksik* kaldığı yer bulundu. Bu sıralamanın kendisi
ders: her tur bir önceki turun "tamam" dediği yeri ölçtü.

**Tur 1 — akış boşlukları.** İptal doğrulaması yanlış nesneyi sorguluyordu
(`cancelorder` sonrası `getshipment`), `order_created` durumundan çıkış yolu
yoktu, başarısız durum sorguları deneme bütçesinden düşmüyordu (sonsuz zincir),
ve "taşıyıcıdan bağımsız" iddiası kaynakta doğru değildi. Bkz. K-14…K-17.

**Tur 2 — mutasyon sınırı.** Güncelleme ve iptal ortak güvenlik kapısından hiç
geçmiyordu, kilit yalnız oluşturma yolundaydı, ve `make verify` izin listesi
kararını gerçek runner'ı çalıştırıp `head -n 1` ile alıyordu — PHP'yi durduran
şey bir kural değil SIGPIPE zamanlamasıydı. Bkz. K-18…K-20.

**Tur 3 — sahiplik.** Sipariş, mağazanın **güncel** varsayılan taşıyıcısına
göre yönlendiriliyordu. İkinci kurye eklenip varsayılan değiştirildiğinde eski
DHL siparişlerinin iptali yeni kuryeye gidiyor, o kuryenin "kayıt yok" cevabı
**iptalin kanıtı** sayılıyordu. Bkz. K-21…K-23.

**Tur 4 — yazma kanıtı.** Taşıyıcıya *ulaşmış* bir iptal, doğrulama başarısız
olduğunda tekrar gönderilebiliyordu; belirsiz bir güncelleme nesnenin
varlığıyla başarılı sayılıyordu; provider geçerli bir istek olmadan
sabitleniyordu; test önbelleği sahipliği tahminle belirliyordu; ve deaktivasyon
bekleyen işleri gerçekte iptal etmiyordu. Bkz. K-24…K-28.

**Tur 5 — kalıcı niyet.** Dördüncü tur kapıyı **cevap geldikten sonra**
kapatıyordu, ve bu cevabın geldiği varsayımına dayanır. Süreç istek uçarken
ölürse (fatal, OOM, deploy, kopan bağlantı) hiçbir kod yolu geri dönmez: sipariş
`none` durumunda kalıyor, `states_blocking_create()` onu geçiriyor ve ikinci bir
`createOrder` gidiyordu — tek paket, iki kayıt. Ayrıca `guarded_write()`'ın
sabitleme callback'i `void` olduğu için kaydedilemeyen bir sabitleme
kaydedilenden ayırt edilemiyordu; "kesin ret hiçbir şeyi değiştirmemiştir"
varsayımı satıcının OpenAPI sözleşmesinde **yazılı değildi**; sonuç geçişleri
iki save'di; `KUKA_DHL_ADAPTER` yazım hatasında **açık** kalıyordu;
`fields_match()` iki tarafı da `trim()` ettiği için "birebir" iddiası yanlıştı;
ve bir kod yorumu, planlı durum sorgusunun doğrulanmamış iptali "izleyen tek
şey" olduğunu söylüyordu — oysa o iş tek bir okuma bile yapmadan bitiyordu.
Turun sonunda eklenti kullanıcının kararıyla **etkinleştirildi** ve bu bir
altıncı kusuru açığa çıkardı: yalnız pasif eklentide gözlemlenebilen üç ölçüm
FAIL raporlayıp, `set -e` yüzünden **bütün kargo doğrulamasını** kesiyordu.
Bkz. K-29…K-36.

**Tur 6 — izin listesi, sahipsiz kanıt, ve kanonik doğrulama.** Beşinci tur
kapıyı istek gitmeden önce kapatmıştı, fakat create kapısı hâlâ bir **yasak
listesi** soruyordu ve `cancelled` o listede yoktu: iptali kanıtlanmış bir
sipariş kapıyı geçiyor, `createOrder` dalı atlanıyor, ve `run_creation()`
koşulsuz olarak `run_barcode()` ile bitiyordu — taşıyıcının iptal ettiği kayda
`createbarcode`. `has_carrier_evidence()` de iki korumalı durumu ve intent
kaydını kanıt saymadığı için sahipsiz bir "iptal doğrulanıyor" kaydı mağazanın
güncel varsayılan taşıyıcısına düşüyordu. Ve kanonik doğrulamanın kendisi
çalışmıyordu: EDM pasifken gerçek `make verify` exit 2 veriyordu. Bkz.
K-37…K-39.

**Tur 7 — zaman, kilit ve boş zincir.** Dört kusur, hiçbiri "yanlış cevap"
değil, hepsi "hiç sorulmamış soru". Teslim tarihini modül değil WooCommerce'in
data store'u yazıyordu — değer doğruydu, fakat mali bir belgenin teslim tarihi
yazılı olmayan bir vendor yan etkisine bağlıydı. `reconcile_order()` kilit
almayan tek dış kapıydı ve uçuştaki bir yazmanın intent'ini kapatabiliyordu.
Taşıyıcıya hiç ulaşmayan bir ret — kimlik eksik, kapı kapalı — deneme
harcamadığı için `MAX_ATTEMPTS`'a hiç varmıyor ve zinciri yalnız `MAX_ELAPSED`
bitiriyordu: yaklaşık on dört günlük gereksiz scheduler işi, her turunda bir not
ve bir geçmiş kaydıyla. Ve mutabakatla benimsenen bir gönderinin `created_at`
alanı 0 kalıyordu, yani geçen süre 1970'ten beri sayılıyor ve ilk poll turu tek
okuma yapmadan vazgeçiyordu. Bkz. K-40…K-43.

**Tur 8 — müşteriye giden ileti.** Kargo kaydı `fulfilled` oluyor, müşteriye
hiçbir e-posta gitmiyordu: WooCommerce bildirim eylemini **tek bir yerden**,
çekmecedeki "müşteriye bildir" işaretinin arkasındaki REST controller'dan
tetikler, ve modül kaydı doğrudan kaydettiği için o eylem hiç oluşmuyordu.
Bildirim artık modülün kendisi tarafından, yalnız kendi kaydının ilk
`unfulfilled → fulfilled` geçişinde tetikleniyor; kalıcı ve çökme güvenli bir
durum (`pending → sending → sent | failed | reconciliation_required`) tekrar
sorgularda tek iletiyi garanti ediyor, belirsiz bir SMTP sonucu **hiçbir zaman**
otomatik ikinci ileti üretmiyor. Bu turda ayrıca metnin kendisi düzeltildi:
WooCommerce'in tr_TR çevirisi müşteriye `bir öğe yerine getirildi!` ve
`Öğeniz yolda!` yazıyordu. Ve dilin nasıl seçildiği bozuktu — `WC_Email` nesnesi
yeniden kullanıldığı, bu iki e-postada siparişin `setup_locale()`'dan sonra
atandığı için aynı istekteki ikinci bildirim dilini **önceki siparişten**
alıyordu. Bkz. K-44…K-47. Gerçek SMTP'nin ortama girmesi ayrıca iki eski
e-posta kabul ölçümünü kırdı: üçü panelden değiştirilebilen marka adresini
sabit yazıyordu, `disabled-mail` ölçümü ise `mail()` yerine SMTP'ye kayıp
her koşuda operatörün üretim sunucusuna gerçek bir mesaj bırakıyordu.
Ölçümler adres yerine tek kaynağa bağlandı ve taşıyıcı o tek gönderim için
geri çekilip ölçüldü. Bkz. K-48.

**Tur 9 — müşterinin gördüğü şey.** Bildirim gidiyordu, görünümü mağazanın
değildi: WooCommerce'in 600 pikselik varsayılan iskeleti, mor bağlantılar,
ham `dhl` yazan taşıyıcı satırı, misafir siparişinde bile "Hesabım >
Siparişler" bağlantısı ve takip adresi boşken bile bir bağlantı. Ürün
fotoğrafının Gmail'de görünmemesinin nedeni şablonda resim olmaması değildi:
adres `http://localhost:8080/...` olduğu için Gmail ona erişemiyordu. Artık
bütün müşteri e-postaları tek iskelet, tek stil katmanı ve tek görsel kapısı
kullanıyor: 780 piksel masaüstü, mobilde yüzde yüz genişlik ve yatay taşma
sıfır, halka açık HTTPS olmayan hiçbir adres gönderilmiyor, logo yoksa
tipografik wordmark yazılıyor. Vendor şablonlarının tamamı kopyalanmadı;
yalnız filtresi olmayan üç dosya kopyalandı, kaynak sürümleri kaydedildi ve
yukarı akış farkı ölçümle kilitlendi. Bkz. K-49 ve docs/EPOSTA_TASARIMI.md.

**Tur 10 — iki süreç, tek e-posta.** Bildirim durum makinesi kilitsiz bir
"oku, sonra yaz"dı. Zamanlanmış durum sorgusu ile operatörün "durumu
sorgula" basışı aynı siparişe aynı anda girdiğinde ikisi de `fulfilled`
olmayan bir kayıt ve boş bir bildirim durumu görüyor, ikisi de
gönderiyordu. Sıralı tekrar-poll ölçümleri bunu göstermiyordu; iki gerçek
PHP süreci ve iki ayrı MySQL oturumu gösterdi: iki e-posta, iki bildirim
olayı, ve ikinci yazma birincinin metasını ezdiği için geride kalan kayıt
tek gönderim gibi görünüyordu. Karar artık sipariş bazlı, sıfır beklemeli
bir advisory lock altında veriliyor ve kayıtlar kilit içinde veritabanından
taze okunuyor; kilidi alamayan süreç göndermiyor ve beklemiyor, dolayısıyla
taşıyıcı mutasyon kilidiyle deadlock kurulamıyor. Bkz. K-50.

### On bir tekrarlayan ders

1. **`success` bir alındıdır, kanıt değildir.** Taşıyıcının "iptal edildi"
   demesi, iptalin uygulandığını söylemez. Bir yazma taşıyıcıya ulaştıysa
   ikincisi gönderilemez; çıkış yalnız okumayladır. Beşinci tur buradaki "tek
   istisna"yı da kaldırdı: `permanent` bir ret de reddedilmiş isteğin hiçbir şey
   değiştirmediğini **kanıtlamaz**, çünkü satıcının sözleşmesi altı yazma
   operasyonu için yalnız `200/400/401/500` tanımlar ve hiçbirinde yan etkiden
   söz etmez. Okumadan kapatılabilen tek ret, adaptörün **ağa çıkmadan**
   verdiği rettir (`Result::local_refusal()`, `reached_carrier() === false`) —
   ve bu durum koduyla değil kod yoluyla kanıtlanır.
2. **Nesnenin varlığı, işlemin uygulandığını kanıtlamaz.** Bir CREATE için
   kaydı bulmak kanıttır; bir CANCEL için tam tersidir; bir UPDATE için ise
   hiçbir şey söylemez — nesne güncellemeden önce de oradaydı. Bu yüzden üç
   ayrı mutabakat vardır, biri değil.
3. **Sahiplik siparişin, tercih mağazanın.** Varsayılan ayar yalnız hiç
   dokunulmamış bir siparişe karar verebilir. Kanıt var ama sahip yazılı
   değilse doğru davranış fail-closed'dur, tahmin değil.
4. **Test, mağazanın verisine dokunmamalı; sahiplik bildirilir, çıkarılmaz.**
   "Anlık görüntümde yoktu, demek ki benim" bir sahiplik kanıtı değildir.
   Doğru çözüm koşuya ait bir anahtar alanı ve birebir ad bildirimidir.
5. **Cevaplanamaz bir ölçüm, başarısız bir ölçüm değildir.** Eklenti etkinken
   "tek bir sınıfı yüklü değil" sorusu yanlış değil, cevapsızdır — ve onu FAIL
   raporlamak, `set -e` altında kendisinden sonraki bütün ölçümleri de öldürür.
   Cevapsız ölçüm gerekçesiyle atlanır, garantinin nerede ölçüldüğünü söyler, ve
   yerine her iki durumda sorulabilen bir soru konur. Suite'i "her durumu kabul
   et" diye gevşetmek ise garantiyi tamamen kaybetmektir (K-36).
6. **Yasak listesi, yeni bir durum eklendiği ilk anda delik verir.** Bir dış
   yazmaya izin veren kapı bir **izin listesi** olmak zorundadır: "şu üç durum"
   demek, "bu yedi durum hariç" demekten farklıdır, çünkü ikincisi sekizinci
   durumu sessizce içeri alır. Bu modülde iki kez oldu — `cancelled` create
   kapısında, iki korumalı durum sahiplik kanıtında — ve ikisinde de eksik olan
   şey yeni eklenmiş bir durumdu (K-37, K-38).
7. **Değerin doğru olması, onu senin ürettiğin anlamına gelmez.** Fulfillment
   teslim tarihi bu kurulumda hep doğru çıkıyordu — çünkü WooCommerce'in data
   store'u dolduruyordu, hiçbir yerde yazılı olmayan bir yan etkiyle. Bir
   ölçüm "alan dolu mu" diye sorarsa böyle bir bağımlılığı hiç görmez; "bunu
   kim yazdı" diye sorması gerekir (K-40).
8. **Deneme harcamayan bir başarısızlık, sonsuz bir zincir demektir.** Taşıyıcıya
   ulaşmayan ret bütçeden düşmemelidir — bu doğrudur — ama o zaman zinciri
   bitiren şey de kalmaz. Bütçeyle sınırlanmayan her tekrar için ayrı ve açık
   bir durma koşulu gerekir (K-42).
9. **Bir kancayı kimin tetiklediğini varsayma.** WooCommerce'in gönderim
   bildirimi eylemi, adı öyle görünmesine rağmen bir *durum değişikliğinin*
   değil bir *insan işaretinin* sonucudur: yalnız REST controller tetikler.
   Bir kancanın hangi kod yolundan doğduğu, adından değil `grep`'ten öğrenilir
   (K-44).
10. **Yeniden kullanılan bir nesnede boş alan fark edilir, bayat alan
   edilmez.** İlk bildirimde `$email->object` boştu ve bu görülebilirdi;
   ikincisinde ÖNCEKİ siparişi tutuyordu ve ölçüm iki farklı dilde iki gönderim
   yapana kadar hiçbir şey yanlış görünmedi (K-46).
11. **Bir dönüş değeri gördüğünü ölçen test, sürecin öldüğünü ölçmez.**
   `uncertain` bir `Result` bile koda geri dönmüş demektir: kayıt tutabilecek
   bir kod yolu çalıştı. Çökme öyle değildir. Bu yüzden korumanın tamamı
   **istek gitmeden önce diske yazılmış** olana dayanır, ve ölçüm de bunu
   yansıtmak zorundadır: yazmanın içinde `Throwable` ile kontrol akışını kes,
   sonra **yeni nesne + yeni Manager + yeni adaptörle** tekrar dene ve ikinci
   yazmayı say. Aynı mantık yorumlar için de geçerli: davranış iddia eden bir
   yorumun ölçümü yoksa, yorum silinmeli ya da ölçüm yazılmalı.

### Modül şu anda ne durumda

- Eklenti **etkin** (kullanıcının beşinci turdaki açık kararı). Etkinlik tek
  başına hiçbir şey göndermez: kimlikler eksik olduğu için her çalışma zamanı
  yazması `credentials_missing` ile ağdan önce reddedilir.
- Otomatik durum sorgusu **kapalı** (`KUKA_SHIPPING_AUTOMATION` tanımsız).
- Adaptör açık; kendi anahtarı var (`KUKA_DHL_ADAPTER`) ve tanınmayan her
  değerde **kapanır** (K-33).
- Aktiflik tek başına kargo oluşturmaz: gönderi yalnız operatörün açık
  basışıyla oluşur, hiçbir sipariş durumu kancası bu yola bağlı değildir.
- Dört anahtar (eklenti / çalışma kapısı / otomatik sorgu / adaptör) sipariş
  ekranında birlikte yazılıdır.

### Sandbox: nerede kaldı

Kimlik dosyasında **2/4** anahtar var; kargo hesabı çifti
(`KUKA_DHL_CUSTOMER_NUMBER`, `KUKA_DHL_PASSWORD`) eksik. Salt-okunur bağlantı
testi çalıştırıldı ve kimlik kapısında durdu: **dış çağrı 0**. Yani bu projede
kargo tarafında henüz **hiçbir gerçek taşıyıcı çağrısı yapılmadı** — bütün
kanıt mock transport ve offline ölçüm. `Ö-01`…`Ö-05` açık.

Tek sandbox gönderisi için çalıştırılacak tam komut, beklenen dış etkileri ve
geri alma zinciri `docs/DHL_BAKIM_HAFIZASI.md` → "Sandbox hazırlığı" Aşama 5'te
yazılı ve **açık kullanıcı onayı bekliyor**.

### Bakım sırasında izlenecek sıra

`docs/DHL_BAKIM_HAFIZASI.md` → "Bakım sırası". Kısaca: hangi anahtar kapalı →
sipariş hangi taşıyıcıya ait → bekleyen bir yazma kanıtı var mı → ret ağa çıktı
mı çıkmadı mı → önce davranış ölçümleri, sonra `make verify`, gerçek sandbox en
son ve yalnız operatör kontrolünde.

Bu bölümde hiçbir kimlik bilgisi, token, parola veya müşteri numarası yoktur ve
yazılmayacaktır.

---

## 16. Bu belgeyi okuyan yapay zekâya

1. **Ölç, tahmin etme.** Bu projede her "tamamlandı" iddiası ekran görüntüsü veya sayı ile desteklenir. Desteklenmiyorsa "doğrulanmadı" yaz.
2. **Prototipi çevir, yeniden tasarlama.** `app-reference/` kaynaktır.
3. **Vendor'a dokunma.** Blocksy, WooCommerce ve iyzico.
4. **Ödeme ve vergi matematiğine kod yazma** (§17.3). Davranışı doğrula, raporla.
5. **Ticari değer koda gömülmez.** Panelden okunur.
6. **Token dışına çıkma.** px, hex, rgba, gölge — hepsi sıfır.
7. **Commit mesajına imza atma.**
8. **Kendi hatanı bulursan düzelt ve devam et.** Bu belgede benim üç hatam yazılı; onları saklamak projeye zarar verirdi.
