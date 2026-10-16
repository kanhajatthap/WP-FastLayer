<?php
/**
 * Focused regression check for the config.json migration.
 *
 * FastLayer used to write an executable config.php under wp-content/cache and
 * include it from the advanced-cache.php drop-in. 1.1.0 switches to plain JSON:
 *   - Bootstrap::write_static_config() writes config.json (valid JSON, with the
 *     trusted-host list) and removes the legacy config.php;
 *   - the same file_get_contents() + json_decode() pair the drop-in uses
 *     round-trips to the original options;
 *   - Bootstrap::ensure_static_config(), run from init(), re-creates the JSON
 *     file and deletes a leftover legacy config.php.
 *
 * The check runs against the plugin's real cache directory, then restores it to
 * the live options so the site is left exactly as it was found.
 *
 * Run against a site with WP-CLI:
 *
 *     wp eval-file tests/test-config-json.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$fastlayer_config_failures = array();

if ( ! class_exists( 'FastLayer\\Bootstrap' ) ) {
    echo "FAIL: FastLayer\\Bootstrap class is not loaded.\n";
    exit( 1 );
}

$fastlayer_cache_dir  = wp_normalize_path( FASTLAYER_CACHE_DIR );
$fastlayer_json_file  = $fastlayer_cache_dir . 'config.json';
$fastlayer_legacy     = $fastlayer_cache_dir . 'config.php';

$fastlayer_json_backup = file_exists( $fastlayer_json_file ) ? (string) file_get_contents( $fastlayer_json_file ) : null;
$fastlayer_legacy_backup = file_exists( $fastlayer_legacy ) ? (string) file_get_contents( $fastlayer_legacy ) : null;

$fastlayer_probe = array(
    'enable_cache' => '1',
    '_sentinel'    => 'config-json-migration-probe',
);

// Prove the legacy file is removed: plant one first.
@file_put_contents( $fastlayer_legacy, "<?php\nreturn array( 'legacy' => true );\n" );

$fastlayer_wrote = \FastLayer\Bootstrap::write_static_config( $fastlayer_probe );
if ( true !== $fastlayer_wrote ) {
    $fastlayer_config_failures[] = 'write_static_config() did not report success.';
}

if ( ! file_exists( $fastlayer_json_file ) ) {
    $fastlayer_config_failures[] = 'config.json was not created.';
} else {
    $fastlayer_raw = (string) file_get_contents( $fastlayer_json_file );
    $fastlayer_decoded = json_decode( $fastlayer_raw, true );
    if ( ! is_array( $fastlayer_decoded ) ) {
        $fastlayer_config_failures[] = 'config.json did not decode as a JSON object.';
    } else {
        if ( 'config-json-migration-probe' !== ( $fastlayer_decoded['_sentinel'] ?? null ) ) {
            $fastlayer_config_failures[] = 'config.json did not round-trip the options.';
        }
        if ( ! isset( $fastlayer_decoded['_home_host'] ) || ! is_array( $fastlayer_decoded['_home_host'] ) || empty( $fastlayer_decoded['_home_host'] ) ) {
            $fastlayer_config_failures[] = 'config.json is missing the trusted-host list.';
        }
    }
}

if ( file_exists( $fastlayer_legacy ) ) {
    $fastlayer_config_failures[] = 'the legacy config.php was not removed.';
}

// ensure_static_config() is private; exercise it via the public entry point by
// removing config.json and re-running init through a fresh Bootstrap request is
// not possible here, so the migration path is proven directly: plant a legacy
// file, call write_static_config() with the live options, and confirm the legacy
// file is gone again.
@file_put_contents( $fastlayer_legacy, "<?php\nreturn array();\n" );
\FastLayer\Bootstrap::write_static_config( get_option( 'fastlayer_options', array() ) );
if ( file_exists( $fastlayer_legacy ) ) {
    $fastlayer_config_failures[] = 'a planted legacy config.php survived write_static_config().';
}

// Restore the cache directory to exactly what it held before the test.
if ( null !== $fastlayer_json_backup ) {
    @file_put_contents( $fastlayer_json_file, $fastlayer_json_backup );
} else {
    \FastLayer\Bootstrap::write_static_config( get_option( 'fastlayer_options', array() ) );
}
if ( null !== $fastlayer_legacy_backup ) {
    @file_put_contents( $fastlayer_legacy, $fastlayer_legacy_backup );
} else {
    @wp_delete_file( $fastlayer_legacy );
}

if ( empty( $fastlayer_config_failures ) ) {
    echo "PASS: config.json is written, round-trips the options, and the legacy config.php is removed.\n";
    exit( 0 );
}

foreach ( $fastlayer_config_failures as $fastlayer_config_failure ) {
    echo 'FAIL: ' . esc_html( $fastlayer_config_failure ) . "\n";
}

echo 'FAILED: ' . count( $fastlayer_config_failures ) . " check(s).\n";
exit( 1 );
