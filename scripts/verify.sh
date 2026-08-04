#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"
./scripts/ensure-env.sh

docker compose run --rm wp-cli wp eval-file /project-scripts/verify.php

override_count=$(find wp-content/themes/kuka-island-child/woocommerce -type f ! -name '.gitkeep' | wc -l | tr -d ' ')
echo "WOOCOMMERCE_OVERRIDES=$override_count"
echo "THEME_POT=$(test -s wp-content/themes/kuka-island-child/languages/kuka-island.pot && echo yes || echo no)"
echo "PLUGIN_POT=$(test -s wp-content/plugins/kuka-island-core/languages/kuka-island-core.pot && echo yes || echo no)"
echo "PRO_CUSTOM_FEATURES=$(rg -l 'wishlist|favorite|kuka-swatch|offcanvas-cart|filter-drawer|size-modal' wp-content/themes/kuka-island-child wp-content/plugins/kuka-island-core --glob '!*.pot' --glob '!class-swatch-meta.php' --glob '!class-plugin.php' | wc -l | tr -d ' ')"
