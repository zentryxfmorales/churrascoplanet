<?php
/**
 * Plugin Name: WC Uber Direct Connect
 * Plugin URI: https://github.com/zentryx/wc-uber-direct-connect
 * Description: Integración de Uber Direct con WooCommerce para delivery de restaurante.
 * Version: 1.0.0
 * Author: Zentryx
 * Author URI: https://zentryx.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wc-uber-direct-connect
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
define( 'WCUDC_VERSION', '1.0.0' );
define( 'WCUDC_PLUGIN_FILE', __FILE__ );
define( 'WCUDC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCUDC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCUDC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Clase principal del plugin - Singleton
 */
final class WC_Uber_Direct_Connect {

	/**
	 * Instancia única del plugin
	 *
	 * @var WC_Uber_Direct_Connect|null
	 */
	private static ?WC_Uber_Direct_Connect $instance = null;

	/**
	 * Instancia de la clase API de Uber
	 *
	 * @var WCUDC_Uber_API|null
	 */
	public ?WCUDC_Uber_API $api = null;

	/**
	 * Nombre de la acción programada para crear delivery
	 */
	public const SCHEDULED_ACTION_CREATE_DELIVERY = 'wcudc_process_uber_delivery';

	/**
	 * Grupo de acciones programadas
	 */
	public const SCHEDULED_ACTION_GROUP = 'wcudc_deliveries';

	/**
	 * Obtiene la instancia única del plugin (Singleton)
	 *
	 * @return WC_Uber_Direct_Connect
	 */
	public static function instance(): WC_Uber_Direct_Connect {
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

		// Hook para PROGRAMAR delivery cuando se completa el pago
		add_action( 'woocommerce_payment_complete', array( $this, 'schedule_uber_delivery' ) );

		// Hook de Action Scheduler para PROCESAR el delivery de forma asíncrona
		add_action( self::SCHEDULED_ACTION_CREATE_DELIVERY, array( $this, 'process_uber_delivery' ) );
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
					esc_html__( '%s requiere que WooCommerce esté instalado y activo.', 'wc-uber-direct-connect' ),
					'<strong>WC Uber Direct Connect</strong>'
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
		$this->api = new WCUDC_Uber_API();

		// Inicializar el manejador de webhooks
		new WCUDC_Webhook_Handler();

		// Inicializar configuración de admin
		if ( is_admin() ) {
			new WCUDC_Admin_Settings();
		}

		// Inicializar componentes frontend
		if ( ! is_admin() || wp_doing_ajax() ) {
			new WCUDC_Checkout_Map();
			new WCUDC_Location_Modal();
		}
	}

	/**
	 * Incluye los archivos necesarios del plugin
	 *
	 * NOTA: Las clases de shipping se cargan en load_shipping_classes()
	 * mediante el hook 'woocommerce_shipping_init'.
	 */
	private function includes(): void {
		// Clases del núcleo (NO incluir shipping methods aquí)
		require_once WCUDC_PLUGIN_DIR . 'includes/class-uber-api.php';
		require_once WCUDC_PLUGIN_DIR . 'includes/class-webhook-handler.php';
		require_once WCUDC_PLUGIN_DIR . 'includes/class-polygon-validator.php';
		require_once WCUDC_PLUGIN_DIR . 'includes/class-geo-validator.php';
		require_once WCUDC_PLUGIN_DIR . 'includes/class-checkout-map.php';
		require_once WCUDC_PLUGIN_DIR . 'includes/class-location-modal.php';

		// Clases de administración
		if ( is_admin() ) {
			require_once WCUDC_PLUGIN_DIR . 'admin/class-admin-settings.php';
		}
	}

	/**
	 * Carga las clases de métodos de envío
	 *
	 * Hook: woocommerce_shipping_init
	 * Se ejecuta DESPUÉS de que WC_Shipping_Method esté disponible.
	 */
	public function load_shipping_classes(): void {
		require_once WCUDC_PLUGIN_DIR . 'includes/class-shipping-method.php';
		require_once WCUDC_PLUGIN_DIR . 'includes/class-pickup-shipping.php';
	}

