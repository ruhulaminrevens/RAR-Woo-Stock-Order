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

        $states = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_states( 'BD' ) : array();
        foreach ( $states as $label ) {
            if ( 0 === strcasecmp( trim( wp_strip_all_tags( $label ) ), $district ) ) {
                foreach ( self::districts() as $candidate ) {
                    if ( 0 === strcasecmp( $candidate, trim( wp_strip_all_tags( $label ) ) ) ) {
                        return $candidate;
                    }
                }
                return trim( wp_strip_all_tags( $label ) );
            }
        }

        return '';
    }

    public static function district_to_state_code( $district ) {
        $district = self::canonical_district( $district );
        if ( '' === $district || ! function_exists( 'WC' ) || ! WC()->countries ) {
            return '';
        }

        $states = WC()->countries->get_states( 'BD' );
        foreach ( $states as $code => $label ) {
            if ( 0 === strcasecmp( trim( wp_strip_all_tags( $label ) ), $district ) ) {
                return $code;
            }
        }

        return '';
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

    public static function live_order_status_slugs() {
        $closed = array( 'completed', 'cancelled', 'refunded', 'failed', 'returned' );
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
