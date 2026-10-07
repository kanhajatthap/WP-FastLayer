<?php
namespace WP_FastLayer;
if ( ! defined( 'ABSPATH' ) ) exit;

class Bootstrap {
    private static $instance = null;
    private $log_file;
    private $is_debug_mode;
    const ADVANCED_CACHE_DROPIN = WP_CONTENT_DIR . '/advanced-cache.php';
    const ADVANCED_CACHE_DROPIN_MARKER = 'wp-fastlayer:advanced-cache-dropin';
    const WP_CACHE_OWNER_MARKER = 'wp-fastlayer:owned-wp-cache';
    const WRITE_FAILURE_TRANSIENT = 'wp_fastlayer_write_failures';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->is_debug_mode = $this->is_debug_enabled();

        add_action( 'init', array( $this, 'init' ) );
        register_activation_hook( WP_FASTLAYER_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( WP_FASTLAYER_FILE, array( $this, 'deactivate' ) );

        add_action( 'update_option_wp_fastlayer_options', array( $this, 'sync_static_config' ), 10, 3 );
        add_action( 'add_option_wp_fastlayer_options', array( $this, 'sync_static_config' ), 10, 2 );

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
        \wp_fastlayer_debug_log( 'WP FastLayer: ' . $context . ' write failure: ' . $message );

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
            echo '<div class="notice notice-error wpfastlayer-notice"><p><strong>FastLayer:</strong> Cache directory is not writable. Please check permissions for: ' . esc_html( WP_FASTLAYER_CACHE_DIR ) . '</p></div>';
        }

