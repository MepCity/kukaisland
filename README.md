# Kuka Island — WooCommerce teknik pilotu

Bu depo Kuka Island üretim kod tabanıdır. WordPress çekirdeği, üçüncü taraf tema/eklentiler, medya ve veritabanı Docker volume'larında kalır; Git yalnız proje kodunu ve belgeleri taşır.

## Gereksinimler

- Docker Desktop 29+ ve Docker Compose 2.40+
- `openssl` (yerel geliştirme sırlarını üretmek için)
- Pilot medya kaynağı: varsayılan olarak `/Users/yasir.arslan/Desktop/kukaisland/public/images/demo`

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

## Sıfırdan kurulum

```sh
make reset
```

`reset`, yalnız doğrulanmış `kukaisland_canli` Compose projesinin iki Docker volume'unu kaldırır ve `make install` akışını yeniden çalıştırır. `.env` ve `seed-media/` korunur. Bu komut yerel WordPress/veritabanı verisini geri alınamaz biçimde siler.

## Dizinler

- `wp-content/themes/kuka-island-child/`: yalnız görsel sunum
- `wp-content/plugins/kuka-island-core/`: tema bağımsız veri/işlev iskeleti
- `scripts/`: kurulum, seed, pilot ve doğrulama
- `docs/`: kararlar, ölçümler ve Faz 3 aktarma planı
- `PLAN.md`: bu depodaki kanonik proje planı
- `seed-media/`: yerel ve Git dışı pilot medya

## Sırlar ve üçüncü taraf kodu

`.env.example` yalnız değişken adlarını ve açıklamalarını içerir. `.env`, uploads, veritabanı dökümleri, WordPress çekirdeği, Blocksy parent ve üçüncü taraf eklentileri Git'e alınmaz. iyzico API anahtarları yönetim ekranından ve yalnız sandbox anahtarları hazır olduğunda girilecektir.

