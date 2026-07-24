<?php
/**
 * Plugin Name: WP FastLayer
 * Plugin URI: https://github.com/kanhajatthap/WP-FastLayer
 * Description: High-performance WordPress caching and optimization plugin.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Kanha Jatthap
 * Author URI: https://github.com/kanhajatthap
 * Text Domain: wp-fastlayer
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/kanhajatthap/WP-FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_FASTLAYER_VERSION', '1.0.0' );
define( 'WP_FASTLAYER_FILE', __FILE__ );
define( 'WP_FASTLAYER_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_FASTLAYER_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_FASTLAYER_CACHE_DIR', WP_CONTENT_DIR . '/cache/wp-fastlayer/' );

// Capture any fatal errors during plugin load and write to PHP error log.
register_shutdown_function( function () {
    $error = error_get_last();
    if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
        error_log(
            'WP FastLayer FATAL: ' . $error['message'] .
            ' in ' . $error['file'] .
            ' on line ' . $error['line']
        );
    }
} );

if ( ! function_exists( 'wp_fastlayer_debug_log' ) ) {
    function wp_fastlayer_debug_log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $message );
        }
    }
}

load_plugin_textdomain( 'wp-fastlayer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'WP_FastLayer\\' ) !== 0 ) {
        return;
    }
    $relative = str_replace( 'WP_FastLayer\\', '', $class );
    $filename = str_replace( '_', '-', strtolower( $relative ) );

    $exceptions = array();

    $file = WP_FASTLAYER_PATH . 'includes/class-' . $filename . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

$_wpfl_bootstrap_file = WP_FASTLAYER_PATH . 'includes/class-bootstrap.php';
if ( file_exists( $_wpfl_bootstrap_file ) ) {
    require_once $_wpfl_bootstrap_file;
} else {
    error_log( 'WP FastLayer: class-bootstrap.php not found at ' . $_wpfl_bootstrap_file );
    return;
}
unset( $_wpfl_bootstrap_file );

if ( class_exists( 'WP_FastLayer\\Bootstrap' ) ) {
    WP_FastLayer\Bootstrap::get_instance();
} else {
    error_log( 'WP FastLayer: WP_FastLayer\\Bootstrap class not available after include.' );
}

if ( class_exists( 'WP_FastLayer\\Admin' ) ) {
    WP_FastLayer\Admin::get_instance();
}
