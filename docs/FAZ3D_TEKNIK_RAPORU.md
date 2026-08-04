# Faz 3D Teknik Teslim Raporu

## Erişim

- Site: `http://localhost:8080/`
- Yönetim: `http://localhost:8080/wp-admin/`
- Yönetici: `[removed-admin-user]`
- Günlük Shop Manager: `[removed-manager-user]`
- Her iki yerel hesabın şifresi: `a811078f429846823ab46ba75aee266fcc5f`
- Site Görünümü: yönetimde **Kuka Island → Site Görünümü** (`/wp-admin/admin.php?page=kuka-island`)

Bu bilgi yalnız yerel geliştirme ortamı içindir; `.env.example` içine yazılmamıştır.

## Önerilen beş deneme

1. Duyuru bandının ilk satırını değiştirip kaydedin.
2. Hero başlığını değiştirip ana sayfayı yenileyin.
3. Bir varyasyonun stok adedini sıfıra indirip karttaki bedenin üstü çizildiğini görün.
4. “Kart beden/stok satırını göster” anahtarını kapatıp kartı kontrol edin.
5. Ücretsiz kargo eşiğini değiştirip sepet çekmecesi mesajını kontrol edin.

## Kabul özeti

- Sadakat denetimi: **24 sapma bulundu, 24'ü düzeltildi**.
- Site Appearance: **8 grup, tarayıcıda 72 görünür alan**, nonce mevcut, Shop Manager erişimi doğrulandı.
- Gutenberg: **2/2 kilitli desen** kayıtlı.
- Responsive: 6 genişlik × 5 rota = **30/30 yatay taşma 0 px**.
- Ortak panel: handler dosyası **1**; `product.js` ikinci Tab/Escape/inert uygulaması **0**.
- Tasarım harness: token dışı ham renk **0**, gölge **0**, kök overflow maskesi **0**, kilitli tasarım kontrolü **0**.
- Override bütçesi: WooCommerce child override **2**; Blocksy parent, WooCommerce ve iyzico vendor dosyası değişikliği **0**.
- Temiz kurulum: `make reset && make verify` arka arkaya **2/2 başarılı**; son ortam ayakta bırakıldı.

## §15.3 kilit denetimi

| Panelden açılmayan tasarım yetkisi | Sonuç |
|---|---|
| Font ailesi | Yok |
| Font ölçüleri / tipografi ölçeği | Yok |
| Rastgele renk veya serbest palet | Yok |
| Grid sayıları / ölçüleri | Yok |
| Breakpoint değerleri | Yok |
| Animasyon süreleri / easing | Yok |
| Galeri JavaScript davranışı | Yok |
| Rastgele HTML ekleme | Yok |
| Tema dosyası düzenleme | Yok |
| WooCommerce görsel boyutları | Yok |
| Ürün kartı oranı | Yok |
| Hero ve ana sayfa iskelet sırası | Yok |
| Serbest/desen dışı düzen blokları | Kilitli Kuka desenlerinde yok |

## Yapılmayanlar

- iyzico gerçek sandbox/3D tahsilat testi: sandbox anahtarları teslim edilmedi.
- Safari, Firefox, iOS Safari ve Android Chrome gerçek cihaz turu: mevcut ortam yalnız Chromium tabanlı uygulama tarayıcısı sağlıyor.
- Gerçek yedi fotoğraflı ürün turu: müşteri medya seti henüz teslim edilmedi.
- Yasal metinlerin yürürlük onayı: müşteri/hukuk sorumluluğunda.
