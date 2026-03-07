<?php
/**
 * Clase para manejar la conexión con la API de Uber Direct
 *
 * @package WC_Uber_Direct_Connect
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase WCUDC_Uber_API
 *
 * Maneja todas las comunicaciones con la API de Uber Direct
 */
class WCUDC_Uber_API {

    /**
     * URL base de la API de Uber Direct (Producción)
     */
    private const API_URL = 'https://api.uber.com/v1/customers/';

    /**
     * URL base de la API de Uber Direct (Sandbox)
     */
    private const SANDBOX_API_URL = 'https://sandbox-api.uber.com/v1/customers/';

    /**
     * URL para obtener token OAuth
     */
    private const AUTH_URL = 'https://login.uber.com/oauth/v2/token';

    /**
     * Contexto del logger (aparece en WooCommerce > Estado > Registros)
     */
    private const LOG_SOURCE = 'wc-uber-direct-connect';

    /**
     * Token de acceso actual
     *
     * @var string|null
     */
    private ?string $access_token = null;

    /**
     * Configuración del plugin
     *
     * @var array
     */
    private array $settings;

    /**
     * Logger de WooCommerce
     *
     * @var WC_Logger_Interface|null
     */
    private ?WC_Logger_Interface $logger = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option( 'wcudc_settings', array() );
        $this->init_logger();
    }

    /**
     * Inicializa el logger de WooCommerce
     */
    private function init_logger(): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Registra un mensaje en el log de WooCommerce
     *
     * @param string $level   Nivel: emergency|alert|critical|error|warning|notice|info|debug
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional (opcional).
     */
    private function log( string $level, string $message, array $context = array() ): void {
        if ( ! $this->logger ) {
            return;
        }

        $context['source'] = self::LOG_SOURCE;

        $this->logger->log( $level, $message, $context );
    }

    /**
     * Obtiene la URL base de la API según el modo
     *
     * @return string
     */
    private function get_api_url(): string {
        $sandbox_mode = $this->settings['sandbox_mode'] ?? 'yes';
        return 'yes' === $sandbox_mode ? self::SANDBOX_API_URL : self::API_URL;
    }

    /**
     * Obtiene el token de acceso OAuth
     *
     * @return string|WP_Error
     */
    private function get_access_token(): string|WP_Error {
        // Si ya tenemos un token válido, usarlo
        $cached_token = get_transient( 'wcudc_uber_access_token' );
        if ( $cached_token ) {
            $this->log( 'debug', 'Usando token OAuth cacheado.' );
            return $cached_token;
        }

        $client_id     = $this->settings['client_id'] ?? '';
        $client_secret = $this->settings['client_secret'] ?? '';

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            $this->log( 'error', 'Autenticación fallida: faltan credenciales de API.' );
            return new WP_Error(
                'missing_credentials',
                __( 'Faltan las credenciales de Uber API.', 'wc-uber-direct-connect' )
            );
        }

        $this->log( 'debug', 'Solicitando nuevo token OAuth a Uber.' );

        $response = wp_remote_post(
            self::AUTH_URL,
            array(
                'body' => array(
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'grant_type'    => 'client_credentials',
                    'scope'         => 'eats.deliveries',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->log( 'error', sprintf(
                'Error de conexión al obtener token OAuth: %s',
                $response->get_error_message()
            ) );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['access_token'] ) ) {
            // Guardar token en cache (expira 1 hora antes del tiempo real)
            $expires_in = ( $body['expires_in'] ?? 3600 ) - 3600;
            set_transient( 'wcudc_uber_access_token', $body['access_token'], $expires_in );

            $this->log( 'info', sprintf(
                'Token OAuth obtenido exitosamente. Expira en %d segundos.',
                $body['expires_in'] ?? 3600
            ) );

            return $body['access_token'];
        }

        $error_message = $body['error_description'] ?? __( 'Error de autenticación con Uber.', 'wc-uber-direct-connect' );

        $this->log( 'error', sprintf(
            'Autenticación OAuth fallida. HTTP %d: %s',
            $response_code,
            $error_message
        ) );

        return new WP_Error( 'auth_failed', $error_message );
    }

    /**
     * Realiza una petición a la API de Uber
     *
     * @param string $endpoint Endpoint de la API.
     * @param string $method   Método HTTP (GET, POST, etc.).
     * @param array  $body     Cuerpo de la petición.
     * @return array|WP_Error
     */
    private function request( string $endpoint, string $method = 'GET', array $body = array() ): array|WP_Error {
        $token = $this->get_access_token();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $customer_id = $this->settings['customer_id'] ?? '';
        $url         = $this->get_api_url() . $customer_id . '/' . $endpoint;

        $this->log( 'debug', sprintf(
            'API Request: %s %s',
            $method,
            $endpoint
        ) );

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 30,
        );

        if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $args['body'] = wp_json_encode( $body );

            // Log del body (sin datos sensibles)
            $this->log( 'debug', sprintf(
                'Request body keys: %s',
                implode( ', ', array_keys( $body ) )
            ) );
        }

        $start_time = microtime( true );
        $response   = wp_remote_request( $url, $args );
        $duration   = round( ( microtime( true ) - $start_time ) * 1000 );

        if ( is_wp_error( $response ) ) {
            $this->log( 'error', sprintf(
                'API Error en %s %s (%dms): %s',
                $method,
                $endpoint,
                $duration,
                $response->get_error_message()
            ) );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $response_code >= 400 ) {
            $error_message = $response_body['message'] ?? __( 'Error en la API de Uber.', 'wc-uber-direct-connect' );

            $this->log( 'error', sprintf(
                'API Error HTTP %d en %s %s (%dms): %s',
                $response_code,
                $method,
                $endpoint,
                $duration,
                $error_message
            ) );

            // Log detallado del error para debugging
            if ( ! empty( $response_body['error'] ) || ! empty( $response_body['code'] ) ) {
                $this->log( 'debug', sprintf(
                    'Error details - Code: %s, Error: %s',
                    $response_body['code'] ?? 'N/A',
                    $response_body['error'] ?? 'N/A'
                ) );
            }

            return new WP_Error(
                'api_error',
                $error_message,
                array( 'status' => $response_code )
            );
        }

        $this->log( 'debug', sprintf(
            'API Response HTTP %d en %s %s (%dms)',
            $response_code,
            $method,
            $endpoint,
            $duration
        ) );

        return $response_body ?? array();
    }

    /**
     * Obtiene una cotización de envío
     *
     * @param array $pickup_address  Dirección de recogida.
     * @param array $dropoff_address Dirección de entrega.
     * @return array|WP_Error
     */
    public function get_quote( array $pickup_address, array $dropoff_address ): array|WP_Error {
        $this->log( 'info', 'Solicitando cotización de delivery.' );

        $body = array(
            'pickup_address'  => $pickup_address,
            'dropoff_address' => $dropoff_address,
        );

        $result = $this->request( 'delivery_quotes', 'POST', $body );

        if ( is_wp_error( $result ) ) {
            $this->log( 'warning', sprintf(
                'Cotización fallida: %s',
                $result->get_error_message()
            ) );
        } else {
            $this->log( 'info', sprintf(
                'Cotización exitosa. Fee: %s, ETA: %s min',
                $result['fee'] ?? 'N/A',
                $result['estimated_delivery_minutes'] ?? 'N/A'
            ) );
        }

        return $result;
    }

    /**
     * Crea un delivery a partir de un pedido de WooCommerce
     *
     * @param WC_Order $order Pedido de WooCommerce.
     * @return array|WP_Error
     */
    public function create_delivery( WC_Order $order ): array|WP_Error {
        $order_id = $order->get_id();

        $this->log( 'info', sprintf(
            'Iniciando creación de delivery para pedido #%d',
            $order_id
        ) );

        // Obtener la tienda asignada al pedido
        $assigned_store = $this->get_assigned_store_for_order( $order );

        if ( ! $assigned_store ) {
            $this->log( 'error', sprintf(
                'Pedido #%d: No se pudo determinar la tienda de origen.',
                $order_id
            ) );

            return new WP_Error(
                'no_store_assigned',
                __( 'No se pudo determinar la tienda de origen para este pedido.', 'wc-uber-direct-connect' )
            );
        }

        $this->log( 'info', sprintf(
            'Pedido #%d: Tienda asignada = %s (ID: %s)',
            $order_id,
            $assigned_store['name'],
            $assigned_store['id']
        ) );

        // Obtener datos del cliente (dropoff)
        $dropoff_address = $this->get_dropoff_address( $order );

        // Validar que tengamos coordenadas de destino
        if ( empty( $dropoff_address['latitude'] ) || empty( $dropoff_address['longitude'] ) ) {
            $this->log( 'error', sprintf(
                'Pedido #%d: Faltan coordenadas de destino. Lat: %s, Lng: %s',
                $order_id,
                $dropoff_address['latitude'] ?? 'vacío',
                $dropoff_address['longitude'] ?? 'vacío'
            ) );

            return new WP_Error(
                'missing_coordinates',
                __( 'La dirección del cliente no tiene coordenadas válidas.', 'wc-uber-direct-connect' )
            );
        }

        // Construir items del pedido
        $manifest_items = array();
        foreach ( $order->get_items() as $item ) {
            $manifest_items[] = array(
                'name'     => $item->get_name(),
                'quantity' => $item->get_quantity(),
            );
        }

        $this->log( 'debug', sprintf(
            'Pedido #%d: %d items, origen: %s, destino: %s',
            $order_id,
            count( $manifest_items ),
            $assigned_store['address'],
            $dropoff_address['street_address']
        ) );

        $body = array(
            'pickup_name'           => $assigned_store['name'],
            'pickup_address'        => $assigned_store['address'],
            'pickup_phone_number'   => $assigned_store['phone'] ?? '',
            'pickup_latitude'       => $assigned_store['latitude'],
            'pickup_longitude'      => $assigned_store['longitude'],
            'dropoff_name'          => $order->get_formatted_shipping_full_name(),
            'dropoff_address'       => $dropoff_address['street_address'],
            'dropoff_phone_number'  => $order->get_billing_phone(),
            'dropoff_latitude'      => $dropoff_address['latitude'],
            'dropoff_longitude'     => $dropoff_address['longitude'],
            'manifest_items'        => $manifest_items,
            'external_id'           => (string) $order_id,
        );

        $result = $this->request( 'deliveries', 'POST', $body );

        if ( is_wp_error( $result ) ) {
            $this->log( 'error', sprintf(
                'Pedido #%d: Fallo al crear delivery - %s',
                $order_id,
                $result->get_error_message()
            ) );
        } else {
            // Guardar información de la tienda en el pedido
            $order->update_meta_data( '_wcudc_store_id', $assigned_store['id'] );
            $order->update_meta_data( '_wcudc_store_name', $assigned_store['name'] );
            $order->save();

            $this->log( 'info', sprintf(
                'Pedido #%d: Delivery creado exitosamente. Uber ID: %s, Tienda: %s',
                $order_id,
                $result['id'] ?? 'N/A',
                $assigned_store['name']
            ) );
        }

        return $result;
    }

    /**
     * Obtiene la tienda asignada para un pedido
     *
     * Busca en este orden:
     * 1. Metadata del pedido (_wcudc_store_id)
     * 2. Metadata del shipping item
     * 3. Geofencing con coordenadas del cliente
     * 4. Primera tienda activa (fallback)
     *
     * @param WC_Order $order Pedido de WooCommerce.
     * @return array|null Datos de la tienda o null.
     */
    private function get_assigned_store_for_order( WC_Order $order ): ?array {
        $stores = get_option( 'wcudc_stores', array() );

        if ( empty( $stores ) ) {
            return null;
        }

        // 1. Intentar desde metadata del pedido
        $store_id = $order->get_meta( '_wcudc_store_id' );
        if ( $store_id && isset( $stores[ $store_id ] ) ) {
            $this->log( 'debug', 'Tienda obtenida desde metadata del pedido.' );
            return $stores[ $store_id ];
        }

        // 2. Intentar desde metadata del shipping item
        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            $item_store_id = $shipping_item->get_meta( 'store_id' );
            if ( $item_store_id && isset( $stores[ $item_store_id ] ) ) {
                $this->log( 'debug', 'Tienda obtenida desde shipping item metadata.' );
                return $stores[ $item_store_id ];
            }
        }

        // 3. Intentar geofencing con coordenadas del cliente
        $customer_lat = $order->get_meta( '_shipping_latitude' );
        $customer_lng = $order->get_meta( '_shipping_longitude' );

        if ( $customer_lat && $customer_lng ) {
            $validator = wcudc_polygon_validator();
            $point     = array( 'lat' => (float) $customer_lat, 'lng' => (float) $customer_lng );

            $found_store = $validator->find_store_for_point( $point );

            if ( $found_store ) {
                $this->log( 'debug', 'Tienda obtenida via geofencing.' );
                return $found_store;
            }

            // Intentar tienda más cercana
            $nearest_store = $validator->find_nearest_store( $point );
            if ( $nearest_store ) {
                $this->log( 'debug', 'Tienda obtenida como más cercana (fallback geofencing).' );
                return $nearest_store;
            }
        }

        // 4. Fallback: primera tienda activa
        $active_stores = array_filter( $stores, fn( $s ) => ! empty( $s['active'] ) );

        if ( ! empty( $active_stores ) ) {
            $this->log( 'debug', 'Tienda obtenida como primera activa (fallback final).' );
            return reset( $active_stores );
        }

        return null;
    }

    /**
     * Obtiene el estado de un delivery
     *
     * @param string $delivery_id ID del delivery en Uber.
     * @return array|WP_Error
     */
    public function get_delivery_status( string $delivery_id ): array|WP_Error {
        $this->log( 'debug', sprintf( 'Consultando estado de delivery: %s', $delivery_id ) );

        $result = $this->request( 'deliveries/' . $delivery_id );

        if ( ! is_wp_error( $result ) && isset( $result['status'] ) ) {
            $this->log( 'debug', sprintf(
                'Delivery %s: estado = %s',
                $delivery_id,
                $result['status']
            ) );
        }

        return $result;
    }

    /**
     * Cancela un delivery
     *
     * @param string $delivery_id ID del delivery en Uber.
     * @return array|WP_Error
     */
    public function cancel_delivery( string $delivery_id ): array|WP_Error {
        $this->log( 'warning', sprintf( 'Solicitando cancelación de delivery: %s', $delivery_id ) );

        $result = $this->request( 'deliveries/' . $delivery_id . '/cancel', 'POST' );

        if ( is_wp_error( $result ) ) {
            $this->log( 'error', sprintf(
                'Fallo al cancelar delivery %s: %s',
                $delivery_id,
                $result->get_error_message()
            ) );
        } else {
            $this->log( 'info', sprintf( 'Delivery %s cancelado exitosamente.', $delivery_id ) );
        }

        return $result;
    }

    /**
     * Obtiene la dirección de entrega del pedido
     *
     * @param WC_Order $order Pedido de WooCommerce.
     * @return array
     */
    private function get_dropoff_address( WC_Order $order ): array {
        $address = sprintf(
            '%s %s, %s, %s %s',
            $order->get_shipping_address_1(),
            $order->get_shipping_address_2(),
            $order->get_shipping_city(),
            $order->get_shipping_state(),
            $order->get_shipping_postcode()
        );

        return array(
            'street_address' => trim( $address ),
            'latitude'       => $order->get_meta( '_shipping_latitude' ),
            'longitude'      => $order->get_meta( '_shipping_longitude' ),
        );
    }
}
