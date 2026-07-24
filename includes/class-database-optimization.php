<?php
namespace WP_FastLayer;
if ( ! defined( 'ABSPATH' ) ) exit;

class Database_Optimization {
    private static $instance = null;
    private $notice_transient_prefix = 'wp_fastlayer_db_cleanup_notice_';

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_post_wp_fastlayer_optimize_database', array( $this, 'optimize_database' ) );
    }

    public function optimize_database() {
        check_admin_referer( 'wp_fastlayer_optimize_database_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }

        $this->log_execution_context();

        $selected_cleanup = $this->get_selected_cleanup_items_from_request();
        $result = $this->run_optimization( $selected_cleanup );

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

    private function run_optimization( $selected_cleanup = array() ) {
        global $wpdb;
        $options = get_option( 'wp_fastlayer_options', array() );

        $result = array(
            'success' => true,
            'stats'   => array(),
            'errors'  => array(),
            'rows_removed' => 0,
            'diagnostics' => array(),
        );

        $actions = array(
            'db_clean_post_revisions' => array(
                'option_key' => 'db_clean_post_revisions',
                'label' => __( 'Post revisions removed', 'wp-fastlayer' ),
                'callback' => 'clean_post_revisions',
                'count_callback' => 'get_post_revisions_count',
            ),
            'db_clean_trashed_posts' => array(
                'option_key' => 'db_clean_trashed_posts',
                'label' => __( 'Trashed posts removed', 'wp-fastlayer' ),
                'callback' => 'clean_trashed_posts',
                'count_callback' => 'get_trashed_posts_count',
            ),
            'db_clean_spam_comments' => array(
                'option_key' => 'db_clean_spam_comments',
                'label' => __( 'Spam comments removed', 'wp-fastlayer' ),
                'callback' => 'clean_spam_comments',
                'count_callback' => 'get_spam_comments_count',
            ),
            'db_clean_auto_drafts' => array(
                'option_key' => 'db_clean_auto_drafts',
                'label' => __( 'Auto-drafts removed', 'wp-fastlayer' ),
                'callback' => 'clean_auto_drafts',
                'count_callback' => 'get_auto_drafts_count',
            ),
            'db_clean_all_transients' => array(
                'option_key' => 'db_clean_all_transients',
                'label' => __( 'All transients removed', 'wp-fastlayer' ),
                'callback' => 'clean_all_transients',
                'count_callback' => 'get_all_transients_count',
            ),
            'db_clean_expired_transients' => array(
                'option_key' => 'db_clean_expired_transients',
                'label' => __( 'Expired transients removed', 'wp-fastlayer' ),
                'callback' => 'clean_expired_transients',
                'count_callback' => 'get_expired_transients_count',
            ),
            'db_clean_duplicated_postmeta' => array(
                'option_key' => 'db_clean_duplicated_postmeta',
                'label' => __( 'Duplicate post meta rows removed', 'wp-fastlayer' ),
                'callback' => 'clean_duplicated_postmeta',
                'count_callback' => 'get_duplicated_postmeta_count',
            ),
            'db_clean_duplicated_commentmeta' => array(
                'option_key' => 'db_clean_duplicated_commentmeta',
                'label' => __( 'Duplicate comment meta rows removed', 'wp-fastlayer' ),
                'callback' => 'clean_duplicated_commentmeta',
                'count_callback' => 'get_duplicated_commentmeta_count',
            ),
            'db_clean_duplicated_usermeta' => array(
                'option_key' => 'db_clean_duplicated_usermeta',
                'label' => __( 'Duplicate user meta rows removed', 'wp-fastlayer' ),
                'callback' => 'clean_duplicated_usermeta',
                'count_callback' => 'get_duplicated_usermeta_count',
            ),
            'db_clean_duplicated_termmeta' => array(
                'option_key' => 'db_clean_duplicated_termmeta',
                'label' => __( 'Duplicate term meta rows removed', 'wp-fastlayer' ),
                'callback' => 'clean_duplicated_termmeta',
                'count_callback' => 'get_duplicated_termmeta_count',
            ),
            'db_clean_oembed_cache' => array(
                'option_key' => 'db_clean_oembed_cache',
                'label' => __( 'oEmbed cache rows removed', 'wp-fastlayer' ),
                'callback' => 'clean_oembed_cache',
                'count_callback' => 'get_oembed_cache_count',
            ),
            'db_clean_orphaned_postmeta' => array(
                'option_key' => 'db_clean_orphaned_postmeta',
                'label' => __( 'Orphaned post meta rows removed', 'wp-fastlayer' ),
                'callback' => 'clean_orphaned_postmeta',
                'count_callback' => 'get_orphaned_postmeta_count',
            ),
            'db_clean_orphaned_usermeta' => array(
                'option_key' => 'db_clean_orphaned_usermeta',
                'label' => __( 'Orphaned user meta rows removed', 'wp-fastlayer' ),
                'callback' => 'clean_orphaned_usermeta',
                'count_callback' => 'get_orphaned_usermeta_count',
            ),
            'db_clean_orphaned_termmeta' => array(
                'option_key' => 'db_clean_orphaned_termmeta',
                'label' => __( 'Orphaned term meta rows removed', 'wp-fastlayer' ),
                'callback' => 'clean_orphaned_termmeta',
                'count_callback' => 'get_orphaned_termmeta_count',
            ),
            'db_clean_orphaned_commentmeta' => array(
                'option_key' => 'db_clean_orphaned_commentmeta',
                'label' => __( 'Orphaned comment meta rows removed', 'wp-fastlayer' ),
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
                $result['errors'][] = sprintf( __( 'Cleanup callback not found for %s.', 'wp-fastlayer' ), $action_key );
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
                    $result['errors'][] = sprintf( __( 'Cleanup failed for %s.', 'wp-fastlayer' ), $config['label'] );
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
                    $result['errors'][] = sprintf( __( '%1$s did not remove any rows. Records before cleanup: %2$d.', 'wp-fastlayer' ), $config['label'], $before_count );
                    $diagnostic['notes'][] = sprintf( __( 'Validation failed. Records before cleanup: %d, after cleanup: %d.', 'wp-fastlayer' ), $before_count, $after_count );
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
            $this->log_cleanup( $config['label'] . ' executed. Rows affected: ' . $affected_rows );
        }

        $optimization_result = $this->optimize_all_tables();
        $result['stats'][] = array(
            'label' => __( 'Database tables optimized', 'wp-fastlayer' ),
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
            $result['errors'][] = __( 'No cleanup item was selected. Enable at least one database cleanup action.', 'wp-fastlayer' );
            $result['success'] = false;
            $this->log_cleanup( 'Database cleanup finished with no selected cleanup items.', 'warning' );
        } elseif ( true === $result['success'] && 0 === (int) $result['rows_removed'] ) {
            $result['success'] = false;
            $result['errors'][] = __( 'No matching records were found for the selected cleanup items. Nothing was removed.', 'wp-fastlayer' );
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
                $errors[] = sprintf( __( 'Failed to optimize table %s: %s', 'wp-fastlayer' ), $table_name, $wpdb->last_error );
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
        if ( empty( $_POST['cleanup_items'] ) || ! is_array( $_POST['cleanup_items'] ) ) {
            return array();
        }

        $selected = array();
        $raw = wp_unslash( $_POST['cleanup_items'] );
        foreach ( $raw as $key => $value ) {
            $key = sanitize_key( $key );
            $selected[ $key ] = '1' === (string) $value;
        }

        return $selected;
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
        $removable_candidates = max( 0, $expired_timeout_rows + $orphan_timeout_rows );

        return array(
            'removable_candidates' => $removable_candidates,
            'timeout_rows' => $timeout_rows,
            'value_rows' => $value_rows,
            'expired_timeout_rows' => $expired_timeout_rows,
            'active_timeout_rows' => $active_timeout_rows,
            'orphan_timeout_rows' => $orphan_timeout_rows,
            'runtime_no_timeout_rows' => $runtime_no_timeout_rows,
            'active_runtime_rows' => $active_runtime_rows,
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
        return $this->execute_cleanup_query( __( 'Auto drafts cleanup', 'wp-fastlayer' ), "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
    }

    public function clean_post_revisions() {
        global $wpdb;
        $operation = __( 'Post revisions cleanup', 'wp-fastlayer' );
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
        $operation = __( 'Trashed posts cleanup', 'wp-fastlayer' );
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
        return $this->execute_cleanup_query( __( 'Spam comments cleanup', 'wp-fastlayer' ), "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
    }

    public function clean_all_transients() {
        global $wpdb;
        $first = $this->execute_cleanup_query( __( 'Transients cleanup', 'wp-fastlayer' ), "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'" );
        $second = $this->execute_cleanup_query( __( 'Site transients cleanup', 'wp-fastlayer' ), "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_%'" );

        if ( false === $first['success'] || false === $second['success'] ) {
            return array(
                'success' => false,
                'affected' => (int) $first['affected'] + (int) $second['affected'],
                'error' => trim( (string) $first['error'] . ' ' . (string) $second['error'] ),
            );
        }

        return array(
            'success' => true,
            'affected' => (int) $first['affected'] + (int) $second['affected'],
            'error' => '',
        );
    }

    public function clean_duplicated_postmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Duplicate post meta cleanup', 'wp-fastlayer' ), "DELETE pm1 FROM {$wpdb->postmeta} pm1 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.meta_id > pm2.meta_id AND pm1.post_id = pm2.post_id AND pm1.meta_key = pm2.meta_key AND pm1.meta_value <=> pm2.meta_value" );
    }

    public function clean_duplicated_commentmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Duplicate comment meta cleanup', 'wp-fastlayer' ), "DELETE cm1 FROM {$wpdb->commentmeta} cm1 INNER JOIN {$wpdb->commentmeta} cm2 ON cm1.meta_id > cm2.meta_id AND cm1.comment_id = cm2.comment_id AND cm1.meta_key = cm2.meta_key AND cm1.meta_value <=> cm2.meta_value" );
    }

    public function clean_duplicated_usermeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Duplicate user meta cleanup', 'wp-fastlayer' ), "DELETE um1 FROM {$wpdb->usermeta} um1 INNER JOIN {$wpdb->usermeta} um2 ON um1.umeta_id > um2.umeta_id AND um1.user_id = um2.user_id AND um1.meta_key = um2.meta_key AND um1.meta_value <=> um2.meta_value" );
    }

    public function clean_duplicated_termmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Duplicate term meta cleanup', 'wp-fastlayer' ), "DELETE tm1 FROM {$wpdb->termmeta} tm1 INNER JOIN {$wpdb->termmeta} tm2 ON tm1.meta_id > tm2.meta_id AND tm1.term_id = tm2.term_id AND tm1.meta_key = tm2.meta_key AND tm1.meta_value <=> tm2.meta_value" );
    }

    public function clean_expired_transients() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Expired transients cleanup', 'wp-fastlayer' ), "DELETE o, v FROM {$wpdb->options} o LEFT JOIN {$wpdb->options} v ON v.option_name = CONCAT( IF( o.option_name LIKE '\\_site\\_transient\\_timeout\\_%', '_site_transient_', '_transient_' ), SUBSTRING_INDEX( o.option_name, '_timeout_', -1 ) ) WHERE (o.option_name LIKE '\\_transient\\_timeout\\_%' OR o.option_name LIKE '\\_site\\_transient\\_timeout\\_%') AND CAST(o.option_value AS UNSIGNED) < UNIX_TIMESTAMP()" );
    }

    public function clean_oembed_cache() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'oEmbed cache cleanup', 'wp-fastlayer' ), "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_oembed\\_%'" );
    }

    public function clean_orphaned_postmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Orphaned post meta cleanup', 'wp-fastlayer' ), "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
    }

    public function clean_orphaned_usermeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Orphaned user meta cleanup', 'wp-fastlayer' ), "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL" );
    }

    public function clean_orphaned_termmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Orphaned term meta cleanup', 'wp-fastlayer' ), "DELETE tm FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id WHERE t.term_id IS NULL" );
    }

    public function clean_orphaned_commentmeta() {
        global $wpdb;
        return $this->execute_cleanup_query( __( 'Orphaned comment meta cleanup', 'wp-fastlayer' ), "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL" );
    }

    private function execute_cleanup_query( $operation, $sql ) {
        global $wpdb;

        $this->log_cleanup( $operation . ' SQL: ' . $sql, 'debug' );

        $affected = $wpdb->query( $sql );
        if ( false === $affected ) {
            $error = $wpdb->last_error;
            if ( empty( $error ) ) {
                $error = sprintf( __( '%s failed with an unknown database error.', 'wp-fastlayer' ), $operation );
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
