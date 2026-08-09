# Kuka Island panel haritası

Ölçüm tarihi: 9 Ağustos 2026. Kaynak: `Kuka_Island_Core_Site_Appearance::field_inventory()` ve ilgili WordPress/WooCommerce kayıt ekranları.

## Ölçüm özeti

- Site Görünümü: **13 sekme, 105 görünen alan satırı, 146 saklanan alan kontrolü**. Fark, 41 Türkçe alanın aynı satırdaki `(EN)` karşılığıdır.
- Marka Hikâyesi `scenes` alanı tek sözleşme alanıdır; her sahne kendi içinde TR/EN metin, dört medya, iki metin tonu ve üç sanat yönü kontrolü taşır.
- Ürün: 7 ortak Kuka alanı + 8 İngilizce karşılık + ürün adı/açıklama/kısa açıklama dil çiftleri. Fiyat, stok, SKU, varyasyon ve görseller ortaktır.
- Sayfa: başlık ve içerik için TR/EN çifti. Taksonomi terimi: ad için TR/EN çifti.

## Site Görünümü envanteri

Tipler: `text` tek satır, `textarea` çok satır, `checkbox` anahtar, `number` sayısal, `email` e-posta, `url` güvenli iç/dış URL, `media_*` Medya Kütüphanesi, `lines/link_lines/url_lines` satır listesi, diğerleri izinli seçenek listesidir.

| Sekme | Sitede etkilediği yer | Alanlar (`anahtar: tip`) |
|---|---|---|
| 1. Marka | Header/footer marka, iletişim, WhatsApp, favicon ve OG görseli | `logo_id: media_image`; `mobile_logo_id: media_image`; `emblem_id: media_image`; `favicon_id: media_image`; `social_share_image_id: media_image`; `email: email`; `phone: text`; `whatsapp_phone: text`; `social_links: link_lines` |
| 2. Duyuru Bandı | Tüm sayfaların üst duyuru satırı | `enabled: checkbox`; `items: lines`; `items_en: lines`; `link_labels: lines`; `link_labels_en: lines`; `link_urls: url_lines` |
| 3. Dil Seçici | Header dil seçici ve bekleyen dil notu | `items: link_lines`; `pending_urls: text`; `pending_note: text` |
| 4. Ana Hero | Ana sayfanın ilk ekranı | `enabled: checkbox`; `desktop_image_id: media_image`; `mobile_image_id: media_image`; `eyebrow: text`; `eyebrow_en: text`; `title: text`; `title_en: text`; `copy: textarea`; `copy_en: textarea`; `button_label: text`; `button_label_en: text`; `button_url: url`; `alignment: alignment`; `text_tone: tone` |
| 5. Ana Sayfa Bölümleri | Kesim indeksi, yeni gelenler, editoryal, manifesto ve servis şeridi | `category_index_enabled: checkbox`; `category_index_label: text`; `category_index_label_en: text`; `category_index_title: text`; `category_index_title_en: text`; `new_arrivals_enabled: checkbox`; `new_arrivals_title: text`; `new_arrivals_title_en: text`; `new_arrivals_copy: textarea`; `new_arrivals_copy_en: textarea`; `new_arrivals_source: product_source`; `source_category: slug`; `source_collection: slug`; `manual_product_ids: ids`; `presentation: presentation`; `card_swatches_enabled: checkbox`; `card_stock_enabled: checkbox`; `editorial_enabled: checkbox`; `editorial_title: text`; `editorial_title_en: text`; `editorial_copy: textarea`; `editorial_copy_en: textarea`; `editorial_image_id: media_image`; `editorial_video_id: media_video`; `editorial_url: url`; `editorial_link_label: text`; `editorial_link_label_en: text`; `manifesto_enabled: checkbox`; `manifesto_line_1: text`; `manifesto_line_1_en: text`; `manifesto_line_2: text`; `manifesto_line_2_en: text`; `services_enabled: checkbox`; `service_1_title: text`; `service_1_title_en: text`; `service_1_copy: text`; `service_1_copy_en: text`; `service_1_url: url`; `service_2_title: text`; `service_2_title_en: text`; `service_2_copy: text`; `service_2_copy_en: text`; `service_2_url: url`; `service_3_title: text`; `service_3_title_en: text`; `service_3_copy: text`; `service_3_copy_en: text`; `service_3_url: url` |
| 6. Marka Hikâyesi | Hakkımızda sayfasının kaydırmalı sahneleri | `scenes: story_scenes` |
| 7. Navigasyon | Header, ana sayfa kategori indeksi ve yardım menüsü | `main: link_lines`; `main_labels_en: lines`; `categories: category_navigation`; `categories_labels_en: lines`; `help: link_lines`; `help_labels_en: lines` |
| 8. Footer | Bülten alanı, yardım/yasal linkler | `newsletter_enabled: checkbox`; `newsletter_eyebrow: text`; `newsletter_eyebrow_en: text`; `newsletter_title: text`; `newsletter_title_en: text`; `newsletter_copy: textarea`; `newsletter_copy_en: textarea`; `newsletter_consent: textarea`; `newsletter_consent_en: textarea`; `newsletter_notification_email: email`; `help_links: link_lines`; `help_links_labels_en: lines`; `legal_links: link_lines`; `legal_links_labels_en: lines` |
| 9. Ticari Bilgiler | Duyuru, sepet, ödeme, kargo/iade ve yasal kısa kodlar | `free_shipping_threshold: number`; `ignore_discounts: shipping_discount_basis`; `flat_shipping_fee: number`; `shipping_carrier: text`; `delivery_time: text`; `delivery_time_en: text`; `cayma_hakki_gun: number`; `return_shipping_responsibility: text`; `return_shipping_responsibility_en: text`; `shipping_copy: textarea`; `shipping_copy_en: textarea`; `free_shipping_remaining_copy: textarea`; `free_shipping_remaining_copy_en: textarea`; `free_shipping_ready_copy: textarea`; `free_shipping_ready_copy_en: textarea`; `flat_rate_copy: textarea`; `flat_rate_copy_en: textarea`; `hygiene_copy: textarea`; `hygiene_copy_en: textarea`; `hygiene_defect_copy: textarea`; `hygiene_defect_copy_en: textarea`; `hygiene_try_on_copy: textarea`; `hygiene_try_on_copy_en: textarea`; `secure_payment_copy: textarea`; `secure_payment_copy_en: textarea`; `support_hours: text`; `support_hours_en: text` |
| 10. Şirket ve Yasal Bilgiler | Footer, iletişim ve sekiz yasal sayfanın şirket bloğu | `company_title: text`; `brand_name: text`; `tax_number: text`; `tax_office: text`; `address_full: textarea`; `address_short: text`; `telephone: text`; `mersis_number: text`; `etbis_number: text` |
| 11. Ödeme Formu Alanları | Checkout alan zorunlulukları | `require_phone: checkbox`; `require_company: checkbox`; `require_address_2: checkbox`; `require_city: checkbox` |
| 12. Beden Rehberi Verileri | Beden Rehberi sayfasındaki üç tablo | `size_top_rows: size_rows`; `size_bottom_rows: size_rows`; `size_swimsuit_rows: size_rows` |
| 13. Üyelik | Misafir ödeme ve sepet oturumu | `enabled: checkbox`; `guest_session_hours: number` |

