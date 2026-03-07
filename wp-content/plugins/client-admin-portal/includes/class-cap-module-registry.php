<?php
/**
 * Module Registry System
 *
 * Allows plugins to auto-register their features/modules
 * that can be managed through the Client Admin Portal.
 *
 * @package Client_Admin_Portal
 * @since   2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CAP_Module_Registry
 *
 * Manages module registration, discovery, and state.
 */
class CAP_Module_Registry {

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
     * Registered modules (runtime).
     *
     * @var array
     */
    private array $registered_modules = array();

    /**
     * Singleton instance.
     *
     * @var CAP_Module_Registry|null
     */
    private static ?CAP_Module_Registry $instance = null;

    /**
     * Get singleton instance.
     *
     * @return CAP_Module_Registry
     */
    public static function instance(): CAP_Module_Registry {
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
     * Initialize the registry.
     *
     * Call this after plugins_loaded to allow all plugins to register.
     */
    public function init(): void {
        // Fire action for plugins to register their modules.
        do_action( 'cap_register_modules', $this );

        // Sync registered modules with database.
        $this->sync_modules();

        // Note: We no longer auto-cleanup orphaned modules on every page load.
        // Modules added via UI should persist regardless of code registration.
        // Cleanup can be triggered manually if needed via cleanup_orphaned_modules().
    }

    /**
     * Register a module.
     *
     * @param array $module Module configuration.
     * @return bool Success.
     */
    public function register( array $module ): bool {
        // Validate required fields.
        $required = array( 'slug', 'name', 'plugin_source' );
        foreach ( $required as $field ) {
            if ( empty( $module[ $field ] ) ) {
                return false;
            }
        }

        // Set defaults.
        $module = wp_parse_args( $module, array(
            'slug'        => '',
            'name'        => '',
            'description' => '',
            'plugin_source' => '',
            'menu_slug'   => '',
            'capability'  => 'manage_options',
            'icon'        => 'dashicons-admin-generic',
            'sort_order'  => 10,
            'is_core'     => false,
        ) );

        // Sanitize.
        $module['slug']        = sanitize_key( $module['slug'] );
        $module['name']        = sanitize_text_field( $module['name'] );
        $module['description'] = sanitize_textarea_field( $module['description'] );
        $module['plugin_source'] = sanitize_text_field( $module['plugin_source'] );
        $module['menu_slug']   = sanitize_text_field( $module['menu_slug'] );
        $module['capability']  = sanitize_key( $module['capability'] );
        $module['icon']        = sanitize_text_field( $module['icon'] );
        $module['sort_order']  = absint( $module['sort_order'] );
        $module['is_core']     = (bool) $module['is_core'];

        // Store in runtime registry.
        $this->registered_modules[ $module['slug'] ] = $module;

        return true;
    }

    /**
     * Register multiple modules at once.
     *
     * @param array $modules Array of module configurations.
     * @return int Number of successfully registered modules.
     */
    public function register_many( array $modules ): int {
        $count = 0;
        foreach ( $modules as $module ) {
            if ( $this->register( $module ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Sync runtime modules with database.
     */
    private function sync_modules(): void {
        $table = $this->db->table( 'modules' );

        foreach ( $this->registered_modules as $slug => $module ) {
            // Check if exists in database.
            $existing = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT id, is_active FROM {$table} WHERE module_slug = %s",
                    $slug
                ),
                ARRAY_A
            );

            if ( $existing ) {
                // Update existing (but preserve is_active state).
                $this->wpdb->update(
                    $table,
                    array(
                        'module_name'        => $module['name'],
                        'module_description' => $module['description'],
                        'plugin_source'      => $module['plugin_source'],
                        'menu_slug'          => $module['menu_slug'],
                        'capability'         => $module['capability'],
                        'icon'               => $module['icon'],
                        'is_core'            => $module['is_core'] ? 1 : 0,
                    ),
                    array( 'id' => $existing['id'] ),
                    array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' ),
                    array( '%d' )
                );
            } else {
                // Insert new module (active by default).
                $this->wpdb->insert(
                    $table,
                    array(
                        'module_slug'        => $slug,
                        'module_name'        => $module['name'],
                        'module_description' => $module['description'],
                        'plugin_source'      => $module['plugin_source'],
                        'menu_slug'          => $module['menu_slug'],
                        'capability'         => $module['capability'],
                        'icon'               => $module['icon'],
                        'sort_order'         => $module['sort_order'],
                        'is_active'          => 1,
                        'is_core'            => $module['is_core'] ? 1 : 0,
                    ),
                    array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
                );

                // Set default access for all roles.
                $this->set_default_access( $this->wpdb->insert_id, $module['capability'] );
            }
        }
    }

    /**
     * Set default access for a new module.
     *
     * @param int    $module_id   Module ID.
     * @param string $capability  Required capability.
     */
    private function set_default_access( int $module_id, string $capability ): void {
        $table = $this->db->table( 'module_access' );
        $roles = wp_roles()->roles;

        foreach ( $roles as $role_slug => $role_data ) {
            // Check if role has the required capability.
            $has_capability = isset( $role_data['capabilities'][ $capability ] ) && $role_data['capabilities'][ $capability ];

            // Administrators always have access.
            if ( 'administrator' === $role_slug ) {
                $has_capability = true;
            }

            $this->wpdb->insert(
                $table,
                array(
                    'module_id' => $module_id,
                    'role_slug' => $role_slug,
                    'can_view'  => $has_capability ? 1 : 0,
                ),
                array( '%d', '%s', '%d' )
            );
        }
    }

    /**
     * Cleanup modules from plugins that are no longer active.
     *
     * IMPORTANT: This method is NOT called automatically anymore.
     * It should only be called manually when explicitly needed (e.g., plugin cleanup tools).
     *
     * Modules added via the admin UI are never affected by this cleanup.
     *
     * @param bool $only_from_deactivated_plugins If true, only cleanup modules from plugins
     *                                             that are currently deactivated.
     * @return int Number of modules marked as inactive.
     */
    public function cleanup_orphaned_modules( bool $only_from_deactivated_plugins = false ): int {
        $table = $this->db->table( 'modules' );
        $count = 0;

        // Get modules that were registered via code (not manual/UI modules).
        $db_modules = $this->wpdb->get_results(
            "SELECT id, module_slug, plugin_source FROM {$table} WHERE module_slug NOT LIKE 'manual_%'",
            ARRAY_A
        );

        foreach ( $db_modules as $db_module ) {
            // Check if the module is still registered in code.
            if ( ! isset( $this->registered_modules[ $db_module['module_slug'] ] ) ) {
                // Mark as inactive (don't delete, in case plugin is reactivated).
                $result = $this->wpdb->update(
                    $table,
                    array( 'is_active' => 0 ),
                    array( 'id' => $db_module['id'] ),
                    array( '%d' ),
                    array( '%d' )
                );

                if ( false !== $result ) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Get all modules.
     *
     * @param bool $active_only Only return active modules.
     * @return array Modules.
     */
    public function get_all( bool $active_only = false ): array {
        $table = $this->db->table( 'modules' );

        $where = $active_only ? 'WHERE is_active = 1' : '';

        $modules = $this->wpdb->get_results(
            "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, module_name ASC",
            ARRAY_A
        );

        return $modules ?: array();
    }

    /**
     * Get a single module by slug.
     *
     * @param string $slug Module slug.
     * @return array|null Module data or null.
     */
    public function get( string $slug ): ?array {
        $table = $this->db->table( 'modules' );

        $module = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE module_slug = %s",
                $slug
            ),
            ARRAY_A
        );

        return $module ?: null;
    }

    /**
     * Get modules accessible by a role.
     *
     * @param string $role_slug Role slug.
     * @return array Accessible modules.
     */
    public function get_for_role( string $role_slug ): array {
        $modules_table = $this->db->table( 'modules' );
        $access_table  = $this->db->table( 'module_access' );

        $modules = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT m.* FROM {$modules_table} m
                 INNER JOIN {$access_table} a ON m.id = a.module_id
                 WHERE m.is_active = 1 AND a.role_slug = %s AND a.can_view = 1
                 ORDER BY m.sort_order ASC, m.module_name ASC",
                $role_slug
            ),
            ARRAY_A
        );

        return $modules ?: array();
    }

    /**
     * Get module access matrix.
     *
     * @return array Matrix of module_slug => role_slug => can_view.
     */
    public function get_access_matrix(): array {
        $modules_table = $this->db->table( 'modules' );
        $access_table  = $this->db->table( 'module_access' );

        $results = $this->wpdb->get_results(
            "SELECT m.module_slug, a.role_slug, a.can_view
             FROM {$access_table} a
             INNER JOIN {$modules_table} m ON a.module_id = m.id
             ORDER BY m.module_slug, a.role_slug",
            ARRAY_A
        );

        $matrix = array();
        foreach ( $results as $row ) {
            if ( ! isset( $matrix[ $row['module_slug'] ] ) ) {
                $matrix[ $row['module_slug'] ] = array();
            }
            $matrix[ $row['module_slug'] ][ $row['role_slug'] ] = (bool) $row['can_view'];
        }

        return $matrix;
    }

    /**
     * Update module active status.
     *
     * @param string $slug      Module slug.
     * @param bool   $is_active Active status.
     * @return bool Success.
     */
    public function set_active( string $slug, bool $is_active ): bool {
        $table = $this->db->table( 'modules' );

        // Verify module exists first.
        $module = $this->get( $slug );
        if ( ! $module ) {
            error_log( 'CAP set_active: Module not found with slug: ' . $slug );
            return false;
        }

        // Don't update if already in the desired state.
        $current_state = (bool) $module['is_active'];
        if ( $current_state === $is_active ) {
            return true; // Already in desired state.
        }

        $result = $this->wpdb->update(
            $table,
            array( 'is_active' => $is_active ? 1 : 0 ),
            array( 'id' => $module['id'] ),
            array( '%d' ),
            array( '%d' )
        );

        if ( false === $result ) {
            error_log( 'CAP set_active: Update failed for module ID: ' . $module['id'] . ' - DB Error: ' . $this->wpdb->last_error );
            return false;
        }

        return true;
    }

    /**
     * Update module sort order.
     *
     * @param string $slug       Module slug.
     * @param int    $sort_order New sort order.
     * @return bool Success.
     */
    public function set_sort_order( string $slug, int $sort_order ): bool {
        $table = $this->db->table( 'modules' );

        $result = $this->wpdb->update(
            $table,
            array( 'sort_order' => $sort_order ),
            array( 'module_slug' => $slug ),
            array( '%d' ),
            array( '%s' )
        );

        return false !== $result;
    }

    /**
     * Update role access for a module.
     *
     * @param string $slug      Module slug.
     * @param string $role_slug Role slug.
     * @param bool   $can_view  Can view.
     * @return bool Success.
     */
    public function set_role_access( string $slug, string $role_slug, bool $can_view ): bool {
        $module = $this->get( $slug );
        if ( ! $module ) {
            return false;
        }

        $table = $this->db->table( 'module_access' );

        // Check if exists.
        $existing = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$table} WHERE module_id = %d AND role_slug = %s",
                $module['id'],
                $role_slug
            )
        );

