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
echo "OUT_OF_SCOPE_CUSTOM_FEATURES=$(rg -l 'wishlist|favorite|offcanvas-cart|size-modal|mega-menu|cut-index|card-stock' wp-content/themes/kuka-island-child wp-content/plugins/kuka-island-core --glob '!*.pot' | wc -l | tr -d ' ')"
echo "PANEL_HANDLER_FILES=$(rg -l 'let activePanel' wp-content/themes/kuka-island-child/assets/js/*.js | wc -l | tr -d ' ')"
echo "CART_FRAGMENT_FILTER=$(rg -l 'woocommerce_add_to_cart_fragments' wp-content/themes/kuka-island-child --glob '*.php' | wc -l | tr -d ' ')"
