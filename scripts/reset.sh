#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"
./scripts/ensure-env.sh

project_name=$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' .env)
case "$project_name" in
  kukaisland_canli) ;;
  *) echo "Güvenlik nedeniyle beklenmeyen proje silinmedi: $project_name" >&2; exit 1 ;;
esac

docker compose down --volumes --remove-orphans
./scripts/install.sh

