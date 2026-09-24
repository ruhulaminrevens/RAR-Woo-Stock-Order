<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RAR_WSO_PWA {
    public function __construct() {
        add_action( 'init', array( $this, 'rewrites' ) );
        add_filter( 'query_vars', array( $this, 'query_vars' ) );
        add_action( 'template_redirect', array( $this, 'route' ), 0 );
    }

    public function rewrites() {
        $s=RAR_WSO_Plugin::settings();$slug=sanitize_title($s['staff_slug']);if(!$slug)$slug='staff';
        add_rewrite_rule('^'.preg_quote($slug,'/').'/?$','index.php?rar_wso_app=1','top');
        add_rewrite_rule('^rar-wso-manifest\.webmanifest$','index.php?rar_wso_manifest=1','top');
        add_rewrite_rule('^rar-wso-sw\.js$','index.php?rar_wso_sw=1','top');
        add_rewrite_rule('^rar-wso-icon\.svg$','index.php?rar_wso_icon=1','top');
    }

    public function query_vars($vars){$vars[]='rar_wso_app';$vars[]='rar_wso_manifest';$vars[]='rar_wso_sw';$vars[]='rar_wso_icon';return $vars;}

    public function route(){
        if(get_query_var('rar_wso_manifest'))$this->manifest();
        if(get_query_var('rar_wso_sw'))$this->service_worker();
        if(get_query_var('rar_wso_icon'))$this->icon();
        if(get_query_var('rar_wso_app'))$this->app();
    }

    private function icon(){
        nocache_headers();header('Content-Type: image/svg+xml; charset=utf-8');
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect x="28" y="28" width="456" height="456" rx="96" fill="#0f7b63"/><path d="M150 210h212v166H150zM150 210l106-58 106 58M256 152v224" fill="none" stroke="#fff" stroke-width="28" stroke-linejoin="round"/><circle cx="375" cy="377" r="58" fill="#fff"/><path d="M347 377h56M375 349v56" stroke="#0f7b63" stroke-width="18" stroke-linecap="round"/></svg>';
        exit;
    }

    private function manifest(){
        $s=RAR_WSO_Plugin::settings();nocache_headers();header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode(array(
            'name'=>$s['dashboard_title'],'short_name'=>'Woo Stock & Order','start_url'=>RAR_WSO_Plugin::staff_url(),
            'scope'=>trailingslashit(wp_parse_url(RAR_WSO_Plugin::staff_url(),PHP_URL_PATH)),'display'=>'standalone',
            'background_color'=>'#f5f7f8','theme_color'=>'#0f7b63',
            'icons'=>array(array('src'=>home_url('/rar-wso-icon.svg'),'sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any maskable'))
        ),JSON_UNESCAPED_SLASHES);exit;
    }

    private function service_worker(){
        nocache_headers();header('Content-Type: application/javascript; charset=utf-8');header('Service-Worker-Allowed: /');
        $start=esc_url_raw(RAR_WSO_Plugin::staff_url());
        echo "const CACHE='rar-wso-v".esc_js(RAR_WSO_VERSION)."';\n";
        echo "const START=".wp_json_encode($start).";\n";
        echo "self.addEventListener('install',e=>{self.skipWaiting();e.waitUntil(caches.open(CACHE).then(c=>c.add(START)).catch(()=>{}));});\n";
        echo "self.addEventListener('activate',e=>{e.waitUntil(self.clients.claim());});\n";
        echo "self.addEventListener('fetch',e=>{if(e.request.method!=='GET')return;const u=new URL(e.request.url);if(u.origin!==location.origin)return;if(u.pathname.includes('/wp-admin/admin-ajax.php'))return;e.respondWith(fetch(e.request).then(r=>{const c=r.clone();caches.open(CACHE).then(x=>x.put(e.request,c)).catch(()=>{});return r;}).catch(()=>caches.match(e.request).then(r=>r||caches.match(START))));});\n";
        exit;
    }

    private function app(){
        $s=RAR_WSO_Plugin::settings();if('yes'!==$s['enabled']){status_header(404);exit;}nocache_headers();
        if(!is_user_logged_in())$this->login_screen();
        if(!RAR_WSO_Plugin::can('rar_wso_access')){status_header(403);$this->simple_page(__('Access denied','rar-woo-stock-order'),__('Your account does not have permission to use the staff app.','rar-woo-stock-order'));}

        $user=wp_get_current_user();$config=array(
            'ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('rar_wso_nonce'),'currency'=>get_woocommerce_currency_symbol(),
            'canStock'=>RAR_WSO_Plugin::can('rar_wso_manage_stock'),'canOrder'=>RAR_WSO_Plugin::can('rar_wso_create_orders'),
            'allowPrice'=>'yes'===$s['allow_price_override']&&RAR_WSO_Plugin::can('rar_wso_adjust_price'),
            'allowAdd'=>'yes'===$s['allow_product_add']||current_user_can('manage_woocommerce'),
            'allowDelete'=>'yes'===$s['allow_product_delete']||current_user_can('manage_woocommerce'),
            'shipping'=>(float)$s['default_shipping'],'staffUrl'=>RAR_WSO_Plugin::staff_url(),'swUrl'=>home_url('/rar-wso-sw.js')
        );
        $districts=WC()->countries->get_states('BD');
        ?>
<!doctype html><html <?php language_attributes(); ?>><head>
<meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0f7b63"><meta name="robots" content="noindex,nofollow,noarchive">
<title><?php echo esc_html($s['dashboard_title']); ?></title>
<link rel="manifest" href="<?php echo esc_url(home_url('/rar-wso-manifest.webmanifest')); ?>">
<link rel="icon" href="<?php echo esc_url(home_url('/rar-wso-icon.svg')); ?>">
<link rel="stylesheet" href="<?php echo esc_url(RAR_WSO_URL.'assets/css/staff.css?ver='.rawurlencode(RAR_WSO_VERSION)); ?>">
</head><body><div class="rar-app">
<header class="rar-topbar"><div><div class="rar-kicker">RAR WOO</div><h1><?php echo esc_html($s['dashboard_title']); ?></h1></div><div class="rar-user"><span><?php echo esc_html($user->display_name); ?></span><a href="<?php echo esc_url(wp_logout_url(RAR_WSO_Plugin::staff_url())); ?>"><?php esc_html_e('Log out','rar-woo-stock-order'); ?></a></div></header>
<main>
<section id="rar-dashboard" class="rar-view active">
<div class="rar-stats"><div class="rar-stat"><span>Today's Orders</span><strong id="stat-orders">—</strong></div><div class="rar-stat"><span>Today's Sales</span><strong id="stat-sales">—</strong></div><div class="rar-stat"><span>Low Stock</span><strong id="stat-low">—</strong></div><div class="rar-stat"><span>Out of Stock</span><strong id="stat-out">—</strong></div></div>
<div class="rar-actions-home"><?php if(RAR_WSO_Plugin::can('rar_wso_manage_stock')):?><button class="rar-big-btn" data-view="stock"><span>📦</span>Stock Manager</button><?php endif; ?><?php if(RAR_WSO_Plugin::can('rar_wso_create_orders')):?><button class="rar-big-btn" data-view="order"><span>🧾</span>Create Order</button><?php endif; ?></div>
<div class="rar-install-tip"><strong>Phone tip:</strong> Chrome → Add to Home Screen to use this like an app.</div>
</section>

<section id="rar-stock" class="rar-view"><div class="rar-section-head"><button class="rar-back" data-view="dashboard">←</button><h2>Stock Manager</h2><?php if('yes'===$s['allow_product_add']||current_user_can('manage_woocommerce')):?><button class="rar-secondary" id="rar-add-product-open">+ Add</button><?php endif; ?></div><div class="rar-search-wrap"><input id="rar-stock-search" type="search" placeholder="Search item name or SKU…" autocomplete="off"></div><div id="rar-stock-list" class="rar-product-list"></div></section>

<section id="rar-order" class="rar-view"><div class="rar-section-head"><button class="rar-back" data-view="dashboard">←</button><h2>Create Order</h2></div>
<form id="rar-order-form" novalidate>
<div class="rar-card rar-customer-grid">
<label><span>Customer name *</span><input name="name" required></label><label><span>Phone *</span><input name="phone" inputmode="tel" required></label>
<label><span>Email (optional)</span><input name="email" type="email"></label><label class="wide"><span>Address *</span><input name="address" required></label>
<label><span>Town / City *</span><input name="city" required></label><label><span>District *</span><input name="district" list="rar-districts" required></label>
<datalist id="rar-districts"><?php foreach($districts as $code=>$label):?><option value="<?php echo esc_attr($label); ?>"><?php endforeach;?></datalist>
</div>
<div class="rar-card"><label><span>Search product</span><input id="rar-order-search" type="search" placeholder="Type item name or SKU…" autocomplete="off"></label><div id="rar-order-search-results" class="rar-search-results"></div></div>
<div class="rar-card"><div class="rar-order-table-head"><span>Items</span><span id="rar-item-count">0</span></div><div id="rar-order-items"></div><div class="rar-summary-row"><span>Items subtotal</span><strong id="rar-subtotal">0</strong></div><div class="rar-summary-row"><label for="rar-shipping">Shipping</label><input id="rar-shipping" type="number" min="0" step="0.01" value="<?php echo esc_attr($s['default_shipping']); ?>"></div><div class="rar-summary-row total"><span>Total</span><strong id="rar-total">0</strong></div><label><span>Order note (optional)</span><textarea name="note" rows="3"></textarea></label></div>
<button class="rar-primary rar-save-order" type="submit">Save Order</button></form><div id="rar-order-result" class="rar-result" hidden></div></section>
</main></div>

<div id="rar-add-product-modal" class="rar-modal" hidden><div class="rar-modal-card"><div class="rar-section-head"><h2>Add Simple Product</h2><button id="rar-add-product-close" type="button">×</button></div><form id="rar-add-product-form"><label><span>Product name *</span><input name="name" required></label><label><span>SKU</span><input name="sku"></label><label><span>Regular price *</span><input name="price" type="number" min="0" step="0.01" required></label><label><span>Stock quantity</span><input name="qty" type="number" min="0" step="1" value="0"></label><button class="rar-primary" type="submit">Create Product</button></form></div></div>
<div id="rar-toast" class="rar-toast" hidden></div>
<script>window.RARWSO=<?php echo wp_json_encode($config,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo esc_url(RAR_WSO_URL.'assets/js/staff.js?ver='.rawurlencode(RAR_WSO_VERSION)); ?>"></script>
</body></html><?php exit;
    }

    private function login_screen(){
        $s=RAR_WSO_Plugin::settings();$redirect=RAR_WSO_Plugin::staff_url();?>
<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0f7b63"><meta name="robots" content="noindex,nofollow,noarchive"><title><?php echo esc_html($s['dashboard_title']); ?> — Login</title><link rel="manifest" href="<?php echo esc_url(home_url('/rar-wso-manifest.webmanifest')); ?>"><link rel="stylesheet" href="<?php echo esc_url(RAR_WSO_URL.'assets/css/staff.css?ver='.rawurlencode(RAR_WSO_VERSION)); ?>"></head><body class="rar-login-page"><div class="rar-login-card"><div class="rar-kicker">RAR WOO</div><h1><?php echo esc_html($s['dashboard_title']); ?></h1><p>Staff login</p><?php wp_login_form(array('redirect'=>$redirect,'remember'=>true)); ?></div></body></html><?php exit;
    }

    private function simple_page($title,$message){?>
<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="<?php echo esc_url(RAR_WSO_URL.'assets/css/staff.css?ver='.rawurlencode(RAR_WSO_VERSION)); ?>"></head><body class="rar-login-page"><div class="rar-login-card"><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($message); ?></p><a class="rar-primary" href="<?php echo esc_url(wp_logout_url(RAR_WSO_Plugin::staff_url())); ?>">Log out</a></div></body></html><?php exit;
    }
}
