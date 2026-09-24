<?php

use Cookbook\TermAbilitiesService;
use PHPUnit\Framework\TestCase;

class TermAbilitiesServiceTest extends TestCase {
    public function test_merge_replaces_nested_ingredient_ids_without_changing_recipe_words(): void {
        $service = new TermAbilitiesService();
        $rows = [
            [ 'name' => 'lemon, juiced', 'notes' => 'fresh', 'term_id' => 12 ],
            [ 'title' => 'Sauce', 'ingredients' => [ [ 'name' => 'lemon zest', 'term_id' => 12 ] ] ],
            [ 'name' => 'other', 'term_id' => 50, 'term_ids' => [ 12, 50, 12 ] ],
        ];
        $method = new ReflectionMethod( TermAbilitiesService::class, 'replace_nested_ids' );
        $args = [ &$rows, 12, 20 ];

        $this->assertTrue( $method->invokeArgs( $service, $args ) );
        $this->assertSame( 'lemon, juiced', $rows[0]['name'] );
        $this->assertSame( 'fresh', $rows[0]['notes'] );
        $this->assertSame( 20, $rows[0]['term_id'] );
        $this->assertSame( 20, $rows[1]['ingredients'][0]['term_id'] );
        $this->assertSame( [ 20, 50 ], $rows[2]['term_ids'] );
    }
}
