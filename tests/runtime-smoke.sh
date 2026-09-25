#!/usr/bin/env bash
set -euo pipefail

WP_PATH="${WP_PATH:-/tmp/rar-wso-wp}"
BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
STAFF_COOKIE="/tmp/rar-wso-staff-cookies.txt"
MANAGER_COOKIE="/tmp/rar-wso-manager-cookies.txt"
STAFF_HTML="/tmp/rar-wso-staff.html"
MANAGER_HTML="/tmp/rar-wso-manager.html"
SERVER_LOG="/tmp/rar-wso-php-server.log"
WP=(wp --path="${WP_PATH}" --allow-root)

fail() {
  echo "::error::$*"
  if [[ -f "${SERVER_LOG}" ]]; then
    echo "---- PHP server log ----"
    tail -n 160 "${SERVER_LOG}" || true
  fi
  exit 1
}

assert_jq() {
  local json="$1"
  local filter="$2"
  local label="$3"
  echo "${json}" | jq -e "${filter}" >/dev/null || fail "${label}: ${json}"
  echo "PASS: ${label}"
}

login_user() {
  local username="$1"
  local password="$2"
  local jar="$3"
  local out="$4"

  rm -f "${jar}"
  curl -fsS -c "${jar}" "${BASE_URL}/wp-login.php" >/dev/null
  curl -fsS -L     -b "${jar}"     -c "${jar}"     --data-urlencode "log=${username}"     --data-urlencode "pwd=${password}"     --data-urlencode 'wp-submit=Log In'     --data-urlencode "redirect_to=${BASE_URL}/staff/"     --data-urlencode 'testcookie=1'     "${BASE_URL}/wp-login.php" >"${out}"
}

extract_nonce() {
  grep -o '"nonce":"[^"]*"' "$1" | head -1 | cut -d'"' -f4
}

echo "== Bootstrap WordPress =="
rm -rf "${WP_PATH}"
mkdir -p "${WP_PATH}"
wp core download --path="${WP_PATH}" --force --allow-root
"${WP[@]}" config create   --dbname=wordpress   --dbuser=root   --dbpass=root   --dbhost=127.0.0.1:3306   --skip-check
"${WP[@]}" core install   --url="${BASE_URL}"   --title="RAR WSO Runtime"   --admin_user=admin   --admin_password='AdminPass123!'   --admin_email=admin@example.com   --skip-email

echo "== Install WooCommerce =="
"${WP[@]}" plugin install woocommerce --activate
"${WP[@]}" option update woocommerce_currency BDT
"${WP[@]}" option update woocommerce_currency_pos right_space
"${WP[@]}" option update woocommerce_price_num_decimals 2

PLUGIN_DIR="${WP_PATH}/wp-content/plugins/rar-woo-stock-order"
mkdir -p "${PLUGIN_DIR}"
rsync -a --delete   --exclude='.git'   --exclude='.github'   --exclude='tests'   --exclude='dist'   ./ "${PLUGIN_DIR}/"

echo "== Seed v1.1 state and activate v1.2.0 =="
"${WP[@]}" eval 'update_option("rar_wso_version","1.1.0"); update_option("rar_wso_settings", array("enabled"=>"yes","staff_slug"=>"staff","default_order_status"=>"processing","allow_price_override"=>"yes","default_shipping"=>"0","dashboard_title"=>"Woo Stock & Order"));'
"${WP[@]}" plugin activate rar-woo-stock-order

VERSION_JSON="$("${WP[@]}" eval '$r=get_role("rar_wso_staff"); echo wp_json_encode(array("version"=>get_option("rar_wso_version"),"settings"=>get_option("rar_wso_settings"),"caps"=>$r ? $r->capabilities : array(),"shop_manager_edit_products"=>get_role("shop_manager") ? get_role("shop_manager")->has_cap("edit_products") : false));')"
assert_jq "${VERSION_JSON}" '.version=="1.2.0"' "upgrade version migrated to 1.2.0"
assert_jq "${VERSION_JSON}" '.caps.rar_wso_manage_stock==true and .caps.rar_wso_create_orders==true' "staff stock/order capabilities preserved"
assert_jq "${VERSION_JSON}" '.shop_manager_edit_products==true' "Shop Manager native product editing preserved"

