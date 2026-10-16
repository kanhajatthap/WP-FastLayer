<?php
/**
 * Focused regression check for option type-handling in the feature readers.
 *
 * FastLayer stores every toggle as the string '1' or '0'. The readers must not
 * rely on the stored value already being a string: a value that arrives as the
 * integer 1 (for example from `wp option patch update`, an importer, or a future
 * migration) must still read as enabled. A strict `'1' === $options['x']`
 * comparison without a `(string)` cast silently turns the feature off.
 *
 * Each case below writes the integer 1 into the option row and asserts the
 * reader reports enabled, then writes '0' and asserts it reports disabled. The
 * options row is restored to its original value afterwards.
 *
 * Run against a site with WP-CLI:
 *
 *     wp eval-file tests/test-option-type-strictness.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$fastlayer_type_failures = array();
$fastlayer_original      = get_option( 'fastlayer_options', array() );

/**
 * class, reader method, option key, companion options needed by the reader.
 */
$fastlayer_type_cases = array(
    array( 'FastLayer\\Webp', 'is_enabled', 'enable_webp', array() ),
    array( 'FastLayer\\Webp', 'is_image_delivery_enabled', 'enable_image_delivery', array() ),
    array( 'FastLayer\\Page_Cache', 'is_cache_enabled', 'enable_cache', array() ),
    array( 'FastLayer\\Page_Cache', 'is_preload_enabled', 'enable_preload', array() ),
    array( 'FastLayer\\Bootstrap', 'is_debug_enabled', 'debug_mode', array() ),
    array( 'FastLayer\\Optimizer', 'is_css_enabled', 'enable_minify_css', array() ),
    array( 'FastLayer\\Optimizer', 'is_js_enabled', 'enable_minify_js', array() ),
    array( 'FastLayer\\Optimizer', 'is_defer_enabled', 'enable_js_defer', array() ),
    array( 'FastLayer\\Optimizer', 'is_css_combine_enabled', 'enable_css_combine', array() ),
    array( 'FastLayer\\Optimizer', 'is_google_fonts_display_enabled', 'enable_google_fonts_display', array() ),
    array( 'FastLayer\\Optimizer', 'is_css_inline_enabled', 'enable_css_inline', array() ),
    array( 'FastLayer\\Optimizer', 'is_critical_css_enabled', 'enable_critical_css', array() ),
    array( 'FastLayer\\Optimizer', 'is_html_minify_enabled', 'enable_minify', array() ),
    array( 'FastLayer\\Optimizer', 'is_lazyload_enabled', 'enable_lazyload', array() ),
    array( 'FastLayer\\CDN', 'is_enabled', 'enable_cdn', array( 'cdn_cname' => 'cdn.example.com' ) ),
);

try {
    foreach ( $fastlayer_type_cases as $fastlayer_type_case ) {
        list( $fastlayer_class, $fastlayer_method, $fastlayer_key, $fastlayer_extra ) = $fastlayer_type_case;

        if ( ! class_exists( $fastlayer_class ) ) {
            $fastlayer_type_failures[] = $fastlayer_class . ' is not loadable.';
            continue;
        }

        $fastlayer_instance = call_user_func( array( $fastlayer_class, 'get_instance' ) );
        $fastlayer_reader   = new ReflectionMethod( $fastlayer_class, $fastlayer_method );
        $fastlayer_reader->setAccessible( true );

        // Integer 1 must read as enabled.
        $fastlayer_options            = get_option( 'fastlayer_options', array() );
        $fastlayer_options[ $fastlayer_key ] = 1;
        foreach ( $fastlayer_extra as $fastlayer_extra_key => $fastlayer_extra_value ) {
            $fastlayer_options[ $fastlayer_extra_key ] = $fastlayer_extra_value;
        }
        update_option( 'fastlayer_options', $fastlayer_options );

        if ( true !== $fastlayer_reader->invoke( $fastlayer_instance ) ) {
            $fastlayer_type_failures[] = $fastlayer_class . '::' . $fastlayer_method . '() reads int 1 as disabled (option ' . $fastlayer_key . ').';
        }

        // String '0' must read as disabled.
        $fastlayer_options[ $fastlayer_key ] = '0';
        update_option( 'fastlayer_options', $fastlayer_options );

        if ( false !== $fastlayer_reader->invoke( $fastlayer_instance ) ) {
            $fastlayer_type_failures[] = $fastlayer_class . '::' . $fastlayer_method . '() reads \'0\' as enabled (option ' . $fastlayer_key . ').';
        }
    }
} finally {
    update_option( 'fastlayer_options', $fastlayer_original );
}

if ( empty( $fastlayer_type_failures ) ) {
    echo "PASS: every toggle reader accepts both the string and integer form of '1'.\n";
    exit( 0 );
}

foreach ( $fastlayer_type_failures as $fastlayer_type_failure ) {
    echo 'FAIL: ' . esc_html( $fastlayer_type_failure ) . "\n";
}

echo 'FAILED: ' . count( $fastlayer_type_failures ) . " check(s).\n";
exit( 1 );
