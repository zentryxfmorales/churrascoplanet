<?php
/**
 * Integración con Nextend Social Login
 *
 * Maneja la personalización y hooks de integración con
 * el plugin Nextend Social Login para Facebook y Google.
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase ChurrascoPlanet_Social_Login
 *
 * Integración con Nextend Social Login
 */
class ChurrascoPlanet_Social_Login {

    /**
     * Instancia única
     *
     * @var ChurrascoPlanet_Social_Login|null
     */
    private static ?ChurrascoPlanet_Social_Login $instance = null;

    /**
     * Providers soportados
     *
     * @var array
     */
    private array $supported_providers = ['facebook', 'google'];

    /**
     * Obtener instancia única
     *
     * @return ChurrascoPlanet_Social_Login
     */
    public static function get_instance(): ChurrascoPlanet_Social_Login {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Verificar si Nextend Social Login está activo
     *
     * @return bool
     */
    public function is_nextend_active(): bool {
        return class_exists('NextendSocialLogin') || function_exists('NextendSocialLogin');
    }

    /**
     * Inicializar hooks
     */
    private function init_hooks(): void {
        // Solo cargar si Nextend está activo
        add_action('plugins_loaded', [$this, 'init_integration'], 30);
    }

    /**
     * Inicializar integración después de que todos los plugins carguen
     */
    public function init_integration(): void {
        if (!$this->is_nextend_active()) {
            // Mostrar aviso en admin si no está instalado
            add_action('admin_notices', [$this, 'nextend_missing_notice']);
            return;
        }

        // Hooks de Nextend Social Login
        add_action('nsl_registration', [$this, 'on_social_registration'], 10, 3);
        add_action('nsl_login', [$this, 'on_social_login'], 10, 2);
        add_action('nsl_before_register', [$this, 'before_social_register'], 10, 1);

        // Personalización de botones
        add_filter('nsl_buttons_style', [$this, 'customize_buttons_style']);

        // Agregar botones en formulario de login de WooCommerce
        add_action('woocommerce_login_form_end', [$this, 'add_social_buttons_login']);
        add_action('woocommerce_register_form_end', [$this, 'add_social_buttons_register']);

        // Agregar botones en checkout
        add_action('woocommerce_before_checkout_form', [$this, 'add_social_buttons_checkout'], 5);

        // Estilos personalizados
        add_action('wp_enqueue_scripts', [$this, 'enqueue_custom_styles']);

        // Shortcode para botones personalizados
        add_shortcode('chp_social_login', [$this, 'social_login_shortcode']);

        // Sincronizar datos del usuario después de login social
        add_action('nsl_login', [$this, 'sync_user_data_after_login'], 20, 2);
    }

    /**
     * Aviso si Nextend no está instalado
     */
    public function nextend_missing_notice(): void {
        if (!current_user_can('install_plugins')) {
            return;
        }

        $screen = get_current_screen();
        if ($screen && $screen->id !== 'plugins') {
            return;
        }

        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong>ChurrascoPlanet:</strong>
                <?php
                printf(
                    __('Para habilitar el login social (Facebook/Google), instala el plugin %s.', 'churrascoplanet-core'),
                    '<a href="' . admin_url('plugin-install.php?s=nextend+social+login&tab=search&type=term') . '">Nextend Social Login</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Hook cuando un usuario se registra via social
     *
     * @param int    $user_id  ID del usuario.
     * @param string $provider Proveedor (facebook, google).
     * @param mixed  $raw_data Datos crudos del proveedor.
     */
    public function on_social_registration(int $user_id, string $provider, $raw_data): void {
        // Guardar origen del registro
        $provider_lower = strtolower($provider);
        update_user_meta($user_id, 'chp_registration_source', 'social_' . $provider_lower);
        update_user_meta($user_id, 'chp_registration_date', current_time('mysql'));

        // Log
        error_log(sprintf(
            'ChurrascoPlanet: Nuevo usuario registrado via %s - ID: %d',
            $provider,
            $user_id
        ));

        // Disparar acción personalizada
        do_action('chp_social_user_registered', $user_id, $provider, $raw_data);

        // Actualizar tabla de clientes si existe
        if (function_exists('chp_guest_tracker')) {
            $user = get_userdata($user_id);
            if ($user) {
                chp_guest_tracker()->upsert_customer([
                    'email'              => $user->user_email,
                    'first_name'         => $user->first_name,
                    'last_name'          => $user->last_name,
                    'customer_type'      => 'social_' . $provider_lower,
                    'user_id'            => $user_id,
                    'registration_source'=> 'social_' . $provider_lower,
                ]);
            }
        }
    }

    /**
     * Hook cuando un usuario hace login via social
     *
     * @param int    $user_id  ID del usuario.
     * @param string $provider Proveedor.
     */
    public function on_social_login(int $user_id, string $provider): void {
        // Actualizar última fecha de login
        update_user_meta($user_id, 'chp_last_social_login', current_time('mysql'));
        update_user_meta($user_id, 'chp_last_social_provider', strtolower($provider));

        // Disparar acción
        do_action('chp_social_user_logged_in', $user_id, $provider);
    }

    /**
     * Hook antes de registrar usuario social
     *
     * @param mixed $data Datos del registro.
     */
    public function before_social_register($data): void {
        // Aquí se pueden hacer validaciones adicionales
        // Por ejemplo, verificar dominios de email prohibidos
    }

    /**
     * Sincronizar datos del usuario después de login social
     *
     * @param int    $user_id  ID del usuario.
     * @param string $provider Proveedor.
     */
    public function sync_user_data_after_login(int $user_id, string $provider): void {
        // Solo sincronizar si el usuario no tiene datos de WooCommerce
        $billing_first_name = get_user_meta($user_id, 'billing_first_name', true);

        if (empty($billing_first_name)) {
            $user = get_userdata($user_id);
            if ($user) {
                update_user_meta($user_id, 'billing_first_name', $user->first_name);
                update_user_meta($user_id, 'billing_last_name', $user->last_name);
                update_user_meta($user_id, 'billing_email', $user->user_email);
            }
        }
    }

    /**
     * Personalizar estilo de botones
     *
     * @param string $style Estilo actual.
     * @return string
     */
    public function customize_buttons_style(string $style): string {
        // Usar estilo icon (solo iconos) o default
        return 'default';
    }

    /**
     * Agregar botones de login social en formulario de login WooCommerce
     */
    public function add_social_buttons_login(): void {
        if (!$this->is_nextend_active()) {
            return;
        }

        echo '<div class="chp-social-login-wrapper">';
        echo '<div class="chp-social-login-divider"><span>' . esc_html__('o continuar con', 'churrascoplanet-core') . '</span></div>';
        echo '<div class="chp-social-login-buttons">';

        // Renderizar botones de Nextend
        if (function_exists('nsl_action_login_buttons')) {
            nsl_action_login_buttons();
        } elseif (shortcode_exists('nextend_social_login')) {
            echo do_shortcode('[nextend_social_login]');
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Agregar botones de login social en formulario de registro WooCommerce
     */
    public function add_social_buttons_register(): void {
        if (!$this->is_nextend_active()) {
            return;
        }

        echo '<div class="chp-social-login-wrapper chp-social-register">';
        echo '<div class="chp-social-login-divider"><span>' . esc_html__('o registrarse con', 'churrascoplanet-core') . '</span></div>';
        echo '<div class="chp-social-login-buttons">';

        if (function_exists('nsl_action_register_buttons')) {
            nsl_action_register_buttons();
        } elseif (shortcode_exists('nextend_social_login')) {
            echo do_shortcode('[nextend_social_login]');
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Agregar botones en checkout para invitados
     */
    public function add_social_buttons_checkout(): void {
        // Solo mostrar si el usuario no está logueado
        if (is_user_logged_in()) {
            return;
        }

        if (!$this->is_nextend_active()) {
            return;
        }

        ?>
        <div class="chp-checkout-social-login">
            <div class="chp-social-login-notice">
                <p><?php esc_html_e('¿Ya tienes cuenta? Inicia sesión para un checkout más rápido:', 'churrascoplanet-core'); ?></p>
                <div class="chp-social-login-buttons chp-checkout-buttons">
                    <?php
                    if (shortcode_exists('nextend_social_login')) {
                        echo do_shortcode('[nextend_social_login]');
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue estilos personalizados
     */
    public function enqueue_custom_styles(): void {
        if (!$this->is_nextend_active()) {
            return;
        }

        // Solo en páginas relevantes
        if (!is_account_page() && !is_checkout()) {
            return;
        }

        wp_add_inline_style('chp-myaccount', $this->get_custom_css());
    }

    /**
     * Obtener CSS personalizado
     *
     * @return string
     */
    private function get_custom_css(): string {
        return '
            /* Wrapper de login social */
            .chp-social-login-wrapper {
                margin: 25px 0;
                text-align: center;
            }

            /* Divider */
            .chp-social-login-divider {
                position: relative;
                text-align: center;
                margin: 20px 0;
            }

            .chp-social-login-divider::before {
                content: "";
                position: absolute;
                top: 50%;
                left: 0;
                right: 0;
                height: 1px;
                background-color: #ddd;
            }

            .chp-social-login-divider span {
                position: relative;
                background-color: #fff;
                padding: 0 15px;
                color: #666;
                font-size: 14px;
                text-transform: lowercase;
            }

            /* Contenedor de botones */
            .chp-social-login-buttons {
                display: flex;
                justify-content: center;
                gap: 15px;
                flex-wrap: wrap;
                margin-top: 15px;
            }

            /* Estilos base para botones de Nextend */
            .chp-social-login-buttons .nsl-container {
                display: flex;
                justify-content: center;
                gap: 10px;
            }

            .chp-social-login-buttons .nsl-button {
                border-radius: 5px !important;
                min-width: 150px;
                transition: transform 0.2s, box-shadow 0.2s;
            }

            .chp-social-login-buttons .nsl-button:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            }

            /* Checkout social login */
            .chp-checkout-social-login {
                background-color: #f8f9fa;
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 30px;
                text-align: center;
            }

            .chp-checkout-social-login p {
                margin: 0 0 15px;
                color: #495057;
            }

            .chp-checkout-buttons .nsl-button {
                min-width: 120px;
            }

            /* Responsive */
            @media (max-width: 480px) {
                .chp-social-login-buttons {
                    flex-direction: column;
                    align-items: center;
                }

                .chp-social-login-buttons .nsl-button {
                    width: 100%;
                    max-width: 280px;
                }
            }
        ';
    }

    /**
     * Shortcode para mostrar botones de login social
     *
     * @param array $atts Atributos del shortcode.
     * @return string
     */
    public function social_login_shortcode(array $atts = []): string {
        if (!$this->is_nextend_active()) {
            return '';
        }

        if (is_user_logged_in()) {
            return '';
        }

        $atts = shortcode_atts([
            'title'   => __('Iniciar sesión con', 'churrascoplanet-core'),
            'divider' => 'true',
        ], $atts);

        ob_start();
        ?>
        <div class="chp-social-login-wrapper chp-shortcode">
            <?php if ($atts['divider'] === 'true'): ?>
                <div class="chp-social-login-divider"><span><?php echo esc_html($atts['title']); ?></span></div>
            <?php else: ?>
                <p class="chp-social-title"><?php echo esc_html($atts['title']); ?></p>
            <?php endif; ?>
            <div class="chp-social-login-buttons">
                <?php echo do_shortcode('[nextend_social_login]'); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Verificar si un proveedor está habilitado
     *
     * @param string $provider Nombre del proveedor.
     * @return bool
     */
    public function is_provider_enabled(string $provider): bool {
        if (!$this->is_nextend_active()) {
            return false;
        }

        // Verificar en la configuración de Nextend
        // Esto depende de cómo Nextend almacena su configuración
        $provider_lower = strtolower($provider);

        // Verificar si la clase del proveedor existe
        $provider_class = 'NextendSocialProvider' . ucfirst($provider_lower);
        if (!class_exists($provider_class)) {
            return false;
        }

        return true;
    }

    /**
     * Obtener URL de login con proveedor específico
     *
     * @param string $provider Nombre del proveedor.
     * @param string $redirect URL de redirección después de login.
     * @return string
     */
    public function get_provider_login_url(string $provider, string $redirect = ''): string {
        if (!$this->is_nextend_active()) {
            return wp_login_url($redirect);
        }

        $provider_lower = strtolower($provider);
        $login_url = site_url('wp-login.php?loginSocial=' . $provider_lower);

        if ($redirect) {
            $login_url = add_query_arg('redirect', urlencode($redirect), $login_url);
        }

        return $login_url;
    }

    /**
     * Obtener proveedores habilitados
     *
     * @return array
     */
    public function get_enabled_providers(): array {
        $enabled = [];

        foreach ($this->supported_providers as $provider) {
            if ($this->is_provider_enabled($provider)) {
                $enabled[] = $provider;
            }
        }

        return $enabled;
    }

    /**
     * Renderizar botón individual de proveedor
     *
     * @param string $provider  Nombre del proveedor.
     * @param string $button_text Texto del botón.
     * @return string
     */
    public function render_provider_button(string $provider, string $button_text = ''): string {
        if (!$this->is_provider_enabled($provider)) {
            return '';
        }

        $provider_lower = strtolower($provider);

        $icons = [
            'facebook' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.84 3.44 8.87 8 9.8V15H8v-3h2V9.5C10 7.57 11.57 6 13.5 6H16v3h-2c-.55 0-1 .45-1 1v2h3v3h-3v6.95c5.05-.5 9-4.76 9-9.95z"/></svg>',
            'google'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>',
        ];

        $colors = [
            'facebook' => '#1877F2',
            'google'   => '#ffffff',
        ];

        $text_colors = [
            'facebook' => '#ffffff',
            'google'   => '#757575',
        ];

        if (empty($button_text)) {
            $button_text = sprintf(__('Continuar con %s', 'churrascoplanet-core'), ucfirst($provider));
        }

        $url = $this->get_provider_login_url($provider);

        return sprintf(
            '<a href="%s" class="chp-social-button chp-social-%s" style="background-color: %s; color: %s;">
                <span class="chp-social-icon">%s</span>
                <span class="chp-social-text">%s</span>
            </a>',
            esc_url($url),
            esc_attr($provider_lower),
            esc_attr($colors[$provider_lower] ?? '#333'),
            esc_attr($text_colors[$provider_lower] ?? '#fff'),
            $icons[$provider_lower] ?? '',
            esc_html($button_text)
        );
    }
}

/**
 * Función helper para acceder al social login
 *
 * @return ChurrascoPlanet_Social_Login
 */
function chp_social_login(): ChurrascoPlanet_Social_Login {
    return ChurrascoPlanet_Social_Login::get_instance();
}