PLUGIN_VERSION="$("${WP[@]}" plugin get rar-woo-stock-order --field=version)"
[[ "${PLUGIN_VERSION}" == "1.2.0" ]] || fail "Expected plugin version 1.2.0, got ${PLUGIN_VERSION}"
echo "PASS: plugin version is 1.2.0"

echo "== Validate Bangladesh address data against WooCommerce states =="
ADDRESS_CHECK="$("${WP[@]}" eval '$bad=array(); foreach(RAR_WSO_Data::districts() as $d){ if(!RAR_WSO_Data::district_to_state_code($d)){ $bad[]=$d; } } echo wp_json_encode(array("districts"=>count(RAR_WSO_Data::districts()),"bad"=>$bad,"dhaka_cities"=>RAR_WSO_Data::city_map()["Dhaka"]??array()));')"
assert_jq "${ADDRESS_CHECK}" '.districts==64' "64 Bangladesh districts available"
assert_jq "${ADDRESS_CHECK}" '(.bad|length)==0' "all district labels resolve to WooCommerce state codes"
assert_jq "${ADDRESS_CHECK}" '(.dhaka_cities|index("Savar")) != null' "district-to-town map includes Savar"

"${WP[@]}" rewrite structure '/%postname%/'
"${WP[@]}" rewrite flush
"${WP[@]}" user create staff staff@example.com --role=rar_wso_staff --user_pass='StaffPass123!' >/dev/null
"${WP[@]}" user create manager manager@example.com --role=shop_manager --user_pass='ManagerPass123!' >/dev/null

HIGH_ID="$("${WP[@]}" eval '$p=new WC_Product_Simple(); $p->set_name("RAR High Stock Product"); $p->set_sku("RARHIGH001"); $p->set_regular_price("100"); $p->set_manage_stock(true); $p->set_stock_quantity(15); $p->set_stock_status("instock"); $p->set_status("publish"); $p->save(); echo $p->get_id();')"
LOW_ID="$("${WP[@]}" eval '$p=new WC_Product_Simple(); $p->set_name("RAR Low Stock Product"); $p->set_sku("RARLOW001"); $p->set_regular_price("200"); $p->set_manage_stock(true); $p->set_stock_quantity(5); $p->set_stock_status("instock"); $p->set_status("publish"); $p->save(); echo $p->get_id();')"
OUT_ID="$("${WP[@]}" eval '$p=new WC_Product_Simple(); $p->set_name("RAR Out Stock Product"); $p->set_sku("RAROUT001"); $p->set_regular_price("300"); $p->set_manage_stock(true); $p->set_stock_quantity(0); $p->set_stock_status("outofstock"); $p->set_status("publish"); $p->save(); echo $p->get_id();')"
UNMANAGED_ID="$("${WP[@]}" eval '$p=new WC_Product_Simple(); $p->set_name("RAR Unmanaged Product"); $p->set_sku("RARUNMANAGED"); $p->set_regular_price("80"); $p->set_manage_stock(false); $p->set_stock_status("instock"); $p->set_status("publish"); $p->save(); echo $p->get_id();')"

cat > "${WP_PATH}/router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
PHP

php -S 127.0.0.1:8080 -t "${WP_PATH}" "${WP_PATH}/router.php" >"${SERVER_LOG}" 2>&1 &
SERVER_PID=$!
trap 'kill "${SERVER_PID}" 2>/dev/null || true' EXIT

for i in {1..30}; do
  if curl -fsS "${BASE_URL}/wp-login.php" >/dev/null; then
    break
  fi
  sleep 1
done
curl -fsS "${BASE_URL}/wp-login.php" >/dev/null || fail "WordPress web server did not start"

echo "== Logged-out PWA =="
LOGGED_OUT="$(curl -fsS "${BASE_URL}/staff/")"
grep -q 'Secure staff login' <<<"${LOGGED_OUT}" || fail "Logged-out /staff/ did not render staff login"
grep -q 'noindex,nofollow,noarchive' <<<"${LOGGED_OUT}" || fail "Logged-out /staff/ missing robots protection"
echo "PASS: logged-out staff route"

