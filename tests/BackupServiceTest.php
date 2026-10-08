<?php

use CookApp\App;
use CookApp\BackupService;
use CookApp\JsonLdRecipeParser;
use PHPUnit\Framework\TestCase;

class BackupServiceTest extends TestCase {
    private BackupService $backups;

    protected function setUp(): void {
        $this->backups = ( new ReflectionClass( BackupService::class ) )->newInstanceWithoutConstructor();
    }

    private function data(): array {
        return [
            'format' => 'cook-app-backup', 'version' => 1,
            'terms' => [ [ 'id' => 8, 'taxonomy' => App::TAX_CATEGORY, 'name' => 'Breakfast', 'parent' => 0 ] ],
            'preferences' => [ 'units' => 'metric', 'household' => [] ],
            'posts' => [ [
                'id' => 12, 'type' => App::POST_TYPE, 'title' => 'Pancakes',
                'content' => 'Weekend breakfast', 'status' => 'draft', 'parent' => 0,
                'date' => '2026-10-01 09:00:00', 'image_url' => 'https://example.com/photo.jpg',
                'terms' => [ App::TAX_CATEGORY => [ 8 ] ],
                'meta' => [
                    App::META_SERVINGS => '2', App::META_PREP => '5', App::META_COOK => '10',
                    App::META_INGREDIENTS => [ [ 'amount' => '200', 'unit' => 'g', 'name' => 'flour', 'term_id' => 50, 'notes' => '' ] ],
                    App::META_INSTRUCTIONS => [ 'Mix.', 'Cook.' ],
                    App::META_NOTES => '**Serve warm.**', App::META_SOURCE_URL => 'https://example.com/pancakes',
                ],
            ] ],
        ];
    }

    public function test_backup_exposes_standard_recipes_and_round_trips_exact_data(): void {
        $data = $this->data();
        $document = $this->backups->to_document( $data );
        $this->assertSame( 'Recipe', $document['@graph'][0]['@type'] );
        $this->assertSame( [ '200 g flour' ], $document['@graph'][0]['recipeIngredient'] );
        $this->assertSame( 'PT5M', $document['@graph'][0]['prepTime'] );
        $this->assertSame( [ 'Breakfast' ], $document['@graph'][0]['recipeCategory'] );
        $this->assertArrayNotHasKey( 'title', $document['cookApp:backup']['posts'][0] );
        $restored = $this->backups->decode_backup( json_encode( $document ) );
        $post = $restored['posts'][0];
        $this->assertSame( $data['posts'][0]['meta'], $post['meta'] );
        $this->assertSame( 'Pancakes', $post['title'] );
        $this->assertSame( 'Weekend breakfast', $post['content'] );
        $this->assertSame( 'draft', $post['status'] );
        $this->assertSame( $data['preferences'], $restored['preferences'] );
        unset( $document['cookApp:backup'] );
        $portable = $this->backups->decode_backup( json_encode( $document ) );
        $this->assertTrue( $portable['portable'] );
        $this->assertSame( 'Pancakes', $portable['posts'][0]['title'] );
        $this->assertSame( 'Breakfast', $portable['terms'][0]['name'] );
    }

    public function test_parser_imports_all_graph_recipes_and_preserves_web_first_recipe_behavior(): void {
        $json = json_encode( [ '@context' => 'https://schema.org', '@graph' => [
            [ '@type' => 'WebPage', 'name' => 'Ignore me' ],
            [ '@type' => 'Recipe', 'name' => 'First', 'recipeIngredient' => [ '2 eggs' ] ],
            [ '@type' => [ 'Thing', 'Recipe' ], 'name' => 'Second', 'keywords' => 'quick, lunch' ],
        ] ] );
        $parser = new JsonLdRecipeParser();
        $this->assertSame( [ 'First', 'Second' ], array_column( $parser->parse_all( $json ), 'title' ) );
        $this->assertSame( 'First', $parser->parse( '', 'application/ld+json', $json )['title'] );
        $data = $this->backups->decode_backup( $json );
        $this->assertCount( 2, $data['posts'] );
        $this->assertSame( [ 'quick', 'lunch' ], array_column( $data['terms'], 'name' ) );
    }

    public function test_recipe_export_is_standalone_and_preserves_interchange_fields(): void {
        $data = $this->data();
        $data['posts'][0]['meta'][ App::META_PARTS ] = [ [ 'title' => 'Batter',
            'ingredients' => $data['posts'][0]['meta'][ App::META_INGREDIENTS ],
            'instructions' => [ 'Mix.', 'Cook.' ],
        ] ];
        $document = $this->backups->to_document( $data );
        $recipes = $this->backups->recipe_document( $document );
        $this->assertCount( 1, $recipes );
        $recipe = $recipes[0];
        $this->assertSame( 'https://schema.org', $recipe['@context'] );
        $this->assertSame( [ 'https://example.com/photo.jpg' ], $recipe['image'] );
        $this->assertSame( 'PT15M', $recipe['totalTime'] );
        $this->assertSame( 'https://example.com/pancakes', $recipe['isBasedOn'] );
        $this->assertSame( '**Serve warm.**', $recipe['comment'][0]['text'] );
        $this->assertSame( [ 'Batter: Mix.', 'Cook.' ], array_column( $recipe['recipeInstructions'], 'text' ) );
        $this->assertSame( [ 'HowToStep', 'HowToStep' ], array_column( $recipe['recipeInstructions'], '@type' ) );
        $this->assertStringNotContainsString( 'cookApp', json_encode( $recipes ) );
        $restored = $this->backups->decode_backup( json_encode( $recipes ) );
        $this->assertTrue( $restored['portable'] );
        $this->assertSame( 'https://example.com/pancakes', $restored['posts'][0]['meta'][ App::META_SOURCE_URL ] );
        $this->assertSame( '**Serve warm.**', $restored['posts'][0]['meta'][ App::META_NOTES ] );
        $this->assertSame( 'HowToSection', $document['@graph'][0]['recipeInstructions'][0]['@type'] );
    }

