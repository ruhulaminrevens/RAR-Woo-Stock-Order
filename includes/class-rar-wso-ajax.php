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
            'create_order'        => 'create_order',
            'manager_orders'      => 'manager_orders',
            'update_order_status' => 'update_order_status',
        ) as $action => $method ) {
            add_action( 'wp_ajax_rar_wso_' . $action, array( $this, $method ) );
        }
    }

    private function guard( $cap = 'rar_wso_access' ) {
        check_ajax_referer( 'rar_wso_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! RAR_WSO_Plugin::can( $cap ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to perform this action.', 'rar-woo-stock-order' ) ),
                403
            );
        }
    }

    private function manager_guard() {
        check_ajax_referer( 'rar_wso_nonce', 'nonce' );

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

    private function inventory_stats() {
        global $wpdb;

        $lookup = $wpdb->wc_product_meta_lookup;
        $posts  = $wpdb->posts;

        $sql = "
            SELECT
                COUNT(l.product_id) AS all_stock,
                SUM(CASE WHEN l.stock_status='instock' AND l.stock_quantity > 0 THEN 1 ELSE 0 END) AS available_stock,
                SUM(CASE WHEN l.stock_status='outofstock' OR (l.stock_quantity IS NOT NULL AND l.stock_quantity <= 0) THEN 1 ELSE 0 END) AS out_stock,
                SUM(CASE WHEN l.stock_status='instock' AND l.stock_quantity >= 11 THEN 1 ELSE 0 END) AS high_stock,
                SUM(CASE WHEN l.stock_status='instock' AND l.stock_quantity BETWEEN 1 AND 10 THEN 1 ELSE 0 END) AS low_stock,
                SUM(CASE WHEN l.stock_status='instock' AND l.stock_quantity IS NULL THEN 1 ELSE 0 END) AS unmanaged_stock
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
        ";

        $row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! is_array( $row ) ) {
            $row = array();
        }

        return array(
            'all_stock'       => (int) ( $row['all_stock'] ?? 0 ),
            'available_stock' => (int) ( $row['available_stock'] ?? 0 ),
            'out_stock'       => (int) ( $row['out_stock'] ?? 0 ),
            'high_stock'      => (int) ( $row['high_stock'] ?? 0 ),
            'low_stock'       => (int) ( $row['low_stock'] ?? 0 ),
            'unmanaged_stock' => (int) ( $row['unmanaged_stock'] ?? 0 ),
        );
    }

    private function count_orders_by_status( $statuses ) {
        $registered = array_map(
            static function ( $key ) {
                return str_replace( 'wc-', '', $key );
            },
            array_keys( wc_get_order_statuses() )
        );

        $statuses = array_values( array_intersect( (array) $statuses, $registered ) );
        if ( empty( $statuses ) ) {
            return 0;
        }

        $result = wc_get_orders(
            array(
                'limit'    => 1,
                'page'     => 1,
                'paginate' => true,
                'return'   => 'ids',
                'status'   => $statuses,
            )
        );

        return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
    }

    private function order_metrics_today() {
        $today  = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
        $orders = wc_get_orders(
            array(
                'limit'        => -1,
                'return'       => 'objects',
                'date_created' => '>=' . $today . ' 00:00:00',
                'status'       => array_keys( wc_get_order_statuses() ),
            )
        );

        $metrics = array(
            'today_orders'       => count( $orders ),
            'today_sales'        => 0.0,
            'completed_orders'   => $this->count_orders_by_status( array( 'completed' ) ),
            'returned_cancelled' => $this->count_orders_by_status( array( 'returned', 'cancelled', 'refunded' ) ),
        );

        foreach ( $orders as $order ) {
            if ( ! $order->has_status( array( 'cancelled', 'failed', 'refunded', 'returned' ) ) ) {
                $metrics['today_sales'] += (float) $order->get_total();
            }
        }

        return $metrics;
    }

    private function manager_analytics() {
        $tz        = wp_timezone();
        $today     = new DateTimeImmutable( 'today', $tz );
        $start     = $today->modify( '-13 days' );
        $date_from = $start->format( 'Y-m-d' ) . ' 00:00:00';

        $orders = wc_get_orders(
            array(
                'limit'        => -1,
                'return'       => 'objects',
                'date_created' => '>=' . $date_from,
                'status'       => array_keys( wc_get_order_statuses() ),
            )
        );

        $daily = array();
        for ( $i = 0; $i < 14; $i++ ) {
            $key           = $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
            $daily[ $key ] = 0.0;
        }

        foreach ( $orders as $order ) {
            if ( $order->has_status( array( 'cancelled', 'failed', 'refunded', 'returned' ) ) ) {
                continue;
            }

            $created = $order->get_date_created();
            if ( ! $created ) {
                continue;
            }

            $key = wp_date( 'Y-m-d', $created->getTimestamp() );
            if ( isset( $daily[ $key ] ) ) {
                $daily[ $key ] += (float) $order->get_total();
            }
        }

        $values        = array_values( $daily );
        $previous      = array_sum( array_slice( $values, 0, 7 ) );
        $current       = array_sum( array_slice( $values, 7, 7 ) );
        $growth        = $previous > 0 ? ( ( $current - $previous ) / $previous ) * 100 : ( $current > 0 ? 100 : 0 );
        $recent_values = array_slice( $values, 7, 7 );
        $recent_keys   = array_slice( array_keys( $daily ), 7, 7 );
        $labels        = array();

        foreach ( $recent_keys as $key ) {
            $labels[] = wp_date( 'D', strtotime( $key . ' 12:00:00' ) );
        }

        return array(
            'labels'      => $labels,
            'sales'       => array_map( 'floatval', $recent_values ),
            'week_total'  => (float) $current,
            'growth_pct'  => round( (float) $growth, 1 ),
        );
    }

    public function stats() {
        $this->guard();

        $data = array_merge( $this->order_metrics_today(), $this->inventory_stats() );

        if ( current_user_can( 'manage_woocommerce' ) ) {
            $data['analytics'] = $this->manager_analytics();
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

        $qty = max( 0, (float) $raw );
        $old = $product->get_stock_quantity();

        $product->set_manage_stock( true );
        $product->set_stock_quantity( $qty );
        $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
        $product->save();

        update_post_meta(
            $id,
            '_rar_wso_last_stock_update',
            wp_json_encode(
                array(
                    'user_id' => get_current_user_id(),
                    'time'    => current_time( 'mysql' ),
                    'from'    => is_null( $old ) ? null : (float) $old,
                    'to'      => $qty,
                )
            )
        );

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
                'message' => __( 'Stock updated.', 'rar-woo-stock-order' ),
                'product' => $this->product_payload( $product ),
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

            foreach ( $items as $raw ) {
                $product_id = absint( $raw['id'] ?? 0 );
                $qty        = max( 1, absint( $raw['qty'] ?? 1 ) );
                $product    = wc_get_product( $product_id );

                if ( ! $product || ! $product->is_purchasable() || 'out' === RAR_WSO_Data::stock_band( $product ) ) {
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

            $target = sanitize_key( $settings['default_order_status'] );
            if ( ! isset( wc_get_order_statuses()[ 'wc-' . $target ] ) ) {
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
                'name' => wp_strip_all_tags( $item->get_name() ),
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
        $args = array(
            'limit'   => 60,
            'return'  => 'objects',
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => array_keys( wc_get_order_statuses() ),
        );

        if ( 'live' === $mode ) {
            $args['status'] = RAR_WSO_Data::live_order_status_slugs();
        } elseif ( 'today' === $mode ) {
            $today                = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
            $args['date_created'] = '>=' . $today . ' 00:00:00';
        } elseif ( 'completed' === $mode ) {
            $args['status'] = array( 'completed' );
        } elseif ( 'returns' === $mode ) {
            $registered     = array_map(
                static function ( $key ) {
                    return str_replace( 'wc-', '', $key );
                },
                array_keys( wc_get_order_statuses() )
            );
            $args['status'] = array_values( array_intersect( array( 'returned', 'cancelled', 'refunded' ), $registered ) );
        }

        $orders = wc_get_orders( $args );
        $items  = array();

        foreach ( $orders as $order ) {
            $items[] = $this->manager_order_payload( $order );
        }

        wp_send_json_success(
            array(
                'orders' => $items,
                'mode'   => in_array( $mode, array( 'live', 'today', 'completed', 'returns' ), true ) ? $mode : 'all',
            )
        );
    }

    public function update_order_status() {
        $this->manager_guard();

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

        $statuses = wc_get_order_statuses();
        if ( ! $order_id || ! isset( $statuses[ 'wc-' . $status ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order or status.', 'rar-woo-stock-order' ) ), 422 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'rar-woo-stock-order' ) ), 404 );
        }

        if ( $order->get_status() !== $status ) {
            $order->update_status(
                $status,
                sprintf(
                    'Status updated from RAR Woo Stock & Order by %s.',
                    wp_get_current_user()->display_name
                ),
                true
            );
        }

        wp_send_json_success(
            array(
                'message' => __( 'Order status updated.', 'rar-woo-stock-order' ),
                'order'   => $this->manager_order_payload( $order ),
            )
        );
    }
}
