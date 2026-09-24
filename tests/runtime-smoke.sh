#!/usr/bin/env bash
set -euo pipefail

WP_PATH="${WP_PATH:-/tmp/rar-wso-wp}"
BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
COOKIE_JAR="/tmp/rar-wso-cookies.txt"
STAFF_HTML="/tmp/rar-wso-staff.html"
SERVER_LOG="/tmp/rar-wso-php-server.log"
WP=(wp --path="${WP_PATH}" --allow-root)

fail() {
  echo "::error::$*"
  if [[ -f "${SERVER_LOG}" ]]; then
    echo "---- PHP server log ----"
    tail -n 120 "${SERVER_LOG}" || true
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

echo "== Bootstrap WordPress =="
rm -rf "${WP_PATH}"
mkdir -p "${WP_PATH}"
wp core download --path="${WP_PATH}" --force --allow-root
"${WP[@]}" config create   --dbname=wordpress   --dbuser=root   --dbpass=root   --dbhost=127.0.0.1:3306   --skip-check
"${WP[@]}" core install   --url="${BASE_URL}"   --title="RAR WSO Runtime"   --admin_user=admin   --admin_password='AdminPass123!'   --admin_email=admin@example.com   --skip-email

echo "== Install WooCommerce =="
"${WP[@]}" plugin install woocommerce --activate

PLUGIN_DIR="${WP_PATH}/wp-content/plugins/rar-woo-stock-order"
mkdir -p "${PLUGIN_DIR}"
rsync -a --delete   --exclude='.git'   --exclude='.github'   --exclude='tests'   ./ "${PLUGIN_DIR}/"

echo "== Seed a v1.0-style state and activate v1.1.0 =="
"${WP[@]}" eval 'update_option("rar_wso_version","1.0.0"); update_option("rar_wso_settings", array("enabled"=>"yes","staff_slug"=>"staff","default_order_status"=>"processing","allow_price_override"=>"yes","allow_product_add"=>"yes","allow_product_delete"=>"yes","default_shipping"=>"0","dashboard_title"=>"Woo Stock & Order")); add_role("rar_wso_staff","Woo Stock & Order Staff", array("read"=>true,"rar_wso_access"=>true,"rar_wso_manage_stock"=>true,"rar_wso_create_orders"=>true,"rar_wso_adjust_price"=>true,"rar_wso_add_products"=>true,"rar_wso_delete_products"=>true));'
"${WP[@]}" plugin activate rar-woo-stock-order

VERSION_JSON="$("${WP[@]}" eval '$r=get_role("rar_wso_staff"); echo wp_json_encode(array("version"=>get_option("rar_wso_version"),"settings"=>get_option("rar_wso_settings"),"caps"=>$r ? $r->capabilities : array(),"shop_manager_edit_products"=>get_role("shop_manager") ? get_role("shop_manager")->has_cap("edit_products") : false));')"
assert_jq "${VERSION_JSON}" '.version=="1.1.0"' "upgrade version migrated"
assert_jq "${VERSION_JSON}" '(.settings|has("allow_product_add")|not) and (.settings|has("allow_product_delete")|not)' "legacy add/delete settings removed"
assert_jq "${VERSION_JSON}" '(.caps.rar_wso_add_products // false)==false and (.caps.rar_wso_delete_products // false)==false' "legacy add/delete capabilities removed"
assert_jq "${VERSION_JSON}" '.caps.rar_wso_manage_stock==true and .caps.rar_wso_create_orders==true' "staff stock/order capabilities preserved"
assert_jq "${VERSION_JSON}" '.shop_manager_edit_products==true' "Shop Manager native product editing preserved"

PLUGIN_VERSION="$("${WP[@]}" plugin get rar-woo-stock-order --field=version)"
[[ "${PLUGIN_VERSION}" == "1.1.0" ]] || fail "Expected plugin version 1.1.0, got ${PLUGIN_VERSION}"
echo "PASS: plugin version is 1.1.0"

"${WP[@]}" rewrite structure '/%postname%/'
"${WP[@]}" rewrite flush
"${WP[@]}" user create staff staff@example.com --role=rar_wso_staff --user_pass='StaffPass123!' >/dev/null

PRODUCT_ID="$("${WP[@]}" eval '$p=new WC_Product_Simple(); $p->set_name("RAR Runtime Test Product"); $p->set_sku("RARTEST001"); $p->set_regular_price("100"); $p->set_manage_stock(true); $p->set_stock_quantity(5); $p->set_stock_status("instock"); $p->set_status("publish"); $p->save(); echo $p->get_id();')"
UNMANAGED_ID="$("${WP[@]}" eval '$p=new WC_Product_Simple(); $p->set_name("RAR Runtime Unmanaged"); $p->set_sku("RARUNMANAGED"); $p->set_regular_price("80"); $p->set_manage_stock(false); $p->set_stock_status("instock"); $p->set_status("publish"); $p->save(); echo $p->get_id();')"

DISTRICT="$("${WP[@]}" eval '$states=WC()->countries->get_states("BD"); foreach($states as $label){ if(stripos($label,"Dhaka")!==false){ echo wp_strip_all_tags($label); break; } }')"
[[ -n "${DISTRICT}" ]] || fail "Could not resolve a Bangladesh district containing Dhaka"
echo "Using district: ${DISTRICT}"

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
grep -q 'Staff login' <<<"${LOGGED_OUT}" || fail "Logged-out /staff/ did not render staff login"
grep -q 'noindex,nofollow,noarchive' <<<"${LOGGED_OUT}" || fail "Logged-out /staff/ missing robots protection"
echo "PASS: logged-out staff route"

echo "== Staff login =="
curl -fsS -c "${COOKIE_JAR}" "${BASE_URL}/wp-login.php" >/dev/null
curl -fsS -L   -b "${COOKIE_JAR}"   -c "${COOKIE_JAR}"   --data-urlencode 'log=staff'   --data-urlencode 'pwd=StaffPass123!'   --data-urlencode 'wp-submit=Log In'   --data-urlencode "redirect_to=${BASE_URL}/staff/"   --data-urlencode 'testcookie=1'   "${BASE_URL}/wp-login.php" >/tmp/rar-wso-login.html

curl -fsS -b "${COOKIE_JAR}" "${BASE_URL}/staff/" -o "${STAFF_HTML}"
grep -q 'Stock Manager' "${STAFF_HTML}" || fail "Authenticated staff app missing Stock Manager"
grep -q 'Create Order' "${STAFF_HTML}" || fail "Authenticated staff app missing Create Order"
grep -q 'Secure staff workspace · v1.1.0' "${STAFF_HTML}" || fail "Authenticated staff app missing v1.1.0 marker"
if grep -q 'Add Simple Product' "${STAFF_HTML}"; then
  fail "Staff app still exposes product creation"
fi
echo "PASS: authenticated staff app renders focused v1.1.0 UI"

NONCE="$(grep -o '"nonce":"[^"]*"' "${STAFF_HTML}" | head -1 | cut -d'"' -f4)"
[[ -n "${NONCE}" ]] || fail "Could not extract staff AJAX nonce"

ajax() {
  curl -sS -b "${COOKIE_JAR}"     --data-urlencode "action=rar_wso_$1"     --data-urlencode "nonce=${NONCE}"     "${@:2}"     "${BASE_URL}/wp-admin/admin-ajax.php"
}

echo "== Product search =="
SEARCH_JSON="$(curl -sS -b "${COOKIE_JAR}"   --data-urlencode 'action=rar_wso_products'   --data-urlencode "nonce=${NONCE}"   --data-urlencode 'search=RARTEST001'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${SEARCH_JSON}" ".success==true and ([.data.items[].id] | index(${PRODUCT_ID})) != null" "SKU search finds exact product"

NO_MATCH_JSON="$(curl -sS -b "${COOKIE_JAR}"   --data-urlencode 'action=rar_wso_products'   --data-urlencode "nonce=${NONCE}"   --data-urlencode 'search=NO-SUCH-RAR-PRODUCT-XYZ'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${NO_MATCH_JSON}" '.success==true and (.data.items|length)==0' "unrelated search returns no products"

echo "== Stock update =="
STOCK_JSON="$(curl -sS -b "${COOKIE_JAR}"   --data-urlencode 'action=rar_wso_stock_update'   --data-urlencode "nonce=${NONCE}"   --data-urlencode "product_id=${PRODUCT_ID}"   --data-urlencode 'qty=4'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${STOCK_JSON}" '.success==true and .data.product.stock_qty==4' "managed stock update"
STOCK_DB="$("${WP[@]}" eval "echo wc_get_product(${PRODUCT_ID})->get_stock_quantity();")"
[[ "${STOCK_DB}" == "4" ]] || fail "Stock DB verification expected 4, got ${STOCK_DB}"
echo "PASS: stock persisted through WooCommerce CRUD"

UNMANAGED_SEARCH="$(curl -sS -b "${COOKIE_JAR}"   --data-urlencode 'action=rar_wso_products'   --data-urlencode "nonce=${NONCE}"   --data-urlencode 'search=RARUNMANAGED'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${UNMANAGED_SEARCH}" '.success==true and .data.items[0].manage_stock==false and .data.items[0].stock_qty==""' "unmanaged stock stays visually distinct"

UNMANAGED_SET="$(curl -sS -b "${COOKIE_JAR}"   --data-urlencode 'action=rar_wso_stock_update'   --data-urlencode "nonce=${NONCE}"   --data-urlencode "product_id=${UNMANAGED_ID}"   --data-urlencode 'qty=3'   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${UNMANAGED_SET}" '.success==true and .data.product.manage_stock==true and .data.product.stock_qty==3' "explicit Set stock enables stock management"

echo "== Create Order validation =="
INVALID_PAYLOAD="$(jq -cn   --argjson pid "${PRODUCT_ID}"   '{request_id:"invalid-district-1",name:"Runtime Customer",phone:"01700000000",email:"",address:"Test Address",city:"Test City",district:"Dhhaka",note:"",shipping:0,items:[{id:$pid,qty:1,price:100}]}')"
INVALID_JSON="$(curl -sS -b "${COOKIE_JAR}"   --data-urlencode 'action=rar_wso_create_order'   --data-urlencode "nonce=${NONCE}"   --data-urlencode "payload=${INVALID_PAYLOAD}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${INVALID_JSON}" '.success==false and (.data.message|test("valid Bangladesh district";"i"))' "invalid district rejected"

LIMIT_PAYLOAD="$(jq -cn   --arg district "${DISTRICT}"   --argjson pid "${PRODUCT_ID}"   '{request_id:"stock-limit-1",name:"Runtime Customer",phone:"01700000000",email:"",address:"Test Address",city:"Test City",district:$district,note:"",shipping:0,items:[{id:$pid,qty:99,price:100}]}')"
LIMIT_JSON="$(curl -sS -b "${COOKIE_JAR}"   --data-urlencode 'action=rar_wso_create_order'   --data-urlencode "nonce=${NONCE}"   --data-urlencode "payload=${LIMIT_PAYLOAD}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${LIMIT_JSON}" '.success==false and (.data.message|test("currently in stock";"i"))' "quantity above available stock rejected"

VALID_PAYLOAD="$(jq -cn   --arg district "${DISTRICT}"   --argjson pid "${PRODUCT_ID}"   '{request_id:"runtime-order-001",name:"Runtime Customer",phone:"01700000000",email:"runtime@example.com",address:"Test Address",city:"Test City",district:$district,note:"CI runtime order",shipping:50,items:[{id:$pid,qty:1,price:100}]}')"

ORDER_ONE="$(curl -sS -b "${COOKIE_JAR}"   --data-urlencode 'action=rar_wso_create_order'   --data-urlencode "nonce=${NONCE}"   --data-urlencode "payload=${VALID_PAYLOAD}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${ORDER_ONE}" '.success==true and .data.duplicate==false and .data.status=="Processing" and .data.admin_url==""' "staff order created without admin-only link"
ORDER_ID="$(echo "${ORDER_ONE}" | jq -r '.data.order_id')"

ORDER_TWO="$(curl -sS -b "${COOKIE_JAR}"   --data-urlencode 'action=rar_wso_create_order'   --data-urlencode "nonce=${NONCE}"   --data-urlencode "payload=${VALID_PAYLOAD}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${ORDER_TWO}" ".success==true and .data.duplicate==true and .data.order_id==${ORDER_ID}" "duplicate retry returns original order"

POST_ORDER_STOCK="$("${WP[@]}" eval "echo wc_get_product(${PRODUCT_ID})->get_stock_quantity();")"
[[ "${POST_ORDER_STOCK}" == "3" ]] || fail "Expected stock 3 after one real order and duplicate retry, got ${POST_ORDER_STOCK}"
echo "PASS: duplicate retry did not reduce stock twice"

ORDER_META="$("${WP[@]}" eval "\$o=wc_get_order(${ORDER_ID}); echo wp_json_encode(array('created_via'=>\$o->get_created_via(),'channel'=>\$o->get_meta('_rar_wso_channel'),'request_id'=>\$o->get_meta('_rar_wso_request_id')));")"
assert_jq "${ORDER_META}" '.created_via=="rar-wso-staff" and .channel=="staff-pwa" and .request_id=="runtime-order-001"' "order metadata persisted"

STATS_JSON="$(curl -sS -b "${COOKIE_JAR}"   --data-urlencode 'action=rar_wso_stats'   --data-urlencode "nonce=${NONCE}"   "${BASE_URL}/wp-admin/admin-ajax.php")"
assert_jq "${STATS_JSON}" '.success==true and .data.orders>=1 and (.data.sales|type)=="number"' "dashboard stats return numeric sales"

echo "== PWA endpoints =="
MANIFEST="$(curl -fsS "${BASE_URL}/rar-wso-manifest.webmanifest")"
assert_jq "${MANIFEST}" '.display=="standalone" and (.start_url|contains("/staff/"))' "manifest endpoint"

SERVICE_WORKER="$(curl -fsS "${BASE_URL}/rar-wso-sw.js")"
grep -q "rar-wso-assets-1.1.0-r2" <<<"${SERVICE_WORKER}" || fail "Service worker cache version mismatch"
grep -q "u.pathname.startsWith(STAFF_PATH)" <<<"${SERVICE_WORKER}" || fail "Service worker does not bypass authenticated staff HTML"
grep -q "u.pathname.startsWith('/wp-admin/')" <<<"${SERVICE_WORKER}" || fail "Service worker does not bypass wp-admin"
echo "PASS: service worker private-cache protections"

echo "All RAR Woo Stock & Order v1.1.0 runtime smoke tests passed."
