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
<section id="rar-dashboard" class="rar-view active">
    <div class="rar-datebar">
        <span>Today's Date</span>
        <strong id="rar-live-clock">—</strong>
    </div>

    <div class="rar-stats rar-stats-orders">
        <button class="rar-stat stat-blue" type="button">
            <span>Today's Orders</span><strong id="stat-orders">—</strong><small>Total orders today</small>
        </button>
        <button class="rar-stat stat-teal" type="button">
            <span>Today's Sales</span><strong id="stat-sales">—</strong><small>Total active sales</small>
        </button>
        <button class="rar-stat stat-green" type="button">
            <span>Completed Orders</span><strong id="stat-completed">—</strong><small>Completed today</small>
        </button>
        <button class="rar-stat stat-red" type="button">
            <span>Returned / Cancelled</span><strong id="stat-returned">—</strong><small>Returned, refunded or cancelled</small>
        </button>
    </div>

    <div class="rar-block-title">
        <div><span>Inventory overview</span><small>Tap a card to open filtered stock</small></div>
    </div>
    <div class="rar-inventory-cards">
        <button class="rar-inventory-card inventory-all" type="button" data-stock-filter="all">
            <span class="icon">▦</span><span>All Stock</span><strong id="stat-all-stock">—</strong><small>All published stock items</small>
        </button>
        <button class="rar-inventory-card inventory-live" type="button" data-stock-filter="available">
            <span class="icon">✓</span><span>Available / Live</span><strong id="stat-available">—</strong><small>Ready to sell</small>
        </button>
        <button class="rar-inventory-card inventory-out" type="button" data-stock-filter="out">
            <span class="icon">!</span><span>Out of Stock</span><strong id="stat-out">—</strong><small>Needs attention</small>
        </button>
    </div>

    <div class="rar-actions-home">
        <?php if ( RAR_WSO_Plugin::can( 'rar_wso_manage_stock' ) ) : ?>
            <button class="rar-big-btn action-stock" data-view="stock" type="button"><span>📦</span><strong>Stock Manager</strong><small>Search, filter & update inventory</small></button>
        <?php endif; ?>
        <?php if ( RAR_WSO_Plugin::can( 'rar_wso_create_orders' ) ) : ?>
            <button class="rar-big-btn action-order" data-view="order" type="button"><span>🧾</span><strong>Create Order</strong><small>Fast mobile sales order entry</small></button>
        <?php endif; ?>
    </div>

    <?php if ( $is_manager ) : ?>
    <div class="rar-manager-zone">
        <div class="rar-block-title">
            <div><span>Manager Control Center</span><small>Live order actions and sales intelligence</small></div>
        </div>
        <div class="rar-manager-actions">
            <button type="button" class="rar-manager-btn" data-orders-mode="all"><span>📋</span><strong>All Orders</strong><small>View and update every recent order</small></button>
            <button type="button" class="rar-manager-btn" data-orders-mode="live"><span>⚡</span><strong>Live Orders</strong><small>Only running / incomplete orders</small></button>
        </div>
        <div class="rar-analytics-card">
            <div class="rar-analytics-head">
                <div><span>7-Day Sales</span><strong id="rar-week-sales">—</strong></div>
                <div class="rar-growth" id="rar-growth">—</div>
            </div>
            <div id="rar-sales-chart" class="rar-sales-chart" aria-label="7 day sales bar chart"></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="rar-install-tip"><strong>Phone tip:</strong> Chrome → Add to Home Screen to use this like an app.</div>
    <div class="rar-app-meta">Secure staff workspace · v<?php echo esc_html( RAR_WSO_VERSION ); ?></div>
</section>

<section id="rar-stock" class="rar-view">
    <div class="rar-section-head">
        <button class="rar-back" data-view="dashboard" aria-label="Back to dashboard">←</button>
        <div><h2>Stock Manager</h2><small>Color-coded live inventory control</small></div>
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
                <label><span>Contact No. *</span><input name="phone" inputmode="tel" autocomplete="tel" placeholder="+8801XXXXXXXXX" required></label>
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
            <div class="rar-order-table-head"><span>Items</span><span id="rar-item-count">0</span></div>
            <div id="rar-order-items"></div>
            <div class="rar-summary-row"><span>Items subtotal</span><strong id="rar-subtotal">0</strong></div>
            <div class="rar-summary-row rar-discount-row">
                <label for="rar-discount-value">Discount</label>
                <div class="rar-discount-control">
                    <select id="rar-discount-type" aria-label="Discount type"><option value="fixed">Amount</option><option value="percent">%</option></select>
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
</section>
<?php endif; ?>
</main>
</div>

<div id="rar-toast" class="rar-toast" hidden></div>
<script>window.RARWSO=<?php echo wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
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
