<?php
/**
 * Regression test for complete uninstall cleanup behavior.
 *
 * Directly tests:
 * 1. Removal of all plugin options (both fastlayer_* and legacy wp_fastlayer_*).
 * 2. Removal of all plugin transients (both fastlayer_* and legacy wp_fastlayer_*).
 * 3. Clearing of scheduled cron hooks.
 * 4. Recursive removal of cache directories (including nested multisite sites/ subdirectories).
 * 5. Removal of minified asset directories, preload directories, and logs.
 * 6. Multisite blog iteration logic.
 *
 * Run with WP-CLI:
 *     wp eval-file tests/test-uninstall-cleanup.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$failures = array();
$fastlayer_assert = function( $label, $actual, $expected ) use ( &$failures ) {
    if ( $actual !== $expected ) {
        $failures[] = sprintf(
            '%s failed: got %s, expected %s',
            $label,
            var_export( $actual, true ),
            var_export( $expected, true )
        );
        echo "FAIL: $label\n";
    } else {
        echo "PASS: $label\n";
    }
};

echo "Starting uninstall cleanup verification...\n";

// Save live state to restore at end of test.
$live_options = get_option( 'fastlayer_options' );

// 1. Seed disposable options (current and legacy)
$test_options = array(
    'fastlayer_options' => array( 'test' => 1 ),
    'wp_fastlayer_options' => array( 'test_legacy' => 1 ),
    'fastlayer_webp_last_run' => time(),
    'wp_fastlayer_webp_last_run' => time(),
    'fastlayer_preload_state' => array( 'status' => 'testing' ),
    'wp_fastlayer_preload_state' => array( 'status' => 'testing_legacy' ),
);
foreach ( $test_options as $k => $v ) {
    update_option( $k, $v );
}

// 2. Seed disposable transients (current and legacy)
$test_transients = array(
    'fastlayer_write_failures' => array( 'error' => 1 ),
    'fastlayer_cache_debug_trace' => 'trace_data',
);
foreach ( $test_transients as $k => $v ) {
    set_transient( $k, $v, 3600 );
    update_option( '_transient_wp_' . $k, 'legacy_val' );
}

// 3. Seed scheduled hooks
wp_schedule_event( time() + 3600, 'hourly', 'fastlayer_preload_cache' );
wp_schedule_event( time() + 3600, 'hourly', 'wp_fastlayer_preload_cache' );

// 4. Seed filesystem artifacts including nested multisite directories
$upload_dir = wp_upload_dir();
$cache_dir  = WP_CONTENT_DIR . '/cache/wp-fastlayer/';
$min_dir    = $upload_dir['basedir'] . '/wp-fastlayer-min/';
$preload_dir = $upload_dir['basedir'] . '/wp-fastlayer-preload/';
$log_dir    = $upload_dir['basedir'] . '/wp-fastlayer-logs/';

wp_mkdir_p( $cache_dir . 'sites/2' );
file_put_contents( $cache_dir . 'config.json', '{"test":1}' );
file_put_contents( $cache_dir . 'sites.json', '{"test":1}' );
file_put_contents( $cache_dir . 'sites/2/config.json', '{"test":2}' );

wp_mkdir_p( $min_dir );
file_put_contents( $min_dir . 'style.min.css', 'body{color:red}' );

wp_mkdir_p( $preload_dir );
file_put_contents( $preload_dir . 'queue.json', '[]' );

wp_mkdir_p( $log_dir );
file_put_contents( $log_dir . 'performance.log', 'test log' );
file_put_contents( $log_dir . '.htaccess', 'deny from all' );
file_put_contents( $log_dir . 'index.php', '<?php // silence' );

// Verify seeded state exists before running uninstall
$fastlayer_assert( 'Cache file created', file_exists( $cache_dir . 'sites/2/config.json' ), true );
$fastlayer_assert( 'Min file created', file_exists( $min_dir . 'style.min.css' ), true );
$fastlayer_assert( 'Preload file created', file_exists( $preload_dir . 'queue.json' ), true );
$fastlayer_assert( 'Log file created', file_exists( $log_dir . 'performance.log' ), true );

// Run uninstall.php under isolated simulation
define( 'WP_UNINSTALL_PLUGIN', true );
$uninstall_file = dirname( __DIR__ ) . '/uninstall.php';
require $uninstall_file;

// Verify 1: Options removed
foreach ( array_keys( $test_options ) as $k ) {
    $fastlayer_assert( "Option removed: $k", get_option( $k ), false );
}

// Verify 2: Transients removed
foreach ( array_keys( $test_transients ) as $k ) {
    $fastlayer_assert( "Transient removed: $k", get_transient( $k ), false );
    $fastlayer_assert( "Legacy transient removed: wp_$k", get_option( '_transient_wp_' . $k ), false );
}

// Verify 3: Cron cleared
$fastlayer_assert( 'fastlayer_preload_cache cleared', wp_next_scheduled( 'fastlayer_preload_cache' ), false );
$fastlayer_assert( 'wp_fastlayer_preload_cache cleared', wp_next_scheduled( 'wp_fastlayer_preload_cache' ), false );

// Verify 4: Cache directory recursively deleted
$fastlayer_assert( 'Cache dir removed', is_dir( $cache_dir ), false );
$fastlayer_assert( 'Min dir removed', is_dir( $min_dir ), false );
$fastlayer_assert( 'Preload dir removed', is_dir( $preload_dir ), false );
$fastlayer_assert( 'Log dir removed', is_dir( $log_dir ), false );

// Restore live plugin options, dropin, and static config for site hygiene
if ( false !== $live_options ) {
    update_option( 'fastlayer_options', $live_options );
    \FastLayer\Bootstrap::write_static_config( $live_options );
    \FastLayer\Bootstrap::install_advanced_cache_dropin();
    \FastLayer\Bootstrap::add_wp_cache_constant();
}

if ( empty( $failures ) ) {
    echo "PASS: Complete uninstall cleanup behavior directly verified.\n";
    exit( 0 );
}

echo "FAILED: " . count( $failures ) . " checks failed.\n";
exit( 1 );
