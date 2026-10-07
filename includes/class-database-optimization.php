<?php
namespace WP_FastLayer;
if ( ! defined( 'ABSPATH' ) ) exit;

class Database_Optimization {
    const LOCK_TTL = 300;
    const TRANSIENT_NAME_PREFIX = '_transient_';
    const SITE_TRANSIENT_NAME_PREFIX = '_site_transient_';

    private static $instance = null;
    private $notice_transient_prefix = 'wp_fastlayer_db_cleanup_notice_';
    private $lock_option_prefix = 'wp_fastlayer_db_cleanup_lock_';

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_post_wp_fastlayer_optimize_database', array( $this, 'optimize_database' ) );
        add_action( 'wp_ajax_wp_fastlayer_database_cleanup', array( $this, 'ajax_cleanup_database' ) );
        add_action( 'wp_ajax_wp_fastlayer_database_stats', array( $this, 'ajax_database_stats' ) );
        add_action( 'shutdown', array( $this, 'release_cleanup_lock' ) );
    }

    public function optimize_database() {
        check_admin_referer( 'wp_fastlayer_optimize_database_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        if ( ! $this->acquire_cleanup_lock() ) {
            $this->save_cleanup_notice_for_current_user( array(
                'success' => false,
                'stats' => array(),
                'errors' => array( __( 'A database cleanup is already running. Please wait for it to finish before starting another one.', 'fastlayer' ) ),
                'rows_removed' => 0,
                'diagnostics' => array(),
                'timestamp' => time(),
            ) );

            wp_safe_redirect( add_query_arg(
                array(
                    'page' => 'wp-fastlayer-database',
                    'db_cleanup_ts' => time(),
                ),
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        $this->log_execution_context();

        $selected_cleanup = $this->get_selected_cleanup_items_from_request();
        $result = $this->run_optimization( $selected_cleanup );

        $this->release_cleanup_lock();

        $redirect_page = isset( $_POST['redirect_page'] ) ? sanitize_key( wp_unslash( $_POST['redirect_page'] ) ) : 'wp-fastlayer-database';
        if ( empty( $redirect_page ) ) {
            $redirect_page = 'wp-fastlayer-database';
        }

        $redirect_args = array(
            'page' => $redirect_page,
            'db_cleanup_ts' => time(),
        );

        $redirect_section = isset( $_POST['redirect_section'] ) ? sanitize_key( wp_unslash( $_POST['redirect_section'] ) ) : '';
        if ( '' !== $redirect_section ) {
            $redirect_args['section'] = $redirect_section;
        }

        $notice_payload = array(
            'success' => ! empty( $result['success'] ),
            'stats' => isset( $result['stats'] ) && is_array( $result['stats'] ) ? $result['stats'] : array(),
            'errors' => isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array(),
            'rows_removed' => isset( $result['rows_removed'] ) ? (int) $result['rows_removed'] : 0,
            'diagnostics' => isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? $result['diagnostics'] : array(),
            'timestamp' => time(),
        );
        $this->save_cleanup_notice_for_current_user( $notice_payload );

        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
            $this->log_cleanup( 'Global object cache flushed after cleanup.', 'debug' );
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public function ajax_cleanup_database() {
        $this->verify_ajax_request();

        if ( ! $this->acquire_cleanup_lock() ) {
            wp_send_json_error( array(
                'message' => __( 'A database cleanup is already running. Please wait for it to finish before starting another one.', 'fastlayer' ),
                'statistics' => $this->get_cleanup_statistics(),
            ), 409 );
        }

        $selected_cleanup = $this->get_selected_cleanup_items_from_request();

        if ( empty( array_filter( $selected_cleanup ) ) ) {
            $this->release_cleanup_lock();
            wp_send_json_error( array(
                'message' => __( 'No cleanup item was selected. Enable at least one database cleanup action before clicking Clean Now.', 'fastlayer' ),
                'statistics' => $this->get_cleanup_statistics(),
            ) );
        }

        $this->log_execution_context();

        $result = $this->run_optimization( $selected_cleanup );

        $this->release_cleanup_lock();

        $statistics = $this->get_cleanup_statistics();
        $payload    = array(
            'result' => array(
                'rows_removed' => isset( $result['rows_removed'] ) ? (int) $result['rows_removed'] : 0,
                'rows_deleted' => isset( $result['rows_deleted'] ) ? (int) $result['rows_deleted'] : 0,
                'stats' => isset( $result['stats'] ) && is_array( $result['stats'] ) ? array_values( $result['stats'] ) : array(),
                'diagnostics' => isset( $result['diagnostics'] ) && is_array( $result['diagnostics'] ) ? array_values( $result['diagnostics'] ) : array(),
            ),
            'statistics' => $statistics,
        );

        if ( empty( $result['success'] ) ) {
            $payload['message'] = __( 'Database cleanup could not be completed.', 'fastlayer' );
            $payload['errors']  = isset( $result['errors'] ) && is_array( $result['errors'] ) ? array_values( array_map( 'strval', $result['errors'] ) ) : array();

            wp_send_json_error( $payload );
        }

        $payload['message'] = __( 'Database cleanup completed successfully.', 'fastlayer' );

        wp_send_json_success( $payload );
    }

    public function ajax_database_stats() {
        $this->verify_ajax_request();

        wp_send_json_success( array(
            'statistics' => $this->get_cleanup_statistics(),
        ) );
    }

    private function verify_ajax_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array(
                'message' => __( 'You do not have permission to perform this action.', 'fastlayer' ),
            ), 403 );
        }

        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'wp_fastlayer_optimize_database_nonce' ) ) {
            wp_send_json_error( array(
                'message' => __( 'Security check failed. Please reload the page and try again.', 'fastlayer' ),
            ), 403 );
        }
    }

    private function acquire_cleanup_lock() {
        $user_id = get_current_user_id();

        if ( $user_id <= 0 ) {
            return false;
        }

        $lock_name  = $this->lock_option_prefix . $user_id;
        $locked_at  = (int) get_option( $lock_name, 0 );

        if ( $locked_at > 0 ) {
            if ( ( time() - $locked_at ) < self::LOCK_TTL ) {
                $this->log_cleanup( 'Cleanup lock is already held. Skipping duplicate request.', 'warning' );
                return false;
            }

            // A lock left behind by a request that never finished would otherwise
            // block every later cleanup, so an expired one is cleared first.
            $this->log_cleanup( 'Replacing an expired cleanup lock.', 'warning' );
            delete_option( $lock_name );
        }

        if ( ! add_option( $lock_name, time(), '', false ) ) {
            $this->log_cleanup( 'Cleanup lock could not be acquired. Skipping duplicate request.', 'warning' );
            return false;
        }

        return true;
    }

    /**
     * Releases the per user cleanup lock. Public because it is registered as a
     * shutdown callback that has to run even when the request died mid cleanup.
     */
    public function release_cleanup_lock() {
        $user_id = get_current_user_id();

        if ( $user_id <= 0 ) {
            return;
        }

        delete_option( $this->lock_option_prefix . $user_id );
    }

    public function get_cleanup_statistics() {
        $transients = $this->get_transient_count_breakdown();

        $counts = array(
            'db_clean_post_revisions' => $this->get_post_revisions_count(),
            'db_clean_trashed_posts' => $this->get_trashed_posts_count(),
            'db_clean_spam_comments' => $this->get_spam_comments_count(),
            'db_clean_auto_drafts' => $this->get_auto_drafts_count(),
            'db_clean_all_transients' => isset( $transients['removable_candidates'] ) ? (int) $transients['removable_candidates'] : 0,
            'db_clean_expired_transients' => $this->get_expired_transients_count(),
            'db_clean_duplicated_postmeta' => $this->get_duplicated_postmeta_count(),
            'db_clean_duplicated_commentmeta' => $this->get_duplicated_commentmeta_count(),
            'db_clean_duplicated_usermeta' => $this->get_duplicated_usermeta_count(),
            'db_clean_duplicated_termmeta' => $this->get_duplicated_termmeta_count(),
            'db_clean_oembed_cache' => $this->get_oembed_cache_count(),
            'db_clean_orphaned_postmeta' => $this->get_orphaned_postmeta_count(),
            'db_clean_orphaned_usermeta' => $this->get_orphaned_usermeta_count(),
            'db_clean_orphaned_termmeta' => $this->get_orphaned_termmeta_count(),
            'db_clean_orphaned_commentmeta' => $this->get_orphaned_commentmeta_count(),
        );

        foreach ( $counts as $key => $value ) {
            $counts[ $key ] = (int) $value;
        }

        return array(
            'counts' => $counts,
            'transients' => $transients,
            'table_count' => (int) $this->get_table_count(),
            'database_size' => $this->get_database_size(),
            'generated_at' => time(),
        );
    }

    private function run_optimization( $selected_cleanup = array() ) {
        global $wpdb;
        $options = get_option( 'wp_fastlayer_options', array() );

        $result = array(
            'success' => true,
            'stats'   => array(),
            'errors'  => array(),
            'rows_removed' => 0,
            'rows_deleted' => 0,
            'diagnostics' => array(),
        );

        $actions = array(
            'db_clean_post_revisions' => array(
                'option_key' => 'db_clean_post_revisions',
                'label' => __( 'Post revisions removed', 'fastlayer' ),
                'callback' => 'clean_post_revisions',
                'count_callback' => 'get_post_revisions_count',
            ),
            'db_clean_trashed_posts' => array(
                'option_key' => 'db_clean_trashed_posts',
                'label' => __( 'Trashed posts removed', 'fastlayer' ),
                'callback' => 'clean_trashed_posts',
                'count_callback' => 'get_trashed_posts_count',
            ),
            'db_clean_spam_comments' => array(
                'option_key' => 'db_clean_spam_comments',
                'label' => __( 'Spam comments removed', 'fastlayer' ),
                'callback' => 'clean_spam_comments',
                'count_callback' => 'get_spam_comments_count',
            ),
            'db_clean_auto_drafts' => array(
                'option_key' => 'db_clean_auto_drafts',
                'label' => __( 'Auto-drafts removed', 'fastlayer' ),
                'callback' => 'clean_auto_drafts',
                'count_callback' => 'get_auto_drafts_count',
            ),
            'db_clean_all_transients' => array(
                'option_key' => 'db_clean_all_transients',
                'label' => __( 'All transients removed', 'fastlayer' ),
                'callback' => 'clean_all_transients',
                'count_callback' => 'get_all_transients_count',
            ),
            'db_clean_expired_transients' => array(
                'option_key' => 'db_clean_expired_transients',
                'label' => __( 'Expired transients removed', 'fastlayer' ),
                'callback' => 'clean_expired_transients',
                'count_callback' => 'get_expired_transients_count',
            ),
            'db_clean_duplicated_postmeta' => array(
                'option_key' => 'db_clean_duplicated_postmeta',
                'label' => __( 'Duplicate post meta rows removed', 'fastlayer' ),
                'callback' => 'clean_duplicated_postmeta',
                'count_callback' => 'get_duplicated_postmeta_count',
            ),
            'db_clean_duplicated_commentmeta' => array(
                'option_key' => 'db_clean_duplicated_commentmeta',
                'label' => __( 'Duplicate comment meta rows removed', 'fastlayer' ),
                'callback' => 'clean_duplicated_commentmeta',
                'count_callback' => 'get_duplicated_commentmeta_count',
            ),
            'db_clean_duplicated_usermeta' => array(
                'option_key' => 'db_clean_duplicated_usermeta',
                'label' => __( 'Duplicate user meta rows removed', 'fastlayer' ),
                'callback' => 'clean_duplicated_usermeta',
                'count_callback' => 'get_duplicated_usermeta_count',
            ),
            'db_clean_duplicated_termmeta' => array(
                'option_key' => 'db_clean_duplicated_termmeta',
                'label' => __( 'Duplicate term meta rows removed', 'fastlayer' ),
                'callback' => 'clean_duplicated_termmeta',
                'count_callback' => 'get_duplicated_termmeta_count',
            ),
            'db_clean_oembed_cache' => array(
                'option_key' => 'db_clean_oembed_cache',
                'label' => __( 'oEmbed cache rows removed', 'fastlayer' ),
                'callback' => 'clean_oembed_cache',
                'count_callback' => 'get_oembed_cache_count',
            ),
            'db_clean_orphaned_postmeta' => array(
                'option_key' => 'db_clean_orphaned_postmeta',
                'label' => __( 'Orphaned post meta rows removed', 'fastlayer' ),
                'callback' => 'clean_orphaned_postmeta',
                'count_callback' => 'get_orphaned_postmeta_count',
            ),
            'db_clean_orphaned_usermeta' => array(
                'option_key' => 'db_clean_orphaned_usermeta',
                'label' => __( 'Orphaned user meta rows removed', 'fastlayer' ),
                'callback' => 'clean_orphaned_usermeta',
                'count_callback' => 'get_orphaned_usermeta_count',
            ),
            'db_clean_orphaned_termmeta' => array(
                'option_key' => 'db_clean_orphaned_termmeta',
                'label' => __( 'Orphaned term meta rows removed', 'fastlayer' ),
                'callback' => 'clean_orphaned_termmeta',
                'count_callback' => 'get_orphaned_termmeta_count',
            ),
            'db_clean_orphaned_commentmeta' => array(
                'option_key' => 'db_clean_orphaned_commentmeta',
                'label' => __( 'Orphaned comment meta rows removed', 'fastlayer' ),
                'callback' => 'clean_orphaned_commentmeta',
                'count_callback' => 'get_orphaned_commentmeta_count',
            ),
        );

        $this->log_cleanup( 'Database cleanup started.' );
        $this->log_cleanup( 'External object cache active: ' . ( wp_using_ext_object_cache() ? 'yes' : 'no' ), 'debug' );
        $executed_any_action = false;

        foreach ( $actions as $action_key => $config ) {
            $enabled_in_request = isset( $selected_cleanup[ $action_key ] ) ? (bool) $selected_cleanup[ $action_key ] : null;
            $enabled_in_options = isset( $options[ $config['option_key'] ] ) && '1' === (string) $options[ $config['option_key'] ];
            $should_run = null !== $enabled_in_request ? $enabled_in_request : $enabled_in_options;

            if ( ! $should_run ) {
                continue;
            }

            $executed_any_action = true;

            if ( ! method_exists( $this, $config['callback'] ) ) {
                $result['success'] = false;
                $result['errors'][] = sprintf( __( 'Cleanup callback not found for %s.', 'fastlayer' ), $action_key );
                $this->log_cleanup( 'Missing cleanup callback: ' . $config['callback'], 'error' );
                continue;
            }

            $before_count = null;
            if ( isset( $config['count_callback'] ) && method_exists( $this, $config['count_callback'] ) ) {
                $before_count = (int) call_user_func( array( $this, $config['count_callback'] ) );
                $this->log_cleanup( $config['label'] . ' count before cleanup: ' . $before_count, 'debug' );
            }

            $diagnostic = array(
                'action_key' => $action_key,
                'label' => $config['label'],
                'before_count' => null !== $before_count ? (int) $before_count : null,
                'ids_detected' => null,
                'delete_attempts' => 0,
                'successful_deletions' => 0,
                'failed_deletions' => 0,
                'after_count' => null,
                'validation' => 'pending',
                'notes' => array(),
            );

            $action_result = call_user_func( array( $this, $config['callback'] ) );
            if ( isset( $action_result['success'] ) && false === $action_result['success'] ) {
                $result['success'] = false;
                $result['stats'][] = array(
                    'label' => $config['label'],
                    'count' => 0,
                );

                if ( isset( $action_result['diagnostics'] ) && is_array( $action_result['diagnostics'] ) ) {
                    $diagnostic = array_merge( $diagnostic, $action_result['diagnostics'] );
                }

                if ( ! empty( $action_result['error'] ) ) {
                    $result['errors'][] = $action_result['error'];
                    $diagnostic['notes'][] = $action_result['error'];
                    $this->log_cleanup( $config['label'] . ' failed. Error: ' . $action_result['error'], 'error' );
                } else {
                    $result['errors'][] = sprintf( __( 'Cleanup failed for %s.', 'fastlayer' ), $config['label'] );
                    $this->log_cleanup( $config['label'] . ' failed without an explicit SQL error.', 'error' );
                }

                $diagnostic['validation'] = 'failed';
                $result['diagnostics'][] = $diagnostic;

                continue;
            }

            $affected_rows = isset( $action_result['affected'] ) ? (int) $action_result['affected'] : 0;

            if ( isset( $action_result['diagnostics'] ) && is_array( $action_result['diagnostics'] ) ) {
                $diagnostic = array_merge( $diagnostic, $action_result['diagnostics'] );
            }

            if ( null !== $before_count && isset( $config['count_callback'] ) && method_exists( $this, $config['count_callback'] ) ) {
                $after_count = (int) call_user_func( array( $this, $config['count_callback'] ) );
                $verified_removed = max( 0, $before_count - $after_count );
                $this->log_cleanup( $config['label'] . ' count after cleanup: ' . $after_count . '. Verified removed: ' . $verified_removed, 'debug' );
                $diagnostic['after_count'] = $after_count;
                $affected_rows = $verified_removed;

                if ( $before_count > 0 && 0 === $verified_removed ) {
                    $result['success'] = false;
                    $result['errors'][] = sprintf( __( '%1$s did not remove any rows. Records before cleanup: %2$d.', 'fastlayer' ), $config['label'], $before_count );
                    $diagnostic['notes'][] = sprintf( __( 'Validation failed. Records before cleanup: %d, after cleanup: %d.', 'fastlayer' ), $before_count, $after_count );
                    $diagnostic['validation'] = 'failed';
                    $this->log_cleanup( $config['label'] . ' validation failed. Existing rows were not removed.', 'warning' );
                } else {
                    $diagnostic['validation'] = 'passed';
                }
            }

            if ( null === $diagnostic['after_count'] ) {
                $diagnostic['validation'] = 'passed';
            }

            if ( empty( $diagnostic['ids_detected'] ) && null !== $before_count ) {
                $diagnostic['ids_detected'] = $before_count;
            }

            if ( 0 === (int) $diagnostic['delete_attempts'] ) {
                $diagnostic['delete_attempts'] = null !== $before_count ? $before_count : $affected_rows;
            }

            if ( 0 === (int) $diagnostic['successful_deletions'] ) {
                $diagnostic['successful_deletions'] = $affected_rows;
            }

            if ( 0 === (int) $diagnostic['failed_deletions'] && null !== $before_count && $before_count > $affected_rows ) {
                $diagnostic['failed_deletions'] = max( 0, $before_count - $affected_rows );
            }

            $result['stats'][] = array(
                'label' => $config['label'],
                'count' => $affected_rows,
            );
            $result['diagnostics'][] = $diagnostic;
            $result['rows_removed'] += $affected_rows;
            $result['rows_deleted'] += isset( $action_result['affected'] ) ? (int) $action_result['affected'] : 0;
            $this->log_cleanup( $config['label'] . ' executed. Rows affected: ' . $affected_rows );
        }

        $optimization_result = $this->optimize_all_tables();
        $result['stats'][] = array(
            'label' => __( 'Database tables optimized', 'fastlayer' ),
            'count' => isset( $optimization_result['affected'] ) ? (int) $optimization_result['affected'] : 0,
        );

        if ( isset( $optimization_result['success'] ) && false === $optimization_result['success'] ) {
            $result['success'] = false;
            if ( ! empty( $optimization_result['error'] ) ) {
                $result['errors'][] = $optimization_result['error'];
            }
            $this->log_cleanup( 'Table optimization failed. Error: ' . ( isset( $optimization_result['error'] ) ? $optimization_result['error'] : 'unknown' ), 'error' );
        } else {
            $this->log_cleanup( 'Table optimization completed. Tables affected: ' . ( isset( $optimization_result['affected'] ) ? (int) $optimization_result['affected'] : 0 ) );
        }

        if ( ! $executed_any_action ) {
            $result['errors'][] = __( 'No cleanup item was selected. Enable at least one database cleanup action.', 'fastlayer' );
            $result['success'] = false;
            $this->log_cleanup( 'Database cleanup finished with no selected cleanup items.', 'warning' );
        } elseif ( true === $result['success'] && 0 === (int) $result['rows_removed'] ) {
            $result['success'] = false;
            $result['errors'][] = __( 'No matching records were found for the selected cleanup items. Nothing was removed.', 'fastlayer' );
            $this->log_cleanup( 'Database cleanup ran without SQL errors but removed 0 rows.', 'warning' );
        }

        $this->log_cleanup( 'Database cleanup completed. Success: ' . ( $result['success'] ? 'yes' : 'no' ) . '. Rows removed: ' . (int) $result['rows_removed'] );
        $this->log_cleanup( 'Transient recount summary after cleanup: ' . wp_json_encode( $this->get_transient_count_breakdown() ), 'debug' );

        return $result;
    }

    private function optimize_all_tables() {
        global $wpdb;

        $tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
        if ( empty( $tables ) ) {
            return array(
                'success' => true,
                'affected' => 0,
            );
        }

        $optimized_tables = 0;
        $errors = array();

        foreach ( $tables as $table ) {
            $table_name = isset( $table[0] ) ? $table[0] : '';
            if ( '' === $table_name ) {
                continue;
            }

            $result = $wpdb->query( 'OPTIMIZE TABLE `' . sanitize_key( $table_name ) . '`' );
            if ( false === $result ) {
                $errors[] = sprintf( __( 'Failed to optimize table %s: %s', 'fastlayer' ), $table_name, $wpdb->last_error );
                continue;
            }

            $optimized_tables++;
        }

        if ( ! empty( $errors ) ) {
            return array(
                'success' => false,
                'affected' => $optimized_tables,
                'error' => implode( ' | ', $errors ),
            );
        }

        return array(
            'success' => true,
            'affected' => $optimized_tables,
        );
    }

    private function get_selected_cleanup_items_from_request() {
        $raw = array();

        if ( ! empty( $_REQUEST['cleanup_items'] ) && is_array( $_REQUEST['cleanup_items'] ) ) {
            $raw = wp_unslash( $_REQUEST['cleanup_items'] );
        }

        $known_keys = array_keys( $this->get_cleanup_action_map() );
        $selected   = array();

        foreach ( $known_keys as $key ) {
            // Only keys that were actually submitted are returned so that a
            // request without a cleanup_items payload keeps falling back to the
            // saved cleanup options instead of silently disabling everything.
            if ( ! array_key_exists( $key, $raw ) ) {
                continue;
            }

            $selected[ $key ] = '1' === (string) $raw[ $key ];
        }

        return $selected;
    }

    private function get_cleanup_action_map() {
        return array(
            'db_clean_post_revisions' => array( 'clean_post_revisions', 'get_post_revisions_count' ),
            'db_clean_trashed_posts' => array( 'clean_trashed_posts', 'get_trashed_posts_count' ),
            'db_clean_spam_comments' => array( 'clean_spam_comments', 'get_spam_comments_count' ),
            'db_clean_auto_drafts' => array( 'clean_auto_drafts', 'get_auto_drafts_count' ),
            'db_clean_all_transients' => array( 'clean_all_transients', 'get_all_transients_count' ),
            'db_clean_expired_transients' => array( 'clean_expired_transients', 'get_expired_transients_count' ),
            'db_clean_duplicated_postmeta' => array( 'clean_duplicated_postmeta', 'get_duplicated_postmeta_count' ),
            'db_clean_duplicated_commentmeta' => array( 'clean_duplicated_commentmeta', 'get_duplicated_commentmeta_count' ),
            'db_clean_duplicated_usermeta' => array( 'clean_duplicated_usermeta', 'get_duplicated_usermeta_count' ),
            'db_clean_duplicated_termmeta' => array( 'clean_duplicated_termmeta', 'get_duplicated_termmeta_count' ),
            'db_clean_oembed_cache' => array( 'clean_oembed_cache', 'get_oembed_cache_count' ),
            'db_clean_orphaned_postmeta' => array( 'clean_orphaned_postmeta', 'get_orphaned_postmeta_count' ),
            'db_clean_orphaned_usermeta' => array( 'clean_orphaned_usermeta', 'get_orphaned_usermeta_count' ),
            'db_clean_orphaned_termmeta' => array( 'clean_orphaned_termmeta', 'get_orphaned_termmeta_count' ),
            'db_clean_orphaned_commentmeta' => array( 'clean_orphaned_commentmeta', 'get_orphaned_commentmeta_count' ),
        );
    }

    private function save_cleanup_notice_for_current_user( $notice_payload ) {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return;
        }

        set_transient( $this->notice_transient_prefix . $user_id, $notice_payload, 120 );
    }

    private function log_cleanup( $message, $type = 'info' ) {
        if ( class_exists( 'WP_FastLayer\\Logger' ) ) {
            Bootstrap::get_instance()->log( '[DB Cleanup] ' . $message, $type );
            return;
        }

        error_log( 'WP FastLayer [DB Cleanup] [' . strtoupper( $type ) . '] ' . $message );
    }

    private function log_execution_context() {
        $user_id = get_current_user_id();
        $blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        $is_switched = function_exists( 'ms_is_switched' ) ? (bool) ms_is_switched() : false;

        $context = array(
            'is_admin' => is_admin() ? 'yes' : 'no',
            'doing_ajax' => ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? 'yes' : 'no',
            'doing_cron' => ( defined( 'DOING_CRON' ) && DOING_CRON ) ? 'yes' : 'no',
            'is_multisite' => is_multisite() ? 'yes' : 'no',
            'current_blog_id' => $blog_id,
            'ms_is_switched' => $is_switched ? 'yes' : 'no',
            'current_user_id' => $user_id,
            'can_manage_options' => current_user_can( 'manage_options' ) ? 'yes' : 'no',
        );

        $this->log_cleanup( 'Execution context: ' . wp_json_encode( $context ), 'debug' );
    }

    private function get_hook_callback_count( $hook_name ) {
        global $wp_filter;

        if ( ! isset( $wp_filter[ $hook_name ] ) ) {
            return 0;
        }

        $hook_object = $wp_filter[ $hook_name ];
        $callbacks = is_object( $hook_object ) && isset( $hook_object->callbacks ) ? $hook_object->callbacks : $hook_object;
        if ( ! is_array( $callbacks ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $callbacks as $priority_callbacks ) {
            if ( is_array( $priority_callbacks ) ) {
                $count += count( $priority_callbacks );
            }
        }

        return $count;
    }

    private function get_deletion_hooks_snapshot() {
        $hooks = array(
            'before_delete_post',
            'delete_post',
            'deleted_post',
            'wp_trash_post',
            'trashed_post',
            'pre_delete_post',
        );

        $snapshot = array();
        foreach ( $hooks as $hook ) {
            $snapshot[ $hook ] = $this->get_hook_callback_count( $hook );
        }

        return $snapshot;
    }

    private function log_wp_delete_post_failure( $operation, $post_id, $post_object, $can_delete_post, $delete_result, $fallback_result = array(), $hook_snapshot = array() ) {
        global $wpdb;

        $post_type = ( $post_object && isset( $post_object->post_type ) ) ? $post_object->post_type : 'unknown';
        $post_status = ( $post_object && isset( $post_object->post_status ) ) ? $post_object->post_status : 'unknown';
        $fallback_success = isset( $fallback_result['success'] ) ? ( $fallback_result['success'] ? 'yes' : 'no' ) : 'no';
        $fallback_error = isset( $fallback_result['error'] ) ? (string) $fallback_result['error'] : '';

        $details = array(
            'post_id' => (int) $post_id,
            'post_type' => $post_type,
            'post_status' => $post_status,
            'current_user_id' => get_current_user_id(),
            'current_user_can_delete_post' => $can_delete_post ? 'yes' : 'no',
            'current_user_can_manage_options' => current_user_can( 'manage_options' ) ? 'yes' : 'no',
            'wp_delete_post_return' => is_object( $delete_result ) ? get_class( $delete_result ) : ( is_scalar( $delete_result ) ? (string) $delete_result : gettype( $delete_result ) ),
            'db_last_error' => (string) $wpdb->last_error,
            'is_admin' => is_admin() ? 'yes' : 'no',
            'is_multisite' => is_multisite() ? 'yes' : 'no',
            'current_blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
            'ms_is_switched' => function_exists( 'ms_is_switched' ) && ms_is_switched() ? 'yes' : 'no',
            'object_cache_active' => wp_using_ext_object_cache() ? 'yes' : 'no',
            'hook_snapshot' => $hook_snapshot,
            'fallback_success' => $fallback_success,
            'fallback_error' => $fallback_error,
        );

        $this->log_cleanup( $operation . ' wp_delete_post failure details: ' . wp_json_encode( $details ), 'error' );
    }



    public function get_database_size() {
        global $wpdb;
        
        $tables = $wpdb->get_results( "SHOW TABLE STATUS", ARRAY_A );
        $total_size = 0;
        
        foreach ( $tables as $table ) {
            $total_size += ( $table['Data_length'] + $table['Index_length'] );
        }
        
        return size_format( $total_size );
    }

    public function get_table_count() {
        global $wpdb;
        $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
        return count( $tables );
    }

    public function get_auto_drafts_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
    }

    public function get_post_revisions_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
    }

    public function get_trashed_posts_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
    }

    public function get_spam_comments_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
    }

    public function get_all_transients_count() {
        $breakdown = $this->get_transient_count_breakdown();
        return isset( $breakdown['removable_candidates'] ) ? (int) $breakdown['removable_candidates'] : 0;
    }

    public function get_transient_count_breakdown() {
        global $wpdb;

        $sql = "
            SELECT SQL_NO_CACHE
                SUM(CASE WHEN o.option_name LIKE '\\_transient\\_timeout\\_%' OR o.option_name LIKE '\\_site\\_transient\\_timeout\\_%' THEN 1 ELSE 0 END) AS timeout_rows,
                SUM(CASE WHEN (o.option_name LIKE '\\_transient\\_%' OR o.option_name LIKE '\\_site\\_transient\\_%')
                          AND o.option_name NOT LIKE '\\_transient\\_timeout\\_%'
                          AND o.option_name NOT LIKE '\\_site\\_transient\\_timeout\\_%' THEN 1 ELSE 0 END) AS value_rows,
                SUM(CASE WHEN (o.option_name LIKE '\\_transient\\_timeout\\_%' OR o.option_name LIKE '\\_site\\_transient\\_timeout\\_%')
                          AND CAST(o.option_value AS UNSIGNED) < UNIX_TIMESTAMP() THEN 1 ELSE 0 END) AS expired_timeout_rows,
                SUM(CASE WHEN (o.option_name LIKE '\\_transient\\_timeout\\_%' OR o.option_name LIKE '\\_site\\_transient\\_timeout\\_%')
                          AND CAST(o.option_value AS UNSIGNED) >= UNIX_TIMESTAMP() THEN 1 ELSE 0 END) AS active_timeout_rows
            FROM {$wpdb->options} o
            WHERE o.option_name LIKE '\\_transient\\_%' OR o.option_name LIKE '\\_site\\_transient\\_%'
        ";

        $aggregates = $wpdb->get_row( $sql, ARRAY_A );
        if ( ! is_array( $aggregates ) ) {
            $aggregates = array();
        }

        $timeout_rows = isset( $aggregates['timeout_rows'] ) ? (int) $aggregates['timeout_rows'] : 0;
        $value_rows = isset( $aggregates['value_rows'] ) ? (int) $aggregates['value_rows'] : 0;
        $expired_timeout_rows = isset( $aggregates['expired_timeout_rows'] ) ? (int) $aggregates['expired_timeout_rows'] : 0;
        $active_timeout_rows = isset( $aggregates['active_timeout_rows'] ) ? (int) $aggregates['active_timeout_rows'] : 0;

        $orphan_timeout_rows = (int) $wpdb->get_var(
            "SELECT SQL_NO_CACHE COUNT(*)
             FROM {$wpdb->options} t
             LEFT JOIN {$wpdb->options} v
                ON v.option_name = CONCAT(
                    IF(t.option_name LIKE '\\_site\\_transient\\_timeout\\_%', '_site_transient_', '_transient_'),
                    SUBSTRING_INDEX(t.option_name, '_timeout_', -1)
                )
             WHERE (t.option_name LIKE '\\_transient\\_timeout\\_%' OR t.option_name LIKE '\\_site\\_transient\\_timeout\\_%')
               AND v.option_id IS NULL"
        );

        $runtime_no_timeout_rows = (int) $wpdb->get_var(
            "SELECT SQL_NO_CACHE COUNT(*)
             FROM {$wpdb->options} v
             LEFT JOIN {$wpdb->options} t
                ON t.option_name = CONCAT(
                    IF(v.option_name LIKE '\\_site\\_transient\\_%', '_site_transient_timeout_', '_transient_timeout_'),
                    IF(v.option_name LIKE '\\_site\\_transient\\_%', SUBSTRING(v.option_name, 17), SUBSTRING(v.option_name, 12))
                )
             WHERE (v.option_name LIKE '\\_transient\\_%' OR v.option_name LIKE '\\_site\\_transient\\_%')
               AND v.option_name NOT LIKE '\\_transient\\_timeout\\_%'
               AND v.option_name NOT LIKE '\\_site\\_transient\\_timeout\\_%'
               AND t.option_id IS NULL"
        );

$active_runtime_rows = max( 0, $active_timeout_rows + $runtime_no_timeout_rows );

        /*
         * A transient row is only a removable candidate when its timeout row has
         * already expired, or when the timeout row no longer has a matching value
         * row (an orphan left behind by a partially written transient).
         *
         * Both conditions are evaluated in a single query so that a row which is
         * both expired and orphaned is counted once, and so that an active
         * (not yet expired) orphan timeout row is never reported as removable.
         */
        $removable_candidates = (int) $wpdb->get_var(
            "SELECT SQL_NO_CACHE COUNT(*)
             FROM {$wpdb->options} t
             LEFT JOIN {$wpdb->options} v
                ON v.option_name = CONCAT(
                    IF(t.option_name LIKE '\_site\_transient\_timeout\_%', '_site_transient_', '_transient_'),
                    SUBSTRING_INDEX(t.option_name, '_timeout_', -1)
                )
             WHERE (t.option_name LIKE '\_transient\_timeout\_%' OR t.option_name LIKE '\_site\_transient\_timeout\_%')
               AND ( v.option_id IS NULL OR CAST(t.option_value AS UNSIGNED) < UNIX_TIMESTAMP() )"
        );

        return array(
            'removable_candidates' => $removable_candidates,
            'timeout_rows'         => $timeout_rows,
            'value_rows'           => $value_rows,
            'expired_timeout_rows' => $expired_timeout_rows,
            'active_timeout_rows'  => $active_timeout_rows,
            'orphan_timeout_rows'  => $orphan_timeout_rows,
            'runtime_no_timeout_rows' => $runtime_no_timeout_rows,
            'active_runtime_rows'  => $active_runtime_rows,
        );
    }

    public function get_duplicated_postmeta_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm1 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.meta_id > pm2.meta_id AND pm1.post_id = pm2.post_id AND pm1.meta_key = pm2.meta_key AND pm1.meta_value <=> pm2.meta_value" );
    }

    public function get_duplicated_commentmeta_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm1 INNER JOIN {$wpdb->commentmeta} cm2 ON cm1.meta_id > cm2.meta_id AND cm1.comment_id = cm2.comment_id AND cm1.meta_key = cm2.meta_key AND cm1.meta_value <=> cm2.meta_value" );
    }

    public function get_duplicated_usermeta_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} um1 INNER JOIN {$wpdb->usermeta} um2 ON um1.umeta_id > um2.umeta_id AND um1.user_id = um2.user_id AND um1.meta_key = um2.meta_key AND um1.meta_value <=> um2.meta_value" );
    }

    public function get_duplicated_termmeta_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} tm1 INNER JOIN {$wpdb->termmeta} tm2 ON tm1.meta_id > tm2.meta_id AND tm1.term_id = tm2.term_id AND tm1.meta_key = tm2.meta_key AND tm1.meta_value <=> tm2.meta_value" );
    }

    public function get_expired_transients_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE '\\_transient\\_timeout\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_%') AND CAST(option_value AS UNSIGNED) < UNIX_TIMESTAMP()" );
    }

    public function get_oembed_cache_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_oembed\\_%'" );
    }

    public function get_orphaned_postmeta_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
    }

    public function get_orphaned_usermeta_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL" );
    }

    public function get_orphaned_termmeta_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id WHERE t.term_id IS NULL" );
    }

    public function get_orphaned_commentmeta_count() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" );
    }

    public function clean_auto_drafts() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Auto drafts cleanup', 'fastlayer' ), "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
    }

    public function clean_post_revisions() {
        global $wpdb;
        $operation = __( 'Post revisions cleanup', 'fastlayer' );
        $this->log_cleanup( $operation . ' SQL: SELECT ID FROM ' . $wpdb->posts . " WHERE post_type = 'revision'", 'debug' );
        $revision_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" );
        $hook_snapshot = $this->get_deletion_hooks_snapshot();
        $this->log_cleanup( $operation . ' hook snapshot: ' . wp_json_encode( $hook_snapshot ), 'debug' );

        $diagnostics = array(
            'ids_detected' => is_array( $revision_ids ) ? count( $revision_ids ) : 0,
            'delete_attempts' => is_array( $revision_ids ) ? count( $revision_ids ) : 0,
            'successful_deletions' => 0,
            'failed_deletions' => 0,
            'notes' => array(),
        );

        if ( empty( $revision_ids ) ) {
            $this->log_cleanup( $operation . ' skipped. No revisions found.', 'debug' );
            return array(
                'success' => true,
                'affected' => 0,
                'error' => '',
                'diagnostics' => $diagnostics,
            );
        }

        $deleted = 0;
        $failed_ids = array();
        foreach ( $revision_ids as $revision_id ) {
            $revision_id = (int) $revision_id;
            $post_object = get_post( $revision_id );
            $can_delete_post = current_user_can( 'delete_post', $revision_id );
            if ( ! $can_delete_post ) {
                $this->log_cleanup( $operation . ' permission denied for revision ID: ' . $revision_id, 'error' );
            }

            $deleted_post_id = wp_delete_post( $revision_id, true );
            if ( false !== $deleted_post_id && null !== $deleted_post_id ) {
                $deleted++;
            } else {
                $this->log_wp_delete_post_failure( $operation, $revision_id, $post_object, $can_delete_post, $deleted_post_id, array( 'success' => false, 'error' => 'wp_delete_post failed' ), $hook_snapshot );
                $failed_ids[] = $revision_id;
            }
        }

        $diagnostics['successful_deletions'] = $deleted;
        $diagnostics['failed_deletions'] = count( $failed_ids );
        if ( ! empty( $failed_ids ) ) {
            $diagnostics['notes'][] = 'Failed IDs sample: ' . implode( ', ', array_slice( $failed_ids, 0, 20 ) );
            $this->log_cleanup( $operation . ' failed IDs sample: ' . implode( ', ', array_slice( $failed_ids, 0, 20 ) ), 'warning' );
        }

        $this->log_cleanup( $operation . ' completed via wp_delete_post(force=true). Rows deleted: ' . $deleted, 'info' );

        return array(
            'success' => true,
            'affected' => $deleted,
            'error' => '',
            'diagnostics' => $diagnostics,
        );
    }

    public function clean_trashed_posts() {
        global $wpdb;
        $operation = __( 'Trashed posts cleanup', 'fastlayer' );
        $this->log_cleanup( $operation . ' SQL: SELECT ID FROM ' . $wpdb->posts . " WHERE post_status = 'trash'", 'debug' );
        $trashed_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'" );
        $hook_snapshot = $this->get_deletion_hooks_snapshot();
        $this->log_cleanup( $operation . ' hook snapshot: ' . wp_json_encode( $hook_snapshot ), 'debug' );

        $diagnostics = array(
            'ids_detected' => is_array( $trashed_ids ) ? count( $trashed_ids ) : 0,
            'delete_attempts' => is_array( $trashed_ids ) ? count( $trashed_ids ) : 0,
            'successful_deletions' => 0,
            'failed_deletions' => 0,
            'notes' => array(),
        );

        if ( empty( $trashed_ids ) ) {
            $this->log_cleanup( $operation . ' skipped. No trashed posts found.', 'debug' );
            return array(
                'success' => true,
                'affected' => 0,
                'error' => '',
                'diagnostics' => $diagnostics,
            );
        }

        $deleted = 0;
        $failed_ids = array();
        foreach ( $trashed_ids as $trashed_id ) {
            $trashed_id = (int) $trashed_id;
            $post_object = get_post( $trashed_id );
            $can_delete_post = current_user_can( 'delete_post', $trashed_id );
            if ( ! $can_delete_post ) {
                $this->log_cleanup( $operation . ' permission denied for trashed post ID: ' . $trashed_id, 'error' );
            }

            $deleted_post_id = wp_delete_post( $trashed_id, true );
            if ( false !== $deleted_post_id && null !== $deleted_post_id ) {
                $deleted++;
            } else {
                $this->log_wp_delete_post_failure( $operation, $trashed_id, $post_object, $can_delete_post, $deleted_post_id, array( 'success' => false, 'error' => 'wp_delete_post failed' ), $hook_snapshot );
                $failed_ids[] = $trashed_id;
            }
        }

        $diagnostics['successful_deletions'] = $deleted;
        $diagnostics['failed_deletions'] = count( $failed_ids );
        if ( ! empty( $failed_ids ) ) {
            $diagnostics['notes'][] = 'Failed IDs sample: ' . implode( ', ', array_slice( $failed_ids, 0, 20 ) );
            $this->log_cleanup( $operation . ' failed IDs sample: ' . implode( ', ', array_slice( $failed_ids, 0, 20 ) ), 'warning' );
        }

        $this->log_cleanup( $operation . ' completed via wp_delete_post(force=true). Rows deleted: ' . $deleted, 'info' );

        return array(
            'success' => true,
            'affected' => $deleted,
            'error' => '',
            'diagnostics' => $diagnostics,
        );
    }

    public function clean_spam_comments() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Spam comments cleanup', 'fastlayer' ), "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
    }

    public function clean_all_transients() {
        $targets = $this->get_removable_transient_option_names();

        if ( empty( $targets['timeout'] ) && empty( $targets['value'] ) ) {
            $this->log_cleanup( 'Transients cleanup skipped. No removable candidates found.', 'debug' );

            return array(
                'success' => true,
                'affected' => 0,
                'error' => '',
                'diagnostics' => array(
                    'ids_detected' => 0,
                    'delete_attempts' => 0,
                    'successful_deletions' => 0,
                    'failed_deletions' => 0,
                    'notes' => array( 'No expired or orphaned transient candidates were present.' ),
                ),
            );
        }

        // The paired value rows are removed first so that removing a timeout row
        // never turns a still-active value row into a new "no timeout" orphan.
        $value_result   = $this->delete_options_by_name( __( 'Transient values cleanup', 'fastlayer' ), $targets['value'] );
        $timeout_result = $this->delete_options_by_name( __( 'Transients cleanup', 'fastlayer' ), $targets['timeout'] );

        $deleted_names = array_merge( $targets['value'], $targets['timeout'] );
        $this->invalidate_transient_caches( $deleted_names );

        if ( false === $value_result['success'] || false === $timeout_result['success'] ) {
            return array(
                'success' => false,
                'affected' => (int) $value_result['affected'] + (int) $timeout_result['affected'],
                'error' => trim( (string) $value_result['error'] . ' ' . (string) $timeout_result['error'] ),
            );
        }

        return array(
            'success' => true,
            'affected' => (int) $value_result['affected'] + (int) $timeout_result['affected'],
            'error' => '',
        );
    }

    /**
     * Resolves the exact option names that make up the removable transient
     * candidates reported by get_all_transients_count().
     *
     * Only expired timeout rows and orphaned timeout rows are returned, together
     * with the value row each of them belongs to. Active runtime transients are
     * deliberately left untouched.
     */
    private function get_removable_transient_option_names( $expired_only = false ) {
        global $wpdb;

        $expired_clause = $expired_only
            ? 'CAST(t.option_value AS UNSIGNED) < UNIX_TIMESTAMP()'
            : '( v.option_id IS NULL OR CAST(t.option_value AS UNSIGNED) < UNIX_TIMESTAMP() )';

        $rows = $wpdb->get_results(
            "SELECT t.option_name AS option_name
             FROM {$wpdb->options} t
             LEFT JOIN {$wpdb->options} v
                ON v.option_name = CONCAT(
                    IF(t.option_name LIKE '\_site\_transient\_timeout\_%', '_site_transient_', '_transient_'),
                    SUBSTRING_INDEX(t.option_name, '_timeout_', -1)
                )
             WHERE (t.option_name LIKE '\_transient\_timeout\_%' OR t.option_name LIKE '\_site\_transient\_timeout\_%')
               AND " . $expired_clause, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        $timeout_names = array();
        $value_names   = array();

        foreach ( (array) $rows as $row ) {
            $timeout_name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';

            if ( '' === $timeout_name ) {
                continue;
            }

            if ( 0 === strpos( $timeout_name, self::SITE_TRANSIENT_NAME_PREFIX . 'timeout_' ) ) {
                $transient_key = substr( $timeout_name, strlen( self::SITE_TRANSIENT_NAME_PREFIX . 'timeout_' ) );
                $value_prefix  = self::SITE_TRANSIENT_NAME_PREFIX;
            } else {
                $transient_key = substr( $timeout_name, strlen( self::TRANSIENT_NAME_PREFIX . 'timeout_' ) );
                $value_prefix  = self::TRANSIENT_NAME_PREFIX;
            }

            $timeout_names[] = $timeout_name;
            $value_names[]   = $value_prefix . $transient_key;
        }

        return array(
            'timeout' => array_values( array_unique( $timeout_names ) ),
            'value'   => array_values( array_unique( $value_names ) ),
        );
    }

    private function delete_options_by_name( $operation, array $option_names ) {
        global $wpdb;

        $option_names = array_values( array_unique( array_filter( array_map( 'strval', $option_names ), 'strlen' ) ) );

        if ( empty( $option_names ) ) {
            return array(
                'success' => true,
                'affected' => 0,
                'error' => '',
            );
        }

        $affected = 0;
        $errors   = array();

        foreach ( array_chunk( $option_names, 250 ) as $chunk ) {
            $placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
            $sql          = "DELETE FROM {$wpdb->options} WHERE option_name IN ( " . $placeholders . ' )';

            $this->log_cleanup( $operation . ' SQL: ' . $sql, 'debug' );

            $chunk_affected = $wpdb->query( $wpdb->prepare( $sql, $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            if ( false === $chunk_affected ) {
                $errors[] = (string) $wpdb->last_error;
                continue;
            }

            $affected += (int) $chunk_affected;
        }

        if ( ! empty( $errors ) ) {
            $this->log_cleanup( $operation . ' failed. SQL error: ' . implode( ' | ', $errors ), 'error' );

            return array(
                'success' => false,
                'affected' => $affected,
                'error' => implode( ' | ', $errors ),
            );
        }

        $this->log_cleanup( $operation . ' succeeded. Rows affected: ' . $affected, 'info' );

        return array(
            'success' => true,
            'affected' => $affected,
            'error' => '',
        );
    }

    /**
     * Keeps the transient related object cache entries in sync with the rows
     * removed by the raw SQL cleanup above, without flushing the whole
     * object cache for unrelated data.
     */
    private function invalidate_transient_caches( array $option_names ) {
        $value_cache_groups   = array(
            self::TRANSIENT_NAME_PREFIX => 'transient',
            self::SITE_TRANSIENT_NAME_PREFIX => 'site_transient',
        );
        $timeout_cache_groups  = array(
            self::TRANSIENT_NAME_PREFIX . 'timeout_' => 'timeout',
            self::SITE_TRANSIENT_NAME_PREFIX . 'timeout_' => 'site-timeout',
        );

        foreach ( array_unique( array_map( 'strval', $option_names ) ) as $option_name ) {
            if ( '' === $option_name ) {
                continue;
            }

            foreach ( $value_cache_groups as $prefix => $group ) {
                if ( 0 === strpos( $option_name, $prefix ) && strlen( $option_name ) > strlen( $prefix ) ) {
                    wp_cache_delete( substr( $option_name, strlen( $prefix ) ), $group );
                    wp_cache_delete( substr( $option_name, strlen( $prefix ) ), $group . '_timeout' );
                    break;
                }
            }

            foreach ( $timeout_cache_groups as $prefix => $group ) {
                if ( 0 === strpos( $option_name, $prefix ) && strlen( $option_name ) > strlen( $prefix ) ) {
                    wp_cache_delete( substr( $option_name, strlen( $prefix ) ), $group );
                    break;
                }
            }
        }
    }

    public function clean_duplicated_postmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Duplicate post meta cleanup', 'fastlayer' ), "DELETE pm1 FROM {$wpdb->postmeta} pm1 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.meta_id > pm2.meta_id AND pm1.post_id = pm2.post_id AND pm1.meta_key = pm2.meta_key AND pm1.meta_value <=> pm2.meta_value" );
    }

    public function clean_duplicated_commentmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Duplicate comment meta cleanup', 'fastlayer' ), "DELETE cm1 FROM {$wpdb->commentmeta} cm1 INNER JOIN {$wpdb->commentmeta} cm2 ON cm1.meta_id > cm2.meta_id AND cm1.comment_id = cm2.comment_id AND cm1.meta_key = cm2.meta_key AND cm1.meta_value <=> cm2.meta_value" );
    }

    public function clean_duplicated_usermeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Duplicate user meta cleanup', 'fastlayer' ), "DELETE um1 FROM {$wpdb->usermeta} um1 INNER JOIN {$wpdb->usermeta} um2 ON um1.umeta_id > um2.umeta_id AND um1.user_id = um2.user_id AND um1.meta_key = um2.meta_key AND um1.meta_value <=> um2.meta_value" );
    }

    public function clean_duplicated_termmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Duplicate term meta cleanup', 'fastlayer' ), "DELETE tm1 FROM {$wpdb->termmeta} tm1 INNER JOIN {$wpdb->termmeta} tm2 ON tm1.meta_id > tm2.meta_id AND tm1.term_id = tm2.term_id AND tm1.meta_key = tm2.meta_key AND tm1.meta_value <=> tm2.meta_value" );
    }

    public function clean_expired_transients() {
        $targets = $this->get_removable_transient_option_names( true );

        if ( empty( $targets['timeout'] ) && empty( $targets['value'] ) ) {
            $this->log_cleanup( 'Expired transients cleanup skipped. No expired transient rows found.', 'debug' );

            return array(
                'success' => true,
                'affected' => 0,
                'error' => '',
            );
        }

        $value_result   = $this->delete_options_by_name( __( 'Expired transient values cleanup', 'fastlayer' ), $targets['value'] );
        $timeout_result = $this->delete_options_by_name( __( 'Expired transients cleanup', 'fastlayer' ), $targets['timeout'] );

        $this->invalidate_transient_caches( array_merge( $targets['value'], $targets['timeout'] ) );

        if ( false === $value_result['success'] || false === $timeout_result['success'] ) {
            return array(
                'success' => false,
                'affected' => (int) $value_result['affected'] + (int) $timeout_result['affected'],
                'error' => trim( (string) $value_result['error'] . ' ' . (string) $timeout_result['error'] ),
            );
        }

        return array(
            'success' => true,
            'affected' => (int) $value_result['affected'] + (int) $timeout_result['affected'],
            'error' => '',
        );
    }

    public function clean_oembed_cache() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'oEmbed cache cleanup', 'fastlayer' ), "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_oembed\\_%'" );
    }

    public function clean_orphaned_postmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Orphaned post meta cleanup', 'fastlayer' ), "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
    }

    public function clean_orphaned_usermeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Orphaned user meta cleanup', 'fastlayer' ), "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL" );
    }

    public function clean_orphaned_termmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Orphaned term meta cleanup', 'fastlayer' ), "DELETE tm FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id WHERE t.term_id IS NULL" );
    }

    public function clean_orphaned_commentmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Orphaned comment meta cleanup', 'fastlayer' ), "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" );
    }

    private function execute_cleanup_query( $operation, $sql ) {
        global $wpdb;

        $this->log_cleanup( $operation . ' SQL: ' . $sql, 'debug' );

        $affected = $wpdb->query( $sql );
        if ( false === $affected ) {
            $error = $wpdb->last_error;
            if ( empty( $error ) ) {
                $error = sprintf( __( '%s failed with an unknown database error.', 'fastlayer' ), $operation );
            }

            $this->log_cleanup( $operation . ' failed. SQL error: ' . $error, 'error' );

            return array(
                'success' => false,
                'affected' => 0,
                'error' => $error,
            );
        }

        $this->log_cleanup( $operation . ' succeeded. Rows affected: ' . (int) $affected, 'info' );

        return array(
            'success' => true,
            'affected' => (int) $affected,
            'error' => '',
        );
    }
}
