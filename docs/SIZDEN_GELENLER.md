# Sizden Gelenler — kullanım ve bakım

22–23 Eylül 2026: kullanıcı kademeli fotoğraf galerisini onayladı; son isteğinde fotoğraf altı yazıları, büyük görüntü penceresini ve izin/tarih/not alanlarını kaldırdı. **Son kapsam: üst başlık/açıklama + yalnız fotoğraflar + isteğe bağlı doğrudan ürün bağlantısı.** Ziyaretçi yükleme formu ve Instagram API entegrasyonu yoktur.

## Kullanım

**Kuka Island → Sizden Gelenler**. İlk kurulumda **Onaylanan dört başlangıç fotoğrafını yükle**, teslim edilen fotoğrafları WordPress Medya Kütüphanesi'ne alır. Var olan galeri üzerine yazmaz. İlk içe aktarım tamamlanana kadar boş bölüm sitede gösterilmez.

**Fotoğraf ekle / toplu seç** ile yükleyin veya kütüphaneden seçin. Fotoğrafın **Ürün bağlantısı** alanına isteğe bağlı tam HTTP/HTTPS adresini yapıştırın; fotoğrafa tıklanınca aynı sekmede doğrudan bu adres açılır. Boş bağlantı fotoğrafı tıklanamaz bırakır. Başlangıç fotoğraflarının ürünleri bilinmediği için bağlantıları boştur.

- Yeni kayıt taslaktır; **Yayında** seçilip **Galeriyi kaydet** ile vitrinde gösterilir.
- Sıralama sürükleme veya yukarı/aşağı düğmeleriyle yapılır.
- Arşiv kaydı saklar, vitrinden kaldırır.
- Galeriden çıkar kayıt satırını kaldırır; Medya Kütüphanesi dosyasını silmez. Kaydetmeden önce sayfayı yenileyerek geri alınabilir.
- Galeride kişi adı, açıklama, sıra numarası, izin tarihi/notu ve ayrı ürün düğmesi gösterilmez.
- Bölüm başlığı/açıklaması TR/EN düzenlenir. İngilizce alan boşsa Türkçe karşılık kullanılır.
- Görsel alternatif metni Medya Kütüphanesi'nden alınır; fotoğrafın altında yazı olarak gösterilmez.
- En fazla 40 kayıt saklanır.

## Mimari

Core `class-community-gallery.php`, `kuka_island_community_gallery` seçeneğini yönetir (`autoload=false`). Yönetim `manage_woocommerce`, başlangıç içe aktarımı ayrıca `upload_files` yetkisi ve nonce ister. Sunucu görsel kimliğini, kayıt sayısını, durum ve URL protokolünü doğrular. Eksik form ve eski revizyon reddedilir. Revizyon kontrolü ardışık eski pencere yazmalarını engeller; atomik eşzamanlı kilit değildir.

Child tema `template-parts/community-gallery.php` ile eski Editoryal bölümün yerini alır. Ürün bağlantısı bulunan fotoğraflar hem görselden hem de altında gösterilen iki dilli “Ürüne Git / View product” çağrısından aynı ürüne gider; bağlantısız fotoğrafta boş CTA üretilmez. Eski editoryal değerler korunur, panelde arşiv etiketi taşır. Yeni Gelenler, Manifesto, ürün/sipariş ve kargo çekmecesi akışları değiştirilmez.

Fotoğraflar WordPress responsive medya boyutlarıyla, tembel yüklemeyle sunulur. JS kapalıyken ürün bağlantıları ve yatay kaydırma çalışır. Otomatik dönüş yoktur. Mobil yatay kaydırma ve taşma varsa oklar vardır. Azaltılmış hareket tercihinde giriş/zoom efektleri kapalıdır. Modal/büyük fotoğraf JS'si yoktur.

## Son sürümde ölçülen sonuçlar

Yerel WordPress Playground: **PHP 8.5.10, WordPress 7.1.2, WooCommerce 11.1.2**. Bu ortam projenin sabitlenmiş Docker sürümleriyle aynı değildir.

- Dört gerçek fotoğraf içe aktarıldı. Vitrin 4 fotoğraf; alt yazı ve dialog 0.
- 1280, 390, 320 genişliklerinde belge yatay taşması 0; mobil rail kendi içinde kayıyor.
- Ürün URL'i panelden kaydedildi, fotoğraf linki oluştu; tıklama gerçek geçici WooCommerce ürün sayfasına ulaştı. Test bağlantısı kaldırıldı, ürün çöp kutusuna taşındı.
- Panel sıralama/arşivleme: 4 → 3 vitrin fotoğrafı ve değişen ilk fotoğraf ölçüldü. Başlangıç sırası geri getirildi.
- Toplu iki fotoğraf ekleme: panel 4 → 6, yeni kayıtlar taslak, vitrin 4. Çıkarma/kayıt sonrası panel tekrar 4.
- TR/EN bölüm metinleri doğrulandı.
- `scripts/verify-community-gallery.php`: son şemaya göre 16 davranış kontrolü geçti. Eksik görsel, bozuk iç içe girdi, 41 kayıt, zararlı/geçersiz URL, geçerli ve boş link, kaldırılan alanların saklanmaması, yayın filtresi, yetkisiz kayıt, yanlış nonce, eksik form, eski revizyon ve verinin korunması ölçüldü.
- Değişen PHP dosyaları `TOKEN_PARSE`; iki JavaScript dosyası `node --check` ile geçti. Tema renk/px/tanımsız token taraması 0/0/0.

**Sınır:** Docker kurulumu BuildKit disk I/O hatasıyla durdu. Kanonik `make verify`, MySQL ortamı ve tüm mağaza regresyonu çalıştırılmış sayılmaz. Canlı aktarım, gerçek mobil Safari/Android testi yapılmadı. Yerel önizleme üretim veritabanı değildir.

Üretime aktarımda Core + child tema birlikte taşınır; panelde başlangıç fotoğrafları içe aktarılır, önbellek temizlenir ve vitrin/panel kontrolü tekrarlanır. Canlı görünürlük ayarları değiştirilmez.
