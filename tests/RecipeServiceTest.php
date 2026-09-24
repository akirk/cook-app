<?php

use CookApp\RecipeService;
use PHPUnit\Framework\TestCase;

class RecipeServiceTest extends TestCase {

    private RecipeService $recipes;

    protected function setUp(): void {
        $this->recipes = ( new ReflectionClass( RecipeService::class ) )->newInstanceWithoutConstructor();
    }

    public function test_submitted_ingredient_parts_ignore_empty_sections_with_empty_ingredients(): void {
        $parts = $this->invoke( 'sanitize_submitted_ingredient_parts', [
            [
                'title'       => 'Sauce',
                'ingredients' => [
                    [ 'amount' => '', 'unit' => '', 'name' => '', 'notes' => '' ],
                ],
            ],
            [
                'title'       => 'Dough',
                'ingredients' => [
                    [ 'amount' => '200', 'unit' => 'g', 'name' => 'flour', 'notes' => '' ],
                    [ 'amount' => '', 'unit' => '', 'name' => '', 'notes' => '' ],
                ],
            ],
        ] );

        $this->assertCount( 1, $parts );
        $this->assertSame( 'Dough', $parts[0]['title'] );
        $this->assertSame( 'flour', $parts[0]['ingredients'][0]['name'] );
        $this->assertCount( 1, $parts[0]['ingredients'] );
    }

    public function test_normalized_recipe_parts_ignore_title_only_sections(): void {
        $parts = $this->invoke( 'normalize_recipe_parts_array', [
            [
                'title'       => 'Empty',
                'ingredients' => [
                    [ 'amount' => '', 'unit' => '', 'name' => '', 'notes' => '' ],
                ],
                'instructions' => [ '' ],
            ],
            [
                'title'        => 'Steps',
                'ingredients'  => [],
                'instructions' => [ '1. Mix everything' ],
            ],
        ], false );

        $this->assertCount( 1, $parts );
        $this->assertSame( 'Steps', $parts[0]['title'] );
        $this->assertSame( [ 'Mix everything' ], $parts[0]['instructions'] );
    }

    public function test_apply_recipe_part_template_preserves_sections_for_flat_translated_ingredients(): void {
        $parts = $this->invoke( 'apply_recipe_part_template', [
            [
                'title'       => 'Sauce',
                'ingredients' => [
                    [ 'amount' => '1', 'unit' => 'tbsp', 'name' => 'mustard', 'notes' => '' ],
                ],
            ],
            [
                'title'       => 'Fish',
                'ingredients' => [
                    [ 'amount' => '2', 'unit' => '', 'name' => 'salmon', 'notes' => '' ],
                    [ 'amount' => '1', 'unit' => '', 'name' => 'lemon', 'notes' => '' ],
                ],
            ],
        ], [
            [ 'amount' => '1', 'unit' => 'EL', 'name' => 'Senf', 'notes' => '' ],
            [ 'amount' => '2', 'unit' => '', 'name' => 'Lachs', 'notes' => '' ],
            [ 'amount' => '1', 'unit' => '', 'name' => 'Zitrone', 'notes' => '' ],
        ], [] );

        $this->assertCount( 2, $parts );
        $this->assertSame( 'Sauce', $parts[0]['title'] );
        $this->assertSame( 'Senf', $parts[0]['ingredients'][0]['name'] );
        $this->assertSame( 'Fish', $parts[1]['title'] );
        $this->assertSame( [ 'Lachs', 'Zitrone' ], array_column( $parts[1]['ingredients'], 'name' ) );
    }

    public function test_apply_recipe_part_template_returns_empty_when_counts_do_not_match(): void {
        $parts = $this->invoke( 'apply_recipe_part_template', [
            [
                'title'       => 'Sauce',
                'ingredients' => [
                    [ 'amount' => '1', 'unit' => 'tbsp', 'name' => 'mustard', 'notes' => '' ],
                ],
            ],
            [
                'title'       => 'Fish',
                'ingredients' => [
                    [ 'amount' => '2', 'unit' => '', 'name' => 'salmon', 'notes' => '' ],
                ],
            ],
        ], [
            [ 'amount' => '1', 'unit' => 'EL', 'name' => 'Senf', 'notes' => '' ],
        ], [] );

        $this->assertSame( [], $parts );
    }

    private function invoke( string $method, ...$args ) {
        $reflection = new ReflectionMethod( RecipeService::class, $method );
        $reflection->setAccessible( true );
        return $reflection->invokeArgs( $this->recipes, $args );
    }
}