echo "== Staff login and UI =="
login_user staff 'StaffPass123!' "${STAFF_COOKIE}" /tmp/rar-wso-staff-login.html
curl -fsS -b "${STAFF_COOKIE}" "${BASE_URL}/staff/" -o "${STAFF_HTML}"

grep -q 'Secure staff workspace · v1.2.0' "${STAFF_HTML}" || fail "Staff app missing v1.2.0 marker"
grep -q "Today's Date" "${STAFF_HTML}" || fail "Professional dashboard date bar missing"
grep -q 'Available / Live' "${STAFF_HTML}" || fail "Inventory dashboard cards missing"
grep -q 'Save &amp; Share' "${STAFF_HTML}" || fail "Save & Share action missing"
grep -q 'Town / City / Upazila' "${STAFF_HTML}" || fail "Town/City/Upazila picker missing"
if grep -q 'Manager Control Center' "${STAFF_HTML}"; then
  fail "Staff user unexpectedly sees manager-only controls"
fi
if grep -q '&#2547;' "${STAFF_HTML}" || grep -q '&amp;nbsp;' "${STAFF_HTML}"; then
  fail "Raw currency HTML entity leaked into staff HTML"
fi
grep -q '"currency":"৳"' "${STAFF_HTML}" || fail "BDT currency symbol not localized as plain Unicode"
grep -q '"Savar"' "${STAFF_HTML}" || fail "Bangladesh town/upazila data missing from client config"
echo "PASS: staff UI renders professional v1.2.0 layout with plain BDT currency"

STAFF_NONCE="$(extract_nonce "${STAFF_HTML}")"
[[ -n "${STAFF_NONCE}" ]] || fail "Could not extract staff AJAX nonce"

echo "== Dashboard stats and inventory bands =="
STATS_JSON="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_stats'   --data-urlencode "nonce=${STAFF_NONCE}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${STATS_JSON}" '.success==true and .data.all_stock>=4 and .data.available_stock>=2 and .data.available_stock==(.data.high_stock+.data.low_stock) and .data.out_stock>=1 and .data.high_stock>=1 and .data.low_stock>=1 and .data.unmanaged_stock>=1' "dashboard inventory metrics"

HIGH_JSON="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_products'   --data-urlencode "nonce=${STAFF_NONCE}"   --data-urlencode 'search=RARHIGH001'   --data-urlencode 'filter=all'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${HIGH_JSON}" ".success==true and .data.items[0].id==${HIGH_ID} and .data.items[0].stock_band=="high" and .data.items[0].can_add==true" "healthy stock product classification"

LOW_JSON="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_products'   --data-urlencode "nonce=${STAFF_NONCE}"   --data-urlencode 'search=RARLOW001'   --data-urlencode 'filter=low'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${LOW_JSON}" ".success==true and .data.items[0].id==${LOW_ID} and .data.items[0].stock_band=="low"" "low stock product classification"

OUT_JSON="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_products'   --data-urlencode "nonce=${STAFF_NONCE}"   --data-urlencode 'search=RAROUT001'   --data-urlencode 'filter=out'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${OUT_JSON}" ".success==true and .data.items[0].id==${OUT_ID} and .data.items[0].stock_band=="out" and .data.items[0].can_add==false" "out-of-stock product cannot be added"

echo "== Stock update =="
STOCK_JSON="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_stock_update'   --data-urlencode "nonce=${STAFF_NONCE}"   --data-urlencode "product_id=${LOW_ID}"   --data-urlencode 'qty=8'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${STOCK_JSON}" '.success==true and .data.product.stock_qty==8 and .data.product.stock_band=="low"' "low stock update persists"

UNMANAGED_SET="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_stock_update'   --data-urlencode "nonce=${STAFF_NONCE}"   --data-urlencode "product_id=${UNMANAGED_ID}"   --data-urlencode 'qty=12'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${UNMANAGED_SET}" '.success==true and .data.product.manage_stock==true and .data.product.stock_qty==12 and .data.product.stock_band=="high"' "explicit Set stock converts unmanaged product"