        if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
            $dropin_installed = file_exists( self::ADVANCED_CACHE_DROPIN );
            if ( $dropin_installed ) {
                echo '<div class="notice notice-warning wpfastlayer-notice is-dismissible"><p><strong>FastLayer:</strong> The page cache drop-in is installed but <code>WP_CACHE</code> is not set to <code>true</code> in <code>wp-config.php</code>. Add the following line above the "stop editing" comment: <code>define( \'WP_CACHE\', true );</code></p></div>';
            }
        }
    }

    private function is_cache_directory_writable() {
        $cache_dir = WP_FASTLAYER_CACHE_DIR;
        if ( ! file_exists( $cache_dir ) ) {
            return wp_mkdir_p( $cache_dir );
        }
        return is_writable( $cache_dir );
    }

    private function is_debug_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['debug_mode'] ) && $options['debug_mode'] === '1';
    }

    private function load_modules() {
        $modules = array(
            'Page_Cache',
            'Optimizer',
            'Database_Optimization',
            'Admin_Bar',
            'Webp',
        );

        foreach ( $modules as $module ) {
            $fqcn = 'WP_FastLayer\\' . $module;
            if ( class_exists( $fqcn ) ) {
                try {
                    $fqcn::get_instance();
                } catch ( \Throwable $e ) {
                    error_log( 'WP FastLayer: failed to load module ' . $module . ' — ' . $e->getMessage() );
                }
            }
        }

        if ( ! class_exists( 'WP_FastLayer\\Admin' ) ) {
            $admin_file = WP_FASTLAYER_PATH . 'includes/class-admin.php';
            if ( file_exists( $admin_file ) ) {
                require_once $admin_file;
                \wp_fastlayer_debug_log( 'WP FastLayer: required class-admin.php as fallback.' );
            }
        }

        if ( class_exists( 'WP_FastLayer\\Admin' ) ) {
            \wp_fastlayer_debug_log( 'WP FastLayer: Admin class exists before instantiation.' );
            try {
                Admin::get_instance();
                \wp_fastlayer_debug_log( 'WP FastLayer: Admin class instantiated successfully.' );
            } catch ( \Throwable $e ) {
                error_log( 'WP FastLayer: failed to load Admin — ' . $e->getMessage() );
            }
        } else {
            \wp_fastlayer_debug_log( 'WP FastLayer: Admin class does not exist at load_modules.' );
        }
    }

    public function init() {
        try {
            $this->create_cache_directory();
            $this->create_logs_directory();
            $this->reset_options_if_needed();
            $this->load_modules();
        } catch ( \Throwable $e ) {
            error_log( 'WP FastLayer init error: ' . $e->getMessage() );
        }
    }

    private function reset_options_if_needed() {
        $options = get_option( 'wp_fastlayer_options', array() );

        if ( ! is_array( $options ) || empty( $options ) ) {
            $this->set_default_options();
            return;
        }

        $default_options = self::get_default_options();
        $normalized_options = wp_parse_args( $options, $default_options );
        $normalized_options['_version'] = WP_FASTLAYER_VERSION;

        if ( $normalized_options !== $options ) {
            update_option( 'wp_fastlayer_options', $normalized_options );
        }
    }

    public function activate() {
        $this->create_cache_directory();
        $this->create_logs_directory();
        $this->set_default_options( get_option( 'wp_fastlayer_options', array() ) );
        if ( ! wp_next_scheduled( 'wp_fastlayer_preload_cache' ) ) {
            wp_schedule_event( time(), 'hourly', 'wp_fastlayer_preload_cache' );
        }
        self::install_advanced_cache_dropin();
        self::add_wp_cache_constant();
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
            '_version' => WP_FASTLAYER_VERSION
        );
    }

    private function set_default_options( $existing_options = array() ) {
        $options = wp_parse_args( is_array( $existing_options ) ? $existing_options : array(), self::get_default_options() );
        $options['_version'] = WP_FASTLAYER_VERSION;

        update_option( 'wp_fastlayer_options', $options );
        self::write_static_config( $options );
    }

    public static function write_static_config( $options ) {
        $cache_dir = WP_FASTLAYER_CACHE_DIR;
        if ( ! file_exists( $cache_dir ) ) {
            wp_mkdir_p( $cache_dir );
        }

        self::ensure_directory_protection( $cache_dir );

        $options = is_array( $options ) ? $options : array();

        // The advanced-cache.php drop-in runs before WordPress is loaded, so it
        // cannot call home_url() itself. Store the canonical hosts here so the
        // drop-in can validate the client-supplied HTTP_HOST before it is used
        // in a cache key. This key exists only in the generated config file; it
        // is never written back to the wp_fastlayer_options database row.
        $options['_home_host'] = self::get_expected_hosts();

        $config_file = $cache_dir . 'config.php';
        $content = "<?php\n// WP FastLayer static configuration file. Generated on " . date('Y-m-d H:i:s') . "\nreturn " . var_export( $options, true ) . ";\n";
        @file_put_contents( $config_file, $content, LOCK_EX );
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
     * Keeps the generated static config in sync with the options row.
     *
     * The two hooks pass different signatures:
     *   add_option_wp_fastlayer_options    ( $option, $value )
     *   update_option_wp_fastlayer_options ( $option, $old_value, $value )
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
        wp_clear_scheduled_hook( 'wp_fastlayer_preload_cache' );
        // A batch event can be left pending when the plugin is switched off, so
        // it is cleared here too rather than firing against a disabled plugin.
        wp_clear_scheduled_hook( Page_Cache::PRELOAD_BATCH_HOOK );
        self::remove_advanced_cache_dropin();
        self::remove_wp_cache_constant();
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

        $source = WP_FASTLAYER_PATH . 'advanced-cache.php';
        if ( is_readable( $source ) && md5_file( $file ) === md5_file( $source ) ) {
            return true;
        }

        $head = (string) file_get_contents( $file );
        if ( false !== strpos( $head, self::ADVANCED_CACHE_DROPIN_MARKER ) ) {
            return true;
        }

        // A drop-in that declares FastLayer's cache functions is ours, even if
        // it predates the marker.
        return false !== strpos( $head, 'function wp_fastlayer_should_skip_cache' );
    }

    public static function install_advanced_cache_dropin() {
        $source = WP_FASTLAYER_PATH . 'advanced-cache.php';
        $dest   = self::ADVANCED_CACHE_DROPIN;

        if ( ! file_exists( $source ) ) {
            return;
        }

        // Never overwrite a drop-in owned by another caching plugin.
        if ( file_exists( $dest ) && ! self::is_our_advanced_cache_dropin( $dest ) ) {
            \wp_fastlayer_debug_log( 'WP FastLayer: a foreign advanced-cache.php drop-in is installed; leaving it untouched.' );
            return;
        }

        @copy( $source, $dest );
    }

    public static function remove_advanced_cache_dropin() {
        $dest = self::ADVANCED_CACHE_DROPIN;
        if ( file_exists( $dest ) && self::is_our_advanced_cache_dropin( $dest ) ) {
            @unlink( $dest );
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

        if ( ! is_writable( $config_file ) ) {
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
            @unlink( $backup );
            self::record_write_failure( 'wp-config.php', 'the backup could not be written to ' . $backup . ', so the file was not modified.' );
            return false;
        }

        $verify = file_get_contents( $backup );
        if ( ! is_string( $verify ) || $verify !== $content ) {
            @unlink( $backup );
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
            @unlink( $staged );
            self::record_write_failure( 'wp-config.php', 'the updated file could not be staged, so wp-config.php was left unchanged.' );
            return false;
        }

        $verify = file_get_contents( $staged );
        if ( ! is_string( $verify ) || $verify !== $content ) {
            @unlink( $staged );
            self::record_write_failure( 'wp-config.php', 'the staged file did not match the intended content, so wp-config.php was left unchanged.' );
            return false;
        }

        if ( ! @rename( $staged, $config_file ) ) {
            @unlink( $staged );
            self::record_write_failure( 'wp-config.php', 'the staged file could not be moved into place, so wp-config.php was left unchanged.' );
            return false;
        }

        $final = file_get_contents( $config_file );
        if ( ! is_string( $final ) || $final !== $content ) {
            self::record_write_failure( 'wp-config.php', 'the file on disk does not match what was written. Restore the original from ' . $backup . '.' );
            return false;
        }

        // Only now is the backup redundant.
        @unlink( $backup );
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
        $cache_dir = WP_FASTLAYER_CACHE_DIR;
        if ( ! file_exists( $cache_dir ) ) {
            wp_mkdir_p( $cache_dir );
        }

        self::ensure_directory_protection( $cache_dir );
    }

    private function get_log_file() {
        if ( null === $this->log_file ) {
            $upload_dir = wp_upload_dir();
            $log_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-logs/';
            if ( ! file_exists( $log_dir ) ) {
                wp_mkdir_p( $log_dir );
            }
            self::ensure_directory_protection( $log_dir );
            $this->log_file = $log_dir . 'performance.log';
        }
        return $this->log_file;
    }

    private function create_logs_directory() {
        $this->get_log_file();

        $log_file = $this->log_file;
        if ( ! file_exists( $log_file ) ) {
            @file_put_contents( $log_file, '' );
        }

        $old_log_dir = WP_FASTLAYER_PATH . 'logs/';
        if ( is_dir( $old_log_dir ) ) {
            $old_log_file = $old_log_dir . 'performance.log';
            if ( file_exists( $old_log_file ) ) {
                @unlink( $old_log_file );
            }
            @rmdir( $old_log_dir );
        }
    }

    public function log( $message, $type = 'info' ) {
        if ( ! $this->is_debug_mode ) return;

        $log_file = $this->get_log_file();
        $timestamp = current_time( 'Y-m-d H:i:s' );
        $log_entry = sprintf( '[%s] [%s] %s', $timestamp, strtoupper( $type ), $message ) . PHP_EOL;

        @file_put_contents( $log_file, $log_entry, FILE_APPEND );
    }

    public function get_logs() {
        $log_file = $this->get_log_file();
        if ( ! file_exists( $log_file ) ) return array();

        $logs = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        return array_reverse( $logs );
    }

    public function clear_logs() {
        $log_file = $this->get_log_file();
        if ( file_exists( $log_file ) ) {
            @unlink( $log_file );
        }
    }
}
