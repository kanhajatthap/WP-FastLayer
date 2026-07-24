<?php
namespace WP_FastLayer;
if ( ! defined( 'ABSPATH' ) ) exit;

class Bootstrap {
    private static $instance = null;
    private $log_file;
    private $is_debug_mode;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $log_dir = WP_FASTLAYER_PATH . 'logs/';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        $this->log_file = $log_dir . 'performance.log';
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

    public function admin_notices() {
        if ( ! $this->is_cache_directory_writable() ) {
            echo '<div class="notice notice-error wpfastlayer-notice"><p><strong>WP FastLayer:</strong> Cache directory is not writable. Please check permissions for: ' . esc_html( WP_FASTLAYER_CACHE_DIR ) . '</p></div>';
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
        $config_file = $cache_dir . 'config.php';
        $content = "<?php\n// WP FastLayer static configuration file. Generated on " . date('Y-m-d H:i:s') . "\nreturn " . var_export( $options, true ) . ";\n";
        @file_put_contents( $config_file, $content, LOCK_EX );
    }

    public function sync_static_config( $param1, $param2 = null ) {
        $value = $param2;
        if ( is_array( $value ) && ! empty( $value ) ) {
            self::write_static_config( $value );
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'wp_fastlayer_preload_cache' );
    }

    private function create_cache_directory() {
        $cache_dir = WP_FASTLAYER_CACHE_DIR;
        if ( ! file_exists( $cache_dir ) ) {
            wp_mkdir_p( $cache_dir );
            file_put_contents( $cache_dir . '.htaccess', "Deny from all\nRequire all denied\n" );
        }
    }

    private function create_logs_directory() {
        $log_dir = WP_FASTLAYER_PATH . 'logs/';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        $log_file = $log_dir . 'performance.log';
        if ( ! file_exists( $log_file ) ) {
            file_put_contents( $log_file, '' );
        }
    }

    public function log( $message, $type = 'info' ) {
        if ( ! $this->is_debug_mode ) return;

        $timestamp = current_time( 'Y-m-d H:i:s' );
        $log_entry = sprintf( '[%s] [%s] %s', $timestamp, strtoupper( $type ), $message ) . PHP_EOL;

        file_put_contents( $this->log_file, $log_entry, FILE_APPEND );
    }

    public function get_logs() {
        if ( ! file_exists( $this->log_file ) ) return array();

        $logs = file( $this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        return array_reverse( $logs );
    }

    public function clear_logs() {
        if ( file_exists( $this->log_file ) ) {
            @unlink( $this->log_file );
        }
    }
}
