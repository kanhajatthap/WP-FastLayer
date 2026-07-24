<?php
namespace WP_FastLayer;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Page_Cache {
    private static $instance = null;
    private $cache_dir;
    private $buffer_started = false;
    private $captured_buffer_content = '';
    private $debug_trace = array(
        'cache_init' => false,
        'template_redirect' => false,
        'buffer_started' => false,
        'shutdown_triggered' => false,
        'save_cache_started' => false,
        'cache_file' => '',
        'cache_dir' => '',
        'skip_reason' => '',
        'save_result' => '',
        'last_url' => '',
        'last_hook' => '',
    );
    private $is_running = false;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->cache_dir = WP_FASTLAYER_CACHE_DIR;
        $this->debug_trace['cache_init'] = true;
        $this->debug_trace['cache_dir'] = \wp_normalize_path( $this->cache_dir );

        \wp_fastlayer_debug_log( 'WP FastLayer: Page_Cache constructor initializing.' );
        \wp_fastlayer_debug_log( 'WP FastLayer: Cache directory is ' . \wp_normalize_path( $this->cache_dir ) );

        \add_action( 'template_redirect', array( $this, 'maybe_serve_cache' ), 1 );
        \add_action( 'wp_footer', array( $this, 'print_frontend_debug_script' ), 9999 );
        \add_action( 'shutdown', array( $this, 'save_cache' ), 999 );

        \add_action( 'save_post', array( $this, 'clear_cache_on_post_update' ), 10, 2 );
        \add_action( 'deleted_post', array( $this, 'clear_cache_on_post_delete' ) );
        \add_action( 'edit_post', array( $this, 'clear_cache_on_post_update' ), 10, 2 );
        \add_action( 'switch_theme', array( $this, 'clear_all_cache' ) );
        \add_action( 'wp_trash_post', array( $this, 'clear_cache_on_post_delete' ) );
        \add_action( 'comment_post', array( $this, 'clear_cache_on_comment' ), 10, 3 );
        \add_action( 'edit_comment', array( $this, 'clear_cache_on_comment' ), 10, 2 );
        \add_action( 'create_term', array( $this, 'clear_all_cache' ), 10, 3 );
        \add_action( 'edit_term', array( $this, 'clear_all_cache' ), 10, 3 );
        \add_action( 'delete_term', array( $this, 'clear_all_cache' ), 10, 3 );

        \add_action( 'wp_fastlayer_preload_cache', array( $this, 'preload_cache' ) );

