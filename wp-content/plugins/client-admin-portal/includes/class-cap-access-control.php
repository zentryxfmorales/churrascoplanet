<?php
/**
 * Access Control Class
 *
 * Manages role-based visibility and access control for modules
 * and menu items in the Client Admin Portal.
 *
 * @package Client_Admin_Portal
 * @since   2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CAP_Access_Control
 *
 * Centralizes all access control logic.
 */
class CAP_Access_Control {

    /**
     * Module registry instance.
     *
     * @var CAP_Module_Registry
     */
    private CAP_Module_Registry $modules;

    /**
     * Settings instance.
     *
     * @var CAP_Settings
     */
    private CAP_Settings $settings;

    /**
     * Cached allowed menus per role.
     *
     * @var array
     */
    private array $allowed_menus_cache = array();

    /**
     * Core menu items that are always available for restricted users.
     *
     * @var array
     */
    private array $core_menus = array(
        'index.php', // Dashboard
    );

    /**
     * Menu items that are NEVER available for restricted users.
     *
     * @var array
     */
    private array $blocked_menus = array(
        'plugins.php',
        'users.php',
        'tools.php',
        'options-general.php',
        'themes.php',
        'edit.php', // Posts
        'edit-comments.php',
        'upload.php', // Media (can be enabled per module if needed)
    );

    /**
     * Singleton instance.
     *
     * @var CAP_Access_Control|null
     */
    private static ?CAP_Access_Control $instance = null;

    /**
     * Get singleton instance.
     *
     * @return CAP_Access_Control
     */
    public static function instance(): CAP_Access_Control {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->modules  = cap_modules();
        $this->settings = cap_settings();
    }

    /**
     * Initialize access control hooks.
     */
    public function init(): void {
        // Only apply restrictions for non-administrators.
        if ( $this->is_restricted_user() ) {
            add_action( 'admin_menu', array( $this, 'filter_admin_menu' ), 9999 );
            add_action( 'admin_init', array( $this, 'block_unauthorized_pages' ) );
            add_action( 'wp_before_admin_bar_render', array( $this, 'filter_admin_bar' ), 9999 );
        }

        // Login redirect based on role.
        add_filter( 'login_redirect', array( $this, 'custom_login_redirect' ), 10, 3 );
    }

    /**
     * Check if current user is restricted (non-administrator).
     *
     * @return bool
     */
    public function is_restricted_user(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        return ! current_user_can( 'manage_options' );
    }

    /**
     * Get current user's primary role.
     *
     * @return string|null
     */
    public function get_current_role(): ?string {
        $user = wp_get_current_user();

        if ( ! $user->exists() ) {
            return null;
        }

        return ! empty( $user->roles ) ? reset( $user->roles ) : null;
    }

    /**
     * Get all allowed menu slugs for current user.
     *
     * @return array
     */
    public function get_allowed_menus(): array {
        $role = $this->get_current_role();

        if ( ! $role ) {
            return array();
        }

        // Check cache.
        if ( isset( $this->allowed_menus_cache[ $role ] ) ) {
            return $this->allowed_menus_cache[ $role ];
        }

        // Start with core menus.
        $allowed = $this->core_menus;

        // Get menus from active modules for this role.
        $module_menus = $this->modules->get_allowed_menus_for_role( $role );
        $allowed      = array_merge( $allowed, $module_menus );

        // Apply filter for extensibility.
        $allowed = apply_filters( 'cap_allowed_menus', $allowed, $role );

        // Cache and return.
        $this->allowed_menus_cache[ $role ] = array_unique( $allowed );

        return $this->allowed_menus_cache[ $role ];
    }

