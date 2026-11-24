<?php

use PHPUnit\Framework\TestCase;
use CookApp\Units;

class UnitsTest extends TestCase {

    /** @dataProvider amounts */
    public function test_parse_amount( $input, $expected ): void {
        $this->assertSame( $expected, Units::parse_amount( $input ) );
    }

    public static function amounts(): array {
        return [
            'plain int'     => [ '200',     200.0 ],
            'decimal'       => [ '1.5',     1.5 ],
            'comma decimal' => [ '1,5',     1.5 ],
            'fraction'      => [ '1/2',     0.5 ],
            'mixed'         => [ '1 1/2',   1.5 ],
            'unicode half'  => [ '½',       0.5 ],
            'unicode third' => [ '⅓',       1.0 / 3.0 ],
            'range'         => [ '2–3',      null ],
            'blank'         => [ '',        null ],
            'non-numeric'   => [ 'a pinch', null ],
        ];
    }

    public function test_normalize_unit_aliases(): void {
        $this->assertSame( 'tbsp',  Units::normalize_unit( 'EL' ) );
        $this->assertSame( 'tbsp',  Units::normalize_unit( 'tablespoons' ) );
        $this->assertSame( 'tsp',   Units::normalize_unit( 'TL' ) );
        $this->assertSame( 'piece', Units::normalize_unit( 'Stk' ) );
        $this->assertSame( 'pinch', Units::normalize_unit( 'Prise' ) );
        $this->assertSame( 'kg',    Units::normalize_unit( 'kilograms' ) );
    }

    public function test_metric_to_imperial_mass(): void {
        $out = Units::to_preference( 1000, 'g', 'imperial' );
        $amount = Units::parse_amount( $out['amount'] );
        $this->assertSame( 'lb', $out['unit'] );
        $this->assertNotNull( $amount );
        $this->assertGreaterThan( 2.0, $amount );
        $this->assertLessThan( 2.5, $amount );
    }

    public function test_imperial_to_metric_volume(): void {
        $out = Units::to_preference( 2, 'cup', 'metric' );
        // 2 cups ≈ 473 ml.
        $this->assertSame( 'ml', $out['unit'] );
        $this->assertGreaterThan( 460, (float) $out['amount'] );
        $this->assertLessThan( 490, (float) $out['amount'] );
    }

    public function test_metric_promotion_g_to_kg(): void {
        $out = Units::to_preference( 1500, 'g', 'metric' );
        $this->assertSame( 'kg',  $out['unit'] );
        $this->assertSame( '1½', $out['amount'] );
    }

    public function test_non_convertible_unit_passes_through(): void {
        $out = Units::to_preference( 1, 'piece', 'imperial' );
        $this->assertSame( 'piece', $out['unit'] );
        $this->assertSame( 1, $out['amount'] );
    }

    public function test_render_ingredient_scales_and_converts(): void {
        $row = [ 'amount' => '200', 'unit' => 'g', 'name' => 'flour', 'notes' => '' ];
        $rendered = Units::render_ingredient( $row, 2.0, 'imperial' );
        $amount = Units::parse_amount( $rendered['amount'] );

        // 400 g ≈ 14.1 oz.
        $this->assertSame( 'oz', $rendered['unit'] );
        $this->assertNotNull( $amount );
        $this->assertGreaterThan( 13.5, $amount );
        $this->assertLessThan(  14.5, $amount );
    }

    /** @dataProvider kitchen_measure_preferences */
    public function test_kitchen_measures_keep_their_unit( int $amount, string $unit, string $preference, array $expected ): void {
        $this->assertSame( $expected, Units::to_preference( $amount, $unit, $preference ) );
    }

    public static function kitchen_measure_preferences(): array {
        return [
            // "1 TL Salz" must stay 1 tsp instead of becoming 5 ml.
            'teaspoon in metric' => [ 1, 'tsp', 'metric', [ 'amount' => '1', 'unit' => 'tsp' ] ],
            'tablespoon in metric' => [ 2, 'tbsp', 'metric', [ 'amount' => '2', 'unit' => 'tbsp' ] ],
            'teaspoon in imperial' => [ 1, 'tsp', 'imperial', [ 'amount' => '1', 'unit' => 'tsp' ] ],
        ];
    }

    public function test_ml_to_imperial_still_chooses_tsp_for_small_amounts(): void {
        // Conversion in the other direction (ml → imperial) still works.
        $out = Units::to_preference( 5, 'ml', 'imperial' );
        $amount = Units::parse_amount( $out['amount'] );
        $this->assertSame( 'tsp', $out['unit'] );
        $this->assertNotNull( $amount );
        $this->assertGreaterThan( 0.9, $amount );
        $this->assertLessThan( 1.1, $amount );
    }

    public function test_render_ingredient_unparseable_amount_kept_verbatim(): void {
        $row = [ 'amount' => 'a pinch', 'unit' => '', 'name' => 'salt', 'notes' => '' ];
        $rendered = Units::render_ingredient( $row, 2.0, 'imperial' );

        $this->assertSame( 'a pinch', $rendered['amount'] );
        $this->assertSame( 'salt',    $rendered['name'] );
    }

    public function test_format_number_keeps_tiny_amounts_nonzero(): void {
        $this->assertSame( '0.05', Units::format_number( 0.05, 2 ) );
    }

    public function test_format_number_uses_common_fractions(): void {
        $this->assertSame( '1½', Units::format_number( 1.5, 2 ) );
        $this->assertSame( '⅓', Units::format_number( 1.0 / 3.0, 2 ) );
    }

    public function test_render_ingredient_scales_amount_ranges(): void {
        $row = [ 'amount' => '2–3', 'unit' => 'cup', 'name' => 'flour', 'notes' => '' ];
        $rendered = Units::render_ingredient( $row, 2.0, 'imperial' );

        $this->assertSame( 'cup', $rendered['unit'] );
        $this->assertSame( '4–6', $rendered['amount'] );
    }

    public function test_range_converts_units_without_collapsing_to_first_value(): void {
        $out = Units::to_preference( '2–3', 'cup', 'metric' );

        $this->assertSame( 'ml', $out['unit'] );
        $this->assertSame( '473–710', $out['amount'] );
    }
}
