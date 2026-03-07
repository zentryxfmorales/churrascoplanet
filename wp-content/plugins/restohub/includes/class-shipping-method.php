<?php
/**
 * Método de envío de WooCommerce para Uber Direct
 *
 * @package RestoHub
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Verificar que WC_Shipping_Method esté disponible
if ( ! class_exists( 'WC_Shipping_Method' ) ) {
    return;
}

/**
 * Clase RestoHub_Shipping_Method
 *
 * Implementa el método de envío de Uber Direct para WooCommerce
 * con asignación dinámica de tienda basada en geofencing
 */
class RestoHub_Shipping_Method extends WC_Shipping_Method {

    /**
     * Logger de WooCommerce
     *
     * @var WC_Logger_Interface|null
     */
    private ?WC_Logger_Interface $logger = null;

    /**
     * Contexto del logger
     */
    private const LOG_SOURCE = 'restohub';

    /**
     * Constructor
     *
     * @param int $instance_id ID de instancia del método de envío.
     */
    public function __construct( int $instance_id = 0 ) {
        $this->id                 = 'uber_direct';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Uber Direct', 'restohub' );
        $this->method_description = __( 'Delivery a través de Uber Direct con asignación automática de tienda por zona.', 'restohub' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
        );

        $this->init();
        $this->init_logger();
    }