    /**
     * Filter admin menu to show only allowed items.
     */
    public function filter_admin_menu(): void {
        global $menu, $submenu;

        $allowed_menus = $this->get_allowed_menus();

        // Process main menu.
        if ( is_array( $menu ) ) {
            foreach ( $menu as $key => $item ) {
                $menu_slug = $item[2] ?? '';

                // Check if this menu or any of its submenus is allowed.
                $is_allowed = $this->is_menu_allowed( $menu_slug, $allowed_menus );

                // Also check if blocked.
                $is_blocked = in_array( $menu_slug, $this->blocked_menus, true );

                if ( ! $is_allowed || $is_blocked ) {
                    unset( $menu[ $key ] );
                }
            }
        }

        // Process submenus.
        if ( is_array( $submenu ) ) {
            foreach ( $submenu as $parent_slug => $items ) {
                // If parent is blocked, remove all submenus.
                if ( in_array( $parent_slug, $this->blocked_menus, true ) ) {
                    unset( $submenu[ $parent_slug ] );
                    continue;
                }

                foreach ( $items as $key => $item ) {
                    $submenu_slug = $item[2] ?? '';
                    $full_slug    = $parent_slug . '&' . $submenu_slug;

                    // Check various slug formats.
                    $is_allowed = $this->is_menu_allowed( $submenu_slug, $allowed_menus )
                                  || $this->is_menu_allowed( $full_slug, $allowed_menus )
                                  || $this->is_menu_allowed( $parent_slug, $allowed_menus );

                    if ( ! $is_allowed ) {
                        unset( $submenu[ $parent_slug ][ $key ] );
                    }
                }

                // If no submenus left, remove parent entry from submenu array.
                if ( empty( $submenu[ $parent_slug ] ) ) {
                    unset( $submenu[ $parent_slug ] );
                }
            }
        }
    }

