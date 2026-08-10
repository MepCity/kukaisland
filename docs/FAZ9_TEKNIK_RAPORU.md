# Faz 9 — Sipariş e-postası dayanıklılık raporu

## Sonuç

10 Ağustos 2026 yerel kabulünde Apache PHP sürecinde `mail()` gerçekten kapatıldı. Test siparişi #93 oluştu, `Beklemede` durumuna geçti ve tarayıcı `Sipariş alındı` sayfasında 93 numarasını gösterdi. İki WooCommerce iletisi gönderilemedi; Core ikisini de yakalayıp iki sipariş notu ve Başlangıç ekranında `2 gönderilemeyen sipariş e-postası var` uyarısı oluşturdu. Test verisi sonraki temiz reset ile silindi.

## Makine ölçümleri

| Ölçüm | Sonuç |
|---|---|
| PHP süreci | `function_exists('mail') = false` |
| Kapalı `mail()` güvenli dönüşü | 1/1 |
| Zorlanmış `Exception` / `Error` | 2/2 yakalandı |
| Zorlanmış Throwable sipariş notları | 2/2 |
| PHPMailer taşıyıcısı | `smtp` |
| Gönderen / ayrı Reply-To | fixed / separate |
| SMTP sabiti adı içeren DB satırı | 0 |
| SMTP gizlisini çıktı hedefine bağlayan kaynak satırı | 0 |
| WooCommerce yeniden gönderme yüzeyleri | müşteri + yönetici, 2/2 |
| Temiz kurulum doğrulaması | `make reset && make verify` 2/2 PASS |
| Smoke | iki turda 5/5 + 5/5 PASS |
| Vendor/çekirdek değişikliği | 0 |

Gerçek SMTP hesabı sağlanmadığı için dış posta kutusuna teslim, SPF/DKIM ve canlı başarı sonucu ölçülmedi. Panelin yeşil başarı durumu, yalnız UI dalını doğrulayan geçici yerel kısa-devre ile görüntülendi; SMTP nesne yapılandırması ayrıca bağlantı kurmadan ölçüldü ve bu görsel gerçek teslimat kanıtı sayılmadı.

## Görsel kanıtlar

- [Kapalı mail() Başlangıç uyarısı](qa/faz9/01-mail-disabled-warning.png)
- [Test e-postası okunur hata sonucu](qa/faz9/02-test-email-error.png)
- [Test e-postası başarı UI dalı — simüle](qa/faz9/03-test-email-success-simulated.png)
- [Kapalı mail() ile Sipariş alındı ve sipariş #93](qa/faz9/04-mail-disabled-order-received.png)
- [Sipariş notları](qa/faz9/05-order-note-and-resend-actions.png)
- [WooCommerce yerleşik yeniden gönderme seçimi](qa/faz9/06-native-resend-actions.png)
- [Başlangıç gönderilemeyen e-posta sayacı](qa/faz9/07-failed-email-dashboard-warning.png)

## Canlı ve cron sınırı

Canlı sipariş #87'nin durumuna erişim sağlanmadı; hata logu siparişin varlığını gösterse de mevcut durum veya müşteriye teslim bilgisini kanıtlamaz. Bu kriter açık olarak `docs/BILINEN_SINIRLAMALAR.md` içinde tutuldu.

Yerelde `action_scheduler_run_queue` olayı `every_minute=60`, `wp_1_wc_regenerate_images_cron` olayı kendi `300` saniyelik schedule kaydıyla eşleşti. Canlıdaki `invalid_schedule` yerelde yeniden üretilemediği için cron kaydı tahminle silinmedi veya yeniden kurulmadı.
