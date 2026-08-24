#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$project_dir"
./scripts/ensure-env.sh
set -a
. "$project_dir/.env"
set +a

temporary_dir=$(mktemp -d)
trap 'rm -r "$temporary_dir"' EXIT HUP INT TERM
cookie_jar="$temporary_dir/cookies"
preview_cookie_jar="$temporary_dir/preview-cookies"

# Storefront acceptance runs through WooCommerce's private preview link while
# the public site remains in Coming Soon mode. The key is read locally and is
# never printed or committed.
share_key=$(docker compose run --rm -T wp-cli wp option get woocommerce_share_key 2>/dev/null)
[ -n "$share_key" ] || { echo "FAIL missing WooCommerce private preview key" >&2; exit 1; }
cookie_host=$(printf '%s' "$WP_URL" | sed -E 's,^[a-z]+://,,; s,/.*,,; s,:.*,,')
[ -n "$cookie_host" ] || { echo "FAIL invalid WP_URL cookie host" >&2; exit 1; }
printf '%s\tFALSE\t/\tFALSE\t0\twoo-share\t%s\n' "$cookie_host" "$share_key" > "$preview_cookie_jar"
unset share_key
curl() { command curl -b "$preview_cookie_jar" "$@"; }

pass() { echo "PASS $1"; }
fail() { echo "FAIL $1" >&2; exit 1; }
fetch() {
  url=$1
  output=$2
  code=$(curl -sS -L -o "$output" -w '%{http_code}' "$url")
  [ "$code" = "200" ] || fail "$url HTTP $code"
}

