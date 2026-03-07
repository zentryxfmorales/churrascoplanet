<?php
/**
 * Clase para probar la API de Uber Direct
 *
 * @package RestoHub
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase RestoHub_API_Tester
 *
 * Herramienta de diagnóstico para probar la conexión con Uber Direct API
 */
class RestoHub_API_Tester {

    /**
     * Instancia singleton
     *
     * @var RestoHub_API_Tester|null
     */
    private static ?RestoHub_API_Tester $instance = null;

    /**
     * Resultados de las pruebas
     *
     * @var array
     */
    private array $results = array();

    /**
     * API de Uber
     *
     * @var RestoHub_Uber_API|null
     */
    private ?RestoHub_Uber_API $api = null;

    /**
     * Obtiene la instancia singleton
     *
     * @return RestoHub_API_Tester
     */
    public static function instance(): RestoHub_API_Tester {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->api = new RestoHub_Uber_API();
    }

    /**
     * Ejecuta todas las pruebas
     *
     * @param array $test_params Parámetros de prueba opcionales.
     * @return array Resultados de todas las pruebas.
     */
    public function run_all_tests( array $test_params = array() ): array {
        $this->results = array(
            'timestamp'   => current_time( 'mysql' ),
            'environment' => $this->get_environment_info(),
            'tests'       => array(),
        );

        // 1. Verificar credenciales
        $this->test_credentials();

        // 2. Probar autenticación OAuth
        $this->test_authentication();

        // 3. Probar cotización (Quote)
        $this->test_quote( $test_params );

        return $this->results;
    }

    /**
     * Obtiene información del entorno
     *
     * @return array
     */
    private function get_environment_info(): array {
        $settings = get_option( 'restohub_settings', array() );

        return array(
            'php_version'     => PHP_VERSION,
            'wp_version'      => get_bloginfo( 'version' ),
            'wc_version'      => defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A',
            'plugin_version'  => defined( 'RESTOHUB_VERSION' ) ? RESTOHUB_VERSION : 'N/A',
            'sandbox_mode'    => ( $settings['sandbox_mode'] ?? 'yes' ) === 'yes' ? 'Sí (Sandbox)' : 'No (Producción)',
            'api_url'         => ( $settings['sandbox_mode'] ?? 'yes' ) === 'yes'
                ? 'https://sandbox-api.uber.com/v1/customers/'
                : 'https://api.uber.com/v1/customers/',
            'has_client_id'   => ! empty( $settings['client_id'] ) ? 'Sí' : 'No',
            'has_secret'      => ! empty( $settings['client_secret'] ) ? 'Sí' : 'No',
            'has_customer_id' => ! empty( $settings['customer_id'] ) ? 'Sí' : 'No',
        );
    }

    /**
     * Prueba 1: Verificar que las credenciales estén configuradas
     */
    private function test_credentials(): void {
        $settings = get_option( 'restohub_settings', array() );

        $test_result = array(
            'name'    => 'Verificar Credenciales',
            'status'  => 'success',
            'message' => '',
            'details' => array(),
        );

        $missing = array();

        if ( empty( $settings['client_id'] ) ) {
            $missing[] = 'Client ID';
        }
        if ( empty( $settings['client_secret'] ) ) {
            $missing[] = 'Client Secret';
        }
        if ( empty( $settings['customer_id'] ) ) {
            $missing[] = 'Customer ID';
        }

        if ( ! empty( $missing ) ) {
            $test_result['status']  = 'error';
            $test_result['message'] = 'Faltan credenciales: ' . implode( ', ', $missing );
            $test_result['details'] = array(
                'missing_fields' => $missing,
                'action'         => 'Configura las credenciales en WooCommerce > Uber Direct > API',
            );
        } else {
            $test_result['message'] = 'Todas las credenciales están configuradas';
            $test_result['details'] = array(
                'client_id_preview'   => substr( $settings['client_id'], 0, 8 ) . '...',
                'customer_id_preview' => substr( $settings['customer_id'], 0, 8 ) . '...',
            );
        }

        $this->results['tests']['credentials'] = $test_result;
    }

