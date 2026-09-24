<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Unit conversion + display formatting.
 *
 * Mass and volume units are convertible across the metric/imperial divide.
 * Anything else (piece, slice, clove, pinch, can, …) is left as-is.
 */
class Units {

    // Canonical mass in grams.
    private const MASS = [
        'g'  => 1.0,
        'kg' => 1000.0,
        'oz' => 28.3495,
        'lb' => 453.592,
    ];

    // Canonical volume in millilitres.
    private const VOLUME = [
        'ml'   => 1.0,
        'l'    => 1000.0,
        'tsp'  => 4.92892,
        'tbsp' => 14.7868,
        'floz' => 29.5735,
        'cup'  => 236.588,
        'pt'   => 473.176,
        'qt'   => 946.353,
        'gal'  => 3785.41,
    ];

    private const UNIT_ALIASES = [
        'gram'        => 'g',
        'grams'       => 'g',
        'kilogram'    => 'kg',
        'kilograms'   => 'kg',
        'ounce'       => 'oz',
        'ounces'      => 'oz',
        'pound'       => 'lb',
        'pounds'      => 'lb',
        'lbs'         => 'lb',
        'millilitre'  => 'ml',
        'millilitres' => 'ml',
        'milliliter'  => 'ml',
        'milliliters' => 'ml',
        'litre'       => 'l',
        'litres'      => 'l',
        'liter'       => 'l',
        'liters'      => 'l',
        'teaspoon'    => 'tsp',
        'teaspoons'   => 'tsp',
        'tablespoon'  => 'tbsp',
        'tablespoons' => 'tbsp',
        'tbs'         => 'tbsp',
        'fluid ounce' => 'floz',
        'fl oz'       => 'floz',
        'cups'        => 'cup',
        'pint'        => 'pt',
        'pints'       => 'pt',
        'quart'       => 'qt',
        'quarts'      => 'qt',
        'gallon'      => 'gal',
        'gallons'     => 'gal',

        // German.
        'el'          => 'tbsp',
        'tl'          => 'tsp',
        'esslöffel'   => 'tbsp',
        'teelöffel'   => 'tsp',
        'stk'         => 'piece',
        'stk.'        => 'piece',
        'stück'       => 'piece',
        'msp'         => 'pinch',
        'msp.'        => 'pinch',
        'messerspitze' => 'pinch',
        'prise'       => 'pinch',
        'bund'        => 'bunch',
        'pk'          => 'packet',
        'pkg'         => 'packet',
        'pck'         => 'packet',
        'pkt'         => 'packet',
        'packung'     => 'packet',

        // Meal-kit sites write countable items as "1 unit(s) Courgette" and
        // "1 sachet(s) Vegetable Stock". Treat them as the units they mean.
        'unit'        => 'piece',
        'units'       => 'piece',
        'sachet'      => 'packet',
        'sachets'     => 'packet',
        'pack'        => 'packet',
        'packs'       => 'packet',
    ];

    public const COMMON_UNITS = [
        'metric'   => [ 'g', 'kg', 'ml', 'l', 'piece', 'pinch', 'clove', 'slice' ],
        'imperial' => [ 'oz', 'lb', 'tsp', 'tbsp', 'cup', 'floz', 'pt', 'qt', 'piece', 'pinch', 'clove', 'slice' ],
    ];

    public static function normalize_unit( string $unit ): string {
        $unit = strtolower( trim( $unit ) );
        return self::UNIT_ALIASES[ $unit ] ?? $unit;
    }

    public static function unit_kind( string $unit ): ?string {
        $unit = self::normalize_unit( $unit );
        if ( isset( self::MASS[ $unit ] ) )   return 'mass';
        if ( isset( self::VOLUME[ $unit ] ) ) return 'volume';
        return null;
    }

    /**
     * Parse an amount that may include a unicode fraction or "1 1/2" mixed form.
     * Returns float, or null if blank/non-numeric.
     */
    public static function parse_amount( $value ): ?float {
        if ( is_numeric( $value ) ) return (float) $value;
        if ( ! is_string( $value ) ) return null;
        $v = trim( $value );
        if ( $v === '' ) return null;

        if ( self::parse_amount_range( $v ) !== null ) {
            return null;
        }

        return self::parse_single_amount( $v, true );
    }

