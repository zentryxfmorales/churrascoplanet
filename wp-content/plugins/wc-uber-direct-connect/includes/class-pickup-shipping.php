<?php
/**
 * Método de envío: Retiro en Tienda
 *
 * Permite al cliente retirar su pedido en una tienda física
 * cuando no hay cobertura de delivery o por preferencia.
 *
 * @package WC_Uber_Direct_Connect
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
 * Clase WCUDC_Pickup_Shipping_Method
 *
 * Implementa el método de envío de Retiro en Tienda
 */
class WCUDC_Pickup_Shipping_Method extends WC_Shipping_Method {

    /**
     * Constructor
     *
     * @param int $instance_id ID de instancia.
     */
    public function __construct( int $instance_id = 0 ) {
        $this->id                 = 'local_pickup_wcudc';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'Retiro en Tienda', 'wc-uber-direct-connect' );
        $this->method_description = __( 'Permite a los clientes retirar su pedido en una de tus tiendas.', 'wc-uber-direct-connect' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
        );

        $this->init();
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
     * Define los campos de configuración
     */
    public function init_form_fields(): void {
        $this->instance_form_fields = array(
            'title'       => array(
                'title'       => __( 'Título', 'wc-uber-direct-connect' ),
                'type'        => 'text',
                'description' => __( 'Nombre que verán los clientes.', 'wc-uber-direct-connect' ),
                'default'     => __( 'Retiro en Tienda', 'wc-uber-direct-connect' ),
                'desc_tip'    => true,
            ),
            'cost'        => array(
                'title'       => __( 'Costo', 'wc-uber-direct-connect' ),
                'type'        => 'price',
                'description' => __( 'Costo del retiro (normalmente 0).', 'wc-uber-direct-connect' ),
                'default'     => '0',
                'desc_tip'    => true,
            ),
            'show_always' => array(
                'title'       => __( 'Mostrar siempre', 'wc-uber-direct-connect' ),
                'type'        => 'checkbox',
                'label'       => __( 'Mostrar retiro aunque el cliente tenga cobertura de delivery', 'wc-uber-direct-connect' ),
                'default'     => 'yes',
            ),
        );
    }

    /**
     * Calcula el envío
     *
     * @param array $package Paquete de envío.
     */
    public function calculate_shipping( $package = array() ): void {
        // Obtener tipo de entrega seleccionado
        $delivery_type = $this->get_selected_delivery_type();

        // Verificar si mostrar siempre o solo cuando sea retiro
        $show_always = $this->get_option( 'show_always', 'yes' ) === 'yes';

        if ( ! $show_always && $delivery_type === 'delivery' ) {
            // Si el cliente eligió delivery y tiene cobertura, no mostrar pickup
            if ( WCUDC_Location_Modal::has_delivery_coverage() ) {
                return;
            }
        }

        // Obtener la tienda seleccionada para retiro
        $pickup_store = $this->get_pickup_store();
        $store_name   = $pickup_store ? $pickup_store['name'] : '';

        // Construir label
        $label = $this->title;
        if ( $store_name ) {
            $label .= ' (' . $store_name . ')';
        }

        // Agregar rate
        $rate = array(
            'id'        => $this->get_rate_id(),
            'label'     => $label,
            'cost'      => $this->get_option( 'cost', 0 ),
            'calc_tax'  => 'per_order',
            'meta_data' => array(
                'pickup_store_id'   => $pickup_store ? $pickup_store['id'] : '',
                'pickup_store_name' => $store_name,
            ),
        );

        $this->add_rate( $rate );
    }

    /**
     * Obtiene el tipo de entrega seleccionado
     *
     * @return string
     */
    private function get_selected_delivery_type(): string {
        if ( class_exists( 'WCUDC_Location_Modal' ) ) {
            return WCUDC_Location_Modal::get_delivery_type();
        }
        return 'delivery';
    }

    /**
     * Obtiene la tienda seleccionada para retiro
     *
     * @return array|null
     */
    private function get_pickup_store(): ?array {
        // Intentar desde sesión
        if ( function_exists( 'WC' ) && WC()->session ) {
            $location = WC()->session->get( 'wcudc_location' );

            if ( $location && ! empty( $location['store_id'] ) ) {
                $stores = get_option( 'wcudc_stores', array() );
                if ( isset( $stores[ $location['store_id'] ] ) ) {
                    return $stores[ $location['store_id'] ];
                }
            }
        }

        // Fallback: primera tienda activa
        $stores = get_option( 'wcudc_stores', array() );
        $active = array_filter( $stores, fn( $s ) => ! empty( $s['active'] ) );

        return ! empty( $active ) ? reset( $active ) : null;
    }

    /**
     * Guarda los datos de pickup en el pedido
     *
     * @param WC_Order $order Pedido.
     * @param array    $data  Datos del checkout.
     */
    public static function save_pickup_data_to_order( WC_Order $order, array $data ): void {
        // Verificar si el método seleccionado es pickup
        $shipping_methods = $order->get_shipping_methods();

        foreach ( $shipping_methods as $shipping ) {
            if ( $shipping->get_method_id() === 'local_pickup_wcudc' ) {
                $store_id   = $shipping->get_meta( 'pickup_store_id' );
                $store_name = $shipping->get_meta( 'pickup_store_name' );

                if ( $store_id ) {
                    $order->update_meta_data( '_wcudc_pickup_store_id', $store_id );
                    $order->update_meta_data( '_wcudc_pickup_store_name', $store_name );
                    $order->update_meta_data( '_wcudc_delivery_type', 'pickup' );

                    // Agregar nota al pedido
                    $order->add_order_note(
                        sprintf(
                            __( 'Cliente retirará en tienda: %s', 'wc-uber-direct-connect' ),
                            $store_name
                        )
                    );
                }

                break;
            }
        }
    }
}

/**
 * Hook para guardar datos de pickup al crear el pedido
 */
add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {
    WCUDC_Pickup_Shipping_Method::save_pickup_data_to_order( $order, $data );
}, 20, 2 );
