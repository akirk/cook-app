<?php
/**
 * Plugin Name: Cookbook App
 * Plugin URI: https://wpapps.kirk.at/apps/cookbook/
 * Description: A personal cookbook for WordPress: store, import, categorize, scale, plan and shop from your own recipes.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author: Alex Kirk
 * Author URI: https://alex.kirk.at/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cookbook
 * Domain Path: /languages
 */

namespace Cookbook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

// Autoloader for plugin classes.
spl_autoload_register( function( $class ) {
    $prefix = 'Cookbook\\';
    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

add_action( 'init', function() {
    $app = new App();
    $app->init();
} );

register_activation_hook( __FILE__, function() {
    $app = new App();
    $app->activate();
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );
