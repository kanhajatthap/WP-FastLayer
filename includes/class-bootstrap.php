<?php
namespace FastLayer;
if ( ! defined( 'ABSPATH' ) ) exit;

class Bootstrap {
    private static $instance = null;
    private $log_file;
    private $is_debug_mode;
    const ADVANCED_CACHE_DROPIN = WP_CONTENT_DIR . '/advanced-cache.php';
    const ADVANCED_CACHE_DROPIN_MARKER = 'wp-fastlayer:advanced-cache-dropin';
    const WP_CACHE_OWNER_MARKER = 'wp-fastlayer:owned-wp-cache';
    const WRITE_FAILURE_TRANSIENT = 'fastlayer_write_failures';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->is_debug_mode = $this->is_debug_enabled();

add_action( 'init', array( $this, 'init' ) );
        register_activation_hook( FASTLAYER_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( FASTLAYER_FILE, array( $this, 'deactivate' ) );

        add_action( 'update_option_fastlayer_options', array( $this, 'sync_static_config' ), 10, 3 );
        add_action( 'add_option_fastlayer_options', array( $this, 'sync_static_config' ), 10, 2 );

        // On multisite each site keeps its own config file. A site created after
        // activation must be added to the network index at creation time, or the
        // drop-in cannot resolve it until its first front-end request (and until
        // then the main site's entry would answer for it).
        add_action( 'wp_initialize_site', array( $this, 'on_initialize_site' ), 20, 1 );

        // The drop-in resolves a site by the home URL recorded in sites.json, so
        // a home URL change (Settings -> General, network admin, or a
        // domain-mapping plugin) must refresh the index immediately. Without
        // this the site stays unresolvable to the drop-in (cache disabled) and
        // the old home URL keeps answering for it until its settings are saved.
        add_action( 'update_option_home', array( $this, 'on_home_option_updated' ), 10, 3 );
        add_action( 'update_option_siteurl', array( $this, 'on_home_option_updated' ), 10, 3 );

        // Installs upgraded from the original wp_fastlayer_options naming keep
        // writing the legacy row until a settings save flips the plugins to
        // the new key. Mirror those writes so the two rows never diverge.
        add_action( 'update_option_wp_fastlayer_options', array( $this, 'mirror_legacy_options' ), 10, 3 );
        add_action( 'add_option_wp_fastlayer_options', array( $this, 'mirror_legacy_options' ), 10, 2 );

        if ( is_admin() ) {
            add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        }
    }

    /**
     * Records a file FastLayer could not write.
     *
     * Activation, deactivation and uninstall all run outside the admin screens,
     * so the message is stored in a short-lived transient and rendered by
     * admin_notices() on the next admin page load. The debug log is written as
     * well, but it only reaches the log when WP_DEBUG is enabled, so it is not
     * sufficient on its own.
     *
     * @param string $context File or area the failure belongs to, e.g. '.htaccess'.
     * @param string $message Human readable reason.
     */
    public static function record_write_failure( $context, $message ) {
        \fastlayer_debug_log( 'WP FastLayer: ' . $context . ' write failure: ' . $message );

        $failures = get_transient( self::WRITE_FAILURE_TRANSIENT );
        $failures = is_array( $failures ) ? $failures : array();
        $failures[] = $context . ': ' . $message;

        // Keep only the most recent entries so a repeatedly failing task cannot
        // grow the option row without bound.
        set_transient( self::WRITE_FAILURE_TRANSIENT, array_slice( $failures, -10 ), 300 );
    }

    public function admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $failures = get_transient( self::WRITE_FAILURE_TRANSIENT );
        if ( is_array( $failures ) && $failures ) {
            echo '<div class="notice notice-error wpfastlayer-notice"><p><strong>FastLayer:</strong> The following files could not be updated:</p><ul style="list-style: disc; margin-left: 2em;">';
            foreach ( $failures as $failure ) {
                echo '<li>' . esc_html( $failure ) . '</li>';
            }
            echo '</ul></div>';
            delete_transient( self::WRITE_FAILURE_TRANSIENT );
        }

        if ( ! $this->is_cache_directory_writable() ) {
            echo '<div class="notice notice-error wpfastlayer-notice"><p><strong>FastLayer:</strong> Cache directory is not writable. Please check permissions for: ' . esc_html( FASTLAYER_CACHE_DIR ) . '</p></div>';
        }

