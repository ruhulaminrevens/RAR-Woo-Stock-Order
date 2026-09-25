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
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-data.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-admin.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-ajax.php';
        require_once RAR_WSO_PATH . 'includes/class-rar-wso-pwa.php';

        new RAR_WSO_Admin();
        new RAR_WSO_Ajax();
        new RAR_WSO_PWA();

        add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );
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
            'default_shipping'     => '0',
            'dashboard_title'      => 'Woo Stock & Order',
        );
    }

    public static function settings() {
        $defaults = self::defaults();
        $stored   = (array) get_option( 'rar_wso_settings', array() );
        $stored   = array_intersect_key( $stored, $defaults );
        return array_merge( $defaults, $stored );
    }

    public static function staff_url() {
        $settings = self::settings();
        $slug = sanitize_title( $settings['staff_slug'] );
        return home_url( '/' . ( $slug ? $slug : 'staff' ) . '/' );
    }

    public static function activate() {
        self::register_roles_and_caps();
        if ( ! get_option( 'rar_wso_settings', false ) ) {
            add_option( 'rar_wso_settings', self::defaults() );
        } else {
            update_option( 'rar_wso_settings', self::settings() );
        }
        update_option( 'rar_wso_version', RAR_WSO_VERSION, false );
        update_option( 'rar_wso_flush_rewrite', 1, false );
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function maybe_upgrade() {
        $installed = (string) get_option( 'rar_wso_version', '0' );
        if ( version_compare( $installed, RAR_WSO_VERSION, '>=' ) ) {
            return;
        }

        self::register_roles_and_caps();
        update_option( 'rar_wso_settings', self::settings() );
        update_option( 'rar_wso_version', RAR_WSO_VERSION, false );
        update_option( 'rar_wso_flush_rewrite', 1, false );
    }

    public static function register_roles_and_caps() {
        $staff_caps = array(
            'read'                  => true,
            'rar_wso_access'        => true,
            'rar_wso_manage_stock'  => true,
            'rar_wso_create_orders' => true,
            'rar_wso_adjust_price'  => true,
        );

        add_role( 'rar_wso_staff', __( 'Woo Stock & Order Staff', 'rar-woo-stock-order' ), $staff_caps );

        $staff_role = get_role( 'rar_wso_staff' );
        if ( $staff_role ) {
            foreach ( $staff_caps as $cap => $grant ) {
                if ( $grant ) {
                    $staff_role->add_cap( $cap );
                }
            }
            // Remove legacy catalog-management capabilities from pre-1.1 installs.
            $staff_role->remove_cap( 'rar_wso_add_products' );
            $staff_role->remove_cap( 'rar_wso_delete_products' );
        }

        foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }
            foreach ( array( 'rar_wso_access', 'rar_wso_manage_stock', 'rar_wso_create_orders', 'rar_wso_adjust_price' ) as $cap ) {
                $role->add_cap( $cap );
            }
            $role->remove_cap( 'rar_wso_add_products' );
            $role->remove_cap( 'rar_wso_delete_products' );
        }
    }

    public static function can( $cap ) {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( $cap );
    }
}
