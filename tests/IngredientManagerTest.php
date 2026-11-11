<?php

use CookApp\App;
use CookApp\ServiceContainer;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IngredientManagerTest extends TestCase {
    public function test_unused_cleanup_preserves_live_references_but_ignores_revision_snapshots(): void {
        require __DIR__ . '/fixtures/ingredient-manager-wordpress.php';
        $service = new \CookApp\TermAbilitiesService();
        $GLOBALS['backup_terms'] = [ 10 => [ 'name' => 'ml stock', 'taxonomy' => App::TAX_INGREDIENT ] ];
        $GLOBALS['backup_assignments'][55][App::TAX_INGREDIENT] = [ 10 ];
        $service->delete_unused_ingredient_term( 10 );
        $this->assertArrayHasKey( 10, $GLOBALS['backup_terms'] );
        $GLOBALS['backup_assignments'] = [];
        $GLOBALS['manager_children'][10] = [ 11 ];
        $service->delete_unused_ingredient_term( 10 );
        $this->assertArrayHasKey( 10, $GLOBALS['backup_terms'] );
        $GLOBALS['manager_children'] = [];
        $GLOBALS['backup_meta'][55][App::META_SHOPPING_ITEMS] = [ [ 'term_id' => 10 ] ];
        $service->delete_unused_ingredient_term( 10 );
        $this->assertArrayHasKey( 10, $GLOBALS['backup_terms'] );
        $GLOBALS['backup_meta'] = [];
        $GLOBALS['backup_users'][7][App::USER_HOUSEHOLD_INGREDIENTS] = [ 10 ];
        $service->delete_unused_ingredient_term( 10 );
        $this->assertArrayHasKey( 10, $GLOBALS['backup_terms'] );
        $GLOBALS['backup_users'] = [];
        $GLOBALS['backup_meta'][56][App::META_INGREDIENTS] = [ [ 'term_id' => 10 ] ];
        $GLOBALS['manager_revisions'] = [ 56 ];
        $service->delete_unused_ingredient_term( 10 );
        $this->assertArrayNotHasKey( 10, $GLOBALS['backup_terms'] );
    }

    public function test_amount_only_correction_keeps_the_previously_merged_ingredient_link(): void {
        require __DIR__ . '/fixtures/ingredient-manager-wordpress.php';
        $GLOBALS['backup_next_term'] = 200;
        $GLOBALS['backup_terms'] = [ 11 => [ 'name' => 'sugar', 'taxonomy' => App::TAX_INGREDIENT ] ];
        $row = [ 'name' => 'caster sugar', 'amount' => '50', 'unit' => 'g', 'notes' => '', 'term_id' => 11 ];
        $GLOBALS['backup_meta'][99] = [ App::META_INGREDIENTS => [ $row ] ];

        ( new ServiceContainer() )->recipes()->replace_recipe_ingredient_row( 99, 0, array_merge( $row, [ 'amount' => '100' ] ) );

        $this->assertSame( '100', $GLOBALS['backup_meta'][99][App::META_INGREDIENTS][0]['amount'] );
        $this->assertSame( 11, $GLOBALS['backup_meta'][99][App::META_INGREDIENTS][0]['term_id'] );
        $this->assertSame( [ 11 ], $GLOBALS['backup_assignments'][99][App::TAX_INGREDIENT] );
        $this->assertCount( 1, $GLOBALS['backup_terms'] );
    }

    public function test_correcting_an_embedded_amount_updates_sections_and_taxonomy_without_changing_other_rows(): void {
        require __DIR__ . '/fixtures/ingredient-manager-wordpress.php';
        $GLOBALS['backup_next_term'] = 200;
        $GLOBALS['backup_terms'] = [
            10 => [ 'name' => '200 g flour', 'taxonomy' => App::TAX_INGREDIENT ],
            11 => [ 'name' => 'sugar', 'taxonomy' => App::TAX_INGREDIENT ],
            12 => [ 'name' => 'flour', 'taxonomy' => App::TAX_INGREDIENT ],
        ];
        $wrong = [ 'name' => '200 g flour', 'amount' => '', 'unit' => '', 'notes' => 'sifted', 'term_id' => 10, 'optional' => true ];
        $other = [ 'name' => 'caster sugar', 'amount' => '50', 'unit' => 'g', 'notes' => '', 'term_id' => 11 ];
        $GLOBALS['backup_meta'][99] = [
            App::META_INGREDIENTS => [ $other, $wrong ],
            App::META_PARTS => [
                [ 'title' => 'Topping', 'ingredients' => [ $other ], 'instructions' => [ 'Mix topping.' ] ],
                [ 'title' => 'Dough', 'ingredients' => [ $wrong ], 'instructions' => [ 'Knead dough.' ] ],
            ],
        ];
        $fixed = array_merge( $wrong, [ 'name' => 'flour', 'amount' => '200', 'unit' => 'g' ] );
        ( new ServiceContainer() )->recipes()->replace_recipe_ingredient_row( 99, 1, $fixed );

        $rows = $GLOBALS['backup_meta'][99][App::META_INGREDIENTS];
        $parts = $GLOBALS['backup_meta'][99][App::META_PARTS];
        $this->assertEquals( $other, $rows[0] );
        $this->assertSame( 'flour', $rows[1]['name'] );
        $this->assertSame( '200', $rows[1]['amount'] );
        $this->assertSame( 'g', $rows[1]['unit'] );
        $this->assertSame( 'sifted', $rows[1]['notes'] );
        $this->assertTrue( $rows[1]['optional'] );
        $this->assertTrue( $parts[1]['ingredients'][0]['optional'] );
        $this->assertSame( 12, $rows[1]['term_id'] );
        $this->assertSame( $rows[1], $parts[1]['ingredients'][0] );
        $this->assertEquals( $other, $parts[0]['ingredients'][0] );
        $this->assertSame( [ 'Knead dough.' ], $parts[1]['instructions'] );
        $this->assertSame( [ 11, 12 ], $GLOBALS['backup_assignments'][99][App::TAX_INGREDIENT] );
        $this->assertCount( 2, $GLOBALS['backup_terms'] );
        $this->assertArrayNotHasKey( 10, $GLOBALS['backup_terms'] );
    }
}