## Site Görünümü dışından yönetilen yüzeyler

| Yüzey | Yönetim yeri | Not |
|---|---|---|
| Ürün adı, açıklamalar, kumaş/bakım/kalıp/model/SEO | Ürün düzenleme | TR ve `(EN)` aynı ekranda; fiyat/stok/SKU/görseller ortaktır. |
| Fiyat, stok, SKU, varyasyon, ürün görselleri | Ürün düzenleme → Ürün verisi / Görseller | WooCommerce tek kaynaktır. |
| Kategori adı ve sırası | Ürünler → Kategoriler | Ad ve `Ad (EN)` aynı formdadır. |
| Renk, beden, kesim terimleri | Ürünler → Nitelikler → Terimleri yapılandır | Ad ve `Ad (EN)` aynı formdadır; renk teriminde swatch rengi de bulunur. |
| Sayfa başlığı ve gövdesi | Sayfalar → ilgili sayfa | TR ve `(EN)` aynı ekrandadır. |
| Kargo bölgeleri/yöntemleri | WooCommerce → Ayarlar → Gönderim | Bölge ve yöntem WooCommerce'te; tutar/eşik Site Görünümü'nden senkronlanır. |
| Vergi, ödeme yöntemi, kupon | WooCommerce ayarları / Pazarlama | Ödeme matematiği ve kupon motoru yeniden yazılmaz. |
| Siparişler ve müşteriler | WooCommerce → Siparişler / Analiz | HPOS tek kaynaktır. |
| Menü iskeleti | Görünüm/Menüler + Site Görünümü Navigasyon | Header sabit sayfa bağlantıları paneldedir; WordPress menüsü temel kayıt ve yedek kaynaktır. |
| Bülten kayıtları | Kuka Island → Bülten Kayıtları | Kayıt/izin kanıtı ve CSV; toplu gönderim yoktur. |
| Yayın görünürlüğü | WooCommerce → Ayarlar → Site görünürlüğü | Gerçek lansmana kadar tüm site `Çok yakında`; “yalnızca mağaza sayfaları” kapalıdır. Özel önizleme anahtarı test içindir. |
| Arama motoru görünürlüğü | Ayarlar → Okuma | Gerçek lansmana kadar `blog_public=0` / noindex. |

## Çakışma denetimi

- Ücretsiz kargo eşiği ve sabit kargo ücreti iki ekranda görünse de **Site Görünümü tek yazma kaynağıdır**; kayıtta WooCommerce yöntem seçeneklerine senkronlanır.
- Header/footer yardım ve yasal linkleri Site Görünümü'nden gelir. WordPress menü kayıtları bu vitrinde okunmaz; operatör Yönetim Haritası'ndan doğru kaynağa yönlendirilir.
- Ürün ve sayfalarda eski ayrı “English … content” kutuları kaldırıldı. Türkçe kaynak ile `(EN)` aynı formdadır; ikinci yazma yolu yoktur.
- Ürün fiyatı, stok, SKU, varyasyon ve görsel için dil ikizi yoktur; tek ürün kaydı kullanılır.

## Yönetilemeyen ve bilinçli kilitli değerler

Koda gömülü, operatörün değiştirmesi gereken açık içerik boşluğu bulunmadı. Aşağıdakiler bilinçli tasarım/iş kuralı kilididir ve panelde yoktur: font ailesi/ölçüsü, renk paleti, grid sütunu, breakpoint, animasyon süresi, ürün kartı oranı, galeri davranışı, rastgele HTML/JS/CSS, görsel dönüşüm boyutları ve hero/temel sayfa iskelet sırası. Bunlar içerik değil tasarım sözleşmesidir.

## Güvenlik ölçümü

Site Görünümü kaydı `manage_woocommerce` + nonce kullanır; ürün/sayfa/terim/swatch ve bülten dışa aktarma yollarında ilgili yetki + nonce vardır. URL/e-posta/sayı/medya/slug/link satırı alanları tipe göre temizlenir. `upload_mimes` filtresi yoktur; SVG yükleme açılmamıştır.
