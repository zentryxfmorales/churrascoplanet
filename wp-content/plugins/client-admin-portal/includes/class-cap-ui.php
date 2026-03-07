<?php
/**
 * Admin UI Customization Class
 *
 * Handles all visual customizations for the WordPress admin area.
 * Replaces the need for "UiPress" by providing a custom dark theme
 * and branding options.
 *
 * @package Client_Admin_Portal
 * @since   1.0.0
 */

// Security check
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CAP_UI
 *
 * Manages admin styles, branding, and visual customizations.
 *
 * @since 1.0.0
 */
class CAP_UI {

	/**
	 * Theme configuration
	 *
	 * Can be extended via filters for customization.
	 *
	 * @var array
	 */
	private array $theme_config = array(
		'primary_bg'     => '#1a1a2e',   // Dark Space background
		'secondary_bg'   => '#16213e',   // Slightly lighter dark
		'accent'         => '#e94560',   // Red/Pink accent
		'accent_hover'   => '#ff6b6b',   // Lighter red for hover
		'text_primary'   => '#ffffff',   // White text
		'text_secondary' => '#a0a0a0',   // Gray text
		'border'         => '#2d2d44',   // Dark border
		'success'        => '#00d9a5',   // Green for success
		'warning'        => '#ffc107',   // Yellow for warning
		'error'          => '#ff4757',   // Red for errors
	);

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Apply theme config filter
		$this->theme_config = apply_filters( 'cap_theme_config', $this->theme_config );
	}

	/**
	 * Enqueue admin styles
	 *
	 * Loads the custom dark mode CSS for the admin area.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_admin_styles(): void {
		// Only load dark theme styles for restricted users (non-administrators)
		if ( ! CAP_Loader::is_restricted_user() ) {
			return;
		}

		// Enqueue main admin stylesheet
		wp_enqueue_style(
			'cap-admin-style',
			CAP_PLUGIN_URL . 'assets/css/admin-style.css',
			array(),
			CAP_VERSION
		);

		// Add inline CSS for theme variables
		$inline_css = $this->generate_css_variables();
		wp_add_inline_style( 'cap-admin-style', $inline_css );

		// Enqueue Google Fonts (Inter for modern look)
		wp_enqueue_style(
			'cap-google-fonts',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
			array(),
			CAP_VERSION
		);
	}

	/**
	 * Generate CSS custom properties from theme config
	 *
	 * Creates CSS variables that can be used throughout the stylesheet.
	 *
	 * @since 1.0.0
	 * @return string CSS custom properties declaration.
	 */
	private function generate_css_variables(): string {
		$css = ':root {';

		foreach ( $this->theme_config as $key => $value ) {
			$css_var = '--cap-' . str_replace( '_', '-', $key );
			$css    .= "{$css_var}: {$value};";
		}

		$css .= '}';

		return $css;
	}

	/**
	 * Add custom body class to admin area
	 *
	 * Adds a class for targeting the dark theme styles.
	 *
	 * @since 1.0.0
	 * @param string $classes Existing body classes.
	 * @return string Modified body classes.
	 */
	public function add_admin_body_class( string $classes ): string {
		// Only apply dark theme classes for restricted users (non-administrators)
		if ( ! CAP_Loader::is_restricted_user() ) {
			return $classes;
		}

		// Add dark theme class
		$classes .= ' cap-dark-theme';

		// Add space theme class
		$classes .= ' cap-space-theme';

		// Add class for restricted user
		$classes .= ' cap-restricted-user';

		// Add class for shop manager
		if ( CAP_Loader::is_shop_manager() ) {
			$classes .= ' cap-shop-manager';
		}

		return $classes;
	}

	/**
	 * Customize login page logo
	 *
	 * Replaces the WordPress logo on the login page with custom branding.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function customize_login_logo(): void {
		// Get custom logo or use default
		$logo_url = apply_filters( 'cap_login_logo_url', '' );

		// If no custom logo, use text-based branding
		$brand_name = apply_filters( 'cap_brand_name', 'Churrasco Planet' );

		?>
		<style type="text/css">
			/* Login page dark theme */
			body.login {
				background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%) !important;
			}

			/* Logo area */
			#login h1 a {
				<?php if ( $logo_url ) : ?>
				background-image: url('<?php echo esc_url( $logo_url ); ?>') !important;
				background-size: contain;
				background-position: center;
				background-repeat: no-repeat;
				width: 200px;
				height: 80px;
				<?php else : ?>
				background-image: none !important;
				text-indent: 0;
				font-size: 28px;
				font-weight: 700;
				color: #e94560;
				text-shadow: none;
				width: auto;
				height: auto;
				<?php endif; ?>
			}

			<?php if ( ! $logo_url ) : ?>
			#login h1 a::after {
				content: '<?php echo esc_js( $brand_name ); ?>';
			}
			<?php endif; ?>

			/* Login form styling */
			.login form {
				background: rgba(255, 255, 255, 0.05) !important;
				border: 1px solid rgba(255, 255, 255, 0.1) !important;
				border-radius: 16px !important;
				box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3) !important;
				backdrop-filter: blur(10px);
			}

			.login label {
				color: #ffffff !important;
			}

			.login input[type="text"],
			.login input[type="password"] {
				background: rgba(255, 255, 255, 0.1) !important;
				border: 1px solid rgba(255, 255, 255, 0.2) !important;
				border-radius: 8px !important;
				color: #ffffff !important;
			}

			.login input[type="text"]:focus,
			.login input[type="password"]:focus {
				border-color: #e94560 !important;
				box-shadow: 0 0 0 2px rgba(233, 69, 96, 0.3) !important;
			}

			.login input[type="text"]::placeholder,
			.login input[type="password"]::placeholder {
				color: rgba(255, 255, 255, 0.5);
			}

			/* Submit button */
			.login .button-primary {
				background: linear-gradient(135deg, #e94560 0%, #ff6b6b 100%) !important;
				border: none !important;
				border-radius: 8px !important;
				text-shadow: none !important;
				box-shadow: 0 4px 15px rgba(233, 69, 96, 0.4) !important;
				transition: transform 0.2s, box-shadow 0.2s !important;
			}

			.login .button-primary:hover {
				transform: translateY(-2px);
				box-shadow: 0 6px 20px rgba(233, 69, 96, 0.5) !important;
			}

			/* Links */
			.login #nav a,
			.login #backtoblog a {
				color: rgba(255, 255, 255, 0.7) !important;
			}

			.login #nav a:hover,
			.login #backtoblog a:hover {
				color: #e94560 !important;
			}

			/* Remember me checkbox */
			.login .forgetmenot label {
				color: rgba(255, 255, 255, 0.7) !important;
			}

			/* Error messages */
			.login #login_error {
				background: rgba(255, 71, 87, 0.2) !important;
				border-left-color: #ff4757 !important;
				color: #ffffff !important;
				border-radius: 8px;
			}

			/* Success messages */
			.login .message {
				background: rgba(0, 217, 165, 0.2) !important;
				border-left-color: #00d9a5 !important;
				color: #ffffff !important;
				border-radius: 8px;
			}
		</style>
		<?php
	}

	/**
	 * Remove WordPress logo from admin bar
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function remove_wp_logo(): void {
		// Only remove for restricted users (non-administrators)
		if ( ! CAP_Loader::is_restricted_user() ) {
			return;
		}

		global $wp_admin_bar;

		// Remove WordPress logo
		$wp_admin_bar->remove_menu( 'wp-logo' );
	}

	/**
	 * Custom admin footer text
	 *
	 * Replaces the default "Thank you for creating with WordPress" text.
	 *
	 * @since 1.0.0
	 * @param string $text Default footer text.
	 * @return string Custom footer text.
	 */
	public function custom_admin_footer( string $text ): string {
		// Only customize footer for restricted users (non-administrators)
		if ( ! CAP_Loader::is_restricted_user() ) {
			return $text;
		}

		$brand_name = apply_filters( 'cap_brand_name', 'Churrasco Planet' );

		return sprintf(
			/* translators: %s: brand name */
			'<span class="cap-footer-text">%s</span>',
			esc_html( $brand_name . ' Admin Portal' )
		);
	}

	/**
	 * Remove WordPress version from footer
	 *
	 * Hides the WordPress version number for security and branding.
	 *
	 * @since 1.0.0
	 * @param string $version Default version text.
	 * @return string Empty string.
	 */
	public function remove_footer_version( string $version ): string {
		// Only hide for restricted users (admins may want to see it)
		if ( CAP_Loader::is_restricted_user() ) {
			return '';
		}

		return $version;
	}

	/**
	 * Get theme configuration
	 *
	 * Returns the current theme colors and settings.
	 *
	 * @since 1.0.0
	 * @return array Theme configuration.
	 */
	public function get_theme_config(): array {
		return $this->theme_config;
	}

	/**
	 * Get a specific theme color
	 *
	 * @since 1.0.0
	 * @param string $key Color key (e.g., 'primary_bg', 'accent').
	 * @return string|null Color value or null if not found.
	 */
	public function get_color( string $key ): ?string {
		return $this->theme_config[ $key ] ?? null;
	}
}
