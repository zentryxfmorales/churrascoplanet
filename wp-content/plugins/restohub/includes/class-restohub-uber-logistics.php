<?php
/**
 * Motor Logístico de Uber Direct
 *
 * Encapsula todo el ciclo de vida de un delivery: desde el trigger de pago
 * hasta la creación en Uber, con reintentos inteligentes por sin-repartidores
 * o errores 5xx, y alertas críticas cuando se agotan los intentos.
 *
 * Flujo:
 *  1. `on_payment_complete`  → valida método de envío, calcula trigger, encola AS.
 *  2. `process_delivery`     → llama a la API, evalúa respuesta.
 *  3. Fallo recuperable      → incrementa contador, re-encola en 5 min.
 *  4. Fallo definitivo       → cambia pedido a on-hold, envía alerta por email.
 *
 * Claves de meta utilizadas en el pedido:
 *  _uber_delivery_id        → ID asignado por Uber al crear el delivery.
 *  _uber_tracking_url       → URL de seguimiento del repartidor.
 *  _uber_delivery_status    → pending | processing | retrying | created | failed.
 *  _restohub_uber_retry_count → Contador de intentos realizados.
 *  _uber_last_error         → Último mensaje de error para diagnóstico.
 *
 * @package RestoHub
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestoHub_Uber_Logistics
 */
final class RestoHub_Uber_Logistics {

	// =========================================================================
	// Constantes
	// =========================================================================

	/**
	 * Nombre de la acción de Action Scheduler para crear el delivery.
	 * Se mantiene distinto de 'restohub_process_uber_delivery' (versión anterior)
	 * para evitar colisiones con acciones aún en cola de instalaciones existentes.
	 */
	public const ACTION_CREATE = 'restohub_uber_logistics_create';

	/**
	 * Grupo de acciones (compartido con el plugin principal).
	 */
	public const ACTION_GROUP = RestoHub::SCHEDULED_ACTION_GROUP;

	/**
	 * Segundos de espera entre reintentos (5 minutos).
	 */
	private const RETRY_DELAY = 5 * MINUTE_IN_SECONDS;

	// Claves de meta del pedido.
	private const META_DELIVERY_ID  = '_uber_delivery_id';
	private const META_TRACKING_URL = '_uber_tracking_url';
	private const META_STATUS       = '_uber_delivery_status';
	private const META_RETRY_COUNT  = '_restohub_uber_retry_count';
	private const META_LAST_ERROR   = '_uber_last_error';

	// =========================================================================
	// Propiedades
	// =========================================================================

	/**
	 * Instancia de la API de Uber.
	 *
	 * @var RestoHub_Uber_API
	 */
	private RestoHub_Uber_API $api;

	// =========================================================================
	// Constructor
	// =========================================================================

	/**
	 * Constructor.
	 *
	 * @param RestoHub_Uber_API $api Instancia inyectada de la API de Uber.
	 */
	public function __construct( RestoHub_Uber_API $api ) {
		$this->api = $api;
		$this->register_hooks();
	}

	// =========================================================================
	// Registro de hooks
	// =========================================================================

	/**
	 * Registra todos los hooks de WordPress / WooCommerce / Action Scheduler.
	 */
	private function register_hooks(): void {
		// 1. Disparador principal: pago completado en WooCommerce.
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 10, 1 );

		// 2. Procesador asíncrono ejecutado por Action Scheduler.
		add_action( self::ACTION_CREATE, array( $this, 'process_delivery' ), 10, 1 );

