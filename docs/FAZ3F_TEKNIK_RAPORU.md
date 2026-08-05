# Faz 3F Teknik Raporu

Tarih: 5 Ağustos 2026  
Kapsam: İçerik tamamlama ve Veridyen test yayını hazırlığı  
Sonuç: 22/22 kabul kriteri karşılandı; fiili deploy ve canlı ödeme yapılmadı.

## Kabul ölçümleri

| # | Kriter | Ölçülen sonuç | Durum |
|---:|---|---|---|
| 1 | Altı yasal sayfa, görünür taslak uyarısı ve şirket yer tutucuları | `LEGAL_DRAFT_WARNINGS=6/6`, `LEGAL_CENTRAL_COMPANY=6/6`; tarayıcıda uyarı ve 7 şirket satırı görüldü | Karşılandı |
| 2 | Yer tutucuların tek kaynağı | 7/7 değer `Site Görünümü → Şirket ve Yasal Yer Tutucular` grubunda; 6 sayfa `[kuka_company_details]` kullanıyor | Karşılandı |
| 3 | Hijyen metni birebir tutarlı | İade sayfası ve ürün akordiyonu aynı `Kuka_Island_Core_Content::HYGIENE_POLICY` sabitini okuyor; `HYGIENE_SINGLE_SOURCE=yes` | Karşılandı |
| 4 | KVKK yalnız fiilen toplanan verileri anlatıyor | 8 veri grubu ve yalnız belirtilen 5 alıcı/işleyici grubu yazıldı; fazladan form alanı eklenmedi | Karşılandı |
| 5 | Çerez politikasında olmayan takip aracı yok | GA4/Meta Pixel kurulmadığı açıkça yazıldı; analitik çerezlerin etkin olmadığı ve zorunlu olmayanların varsayılan kapalı olduğu belirtildi | Karşılandı |
| 6 | SSS altı özgün kategori | Beden ve kalıp, ürün ve kumaş, kargo, iade/değişim, ödeme ve sipariş: 6/6 | Karşılandı |
| 7 | Ticari değerler panelden okunuyor | Tarayıcıda 149→151 TL, 1.500→1.510 TL ve 14→15 gün değiştirildi; SSS'de üçü de değişti; sonra 149/1.500/14 değerlerine geri alındı | Karşılandı |
| 8 | Beden rehberi | 3/3 ayrı düzenlenebilir tablo, cm açıklaması, ölçüm anlatımı, 34–42 ve XS–XL; 320 px viewport'ta sayfa taşması 0, tablo kendi kabında kayıyor | Karşılandı |
| 9 | Instagram | `INSTAGRAM_LINK=yes`; footer ve iletişim sayfasında iki görünür bağlantı, adres `https://www.instagram.com/kukaisland` | Karşılandı |
| 10 | Ölü form yok | İletişim ve bülten alanları entegrasyon kurulana kadar açıkça devre dışı; sipariş takip formu gerçek WooCommerce formu | Karşılandı |
| 11 | Dört pilot ürünün zorunlu alanları | 4/4 üründe kumaş, bakım, kalıp, model boyu/bedeni, beden rehberi, SEO başlığı ve meta açıklaması: `required_fields:yes` | Karşılandı |
| 12 | CSV şablonu ve rehber | `docs/URUN_AKTARIM_SABLONU.csv` ile `docs/URUN_CSV_REHBERI.md`; istenen 14 veri grubu kapsanıyor | Karşılandı |
| 13 | Veridyen runbook | Panel/dosya yöneticisi, SFTP, koşullu SSH, DB/URL/medya, `wp-config.php`, noindex, coming soon seçenekleri, SMTP, kontrol ve geri dönüş adımları mevcut; gerçek sır yok | Karşılandı |
| 14 | Deploy paket betiği | `scripts/build-deploy-package.sh` başarıyla checksum'lı `.tar.gz` üretti; arşiv child tema + Core eklenti + runbook içeriyor; `git check-ignore` geçti | Karşılandı |
| 15 | Metin özgünlüğü | Bu fazdaki yasal, yardım, marka ve ürün metinleri Kuka Island için sıfırdan yazıldı; Jacquemus, Aslora veya başka bir siteden metin kopyalanmadı | Karşılandı |
| 16 | Token disiplini | `tokens.css` dışında ham `px=0`, `hex=0`, `rgba=0`; tanımsız token `0`; gölge `0` | Karşılandı |
| 17 | Kontrast | Aşağıdaki 8/8 ana metin/durum çifti AA 4.5:1 üzerinde | Karşılandı |
| 18 | Yatay taşma | 8 rota × 6 genişlik = 48/48 tarayıcı ölçümü; azami taşma `0` | Karşılandı |
| 19 | İki temiz kurulum | Nihai kod üzerinde temiz volume'dan 2/2 `make reset && make verify`; her ikisi `VERIFY=PASS`, smoke `5/5` | Karşılandı |
| 20 | CI kapısı | `composer.json` strict doğrulaması geçti; PHP 8.3 ile 31/31 `php -l`, PHPCS 31/31, iki temiz install/verify ve smoke 5/5 geçti; GitHub workflow aynı kapıları içeriyor | Karşılandı |
| 21 | Sağlayıcı dosyaları | Blocksy parent, WooCommerce ve iyzico altında çalışma ağacı değişikliği `0`; WooCommerce override sayısı yine `2` | Karşılandı |
| 22 | Gizli bilgi | İzlenen gerçek `.env` dosyası `0` (`.env.example` yalnız değişken adları içeriyor); yüksek güvenli anahtar/özel anahtar taraması `0`; deploy paketi Git dışı | Karşılandı |

