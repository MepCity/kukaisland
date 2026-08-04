#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
env_file="$project_dir/.env"

if [ -f "$env_file" ]; then
  exit 0
fi

db_password=$(openssl rand -hex 18)
db_root_password=$(openssl rand -hex 18)
admin_password=$(openssl rand -hex 18)

umask 077
sed \
  -e 's/^COMPOSE_PROJECT_NAME=.*/COMPOSE_PROJECT_NAME=kukaisland_canli/' \
  -e 's/^WP_PORT=.*/WP_PORT=8080/' \
  -e 's/^DB_NAME=.*/DB_NAME=kuka_island/' \
  -e 's/^DB_USER=.*/DB_USER=kuka/' \
  -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$db_password/" \
  -e "s/^DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=$db_root_password/" \
  -e 's|^WP_URL=.*|WP_URL=http://localhost:8080/|' \
  -e 's/^WP_TITLE=.*/WP_TITLE="Kuka Island"/' \
  -e 's/^WP_ADMIN_USER=.*/WP_ADMIN_USER=[removed-admin-user]/' \
  -e "s/^WP_ADMIN_PASSWORD=.*/WP_ADMIN_PASSWORD=$admin_password/" \
  -e 's/^WP_ADMIN_EMAIL=.*/WP_ADMIN_EMAIL=admin@kukaisland.test/' \
  "$project_dir/.env.example" > "$env_file"

echo "Yerel .env üretildi; Git tarafından yok sayılıyor."