    /**
     * Prueba 2: Probar autenticación OAuth
     */
    private function test_authentication(): void {
        $test_result = array(
            'name'    => 'Autenticación OAuth',
            'status'  => 'pending',
            'message' => '',
            'details' => array(),
        );

        // Si las credenciales fallaron, no intentar auth
        if ( $this->results['tests']['credentials']['status'] === 'error' ) {
            $test_result['status']  = 'skipped';
            $test_result['message'] = 'Omitido: primero configura las credenciales';
            $this->results['tests']['authentication'] = $test_result;
            return;
        }

        // Limpiar token cacheado para forzar nueva autenticación
        delete_transient( 'restohub_uber_access_token' );

        $settings = get_option( 'restohub_settings', array() );

        $start_time = microtime( true );

        $response = wp_remote_post(
            'https://login.uber.com/oauth/v2/token',
            array(
                'body'    => array(
                    'client_id'     => $settings['client_id'] ?? '',
                    'client_secret' => $settings['client_secret'] ?? '',
                    'grant_type'    => 'client_credentials',
                    'scope'         => 'eats.deliveries',
                ),
                'timeout' => 30,
            )
        );

        $duration = round( ( microtime( true ) - $start_time ) * 1000 );

        if ( is_wp_error( $response ) ) {
            $test_result['status']  = 'error';
            $test_result['message'] = 'Error de conexión: ' . $response->get_error_message();
            $test_result['details'] = array(
                'duration_ms' => $duration,
                'error_code'  => $response->get_error_code(),
            );
        } else {
            $http_code = wp_remote_retrieve_response_code( $response );
            $body      = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( isset( $body['access_token'] ) ) {
                $test_result['status']  = 'success';
                $test_result['message'] = 'Autenticación exitosa';
                $test_result['details'] = array(
                    'duration_ms'   => $duration,
                    'token_type'    => $body['token_type'] ?? 'Bearer',
                    'expires_in'    => $body['expires_in'] ?? 'N/A',
                    'scope'         => $body['scope'] ?? 'N/A',
                    'token_preview' => substr( $body['access_token'], 0, 20 ) . '...',
                );

                // Guardar token para la siguiente prueba
                set_transient(
                    'restohub_uber_access_token',
                    $body['access_token'],
                    ( $body['expires_in'] ?? 3600 ) - 300
                );
            } else {
                $test_result['status']  = 'error';
                $test_result['message'] = 'Autenticación fallida: ' . ( $body['error_description'] ?? $body['error'] ?? 'Error desconocido' );
                $test_result['details'] = array(
                    'duration_ms' => $duration,
                    'http_code'   => $http_code,
                    'error'       => $body['error'] ?? 'N/A',
                    'error_desc'  => $body['error_description'] ?? 'N/A',
                );
            }
        }

        $this->results['tests']['authentication'] = $test_result;
    }

    /**
     * Prueba 3: Probar cotización (Quote API)
     *
     * @param array $test_params Parámetros de prueba.
     */
    private function test_quote( array $test_params = array() ): void {
        $test_result = array(
            'name'    => 'Cotización de Delivery (Quote)',
            'status'  => 'pending',
            'message' => '',
            'details' => array(),
        );

        // Si la autenticación falló, no intentar quote
        if (
            ! isset( $this->results['tests']['authentication'] ) ||
            $this->results['tests']['authentication']['status'] !== 'success'
        ) {
            $test_result['status']  = 'skipped';
            $test_result['message'] = 'Omitido: primero debe pasar la autenticación';
            $this->results['tests']['quote'] = $test_result;
            return;
        }

        // Obtener tienda de origen (primera tienda activa)
        $stores = get_option( 'restohub_stores', array() );
        $pickup_store = null;

        foreach ( $stores as $store ) {
            if ( ! empty( $store['active'] ) && ! empty( $store['latitude'] ) && ! empty( $store['longitude'] ) ) {
                $pickup_store = $store;
                break;
            }
        }

        // Datos de pickup (tienda)
        if ( $pickup_store ) {
            $pickup = array(
                'address'   => $pickup_store['address'],
                'latitude'  => (float) $pickup_store['latitude'],
                'longitude' => (float) $pickup_store['longitude'],
            );
        } else {
            // Dirección de prueba por defecto (Santiago Centro)
            $pickup = $test_params['pickup'] ?? array(
                'address'   => 'Av. Libertador Bernardo O\'Higgins 1112, Santiago, Chile',
                'latitude'  => -33.4425,
                'longitude' => -70.6525,
            );
        }

        // Datos de dropoff (destino de prueba)
        $dropoff = $test_params['dropoff'] ?? array(
            'address'   => 'Mario Vergara 234, Maipú, Chile',
            'latitude'  => -33.5117,
            'longitude' => -70.7578,
        );

        $settings    = get_option( 'restohub_settings', array() );
        $customer_id = $settings['customer_id'] ?? '';
        $api_url     = ( $settings['sandbox_mode'] ?? 'yes' ) === 'yes'
            ? 'https://sandbox-api.uber.com/v1/customers/'
            : 'https://api.uber.com/v1/customers/';

        $token = get_transient( 'restohub_uber_access_token' );

        if ( ! $token ) {
            $test_result['status']  = 'error';
            $test_result['message'] = 'No hay token de acceso disponible';
            $this->results['tests']['quote'] = $test_result;
            return;
        }

        $start_time = microtime( true );

        $response = wp_remote_post(
            $api_url . $customer_id . '/delivery_quotes',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( array(
                    'pickup_address'  => $pickup['address'],
                    'pickup_latitude' => $pickup['latitude'],
                    'pickup_longitude' => $pickup['longitude'],
                    'dropoff_address' => $dropoff['address'],
                    'dropoff_latitude' => $dropoff['latitude'],
                    'dropoff_longitude' => $dropoff['longitude'],
                ) ),
                'timeout' => 30,
            )
        );

