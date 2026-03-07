<?php
/**
 * Receptor de Webhooks de Uber Direct
 *
 * Escucha eventos de estado de deliveries enviados por Uber Direct via WC API.
 * URL: https://tutienda.cl/?wc-api=restohub_uber_webhook
 *
 * Evento       → Status meta (_uber_status)    → Acción WooCommerce
 * ─────────────────────────────────────────────────────────────────
 * pickup       → picked_up                     → nota en pedido
 * dropoff      → delivered                     → completar pedido
 * approaching  → courier_approaching           → nota en pedido
 * cancelled    → cancelled                     → nota en pedido
 *
 * @package RestoHub
 * @since   1.1.3
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestoHub_Uber_Webhook
 *
 * Receptor de webhooks de Uber Direct mediante WooCommerce API hook.
 * No usa REST API — responde en la URL ?wc-api=restohub_uber_webhook.
 */
class RestoHub_Uber_Webhook {

	/**
	 * Meta key compartida con el Motor Logístico y la Delivery UI.
	 */
	private const META_STATUS    = '_uber_status';
	private const META_DELIVERY  = '_uber_delivery_id';

	/**
	 * Constructor — registra el hook de WC API.
	 */
	public function __construct() {
		add_action( 'woocommerce_api_restohub_uber_webhook', array( $this, 'handle' ) );
	}

	/**
	 * Punto de entrada — valida firma, parsea payload y despacha eventos.
	 *
	 * Siempre responde 200 a Uber (incluso ante errores internos) para evitar
	 * reenvíos innecesarios. Los problemas se loguean vía wc_get_logger().
	 */
	public function handle(): void {
		$raw_body = (string) file_get_contents( 'php://input' );

		// ── 1. Verificar firma HMAC ────────────────────────────────────────
		if ( ! $this->verify_signature( $raw_body ) ) {
			$this->respond( 401, array( 'error' => 'Invalid signature' ) );
		}

		// ── 2. Parsear JSON ────────────────────────────────────────────────
		$data = json_decode( $raw_body, true );

		if ( ! is_array( $data ) || empty( $data['event_type'] ) || empty( $data['delivery_id'] ) ) {
			$this->log( 'Payload inválido o campos faltantes: ' . $raw_body );
			$this->respond( 400, array( 'error' => 'Invalid payload' ) );
		}

		$event_type  = sanitize_text_field( $data['event_type'] );
		$delivery_id = sanitize_text_field( $data['delivery_id'] );

		// ── 3. Localizar el pedido ─────────────────────────────────────────
		$order = $this->find_order( $delivery_id );

		if ( ! $order ) {
			$this->log( "Pedido no encontrado para delivery_id={$delivery_id} (event={$event_type})" );
			// Responder 200 para que Uber no reintente un evento de un delivery
			// que no reconocemos (p.ej. de otro entorno o ya eliminado).
			$this->respond( 200, array( 'ok' => true ) );
		}

		// ── 4. Despachar evento ────────────────────────────────────────────
		$this->dispatch( $order, $event_type, $data );

		$this->respond( 200, array( 'ok' => true ) );
	}

	// =========================================================================
	// VERIFICACIÓN DE FIRMA
	// =========================================================================

	/**
	 * Verifica la firma HMAC-SHA256 del payload.
	 *
	 * Uber envía el hash hex del payload en el header X-Uber-Signature.
	 * La constante wp-config RESTOHUB_WEBHOOK_SECRET tiene precedencia sobre
	 * el valor guardado en la base de datos.
	 *
	 * @param string $raw_body Payload crudo del request.
	 * @return bool
	 */
	private function verify_signature( string $raw_body ): bool {
		$signature = isset( $_SERVER['HTTP_X_UBER_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_UBER_SIGNATURE'] ) )
			: '';

		if ( empty( $signature ) ) {
			return false;
		}

		$settings = get_option( 'restohub_settings', array() );
		$secret   = defined( 'RESTOHUB_WEBHOOK_SECRET' )
			? RESTOHUB_WEBHOOK_SECRET
			: ( $settings['webhook_secret'] ?? '' );

		// Sin secret configurado: permitir solo en entorno debug
		if ( empty( $secret ) ) {
			return defined( 'WP_DEBUG' ) && WP_DEBUG;
		}

		$expected = hash_hmac( 'sha256', $raw_body, $secret );

