<?php
/**
 * Dynamic Branding System
 *
 * Manages theme colors, logos, and visual customizations
 * with per-role support.
 *
 * @package Client_Admin_Portal
 * @since   2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CAP_Branding
 *
 * Handles dynamic theme generation from database settings.
 */
class CAP_Branding {

    /**
     * Settings instance.
     *
     * @var CAP_Settings
     */
    private CAP_Settings $settings;

    /**
     * Cached branding config.
     *
     * @var array|null
     */
    private ?array $cached_config = null;

    /**
     * Available theme presets.
     *
     * @var array
     */
    private array $presets = array();

    /**
     * Singleton instance.
     *
     * @var CAP_Branding|null
     */
    private static ?CAP_Branding $instance = null;

    /**
     * Get singleton instance.
     *
     * @return CAP_Branding
     */
    public static function instance(): CAP_Branding {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->settings = cap_settings();
        // Presets are initialized lazily in get_presets() to avoid early translation loading
    }

    /**
     * Initialize theme presets (called lazily).
     */
    private function init_presets(): void {
        if ( ! empty( $this->presets ) ) {
            return; // Already initialized
        }

        $this->presets = array(
            'dark_space' => array(
                'name'         => __( 'Dark Space', 'client-admin-portal' ),
                'bg_main'      => '#0a0a14',
                'bg_card'      => '#161625',
                'bg_input'     => '#222235',
                'bg_hover'     => '#1e1e30',
                'bg_header'    => '#0d0d1a',
                'text_main'    => '#e0e0e0',
                'text_muted'   => '#a0a0b0',
                'text_faint'   => '#6a6a7a',
                'accent'       => '#ff5722',
                'accent_hover' => '#ff7043',
                'border'       => '#33334d',
                'border_light' => '#44446a',
                'success'      => '#4caf50',
                'warning'      => '#ff9800',
                'error'        => '#f44336',
                'info'         => '#2196f3',
            ),
            'midnight_blue' => array(
                'name'         => __( 'Midnight Blue', 'client-admin-portal' ),
                'bg_main'      => '#0f172a',
                'bg_card'      => '#1e293b',
                'bg_input'     => '#334155',
                'bg_hover'     => '#475569',
                'bg_header'    => '#0f172a',
                'text_main'    => '#f1f5f9',
                'text_muted'   => '#94a3b8',
                'text_faint'   => '#64748b',
                'accent'       => '#3b82f6',
                'accent_hover' => '#60a5fa',
                'border'       => '#334155',
                'border_light' => '#475569',
                'success'      => '#22c55e',
                'warning'      => '#f59e0b',
                'error'        => '#ef4444',
                'info'         => '#0ea5e9',
            ),
            'forest_green' => array(
                'name'         => __( 'Forest Green', 'client-admin-portal' ),
                'bg_main'      => '#14211c',
                'bg_card'      => '#1a2f27',
                'bg_input'     => '#243d33',
                'bg_hover'     => '#2d4a3f',
                'bg_header'    => '#0f1a15',
                'text_main'    => '#e8f5e9',
                'text_muted'   => '#a5d6a7',
                'text_faint'   => '#81c784',
                'accent'       => '#4caf50',
                'accent_hover' => '#66bb6a',
                'border'       => '#2e5f4a',
                'border_light' => '#3d7a60',
                'success'      => '#4caf50',
                'warning'      => '#ff9800',
                'error'        => '#f44336',
                'info'         => '#29b6f6',
            ),
            'light_clean' => array(
                'name'         => __( 'Light Clean', 'client-admin-portal' ),
                'bg_main'      => '#f5f5f5',
                'bg_card'      => '#ffffff',
                'bg_input'     => '#fafafa',
                'bg_hover'     => '#eeeeee',
                'bg_header'    => '#ffffff',
                'text_main'    => '#212121',
                'text_muted'   => '#757575',
                'text_faint'   => '#9e9e9e',
                'accent'       => '#1976d2',
                'accent_hover' => '#1565c0',
                'border'       => '#e0e0e0',
                'border_light' => '#eeeeee',
                'success'      => '#388e3c',
                'warning'      => '#f57c00',
                'error'        => '#d32f2f',
                'info'         => '#0288d1',
            ),
            'custom' => array(
                'name' => __( 'Custom', 'client-admin-portal' ),
            ),
        );

        // Allow plugins to add presets.
        $this->presets = apply_filters( 'cap_theme_presets', $this->presets );
    }

