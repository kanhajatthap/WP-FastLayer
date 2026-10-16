<?php
/**
 * Standalone verification of fastlayer_normalize_request_host() from advanced-cache.php.
 * 
 * This script defines the constants the drop-in expects and then includes the file.
 * It tests the IPv6 host normalization without requiring a database.
 *
 * Exit status: 0 = pass, 1 = fail.
 */

// Define constants the drop-in expects
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/fastlayer-test/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', '/tmp/fastlayer-test/wp-content' );
}

// Define HTTP_HOST for testing
$_SERVER['HTTP_HOST'] = '';

// Load the drop-in
require_once 'C:\\Users\\india\\Local Sites\\fastlayer\\app\\public\\wp-content\\plugins\\fastlayer\\advanced-cache.php';

// Verify the function exists
if ( ! function_exists( 'fastlayer_normalize_request_host' ) ) {
    echo "FAIL: fastlayer_normalize_request_host() not found\n";
    exit( 1 );
}

// Test cases
$test_cases = array(
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

$failures = array();

foreach ( $test_cases as $case ) {
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

if ( empty( $failures ) ) {
    echo "PASS: IPv6 literal host normalisation is correct and consistent.\n";
    exit( 0 );
}

foreach ( $failures as $failure ) {
    echo "FAIL: $failure\n";
}

echo "FAILED: " . count( $failures ) . " check(s).\n";
exit( 1 );