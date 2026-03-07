<?php
/**
 * Plugin Name: RestoHub
 * Plugin URI: https://github.com/zentryx/restohub
 * Description: Integración de Uber Direct con WooCommerce para delivery de restaurante.
 * Version: 1.1.4
 * Author: Zentryx
 * Author URI: https://zentryx.cl
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: restohub
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constantes del plugin (seguro definir inmediatamente)
 */
define( 'RESTOHUB_VERSION', '1.1.4' );
define( 'RESTOHUB_PLUGIN_FILE', __FILE__ );
define( 'RESTOHUB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RESTOHUB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RESTOHUB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Clase principal del plugin - Singleton
 */
final class RestoHub {

	/**
	 * Instancia única del plugin
	 *
	 * @var RestoHub|null
	 */
	private static ?RestoHub $instance = null;

	/**
	 * Instancia de la clase API de Uber
	 *
	 * @var RestoHub_Uber_API|null
	 */
	public ?RestoHub_Uber_API $api = null;

	/**
	 * Grupo de acciones programadas (compartido con RestoHub_Uber_Logistics)
	 */
	public const SCHEDULED_ACTION_GROUP = 'restohub_deliveries';

	/**
	 * Obtiene la instancia única del plugin (Singleton)
	 *
	 * @return RestoHub
	 */
	public static function instance(): RestoHub {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor privado para prevenir instanciación directa
	 */
	private function __construct() {
		$this->define_hooks();
	}

	/**
	 * Prevenir clonación de la instancia
	 */
	private function __clone() {}

	/**
	 * Prevenir deserialización de la instancia
	 *
	 * @throws \Exception Siempre.
	 */
	public function __wakeup() {
		throw new \Exception( 'No se puede deserializar una instancia Singleton.' );
	}

	/**
	 * Define todos los hooks del plugin
	 *
	 * Solo registra hooks, NO ejecuta lógica pesada aquí.
	 */
	private function define_hooks(): void {
		// Cargar traducciones en el momento correcto
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Inicialización principal después de que todos los plugins estén cargados
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );

		// Cargar clases de shipping DESPUÉS de que WooCommerce inicialice sus clases
		add_action( 'woocommerce_shipping_init', array( $this, 'load_shipping_classes' ) );

		// Registrar métodos de envío en WooCommerce
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_methods' ) );

		// Registrar pasarela de pago SumUp en WooCommerce
		add_filter( 'woocommerce_payment_gateways', array( 'RestoHub_SumUp_Gateway', 'register' ) );

		// Los hooks de delivery (woocommerce_payment_complete + AS action) los
		// registra RestoHub_Uber_Logistics desde su constructor en init().

		// Copiar campos de facturación al envío si el envío está vacío.
		// Necesario porque RestoHub usa un único formulario de dirección
		// (billing) y Uber Direct necesita la shipping address para el dispatch.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'copy_billing_to_shipping' ), 10, 2 );
	}

	/**
	 * Comprueba si WooCommerce está activo
	 *
	 * @return bool
	 */
	private function is_woocommerce_active(): bool {
		return in_array(
			'woocommerce/woocommerce.php',
			apply_filters( 'active_plugins', get_option( 'active_plugins' ) ),
			true
		);
	}

	/**
	 * Muestra aviso si WooCommerce no está activo
	 */
	public function woocommerce_missing_notice(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: WooCommerce plugin name */
					esc_html__( '%s requiere que WooCommerce esté instalado y activo.', 'restohub' ),
					'<strong>RestoHub</strong>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Inicializa el plugin después de que todos los plugins estén cargados
	 *
	 * Hook: plugins_loaded (prioridad 20)
	 */
	public function init(): void {
		// Verificar dependencia de WooCommerce
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		// Incluir archivos necesarios
		$this->includes();

		// Inicializar la API de Uber
		$this->api = new RestoHub_Uber_API();

		// Inicializar el Motor Logístico (registra hooks de delivery y reintentos)
		new RestoHub_Uber_Logistics( $this->api );

		// Inicializar la UI de Delivery Tracking (thank you, view order, email, Mi Cuenta)
		new RestoHub_Delivery_UI();

		// Inicializar el receptor de webhooks de Uber Direct (?wc-api=restohub_uber_webhook)
		new RestoHub_Uber_Webhook();

		// Inicializar el webhook de SumUp después de que WooCommerce esté completamente
		// cargado (woocommerce_init), para que wc_get_logger() y otras funciones WC
		// estén disponibles y el text domain de WooCommerce ya esté registrado.
		// Esto evita el notice "woocommerce domain loaded too early" en WordPress 6.7+.
		add_action( 'woocommerce_init', function () {
			new RestoHub_SumUp_Webhook( new RestoHub_SumUp_API() );
		} );

		// Inicializar configuración de admin
		if ( is_admin() ) {
			new RestoHub_Admin_Settings();
		}

		// Inicializar componentes frontend
		if ( ! is_admin() || wp_doing_ajax() ) {
			// Modal de ubicación - integrado con el botón Delivery del tema
			new RestoHub_Location_Modal();

			// Checkout Map - para seleccionar dirección en el checkout
			new RestoHub_Checkout_Map();

			// Checkout Fees - cuota de servicio y propina
			new RestoHub_Checkout_Fees();

			// Auth Manager - modal de login/registro en checkout
			new RestoHub_Auth_Manager();

			// Frontend Storefront - estilo app de delivery (desactivado, tema tiene su propio diseño)
			// new RestoHub_Frontend_Storefront();
		}
	}

	/**
	 * Incluye los archivos necesarios del plugin
	 *
	 * NOTA: Las clases de shipping se cargan en load_shipping_classes()
	 * mediante el hook 'woocommerce_shipping_init'.
	 */
	private function includes(): void {
		// Clases de base de datos y repositorios (cargar primero)
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-installer.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-store-repository.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-checkout-fees-repository.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-auth-events-repository.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-checkout-fees.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-auth-manager.php';

		// Verificar si necesita actualización de BD (para actualizaciones via FTP)
		RestoHub_Installer::maybe_update();

		// Pasarela de pago SumUp (orden: API → Webhook → Gateway)
		require_once RESTOHUB_PLUGIN_DIR . 'payments/class-restohub-sumup-api.php';
		require_once RESTOHUB_PLUGIN_DIR . 'payments/class-restohub-sumup-webhook.php';
		require_once RESTOHUB_PLUGIN_DIR . 'payments/class-restohub-sumup-gateway.php';

		// Clases del núcleo (NO incluir shipping methods aquí)
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-uber-api.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-restohub-uber-logistics.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-restohub-delivery-ui.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-api-tester.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-restohub-uber-webhook.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-polygon-validator.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-geo-validator.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-checkout-map.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-location-modal.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-scheduling.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-frontend-storefront.php';

		// Clases de administración
		if ( is_admin() ) {
			require_once RESTOHUB_PLUGIN_DIR . 'admin/class-admin-settings.php';
		}
	}

	/**
	 * Carga las clases de métodos de envío
	 *
	 * Hook: woocommerce_shipping_init
	 * Se ejecuta DESPUÉS de que WC_Shipping_Method esté disponible.
	 */
	public function load_shipping_classes(): void {
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-shipping-method.php';
		require_once RESTOHUB_PLUGIN_DIR . 'includes/class-pickup-shipping.php';
	}

	/**
	 * Carga los archivos de traducción
	 *
	 * Hook: init
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'restohub',
			false,
			dirname( RESTOHUB_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Registra los métodos de envío en WooCommerce
	 *
	 * Hook: woocommerce_shipping_methods
	 *
	 * @param array $methods Métodos de envío existentes.
	 * @return array
	 */
	public function register_shipping_methods( array $methods ): array {
		$methods['uber_direct']           = 'RestoHub_Shipping_Method';
		$methods['local_pickup_restohub'] = 'RestoHub_Pickup_Shipping_Method';
		return $methods;
	}

	/**
	 * Verifica si un pedido tiene delivery pendiente de crear.
	 *
	 * @param int $order_id ID del pedido.
	 * @return bool
	 */
	public function has_pending_delivery( int $order_id ): bool {
		return as_has_scheduled_action(
			RestoHub_Uber_Logistics::ACTION_CREATE,
			array( 'order_id' => $order_id ),
			self::SCHEDULED_ACTION_GROUP
		);
	}

	/**
	 * Reintenta manualmente crear un delivery fallido.
	 *
	 * Solo actúa si el status es 'failed' y aún no existe un delivery creado.
	 *
	 * @param int $order_id ID del pedido.
	 * @return bool True si se programó el reintento.
	 */
	public function retry_delivery( int $order_id ): bool {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$status = $order->get_meta( '_uber_delivery_status' );

		if ( 'failed' !== $status || $order->get_meta( '_uber_delivery_id' ) ) {
			return false;
		}

		// Reiniciar el contador de reintentos para el intento manual.
		$order->update_meta_data( '_restohub_uber_retry_count', 0 );
		$order->save();

		as_schedule_single_action(
			time(),
			RestoHub_Uber_Logistics::ACTION_CREATE,
			array( 'order_id' => $order_id ),
			self::SCHEDULED_ACTION_GROUP
		);

		$order->add_order_note(
			__( 'Reintento manual programado (Motor Logístico).', 'restohub' )
		);

		return true;
	}

	/**
	 * Copia los campos de facturación al envío si la dirección de envío está vacía.
	 *
	 * RestoHub muestra un único bloque de dirección en el checkout (billing).
	 * Para que Uber Direct tenga la dirección correcta, los campos de shipping
	 * deben espejarse desde billing antes de guardar el pedido.
	 *
	 * La copia solo ocurre cuando shipping_address_1 Y shipping_first_name
	 * están vacíos — si el cliente llenó alguno, se respeta su elección.
	 *
	 * Hook: woocommerce_checkout_create_order (prioridad 10)
	 *
	 * @param WC_Order $order Objeto del pedido (billing ya aplicado por WC).
	 * @param array    $data  Datos raw del checkout (no usados; leemos del objeto).
	 * @return void
	 */
	public function copy_billing_to_shipping( WC_Order $order, array $data ): void {
		// Respetar si el cliente ya tiene datos de envío propios.
		if (
			! empty( $order->get_shipping_address_1() )
			|| ! empty( $order->get_shipping_first_name() )
		) {
			return;
		}

		$fields = array(
			'first_name',
			'last_name',
			'company',
			'address_1',
			'address_2',
			'city',
			'state',
			'postcode',
			'country',
		);
		// Nota: 'phone' y 'email' son exclusivos de billing en WooCommerce
		// y no tienen setter de shipping equivalente — no se copian.

		foreach ( $fields as $field ) {
			$getter = 'get_billing_' . $field;
			$setter = 'set_shipping_' . $field;

			if ( method_exists( $order, $getter ) && method_exists( $order, $setter ) ) {
				$order->{ $setter }( $order->{ $getter }() );
			}
		}
	}

	/**
	 * Acciones a ejecutar al desactivar el plugin
	 */
	public static function deactivate(): void {
		// Cancelar todas las acciones programadas del Motor Logístico
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions(
				RestoHub_Uber_Logistics::ACTION_CREATE,
				array(),
				self::SCHEDULED_ACTION_GROUP
			);
		}

		flush_rewrite_rules();
	}
}

/**
 * Función helper para acceder a la instancia del plugin
 *
 * @return RestoHub
 */
function restohub(): RestoHub {
	return RestoHub::instance();
}

/**
 * Hooks de activación/desactivación (deben registrarse inmediatamente)
 *
 * IMPORTANTE: El instalador se carga aquí para garantizar que la clase
 * RestoHub_Installer esté disponible al activar el plugin.
 * Esto permite "plug-and-play": copiar el plugin y activarlo crea las tablas.
 */
require_once RESTOHUB_PLUGIN_DIR . 'includes/class-installer.php';

register_activation_hook( __FILE__, array( 'RestoHub_Installer', 'install' ) );
register_deactivation_hook( RESTOHUB_PLUGIN_FILE, array( 'RestoHub', 'deactivate' ) );

/**
 * Declarar compatibilidad con HPOS (High-Performance Order Storage)
 *
 * WooCommerce 8.2+ requiere que los plugins declaren explícitamente
 * su compatibilidad con el nuevo sistema de almacenamiento de pedidos.
 *
 * @since 1.1.0
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RESTOHUB_PLUGIN_FILE, true );
	}
} );

/**
 * Iniciar el plugin en el hook plugins_loaded
 *
 * Esto asegura que WordPress y otros plugins estén completamente cargados
 * antes de inicializar nuestro plugin.
 */
add_action( 'plugins_loaded', 'restohub', 10 );

/**
 * Register RestoHub modules with Client Admin Portal
 *
 * If the Client Admin Portal plugin is active, register RestoHub features
 * as modules that can be managed from CAP's settings.
 *
 * @since 1.1.0
 */
add_action( 'cap_register_modules', function( $registry ) {
	// Main RestoHub Settings (covers all tabs)
	$registry->register( array(
		'slug'          => 'restohub_settings',
		'name'          => __( 'RestoHub - Configuración', 'restohub' ),
		'description'   => __( 'Configuración de API, tiendas y horarios para RestoHub', 'restohub' ),
		'plugin_source' => 'RestoHub',
		'menu_slug'     => 'restohub-settings',
		'capability'    => 'manage_woocommerce',
		'icon'          => 'dashicons-car',
		'sort_order'    => 50,
		'is_core'       => false,
	) );
} );
