<?php
/**
 * Regression test for secure log path resolution, outside-webroot storage,
 * and elimination of publicly accessible log files.
 *
 * Verifies that:
 * 1. Bootstrap::is_path_outside_webroot() correctly identifies paths inside/outside web root.
 * 2. Bootstrap::get_log_file() resolves strictly to a location outside the public document root
 *    or fails closed (returns false), and never returns a path under uploads/ or ABSPATH.
 * 3. Bootstrap::create_logs_directory() actively removes legacy public logs from uploads/.
 * 4. Direct HTTP access to /wp-content/uploads/wp-fastlayer-logs/performance.log returns 404 (Not Found).
 * 5. Direct HTTP access to the outside-root log directory returns 404 (Not Found).
 * 6. Logging in debug mode writes to the secure outside-root file without creating any public file.
 *
 * Run with WP-CLI:
 *     wp eval-file tests/test-log-security.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$failures = array();
$bs = \FastLayer\Bootstrap::get_instance();

// -------------------------------------------------------------------------
// 1. Test is_path_outside_webroot() helper logic
// -------------------------------------------------------------------------
$upload_dir = wp_upload_dir();
$uploads_log_path = trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-logs';
if ( \FastLayer\Bootstrap::is_path_outside_webroot( $uploads_log_path ) ) {
    $failures[] = 'is_path_outside_webroot() incorrectly returned true for uploads/wp-fastlayer-logs.';
}

$abspath = wp_normalize_path( untrailingslashit( ABSPATH ) );
$inside_abspath_path = $abspath . '/wp-content/cache/logs';
if ( \FastLayer\Bootstrap::is_path_outside_webroot( $inside_abspath_path ) ) {
    $failures[] = 'is_path_outside_webroot() incorrectly returned true for a path inside ABSPATH.';
}

$parent_abspath = dirname( $abspath );
$outside_candidate = $parent_abspath . '/fastlayer-logs';
if ( ! \FastLayer\Bootstrap::is_path_outside_webroot( $outside_candidate ) ) {
    $failures[] = 'is_path_outside_webroot() failed to identify parent directory as outside web root.';
}

// -------------------------------------------------------------------------
// 2. Test get_log_file() path resolution and fail-closed safety
// -------------------------------------------------------------------------
$log_file = $bs->get_log_file();

if ( false !== $log_file ) {
    $normalized_log = wp_normalize_path( $log_file );
    // Must be outside ABSPATH
    if ( 0 === strpos( $normalized_log, $abspath . '/' ) || $normalized_log === $abspath ) {
        $failures[] = 'get_log_file() returned a path located inside ABSPATH: ' . $log_file;
    }
    // Must be outside DOCUMENT_ROOT if available
    if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) {
        $doc_root = wp_normalize_path( untrailingslashit( $_SERVER['DOCUMENT_ROOT'] ) );
        if ( '' !== $doc_root && ( 0 === strpos( $normalized_log, $doc_root . '/' ) || $normalized_log === $doc_root ) ) {
            $failures[] = 'get_log_file() returned a path located inside DOCUMENT_ROOT: ' . $log_file;
        }
    }
}

// -------------------------------------------------------------------------
// 3. Test legacy public log cleanup and non-existence in uploads
// -------------------------------------------------------------------------
// Plant a legacy log in uploads to verify active removal
$legacy_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-logs/';
if ( ! file_exists( $legacy_dir ) ) {
    wp_mkdir_p( $legacy_dir );
}
$legacy_file = $legacy_dir . 'performance.log';
file_put_contents( $legacy_file, 'legacy public log probe' );

// Trigger cleanup via create_logs_directory() using Reflection
$ref = new ReflectionClass( $bs );
$create_method = $ref->getMethod( 'create_logs_directory' );
$create_method->setAccessible( true );
$create_method->invoke( $bs );

if ( file_exists( $legacy_file ) ) {
    $failures[] = 'create_logs_directory() failed to remove legacy public performance.log in uploads.';
}

// -------------------------------------------------------------------------
// 4. Test direct HTTP access returns HTTP 404
// -------------------------------------------------------------------------
$public_url = trailingslashit( $upload_dir['baseurl'] ) . 'wp-fastlayer-logs/performance.log';
$response = wp_remote_get( $public_url, array( 'timeout' => 5 ) );

if ( is_wp_error( $response ) ) {
    $failures[] = 'Direct HTTP request to legacy uploads log failed: ' . $response->get_error_message();
} else {
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( 404 !== $code ) {
        $failures[] = sprintf( 'Direct HTTP request to legacy uploads log returned HTTP %d (expected 404).', $code );
    }
}

// Also test direct HTTP request to the outside-root directory URL
$outside_url = home_url( '/fastlayer-logs/performance.log' );
$outside_res = wp_remote_get( $outside_url, array( 'timeout' => 5 ) );
if ( is_wp_error( $outside_res ) ) {
    $failures[] = 'Direct HTTP request to outside-root log URL failed: ' . $outside_res->get_error_message();
} else {
    $outside_code = (int) wp_remote_retrieve_response_code( $outside_res );
    if ( 404 !== $outside_code ) {
        $failures[] = sprintf( 'Direct HTTP request to outside-root log URL returned HTTP %d (expected 404).', $outside_code );
    }
}

// -------------------------------------------------------------------------
// 5. Test logging functionality in debug mode
// -------------------------------------------------------------------------
$orig_options = get_option( 'fastlayer_options', array() );
try {
    update_option( 'fastlayer_options', array_merge( is_array( $orig_options ) ? $orig_options : array(), array( 'debug_mode' => '1' ) ), false );

    // Refresh debug mode flag in Bootstrap via reflection
    $debug_prop = $ref->getProperty( 'is_debug_mode' );
    $debug_prop->setAccessible( true );
    $debug_prop->setValue( $bs, true );

    $test_entry = 'Security audit verification entry ' . time();
    $bs->log( $test_entry, 'debug' );

    // If secure logging is enabled, verify the file contains the message
    if ( false !== $log_file && file_exists( $log_file ) ) {
        $content = file_get_contents( $log_file );
        if ( false === strpos( $content, $test_entry ) ) {
            $failures[] = 'Logged message was not written to secure outside-root performance.log.';
        }
    }

    // Crucially: assert NO log file was recreated in uploads
    if ( file_exists( $legacy_file ) ) {
        $failures[] = 'A log file was erroneously created in uploads during logging.';
    }

} finally {
    if ( ! empty( $orig_options ) && is_array( $orig_options ) ) {
        update_option( 'fastlayer_options', $orig_options, false );
    } else {
        delete_option( 'fastlayer_options' );
    }
}

if ( empty( $failures ) ) {
    echo "PASS: Log security, outside-root resolution, and public access elimination verified.\n";
    exit( 0 );
}

foreach ( $failures as $failure ) {
    echo 'FAIL: ' . esc_html( $failure ) . "\n";
}

echo 'FAILED: ' . count( $failures ) . " check(s).\n";
exit( 1 );
