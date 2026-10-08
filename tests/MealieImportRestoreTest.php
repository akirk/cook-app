<?php

use CookApp\App;
use CookApp\MealieImportService;
use CookApp\ServiceContainer;
use PHPUnit\Framework\TestCase;

/** @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MealieImportRestoreTest extends TestCase {
    private string $path;
    private string $photo;

    protected function setUp(): void {
        if ( ! class_exists( ZipArchive::class ) ) {
            $this->markTestSkipped( 'PHP ZIP extension is required.' );
        }
        require __DIR__ . '/fixtures/backup-wordpress.php';
        require __DIR__ . '/fixtures/mealie-media.php';
        $GLOBALS['backup_next_post'] = 100;
        $GLOBALS['backup_next_term'] = 200;
        $GLOBALS['backup_posts'] = [ 99 => [ 'post_title' => 'Existing recipe' ] ];
        $GLOBALS['backup_terms'] = [];
        $GLOBALS['backup_meta'] = [];
        $GLOBALS['backup_assignments'] = [];
        $GLOBALS['backup_users'] = [ 7 => [ App::USER_PREF_UNITS => 'imperial' ] ];
        $GLOBALS['mealie_attachments'] = [];
        $GLOBALS['mealie_thumbnails'] = [];
        $GLOBALS['mealie_temp_paths'] = [];
        $GLOBALS['cook_app_test_user_caps'] = [ 'upload_files' => true ];
        $photos = require __DIR__ . '/fixtures/mealie-photos.php';
        $this->photo = $photos[0];
        $this->path = tempnam( sys_get_temp_dir(), 'cook-app-mealie-' );
        $zip = new ZipArchive();
        $zip->open( $this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
        $recipe = json_decode( file_get_contents( __DIR__ . '/fixtures/mealie-recipe.json' ), true );
        foreach ( [ 'first', 'second' ] as $slug ) {
            $recipe['id'] = $slug;
            $zip->addFromString( 'recipes/' . $slug . '/' . $slug . '.json', json_encode( $recipe ) );
            $zip->addFromString( 'recipes/' . $slug . '/images/original.webp', $this->photo );
        }
        $zip->close();
    }

    protected function tearDown(): void {
        if ( isset( $this->path ) && file_exists( $this->path ) ) {
            unlink( $this->path );
        }
        foreach ( $GLOBALS['mealie_temp_paths'] ?? [] as $path ) {
            $this->assertFileDoesNotExist( $path );
        }
    }

    public function test_restore_preserves_quantities_sections_and_copies_photos(): void {
        $imports = new MealieImportService( new ServiceContainer() );
        $this->assertSame( 2, $imports->import_archive( $this->path, 7 ) );
        $this->assertSame( 'Existing recipe', $GLOBALS['backup_posts'][99]['post_title'] );
        $this->assertSame( 7, $GLOBALS['backup_posts'][101]['post_author'] );
        $ingredient = $GLOBALS['backup_meta'][101][ App::META_INGREDIENTS ][0];
        $this->assertSame( '200', $ingredient['amount'] );
        $this->assertSame( 'g', $ingredient['unit'] );
        $this->assertSame( 201, $ingredient['term_id'] );
        $this->assertSame( 201, $GLOBALS['backup_meta'][102][ App::META_INGREDIENTS ][0]['term_id'] );
        $this->assertSame( 201, $GLOBALS['backup_meta'][101][ App::META_PARTS ][0]['ingredients'][0]['term_id'] );
        $this->assertSame( [ 201, 202, 203 ], $GLOBALS['backup_assignments'][101][ App::TAX_INGREDIENT ] );
        $this->assertSame( $this->photo, $GLOBALS['mealie_attachments'][1101]['bytes'] );
        $this->assertSame( [ 101 => 1101, 102 => 1102 ], $GLOBALS['mealie_thumbnails'] );
        $this->assertSame( [ 7 => [ App::USER_PREF_UNITS => 'imperial' ] ], $GLOBALS['backup_users'] );
    }

    public function test_second_photo_failure_rolls_back_posts_terms_and_first_photo(): void {
        $GLOBALS['mealie_fail_media_after'] = 1;
        try {
            ( new MealieImportService( new ServiceContainer() ) )->import_archive( $this->path, 7 );
            $this->fail( 'Photo import should fail.' );
        } catch ( RuntimeException $error ) {
            $this->assertSame( 'Could not import the recipe photo.', $error->getMessage() );
        }
        $this->assertSame( [ 99 ], array_keys( $GLOBALS['backup_posts'] ) );
        $this->assertSame( [], $GLOBALS['backup_terms'] );
        $this->assertSame( [], $GLOBALS['mealie_attachments'] );
        $this->assertSame( [], $GLOBALS['mealie_thumbnails'] );
        $this->assertSame( [ 7 => [ App::USER_PREF_UNITS => 'imperial' ] ], $GLOBALS['backup_users'] );
    }

    public function test_thumbnail_failure_removes_the_just_uploaded_photo(): void {
        $GLOBALS['mealie_fail_thumbnail'] = true;
        try {
            ( new MealieImportService( new ServiceContainer() ) )->import_archive( $this->path, 7 );
            $this->fail( 'Thumbnail assignment should fail.' );
        } catch ( RuntimeException $error ) {
            $this->assertSame( 'Could not set the recipe photo.', $error->getMessage() );
        }
        $this->assertSame( [ 99 ], array_keys( $GLOBALS['backup_posts'] ) );
        $this->assertSame( [], $GLOBALS['backup_terms'] );
        $this->assertSame( [], $GLOBALS['mealie_attachments'] );
    }

    public function test_missing_upload_permission_prevents_any_writes(): void {
        $GLOBALS['cook_app_test_user_caps'] = [];
        try {
            ( new MealieImportService( new ServiceContainer() ) )->import_archive( $this->path, 7 );
            $this->fail( 'Photo import requires upload permission.' );
        } catch ( RuntimeException $error ) {
            $this->assertSame( 'Not allowed to upload recipe photos.', $error->getMessage() );
        }
        $this->assertSame( [ 99 ], array_keys( $GLOBALS['backup_posts'] ) );
        $this->assertSame( [], $GLOBALS['backup_terms'] );
    }
}
