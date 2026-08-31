# Kargo çekmecesi scroll koruma notu

## Sorun

WooCommerce 11.0.1, sipariş ekranındaki Fulfillments çekmecesinde iki iç içe
scroll konteyneri üretir:

- `.woocommerce-fulfillment-drawer__panel`: dış panel, gerçek taşan alan.
- `.woocommerce-fulfillment-drawer__body`: iç gövde; masaüstünde kendi scroll
  aralığı yoktur ancak `overflow-y:auto` ve `overscroll-behavior:none` taşır.

Chrome'da imleç içerik üzerindeyken wheel/trackpad olayı iç gövdede tutulur ve
kayabilen dış panele aktarılmaz. Sonuç olarak sağ scrollbar elle sürüklenebilir,
fakat normal tekerlek hareketi butonlara ulaştıramaz.

## Tek doğru yerel düzeltme

`wp-content/plugins/kuka-island-core/assets/admin-orders.css` içinde yalnız şu
kural korunur:

```css
.woocommerce_page_wc-orders .woocommerce-fulfillment-drawer__body {
	height: auto;
	overflow: visible;
	overscroll-behavior: auto;
}
```

Bu kural iç gövdeyi scroll konteyneri olmaktan çıkarır. WooCommerce'in dış
`.woocommerce-fulfillment-drawer__panel` elemanı tek dikey scroll alanı olur.

## Bu alanda yapılmaması gerekenler

- `[class*="fulfillment-drawer"]` gibi geniş seçiciler yazılmamalı.
- Drawer paneline veya gövdesine `overflow:hidden` verilmemeli.
- `html` veya `body` için drawer'a bağlı `overflow:hidden/clip` belge kilidi
  eklenmemeli.
- `position:fixed` body kilidi, MutationObserver tabanlı kilit veya
  `wheel/mousewheel/touchmove/keydown` yönlendirme JavaScript'i eklenmemeli.
- `admin-orders.js` adlı bir scroll telafi dosyası oluşturulmamalı veya enqueue
  edilmemeli.
- Bu CSS kuralı kaldırılmadan önce gerçek Chrome wheel testi ve
  `scripts/verify-order-experience.php` sözleşmesi birlikte güncellenmeli.

Bu alternatiflerin tamamı denendi; bazıları arka belgeyi kilitlerken panel
içindeki wheel olayını yine yuttu, bazıları sayfa konumunu sıfırladı veya başka
yönetim ekranlarını etkileme riski doğurdu.

## Kontrol yöntemi

1. `%100` tarayıcı ölçeğinde sipariş `#360` açılır.
2. `Kargo işlemlerini aç` seçilir.
3. İmleç scrollbar veya başlık üzerinde değil, kargo kartının ortasında tutulur.
4. Wheel/trackpad ile aşağı kaydırılır.
5. Dış panelin `scrollTop` değeri artmalı ve `Kargoya verildi olarak işaretle`
   butonu görünmelidir.
6. Aşağıdaki komut şu sözleşmeyi üretmelidir:

```text
DRAWER_SCROLL_CONTRACT=drawer_rules:1|safe_body_rules:1|document_locks:0|script:removed
```

Bu dosyalar aynı amaçla değiştirilmemelidir:

- `wp-content/plugins/kuka-island-core/assets/admin-orders.css`
- `wp-content/plugins/kuka-island-core/includes/class-fulfillments-language.php`
- `scripts/verify-order-experience.php`
- `scripts/verify.sh` içindeki `DRAWER_SCROLL_CONTRACT` beklentisi
