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
  docker compose run --rm wp-cli wp core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --locale=tr_TR \
    --skip-email
fi

docker compose run --rm wp-cli wp language core install tr_TR --activate
docker compose run --rm wp-cli wp plugin install woocommerce --version=11.0.0 --activate
docker compose run --rm wp-cli wp plugin install blocksy-companion --version=2.1.51 --activate
docker compose run --rm wp-cli wp plugin install iyzico-woocommerce --version=3.5.28 --activate
docker compose run --rm wp-cli wp language plugin install woocommerce tr_TR
docker compose run --rm wp-cli wp theme install blocksy --version=2.1.51
docker compose run --rm wp-cli wp theme activate kuka-island-child
docker compose run --rm wp-cli wp plugin activate kuka-island-core
./scripts/seed.sh

echo "Kurulum hazır: $WP_URL"