        \wp_fastlayer_debug_log( 'WP FastLayer: Page_Cache hooks registered.' );
    }

    public function maybe_serve_cache() {
        $url = $this->get_current_url();
        $this->debug_trace['last_url'] = $url;
        $this->debug_trace['last_hook'] = 'maybe_serve_cache';
        $this->debug_trace['template_redirect'] = true;
        $this->debug_trace['cache_file'] = '';
        $this->debug_trace['save_result'] = '';

        \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache started for URL: ' . $url );
        $skip_reasons = array();

        if ( ! $this->is_cache_enabled() ) {
            $skip_reasons[] = 'cache_disabled';
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skip condition cache_disabled.' );
        }
        if ( \is_user_logged_in() ) {
            $skip_reasons[] = 'user_logged_in';
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skip condition user_logged_in.' );
        }
        if ( \wp_doing_ajax() ) {
            $skip_reasons[] = 'ajax';
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skip condition ajax.' );
        }
        if ( \wp_doing_cron() ) {
            $skip_reasons[] = 'cron';
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skip condition cron.' );
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $skip_reasons[] = 'rest_request';
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skip condition rest_request.' );
        }
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            $skip_reasons[] = 'xmlrpc';
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skip condition xmlrpc.' );
        }
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && in_array( $_SERVER['REQUEST_METHOD'], array( 'POST', 'HEAD' ), true ) ) {
            $skip_reasons[] = 'method_' . strtoupper( $_SERVER['REQUEST_METHOD'] );
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skip condition method_' . strtoupper( $_SERVER['REQUEST_METHOD'] ) . '.' );
        }
        if ( \is_admin() ) {
            $skip_reasons[] = 'is_admin';
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skip condition is_admin.' );
        }
        if ( $this->should_skip_cache() ) {
            $skip_reasons[] = 'should_skip_cache';
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skip condition should_skip_cache.' );
        }

        if ( ! empty( $skip_reasons ) ) {
            $this->debug_trace['skip_reason'] = implode( ', ', $skip_reasons );
            $this->persist_debug_trace();
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skipping cache for URL: ' . $url . ' ; reasons: ' . implode( ', ', $skip_reasons ) );
            return;
        }

        $this->send_accept_vary_header();

        $cache_file = $this->get_cache_file();
        $this->debug_trace['cache_file'] = \wp_normalize_path( $cache_file );
        $this->debug_trace['cache_dir'] = \wp_normalize_path( $this->cache_dir );
        \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache cache candidate path: ' . \wp_normalize_path( $cache_file ) );

        if ( file_exists( $cache_file ) ) {
            if ( $this->is_cache_expired( $cache_file ) ) {
                @unlink( $cache_file );
                $this->debug_trace['save_result'] = 'expired_removed';
                $this->persist_debug_trace();
                \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache expired cache removed: ' . \wp_normalize_path( $cache_file ) );
            } else {
                $this->debug_trace['save_result'] = 'serving_cache';
                $this->persist_debug_trace();
                \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache serving cache file: ' . \wp_normalize_path( $cache_file ) );
                $this->serve_cache( $cache_file );
                return;
            }
        }

        if ( ! $this->buffer_started ) {
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache starting output buffer for URL: ' . $url );
            \ob_start( array( $this, 'capture_output_buffer' ) );
            $this->buffer_started = true;
            $this->debug_trace['buffer_started'] = true;
            $this->persist_debug_trace();
        } else {
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache output buffer already active.' );
        }
    }

    private function is_cache_enabled() {
        $options = \get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_cache'] ) && '1' === $options['enable_cache'];
    }

    private function should_skip_cache() {
        if ( function_exists( '\is_preview' ) && \is_preview() ) {
            return true;
        }

        if ( function_exists( '\is_404' ) && \is_404() ) {
            return true;
        }

        if ( function_exists( '\is_search' ) && \is_search() ) {
            return true;
        }

        $skip_paths = array(
            '/wp-admin/',
            '/wp-login.php',
            '/wp-register.php',
            '/cart/',
            '/checkout/',
            '/my-account/',
            '/feed/',
        );

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? \esc_url_raw( \wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        foreach ( $skip_paths as $path ) {
            if ( false !== strpos( $request_uri, $path ) ) {
                return true;
            }
        }

        if ( $this->is_excluded_url( $request_uri ) ) {
            return true;
        }

        if ( $this->has_excluded_cookie() ) {
            return true;
        }

        if ( $this->is_excluded_user_agent() ) {
            return true;
        }

        return false;
    }

    private function get_cache_file() {
        $url       = $this->get_current_url();
        $cache_key = $this->generate_cache_key( $url );
        $cache_file = \trailingslashit( \wp_normalize_path( $this->cache_dir ) ) . $cache_key . '.html';

        \wp_fastlayer_debug_log( 'WP FastLayer: get_cache_file generated file path: ' . \wp_normalize_path( $cache_file ) );
        return $cache_file;
    }

    private function get_cache_expiration() {
        $options = \get_option( 'wp_fastlayer_options', array() );
        $hours = isset( $options['cache_expiration'] ) ? \absint( $options['cache_expiration'] ) : 10;
        return max( 1, $hours ) * HOUR_IN_SECONDS;
    }

    private function is_excluded_url( $request_uri ) {
        $options = \get_option( 'wp_fastlayer_options', array() );
        $patterns = isset( $options['exclude_urls'] ) ? \preg_split( '/\r?\n/', trim( $options['exclude_urls'] ) ) : array();
        return $this->is_excluded_by_patterns( $request_uri, $patterns );
    }

    private function has_excluded_cookie() {
        $options = \get_option( 'wp_fastlayer_options', array() );
        $patterns = isset( $options['exclude_cookies'] ) ? \preg_split( '/\r?\n/', trim( $options['exclude_cookies'] ) ) : array();

        foreach ( $patterns as $pattern ) {
            $pattern = trim( $pattern );
            if ( '' === $pattern ) {
                continue;
            }

            foreach ( $_COOKIE as $name => $value ) {
                if ( stripos( $name, $pattern ) !== false ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function is_excluded_user_agent() {
        $options = \get_option( 'wp_fastlayer_options', array() );
        $patterns = isset( $options['exclude_user_agents'] ) ? \preg_split( '/\r?\n/', trim( $options['exclude_user_agents'] ) ) : array();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

        return $this->is_excluded_by_patterns( $user_agent, $patterns );
    }

    private function is_excluded_by_patterns( $value, $patterns ) {
        foreach ( $patterns as $pattern ) {
            $pattern = trim( $pattern );
            if ( '' === $pattern ) {
                continue;
            }

            if ( false !== strpos( $pattern, '*' ) || false !== strpos( $pattern, '(.*)' ) ) {
                $regex = '#^' . str_replace( array( '\*', '(.*)' ), array( '.*', '.*' ), preg_quote( $pattern, '#' ) ) . '$#i';
                if ( preg_match( $regex, $value ) || preg_match( $regex, $this->strip_url_domain( $value ) ) ) {
                    return true;
                }
                continue;
            }

            $value_compare = $this->strip_url_domain( $value );
            $pattern_compare = $this->strip_url_domain( $pattern );

            if ( stripos( $value_compare, $pattern_compare ) !== false || stripos( $value, $pattern ) !== false ) {
                return true;
            }
        }

        return false;
    }

    private function strip_url_domain( $url ) {
        $parsed = \wp_parse_url( $url );
        if ( empty( $parsed['path'] ) ) {
            return $url;
        }

        $path = $parsed['path'];
        if ( isset( $parsed['query'] ) ) {
            $path .= '?' . $parsed['query'];
        }

        return $path;
    }

    private function get_current_url() {
        $protocol    = \is_ssl() ? 'https' : 'http';
        $host        = isset( $_SERVER['HTTP_HOST'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? \esc_url_raw( \wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        return $protocol . '://' . $host . $request_uri;
    }

    private function generate_cache_key( $url ) {
        return md5( $url . '|' . $this->get_accept_cache_variant() );
    }

    private function is_cache_expired( $file ) {
        return ( time() - filemtime( $file ) ) > $this->get_cache_expiration();
    }

    private function serve_cache( $file ) {
        if ( ! headers_sent() ) {
            $expiration = $this->get_cache_expiration();
            $this->send_accept_vary_header();
            header( 'X-Cache-Status: HIT' );
            header( 'Cache-Control: public, max-age=' . $expiration . ', s-maxage=' . $expiration );
            header( 'X-Cache-Generated: yes' );
        }
        readfile( $file );
        exit;
    }

    public function save_cache() {
        $url = $this->get_current_url();
        $this->debug_trace['last_url'] = $url;
        $this->debug_trace['last_hook'] = 'save_cache';
        $this->debug_trace['shutdown_triggered'] = true;
        $this->debug_trace['save_cache_started'] = true;

        \wp_fastlayer_debug_log( 'WP FastLayer: save_cache started for URL: ' . $url );
        $skip_reasons = array();

        if ( ! $this->is_cache_enabled() ) {
            $skip_reasons[] = 'cache_disabled';
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition cache_disabled.' );
        }
        if ( ! $this->buffer_started ) {
            $skip_reasons[] = 'buffer_not_started';
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition buffer_not_started.' );
        }
        if ( \is_user_logged_in() ) {
            $skip_reasons[] = 'user_logged_in';
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition user_logged_in.' );
        }
        if ( \wp_doing_ajax() ) {
            $skip_reasons[] = 'ajax';
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition ajax.' );
        }
        if ( \wp_doing_cron() ) {
            $skip_reasons[] = 'cron';
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition cron.' );
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $skip_reasons[] = 'rest_request';
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition rest_request.' );
        }
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            $skip_reasons[] = 'xmlrpc';
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition xmlrpc.' );
        }
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && in_array( $_SERVER['REQUEST_METHOD'], array( 'POST', 'HEAD' ), true ) ) {
            $skip_reasons[] = 'method_' . strtoupper( $_SERVER['REQUEST_METHOD'] );
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition method_' . strtoupper( $_SERVER['REQUEST_METHOD'] ) . '.' );
        }
        if ( \is_admin() ) {
            $skip_reasons[] = 'is_admin';
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition is_admin.' );
        }
        if ( $this->should_skip_cache() ) {
            $skip_reasons[] = 'should_skip_cache';
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition should_skip_cache.' );
        }

        if ( ! empty( $skip_reasons ) ) {
            $this->debug_trace['skip_reason'] = implode( ', ', $skip_reasons );
            $this->persist_debug_trace();
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skipping save for URL: ' . $url . ' ; reasons: ' . implode( ', ', $skip_reasons ) );
            return;
        }

        if ( \is_404() || \is_search() ) {
            $this->debug_trace['skip_reason'] = \is_404() ? '404' : 'search';
            $this->persist_debug_trace();
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skipping save for 404/search URL: ' . $url );
            return;
        }

        $cache_file = $this->get_cache_file();
        $this->debug_trace['cache_file'] = \wp_normalize_path( $cache_file );
        $this->debug_trace['cache_dir'] = \wp_normalize_path( $this->cache_dir );
        \wp_fastlayer_debug_log( 'WP FastLayer: save_cache attempting to save cache file: ' . \wp_normalize_path( $cache_file ) );

        if ( ! \is_dir( $this->cache_dir ) ) {
            $mkdir_result = \wp_mkdir_p( $this->cache_dir );
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache cache directory creation result: ' . ( $mkdir_result ? 'success' : 'failure' ) . ' ; dir: ' . \wp_normalize_path( $this->cache_dir ) );
        }

        if ( ! is_dir( $this->cache_dir ) || ! is_writable( $this->cache_dir ) ) {
            $this->debug_trace['save_result'] = 'cache_dir_unavailable';
            $this->persist_debug_trace();
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache cache directory unavailable or not writable: ' . \wp_normalize_path( $this->cache_dir ) );
            return;
        }

        if ( ! $this->is_safe_path( $cache_file ) ) {
            $this->debug_trace['save_result'] = 'unsafe_path';
            $this->persist_debug_trace();
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache unsafe cache path prevented write: ' . \wp_normalize_path( $cache_file ) );
            return;
        }

        $content = \ob_get_contents();
        if ( ! $content && ! empty( $this->captured_buffer_content ) ) {
            $content = $this->captured_buffer_content;
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache using captured buffer fallback; length=' . strlen( $content ) );
        }
        if ( ! $content ) {
            $this->debug_trace['save_result'] = 'empty_buffer';
            $this->persist_debug_trace();
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache no buffer content available for URL: ' . $url );
            return;
        }

        $written = @file_put_contents( $cache_file, $content, LOCK_EX );
        if ( false === $written ) {
            $error = error_get_last();
            $this->debug_trace['save_result'] = 'write_failure';
            $this->persist_debug_trace();
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache failed to write cache file: ' . \wp_normalize_path( $cache_file ) . ' ; error: ' . ( $error['message'] ?? 'unknown' ) );
            return;
        }

        $exists = \file_exists( $cache_file ) ? 'yes' : 'no';
        $this->debug_trace['save_result'] = 'wrote_cache';
        $this->debug_trace['cache_file'] = \wp_normalize_path( $cache_file );
        $this->persist_debug_trace();
        \wp_fastlayer_debug_log( 'WP FastLayer: save_cache wrote cache file: ' . \wp_normalize_path( $cache_file ) . ' (' . \size_format( $written ) . ') ; exists: ' . $exists );
    }

    private function persist_debug_trace() {
        $trace = $this->debug_trace;
        $trace['updated_at'] = \time();

        if ( function_exists( 'update_transient' ) ) {
            \update_transient( 'wp_fastlayer_cache_debug_trace', $trace, 300 );
            return;
        }

        \wp_fastlayer_debug_log( 'WP FastLayer: update_transient() unavailable while persisting cache debug trace.' );
    }

    public function capture_output_buffer( $buffer ) {
        if ( is_string( $buffer ) && '' !== $buffer ) {
            $this->captured_buffer_content = $buffer;
        }

        return $buffer;
    }

    private function get_accept_cache_variant() {
        $accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( (string) \wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

        if ( false !== strpos( $accept, 'image/avif' ) ) {
            return 'accept-avif';
        }

        if ( false !== strpos( $accept, 'image/webp' ) ) {
            return 'accept-webp';
        }

        return 'accept-legacy';
    }

    private function send_accept_vary_header() {
        if ( headers_sent() ) {
            return;
        }

        header( 'Vary: Accept', false );
    }

    public function print_frontend_debug_script() {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }
        if ( \is_admin() || \wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        $script_data = array(
            'cache_enabled' => $this->is_cache_enabled(),
            'buffer_started' => $this->buffer_started,
            'cache_dir' => \wp_normalize_path( $this->cache_dir ),
            'cache_file' => $this->debug_trace['cache_file'] ?? '',
            'skip_reason' => $this->debug_trace['skip_reason'] ?? '',
            'save_result' => $this->debug_trace['save_result'] ?? '',
            'last_hook' => $this->debug_trace['last_hook'] ?? '',
        );

        \wp_fastlayer_debug_log( 'WP FastLayer: print_frontend_debug_script outputting browser console debug.' );

        echo '<script id="wp-fastlayer-debug">';
        echo 'console.group("WP FastLayer Debug");';
        foreach ( $script_data as $key => $value ) {
            $value_js = \esc_js( \wp_json_encode( $value ) );
            echo 'console.log("' . \esc_js( $key ) . ': ", ' . $value_js . ');';
        }
        echo 'console.groupEnd();';
        echo '</script>';
    }

    private function is_safe_path( $path ) {
        $directory = is_dir( $path ) ? $path : dirname( $path );
        $realpath = realpath( $directory );
        if ( false === $realpath ) {
            return false;
        }

        $cache_realpath = realpath( $this->cache_dir );
        if ( false === $cache_realpath ) {
            return false;
        }

        return 0 === strpos( $realpath, $cache_realpath );
    }

    public function clear_cache_on_post_update( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( $this->is_cacheable_post( $post ) ) {
            $this->clear_cache_by_post( $post_id );
            return;
        }

        $this->clear_all_cache();
    }

    public function clear_cache_on_post_delete( $post_id ) {
        $this->clear_cache_by_post( $post_id );
    }

    public function clear_cache_on_comment( $comment_id ) {
        $comment = get_comment( $comment_id );
        if ( $comment && ! empty( $comment->comment_post_ID ) ) {
            $this->clear_cache_by_post( $comment->comment_post_ID );
            return;
        }

        $this->clear_all_cache();
    }

    private function is_cacheable_post( $post ) {
        if ( ! $post instanceof \WP_Post ) {
            $post = get_post( $post );
        }

        if ( ! $post || 'publish' !== $post->post_status ) {
            return false;
        }

        return ! in_array( $post->post_type, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache' ), true );
    }

    private function clear_cache_by_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || ! $this->is_cacheable_post( $post ) ) {
            return;
        }

        if ( $permalink = get_permalink( $post ) ) {
            $this->clear_cache_by_url( $permalink );
        }

        $this->clear_cache_by_url( home_url( '/' ) );

        if ( function_exists( 'get_post_type_archive_link' ) ) {
            $archive_url = get_post_type_archive_link( $post->post_type );
            if ( $archive_url ) {
                $this->clear_cache_by_url( $archive_url );
            }
        }
    }

    public function clear_all_cache() {
        if ( ! is_dir( $this->cache_dir ) ) return;

        $files = $this->get_cache_files();
        foreach ( $files as $file ) {
            if ( $this->is_safe_to_delete( $file ) ) {
                @unlink( $file );
            }
        }
    }

    private function get_cache_files() {
        $files = array();
        if ( is_dir( $this->cache_dir ) ) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $this->cache_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() ) {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $files;
    }

    private function is_safe_to_delete( $file ) {
        $realpath = realpath( $file );
        if ( $realpath === false ) return false;

        $cache_realpath = realpath( $this->cache_dir );
        if ( $cache_realpath === false ) return false;

        return strpos( $realpath, $cache_realpath ) === 0;
    }

    public function clear_cache_by_url( $url ) {
        $cache_key = md5( $url );
        $cache_file = $this->cache_dir . $cache_key . '.html';
        if ( file_exists( $cache_file ) ) {
            @unlink( $cache_file );
        }
    }

    public function preload_cache() {
        if ( $this->is_running || ! $this->is_preload_enabled() ) {
            return;
        }

        $this->is_running = true;
        $urls = $this->get_urls_from_sitemap();

        if ( empty( $urls ) ) {
            $urls = $this->get_default_urls();
        }

        foreach ( $urls as $url ) {
            $this->preload_url( $url );
        }

        $this->is_running = false;
    }

    public function manual_preload() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-fastlayer' ) );
        }

        check_admin_referer( 'wp_fastlayer_preload_nonce' );

        $this->preload_cache();

        wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer&preload=success' ) );
        exit;
    }

    private function is_preload_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_preload'] ) && '1' === $options['enable_preload'];
    }

    private function get_urls_from_sitemap() {
        $urls = array();
        $sitemap_url = esc_url_raw( home_url( '/sitemap.xml' ) );

        $response = wp_remote_get( $sitemap_url, array(
            'timeout'  => 30,
            'sslverify' => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return $urls;
        }

        $body = wp_remote_retrieve_body( $response );

        if ( empty( $body ) ) {
            return $urls;
        }

        $xml = simplexml_load_string( $body );

        if ( $xml === false ) {
            return $urls;
        }

        $ns = $xml->getNamespaces( true );
        $xml->registerXPathNamespace( 'sm', $ns['url'] ?? '' );

        foreach ( $xml->url as $url_element ) {
            $url = esc_url_raw( (string) $url_element->loc );
            if ( $this->is_valid_url( $url ) ) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function get_default_urls() {
        $urls = array( home_url( '/' ) );

        $posts = get_posts( array(
            'post_type' => array( 'post', 'page' ),
            'post_status' => 'publish',
            'numberposts' => 50,
            'fields' => 'ids',
        ) );

        foreach ( $posts as $post_id ) {
            $urls[] = get_permalink( $post_id );
        }

        return $urls;
    }

    private function preload_url( $url ) {
        if ( ! $this->is_valid_url( $url ) ) return false;

        $response = wp_remote_get( esc_url_raw( $url ), array(
            'timeout' => 30,
            'sslverify' => false,
            'user-agent' => 'WP FastLayer Preloader',
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        return true;
    }

    private function is_valid_url( $url ) {
        return filter_var( $url, FILTER_VALIDATE_URL ) !== false && strpos( $url, home_url() ) === 0;
    }
}
