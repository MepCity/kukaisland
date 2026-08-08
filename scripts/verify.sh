#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"
./scripts/ensure-env.sh

snapshot=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify.php)
printf '%s\n' "$snapshot"

search_count() {
  pattern=$1
  shift
  if command -v rg >/dev/null 2>&1; then
    rg -n "$pattern" "$@" 2>/dev/null | wc -l | tr -d ' '
  else
    grep -ERn --include='*.php' --include='*.js' --include='*.css' "$pattern" "$@" 2>/dev/null | wc -l | tr -d ' '
  fi
}

override_count=$(find wp-content/themes/kuka-island-child/woocommerce -type f ! -name '.gitkeep' | wc -l | tr -d ' ')
theme_pot=$(test -s wp-content/themes/kuka-island-child/languages/kuka-island.pot && echo yes || echo no)
plugin_pot=$(test -s wp-content/plugins/kuka-island-core/languages/kuka-island-core.pot && echo yes || echo no)
panel_handlers=$(search_count 'let activePanel' wp-content/themes/kuka-island-child/assets/js)
duplicate_lightbox=$(search_count 'inert|event.key === "Tab"|event.key === "Escape"' wp-content/themes/kuka-island-child/assets/js/product.js)
cart_fragments=$(search_count 'woocommerce_add_to_cart_fragments' wp-content/themes/kuka-island-child)
overflow_masks=$(search_count 'overflow-x:[[:space:]]*(hidden|clip)' wp-content/themes/kuka-island-child/assets/css/global.css)
# Token disiplini bildirimler üzerinde ölçülür: yorum blokları ayıklanır,
# böylece bir kuralın neden yazıldığını anlatan açıklamadaki "760px" ya da
# "#2872fa" gibi alıntılar ihlal sayılmaz. Eşik yine 0'dır.
css_declarations=$(mktemp)
find wp-content/themes/kuka-island-child/assets/css -name '*.css' ! -name tokens.css -exec awk '
  {
    line = $0
    out = ""
    while (1) {
      if (in_comment) {
        i = index(line, "*/")
        if (i == 0) { line = ""; break }
        in_comment = 0
        line = substr(line, i + 2)
      } else {
        i = index(line, "/*")
        if (i == 0) { out = out line; break }
        out = out substr(line, 1, i - 1)
        in_comment = 1
        line = substr(line, i + 2)
      }
    }
    print out
  }