    /**
     * Check if a menu slug is allowed.
     *
     * @param string $slug          Menu slug to check.
     * @param array  $allowed_menus Allowed menus list.
     * @return bool
     */
    private function is_menu_allowed( string $slug, array $allowed_menus ): bool {
        // Direct match.
        if ( in_array( $slug, $allowed_menus, true ) ) {
            return true;
        }

        // Check if slug contains any allowed menu.
        foreach ( $allowed_menus as $allowed ) {
            if ( strpos( $slug, $allowed ) !== false || strpos( $allowed, $slug ) !== false ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Block access to unauthorized admin pages.
     */
    public function block_unauthorized_pages(): void {
        global $pagenow;

        // Get current page.
        $current_page = $pagenow;

        // Add query params for full page identification.
        if ( isset( $_GET['page'] ) ) {
            $current_page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
        }

        // Dashboard and index are always allowed.
        if ( 'index.php' === $pagenow && ! isset( $_GET['page'] ) ) {
            return;
        }

        // For edit.php with post_type, build full slug and check against modules
        // BEFORE the blanket edit.php block (which is meant for blog posts only).
        if ( 'edit.php' === $pagenow && isset( $_GET['post_type'] ) ) {
            $post_type_page = 'edit.php?post_type=' . sanitize_text_field( wp_unslash( $_GET['post_type'] ) );
            $allowed_menus  = $this->get_allowed_menus();

            if ( $this->is_menu_allowed( $post_type_page, $allowed_menus ) ) {
                return; // Allowed by module system.
            }

            $this->redirect_unauthorized();
        }

        // Check if page is blocked (bare edit.php = blog posts, etc.).
        if ( in_array( $pagenow, $this->blocked_menus, true ) ) {
            $this->redirect_unauthorized();
        }

        // Check if page is in allowed menus.
        $allowed_menus = $this->get_allowed_menus();

        // Check various formats.
        $is_allowed = $this->is_menu_allowed( $current_page, $allowed_menus )
                      || $this->is_menu_allowed( $pagenow, $allowed_menus );

        // For admin.php, check the page parameter.
        if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) ) {
            $is_allowed = $this->is_menu_allowed( sanitize_text_field( wp_unslash( $_GET['page'] ) ), $allowed_menus );
        }

        if ( ! $is_allowed ) {
            $this->redirect_unauthorized();
        }
    }

    /**
     * Redirect unauthorized access.
     */
    private function redirect_unauthorized(): void {
        // Get redirect URL based on role.
        $redirect_url = $this->get_role_redirect_url();

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Get redirect URL for current user's role.
     *
     * @return string
     */
    public function get_role_redirect_url(): string {
        $role = $this->get_current_role();

        // Check for role-specific redirect.
        $redirect = $this->settings->get( 'access', 'login_redirect', null, $role );

        if ( $redirect ) {
            return admin_url( $redirect );
        }

        // Default redirects by role.
        $default_redirects = array(
            'shop_manager' => cap_wc_orders_menu_slug(),
            'editor'       => 'edit.php',
            'author'       => 'edit.php',
            'contributor'  => 'edit.php',
            'subscriber'   => 'profile.php',
        );

        if ( isset( $default_redirects[ $role ] ) ) {
            return admin_url( $default_redirects[ $role ] );
        }

        return admin_url();
    }

    /**
     * Filter admin bar for restricted users.
     */
    public function filter_admin_bar(): void {
        global $wp_admin_bar;

        // Items to remove from admin bar.
        $remove_items = array(
            'wp-logo',
            'about',
            'wporg',
            'documentation',
            'support-forums',
            'feedback',
            'updates',
            'comments',
            'new-content',
            'customize',
        );

        foreach ( $remove_items as $item ) {
            $wp_admin_bar->remove_node( $item );
        }

        // Apply filter for extensibility.
        $additional_removes = apply_filters( 'cap_admin_bar_remove_items', array() );
        foreach ( $additional_removes as $item ) {
            $wp_admin_bar->remove_node( $item );
        }
    }

    /**
     * Custom login redirect based on role.
     *
     * @param string           $redirect_to Default redirect URL.
     * @param string           $requested   Requested redirect URL.
     * @param WP_User|WP_Error $user        User object or error.
     * @return string Redirect URL.
     */
    public function custom_login_redirect( string $redirect_to, string $requested, $user ): string {
        if ( ! is_a( $user, 'WP_User' ) ) {
            return $redirect_to;
        }

        // Administrators go to default location.
        if ( in_array( 'administrator', $user->roles, true ) ) {
            return $redirect_to;
        }

        // Get the primary role.
        $role = ! empty( $user->roles ) ? reset( $user->roles ) : null;

        if ( ! $role ) {
            return $redirect_to;
        }

        // Check for role-specific redirect.
        $custom_redirect = $this->settings->get( 'access', 'login_redirect', null, $role );

        if ( $custom_redirect ) {
            return admin_url( $custom_redirect );
        }

        // Default redirects.
        if ( 'shop_manager' === $role ) {
            return cap_wc_orders_admin_url();
        }

        // Customers and subscribers go to the store's My Account page (never wp-admin).
        if ( in_array( $role, array( 'customer', 'subscriber' ), true ) ) {
            return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url();
        }

        return $redirect_to;
    }

    /**
     * Check if a specific user can access a module.
     *
     * @param int    $user_id     User ID.
     * @param string $module_slug Module slug.
     * @return bool
     */
    public function user_can_access_module( int $user_id, string $module_slug ): bool {
        $user = get_user_by( 'id', $user_id );

        if ( ! $user ) {
            return false;
        }

        // Administrators have full access.
        if ( in_array( 'administrator', $user->roles, true ) ) {
            return true;
        }

        // Check each role.
        foreach ( $user->roles as $role ) {
            if ( $this->modules->can_access( $module_slug, $role ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all available roles (excluding administrator for restriction purposes).
     *
     * @param bool $include_admin Include administrator role.
     * @return array Role slug => Role name.
     */
    public function get_available_roles( bool $include_admin = false ): array {
        $wp_roles = wp_roles();
        $roles    = array();

        foreach ( $wp_roles->roles as $slug => $role ) {
            if ( ! $include_admin && 'administrator' === $slug ) {
                continue;
            }
            $roles[ $slug ] = translate_user_role( $role['name'] );
        }

        return $roles;
    }

    /**
     * Set login redirect for a role.
     *
     * @param string $role_slug   Role slug.
     * @param string $redirect_to Admin page to redirect to.
     * @return bool Success.
     */
    public function set_login_redirect( string $role_slug, string $redirect_to ): bool {
        return $this->settings->set( 'access', 'login_redirect', $redirect_to, $role_slug );
    }

    /**
     * Add a menu to the allowed list for a role.
     *
     * This creates/updates a module dynamically.
     *
     * @param string $menu_slug Menu slug.
     * @param string $role_slug Role slug.
     * @return bool Success.
     */
    public function allow_menu_for_role( string $menu_slug, string $role_slug ): bool {
        // Clear cache.
        unset( $this->allowed_menus_cache[ $role_slug ] );

        // This would typically be done through the module system.
        // For ad-hoc menus, we use settings.
        $custom_menus = $this->settings->get( 'access', 'custom_menus', array(), $role_slug );

        if ( ! is_array( $custom_menus ) ) {
            $custom_menus = array();
        }

        if ( ! in_array( $menu_slug, $custom_menus, true ) ) {
            $custom_menus[] = $menu_slug;
        }

        return $this->settings->set( 'access', 'custom_menus', $custom_menus, $role_slug );
    }

    /**
     * Clear access cache.
     */
    public function clear_cache(): void {
        $this->allowed_menus_cache = array();
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
 * Get access control instance.
 *
 * @return CAP_Access_Control
 */
function cap_access(): CAP_Access_Control {
    return CAP_Access_Control::instance();
}
