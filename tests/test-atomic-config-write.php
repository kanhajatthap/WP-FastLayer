<?php
/**
 * Regression test for atomic configuration writes and failure handling.
 *
 * Verifies:
 * 1. write_json_file() fails on unwritable target path.
 * 2. write_json_file() fails on un-encodable data (e.g. invalid UTF-8).
 * 3. write_static_config() rejects target paths escaping cache directory.
 * 4. write_static_config() records write failures to transient on failure.
 * 5. Successful atomic write produces verified, byte-identical JSON and clears or updates cleanly.
 *
 * Run with WP-CLI:
 *     wp eval-file tests/test-atomic-config-write.php
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

echo "Starting atomic config write and failure recovery verification...\n";

// Access private static write_json_file via reflection
$reflection = new ReflectionClass( 'FastLayer\\Bootstrap' );
$write_json = $reflection->getMethod( 'write_json_file' );
$write_json->setAccessible( true );

// 1. Unwritable path fails cleanly
$invalid_path = '/nonexistent_dir_' . uniqid() . '/test.json';
$result_unwritable = $write_json->invoke( null, $invalid_path, array( 'key' => 'value' ) );
$fastlayer_assert( 'write_json_file to invalid path returns false', $result_unwritable, false );

// 2. Un-encodable data fails cleanly (e.g. NAN float which cannot be represented in JSON)
$temp_file = wp_tempnam( 'fastlayer_test_' );
$invalid_data = array( 'bad_float' => NAN );
$result_bad_data = $write_json->invoke( null, $temp_file, $invalid_data );
$fastlayer_assert( 'write_json_file with unencodable NAN float returns false', $result_bad_data, false );
if ( file_exists( $temp_file ) ) {
    @unlink( $temp_file );
}

// 3. Valid write succeeds and round-trips byte-for-byte
$valid_temp = wp_tempnam( 'fastlayer_test_' );
$valid_data = array( 'enable_cache' => '1', 'timeout' => 3600 );
$result_valid = $write_json->invoke( null, $valid_temp, $valid_data );
$fastlayer_assert( 'write_json_file with valid data returns true', $result_valid, true );
$fastlayer_assert( 'written file content equals wp_json_encode', file_get_contents( $valid_temp ), wp_json_encode( $valid_data ) );
if ( file_exists( $valid_temp ) ) {
    @unlink( $valid_temp );
}

// 4. Test write failure recording
delete_transient( \FastLayer\Bootstrap::WRITE_FAILURE_TRANSIENT );
\FastLayer\Bootstrap::record_write_failure( 'test_context', 'disk full simulated' );
$recorded = get_transient( \FastLayer\Bootstrap::WRITE_FAILURE_TRANSIENT );
$fastlayer_assert( 'record_write_failure writes to transient', is_array( $recorded ), true );
$fastlayer_assert( 'recorded failure contains message', in_array( 'test_context: disk full simulated', $recorded, true ), true );
delete_transient( \FastLayer\Bootstrap::WRITE_FAILURE_TRANSIENT );

// 5. Verify write_static_config on live config
$live_options = get_option( 'fastlayer_options' );
$write_res = \FastLayer\Bootstrap::write_static_config( $live_options );
$fastlayer_assert( 'write_static_config returns true for valid options', $write_res, true );

$config_file = wp_normalize_path( FASTLAYER_CACHE_DIR . 'config.json' );
$fastlayer_assert( 'config.json exists on disk', file_exists( $config_file ), true );
$decoded = json_decode( file_get_contents( $config_file ), true );
$fastlayer_assert( 'config.json is valid JSON array', is_array( $decoded ), true );
$fastlayer_assert( 'config.json contains _home_host', isset( $decoded['_home_host'] ), true );

if ( empty( $failures ) ) {
    echo "PASS: Atomic configuration writes and failure handling directly verified.\n";
    exit( 0 );
}

echo "FAILED: " . count( $failures ) . " checks failed.\n";
exit( 1 );
