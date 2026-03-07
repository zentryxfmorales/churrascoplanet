<?php
/**
 * Settings Management Class
 *
 * Handles CRUD operations for plugin settings with caching,
 * type safety, and role-based configuration support.
 *
 * @package Client_Admin_Portal
 * @since   2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CAP_Settings
 *
 * Manages all plugin settings with database persistence.
 */
class CAP_Settings {

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
     * Settings cache.
     *
     * @var array
     */
    private array $cache = array();

    /**
     * Cache group for WordPress object cache.
     */
    const CACHE_GROUP = 'cap_settings';

    /**
     * Cache expiration in seconds.
     */
    const CACHE_EXPIRATION = 3600;

    /**
     * Singleton instance.
     *
     * @var CAP_Settings|null
     */
    private static ?CAP_Settings $instance = null;

    /**
     * Get singleton instance.
     *
     * @return CAP_Settings
     */
    public static function instance(): CAP_Settings {
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
     * Get a setting value.
     *
     * @param string      $group     Setting group.
     * @param string      $key       Setting key.
     * @param mixed       $default   Default value if not found.
     * @param string|null $role_slug Role slug (null for global, 'current' for current user's role).
     * @return mixed Setting value.
     */
    public function get( string $group, string $key, $default = null, ?string $role_slug = null ) {
        // Handle 'current' role.
        if ( 'current' === $role_slug ) {
            $role_slug = $this->get_current_user_role();
        }

        // Try role-specific first, then fall back to global.
        if ( null !== $role_slug ) {
            $value = $this->get_from_db( $group, $key, $role_slug );
            if ( null !== $value ) {
                return $this->maybe_decode_json( $value );
            }
        }

        // Get global setting.
        $value = $this->get_from_db( $group, $key, null );

        if ( null !== $value ) {
            return $this->maybe_decode_json( $value );
        }

        return $default;
    }

    /**
     * Get setting from database with caching.
     *
     * @param string      $group     Setting group.
     * @param string      $key       Setting key.
     * @param string|null $role_slug Role slug.
     * @return string|null Setting value or null.
     */
    private function get_from_db( string $group, string $key, ?string $role_slug ): ?string {
        $cache_key = $this->build_cache_key( $group, $key, $role_slug );

        // Check memory cache.
        if ( isset( $this->cache[ $cache_key ] ) ) {
            return $this->cache[ $cache_key ];
        }

        // Check object cache.
        $cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( false !== $cached ) {
            $this->cache[ $cache_key ] = $cached;
            return $cached;
        }

        // Query database.
        $table = $this->db->table( 'settings' );

        if ( null === $role_slug ) {
            $value = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT setting_value FROM {$table} WHERE setting_group = %s AND setting_key = %s AND role_slug IS NULL",
                    $group,
                    $key
                )
            );
        } else {
            $value = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT setting_value FROM {$table} WHERE setting_group = %s AND setting_key = %s AND role_slug = %s",
                    $group,
                    $key,
                    $role_slug
                )
            );
        }

        // Cache the result (even if null, we cache as empty string to prevent repeated queries).
        $cache_value = $value ?? '';
        $this->cache[ $cache_key ] = $cache_value;
        wp_cache_set( $cache_key, $cache_value, self::CACHE_GROUP, self::CACHE_EXPIRATION );

        return $value;
    }

    /**
     * Set a setting value.
     *
     * @param string      $group     Setting group.
     * @param string      $key       Setting key.
     * @param mixed       $value     Setting value.
     * @param string|null $role_slug Role slug (null for global).
     * @param bool        $autoload  Whether to autoload.
     * @return bool Success.
     */
    public function set( string $group, string $key, $value, ?string $role_slug = null, bool $autoload = true ): bool {
        $table     = $this->db->table( 'settings' );
        $cache_key = $this->build_cache_key( $group, $key, $role_slug );

        // Encode arrays/objects to JSON.
        $db_value = is_array( $value ) || is_object( $value ) ? wp_json_encode( $value ) : $value;

        // Check if exists.
        if ( null === $role_slug ) {
            $existing_id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$table} WHERE setting_group = %s AND setting_key = %s AND role_slug IS NULL",
                    $group,
                    $key
                )
            );
        } else {
            $existing_id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$table} WHERE setting_group = %s AND setting_key = %s AND role_slug = %s",
                    $group,
                    $key,
                    $role_slug
                )
            );
        }

        if ( $existing_id ) {
            // Update existing.
            $result = $this->wpdb->update(
                $table,
                array(
                    'setting_value' => $db_value,
                    'autoload'      => $autoload ? 'yes' : 'no',
                ),
                array( 'id' => $existing_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        } else {
            // Insert new.
            $result = $this->wpdb->insert(
                $table,
                array(
                    'setting_group' => $group,
                    'setting_key'   => $key,
                    'setting_value' => $db_value,
                    'role_slug'     => $role_slug,
                    'autoload'      => $autoload ? 'yes' : 'no',
                ),
                array( '%s', '%s', '%s', '%s', '%s' )
            );
        }

        // Clear cache.
        unset( $this->cache[ $cache_key ] );
        wp_cache_delete( $cache_key, self::CACHE_GROUP );

        return false !== $result;
    }

    /**
     * Delete a setting.
     *
     * @param string      $group     Setting group.
     * @param string      $key       Setting key.
     * @param string|null $role_slug Role slug (null for global).
     * @return bool Success.
     */
    public function delete( string $group, string $key, ?string $role_slug = null ): bool {
        $table     = $this->db->table( 'settings' );
        $cache_key = $this->build_cache_key( $group, $key, $role_slug );

        if ( null === $role_slug ) {
            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$table} WHERE setting_group = %s AND setting_key = %s AND role_slug IS NULL",
                    $group,
                    $key
                )
            );
        } else {
            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$table} WHERE setting_group = %s AND setting_key = %s AND role_slug = %s",
                    $group,
                    $key,
                    $role_slug
                )
            );
        }

        // Clear cache.
        unset( $this->cache[ $cache_key ] );
        wp_cache_delete( $cache_key, self::CACHE_GROUP );

        return $result > 0;
    }

    /**
     * Get all settings in a group.
     *
     * @param string      $group     Setting group.
     * @param string|null $role_slug Role slug (null for global).
     * @return array Settings as key => value.
     */
    public function get_group( string $group, ?string $role_slug = null ): array {
        $table = $this->db->table( 'settings' );

        if ( null === $role_slug ) {
            $results = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT setting_key, setting_value FROM {$table} WHERE setting_group = %s AND role_slug IS NULL",
                    $group
                ),
                ARRAY_A
            );
        } else {
            // Get role-specific settings merged with global defaults.
            $global_results = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT setting_key, setting_value FROM {$table} WHERE setting_group = %s AND role_slug IS NULL",
                    $group
                ),
                ARRAY_A
            );

            $role_results = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT setting_key, setting_value FROM {$table} WHERE setting_group = %s AND role_slug = %s",
                    $group,
                    $role_slug
                ),
                ARRAY_A
            );

            // Merge: role-specific overrides global.
            $global_settings = array();
            foreach ( $global_results as $row ) {
                $global_settings[ $row['setting_key'] ] = $row['setting_value'];
            }

            foreach ( $role_results as $row ) {
                $global_settings[ $row['setting_key'] ] = $row['setting_value'];
            }

            $settings = array();
            foreach ( $global_settings as $key => $value ) {
                $settings[ $key ] = $this->maybe_decode_json( $value );
            }

            return $settings;
        }

        $settings = array();
        foreach ( $results as $row ) {
            $settings[ $row['setting_key'] ] = $this->maybe_decode_json( $row['setting_value'] );
        }

        return $settings;
    }

    /**
     * Set multiple settings at once.
     *
     * @param string      $group     Setting group.
     * @param array       $settings  Key => value pairs.
     * @param string|null $role_slug Role slug.
     * @return bool Success.
     */
    public function set_group( string $group, array $settings, ?string $role_slug = null ): bool {
        $success = true;

        foreach ( $settings as $key => $value ) {
            if ( ! $this->set( $group, $key, $value, $role_slug ) ) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Get all roles that have custom settings for a group.
     *
     * @param string $group Setting group.
     * @return array Role slugs.
     */
    public function get_roles_with_settings( string $group ): array {
        $table = $this->db->table( 'settings' );

        $roles = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT DISTINCT role_slug FROM {$table} WHERE setting_group = %s AND role_slug IS NOT NULL",
                $group
            )
        );

        return array_filter( $roles );
    }

    /**
     * Export all settings as array.
     *
     * @return array All settings.
     */
    public function export_all(): array {
        $table   = $this->db->table( 'settings' );
        $results = $this->wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

        $export = array();
        foreach ( $results as $row ) {
            $group    = $row['setting_group'];
            $role     = $row['role_slug'] ?? '_global';
            $key      = $row['setting_key'];

            if ( ! isset( $export[ $group ] ) ) {
                $export[ $group ] = array();
            }
            if ( ! isset( $export[ $group ][ $role ] ) ) {
                $export[ $group ][ $role ] = array();
            }

            $export[ $group ][ $role ][ $key ] = $this->maybe_decode_json( $row['setting_value'] );
        }

        return $export;
    }

    /**
     * Import settings from array.
     *
     * @param array $data    Settings data.
     * @param bool  $replace Whether to replace existing settings.
     * @return int Number of settings imported.
     */
    public function import_all( array $data, bool $replace = false ): int {
        $count = 0;

        foreach ( $data as $group => $roles ) {
            foreach ( $roles as $role => $settings ) {
                $role_slug = '_global' === $role ? null : $role;

                foreach ( $settings as $key => $value ) {
                    // Check if exists and skip if not replacing.
                    if ( ! $replace ) {
                        $existing = $this->get( $group, $key, null, $role_slug );
                        if ( null !== $existing ) {
                            continue;
                        }
                    }

                    if ( $this->set( $group, $key, $value, $role_slug ) ) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Clear all caches.
     */
    public function clear_cache(): void {
        $this->cache = array();
        wp_cache_flush_group( self::CACHE_GROUP );
    }

    /**
     * Build cache key.
     *
     * @param string      $group     Setting group.
     * @param string      $key       Setting key.
     * @param string|null $role_slug Role slug.
     * @return string Cache key.
     */
    private function build_cache_key( string $group, string $key, ?string $role_slug ): string {
        return sprintf( '%s:%s:%s', $group, $key, $role_slug ?? '_global' );
    }

    /**
     * Maybe decode JSON value.
     *
     * @param string $value Value to decode.
     * @return mixed Decoded value or original string.
     */
    private function maybe_decode_json( $value ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        // Check if it looks like JSON.
        $first_char = substr( $value, 0, 1 );
        if ( '{' === $first_char || '[' === $first_char ) {
            $decoded = json_decode( $value, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * Get current user's primary role.
     *
     * @return string|null Role slug or null.
     */
    private function get_current_user_role(): ?string {
        $user = wp_get_current_user();

        if ( ! $user->exists() ) {
            return null;
        }

        $roles = $user->roles;

        return ! empty( $roles ) ? reset( $roles ) : null;
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
 * Get settings instance.
 *
 * @return CAP_Settings
 */
function cap_settings(): CAP_Settings {
    return CAP_Settings::instance();
}
