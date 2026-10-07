<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove stored plugin settings.
delete_option( 'wp_fastlayer_options' );
delete_option( 'wp_fastlayer_webp_last_run' );
delete_option( 'wp_fastlayer_image_runtime_debug_samples' );

// Remove the preload job state and its locks.
delete_option( 'wp_fastlayer_preload_state' );
delete_option( 'wp_fastlayer_preload_lock' );
delete_option( 'wp_fastlayer_preload_batch_lock' );

// Clear scheduled preload event.
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
    wp_clear_scheduled_hook( 'wp_fastlayer_preload_cache' );
    wp_clear_scheduled_hook( 'wp_fastlayer_preload_batch' );
}

// Remove generated cache files.
$cache_dir = WP_CONTENT_DIR . '/cache/wp-fastlayer/';
if ( is_dir( $cache_dir ) ) {
    $files = glob( $cache_dir . '*', GLOB_NOSORT );
    if ( is_array( $files ) ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                @unlink( $file );
            }
        }
    }
    @rmdir( $cache_dir );
}

// Remove generated minified assets.
$min_dir = wp_upload_dir()['basedir'] . '/wp-fastlayer-min/';
if ( is_dir( $min_dir ) ) {
    $files = glob( $min_dir . '*', GLOB_NOSORT );
    if ( is_array( $files ) ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                @unlink( $file );
            }
        }
    }
    @rmdir( $min_dir );
}

// Remove advanced-cache.php drop-in if it belongs to WP FastLayer.
$adv_cache = WP_CONTENT_DIR . '/advanced-cache.php';
$plugin_adv_cache = dirname( __FILE__ ) . '/advanced-cache.php';
if ( file_exists( $adv_cache ) && file_exists( $plugin_adv_cache ) && md5_file( $adv_cache ) === md5_file( $plugin_adv_cache ) ) {
    @unlink( $adv_cache );
}

// Remove the WP_CACHE constant from wp-config.php, but only the exact
// definition that FastLayer inserted itself. A WP_CACHE define belonging to
// WordPress core, a manual edit, or another caching plugin is left untouched.
// If the Bootstrap class is somehow unavailable we do not touch wp-config.php.
if ( class_exists( 'WP_FastLayer\\Bootstrap' ) ) {
    \WP_FastLayer\Bootstrap::remove_wp_cache_constant();
}

// Remove plugin logs (new location in uploads).
$upload_dir = wp_upload_dir();
$log_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-logs/';
if ( is_dir( $log_dir ) ) {
    $log_file = $log_dir . 'performance.log';
    if ( file_exists( $log_file ) ) {
        @unlink( $log_file );
    }
    // The access-protection files are generated, so remove them too, otherwise
    // the directory is no longer empty and rmdir() below would fail.
    foreach ( array( '.htaccess', 'index.php' ) as $protection_file ) {
        if ( file_exists( $log_dir . $protection_file ) ) {
            @unlink( $log_dir . $protection_file );
        }
    }
    @rmdir( $log_dir );
}

// Remove old plugin logs from plugin directory (legacy).
$old_log_dir = dirname( __FILE__ ) . '/logs/';
if ( is_dir( $old_log_dir ) ) {
    $old_log_file = $old_log_dir . 'performance.log';
    if ( file_exists( $old_log_file ) ) {
        @unlink( $old_log_file );
    }
    @rmdir( $old_log_dir );
}
