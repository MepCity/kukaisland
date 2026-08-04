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
echo "PANEL_HANDLER_FILES=$(rg -l 'let activePanel' wp-content/themes/kuka-island-child/assets/js/*.js | wc -l | tr -d ' ')"
echo "PRODUCT_LIGHTBOX_DUPLICATE_HANDLER=$(rg -l 'inert|event.key === \"Tab\"|event.key === \"Escape\"' wp-content/themes/kuka-island-child/assets/js/product.js | wc -l | tr -d ' ')"
echo "CART_FRAGMENT_FILTER=$(rg -l 'woocommerce_add_to_cart_fragments' wp-content/themes/kuka-island-child --glob '*.php' | wc -l | tr -d ' ')"
echo "ROOT_OVERFLOW_MASK=$(rg -n 'overflow-x:\s*(hidden|clip)' wp-content/themes/kuka-island-child/assets/css/global.css | wc -l | tr -d ' ')"
echo "CSS_RAW_COLORS_OUTSIDE_TOKENS=$(rg -n '#[0-9a-fA-F]{3,8}|rgba?\(' wp-content/themes/kuka-island-child/assets/css --glob '!tokens.css' | wc -l | tr -d ' ')"
echo "CSS_SHADOWS=$(rg -n 'box-shadow|drop-shadow' wp-content/themes/kuka-island-child/assets/css | wc -l | tr -d ' ')"
echo "LOCKED_DESIGN_CONTROLS=$(rg -n "'font_(family|size)'|'grid_columns'|'breakpoint'|'animation_duration'|'product_card_ratio'" wp-content/plugins/kuka-island-core/includes/class-site-appearance.php | wc -l | tr -d ' ')"
