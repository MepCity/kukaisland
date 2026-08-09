# Faz 5E — İngilizce sepet, ödeme ve dil sözleşmesi teknik raporu

Tarih: 2026-08-09  
Ortam: yerel Docker, `http://localhost:8080`  
Kısıt: ödeme/vergi matematiği, vendor dosyaları, deploy ve canlı anahtarlar değiştirilmedi.

## Merkezi URL dili

İngilizce bağlamı yalnız `home_url()` filtresine bırakılmadı. Aynı merkezî dönüştürücü; yazı/sayfa/ürün/terim permalinklerini, sayfalama URL'lerini, WooCommerce sepet/ödeme/hesap/sipariş-alındı/dönüş URL'lerini ve public yönlendirmeleri kapsıyor. `wp-admin`, `wp-login.php`, `wp-json`, `admin-ajax.php` ve `wc-ajax` teknik uçları dışarıda kalıyor.

Smoke taraması dil değiştiricinin bilinçli Türkçe hedefini dışarıda bırakıp normal görünür bağlantıları saydı. Home, katalog, ürün, sepet, ödeme, sipariş takibi, hakkımızda, beden rehberi, kargo, iade, SSS ve iletişim sayfalarının her birinde ön eksiz normal iç bağlantı sayısı **0**.

Sipariş alındı URL'i ve iyzico'nun kullandığı WooCommerce dönüş filtresi `/en/odeme/order-received/...` yolunu korudu. Teknik AJAX yolu ön eksiz kaldı.

## Cart fragments ve AJAX

Fragment imzası panel/script mtime değerlerine ek olarak `tr` veya `en` içeriyor; iki dil aynı sessionStorage fragment anahtarını paylaşmıyor. Sepet hash anahtarı da dile göre ayrıldı. Aksi hâlde İngilizce sepete ekleme ortak hash'i güncellerken Türkçe taraftaki eski “boş sepet” fragment'i yeni hash ile geçerli sanılıyordu. Cookie hash'i ortak kaldığı için dil değişiminde eski dil cache'i artık uyuşmuyor ve WooCommerce doğru sepeti sunucudan yeniliyor.

İngilizce `wc_ajax_url`, `%%endpoint%%` yer tutucusunu bozmadan `kuka_lang=en` parametresi taşır. Dil çözüm sırası açık istek parametresi, Referer ve mevcutsa WooCommerce oturumudur. Referer verilmeden yapılan `get_refreshed_fragments&kuka_lang=en` isteği İngilizce “Return to shop” ve `/en/magaza/` döndürdü. Gerçek tarayıcı testinde Türkçe boş sepet cache'i oluşturuldu, İngilizce vitrinde ürün eklendi ve seçiciden Türkçeye dönüldü: sayaç `1`, sepet satırı `1`, boş durum `false`. [Türkçe sepet çekmecesi](qa/faz5e/cart-language-switch-tr.png).

## Dil adları ve çevrilmeyen alanlar

Seçici iki vitrinde de paneldeki tek kaynaktan `Türkçe` ve `English` okur: [TR açık menü](qa/faz5e/language-selector-tr.png), [EN açık menü](qa/faz5e/language-selector-en.png). Seed değeri `Türkçe|/` ve `English|/en/` olarak sabittir.

Denetimde çeviri sözleşmesine yanlışlıkla dahil edilmiş **1 alan** bulundu ve çıkarıldı: `brand.social_links_labels_en`. Ayrıca eski kurulumlarda oluşabilecek `languages.items_en` migrasyonda açıkça siliniyor. Sonuçta çevrilebilir Site Görünümü alanı `42 → 41`; kayıtlı beklenmeyen `_en` ikizi `0`. URL, sayı, medya ID'si, renk, telefon, şirket/adres ve marka alanları tek kaynaktır. Panel notu [burada](qa/faz5e/panel-language-note.png).

## İki dilli E2E

Her dilde ana sayfa → mağaza → ürün → sepet çekmecesi → sepet → ödeme → sipariş alındı akışı görüntülendi. İngilizce ürün/variation ve kalıcı sipariş satırı adları da çevrildi; görünür İngilizce yüzey taramasında Türkçe ticaret terimi `0` oldu. Sipariş e-posta renderı `_kuka_order_locale=en_US` ile İngilizce başlık, gövde, ürün ve takip bağlantısı üretti.

| Adım | Türkçe | English |
|---:|---|---|
| 1 Ana sayfa | [görüntü](qa/faz5e/e2e-tr-01-home.png) | [görüntü](qa/faz5e/e2e-en-01-home.png) |
| 2 Mağaza | [görüntü](qa/faz5e/e2e-tr-02-shop.png) | [görüntü](qa/faz5e/e2e-en-02-shop.png) |
| 3 Ürün | [görüntü](qa/faz5e/e2e-tr-03-product.png) | [görüntü](qa/faz5e/e2e-en-03-product.png) |
| 4 Sepet çekmecesi | [görüntü](qa/faz5e/e2e-tr-04-drawer.png) | [görüntü](qa/faz5e/e2e-en-04-drawer.png) |
| 5 Sepet | [görüntü](qa/faz5e/e2e-tr-05-sepet.png) | [görüntü](qa/faz5e/e2e-en-05-sepet.png) |
| 6 Ödeme | [görüntü](qa/faz5e/e2e-tr-06-odeme.png) | [görüntü](qa/faz5e/e2e-en-06-odeme.png) |
| 7 Sipariş alındı | [görüntü](qa/faz5e/e2e-tr-07-order-received.png) | [görüntü](qa/faz5e/e2e-en-07-order-received.png) |

Filtre çekmeceleri de ayrı kaydedildi: [TR](qa/faz5e/surface-tr-filter.png), [EN](qa/faz5e/surface-en-filter.png).

## Kabul durumu

- Public İngilizce URL sürekliliği ve sayfa bazlı link taraması: geçti.
- Sipariş alındı / iyzico dönüş yolu: İngilizce bağlam korundu.
- Dil bağımlı fragment/hash anahtarı, Referer olmayan AJAX ve EN → TR sepet geçişi: geçti.
- `languages.items_en`: yok; beklenmeyen çeviri ikizi: `0`.
- Türkçe ve İngilizce ticaret akışı: yedişer görüntüyle doğrulandı.
- Token disiplini, gölge ve vendor değişikliği: `0`.
