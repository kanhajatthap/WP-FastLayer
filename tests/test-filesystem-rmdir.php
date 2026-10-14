<?php
/**
 * Focused regression check for FastLayer's WP_Filesystem-backed directory
 * removal (AlternativeFunctions round 7).
 *
 * Verifies that Bootstrap::delete_empty_directory():
 *   - removes an empty directory,
 *   - refuses a non-directory path,
 *   - refuses a non-empty directory (rmdir() semantics are preserved).
 *
 * Run against a site with WP-CLI:
 *
 *     wp eval-file tests/test-filesystem-rmdir.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$fastlayer_fs_failures = array();

if ( ! class_exists( 'FastLayer\\Bootstrap' ) ) {
    echo "FAIL: FastLayer\\Bootstrap class is not loaded.\n";
    exit( 1 );
}

$fastlayer_fs_upload = wp_upload_dir();
$fastlayer_fs_base   = trailingslashit( $fastlayer_fs_upload['basedir'] ) . 'fastlayer-fs-test-' . wp_generate_password( 8, false, false ) . '/';
$fastlayer_fs_empty  = $fastlayer_fs_base . 'empty/';
$fastlayer_fs_full   = $fastlayer_fs_base . 'full/';
$fastlayer_fs_source = $fastlayer_fs_base . 'plain.txt';

wp_mkdir_p( $fastlayer_fs_empty );
wp_mkdir_p( $fastlayer_fs_full );
copy( __FILE__, $fastlayer_fs_full . 'keep.txt' );
copy( __FILE__, $fastlayer_fs_source );

// 1. An empty directory is removed.
$fastlayer_fs_result = \FastLayer\Bootstrap::delete_empty_directory( $fastlayer_fs_empty );
if ( true !== $fastlayer_fs_result ) {
    $fastlayer_fs_failures[] = 'delete_empty_directory() did not report success for an empty directory.';
}
if ( is_dir( $fastlayer_fs_empty ) ) {
    $fastlayer_fs_failures[] = 'The empty directory still exists after delete_empty_directory().';
}

// 2. A non-empty directory is refused, matching rmdir() semantics.
$fastlayer_fs_result = \FastLayer\Bootstrap::delete_empty_directory( $fastlayer_fs_full );
if ( false !== $fastlayer_fs_result ) {
    $fastlayer_fs_failures[] = 'delete_empty_directory() reported success for a non-empty directory.';
}
if ( ! is_dir( $fastlayer_fs_full ) ) {
    $fastlayer_fs_failures[] = 'The non-empty directory was removed; rmdir() semantics were not preserved.';
}

// 3. A file path is refused.
$fastlayer_fs_result = \FastLayer\Bootstrap::delete_empty_directory( $fastlayer_fs_source );
if ( false !== $fastlayer_fs_result ) {
    $fastlayer_fs_failures[] = 'delete_empty_directory() reported success for a file path.';
}
if ( ! file_exists( $fastlayer_fs_source ) ) {
    $fastlayer_fs_failures[] = 'The file was removed by delete_empty_directory().';
}

// Clean up the fixtures using the same helper.
if ( file_exists( $fastlayer_fs_full . 'keep.txt' ) ) {
    wp_delete_file( $fastlayer_fs_full . 'keep.txt' );
}
\FastLayer\Bootstrap::delete_empty_directory( $fastlayer_fs_full );
if ( file_exists( $fastlayer_fs_source ) ) {
    wp_delete_file( $fastlayer_fs_source );
}
\FastLayer\Bootstrap::delete_empty_directory( $fastlayer_fs_base );

if ( empty( $fastlayer_fs_failures ) ) {
    echo "PASS: delete_empty_directory() removes empty directories and preserves rmdir() refusal semantics.\n";
    exit( 0 );
}

foreach ( $fastlayer_fs_failures as $fastlayer_fs_failure ) {
    echo 'FAIL: ' . esc_html( $fastlayer_fs_failure ) . "\n";
}

echo 'FAILED: ' . count( $fastlayer_fs_failures ) . " check(s).\n";
exit( 1 );
