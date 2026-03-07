<?php
/**
 * Audit Log System
 *
 * Logs important actions like settings changes, module toggles,
 * and login events for security and compliance.
 *
 * @package Client_Admin_Portal
 * @since   2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CAP_Audit_Log
 *
 * Handles logging and querying of audit events.
 */
class CAP_Audit_Log {

    /**
     * Database instance.
     *
     * @var CAP_Database
     */
    private CAP_Database $db;

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private wpdb $wpdb;

    /**
     * Action types.
     */
    const ACTION_LOGIN          = 'login';
    const ACTION_LOGOUT         = 'logout';
    const ACTION_LOGIN_FAILED   = 'login_failed';
    const ACTION_SETTINGS_CHANGE = 'settings_change';
    const ACTION_MODULE_TOGGLE  = 'module_toggle';
    const ACTION_MODULE_ACCESS  = 'module_access';
    const ACTION_BRANDING_CHANGE = 'branding_change';
    const ACTION_EXPORT         = 'export';
    const ACTION_IMPORT         = 'import';

    /**
     * Singleton instance.
     *
     * @var CAP_Audit_Log|null
     */
    private static ?CAP_Audit_Log $instance = null;

    /**
     * Get singleton instance.
     *
     * @return CAP_Audit_Log
     */
    public static function instance(): CAP_Audit_Log {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->db   = new CAP_Database();
    }

