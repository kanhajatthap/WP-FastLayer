<?php
/**
 * Focused regression check for the FastLayer WebP .htaccess marker scoping.
 *
 * Confirms that the deactivation and uninstall cleanup can only ever touch
 * FastLayer's own rewrite block:
 *   - Webp::HTACCESS_MARKER has the exact expected value;
 *   - Webp::contains_htaccess_marker() reports presence and absence correctly;
 *   - clearing FastLayer's block with insert_with_markers() removes every
 *     FastLayer directive while preserving a foreign plugin block and a
 *     hand-written directive;
 *   - when the marker is absent the guard skips cleanup, so it can never append
 *     a spurious "# BEGIN WP FastLayer WebP" block.
 *
 * Note: WordPress core's insert_with_markers() never deletes the BEGIN/END
 * markers; it keeps them and refills the section with its standard
 * informational comments. The regression therefore asserts that the FastLayer
 * directives are gone and that whatever remains between the markers is a
 * comment, not that the markers themselves disappear.
 *
 * The check runs on temporary fixtures inside the uploads directory. It never
 * reads or writes the live site-root .htaccess, which is what the real
 * deactivation and uninstall code paths operate on.
 *
 * Run against a site with WP-CLI:
 *
 *     wp eval-file tests/test-webp-htaccess-scope.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$fastlayer_htaccess_failures = array();

if ( ! class_exists( 'FastLayer\\Webp' ) ) {
    echo "FAIL: FastLayer\\Webp class is not loaded.\n";
    exit( 1 );
}

// insert_with_markers() lives in wp-admin/includes/misc.php, which is not
// loaded during a plain WP-CLI request.
if ( ! function_exists( 'insert_with_markers' ) ) {
    require_once ABSPATH . 'wp-admin/includes/misc.php';
}

// The fixtures are handled through the WordPress filesystem API rather than raw
// file functions, matching the plugin's own write-safety rules.
global $wp_filesystem;
if ( ! function_exists( 'WP_Filesystem' ) ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}
if ( ! WP_Filesystem() || ! $wp_filesystem instanceof WP_Filesystem_Base ) {
    echo "FAIL: WP_Filesystem() is not available, so the fixture files cannot be created.\n";
    exit( 1 );
}

// 1. The shared marker is the value uninstall.php also inlines; a drift here
// would silently break cleanup on both deactivation and uninstall.
if ( \FastLayer\Webp::HTACCESS_MARKER !== 'WP FastLayer WebP' ) {
    $fastlayer_htaccess_failures[] = 'Webp::HTACCESS_MARKER does not equal "WP FastLayer WebP".';
}

$fastlayer_htaccess_upload = wp_upload_dir();
$fastlayer_htaccess_base   = trailingslashit( $fastlayer_htaccess_upload['basedir'] ) . 'fastlayer-htaccess-test-' . wp_generate_password( 8, false, false );
$fastlayer_htaccess_mixed  = $fastlayer_htaccess_base . '-mixed.txt';
$fastlayer_htaccess_plain  = $fastlayer_htaccess_base . '-plain.txt';

$fastlayer_htaccess_foreign = implode(
    "\n",
    array(
        '# BEGIN Other Plugin',
        'RewriteRule ^other-plugin$ /index.php [L]',
        '# END Other Plugin',
    )
);
$fastlayer_htaccess_ours = implode(
    "\n",
    array(
        '# BEGIN WP FastLayer WebP',
        '<IfModule mod_mime.c>',
        'AddType image/webp .webp',
        '</IfModule>',
        '# END WP FastLayer WebP',
    )
);
$fastlayer_htaccess_manual = implode(
    "\n",
    array(
        '# Hand-written directive added by the site owner.',
        'php_flag display_errors off',
    )
);

$fastlayer_htaccess_mixed_contents = $fastlayer_htaccess_foreign . "\n\n" . $fastlayer_htaccess_ours . "\n\n" . $fastlayer_htaccess_manual . "\n";

if ( ! $wp_filesystem->put_contents( $fastlayer_htaccess_mixed, $fastlayer_htaccess_mixed_contents ) ) {
    echo "FAIL: could not write the mixed fixture file.\n";
    exit( 1 );
}

// 2. Presence detection must find FastLayer's marker inside a mixed file.
if ( true !== \FastLayer\Webp::contains_htaccess_marker( $fastlayer_htaccess_mixed_contents ) ) {
    $fastlayer_htaccess_failures[] = 'contains_htaccess_marker() did not detect the FastLayer block.';
}

// 3. Clearing the block must strip every FastLayer directive and leave every
// other line untouched.
insert_with_markers( $fastlayer_htaccess_mixed, \FastLayer\Webp::HTACCESS_MARKER, array() );

$fastlayer_htaccess_after = (string) $wp_filesystem->get_contents( $fastlayer_htaccess_mixed );

foreach ( array( 'AddType image/webp .webp', 'RewriteCond %{HTTP_ACCEPT} image/webp', 'Header append Vary Accept' ) as $fastlayer_htaccess_directive ) {
    if ( false !== strpos( $fastlayer_htaccess_after, $fastlayer_htaccess_directive ) ) {
        $fastlayer_htaccess_failures[] = 'A FastLayer directive survived cleanup: ' . $fastlayer_htaccess_directive;
    }
}
if ( false === strpos( $fastlayer_htaccess_after, '# BEGIN Other Plugin' ) || false === strpos( $fastlayer_htaccess_after, '# END Other Plugin' ) ) {
    $fastlayer_htaccess_failures[] = 'The foreign plugin block was modified or removed.';
}
if ( false === strpos( $fastlayer_htaccess_after, 'RewriteRule ^other-plugin$ /index.php [L]' ) ) {
    $fastlayer_htaccess_failures[] = 'The foreign plugin directive was lost.';
}
if ( false === strpos( $fastlayer_htaccess_after, 'php_flag display_errors off' ) ) {
    $fastlayer_htaccess_failures[] = 'The hand-written directive was lost.';
}

// Whatever WordPress keeps between FastLayer's markers must be comments only.
if ( preg_match( '/# BEGIN WP FastLayer WebP(.*?)# END WP FastLayer WebP/s', $fastlayer_htaccess_after, $fastlayer_htaccess_match ) ) {
    foreach ( explode( "\n", (string) $fastlayer_htaccess_match[1] ) as $fastlayer_htaccess_line ) {
        $fastlayer_htaccess_line = trim( $fastlayer_htaccess_line );
        if ( '' !== $fastlayer_htaccess_line && 0 !== strpos( $fastlayer_htaccess_line, '#' ) ) {
            $fastlayer_htaccess_failures[] = 'The emptied FastLayer block still holds a non-comment line: ' . $fastlayer_htaccess_line;
        }
    }
}

// 4. Missing-marker behavior. insert_with_markers() with an empty array appends
// an empty "# BEGIN / # END" block when the markers are absent, which is why
// the real cleanup guards on contains_htaccess_marker() first. Mirror that
// guard here and confirm the file is left untouched.
$fastlayer_htaccess_plain_contents = $fastlayer_htaccess_foreign . "\n\n" . $fastlayer_htaccess_manual . "\n";

if ( ! $wp_filesystem->put_contents( $fastlayer_htaccess_plain, $fastlayer_htaccess_plain_contents ) ) {
    echo "FAIL: could not write the plain fixture file.\n";
    exit( 1 );
}

if ( false !== \FastLayer\Webp::contains_htaccess_marker( $fastlayer_htaccess_plain_contents ) ) {
    $fastlayer_htaccess_failures[] = 'contains_htaccess_marker() reported a block in a file that has none.';
}

$fastlayer_htaccess_plain_before = (string) $wp_filesystem->get_contents( $fastlayer_htaccess_plain );
if ( \FastLayer\Webp::contains_htaccess_marker( $fastlayer_htaccess_plain_before ) ) {
    insert_with_markers( $fastlayer_htaccess_plain, \FastLayer\Webp::HTACCESS_MARKER, array() );
}

$fastlayer_htaccess_plain_after = (string) $wp_filesystem->get_contents( $fastlayer_htaccess_plain );

if ( false !== strpos( $fastlayer_htaccess_plain_after, 'WP FastLayer WebP' ) ) {
    $fastlayer_htaccess_failures[] = 'Cleanup created an empty FastLayer marker block in a file that had none.';
}
if ( $fastlayer_htaccess_plain_after !== $fastlayer_htaccess_plain_contents ) {
    $fastlayer_htaccess_failures[] = 'A markerless fixture was modified by the guarded cleanup path.';
}

// Clean up the temporary fixtures.
if ( $wp_filesystem->exists( $fastlayer_htaccess_mixed ) ) {
    $wp_filesystem->delete( $fastlayer_htaccess_mixed );
}
if ( $wp_filesystem->exists( $fastlayer_htaccess_plain ) ) {
    $wp_filesystem->delete( $fastlayer_htaccess_plain );
}

if ( empty( $fastlayer_htaccess_failures ) ) {
    echo "PASS: WebP .htaccess cleanup strips only FastLayer's directives, preserves other rules, and never adds a spurious block.\n";
    exit( 0 );
}

foreach ( $fastlayer_htaccess_failures as $fastlayer_htaccess_failure ) {
    echo 'FAIL: ' . esc_html( $fastlayer_htaccess_failure ) . "\n";
}

echo 'FAILED: ' . count( $fastlayer_htaccess_failures ) . " check(s).\n";
exit( 1 );
