<?php
namespace WP_FastLayer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin_Bar {
    private static $instance = null;
    private $action_key = 'wp_fastlayer_admin_bar_action';
    private $nonce_action_prefix = 'wp_fastlayer_admin_bar_nonce';
    private $allowed_actions = array(
        'clear_all_cache',
        'clear_css_js_cache',
        'preload_cache',
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_bar_menu', array( $this, 'register_admin_bar_menu' ), 100 );
        add_action( 'admin_bar_menu', array( $this, 'add_settings_admin_bar_node' ), 999 );
        add_action( 'admin_init', array( $this, 'handle_action_request' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_toolbar_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_toolbar_assets' ) );
        add_action( 'admin_notices', array( $this, 'display_success_notice' ) );
    }

    public function register_admin_bar_menu( $wp_admin_bar ) {
        if ( ! is_user_logged_in() || ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! is_object( $wp_admin_bar ) ) {
            return;
        }

        $root_id = 'wp-fastlayer-admin-bar';

        $wp_admin_bar->add_node( array(
            'id'    => $root_id,
            'title' => esc_html__( 'FastLayer', 'fastlayer' ),
            'href'  => admin_url( 'admin.php?page=wp-fastlayer' ),
            'meta'  => array(
                'class' => 'wp-fastlayer-admin-bar-root',
            ),
        ) );

        $wp_admin_bar->add_node( array(
            'id'     => 'wp-fastlayer-clear-all-cache',
            'title'  => esc_html__( 'Clear All Cache', 'fastlayer' ),
            'href'   => $this->build_action_url( 'clear_all_cache', 'cache_cleared' ),
            'parent' => $root_id,
        ) );

        $wp_admin_bar->add_node( array(
            'id'     => 'wp-fastlayer-clear-css-js-cache',
            'title'  => esc_html__( 'Clear CSS/JS Cache', 'fastlayer' ),
            'href'   => $this->build_action_url( 'clear_css_js_cache', 'css_js_cleared' ),
            'parent' => $root_id,
        ) );

        $wp_admin_bar->add_node( array(
            'id'     => 'wp-fastlayer-preload-cache',
            'title'  => esc_html__( 'Preload Cache', 'fastlayer' ),
            'href'   => $this->build_action_url( 'preload_cache', 'preload_started' ),
            'parent' => $root_id,
        ) );
    }

    public function enqueue_toolbar_assets() {
        if ( ! is_user_logged_in() || ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style( 'wp-fastlayer-admin', WP_FASTLAYER_URL . 'assets/css/admin.css', array(), WP_FASTLAYER_VERSION );
        wp_enqueue_script( 'wp-fastlayer-admin', WP_FASTLAYER_URL . 'assets/js/admin.js', array( 'jquery' ), WP_FASTLAYER_VERSION, true );
    }

    public function handle_action_request() {
        if ( ! isset( $_GET[ $this->action_key ] ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_GET[ $this->action_key ] ) );
        if ( ! in_array( $action, $this->allowed_actions, true ) ) {
            return;
        }

        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $this->get_nonce_action( $action ) ) ) {
            wp_die( esc_html__( 'Security check failed.', 'fastlayer' ), esc_html__( 'Error', 'fastlayer' ), array( 'response' => 403 ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'fastlayer' ), esc_html__( 'Error', 'fastlayer' ), array( 'response' => 403 ) );
        }

        $redirect_to = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '';
        $redirect_to = $this->sanitize_redirect_url( $redirect_to, admin_url( 'admin.php?page=wp-fastlayer' ) );

        switch ( $action ) {
            case 'clear_all_cache':
                $this->process_clear_all_cache( $redirect_to );
                break;
            case 'clear_css_js_cache':
                $this->process_clear_css_js_cache( $redirect_to );
                break;
            case 'preload_cache':
                $this->process_preload_cache( $redirect_to );
                break;
        }

        exit;
    }

    public function display_success_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_GET['wp_fastlayer_admin_bar_message'] ) ) {
            return;
        }

        $message = sanitize_text_field( wp_unslash( $_GET['wp_fastlayer_admin_bar_message'] ) );
        $notice  = '';
        $type    = 'notice-success';

        switch ( $message ) {
            case 'cache_cleared':
                $notice = esc_html__( 'Cache cleared successfully via FastLayer admin bar.', 'fastlayer' );
                break;
            case 'css_js_cleared':
                $notice = esc_html__( 'CSS/JS cache cleared successfully via FastLayer admin bar.', 'fastlayer' );
                break;
            case 'preload_started':
                // The job is still warming pages, so this is not a success
                // notice: it would report completion that has not happened.
                $type    = 'notice-info';
                $notice = esc_html__( 'Cache preload started in the background. You can keep working while pages are warmed.', 'fastlayer' );
                break;
            case 'preload_busy':
                $type    = 'notice-warning';
                $notice = esc_html__( 'A cache preload job is already running, so a second one was not started.', 'fastlayer' );
                break;
            case 'preload_disabled':
                $type    = 'notice-warning';
                $notice = esc_html__( 'Cache preloading is disabled, so nothing was queued.', 'fastlayer' );
                break;
            case 'preload_error':
                $type    = 'notice-error';
                $notice = esc_html__( 'The cache preload job could not be started. See the Cache page for details.', 'fastlayer' );
                break;
        }

        if ( $notice ) {
            printf(
                '<div class="notice %1$s wpfastlayer-notice is-dismissible"><p>%2$s</p></div>',
                esc_attr( $type ),
                $notice
            );
        }
    }

    public function add_settings_admin_bar_node( $wp_admin_bar ) {
        if ( ! is_user_logged_in() || ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! is_object( $wp_admin_bar ) ) {
            return;
        }

        $root_id = 'wp-fastlayer-admin-bar';
        if ( ! $wp_admin_bar->get_node( $root_id ) ) {
            return;
        }

        $wp_admin_bar->add_node( array(
            'id'     => 'wp-fastlayer-settings',
            'title'  => esc_html__( 'Settings', 'fastlayer' ),
            'href'   => admin_url( 'admin.php?page=wp-fastlayer' ),
            'parent' => $root_id,
        ) );
    }

    private function build_action_url( $action, $message ) {
        $redirect_to = add_query_arg(
            array(
                'page'                           => 'wp-fastlayer',
                'wp_fastlayer_admin_bar_message' => $message,
            ),
            admin_url( 'admin.php' )
        );

        $redirect_to = $this->sanitize_redirect_url( $redirect_to, admin_url( 'admin.php?page=wp-fastlayer' ) );

        $url = add_query_arg(
            array(
                $this->action_key => $action,
                'redirect_to'    => $redirect_to,
            ),
            admin_url( 'admin-post.php' )
        );

        return wp_nonce_url( $url, $this->get_nonce_action( $action ) );
    }

    private function sanitize_redirect_url( $redirect_to, $fallback ) {
        $redirect_to = trim( $redirect_to );
        $redirect_to = esc_url_raw( $redirect_to );
        $redirect_to = wp_validate_redirect( $redirect_to, $fallback );

        if ( empty( $redirect_to ) ) {
            return $fallback;
        }

        return $redirect_to;
    }

    private function get_nonce_action( $action ) {
        return $this->nonce_action_prefix . '_' . $action;
    }

    private function process_clear_all_cache( $redirect_to ) {
        $this->clear_page_cache();
        $this->clear_assets_cache();
        $this->clear_preload_cache_artifacts();

        if ( function_exists( 'delete_transient' ) ) {
            \delete_transient( 'wp_fastlayer_cache_debug_trace' );
        }

        wp_safe_redirect( $redirect_to );
        exit;
    }

    private function process_clear_css_js_cache( $redirect_to ) {
        $this->clear_assets_cache();

        if ( function_exists( 'delete_transient' ) ) {
            \delete_transient( 'wp_fastlayer_cache_debug_trace' );
        }

        wp_safe_redirect( $redirect_to );
        exit;
    }

    private function process_preload_cache( $redirect_to ) {
        $status = 'error';

        if ( class_exists( 'WP_FastLayer\Page_Cache' ) && method_exists( Page_Cache::get_instance(), 'preload_cache' ) ) {
            // The job is queued and returns immediately, so this request is not
            // held open while pages are warmed.
            $status = Page_Cache::get_instance()->preload_cache( 'manual' );
        }

        if ( function_exists( 'delete_transient' ) ) {
            \delete_transient( 'wp_fastlayer_cache_debug_trace' );
        }

        $status = sanitize_key( $status );

        wp_safe_redirect( add_query_arg( 'wp_fastlayer_admin_bar_message', 'preload_' . $status, $redirect_to ) );
        exit;
    }

    private function clear_page_cache() {
        if ( class_exists( 'WP_FastLayer\Page_Cache' ) && method_exists( Page_Cache::get_instance(), 'clear_all_cache' ) ) {
            Page_Cache::get_instance()->clear_all_cache();
            return;
        }

        $this->delete_directory_recursive( WP_FASTLAYER_CACHE_DIR );
    }

    private function clear_assets_cache() {
        $upload_dir = wp_upload_dir();
        $min_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-min/';

        $this->delete_directory_recursive( $min_dir );
    }

    private function clear_preload_cache_artifacts() {
        $upload_dir = wp_upload_dir();
        $preload_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-preload/';

        $this->delete_directory_recursive( $preload_dir );
    }

    private function delete_directory_recursive( $directory ) {
        $directory = wp_normalize_path( (string) $directory );

        if ( ! is_dir( $directory ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                @rmdir( $item->getPathname() );
                continue;
            }

            if ( $item->isFile() || $item->isLink() ) {
                @unlink( $item->getPathname() );
            }
        }

        @rmdir( $directory );
    }
}
