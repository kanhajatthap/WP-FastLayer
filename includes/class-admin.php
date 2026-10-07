<?php
namespace WP_FastLayer;
if ( ! defined( 'ABSPATH' ) ) exit;

class Admin {
    private static $instance = null;
    private $option_name = 'wp_fastlayer_options';

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( WP_FASTLAYER_FILE ), array( $this, 'add_plugin_action_links' ) );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( 'WP FastLayer: Admin constructor running, admin_menu hook added.' );
        }
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_wp_fastlayer_clear_cache', array( $this, 'clear_cache' ) );
        add_action( 'admin_post_wp_fastlayer_cache_debug_test', array( $this, 'handle_cache_debug_test' ) );
        add_action( 'admin_post_wp_fastlayer_manual_preload', array( $this, 'manual_preload' ) );
        add_action( 'admin_post_wp_fastlayer_test_imagekit', array( $this, 'test_imagekit_connection' ) );
        add_action( 'admin_post_wp_fastlayer_generate_image_debug_report', array( $this, 'generate_image_debug_report' ) );
        add_action( 'admin_post_wp_fastlayer_download_image_debug_report', array( $this, 'download_image_debug_report' ) );
        add_action( 'admin_post_wp_fastlayer_generate_critical_css', array( $this, 'generate_critical_css' ) );
        add_action( 'admin_post_wp_fastlayer_bulk_webp', array( $this, 'bulk_generate_webp' ) );
        add_action( 'wp_ajax_wp_fastlayer_bulk_webp_batch', array( $this, 'ajax_bulk_generate_webp_batch' ) );
        add_action( 'wp_ajax_wp_fastlayer_webp_summary', array( $this, 'ajax_get_webp_summary' ) );
        add_action( 'wp_ajax_wp_fastlayer_webp_last_run', array( $this, 'ajax_save_webp_last_run' ) );
        add_action( 'wp_ajax_wp_fastlayer_preload_status', array( $this, 'ajax_get_preload_status' ) );
        add_action( 'current_screen', array( $this, 'setup_admin_notice_suppression' ), 1 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function add_menu_page() {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( 'WP FastLayer: add_menu_page called.' );
        }
        add_menu_page( __( 'FastLayer', 'fastlayer' ), __( 'FastLayer', 'fastlayer' ), 'manage_options', 'wp-fastlayer', array( $this, 'render_dashboard' ), 'dashicons-performance', 100 );
        add_submenu_page( 'wp-fastlayer', __( 'Dashboard', 'fastlayer' ), __( 'Dashboard', 'fastlayer' ), 'manage_options', 'wp-fastlayer', array( $this, 'render_dashboard' ) );
        add_submenu_page( 'wp-fastlayer', __( 'Cache', 'fastlayer' ), __( 'Cache', 'fastlayer' ), 'manage_options', 'wp-fastlayer-cache', array( $this, 'render_cache_page' ) );
        add_submenu_page( 'wp-fastlayer', __( 'HTML Optimization', 'fastlayer' ), __( 'HTML Optimization', 'fastlayer' ), 'manage_options', 'wp-fastlayer-html', array( $this, 'render_html_page' ) );
        add_submenu_page( 'wp-fastlayer', __( 'CSS Optimization', 'fastlayer' ), __( 'CSS Optimization', 'fastlayer' ), 'manage_options', 'wp-fastlayer-css', array( $this, 'render_css_page' ) );
        add_submenu_page( 'wp-fastlayer', __( 'JS Optimization', 'fastlayer' ), __( 'JS Optimization', 'fastlayer' ), 'manage_options', 'wp-fastlayer-js', array( $this, 'render_js_page' ) );
        add_submenu_page( 'wp-fastlayer', __( 'Media', 'fastlayer' ), __( 'Media', 'fastlayer' ), 'manage_options', 'wp-fastlayer-media', array( $this, 'render_media_page' ) );
        add_submenu_page( 'wp-fastlayer', __( 'CDN', 'fastlayer' ), __( 'CDN', 'fastlayer' ), 'manage_options', 'wp-fastlayer-cdn', array( $this, 'render_cdn_page' ) );
        add_submenu_page( 'wp-fastlayer', __( 'Database', 'fastlayer' ), __( 'Database', 'fastlayer' ), 'manage_options', 'wp-fastlayer-database', array( $this, 'render_database_page' ) );
        add_submenu_page( 'wp-fastlayer', __( 'Advanced Rules', 'fastlayer' ), __( 'Advanced Rules', 'fastlayer' ), 'manage_options', 'wp-fastlayer-advanced', array( $this, 'render_advanced_page' ) );
    }

    public function register_settings() {
        register_setting( $this->option_name, $this->option_name, array( $this, 'sanitize_options' ) );
    }

    public function setup_admin_notice_suppression( $screen ) {
        if ( ! $this->is_wp_fastlayer_admin_screen( $screen ) ) {
            return;
        }

        // Use CSS instead of removing notice callbacks so that only third-party
        // admin notices are hidden on WP FastLayer screens.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_notice_scope_css' ) );
    }

    public function enqueue_admin_notice_scope_css() {
        wp_register_style( 'wp-fastlayer-admin-notice-scope', false, array(), WP_FASTLAYER_VERSION );
        wp_enqueue_style( 'wp-fastlayer-admin-notice-scope' );
        wp_add_inline_style(
            'wp-fastlayer-admin-notice-scope',
            '.wrap .notice:not(.wpfastlayer-notice), .wrap .update-nag, .wrap .notice-warning:not(.wpfastlayer-notice), .wrap .notice-info:not(.wpfastlayer-notice) { display: none !important; }'
        );
    }

    private function is_wp_fastlayer_admin_screen( $screen ) {
        if ( ! is_object( $screen ) ) {
            $screen = get_current_screen();
        }

        if ( ! $screen || empty( $screen->id ) ) {
            return false;
        }

        $screen_id = (string) $screen->id;
        $screen_base = isset( $screen->base ) ? (string) $screen->base : '';

        return false !== strpos( $screen_id, 'wp-fastlayer' ) || false !== strpos( $screen_base, 'wp-fastlayer' );
    }

    /**
     * Matches the admin_enqueue_scripts hook suffix used by the FastLayer screens.
     *
     * WordPress derives the hook suffix of a submenu page from the sanitized
     * menu title of its parent (wp-admin/menu.php), so a page registered under
     * the "wp-fastlayer" slug but titled "FastLayer" resolves to
     * "fastlayer_page_<slug>" rather than "wp-fastlayer_page_<slug>". Both the
     * legacy and the current forms are accepted so the localized admin script
     * data is always emitted on FastLayer screens.
     */
    private function is_wp_fastlayer_admin_hook( $hook ) {
        $hook = is_string( $hook ) ? $hook : '';

        if ( '' === $hook ) {
            return false;
        }

        $accepted_prefixes = array(
            'toplevel_page_wp-fastlayer',
            'wp-fastlayer_page_',
            'toplevel_page_fastlayer',
            'fastlayer_page_',
        );

        foreach ( $accepted_prefixes as $prefix ) {
            if ( 0 === strpos( $hook, $prefix ) ) {
                return true;
            }
        }

        return false;
    }


    public function add_plugin_action_links( $links ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return $links;
        }

        $settings_link = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url( admin_url( 'admin.php?page=wp-fastlayer' ) ),
            esc_html__( 'Settings', 'fastlayer' )
        );

        array_unshift( $links, $settings_link );
        return $links;
    }

    public function handle_cache_debug_test() {
        check_admin_referer( 'wp_fastlayer_cache_debug_test_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        $cache_dir = WP_FASTLAYER_CACHE_DIR;
        $result = array(
            'cache_dir' => wp_normalize_path( $cache_dir ),
            'writable' => false,
            'write' => 'not_attempted',
            'read' => 'not_attempted',
            'delete' => 'not_attempted',
            'detail' => '',
            'timestamp' => time(),
        );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( 'WP FastLayer: cache debug test started. dir=' . wp_normalize_path( $cache_dir ) );
        }

        if ( ! is_dir( $cache_dir ) ) {
            $result['detail'] = 'Cache directory does not exist.';
        } else {
            $result['writable'] = is_writable( $cache_dir );
            $test_file = trailingslashit( wp_normalize_path( $cache_dir ) ) . '_admin_cache_debug_' . wp_rand() . '.tmp';
            $written = @file_put_contents( $test_file, 'wp-fastlayer-admin-debug', LOCK_EX );
            $result['write'] = false === $written ? 'failed' : 'success';
            $result['detail'] = 'write_bytes=' . ( false === $written ? '0' : $written );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( 'WP FastLayer: cache debug test write result=' . $result['write'] . ' ; file=' . basename( $test_file ) . ' ; dir=' . wp_normalize_path( $cache_dir ) );
            }
            if ( false !== $written && file_exists( $test_file ) ) {
                $result['read'] = @file_get_contents( $test_file ) === 'wp-fastlayer-admin-debug' ? 'success' : 'mismatch';
                $result['delete'] = @unlink( $test_file ) ? 'success' : 'failed';
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    \wp_fastlayer_debug_log( 'WP FastLayer: cache debug test read=' . $result['read'] . ' ; delete=' . $result['delete'] );
                }
            }
        }

        set_transient( 'wp_fastlayer_cache_debug_test_result', $result, 300 );

        $redirect_url = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wp-fastlayer-cache' );
        $redirect_url = add_query_arg( 'cache_debug_test', '1', $redirect_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    public function sanitize_options( $input ) {
        $defaults = Bootstrap::get_default_options();
        $existing_options = wp_parse_args( get_option( $this->option_name, array() ), $defaults );
        $sanitized = $existing_options;
        $input = is_array( $input ) ? $input : array();

        // Debug logging: capture raw POST for wp_fastlayer_options (only when WP_DEBUG is enabled).
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Settings API callback; nonce verified by WordPress core.
            if ( isset( $_POST['wp_fastlayer_options'] ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Settings API callback; nonce verified by WordPress core.
                \wp_fastlayer_debug_log( 'CSS OPTION INPUT: ' . print_r( $_POST['wp_fastlayer_options'], true ) );
            } else {
                \wp_fastlayer_debug_log( 'CSS OPTION INPUT: (none)' );
            }
        }

        $checkbox_fields = array(
            'enable_cache', 'enable_preload', 'enable_minify', 'enable_minify_css', 'enable_minify_js',
            'enable_lazyload', 'enable_lazyload_iframes', 'enable_lazyload_videos', 'enable_smart_lcp_optimization', 'enable_lcp_preload', 'enable_lcp_hero_background', 'enable_lcp_debug_mode', 'enable_webp', 'enable_bulk_webp_generation', 'enable_image_delivery',
            'enable_progressive_image_loading', 'enable_lqip_placeholders',
            'enable_css_combine', 'enable_css_remove_comments', 'enable_css_remove_unused', 'enable_smart_gutenberg_css_optimization', 'enable_google_fonts_display', 'enable_css_inline', 'enable_css_delivery',
            'enable_critical_css',
            'enable_html_remove_comments', 'enable_html_remove_whitespace', 'enable_html_preserve_gutenberg_comments',
            'enable_js_combine', 'enable_js_remove_comments', 'enable_js_defer', 'enable_js_async', 'enable_js_safe_mode', 'enable_js_smart_delay', 'enable_js_smart_delay_debug', 'debug_mode',
            'enable_cdn', 'enable_imagekit_cdn', 'imagekit_auto_quality', 'imagekit_auto_format', 'imagekit_runtime_correction', 'imagekit_debug_overlay',
            'db_clean_post_revisions', 'db_clean_trashed_posts', 'db_clean_spam_comments',
            'db_clean_auto_drafts', 'db_clean_all_transients', 'db_clean_duplicated_postmeta',
            'db_clean_duplicated_commentmeta', 'db_clean_duplicated_usermeta', 'db_clean_duplicated_termmeta',
            'db_clean_expired_transients', 'db_clean_oembed_cache', 'db_clean_orphaned_postmeta',
            'db_clean_orphaned_usermeta', 'db_clean_orphaned_termmeta', 'db_clean_orphaned_commentmeta'
        );

        $keys_to_compare = array(
            'enable_minify',
            'enable_html_remove_comments',
            'enable_html_remove_whitespace',
            'enable_html_preserve_gutenberg_comments',
            'enable_minify_css',
            'enable_css_remove_comments',
            'enable_css_remove_unused',
            'enable_google_fonts_display',
            'enable_css_inline',
            'enable_css_delivery',
            'enable_critical_css',
            'enable_js_defer',
            'enable_js_smart_delay',
            'enable_js_async',
            'enable_js_combine',
            'enable_cache',
            'enable_cdn',
            'enable_webp',
            'enable_image_delivery',
            'enable_progressive_image_loading',
            'enable_lqip_placeholders',
            'lqip_quality',
            'lqip_width',
            'lqip_blur_intensity',
            'lqip_fade_duration',
            'enable_smart_lcp_optimization',
            'enable_lcp_preload',
        );

        foreach ( $checkbox_fields as $field ) {
            if ( array_key_exists( $field, $input ) ) {
                $sanitized[$field] = '1' === (string) $input[ $field ] ? '1' : '0';
            }
        }

        if ( isset( $sanitized['enable_js_defer'] ) && '1' === $sanitized['enable_js_defer'] ) {
            $sanitized['enable_js_combine'] = '0';
        }

        if ( isset( $sanitized['enable_js_smart_delay'] ) && '1' === $sanitized['enable_js_smart_delay'] ) {
            $sanitized['enable_js_async'] = '0';
            $sanitized['enable_js_combine'] = '0';
        }

        $sanitized['cache_expiration'] = isset( $input['cache_expiration'] ) ? absint( $input['cache_expiration'] ) : ( isset( $existing_options['cache_expiration'] ) ? $existing_options['cache_expiration'] : 10 );
        $sanitized['exclude_urls'] = isset( $input['exclude_urls'] ) ? sanitize_textarea_field( $input['exclude_urls'] ) : ( isset( $existing_options['exclude_urls'] ) ? $existing_options['exclude_urls'] : '' );
        $sanitized['exclude_cookies'] = isset( $input['exclude_cookies'] ) ? sanitize_textarea_field( $input['exclude_cookies'] ) : ( isset( $existing_options['exclude_cookies'] ) ? $existing_options['exclude_cookies'] : '' );
        $sanitized['exclude_user_agents'] = isset( $input['exclude_user_agents'] ) ? sanitize_textarea_field( $input['exclude_user_agents'] ) : ( isset( $existing_options['exclude_user_agents'] ) ? $existing_options['exclude_user_agents'] : '' );
        $sanitized['exclude_css_files'] = isset( $input['exclude_css_files'] ) ? sanitize_textarea_field( $input['exclude_css_files'] ) : ( isset( $existing_options['exclude_css_files'] ) ? $existing_options['exclude_css_files'] : '' );
        $sanitized['exclude_js_files'] = isset( $input['exclude_js_files'] ) ? sanitize_textarea_field( $input['exclude_js_files'] ) : ( isset( $existing_options['exclude_js_files'] ) ? $existing_options['exclude_js_files'] : '' );
        $sanitized['exclude_js_defer'] = isset( $input['exclude_js_defer'] ) ? sanitize_textarea_field( $input['exclude_js_defer'] ) : ( isset( $existing_options['exclude_js_defer'] ) ? $existing_options['exclude_js_defer'] : '' );
        $timeout_input = isset( $input['smart_js_delay_timeout'] ) ? absint( $input['smart_js_delay_timeout'] ) : ( isset( $existing_options['smart_js_delay_timeout'] ) ? absint( $existing_options['smart_js_delay_timeout'] ) : 6000 );
        $sanitized['smart_js_delay_timeout'] = (string) max( 1000, min( 15000, $timeout_input ) );
        $sanitized['smart_js_delay_exclusions'] = isset( $input['smart_js_delay_exclusions'] ) ? sanitize_textarea_field( $input['smart_js_delay_exclusions'] ) : ( isset( $existing_options['smart_js_delay_exclusions'] ) ? $existing_options['smart_js_delay_exclusions'] : '' );
        $sanitized['critical_css_custom'] = isset( $input['critical_css_custom'] ) ? wp_kses_post( $input['critical_css_custom'] ) : ( isset( $existing_options['critical_css_custom'] ) ? $existing_options['critical_css_custom'] : '' );
        $sanitized['cdn_cname'] = isset( $input['cdn_cname'] ) ? esc_url_raw( $input['cdn_cname'] ) : ( isset( $existing_options['cdn_cname'] ) ? $existing_options['cdn_cname'] : '' );
        $sanitized['imagekit_endpoint'] = isset( $input['imagekit_endpoint'] ) ? esc_url_raw( trim( $input['imagekit_endpoint'] ) ) : ( isset( $existing_options['imagekit_endpoint'] ) ? $existing_options['imagekit_endpoint'] : '' );
        $sanitized['imagekit_origin_path_mode'] = isset( $input['imagekit_origin_path_mode'] ) ? sanitize_key( $input['imagekit_origin_path_mode'] ) : ( isset( $existing_options['imagekit_origin_path_mode'] ) ? $existing_options['imagekit_origin_path_mode'] : 'full_uploads_path' );
        if ( ! in_array( $sanitized['imagekit_origin_path_mode'], array( 'full_uploads_path', 'uploads_relative_path' ), true ) ) {
            $sanitized['imagekit_origin_path_mode'] = 'full_uploads_path';
        }
        $sanitized['imagekit_auto_format_mode'] = isset( $input['imagekit_auto_format_mode'] ) ? sanitize_key( $input['imagekit_auto_format_mode'] ) : ( isset( $existing_options['imagekit_auto_format_mode'] ) ? $existing_options['imagekit_auto_format_mode'] : 'f-auto' );
        if ( ! in_array( $sanitized['imagekit_auto_format_mode'], array( 'f-auto', 'fm-auto' ), true ) ) {
            $sanitized['imagekit_auto_format_mode'] = 'f-auto';
        }
        $ratio_input = isset( $input['imagekit_max_oversize_ratio'] ) ? (float) $input['imagekit_max_oversize_ratio'] : ( isset( $existing_options['imagekit_max_oversize_ratio'] ) ? (float) $existing_options['imagekit_max_oversize_ratio'] : 1.5 );
        $sanitized['imagekit_max_oversize_ratio'] = (string) round( max( 1.0, min( 3.0, $ratio_input ) ), 2 );
        $sanitized['webp_quality'] = isset( $input['webp_quality'] ) ? min( 100, max( 50, absint( $input['webp_quality'] ) ) ) : ( isset( $existing_options['webp_quality'] ) ? absint( $existing_options['webp_quality'] ) : 80 );
        $sanitized['enable_progressive_image_loading'] = isset( $input['enable_progressive_image_loading'] ) ? '1' === (string) $input['enable_progressive_image_loading'] ? '1' : '0' : ( isset( $existing_options['enable_progressive_image_loading'] ) ? $existing_options['enable_progressive_image_loading'] : '0' );
        $sanitized['enable_lqip_placeholders'] = isset( $input['enable_lqip_placeholders'] ) ? '1' === (string) $input['enable_lqip_placeholders'] ? '1' : '0' : ( isset( $existing_options['enable_lqip_placeholders'] ) ? $existing_options['enable_lqip_placeholders'] : '1' );
        $sanitized['lqip_quality'] = isset( $input['lqip_quality'] ) ? min( 50, max( 1, absint( $input['lqip_quality'] ) ) ) : ( isset( $existing_options['lqip_quality'] ) ? absint( $existing_options['lqip_quality'] ) : 25 );
        $sanitized['lqip_width'] = isset( $input['lqip_width'] ) ? min( 80, max( 20, absint( $input['lqip_width'] ) ) ) : ( isset( $existing_options['lqip_width'] ) ? absint( $existing_options['lqip_width'] ) : 40 );
        $sanitized['lqip_blur_intensity'] = isset( $input['lqip_blur_intensity'] ) ? min( 50, max( 0, absint( $input['lqip_blur_intensity'] ) ) ) : ( isset( $existing_options['lqip_blur_intensity'] ) ? absint( $existing_options['lqip_blur_intensity'] ) : 20 );
        $sanitized['lqip_fade_duration'] = isset( $input['lqip_fade_duration'] ) ? min( 1000, max( 0, absint( $input['lqip_fade_duration'] ) ) ) : ( isset( $existing_options['lqip_fade_duration'] ) ? absint( $existing_options['lqip_fade_duration'] ) : 250 );
        $sanitized['enable_html_remove_comments'] = isset( $input['enable_html_remove_comments'] ) ? '1' === (string) $input['enable_html_remove_comments'] ? '1' : '0' : ( isset( $existing_options['enable_html_remove_comments'] ) ? $existing_options['enable_html_remove_comments'] : '1' );
        $sanitized['enable_html_remove_whitespace'] = isset( $input['enable_html_remove_whitespace'] ) ? '1' === (string) $input['enable_html_remove_whitespace'] ? '1' : '0' : ( isset( $existing_options['enable_html_remove_whitespace'] ) ? $existing_options['enable_html_remove_whitespace'] : '1' );
        $sanitized['enable_html_preserve_gutenberg_comments'] = isset( $input['enable_html_preserve_gutenberg_comments'] ) ? '1' === (string) $input['enable_html_preserve_gutenberg_comments'] ? '1' : '0' : ( isset( $existing_options['enable_html_preserve_gutenberg_comments'] ) ? $existing_options['enable_html_preserve_gutenberg_comments'] : '1' );
        $sanitized['enable_css_remove_unused'] = isset( $input['enable_css_remove_unused'] ) ? '1' === (string) $input['enable_css_remove_unused'] ? '1' : '0' : ( isset( $existing_options['enable_css_remove_unused'] ) ? $existing_options['enable_css_remove_unused'] : '0' );
        $sanitized['enable_google_fonts_display'] = isset( $input['enable_google_fonts_display'] ) ? '1' === (string) $input['enable_google_fonts_display'] ? '1' : '0' : ( isset( $existing_options['enable_google_fonts_display'] ) ? $existing_options['enable_google_fonts_display'] : ( isset( $existing_options['enable_font_display_swap'] ) ? $existing_options['enable_font_display_swap'] : '1' ) );
        $sanitized['_version'] = WP_FASTLAYER_VERSION;

        if ( $this->should_clear_cache_on_option_change( $existing_options, $sanitized, $keys_to_compare ) ) {
            Page_Cache::get_instance()->clear_all_cache();
        }

        // Debug logging: capture sanitized array before returning (only when WP_DEBUG is enabled).
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( 'CSS OPTION SANITIZED: ' . print_r( $sanitized, true ) );
        }

        Bootstrap::write_static_config( $sanitized );

        return $sanitized;
    }

    private function should_clear_cache_on_option_change( $existing_options, $new_options, $keys ) {
        foreach ( $keys as $key ) {
            $old_value = isset( $existing_options[ $key ] ) ? $existing_options[ $key ] : '0';
            $new_value = isset( $new_options[ $key ] ) ? $new_options[ $key ] : '0';
            if ( (string) $old_value !== (string) $new_value ) {
                return true;
            }
        }

        return false;
    }

    private function render_toggle_input( $field, $value, $class = '', $attributes = '' ) {
        $name = sprintf( '%s[%s]', $this->option_name, $field );
        $high_risk = $this->is_high_risk_setting( $field );
        ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
        <input type="checkbox"
            name="<?php echo esc_attr( $name ); ?>"
            value="1"
            <?php checked( $value ); ?>
            data-high-risk="<?php echo esc_attr( $high_risk ? '1' : '0' ); ?>"
            data-high-risk-field="<?php echo esc_attr( $field ); ?>"
            data-high-risk-label="<?php echo esc_attr( $high_risk ? $this->get_high_risk_feature_map()[ $field ]['label'] : '' ); ?>"
            data-high-risk-summary="<?php echo esc_attr( $high_risk ? $this->get_high_risk_feature_map()[ $field ]['summary'] : '' ); ?>"
            data-original-value="<?php echo esc_attr( $value ? '1' : '0' ); ?>"
            <?php echo $class ? ' class="' . esc_attr( $class ) . '"' : ''; ?>
            <?php echo wp_kses_post( $attributes ); ?>
        >
        
        <?php
    }

    private function is_high_risk_setting( $field ) {
        $high_risk_features = $this->get_high_risk_feature_map();
        return isset( $high_risk_features[ $field ] );
    }

    private function get_high_risk_feature_map() {
        return array(
            'enable_css_combine' => array(
                'label' => __( 'Combine CSS Files', 'fastlayer' ),
                'summary' => __( 'Combining CSS files can change CSS execution order and may affect layout or dynamic styling.', 'fastlayer' ),
            ),
            'enable_css_delivery' => array(
                'label' => __( 'Optimize CSS Delivery', 'fastlayer' ),
                'summary' => __( 'Optimizing CSS delivery can affect how styles are loaded and may impact theme or page rendering.', 'fastlayer' ),
            ),
            'enable_critical_css' => array(
                'label' => __( 'Inline Critical CSS', 'fastlayer' ),
                'summary' => __( 'Inlined critical CSS can improve first paint but may alter how page styles are applied.', 'fastlayer' ),
            ),
            'enable_js_combine' => array(
                'label' => __( 'Combine JavaScript Files', 'fastlayer' ),
                'summary' => __( 'Combining JavaScript may delay or change execution order and can affect scripts, widgets, and page behavior.', 'fastlayer' ),
            ),
            'enable_js_defer' => array(
                'label' => __( 'Defer JavaScript Execution', 'fastlayer' ),
                'summary' => __( 'Deferring JavaScript may prevent scripts from running at the expected time, affecting layout, sliders, or forms.', 'fastlayer' ),
            ),
            'enable_js_smart_delay' => array(
                'label' => __( 'Enable Smart JS Delay', 'fastlayer' ),
                'summary' => __( 'Delaying scripts until user interaction can improve performance but may impact third-party integrations and dynamic content.', 'fastlayer' ),
            ),
            'enable_lazyload' => array(
                'label' => __( 'Enable Lazyload', 'fastlayer' ),
                'summary' => __( 'Lazyloading images can impact how media is displayed and may affect carousels, galleries, or Elementor layouts.', 'fastlayer' ),
            ),
            'enable_lazyload_iframes' => array(
                'label' => __( 'Lazyload iframes', 'fastlayer' ),
                'summary' => __( 'Lazyloading iframes may delay third-party embeds and affect interactive content or tracking scripts.', 'fastlayer' ),
            ),
            'enable_lazyload_videos' => array(
                'label' => __( 'Lazyload videos', 'fastlayer' ),
                'summary' => __( 'Lazyloading videos can alter playback timing and may change how media is presented on pages.', 'fastlayer' ),
            ),
        );
    }

    private function get_newly_enabled_high_risk_features( $existing_options, $sanitized_options ) {
        $newly_enabled = array();
        foreach ( $this->get_high_risk_feature_map() as $field => $meta ) {
            $old_value = isset( $existing_options[ $field ] ) ? (string) $existing_options[ $field ] : '0';
            $new_value = isset( $sanitized_options[ $field ] ) ? (string) $sanitized_options[ $field ] : '0';
            if ( '0' === $old_value && '1' === $new_value ) {
                $newly_enabled[ $field ] = $meta;
            }
        }
        return $newly_enabled;
    }

    private function record_high_risk_confirmation_log( $existing_options, $sanitized_options, $newly_enabled_features ) {
        $log = isset( $existing_options['high_risk_confirmation_log'] ) && is_array( $existing_options['high_risk_confirmation_log'] ) ? $existing_options['high_risk_confirmation_log'] : array();
        $current_user = wp_get_current_user();
        $user_name = is_object( $current_user ) && ! empty( $current_user->user_login ) ? $current_user->user_login : '';
        $timestamp = current_time( 'mysql' );
        foreach ( $newly_enabled_features as $field => $meta ) {
            $log[ $field ] = array(
                'label' => $meta['label'],
                'enabled_by' => $user_name,
                'enabled_at' => $timestamp,
            );
        }
        return $log;
    }

    private function get_high_risk_architecture_support() {
        return array(
            'safe_mode' => false,
            'emergency_recovery_mode' => false,
            'rollback_system' => false,
            'last_known_working_config' => false,
        );
    }


    private function is_bulk_webp_generation_enabled( $options = null ) {
        if ( ! is_array( $options ) ) {
            $options = get_option( $this->option_name, array() );
        }

        return ! isset( $options['enable_bulk_webp_generation'] ) || '1' === (string) $options['enable_bulk_webp_generation'];
    }
    private function render_help_icon( $text ) {
        return sprintf(
            '<span class="wpfl-help-icon" title="%s" aria-label="Help">?</span>',
            esc_attr( $text )
        );
    }

    private function get_admin_tabs() {
        return array(
            '' => array(
                'wp-fastlayer' => array( __( 'Dashboard', 'fastlayer' ), 'dashicons-dashboard' ),
                'wp-fastlayer-cache' => array( __( 'Cache', 'fastlayer' ), 'dashicons-image-filter' ),
                'wp-fastlayer-html' => array( __( 'HTML', 'fastlayer' ), 'dashicons-editor-code' ),
                'wp-fastlayer-css' => array( __( 'CSS', 'fastlayer' ), 'dashicons-admin-appearance' ),
                'wp-fastlayer-js' => array( __( 'JS', 'fastlayer' ), 'dashicons-admin-page' ),
                'wp-fastlayer-media' => array( __( 'Media', 'fastlayer' ), 'dashicons-format-image' ),
                'wp-fastlayer-cdn' => array( __( 'CDN', 'fastlayer' ), 'dashicons-cloud' ),
                'wp-fastlayer-database' => array( __( 'Database', 'fastlayer' ), 'dashicons-database' ),
                'wp-fastlayer-advanced' => array( __( 'Advanced', 'fastlayer' ), 'dashicons-admin-tools' ),
            ),
        );
    }

    private function render_page_navigation() {
        $current = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'wp-fastlayer';
        $groups = $this->get_admin_tabs();

        echo '<nav class="wpfl-page-navigation" aria-label="' . esc_attr__( 'FastLayer navigation', 'fastlayer' ) . '">';
        foreach ( $groups as $group_label => $tabs ) {
            echo '<div class="wpfl-nav-group">';
            echo '<div class="wpfl-nav-group-title">' . esc_html( $group_label ) . '</div>';
            foreach ( $tabs as $slug => $tab_data ) {
                $label = isset( $tab_data[0] ) ? $tab_data[0] : '';
                $icon = isset( $tab_data[1] ) ? $tab_data[1] : 'dashicons-admin-generic';
                $class = $slug === $current ? ' wpfl-page-navigation-item-active' : '';
                echo '<a class="wpfl-page-navigation-item' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">';
                echo '<span class="wpfl-nav-icon dashicons ' . esc_attr( $icon ) . '"></span>';
                echo '<span class="wpfl-nav-label">' . esc_html( $label ) . '</span>';
                echo '</a>';
            }
            echo '</div>';
        }
        echo '</nav>';
    }

    private function render_save_bar() {
        echo '<div class="wpfl-savebar">';
        submit_button( __( 'Save Changes', 'fastlayer' ), 'primary', 'submit', true, array( 'class' => 'wpfl-submit-btn' ) );
        echo '</div>';
    }

    private function render_page_header( $title, $description = '', $help_url = '' ) {
        ?>
        <div class="wpfl-header">
            <div class="wpfl-logo">
                <span class="dashicons dashicons-performance"></span>
                <div>
                    <h1><?php echo esc_html( $title ); ?></h1>
                    <?php if ( $description ) : ?>
                        <p class="wpfl-page-description"><?php echo esc_html( $description ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="wpfl-header-action" title="Quick settings">
                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
            </button>
            <?php if ( defined( 'WP_FASTLAYER_VERSION' ) ) : ?>
                <div class="wpfl-version"><?php echo esc_html__( 'v', 'fastlayer' ) . esc_html( WP_FASTLAYER_VERSION ); ?></div>
            <?php endif; ?>
        </div>
        <?php
        $this->render_page_navigation();
        $this->render_settings_saved_notice();
    }

    private function render_settings_saved_notice() {
        if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
            echo '<div class="notice notice-success wpfastlayer-notice is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'fastlayer' ) . '</p></div>';
        }

        if ( function_exists( 'settings_errors' ) ) {
            settings_errors( $this->option_name );
        }

        $imagekit_notice = get_transient( 'wp_fastlayer_imagekit_test_notice' );
        if ( is_array( $imagekit_notice ) && ! empty( $imagekit_notice['message'] ) ) {
            $notice_class = ( isset( $imagekit_notice['type'] ) && 'success' === $imagekit_notice['type'] ) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr( $notice_class ) . ' wpfastlayer-notice is-dismissible"><p>' . esc_html( $imagekit_notice['message'] ) . '</p></div>';
            delete_transient( 'wp_fastlayer_imagekit_test_notice' );
        }
    }

    public function clear_cache( $redirect_url = '' ) {
        check_admin_referer( 'wp_fastlayer_clear_cache_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        $cache_stats_before = $this->get_cache_stats();
        $this->log_cache_clear( 'Cache clear started.', $cache_stats_before );

        $invalidation = Page_Cache::get_instance();
        $invalidation->clear_all_cache();
        $minify_stats = $this->clear_minify_cache();
        $preload_stats = $this->clear_preload_cache_artifacts();

        if ( function_exists( 'delete_transient' ) ) {
            \delete_transient( 'wp_fastlayer_cache_debug_trace' );
        }

        $cache_stats_after = $this->get_cache_stats();
        $this->log_cache_clear( 'Cache clear completed.', $cache_stats_after, $minify_stats, $preload_stats );

        wp_safe_redirect( $this->get_safe_redirect_url( $redirect_url, admin_url( 'admin.php?page=wp-fastlayer&cache=cleared' ) ) );
        exit;
    }

    public function manual_preload( $redirect_url = '' ) {
        check_admin_referer( 'wp_fastlayer_preload_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        $preload = Page_Cache::get_instance();
        $status  = $preload->preload_cache( 'manual' );

        if ( function_exists( 'delete_transient' ) ) {
            \delete_transient( 'wp_fastlayer_cache_debug_trace' );
        }

        wp_safe_redirect( $this->get_safe_redirect_url( $redirect_url, admin_url( 'admin.php?page=wp-fastlayer&preload=' . rawurlencode( $status ) ) ) );
        exit;
    }

    /**
     * Reports the live preload job state so the progress shown in the admin
     * reflects the job that is actually running rather than the moment the
     * request that started it returned.
     */
    public function ajax_get_preload_status() {
        check_ajax_referer( 'wp_fastlayer_preload_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to view the preload status.', 'fastlayer' ) ), 403 );
        }

        wp_send_json_success( Page_Cache::get_instance()->get_preload_state() );
    }

    public function test_imagekit_connection() {
        check_admin_referer( 'wp_fastlayer_test_imagekit_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        $options  = get_option( $this->option_name, array() );
        $endpoint = isset( $options['imagekit_endpoint'] ) ? trim( (string) $options['imagekit_endpoint'] ) : '';

        if ( '' === $endpoint ) {
            set_transient(
                'wp_fastlayer_imagekit_test_notice',
                array(
                    'type'    => 'error',
                    'message' => __( 'ImageKit test failed: Endpoint is missing. Please enter your ImageKit URL Endpoint first.', 'fastlayer' ),
                ),
                120
            );

            wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer-cdn' ) );
            exit;
        }

        if ( ! $this->is_valid_imagekit_endpoint( $endpoint ) ) {
            set_transient(
                'wp_fastlayer_imagekit_test_notice',
                array(
                    'type'    => 'error',
                    'message' => __( 'ImageKit test failed: The endpoint format looks invalid. Use a full URL like https://ik.imagekit.io/your_endpoint', 'fastlayer' ),
                ),
                120
            );

            wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer-cdn' ) );
            exit;
        }

        $test_url = $this->build_imagekit_test_url( $endpoint, $options );

        if ( ! $this->is_permitted_imagekit_request_url( $test_url ) ) {
            set_transient(
                'wp_fastlayer_imagekit_test_notice',
                array(
                    'type'    => 'error',
                    'message' => __( 'ImageKit test failed: The endpoint format looks invalid. Use a full URL like https://ik.imagekit.io/your_endpoint', 'fastlayer' ),
                ),
                120
            );

            wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer-cdn' ) );
            exit;
        }

        $response = wp_safe_remote_head(
            $test_url,
            array(
                'timeout'     => 8,
                'redirection' => 2,
                'headers'     => array(
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $response = wp_safe_remote_get(
                $test_url,
                array(
                'timeout'     => 8,
                'redirection' => 2,
                'headers'     => array(
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                ),
            )
        );
        }

        if ( is_wp_error( $response ) ) {
            set_transient(
                'wp_fastlayer_imagekit_test_notice',
                array(
                    'type'    => 'error',
                    'message' => sprintf(
                        /* translators: %s: WP error from remote request. */
                        __( 'ImageKit test failed: %s', 'fastlayer' ),
                        $response->get_error_message()
                    ),
                ),
                120
            );
        } else {
            $code = (int) wp_remote_retrieve_response_code( $response );
            $mode = $this->get_imagekit_origin_path_mode( $options );

            if ( $code >= 200 && $code < 500 ) {
                set_transient(
                    'wp_fastlayer_imagekit_test_notice',
                    array(
                        'type'    => 'success',
                        'message' => sprintf(
                            /* translators: 1: rewrite mode, 2: tested URL, 3: HTTP code. */
                            __( 'ImageKit test successful (%1$s): %2$s (HTTP %3$d).', 'fastlayer' ),
                            $mode,
                            $test_url,
                            $code
                        ),
                    ),
                    120
                );
            } else {
                set_transient(
                    'wp_fastlayer_imagekit_test_notice',
                    array(
                        'type'    => 'error',
                        'message' => sprintf(
                            /* translators: 1: rewrite mode, 2: tested URL, 3: HTTP code. */
                            __( 'ImageKit test failed (%1$s): %2$s returned HTTP %3$d.', 'fastlayer' ),
                            $mode,
                            $test_url,
                            $code
                        ),
                    ),
                    120
                );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer-cdn' ) );
        exit;
    }

    public function generate_image_debug_report() {
        check_admin_referer( 'wp_fastlayer_generate_image_debug_report_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        $samples = get_option( 'wp_fastlayer_image_runtime_debug_samples', array() );
        $samples = is_array( $samples ) ? $samples : array();

        $report = $this->build_image_runtime_debug_report( $samples );
        set_transient( 'wp_fastlayer_image_debug_report_payload', $report, 1800 );

        wp_safe_redirect( add_query_arg( 'image_debug_report', 'generated', admin_url( 'admin.php?page=wp-fastlayer-cdn' ) ) );
        exit;
    }

    public function download_image_debug_report() {
        check_admin_referer( 'wp_fastlayer_download_image_debug_report_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        $report = get_transient( 'wp_fastlayer_image_debug_report_payload' );
        if ( ! is_array( $report ) ) {
            $samples = get_option( 'wp_fastlayer_image_runtime_debug_samples', array() );
            $report = $this->build_image_runtime_debug_report( is_array( $samples ) ? $samples : array() );
        }

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=wp-fastlayer-responsive-image-debug-report.json' );

        echo wp_json_encode( $report, JSON_PRETTY_PRINT );
        exit;
    }

    private function build_image_runtime_debug_report( array $samples ) {
        $summary = array(
            'total_samples' => count( $samples ),
            'oversized_images' => 0,
            'runtime_corrections' => 0,
            'max_oversize_ratio' => 0,
            'avg_oversize_ratio' => 0,
        );

        $ratio_total = 0.0;

        foreach ( $samples as $sample ) {
            if ( ! is_array( $sample ) ) {
                continue;
            }

            $ratio = isset( $sample['oversizeRatio'] ) ? (float) $sample['oversizeRatio'] : 0.0;
            $ratio_total += $ratio;

            if ( $ratio > 1.5 ) {
                $summary['oversized_images']++;
            }

            if ( ! empty( $sample['runtimeCorrectionApplied'] ) ) {
                $summary['runtime_corrections']++;
            }

            if ( $ratio > $summary['max_oversize_ratio'] ) {
                $summary['max_oversize_ratio'] = round( $ratio, 3 );
            }
        }

        if ( $summary['total_samples'] > 0 ) {
            $summary['avg_oversize_ratio'] = round( $ratio_total / $summary['total_samples'], 3 );
        }

        return array(
            'generated_at' => current_time( 'mysql' ),
            'generated_timestamp' => time(),
            'summary' => $summary,
            'samples' => array_values( $samples ),
        );
    }

    private function is_valid_imagekit_endpoint( $endpoint ) {
        $endpoint = trim( (string) $endpoint );

        if ( '' === $endpoint ) {
            return false;
        }

        if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
            $endpoint = 'https://' . ltrim( $endpoint, '/' );
        }

        if ( ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
            return false;
        }

        $parts = wp_parse_url( $endpoint );

        if ( empty( $parts['host'] ) ) {
            return false;
        }

        return false !== filter_var( $parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );
    }

    private function is_permitted_imagekit_request_url( $url ) {
        $url = trim( (string) $url );

        if ( '' === $url ) {
            return false;
        }

        $parsed = wp_parse_url( $url );

        if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
            return false;
        }

        if ( ! in_array( strtolower( (string) $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }

        if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
            return false;
        }

        $host = strtolower( (string) $parsed['host'] );

        if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        if ( 'imagekit.io' !== $host && '.imagekit.io' !== substr( $host, -12 ) ) {
            return false;
        }

        return false !== filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );
    }

    private function build_imagekit_test_url( $endpoint, $options ) {
        $endpoint = trim( (string) $endpoint );

        if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
            $endpoint = 'https://' . ltrim( $endpoint, '/' );
        }

        $endpoint         = untrailingslashit( $endpoint );
        $original_test_url = '';

        $attachment = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'post_status'    => 'inherit',
                'fields'         => 'ids',
            )
        );

        if ( ! empty( $attachment ) ) {
            $attachment_url = $this->get_local_upload_attachment_url( (int) $attachment[0] );
            if ( is_string( $attachment_url ) && '' !== $attachment_url ) {
                $original_test_url = $attachment_url;
            }
        }

        if ( '' === $original_test_url ) {
            $uploads = wp_upload_dir();
            $baseurl = isset( $uploads['baseurl'] ) && is_string( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '';
            $original_test_url = '' !== $baseurl ? $baseurl . '/demo.jpg' : home_url( '/wp-content/uploads/demo.jpg' );
        }

        $url = $this->build_imagekit_rewritten_url( $endpoint, $original_test_url, $options );

        if ( '' === $url ) {
            $url = $endpoint . '/demo.jpg';
        }

        return $this->append_imagekit_transform( $url, 400, $options );
    }

    private function get_imagekit_origin_path_mode( $options ) {
        $mode = isset( $options['imagekit_origin_path_mode'] ) ? sanitize_key( (string) $options['imagekit_origin_path_mode'] ) : 'full_uploads_path';

        return in_array( $mode, array( 'full_uploads_path', 'uploads_relative_path' ), true ) ? $mode : 'full_uploads_path';
    }

    private function build_imagekit_rewritten_url( $endpoint, $original_url, $options ) {
        $endpoint = untrailingslashit( (string) $endpoint );
        $parsed   = wp_parse_url( (string) $original_url );

        if ( '' === $endpoint || empty( $parsed['path'] ) ) {
            return '';
        }

        if ( ! empty( $parsed['host'] ) && false !== stripos( (string) $parsed['host'], 'imagekit.io' ) ) {
            return '';
        }

        $normalized_path = preg_replace( '#/+#', '/', (string) $parsed['path'] );
        $normalized_path = is_string( $normalized_path ) ? $normalized_path : (string) $parsed['path'];
        $normalized_path = '/' . ltrim( $normalized_path, '/' );

        $uploads      = wp_upload_dir();
        $uploads_path = wp_parse_url( isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '', PHP_URL_PATH );
        $uploads_path = is_string( $uploads_path ) ? '/' . ltrim( untrailingslashit( $uploads_path ), '/' ) : '';

        if ( '' === $uploads_path || 0 !== strpos( $normalized_path, $uploads_path ) ) {
            return '';
        }

        $mode           = $this->get_imagekit_origin_path_mode( $options );
        $relative_path  = ltrim( substr( $normalized_path, strlen( $uploads_path ) ), '/' );
        $rewritten_path = 'uploads_relative_path' === $mode ? $relative_path : ltrim( $normalized_path, '/' );
        $rewritten_path = $this->normalize_imagekit_rewritten_path( $rewritten_path, $mode );
        $rewritten_path = $this->strip_endpoint_prefix_from_rewritten_path( $endpoint, $rewritten_path );

        if ( '' === $rewritten_path ) {
            return '';
        }

        return $this->build_imagekit_endpoint_url( $endpoint, $rewritten_path );
    }

    private function append_imagekit_transform( $url, $width, $options ) {
        if ( '' === $url ) {
            return '';
        }

        $transforms = array();

        $format_transform = $this->get_imagekit_auto_format_transform( $options );
        if ( '' !== $format_transform ) {
            $transforms[] = $format_transform;
        }

        if ( ! isset( $options['imagekit_auto_quality'] ) || '1' === (string) $options['imagekit_auto_quality'] ) {
            $transforms[] = 'q-auto';
        }

        $transforms[] = 'w-' . max( 1, absint( $width ) );

        return str_replace( '%2C', ',', add_query_arg( 'tr', implode( ',', $transforms ), $url ) );
    }

    private function get_imagekit_auto_format_transform( $options ) {
        if ( isset( $options['imagekit_auto_format'] ) && '1' === (string) $options['imagekit_auto_format'] ) {
            if ( isset( $options['imagekit_auto_format_mode'] ) && 'fm-auto' === $options['imagekit_auto_format_mode'] ) {
                return 'fm-auto';
            }
            return 'f-auto';
        }

        return '';
    }

    private function get_imagekit_debug_data( $imagekit_url ) {
        $data = array(
            'final_url'             => $imagekit_url,
            'accept_header'         => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'content_type'          => 'n/a',
            'cache_status'          => 'n/a',
            'cache_control'         => 'n/a',
            'vary_header'           => 'n/a',
            'detected_format'       => 'unknown',
            'modern_format_detected'=> false,
            'http_code'             => 'n/a',
            'is_background_image'   => false,
            'normalized_path'       => '',
            'duplicate_path_segments'=> array(),
            'path_warning'          => '',
            'error'                 => '',
        );

        if ( ! $this->is_permitted_imagekit_request_url( $imagekit_url ) ) {
            $data['error'] = __( 'No ImageKit URL available for testing.', 'fastlayer' );
            return $data;
        }

        $parsed_debug_url = wp_parse_url( $imagekit_url );
        $path_for_debug = isset( $parsed_debug_url['path'] ) ? (string) $parsed_debug_url['path'] : '';
        $normalized_path = preg_replace( '#/+#', '/', $path_for_debug );
        $normalized_path = is_string( $normalized_path ) ? $normalized_path : $path_for_debug;
        $data['normalized_path'] = $normalized_path;
        $data['duplicate_path_segments'] = $this->detect_duplicate_path_segments( $normalized_path );

        if ( ! empty( $data['duplicate_path_segments'] ) ) {
            $data['path_warning'] = __( 'Duplicate path segments detected in rewritten URL path.', 'fastlayer' );
        }

        $response = wp_safe_remote_get(
            $imagekit_url,
            array(
                'timeout'     => 10,
                'redirection' => 2,
                'headers'     => array(
                    'Accept' => $data['accept_header'],
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $data['error'] = $response->get_error_message();
            return $data;
        }

        $headers = wp_remote_retrieve_headers( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        $cache_header = wp_remote_retrieve_header( $response, 'x-cache' );
        if ( empty( $cache_header ) ) {
            $cache_header = wp_remote_retrieve_header( $response, 'cf-cache-status' );
        }
        if ( empty( $cache_header ) ) {
            $cache_header = wp_remote_retrieve_header( $response, 'x-imagekit-cache' );
        }
        $cache_control = wp_remote_retrieve_header( $response, 'cache-control' );
        $vary_header = wp_remote_retrieve_header( $response, 'vary' );

        $data['http_code']        = (string) wp_remote_retrieve_response_code( $response );
        $data['content_type']     = $content_type ? (string) $content_type : 'n/a';
        $data['cache_status']     = $cache_header ? (string) $cache_header : 'n/a';
        $data['cache_control']    = $cache_control ? (string) $cache_control : 'n/a';
        $data['vary_header']      = $vary_header ? (string) $vary_header : 'n/a';
        $data['detected_format']  = $this->detect_image_format_from_content_type( $data['content_type'] );
        $data['modern_format_detected'] = in_array( $data['detected_format'], array( 'webp', 'avif' ), true );

        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $all_headers = $headers->getAll();
            foreach ( array( 'x-cache', 'cf-cache-status', 'x-imagekit-cache', 'cache-control', 'vary' ) as $header_name ) {
                if ( isset( $all_headers[ $header_name ] ) && 'n/a' === $data['cache_status'] ) {
                    $data['cache_status'] = (string) $all_headers[ $header_name ];
                }
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer ImageKit] imagekit_request_url=' . $data['final_url'] . ' | browser_accept_header=' . $data['accept_header'] . ' | final_content_type=' . $data['content_type'] . ' | modern_format_detected=' . ( $data['modern_format_detected'] ? 'yes' : 'no' ) . ' | imagekit_cache_status=' . $data['cache_status'] );
        }

        return $data;
    }

    private function detect_image_format_from_content_type( $content_type ) {
        $content_type = strtolower( (string) $content_type );

        if ( false !== strpos( $content_type, 'image/avif' ) ) {
            return 'avif';
        }

        if ( false !== strpos( $content_type, 'image/webp' ) ) {
            return 'webp';
        }

        if ( false !== strpos( $content_type, 'image/png' ) ) {
            return 'png';
        }

        if ( false !== strpos( $content_type, 'image/jpeg' ) || false !== strpos( $content_type, 'image/jpg' ) ) {
            return 'jpg';
        }

        return 'unknown';
    }

    public function clear_css_js_cache( $redirect_url = '' ) {
        check_admin_referer( 'wp_fastlayer_clear_cache_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        $cache_stats_before = $this->get_cache_stats();
        $this->log_cache_clear( 'CSS/JS cache clear started.', $cache_stats_before );

        $minify_stats = $this->clear_minify_cache();

        if ( function_exists( 'delete_transient' ) ) {
            \delete_transient( 'wp_fastlayer_cache_debug_trace' );
        }

        $cache_stats_after = $this->get_cache_stats();
        $this->log_cache_clear( 'CSS/JS cache clear completed.', $cache_stats_after, $minify_stats );

        wp_safe_redirect( $this->get_safe_redirect_url( $redirect_url, admin_url( 'admin.php?page=wp-fastlayer&css_js_cache=cleared' ) ) );
        exit;
    }

    public function generate_critical_css() {
        check_admin_referer( 'wp_fastlayer_generate_critical_css_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        $css = $this->build_critical_css_sample();
        if ( $css ) {
            $options = get_option( $this->option_name, array() );
            $options['critical_css_custom'] = wp_kses_post( $css );
            update_option( $this->option_name, $options );
            wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer-css&critical_css=generated' ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer-css&critical_css=error' ) );
        exit;
    }

    public function bulk_generate_webp() {
        check_admin_referer( 'wp_fastlayer_bulk_webp_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        if ( ! $this->is_bulk_webp_generation_enabled() ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer-media' ) );
            exit;
        }

        $webp = Webp::get_instance();
        if ( ! $webp->is_webp_supported() ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer-media&webp_bulk=unsupported' ) );
            exit;
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $count = $webp->bulk_generate_webp();
        if ( $count > 0 ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer-media&webp_bulk=success&count=' . absint( $count ) ) );
        } else {
            wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer-media&webp_bulk=error' ) );
        }
        exit;
    }

    public function ajax_bulk_generate_webp_batch() {
        check_ajax_referer( 'wp_fastlayer_bulk_webp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to perform this action.', 'fastlayer' ) ), 403 );
        }

        if ( ! $this->is_bulk_webp_generation_enabled() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Bulk WebP generation is currently disabled. Existing generated WebP files will continue working normally.', 'fastlayer' ),
                ),
                403
            );
        }

        $webp = Webp::get_instance();
        if ( ! $webp->is_webp_supported() ) {
            wp_send_json_error( array( 'message' => __( 'WebP conversion is not supported on this server.', 'fastlayer' ), 'support' => $webp->get_webp_support_details() ) );
        }

        $page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 20;
        $batch_size = $batch_size > 0 ? min( 50, $batch_size ) : 20;
        $page = $page > 0 ? $page : 1;

        $result = $webp->bulk_generate_webp_batch( $page, $batch_size );
        wp_send_json_success( $result );
    }

    public function ajax_get_webp_summary() {
        check_ajax_referer( 'wp_fastlayer_bulk_webp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to view WebP summary data.', 'fastlayer' ) ), 403 );
        }

        wp_send_json_success( Webp::get_instance()->get_conversion_summary() );
    }

    public function ajax_save_webp_last_run() {
        check_ajax_referer( 'wp_fastlayer_bulk_webp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to save WebP run data.', 'fastlayer' ) ), 403 );
        }

        $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'success';
        $duration = isset( $_POST['duration'] ) ? absint( $_POST['duration'] ) : 0;

        Webp::get_instance()->save_last_run_summary( $status, $duration );
        wp_send_json_success( Webp::get_instance()->get_conversion_summary() );
    }

    private function build_critical_css_sample() {
        $home_url = home_url( '/' );
        $response = wp_remote_get( $home_url, array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return '';
        }

        preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $body, $matches );
        if ( empty( $matches[0] ) ) {
            return '';
        }

        $css = '';
        foreach ( $matches[0] as $link_tag ) {
            if ( preg_match( '/href=["\']([^"\']+)["\']/', $link_tag, $href_match ) ) {
                $stylesheet_url = esc_url_raw( $href_match[1] );
                if ( $this->is_internal_url( $stylesheet_url ) ) {
                    $path = $this->url_to_path( $stylesheet_url );
                    if ( $path && is_readable( $path ) ) {
                        $css .= file_get_contents( $path ) . "\n";
                    }
                }
            }

            if ( strlen( $css ) > 20000 ) {
                break;
            }
        }

        $css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );
        $css = preg_replace( '/\s+/', ' ', $css );
        return trim( substr( $css, 0, 20000 ) );
    }

    private function is_internal_url( $url ) {
        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['host'] ) ) {
            return true;
        }

        $site = wp_parse_url( site_url() );
        return isset( $site['host'] ) && strtolower( $parsed['host'] ) === strtolower( $site['host'] );
    }

    private function url_to_path( $url ) {
        $url        = esc_url_raw( $url );
        $base_url   = site_url();
        $parsed_url = wp_parse_url( $url );

        if ( empty( $parsed_url['host'] ) || empty( $parsed_url['path'] ) ) {
            return false;
        }

        if ( 0 !== strpos( $url, $base_url ) ) {
            return false;
        }

        // Ignore query strings and fragments (e.g. "?ver=1.2.3") so the
        // filesystem check targets the real file.
        $path = (string) $parsed_url['path'];

        $base_path = wp_parse_url( $base_url, PHP_URL_PATH );
        $base_path = is_string( $base_path ) ? rtrim( $base_path, '/' ) : '';

        if ( '' !== $base_path && 0 === strpos( $path, $base_path ) ) {
            $path = substr( $path, strlen( $base_path ) );
        }

        $relative = ltrim( $path, '/' );

        if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
            return false;
        }

        return ABSPATH . $relative;
    }

    private function get_cache_stats() {
        $stats = array(
            'files' => 0,
            'size' => 0,
            'html_files' => 0,
            'gzip_files' => 0,
            'webp_files' => 0,
            'mobile_files' => 0,
            'desktop_files' => 0,
            'cache_dirs' => array(),
            'cache_dir_found' => false,
        );

        $cache_dirs = $this->detect_cache_directories();
        if ( empty( $cache_dirs ) && defined( 'WP_FASTLAYER_CACHE_DIR' ) && is_dir( WP_FASTLAYER_CACHE_DIR ) ) {
            $cache_dirs[] = trailingslashit( wp_normalize_path( WP_FASTLAYER_CACHE_DIR ) );
        }

        $stats['cache_dirs'] = $cache_dirs;

        if ( empty( $cache_dirs ) ) {
            $this->log_cache_stats_debug( $stats );
            return $stats;
        }

        $stats['cache_dir_found'] = true;
        $visited_paths = array();

        foreach ( $cache_dirs as $cache_dir ) {
            $real_cache_dir = realpath( $cache_dir );
            if ( false === $real_cache_dir ) {
                if ( is_dir( $cache_dir ) ) {
                    $real_cache_dir = wp_normalize_path( untrailingslashit( $cache_dir ) );
                } else {
                    continue;
                }
            }

            if ( isset( $visited_paths[ $real_cache_dir ] ) ) {
                continue;
            }

            $visited_paths[ $real_cache_dir ] = true;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $real_cache_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() ) {
                    continue;
                }

                $file_size = (int) $file->getSize();
                if ( $file_size <= 0 ) {
                    continue;
                }

                $path = str_replace( '\\', '/', $file->getPathname() );
                $filename = strtolower( $file->getFilename() );
                $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

                if ( preg_match( '/\.(tmp|temp|part|lock)$/', $filename ) || preg_match( '/(^|\/)tmp(\/|$)/', $path ) ) {
                    continue;
                }

                $stats['files']++;
                $stats['size'] += $file_size;

                if ( in_array( $extension, array( 'html', 'htm' ), true ) ) {
                    $stats['html_files']++;
                }

                if ( in_array( $extension, array( 'gz', 'gzip' ), true ) ) {
                    $stats['gzip_files']++;
                }

                if ( 'webp' === $extension ) {
                    $stats['webp_files']++;
                }

                if ( false !== strpos( $path, '/mobile/' ) || false !== strpos( $filename, 'mobile' ) ) {
                    $stats['mobile_files']++;
                }

                if ( false !== strpos( $path, '/desktop/' ) || false !== strpos( $filename, 'desktop' ) ) {
                    $stats['desktop_files']++;
                }
            }
        }

        // Keep the main dashboard card focused on real cached page artifacts.
        if ( $stats['html_files'] > 0 ) {
            $stats['files'] = $stats['html_files'];
        }

        $this->log_cache_stats_debug( $stats );

        return $stats;
    }

    private function detect_cache_directories() {
        $candidates = array();

        if ( defined( 'WP_FASTLAYER_CACHE_DIR' ) ) {
            $candidates[] = trailingslashit( wp_normalize_path( WP_FASTLAYER_CACHE_DIR ) );
        }

        if ( defined( 'WP_CONTENT_DIR' ) ) {
            $candidates[] = WP_CONTENT_DIR . '/cache/wp-fastlayer/';

            $pattern = WP_CONTENT_DIR . '/cache/*fastlayer*';
            $matched_dirs = glob( $pattern, GLOB_ONLYDIR );
            if ( is_array( $matched_dirs ) ) {
                foreach ( $matched_dirs as $matched_dir ) {
                    $candidates[] = trailingslashit( $matched_dir );
                }
            }

            // Discover real cache path by scanning for md5-style generated cache files.
            $cache_root = WP_CONTENT_DIR . '/cache/';
            if ( is_dir( $cache_root ) ) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator( $cache_root, \RecursiveDirectoryIterator::SKIP_DOTS ),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ( $iterator as $item ) {
                    if ( ! $item->isFile() ) {
                        continue;
                    }

                    $name = strtolower( $item->getFilename() );
                    if ( ! preg_match( '/^[a-f0-9]{32}\.(html|htm|gz|gzip)$/', $name ) ) {
                        continue;
                    }

                    $candidates[] = trailingslashit( wp_normalize_path( dirname( $item->getPathname() ) ) );
                }
            }
        }

        $valid_dirs = array();
        $seen = array();

        foreach ( $candidates as $candidate ) {
            $normalized = wp_normalize_path( untrailingslashit( (string) $candidate ) );
            if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
                continue;
            }

            $seen[ $normalized ] = true;
            if ( is_dir( $normalized ) ) {
                $valid_dirs[] = trailingslashit( $normalized );
            }
        }

        return $valid_dirs;
    }

    private function log_cache_stats_debug( $stats ) {
        if ( ! class_exists( 'WP_FastLayer\\Logger' ) ) {
            return;
        }

        $payload = array(
            'cache_dirs' => isset( $stats['cache_dirs'] ) ? $stats['cache_dirs'] : array(),
            'cache_dir_found' => ! empty( $stats['cache_dir_found'] ),
            'cached_pages' => isset( $stats['files'] ) ? (int) $stats['files'] : 0,
            'total_size_bytes' => isset( $stats['size'] ) ? (int) $stats['size'] : 0,
            'html_files' => isset( $stats['html_files'] ) ? (int) $stats['html_files'] : 0,
            'gzip_files' => isset( $stats['gzip_files'] ) ? (int) $stats['gzip_files'] : 0,
            'webp_files' => isset( $stats['webp_files'] ) ? (int) $stats['webp_files'] : 0,
            'mobile_files' => isset( $stats['mobile_files'] ) ? (int) $stats['mobile_files'] : 0,
            'desktop_files' => isset( $stats['desktop_files'] ) ? (int) $stats['desktop_files'] : 0,
            'calculated_size' => isset( $stats['size'] ) ? size_format( (int) $stats['size'] ) : '0 B',
        );

        Bootstrap::get_instance()->log( '[Dashboard Cache Stats] ' . wp_json_encode( $payload ), 'debug' );
    }


    private function clear_minify_cache() {
        $upload_dir = wp_upload_dir();
        $min_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-min/';
        return $this->clear_cache_directory( $min_dir );
    }

    private function get_safe_redirect_url( $redirect_url, $fallback ) {
        $redirect_url = trim( (string) $redirect_url );
        $redirect_url = esc_url_raw( $redirect_url );
        $redirect_url = wp_validate_redirect( $redirect_url, $fallback );

        if ( empty( $redirect_url ) ) {
            return $fallback;
        }

        return $redirect_url;
    }

    private function clear_preload_cache_artifacts() {
        $upload_dir = wp_upload_dir();
        $results = array(
            'files_deleted' => 0,
            'bytes_deleted' => 0,
            'dirs' => array(),
        );

        $candidate_dirs = array(
            trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-preload/',
        );

        foreach ( $candidate_dirs as $dir ) {
            if ( is_dir( $dir ) ) {
                $results['dirs'][] = $dir;
                $cleared = $this->clear_cache_directory( $dir );
                $results['files_deleted'] += $cleared['files_deleted'];
                $results['bytes_deleted'] += $cleared['bytes_deleted'];
            }
        }

        return $results;
    }

    private function clear_cache_directory( $directory ) {
        $stats = array(
            'files_deleted' => 0,
            'bytes_deleted' => 0,
        );

        if ( ! is_dir( $directory ) ) {
            return $stats;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isFile() ) {
                $stats['bytes_deleted'] += (int) $item->getSize();
                $stats['files_deleted']++;
                @unlink( $item->getPathname() );
                continue;
            }

            if ( $item->isDir() ) {
                @rmdir( $item->getPathname() );
            }
        }

        return $stats;
    }

    private function log_cache_clear( $message, $cache_stats, $minify_stats = null, $upload_stats = null ) {
        if ( ! class_exists( 'WP_FastLayer\\Logger' ) ) {
            return;
        }

        $payload = array(
            'message' => $message,
            'cache_stats' => $cache_stats,
            'minify_stats' => $minify_stats,
            'upload_stats' => $upload_stats,
        );

        Bootstrap::get_instance()->log( '[Cache Clear] ' . wp_json_encode( $payload ), 'cache' );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( ! $this->is_wp_fastlayer_admin_hook( $hook ) ) {
            return;
        }

        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style( 'wp-fastlayer-admin', WP_FASTLAYER_URL . 'assets/css/admin.css', array(), WP_FASTLAYER_VERSION );
        wp_enqueue_script( 'wp-fastlayer-admin', WP_FASTLAYER_URL . 'assets/js/admin.js', array( 'jquery' ), WP_FASTLAYER_VERSION, true );
        $uploads = wp_upload_dir();
        $uploads_path = wp_parse_url( isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '', PHP_URL_PATH );
        $uploads_path = is_string( $uploads_path ) ? '/' . ltrim( untrailingslashit( $uploads_path ), '/' ) : '/wp-content/uploads';
        wp_localize_script(
            'wp-fastlayer-admin',
            'wpFastLayerAdmin',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'wp_fastlayer_bulk_webp_nonce' ),
                'bulk_button_text' => __( 'Generate WebP for Existing Images', 'fastlayer' ),
                'bulk_cancel_text' => __( 'Cancel Conversion', 'fastlayer' ),
                'bulk_running' => __( 'Starting WebP conversion...', 'fastlayer' ),
                'bulk_processing' => __( 'Preparing next batch...', 'fastlayer' ),
                'bulk_progress_template' => __( 'Converting image %current% of %total%', 'fastlayer' ),
                'bulk_progress_count_template' => __( '%converted% / %total% converted', 'fastlayer' ),
                'bulk_success' => __( 'WebP generation completed.', 'fastlayer' ),
                'bulk_success_notice' => __( 'WebP generation completed successfully.', 'fastlayer' ),
                'bulk_error' => __( 'WebP generation failed.', 'fastlayer' ),
                'bulk_error_notice' => __( 'WebP generation stopped before completion. Please review the error details and try again.', 'fastlayer' ),
                'bulk_ready' => __( 'Ready', 'fastlayer' ),
                'bulk_running_label' => __( 'Running', 'fastlayer' ),
                'bulk_completed_label' => __( 'Completed', 'fastlayer' ),
                'bulk_failed_label' => __( 'Error', 'fastlayer' ),
                'bulk_cancelled_label' => __( 'Cancelled', 'fastlayer' ),
                'bulk_empty_notice' => __( 'No eligible JPEG or PNG images were found for conversion.', 'fastlayer' ),
                'bulk_empty_message' => __( 'No images needed WebP conversion.', 'fastlayer' ),
                'bulk_cancel_requested' => __( 'Cancelling WebP conversion after the current request...', 'fastlayer' ),
                'bulk_cancelled_notice' => __( 'WebP conversion was cancelled. Partial progress has been saved.', 'fastlayer' ),
                'bulk_cancelled_message' => __( 'WebP conversion cancelled.', 'fastlayer' ),
                'bulk_percentage_template' => __( '%percent%% complete', 'fastlayer' ),
                'bulk_summary_template' => __( '%converted% converted, %failed% failed, %remaining% remaining.', 'fastlayer' ),
                'bulk_refreshing' => __( 'Refreshing conversion stats...', 'fastlayer' ),
                'webp_summary_loading' => __( 'Loading conversion summary...', 'fastlayer' ),
                'webp_summary_error' => __( 'Unable to load current conversion summary.', 'fastlayer' ),
                'webp_summary_updated' => __( 'Conversion summary updated.', 'fastlayer' ),
                'webp_summary_coverage' => __( '%percent%% of eligible images already have WebP files.', 'fastlayer' ),
                'webp_last_run_template' => __( 'Last run: %time%', 'fastlayer' ),
                'webp_last_duration_template' => __( 'Duration: %duration%', 'fastlayer' ),
                'webp_last_status_template' => __( 'Status: %status%', 'fastlayer' ),
                'bulk_pause_text' => __( 'Pause Conversion', 'fastlayer' ),
                'bulk_resume_text' => __( 'Resume Conversion', 'fastlayer' ),
                'bulk_pausing' => __( 'Pausing after the current batch...', 'fastlayer' ),
                'bulk_resuming' => __( 'Resuming WebP conversion...', 'fastlayer' ),
                'bulk_paused_label' => __( 'Paused', 'fastlayer' ),
                'bulk_paused_message' => __( 'WebP conversion is paused. Resume when you are ready.', 'fastlayer' ),
                'bulk_log_started' => __( 'WebP conversion started. Processing batches in the background.', 'fastlayer' ),
                'bulk_log_pause_requested' => __( 'Pause requested. Current batch will complete before stopping.', 'fastlayer' ),
                'bulk_log_paused' => __( 'Conversion paused after the current batch.', 'fastlayer' ),
                'bulk_log_resumed' => __( 'Resuming conversion from the remaining images.', 'fastlayer' ),
                'bulk_log_batch_started' => __( 'Starting batch %page% (%processed% expected).', 'fastlayer' ),
                'bulk_log_batch_completed' => __( 'Batch %page% completed: %processed% processed, %converted% converted, %failed% failed.', 'fastlayer' ),
                'bulk_batch_progress_template' => __( 'Batch %page% progress: %processed% / %total%', 'fastlayer' ),
                'bulk_status_template' => __( 'Processing batch %page% of %count% — Converting image %current% of %total%', 'fastlayer' ),
                'bulk_eta_template' => __( 'Estimated time remaining: %eta%', 'fastlayer' ),
                'bulk_eta_unavailable' => __( 'ETA unavailable until conversion starts.', 'fastlayer' ),
                'bulk_view_logs_text' => __( 'View Detailed Logs', 'fastlayer' ),
                'bulk_hide_logs_text' => __( 'Hide Detailed Logs', 'fastlayer' ),
                'db_cleanup_processing' => __( 'Cleaning...', 'fastlayer' ),
                'db_cleanup_default' => __( 'Clean Now', 'fastlayer' ),
                'db_cleanup_action' => 'wp_fastlayer_database_cleanup',
                'db_cleanup_stats_action' => 'wp_fastlayer_database_stats',
                'db_cleanup_nonce' => wp_create_nonce( 'wp_fastlayer_optimize_database_nonce' ),
                'db_cleanup_modal_title' => __( 'Database cleanup in progress', 'fastlayer' ),
                'db_cleanup_modal_starting' => __( 'Starting database cleanup...', 'fastlayer' ),
                'db_cleanup_modal_running' => __( 'Removing selected records and optimizing tables. Please keep this tab open.', 'fastlayer' ),
                'db_cleanup_modal_finalizing' => __( 'Refreshing database statistics...', 'fastlayer' ),
                'db_cleanup_modal_success_title' => __( 'Database cleanup complete', 'fastlayer' ),
                'db_cleanup_modal_error_title' => __( 'Database cleanup failed', 'fastlayer' ),
                'db_cleanup_modal_rows_removed' => __( 'Records removed: %s', 'fastlayer' ),
                'db_cleanup_modal_no_items' => __( 'No database cleanup action was selected, so nothing was removed.', 'fastlayer' ),
                'db_cleanup_modal_stats_refreshed' => __( 'All cleanup counters now reflect fresh database queries.', 'fastlayer' ),
                'db_cleanup_modal_close' => __( 'Close', 'fastlayer' ),
                'db_cleanup_modal_retry' => __( 'Try Again', 'fastlayer' ),
                'db_cleanup_modal_result_header' => __( 'Cleanup results', 'fastlayer' ),
                'db_cleanup_modal_continue' => __( 'Refreshing page...', 'fastlayer' ),
                'db_cleanup_running' => __( 'Database cleanup already running', 'fastlayer' ),
                'preload_status_action' => 'wp_fastlayer_preload_status',
                'preload_status_nonce' => wp_create_nonce( 'wp_fastlayer_preload_nonce' ),
                'preload_status_queued' => __( 'Queued. Warming pages in the background...', 'fastlayer' ),
                'preload_status_running' => __( 'Running. Warming pages in the background...', 'fastlayer' ),
                'preload_status_completed' => __( 'Completed.', 'fastlayer' ),
                'preload_status_completed_with_errors' => __( 'Completed with errors.', 'fastlayer' ),
                'preload_status_failed' => __( 'Failed.', 'fastlayer' ),
                'preload_status_never_run' => __( 'No preload job has been run yet.', 'fastlayer' ),
                'preload_status_progress_template' => __( '%processed% of %total% pages processed', 'fastlayer' ),
                'preload_status_warmed_template' => __( '%succeeded% warmed', 'fastlayer' ),
                'preload_status_failed_template' => __( '%failed% failed', 'fastlayer' ),
                'preload_status_error_row_template' => __( '%url% (%reason%)', 'fastlayer' ),
                'preload_status_errors_heading' => __( 'Pages that could not be warmed', 'fastlayer' ),
                'copy_text' => __( 'Copy Example', 'fastlayer' ),
                'copied_text' => __( 'Copied', 'fastlayer' ),
                'copy_failed_text' => __( 'Copy failed', 'fastlayer' ),
                'imagekit_uploads_path' => $uploads_path,
                'imagekit_preview_missing_endpoint' => __( 'Enter ImageKit endpoint to generate preview URL.', 'fastlayer' ),
                'imagekit_preview_invalid' => __( 'Preview unavailable for this URL and mode combination.', 'fastlayer' ),
            )
        );
    }

    /**
     * Reports the outcome of the request that started a preload job.
     *
     * A job no longer finishes inside the request that starts it, so the notice
     * only reports what actually happened at that point: whether it was queued,
     * refused because one was already running, or skipped. Claiming success here
     * would report a warming job that had not warmed anything yet.
     */
    private function render_preload_result_notice() {
        $result = isset( $_GET['preload'] ) ? sanitize_key( wp_unslash( $_GET['preload'] ) ) : '';
        if ( '' === $result ) {
            return;
        }

        switch ( $result ) {
            case 'started':
                $type    = 'notice-info';
                $message = __( 'Cache preload started in the background. Progress is shown below and you can safely leave this page.', 'fastlayer' );
                break;
            case 'busy':
                $type    = 'notice-warning';
                $message = __( 'A cache preload job is already running, so this request did not start a second one.', 'fastlayer' );
                break;
            case 'disabled':
                $type    = 'notice-warning';
                $message = __( 'Cache preloading is disabled, so nothing was queued.', 'fastlayer' );
                break;
            case 'error':
                $type    = 'notice-error';
                $message = __( 'The cache preload job could not be started.', 'fastlayer' );
                break;
            default:
                return;
        }

        printf(
            '<div class="notice %1$s wpfastlayer-notice is-dismissible"><p>%2$s</p></div>',
            esc_attr( $type ),
            esc_html( $message )
        );
    }

    /**
     * Renders the live preload job state. The markup carries the raw numbers and
     * the localized labels so the same panel can be refreshed in place while the
     * job runs, instead of only showing the state at page load.
     */
    private function render_preload_status_panel() {
        $state = Page_Cache::get_instance()->get_preload_state();

        $status    = isset( $state['status'] ) ? sanitize_key( $state['status'] ) : '';
        $total     = isset( $state['total'] ) ? (int) $state['total'] : 0;
        $processed = isset( $state['processed'] ) ? (int) $state['processed'] : 0;
        $succeeded = isset( $state['succeeded'] ) ? (int) $state['succeeded'] : 0;
        $failed    = isset( $state['failed'] ) ? (int) $state['failed'] : 0;
        $message   = isset( $state['message'] ) ? (string) $state['message'] : '';
        $errors    = ( isset( $state['errors'] ) && is_array( $state['errors'] ) ) ? $state['errors'] : array();

        $is_active = in_array( $status, array( 'queued', 'running' ), true );
        $labels    = array(
            'queued'                 => __( 'Queued', 'fastlayer' ),
            'running'                => __( 'Running', 'fastlayer' ),
            'completed'              => __( 'Completed', 'fastlayer' ),
            'completed_with_errors'  => __( 'Completed with errors', 'fastlayer' ),
            'failed'                 => __( 'Failed', 'fastlayer' ),
            'stopped'                => __( 'Paused', 'fastlayer' ),
        );

        $status_label = isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Not started', 'fastlayer' );
        ?>        <div class="wpfl-preload-status notice notice-info" data-wpfl-preload-status="<?php echo esc_attr( $status ); ?>" data-wpfl-preload-active="<?php echo $is_active ? 'yes' : 'no'; ?>">
            <p>
                <strong><?php esc_html_e( 'Cache preload status:', 'fastlayer' ); ?></strong>
                <span data-wpfl-preload-label><?php echo esc_html( $status_label ); ?></span>
                &mdash;
                <span data-wpfl-preload-progress><?php
                    echo esc_html( sprintf( '%1$d of %2$d pages processed', $processed, $total ) );
                ?></span>
            </p>
            <p>
                <span data-wpfl-preload-succeeded><?php echo esc_html( sprintf( '%d warmed', $succeeded ) ); ?></span>,
                <span data-wpfl-preload-failed><?php echo esc_html( sprintf( '%d failed', $failed ) ); ?></span>
            </p>
            <?php if ( '' !== $message ) : ?>
                <p data-wpfl-preload-message><?php echo esc_html( $message ); ?></p>
            <?php endif; ?>
            <?php if ( ! empty( $errors ) ) : ?>
                <details data-wpfl-preload-errors>
                    <summary><?php esc_html_e( 'Pages that could not be warmed', 'fastlayer' ); ?></summary>
                    <ul>
                        <?php foreach ( array_slice( $errors, -20 ) as $error ) : ?>
                            <li><?php echo esc_html( $error['url'] ?? '' ); ?> (<?php echo esc_html( $error['reason'] ?? '' ); ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_dashboard() {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( 'WP FastLayer: render_dashboard called.' );
        }
        $options = get_option( $this->option_name, array() );
        $cache_stats = $this->get_cache_stats();
        $cache_notice = isset( $_GET['cache'] ) ? sanitize_text_field( wp_unslash( $_GET['cache'] ) ) : '';
        $css_js_cache_notice = isset( $_GET['css_js_cache'] ) ? sanitize_text_field( wp_unslash( $_GET['css_js_cache'] ) ) : '';
        ?>
        <div class="wrap wpfl-wrap">
            <?php if ( 'cleared' === $cache_notice ) : ?>
                <div class="notice notice-success wpfastlayer-notice is-dismissible">
                    <p><?php echo esc_html__( 'Cache cleared successfully!', 'fastlayer' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( 'cleared' === $css_js_cache_notice ) : ?>
                <div class="notice notice-success wpfastlayer-notice is-dismissible">
                    <p><?php echo esc_html__( 'CSS/JS cache cleared successfully!', 'fastlayer' ); ?></p>
                </div>
            <?php endif; ?>

            <?php $this->render_preload_result_notice(); ?>
            <?php $this->render_preload_status_panel(); ?>

            <?php $this->render_page_header( 'FastLayer', '', '' ); ?>


            <div class="wpfl-dashboard">
                <div class="wpfl-stats-grid">
                    <div class="wpfl-stat-card">
                        <div class="wpfl-stat-icon">
                            <span class="dashicons dashicons-admin-page"></span>
                        </div>
                        <div class="wpfl-stat-content">
                            <h3><?php echo esc_html( number_format( $cache_stats['files'] ) ); ?></h3>
                            <p>Cached Pages</p>
                        </div>
                    </div>
                    <div class="wpfl-stat-card">
                        <div class="wpfl-stat-icon">
                            <span class="dashicons dashicons-database"></span>
                        </div>
                        <div class="wpfl-stat-content">
                            <h3><?php echo esc_html( size_format( $cache_stats['size'] ) ); ?></h3>
                            <p>Cache Size</p>
                        </div>
                    </div>
                    <div class="wpfl-stat-card">
                        <div class="wpfl-stat-icon">
                            <span class="dashicons dashicons-clock"></span>
                        </div>
                        <div class="wpfl-stat-content">
                            <h3><?php echo esc_html( $options['cache_expiration'] ?? 10 ); ?>h</h3>
                            <p>Cache Expiration</p>
                        </div>
                    </div>
                    <div class="wpfl-stat-card">
                        <div class="wpfl-stat-icon">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="wpfl-stat-content">
                            <h3><?php echo isset( $options['enable_cache'] ) && $options['enable_cache'] === '1' ? 'Active' : 'Inactive'; ?></h3>
                            <p>Cache Status</p>
                        </div>
                    </div>
                </div>

                <div class="wpfl-quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="wpfl-action-buttons">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpfl-action-form">
                            <input type="hidden" name="action" value="wp_fastlayer_clear_cache">
                            <?php wp_nonce_field( 'wp_fastlayer_clear_cache_nonce' ); ?>
                            <button type="submit" class="wpfl-btn wpfl-btn-danger">
                                <span class="dashicons dashicons-trash"></span>
                                Clear All Cache
                            </button>
                        </form>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpfl-action-form">
                            <input type="hidden" name="action" value="wp_fastlayer_manual_preload">
                            <?php wp_nonce_field( 'wp_fastlayer_preload_nonce' ); ?>
                            <button type="submit" class="wpfl-btn wpfl-btn-secondary">
                                <span class="dashicons dashicons-update"></span>
                                Preload Cache
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="wpfl-features-section">
                <h2>Features</h2>
                <div class="wpfl-features-grid">
                    <div class="wpfl-feature-card">
                        <div class="wpfl-feature-icon">
                            <span class="dashicons dashicons-dashboard"></span>
                        </div>
                        <div class="wpfl-feature-content">
                            <h3>Dashboard</h3>
                            <p>View cache statistics, storage usage and quick optimization actions from a single dashboard.</p>
                        </div>
                    </div>
                    <div class="wpfl-feature-card">
                        <div class="wpfl-feature-icon">
                            <span class="dashicons dashicons-editor-code"></span>
                        </div>
                        <div class="wpfl-feature-content">
                            <h3>HTML Optimization</h3>
                            <p>Minify HTML output, remove unnecessary comments and reduce page size.</p>
                        </div>
                    </div>
                    <div class="wpfl-feature-card">
                        <div class="wpfl-feature-icon">
                            <span class="dashicons dashicons-art"></span>
                        </div>
                        <div class="wpfl-feature-content">
                            <h3>CSS Optimization</h3>
                            <p>Minify CSS, generate Critical CSS and improve render performance.</p>
                        </div>
                    </div>
                    <div class="wpfl-feature-card">
                        <div class="wpfl-feature-icon">
                            <span class="dashicons dashicons-media-code"></span>
                        </div>
                        <div class="wpfl-feature-content">
                            <h3>JavaScript Optimization</h3>
                            <p>Minify, defer and delay JavaScript safely without breaking functionality.</p>
                        </div>
                    </div>
                    <div class="wpfl-feature-card">
                        <div class="wpfl-feature-icon">
                            <span class="dashicons dashicons-format-image"></span>
                        </div>
                        <div class="wpfl-feature-content">
                            <h3>Media Optimization</h3>
                            <p>WebP conversion, LQIP placeholders, lazy loading and smart LCP optimization.</p>
                        </div>
                    </div>
                    <div class="wpfl-feature-card">
                        <div class="wpfl-feature-icon">
                            <span class="dashicons dashicons-networking"></span>
                        </div>
                        <div class="wpfl-feature-content">
                            <h3>CDN</h3>
                            <p>Configure CDN hostname and ImageKit integration for faster asset delivery.</p>
                        </div>
                    </div>
                    <div class="wpfl-feature-card">
                        <div class="wpfl-feature-icon">
                            <span class="dashicons dashicons-database"></span>
                        </div>
                        <div class="wpfl-feature-content">
                            <h3>Database</h3>
                            <p>Clean revisions, transients, orphan metadata and optimize your database safely.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_cache_page() {
        $options = get_option( $this->option_name, array() );
        ?>
        <div class="wrap wpfl-wrap">
            <?php $this->render_page_header( __( 'Cache Settings', 'fastlayer' ), __( 'Manage page caching and preload behavior for your site.', 'fastlayer' ) ); ?>

            <div class="wpfl-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( $this->option_name ); ?>
                    <div class="wpfl-section">
                        <h2>Page Cache</h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_cache', $options['enable_cache'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Enable Page Caching</span>
                            </label>
                            <p class="description">Cache your pages to serve static HTML files to your visitors, reducing the load on your server.</p>
                        </div>
                        <div class="wpfl-field">
                            <label for="cache_expiration">Cache Expiration (hours)</label>
                            <input type="number" id="cache_expiration" name="<?php echo esc_attr( $this->option_name ); ?>[cache_expiration]" value="<?php echo esc_attr( $options['cache_expiration'] ?? 10 ); ?>" min="1" max="720">
                            <p class="description">How long the cache files should be kept before being automatically cleared.</p>
                        </div>
                    </div>

                    <div class="wpfl-section">
                        <h2>Cache Preloading</h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_preload', $options['enable_preload'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Enable Cache Preloading</span>
                            </label>
                            <p class="description">Automatically preload the cache after it's been cleared. This simulates a visit to your site to create the cache files.</p>
                        </div>
                    </div>

                    <?php $this->render_preload_result_notice(); ?>
                    <?php $this->render_preload_status_panel(); ?>

                    <?php $this->render_save_bar(); ?>
                </form>

                <?php
                $debug_trace = get_transient( 'wp_fastlayer_cache_debug_trace' );
                $debug_test = get_transient( 'wp_fastlayer_cache_debug_test_result' );
                $cache_dir = WP_FASTLAYER_CACHE_DIR;
                $cache_dir_writable = is_dir( $cache_dir ) && is_writable( $cache_dir );
                ?>

                <div class="wpfl-section wpfl-debug-panel" style="padding-bottom:40px;">
                    <h2>Temporary Cache Debug</h2>
                    <p>This panel is for temporary diagnostics only. It displays the last cache flow trace and allows a direct filesystem test.</p>
                    <table class="widefat fixed striped">
                        <tbody>
                            <tr>
                                <th>Cache directory</th>
                                <td><?php echo esc_html( wp_normalize_path( $cache_dir ) ); ?></td>
                            </tr>
                            <tr>
                                <th>Directory writable</th>
                                <td><?php echo $cache_dir_writable ? '<span style="color:green;">Yes</span>' : '<span style="color:red;">No</span>'; ?></td>
                            </tr>
                            <tr>
                                <th>Last cache hook</th>
                                <td><?php echo esc_html( $debug_trace['last_hook'] ?? 'n/a' ); ?></td>
                            </tr>
                            <tr>
                                <th>Last URL</th>
                                <td><?php echo esc_html( $debug_trace['last_url'] ?? 'n/a' ); ?></td>
                            </tr>
                            <tr>
                                <th>Last skip reason</th>
                                <td><?php echo esc_html( $debug_trace['skip_reason'] ?? 'none' ); ?></td>
                            </tr>
                            <tr>
                                <th>Last save result</th>
                                <td><?php echo esc_html( $debug_trace['save_result'] ?? 'none' ); ?></td>
                            </tr>
                            <tr>
                                <th>Last cache file path</th>
                                <td><?php echo esc_html( $debug_trace['cache_file'] ?? 'n/a' ); ?></td>
                            </tr>
                            <tr>
                                <th>Last debug timestamp</th>
                                <td><?php echo isset( $debug_trace['updated_at'] ) ? esc_html( date_i18n( 'Y-m-d H:i:s', $debug_trace['updated_at'] ) ) : 'n/a'; ?></td>
                            </tr>
                            <tr>
                                <th>Filesystem test result</th>
                                <td>
                                    <?php if ( $debug_test ) : ?>
                                        <strong>Write:</strong> <?php echo esc_html( $debug_test['write'] ); ?><br>
                                        <strong>Read:</strong> <?php echo esc_html( $debug_test['read'] ); ?><br>
                                        <strong>Delete:</strong> <?php echo esc_html( $debug_test['delete'] ); ?><br>
                                        <strong>Detail:</strong> <?php echo esc_html( $debug_test['detail'] ); ?>
                                    <?php else : ?>
                                        No test result available.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:20px 0;">
                        <input type="hidden" name="action" value="wp_fastlayer_cache_debug_test">
                        <?php wp_nonce_field( 'wp_fastlayer_cache_debug_test_nonce' ); ?>
                        <button type="submit" class="wpfl-btn wpfl-btn-secondary">Run Direct Filesystem Debug Test</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_html_page() {
        $options = get_option( $this->option_name, array() );
        ?>
        <div class="wrap wpfl-wrap">
            <?php $this->render_page_header( __( 'HTML Optimization', 'fastlayer' ), __( 'Minify and compress HTML output to reduce page size.', 'fastlayer' ) ); ?>

            <div class="wpfl-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( $this->option_name ); ?>
                    <div class="wpfl-section">
                        <h2><?php echo esc_html__( 'Basic HTML Minify', 'fastlayer' ); ?></h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_minify', $options['enable_minify'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label"><?php echo esc_html__( 'Enable HTML Minification', 'fastlayer' ); ?></span>
                            </label>
                            <p class="description"><?php echo esc_html__( 'Minify HTML output by removing comments and whitespace from HTML pages.', 'fastlayer' ); ?></p>
                        </div>
                    </div>

                    <div class="wpfl-section">
                        <h2><?php echo esc_html__( 'Advanced HTML Minify', 'fastlayer' ); ?></h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_html_remove_comments', $options['enable_html_remove_comments'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label"><?php echo esc_html__( 'Remove HTML Comments', 'fastlayer' ); ?></span>
                            </label>
                            <p class="description"><?php echo esc_html__( 'Remove HTML comments from the page output. This includes comments that are not critical for site rendering.', 'fastlayer' ); ?></p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_html_remove_whitespace', $options['enable_html_remove_whitespace'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label"><?php echo esc_html__( 'Remove HTML Whitespace', 'fastlayer' ); ?></span>
                            </label>
                            <p class="description"><?php echo esc_html__( 'Collapse extra whitespace in HTML output to reduce page size.', 'fastlayer' ); ?></p>
                        </div>
                    </div>

                    <div class="wpfl-section">
                        <h2><?php echo esc_html__( 'Extra HTML Minify Options', 'fastlayer' ); ?></h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_html_preserve_gutenberg_comments', $options['enable_html_preserve_gutenberg_comments'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label"><?php echo esc_html__( 'Preserve Gutenberg Block Comments', 'fastlayer' ); ?></span>
                            </label>
                            <p class="description"><?php echo esc_html__( 'Keep Gutenberg block comments like <!-- wp:paragraph --> so block markup remains intact.', 'fastlayer' ); ?></p>
                        </div>
                    </div>

                    <?php $this->render_save_bar(); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_css_page() {
        $options = get_option( $this->option_name, array() );
        ?>
        <div class="wrap wpfl-wrap">
            <?php $this->render_page_header( __( 'CSS Optimization', 'fastlayer' ), __( 'Optimize CSS delivery, combine files and manage critical CSS.', 'fastlayer' ), '#' ); ?>

            <div class="wpfl-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( $this->option_name ); ?>
                    <div class="wpfl-section">
                        <h2>CSS Minification <?php echo wp_kses_post( $this->render_help_icon( 'Minify CSS removes whitespace and comments to reduce file size and speed up CSS delivery.' ) ); ?></h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_minify_css', $options['enable_minify_css'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Minify CSS Files</span>
                            </label>
                            <p class="description">Minify CSS files to reduce their size and improve page load time.</p>
                        </div>
                    </div>

                    <div class="wpfl-section">
                        <h2>CSS File Optimization <?php echo wp_kses_post( $this->render_help_icon( 'Optimize delivery, combine, and inline CSS while excluding files as needed for compatibility.' ) ); ?></h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_css_delivery', $options['enable_css_delivery'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Optimize CSS Delivery</span>
                            </label>
                            <p class="description">Optimize CSS delivery by preloading styles and loading them non-blocking. This improves rendering while keeping compatibility with dynamic CSS.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_css_remove_unused', $options['enable_css_remove_unused'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Remove Unused CSS</span>
                            </label>
                            <p class="description">Attempt to reduce unused CSS by using critical CSS and optimized delivery. Keep this enabled only if you have custom critical CSS in place.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_smart_gutenberg_css_optimization', $options['enable_smart_gutenberg_css_optimization'] ?? 1 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Smart Gutenberg CSS Optimization</span>
                            </label>
                            <p class="description">Unload unused Gutenberg block CSS on pages that do not use block editor styles.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_google_fonts_display', $options['enable_google_fonts_display'] ?? 1 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Optimize Google Fonts Display</span>
                            </label>
                            <p class="description">Append display=swap to Google Fonts URLs for safe text font loading, while skipping Material Icons and symbols.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_css_combine', $options['enable_css_combine'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Combine CSS Files</span>
                            </label>
                            <p class="description">Combine multiple CSS files into a single file to reduce HTTP requests while preserving media-specific styles.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_css_remove_comments', $options['enable_css_remove_comments'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Remove CSS Comments</span>
                            </label>
                            <p class="description">Remove comments from CSS files to further reduce file size.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_css_inline', $options['enable_css_inline'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Inline Critical CSS</span>
                            </label>
                            <p class="description">Inline critical CSS above the fold for faster perceived page load time.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_critical_css', $options['enable_critical_css'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Enable Critical CSS</span>
                            </label>
                            <p class="description">Inline the critical CSS needed for above-the-fold rendering to speed up the first paint.</p>
                        </div>
                        <div class="wpfl-field">
                            <label for="critical_css_custom">Custom Critical CSS</label>
                            <textarea id="critical_css_custom" name="<?php echo esc_attr( $this->option_name ); ?>[critical_css_custom]" rows="8"><?php echo esc_textarea( $options['critical_css_custom'] ?? '' ); ?></textarea>
                            <p class="description">Paste your critical CSS here. You can also generate a homepage sample to get started.</p>
                        </div>
                        <div class="wpfl-field">
                            <button type="button" class="wpfl-btn wpfl-btn-secondary" onclick="document.getElementById('wp-fastlayer-generate-critical-css-form').submit();"><?php echo esc_html__( 'Generate Critical CSS Sample', 'fastlayer' ); ?></button>
                        </div>
                        <div class="wpfl-field">
                            <label for="exclude_css_files">Excluded CSS Files</label>
                            <textarea id="exclude_css_files" name="<?php echo esc_attr( $this->option_name ); ?>[exclude_css_files]" rows="5"><?php echo esc_textarea( $options['exclude_css_files'] ?? '' ); ?></textarea>
                            <p class="description">Specify URLs of CSS files to be excluded from minification (one per line).<br>Internal: The domain part of the URL will be stripped automatically. Use (.*).css wildcards to exclude all CSS files located at a specific path.<br>3rd Party: Use either the full URL path or only the domain name, to exclude external CSS. More info</p>
                        </div>
                    </div>

                    <?php $this->render_save_bar(); ?>
                </form>
                <form id="wp-fastlayer-generate-critical-css-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="wp_fastlayer_generate_critical_css">
                    <?php wp_nonce_field( 'wp_fastlayer_generate_critical_css_nonce' ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_js_page() {
        $options = get_option( $this->option_name, array() );
        ?>
        <div class="wrap wpfl-wrap">
            <?php $this->render_page_header( __( 'JavaScript Optimization', 'fastlayer' ), __( 'Minify and defer/async JavaScript to reduce render-blocking.', 'fastlayer' ), '#' ); ?>

            <div class="wpfl-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( $this->option_name ); ?>
                    <div class="wpfl-section">
                        <h2>JavaScript Minification <?php echo wp_kses_post( $this->render_help_icon( 'Minify JS by removing comments and whitespace to reduce file size and speed up page load.' ) ); ?></h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_minify_js', $options['enable_minify_js'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Minify JavaScript Files</span>
                            </label>
                            <p class="description">Minify JavaScript files to reduce their size and improve page load time.</p>
                        </div>
                    </div>

                    <div class="wpfl-section">
                        <h2>JavaScript File Optimization <?php echo wp_kses_post( $this->render_help_icon( 'Control combination, defer, and exclusions for JavaScript files to avoid render-blocking and compatibility issues.' ) ); ?></h2>
                        <div class="wpfl-field">
                            <label for="exclude_js_files">Excluded JavaScript Files</label>
                            <textarea id="exclude_js_files" name="<?php echo esc_attr( $this->option_name ); ?>[exclude_js_files]" rows="5"><?php echo esc_textarea( $options['exclude_js_files'] ?? '' ); ?></textarea>
                            <p class="description">Specify URLs of JavaScript files to be excluded from minification and concatenation (one per line).<br>/wp-content/themes/some-theme/(.*).js<br>Internal: The domain part of the URL will be stripped automatically. Use (.*).js wildcards to exclude all JS files located at a specific path.<br>3rd Party: Use either the full URL path or only the domain name, to exclude external JS. More info</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_js_combine', $options['enable_js_combine'] ?? 0, '', isset( $options['enable_js_defer'] ) && '1' === $options['enable_js_defer'] ? 'disabled' : '' ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Combine JavaScript Files</span>
                            </label>
                            <p class="description">Combine multiple JavaScript files into a single file to reduce HTTP requests. For compatibility and best results, this option is disabled when delay javascript execution is enabled.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_js_remove_comments', $options['enable_js_remove_comments'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Remove JavaScript Comments</span>
                            </label>
                            <p class="description">Remove comments from JavaScript files to further reduce file size.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_js_defer', $options['enable_js_defer'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Defer JavaScript Loading</span>
                            </label>
                            <p class="description">Load JavaScript deferred eliminates render-blocking JS on your site and can improve load time. More info</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_js_async', $options['enable_js_async'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Load JavaScript Asynchronously</span>
                            </label>
                            <p class="description">Use async loading for eligible scripts to improve execution speed. Safe mode and exclusions are still respected.</p>
                        </div>
                        <div class="wpfl-field">
                            <label for="exclude_js_defer">Excluded JavaScript Files</label>
                            <textarea id="exclude_js_defer" name="<?php echo esc_attr( $this->option_name ); ?>[exclude_js_defer]" rows="5"><?php echo esc_textarea( $options['exclude_js_defer'] ?? '' ); ?></textarea>
                            <p class="description">Specify URLs or keywords of JavaScript files to be excluded from defer (one per line). Also, please check our documentation for a list of compatibility exclusions.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_js_safe_mode', $options['enable_js_safe_mode'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Safe Mode</span>
                            </label>
                            <p class="description">Safe Mode prevents all internal scripts from being delayed.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_js_smart_delay', $options['enable_js_smart_delay'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Enable Smart JS Delay</span>
                            </label>
                            <p class="description">Delay only eligible non-critical scripts until the first user interaction. This mode keeps critical dependencies, Elementor, WooCommerce checkout/cart, and navigation scripts running immediately.</p>
                        </div>
                        <div class="wpfl-field">
                            <label for="smart_js_delay_timeout">Smart Delay Timeout Fallback (ms)</label>
                            <input type="number" id="smart_js_delay_timeout" name="<?php echo esc_attr( $this->option_name ); ?>[smart_js_delay_timeout]" value="<?php echo esc_attr( $options['smart_js_delay_timeout'] ?? '6000' ); ?>" min="1000" max="15000" step="100">
                            <p class="description">If no interaction happens, delayed scripts are restored automatically after this timeout.</p>
                        </div>
                        <div class="wpfl-field">
                            <label for="smart_js_delay_exclusions">Smart Delay Exclusions</label>
                            <textarea id="smart_js_delay_exclusions" name="<?php echo esc_attr( $this->option_name ); ?>[smart_js_delay_exclusions]" rows="5"><?php echo esc_textarea( $options['smart_js_delay_exclusions'] ?? '' ); ?></textarea>
                            <p class="description">One pattern per line. Supports handle-based exclusions with handle:keyword, URL keywords, and regex patterns (for example /gtag|analytics/i or regex:/hotjar|clarity/i).</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_js_smart_delay_debug', $options['enable_js_smart_delay_debug'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Smart JS Delay Debug Mode</span>
                            </label>
                            <p class="description">Log delayed, excluded, and restored scripts with timing details in the browser console using the [WP FastLayer Delay] prefix.</p>
                        </div>
                        <div class="wpfl-field">
                            <p class="description">One-click exclusions: When using the Delay JavaScript feature, you might notice some elements in the viewport take time to appear. If needed, add keywords or URLs for plugins, themes, analytics, payment processors, or other services below to ensure they are not delayed.</p>
                        </div>
                    </div>

                    <?php $this->render_save_bar(); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_media_page() {
        $options = get_option( $this->option_name, array() );
        $bulk_webp_enabled = $this->is_bulk_webp_generation_enabled( $options );
        $webp_bulk = isset( $_GET['webp_bulk'] ) ? sanitize_text_field( wp_unslash( $_GET['webp_bulk'] ) ) : '';
        $webp_count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
        $webp_summary = Webp::get_instance()->get_conversion_summary();
        ?>
        <div class="wrap wpfl-wrap">
            <?php $this->render_page_header( __( 'Media Optimization', 'fastlayer' ), __( 'Configure WebP conversion and lazy-loading for images and media.', 'fastlayer' ) ); ?>

            <?php $webp_support = Webp::get_instance()->get_webp_support_details(); ?>
            <?php if ( 'no' === $webp_support['supported'] ) : ?>
                <div class="notice notice-error wpfastlayer-notice is-dismissible">
                    <p><?php echo esc_html__( 'WebP conversion is currently unavailable on this server. Please enable GD with WebP support or install/configure Imagick with WebP support.', 'fastlayer' ); ?></p>
                    <p>
                        <?php echo esc_html__( 'Detected support details:', 'fastlayer' ); ?>
                        <br><?php echo esc_html( sprintf( __( 'GD installed: %s, GD WebP support: %s, Imagick installed: %s, Imagick WebP support: %s.', 'fastlayer' ), $webp_support['gd_installed'], $webp_support['gd_webp'], $webp_support['imagick_installed'], $webp_support['imagick_webp'] ) ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( 'success' === $webp_bulk ) : ?>
                <div class="notice notice-success wpfastlayer-notice is-dismissible">
                    <p><?php echo esc_html__( 'WebP generation completed successfully.', 'fastlayer' ); ?> <?php echo esc_html( sprintf( __( '%d image(s) processed.', 'fastlayer' ), $webp_count ) ); ?></p>
                </div>
            <?php elseif ( 'unsupported' === $webp_bulk ) : ?>
                <div class="notice notice-error wpfastlayer-notice is-dismissible">
                    <?php $webp_details = Webp::get_instance()->get_webp_support_details(); ?>
                    <p><?php echo esc_html__( 'WebP conversion is not supported on this server. Please enable GD WebP or Imagick.', 'fastlayer' ); ?></p>
                    <p>
                        <?php echo esc_html__( 'Server WebP support details:', 'fastlayer' ); ?>
                        <br><?php echo esc_html( sprintf( __( 'GD installed: %s, GD WebP support: %s, Imagick installed: %s, Imagick WebP support: %s.', 'fastlayer' ), $webp_details['gd_installed'], $webp_details['gd_webp'], $webp_details['imagick_installed'], $webp_details['imagick_webp'] ) ); ?>
                    </p>
                </div>
            <?php elseif ( 'error' === $webp_bulk ) : ?>
                <div class="notice notice-warning wpfastlayer-notice is-dismissible">
                    <p><?php echo esc_html__( 'No WebP images were generated. Verify that JPEG/PNG attachments exist and that the server supports WebP.', 'fastlayer' ); ?></p>
                </div>
            <?php endif; ?>

            <div class="wpfl-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( $this->option_name ); ?>
                    <div class="wpfl-section">
                        <h2>Image Optimization</h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_webp', $options['enable_webp'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Convert Images to WebP Format</span>
                            </label>
                            <p class="description">Automatically convert JPEG and PNG images to WebP format for better compression and faster loading.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_image_delivery', $options['enable_image_delivery'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Improve Image Delivery</span>
                            </label>
                            <p class="description">Serve optimized responsive image sizes and WebP variants based on frontend display dimensions.</p>
                        </div>
                        <div class="wpfl-field">
                            <label for="webp_quality">WebP Quality</label>
                            <input type="number" id="webp_quality" name="<?php echo esc_attr( $this->option_name ); ?>[webp_quality]" value="<?php echo esc_attr( $options['webp_quality'] ?? 80 ); ?>" min="50" max="100">
                            <p class="description">Choose the WebP quality level for new image conversions. Higher values preserve more detail.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_progressive_image_loading', $options['enable_progressive_image_loading'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Enable Progressive Image Loading</span>
                            </label>
                            <p class="description">Use local LQIP placeholders for smoother transitions while optimized images load.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_lqip_placeholders', $options['enable_lqip_placeholders'] ?? 1 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Generate LQIP Placeholders</span>
                            </label>
                            <p class="description">Create tiny blurred WebP placeholders for local images during upload and bulk conversion.</p>
                        </div>
                        <div class="wpfl-field">
                            <label for="lqip_width">LQIP Width</label>
                            <input type="number" id="lqip_width" name="<?php echo esc_attr( $this->option_name ); ?>[lqip_width]" value="<?php echo esc_attr( $options['lqip_width'] ?? 40 ); ?>" min="20" max="80">
                            <p class="description">Set the placeholder width in pixels for low-resolution blurred previews.</p>
                        </div>
                        <div class="wpfl-field">
                            <label for="lqip_quality">LQIP Quality</label>
                            <input type="number" id="lqip_quality" name="<?php echo esc_attr( $this->option_name ); ?>[lqip_quality]" value="<?php echo esc_attr( $options['lqip_quality'] ?? 25 ); ?>" min="1" max="50">
                            <p class="description">Lower values reduce placeholder file size while preserving basic blur shapes.</p>
                        </div>
                        <div class="wpfl-field">
                            <label for="lqip_blur_intensity">Placeholder Blur Strength</label>
                            <input type="number" id="lqip_blur_intensity" name="<?php echo esc_attr( $this->option_name ); ?>[lqip_blur_intensity]" value="<?php echo esc_attr( $options['lqip_blur_intensity'] ?? 20 ); ?>" min="0" max="50">
                            <p class="description">Control the blur effect applied to the low-quality placeholder background.</p>
                        </div>
                        <div class="wpfl-field">
                            <label for="lqip_fade_duration">Placeholder Fade Duration (ms)</label>
                            <input type="number" id="lqip_fade_duration" name="<?php echo esc_attr( $this->option_name ); ?>[lqip_fade_duration]" value="<?php echo esc_attr( $options['lqip_fade_duration'] ?? 250 ); ?>" min="0" max="1000">
                            <p class="description">Define the transition duration used for image loading placeholders.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_smart_lcp_optimization', $options['enable_smart_lcp_optimization'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Enable Smart LCP Optimization</span>
                            </label>
                            <p class="description">Detect one primary above-the-fold LCP image or hero background and prioritize it safely without disabling lazyload globally.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_lcp_preload', $options['enable_lcp_preload'] ?? 1 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Enable LCP Preload Hint</span>
                            </label>
                            <p class="description">Add one preload hint only for the detected primary LCP image/background to improve discovery timing.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_lcp_hero_background', $options['enable_lcp_hero_background'] ?? 1 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Enable Hero Background LCP Support</span>
                            </label>
                            <p class="description">Allow hero and Elementor background-image sections near the top to be considered for single-image LCP preload.</p>
                        </div>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_lcp_debug_mode', $options['enable_lcp_debug_mode'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label">Smart LCP Debug Mode</span>
                            </label>
                            <p class="description">Log detected LCP element, preload URL, and attribute decisions with the [WP FastLayer LCP] prefix.</p>
                        </div>
                        <?php $lcp_report = get_transient( 'wp_fastlayer_lcp_last_report' ); ?>
                        <?php if ( is_array( $lcp_report ) && ! empty( $lcp_report ) ) : ?>
                            <div class="wpfl-field">
                                <strong><?php echo esc_html__( 'Smart LCP Last Detection Report', 'fastlayer' ); ?></strong>
                                <table class="form-table" role="presentation">
                                    <tbody>
                                        <tr>
                                            <th><?php echo esc_html__( 'Detected LCP Asset', 'fastlayer' ); ?></th>
                                            <td><code><?php echo esc_html( (string) ( $lcp_report['detected_lcp_asset'] ?? '' ) ); ?></code></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo esc_html__( 'Preload URL', 'fastlayer' ); ?></th>
                                            <td><code><?php echo esc_html( (string) ( $lcp_report['preload_url'] ?? '' ) ); ?></code></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo esc_html__( 'Preload Applied', 'fastlayer' ); ?></th>
                                            <td><?php echo esc_html( ! empty( $lcp_report['preload_applied'] ) ? 'yes' : 'no' ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo esc_html__( 'Eager Loading Applied', 'fastlayer' ); ?></th>
                                            <td><?php echo esc_html( ! empty( $lcp_report['eager_loading_applied'] ) ? 'yes' : 'no' ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo esc_html__( 'Background Detected', 'fastlayer' ); ?></th>
                                            <td><?php echo esc_html( ! empty( $lcp_report['background_detection'] ) ? 'yes' : 'no' ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php echo esc_html__( 'Final ImageKit URL', 'fastlayer' ); ?></th>
                                            <td><code><?php echo esc_html( (string) ( $lcp_report['final_imagekit_url'] ?? '' ) ); ?></code></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <div class="wpfl-section">
                    <h2>Bulk WebP Generation</h2>
                    <div class="wpfl-field">
                        <label class="wpfl-toggle">
                            <?php $this->render_toggle_input( 'enable_bulk_webp_generation', $bulk_webp_enabled ? 1 : 0 ); ?>
                            <span class="wpfl-toggle-slider"></span>
                            <span class="wpfl-toggle-label">Enable Bulk WebP Generation</span>
                        </label>
                        <p class="description">Disable bulk WebP generation if your hosting environment, storage limits, or third-party image/CDN setup already handles optimization.</p>
                    </div>

                    <?php if ( ! $bulk_webp_enabled ) : ?>
                        <div class="notice notice-warning wpfastlayer-notice is-dismissible">
                            <p><?php echo esc_html__( 'Bulk WebP generation is currently disabled. Existing generated WebP files will continue working normally.', 'fastlayer' ); ?></p>
                        </div>
                    <?php else : ?>
                    <div class="wpfl-field">
                        <div class="wpfl-webp-summary-box" id="wpfl-webp-summary-box" data-total="<?php echo esc_attr( $webp_summary['total'] ); ?>" data-converted="<?php echo esc_attr( $webp_summary['converted'] ); ?>" data-remaining="<?php echo esc_attr( $webp_summary['remaining'] ); ?>" data-percentage="<?php echo esc_attr( $webp_summary['percentage'] ); ?>" data-last-run-at="<?php echo esc_attr( $webp_summary['last_run_at'] ); ?>" data-last-run-duration="<?php echo esc_attr( $webp_summary['last_run_duration'] ); ?>" data-last-run-status="<?php echo esc_attr( $webp_summary['last_run_status'] ); ?>">
                            <div class="wpfl-webp-summary-header">
                                <div>
                                    <strong class="wpfl-webp-summary-title"><?php echo esc_html__( 'Current conversion summary', 'fastlayer' ); ?></strong>
                                    <p class="wpfl-webp-summary-description" id="wpfl-webp-summary-description"><?php echo esc_html( $webp_summary['resume_message'] ); ?></p>
                                </div>
                                <span class="wpfl-webp-summary-refresh" id="wpfl-webp-summary-refresh"><?php echo esc_html__( 'Loading conversion summary...', 'fastlayer' ); ?></span>
                            </div>
                            <div class="wpfl-webp-summary-grid">
                                <span class="wpfl-webp-summary-card"><small><?php echo esc_html__( 'Eligible images', 'fastlayer' ); ?></small><strong id="wpfl-webp-summary-total"><?php echo esc_html( $webp_summary['total'] ); ?></strong></span>
                                <span class="wpfl-webp-summary-card"><small><?php echo esc_html__( 'Already converted', 'fastlayer' ); ?></small><strong id="wpfl-webp-summary-converted"><?php echo esc_html( $webp_summary['converted'] ); ?></strong></span>
                                <span class="wpfl-webp-summary-card"><small><?php echo esc_html__( 'Still pending', 'fastlayer' ); ?></small><strong id="wpfl-webp-summary-remaining"><?php echo esc_html( $webp_summary['remaining'] ); ?></strong></span>
                                <span class="wpfl-webp-summary-card"><small><?php echo esc_html__( 'Coverage', 'fastlayer' ); ?></small><strong id="wpfl-webp-summary-percentage"><?php echo esc_html( $webp_summary['percentage'] ); ?>%</strong></span>
                            </div>
                            <div class="wpfl-webp-summary-meta">
                                <span class="wpfl-webp-summary-meta-item"><small><?php echo esc_html__( 'Last run', 'fastlayer' ); ?></small><strong id="wpfl-webp-summary-last-run"><?php echo esc_html( $webp_summary['last_run_at'] ); ?></strong></span>
                                <span class="wpfl-webp-summary-meta-item"><small><?php echo esc_html__( 'Last duration', 'fastlayer' ); ?></small><strong id="wpfl-webp-summary-last-duration"><?php echo esc_html( $webp_summary['last_run_duration'] ); ?></strong></span>
                                <span class="wpfl-webp-summary-meta-item"><small><?php echo esc_html__( 'Last status', 'fastlayer' ); ?></small><strong id="wpfl-webp-summary-last-status"><?php echo esc_html( $webp_summary['last_run_status'] ); ?></strong></span>
                            </div>
                        </div>
                    </div>
                    <div class="wpfl-field">
                        <div class="wpfl-webp-action-row">
                            <button type="button" id="wp-fastlayer-bulk-webp-start" class="wpfl-btn wpfl-btn-secondary" data-batch-size="20"<?php echo 'yes' !== $webp_support['supported'] ? ' disabled="disabled"' : ''; ?>>Generate WebP for Existing Images</button>
                            <button type="button" id="wp-fastlayer-bulk-webp-pause" class="wpfl-btn wpfl-btn-ghost" style="display:none;"><?php echo esc_html__( 'Pause Conversion', 'fastlayer' ); ?></button>
                            <button type="button" id="wp-fastlayer-bulk-webp-cancel" class="wpfl-btn wpfl-btn-ghost" style="display:none;"><?php echo esc_html__( 'Cancel Conversion', 'fastlayer' ); ?></button>
                        </div>
                        <p class="description">
                            <?php if ( 'yes' === $webp_support['supported'] ) : ?>
                                <?php echo esc_html__( 'This will convert existing JPEG and PNG attachments to WebP in batches. The process can resume safely if interrupted.', 'fastlayer' ); ?>
                            <?php else : ?>
                                <?php echo esc_html__( 'WebP conversion is unavailable because the server does not currently support GD WebP or Imagick WebP. Enable one of those extensions to use bulk generation.', 'fastlayer' ); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="wpfl-field" id="wpfl-webp-bulk-progress" style="display:none;">
                        <div class="wpfl-webp-progress-shell">
                            <div class="wpfl-webp-progress-header">
                                <div class="wpfl-webp-progress-title-group">
                                    <span class="wpfl-webp-status-badge wpfl-webp-status-idle" id="wpfl-webp-status-badge"><?php echo esc_html__( 'Ready', 'fastlayer' ); ?></span>
                                    <strong class="wpfl-webp-progress-title"><?php echo esc_html__( 'WebP conversion progress', 'fastlayer' ); ?></strong>
                                </div>
                                <div class="wpfl-webp-progress-meta">
                                    <span class="wpfl-webp-progress-percentage" id="wpfl-webp-progress-percentage">0%</span>
                                    <span class="wpfl-webp-spinner" id="wpfl-webp-spinner" aria-hidden="true"></span>
                                </div>
                            </div>
                            <div class="wpfl-webp-bulk-notice" id="wpfl-webp-bulk-notice" style="display:none;"></div>
                            <div class="wpfl-progress-bar-wrapper">
                                <div id="wpfl-webp-bulk-bar" class="wpfl-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-valuetext="0% complete"></div>
                            </div>
                            <div class="wpfl-webp-progress-details">
                                <span class="wpfl-webp-progress-count" id="wpfl-webp-progress-count"><?php echo esc_html__( '0 / 0 converted', 'fastlayer' ); ?></span>
                            </div>
                            <div class="wpfl-webp-bulk-status-line">
                                <span class="wpfl-webp-bulk-message" id="wpfl-webp-bulk-message"><?php echo esc_html__( 'Waiting to start WebP conversion.', 'fastlayer' ); ?></span>
                                <span class="wpfl-webp-bulk-summary" id="wpfl-webp-bulk-summary"><?php echo esc_html__( '0 converted, 0 failed, 0 remaining.', 'fastlayer' ); ?></span>
                            </div>
                            <div class="wpfl-webp-batch-progress-line">
                                <span class="wpfl-webp-batch-progress" id="wpfl-webp-batch-progress"><?php echo esc_html__( 'Batch progress will appear here when conversion starts.', 'fastlayer' ); ?></span>
                                <span class="wpfl-webp-eta" id="wpfl-webp-eta"><?php echo esc_html__( 'ETA unavailable until conversion starts.', 'fastlayer' ); ?></span>
                                <button type="button" class="wpfl-btn wpfl-btn-link" id="wpfl-webp-toggle-logs"><?php echo esc_html__( 'View Detailed Logs', 'fastlayer' ); ?></button>
                            </div>
                            <div class="wpfl-webp-bulk-log" id="wpfl-webp-bulk-log" style="display:none;"></div>
                            <div class="wpfl-webp-bulk-stats">
                                <span class="wpfl-webp-stat-card" data-type="total"><small><?php echo esc_html__( 'Total images', 'fastlayer' ); ?></small><strong id="wpfl-webp-total">0</strong></span>
                                <span class="wpfl-webp-stat-card" data-type="converted"><small><?php echo esc_html__( 'Converted', 'fastlayer' ); ?></small><strong id="wpfl-webp-converted">0</strong></span>
                                <span class="wpfl-webp-stat-card" data-type="remaining"><small><?php echo esc_html__( 'Remaining', 'fastlayer' ); ?></small><strong id="wpfl-webp-remaining">0</strong></span>
                                <span class="wpfl-webp-stat-card" data-type="processed"><small><?php echo esc_html__( 'Processed', 'fastlayer' ); ?></small><strong id="wpfl-webp-processed">0</strong></span>
                                <span class="wpfl-webp-stat-card" data-type="batch"><small><?php echo esc_html__( 'Current batch', 'fastlayer' ); ?></small><strong id="wpfl-webp-current-batch">0</strong></span>
                                <span class="wpfl-webp-stat-card" data-type="failed"><small><?php echo esc_html__( 'Failed', 'fastlayer' ); ?></small><strong id="wpfl-webp-failed">0</strong></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="wpfl-section">
                    <h2>Lazy Load Images</h2>
                    <div class="wpfl-field">
                        <label class="wpfl-toggle">
                            <?php $this->render_toggle_input( 'enable_lazyload', $options['enable_lazyload'] ?? 0 ); ?>
                            <span class="wpfl-toggle-slider"></span>
                            <span class="wpfl-toggle-label">Enable Lazy Loading for Images</span>
                        </label>
                        <p class="description">Lazy load images to improve page load time by only loading images when they enter the viewport.</p>
                    </div>
                </div>

                <div class="wpfl-section">
                    <h2>Lazy Load Iframes & Videos</h2>
                    <div class="wpfl-field">
                        <label class="wpfl-toggle">
                            <?php $this->render_toggle_input( 'enable_lazyload_iframes', $options['enable_lazyload_iframes'] ?? 0 ); ?>
                            <span class="wpfl-toggle-slider"></span>
                            <span class="wpfl-toggle-label">Enable Lazy Loading for Iframes</span>
                        </label>
                        <p class="description">Lazy load iframes (YouTube, Vimeo, etc.) to improve initial page load time.</p>
                    </div>
                    <div class="wpfl-field">
                        <label class="wpfl-toggle">
                            <?php $this->render_toggle_input( 'enable_lazyload_videos', $options['enable_lazyload_videos'] ?? 0 ); ?>
                            <span class="wpfl-toggle-slider"></span>
                            <span class="wpfl-toggle-label">Enable Lazy Loading for Videos</span>
                        </label>
                        <p class="description">Lazy load HTML5 videos to improve initial page load time.</p>
                    </div>
                </div>

                <?php $this->render_save_bar(); ?>
            </form>

            </div>
        </div>
        <?php
    }

    public function render_cdn_page() {
        $options = get_option( $this->option_name, array() );
        $imagekit_endpoint = isset( $options['imagekit_endpoint'] ) ? trim( (string) $options['imagekit_endpoint'] ) : '';
        $imagekit_enabled  = ! empty( $options['enable_imagekit_cdn'] );
        $max_oversize_ratio = isset( $options['imagekit_max_oversize_ratio'] ) ? (float) $options['imagekit_max_oversize_ratio'] : 1.5;
        $image_debug_report = get_transient( 'wp_fastlayer_image_debug_report_payload' );
        $imagekit_mode     = $this->get_imagekit_origin_path_mode( $options );
        $preview_original  = $this->get_imagekit_preview_original_url();
        $preview_rewritten = $this->build_imagekit_rewritten_url( $imagekit_endpoint, $preview_original, $options );
        $preview_rewritten = $this->append_imagekit_transform( $preview_rewritten, 400, $options );
        $imagekit_debug    = $this->get_imagekit_debug_data( $preview_rewritten );
        $imagekit_valid    = '' !== $imagekit_endpoint && $this->is_valid_imagekit_endpoint( $imagekit_endpoint );
        $status_label      = __( 'Not Configured', 'fastlayer' );
        $status_class      = 'wpfl-imagekit-status-not-configured';

        if ( '' !== $imagekit_endpoint && ! $imagekit_valid ) {
            $status_label = __( 'Invalid Endpoint', 'fastlayer' );
            $status_class = 'wpfl-imagekit-status-invalid';
        } elseif ( $imagekit_valid ) {
            $status_label = __( 'Connected', 'fastlayer' );
            $status_class = 'wpfl-imagekit-status-connected';
        }

        $test_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=wp_fastlayer_test_imagekit' ),
            'wp_fastlayer_test_imagekit_nonce'
        );
        $generate_debug_report_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=wp_fastlayer_generate_image_debug_report' ),
            'wp_fastlayer_generate_image_debug_report_nonce'
        );
        $download_debug_report_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=wp_fastlayer_download_image_debug_report' ),
            'wp_fastlayer_download_image_debug_report_nonce'
        );
        ?>
        <div class="wrap wpfl-wrap">
            <?php $this->render_page_header( __( 'CDN Settings', 'fastlayer' ), __( 'Use a CDN hostname for internal assets to improve load times.', 'fastlayer' ) ); ?>

            <div class="wpfl-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( $this->option_name ); ?>

                    <div class="wpfl-section">
                        <h2><?php echo esc_html__( 'CDN Settings', 'fastlayer' ); ?></h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'enable_cdn', $options['enable_cdn'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label"><?php echo esc_html__( 'Enable CDN Replacement', 'fastlayer' ); ?></span>
                            </label>
                            <p class="description"><?php echo esc_html__( 'Rewrite supported internal asset URLs to a CDN hostname for faster delivery.', 'fastlayer' ); ?></p>
                        </div>
                        <div class="wpfl-field">
                            <label for="cdn_cname"><?php echo esc_html__( 'CDN CNAME', 'fastlayer' ); ?></label>
                            <input type="text" id="cdn_cname" name="<?php echo esc_attr( $this->option_name ); ?>[cdn_cname]" value="<?php echo esc_attr( $options['cdn_cname'] ?? '' ); ?>">
                            <p class="description"><?php echo esc_html__( 'Enter your CDN hostname, for example https://cdn.example.com. Only internal assets will be rewritten.', 'fastlayer' ); ?></p>
                        </div>
                    </div>

                    <div class="wpfl-section">
                        <div class="wpfl-imagekit-header-row">
                            <h2><?php echo esc_html__( 'ImageKit CDN (Images)', 'fastlayer' ); ?></h2>
                            <span class="wpfl-imagekit-status-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                        </div>

                        <?php if ( $imagekit_enabled ) : ?>
                            <div class="wpfl-imagekit-notice">
                                <span class="dashicons dashicons-info"></span>
                                <p><?php echo esc_html__( 'ImageKit delivery and local image optimization now run in compatibility mode. Local responsive sizes and WebP variants can still be generated and served safely while ImageKit handles final delivery.', 'fastlayer' ); ?></p>
                            </div>
                            <div class="wpfl-imagekit-status-summary">
                                <span class="wpfl-optimization-status-badge wpfl-optimization-status-cdn-active"><?php echo esc_html__( 'CDN Optimization: Active', 'fastlayer' ); ?></span>
                                <span class="wpfl-optimization-status-badge wpfl-optimization-status-local-paused"><?php echo esc_html__( 'Local Optimization: Compatible with ImageKit', 'fastlayer' ); ?></span>
                                <span class="wpfl-optimization-status-badge wpfl-optimization-status-webp-bypassed"><?php echo esc_html__( 'WebP Conversion: Enabled', 'fastlayer' ); ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="wpfl-imagekit-grid">
                            <div class="wpfl-imagekit-card wpfl-imagekit-card-guide">
                                <h3><span class="dashicons dashicons-info-outline"></span><?php echo esc_html__( 'Step-by-step setup', 'fastlayer' ); ?></h3>
                                <ol class="wpfl-imagekit-steps">
                                    <li>
                                        <strong><?php echo esc_html__( 'Step 1: Create your free ImageKit account', 'fastlayer' ); ?></strong>
                                        <div class="wpfl-imagekit-link-row">
                                            <a class="wpfl-btn wpfl-btn-secondary" href="https://imagekit.io/" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Create Free Account', 'fastlayer' ); ?></a>
                                        </div>
                                    </li>
                                    <li>
                                        <strong><?php echo esc_html__( 'Step 2: Copy your URL Endpoint from ImageKit dashboard', 'fastlayer' ); ?></strong>
                                        <p><?php echo esc_html__( 'In ImageKit: Dashboard -> URL Endpoint. It usually looks like this:', 'fastlayer' ); ?></p>
                                        <div class="wpfl-imagekit-example-row">
                                            <code class="wpfl-imagekit-example-code">https://ik.imagekit.io/demo</code>
                                            <button type="button" class="wpfl-btn wpfl-btn-ghost wpfl-copy-example-btn" data-copy-text="https://ik.imagekit.io/demo"><?php echo esc_html__( 'Copy Example', 'fastlayer' ); ?></button>
                                        </div>
                                    </li>
                                    <li>
                                        <strong><?php echo esc_html__( 'Step 3: Paste endpoint below, enable ImageKit, then save', 'fastlayer' ); ?></strong>
                                    </li>
                                </ol>
                            </div>

                            <div class="wpfl-imagekit-card wpfl-imagekit-card-actions">
                                <h3><span class="dashicons dashicons-admin-links"></span><?php echo esc_html__( 'Quick links', 'fastlayer' ); ?></h3>
                                <div class="wpfl-imagekit-link-row">
                                    <a class="wpfl-btn wpfl-btn-ghost" href="https://imagekit.io/dashboard" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open ImageKit Dashboard', 'fastlayer' ); ?></a>
                                    <a class="wpfl-btn wpfl-btn-ghost" href="https://imagekit.io/docs" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'View Documentation', 'fastlayer' ); ?></a>
                                </div>
                                <div class="wpfl-imagekit-link-row">
                                    <a class="wpfl-btn wpfl-btn-secondary" href="<?php echo esc_url( $test_url ); ?>"><?php echo esc_html__( 'Test CDN URL', 'fastlayer' ); ?></a>
                                    <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
                                        <a class="wpfl-btn wpfl-btn-secondary" href="<?php echo esc_url( $generate_debug_report_url ); ?>"><?php echo esc_html__( 'Generate Responsive Image Debug Report', 'fastlayer' ); ?></a>
                                    <?php endif; ?>
                                </div>
                                <p class="description"><?php echo esc_html__( 'Test validates endpoint + rewrite mode, requests a generated ImageKit URL, and shows HTTP success/failure.', 'fastlayer' ); ?></p>
                            </div>
                        </div>

                        <div class="wpfl-imagekit-card wpfl-imagekit-card-config">
                            <h3><span class="dashicons dashicons-admin-generic"></span><?php echo esc_html__( 'Configuration', 'fastlayer' ); ?></h3>

                            <div class="wpfl-field">
                                <label class="wpfl-toggle">
                                    <?php $this->render_toggle_input( 'enable_imagekit_cdn', $options['enable_imagekit_cdn'] ?? 0 ); ?>
                                    <span class="wpfl-toggle-slider"></span>
                                    <span class="wpfl-toggle-label"><?php echo esc_html__( 'Enable ImageKit for image delivery', 'fastlayer' ); ?></span>
                                </label>
                                <p class="description"><?php echo esc_html__( 'Turn this on to serve your site images through ImageKit.', 'fastlayer' ); ?></p>
                            </div>

                            <div class="wpfl-field">
                                <label for="imagekit_endpoint"><?php echo esc_html__( 'ImageKit URL Endpoint', 'fastlayer' ); ?></label>
                                <input type="text" id="imagekit_endpoint" name="<?php echo esc_attr( $this->option_name ); ?>[imagekit_endpoint]" value="<?php echo esc_attr( $options['imagekit_endpoint'] ?? '' ); ?>" placeholder="https://ik.imagekit.io/your_endpoint" class="wpfl-imagekit-endpoint-input">
                                <p class="description"><?php echo esc_html__( 'Where to find this: ImageKit Dashboard -> URL Endpoint.', 'fastlayer' ); ?></p>
                                <p class="description"><?php echo esc_html__( 'Real example: https://ik.imagekit.io/your_endpoint', 'fastlayer' ); ?></p>
                            </div>

                            <div class="wpfl-field">
                                <label for="imagekit_origin_path_mode"><?php echo esc_html__( 'ImageKit Origin Path Mode', 'fastlayer' ); ?></label>
                                <select id="imagekit_origin_path_mode" name="<?php echo esc_attr( $this->option_name ); ?>[imagekit_origin_path_mode]" class="wpfl-imagekit-origin-mode-select">
                                    <option value="full_uploads_path" <?php selected( $imagekit_mode, 'full_uploads_path' ); ?>><?php echo esc_html__( 'Full Uploads Path (/wp-content/uploads/...)', 'fastlayer' ); ?></option>
                                    <option value="uploads_relative_path" <?php selected( $imagekit_mode, 'uploads_relative_path' ); ?>><?php echo esc_html__( 'Uploads Relative Path (/YYYY/MM/file.jpg)', 'fastlayer' ); ?></option>
                                </select>
                                <p class="description"><?php echo esc_html__( 'Use Full Uploads Path for standard ImageKit origins. Use Uploads Relative Path if your ImageKit endpoint already maps to uploads internally.', 'fastlayer' ); ?></p>
                            </div>

                            <div class="wpfl-imagekit-preview" id="wpfl-imagekit-preview" data-original-url="<?php echo esc_attr( $preview_original ); ?>" data-endpoint="<?php echo esc_attr( $imagekit_endpoint ); ?>" data-mode="<?php echo esc_attr( $imagekit_mode ); ?>">
                                <h4><?php echo esc_html__( 'Live URL Preview', 'fastlayer' ); ?></h4>
                                <p class="description"><?php echo esc_html__( 'Preview updates automatically as you type or switch origin path mode.', 'fastlayer' ); ?></p>
                                <div class="wpfl-imagekit-preview-row">
                                    <span class="wpfl-imagekit-preview-label"><?php echo esc_html__( 'Original URL', 'fastlayer' ); ?></span>
                                    <code id="wpfl-imagekit-preview-original" class="wpfl-imagekit-preview-code"><?php echo esc_html( $preview_original ); ?></code>
                                </div>
                                <div class="wpfl-imagekit-preview-arrow">&rarr;</div>
                                <div class="wpfl-imagekit-preview-row">
                                    <span class="wpfl-imagekit-preview-label"><?php echo esc_html__( 'Rewritten ImageKit URL', 'fastlayer' ); ?></span>
                                    <code id="wpfl-imagekit-preview-rewritten" class="wpfl-imagekit-preview-code"><?php echo esc_html( $preview_rewritten ); ?></code>
                                </div>
                            </div>

                            <?php if ( $imagekit_enabled ) : ?>
                                <div class="wpfl-imagekit-preview">
                                    <h4><?php echo esc_html__( 'ImageKit Negotiation Debug', 'fastlayer' ); ?></h4>
                                    <table class="widefat striped">
                                        <tbody>
                                            <tr>
                                                <th><?php echo esc_html__( 'Final ImageKit URL', 'fastlayer' ); ?></th>
                                                <td><code class="wpfl-imagekit-preview-code"><?php echo esc_html( $imagekit_debug['final_url'] ); ?></code></td>
                                            </tr>
                                            <tr>
                                                <th><?php echo esc_html__( 'Response Content-Type', 'fastlayer' ); ?></th>
                                                <td><?php echo esc_html( $imagekit_debug['content_type'] ); ?></td>
                                            </tr>
                                            <tr>
                                                <th><?php echo esc_html__( 'Accept Header Sent', 'fastlayer' ); ?></th>
                                                <td><code><?php echo esc_html( $imagekit_debug['accept_header'] ); ?></code></td>
                                            </tr>
                                            <tr>
                                                <th><?php echo esc_html__( 'Vary Header', 'fastlayer' ); ?></th>
                                                <td><?php echo esc_html( $imagekit_debug['vary_header'] ); ?></td>
                                            </tr>
                                            <tr>
                                                <th><?php echo esc_html__( 'Cache-Control', 'fastlayer' ); ?></th>
                                                <td><?php echo esc_html( $imagekit_debug['cache_control'] ); ?></td>
                                            </tr>
                                            <tr>
                                                <th><?php echo esc_html__( 'Cache Status', 'fastlayer' ); ?></th>
                                                <td><?php echo esc_html( $imagekit_debug['cache_status'] ); ?></td>
                                            </tr>
                                            <tr>
                                                <th><?php echo esc_html__( 'Detected Format', 'fastlayer' ); ?></th>
                                                <td><?php echo esc_html( $imagekit_debug['detected_format'] ); ?></td>
                                            </tr>
                                            <tr>
                                                <th><?php echo esc_html__( 'Modern Format Delivered', 'fastlayer' ); ?></th>
                                                <td><?php echo esc_html( $imagekit_debug['modern_format_detected'] ? 'yes' : 'no' ); ?></td>
                                            </tr>
                                            <tr>
                                                <th><?php echo esc_html__( 'HTTP Status', 'fastlayer' ); ?></th>
                                                <td><?php echo esc_html( $imagekit_debug['http_code'] ); ?></td>
                                            </tr>
                                            <tr>
                                                <th><?php echo esc_html__( 'Background Rewrite Detected', 'fastlayer' ); ?></th>
                                                <td><?php echo esc_html( $imagekit_debug['is_background_image'] ? 'yes' : 'no' ); ?></td>
                                            </tr>
                                            <tr>
                                                <th><?php echo esc_html__( 'Normalized Path', 'fastlayer' ); ?></th>
                                                <td><code><?php echo esc_html( $imagekit_debug['normalized_path'] ); ?></code></td>
                                            </tr>
                                            <?php if ( ! empty( $imagekit_debug['duplicate_path_segments'] ) ) : ?>
                                                <tr>
                                                    <th><?php echo esc_html__( 'Duplicate Path Segments', 'fastlayer' ); ?></th>
                                                    <td><?php echo esc_html( implode( ', ', (array) $imagekit_debug['duplicate_path_segments'] ) ); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $imagekit_debug['path_warning'] ) ) : ?>
                                                <tr>
                                                    <th><?php echo esc_html__( 'Path Validation Warning', 'fastlayer' ); ?></th>
                                                    <td><?php echo esc_html( $imagekit_debug['path_warning'] ); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $imagekit_debug['error'] ) ) : ?>
                                                <tr>
                                                    <th><?php echo esc_html__( 'Debug Error', 'fastlayer' ); ?></th>
                                                    <td><?php echo esc_html( $imagekit_debug['error'] ); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <?php if ( $imagekit_enabled && '' === $imagekit_endpoint ) : ?>
                                <div class="wpfl-imagekit-inline-warning">
                                    <span class="dashicons dashicons-warning"></span>
                                    <span><?php echo esc_html__( 'ImageKit is enabled but endpoint is missing.', 'fastlayer' ); ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="wpfl-imagekit-toggle-grid">
                                <div class="wpfl-field">
                                    <label class="wpfl-toggle">
                                        <?php $this->render_toggle_input( 'imagekit_auto_quality', $options['imagekit_auto_quality'] ?? 1 ); ?>
                                        <span class="wpfl-toggle-slider"></span>
                                        <span class="wpfl-toggle-label"><?php echo esc_html__( 'Auto Quality', 'fastlayer' ); ?></span>
                                    </label>
                                    <p class="description"><?php echo esc_html__( 'Automatically compresses images to reduce file size while keeping good visual quality.', 'fastlayer' ); ?></p>
                                </div>

                                <div class="wpfl-field">
                                    <label class="wpfl-toggle">
                                        <?php $this->render_toggle_input( 'imagekit_auto_format', $options['imagekit_auto_format'] ?? 1 ); ?>
                                        <span class="wpfl-toggle-slider"></span>
                                        <span class="wpfl-toggle-label"><?php echo esc_html__( 'Auto Format', 'fastlayer' ); ?></span>
                                    </label>
                                    <p class="description"><?php echo esc_html__( 'Automatically serves modern image formats like WebP or AVIF when supported.', 'fastlayer' ); ?></p>
                                </div>

                                <div class="wpfl-field">
                                    <label for="imagekit_auto_format_mode"><?php echo esc_html__( 'Auto Format Mode', 'fastlayer' ); ?></label>
                                    <select id="imagekit_auto_format_mode" name="<?php echo esc_attr( $this->option_name ); ?>[imagekit_auto_format_mode]" class="wpfl-imagekit-origin-mode-select">
                                        <option value="f-auto" <?php selected( $options['imagekit_auto_format_mode'] ?? 'f-auto', 'f-auto' ); ?>><?php echo esc_html__( 'Standard Auto Format (f-auto)', 'fastlayer' ); ?></option>
                                        <option value="fm-auto" <?php selected( $options['imagekit_auto_format_mode'] ?? 'f-auto', 'fm-auto' ); ?>><?php echo esc_html__( 'Modern Format Negotiation (fm-auto)', 'fastlayer' ); ?></option>
                                    </select>
                                    <p class="description"><?php echo esc_html__( 'Choose the ImageKit auto-format transform used in URLs. Use fm-auto if ImageKit recommends it for your configuration.', 'fastlayer' ); ?></p>
                                </div>

                                <div class="wpfl-field">
                                    <label for="imagekit_max_oversize_ratio"><?php echo esc_html__( 'Max Oversize Ratio', 'fastlayer' ); ?></label>
                                    <input type="number" id="imagekit_max_oversize_ratio" name="<?php echo esc_attr( $this->option_name ); ?>[imagekit_max_oversize_ratio]" value="<?php echo esc_attr( $max_oversize_ratio ); ?>" min="1" max="3" step="0.1">
                                    <p class="description"><?php echo esc_html__( 'Requested transform width is capped to this multiple of detected display width. Recommended: 1.5', 'fastlayer' ); ?></p>
                                </div>

                                <div class="wpfl-field">
                                    <label class="wpfl-toggle">
                                        <?php $this->render_toggle_input( 'imagekit_runtime_correction', $options['imagekit_runtime_correction'] ?? 1 ); ?>
                                        <span class="wpfl-toggle-slider"></span>
                                        <span class="wpfl-toggle-label"><?php echo esc_html__( 'Enable Runtime Width Correction (WP_DEBUG)', 'fastlayer' ); ?></span>
                                    </label>
                                    <p class="description"><?php echo esc_html__( 'When WP_DEBUG is enabled, oversized ImageKit image URLs are corrected before image load using actual rendered width.', 'fastlayer' ); ?></p>
                                </div>

                                <div class="wpfl-field">
                                    <label class="wpfl-toggle">
                                        <?php $this->render_toggle_input( 'imagekit_debug_overlay', $options['imagekit_debug_overlay'] ?? 0 ); ?>
                                        <span class="wpfl-toggle-slider"></span>
                                        <span class="wpfl-toggle-label"><?php echo esc_html__( 'Show Runtime Image Debug Overlay (WP_DEBUG)', 'fastlayer' ); ?></span>
                                    </label>
                                    <p class="description"><?php echo esc_html__( 'Adds an admin-only overlay with rendered width, requested transform width, and oversize ratio.', 'fastlayer' ); ?></p>
                                </div>
                            </div>

                            <?php if ( ! empty( $options['enable_cdn'] ) && ! empty( $options['enable_imagekit_cdn'] ) ) : ?>
                                <div class="wpfl-imagekit-inline-note">
                                    <strong><?php echo esc_html__( 'Compatibility note:', 'fastlayer' ); ?></strong>
                                    <?php echo esc_html__( 'Standard CDN remains for CSS/JS and other assets. ImageKit is applied to image URLs.', 'fastlayer' ); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && is_array( $image_debug_report ) ) : ?>
                                <div class="wpfl-imagekit-preview">
                                    <h4><?php echo esc_html__( 'Responsive Image Debug Report', 'fastlayer' ); ?></h4>
                                    <p class="description"><?php echo esc_html__( 'Report contains runtime-collected rendered width, requested transform width, oversize ratio, srcset widths, and estimated mobile/desktop viewport widths.', 'fastlayer' ); ?></p>
                                    <div class="wpfl-imagekit-link-row">
                                        <a class="wpfl-btn wpfl-btn-ghost" href="<?php echo esc_url( $download_debug_report_url ); ?>"><?php echo esc_html__( 'Download JSON Report', 'fastlayer' ); ?></a>
                                    </div>
                                    <textarea rows="12" class="large-text code" readonly><?php echo esc_textarea( wp_json_encode( $image_debug_report, JSON_PRETTY_PRINT ) ); ?></textarea>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="wpfl-imagekit-card wpfl-imagekit-card-how">
                            <h3><span class="dashicons dashicons-lightbulb"></span><?php echo esc_html__( 'How it works', 'fastlayer' ); ?></h3>
                            <ul class="wpfl-imagekit-how-list">
                                <li><?php echo esc_html__( 'Your original images stay on your own server.', 'fastlayer' ); ?></li>
                                <li><?php echo esc_html__( 'This plugin automatically rewrites image URLs on the frontend.', 'fastlayer' ); ?></li>
                                <li><?php echo esc_html__( 'ImageKit optimizes and delivers those images globally.', 'fastlayer' ); ?></li>
                            </ul>
                        </div>
                    </div>

                    <?php $this->render_save_bar(); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private function get_imagekit_preview_original_url() {
        $attachment = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'post_status'    => 'inherit',
                'fields'         => 'ids',
            )
        );

        if ( ! empty( $attachment ) ) {
            $attachment_url = $this->get_local_upload_attachment_url( (int) $attachment[0] );
            if ( is_string( $attachment_url ) && '' !== $attachment_url ) {
                return $attachment_url;
            }
        }

        $uploads = wp_upload_dir();
        $baseurl = isset( $uploads['baseurl'] ) && is_string( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '';
        if ( '' !== $baseurl ) {
            return $baseurl . '/demo.jpg';
        }

        return home_url( '/wp-content/uploads/demo.jpg' );
    }

    private function get_local_upload_attachment_url( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id <= 0 ) {
            return '';
        }

        $relative_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( ! is_string( $relative_file ) || '' === $relative_file ) {
            return '';
        }

        $relative_file = ltrim( (string) preg_replace( '#/+#', '/', $relative_file ), '/' );
        $uploads = wp_upload_dir();
        $basedir = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? untrailingslashit( $uploads['basedir'] ) : '';
        $baseurl = isset( $uploads['baseurl'] ) && is_string( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '';

        if ( '' === $baseurl || '' === $basedir ) {
            return '';
        }

        $candidate_file = $basedir . '/' . $relative_file;
        if ( ! file_exists( $candidate_file ) ) {
            return '';
        }

        return $baseurl . '/' . $relative_file;
    }

    private function build_imagekit_endpoint_url( $endpoint, $rewritten_path ) {
        $endpoint = untrailingslashit( (string) $endpoint );
        $endpoint_path = wp_parse_url( $endpoint, PHP_URL_PATH );
        $endpoint_segments = is_string( $endpoint_path ) ? array_values( array_filter( explode( '/', trim( $endpoint_path, '/' ) ) ) ) : array();
        $path_segments = array_values( array_filter( explode( '/', trim( (string) $rewritten_path, '/' ) ) ) );

        if ( ! empty( $endpoint_segments ) && count( $path_segments ) >= count( $endpoint_segments ) ) {
            $prefix = array_slice( $path_segments, 0, count( $endpoint_segments ) );
            if ( implode( '/', $prefix ) === implode( '/', $endpoint_segments ) ) {
                $path_segments = array_slice( $path_segments, count( $endpoint_segments ) );
            }
        }

        return $endpoint . '/' . implode( '/', $path_segments );
    }

    private function normalize_imagekit_rewritten_path( $rewritten_path, $mode ) {
        $path = preg_replace( '#/+#', '/', (string) $rewritten_path );
        $path = is_string( $path ) ? $path : (string) $rewritten_path;
        $path = ltrim( $path, '/' );

        if ( 'uploads_relative_path' === $mode ) {
            $path = preg_replace( '#^wp-content/uploads/#i', '', $path );
            $path = preg_replace( '#^uploads/#i', '', $path );
        }

        return trim( (string) preg_replace( '#(^|/)uploads/uploads(/|$)#i', '$1uploads$2', $path ), '/' );
    }

    private function strip_endpoint_prefix_from_rewritten_path( $endpoint, $rewritten_path ) {
        $endpoint_path = wp_parse_url( (string) $endpoint, PHP_URL_PATH );
        if ( ! is_string( $endpoint_path ) || '' === $endpoint_path || '/' === $endpoint_path ) {
            return trim( (string) $rewritten_path, '/' );
        }

        $endpoint_segments = array_values( array_filter( explode( '/', trim( $endpoint_path, '/' ) ) ) );
        $path_segments = array_values( array_filter( explode( '/', trim( (string) $rewritten_path, '/' ) ) ) );

        if ( empty( $endpoint_segments ) || count( $path_segments ) < count( $endpoint_segments ) ) {
            return trim( (string) $rewritten_path, '/' );
        }

        $prefix = array_slice( $path_segments, 0, count( $endpoint_segments ) );
        if ( implode( '/', $prefix ) === implode( '/', $endpoint_segments ) ) {
            $path_segments = array_slice( $path_segments, count( $endpoint_segments ) );
        }

        return implode( '/', $path_segments );
    }

    private function detect_duplicate_path_segments( $path ) {
        $segments = array_values( array_filter( explode( '/', trim( (string) $path, '/' ) ) ) );
        $duplicates = array();

        for ( $i = 1, $count = count( $segments ); $i < $count; $i++ ) {
            if ( strtolower( $segments[ $i ] ) === strtolower( $segments[ $i - 1 ] ) ) {
                $duplicates[] = $segments[ $i ];
            }
        }

        return array_values( array_unique( $duplicates ) );
    }

    public function render_advanced_page() {
        $options = get_option( $this->option_name, array() );
        ?>
        <div class="wrap wpfl-wrap">
            <?php $this->render_page_header( __( 'Advanced Rules', 'fastlayer' ), __( 'Exclude URLs, cookies and user agents from caching for compatibility.', 'fastlayer' ) ); ?>

            <div class="wpfl-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( $this->option_name ); ?>
                    <div class="wpfl-section">
                        <h2>Never Cache URLs</h2>
                        <div class="wpfl-field">
                            <label for="exclude_urls"><?php echo esc_html__( 'URLs to never cache (one per line)', 'fastlayer' ); ?></label>
                            <textarea id="exclude_urls" name="<?php echo esc_attr( $this->option_name ); ?>[exclude_urls]" rows="5" class="large-text"><?php echo esc_textarea( $options['exclude_urls'] ?? '' ); ?></textarea>
                            <p class="description"><?php echo esc_html__( 'Enter URLs that should never be cached. One URL per line. You can use wildcards like /category/*', 'fastlayer' ); ?></p>
                        </div>
                    </div>

                    <div class="wpfl-section">
                        <h2>Never Cache Cookies</h2>
                        <div class="wpfl-field">
                            <label for="exclude_cookies"><?php echo esc_html__( 'Cookies to never cache (one per line)', 'fastlayer' ); ?></label>
                            <textarea id="exclude_cookies" name="<?php echo esc_attr( $this->option_name ); ?>[exclude_cookies]" rows="5" class="large-text"><?php echo esc_textarea( $options['exclude_cookies'] ?? '' ); ?></textarea>
                            <p class="description"><?php echo esc_html__( 'Enter cookie names that should prevent caching when present. One cookie per line.', 'fastlayer' ); ?></p>
                        </div>
                    </div>

                    <div class="wpfl-section">
                        <h2>Never Cache User Agents</h2>
                        <div class="wpfl-field">
                            <label for="exclude_user_agents"><?php echo esc_html__( 'User agents to never cache (one per line)', 'fastlayer' ); ?></label>
                            <textarea id="exclude_user_agents" name="<?php echo esc_attr( $this->option_name ); ?>[exclude_user_agents]" rows="5" class="large-text"><?php echo esc_textarea( $options['exclude_user_agents'] ?? '' ); ?></textarea>
                            <p class="description"><?php echo esc_html__( 'Enter user agent strings that should never be cached. One user agent per line.', 'fastlayer' ); ?></p>
                        </div>
                    </div>

                    <div class="wpfl-section">
                        <h2>Debug Mode</h2>
                        <div class="wpfl-field">
                            <label class="wpfl-toggle">
                                <?php $this->render_toggle_input( 'debug_mode', $options['debug_mode'] ?? 0 ); ?>
                                <span class="wpfl-toggle-slider"></span>
                                <span class="wpfl-toggle-label"><?php echo esc_html__( 'Enable Debug Mode', 'fastlayer' ); ?></span>
                            </label>
                            <p class="description"><?php echo esc_html__( 'Enable debug logging to troubleshoot caching issues. Logs are saved in the plugin\'s logs directory.', 'fastlayer' ); ?></p>
                        </div>
                    </div>

                    <?php $this->render_save_bar(); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_database_page() {
        $options = get_option( $this->option_name, array() );
        $db_optimization = Database_Optimization::get_instance();
        $db_size = $db_optimization->get_database_size();
        $table_count = $db_optimization->get_table_count();
        $cleanup_stats = $db_optimization->get_cleanup_statistics();
        $cleanup_counts = isset( $cleanup_stats['counts'] ) && is_array( $cleanup_stats['counts'] ) ? $cleanup_stats['counts'] : array();
        $transient_breakdown = isset( $cleanup_stats['transients'] ) && is_array( $cleanup_stats['transients'] )
            ? $cleanup_stats['transients']
            : $db_optimization->get_transient_count_breakdown();

        /**
         * Resolves the current count of a single cleanup item from the shared
         * statistics snapshot so the page and the post-cleanup AJAX refresh
         * always use exactly the same queries.
         */
        $cleanup_item_count = static function ( $item_id ) use ( $cleanup_counts, $db_optimization ) {
            if ( isset( $cleanup_counts[ $item_id ] ) ) {
                return (int) $cleanup_counts[ $item_id ];
            }

            return 0;
        };

        $cleanup_notice = array();
        $cleanup_notice_key = 'wp_fastlayer_db_cleanup_notice_' . get_current_user_id();
        $stored_cleanup_notice = get_transient( $cleanup_notice_key );
        if ( is_array( $stored_cleanup_notice ) ) {
            $cleanup_notice = $stored_cleanup_notice;
            delete_transient( $cleanup_notice_key );
        }

        $db_optimized = isset( $_GET['db_optimized'] ) ? sanitize_text_field( wp_unslash( $_GET['db_optimized'] ) ) : '';
        
        $cleanup_items = array(
            array(
                'id' => 'db_clean_post_revisions',
                'label' => 'Post Revisions',
                'count' => $cleanup_item_count( 'db_clean_post_revisions' ),
                'description' => 'Remove old post revisions'
            ),
            array(
                'id' => 'db_clean_trashed_posts',
                'label' => 'Trashed Posts',
                'count' => $cleanup_item_count( 'db_clean_trashed_posts' ),
                'description' => 'Remove posts currently in trash'
            ),
            array(
                'id' => 'db_clean_spam_comments',
                'label' => 'Spam Comments',
                'count' => $cleanup_item_count( 'db_clean_spam_comments' ),
                'description' => 'Remove comments marked as spam'
            ),
            array(
                'id' => 'db_clean_auto_drafts',
                'label' => 'Auto Drafts',
                'count' => $cleanup_item_count( 'db_clean_auto_drafts' ),
                'description' => 'Remove auto-draft posts created by WordPress'
            ),
            array(
                'id' => 'db_clean_all_transients',
                'label' => 'All Transients',
                'count' => $cleanup_item_count( 'db_clean_all_transients' ),
                'description' => __( 'Removable candidates only. Timeout rows: %timeout%, Active runtime: %active%, Expired timeout rows: %expired%, Runtime no-timeout rows: %no_timeout%, Orphan timeout rows: %orphans%', 'fastlayer' )
            ),
            array(
                'id' => 'db_clean_duplicated_postmeta',
                'label' => 'Duplicated Posts metadata',
                'count' => $cleanup_item_count( 'db_clean_duplicated_postmeta' ),
                'description' => 'Remove duplicate post metadata'
            ),
            array(
                'id' => 'db_clean_duplicated_commentmeta',
                'label' => 'Duplicated Comments metadata',
                'count' => $cleanup_item_count( 'db_clean_duplicated_commentmeta' ),
                'description' => 'Remove duplicate comment metadata'
            ),
            array(
                'id' => 'db_clean_duplicated_usermeta',
                'label' => 'Duplicated User metadata',
                'count' => $cleanup_item_count( 'db_clean_duplicated_usermeta' ),
                'description' => 'Remove duplicate user metadata'
            ),
            array(
                'id' => 'db_clean_duplicated_termmeta',
                'label' => 'Duplicated Term metadata',
                'count' => $cleanup_item_count( 'db_clean_duplicated_termmeta' ),
                'description' => 'Remove duplicate term metadata'
            ),
            array(
                'id' => 'db_clean_expired_transients',
                'label' => 'Expired Transients',
                'count' => $cleanup_item_count( 'db_clean_expired_transients' ),
                'description' => 'Remove expired transient options'
            ),
            array(
                'id' => 'db_clean_oembed_cache',
                'label' => 'oEmbed in Posts metadata',
                'count' => $cleanup_item_count( 'db_clean_oembed_cache' ),
                'description' => 'Clean oEmbed cache in post metadata'
            ),
            array(
                'id' => 'db_clean_orphaned_postmeta',
                'label' => 'Orphaned Posts metadata',
                'count' => $cleanup_item_count( 'db_clean_orphaned_postmeta' ),
                'description' => 'Remove metadata for deleted posts'
            ),
            array(
                'id' => 'db_clean_orphaned_usermeta',
                'label' => 'Orphan User metadata',
                'count' => $cleanup_item_count( 'db_clean_orphaned_usermeta' ),
                'description' => 'Remove metadata for deleted users'
            ),
            array(
                'id' => 'db_clean_orphaned_termmeta',
                'label' => 'Orphan Term metadata',
                'count' => $cleanup_item_count( 'db_clean_orphaned_termmeta' ),
                'description' => 'Remove metadata for deleted terms'
            ),
            array(
                'id' => 'db_clean_orphaned_commentmeta',
                'label' => 'Orphaned Comments metadata',
                'count' => $cleanup_item_count( 'db_clean_orphaned_commentmeta' ),
                'description' => 'Remove metadata for deleted comments'
            )
        );
        ?>
        <div class="wrap wpfl-wrap">
            <?php if ( ! empty( $cleanup_notice ) ) : ?>
                <?php $notice_class = ! empty( $cleanup_notice['success'] ) ? 'notice-success' : 'notice-error'; ?>
                <div class="notice <?php echo esc_attr( $notice_class ); ?> wpfastlayer-notice is-dismissible">
                    <?php if ( ! empty( $cleanup_notice['success'] ) ) : ?>
                        <p><strong><?php echo esc_html__( 'Database cleanup completed successfully.', 'fastlayer' ); ?></strong></p>
                        <?php if ( isset( $cleanup_notice['rows_removed'] ) ) : ?>
                            <p><?php echo esc_html( sprintf( __( 'Total rows removed: %d', 'fastlayer' ), absint( $cleanup_notice['rows_removed'] ) ) ); ?></p>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php if ( isset( $cleanup_notice['rows_removed'] ) && 0 === absint( $cleanup_notice['rows_removed'] ) ) : ?>
                            <p><strong><?php echo esc_html__( 'Database cleanup ran, but no matching records were removed.', 'fastlayer' ); ?></strong></p>
                        <?php else : ?>
                            <p><strong><?php echo esc_html__( 'Database cleanup completed with errors.', 'fastlayer' ); ?></strong></p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ( ! empty( $cleanup_notice['stats'] ) && is_array( $cleanup_notice['stats'] ) ) : ?>
                        <?php foreach ( $cleanup_notice['stats'] as $stat_item ) : ?>
                            <?php
                            $label = isset( $stat_item['label'] ) ? sanitize_text_field( $stat_item['label'] ) : '';
                            $count = isset( $stat_item['count'] ) ? absint( $stat_item['count'] ) : 0;
                            if ( '' === $label ) {
                                continue;
                            }
                            ?>
                            <p><?php echo esc_html( sprintf( '%s: %d', $label, $count ) ); ?></p>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ( ! empty( $cleanup_notice['errors'] ) && is_array( $cleanup_notice['errors'] ) ) : ?>
                        <?php foreach ( $cleanup_notice['errors'] as $error_item ) : ?>
                            <?php if ( ! empty( $error_item ) ) : ?>
                                <p><?php echo esc_html( $error_item ); ?></p>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ( ! empty( $cleanup_notice['diagnostics'] ) && is_array( $cleanup_notice['diagnostics'] ) ) : ?>
                    <div class="notice notice-info wpfastlayer-notice">
                        <p><strong><?php echo esc_html__( 'Temporary Cleanup Diagnostics', 'fastlayer' ); ?></strong></p>
                        <table class="widefat striped" style="margin-top: 8px;">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__( 'Cleanup Type', 'fastlayer' ); ?></th>
                                    <th><?php echo esc_html__( 'Before', 'fastlayer' ); ?></th>
                                    <th><?php echo esc_html__( 'IDs Detected', 'fastlayer' ); ?></th>
                                    <th><?php echo esc_html__( 'Delete Attempts', 'fastlayer' ); ?></th>
                                    <th><?php echo esc_html__( 'Successful Deletions', 'fastlayer' ); ?></th>
                                    <th><?php echo esc_html__( 'Failed Deletions', 'fastlayer' ); ?></th>
                                    <th><?php echo esc_html__( 'After', 'fastlayer' ); ?></th>
                                    <th><?php echo esc_html__( 'Validation', 'fastlayer' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $cleanup_notice['diagnostics'] as $diag_item ) : ?>
                                    <?php
                                    $diag_label = isset( $diag_item['label'] ) ? sanitize_text_field( $diag_item['label'] ) : '';
                                    if ( '' === $diag_label ) {
                                        continue;
                                    }

                                    $before = isset( $diag_item['before_count'] ) && null !== $diag_item['before_count'] ? absint( $diag_item['before_count'] ) : '-';
                                    $ids_detected = isset( $diag_item['ids_detected'] ) && null !== $diag_item['ids_detected'] ? absint( $diag_item['ids_detected'] ) : '-';
                                    $attempts = isset( $diag_item['delete_attempts'] ) && null !== $diag_item['delete_attempts'] ? absint( $diag_item['delete_attempts'] ) : '-';
                                    $successes = isset( $diag_item['successful_deletions'] ) && null !== $diag_item['successful_deletions'] ? absint( $diag_item['successful_deletions'] ) : '-';
                                    $failures = isset( $diag_item['failed_deletions'] ) && null !== $diag_item['failed_deletions'] ? absint( $diag_item['failed_deletions'] ) : '-';
                                    $after = isset( $diag_item['after_count'] ) && null !== $diag_item['after_count'] ? absint( $diag_item['after_count'] ) : '-';
                                    $validation = isset( $diag_item['validation'] ) ? sanitize_text_field( $diag_item['validation'] ) : 'unknown';
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( $diag_label ); ?></td>
                                        <td><?php echo esc_html( (string) $before ); ?></td>
                                        <td><?php echo esc_html( (string) $ids_detected ); ?></td>
                                        <td><?php echo esc_html( (string) $attempts ); ?></td>
                                        <td><?php echo esc_html( (string) $successes ); ?></td>
                                        <td><?php echo esc_html( (string) $failures ); ?></td>
                                        <td><?php echo esc_html( (string) $after ); ?></td>
                                        <td><?php echo esc_html( $validation ); ?></td>
                                    </tr>
                                    <?php if ( ! empty( $diag_item['notes'] ) && is_array( $diag_item['notes'] ) ) : ?>
                                        <tr>
                                            <td colspan="8">
                                                <?php foreach ( $diag_item['notes'] as $diag_note ) : ?>
                                                    <?php if ( ! empty( $diag_note ) ) : ?>
                                                        <div><?php echo esc_html( $diag_note ); ?></div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php elseif ( 'success' === $db_optimized ) : ?>
                <div class="notice notice-success wpfastlayer-notice is-dismissible">
                    <p><?php echo esc_html__( 'Database optimized successfully!', 'fastlayer' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( 'error' === $db_optimized ) : ?>
                <div class="notice notice-error wpfastlayer-notice is-dismissible">
                    <p><?php echo esc_html__( 'Database optimization failed. Please try again.', 'fastlayer' ); ?></p>
                </div>
            <?php endif; ?>

            <?php $this->render_page_header( __( 'Database Options', 'fastlayer' ), __( 'Optimize your database and clean up unused items safely.', 'fastlayer' ) ); ?>

            <div class="wpfl-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( $this->option_name ); ?>
                <div class="wpfl-db-header">
                    <div class="wpfl-db-header-left">
                        <h2>Clean All</h2>
                        <p class="description">Enable all cleanup options at once</p>
                    </div>
                    <div class="wpfl-db-header-right">
                        <label class="wpfl-toggle">
                            <input type="checkbox" id="wpfl-clean-all-toggle">
                            <span class="wpfl-toggle-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="wpfl-db-cards-grid">
                    <?php foreach ( $cleanup_items as $item ) : ?>
                        <div class="wpfl-db-card" data-cleanup-key="<?php echo esc_attr( $item['id'] ); ?>">
                            <div class="wpfl-db-card-header">
                                <h3><?php echo esc_html( $item['label'] ); ?></h3>
                                <span class="wpfl-db-count"><?php echo esc_html( number_format_i18n( $item['count'] ) ); ?></span>
                            </div>
                            <p class="wpfl-db-description">
                                <?php if ( 'db_clean_all_transients' === $item['id'] ) : ?>
                                    <span
                                        class="wpfl-db-description-breakdown"
                                        data-transient-breakdown="<?php echo esc_attr( $item['description'] ); ?>"
                                    ><?php echo esc_html( $this->format_transient_breakdown( $item['description'], $transient_breakdown ) ); ?></span>
                                <?php else : ?>
                                    <?php echo esc_html( $item['description'] ); ?>
                                <?php endif; ?>
                            </p>
                            <label class="wpfl-toggle wpfl-toggle-small">
                                <?php $this->render_toggle_input( $item['id'], $options[$item['id']] ?? 0, 'wpfl-db-cleanup-toggle', 'data-cleanup-key="' . esc_attr( $item['id'] ) . '"' ); ?>
                                <span class="wpfl-toggle-slider"></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="wpfl-db-actions">
                    <?php $this->render_save_bar(); ?>
                </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpfl-action-form wpfl-db-cleanup-form" id="wpfl-db-cleanup-form">
                        <input type="hidden" name="action" value="wp_fastlayer_optimize_database">
                        <input type="hidden" name="redirect_page" value="wp-fastlayer-database">
                        <?php if ( isset( $_GET['section'] ) ) : ?>
                            <input type="hidden" name="redirect_section" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['section'] ) ) ); ?>">
                        <?php endif; ?>
                        <?php
                        /*
                         * Server rendered mirror of the cleanup toggles. The
                         * database cleanup form posts to admin-post.php while the
                         * toggles live in the settings form, so the selection is
                         * mirrored here to keep the non JavaScript fallback
                         * submitting the items that are actually enabled. The
                         * inline script keeps these values in sync.
                         */
                        foreach ( $cleanup_items as $item ) :
                            ?>
                            <input
                                type="hidden"
                                class="wpfl-db-cleanup-fallback"
                                name="cleanup_items[<?php echo esc_attr( $item['id'] ); ?>]"
                                value="<?php echo empty( $options[ $item['id'] ] ) ? '0' : '1'; ?>"
                                data-cleanup-key="<?php echo esc_attr( $item['id'] ); ?>"
                            >
                        <?php endforeach; ?>
                        <?php wp_nonce_field( 'wp_fastlayer_optimize_database_nonce' ); ?>
                        <button type="submit" class="wpfl-btn wpfl-btn-danger" id="wpfl-db-cleanup-submit">
                            <span class="dashicons dashicons-trash"></span>
                            <span class="wpfl-db-cleanup-btn-text"><?php echo esc_html__( 'Clean Now', 'fastlayer' ); ?></span>
                            <span class="wpfl-db-cleanup-spinner" aria-hidden="true"></span>
                        </button>
                    </form>
                </div>
            </div>

            <?php $this->render_database_cleanup_modal(); ?>
        </div>
        <?php
    }

    /**
     * Fills the named placeholders of the transient breakdown template with the
     * current counts, keeping the server rendered text identical to the text
     * the admin script writes after a cleanup finishes.
     */
    private function format_transient_breakdown( $template, $transient_breakdown ) {
        $map = array(
            '%timeout%' => isset( $transient_breakdown['timeout_rows'] ) ? (int) $transient_breakdown['timeout_rows'] : 0,
            '%active%' => isset( $transient_breakdown['active_runtime_rows'] ) ? (int) $transient_breakdown['active_runtime_rows'] : 0,
            '%expired%' => isset( $transient_breakdown['expired_timeout_rows'] ) ? (int) $transient_breakdown['expired_timeout_rows'] : 0,
            '%no_timeout%' => isset( $transient_breakdown['runtime_no_timeout_rows'] ) ? (int) $transient_breakdown['runtime_no_timeout_rows'] : 0,
            '%orphans%' => isset( $transient_breakdown['orphan_timeout_rows'] ) ? (int) $transient_breakdown['orphan_timeout_rows'] : 0,
        );

        return str_replace( array_keys( $map ), array_map( 'number_format_i18n', array_values( $map ) ), (string) $template );
    }

    /**
     * Progress overlay shown while the database cleanup request is running.
     *
     * The cleanup runs as a single synchronous request, so the progress bar is
     * intentionally indeterminate: it communicates that work is in progress
     * without reporting a percentage that the backend cannot provide.
     */
    private function render_database_cleanup_modal() {
        ?>
        <div class="wpfl-db-cleanup-modal" id="wpfl-db-cleanup-modal" role="dialog" aria-modal="true" aria-labelledby="wpfl-db-cleanup-modal-title" aria-describedby="wpfl-db-cleanup-modal-status" hidden>
            <div class="wpfl-db-cleanup-modal-backdrop"></div>
            <div class="wpfl-db-cleanup-modal-dialog" role="document">
                <h2 class="wpfl-db-cleanup-modal-title" id="wpfl-db-cleanup-modal-title"><?php echo esc_html__( 'Database cleanup in progress', 'fastlayer' ); ?></h2>
                <p class="wpfl-db-cleanup-modal-status" id="wpfl-db-cleanup-modal-status" role="status" aria-live="polite"><?php echo esc_html__( 'Starting database cleanup...', 'fastlayer' ); ?></p>

                <div class="wpfl-db-cleanup-modal-progress" data-state="running">
                    <div class="wpfl-db-cleanup-modal-progress-bar"><span></span></div>
                </div>

                <p class="wpfl-db-cleanup-modal-rows-removed" hidden></p>

                <div class="wpfl-db-cleanup-modal-results" hidden>
                    <p class="wpfl-db-cleanup-modal-results-header"><?php echo esc_html__( 'Cleanup results', 'fastlayer' ); ?></p>
                    <ul></ul>
                </div>

                <div class="wpfl-db-cleanup-modal-errors" hidden></div>

                <div class="wpfl-db-cleanup-modal-footer">
                    <button type="button" class="button wpfl-db-cleanup-modal-close" hidden><?php echo esc_html__( 'Close', 'fastlayer' ); ?></button>
                    <button type="button" class="button button-primary wpfl-db-cleanup-modal-retry" hidden><?php echo esc_html__( 'Try Again', 'fastlayer' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}