echo "== Create Order validation =="
INVALID_PHONE_PAYLOAD="$(jq -cn   --argjson pid "${HIGH_ID}"   '{request_id:"invalid-phone",name:"Runtime Customer",phone:"01912",email:"",address:"Test Address",city:"Savar",district:"Dhaka",note:"",shipping:0,discount_type:"fixed",discount_value:0,items:[{id:$pid,qty:1,price:100}]}')"
INVALID_PHONE_JSON="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_create_order'   --data-urlencode "nonce=${STAFF_NONCE}"   --data-urlencode "payload=${INVALID_PHONE_PAYLOAD}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${INVALID_PHONE_JSON}" '.success==false and (.data.message|test("valid Bangladesh phone";"i"))' "invalid Bangladesh phone rejected"

INVALID_CITY_PAYLOAD="$(jq -cn   --argjson pid "${HIGH_ID}"   '{request_id:"invalid-city",name:"Runtime Customer",phone:"01700000000",email:"",address:"Test Address",city:"Naogaon Sadar",district:"Dhaka",note:"",shipping:0,discount_type:"fixed",discount_value:0,items:[{id:$pid,qty:1,price:100}]}')"
INVALID_CITY_JSON="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_create_order'   --data-urlencode "nonce=${STAFF_NONCE}"   --data-urlencode "payload=${INVALID_CITY_PAYLOAD}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${INVALID_CITY_JSON}" '.success==false and (.data.message|test("town/city";"i"))' "district-specific town/city validation"

OUT_PAYLOAD="$(jq -cn   --argjson pid "${OUT_ID}"   '{request_id:"out-item",name:"Runtime Customer",phone:"01700000000",email:"",address:"Test Address",city:"Savar",district:"Dhaka",note:"",shipping:0,discount_type:"fixed",discount_value:0,items:[{id:$pid,qty:1,price:300}]}')"
OUT_ORDER_JSON="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_create_order'   --data-urlencode "nonce=${STAFF_NONCE}"   --data-urlencode "payload=${OUT_PAYLOAD}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${OUT_ORDER_JSON}" '.success==false and (.data.message|test("not available";"i"))' "out-of-stock order item rejected server-side"

VALID_PAYLOAD="$(jq -cn   --argjson pid "${HIGH_ID}"   '{request_id:"runtime-order-120",name:"Runtime Customer",phone:"+8801700000000",email:"runtime@example.com",address:"Test Address",city:"Savar",district:"Dhaka",note:"CI runtime order",shipping:50,discount_type:"fixed",discount_value:10,items:[{id:$pid,qty:1,price:100}]}')"

ORDER_ONE="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_create_order'   --data-urlencode "nonce=${STAFF_NONCE}"   --data-urlencode "payload=${VALID_PAYLOAD}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${ORDER_ONE}" '.success==true and .data.duplicate==false and .data.status=="Processing" and .data.admin_url=="" and .data.items_subtotal==100 and .data.discount==10 and .data.shipping==50 and .data.total==140 and (.data.amount_words|test("One Hundred Forty Taka Only"))' "discounted staff order created with numeric totals"
ORDER_ID="$(echo "${ORDER_ONE}" | jq -r '.data.order_id')"

ORDER_TWO="$(curl -sS -b "${STAFF_COOKIE}"   --data-urlencode 'action=rar_wso_create_order'   --data-urlencode "nonce=${STAFF_NONCE}"   --data-urlencode "payload=${VALID_PAYLOAD}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${ORDER_TWO}" ".success==true and .data.duplicate==true and .data.order_id==${ORDER_ID}" "duplicate retry returns original order"

POST_ORDER_STOCK="$("${WP[@]}" eval "echo wc_get_product(${HIGH_ID})->get_stock_quantity();")"
[[ "${POST_ORDER_STOCK}" == "14" ]] || fail "Expected stock 14 after one real order and duplicate retry, got ${POST_ORDER_STOCK}"
echo "PASS: duplicate retry reduces stock exactly once"

ORDER_DB="$("${WP[@]}" eval "\$o=wc_get_order(${ORDER_ID}); echo wp_json_encode(array('phone'=>\$o->get_billing_phone(),'city'=>\$o->get_billing_city(),'discount'=>\$o->get_meta('_rar_wso_discount'),'total'=>(float)\$o->get_total(),'channel'=>\$o->get_meta('_rar_wso_channel')));")"
assert_jq "${ORDER_DB}" '.phone=="+8801700000000" and .city=="Savar" and .discount=="10" and .total==140 and .channel=="staff-pwa"' "normalized phone, city, discount and total persist"

