<?php
/**
 * Plugin Name: Client Admin Portal
 * Plugin URI: https://churrascoplanet.com
 * Description: White-label WordPress admin experience with configurable branding, modules, and role-based access control. Features a professional dark theme and comprehensive audit logging.
 * Version: 2.0.0
 * Author: Zentryx
 * Author URI: https://zentryx.cl
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: client-admin-portal
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package Client_Admin_Portal
 */

// ============================================================================
// SECURITY: Prevent direct file access
// ============================================================================
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// ============================================================================
// PLUGIN CONSTANTS
// ============================================================================

/**
 * Plugin version number
 */
define( 'CAP_VERSION', '2.0.0' );

/**
 * Plugin base file path
 */
define( 'CAP_PLUGIN_FILE', __FILE__ );

/**
 * Plugin directory path (with trailing slash)
 */
define( 'CAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin URL (with trailing slash)
 */
define( 'CAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename (e.g., 'client-admin-portal/client-admin-portal.php')
 */
define( 'CAP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ============================================================================
// AUTOLOAD REQUIRED FILES
// ============================================================================

/**
 * Load core plugin classes
 *
 * Classes are loaded in dependency order. The new modular architecture
 * separates concerns: Database, Settings, Modules, Access Control, Audit, Branding.
 */

// Database & Settings (foundation layer)
require_once CAP_PLUGIN_DIR . 'includes/class-cap-database.php';
require_once CAP_PLUGIN_DIR . 'includes/class-cap-settings.php';

// Module Registry & Access Control (business logic layer)
require_once CAP_PLUGIN_DIR . 'includes/class-cap-module-registry.php';
require_once CAP_PLUGIN_DIR . 'includes/class-cap-access-control.php';

// Audit Log (security layer)
require_once CAP_PLUGIN_DIR . 'includes/class-cap-audit-log.php';

// Branding (presentation layer)
require_once CAP_PLUGIN_DIR . 'includes/class-cap-branding.php';

// Legacy classes (maintained for backwards compatibility)
require_once CAP_PLUGIN_DIR . 'includes/class-cap-loader.php';
require_once CAP_PLUGIN_DIR . 'includes/class-cap-menu.php';
require_once CAP_PLUGIN_DIR . 'includes/class-cap-ui.php';
require_once CAP_PLUGIN_DIR . 'includes/class-cap-dashboard.php';

// Admin Settings Page (admin only)
if ( is_admin() ) {
    require_once CAP_PLUGIN_DIR . 'admin/class-cap-settings-page.php';
}

// ============================================================================
// PLUGIN INITIALIZATION
// ============================================================================

/**
 * Load plugin text domain for translations
 *
 * @since 2.0.0
 * @return void
 */
function cap_load_textdomain(): void {
    load_plugin_textdomain(
        'client-admin-portal',
        false,
        dirname( CAP_PLUGIN_BASENAME ) . '/languages'
    );
}
add_action( 'init', 'cap_load_textdomain' );

/**
 * Initialize the plugin
 *
 * Sets up the new modular architecture alongside legacy components.
 * Runs on 'plugins_loaded' to ensure all dependencies are available.
 *
 * @since 2.0.0
 * @return void
 */
function cap_init(): void {
    // Run database migrations if needed
    $database = new CAP_Database();
    $database->maybe_migrate();

    // Initialize core systems (singletons)
    $settings = cap_settings();
    $modules  = cap_modules();
    $access   = cap_access();
    $audit    = cap_audit();
    $branding = cap_branding();

    // Initialize module registry (allows plugins to register) - after init to ensure textdomain is loaded
    add_action( 'init', function() use ( $modules ) {
        $modules->init();
    }, 15 ); // Priority 15 to run after textdomain is loaded at priority 10

    // Initialize audit logging
    $audit->init();

    // Admin-only initialization
    if ( is_admin() ) {
        // Initialize access control
        $access->init();

        // Initialize branding
        $branding->init();

        // Initialize settings page (only for administrators)
        if ( current_user_can( 'manage_options' ) ) {
            $settings_page = new CAP_Settings_Page();
            $settings_page->init();
        }

        // Initialize legacy loader for backwards compatibility
        $loader = new CAP_Loader();
        $loader->run();
    }

    // Register core modules from this plugin (after init for proper translation loading)
    add_action( 'cap_register_modules', 'cap_register_core_modules' );
}

// Hook into plugins_loaded for proper initialization timing
add_action( 'plugins_loaded', 'cap_init' );

/**
 * Get the WooCommerce orders admin page slug, HPOS-aware.
 *
 * WooCommerce 8.2+ with HPOS enabled moves orders to admin.php?page=wc-orders.
 * Without HPOS the classic slug edit.php?post_type=shop_order is used.
 *
 * @return string Admin-relative slug (no leading slash).
 */
function cap_wc_orders_menu_slug(): string {
    if (
        class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
        \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
    ) {
        return 'admin.php?page=wc-orders';
    }
    return 'edit.php?post_type=shop_order';
}

/**
 * Get the full WooCommerce orders admin URL, HPOS-aware.
 *
 * @param string $status Optional WC order status filter (e.g. 'wc-processing').
 * @return string Full admin URL.
 */
function cap_wc_orders_admin_url( string $status = '' ): string {
    if (
        class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
        \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
    ) {
        $base = 'admin.php?page=wc-orders';
        return admin_url( $status ? $base . '&status=' . $status : $base );
    }
    $base = 'edit.php?post_type=shop_order';
    return admin_url( $status ? $base . '&post_status=' . $status : $base );
}

/**
 * Register core modules provided by Client Admin Portal
 *
 * @param CAP_Module_Registry $registry Module registry instance.
 */
function cap_register_core_modules( CAP_Module_Registry $registry ): void {
    // Dashboard module
    $registry->register( array(
        'slug'          => 'cap_dashboard',
        'name'          => __( 'Dashboard', 'client-admin-portal' ),
        'description'   => __( 'Panel principal con widgets personalizados', 'client-admin-portal' ),
        'plugin_source' => 'Client Admin Portal',
        'menu_slug'     => 'index.php',
        'capability'    => 'read',
        'icon'          => 'dashicons-dashboard',
        'sort_order'    => 1,
        'is_core'       => true,
    ) );

    // WooCommerce modules (if WooCommerce is active)
    if ( class_exists( 'WooCommerce' ) ) {
        // Main WooCommerce menu (covers orders via HPOS, analytics, settings, etc.)
        $registry->register( array(
            'slug'          => 'wc_main',
            'name'          => __( 'WooCommerce', 'client-admin-portal' ),
            'description'   => __( 'Menú principal de WooCommerce: pedidos, analíticas, ajustes', 'client-admin-portal' ),
            'plugin_source' => 'WooCommerce',
            'menu_slug'     => 'woocommerce',
            'capability'    => 'manage_woocommerce',
            'icon'          => 'dashicons-cart',
            'sort_order'    => 5,
            'is_core'       => false,
        ) );

        // Orders — slug resolved at runtime to support both HPOS and legacy storage
        $registry->register( array(
            'slug'          => 'wc_orders',
            'name'          => __( 'Pedidos', 'client-admin-portal' ),
            'description'   => __( 'Gestionar pedidos de WooCommerce', 'client-admin-portal' ),
            'plugin_source' => 'WooCommerce',
            'menu_slug'     => cap_wc_orders_menu_slug(),
            'capability'    => 'manage_woocommerce',
            'icon'          => 'dashicons-clipboard',
            'sort_order'    => 10,
            'is_core'       => false,
        ) );

        // Products
        $registry->register( array(
            'slug'          => 'wc_products',
            'name'          => __( 'Productos', 'client-admin-portal' ),
            'description'   => __( 'Gestionar productos de WooCommerce', 'client-admin-portal' ),
            'plugin_source' => 'WooCommerce',
            'menu_slug'     => 'edit.php?post_type=product',
            'capability'    => 'manage_woocommerce',
            'icon'          => 'dashicons-products',
            'sort_order'    => 20,
            'is_core'       => false,
        ) );
    }
}

// ============================================================================
// ACTIVATION & DEACTIVATION HOOKS
// ============================================================================

/**
 * Plugin activation callback
 *
 * Runs when the plugin is activated. Creates database tables
 * and sets up initial configuration.
 *
 * @since 2.0.0
 * @return void
 */
function cap_activate(): void {
    // Run database migrations
    $database = new CAP_Database();
    $database->maybe_migrate();

    // Store plugin version for future migrations
    update_option( 'cap_version', CAP_VERSION );

    // Store activation timestamp
    update_option( 'cap_activated', current_time( 'mysql' ) );

    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cap_activate' );

/**
 * Plugin deactivation callback
 *
 * Runs when the plugin is deactivated. Cleans up temporary data
 * but preserves settings for potential reactivation.
 *
 * @since 2.0.0
 * @return void
 */
function cap_deactivate(): void {
    // Clear scheduled events
    wp_clear_scheduled_hook( 'cap_audit_log_cleanup' );

    // Flush rewrite rules to clean up
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cap_deactivate' );

/**
 * Plugin uninstall callback
 *
 * Runs when the plugin is deleted. Removes all data including
 * database tables and options.
 *
 * Note: This must be in uninstall.php or registered this way.
 * We use register_uninstall_hook for simplicity.
 *
 * @since 2.0.0
 * @return void
 */
function cap_uninstall(): void {
    // Only run if user can manage options
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Drop all custom tables
    $database = new CAP_Database();
    $database->drop_tables();

    // Remove options
    delete_option( 'cap_version' );
    delete_option( 'cap_activated' );
    delete_option( 'cap_db_version' );

    // Clear any transients
    delete_transient( 'cap_settings_cache' );
}
// Note: Uninstall hook should be in uninstall.php for best practices
// register_uninstall_hook( __FILE__, 'cap_uninstall' );
