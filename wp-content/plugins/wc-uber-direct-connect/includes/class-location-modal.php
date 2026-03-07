<?php
/**
 * Modal de Ubicación al Ingresar a la Tienda
 *
 * Muestra un popup para que el cliente ingrese su ubicación
 * antes de navegar por la tienda.
 *
 * @package WC_Uber_Direct_Connect
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase WCUDC_Location_Modal
 *
 * Maneja el modal de ubicación que aparece al ingresar a la tienda
 */
class WCUDC_Location_Modal {

    /**
     * Nombre de la cookie para guardar la ubicación
     */
    private const LOCATION_COOKIE = 'wcudc_customer_location';

    /**
     * Duración de la cookie en días
     */
    private const COOKIE_DURATION = 7;

    /**
     * Coordenadas por defecto (Santiago, Chile)
     */
    private const DEFAULT_LAT = -33.4489;
    private const DEFAULT_LNG = -70.6693;
    private const DEFAULT_ZOOM = 12;

    /**
     * Constructor
     */
    public function __construct() {
        // Solo en frontend
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        // Encolar assets
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Agregar modal al footer
        add_action( 'wp_footer', array( $this, 'render_modal' ) );

        // Agregar botón de ubicación al header (via hook de tema)
        add_action( 'wp_body_open', array( $this, 'render_location_button' ) );

        // AJAX handlers
        add_action( 'wp_ajax_wcudc_save_location', array( $this, 'ajax_save_location' ) );
        add_action( 'wp_ajax_nopriv_wcudc_save_location', array( $this, 'ajax_save_location' ) );
        add_action( 'wp_ajax_wcudc_get_location', array( $this, 'ajax_get_location' ) );
        add_action( 'wp_ajax_nopriv_wcudc_get_location', array( $this, 'ajax_get_location' ) );
        add_action( 'wp_ajax_wcudc_clear_location', array( $this, 'ajax_clear_location' ) );
        add_action( 'wp_ajax_nopriv_wcudc_clear_location', array( $this, 'ajax_clear_location' ) );

        // Endpoint principal para verificar zona de delivery (frontend checkout)
        add_action( 'wp_ajax_check_delivery_zone', array( $this, 'ajax_check_delivery_zone' ) );
        add_action( 'wp_ajax_nopriv_check_delivery_zone', array( $this, 'ajax_check_delivery_zone' ) );

        // Sincronizar cookie con sesión de WC
        add_action( 'woocommerce_init', array( $this, 'sync_location_to_session' ) );
    }

