#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"
./scripts/ensure-env.sh
set -a
. "$project_dir/.env"
set +a

snapshot=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify.php)
printf '%s\n' "$snapshot"

free_shipping_coupon=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-free-shipping-coupon.php)
printf '%s\n' "$free_shipping_coupon"

product_card_price=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-product-card-price.php)
printf '%s\n' "$product_card_price"

fulfillments=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-fulfillments.php)
printf '%s\n' "$fulfillments"

legal_status=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-legal-status.php)
printf '%s\n' "$legal_status"

iyzico_idempotency=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-iyzico-idempotency.php)
printf '%s\n' "$iyzico_idempotency"

iyzico_isolation=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-iyzico-test-isolation.php)
printf '%s\n' "$iyzico_isolation"

order_experience=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-order-experience.php)
printf '%s\n' "$order_experience"

# Portable, fixture-backed companion to the read-only order-screen snapshot.
order_billing=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-order-billing-panel.php)
printf '%s\n' "$order_billing"

refund_guard=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-iyzico-refund-guard.php)
printf '%s\n' "$refund_guard"

# Cross-process DB keyset fingerprint. Covers wc_orders, wc_orders_meta,
# wc_order_addresses, wc_order_operational_data, woocommerce_order_items,
# woocommerce_order_itemmeta, order notes, wc_order_stats, wc_customer_lookup,
# wc_order_product_lookup, wc_order_tax_lookup and wc_order_coupon_lookup.
invoice_keyset_line() {
  docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-invoice-keyset.php 2>/dev/null \
    | grep -E '^INVOICE_DB_KEYSET=' | tail -n 1 | tr -d '\r\n'
}

invoice_pre_keyset=$(invoice_keyset_line)
printf 'INVOICE_DB_KEYSET_PRE=%s\n' "${invoice_pre_keyset#INVOICE_DB_KEYSET=}"

invoice_integration=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-invoice-integration.php)
printf '%s\n' "$invoice_integration"

# Sandbox harness: fixtures and mocks only. No network call, no EDM operation,
# no document, no database write.
edm_sandbox_harness=$(docker compose run --rm -T wp-cli wp eval-file /project-scripts/verify-edm-sandbox-harness.php)
printf '%s\n' "$edm_sandbox_harness"

# The credential mount must be reachable only by the single allow-listed
# read-only script. Every other value must be refused BEFORE the credential gate,
# so a refusal reason of credentials_file_absent counts as a leak here.
edm_runner_leaks=0
for edm_bad_script in 'edm-sandbox-invoice.php' '../scripts/test-edm-sandbox.php' '/etc/passwd' 'sub/test-edm-sandbox.php' 'test-edm-sandbox.php;id' 'verify.php' 'TEST-EDM-SANDBOX.PHP' ''; do
  edm_runner_out=$(./scripts/edm-test-run.sh "$edm_bad_script" 2>&1 | head -n 1 || true)
  case "$edm_runner_out" in
    EDM_TEST_RUN=BLOCKED*credentials_file_absent*) edm_runner_leaks=$((edm_runner_leaks + 1)) ;;
    EDM_TEST_RUN=BLOCKED*) ;;
    *) edm_runner_leaks=$((edm_runner_leaks + 1)) ;;
  esac
done
edm_runner_allowlisted=$(./scripts/edm-test-run.sh 'test-edm-sandbox.php' 2>&1 | head -n 1 || true)
case "$edm_runner_allowlisted" in
  EDM_TEST_RUN=STARTING*|EDM_TEST_RUN=BLOCKED*credentials_file_absent*) edm_runner_allowlist_ok=yes ;;
  *) edm_runner_allowlist_ok=no ;;
esac
printf 'EDM_RUNNER_ALLOWLIST=leaks:%s|allowlisted_reaches_credential_gate:%s\n' "$edm_runner_leaks" "$edm_runner_allowlist_ok"

invoice_post_keyset=$(invoice_keyset_line)
printf 'INVOICE_DB_KEYSET_POST=%s\n' "${invoice_post_keyset#INVOICE_DB_KEYSET=}"

if [ -n "$invoice_pre_keyset" ] && [ "$invoice_pre_keyset" = "$invoice_post_keyset" ]; then
  invoice_external_isolation="INVOICE_EXTERNAL_ISOLATION=keyset_match:yes"
else
  invoice_external_isolation="INVOICE_EXTERNAL_ISOLATION=keyset_match:no"
fi
printf '%s\n' "$invoice_external_isolation"

email_throwables=$(docker compose run --rm -T wp-cli php /project-scripts/verify-email-delivery.php throwables)
email_disabled_mail=$(docker compose run --rm -T wp-cli php -d disable_functions=mail /project-scripts/verify-email-delivery.php disabled-mail)
email_smtp=$(docker compose run --rm -T wp-cli php /project-scripts/verify-email-delivery.php smtp)
printf '%s\n%s\n%s\n' "$email_throwables" "$email_disabled_mail" "$email_smtp"

response_headers=$(curl -fsS -D - -o /dev/null "$WP_URL/" | tr -d '\r')
security_txt=$(curl -fsSL --max-redirs 3 "$WP_URL/.well-known/security.txt")
xmlrpc_code=$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: text/xml' --data '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>' "$WP_URL/xmlrpc.php")
asset_cache_headers=$(curl -fsSI "$WP_URL/wp-content/themes/kuka-island-child/assets/css/global.css" | tr -d '\r')
header_present() {
  printf '%s\n' "$response_headers" | grep -Eiq "^$1:"
}
security_header_contract="csp:$(header_present 'Content-Security-Policy' && echo yes || echo no)|nosniff:$(header_present 'X-Content-Type-Options' && echo yes || echo no)|referrer:$(header_present 'Referrer-Policy' && echo yes || echo no)|frame:$(header_present 'X-Frame-Options' && echo yes || echo no)|permissions:$(header_present 'Permissions-Policy' && echo yes || echo no)"
hsts_local=$(header_present 'Strict-Transport-Security' && echo present || echo absent)
security_txt_contract="contact:$(printf '%s\n' "$security_txt" | grep -Fq 'Contact: mailto:' && echo yes || echo no)|canonical:$(printf '%s\n' "$security_txt" | grep -Fq 'Canonical:' && echo yes || echo no)"
printf 'SECURITY_HEADERS=%s\nHSTS_LOCAL=%s\nSECURITY_TXT=%s\n' "$security_header_contract" "$hsts_local" "$security_txt_contract"

search_count() {
  pattern=$1
  shift
  if command -v rg >/dev/null 2>&1; then
    rg -n -- "$pattern" "$@" 2>/dev/null | wc -l | tr -d ' '
  else
    grep -ERn --include='*.php' --include='*.js' --include='*.css' --include='*.sh' --include='*.yml' --include='*.yaml' -- "$pattern" "$@" 2>/dev/null | wc -l | tr -d ' '
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
newsletter_blue=$(search_count '\bblue\b|#(00f|0000ff)\b' wp-content/themes/kuka-island-child/assets/css)
svg_upload_filters=$(search_count 'upload_mimes' wp-content/plugins/kuka-island-core wp-content/themes/kuka-island-child)
vendor_changes=$(git diff --name-only -- wp-content/plugins/woocommerce wp-content/plugins/iyzico-woocommerce wp-content/themes/blocksy 2>/dev/null | wc -l | tr -d ' ')
legacy_english_boxes=$(search_count 'English product content|English page content|Leave an English field empty' wp-content/plugins/kuka-island-core/includes)
panel_tabs=$(search_count 'nav-tab-wrapper' wp-content/plugins/kuka-island-core/includes/class-site-appearance.php)
panel_search=$(search_count 'data-kuka-field-search' wp-content/plugins/kuka-island-core)
management_map=$(search_count 'kuka-island-management-map' wp-content/plugins/kuka-island-core/includes)
product_checklist=$(search_count 'kuka-product-checklist' wp-content/plugins/kuka-island-core/includes/class-product-fields.php)
hero_main_line=$(search_count 'kuka-hero__title-main' wp-content/themes/kuka-island-child)
smtp_secret_output_sinks=$(search_count '(echo|print|error_log|add_order_note|wc_get_logger).*(KUKA_SMTP_PASSWORD|\$config\[['"'"']password['"'"']\])' wp-content/plugins/kuka-island-core)
prompted_passwords=$(search_count '--prompt=(admin_password|user_pass)' scripts)
password_argv=$(search_count '--(admin_password|user_pass)=' scripts)
password_stdin_readers=$(search_count 'stream_get_contents\([[:space:]]*STDIN[[:space:]]*\)' scripts/install.sh scripts/seed.sh)
mutable_actions=$(search_count 'uses:[[:space:]]+[^@[:space:]]+@(v[0-9]+|main|master)([[:space:]]|$)' .github/workflows)
pinned_actions=$(search_count 'uses:[[:space:]]+[^@[:space:]]+@[0-9a-f]{40}' .github/workflows)
workflow_permissions=$(search_count '^[[:space:]]{0,2}permissions:' .github/workflows/quality.yml)
fixed_local_usernames=$(search_count 'kuka_(admin|manager)' .env.example README.md PLAN.md scripts wp-content/plugins/kuka-island-core wp-content/themes/kuka-island-child docs/AKTARMA_HARITASI.md docs/FAZ3D_TEKNIK_RAPORU.md docs/FAZ3E_TEKNIK_RAPORU.md)

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
NEWSLETTER_BLUE=$newsletter_blue
SVG_UPLOAD_FILTERS=$svg_upload_filters
VENDOR_CHANGES=$vendor_changes
LEGACY_ENGLISH_BOXES=$legacy_english_boxes
PANEL_TABS=$panel_tabs
PANEL_SEARCH=$panel_search
MANAGEMENT_MAP=$management_map
PRODUCT_CHECKLIST=$product_checklist
HERO_MAIN_LINE=$hero_main_line
SMTP_SECRET_OUTPUT_SINKS=$smtp_secret_output_sinks
PROMPTED_PASSWORDS=$prompted_passwords
PASSWORD_ARGV=$password_argv
PASSWORD_STDIN_READERS=$password_stdin_readers
MUTABLE_ACTIONS=$mutable_actions
PINNED_ACTIONS=$pinned_actions
WORKFLOW_PERMISSIONS=$workflow_permissions
FIXED_LOCAL_USERNAMES=$fixed_local_usernames
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
expect_email_line() {
  label=$1
  line=$2
  if printf '%s\n%s\n%s\n' "$email_throwables" "$email_disabled_mail" "$email_smtp" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}

expect_fulfillment_line() {
  label=$1
  expected=$2
  if printf '%s\n' "$fulfillments" | grep -Fqx "$expected"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $expected)" >&2
    failures=$((failures + 1))
  fi
}
expect_coupon_line() {
  label=$1
  line=$2
  if printf '%s\n' "$free_shipping_coupon" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}
