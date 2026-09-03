# AGENTS.md — Kuka Island

Bu dosya depo genelinde bağlayıcıdır. Daha alt dizindeki bir `AGENTS.md`, kendi
kapsamında buna ek kurallar getirebilir.

## Çalışmadan önce

1. `GECMIS.md` dosyasını okuyarak neyin neden yapıldığını öğren.
2. `PLAN.md` §38 karar defterini ve §39 güncel durumu oku.
3. Çalışacağın dizindeki en yakın `AGENTS.md` dosyasını ve işaret ettiği bakım
   belgelerini oku. EDM için sıra: `docs/EDM_BAKIM_HAFIZASI.md` →
   `docs/EDM_AKTIVASYON_REHBERI.md` → `docs/EDM_ENTEGRASYONU.md`.

## Genel kurallar

- Ölçmeden tamamlandı veya PASS deme; kaynak taraması, davranış testi,
  tarayıcı ölçümü ve gerçek dış servis sonucu farklı kanıtlardır.
- Mevcut kullanıcı değişikliklerini, vendor dosyalarını ve belgelenmiş koruma
  sözleşmelerini bozma. Özellikle `docs/KARGO_SCROLL_KORUMA_NOTU.md` bağlayıcıdır.
- Kimlik bilgisi, parola, secret key, session ID, tam belge UUID'si veya tam
  fatura numarasını repoya, loga ya da rapora yazma.
- `make reset` veya veri silen başka bir komutu açık kullanıcı izni olmadan
  çalıştırma.
- Commit mesajı kısa ve tek satır olsun; body, `Co-Authored-By` veya AI atfı
  ekleme. Kullanıcı ayrıca onaylamadan push yapma.
