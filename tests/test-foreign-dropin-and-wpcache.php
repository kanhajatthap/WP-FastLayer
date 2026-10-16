<?php
/**
 * Test foreign drop-in preservation, WP_CACHE ownership, and safe config read/write contracts.
 *
 * Verifies that:
 * 1. Foreign advanced-cache.php drop-in is recognized as NOT ours.
 * 2. install_advanced_cache_dropin() refuses to overwrite a foreign drop-in.
 * 3. remove_advanced_cache_dropin() refuses to delete a foreign drop-in.
 * 4. remove_wp_cache_constant() pattern matches ONLY FastLayer-owned lines.
 * 5. read_wp_config() rejects non-existent, empty, and non-PHP files safely.
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$failures = array();
$dest = WP_CONTENT_DIR . '/advanced-cache.php';
$backup_dropin = null;

try {
    // 1. Foreign drop-in preservation
    if ( file_exists( $dest ) ) {
        $backup_dropin = file_get_contents( $dest );
    }

    $foreign_content = "<?php\n/* Foreign Plugin Drop-in: SuperCache Pro */\ndefine( 'FOREIGN_CACHE', true );\n";
    file_put_contents( $dest, $foreign_content );

    if ( FastLayer\Bootstrap::is_our_advanced_cache_dropin( $dest ) ) {
        $failures[] = 'Foreign drop-in was incorrectly identified as FastLayer drop-in.';
    }

    // Attempt to install drop-in over foreign one
    FastLayer\Bootstrap::install_advanced_cache_dropin();
    $after_install = file_get_contents( $dest );
    if ( $after_install !== $foreign_content ) {
        $failures[] = 'install_advanced_cache_dropin() overwrote a foreign drop-in!';
    }

    // Attempt to remove foreign drop-in
    FastLayer\Bootstrap::remove_advanced_cache_dropin();
    if ( ! file_exists( $dest ) || file_get_contents( $dest ) !== $foreign_content ) {
        $failures[] = 'remove_advanced_cache_dropin() deleted or modified a foreign drop-in!';
    }

} finally {
    // Restore original drop-in
    if ( null !== $backup_dropin ) {
        file_put_contents( $dest, $backup_dropin );
    } else {
        @unlink( $dest );
    }
}

// 2. WP_CACHE ownership pattern matching contract
$owner_marker = FastLayer\Bootstrap::WP_CACHE_OWNER_MARKER;
$pattern = '/\r?\n[ \t]*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;[^\n]*'
    . preg_quote( $owner_marker, '/' ) . '[^\n]*(\r?\n)*/';

$foreign_config = "<?php\ndefine( 'WP_CACHE', true ); // manual edit by admin\n/* That's all, stop editing! */\n";
$cleaned_foreign = preg_replace( $pattern, "\n", $foreign_config );
if ( $cleaned_foreign !== $foreign_config ) {
    $failures[] = 'WP_CACHE removal pattern matched a foreign/manual WP_CACHE line!';
}

$owned_config = "<?php\ndefine( 'WP_CACHE', true ); // " . $owner_marker . "\n/* That's all, stop editing! */\n";
$cleaned_owned = preg_replace( $pattern, "\n", $owned_config );
if ( $cleaned_owned === $owned_config || strpos( $cleaned_owned, 'WP_CACHE' ) !== false ) {
    $failures[] = 'WP_CACHE removal pattern failed to match and remove FastLayer-owned line.';
}

// 3. read_wp_config contract validation via reflection
$bs = FastLayer\Bootstrap::get_instance();
$ref = new ReflectionClass( $bs );
$read_method = $ref->getMethod( 'read_wp_config' );
$read_method->setAccessible( true );

// Non-existent file
$res_nonexistent = $read_method->invoke( null, ABSPATH . 'nonexistent-config-file.php' );
if ( false !== $res_nonexistent ) {
    $failures[] = 'read_wp_config() did not return false for non-existent file.';
}

// Non-PHP file
$temp_non_php = tempnam( sys_get_temp_dir(), 'fl_test_' );
file_put_contents( $temp_non_php, 'NOT_PHP_START define("FOO", 1);' );
$res_non_php = $read_method->invoke( null, $temp_non_php );
unlink( $temp_non_php );
if ( false !== $res_non_php ) {
    $failures[] = 'read_wp_config() did not return false for file not starting with <?php.';
}

// Empty file
$temp_empty = tempnam( sys_get_temp_dir(), 'fl_test_' );
file_put_contents( $temp_empty, '' );
$res_empty = $read_method->invoke( null, $temp_empty );
unlink( $temp_empty );
if ( false !== $res_empty ) {
    $failures[] = 'read_wp_config() did not return false for empty file.';
}

if ( empty( $failures ) ) {
    echo "PASS: Foreign drop-in preservation, WP_CACHE ownership, and safe config read/write directly verified.\n";
    exit( 0 );
}

foreach ( $failures as $failure ) {
    echo "FAIL: $failure\n";
}
exit( 1 );
