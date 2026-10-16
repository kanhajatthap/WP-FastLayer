<?php
/**
 * Focused regression check for the guarded output-buffer flush order.
 *
 * The page cache owns the outer buffer (template_redirect priority 1) and the
 * optimizer owns the inner one (same hook, registered second). Before 1.1.0
 * neither buffer was ever closed explicitly, so the page cache relied on being
 * the innermost open buffer at shutdown and stored nothing once the optimizer's
 * buffer was nested inside it. The fix flushes the optimizer first (shutdown
 * priority 1), then the page cache (priority 5), then saves (priority 999).
 *
 * This check emulates that stack with the real classes and asserts:
 *   - the optimizer's flush runs the minify callback so its output lands in the
 *     page-cache buffer;
 *   - the page-cache flush runs capture_output_buffer so the transformed page is
 *     retained for the cache write;
 *   - the level guard never closes a foreign buffer opened on top.
 *
 * Run against a site with WP-CLI:
 *
 *     wp eval-file tests/test-buffer-flush.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$fastlayer_buffer_failures = array();

if ( ! class_exists( 'FastLayer\\Page_Cache' ) || ! class_exists( 'FastLayer\\Optimizer' ) ) {
    echo "FAIL: Page_Cache or Optimizer class is not loaded.\n";
    exit( 1 );
}

$fastlayer_set_private = function ( $object, $property, $value ) {
    $reflection = new ReflectionObject( $object );
    while ( $reflection && ! $reflection->hasProperty( $property ) ) {
        $reflection = $reflection->getParentClass();
    }
    if ( ! $reflection ) {
        return false;
    }
    $prop = $reflection->getProperty( $property );
    $prop->setAccessible( true );
    $prop->setValue( $object, $value );
    return true;
};

$fastlayer_get_private = function ( $object, $property ) {
    $reflection = new ReflectionObject( $object );
    while ( $reflection && ! $reflection->hasProperty( $property ) ) {
        $reflection = $reflection->getParentClass();
    }
    if ( ! $reflection ) {
        return null;
    }
    $prop = $reflection->getProperty( $property );
    $prop->setAccessible( true );
    return $prop->getValue( $object );
};

$fastlayer_page = \FastLayer\Page_Cache::get_instance();
$fastlayer_opt  = \FastLayer\Optimizer::get_instance();

$fastlayer_baseline_level = ob_get_level();

// Outer (page cache) buffer.
ob_start( array( $fastlayer_page, 'capture_output_buffer' ) );
$fastlayer_set_private( $fastlayer_page, 'buffer_started', true );
$fastlayer_set_private( $fastlayer_page, 'buffer_level', ob_get_level() );

// Inner (optimizer) buffer.
ob_start( array( $fastlayer_opt, 'minify_html' ) );
$fastlayer_set_private( $fastlayer_opt, 'buffer_level', ob_get_level() );

echo "  <p>   Hello   <b>World</b>   </p>  ";

// Shutdown order under test: optimizer first, page cache second.
$fastlayer_opt->flush_html_buffer();
$fastlayer_page->flush_output_buffer();

if ( ob_get_level() !== $fastlayer_baseline_level ) {
    $fastlayer_buffer_failures[] = 'the buffers were not fully closed (level ' . ob_get_level() . ').';
}

$fastlayer_captured = (string) $fastlayer_get_private( $fastlayer_page, 'captured_buffer_content' );
if ( '' === $fastlayer_captured ) {
    $fastlayer_buffer_failures[] = 'capture_output_buffer did not retain the page content.';
} elseif ( false === strpos( $fastlayer_captured, 'Hello' ) || false === strpos( $fastlayer_captured, 'World' ) ) {
    $fastlayer_buffer_failures[] = 'the retained content is missing the rendered page body.';
}

// The guard must not close a foreign buffer opened on top of ours.
ob_start(); // Foreign buffer.
$fastlayer_foreign_level = ob_get_level();
$fastlayer_opt->flush_html_buffer();
$fastlayer_page->flush_output_buffer();
if ( ob_get_level() !== $fastlayer_foreign_level ) {
    $fastlayer_buffer_failures[] = 'the guard closed a foreign buffer opened on top.';
}
ob_end_clean();

if ( empty( $fastlayer_buffer_failures ) ) {
    echo "PASS: the guarded flush runs the callbacks in order and never closes a foreign buffer.\n";
    exit( 0 );
}

foreach ( $fastlayer_buffer_failures as $fastlayer_buffer_failure ) {
    echo 'FAIL: ' . esc_html( $fastlayer_buffer_failure ) . "\n";
}

echo 'FAILED: ' . count( $fastlayer_buffer_failures ) . " check(s).\n";
exit( 1 );
