<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Directory removal uses the WordPress filesystem API (not rmdir() directly)
// so it behaves on hosts where WordPress abstracts the filesystem.
global $wp_filesystem;
if ( ! function_exists( 'WP_Filesystem' ) ) {
    $fastlayer_filesystem_file = ABSPATH . 'wp-admin/includes/file.php';
    if ( file_exists( $fastlayer_filesystem_file ) ) {
        require_once $fastlayer_filesystem_file;
    }
}
$fastlayer_filesystem = false;
if ( function_exists( 'WP_Filesystem' ) && WP_Filesystem() && $wp_filesystem instanceof WP_Filesystem_Base ) {
    $fastlayer_filesystem = $wp_filesystem;
}

// Remove stored plugin settings (currently used and legacy naming rows).
delete_option( 'fastlayer_options' );
delete_option( 'wp_fastlayer_options' );
delete_option( 'fastlayer_webp_last_run' );
delete_option( 'wp_fastlayer_webp_last_run' );
delete_option( 'fastlayer_image_runtime_debug_samples' );
delete_option( 'wp_fastlayer_image_runtime_debug_samples' );
delete_option( 'fastlayer_preload_state' );
delete_option( 'wp_fastlayer_preload_state' );
delete_option( 'fastlayer_preload_lock' );
delete_option( 'wp_fastlayer_preload_lock' );
delete_option( 'fastlayer_preload_batch_lock' );
delete_option( 'wp_fastlayer_preload_batch_lock' );

// Remove stored transients the plugin uses for notices and debug diagnostics.
foreach (
    array(
        'fastlayer_write_failures',
        'fastlayer_cache_debug_trace',
        'fastlayer_cache_debug_test_result',
        'fastlayer_imagekit_test_notice',
        'fastlayer_dismissed_nginx_webp_notice',
        'fastlayer_image_debug_report_payload',
        'fastlayer_lcp_last_report',
        'fastlayer_webp_summary',
        'fastlayer_webp_upload_notice_',
        'fastlayer_bulk_webp_batch',
    ) as $fastlayer_transient_prefix
) {
    foreach ( array( '_transient_', '_transient_timeout_' ) as $fastlayer_transient_key_prefix ) {
        delete_option( $fastlayer_transient_key_prefix . $fastlayer_transient_prefix );
    }
    foreach ( array( '_transient_', '_transient_timeout_' ) as $fastlayer_transient_key_prefix ) {
        delete_option( $fastlayer_transient_key_prefix . 'wp_' . $fastlayer_transient_prefix );
    }
}

// Clear scheduled (current and legacy) preload events.
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
    wp_clear_scheduled_hook( 'fastlayer_preload_cache' );
    wp_clear_scheduled_hook( 'wp_fastlayer_preload_cache' );
    wp_clear_scheduled_hook( 'fastlayer_preload_batch' );
    wp_clear_scheduled_hook( 'wp_fastlayer_preload_batch' );
}

// Remove generated cache files.
$fastlayer_cache_dir = WP_CONTENT_DIR . '/cache/wp-fastlayer/';
if ( is_dir( $fastlayer_cache_dir ) ) {
    $fastlayer_files = glob( $fastlayer_cache_dir . '*', GLOB_NOSORT );
    if ( is_array( $fastlayer_files ) ) {
        foreach ( $fastlayer_files as $fastlayer_file ) {
            if ( is_file( $fastlayer_file ) ) {
                wp_delete_file( $fastlayer_file );
            }
        }
    }
    if ( $fastlayer_filesystem ) {
        $fastlayer_filesystem->rmdir( $fastlayer_cache_dir );
    }
}

// Remove generated minified assets.
$fastlayer_min_dir = wp_upload_dir()['basedir'] . '/wp-fastlayer-min/';
if ( is_dir( $fastlayer_min_dir ) ) {
    $fastlayer_files = glob( $fastlayer_min_dir . '*', GLOB_NOSORT );
    if ( is_array( $fastlayer_files ) ) {
        foreach ( $fastlayer_files as $fastlayer_file ) {
            if ( is_file( $fastlayer_file ) ) {
                wp_delete_file( $fastlayer_file );
            }
        }
    }
    if ( $fastlayer_filesystem ) {
        $fastlayer_filesystem->rmdir( $fastlayer_min_dir );
    }
}

