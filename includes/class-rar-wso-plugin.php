<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_WSO_Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-admin.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-ajax.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-pwa.php';

        new RAR_WSO_Admin();
        new RAR_WSO_Ajax();
        new RAR_WSO_PWA();

        add_filter( 'plugin_action_links_' . plugin_basename( RAR_WSO_FILE ), array( $this, 'plugin_action_links' ) );
    }

    public function plugin_action_links( $links ) {
        $url = admin_url( 'admin.php?page=rar-wso' );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'rar-woo-stock-order' ) . '</a>' );
        return $links;
    }

    public static function defaults() {
        return array(
            'enabled'              => 'yes',
            'staff_slug'           => 'staff',
            'default_order_status' => 'processing',
            'allow_price_override' => 'yes',
            'allow_product_add'    => 'no',
            'allow_product_delete' => 'no',
            'default_shipping'     => '0',
            'dashboard_title'      => 'Woo Stock & Order',
        );
    }

    public static function settings() {
        return wp_parse_args( get_option( 'rar_wso_settings', array() ), self::defaults() );
    }

    public static function staff_url() {
        $settings = self::settings();
        $slug = sanitize_title( $settings['staff_slug'] );
        return home_url( '/' . ( $slug ? $slug : 'staff' ) . '/' );
    }

    public static function activate() {
        self::register_role();
        if ( ! get_option( 'rar_wso_settings', false ) ) {
            add_option( 'rar_wso_settings', self::defaults() );
        }
        update_option( 'rar_wso_flush_rewrite', 1, false );
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function register_role() {
        $caps = array(
            'read'                  => true,
            'rar_wso_access'        => true,
            'rar_wso_manage_stock'  => true,
            'rar_wso_create_orders' => true,
            'rar_wso_adjust_price'  => true,
        );
        add_role( 'rar_wso_staff', __( 'Woo Stock & Order Staff', 'rar-woo-stock-order' ), $caps );

        foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( $role ) {
                foreach ( array( 'rar_wso_access', 'rar_wso_manage_stock', 'rar_wso_create_orders', 'rar_wso_adjust_price', 'rar_wso_add_products', 'rar_wso_delete_products' ) as $cap ) {
                    $role->add_cap( $cap );
                }
            }
        }
    }

    public static function can( $cap ) {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( $cap );
    }
}