        $duration = round( ( microtime( true ) - $start_time ) * 1000 );

        if ( is_wp_error( $response ) ) {
            $test_result['status']  = 'error';
            $test_result['message'] = 'Error de conexión: ' . $response->get_error_message();
            $test_result['details'] = array(
                'duration_ms' => $duration,
                'pickup'      => $pickup,
                'dropoff'     => $dropoff,
            );
        } else {
            $http_code = wp_remote_retrieve_response_code( $response );
            $body      = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $http_code >= 200 && $http_code < 300 && ! empty( $body ) ) {
                $test_result['status']  = 'success';
                $test_result['message'] = 'Cotización obtenida exitosamente';
                $test_result['details'] = array(
                    'duration_ms'     => $duration,
                    'http_code'       => $http_code,
                    'pickup_used'     => $pickup,
                    'dropoff_used'    => $dropoff,
                    'quote_response'  => $this->parse_quote_response( $body ),
                    'raw_response'    => $body, // Respuesta completa para análisis
                );
            } else {
                $test_result['status']  = 'error';
                $test_result['message'] = 'Error al obtener cotización: ' . ( $body['message'] ?? 'Error desconocido' );
                $test_result['details'] = array(
                    'duration_ms' => $duration,
                    'http_code'   => $http_code,
                    'pickup'      => $pickup,
                    'dropoff'     => $dropoff,
                    'error'       => $body,
                );
            }
        }

        $this->results['tests']['quote'] = $test_result;
    }

    /**
     * Parsea la respuesta de cotización para mostrar información legible
     *
     * @param array $response Respuesta de la API.
     * @return array Datos parseados.
     */
    private function parse_quote_response( array $response ): array {
        $parsed = array();

        // Fee / Costo de envío
        if ( isset( $response['fee'] ) ) {
            $parsed['costo_envio'] = array(
                'valor'    => $response['fee'],
                'moneda'   => $response['currency'] ?? 'CLP',
                'formateado' => $this->format_price( $response['fee'], $response['currency'] ?? 'CLP' ),
            );
        }

        // Tiempo estimado de entrega
        if ( isset( $response['estimated_delivery_minutes'] ) ) {
            $parsed['tiempo_estimado'] = array(
                'minutos'   => $response['estimated_delivery_minutes'],
                'formateado' => $response['estimated_delivery_minutes'] . ' minutos',
            );
        }

        // ETA de pickup
        if ( isset( $response['pickup_eta'] ) ) {
            $parsed['eta_pickup'] = $response['pickup_eta'];
        }

        // ETA de dropoff
        if ( isset( $response['dropoff_eta'] ) ) {
            $parsed['eta_dropoff'] = $response['dropoff_eta'];
        }

        // Distancia
        if ( isset( $response['distance'] ) ) {
            $parsed['distancia'] = array(
                'valor'      => $response['distance'],
                'unidad'     => $response['distance_unit'] ?? 'km',
                'formateado' => round( $response['distance'], 2 ) . ' ' . ( $response['distance_unit'] ?? 'km' ),
            );
        }

        // Quote ID (necesario para crear delivery)
        if ( isset( $response['id'] ) ) {
            $parsed['quote_id'] = $response['id'];
        }

        // Fecha de expiración del quote
        if ( isset( $response['expires_at'] ) ) {
            $parsed['expira'] = $response['expires_at'];
        }

        // Tipo de vehículo
        if ( isset( $response['vehicle_type'] ) ) {
            $parsed['tipo_vehiculo'] = $response['vehicle_type'];
        }

        // Desglose de tarifas si existe
        if ( isset( $response['fee_breakdown'] ) ) {
            $parsed['desglose_tarifas'] = array();
            foreach ( $response['fee_breakdown'] as $item ) {
                $parsed['desglose_tarifas'][] = array(
                    'concepto' => $item['name'] ?? $item['type'] ?? 'N/A',
                    'valor'    => $item['amount'] ?? $item['fee'] ?? 0,
                    'tipo'     => $item['type'] ?? 'N/A',
                );
            }
        }

        // Surge pricing / Tarifa dinámica
        if ( isset( $response['surge_multiplier'] ) ) {
            $parsed['multiplicador_demanda'] = $response['surge_multiplier'];
        }

        // Promociones aplicadas
        if ( isset( $response['promotions'] ) ) {
            $parsed['promociones'] = $response['promotions'];
        }

        return $parsed;
    }

    /**
     * Formatea precio según moneda
     *
     * @param float|int $amount   Monto.
     * @param string    $currency Código de moneda.
     * @return string
     */
    private function format_price( $amount, string $currency ): string {
        $symbols = array(
            'CLP' => '$',
            'USD' => 'US$',
            'MXN' => 'MX$',
            'PEN' => 'S/',
            'COP' => 'COP$',
            'ARS' => 'AR$',
        );

        $symbol = $symbols[ $currency ] ?? $currency . ' ';

        // CLP normalmente no tiene decimales
        if ( $currency === 'CLP' ) {
            return $symbol . number_format( $amount, 0, ',', '.' );
        }

        return $symbol . number_format( $amount, 2, ',', '.' );
    }

    /**
     * Obtiene los resultados como HTML formateado
     *
     * @return string
     */
    public function get_results_html(): string {
        if ( empty( $this->results ) ) {
            return '<p>No hay resultados de prueba disponibles.</p>';
        }

        $html = '<div class="restohub-api-test-results">';

        // Información del entorno
        $html .= '<div class="restohub-test-section">';
        $html .= '<h3>📋 Información del Entorno</h3>';
        $html .= '<table class="widefat striped">';
        foreach ( $this->results['environment'] as $key => $value ) {
            $label = ucwords( str_replace( '_', ' ', $key ) );
            $html .= "<tr><td><strong>{$label}</strong></td><td>{$value}</td></tr>";
        }
        $html .= '</table>';
        $html .= '</div>';

        // Resultados de cada prueba
        foreach ( $this->results['tests'] as $test_key => $test ) {
            $status_icon = match( $test['status'] ) {
                'success' => '✅',
                'error'   => '❌',
                'skipped' => '⏭️',
                default   => '⏳',
            };

            $status_class = 'restohub-test-' . $test['status'];

            $html .= "<div class='restohub-test-section {$status_class}'>";
            $html .= "<h3>{$status_icon} {$test['name']}</h3>";
            $html .= "<p class='restohub-test-message'>{$test['message']}</p>";

            if ( ! empty( $test['details'] ) ) {
                $html .= '<div class="restohub-test-details">';
                $html .= '<h4>Detalles:</h4>';
                $html .= '<pre>' . wp_json_encode( $test['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</pre>';
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        // Estilos inline para el reporte
        $html .= '<style>
            .restohub-api-test-results { max-width: 900px; }
            .restohub-test-section {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 8px;
                padding: 15px 20px;
                margin-bottom: 15px;
            }
            .restohub-test-section h3 { margin-top: 0; }
            .restohub-test-section h4 { margin-bottom: 10px; }
            .restohub-test-message { font-size: 14px; }
            .restohub-test-details pre {
                background: #f6f7f7;
                padding: 15px;
                border-radius: 4px;
                overflow-x: auto;
                font-size: 12px;
                line-height: 1.5;
            }
            .restohub-test-success { border-left: 4px solid #46b450; }
            .restohub-test-error { border-left: 4px solid #dc3232; }
            .restohub-test-skipped { border-left: 4px solid #ffb900; }
        </style>';

        return $html;
    }

    /**
     * Obtiene los resultados como array
     *
     * @return array
     */
    public function get_results(): array {
        return $this->results;
    }
}

/**
 * Función helper para obtener la instancia del tester
 *
 * @return RestoHub_API_Tester
 */
function restohub_api_tester(): RestoHub_API_Tester {
    return RestoHub_API_Tester::instance();
}