assert_english_links() {
  html=$1
  label=$2
  base=${WP_URL%/}
  links=$(grep -Eo '<a[^>]+href="[^"]+"[^>]*' "$html" 2>/dev/null | grep -v 'hreflang="tr"' | sed -E 's/^.*href="([^"]+)".*$/\1/' || true)
  count=0
  for link in $links; do
    case "$link" in
      "$base"/|"$base"/en|"$base"/en/*|"$base"/wp-*|"$base"/xmlrpc.php*|"$base"/?wc-ajax=*|\#*|mailto:*|tel:*|https://wa.me/*|https://www.instagram.com/*) ;;
      "$base"/*) count=$((count + 1)) ;;
    esac
  done
  [ "$count" = "0" ] || fail "$label unprefixed internal hrefs ($count)"
  echo "ENGLISH_LINK_SCAN=$label:0"
}

fetch "$WP_URL" "$temporary_dir/home.html"
grep -q 'class="kuka-hero' "$temporary_dir/home.html" || fail "home hero"
! grep -Eqi 'kuka-account|Hesabım|Hesap oluştur|Üye ol|Giriş yap' "$temporary_dir/home.html" || fail "home account copy"
pass "home 200 + hero"
fetch "${WP_URL%/}/en/" "$temporary_dir/home-en.html"
grep -q '<html lang="en-US"' "$temporary_dir/home-en.html" || fail "English home locale"
grep -q 'hreflang="tr"' "$temporary_dir/home-en.html" && grep -q 'hreflang="en"' "$temporary_dir/home-en.html" && grep -q 'hreflang="x-default"' "$temporary_dir/home-en.html" || fail "English home hreflang"
assert_english_links "$temporary_dir/home-en.html" home
fetch "${WP_URL%/}/wp-sitemap.xml" "$temporary_dir/sitemap.xml"
grep -q 'wp-sitemap-english-1.xml' "$temporary_dir/sitemap.xml" || fail "English sitemap provider"
fetch "${WP_URL%/}/wp-sitemap-english-1.xml" "$temporary_dir/sitemap-en.xml"
grep -q '/en/kategori/bikini-ustleri/' "$temporary_dir/sitemap-en.xml" || fail "English taxonomy sitemap"
! grep -q '/en/hesabim/' "$temporary_dir/sitemap-en.xml" || fail "redirecting account page in sitemap"

fetch "${WP_URL%/}/magaza/" "$temporary_dir/catalog.html"
catalog_count=$(grep -o 'data-product-card' "$temporary_dir/catalog.html" | wc -l | tr -d ' ')
fetch "${WP_URL%/}/magaza/?ki_color%5B%5D=kobalt" "$temporary_dir/catalog-filtered.html"
filtered_count=$(grep -o 'data-product-card' "$temporary_dir/catalog-filtered.html" | wc -l | tr -d ' ')
[ "$catalog_count" -gt 0 ] && [ "$filtered_count" -gt 0 ] && [ "$catalog_count" -ne "$filtered_count" ] || fail "catalog filter result change"
grep -q 'kuka-active-filters' "$temporary_dir/catalog-filtered.html" || fail "catalog active filter"
pass "catalog cards + filter changes result ($catalog_count -> $filtered_count)"
fetch "${WP_URL%/}/en/magaza/" "$temporary_dir/catalog-en.html"
grep -q 'lang="en-US"' "$temporary_dir/catalog-en.html" || fail "English catalog locale"
grep -q '>Filter<' "$temporary_dir/catalog-en.html" || fail "English catalog controls"
assert_english_links "$temporary_dir/catalog-en.html" catalog

product_data=$(docker compose run --rm -T wp-cli wp eval '
$products=wc_get_products(array("type"=>"variable","limit"=>-1));
foreach($products as $product){$in=null;$out=null;foreach($product->get_children() as $id){$variation=wc_get_product($id);if(!$variation instanceof WC_Product_Variation){continue;}if($variation->is_in_stock()&&!$in){$in=$variation;}if(!$variation->is_in_stock()&&!$out){$out=$variation;}}if($in&&$out){$a=$in->get_attributes();$b=$out->get_attributes();echo implode("|",array($product->get_id(),$product->get_permalink(),$in->get_id(),$a["pa_renk"]??"",$a["pa_beden"]??"",$out->get_id(),$b["pa_beden"]??""));break;}}')
IFS='|' read -r product_id product_url variation_id color size out_variation_id out_size <<EOF
$product_data
EOF
[ -n "$out_variation_id" ] || fail "product stock fixtures"
fetch "$product_url" "$temporary_dir/product.html"
grep -q 'form class="variations_form' "$temporary_dir/product.html" || fail "product variation form"
grep -q "\"id\":$out_variation_id" "$temporary_dir/product.html" || fail "out-of-stock variation data"
grep -q '"available":false' "$temporary_dir/product.html" || fail "out-of-stock availability"
pass "product variation + out-of-stock size ($out_size)"
english_product_url=$(printf '%s' "$product_url" | sed "s#${WP_URL%/}#${WP_URL%/}/en#")
fetch "$english_product_url" "$temporary_dir/product-en.html"
grep -q 'lang="en-US"' "$temporary_dir/product-en.html" || fail "English product locale"
grep -q "canonical.*${WP_URL%/}/en/urun/" "$temporary_dir/product-en.html" || fail "English product canonical"
assert_english_links "$temporary_dir/product-en.html" product

add_code=$(curl -sS -L -c "$cookie_jar" -b "$cookie_jar" -o "$temporary_dir/added.html" -w '%{http_code}' -X POST "$english_product_url" \
  --data-urlencode "add-to-cart=$product_id" \
  --data-urlencode "product_id=$product_id" \
  --data-urlencode "variation_id=$variation_id" \
  --data-urlencode 'quantity=1' \
  --data-urlencode "attribute_pa_renk=$color" \
  --data-urlencode "attribute_pa_beden=$size")
[ "$add_code" = "200" ] || fail "add to cart HTTP $add_code"
cart_code=$(curl -sS -L -c "$cookie_jar" -b "$cookie_jar" -o "$temporary_dir/cart.html" -w '%{http_code}' "${WP_URL%/}/en/sepet/")
[ "$cart_code" = "200" ] || fail "cart HTTP $cart_code"
grep -q "$color" "$temporary_dir/cart.html" && grep -q "$size" "$temporary_dir/cart.html" || fail "correct cart variation"
grep -q 'lang="en-US"' "$temporary_dir/cart.html" || fail "English cart locale"
assert_english_links "$temporary_dir/cart.html" cart
pass "correct variation added to cart ($variation_id: $color/$size)"

# A new HTTP client process reuses only WooCommerce's cookie jar. This models a
# browser restart: no localStorage or custom cart store participates.
restart_cart_code=$(curl -sS -L -b "$cookie_jar" -o "$temporary_dir/cart-after-restart.html" -w '%{http_code}' "${WP_URL%/}/en/sepet/")
[ "$restart_cart_code" = "200" ] || fail "cart after restart HTTP $restart_cart_code"
grep -q "$color" "$temporary_dir/cart-after-restart.html" && grep -q "$size" "$temporary_dir/cart-after-restart.html" || fail "guest cart cookie persistence"

checkout_code=$(curl -sS -L -c "$cookie_jar" -b "$cookie_jar" -o "$temporary_dir/checkout.html" -w '%{http_code}' "${WP_URL%/}/en/odeme/")
[ "$checkout_code" = "200" ] || fail "checkout HTTP $checkout_code"
[ "$(grep -o 'name="kuka_[^"]*_accepted"' "$temporary_dir/checkout.html" | sort -u | wc -l | tr -d ' ')" = "2" ] || fail "checkout legal consents"
grep -q 'id="place_order"' "$temporary_dir/checkout.html" || fail "checkout payment button"
grep -q 'I have read and accept the Pre-information Form' "$temporary_dir/checkout.html" || fail "English checkout legal consent"
! grep -q 'admin@kukaisland.test' "$temporary_dir/checkout.html" || fail "checkout administrator email leak"
grep -q 'placeholder="5XX XXX XX XX"' "$temporary_dir/checkout.html" && grep -Fq 'pattern="5[0-9]{2} [0-9]{3} [0-9]{2} [0-9]{2}"' "$temporary_dir/checkout.html" || fail "checkout Turkish mobile input contract"
assert_english_links "$temporary_dir/checkout.html" checkout
# Raw HTML may contain translated account words inside third-party script data
# even when no control is rendered. Assert the actual storefront structures;
# visible text is covered separately by the browser acceptance scan.
! grep -Eqi 'id="createaccount"|woocommerce-form-login-toggle|kuka-account' "$temporary_dir/checkout.html" || fail "checkout account UI"
[ "$(grep -c 'name="kuka_[a-z_]*_accepted" value="1" required' "$temporary_dir/checkout.html" | tr -d ' ')" -ge 2 ] || fail "checkout consent required attribute"

# Onay kapısı sunucuda doğrulanır: JS kapalıyken de onaysız gönderim reddedilmeli.
checkout_nonce=$(grep -o 'name="woocommerce-process-checkout-nonce" value="[^"]*"' "$temporary_dir/checkout.html" | head -1 | sed 's/.*value="//;s/"$//')
[ -n "$checkout_nonce" ] || fail "checkout nonce"
curl -sS -L -c "$cookie_jar" -b "$cookie_jar" -o "$temporary_dir/checkout-no-consent.html" -X POST "${WP_URL%/}/en/odeme/" \
	--data-urlencode 'billing_first_name=' --data-urlencode 'billing_last_name=Testi' \
  --data-urlencode 'billing_email=duman@example.com' --data-urlencode 'billing_phone=123' \
  --data-urlencode 'billing_customer_type=personal' --data-urlencode 'billing_country=TR' \
  --data-urlencode 'billing_address_1=Ata Sk 2' --data-urlencode 'billing_postcode=34335' \
  --data-urlencode 'billing_city=Besiktas' --data-urlencode 'billing_state=TR34' \
  --data-urlencode 'payment_method=iyzico' \
  --data-urlencode "woocommerce-process-checkout-nonce=$checkout_nonce" \
  --data-urlencode '_wp_http_referer=/en/odeme/' \
  --data-urlencode 'woocommerce_checkout_place_order=Siparis ver' >/dev/null
grep -q 'Acceptance of the Pre-information Form is required' "$temporary_dir/checkout-no-consent.html" || fail "checkout consent gate (preinfo)"
grep -q 'Acceptance of the Distance Sales Agreement is required' "$temporary_dir/checkout-no-consent.html" || fail "checkout consent gate (distance sales)"
grep -q '<strong>First name</strong> is a required field' "$temporary_dir/checkout-no-consent.html" || fail "English natural checkout field notice"
! grep -q '<strong>Billing First name</strong>' "$temporary_dir/checkout-no-consent.html" || fail "English prefixed checkout field notice"
grep -q 'id="billing_first_name" aria-invalid="true" aria-describedby="billing_first_name_error"' "$temporary_dir/checkout-no-consent.html" || fail "English no-JS invalid field association"
grep -q 'id="billing_first_name_error">This field is required\.' "$temporary_dir/checkout-no-consent.html" || fail "English no-JS inline required message"
grep -q 'The phone number must use the 5XX XXX XX XX format\.' "$temporary_dir/checkout-no-consent.html" || fail "English phone format validation"
grep -q 'id="billing_phone_error">The phone number must use the 5XX XXX XX XX format\.' "$temporary_dir/checkout-no-consent.html" || fail "English no-JS inline phone association"
grep -q 'data-id="kuka_preinfo_accepted"' "$temporary_dir/checkout-no-consent.html" || fail "English consent error field association"
perl -0ne 'exit !(/data-checkout-notices-inner>.*?woocommerce-error.*?<\/div>\s*<\/div>\s*<aside/s)' "$temporary_dir/checkout-no-consent.html" || fail "server checkout notices inside grid region"
pass "checkout rendered + two-consent payment gate"

# The cart panel is server-rendered and refreshes only after a cart mutation.
# Therefore neither language may bootstrap WooCommerce's eager fragment script
# or its sessionStorage keys. Explicit AJAX language remains the fallback.
curl -sS "${WP_URL%/}/sepet/" > "$temporary_dir/fragments-source-tr.html"
! grep -q 'wc-cart-fragments-js\|cart_hash_key\|fragment_name' "$temporary_dir/fragments-source-tr.html" || fail "Turkish eager cart fragment bootstrap"
! grep -q 'wc-cart-fragments-js\|cart_hash_key\|fragment_name' "$temporary_dir/cart.html" || fail "English eager cart fragment bootstrap"
grep -q 'wc_ajax_url.*kuka_lang=en' "$temporary_dir/cart.html" || fail "English cart mutation language endpoint"
curl -sS "${WP_URL%/}/?wc-ajax=get_refreshed_fragments&kuka_lang=en" > "$temporary_dir/fragments-en.json"
grep -q 'Return to shop' "$temporary_dir/fragments-en.json" && grep -q '\\/en\\/magaza\\/' "$temporary_dir/fragments-en.json" || fail "English AJAX fallback"
pass "on-demand cart fragments + no-Referer English AJAX fallback"

# The exact reported regression: add in English, then visit Turkish with the
# same WooCommerce cookie. The server cart must remain populated after the
# language switch; browser cache behavior is covered by the separate hash key.
curl -sS -L -c "$cookie_jar" -b "$cookie_jar" -o "$temporary_dir/cart-switched-tr.html" "${WP_URL%/}/sepet/"
grep -q 'woocommerce-cart-form__cart-item cart_item' "$temporary_dir/cart-switched-tr.html" || fail "English cart survives Turkish switch"
pass "English add-to-cart survives Turkish language switch"

# Repeat the commerce path with an independent Turkish guest session.
tr_cookie_jar="$temporary_dir/cookies-tr"
curl -sS -L -c "$tr_cookie_jar" -b "$tr_cookie_jar" -o "$temporary_dir/added-tr.html" -X POST "$product_url" \
  --data-urlencode "add-to-cart=$product_id" --data-urlencode "product_id=$product_id" \
  --data-urlencode "variation_id=$variation_id" --data-urlencode 'quantity=1' \
  --data-urlencode "attribute_pa_renk=$color" --data-urlencode "attribute_pa_beden=$size" >/dev/null
curl -sS -L -c "$tr_cookie_jar" -b "$tr_cookie_jar" -o "$temporary_dir/cart-tr.html" "${WP_URL%/}/sepet/"
curl -sS -L -c "$tr_cookie_jar" -b "$tr_cookie_jar" -o "$temporary_dir/checkout-tr.html" "${WP_URL%/}/odeme/"
grep -q '<html lang="tr"' "$temporary_dir/cart-tr.html" && grep -q 'Ön Bilgilendirme Formu' "$temporary_dir/checkout-tr.html" || fail "Turkish commerce flow"
tr_checkout_nonce=$(grep -o 'name="woocommerce-process-checkout-nonce" value="[^"]*"' "$temporary_dir/checkout-tr.html" | head -1 | sed 's/.*value="//;s/"$//')
[ -n "$tr_checkout_nonce" ] || fail "Turkish checkout nonce"
curl -sS -L -c "$tr_cookie_jar" -b "$tr_cookie_jar" -o "$temporary_dir/checkout-tr-required.html" -X POST "${WP_URL%/}/odeme/" \
  --data-urlencode 'billing_first_name=' --data-urlencode 'billing_last_name=Testi' \
  --data-urlencode 'billing_email=duman@example.com' --data-urlencode 'billing_phone=05309481996' \
  --data-urlencode 'billing_customer_type=personal' --data-urlencode 'billing_country=TR' \
  --data-urlencode 'billing_address_1=Ata Sk 2' --data-urlencode 'billing_postcode=34335' \
  --data-urlencode 'billing_city=Besiktas' --data-urlencode 'billing_state=TR34' \
  --data-urlencode 'payment_method=iyzico' \
  --data-urlencode "woocommerce-process-checkout-nonce=$tr_checkout_nonce" \
  --data-urlencode '_wp_http_referer=/odeme/' \
  --data-urlencode 'woocommerce_checkout_place_order=Siparis ver' >/dev/null
grep -q '<strong>Ad</strong> gerekli bir alandır' "$temporary_dir/checkout-tr-required.html" || fail "Turkish natural checkout field notice"
! grep -q '<strong>Fatura Ad</strong>' "$temporary_dir/checkout-tr-required.html" || fail "Turkish prefixed checkout field notice"
grep -q 'id="billing_first_name" aria-invalid="true" aria-describedby="billing_first_name_error"' "$temporary_dir/checkout-tr-required.html" || fail "Turkish no-JS invalid field association"
grep -q 'id="billing_first_name_error">Bu alan zorunludur\.' "$temporary_dir/checkout-tr-required.html" || fail "Turkish no-JS inline required message"
! grep -q 'Telefon numarası 5XX XXX XX XX biçiminde olmalıdır\.' "$temporary_dir/checkout-tr-required.html" || fail "Turkish 0-prefixed phone normalization"
grep -q 'data-id="kuka_preinfo_accepted"' "$temporary_dir/checkout-tr-required.html" || fail "Turkish consent error field association"
perl -0ne 'exit !(/data-checkout-notices-inner>.*?woocommerce-error.*?<\/div>\s*<\/div>\s*<aside/s)' "$temporary_dir/checkout-tr-required.html" || fail "Turkish server notices inside grid region"
pass "Turkish product → cart → checkout flow"

account_code=$(curl -sS -o /dev/null -w '%{http_code}|%{redirect_url}' "${WP_URL%/}/hesabim/")
[ "$account_code" = "302|${WP_URL%/}/" ] || fail "account 302 redirect ($account_code)"
fetch "${WP_URL%/}/siparis-takibi/" "$temporary_dir/tracking.html"
grep -q 'woocommerce-form-track-order' "$temporary_dir/tracking.html" || fail "order tracking form"
fetch "${WP_URL%/}/en/siparis-takibi/" "$temporary_dir/tracking-en.html"
grep -q 'woocommerce-form-track-order' "$temporary_dir/tracking-en.html" && grep -q 'lang="en-US"' "$temporary_dir/tracking-en.html" || fail "English order tracking form"
assert_english_links "$temporary_dir/tracking-en.html" tracking

for slug in hakkimizda beden-rehberi kargo-teslimat iade-degisim sik-sorulan-sorular iletisim; do
  fetch "${WP_URL%/}/en/$slug/" "$temporary_dir/$slug-en.html"
  assert_english_links "$temporary_dir/$slug-en.html" "$slug"
done

echo "SMOKE=PASS (5/5)"
