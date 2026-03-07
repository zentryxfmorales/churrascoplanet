<?php
/**
 * Admin Menu Restriction Class
 *
 * Handles the restriction and cleanup of WordPress admin menus.
 * Replaces the need for "Admin Menu Editor" plugin by programmatically
 * controlling which menus are visible to non-administrator users.
 *
 * @package Client_Admin_Portal
 * @since   1.0.0
 */

// Security check
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CAP_Menu
 *
 * Controls admin menu visibility and access restrictions.
 *
 * @since 1.0.0
 */
class CAP_Menu {

	/**
	 * List of allowed top-level menu slugs for restricted users
	 *
	 * These are the only menus that shop_manager and other
	 * non-admin users will see in the admin sidebar.
	 *
	 * @var array
	 */
	private array $allowed_menus = array(
		'index.php',                        // Dashboard
		'edit.php?post_type=shop_order',    // WooCommerce Orders (legacy, no HPOS)
		'admin.php?page=wc-orders',         // WooCommerce Orders (HPOS enabled)
		'edit.php?post_type=product',       // Products
		'woocommerce',                      // WooCommerce main menu
		'wcudc-settings',                   // Uber Direct Connect (Delivery plugin)
		'wc-admin&path=/analytics/revenue', // WooCommerce Analytics (optional)
	);

	/**
	 * List of WooCommerce submenus to remove
	 *
	 * These submenus under WooCommerce will be hidden
	 * from restricted users.
	 *
	 * @var array
	 */
	private array $woo_submenus_to_remove = array(
		'wc-admin&path=/analytics/overview',  // Analytics
		'wc-admin&path=/marketing',           // Marketing
		'wc-admin&path=/extensions',          // Extensions
		'wc-settings',                        // Settings
		'wc-status',                          // Status/Tools
		'wc-addons',                          // Addons
	);

