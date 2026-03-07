<?php
/**
 * Extensiones de Mi Cuenta para ChurrascoPlanet
 *
 * Agrega nuevos endpoints y personaliza el panel de Mi Cuenta de WooCommerce.
 *
 * @package ChurrascoPlanet_Core
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase ChurrascoPlanet_My_Account
 *
 * Maneja las extensiones del panel Mi Cuenta
 */
class ChurrascoPlanet_My_Account {

    /**
     * Instancia única
     *
     * @var ChurrascoPlanet_My_Account|null
     */
    private static ?ChurrascoPlanet_My_Account $instance = null;

    /**
     * Endpoints personalizados
     *
     * @var array
     */
    private array $endpoints = [
        'order-tracking' => 'order-tracking',
        'my-coupons'     => 'my-coupons',
        'preferences'    => 'preferences',
    ];

    /**
     * Obtener instancia única
     *
     * @return ChurrascoPlanet_My_Account
     */
    public static function get_instance(): ChurrascoPlanet_My_Account {
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
     * Inicializar hooks
     */
    private function init_hooks(): void {
        // Registrar endpoints
        add_action('init', [$this, 'add_endpoints']);

        // Agregar query vars
        add_filter('query_vars', [$this, 'add_query_vars']);

        // Flush rewrite rules al activar
        add_action('churrascoplanet_core_activate', [$this, 'flush_rewrite_rules']);

        // Agregar items al menú de Mi Cuenta
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_items'], 10, 1);

        // Contenido de los endpoints
        add_action('woocommerce_account_order-tracking_endpoint', [$this, 'order_tracking_content']);
        add_action('woocommerce_account_my-coupons_endpoint', [$this, 'my_coupons_content']);
        add_action('woocommerce_account_preferences_endpoint', [$this, 'preferences_content']);

        // Títulos de los endpoints
        add_filter('the_title', [$this, 'endpoint_title'], 10, 2);

        // Personalizar dashboard
        add_action('woocommerce_account_dashboard', [$this, 'custom_dashboard_content'], 5);

        // Enqueue scripts y estilos
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers para preferencias
        add_action('wp_ajax_chp_save_preferences', [$this, 'ajax_save_preferences']);

        // Breadcrumbs
        add_filter('woocommerce_get_breadcrumb', [$this, 'custom_breadcrumbs'], 10, 2);

        // Checkout: checkbox de marketing y campos extra
        add_action('woocommerce_review_order_before_submit', [$this, 'add_checkout_marketing_checkbox']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_checkout_marketing_checkbox'], 10, 2);

        // Habilitar registro en WooCommerce por defecto
        add_filter('woocommerce_registration_redirect', [$this, 'registration_redirect']);

        // Personalizar campos del formulario de registro WooCommerce
        add_action('woocommerce_register_form_start', [$this, 'add_register_form_fields']);
        add_action('woocommerce_register_form', [$this, 'add_register_phone_field']);
        add_action('woocommerce_register_post', [$this, 'validate_register_fields'], 10, 3);
        add_action('woocommerce_created_customer', [$this, 'save_register_fields'], 10, 1);
    }

    /**
     * Registrar endpoints
     */
    public function add_endpoints(): void {
        foreach ($this->endpoints as $endpoint => $slug) {
            add_rewrite_endpoint($slug, EP_ROOT | EP_PAGES);
        }
    }

    /**
     * Agregar query vars
     *
     * @param array $vars Query vars existentes.
     * @return array
     */
    public function add_query_vars(array $vars): array {
        foreach ($this->endpoints as $endpoint => $slug) {
            $vars[] = $slug;
        }
        return $vars;
    }

    /**
     * Flush rewrite rules
     */
    public function flush_rewrite_rules(): void {
        $this->add_endpoints();
        flush_rewrite_rules();
    }

    /**
     * Agregar items al menú de Mi Cuenta
     *
     * @param array $items Items del menú.
     * @return array
     */
    public function add_menu_items(array $items): array {
        // Reorganizar items
        $new_items = [];

        foreach ($items as $key => $label) {
            $new_items[$key] = $label;

            // Agregar rastreo después de pedidos
            if ($key === 'orders') {
                $new_items['order-tracking'] = __('Rastrear Pedido', 'churrascoplanet-core');
            }

            // Agregar cupones después de descargas
            if ($key === 'downloads') {
                $new_items['my-coupons'] = __('Mis Cupones', 'churrascoplanet-core');
            }

            // Agregar preferencias antes de cerrar sesión
            if ($key === 'edit-account') {
                $new_items['preferences'] = __('Preferencias', 'churrascoplanet-core');
            }
        }

        return $new_items;
    }

    /**
     * Título de los endpoints
     *
     * @param string $title Título actual.
     * @param int    $id    ID del post.
     * @return string
     */
    public function endpoint_title(string $title, int $id = 0): string {
        global $wp_query;

        if (!is_admin() && is_main_query() && in_the_loop() && is_account_page()) {
            if (isset($wp_query->query_vars['order-tracking'])) {
                return __('Rastrear Pedido', 'churrascoplanet-core');
            }
            if (isset($wp_query->query_vars['my-coupons'])) {
                return __('Mis Cupones', 'churrascoplanet-core');
            }
            if (isset($wp_query->query_vars['preferences'])) {
                return __('Preferencias', 'churrascoplanet-core');
            }
        }

        return $title;
    }

    /**
     * Contenido del dashboard personalizado
     */
    public function custom_dashboard_content(): void {
        $customer_id = get_current_user_id();
        if (!$customer_id) {
            return;
        }

        $customer = new ChurrascoPlanet_Customer($customer_id);
        $wc_customer = $customer->get_wc_customer();

        if (!$wc_customer) {
            return;
        }

        // Obtener estadísticas
        $total_orders = $customer->get_total_orders();
        $total_spent = $customer->get_total_spent();
        $last_order_date = $customer->get_last_order_date();
        $available_coupons = count($customer->get_available_coupons());

        // Obtener último pedido con delivery
        $recent_orders = wc_get_orders([
            'customer_id' => $customer_id,
            'limit'       => 1,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'meta_key'    => '_uber_delivery_id',
            'meta_compare'=> 'EXISTS',
        ]);

        $tracking_order = !empty($recent_orders) ? $recent_orders[0] : null;

        include CHP_CORE_PATH . 'templates/myaccount/dashboard-custom.php';
    }

    /**
     * Contenido de rastreo de pedido
     */
    public function order_tracking_content(): void {
        global $wp_query;

        $order_id = absint($wp_query->query_vars['order-tracking']);
        $customer_id = get_current_user_id();

        if ($order_id) {
            // Mostrar rastreo de pedido específico
            $order = wc_get_order($order_id);

            // Verificar que el pedido pertenece al cliente
            if (!$order || $order->get_customer_id() !== $customer_id) {
                wc_print_notice(__('No tienes permiso para ver este pedido.', 'churrascoplanet-core'), 'error');
                return;
            }

            // Verificar que tiene delivery Uber
            $uber_delivery_id = $order->get_meta('_uber_delivery_id');
            if (!$uber_delivery_id) {
                wc_print_notice(__('Este pedido no tiene información de rastreo disponible.', 'churrascoplanet-core'), 'notice');
                return;
            }

            include CHP_CORE_PATH . 'templates/myaccount/order-tracking.php';
        } else {
            // Mostrar lista de pedidos rastreables
            $orders = wc_get_orders([
                'customer_id'  => $customer_id,
                'limit'        => 10,
                'orderby'      => 'date',
                'order'        => 'DESC',
                'meta_key'     => '_uber_delivery_id',
                'meta_compare' => 'EXISTS',
                'status'       => ['wc-processing', 'wc-on-hold', 'wc-pending'],
            ]);

            include CHP_CORE_PATH . 'templates/myaccount/order-tracking-list.php';
        }
    }

    /**
     * Contenido de mis cupones
     */
    public function my_coupons_content(): void {
        $customer_id = get_current_user_id();

        if (!$customer_id) {
            wc_print_notice(__('Debes iniciar sesión para ver tus cupones.', 'churrascoplanet-core'), 'error');
            return;
        }

        $customer = new ChurrascoPlanet_Customer($customer_id);
        $coupons = $customer->get_available_coupons();

        include CHP_CORE_PATH . 'templates/myaccount/my-coupons.php';
    }

    /**
     * Contenido de preferencias
     */
    public function preferences_content(): void {
        $customer_id = get_current_user_id();

        if (!$customer_id) {
            wc_print_notice(__('Debes iniciar sesión para ver tus preferencias.', 'churrascoplanet-core'), 'error');
            return;
        }

        $customer = new ChurrascoPlanet_Customer($customer_id);

        // Obtener locales disponibles
        $stores = [];
        if (class_exists('ChurrascoPlanet_Models')) {
            $models = ChurrascoPlanet_Models::get_instance();
            $stores = $models->get_locales(['activo' => 1]);
        }

        // Procesar formulario si se envió
        if (isset($_POST['chp_save_preferences']) && wp_verify_nonce($_POST['chp_preferences_nonce'], 'chp_save_preferences')) {
            $this->process_preferences_form($customer);
        }

        include CHP_CORE_PATH . 'templates/myaccount/preferences.php';
    }

    /**
     * Procesar formulario de preferencias
     *
     * @param ChurrascoPlanet_Customer $customer Cliente.
     */
    private function process_preferences_form(ChurrascoPlanet_Customer $customer): void {
        // Local favorito
        if (isset($_POST['preferred_store_id'])) {
            $store_id = absint($_POST['preferred_store_id']);
            $customer->set_preferred_store_id($store_id);
        }

        // Notificaciones email
        $email_notifications = isset($_POST['notification_email']) ? true : false;
        $customer->set_email_notifications($email_notifications);

        // Notificaciones WhatsApp
        $whatsapp_notifications = isset($_POST['notification_whatsapp']) ? true : false;
        $customer->set_whatsapp_notifications($whatsapp_notifications);

        // Número WhatsApp
        if (isset($_POST['whatsapp_number'])) {
            $customer->set_whatsapp_number(sanitize_text_field($_POST['whatsapp_number']));
        }

        // Actualizar en tabla de clientes
        if (function_exists('chp_guest_tracker')) {
            $user = get_userdata($customer->get_id());
            if ($user) {
                chp_guest_tracker()->upsert_customer([
                    'email'             => $user->user_email,
                    'preferred_store_id'=> $customer->get_preferred_store_id(),
                    'accepts_whatsapp'  => $whatsapp_notifications ? 1 : 0,
                    'whatsapp'          => $customer->get_whatsapp_number(),
                ]);
            }
        }

        wc_add_notice(__('Preferencias guardadas correctamente.', 'churrascoplanet-core'), 'success');
    }

    /**
     * Handler AJAX para guardar preferencias
     */
    public function ajax_save_preferences(): void {
        check_ajax_referer('chp_preferences_nonce', 'nonce');

        $customer_id = get_current_user_id();
        if (!$customer_id) {
            wp_send_json_error(['message' => __('No autorizado.', 'churrascoplanet-core')]);
        }

        $customer = new ChurrascoPlanet_Customer($customer_id);

        // Procesar campos enviados
        if (isset($_POST['preferred_store_id'])) {
            $customer->set_preferred_store_id(absint($_POST['preferred_store_id']));
        }

        if (isset($_POST['notification_email'])) {
            $customer->set_email_notifications($_POST['notification_email'] === 'true');
        }

        if (isset($_POST['notification_whatsapp'])) {
            $customer->set_whatsapp_notifications($_POST['notification_whatsapp'] === 'true');
        }

        if (isset($_POST['whatsapp_number'])) {
            $customer->set_whatsapp_number(sanitize_text_field($_POST['whatsapp_number']));
        }

        wp_send_json_success(['message' => __('Preferencias guardadas.', 'churrascoplanet-core')]);
    }

    /**
     * Enqueue scripts y estilos
     */
    public function enqueue_assets(): void {
        if (!is_account_page()) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'chp-myaccount',
            CHP_CORE_URL . 'assets/css/myaccount.css',
            [],
            CHP_CORE_VERSION
        );

        // intl-tel-input para formulario de registro (solo si no está logueado)
        if (!is_user_logged_in()) {
            wp_enqueue_style(
                'intl-tel-input',
                'https://cdn.jsdelivr.net/npm/intl-tel-input@21/build/css/intlTelInput.min.css',
                [],
                '21.0'
            );
            wp_enqueue_script(
                'intl-tel-input',
                'https://cdn.jsdelivr.net/npm/intl-tel-input@21/build/js/intlTelInput.min.js',
                [],
                '21.0',
                true
            );
        }

        // JS de preferencias
        global $wp_query;
        if (isset($wp_query->query_vars['preferences'])) {
            wp_enqueue_script(
                'chp-preferences',
                CHP_CORE_URL . 'assets/js/preferences.js',
                ['jquery'],
                CHP_CORE_VERSION,
                true
            );

            wp_localize_script('chp-preferences', 'chpPreferences', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('chp_preferences_nonce'),
            ]);
        }

        // JS de rastreo
        if (isset($wp_query->query_vars['order-tracking']) && $wp_query->query_vars['order-tracking']) {
            wp_enqueue_script(
                'chp-order-tracking',
                CHP_CORE_URL . 'assets/js/order-tracking.js',
                ['jquery'],
                CHP_CORE_VERSION,
                true
            );

            wp_localize_script('chp-order-tracking', 'chpTracking', [
                'restUrl'      => rest_url('chp/v1/'),
                'nonce'        => wp_create_nonce('wp_rest'),
                'orderId'      => absint($wp_query->query_vars['order-tracking']),
                'pollInterval' => 30000, // 30 segundos
                'strings'      => [
                    'error'     => __('Error al obtener estado del pedido.', 'churrascoplanet-core'),
                    'pending'   => __('Confirmado', 'churrascoplanet-core'),
                    'pickup'    => __('En preparación', 'churrascoplanet-core'),
                    'pickup_complete' => __('Recogido', 'churrascoplanet-core'),
                    'dropoff'   => __('En camino', 'churrascoplanet-core'),
                    'delivered' => __('Entregado', 'churrascoplanet-core'),
                    'canceled'  => __('Cancelado', 'churrascoplanet-core'),
                ],
            ]);
        }
    }