    private static function parse_single_amount( string $v, bool $allow_leading_number ): ?float {
        $fractions = [
            '½' => 0.5,    '⅓' => 1.0 / 3.0, '⅔' => 2.0 / 3.0, '¼' => 0.25, '¾' => 0.75,
            '⅕' => 0.2,    '⅖' => 0.4,       '⅗' => 0.6,       '⅘' => 0.8,
            '⅙' => 1.0 / 6.0, '⅚' => 5.0 / 6.0,
            '⅛' => 0.125,  '⅜' => 0.375,    '⅝' => 0.625,     '⅞' => 0.875,
        ];

        // Bare unicode fraction: "½".
        if ( isset( $fractions[ $v ] ) ) return $fractions[ $v ];

        // Mixed unicode fraction: "1½", "1 ½".
        if ( preg_match( '/^(\d+)\s*(' . implode( '|', array_map( 'preg_quote', array_keys( $fractions ) ) ) . ')$/u', $v, $m ) ) {
            return (float) $m[1] + $fractions[ $m[2] ];
        }

        // ASCII mixed: "1 1/2".
        if ( preg_match( '#^(\d+)\s+(\d+)/(\d+)$#', $v, $m ) ) {
            return (float) $m[1] + ( (float) $m[2] / (float) $m[3] );
        }
        // ASCII fraction: "1/2".
        if ( preg_match( '#^(\d+)/(\d+)$#', $v, $m ) ) {
            return (float) $m[1] / (float) $m[2];
        }
        // Decimal — accept "1,5" too.
        $decimal = str_replace( ',', '.', $v );
        if ( is_numeric( $decimal ) ) return (float) $decimal;
        // Leading number, e.g. "1.5 something".
        if ( $allow_leading_number && preg_match( '/^\d+(?:[.,]\d+)?/', $v, $m ) ) {
            return (float) str_replace( ',', '.', $m[0] );
        }
        return null;
    }

    private static function amount_pattern(): string {
        return '(?:\d+(?:[.,]\d+)?\s+\d+/\d+|\d+/\d+|\d+(?:[.,]\d+)?|[½⅓⅔¼¾⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]|\d+\s*[½⅓⅔¼¾⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞])';
    }

    private static function parse_amount_range( $value ): ?array {
        if ( ! is_string( $value ) ) {
            return null;
        }

        $v = trim( $value );
        if ( $v === '' ) {
            return null;
        }

        if ( ! preg_match( '#^(' . self::amount_pattern() . ')\s*(?:-|–|—|to)\s*(' . self::amount_pattern() . ')$#iu', $v, $m ) ) {
            return null;
        }

        $low = self::parse_single_amount( trim( $m[1] ), false );
        $high = self::parse_single_amount( trim( $m[2] ), false );
        if ( $low === null || $high === null ) {
            return null;
        }

        if ( $low > $high ) {
            return [ $high, $low ];
        }

        return [ $low, $high ];
    }

    /**
     * Convert (amount, unit) to the user's preferred system, picking a sensible
     * sub-unit (e.g. >=1000 g becomes kg). Non-convertible units pass through.
     */
    public static function to_preference( $amount, string $unit, string $preference ): array {
        $original = [ 'amount' => $amount, 'unit' => $unit ];
        $kind = self::unit_kind( $unit );
        if ( $kind === null ) return $original;

        $range = self::parse_amount_range( $amount );
        if ( $range !== null ) {
            $unit = self::normalize_unit( $unit );
            $current_system = self::system_of( $unit );
            if ( $current_system === $preference ) {
                return self::pretty_range_in_system( $range[0], $range[1], $unit, $kind, $preference );
            }

            $canonical_low = ( $kind === 'mass' )
                ? $range[0] * self::MASS[ $unit ]
                : $range[0] * self::VOLUME[ $unit ];
            $canonical_high = ( $kind === 'mass' )
                ? $range[1] * self::MASS[ $unit ]
                : $range[1] * self::VOLUME[ $unit ];

            return self::from_canonical_range( $canonical_low, $canonical_high, $kind, $preference );
        }

        $value = self::parse_amount( $amount );
        if ( $value === null ) return $original;

        $unit = self::normalize_unit( $unit );
        $current_system = self::system_of( $unit );
        if ( $current_system === $preference ) {
            return self::pretty_in_system( $value, $unit, $kind, $preference );
        }

        // Convert to canonical, then to preferred.
        $canonical = ( $kind === 'mass' )
            ? $value * self::MASS[ $unit ]
            : $value * self::VOLUME[ $unit ];

        return self::from_canonical( $canonical, $kind, $preference );
    }

