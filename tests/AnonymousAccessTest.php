<?php

use CookApp\AccessService;
use PHPUnit\Framework\TestCase;

function get_query_var( $name, $default = '' ) {
    return $GLOBALS['cook_app_test_query_vars'][ $name ] ?? $default;
}

function nocache_headers() {
    $GLOBALS['cook_app_test_nocache'] = true;
}

function wp_die( $message, $title = '', $args = [] ) {
    $GLOBALS['cook_app_test_die'] = compact( 'message', 'title', 'args' );
    throw new RuntimeException( 'Request terminated' );
}

final class AnonymousAccessTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['cook_app_test_user_logged_in'] = false;
        $GLOBALS['cook_app_test_query_vars'] = [];
        $GLOBALS['cook_app_test_nocache'] = false;
        $GLOBALS['cook_app_test_die'] = null;
    }

    protected function tearDown(): void {
        unset( $GLOBALS['cook_app_test_query_vars'], $GLOBALS['cook_app_test_nocache'], $GLOBALS['cook_app_test_die'] );
        $GLOBALS['cook_app_test_user_logged_in'] = false;
    }

    /** @dataProvider appPaths */
    public function test_anonymous_requests_stop_before_the_login_redirect( string $path ): void {
        $GLOBALS['cook_app_test_query_vars'] = [
            'wp_app_path' => 'cook-app',
            'wp_app_request' => $path,
        ];
        $service = ( new ReflectionClass( AccessService::class ) )->newInstanceWithoutConstructor();
        try {
            $service->protect_anonymous_app_request();
            $this->fail( 'Anonymous request was allowed to reach the app router.' );
        } catch ( RuntimeException $exception ) {
            $this->assertSame( 'Request terminated', $exception->getMessage() );
        }
        $this->assertTrue( $GLOBALS['cook_app_test_nocache'] );
        $this->assertSame( 404, $GLOBALS['cook_app_test_die']['args']['response'] );
        $this->assertFalse( $GLOBALS['cook_app_test_die']['args']['back_link'] );
        $this->assertSame( 'Not found.', $GLOBALS['cook_app_test_die']['message'] );
    }

    public static function appPaths(): array {
        return [ [ '' ], [ 'recipe/123' ], [ 'recipe/123/edit' ], [ 'settings' ], [ 'missing' ] ];
    }

    public function test_logged_in_users_continue_to_existing_permission_checks(): void {
        $GLOBALS['cook_app_test_query_vars']['wp_app_path'] = 'cook-app';
        $GLOBALS['cook_app_test_user_logged_in'] = true;
        $service = ( new ReflectionClass( AccessService::class ) )->newInstanceWithoutConstructor();
        $service->protect_anonymous_app_request();
        $this->assertNull( $GLOBALS['cook_app_test_die'] );
        $this->assertFalse( $GLOBALS['cook_app_test_nocache'] );
    }

    /** @dataProvider otherApps */
    public function test_other_requests_are_unaffected( string $app ): void {
        $GLOBALS['cook_app_test_query_vars']['wp_app_path'] = $app;
        $service = ( new ReflectionClass( AccessService::class ) )->newInstanceWithoutConstructor();
        $service->protect_anonymous_app_request();
        $this->assertNull( $GLOBALS['cook_app_test_die'] );
        $this->assertFalse( $GLOBALS['cook_app_test_nocache'] );
    }

    public static function otherApps(): array {
        return [ [ '' ], [ 'friends' ], [ 'cook-app-public' ] ];
    }
}
