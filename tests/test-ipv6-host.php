<?php
/**
 * Regression test for the IPv6 literal host handling in advanced-cache.php.
 *
 * fastlayer_normalize_request_host() previously rejected any host that did not
 * match the hostname regex, so a request to a bracketed IPv6 literal such as
 * [::1] or [::1]:8080 was treated as untrusted and cache was disabled. The
 * fix accepts bracketed IPv6 literals (with or without a port) and rejects
 * malformed bracketed values the same way it rejects malformed hostnames.
 *
 * fastlayer_normalize_host() already handled brackets for comparison; this
 * test confirms it still does, and that the two functions agree on every
 * input they share.
 *
 * Run against a site with WP-CLI:
 *
 *     wp eval-file tests/test-ipv6-host.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$failures = array();

if ( ! function_exists( 'fastlayer_normalize_request_host' ) ) {
    $prev_preload = isset( $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] ) ? $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] : null;
    $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] = '1';
    require_once dirname( __DIR__ ) . '/advanced-cache.php';
    if ( null === $prev_preload ) {
        unset( $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] );
    } else {
        $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] = $prev_preload;
    }
}

if ( ! function_exists( 'fastlayer_normalize_request_host' ) || ! function_exists( 'fastlayer_normalize_host' ) ) {
    echo "FAIL: drop-in host functions are not loaded.\n";
    exit( 1 );
}

$cases = array(
    // [input HTTP_HOST, expected fastlayer_normalize_request_host()]
    // Valid domain names and case normalization
    array( 'example.com',        'example.com' ),
    array( 'Example.COM',        'example.com' ),
    array( 'example.com:8080',   'example.com' ),
    array( 'example.com.',       'example.com' ),
    array( 'sub.example.com',    'sub.example.com' ),
    // Valid IPv4 addresses
    array( '127.0.0.1',          '127.0.0.1' ),
    array( '127.0.0.1:8080',     '127.0.0.1' ),
    // Valid IPv6 bracketed and bare literals
    array( '[::1]',              '[::1]' ),
    array( '::1',                '[::1]' ),
    array( '[::1]:8080',         '[::1]' ),
    array( '[2001:db8::1]',      '[2001:db8::1]' ),
    array( '2001:db8::1',        '[2001:db8::1]' ),
    array( '[2001:db8::1]:443',  '[2001:db8::1]' ),
    // Empty ports (malformed)
    array( '[::1]:',             '' ),
    array( 'example.com:',       '' ),
    array( '127.0.0.1:',         '' ),
    // Nonnumeric ports (malformed)
    array( '[::1]:abc',          '' ),
    array( 'example.com:abc',    '' ),
    array( '127.0.0.1:xyz',      '' ),
    // Out-of-range ports (0 or >65535)
    array( '[::1]:0',            '' ),
    array( 'example.com:0',      '' ),
    array( '[::1]:65536',        '' ),
    array( 'example.com:65536',  '' ),
    array( '[::1]:999999',       '' ),
    array( 'example.com:999999', '' ),
    // Unexpected suffixes / multiple colons / paths
    array( 'evil.com:8080:extra', '' ),
    array( 'example.com:8080/foo', '' ),
    array( '[::1]:8080/path',    '' ),
    array( '[::1]x',             '' ),       // trailing junk after the bracket
    // Malformed bracketed IPv6 / zones / invalid literals
    array( '[fe80::1%25eth0]',   '' ),       // zone id is not a bare IPv6 literal
    array( '[::1',               '' ),       // unterminated bracket
    array( '[gggg]',             '' ),       // not an IPv6 address
    array( 'host with space',    '' ),
    array( 'bad@host',           '' ),
    array( '',                   '' ),
);

foreach ( $cases as $case ) {
    $prev_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
    $_SERVER['HTTP_HOST'] = $case[0];
    $got = fastlayer_normalize_request_host();
    if ( null === $prev_host ) {
        unset( $_SERVER['HTTP_HOST'] );
    } else {
        $_SERVER['HTTP_HOST'] = $prev_host;
    }

    if ( $got !== $case[1] ) {
        $failures[] = sprintf(
            'normalize_request_host(%s) returned %s, expected %s.',
            $case[0] === '' ? '(empty)' : $case[0],
            $got === '' ? '(empty)' : $got,
            $case[1] === '' ? '(empty)' : $case[1]
        );
    }
}

// fastlayer_normalize_host() must agree with the request host normaliser for
// every input (both helpers apply the exact same validation and normalization).
$compare = array();
foreach ( $cases as $case ) {
    $compare[] = $case[0];
}
foreach ( $compare as $host ) {
    $prev_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
    $_SERVER['HTTP_HOST'] = $host;
    $from_request = fastlayer_normalize_request_host();
    if ( null === $prev_host ) {
        unset( $_SERVER['HTTP_HOST'] );
    } else {
        $_SERVER['HTTP_HOST'] = $prev_host;
    }

    $from_helper = fastlayer_normalize_host( $host );
    if ( $from_request !== $from_helper ) {
        $failures[] = sprintf(
            'normalize_request_host(%s)=%s but normalize_host(%s)=%s; the two helpers disagree.',
            $host,
            $from_request === '' ? '(empty)' : $from_request,
            $host,
            $from_helper === '' ? '(empty)' : $from_helper
        );
    }
}

if ( empty( $failures ) ) {
    echo "PASS: IPv6 literal host normalisation is correct and consistent.\n";
    exit( 0 );
}

foreach ( $failures as $failure ) {
    echo 'FAIL: ' . esc_html( $failure ) . "\n";
}

echo 'FAILED: ' . count( $failures ) . " check(s).\n";
exit( 1 );