        if ( $existing ) {
            $result = $this->wpdb->update(
                $table,
                array( 'can_view' => $can_view ? 1 : 0 ),
                array( 'id' => $existing ),
                array( '%d' ),
                array( '%d' )
            );
        } else {
            $result = $this->wpdb->insert(
                $table,
                array(
                    'module_id' => $module['id'],
                    'role_slug' => $role_slug,
                    'can_view'  => $can_view ? 1 : 0,
                ),
                array( '%d', '%s', '%d' )
            );
        }

        return false !== $result;
    }

    /**
     * Bulk update role access for a module.
     *
     * @param string $slug  Module slug.
     * @param array  $roles Role => can_view mapping.
     * @return bool Success.
     */
    public function set_module_roles( string $slug, array $roles ): bool {
        $success = true;

        foreach ( $roles as $role_slug => $can_view ) {
            if ( ! $this->set_role_access( $slug, $role_slug, $can_view ) ) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Check if a role can access a module.
     *
     * @param string $slug      Module slug.
     * @param string $role_slug Role slug.
     * @return bool Can access.
     */
    public function can_access( string $slug, string $role_slug ): bool {
        // Administrators always have access.
        if ( 'administrator' === $role_slug ) {
            return true;
        }

        $modules_table = $this->db->table( 'modules' );
        $access_table  = $this->db->table( 'module_access' );

        $can_view = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT a.can_view FROM {$access_table} a
                 INNER JOIN {$modules_table} m ON a.module_id = m.id
                 WHERE m.module_slug = %s AND m.is_active = 1 AND a.role_slug = %s",
                $slug,
                $role_slug
            )
        );

        return (bool) $can_view;
    }

    /**
     * Check if current user can access a module.
     *
     * @param string $slug Module slug.
     * @return bool Can access.
     */
    public function current_user_can_access( string $slug ): bool {
        $user = wp_get_current_user();

        if ( ! $user->exists() ) {
            return false;
        }

        // Check all user roles.
        foreach ( $user->roles as $role ) {
            if ( $this->can_access( $slug, $role ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get allowed menu slugs for a role.
     *
     * @param string $role_slug Role slug.
     * @return array Menu slugs.
     */
    public function get_allowed_menus_for_role( string $role_slug ): array {
        $modules = $this->get_for_role( $role_slug );
        $menus   = array();

        foreach ( $modules as $module ) {
            if ( ! empty( $module['menu_slug'] ) ) {
                $menus[] = $module['menu_slug'];
            }
        }

        return $menus;
    }

    /**
     * Delete a module and its access records.
     *
     * @param string $slug Module slug.
     * @return bool Success.
     */
    public function delete( string $slug ): bool {
        $module = $this->get( $slug );
        if ( ! $module ) {
            return false;
        }

        $modules_table = $this->db->table( 'modules' );
        $access_table  = $this->db->table( 'module_access' );

        // Delete access records first.
        $this->wpdb->delete(
            $access_table,
            array( 'module_id' => $module['id'] ),
            array( '%d' )
        );

        // Delete module.
        $result = $this->wpdb->delete(
            $modules_table,
            array( 'id' => $module['id'] ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * Auto-detect WordPress admin menus.
     *
     * Scans the global $menu and $submenu to find all available admin pages.
     *
     * @return array Array of detected menus.
     */
    public function auto_detect_menus(): array {
        global $menu, $submenu;

        $detected = array();

        // Menu slugs to exclude (WordPress core that shouldn't be modules).
        $exclude_slugs = array(
            'separator1',
            'separator2',
            'separator-last',
        );

        // Process main menu items.
        if ( ! empty( $menu ) ) {
            foreach ( $menu as $position => $item ) {
                // Skip separators and empty items.
                if ( empty( $item[0] ) || strpos( $item[2], 'separator' ) !== false ) {
                    continue;
                }

                if ( in_array( $item[2], $exclude_slugs, true ) ) {
                    continue;
                }

                // Extract menu info.
                $menu_title = wp_strip_all_tags( $item[0] );
                $menu_slug  = $item[2];
                $capability = $item[1] ?? 'read';
                $icon       = $item[6] ?? 'dashicons-admin-generic';

                // Normalize icon.
                if ( strpos( $icon, 'dashicons-' ) === false && strpos( $icon, 'data:image' ) === false ) {
                    $icon = 'dashicons-admin-generic';
                }
                if ( strpos( $icon, 'data:image' ) !== false ) {
                    $icon = 'dashicons-admin-generic'; // Use generic for SVG icons.
                }

                // Determine plugin source.
                $source = $this->detect_menu_source( $menu_slug );

                $detected[] = array(
                    'slug'          => 'menu_' . sanitize_key( $menu_slug ),
                    'name'          => $menu_title,
                    'description'   => '',
                    'plugin_source' => $source,
                    'menu_slug'     => $menu_slug,
                    'capability'    => $capability,
                    'icon'          => $icon,
                    'sort_order'    => $position,
                    'is_submenu'    => false,
                    'parent'        => '',
                );
            }
        }

        // Process submenu items.
        if ( ! empty( $submenu ) ) {
            foreach ( $submenu as $parent_slug => $items ) {
                foreach ( $items as $item ) {
                    if ( empty( $item[0] ) ) {
                        continue;
                    }

                    $menu_title = wp_strip_all_tags( $item[0] );
                    $menu_slug  = $item[2];
                    $capability = $item[1] ?? 'read';

                    // Skip if same as parent (first submenu item often duplicates parent).
                    if ( $menu_slug === $parent_slug ) {
                        continue;
                    }

                    $source = $this->detect_menu_source( $menu_slug );

                    $detected[] = array(
                        'slug'          => 'submenu_' . sanitize_key( $parent_slug . '_' . $menu_slug ),
                        'name'          => $menu_title,
                        'description'   => '',
                        'plugin_source' => $source,
                        'menu_slug'     => $this->build_submenu_url( $parent_slug, $menu_slug ),
                        'capability'    => $capability,
                        'icon'          => 'dashicons-arrow-right-alt2',
                        'sort_order'    => 100,
                        'is_submenu'    => true,
                        'parent'        => $parent_slug,
                    );
                }
            }
        }

        return $detected;
    }

    /**
     * Build the correct URL for a submenu item.
     *
     * @param string $parent_slug Parent menu slug.
     * @param string $menu_slug   Submenu slug.
     * @return string Full menu URL.
     */
    private function build_submenu_url( string $parent_slug, string $menu_slug ): string {
        // If menu_slug is already a full URL or has query params.
        if ( strpos( $menu_slug, '.php' ) !== false || strpos( $menu_slug, '?' ) !== false ) {
            return $menu_slug;
        }

        // If parent is a PHP file, append as query param.
        if ( strpos( $parent_slug, '.php' ) !== false ) {
            $separator = strpos( $parent_slug, '?' ) !== false ? '&' : '?';
            return $parent_slug . $separator . 'page=' . $menu_slug;
        }

        // Otherwise use admin.php.
        return 'admin.php?page=' . $menu_slug;
    }

    /**
     * Detect the source plugin/theme for a menu.
     *
     * @param string $menu_slug Menu slug.
     * @return string Source name.
     */
    private function detect_menu_source( string $menu_slug ): string {
        // WordPress core menus.
        $wp_core = array(
            'index.php', 'edit.php', 'upload.php', 'edit-comments.php',
            'themes.php', 'plugins.php', 'users.php', 'tools.php',
            'options-general.php', 'profile.php', 'link-manager.php',
        );

        if ( in_array( $menu_slug, $wp_core, true ) || strpos( $menu_slug, 'edit.php?post_type=' ) === 0 ) {
            return 'WordPress';
        }

        // WooCommerce.
        if ( strpos( $menu_slug, 'woocommerce' ) !== false || strpos( $menu_slug, 'wc-' ) !== false ) {
            return 'WooCommerce';
        }

        // Known plugin patterns.
        $known_plugins = array(
            'elementor'    => 'Elementor',
            'wpforms'      => 'WPForms',
            'contact-form' => 'Contact Form 7',
            'jetpack'      => 'Jetpack',
            'yoast'        => 'Yoast SEO',
            'wpseo'        => 'Yoast SEO',
            'akismet'      => 'Akismet',
            'wordfence'    => 'Wordfence',
            'wcudc'        => 'WC Uber Direct Connect',
            'cap-'         => 'Client Admin Portal',
        );

        foreach ( $known_plugins as $pattern => $name ) {
            if ( stripos( $menu_slug, $pattern ) !== false ) {
                return $name;
            }
        }

        return __( 'Otro Plugin', 'client-admin-portal' );
    }

    /**
     * Get auto-detected menus that are not yet registered.
     *
     * @return array Unregistered menus.
     */
    public function get_unregistered_menus(): array {
        $detected   = $this->auto_detect_menus();
        $registered = $this->get_all();

        // Build list of registered menu slugs.
        $registered_slugs = array();
        foreach ( $registered as $module ) {
            $registered_slugs[] = $module['menu_slug'];
        }

        // Filter out already registered menus.
        $unregistered = array();
        foreach ( $detected as $menu ) {
            if ( ! in_array( $menu['menu_slug'], $registered_slugs, true ) ) {
                $unregistered[] = $menu;
            }
        }

        return $unregistered;
    }

    /**
     * Register a module manually (from admin UI).
     *
     * @param array $data Module data.
     * @return int|false Module ID on success, false on failure.
     */
    public function register_manual( array $data ) {
        $table = $this->db->table( 'modules' );

        // Validate required fields.
        if ( empty( $data['name'] ) || empty( $data['menu_slug'] ) ) {
            return false;
        }

        // Generate slug if not provided.
        $slug = ! empty( $data['slug'] ) ? sanitize_key( $data['slug'] ) : 'manual_' . sanitize_key( $data['menu_slug'] );

        // Check if already exists.
        $existing = $this->get( $slug );
        if ( $existing ) {
            return false;
        }

        // Prepare data.
        $module_data = array(
            'module_slug'        => $slug,
            'module_name'        => sanitize_text_field( $data['name'] ),
            'module_description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'plugin_source'      => sanitize_text_field( $data['plugin_source'] ?? __( 'Manual', 'client-admin-portal' ) ),
            'menu_slug'          => sanitize_text_field( $data['menu_slug'] ),
            'capability'         => sanitize_key( $data['capability'] ?? 'manage_options' ),
            'icon'               => sanitize_text_field( $data['icon'] ?? 'dashicons-admin-generic' ),
            'sort_order'         => absint( $data['sort_order'] ?? 50 ),
            'is_active'          => 1,
            'is_core'            => 0,
        );

        $result = $this->wpdb->insert(
            $table,
            $module_data,
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
        );

        if ( false === $result ) {
            return false;
        }

        $module_id = $this->wpdb->insert_id;

        // Set default access.
        $this->set_default_access( $module_id, $module_data['capability'] );

        return $module_id;
    }

    /**
     * Update an existing module.
     *
     * @param string $slug Module slug.
     * @param array  $data Data to update.
     * @return bool Success.
     */
    public function update( string $slug, array $data ): bool {
        $module = $this->get( $slug );
        if ( ! $module ) {
            return false;
        }

        $table = $this->db->table( 'modules' );

        $update_data = array();
        $formats     = array();

        // Only update allowed fields.
        $allowed = array(
            'module_name'        => '%s',
            'module_description' => '%s',
            'plugin_source'      => '%s',
            'menu_slug'          => '%s',
            'capability'         => '%s',
            'icon'               => '%s',
            'sort_order'         => '%d',
        );

        foreach ( $allowed as $field => $format ) {
            // Map incoming keys to database fields.
            $input_key = str_replace( 'module_', '', $field );
            if ( $input_key === 'module_name' ) {
                $input_key = 'name';
            }
            if ( $input_key === 'module_description' ) {
                $input_key = 'description';
            }

            if ( isset( $data[ $input_key ] ) ) {
                $update_data[ $field ] = sanitize_text_field( $data[ $input_key ] );
                $formats[]             = $format;
            } elseif ( isset( $data[ $field ] ) ) {
                $update_data[ $field ] = sanitize_text_field( $data[ $field ] );
                $formats[]             = $format;
            }
        }

        if ( empty( $update_data ) ) {
            return true; // Nothing to update.
        }

        $result = $this->wpdb->update(
            $table,
            $update_data,
            array( 'module_slug' => $slug ),
            $formats,
            array( '%s' )
        );

        return false !== $result;
    }

    /**
     * Import multiple menus from auto-detection.
     *
     * @param array $slugs Array of detected menu slugs to import.
     * @return int Number of imported modules.
     */
    public function import_detected_menus( array $slugs ): int {
        $detected = $this->auto_detect_menus();
        $count    = 0;

        foreach ( $detected as $menu ) {
            if ( in_array( $menu['slug'], $slugs, true ) ) {
                $result = $this->register_manual( array(
                    'slug'          => $menu['slug'],
                    'name'          => $menu['name'],
                    'description'   => $menu['description'],
                    'plugin_source' => $menu['plugin_source'],
                    'menu_slug'     => $menu['menu_slug'],
                    'capability'    => $menu['capability'],
                    'icon'          => $menu['icon'],
                    'sort_order'    => $menu['sort_order'],
                ) );

                if ( $result ) {
                    $count++;
                }
            }
        }

        return $count;
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
 * Get module registry instance.
 *
 * @return CAP_Module_Registry
 */
function cap_modules(): CAP_Module_Registry {
    return CAP_Module_Registry::instance();
}
