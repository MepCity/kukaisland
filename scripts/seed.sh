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
docker compose run --rm wp-cli wp eval-file /project-scripts/migrate-sizes.php
docker compose run --rm wp-cli wp eval-file /project-scripts/seed-content.php

if docker compose run --rm wp-cli wp user get kuka_manager >/dev/null 2>&1; then
  printf '%s\n' "$WP_MANAGER_PASSWORD" | docker compose run --rm -T wp-cli wp user update kuka_manager --role=shop_manager --prompt=user_pass
else
  printf '%s\n' "$WP_MANAGER_PASSWORD" | docker compose run --rm -T wp-cli wp user create kuka_manager manager@kukaisland.test --role=shop_manager --prompt=user_pass
fi
