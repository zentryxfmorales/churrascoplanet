<?php
/**
 * Modal de Ubicación al Ingresar a la Tienda
 *
 * Muestra un popup para que el cliente ingrese su ubicación
 * antes de navegar por la tienda.
 *
 * @package RestoHub
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase RestoHub_Location_Modal
 *
 * Maneja el modal de ubicación que aparece al ingresar a la tienda
 */
class RestoHub_Location_Modal {

    /**
     * Nombre de la cookie para guardar la ubicación
     */
    private const LOCATION_COOKIE = 'restohub_customer_location';

    /**
     * Duración de la cookie en días
     */
    private const COOKIE_DURATION = 7;

    /**
     * Coordenadas por defecto (Santiago, Chile - Maipú)
     */
    private const DEFAULT_LAT = -33.5117;
    private const DEFAULT_LNG = -70.7578;
    private const DEFAULT_ZOOM = 13;

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
        add_action( 'wp_ajax_restohub_save_location', array( $this, 'ajax_save_location' ) );
        add_action( 'wp_ajax_nopriv_restohub_save_location', array( $this, 'ajax_save_location' ) );
        add_action( 'wp_ajax_restohub_get_location', array( $this, 'ajax_get_location' ) );
        add_action( 'wp_ajax_nopriv_restohub_get_location', array( $this, 'ajax_get_location' ) );
        add_action( 'wp_ajax_restohub_clear_location', array( $this, 'ajax_clear_location' ) );
        add_action( 'wp_ajax_nopriv_restohub_clear_location', array( $this, 'ajax_clear_location' ) );

        // Endpoint principal para verificar zona de delivery (frontend checkout)
        add_action( 'wp_ajax_check_delivery_zone', array( $this, 'ajax_check_delivery_zone' ) );
        add_action( 'wp_ajax_nopriv_check_delivery_zone', array( $this, 'ajax_check_delivery_zone' ) );

        // Sincronizar cookie con sesión de WC
        add_action( 'woocommerce_init', array( $this, 'sync_location_to_session' ) );

