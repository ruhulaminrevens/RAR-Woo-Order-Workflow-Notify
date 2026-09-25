<?php
/**
 * Amount in words using the Bangladesh/South-Asian numbering system
 * (hundred / thousand / lakh / crore) in English and Bangla.
 *
 * Example: 1,25,450.50 → "Taka One Lakh Twenty Five Thousand Four Hundred Fifty and Fifty Paisa Only"
 *                      → "এক লক্ষ পঁচিশ হাজার চারশত পঞ্চাশ টাকা পঞ্চাশ পয়সা মাত্র"
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_WOW_Amount_Words {

    private static $en_ones = array(
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    );

    private static $en_tens = array( '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety' );

    /** Bangla words 0–99 are irregular, so they are listed explicitly. */
    private static $bn = array(
        'শূন্য', 'এক', 'দুই', 'তিন', 'চার', 'পাঁচ', 'ছয়', 'সাত', 'আট', 'নয়',
        'দশ', 'এগারো', 'বারো', 'তেরো', 'চৌদ্দ', 'পনেরো', 'ষোলো', 'সতেরো', 'আঠারো', 'উনিশ',
        'বিশ', 'একুশ', 'বাইশ', 'তেইশ', 'চব্বিশ', 'পঁচিশ', 'ছাব্বিশ', 'সাতাশ', 'আটাশ', 'ঊনত্রিশ',
        'ত্রিশ', 'একত্রিশ', 'বত্রিশ', 'তেত্রিশ', 'চৌত্রিশ', 'পঁয়ত্রিশ', 'ছত্রিশ', 'সাঁইত্রিশ', 'আটত্রিশ', 'ঊনচল্লিশ',
        'চল্লিশ', 'একচল্লিশ', 'বিয়াল্লিশ', 'তেতাল্লিশ', 'চুয়াল্লিশ', 'পঁয়তাল্লিশ', 'ছেচল্লিশ', 'সাতচল্লিশ', 'আটচল্লিশ', 'ঊনপঞ্চাশ',
        'পঞ্চাশ', 'একান্ন', 'বাহান্ন', 'তিপ্পান্ন', 'চুয়ান্ন', 'পঞ্চান্ন', 'ছাপ্পান্ন', 'সাতান্ন', 'আটান্ন', 'ঊনষাট',
        'ষাট', 'একষট্টি', 'বাষট্টি', 'তেষট্টি', 'চৌষট্টি', 'পঁয়ষট্টি', 'ছেষট্টি', 'সাতষট্টি', 'আটষট্টি', 'ঊনসত্তর',
        'সত্তর', 'একাত্তর', 'বাহাত্তর', 'তিয়াত্তর', 'চুয়াত্তর', 'পঁচাত্তর', 'ছিয়াত্তর', 'সাতাত্তর', 'আটাত্তর', 'ঊনআশি',
        'আশি', 'একাশি', 'বিরাশি', 'তিরাশি', 'চুরাশি', 'পঁচাশি', 'ছিয়াশি', 'সাতাশি', 'আটাশি', 'ঊননব্বই',
        'নব্বই', 'একানব্বই', 'বিরানব্বই', 'তিরানব্বই', 'চুরানব্বই', 'পঁচানব্বই', 'ছিয়ানব্বই', 'সাতানব্বই', 'আটানব্বই', 'নিরানব্বই',
    );

    /**
     * Split an integer into crore / lakh / thousand / hundred / rest.
     *
     * @param int $n Non-negative integer.
     * @return array<string,int>
     */
    private static function split( $n ) {
        $parts          = array();
        $parts['crore'] = intdiv( $n, 10000000 );
        $n             %= 10000000;
        $parts['lakh']  = intdiv( $n, 100000 );
        $n             %= 100000;
        $parts['thousand'] = intdiv( $n, 1000 );
        $n             %= 1000;
        $parts['hundred'] = intdiv( $n, 100 );
        $parts['rest']  = $n % 100;

        return $parts;
    }

    private static function en_below_100( $n ) {
        if ( $n < 20 ) {
            return self::$en_ones[ $n ];
        }

        return trim( self::$en_tens[ intdiv( $n, 10 ) ] . ' ' . self::$en_ones[ $n % 10 ] );
    }

    private static function en_int( $n ) {
        if ( 0 === $n ) {
            return 'Zero';
        }

        $words = array();

        if ( $n >= 10000000 ) {
            // Crore can itself exceed 99 (e.g. 150 crore).
            $words[] = self::en_int( intdiv( $n, 10000000 ) ) . ' Crore';
            $n      %= 10000000;
        }

        $p = self::split( $n );

        foreach ( array( 'lakh' => 'Lakh', 'thousand' => 'Thousand' ) as $key => $label ) {
            if ( $p[ $key ] ) {
                $words[] = self::en_below_100( $p[ $key ] ) . ' ' . $label;
            }
        }

        if ( $p['hundred'] ) {
            $words[] = self::$en_ones[ $p['hundred'] ] . ' Hundred';
        }

        if ( $p['rest'] ) {
            $words[] = self::en_below_100( $p['rest'] );
        }

        return trim( implode( ' ', $words ) );
    }

    private static function bn_int( $n ) {
        if ( 0 === $n ) {
            return self::$bn[0];
        }

        $words = array();

        if ( $n >= 10000000 ) {
            $words[] = self::bn_int( intdiv( $n, 10000000 ) ) . ' কোটি';
            $n      %= 10000000;
        }

        $p = self::split( $n );

        if ( $p['lakh'] ) {
            $words[] = self::$bn[ $p['lakh'] ] . ' লক্ষ';
        }

        if ( $p['thousand'] ) {
            $words[] = self::$bn[ $p['thousand'] ] . ' হাজার';
        }

        if ( $p['hundred'] ) {
            $words[] = self::$bn[ $p['hundred'] ] . 'শত';
        }

        if ( $p['rest'] ) {
            $words[] = self::$bn[ $p['rest'] ];
        }

        return trim( implode( ' ', $words ) );
    }

    /**
     * @param float  $amount   Amount.
     * @param string $currency ISO currency; BDT gets Taka/Paisa wording.
     */
    public static function english( $amount, $currency = 'BDT' ) {
        $amount = round( abs( (float) $amount ), 2 );
        $int    = (int) floor( $amount );
        $fract  = (int) round( ( $amount - $int ) * 100 );

        if ( 'BDT' === $currency ) {
            $text = 'Taka ' . self::en_int( $int );

            if ( $fract > 0 ) {
                $text .= ' and ' . self::en_below_100( $fract ) . ' Paisa';
            }

            return $text . ' Only';
        }

        $text = self::en_int( $int ) . ' ' . $currency;

        if ( $fract > 0 ) {
            $text .= ' and ' . $fract . '/100';
        }

        return $text . ' Only';
    }

    public static function bangla( $amount ) {
        $amount = round( abs( (float) $amount ), 2 );
        $int    = (int) floor( $amount );
        $fract  = (int) round( ( $amount - $int ) * 100 );

        $text = self::bn_int( $int ) . ' টাকা';

        if ( $fract > 0 ) {
            $text .= ' ' . self::$bn[ $fract ] . ' পয়সা';
        }

        return $text . ' মাত্র';
    }
}
