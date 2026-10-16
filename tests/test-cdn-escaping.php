<?php
/**
 * Focused regression test for CDN / ImageKit output escaping and sanitization.
 *
 * Verifies that FastLayer\CDN::rewrite_html_content_images():
 * 1. Rewrites local image src attributes to ImageKit with correct quotes and attribute safety.
 * 2. Properly escapes quotes/characters via esc_attr() to prevent HTML attribute injection breakout.
 * 3. Correctly handles and escapes individual srcset candidates.
 * 4. Rewrites CSS background images and strips dangerous delimiter tokens via sanitize_css_url().
 *
 * Run against a site with WP-CLI:
 *     wp eval-file tests/test-cdn-escaping.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$cdn_escaping_failures = array();

if ( ! class_exists( 'FastLayer\\CDN' ) ) {
    echo "FAIL: FastLayer\\CDN class is not loadable.\n";
    exit( 1 );
}

$cdn = \FastLayer\CDN::get_instance();

// Backup original options
$original_options = get_option( 'fastlayer_options', array() );

$uploads     = wp_upload_dir();
$uploads_url = untrailingslashit( $uploads['baseurl'] );

try {
    // Configure ImageKit CDN for testing
    $test_endpoint = 'https://ik.imagekit.io/fastlayertest';
    $test_options  = array_merge(
        is_array( $original_options ) ? $original_options : array(),
        array(
            'enable_imagekit_cdn'         => '1',
            'imagekit_endpoint'           => $test_endpoint,
            'imagekit_origin_path_mode'   => 'full_uploads_path',
        )
    );
    update_option( 'fastlayer_options', $test_options, false );

    // ---------------------------------------------------------------------
    // Test 1: Standard image src rewriting with attribute integrity and quoting
    // ---------------------------------------------------------------------
    $input_1  = '<img src="' . $uploads_url . '/2026/10/hero.jpg" alt="Hero Image" width="800" height="600">';
    $output_1 = $cdn->rewrite_html_content_images( $input_1 );

    if ( false === strpos( $output_1, $test_endpoint ) ) {
        $cdn_escaping_failures[] = 'Test 1: ImageKit endpoint was not applied to local image src.';
    }
    if ( false === strpos( $output_1, 'alt="Hero Image"' ) ) {
        $cdn_escaping_failures[] = 'Test 1: Sibling alt attribute was corrupted or missing.';
    }
    // Check that src attribute is correctly enclosed in quotes and includes transformation
    if ( ! preg_match( '#src="https://ik\.imagekit\.io/fastlayertest/[^"]+\?tr=[^"]+"#', $output_1 ) ) {
        $cdn_escaping_failures[] = 'Test 1: Rewritten src is missing expected transformation parameter or attribute quoting.';
    }
    // Check that tag syntax remains well-formed
    if ( ! preg_match( '#^<img\s+[^>]+>$#', trim( $output_1 ) ) ) {
        $cdn_escaping_failures[] = 'Test 1: Rewritten img tag is not properly formed.';
    }

    // ---------------------------------------------------------------------
    // Test 2: Malicious attribute breakout injection in image URL
    // ---------------------------------------------------------------------
    // An attacker-controlled URL or query string containing double quotes intended
    // to break out of src="..." and inject an event handler.
    $malicious_url = $uploads_url . '/2026/10/pic.jpg?x="onload="alert(1)"';
    $input_2       = '<img src="' . esc_attr( $malicious_url ) . '" alt="Test">';
    $output_2      = $cdn->rewrite_html_content_images( $input_2 );

    // Assert that raw double quotes are NOT present in the rewritten attribute value
    // and that no separate unquoted onload attribute was created.
    if ( preg_match( '#<img[^>]+src="[^"]*"\s+onload=#i', $output_2 ) ) {
        $cdn_escaping_failures[] = 'Test 2: Attribute injection breakout succeeded; unquoted onload attribute was created.';
    }
    // Verify that the rewritten img tag maintains exact single-attribute boundary
    if ( preg_match_all( '#\bonload\s*=#i', $output_2, $onload_matches ) && count( $onload_matches[0] ) > 0 ) {
        $cdn_escaping_failures[] = 'Test 2: Raw onload attribute handler found outside URL query string.';
    }
    // Verify the output tag remains valid HTML
    if ( ! preg_match( '#^<img\s+[^>]+>$#', trim( $output_2 ) ) ) {
        $cdn_escaping_failures[] = 'Test 2: Tag boundary was broken by quote injection.';
    }

    // ---------------------------------------------------------------------
    // Test 3: srcset candidate handling and attribute escaping
    // ---------------------------------------------------------------------
    $srcset_input = $uploads_url . '/2026/10/pic-300.jpg 300w, ' . $uploads_url . '/2026/10/pic-800.jpg 800w';
    $input_3      = '<img srcset="' . $srcset_input . '" src="' . $uploads_url . '/2026/10/pic-800.jpg" alt="Srcset Test">';
    $output_3     = $cdn->rewrite_html_content_images( $input_3 );

    if ( false === strpos( $output_3, '300w' ) || false === strpos( $output_3, '800w' ) ) {
        $cdn_escaping_failures[] = 'Test 3: srcset width descriptors were not preserved.';
    }
    // Both candidates in srcset + the 1 src must be rewritten to ImageKit (total 3)
    if ( substr_count( $output_3, $test_endpoint ) < 3 ) {
        $cdn_escaping_failures[] = 'Test 3: Expected 3 ImageKit URLs (2 in srcset, 1 in src), found fewer.';
    }
    // Confirm srcset attribute remains properly enclosed
    if ( ! preg_match( '#srcset="[^"]+"#', $output_3 ) ) {
        $cdn_escaping_failures[] = 'Test 3: srcset attribute was not properly quoted.';
    }

    // ---------------------------------------------------------------------
    // Test 4: Inline CSS background-image url(...) rewriting and sanitization
    // ---------------------------------------------------------------------
    $bg_input  = '<div style="background-image: url(\'' . $uploads_url . '/2026/10/bg.jpg\'); color: blue;">Header</div>';
    $bg_output = $cdn->rewrite_html_content_images( $bg_input );

    if ( false === strpos( $bg_output, $test_endpoint ) ) {
        $cdn_escaping_failures[] = 'Test 4: Background image in style attribute was not rewritten to ImageKit.';
    }
    if ( false === strpos( $bg_output, "url('" . $test_endpoint ) ) {
        $cdn_escaping_failures[] = 'Test 4: CSS url() quoting structure was altered or corrupted.';
    }

    // Direct verification of sanitize_css_url() with dangerous breakout tokens:
    // parenthesis ')', semicolon ';', quotes, angle brackets '<', '>'
    $ref = new ReflectionClass( $cdn );
    $sanitize_method = $ref->getMethod( 'sanitize_css_url' );
    $sanitize_method->setAccessible( true );

    $dangerous_payload = 'https://ik.imagekit.io/test/image.jpg?foo=bar);background:red;/*&x="><script>';
    $sanitized = $sanitize_method->invoke( $cdn, $dangerous_payload );

    if ( false !== strpos( $sanitized, ')' ) ) {
        $cdn_escaping_failures[] = 'Test 4: sanitize_css_url failed to strip closing parenthesis.")".';
    }
    if ( false !== strpos( $sanitized, '"' ) || false !== strpos( $sanitized, "'" ) ) {
        $cdn_escaping_failures[] = 'Test 4: sanitize_css_url failed to strip quotation marks.';
    }
    if ( false !== strpos( $sanitized, '<' ) || false !== strpos( $sanitized, '>' ) ) {
        $cdn_escaping_failures[] = 'Test 4: sanitize_css_url failed to strip angle brackets.';
    }

} finally {
    // Restore original options
    if ( ! empty( $original_options ) && is_array( $original_options ) ) {
        update_option( 'fastlayer_options', $original_options, false );
    } else {
        delete_option( 'fastlayer_options' );
    }
}

if ( empty( $cdn_escaping_failures ) ) {
    echo "PASS: CDN output escaping, attribute quoting, srcset handling, and CSS sanitization verified.\n";
    exit( 0 );
}

foreach ( $cdn_escaping_failures as $failure ) {
    echo 'FAIL: ' . esc_html( $failure ) . "\n";
}

echo 'FAILED: ' . count( $cdn_escaping_failures ) . " check(s).\n";
exit( 1 );
