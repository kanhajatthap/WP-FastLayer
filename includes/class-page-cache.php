<?php
namespace WP_FastLayer;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Page_Cache {
    private static $instance = null;
    private $cache_dir;
    private $buffer_started = false;
    private $buffer_level = 0;
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

    /**
     * Hard cap on the number of pages a single preload job may warm. A sitemap
     * on a large site lists far more URLs than one background run should ever
     * chase, so the queue is truncated instead of growing without bound.
     */
    const PRELOAD_MAX_URLS = 200;

    /** Hard cap on how many sitemap documents (index plus children) are read. */
    const PRELOAD_MAX_SITEMAPS = 10;

    /**
     * Number of pages warmed per background batch.
     *
     * Warming is deliberately tiny. A warm request is a loopback request: the
     * batch that fires it already holds one PHP worker, and the site needs a
     * second worker to render the page being warmed. On a pool of two that is
     * every worker, so even a short batch leaves nothing for a visitor who
     * arrives while it runs. Warming a couple of URLs per batch and then
     * handing control back to WP-Cron keeps the exposure window as small as the
     * pool allows.
     */
    const PRELOAD_BATCH_SIZE = 2;

    /**
     * Wall clock budget for one batch, in seconds. It bounds a batch even when
     * every page in it responds just under the per request timeout, so a run
     * always returns to WP-Cron instead of holding a PHP worker.
     */
    const PRELOAD_BATCH_TIME_BUDGET = 20;

    /**
     * Total per request timeout in seconds. A page that cannot answer within
     * this is recorded as a failure and skipped, so one slow URL cannot hold up
     * the rest of the queue.
     *
     * This is also the time the batch's own PHP worker is held, because the
     * worker waits on the loopback response. A visitor arriving during that
     * wait can only be served once a worker frees up, so this value is the
     * upper bound on how long preload can delay a real request.
     */
    const PRELOAD_URL_TIMEOUT = 5;

    /**
     * Connection timeout in seconds, tracked separately from the total timeout.
     *
     * A warm request goes to this same server, so a connection that has not been
     * accepted almost always means the worker pool is already exhausted. Failing
     * fast on the connection and treating that as "the site is busy" lets the
     * batch yield immediately instead of spending the whole total timeout
     * waiting for a connection that will not be granted.
     */
    const PRELOAD_URL_CONNECT_TIMEOUT = 2;

    /**
     * A warm request slower than this, in seconds, is treated as a sign that the
     * worker pool is contended rather than that the page is merely heavy.
     *
     * Preload measures the loopbacks it makes anyway, so this costs nothing to
     * evaluate. When it trips, the batch stops warming and hands its worker
     * straight back instead of continuing to compete with the visitors that the
     * slow response was caused by. Measured from an idle site a page render is
     * well under a second, so this only fires under real pressure.
     */
    const PRELOAD_URL_SLOW_SECONDS = 2;

    /**
     * Extra seconds to wait before the next batch runs after a contended warm.
     *
     * A contended pool needs time to drain. Waiting before coming back costs the
     * job very little, because a job is spread over many batches anyway, and it
     * stops preload from immediately re-entering the pool it just found busy.
     */
    const PRELOAD_CONTENDED_DELAY = 60;

    /**
     * Wall clock budget for the whole job, in seconds, across every batch.
     *
     * A batch budget bounds one batch, but a long queue chained across many
     * batches would otherwise keep preloading all day. Once the job has been
     * running this long it stops on purpose, leaving the remaining pages queued,
     * so preload is never an indefinite background load competing with the site.
     */
    const PRELOAD_JOB_TIME_BUDGET = 300;

    /**
     * Pause between two warm requests inside one batch, in microseconds.
     *
     * It costs almost nothing and it gives a request that is already queued
     * behind the previous warm a worker to run in, instead of making it wait for
     * the whole batch.
     */
    const PRELOAD_URL_PAUSE_MICROSECONDS = 250000;

    /**
     * Wall clock budget for discovering pages from the sitemaps, in seconds.
     */
    const PRELOAD_SITEMAP_TIME_BUDGET = 20;

    /** Job lock older than this is treated as abandoned and replaced. */
    const PRELOAD_LOCK_TTL = 900;

    /** A batch in progress older than this is treated as a dead worker. */
    const PRELOAD_BATCH_LOCK_TTL = 60;

    /** Maximum number of per URL errors kept for the status report. */
    const PRELOAD_MAX_RECORDED_ERRORS = 20;

    /** Cron hook that warms one batch. Public so deactivation can clear it. */
    const PRELOAD_BATCH_HOOK = 'wp_fastlayer_preload_batch';

    /** Option names holding the job state and the two locks. */
    const PRELOAD_STATE_OPTION      = 'wp_fastlayer_preload_state';
    const PRELOAD_LOCK_OPTION       = 'wp_fastlayer_preload_lock';
    const PRELOAD_BATCH_LOCK_OPTION = 'wp_fastlayer_preload_batch_lock';