    /**
     * Inicializa el logger
     */
    private function init_logger(): void {
        if ( function_exists( 'wc_get_logger' ) ) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Registra un mensaje en el log
     *
     * @param string $level   Nivel del log.
     * @param string $message Mensaje.
     */
    private function log( string $level, string $message ): void {
        if ( $this->logger ) {
            $this->logger->log( $level, '[Shipping] ' . $message, array( 'source' => self::LOG_SOURCE ) );
        }
    }

    /**
     * Inicializa el método de envío
     */
    public function init(): void {
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option( 'title', $this->method_title );

        add_action(
            'woocommerce_update_options_shipping_' . $this->id,
            array( $this, 'process_admin_options' )
        );
    }

    /**
     * Define los campos de configuración del método de envío
     */
    public function init_form_fields(): void {
        $this->instance_form_fields = array(
            'title'              => array(
                'title'       => __( 'Título', 'restohub' ),
                'type'        => 'text',
                'description' => __( 'Nombre que verán los clientes en el checkout.', 'restohub' ),
                'default'     => __( 'Delivery Express (Uber)', 'restohub' ),
                'desc_tip'    => true,
            ),
            'fallback_cost'      => array(
                'title'       => __( 'Costo de fallback', 'restohub' ),
                'type'        => 'price',
                'description' => __( 'Costo a usar si la API de Uber no está disponible.', 'restohub' ),
                'default'     => '5.00',
                'desc_tip'    => true,
            ),
            'no_coverage_action' => array(
                'title'       => __( 'Sin cobertura', 'restohub' ),
                'type'        => 'select',
                'description' => __( 'Qué hacer cuando la dirección del cliente no está en ninguna zona de reparto.', 'restohub' ),
                'default'     => 'hide',
                'options'     => array(
                    'hide'    => __( 'Ocultar método de envío', 'restohub' ),
                    'nearest' => __( 'Usar tienda más cercana', 'restohub' ),
                ),
                'desc_tip'    => true,
            ),
            'show_store_name'    => array(
                'title'       => __( 'Mostrar tienda', 'restohub' ),
                'type'        => 'checkbox',
                'label'       => __( 'Mostrar nombre de la tienda asignada en el checkout', 'restohub' ),
                'default'     => 'yes',
            ),
            'require_coordinates' => array(
                'title'       => __( 'Requerir coordenadas', 'restohub' ),
                'type'        => 'checkbox',
                'label'       => __( 'Requerir que el cliente tenga coordenadas válidas para mostrar este método', 'restohub' ),
                'description' => __( 'Si está activo, el método solo aparece si el cliente tiene lat/lng en su dirección.', 'restohub' ),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Calcula el costo de envío basado en la zona de cobertura
     *
     * @param array $package Paquete con información del envío.
     */
    public function calculate_shipping( $package = array() ): void {
        // Obtener coordenadas del cliente
        $customer_coords = $this->get_customer_coordinates( $package );

        // Si se requieren coordenadas y no las tenemos, no mostrar método
        if ( 'yes' === $this->get_option( 'require_coordinates', 'no' ) ) {
            if ( ! $customer_coords ) {
                $this->log( 'debug', 'Método oculto: coordenadas requeridas pero no disponibles.' );
                return;
            }
        }

        // Buscar tienda por geofencing
        $assigned_store = null;
        $validator      = restohub_polygon_validator();

        if ( $customer_coords ) {
            $assigned_store = $validator->find_store_for_point( $customer_coords );

            // Si no hay cobertura, verificar configuración
            if ( ! $assigned_store ) {
                $no_coverage_action = $this->get_option( 'no_coverage_action', 'hide' );

                if ( 'nearest' === $no_coverage_action ) {
                    // Usar tienda más cercana como fallback
                    $assigned_store = $validator->find_nearest_store( $customer_coords );

                    if ( $assigned_store ) {
                        $this->log( 'info', sprintf(
                            'Sin cobertura por polígono. Usando tienda más cercana: %s',
                            $assigned_store['name']
                        ) );
                    }
                } else {
                    // Ocultar método de envío
                    $this->log( 'debug', 'Método oculto: cliente fuera de zona de cobertura.' );
                    return;
                }
            }
        } else {
            // Sin coordenadas, intentar usar tienda por defecto o la primera activa
            $assigned_store = $this->get_default_store();
        }

        // Si no hay tienda asignada, no mostrar método
        if ( ! $assigned_store ) {
            $this->log( 'warning', 'No hay tiendas disponibles para asignar.' );
            return;
        }

        // Guardar tienda asignada en la sesión para uso posterior
        $this->save_assigned_store_to_session( $assigned_store );

        // Calcular costo de envío
        $shipping_data = $this->calculate_uber_quote( $package, $assigned_store, $customer_coords );

        // Construir label
        $label = $this->build_shipping_label( $assigned_store, $shipping_data );

        // Agregar rate
        $rate = array(
            'id'        => $this->get_rate_id(),
            'label'     => $label,
            'cost'      => $shipping_data['cost'],
            'calc_tax'  => 'per_order',
            'meta_data' => array(
                'store_id'   => $assigned_store['id'],
                'store_name' => $assigned_store['name'],
            ),
        );

        $this->add_rate( $rate );

        $this->log( 'info', sprintf(
            'Rate agregado: Tienda=%s, Costo=%s',
            $assigned_store['name'],
            $shipping_data['cost']
        ) );
    }

    /**
     * Obtiene las coordenadas del cliente desde el paquete o sesión
     *
     * @param array $package Paquete de envío.
     * @return array|null ['lat' => float, 'lng' => float] o null.
     */
    private function get_customer_coordinates( array $package ): ?array {
        // 1. Primero intentar desde restohub_location (sincronizado desde localStorage)
        if ( WC()->session ) {
            $restohub_location = WC()->session->get( 'restohub_location' );
            if ( $restohub_location && ! empty( $restohub_location['lat'] ) && ! empty( $restohub_location['lng'] ) ) {
                $this->log( 'debug', sprintf(
                    'Coordenadas obtenidas desde restohub_location: %s, %s',
                    $restohub_location['lat'],
                    $restohub_location['lng']
                ) );
                return array(
                    'lat' => (float) $restohub_location['lat'],
                    'lng' => (float) $restohub_location['lng'],
                );
            }
        }

        // 2. Intentar desde la sesión de WooCommerce (shipping_latitude/longitude)
        if ( WC()->session ) {
            $session_lat = WC()->session->get( 'shipping_latitude' );
            $session_lng = WC()->session->get( 'shipping_longitude' );

            if ( $session_lat && $session_lng ) {
                $this->log( 'debug', sprintf(
                    'Coordenadas obtenidas desde sesión shipping: %s, %s',
                    $session_lat,
                    $session_lng
                ) );
                return array(
                    'lat' => (float) $session_lat,
                    'lng' => (float) $session_lng,
                );
            }
        }

        // 3. Intentar desde meta del cliente (usuario logueado)
        $customer_id = get_current_user_id();
        if ( $customer_id ) {
            $lat = get_user_meta( $customer_id, 'shipping_latitude', true );
            $lng = get_user_meta( $customer_id, 'shipping_longitude', true );

            if ( $lat && $lng ) {
                $this->log( 'debug', sprintf(
                    'Coordenadas obtenidas desde user meta: %s, %s',
                    $lat,
                    $lng
                ) );
                return array(
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                );
            }
        }

        // 4. Intentar desde campos personalizados del checkout (si existen en el package)
        if ( ! empty( $package['destination']['latitude'] ) && ! empty( $package['destination']['longitude'] ) ) {
            $this->log( 'debug', 'Coordenadas obtenidas desde package destination.' );
            return array(
                'lat' => (float) $package['destination']['latitude'],
                'lng' => (float) $package['destination']['longitude'],
            );
        }

        $this->log( 'warning', 'No se encontraron coordenadas del cliente en ninguna fuente.' );
        return null;
    }

    /**
     * Obtiene la tienda por defecto (primera activa)
     *
     * @return array|null
     */
    private function get_default_store(): ?array {
        // Usar repositorio si está disponible
        if ( ! function_exists( 'restohub_store_repository' ) ) {
            return null;
        }

        $active_stores = restohub_store_repository()->get_active();

        if ( empty( $active_stores ) ) {
            return null;
        }

        return reset( $active_stores );
    }

    /**
     * Guarda la tienda asignada en la sesión de WooCommerce
     *
     * @param array $store Datos de la tienda.
     */
    private function save_assigned_store_to_session( array $store ): void {
        if ( WC()->session ) {
            WC()->session->set( 'restohub_assigned_store_id', $store['id'] );
            WC()->session->set( 'restohub_assigned_store', $store );
        }
    }

    /**
     * Calcula la cotización de Uber para el envío
     *
     * @param array      $package          Paquete de envío.
     * @param array      $store            Tienda asignada.
     * @param array|null $customer_coords  Coordenadas del cliente.
     * @return array ['cost' => float, 'eta' => string|null]
     */
    private function calculate_uber_quote( array $package, array $store, ?array $customer_coords ): array {
        $fallback_cost = (float) $this->get_option( 'fallback_cost', 5 );
        $result        = array(
            'cost' => $fallback_cost,
            'eta'  => null,
        );

        $api = restohub()->api;

        if ( ! $api ) {
            $this->log( 'warning', 'API no disponible, usando costo fallback.' );
            return $result;
        }

        // Si no hay coordenadas del cliente, no podemos cotizar correctamente
        if ( ! $customer_coords ) {
            $this->log( 'warning', 'Sin coordenadas del cliente, usando costo fallback.' );
            return $result;
        }

        // Construir datos de pickup (tienda)
        $pickup = array(
            'address'   => $store['address'] ?? '',
            'latitude'  => (float) ( $store['latitude'] ?? 0 ),
            'longitude' => (float) ( $store['longitude'] ?? 0 ),
        );

        // Construir datos de dropoff (cliente)
        $dropoff = array(
            'address'   => $this->build_dropoff_address( $package ),
            'latitude'  => $customer_coords['lat'],
            'longitude' => $customer_coords['lng'],
        );

        $this->log( 'debug', sprintf(
            'Solicitando cotización: Tienda=%s, Cliente=%s,%s',
            $store['name'] ?? 'N/A',
            $customer_coords['lat'],
            $customer_coords['lng']
        ) );

        // Obtener cotización de Uber
        $quote = $api->get_quote( $pickup, $dropoff );

        if ( ! is_wp_error( $quote ) && isset( $quote['fee'] ) ) {
            // Uber devuelve el fee en la moneda local (no centavos para CLP)
            // Verificar si es centavos o valor directo según la moneda
            $fee = $quote['fee'];

            // Para monedas como CLP que no usan centavos, el valor ya es directo
            // Para USD/EUR, viene en centavos
            $currency = $quote['currency'] ?? 'CLP';
            if ( in_array( $currency, array( 'CLP', 'JPY', 'KRW' ), true ) ) {
                $result['cost'] = $fee; // Ya es el valor real
            } else {
                $result['cost'] = $fee / 100; // Convertir de centavos
            }

            $result['eta'] = $quote['estimated_delivery_minutes'] ?? null;

            $this->log( 'info', sprintf(
                'Cotización Uber exitosa: %s %s, ETA %s min',
                $currency,
                $result['cost'],
                $result['eta'] ?? 'N/A'
            ) );
        } else {
            $error_msg = is_wp_error( $quote ) ? $quote->get_error_message() : 'Respuesta inválida';
            $this->log( 'warning', 'Cotización Uber fallida: ' . $error_msg . '. Usando fallback: ' . $fallback_cost );
        }

        return $result;
    }

    /**
     * Construye la dirección de destino desde el paquete
     *
     * @param array $package Paquete de envío.
     * @return string
     */
    private function build_dropoff_address( array $package ): string {
        $destination = $package['destination'] ?? array();

        return trim( sprintf(
            '%s %s, %s, %s %s',
            $destination['address'] ?? '',
            $destination['address_2'] ?? '',
            $destination['city'] ?? '',
            $destination['state'] ?? '',
            $destination['postcode'] ?? ''
        ) );
    }

    /**
     * Construye el label del método de envío
     *
     * @param array $store         Tienda asignada.
     * @param array $shipping_data Datos del cálculo de envío.
     * @return string
     */
    private function build_shipping_label( array $store, array $shipping_data ): string {
        $label = $this->title;

        // Agregar ETA si está disponible
        if ( $shipping_data['eta'] ) {
            $label .= sprintf(
                ' - %s min',
                $shipping_data['eta']
            );
        }

        // Agregar nombre de tienda si está configurado
        if ( 'yes' === $this->get_option( 'show_store_name', 'yes' ) ) {
            $label .= sprintf(
                ' (%s)',
                $store['name']
            );
        }

        return $label;
    }

    /**
     * Obtiene la tienda asignada desde la sesión
     *
     * @return array|null
     */
    public static function get_assigned_store(): ?array {
        if ( WC()->session ) {
            return WC()->session->get( 'restohub_assigned_store' );
        }
        return null;
    }

    /**
     * Obtiene el ID de la tienda asignada desde la sesión
     *
     * @return string|null
     */
    public static function get_assigned_store_id(): ?string {
        if ( WC()->session ) {
            return WC()->session->get( 'restohub_assigned_store_id' );
        }
        return null;
    }
}
