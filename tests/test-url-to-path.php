<?php
/**
 * Focused regression check for the shared Bootstrap::url_to_path() helper.
 *
 * Before 1.1.0 each module had its own URL -> path conversion and they
 * disagreed: Admin and Optimizer assumed site_url() equaled ABSPATH (which
 * breaks subdirectory installs and custom content directories) and neither
 * rejected "..", while Webp mapped any uploads-looking path to the uploads
 * directory regardless of the host. The single helper must:
 *   - map content, includes, admin, plugins and uploads URLs to the right
 *     directory via the longest matching base URL;
 *   - strip the query string and fragment before touching the filesystem;
 *   - reject directory traversal and NUL bytes;
 *   - reject a host that is not one of this site's hosts;
 *   - still resolve a host-less, site-relative path against ABSPATH.
 *
 * Run against a site with WP-CLI:
 *
 *     wp eval-file tests/test-url-to-path.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$fastlayer_url_path_failures = array();

if ( ! class_exists( 'FastLayer\\Bootstrap' ) ) {
    echo "FAIL: FastLayer\\Bootstrap class is not loaded.\n";
    exit( 1 );
}

$fastlayer_assert = function ( $label, $actual, $expected ) use ( &$fastlayer_url_path_failures ) {
    if ( $actual !== $expected ) {
        $fastlayer_url_path_failures[] = $label . ' (expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) . ')';
    }
};

// 1. Uploads URL -> uploads basedir, with the query string dropped.
$fastlayer_upload = wp_upload_dir();
$fastlayer_assert(
    'uploads URL maps to basedir',
    \FastLayer\Bootstrap::url_to_path( $fastlayer_upload['baseurl'] . '/2024/01/photo.jpg?ver=2#frag' ),
    wp_normalize_path( trailingslashit( $fastlayer_upload['basedir'] ) . '2024/01/photo.jpg' )
);

// 2. Plugins URL -> WP_PLUGIN_DIR.
$fastlayer_assert(
    'plugins URL maps to WP_PLUGIN_DIR',
    \FastLayer\Bootstrap::url_to_path( plugins_url( 'fastlayer/wp-fastlayer.php' ) ),
    wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . 'fastlayer/wp-fastlayer.php' )
);

// 3. Includes URL -> ABSPATH . WPINC.
$fastlayer_assert(
    'includes URL maps to wp-includes',
    \FastLayer\Bootstrap::url_to_path( includes_url( 'js/jquery/jquery.min.js' ) ),
    wp_normalize_path( trailingslashit( ABSPATH . WPINC ) . 'js/jquery/jquery.min.js' )
);

// 4. Content URL outside the uploads directory -> WP_CONTENT_DIR.
$fastlayer_assert(
    'content URL maps to WP_CONTENT_DIR',
    \FastLayer\Bootstrap::url_to_path( content_url( 'fastlayer-probe.txt' ) ),
    wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'fastlayer-probe.txt' )
);

// 5. Site root URL -> ABSPATH (works for subdirectory installs too).
$fastlayer_assert(
    'site root URL maps to ABSPATH',
    \FastLayer\Bootstrap::url_to_path( home_url( '/some-page/' ) ),
    wp_normalize_path( ABSPATH . 'some-page/' )
);

// 6. Host-less, site-relative path -> ABSPATH.
$fastlayer_assert(
    'relative URL maps to ABSPATH',
    \FastLayer\Bootstrap::url_to_path( '/wp-includes/js/relative.js' ),
    wp_normalize_path( ABSPATH . 'wp-includes/js/relative.js' )
);

// 7. Directory traversal is rejected, with or without a host.
$fastlayer_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
$fastlayer_assert(
    'traversal in a relative path is rejected',
    \FastLayer\Bootstrap::url_to_path( '/wp-content/../../wp-config.php' ),
    false
);
$fastlayer_assert(
    'traversal in a full URL is rejected',
    \FastLayer\Bootstrap::url_to_path( 'http://' . $fastlayer_host . '/wp-content/uploads/../../../wp-config.php' ),
    false
);

// 8. A foreign host is never mapped to a local file.
$fastlayer_assert(
    'external host is rejected',
    \FastLayer\Bootstrap::url_to_path( 'http://evil.example.com/wp-content/uploads/photo.jpg' ),
    false
);

// 8b. URL with a custom port on internal host is properly accepted and mapped.
$fastlayer_assert(
    'URL with internal host and port maps correctly',
    \FastLayer\Bootstrap::url_to_path( 'http://' . $fastlayer_host . ':8080/wp-content/uploads/2024/01/photo.jpg' ),
    wp_normalize_path( trailingslashit( $fastlayer_upload['basedir'] ) . '2024/01/photo.jpg' )
);

// 8c. URL with foreign host and port is rejected.
$fastlayer_assert(
    'URL with foreign host and port is rejected',
    \FastLayer\Bootstrap::url_to_path( 'http://evil.example.com:8080/wp-content/uploads/photo.jpg' ),
    false
);

// 8d. Encoded directory traversal (%2e%2e, %2e%2e%2f) is rejected.
$fastlayer_assert(
    'encoded traversal (%2e%2e) is rejected',
    \FastLayer\Bootstrap::url_to_path( '/wp-content/%2e%2e/wp-config.php' ),
    false
);
$fastlayer_assert(
    'double-encoded traversal (%252e%252e) is rejected',
    \FastLayer\Bootstrap::url_to_path( '/wp-content/%252e%252e/wp-config.php' ),
    false
);
$fastlayer_assert(
    'encoded traversal in full URL is rejected',
    \FastLayer\Bootstrap::url_to_path( 'http://' . $fastlayer_host . '/wp-content/%2e%2e%2fwp-config.php' ),
    false
);

// 9. path_has_traversal() covers the segment and NUL cases directly, since
// esc_url_raw() would strip a NUL before url_to_path() ever sees it.
$fastlayer_assert( 'path_has_traversal("../x")', \FastLayer\Bootstrap::path_has_traversal( '../x' ), true );
$fastlayer_assert( 'path_has_traversal("a/../../b")', \FastLayer\Bootstrap::path_has_traversal( 'a/../../b' ), true );
$fastlayer_assert( 'path_has_traversal("a/b")', \FastLayer\Bootstrap::path_has_traversal( 'a/b' ), false );
$fastlayer_assert( 'path_has_traversal("")', \FastLayer\Bootstrap::path_has_traversal( '' ), true );
$fastlayer_assert( 'path_has_traversal with NUL', \FastLayer\Bootstrap::path_has_traversal( "a\0b" ), true );
$fastlayer_assert( 'path_has_traversal with backslash ..\\\\', \FastLayer\Bootstrap::path_has_traversal( '..\\x' ), true );

// 10. url_host_is_internal() reflects the site's own hosts only.
$fastlayer_assert( 'url_host_is_internal(home host)', \FastLayer\Bootstrap::url_host_is_internal( home_url( '/' ) ), true );
$fastlayer_assert( 'url_host_is_internal(home host with port)', \FastLayer\Bootstrap::url_host_is_internal( 'http://' . $fastlayer_host . ':8080/' ), true );
$fastlayer_assert( 'url_host_is_internal(external)', \FastLayer\Bootstrap::url_host_is_internal( 'https://evil.example.com/' ), false );
$fastlayer_assert( 'url_host_is_internal(external with port)', \FastLayer\Bootstrap::url_host_is_internal( 'https://evil.example.com:8080/' ), false );

if ( empty( $fastlayer_url_path_failures ) ) {
    echo "PASS: Bootstrap::url_to_path() maps internal URLs correctly and rejects traversal, NUL and foreign hosts.\n";
    exit( 0 );
}

foreach ( $fastlayer_url_path_failures as $fastlayer_url_path_failure ) {
    echo 'FAIL: ' . esc_html( $fastlayer_url_path_failure ) . "\n";
}

echo 'FAILED: ' . count( $fastlayer_url_path_failures ) . " check(s).\n";
exit( 1 );
