<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove stored plugin settings.
delete_option( 'wp_fastlayer_options' );
delete_option( 'wp_fastlayer_webp_last_run' );
delete_option( 'wp_fastlayer_image_runtime_debug_samples' );

// Clear scheduled preload event.
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
    wp_clear_scheduled_hook( 'wp_fastlayer_preload_cache' );
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

// Remove plugin logs.
$log_dir = defined( 'WP_FASTLAYER_PATH' ) ? WP_FASTLAYER_PATH . 'logs/' : dirname( __FILE__ ) . '/logs/';
if ( is_dir( $log_dir ) ) {
    $log_file = $log_dir . 'performance.log';
    if ( file_exists( $log_file ) ) {
        @unlink( $log_file );
    }
    @rmdir( $log_dir );
}