    /**
     * Initialize branding hooks.
     */
    public function init(): void {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dynamic_styles' ), 20 );
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_styles' ) );
        add_action( 'admin_head', array( $this, 'output_custom_css' ), 100 );
        add_filter( 'admin_body_class', array( $this, 'add_body_classes' ) );
        add_filter( 'admin_footer_text', array( $this, 'custom_footer_text' ) );
        add_filter( 'update_footer', array( $this, 'remove_footer_version' ), 999 );

        // Login customization.
        add_action( 'login_head', array( $this, 'output_login_styles' ) );

        // Admin bar customization.
        if ( $this->should_apply_branding() ) {
            add_action( 'wp_before_admin_bar_render', array( $this, 'customize_admin_bar' ), 11 );
        }
    }

    /**
     * Check if branding should be applied to current user.
     *
     * @return bool
     */
    private function should_apply_branding(): bool {
        // Always apply for non-administrators.
        if ( ! current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Check if admin wants to see branding too.
        return (bool) $this->settings->get( 'general', 'apply_branding_to_admin', false );
    }

    /**
     * Get branding configuration for current user.
     *
     * @param bool $force_refresh Force refresh cache.
     * @return array Branding config.
     */
    public function get_config( bool $force_refresh = false ): array {
        if ( null !== $this->cached_config && ! $force_refresh ) {
            return $this->cached_config;
        }

        // Ensure presets are initialized.
        $this->init_presets();

        // Get current user's role.
        $role = $this->get_current_role();

        // Get branding settings (role-specific falls back to global).
        $branding = $this->settings->get_group( 'branding', $role );

        // Determine preset.
        $preset = $branding['theme_preset'] ?? 'dark_space';

        // If using a preset (not custom), merge preset colors.
        if ( 'custom' !== $preset && isset( $this->presets[ $preset ] ) ) {
            $preset_colors = $this->presets[ $preset ];
            unset( $preset_colors['name'] );

            // Preset provides base, branding settings can override.
            $branding = array_merge( $preset_colors, array_filter( $branding ) );
        }

        // Ensure all required keys exist with defaults.
        $defaults = $this->get_defaults();
        $this->cached_config = wp_parse_args( $branding, $defaults );

        return $this->cached_config;
    }

    /**
     * Get default branding values.
     *
     * @return array Defaults.
     */
    public function get_defaults(): array {
        return array(
            'theme_preset'  => 'dark_space',
            'bg_main'       => '#0a0a14',
            'bg_card'       => '#161625',
            'bg_input'      => '#222235',
            'bg_hover'      => '#1e1e30',
            'bg_header'     => '#0d0d1a',
            'text_main'     => '#e0e0e0',
            'text_muted'    => '#a0a0b0',
            'text_faint'    => '#6a6a7a',
            'accent'        => '#ff5722',
            'accent_hover'  => '#ff7043',
            'border'        => '#33334d',
            'border_light'  => '#44446a',
            'success'       => '#4caf50',
            'warning'       => '#ff9800',
            'error'         => '#f44336',
            'info'          => '#2196f3',
            'logo_url'      => '',
            'logo_height'   => '60',
            'brand_name'    => 'Client Portal',
            'custom_css'    => '',
            'admin_footer'  => '',
        );
    }

    /**
     * Get available presets.
     *
     * @return array Presets.
     */
    public function get_presets(): array {
        $this->init_presets();
        return $this->presets;
    }

    /**
     * Get a specific color.
     *
     * @param string $key Color key.
     * @return string Color value.
     */
    public function get_color( string $key ): string {
        $config = $this->get_config();
        return $config[ $key ] ?? '';
    }

    /**
     * Check if dark theme is active.
     *
     * @return bool
     */
    public function is_dark_theme(): bool {
        $config = $this->get_config();
        $preset = $config['theme_preset'] ?? 'dark_space';

        // Light themes.
        $light_presets = array( 'light_clean' );

        if ( in_array( $preset, $light_presets, true ) ) {
            return false;
        }

        // For custom, check background luminance.
        if ( 'custom' === $preset ) {
            $bg_main   = $config['bg_main'] ?? '#0a0a14';
            $luminance = $this->get_luminance( $bg_main );
            return $luminance < 0.5;
        }

        return true;
    }

    /**
     * Calculate luminance of a color.
     *
     * @param string $hex Hex color.
     * @return float Luminance (0-1).
     */
    private function get_luminance( string $hex ): float {
        $hex = ltrim( $hex, '#' );

        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec( substr( $hex, 0, 2 ) ) / 255;
        $g = hexdec( substr( $hex, 2, 2 ) ) / 255;
        $b = hexdec( substr( $hex, 4, 2 ) ) / 255;

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Enqueue dynamic styles.
     */
    public function enqueue_dynamic_styles(): void {
        if ( ! $this->should_apply_branding() ) {
            return;
        }

        // Output CSS variables inline.
        $css = $this->generate_css_variables();

        wp_add_inline_style( 'cap-admin-style', $css );
    }

    /**
     * Generate CSS variables from config.
     *
     * @return string CSS.
     */
    public function generate_css_variables(): string {
        $config = $this->get_config();

        $css = ':root {' . "\n";

        // Map config keys to CSS variable names.
        $var_map = array(
            'bg_main'       => '--cap-bg-main',
            'bg_card'       => '--cap-bg-card',
            'bg_input'      => '--cap-bg-input',
            'bg_hover'      => '--cap-bg-hover',
            'bg_header'     => '--cap-bg-header',
            'text_main'     => '--cap-text-main',
            'text_muted'    => '--cap-text-muted',
            'text_faint'    => '--cap-text-faint',
            'accent'        => '--cap-accent',
            'accent_hover'  => '--cap-accent-hover',
            'border'        => '--cap-border',
            'border_light'  => '--cap-border-light',
            'success'       => '--cap-success',
            'warning'       => '--cap-warning',
            'error'         => '--cap-error',
            'info'          => '--cap-info',
        );

        foreach ( $var_map as $key => $var_name ) {
            if ( ! empty( $config[ $key ] ) ) {
                $css .= "    {$var_name}: {$config[ $key ]};\n";
            }
        }

        // Add computed variables.
        $css .= "    --cap-accent-glow: rgba(" . $this->hex_to_rgb( $config['accent'] ?? '#ff5722' ) . ", 0.35);\n";
        $css .= "    --cap-success-bg: rgba(" . $this->hex_to_rgb( $config['success'] ?? '#4caf50' ) . ", 0.15);\n";
        $css .= "    --cap-warning-bg: rgba(" . $this->hex_to_rgb( $config['warning'] ?? '#ff9800' ) . ", 0.15);\n";
        $css .= "    --cap-error-bg: rgba(" . $this->hex_to_rgb( $config['error'] ?? '#f44336' ) . ", 0.15);\n";
        $css .= "    --cap-info-bg: rgba(" . $this->hex_to_rgb( $config['info'] ?? '#2196f3' ) . ", 0.15);\n";

        $css .= "}\n";

        return $css;
    }

    /**
     * Convert hex to RGB string.
     *
     * @param string $hex Hex color.
     * @return string RGB values (e.g., "255, 87, 34").
     */
    private function hex_to_rgb( string $hex ): string {
        $hex = ltrim( $hex, '#' );

        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );

        return "{$r}, {$g}, {$b}";
    }

    /**
     * Output custom CSS.
     */
    public function output_custom_css(): void {
        if ( ! $this->should_apply_branding() ) {
            return;
        }

        $config     = $this->get_config();
        $custom_css = $config['custom_css'] ?? '';

        if ( ! empty( $custom_css ) ) {
            echo '<style id="cap-custom-css">' . "\n";
            echo wp_strip_all_tags( $custom_css );
            echo "\n</style>\n";
        }
    }

    /**
     * Add body classes.
     *
     * @param string $classes Existing classes.
     * @return string Modified classes.
     */
    public function add_body_classes( string $classes ): string {
        if ( ! $this->should_apply_branding() ) {
            return $classes;
        }

        $config = $this->get_config();

        // Add dark theme class if applicable.
        if ( $this->is_dark_theme() ) {
            $classes .= ' cap-dark-theme';
        } else {
            $classes .= ' cap-light-theme';
        }

        // Add preset class.
        $preset = $config['theme_preset'] ?? 'dark_space';
        $classes .= ' cap-theme-' . sanitize_html_class( $preset );

        return $classes;
    }

    /**
     * Enqueue login styles.
     */
    public function enqueue_login_styles(): void {
        // Always apply to login page.
        wp_enqueue_style(
            'cap-login-style',
            CAP_PLUGIN_URL . 'assets/css/login-style.css',
            array(),
            CAP_VERSION
        );
    }

    /**
     * Output login page styles.
     */
    public function output_login_styles(): void {
        $config = $this->get_config();

        echo '<style id="cap-login-branding">' . "\n";

        // Background.
        echo "body.login { background-color: {$config['bg_main']}; }\n";

        // Logo.
        $logo_url    = $config['logo_url'] ?? '';
        $logo_height = $config['logo_height'] ?? '60';

        if ( ! empty( $logo_url ) ) {
            echo "#login h1 a {
                background-image: url('{$logo_url}');
                background-size: contain;
                background-repeat: no-repeat;
                background-position: center;
                width: 100%;
                height: {$logo_height}px;
            }\n";
        }

        // Form styling.
        echo ".login form {
            background: {$config['bg_card']};
            border-color: {$config['border']};
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }\n";