expect_product_card_line() {
  label=$1
  line=$2
  if printf '%s\n' "$product_card_price" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}
expect_isolation_line() {
  label=$1
  line=$2
  if printf '%s\n' "$iyzico_isolation" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}
expect_refund_guard_line() {
  label=$1
  line=$2
  if printf '%s\n' "$refund_guard" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}
expect_invoice_line() {
  label=$1
  line=$2
  if printf '%s\n%s\n' "$invoice_integration" "$invoice_external_isolation" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}
expect_sandbox_line() {
  label=$1
  line=$2
  if printf '%s\n' "$edm_sandbox_harness" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}
expect_sandbox_match() {
  label=$1
  pattern=$2
  if printf '%s\n' "$edm_sandbox_harness" | grep -Eq "$pattern"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected pattern $pattern)" >&2
    failures=$((failures + 1))
  fi
}
expect_invoice_match() {
  label=$1
  pattern=$2
  if printf '%s\n%s\n' "$invoice_integration" "$invoice_external_isolation" | grep -Eq "$pattern"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected pattern $pattern)" >&2
    failures=$((failures + 1))
  fi
}
expect_order_experience_line() {
  label=$1
  line=$2
  if printf '%s\n' "$order_experience" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}
expect_order_experience_match() {
  label=$1
  pattern=$2
  if printf '%s\n' "$order_experience" | grep -Eq "$pattern"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected pattern $pattern)" >&2
    failures=$((failures + 1))
  fi
}
expect_order_billing_line() {
  label=$1
  line=$2
  if printf '%s\n' "$order_billing" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}
