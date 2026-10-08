<?php

use CookApp\App;
use CookApp\BackupService;
use CookApp\MealieImportService;
use CookApp\ServiceContainer;
use PHPUnit\Framework\TestCase;

class MealieImportServiceTest extends TestCase {
    private string $path;
    private MealieImportService $imports;

    protected function setUp(): void {
        if ( ! class_exists( ZipArchive::class ) ) {
            $this->markTestSkipped( 'PHP ZIP extension is required.' );
        }
        $this->path = tempnam( sys_get_temp_dir(), 'cook-app-mealie-' );
        $this->imports = new MealieImportService( new ServiceContainer() );
    }

    protected function tearDown(): void {
        if ( isset( $this->path ) && file_exists( $this->path ) ) {
            unlink( $this->path );
        }
    }

    private function recipe(): array {
        return json_decode( file_get_contents( __DIR__ . '/fixtures/mealie-recipe.json' ), true );
    }

    private function archive( array $files ): void {
        $zip = new ZipArchive();
        $zip->open( $this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
        foreach ( $files as $name => $bytes ) {
            $zip->addFromString( $name, $bytes );
        }
        $zip->close();
    }

    private function photo( int $color = 0 ): string {
        $photos = require __DIR__ . '/fixtures/mealie-photos.php';
        return $photos[ $color === 0 ? 0 : 1 ];
    }

    public function test_single_recipe_preserves_structured_fields_and_bundled_photo(): void {
        $photo = $this->photo();
        $this->archive( [ 'flour-pancakes.json' => json_encode( $this->recipe() ), 'original.webp' => $photo ] );
        $archive = $this->imports->decode_archive( $this->path );
        $post = $archive['data']['posts'][0];
        $meta = $post['meta'];
        $this->assertSame( 'Flour pancakes', $post['title'] );
        $this->assertSame( 2, $meta[ App::META_SERVINGS ] );
        $this->assertSame( 5, $meta[ App::META_PREP ] );
        $this->assertSame( 10, $meta[ App::META_COOK ] );
        $this->assertSame( 'https://example.com/pancakes', $meta[ App::META_SOURCE_URL ] );
        $this->assertSame( "Serving\nServe warm.", $meta[ App::META_NOTES ] );
        $this->assertSame( [ '200', '0.5', '1' ], array_column( $meta[ App::META_INGREDIENTS ], 'amount' ) );
        $this->assertSame( [ 'g', 'cup', '' ], array_column( $meta[ App::META_INGREDIENTS ], 'unit' ) );
        $this->assertSame( 'sifted', $meta[ App::META_INGREDIENTS ][0]['notes'] );
        $this->assertSame( [ 'Batter', 'Topping' ], array_column( $meta[ App::META_PARTS ], 'title' ) );
        $this->assertSame( [ 'Mix: Combine the ingredients.', 'Cook in a pan.' ], $meta[ App::META_PARTS ][0]['instructions'] );
        $this->assertSame( [ 'flour', 'milk', 'tomato', 'Breakfast', 'Quick' ], array_column( $archive['data']['terms'], 'name' ) );
        $this->assertSame( [ 1 => $photo ], $archive['images'] );
        $this->assertArrayNotHasKey( 'preferences', $archive['data'] );
    }

    public function test_bulk_export_imports_every_recipe_with_its_own_photo(): void {
        $second = $this->recipe();
        $second['id'] = 'f465f671-207f-42e3-9f6e-b6c42e3e443b';
        $second['name'] = 'Second recipe';
        $photo = $this->photo();
        $second_photo = $this->photo( 0xFFFFFF );
        $this->archive( [
            'recipes/flour-pancakes/flour-pancakes.json' => json_encode( $this->recipe() ),
            'recipes/flour-pancakes/images/original.webp' => $photo,
            'recipes/second/second.json' => json_encode( $second ),
            'recipes/second/images/original.webp' => $second_photo,
            'recipes/second/assets/instructions.txt' => 'An unrelated asset',
        ] );
        $archive = $this->imports->decode_archive( $this->path );
        $this->assertSame( [ 'Flour pancakes', 'Second recipe' ], array_column( $archive['data']['posts'], 'title' ) );
        $this->assertSame( [ 1 => $photo, 2 => $second_photo ], $archive['images'] );
        $this->assertCount( 5, $archive['data']['terms'] );
    }

    public function test_camel_case_export_and_iso_durations_are_supported(): void {
        $recipe = $this->recipe();
        foreach ( [ 'recipe_ingredient' => 'recipeIngredient', 'recipe_instructions' => 'recipeInstructions',
            'recipe_category' => 'recipeCategory', 'recipe_servings' => 'recipeServings',
            'prep_time' => 'prepTime', 'perform_time' => 'performTime', 'total_time' => 'totalTime', 'org_url' => 'orgURL' ] as $snake => $camel ) {
            $recipe[ $camel ] = $recipe[ $snake ];
            unset( $recipe[ $snake ] );
        }
        $recipe['prepTime'] = 'PT5M';
        $recipe['performTime'] = 'PT1H30M';
        $this->archive( [ 'recipe.json' => json_encode( $recipe ) ] );
        $archive = $this->imports->decode_archive( $this->path );
        $this->assertSame( 5, $archive['data']['posts'][0]['meta'][ App::META_PREP ] );
        $this->assertSame( 90, $archive['data']['posts'][0]['meta'][ App::META_COOK ] );
        $this->assertSame( [], $archive['images'] );
    }

    public function test_total_time_fallback_subtracts_preparation_time(): void {
        $recipe = $this->recipe();
        $recipe['perform_time'] = null;
        $this->archive( [ 'recipe.json' => json_encode( $recipe ) ] );
        $archive = $this->imports->decode_archive( $this->path );
        $this->assertSame( 10, $archive['data']['posts'][0]['meta'][ App::META_COOK ] );
    }

    public function test_unsafe_paths_are_rejected_without_extraction(): void {
        $this->archive( [ '../escape.json' => json_encode( $this->recipe() ) ] );
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Unsafe or duplicate path' );
        $this->imports->decode_archive( $this->path );
    }

    public function test_symlinks_are_rejected(): void {
        $this->archive( [ 'recipe.json' => json_encode( $this->recipe() ), 'link' => '/tmp/target' ] );
        $zip = new ZipArchive();
        $zip->open( $this->path );
        $zip->setExternalAttributesName( 'link', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
        $zip->close();
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Symbolic links' );
        $this->imports->decode_archive( $this->path );
    }

    public function test_expansion_limit_applies_even_to_ignored_assets(): void {
        $this->archive( [ 'recipe.json' => json_encode( $this->recipe() ), 'asset.txt' => str_repeat( 'a', BackupService::MAX_BYTES + 1 ) ] );
        $this->assertLessThan( BackupService::MAX_BYTES, filesize( $this->path ) );
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Expanded Mealie archive' );
        $this->imports->decode_archive( $this->path );
    }

    public function test_file_count_limit_is_enforced(): void {
        $files = [];
        for ( $index = 0; $index <= MealieImportService::MAX_ENTRIES; ++$index ) {
            $files[ 'file-' . $index ] = '';
        }
        $this->archive( $files );
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Too many files' );
        $this->imports->decode_archive( $this->path );
    }

    public function test_database_backups_are_rejected(): void {
        $this->archive( [ 'database.json' => '{"recipes": []}' ] );
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Expected a Mealie recipe export' );
        $this->imports->decode_archive( $this->path );
    }

    public function test_duplicate_recipe_ids_are_rejected(): void {
        $json = json_encode( $this->recipe() );
        $this->archive( [ 'recipes/first/first.json' => $json, 'recipes/second/second.json' => $json ] );
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Duplicate recipe' );
        $this->imports->decode_archive( $this->path );
    }

    public function test_invalid_later_recipe_prevents_any_import(): void {
        $recipe = $this->recipe();
        $recipe['id'] = 'second';
        $recipe['recipe_ingredient'][0]['quantity'] = [ 'invalid' ];
        $this->archive( [ 'recipes/first/first.json' => json_encode( $this->recipe() ), 'recipes/second/second.json' => json_encode( $recipe ) ] );
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Invalid quantity' );
        $this->imports->import_archive( $this->path, 7 );
    }

    public function test_invalid_image_prevents_import(): void {
        $this->archive( [ 'recipe.json' => json_encode( $this->recipe() ), 'original.webp' => 'not an image' ] );
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Invalid or oversized' );
        $this->imports->decode_archive( $this->path );
    }

    public function test_corrupt_zip_is_rejected(): void {
        file_put_contents( $this->path, 'not a zip' );
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Invalid Mealie ZIP' );
        $this->imports->decode_archive( $this->path );
    }
}