## Merkezî yer tutucular

Müşteri bilgisi uydurulmadı. Aşağıdaki 7 değer `Kuka Island → Site Görünümü → Şirket ve Yasal Yer Tutucular` altında tek noktadan yönetilir:

- `[ŞİRKET UNVANI]`
- `[VKN]`
- `[VERGİ DAİRESİ]`
- `[ADRES]`
- `[TELEFON]`
- `[ETBİS NO]`
- `[MERSİS NO]`

Altı yasal sayfa bu alanları `[kuka_company_details]` kısa koduyla okur. Kargo firması, tahmini teslimat süresi ve iade kargo sorumluluğu da ayrı ticari panel alanlarında yer tutucu kalır.

## Hijyen metni tutarlılık kanıtı

İade sayfası ile dört ürünün iade akordiyonu şu tek Core sabitinden render edilir:

> Koruyucu unsur, hijyen bandı veya mühür teslimden sonra açılmışsa; ürünün niteliği ve yürürlükteki mevzuat değerlendirilerek cayma hakkı istisnası uygulanabilir. Bu sonuç otomatik değildir ve her talep kendi koşullarıyla incelenir.

Kaynak sayısı `1`, tüketici yüzeyi sayısı `2`'dir. Otomatik ret ifadesi kullanılmadı.

## Ticari panel testi

| Değer | Başlangıç | Panel testi | SSS'de görülen | Geri yüklenen |
|---|---:|---:|---:|---:|
| Standart kargo | 149 TL | 151 TL | 151 TL | 149 TL |
| Ücretsiz kargo eşiği | 1.500 TL | 1.510 TL | 1.510 TL | 1.500 TL |
| İade/değişim süresi | 14 gün | 15 gün | 15 gün | 14 gün |

Kargo ücreti ve ücretsiz eşik kaydında WooCommerce `flat_rate` ve `free_shipping` yöntemleri de aynı kaynaktan güncellendi.

## Kontrast ölçümü

| Ön plan / arka plan | Oran |
|---|---:|
| Ink / paper | 12.93:1 |
| Muted / paper | 5.51:1 |
| Ink / sand | 11.35:1 |
| Muted / sand | 4.84:1 |
| Muted-on-ink / ink | 6.87:1 |
| White / ink | 13.48:1 |
| Success / paper | 5.80:1 |
| Error / paper | 6.88:1 |

## Responsive taşma ölçümü

Ölçülen rotalar: ana sayfa, mağaza, ürün detay, beden rehberi, SSS, mesafeli satış, iletişim ve sipariş takibi.

| Viewport | Ölçülen rota | Yatay taşma görülen | Azami taşma |
|---:|---:|---:|---:|
| 320 | 8 | 0 | 0 |
| 390 | 8 | 0 | 0 |
| 768 | 8 | 0 | 0 |
| 1024 | 8 | 0 | 0 |
| 1280 | 8 | 0 | 0 |
| 1920 | 8 | 0 | 0 |

Görsel kanıtlar: `docs/qa/faz3f-size-guide-320.png` ve `docs/qa/faz3f-legal-draft-1280.png`.

## Kullanıcının yapması gerekenler

1. Şirketin 7 gerçek bilgisini ve kargo firması/süre/iade kargo kararını panelde doldurmak.
2. Altı yasal taslağı şirket yetkilisi ve hukuk danışmanına onaylatmak; onaydan sonra taslak uyarısının kaldırılmasına ayrıca karar vermek.
3. Geçici Hakkımızda anlatısını gerçek marka hikâyesiyle, pilot ürünleri gerçek katalog/fiyat/stok/görsellerle değiştirmek.
4. Veridyen erişim yöntemi ile coming soon seçeneğini belirlemek ve `DEPLOY_RUNBOOK.md` adımlarını yetkili hesapla uygulamak.
5. SMTP hesabını/DNS kayıtlarını oluşturmak ve test e-postalarını doğrulamak.
6. iyzico sandbox bilgilerini hosting secret alanında tanımlayıp ödeme testini tamamlamak; canlı anahtarı yalnız onaylı canlı geçişte girmek.
7. Yayından önce gerçek yedek ve geri dönüş testini yapmak.

## Bilerek yapılmayanlar

- Fiili Veridyen deploy yapılmadı; erişim bilgisi istenmedi.
- Canlı iyzico anahtarı, gerçek tahsilat ve 3D Secure dönüşü denenmedi.
- SMTP hesabı veya DNS kaydı oluşturulmadı.
- Hukuki onay verilmedi; yasal metinler görünür biçimde taslaktır.
- Instagram beslemesi/grid'i eklenmedi; yalnız bağlantı var.
- Geçici marka hikâyesine kuruluş yılı, üretim yeri veya sertifika uydurulmadı.

Not: macOS hostundaki eski PHP 7.1 kurulumu eksik `aspell` dinamik kütüphanesi nedeniyle çalışmadığından kalite araçları projenin sabitlenmiş PHP 8.3 Docker imajında çalıştırıldı. Bu, GitHub Actions'taki PHP 8.3 ortamıyla aynıdır ve proje koduna ilişkin bir hata değildir.
