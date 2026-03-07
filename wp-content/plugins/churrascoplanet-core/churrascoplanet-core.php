<?php
/**
 * Plugin Name: ChurrascoPlanet Core
 * Plugin URI: https://www.zentryx.cl
 * Description: Sistema central para ChurrascoPlanet - Gestión de configuración, locales, extras de productos y más.
 * Version: 1.0.0
 * Author: Zentryx SPA
 * Author URI: https://www.zentryx.cl
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: churrascoplanet-core
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.4
 *
 * @package ChurrascoPlanet_Core
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Constantes del plugin
 */
define('CHP_CORE_VERSION', '1.0.0');
define('CHP_CORE_FILE', __FILE__);
define('CHP_CORE_PATH', plugin_dir_path(__FILE__));
define('CHP_CORE_URL', plugin_dir_url(__FILE__));
define('CHP_CORE_BASENAME', plugin_basename(__FILE__));

/**
 * Clase principal del plugin
 */
final class ChurrascoPlanet_Core {

    /**
     * Instancia única
     */
    private static $instance = null;

    /**
     * Versión mínima de PHP
     */
    const MINIMUM_PHP_VERSION = '7.4';

    /**
     * Versión mínima de WordPress
     */
    const MINIMUM_WP_VERSION = '6.0';

