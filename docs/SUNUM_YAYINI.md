# Sunum yayını ve erişim kontrolü

Bu belge hazırlık kontrol listesidir; adımları uygulamak yayın veya erişim politikasını kendiliğinden başlatmaz. Yayından önce aşağıdaki iki yoldan yalnızca biri seçilmelidir.

`noindex, nofollow` sayfalarda zaten bulunur. Bu işaret arama motorlarına bir istektir; bağlantıyı bilen kişiyi engellemez ve erişim kontrolü değildir.

## Yol A — Sites erişim politikası (önerilen)

Sites'in erişim katmanı uygulamaya gelmeden önce ziyaretçiyi denetler. Kodda parola tutulmaz; kişi bazlı erişim geri alınabilir. Dezavantajı, müşterinin izin verilen e-posta hesabıyla oturum açması ve e-posta listesinin yayın öncesi eksiksiz hazırlanması gereğidir. Ortak parola paylaşma ihtiyacı olmadığı için önerilen yoldur.

### Etkinleştirme

1. Müşteri ve proje ekibinden erişecek kişilerin e-posta adreslerini yazılı olarak doğrulayın.
2. Site kaydı oluşturulup ilk sürüm hazırlanırken, paylaşım/erişim ayarını `custom` seçin. Arayüz adı değişirse eşdeğer “özel/izin listesi” seçeneğini kullanın.
3. `allowed_user_emails` listesine yalnızca doğrulanan adresleri ekleyin; ihtiyaç yoksa grup eklemeyin. Site sahibi zaten erişebilir.
4. Ayarı kaydedip gizli pencerede izinsiz bir hesapla erişimin reddedildiğini, izinli müşteri hesabıyla erişimin açıldığını kontrol edin.
5. Bu kontrol geçmeden müşteri bağlantısını göndermeyin. Sites bu işlem sırasında davet e-postası göndermediğinden bağlantıyı ayrıca iletin.

### Geri alma

1. Sunum bittiğinde siteyi arşivleyin/silin veya erişim listesinden müşteri adreslerini çıkarın.
2. Geçici olarak yalnızca sahibin görmesini istiyorsanız `custom` politikayı sahibi dışında açık kullanıcı/grup kalmayacak şekilde daraltın.
3. `public` moda geçiş bağlantıyı herkese açar; yalnızca açık yayın için ayrıca yazılı onay alındığında kullanılmalıdır.

## Yol B — worker HTTP Basic Auth (yedek)

Worker'daki kapı, yalnızca `PRESENTATION_BASIC_AUTH` ortam değişkeni boş olmayan bir değerle tanımlandığında bütün istekleri — görseller ve diğer statik varlıklar dahil — korur. Değişken yoksa kod yolu tamamen devre dışıdır. Avantajı platformdan bağımsız ve basit olmasıdır. Dezavantajı ortak bilginin güvenli paylaşılması, tarayıcı parola penceresi ve parolanın düzenli değiştirilmesi sorumluluğudur.

### Etkinleştirme

1. Yerel dosyaya veya repoya yazmadan güçlü, sunuma özel bir `kullanici:parola` değeri üretin. Kullanıcı adında `:` kullanmayın.
2. Barındırma ortamının secret/variable ekranında `PRESENTATION_BASIC_AUTH` adını oluşturup değeri secret olarak girin. `.env.example` yalnızca değişken adını gösterir.
3. Yeni sürümü yayımladıktan sonra gizli pencerede ana sayfa ve doğrudan bir görsel URL'sinin 401/parola penceresi verdiğini doğrulayın.
4. Doğru bilgiyle ana sayfa, ürün sayfası ve görselin açıldığını kontrol edin. 401 yanıtında `WWW-Authenticate` ve `X-Robots-Tag: noindex, nofollow` bulunmalıdır.

### Geri alma

1. Barındırma ortamından `PRESENTATION_BASIC_AUTH` değişkenini tamamen silin; boş veya örnek parola bırakmayın.
2. Ortam değişikliği yeni sürüm gerektiriyorsa aynı onaylı commit'i yeniden yayımlayın.
3. Parola penceresi olmadan ana sayfanın 200 verdiğini doğrulayın. Daha önce paylaşılan sunum bilgisini yeniden kullanmayın.

## Yayın sonrası zorunlu `IMAGES` kontrolü

Kontrol URL'si: `https://SUNUM-ADRESI/_vinext/image?url=%2Fimages%2Fdemo%2Fnoir-one-piece.jpg&w=640&q=78`.

Başarılı sonuç 200 döner; `Content-Type: image/webp` ve `X-Image-Optimization: enabled` başlıklarını taşır. İndirilen WebP, aynı sunumdaki `/images/demo/noir-one-piece.jpg` kaynak dosyasından bayt olarak küçük olmalıdır. Yol B kullanılıyorsa aşağıdaki `BASIC_AUTH` değerini terminalde geçici olarak doldurun; Yol A kullanılıyorsa aynı üç kontrolü izinli oturumun tarayıcı Network panelinde yapın.

```bash
SITE_URL='https://SUNUM-ADRESI' BASIC_AUTH='' bash -c 'a=(); test -z "$BASIC_AUTH" || a=(-u "$BASIC_AUTH"); curl -fsS "${a[@]}" -o /tmp/kuka-original.jpg "$SITE_URL/images/demo/noir-one-piece.jpg" && curl -fsS "${a[@]}" -D /tmp/kuka-image.headers -o /tmp/kuka-image.webp "$SITE_URL/_vinext/image?url=%2Fimages%2Fdemo%2Fnoir-one-piece.jpg&w=640&q=78" && grep -qi "^content-type: image/webp" /tmp/kuka-image.headers && grep -qi "^x-image-optimization: enabled" /tmp/kuka-image.headers && test "$(wc -c </tmp/kuka-image.webp)" -lt "$(wc -c </tmp/kuka-original.jpg)" && echo "IMAGES OK"'
```

Komut `IMAGES OK` yazmazsa bağlantıyı müşteriye göndermeyin. `X-Image-Optimization: disabled` bağlayıcının yayın ortamında olmadığını gösterir: `IMAGES` binding'ini o ortama ekleyin, aynı doğrulanmış commit'i yeniden yayımlayın ve testi tekrarlayın. 401 erişim bilgisini; 400 ise URL, genişlik (`640`) ve izinli genişlik ayarını kontrol etmeyi gerektirir. WebP başlığı olup dosya küçük değilse dönüşüm ayarını ve kaynak dosyayı inceleyip sonucu elle onaylamayın.
