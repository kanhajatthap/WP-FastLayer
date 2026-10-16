<?php
/**
 * Test legacy v1.0.0 state migration to v1.1.0.
 *
 * Verifies that:
 * 1. wp_fastlayer_options is migrated to fastlayer_options.
 * 2. Legacy cron wp_fastlayer_preload_cache is migrated to fastlayer_preload_cache.
 * 3. Legacy batch cron wp_fastlayer_preload_batch is cleared.
 * 4. mirror_legacy_options keeps fastlayer_options updated when legacy option is updated.
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

$failures = array();

// Backup existing fastlayer_options
$original_options = get_option( 'fastlayer_options', false );
$original_legacy  = get_option( 'wp_fastlayer_options', false );
$original_cron    = wp_next_scheduled( 'fastlayer_preload_cache' );

try {
    // 1. Setup disposable v1.0.0 state
    delete_option( 'fastlayer_options' );
    wp_clear_scheduled_hook( 'fastlayer_preload_cache' );

    $v1_options = array(
        'enable_cache'     => '1',
        'cache_expiration' => '24',
        'legacy_marker'    => 'v1_test_marker',
    );
    update_option( 'wp_fastlayer_options', $v1_options, false );

    $sched_time = time() + 1800;
    wp_schedule_event( $sched_time, 'hourly', 'wp_fastlayer_preload_cache' );
    wp_schedule_single_event( time() + 300, 'wp_fastlayer_preload_batch' );

    // 2. Trigger migration via Reflection on Bootstrap
    $bs = FastLayer\Bootstrap::get_instance();
    $ref = new ReflectionClass( $bs );
    $method = $ref->getMethod( 'migrate_legacy_state' );
    $method->setAccessible( true );
    $method->invoke( $bs );

    // 3. Assert options migration
    $migrated = get_option( 'fastlayer_options', array() );
    if ( ! is_array( $migrated ) || ( $migrated['legacy_marker'] ?? '' ) !== 'v1_test_marker' ) {
        $failures[] = 'fastlayer_options was not properly populated from wp_fastlayer_options.';
    }
    if ( ( $migrated['cache_expiration'] ?? '' ) !== '24' ) {
        $failures[] = 'cache_expiration was not migrated correctly.';
    }

    // 4. Assert cron migration
    if ( wp_next_scheduled( 'wp_fastlayer_preload_cache' ) ) {
        $failures[] = 'Legacy wp_fastlayer_preload_cache was not cleared.';
    }
    if ( wp_next_scheduled( 'wp_fastlayer_preload_batch' ) ) {
        $failures[] = 'Legacy wp_fastlayer_preload_batch was not cleared.';
    }
    $new_cron = wp_next_scheduled( 'fastlayer_preload_cache' );
    if ( ! $new_cron || $new_cron !== $sched_time ) {
        $failures[] = sprintf( 'fastlayer_preload_cache not scheduled at expected time (%s vs %s).', (string) $new_cron, (string) $sched_time );
    }

    // 5. Test mirroring
    $updated_legacy = array_merge( $v1_options, array( 'mirrored_key' => 'mirrored_value' ) );
    $bs->mirror_legacy_options( null, $updated_legacy, null );
    $mirrored_opt = get_option( 'fastlayer_options', array() );
    if ( ( $mirrored_opt['mirrored_key'] ?? '' ) !== 'mirrored_value' ) {
        $failures[] = 'mirror_legacy_options did not synchronize update to fastlayer_options.';
    }

} finally {
    // Restore original state
    if ( false !== $original_options ) {
        update_option( 'fastlayer_options', $original_options, false );
    } else {
        delete_option( 'fastlayer_options' );
    }

    if ( false !== $original_legacy ) {
        update_option( 'wp_fastlayer_options', $original_legacy, false );
    } else {
        delete_option( 'wp_fastlayer_options' );
    }

    wp_clear_scheduled_hook( 'fastlayer_preload_cache' );
    wp_clear_scheduled_hook( 'wp_fastlayer_preload_cache' );
    wp_clear_scheduled_hook( 'wp_fastlayer_preload_batch' );
    if ( $original_cron ) {
        wp_schedule_event( $original_cron, 'hourly', 'fastlayer_preload_cache' );
    }
}

if ( empty( $failures ) ) {
    echo "PASS: Legacy v1.0.0 state migration directly verified.\n";
    exit( 0 );
}

foreach ( $failures as $failure ) {
    echo "FAIL: $failure\n";
}
exit( 1 );
