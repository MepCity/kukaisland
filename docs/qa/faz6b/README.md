# Faz 6B QA kanıtları

- `story-scenes-contact.png`: altı Türkçe sahnenin ikinci temiz kurulum sonrası son masaüstü render'ı.
- `story-before-after.png`: Faz 6A ilk sahne ile Faz 6B ilk sahne karşılaştırması.
- `story-tr-scene-01..06-1440.png` ve `story-en-scene-01..06-1440.png`: iki dilde sahne ekran görüntüleri.
- `story-tr-mobile-320.png` ve `story-tr-mobile-390.png`: tam sayfa mobil render'lar.
- `story-tr-mobile-enhanced-320.png`, `story-tr-mobile-enhanced-390.png` ve `story-en-mobile-enhanced-390.png`: scroll-led mobil final sahne render'ları.
- `story-panel-*.png`: Site Appearance sanat yönü alanları.
- `story-contrast-*.json`: render edilen her satırın medyan örnek kontrastı.
- `story-layout-seven-viewports.json`: yedi genişlikte yatay taşma ve progressive-enhancement sonucu.
- `story-opening-network.json`: açılış envanteri, sıkıştırılmış byte hesabı ve ilk-görsel eager kontrolü.
- `story-fallback-static.json`: güncel mekanik SHA-256 değeri; reduced-motion ve JavaScript-kapalı fallback kontrolleri.
- `reset-verify-1.txt` ve `reset-verify-2.txt`: birbirinden bağımsız iki temiz kurulumun PASS günlükleri; yerel test parolaları redakte edilmiştir.

Kontrast JSON'ları bir piksel-minimum WCAG ölçümü değildir. Her satır kutusunda yazı/glyph örnekleri ayıklandıktan sonra kalan arka plan örneklerinin medyanı ile metin rengi arasındaki oranı verir; dolayısıyla görsel regresyon kanıtıdır. İki dilde de eşik altı satır yoktur.