	/**
	 * List of restricted page slugs
	 *
	 * Users will be redirected if they try to access these pages directly.
	 *
	 * @var array
	 */
	private array $restricted_pages = array(
		'options-general.php',
		'options-writing.php',
		'options-reading.php',
		'options-discussion.php',
		'options-media.php',
		'options-permalink.php',
		'options-privacy.php',
		'themes.php',
		'plugins.php',
		'users.php',
		'tools.php',
		'import.php',
		'export.php',
		'site-health.php',
		'update-core.php',
		'edit-comments.php',
	);

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Constructor is empty as hooks are registered by the Loader
	}

	/**
	 * Restrict admin menus for non-administrator users
	 *
	 * Removes all menus except those explicitly allowed.
	 * Hooked to 'admin_menu' with priority 999 to run after
	 * all other plugins have registered their menus.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function restrict_menus(): void {
		// Only restrict for non-administrators
		if ( ! CAP_Loader::is_restricted_user() ) {
			return;
		}

		global $menu, $submenu;

		// ====================================================================
		// REMOVE TOP-LEVEL MENUS
		// ====================================================================

		if ( ! empty( $menu ) ) {
			foreach ( $menu as $key => $item ) {
				// Menu item structure: [0]=name, [1]=capability, [2]=slug
				$menu_slug = $item[2] ?? '';

				// Check if this menu is NOT in the allowed list
				if ( ! $this->is_menu_allowed( $menu_slug ) ) {
					remove_menu_page( $menu_slug );
				}
			}
		}

		// ====================================================================
		// REMOVE SPECIFIC SUBMENUS
		// ====================================================================

		// Remove WooCommerce submenus
		$this->remove_woocommerce_submenus();

		// Remove Dashboard submenus (Updates, etc.)
		remove_submenu_page( 'index.php', 'update-core.php' );

		// ====================================================================
		// REMOVE SPECIFIC MENUS BY SLUG (CLEANUP)
		// ====================================================================

		// Core WordPress menus to remove
		$menus_to_remove = array(
			'edit.php',              // Posts
			'upload.php',            // Media
			'edit.php?post_type=page', // Pages
			'edit-comments.php',     // Comments
			'themes.php',            // Appearance
			'plugins.php',           // Plugins
			'users.php',             // Users
			'tools.php',             // Tools
			'options-general.php',   // Settings
		);

		foreach ( $menus_to_remove as $menu_slug ) {
			remove_menu_page( $menu_slug );
		}
	}

	/**
	 * Check if a menu slug is in the allowed list
	 *
	 * @since 1.0.0
	 * @param string $slug The menu slug to check.
	 * @return bool True if menu is allowed.
	 */
	private function is_menu_allowed( string $slug ): bool {
		// Direct match
		if ( in_array( $slug, $this->allowed_menus, true ) ) {
			return true;
		}

		// Partial match for dynamic slugs (e.g., WooCommerce pages)
		foreach ( $this->allowed_menus as $allowed ) {
			if ( strpos( $slug, $allowed ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove WooCommerce-specific submenus
	 *
	 * Cleans up the WooCommerce menu to only show relevant items.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function remove_woocommerce_submenus(): void {
		// Remove submenus from WooCommerce
		foreach ( $this->woo_submenus_to_remove as $submenu_slug ) {
			remove_submenu_page( 'woocommerce', $submenu_slug );
		}

		// Remove "Home" dashboard from WooCommerce (wc-admin)
		remove_submenu_page( 'woocommerce', 'wc-admin' );

		// Remove Analytics submenus
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/overview' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/products' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/revenue' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/orders' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/variations' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/categories' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/coupons' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/taxes' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/downloads' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/stock' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/analytics/settings' );

		// Keep Orders as the main WooCommerce entry point
		// This is already handled by the allowed_menus list
	}

	/**
	 * Customize the admin bar
	 *
	 * Removes unnecessary items from the admin toolbar for restricted users.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function customize_admin_bar(): void {
		// Only customize for restricted users
		if ( ! CAP_Loader::is_restricted_user() ) {
			return;
		}

		global $wp_admin_bar;

		// Remove items from admin bar
		$items_to_remove = array(
			'comments',       // Comments
			'new-content',    // "+ New" menu
			'updates',        // Updates notification
			'wp-logo',        // WordPress logo (also handled by UI class)
			'customize',      // Customize link
			'menus',          // Menus
			'widgets',        // Widgets
			'themes',         // Themes
		);

		foreach ( $items_to_remove as $item ) {
			$wp_admin_bar->remove_node( $item );
		}

		// Remove "New" submenu items
		$wp_admin_bar->remove_node( 'new-post' );
		$wp_admin_bar->remove_node( 'new-page' );
		$wp_admin_bar->remove_node( 'new-media' );
		$wp_admin_bar->remove_node( 'new-user' );
	}

	/**
	 * Block access to restricted admin pages
	 *
	 * Redirects users to the dashboard if they try to access
	 * pages they shouldn't have access to.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function block_restricted_pages(): void {
		// Only block for restricted users
		if ( ! CAP_Loader::is_restricted_user() ) {
			return;
		}

		// Get current page
		global $pagenow;

		// Check if current page is restricted
		if ( in_array( $pagenow, $this->restricted_pages, true ) ) {
			// Log the blocked access attempt (optional, for debugging)
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'[Client Admin Portal] Blocked access to %s for user ID %d',
					$pagenow,
					get_current_user_id()
				) );
			}

			// Redirect to dashboard
			wp_safe_redirect( admin_url( 'index.php' ) );
			exit;
		}

		// Also check for query string based pages (WooCommerce admin pages)
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';

		$restricted_query_pages = array(
			'wc-settings',
			'wc-status',
			'wc-addons',
		);

		if ( in_array( $current_page, $restricted_query_pages, true ) ) {
			wp_safe_redirect( cap_wc_orders_admin_url() );
			exit;
		}
	}

	/**
	 * Get list of allowed menus
	 *
	 * Public getter for the allowed menus array.
	 * Can be filtered to add/remove allowed menus.
	 *
	 * @since 1.0.0
	 * @return array List of allowed menu slugs.
	 */
	public function get_allowed_menus(): array {
		/**
		 * Filter the list of allowed menus for restricted users
		 *
		 * @since 1.0.0
		 * @param array $allowed_menus Default allowed menu slugs.
		 */
		return apply_filters( 'cap_allowed_menus', $this->allowed_menus );
	}

	/**
	 * Add a menu to the allowed list
	 *
	 * Utility method to programmatically allow additional menus.
	 *
	 * @since 1.0.0
	 * @param string $menu_slug The menu slug to allow.
	 * @return void
	 */
	public function allow_menu( string $menu_slug ): void {
		if ( ! in_array( $menu_slug, $this->allowed_menus, true ) ) {
			$this->allowed_menus[] = $menu_slug;
		}
	}
}
