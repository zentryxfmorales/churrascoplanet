<?php
/**
 * Database Schema and Migration System
 *
 * Handles table creation, migrations, and database operations
 * for the Client Admin Portal plugin.
 *
 * @package Client_Admin_Portal
 * @since   2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CAP_Database
 *
 * Manages database schema, migrations, and version control.
 */
class CAP_Database {

    /**
     * Current database schema version.
     */
    const SCHEMA_VERSION = '2.0.0';

    /**
     * Option name for storing schema version.
     */
    const VERSION_OPTION = 'cap_db_version';

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private wpdb $wpdb;

    /**
     * Table prefix for CAP tables.
     *
     * @var string
     */
    private string $prefix;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb   = $wpdb;
        $this->prefix = $wpdb->prefix . 'cap_';
    }

    /**
     * Get table name with prefix.
     *
     * @param string $table Base table name.
     * @return string Full table name.
     */
    public function table( string $table ): string {
        return $this->prefix . $table;
    }

    /**
     * Run migrations if needed.
     *
     * Called on plugin activation and admin_init.
     */
    public function maybe_migrate(): void {
        $current_version = get_option( self::VERSION_OPTION, '0.0.0' );

        if ( version_compare( $current_version, self::SCHEMA_VERSION, '<' ) ) {
            $this->run_migrations( $current_version );
            update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
        }
    }

    /**
     * Run all necessary migrations.
     *
     * @param string $from_version Current installed version.
     */
    private function run_migrations( string $from_version ): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Always run full schema (dbDelta handles existing tables gracefully).
        $this->create_settings_table();
        $this->create_modules_table();
        $this->create_module_access_table();
        $this->create_audit_log_table();

        // Version-specific migrations.
        if ( version_compare( $from_version, '2.0.0', '<' ) ) {
            $this->migrate_to_2_0_0();
        }
    }

    /**
     * Create settings table.
     */
    private function create_settings_table(): void {
        $table   = $this->table( 'settings' );
        $charset = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            setting_group varchar(50) NOT NULL,
            setting_key varchar(100) NOT NULL,
            setting_value longtext,
            role_slug varchar(50) DEFAULT NULL,
            autoload varchar(3) NOT NULL DEFAULT 'yes',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY group_key_role (setting_group, setting_key, role_slug),
            KEY setting_group (setting_group),
            KEY autoload (autoload)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Create modules table.
     */
    private function create_modules_table(): void {
        $table   = $this->table( 'modules' );
        $charset = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            module_slug varchar(100) NOT NULL,
            module_name varchar(200) NOT NULL,
            module_description text,
            plugin_source varchar(200) NOT NULL,
            menu_slug varchar(200) DEFAULT NULL,
            capability varchar(100) DEFAULT 'manage_options',
            icon varchar(100) DEFAULT 'dashicons-admin-generic',
            sort_order int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            is_core tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY module_slug (module_slug),
            KEY plugin_source (plugin_source),
            KEY is_active (is_active)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Create module access table.
     */
    private function create_module_access_table(): void {
        $table   = $this->table( 'module_access' );
        $charset = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            module_id bigint(20) unsigned NOT NULL,
            role_slug varchar(50) NOT NULL,
            can_view tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY module_role (module_id, role_slug),
            KEY role_slug (role_slug)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Create audit log table.
     */
    private function create_audit_log_table(): void {
        $table   = $this->table( 'audit_log' );
        $charset = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            user_login varchar(60) NOT NULL,
            action varchar(50) NOT NULL,
            target varchar(200) DEFAULT NULL,
            old_value longtext,
            new_value longtext,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Migration to version 2.0.0.
     *
     * Initial setup - insert default settings.
     */
    private function migrate_to_2_0_0(): void {
        // Insert default branding settings (global, no role).
        $defaults = $this->get_default_branding_settings();

        foreach ( $defaults as $key => $value ) {
            $this->insert_setting( 'branding', $key, $value );
        }

        // Insert default general settings.
        $this->insert_setting( 'general', 'audit_retention_days', 90 );
        $this->insert_setting( 'general', 'default_theme', 'dark_space' );
    }

    /**
     * Get default branding settings.
     *
     * @return array Default settings.
     */
    private function get_default_branding_settings(): array {
        return array(
            'theme_preset'     => 'dark_space',
            'bg_main'          => '#0a0a14',
            'bg_card'          => '#161625',
            'bg_input'         => '#222235',
            'bg_hover'         => '#1e1e30',
            'bg_header'        => '#0d0d1a',
            'text_main'        => '#e0e0e0',
            'text_muted'       => '#a0a0b0',
            'text_faint'       => '#6a6a7a',
            'accent'           => '#ff5722',
            'accent_hover'     => '#ff7043',
            'border'           => '#33334d',
            'border_light'     => '#44446a',
            'success'          => '#4caf50',
            'warning'          => '#ff9800',
            'error'            => '#f44336',
            'info'             => '#2196f3',
            'logo_url'         => '',
            'logo_height'      => '60',
            'brand_name'       => 'Client Portal',
            'custom_css'       => '',
            'admin_footer'     => '',
        );
    }

    /**
     * Insert a setting if it doesn't exist.
     *
     * @param string      $group     Setting group.
     * @param string      $key       Setting key.
     * @param mixed       $value     Setting value.
     * @param string|null $role_slug Role slug (null for global).
     */
    private function insert_setting( string $group, string $key, $value, ?string $role_slug = null ): void {
        $table = $this->table( 'settings' );

        // Check if exists.
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$table} WHERE setting_group = %s AND setting_key = %s AND (role_slug = %s OR (role_slug IS NULL AND %s IS NULL))",
                $group,
                $key,
                $role_slug,
                $role_slug
            )
        );

        if ( ! $exists ) {
            $this->wpdb->insert(
                $table,
                array(
                    'setting_group' => $group,
                    'setting_key'   => $key,
                    'setting_value' => is_array( $value ) || is_object( $value ) ? wp_json_encode( $value ) : $value,
                    'role_slug'     => $role_slug,
                ),
                array( '%s', '%s', '%s', '%s' )
            );
        }
    }

    /**
     * Drop all CAP tables.
     *
     * Used on plugin uninstall.
     */
    public function drop_tables(): void {
        $tables = array(
            $this->table( 'settings' ),
            $this->table( 'modules' ),
            $this->table( 'module_access' ),
            $this->table( 'audit_log' ),
        );

        foreach ( $tables as $table ) {
            $this->wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        delete_option( self::VERSION_OPTION );
    }

    /**
     * Clean old audit log entries.
     *
     * @param int $days Number of days to retain.
     * @return int Number of deleted rows.
     */
    public function cleanup_audit_log( int $days = 90 ): int {
        $table = $this->table( 'audit_log' );

        return (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    /**
     * Get database statistics.
     *
     * @return array Stats array.
     */
    public function get_stats(): array {
        return array(
            'settings_count'   => (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table( 'settings' )}" ),
            'modules_count'    => (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table( 'modules' )}" ),
            'audit_log_count'  => (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table( 'audit_log' )}" ),
            'schema_version'   => get_option( self::VERSION_OPTION, 'not installed' ),
        );
    }
}
