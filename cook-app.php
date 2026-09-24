<?php
/**
 * Plugin Name: Cook App
 * Plugin URI: https://wpapps.kirk.at/apps/cook-app/
 * Description: A personal cookbook for WordPress: store, import, categorize, scale, plan and shop from your own recipes.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author: Alex Kirk
 * Author URI: https://alex.kirk.at/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cook-app
 * Domain Path: /languages
 */

namespace Cookbook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'COOK_APP_PLUGIN_FILE', __FILE__ );
define( 'COOK_APP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'COOK_APP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once COOK_APP_PLUGIN_DIR . 'vendor/autoload.php';

// Autoloader for plugin classes.
spl_autoload_register( function( $class ) {
    $prefix = 'Cookbook\\';
    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }
    $file = COOK_APP_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

add_action( 'init', function() {
    $app = new App();
    $app->init();
} );

register_activation_hook( COOK_APP_PLUGIN_FILE, function() {
    $app = new App();
    $app->activate();
} );

register_deactivation_hook( COOK_APP_PLUGIN_FILE, function() {
    flush_rewrite_rules();
} );