    /**
     * Personalizar breadcrumbs
     *
     * @param array                 $crumbs Breadcrumbs actuales.
     * @param WC_Breadcrumb|null    $breadcrumb Objeto breadcrumb.
     * @return array
     */
    public function custom_breadcrumbs(array $crumbs, $breadcrumb = null): array {
        global $wp_query;

        if (is_account_page()) {
            if (isset($wp_query->query_vars['order-tracking'])) {
                $crumbs[] = [__('Rastrear Pedido', 'churrascoplanet-core'), ''];
            }
            if (isset($wp_query->query_vars['my-coupons'])) {
                $crumbs[] = [__('Mis Cupones', 'churrascoplanet-core'), ''];
            }
            if (isset($wp_query->query_vars['preferences'])) {
                $crumbs[] = [__('Preferencias', 'churrascoplanet-core'), ''];
            }
        }

        return $crumbs;
    }

    /**
     * Obtener URL de un endpoint
     *
     * @param string $endpoint Nombre del endpoint.
     * @return string
     */
    public static function get_endpoint_url(string $endpoint): string {
        return wc_get_account_endpoint_url($endpoint);
    }

    // =========================================================================
    // Checkout: Checkbox de marketing
    // =========================================================================

    /**
     * Agregar checkbox de marketing en el checkout
     */
    public function add_checkout_marketing_checkbox(): void {
        ?>
        <div class="chp-checkout-marketing">
            <p class="form-row">
                <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                    <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"
                           name="chp_accepts_marketing" id="chp_accepts_marketing" value="1">
                    <span class="chp-marketing-text">
                        <?php esc_html_e('Quiero recibir ofertas, descuentos y novedades de ChurrascoPlanet por correo electrónico.', 'churrascoplanet-core'); ?>
                    </span>
                </label>
            </p>
        </div>
        <?php
    }