    private static function system_of( string $unit ): string {
        // tsp/tbsp are everyday kitchen measures used in both metric and imperial
        // contexts (TL/EL in German, etc.) — leave them untouched in either mode
        // rather than rounding "1 tsp salt" to "5 ml salt".
        $imperial = [ 'oz', 'lb', 'cup', 'floz', 'pt', 'qt', 'gal' ];
        return in_array( $unit, $imperial, true ) ? 'imperial' : 'metric';
    }

    private static function from_canonical( float $canonical, string $kind, string $preference ): array {
        if ( $kind === 'mass' ) {
            if ( $preference === 'metric' ) {
                return $canonical >= 1000
                    ? [ 'amount' => self::format_number( $canonical / 1000, 2 ), 'unit' => 'kg' ]
                    : [ 'amount' => self::format_number( $canonical, 0 ), 'unit' => 'g' ];
            }
            $oz = $canonical / self::MASS['oz'];
            return $oz >= 16
                ? [ 'amount' => self::format_number( $canonical / self::MASS['lb'], 2 ), 'unit' => 'lb' ]
                : [ 'amount' => self::format_number( $oz, 1 ), 'unit' => 'oz' ];
        }
        // volume
        if ( $preference === 'metric' ) {
            return $canonical >= 1000
                ? [ 'amount' => self::format_number( $canonical / 1000, 2 ), 'unit' => 'l' ]
                : [ 'amount' => self::format_number( $canonical, 0 ), 'unit' => 'ml' ];
        }
        $cups = $canonical / self::VOLUME['cup'];
        if ( $cups >= 0.25 ) {
            return [ 'amount' => self::format_number( $cups, 2 ), 'unit' => 'cup' ];
        }
        $tbsp = $canonical / self::VOLUME['tbsp'];
        if ( $tbsp >= 1 ) {
            return [ 'amount' => self::format_number( $tbsp, 1 ), 'unit' => 'tbsp' ];
        }
        return [ 'amount' => self::format_number( $canonical / self::VOLUME['tsp'], 1 ), 'unit' => 'tsp' ];
    }

    private static function from_canonical_range( float $low, float $high, string $kind, string $preference ): array {
        if ( $kind === 'mass' ) {
            if ( $preference === 'metric' ) {
                return $high >= 1000
                    ? [ 'amount' => self::format_range( $low / 1000, $high / 1000, 2 ), 'unit' => 'kg' ]
                    : [ 'amount' => self::format_range( $low, $high, 0 ), 'unit' => 'g' ];
            }
            $low_oz = $low / self::MASS['oz'];
            $high_oz = $high / self::MASS['oz'];
            return $high_oz >= 16
                ? [ 'amount' => self::format_range( $low / self::MASS['lb'], $high / self::MASS['lb'], 2 ), 'unit' => 'lb' ]
                : [ 'amount' => self::format_range( $low_oz, $high_oz, 1 ), 'unit' => 'oz' ];
        }

        if ( $preference === 'metric' ) {
            return $high >= 1000
                ? [ 'amount' => self::format_range( $low / 1000, $high / 1000, 2 ), 'unit' => 'l' ]
                : [ 'amount' => self::format_range( $low, $high, 0 ), 'unit' => 'ml' ];
        }

        $low_cups = $low / self::VOLUME['cup'];
        $high_cups = $high / self::VOLUME['cup'];
        if ( $high_cups >= 0.25 ) {
            return [ 'amount' => self::format_range( $low_cups, $high_cups, 2 ), 'unit' => 'cup' ];
        }

        $low_tbsp = $low / self::VOLUME['tbsp'];
        $high_tbsp = $high / self::VOLUME['tbsp'];
        if ( $high_tbsp >= 1 ) {
            return [ 'amount' => self::format_range( $low_tbsp, $high_tbsp, 1 ), 'unit' => 'tbsp' ];
        }

        return [
            'amount' => self::format_range( $low / self::VOLUME['tsp'], $high / self::VOLUME['tsp'], 1 ),
            'unit'   => 'tsp',
        ];
    }

    private static function pretty_in_system( float $value, string $unit, string $kind, string $system ): array {
        // Promote g->kg or ml->l when large; otherwise leave as-is.
        if ( $system === 'metric' ) {
            if ( $unit === 'g' && $value >= 1000 ) {
                return [ 'amount' => self::format_number( $value / 1000, 2 ), 'unit' => 'kg' ];
            }
            if ( $unit === 'ml' && $value >= 1000 ) {
                return [ 'amount' => self::format_number( $value / 1000, 2 ), 'unit' => 'l' ];
            }
        }
        return [ 'amount' => self::format_number( $value, $value < 10 ? 2 : 0 ), 'unit' => $unit ];
    }

