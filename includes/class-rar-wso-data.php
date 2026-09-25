<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_WSO_Data {
    public static function city_map() {
        static $map = null;

        if ( null !== $map ) {
            return $map;
        }

        $file = RAR_WSO_PATH . 'assets/data/bd-cities.json';
        if ( ! is_readable( $file ) ) {
            $map = array();
            return $map;
        }

        $decoded = json_decode( (string) file_get_contents( $file ), true );
        $map     = is_array( $decoded ) ? $decoded : array();

        return $map;
    }

    public static function districts() {
        return array_keys( self::city_map() );
    }

    /**
     * WooCommerce Bangladesh state codes for the English district names used in
     * assets/data/bd-cities.json. Kept static so validation does not depend on
     * translated state labels (bn_BD sites translate them).
     */
    private static function district_codes() {
        return array( 'Bandarban' => 'BD-01', 'Brahmanbaria' => 'BD-04', 'Chandpur' => 'BD-09', 'Chattogram' => 'BD-10', 'Cox\'s Bazar' => 'BD-11', 'Cumilla' => 'BD-08', 'Feni' => 'BD-16', 'Khagrachhari' => 'BD-29', 'Lakshmipur' => 'BD-31', 'Noakhali' => 'BD-47', 'Rangamati' => 'BD-56', 'Dhaka' => 'BD-13', 'Faridpur' => 'BD-15', 'Gazipur' => 'BD-18', 'Gopalganj' => 'BD-17', 'Kishoreganj' => 'BD-26', 'Madaripur' => 'BD-36', 'Manikganj' => 'BD-33', 'Munshiganj' => 'BD-35', 'Narayanganj' => 'BD-40', 'Narsingdi' => 'BD-42', 'Rajbari' => 'BD-53', 'Shariatpur' => 'BD-62', 'Tangail' => 'BD-63', 'Bagerhat' => 'BD-05', 'Chuadanga' => 'BD-12', 'Jashore' => 'BD-22', 'Jhenaidah' => 'BD-23', 'Khulna' => 'BD-27', 'Kushtia' => 'BD-30', 'Magura' => 'BD-37', 'Meherpur' => 'BD-39', 'Narail' => 'BD-43', 'Satkhira' => 'BD-58', 'Bogura' => 'BD-03', 'Nawabganj' => 'BD-45', 'Joypurhat' => 'BD-24', 'Naogaon' => 'BD-48', 'Natore' => 'BD-44', 'Pabna' => 'BD-49', 'Rajshahi' => 'BD-54', 'Sirajganj' => 'BD-59', 'Barguna' => 'BD-02', 'Barishal' => 'BD-06', 'Bhola' => 'BD-07', 'Jhalokati' => 'BD-25', 'Patuakhali' => 'BD-51', 'Pirojpur' => 'BD-50', 'Jamalpur' => 'BD-21', 'Mymensingh' => 'BD-34', 'Netrakona' => 'BD-41', 'Sherpur' => 'BD-57', 'Dinajpur' => 'BD-14', 'Gaibandha' => 'BD-19', 'Kurigram' => 'BD-28', 'Lalmonirhat' => 'BD-32', 'Nilphamari' => 'BD-46', 'Panchagarh' => 'BD-52', 'Rangpur' => 'BD-55', 'Thakurgaon' => 'BD-64', 'Habiganj' => 'BD-20', 'Moulvibazar' => 'BD-38', 'Sunamganj' => 'BD-61', 'Sylhet' => 'BD-60' );
    }

    public static function canonical_district( $district ) {
        $district = trim( wp_strip_all_tags( (string) $district ) );
        if ( '' === $district ) {
            return '';
        }

        foreach ( self::districts() as $candidate ) {
            if ( 0 === strcasecmp( $candidate, $district ) ) {
                return $candidate;
            }
        }

        // A WooCommerce state code (BD-13) or a (possibly translated) WooCommerce state label.
        $codes = array_flip( self::district_codes() );
        $upper = strtoupper( $district );
        if ( isset( $codes[ $upper ] ) ) {
            return $codes[ $upper ];
        }

        $states = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_states( 'BD' ) : array();
        foreach ( (array) $states as $code => $label ) {
            $label = trim( html_entity_decode( wp_strip_all_tags( (string) $label ), ENT_QUOTES, 'UTF-8' ) );
            if ( 0 === strcasecmp( $label, $district ) && isset( $codes[ $code ] ) ) {
                return $codes[ $code ];
            }
        }

        return '';
    }

    public static function district_to_state_code( $district ) {
        $district = self::canonical_district( $district );
        $codes    = self::district_codes();

        return ( '' !== $district && isset( $codes[ $district ] ) ) ? $codes[ $district ] : '';
    }

    public static function canonical_city( $district, $city ) {
        $district = self::canonical_district( $district );
        $city     = trim( wp_strip_all_tags( (string) $city ) );

        if ( '' === $district || '' === $city ) {
            return '';
        }

        $map = self::city_map();
        if ( empty( $map[ $district ] ) || ! is_array( $map[ $district ] ) ) {
            return '';
        }

        foreach ( $map[ $district ] as $candidate ) {
            if ( 0 === strcasecmp( $candidate, $city ) ) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Plain text for names shown in the app (decodes stored entities such as "&amp;" once).
     */
    public static function plain_text( $text ) {
        return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }

    public static function clean_currency_symbol() {
        $symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '৳';
        $symbol = html_entity_decode( wp_strip_all_tags( (string) $symbol ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $symbol = str_replace( array( "Â ", '&nbsp;' ), ' ', $symbol );
        $symbol = preg_replace( '/\s+/u', ' ', $symbol );
        return trim( (string) $symbol );
    }

    public static function normalize_bd_phone( $phone ) {
        $phone = preg_replace( '/[^0-9+]/', '', (string) $phone );

        if ( preg_match( '/^01[3-9][0-9]{8}$/', $phone ) ) {
            return '+88' . $phone;
        }

        if ( preg_match( '/^8801[3-9][0-9]{8}$/', $phone ) ) {
            return '+' . $phone;
        }

        if ( preg_match( '/^\+8801[3-9][0-9]{8}$/', $phone ) ) {
            return $phone;
        }

        return '';
    }

    public static function stock_band( $product ) {
        if ( ! $product ) {
            return 'out';
        }

        if ( ! $product->get_manage_stock() ) {
            return 'outofstock' === $product->get_stock_status() ? 'out' : 'unmanaged';
        }

        $qty = (float) $product->get_stock_quantity();
        if ( $qty <= 0 || 'outofstock' === $product->get_stock_status() ) {
            return 'out';
        }

        return $qty <= 10 ? 'low' : 'high';
    }

    /**
     * Statuses that are not real orders (Checkout block drafts are abandoned carts
     * and WooCommerce deletes them automatically).
     */
    public static function non_order_statuses() {
        return array( 'checkout-draft', 'trash', 'auto-draft' );
    }

    /**
     * WooCommerce order statuses usable in the staff app (slug => label), without drafts.
     */
    public static function order_statuses() {
        $out = array();
        if ( function_exists( 'wc_get_order_statuses' ) ) {
            foreach ( wc_get_order_statuses() as $key => $label ) {
                $slug = str_replace( 'wc-', '', (string) $key );
                if ( ! in_array( $slug, self::non_order_statuses(), true ) ) {
                    $out[ $slug ] = wp_strip_all_tags( (string) $label );
                }
            }
        }
        return $out;
    }

    /**
     * Statuses a manager may set from the quick status control. Refunds stay in
     * WooCommerce admin (a refund creates records and emails that Undo cannot reverse).
     */
    public static function settable_order_statuses() {
        $out = self::order_statuses();
        unset( $out['refunded'] );
        return $out;
    }

    public static function live_order_status_slugs() {
        $closed = array( 'completed', 'cancelled', 'refunded', 'failed', 'returned', 'checkout-draft', 'trash' );
        $all    = array();

        if ( function_exists( 'wc_get_order_statuses' ) ) {
            foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
                $slug = str_replace( 'wc-', '', $key );
                if ( ! in_array( $slug, $closed, true ) ) {
                    $all[] = $slug;
                }
            }
        }

        return $all;
    }

    public static function amount_in_words( $amount ) {
        $amount = max( 0, (float) $amount );
        $taka   = (int) floor( $amount + 0.00001 );
        $paisa  = (int) round( ( $amount - $taka ) * 100 );

        $words = self::integer_to_words( $taka ) . ' Taka';
        if ( $paisa > 0 ) {
            $words .= ' and ' . self::integer_to_words( $paisa ) . ' Paisa';
        }

        return trim( $words ) . ' Only';
    }

    private static function integer_to_words( $number ) {
        $number = (int) $number;

        if ( 0 === $number ) {
            return 'Zero';
        }

        $parts = array();

        $crore = intdiv( $number, 10000000 );
        if ( $crore > 0 ) {
            $parts[] = self::integer_to_words( $crore ) . ' Crore';
            $number %= 10000000;
        }

        $lakh = intdiv( $number, 100000 );
        if ( $lakh > 0 ) {
            $parts[] = self::integer_to_words( $lakh ) . ' Lakh';
            $number %= 100000;
        }

        $thousand = intdiv( $number, 1000 );
        if ( $thousand > 0 ) {
            $parts[] = self::integer_to_words( $thousand ) . ' Thousand';
            $number %= 1000;
        }

        $hundred = intdiv( $number, 100 );
        if ( $hundred > 0 ) {
            $parts[] = self::integer_to_words( $hundred ) . ' Hundred';
            $number %= 100;
        }

        if ( $number > 0 ) {
            $small = array(
                0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
                6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
                11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
                15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen',
                19 => 'Nineteen',
            );
            $tens = array(
                20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
                60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety',
            );

            if ( $number < 20 ) {
                $parts[] = $small[ $number ];
            } else {
                $ten   = intdiv( $number, 10 ) * 10;
                $unit  = $number % 10;
                $parts[] = $tens[ $ten ] . ( $unit ? ' ' . $small[ $unit ] : '' );
            }
        }

        return implode( ' ', $parts );
    }
}
