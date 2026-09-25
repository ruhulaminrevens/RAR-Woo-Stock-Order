<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAR_WSO_PWA {
    public function __construct() {
        add_action( 'init', array( $this, 'rewrites' ) );
        add_filter( 'query_vars', array( $this, 'query_vars' ) );
        add_action( 'template_redirect', array( $this, 'route' ), 0 );
    }

    public function rewrites() {
        $settings = RAR_WSO_Plugin::settings();
        $slug     = sanitize_title( $settings['staff_slug'] );

        if ( ! $slug ) {
            $slug = 'staff';
        }

        add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?rar_wso_app=1', 'top' );
        add_rewrite_rule( '^rar-wso-manifest\.webmanifest$', 'index.php?rar_wso_manifest=1', 'top' );
        add_rewrite_rule( '^rar-wso-sw\.js$', 'index.php?rar_wso_sw=1', 'top' );
        add_rewrite_rule( '^rar-wso-icon\.svg$', 'index.php?rar_wso_icon=1', 'top' );
    }

    public function query_vars( $vars ) {
        $vars[] = 'rar_wso_app';
        $vars[] = 'rar_wso_manifest';
        $vars[] = 'rar_wso_sw';
        $vars[] = 'rar_wso_icon';
        return $vars;
    }

    public function route() {
        if ( get_query_var( 'rar_wso_manifest' ) ) {
            $this->manifest();
        }
        if ( get_query_var( 'rar_wso_sw' ) ) {
            $this->service_worker();
        }
        if ( get_query_var( 'rar_wso_icon' ) ) {
            $this->icon();
        }
        if ( get_query_var( 'rar_wso_app' ) ) {
            $this->app();
        }
    }

    private function icon() {
        nocache_headers();
        header( 'Content-Type: image/svg+xml; charset=utf-8' );
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect x="28" y="28" width="456" height="456" rx="96" fill="#0f7b63"/><path d="M150 210h212v166H150zM150 210l106-58 106 58M256 152v224" fill="none" stroke="#fff" stroke-width="28" stroke-linejoin="round"/><circle cx="375" cy="377" r="58" fill="#fff"/><path d="M347 377h56M375 349v56" stroke="#0f7b63" stroke-width="18" stroke-linecap="round"/></svg>';
        exit;
    }

    private function manifest() {
        $settings = RAR_WSO_Plugin::settings();

        nocache_headers();
        header( 'Content-Type: application/manifest+json; charset=utf-8' );

        echo wp_json_encode(
            array(
                'name'             => $settings['dashboard_title'],
                'short_name'       => 'Woo Stock & Order',
                'start_url'        => RAR_WSO_Plugin::staff_url(),
                'scope'            => trailingslashit( wp_parse_url( RAR_WSO_Plugin::staff_url(), PHP_URL_PATH ) ),
                'display'          => 'standalone',
                'background_color' => '#f4f7f8',
                'theme_color'      => '#0f7b63',
                'icons'            => array(
                    array(
                        'src'     => home_url( '/rar-wso-icon.svg' ),
                        'sizes'   => 'any',
                        'type'    => 'image/svg+xml',
                        'purpose' => 'any maskable',
                    ),
                ),
            ),
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    private function service_worker() {
        nocache_headers();
        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Service-Worker-Allowed: /' );

        $staff_path = trailingslashit( wp_parse_url( RAR_WSO_Plugin::staff_url(), PHP_URL_PATH ) );
        $assets     = array(
            RAR_WSO_URL . 'assets/css/staff.css?ver=' . rawurlencode( RAR_WSO_VERSION ),
            RAR_WSO_URL . 'assets/js/staff.js?ver=' . rawurlencode( RAR_WSO_VERSION ),
            home_url( '/rar-wso-icon.svg' ),
            home_url( '/rar-wso-manifest.webmanifest' ),
        );

        echo "const CACHE='rar-wso-assets-" . esc_js( RAR_WSO_VERSION ) . "-r3';\n";
        echo 'const STAFF_PATH=' . wp_json_encode( $staff_path ) . ";\n";
        echo 'const ASSETS=' . wp_json_encode( array_values( $assets ), JSON_UNESCAPED_SLASHES ) . ";\n";
        echo "self.addEventListener('install',e=>{self.skipWaiting();e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS)).catch(()=>{}));});\n";
        echo "self.addEventListener('activate',e=>{e.waitUntil(Promise.all([caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('rar-wso-')&&k!==CACHE).map(k=>caches.delete(k)))),self.clients.claim()]));});\n";
        echo "self.addEventListener('fetch',e=>{if(e.request.method!=='GET')return;const u=new URL(e.request.url);if(u.origin!==location.origin)return;if(u.pathname.startsWith(STAFF_PATH)||u.pathname.startsWith('/wp-admin/'))return;if(!ASSETS.includes(e.request.url))return;e.respondWith(caches.match(e.request).then(hit=>hit||fetch(e.request).then(r=>{if(r&&r.ok){const copy=r.clone();caches.open(CACHE).then(c=>c.put(e.request,copy)).catch(()=>{});}return r;})));});\n";
        exit;
    }

    private function app() {
        $settings = RAR_WSO_Plugin::settings();

        if ( 'yes' !== $settings['enabled'] ) {
            status_header( 404 );
            exit;
        }

        nocache_headers();

        if ( ! is_user_logged_in() ) {
            $this->login_screen();
        }

        if ( ! RAR_WSO_Plugin::can( 'rar_wso_access' ) ) {
            status_header( 403 );
            $this->simple_page(
                __( 'Access denied', 'rar-woo-stock-order' ),
                __( 'Your account does not have permission to use the staff app.', 'rar-woo-stock-order' )
            );
        }

        $user       = wp_get_current_user();
        $districts  = RAR_WSO_Data::districts();
        $city_map   = RAR_WSO_Data::city_map();
        $is_manager = current_user_can( 'manage_woocommerce' );
        $statuses   = array();

        foreach ( wc_get_order_statuses() as $key => $label ) {
            $statuses[] = array(
                'value' => str_replace( 'wc-', '', $key ),
                'label' => wp_strip_all_tags( $label ),
            );
        }

        $config = array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'rar_wso_nonce' ),
            'currency'         => RAR_WSO_Data::clean_currency_symbol(),
            'currencyPosition' => get_option( 'woocommerce_currency_pos', 'left' ),
            'decimals'         => wc_get_price_decimals(),
            'decimalSep'       => wc_get_price_decimal_separator(),
            'thousandSep'      => wc_get_price_thousand_separator(),
            'districts'        => array_values( $districts ),
            'cityMap'          => $city_map,
            'orderStatuses'    => $statuses,
            'isManager'        => $is_manager,
            'canStock'         => RAR_WSO_Plugin::can( 'rar_wso_manage_stock' ),
            'canOrder'         => RAR_WSO_Plugin::can( 'rar_wso_create_orders' ),
            'allowPrice'       => 'yes' === $settings['allow_price_override'] && RAR_WSO_Plugin::can( 'rar_wso_adjust_price' ),
            'shipping'         => (float) $settings['default_shipping'],
            'staffUrl'         => RAR_WSO_Plugin::staff_url(),
            'swUrl'            => home_url( '/rar-wso-sw.js' ),
            'version'          => RAR_WSO_VERSION,
            'siteTimezone'     => wp_timezone_string(),
            'storeName'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
        );
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0f7b63">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?php echo esc_html( $settings['dashboard_title'] ); ?></title>
<link rel="manifest" href="<?php echo esc_url( home_url( '/rar-wso-manifest.webmanifest' ) ); ?>">
<link rel="icon" href="<?php echo esc_url( home_url( '/rar-wso-icon.svg' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( RAR_WSO_URL . 'assets/css/staff.css?ver=' . rawurlencode( RAR_WSO_VERSION ) ); ?>">
</head>
<body>
<div class="rar-app">
<header class="rar-topbar">
    <div>
        <div class="rar-kicker">RAR WOO</div>
        <h1><?php echo esc_html( $settings['dashboard_title'] ); ?></h1>
    </div>
    <div class="rar-user">
        <span><?php echo esc_html( $user->display_name ); ?></span>
        <small><?php echo $is_manager ? esc_html__( 'Manager', 'rar-woo-stock-order' ) : esc_html__( 'Staff', 'rar-woo-stock-order' ); ?></small>
        <a href="<?php echo esc_url( wp_logout_url( RAR_WSO_Plugin::staff_url() ) ); ?>"><?php esc_html_e( 'Log out', 'rar-woo-stock-order' ); ?></a>
    </div>
</header>

<main>
<section id="rar-dashboard" class="rar-view active rar-dashboard-v13">
    <div class="rar-dashboard-hero">
        <div class="rar-dashboard-date">
            <span class="rar-eyebrow"><i></i> TODAY'S DATE</span>
            <strong id="rar-live-clock">—</strong>
            <small>Good <span id="rar-daypart">day</span>, <?php echo esc_html( $user->display_name ); ?> · <?php echo $is_manager ? esc_html__( 'Shop Manager', 'rar-woo-stock-order' ) : esc_html__( 'Staff', 'rar-woo-stock-order' ); ?></small>
        </div>
        <div class="rar-quick-actions">
            <?php if ( RAR_WSO_Plugin::can( 'rar_wso_create_orders' ) ) : ?>
                <button class="rar-quick-btn quick-order" data-view="order" type="button"><b>＋</b><span><strong>Create Order</strong><small>নতুন অর্ডার নিন</small></span></button>
            <?php endif; ?>
            <?php if ( RAR_WSO_Plugin::can( 'rar_wso_manage_stock' ) ) : ?>
                <button class="rar-quick-btn quick-stock" data-view="stock" type="button"><b>≋</b><span><strong>Stock Manager</strong><small>স্টক দেখুন ও আপডেট করুন</small></span></button>
            <?php endif; ?>
        </div>
    </div>

    <div class="rar-dashboard-heading">
        <h2>Orders</h2>
        <div class="rar-period-switch" aria-label="Dashboard period">
            <button type="button" class="active" data-dashboard-period="today">Today</button>
            <button type="button" data-dashboard-period="7days">7 days</button>
            <button type="button" data-dashboard-period="month">This month</button>
        </div>
    </div>

    <div class="rar-v13-kpi-grid">
        <button class="rar-v13-kpi kpi-orders" type="button"<?php echo $is_manager ? ' data-orders-mode="today"' : ''; ?>>
            <span class="rar-kpi-icon">▣</span>
            <small id="stat-orders-label">Today's Orders</small>
            <strong id="stat-orders">—</strong>
            <em id="stat-orders-sub">Loading…</em>
        </button>
        <button class="rar-v13-kpi kpi-sales" type="button"<?php echo $is_manager ? ' data-orders-mode="today"' : ''; ?>>
            <span class="rar-kpi-icon">৳</span>
            <small id="stat-sales-label">Today's Sales</small>
            <strong id="stat-sales">—</strong>
            <em id="stat-sales-sub">Loading…</em>
        </button>
        <button class="rar-v13-kpi kpi-completed" type="button"<?php echo $is_manager ? ' data-orders-mode="completed"' : ''; ?>>
            <span class="rar-kpi-icon">✓</span>
            <small>Completed Orders</small>
            <strong id="stat-completed-period">—</strong>
            <em id="stat-completed-sub">Loading…</em>
        </button>
        <button class="rar-v13-kpi kpi-returned" type="button"<?php echo $is_manager ? ' data-orders-mode="returns"' : ''; ?>>
            <span class="rar-kpi-icon">↶</span>
            <small>Returned / Cancelled</small>
            <strong id="stat-returned-period">—</strong>
            <em id="stat-returned-sub">Loading…</em>
        </button>
    </div>

    <div class="rar-dashboard-heading rar-stock-heading">
        <h2>Stock</h2>
        <div class="rar-stock-key">
            <span class="key-high">10+ in stock</span>
            <span class="key-low">Low 1–10</span>
            <span class="key-out">Stock out</span>
            <span class="key-unmanaged">Not tracked</span>
        </div>
    </div>

    <div class="rar-v13-stock-grid">
        <button class="rar-v13-stock-card stock-all" type="button" data-stock-filter="all">
            <div class="rar-stock-card-top"><span class="rar-kpi-icon">◇</span><i>↗</i></div>
            <small>All Stock</small>
            <strong><span id="stat-all-stock">—</span><em> products</em></strong>
            <div class="rar-stock-composition" aria-hidden="true">
                <i class="comp-high" id="rar-comp-high"></i>
                <i class="comp-low" id="rar-comp-low"></i>
                <i class="comp-out" id="rar-comp-out"></i>
                <i class="comp-unmanaged" id="rar-comp-unmanaged"></i>
            </div>
            <div class="rar-stock-breakdown">
                <span class="high"><b id="dash-high">—</b> 10+</span>
                <span class="low"><b id="dash-low">—</b> low</span>
                <span class="out"><b id="dash-out">—</b> out</span>
                <span class="unmanaged"><b id="dash-unmanaged">—</b> not tracked</span>
            </div>
            <em id="dash-stock-units">— units in hand</em>
        </button>

        <button class="rar-v13-stock-card stock-live" type="button" data-stock-filter="available">
            <div class="rar-stock-card-top"><span class="rar-kpi-icon">↗</span><i>↗</i></div>
            <small>Available / Live Stock</small>
            <strong><span id="stat-available">—</span><em> products</em></strong>
            <div class="rar-stock-breakdown">
                <span class="high"><b id="dash-healthy">—</b> healthy</span>
                <span class="low"><b id="dash-low-2">—</b> low</span>
            </div>
            <em>Managed quantity ready to sell</em>
        </button>

        <button class="rar-v13-stock-card stock-out" type="button" data-stock-filter="out">
            <div class="rar-stock-card-top"><span class="rar-kpi-icon">⊘</span><i>↗</i></div>
            <small>Out of Stock</small>
            <strong><span id="stat-out">—</span><em> products</em></strong>
            <div id="rar-out-preview" class="rar-out-preview">Tap to review unavailable products</div>
            <em>Needs attention</em>
        </button>
    </div>

    <?php if ( $is_manager ) : ?>
    <div class="rar-manager-zone-v13">
        <div class="rar-dashboard-heading">
            <h2>Order Control <span>SHOP MANAGER</span></h2>
        </div>
        <div class="rar-order-control-grid">
            <button type="button" class="rar-order-control-card all-orders" data-orders-mode="all">
                <span class="rar-kpi-icon">☷</span><small>All Orders</small>
                <strong id="manager-all-orders">—</strong><em>orders</em>
                <p>View & update recent orders</p>
            </button>
            <button type="button" class="rar-order-control-card live-orders" data-orders-mode="live">
                <span class="rar-kpi-icon">▣</span><small>Live Orders</small>
                <strong id="manager-live-orders">—</strong><em>running</em>
                <p>Pending / processing / confirmed</p>
            </button>
            <button type="button" class="rar-order-control-card processing-orders" data-orders-mode="processing">
                <span class="rar-kpi-icon">◉</span><small>Total Processing</small>
                <strong id="manager-processing-orders">—</strong><em>orders</em>
                <p>Currently being prepared</p>
            </button>
        </div>

        <div class="rar-dashboard-heading">
            <h2>Sales &amp; Growth <span>SHOP MANAGER</span></h2>
            <div class="rar-period-switch rar-manager-period-switch" aria-label="Manager reporting period">
                <button type="button" data-manager-period="7days">7 days</button>
                <button type="button" class="active" data-manager-period="30days">30 days</button>
                <button type="button" data-manager-period="90days">90 days</button>
            </div>
        </div>

        <div class="rar-manager-metrics">
            <div><small id="mgr-sales-label">Sales · 30 days</small><strong id="mgr-sales">—</strong><em id="mgr-sales-change">—</em></div>
            <div><small>Orders</small><strong id="mgr-orders">—</strong><em id="mgr-orders-change">—</em></div>
            <div><small>Average order</small><strong id="mgr-average">—</strong><em>sales orders only</em></div>
            <div><small>Items sold</small><strong id="mgr-items">—</strong><em>excluding returned/cancelled</em></div>
        </div>

        <div class="rar-v13-chart-card">
            <div class="rar-chart-head"><strong>Sales trend</strong><span id="rar-week-sales">—</span></div>
            <div id="rar-sales-chart" class="rar-sales-chart rar-sales-chart-v13" aria-label="7 day sales chart"></div>
            <div class="rar-growth" id="rar-growth">—</div>
        </div>

        <div class="rar-manager-breakdowns">
            <section class="rar-v13-panel">
                <div class="rar-panel-head"><strong>Top Products</strong><span id="rar-top-products-meta">By quantity sold</span></div>
                <div id="rar-top-products" class="rar-breakdown-list"><div class="rar-dashboard-loading">Loading…</div></div>
            </section>
            <section class="rar-v13-panel">
                <div class="rar-panel-head"><strong>Payment Mix</strong><span>Sales share</span></div>
                <div id="rar-payment-mix" class="rar-breakdown-list"><div class="rar-dashboard-loading">Loading…</div></div>
            </section>
            <section class="rar-v13-panel">
                <div class="rar-panel-head"><strong>Sales Channels</strong><span>Sales share</span></div>
                <div id="rar-channel-mix" class="rar-breakdown-list"><div class="rar-dashboard-loading">Loading…</div></div>
            </section>
        </div>
    </div>
    <?php endif; ?>

    <div class="rar-dashboard-lower">
        <section class="rar-v13-panel">
            <div class="rar-panel-head"><strong>Sales · last 7 days</strong><span id="rar-seven-total">—</span></div>
            <div id="rar-seven-chart" class="rar-mini-bars"></div>
        </section>
        <section class="rar-v13-panel">
            <div class="rar-panel-head"><strong>Needs attention</strong><span id="rar-attention-count">—</span></div>
            <div id="rar-attention-list" class="rar-attention-list"><div class="rar-dashboard-loading">Loading…</div></div>
        </section>
    </div>

    <section class="rar-v13-panel rar-recent-panel">
        <div class="rar-panel-head"><strong><?php echo $is_manager ? esc_html__( 'Recent orders', 'rar-woo-stock-order' ) : esc_html__( 'My recent orders', 'rar-woo-stock-order' ); ?></strong><?php if ( $is_manager ) : ?><button type="button" data-orders-mode="all">See all</button><?php endif; ?></div>
        <div id="rar-recent-orders" class="rar-recent-orders"><div class="rar-dashboard-loading">Loading…</div></div>
    </section>

    <div class="rar-install-tip rar-install-tip-dark"><strong>Phone tip:</strong> Chrome → Add to Home Screen to use this like an app.</div>
    <div class="rar-app-meta">Secure staff workspace · v<?php echo esc_html( RAR_WSO_VERSION ); ?> · operations workspace</div>
</section>

<section id="rar-stock" class="rar-view">
    <div class="rar-section-head">
        <button class="rar-back" data-view="dashboard" aria-label="Back to dashboard">←</button>
        <div><h2>Stock Manager</h2><small>Search, filter, quick-adjust and review movement history</small></div>
    </div>

    <div class="rar-stock-summary">
        <button type="button" data-stock-filter="all" class="active">All <span id="stock-count-all">—</span></button>
        <button type="button" data-stock-filter="high">Healthy <span id="stock-count-high">—</span></button>
        <button type="button" data-stock-filter="low">Low <span id="stock-count-low">—</span></button>
        <button type="button" data-stock-filter="out">Out <span id="stock-count-out">—</span></button>
        <button type="button" data-stock-filter="unmanaged">Unmanaged <span id="stock-count-unmanaged">—</span></button>
    </div>

    <div class="rar-stock-legend">
        <span class="legend-high">11+ Healthy</span>
        <span class="legend-low">1–10 Low</span>
        <span class="legend-out">0 / Out</span>
        <span class="legend-unmanaged">Unmanaged</span>
    </div>

    <div class="rar-search-wrap">
        <input id="rar-stock-search" type="search" placeholder="Search item name or SKU…" autocomplete="off">
    </div>

    <div class="rar-stock-helper">Tap any product card to open quick stock controls and Movement Log.</div>
    <div id="rar-stock-list" class="rar-product-list"></div>
    <button id="rar-stock-more" class="rar-secondary rar-load-more" type="button" hidden>Load more</button>
</section>

<section id="rar-order" class="rar-view">
    <div class="rar-section-head">
        <button class="rar-back" data-view="dashboard" aria-label="Back to dashboard">←</button>
        <div><h2>Create Order</h2><small>Fast sales order entry</small></div>
    </div>

    <div class="rar-order-meta">
        <div><span>Date</span><strong id="rar-order-date">—</strong></div>
        <div><span>Order No.</span><strong id="rar-order-number">Auto on save</strong></div>
    </div>

    <form id="rar-order-form" novalidate>
        <div class="rar-card">
            <div class="rar-card-title"><span>1</span><div><strong>Customer Details</strong><small>Who is placing this order?</small></div></div>
            <div class="rar-customer-grid">
                <label class="wide"><span>Full Name *</span><input name="name" autocomplete="name" placeholder="Customer's full name" required></label>
                <label><span>Contact No. *</span><div class="rar-phone-field"><b>+88</b><input name="phone" inputmode="numeric" autocomplete="tel" placeholder="01XXXXXXXXX" pattern="01[3-9][0-9]{8}" maxlength="11" required></div><small class="rar-field-hint">11-digit Bangladesh mobile number</small></label>
                <label><span>Email <em>(optional)</em></span><input name="email" type="email" autocomplete="email" placeholder="For order status updates"></label>
            </div>
        </div>

        <div class="rar-card">
            <div class="rar-card-title"><span>2</span><div><strong>Shipping Details</strong><small>Validated Bangladesh delivery location</small></div></div>
            <label><span>Full Address *</span><textarea name="address" autocomplete="street-address" rows="2" placeholder="House / Road / Area / Landmark" required></textarea></label>
            <div class="rar-customer-grid">
                <label class="rar-picker-field">
                    <span>District *</span>
                    <input name="district" id="rar-district" type="search" autocomplete="off" placeholder="Search district…" required>
                    <div id="rar-district-options" class="rar-picker-options"></div>
                </label>
                <label class="rar-picker-field">
                    <span>Town / City / Upazila *</span>
                    <input name="city" id="rar-city" type="search" autocomplete="off" placeholder="Select district first" disabled required>
                    <div id="rar-city-options" class="rar-picker-options"></div>
                </label>
            </div>
        </div>

        <div class="rar-card">
            <div class="rar-card-title"><span>3</span><div><strong>Add Items / Order Details</strong><small>Out-of-stock products cannot be added</small></div></div>
            <label><span>Search products</span><input id="rar-order-search" type="search" placeholder="Type item name or SKU…" autocomplete="off"></label>
            <div class="rar-stock-legend compact">
                <span class="legend-high">11+ Healthy</span>
                <span class="legend-low">1–10 Low</span>
                <span class="legend-out">0 / Out</span>
                <span class="legend-unmanaged">Unmanaged</span>
            </div>
            <div id="rar-order-search-results" class="rar-search-results"></div>
        </div>

        <div class="rar-card">
            <div class="rar-order-table-head"><span>SL · ITEM · QTY · RATE</span><span id="rar-item-count">0 item(s)</span></div>
            <div id="rar-order-items"></div>
            <div class="rar-summary-row"><span>Items subtotal</span><strong id="rar-subtotal">0</strong></div>
            <div class="rar-summary-row rar-discount-row">
                <label for="rar-discount-value">Discount</label>
                <div class="rar-discount-control">
                    <select id="rar-discount-type" aria-label="Discount type"><option value="fixed">৳ Amount</option><option value="percent">% Percent</option></select>
                    <input id="rar-discount-value" type="number" min="0" step="0.01" value="0">
                </div>
            </div>
            <div class="rar-summary-row"><label for="rar-shipping">Shipping</label><input id="rar-shipping" type="number" min="0" step="0.01" value="<?php echo esc_attr( $settings['default_shipping'] ); ?>"></div>
            <div class="rar-summary-row total"><span>Total</span><strong id="rar-total">0</strong></div>
            <div class="rar-summary-row rar-inwords"><span>In Words</span><strong id="rar-in-words">Zero Taka Only</strong></div>
            <label><span>Order note <em>(optional)</em></span><textarea name="note" rows="3" placeholder="Delivery instruction or internal note"></textarea></label>
        </div>

        <div class="rar-order-actions">
            <button class="rar-primary rar-save-order" type="submit" data-submit-mode="save">Save Order</button>
            <button class="rar-primary rar-share-order" type="submit" data-submit-mode="share">Save & Share</button>
        </div>
    </form>
    <div id="rar-order-result" class="rar-result" hidden></div>
</section>

<?php if ( $is_manager ) : ?>
<section id="rar-orders" class="rar-view">
    <div class="rar-section-head">
        <button class="rar-back" data-view="dashboard" aria-label="Back to dashboard">←</button>
        <div><h2 id="rar-orders-title">Orders</h2><small>Manager status control</small></div>
    </div>
    <div class="rar-order-list-toolbar">
        <input id="rar-orders-search" type="search" placeholder="Search order, customer or phone…" autocomplete="off">
        <button type="button" id="rar-orders-refresh" class="rar-secondary">Refresh</button>
    </div>
    <div id="rar-manager-orders" class="rar-manager-orders"></div>
    <button type="button" id="rar-orders-more" class="rar-secondary rar-load-more" hidden>Load more orders</button>
</section>
<?php endif; ?>
</main>
</div>

<div id="rar-stock-modal" class="rar-modal" hidden aria-hidden="true">
    <div class="rar-modal-backdrop" data-close-stock-modal></div>
    <section class="rar-modal-card" role="dialog" aria-modal="true" aria-labelledby="rar-stock-modal-title">
        <header class="rar-modal-head">
            <div>
                <span class="rar-kicker">STOCK CONTROL</span>
                <h2 id="rar-stock-modal-title">Adjust Stock</h2>
            </div>
            <button type="button" class="rar-modal-close" data-close-stock-modal aria-label="Close">×</button>
        </header>

        <div class="rar-stock-modal-product">
            <img id="rar-stock-modal-image" src="" alt="">
            <div>
                <strong id="rar-stock-modal-name">—</strong>
                <small id="rar-stock-modal-sku">—</small>
                <span id="rar-stock-modal-band" class="rar-stock-badge">—</span>
            </div>
        </div>

        <div class="rar-stock-current">
            <span>Current stock</span>
            <strong id="rar-stock-modal-current">—</strong>
        </div>

        <div class="rar-stock-quick" aria-label="Quick stock adjustment">
            <button type="button" data-stock-delta="-1">−1</button>
            <button type="button" data-stock-delta="1">+1</button>
            <button type="button" data-stock-delta="5">+5</button>
            <button type="button" data-stock-delta="10">+10</button>
        </div>

        <div class="rar-stock-exact">
            <label for="rar-stock-modal-qty">Set exact quantity</label>
            <div>
                <input id="rar-stock-modal-qty" type="number" min="0" step="1" inputmode="numeric">
                <button type="button" id="rar-stock-modal-save" class="rar-primary">Save Stock</button>
            </div>
        </div>

        <div class="rar-movement-head">
            <strong>Movement Log</strong>
            <button type="button" id="rar-stock-history-refresh">Refresh</button>
        </div>
        <div id="rar-stock-history" class="rar-stock-history"><div class="rar-dashboard-loading">Loading…</div></div>
    </section>
</div>

<div id="rar-toast" class="rar-toast" hidden></div>
<script>window.RARWSO=<?php echo wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
<script src="<?php echo esc_url( RAR_WSO_URL . 'assets/js/staff.js?ver=' . rawurlencode( RAR_WSO_VERSION ) ); ?>"></script>
</body>
</html>
<?php
        exit;
    }

    private function login_screen() {
        $settings = RAR_WSO_Plugin::settings();
        $redirect = RAR_WSO_Plugin::staff_url();
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0f7b63">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?php echo esc_html( $settings['dashboard_title'] ); ?> — Login</title>
<link rel="manifest" href="<?php echo esc_url( home_url( '/rar-wso-manifest.webmanifest' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( RAR_WSO_URL . 'assets/css/staff.css?ver=' . rawurlencode( RAR_WSO_VERSION ) ); ?>">
</head>
<body class="rar-login-page">
<div class="rar-login-card">
    <div class="rar-kicker">RAR WOO</div>
    <h1><?php echo esc_html( $settings['dashboard_title'] ); ?></h1>
    <p>Secure staff login</p>
    <?php wp_login_form( array( 'redirect' => $redirect, 'remember' => true ) ); ?>
</div>
</body>
</html>
<?php
        exit;
    }

    private function simple_page( $title, $message ) {
        ?>
<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="<?php echo esc_url( RAR_WSO_URL . 'assets/css/staff.css?ver=' . rawurlencode( RAR_WSO_VERSION ) ); ?>">
</head>
<body class="rar-login-page">
<div class="rar-login-card">
    <h1><?php echo esc_html( $title ); ?></h1>
    <p><?php echo esc_html( $message ); ?></p>
    <a class="rar-primary" href="<?php echo esc_url( wp_logout_url( RAR_WSO_Plugin::staff_url() ) ); ?>">Log out</a>
</div>
</body>
</html>
<?php
        exit;
    }
}
