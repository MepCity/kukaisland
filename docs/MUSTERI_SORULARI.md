# Kuka Island — Tasarım ve Mağaza Başlangıç Soruları

Bu form, tasarımı ve mağaza altyapısını yanlış varsayımlarla kalıcılaştırmamak için hazırlanmıştır. Kısa cevaplar yeterlidir. Cevabı henüz belli olmayan maddeler `Karar verilmedi` olarak işaretlenebilir.

## Tasarımı başlatmak için gerekli

1. Marka adı ve yazımı kesin olarak nedir? (`Kuka Island` geçici çalışma adıdır.)
2. Kullanılacak logo veya wordmark hazır mı? Varsa SVG, PDF veya yüksek çözünürlüklü dosyası paylaşılabilir mi?
3. Markanın mevcut renkleri veya özellikle kaçınılması istenen renkleri var mı?
4. Beğenilen ve beğenilmeyen 2–3 marka/site örneği var mı? Her örnekte özellikle beğenilen bölüm nedir?
5. Profesyonel ürün ve kampanya fotoğrafları ne zaman hazır olacak?

## Ürün yapısını kesinleştirmek için gerekli

6. Bikini üstleri ve altları ayrı mı, takım mı, yoksa iki şekilde de mi satılacak?
7. Ayrı satılan üst ve alt parçalar farklı bedenlerde eşleştirilebilecek mi?
8. Beden sistemi `XS–XL` mi, `34–42` mi olacak?
9. Açılışta yaklaşık kaç farklı kesim olacak: 10’dan az, 10–30 arası, 30’dan fazla?
10. Bir kesimin ortalama kaç renk seçeneği olacak?
11. Her renk için ayrı ürün fotoğrafı seti bulunacak mı?
12. Açılıştaki tahmini ürün sayısı kaçtır? 30+ kesim açılış kataloğu mu, ileride ulaşılacak hedef mi?
13. Site ilk aşamada yalnızca Türkçe ve TL ile mi çalışacak?

## Satış ve canlıya alma öncesinde gerekli

14. Başlangıçta hangi kargo yöntemi veya firması kullanılacak?
15. Ücretsiz kargo limiti olacak mı? Olacaksa tutarı nedir?
16. İade/değişim politikası ve yasal metinler hazır mı?
17. Hijyen bandı uygulanacak mı?
18. Şirket türü nedir: şahıs, limited veya anonim?
19. Vergi levhası ve iyzico başvurusu için şirket bilgileri hazır mı?
20. e-Fatura durumu mali müşavirle görüşüldü mü?

## Şimdilik kullanılan, değiştirilebilir varsayımlar

- Çalışma adı: Kuka Island.
- Bikini üstü ve altı ayrı ürün; eşleşen parçalar birbirine bağlanır.
- Beden sistemi: 34–42.
- Dil ve para birimi: Türkçe / TRY.
- Her renk ayrı varyasyon ve ayrı galeri taşıyabilir.
- Tasarımda özgün demo içerikleri kullanılacaktır.

Bu varsayımlar yalnızca prototipin ilerleyebilmesi içindir; gerçek cevap geldiğinde veri katmanından değiştirilecektir.

## Faz 3F sonunda hâlâ beklenenler

| Girdi | Durum | Panelde doldurulacağı yer |
|---|---|---|
| Şirket unvanı, VKN, vergi dairesi, adres, telefon, ETBİS ve MERSİS | Bekleniyor; uydurulmadı | Site Görünümü → Şirket ve Yasal Yer Tutucular |
| Kargo firması ve tahmini teslimat süresi | Bekleniyor | Site Görünümü → Ticari Bilgiler |
| Standart kargo ücreti ve ücretsiz kargo eşiği onayı | Pilot değerler 149 TL / 1.500 TL | Site Görünümü → Ticari Bilgiler |
| İade kargo ücretinin kime ait olduğu | Bekleniyor | Site Görünümü → Ticari Bilgiler |
| Hijyen bandı/mühür operasyon kararı | Bekleniyor; metin koşullu ve otomatik ret kurmuyor | Yasal metin onayı + ürün operasyonu |
| Yasal taslakların hukuk/şirket onayı | Bekleniyor | Onay sonrası sayfa metinleri ve merkezî yer tutucular |
| Gerçek marka kuruluş hikâyesi | Bekleniyor; Hakkımızda metni geçici | Sayfalar → Hakkımızda |
| Gerçek ürün kataloğu, fiyat, stok ve fotoğraflar | Bekleniyor; dört ürün pilot şablon | Ürünler veya CSV şablonu |
| SMTP hesabı ve DNS kayıtları | Veridyen test yayını öncesi müşteri açacak | Hosting/DNS + seçilen SMTP eklentisi |
| Coming soon altında test erişim yöntemi | Müşteri seçimi bekleniyor | `DEPLOY_RUNBOOK.md` §6 |
| iyzico sandbox/canlı aktivasyonu | Bekleniyor; canlı anahtar repoya girilmeyecek | WooCommerce ödeme ayarları / secret alanı |
