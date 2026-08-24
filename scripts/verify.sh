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

email_throwables=$(docker compose run --rm -T wp-cli php /project-scripts/verify-email-delivery.php throwables)
email_disabled_mail=$(docker compose run --rm -T wp-cli php -d disable_functions=mail /project-scripts/verify-email-delivery.php disabled-mail)
email_smtp=$(docker compose run --rm -T wp-cli php /project-scripts/verify-email-delivery.php smtp)
printf '%s\n%s\n%s\n' "$email_throwables" "$email_disabled_mail" "$email_smtp"

response_headers=$(curl -fsS -D - -o /dev/null "$WP_URL/" | tr -d '\r')
security_txt=$(curl -fsSL --max-redirs 3 "$WP_URL/.well-known/security.txt")
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
    grep -ERn --include='*.php' --include='*.js' --include='*.css' --include='*.sh' -- "$pattern" "$@" 2>/dev/null | wc -l | tr -d ' '
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
expect_line "WordPress security release" "WP_VERSION=7.0.4"
expect_line "WooCommerce security maintenance release" "WOOCOMMERCE_VERSION=11.0.1"
expect_line "Blocksy security maintenance release" "BLOCKSY_VERSION=2.1.53"
expect_line "Blocksy Companion security maintenance release" "BLOCKSY_COMPANION_VERSION=2.1.53"
expect_line "Loginizer race-condition fix" "LOGINIZER_VERSION=2.1.0"
expect_line "security header module" "SECURITY_HEADER_MODULE=ready"
expect_line "CSP keeps iyzico checkout sources" "SECURITY_CSP_IYZICO=allowed"
expect_value "public response security headers" "$security_header_contract" "csp:yes|nosniff:yes|referrer:yes|frame:yes|permissions:yes"
expect_value "HSTS stays off on local HTTP" "$hsts_local" "absent"
expect_value "RFC 9116 security contact" "$security_txt_contract" "contact:yes|canonical:yes"
expect_line "six-item header menu" "PRIMARY_MENU_COUNT=6"
expect_line "daily manager" "DAILY_MANAGER=yes"
expect_line "Coming Soon remains enabled" "STORE_VISIBILITY=coming-soon"
expect_line "Coming Soon covers the whole site" "COMING_SOON_SCOPE=whole-site"
expect_line "search engines remain blocked" "SEARCH_ENGINE_VISIBILITY=noindex"
expect_line "private acceptance preview" "PRIVATE_PREVIEW=ready"
expect_line "measured Site Appearance inventory" "SITE_APPEARANCE_INVENTORY=13_groups|113_rows|154_controls"
expect_line "classic checkout" "CHECKOUT_CLASSIC=yes"
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
expect_line "retired panel fields removed" "RETIRED_PANEL_FIELDS="
expect_line "hero overlay layer removed" "HERO_OVERLAY_LAYER=absent"
expect_line "header top and scrolled modes" "HEADER_TOP_MODE=photo-white-to-paper-dark"
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
expect_line "iyzico automatic readiness" "IYZICO_APPLICATION_READINESS=7/12|missing:5"
expect_line "all readiness rows link to their screen" "IYZICO_APPLICATION_LINKS=12/12"
expect_line "manual iyzico documents start unchecked" "IYZICO_MANUAL_DOCUMENTS=0/5"
expect_line "contact has one company and one support block" "CONTACT_SHORTCODES=company:1|support:1"
expect_line "unknown legal values stay hidden" "APPLICATION_LEGAL_ROWS=mersis:0|kep:0|chamber:0|rules:0|etbis:0"
expect_line "footer payment logos and CSS are absent" "FOOTER_PAYMENT_LOGOS=absent"
expect_line "footer payment panel field is retired" "FOOTER_PAYMENT_PANEL_FIELD=absent"
expect_line "footer site e-mail stays hidden" "FOOTER_SITE_EMAIL=absent"
expect_line "theme payment asset directory is absent" "THEME_PAYMENT_ASSETS=absent"
expect_line "checkout keeps the plugin-owned card strip" "CHECKOUT_CARD_STRIP_ASSET=plugin-owned"
expect_line "payment color asset exceptions stay at zero" "PAYMENT_COLOR_ASSET_EXCEPTIONS=0"
expect_line "four Coming Soon media files" "COMING_SOON_MEDIA_FILES=4/4"
expect_line "measured Coming Soon video bytes" "COMING_SOON_VIDEO_BYTES=desktop:13660330|mobile:10905271"
expect_line "responsive muted looping Coming Soon video" "COMING_SOON_VIDEO_CONTRACT=responsive+autoplay+muted+loop+playsinline"
expect_line "reduced motion keeps only the poster" "COMING_SOON_REDUCED_MOTION=poster-only"
expect_line "home hero reuses responsive muted video" "HOME_HERO_VIDEO=responsive+muted+poster-fallback"
expect_line "footer WhatsApp uses phone helper" "FOOTER_WHATSAPP_SOURCE=phone-helper"
expect_line "WhatsApp empty and derived URL rule" "WHATSAPP_PHONE_RULE=empty-hidden|number-derived"
expect_line "updated bilingual hero title" "HOME_HERO_TITLES=Kaçışınız için tasarlandı. Est. 2026|Designed for your escape. Est. 2026"
expect_line "updated bilingual editorial title" "HOME_EDITORIAL_TITLES=Sonsuz yazlar için tasarlandı|Designed for endless summers"
expect_line "mobile Safari arrows use text presentation" "MOBILE_SAFARI_ARROWS=text"
expect_line "hero Est. 2026 is on its own line" "HERO_EST_LINE=separate"
expect_line "language hover keeps color and adds underline" "LANGUAGE_HOVER=same-color+underline"
expect_line "story media waits for target image and warms the next" "STORY_MEDIA_HANDOFF=load-guarded+next-warmed"
expect_line "SMTP constant names are absent from the database" "SMTP_CONFIG_DATABASE_ROWS=0"
expect_value "installation passwords never enter an interactive log" "$prompted_passwords" "0"
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
