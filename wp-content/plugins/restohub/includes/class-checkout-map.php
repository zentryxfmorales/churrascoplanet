<?php
/**
 * Mapa de OpenStreetMap en el Checkout
 *
 * Maneja la integración de Leaflet en el checkout para
 * que el cliente seleccione su ubicación exacta.
 *
 * @package RestoHub
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase RestoHub_Checkout_Map
 *
 * Agrega un mapa interactivo al checkout de WooCommerce
 * usando OpenStreetMap y Leaflet
 */
class RestoHub_Checkout_Map {

    /**
     * Coordenadas por defecto (Santiago, Chile - Maipú)
     * TODO: Hacer configurable desde admin
     */
    private const DEFAULT_LAT = -33.5117;
    private const DEFAULT_LNG = -70.7578;
    private const DEFAULT_ZOOM = 13;

    /**
     * Constructor
     */
    public function __construct() {
        // Encolar scripts y estilos en checkout
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );

        // Toggle Delivery/Retiro + Dirección (antes del formulario de billing)
        add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'render_delivery_toggle' ), 5 );
        add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'render_address_selector' ), 10 );

        // Mapa de confirmación (después del formulario de billing)
        add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'add_map_container' ) );
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_coordinates_to_order' ) );

        // Modificar campos del checkout: campo único de dirección + coordenadas ocultas
        add_filter( 'woocommerce_checkout_fields', array( $this, 'modify_address_fields' ), 20 );
        add_filter( 'woocommerce_checkout_fields', array( $this, 'add_coordinate_fields' ), 30 );

        // Guardar coordenadas en la sesión vía AJAX
        add_action( 'wp_ajax_restohub_save_coordinates', array( $this, 'ajax_save_coordinates' ) );
        add_action( 'wp_ajax_nopriv_restohub_save_coordinates', array( $this, 'ajax_save_coordinates' ) );

        // Sincronizar ubicación completa desde localStorage
        add_action( 'wp_ajax_restohub_sync_location', array( $this, 'ajax_sync_location' ) );
        add_action( 'wp_ajax_nopriv_restohub_sync_location', array( $this, 'ajax_sync_location' ) );

        // Switch de modo Delivery/Retiro desde el checkout
        add_action( 'wp_ajax_restohub_switch_delivery_mode', array( $this, 'ajax_switch_delivery_mode' ) );
        add_action( 'wp_ajax_nopriv_restohub_switch_delivery_mode', array( $this, 'ajax_switch_delivery_mode' ) );

        // Scheduling: AJAX endpoints
        add_action( 'wp_ajax_restohub_get_schedule_slots', array( $this, 'ajax_get_schedule_slots' ) );
        add_action( 'wp_ajax_nopriv_restohub_get_schedule_slots', array( $this, 'ajax_get_schedule_slots' ) );
        add_action( 'wp_ajax_restohub_save_schedule', array( $this, 'ajax_save_schedule' ) );
        add_action( 'wp_ajax_nopriv_restohub_save_schedule', array( $this, 'ajax_save_schedule' ) );

        // Scheduling: render selector (priority 7 = between delivery toggle(5) and address(10))
        add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'render_schedule_selector' ), 7 );

        // Validar que las coordenadas estén presentes
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_coordinates' ) );

        // Validar schedule al procesar checkout
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_schedule' ) );

        // Filtrar métodos de envío según el modo de entrega seleccionado
        add_filter( 'woocommerce_package_rates', array( $this, 'filter_shipping_methods' ), 100, 2 );

        // Mostrar schedule en admin order detail
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_schedule_in_admin' ) );

        // Agregar schedule a emails
        add_action( 'woocommerce_email_after_order_table', array( $this, 'add_schedule_to_email' ), 10, 4 );

        // Columna de schedule en listado de órdenes
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_schedule_column' ), 20 );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_schedule_column' ), 10, 2 );
    }

    /**
     * Filtra los métodos de envío según el modo de entrega
     *
     * @param array $rates Métodos de envío disponibles.
     * @param array $package Paquete de envío.
     * @return array
     */
    public function filter_shipping_methods( array $rates, array $package ): array {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return $rates;
        }

        // Obtener el tipo de entrega desde la sesión
        $delivery_type = 'delivery'; // Por defecto

        if ( WC()->session ) {
            $location_data = WC()->session->get( 'restohub_location' );
            if ( $location_data && isset( $location_data['delivery_type'] ) ) {
                $delivery_type = $location_data['delivery_type'];
            }
        }

        // Si es modo retiro, ocultar delivery (Uber Direct)
        if ( $delivery_type === 'pickup' ) {
            foreach ( $rates as $rate_id => $rate ) {
                if ( strpos( $rate_id, 'uber_direct' ) !== false ) {
                    unset( $rates[ $rate_id ] );
                }
            }
        }
        // Si es modo delivery, ocultar retiro en tienda
        else {
            foreach ( $rates as $rate_id => $rate ) {
                if ( strpos( $rate_id, 'local_pickup' ) !== false || strpos( $rate_id, 'pickup' ) !== false ) {
                    // Solo ocultar si NO es uber_direct (pickup propio del plugin)
                    if ( strpos( $rate_id, 'uber_direct' ) === false ) {
                        unset( $rates[ $rate_id ] );
                    }
                }
            }
        }

        return $rates;
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
            'restohub-checkout',
            RESTOHUB_PLUGIN_URL . 'assets/css/checkout.css',
            array( 'leaflet' ),
            RESTOHUB_VERSION
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
            'restohub-checkout-osm',
            RESTOHUB_PLUGIN_URL . 'assets/js/checkout-osm.js',
            array( 'jquery', 'leaflet' ),
            RESTOHUB_VERSION,
            true
        );

        // Obtener datos de ubicación guardados (si existen)
        $saved_lat      = '';
        $saved_lng      = '';
        $delivery_type  = 'delivery';
        $location_data  = array();

        if ( WC()->session ) {
            $saved_lat = WC()->session->get( 'shipping_latitude', '' );
            $saved_lng = WC()->session->get( 'shipping_longitude', '' );

            // Obtener datos completos de ubicación
            $restohub_location = WC()->session->get( 'restohub_location' );
            if ( $restohub_location ) {
                $location_data = $restohub_location;
                $delivery_type = $restohub_location['delivery_type'] ?? 'delivery';

                // Usar coordenadas de la ubicación si no hay en sesión de shipping
                if ( empty( $saved_lat ) && ! empty( $restohub_location['lat'] ) ) {
                    $saved_lat = $restohub_location['lat'];
                    $saved_lng = $restohub_location['lng'];
                }
            }
        }

        // Obtener direcciones guardadas del perfil del usuario (si está logueado)
        $saved_addresses = $this->get_user_saved_addresses();

        // Datos de scheduling
        $scheduling_data = array(
            'enabled' => false,
        );
        if ( function_exists( 'restohub_scheduling' ) && restohub_scheduling()->is_enabled() ) {
            $scheduling_data = array(
                'enabled'         => true,
                'mode'            => restohub_scheduling()->get_scheduling_mode(),
                'isStoreOpen'     => restohub_scheduling()->is_store_currently_open(),
                'currentSchedule' => WC()->session ? WC()->session->get( 'restohub_schedule', array() ) : array(),
                'dates'           => restohub_scheduling()->get_schedulable_dates(),
            );
        }

        // Pasar configuración al JS
        wp_localize_script( 'restohub-checkout-osm', 'restoHubCheckout', array(
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'restohub_checkout_nonce' ),
            'deliveryType'   => $delivery_type,
            'locationData'   => $location_data,
            'hasLocation'    => ! empty( $saved_lat ) && ! empty( $saved_lng ),
            'savedAddresses' => $saved_addresses,
            'scheduling'     => $scheduling_data,
            'map'            => array(
                'defaultLat'  => self::DEFAULT_LAT,
                'defaultLng'  => self::DEFAULT_LNG,
                'defaultZoom' => self::DEFAULT_ZOOM,
                'savedLat'    => $saved_lat,
                'savedLng'    => $saved_lng,
            ),
            'nominatim'      => array(
                'url'         => 'https://nominatim.openstreetmap.org',
                'countryCode' => 'cl', // Chile - TODO: Hacer configurable
            ),
            'strings'        => array(
                'searchPlaceholder' => __( 'Buscar dirección...', 'restohub' ),
                'dragMarkerHint'    => __( 'Arrastra el marcador a tu ubicación exacta', 'restohub' ),
                'locating'          => __( 'Localizando...', 'restohub' ),
                'locationError'     => __( 'No se pudo obtener tu ubicación', 'restohub' ),
                'searchError'       => __( 'No se encontraron resultados', 'restohub' ),
                'confirmLocation'   => __( 'Confirmar ubicación', 'restohub' ),
                'selectLocation'    => __( 'Selecciona tu ubicación de entrega', 'restohub' ),
            ),
        ) );
    }

    /**
     * Obtiene las direcciones guardadas del usuario logueado
     *
     * @return array Lista de direcciones disponibles.
     */
    private function get_user_saved_addresses(): array {
        $addresses = array();

        if ( ! is_user_logged_in() ) {
            return $addresses;
        }

        $customer = new WC_Customer( get_current_user_id() );

        // Dirección de entrega guardada en perfil
        $shipping_address_1 = $customer->get_shipping_address_1();
        if ( ! empty( $shipping_address_1 ) ) {
            $addresses[] = array(
                'type'      => 'shipping',
                'label'     => __( 'Dirección de entrega guardada', 'restohub' ),
                'address_1' => $shipping_address_1,
                'address_2' => $customer->get_shipping_address_2(),
                'city'      => $customer->get_shipping_city(),
                'state'     => $customer->get_shipping_state(),
                'postcode'  => $customer->get_shipping_postcode(),
                'country'   => $customer->get_shipping_country(),
            );
        }

        return $addresses;
    }

    /**
     * Renderiza el selector de direcciones guardadas en el checkout
     *
     * Muestra opciones para elegir entre la dirección del modal de delivery
     * y las direcciones guardadas en el perfil del usuario.
     *
     * @param WC_Checkout $checkout Objeto del checkout.
     */
    /**
     * Renderiza el toggle Delivery / Retiro en tienda
     *
     * @param WC_Checkout $checkout Objeto del checkout.
     */
    public function render_delivery_toggle( $checkout ): void {
        // Obtener modo actual desde sesión
        $delivery_type = 'delivery';
        if ( WC()->session ) {
            $restohub_location = WC()->session->get( 'restohub_location' );
            if ( $restohub_location && ! empty( $restohub_location['delivery_type'] ) ) {
                $delivery_type = $restohub_location['delivery_type'];
            }
        }

        // Obtener dirección del modal
        $modal_address = '';
        $modal_full    = '';
        if ( WC()->session ) {
            $restohub_location = WC()->session->get( 'restohub_location' );
            if ( $restohub_location && ! empty( $restohub_location['address'] ) ) {
                $modal_address = $restohub_location['address'];
                $modal_full    = $restohub_location['full_address'] ?? $modal_address;
            }
        }

        // Obtener tiendas activas para retiro
        $stores = array();
        if ( function_exists( 'restohub_store_repository' ) ) {
            $stores = restohub_store_repository()->get_active();
        } else {
            $stores = get_option( 'restohub_stores', array() );
            $stores = array_filter( $stores, fn( $s ) => ! empty( $s['active'] ) );
        }

        $selected_store = $restohub_location['store_name'] ?? '';
        ?>
        <div id="restohub-checkout-delivery-toggle" class="restohub-checkout-toggle">
            <!-- Toggle Delivery / Retiro -->
            <div class="restohub-checkout-mode-switch">
                <button type="button"
                        class="restohub-checkout-mode-btn <?php echo $delivery_type === 'delivery' ? 'active' : ''; ?>"
                        data-mode="delivery">
                    <span class="restohub-checkout-mode-icon">🛵</span>
                    <span class="restohub-checkout-mode-label"><?php esc_html_e( 'Delivery', 'restohub' ); ?></span>
                </button>
                <button type="button"
                        class="restohub-checkout-mode-btn <?php echo $delivery_type === 'pickup' ? 'active' : ''; ?>"
                        data-mode="pickup">
                    <span class="restohub-checkout-mode-icon">🏪</span>
                    <span class="restohub-checkout-mode-label"><?php esc_html_e( 'Retiro en tienda', 'restohub' ); ?></span>
                </button>
            </div>

            <!-- Info de dirección para Delivery -->
            <div id="restohub-checkout-delivery-info" class="restohub-checkout-delivery-info" <?php echo $delivery_type !== 'delivery' ? 'style="display:none;"' : ''; ?>>
                <?php if ( ! empty( $modal_full ) ) : ?>
                    <div class="restohub-checkout-address-display">
                        <span class="restohub-checkout-address-icon">📍</span>
                        <div class="restohub-checkout-address-text">
                            <span class="restohub-checkout-address-label"><?php esc_html_e( 'Dirección de entrega', 'restohub' ); ?></span>
                            <span class="restohub-checkout-address-value" id="restohub-checkout-address-value"><?php echo esc_html( $modal_full ); ?></span>
                        </div>
                        <span class="restohub-checkout-address-check">✅</span>
                    </div>
                <?php else : ?>
                    <div class="restohub-checkout-address-display restohub-no-address">
                        <span class="restohub-checkout-address-icon">📍</span>
                        <div class="restohub-checkout-address-text">
                            <span class="restohub-checkout-address-label"><?php esc_html_e( 'Dirección de entrega', 'restohub' ); ?></span>
                            <span class="restohub-checkout-address-value"><?php esc_html_e( 'No has seleccionado una dirección', 'restohub' ); ?></span>
                        </div>
                        <span class="restohub-checkout-address-warning">⚠️</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Selector de tienda para Retiro -->
            <div id="restohub-checkout-pickup-info" class="restohub-checkout-pickup-info" <?php echo $delivery_type !== 'pickup' ? 'style="display:none;"' : ''; ?>>
                <?php if ( ! empty( $stores ) ) : ?>
                    <div class="restohub-checkout-store-select">
                        <span class="restohub-checkout-store-icon">🏪</span>
                        <div class="restohub-checkout-store-text">
                            <span class="restohub-checkout-store-label"><?php esc_html_e( 'Retiro en', 'restohub' ); ?></span>
                            <select id="restohub-checkout-store-selector" class="restohub-checkout-store-dropdown">
                                <?php foreach ( $stores as $store ) : ?>
                                    <option value="<?php echo esc_attr( $store['id'] ); ?>"
                                            data-lat="<?php echo esc_attr( $store['latitude'] ?? '' ); ?>"
                                            data-lng="<?php echo esc_attr( $store['longitude'] ?? '' ); ?>"
                                            <?php selected( $selected_store, $store['name'] ); ?>>
                                        <?php echo esc_html( $store['name'] ); ?> - <?php echo esc_html( $store['address'] ?? '' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <span class="restohub-checkout-store-check">✅</span>
                    </div>
                <?php else : ?>
                    <p class="restohub-checkout-no-stores"><?php esc_html_e( 'No hay tiendas disponibles para retiro.', 'restohub' ); ?></p>
                <?php endif; ?>
            </div>

            <!-- Input oculto para el modo de entrega -->
            <input type="hidden" id="restohub-checkout-delivery-type" name="restohub_delivery_type" value="<?php echo esc_attr( $delivery_type ); ?>">
        </div>
        <?php
    }

    public function render_address_selector( $checkout ): void {
        // Obtener dirección del modal de delivery
        $modal_address = '';
        $modal_full    = '';
        if ( WC()->session ) {
            $restohub_location = WC()->session->get( 'restohub_location' );
            if ( $restohub_location && ! empty( $restohub_location['address'] ) ) {
                $modal_address = $restohub_location['address'];
                $modal_full    = $restohub_location['full_address'] ?? $modal_address;
            }
        }

        // Obtener direcciones del perfil
        $saved_addresses = $this->get_user_saved_addresses();
        $has_options     = ! empty( $modal_address ) || ! empty( $saved_addresses );

        // Determinar dirección inicial para el campo único
        $initial_address = $modal_full ?: $modal_address;
        if ( empty( $initial_address ) && ! empty( $saved_addresses ) ) {
            $first = $saved_addresses[0];
            $initial_address = implode( ', ', array_filter( array(
                $first['address_1'],
                $first['city'],
                $first['state'],
            ) ) );
        }

        ?>
        <!-- Campo único de dirección con autocompletado -->
        <div id="restohub-unified-address" class="restohub-unified-address">
            <label class="restohub-unified-address-label" for="restohub-unified-address-input">
                <?php esc_html_e( 'Dirección de entrega', 'restohub' ); ?>
            </label>
            <div class="restohub-unified-address-wrapper">
                <span class="restohub-unified-address-icon">📍</span>
                <input type="text"
                       id="restohub-unified-address-input"
                       class="restohub-unified-address-input"
                       placeholder="<?php esc_attr_e( 'Ingresa tu dirección, calle y número', 'restohub' ); ?>"
                       autocomplete="off"
                       value="<?php echo esc_attr( $initial_address ); ?>">
                <div id="restohub-unified-address-results" class="restohub-unified-address-results"></div>
            </div>
        </div>

        <?php if ( $has_options && ( ! empty( $modal_address ) && ! empty( $saved_addresses ) ) ) : ?>
        <!-- Selector de direcciones guardadas (solo si hay múltiples opciones) -->
        <div id="restohub-address-selector" class="restohub-address-selector">
            <label class="restohub-address-selector-label">
                <?php esc_html_e( 'O elige una dirección guardada:', 'restohub' ); ?>
            </label>
            <div class="restohub-address-options">
                <?php if ( ! empty( $modal_address ) ) : ?>
                    <label class="restohub-address-option restohub-address-option-active">
                        <input type="radio" name="restohub_address_source" value="modal" checked>
                        <span class="restohub-address-option-icon">🛵</span>
                        <span class="restohub-address-option-content">
                            <span class="restohub-address-option-title"><?php esc_html_e( 'Dirección de delivery', 'restohub' ); ?></span>
                            <span class="restohub-address-option-detail"><?php echo esc_html( $modal_address ); ?></span>
                        </span>
                    </label>
                <?php endif; ?>

                <?php foreach ( $saved_addresses as $index => $addr ) : ?>
                    <label class="restohub-address-option"
                           data-address-1="<?php echo esc_attr( $addr['address_1'] ); ?>"
                           data-address-2="<?php echo esc_attr( $addr['address_2'] ); ?>"
                           data-city="<?php echo esc_attr( $addr['city'] ); ?>"
                           data-state="<?php echo esc_attr( $addr['state'] ); ?>"
                           data-postcode="<?php echo esc_attr( $addr['postcode'] ); ?>"
                           data-country="<?php echo esc_attr( $addr['country'] ); ?>">
                        <input type="radio"
                               name="restohub_address_source"
                               value="profile_<?php echo esc_attr( $index ); ?>"
                               <?php echo empty( $modal_address ) && $index === 0 ? 'checked' : ''; ?>>
                        <span class="restohub-address-option-icon">📋</span>
                        <span class="restohub-address-option-content">
                            <span class="restohub-address-option-title"><?php echo esc_html( $addr['label'] ); ?></span>
                            <span class="restohub-address-option-detail">
                                <?php echo esc_html( $addr['address_1'] ); ?>
                                <?php if ( ! empty( $addr['city'] ) ) : ?>
                                    , <?php echo esc_html( $addr['city'] ); ?>
                                <?php endif; ?>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Modifica los campos de dirección del checkout
     *
     * Oculta los campos separados (address_1, city, state) y los convierte
     * en campos ocultos que se rellenan automáticamente desde el campo
     * único de dirección con autocompletado Nominatim.
     *
     * @param array $fields Campos del checkout.
     * @return array
     */
    public function modify_address_fields( array $fields ): array {
        $hidden_fields = array( 'billing_address_1', 'billing_city', 'billing_state' );

        foreach ( $hidden_fields as $field_key ) {
            if ( isset( $fields['billing'][ $field_key ] ) ) {
                $fields['billing'][ $field_key ]['class']    = array( 'restohub-hidden-field' );
                $fields['billing'][ $field_key ]['required'] = false;
            }
        }

        return $fields;
    }

    /**
     * Agrega el contenedor del mapa al formulario de checkout
     *
     * @param WC_Checkout $checkout Objeto del checkout.
     */
    public function add_map_container( $checkout ): void {
        ?>
        <div id="restohub-map-section" class="restohub-checkout-map-section">

            <!-- Trigger colapsable -->
            <button type="button"
                    id="restohub-map-toggle"
                    class="restohub-map-toggle-trigger"
                    aria-expanded="false"
                    aria-controls="restohub-map-collapsible">
                <span class="restohub-map-trigger-icon">📍</span>
                <span class="restohub-map-trigger-text">
                    <span class="restohub-map-trigger-title"><?php esc_html_e( 'Confirma tu ubicación de entrega', 'restohub' ); ?></span>
                    <span class="restohub-map-trigger-subtitle" id="restohub-map-trigger-subtitle"><?php esc_html_e( 'Toca para ajustar el punto exacto en el mapa', 'restohub' ); ?></span>
                </span>
                <span class="restohub-map-trigger-chevron" aria-hidden="true">&#8964;</span>
            </button>

            <!-- Contenido colapsable -->
            <div id="restohub-map-collapsible" class="restohub-map-collapsible" aria-hidden="true">
                <p class="restohub-map-description">
                    <?php esc_html_e( 'Arrastra el marcador para indicar el punto exacto de entrega. Esto nos ayuda a que tu pedido llegue más rápido.', 'restohub' ); ?>
                </p>

                <!-- Buscador de direcciones -->
                <div class="restohub-search-container">
                    <input type="text"
                           id="restohub-address-search"
                           class="restohub-address-search"
                           placeholder="<?php esc_attr_e( 'Buscar dirección...', 'restohub' ); ?>"
                           autocomplete="off">
                    <button type="button" id="restohub-locate-me" class="restohub-locate-btn" title="<?php esc_attr_e( 'Usar mi ubicación', 'restohub' ); ?>">
                        <span class="restohub-locate-icon">📍</span>
                    </button>
                    <div id="restohub-search-results" class="restohub-search-results"></div>
                </div>

                <!-- Contenedor del mapa -->
                <div id="restohub-delivery-map" class="restohub-delivery-map"></div>

                <!-- Indicador de ubicación seleccionada -->
                <div id="restohub-selected-location" class="restohub-selected-location" style="display: none;">
                    <span class="restohub-location-icon">✓</span>
                    <span id="restohub-selected-address" class="restohub-selected-address"></span>
                </div>

                <!-- Mensaje de zona de cobertura -->
                <div id="restohub-coverage-status" class="restohub-coverage-status"></div>
            </div>

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
            'class'    => array( 'restohub-coordinate-field' ),
            'required' => false,
            'default'  => '',
        );

        // Campo de longitud (oculto)
        $fields['billing']['billing_longitude'] = array(
            'type'     => 'hidden',
            'class'    => array( 'restohub-coordinate-field' ),
            'required' => false,
            'default'  => '',
        );

        // También para shipping si es diferente
        $fields['shipping']['shipping_latitude'] = array(
            'type'     => 'hidden',
            'class'    => array( 'restohub-coordinate-field' ),
            'required' => false,
            'default'  => '',
        );

        $fields['shipping']['shipping_longitude'] = array(
            'type'     => 'hidden',
            'class'    => array( 'restohub-coordinate-field' ),
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
                    array( 'source' => 'restohub' )
                );
            }
        }

        // Guardar datos de scheduling
        $this->save_schedule_to_order( $order_id );
    }

    /**
     * Valida coordenadas y cobertura al procesar el checkout
     *
     * Si el cliente eligió Uber Direct como método de envío pero está
     * fuera de zona de cobertura, bloquea el checkout.
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

        // Si no es Uber Direct (ej: retiro en tienda), no validar
        if ( ! $is_uber_shipping ) {
            return;
        }

        // Obtener coordenadas desde POST o sesión
        $lat = $this->get_coordinate_value( 'latitude' );
        $lng = $this->get_coordinate_value( 'longitude' );

        // Validar que existan coordenadas
        if ( empty( $lat ) || empty( $lng ) ) {
            wc_add_notice(
                __( 'Por favor, selecciona tu ubicación en el mapa para continuar con el delivery.', 'restohub' ),
                'error'
            );
            return;
        }

        // Verificar cobertura usando el validador de polígonos
        $validator = restohub_polygon_validator();
        $point     = array(
            'lat' => (float) $lat,
            'lng' => (float) $lng,
        );

        $store = $validator->find_store_for_point( $point );

        // Si no hay tienda que cubra el punto, bloquear checkout
        if ( ! $store ) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: link to change location */
                    __( 'Lo sentimos, tu ubicación está fuera de nuestra zona de delivery. Por favor, elige "Retiro en Tienda" o %scambia tu ubicación%s.', 'restohub' ),
                    '<a href="#restohub-map-section" class="restohub-scroll-to-map">',
                    '</a>'
                ),
                'error'
            );

            // Log para debugging
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->warning(
                    sprintf(
                        'Checkout bloqueado: Cliente fuera de zona. Lat: %s, Lng: %s',
                        $lat,
                        $lng
                    ),
                    array( 'source' => 'restohub' )
                );
            }
        }
    }

    /**
     * Obtiene el valor de una coordenada desde POST o sesión
     *
     * @param string $type 'latitude' o 'longitude'.
     * @return string
     */
    private function get_coordinate_value( string $type ): string {
        $value = '';

        // Primero intentar desde POST (billing)
        if ( ! empty( $_POST[ 'billing_' . $type ] ) ) {
            $value = sanitize_text_field( $_POST[ 'billing_' . $type ] );
        }
        // Luego desde POST (shipping)
        elseif ( ! empty( $_POST[ 'shipping_' . $type ] ) ) {
            $value = sanitize_text_field( $_POST[ 'shipping_' . $type ] );
        }
        // Finalmente desde sesión
        elseif ( WC()->session ) {
            $value = WC()->session->get( 'shipping_' . $type, '' );
        }

        return $value;
    }

    /**
     * AJAX: Guarda las coordenadas en la sesión
     */
    public function ajax_save_coordinates(): void {
        check_ajax_referer( 'restohub_checkout_nonce', 'nonce' );

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
     * AJAX: Sincroniza ubicación completa desde localStorage del cliente
     *
     * Este método es CRÍTICO para que el cálculo de shipping funcione
     * cuando el usuario ya seleccionó ubicación en el modal de delivery.
     */
    public function ajax_sync_location(): void {
        check_ajax_referer( 'restohub_checkout_nonce', 'nonce' );

        $lat           = isset( $_POST['latitude'] ) ? floatval( $_POST['latitude'] ) : 0;
        $lng           = isset( $_POST['longitude'] ) ? floatval( $_POST['longitude'] ) : 0;
        $address       = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
        $full_address  = isset( $_POST['full_address'] ) ? sanitize_text_field( wp_unslash( $_POST['full_address'] ) ) : '';
        $street        = isset( $_POST['street'] ) ? sanitize_text_field( wp_unslash( $_POST['street'] ) ) : '';
        $city          = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
        $state         = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
        $postcode      = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '';
        $delivery_type = isset( $_POST['delivery_type'] ) ? sanitize_key( $_POST['delivery_type'] ) : 'delivery';
        $store_id      = isset( $_POST['store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['store_id'] ) ) : '';

        if ( ! $lat || ! $lng ) {
            wp_send_json_error( array( 'message' => 'Coordenadas inválidas' ) );
        }

        // Log para debugging
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->info(
                sprintf( 'Sincronizando ubicación: Lat=%s, Lng=%s, Tipo=%s, Dirección=%s', $lat, $lng, $delivery_type, $address ),
                array( 'source' => 'restohub' )
            );
        }

        // Guardar en sesión de WooCommerce
        if ( WC()->session ) {
            // Coordenadas para el cálculo de shipping
            WC()->session->set( 'shipping_latitude', $lat );
            WC()->session->set( 'shipping_longitude', $lng );

            // Datos completos de ubicación
            $location_data = array(
                'lat'           => $lat,
                'lng'           => $lng,
                'address'       => $address,
                'full_address'  => $full_address,
                'street'        => $street,
                'city'          => $city,
                'state'         => $state,
                'postcode'      => $postcode,
                'delivery_type' => $delivery_type,
                'store_id'      => $store_id,
                'synced_at'     => time(),
            );
            WC()->session->set( 'restohub_location', $location_data );

            // Forzar que WooCommerce recalcule el shipping
            WC()->session->set( 'shipping_for_package_0', null );
            WC()->shipping()->reset_shipping();
        }

        // Verificar cobertura
        $coverage_info = $this->check_coverage( $lat, $lng );

        wp_send_json_success( array(
            'message'       => 'Ubicación sincronizada correctamente',
            'coverage'      => $coverage_info,
            'location_data' => array(
                'lat'           => $lat,
                'lng'           => $lng,
                'delivery_type' => $delivery_type,
            ),
        ) );
    }

    /**
     * Verifica la cobertura para unas coordenadas
     *
     * @param float $lat Latitud.
     * @param float $lng Longitud.
     * @return array Información de cobertura.
     */
    /**
     * AJAX: Cambia el modo de entrega (Delivery/Retiro) desde el checkout
     */
    public function ajax_switch_delivery_mode(): void {
        check_ajax_referer( 'restohub_checkout_nonce', 'nonce' );

        $delivery_type = isset( $_POST['delivery_type'] ) ? sanitize_key( $_POST['delivery_type'] ) : 'delivery';
        $store_id      = isset( $_POST['store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['store_id'] ) ) : '';

        if ( WC()->session ) {
            // Obtener datos existentes y actualizar el tipo
            $location_data = WC()->session->get( 'restohub_location', array() );
            $location_data['delivery_type'] = $delivery_type;

            if ( $delivery_type === 'pickup' && $store_id ) {
                $location_data['store_id'] = $store_id;

                // Buscar nombre de la tienda
                $stores = function_exists( 'restohub_store_repository' )
                    ? restohub_store_repository()->get_active()
                    : get_option( 'restohub_stores', array() );

                foreach ( $stores as $store ) {
                    if ( ( $store['id'] ?? '' ) === $store_id ) {
                        $location_data['store_name'] = $store['name'] ?? '';
                        break;
                    }
                }
            }

            WC()->session->set( 'restohub_location', $location_data );
            WC()->session->set( 'restohub_delivery_type', $delivery_type );

            // Forzar recálculo de shipping
            WC()->session->set( 'shipping_for_package_0', null );
            WC()->shipping()->reset_shipping();
        }

        wp_send_json_success( array(
            'message'       => __( 'Modo de entrega actualizado', 'restohub' ),
            'delivery_type' => $delivery_type,
        ) );
    }

    // =========================================================================
    // SCHEDULING: Render, AJAX, Validation, Order Meta, Admin, Emails
    // =========================================================================

    /**
     * Renderiza el selector de horarios en el checkout
     *
     * @param WC_Checkout $checkout Objeto del checkout.
     */
    public function render_schedule_selector( $checkout ): void {
        if ( ! function_exists( 'restohub_scheduling' ) || ! restohub_scheduling()->is_enabled() ) {
            return;
        }

        $mode        = restohub_scheduling()->get_scheduling_mode();
        $is_open     = restohub_scheduling()->is_store_currently_open();
        $saved       = WC()->session ? WC()->session->get( 'restohub_schedule', array() ) : array();
        $schedule_type = $saved['type'] ?? ( $is_open ? 'asap' : 'scheduled' );
        ?>
        <div id="restohub-checkout-schedule" class="restohub-checkout-schedule" data-mode="<?php echo esc_attr( $mode ); ?>">

            <?php if ( ! $is_open ) : ?>
                <div id="restohub-store-closed-banner" class="restohub-schedule-closed-banner">
                    <span class="restohub-schedule-closed-icon">&#128337;</span>
                    <span class="restohub-schedule-closed-text">
                        <?php esc_html_e( 'La tienda está cerrada en este momento. Puedes programar tu pedido para más tarde.', 'restohub' ); ?>
                    </span>
                </div>
            <?php endif; ?>

            <!-- Toggle ASAP / Programar -->
            <div class="restohub-schedule-toggle">
                <button type="button"
                        class="restohub-schedule-toggle-btn <?php echo $schedule_type === 'asap' ? 'active' : ''; ?>"
                        data-schedule="asap"
                        <?php echo ! $is_open ? 'disabled' : ''; ?>>
                    <span class="restohub-schedule-toggle-icon">&#9889;</span>
                    <span class="restohub-schedule-toggle-label"><?php esc_html_e( 'Lo antes posible', 'restohub' ); ?></span>
                </button>
                <button type="button"
                        class="restohub-schedule-toggle-btn <?php echo $schedule_type === 'scheduled' ? 'active' : ''; ?>"
                        data-schedule="scheduled">
                    <span class="restohub-schedule-toggle-icon">&#128197;</span>
                    <span class="restohub-schedule-toggle-label"><?php esc_html_e( 'Programar', 'restohub' ); ?></span>
                </button>
            </div>

            <!-- Info ASAP -->
            <div id="restohub-schedule-asap-info" class="restohub-schedule-asap-info" <?php echo $schedule_type !== 'asap' ? 'style="display:none;"' : ''; ?>>
                <span class="restohub-schedule-asap-icon">&#128666;</span>
                <span class="restohub-schedule-asap-text"><?php esc_html_e( 'Entrega estimada: 30-45 min', 'restohub' ); ?></span>
            </div>

            <!-- Picker de fecha/hora -->
            <div id="restohub-schedule-picker" class="restohub-schedule-picker" <?php echo $schedule_type !== 'scheduled' ? 'style="display:none;"' : ''; ?>>
                <div class="restohub-schedule-date-tabs" id="restohub-schedule-date-tabs">
                    <!-- Tabs de fecha renderizados por JS -->
                </div>
                <div class="restohub-schedule-slots" id="restohub-schedule-slots">
                    <!-- Slots renderizados por JS -->
                </div>
            </div>

            <!-- Hidden inputs -->
            <input type="hidden" id="restohub_schedule_type" name="restohub_schedule_type" value="<?php echo esc_attr( $schedule_type ); ?>">
            <input type="hidden" id="restohub_schedule_date" name="restohub_schedule_date" value="<?php echo esc_attr( $saved['date'] ?? '' ); ?>">
            <input type="hidden" id="restohub_schedule_time" name="restohub_schedule_time" value="<?php echo esc_attr( $saved['time'] ?? '' ); ?>">
        </div>
        <?php
    }

    /**
     * AJAX: Obtiene los slots disponibles
     */
    public function ajax_get_schedule_slots(): void {
        check_ajax_referer( 'restohub_checkout_nonce', 'nonce' );

        if ( ! function_exists( 'restohub_scheduling' ) ) {
            wp_send_json_error( array( 'message' => 'Scheduling no disponible' ) );
        }

        wp_send_json_success( array(
            'mode'        => restohub_scheduling()->get_scheduling_mode(),
            'isStoreOpen' => restohub_scheduling()->is_store_currently_open(),
            'dates'       => restohub_scheduling()->get_schedulable_dates(),
        ) );
    }

    /**
     * AJAX: Guarda la selección de schedule en sesión
     */
    public function ajax_save_schedule(): void {
        check_ajax_referer( 'restohub_checkout_nonce', 'nonce' );

        $type = sanitize_key( $_POST['schedule_type'] ?? 'asap' );
        $date = sanitize_text_field( $_POST['schedule_date'] ?? '' );
        $time = sanitize_text_field( $_POST['schedule_time'] ?? '' );

        $schedule_data = array(
            'type' => $type,
            'date' => $date,
            'time' => $time,
        );

        // Validar si es programado
        if ( $type === 'scheduled' && $date && $time && function_exists( 'restohub_scheduling' ) ) {
            $valid = restohub_scheduling()->validate_scheduled_time( $date, $time );
            if ( is_wp_error( $valid ) ) {
                wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
            }
        }

        if ( WC()->session ) {
            WC()->session->set( 'restohub_schedule', $schedule_data );
        }

        wp_send_json_success( array(
            'message'  => __( 'Horario guardado', 'restohub' ),
            'schedule' => $schedule_data,
        ) );
    }

    /**
     * Valida el schedule al procesar el checkout
     */
    public function validate_schedule(): void {
        if ( ! function_exists( 'restohub_scheduling' ) || ! restohub_scheduling()->is_enabled() ) {
            return;
        }

        $schedule_type = sanitize_key( $_POST['restohub_schedule_type'] ?? '' );

        // Si la tienda está cerrada y no hay schedule seleccionado
        if ( ! restohub_scheduling()->is_store_currently_open() ) {
            if ( $schedule_type !== 'scheduled' ) {
                wc_add_notice(
                    __( 'La tienda está cerrada. Por favor, programa tu pedido para un horario disponible.', 'restohub' ),
                    'error'
                );
                return;
            }
        }

        // Si es programado, validar fecha y hora
        if ( $schedule_type === 'scheduled' ) {
            $date = sanitize_text_field( $_POST['restohub_schedule_date'] ?? '' );
            $time = sanitize_text_field( $_POST['restohub_schedule_time'] ?? '' );

            if ( empty( $date ) || empty( $time ) ) {
                wc_add_notice(
                    __( 'Por favor, selecciona una fecha y horario para tu pedido programado.', 'restohub' ),
                    'error'
                );
                return;
            }

            $valid = restohub_scheduling()->validate_scheduled_time( $date, $time );
            if ( is_wp_error( $valid ) ) {
                wc_add_notice( $valid->get_error_message(), 'error' );
            }
        }
    }

    /**
     * Guarda datos de scheduling en order meta
     *
     * Se llama desde save_coordinates_to_order (extendido)
     *
     * @param int $order_id ID del pedido.
     */
    private function save_schedule_to_order( int $order_id ): void {
        if ( ! function_exists( 'restohub_scheduling' ) || ! restohub_scheduling()->is_enabled() ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $schedule_type = sanitize_key( $_POST['restohub_schedule_type'] ?? '' );

        if ( empty( $schedule_type ) && WC()->session ) {
            $session_schedule = WC()->session->get( 'restohub_schedule', array() );
            $schedule_type = $session_schedule['type'] ?? 'asap';
        }

        $order->update_meta_data( '_restohub_schedule_type', $schedule_type ?: 'asap' );

        if ( $schedule_type === 'scheduled' ) {
            $date = sanitize_text_field( $_POST['restohub_schedule_date'] ?? '' );
            $time = sanitize_text_field( $_POST['restohub_schedule_time'] ?? '' );

            if ( empty( $date ) && WC()->session ) {
                $session_schedule = WC()->session->get( 'restohub_schedule', array() );
                $date = $session_schedule['date'] ?? '';
                $time = $session_schedule['time'] ?? '';
            }

            $order->update_meta_data( '_restohub_scheduled_date', $date );
            $order->update_meta_data( '_restohub_scheduled_time', $time );
            $order->update_meta_data( '_restohub_scheduled_label', $date . ' a las ' . $time );

            $order->add_order_note(
                sprintf(
                    /* translators: 1: date, 2: time */
                    __( 'Pedido programado para %1$s a las %2$s.', 'restohub' ),
                    $date,
                    $time
                )
            );
        } else {
            $order->add_order_note(
                __( 'Pedido solicitado lo antes posible (ASAP).', 'restohub' )
            );
        }

        $order->save();

        // Limpiar sesión
        if ( WC()->session ) {
            WC()->session->set( 'restohub_schedule', null );
        }
    }

    /**
     * Muestra información del schedule en el detalle de la orden (admin)
     *
     * @param WC_Order $order Pedido.
     */
    public function display_schedule_in_admin( $order ): void {
        $schedule_type = $order->get_meta( '_restohub_schedule_type' );

        if ( empty( $schedule_type ) ) {
            return;
        }

        $bg_color = $schedule_type === 'scheduled' ? '#e3f2fd' : '#e8f5e9';
        $border_color = $schedule_type === 'scheduled' ? '#1976d2' : '#4caf50';
        $icon = $schedule_type === 'scheduled' ? '&#128197;' : '&#9889;';

        echo '<div style="background:' . esc_attr( $bg_color ) . ';border-left:4px solid ' . esc_attr( $border_color ) . ';padding:12px 16px;margin-top:12px;border-radius:4px;">';
        echo '<strong>' . esc_html( $icon ) . ' ' . esc_html__( 'Entrega', 'restohub' ) . ':</strong> ';

        if ( $schedule_type === 'scheduled' ) {
            $label = $order->get_meta( '_restohub_scheduled_label' );
            echo esc_html(
                sprintf(
                    /* translators: %s: scheduled label */
                    __( 'Programado: %s', 'restohub' ),
                    $label
                )
            );
        } else {
            echo esc_html__( 'Lo antes posible (ASAP)', 'restohub' );
        }

        echo '</div>';
    }

    /**
     * Agrega información de schedule a los emails
     *
     * @param WC_Order $order     Pedido.
     * @param bool     $sent_to_admin Si se envía al admin.
     * @param bool     $plain_text Si es texto plano.
     * @param WC_Email $email     Objeto email.
     */
    public function add_schedule_to_email( $order, $sent_to_admin, $plain_text, $email ): void {
        $schedule_type = $order->get_meta( '_restohub_schedule_type' );

        if ( empty( $schedule_type ) ) {
            return;
        }

        if ( $plain_text ) {
            if ( $schedule_type === 'scheduled' ) {
                $label = $order->get_meta( '_restohub_scheduled_label' );
                echo "\n" . sprintf( __( 'Entrega programada: %s', 'restohub' ), $label ) . "\n";
            } else {
                echo "\n" . __( 'Entrega: Lo antes posible', 'restohub' ) . "\n";
            }
            return;
        }

        echo '<div style="border:2px solid #ff9800;border-radius:8px;padding:16px;margin:16px 0;background:#fff8e1;">';
        echo '<h3 style="margin:0 0 8px;font-size:16px;color:#e65100;">&#128197; ';

        if ( $schedule_type === 'scheduled' ) {
            $date = $order->get_meta( '_restohub_scheduled_date' );
            $time = $order->get_meta( '_restohub_scheduled_time' );
            echo esc_html__( 'Entrega Programada', 'restohub' );
            echo '</h3>';
            echo '<p style="margin:0;font-size:15px;color:#333;">';
            echo esc_html( $date . ' a las ' . $time );
            echo '</p>';
        } else {
            echo esc_html__( 'Entrega', 'restohub' );
            echo '</h3>';
            echo '<p style="margin:0;font-size:15px;color:#333;">';
            echo esc_html__( 'Lo antes posible', 'restohub' );
            echo '</p>';
        }

        echo '</div>';
    }

    /**
     * Agrega columna de schedule al listado de órdenes
     *
     * @param array $columns Columnas existentes.
     * @return array
     */
    public function add_schedule_column( array $columns ): array {
        $new_columns = array();

        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            if ( $key === 'order_date' ) {
                $new_columns['restohub_schedule'] = __( 'Entrega', 'restohub' );
            }
        }

        return $new_columns;
    }

    /**
     * Renderiza la columna de schedule en el listado de órdenes
     *
     * @param string   $column_name Nombre de la columna.
     * @param WC_Order $order       Pedido (HPOS).
     */
    public function render_schedule_column( string $column_name, $order ): void {
        if ( $column_name !== 'restohub_schedule' ) {
            return;
        }

        $schedule_type = $order->get_meta( '_restohub_schedule_type' );

        if ( empty( $schedule_type ) ) {
            echo '<span style="color:#999;">—</span>';
            return;
        }

        if ( $schedule_type === 'scheduled' ) {
            $date  = $order->get_meta( '_restohub_scheduled_date' );
            $time  = $order->get_meta( '_restohub_scheduled_time' );
            $today = current_time( 'Y-m-d' );

            $label = ( $date === $today )
                ? sprintf( __( 'Hoy %s', 'restohub' ), $time )
                : $date . ' ' . $time;

            echo '<span style="color:#1976d2;" title="' . esc_attr__( 'Programado', 'restohub' ) . '">&#128197; ' . esc_html( $label ) . '</span>';
        } else {
            echo '<span style="color:#4caf50;" title="ASAP">&#9889; ASAP</span>';
        }
    }

    private function check_coverage( float $lat, float $lng ): array {
        $validator = restohub_polygon_validator();
        $point     = array( 'lat' => $lat, 'lng' => $lng );

        $store = $validator->find_store_for_point( $point );

        if ( $store ) {
            return array(
                'has_coverage' => true,
                'store_name'   => $store['name'],
                'message'      => sprintf(
                    /* translators: %s: store name */
                    __( '¡Genial! Tu ubicación está dentro de nuestra zona de reparto (%s).', 'restohub' ),
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
                    __( 'Tu ubicación está fuera de zona. La tienda más cercana es %1$s (%.1f km).', 'restohub' ),
                    $nearest['name'],
                    $distance
                ),
            );
        }

        return array(
            'has_coverage' => false,
            'message'      => __( 'Lo sentimos, no tenemos cobertura en tu zona actualmente.', 'restohub' ),
        );
    }
}
