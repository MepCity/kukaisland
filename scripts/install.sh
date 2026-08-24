#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"
./scripts/ensure-env.sh
set -a
. "$project_dir/.env"
set +a

./scripts/prepare-media.sh
if [ ! -f "$project_dir/seed-media/noir-asymmetric-top.jpg" ]; then
  echo "Hata: seed-media bulunamadı. README medya hazırlama bölümüne bakın." >&2
  exit 1
fi

docker compose up -d --wait db wordpress

if ! docker compose run --rm wp-cli wp core is-installed >/dev/null 2>&1; then
  if ! printf '%s\n' "$WP_ADMIN_PASSWORD" | docker compose run --rm -T wp-cli wp core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --locale=tr_TR \
    --prompt=admin_password \
    --skip-email >/dev/null 2>&1; then
    echo "Hata: WordPress yönetici hesabı güvenli biçimde kurulamadı." >&2
    exit 1
  fi
fi

# Güvenlik sürümü ana imajın taşıdığı çekirdekten bağımsız olarak sabitlenir.
docker compose run --rm wp-cli wp core update --version=7.0.4 --force
docker compose run --rm wp-cli wp core update-db
docker compose run --rm wp-cli wp language core install tr_TR --activate
docker compose run --rm wp-cli wp plugin install woocommerce --version=11.0.1 --activate --force
docker compose run --rm wp-cli wp plugin install blocksy-companion --version=2.1.53 --activate --force
docker compose run --rm wp-cli wp plugin install iyzico-woocommerce --version=3.5.28 --activate --force
docker compose run --rm wp-cli wp plugin install loginizer --version=2.1.0 --activate --force
docker compose run --rm wp-cli wp language plugin install woocommerce tr_TR
docker compose run --rm wp-cli wp theme install blocksy --version=2.1.53 --force
docker compose run --rm wp-cli wp theme activate kuka-island-child
docker compose run --rm wp-cli wp plugin activate kuka-island-core
# Üyelik kaldırıldı; sosyal giriş eklentisi artık kurulmaz ve önceki bir
# kurulumdan kalmışsa silinir.
docker compose run --rm wp-cli wp plugin delete nextend-facebook-connect >/dev/null 2>&1 || true
./scripts/seed.sh

echo "Kurulum hazır: $WP_URL"