	/**
	 * Carga los archivos de traducción
	 *
	 * Hook: init
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wc-uber-direct-connect',
			false,
			dirname( WCUDC_PLUGIN_BASENAME ) . '/languages'
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
		$methods['uber_direct']        = 'WCUDC_Shipping_Method';
		$methods['local_pickup_wcudc'] = 'WCUDC_Pickup_Shipping_Method';
		return $methods;
	}

	/**
	 * Programa el delivery en Uber usando Action Scheduler
	 *
	 * Hook: woocommerce_payment_complete
	 * NO llama a la API directamente - programa una acción asíncrona.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function schedule_uber_delivery( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Verificar que el método de envío sea Uber Direct
		$shipping_methods = $order->get_shipping_methods();
		$is_uber_shipping = false;

		foreach ( $shipping_methods as $shipping_method ) {
			if ( 'uber_direct' === $shipping_method->get_method_id() ) {
				$is_uber_shipping = true;
				break;
			}
		}

		if ( ! $is_uber_shipping ) {
			return;
		}

		// Verificar que no exista ya una acción programada para este pedido
		if ( as_has_scheduled_action( self::SCHEDULED_ACTION_CREATE_DELIVERY, array( 'order_id' => $order_id ), self::SCHEDULED_ACTION_GROUP ) ) {
			return;
		}

		// Verificar que no tenga ya un delivery creado
		if ( $order->get_meta( '_uber_delivery_id' ) ) {
			return;
		}

		// Marcar como pendiente de envío a Uber
		$order->update_meta_data( '_uber_delivery_status', 'pending' );
		$order->save();

		// Programar la acción asíncrona
		as_schedule_single_action(
			time(),
			self::SCHEDULED_ACTION_CREATE_DELIVERY,
			array( 'order_id' => $order_id ),
			self::SCHEDULED_ACTION_GROUP
		);

		$order->add_order_note(
			__( 'Delivery programado para envío a Uber Direct.', 'wc-uber-direct-connect' )
		);
	}

	/**
	 * Procesa el delivery en Uber (ejecutado por Action Scheduler)
	 *
	 * Hook: wcudc_process_uber_delivery (Action Scheduler)
	 * Si falla, Action Scheduler reintentará automáticamente.
	 *
	 * @param int $order_id ID del pedido.
	 * @throws \Exception Si la API falla (para que Action Scheduler reintente).
	 */
	public function process_uber_delivery( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			throw new \Exception(
				sprintf( 'Pedido #%d no encontrado.', $order_id )
			);
		}

		// Verificar que no tenga ya un delivery creado
		if ( $order->get_meta( '_uber_delivery_id' ) ) {
			return;
		}

		// Actualizar estado
		$order->update_meta_data( '_uber_delivery_status', 'processing' );
		$order->save();

		// Inicializar API si es necesario
		if ( ! $this->api ) {
			$this->api = new WCUDC_Uber_API();
		}

		// Llamar a la API de Uber
		$result = $this->api->create_delivery( $order );

