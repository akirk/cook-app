<?php

use CookApp\AbilitiesService;
use CookApp\App;
use PHPUnit\Framework\TestCase;

class AbilitiesServiceTest extends TestCase {
    private AbilitiesService $abilities;

    protected function setUp(): void {
        $this->abilities = ( new ReflectionClass( AbilitiesService::class ) )->newInstanceWithoutConstructor();
        $GLOBALS['cook_app_test_user_logged_in'] = true;
        $GLOBALS['cook_app_test_user_caps'] = [];
        $GLOBALS['cook_app_test_posts'] = [
            10 => (object) [ 'ID' => 10, 'post_type' => App::POST_TYPE ],
            20 => (object) [ 'ID' => 20, 'post_type' => 'post' ],
        ];
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['cook_app_test_user_logged_in'],
            $GLOBALS['cook_app_test_user_caps'],
            $GLOBALS['cook_app_test_posts']
        );
    }

    public function test_get_recipe_requires_per_post_read_capability(): void {
        $this->assertFalse( $this->abilities->can_read_recipe_ability( [ 'id' => 10 ] ) );

        $GLOBALS['cook_app_test_user_caps']['read_post:10'] = true;
        $this->assertTrue( $this->abilities->can_read_recipe_ability( [ 'id' => 10 ] ) );
        $this->assertFalse( $this->abilities->can_read_recipe_ability( [ 'id' => 20 ] ) );
    }

    public function test_create_requires_publish_posts(): void {
        $GLOBALS['cook_app_test_user_caps']['edit_posts'] = true;
        $this->assertFalse( $this->abilities->can_save_recipe_ability( [] ) );

        $GLOBALS['cook_app_test_user_caps']['publish_posts'] = true;
        $this->assertTrue( $this->abilities->can_save_recipe_ability( [] ) );
        $this->assertTrue( $this->abilities->can_publish_recipe_abilities() );
    }

    public function test_update_requires_per_post_edit_capability(): void {
        $GLOBALS['cook_app_test_user_caps']['publish_posts'] = true;
        $this->assertFalse( $this->abilities->can_save_recipe_ability( [ 'id' => 10 ] ) );

        $GLOBALS['cook_app_test_user_caps']['edit_post:10'] = true;
        $this->assertTrue( $this->abilities->can_save_recipe_ability( [ 'id' => 10 ] ) );
    }

    public function test_save_with_parent_requires_parent_read_capability(): void {
        $GLOBALS['cook_app_test_user_caps']['publish_posts'] = true;
        $this->assertFalse( $this->abilities->can_save_recipe_ability( [ 'parent_id' => 10 ] ) );

        $GLOBALS['cook_app_test_user_caps']['read_post:10'] = true;
        $this->assertTrue( $this->abilities->can_save_recipe_ability( [ 'parent_id' => 10 ] ) );
    }

    public function test_variation_requires_publish_and_source_read_capabilities(): void {
        $GLOBALS['cook_app_test_user_caps']['publish_posts'] = true;
        $this->assertFalse( $this->abilities->can_create_recipe_variation_ability( [ 'source_recipe_id' => 10 ] ) );

        $GLOBALS['cook_app_test_user_caps']['read_post:10'] = true;
        $this->assertTrue( $this->abilities->can_create_recipe_variation_ability( [ 'source_recipe_id' => 10 ] ) );
    }
}