		// 3. Inyectar pickup_ready_dt para pedidos ASAP en el payload de la API.
		//    Para pedidos programados RestoHub_Uber_API ya lo gestiona; este filtro
		//    se aplica solo cuando NO existe el campo en el body.
		add_filter( 'restohub_uber_delivery_body', array( $this, 'inject_asap_pickup_ready_dt' ), 10, 2 );
	}

	// =========================================================================
	// 1. Disparador principal
	// =========================================================================

	/**
	 * Se ejecuta al completar el pago de un pedido.
	 *
	 * Verifica que el pedido use Uber Direct, calcula cuándo disparar la
	 * creación del delivery y lo encola en Action Scheduler.
	 *
	 * @param int $order_id ID del pedido de WooCommerce.
	 */
	public function on_payment_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Solo procesar pedidos con método de envío Uber Direct.
		if ( ! $this->order_uses_uber_shipping( $order ) ) {
			return;
		}

		// Idempotencia: ya tiene un delivery creado → no duplicar.
		if ( $order->get_meta( self::META_DELIVERY_ID ) ) {
			return;
		}

		// Idempotencia: ya hay una acción en cola para este pedido → no duplicar.
		if ( as_has_scheduled_action(
			self::ACTION_CREATE,
			array( 'order_id' => $order_id ),
			self::ACTION_GROUP
		) ) {
			return;
		}

		// Calcular timestamp de disparo: ASAP (ahora) o según slot programado.
		$trigger_timestamp = $this->get_trigger_timestamp( $order );

		// Inicializar meta del ciclo de vida.
		$order->update_meta_data( self::META_STATUS, 'pending' );
		$order->update_meta_data( self::META_RETRY_COUNT, 0 );
		$order->save();

		// Encolar acción asíncrona.
		as_schedule_single_action(
			$trigger_timestamp,
			self::ACTION_CREATE,
			array( 'order_id' => $order_id ),
			self::ACTION_GROUP
		);

		$order->add_order_note( $this->build_schedule_note( $order ) );
	}

	// =========================================================================
	// 2. Procesador asíncrono (Action Scheduler)
	// =========================================================================

	/**
	 * Crea el delivery en Uber Direct.
	 *
	 * Ejecutado por Action Scheduler. NO lanza excepciones en errores
	 * recuperables: los reintentos son gestionados manualmente para tener
	 * control total sobre el contador, las notas y las alertas.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function process_delivery( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			// El pedido ya no existe; no tiene sentido reintentar.
			$this->log_critical(
				sprintf( 'Pedido #%d no encontrado al intentar crear delivery en Uber.', $order_id )
			);
			return;
		}

		// Idempotencia: ya tiene delivery creado (p.ej. reintento tardío).
		if ( $order->get_meta( self::META_DELIVERY_ID ) ) {
			return;
		}

		$retry_count = (int) $order->get_meta( self::META_RETRY_COUNT );

		$order->update_meta_data( self::META_STATUS, 'processing' );
		$order->save();

		// Llamar a la API de Uber.
		// El filtro 'restohub_uber_delivery_body' (hook en esta clase) inyectará
		// pickup_ready_dt para pedidos ASAP antes de que la petición salga.
		$result = $this->api->create_delivery( $order );

		if ( is_wp_error( $result ) ) {
			$this->handle_failure( $order, $result, $retry_count );
			return;
		}

		$this->handle_success( $order, $result );
	}

	// =========================================================================
	// 3. Handlers de éxito y fallo
	// =========================================================================

	/**
	 * Procesa una respuesta exitosa de la API de Uber.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param array    $result Respuesta de la API de Uber.
	 */
	private function handle_success( WC_Order $order, array $result ): void {
		$delivery_id  = sanitize_text_field( $result['id'] ?? '' );
		$tracking_url = esc_url_raw( $result['tracking_url'] ?? '' );

		$order->update_meta_data( self::META_DELIVERY_ID, $delivery_id );
		$order->update_meta_data( self::META_STATUS, 'created' );

		if ( $tracking_url ) {
			$order->update_meta_data( self::META_TRACKING_URL, $tracking_url );
		}

		// Limpiar contadores de reintento una vez el delivery está creado.
		$order->delete_meta_data( self::META_RETRY_COUNT );
		$order->delete_meta_data( self::META_LAST_ERROR );
		$order->save();

		$note = sprintf(
			/* translators: %s: Uber delivery ID */
			__( '✅ Delivery creado en Uber Direct. ID: %s', 'restohub' ),
			$delivery_id
		);

		if ( $tracking_url ) {
			$note .= "\n" . sprintf(
				/* translators: %s: tracking URL */
				__( 'Tracking en tiempo real: %s', 'restohub' ),
				$tracking_url
			);
		}

		$order->add_order_note( $note );

		/**
		 * Disparado cuando el delivery es creado con éxito en Uber.
		 *
		 * @param WC_Order $order  Pedido de WooCommerce.
		 * @param array    $result Respuesta completa de la API.
		 */
		do_action( 'restohub_delivery_created', $order, $result );
	}

	/**
	 * Procesa un fallo en la creación del delivery.
	 *
	 * Lógica de decisión:
	 *  - Error recuperable (sin repartidores / 5xx) Y reintentos disponibles
	 *    → incrementa contador, re-encola en RETRY_DELAY segundos.
	 *  - Error no recuperable O reintentos agotados
	 *    → pedido a on-hold, nota crítica, alerta por email.
	 *
	 * @param WC_Order $order       Pedido afectado.
	 * @param WP_Error $error       Error recibido de la API.
	 * @param int      $retry_count Número de intentos ya realizados (0-based).
	 */
	private function handle_failure( WC_Order $order, WP_Error $error, int $retry_count ): void {
		$settings    = get_option( 'restohub_settings', array() );
		$max_retries = (int) ( $settings['uber_max_retries'] ?? 3 );
		$error_msg   = $error->get_error_message();

		// Extraer HTTP status si fue un error de la API (puede ser 0 para errores locales).
		$error_data  = is_array( $error->get_error_data() ) ? $error->get_error_data() : array();
		$http_status = (int) ( $error_data['status'] ?? 0 );

		// Persistir el último error para diagnóstico en la ficha del pedido.
		$order->update_meta_data( self::META_LAST_ERROR, $error_msg );

		if ( $this->is_retriable_error( $error, $http_status ) && $retry_count < $max_retries ) {

			// ── REINTENTO ──────────────────────────────────────────────────────
			$new_count = $retry_count + 1;

			$order->update_meta_data( self::META_STATUS, 'retrying' );
			$order->update_meta_data( self::META_RETRY_COUNT, $new_count );
			$order->save();

			as_schedule_single_action(
				time() + self::RETRY_DELAY,
				self::ACTION_CREATE,
				array( 'order_id' => $order->get_id() ),
				self::ACTION_GROUP
			);

			$order->add_order_note(
				sprintf(
					/* translators: 1: current attempt, 2: max retries, 3: error message */
					__( '⚠️ Sin repartidores disponibles. Reintentando en 5 min. (Intento %1$d de %2$d). Detalle: %3$s', 'restohub' ),
					$new_count,
					$max_retries,
					$error_msg
				)
			);

		} else {

			// ── FALLO DEFINITIVO ────────────────────────────────────────────────
			$total_attempts = $retry_count + 1;

			$order->update_meta_data( self::META_STATUS, 'failed' );
			$order->save();

			$critical_note = sprintf(
				/* translators: 1: total attempts, 2: error message */
				__( '🚨 Delivery en Uber falló definitivamente tras %1$d intento(s). Último error: %2$s. Requiere atención manual.', 'restohub' ),
				$total_attempts,
				$error_msg
			);

			// update_status guarda el pedido y añade la nota internamente.
			$order->update_status( 'on-hold', $critical_note );

			// Enviar alerta a los responsables configurados.
			$this->send_critical_alert( $order, $error_msg, $total_attempts );
		}
	}

	// =========================================================================
	// 4. Filtro: inyectar pickup_ready_dt para pedidos ASAP
	// =========================================================================

	/**
	 * Inyecta pickup_ready_dt en el payload de la API para pedidos ASAP.
	 *
	 * Se ejecuta vía el filtro 'restohub_uber_delivery_body' (definido en
	 * RestoHub_Uber_API::create_delivery). Para pedidos programados,
	 * RestoHub_Uber_API ya incluyó el campo → este método no sobreescribe.
	 *
	 * La fórmula: ahora + restohub_uber_prep_time minutos (ISO 8601 UTC).
	 *
	 * @param array    $body  Payload ya construido por RestoHub_Uber_API.
	 * @param WC_Order $order Pedido de WooCommerce.
	 * @return array
	 */
	public function inject_asap_pickup_ready_dt( array $body, WC_Order $order ): array {
		// Si pickup_ready_dt ya existe (pedido programado) → no tocar.
		if ( isset( $body['pickup_ready_dt'] ) ) {
			return $body;
		}

		$settings  = get_option( 'restohub_settings', array() );
		$prep_time = (int) ( $settings['uber_prep_time'] ?? 20 );

		if ( $prep_time > 0 ) {
			$body['pickup_ready_dt'] = gmdate( 'c', time() + ( $prep_time * MINUTE_IN_SECONDS ) );
		}

		return $body;
	}

	// =========================================================================
	// Métodos privados de apoyo
	// =========================================================================

	/**
	 * Verifica si el pedido usa el método de envío Uber Direct.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	private function order_uses_uber_shipping( WC_Order $order ): bool {
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 'uber_direct' === $method->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Calcula el timestamp en que debe dispararse la creación del delivery.
	 *
	 * - Pedido programado → usa RestohHub_Scheduling para calcular antes del slot.
	 * - Pedido ASAP       → time() (inmediato).
	 *
	 * @param WC_Order $order Pedido.
	 * @return int Unix timestamp.
	 */
	private function get_trigger_timestamp( WC_Order $order ): int {
		if (
			'scheduled' === $order->get_meta( '_restohub_schedule_type' ) &&
			function_exists( 'restohub_scheduling' )
		) {
			return restohub_scheduling()->get_uber_trigger_timestamp(
				$order->get_meta( '_restohub_scheduled_date' ),
				$order->get_meta( '_restohub_scheduled_time' )
			);
		}

		return time();
	}

	/**
	 * Genera la nota de pedido que se añade al encolar el delivery.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	private function build_schedule_note( WC_Order $order ): string {
		if ( 'scheduled' === $order->get_meta( '_restohub_schedule_type' ) ) {
			return sprintf(
				/* translators: 1: scheduled date, 2: scheduled time */
				__( 'Motor Logístico: delivery programado para Uber Direct. Se solicitará repartidor antes de %1$s a las %2$s.', 'restohub' ),
				$order->get_meta( '_restohub_scheduled_date' ),
				$order->get_meta( '_restohub_scheduled_time' )
			);
		}

		$settings  = get_option( 'restohub_settings', array() );
		$prep_time = (int) ( $settings['uber_prep_time'] ?? 20 );

		return sprintf(
			/* translators: %d: prep time in minutes */
			__( 'Motor Logístico: delivery ASAP enviado a Uber Direct. Repartidor solicitado en ~%d min (pickup_ready_dt calculado).', 'restohub' ),
			$prep_time
		);
	}

	/**
	 * Determina si un error de la API es recuperable mediante reintento.
	 *
	 * Son recuperables:
	 *  - HTTP 5xx (error del servidor de Uber, posiblemente transitorio).
	 *  - Respuesta con código/mensaje "no_couriers_available".
	 *
	 * No son recuperables (y no tiene sentido reintentar en 5 min):
	 *  - Credenciales inválidas.
	 *  - Tienda sin coordenadas.
	 *  - Dirección del cliente inválida.
	 *
	 * @param WP_Error $error       Error recibido.
	 * @param int      $http_status Código HTTP (0 si no aplica).
	 * @return bool
	 */
	private function is_retriable_error( WP_Error $error, int $http_status ): bool {
		// Error de servidor de Uber (5xx).
		if ( $http_status >= 500 ) {
			return true;
		}

		// Sin repartidores disponibles (Uber devuelve esto como 4xx con código específico).
		$haystack = strtolower( $error->get_error_message() . ' ' . $error->get_error_code() );

		return str_contains( $haystack, 'no_couriers_available' )
			|| str_contains( $haystack, 'no couriers available' )
			|| str_contains( $haystack, 'no_couriers' );
	}

	/**
	 * Envía una alerta de texto plano a los responsables configurados.
	 *
	 * Destinatarios: correos de 'uber_alert_emails' (settings) + admin del sitio
	 * como fallback. Cada dirección es validada con is_email() antes de enviar.
	 *
	 * @param WC_Order $order       Pedido afectado.
	 * @param string   $error_msg   Último mensaje de error.
	 * @param int      $attempts    Total de intentos realizados.
	 */
	private function send_critical_alert( WC_Order $order, string $error_msg, int $attempts ): void {
		$settings    = get_option( 'restohub_settings', array() );
		$raw_emails  = (string) ( $settings['uber_alert_emails'] ?? '' );
		$admin_email = (string) get_option( 'admin_email' );

		// Construir lista de destinatarios únicos y con formato válido.
		$recipients = array_unique(
			array_filter(
				array_map( 'trim', explode( ',', $raw_emails ) ),
				'is_email'
			)
		);

		// El admin del sitio siempre recibe la alerta como fallback.
		if ( is_email( $admin_email ) && ! in_array( $admin_email, $recipients, true ) ) {
			$recipients[] = $admin_email;
		}

		if ( empty( $recipients ) ) {
			return;
		}

		$order_id  = $order->get_id();
		$blog_name = get_bloginfo( 'name' );
		$order_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );

		$subject = sprintf(
			/* translators: 1: blog name, 2: order ID */
			'[%1$s] 🚨 URGENTE: Delivery Uber FALLIDO — Pedido #%2$d',
			$blog_name,
			$order_id
		);

		$message = implode(
			"\n\n",
			array(
				sprintf( 'ALERTA CRÍTICA — %s', $blog_name ),
				sprintf(
					'El delivery del pedido #%d no pudo crearse en Uber Direct tras %d intento(s).',
					$order_id,
					$attempts
				),
				sprintf( 'Último error: %s', $error_msg ),
				sprintf(
					"Cliente: %s\nTotal del pedido: %s",
					$order->get_formatted_billing_full_name(),
					wp_strip_all_tags( wc_price( $order->get_total() ) )
				),
				'El pedido fue marcado como "En espera" (on-hold). Acción requerida: contactar al cliente y coordinar el delivery de forma alternativa.',
				sprintf( 'Ver pedido en el panel: %s', esc_url_raw( $order_url ) ),
			)
		);

		foreach ( $recipients as $email ) {
			wp_mail(
				$email,
				$subject,
				$message,
				array( 'Content-Type: text/plain; charset=UTF-8' )
			);
		}
	}

	/**
	 * Registra un mensaje crítico en el logger de WooCommerce.
	 *
	 * @param string $message Mensaje a registrar.
	 */
	private function log_critical( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->critical( $message, array( 'source' => 'restohub' ) );
		}
	}
}