        echo ".login label { color: {$config['text_main']}; }\n";

        echo ".login input[type='text'],
              .login input[type='password'] {
            background: {$config['bg_input']};
            border-color: {$config['border']};
            color: {$config['text_main']};
        }\n";

        echo ".login .button-primary {
            background: {$config['accent']};
            border-color: {$config['accent']};
            color: #fff;
        }\n";

        echo ".login .button-primary:hover {
            background: {$config['accent_hover']};
            border-color: {$config['accent_hover']};
        }\n";

        echo ".login #nav a,
              .login #backtoblog a {
            color: {$config['text_muted']};
        }\n";

        echo ".login #nav a:hover,
              .login #backtoblog a:hover {
            color: {$config['accent']};
        }\n";

        echo '</style>' . "\n";
    }

    /**
     * Custom footer text.
     *
     * @param string $text Default footer text.
     * @return string Modified footer text.
     */
    public function custom_footer_text( string $text ): string {
        if ( ! $this->should_apply_branding() ) {
            return $text;
        }

        $config      = $this->get_config();
        $custom_text = $config['admin_footer'] ?? '';

        if ( ! empty( $custom_text ) ) {
            return wp_kses_post( $custom_text );
        }

        $brand_name = $config['brand_name'] ?? 'Client Portal';

        return sprintf(
            '<span id="footer-thankyou">%s &copy; %s</span>',
            esc_html( $brand_name ),
            gmdate( 'Y' )
        );
    }

    /**
     * Remove WordPress version from footer.
     *
     * @return string Empty string.
     */
    public function remove_footer_version(): string {
        if ( ! $this->should_apply_branding() ) {
            return '';
        }
        return '';
    }

    /**
     * Customize admin bar.
     */
    public function customize_admin_bar(): void {
        global $wp_admin_bar;

        // Remove WordPress logo.
        $wp_admin_bar->remove_node( 'wp-logo' );

        // Add custom logo/brand if configured.
        $config   = $this->get_config();
        $logo_url = $config['logo_url'] ?? '';

        if ( ! empty( $logo_url ) ) {
            $wp_admin_bar->add_node( array(
                'id'    => 'cap-brand',
                'title' => '<img src="' . esc_url( $logo_url ) . '" alt="" style="height: 20px; vertical-align: middle; margin-right: 5px;">',
                'href'  => admin_url(),
            ) );
        }
    }

    /**
     * Save branding settings.
     *
     * @param array       $settings  Settings to save.
     * @param string|null $role_slug Role slug (null for global).
     * @return bool Success.
     */
    public function save( array $settings, ?string $role_slug = null ): bool {
        $allowed_keys = array_keys( $this->get_defaults() );

        foreach ( $settings as $key => $value ) {
            if ( ! in_array( $key, $allowed_keys, true ) ) {
                continue;
            }

            // Sanitize based on type.
            switch ( $key ) {
                case 'logo_url':
                    $value = esc_url_raw( $value );
                    break;
                case 'logo_height':
                    $value = absint( $value );
                    break;
                case 'custom_css':
                    $value = wp_strip_all_tags( $value );
                    break;
                case 'admin_footer':
                    $value = wp_kses_post( $value );
                    break;
                default:
                    if ( strpos( $key, 'bg_' ) === 0 || strpos( $key, 'text_' ) === 0 ||
                         in_array( $key, array( 'accent', 'accent_hover', 'border', 'border_light', 'success', 'warning', 'error', 'info' ), true ) ) {
                        // Color validation.
                        $value = sanitize_hex_color( $value ) ?: $value;
                    } else {
                        $value = sanitize_text_field( $value );
                    }
            }

            $this->settings->set( 'branding', $key, $value, $role_slug );
        }

        // Clear cache.
        $this->cached_config = null;

        return true;
    }

    /**
     * Get current user's primary role.
     *
     * @return string|null Role slug.
     */
    private function get_current_role(): ?string {
        $user = wp_get_current_user();

        if ( ! $user->exists() ) {
            return null;
        }

        return ! empty( $user->roles ) ? reset( $user->roles ) : null;
    }

    /**
     * Clear branding cache.
     */
    public function clear_cache(): void {
        $this->cached_config = null;
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
 * Get branding instance.
 *
 * @return CAP_Branding
 */
function cap_branding(): CAP_Branding {
    return CAP_Branding::instance();
}