' {} + > "$css_declarations"
raw_colors=$(grep -En '#[0-9a-fA-F]{3,8}|rgba?\(' "$css_declarations" 2>/dev/null | wc -l | tr -d ' ')
raw_px=$(grep -Ev '^[[:space:]]*@media' "$css_declarations" | grep -En '[0-9]+(\.[0-9]+)?px' 2>/dev/null | wc -l | tr -d ' ')
rm "$css_declarations"
shadows=$(search_count 'box-shadow|drop-shadow' wp-content/themes/kuka-island-child/assets/css)
locked_controls=$(search_count "'font_(family|size)'|'grid_columns'|'breakpoint'|'animation_duration'|'product_card_ratio'" wp-content/plugins/kuka-island-core/includes/class-site-appearance.php)
used_tokens=$(mktemp)
defined_tokens=$(mktemp)
grep -hoE 'var\(--[a-z0-9-]+' wp-content/themes/kuka-island-child/assets/css/*.css | sed 's/var(--//' | sort -u > "$used_tokens"
grep -hoE -- '--[a-z0-9-]+[[:space:]]*:' wp-content/themes/kuka-island-child/assets/css/tokens.css | sed -E 's/^--//;s/[[:space:]]*:.*$//' | sort -u > "$defined_tokens"
undefined_tokens=$(comm -23 "$used_tokens" "$defined_tokens" | grep -Ev '^(hero-desktop|hero-mobile|swatch-color|zoom-scale|zoom-x|zoom-y)$' | wc -l | tr -d ' ')
rm "$used_tokens" "$defined_tokens"
newsletter_mail_calls=$(search_count 'wp_mail[[:space:]]*\(' wp-content/plugins/kuka-island-core/includes/class-newsletter.php)

cat <<EOF
WOOCOMMERCE_OVERRIDES=$override_count
THEME_POT=$theme_pot
PLUGIN_POT=$plugin_pot
PANEL_HANDLER_FILES=$panel_handlers
PRODUCT_LIGHTBOX_DUPLICATE_HANDLER=$duplicate_lightbox
CART_FRAGMENT_FILTER=$cart_fragments
ROOT_OVERFLOW_MASK=$overflow_masks
CSS_RAW_COLORS_OUTSIDE_TOKENS=$raw_colors
CSS_RAW_PX_OUTSIDE_TOKENS=$raw_px
CSS_SHADOWS=$shadows
LOCKED_DESIGN_CONTROLS=$locked_controls
CSS_UNDEFINED_TOKENS=$undefined_tokens
NEWSLETTER_WP_MAIL_CALLS=$newsletter_mail_calls
EOF

failures=0
expect_line() {
  label=$1
  line=$2
  if printf '%s\n' "$snapshot" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}
expect_value() {
  label=$1
  actual=$2
  expected=$3
  if [ "$actual" = "$expected" ]; then
    echo "PASS $label"
  else
    echo "FAIL $label ($actual != $expected)" >&2
    failures=$((failures + 1))
  fi
}

expect_line "child theme active" "ACTIVE_THEME=kuka-island-child"
expect_line "six-item header menu" "PRIMARY_MENU_COUNT=6"
expect_line "daily manager" "DAILY_MANAGER=yes"
expect_line "classic checkout" "CHECKOUT_CLASSIC=yes"
expect_line "no legal draft warnings left" "LEGAL_DRAFT_WARNINGS=0"
expect_line "central legal company data" "LEGAL_CENTRAL_COMPANY=8/8"
expect_line "42 bilingual appearance fields" "LANGUAGE_TRANSLATABLE_FIELDS=42"
expect_line "five English product fields" "PRODUCT_EN_FIELD_SCHEMA=5"
expect_line "two English page fields" "PAGE_EN_FIELD_SCHEMA=2"
expect_line "English taxonomy field" "TAXONOMY_EN_FIELD=_kuka_name_en"
expect_line "no translation plugin" "TRANSLATION_PLUGIN=none"
expect_line "English language no longer pending" "LANGUAGE_PENDING_URLS="
expect_line "legal English seed remains empty" "LEGAL_EN_VALUES=0/16"
expect_line "English order locale persistence" "ORDER_LOCALE_META=en_US"
expect_line "email locale switch and restore" "EMAIL_LOCALE_SWITCH=tr_TR>en_US>tr_TR"
expect_line "English order email surfaces" "ENGLISH_EMAIL_HTML=heading:yes|body:yes|product:yes|tracking:yes|additional:yes"
expect_line "single-source hygiene policy" "HYGIENE_SINGLE_SOURCE=yes"
expect_line "three size guide tables" "SIZE_GUIDE_TABLES=3"
expect_line "size set narrowed to S M L" "SIZE_TERMS=S,M,L"
expect_line "size term menu order" "SIZE_TERM_ORDER=S:0|M:1|L:2"
expect_line "story menu label" "STORY_MENU_LABEL=Hikâyemiz"
expect_line "brand story matches source PDF" "BRAND_STORY_PDF_MATCH=yes"
expect_line "About opening follows panel" "ABOUT_OPENING_PANEL_BOUND=yes"
expect_line "newsletter table" "NEWSLETTER_TABLE=ready"
expect_line "native required newsletter form" "NEWSLETTER_FORM=native-required"
expect_line "newsletter notification panel field" "NEWSLETTER_NOTIFICATION_FIELD=panel"
expect_line "retired panel fields removed" "RETIRED_PANEL_FIELDS="
expect_line "membership disabled" "MEMBERSHIP_ENABLED=no"
expect_line "guest-only account options" "ACCOUNT_OPTIONS=guest:yes|checkout_signup:no|checkout_login:no|myaccount_registration:no|users_can_register:0"
expect_line "social login plugin removed" "SOCIAL_LOGIN_PLUGIN=absent"
expect_line "Loginizer protects admin login" "LOGINIZER_ACTIVE=yes"
expect_line "customer email has personalized tracking link" "EMAIL_TRACKING_LINK=personalized"
expect_line "order tracking page ready" "ORDER_TRACKING_PAGE=ready"
expect_line "membership terms draft" "MEMBERSHIP_TERMS_STATUS=draft"
expect_line "WooCommerce account page kept" "MYACCOUNT_PAGE=kept"
expect_line "guest cart lifetime panel value" "GUEST_SESSION_HOURS=48"
expect_line "Instagram link" "INSTAGRAM_LINK=yes"
expect_value "theme POT" "$theme_pot" "yes"
expect_value "plugin POT" "$plugin_pot" "yes"
expect_value "raw theme colors" "$raw_colors" "0"
expect_value "raw theme pixel values" "$raw_px" "0"
expect_value "theme shadows" "$shadows" "0"
expect_value "root overflow mask" "$overflow_masks" "0"
expect_value "undefined CSS tokens" "$undefined_tokens" "0"
expect_value "single-record notification only" "$newsletter_mail_calls" "1"

if [ "$failures" -ne 0 ]; then
  echo "VERIFY=FAIL ($failures)" >&2
  exit 1
fi

./scripts/smoke.sh
echo "VERIFY=PASS"