    private $preload_state_option = self::PRELOAD_STATE_OPTION;
    private $preload_lock_option  = self::PRELOAD_LOCK_OPTION;
    private $preload_batch_lock_option = self::PRELOAD_BATCH_LOCK_OPTION;
    private $preload_batch_hook  = self::PRELOAD_BATCH_HOOK;

    /**
     * The batch lock value claimed by this process, so the batch running now can
     * tell its own lock apart from one held by a different batch.
     *
     * @var int
     */
    private $preload_batch_lock_owned_at = 0;

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
        \add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_debug_script' ), 9999 );
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
        \add_action( $this->preload_batch_hook, array( $this, 'process_preload_batch' ) );

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
        if ( ! $this->is_request_host_trusted() ) {
            $skip_reasons[] = 'untrusted_host';
            \wp_fastlayer_debug_log( 'WP FastLayer: maybe_serve_cache skip condition untrusted_host.' );
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
            $this->buffer_level = \ob_get_level();
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
        if ( ! empty( $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] ) ) {
            return true;
        }

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

    /**
     * Validates the client-supplied HTTP_HOST against the site host.
     *
     * HTTP_HOST comes from the client and must never be trusted in a cache key
     * on its own. The advanced-cache.php drop-in applies the same rule using the
     * hosts stored in the static config, so both cache paths agree.
     */
    private function is_request_host_trusted() {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? \strtolower( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
        $colon = \strpos( $host, ':' );
        if ( false !== $colon ) {
            $host = \substr( $host, 0, $colon );
        }

        if ( '' === $host ) {
            return false;
        }

        $expected = array();
        foreach ( array( \home_url( '/' ), \site_url( '/' ) ) as $candidate ) {
            $expected_host = \wp_parse_url( $candidate, PHP_URL_HOST );
            if ( \is_string( $expected_host ) && '' !== $expected_host ) {
                $expected[] = \strtolower( $expected_host );
            }
        }
        $expected = \array_values( \array_unique( $expected ) );

        // home_url() is always available here, so an empty list means the value
        // could not be determined. Allow the request rather than silently
        // disabling the page cache for the whole site.
        if ( empty( $expected ) ) {
            return true;
        }

        return \in_array( $host, $expected, true );
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

    private function generate_cache_key( $url, $variant = null ) {
        if ( null === $variant ) {
            $variant = $this->get_accept_cache_variant();
        }

        return md5( $url . '|' . $variant );
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
        if ( ! $this->is_request_host_trusted() ) {
            $skip_reasons[] = 'untrusted_host';
            \wp_fastlayer_debug_log( 'WP FastLayer: save_cache skip condition untrusted_host.' );
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

        // The cache must store the same HTML that is sent to the visitor. The
        // authoritative copy is the one our own output-buffer callback
        // (capture_output_buffer) stored: our buffer is the outermost of the
        // FastLayer buffers, so its callback runs only after every inner
        // buffer -- including the optimizer's minify_html -- has flushed its
        // transformed output into ours. ob_get_contents() must never be the
        // primary source: it returns the innermost *open* buffer, which is
        // pre-transformation at best and another plugin's partial output at
        // worst. It is used only when our own buffer is still the innermost
        // one, i.e. provably ours and already fully transformed.
        $content = '';
        if ( ! empty( $this->captured_buffer_content ) ) {
            $content = $this->captured_buffer_content;
        } elseif ( $this->buffer_started && \ob_get_level() === $this->buffer_level ) {
            $content = \ob_get_contents();
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

    private function get_accept_cache_variants() {
        return array( 'accept-avif', 'accept-webp', 'accept-legacy' );
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

    public function enqueue_frontend_debug_script() {
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

        \wp_fastlayer_debug_log( 'WP FastLayer: enqueue_frontend_debug_script registering browser console debug.' );

        // Every trace value above is populated by maybe_serve_cache() on
        // template_redirect, which runs before wp_enqueue_scripts, so the payload
        // is identical to the previous wp_footer output.
        $lines = 'console.group("WP FastLayer Debug");';
        foreach ( $script_data as $key => $value ) {
            $lines .= 'console.log(' . \wp_json_encode( $key . ': ' ) . ', ' . \wp_json_encode( $value ) . ');';
        }
        $lines .= 'console.groupEnd();';

        \wp_register_script( 'wp-fastlayer-cache-debug', false, array(), WP_FASTLAYER_VERSION, array( 'in_footer' => true ) );
        \wp_enqueue_script( 'wp-fastlayer-cache-debug' );
        \wp_add_inline_script( 'wp-fastlayer-cache-debug', $lines );
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
                if ( ! $file->isFile() ) {
                    continue;
                }

                $basename = $file->getBasename();
                if ( '.htaccess' === $basename || 'index.php' === $basename ) {
                    continue;
                }

                $files[] = $file->getPathname();
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
        foreach ( $this->get_accept_cache_variants() as $variant ) {
            $cache_key  = $this->generate_cache_key( $url, $variant );
            $cache_file = $this->cache_dir . $cache_key . '.html';
            if ( file_exists( $cache_file ) ) {
                @unlink( $cache_file );
            }
        }
    }

    /**
     * Starts a preload job.
     *
     * This used to walk the whole URL list with blocking HTTP requests inside
     * the request that triggered it, which held a PHP worker for as long as the
     * slowest page took and could starve the site of workers entirely. It now
     * only records a job, hands it to WP-Cron and returns straight away, so the
     * triggering request finishes immediately no matter how large the queue is.
     *
     * @param string $source Either 'manual' or 'scheduled', for reporting only.
     * @return string One of: started, disabled, busy, error.
     */
    public function preload_cache( $source = 'manual' ) {
        if ( ! $this->is_preload_enabled() ) {
            return 'disabled';
        }

        $state = $this->get_preload_state();
        if ( $this->is_preload_job_active( $state ) ) {
            return 'busy';
        }

        if ( ! $this->acquire_preload_lock( $source ) ) {
            return 'busy';
        }

        // The queue is deliberately not built here. Discovering it means
        // reading the sitemap documents over HTTP, and doing that inside the
        // request that started the job is exactly the stall this rewrite
        // removes. The queue is built by the first background batch instead, so
        // this request performs no network work and returns immediately.
        $this->save_preload_state( array(
            'status'      => 'queued',
            'queue_built' => false,
            'total'       => 0,
            'processed'   => 0,
            'succeeded'   => 0,
            'failed'      => 0,
            'queue'       => array(),
            'errors'      => array(),
            'source'      => $source,
            'message'     => __( 'The preload job is queued and will run in the background.', 'fastlayer' ),
            'started_at'  => time(),
            'updated_at'  => time(),
            'finished_at' => 0,
        ) );

        $this->schedule_preload_batch();

        // A job started from WP-Cron is already inside a background worker, so
        // the first batch runs here instead of waiting for the next spawn. A
        // job started from an admin request does not, so it stays queued: firing
        // a loopback request immediately would compete for the same limited
        // pool of PHP workers that the current request is holding.
        if ( \wp_doing_cron() ) {
            $this->process_preload_batch();
        }

        return 'started';
    }

    /**
     * Warms one bounded batch of the queued pages.
     *
     * The batch stops on whichever limit is reached first, the page count or the
     * time budget, so a batch always returns control to WP-Cron promptly and no
     * single page can monopolise a worker.
     */
    public function process_preload_batch() {
        $state = $this->get_preload_state();

        if ( empty( $state['status'] ) || ! $this->is_preload_job_active( $state ) ) {
            return;
        }

        if ( ! $this->acquire_preload_batch_lock() ) {
            return;
        }

        // Claimed by an earlier batch, so the timeout is counted from there.
        $this->refresh_preload_lock();

        // The whole job gets a wall clock budget of its own, on top of the per
        // batch one. A batch budget bounds a single batch, but a long queue
        // chained across many batches would otherwise keep warming all day.
        //
        // This is checked before any warming, not after, so a job that has
        // already run its course stops without taking one more page off the
        // queue. What is left stays recorded and the next run picks it up.
        $job_started = isset( $state['started_at'] ) ? (int) $state['started_at'] : 0;

        if ( $job_started > 0 && ( time() - $job_started ) >= self::PRELOAD_JOB_TIME_BUDGET ) {
            $this->stop_preload_job( $state );
            return;
        }

        $queue  = isset( $state['queue'] ) && is_array( $state['queue'] ) ? $state['queue'] : array();
        $errors = isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array();

        // The queue is built on the first batch, in the background, because
        // reading the sitemap documents is remote work. Discovery gets its own
        // budget instead of sharing the warming one: it is usually the slowest
        // part of a batch, and charging it to the same deadline would let it
        // consume the whole budget and leave the batch with nothing warmed.
        if ( empty( $state['queue_built'] ) ) {
            $queue = $this->build_preload_queue();

            if ( empty( $queue ) ) {
                $this->release_preload_batch_lock();
                $this->save_preload_state( array_merge( $state, array(
                    'status'      => 'failed',
                    'queue_built' => true,
                    'total'       => 0,
                    'processed'   => 0,
                    'succeeded'   => 0,
                    'failed'      => 0,
                    'queue'       => array(),
                    'message'     => __( 'No warmable pages were found, so the preload job stopped without doing anything.', 'fastlayer' ),
                    'updated_at'  => time(),
                    'finished_at' => time(),
                ) ) );
                $this->release_preload_lock();
                return;
            }

            $state['total']       = count( $queue );
            $state['queue_built'] = true;
        }

        $deadline = microtime( true ) + self::PRELOAD_BATCH_TIME_BUDGET;

        // The counters carried in from earlier batches are the authoritative
        // record of what has actually finished. The batch adds to them only
        // after a URL has returned, and the reported progress is derived from
        // them at the end, so "processed" can never claim work that is still in
        // flight and can never drift away from succeeded plus failed.
        $succeeded_total = isset( $state['succeeded'] ) ? (int) $state['succeeded'] : 0;
        $failed_total    = isset( $state['failed'] ) ? (int) $state['failed'] : 0;

        // Counts every attempt, not just the successful ones, so the batch bound
        // cannot be side stepped by a run of failing URLs.
        $attempted_this_batch = 0;
        $yield_reason         = '';

        while ( ! empty( $queue ) && $attempted_this_batch < self::PRELOAD_BATCH_SIZE ) {
            $remaining = $deadline - microtime( true );

            if ( $this->is_memory_budget_exceeded() ) {
                $yield_reason = 'memory';
                break;
            }

            // A request is only started when there is still time left to wait for
            // it. Abandoning a page halfway through does not cancel the render
            // already running on the server, so a request that is cut off would
            // keep occupying a worker after this batch returned. On a small pool
            // those orphaned renders are what makes the rest of the site look
            // slow, so the page is left queued for a later batch.
            if ( $remaining < self::PRELOAD_URL_TIMEOUT ) {
                $yield_reason = 'budget';
                break;
            }

            // Another preload batch is still warming. This one adds nothing, so
            // it returns its worker immediately rather than competing.
            if ( $this->is_another_preload_batch_live() ) {
                $yield_reason = 'contended';
                break;
            }

            $url    = array_shift( $queue );
            $started = microtime( true );
            $result = $this->preload_url( $url );
            $elapsed = microtime( true ) - $started;

            $attempted_this_batch++;

            // The URL has finished, so only now is it counted.
            if ( true === $result ) {
                $succeeded_total++;
            } else {
                $failed_total++;
                $errors = $this->record_preload_error( $errors, $url, $result );
            }

            $state['processed'] = $succeeded_total + $failed_total;

            // A slow warm means the pool was already contended. The batch gives
            // its worker back now and comes back later, rather than continuing
            // to compete with the visitors the slow response was caused by.
            //
            // This ends the batch only. It never ends the job: a page that is
            // slow or unresponsive must not be able to strand the pages queued
            // behind it, so the remaining queue is carried into the next batch.
            if ( $elapsed >= self::PRELOAD_URL_SLOW_SECONDS ) {
                $yield_reason = 'contended';
                break;
            }

            // Hand a worker back between warms so a request already queued
            // behind the previous one gets to run.
            if ( ! empty( $queue ) && $attempted_this_batch < self::PRELOAD_BATCH_SIZE ) {
                \usleep( self::PRELOAD_URL_PAUSE_MICROSECONDS );
            }
        }

        // Derived, never accumulated separately, so processed always equals
        // completed plus failed and can never exceed the discovered total.
        $processed  = $succeeded_total + $failed_total;
        $total      = isset( $state['total'] ) ? (int) $state['total'] : 0;
        $total_done = $processed;

        if ( $total > 0 && $total_done > $total ) {
            $total_done = $total;
        }

        $state = array_merge( $state, array(
            'status'     => 'running',
            'queue'      => array_values( $queue ),
            'errors'     => $errors,
            'processed'  => $total_done,
            'succeeded'  => $succeeded_total,
            'failed'     => $failed_total,
            'updated_at' => time(),
        ) );

        $this->release_preload_batch_lock();

        if ( empty( $queue ) ) {
            $state = $this->finalize_preload_state( $state );
            $this->save_preload_state( $state );
            $this->release_preload_lock();
            return;
        }

        $state['message'] = ( 'contended' === $yield_reason )
            ? sprintf(
                /* translators: 1: processed pages, 2: total pages. */
                __( 'Warmed %1$d of %2$d pages so far, then paused because the site is busy serving visitors. Continuing shortly.', 'fastlayer' ),
                $total_done,
                $total
            )
            : sprintf(
                /* translators: 1: processed pages, 2: total pages. */
                __( 'Warmed %1$d of %2$d pages so far. Continuing in the background.', 'fastlayer' ),
                $total_done,
                $total
            );

        $this->save_preload_state( $state );

        // A long job is made of several short batches, each scheduled on its own.
        // After a contended batch the next one waits, so preload does not walk
        // straight back into a pool that has just said it has no spare capacity.
        $this->schedule_preload_batch( 'contended' === $yield_reason ? self::PRELOAD_CONTENDED_DELAY : 10 );
    }

    public function manual_preload() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'fastlayer' ) );
        }

        check_admin_referer( 'wp_fastlayer_preload_nonce' );

        $status = $this->preload_cache( 'manual' );

        wp_safe_redirect( admin_url( 'admin.php?page=wp-fastlayer&preload=' . rawurlencode( $status ) ) );
        exit;
    }

    /**
     * Reads the current job state for status reporting. Safe to call from any
     * request, including one that only wants to display progress.
     */
    public function get_preload_state() {
        $state = \get_option( $this->preload_state_option, array() );
        return is_array( $state ) ? $state : array();
    }

    private function is_preload_enabled() {
        $options = \get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_preload'] ) && '1' === $options['enable_preload'];
    }

    private function save_preload_state( $state ) {
        \update_option( $this->preload_state_option, $state, false );
    }

    /**
     * A job counts as active while it still holds a live job lock. The lock is
     * what prevents two jobs from running at once, so it, and not the recorded
     * status alone, is the authority on whether work is in flight.
     */
    private function is_preload_job_active( $state ) {
        if ( empty( $state['status'] ) ) {
            return false;
        }

        if ( ! in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
            return false;
        }

        return $this->is_preload_lock_live();
    }

    private function is_preload_lock_live() {
        $locked_at = (int) \get_option( $this->preload_lock_option, 0 );
        if ( $locked_at <= 0 ) {
            return false;
        }

        return ( time() - $locked_at ) < self::PRELOAD_LOCK_TTL;
    }

    /**
     * Claims the single job slot. add_option() is used because it only succeeds
     * when the row does not exist yet, which makes it an atomic claim even when
     * two requests race each other.
     */
    private function acquire_preload_lock( $source ) {
        $locked_at = (int) \get_option( $this->preload_lock_option, 0 );

        if ( $locked_at > 0 ) {
            if ( ( time() - $locked_at ) < self::PRELOAD_LOCK_TTL ) {
                return false;
            }

            // The previous job never finished, most likely because the process
            // handling it was killed. Recover rather than staying blocked.
            \delete_option( $this->preload_lock_option );
        }

        if ( ! \add_option( $this->preload_lock_option, time(), '', false ) ) {
            return false;
        }

        $state = $this->get_preload_state();
        if ( ! empty( $state['status'] ) && in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
            $state['status']      = 'failed';
            $state['message']     = __( 'A previous preload job stopped before it finished and was replaced by a new one.', 'fastlayer' );
            $state['finished_at'] = time();
            $state['updated_at']  = time();
            $this->save_preload_state( $state );
        }

        return true;
    }

    /**
     * Extends the job lock while the job is still making progress.
     *
     * The lock has to expire on its own so a job whose process was killed does
     * not block the site forever. But a healthy job made of many batches can
     * outlive that TTL, and letting it lapse would let a second job start
     * alongside the first. Each batch therefore pushes the expiry forward, so
     * the lock only ever expires after a batch stops running.
     */
    private function refresh_preload_lock() {
        \update_option( $this->preload_lock_option, time(), false );
    }

    private function release_preload_lock() {
        \delete_option( $this->preload_lock_option );
    }

    /**
     * Claims the single batch slot. This is separate from the job lock so a
     * second batch cannot start while another one is still warming pages.
     */
    private function acquire_preload_batch_lock() {
        $locked_at = (int) \get_option( $this->preload_batch_lock_option, 0 );

        if ( $locked_at > 0 ) {
            if ( ( time() - $locked_at ) < self::PRELOAD_BATCH_LOCK_TTL ) {
                return false;
            }

            \delete_option( $this->preload_batch_lock_option );
        }

        if ( ! \add_option( $this->preload_batch_lock_option, time(), '', false ) ) {
            return false;
        }

        $this->preload_batch_lock_owned_at = (int) \get_option( $this->preload_batch_lock_option, 0 );

        return true;
    }

    private function release_preload_batch_lock() {
        \delete_option( $this->preload_batch_lock_option );
        $this->preload_batch_lock_owned_at = 0;
    }

    /**
     * Queues the next batch. The existing schedule check keeps a single pending
     * event on the cron array, so repeated triggers cannot pile up duplicates.
     *
     * @param int $delay Seconds to wait before the next batch runs.
     */
    private function schedule_preload_batch( $delay = 10 ) {
        if ( \wp_next_scheduled( $this->preload_batch_hook ) ) {
            return;
        }

        \wp_schedule_single_event( time() + max( 1, (int) $delay ), $this->preload_batch_hook );
    }

    /**
     * Builds the bounded list of pages to warm.
     *
     * Core, Yoast and AIOSEO all publish a sitemap index that points at one or
     * more child sitemaps holding the actual <url> entries. Reading only the
     * index therefore finds no pages at all, which is why the previous
     * implementation always fell back to a partial list of recent posts.
     */
    private function build_preload_queue() {
        $urls = $this->get_urls_from_sitemap();
        $urls = $this->filter_preloadable_urls( $urls );

        if ( empty( $urls ) ) {
            $urls = $this->filter_preloadable_urls( $this->get_default_urls() );
        }

        if ( count( $urls ) > self::PRELOAD_MAX_URLS ) {
            $urls = array_slice( $urls, 0, self::PRELOAD_MAX_URLS );
        }

        return array_values( array_unique( $urls ) );
    }

    private function get_urls_from_sitemap() {
        $urls       = array();
        $visited    = array();
        $sitemaps   = 0;
        $candidates = array(
            \esc_url_raw( \home_url( '/sitemap.xml' ) ),
            \esc_url_raw( \home_url( '/wp-sitemap.xml' ) ),
        );

        // Reading the sitemap documents is remote work, so the crawl is bounded
        // by the same kind of budget as a warming batch. A site that serves a
        // slow or hanging sitemap must not be able to hold a worker for the
        // combined timeout of every child document.
        $deadline = microtime( true ) + self::PRELOAD_SITEMAP_TIME_BUDGET;

        while ( ! empty( $candidates ) && $sitemaps < self::PRELOAD_MAX_SITEMAPS && count( $urls ) < self::PRELOAD_MAX_URLS ) {
            if ( microtime( true ) >= $deadline ) {
                break;
            }

            $sitemap_url = \esc_url_raw( array_shift( $candidates ) );

            if ( '' === $sitemap_url || isset( $visited[ $sitemap_url ] ) ) {
                continue;
            }

            if ( ! $this->is_trusted_site_url( $sitemap_url ) ) {
                continue;
            }

            $visited[ $sitemap_url ] = true;
            $sitemaps++;

            $body = $this->fetch_sitemap_body( $sitemap_url );
            if ( '' === $body ) {
                continue;
            }

            $parsed = $this->parse_sitemap_document( $body );
            if ( empty( $parsed ) ) {
                continue;
            }

            foreach ( $parsed['pages'] as $page_url ) {
                $urls[] = $page_url;
                if ( count( $urls ) >= self::PRELOAD_MAX_URLS ) {
                    break 2;
                }
            }

            foreach ( $parsed['children'] as $child_url ) {
                if ( ! isset( $visited[ $child_url ] ) ) {
                    $candidates[] = $child_url;
                }
            }
        }

        return $urls;
    }

    private function fetch_sitemap_body( $sitemap_url ) {
        $response = \wp_remote_get( $sitemap_url, array(
            'timeout'     => 10,
            'sslverify'   => true,
            'redirection' => 3,
            'user-agent'  => 'WP FastLayer Preloader',
            // Marks this as a preload discovery request so the cache drop-in
            // stores the XML response nowhere. Reading a sitemap must not leave
            // a cacheable file behind.
            'headers'     => array(
                'Accept'            => 'application/xml, text/xml;q=0.9, */*;q=0.1',
                'X-FastLayer-Preload' => 'discovery',
            ),
        ) );

        if ( \is_wp_error( $response ) ) {
            return '';
        }

        if ( 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
            return '';
        }

        return (string) \wp_remote_retrieve_body( $response );
    }

    /**
     * Reads a sitemap document and returns its child sitemaps and its pages.
     *
     * Entries are read through children( $ns ) rather than through $xml->url so
     * that the default sitemap namespace does not hide them, and the traversal
     * only descends while the document is an index, so a urlset is never walked
     * as if it contained more sitemaps.
     */
    private function parse_sitemap_document( $body ) {
        $previous = libxml_use_internal_errors( true );
        // LIBXML_NONET stops external entity resolution while reading a file
        // that ultimately comes from a remote, plugin generated document.
        $xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( false === $xml ) {
            return array();
        }

        $namespaces = $xml->getNamespaces( true );
        $ns         = '';
        if ( isset( $namespaces[''] ) && is_string( $namespaces[''] ) ) {
            $ns = $namespaces[''];
        } elseif ( isset( $namespaces['sm'] ) && is_string( $namespaces['sm'] ) ) {
            $ns = $namespaces['sm'];
        }

        $children = '' === $ns ? $xml : $xml->children( $ns );

        $pages   = array();
        $sitemap = array();

        foreach ( $children as $entry ) {
            $entry_children = '' === $ns ? $entry : $entry->children( $ns );

            if ( ! isset( $entry_children->loc ) ) {
                continue;
            }

            $loc = \esc_url_raw( trim( (string) $entry_children->loc ) );
            if ( '' === $loc ) {
                continue;
            }

            if ( 'sitemap' === $entry->getName() ) {
                $sitemap[] = $loc;
                continue;
            }

            $pages[] = $loc;
        }

        return array(
            'pages'    => $pages,
            'children' => $sitemap,
        );
    }

    private function get_default_urls() {
        $urls = array( \home_url( '/' ) );

        $posts = \get_posts( array(
            'post_type'   => array( 'post', 'page' ),
            'post_status' => 'publish',
            'numberposts' => 50,
            'fields'      => 'ids',
        ) );

        foreach ( $posts as $post_id ) {
            $permalink = \get_permalink( $post_id );
            if ( $permalink ) {
                $urls[] = $permalink;
            }
        }

        return $urls;
    }

    /**
     * Drops anything that is not a warmable page: untrusted hosts, non HTTP
     * schemes, fragments, excluded paths, and URLs that point at a file such as
     * an image, stylesheet or sitemap rather than at a page.
     */
    private function filter_preloadable_urls( $urls ) {
        $filtered = array();

        foreach ( (array) $urls as $url ) {
            $url = \esc_url_raw( trim( (string) $url ) );

            if ( '' === $url ) {
                continue;
            }

            $url = strtok( $url, '#' );
            if ( ! is_string( $url ) || '' === $url ) {
                continue;
            }

            if ( ! $this->is_preloadable_url( $url ) ) {
                continue;
            }

            $filtered[] = $url;
        }

        return array_values( array_unique( $filtered ) );
    }

    private function is_preloadable_url( $url ) {
        if ( false === \filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return false;
        }

        if ( ! $this->is_trusted_site_url( $url ) ) {
            return false;
        }

        $path = \wp_parse_url( $url, PHP_URL_PATH );
        if ( ! is_string( $path ) ) {
            return false;
        }

        foreach ( $this->get_excluded_preload_paths() as $excluded ) {
            if ( false !== stripos( $path, $excluded ) ) {
                return false;
            }
        }

        $extension = strtolower( (string) \pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( '' !== $extension && in_array( $extension, $this->get_non_preloadable_extensions(), true ) ) {
            return false;
        }

        return true;
    }

    /**
     * Confirms a URL points at this site over HTTP(S). The old check only asked
     * whether the URL started with the home URL text, which also accepted hosts
     * such as the site name followed by an attacker controlled domain.
     */
    private function is_trusted_site_url( $url ) {
        $host = \wp_parse_url( $url, PHP_URL_HOST );
        if ( ! is_string( $host ) || '' === $host ) {
            return false;
        }

        $scheme = \strtolower( (string) \wp_parse_url( $url, PHP_URL_SCHEME ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return false;
        }

        $trusted = array();
        foreach ( array( \home_url( '/' ), \site_url( '/' ) ) as $candidate ) {
            $candidate_host = \wp_parse_url( $candidate, PHP_URL_HOST );
            if ( is_string( $candidate_host ) && '' !== $candidate_host ) {
                $trusted[] = \strtolower( $candidate_host );
            }
        }

        if ( empty( $trusted ) ) {
            return false;
        }

        return in_array( \strtolower( $host ), array_values( array_unique( $trusted ) ), true );
    }

    private function get_excluded_preload_paths() {
        return array(
            '/wp-admin/',
            '/wp-login.php',
            '/wp-register.php',
            '/wp-json/',
            '/wp-cron.php',
            '/xmlrpc.php',
            '/cart/',
            '/checkout/',
            '/my-account/',
            '/feed/',
        );
    }

    private function get_non_preloadable_extensions() {
        return array(
            'xml', 'xsl', 'rss', 'atom',
            'css', 'js', 'mjs', 'map', 'json',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tiff',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt',
            'zip', 'gz', 'tar', 'rar', '7z',
            'mp3', 'mp4', 'm4a', 'm4v', 'avi', 'mov', 'wav', 'webm', 'ogg',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
        );
    }

    /**
     * Requests one page and reports whether it was actually warmed.
     *
     * The cache drop-in stores whatever a URL returns, so a page that answers
     * with something other than HTML, such as a sitemap, would leave a file that
     * later requests could be served. Such a response is rejected here and the
     * file it produced is removed.
     *
     * @return true|string True on success, otherwise a failure reason.
     */
    private function preload_url( $url ) {
        $url = \esc_url_raw( $url );

        if ( ! $this->is_preloadable_url( $url ) ) {
            return 'not_warmable';
        }

        $response = \wp_remote_get( $url, array(
            'timeout'         => self::PRELOAD_URL_TIMEOUT,
            'connect_timeout' => self::PRELOAD_URL_CONNECT_TIMEOUT,
            'sslverify'       => true,
            'redirection'     => 3,
            'user-agent'      => 'WP FastLayer Preloader',
            // Warming must be counted by the page cache exactly like a visitor
            // request would be, so this request is deliberately NOT marked as
            // preload discovery. The marker is reserved for the sitemap reads,
            // which must leave nothing behind in the cache.
            'headers'         => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8' ),
        ) );

        if ( \is_wp_error( $response ) ) {
            return $response->get_error_message();
        }

        $code = (int) \wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code > 299 ) {
            return 'http_' . $code;
        }

        if ( ! $this->is_html_response( $response ) ) {
            $this->clear_cache_by_url( $url );
            return 'not_html';
        }

        return true;
    }

    /**
     * Decides whether a warmed response is really an HTML page.
     *
     * The URL list is built from the sitemap, so a queued entry is normally a
     * page, but a redirect, a plugin or a theme can still answer with XML, JSON
     * or a binary asset. Those must never be reported as warmed, because a
     * successful-looking result would hide a wrong cache file being created.
     *
     * The header is authoritative when the server sends one. A missing
     * Content-Type is not treated as success on its own: some servers omit it
     * entirely, so the body is sniffed instead and the URL only counts as warmed
     * when it really does look like a document.
     */
    private function is_html_response( $response ) {
        $content_type = (string) \wp_remote_retrieve_header( $response, 'content-type' );

        if ( '' !== $content_type ) {
            return false !== stripos( $content_type, 'text/html' ) || false !== stripos( $content_type, 'application/xhtml+xml' );
        }

        $body = (string) \wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return false;
        }

        $head = strtolower( substr( ltrim( $body ), 0, 512 ) );

        return 0 === strpos( $head, '<!doctype html' ) || false !== strpos( $head, '<html' );
    }

    private function record_preload_error( $errors, $url, $reason ) {
        $errors[] = array(
            'url'    => (string) $url,
            'reason' => is_string( $reason ) && '' !== $reason ? $reason : 'unknown',
        );

        if ( count( $errors ) > self::PRELOAD_MAX_RECORDED_ERRORS ) {
            $errors = array_slice( $errors, -self::PRELOAD_MAX_RECORDED_ERRORS );
        }

        return $errors;
    }

    /**
     * Ends a job that has run as long as it is allowed to.
     *
     * The job stops rather than pausing, and the pages that are left stay in the
     * queue so a later run can finish them. Nothing is rescheduled and both
     * locks are released, so a stopped job never blocks a new one and never
     * leaves a batch event behind to fire into a queue nobody is waiting for.
     *
     * @param array $state Current job state.
     */
    private function stop_preload_job( $state ) {
        $processed = isset( $state['processed'] ) ? (int) $state['processed'] : 0;
        $total     = isset( $state['total'] ) ? (int) $state['total'] : 0;

        $state['status']      = 'stopped';
        $state['finished_at'] = time();
        $state['updated_at']  = time();
        $state['message']     = sprintf(
            /* translators: 1: processed pages, 2: total pages, 3: minutes the job ran. */
            __( 'Preload stopped after running for %3$d minutes, having warmed %1$d of %2$d pages. The pages that are left stay queued for the next preload run.', 'fastlayer' ),
            $processed,
            $total,
            (int) round( self::PRELOAD_JOB_TIME_BUDGET / 60 )
        );

        $this->save_preload_state( $state );
        $this->release_preload_batch_lock();
        $this->release_preload_lock();

        // A stopped job must not leave a pending batch behind.
        \wp_clear_scheduled_hook( $this->preload_batch_hook );
    }

    private function finalize_preload_state( $state ) {
        $total     = isset( $state['total'] ) ? (int) $state['total'] : 0;
        $succeeded = isset( $state['succeeded'] ) ? (int) $state['succeeded'] : 0;
        $failed    = isset( $state['failed'] ) ? (int) $state['failed'] : 0;

        // Counters are clamped before the terminal status is decided. A job that
        // was interrupted, restored from an older plugin version, or overlapped
        // by a second runner can carry counts that are negative or larger than
        // the discovered total. Reporting those as-is would claim more progress
        // than the job ever had, so the numbers are first pulled back into the
        // range the job could actually have produced, keeping processed equal to
        // succeeded plus failed.
        $succeeded = max( 0, $succeeded );
        $failed    = max( 0, $failed );

        if ( $total <= 0 ) {
            // Nothing was discovered, so nothing can have been warmed.
            $succeeded = 0;
            $failed    = 0;
        } elseif ( ( $succeeded + $failed ) > $total ) {
            $overflow  = ( $succeeded + $failed ) - $total;
            $succeeded = max( 0, $succeeded - $overflow );
            $failed    = max( 0, $failed - $overflow );
        }

        $processed = $succeeded + $failed;

        if ( $failed > 0 && $succeeded > 0 ) {
            $status  = 'completed_with_errors';
            $message = sprintf(
                /* translators: 1: number of warmed pages, 2: number of failed pages. */
                __( 'Preload finished. %1$d pages were warmed and %2$d could not be warmed.', 'fastlayer' ),
                $succeeded,
                $failed
            );
        } elseif ( $failed > 0 ) {
            $status  = 'failed';
            $message = __( 'Preload finished without warming any pages. See the errors below.', 'fastlayer' );
        } else {
            $status  = 'completed';
            $message = sprintf(
                /* translators: %d: number of warmed pages. */
                __( 'Preload finished. %d pages were warmed.', 'fastlayer' ),
                $succeeded
            );
        }

        $state['status']      = $status;
        $state['message']     = $message;
        $state['queue']       = array();
        $state['processed']   = $processed;
        $state['succeeded']   = $succeeded;
        $state['failed']      = $failed;
        $state['updated_at']  = time();
        $state['finished_at'] = time();

        return $state;
    }

/**
     * Stops a batch early when it has taken a large share of the
     * request is allowed, so a heavy page cannot push the worker over its limit.
     */
    private function is_memory_budget_exceeded() {
        $limit = \wp_convert_hr_to_bytes( (string) \ini_get( 'memory_limit' ) );

        if ( $limit <= 0 ) {
            return false;
        }

        return \memory_get_usage( true ) > (int) ( $limit * 0.8 );
    }

    /**
     * Reports a batch lock held by a batch other than this one.
     *
     * The lock this batch is running under is remembered when it is claimed. If
     * the option still holds that same value the lock is ours, so it says nothing
     * about contention; a different value means another batch has taken over, and
     * this batch should stay out of the way rather than add a second warm.
     */
    private function is_another_preload_batch_live() {
        $locked_at = (int) \get_option( $this->preload_batch_lock_option, 0 );
        if ( $locked_at <= 0 || $locked_at === $this->preload_batch_lock_owned_at ) {
            return false;
        }

        return ( time() - $locked_at ) < self::PRELOAD_BATCH_LOCK_TTL;
    }
}