    /**
     * Initialize audit hooks.
     */
    public function init(): void {
        // Login/logout hooks.
        add_action( 'wp_login', array( $this, 'log_login' ), 10, 2 );
        add_action( 'wp_logout', array( $this, 'log_logout' ) );
        add_action( 'wp_login_failed', array( $this, 'log_login_failed' ) );

        // Schedule cleanup.
        if ( ! wp_next_scheduled( 'cap_audit_log_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'cap_audit_log_cleanup' );
        }
        add_action( 'cap_audit_log_cleanup', array( $this, 'cleanup' ) );
    }

    /**
     * Log an action.
     *
     * @param string      $action    Action type.
     * @param string|null $target    Target of the action.
     * @param mixed       $old_value Previous value.
     * @param mixed       $new_value New value.
     * @param int|null    $user_id   User ID (null for current user).
     * @return int|false Insert ID or false on failure.
     */
    public function log( string $action, ?string $target = null, $old_value = null, $new_value = null, ?int $user_id = null ) {
        // Get user info.
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        $user       = get_user_by( 'id', $user_id );
        $user_login = $user ? $user->user_login : 'guest';

        // Get request info.
        $ip_address = $this->get_client_ip();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        // Encode complex values.
        $old_value_db = is_array( $old_value ) || is_object( $old_value ) ? wp_json_encode( $old_value ) : $old_value;
        $new_value_db = is_array( $new_value ) || is_object( $new_value ) ? wp_json_encode( $new_value ) : $new_value;

        // Insert log entry.
        $table  = $this->db->table( 'audit_log' );
        $result = $this->wpdb->insert(
            $table,
            array(
                'user_id'    => $user_id,
                'user_login' => $user_login,
                'action'     => $action,
                'target'     => $target,
                'old_value'  => $old_value_db,
                'new_value'  => $new_value_db,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Log settings change.
     *
     * @param string $group     Setting group.
     * @param string $key       Setting key.
     * @param mixed  $old_value Previous value.
     * @param mixed  $new_value New value.
     */
    public function log_settings_change( string $group, string $key, $old_value, $new_value ): void {
        $this->log(
            self::ACTION_SETTINGS_CHANGE,
            "{$group}/{$key}",
            $old_value,
            $new_value
        );
    }

    /**
     * Log branding change.
     *
     * @param string $key       Setting key.
     * @param mixed  $old_value Previous value.
     * @param mixed  $new_value New value.
     * @param string $role_slug Role (or 'global').
     */
    public function log_branding_change( string $key, $old_value, $new_value, string $role_slug = 'global' ): void {
        $this->log(
            self::ACTION_BRANDING_CHANGE,
            "branding/{$key} ({$role_slug})",
            $old_value,
            $new_value
        );
    }

    /**
     * Log module toggle.
     *
     * @param string $module_slug Module slug.
     * @param bool   $is_active   New active state.
     */
    public function log_module_toggle( string $module_slug, bool $is_active ): void {
        $this->log(
            self::ACTION_MODULE_TOGGLE,
            $module_slug,
            ! $is_active ? 'active' : 'inactive',
            $is_active ? 'active' : 'inactive'
        );
    }

    /**
     * Log module access change.
     *
     * @param string $module_slug Module slug.
     * @param string $role_slug   Role slug.
     * @param bool   $can_view    New access state.
     */
    public function log_module_access( string $module_slug, string $role_slug, bool $can_view ): void {
        $this->log(
            self::ACTION_MODULE_ACCESS,
            "{$module_slug}/{$role_slug}",
            $can_view ? 'denied' : 'allowed',
            $can_view ? 'allowed' : 'denied'
        );
    }

    /**
     * Log export action.
     *
     * @param string $type Export type.
     */
    public function log_export( string $type ): void {
        $this->log(
            self::ACTION_EXPORT,
            $type,
            null,
            null
        );
    }

    /**
     * Log import action.
     *
     * @param string $type  Import type.
     * @param int    $count Number of items imported.
     */
    public function log_import( string $type, int $count ): void {
        $this->log(
            self::ACTION_IMPORT,
            $type,
            null,
            "Imported {$count} items"
        );
    }

    /**
     * Log successful login.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       User object.
     */
    public function log_login( string $user_login, WP_User $user ): void {
        $this->log(
            self::ACTION_LOGIN,
            $user_login,
            null,
            null,
            $user->ID
        );
    }

    /**
     * Log logout.
     */
    public function log_logout(): void {
        $this->log(
            self::ACTION_LOGOUT,
            null,
            null,
            null
        );
    }

    /**
     * Log failed login attempt.
     *
     * @param string $username Attempted username.
     */
    public function log_login_failed( string $username ): void {
        $this->log(
            self::ACTION_LOGIN_FAILED,
            $username,
            null,
            null,
            0 // Guest
        );
    }

    /**
     * Query audit log entries.
     *
     * @param array $args Query arguments.
     * @return array Results.
     */
    public function query( array $args = array() ): array {
        $defaults = array(
            'user_id'    => null,
            'action'     => null,
            'target'     => null,
            'date_from'  => null,
            'date_to'    => null,
            'search'     => null,
            'orderby'    => 'created_at',
            'order'      => 'DESC',
            'limit'      => 50,
            'offset'     => 0,
        );

        $args  = wp_parse_args( $args, $defaults );
        $table = $this->db->table( 'audit_log' );

        // Build query.
        $where_clauses = array();
        $where_values  = array();

        if ( null !== $args['user_id'] ) {
            $where_clauses[] = 'user_id = %d';
            $where_values[]  = $args['user_id'];
        }

        if ( null !== $args['action'] ) {
            if ( is_array( $args['action'] ) ) {
                $placeholders    = implode( ',', array_fill( 0, count( $args['action'] ), '%s' ) );
                $where_clauses[] = "action IN ({$placeholders})";
                $where_values    = array_merge( $where_values, $args['action'] );
            } else {
                $where_clauses[] = 'action = %s';
                $where_values[]  = $args['action'];
            }
        }

        if ( null !== $args['target'] ) {
            $where_clauses[] = 'target LIKE %s';
            $where_values[]  = '%' . $this->wpdb->esc_like( $args['target'] ) . '%';
        }

        if ( null !== $args['date_from'] ) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[]  = $args['date_from'];
        }

        if ( null !== $args['date_to'] ) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[]  = $args['date_to'];
        }

        if ( null !== $args['search'] ) {
            $search_like     = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
            $where_clauses[] = '(user_login LIKE %s OR target LIKE %s OR old_value LIKE %s OR new_value LIKE %s)';
            $where_values[]  = $search_like;
            $where_values[]  = $search_like;
            $where_values[]  = $search_like;
            $where_values[]  = $search_like;
        }

        // Build WHERE clause.
        $where = '';
        if ( ! empty( $where_clauses ) ) {
            $where = 'WHERE ' . implode( ' AND ', $where_clauses );
        }

        // Sanitize order.
        $allowed_orderby = array( 'id', 'user_id', 'user_login', 'action', 'target', 'created_at' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

        // Build full query.
        $sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

        // Add limit and offset to values.
        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];

        // Execute query.
        if ( ! empty( $where_values ) ) {
            $results = $this->wpdb->get_results(
                $this->wpdb->prepare( $sql, $where_values ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                ARRAY_A
            );
        } else {
            $results = $this->wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        return $results ?: array();
    }

    /**
     * Get total count for query.
     *
     * @param array $args Query arguments (same as query()).
     * @return int Total count.
     */
    public function count( array $args = array() ): int {
        $defaults = array(
            'user_id'   => null,
            'action'    => null,
            'target'    => null,
            'date_from' => null,
            'date_to'   => null,
            'search'    => null,
        );

        $args  = wp_parse_args( $args, $defaults );
        $table = $this->db->table( 'audit_log' );

        // Build query (same logic as query()).
        $where_clauses = array();
        $where_values  = array();

        if ( null !== $args['user_id'] ) {
            $where_clauses[] = 'user_id = %d';
            $where_values[]  = $args['user_id'];
        }

        if ( null !== $args['action'] ) {
            if ( is_array( $args['action'] ) ) {
                $placeholders    = implode( ',', array_fill( 0, count( $args['action'] ), '%s' ) );
                $where_clauses[] = "action IN ({$placeholders})";
                $where_values    = array_merge( $where_values, $args['action'] );
            } else {
                $where_clauses[] = 'action = %s';
                $where_values[]  = $args['action'];
            }
        }

        if ( null !== $args['target'] ) {
            $where_clauses[] = 'target LIKE %s';
            $where_values[]  = '%' . $this->wpdb->esc_like( $args['target'] ) . '%';
        }

        if ( null !== $args['date_from'] ) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[]  = $args['date_from'];
        }

        if ( null !== $args['date_to'] ) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[]  = $args['date_to'];
        }

        if ( null !== $args['search'] ) {
            $search_like     = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
            $where_clauses[] = '(user_login LIKE %s OR target LIKE %s OR old_value LIKE %s OR new_value LIKE %s)';
            $where_values[]  = $search_like;
            $where_values[]  = $search_like;
            $where_values[]  = $search_like;
            $where_values[]  = $search_like;
        }

        $where = '';
        if ( ! empty( $where_clauses ) ) {
            $where = 'WHERE ' . implode( ' AND ', $where_clauses );
        }

        $sql = "SELECT COUNT(*) FROM {$table} {$where}";

        if ( ! empty( $where_values ) ) {
            return (int) $this->wpdb->get_var(
                $this->wpdb->prepare( $sql, $where_values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            );
        }

        return (int) $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Get available action types.
     *
     * @return array Action type => label.
     */
    public function get_action_types(): array {
        return array(
            self::ACTION_LOGIN          => __( 'Login', 'client-admin-portal' ),
            self::ACTION_LOGOUT         => __( 'Logout', 'client-admin-portal' ),
            self::ACTION_LOGIN_FAILED   => __( 'Failed Login', 'client-admin-portal' ),
            self::ACTION_SETTINGS_CHANGE => __( 'Settings Change', 'client-admin-portal' ),
            self::ACTION_MODULE_TOGGLE  => __( 'Module Toggle', 'client-admin-portal' ),
            self::ACTION_MODULE_ACCESS  => __( 'Module Access Change', 'client-admin-portal' ),
            self::ACTION_BRANDING_CHANGE => __( 'Branding Change', 'client-admin-portal' ),
            self::ACTION_EXPORT         => __( 'Export', 'client-admin-portal' ),
            self::ACTION_IMPORT         => __( 'Import', 'client-admin-portal' ),
        );
    }

    /**
     * Export log entries as CSV.
     *
     * @param array $args Query arguments.
     * @return string CSV content.
     */
    public function export_csv( array $args = array() ): string {
        // Remove limit for export.
        $args['limit']  = 99999;
        $args['offset'] = 0;

        $entries = $this->query( $args );

        // Build CSV.
        $output = fopen( 'php://temp', 'r+' );

        // Headers.
        fputcsv( $output, array(
            'ID',
            'Date',
            'User ID',
            'Username',
            'Action',
            'Target',
            'Old Value',
            'New Value',
            'IP Address',
            'User Agent',
        ) );

        // Data rows.
        foreach ( $entries as $entry ) {
            fputcsv( $output, array(
                $entry['id'],
                $entry['created_at'],
                $entry['user_id'],
                $entry['user_login'],
                $entry['action'],
                $entry['target'],
                $entry['old_value'],
                $entry['new_value'],
                $entry['ip_address'],
                $entry['user_agent'],
            ) );
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        return $csv;
    }

    /**
     * Cleanup old log entries.
     *
     * @param int|null $days Days to retain (null uses setting).
     * @return int Number of deleted entries.
     */
    public function cleanup( ?int $days = null ): int {
        if ( null === $days ) {
            $days = (int) cap_settings()->get( 'general', 'audit_retention_days', 90 );
        }

        return $this->db->cleanup_audit_log( $days );
    }

    /**
     * Get client IP address.
     *
     * @return string IP address.
     */
    private function get_client_ip(): string {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        );

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

                // Handle comma-separated IPs (X-Forwarded-For).
                if ( strpos( $ip, ',' ) !== false ) {
                    $ips = explode( ',', $ip );
                    $ip  = trim( $ips[0] );
                }

                // Validate IP.
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserialization.
     */
    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize singleton.' );
    }
}

/**
 * Get audit log instance.
 *
 * @return CAP_Audit_Log
 */
function cap_audit(): CAP_Audit_Log {
    return CAP_Audit_Log::instance();
}