    /**
     * Obtener instancia única
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Verificar requisitos
        if (!$this->check_requirements()) {
            return;
        }

        // Cargar archivos
        $this->includes();

        // Hooks de inicialización
        add_action('plugins_loaded', array($this, 'init'), 0);

        // Hooks de activación/desactivación
        register_activation_hook(CHP_CORE_FILE, array($this, 'activate'));
        register_deactivation_hook(CHP_CORE_FILE, array($this, 'deactivate'));

        // Declarar compatibilidad con HPOS de WooCommerce
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }

    /**
     * Verificar requisitos del sistema
     */
    private function check_requirements() {
        // PHP version
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '<')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                printf(
                    __('ChurrascoPlanet Core requiere PHP %s o superior. Tu versión actual es %s.', 'churrascoplanet-core'),
                    self::MINIMUM_PHP_VERSION,
                    PHP_VERSION
                );
                echo '</p></div>';
            });
            return false;
        }

        // WordPress version
        if (version_compare(get_bloginfo('version'), self::MINIMUM_WP_VERSION, '<')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                printf(
                    __('ChurrascoPlanet Core requiere WordPress %s o superior.', 'churrascoplanet-core'),
                    self::MINIMUM_WP_VERSION
                );
                echo '</p></div>';
            });
            return false;
        }

        return true;
    }

    /**
     * Incluir archivos necesarios
     */
    private function includes() {
        // Base de datos
        require_once CHP_CORE_PATH . 'includes/class-database.php';
        require_once CHP_CORE_PATH . 'includes/class-models.php';
        require_once CHP_CORE_PATH . 'includes/class-migration.php';
        require_once CHP_CORE_PATH . 'includes/class-compat.php';

        // Admin
        require_once CHP_CORE_PATH . 'includes/class-admin-options.php';
        require_once CHP_CORE_PATH . 'includes/class-admin-productos.php';

        // Sistema de Clientes
        require_once CHP_CORE_PATH . 'includes/class-customer.php';
        require_once CHP_CORE_PATH . 'includes/class-guest-tracker.php';

        // Mi Cuenta y Rastreo
        require_once CHP_CORE_PATH . 'includes/class-my-account.php';
        require_once CHP_CORE_PATH . 'includes/class-order-tracking.php';

        // Login Social
        require_once CHP_CORE_PATH . 'includes/class-social-login.php';
    }

    /**
     * Inicializar plugin
     */
    public function init() {
        // Cargar traducciones
        load_plugin_textdomain(
            'churrascoplanet-core',
            false,
            dirname(CHP_CORE_BASENAME) . '/languages/'
        );

        // Verificar WooCommerce
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>';
                _e('ChurrascoPlanet Core funciona mejor con WooCommerce. Algunas funcionalidades estarán deshabilitadas.', 'churrascoplanet-core');
                echo '</p></div>';
            });
        } else {
            // Inicializar componentes que requieren WooCommerce
            $this->init_customer_system();
            $this->init_coupon_hooks();
        }

        // Disparar acción para que otros plugins/temas sepan que estamos listos
        do_action('churrascoplanet_core_loaded');
    }

    /**
     * Hooks de configuración de cupones WooCommerce
     */
    private function init_coupon_hooks() {
        $mostrar_carrito  = churrascoplanet_get_option('cupones_mostrar_carrito', '1');
        $mostrar_checkout = churrascoplanet_get_option('cupones_mostrar_checkout', '1');

        if ($mostrar_carrito === '0') {
            add_filter('woocommerce_coupons_enabled', '__return_false');
        }

        if ($mostrar_checkout === '0') {
            add_filter('woocommerce_checkout_coupon_message', '__return_empty_string');
        }
    }

    /**
     * Inicializar sistema de clientes
     */
    private function init_customer_system() {
        // Inicializar modelo de cliente
        if (class_exists('ChurrascoPlanet_Customer')) {
            ChurrascoPlanet_Customer::init();
        }

        // Inicializar rastreador de invitados
        if (class_exists('ChurrascoPlanet_Guest_Tracker')) {
            ChurrascoPlanet_Guest_Tracker::get_instance();
        }

        // Inicializar extensiones de Mi Cuenta
        if (class_exists('ChurrascoPlanet_My_Account')) {
            ChurrascoPlanet_My_Account::get_instance();
        }

        // Inicializar rastreo de pedidos
        if (class_exists('ChurrascoPlanet_Order_Tracking')) {
            ChurrascoPlanet_Order_Tracking::get_instance();
        }

        // Inicializar login social
        if (class_exists('ChurrascoPlanet_Social_Login')) {
            ChurrascoPlanet_Social_Login::get_instance();
        }
    }

    /**
     * Activación del plugin
     */
    public function activate() {
        // Instalar tablas base
        if (class_exists('ChurrascoPlanet_Database')) {
            ChurrascoPlanet_Database::get_instance()->install();
        }

        // Instalar tabla de clientes
        if (class_exists('ChurrascoPlanet_Guest_Tracker')) {
            ChurrascoPlanet_Guest_Tracker::create_table();
        }

        // Limpiar cache de rewrite rules
        flush_rewrite_rules();

        // Guardar versión
        update_option('churrascoplanet_core_version', CHP_CORE_VERSION);

        // Disparar acción de activación
        do_action('churrascoplanet_core_activate');

        // Log
        error_log('ChurrascoPlanet Core: Plugin activado - Versión ' . CHP_CORE_VERSION);
    }

    /**
     * Desactivación del plugin
     */
    public function deactivate() {
        // Limpiar cache de rewrite rules
        flush_rewrite_rules();

        // No eliminamos las tablas para preservar datos
        error_log('ChurrascoPlanet Core: Plugin desactivado');
    }

    /**
     * Verificar si WooCommerce está activo
     */
    public function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Declarar compatibilidad con HPOS de WooCommerce
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                CHP_CORE_FILE,
                true
            );
        }
    }

    /**
     * Obtener URL del plugin
     */
    public function get_plugin_url() {
        return CHP_CORE_URL;
    }

    /**
     * Obtener path del plugin
     */
    public function get_plugin_path() {
        return CHP_CORE_PATH;
    }
}

/**
 * Función para obtener la instancia del plugin
 *
 * @return ChurrascoPlanet_Core
 */
function CHP() {
    return ChurrascoPlanet_Core::get_instance();
}

// Inicializar el plugin
CHP();

/**
 * Register ChurrascoPlanet Core modules with Client Admin Portal
 */
add_action( 'cap_register_modules', function( $registry ) {
	$registry->register( array(
		'slug'          => 'churrascoplanet_options',
		'name'          => __( 'Planeta Churrasco', 'churrascoplanet-core' ),
		'description'   => __( 'Configuración visual de la tienda: Inicio, Navegación, Menú, Promociones, Locales, Extras y Colores', 'churrascoplanet-core' ),
		'plugin_source' => 'ChurrascoPlanet Core',
		'menu_slug'     => 'churrascoplanet-options',
		'capability'    => 'manage_woocommerce',
		'icon'          => 'dashicons-star-filled',
		'sort_order'    => 30,
		'is_core'       => false,
	) );
} );