expect_order_billing_match() {
  label=$1
  pattern=$2
  if printf '%s\n' "$order_billing" | grep -Eq "$pattern"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected pattern $pattern)" >&2
    failures=$((failures + 1))
  fi
}
expect_iyzico_line() {
  label=$1
  line=$2
  if printf '%s\n' "$iyzico_idempotency" | grep -Fqx "$line"; then
    echo "PASS $label"
  else
    echo "FAIL $label (expected $line)" >&2
    failures=$((failures + 1))
  fi
}
expect_legal_status_line() {
  label=$1
  line=$2
  if printf '%s\n' "$legal_status" | grep -Fqx "$line"; then
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
expect_line "WordPress current release" "WP_VERSION=7.1"
expect_line "WooCommerce security maintenance release" "WOOCOMMERCE_VERSION=11.0.1"
expect_fulfillment_line "WooCommerce Fulfillments feature" "FULFILLMENTS_FEATURE=PASS"
expect_fulfillment_line "Fulfillments uses HPOS" "HPOS=PASS"
expect_fulfillment_line "Fulfillments order table" "FULFILLMENTS_TABLE=PASS"
expect_fulfillment_line "Fulfillments meta table" "FULFILLMENTS_META_TABLE=PASS"
expect_fulfillment_line "Fulfillments data store" "FULFILLMENTS_DATA_STORE=PASS|WC_Data_Store"
expect_fulfillment_line "Fulfillments REST routes" "FULFILLMENTS_REST=PASS|routes:4"
expect_fulfillment_line "Fulfillments HPOS admin UI" "FULFILLMENTS_HPOS_UI=PASS"
expect_fulfillment_line "guest order fulfillment UI" "GUEST_ORDER_DETAILS_UI=PASS"
expect_fulfillment_line "Aras Kargo tracking provider" "ARAS_KARGO=PASS|https://www.araskargo.com.tr/Tracking/Detail?trackingNumber=1234567890"
expect_fulfillment_line "Yurtiçi Kargo tracking provider" "YURTICI_KARGO=PASS|https://www.yurticikargo.com/Tracking/Detail/1234567890"
expect_fulfillment_line "created fulfillment customer email" "CUSTOMER_FULFILLMENT_CREATED=PASS"
expect_fulfillment_line "updated fulfillment customer email" "CUSTOMER_FULFILLMENT_UPDATED=PASS"
expect_fulfillment_line "deleted fulfillment customer email" "CUSTOMER_FULFILLMENT_DELETED=PASS"
expect_fulfillment_line "guest fulfillment email target" "GUEST_FULFILLMENT_EMAIL_TARGET=PASS|unsaved-order"
expect_line "Blocksy security maintenance release" "BLOCKSY_VERSION=2.1.53"
expect_line "Blocksy Companion security maintenance release" "BLOCKSY_COMPANION_VERSION=2.1.53"
expect_line "Loginizer race-condition fix" "LOGINIZER_VERSION=2.1.0"
expect_line "security header module" "SECURITY_HEADER_MODULE=ready"
expect_line "CSP keeps iyzico checkout sources" "SECURITY_CSP_IYZICO=allowed"
expect_line "CSP allows WooCommerce variation templates only on products" "SECURITY_CSP_WC_VARIATIONS=product-only"
expect_value "public response security headers" "$security_header_contract" "csp:yes|nosniff:yes|referrer:yes|frame:yes|permissions:yes"
expect_value "HSTS stays off on local HTTP" "$hsts_local" "absent"
expect_value "RFC 9116 security contact" "$security_txt_contract" "contact:yes|canonical:yes"
expect_value "XML-RPC endpoint disabled" "$xmlrpc_code" "403"
expect_value "versioned theme assets are immutable" "$(printf '%s\n' "$asset_cache_headers" | grep -Eiq '^Cache-Control:.*max-age=31536000.*immutable' && echo yes || echo no)" "yes"
expect_line "six-item header menu" "PRIMARY_MENU_COUNT=6"
expect_line "daily manager" "DAILY_MANAGER=yes"
expect_line "Coming Soon remains enabled" "STORE_VISIBILITY=coming-soon"
expect_line "Coming Soon covers the whole site" "COMING_SOON_SCOPE=whole-site"
expect_line "search engines remain blocked" "SEARCH_ENGINE_VISIBILITY=noindex"
expect_line "private acceptance preview" "PRIVATE_PREVIEW=ready"
expect_line "measured Site Appearance inventory" "SITE_APPEARANCE_INVENTORY=13_groups|115_rows|156_controls"
expect_line "classic checkout" "CHECKOUT_CLASSIC=yes"
expect_line "stock quantity appears only when low" "STOCK_DISPLAY_FORMAT=low_amount"
expect_line "no legal draft warnings left" "LEGAL_DRAFT_WARNINGS=0"
expect_line "central legal company data" "LEGAL_CENTRAL_COMPANY=8/8"
expect_line "41 bilingual appearance fields" "LANGUAGE_TRANSLATABLE_FIELDS=41"
expect_line "nine English product fields" "PRODUCT_EN_FIELD_SCHEMA=9"
expect_line "two English page fields" "PAGE_EN_FIELD_SCHEMA=2"
expect_line "English taxonomy field" "TAXONOMY_EN_FIELD=_kuka_name_en"
expect_line "no translation plugin" "TRANSLATION_PLUGIN=none"
expect_line "English language no longer pending" "LANGUAGE_PENDING_URLS="
expect_line "legal English seed remains empty" "LEGAL_EN_VALUES=0/16"
expect_line "all non-legal page English fields seeded" "NONLEGAL_EN_VALUES=16/16"
expect_line "all Site Appearance English fields seeded" "SITE_APPEARANCE_EN_VALUES=41/41"
expect_line "native language labels" "LANGUAGE_ITEMS=Türkçe|/|English|/en/"
expect_line "language list has no English twin" "LANGUAGE_ITEMS_EN=absent"
expect_line "non-translatable fields have no twins" "NONTRANSLATABLE_TWINS=0"
expect_line "all product English fields seeded" "PRODUCT_EN_VALUES=36/36"
expect_line "English brand story rhythm and signature" "ABOUT_EN_RHYTHM=yes"
expect_line "English order locale persistence" "ORDER_LOCALE_META=en_US"
expect_line "English public URLs stay prefixed" "ENGLISH_PUBLIC_URLS=prefixed"
expect_line "order received URL stays English" "ORDER_RECEIVED_LANGUAGE=preserved"
expect_line "iyzico return URL stays English" "IYZICO_RETURN_LANGUAGE=preserved"
expect_line "email locale switch and restore" "EMAIL_LOCALE_SWITCH=tr_TR>en_US>tr_TR"
expect_line "English order email surfaces" "ENGLISH_EMAIL_HTML=heading:yes|body:yes|product:yes|tracking:yes|additional:yes"
expect_line "single-source hygiene policy" "HYGIENE_SINGLE_SOURCE=yes"
expect_line "free shipping discount basis default" "FREE_SHIPPING_IGNORE_DISCOUNTS=no"
expect_line "free shipping discount basis sync" "FREE_SHIPPING_IGNORE_DISCOUNTS_SYNC=no"
expect_line "free shipping threshold or coupon requirement" "FREE_SHIPPING_REQUIREMENT_SYNC=either"
expect_line "English shipping method labels" "SHIPPING_RATE_LABELS_EN=Free shipping|Flat rate"
expect_coupon_line "free shipping coupon test stays below threshold" "FREE_SHIPPING_COUPON_BELOW_THRESHOLD=yes"
expect_coupon_line "free shipping coupon exposes only free method" "FREE_SHIPPING_COUPON_METHODS=free_shipping"
expect_coupon_line "free shipping coupon removes shipping cost" "FREE_SHIPPING_COUPON_COST=0.00"
expect_product_card_line "variable sale product card is on sale" "PRODUCT_CARD_VARIABLE_ON_SALE=yes"
expect_product_card_line "variable sale product card uses minimum price" "PRODUCT_CARD_MIN_PRICE=1.00"
expect_product_card_line "Turkish variable sale card shows one lira" "PRODUCT_CARD_ONE_LIRA_TR=present"
expect_product_card_line "Turkish variable sale card never invents zero lira" "PRODUCT_CARD_ZERO_LIRA_TR=absent"
expect_product_card_line "English variable sale card shows one lira" "PRODUCT_CARD_ONE_LIRA_EN=present"
expect_product_card_line "English variable sale card never invents zero lira" "PRODUCT_CARD_ZERO_LIRA_EN=absent"
expect_line "three size guide tables" "SIZE_GUIDE_TABLES=3"
expect_line "size set narrowed to S M L" "SIZE_TERMS=S,M,L"
expect_line "size term menu order" "SIZE_TERM_ORDER=S:0|M:1|L:2"
expect_line "story menu label" "STORY_MENU_LABEL=Hikâyemiz"
expect_line "brand story matches source PDF" "BRAND_STORY_PDF_MATCH=yes"
expect_line "About opening follows panel" "ABOUT_OPENING_PANEL_BOUND=yes"
expect_line "six story scenes" "STORY_SCENES=6"
expect_line "bilingual story scenes" "STORY_BILINGUAL=6/6"
expect_line "separate bilingual desktop and mobile story media" "STORY_MEDIA_FIELDS=24/24"
expect_line "story body matches manifesto PDF" "STORY_PDF_BODY_MATCH=yes"
expect_line "scene four line reveal" "STORY_LINE_REVEAL=scene-04"
expect_line "server-rendered story DOM" "STORY_PROGRESSIVE_DOM=server"
expect_line "story observer cleanup and no scroll listener" "STORY_OBSERVER=io+cleanup+no-scroll"
expect_line "mobile story uses the scroll-led enhancement" "STORY_MOBILE_ENHANCED=enabled"
expect_line "empty story media placeholder" "STORY_EMPTY_MEDIA=placeholder"
expect_line "newsletter table" "NEWSLETTER_TABLE=ready"
expect_line "native required newsletter form" "NEWSLETTER_FORM=native-required"
expect_line "newsletter label placeholder and site button" "NEWSLETTER_UI=label+placeholder|site-button"
expect_line "newsletter notification panel field" "NEWSLETTER_NOTIFICATION_FIELD=panel"
expect_line "newsletter uses double opt-in" "NEWSLETTER_DOUBLE_OPT_IN=schema+token+confirm"
expect_line "newsletter preserves first consent evidence" "NEWSLETTER_FIRST_EVIDENCE=immutable"
expect_line "newsletter has IP and address-pair rate limits" "NEWSLETTER_IP_LIMIT=global+pair"
expect_line "CSV export neutralizes spreadsheet formulas" "NEWSLETTER_CSV_FORMULA=escaped"
expect_line "relative URLs reject backslashes" "RELATIVE_URL_BACKSLASH=rejected"
expect_line "tracking links carry no customer e-mail" "TRACKING_LINK_PII=token-only"
expect_line "nested catalog filters cannot fatal" "CATALOG_NESTED_FILTER=ignored"
expect_line "array phone input is rejected" "CHECKOUT_PHONE_ARRAY=rejected"
expect_line "checkout AJAX preview uses delivery address" "CHECKOUT_PREVIEW_ADDRESS=swapped"
expect_line "English singular product label" "ENGLISH_PLURAL_ONE=1 product"
expect_line "taxonomyless get_terms accepts null taxonomy" "TAXONOMYLESS_GET_TERMS=null-safe"
expect_line "canonical query uses an allowlist" "CANONICAL_QUERY_POLICY=allowlisted"
expect_line "coupon response parsing is inert" "CART_RESPONSE_PARSER=inert"
expect_line "title callbacks tolerate one argument" "TITLE_CALLBACK_DEFAULTS=compatible"
expect_line "hot content options autoload" "CONTENT_OPTION_AUTOLOAD=3/3"
expect_line "English sitemap queries are bounded" "ENGLISH_SITEMAP_PAGING=bounded"
expect_line "dead language fields are removed" "DEAD_LANGUAGE_FIELDS=absent"
expect_line "newsletter pair limit follows successful mail" "NEWSLETTER_RATE_AFTER_MAIL=yes"
expect_line "checkout summary total follows AJAX totals" "CHECKOUT_SUMMARY_TOTAL=ajax-synced"
expect_line "optional phone can stay empty" "CHECKOUT_OPTIONAL_PHONE=empty-allowed"
expect_line "company is required only for corporate billing" "CHECKOUT_COMPANY_REQUIRED=corporate-only"
expect_line "site content is request-local cached" "SITE_CONTENT_CACHE=request-local"
expect_line "order raw metadata is replaced by a read-only payment summary" "ORDER_META_ADMIN=raw-hidden|summary-readonly"
expect_line "product caches cover shortcode and single product" "PRODUCT_CACHE_PRIMING=shortcode+single"
expect_line "cart fragments are not an eager dependency" "CART_FRAGMENT_DEPENDENCY=deferred"
expect_line "retired panel fields removed" "RETIRED_PANEL_FIELDS="
expect_line "checkout address uses province-only reference flow" "CHECKOUT_ADDRESS_FLOW=address|address2|postcode+province|phone"
expect_line "checkout checkbox and labels share alignment grid" "CHECKOUT_CHECKBOX_ALIGNMENT=shared-grid"
expect_line "checkout errors use underline-only treatment" "CHECKOUT_ERROR_STYLE=underline-only"
expect_line "hero overlay layer removed" "HERO_OVERLAY_LAYER=absent"
expect_line "header top and scrolled modes" "HEADER_TOP_MODE=photo-white-to-paper-dark"
expect_line "membership disabled" "MEMBERSHIP_ENABLED=no"
expect_line "guest-only account options" "ACCOUNT_OPTIONS=guest:yes|checkout_signup:no|checkout_login:no|myaccount_registration:no|users_can_register:0"
expect_line "social login plugin removed" "SOCIAL_LOGIN_PLUGIN=absent"
expect_line "Loginizer protects admin login" "LOGINIZER_ACTIVE=yes"
expect_line "customer email has tokenized tracking link" "EMAIL_TRACKING_LINK=tokenized"
expect_line "order tracking page ready" "ORDER_TRACKING_PAGE=ready"
expect_line "membership terms draft" "MEMBERSHIP_TERMS_STATUS=draft"
expect_line "WooCommerce account page kept" "MYACCOUNT_PAGE=kept"
expect_line "guest cart lifetime panel value" "GUEST_SESSION_HOURS=48"
expect_line "Instagram link" "INSTAGRAM_LINK=yes"
expect_line "iyzico automatic readiness" "IYZICO_APPLICATION_READINESS=7/15|missing:8"
expect_line "no legal row is declared not applicable yet" "IYZICO_APPLICATION_ROWS=15|not_applicable:0"
expect_line "all readiness rows link to their screen" "IYZICO_APPLICATION_LINKS=15/15"
expect_line "legal identifiers default to pending" "LEGAL_FIELD_STATUS=mersis_number:pending|kep_address:pending|professional_chamber:pending|professional_rules_url:pending|etbis_number:pending"
expect_legal_status_line "three legal states resolve independently" "LEGAL_STATE_RESOLUTION=present:present|pending:pending|not_applicable:not_applicable|unverified:unverified"
expect_legal_status_line "only verified present values are published" "LEGAL_STATE_PUBLISHED=present:5|pending:0|not_applicable:0|unverified:0"
expect_legal_status_line "not applicable rows leave the readiness denominator" "LEGAL_STATE_READINESS=present:12/15|pending:7/15|not_applicable:7/10|unverified:7/15"
expect_legal_status_line "pending rows count as launch gaps, not applicable rows do not" "LEGAL_STATE_MISSING=present:3|pending:8|not_applicable:3|unverified:8"
expect_legal_status_line "legal value verification rules" "LEGAL_VALUE_VERIFICATION=filled:yes|empty:no|placeholder:no|bad_email:no|good_email:yes|bad_url:no|good_url:yes"
expect_legal_status_line "unknown or absent status input falls back to pending" "LEGAL_STATUS_SANITIZE=unknown:pending|absent:pending|explicit:not_applicable"
expect_legal_status_line "migration never assigns not applicable" "LEGAL_MIGRATION_TARGETS=PENDING,PRESENT"
expect_legal_status_line "shipped default legal status" "LEGAL_STATUS_DEFAULT=pending"
expect_line "manual iyzico documents start unchecked" "IYZICO_MANUAL_DOCUMENTS=0/5"
expect_iyzico_line "iyzico guard lives in the core plugin, not in the gateway" "IYZICO_GUARD_LOCATION=core-plugin"
expect_iyzico_line "iyzico guard owns the route and callback hooks" "IYZICO_GUARD_HOOKS=rest:10|callback:1|probe:1"
expect_iyzico_line "iyzico guard is scoped to the checkout form flow" "IYZICO_GUARD_SCOPE=CHECKOUT_FORM_AUTH"
expect_iyzico_line "iyzico concurrency uses a connection scoped advisory lock" "IYZICO_LOCK_PRIMITIVE=advisory-lock|expiry_rules:0|name_length:49"
expect_iyzico_line "iyzico status probe carries no payment token" "IYZICO_STATUS_PROBE=token-free|auth:order-key"
expect_iyzico_line "iyzico currency verification fails closed" "IYZICO_CURRENCY=fail-closed"
expect_iyzico_line "iyzico secret and signature never reach a log" "IYZICO_SECRET_LEAKS=0"
expect_iyzico_line "record-before-processing guard is gone" "IYZICO_LEGACY_GUARD=removed"
expect_iyzico_line "iyzico delivery is recorded only after it settles" "IYZICO_GUARD_ORDER=signature<lock<preflight<vendor<settled<record"
expect_iyzico_line "iyzico settlement contract requires every clause" "IYZICO_SETTLED_MATRIX=both_success_same_id:PASS|success_failure:PASS|failure_success:PASS|empty_success:PASS|success_empty:PASS|empty_stored_id:PASS|empty_expected_id:PASS|different_id:PASS"
expect_iyzico_line "iyzico payment is verified against the API before the vendor runs" "IYZICO_PREFLIGHT=before-vendor|default:live-api|override:test-only"
expect_iyzico_line "a concurrent return never sees a thank-you page or a GET reload" "IYZICO_CALLBACK_INPROGRESS=409-holding-page|no-meta-refresh|post-retry"
expect_iyzico_line "one shared order level lock guards both channels" "IYZICO_LOCK_SCOPE=order-level|recheck_after_lock:yes|shared_name:stable"
expect_iyzico_line "the timed claim implementation is fully gone" "IYZICO_LEGACY_CLAIM=removed|daily_cron:none"
expect_isolation_line "fixture cleanup refuses every unowned target" "ISOLATION_OWNERSHIP=owned:PASS|protected_order:PASS|protected_order_189:PASS|missing_run_id:PASS|invalid_order_id:PASS|not_created_by_this_run:PASS|run_meta_absent:PASS|run_meta_other_run:PASS|fixture_marker_absent:PASS|fixture_marker_wrong:PASS"
expect_isolation_line "long lived sandbox orders can never be a cleanup target" "ISOLATION_PROTECTED=5/5"
expect_isolation_line "no wildcard, e-mail or date based delete exists" "ISOLATION_FORBIDDEN_DELETES=like_delete:none|email_delete:none|date_delete:none|wildcard_meta:none|bulk_wc_orders:none"
expect_isolation_line "cleanup is gated on the shared ownership predicate" "ISOLATION_CLEANUP=predicate-gated|refusal_fails_run:yes"
expect_isolation_line "each run carries its own UUID" "ISOLATION_RUN_ID=uuid-per-run"
expect_isolation_line "a run id must be a real UUID, not any 36 characters" "ISOLATION_RUN_ID_FORMAT=valid:PASS|thirty_six_spaces:PASS|thirty_six_chars:PASS|wrong_version:PASS|wrong_variant:PASS|empty:PASS"
expect_isolation_line "gateway row cleanup needs id, order and token to agree" "ISOLATION_PROVIDER_ROWS=owned:PASS|row_not_found:PASS|row_id_mismatch:PASS|order_id_mismatch:PASS|token_mismatch:PASS|protected_order:PASS|incomplete_record:PASS"
expect_isolation_line "a customer row needs every ownership clause" "ISOLATION_CUSTOMER_ROWS=only_run_orders:PASS|empty_linked_orders:PASS|preexisting_customer:PASS|mixed_real_and_run_orders:PASS|not_a_run_candidate:PASS|row_not_found:PASS|email_mismatch:PASS|registered_user:PASS|missing_run_email:PASS"
expect_isolation_line "one shutdown coordinator releases the lock only after cleanup" "ISOLATION_SHUTDOWN_ORDER=cleanup-then-release|single_coordinator:yes|idempotent:yes"
expect_isolation_line "a blocked run exits non-zero and never creates the fixture" "ISOLATION_FAIL_EXIT=lock:yes|stale:yes|fixture:yes|mu_write:yes|option_write:yes|final:yes|created_by_test:no"
expect_isolation_line "every linked order is re-checked on the live record" "ISOLATION_LINKED_ORDERS=linked_full_ownership:PASS|linked_run_meta_mismatch:PASS|linked_marker_missing:PASS|linked_protected_order:PASS|linked_order_missing:PASS|linked_not_created:PASS"
expect_isolation_line "the e-mail acceptance fixture tears itself down" "ISOLATION_EMAIL_FIXTURE=run-owned-teardown|raw_delete:none"
expect_isolation_line "every teardown transition is proven purely" "ISOLATION_CLEANUP_TRANSITIONS=idle_starts:PASS|running_no_reentry:PASS|succeeded_terminal:PASS|failed_terminal:PASS|finish_clean:PASS|finish_provider:PASS|finish_customer:PASS|finish_option:PASS|finish_mu_plugin:PASS"
expect_isolation_line "no runtime fault injection, preflight before writes" "ISOLATION_HARNESS_SAFETY=fault_injection:0|preflight:before-writes|write_verified:yes"
expect_isolation_line "cleanup state is explicit and success is conditional" "ISOLATION_CLEANUP_STATE=four-state|success-conditional|no-reentry|refusal-exits-nonzero"
expect_isolation_line "the e-mail teardown uses the same state contract" "ISOLATION_EMAIL_CLEANUP_STATE=four-state|refusal-exits-nonzero"
expect_isolation_line "the harness lock is taken before any shared write" "ISOLATION_HARNESS_LOCK=before-shared-writes|run_scoped_teardown:yes"
expect_isolation_line "the baseline is compared as primary key sets" "ISOLATION_BASELINE=primary-key-sets"
expect_order_experience_line "shipping vocabulary covers both delivery paths" "FULFILLMENT_MAP=total:61|drawer:35|php:26"
expect_order_experience_line "no raw fulfillment wording and no collateral domain" "FULFILLMENT_WORDING=raw_yerine_getirme:0|empty:0|other_domain_affected:0|unrelated_wc_affected:0"
expect_order_experience_line "handed to courier is never called delivered" "FULFILLMENT_DELIVERY_TERM=teslim_edildi_in_map:0"
expect_order_experience_line "wording never attaches outside the orders screens" "FULFILLMENT_SCOPE=dashboard_hooked:no"
expect_order_experience_line "wording attaches on the orders screen" "FULFILLMENT_HOOKED=orders_screen:yes"
expect_order_experience_line "no second summary or guide on the order screen" "ORDER_OVERVIEW_REMOVED=yes|leftovers:0"
expect_order_experience_line "the fulfillment drawer has one safe scrolling layer" "DRAWER_SCROLL_CONTRACT=drawer_rules:1|safe_body_rules:1|document_locks:0|script:removed"
# Billing-panel behaviour is proved on run-owned fixtures, so the same result is
# produced on a clean CI database and on the developer database.
expect_order_billing_line "customer details stay in the Billing panel" "ORDER_BILLING_FIELDS=full:first:set,last:set,email:set,phone:set|no_phone:first:set,last:set,email:set,phone:empty"
expect_order_billing_match "billing field presence contract" "^ORDER_BILLING_FIELD_PRESENCE=PASS\\|cases:2\\|"
expect_order_billing_line "billing values survive the round trip byte for byte" "ORDER_BILLING_ROUNDTRIP=PASS|fields:12|mismatches:none"
expect_order_billing_line "billing fixtures are owned and fully removed" "ORDER_BILLING_FIXTURE_CLEANUP=PASS|state:succeeded|created:2|db_discoverable:2|refused:0|leftover:0|reentry_blocked:yes"
expect_order_billing_match "billing fixtures leave the database untouched" "^ORDER_BILLING_DB_ISOLATION=PASS\\|tables:12\\|pre_hash:[0-9a-f]+\\|post_hash:[0-9a-f]+\\|diff:none$"
expect_order_billing_match "protected-order verdict covers every branch" "^PROTECTED_ORDERS_VERDICT_MATRIX=PASS\\|cases:8\\|.*\\|clean_line_shape:ok$"

# The long-lived sandbox orders exist only in the developer database. Present and
# unchanged is a pass; entirely absent on a clean install is a pass reported as
# not_applicable. Partial presence or a changed signature is DRIFT and fails.
expect_order_experience_match "long lived sandbox orders are unchanged or absent on a clean database" "^PROTECTED_ORDERS=(verified\\|present:5/5\\|matching:5\\|drifted:0\\|absent:0\\|reason:all_snapshot_orders_present_and_unchanged|not_applicable\\|present:0/5\\|matching:0\\|drifted:0\\|absent:5\\|reason:clean_database_without_local_sandbox_orders)$"
expect_refund_guard_line "an unsafe automatic iyzico refund is refused on every clause" "REFUND_PREFLIGHT=valid_latest_row:PASS|payment_id_null:PASS|payment_id_empty:PASS|conversation_id_empty:PASS|status_failure:PASS|payment_status_failure:PASS|verified_id_differs:PASS|no_verified_id:PASS|order_id_mismatch:PASS|latest_row_missing:PASS"
expect_refund_guard_line "a healthy older row cannot excuse a broken newest row" "REFUND_STALE_LATEST_ROW=older-ok-but-latest-blocked"
expect_refund_guard_line "the refund guard runs before the record is saved" "REFUND_GUARD_SHAPE=before-save|manual-skipped|other-gateways-skipped"
expect_refund_guard_line "the refund guard reads the row the gateway would use" "REFUND_ROW_SELECTION=matches-vendor"
expect_refund_guard_line "the refund guard leaks nothing" "REFUND_GUARD_LEAKS=0"
expect_invoice_line "Invoice SOAP ext-soap is available" "INVOICE_SOAP_EXTENSION_AVAILABLE=PASS"
expect_invoice_line "Invoice config hides passwords and masks VKN" "INVOICE_CONFIG_SECURITY=PASS|credentials_hidden:yes|vkn_masked:yes"
expect_invoice_line "Invoice live readiness validation" "INVOICE_LIVE_READINESS_VALIDATION=PASS|ready:no|missing_count:11"

# Audit item 2: generic individual VKN policy defaults to false.
expect_invoice_line "Generic individual VKN defaults to false" "INVOICE_GENERIC_VKN_DEFAULT_FALSE=PASS|constant_defined:no|default_allow:no"
expect_invoice_line "Generic individual VKN needs literal true" "INVOICE_GENERIC_VKN_STRICT_TRUE_ONLY=PASS|explicit_true:allow|truthy_string:deny|explicit_false:deny"
expect_invoice_line "Generic individual VKN runtime behaviour" "INVOICE_GENERIC_VKN_RUNTIME_BEHAVIOUR=PASS|default_error:missing_individual_tckn|explicit_true_vkn:11111111111"

# Audit item 3: auto-send honours the whole can_send_invoice contract.
expect_invoice_line "Auto-send honours full readiness contract" "INVOICE_AUTO_SEND_FULL_READINESS_CONTRACT=PASS|ready_enabled:yes|fields_checked:11|leaks:none|postcode_optional:yes"

# EDM's own sample invoices carry no supplier cbc:PostalZone and its test portal
# has no postcode field, so the value is emitted when known and omitted when not.
expect_invoice_line "supplier postcode is optional in the UBL" "INVOICE_SUPPLIER_POSTCODE_OPTIONAL=PASS|with_postcode:present|value_roundtrip:exact|without_postcode:omitted|empty_node_emitted:no|supplier_fields_missing:none|customer_postal_zone:unchanged"
expect_invoice_line "the mapper accepts a missing supplier postcode" "INVOICE_MAPPER_POSTCODE_OPTIONAL=PASS|missing_postcode:accepted|postcode_value:empty|missing_city:missing_supplier_configuration"

# REQUEST_HEADER matches EDM's published envelope, and a SOAP fault is reduced
# to a fixed vocabulary before it can reach any output stream.
expect_invoice_line "Login REQUEST_HEADER matches the EDM reference envelope" "INVOICE_LOGIN_REQUEST_HEADER_CONTRACT=PASS|fields:8|missing:none|session_id:0|reason:Login|compressed:N|application_name_ok:yes|action_date_shape:ok|client_txn_id_uuid:yes"
expect_invoice_line "session operations carry the same complete header" "INVOICE_SESSION_REQUEST_HEADER_CONTRACT=PASS|operations:4|complete:yes|problems:none"
expect_invoice_line "SendInvoice keeps its idempotency key" "INVOICE_SENDINVOICE_HEADER_KEEPS_UUID=PASS|client_txn_id_bound:yes|reason:SendInvoice|compressed:N"
expect_invoice_line "SOAP faults reduce to a fixed safe vocabulary" "INVOICE_FAULT_CLASSIFIER_MATRIX=PASS|cases:15|wrong:none|fields:4|digest_field:absent"
expect_invoice_line "a poisoned diagnostic never reaches an output surface" "INVOICE_DIAGNOSTIC_INJECTION_REFUSED=PASS|cases:27|secrets:5|leaked:none|bad_shape:none|retry_forced_open:none|unset_diagnostic_prints:nothing"
expect_invoice_match "a fault message never reaches the exception surface" "^INVOICE_FAULT_MESSAGE_NEVER_LEAKS=PASS\\|needles_checked:5\\|leaked:none\\|safe_code:edm_auth_failed\\|diagnostic:category:credentials_rejected\\|"
expect_invoice_line "Auto-send still requires explicit opt-in" "INVOICE_AUTO_SEND_REQUIRES_OPT_IN=PASS|auto_send_off_enabled:no"

# Login contract: authentication must not require fiscal configuration.
expect_invoice_line "Login works without fiscal configuration" "INVOICE_LOGIN_WITHOUT_FISCAL_CONFIG=PASS|transport_Login_calls:1|session_obtained:yes|has_login_credentials:yes|is_configured:no|can_send_invoice:no|auto_send:no|error:none"
expect_invoice_line "Login SECRET_KEY stays optional" "INVOICE_LOGIN_SECRET_KEY_OPTIONAL=PASS|transport_Login_calls:1|session_obtained:yes"
expect_invoice_line "Login rejects missing credentials before any transport call" "INVOICE_LOGIN_REJECTS_WITHOUT_TRANSPORT_CALL=PASS|no_username:edm_not_configured/calls=0|no_password:edm_not_configured/calls=0|neither:edm_not_configured/calls=0|whitespace_user:edm_not_configured/calls=0"

# Audit items 1 and 10: fixture guard on the real automatic-send path.
expect_invoice_line "Queue fixture guard on real runtime path" "INVOICE_QUEUE_FIXTURE_GUARD_RUNTIME_PATH=PASS|throwable:none|fixture_status:none|control_status:queued|auto_send:on|settled:yes"
expect_invoice_match "Queue scheduling leaves no residue" "^INVOICE_QUEUE_SCHEDULING_RESIDUE_ZERO=PASS\|purged:[0-9]+\|residual_rows:0$"
expect_invoice_line "Manager rejects fixture orders" "INVOICE_MANAGER_FIXTURE_GUARD=PASS|code:test_fixture_rejected|SendInvoice:0"
expect_invoice_match "Fixture guard is structurally not overridable" "^INVOICE_FIXTURE_GUARD_NOT_OVERRIDABLE=PASS\|module_files_scanned:[0-9]{2,}\|guard_final:yes\|guard_static:yes\|manager_accessor_final:yes\|toggles:none$"

# Audit item 7: every SOAP contract assertion runs through the production client.
expect_invoice_line "Invoice WSDL interceptor loads successfully" "INVOICE_WSDL_INTERCEPTOR_INIT=PASS|wsdl_loaded:yes"
expect_invoice_line "Login with secret key DOMXPath" "INVOICE_SOAP_XPATH_LOGIN_WITH_SECRET=PASS|assertions:5|session_parsed:yes|failed:none"
expect_invoice_line "Login without secret key DOMXPath" "INVOICE_SOAP_XPATH_LOGIN_NO_SECRET=PASS|assertions:4|failed:none"
expect_invoice_line "CheckCounter DOMXPath and counter_left" "INVOICE_SOAP_XPATH_CHECK_COUNTER=PASS|assertions:3|counter_left:1250|failed:none"
expect_invoice_line "CheckUser DOMXPath" "INVOICE_SOAP_XPATH_CHECK_USER=PASS|assertions:5|alias_parsed:urn:mail:defaultgb@acme.com|failed:none"
expect_invoice_line "GetInvoiceSerial DOMXPath" "INVOICE_SOAP_XPATH_GET_INVOICE_SERIAL=PASS|assertions:6|serial_code:KUK|last_serial_used:42|failed:none"
expect_invoice_line "SendInvoice e-Archive DOMXPath and single base64" "INVOICE_SOAP_XPATH_SEND_INVOICE_EARCHIVE=PASS|assertions:15|single_base64_sha256_match:yes|error:none|failed:none"
expect_invoice_line "SendInvoice e-Invoice DOMXPath" "INVOICE_SOAP_XPATH_SEND_INVOICE_EINVOICE=PASS|assertions:6|failed:none"
expect_invoice_line "GetInvoiceStatus DOMXPath" "INVOICE_SOAP_XPATH_GET_INVOICE_STATUS=PASS|assertions:7|parsed_status:completed|failed:none"
expect_invoice_line "GetInvoice DOMXPath" "INVOICE_SOAP_XPATH_GET_INVOICE=PASS|assertions:6|error:none|failed:none"
expect_invoice_line "EmailInvoice DOMXPath" "INVOICE_SOAP_XPATH_EMAIL_INVOICE=PASS|assertions:6|error:none|failed:none"
expect_invoice_line "Logout DOMXPath" "INVOICE_SOAP_XPATH_LOGOUT=PASS|assertions:3|session_cleared:yes|failed:none"
expect_invoice_line "All SOAP ops went through the production client" "INVOICE_SOAP_OPS_VIA_PRODUCTION_CLIENT=PASS|observed:Login,Login,CheckCounter,CheckUser,GetInvoiceSerial,SendInvoice,SendInvoice,GetInvoiceStatus,GetInvoice,EmailInvoice,Logout"

# Audit item 4: no locally invented fiscal document numbers.
expect_invoice_match "Local invoice numbering removed" "^INVOICE_NUMBER_LOCAL_GENERATION_REMOVED=PASS\|module_files_scanned:[0-9]{2,}\|mapper_generator_exists:no\|source_hits:none$"
expect_invoice_line "Numbering is fail-closed BLOCKED" "INVOICE_NUMBERING_FAIL_CLOSED_BLOCKED=PASS|code:invoice_numbering_unconfirmed|status:blocked|SendInvoice:0"
expect_invoice_line "Queue worker preserves the blocked status" "INVOICE_NUMBERING_BLOCKED_STATUS_PRESERVED=PASS|status_after_queue_worker:blocked"
expect_invoice_line "Mapper rejects an empty invoice number" "INVOICE_MAPPER_REJECTS_EMPTY_NUMBER=PASS|code:invoice_numbering_unconfirmed"
expect_invoice_line "Legacy numbers without EDM provenance are rejected" "INVOICE_NUMBERING_REJECTS_LEGACY_NUMBER=PASS|code:invoice_numbering_unconfirmed|status:blocked|SendInvoice:0|seeded_number_without_provenance:yes"
expect_invoice_line "A real send records the EDM number provenance" "INVOICE_SEND_RECORDS_EDM_PROVENANCE=PASS|SendInvoice:1|status:completed|number:KUK2026000000042|number_source:edm|error:none"

# Audit item 5: fiscal fallbacks removed, fail-closed instead.
expect_invoice_match "Fiscal fallbacks removed from production path" "^INVOICE_FISCAL_FALLBACKS_REMOVED=PASS\|module_files_scanned:[0-9]{2,}\|fallback_hits:none\|generic_vkn_occurrences:class-invoice-order-mapper\.php\(1\)$"
expect_invoice_line "e-Archive recipient alias is not invented" "INVOICE_EARCHIVE_ALIAS_NOT_INVENTED=PASS|receiver_alias:<empty>|document_type:earchive|profile:EARSIVFATURA"
expect_invoice_match "UBL builder fails closed on missing fiscal fields" "^INVOICE_UBL_BUILDER_FAIL_CLOSED=PASS\|cases:8\|control_builds:yes\|"

# Audit item 6: coupon and VAT arithmetic in kuruş.
expect_invoice_line "Kuruş fixture precision is in force" "INVOICE_MONETARY_FIXTURE_PRECISION=PASS|filtered_decimals:2|granularity_cents:1|stored_shop_decimals:0"
expect_invoice_match "Coupon and VAT kuruş invariants hold" "^INVOICE_COUPON_VAT_KURUS_INVARIANTS=PASS\|scenarios:7\|tax_subtotals_checked:[0-9]+\|"
expect_invoice_match "Coupon and VAT hold at the shop's own precision" "^INVOICE_COUPON_VAT_NATIVE_SHOP_PRECISION=PASS\|shop_decimals:0\|granularity_cents:100\|"
expect_invoice_line "Inconsistent fiscal data fails closed" "INVOICE_MONETARY_NEGATIVE_TESTS=PASS|codes:payable_total_mismatch=payable_total_mismatch,discount_allocation_mismatch=discount_allocation_mismatch,missing_tax_rate=missing_tax_rate|percent_normaliser:ok"

# Duplicate-send state machine through the production manager.
expect_invoice_line "Invoice lock for sent status" "INVOICE_LOCK_SENT_RECONCILE=PASS|SendInvoice:0|GetInvoiceStatus:1|error:none"
expect_invoice_line "Invoice lock for pending approval status" "INVOICE_LOCK_PENDING_RECONCILE=PASS|SendInvoice:0|GetInvoiceStatus:1|error:none"
expect_invoice_line "Invoice lock for sending status" "INVOICE_LOCK_SENDING_RECONCILE=PASS|SendInvoice:0|GetInvoiceStatus:1|error:none"
expect_invoice_line "Invoice network drop uncertain lock and reconciliation" "INVOICE_NETWORK_DROP_UNCERTAIN_LOCK=PASS|SendInvoice:1|GetInvoiceStatus:1|uncertain_status:send_uncertain|retry_error:none"
expect_invoice_line "Invoice reconciliation timeout lock" "INVOICE_RECONCILIATION_TIMEOUT_LOCK=PASS|SendInvoice:0|code:edm_soap_fault"
expect_invoice_line "Invoice terminal completed lock" "INVOICE_TERMINAL_COMPLETED_LOCK=PASS|SendInvoice:0|code:already_terminal_invoice"
expect_invoice_line "Order refund hook adds informative audit note" "INVOICE_REFUND_HANDLING=PASS|refund_note_added:yes"

# Audit item 8: the real read-only probe is PASS or explicitly BLOCKED, and it never sends.
expect_invoice_match "Real EDM login is PASS or explicitly BLOCKED" "^REAL_EDM_LOGIN=(PASS|BLOCKED)\|"
expect_invoice_match "Real EDM CheckCounter is PASS or explicitly BLOCKED" "^REAL_EDM_CHECK_COUNTER=(PASS|BLOCKED)\|"
expect_invoice_match "Real EDM logout is PASS or explicitly BLOCKED" "^REAL_EDM_LOGOUT=(PASS|BLOCKED)\|"
expect_invoice_line "Real EDM verification never calls SendInvoice" "REAL_EDM_SEND_INVOICE=SKIPPED|reason:read_only_verification_never_sends"

# Audit item 9: cleanup state machine and database keyset isolation.
expect_invoice_match "Every fixture is discoverable from the database" "^INVOICE_FIXTURE_DB_DISCOVERABLE=PASS\|run_meta:_kuka_isolation_run_id\|"
expect_invoice_line "Cleanup refuses unowned fixtures with a non-zero code" "INVOICE_CLEANUP_OWNERSHIP_REFUSAL=PASS|state:failed|refused:1|exit_code:1|record_preserved:yes"
expect_invoice_line "Cleanup coordinator blocks re-entry" "INVOICE_CLEANUP_REENTRY_GUARD=PASS|reentry_blocked:yes|state:failed"
expect_invoice_line "Cleanup state machine reaches succeeded for the probe run" "INVOICE_CLEANUP_STATE_MACHINE_PROBE=PASS|state:succeeded|considered:1|refused:0|leftover:0|exit_code:0"
expect_invoice_line "Cleanup state machine reaches succeeded for the main run" "INVOICE_CLEANUP_STATE_MACHINE_MAIN=PASS|state:succeeded|considered:0|refused:0|leftover:0|reentry_blocked:yes|registry_remaining:0"
expect_invoice_match "Invoice test database keyset internal isolation" "^INVOICE_TEST_DATABASE_ISOLATION=PASS\|tables:12\|missing:none\|pre_hash:[0-9a-f]+\|post_hash:[0-9a-f]+\|diff:none$"
expect_invoice_line "Invoice test database keyset external isolation" "INVOICE_EXTERNAL_ISOLATION=keyset_match:yes"

# EDM sandbox harness: fixture and mock proofs, no network and no document.
expect_value "credential mount is reachable only by the allow-listed script" "$edm_runner_leaks" "0"
expect_value "allow-listed script reaches the credential gate" "$edm_runner_allowlist_ok" "yes"
expect_sandbox_match "credential parser keeps values verbatim" "^SANDBOX_CRED_PARSER_VERBATIM=PASS\\|keys_recognised:6\\|equals_in_value_preserved:yes\\|trailing_space_preserved:yes\\|quotes_preserved:yes\\|crlf_handled:yes\\|unknown_key_ignored:yes$"
# CheckUser is a GİB e-Invoice registry lookup, so an e-Archive sender is proved
# against the independent portal fixture instead. The e-Invoice path keeps the
# registry requirement unchanged.
expect_sandbox_line "the portal sender fixture is independent of the config" "SANDBOX_SENDER_FIXTURE_IS_INDEPENDENT=PASS|fields:7|deterministic:yes|parameters:0|derives_from_config:no|postcode_listed:no"
expect_sandbox_line "the sender fixture is released only for the proved test endpoint" "SANDBOX_SENDER_FIXTURE_TEST_ONLY=PASS|test_label_verified_url:released|live_label_verified_url:withheld|test_label_live_url:withheld|live_label_live_url:withheld"
expect_sandbox_match "profile-aware sender verification is fail-closed" "^SANDBOX_SENDER_PROFILE_MATRIX=PASS\\|cases:1[0-9]\\|wrong:none$"
expect_sandbox_line "the report names its sender identity authority" "SANDBOX_SENDER_IDENTITY_SOURCE_LABELS=PASS|earchive:sender_identity_source=portal_verified_test_fixture,check_user_role=einvoice_registry_lookup,check_user_requirement=not_applicable_for_earchive_sender,check_user_result=user_entry_absent,check_user_blocking=no|einvoice:sender_identity_source=edm_checkuser_registry_alias,check_user_requirement=required_for_einvoice_sender,check_user_blocking=yes"
expect_sandbox_line "blocked guidance names the real failure" "SANDBOX_SENDER_GUIDANCE_IS_ACCURATE=PASS|mismatch_names_field:yes|mismatch_avoids_missing_text:yes|missing_asks_for_field:yes|registry_explained:yes|passing_run_silent:yes"
expect_sandbox_match "LoadInvoice response parser fixtures" "^SANDBOX_LOAD_RESPONSE_PARSER=PASS\\|fixtures:12\\|"
expect_sandbox_match "readback verdict is fail-closed" "^SANDBOX_READBACK_VERDICT_FAIL_CLOSED=PASS\\|"
expect_sandbox_line "only one process may hold the write claim" "SANDBOX_CLAIM_SINGLE_HOLDER=PASS|first_acquire:yes|second_acquire:no"
expect_sandbox_line "claiming requires the lock" "SANDBOX_CLAIM_REQUIRES_LOCK=PASS|reason:lock_not_held"
expect_sandbox_line "claim moves idle to in_flight and refuses a second claim" "SANDBOX_CLAIM_IDLE_TO_IN_FLIGHT=PASS|first:in_flight/written|second_refused:claim_refused_from_state_in_flight"
expect_sandbox_line "claim state file is mode 600" "SANDBOX_CLAIM_STATE_FILE_MODE_600=PASS|mode:600"
expect_sandbox_line "transport uncertainty blocks a second write" "SANDBOX_CLAIM_TIMEOUT_UNCERTAIN_NO_SECOND_WRITE=PASS|settled:uncertain|second_write:claim_refused_from_state_uncertain"
expect_sandbox_line "reconciliation reset needs absence evidence" "SANDBOX_CLAIM_RECONCILE_REQUIRES_EVIDENCE=PASS|weak_evidence:reset_requires_document_absent_evidence|strong_evidence:idle"
expect_sandbox_line "terminal claim states refuse a further write" "SANDBOX_CLAIM_TERMINAL_STATES_REFUSE=PASS|confirmed:refused|failed_definitive:refused"
expect_sandbox_line "settle transitions are guarded" "SANDBOX_CLAIM_SETTLE_GUARDS=PASS|from_idle:settle_refused_from_state_idle|bad_target:invalid_target_state"
expect_sandbox_line "state write failure is never reported as recorded" "SANDBOX_CLAIM_STATE_WRITE_FAILURE_REPORTED=PASS|lock:yes|written:no|reason:state_persist_failed"
expect_sandbox_line "sandbox harness leaves no temporary files" "SANDBOX_HARNESS_TEMP_CLEANED=PASS|temp_root_removed:yes"
expect_sandbox_match "plugin gained no document-creating capability" "^SANDBOX_PLUGIN_HAS_NO_WRITE_CAPABILITY=PASS\\|module_files:[0-9]{2,}\\|hits:none$"
expect_sandbox_line "production numbering guard untouched" "SANDBOX_NUMBERING_GUARD_UNTOUCHED=PASS|invoice_numbering_unconfirmed:present|provenance_required:present"

# Corrupt claim records, call classification and the driver write path.
expect_sandbox_match "corrupt claim records are fail-closed" "^SANDBOX_STATE_CORRUPTION_FAIL_CLOSED=PASS\\|cases:11\\|missing_file=idle "
expect_sandbox_line "a corrupt record blocks the write path entirely" "SANDBOX_CORRUPT_STATE_BLOCKS_WRITE=PASS|empty_file:calls=0|malformed_json:calls=0|unknown_state:calls=0|partial_in_flight:calls=0"
expect_sandbox_match "only a complete rejection is definitive" "^SANDBOX_CALL_CLASSIFICATION=PASS\\|cases:9\\|nonzero_return_code=definitive_rejection "
expect_sandbox_match "driver write path verdicts" "^SANDBOX_DRIVER_WRITE_PATH=PASS\\|cases:6\\|success=PASS/draft_uploaded/record=confirmed "
expect_sandbox_line "settle persist failure is never PASS or confirmed" "SANDBOX_SETTLE_PERSIST_FAILURE_NOT_CONFIRMED=PASS|calls:1|classification:success|verdict:FAIL|label:state_persist_failed_manual_reconciliation_required|state_recorded:no|exit:1|number_available:yes|record_state:in_flight|second_write:refused"
expect_sandbox_line "an uncertain record makes no second call" "SANDBOX_UNCERTAIN_SECOND_RUN_NO_WRITE=PASS|calls:0|claimed:no|reason:claim_refused_from_state_uncertain"
# The documented EDM test values (PROFILEID and the generic individual
# recipient) are usable on the test endpoint only, the artificial "EDM must
# confirm it in writing" gate is gone, and neither value reaches production.
# The real get_wsdl() value is allow-listed before Login. The environment label
# alone never unlocks the sandbox fixture identities.
expect_sandbox_match "only the EDM test WSDL is accepted" "^SANDBOX_ENDPOINT_ALLOWLIST=PASS\\|cases:[0-9]{2,}\\|accepted:2\\|refused:[0-9]{2,}\\|wrong:none\\|config_default_test:accepted\\|config_default_live:refused$"
expect_sandbox_line "a padded canonical WSDL is refused, not trimmed" "SANDBOX_ENDPOINT_REJECTS_PADDING=PASS|pad_bytes:9|variants:18|leaked:none|unpadded_canonical:accepted"
expect_sandbox_line "the endpoint verifier never normalises its input" "SANDBOX_ENDPOINT_DOES_NOT_NORMALISE=PASS|verifier_located:yes|trim_calls:none|raw_bytes_validated:yes"
expect_sandbox_line "the endpoint is proved before Login" "SANDBOX_ENDPOINT_CHECKED_BEFORE_LOGIN=PASS|verifier_present:yes|reads_real_get_wsdl:yes|before_login:yes|blocked_line_states_no_login:yes"
expect_sandbox_line "sandbox fixture identities need the proved endpoint, not the label" "SANDBOX_DEFAULTS_TEST_ENDPOINT_ONLY=PASS|test_label_and_verified_url:resolved|live_both:refused|test_label_live_url:refused|live_label_test_url:refused|live_with_override:refused|values_leaked:none|reason:sandbox_values_refused_without_verified_test_endpoint"
expect_sandbox_line "sandbox overrides are format and safety checked" "SANDBOX_OVERRIDE_VALIDATION=PASS|cases:10|wrong:none"
expect_sandbox_line "the PROFILEID written-confirmation gate is gone" "SANDBOX_PROFILE_CONFIRMATION_GATE_REMOVED=PASS|function_exists:no|credential_key:absent|sources_scanned:4|hits:none|sandbox_keys:2"
expect_sandbox_match "sandbox defaults never reach production" "^SANDBOX_DEFAULTS_NOT_IN_PRODUCTION=PASS\\|module_files:[0-9]{2,}\\|sandbox_references:none\\|generic_receiver_still_policy_gated:yes$"
expect_sandbox_line "serial selection is optional but never sloppy" "SANDBOX_SERIES_OPTIONAL=PASS|not_configured:omitted|not_configured_dark:omitted|registered:sent|unregistered:blocked|query_failed:blocked|bad_format_long:blocked|bad_format_lower:blocked|bad_format_symbol:blocked"
expect_sandbox_line "LoadInvoice request shape is fixed" "SANDBOX_LOAD_REQUEST_SHAPE=PASS|no_series:generate_on_load=true,invoiceserial=absent,invoice_id=absent|with_series:generate_on_load=true,invoiceserial=present,invoice_id=absent"

# One shared REQUEST_HEADER generator for production and sandbox, and a UBL that
# carries EDM's portal-serial placeholder instead of having cbc:ID stripped out.
expect_sandbox_line "the LoadInvoice header carries all eight contract fields" "SANDBOX_LOAD_REQUEST_HEADER_CONTRACT=PASS|fields:8|order_matches_contract:yes|duplicates:none|wrong_values:none|reason:LoadInvoice|hostname:kukaisland|channel:WEB|compressed:N|client_txn_id_is_uuid:yes"
expect_sandbox_line "production and sandbox share one header generator" "SANDBOX_HEADER_GENERATOR_IS_SHARED=PASS|sandbox_uses_shared_builder:yes|sandbox_own_header_literals:none|builder_is_pure_static:yes"
expect_sandbox_line "the UBL carries EDM's portal-serial placeholder" "SANDBOX_UBL_CBC_ID_PLACEHOLDER=PASS|cbc_id_count:1|cbc_id:ABC2009123456789|matches_literal:yes|dom_removal_code:removed|old_placeholder:gone"
expect_sandbox_line "the UBL id and the SOAP invoice id are independent" "SANDBOX_REQUEST_KEEPS_UBL_ID_AND_OMITS_SOAP_ID=PASS|soap_invoice_id_attribute:absent|ubl_cbc_id_in_content:present|generate_invoice_id_on_load:true"
expect_sandbox_line "with no serial the request lets EDM assign the number" "SANDBOX_NO_SERIES_LOAD_REQUEST=PASS|calls:1|operation:LoadInvoice|generate_invoice_id_on_load:true|invoiceserial_requested:absent|invoice_id_attribute:absent|verdict:PASS|label:draft_uploaded|edm_assigned_number:read_back"
expect_sandbox_line "LoadInvoice uploads a draft and SendInvoice is never called" "SANDBOX_LOAD_VS_SEND_SEMANTICS=PASS|transport_operations:LoadInvoice|forbidden_calls:none|success_label:draft_uploaded|sendinvoice_line:present|draft_step:present"
expect_sandbox_line "an uncertain write carries a safe fault classification" "SANDBOX_UNCERTAIN_WRITE_CARRIES_SAFE_FAULT=PASS|calls:1|classification:uncertain|fault_line_shape:ok|remote_text_leaked:none"
expect_sandbox_line "state fixtures are cleaned up" "SANDBOX_STATE_FIXTURES_CLEANED=PASS|temp_root_removed:yes"
expect_iyzico_line "a cancelled order is not treated as paid" "IYZICO_CANCELLED_NOT_PAID=yes"
expect_line "contact has one company and one support block" "CONTACT_SHORTCODES=company:1|support:1"
expect_line "unknown legal values stay hidden" "APPLICATION_LEGAL_ROWS=mersis:0|kep:0|chamber:0|rules:0|etbis:0"
expect_line "footer payment logos and CSS are absent" "FOOTER_PAYMENT_LOGOS=absent"
expect_line "footer payment panel field is retired" "FOOTER_PAYMENT_PANEL_FIELD=absent"
expect_line "footer site e-mail stays hidden" "FOOTER_SITE_EMAIL=absent"
expect_line "theme payment asset directory is absent" "THEME_PAYMENT_ASSETS=absent"
expect_line "checkout keeps the plugin-owned card strip" "CHECKOUT_CARD_STRIP_ASSET=plugin-owned"
expect_line "payment color asset exceptions stay at zero" "PAYMENT_COLOR_ASSET_EXCEPTIONS=0"
expect_line "four Coming Soon media files" "COMING_SOON_MEDIA_FILES=4/4"
expect_line "measured Coming Soon video bytes" "COMING_SOON_VIDEO_BYTES=desktop:4542803|mobile:2213198"
expect_line "responsive muted looping Coming Soon video" "COMING_SOON_VIDEO_CONTRACT=responsive+autoplay+muted+loop+playsinline"
expect_line "reduced motion keeps only the poster" "COMING_SOON_REDUCED_MOTION=poster-only"
expect_line "home hero reuses responsive muted video" "HOME_HERO_VIDEO=responsive+muted+poster-fallback"
expect_line "checkout mutations do not steal focus" "CHECKOUT_MUTATION_FOCUS=stable"
expect_line "legal consents survive checkout fragments" "LEGAL_CONSENT_FRAGMENT_STATE=preserved"
expect_line "language hot path is memoized" "LANGUAGE_HOT_PATH_CACHE=memoized"
expect_line "footer WhatsApp uses phone helper" "FOOTER_WHATSAPP_SOURCE=phone-helper"
expect_line "WhatsApp empty and derived URL rule" "WHATSAPP_PHONE_RULE=empty-hidden|number-derived"
expect_line "updated bilingual hero title" "HOME_HERO_TITLES=Kaçışınız için tasarlandı. Est. 2026|Designed for your escape. Est. 2026"
expect_line "updated bilingual editorial title" "HOME_EDITORIAL_TITLES=Sonsuz yazlar için tasarlandı|Designed for endless summers"
expect_line "mobile Safari arrows use text presentation" "MOBILE_SAFARI_ARROWS=text"
expect_line "hero Est. 2026 is on its own line" "HERO_EST_LINE=separate"
expect_line "language hover keeps color and adds underline" "LANGUAGE_HOVER=same-color+underline"
expect_line "story media waits for target image and warms the next" "STORY_MEDIA_HANDOFF=load-guarded+next-warmed"
expect_line "SMTP constant names are absent from the database" "SMTP_CONFIG_DATABASE_ROWS=0"
expect_value "only the output-suppressed core installer uses a prompt" "$prompted_passwords" "1"
expect_value "installation passwords never enter process arguments" "$password_argv" "0"
expect_value "manager password is consumed directly from stdin" "$password_stdin_readers" "1"
expect_value "GitHub Actions use immutable commits" "$mutable_actions" "0"
expect_value "both GitHub Actions are SHA-pinned" "$pinned_actions" "2"
expect_value "GitHub workflow declares least privilege" "$workflow_permissions" "1"
expect_value "local privileged usernames are not fixed in tracked sources" "$fixed_local_usernames" "0"
expect_line "site e-mail seed" "SITE_EMAIL=info@kukaisland.com"
expect_email_line "Exception cannot abort checkout mail" "THROWABLE_EXCEPTION_CAUGHT=yes"
expect_email_line "Error cannot abort checkout mail" "THROWABLE_ERROR_CAUGHT=yes"
expect_email_line "failed order mail creates two notes" "THROWABLE_ORDER_NOTES=2/2"
expect_email_line "PHP mail is actually disabled in acceptance process" "PHP_MAIL_FUNCTION=disabled"
expect_email_line "disabled PHP mail fails safely" "DISABLED_MAIL_SAFE=yes"
expect_email_line "disabled PHP mail creates an order note" "DISABLED_MAIL_ORDER_NOTE=yes"
expect_email_line "disabled PHP mail appears on Start" "DISABLED_MAIL_START_WARNING=yes"
expect_email_line "failed order mail appears on Start" "FAILED_EMAIL_START_WARNING=yes"
expect_email_line "configured PHPMailer uses SMTP" "SMTP_TRANSPORT=smtp"
expect_email_line "sender address and name are fixed" "SMTP_IDENTITY=fixed"
expect_email_line "WordPress and WooCommerce sender addresses match" "MAIL_FROM_IDENTITIES=wp=woo:info@kukaisland.com"
expect_email_line "reply-to remains separate" "SMTP_REPLY_TO=separate"
expect_email_line "WooCommerce resend actions remain native" "ORDER_RESEND_ACTIONS=customer+admin"
expect_value "theme POT" "$theme_pot" "yes"
expect_value "plugin POT" "$plugin_pot" "yes"
expect_value "raw theme colors" "$raw_colors" "0"
expect_value "raw theme pixel values" "$raw_px" "0"
expect_value "theme shadows" "$shadows" "0"
expect_value "root overflow mask" "$overflow_masks" "0"
expect_value "undefined CSS tokens" "$undefined_tokens" "0"
expect_value "single-record notification only" "$newsletter_mail_calls" "1"
expect_value "newsletter has no blue" "$newsletter_blue" "0"
expect_value "SVG uploads remain disabled" "$svg_upload_filters" "0"
expect_value "vendor files untouched" "$vendor_changes" "0"
expect_value "legacy English metaboxes removed" "$legacy_english_boxes" "0"
expect_value "Site Appearance tabs" "$panel_tabs" "1"
expect_value "Site Appearance field search" "$panel_search" "2"
expect_value "management map routes" "$management_map" "2"
expect_value "product publication checklist" "$product_checklist" "2"
expect_value "hero sentence and Est lines" "$hero_main_line" "2"
expect_value "SMTP secret has no output sink" "$smtp_secret_output_sinks" "0"

if [ "$failures" -ne 0 ]; then
  echo "VERIFY=FAIL ($failures)" >&2
  exit 1
fi

./scripts/smoke.sh
echo "VERIFY=PASS"
