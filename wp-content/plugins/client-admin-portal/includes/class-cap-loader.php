<?php
/**
 * Plugin Loader Class
 *
 * Orchestrates all WordPress hooks and initializes plugin components.
 * Acts as the central coordinator that ties together Menu, UI, and Dashboard classes.
 *
 * @package Client_Admin_Portal
 * @since   1.0.0
 */

// Security check
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CAP_Loader
 *
 * Main orchestrator class that initializes all plugin components
 * and registers their hooks with WordPress.
 *
 * @since 1.0.0
 */
class CAP_Loader {

	/**
	 * Menu handler instance
	 *
	 * @var CAP_Menu
	 */
	private CAP_Menu $menu;

	/**
	 * UI handler instance
	 *
	 * @var CAP_UI
	 */
	private CAP_UI $ui;

	/**
	 * Dashboard handler instance
	 *
	 * @var CAP_Dashboard
	 */
	private CAP_Dashboard $dashboard;

	/**
	 * Constructor
	 *
	 * Initializes all component classes.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->menu      = new CAP_Menu();
		$this->ui        = new CAP_UI();
		$this->dashboard = new CAP_Dashboard();
	}

	/**
	 * Run the loader
	 *
	 * Registers all hooks for the plugin components.
	 * This is the main entry point that activates all functionality.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function run(): void {
		// Register Menu hooks
		$this->register_menu_hooks();

		// Register UI hooks
		$this->register_ui_hooks();

		// Register Dashboard hooks
		$this->register_dashboard_hooks();

		// Register security hooks
		$this->register_security_hooks();
	}

	/**
	 * Register menu-related hooks
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_menu_hooks(): void {
		// Remove menu items with high priority (runs late to catch all menus)
		add_action( 'admin_menu', array( $this->menu, 'restrict_menus' ), 999 );

		// Remove admin bar items
		add_action( 'wp_before_admin_bar_render', array( $this->menu, 'customize_admin_bar' ), 999 );
	}

	/**
	 * Register UI-related hooks
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_ui_hooks(): void {
		// Enqueue admin styles
		add_action( 'admin_enqueue_scripts', array( $this->ui, 'enqueue_admin_styles' ) );

		// Add body class for dark theme
		add_filter( 'admin_body_class', array( $this->ui, 'add_admin_body_class' ) );

		// Customize login page (logo)
		add_action( 'login_enqueue_scripts', array( $this->ui, 'customize_login_logo' ) );

		// Remove WordPress logo from admin bar
		add_action( 'wp_before_admin_bar_render', array( $this->ui, 'remove_wp_logo' ), 11 );

		// Custom admin footer text
		add_filter( 'admin_footer_text', array( $this->ui, 'custom_admin_footer' ) );

		// Remove WordPress version from footer
		add_filter( 'update_footer', array( $this->ui, 'remove_footer_version' ), 999 );
	}

	/**
	 * Register dashboard-related hooks
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_dashboard_hooks(): void {
		// Remove default dashboard widgets
		add_action( 'wp_dashboard_setup', array( $this->dashboard, 'remove_default_widgets' ), 999 );

		// Add custom dashboard widgets
		add_action( 'wp_dashboard_setup', array( $this->dashboard, 'add_custom_widgets' ), 10 );
	}

	/**
	 * Register security-related hooks
	 *
	 * Prevents unauthorized access to restricted pages.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_security_hooks(): void {
		// Block access to restricted admin pages
		add_action( 'admin_init', array( $this->menu, 'block_restricted_pages' ) );

		// Redirect shop managers after login
		add_filter( 'login_redirect', array( $this, 'custom_login_redirect' ), 10, 3 );
	}

	/**
	 * Custom login redirect for shop managers
	 *
	 * Redirects shop managers to the orders page instead of dashboard.
	 *
	 * @since 1.0.0
	 * @param string           $redirect_to           Default redirect URL.
	 * @param string           $requested_redirect_to Requested redirect URL.
	 * @param WP_User|WP_Error $user                  User object or error.
	 * @return string Modified redirect URL.
	 */
	public function custom_login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		// Check if user object exists and is not an error
		if ( ! is_wp_error( $user ) && $user instanceof WP_User ) {
			// If user is shop_manager, redirect to WooCommerce orders
			if ( in_array( 'shop_manager', (array) $user->roles, true ) ) {
				return cap_wc_orders_admin_url();
			}
		}

		return $redirect_to;
	}

	/**
	 * Check if current user should have restricted access
	 *
	 * Helper method to determine if restrictions should apply.
	 * Returns true for non-administrators.
	 *
	 * @since 1.0.0
	 * @return bool True if user should be restricted.
	 */
	public static function is_restricted_user(): bool {
		// Don't restrict if user is not logged in
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Get current user
		$user = wp_get_current_user();

		// Administrators have full access
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return false;
		}

		// All other users are restricted
		return true;
	}

	/**
	 * Check if current user is a shop manager
	 *
	 * @since 1.0.0
	 * @return bool True if user is shop_manager.
	 */
	public static function is_shop_manager(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();

		return in_array( 'shop_manager', (array) $user->roles, true );
	}
}