        // Shortcode para búsqueda de dirección en home
        add_shortcode( 'uber_address_search', array( $this, 'render_address_search_shortcode' ) );
    }

    /**
     * Renderiza el shortcode [uber_address_search]
     *
     * @param array $atts Atributos del shortcode.
     * @return string HTML del shortcode.
     */
    public function render_address_search_shortcode( $atts = array() ): string {
        $atts = shortcode_atts( array(
            'placeholder'   => __( 'Ingresa tu dirección, calle y número', 'restohub' ),
            'button_text'   => __( 'Buscar', 'restohub' ),
            'redirect'      => wc_get_page_permalink( 'shop' ),
            'show_map'      => 'false',
            'class'         => '',
        ), $atts, 'uber_address_search' );

        $unique_id = 'restohub-search-' . wp_rand( 1000, 9999 );

        ob_start();
        ?>
        <div class="restohub-address-search-widget <?php echo esc_attr( $atts['class'] ); ?>" id="<?php echo esc_attr( $unique_id ); ?>">
            <div class="restohub-search-form">
                <div class="restohub-search-input-wrapper">
                    <span class="restohub-search-icon">📍</span>
                    <input type="text"
                           class="restohub-search-input"
                           placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
                           autocomplete="off"
                           data-redirect="<?php echo esc_url( $atts['redirect'] ); ?>">
                    <div class="restohub-search-results-dropdown"></div>
                </div>
                <button type="button" class="restohub-search-submit-btn">
                    <?php echo esc_html( $atts['button_text'] ); ?>
                </button>
            </div>

            <?php if ( $atts['show_map'] === 'true' ) : ?>
            <div class="restohub-search-map-container" style="display: none;">
                <div class="restohub-search-map" style="height: 250px;"></div>
            </div>
            <?php endif; ?>
        </div>

        <style>
        .restohub-address-search-widget {
            max-width: 600px;
            margin: 0 auto;
        }
        .restohub-search-form {
            display: flex;
            gap: 12px;
            background: #fff;
            padding: 8px;
            border-radius: 50px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .restohub-search-input-wrapper {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
        }
        .restohub-search-icon {
            padding: 0 12px;
            font-size: 20px;
        }
        .restohub-search-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 16px;
            padding: 12px 0;
            background: transparent;
        }
        .restohub-search-input::placeholder {
            color: #999;
        }
        .restohub-search-submit-btn {
            background: #000;
            color: #fff;
            border: none;
            padding: 12px 28px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .restohub-search-submit-btn:hover {
            background: #333;
        }
        .restohub-search-results-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            z-index: 1000;
            margin-top: 8px;
            display: none;
            max-height: 300px;
            overflow-y: auto;
        }
        .restohub-search-results-dropdown.show {
            display: block;
        }
        .restohub-search-result-item {
            padding: 14px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .restohub-search-result-item:last-child {
            border-bottom: none;
        }
        .restohub-search-result-item:hover {
            background: #f8f8f8;
        }
        .restohub-search-result-icon {
            color: #666;
            flex-shrink: 0;
        }
        .restohub-search-result-content {
            flex: 1;
        }
        .restohub-search-result-main {
            font-weight: 500;
            color: #333;
            display: block;
        }
        .restohub-search-result-secondary {
            font-size: 13px;
            color: #888;
            display: block;
            margin-top: 2px;
        }
        .restohub-search-loading {
            padding: 20px;
            text-align: center;
            color: #888;
        }
        @media (max-width: 600px) {
            .restohub-search-form {
                flex-direction: column;
                border-radius: 16px;
                padding: 12px;
            }
            .restohub-search-submit-btn {
                width: 100%;
            }
        }
        </style>

        <script>
        (function($) {
            var $widget = $('#<?php echo esc_js( $unique_id ); ?>');
            var $input = $widget.find('.restohub-search-input');
            var $results = $widget.find('.restohub-search-results-dropdown');
            var $submitBtn = $widget.find('.restohub-search-submit-btn');
            var searchTimeout = null;
            var selectedData = null;

            // Búsqueda con debounce 500ms
            $input.on('input', function() {
                var query = $(this).val().trim();
                clearTimeout(searchTimeout);

                if (query.length < 3) {
                    $results.removeClass('show').empty();
                    return;
                }

                searchTimeout = setTimeout(function() {
                    searchAddress(query);
                }, 500);
            });

            // Buscar dirección
            function searchAddress(query) {
                $results.html('<div class="restohub-search-loading">Buscando...</div>').addClass('show');

                var url = 'https://nominatim.openstreetmap.org/search?format=json&q=' +
                          encodeURIComponent(query) + '&limit=5&addressdetails=1&countrycodes=<?php echo esc_js( $this->get_country_code() ); ?>';

                $.ajax({
                    url: url,
                    type: 'GET',
                    dataType: 'json',
                    headers: { 'Accept-Language': 'es' },
                    success: function(data) {
                        if (!data || data.length === 0) {
                            $results.html('<div class="restohub-search-loading">No se encontraron resultados</div>');
                            return;
                        }

                        var html = '';
                        data.forEach(function(item) {
                            var parts = item.display_name.split(',');
                            var main = parts.slice(0, 2).join(',').trim();
                            var secondary = parts.slice(2, 4).join(',').trim();
                            var city = item.address ? (item.address.city || item.address.town || item.address.village || '') : '';
                            var state = item.address ? (item.address.state || item.address.region || '') : '';

                            html += '<div class="restohub-search-result-item" ' +
                                    'data-lat="' + item.lat + '" ' +
                                    'data-lng="' + item.lon + '" ' +
                                    'data-address="' + escapeHtml(main) + '" ' +
                                    'data-full-address="' + escapeHtml(item.display_name) + '" ' +
                                    'data-city="' + escapeHtml(city) + '" ' +
                                    'data-state="' + escapeHtml(state) + '">' +
                                    '<span class="restohub-search-result-icon">📍</span>' +
                                    '<div class="restohub-search-result-content">' +
                                    '<span class="restohub-search-result-main">' + escapeHtml(main) + '</span>' +
                                    '<span class="restohub-search-result-secondary">' + escapeHtml(secondary) + '</span>' +
                                    '</div></div>';
                        });

                        $results.html(html);
                    },
                    error: function() {
                        $results.html('<div class="restohub-search-loading">Error en la búsqueda</div>');
                    }
                });
            }

            // Seleccionar resultado
            $results.on('click', '.restohub-search-result-item', function() {
                selectedData = {
                    lat: $(this).data('lat'),
                    lng: $(this).data('lng'),
                    address: $(this).data('address'),
                    full_address: $(this).data('full-address'),
                    city: $(this).data('city'),
                    state: $(this).data('state'),
                    delivery_type: 'delivery',
                    timestamp: Date.now()
                };

                $input.val(selectedData.address);
                $results.removeClass('show');

                // Guardar en localStorage
                saveAndRedirect();
            });

            // Click en botón buscar
            $submitBtn.on('click', function() {
                if (selectedData) {
                    saveAndRedirect();
                } else {
                    // Si no hay selección, abrir el modal
                    $('#restohub-open-location-modal').trigger('click');
                }
            });

            // Guardar en localStorage y redirigir
            function saveAndRedirect() {
                if (!selectedData) return;

                try {
                    localStorage.setItem('restohub_customer_location', JSON.stringify(selectedData));
                } catch(e) {
                    console.warn('Error guardando en localStorage', e);
                }

                // Redirigir
                var redirectUrl = $input.data('redirect') || '<?php echo esc_js( wc_get_page_permalink( 'shop' ) ); ?>';
                window.location.href = redirectUrl;
            }

            // Click fuera cierra resultados
            $(document).on('click', function(e) {
                if (!$(e.target).closest($widget).length) {
                    $results.removeClass('show');
                }
            });

            // Escape HTML
            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Obtiene el código de país configurado
     *
     * @return string
     */
    private function get_country_code(): string {
        // TODO: Hacer configurable desde admin
        return 'cl'; // Chile por defecto
    }

    /**
     * Encola los scripts y estilos
     */
    public function enqueue_assets(): void {
        // No cargar en admin ni en checkout (checkout tiene su propio mapa)
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
            'restohub-location-modal',
            RESTOHUB_PLUGIN_URL . 'assets/css/location-modal.css',
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

        // JS del modal
        wp_enqueue_script(
            'restohub-location-modal',
            RESTOHUB_PLUGIN_URL . 'assets/js/location-modal.js',
            array( 'jquery', 'leaflet' ),
            RESTOHUB_VERSION,
            true
        );

        // Obtener ubicación guardada
        $saved_location = $this->get_saved_location();
        $has_location   = ! empty( $saved_location['lat'] ) && ! empty( $saved_location['lng'] );

        // Obtener tiendas activas para mostrar en el mapa
        $stores = $this->get_stores_for_map();

        // Pasar configuración al JS
        wp_localize_script( 'restohub-location-modal', 'restoHubLocationModal', array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'restohub_location_nonce' ),
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
                'countryCode' => 'cl', // Chile
            ),
            'strings'      => array(
                'modalTitle'        => __( '¿Dónde te entregamos?', 'restohub' ),
                'modalSubtitle'     => __( 'Ingresa tu dirección para ver si llegamos a tu zona', 'restohub' ),
                'searchPlaceholder' => __( 'Buscar tu dirección...', 'restohub' ),
                'useMyLocation'     => __( 'Usar mi ubicación', 'restohub' ),
                'confirmLocation'   => __( 'Confirmar ubicación', 'restohub' ),
                'changeLocation'    => __( 'Cambiar', 'restohub' ),
                'deliveryTo'        => __( 'Entrega en:', 'restohub' ),
                'pickupAt'          => __( 'Retiro en:', 'restohub' ),
                'locating'          => __( 'Localizando...', 'restohub' ),
                'locationError'     => __( 'No pudimos obtener tu ubicación', 'restohub' ),
                'searchError'       => __( 'No encontramos resultados', 'restohub' ),
                'coverageOk'        => __( '¡Genial! Llegamos a tu zona', 'restohub' ),
                'noCoverage'        => __( 'No llegamos a tu zona, pero puedes retirar en tienda', 'restohub' ),
                'selectStore'       => __( 'Selecciona una tienda para retiro:', 'restohub' ),
                'deliveryOption'    => __( 'Delivery a domicilio', 'restohub' ),
                'pickupOption'      => __( 'Retiro en tienda', 'restohub' ),
                'estimatedTime'     => __( 'Tiempo estimado:', 'restohub' ),
                'minutes'           => __( 'min', 'restohub' ),
                'free'              => __( 'Gratis', 'restohub' ),
            ),
        ) );
    }

    /**
     * Renderiza el header bar de ubicación (estilo Rappi)
     */
    public function render_location_button(): void {
        if ( is_admin() || is_checkout() ) {
            return;
        }

        $location = $this->get_saved_location();
        $has_location = ! empty( $location['lat'] ) && ! empty( $location['lng'] );
        $delivery_type = $location['delivery_type'] ?? 'delivery';
        $display_text = $has_location ? $this->get_short_address( $location ) : __( 'Ingresa tu ubicación', 'restohub' );
        $estimated_time = $this->get_estimated_delivery_time();

        ?>
        <div id="restohub-header-bar" class="restohub-header-bar <?php echo $has_location ? 'has-location' : 'no-location'; ?>">
            <!-- Fila superior: Modo + Horario -->
            <div class="restohub-header-top">
                <button type="button" class="restohub-header-mode" id="restohub-header-mode-btn">
                    <?php if ( $delivery_type === 'pickup' ) : ?>
                        <span class="restohub-header-mode-icon">🏪</span>
                        <span class="restohub-header-mode-text"><?php esc_html_e( 'Retiro', 'restohub' ); ?></span>
                    <?php else : ?>
                        <span class="restohub-header-mode-icon">🛵</span>
                        <span class="restohub-header-mode-text"><?php esc_html_e( 'Delivery', 'restohub' ); ?></span>
                    <?php endif; ?>
                </button>
                <span class="restohub-header-separator"></span>
                <button type="button" class="restohub-header-schedule" id="restohub-header-schedule-btn">
                    <span class="restohub-header-schedule-icon">📅</span>
                    <span class="restohub-header-schedule-text" id="restohub-header-time"><?php echo esc_html( $estimated_time ); ?></span>
                    <span class="restohub-header-arrow">▼</span>
                </button>
            </div>
            <!-- Fila inferior: Dirección -->
            <button type="button" class="restohub-header-location restohub-open-modal-btn">
                <span class="restohub-header-location-icon">📍</span>
                <span class="restohub-header-location-text" id="restohub-location-display"><?php echo esc_html( $display_text ); ?></span>
                <span class="restohub-header-arrow">▼</span>
            </button>
        </div>
        <?php
    }

    /**
     * Calcula el tiempo estimado de entrega
     *
     * @return string
     */
    private function get_estimated_delivery_time(): string {
        // Obtener configuración de tiempos
        $settings = get_option( 'restohub_settings', array() );
        $prep_time = isset( $settings['preparation_time'] ) ? (int) $settings['preparation_time'] : 20;
        $delivery_time = isset( $settings['delivery_time'] ) ? (int) $settings['delivery_time'] : 25;

        // Hora actual + tiempos
        $total_minutes = $prep_time + $delivery_time;
        $estimated = strtotime( "+{$total_minutes} minutes" );

        // Verificar si la tienda está abierta
        $is_open = $this->is_store_open();

        if ( ! $is_open ) {
            // Tienda cerrada - mostrar próxima apertura
            $next_open = $this->get_next_opening_time();
            if ( $next_open ) {
                return $next_open;
            }
            return __( 'Cerrado', 'restohub' );
        }

        // Formatear hora estimada
        return sprintf(
            /* translators: %s: estimated time */
            __( 'Hoy a las %s', 'restohub' ),
            date_i18n( 'H:i', $estimated )
        );
    }

    /**
     * Verifica si la tienda está abierta
     *
     * @return bool
     */
    private function is_store_open(): bool {
        $settings = get_option( 'restohub_settings', array() );
        $hours = $settings['store_hours'] ?? array();

        if ( empty( $hours ) ) {
            return true; // Sin horario configurado = siempre abierto
        }

        $day_map = array(
            1 => 'lunes',
            2 => 'martes',
            3 => 'miercoles',
            4 => 'jueves',
            5 => 'viernes',
            6 => 'sabado',
            0 => 'domingo',
        );

        $current_day = $day_map[ (int) date( 'w' ) ];
        $current_time = date( 'H:i' );

        if ( ! isset( $hours[ $current_day ] ) ) {
            return true;
        }

        $day_hours = $hours[ $current_day ];

        if ( ! empty( $day_hours['closed'] ) ) {
            return false;
        }

        $open = $day_hours['open'] ?? '00:00';
        $close = $day_hours['close'] ?? '23:59';

        return ( $current_time >= $open && $current_time <= $close );
    }

    /**
     * Obtiene la próxima hora de apertura
     *
     * @return string|null
     */
    private function get_next_opening_time(): ?string {
        $settings = get_option( 'restohub_settings', array() );
        $hours = $settings['store_hours'] ?? array();

        if ( empty( $hours ) ) {
            return null;
        }

        $day_names = array(
            'lunes'     => __( 'Lunes', 'restohub' ),
            'martes'    => __( 'Martes', 'restohub' ),
            'miercoles' => __( 'Miércoles', 'restohub' ),
            'jueves'    => __( 'Jueves', 'restohub' ),
            'viernes'   => __( 'Viernes', 'restohub' ),
            'sabado'    => __( 'Sábado', 'restohub' ),
            'domingo'   => __( 'Domingo', 'restohub' ),
        );

        $day_order = array( 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo' );
        $day_map = array( 1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 0 => 6 );

        $current_day_index = $day_map[ (int) date( 'w' ) ];
        $current_time = date( 'H:i' );

        // Buscar en los próximos 7 días
        for ( $i = 0; $i <= 7; $i++ ) {
            $check_index = ( $current_day_index + $i ) % 7;
            $check_day = $day_order[ $check_index ];
            $day_hours = $hours[ $check_day ] ?? array();

            if ( ! empty( $day_hours['closed'] ) ) {
                continue;
            }

            $open_time = $day_hours['open'] ?? '09:00';

            // Si es hoy y la hora de apertura es posterior
            if ( $i === 0 && $open_time > $current_time ) {
                return sprintf( __( 'Hoy a las %s', 'restohub' ), $open_time );
            }

            // Si es mañana o después
            if ( $i > 0 ) {
                if ( $i === 1 ) {
                    return sprintf( __( 'Mañana a las %s', 'restohub' ), $open_time );
                }
                return sprintf( '%s a las %s', $day_names[ $check_day ], $open_time );
            }
        }

        return null;
    }

    /**
     * Renderiza el modal de ubicación (estilo Rappi)
     */
    public function render_modal(): void {
        if ( is_admin() || is_checkout() ) {
            return;
        }

        $location = $this->get_saved_location();
        $has_location = ! empty( $location['lat'] ) && ! empty( $location['lng'] );
        $stores = $this->get_active_stores();
        $delivery_type = $location['delivery_type'] ?? 'delivery';
        $address_type = $location['address_type'] ?? '';
        ?>
        <div id="restohub-location-modal" class="restohub-modal-overlay" style="display:none;" data-state="initial">
            <div class="restohub-modal-container">
                <!-- Header del Modal -->
                <div class="restohub-modal-header">
                    <h2 class="restohub-modal-title" id="restohub-modal-title"><?php esc_html_e( 'Hola! ¿Cómo quieres tu pedido?', 'restohub' ); ?></h2>
                    <button type="button" class="restohub-modal-close" id="restohub-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'restohub' ); ?>">&times;</button>
                </div>

                <div class="restohub-modal-body">
                    <!-- Toggle Delivery / Retiro -->
                    <div class="restohub-mode-toggle" id="restohub-mode-toggle">
                        <button type="button"
                                class="restohub-mode-toggle-btn <?php echo $delivery_type === 'delivery' ? 'active' : ''; ?>"
                                data-mode="delivery">
                            <span class="restohub-mode-icon">🛵</span>
                            <span class="restohub-mode-label"><?php esc_html_e( 'Delivery', 'restohub' ); ?></span>
                        </button>
                        <button type="button"
                                class="restohub-mode-toggle-btn <?php echo $delivery_type === 'pickup' ? 'active' : ''; ?>"
                                data-mode="pickup">
                            <span class="restohub-mode-icon">🏪</span>
                            <span class="restohub-mode-label"><?php esc_html_e( 'Retiro', 'restohub' ); ?></span>
                        </button>
                    </div>

                    <!-- ============================================ -->
                    <!-- VISTA DELIVERY -->
                    <!-- ============================================ -->
                    <div id="restohub-delivery-view" class="restohub-view <?php echo $delivery_type === 'delivery' ? 'active' : ''; ?>">

                        <!-- Estado 1: Búsqueda de dirección -->
                        <div id="restohub-delivery-search" class="restohub-delivery-state active">
                            <div class="restohub-address-input-wrapper">
                                <label class="restohub-input-label"><?php esc_html_e( 'Ingresa tu dirección', 'restohub' ); ?></label>
                                <div class="restohub-address-input-container">
                                    <span class="restohub-address-icon">📍</span>
                                    <input type="text"
                                           id="restohub-address-input"
                                           class="restohub-address-input"
                                           placeholder="<?php esc_attr_e( 'Ingresa una ubicación', 'restohub' ); ?>"
                                           autocomplete="off"
                                           value="<?php echo esc_attr( $location['address'] ?? '' ); ?>">
                                    <button type="button" id="restohub-geolocate-btn" class="restohub-geolocate-btn" title="<?php esc_attr_e( 'Usar mi ubicación', 'restohub' ); ?>">
                                        <span class="restohub-geolocate-icon">📍</span>
                                    </button>
                                </div>
                                <div id="restohub-address-results" class="restohub-address-results"></div>
                            </div>
                        </div>

                        <!-- Estado 2: Confirmación de dirección con mapa -->
                        <div id="restohub-delivery-confirm" class="restohub-delivery-state">
                            <div class="restohub-address-input-wrapper">
                                <label class="restohub-input-label"><?php esc_html_e( 'Ingresa tu dirección', 'restohub' ); ?></label>
                                <div class="restohub-address-input-container">
                                    <span class="restohub-address-icon">📍</span>
                                    <input type="text"
                                           id="restohub-address-display"
                                           class="restohub-address-input"
                                           placeholder="<?php esc_attr_e( 'Modifica tu dirección si es necesario', 'restohub' ); ?>"
                                           autocomplete="off">
                                    <span class="restohub-input-edit-icon">✏️</span>
                                </div>
                                <div id="restohub-address-results-confirm" class="restohub-address-results"></div>
                            </div>

                            <!-- Mapa con botón Ajústalo -->
                            <div class="restohub-map-wrapper">
                                <div id="restohub-confirm-map" class="restohub-confirm-map"></div>
                                <button type="button" id="restohub-adjust-address-btn" class="restohub-adjust-btn">
                                    <?php esc_html_e( '¿No es tu dirección? Ajústalo', 'restohub' ); ?>
                                </button>
                            </div>

                            <!-- Dirección completa -->
                            <p class="restohub-full-address" id="restohub-full-address"></p>

                            <!-- Estado de cobertura -->
                            <div id="restohub-coverage-status" class="restohub-coverage-status" style="display: none;">
                                <span class="restohub-coverage-icon"></span>
                                <span class="restohub-coverage-text"></span>
                            </div>

                            <!-- Indicaciones adicionales -->
                            <div class="restohub-address-extras">
                                <label class="restohub-extras-label"><?php esc_html_e( 'Indicaciones Adicionales:', 'restohub' ); ?></label>
                                <div class="restohub-address-tags" id="restohub-address-tags">
                                    <button type="button" class="restohub-address-tag <?php echo $address_type === 'depto' ? 'selected' : ''; ?>" data-type="depto">
                                        <?php esc_html_e( 'Depto', 'restohub' ); ?>
                                    </button>
                                    <button type="button" class="restohub-address-tag <?php echo $address_type === 'casa' ? 'selected' : ''; ?>" data-type="casa">
                                        <?php esc_html_e( 'Casa', 'restohub' ); ?>
                                    </button>
                                    <button type="button" class="restohub-address-tag <?php echo $address_type === 'oficina' ? 'selected' : ''; ?>" data-type="oficina">
                                        <?php esc_html_e( 'Oficina', 'restohub' ); ?>
                                    </button>
                                    <button type="button" class="restohub-address-tag <?php echo $address_type === 'pareja' ? 'selected' : ''; ?>" data-type="pareja">
                                        <?php esc_html_e( 'Pareja', 'restohub' ); ?>
                                    </button>
                                    <button type="button" class="restohub-address-tag <?php echo $address_type === 'papas' ? 'selected' : ''; ?>" data-type="papas">
                                        <?php esc_html_e( 'Papás', 'restohub' ); ?>
                                    </button>
                                    <button type="button" class="restohub-address-tag <?php echo $address_type === 'otro' ? 'selected' : ''; ?>" data-type="otro">
                                        <?php esc_html_e( 'Otro', 'restohub' ); ?>
                                    </button>
                                </div>

                                <input type="text"
                                       id="restohub-address-detail"
                                       class="restohub-address-detail-input"
                                       placeholder="<?php esc_attr_e( 'Número de Departamento / Torre', 'restohub' ); ?>"
                                       value="<?php echo esc_attr( $location['address_detail'] ?? '' ); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- ============================================ -->
                    <!-- VISTA RETIRO -->
                    <!-- ============================================ -->
                    <div id="restohub-pickup-view" class="restohub-view <?php echo $delivery_type === 'pickup' ? 'active' : ''; ?>">
                        <!-- Buscador de sucursales -->
                        <div class="restohub-store-search-wrapper">
                            <div class="restohub-store-search-container">
                                <span class="restohub-store-search-icon">🔍</span>
                                <input type="text"
                                       id="restohub-store-search"
                                       class="restohub-store-search-input"
                                       placeholder="<?php esc_attr_e( 'Busca tu sucursal', 'restohub' ); ?>"
                                       autocomplete="off">
                            </div>
                        </div>

                        <!-- Lista de tiendas -->
                        <div class="restohub-store-list" id="restohub-store-list">
                            <?php foreach ( $stores as $index => $store ) :
                                $is_selected = ( $location['store_id'] ?? '' ) == $store['id'];
                            ?>
                                <div class="restohub-store-card <?php echo $is_selected ? 'selected' : ''; ?>"
                                     data-store-id="<?php echo esc_attr( $store['id'] ); ?>"
                                     data-store-name="<?php echo esc_attr( $store['name'] ); ?>"
                                     data-store-address="<?php echo esc_attr( $store['address'] ); ?>"
                                     data-store-lat="<?php echo esc_attr( $store['latitude'] ); ?>"
                                     data-store-lng="<?php echo esc_attr( $store['longitude'] ); ?>">
                                    <div class="restohub-store-card-radio">
                                        <span class="restohub-radio-circle <?php echo $is_selected ? 'checked' : ''; ?>">
                                            <?php if ( $is_selected ) : ?>
                                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="restohub-store-card-info">
                                        <span class="restohub-store-card-name"><?php echo esc_html( $store['name'] ); ?></span>
                                        <span class="restohub-store-card-address"><?php echo esc_html( $store['address'] ); ?></span>
                                    </div>
                                    <div class="restohub-store-card-map">
                                        <!-- Placeholder para mini-mapa - reemplazar src con imagen real -->
                                        <img src=""
                                             alt="<?php echo esc_attr( $store['name'] ); ?>"
                                             class="restohub-store-minimap"
                                             data-store-id="<?php echo esc_attr( $store['id'] ); ?>"
                                             loading="lazy">
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if ( empty( $stores ) ) : ?>
                                <div class="restohub-no-stores">
                                    <p><?php esc_html_e( 'No hay tiendas disponibles.', 'restohub' ); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Footer del Modal -->
                <div class="restohub-modal-footer">
                    <button type="button" id="restohub-cancel-btn" class="restohub-btn-secondary">
                        <?php esc_html_e( 'Cancelar', 'restohub' ); ?>
                    </button>
                    <button type="button" id="restohub-confirm-btn" class="restohub-btn-primary" disabled>
                        <?php esc_html_e( 'Guardar Dirección', 'restohub' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Input oculto para almacenar datos -->
        <input type="hidden" id="restohub-selected-lat" value="<?php echo esc_attr( $location['lat'] ?? '' ); ?>">
        <input type="hidden" id="restohub-selected-lng" value="<?php echo esc_attr( $location['lng'] ?? '' ); ?>">
        <input type="hidden" id="restohub-selected-store-id" value="<?php echo esc_attr( $location['store_id'] ?? '' ); ?>">
        <input type="hidden" id="restohub-selected-delivery-type" value="<?php echo esc_attr( $delivery_type ); ?>">
        <input type="hidden" id="restohub-selected-address-type" value="<?php echo esc_attr( $address_type ); ?>">
        <?php
    }

    /**
     * Obtiene las tiendas activas
     *
     * @return array
     */
    private function get_active_stores(): array {
        // Usar repositorio si está disponible
        if ( function_exists( 'restohub_store_repository' ) ) {
            return restohub_store_repository()->get_active();
        }

        // Fallback a wp_options (retrocompatibilidad)
        $stores = get_option( 'restohub_stores', array() );
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
            $session_data = WC()->session->get( 'restohub_location' );
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
            return sprintf( __( 'Retiro en %s', 'restohub' ), $location['store_name'] );
        }

        return __( 'Ubicación seleccionada', 'restohub' );
    }

    /**
     * Sincroniza la ubicación de cookie a sesión de WC
     */
    public function sync_location_to_session(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        // Si ya hay datos en sesión, no hacer nada
        if ( WC()->session->get( 'restohub_location' ) ) {
            return;
        }

        // Copiar desde cookie si existe
        if ( isset( $_COOKIE[ self::LOCATION_COOKIE ] ) ) {
            $cookie_data = json_decode( stripslashes( $_COOKIE[ self::LOCATION_COOKIE ] ), true );
            if ( $cookie_data && isset( $cookie_data['lat'] ) ) {
                WC()->session->set( 'restohub_location', $cookie_data );

                // También guardar coordenadas individuales para el shipping
                WC()->session->set( 'shipping_latitude', $cookie_data['lat'] );
                WC()->session->set( 'shipping_longitude', $cookie_data['lng'] );

                if ( ! empty( $cookie_data['store_id'] ) ) {
                    WC()->session->set( 'restohub_assigned_store_id', $cookie_data['store_id'] );
                }
            }
        }
    }

    /**
     * AJAX: Guardar ubicación
     */
    public function ajax_save_location(): void {
        check_ajax_referer( 'restohub_location_nonce', 'nonce' );

        $lat            = isset( $_POST['lat'] ) ? floatval( $_POST['lat'] ) : null;
        $lng            = isset( $_POST['lng'] ) ? floatval( $_POST['lng'] ) : null;
        $address        = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
        $full_address   = isset( $_POST['full_address'] ) ? sanitize_text_field( wp_unslash( $_POST['full_address'] ) ) : '';
        $short_address  = isset( $_POST['short_address'] ) ? sanitize_text_field( wp_unslash( $_POST['short_address'] ) ) : '';
        $street         = isset( $_POST['street'] ) ? sanitize_text_field( wp_unslash( $_POST['street'] ) ) : '';
        $city           = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
        $state          = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
        $postcode       = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '';
        $address_type   = isset( $_POST['address_type'] ) ? sanitize_key( $_POST['address_type'] ) : '';
        $address_detail = isset( $_POST['address_detail'] ) ? sanitize_text_field( wp_unslash( $_POST['address_detail'] ) ) : '';
        $delivery_type  = isset( $_POST['delivery_type'] ) ? sanitize_key( $_POST['delivery_type'] ) : 'delivery';
        $store_id       = isset( $_POST['store_id'] ) ? sanitize_key( $_POST['store_id'] ) : '';
        $store_name     = isset( $_POST['store_name'] ) ? sanitize_text_field( wp_unslash( $_POST['store_name'] ) ) : '';

        // Validar que vengan coordenadas y estén en rango geográfico válido
        if ( null === $lat || null === $lng ) {
            wp_send_json_error( array( 'message' => __( 'Coordenadas inválidas', 'restohub' ) ) );
        }
        if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
            wp_send_json_error( array( 'message' => __( 'Coordenadas fuera de rango válido.', 'restohub' ) ) );
        }

        // Verificar cobertura
        $coverage = $this->check_coverage( $lat, $lng );

        // Si es delivery y no hay cobertura, devolver error (pero frontend manejará)
        if ( $delivery_type === 'delivery' && ! $coverage['has_coverage'] ) {
            // Aún así guardamos para que pueda elegir retiro
        }

        // Preparar datos
        $location_data = array(
            'lat'            => $lat,
            'lng'            => $lng,
            'address'        => $address,
            'full_address'   => $full_address ?: $address,
            'short_address'  => $short_address ?: $address,
            'street'         => $street,
            'city'           => $city,
            'state'          => $state,
            'postcode'       => $postcode,
            'address_type'   => $address_type,
            'address_detail' => $address_detail,
            'delivery_type'  => $delivery_type,
            'store_id'       => $delivery_type === 'delivery' ? ( $coverage['store_id'] ?? '' ) : $store_id,
            'store_name'     => $delivery_type === 'delivery' ? ( $coverage['store_name'] ?? '' ) : $store_name,
            'has_coverage'   => $coverage['has_coverage'],
            'timestamp'      => time(),
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
            WC()->session->set( 'restohub_location', $location_data );
            WC()->session->set( 'shipping_latitude', $lat );
            WC()->session->set( 'shipping_longitude', $lng );

            if ( ! empty( $location_data['store_id'] ) ) {
                WC()->session->set( 'restohub_assigned_store_id', $location_data['store_id'] );
            }

            // Guardar tipo de entrega
            WC()->session->set( 'restohub_delivery_type', $delivery_type );
        }

        wp_send_json_success( array(
            'message'   => __( 'Ubicación guardada', 'restohub' ),
            'location'  => $location_data,
            'coverage'  => $coverage,
        ) );
    }

    /**
     * AJAX: Obtener ubicación guardada
     */
    public function ajax_get_location(): void {
        check_ajax_referer( 'restohub_location_nonce', 'nonce' );

        $location = $this->get_saved_location();

        if ( empty( $location ) ) {
            wp_send_json_error( array( 'message' => __( 'No hay ubicación guardada', 'restohub' ) ) );
        }

        wp_send_json_success( array( 'location' => $location ) );
    }

    /**
     * AJAX: Limpiar ubicación
     */
    public function ajax_clear_location(): void {
        check_ajax_referer( 'restohub_location_nonce', 'nonce' );

        // Eliminar cookie
        setcookie( self::LOCATION_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );

        // Limpiar sesión
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'restohub_location', null );
            WC()->session->set( 'shipping_latitude', null );
            WC()->session->set( 'shipping_longitude', null );
            WC()->session->set( 'restohub_assigned_store_id', null );
            WC()->session->set( 'restohub_delivery_type', null );
        }

        wp_send_json_success( array( 'message' => __( 'Ubicación eliminada', 'restohub' ) ) );
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
        $nonce_valid = wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'restohub_location_nonce' ) ||
                       wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'restohub_checkout_nonce' );

        if ( ! $nonce_valid ) {
            wp_send_json_error( array(
                'message' => __( 'Sesión expirada. Recarga la página.', 'restohub' ),
                'code'    => 'invalid_nonce',
            ) );
        }

        // Obtener coordenadas
        $lat = isset( $_REQUEST['lat'] ) ? floatval( $_REQUEST['lat'] ) : 0;
        $lng = isset( $_REQUEST['lng'] ) ? floatval( $_REQUEST['lng'] ) : 0;

        // Validar coordenadas
        if ( $lat === 0.0 || $lng === 0.0 ) {
            wp_send_json_error( array(
                'message' => __( 'Coordenadas inválidas.', 'restohub' ),
                'code'    => 'invalid_coordinates',
            ) );
        }

        // Validar rangos
        if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
            wp_send_json_error( array(
                'message' => __( 'Coordenadas fuera de rango.', 'restohub' ),
                'code'    => 'out_of_range',
            ) );
        }

        // Usar el validador de polígonos
        $validator = restohub_polygon_validator();
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
                    __( '¡Excelente! Hacemos delivery a tu zona desde %s', 'restohub' ),
                    $store['name']
                ),
            ) );
        }

        // Cliente fuera de zona - buscar tienda más cercana como referencia
        $nearest = $validator->find_nearest_store( $point );

        $response = array(
            'in_zone'   => false,
            'message'   => __( 'Lo sentimos, no llegamos a tu zona con delivery.', 'restohub' ),
            'pickup_available' => true,
            'pickup_message'   => __( 'Puedes retirar tu pedido en cualquiera de nuestras tiendas.', 'restohub' ),
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
        $validator = restohub_polygon_validator();
        $point     = array( 'lat' => $lat, 'lng' => $lng );

        $store = $validator->find_store_for_point( $point );

        if ( $store ) {
            return array(
                'has_coverage' => true,
                'store_id'     => $store['id'],
                'store_name'   => $store['name'],
                'store_address' => $store['address'],
                'message'      => sprintf(
                    __( '¡Llegamos a tu zona! Envío desde %s', 'restohub' ),
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
            'message'        => __( 'No llegamos a tu zona, pero puedes retirar tu pedido en tienda.', 'restohub' ),
        );
    }

    /**
     * Obtiene el tipo de entrega seleccionado
     *
     * @return string 'delivery' o 'pickup'
     */
    public static function get_delivery_type(): string {
        if ( function_exists( 'WC' ) && WC()->session ) {
            return WC()->session->get( 'restohub_delivery_type', 'delivery' );
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
            $location = WC()->session->get( 'restohub_location' );
            return ! empty( $location['has_coverage'] );
        }

        return false;
    }

    /**
     * Verifica si el cliente tiene ubicación guardada
     *
     * @return bool
     */
    public static function has_location(): bool {
        // Intentar desde sesión de WC
        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_data = WC()->session->get( 'restohub_location' );
            if ( $session_data && ! empty( $session_data['lat'] ) ) {
                return true;
            }
        }

        // Intentar desde cookie
        if ( isset( $_COOKIE[ self::LOCATION_COOKIE ] ) ) {
            $cookie_data = json_decode( stripslashes( $_COOKIE[ self::LOCATION_COOKIE ] ), true );
            if ( $cookie_data && isset( $cookie_data['lat'] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtiene la ubicación guardada (versión estática)
     *
     * @return array
     */
    public static function get_location(): array {
        // Primero intentar desde sesión de WC
        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_data = WC()->session->get( 'restohub_location' );
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
}