// Remove preload artifacts (plugin-generated files under uploads).
$fastlayer_preload_dir = wp_upload_dir()['basedir'] . '/wp-fastlayer-preload/';
if ( is_dir( $fastlayer_preload_dir ) ) {
    $fastlayer_files = glob( $fastlayer_preload_dir . '*', GLOB_NOSORT );
    if ( is_array( $fastlayer_files ) ) {
        foreach ( $fastlayer_files as $fastlayer_file ) {
            if ( is_file( $fastlayer_file ) ) {
                wp_delete_file( $fastlayer_file );
            }
        }
    }
    if ( $fastlayer_filesystem ) {
        $fastlayer_filesystem->rmdir( $fastlayer_preload_dir );
    }
}

// Remove advanced-cache.php drop-in only if it belongs to WP FastLayer.
// Match the bundled file, the ownership marker, or a drop-in that declares
// FastLayer's cache functions (drop-ins installed under the legacy naming
// included). A foreign drop-in is never touched.
$fastlayer_adv_cache = WP_CONTENT_DIR . '/advanced-cache.php';
$fastlayer_plugin_adv_cache = dirname( __FILE__ ) . '/advanced-cache.php';
$fastlayer_is_ours = false;
if ( file_exists( $fastlayer_adv_cache ) ) {
    if ( file_exists( $fastlayer_plugin_adv_cache ) && md5_file( $fastlayer_adv_cache ) === md5_file( $fastlayer_plugin_adv_cache ) ) {
        $fastlayer_is_ours = true;
    } else {
        $fastlayer_adv_cache_head = (string) @file_get_contents( $fastlayer_adv_cache );
        $fastlayer_is_ours = ( false !== strpos( $fastlayer_adv_cache_head, 'wp-fastlayer:advanced-cache-dropin' ) )
            || ( false !== strpos( $fastlayer_adv_cache_head, 'function fastlayer_should_skip_cache' ) )
            || ( false !== strpos( $fastlayer_adv_cache_head, 'function wp_fastlayer_should_skip_cache' ) );
    }
}
if ( $fastlayer_is_ours ) {
    wp_delete_file( $fastlayer_adv_cache );
}

// Remove the WP_CACHE constant from wp-config.php, but only the exact
// definition that FastLayer inserted itself. A WP_CACHE define belonging to
// WordPress core, a manual edit, or another caching plugin is left untouched.
// If the Bootstrap class is somehow unavailable we do not touch wp-config.php.
if ( class_exists( 'FastLayer\\Bootstrap' ) ) {
    \FastLayer\Bootstrap::remove_wp_cache_constant();
}

// Remove plugin logs (new location in uploads).
$fastlayer_upload_dir = wp_upload_dir();
$fastlayer_log_dir = trailingslashit( $fastlayer_upload_dir['basedir'] ) . 'wp-fastlayer-logs/';
if ( is_dir( $fastlayer_log_dir ) ) {
    $fastlayer_log_file = $fastlayer_log_dir . 'performance.log';
    if ( file_exists( $fastlayer_log_file ) ) {
        wp_delete_file( $fastlayer_log_file );
    }
    // The access-protection files are generated, so remove them too, otherwise
    // the directory is no longer empty and the removal below would fail.
    foreach ( array( '.htaccess', 'index.php' ) as $fastlayer_protection_file ) {
        if ( file_exists( $fastlayer_log_dir . $fastlayer_protection_file ) ) {
            wp_delete_file( $fastlayer_log_dir . $fastlayer_protection_file );
        }
    }
    if ( $fastlayer_filesystem ) {
        $fastlayer_filesystem->rmdir( $fastlayer_log_dir );
    }
}

// Remove old plugin logs from plugin directory (legacy).
$fastlayer_old_log_dir = dirname( __FILE__ ) . '/logs/';
if ( is_dir( $fastlayer_old_log_dir ) ) {
    $fastlayer_old_log_file = $fastlayer_old_log_dir . 'performance.log';
    if ( file_exists( $fastlayer_old_log_file ) ) {
        wp_delete_file( $fastlayer_old_log_file );
    }
    if ( $fastlayer_filesystem ) {
        $fastlayer_filesystem->rmdir( $fastlayer_old_log_dir );
    }
}
