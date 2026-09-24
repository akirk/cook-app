<?php

namespace WpApp {
    if ( ! class_exists( BaseApp::class ) ) {
        class BaseApp {}
    }

    if ( ! class_exists( WpApp::class ) ) {
        class WpApp {}
    }
}

namespace {
    use CookApp\App;
    use PHPUnit\Framework\TestCase;

    if ( ! function_exists( 'absint' ) ) {
        function absint( $value ) {
            return abs( (int) $value );
        }
    }

    if ( ! function_exists( 'get_current_user_id' ) ) {
        function get_current_user_id() {
            return 7;
        }
    }

    if ( ! function_exists( 'get_posts' ) ) {
        function get_posts( $query = [] ) {
            $GLOBALS['cookbook_test_last_get_posts_query'] = $query;
            return [];
        }
    }

    require_once dirname( __DIR__ ) . '/src/App.php';

    class AppCookedEntriesTest extends TestCase {

        public function test_recipe_cooked_entries_default_to_current_user(): void {
            $GLOBALS['cookbook_test_last_get_posts_query'] = null;

            App::get_recipe_cooked_entries( 123, 1 );

            $query = $GLOBALS['cookbook_test_last_get_posts_query'];
            $this->assertSame( 7, $query['author'] );
            $this->assertSame( 1, $query['posts_per_page'] );
            $this->assertSame( App::META_COOKED_RECIPE_ID, $query['meta_query'][0]['key'] );
            $this->assertSame( 123, $query['meta_query'][0]['value'] );
        }

        public function test_recipe_cooked_entries_honor_explicit_user(): void {
            $GLOBALS['cookbook_test_last_get_posts_query'] = null;

            App::get_recipe_cooked_entries( 123, 1, 9 );

            $query = $GLOBALS['cookbook_test_last_get_posts_query'];
            $this->assertSame( 9, $query['author'] );
        }
    }
}
