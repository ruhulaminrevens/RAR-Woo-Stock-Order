<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RAR_WSO_Ajax {
    public function __construct() {
        foreach ( array(
            'stats'               => 'stats',
            'products'            => 'products',
            'stock_update'        => 'stock_update',
            'stock_adjust'        => 'stock_adjust',
            'stock_history'       => 'stock_history',
            'create_order'        => 'create_order',
            'manager_orders'      => 'manager_orders',
            'update_order_status' => 'update_order_status',
            'undo_order_status'   => 'undo_order_status',
        ) as $action => $method ) {
            add_action( 'wp_ajax_rar_wso_' . $action, array( $this, $method ) );
        }
    }

    private function ensure_enabled() {
        $settings = RAR_WSO_Plugin::settings();
        if ( 'yes' !== $settings['enabled'] ) {
            wp_send_json_error(
                array( 'message' => __( 'The staff app is disabled in WooCommerce → Stock & Order settings.', 'rar-woo-stock-order' ) ),
                403
            );
        }
    }

    private function guard( $cap = 'rar_wso_access' ) {
        check_ajax_referer( 'rar_wso_nonce', 'nonce' );
        $this->ensure_enabled();

        if ( ! is_user_logged_in() || ! RAR_WSO_Plugin::can( $cap ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to perform this action.', 'rar-woo-stock-order' ) ),
                403
            );
        }
    }

    private function manager_guard() {
        check_ajax_referer( 'rar_wso_nonce', 'nonce' );
        $this->ensure_enabled();

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Manager permission is required for this action.', 'rar-woo-stock-order' ) ),
                403
            );
        }
    }

    private function product_payload( $product ) {
        if ( ! $product ) {
            return null;
        }

        $image_id = $product->get_image_id();
        $image    = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
        $name     = $product->get_name();

        if ( $product->is_type( 'variation' ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $name = $parent->get_name() . ' — ' . wc_get_formatted_variation( $product, true, false, false );
            }
        }
        $name = RAR_WSO_Data::plain_text( $name );

        $stock = $product->get_manage_stock() ? $product->get_stock_quantity() : null;
        $band  = RAR_WSO_Data::stock_band( $product );

        return array(
            'id'            => $product->get_id(),
            'name'          => wp_strip_all_tags( $name ),
            'sku'           => $product->get_sku(),
            'price'         => (float) wc_get_price_to_display( $product ),
            'regular_price' => $product->get_regular_price() === '' ? null : (float) wc_get_price_to_display(
                $product,
                array( 'price' => (float) $product->get_regular_price() )
            ),
            'sale_price'    => $product->get_sale_price() === '' ? null : (float) wc_get_price_to_display(
                $product,
                array( 'price' => (float) $product->get_sale_price() )
            ),
            'stock_qty'     => is_null( $stock ) ? '' : (float) $stock,
            'manage_stock'  => (bool) $product->get_manage_stock(),
            'stock_status'  => $product->get_stock_status(),
            'stock_band'    => $band,
            'can_add'       => $product->is_purchasable() && 'out' !== $band,
            'image'         => $image,
            'type'          => $product->get_type(),
        );
    }

    private function registered_order_status_slugs() {
        return array_keys( RAR_WSO_Data::order_statuses() );
    }

    public function stats() {
        $this->guard();

        $period         = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : 'today';
        $manager_period = isset( $_POST['manager_period'] ) ? sanitize_key( wp_unslash( $_POST['manager_period'] ) ) : '30days';

        $is_manager = current_user_can( 'manage_woocommerce' );
        $cache_key  = 'rar_wso_dash_' . md5( $period . '|' . $manager_period . '|' . ( $is_manager ? 'm' : 's' ) . '|' . get_current_user_id() );
        $version    = (string) get_option( 'rar_wso_report_ver', '0' );
        $cached     = get_transient( $cache_key );

        if ( is_array( $cached ) && isset( $cached['ver'], $cached['data'] ) && $cached['ver'] === $version ) {
            wp_send_json_success( $cached['data'] );
        }

        try {
            $data = RAR_WSO_Reports::dashboard( $period, $manager_period, $is_manager );
            // Short cache: busy stores refresh every minute without re-reading every order each time.
            set_transient( $cache_key, array( 'ver' => $version, 'data' => $data ), MINUTE_IN_SECONDS );
        } catch ( Throwable $e ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    'Dashboard API failed: ' . $e->getMessage(),
                    array( 'source' => 'rar-wso' )
                );
            }

            wp_send_json_error(
                array( 'message' => __( 'Dashboard data is temporarily unavailable. Please retry.', 'rar-woo-stock-order' ) ),
                500
            );
        }

        wp_send_json_success( $data );
    }

    private function matches_filter( $product, $filter ) {
        $band = RAR_WSO_Data::stock_band( $product );

        switch ( $filter ) {
            case 'available':
                return in_array( $band, array( 'high', 'low' ), true );
            case 'out':
                return 'out' === $band;
            case 'high':
                return 'high' === $band;
            case 'low':
                return 'low' === $band;
            case 'unmanaged':
                return 'unmanaged' === $band;
            default:
                return true;
        }
    }

    private function expand_product( $product ) {
        if ( ! $product ) {
            return array();
        }

        if ( ! $product->is_type( 'variable' ) ) {
            return array( $product );
        }

        $expanded = array();
        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( $variation && 'publish' === get_post_status( $variation_id ) ) {
                $expanded[] = $variation;
            }
        }

        return $expanded;
    }

    private function inventory_product_ids( $filter, $page, $limit ) {
        global $wpdb;

        $lookup = $wpdb->wc_product_meta_lookup;
        $posts  = $wpdb->posts;

        $condition = '';
        switch ( $filter ) {
            case 'available':
                $condition = " AND l.stock_status='instock' AND l.stock_quantity > 0";
                break;
            case 'out':
                $condition = " AND (l.stock_status='outofstock' OR (l.stock_quantity IS NOT NULL AND l.stock_quantity <= 0))";
                break;
            case 'high':
                $condition = " AND l.stock_status='instock' AND l.stock_quantity >= 11";
                break;
            case 'low':
                $condition = " AND l.stock_status='instock' AND l.stock_quantity BETWEEN 1 AND 10";
                break;
            case 'unmanaged':
                $condition = " AND l.stock_status='instock' AND l.stock_quantity IS NULL";
                break;
        }

        $offset = max( 0, ( $page - 1 ) * $limit );
        $sql    = "
            SELECT p.ID
            FROM {$lookup} l
            INNER JOIN {$posts} p ON p.ID=l.product_id
            WHERE p.post_status='publish'
              AND p.post_type IN ('product','product_variation')
              AND NOT (
                p.post_type='product'
                AND EXISTS (
                    SELECT 1
                    FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                    WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' AND t.slug='variable'
                )
              )
              {$condition}
            ORDER BY p.post_title ASC, p.ID ASC
            LIMIT %d OFFSET %d
        ";

        return array_map(
            'absint',
            $wpdb->get_col(
                $wpdb->prepare( $sql, $limit + 1, $offset ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            )
        );
    }

    public function products() {
        $this->guard();

        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $filter = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( $_POST['filter'] ) ) : 'all';
        $page   = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
        $limit  = 40;

        $allowed_filters = array( 'all', 'available', 'out', 'high', 'low', 'unmanaged' );
        if ( ! in_array( $filter, $allowed_filters, true ) ) {
            $filter = 'all';
        }

        $items    = array();
        $has_more = false;

        if ( '' !== $search ) {
            $data_store = WC_Data_Store::load( 'product' );
            $ids        = $data_store->search_products( $search, '', true, false, 200 );
            $seen       = array();
            $filtered   = array();

            foreach ( $ids as $id ) {
                $product = wc_get_product( $id );
                if ( ! $product || 'publish' !== get_post_status( $product->get_id() ) ) {
                    continue;
                }

                foreach ( $this->expand_product( $product ) as $candidate ) {
                    if ( ! $candidate || isset( $seen[ $candidate->get_id() ] ) ) {
                        continue;
                    }

                    $seen[ $candidate->get_id() ] = true;

                    if ( ! $this->matches_filter( $candidate, $filter ) ) {
                        continue;
                    }

                    $needle = strtolower( $search );
                    $name   = strtolower( wp_strip_all_tags( $candidate->get_name() ) );
                    $sku    = strtolower( (string) $candidate->get_sku() );
                    if ( false === strpos( $name, $needle ) && false === strpos( $sku, $needle ) ) {
                        continue;
                    }

                    $filtered[] = $candidate;
                }
            }

            usort(
                $filtered,
                static function ( $a, $b ) {
                    return strcasecmp( $a->get_name(), $b->get_name() );
                }
            );

            $offset   = ( $page - 1 ) * $limit;
            $has_more = count( $filtered ) > ( $offset + $limit );

            foreach ( array_slice( $filtered, $offset, $limit ) as $product ) {
                $payload = $this->product_payload( $product );
                if ( $payload ) {
                    $items[] = $payload;
                }
            }
        } else {
            $ids      = $this->inventory_product_ids( $filter, $page, $limit );
            $has_more = count( $ids ) > $limit;
            $ids      = array_slice( $ids, 0, $limit );

            foreach ( $ids as $id ) {
                $payload = $this->product_payload( wc_get_product( $id ) );
                if ( $payload ) {
                    $items[] = $payload;
                }
            }
        }

        wp_send_json_success(
            array(
                'items'    => $items,
                'page'     => $page,
                'has_more' => $has_more,
                'filter'   => $filter,
            )
        );
    }

    private function append_stock_movement( $product_id, $old, $new, $source = 'manual' ) {
        $product_id = absint( $product_id );
        $user       = wp_get_current_user();
        $entries    = get_post_meta( $product_id, '_rar_wso_stock_movements', true );
        $entries    = is_array( $entries ) ? $entries : array();

        $entry = array(
            'id'        => wp_generate_uuid4(),
            'time'      => current_time( 'mysql' ),
            'timestamp' => current_time( 'timestamp' ),
            'user_id'   => get_current_user_id(),
            'user'      => $user && $user->exists() ? $user->display_name : __( 'System', 'rar-woo-stock-order' ),
            'from'      => is_null( $old ) ? null : (float) $old,
            'to'        => (float) $new,
            'delta'     => (float) $new - ( is_null( $old ) ? 0.0 : (float) $old ),
            'source'    => sanitize_key( $source ),
        );

        array_unshift( $entries, $entry );
        $entries = array_slice( $entries, 0, 60 );

        update_post_meta( $product_id, '_rar_wso_stock_movements', $entries );
        update_post_meta( $product_id, '_rar_wso_last_stock_update', wp_json_encode( $entry ) );

        return $entry;
    }

    private function stock_history_payload( $product_id ) {
        $entries = get_post_meta( $product_id, '_rar_wso_stock_movements', true );
        $entries = is_array( $entries ) ? array_slice( $entries, 0, 30 ) : array();

        return array_map(
            static function ( $entry ) {
                return array(
                    'id'     => sanitize_text_field( $entry['id'] ?? '' ),
                    'time'   => sanitize_text_field( $entry['time'] ?? '' ),
                    'user'   => sanitize_text_field( $entry['user'] ?? '' ),
                    'from'   => array_key_exists( 'from', $entry ) && null !== $entry['from'] ? (float) $entry['from'] : null,
                    'to'     => (float) ( $entry['to'] ?? 0 ),
                    'delta'  => (float) ( $entry['delta'] ?? 0 ),
                    'source' => sanitize_key( $entry['source'] ?? 'manual' ),
                );
            },
            $entries
        );
    }

    /**
     * Change stock through WooCommerce's atomic stock API (a concurrent checkout's
     * reduction is never overwritten). Unmanaged products start being managed.
     *
     * @param WC_Product $product Product.
     * @param string     $mode    'set' or 'delta'.
     * @param mixed      $amount  Quantity or signed change.
     * @return array [ fresh WC_Product, new quantity ]
     */
    private function write_stock( $product, $mode, $amount ) {
        $id = $product->get_id();

        if ( ! $product->managing_stock() ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( 0 );
            $product->save();
            $product = wc_get_product( $id );
        }

        if ( 'set' === $mode ) {
            $new = wc_update_product_stock( $product, max( 0, wc_stock_amount( $amount ) ), 'set' );
        } else {
            $delta   = wc_stock_amount( $amount );
            $current = (float) $product->get_stock_quantity();
            if ( $delta >= 0 ) {
                $new = wc_update_product_stock( $product, $delta, 'increase' );
            } elseif ( $current + $delta < 0 ) {
                $new = wc_update_product_stock( $product, 0, 'set' );
            } else {
                $new = wc_update_product_stock( $product, abs( $delta ), 'decrease' );
            }
        }

        $fresh = wc_get_product( $id );
        return array( $fresh ? $fresh : $product, (float) $new );
    }

    public function stock_update() {
        $this->guard( 'rar_wso_manage_stock' );

        $id  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $raw = isset( $_POST['qty'] ) ? wc_format_decimal( wp_unslash( $_POST['qty'] ) ) : '';

        if ( ! $id || '' === $raw || ! is_numeric( $raw ) ) {
            wp_send_json_error(
                array( 'message' => __( 'A valid product and stock quantity are required.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        $product = wc_get_product( $id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'rar-woo-stock-order' ) ), 404 );
        }

        $old = $product->managing_stock() ? $product->get_stock_quantity() : null;
        list( $product, $qty ) = $this->write_stock( $product, 'set', $raw );

        $movement = $this->append_stock_movement( $id, $old, $qty, 'manual_set' );

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info(
                sprintf(
                    'Stock update product #%d: %s -> %s by user #%d',
                    $id,
                    is_null( $old ) ? 'unmanaged' : $old,
                    $qty,
                    get_current_user_id()
                ),
                array( 'source' => 'rar-wso' )
            );
        }

        do_action( 'rar_wso_stock_updated', $product, $old, $qty, get_current_user_id() );

        wp_send_json_success(
            array(
                'message'  => __( 'Stock updated.', 'rar-woo-stock-order' ),
                'product'  => $this->product_payload( $product ),
                'movement' => $movement,
            )
        );
    }

    public function stock_adjust() {
        $this->guard( 'rar_wso_manage_stock' );

        $id    = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $delta = isset( $_POST['delta'] ) ? (int) wp_unslash( $_POST['delta'] ) : 0;

        if ( ! $id || ! in_array( $delta, array( -10, -5, -1, 1, 5, 10 ), true ) ) {
            wp_send_json_error(
                array( 'message' => __( 'A valid product and stock adjustment are required.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        $product = wc_get_product( $id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'rar-woo-stock-order' ) ), 404 );
        }

        $old = $product->managing_stock() ? $product->get_stock_quantity() : null;
        list( $product, $qty ) = $this->write_stock( $product, 'delta', $delta );

        $movement = $this->append_stock_movement( $id, $old, $qty, 'quick_adjust' );

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info(
                sprintf(
                    'Quick stock adjustment product #%d: %s -> %s (%+d) by user #%d',
                    $id,
                    is_null( $old ) ? 'unmanaged' : $old,
                    $qty,
                    $delta,
                    get_current_user_id()
                ),
                array( 'source' => 'rar-wso' )
            );
        }

        do_action( 'rar_wso_stock_updated', $product, $old, $qty, get_current_user_id() );

        wp_send_json_success(
            array(
                'message'  => __( 'Stock adjusted.', 'rar-woo-stock-order' ),
                'product'  => $this->product_payload( $product ),
                'movement' => $movement,
                'history'  => $this->stock_history_payload( $id ),
            )
        );
    }

    public function stock_history() {
        $this->guard( 'rar_wso_manage_stock' );

        $id      = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $product = $id ? wc_get_product( $id ) : false;

        if ( ! $product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'rar-woo-stock-order' ) ), 404 );
        }

        wp_send_json_success(
            array(
                'product' => $this->product_payload( $product ),
                'history' => $this->stock_history_payload( $id ),
            )
        );
    }

    public function create_order() {
        $this->guard( 'rar_wso_create_orders' );

        $payload = isset( $_POST['payload'] ) ? json_decode( wp_unslash( $_POST['payload'] ), true ) : null;
        if ( ! is_array( $payload ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order data.', 'rar-woo-stock-order' ) ), 422 );
        }

        $name       = sanitize_text_field( $payload['name'] ?? '' );
        $phone      = RAR_WSO_Data::normalize_bd_phone( $payload['phone'] ?? '' );
        $email      = sanitize_email( $payload['email'] ?? '' );
        $address    = sanitize_text_field( $payload['address'] ?? '' );
        $district   = RAR_WSO_Data::canonical_district( $payload['district'] ?? '' );
        $city       = RAR_WSO_Data::canonical_city( $district, $payload['city'] ?? '' );
        $note       = sanitize_textarea_field( $payload['note'] ?? '' );
        $shipping   = max( 0, (float) wc_format_decimal( $payload['shipping'] ?? 0 ) );
        $request_id = sanitize_key( $payload['request_id'] ?? '' );
        $items      = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();

        if ( '' === $name || '' === $phone || '' === $address || '' === $district || '' === $city || empty( $items ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Full name, valid Bangladesh phone, full address, district, town/city and at least one item are required.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        if ( $email && ! is_email( $email ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Please enter a valid email address.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        $district_code = RAR_WSO_Data::district_to_state_code( $district );
        if ( '' === $district_code ) {
            wp_send_json_error(
                array( 'message' => __( 'Please select a valid Bangladesh district.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        $dedupe_key = '';
        if ( '' !== $request_id ) {
            $dedupe_key = 'rar_wso_req_' . md5( get_current_user_id() . '|' . $request_id );
            $existing   = absint( get_transient( $dedupe_key ) );
            if ( $existing ) {
                $existing_order = wc_get_order( $existing );
                if ( $existing_order ) {
                    $this->send_order_success(
                        $existing_order,
                        __( 'This order was already created. Showing the existing order instead of creating a duplicate.', 'rar-woo-stock-order' ),
                        true
                    );
                }
            }
        }

        $settings     = RAR_WSO_Plugin::settings();
        $can_override = 'yes' === $settings['allow_price_override'] && RAR_WSO_Plugin::can( 'rar_wso_adjust_price' );
        $shipping     = max(
            0,
            (float) apply_filters( 'rar_wso_shipping_total', $shipping, $payload, get_current_user_id() )
        );

        // Validate every line before anything is written, so a rejected order never
        // creates an empty WooCommerce order (or fires new-order integrations).
        $lines = array();
        foreach ( $items as $raw ) {
            $product_id = absint( $raw['id'] ?? 0 );
            $qty        = max( 1, absint( $raw['qty'] ?? 1 ) );
            $product    = wc_get_product( $product_id );

            if ( ! $product || ! $product->is_purchasable() || 'out' === RAR_WSO_Data::stock_band( $product ) ) {
                wp_send_json_error(
                    array(
                        'message' => sprintf(
                            /* translators: %s product name */
                            __( '%s is not available for ordering.', 'rar-woo-stock-order' ),
                            $product ? RAR_WSO_Data::plain_text( $product->get_name() ) : '#' . $product_id
                        ),
                    ),
                    422
                );
            }

            $stock_qty = $product->get_stock_quantity();
            if ( $product->managing_stock() && null !== $stock_qty && $qty > (float) $stock_qty && ! $product->backorders_allowed() ) {
                wp_send_json_error(
                    array(
                        'message' => sprintf(
                            /* translators: 1: quantity 2: product name */
                            __( 'Only %1$s unit(s) of %2$s are currently in stock.', 'rar-woo-stock-order' ),
                            wc_format_localized_decimal( $stock_qty ),
                            RAR_WSO_Data::plain_text( $product->get_name() )
                        ),
                    ),
                    422
                );
            }
            $lines[] = array( $product, $qty, $raw );
        }

        try {
            $order = wc_create_order( array( 'status' => 'pending' ) );
            if ( is_wp_error( $order ) ) {
                throw new Exception( $order->get_error_message() );
            }

            $parts = preg_split( '/\s+/', trim( $name ), 2 );
            $first = $parts[0] ?? $name;
            $last  = $parts[1] ?? '';

            $billing = array(
                'first_name' => $first,
                'last_name'  => $last,
                'phone'      => $phone,
                'email'      => $email,
                'address_1'  => $address,
                'city'       => $city,
                'state'      => $district_code,
                'country'    => 'BD',
            );

            $order->set_address( $billing, 'billing' );
            $order->set_address( $billing, 'shipping' );

            $items_subtotal = 0.0;
            $audit          = array();

            foreach ( $lines as $line ) {
                list( $product, $qty, $raw ) = $line;
                $product_id = $product->get_id();

                if ( ! $product->is_purchasable() || 'out' === RAR_WSO_Data::stock_band( $product ) ) {
                    throw new Exception(
                        sprintf(
                            __( '%s is not available for ordering.', 'rar-woo-stock-order' ),
                            $product ? wp_strip_all_tags( $product->get_name() ) : '#' . $product_id
                        )
                    );
                }

                $stock_qty = $product->get_stock_quantity();
                if (
                    $product->get_manage_stock() &&
                    null !== $stock_qty &&
                    $qty > (float) $stock_qty &&
                    ! $product->backorders_allowed()
                ) {
                    throw new Exception(
                        sprintf(
                            __( 'Only %1$s unit(s) of %2$s are currently in stock.', 'rar-woo-stock-order' ),
                            wc_format_localized_decimal( $stock_qty ),
                            wp_strip_all_tags( $product->get_name() )
                        )
                    );
                }

                $base  = (float) $product->get_price();
                $price = $base;

                if ( $can_override && isset( $raw['price'] ) && is_numeric( $raw['price'] ) ) {
                    $price = max( 0, (float) wc_format_decimal( $raw['price'] ) );
                }

                $line_total    = $price * $qty;
                $items_subtotal += $line_total;

                $item_id = $order->add_product(
                    $product,
                    $qty,
                    array(
                        'subtotal' => $line_total,
                        'total'    => $line_total,
                    )
                );

                if ( ! $item_id ) {
                    throw new Exception( __( 'Could not add one of the selected products to the order.', 'rar-woo-stock-order' ) );
                }

                if ( $can_override && abs( $price - $base ) > 0.0001 ) {
                    wc_add_order_item_meta( $item_id, '_rar_wso_price_override', wc_format_decimal( $price ), true );
                    $audit[] = sprintf(
                        '%s: %s → %s × %s',
                        RAR_WSO_Data::plain_text( $product->get_name() ),
                        wc_format_decimal( $base, wc_get_price_decimals() ),
                        wc_format_decimal( $price, wc_get_price_decimals() ),
                        $qty
                    );
                }
            }

            $discount_type  = sanitize_key( $payload['discount_type'] ?? 'fixed' );
            $discount_value = max( 0, (float) wc_format_decimal( $payload['discount_value'] ?? 0 ) );
            $discount       = 0.0;

            if ( 'percent' === $discount_type ) {
                $discount = $items_subtotal * min( 100, $discount_value ) / 100;
            } else {
                $discount = min( $items_subtotal, $discount_value );
            }

            $discount = round( $discount, wc_get_price_decimals() );

            if ( $discount > 0 ) {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name( __( 'Discount', 'rar-woo-stock-order' ) );
                $fee->set_amount( -$discount );
                $fee->set_total( -$discount );
                $fee->set_tax_status( 'none' );
                $order->add_item( $fee );
                $order->update_meta_data( '_rar_wso_discount', wc_format_decimal( $discount ) );
                $order->update_meta_data( '_rar_wso_discount_type', $discount_type );
            }

            if ( $shipping > 0 ) {
                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_title( __( 'Staff order delivery', 'rar-woo-stock-order' ) );
                $shipping_item->set_method_id( 'rar_wso_manual_shipping' );
                $shipping_item->set_total( $shipping );
                $order->add_item( $shipping_item );
            }

            $payment_method = sanitize_key( apply_filters( 'rar_wso_payment_method', 'cod', $payload, $order ) );
            $payment_title  = __( 'Cash on delivery', 'woocommerce' );

            if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
                $gateways = WC()->payment_gateways()->payment_gateways();
                if ( isset( $gateways[ $payment_method ] ) ) {
                    $payment_title = $gateways[ $payment_method ]->get_title();
                }
            }

            $payment_title = sanitize_text_field(
                apply_filters( 'rar_wso_payment_method_title', $payment_title, $payment_method, $payload, $order )
            );

            $order->set_payment_method( $payment_method );
            $order->set_payment_method_title( $payment_title );
            $order->set_created_via( 'rar-wso-staff' );
            $order->update_meta_data( '_rar_wso_created_by', get_current_user_id() );
            $order->update_meta_data( '_rar_wso_channel', 'staff-pwa' );

            if ( '' !== $request_id ) {
                $order->update_meta_data( '_rar_wso_request_id', $request_id );
            }

            if ( $note ) {
                $order->set_customer_note( $note );
            }

            $order->calculate_totals( true );
            $order->save();

            $user = wp_get_current_user();
            $order->add_order_note(
                sprintf(
                    'Created via RAR Woo Stock & Order by %s (#%d).',
                    $user->display_name,
                    $user->ID
                ),
                false,
                true
            );

            // Visible audit trail for price overrides and discounts given in the staff app.
            if ( $audit || $discount > 0 ) {
                $note = array();
                if ( $audit ) {
                    $note[] = 'Price override (regular → charged × qty): ' . implode( '; ', $audit );
                }
                if ( $discount > 0 ) {
                    $note[] = sprintf(
                        'Discount: %s%s',
                        wc_format_decimal( $discount, wc_get_price_decimals() ),
                        'percent' === $discount_type ? ' (' . wc_format_decimal( min( 100, $discount_value ), 2 ) . '%)' : ''
                    );
                }
                $order->add_order_note( 'Staff app pricing by ' . $user->display_name . ' — ' . implode( ' · ', $note ), false, true );
            }

            $target  = sanitize_key( $settings['default_order_status'] );
            $allowed = RAR_WSO_Data::order_statuses();
            if ( ! isset( $allowed[ $target ] ) || 'pending' === $target ) {
                $target = 'processing';
            }

            $order->update_status(
                $target,
                __( 'Staff order created from RAR Woo Stock & Order.', 'rar-woo-stock-order' ),
                true
            );

            wc_maybe_reduce_stock_levels( $order->get_id() );

            if ( '' !== $dedupe_key ) {
                set_transient( $dedupe_key, $order->get_id(), 15 * MINUTE_IN_SECONDS );
            }

            do_action( 'rar_wso_order_created', $order, $payload, get_current_user_id() );
            RAR_WSO_Plugin::bust_reports();

            $this->send_order_success(
                $order,
                __( 'Order created successfully.', 'rar-woo-stock-order' ),
                false
            );
        } catch ( Throwable $e ) {
            if ( isset( $order ) && $order instanceof WC_Order && $order->get_id() ) {
                $order->delete( true );
            }

            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    'Staff order creation failed: ' . $e->getMessage(),
                    array( 'source' => 'rar-wso' )
                );
            }

            wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
        }
    }

    private function send_order_success( $order, $message, $duplicate ) {
        $discount = abs( (float) $order->get_meta( '_rar_wso_discount' ) );

        wp_send_json_success(
            array(
                'message'         => $message,
                'order_id'        => $order->get_id(),
                'order_num'       => $order->get_order_number(),
                'status'          => wc_get_order_status_name( $order->get_status() ),
                'total'           => (float) $order->get_total(),
                'items_subtotal'  => (float) $order->get_subtotal(),
                'discount'        => $discount,
                'shipping'        => (float) $order->get_shipping_total(),
                'amount_words'    => RAR_WSO_Data::amount_in_words( (float) $order->get_total() ),
                'order_date'      => $order->get_date_created() ? wp_date( 'd M Y, h:i A', $order->get_date_created()->getTimestamp() ) : wp_date( 'd M Y, h:i A' ),
                'admin_url'       => current_user_can( 'manage_woocommerce' ) ? $order->get_edit_order_url() : '',
                'duplicate'       => (bool) $duplicate,
            )
        );
    }

    private function manager_order_payload( $order ) {
        $items = array();

        foreach ( $order->get_items() as $item ) {
            $items[] = array(
                'name' => RAR_WSO_Data::plain_text( $item->get_name() ),
                'qty'  => (float) $item->get_quantity(),
            );
        }

        $created = $order->get_date_created();

        return array(
            'id'          => $order->get_id(),
            'number'      => $order->get_order_number(),
            'date'        => $created ? wp_date( 'd M Y, h:i A', $created->getTimestamp() ) : '',
            'customer'    => trim( $order->get_formatted_billing_full_name() ) ?: __( 'Guest', 'rar-woo-stock-order' ),
            'phone'       => $order->get_billing_phone(),
            'address'     => implode( ', ', array_filter( array( $order->get_billing_address_1(), $order->get_billing_city() ) ) ),
            'total'       => (float) $order->get_total(),
            'status'      => $order->get_status(),
            'status_label'=> wc_get_order_status_name( $order->get_status() ),
            'items'       => $items,
            'item_count'  => count( $items ),
        );
    }

    public function manager_orders() {
        $this->manager_guard();

        $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'all';
        $page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
        $args = array(
            'type'     => 'shop_order',
            'limit'    => 30,
            'page'     => $page,
            'paginate' => true,
            'return'   => 'objects',
            'orderby'  => 'date',
            'order'    => 'DESC',
            'status'   => $this->registered_order_status_slugs(),
        );

        if ( 'live' === $mode ) {
            $args['status'] = RAR_WSO_Data::live_order_status_slugs();
        } elseif ( 'today' === $mode ) {
            $midnight             = new DateTimeImmutable( 'today', wp_timezone() );
            $args['date_created'] = $midnight->getTimestamp() . '...' . time();
        } elseif ( 'processing' === $mode ) {
            $args['status'] = array( 'processing' );
        } elseif ( 'completed' === $mode ) {
            $args['status'] = array( 'completed' );
        } elseif ( 'returns' === $mode ) {
            $registered     = $this->registered_order_status_slugs();
            $args['status'] = array_values( array_intersect( array( 'returned', 'cancelled', 'refunded' ), $registered ) );
        }

        $query = wc_get_orders( $args );
        $orders = is_object( $query ) && isset( $query->orders ) ? $query->orders : array();
        $items  = array();

        foreach ( $orders as $order ) {
            $items[] = $this->manager_order_payload( $order );
        }

        $max_pages = is_object( $query ) && isset( $query->max_num_pages ) ? (int) $query->max_num_pages : 1;

        wp_send_json_success(
            array(
                'orders'   => $items,
                'mode'     => in_array( $mode, array( 'live', 'today', 'processing', 'completed', 'returns' ), true ) ? $mode : 'all',
                'page'     => $page,
                'has_more' => $page < $max_pages,
                'total'    => is_object( $query ) && isset( $query->total ) ? (int) $query->total : count( $items ),
            )
        );
    }

    public function update_order_status() {
        $this->manager_guard();

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

        $statuses = RAR_WSO_Data::settable_order_statuses();
        if ( ! $order_id || ! isset( $statuses[ $status ] ) ) {
            wp_send_json_error(
                array( 'message' => 'refunded' === $status ? __( 'Refunds must be made from the WooCommerce order screen.', 'rar-woo-stock-order' ) : __( 'Invalid order or status.', 'rar-woo-stock-order' ) ),
                422
            );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'rar-woo-stock-order' ) ), 404 );
        }

        $previous_status = $order->get_status();
        $undo             = null;

        if ( $previous_status !== $status ) {
            $order->update_status(
                $status,
                sprintf(
                    'Status updated from RAR Woo Stock & Order by %s.',
                    wp_get_current_user()->display_name
                ),
                true
            );

            $token = sanitize_key( str_replace( '-', '', wp_generate_uuid4() ) );
            set_transient(
                'rar_wso_undo_' . $token,
                array(
                    'user_id'         => get_current_user_id(),
                    'order_id'        => $order_id,
                    'previous_status' => $previous_status,
                    'current_status'  => $status,
                ),
                5 * MINUTE_IN_SECONDS
            );

            $undo = array(
                'token'                 => $token,
                'previous_status'       => $previous_status,
                'previous_status_label' => wc_get_order_status_name( $previous_status ),
                'current_status'        => $status,
            );
            RAR_WSO_Plugin::bust_reports();
        }

        wp_send_json_success(
            array(
                'message' => __( 'Order status updated.', 'rar-woo-stock-order' ),
                'order'   => $this->manager_order_payload( $order ),
                'undo'    => $undo,
            )
        );
    }

    public function undo_order_status() {
        $this->manager_guard();

        $token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $data  = $token ? get_transient( 'rar_wso_undo_' . $token ) : false;

        if (
            ! is_array( $data ) ||
            absint( $data['user_id'] ?? 0 ) !== get_current_user_id()
        ) {
            wp_send_json_error(
                array( 'message' => __( 'Undo is no longer available for this status change.', 'rar-woo-stock-order' ) ),
                409
            );
        }

        $order = wc_get_order( absint( $data['order_id'] ?? 0 ) );
        if ( ! $order ) {
            delete_transient( 'rar_wso_undo_' . $token );
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'rar-woo-stock-order' ) ), 404 );
        }

        $current_status  = sanitize_key( $data['current_status'] ?? '' );
        $previous_status = sanitize_key( $data['previous_status'] ?? '' );
        $statuses        = RAR_WSO_Data::order_statuses();

        if (
            $order->get_status() !== $current_status ||
            ! isset( $statuses[ $previous_status ] )
        ) {
            delete_transient( 'rar_wso_undo_' . $token );
            wp_send_json_error(
                array( 'message' => __( 'Order changed again, so this undo can no longer be applied.', 'rar-woo-stock-order' ) ),
                409
            );
        }

        $order->update_status(
            $previous_status,
            sprintf(
                'Status change undone from RAR Woo Stock & Order by %s.',
                wp_get_current_user()->display_name
            ),
            true
        );

        delete_transient( 'rar_wso_undo_' . $token );
        RAR_WSO_Plugin::bust_reports();

        wp_send_json_success(
            array(
                'message' => __( 'Order status change undone.', 'rar-woo-stock-order' ),
                'order'   => $this->manager_order_payload( $order ),
            )
        );
    }
}
