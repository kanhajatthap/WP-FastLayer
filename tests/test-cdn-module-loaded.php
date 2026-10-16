<?php
/**
 * Focused regression check that the CDN module is actually booted.
 *
 * Bootstrap::load_modules() enumerates the feature classes that WordPress must
 * instantiate on every request. FastLayer\CDN registers all of its ImageKit /
 * CDN / Smart-LCP hooks from its constructor, so if the class name is missing
 * from that list the entire CDN feature is silently inert: the settings screen
 * still renders, options still save, but no URL rewriting or LCP preload ever
 * runs on the front end.
 *
 * This check fails against the pre-fix body (CDN absent from the module list)
 * and passes once CDN is loaded, by asserting that:
 *   - the FastLayer\CDN singleton has been created by the bootstrap, and
 *   - its constructor-registered hooks are actually attached.
 *
 * Run against a site with WP-CLI:
 *
 *     wp eval-file tests/test-cdn-module-loaded.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$fastlayer_cdn_failures = array();

if ( ! class_exists( 'FastLayer\\CDN' ) ) {
    echo "FAIL: FastLayer\\CDN class is not loadable.\n";
    exit( 1 );
}

// 1. The bootstrap must have instantiated the class on this request.
$fastlayer_cdn_reflection = new ReflectionClass( 'FastLayer\\CDN' );
if ( ! $fastlayer_cdn_reflection->hasProperty( 'instance' ) ) {
    echo "FAIL: FastLayer\\CDN has no singleton instance property.\n";
    exit( 1 );
}

$fastlayer_cdn_instance_prop = $fastlayer_cdn_reflection->getProperty( 'instance' );
$fastlayer_cdn_instance_prop->setAccessible( true );
if ( null === $fastlayer_cdn_instance_prop->getValue() ) {
    $fastlayer_cdn_failures[] = 'FastLayer\\CDN was never instantiated by Bootstrap::load_modules().';
}

// 2. Its constructor-registered filters must be attached to real hooks.
$fastlayer_cdn_hooks = array(
    'wp_get_attachment_url',
    'wp_get_attachment_image_src',
    'wp_calculate_image_srcset',
    'wp_get_attachment_image_attributes',
    'the_content',
);

foreach ( $fastlayer_cdn_hooks as $fastlayer_cdn_hook ) {
    $fastlayer_cdn_found = false;

    if ( isset( $GLOBALS['wp_filter'][ $fastlayer_cdn_hook ] ) ) {
        foreach ( $GLOBALS['wp_filter'][ $fastlayer_cdn_hook ]->callbacks as $fastlayer_cdn_callbacks ) {
            foreach ( $fastlayer_cdn_callbacks as $fastlayer_cdn_callback ) {
                if (
                    isset( $fastlayer_cdn_callback['function'] )
                    && is_array( $fastlayer_cdn_callback['function'] )
                    && isset( $fastlayer_cdn_callback['function'][0] )
                    && is_object( $fastlayer_cdn_callback['function'][0] )
                    && $fastlayer_cdn_callback['function'][0] instanceof \FastLayer\CDN
                ) {
                    $fastlayer_cdn_found = true;
                    break 2;
                }
            }
        }
    }

    if ( ! $fastlayer_cdn_found ) {
        $fastlayer_cdn_failures[] = 'no FastLayer\\CDN callback attached to ' . $fastlayer_cdn_hook . '.';
    }
}

if ( empty( $fastlayer_cdn_failures ) ) {
    echo "PASS: FastLayer\\CDN is instantiated and its front-end hooks are attached.\n";
    exit( 0 );
}

foreach ( $fastlayer_cdn_failures as $fastlayer_cdn_failure ) {
    echo 'FAIL: ' . esc_html( $fastlayer_cdn_failure ) . "\n";
}

echo 'FAILED: ' . count( $fastlayer_cdn_failures ) . " check(s).\n";
exit( 1 );
