<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only dashboard/reporting service.
 *
 * WooCommerce remains the source of truth. This class deliberately avoids
 * direct writes so dashboard failures can never block stock/order mutations.
 */
final class RAR_WSO_Reports {
    private static function registered_statuses() {
        $statuses = array();

        if ( function_exists( 'wc_get_order_statuses' ) ) {
            foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
                $statuses[] = str_replace( 'wc-', '', (string) $key );
            }
        }

        return array_values( array_unique( array_filter( $statuses ) ) );
    }

    private static function sales_excluded_statuses() {
        return array( 'cancelled', 'failed', 'refunded', 'returned' );
    }

    private static function normalize_period( $period ) {
        $period = sanitize_key( (string) $period );
        return in_array( $period, array( 'today', '7days', 'month', '30days', '90days' ), true ) ? $period : 'today';
    }

    private static function period_range( $period ) {
        $period = self::normalize_period( $period );
        $tz     = wp_timezone();
        $now    = new DateTimeImmutable( 'now', $tz );
        $today  = new DateTimeImmutable( 'today', $tz );

        switch ( $period ) {
            case '7days':
                $start = $today->modify( '-6 days' );
                $label = __( 'Last 7 days', 'rar-woo-stock-order' );
                break;
            case 'month':
                $start = $today->modify( 'first day of this month' );
                $label = __( 'This month', 'rar-woo-stock-order' );
                break;
            case '30days':
                $start = $today->modify( '-29 days' );
                $label = __( 'Last 30 days', 'rar-woo-stock-order' );
                break;
            case '90days':
                $start = $today->modify( '-89 days' );
                $label = __( 'Last 90 days', 'rar-woo-stock-order' );
                break;
            case 'today':
            default:
                $start = $today;
                $label = __( 'Today', 'rar-woo-stock-order' );
                break;
        }

        $duration       = max( 1, $now->getTimestamp() - $start->getTimestamp() + 1 );
        $previous_end   = $start->modify( '-1 second' );
        $previous_start = $previous_end->modify( '-' . ( $duration - 1 ) . ' seconds' );

        return array(
            'key'            => $period,
            'label'          => $label,
            'start'          => $start,
            'end'            => $now,
            'previous_start' => $previous_start,
            'previous_end'   => $previous_end,
        );
    }

    private static function order_date_query( DateTimeImmutable $start, DateTimeImmutable $end ) {
        return $start->getTimestamp() . '...' . $end->getTimestamp();
    }

    private static function orders_between( DateTimeImmutable $start, DateTimeImmutable $end, $statuses = null ) {
        if ( null === $statuses ) {
            $statuses = self::registered_statuses();
        }

        if ( empty( $statuses ) ) {
            return array();
        }

        return wc_get_orders(
            array(
                'limit'        => -1,
                'return'       => 'objects',
                'orderby'      => 'date',
                'order'        => 'DESC',
                'status'       => array_values( $statuses ),
                'date_created' => self::order_date_query( $start, $end ),
            )
        );
    }

    private static function is_sales_order( $order ) {
        return $order && ! $order->has_status( self::sales_excluded_statuses() );
    }

    private static function summarize_orders( $orders ) {
        $summary = array(
            'orders'               => 0,
            'sales'                => 0.0,
            'completed'            => 0,
            'returned_cancelled'   => 0,
            'processing'           => 0,
            'live'                 => 0,
            'sales_orders'         => 0,
            'items_sold'           => 0,
            'average_order'        => 0.0,
        );

        $live_statuses = RAR_WSO_Data::live_order_status_slugs();

        foreach ( (array) $orders as $order ) {
            if ( ! $order instanceof WC_Order ) {
                continue;
            }

            $summary['orders']++;

            if ( $order->has_status( 'completed' ) ) {
                $summary['completed']++;
            }
            if ( $order->has_status( array( 'returned', 'cancelled', 'refunded' ) ) ) {
                $summary['returned_cancelled']++;
            }
            if ( $order->has_status( 'processing' ) ) {
                $summary['processing']++;
            }
            if ( $order->has_status( $live_statuses ) ) {
                $summary['live']++;
            }

            if ( ! self::is_sales_order( $order ) ) {
                continue;
            }

            $summary['sales_orders']++;
            $summary['sales'] += (float) $order->get_total();

            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $summary['items_sold'] += max( 0, (int) $item->get_quantity() );
            }
        }

        if ( $summary['sales_orders'] > 0 ) {
            $summary['average_order'] = $summary['sales'] / $summary['sales_orders'];
        }

        $summary['sales']         = round( (float) $summary['sales'], wc_get_price_decimals() );
        $summary['average_order'] = round( (float) $summary['average_order'], wc_get_price_decimals() );

        return $summary;
    }

    private static function percentage_change( $current, $previous ) {
        $current  = (float) $current;
        $previous = (float) $previous;

        if ( abs( $previous ) < 0.00001 ) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round( ( ( $current - $previous ) / abs( $previous ) ) * 100, 1 );
    }

    private static function period_metrics( $period ) {
        $range    = self::period_range( $period );
        $current  = self::summarize_orders( self::orders_between( $range['start'], $range['end'] ) );
        $previous = self::summarize_orders( self::orders_between( $range['previous_start'], $range['previous_end'] ) );

        $current['comparison'] = array(
            'orders_pct' => self::percentage_change( $current['orders'], $previous['orders'] ),
            'sales_pct'  => self::percentage_change( $current['sales'], $previous['sales'] ),
        );

        return array(
            'key'      => $range['key'],
            'label'    => $range['label'],
            'current'  => $current,
            'previous' => $previous,
        );
    }

    public static function inventory_summary() {
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
                SUM(CASE WHEN l.stock_status='instock' AND l.stock_quantity IS NULL THEN 1 ELSE 0 END) AS unmanaged_stock,
                SUM(CASE WHEN l.stock_status='instock' AND l.stock_quantity > 0 THEN l.stock_quantity ELSE 0 END) AS units_in_hand,
                SUM(CASE WHEN l.stock_status='instock' AND l.stock_quantity > 0 THEN l.stock_quantity * COALESCE(l.min_price,0) ELSE 0 END) AS stock_value
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
        $row = is_array( $row ) ? $row : array();

        $available = (int) ( $row['available_stock'] ?? 0 );
        $unmanaged = (int) ( $row['unmanaged_stock'] ?? 0 );

        return array(
            'all_stock'       => (int) ( $row['all_stock'] ?? 0 ),
            'available_stock' => $available,
            'sellable_stock'  => $available + $unmanaged,
            'out_stock'       => (int) ( $row['out_stock'] ?? 0 ),
            'high_stock'      => (int) ( $row['high_stock'] ?? 0 ),
            'low_stock'       => (int) ( $row['low_stock'] ?? 0 ),
            'unmanaged_stock' => $unmanaged,
            'units_in_hand'   => (float) ( $row['units_in_hand'] ?? 0 ),
            'stock_value'     => round( (float) ( $row['stock_value'] ?? 0 ), wc_get_price_decimals() ),
        );
    }

    private static function count_orders( $statuses = null, $extra = array() ) {
        if ( null === $statuses ) {
            $statuses = self::registered_statuses();
        }

        $statuses = array_values( array_filter( (array) $statuses ) );
        if ( empty( $statuses ) ) {
            return 0;
        }

        $query = wc_get_orders(
            array_merge(
                array(
                    'limit'    => 1,
                    'page'     => 1,
                    'paginate' => true,
                    'return'   => 'ids',
                    'status'   => $statuses,
                ),
                $extra
            )
        );

        return is_object( $query ) && isset( $query->total ) ? (int) $query->total : 0;
    }

    private static function order_control() {
        $registered = self::registered_statuses();
        $live       = RAR_WSO_Data::live_order_status_slugs();

        return array(
            'all'        => self::count_orders( $registered ),
            'live'       => self::count_orders( $live ),
            'processing' => in_array( 'processing', $registered, true ) ? self::count_orders( array( 'processing' ) ) : 0,
        );
    }

    private static function trend( $days = 7 ) {
        $days  = max( 2, min( 90, absint( $days ) ) );
        $tz    = wp_timezone();
        $today = new DateTimeImmutable( 'today', $tz );
        $now   = new DateTimeImmutable( 'now', $tz );
        $start = $today->modify( '-' . ( $days - 1 ) . ' days' );
        $daily = array();

        for ( $i = 0; $i < $days; $i++ ) {
            $date = $start->modify( '+' . $i . ' days' );
            $key  = $date->format( 'Y-m-d' );
            $daily[ $key ] = array(
                'label'  => wp_date( 'D', $date->getTimestamp() ),
                'sales'  => 0.0,
                'orders' => 0,
            );
        }

        foreach ( self::orders_between( $start, $now ) as $order ) {
            if ( ! self::is_sales_order( $order ) ) {
                continue;
            }

            $created = $order->get_date_created();
            if ( ! $created ) {
                continue;
            }

            $key = wp_date( 'Y-m-d', $created->getTimestamp() );
            if ( isset( $daily[ $key ] ) ) {
                $daily[ $key ]['sales'] += (float) $order->get_total();
                $daily[ $key ]['orders']++;
            }
        }

        return array(
            'labels' => array_values( wp_list_pluck( $daily, 'label' ) ),
            'sales'  => array_map( 'floatval', array_values( wp_list_pluck( $daily, 'sales' ) ) ),
            'orders' => array_map( 'intval', array_values( wp_list_pluck( $daily, 'orders' ) ) ),
        );
    }

    private static function recent_orders( $is_manager ) {
        $args = array(
            'limit'   => 6,
            'return'  => 'objects',
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => self::registered_statuses(),
        );

        if ( ! $is_manager ) {
            $args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'     => '_rar_wso_created_by',
                    'value'   => (string) get_current_user_id(),
                    'compare' => '=',
                ),
            );
        }

        $orders = wc_get_orders( $args );
        $items  = array();

        foreach ( $orders as $order ) {
            $created = $order->get_date_created();
            $items[] = array(
                'id'           => $order->get_id(),
                'number'       => $order->get_order_number(),
                'customer'     => trim( $order->get_formatted_billing_full_name() ) ?: __( 'Guest', 'rar-woo-stock-order' ),
                'total'        => (float) $order->get_total(),
                'status'       => $order->get_status(),
                'status_label' => wc_get_order_status_name( $order->get_status() ),
                'payment'      => wp_strip_all_tags( $order->get_payment_method_title() ),
                'city'         => wp_strip_all_tags( $order->get_shipping_city() ?: $order->get_billing_city() ),
                'items'        => (int) $order->get_item_count(),
                'created'      => $created ? wp_date( 'M j, h:i a', $created->getTimestamp() ) : '',
                'channel'      => sanitize_key( (string) $order->get_meta( '_rar_wso_channel' ) ),
            );
        }

        return $items;
    }

    private static function needs_attention( $inventory, $is_manager ) {
        $items = array();

        if ( $inventory['low_stock'] > 0 ) {
            $items[] = array(
                'type'   => 'low',
                'count'  => $inventory['low_stock'],
                'title'  => sprintf( _n( '%d product running low', '%d products running low', $inventory['low_stock'], 'rar-woo-stock-order' ), $inventory['low_stock'] ),
                'detail' => __( 'Managed stock between 1 and 10 units', 'rar-woo-stock-order' ),
                'filter' => 'low',
            );
        }

        if ( $inventory['out_stock'] > 0 ) {
            $items[] = array(
                'type'   => 'out',
                'count'  => $inventory['out_stock'],
                'title'  => sprintf( _n( '%d product out of stock', '%d products out of stock', $inventory['out_stock'], 'rar-woo-stock-order' ), $inventory['out_stock'] ),
                'detail' => __( 'Stock quantity is zero or status is out of stock', 'rar-woo-stock-order' ),
                'filter' => 'out',
            );
        }

        if ( $is_manager ) {
            $registered = self::registered_statuses();

            if ( in_array( 'on-hold', $registered, true ) ) {
                $on_hold = self::count_orders( array( 'on-hold' ) );
                if ( $on_hold > 0 ) {
                    $items[] = array(
                        'type'  => 'hold',
                        'count' => $on_hold,
                        'title' => sprintf( _n( '%d order on hold', '%d orders on hold', $on_hold, 'rar-woo-stock-order' ), $on_hold ),
                        'detail'=> __( 'Manager review may be required', 'rar-woo-stock-order' ),
                        'mode'  => 'live',
                    );
                }
            }

            $cutoff = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-24 hours' );
            $stale  = self::count_orders(
                RAR_WSO_Data::live_order_status_slugs(),
                array( 'date_created' => '<' . $cutoff->getTimestamp() )
            );

            if ( $stale > 0 ) {
                $items[] = array(
                    'type'   => 'stale',
                    'count'  => $stale,
                    'title'  => sprintf( _n( '%d live order waiting 24h+', '%d live orders waiting 24h+', $stale, 'rar-woo-stock-order' ), $stale ),
                    'detail' => __( 'Old running orders need attention', 'rar-woo-stock-order' ),
                    'mode'   => 'live',
                );
            }
        }

        return array_slice( $items, 0, 4 );
    }

    public static function dashboard( $period = 'today', $manager_period = '30days', $is_manager = false ) {
        $period         = self::normalize_period( $period );
        $manager_period = self::normalize_period( $manager_period );
        if ( ! in_array( $manager_period, array( '7days', '30days', '90days' ), true ) ) {
            $manager_period = '30days';
        }

        $inventory      = self::inventory_summary();
        $period_metrics = self::period_metrics( $period );
        $today_metrics  = 'today' === $period ? $period_metrics : self::period_metrics( 'today' );
        $trend7         = self::trend( 7 );

        $payload = array_merge(
            $inventory,
            array(
                'role'                 => $is_manager ? 'manager' : 'staff',
                'period'               => $period_metrics,
                'today_orders'         => (int) $today_metrics['current']['orders'],
                'today_sales'          => (float) $today_metrics['current']['sales'],
                'completed_orders'     => self::count_orders( array( 'completed' ) ),
                'returned_cancelled'   => self::count_orders( array_values( array_intersect( array( 'returned', 'cancelled', 'refunded' ), self::registered_statuses() ) ) ),
                'trend'                => $trend7,
                'recent_orders'        => self::recent_orders( $is_manager ),
                'needs_attention'      => self::needs_attention( $inventory, $is_manager ),
            )
        );

        if ( $is_manager ) {
            $manager_metrics = self::period_metrics( $manager_period );
            $previous_week   = self::period_metrics( '7days' );

            $payload['order_control']   = self::order_control();
            $payload['manager_period']  = $manager_metrics;
            $payload['analytics']       = array(
                'labels'     => $trend7['labels'],
                'sales'      => $trend7['sales'],
                'orders'     => $trend7['orders'],
                'week_total' => (float) array_sum( $trend7['sales'] ),
                'growth_pct' => (float) $previous_week['current']['comparison']['sales_pct'],
            );
        }

        return $payload;
    }
}