echo "== Manager workspace =="
login_user manager 'ManagerPass123!' "${MANAGER_COOKIE}" /tmp/rar-wso-manager-login.html
curl -fsS -b "${MANAGER_COOKIE}" "${BASE_URL}/staff/" -o "${MANAGER_HTML}"
grep -q 'Manager Control Center' "${MANAGER_HTML}" || fail "Shop Manager missing manager control center"
grep -q 'All Orders' "${MANAGER_HTML}" || fail "All Orders action missing"
grep -q 'Live Orders' "${MANAGER_HTML}" || fail "Live Orders action missing"
grep -q '7-Day Sales' "${MANAGER_HTML}" || fail "Sales analytics chart missing"
echo "PASS: Shop Manager receives extra professional controls"

MANAGER_NONCE="$(extract_nonce "${MANAGER_HTML}")"
[[ -n "${MANAGER_NONCE}" ]] || fail "Could not extract manager AJAX nonce"

MANAGER_ORDERS="$(curl -sS -b "${MANAGER_COOKIE}"   --data-urlencode 'action=rar_wso_manager_orders'   --data-urlencode "nonce=${MANAGER_NONCE}"   --data-urlencode 'mode=all'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${MANAGER_ORDERS}" ".success==true and ([.data.orders[].id]|index(${ORDER_ID}))!=null" "manager All Orders includes staff-created order"

STATUS_JSON="$(curl -sS -b "${MANAGER_COOKIE}"   --data-urlencode 'action=rar_wso_update_order_status'   --data-urlencode "nonce=${MANAGER_NONCE}"   --data-urlencode "order_id=${ORDER_ID}"   --data-urlencode 'status=completed'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${STATUS_JSON}" '.success==true and .data.order.status=="completed"' "manager can update order status"

LIVE_JSON="$(curl -sS -b "${MANAGER_COOKIE}"   --data-urlencode 'action=rar_wso_manager_orders'   --data-urlencode "nonce=${MANAGER_NONCE}"   --data-urlencode 'mode=live'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${LIVE_JSON}" ".success==true and ([.data.orders[].id]|index(${ORDER_ID}))==null" "completed order disappears from Live Orders"

MANAGER_STATS="$(curl -sS -b "${MANAGER_COOKIE}"   --data-urlencode 'action=rar_wso_stats'   --data-urlencode "nonce=${MANAGER_NONCE}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${MANAGER_STATS}" '.success==true and .data.completed_orders>=1 and (.data.analytics.sales|length)==7 and (.data.analytics.week_total|type)=="number" and (.data.analytics.growth_pct|type)=="number"' "manager dashboard analytics payload"

echo "== PWA endpoints and currency regression =="
MANIFEST="$(curl -fsS "${BASE_URL}/rar-wso-manifest.webmanifest")"
assert_jq "${MANIFEST}" '.display=="standalone" and (.start_url|contains("/staff/"))' "manifest endpoint"

SERVICE_WORKER="$(curl -fsS "${BASE_URL}/rar-wso-sw.js")"
grep -q "rar-wso-assets-1.2.0-r3" <<<"${SERVICE_WORKER}" || fail "Service worker cache version mismatch"
grep -q "u.pathname.startsWith(STAFF_PATH)" <<<"${SERVICE_WORKER}" || fail "Service worker does not bypass authenticated staff HTML"
grep -q "u.pathname.startsWith('/wp-admin/')" <<<"${SERVICE_WORKER}" || fail "Service worker does not bypass wp-admin"
echo "PASS: service worker private-cache protections"

JS_BODY="$(curl -fsS "${BASE_URL}/wp-content/plugins/rar-woo-stock-order/assets/js/staff.js?ver=1.2.0")"
if grep -q '&#2547;' <<<"${JS_BODY}" || grep -q '&nbsp;' <<<"${JS_BODY}"; then
  fail "Raw BDT HTML entities exist in shipped staff JavaScript"
fi
echo "PASS: BDT currency regression guard"

echo "All RAR Woo Stock & Order v1.2.0 runtime smoke tests passed."
