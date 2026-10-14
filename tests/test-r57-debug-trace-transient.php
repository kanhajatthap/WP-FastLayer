<?php
/**
 * Focused regression check for the FastLayer page-cache debug trace (R-57).
 *
 * Verifies that Page_Cache::persist_debug_trace() stores the request trace in
 * the `fastlayer_cache_debug_trace` transient, that the value can be read back
 * with get_transient(), and that the 300-second expiration is applied.
 *
 * Page caching does not need to be enabled for this check; it drives the
 * private method directly with a sentinel trace and then removes the transient
 * again.
 *
 * Run against a site with WP-CLI:
 *
 *     wp eval-file tests/test-r57-debug-trace-transient.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$fastlayer_r57_transient = 'fastlayer_cache_debug_trace';
$fastlayer_r57_failures  = array();

$fastlayer_r57_sentinel = array(
    'cache_init'         => true,
    'template_redirect'  => true,
    'buffer_started'     => false,
    'shutdown_triggered' => false,
    'save_cache_started' => false,
    'cache_file'         => 'C:/tmp/r57-sentinel.html',
    'cache_dir'          => 'C:/tmp',
    'skip_reason'        => 'r57-sentinel-skip',
    'save_result'        => 'wrote_cache',
    'last_url'           => 'http://example.test/r57-sentinel',
    'last_hook'          => 'r57-sentinel-hook',
);

delete_transient( $fastlayer_r57_transient );

$fastlayer_r57_instance = \FastLayer\Page_Cache::get_instance();
$fastlayer_r57_reflect  = new ReflectionClass( $fastlayer_r57_instance );

$fastlayer_r57_prop = $fastlayer_r57_reflect->getProperty( 'debug_trace' );
$fastlayer_r57_prop->setAccessible( true );
$fastlayer_r57_prop->setValue( $fastlayer_r57_instance, $fastlayer_r57_sentinel );

$fastlayer_r57_method = $fastlayer_r57_reflect->getMethod( 'persist_debug_trace' );
$fastlayer_r57_method->setAccessible( true );
$fastlayer_r57_method->invoke( $fastlayer_r57_instance );

$fastlayer_r57_stored = get_transient( $fastlayer_r57_transient );

if ( ! is_array( $fastlayer_r57_stored ) ) {
    $fastlayer_r57_failures[] = 'get_transient() did not return the stored trace array.';
} else {
    foreach ( $fastlayer_r57_sentinel as $fastlayer_r57_key => $fastlayer_r57_value ) {
        if ( ! array_key_exists( $fastlayer_r57_key, $fastlayer_r57_stored ) || $fastlayer_r57_stored[ $fastlayer_r57_key ] !== $fastlayer_r57_value ) {
            $fastlayer_r57_failures[] = 'Stored field mismatch: ' . $fastlayer_r57_key;
        }
    }

    if ( empty( $fastlayer_r57_stored['updated_at'] ) || ! is_int( $fastlayer_r57_stored['updated_at'] ) ) {
        $fastlayer_r57_failures[] = 'Stored trace has no integer updated_at.';
    }
}

$fastlayer_r57_timeout = get_option( '_transient_timeout_' . $fastlayer_r57_transient );

if ( false === $fastlayer_r57_timeout ) {
    $fastlayer_r57_failures[] = 'Expiration timeout option is missing.';
} elseif ( abs( (int) $fastlayer_r57_timeout - ( time() + 300 ) ) > 5 ) {
    $fastlayer_r57_failures[] = 'Expiration is not ~300 seconds ahead of now.';
}

delete_transient( $fastlayer_r57_transient );

if ( empty( $fastlayer_r57_failures ) ) {
    echo "PASS: R-57 debug trace is stored, retrievable and expires in ~300s.\n";
    exit( 0 );
}

foreach ( $fastlayer_r57_failures as $fastlayer_r57_failure ) {
    echo 'FAIL: ' . esc_html( $fastlayer_r57_failure ) . "\n";
}

echo 'FAILED: ' . count( $fastlayer_r57_failures ) . " check(s).\n";
exit( 1 );
