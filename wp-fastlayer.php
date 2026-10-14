<?php
/**
 * Plugin Name: FastLayer
 * Plugin URI: https://github.com/kanhajatthap/WP-FastLayer
 * Description: High-performance WordPress caching and optimization plugin.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Kanha Jatthap
 * Author URI: https://github.com/kanhajatthap
 * Text Domain: fastlayer
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FASTLAYER_VERSION', '1.1.0' );
define( 'FASTLAYER_FILE', __FILE__ );
define( 'FASTLAYER_PATH', plugin_dir_path( __FILE__ ) );
define( 'FASTLAYER_URL', plugin_dir_url( __FILE__ ) );
define( 'FASTLAYER_CACHE_DIR', WP_CONTENT_DIR . '/cache/wp-fastlayer/' );

// Capture any fatal errors during plugin load and write to PHP error log.
register_shutdown_function( function () {
    $error = error_get_last();
    if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
        error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- unconditional fatal-error capture in the shutdown handler; must always reach the PHP log.
            'FastLayer FATAL: ' . $error['message'] .
            ' in ' . $error['file'] .
            ' on line ' . $error['line']
        );
    }
} );

if ( ! function_exists( 'fastlayer_debug_log' ) ) {
    function fastlayer_debug_log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the plugin's single controlled debug sink, gated by WP_DEBUG.
        }
    }
}

load_plugin_textdomain( 'fastlayer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'FastLayer\\' ) !== 0 ) {
        return;
    }
    $relative = str_replace( 'FastLayer\\', '', $class );
    $filename = str_replace( '_', '-', strtolower( $relative ) );

    $exceptions = array();

    $file = FASTLAYER_PATH . 'includes/class-' . $filename . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

if ( file_exists( FASTLAYER_PATH . 'includes/class-bootstrap.php' ) ) {
    require_once FASTLAYER_PATH . 'includes/class-bootstrap.php';
} else {
    error_log( 'WP FastLayer: class-bootstrap.php not found at ' . FASTLAYER_PATH . 'includes/class-bootstrap.php' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- load-order failure diagnostic that must always be recorded regardless of WP_DEBUG.
    return;
}

if ( class_exists( 'FastLayer\\Bootstrap' ) ) {
    FastLayer\Bootstrap::get_instance();
} else {
    error_log( 'WP FastLayer: FastLayer\\Bootstrap class not available after include.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- load-order failure diagnostic that must always be recorded regardless of WP_DEBUG.
}

if ( class_exists( 'FastLayer\\Admin' ) ) {
    FastLayer\Admin::get_instance();
}