		return hash_equals( $expected, $signature );
	}

	// =========================================================================
	// BÚSQUEDA DE PEDIDO
	// =========================================================================

	/**
	 * Busca el pedido WooCommerce asociado al delivery_id de Uber.
	 *
	 * @param string $delivery_id ID del delivery de Uber.
	 * @return WC_Order|null
	 */
	private function find_order( string $delivery_id ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'meta_key'   => self::META_DELIVERY,
				'meta_value' => $delivery_id,
				'limit'      => 1,
				'status'     => array_keys( wc_get_order_statuses() ),
			)
		);

		return ! empty( $orders ) ? $orders[0] : null;
	}

	// =========================================================================
	// DESPACHO DE EVENTOS
	// =========================================================================

	/**
	 * Despacha el evento al manejador correspondiente.
	 *
	 * @param WC_Order $order      Pedido asociado.
	 * @param string   $event_type Tipo de evento de Uber.
	 * @param array    $data       Payload completo.
	 */
	private function dispatch( WC_Order $order, string $event_type, array $data ): void {
		switch ( $event_type ) {
			case 'delivery.pickup':
				$this->on_pickup( $order, $data );
				break;

			case 'delivery.dropoff':
				$this->on_dropoff( $order, $data );
				break;

			case 'delivery.courier_approaching_pickup':
				$this->on_approaching( $order, $data );
				break;

			case 'delivery.cancelled':
				$this->on_cancelled( $order, $data );
				break;

			default:
				// Registrar evento desconocido sin error — Uber puede agregar tipos nuevos.
				$order->add_order_note(
					sprintf(
						/* translators: %s: event type string */
						__( '[RestoHub] Evento de Uber recibido: %s', 'restohub' ),
						$event_type
					)
				);
				$this->log( "Evento no manejado: {$event_type} para pedido #{$order->get_id()}" );
		}
	}

	// =========================================================================
	// MANEJADORES DE EVENTOS
	// =========================================================================

	/**
	 * El courier recogió el pedido del restaurante.
	 *
	 * @param WC_Order $order Pedido.
	 * @param array    $data  Payload.
	 */
	private function on_pickup( WC_Order $order, array $data ): void {
		$order->update_meta_data( self::META_STATUS, 'picked_up' );
		$order->save();

		$courier = sanitize_text_field( $data['courier']['name'] ?? '' );
		$note    = $courier
			? sprintf(
				/* translators: %s: courier name */
				__( 'Pedido recogido por %s (Uber Direct).', 'restohub' ),
				$courier
			)
			: __( 'Pedido recogido del restaurante (Uber Direct).', 'restohub' );

		$order->add_order_note( $note );

		do_action( 'restohub_delivery_picked_up', $order, $data );
	}

	/**
	 * El courier entregó el pedido al cliente.
	 *
	 * Completa el pedido en WooCommerce y dispara la acción de integración.
	 *
	 * @param WC_Order $order Pedido.
	 * @param array    $data  Payload.
	 */
	private function on_dropoff( WC_Order $order, array $data ): void {
		$order->update_meta_data( self::META_STATUS, 'delivered' );

		// update_status guarda las meta y añade la nota internamente.
		$order->update_status(
			'completed',
			__( 'Pedido entregado al cliente (Uber Direct).', 'restohub' )
		);

		do_action( 'restohub_delivery_completed', $order, $data );
	}

	/**
	 * El courier está en camino al restaurante.
	 *
	 * @param WC_Order $order Pedido.
	 * @param array    $data  Payload.
	 */
	private function on_approaching( WC_Order $order, array $data ): void {
		$order->update_meta_data( self::META_STATUS, 'courier_approaching' );
		$order->save();

		$eta = absint( $data['eta_minutes'] ?? 0 );
		$note = $eta
			? sprintf(
				/* translators: %d: estimated minutes */
				__( 'El repartidor está en camino al restaurante. ETA: %d min.', 'restohub' ),
				$eta
			)
			: __( 'El repartidor está en camino al restaurante (Uber Direct).', 'restohub' );

		$order->add_order_note( $note );

		do_action( 'restohub_courier_approaching', $order, $data );
	}

	/**
	 * El delivery fue cancelado por Uber.
	 *
	 * No cancela el pedido de WooCommerce automáticamente — el equipo decide
	 * cómo gestionar la situación (reintento manual, reembolso, etc.).
	 *
	 * @param WC_Order $order Pedido.
	 * @param array    $data  Payload.
	 */
	private function on_cancelled( WC_Order $order, array $data ): void {
		$order->update_meta_data( self::META_STATUS, 'cancelled' );
		$order->save();

		$reason = sanitize_text_field( $data['cancellation_reason'] ?? '' );
		$note   = $reason
			? sprintf(
				/* translators: %s: cancellation reason */
				__( 'Delivery de Uber cancelado. Motivo: %s', 'restohub' ),
				$reason
			)
			: __( 'Delivery de Uber cancelado sin motivo especificado.', 'restohub' );

		$order->add_order_note( $note );

		do_action( 'restohub_delivery_cancelled', $order, $data );
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	/**
	 * Emite la respuesta HTTP y termina la ejecución.
	 *
	 * @param int   $code HTTP status code.
	 * @param array $body JSON body.
	 */
	private function respond( int $code, array $body ): void {
		http_response_code( $code );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $body );
		exit;
	}

	/**
	 * Registra un mensaje en el log de WooCommerce.
	 *
	 * @param string $message Mensaje a registrar.
	 */
	private function log( string $message ): void {
		$logger  = wc_get_logger();
		$context = array( 'source' => 'restohub-webhook' );
		$logger->info( $message, $context );
	}
}