        if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
            $dropin_installed = file_exists( self::ADVANCED_CACHE_DROPIN );
            if ( $dropin_installed ) {
                echo '<div class="notice notice-warning wpfastlayer-notice is-dismissible"><p><strong>FastLayer:</strong> The page cache drop-in is installed but <code>WP_CACHE</code> is not set to <code>true</code> in <code>wp-config.php</code>. Add the following line above the "stop editing" comment: <code>define( \'WP_CACHE\', true );</code></p></div>';
            }
        }
    }

    private function is_cache_directory_writable() {
        $cache_dir = FASTLAYER_CACHE_DIR;
        if ( ! file_exists( $cache_dir ) ) {
            return wp_mkdir_p( $cache_dir );
        }
        return wp_is_writable( $cache_dir );
    }

    private function is_debug_enabled() {
        $options = get_option( 'fastlayer_options', array() );
        return isset( $options['debug_mode'] ) && (string) $options['debug_mode'] === '1';
    }

    private function load_modules() {
        $modules = array(
            'Page_Cache',
            'Optimizer',
            'Database_Optimization',
            'Admin_Bar',
            'Webp',
            'CDN',
        );

        foreach ( $modules as $module ) {
            $fqcn = 'FastLayer\\' . $module;
            if ( class_exists( $fqcn ) ) {
                try {
                    $fqcn::get_instance();
                } catch ( \Throwable $e ) {
                    \fastlayer_debug_log( 'WP FastLayer: failed to load module ' . $module . ' - ' . $e->getMessage() );
                }
            }
        }

        if ( ! class_exists( 'FastLayer\\Admin' ) ) {
            $admin_file = FASTLAYER_PATH . 'includes/class-admin.php';
            if ( file_exists( $admin_file ) ) {
                require_once $admin_file;
                \fastlayer_debug_log( 'WP FastLayer: required class-admin.php as fallback.' );
            }
        }

        if ( class_exists( 'FastLayer\\Admin' ) ) {
            \fastlayer_debug_log( 'WP FastLayer: Admin class exists before instantiation.' );
            try {
                Admin::get_instance();
                \fastlayer_debug_log( 'WP FastLayer: Admin class instantiated successfully.' );
            } catch ( \Throwable $e ) {
                \fastlayer_debug_log( 'WP FastLayer: failed to load Admin - ' . $e->getMessage() );
            }
        } else {
            \fastlayer_debug_log( 'WP FastLayer: Admin class does not exist at load_modules.' );
        }
    }

    public function init() {
        try {
            $this->create_cache_directory();
            $this->create_logs_directory();
            $this->migrate_legacy_state();
            $this->reset_options_if_needed();
            $this->ensure_static_config();
            $this->load_modules();
        } catch ( \Throwable $e ) {
            \fastlayer_debug_log( 'WP FastLayer init error: ' . $e->getMessage() );
        }
    }

    /**
     * Re-creates the static config when it is missing, invalid, or left in the
     * legacy executable form.
     *
     * This makes the JSON migration self-healing: an install that upgraded but
     * has not saved settings yet, a file damaged by a partial write, and a site
     * whose home URL changed (the drop-in resolves sites by home URL) all end
     * up with a valid file on the next request, so the drop-in never has to
     * rely on the legacy file or on a stale index for long.
     */
    private function ensure_static_config() {
        $cache_dir   = wp_normalize_path( FASTLAYER_CACHE_DIR );
        $legacy_file = $cache_dir . 'config.php';

        if ( self::is_multisite_install() ) {
            $blog_id    = self::current_site_id();
            $site_file  = $cache_dir . 'sites/' . $blog_id . '.json';
            $index_file = $cache_dir . 'sites.json';

            // A missing, empty or malformed index, a site whose home URL
            // changed, and a missing or corrupt per-site file all leave the
            // drop-in unable to load this site's options. write_static_config()
            // repairs all of them: it rewrites the per-site file and, when the
            // index is unusable, update_site_index() rebuilds it from the sites
            // table so the other sites' entries are preserved.
            $index      = self::read_json_file( $index_file );
            $site_valid = is_array( self::read_json_file( $site_file ) );

            if ( ! self::index_maps_site( $index, $blog_id ) || ! $site_valid || file_exists( $legacy_file ) ) {
                self::write_static_config( get_option( 'fastlayer_options', array() ) );
            }
            return;
        }

        $json_file  = $cache_dir . 'config.json';
        $json_valid = is_array( self::read_json_file( $json_file ) );
        if ( ! $json_valid || file_exists( $legacy_file ) ) {
            self::write_static_config( get_option( 'fastlayer_options', array() ) );
        }
    }

    /**
     * One-time upgrade path for installs created with the original option
     * naming.
     *
     * The option key, cron hooks and batch-lock option moved from the
     * wp_fastlayer_* prefix to the fastlayer_* prefix. Existing, non-empty
     * settings are copied across, and a scheduled legacy preload event is
     * re-created under its new name instead of being left to fire with no
     * handler. Nothing is deleted from the legacy row; the mirror hooks in the
     * constructor keep both rows in sync until the next settings save.
     */
    private function migrate_legacy_state() {
        if ( false === get_option( 'fastlayer_options', false ) ) {
            $legacy = get_option( 'wp_fastlayer_options', array() );
            if ( is_array( $legacy ) && ! empty( $legacy ) ) {
                update_option( 'fastlayer_options', $legacy, false );
            }
        }

        $legacy_cron = wp_next_scheduled( 'wp_fastlayer_preload_cache' );
        if ( $legacy_cron ) {
            wp_clear_scheduled_hook( 'wp_fastlayer_preload_cache' );
            if ( ! wp_next_scheduled( 'fastlayer_preload_cache' ) ) {
                wp_schedule_event( $legacy_cron, 'hourly', 'fastlayer_preload_cache' );
            }
        }

        if ( wp_next_scheduled( 'wp_fastlayer_preload_batch' ) ) {
            wp_clear_scheduled_hook( 'wp_fastlayer_preload_batch' );
        }
    }

    /**
     * Keeps the migrated option row in sync with writes made to the legacy
     * wp_fastlayer_options key. Signature mirrors sync_static_config().
     */
    public function mirror_legacy_options( $param1, $param2 = null, $param3 = null ) {
        $value = is_array( $param3 ) ? $param3 : $param2;
        if ( is_array( $value ) && ! empty( $value ) ) {
            update_option( 'fastlayer_options', $value, false );
        }
    }

    private function reset_options_if_needed() {
        $options = get_option( 'fastlayer_options', array() );

        if ( ! is_array( $options ) || empty( $options ) ) {
            $this->set_default_options();
            return;
        }

        $default_options = self::get_default_options();
        $normalized_options = wp_parse_args( $options, $default_options );
        $normalized_options['_version'] = FASTLAYER_VERSION;

        if ( $normalized_options !== $options ) {
            update_option( 'fastlayer_options', $normalized_options );
        }
    }

    public function activate( $network_wide = false ) {
        $this->create_cache_directory();
        $this->create_logs_directory();
        $this->migrate_legacy_state();
        $this->set_default_options( get_option( 'fastlayer_options', array() ) );
        wp_clear_scheduled_hook( 'wp_fastlayer_preload_cache' );
        if ( ! wp_next_scheduled( 'fastlayer_preload_cache' ) ) {
            wp_schedule_event( time(), 'hourly', 'fastlayer_preload_cache' );
        }
        self::install_advanced_cache_dropin();
        self::add_wp_cache_constant();

        // Network activation must seed the site index for every existing site,
        // so the drop-in can resolve each one before it has been visited. A
        // site-level activation only writes the current site's entry.
        if ( self::is_multisite_install() && $network_wide ) {
            self::rebuild_multisite_index();
        }
    }

    public static function get_default_options() {
        return array(
            'enable_cache' => '0',
            'enable_preload' => '0',
            'enable_minify' => '0',
            'enable_minify_css' => '0',
            'enable_minify_js' => '0',
            'enable_lazyload' => '0',
            'enable_lazyload_iframes' => '0',
            'enable_lazyload_videos' => '0',
            'enable_smart_lcp_optimization' => '0',
            'enable_lcp_preload' => '1',
            'enable_lcp_hero_background' => '1',
            'enable_lcp_debug_mode' => '0',
            'enable_webp' => '0',
            'enable_bulk_webp_generation' => '1',
            'enable_image_delivery' => '0',
            'enable_cdn' => '0',
            'cdn_cname' => '',
            'enable_imagekit_cdn' => '0',
            'imagekit_endpoint' => '',
            'imagekit_origin_path_mode' => 'full_uploads_path',
            'imagekit_auto_quality' => '1',
            'imagekit_auto_format' => '1',
            'imagekit_auto_format_mode' => 'f-auto',
            'imagekit_max_oversize_ratio' => '1.5',
            'imagekit_runtime_correction' => '1',
            'imagekit_debug_overlay' => '0',
            'webp_quality' => 80,
            'enable_progressive_image_loading' => '0',
            'enable_lqip_placeholders' => '1',
            'lqip_quality' => 25,
            'lqip_width' => 40,
            'lqip_blur_intensity' => 20,
            'lqip_fade_duration' => 250,
            'enable_html_remove_comments' => '1',
            'enable_html_remove_whitespace' => '1',
            'enable_html_preserve_gutenberg_comments' => '1',
            'enable_css_combine' => '0',
            'enable_css_remove_comments' => '0',
            'enable_css_remove_unused' => '0',
            'enable_smart_gutenberg_css_optimization' => '1',
            'enable_google_fonts_display' => '1',
            'enable_css_inline' => '0',
            'enable_css_delivery' => '0',
            'enable_critical_css' => '0',
            'critical_css_custom' => '',
            'exclude_css_files' => '',
            'enable_js_combine' => '0',
            'enable_js_remove_comments' => '0',
            'enable_js_defer' => '0',
            'enable_js_async' => '0',
            'enable_js_safe_mode' => '0',
            'enable_js_smart_delay' => '0',
            'enable_js_smart_delay_debug' => '0',
            'exclude_js_files' => '',
            'exclude_js_defer' => '',
            'smart_js_delay_timeout' => '6000',
            'smart_js_delay_exclusions' => '',
            'debug_mode' => '0',
            'db_clean_post_revisions' => '0',
            'db_clean_trashed_posts' => '0',
            'db_clean_spam_comments' => '0',
            'db_clean_auto_drafts' => '0',
            'db_clean_all_transients' => '0',
            'db_clean_duplicated_postmeta' => '0',
            'db_clean_duplicated_commentmeta' => '0',
            'db_clean_duplicated_usermeta' => '0',
            'db_clean_duplicated_termmeta' => '0',
            'db_clean_expired_transients' => '0',
            'db_clean_oembed_cache' => '0',
            'db_clean_orphaned_postmeta' => '0',
            'db_clean_orphaned_usermeta' => '0',
            'db_clean_orphaned_termmeta' => '0',
            'db_clean_orphaned_commentmeta' => '0',
            'cache_expiration' => 10,
            'exclude_urls' => '',
            'exclude_cookies' => '',
            'exclude_user_agents' => '',
            '_version' => FASTLAYER_VERSION
        );
    }

    private function set_default_options( $existing_options = array() ) {
        $options = wp_parse_args( is_array( $existing_options ) ? $existing_options : array(), self::get_default_options() );
        $options['_version'] = FASTLAYER_VERSION;

        update_option( 'fastlayer_options', $options );
        self::write_static_config( $options );
    }

    /**
     * Writes the static config used by the advanced-cache.php drop-in.
     *
     * On a single-site install this is one config.json file. On multisite the
     * options are per site, so each site gets sites/{blog_id}.json and a shared
     * sites.json index maps a site's home URL to its id; the drop-in resolves
     * the current request against that index (it cannot call
     * get_current_blog_id() before ms-settings.php runs).
     *
     * The files live in FastLayer's own cache directory and only serve as a
     * pre-bootstrap mirror of the options row, which stays the source of truth.
     * They are plain JSON, not PHP, so nothing under wp-content/ is ever
     * executed by including it. Every target path is forced to remain inside
     * the cache directory, and a failed or partial write is recorded instead of
     * being silenced, so a read-only directory raises a notice rather than
     * silently leaving the drop-in on stale settings.
     *
     * @param array $options Plugin options (pre-migration form is fine too).
     * @return bool True when the config file on disk matches the options.
     */
    public static function write_static_config( $options ) {
        $cache_dir = wp_normalize_path( FASTLAYER_CACHE_DIR );
        if ( ! file_exists( $cache_dir ) ) {
            wp_mkdir_p( $cache_dir );
        }

        self::ensure_directory_protection( $cache_dir );

        $options = is_array( $options ) ? $options : array();

        // The advanced-cache.php drop-in runs before WordPress is loaded, so it
        // cannot call home_url() itself. Store the canonical hosts here so the
        // drop-in can validate the client-supplied HTTP_HOST before it is used
        // in a cache key. This key exists only in the generated config file; it
        // is never written back to the fastlayer_options database row.
        $options['_home_host'] = self::get_expected_hosts();

        if ( self::is_multisite_install() ) {
            return self::write_multisite_static_config( $cache_dir, $options );
        }

        $options['_blog_id'] = 1;

        $config_file = wp_normalize_path( $cache_dir . 'config.json' );
        if ( 0 !== strpos( $config_file, $cache_dir ) ) {
            self::record_write_failure( 'config.json', 'the target path escaped the cache directory; no file was written.' );
            return false;
        }

        if ( ! self::write_json_file( $config_file, $options ) ) {
            self::record_write_failure( 'config.json', 'the file could not be written to ' . $config_file . '.' );
            return false;
        }

        // A single-site install must not leave a multisite index behind: the
        // drop-in prefers the index over config.json, so a stale index would
        // shadow every later settings change.
        self::remove_legacy_config_files( $cache_dir );
        self::remove_multisite_artifacts( $cache_dir );

        return true;
    }

    /**
     * Writes the per-site config file and the network site index on multisite.
     *
     * Every site keeps its own file under sites/{blog_id}.json, while
     * sites.json maps a site's home URL to its id. advanced-cache.php cannot
     * call get_current_blog_id() (it runs before ms-settings.php), so it
     * resolves the current site by matching the request host and path against
     * that index. The index is additive: each write only adds or updates the
     * current site's entry, so one site's save never clobbers another's.
     *
     * @param string $cache_dir Normalized cache directory (trailing slash).
     * @param array  $options   Plugin options, already carrying _home_host.
     * @return bool
     */
    private static function write_multisite_static_config( $cache_dir, $options ) {
        $blog_id = self::current_site_id();
        if ( $blog_id <= 0 ) {
            self::record_write_failure( 'config.json', 'the multisite site id could not be determined; no per-site file was written.' );
            return false;
        }

        $sites_dir = wp_normalize_path( $cache_dir . 'sites/' );
        if ( 0 !== strpos( $sites_dir, $cache_dir ) ) {
            self::record_write_failure( 'config.json', 'the per-site directory escaped the cache directory; no file was written.' );
            return false;
        }
        if ( ! file_exists( $sites_dir ) ) {
            wp_mkdir_p( $sites_dir );
        }
        self::ensure_directory_protection( $sites_dir );

        $home_url = self::site_home_url( $blog_id );

        $options['_blog_id']  = $blog_id;
        $options['_home_url'] = $home_url;

        $site_file = wp_normalize_path( $sites_dir . $blog_id . '.json' );
        if ( 0 !== strpos( $site_file, $cache_dir ) ) {
            self::record_write_failure( 'config.json', 'the per-site config path escaped the cache directory; no file was written.' );
            return false;
        }
        if ( ! self::write_json_file( $site_file, $options ) ) {
            self::record_write_failure( 'config.json', 'the per-site config could not be written to ' . $site_file . '.' );
            return false;
        }

        if ( '' !== $home_url ) {
            self::update_site_index( wp_normalize_path( $cache_dir . 'sites.json' ), $home_url, $blog_id );
        }

        // A stale shared config must not remain: the drop-in falls back to
        // config.json only when there is no index, and leaving one behind would
        // let a single site's settings leak to the whole network.
        $single_file = wp_normalize_path( $cache_dir . 'config.json' );
        if ( file_exists( $single_file ) ) {
            wp_delete_file( $single_file );
        }
        self::remove_legacy_config_files( $cache_dir );

        return true;
    }

    /**
     * Adds or updates one site's entry in the network index.
     *
     * A malformed or missing index is rebuilt from the sites table first, so a
     * single site's write can never silently drop the other sites' entries. Any
     * stale entry that still points at this site's previous home URL is removed,
     * so a changed home URL or domain does not leave the old URL resolving here.
     *
     * @param string $index_file Absolute path to sites.json.
     * @param string $home_url   Untrailingslashed site home URL.
     * @param int    $blog_id    Site id.
     * @return bool
     */
    private static function update_site_index( $index_file, $home_url, $blog_id ) {
        $index_file = wp_normalize_path( $index_file );
        if ( 0 !== strpos( $index_file, wp_normalize_path( FASTLAYER_CACHE_DIR ) ) ) {
            return false;
        }

        $index = self::read_json_file( $index_file );
        if ( ! is_array( $index ) ) {
            // Missing or malformed: rebuild from the sites table instead of
            // starting from an empty array, which would drop every other site.
            self::rebuild_multisite_index();
            $index = self::read_json_file( $index_file );
            if ( ! is_array( $index ) ) {
                $index = array();
            }
        }

        $blog_id = (int) $blog_id;
        $target  = self::home_url_host_path( $home_url );
        $kept    = false;

        foreach ( $index as $existing_home => $existing_id ) {
            if ( (int) $existing_id !== $blog_id ) {
                continue;
            }

            // Same site and same host+path: keep the existing key (it may differ
            // only by scheme, which the drop-in deliberately ignores) instead of
            // adding a duplicate.
            if ( self::home_url_host_path( (string) $existing_home ) === $target ) {
                $kept = true;
                continue;
            }

            // Stale entry for this site's previous home URL.
            unset( $index[ $existing_home ] );
        }

        if ( ! $kept ) {
            $index[ $home_url ] = $blog_id;
        }

        return self::write_json_file( $index_file, $index );
    }

    /**
     * True when the index contains an entry for the given site at its current
     * home URL.
     *
     * The comparison ignores the scheme because the drop-in resolves a request
     * by host and path only, and get_home_url() reports "https" whenever the
     * request is over TLS even if the stored home URL is "http". Comparing the
     * exact URLs would therefore rewrite a perfectly usable index on every
     * scheme change.
     *
     * @param mixed $index   Decoded index, or null when missing/invalid.
     * @param int   $blog_id Site id.
     * @return bool
     */
    private static function index_maps_site( $index, $blog_id ) {
        if ( ! is_array( $index ) || empty( $index ) ) {
            return false;
        }

        $blog_id = (int) $blog_id;
        if ( $blog_id <= 0 ) {
            return false;
        }

        $home = self::site_home_url( $blog_id );
        if ( '' === $home ) {
            // The home URL cannot be read; accept the site only if the index
            // still lists it, so we do not rewrite for an unrelated reason.
            return in_array( $blog_id, array_map( 'intval', $index ), true );
        }

        $target = self::home_url_host_path( $home );
        foreach ( $index as $index_home => $site_id ) {
            if ( (int) $site_id === $blog_id && self::home_url_host_path( (string) $index_home ) === $target ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Host + untrailingslashed path of a URL, used to compare home URLs
     * regardless of scheme.
     *
     * @param string $url URL.
     * @return string
     */
    private static function home_url_host_path( $url ) {
        $parts = wp_parse_url( (string) $url );
        if ( ! is_array( $parts ) ) {
            return '';
        }

        $host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
        $path = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';

        return $host . $path;
    }

    /**
     * Refreshes the static config when a site's home or site URL changes.
     *
     * Hooked to update_option_home / update_option_siteurl. These actions fire
     * in the changed site's own context (update_blog_option() switches to it
     * first), so get_current_blog_id() names the right site. On single-site the
     * same call refreshes config.json, whose trusted-host list is derived from
     * the home and site URLs.
     */
    public function on_home_option_updated() {
        $options = get_option( 'fastlayer_options', array() );
        if ( is_array( $options ) && ! empty( $options ) ) {
            self::write_static_config( $options );
        }
    }

    /**
     * Rebuilds the whole network index from the sites table.
     *
     * Used on network activation, when the index is missing, and after the
     * cache directory has been emptied. Reads each site's home URL with
     * get_home_url() so mapped domains are indexed exactly as WordPress
     * resolves them.
     *
     * @return bool
     */
    private static function rebuild_multisite_index() {
        if ( ! self::is_multisite_install() || ! function_exists( 'get_sites' ) ) {
            return false;
        }

        $index_file = wp_normalize_path( FASTLAYER_CACHE_DIR . 'sites.json' );
        $index      = array();

        foreach ( get_sites( array( 'number' => 0 ) ) as $site ) {
            $blog_id = isset( $site->blog_id ) ? (int) $site->blog_id : 0;
            if ( $blog_id <= 0 ) {
                continue;
            }
            $home = self::site_home_url( $blog_id );
            if ( '' !== $home ) {
                $index[ $home ] = $blog_id;
            }
        }

        if ( empty( $index ) ) {
            return false;
        }

        return self::write_json_file( $index_file, $index );
    }

    /**
     * Adds a newly created site to the network index.
     *
     * Hooked to wp_initialize_site. Without this a site created after network
     * activation would only enter the index on its first front-end request, and
     * until then the main site's index entry would answer for it.
     *
     * @param \WP_Site|int $new_site Site object (wp_initialize_site) or id.
     */
    public function on_initialize_site( $new_site ) {
        if ( ! self::is_multisite_install() ) {
            return;
        }

        if ( is_object( $new_site ) && isset( $new_site->blog_id ) ) {
            $blog_id = (int) $new_site->blog_id;
        } else {
            $blog_id = (int) $new_site;
        }
        if ( $blog_id <= 0 ) {
            return;
        }

        $home = self::site_home_url( $blog_id );
        if ( '' === $home ) {
            return;
        }

        self::update_site_index( wp_normalize_path( FASTLAYER_CACHE_DIR . 'sites.json' ), $home, $blog_id );
    }

    /**
     * Deletes the legacy executable config.php written before 1.1.0.
     *
     * @param string $cache_dir Normalized cache directory (trailing slash).
     */
    private static function remove_legacy_config_files( $cache_dir ) {
        $legacy_config = wp_normalize_path( $cache_dir . 'config.php' );
        if ( file_exists( $legacy_config ) ) {
            wp_delete_file( $legacy_config );
        }
    }

    /**
     * Removes a stale multisite index and per-site files on a single-site
     * install (for example after a multisite network was reduced to one site).
     *
     * @param string $cache_dir Normalized cache directory (trailing slash).
     */
    private static function remove_multisite_artifacts( $cache_dir ) {
        $index_file = wp_normalize_path( $cache_dir . 'sites.json' );
        if ( file_exists( $index_file ) ) {
            wp_delete_file( $index_file );
        }

        $sites_dir = wp_normalize_path( $cache_dir . 'sites/' );
        if ( ! is_dir( $sites_dir ) ) {
            return;
        }

        try {
            $iterator = new \DirectoryIterator( $sites_dir );
        } catch ( \Exception $e ) {
            return;
        }

        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) {
                continue;
            }
            if ( $item->isFile() ) {
                wp_delete_file( $item->getPathname() );
            }
        }

        self::delete_empty_directory( $sites_dir );
    }

    /**
     * True when the install is a multisite network.
     *
     * @return bool
     */
    private static function is_multisite_install() {
        return function_exists( 'is_multisite' ) && is_multisite();
    }

    /**
     * The id of the site currently being rendered, or 1 on single-site.
     *
     * @return int
     */
    private static function current_site_id() {
        if ( function_exists( 'get_current_blog_id' ) ) {
            return (int) get_current_blog_id();
        }
        return 1;
    }

    /**
     * Untrailingslashed home URL for a site.
     *
     * @param int $blog_id Site id.
     * @return string
     */
    private static function site_home_url( $blog_id ) {
        $blog_id = (int) $blog_id;
        if ( $blog_id <= 0 ) {
            return '';
        }

        // get_home_url( $blog_id ) switches to that blog and back even for the
        // current site, which is wasteful on the per-request path. Use home_url()
        // directly when the id is already current. The current id is read into a
        // variable rather than compared inline so the cast is not mistaken for a
        // literal by the Yoda-conditions sniff.
        $current_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        $is_current      = ( $blog_id === $current_blog_id );

        if ( $is_current ) {
            $home = home_url( '/' );
        } else {
            $home = function_exists( 'get_home_url' ) ? get_home_url( $blog_id ) : home_url( '/' );
        }

        if ( ! is_string( $home ) || '' === $home ) {
            return '';
        }

        return untrailingslashit( $home );
    }

    /**
     * Writes an array as JSON and verifies the bytes that landed on disk.
     *
     * @param string $path Absolute target path.
     * @param array  $data Data to encode.
     * @return bool
     */
    private static function write_json_file( $path, $data ) {
        $content = wp_json_encode( $data );
        if ( false === $content ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- the config directory is not reachable through WP_Filesystem in every path that writes it (activation and pre-init); the write is verified byte-for-byte below.
        $written = @file_put_contents( $path, $content, LOCK_EX );
        if ( false === $written || strlen( $content ) !== $written ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- read-back verification of the file just written; WP_Filesystem is not always initialised on this path.
        return file_get_contents( $path ) === $content;
    }

    /**
     * Reads and decodes a JSON file.
     *
     * @param string $path Absolute path.
     * @return array|null Decoded array, or null when missing or invalid.
     */
    private static function read_json_file( $path ) {
        if ( ! file_exists( $path ) ) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the small network index; WP_Filesystem is not initialised on every front-end request.
        $raw = file_get_contents( $path );
        if ( ! is_string( $raw ) || '' === $raw ) {
            return null;
        }

        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Returns the lowercase hostnames that are trusted for cache purposes.
     */
    public static function get_expected_hosts() {
        $hosts = array();
        foreach ( array( home_url( '/' ), site_url( '/' ) ) as $candidate ) {
            $host = wp_parse_url( $candidate, PHP_URL_HOST );
            if ( is_string( $host ) && '' !== $host ) {
                $hosts[] = strtolower( $host );
            }
        }
        return array_values( array_unique( $hosts ) );
    }

    /**
     * Converts an internal URL into an absolute filesystem path.
     *
     * Every module uses this one implementation so a URL is never mapped with
     * the wrong assumption that site_url() equals ABSPATH. The host must belong
     * to this site, the query string and fragment are dropped, and any ".." or
     * NUL byte aborts the conversion. The longest matching base URL (content,
     * includes, admin, plugins, uploads, then the site root) wins, so a custom
     * content directory or a subdirectory install maps to the right folder.
     *
     * @param string $url Absolute or site-relative URL.
     * @return string|false Absolute path, or false when the URL is not a safe
     *                      internal URL.
     */
    public static function url_to_path( $url ) {
        if ( ! is_string( $url ) || '' === $url ) {
            return false;
        }

        $url = esc_url_raw( $url );
        if ( '' === $url ) {
            return false;
        }

        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) || ! isset( $parsed['path'] ) || '' === $parsed['path'] ) {
            return false;
        }

        // A host that is not one of ours is never mapped to a local file.
        if ( ! empty( $parsed['host'] ) && ! self::url_host_is_internal( $url ) ) {
            return false;
        }

        $request_path = rawurldecode( (string) $parsed['path'] );
        if ( self::path_has_traversal( $request_path ) ) {
            return false;
        }

        // No host: resolve a site-relative path against the WordPress root.
        if ( empty( $parsed['host'] ) ) {
            $relative = ltrim( $request_path, '/' );
            if ( '' === $relative ) {
                return false;
            }
            return wp_normalize_path( ABSPATH . $relative );
        }

        $bases = array();

        $register_base = static function ( $base_url, $base_dir ) use ( &$bases ) {
            if ( ! is_string( $base_url ) || '' === $base_url || ! is_string( $base_dir ) || '' === $base_dir ) {
                return;
            }
            $base_path = wp_parse_url( $base_url, PHP_URL_PATH );
            $base_path = is_string( $base_path ) ? untrailingslashit( rawurldecode( $base_path ) ) : '';
            $bases[] = array(
                'path' => $base_path,
                'dir'  => trailingslashit( wp_normalize_path( $base_dir ) ),
            );
        };

        $register_base( content_url(), WP_CONTENT_DIR );
        $register_base( includes_url(), ABSPATH . WPINC );
        $register_base( admin_url(), ABSPATH . 'wp-admin' );
        $register_base( plugins_url(), WP_PLUGIN_DIR );
        $upload_dir = wp_upload_dir();
        if ( empty( $upload_dir['error'] ) ) {
            $register_base( $upload_dir['baseurl'], $upload_dir['basedir'] );
        }
        $register_base( site_url(), ABSPATH );
        $register_base( home_url(), ABSPATH );

        $best = null;
        foreach ( $bases as $candidate ) {
            $base_path = $candidate['path'];
            if ( '' !== $base_path && $request_path !== $base_path && 0 !== strpos( $request_path, $base_path . '/' ) ) {
                continue;
            }
            if ( null === $best || strlen( $base_path ) > strlen( $best['path'] ) ) {
                $best = $candidate;
            }
        }

        if ( null === $best ) {
            return false;
        }

        $relative = ltrim( substr( $request_path, strlen( $best['path'] ) ), '/' );
        if ( '' === $relative ) {
            return untrailingslashit( $best['dir'] );
        }

        return wp_normalize_path( $best['dir'] . $relative );
    }

    /**
     * True when a URL path contains a directory traversal or NUL byte.
     *
     * Backslashes are treated as separators too, so a Windows-style "..\\"
     * segment is rejected as well as the usual "../".
     *
     * @param string $path URL path component.
     * @return bool
     */
    public static function path_has_traversal( $path ) {
        if ( ! is_string( $path ) || '' === $path ) {
            return true;
        }

        if ( false !== strpos( $path, "\0" ) ) {
            return true;
        }

        // Iteratively decode percent-encoding to catch multi-encoded traversal (e.g. %252e) and encoded NULs.
        $decoded_path = $path;
        for ( $i = 0; $i < 3; $i++ ) {
            if ( false === strpos( $decoded_path, '%' ) ) {
                break;
            }
            $next = rawurldecode( $decoded_path );
            if ( $next === $decoded_path ) {
                break;
            }
            $decoded_path = $next;
        }

        if ( false !== strpos( $decoded_path, "\0" ) ) {
            return true;
        }

        $segments = explode( '/', str_replace( '\\', '/', $decoded_path ) );
        foreach ( $segments as $segment ) {
            if ( '..' === $segment ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a URL's host belongs to this WordPress install.
     *
     * @param string $url Full URL.
     * @return bool
     */
    public static function url_host_is_internal( $url ) {
        if ( ! is_string( $url ) || '' === $url ) {
            return false;
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! is_string( $host ) || '' === $host ) {
            return false;
        }

        $host = strtolower( $host );
        foreach ( self::get_expected_hosts() as $expected ) {
            if ( $host === $expected ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keeps the generated static config in sync with the options row.
     *
     * The two hooks pass different signatures:
     *   add_option_fastlayer_options    ( $option, $value )
     *   update_option_fastlayer_options ( $option, $old_value, $value )
     *
     * The third argument therefore holds the new value on the update path,
     * while the second holds it on the add path. Prefer the third argument
     * when it is an array so the update path does not write the stale value.
     */
    public function sync_static_config( $param1, $param2 = null, $param3 = null ) {
        $value = is_array( $param3 ) ? $param3 : $param2;
        if ( is_array( $value ) && ! empty( $value ) ) {
            self::write_static_config( $value );
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'fastlayer_preload_cache' );
        // A batch event can be left pending when the plugin is switched off, so
        // it is cleared here too rather than firing against a disabled plugin.
        wp_clear_scheduled_hook( Page_Cache::PRELOAD_BATCH_HOOK );
        // Also clear the legacy hook names an upgraded install may still have.
        wp_clear_scheduled_hook( 'wp_fastlayer_preload_cache' );
        wp_clear_scheduled_hook( 'wp_fastlayer_preload_batch' );
        self::remove_advanced_cache_dropin();
        self::remove_wp_cache_constant();
        // Clear FastLayer's WebP rewrite directives too: once the plugin is
        // unloaded the init-time self-heal can no longer run, so the WebP
        // rules would otherwise stay active. The call is guarded and
        // best-effort so a deactivation can never fatal.
        if ( class_exists( __NAMESPACE__ . '\\Webp' ) ) {
            Webp::get_instance()->remove_rewrite_rules_on_deactivate();
        }
    }

    /**
     * Determines whether an advanced-cache.php drop-in belongs to FastLayer.
     *
     * Ownership is proven by the FastLayer marker written into the drop-in, by
     * an exact match against the bundled drop-in, or by the drop-in declaring
     * FastLayer's own cache functions. The last signal matters for installs
     * created before the marker existed: without it those drop-ins are mistaken
     * for a foreign plugin's, so they are never refreshed on upgrade and never
     * removed on deactivation. Anything else is treated as foreign and is never
     * modified or deleted.
     */
    public static function is_our_advanced_cache_dropin( $file ) {
        if ( ! is_file( $file ) || ! is_readable( $file ) ) {
            return false;
        }

        $source = FASTLAYER_PATH . 'advanced-cache.php';
        if ( is_readable( $source ) && md5_file( $file ) === md5_file( $source ) ) {
            return true;
        }

        $head = (string) file_get_contents( $file );
        if ( false !== strpos( $head, self::ADVANCED_CACHE_DROPIN_MARKER ) ) {
            return true;
        }

        // A drop-in that declares FastLayer's cache functions is ours, even if
        // it predates the marker (including the legacy wp_fastlayer_* names).
        return false !== strpos( $head, 'function fastlayer_should_skip_cache' )
            || false !== strpos( $head, 'function wp_fastlayer_should_skip_cache' );
    }

    public static function install_advanced_cache_dropin() {
        $source = FASTLAYER_PATH . 'advanced-cache.php';
        $dest   = self::ADVANCED_CACHE_DROPIN;

        if ( ! file_exists( $source ) ) {
            return;
        }

        // Never overwrite a drop-in owned by another caching plugin.
        if ( file_exists( $dest ) && ! self::is_our_advanced_cache_dropin( $dest ) ) {
            \fastlayer_debug_log( 'WP FastLayer: a foreign advanced-cache.php drop-in is installed; leaving it untouched.' );
            return;
        }

        @copy( $source, $dest );
    }

    public static function remove_advanced_cache_dropin() {
        $dest = self::ADVANCED_CACHE_DROPIN;
        if ( file_exists( $dest ) && self::is_our_advanced_cache_dropin( $dest ) ) {
            wp_delete_file( $dest );
        }
    }

    /**
     * Reads wp-config.php and refuses anything that is not safely editable.
     *
     * is_writable() also returns true for a write-only file, so a config that
     * FastLayer may write but not read reaches file_get_contents() as false.
     * That false must never be treated as content: handing it to preg_replace()
     * yields an empty string, which would then be written back over the config
     * and leave the site with a zero-byte wp-config.php. Every read failure is
     * therefore reported and the file is left untouched.
     *
     * @param string $config_file Absolute path to wp-config.php.
     * @return string|false Contents, or false when the file must not be touched.
     */
    private static function read_wp_config( $config_file ) {
        if ( ! is_file( $config_file ) ) {
            self::record_write_failure( 'wp-config.php', 'the file does not exist; it was not modified.' );
            return false;
        }

        if ( ! is_readable( $config_file ) ) {
            self::record_write_failure( 'wp-config.php', 'the file is not readable, so it was not modified.' );
            return false;
        }

        if ( ! wp_is_writable( $config_file ) ) {
            self::record_write_failure( 'wp-config.php', 'the file is not writable, so it was not modified.' );
            return false;
        }

        $content = file_get_contents( $config_file );
        if ( ! is_string( $content ) || '' === trim( $content ) ) {
            self::record_write_failure( 'wp-config.php', 'the file could not be read, or is empty; it was not modified.' );
            return false;
        }

        if ( 0 !== strpos( $content, '<?php' ) ) {
            self::record_write_failure( 'wp-config.php', 'the file does not start with "<?php" and was left untouched.' );
            return false;
        }

        return $content;
    }

    /**
     * Copies wp-config.php to a temporary file outside the web root before it is
     * modified, and returns the path that was written.
     *
     * The copy exists only for the duration of the write and is deleted again by
     * write_wp_config() once the result has been verified. If the write cannot be
     * completed the copy is kept and its path is reported, so the original can be
     * restored by hand. It is placed in the system temp directory rather than
     * next to wp-config.php because it contains database credentials and salts,
     * and anything in the document root may be served as plain text.
     *
     * @param string $config_file Absolute path to wp-config.php.
     * @param string $content     Current contents of wp-config.php.
     * @return string|false Absolute path of the verified backup, or false.
     */
    private static function backup_wp_config( $config_file, $content ) {
        $temp_dir = sys_get_temp_dir();
        if ( ! is_string( $temp_dir ) || '' === trim( $temp_dir ) ) {
            self::record_write_failure( 'wp-config.php', 'the system temp directory could not be determined, so no backup was made and the file was not modified.' );
            return false;
        }

        $backup = rtrim( $temp_dir, '/\\' ) . DIRECTORY_SEPARATOR
            . 'wp-fastlayer-wp-config-' . substr( md5( $config_file . wp_rand() ), 0, 12 ) . '.bak';

        $written = file_put_contents( $backup, $content, LOCK_EX );
        if ( false === $written || strlen( $content ) !== $written ) {
            wp_delete_file( $backup );
            self::record_write_failure( 'wp-config.php', 'the backup could not be written to ' . $backup . ', so the file was not modified.' );
            return false;
        }

        $verify = file_get_contents( $backup );
        if ( ! is_string( $verify ) || $verify !== $content ) {
            wp_delete_file( $backup );
            self::record_write_failure( 'wp-config.php', 'the backup at ' . $backup . ' could not be verified, so the file was not modified.' );
            return false;
        }

        return $backup;
    }

    /**
     * Replaces wp-config.php atomically.
     *
     * The new content is staged next to the original and read back before it is
     * moved into place, so the original file is never opened for writing. A
     * failed or partial write can therefore not leave wp-config.php empty or
     * truncated, and a failed rename leaves the original exactly as it was.
     *
     * @param string $config_file Absolute path to wp-config.php.
     * @param string $content     Full contents to write.
     * @param string $backup      Path of the backup created for this change.
     * @return bool True when the file on disk matches $content.
     */
    private static function write_wp_config( $config_file, $content, $backup ) {
        // Staged in the same directory so that rename() stays within one
        // filesystem and is therefore atomic.
        $staged = $config_file . '.fastlayer-staged';

        $written = file_put_contents( $staged, $content, LOCK_EX );
        if ( false === $written || strlen( $content ) !== $written ) {
            wp_delete_file( $staged );
            self::record_write_failure( 'wp-config.php', 'the updated file could not be staged, so wp-config.php was left unchanged.' );
            return false;
        }

        $verify = file_get_contents( $staged );
        if ( ! is_string( $verify ) || $verify !== $content ) {
            wp_delete_file( $staged );
            self::record_write_failure( 'wp-config.php', 'the staged file did not match the intended content, so wp-config.php was left unchanged.' );
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Must stay atomic: WP_Filesystem::move() can fall back to a non-atomic copy+delete that could truncate wp-config.php; the staged file is already verified above.
        if ( ! @rename( $staged, $config_file ) ) {
            wp_delete_file( $staged );
            self::record_write_failure( 'wp-config.php', 'the staged file could not be moved into place, so wp-config.php was left unchanged.' );
            return false;
        }

        $final = file_get_contents( $config_file );
        if ( ! is_string( $final ) || $final !== $content ) {
            self::record_write_failure( 'wp-config.php', 'the file on disk does not match what was written. Restore the original from ' . $backup . '.' );
            return false;
        }

        // Only now is the backup redundant.
        wp_delete_file( $backup );
        return true;
    }

    public static function add_wp_cache_constant() {
        if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
            return;
        }

        $config_file = ABSPATH . 'wp-config.php';
        $content = self::read_wp_config( $config_file );
        if ( false === $content ) {
            return;
        }

        // A WP_CACHE define that is already present belongs to WordPress core,
        // to a manual edit, or to another caching plugin. Never rewrite it.
        if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,/', $content ) ) {
            return;
        }

        $marker = "/* That's all, stop editing! Happy publishing. */";
        $pos    = strpos( $content, $marker );
        if ( false === $pos ) {
            self::record_write_failure( 'wp-config.php', 'the "' . $marker . '" comment was not found, so WP_CACHE was not added. Add define( \'WP_CACHE\', true ); above that comment manually.' );
            return;
        }

        $define = "define( 'WP_CACHE', true ); // " . self::WP_CACHE_OWNER_MARKER . "\n\n";
        $new_content = substr_replace( $content, $define, $pos, 0 );

        $backup = self::backup_wp_config( $config_file, $content );
        if ( false === $backup ) {
            return;
        }

        self::write_wp_config( $config_file, $new_content, $backup );
    }

    /**
     * Removes the WP_CACHE constant, but only the exact definition that
     * FastLayer inserted itself.
     *
     * The ownership marker is mandatory in the pattern, so a WP_CACHE define
     * written by WordPress core, a manual edit, or another caching plugin is
     * never matched and never removed. Installs created before the marker
     * existed therefore leave their constant in place, which is the safe
     * outcome: the constant is not ours to delete.
     */
    public static function remove_wp_cache_constant() {
        $config_file = ABSPATH . 'wp-config.php';
        $content = self::read_wp_config( $config_file );
        if ( false === $content ) {
            return;
        }

        $pattern = '/\r?\n[ \t]*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;[^\n]*'
            . preg_quote( self::WP_CACHE_OWNER_MARKER, '/' ) . '[^\n]*(\r?\n)*/';
        $new_content = preg_replace( $pattern, "\n", $content );
        if ( null === $new_content || $new_content === $content ) {
            return;
        }

        // A removal must never leave a config that WordPress cannot boot from.
        if ( '' === trim( $new_content ) || 0 !== strpos( $new_content, '<?php' ) ) {
            self::record_write_failure( 'wp-config.php', 'the WP_CACHE definition was not removed because the result would not have been a usable config file.' );
            return;
        }

        $backup = self::backup_wp_config( $config_file, $content );
        if ( false === $backup ) {
            return;
        }

        self::write_wp_config( $config_file, $new_content, $backup );
    }

    /**
     * Blocks direct web access to a directory FastLayer owns.
     *
     * The page cache files and the debug log are only ever read from PHP, never
     * requested by a browser, so the whole directory can be denied outright. The
     * index.php stops directory listings on any server that runs PHP, and it is
     * the only protection available under nginx or IIS, which never read
     * .htaccess. On Apache and LiteSpeed the .htaccess closes the gap for
     * requests that do not resolve to index.php.
     *
     * The server directives are wrapped in <IfModule> guards because a
     * directive the running server does not understand raises a 500, which
     * would be worse than the exposure it prevents. mod_authz_core carries
     * "Require" on Apache 2.4, and the legacy "Order"/"Deny" pair applies only
     * when it is absent on Apache 2.2.
     */
    private static function ensure_directory_protection( $dir ) {
        $dir = trailingslashit( wp_normalize_path( $dir ) );
        if ( '' === $dir || ! is_dir( $dir ) ) {
            return;
        }

        $index_file = $dir . 'index.php';
        if ( ! file_exists( $index_file ) ) {
            @file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
        }

        $htaccess_file = $dir . '.htaccess';
        $marker = 'WP FastLayer protected directory';
        $rules = "# {$marker}. Denies direct web access; these files are read from PHP only.\n"
            . "<IfModule mod_authz_core.c>\n"
            . "Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "Order allow,deny\n"
            . "Deny from all\n"
            . "</IfModule>\n";

        $current = file_exists( $htaccess_file ) ? (string) file_get_contents( $htaccess_file ) : '';
        $legacy_rules = "Deny from all\nRequire all denied\n";

        // Write when missing, refresh when the file is FastLayer's own (either
        // carrying the marker or the exact legacy string written before), and
        // leave a hand-edited .htaccess untouched.
        $is_ours = ( '' === $current || $legacy_rules === $current || false !== strpos( $current, $marker ) );
        if ( $is_ours && $current !== $rules ) {
            @file_put_contents( $htaccess_file, $rules, LOCK_EX );
        }
    }

    private function create_cache_directory() {
        $cache_dir = FASTLAYER_CACHE_DIR;
        if ( ! file_exists( $cache_dir ) ) {
            wp_mkdir_p( $cache_dir );
        }

        self::ensure_directory_protection( $cache_dir );
    }

    /**
     * Determines whether a filesystem path is located outside the public web root.
     *
     * Validates against both ABSPATH and DOCUMENT_ROOT (when available) so that
     * logs are never placed in a web-accessible directory.
     *
     * @param string $path Target filesystem path.
     * @return bool True if the path is confirmed to be outside the web root.
     */
    public static function is_path_outside_webroot( $path ) {
        $path = wp_normalize_path( untrailingslashit( (string) $path ) );
        if ( '' === $path ) {
            return false;
        }

        $abspath = wp_normalize_path( untrailingslashit( ABSPATH ) );
        if ( '' !== $abspath && ( 0 === strpos( $path, $abspath . '/' ) || $path === $abspath ) ) {
            return false;
        }

        if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) {
            $raw_doc_root = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
            $doc_root     = wp_normalize_path( untrailingslashit( $raw_doc_root ) );
            if ( '' !== $doc_root && ( 0 === strpos( $path, $doc_root . '/' ) || $path === $doc_root ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolves the filesystem path for performance.log.
     *
     * Prefers a directory located outside the public web root (ABSPATH and DOCUMENT_ROOT).
     * If no secure writable directory outside the web root can be established, fails closed
     * by returning false so that no log file is created in a public directory.
     *
     * @return string|false Absolute path to the log file, or false if no secure location exists.
     */
    public function get_log_file() {
        if ( null !== $this->log_file ) {
            return $this->log_file;
        }

        $candidates = array();

        if ( defined( 'FASTLAYER_LOG_DIR' ) && is_string( FASTLAYER_LOG_DIR ) && '' !== FASTLAYER_LOG_DIR ) {
            $candidates[] = wp_normalize_path( FASTLAYER_LOG_DIR );
        }

        $abspath = wp_normalize_path( untrailingslashit( ABSPATH ) );
        $parent_abspath = dirname( $abspath );
        if ( '' !== $parent_abspath && $parent_abspath !== $abspath ) {
            $candidates[] = $parent_abspath . '/fastlayer-logs';
        }

        if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) {
            $raw_doc_root    = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
            $doc_root        = wp_normalize_path( untrailingslashit( $raw_doc_root ) );
            $parent_doc_root = dirname( $doc_root );
            if ( '' !== $parent_doc_root && $parent_doc_root !== $doc_root ) {
                $candidates[] = $parent_doc_root . '/fastlayer-logs';
            }
        }

        foreach ( array_unique( $candidates ) as $candidate_dir ) {
            if ( ! self::is_path_outside_webroot( $candidate_dir ) ) {
                continue;
            }

            $candidate_dir = trailingslashit( $candidate_dir );

            if ( ! file_exists( $candidate_dir ) ) {
                if ( ! @wp_mkdir_p( $candidate_dir ) ) {
                    continue;
                }
            }

            if ( is_dir( $candidate_dir ) && wp_is_writable( $candidate_dir ) ) {
                self::ensure_directory_protection( $candidate_dir );
                $this->log_file = $candidate_dir . 'performance.log';
                return $this->log_file;
            }
        }

        // Fail-closed: No secure writable directory outside the public web root could be established.
        // File logging is disabled rather than exposing logs in the public document root.
        $this->log_file = false;
        return false;
    }

    /**
     * Returns an initialised WP_Filesystem instance, or false when unavailable.
     *
     * Initialised once per request and cached, because WP_Filesystem() performs
     * a write test on every call.
     *
     * @return \WP_Filesystem_Base|false
     */
    private static function get_filesystem() {
        static $filesystem = null;

        if ( null !== $filesystem ) {
            return $filesystem;
        }

        $filesystem = false;
        global $wp_filesystem;

        if ( ! ( $wp_filesystem instanceof \WP_Filesystem_Base ) ) {
            if ( ! function_exists( 'WP_Filesystem' ) ) {
                $filesystem_file = ABSPATH . 'wp-admin/includes/file.php';
                if ( ! file_exists( $filesystem_file ) ) {
                    return $filesystem;
                }
                require_once $filesystem_file;
            }

            if ( ! WP_Filesystem() ) {
                return $filesystem;
            }
        }

        if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
            $filesystem = $wp_filesystem;
        }

        return $filesystem;
    }

    /**
     * Removes an empty directory through the WordPress filesystem API.
     *
     * rmdir() is not called directly: WP_Filesystem is the supported filesystem
     * abstraction (and what the WordPress.org Plugin Check expects). When the
     * filesystem cannot be initialised the directory is left in place rather
     * than falling back to a raw PHP call.
     *
     * @param string $directory Absolute path to the (empty) directory.
     * @return bool True when the directory was removed.
     */
    public static function delete_empty_directory( $directory ) {
        $directory = wp_normalize_path( (string) $directory );
        if ( '' === $directory || ! is_dir( $directory ) ) {
            return false;
        }

        $filesystem = self::get_filesystem();
        if ( false === $filesystem ) {
            return false;
        }

        return (bool) $filesystem->rmdir( $directory );
    }

    private function create_logs_directory() {
        $log_file = $this->get_log_file();
        if ( false !== $log_file && '' !== $log_file ) {
            if ( ! file_exists( $log_file ) ) {
                @file_put_contents( $log_file, '' );
            }
        }

        // Actively clean up any legacy public log files in uploads/ to prevent direct HTTP access on Nginx.
        $upload_dir = wp_upload_dir();
        $legacy_upload_log_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-logs/';
        if ( is_dir( $legacy_upload_log_dir ) ) {
            $legacy_upload_log = $legacy_upload_log_dir . 'performance.log';
            if ( file_exists( $legacy_upload_log ) ) {
                wp_delete_file( $legacy_upload_log );
            }
            foreach ( array( '.htaccess', 'index.php' ) as $protection_file ) {
                $p_file = $legacy_upload_log_dir . $protection_file;
                if ( file_exists( $p_file ) ) {
                    wp_delete_file( $p_file );
                }
            }
            self::delete_empty_directory( $legacy_upload_log_dir );
        }

        $old_log_dir = FASTLAYER_PATH . 'logs/';
        if ( is_dir( $old_log_dir ) ) {
            $old_log_file = $old_log_dir . 'performance.log';
            if ( file_exists( $old_log_file ) ) {
                wp_delete_file( $old_log_file );
            }
            self::delete_empty_directory( $old_log_dir );
        }
    }

    public function log( $message, $type = 'info' ) {
        if ( ! $this->is_debug_mode ) return;

        $log_file = $this->get_log_file();
        if ( false === $log_file || '' === $log_file ) {
            // Fail-closed: File logging disabled because no secure location outside web root exists.
            // Falls back to standard WordPress debug logging if enabled.
            \fastlayer_debug_log( sprintf( '[WP FastLayer] [%s] %s', strtoupper( $type ), $message ) );
            return;
        }

        $timestamp = current_time( 'Y-m-d H:i:s' );
        $log_entry = sprintf( '[%s] [%s] %s', $timestamp, strtoupper( $type ), $message ) . PHP_EOL;

        @file_put_contents( $log_file, $log_entry, FILE_APPEND );
    }

    public function get_logs() {
        $log_file = $this->get_log_file();
        if ( false === $log_file || '' === $log_file || ! file_exists( $log_file ) ) return array();

        $logs = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        return is_array( $logs ) ? array_reverse( $logs ) : array();
    }

    public function clear_logs() {
        $log_file = $this->get_log_file();
        if ( false !== $log_file && '' !== $log_file && file_exists( $log_file ) ) {
            wp_delete_file( $log_file );
        }
    }
}
