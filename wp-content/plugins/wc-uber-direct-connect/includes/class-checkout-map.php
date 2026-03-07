<?php
/**
 * Mapa de OpenStreetMap en el Checkout
 *
 * Maneja la integración de Leaflet en el checkout para
 * que el cliente seleccione su ubicación exacta.
 *
 * @package WC_Uber_Direct_Connect
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase WCUDC_Checkout_Map
 *
 * Agrega un mapa interactivo al checkout de WooCommerce
 * usando OpenStreetMap y Leaflet
 */
class WCUDC_Checkout_Map {

    /**
     * Coordenadas por defecto (Lima, Perú)
     * TODO: Hacer configurable desde admin
     */
    private const DEFAULT_LAT = -12.0464;
    private const DEFAULT_LNG = -77.0428;
    private const DEFAULT_ZOOM = 13;

    /**
     * Constructor
     */
    public function __construct() {
        // Encolar scripts y estilos en checkout
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );

        // Agregar campos ocultos al checkout
        add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'add_map_container' ) );
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_coordinates_to_order' ) );

        // Agregar campos ocultos de coordenadas
        add_filter( 'woocommerce_checkout_fields', array( $this, 'add_coordinate_fields' ) );

        // Guardar coordenadas en la sesión vía AJAX
        add_action( 'wp_ajax_wcudc_save_coordinates', array( $this, 'ajax_save_coordinates' ) );
        add_action( 'wp_ajax_nopriv_wcudc_save_coordinates', array( $this, 'ajax_save_coordinates' ) );

        // Validar que las coordenadas estén presentes
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_coordinates' ) );
    }

    /**
     * Encola los scripts y estilos necesarios en el checkout
     */
    public function enqueue_checkout_assets(): void {
        // Solo en página de checkout
        if ( ! is_checkout() ) {
            return;
        }

        // Leaflet CSS
        wp_enqueue_style(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            array(),
            '1.9.4'
        );

        // CSS personalizado del checkout
        wp_enqueue_style(
            'wcudc-checkout',
            WCUDC_PLUGIN_URL . 'assets/css/checkout.css',
            array( 'leaflet' ),
            WCUDC_VERSION
        );

        // Leaflet JS
        wp_enqueue_script(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            array(),
            '1.9.4',
            true
        );

        // Script del mapa en checkout
        wp_enqueue_script(
            'wcudc-checkout-osm',
            WCUDC_PLUGIN_URL . 'assets/js/checkout-osm.js',
            array( 'jquery', 'leaflet' ),
            WCUDC_VERSION,
            true
        );

        // Obtener coordenadas guardadas (si existen)
        $saved_lat = '';
        $saved_lng = '';

        if ( WC()->session ) {
            $saved_lat = WC()->session->get( 'shipping_latitude', '' );
            $saved_lng = WC()->session->get( 'shipping_longitude', '' );
        }

        // Pasar configuración al JS
        wp_localize_script( 'wcudc-checkout-osm', 'wcudcCheckout', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'wcudc_checkout_nonce' ),
            'map'        => array(
                'defaultLat'  => self::DEFAULT_LAT,
                'defaultLng'  => self::DEFAULT_LNG,
                'defaultZoom' => self::DEFAULT_ZOOM,
                'savedLat'    => $saved_lat,
                'savedLng'    => $saved_lng,
            ),
            'nominatim'  => array(
                'url'         => 'https://nominatim.openstreetmap.org',
                'countryCode' => 'pe', // TODO: Hacer configurable
            ),
            'strings'    => array(
                'searchPlaceholder' => __( 'Buscar dirección...', 'wc-uber-direct-connect' ),
                'dragMarkerHint'    => __( 'Arrastra el marcador a tu ubicación exacta', 'wc-uber-direct-connect' ),
                'locating'          => __( 'Localizando...', 'wc-uber-direct-connect' ),
                'locationError'     => __( 'No se pudo obtener tu ubicación', 'wc-uber-direct-connect' ),
                'searchError'       => __( 'No se encontraron resultados', 'wc-uber-direct-connect' ),
                'confirmLocation'   => __( 'Confirmar ubicación', 'wc-uber-direct-connect' ),
            ),
        ) );
    }

    /**
     * Agrega el contenedor del mapa al formulario de checkout
     *
     * @param WC_Checkout $checkout Objeto del checkout.
     */
    public function add_map_container( $checkout ): void {
        ?>
        <div id="wcudc-map-section" class="wcudc-checkout-map-section">
            <h3><?php esc_html_e( 'Confirma tu ubicación de entrega', 'wc-uber-direct-connect' ); ?></h3>
            <p class="wcudc-map-description">
                <?php esc_html_e( 'Arrastra el marcador para indicar el punto exacto de entrega. Esto nos ayuda a que tu pedido llegue más rápido.', 'wc-uber-direct-connect' ); ?>
            </p>

            <!-- Buscador de direcciones -->
            <div class="wcudc-search-container">
                <input type="text"
                       id="wcudc-address-search"
                       class="wcudc-address-search"
                       placeholder="<?php esc_attr_e( 'Buscar dirección...', 'wc-uber-direct-connect' ); ?>"
                       autocomplete="off">
                <button type="button" id="wcudc-locate-me" class="wcudc-locate-btn" title="<?php esc_attr_e( 'Usar mi ubicación', 'wc-uber-direct-connect' ); ?>">
                    <span class="wcudc-locate-icon">📍</span>
                </button>
                <div id="wcudc-search-results" class="wcudc-search-results"></div>
            </div>

            <!-- Contenedor del mapa -->
            <div id="wcudc-delivery-map" class="wcudc-delivery-map"></div>

            <!-- Indicador de ubicación seleccionada -->
            <div id="wcudc-selected-location" class="wcudc-selected-location" style="display: none;">
                <span class="wcudc-location-icon">✓</span>
                <span id="wcudc-selected-address" class="wcudc-selected-address"></span>
            </div>

            <!-- Mensaje de zona de cobertura -->
            <div id="wcudc-coverage-status" class="wcudc-coverage-status"></div>
        </div>
        <?php
    }

    /**
     * Agrega campos ocultos de coordenadas al checkout
     *
     * @param array $fields Campos del checkout.
     * @return array
     */
    public function add_coordinate_fields( array $fields ): array {
        // Campo de latitud (oculto)
        $fields['billing']['billing_latitude'] = array(
            'type'     => 'hidden',
            'class'    => array( 'wcudc-coordinate-field' ),
            'required' => false,
            'default'  => '',
        );

        // Campo de longitud (oculto)
        $fields['billing']['billing_longitude'] = array(
            'type'     => 'hidden',
            'class'    => array( 'wcudc-coordinate-field' ),
            'required' => false,
            'default'  => '',
        );

        // También para shipping si es diferente
        $fields['shipping']['shipping_latitude'] = array(
            'type'     => 'hidden',
            'class'    => array( 'wcudc-coordinate-field' ),
            'required' => false,
            'default'  => '',
        );

        $fields['shipping']['shipping_longitude'] = array(
            'type'     => 'hidden',
            'class'    => array( 'wcudc-coordinate-field' ),
            'required' => false,
            'default'  => '',
        );

        return $fields;
    }

    /**
     * Guarda las coordenadas en el pedido al procesar el checkout
     *
     * @param int $order_id ID del pedido.
     */
    public function save_coordinates_to_order( int $order_id ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // Obtener coordenadas del POST o de la sesión
        $lat = '';
        $lng = '';

        // Primero intentar desde POST
        if ( ! empty( $_POST['billing_latitude'] ) ) {
            $lat = sanitize_text_field( $_POST['billing_latitude'] );
        } elseif ( ! empty( $_POST['shipping_latitude'] ) ) {
            $lat = sanitize_text_field( $_POST['shipping_latitude'] );
        }

        if ( ! empty( $_POST['billing_longitude'] ) ) {
            $lng = sanitize_text_field( $_POST['billing_longitude'] );
        } elseif ( ! empty( $_POST['shipping_longitude'] ) ) {
            $lng = sanitize_text_field( $_POST['shipping_longitude'] );
        }

        // Si no hay en POST, intentar desde sesión
        if ( empty( $lat ) && WC()->session ) {
            $lat = WC()->session->get( 'shipping_latitude', '' );
        }
        if ( empty( $lng ) && WC()->session ) {
            $lng = WC()->session->get( 'shipping_longitude', '' );
        }

        // Guardar en el pedido
        if ( $lat && $lng ) {
            $order->update_meta_data( '_shipping_latitude', $lat );
            $order->update_meta_data( '_shipping_longitude', $lng );
            $order->save();

            // Log
            if ( function_exists( 'wc_get_logger' ) ) {
                $logger = wc_get_logger();
                $logger->info(
                    sprintf( 'Pedido #%d: Coordenadas guardadas - Lat: %s, Lng: %s', $order_id, $lat, $lng ),
                    array( 'source' => 'wc-uber-direct-connect' )
                );
            }
        }
    }

    /**
     * Valida que las coordenadas estén presentes (opcional)
     */
    public function validate_coordinates(): void {
        // Solo validar si el método de envío seleccionado es Uber Direct
        $chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
        $is_uber_shipping = false;

        foreach ( $chosen_methods as $method ) {
            if ( strpos( $method, 'uber_direct' ) !== false ) {
                $is_uber_shipping = true;
                break;
            }
        }

        if ( ! $is_uber_shipping ) {
            return;
        }

        // Verificar coordenadas
        $lat = isset( $_POST['billing_latitude'] ) ? sanitize_text_field( $_POST['billing_latitude'] ) : '';
        $lng = isset( $_POST['billing_longitude'] ) ? sanitize_text_field( $_POST['billing_longitude'] ) : '';

        // Si no hay en POST, verificar sesión
        if ( empty( $lat ) || empty( $lng ) ) {
            if ( WC()->session ) {
                $lat = WC()->session->get( 'shipping_latitude', '' );
                $lng = WC()->session->get( 'shipping_longitude', '' );
            }
        }

        // Si sigue sin coordenadas, mostrar error (comentado por ahora para no bloquear)
        // if ( empty( $lat ) || empty( $lng ) ) {
        //     wc_add_notice(
        //         __( 'Por favor, selecciona tu ubicación en el mapa para continuar.', 'wc-uber-direct-connect' ),
        //         'error'
        //     );
        // }
    }

    /**
     * AJAX: Guarda las coordenadas en la sesión
     */
    public function ajax_save_coordinates(): void {
        check_ajax_referer( 'wcudc_checkout_nonce', 'nonce' );

        $lat = isset( $_POST['latitude'] ) ? floatval( $_POST['latitude'] ) : 0;
        $lng = isset( $_POST['longitude'] ) ? floatval( $_POST['longitude'] ) : 0;

        if ( ! $lat || ! $lng ) {
            wp_send_json_error( array( 'message' => 'Coordenadas inválidas' ) );
        }

        // Guardar en sesión
        if ( WC()->session ) {
            WC()->session->set( 'shipping_latitude', $lat );
            WC()->session->set( 'shipping_longitude', $lng );
        }

        // Verificar cobertura
        $coverage_info = $this->check_coverage( $lat, $lng );

        wp_send_json_success( array(
            'message'  => 'Coordenadas guardadas',
            'coverage' => $coverage_info,
        ) );
    }

    /**
     * Verifica la cobertura para unas coordenadas
     *
     * @param float $lat Latitud.
     * @param float $lng Longitud.
     * @return array Información de cobertura.
     */
    private function check_coverage( float $lat, float $lng ): array {
        $validator = wcudc_polygon_validator();
        $point     = array( 'lat' => $lat, 'lng' => $lng );

        $store = $validator->find_store_for_point( $point );

        if ( $store ) {
            return array(
                'has_coverage' => true,
                'store_name'   => $store['name'],
                'message'      => sprintf(
                    /* translators: %s: store name */
                    __( '¡Genial! Tu ubicación está dentro de nuestra zona de reparto (%s).', 'wc-uber-direct-connect' ),
                    $store['name']
                ),
            );
        }

        // Verificar tienda más cercana
        $nearest = $validator->find_nearest_store( $point );

        if ( $nearest ) {
            $distance = $validator->haversine_distance(
                $lat,
                $lng,
                (float) $nearest['latitude'],
                (float) $nearest['longitude']
            );

            return array(
                'has_coverage'   => false,
                'nearest_store'  => $nearest['name'],
                'distance'       => round( $distance, 1 ),
                'message'        => sprintf(
                    /* translators: 1: store name, 2: distance in km */
                    __( 'Tu ubicación está fuera de zona. La tienda más cercana es %1$s (%.1f km).', 'wc-uber-direct-connect' ),
                    $nearest['name'],
                    $distance
                ),
            );
        }

        return array(
            'has_coverage' => false,
            'message'      => __( 'Lo sentimos, no tenemos cobertura en tu zona actualmente.', 'wc-uber-direct-connect' ),
        );
    }
}
