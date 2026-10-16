<?php
/**
 * Regression test for database identifier preparation and WP < 6.2 fallback.
 *
 * Verifies:
 * 1. WordPress >= 6.2 %i identifier placeholder support via $wpdb->has_cap('identifier_placeholders').
 * 2. Database_Optimization::quote_identifier() correctly escapes backticks and wraps in backticks.
 * 3. Fallback execution path successfully runs OPTIMIZE TABLE on a real database table.
 *
 * Run with WP-CLI:
 *     wp eval-file tests/test-sql-preparation.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

global $wpdb;

$failures = array();
$fastlayer_assert = function( $label, $actual, $expected ) use ( &$failures ) {
    if ( $actual !== $expected ) {
        $failures[] = sprintf(
            '%s failed: got %s, expected %s',
            $label,
            var_export( $actual, true ),
            var_export( $expected, true )
        );
        echo "FAIL: $label\n";
    } else {
        echo "PASS: $label\n";
    }
};

echo "Starting SQL preparation and identifier fallback verification...\n";

// Use reflection to access private static quote_identifier method
$reflection_class = new ReflectionClass( 'FastLayer\\Database_Optimization' );
$quote_method     = $reflection_class->getMethod( 'quote_identifier' );
$quote_method->setAccessible( true );

// 1. Verify quote_identifier behavior
$escaped_normal = $quote_method->invoke( null, 'wp_options' );
$fastlayer_assert( 'quote_identifier normal table', $escaped_normal, '`wp_options`' );

$escaped_backtick = $quote_method->invoke( null, 'wp_`bad`_table' );
$fastlayer_assert( 'quote_identifier backtick doubling', $escaped_backtick, '`wp_``bad``_table`' );

$escaped_mixed_case = $quote_method->invoke( null, 'wp_MixedCaseTable' );
$fastlayer_assert( 'quote_identifier case preservation', $escaped_mixed_case, '`wp_MixedCaseTable`' );

// 2. Test execution of the WP < 6.2 fallback path directly on a core table
$fallback_sql = 'OPTIMIZE TABLE ' . $escaped_normal;
$fallback_result = $wpdb->query( $fallback_sql );
$fastlayer_assert( 'fallback OPTIMIZE TABLE execution succeeds', ( false !== $fallback_result ), true );

// 3. Test execution of the WP >= 6.2 %i placeholder path if supported
if ( $wpdb->has_cap( 'identifier_placeholders' ) ) {
    $prepared_sql = $wpdb->prepare( 'OPTIMIZE TABLE %i', 'wp_options' );
    $prepared_result = $wpdb->query( $prepared_sql );
    $fastlayer_assert( 'WP 6.2+ %i OPTIMIZE TABLE execution succeeds', ( false !== $prepared_result ), true );
}

// 4. Test the full optimize_all_tables routine
$optimize_method = $reflection_class->getMethod( 'optimize_all_tables' );
$optimize_method->setAccessible( true );
$db_opt_instance = FastLayer\Database_Optimization::get_instance();
$opt_result = $optimize_method->invoke( $db_opt_instance );

$fastlayer_assert( 'optimize_all_tables reports success', $opt_result['success'], true );
$fastlayer_assert( 'optimize_all_tables affected > 0', ( $opt_result['affected'] > 0 ), true );

if ( empty( $failures ) ) {
    echo "PASS: SQL preparation and identifier fallback behavior directly verified.\n";
    exit( 0 );
}

echo "FAILED: " . count( $failures ) . " checks failed.\n";
exit( 1 );
