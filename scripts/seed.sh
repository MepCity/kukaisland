#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"
./scripts/ensure-env.sh
set -a
. "$project_dir/.env"
set +a

docker compose run --rm wp-cli wp eval-file /project-scripts/seed-attributes.php
docker compose run --rm wp-cli wp eval-file /project-scripts/seed.php
docker compose run --rm wp-cli wp eval-file /project-scripts/seed-content.php

if docker compose run --rm wp-cli wp user get [removed-manager-user] >/dev/null 2>&1; then
  docker compose run --rm wp-cli wp user update [removed-manager-user] --role=shop_manager --user_pass="$WP_ADMIN_PASSWORD"
else
  docker compose run --rm wp-cli wp user create [removed-manager-user] manager@kukaisland.test --role=shop_manager --user_pass="$WP_ADMIN_PASSWORD"
fi
