<?php

use CookApp\App;
use CookApp\ServiceContainer;
use PHPUnit\Framework\TestCase;

/** @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BackupServiceRestoreTest extends TestCase {
    protected function setUp(): void {
        require __DIR__ . '/fixtures/backup-wordpress.php';
        $GLOBALS['backup_next_post'] = 100;
        $GLOBALS['backup_next_term'] = 200;
        $GLOBALS['backup_posts'] = [ 99 => [ 'post_title' => 'Existing recipe' ] ];
        $GLOBALS['backup_meta'] = [];
        $GLOBALS['backup_terms'] = [];
        $GLOBALS['backup_users'] = [];
    }

    private function data(): array {
        $post = [ 'type' => App::POST_TYPE, 'title' => 'Pancakes', 'content' => '', 'parent' => 0,
            'meta' => [ App::META_INGREDIENTS => [ [ 'name' => 'flour', 'amount' => '200', 'unit' => 'g', 'term_id' => 8 ] ] ],
            'terms' => [ App::TAX_INGREDIENT => [ 8 ] ], 'image_url' => '' ];
        $post['id'] = 1;
        $variation = $post;
        $variation['id'] = 2;
        $variation['parent'] = 1;
        return [ 'format' => 'cook-app-backup', 'version' => 1,
            'posts' => [ $post, $variation,
                [ 'id' => 3, 'type' => App::WEEK_PLAN_POST_TYPE, 'title' => 'Week', 'meta' => [ App::META_WEEK_MEALS => [ '2026-10-01' => [ 'lunch' => 1 ] ] ], 'terms' => [] ],
                [ 'id' => 4, 'type' => App::COOKED_ENTRY_POST_TYPE, 'title' => 'Cooked', 'meta' => [ App::META_COOKED_RECIPE_ID => 2 ], 'terms' => [] ],
                [ 'id' => 5, 'type' => App::SHOPPING_LIST_POST_TYPE, 'title' => 'List', 'meta' => [], 'terms' => [] ],
                [ 'id' => 6, 'type' => App::SHOPPING_LIST_POST_TYPE, 'title' => 'flour', 'parent' => 5, 'status' => App::SHOPPING_ITEM_STATUS_CHECKED,
                    'meta' => [ App::META_SHOPPING_ITEM_SOURCE_RECIPES => [ [ 'id' => 1, 'title' => 'Pancakes' ] ] ], 'terms' => [ App::TAX_INGREDIENT => [ 8 ] ] ],
            ],
            'terms' => [ [ 'id' => 8, 'taxonomy' => App::TAX_INGREDIENT, 'name' => 'flour', 'parent' => 0 ] ],
            'preferences' => [ 'units' => 'imperial', 'household' => [ 8 ] ],
        ];
    }

    public function test_restore_keeps_existing_content_and_reconnects_copies(): void {
        $backups = ( new ServiceContainer() )->backups();
        $data = $backups->decode_backup( json_encode( $backups->to_document( $this->data() ) ) );
        $this->assertSame( 6, $backups->restore_data( $data, 7 ) );
        $this->assertSame( 'Existing recipe', $GLOBALS['backup_posts'][99]['post_title'] );
        $this->assertSame( 101, $GLOBALS['backup_posts'][102]['post_parent'] );
        $this->assertSame( 105, $GLOBALS['backup_posts'][106]['post_parent'] );
        $this->assertSame( App::SHOPPING_ITEM_STATUS_CHECKED, $GLOBALS['backup_posts'][106]['post_status'] );
        $this->assertSame( 7, $GLOBALS['backup_posts'][101]['post_author'] );
        $this->assertSame( 101, $GLOBALS['backup_meta'][103][App::META_WEEK_MEALS]['2026-10-01']['lunch'] );
        $this->assertSame( 102, $GLOBALS['backup_meta'][104][App::META_COOKED_RECIPE_ID] );
        $this->assertSame( 101, $GLOBALS['backup_meta'][106][App::META_SHOPPING_ITEM_SOURCE_RECIPES][0]['id'] );
        $this->assertSame( 201, $GLOBALS['backup_meta'][101][App::META_INGREDIENTS][0]['term_id'] );
        $this->assertSame( [ 201 ], $GLOBALS['backup_users'][7][App::USER_HOUSEHOLD_INGREDIENTS] );
        $this->assertSame( 'imperial', $GLOBALS['backup_users'][7][App::USER_PREF_UNITS] );
    }

    public function test_recipe_only_import_uses_existing_ingredient_storage(): void {
        $backups = ( new ServiceContainer() )->backups();
        $data = $backups->decode_backup( json_encode( [ '@context' => 'https://schema.org', '@graph' => [
            [ '@type' => 'Recipe', 'name' => 'Soup', 'recipeIngredient' => [ '200 g carrots' ], 'recipeInstructions' => [ 'Simmer.' ], 'recipeCategory' => 'Dinner' ],
            [ '@type' => 'Recipe', 'name' => 'Salad', 'recipeIngredient' => [ '100 g carrots' ] ],
        ] ] ) );
        $this->assertSame( 2, $backups->restore_data( $data, 7 ) );
        $ingredient = $GLOBALS['backup_meta'][101][App::META_INGREDIENTS][0];
        $this->assertSame( 'carrots', $ingredient['name'] );
        $this->assertGreaterThan( 0, $ingredient['term_id'] );
        $this->assertSame( $ingredient['term_id'], $GLOBALS['backup_meta'][102][App::META_INGREDIENTS][0]['term_id'] );
        $this->assertSame( [ $ingredient['term_id'] ], $GLOBALS['backup_assignments'][101][App::TAX_INGREDIENT] );
        $this->assertSame( [ 'Simmer.' ], $GLOBALS['backup_meta'][101][App::META_INSTRUCTIONS] );
        $this->assertSame( 'Dinner', $GLOBALS['backup_terms'][201]['name'] );
    }

    public function test_failed_restore_removes_new_entries_and_terms(): void {
        $backups = ( new ServiceContainer() )->backups();
        $data = $backups->decode_backup( json_encode( $backups->to_document( $this->data() ) ) );
        $GLOBALS['backup_fail_terms'] = true;
        try {
            $backups->restore_data( $data, 7 );
            $this->fail( 'Restore should fail.' );
        } catch ( RuntimeException $error ) {
            $this->assertSame( 'Simulated taxonomy failure', $error->getMessage() );
        }
        $this->assertSame( [ 99 ], array_keys( $GLOBALS['backup_posts'] ) );
        $this->assertSame( [], $GLOBALS['backup_terms'] );
        $this->assertSame( [], $GLOBALS['backup_users'] );
    }
}
