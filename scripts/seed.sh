#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"
./scripts/ensure-env.sh

docker compose run --rm wp-cli wp eval-file /project-scripts/seed-attributes.php
docker compose run --rm wp-cli wp eval-file /project-scripts/seed.php