    /**
     * Encola los scripts y estilos
     */
    public function enqueue_assets(): void {
        // No cargar en admin, checkout (ya tiene su propio mapa), o carrito
        if ( is_admin() || is_checkout() ) {
            return;
        }

        // Leaflet CSS
        wp_enqueue_style(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            array(),
            '1.9.4'
        );

        // CSS del modal
        wp_enqueue_style(
            'wcudc-location-modal',
            WCUDC_PLUGIN_URL . 'assets/css/location-modal.css',
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

        // JS del modal
        wp_enqueue_script(
            'wcudc-location-modal',
            WCUDC_PLUGIN_URL . 'assets/js/location-modal.js',
            array( 'jquery', 'leaflet' ),
            WCUDC_VERSION,
            true
        );

        // Obtener ubicación guardada
        $saved_location = $this->get_saved_location();
        $has_location   = ! empty( $saved_location['lat'] ) && ! empty( $saved_location['lng'] );

        // Obtener tiendas activas para mostrar en el mapa
        $stores = $this->get_stores_for_map();

        // Pasar configuración al JS
        wp_localize_script( 'wcudc-location-modal', 'wcudcLocationModal', array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'wcudc_location_nonce' ),
            'hasLocation'  => $has_location,
            'savedLocation' => $saved_location,
            'stores'       => $stores,
            'map'          => array(
                'defaultLat'  => self::DEFAULT_LAT,
                'defaultLng'  => self::DEFAULT_LNG,
                'defaultZoom' => self::DEFAULT_ZOOM,
            ),
            'nominatim'    => array(
                'url'         => 'https://nominatim.openstreetmap.org',
                'countryCode' => 'cl',
            ),
            'strings'      => array(
                'modalTitle'        => __( '¿Dónde te entregamos?', 'wc-uber-direct-connect' ),
                'modalSubtitle'     => __( 'Ingresa tu dirección para ver si llegamos a tu zona', 'wc-uber-direct-connect' ),
                'searchPlaceholder' => __( 'Buscar tu dirección...', 'wc-uber-direct-connect' ),
                'useMyLocation'     => __( 'Usar mi ubicación', 'wc-uber-direct-connect' ),
                'confirmLocation'   => __( 'Confirmar ubicación', 'wc-uber-direct-connect' ),
                'changeLocation'    => __( 'Cambiar', 'wc-uber-direct-connect' ),
                'deliveryTo'        => __( 'Entrega en:', 'wc-uber-direct-connect' ),
                'pickupAt'          => __( 'Retiro en:', 'wc-uber-direct-connect' ),
                'locating'          => __( 'Localizando...', 'wc-uber-direct-connect' ),
                'locationError'     => __( 'No pudimos obtener tu ubicación', 'wc-uber-direct-connect' ),
                'searchError'       => __( 'No encontramos resultados', 'wc-uber-direct-connect' ),
                'coverageOk'        => __( '¡Genial! Llegamos a tu zona', 'wc-uber-direct-connect' ),
                'noCoverage'        => __( 'No llegamos a tu zona, pero puedes retirar en tienda', 'wc-uber-direct-connect' ),
                'selectStore'       => __( 'Selecciona una tienda para retiro:', 'wc-uber-direct-connect' ),
                'deliveryOption'    => __( 'Delivery a domicilio', 'wc-uber-direct-connect' ),
                'pickupOption'      => __( 'Retiro en tienda', 'wc-uber-direct-connect' ),
                'estimatedTime'     => __( 'Tiempo estimado:', 'wc-uber-direct-connect' ),
                'minutes'           => __( 'min', 'wc-uber-direct-connect' ),
                'free'              => __( 'Gratis', 'wc-uber-direct-connect' ),
            ),
        ) );
    }

    /**
     * Renderiza el botón de ubicación flotante/header
     */
    public function render_location_button(): void {
        if ( is_admin() || is_checkout() ) {
            return;
        }

        $location = $this->get_saved_location();
        $has_location = ! empty( $location['lat'] ) && ! empty( $location['lng'] );
        $display_text = $has_location ? $this->get_short_address( $location ) : __( 'Ingresa tu ubicación', 'wc-uber-direct-connect' );
        $delivery_type = $location['delivery_type'] ?? 'delivery';
        $icon = $delivery_type === 'pickup' ? '🏪' : '📍';

        ?>
        <div id="wcudc-location-bar" class="wcudc-location-bar <?php echo $has_location ? 'has-location' : 'no-location'; ?>">
            <button type="button" id="wcudc-open-location-modal" class="wcudc-location-bar-btn">
                <span class="wcudc-location-icon"><?php echo $icon; ?></span>
                <span class="wcudc-location-text" id="wcudc-location-display"><?php echo esc_html( $display_text ); ?></span>
                <span class="wcudc-location-change"><?php esc_html_e( 'Cambiar', 'wc-uber-direct-connect' ); ?></span>
            </button>
        </div>
        <?php
    }

    /**
     * Renderiza el modal de ubicación
     */
    public function render_modal(): void {
        if ( is_admin() || is_checkout() ) {
            return;
        }

        $location = $this->get_saved_location();
        $has_location = ! empty( $location['lat'] ) && ! empty( $location['lng'] );
        $stores = $this->get_active_stores();
        ?>
        <div id="wcudc-location-modal" class="wcudc-modal-overlay" style="<?php echo $has_location ? 'display:none;' : ''; ?>">
            <div class="wcudc-modal-container">
                <div class="wcudc-modal-header">
                    <h2 class="wcudc-modal-title"><?php esc_html_e( '¿Dónde te entregamos?', 'wc-uber-direct-connect' ); ?></h2>
                    <p class="wcudc-modal-subtitle"><?php esc_html_e( 'Ingresa tu dirección para verificar cobertura', 'wc-uber-direct-connect' ); ?></p>
                    <button type="button" class="wcudc-modal-close" id="wcudc-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'wc-uber-direct-connect' ); ?>">&times;</button>
                </div>

                <div class="wcudc-modal-body">
                    <!-- Buscador de direcciones -->
                    <div class="wcudc-search-box">
                        <div class="wcudc-search-input-wrapper">
                            <span class="wcudc-search-icon">🔍</span>
                            <input type="text"
                                   id="wcudc-modal-search"
                                   class="wcudc-search-input"
                                   placeholder="<?php esc_attr_e( 'Buscar tu dirección...', 'wc-uber-direct-connect' ); ?>"
                                   autocomplete="off">
                            <button type="button" id="wcudc-modal-geolocate" class="wcudc-geolocate-btn" title="<?php esc_attr_e( 'Usar mi ubicación', 'wc-uber-direct-connect' ); ?>">
                                <span class="wcudc-geolocate-icon">📍</span>
                            </button>
                        </div>
                        <div id="wcudc-modal-search-results" class="wcudc-search-results"></div>
                    </div>

                    <!-- Mapa -->
                    <div id="wcudc-modal-map" class="wcudc-modal-map"></div>

                    <!-- Estado de cobertura -->
                    <div id="wcudc-coverage-result" class="wcudc-coverage-result" style="display: none;">
                        <div class="wcudc-coverage-icon"></div>
                        <div class="wcudc-coverage-message"></div>
                    </div>

                    <!-- Opciones de entrega/retiro -->
                    <div id="wcudc-delivery-options" class="wcudc-delivery-options" style="display: none;">
                        <!-- Opción Delivery -->
                        <label class="wcudc-delivery-option" id="wcudc-option-delivery">
                            <input type="radio" name="wcudc_delivery_type" value="delivery" checked>
                            <div class="wcudc-option-content">
                                <span class="wcudc-option-icon">🛵</span>
                                <div class="wcudc-option-details">
                                    <span class="wcudc-option-title"><?php esc_html_e( 'Delivery a domicilio', 'wc-uber-direct-connect' ); ?></span>
                                    <span class="wcudc-option-store" id="wcudc-delivery-store"></span>
                                </div>
                            </div>
                        </label>

                        <!-- Opción Retiro -->
                        <label class="wcudc-delivery-option" id="wcudc-option-pickup">
                            <input type="radio" name="wcudc_delivery_type" value="pickup">
                            <div class="wcudc-option-content">
                                <span class="wcudc-option-icon">🏪</span>
                                <div class="wcudc-option-details">
                                    <span class="wcudc-option-title"><?php esc_html_e( 'Retiro en tienda', 'wc-uber-direct-connect' ); ?></span>
                                    <span class="wcudc-option-subtitle"><?php esc_html_e( 'Gratis', 'wc-uber-direct-connect' ); ?></span>
                                </div>
                            </div>
                        </label>

                        <!-- Selector de tienda para retiro -->
                        <div id="wcudc-pickup-stores" class="wcudc-pickup-stores" style="display: none;">
                            <label class="wcudc-pickup-label"><?php esc_html_e( 'Selecciona tienda para retiro:', 'wc-uber-direct-connect' ); ?></label>
                            <select id="wcudc-pickup-store-select" class="wcudc-pickup-select">
                                <?php foreach ( $stores as $store ) : ?>
                                    <option value="<?php echo esc_attr( $store['id'] ); ?>"
                                            data-lat="<?php echo esc_attr( $store['latitude'] ); ?>"
                                            data-lng="<?php echo esc_attr( $store['longitude'] ); ?>"
                                            data-address="<?php echo esc_attr( $store['address'] ); ?>">
                                        <?php echo esc_html( $store['name'] ); ?> - <?php echo esc_html( $store['address'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="wcudc-modal-footer">
                    <button type="button" id="wcudc-confirm-location" class="wcudc-confirm-btn" disabled>
                        <?php esc_html_e( 'Confirmar ubicación', 'wc-uber-direct-connect' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Obtiene las tiendas activas
     *
     * @return array
     */
    private function get_active_stores(): array {
        $stores = get_option( 'wcudc_stores', array() );
        return array_filter( $stores, fn( $s ) => ! empty( $s['active'] ) );
    }

    /**
     * Obtiene las tiendas formateadas para el mapa
     *
     * @return array
     */
    private function get_stores_for_map(): array {
        $stores = $this->get_active_stores();
        $formatted = array();

        foreach ( $stores as $store ) {
            $formatted[] = array(
                'id'        => $store['id'],
                'name'      => $store['name'],
                'address'   => $store['address'],
                'lat'       => (float) $store['latitude'],
                'lng'       => (float) $store['longitude'],
                'polygon'   => $store['polygon'] ?? array(),
            );
        }

        return $formatted;
    }

    /**
     * Obtiene la ubicación guardada
     *
     * @return array
     */
    public function get_saved_location(): array {
        // Primero intentar desde sesión de WC
        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_data = WC()->session->get( 'wcudc_location' );
            if ( $session_data ) {
                return $session_data;
            }
        }

        // Luego intentar desde cookie
        if ( isset( $_COOKIE[ self::LOCATION_COOKIE ] ) ) {
            $cookie_data = json_decode( stripslashes( $_COOKIE[ self::LOCATION_COOKIE ] ), true );
            if ( $cookie_data && isset( $cookie_data['lat'] ) ) {
                return $cookie_data;
            }
        }

        return array();
    }

    /**
     * Obtiene una dirección corta para mostrar
     *
     * @param array $location Datos de ubicación.
     * @return string
     */
    private function get_short_address( array $location ): string {
        if ( ! empty( $location['short_address'] ) ) {
            return $location['short_address'];
        }

        if ( ! empty( $location['address'] ) ) {
            $parts = explode( ',', $location['address'] );
            return trim( $parts[0] );
        }

        if ( ! empty( $location['store_name'] ) && ( $location['delivery_type'] ?? '' ) === 'pickup' ) {
            return sprintf( __( 'Retiro en %s', 'wc-uber-direct-connect' ), $location['store_name'] );
        }

        return __( 'Ubicación seleccionada', 'wc-uber-direct-connect' );
    }

    /**
     * Sincroniza la ubicación de cookie a sesión de WC
     */
    public function sync_location_to_session(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        // Si ya hay datos en sesión, no hacer nada
        if ( WC()->session->get( 'wcudc_location' ) ) {
            return;
        }

        // Copiar desde cookie si existe
        if ( isset( $_COOKIE[ self::LOCATION_COOKIE ] ) ) {
            $cookie_data = json_decode( stripslashes( $_COOKIE[ self::LOCATION_COOKIE ] ), true );
            if ( $cookie_data && isset( $cookie_data['lat'] ) ) {
                WC()->session->set( 'wcudc_location', $cookie_data );

                // También guardar coordenadas individuales para el shipping
                WC()->session->set( 'shipping_latitude', $cookie_data['lat'] );
                WC()->session->set( 'shipping_longitude', $cookie_data['lng'] );

                if ( ! empty( $cookie_data['store_id'] ) ) {
                    WC()->session->set( 'wcudc_assigned_store_id', $cookie_data['store_id'] );
                }
            }
        }
    }

    /**
     * AJAX: Guardar ubicación
     */
    public function ajax_save_location(): void {
        check_ajax_referer( 'wcudc_location_nonce', 'nonce' );

        $lat           = isset( $_POST['lat'] ) ? floatval( $_POST['lat'] ) : 0;
        $lng           = isset( $_POST['lng'] ) ? floatval( $_POST['lng'] ) : 0;
        $address       = isset( $_POST['address'] ) ? sanitize_text_field( $_POST['address'] ) : '';
        $short_address = isset( $_POST['short_address'] ) ? sanitize_text_field( $_POST['short_address'] ) : '';
        $delivery_type = isset( $_POST['delivery_type'] ) ? sanitize_key( $_POST['delivery_type'] ) : 'delivery';
        $store_id      = isset( $_POST['store_id'] ) ? sanitize_key( $_POST['store_id'] ) : '';
        $store_name    = isset( $_POST['store_name'] ) ? sanitize_text_field( $_POST['store_name'] ) : '';

        if ( ! $lat || ! $lng ) {
            wp_send_json_error( array( 'message' => __( 'Coordenadas inválidas', 'wc-uber-direct-connect' ) ) );
        }

        // Verificar cobertura
        $coverage = $this->check_coverage( $lat, $lng );

        // Si es delivery y no hay cobertura, devolver error (pero frontend manejará)
        if ( $delivery_type === 'delivery' && ! $coverage['has_coverage'] ) {
            // Aún así guardamos para que pueda elegir retiro
        }

        // Preparar datos
        $location_data = array(
            'lat'           => $lat,
            'lng'           => $lng,
            'address'       => $address,
            'short_address' => $short_address,
            'delivery_type' => $delivery_type,
            'store_id'      => $delivery_type === 'delivery' ? ( $coverage['store_id'] ?? '' ) : $store_id,
            'store_name'    => $delivery_type === 'delivery' ? ( $coverage['store_name'] ?? '' ) : $store_name,
            'has_coverage'  => $coverage['has_coverage'],
            'timestamp'     => time(),
        );

        // Guardar en cookie
        $cookie_value = wp_json_encode( $location_data );
        setcookie(
            self::LOCATION_COOKIE,
            $cookie_value,
            time() + ( self::COOKIE_DURATION * DAY_IN_SECONDS ),
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            false
        );

        // Guardar en sesión de WC
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'wcudc_location', $location_data );
            WC()->session->set( 'shipping_latitude', $lat );
            WC()->session->set( 'shipping_longitude', $lng );

            if ( ! empty( $location_data['store_id'] ) ) {
                WC()->session->set( 'wcudc_assigned_store_id', $location_data['store_id'] );
            }

            // Guardar tipo de entrega
            WC()->session->set( 'wcudc_delivery_type', $delivery_type );
        }

        wp_send_json_success( array(
            'message'   => __( 'Ubicación guardada', 'wc-uber-direct-connect' ),
            'location'  => $location_data,
            'coverage'  => $coverage,
        ) );
    }

    /**
     * AJAX: Obtener ubicación guardada
     */
    public function ajax_get_location(): void {
        check_ajax_referer( 'wcudc_location_nonce', 'nonce' );

        $location = $this->get_saved_location();

        if ( empty( $location ) ) {
            wp_send_json_error( array( 'message' => __( 'No hay ubicación guardada', 'wc-uber-direct-connect' ) ) );
        }

        wp_send_json_success( array( 'location' => $location ) );
    }

    /**
     * AJAX: Limpiar ubicación
     */
    public function ajax_clear_location(): void {
        check_ajax_referer( 'wcudc_location_nonce', 'nonce' );

        // Eliminar cookie
        setcookie( self::LOCATION_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );

        // Limpiar sesión
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'wcudc_location', null );
            WC()->session->set( 'shipping_latitude', null );
            WC()->session->set( 'shipping_longitude', null );
            WC()->session->set( 'wcudc_assigned_store_id', null );
            WC()->session->set( 'wcudc_delivery_type', null );
        }

        wp_send_json_success( array( 'message' => __( 'Ubicación eliminada', 'wc-uber-direct-connect' ) ) );
    }

    /**
     * AJAX: Verificar zona de delivery (endpoint principal para frontend)
     *
     * Recibe: lat, lng del cliente
     * Procesa: Usa el validador de polígonos para encontrar la tienda
     * Responde: { success: true, store_id: 'xxx' } o { success: false, message: '...' }
     */
    public function ajax_check_delivery_zone(): void {
        // Verificar nonce (acepta ambos para flexibilidad)
        $nonce_valid = wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'wcudc_location_nonce' ) ||
                       wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'wcudc_checkout_nonce' );

        if ( ! $nonce_valid ) {
            wp_send_json_error( array(
                'message' => __( 'Sesión expirada. Recarga la página.', 'wc-uber-direct-connect' ),
                'code'    => 'invalid_nonce',
            ) );
        }

        // Obtener coordenadas
        $lat = isset( $_REQUEST['lat'] ) ? floatval( $_REQUEST['lat'] ) : 0;
        $lng = isset( $_REQUEST['lng'] ) ? floatval( $_REQUEST['lng'] ) : 0;

        // Validar coordenadas
        if ( $lat === 0.0 || $lng === 0.0 ) {
            wp_send_json_error( array(
                'message' => __( 'Coordenadas inválidas.', 'wc-uber-direct-connect' ),
                'code'    => 'invalid_coordinates',
            ) );
        }

        // Validar rangos
        if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
            wp_send_json_error( array(
                'message' => __( 'Coordenadas fuera de rango.', 'wc-uber-direct-connect' ),
                'code'    => 'out_of_range',
            ) );
        }

        // Usar el validador de polígonos
        $validator = wcudc_polygon_validator();
        $point     = array( 'lat' => $lat, 'lng' => $lng );

        // Buscar tienda que cubra el punto
        $store = $validator->find_store_for_point( $point );

        if ( $store ) {
            // Cliente dentro de zona de cobertura
            wp_send_json_success( array(
                'in_zone'       => true,
                'store_id'      => $store['id'],
                'store_name'    => $store['name'],
                'store_address' => $store['address'] ?? '',
                'store_phone'   => $store['phone'] ?? '',
                'store_lat'     => $store['latitude'] ?? 0,
                'store_lng'     => $store['longitude'] ?? 0,
                'message'       => sprintf(
                    /* translators: %s: store name */
                    __( '¡Excelente! Hacemos delivery a tu zona desde %s', 'wc-uber-direct-connect' ),
                    $store['name']
                ),
            ) );
        }

        // Cliente fuera de zona - buscar tienda más cercana como referencia
        $nearest = $validator->find_nearest_store( $point );

        $response = array(
            'in_zone'   => false,
            'message'   => __( 'Lo sentimos, no llegamos a tu zona con delivery.', 'wc-uber-direct-connect' ),
            'pickup_available' => true,
            'pickup_message'   => __( 'Puedes retirar tu pedido en cualquiera de nuestras tiendas.', 'wc-uber-direct-connect' ),
        );

        if ( $nearest ) {
            $distance = $validator->haversine_distance(
                $lat,
                $lng,
                (float) $nearest['latitude'],
                (float) $nearest['longitude']
            );

            $response['nearest_store'] = array(
                'id'       => $nearest['id'],
                'name'     => $nearest['name'],
                'address'  => $nearest['address'] ?? '',
                'distance' => round( $distance, 2 ),
                'lat'      => $nearest['latitude'],
                'lng'      => $nearest['longitude'],
            );
        }

        // Agregar lista de tiendas para retiro
        $response['pickup_stores'] = $this->get_stores_for_pickup();

        wp_send_json_error( $response );
    }

    /**
     * Obtiene las tiendas disponibles para retiro
     *
     * @return array
     */
    private function get_stores_for_pickup(): array {
        $stores = $this->get_active_stores();
        $pickup_stores = array();

        foreach ( $stores as $store ) {
            $pickup_stores[] = array(
                'id'      => $store['id'],
                'name'    => $store['name'],
                'address' => $store['address'] ?? '',
                'phone'   => $store['phone'] ?? '',
                'lat'     => $store['latitude'] ?? 0,
                'lng'     => $store['longitude'] ?? 0,
            );
        }

        return $pickup_stores;
    }

    /**
     * Verifica la cobertura para unas coordenadas
     *
     * @param float $lat Latitud.
     * @param float $lng Longitud.
     * @return array
     */
    private function check_coverage( float $lat, float $lng ): array {
        $validator = wcudc_polygon_validator();
        $point     = array( 'lat' => $lat, 'lng' => $lng );

        $store = $validator->find_store_for_point( $point );

        if ( $store ) {
            return array(
                'has_coverage' => true,
                'store_id'     => $store['id'],
                'store_name'   => $store['name'],
                'store_address' => $store['address'],
                'message'      => sprintf(
                    __( '¡Llegamos a tu zona! Envío desde %s', 'wc-uber-direct-connect' ),
                    $store['name']
                ),
            );
        }

        // Sin cobertura - buscar tienda más cercana para info
        $nearest = $validator->find_nearest_store( $point );

        return array(
            'has_coverage'   => false,
            'nearest_store'  => $nearest ? $nearest['name'] : null,
            'nearest_id'     => $nearest ? $nearest['id'] : null,
            'message'        => __( 'No llegamos a tu zona, pero puedes retirar tu pedido en tienda.', 'wc-uber-direct-connect' ),
        );
    }

    /**
     * Obtiene el tipo de entrega seleccionado
     *
     * @return string 'delivery' o 'pickup'
     */
    public static function get_delivery_type(): string {
        if ( function_exists( 'WC' ) && WC()->session ) {
            return WC()->session->get( 'wcudc_delivery_type', 'delivery' );
        }

        if ( isset( $_COOKIE[ self::LOCATION_COOKIE ] ) ) {
            $data = json_decode( stripslashes( $_COOKIE[ self::LOCATION_COOKIE ] ), true );
            return $data['delivery_type'] ?? 'delivery';
        }

        return 'delivery';
    }

    /**
     * Verifica si el cliente tiene cobertura de delivery
     *
     * @return bool
     */
    public static function has_delivery_coverage(): bool {
        if ( function_exists( 'WC' ) && WC()->session ) {
            $location = WC()->session->get( 'wcudc_location' );
            return ! empty( $location['has_coverage'] );
        }

        return false;
    }
}