    /**
     * Guardar checkbox de marketing al procesar el checkout
     *
     * @param int   $order_id ID del pedido.
     * @param array $data     Datos del checkout.
     */
    public function save_checkout_marketing_checkbox(int $order_id, $data = []): void {
        $accepts = isset($_POST['chp_accepts_marketing']) ? 1 : 0;

        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('_chp_accepts_marketing', $accepts);
            $order->save();
        }
    }

    /**
     * Redirección después de registro
     *
     * @param string $redirect URL de redirección.
     * @return string
     */
    public function registration_redirect(string $redirect): string {
        return wc_get_account_endpoint_url('dashboard');
    }

    // =========================================================================
    // Formulario de registro WooCommerce: campos extra
    // =========================================================================

    /**
     * Agregar campos nombre/apellido al formulario de registro (antes del email)
     */
    public function add_register_form_fields(): void {
        ?>
        <div class="chp-register-row">
            <p class="woocommerce-form-row form-row chp-register-col">
                <input type="text" class="woocommerce-Input woocommerce-Input--text input-text chp-input-icon" name="first_name" id="reg_first_name"
                       autocomplete="given-name" maxlength="50"
                       placeholder="<?php esc_attr_e('Nombre *', 'churrascoplanet-core'); ?>"
                       value="<?php echo isset($_POST['first_name']) ? esc_attr(sanitize_text_field(wp_unslash($_POST['first_name']))) : ''; ?>" />
                <i class="fas fa-user chp-field-icon"></i>
            </p>
            <p class="woocommerce-form-row form-row chp-register-col">
                <input type="text" class="woocommerce-Input woocommerce-Input--text input-text chp-input-icon" name="last_name" id="reg_last_name"
                       autocomplete="family-name" maxlength="50"
                       placeholder="<?php esc_attr_e('Apellido *', 'churrascoplanet-core'); ?>"
                       value="<?php echo isset($_POST['last_name']) ? esc_attr(sanitize_text_field(wp_unslash($_POST['last_name']))) : ''; ?>" />
                <i class="fas fa-user chp-field-icon"></i>
            </p>
        </div>
        <?php
    }

    /**
     * Agregar campo teléfono al formulario de registro (después del email/password)
     */
    public function add_register_phone_field(): void {
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide chp-phone-row">
            <input type="tel" class="chp-phone-input" name="billing_phone_display" id="reg_phone"
                   autocomplete="tel"
                   placeholder="<?php esc_attr_e('Teléfono *', 'churrascoplanet-core'); ?>"
                   value="<?php echo isset($_POST['billing_phone_display']) ? esc_attr(sanitize_text_field(wp_unslash($_POST['billing_phone_display']))) : ''; ?>" />
            <input type="hidden" name="billing_phone" id="reg_phone_full"
                   value="<?php echo isset($_POST['billing_phone']) ? esc_attr(sanitize_text_field(wp_unslash($_POST['billing_phone']))) : ''; ?>" />
            <input type="hidden" name="billing_phone_code" id="reg_phone_code"
                   value="<?php echo isset($_POST['billing_phone_code']) ? esc_attr(sanitize_text_field(wp_unslash($_POST['billing_phone_code']))) : ''; ?>" />
        </p>
        <?php
    }

    /**
     * Validar campos de registro con seguridad robusta
     *
     * @param string   $username  Username.
     * @param string   $email     Email.
     * @param WP_Error $errors    Errores de validación.
     */
    public function validate_register_fields(string $username, string $email, WP_Error $errors): void {
        // Verificar nonce CSRF (WooCommerce lo genera, nosotros lo verificamos explícitamente)
        if (!isset($_POST['woocommerce-register-nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woocommerce-register-nonce'])), 'woocommerce-register')) {
            $errors->add('nonce_error', __('Error de seguridad. Recarga la página e intenta de nuevo.', 'churrascoplanet-core'));
            return;
        }

        // Regex: solo letras, espacios, acentos, ñ
        $name_regex = '/^[\p{L}\s\'\-]+$/u';

        // --- Nombre ---
        $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $first_name = wp_kses($first_name, []);

        if (empty($first_name)) {
            $errors->add('first_name_error', __('El nombre es obligatorio.', 'churrascoplanet-core'));
        } elseif (mb_strlen($first_name) > 50) {
            $errors->add('first_name_error', __('El nombre no puede exceder 50 caracteres.', 'churrascoplanet-core'));
        } elseif (!preg_match($name_regex, $first_name)) {
            $errors->add('first_name_error', __('El nombre solo puede contener letras y espacios.', 'churrascoplanet-core'));
        }

        // --- Apellido ---
        $last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $last_name = wp_kses($last_name, []);

        if (empty($last_name)) {
            $errors->add('last_name_error', __('El apellido es obligatorio.', 'churrascoplanet-core'));
        } elseif (mb_strlen($last_name) > 50) {
            $errors->add('last_name_error', __('El apellido no puede exceder 50 caracteres.', 'churrascoplanet-core'));
        } elseif (!preg_match($name_regex, $last_name)) {
            $errors->add('last_name_error', __('El apellido solo puede contener letras y espacios.', 'churrascoplanet-core'));
        }

        // --- Teléfono ---
        $phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        // Strip shell chars peligrosos para anti-RCE
        $phone = preg_replace('/[`$\\\\|;&]/', '', $phone);

        if (empty($phone)) {
            $errors->add('phone_error', __('El teléfono es obligatorio.', 'churrascoplanet-core'));
        } elseif (!preg_match('/^[\d\s\+\-\(\)]{8,20}$/', $phone)) {
            $errors->add('phone_error', __('Ingresa un número de teléfono válido (8-20 caracteres).', 'churrascoplanet-core'));
        }
    }

    /**
     * Guardar campos de registro
     *
     * @param int $customer_id ID del cliente.
     */
    public function save_register_fields(int $customer_id): void {
        if (isset($_POST['first_name'])) {
            $first_name = sanitize_text_field(wp_unslash($_POST['first_name']));
            update_user_meta($customer_id, 'first_name', $first_name);
            update_user_meta($customer_id, 'billing_first_name', $first_name);
        }
        if (isset($_POST['last_name'])) {
            $last_name = sanitize_text_field(wp_unslash($_POST['last_name']));
            update_user_meta($customer_id, 'last_name', $last_name);
            update_user_meta($customer_id, 'billing_last_name', $last_name);
        }
        if (isset($_POST['billing_phone'])) {
            $phone = sanitize_text_field(wp_unslash($_POST['billing_phone']));
            update_user_meta($customer_id, 'billing_phone', $phone);
        }
        if (isset($_POST['billing_phone_code'])) {
            $phone_code = sanitize_text_field(wp_unslash($_POST['billing_phone_code']));
            update_user_meta($customer_id, 'billing_phone_code', $phone_code);
        }

        // Guardar en tabla de clientes
        if (function_exists('chp_guest_tracker')) {
            $user = get_userdata($customer_id);
            if ($user) {
                chp_guest_tracker()->upsert_customer([
                    'email'              => $user->user_email,
                    'first_name'         => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
                    'last_name'          => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
                    'phone'              => sanitize_text_field(wp_unslash($_POST['billing_phone'] ?? '')),
                    'customer_type'      => 'registered',
                    'user_id'            => $customer_id,
                    'registration_source'=> 'woocommerce_myaccount',
                ]);
            }
        }
    }
}

/**
 * Función helper para acceder a Mi Cuenta
 *
 * @return ChurrascoPlanet_My_Account
 */
function chp_my_account(): ChurrascoPlanet_My_Account {
    return ChurrascoPlanet_My_Account::get_instance();
}
