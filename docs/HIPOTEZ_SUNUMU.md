# Kuka Island — Tasarım Hipotezleri

Bu belge, müşteri sunumunda özellikle karar verilmesi gereken iki tasarım fikrini açıklar. İki fikir de prototipte karşılaştırma amacıyla bulunur; müşteri onayı olmadan kalıcı mağaza gereksinimi sayılmaz.

## 1. Ürün kartında beden ve stok satırı

Ana sayfadaki **Yeni gelenler** bölümünde iki görünüm karşılaştırılabilir:

- **Stok satırlı:** SKU ve bedenlerin stok durumu ürün kartında görülür. Kullanıcı tükenen bedeni ürün sayfasına girmeden fark eder; kart daha yoğun görünür.
- **Sade kart:** SKU ve beden satırı gizlenir. Ürün fotoğrafı ve adı daha güçlü kalır; beden bilgisi ürün detayında görülür.

### Nasıl deneyebilirsiniz?

1. Ana sayfayı açın: `/`
2. **Yeni gelenler** bölümüne ilerleyin.
3. **Stok satırlı** ve **Sade kart** düğmeleri arasında geçiş yapın.
4. Hem masaüstünde hem telefonda kartların okunabilirliğini karşılaştırın.

### Karar sorusu

Ürüne girmeden stok görmek mi, daha sade ve fotoğraf odaklı kartlar mı Kuka Island için daha doğru?

Onaylanmazsa stok satırı kaldırılır; beden ve stok bilgisi yalnızca ürün detayında gösterilir.

## 2. Ana sayfada kesim indeksi

Ana sayfadaki **Formunu bul** bölümü, ürün gruplarını büyük bir tipografik liste olarak sunar. Bu yaklaşım katalog geniş olduğunda keşfi hızlandırabilir; küçük katalogda gereğinden fazla yer kaplayabilir.

### Nasıl deneyebilirsiniz?

1. Ana sayfayı açın: `/`
2. Hero görselinin hemen altındaki **Formunu bul** bölümünü inceleyin.
3. Liste bağlantılarından kategori sayfalarına geçin.
4. Yapının marka hissine ve gerçek katalog büyüklüğüne uygun olup olmadığını değerlendirin.

### Karar sorusu

Kesim/kategori listesinin ana sayfada belirgin bir tasarım öğesi olması doğru mu, yoksa standart fotoğraflı kategori vitrini yeterli mi?

Onaylanmazsa bu indeks kaldırılır veya standart kategori vitriniyle değiştirilir.

## Doğrulama notu

4 Ağustos 2026 tarihli kontrolde:

- Masaüstü ana sayfa: **1280 px viewport / 1280 px içerik genişliği**, yatay taşma yok.
- Mobil ana sayfa: **390 px viewport / 390 px içerik genişliği**, yatay taşma yok.
- Stok satırlı görünümde **4**, sade görünümde **0** görünür stok satırı ölçüldü.
- Ana sayfada tek `h1`, tek içerik atlama bağlantısı ve erişilebilir bölüm başlıkları korundu.

Bu ölçümler teknik uygunluğu gösterir; hangi seçeneğin marka için doğru olduğu müşteri kararıdır.
