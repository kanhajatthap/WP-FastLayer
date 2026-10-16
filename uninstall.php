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

// Remove stored plugin settings (currently used and legacy naming rows). On
// multisite every site keeps its own options row, so the rows are removed for
// each site rather than only for the site the uninstall happens to run under.
$fastlayer_option_names = array(
    'fastlayer_options',
    'wp_fastlayer_options',
    'fastlayer_webp_last_run',
    'wp_fastlayer_webp_last_run',
    'fastlayer_image_runtime_debug_samples',
    'wp_fastlayer_image_runtime_debug_samples',
    'fastlayer_preload_state',
    'wp_fastlayer_preload_state',
    'fastlayer_preload_lock',
    'wp_fastlayer_preload_lock',
    'fastlayer_preload_batch_lock',
    'wp_fastlayer_preload_batch_lock',
);
$fastlayer_site_ids = array( 0 );
if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
    $fastlayer_site_ids = array();
    foreach ( get_sites( array( 'number' => 0 ) ) as $fastlayer_site ) {
        $fastlayer_site_id = isset( $fastlayer_site->blog_id ) ? (int) $fastlayer_site->blog_id : 0;
        if ( $fastlayer_site_id > 0 ) {
            $fastlayer_site_ids[] = $fastlayer_site_id;
        }
    }
}
foreach ( $fastlayer_site_ids as $fastlayer_site_id ) {
    $fastlayer_switched = false;
    if ( $fastlayer_site_id > 0 ) {
        switch_to_blog( $fastlayer_site_id );
        $fastlayer_switched = true;
    }
    foreach ( $fastlayer_option_names as $fastlayer_option_name ) {
        delete_option( $fastlayer_option_name );
    }
    if ( $fastlayer_switched ) {
        restore_current_blog();
    }
}

// Remove stored transients the plugin uses for notices and debug diagnostics.
// Transients are per site too, so run the removal under each site as well.
$fastlayer_transient_prefixes = array(
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
);
foreach ( $fastlayer_site_ids as $fastlayer_site_id ) {
    $fastlayer_switched = false;
    if ( $fastlayer_site_id > 0 ) {
        switch_to_blog( $fastlayer_site_id );
        $fastlayer_switched = true;
    }
    foreach ( $fastlayer_transient_prefixes as $fastlayer_transient_prefix ) {
        foreach ( array( '_transient_', '_transient_timeout_' ) as $fastlayer_transient_key_prefix ) {
            delete_option( $fastlayer_transient_key_prefix . $fastlayer_transient_prefix );
        }
        foreach ( array( '_transient_', '_transient_timeout_' ) as $fastlayer_transient_key_prefix ) {
            delete_option( $fastlayer_transient_key_prefix . 'wp_' . $fastlayer_transient_prefix );
        }
    }
    if ( $fastlayer_switched ) {
        restore_current_blog();
    }
}

// Clear scheduled (current and legacy) preload events.
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
    wp_clear_scheduled_hook( 'fastlayer_preload_cache' );
    wp_clear_scheduled_hook( 'wp_fastlayer_preload_cache' );
    wp_clear_scheduled_hook( 'fastlayer_preload_batch' );
    wp_clear_scheduled_hook( 'wp_fastlayer_preload_batch' );
}

// Remove generated cache files. The multisite layout nests per-site configs
// under a sites/ subdirectory, so the removal recurses instead of only sweeping
// the top level (otherwise the directory would not be empty and rmdir fails).
$fastlayer_cache_dir = WP_CONTENT_DIR . '/cache/wp-fastlayer/';
if ( is_dir( $fastlayer_cache_dir ) ) {
    $fastlayer_cache_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $fastlayer_cache_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $fastlayer_cache_iterator as $fastlayer_cache_item ) {
        if ( $fastlayer_cache_item->isDir() ) {
            if ( $fastlayer_filesystem ) {
                $fastlayer_filesystem->rmdir( $fastlayer_cache_item->getPathname() );
            }
            continue;
        }
        wp_delete_file( $fastlayer_cache_item->getPathname() );
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

// Clear FastLayer's WebP rewrite directives from the site-root .htaccess. Only
// the block wrapped in FastLayer's own marker is touched, and only when that
// marker is actually present, so a foreign plugin block or a hand-written rule
// is never modified. WordPress's insert_with_markers() keeps the BEGIN/END
// markers and refills the block with its standard informational comments, so
// this removes the directives rather than deleting the marker lines; that also
// means insert_with_markers() must not run when the marker is absent, or it
// would append a spurious block. The marker string must stay in sync with
// FastLayer\Webp::HTACCESS_MARKER; this file runs standalone without the
// plugin's autoloader. Generated .webp files are intentionally left in place:
// their ownership cannot be established reliably, and they are inert without
// the rewrite rule.
if ( ! function_exists( 'get_home_path' ) ) {
    $fastlayer_htaccess_file_include = ABSPATH . 'wp-admin/includes/file.php';
    if ( file_exists( $fastlayer_htaccess_file_include ) ) {
        require_once $fastlayer_htaccess_file_include;
    }
}
if ( ! function_exists( 'insert_with_markers' ) ) {
    $fastlayer_htaccess_misc_include = ABSPATH . 'wp-admin/includes/misc.php';
    if ( file_exists( $fastlayer_htaccess_misc_include ) ) {
        require_once $fastlayer_htaccess_misc_include;
    }
}
if ( $fastlayer_filesystem && function_exists( 'get_home_path' ) && function_exists( 'insert_with_markers' ) ) {
    $fastlayer_root_htaccess = trailingslashit( get_home_path() ) . '.htaccess';
    if ( file_exists( $fastlayer_root_htaccess ) && wp_is_writable( $fastlayer_root_htaccess ) ) {
        $fastlayer_root_htaccess_contents = $fastlayer_filesystem->get_contents( $fastlayer_root_htaccess );
        if ( is_string( $fastlayer_root_htaccess_contents ) && false !== strpos( $fastlayer_root_htaccess_contents, '# BEGIN WP FastLayer WebP' ) ) {
            insert_with_markers( $fastlayer_root_htaccess, 'WP FastLayer WebP', array() );
        }
    }
}

// Remove plugin logs (stored outside the public web root when supported).
$fastlayer_abspath = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( untrailingslashit( ABSPATH ) ) : untrailingslashit( str_replace( '\\', '/', ABSPATH ) );
$fastlayer_parent_abspath = dirname( $fastlayer_abspath );
if ( '' !== $fastlayer_parent_abspath && $fastlayer_parent_abspath !== $fastlayer_abspath ) {
    $fastlayer_outside_log_dir = trailingslashit( $fastlayer_parent_abspath . '/fastlayer-logs' );
    if ( is_dir( $fastlayer_outside_log_dir ) ) {
        $fastlayer_outside_log_file = $fastlayer_outside_log_dir . 'performance.log';
        if ( file_exists( $fastlayer_outside_log_file ) ) {
            wp_delete_file( $fastlayer_outside_log_file );
        }
        foreach ( array( '.htaccess', 'index.php' ) as $fastlayer_protection_file ) {
            if ( file_exists( $fastlayer_outside_log_dir . $fastlayer_protection_file ) ) {
                wp_delete_file( $fastlayer_outside_log_dir . $fastlayer_protection_file );
            }
        }
        if ( $fastlayer_filesystem ) {
            $fastlayer_filesystem->rmdir( $fastlayer_outside_log_dir );
        }
    }
}

// Remove plugin logs (legacy location in uploads).
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