    private static function pretty_range_in_system( float $low, float $high, string $unit, string $kind, string $system ): array {
        if ( $system === 'metric' ) {
            if ( $unit === 'g' && $high >= 1000 ) {
                return [ 'amount' => self::format_range( $low / 1000, $high / 1000, 2 ), 'unit' => 'kg' ];
            }
            if ( $unit === 'ml' && $high >= 1000 ) {
                return [ 'amount' => self::format_range( $low / 1000, $high / 1000, 2 ), 'unit' => 'l' ];
            }
        }
        return [ 'amount' => self::format_range( $low, $high, $high < 10 ? 2 : 0 ), 'unit' => $unit ];
    }

    public static function format_number( float $n, int $max_decimals = 2 ): string {
        $rounded = round( $n );
        if ( abs( $n - $rounded ) < 0.05 && ( (int) $rounded !== 0 || abs( $n ) < 0.00001 ) ) {
            return (string) (int) $rounded;
        }

        if ( $max_decimals > 0 ) {
            $fraction = self::format_fraction( $n );
            if ( $fraction !== null ) {
                return $fraction;
            }
        }

        $s = number_format( $n, $max_decimals, '.', '' );
        if ( $max_decimals <= 0 ) {
            return $s;
        }
        return rtrim( rtrim( $s, '0' ), '.' );
    }

    private static function format_range( float $low, float $high, int $max_decimals ): string {
        if ( abs( $low - $high ) < 0.00001 ) {
            return self::format_number( $low, $max_decimals );
        }
        return self::format_number( $low, $max_decimals ) . '–' . self::format_number( $high, $max_decimals );
    }

    private static function format_fraction( float $n ): ?string {
        $sign = $n < 0 ? '-' : '';
        $absolute = abs( $n );
        $whole = (int) floor( $absolute );
        $remainder = $absolute - $whole;
        $fractions = [
            '⅛' => 1.0 / 8.0,
            '⅙' => 1.0 / 6.0,
            '⅕' => 1.0 / 5.0,
            '¼' => 1.0 / 4.0,
            '⅓' => 1.0 / 3.0,
            '⅖' => 2.0 / 5.0,
            '½' => 1.0 / 2.0,
            '⅗' => 3.0 / 5.0,
            '⅔' => 2.0 / 3.0,
            '¾' => 3.0 / 4.0,
            '⅘' => 4.0 / 5.0,
            '⅚' => 5.0 / 6.0,
            '⅞' => 7.0 / 8.0,
        ];

        foreach ( $fractions as $glyph => $value ) {
            if ( abs( $remainder - $value ) < 0.015 ) {
                return $sign . ( $whole > 0 ? (string) $whole : '' ) . $glyph;
            }
        }

        return null;
    }

    public static function display_unit( string $unit ): string {
        $unit = self::normalize_unit( $unit );
        $labels = [
            'g'    => 'g',
            'kg'   => 'kg',
            'ml'   => 'ml',
            'l'    => 'l',
            'oz'   => 'oz',
            'lb'   => 'lb',
            'tsp'  => 'tsp',
            'tbsp' => 'tbsp',
            'floz' => 'fl oz',
            'cup'  => 'cup',
            'pt'   => 'pt',
            'qt'   => 'qt',
            'gal'  => 'gal',
        ];
        return $labels[ $unit ] ?? $unit;
    }

    /**
     * Scale + convert an ingredient row for display.
     */
    public static function render_ingredient( array $ingredient, float $scale, string $preference ): array {
        $amount_raw = $ingredient['amount'] ?? '';
        $unit       = $ingredient['unit']   ?? '';
        $name       = $ingredient['name']   ?? '';
        $notes      = $ingredient['notes']  ?? '';

        $value = self::parse_amount( $amount_raw );
        $range = self::parse_amount_range( $amount_raw );
        if ( $value === null && $range === null ) {
            return [
                'amount' => $amount_raw,
                'unit'   => self::display_unit( $unit ),
                'name'   => $name,
                'notes'  => $notes,
            ];
        }

        $scaled_amount = $range !== null
            ? self::format_range( $range[0] * $scale, $range[1] * $scale, $range[1] * $scale < 10 ? 2 : 0 )
            : $value * $scale;
        $converted = self::to_preference( $scaled_amount, $unit, $preference );
        return [
            'amount' => $converted['amount'],
            'unit'   => self::display_unit( $converted['unit'] ),
            'name'   => $name,
            'notes'  => $notes,
        ];
    }
}