		if ( is_wp_error( $result ) ) {
			// Actualizar estado de error
			$order->update_meta_data( '_uber_delivery_status', 'error' );
			$order->update_meta_data( '_uber_last_error', $result->get_error_message() );
			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Error al crear delivery en Uber (se reintentará): %s', 'wc-uber-direct-connect' ),
					$result->get_error_message()
				)
			);

			// Lanzar excepción para que Action Scheduler reintente
			throw new \Exception( $result->get_error_message() );
		}

		// Éxito: guardar datos del delivery
		$order->update_meta_data( '_uber_delivery_id', $result['id'] );
		$order->update_meta_data( '_uber_delivery_status', 'created' );
		$order->delete_meta_data( '_uber_last_error' );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: %s: delivery ID */
				__( 'Delivery creado en Uber Direct. ID: %s', 'wc-uber-direct-connect' ),
				$result['id']
			)
		);

		/**
		 * Acción disparada cuando el delivery se crea exitosamente
		 *
		 * @param WC_Order $order  Pedido de WooCommerce.
		 * @param array    $result Respuesta de la API de Uber.
		 */
		do_action( 'wcudc_delivery_created', $order, $result );
	}

	/**
	 * Verifica si un pedido tiene delivery pendiente de crear
	 *
	 * @param int $order_id ID del pedido.
	 * @return bool
	 */
	public function has_pending_delivery( int $order_id ): bool {
		return as_has_scheduled_action(
			self::SCHEDULED_ACTION_CREATE_DELIVERY,
			array( 'order_id' => $order_id ),
			self::SCHEDULED_ACTION_GROUP
		);
	}

	/**
	 * Reintenta manualmente crear un delivery fallido
	 *
	 * @param int $order_id ID del pedido.
	 * @return bool True si se programó el reintento.
	 */
	public function retry_delivery( int $order_id ): bool {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		// Solo reintentar si está en error y no tiene delivery
		$status = $order->get_meta( '_uber_delivery_status' );
		if ( 'error' !== $status || $order->get_meta( '_uber_delivery_id' ) ) {
			return false;
		}

		// Programar nuevo intento
		as_schedule_single_action(
			time(),
			self::SCHEDULED_ACTION_CREATE_DELIVERY,
			array( 'order_id' => $order_id ),
			self::SCHEDULED_ACTION_GROUP
		);

		$order->add_order_note(
			__( 'Reintento manual programado para crear delivery en Uber.', 'wc-uber-direct-connect' )
		);

		return true;
	}

	/**
	 * Acciones a ejecutar al activar el plugin
	 */
	public static function activate(): void {
		// Crear opciones por defecto
		$default_options = array(
			'client_id'     => '',
			'client_secret' => '',
			'customer_id'   => '',
			'sandbox_mode'  => 'yes',
		);

		if ( ! get_option( 'wcudc_settings' ) ) {
			add_option( 'wcudc_settings', $default_options );
		}

		// Limpiar reglas de reescritura
		flush_rewrite_rules();
	}

	/**
	 * Acciones a ejecutar al desactivar el plugin
	 */
	public static function deactivate(): void {
		// Cancelar todas las acciones programadas del plugin
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SCHEDULED_ACTION_CREATE_DELIVERY, array(), self::SCHEDULED_ACTION_GROUP );
		}

		flush_rewrite_rules();
	}
}

/**
 * Función helper para acceder a la instancia del plugin
 *
 * @return WC_Uber_Direct_Connect
 */
function wcudc(): WC_Uber_Direct_Connect {
	return WC_Uber_Direct_Connect::instance();
}

/**
 * Hooks de activación/desactivación (deben registrarse inmediatamente)
 */
register_activation_hook( WCUDC_PLUGIN_FILE, array( 'WC_Uber_Direct_Connect', 'activate' ) );
register_deactivation_hook( WCUDC_PLUGIN_FILE, array( 'WC_Uber_Direct_Connect', 'deactivate' ) );

/**
 * Declarar compatibilidad con HPOS (High-Performance Order Storage)
 *
 * WooCommerce 8.2+ requiere que los plugins declaren explícitamente
 * su compatibilidad con el nuevo sistema de almacenamiento de pedidos.
 *
 * @since 1.0.0
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCUDC_PLUGIN_FILE, true );
	}
} );

/**
 * Iniciar el plugin en el hook plugins_loaded
 *
 * Esto asegura que WordPress y otros plugins estén completamente cargados
 * antes de inicializar nuestro plugin.
 */
add_action( 'plugins_loaded', 'wcudc', 10 );