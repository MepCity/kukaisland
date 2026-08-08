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

pass() { echo "PASS $1"; }
fail() { echo "FAIL $1" >&2; exit 1; }
fetch() {
  url=$1
  output=$2
  code=$(curl -sS -L -o "$output" -w '%{http_code}' "$url")
  [ "$code" = "200" ] || fail "$url HTTP $code"
}

fetch "$WP_URL" "$temporary_dir/home.html"
grep -q 'class="kuka-hero' "$temporary_dir/home.html" || fail "home hero"
! grep -Eqi 'kuka-account|Hesabım|Hesap oluştur|Üye ol|Giriş yap' "$temporary_dir/home.html" || fail "home account copy"
pass "home 200 + hero"

fetch "${WP_URL%/}/magaza/" "$temporary_dir/catalog.html"
catalog_count=$(grep -o 'data-product-card' "$temporary_dir/catalog.html" | wc -l | tr -d ' ')
fetch "${WP_URL%/}/magaza/?ki_color%5B%5D=kobalt" "$temporary_dir/catalog-filtered.html"
filtered_count=$(grep -o 'data-product-card' "$temporary_dir/catalog-filtered.html" | wc -l | tr -d ' ')
[ "$catalog_count" -gt 0 ] && [ "$filtered_count" -gt 0 ] && [ "$catalog_count" -ne "$filtered_count" ] || fail "catalog filter result change"
grep -q 'kuka-active-filters' "$temporary_dir/catalog-filtered.html" || fail "catalog active filter"
pass "catalog cards + filter changes result ($catalog_count -> $filtered_count)"

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

add_code=$(curl -sS -L -c "$cookie_jar" -b "$cookie_jar" -o "$temporary_dir/added.html" -w '%{http_code}' -X POST "$product_url" \
  --data-urlencode "add-to-cart=$product_id" \
  --data-urlencode "product_id=$product_id" \
  --data-urlencode "variation_id=$variation_id" \
  --data-urlencode 'quantity=1' \
  --data-urlencode "attribute_pa_renk=$color" \
  --data-urlencode "attribute_pa_beden=$size")
[ "$add_code" = "200" ] || fail "add to cart HTTP $add_code"
cart_code=$(curl -sS -L -c "$cookie_jar" -b "$cookie_jar" -o "$temporary_dir/cart.html" -w '%{http_code}' "${WP_URL%/}/sepet/")
[ "$cart_code" = "200" ] || fail "cart HTTP $cart_code"
grep -q "$color" "$temporary_dir/cart.html" && grep -q "$size" "$temporary_dir/cart.html" || fail "correct cart variation"
pass "correct variation added to cart ($variation_id: $color/$size)"

# A new HTTP client process reuses only WooCommerce's cookie jar. This models a
# browser restart: no localStorage or custom cart store participates.
restart_cart_code=$(curl -sS -L -b "$cookie_jar" -o "$temporary_dir/cart-after-restart.html" -w '%{http_code}' "${WP_URL%/}/sepet/")
[ "$restart_cart_code" = "200" ] || fail "cart after restart HTTP $restart_cart_code"
grep -q "$color" "$temporary_dir/cart-after-restart.html" && grep -q "$size" "$temporary_dir/cart-after-restart.html" || fail "guest cart cookie persistence"

checkout_code=$(curl -sS -L -c "$cookie_jar" -b "$cookie_jar" -o "$temporary_dir/checkout.html" -w '%{http_code}' "${WP_URL%/}/odeme/")
[ "$checkout_code" = "200" ] || fail "checkout HTTP $checkout_code"
[ "$(grep -o 'name="kuka_[^"]*_accepted"' "$temporary_dir/checkout.html" | sort -u | wc -l | tr -d ' ')" = "2" ] || fail "checkout legal consents"
grep -q 'id="place_order"' "$temporary_dir/checkout.html" || fail "checkout payment button"
# Raw HTML may contain translated account words inside third-party script data
# even when no control is rendered. Assert the actual storefront structures;
# visible text is covered separately by the browser acceptance scan.
! grep -Eqi 'id="createaccount"|woocommerce-form-login-toggle|kuka-account' "$temporary_dir/checkout.html" || fail "checkout account UI"
[ "$(grep -c 'name="kuka_[a-z_]*_accepted" value="1" required' "$temporary_dir/checkout.html" | tr -d ' ')" -ge 2 ] || fail "checkout consent required attribute"

# Onay kapısı sunucuda doğrulanır: JS kapalıyken de onaysız gönderim reddedilmeli.
checkout_nonce=$(grep -o 'name="woocommerce-process-checkout-nonce" value="[^"]*"' "$temporary_dir/checkout.html" | head -1 | sed 's/.*value="//;s/"$//')
[ -n "$checkout_nonce" ] || fail "checkout nonce"
curl -sS -L -c "$cookie_jar" -b "$cookie_jar" -o "$temporary_dir/checkout-no-consent.html" -X POST "${WP_URL%/}/odeme/" \
  --data-urlencode 'billing_first_name=Duman' --data-urlencode 'billing_last_name=Testi' \
  --data-urlencode 'billing_email=duman@example.com' --data-urlencode 'billing_phone=05309481996' \
  --data-urlencode 'billing_customer_type=personal' --data-urlencode 'billing_country=TR' \
  --data-urlencode 'billing_address_1=Ata Sk 2' --data-urlencode 'billing_postcode=34335' \
  --data-urlencode 'billing_city=Besiktas' --data-urlencode 'billing_state=TR34' \
  --data-urlencode 'payment_method=iyzico' \
  --data-urlencode "woocommerce-process-checkout-nonce=$checkout_nonce" \
  --data-urlencode '_wp_http_referer=/odeme/' \
  --data-urlencode 'woocommerce_checkout_place_order=Siparis ver' >/dev/null
grep -q 'Ön Bilgilendirme Formu onayı zorunludur' "$temporary_dir/checkout-no-consent.html" || fail "checkout consent gate (preinfo)"
grep -q 'Mesafeli Satış Sözleşmesi onayı zorunludur' "$temporary_dir/checkout-no-consent.html" || fail "checkout consent gate (distance sales)"
pass "checkout rendered + two-consent payment gate"

account_code=$(curl -sS -o /dev/null -w '%{http_code}|%{redirect_url}' "${WP_URL%/}/hesabim/")
[ "$account_code" = "302|${WP_URL%/}/" ] || fail "account 302 redirect ($account_code)"
fetch "${WP_URL%/}/siparis-takibi/" "$temporary_dir/tracking.html"
grep -q 'woocommerce-form-track-order' "$temporary_dir/tracking.html" || fail "order tracking form"

echo "SMOKE=PASS (5/5)"
