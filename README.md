# Kuka Island — WooCommerce teknik pilotu

Bu depo Kuka Island üretim kod tabanıdır. WordPress çekirdeği, üçüncü taraf tema/eklentiler, medya ve veritabanı Docker volume'larında kalır; Git yalnız proje kodunu ve belgeleri taşır.

## Gereksinimler

- Docker Desktop 29+ ve Docker Compose 2.40+
- `openssl` (yerel geliştirme sırlarını üretmek için)
- Pilot medya kaynağı: varsayılan olarak `/Users/yasir.arslan/Desktop/kukaisland/public/images/demo`
- Composer 2 (yalnız PHPCS kalite araçları için)

Host PHP kullanılmaz; WP-CLI yalnız `wordpress:cli-php8.3` konteynerinde çalışır.

## Tek komut kurulum

```sh
make install
```

Komut ilk çalıştırmada Git tarafından yok sayılan `.env` dosyasını rastgele yerel parolalarla üretir, prototipteki 13 demo görselini salt okunur kaynaktan `seed-media/` dizinine kopyalar, konteynerleri açar ve şunları idempotent biçimde kurar:

- WordPress Türkçe
- WooCommerce
- Blocksy Free ve Blocksy Companion
- resmî iyzico WooCommerce eklentisi
- Kuka Island child theme ve Kuka Island Core
- global nitelikler, kategoriler, 4 variable pilot ürün ve 50 varyasyon
- HPOS, TRY, İstanbul saat dilimi, misafir checkout ve geçici Türkiye kargo bölgesi

Site: [http://localhost:8080](http://localhost:8080)

Başka konumdaki prototip kaynağı için:

```sh
KUKA_PROTOTYPE_DIR=/salt/okunur/kukaisland make install
```

## Günlük komutlar

```sh
make up
make down
make seed
make verify
make wp ARGS='plugin list'
make shell
```

`make verify`, global nitelik/terim, ürün/varyasyon, stok alanı, Blocksy renk galerisi, HPOS, para birimi ve görsel ayarı özetini verir.

```sh
make pot
./scripts/smoke.sh
composer install
composer phpcs
make deploy-package
```

`make verify` artık doğrulama ve beş storefront smoke akışı için açık `PASS/FAIL` üretir; hata halinde sıfır olmayan kodla çıkar. PHPStan eklenmedi: WordPress/WooCommerce'in dinamik hook ve global tipleri için ayrıca stub/baseline bakımı gerektireceğinden, bu yayın kapısında PHP syntax + WordPress PHPCS + gerçek kurulum/smoke testi daha düşük bakım maliyetli seçildi.

`make deploy-package`, yalnız proje sahipli child tema, Core eklenti, ayrı ve
varsayılan pasif EDM eklentisi ile gerekli kurulum/bakım rehberlerini
`dist-deploy/` altında checksum'lı arşivler. Çıktı Git dışıdır; komut fiili
deploy veya eklenti aktivasyonu yapmaz.

## Sıfırdan kurulum

```sh
make reset
```

`reset`, yalnız doğrulanmış `kukaisland_canli` Compose projesinin iki Docker volume'unu kaldırır ve `make install` akışını yeniden çalıştırır. `.env` ve `seed-media/` korunur. Bu komut yerel WordPress/veritabanı verisini geri alınamaz biçimde siler.

## Dizinler

- `wp-content/themes/kuka-island-child/`: yalnız görsel sunum
- `wp-content/plugins/kuka-island-core/`: tema bağımsız veri/işlev iskeleti
- `wp-content/plugins/kuka-island-edm/`: ayrı, varsayılan pasif EDM entegrasyonu
- `scripts/`: kurulum, seed, pilot ve doğrulama
- `docs/`: kararlar, ölçümler ve Faz 3 aktarma planı
- `PLAN.md`: bu depodaki kanonik proje planı
- `seed-media/`: yerel ve Git dışı pilot medya
- `app-reference/`: prototip CSS'inden Faz 3 sadakat denetimi için alınmış, üretilmiş/salt okunur stil referansı
- `data-reference/`: prototip içerik ve katalog sözleşmelerinin üretilmiş/salt okunur kopyası
- `lib-reference/`: prototip DOM etkileşimlerinin üretilmiş/salt okunur davranış referansı

Referans dizinleri çalışma zamanında kullanılmaz ve elle düzenlenmez. Prototipteki kaynak değişirse aynı dosyalar yeniden kopyalanarak güncellenir; medya ise `scripts/prepare-media.sh` ile `KUKA_PROTOTYPE_DIR/public/images/demo` kaynağından hazırlanır.

## Proje hafızası ve okuma sırası

Bakım veya geliştirme öncesinde önce `GECMIS.md`, ardından `PLAN.md` §38–§39
okunur. Sonra çalışılacak dizindeki en yakın `AGENTS.md` ve ilgili bakım
belgeleri izlenir. EDM işi için zorunlu sıra:
`docs/EDM_BAKIM_HAFIZASI.md` → `docs/EDM_AKTIVASYON_REHBERI.md` →
`docs/EDM_ENTEGRASYONU.md`. Kök `AGENTS.md` depo genelindeki bağlayıcı
kuralları ve bu okuma sırasını tanımlar.

## Sırlar ve üçüncü taraf kodu

`.env.example` yalnız değişken adlarını ve açıklamalarını içerir. Yerel yönetici ve Shop Manager kullanıcı adları ile birbirinden farklı parolaları ilk kurulumda rastgele üretilip yalnız `.env` içinde tutulur. `.env`, uploads, veritabanı dökümleri, WordPress çekirdeği, Blocksy parent ve üçüncü taraf eklentileri Git'e alınmaz. iyzico API anahtarları yönetim ekranından ve yalnız sandbox anahtarları hazır olduğunda girilecektir.

## Sorun giderme

- Port doluysa `.env` içindeki `WP_PORT` ve `WP_URL` birlikte değiştirilir, sonra `docker compose up -d --wait` çalıştırılır.
- Yerel zamanlanmış işler `wp-cron` servisi tarafından dakikada bir WP-CLI üzerinden çalıştırılır; durum için `docker compose ps wp-cron`, hata kaydı için `docker compose logs wp-cron` kullanılır.
- Sağlıksız konteynerde `docker compose ps` ve `docker compose logs wordpress db` incelenir; debug log üretimde açılmaz.
- Seed medya bulunamazsa `KUKA_PROTOTYPE_DIR=/mutlak/prototip/yolu make install` kullanılır.
- Tema değişikliği görünmüyorsa `make wp ARGS='cache flush'` ve tarayıcı hard refresh uygulanır.
- Kurulum yarıda kaldıysa yerel verinin silineceği bilinerek `make reset` kullanılır; üretimde bu komut asla çalıştırılmaz.
- Hosting aktarımı için [deploy runbook](docs/DEPLOY_RUNBOOK.md) izlenir.
