<?php
/**
 * PHPUnit bootstrap — stubs the small slice of WordPress that Importer + Units rely on,
 * defines ABSPATH so the plugin's direct-access guards don't exit, and loads the
 * classes under test that do not depend on App's autoloaded services.
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text ) {
        $text = preg_replace( '#<(script|style)[^>]*>.*?</\\1>#si', '', (string) $text );
        return trim( strip_tags( $text ) );
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return false; }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $text ) {
        return trim( wp_strip_all_tags( (string) $text ) );
    }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook_name, ...$args ) {}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $text ) { return (string) $text; }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( '_n' ) ) {
    function _n( $single, $plural, $number, $domain = 'default' ) { return (int) $number === 1 ? $single : $plural; }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) { return (string) $url; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) { return (string) $url; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = [] ) {
        $GLOBALS['wp_remote_get_calls'][] = [ $url, $args ];
        if ( isset( $GLOBALS['wp_remote_get_mock'] ) && is_callable( $GLOBALS['wp_remote_get_mock'] ) ) {
            return $GLOBALS['wp_remote_get_mock']( $url, $args );
        }
        return [ 'body' => '' ];
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) {
        if ( ! is_array( $r ) ) return 0;
        if ( isset( $r['response']['code'] ) ) return (int) $r['response']['code'];
        if ( isset( $r['code'] ) ) return (int) $r['code'];
        return 200;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
    function wp_remote_retrieve_header( $r, $name ) {
        if ( ! is_array( $r ) || empty( $r['headers'] ) || ! is_array( $r['headers'] ) ) {
            return '';
        }
        $name = strtolower( (string) $name );
        foreach ( $r['headers'] as $header => $value ) {
            if ( strtolower( (string) $header ) === $name ) {
                return $value;
            }
        }
        return '';
    }
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/App.php';
require_once dirname( __DIR__ ) . '/src/Markdown.php';
require_once dirname( __DIR__ ) . '/src/Units.php';
require_once dirname( __DIR__ ) . '/src/Importer.php';
require_once dirname( __DIR__ ) . '/src/AbstractService.php';
require_once dirname( __DIR__ ) . '/src/RecipeService.php';
require_once dirname( __DIR__ ) . '/src/StaticArchiveService.php';
