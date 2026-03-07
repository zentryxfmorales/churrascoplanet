<?php
/**
 * Manejador de webhooks de Uber Direct
 *
 * @package RestoHub
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase RestoHub_Webhook_Handler
 *
 * Procesa los webhooks enviados por Uber Direct
 */
class RestoHub_Webhook_Handler {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
    }

    /**
     * Registra el endpoint REST para recibir webhooks
     */
    public function register_webhook_endpoint(): void {
        register_rest_route(
            'restohub/v1',
            '/webhook',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_webhook' ),
                'permission_callback' => array( $this, 'verify_webhook' ),
            )
        );
    }

    /**
     * Verifica la autenticidad del webhook
     *
     * @param WP_REST_Request $request Petición REST.
     * @return bool
     */
    public function verify_webhook( WP_REST_Request $request ): bool {
        $signature = $request->get_header( 'X-Uber-Signature' );

        if ( empty( $signature ) ) {
            return false;
        }

        $settings       = get_option( 'restohub_settings', array() );
        // La constante RESTOHUB_WEBHOOK_SECRET en wp-config.php tiene precedencia
        $webhook_secret = defined( 'RESTOHUB_WEBHOOK_SECRET' )
            ? RESTOHUB_WEBHOOK_SECRET
            : ( $settings['webhook_secret'] ?? '' );

        if ( empty( $webhook_secret ) ) {
            // Si no hay secret configurado, permitir en modo desarrollo
            return defined( 'WP_DEBUG' ) && WP_DEBUG;
        }

        $body            = $request->get_body();
        $expected_signature = hash_hmac( 'sha256', $body, $webhook_secret );

        return hash_equals( $expected_signature, $signature );
    }

    /**
     * Procesa el webhook recibido
     *
     * @param WP_REST_Request $request Petición REST.
     * @return WP_REST_Response
     */
    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $data = $request->get_json_params();

        if ( empty( $data['event_type'] ) || empty( $data['delivery_id'] ) ) {
            return new WP_REST_Response(
                array( 'error' => 'Invalid payload' ),
                400
            );
        }

        $event_type  = sanitize_text_field( $data['event_type'] );
        $delivery_id = sanitize_text_field( $data['delivery_id'] );

        // Buscar el pedido asociado
        $order = $this->get_order_by_delivery_id( $delivery_id );

        if ( ! $order ) {
            return new WP_REST_Response(
                array( 'error' => 'Order not found' ),
                404
            );
        }

        // Procesar según el tipo de evento
        switch ( $event_type ) {
            case 'delivery.pickup':
                $this->handle_pickup( $order, $data );
                break;

            case 'delivery.dropoff':
                $this->handle_dropoff( $order, $data );
                break;

            case 'delivery.courier_approaching_pickup':
                $this->handle_courier_approaching( $order, $data );
                break;

            case 'delivery.cancelled':
                $this->handle_cancelled( $order, $data );
                break;

            default:
                // Registrar evento desconocido
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: event type */
                        __( 'Evento de Uber recibido: %s', 'restohub' ),
                        $event_type
                    )
                );
        }

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Busca un pedido por su ID de delivery de Uber
     *
     * @param string $delivery_id ID del delivery.
     * @return WC_Order|null
     */
    private function get_order_by_delivery_id( string $delivery_id ): ?WC_Order {
        $orders = wc_get_orders(
            array(
                'meta_key'   => '_uber_delivery_id',
                'meta_value' => $delivery_id,
                'limit'      => 1,
            )
        );

        return ! empty( $orders ) ? $orders[0] : null;
    }

    /**
     * Maneja el evento de recogida (pickup)
     *
     * @param WC_Order $order Pedido.
     * @param array    $data  Datos del webhook.
     */
    private function handle_pickup( WC_Order $order, array $data ): void {
        $order->update_meta_data( '_uber_status', 'picked_up' );
        $order->save();

        $courier_name = sanitize_text_field( $data['courier']['name'] ?? __( 'Courier', 'restohub' ) );

        $order->add_order_note(
            sprintf(
                /* translators: %s: courier name */
                __( 'El pedido ha sido recogido por %s (Uber Direct).', 'restohub' ),
                $courier_name
            )
        );

        // Disparar acción para integraciones externas
        do_action( 'restohub_delivery_picked_up', $order, $data );
    }

    /**
     * Maneja el evento de entrega (dropoff)
     *
     * @param WC_Order $order Pedido.
     * @param array    $data  Datos del webhook.
     */
    private function handle_dropoff( WC_Order $order, array $data ): void {
        $order->update_meta_data( '_uber_status', 'delivered' );
        $order->update_status(
            'completed',
            __( 'Pedido entregado por Uber Direct.', 'restohub' )
        );

        // Disparar acción para integraciones externas
        do_action( 'restohub_delivery_completed', $order, $data );
    }

    /**
     * Maneja el evento de courier aproximándose
     *
     * @param WC_Order $order Pedido.
     * @param array    $data  Datos del webhook.
     */
    private function handle_courier_approaching( WC_Order $order, array $data ): void {
        $order->update_meta_data( '_uber_status', 'courier_approaching' );
        $order->save();

        $eta = absint( $data['eta_minutes'] ?? 0 ) ?: '';

        $order->add_order_note(
            sprintf(
                /* translators: %s: ETA in minutes */
                __( 'El courier de Uber está en camino al restaurante. ETA: %s minutos.', 'restohub' ),
                $eta
            )
        );

        do_action( 'restohub_courier_approaching', $order, $data );
    }

    /**
     * Maneja el evento de cancelación
     *
     * @param WC_Order $order Pedido.
     * @param array    $data  Datos del webhook.
     */
    private function handle_cancelled( WC_Order $order, array $data ): void {
        $order->update_meta_data( '_uber_status', 'cancelled' );
        $order->save();

        $reason = sanitize_text_field( $data['cancellation_reason'] ?? __( 'No especificada', 'restohub' ) );

        $order->add_order_note(
            sprintf(
                /* translators: %s: cancellation reason */
                __( 'Delivery de Uber cancelado. Razón: %s', 'restohub' ),
                $reason
            )
        );

        do_action( 'restohub_delivery_cancelled', $order, $data );
    }
}