    public function test_recipe_export_handles_empty_cookbooks_and_missing_photos(): void {
        $this->assertSame( [], $this->backups->recipe_document( $this->backups->to_document( [ 'posts' => [], 'terms' => [] ] ) ) );
        $data = $this->data();
        $data['posts'][0]['image_url'] = '';
        $recipes = $this->backups->recipe_document( $this->backups->to_document( $data ) );
        $this->assertSame( [], $recipes[0]['image'] );
    }

    public function test_recipesage_style_export_imports_source_notes_and_multiple_recipes(): void {
        $data = $this->backups->decode_backup( file_get_contents( __DIR__ . '/fixtures/recipesage-export.json' ) );
        $this->assertCount( 2, $data['posts'] );
        $this->assertSame( 'https://example.com/pancakes', $data['posts'][0]['meta'][ App::META_SOURCE_URL ] );
        $this->assertSame( 'Serve warm.', $data['posts'][0]['meta'][ App::META_NOTES ] );
        $this->assertSame( [ 'Mix.', 'Cook.' ], $data['posts'][0]['meta'][ App::META_INSTRUCTIONS ] );
        $this->assertSame( 'Breakfast', $data['terms'][0]['name'] );
    }

    public function test_plain_recipe_and_top_level_array_are_accepted(): void {
        $recipe = [ '@context' => 'https://schema.org', '@type' => 'Recipe', 'name' => 'Soup' ];
        $this->assertCount( 1, $this->backups->decode_backup( json_encode( $recipe ) )['posts'] );
        $this->assertCount( 2, $this->backups->decode_backup( json_encode( [ $recipe, $recipe ] ) )['posts'] );
    }

    public function test_missing_recipe_reference_is_rejected(): void {
        $document = $this->backups->to_document( $this->data() );
        $document['@graph'] = [];
        $this->expectException( InvalidArgumentException::class );
        $this->backups->decode_backup( json_encode( $document ) );
    }

    public function test_unsupported_version_is_rejected(): void {
        $document = $this->backups->to_document( $this->data() );
        $document['cookApp:backup']['version'] = 2;
        $this->expectException( InvalidArgumentException::class );
        $this->backups->decode_backup( json_encode( $document ) );
    }

    public function test_duplicate_post_ids_are_rejected(): void {
        $document = $this->backups->to_document( $this->data() );
        $document['cookApp:backup']['posts'][] = $document['cookApp:backup']['posts'][0];
        $this->expectException( InvalidArgumentException::class );
        $this->backups->decode_backup( json_encode( $document ) );
    }

    public function test_cyclic_parents_are_rejected(): void {
        $document = $this->backups->to_document( $this->data() );
        $document['cookApp:backup']['posts'][0]['parent'] = 12;
        $this->expectException( InvalidArgumentException::class );
        $this->backups->decode_backup( json_encode( $document ) );
    }

    public function test_wrong_taxonomy_reference_is_rejected(): void {
        $document = $this->backups->to_document( $this->data() );
        $document['cookApp:backup']['posts'][0]['terms'] = [ App::TAX_INGREDIENT => [ 8 ] ];
        $this->expectException( InvalidArgumentException::class );
        $this->backups->decode_backup( json_encode( $document ) );
    }

    public function test_invalid_nested_metadata_is_rejected(): void {
        $document = $this->backups->to_document( $this->data() );
        $document['cookApp:backup']['posts'][0]['meta'][App::META_INGREDIENTS][0]['name'] = [ 'invalid' ];
        $this->expectException( InvalidArgumentException::class );
        $this->backups->decode_backup( json_encode( $document ) );
    }

    public function test_invalid_json_is_rejected(): void {
        $this->expectException( InvalidArgumentException::class );
        $this->backups->decode_backup( '{' );
    }

    public function test_oversized_files_are_rejected(): void {
        $this->expectException( InvalidArgumentException::class );
        $this->backups->decode_backup( str_repeat( ' ', BackupService::MAX_BYTES + 1 ) );
    }

    public function test_references_are_remapped_and_missing_ids_are_cleared(): void {
        $map = [ 12 => 120 ];
        $terms = [ 50 => 500 ];
        $this->assertSame( [ '2026-10-01' => [ 'lunch' => 120, 'dinner' => 0 ] ], $this->backups->remap_value(
            [ '2026-10-01' => [ 'lunch' => 12, 'dinner' => 999 ] ], $map, $terms, App::META_WEEK_MEALS
        ) );
        $this->assertSame( 120, $this->backups->remap_value( '12', $map, $terms, App::META_COOKED_RECIPE_ID ) );
        $items = $this->backups->remap_value( [ [
            'term_id' => 50, 'term_ids' => [ 50, 999 ], 'source_recipe_id' => 12,
            'source_recipes' => [ [ 'id' => 12, 'title' => 'Pancakes' ], [ 'id' => 999, 'title' => 'Missing' ] ],
        ] ], $map, $terms, App::META_SHOPPING_ITEMS );
        $this->assertSame( 500, $items[0]['term_id'] );
        $this->assertSame( [ 500 ], $items[0]['term_ids'] );
        $this->assertSame( 120, $items[0]['source_recipe_id'] );
        $this->assertSame( [ 120, 0 ], array_column( $items[0]['source_recipes'], 'id' ) );
    }
}
